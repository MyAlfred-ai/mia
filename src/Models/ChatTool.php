<?php

declare(strict_types=1);

class ChatTool
{
    /**
     * Types dont le moteur est un modèle du catalogue d'OUTILS — les seuls
     * qu'un arrêt de modèle peut toucher. Les autres passent par un LLM
     * (catalogue LLM) ou par un service tiers (n8n, Gamma).
     */
    private const CATALOG_TYPES = ['image', 'video'];

    /**
     * Types encore en BÊTA TEST — SOURCE UNIQUE de la pastille « En bêta
     * test » de la page Outils (cartes des outils configurés ET cartes de
     * choix du type à la création). Sortir un type de la bêta = le retirer
     * d'ici, rien d'autre.
     */
    public const BETA_TYPES = ['map'];

    /** Libellé et explication de la pastille, partagés par les deux cartes. */
    public const BETA_LABEL = 'En bêta test';
    public const BETA_HINT  = "Outil en phase de test : il fonctionne, mais son comportement peut encore évoluer et présenter des imperfections.";

    public static function isBetaType(string $type): bool
    {
        return in_array($type, self::BETA_TYPES, true);
    }

    /**
     * Arrêt du modèle d'un outil par son fournisseur (cf. ToolCatalog::retirement).
     * Pur : n'interroge que le catalogue, jamais la base.
     *
     * @return array{date:string, source_url:string, note:string, retired:bool}|null
     */
    public static function retirement(array $tool): ?array
    {
        $type = (string)($tool['type'] ?? '');
        if (!in_array($type, self::CATALOG_TYPES, true)) {
            return null;
        }

        return ToolCatalog::retirement($type, (string)($tool['provider'] ?? ''), (string)($tool['model'] ?? ''));
    }

    /**
     * Modèle arrêté À CE JOUR : l'outil est grisé dans le chat, écarté des
     * assistants, et aucune génération ne part plus chez le fournisseur.
     */
    public static function isRetired(array $tool): bool
    {
        return (bool)(self::retirement($tool)['retired'] ?? false);
    }

    /** Message de refus pour l'utilisateur ; null si l'outil est en service. */
    public static function retiredNotice(array $tool): ?string
    {
        $type = (string)($tool['type'] ?? '');
        if (!in_array($type, self::CATALOG_TYPES, true)) {
            return null;
        }

        return ToolCatalog::retiredNotice($type, (string)($tool['provider'] ?? ''), (string)($tool['model'] ?? ''));
    }

    /**
     * Ce qui manque à un outil pour fonctionner — SOURCE UNIQUE de « l'outil
     * est-il utilisable ? », partagée par la page Outils (carte grisée, badge
     * « Clé requise ») et les points à régler de l'administration
     * (AdminAttention). Deux copies de la règle finiraient par ne plus dire la
     * même chose sur le même outil.
     *
     * Une clé : dédiée à l'outil (n8n, Gamma, clé propre) OU clé globale
     * active de son fournisseur (Modèles IA). Une instance n8n sans URL ne
     * peut rien lister ; un site illustré a besoin d'un outil image source
     * lui-même utilisable. Pure : les fournisseurs à clé et l'index des outils
     * sont passés en entrée.
     *
     * @param array<string,bool>   $providersWithKey fournisseur → clé API globale active
     * @param array<int,array>     $toolById         tous les outils, par id (source d'un site illustré)
     * @param array<string,string> $providerLabels   libellés des fournisseurs (repli : nom capitalisé)
     * @return list<string> ce qui manque, en toutes lettres ; vide = utilisable
     */
    public static function missingPrerequisites(array $tool, array $providersWithKey, array $toolById, array $providerLabels = []): array
    {
        $libelle = static fn(string $p): string => $providerLabels[$p] ?? ucfirst($p);
        $aUneCle = static fn(array $t): bool => !empty($t['api_key_enc']) || isset($providersWithKey[(string)($t['provider'] ?? '')]);
        $type    = (string)($tool['type'] ?? '');
        $cfg     = json_decode((string)($tool['config_json'] ?? ''), true);
        $cfg     = is_array($cfg) ? $cfg : [];

        $manque = [];
        if (!$aUneCle($tool)) {
            $manque[] = match ($type) {
                'n8n'   => t('la clé API n8n (bouton Modifier)'),
                'gamma' => t('la clé API Gamma (bouton Modifier)'),
                default => t('la clé API {fournisseur}', ['fournisseur' => $libelle((string)($tool['provider'] ?? ''))]),
            };
        }
        if ($type === 'n8n' && empty($cfg['base_url'])) {
            $manque[] = t("l'URL de l'instance n8n");
        }
        if ($type === 'html_illustrated') {
            $srcId = (int)($cfg['image_tool_id'] ?? 0);
            if ($srcId <= 0 || !isset($toolById[$srcId])) {
                $manque[] = t('un outil image source');
            } elseif (!$aUneCle($toolById[$srcId])) {
                $manque[] = t('la clé API {fournisseur} (outil image source)', ['fournisseur' => $libelle((string)($toolById[$srcId]['provider'] ?? ''))]);
            }
        }
        return $manque;
    }

