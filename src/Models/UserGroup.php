<?php

declare(strict_types=1);

class UserGroup
{
    /** Cache par requête de l'id du groupe par défaut (0 = résolu absent). */
    private static ?int $defaultGroupIdCache = null;

    public static function all(): array
    {
        return Database::getInstance()->query(
            'SELECT g.*,
                    COUNT(ugm.user_id) AS users_count
             FROM user_groups g
             LEFT JOIN user_group_members ugm ON ugm.group_id = g.id
             GROUP BY g.id
             ORDER BY g.is_default DESC, g.name ASC'
        )->fetchAll();
    }

    /** ID du groupe « Par défaut » (contient tous les utilisateurs), null si absent. */
    public static function defaultGroupId(): ?int
    {
        if (self::$defaultGroupIdCache === null) {
            $id = Database::getInstance()
                ->query('SELECT id FROM user_groups WHERE is_default = 1 LIMIT 1')
                ->fetchColumn();
            self::$defaultGroupIdCache = $id !== false ? (int)$id : 0;
        }

        return self::$defaultGroupIdCache > 0 ? self::$defaultGroupIdCache : null;
    }

    public static function isDefaultGroup(int $groupId): bool
    {
        return $groupId > 0 && self::defaultGroupId() === $groupId;
    }

    /** Ajoute un utilisateur au groupe par défaut (à appeler à chaque création de compte). */
    public static function addUserToDefaultGroup(int $userId): void
    {
        $defaultId = self::defaultGroupId();
        if ($defaultId !== null) {
            self::addUserToGroup($userId, $defaultId);
        }
    }

    /** Ré-synchronise le groupe par défaut : tous les utilisateurs en sont membres. Idempotent. */
    public static function syncDefaultGroupMembers(): void
    {
        $defaultId = self::defaultGroupId();
        if ($defaultId === null) {
            return;
        }

        Database::getInstance()->prepare(
            'INSERT IGNORE INTO user_group_members (user_id, group_id)
             SELECT id, ? FROM users'
        )->execute([$defaultId]);
    }

    public static function allWithAssignments(): array
    {
        $groups = self::all();
        if (empty($groups)) {
            return [];
        }

        $groupIds = array_map(static fn(array $g): int => (int)$g['id'], $groups);
        $profileMap = self::profileIdsByGroup($groupIds);
        $toolMap = self::toolIdsByGroup($groupIds);
        $llmMap = self::llmKeysByGroupMulti($groupIds);

        foreach ($groups as &$group) {
            $gid = (int)$group['id'];
            $group['profile_ids'] = $profileMap[$gid] ?? [];
            $group['tool_ids'] = $toolMap[$gid] ?? [];
            $group['llm_keys'] = $llmMap[$gid] ?? [];
        }
        unset($group);

        return $groups;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM user_groups WHERE id = ?');
        $stmt->execute([$id]);
        $group = $stmt->fetch() ?: null;
        if (!$group) {
            return null;
        }

        $group['profile_ids'] = self::profileIdsByGroup([$id])[$id] ?? [];
        $group['tool_ids'] = self::toolIdsByGroup([$id])[$id] ?? [];
        $group['llm_keys'] = self::llmKeysByGroupMulti([$id])[$id] ?? [];

        return $group;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $quotaK = self::extractQuotaK($data);
        $db->prepare(
            'INSERT INTO user_groups (name, credit_quota_k, token_quota, is_active)
             VALUES (?,?,?,?)'
        )->execute([
            trim((string)$data['name']),
            $quotaK,
            $quotaK,
            (int)($data['is_active'] ?? 1),
        ]);

        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $quotaK = self::extractQuotaK($data);
        Database::getInstance()->prepare(
            'UPDATE user_groups
             SET name = ?, credit_quota_k = ?, token_quota = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            trim((string)$data['name']),
            $quotaK,
            $quotaK,
            (int)($data['is_active'] ?? 1),
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // Le groupe par défaut n'est jamais supprimable (contient tous les utilisateurs).
        if (self::isDefaultGroup($id)) {
            return;
        }

        Database::getInstance()->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
    }

    public static function setProfileAccess(int $groupId, array $profileIds): void
    {
        $ids = self::normalizeIds($profileIds);
        $db = Database::getInstance();
        $db->prepare('DELETE FROM user_group_profiles WHERE group_id = ?')->execute([$groupId]);

        if (empty($ids)) {
            return;
        }

        // Sécurité : seuls les assistants d'organisation (is_personal = 0) ET
        // actifs sont autorisables via les groupes. Un assistant personnel
        // épinglé ici ne sera de toute façon pas visible (accessibleByUser
        // filtre is_personal=0), mais une promotion ultérieure le re-rendrait
        // visible sans nouvelle validation. On rejette à la source.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id FROM profiles
             WHERE id IN ($placeholders) AND is_personal = 0 AND is_active = 1"
        );
        $stmt->execute($ids);
        $allowedIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (empty($allowedIds)) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO user_group_profiles (group_id, profile_id) VALUES (?, ?)');
        foreach ($allowedIds as $pid) {
            $stmt->execute([$groupId, $pid]);
        }
    }

