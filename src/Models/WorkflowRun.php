<?php

declare(strict_types=1);

/**
 * Une exécution de workflow, ses passages par les briques et son dossier de
 * pièces. Persistance seule : l'enchaînement est l'affaire de WorkflowEngine.
 *
 * Les dates sont posées par PHP en UTC (ScheduledTask::sqlUtc) quand elles se
 * comparent à une heure PHP ; `started_at`/`updated_at` gardent l'horloge de
 * MySQL, qui ne sert qu'à l'affichage relatif.
 */
final class WorkflowRun
{
    public const RUNNING   = 'running';
    public const WAITING   = 'waiting';
    public const PAUSED    = 'paused';
    public const DONE      = 'done';
    public const STOPPED   = 'stopped';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    /** Statuts où plus rien ne bouge. */
    public const TERMINAL = [self::DONE, self::STOPPED, self::CANCELLED];
    /** Statuts qu'on peut reprendre à l'étape. */
    public const RESUMABLE = [self::FAILED, self::PAUSED];

    public const STEP_RUNNING = 'running';
    public const STEP_WAITING = 'waiting';
    public const STEP_DONE    = 'done';
    public const STEP_FAILED  = 'failed';
    public const STEP_CANCELLED = 'cancelled';

    public static function hydrate(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['workflow_id'] = (int)$row['workflow_id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['conversation_id'] = $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null;
        $row['version'] = (int)$row['version'];
        $row['definition'] = WorkflowSpec::normalize((array)json_decode((string)$row['definition_json'], true));
        $row['inputs'] = (array)json_decode((string)($row['inputs_json'] ?? ''), true);
        $row['error'] = $row['error_json'] !== null ? (array)json_decode((string)$row['error_json'], true) : null;
        $row['parent_run_id'] = isset($row['parent_run_id']) && $row['parent_run_id'] !== null ? (int)$row['parent_run_id'] : null;
        // Passage de l'exécution appelante qui attend celle-ci (brique « Appeler un workflow »).
        $row['caller_step_id'] = isset($row['caller_step_id']) && $row['caller_step_id'] !== null ? (int)$row['caller_step_id'] : null;
        return $row;
    }

    public static function create(
        int $workflowId, int $version, int $userId, array $definition, string $trigger,
        ?\DateTimeImmutable $slot, array $inputs, string $timezone, ?string $ref = null, ?int $parentRunId = null, ?int $callerStepId = null
    ): ?int {
        $db = Database::getInstance();
        try {
            $db->prepare(
                'INSERT INTO workflow_runs (workflow_id, version, user_id, trigger_kind, trigger_ref, parent_run_id, caller_step_id, slot_at, status, definition_json, inputs_json, timezone)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $workflowId, $version, $userId, mb_substr($trigger, 0, 16),
                $ref !== null && $ref !== '' ? mb_substr($ref, 0, 190) : null,
                $parentRunId, $callerStepId,
                $slot !== null ? ScheduledTask::sqlUtc($slot) : null,
                self::RUNNING, Workflow::encode($definition),
                json_encode($inputs, JSON_UNESCAPED_UNICODE), $timezone,
            ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return null; // créneau ou évènement déjà parti
            }
            throw $e;
        }
        return (int)$db->lastInsertId();
    }

    /** L'exécution recopie une autre version publiée (reprise avec la dernière version). */
    public static function refreeze(int $runId, array $definition, int $version): void
    {
        Database::getInstance()->prepare('UPDATE workflow_runs SET definition_json = ?, version = ? WHERE id = ?')
            ->execute([Workflow::encode($definition), $version, $runId]);
    }

    /** Exécution d'un évènement déjà parti (même workflow, même référence). */
    public static function findByRef(int $workflowId, string $ref): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_runs WHERE workflow_id = ? AND trigger_ref = ?');
        $stmt->execute([$workflowId, $ref]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /** Exécutions lancées par un appel de l'adresse depuis `$sinceSec` secondes (borne de débit). */
    public static function countRecent(int $workflowId, string $trigger, int $sinceSec): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ? AND trigger_kind = ? AND started_at >= (NOW() - INTERVAL ' . max(1, $sinceSec) . ' SECOND)'
        );
        $stmt->execute([$workflowId, $trigger]);
        return (int)$stmt->fetchColumn();
    }

