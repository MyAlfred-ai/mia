<?php

declare(strict_types=1);

class Settings
{
    private static array $cache = [];

    /**
     * Le cache est-il hydraté depuis la table ? Tester `empty($cache)` ne
     * suffisait pas : un `set()` AVANT le premier `get()` — cas réel, le
     * heartbeat du worker de jobs — posait UNE entrée, le cache passait pour
     * chargé et `load()` n'était plus jamais appelé. Tous les autres réglages
     * valaient alors leur défaut pour toute la vie du process : la sync des
     * bases de connaissances échouait en « Clé API Google non configurée »
     * alors que la clé était en base, le LLM par défaut et le vocabulaire
     * commun étaient ignorés dans les jobs de chat, etc.
     */
    private static bool $loaded = false;

    public static function load(): void
    {
        $rows = Database::getInstance()->query('SELECT `key`, `value` FROM settings')->fetchAll();
        foreach ($rows as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        )->execute([$key, $value === null ? null : (string)$value]);
        self::$cache[$key] = $value;
    }

    public static function all(): array
    {
        if (!self::$loaded) self::load();
        return self::$cache;
    }

    /**
     * Pose une valeur dans le cache mémoire SANS l'écrire en base : elle ne
     * vaut que pour la requête en cours.
     *
     * Sert aux boutons « Tester » de l'administration, qui doivent éprouver des
     * identifiants saisis mais pas encore enregistrés. Sans ce chemin, tester
     * imposerait d'enregistrer d'abord — donc de casser une configuration qui
     * marche pour découvrir que la nouvelle est fausse.
     */
    public static function override(string $key, mixed $value): void
    {
        if (!self::$loaded) self::load();
        self::$cache[$key] = $value;
    }

    /**
     * Invalide le cache mémoire : le prochain accès relit la table. Pour les
     * process long-vivants (worker de jobs) — sans ça, un réglage modifié en
     * admin après leur démarrage (clé API re-saisie, LLM par défaut, toggle)
     * resterait invisible jusqu'au redémarrage du conteneur.
     */
    public static function refresh(): void
    {
        self::$cache  = [];
        self::$loaded = false;
    }

    /**
     * L'utilisateur appartient-il à l'équipe qui exploite l'instance ?
     *
     * Le suffixe d'e-mail était codé en dur dans deux gabarits : sur toute
     * installation ailleurs, PERSONNE n'était staff et les éléments réservés à
     * l'équipe restaient invisibles sans explication. Domaine vide = fonction
     * désactivée, ce qui est le réglage attendu chez un tiers.
     */
    public static function isStaffEmail(?string $email): bool
    {
        $domaine = strtolower(trim((string) self::get('staff_email_domain', '')));
        if ($domaine === '' || $email === null || $email === '') {
            return false;
        }

        return str_ends_with(strtolower(trim($email)), '@' . ltrim($domaine, '@'));
    }

    public static function theme(): string
    {
        $theme = strtolower(trim((string) self::get('theme', 'dark')));
        return $theme === 'light' ? 'light' : 'dark';
    }

    public static function accentColor(): string
    {
        $color = trim((string) self::get('accent_color', '#10a37f'));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return strtolower($color);
        }
        return '#10a37f';
    }
}
