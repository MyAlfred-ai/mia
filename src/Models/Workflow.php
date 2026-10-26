<?php

declare(strict_types=1);

/**
 * Un workflow et ses deux versions : le brouillon (édité au fil de l'eau) et
 * la version publiée (la seule qui s'exécute). La forme d'une définition est
 * l'affaire de WorkflowSpec ; ici, la persistance, les droits et le départ
 * programmé (`schedule_next_at`, en UTC).
 *
 * Un workflow appartient à une PERSONNE, comme une tâche programmée : ses
 * étapes travaillent avec ses connexions, son quota et ses droits, revérifiés
 * à chaque exécution. Réservé aux créateurs et aux administrateurs.
 */
final class Workflow
{
    public const SETTING_ENABLED = 'workflows_enabled';
    /** « Agir sans validation » proposé dans les étapes et les actions. */
    public const SETTING_AUTO = 'workflows_auto_enabled';
    /** Éléments traités au plus par une boucle « pour chaque » (réglage de l'administrateur). */
    public const SETTING_LOOP_MAX = 'workflows_loop_max';
    /** Plafonds proposés à l'administrateur ; 200 par défaut. */
    public const LOOP_MAX_CHOICES = [50, 100, 200, 500];
    public const LOOP_MAX_DEFAULT = 200;

    public const MAX_PER_USER = 30;
    /** Durée de vie d'une carte d'approbation née d'un workflow (une semaine). */
    public const ACTION_TTL_MINUTES = 7 * 24 * 60;
    /** Couleur par défaut d'un workflow (celle des schémas). */
    public const DEFAULT_COLOR = '#10a37f';

