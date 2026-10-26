<?php

declare(strict_types=1);

/**
 * État d'une base de connaissances TEL QU'ON LE MONTRE — source unique.
 *
 * Répond à la question que l'écran laissait sans réponse : « mes sources
 * sont-elles dans la base vectorisée, et sinon, pourquoi ? ». Avant, il
 * fallait l'assembler soi-même à partir d'une pastille rouge, d'une ligne
 * grise dans la modale et de statuts document par document — et rien ne
 * disait qu'une synchronisation attendait son tour ou était en train de
 * tourner.
 *
 * Trois étages :
 *   - `documentState()` : verdict d'UN document (pur) ;
 *   - `summarize()`     : verdict d'une BASE, à partir des comptes de
 *                         documents et de ce qui tourne en ce moment (pur) ;
 *   - `forBases()`      : lit la base de données et appelle les deux autres
 *                         — seul point d'accès aux données, jamais bloquant.
 *
 * « Ce qui tourne » ne se lit PAS dans `sync_status` : un passage tué net y
 * laisse « syncing » pour toujours, et un job de synchronisation reste en
 * attente sans rien y écrire. La vérité vient du verrou MySQL que tient le
 * passage (`kb_sync_{id}`, relâché avec la connexion quand le processus
 * meurt) et de la file de jobs (`kb_sync:{id}`, `kb_reindex:{id}`).
 *
 * Libellés traduits par t() (clé = texte français) : la locale est celle de
 * la requête — page chargée ou rafraîchissement en direct, qui renvoie les
 * blocs tout rendus. Les motifs d'échec venus d'un fournisseur (erreur
 * d'extraction, de vectorisation, de synchronisation) sont du contenu : ils
 * restent tels quels.
 */
final class KbStatus
{
    // ── États d'une base, par ordre de priorité d'affichage ─────────────
    public const SYNCING    = 'syncing';     // un passage tourne (verrou tenu)
    public const REINDEXING = 'reindexing';  // réindexation en file ou en cours
    public const QUEUED     = 'queued';      // passage demandé, pas encore démarré
    public const INDEXING   = 'indexing';    // documents en cours de lecture / vectorisation
    public const STALLED    = 'stalled';     // passage arrêté avant la fin (processus tué)
    public const ERROR      = 'error';       // dernier passage en échec
    public const WAITING    = 'waiting';     // source configurée, jamais synchronisée
    public const EMPTY      = 'empty';       // aucun document
    public const PARTIAL    = 'partial';     // des documents à vérifier
    public const LATE       = 'late';        // passages attendus qui n'ont pas lieu
    public const OK         = 'ok';

    // ── Tons (la couleur à l'écran) ─────────────────────────────────────
    public const TONE_BUSY  = 'busy';
    public const TONE_OK    = 'ok';
    public const TONE_WARN  = 'warn';
    public const TONE_ERROR = 'error';
    public const TONE_IDLE  = 'idle';

    // ── États d'un document ─────────────────────────────────────────────
    public const DOC_PENDING    = 'pending';     // attend son tour
    public const DOC_EXTRACTING = 'extracting';  // texte en cours de lecture
    public const DOC_INDEXING   = 'indexing';    // extraits en cours de vectorisation
    public const DOC_INDEXED    = 'indexed';     // interrogeable
    public const DOC_NO_TEXT    = 'no_text';     // lu, mais rien d'exploitable dedans
    public const DOC_FAILED     = 'failed';      // illisible
    public const DOC_LEXICAL    = 'lexical';     // lu, non vectorisé : recherche par mots seulement

    /** Rafraîchissement de l'écran : rapide tant que quelque chose tourne. */
    public const POLL_BUSY_MS = 3000;
    public const POLL_IDLE_MS = 45000;

    /** États qui appellent un rafraîchissement rapide de l'écran. */
    private const BUSY_STATES = [self::SYNCING, self::REINDEXING, self::QUEUED, self::INDEXING, self::WAITING];

    // =====================================================================
    // Documents
    // =====================================================================

