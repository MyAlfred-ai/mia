<?php

declare(strict_types=1);

/**
 * Rôles des comptes (migration 090) — qui peut voir, utiliser, créer,
 * modifier, supprimer, gérer quoi. Le catalogue des droits vit dans Permissions
 * (pur) ; ce modèle lit et écrit les rôles.
 *
 * `users.role` porte l'identifiant (slug) du rôle, comme avant : `admin`,
 * `creator`, `user`, ou celui d'un rôle personnalisé (`responsable-rh`).
 * Les trois rôles livrés sont DÉFINIS ICI (nom, couleur, droits par défaut) ;
 * leur ligne en base ne porte qu'une éventuelle surcharge des droits
 * (créateur, utilisateur — l'administrateur est verrouillé). Une base vide,
 * une table absente (migration pas encore passée), une base injoignable :
 * les rôles livrés et leurs défauts s'appliquent, rien ne casse.
 *
 * Lecture mémorisée pour le processus (une requête pour toute la table, elle
 * est petite). Le worker vit longtemps et enchaîne les comptes : JobQueue,
 * le planificateur des tâches et celui des workflows appellent forget() à
 * chaque tour, comme Settings::refresh().
 */
final class Role
{
    public const ADMIN   = 'admin';
    public const CREATOR = 'creator';
    public const USER    = 'user';

    /** Rôles livrés, dans l'ordre de l'écran. */
    public const SYSTEM = [self::ADMIN, self::CREATOR, self::USER];

    /** Identifiants qu'un rôle personnalisé ne prend jamais. */
    public const RESERVED_SLUGS = ['admin', 'creator', 'user', 'pending', 'role'];

    public const MAX_CUSTOM      = 30;
    public const NAME_MAX        = 60;
    public const DESCRIPTION_MAX = 240;
    /** `users.role` est un VARCHAR(20). */
    public const SLUG_MAX        = 20;

    /** Couleurs proposées pour un rôle personnalisé. */
    public const COLORS = ['#6366f1', '#0ea5e9', '#14b8a6', '#22c55e', '#eab308', '#f97316', '#ef4444', '#ec4899', '#a855f7', '#64748b'];

    /**
     * Rôles livrés : nom (écran des rôles), libellé court (badge du compte),
     * couleur, phrase. Les textes sont les clés de traduction (le français
     * source) : all(), find() et label() les rendent dans la langue de
     * l'écran. Un rôle personnalisé, lui, est un texte écrit par
     * l'administrateur, jamais traduit.
     */
    private const BUILTIN = [
        self::ADMIN => [
            'name'        => 'Administrateur',
            'short'       => 'Admin',
            'color'       => '#22c55e',
            'description' => 'Tous les droits, rôles et données compris. Rôle verrouillé : il reste toujours quelqu’un capable de tout régler.',
        ],
        self::CREATOR => [
            'name'        => 'Créateur',
            'short'       => 'Créateur',
            'color'       => '#eab308',
            'description' => 'Conçoit ses assistants, ses bases de connaissances et ses workflows, en plus de tout ce que fait un utilisateur.',
        ],
        self::USER => [
            'name'        => 'Utilisateur',
            'short'       => 'Utilisateur',
            'color'       => '#94a3b8',
            'description' => 'Discute avec MIA et les assistants de ses groupes, relie ses comptes, programme ses tâches.',
        ],
    ];

    /** @var array<string, array<string, mixed>>|null slug → rôle (textes des rôles livrés en français source) */
    private static ?array $cache = null;

    /**
     * La même liste, textes des rôles livrés traduits, pour `$viewLocale`. Le
     * worker enchaîne des comptes de langues différentes : la vue se refait
     * quand la locale change, sans relire la base.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $view = null;
    private static string $viewLocale = '';

    /**
     * Lignes de la table `roles` imposées par un test (null = la base). Les
     * tests unitaires fixent ainsi les rôles sans dépendre de ce que la base
     * de test contient — même patron que Settings::override().
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $rowsOverride = null;

    /** Oublie la lecture mémorisée (worker, tests, après une écriture). */
    public static function forget(): void
    {
        self::$cache = null;
        self::$view  = null;
    }

