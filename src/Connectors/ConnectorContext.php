<?php

declare(strict_types=1);

/**
 * Mémoire des objets connecteurs d'une conversation — SOURCE UNIQUE.
 *
 * Une entrée = UN objet réellement touché par un outil de connecteur pendant
 * cette conversation : fichier Drive, email, évènement d'agenda, tâche,
 * workflow. Elle porte l'identifiant technique ET le nom humain.
 *
 * LE PROBLÈME RÉSOLU. `ChatController::buildLLMMessages` reconstruit
 * l'historique depuis les seuls `content` persistés : les `tool_call` et les
 * `tool_result` d'un message ANTÉRIEUR ne reviennent jamais. Au tour suivant,
 * le modèle a donc oublié l'identifiant du fichier qu'il vient de lire, et
 * « ajoute une conclusion à ce document » repartait sur une recherche à
 * l'aveugle — ou sur un id inventé. C'est l'oubli le plus visible côté
 * utilisateur, précisément sur les MODIFICATIONS de fichier.
 *
 * Le fragment est injecté avec les consignes d'outils, FILTRÉ aux connecteurs
 * réellement engagés pour ce message : rappeler un fichier Drive alors que
 * l'interrupteur Drive est éteint ferait tenter un appel impossible.
 *
 * Les statiques de forme (`normalizeEntry`, `entryForTool`, `fragmentFromRows`)
 * sont PURES : testables sans base.
 */
final class ConnectorContext
{
    /** Objets mémorisés par conversation (les plus récents gagnent). */
    public const MAX_ENTRIES = 12;

    /** Objets cités dans le fragment de system prompt. */
    public const MAX_IN_PROMPT = 8;

    public const MAX_NAME  = 300;
    public const MAX_REF   = 400;
    public const MAX_EXTRA = 200;

    /**
     * Libellé humain par type d'objet. Le fragment est lu par le modèle : il
     * doit pouvoir dire « le Google Doc "Budget" » sans deviner.
     */
    private const KIND_LABELS = [
        'drive_file'  => 'fichier Drive',
        'drive_folder' => 'dossier Drive',
        'gmail_msg'   => 'email Gmail',
        'outlook_msg' => 'email Outlook',
        'gcal_event'  => 'évènement Google Agenda',
        'mscal_event' => 'évènement calendrier Outlook',
        'task'        => 'tâche Google',
        'task_list'   => 'liste de tâches Google',
        // L'identifiant d'une tâche To Do est COMPOSÉ (« liste~tâche ») : Graph
        // n'adresse une tâche que dans sa liste. Il se recopie tel quel.
        'mstodo_task' => 'tâche Microsoft To Do',
        'n8n_wf'      => 'workflow n8n',
        'slack_channel' => 'canal Slack',
        // L'identifiant d'un message Slack ne suffit pas à le désigner : il
        // faut AUSSI son canal (`conversations.replies` exige les deux). Il est
        // donc stocké sous la forme « canal/horodatage », et la consigne
        // d'outils Slack apprend au modèle à le séparer.
        'slack_msg'   => 'message Slack',
        // La référence Teams est rendue TELLE QUELLE par les outils : une
        // conversation (`19:…`), un canal (« équipe~canal ») ou un fil
        // (« équipe~canal~message ») — une seule chaîne à recopier.
        'teams_conv'  => 'conversation ou canal Teams',
    ];

    /**
     * Connecteur porteur de chaque type — c'est LUI qui décide si l'entrée est
     * citée : un id de fichier Drive n'a aucun intérêt (et induit en erreur)
     * quand Drive n'est pas engagé pour ce message.
     */
    private const KIND_CONNECTORS = [
        'drive_file'  => 'drive',
        'drive_folder' => 'drive',
        'gmail_msg'   => 'gmail',
        'outlook_msg' => 'outlook',
        'gcal_event'  => 'calendar',
        'mscal_event' => 'mscalendar',
        'task'        => 'tasks',
        'task_list'   => 'tasks',
        'mstodo_task' => 'mstodo',
        'n8n_wf'      => 'n8n',
        'slack_channel' => 'slack',
        'slack_msg'   => 'slack',
        'teams_conv'  => 'teams',
    ];