    public static function all(): array
    {
        return Database::getInstance()
            ->query('SELECT * FROM chat_tools ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    public static function active(string $type = 'image'): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM chat_tools WHERE is_active=1 AND type=? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }

    /**
     * Outils actifs visibles par un user (union des groupes actifs).
     * Admin : tous les outils actifs. Sinon, un user sans aucun groupe actif
     * (« Sans groupe ») ne voit aucun outil — ni un rôle privé du droit
     * « Outils — Utiliser » (Personnes → Rôles), quels que soient ses groupes.
     */
    public static function activeByUser(
        string $type,
        string $role,
        array $activeGroupIds = [],
        bool $hasGroupAssignments = false
    ): array {
        if (!Role::grants($role, 'tools.use')) {
            return [];
        }
        if ($role === 'admin') {
            return self::active($type);
        }
        if (empty($activeGroupIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($activeGroupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT t.*
             FROM chat_tools t
             INNER JOIN user_group_tools ugt
                ON ugt.tool_id = t.id
             WHERE t.is_active = 1
               AND t.type = ?
               AND ugt.group_id IN ($placeholders)
             ORDER BY t.sort_order ASC, t.id ASC"
        );
        $params = array_merge([$type], array_map('intval', $activeGroupIds));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Portée réelle de chaque outil : nombre de groupes ACTIFS qui l'autorisent
     * (tool_id → nombre ; un outil absent n'est autorisé nulle part). C'est ce
     * que l'écran Outils affiche sous l'interrupteur : « Actif » seul ne
     * propose l'outil à personne d'autre que les administrateurs
     * (cf. activeByUser), et un outil neuf n'est autorisé dans aucun groupe.
     *
     * @return array<int, int>
     */
    public static function activeGroupCounts(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT ugt.tool_id, COUNT(DISTINCT ugt.group_id) AS n
             FROM user_group_tools ugt
             INNER JOIN user_groups g ON g.id = ugt.group_id AND g.is_active = 1
             GROUP BY ugt.tool_id'
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['tool_id']] = (int)$row['n'];
        }
        return $counts;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM chat_tools WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findActiveByIdForUser(
        int $id,
        string $role,
        array $activeGroupIds = [],
        bool $hasGroupAssignments = false
    ): ?array {
        // Même règle que activeByUser : droit « Outils — Utiliser » d'abord,
        // revérifié au moment T (un job, une carte approuvée plus tard).
        if (!Role::grants($role, 'tools.use')) {
            return null;
        }
        if ($role === 'admin') {
            $stmt = Database::getInstance()->prepare('SELECT * FROM chat_tools WHERE id=? AND is_active=1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }

        if (empty($activeGroupIds)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($activeGroupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT t.*
             FROM chat_tools t
             INNER JOIN user_group_tools ugt
                ON ugt.tool_id = t.id
             WHERE t.id = ?
               AND t.is_active = 1
               AND ugt.group_id IN ($placeholders)
             LIMIT 1"
        );
        $params = array_merge([$id], array_map('intval', $activeGroupIds));
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public static function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $v): bool => $v > 0)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM chat_tools WHERE id IN ($placeholders) ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /**
     * IDs des outils liés à un profil/assistant (table profile_tools).
     * @return list<int>
     */
    public static function idsForProfile(int $profileId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT tool_id FROM profile_tools WHERE profile_id=?'
        );
        $stmt->execute([$profileId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * IDs des outils liés à plusieurs assistants, en UNE requête (formulaire
     * des tâches programmées : un assistant par option du sélecteur).
     *
     * @param  list<int> $profileIds
     * @return array<int, list<int>> profile_id → ids d'outils (absent = aucun)
     */
    public static function idsForProfiles(array $profileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn(int $v): bool => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT profile_id, tool_id FROM profile_tools WHERE profile_id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['profile_id']][] = (int)$row['tool_id'];
        }
        return $out;
    }

    /**
     * Outils actifs liés à un profil ET autorisés pour l'utilisateur courant.
     *
     * Sécurité : le binding profil↔outil autorise l'ASSISTANT à appeler l'outil ;
     * il ne contourne pas le scope user-group. On intersecte donc les outils liés
     * au profil avec ceux visibles par l'utilisateur (activeByUser).
     */
    public static function activeForProfile(
        int $profileId,
        string $type,
        string $role,
        array $activeGroupIds = []
    ): array {
        $bound = self::idsForProfile($profileId);
        if (empty($bound)) {
            return [];
        }
        $boundSet = array_flip($bound);
        $visible  = self::activeByUser($type, $role, $activeGroupIds);
        return array_values(array_filter(
            $visible,
            static fn(array $t): bool => isset($boundSet[(int)$t['id']])
        ));
    }

    public static function save(array $data): int
    {
        $db = Database::getInstance();

        if (!empty($data['id'])) {
            $db->prepare(
                'UPDATE chat_tools SET name=?, type=?, provider=?, model=?, config_json=?, is_active=?, sort_order=?, last_test_ok=NULL, last_test_message=NULL, last_tested_at=NULL WHERE id=?'
            )->execute([
                $data['name'],
                $data['type'],
                $data['provider'],
                $data['model'],
                $data['config_json'] ?? null,
                (int)($data['is_active'] ?? 1),
                (int)($data['sort_order'] ?? 0),
                (int)$data['id'],
            ]);
            if (!empty($data['api_key_plain'])) {
                $db->prepare('UPDATE chat_tools SET api_key_enc=? WHERE id=?')
                   ->execute([Encryption::encrypt($data['api_key_plain']), (int)$data['id']]);
            }
            return (int)$data['id'];
        }

        $keyEnc = !empty($data['api_key_plain']) ? Encryption::encrypt($data['api_key_plain']) : null;
        $db->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, config_json, api_key_enc, is_active, sort_order)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $data['name'],
            $data['type'],
            $data['provider'],
            $data['model'],
            $data['config_json'] ?? null,
            $keyEnc,
            (int)($data['is_active'] ?? 1),
            (int)($data['sort_order'] ?? 0),
        ]);
        return (int)$db->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM chat_tools WHERE id=?')->execute([$id]);
    }

    /**
     * Bascule Actif/Inactif seule (interrupteur des cartes de la liste).
     * Ne passe PAS par save() : un simple toggle ne doit pas effacer le
     * résultat du dernier test ni toucher au reste de la configuration.
     */
    public static function setActive(int $id, bool $active): void
    {
        Database::getInstance()->prepare('UPDATE chat_tools SET is_active=? WHERE id=?')
            ->execute([$active ? 1 : 0, $id]);
    }

    public static function getDecryptedKey(int $id): ?string
    {
        $row = self::findById($id);
        if (!$row || empty($row['api_key_enc'])) return null;
        return Encryption::decrypt($row['api_key_enc']);
    }

    public static function setTestResult(int $id, bool $ok, string $message): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE chat_tools
             SET last_test_ok=?, last_test_message=?, last_tested_at=NOW()
             WHERE id=?"
        );
        $stmt->execute([
            $ok ? 1 : 0,
            mb_substr(trim($message), 0, 1000),
            $id,
        ]);
    }
}