    /**
     * Tests : rôles lus dans ces lignes (slug, name, description, color,
     * permissions) au lieu de la base ; null rend la main à la base.
     *
     * @param list<array<string, mixed>>|null $rows
     */
    public static function overrideRows(?array $rows): void
    {
        self::$rowsOverride = $rows;
        self::forget();
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Tous les rôles : les trois livrés d'abord, puis les personnalisés par
     * nom. Chaque rôle : slug, name, short, description, color, system,
     * locked, customized (droits d'un rôle livré différents de ses défauts),
     * permissions (liste fermée), id. Nom, libellé court et phrase des rôles
     * livrés dans la langue de l'écran.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $locale = I18n::locale();
        if (self::$view !== null && self::$viewLocale === $locale) {
            return self::$view;
        }
        $roles = self::load();
        foreach (self::SYSTEM as $slug) {
            foreach (['name', 'short', 'description'] as $field) {
                $roles[$slug][$field] = t(self::BUILTIN[$slug][$field]);
            }
        }
        self::$viewLocale = $locale;
        return self::$view = $roles;
    }

    /**
     * Noms des rôles livrés (nom et libellé court) dans TOUTES les langues :
     * un rôle personnalisé n'en prend aucun, quelle que soit la langue de
     * l'écran où on le nomme — sinon « Administrator », créé en anglais,
     * doublerait « Administrateur » à l'écran d'un collègue francophone.
     *
     * @return list<string>
     */
    public static function builtinNames(): array
    {
        $names   = [];
        $current = I18n::locale();
        try {
            foreach (array_keys(I18n::LOCALES) as $code) {
                I18n::use($code);
                foreach (self::BUILTIN as $def) {
                    $names[] = t($def['name']);
                    $names[] = t($def['short']);
                }
            }
        } finally {
            I18n::use($current);
        }
        return array_values(array_unique($names));
    }

    /**
     * Lecture de la table (mémorisée) : rôles livrés — textes en français
     * source — puis personnalisés.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = [];
        if (self::$rowsOverride !== null) {
            $rows = self::$rowsOverride;
        } else {
            try {
                $rows = Database::getInstance()->query('SELECT * FROM roles ORDER BY name ASC, id ASC')->fetchAll();
            } catch (\Throwable $e) {
                // Table absente (migration en attente), base injoignable : les
                // rôles livrés et leurs défauts. Jamais d'exception vers l'appelant.
                $rows = [];
            }
        }

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string)$row['slug']] = $row;
        }

        $roles = [];
        foreach (self::SYSTEM as $slug) {
            $roles[$slug] = self::builtin($slug, $bySlug[$slug] ?? null);
        }
        foreach ($bySlug as $slug => $row) {
            if (isset($roles[$slug])) {
                continue;
            }
            $roles[$slug] = [
                'id'          => (int)($row['id'] ?? 0),
                'slug'        => $slug,
                'name'        => (string)$row['name'],
                'short'       => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'color'       => self::sanitizeColor((string)($row['color'] ?? '')) ?? self::COLORS[0],
                'system'      => false,
                'locked'      => false,
                'customized'  => false,
                // Rôle personnalisé : un droit qu'aucun administrateur n'a
                // coché est refusé, y compris ceux d'une version future.
                'permissions' => Permissions::decode($row['permissions'] ?? null, []),
            ];
        }

        return self::$cache = $roles;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    public static function isSystem(string $slug): bool
    {
        return in_array($slug, self::SYSTEM, true);
    }

    /** @return array<string, array<string, mixed>> les rôles personnalisés seuls */
    public static function custom(): array
    {
        return array_filter(self::all(), static fn(array $r): bool => !$r['system']);
    }

    /**
     * Droits d'un rôle (liste fermée). Administrateur : tout. Rôle inconnu
     * (supprimé à la main en base, sauvegarde partielle) : ceux d'un
     * utilisateur — ce que faisait tout rôle inconnu avant l'écran des rôles,
     * et jamais plus.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $slug): array
    {
        if ($slug === self::ADMIN) {
            return Permissions::allKeys();
        }
        $role = self::find($slug) ?? self::find(self::USER);
        return $role['permissions'] ?? [];
    }

    /** Le rôle accorde-t-il ce droit ? */
    public static function grants(string $slug, string $permission): bool
    {
        if ($slug === self::ADMIN) {
            return true;
        }
        return in_array($permission, self::permissionsFor($slug), true);
    }

    /**
     * Ce compte a-t-il ce droit ? Compte absent ou désactivé : non.
     * Administrateur : oui, toujours. SOURCE UNIQUE des contrôles — la
     * session (Session::can), le worker et les modèles passent par ici.
     */
    public static function userCan(?array $user, string $permission): bool
    {
        if ($user === null || (int)($user['id'] ?? 0) <= 0) {
            return false;
        }
        if (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1) {
            return false;
        }
        return self::grants((string)($user['role'] ?? self::USER), $permission);
    }

    /**
     * Libellé d'un rôle pour l'utilisateur (badge du compte, liste des
     * comptes) : les rôles livrés sont traduits, un rôle personnalisé est un
     * texte écrit par l'administrateur, jamais traduit.
     */
    public static function label(string $slug): string
    {
        if (isset(self::BUILTIN[$slug])) {
            return t(self::BUILTIN[$slug]['short']);
        }
        if ($slug === 'pending') {
            return t('En attente');
        }
        $role = self::find($slug);
        return $role !== null ? (string)$role['name'] : ucfirst($slug);
    }

    /** Couleur d'un rôle (badge), ou celle d'un utilisateur pour un rôle inconnu. */
    public static function color(string $slug): string
    {
        return (string)(self::find($slug)['color'] ?? self::BUILTIN[self::USER]['color']);
    }

    /**
     * Nombre de comptes par rôle (rôles inconnus compris, sous leur slug).
     *
     * @return array<string, int>
     */
    public static function memberCounts(): array
    {
        try {
            $rows = Database::getInstance()->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll();
        } catch (\Throwable $e) {
            error_log('[Rôles] comptage des comptes impossible : ' . $e->getMessage());
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['role']] = (int)$row['n'];
        }
        return $counts;
    }