    /** Profondeur d'une exécution dans une chaîne de workflows (0 = lancée par une personne ou un évènement). */
    public static function chainDepth(?int $parentRunId): int
    {
        $depth = 0;
        $seen = [];
        while ($parentRunId !== null && $parentRunId > 0 && $depth < 10 && !isset($seen[$parentRunId])) {
            $seen[$parentRunId] = true;
            $stmt = Database::getInstance()->prepare('SELECT parent_run_id FROM workflow_runs WHERE id = ?');
            $stmt->execute([$parentRunId]);
            $next = $stmt->fetchColumn();
            $depth++;
            $parentRunId = $next !== false && $next !== null ? (int)$next : null;
        }
        return $depth;
    }

    /**
     * Les exécutions au-dessus d'une exécution appelée (ou enchaînée), de la
     * plus proche à la racine : id, workflow, déclencheur. Bornée, sans boucle.
     *
     * @return list<array{id: int, workflow_id: int, trigger_kind: string}>
     */
    public static function ancestors(?int $parentRunId): array
    {
        $out = [];
        $seen = [];
        while ($parentRunId !== null && $parentRunId > 0 && count($out) < 10 && !isset($seen[$parentRunId])) {
            $seen[$parentRunId] = true;
            $stmt = Database::getInstance()->prepare('SELECT id, workflow_id, trigger_kind, parent_run_id FROM workflow_runs WHERE id = ?');
            $stmt->execute([$parentRunId]);
            $row = $stmt->fetch();
            if (!$row) {
                break;
            }
            $out[] = ['id' => (int)$row['id'], 'workflow_id' => (int)$row['workflow_id'], 'trigger_kind' => (string)$row['trigger_kind']];
            $parentRunId = $row['parent_run_id'] !== null ? (int)$row['parent_run_id'] : null;
        }
        return $out;
    }

