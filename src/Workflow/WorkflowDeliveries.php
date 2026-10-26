<?php

declare(strict_types=1);

/**
 * Livraison du livrable d'un workflow : l'envoyer par email (Gmail, Outlook),
 * l'enregistrer dans Google Drive, le publier dans Slack, l'ajouter à une
 * base de connaissances.
 *
 * PRINCIPE : une livraison est une ACTION DE CONNECTEUR ordinaire. Elle est
 * déposée dans la file d'approbation (DrivePendingAction) sous le nom de
 * l'outil du chat qui fait la même chose (`creer_brouillon_gmail`,
 * `creer_google_doc`, `envoyer_message_slack`…), avec l'aperçu de ce
 * connecteur : la carte qu'on approuve est celle du chat, et l'exécution
 * passe par le chemin d'un clic (ChatController::decideAction) — connexion,
 * droits et fonctionnalité revérifiés au moment T, message d'issue dans la
 * conversation. Sans validation : le moteur fait le clic
 * (ChatController::decideForWorkflow).
 *
 * Ce qui ne vient JAMAIS du modèle porte un argument interne `_wf_…`
 * (pièces jointes, dossier Drive) : il est retiré de toute carte née d'un
 * tour de conversation (stripInternal), et relu à l'exécution depuis le
 * dossier de l'exécution — dont on vérifie qu'elle appartient à la personne.
 *
 * L'ajout à une base de connaissances n'écrit chez aucun tiers : il est fait
 * aussitôt (KnowledgeBaseDocument + ingestion), si la personne gère la base.
 *
 * Une création (WorkflowCreations) : une image part en pièce jointe (allégée
 * au-delà de 1 Mo) et entre dans une base comme image, que la base décrit ;
 * Drive et Slack, qui ne reçoivent ici que des documents et du texte, la
 * laissent au dossier. Une page web part en .html, un itinéraire en document.
 */
final class WorkflowDeliveries
{
    public const ATTACH_KEY = '_wf_attach';
    public const FOLDER_KEY = '_wf_folder';

    /** Arguments internes retirés d'une carte proposée par le modèle. */
    public static function stripInternal(array $args): array
    {
        foreach (array_keys($args) as $k) {
            if (is_string($k) && str_starts_with($k, '_wf')) {
                unset($args[$k]);
            }
        }
        return $args;
    }