    /**
     * Verdict d'un document : clé, ton, libellé court, explication. Pur.
     *
     * Le piège que ce tri évite : « vectorisé » ne suffit pas à dire
     * « interrogeable ». Un PDF scanné termine en `done` avec ZÉRO extrait —
     * il est traité, et ne ressortira pourtant d'aucune recherche. Il reçoit
     * donc son propre verdict (« Sans texte »), en ambre.
     *
     * @return array{key:string, tone:string, label:string, detail:string}
     */
    public static function documentState(array $doc): array
    {
        $ext    = (string)($doc['extraction_status'] ?? 'pending');
        $vec    = (string)($doc['vector_status'] ?? 'pending');
        $chunks = (int)($doc['chunk_count'] ?? 0);

        if ($ext === 'failed') {
            return self::doc(self::DOC_FAILED, self::TONE_ERROR, t('Illisible'),
                self::reason($doc['extraction_error'] ?? null, t('Le texte du fichier n\'a pas pu être lu.')));
        }
        if ($ext === 'processing') {
            return self::doc(self::DOC_EXTRACTING, self::TONE_BUSY, t('Lecture…'), t('Le texte du fichier est en cours de lecture.'));
        }
        if ($ext !== 'done') {
            return self::doc(self::DOC_PENDING, self::TONE_IDLE, t('En attente'), t('Le document attend son tour.'));
        }
        if ($vec === 'failed') {
            return self::doc(self::DOC_LEXICAL, self::TONE_ERROR, t('Non vectorisé'),
                t('Absent de la recherche par le sens, consultable par mots-clés seulement — {motif}', [
                    'motif' => self::reason($doc['vector_error'] ?? null, t('la vectorisation a échoué.')),
                ]));
        }
        if ($vec !== 'done') {
            return self::doc(self::DOC_INDEXING, self::TONE_BUSY, t('Vectorisation…'), t('Ses extraits sont en cours de vectorisation.'));
        }
        if ($chunks === 0) {
            return self::doc(self::DOC_NO_TEXT, self::TONE_WARN, t('Sans texte'),
                t('Aucun texte n\'a pu en être tiré (document scanné, image sans texte ?) : il ne ressortira d\'aucune recherche.'));
        }
        return self::doc(self::DOC_INDEXED, self::TONE_OK, t('Indexé'),
            I18n::plural($chunks, '{n} extrait interrogeable', '{n} extraits interrogeables'));
    }

    /**
     * Comptes par verdict, à partir de lignes de documents OU de groupes
     * (`n` = nombre de documents du groupe, cf. forBases). Pur. Passer par
     * documentState() est ce qui garantit qu'une carte et la liste des
     * documents ne se contredisent jamais.
     *
     * @param iterable<array> $rows
     * @return array{total:int, indexed:int, no_text:int, failed:int, lexical:int, busy:int, pending:int}
     */
    public static function countDocuments(iterable $rows): array
    {
        $out = ['total' => 0, 'indexed' => 0, 'no_text' => 0, 'failed' => 0, 'lexical' => 0, 'busy' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $n      = (int)($row['n'] ?? 1);
            $bucket = match (self::documentState($row)['key']) {
                self::DOC_INDEXED                        => 'indexed',
                self::DOC_NO_TEXT                        => 'no_text',
                self::DOC_FAILED                         => 'failed',
                self::DOC_LEXICAL                        => 'lexical',
                self::DOC_EXTRACTING, self::DOC_INDEXING => 'busy',
                default                                  => 'pending',
            };
            $out['total']  += $n;
            $out[$bucket]  += $n;
        }
        return $out;
    }

    // =====================================================================
    // Base
    // =====================================================================