    // ===================== FORME (pur, testable sans base) =====================

    /**
     * Normalise une entrée candidate. Null = rien à mémoriser (type inconnu,
     * identifiant ou nom vide) : la capture ne doit JAMAIS faire échouer le
     * tour de chat qui l'a produite.
     *
     * @return array{kind:string,connector:string,ref:string,name:string,extra:?string}|null
     */
    public static function normalizeEntry(string $kind, string $ref, string $name, ?string $extra = null): ?array
    {
        $kind = trim($kind);
        if (!isset(self::KIND_CONNECTORS[$kind])) {
            return null;
        }
        $ref  = trim($ref);
        $name = self::oneLine($name);
        if ($ref === '' || $name === '') {
            return null;
        }
        $extra = $extra === null ? null : self::oneLine($extra);
        return [
            'kind'      => $kind,
            'connector' => self::KIND_CONNECTORS[$kind],
            'ref'       => mb_substr($ref, 0, self::MAX_REF),
            'name'      => mb_substr($name, 0, self::MAX_NAME),
            'extra'     => ($extra === null || $extra === '') ? null : mb_substr($extra, 0, self::MAX_EXTRA),
        ];
    }

    /**
     * Objet désigné par un appel d'outil — lu dans les arguments ET dans le
     * résultat. Le RÉSULTAT est prioritaire pour le nom (le modèle a pu passer
     * un nom approximatif, le service a résolu le vrai) et pour l'identifiant
     * (une résolution par nom rend l'id, seule forme réutilisable ensuite).
     *
     * Ne mémorise que les objets SINGULIERS : une recherche renvoie une liste,
     * et retenir dix résultats noierait le fragment sans rien désigner.
     *
     * @param array $args   arguments de l'appel
     * @param array $result retour du handler (peut porter `error`)
     * @return array{kind:string,connector:string,ref:string,name:string,extra:?string}|null
     */
    public static function entryForTool(string $tool, array $args, array $result): ?array
    {
        // Un appel en échec n'a rien touché : le mémoriser ferait croire au
        // modèle qu'il dispose d'un objet qu'il n'a jamais obtenu.
        if (isset($result['error'])) {
            return null;
        }
        $arg = static fn(string $k): string => isset($args[$k]) && is_scalar($args[$k]) ? (string)$args[$k] : '';
        $res = static fn(string $k): string => isset($result[$k]) && is_scalar($result[$k]) ? (string)$result[$k] : '';

        [$kind, $ref, $name, $extra] = match ($tool) {
            // Repli sur l'ARGUMENT quand le service ne renvoie pas d'identifiant
            // (readEvent Google, readMessage Gmail/Outlook) : l'argument EST
            // l'identifiant que le modèle vient d'employer avec succès, donc
            // réutilisable tel quel. Les noms de clés suivent chaque service —
            // `de` chez les deux messageries, pas `expediteur`.
            'lire_google_drive' => ['drive_file', $res('id') ?: $arg('fichier'), $res('nom'), $res('type')],
            // Le dossier PARCOURU : « et le compte rendu qui est dedans ? » au
            // tour suivant n'a plus rien pour le retrouver sinon.
            'lister_dossier_google_drive' => [
                'drive_folder',
                (string)(is_array($result['dossier'] ?? null) ? ($result['dossier']['id'] ?? '') : ''),
                // Le CHEMIN plutôt que le seul nom de feuille : « 01-CLIENTS/INITECH »
                // situe le dossier, « INITECH » seul oblige à le redeviner.
                $res('chemin') !== '' ? $res('chemin')
                    : (string)(is_array($result['dossier'] ?? null) ? ($result['dossier']['nom'] ?? '') : ''),
                null,
            ],
            'lire_gmail'        => ['gmail_msg', $res('id') ?: $arg('message'), $res('objet'), $res('de')],
            'lire_outlook'      => ['outlook_msg', $res('id') ?: $arg('message'), $res('objet'), $res('de')],
            // Lire une pièce jointe, c'est avoir lu SON message : « réponds-lui »
            // au tour suivant vise ce mail, et la pièce jointe est nommée.
            'lire_piece_jointe_gmail'   => ['gmail_msg', $res('message') ?: $arg('message'), $res('objet'), $res('nom')],
            'lire_piece_jointe_outlook' => ['outlook_msg', $res('message') ?: $arg('message'), $res('objet'), $res('nom')],
            'lire_evenement_agenda'         => ['gcal_event', $res('id') ?: $arg('evenement'), $res('titre'), $res('debut')],
            'lire_evenement_agenda_outlook' => ['mscal_event', $res('id') ?: $arg('evenement'), $res('titre'), $res('quand')],
            'lire_tache'        => ['task', $res('id') ?: $arg('tache'), $res('titre'), $res('echeance')],
            'lire_tache_todo'   => ['mstodo_task', $res('id') ?: $arg('tache'), $res('titre'), $res('echeance')],
            // Le canal PARCOURU : « et le message de Paul là-dedans ? » au tour
            // suivant n'a plus rien pour le retrouver sinon. L'identifiant rendu
            // par le service prime sur le nom tapé par le modèle, qui a pu
            // passer un lien.
            'lire_canal_slack'  => [
                'slack_channel',
                $res('canal_id') ?: $arg('canal'),
                ($res('canal') ?: $arg('canal')) !== '' ? '#' . ltrim($res('canal') ?: $arg('canal'), '#') : '',
                null,
            ],
            // Un message Slack se désigne par SON CANAL ET son horodatage :
            // stocker le seul `ts` rendrait l'entrée inutilisable au tour
            // suivant (`lire_fil_slack` exige les deux).
            'lire_fil_slack'    => [
                'slack_msg',
                $arg('message') !== '' ? ($res('canal_id') ?: $arg('canal')) . '/' . $arg('message') : '',
                ($res('canal') ?: $arg('canal')) !== '' ? '#' . ltrim($res('canal') ?: $arg('canal'), '#') : '',
                null,
            ],
            // La conversation ou le canal LU (référence résolue par le service,
            // le modèle a pu passer un nom ou un lien) ; un fil garde son message.
            'lire_conversation_teams', 'lire_fil_teams' => (static function () use ($result): array {
                $c   = is_array($result['conversation'] ?? null) ? $result['conversation'] : [];
                $ref = is_scalar($c['ref'] ?? null) ? (string)$c['ref'] : '';
                $fil = is_scalar($c['fil'] ?? null) ? (string)$c['fil'] : '';
                return [
                    'teams_conv',
                    $ref !== '' && $fil !== '' ? $ref . '~' . $fil : $ref,
                    is_scalar($c['nom'] ?? null) ? (string)$c['nom'] : '',
                    $fil !== '' ? 'fil de discussion' : (is_scalar($c['lieu'] ?? null) ? (string)$c['lieu'] : null),
                ];
            })(),
            'decrire_workflow_n8n' => ['n8n_wf', $res('id') ?: $arg('workflow'), $res('nom'), null],
            default             => [null, '', '', null],
        };
        if ($kind === null) {
            return null;
        }
        return self::normalizeEntry($kind, $ref, $name, $extra === '' ? null : $extra);
    }