    /**
     * Rôles qu'un compte peut DONNER : tous pour un administrateur ; pour un
     * gestionnaire de comptes qui ne l'est pas, ceux dont les droits sont
     * contenus dans les siens — jamais l'administrateur. Sans cette garde, le
     * droit « Comptes — Modifier » suffirait à s'accorder tous les droits.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function assignableBy(?array $actor): array
    {
        if ($actor === null) {
            return [];
        }
        $actorRole = (string)($actor['role'] ?? self::USER);
        if ($actorRole === self::ADMIN) {
            return self::all();
        }
        $mine = self::permissionsFor($actorRole);
        return array_filter(
            self::all(),
            static fn(array $role): bool => $role['slug'] !== self::ADMIN
                && Permissions::isSubset($role['permissions'], $mine)
        );
    }

    /**
     * Ce compte (l'acteur) peut-il agir sur celui-là (modifier, supprimer,
     * changer le mot de passe) ? Administrateur : oui. Sinon, jamais sur un
     * administrateur, ni sur un compte dont le rôle dépasse le sien.
     */
    public static function canManageAccount(?array $actor, array $target): bool
    {
        if ($actor === null) {
            return false;
        }
        $actorRole  = (string)($actor['role'] ?? self::USER);
        $targetRole = (string)($target['role'] ?? self::USER);
        if ($actorRole === self::ADMIN) {
            return true;
        }
        if ($targetRole === self::ADMIN) {
            return false;
        }
        return Permissions::isSubset(self::permissionsFor($targetRole), self::permissionsFor($actorRole));
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    /**
     * Valide la saisie d'un rôle personnalisé (pure). Lève une
     * InvalidArgumentException au message présentable.
     *
     * @param array<string, mixed> $input name, description, color
     * @param list<string> $takenNames noms déjà pris (rôles livrés compris), hors celui qu'on modifie
     * @return array{name: string, description: string, color: string}
     */
    public static function normalizeInput(array $input, array $takenNames): array
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($input['name'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException(t('Donnez un nom au rôle.'));
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException(t('Le nom du rôle tient en {n} caractères au plus.', ['n' => self::NAME_MAX]));
        }
        $needle = self::normalizeName($name);
        foreach ($takenNames as $taken) {
            if (self::normalizeName((string)$taken) === $needle) {
                throw new \InvalidArgumentException(t('Un rôle porte déjà ce nom : choisissez-en un autre.'));
            }
        }
        $description = trim((string)preg_replace('/\s+/u', ' ', (string)($input['description'] ?? '')));
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $description = rtrim(mb_substr($description, 0, self::DESCRIPTION_MAX));
        }
        $color = self::sanitizeColor((string)($input['color'] ?? '')) ?? self::COLORS[0];
        return ['name' => $name, 'description' => $description, 'color' => $color];
    }