    /**
     * Verdict d'une base. Pur : tout ce qui vient de la base de données ou
     * de l'horloge arrive par les paramètres.
     *
     * @param array $kb     ligne `knowledge_bases` (sync_*, activity_json, is_system)
     * @param array $counts countDocuments()
     * @param array $live   sync_running (verrou tenu), sync_queued, reindex_active,
     *                      last_age (s depuis sync_last_at, horloge MySQL, null si
     *                      jamais), interval_min, now (time() PHP)
     */
    public static function summarize(array $kb, array $counts, array $live): array
    {
        // Base en direct (MyBrain) : ni documents ni passages — son état est
        // celui de sa connexion.
        if (KnowledgeBase::isRemote($kb)) {
            return self::summarizeRemote($kb, $counts);
        }

        $provider = (string)($kb['sync_provider'] ?? '');
        $synced   = $provider !== '' && (string)($kb['sync_folder_ref'] ?? '') !== '';
        $enabled  = $synced && (int)($kb['sync_enabled'] ?? 0) === 1;
        $status   = (string)($kb['sync_status'] ?? '');
        $now      = (int)($live['now'] ?? time());
        $lastAge  = isset($live['last_age']) && $live['last_age'] !== null ? max(0, (int)$live['last_age']) : null;
        $interval = max(1, (int)($live['interval_min'] ?? KbSync::DEFAULT_INTERVAL_MIN)) * 60;

        $report   = self::decode($kb['sync_report'] ?? null);
        $activity = self::decode($kb['activity_json'] ?? null);
        $issues   = array_values(array_filter(
            (array)($report['issues'] ?? []),
            static fn($i): bool => is_array($i) && (string)($i['name'] ?? '') !== ''
        ));
        $finishedAge = isset($report['finished']) ? max(0, $now - (int)$report['finished']) : $lastAge;

        $problems = $counts['failed'] + $counts['lexical'] + $counts['no_text'];
        $downloadFailed = (int)($report['failed'] ?? 0);

        $st = [
            'state'       => self::OK,
            'tone'        => self::TONE_OK,
            'title'       => '',
            'detail'      => '',
            'meta'        => '',
            'progress'    => null,
            'counts'      => $counts,
            'synced'      => $synced,
            'provider'    => $provider,
            'enabled'     => $enabled,
            'syncable'    => $enabled && (int)($kb['is_system'] ?? 0) === 0,
            'error'       => null,
            'folder'      => (string)($kb['sync_folder_name'] ?? ''),
            'report'      => $report,
            'report_line' => $report !== null ? self::reportLine($report) : '',
            'issues'      => $issues,
            'interval'    => (int)($interval / 60),
            'next'        => '',
            'last'        => $synced && $finishedAge !== null && $report !== null ? self::ago($finishedAge) : '',
        ];
        $paused = $enabled && !empty($live['auto_paused']);
        if ($enabled && $lastAge !== null && !$paused) {
            $st['next'] = self::inFuture($interval - $lastAge);
        }

        $activityKind = (string)($activity['kind'] ?? '');

        if (!empty($live['sync_running'])) {
            $st = self::withProgress($st, $activityKind === 'sync' ? $activity : null);
            $st['state'] = self::SYNCING;
            $st['tone']  = self::TONE_BUSY;
            $st['title'] = t('Synchronisation en cours');
        } elseif (!empty($live['reindex_active'])) {
            $st = self::withProgress($st, $activityKind === 'reindex' ? $activity : null);
            $st['state'] = self::REINDEXING;
            $st['tone']  = self::TONE_BUSY;
            $st['title'] = t('Réindexation en cours');
            if ($activityKind !== 'reindex') {
                $st['detail'] = t('Elle démarre dès que le serveur est disponible.');
            }
        } elseif (!empty($live['sync_queued'])) {
            $st['state']  = self::QUEUED;
            $st['tone']   = self::TONE_BUSY;
            $st['title']  = t('Synchronisation en attente');
            $st['detail'] = t('Elle démarre dès que le serveur est disponible.');
        } elseif ($counts['busy'] + $counts['pending'] > 0) {
            $done = $counts['total'] - $counts['busy'] - $counts['pending'];
            $st['state']    = self::INDEXING;
            $st['tone']     = self::TONE_BUSY;
            $st['title']    = t('Indexation en cours');
            $st['detail']   = t('Les documents ajoutés sont lus puis vectorisés.');
            $st['progress'] = self::progress($done, $counts['total']);
            $st['meta']     = $st['progress']['label'];
        } elseif ($synced && $status === 'syncing') {
            // Plus de verrou ni de job, mais l'état dit « en cours » : le
            // processus a été tué (redéploiement, plantage) au milieu du passage.
            $st['state']  = self::STALLED;
            $st['tone']   = self::TONE_WARN;
            $st['title']  = t('Synchronisation interrompue');
            $st['detail'] = ($activityKind === 'sync' && (int)($activity['total'] ?? 0) > 0)
                ? t('Le dernier passage s\'est arrêté avant la fin ({done} / {total}). Relancez-le.', [
                    'done'  => (int)($activity['done'] ?? 0),
                    'total' => (int)$activity['total'],
                ])
                : t('Le dernier passage s\'est arrêté avant la fin. Relancez-le.');
            $st['meta']   = $lastAge !== null ? self::ago($lastAge) : '';
        } elseif ($synced && $status === 'error') {
            $st['state']  = self::ERROR;
            $st['tone']   = self::TONE_ERROR;
            $st['title']  = t('Synchronisation en échec');
            $st['error']  = trim((string)($kb['sync_error'] ?? '')) !== ''
                ? trim((string)$kb['sync_error'])
                : t('Motif inconnu — voir les journaux du serveur.');
            $st['detail'] = $st['error'];
            $st['meta']   = $lastAge !== null ? self::ago($lastAge) : '';
        } elseif ($enabled && $lastAge === null && $status === '') {
            $st['state']  = self::WAITING;
            $st['tone']   = self::TONE_IDLE;
            $st['title']  = t('Première synchronisation à venir');
            $st['detail'] = $paused
                ? t('En pause : budget IA du mois atteint. « Synchroniser maintenant » reste possible.')
                : t('Elle démarre dans quelques instants.');
        } elseif ($counts['total'] === 0) {
            $st['state'] = self::EMPTY;
            $st['tone']  = self::TONE_IDLE;
            if ($enabled) {
                $ignored = $report !== null ? self::ignoredCount($report) : 0;
                $st['title']  = t('Aucun fichier repris');
                $st['detail'] = $ignored > 0
                    ? I18n::plural(
                        $ignored,
                        'Le dossier ne contient aucun fichier pris en charge ({n} fichier ignoré).',
                        'Le dossier ne contient aucun fichier pris en charge ({n} fichiers ignorés).'
                    )
                    : t('Le dossier est vide.');
                $st['meta'] = $st['last'];
            } elseif ($synced) {
                $st['title']  = t('Synchronisation désactivée');
                $st['detail'] = t('Cochez « Synchronisation automatique » dans la base pour la lancer.');
            } else {
                $st['title']  = t('Aucun document');
                $st['detail'] = t('Ajoutez des fichiers pour alimenter la base.');
            }
        } elseif ($problems > 0 || $downloadFailed > 0) {
            $st['state'] = self::PARTIAL;
            $st['tone']  = self::TONE_WARN;
            if ($counts['failed'] + $counts['lexical'] > 0) {
                $n = $counts['failed'] + $counts['lexical'];
                $st['title'] = I18n::plural($n, '{n} document en échec', '{n} documents en échec');
            } elseif ($counts['no_text'] > 0) {
                $st['title'] = I18n::plural($counts['no_text'], '{n} document sans texte', '{n} documents sans texte');
            } else {
                $st['title'] = I18n::plural($downloadFailed, '{n} fichier non téléchargé', '{n} fichiers non téléchargés');
            }
            $st['detail'] = t('Ouvrez la base pour voir lesquels et pourquoi.');
            $st['meta']   = $st['last'];
        } elseif ($enabled && $lastAge !== null && $lastAge > max(3 * $interval, $interval + 900)) {
            // Les passages ont cessé : sans worker ni fork, le déclencheur
            // paresseux ne fait rien, et rien d'autre ne le dirait.
            $st['state']  = self::LATE;
            $st['tone']   = self::TONE_WARN;
            if ($paused) {
                // Pas une panne : la pause voulue par le budget IA (même état,
                // autre explication — sinon « en retard » ferait chercher un bug).
                $st['title']  = t('Synchronisation suspendue');
                $st['detail'] = t('Budget IA du mois atteint : reprise automatique le 1er. « Synchroniser maintenant » reste possible.');
            } else {
                $st['title']  = t('Synchronisation en retard');
                $st['detail'] = I18n::plural(
                    (int)($interval / 60),
                    'Dernier passage {quand}, pour un rythme de {n} minute.',
                    'Dernier passage {quand}, pour un rythme de {n} minutes.',
                    ['quand' => self::ago($lastAge)]
                );
            }
            $st['meta']   = $st['last'];
        } else {
            $st['state']  = self::OK;
            $st['tone']   = self::TONE_OK;
            $st['title']  = $synced ? t('À jour') : t('Prête');
            $st['detail'] = I18n::plural($counts['indexed'], '{n} document indexé', '{n} documents indexés');
            if ($synced && !$enabled) {
                $st['detail'] = t('{documents} · synchronisation automatique désactivée', ['documents' => $st['detail']]);
            }
            $st['meta']   = $st['last'];
        }

        $st['busy'] = in_array($st['state'], self::BUSY_STATES, true);
        return $st;
    }