    /** Passage qui attend cette exécution (brique « Appeler un workflow »), ou null. Lecture légère. */
    public static function callerStepOf(int $runId): ?int
    {
        $stmt = Database::getInstance()->prepare('SELECT caller_step_id FROM workflow_runs WHERE id = ?');
        $stmt->execute([$runId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (int)$v : null;
    }

    /** L'exécution lancée par un passage d'appel (brique « Appeler un workflow »), ou null. */
    public static function calledBy(int $stepId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_runs WHERE caller_step_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$stepId]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Exécutions appelées par celles-ci : passage appelant → {id, status,
     * current_key, workflow_id, name, color} — sans la définition (affichage).
     *
     * @param list<int> $stepIds
     * @return array<int, array>
     */
    public static function calledBySteps(array $stepIds): array
    {
        $stepIds = array_values(array_unique(array_filter(array_map('intval', $stepIds))));
        if ($stepIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($stepIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT r.id, r.caller_step_id, r.status, r.current_key, r.workflow_id, r.conversation_id, r.error_json, w.name, w.color
             FROM workflow_runs r JOIN workflows w ON w.id = r.workflow_id
             WHERE r.caller_step_id IN ($in) ORDER BY r.id ASC"
        );
        $stmt->execute($stepIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['caller_step_id']] = [
                'id' => (int)$row['id'], 'status' => (string)$row['status'], 'current_key' => (string)$row['current_key'],
                'workflow_id' => (int)$row['workflow_id'], 'conversation_id' => $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null,
                'name' => (string)$row['name'], 'color' => (string)$row['color'],
                'error' => $row['error_json'] !== null ? (array)json_decode((string)$row['error_json'], true) : null,
            ];
        }
        return $out;
    }

    /** Un passage par son identifiant (toutes exécutions), ou null. */
    public static function step(int $stepId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_run_steps WHERE id = ?');
        $stmt->execute([$stepId]);
        $row = $stmt->fetch();
        return $row ? self::hydrateStep($row) : null;
    }

    public static function attachConversation(int $runId, int $convId): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE workflow_runs SET conversation_id = ? WHERE id = ?')->execute([$convId, $runId]);
        $db->prepare('UPDATE conversations SET workflow_run_id = ? WHERE id = ?')->execute([$runId, $convId]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_runs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $run = self::find($id);
        return $run !== null && $run['user_id'] === $userId ? $run : null;
    }

    /** Relit l'exécution SOUS VERROU (transaction de l'appelant). */
    public static function lock(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_runs WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function forConversation(int $convId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_runs WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$convId]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Historique des exécutions (WorkflowJournal) : les lancements d'une
     * personne, du plus récent au plus ancien, tous workflows confondus ou
     * ceux d'un seul (`$workflowId`). Chacun porte son numéro POUR SON
     * workflow (1 = le premier lancé), le nom, la couleur et la version
     * publiée de son workflow. `$beforeId` donne la page suivante,
     * `$statuses` ne garde que ces états (un filtre de la liste).
     *
     * @param list<string> $statuses
     * @return list<array>
     */
    public static function history(int $userId, ?int $workflowId, int $limit, ?int $beforeId = null, array $statuses = []): array
    {
        [$where, $params] = self::historyScope($userId, $workflowId, $statuses);
        if ($beforeId !== null) {
            $where .= ' AND r.id < ?';
            $params[] = $beforeId;
        }
        $sql = 'SELECT r.*, ' . self::TIMER_SQL . ' AS timer_at,
                       (SELECT COUNT(*) FROM workflow_runs o WHERE o.workflow_id = r.workflow_id AND o.user_id = r.user_id AND o.id <= r.id) AS ordinal,
                       w.name AS workflow_name, w.color AS workflow_color,
                       CASE WHEN w.published_json IS NULL THEN NULL ELSE w.published_version END AS workflow_latest
                FROM workflow_runs r
                JOIN workflows w ON w.id = r.workflow_id
                WHERE ' . $where . '
                ORDER BY r.id DESC LIMIT ' . max(1, min(201, $limit));
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Lancements de l'historique à partir de `$fromId` inclus (même périmètre
     * que history()) : ce qu'il faut charger pour qu'un lancement choisi, ou
     * déjà affiché, reste dans la liste.
     *
     * @param list<string> $statuses
     */
    public static function countHistoryFrom(int $userId, ?int $workflowId, array $statuses, int $fromId): int
    {
        [$where, $params] = self::historyScope($userId, $workflowId, $statuses);
        $params[] = $fromId;
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM workflow_runs r WHERE ' . $where . ' AND r.id >= ?');
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @param list<string> $statuses @return array{0: string, 1: list<int|string>} */
    private static function historyScope(int $userId, ?int $workflowId, array $statuses): array
    {
        $where = 'r.user_id = ?';
        $params = [$userId];
        if ($workflowId !== null) {
            $where .= ' AND r.workflow_id = ?';
            $params[] = $workflowId;
        }
        $statuses = array_values(array_unique(array_map('strval', $statuses)));
        if ($statuses !== []) {
            $where .= ' AND r.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        return [$where, $params];
    }

    /** Numéro d'un lancement pour son workflow (1 = le premier). */
    public static function ordinal(array $run): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ? AND user_id = ? AND id <= ?');
        $stmt->execute([(int)$run['workflow_id'], (int)$run['user_id'], (int)$run['id']]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * États qu'une suppression interrompt : l'exécution n'est pas finie. Une
     * exécution en échec ou en pause (reprenable) en fait partie — après la
     * suppression, plus rien ne pourrait la reprendre, ses cartes resteraient.
     */
    public const UNFINISHED = [self::RUNNING, self::WAITING, self::PAUSED, self::FAILED];

    /** États dits « en cours » dans la fenêtre de suppression (une exécution en échec ne tourne plus). */
    public const LIVE = [self::RUNNING, self::WAITING, self::PAUSED];

    /**
     * Ce que la suppression d'un workflow emporte, workflow par workflow, en
     * UNE lecture : ses lancements (l'historique qui disparaît avec lui) et
     * ceux encore en cours (qu'elle arrête).
     *
     * @return array<int, array{runs: int, live: int}>
     */
    public static function deletionFacts(int $userId, ?int $workflowId = null): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT workflow_id, status, COUNT(*) AS n FROM workflow_runs WHERE user_id = ?'
            . ($workflowId !== null ? ' AND workflow_id = ?' : '') . ' GROUP BY workflow_id, status'
        );
        $stmt->execute($workflowId !== null ? [$userId, $workflowId] : [$userId]);
        return self::foldDeletionFacts($stmt->fetchAll());
    }

    /**
     * Forme pure de deletionFacts().
     *
     * @param list<array{workflow_id: int|string, status: string, n: int|string}> $rows
     * @return array<int, array{runs: int, live: int}>
     */
    public static function foldDeletionFacts(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['workflow_id'];
            $n = (int)$row['n'];
            $out[$id] ??= ['runs' => 0, 'live' => 0];
            $out[$id]['runs'] += $n;
            if (in_array((string)$row['status'], self::LIVE, true)) {
                $out[$id]['live'] += $n;
            }
        }
        return $out;
    }

    /** Exécutions d'un workflow qui ne sont pas finies (cf. UNFINISHED) — à arrêter avant de le supprimer. */
    public static function unfinishedFor(int $workflowId, int $userId): array
    {
        $in = implode(',', array_fill(0, count(self::UNFINISHED), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM workflow_runs WHERE workflow_id = ? AND user_id = ? AND status IN ($in) ORDER BY id"
        );
        $stmt->execute([$workflowId, $userId, ...self::UNFINISHED]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Lancements par état — tous, pas seulement la page affichée ; d'un seul
     * workflow, ou de tous ceux de la personne (`$workflowId` null).
     *
     * @return array<string, int>
     */
    public static function historyCounts(int $userId, ?int $workflowId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT status, COUNT(*) AS n FROM workflow_runs WHERE user_id = ?' . ($workflowId !== null ? ' AND workflow_id = ?' : '') . ' GROUP BY status');
        $stmt->execute($workflowId !== null ? [$userId, $workflowId] : [$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['status']] = (int)$row['n'];
        }
        return $out;
    }

    /**
     * Ce qui a lancé plusieurs exécutions parties sur un évènement, en une
     * lecture et sans tirer leur contenu (un fichier reçu peut peser des
     * centaines de Ko) : l'objet et l'expéditeur d'un email, le nom d'un
     * fichier, le début d'un texte reçu (WorkflowJournal::context).
     *
     * @return array<int, array{fields: array{objet: string, expediteur: string}, name: string, text: string}>
     */
    public static function eventHeadlines(array $runIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $runIds), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            "SELECT run_id,
                    IF(JSON_VALID(content_json), JSON_UNQUOTE(JSON_EXTRACT(content_json, '$.fields.objet')), NULL) AS objet,
                    IF(JSON_VALID(content_json), JSON_UNQUOTE(JSON_EXTRACT(content_json, '$.fields.expediteur')), NULL) AS expediteur,
                    IF(JSON_VALID(content_json), JSON_UNQUOTE(JSON_EXTRACT(content_json, '$.name')), NULL) AS name,
                    IF(JSON_VALID(content_json), LEFT(JSON_UNQUOTE(JSON_EXTRACT(content_json, '$.text')), 240), NULL) AS text
             FROM workflow_artifacts
             WHERE art_key = ? AND run_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([WorkflowSpec::EVENT_PIECE], $ids));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            // JSON_UNQUOTE d'une valeur JSON null rend la chaîne « null ».
            $clean = static fn(mixed $v): string => $v === null || $v === 'null' ? '' : (string)$v;
            $out[(int)$row['run_id']] = [
                'fields' => ['objet' => $clean($row['objet']), 'expediteur' => $clean($row['expediteur'])],
                'name'   => $clean($row['name']),
                'text'   => $clean($row['text']),
            ];
        }
        return $out;
    }

    /** Passages de plusieurs exécutions en une lecture. @return array<int, list<array>> */
    public static function stepsForRuns(array $runIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $runIds), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_run_steps WHERE run_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id ASC');
        $stmt->execute($ids);
        $out = array_fill_keys($ids, []);
        foreach ($stmt->fetchAll() as $row) {
            $step = self::hydrateStep($row);
            $out[$step['run_id']][] = $step;
        }
        return $out;
    }

    /**
     * Heure de reprise d'une exécution qui attend un MINUTEUR (brique
     * « Attendre ») — elle n'attend alors personne : ni « Attend votre
     * décision », ni « Ce qui vous attend ».
     */
    private const TIMER_SQL = "(SELECT MIN(ts.resume_at) FROM workflow_run_steps ts WHERE ts.run_id = r.id AND ts.status = 'waiting' AND ts.resume_at IS NOT NULL)";

    /** Heure de reprise (UTC) du minuteur en cours, ou null. */
    public static function timerAt(int $runId): ?string
    {
        $stmt = Database::getInstance()->prepare("SELECT MIN(resume_at) FROM workflow_run_steps WHERE run_id = ? AND status = 'waiting' AND resume_at IS NOT NULL");
        $stmt->execute([$runId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (string)$v : null;
    }

    /** Exécutions qui attendent la personne (validation, cartes d'actions) — pas un minuteur. */
    public static function waitingForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT r.*, w.name AS workflow_name, w.color AS workflow_color FROM workflow_runs r
             JOIN workflows w ON w.id = r.workflow_id
             WHERE r.user_id = ? AND r.status = 'waiting' AND " . self::TIMER_SQL . " IS NULL ORDER BY r.id DESC LIMIT 20"
        );
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function setState(int $id, string $status, ?string $currentKey = null, ?array $error = null): void
    {
        $terminal = in_array($status, self::TERMINAL, true);
        $sets = ['status = ?'];
        $params = [$status];
        if ($currentKey !== null) {
            $sets[] = 'current_key = ?';
            $params[] = $currentKey;
        }
        $sets[] = 'error_json = ?';
        $params[] = $error !== null ? json_encode($error, JSON_UNESCAPED_UNICODE) : null;
        $sets[] = 'finished_at = ' . ($terminal ? 'NOW()' : 'NULL');
        $params[] = $id;
        Database::getInstance()->prepare('UPDATE workflow_runs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    // ------------------------------------------------------------------
    // Passages
    // ------------------------------------------------------------------

    public static function steps(int $runId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_run_steps WHERE run_id = ? ORDER BY id ASC');
        $stmt->execute([$runId]);
        return array_map([self::class, 'hydrateStep'], $stmt->fetchAll());
    }

    private static function hydrateStep(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['run_id'] = (int)$row['run_id'];
        $row['attempt'] = (int)$row['attempt'];
        $row['job_id'] = $row['job_id'] !== null ? (int)$row['job_id'] : null;
        $row['message_id'] = $row['message_id'] !== null ? (int)$row['message_id'] : null;
        $row['pending_action_id'] = $row['pending_action_id'] !== null ? (int)$row['pending_action_id'] : null;
        $row['detail'] = $row['detail_json'] !== null ? (array)json_decode((string)$row['detail_json'], true) : [];
        $row['resume_at'] = isset($row['resume_at']) && $row['resume_at'] !== null ? (string)$row['resume_at'] : null;
        return $row;
    }

    public static function lastStep(int $runId, string $nodeKey): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM workflow_run_steps WHERE run_id = ? AND node_key = ? ORDER BY attempt DESC LIMIT 1'
        );
        $stmt->execute([$runId, $nodeKey]);
        $row = $stmt->fetch();
        return $row ? self::hydrateStep($row) : null;
    }

    public static function stepByJob(int $jobId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_run_steps WHERE job_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row ? self::hydrateStep($row) : null;
    }

    public static function stepByAction(int $actionId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_run_steps WHERE pending_action_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$actionId]);
        $row = $stmt->fetch();
        return $row ? self::hydrateStep($row) : null;
    }

    /** Ouvre une tentative ; rend son id. */
    public static function insertStep(int $runId, string $nodeKey, string $status, array $detail = []): int
    {
        $last = self::lastStep($runId, $nodeKey);
        $attempt = $last !== null ? $last['attempt'] + 1 : 1;
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO workflow_run_steps (run_id, node_key, attempt, status, detail_json) VALUES (?,?,?,?,?)'
        )->execute([$runId, $nodeKey, min(255, $attempt), $status, $detail !== [] ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
        return (int)$db->lastInsertId();
    }

    /** Met à jour une tentative (champs nommés). */
    public static function updateStep(int $stepId, array $fields): void
    {
        $allowed = ['status', 'outcome', 'job_id', 'message_id', 'pending_action_id'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = $k . ' = ?';
                $params[] = $v;
            } elseif ($k === 'detail') {
                $sets[] = 'detail_json = ?';
                $params[] = $v !== null ? json_encode($v, JSON_UNESCAPED_UNICODE) : null;
            } elseif ($k === 'finished') {
                $sets[] = 'finished_at = ' . ($v ? 'NOW()' : 'NULL');
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $stepId;
        Database::getInstance()->prepare('UPDATE workflow_run_steps SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * Fait passer une tentative d'un statut à un autre, UNE seule fois : rend
     * false si elle n'était plus dans le statut attendu (fin de job rejouée,
     * double clic). C'est la garde d'idempotence du moteur.
     */
    public static function transitionStep(int $stepId, string $from, string $to, string $outcome = '', ?array $detail = null): bool
    {
        $sql = 'UPDATE workflow_run_steps SET status = ?, outcome = IF(? = \'\', outcome, ?), finished_at = '
            . (in_array($to, [self::STEP_DONE, self::STEP_FAILED, self::STEP_CANCELLED], true) ? 'NOW()' : 'finished_at')
            . ($detail !== null ? ', detail_json = ?' : '')
            . ' WHERE id = ? AND status = ?';
        $params = [$to, $outcome, $outcome];
        if ($detail !== null) {
            $params[] = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $params[] = $stepId;
        $params[] = $from;
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Une tentative en cours change de job (étape qui remet une création : le
     * tour, puis la fabrication), UNE seule fois : rend false si elle n'était
     * plus en cours ou plus rattachée à `$fromJob` (fin de job rejouée).
     */
    public static function swapStepJob(int $stepId, int $fromJob, int $toJob, array $detail): bool
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE workflow_run_steps SET job_id = ?, detail_json = ? WHERE id = ? AND status = '" . self::STEP_RUNNING . "' AND job_id = ?"
        );
        $stmt->execute([$toJob, json_encode($detail, JSON_UNESCAPED_UNICODE), $stepId, $fromJob]);
        return $stmt->rowCount() > 0;
    }

    /** Heure de reprise d'une tentative en attente (attente, nouvel essai), null pour l'effacer. */
    public static function setResumeAt(int $stepId, ?\DateTimeImmutable $at): void
    {
        Database::getInstance()->prepare('UPDATE workflow_run_steps SET resume_at = ? WHERE id = ?')
            ->execute([$at !== null ? ScheduledTask::sqlUtc($at) : null, $stepId]);
    }

    /** Nombre de tentatives « renvoyées » d'une validation. */
    public static function countOutcome(int $runId, string $nodeKey, string $outcome): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ? AND node_key = ? AND outcome = ?'
        );
        $stmt->execute([$runId, $nodeKey, $outcome]);
        return (int)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Dossier
    // ------------------------------------------------------------------

    /** @return array<string, array> clé → {key, node_key, name, form, content, provenance, version, updated_at} */
    /**
     * Le dossier d'une exécution. `$withItems` faux : sans les éléments figés
     * des boucles (`<boucle>~items`, des centaines de lignes) — ils ne sont
     * pas une pièce, le moteur les relit à part (artifact()).
     */
    public static function artifacts(int $runId, bool $withItems = true): array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_artifacts WHERE run_id = ?'
            . ($withItems ? '' : " AND art_key NOT LIKE '%~items'") . ' ORDER BY id ASC');
        $stmt->execute([$runId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['art_key']] = self::artifactRow($row);
        }
        return $out;
    }

    /** Une pièce du dossier, par sa clé, ou null. */
    public static function artifact(int $runId, string $key): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflow_artifacts WHERE run_id = ? AND art_key = ?');
        $stmt->execute([$runId, $key]);
        $row = $stmt->fetch();
        return $row ? self::artifactRow($row) : null;
    }