    /** Icône du menu et des pages : deux briques reliées. */
    public const ICON_PATHS = '<rect x="3" y="3" width="8" height="8" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect x="13" y="13" width="8" height="8" rx="2"/>';

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED, '1') === '1';
    }

    public static function autoAllowed(): bool
    {
        return Settings::get(self::SETTING_AUTO, '1') === '1';
    }

    /**
     * Plafond des boucles « pour chaque » sur cette instance : ce qu'a choisi
     * l'administrateur (50, 100, 200, 500), 200 par défaut, jamais au-delà de
     * la borne de la forme (WorkflowSpec::LOOP_EACH_MAX).
     */
    public static function loopMax(): int
    {
        $v = (int)Settings::get(self::SETTING_LOOP_MAX, (string)self::LOOP_MAX_DEFAULT);
        return in_array($v, self::LOOP_MAX_CHOICES, true) ? min($v, WorkflowSpec::LOOP_EACH_MAX) : self::LOOP_MAX_DEFAULT;
    }

    /**
     * La personne peut-elle LANCER ses workflows (et voir les exécutions qui
     * attendent sa décision) ? Fonctionnalité proposée par l'administrateur,
     * compte actif, droit « Workflows — Utiliser » de son rôle — le moteur le
     * revérifie à chaque étape (une exécution se met en pause sinon).
     */
    public static function canUse(?array $user): bool
    {
        return self::allows($user, 'use');
    }

    /**
     * Droit de cette personne sur ses workflows (`view`, `use`, `create`,
     * `edit`, `delete`) : fonctionnalité proposée, compte actif, droit du
     * rôle (Personnes → Rôles). SOURCE UNIQUE des pages, des menus, de
     * l'outil `creer_workflow` et du moteur.
     */
    public static function allows(?array $user, string $action): bool
    {
        return $user !== null
            && self::isEnabled()
            && !empty($user['is_active'] ?? 1)
            && Role::userCan($user, 'workflows.' . $action);
    }

    /**
     * Pastille « Bêta » des entrées Workflows (barre latérale, menus, titre de
     * la page) : la fonctionnalité est en test, on le dit là où on y entre.
     * Un seul balisage pour toutes les surfaces — quatre copies divergeraient.
     */
    public static function betaChip(): string
    {
        return '<span class="beta-chip" title="' . htmlspecialchars(t('Fonctionnalité en phase de test : elle fonctionne, mais peut encore évoluer.'), ENT_QUOTES)
            . '">' . htmlspecialchars(t('Bêta'), ENT_QUOTES) . '</span>';
    }

    public static function icon(int $size = 16, string $class = ''): string
    {
        return '<svg' . ($class !== '' ? ' class="' . $class . '"' : '') . ' width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . self::ICON_PATHS . '</svg>';
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public static function hydrate(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['published_version'] = (int)$row['published_version'];
        $row['draft'] = WorkflowSpec::normalize((array)json_decode((string)$row['draft_json'], true));
        $row['published'] = $row['published_json'] !== null && $row['published_json'] !== ''
            ? WorkflowSpec::normalize((array)json_decode((string)$row['published_json'], true))
            : null;
        $row['is_active'] = (int)$row['is_active'] === 1;
        $row['draft_changed'] = (int)$row['draft_changed'] === 1;
        $color = (string)($row['color'] ?? '');
        $row['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR;
        return $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflows WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $row = self::findById($id);
        return $row !== null && $row['user_id'] === $userId ? $row : null;
    }

    /**
     * Les workflows d'une personne, avec leur dernière exécution (statut,
     * date, conversation) et leur nombre d'exécutions.
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT w.*,
                    (SELECT COUNT(*) FROM workflow_runs r WHERE r.workflow_id = w.id) AS run_count,
                    lr.id AS last_run_id, lr.status AS last_run_status, lr.started_at AS last_run_started,
                    lr.finished_at AS last_run_finished, lr.current_key AS last_run_key
             FROM workflows w
             LEFT JOIN workflow_runs lr ON lr.id = (
                 SELECT r2.id FROM workflow_runs r2 WHERE r2.workflow_id = w.id ORDER BY r2.id DESC LIMIT 1
             )
             WHERE w.user_id = ?
             ORDER BY w.updated_at DESC, w.id DESC'
        );
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function countForUser(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM workflows WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Workflows publiés et actifs d'une personne lançables depuis le chat. */
    public static function launchableForUser(int $userId): array
    {
        return array_values(array_filter(
            self::forUser($userId),
            static fn(array $w): bool => $w['published'] !== null && $w['is_active'] && !empty($w['published']['start']['chat'])
        ));
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    public static function create(int $userId, string $name, array $definition, ?string $templateKey = null, string $color = ''): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO workflows (user_id, name, color, draft_json, template_key) VALUES (?,?,?,?,?)'
        )->execute([
            $userId,
            self::cleanName($name),
            preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR,
            self::encode(WorkflowSpec::normalize($definition)),
            $templateKey,
        ]);
        return (int)$db->lastInsertId();
    }

    /** Enregistre le brouillon (nom, couleur, définition). */
    public static function saveDraft(int $id, int $userId, string $name, string $color, array $definition): bool
    {
        $wf = self::findForUser($id, $userId);
        if ($wf === null) {
            return false;
        }
        $draft = self::encode(WorkflowSpec::normalize($definition));
        $changed = $wf['published_json'] === null || $draft !== self::encode((array)$wf['published']) ? 1 : 0;
        Database::getInstance()->prepare(
            'UPDATE workflows SET name = ?, color = ?, draft_json = ?, draft_changed = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
        )->execute([
            self::cleanName($name),
            preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $wf['color'],
            $draft, $changed, $id, $userId,
        ]);
        return true;
    }

    /** Publie le brouillon ; rend le nouveau numéro de version. */
    public static function publish(int $id, int $userId, \DateTimeImmutable $now): ?int
    {
        $wf = self::findForUser($id, $userId);
        if ($wf === null) {
            return null;
        }
        $def = $wf['draft'];
        $next = $wf['is_active'] ? self::scheduleNext($def, $now) : null;
        // Une surveillance (boîte, dossier) se vérifie dès la publication :
        // la première vérification pose la ligne de base.
        $watch = $wf['is_active'] && WorkflowTriggers::watches($def) ? $now : null;
        $version = $wf['published_version'] + 1;
        Database::getInstance()->prepare(
            'UPDATE workflows SET published_json = ?, published_version = ?, published_at = ?, draft_changed = 0,
                    schedule_next_at = ?, watch_next_at = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        )->execute([
            self::encode($def), $version, ScheduledTask::sqlUtc($now),
            $next !== null ? ScheduledTask::sqlUtc($next) : null,
            $watch !== null ? ScheduledTask::sqlUtc($watch) : null, $id, $userId,
        ]);
        return $version;
    }

    public static function setActive(int $id, int $userId, bool $active, \DateTimeImmutable $now): bool
    {
        $wf = self::findForUser($id, $userId);
        if ($wf === null) {
            return false;
        }
        $next = $active && $wf['published'] !== null ? self::scheduleNext($wf['published'], $now) : null;
        $watch = $active && WorkflowTriggers::watches($wf['published']) ? $now : null;
        $db = Database::getInstance();
        $db->prepare(
            'UPDATE workflows SET is_active = ?, schedule_next_at = ?, watch_next_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$active ? 1 : 0, $next !== null ? ScheduledTask::sqlUtc($next) : null, $watch !== null ? ScheduledTask::sqlUtc($watch) : null, $id, $userId]);
        if ($active && $watch !== null) {
            // Réactivé : ce qui est arrivé pendant la pause ne lance rien.
            $db->prepare('UPDATE workflows SET watch_state_json = NULL WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        }
        return true;
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM workflows WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Départ programmé
    // ------------------------------------------------------------------

    /** Prochaine exécution programmée d'une définition (UTC), ou null. */
    public static function scheduleNext(array $def, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $schedule = $def['start']['schedule'] ?? null;
        if (!is_array($schedule)) {
            return null;
        }
        return ScheduledTask::nextRunFor($schedule['rule'], (string)$schedule['timezone'], $now);
    }

    /** @return list<int> */
    public static function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM workflows WHERE is_active = 1 AND schedule_next_at IS NOT NULL AND schedule_next_at <= ?
             ORDER BY schedule_next_at ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([ScheduledTask::sqlUtc($now)]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Réserve un workflow dû, dans la transaction de l'appelant : relit la
     * ligne SOUS VERROU (SKIP LOCKED — un autre worker l'a déjà) et rend
     * null si elle n'est plus due.
     */
    public static function lockDue(int $id, \DateTimeImmutable $now): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM workflows WHERE id = ? AND is_active = 1 AND schedule_next_at IS NOT NULL AND schedule_next_at <= ?
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([$id, ScheduledTask::sqlUtc($now)]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function setScheduleNext(int $id, ?\DateTimeImmutable $next): void
    {
        Database::getInstance()->prepare('UPDATE workflows SET schedule_next_at = ? WHERE id = ?')
            ->execute([$next !== null ? ScheduledTask::sqlUtc($next) : null, $id]);
    }

    // ------------------------------------------------------------------
    // Surveillances et adresse d'appel (WorkflowTriggers)
    // ------------------------------------------------------------------

    /** @return list<int> */
    public static function watchDueIds(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM workflows WHERE is_active = 1 AND watch_next_at IS NOT NULL AND watch_next_at <= ?
             ORDER BY watch_next_at ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([ScheduledTask::sqlUtc($now)]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Réserve une surveillance due (transaction de l'appelant, SKIP LOCKED). */
    public static function lockWatchDue(int $id, \DateTimeImmutable $now): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM workflows WHERE id = ? AND is_active = 1 AND watch_next_at IS NOT NULL AND watch_next_at <= ?
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([$id, ScheduledTask::sqlUtc($now)]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function setWatchNext(int $id, ?\DateTimeImmutable $next): void
    {
        Database::getInstance()->prepare('UPDATE workflows SET watch_next_at = ? WHERE id = ?')
            ->execute([$next !== null ? ScheduledTask::sqlUtc($next) : null, $id]);
    }

    /** État d'une surveillance : sig, seen, baseline_at, checked_at, error, launched. */
    public static function watchState(array $wf): array
    {
        $state = json_decode((string)($wf['watch_state_json'] ?? ''), true);
        return is_array($state) ? $state : [];
    }

    public static function saveWatchState(int $id, array $state): void
    {
        Database::getInstance()->prepare('UPDATE workflows SET watch_state_json = ? WHERE id = ?')
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $id]);
    }

    public static function setHook(int $id, string $hash, string $enc): void
    {
        Database::getInstance()->prepare('UPDATE workflows SET hook_token_hash = ?, hook_token_enc = ? WHERE id = ?')
            ->execute([$hash, $enc, $id]);
    }

    public static function findByHookHash(string $hash): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM workflows WHERE hook_token_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Workflows publiés et actifs de la personne qui démarrent « quand
     * `$workflowId` se termine ».
     */
    public static function followersOf(int $workflowId, int $userId): array
    {
        $out = [];
        foreach (self::forUser($userId) as $w) {
            $event = $w['published']['start']['event'] ?? null;
            if ($w['id'] !== $workflowId && $w['is_active'] && is_array($event)
                && $event['kind'] === 'workflow' && (int)($event['workflow_id'] ?? 0) === $workflowId) {
                $out[] = $w;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------

    public static function cleanName(string $name): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        return mb_substr($name !== '' ? $name : t('Nouveau workflow'), 0, WorkflowSpec::NAME_MAX);
    }

    public static function encode(array $def): string
    {
        return (string)json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