    /**
     * Verdict d'une base EN DIRECT (MyBrain). Pur — lit les colonnes
     * `remote_*` jointes par KnowledgeBase::all/forManager.
     *
     * Quatre cas, et le ton dit ce qu'on doit en faire : jamais testée
     * (inactif), en direct (ok), injoignable (ambre — passager, les messages
     * partent sans le cerveau et le disent), refusée (rouge — durable, la
     * base est grisée dans le chat jusqu'au prochain test réussi).
     */
    public static function summarizeRemote(array $kb, array $counts): array
    {
        $status = (string)($kb['remote_status'] ?? '');
        $app    = trim((string)($kb['remote_app_name'] ?? ''));
        $rights = trim((string)($kb['remote_rights'] ?? ''));
        $error  = trim((string)($kb['remote_error'] ?? ''));
        $age    = isset($kb['remote_checked_age']) && $kb['remote_checked_age'] !== null
            ? max(0, (int)$kb['remote_checked_age'])
            : null;

        $st = [
            'state'       => self::OK,
            'tone'        => self::TONE_OK,
            'title'       => '',
            'detail'      => '',
            'meta'        => $age !== null ? t('vérifiée {quand}', ['quand' => self::ago($age)]) : '',
            'progress'    => null,
            'counts'      => $counts,
            'synced'      => false,
            'provider'    => KnowledgeBase::BACKEND_MYBRAIN,
            'enabled'     => false,
            'syncable'    => false,
            'error'       => null,
            'folder'      => '',
            'report'      => null,
            'report_line' => '',
            'issues'      => [],
            'interval'    => 0,
            'next'        => '',
            'last'        => '',
            'remote'      => true,
            'remote_host' => (string)(parse_url((string)($kb['remote_url'] ?? ''), PHP_URL_HOST) ?? ''),
            'remote_app'  => $app,
            'remote_rights' => $rights === '' ? [] : explode(',', $rights),
            'busy'        => false,
        ];

        if ($status === '') {
            $st['state']  = self::WAITING;
            $st['tone']   = self::TONE_IDLE;
            $st['title']  = t('Connexion à vérifier');
            $st['detail'] = t('Renseignez l\'adresse et la clé, puis « Tester et enregistrer ».');
            // WAITING est un état « occupé » pour les bases synchronisées (le
            // premier passage arrive) ; ici rien n'arrive tout seul : pas de
            // rafraîchissement rapide.
            $st['busy']   = false;
        } elseif ($status === KnowledgeBaseRemote::STATUS_REFUSED) {
            $st['state']  = self::ERROR;
            $st['tone']   = self::TONE_ERROR;
            $st['title']  = t('Connexion refusée');
            $st['error']  = $error !== '' ? $error : t('MyBrain refuse la clé.');
            $st['detail'] = t('{motif} La base est grisée dans le chat jusqu\'au prochain test réussi.', ['motif' => $st['error']]);
        } elseif ($status === KnowledgeBaseRemote::STATUS_UNREACHABLE) {
            $st['state']  = self::STALLED;
            $st['tone']   = self::TONE_WARN;
            $st['title']  = t('MyBrain injoignable');
            $st['error']  = $error !== '' ? $error : t('Le serveur ne répond pas.');
            $st['detail'] = t('{motif} Les messages partent sans le cerveau, et le disent.', ['motif' => $st['error']]);
        } else {
            // Droits : identifiants de MyBrain (« lire », « nourrir »), cités tels quels.
            $st['title']  = t('En direct');
            $st['detail'] = t('Interrogé à chaque message')
                . ($app !== '' ? ' — ' . t('application « {app} »', ['app' => $app]) : '')
                . ($rights !== '' ? ' · ' . t('droits : {droits}', ['droits' => str_replace(',', ', ', $rights)]) : '');
        }

        return $st;
    }

