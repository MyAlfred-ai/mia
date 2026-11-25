<?php

declare(strict_types=1);

/**
 * Les pièces jointes de l'email qui LANCE un workflow, jointes au message de
 * chaque étape qui lit cet email.
 *
 * Le problème résolu (constaté chez un client, 2026-11-24) : la pièce « Email reçu » ne porte
 * que les champs de l'email (expéditeur, objet, texte) et le NOM de ses pièces
 * jointes. Une étape qui la citait ne recevait jamais le fichier : il lui
 * fallait cocher Outlook (ou Gmail) dans « Peut consulter » et lire la pièce
 * jointe par un outil — sinon le modèle répondait « pièce jointe non
 * accessible ». Et même avec l'outil, un classeur arrivait en texte paginé,
 * hors de portée des tables de travail : 2 800 lignes comptées de tête.
 *
 * Désormais une étape qui lit l'email reçu reçoit ses pièces jointes comme
 * des fichiers joints au chat : un classeur se lit en entier, se range dans
 * une table de travail (source « lignes_piece_jointe ») et se compte par le
 * serveur ; un PDF et une image partent tels quels au modèle.
 *
 * Le téléchargement se fait DANS le job de l'étape (ChatController::runSend,
 * jamais dans une requête de la personne), avec la connexion qui a reçu
 * l'email. Il ne lève jamais : une panne devient une note au modèle (« dis-le,
 * n'invente rien ») et une ligne ambre de la trace.
 */
final class WorkflowEventFiles
{
    /** Pièces jointes jointes à une étape, au plus. */
    public const MAX_FILES = 5;

    /** Formes d'image qu'un modèle lit (les autres — HEIC, TIFF… — ne sont pas converties ici). */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Ce que l'étape doit recevoir : l'email reçu par le déclencheur, si elle
     * le lit (citation ou « lit aussi ») et s'il annonce des pièces jointes.
     * Null sinon — un email collé à la main n'a pas d'identifiant. Pure.
     *
     * @return array{service: string, message_id: string, piece: string}|null
     */
    public static function forPass(array $def, array $node, array $artifacts): ?array
    {
        if (($node['type'] ?? '') !== WorkflowSpec::TYPE_STEP) {
            return null;
        }
        if (!in_array(WorkflowSpec::EVENT_PIECE, WorkflowSpec::readsOf($def, $node), true)) {
            return null;
        }
        $art = $artifacts[WorkflowSpec::EVENT_PIECE] ?? null;
        $content = is_array($art['content'] ?? null) ? $art['content'] : [];
        $id = trim((string)($content['message_id'] ?? ''));
        $service = (string)($content['service'] ?? '');
        $names = array_filter((array)($content['fields']['pieces_jointes'] ?? []), static fn($n): bool => trim((string)$n) !== '');
        if ($id === '' || !in_array($service, ['gmail', 'outlook'], true) || $names === []) {
            return null;
        }
        return ['service' => $service, 'message_id' => $id, 'piece' => (string)($art['name'] ?? '')];
    }

    /**
     * Les pièces jointes à télécharger, et celles écartées avec leur raison.
     * Une image collée dans le corps (logo, signature) est ignorée sans un
     * mot ; un lien de partage, un format illisible, un fichier trop lourd
     * pour le chat sont nommés. Pure.
     *
     * @param list<array> $listing MailAttachments (nom, type, taille, piece, en_ligne, numero)
     * @return array{take: list<array>, skipped: list<array{name: string, reason: string}>}
     */
    public static function plan(array $listing): array
    {
        $take = [];
        $skipped = [];
        foreach ($listing as $a) {
            if (!is_array($a) || !empty($a['en_ligne'])) {
                continue;
            }
            $name = (string)($a['nom'] ?? '');
            $piece = (string)($a['piece'] ?? 'fichier');
            $mime = MailAttachments::canonicalMime((string)($a['type'] ?? ''), $name);
            $size = (int)($a['taille'] ?? 0);
            $reason = null;
            if ($piece === 'lien') {
                $reason = t('lien de partage, pas un fichier joint');
            } elseif ($piece === 'fichier' && str_starts_with($mime, 'image/')) {
                if (!in_array($mime, self::IMAGE_TYPES, true)) {
                    $reason = t('format d’image non lu');
                }
            } elseif ($piece === 'fichier' && MailAttachments::unreadableReason($a) !== null) {
                $reason = (int)($a['taille'] ?? 0) > MailAttachments::MAX_BYTES
                    ? t('trop lourd ({taille})', ['taille' => MailAttachments::humanSize($size)])
                    : t('format non lu');
            }
            if ($reason === null && $piece === 'fichier' && $size > FileExtractor::uploadLimit($mime)) {
                $reason = t('trop lourd ({taille}, limite {limite})', [
                    'taille' => MailAttachments::humanSize($size), 'limite' => MailAttachments::humanSize(FileExtractor::uploadLimit($mime)),
                ]);
            }
            if ($reason === null && count($take) >= self::MAX_FILES) {
                $reason = t('au-delà de {n} pièces jointes', ['n' => self::MAX_FILES]);
            }
            if ($reason !== null) {
                $skipped[] = ['name' => $name, 'reason' => $reason];
                continue;
            }
            $take[] = $a;
        }
        return ['take' => $take, 'skipped' => $skipped];
    }