    /**
     * Fragment de system prompt. Vide si aucune entrée exploitable → zéro token
     * pour les conversations qui n'ont touché aucun connecteur.
     *
     * @param list<array> $rows              entrées, de la plus récente à la plus ancienne
     * @param list<string> $engagedConnectors clés de connecteurs actives ce message
     */
    public static function fragmentFromRows(array $rows, array $engagedConnectors): string
    {
        $lignes = [];
        foreach ($rows as $row) {
            $kind = (string)($row['kind'] ?? '');
            $cnx  = self::KIND_CONNECTORS[$kind] ?? null;
            if ($cnx === null || !in_array($cnx, $engagedConnectors, true)) {
                continue;
            }
            $nom = self::oneLine((string)($row['name'] ?? ''));
            $ref = trim((string)($row['ref_id'] ?? $row['ref'] ?? ''));
            if ($nom === '' || $ref === '') {
                continue;
            }
            $extra = self::oneLine((string)($row['extra'] ?? ''));
            $lignes[] = '- ' . (self::KIND_LABELS[$kind] ?? 'objet') . ' « ' . $nom . ' » — identifiant '
                . $ref . ($extra !== '' ? ' (' . $extra . ')' : '');
            if (count($lignes) >= self::MAX_IN_PROMPT) {
                break;
            }
        }
        if ($lignes === []) {
            return '';
        }

        return "\n\nObjets déjà consultés dans cette conversation (du plus récent au plus ancien) :\n"
            . implode("\n", $lignes)
            . "\nQuand l'utilisateur dit « ce document », « ce mail », « cet évènement » sans le nommer, "
            . "il parle du plus récent de cette liste : réutilise son identifiant DIRECTEMENT, sans "
            . "relancer une recherche. Si sa demande ne correspond à aucun de ces objets, cherche "
            . "normalement. Et si tu hésites entre deux d'entre eux, demande lequel plutôt que de choisir.";
    }