    /**
     * Remplit un texte de livraison : `@{clé.champ}` → valeur du champ ;
     * `@{clé}` → le contenu de la pièce (`$insert`) ou son nom. Une pièce
     * insérée qui cite d'autres pièces est remplie à son tour selon la règle
     * de son auteur (`$nested`, WorkflowInserts::nestedResolver) ; sans elle,
     * ses repères restent tels quels.
     */
    public static function fill(string $text, array $pieces, array $artifacts, bool $insert, int $pieceMax = 20000, ?callable $nested = null): string
    {
        return (string)preg_replace_callback(
            '/@\{([a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '})(?:\.([a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '}))?\}/',
            static function (array $m) use ($pieces, $artifacts, $insert, $pieceMax, $nested): string {
                $p = $pieces[$m[1]] ?? null;
                if ($p === null) {
                    return $m[0];
                }
                $art = $artifacts[$m[1]] ?? null;
                if (($m[2] ?? '') !== '') {
                    foreach ($p['fields'] ?? [] as $f) {
                        if ($f['key'] === $m[2]) {
                            return WorkflowValues::display($art['content']['fields'][$f['key']] ?? null, (string)$f['type']);
                        }
                    }
                    return '—';
                }
                // Une image ne s'écrit pas dans un texte : son nom (elle part en pièce jointe).
                if (!$insert || $art === null || $p['form'] === 'image') {
                    return '« ' . $p['name'] . ' »';
                }
                // La pièce cite d'autres pièces (rédigée par une étape qui
                // écrit) : remplies à leur tour, selon son auteur.
                return WorkflowInserts::renderPiece($m[1], $p, (array)$art['content'], $pieces, $nested, (string)($art['key'] ?? $m[1]), $pieceMax);
            },
            $text
        );
    }

    /**
     * Mène une livraison. Rend son issue : done (faite), card (carte à
     * approuver déposée), skipped (rien à livrer), failed (+ error). Une
     * livraison passée par une action en garde l'identifiant (`action_ids`),
     * même faite sans carte : l'historique en relit le contenu.
     *
     * @return array{status: string, label: string, error?: string, action_ids?: list<int>, note?: string}
     */
    public static function execute(array $run, array $user, array $d, int $index, int $messageId): array
    {
        $label = self::label($d);
        try {
            return match ($d['type']) {
                'email' => self::email($run, $user, $d, $messageId) + ['label' => $label],
                'drive' => self::drive($run, $user, $d, $messageId) + ['label' => $label],
                'slack' => self::slack($run, $user, $d, $messageId) + ['label' => $label],
                'kb'    => self::kb($run, $user, $d) + ['label' => $label],
                default => ['status' => 'skipped', 'label' => $label],
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            self::post($run, '⚠️ ' . t('Livraison non effectuée — {livraison} : {erreur}', ['livraison' => $label, 'erreur' => $e->getMessage()]));
            return ['status' => 'failed', 'label' => $label, 'error' => $label . ' : ' . $e->getMessage()];
        } catch (\Throwable $e) {
            error_log('[Workflows] livraison #' . $index . ' de l’exécution #' . $run['id'] . ' : ' . $e->getMessage());
            self::post($run, '⚠️ ' . t('Livraison non effectuée — {livraison} : {erreur}', ['livraison' => $label, 'erreur' => t('erreur inattendue')]));
            return ['status' => 'failed', 'label' => $label, 'error' => $label . ' : ' . t('erreur inattendue')];
        }
    }

    /** Libellé d'une livraison (écran, messages). */
    public static function label(array $d): string
    {
        return match ($d['type']) {
            'email' => t('Envoi par email ({service})', ['service' => ($d['source'] ?? '') === 'outlook' ? 'Outlook' : 'Gmail']),
            'drive' => t('Enregistrement dans Google Drive'),
            'slack' => t('Publication dans Slack'),
            'kb'    => t('Ajout à une base de connaissances'),
            default => (string)$d['type'],
        };
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    private static function email(array $run, array $user, array $d, int $messageId): array
    {
        $gmail = $d['source'] !== 'outlook';
        $service = $gmail ? 'gmail' : 'outlook';
        $produit = $gmail ? 'Gmail' : 'Outlook';
        $enabled = $gmail ? UserGmailService::isGloballyEnabled() : UserOutlookService::isGloballyEnabled();
        $svc = $enabled ? ($gmail ? UserGmailService::forUser((int)$user['id']) : UserOutlookService::forUser((int)$user['id'])) : null;
        if ($svc === null) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => $produit]));
        }
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = WorkflowLoop::view(WorkflowRun::artifacts($run['id']), [], 0);