    public static function setToolAccess(int $groupId, array $toolIds): void
    {
        $ids = self::normalizeIds($toolIds);
        $db = Database::getInstance();
        $db->prepare('DELETE FROM user_group_tools WHERE group_id = ?')->execute([$groupId]);

        if (empty($ids)) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)');
        foreach ($ids as $tid) {
            $stmt->execute([$groupId, $tid]);
        }
    }

    /**
     * Liste blanche des LLMs autorisés pour un groupe.
     * $llmKeys = liste de slugs "provider::model".
     * Tableau vide = aucune restriction (tous LLMs autorisés).
     */
    public static function setLlmAccess(int $groupId, array $llmKeys): void
    {
        $keys = [];
        foreach ($llmKeys as $k) {
            $k = trim((string)$k);
            if ($k !== '' && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }

        $db = Database::getInstance();
        $db->prepare('DELETE FROM user_group_llms WHERE group_id = ?')->execute([$groupId]);

        if (empty($keys)) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO user_group_llms (group_id, llm_key) VALUES (?, ?)');
        foreach ($keys as $key) {
            $stmt->execute([$groupId, $key]);
        }
    }

    /** Slugs LLM autorisés pour un groupe (vide = aucune restriction définie). */
    public static function llmKeysByGroup(int $groupId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT llm_key FROM user_group_llms WHERE group_id = ? ORDER BY llm_key ASC'
        );
        $stmt->execute([$groupId]);
        return array_map(static fn($row) => (string)$row['llm_key'], $stmt->fetchAll());
    }

    /**
     * Liste blanche effective des LLMs pour un user à partir de ses groupes actifs.
     *
     * - Aucun groupe actif : seul le LLM par défaut de l'application est
     *   autorisé — le défaut EFFECTIF (LLMFactory::appDefault), jamais le
     *   réglage brut `default_llm`, qui peut désigner un LLM mort. Retour
     *   `[slug]` ou `[]` si aucun LLM n'est disponible.
     * - Au moins un groupe actif :
     *   - si AU MOINS un groupe actif définit une liste blanche → union des
     *     listes (les groupes sans liste sont neutres).
     *   - si AUCUN groupe actif ne définit de liste :
     *     - uniquement le groupe par défaut (neutre) → équivalent historique
     *       « sans groupe » : LLM par défaut de l'application seulement.
     *     - au moins un groupe « métier » → `null` (= aucune restriction,
     *       tous les LLMs activés sont autorisés).
     *
     * @param int[] $activeGroupIds
     * @return string[]|null
     */
    public static function allowedLlmKeysForUser(array $activeGroupIds): ?array
    {
        $ids = self::normalizeIds($activeGroupIds);
        if (empty($ids)) {
            return self::defaultLlmOnly();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT g.id AS group_id, g.is_default, ugl.llm_key
             FROM user_groups g
             LEFT JOIN user_group_llms ugl ON ugl.group_id = g.id
             WHERE g.id IN ($placeholders) AND g.is_active = 1"
        );
        $stmt->execute($ids);

        $groupsWithList = [];
        $allowed = [];
        $hasNonDefaultGroup = false;
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            if ((int)$row['is_default'] !== 1) {
                $hasNonDefaultGroup = true;
            }
            $key = $row['llm_key'] !== null ? (string)$row['llm_key'] : '';
            if ($key === '') {
                continue;
            }
            $groupsWithList[$gid] = true;
            $allowed[$key] = true;
        }

        if (empty($groupsWithList)) {
            return $hasNonDefaultGroup ? null : self::defaultLlmOnly();
        }

        return array_keys($allowed);
    }

    /**
     * Liste réduite au LLM par défaut EFFECTIF de l'application (vide si aucun
     * LLM n'est disponible). Jamais le réglage brut : sur une instance neuve il
     * vaut « openai », un slug de fournisseur qui ne désigne plus rien dès
     * qu'un modèle est activé — la liste blanche était vide et chaque message
     * répondait « Ce LLM n'est pas autorisé pour votre groupe. ».
     *
     * @return string[]
     */
    private static function defaultLlmOnly(): array
    {
        $defaultLlm = LLMFactory::appDefault();
        return $defaultLlm !== null ? [$defaultLlm] : [];
    }

    public static function setGroupsForUser(int $userId, array $groupIds): void
    {
        $ids = self::validIds($groupIds);

        // L'appartenance au groupe par défaut est obligatoire : on la force
        // quelle que soit la sélection transmise.
        $defaultId = self::defaultGroupId();
        if ($defaultId !== null && !in_array($defaultId, $ids, true)) {
            $ids[] = $defaultId;
        }

        $db = Database::getInstance();
        $db->prepare('DELETE FROM user_group_members WHERE user_id = ?')->execute([$userId]);

        if (empty($ids)) {
            self::syncLegacyGroupId($userId);
            return;
        }

        $stmt = $db->prepare('INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)');
        foreach ($ids as $gid) {
            $stmt->execute([$userId, $gid]);
        }

        self::syncLegacyGroupId($userId);
    }

    public static function addUserToGroup(int $userId, int $groupId): void
    {
        Database::getInstance()->prepare(
            'INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?, ?)'
        )->execute([$userId, $groupId]);

        self::syncLegacyGroupId($userId);
    }

    /**
     * Remplace la liste complète des membres d'un groupe (édition via la modale).
     * Groupe par défaut : membres gérés automatiquement → simple resync (tous les users).
     */
    public static function setMembersForGroup(int $groupId, array $userIds): void
    {
        if (self::isDefaultGroup($groupId)) {
            self::syncDefaultGroupMembers();
            return;
        }

        $db = Database::getInstance();

        // Ne conserve que des ids d'utilisateurs existants.
        $ids = self::normalizeIds($userIds);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $stmt = $db->prepare('SELECT user_id FROM user_group_members WHERE group_id = ?');
        $stmt->execute([$groupId]);
        $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Diff plutôt que delete-all/reinsert : préserve created_at des membres conservés.
        $toRemove = array_values(array_diff($current, $ids));
        $toAdd    = array_values(array_diff($ids, $current));

        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $db->prepare("DELETE FROM user_group_members WHERE group_id = ? AND user_id IN ($placeholders)")
                ->execute(array_merge([$groupId], $toRemove));
        }

        if (!empty($toAdd)) {
            $stmt = $db->prepare('INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?, ?)');
            foreach ($toAdd as $uid) {
                $stmt->execute([$uid, $groupId]);
            }
        }

        foreach (array_merge($toRemove, $toAdd) as $uid) {
            self::syncLegacyGroupId($uid);
        }
    }

    public static function groupsByUserIds(array $userIds): array
    {
        $ids = self::normalizeIds($userIds);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT ugm.user_id, g.id, g.name, g.is_active, g.is_default,
                    COALESCE(g.credit_quota_k, g.token_quota) AS credit_quota_k
             FROM user_group_members ugm
             INNER JOIN user_groups g ON g.id = ugm.group_id
             WHERE ugm.user_id IN ($placeholders)
             ORDER BY g.is_default DESC, g.name ASC"
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            $map[$uid] ??= [];
            $map[$uid][] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'is_active' => (int)$row['is_active'],
                'is_default' => (int)$row['is_default'],
                'credit_quota_k' => $row['credit_quota_k'] !== null ? (int)$row['credit_quota_k'] : null,
            ];
        }

        return $map;
    }

    /**
     * Quota effectif d'un user (k crédits/mois) dérivé de ses groupes actifs.
     * Règle : quand un user appartient à plusieurs groupes, on retient le quota
     * LE PLUS GÉNÉREUX (MAX). Si aucun groupe actif n'a de quota défini, on
     * retourne null (= illimité côté groupes).
     */
    public static function effectiveQuotaForUserId(int $userId): ?int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT MAX(COALESCE(g.credit_quota_k, g.token_quota))
             FROM user_group_members ugm
             INNER JOIN user_groups g ON g.id = ugm.group_id
             WHERE ugm.user_id = ?
               AND g.is_active = 1
               AND COALESCE(g.credit_quota_k, g.token_quota) IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return $value !== null ? (int)$value : null;
    }

    public static function effectiveQuotasByUserIds(array $userIds): array
    {
        $ids = self::normalizeIds($userIds);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT ugm.user_id, MAX(COALESCE(g.credit_quota_k, g.token_quota)) AS effective_quota
             FROM user_group_members ugm
             INNER JOIN user_groups g ON g.id = ugm.group_id
             WHERE ugm.user_id IN ($placeholders)
               AND g.is_active = 1
               AND COALESCE(g.credit_quota_k, g.token_quota) IS NOT NULL
             GROUP BY ugm.user_id"
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['user_id']] = (int)$row['effective_quota'];
        }

        return $map;
    }

    public static function membershipScopeForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT g.id AS group_id, g.is_active
             FROM user_group_members ugm
             INNER JOIN user_groups g ON g.id = ugm.group_id
             WHERE ugm.user_id = ?'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $activeIds = [];
        foreach ($rows as $row) {
            if ((int)$row['is_active'] === 1) {
                $activeIds[] = (int)$row['group_id'];
            }
        }

        return [
            'has_assignments' => !empty($rows),
            'active_group_ids' => array_values(array_unique($activeIds)),
        ];
    }

    public static function validIds(array $ids): array
    {
        $normalized = self::normalizeIds($ids);
        if (empty($normalized)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT id FROM user_groups WHERE id IN ($placeholders)"
        );
        $stmt->execute($normalized);

        $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);

        return $valid;
    }

    public static function exists(int $id): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM user_groups WHERE id = ?');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $value): bool => $value > 0
        )));
    }

    private static function extractQuotaK(array $data): ?int
    {
        if (array_key_exists('credit_quota_k', $data)) {
            return self::normalizeQuota($data['credit_quota_k']);
        }

        return self::normalizeQuota($data['token_quota'] ?? null);
    }

    private static function normalizeQuota(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        $quota = (int)$value;
        return $quota >= 0 ? $quota : null;
    }

    private static function profileIdsByGroup(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT group_id, profile_id FROM user_group_profiles WHERE group_id IN ($placeholders)"
        );
        $stmt->execute($groupIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            $map[$gid] ??= [];
            $map[$gid][] = (int)$row['profile_id'];
        }

        return $map;
    }

    private static function toolIdsByGroup(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT group_id, tool_id FROM user_group_tools WHERE group_id IN ($placeholders)"
        );
        $stmt->execute($groupIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            $map[$gid] ??= [];
            $map[$gid][] = (int)$row['tool_id'];
        }

        return $map;
    }

    private static function llmKeysByGroupMulti(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT group_id, llm_key FROM user_group_llms WHERE group_id IN ($placeholders)"
        );
        $stmt->execute($groupIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            $map[$gid] ??= [];
            $map[$gid][] = (string)$row['llm_key'];
        }

        return $map;
    }

    private static function syncLegacyGroupId(int $userId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT group_id FROM user_group_members WHERE user_id = ? ORDER BY group_id ASC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $legacyGroupId = $stmt->fetchColumn();

        $db->prepare('UPDATE users SET group_id = ? WHERE id = ?')
            ->execute([$legacyGroupId !== false ? (int)$legacyGroupId : null, $userId]);
    }
}