    /**
     * Télécharge les pièces jointes de l'email et les joint au message de
     * l'étape (`$messageId`). Un job rejoué ne télécharge pas deux fois : les
     * fichiers déjà joints (marqués `event`) sont repris tels quels. Ne lève
     * jamais.
     *
     * @param array{service: string, message_id: string, piece?: string} $mail
     * @return array{files: list<string>, skipped: list<array{name: string, reason: string}>, error: string}
     */
    public static function attach(array $mail, int $userId, int $messageId): array
    {
        $out = ['files' => [], 'skipped' => [], 'error' => ''];
        $row = $messageId > 0 ? Message::findById($messageId) : null;
        if ($row === null) {
            return $out;
        }
        $existing = json_decode((string)($row['files_json'] ?? ''), true);
        $existing = is_array($existing) ? $existing : [];
        $already = array_values(array_filter($existing, static fn($f): bool => is_array($f) && !empty($f['event'])));
        if ($already !== []) {
            $out['files'] = array_map(static fn(array $f): string => (string)($f['name'] ?? ''), $already);
            return $out;
        }

        $service = (string)($mail['service'] ?? '');
        $id = (string)($mail['message_id'] ?? '');
        try {
            $svc = LoopRunner::mailService($service, $userId);
            $listing = $svc->listAttachments($id);
            $listing = $service === 'outlook' ? (array)$listing : (array)($listing['pieces'] ?? []);
        } catch (\Throwable $e) {
            $out['error'] = self::cause($e);
            error_log('[Workflow pièces jointes] liste impossible (' . $service . ') : ' . $e->getMessage());
            return $out;
        }

        $plan = self::plan($listing);
        $out['skipped'] = $plan['skipped'];
        $files = [];
        foreach ($plan['take'] as $a) {
            $name = (string)($a['nom'] ?? '');
            try {
                // Désignée par son numéro : deux pièces jointes peuvent porter le même nom.
                $file = $svc->downloadAttachment($id, (string)(int)($a['numero'] ?? 0));
                $stored = LoopRunner::storeFile((string)$file['nom'], (string)$file['bytes'], null);
            } catch (\Throwable $e) {
                $out['skipped'][] = ['name' => $name, 'reason' => self::cause($e)];
                error_log('[Workflow pièces jointes] « ' . $name . ' » non téléchargée : ' . $e->getMessage());
                continue;
            }
            $native = str_starts_with($stored['type'], 'image/') || $stored['type'] === 'application/pdf';
            if (!$native && trim($stored['text']) === '') {
                @unlink($stored['path']);
                $out['skipped'][] = ['name' => $stored['name'], 'reason' => t('rien de lisible dans le fichier')];
                continue;
            }
            $files[] = [
                'name'      => $stored['name'],
                'type'      => $stored['type'],
                'path'      => $stored['path'],
                // Image, PDF : rechargés depuis le chemin au moment du tour (WorkflowPrompt::isolatedHistory).
                'extracted' => $native ? '' : $stored['text'],
                'event'     => true,
            ];
            $out['files'][] = $stored['name'];
        }
        if ($files !== []) {
            Message::setFiles($messageId, array_merge($existing, $files));
        }
        return $out;
    }

    /**
     * Ce que le modèle apprend du résultat (prompt de méthode, en français).
     * Vide quand il n'y avait rien à joindre. Pure.
     */
    public static function promptNote(array $res, string $pieceName): string
    {
        $piece = trim($pieceName) !== '' ? $pieceName : 'Email reçu';
        $files = array_values(array_filter((array)($res['files'] ?? []), static fn($n): bool => (string)$n !== ''));
        $skipped = (array)($res['skipped'] ?? []);
        $error = trim((string)($res['error'] ?? ''));
        if ($files === [] && $skipped === [] && $error === '') {
            return '';
        }
        $out = "\n\nPIÈCES JOINTES DE « " . $piece . " » :";
        if ($error !== '') {
            return $out . ' elles n\'ont pas pu être récupérées (' . $error . '). Dis-le clairement dans ta réponse, '
                . 'n\'invente rien de leur contenu et ne présente pas l\'étape comme réussie sur ce point.';
        }
        if ($files !== []) {
            $out .= ' ' . I18n::listeCitee($files) . (count($files) > 1 ? ' sont jointes' : ' est jointe')
                . ' à ce message, en entier. Lis-les ici : aucun connecteur n\'est nécessaire, ne les cherche pas dans la '
                . 'messagerie. Un classeur volumineux se range d\'abord dans une table de travail (collecter_dans_table, '
                . 'source « lignes_piece_jointe ») : compte et calcule sur toutes ses lignes, jamais sur un extrait.';
        }
        if ($skipped !== []) {
            $parts = array_map(static fn(array $s): string => '« ' . (string)$s['name'] . ' » (' . (string)$s['reason'] . ')', $skipped);
            $out .= ' Non jointes : ' . implode(', ', $parts) . '. Si la consigne en avait besoin, dis-le dans ta réponse.';
        }
        return $out;
    }

    /** Détail de la ligne de trace : les fichiers joints, sinon la cause. Pure. */
    public static function traceDetail(array $res): string
    {
        if (trim((string)($res['error'] ?? '')) !== '') {
            return (string)$res['error'];
        }
        $files = array_values(array_filter((array)($res['files'] ?? []), static fn($n): bool => (string)$n !== ''));
        if ($files !== []) {
            return implode(', ', $files);
        }
        return implode(', ', array_map(static fn(array $s): string => (string)$s['name'] . ' (' . (string)$s['reason'] . ')', (array)($res['skipped'] ?? [])));
    }

    /** Vrai quand le résultat mérite une ligne de trace. Pure. */
    public static function worthTracing(array $res): bool
    {
        return (array)($res['files'] ?? []) !== [] || (array)($res['skipped'] ?? []) !== [] || trim((string)($res['error'] ?? '')) !== '';
    }

    /** Cause présentable d'une panne : le message des services est déjà en français. */
    private static function cause(\Throwable $e): string
    {
        $msg = trim($e->getMessage());
        return $e instanceof \RuntimeException && $msg !== '' ? mb_substr($msg, 0, 200) : t('erreur inattendue');
    }
}