        $to = WorkflowSpec::emailList((string)$d['to'])['valid'];
        if ($d['to_field'] !== '') {
            [$pk, $fk] = explode('.', $d['to_field'], 2);
            $v = WorkflowValues::email($artifacts[$pk]['content']['fields'][$fk] ?? null);
            if ($v === null && $to === []) {
                $fieldLabel = $fk;
                foreach ($pieces[$pk]['fields'] ?? [] as $f) {
                    if ($f['key'] === $fk) {
                        $fieldLabel = $f['label'];
                    }
                }
                throw new \RuntimeException(t('aucun destinataire — le champ « {champ} » est vide ou n’est pas une adresse.', ['champ' => $fieldLabel]));
            }
            if ($v !== null && !in_array($v, $to, true)) {
                $to[] = $v;
            }
        }
        if ($to === []) {
            throw new \RuntimeException(t('aucun destinataire.'));
        }
        $subject = trim((string)preg_replace('/\s+/u', ' ', self::fill((string)$d['subject'], $pieces, $artifacts, false)));
        $attach = array_values(array_filter($d['attach'], static fn(string $k): bool => isset($artifacts[$k], $pieces[$k])));
        $body = trim(self::fill((string)$d['message'], $pieces, $artifacts, true, 20000, WorkflowInserts::nestedResolver($def, WorkflowRun::artifacts($run['id']))));
        if ($body === '') {
            $names = array_map(static fn(string $k): string => '« ' . $pieces[$k]['name'] . ' »', $attach);
            $body = $names !== [] ? t('Bonjour,') . "\n\n" . t('Vous trouverez ci-joint : {pieces}.', ['pieces' => implode(', ', $names)]) : '';
        }
        if ($body === '') {
            return ['status' => 'skipped', 'note' => t('rien à envoyer')];
        }
        // Fabriqués maintenant pour vérifier les bornes et nommer les fichiers
        // sur la carte ; refabriqués à l'exécution (même résultat).
        $files = self::attachmentFiles($run, $attach);
        $args = [
            'destinataire' => implode(', ', $to),
            'cc'           => '',
            'objet'        => mb_substr($subject !== '' ? $subject : (string)$def['end']['title'], 0, WorkflowSpec::SUBJECT_MAX),
            'corps'        => mb_substr($body, 0, 60000),
            self::ATTACH_KEY => array_map(static fn(string $k): array => ['run_id' => (int)$run['id'], 'piece' => $k], $attach),
        ];
        $tool = $gmail ? 'creer_brouillon_gmail' : 'creer_brouillon_outlook';
        $preview = $gmail ? UserGmailService::actionPreview($tool, $args) : UserOutlookService::actionPreview($tool, $args);
        if ($files !== []) {
            $preview['impacts'][] = t('Pièces jointes : {fichiers}.', ['fichiers' => implode(', ', array_map(static fn(array $f): string => $f['name'], $files))]);
        }
        [$canSend, $why] = ChatController::mailSendAvailability($service, (int)$user['id']);
        $preview = MailDraftReview::withSendAvailability($preview, $canSend, $why);
        $preview['workflow'] = true;
        $actionId = self::deposit($run, $user, $tool, $args, $preview, $messageId);
        if ($d['mode'] === 'approve') {
            return ['status' => 'card', 'action_ids' => [$actionId]];
        }
        $data = (new ChatController())->decideForWorkflow((int)$run['conversation_id'], $user, $actionId, $d['mode'] === 'send' ? 'send' : 'approve');
        // L'action reste citée : l'historique relit l'email parti.
        return self::outcome($data) + ['action_ids' => [$actionId]];
    }

    /**
     * Fichiers joints d'une action d'email déposée par un workflow : relus
     * depuis le dossier de L'EXÉCUTION de cette conversation, qui doit
     * appartenir à la personne. Jamais un chemin reçu. Une référence qui
     * porte un `cid` est une image INTÉGRÉE au corps (WorkflowInserts).
     *
     * @return list<array{name: string, mime: string, bytes: string, cid?: string}>
     */
    public static function attachmentsFor(array $args, array $user, int $convId): array
    {
        $refs = (array)($args[self::ATTACH_KEY] ?? []);
        if ($refs === []) {
            return [];
        }
        $run = WorkflowRun::forConversation($convId);
        if ($run === null || $run['user_id'] !== (int)$user['id']) {
            throw new \RuntimeException(t('Les pièces jointes de cette action ne sont plus disponibles.'));
        }
        $keys = [];
        foreach ($refs as $r) {
            if (is_array($r) && (int)($r['run_id'] ?? 0) === $run['id'] && is_string($r['piece'] ?? null)) {
                $keys[] = ['piece' => $r['piece'], 'cid' => is_string($r['cid'] ?? null) ? $r['cid'] : ''];
            }
        }
        return self::attachmentFiles($run, $keys);
    }

    /**
     * @param list<string|array{piece: string, cid?: string}> $keys
     * @return list<array{name: string, mime: string, bytes: string, cid?: string}>
     */
    private static function attachmentFiles(array $run, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $ref = is_array($k) ? $k : ['piece' => $k];
            // La forme d'une pièce jointe : image et page allégées au besoin.
            $built = WorkflowFiles::build($run, (string)$ref['piece'], WorkflowFiles::FOR_EMAIL);
            $file = ['name' => $built['name'], 'mime' => $built['mime'], 'bytes' => $built['bytes']];
            if ((string)($ref['cid'] ?? '') !== '') {
                $file['cid'] = (string)$ref['cid'];
            }
            $out[] = $file;
        }
        return OutgoingAttachments::normalize($out);
    }

    // ------------------------------------------------------------------
    // Google Drive
    // ------------------------------------------------------------------

    private static function drive(array $run, array $user, array $d, int $messageId): array
    {
        $svc = UserDriveService::isGloballyEnabled() ? UserDriveService::forUser((int)$user['id']) : null;
        if ($svc === null) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => 'Google Drive']));
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = WorkflowLoop::view(WorkflowRun::artifacts($run['id']), [], 0);
        $date = Format::shortDate(new \DateTimeImmutable('now', Recurrence::zone(ScheduledTask::normalizeTimezone((string)$run['timezone']))));
        $ids = [];
        $left = [];
        foreach ($d['pieces'] as $k) {
            $p = $pieces[$k] ?? null;
            $a = $artifacts[$k] ?? null;
            if ($p === null || $a === null) {
                continue;
            }
            // Drive n'enregistre ici que des Docs et des Sheets : une image reste au dossier.
            if ($p['form'] === 'image') {
                $left[] = '« ' . $p['name'] . ' »';
                continue;
            }
            $title = $p['name'] . ' — ' . $date;
            $rows = self::tableRows($p, $a['content']);
            if ($rows !== null) {
                if (!$svc->canWriteSheets()) {
                    throw new \RuntimeException(UserDriveService::MSG_RECONNECT_WRITE);
                }
                $tool = 'creer_google_sheet';
                $args = ['titre' => $title, 'valeurs' => $rows, 'entete' => true];
            } else {
                if (!$svc->canWriteDocs()) {
                    throw new \RuntimeException(UserDriveService::MSG_RECONNECT_WRITE);
                }
                $tool = 'creer_google_doc';
                $args = ['titre' => $title, 'contenu' => ArtifactContract::render($p, $a['content'], 400000)];
            }
            if (trim((string)$d['folder']) !== '') {
                $args[self::FOLDER_KEY] = (string)$d['folder'];
            }
            $preview = UserDriveService::actionPreview($tool, self::stripInternal($args), null);
            if (trim((string)$d['folder']) !== '') {
                $preview['impacts'][] = t('Rangé ensuite dans le dossier « {dossier} ».', ['dossier' => (string)$d['folder']]);
            }
            $preview['workflow'] = true;
            $ids[] = self::deposit($run, $user, $tool, $args, $preview, $messageId);
        }
        if ($left !== []) {
            self::post($run, 'ℹ️ ' . t('Images laissées de côté par l’enregistrement dans Drive : {pieces}. Elles restent au dossier de l’exécution.', ['pieces' => implode(', ', $left)]));
        }
        if ($ids === []) {
            return ['status' => 'skipped', 'note' => t('aucune pièce à enregistrer')];
        }
        if ($d['mode'] === 'approve') {
            return ['status' => 'card', 'action_ids' => $ids];
        }
        $controller = new ChatController();
        foreach ($ids as $id) {
            $data = $controller->decideForWorkflow((int)$run['conversation_id'], $user, $id, 'approve');
            $res = self::outcome($data);
            if ($res['status'] === 'failed') {
                return $res + ['action_ids' => $ids];
            }
        }
        return ['status' => 'done', 'action_ids' => $ids];
    }

    /** Lignes d'un tableau (tableau, classeur Excel), ou null pour un document. */
    private static function tableRows(array $piece, array $content): ?array
    {
        if ($piece['form'] === 'table') {
            return array_values((array)($content['rows'] ?? []));
        }
        if ($piece['form'] === 'document' && ($content['format'] ?? '') === 'xlsx') {
            try {
                return TabularData::parse((string)($content['source'] ?? ''))->rows();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Slack
    // ------------------------------------------------------------------

    private static function slack(array $run, array $user, array $d, int $messageId): array
    {
        $svc = UserSlackService::isGloballyEnabled() ? UserSlackService::forUser((int)$user['id']) : null;
        if ($svc === null) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => 'Slack']));
        }
        if (!$svc->canPost()) {
            throw new \RuntimeException(UserSlackService::MSG_RECONNECT_WRITE);
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $all = WorkflowRun::artifacts($run['id']);
        $artifacts = WorkflowLoop::view($all, [], 0);
        $nested = WorkflowInserts::nestedResolver($run['definition'], $all);
        $text = trim(self::fill((string)$d['message'], $pieces, $artifacts, true, 12000, $nested));
        foreach ($d['pieces'] as $k) {
            // Slack ne reçoit ici que du texte : une image reste au dossier.
            if (isset($pieces[$k], $artifacts[$k]) && $pieces[$k]['form'] !== 'image') {
                $text .= ($text !== '' ? "\n\n" : '') . '**' . $pieces[$k]['name'] . "**\n\n"
                    . WorkflowInserts::renderPiece($k, $pieces[$k], (array)$artifacts[$k]['content'], $pieces, $nested, (string)($artifacts[$k]['key'] ?? $k), 12000);
            }
        }
        if (trim($text) === '') {
            return ['status' => 'skipped', 'note' => t('rien à publier')];
        }
        $args = UserSlackService::normalizePostArgs(['canal' => (string)$d['channel'], 'texte' => mb_substr($text, 0, 38000)]);
        $preview = UserSlackService::actionPreview('envoyer_message_slack', $args);
        $preview['workflow'] = true;
        $actionId = self::deposit($run, $user, 'envoyer_message_slack', $args, $preview, $messageId);
        if ($d['mode'] === 'approve') {
            return ['status' => 'card', 'action_ids' => [$actionId]];
        }
        return self::outcome((new ChatController())->decideForWorkflow((int)$run['conversation_id'], $user, $actionId, 'approve')) + ['action_ids' => [$actionId]];
    }

    // ------------------------------------------------------------------
    // Base de connaissances
    // ------------------------------------------------------------------

    private static function kb(array $run, array $user, array $d): array
    {
        $kbId = (int)($d['kb_id'] ?? 0);
        $kb = $kbId > 0 ? KnowledgeBase::findById($kbId) : null;
        if ($kb === null) {
            throw new \RuntimeException(t('la base de connaissances n’existe plus.'));
        }
        if (KnowledgeBase::isRemote($kb) || KnowledgeBase::isSystem($kbId) || !KnowledgeBase::manageableBy($kbId, $user)) {
            throw new \RuntimeException(t('vous ne gérez plus la base « {nom} ».', ['nom' => (string)$kb['name']]));
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = WorkflowLoop::view(WorkflowRun::artifacts($run['id']), [], 0);
        $date = Format::shortDate(new \DateTimeImmutable('now', Recurrence::zone(ScheduledTask::normalizeTimezone((string)$run['timezone']))));
        $added = [];
        foreach ($d['pieces'] as $k) {
            $p = $pieces[$k] ?? null;
            $a = $artifacts[$k] ?? null;
            if ($p === null || $a === null) {
                continue;
            }
            // Une image entre telle quelle (la base la décrit pour la rendre
            // cherchable) ; le reste, en Markdown.
            if ($p['form'] === 'image') {
                $img = WorkflowFiles::build($run, (string)$a['key'], '');
                [$bytes, $mime, $ext] = [$img['bytes'], $img['mime'], ImageBytes::extension($img['mime'])];
            } else {
                $bytes = '# ' . $p['name'] . "\n\n" . trim(ArtifactContract::render($p, $a['content'], 400000));
                [$mime, $ext] = ['text/markdown', 'md'];
            }
            $fileName = OutgoingAttachments::cleanName(($d['replace'] ? $p['name'] : $p['name'] . ' — ' . $date) . '.' . $ext);
            if ($d['replace']) {
                foreach (KnowledgeBaseDocument::allForKb($kbId) as $doc) {
                    if ((string)($doc['source'] ?? 'upload') === 'upload' && (string)$doc['file_name'] === $fileName) {
                        try {
                            (new QdrantStore())->deletePointsByDocument(KnowledgeBase::collectionName($kbId), (int)$doc['id']);
                        } catch (\Throwable $e) {
                            error_log('[Workflows] purge des points du document #' . (int)$doc['id'] . ' : ' . $e->getMessage());
                        }
                        KnowledgeBaseDocument::delete((int)$doc['id']);
                    }
                }
            }
            if (KnowledgeBaseDocument::countBySource($kbId, 'upload') >= KnowledgeBase::MAX_DOCS_PER_KB) {
                throw new \RuntimeException(t('la base « {nom} » a atteint {n} documents.', ['nom' => (string)$kb['name'], 'n' => KnowledgeBase::MAX_DOCS_PER_KB]));
            }
            $dir = KnowledgeBase::ensureStorageDir($kbId);
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (@file_put_contents($dir . '/' . $stored, $bytes) === false) {
                throw new \RuntimeException(t('écriture impossible dans la base.'));
            }
            $docId = KnowledgeBaseDocument::create([
                'kb_id' => $kbId, 'file_name' => $fileName, 'stored_name' => $stored,
                'mime_type' => $mime, 'file_size' => strlen($bytes),
            ]);
            JobQueue::enqueueMaintenance('kb_document', $docId, 'Ingestion document #' . $docId);
            $added[] = $fileName;
        }
        if ($added === []) {
            return ['status' => 'skipped', 'note' => t('aucune pièce à ajouter')];
        }
        self::post($run, '✅ ' . t('Ajouté à la base « {nom} » : {fichiers}. Il sera interrogeable dès sa lecture terminée.', [
            'nom' => (string)$kb['name'], 'fichiers' => implode(', ', $added),
        ]));
        return ['status' => 'done'];
    }

    // ------------------------------------------------------------------

    /** Dépose une action dans la file, rattachée au message du livrable, pour une semaine. */
    private static function deposit(array $run, array $user, string $tool, array $args, array $preview, int $messageId): int
    {
        $convId = (int)$run['conversation_id'];
        $id = DrivePendingAction::create((int)$user['id'], $convId, $tool, $args, $preview);
        DrivePendingAction::extend($id, (int)$user['id'], Workflow::ACTION_TTL_MINUTES);
        if ($messageId > 0) {
            DrivePendingAction::attachToMessage([$id], $messageId, (int)$user['id']);
        }
        return $id;
    }

    /** Issue d'une décision prise par le moteur. */
    private static function outcome(array $data): array
    {
        if (!empty($data['ok']) && ($data['statut'] ?? '') === 'executed') {
            return ['status' => 'done'];
        }
        $error = trim((string)($data['error'] ?? ''));
        return ['status' => 'failed', 'error' => $error !== '' ? $error : t('Erreur inattendue.')];
    }

    private static function post(array $run, string $content): void
    {
        $convId = (int)($run['conversation_id'] ?? 0);
        if ($convId > 0) {
            Message::create($convId, 'assistant', $content);
            Conversation::touch($convId);
        }
    }
}