    private static function artifactRow(array $row): array
    {
        return [
                'key'        => (string)$row['art_key'],
                'node_key'   => (string)$row['node_key'],
                'name'       => (string)$row['name'],
                'form'       => (string)$row['form'],
                'content'    => (array)json_decode((string)$row['content_json'], true),
                'provenance' => $row['provenance_json'] !== null ? (array)json_decode((string)$row['provenance_json'], true) : [],
                'version'    => (int)$row['version'],
                'updated_at' => (string)$row['updated_at'],
        ];
    }

    public static function saveArtifact(int $runId, string $key, string $nodeKey, string $name, string $form, array $content, array $provenance = []): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO workflow_artifacts (run_id, art_key, node_key, name, form, content_json, provenance_json)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE node_key = VALUES(node_key), name = VALUES(name), form = VALUES(form),
                 content_json = VALUES(content_json), provenance_json = VALUES(provenance_json), version = version + 1'
        )->execute([
            $runId, $key, $nodeKey, mb_substr($name, 0, 120), $form,
            json_encode($content, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $provenance !== [] ? json_encode($provenance, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function deleteArtifact(int $runId, string $key): void
    {
        Database::getInstance()->prepare('DELETE FROM workflow_artifacts WHERE run_id = ? AND art_key = ?')->execute([$runId, $key]);
    }

    /**
     * Heure de démarrage dans le fuseau de l'exécution (MySQL écrit en UTC,
     * comme les tâches programmées).
     */
    public static function startedLabel(array $run): string
    {
        $d = ScheduledTask::fromSqlUtc((string)($run['started_at'] ?? ''));
        if ($d === null) {
            return '';
        }
        return Format::shortDateTime($d->setTimezone(Recurrence::zone(ScheduledTask::normalizeTimezone((string)($run['timezone'] ?? '')))));
    }

    /** Coût de l'exécution : crédits de toutes les opérations de sa conversation. */
    /** Exécutions terminées relues pour dire ce qu'un workflow coûte vraiment. */
    public const RECENT_RUNS = 10;

    /**
     * Ce qu'ont réellement coûté les dernières exécutions TERMINÉES d'un
     * workflow (menées au bout ou arrêtées comme prévu) : tout ce que leur
     * conversation a débité, étapes, conditions, créations comprises. Une
     * exécution qui n'a rien coûté (aucun appel de modèle) ne compte pas. La
     * définition a pu changer depuis : c'est un repère, l'estimation reste
     * celle de la version en cours. Null sans exécution facturée ; ne lève
     * jamais.
     *
     * @return array{runs:int, avg:int, min:int, max:int}|null
     */
    public static function recentCredits(int $workflowId, int $limit = self::RECENT_RUNS): ?array
    {
        if ($workflowId <= 0) {
            return null;
        }
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT r.id, (SELECT COALESCE(SUM(u.credits_used), 0) FROM usage_logs u WHERE u.conversation_id = r.conversation_id)
                      + (SELECT COALESCE(SUM(t.credits_used), 0) FROM tool_usage_logs t WHERE t.conversation_id = r.conversation_id) AS credits
                 FROM workflow_runs r
                 WHERE r.workflow_id = ? AND r.status IN ('" . self::DONE . "', '" . self::STOPPED . "') AND r.conversation_id IS NOT NULL
                 ORDER BY r.id DESC
                 LIMIT " . max(1, min(50, $limit))
            );
            $stmt->execute([$workflowId]);
            // Une exécution paie aussi les workflows qu'elle a appelés (leurs
            // étapes sont comptées dans LEUR conversation).
            return self::summarizeCredits(array_map(
                static fn(array $row): int => (int)$row['credits'] + self::calledCredits((int)$row['id']),
                $stmt->fetchAll()
            ));
        } catch (\Throwable $e) {
            error_log('[Workflows] coût des dernières exécutions illisible : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Moyenne, minimum et maximum de crédits débités (pure) ; les exécutions
     * à zéro écartées. Null s'il n'en reste aucune.
     *
     * @param list<int> $credits
     * @return array{runs:int, avg:int, min:int, max:int}|null
     */
    public static function summarizeCredits(array $credits): ?array
    {
        $paid = array_values(array_filter($credits, static fn($c): bool => (int)$c > 0));
        if ($paid === []) {
            return null;
        }
        return [
            'runs' => count($paid),
            'avg'  => (int)round(array_sum($paid) / count($paid)),
            'min'  => (int)min($paid),
            'max'  => (int)max($paid),
        ];
    }

    /**
     * Crédits débités par les workflows qu'une exécution a APPELÉS (brique
     * « Appeler un workflow »), leurs propres appels compris. Ne lève jamais.
     */
    public static function calledCredits(int $runId, int $depth = 0): int
    {
        if ($runId <= 0 || $depth > 4) {
            return 0;
        }
        try {
            $stmt = Database::getInstance()->prepare('SELECT id, conversation_id FROM workflow_runs WHERE parent_run_id = ? AND caller_step_id IS NOT NULL');
            $stmt->execute([$runId]);
            $total = 0;
            foreach ($stmt->fetchAll() as $row) {
                $total += self::credits($row['conversation_id'] !== null ? (int)$row['conversation_id'] : null)
                    + self::calledCredits((int)$row['id'], $depth + 1);
            }
            return $total;
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function credits(?int $convId): int
    {
        if (!$convId) {
            return 0;
        }
        try {
            return UsageLog::conversationCreditsTotal($convId);
        } catch (\Throwable) {
            return 0;
        }
    }
}