    /**
     * Identifiant d'un rôle personnalisé, tiré de son nom (pure) :
     * « Responsable RH » → `responsable-rh`. Il ne change plus ensuite
     * (renommer le rôle ne touche pas aux comptes qui le portent).
     *
     * @param list<string> $taken identifiants déjà pris
     */
    public static function slugFrom(string $name, array $taken): string
    {
        $base = str_replace(' ', '-', self::normalizeName($name));
        $max  = self::SLUG_MAX - 3; // place pour un suffixe « -2 »
        if (strlen($base) > $max) {
            // Coupé à un mot entier quand c'est possible : « responsable-assistants »
            // donne `responsable`, pas `responsable-assis`.
            $cut  = substr($base, 0, $max);
            $dash = strrpos($cut, '-');
            $base = ($dash !== false && $dash >= 6) ? substr($cut, 0, $dash) : $cut;
        }
        $base = trim($base, '-');
        if ($base === '' || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = 'role' . ($base !== '' ? '-' . substr($base, 0, self::SLUG_MAX - 8) : '');
            $base = trim($base, '-');
        }
        $taken = array_flip(array_merge($taken, self::RESERVED_SLUGS));
        $slug  = $base;
        for ($i = 2; isset($taken[$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /**
     * Crée un rôle personnalisé. @return string son identifiant
     *
     * @param array{name: string, description: string, color: string} $data saisie validée
     * @param list<string> $permissions
     */
    public static function create(array $data, array $permissions): string
    {
        if (count(self::custom()) >= self::MAX_CUSTOM) {
            throw new \InvalidArgumentException(t('Vous avez atteint le nombre maximal de rôles personnalisés ({n}).', ['n' => self::MAX_CUSTOM]));
        }
        $slug = self::slugFrom($data['name'], array_keys(self::all()));
        Database::getInstance()->prepare(
            'INSERT INTO roles (slug, name, description, color, permissions, is_system) VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([$slug, $data['name'], $data['description'], $data['color'], Permissions::encode($permissions)]);
        self::forget();
        return $slug;
    }

    /**
     * Nom, phrase et couleur d'un rôle personnalisé.
     *
     * @param array{name: string, description: string, color: string} $data saisie validée
     */
    public static function updateDetails(string $slug, array $data): void
    {
        if (self::isSystem($slug)) {
            throw new \InvalidArgumentException(t('Le nom et la couleur d’un rôle livré ne se modifient pas.'));
        }
        Database::getInstance()->prepare(
            'UPDATE roles SET name = ?, description = ?, color = ? WHERE slug = ?'
        )->execute([$data['name'], $data['description'], $data['color'], $slug]);
        self::forget();
    }

    /**
     * Droits d'un rôle (créateur, utilisateur, personnalisé). L'administrateur
     * est verrouillé : ses droits ne se règlent pas.
     *
     * @param list<string> $permissions
     */
    public static function savePermissions(string $slug, array $permissions): void
    {
        if ($slug === self::ADMIN) {
            throw new \InvalidArgumentException(t('Le rôle Administrateur a tous les droits : il ne se modifie pas.'));
        }
        if (!self::exists($slug)) {
            throw new \InvalidArgumentException(t('Rôle introuvable.'));
        }
        $json = Permissions::encode($permissions);
        if (self::isSystem($slug)) {
            // Ligne absente (base remise à zéro, instance antérieure à 090) :
            // créée à la volée.
            Database::getInstance()->prepare(
                'INSERT INTO roles (slug, name, description, color, permissions, is_system)
                 VALUES (?, ?, \'\', \'\', ?, 1)
                 ON DUPLICATE KEY UPDATE permissions = VALUES(permissions)'
            )->execute([$slug, self::BUILTIN[$slug]['name'], $json]);
        } else {
            Database::getInstance()->prepare('UPDATE roles SET permissions = ? WHERE slug = ?')->execute([$json, $slug]);
        }
        self::forget();
    }

    /** Rôle livré : retour aux droits par défaut (la surcharge est effacée). */
    public static function resetPermissions(string $slug): void
    {
        if (!in_array($slug, [self::CREATOR, self::USER], true)) {
            throw new \InvalidArgumentException(t('Seuls les rôles Créateur et Utilisateur ont des droits par défaut à rétablir.'));
        }
        Database::getInstance()->prepare('UPDATE roles SET permissions = NULL WHERE slug = ?')->execute([$slug]);
        self::forget();
    }

    /**
     * Supprime un rôle personnalisé ; ses comptes passent à `$fallback`, dans
     * la même transaction. Un rôle livré ne se supprime pas, et la
     * réaffectation ne donne jamais le rôle Administrateur (une suppression
     * ne doit pas promouvoir des comptes en masse).
     *
     * @return int comptes réaffectés
     */
    public static function delete(string $slug, string $fallback): int
    {
        if (self::isSystem($slug)) {
            throw new \InvalidArgumentException(t('Les rôles Administrateur, Créateur et Utilisateur ne se suppriment pas.'));
        }
        if (!self::exists($slug)) {
            throw new \InvalidArgumentException(t('Rôle introuvable.'));
        }
        if ($fallback === $slug || $fallback === self::ADMIN || !self::exists($fallback)) {
            throw new \InvalidArgumentException(t('Choisissez le rôle que prendront ses comptes (ni celui-ci, ni Administrateur).'));
        }
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $moved = $db->prepare('UPDATE users SET role = ? WHERE role = ?');
            $moved->execute([$fallback, $slug]);
            $count = $moved->rowCount();
            $db->prepare('DELETE FROM roles WHERE slug = ?')->execute([$slug]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::forget();
        return $count;
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function builtin(string $slug, ?array $row): array
    {
        $def      = self::BUILTIN[$slug];
        $defaults = Permissions::defaultsFor($slug) ?? [];
        if ($slug === self::ADMIN) {
            $permissions = Permissions::allKeys();
        } else {
            // Surcharge éventuelle ; un droit qu'elle ne connaît pas (ajouté
            // par une version future) prend la valeur du défaut.
            $permissions = Permissions::decode($row['permissions'] ?? null, $defaults);
        }
        return [
            'id'          => $row !== null ? (int)($row['id'] ?? 0) : null,
            'slug'        => $slug,
            'name'        => $def['name'],
            'short'       => $def['short'],
            'description' => $def['description'],
            'color'       => $def['color'],
            'system'      => true,
            'locked'      => $slug === self::ADMIN,
            'customized'  => $slug !== self::ADMIN && $permissions !== $defaults,
            'permissions' => $permissions,
        ];
    }

    private static function sanitizeColor(string $color): ?string
    {
        $color = strtolower(trim($color));
        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    /** Minuscules sans accents ni ponctuation (comparaison des noms, identifiant). */
    private static function normalizeName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