    /**
     * Note pour une RÉGÉNÉRATION. `regenerate()` n'expose ni connecteurs ni
     * recherche web — parité assumée : rejouer une demande déjà traitée ne doit
     * ni ré-écrire, ni re-mémoriser. Mais le taire rendait une réponse
     * SILENCIEUSEMENT dégradée : une réponse bâtie sur un fichier Drive était
     * rejouée de mémoire, sans un mot, et l'utilisateur y lisait une régression
     * de l'assistant.
     *
     * Les identifiants ne sont PAS cités ici : sans outil, ils ne servent à
     * rien et invitent le modèle à faire semblant. Seuls les noms, pour qu'il
     * puisse nommer ce qu'il ne peut plus relire.
     *
     * PURE. Vide si la conversation n'a touché aucun connecteur.
     *
     * @param list<array> $rows sortie de forConversation()
     */
    public static function regenerationNote(array $rows): string
    {
        $noms = [];
        foreach ($rows as $row) {
            $nom = self::oneLine((string)($row['name'] ?? ''));
            if ($nom !== '' && !in_array($nom, $noms, true)) {
                $noms[] = $nom;
            }
            if (count($noms) >= 4) {
                break;
            }
        }
        if ($noms === []) {
            return '';
        }

        return "\n\n" . t("Note : cette réponse est une RÉGÉNÉRATION, et les connecteurs (Drive, Gmail, agendas, tâches) ainsi que la recherche Internet n'y sont pas disponibles — tu ne peux donc PAS relire {objets}, dont il a été question dans cette conversation. Appuie-toi sur ce qui figure déjà dans l'historique. Si la réponse précédente reposait sur le contenu de ces éléments, dis clairement que tu n'as pas pu le relire pour cette régénération et invite l'utilisateur à renvoyer sa demande (les connecteurs y seront actifs) plutôt que de régénérer. N'invente jamais leur contenu.", ['objets' => I18n::listeCitee($noms)]);
    }

    // ===================== PERSISTANCE =====================

