<?php

declare(strict_types=1);

class Profile
{
    /** Quota d'assistants personnels par utilisateur "creator". */
    public const MAX_PERSONAL_PER_CREATOR = 10;

    public static function all(): array
    {
        return Database::getInstance()->query(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             ORDER BY p.name ASC'
        )->fetchAll();
    }

    /**
     * Sous-requête scalaire : nombre de bases de connaissances ACTIVES liées à
     * l'assistant (badge « RAG » côté chat). Scalaire plutôt que JOIN+GROUP BY :
     * compatible ONLY_FULL_GROUP_BY avec p.* (longtext).
     */
    private const KB_COUNT_SELECT = '(SELECT COUNT(*) FROM profile_knowledge_bases pkb
             JOIN knowledge_bases kb ON kb.id = pkb.kb_id AND kb.is_active = 1
             WHERE pkb.profile_id = p.id) AS kb_count';

    public static function allActive(): array
    {
        return Database::getInstance()->query(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             WHERE p.is_active = 1
             ORDER BY p.name ASC'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Liste des assistants accessibles à un user dans le chat.
     *
     * Deux familles d'assistants :
     *  - "organisation" : créés par un admin (ou historiques sans créateur).
     *    Visibilité FILTRÉE PAR GROUPE — un utilisateur ne voit que les
     *    assistants d'organisation cochés dans « Assistants autorisés » de l'un
     *    de ses groupes actifs (table user_group_profiles). Un admin voit tous
     *    les assistants d'organisation. Un user sans groupe ne voit aucun
     *    assistant d'organisation.
     *  - "personnels" : créés par un "creator", visibles UNIQUEMENT par leur
     *    créateur — JAMAIS partagés via les groupes (même cochés dans un
     *    groupe, ils n'apparaissent pas pour les membres).
     *
     * IMPORTANT — la nature "organisation" est portée par le flag p.is_personal
     * (= 0). C'est la source de vérité, fiabilisée par les migrations de resync
     * (033/034), posée correctement à la création (createProfile) et basculable
     * par un admin (Profile::setPersonal / bouton « Partager »). Un assistant
     * personnel (is_personal = 1) n'apparaît jamais dans la branche org, même
     * coché dans un groupe : il reste visible de son seul créateur (branche
     * "own"). Promouvoir un perso en org (is_personal → 0) le rend autorisable
     * par groupe ; le rétrograder le re-cloisonne à son créateur.
     *
     * Résultat = org autorisés par groupe (ou tous si admin) ∪ assistants créés
     * par le user courant, dédupliqués (par id, en PHP) et triés par nom.
     */
    public static function accessibleByUser(
        int $userId,
        string $role,
        array $activeGroupIds = [],
        bool $hasGroupAssignments = false
    ): array {
        $db = Database::getInstance();

        // Sans le droit « Assistants — Utiliser », aucun assistant : ni ceux
        // des groupes, ni les siens, ni les globaux (Personnes → Rôles).
        if (!Role::grants($role, 'assistants.use')) {
            return [];
        }

        // --- Assistants d'organisation (is_personal = 0) ---
        $org = [];
        if (Role::grants($role, 'assistants.manage')) {
            // Admin (et tout rôle « Assistants — Gérer ») : tous les
            // assistants d'organisation, sans filtre de groupe.
            $stmt = $db->query(
                'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
                 FROM profiles p
                 WHERE p.is_active = 1 AND p.is_personal = 0
                 ORDER BY p.name ASC'
            );
            $org = $stmt->fetchAll();
        } elseif (!empty($activeGroupIds)) {
            // User : assistants d'organisation autorisés par ses groupes actifs
            // (case cochée dans « Assistants autorisés »). Le JOIN peut produire
            // des doublons (assistant autorisé dans plusieurs groupes) →
            // dédupliqués par id en PHP plus bas (pas de DISTINCT sur longtext).
            $placeholders = implode(',', array_fill(0, count($activeGroupIds), '?'));
            $sql = 'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . "
                    FROM profiles p
                    JOIN user_group_profiles ugp ON ugp.profile_id = p.id
                    WHERE p.is_active = 1 AND p.is_personal = 0
                      AND ugp.group_id IN ($placeholders)
                    ORDER BY p.name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_map('intval', $activeGroupIds));
            $org = $stmt->fetchAll();
        }
        // else : user sans groupe actif → aucun assistant d'organisation.

        // --- Assistants personnels créés par le user courant (créateur-seul) ---
        // Matche sur created_by : un créateur voit TOUS les assistants qu'il a
        // créés, jamais ceux d'un autre. Indépendant des groupes.
        $ownStmt = $db->prepare(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             WHERE p.is_active = 1 AND p.created_by = ?
             ORDER BY p.name ASC'
        );
        $ownStmt->execute([$userId]);
        $own = $ownStmt->fetchAll();

        // --- Assistants GLOBAUX (is_global = 1) ---
        // Visibles par TOUS, sans condition de groupe (assistant système d'aide,
        // etc.). Déjà inclus pour l'admin via la branche org, mais ré-ajoutés
        // ici sans risque (dédup par id plus bas).
        $global = $db->query(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             WHERE p.is_active = 1 AND p.is_personal = 0 AND p.is_global = 1
             ORDER BY p.name ASC'
        )->fetchAll();

        // Fusion + déduplication par id, tri par nom.
        $byId = [];
        foreach (array_merge($org, $own, $global) as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $rows = array_values($byId);
        usort($rows, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
        return $rows;
    }

    /** Nombre d'assistants personnels appartenant à un créateur (pour le quota). */
    public static function countPersonalByCreator(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM profiles WHERE created_by = ? AND is_personal = 1'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Modèle d'un assistant tel qu'il s'enregistre. « Défaut application »
     * (valeur vide) ou un modèle hors du périmètre du gestionnaire → NULL :
     * l'assistant suit le modèle par défaut de l'application AU MOMENT DE
     * CHAQUE MESSAGE (ChatController::resolveProfileDefaultLlm), comme une
     * conversation sans assistant — il ne contourne donc pas la liste des
     * modèles du groupe de l'utilisateur, contrairement à un modèle imposé.
     *
     * Avant, le défaut DU MOMENT était recopié ici : l'assistant ne suivait
     * plus un changement ultérieur, et sa fiche rouverte affichait un modèle
     * précis au lieu de « Défaut application ». Pure, testable sans base.
     *
     * @param array<string, string> $allowed slug → libellé (périmètre du gestionnaire)
     */
    public static function normalizeDefaultLlm(string $raw, array $allowed): ?string
    {
        $raw = trim($raw);
        return ($raw !== '' && isset($allowed[$raw])) ? $raw : null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO profiles (name, description, system_prompt, default_llm, is_active, color, temperature, avatar, created_by, is_personal, is_global)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['name'], $data['description'] ?? null,
            $data['system_prompt'], $data['default_llm'] ?? null,
            $data['is_active'] ?? 1,
            $data['color'] ?? null,
            isset($data['temperature']) && $data['temperature'] !== '' ? (float)$data['temperature'] : null,
            $data['avatar'] ?? null,
            isset($data['created_by']) && $data['created_by'] !== null ? (int)$data['created_by'] : null,
            !empty($data['is_personal']) ? 1 : 0,
            !empty($data['is_global']) ? 1 : 0,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Assistants que la page Assistants montre à un gestionnaire (le droit
     * « Assistants — Voir » est vérifié par la route) : « Assistants — Gérer »
     * (`assistants.manage`, l'administrateur par défaut) → tous ; sinon ceux
     * qu'il a créés (created_by).
     */
    public static function forManager(array $user): array
    {
        if (Role::userCan($user, 'assistants.manage')) {
            return self::all();
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*,
                    ' . self::KB_COUNT_SELECT . '
             FROM profiles p
             WHERE p.created_by = ?
             ORDER BY p.name ASC'
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    }

    /**
     * L'assistant est-il dans la portée du gestionnaire ? « Assistants —
     * Gérer » (`assistants.manage`) : tout assistant existant ; sinon
     * ceux qu'il a créés. Le droit d'agir (Modifier, Supprimer) est vérifié
     * par la route.
     */
    public static function manageableBy(int $profileId, array $user): bool
    {
        if (Role::userCan($user, 'assistants.manage')) {
            return self::findById($profileId) !== null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM profiles WHERE id = ? AND created_by = ?'
        );
        $stmt->execute([$profileId, (int)$user['id']]);
        return (bool)$stmt->fetch();
    }

    public static function update(int $id, array $data): void
    {
        Database::getInstance()->prepare(
            'UPDATE profiles SET name=?, description=?, system_prompt=?, default_llm=?,
             is_active=?, color=?, temperature=?, avatar=?, updated_at=NOW() WHERE id=?'
        )->execute([
            $data['name'], $data['description'] ?? null,
            $data['system_prompt'], $data['default_llm'] ?? null,
            $data['is_active'] ?? 1,
            $data['color'] ?? null,
            isset($data['temperature']) && $data['temperature'] !== '' ? (float)$data['temperature'] : null,
            $data['avatar'] ?? null,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM profiles WHERE id=?')->execute([$id]);
    }

    /**
     * Bascule la nature d'un assistant : personnel (is_personal=1) ↔
     * organisation (is_personal=0). created_by reste inchangé (le créateur garde
     * la main, les admins aussi). Un assistant promu en organisation devient
     * autorisable via les groupes ; rétrogradé, il se re-cloisonne à son créateur.
     */
    public static function setPersonal(int $id, bool $personal): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE profiles SET is_personal=?, updated_at=NOW() WHERE id=?')
           ->execute([$personal ? 1 : 0, $id]);

        // Rétrogradation organisation → personnel : on PURGE les autorisations
        // de groupe existantes. Sans ça, ré-promouvoir plus tard l'assistant en
        // organisation le re-rendrait visible aux groupes ayant été cochés
        // précédemment sans nouvelle validation de l'admin → fuite.
        if ($personal) {
            $db->prepare('DELETE FROM user_group_profiles WHERE profile_id = ?')->execute([$id]);
        }
    }

    public static function setAccess(int $profileId, array $roles): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM profile_access WHERE profile_id=?')->execute([$profileId]);
        foreach ($roles as $role) {
            $db->prepare('INSERT INTO profile_access (profile_id, role) VALUES (?,?)')->execute([$profileId, $role]);
        }
    }

    /**
     * Remplace les outils liés à un profil (table profile_tools).
     * Le binding autorise l'assistant à appeler ces outils via function-calling.
     *
     * @param list<int>|array $toolIds
     */
    public static function setTools(int $profileId, array $toolIds): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM profile_tools WHERE profile_id=?')->execute([$profileId]);
        $seen = [];
        foreach ($toolIds as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $db->prepare('INSERT INTO profile_tools (profile_id, tool_id) VALUES (?,?)')
               ->execute([$profileId, $tid]);
        }
    }
}
