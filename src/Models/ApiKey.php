<?php

declare(strict_types=1);

class ApiKey
{
    public static function all(): array
    {
        return Database::getInstance()->query('SELECT * FROM api_keys ORDER BY provider ASC')->fetchAll();
    }

    public static function findByProvider(string $provider): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM api_keys WHERE provider = ? AND is_active = 1');
        $stmt->execute([$provider]);
        return $stmt->fetch() ?: null;
    }

    public static function getDecrypted(string $provider): ?string
    {
        $row = self::findByProvider($provider);
        if (!$row) return null;
        return Encryption::decrypt($row['key_enc']);
    }

    /**
     * Modèle enregistré avec la clé du fournisseur (ancien format, sans slug
     * de modèle). Null quand rien n'est enregistré — ou quand ce modèle a été
     * arrêté par son éditeur (LlmCatalog::retirement) : chaque appelant
     * retombe alors sur le modèle par défaut du catalogue au lieu d'appeler un
     * modèle qui ne répond plus.
     */
    public static function getModel(string $provider): ?string
    {
        $row = self::findByProvider($provider);
        if (!$row || $row['model'] === null || $row['model'] === '') {
            return null;
        }
        return LlmCatalog::isRetired($provider, (string)$row['model']) ? null : (string)$row['model'];
    }

    public static function getBaseUrl(string $provider): ?string
    {
        $row = self::findByProvider($provider);
        return ($row && !empty($row['base_url'])) ? $row['base_url'] : null;
    }

    public static function getDisplayName(string $provider): ?string
    {
        $row = self::findByProvider($provider);
        return ($row && !empty($row['display_name'])) ? $row['display_name'] : null;
    }

    /**
     * Au moins un LLM est-il configuré ? SOURCE UNIQUE du signal de
     * configuration initiale : tant que c'est faux, MIA ne peut répondre à
     * personne — la navigation d'administration (admin-nav.php) fait alors
     * clignoter « Modèles IA / Clés API » et le tableau de bord affiche le
     * bandeau de démarrage. Une ligne active suffit, cloud OU locale (un
     * endpoint local sans clé compte : c'est `base_url` qui le rend utilisable).
     */
    public static function hasActiveLlm(): bool
    {
        return (bool)Database::getInstance()
            ->query('SELECT 1 FROM api_keys WHERE is_active = 1 LIMIT 1')
            ->fetchColumn();
    }

    public static function save(string $provider, string $plainKey, ?string $model = null, ?string $baseUrl = null, ?string $displayName = null): void
    {
        $db = Database::getInstance();
        $encrypted = Encryption::encrypt($plainKey);
        $existing  = $db->prepare('SELECT id FROM api_keys WHERE provider=?');
        $existing->execute([$provider]);

        if ($existing->fetch()) {
            $db->prepare('UPDATE api_keys SET key_enc=?, model=?, base_url=?, display_name=?, is_active=1, updated_at=NOW() WHERE provider=?')
               ->execute([$encrypted, $model, $baseUrl, $displayName, $provider]);
        } else {
            $db->prepare('INSERT INTO api_keys (provider, key_enc, model, base_url, display_name) VALUES (?,?,?,?,?)')
               ->execute([$provider, $encrypted, $model, $baseUrl, $displayName]);
        }
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM api_keys WHERE id=?')->execute([$id]);
    }
}