    /**
     * Mémorise un objet touché. Best-effort ABSOLU : toute erreur est avalée
     * (journalisée) — une conversation ne doit jamais échouer parce qu'on n'a
     * pas réussi à noter ce qu'elle vient de lire.
     */
    public static function record(int $conversationId, string $kind, string $ref, string $name, ?string $extra = null): void
    {
        if ($conversationId <= 0) {
            return;
        }
        $entry = self::normalizeEntry($kind, $ref, $name, $extra);
        if ($entry === null) {
            return;
        }
        try {
            $db = Database::getInstance();
            // Revoir le même objet le REMONTE en tête (updated_at) au lieu de
            // créer un doublon : c'est la récence qui fait le sens de « ce
            // document ». `name = VALUES(name)` suit un renommage.
            $stmt = $db->prepare(
                'INSERT INTO conversation_connector_refs
                     (conversation_id, connector, kind, ref_id, name, extra)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     name = VALUES(name), extra = VALUES(extra), kind = VALUES(kind),
                     updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                $conversationId, $entry['connector'], $entry['kind'],
                $entry['ref'], $entry['name'], $entry['extra'],
            ]);
            self::prune($conversationId);
        } catch (\Throwable $e) {
            error_log('[connecteurs] mémoire de conversation non écrite (conv ' . $conversationId . ') : ' . $e->getMessage());
        }
    }

    /** Enregistre l'objet désigné par un appel d'outil, s'il y en a un. */
    public static function recordToolCall(int $conversationId, string $tool, array $args, array $result): void
    {
        $entry = self::entryForTool($tool, $args, $result);
        if ($entry !== null) {
            self::record($conversationId, $entry['kind'], $entry['ref'], $entry['name'], $entry['extra']);
        }
    }

    /**
     * Oublie un objet — il n'existe plus. SEUL cas actuel : une tâche Google
     * supprimée (aucun autre connecteur n'expose de suppression). Sans cet
     * oubli, le fragment continuerait de proposer un identifiant mort, et
     * l'appel suivant échouerait en 404 sans que le modèle comprenne pourquoi.
     */
    public static function forget(int $conversationId, string $kind, string $ref): void
    {
        $ref = trim($ref);
        if ($conversationId <= 0 || $ref === '') {
            return;
        }
        try {
            $stmt = Database::getInstance()->prepare(
                'DELETE FROM conversation_connector_refs
                  WHERE conversation_id = ? AND kind = ? AND ref_id = ?'
            );
            $stmt->execute([$conversationId, trim($kind), mb_substr($ref, 0, self::MAX_REF)]);
        } catch (\Throwable $e) {
            error_log('[connecteurs] oubli impossible (conv ' . $conversationId . ') : ' . $e->getMessage());
        }
    }

    /** @return list<array> entrées de la plus récente à la plus ancienne */
    public static function forConversation(int $conversationId): array
    {
        if ($conversationId <= 0) {
            return [];
        }
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT connector, kind, ref_id, name, extra, updated_at
                   FROM conversation_connector_refs
                  WHERE conversation_id = ?
                  ORDER BY updated_at DESC, id DESC
                  LIMIT ' . self::MAX_ENTRIES
            );
            $stmt->execute([$conversationId]);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[connecteurs] mémoire de conversation illisible (conv ' . $conversationId . ') : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fragment prêt à injecter pour cette conversation.
     *
     * @param list<string> $engagedConnectors clés des connecteurs engagés ce message
     */
    public static function promptFragment(int $conversationId, array $engagedConnectors): string
    {
        if ($engagedConnectors === []) {
            return '';
        }
        return self::fragmentFromRows(self::forConversation($conversationId), $engagedConnectors);
    }

    /** Purge les entrées au-delà de MAX_ENTRIES (les plus anciennes). */
    private static function prune(int $conversationId): void
    {
        $db   = Database::getInstance();
        // Sous-requête enveloppée : MySQL refuse de viser dans un DELETE la
        // table lue par la sous-requête sans cette dérivation.
        $stmt = $db->prepare(
            'DELETE FROM conversation_connector_refs
              WHERE conversation_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM conversation_connector_refs
                         WHERE conversation_id = ?
                         ORDER BY updated_at DESC, id DESC
                         LIMIT ' . self::MAX_ENTRIES . '
                    ) AS garde
                )'
        );
        $stmt->execute([$conversationId, $conversationId]);
    }

    /** Une seule ligne, bornée — un nom vient d'une API tierce. */
    private static function oneLine(string $raw): string
    {
        $clean = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        return trim((string)preg_replace('/\s+/u', ' ', $clean));
    }
}