    /**
     * Verdicts des bases données, clés = id. Lit la base de données en QUATRE
     * requêtes quel que soit le nombre de bases (comptes groupés, âges, jobs,
     * verrous) : l'écran le rappelle toutes les quelques secondes. Ne lève
     * jamais — un incident rend un verdict sans l'information manquante,
     * l'écran reste affichable.
     *
     * @param list<array> $kbs lignes `knowledge_bases` (forManager / findById)
     * @return array<int, array>
     */
    public static function forBases(array $kbs): array
    {
        $ids = [];
        foreach ($kbs as $kb) {
            $ids[] = (int)$kb['id'];
        }
        if ($ids === []) {
            return [];
        }
        $in       = implode(',', array_fill(0, count($ids), '?'));
        $db       = Database::getInstance();
        $groups   = array_fill_keys($ids, []);
        $ages     = [];
        $jobs     = [];
        $locks    = [];
        $interval = KbSync::intervalMinutes();

        try {
            // Groupes par statut et par « a des extraits » : quelques lignes par
            // base au lieu d'une par document, classées ensuite par
            // documentState() — la même règle que la liste des documents.
            $stmt = $db->prepare(
                "SELECT kb_id, extraction_status, vector_status, (chunk_count > 0) AS has_chunks, COUNT(*) AS n
                 FROM knowledge_base_documents
                 WHERE kb_id IN ($in)
                 GROUP BY kb_id, extraction_status, vector_status, has_chunks"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $row['chunk_count'] = (int)$row['has_chunks'];
                $groups[(int)$row['kb_id']][] = $row;
            }

            $stmt = $db->prepare(
                "SELECT id, TIMESTAMPDIFF(SECOND, sync_last_at, NOW()) AS last_age
                 FROM knowledge_bases WHERE id IN ($in)"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $ages[(int)$row['id']] = $row['last_age'] !== null ? (int)$row['last_age'] : null;
            }
        } catch (\Throwable $e) {
            error_log('[KB] état des bases : ' . $e->getMessage());
        }

        try {
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = 'kb_sync:' . $id;
                $keys[] = 'kb_reindex:' . $id;
            }
            $stmt = $db->prepare(
                "SELECT dedup_key, status FROM async_jobs
                 WHERE dedup_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")
                   AND status IN ('pending', 'running')"
            );
            $stmt->execute($keys);
            foreach ($stmt->fetchAll() as $row) {
                $jobs[(string)$row['dedup_key']] = (string)$row['status'];
            }
        } catch (\Throwable $e) {
            error_log('[KB] file des bases : ' . $e->getMessage());
        }

        // Verrou du passage (cf. KbSync::syncKb) : relâché avec la connexion
        // quand le processus meurt, il ne ment jamais sur « en cours ».
        $syncedIds = [];
        foreach ($kbs as $kb) {
            if ((string)($kb['sync_provider'] ?? '') !== '') {
                $syncedIds[] = (int)$kb['id'];
            }
        }
        if ($syncedIds !== []) {
            try {
                $stmt = $db->prepare(
                    'SELECT ' . implode(', ', array_fill(0, count($syncedIds), 'IS_USED_LOCK(?)'))
                );
                $stmt->execute(array_map(static fn(int $id): string => 'kb_sync_' . $id, $syncedIds));
                $row = $stmt->fetch(\PDO::FETCH_NUM) ?: [];
                foreach ($syncedIds as $i => $id) {
                    $locks[$id] = ($row[$i] ?? null) !== null;
                }
            } catch (\Throwable $e) {
                error_log('[KB] verrous de synchronisation : ' . $e->getMessage());
            }
        }

        $now = time();
        // Budget IA du mois atteint : la synchro AUTOMATIQUE est en pause
        // (KbSync::maybeRunDue). Lu une fois pour toutes les bases (mémo).
        $paused = false;
        try {
            $paused = AiBudget::blocksAutomaticSync();
        } catch (\Throwable) {
            $paused = false;
        }
        $out = [];
        foreach ($kbs as $kb) {
            $id  = (int)$kb['id'];
            $out[$id] = self::summarize($kb, self::countDocuments($groups[$id] ?? []), [
                // Un job de sync « en cours » tient le verrou quelques instants
                // plus tard : on ne l'affiche pas comme « en attente » entre-temps.
                'sync_running'   => ($locks[$id] ?? false) || ($jobs['kb_sync:' . $id] ?? '') === 'running',
                'sync_queued'    => ($jobs['kb_sync:' . $id] ?? '') === 'pending',
                'reindex_active' => isset($jobs['kb_reindex:' . $id]),
                'last_age'       => array_key_exists($id, $ages) ? $ages[$id] : null,
                'interval_min'   => $interval,
                'now'            => $now,
                'auto_paused'    => $paused,
            ]);
        }
        return $out;
    }

    // =====================================================================
    // Formes d'affichage (pures)
    // =====================================================================

    /** Durée écoulée : « à l'instant », « il y a 4 min », « il y a 2 h », « il y a 3 j ». */
    public static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 45) {
            return t('à l\'instant');
        }
        if ($seconds < 3600) {
            return t('il y a {n} min', ['n' => max(1, (int)round($seconds / 60))]);
        }
        if ($seconds < 86400) {
            return t('il y a {n} h', ['n' => intdiv($seconds, 3600)]);
        }
        return t('il y a {n} j', ['n' => intdiv($seconds, 86400)]);
    }

    /** Échéance : « dans 6 min », « dans 2 h » — « imminente » dans la minute ou en retard. */
    public static function inFuture(int $seconds): string
    {
        if ($seconds <= 60) {
            return t('imminente');
        }
        if ($seconds < 3600) {
            return t('dans {n} min', ['n' => (int)ceil($seconds / 60)]);
        }
        return t('dans {n} h', ['n' => intdiv($seconds, 3600)]);
    }

    /**
     * Bilan du dernier passage, en une ligne : « 3 ajoutés · 1 mis à jour ·
     * 2 non repris » — seulement ce qui a eu lieu, « aucun changement » sinon.
     */
    public static function reportLine(array $report): string
    {
        $parts = [];
        $add = static function (int $n, string $part) use (&$parts): void {
            if ($n > 0) {
                $parts[] = $part;
            }
        };
        $added   = (int)($report['added'] ?? 0);
        $updated = (int)($report['updated'] ?? 0);
        $renamed = (int)($report['renamed'] ?? 0);
        $deleted = (int)($report['deleted'] ?? 0);
        $failed  = (int)($report['failed'] ?? 0);
        $ignored = self::ignoredCount($report);
        $add($added, I18n::plural($added, '{n} ajouté', '{n} ajoutés'));
        $add($updated, t('{n} mis à jour', ['n' => $updated]));
        $add($renamed, I18n::plural($renamed, '{n} renommé', '{n} renommés'));
        $add($deleted, I18n::plural($deleted, '{n} retiré', '{n} retirés'));
        $add($failed, t('{n} en échec', ['n' => $failed]));
        $add($ignored, t('{n} non repris', ['n' => $ignored]));
        return $parts !== [] ? implode(' · ', $parts) : t('aucun changement');
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /** @return array{key:string, tone:string, label:string, detail:string} */
    private static function doc(string $key, string $tone, string $label, string $detail): array
    {
        return ['key' => $key, 'tone' => $tone, 'label' => $label, 'detail' => $detail];
    }

    /** Motif d'échec présentable : une ligne, bornée — ou le repli fourni. */
    private static function reason(mixed $raw, string $fallback): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
        if ($text === '') {
            return $fallback;
        }
        return mb_strlen($text) > 220 ? mb_substr($text, 0, 219) . '…' : $text;
    }

    private static function ignoredCount(array $report): int
    {
        return (int)($report['ignored_unsupported'] ?? 0)
            + (int)($report['ignored_too_big'] ?? 0)
            + (int)($report['ignored_over_cap'] ?? 0);
    }

    /** @return array{done:int, total:int, pct:int, label:string} */
    private static function progress(int $done, int $total): array
    {
        $total = max(0, $total);
        $done  = max(0, min($done, $total));
        return [
            'done'  => $done,
            'total' => $total,
            'pct'   => $total > 0 ? (int)floor($done * 100 / $total) : 0,
            'label' => $done . ' / ' . $total,
        ];
    }

    /**
     * Progression d'un passage vivant, lue dans `activity_json`. Sans total
     * connu (lecture du dossier), la barre est « indéterminée » : elle bouge
     * sans prétendre à un pourcentage.
     */
    private static function withProgress(array $st, ?array $activity): array
    {
        $phase = (string)($activity['phase'] ?? '');
        $total = (int)($activity['total'] ?? 0);
        if ($activity === null || $phase === 'listing' || ($phase === 'files' && $total === 0)) {
            $st['progress'] = ['done' => 0, 'total' => 0, 'pct' => 0, 'label' => '', 'indeterminate' => true];
            $st['detail']   = $activity === null ? t('Démarrage…') : t('Lecture du dossier…');
            return $st;
        }
        $st['progress'] = self::progress((int)($activity['done'] ?? 0), $total);
        $st['meta']     = $st['progress']['label'];
        $st['detail']   = $phase === 'cleanup'
            ? t('Retrait des fichiers disparus de la source…')
            : ((string)($activity['current'] ?? '') !== '' ? t('« {label} »', ['label' => (string)$activity['current']]) : '');
        return $st;
    }

    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
