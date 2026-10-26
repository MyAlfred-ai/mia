<?php

declare(strict_types=1);

/**
 * Qui ouvre quelle page de l'administration — SOURCE UNIQUE, lue par le menu
 * latéral (admin-nav.php), le bouton « Admin », la palette ⌘K, la
 * redirection de /admin, le filtre des points à régler et le mode lecture seule.
 *
 * Une page (la valeur `$page` que chaque gabarit pose avant le menu) se
 * rattache à une fonctionnalité du catalogue des droits (Permissions) : le
 * droit qui l'OUVRE, et les actions qui y ÉCRIVENT. Qui l'ouvre sans aucun de
 * ces droits d'écriture la voit en lecture seule (bandeau, contrôles des
 * formulaires désactivés) — le serveur refusant de toute façon chaque
 * écriture (AuthMiddleware::requirePermission), le mode lecture seule n'est
 * qu'un confort : on ne tape pas dans un champ qui ne s'enregistrera pas.
 *
 * Rôles et Données n'ont pas de droit : réservés au rôle Administrateur
 * (Permissions::ADMIN_ONLY).
 */
final class AdminAccess
{
    /** Pas de droit : seul le rôle Administrateur ouvre la page. */
    public const ADMIN_ONLY = null;

    /**
     * $page → [droit qui l'ouvre (null = administrateur seul), fonctionnalité,
     *          actions qui y écrivent].
     *
     * @var array<string, array{0: ?string, 1: ?string, 2: list<string>}>
     */
    public const PAGES = [
        'dashboard'              => ['dashboard.view',       'dashboard',       []],
        'users'                  => ['users.view',           'users',           ['create', 'edit', 'delete']],
        'user-groups'            => ['groups.view',          'groups',          ['create', 'edit', 'delete']],
        'roles'                  => [self::ADMIN_ONLY,       null,              []],
        'llms-keys'              => ['llms.view',            'llms',            ['use', 'create', 'edit', 'delete']],
        'llms-models'            => ['llms.view',            'llms',            ['use', 'create', 'edit', 'delete']],
        'llms-tests'             => ['llms.view',            'llms',            ['use', 'create', 'edit', 'delete']],
        'profiles'               => ['assistants.view',      'assistants',      ['create', 'edit', 'delete']],
        'profiles-new'           => ['assistants.create',    'assistants',      ['create']],
        'knowledge-bases'        => ['knowledge_bases.view', 'knowledge_bases', ['create', 'edit', 'delete']],
        'knowledge-bases-sync'   => ['knowledge_bases.manage', 'knowledge_bases', ['manage']],
        'tools'                  => ['tools.view',           'tools',           ['create', 'edit', 'delete']],
        'connectors'             => ['connectors.view',      'connectors',      ['edit']],
        'budget'                 => ['costs.view',           'costs',           ['edit']],
        'usage'                  => ['costs.view',           'costs',           ['edit']],
        'credits'                => ['costs.view',           'costs',           ['edit']],
        'company'                => ['company.view',         'company',         ['edit']],
        'settings'               => ['company.view',         'company',         ['edit']],
        'sso'                    => ['sso.view',             'sso',             ['edit']],
        'diagnostics'            => ['diagnostics.view',     'diagnostics',     ['use']],
        'logs'                   => ['diagnostics.view',     'diagnostics',     []],
        'knowledge-bases-vector' => ['diagnostics.view',     'diagnostics',     ['use']],
        'agent'                  => ['diagnostics.view',     'diagnostics',     []],
        'backup'                 => [self::ADMIN_ONLY,       null,              []],
        'pruning'                => [self::ADMIN_ONLY,       null,              []],
    ];

    /**
     * Rubriques du menu (AdminMenu::ENTRIES) → pages qu'elles regroupent, dans
     * l'ordre : la rubrique est visible dès qu'une de ses pages s'ouvre, et
     * mène à la première qui s'ouvre (« Personnes » → Groupes pour qui ne voit
     * pas les comptes). Les assistants et les bases de connaissances n'y sont
     * pas : pages de l'espace de travail (NavRegistry), leurs droits restent
     * décrits dans PAGES.
     *
     * @var array<string, list<string>>
     */
    public const ENTRY_PAGES = [
        'dashboard'    => ['dashboard'],
        'people'       => ['users', 'user-groups', 'roles'],
        'models'       => ['llms-keys', 'llms-models', 'llms-tests'],
        'capabilities' => ['tools', 'connectors', 'knowledge-bases-sync'],
        'costs'        => ['budget', 'usage', 'credits'],
        'organisation' => ['company', 'settings', 'sso'],
        'maintenance'  => ['diagnostics', 'backup'],
    ];

    /** Adresse de chaque page (première page ouvrable d'une entrée). */
    public const PAGE_HREFS = [
        'dashboard'              => '/admin',
        'users'                  => '/admin/users',
        'user-groups'            => '/admin/user-groups',
        'roles'                  => '/admin/roles',
        'llms-keys'              => '/admin/llms',
        'llms-models'            => '/admin/llms/models',
        'llms-tests'             => '/admin/llms/tests',
        'profiles'               => '/admin/profiles',
        'knowledge-bases'        => '/admin/knowledge-bases',
        'knowledge-bases-sync'   => '/admin/knowledge-bases/sync',
        'tools'                  => '/admin/tools',
        'connectors'             => '/admin/connectors',
        'budget'                 => '/admin/budget',
        'usage'                  => '/admin/usage',
        'credits'                => '/admin/credits',
        'company'                => '/admin/company',
        'settings'               => '/admin/settings',
        'sso'                    => '/admin/sso',
        'diagnostics'            => '/admin/diagnostics',
        'backup'                 => '/admin/backup',
    ];

    /** Ce compte ouvre-t-il cette page ? Page inconnue : administrateur seul. */
    public static function canOpen(string $page, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (!array_key_exists($page, self::PAGES)) {
            return (string)($user['role'] ?? '') === Role::ADMIN && Role::userCan($user, 'dashboard.view');
        }
        $permission = self::PAGES[$page][0];
        if ($permission === self::ADMIN_ONLY) {
            return (string)($user['role'] ?? '') === Role::ADMIN && Role::userCan($user, 'dashboard.view');
        }
        return Role::userCan($user, $permission);
    }

    /**
     * Page ouverte en lecture seule : la personne la voit, mais n'a aucun des
     * droits qui y écrivent. Une page sans écriture (journaux, tableau de
     * bord) n'est jamais « en lecture seule » : il n'y a rien à désactiver.
     */
    public static function readOnly(string $page, ?array $user): bool
    {
        if ($user === null || !isset(self::PAGES[$page])) {
            return false;
        }
        [, $feature, $writes] = self::PAGES[$page];
        if ($feature === null || $writes === []) {
            return false;
        }
        foreach ($writes as $action) {
            if (Role::userCan($user, $feature . '.' . $action)) {
                return false;
            }
        }
        return true;
    }

    /** Pages d'une entrée du menu que ce compte ouvre, dans l'ordre. @return list<string> */
    public static function openPagesOf(string $entry, ?array $user): array
    {
        return array_values(array_filter(
            self::ENTRY_PAGES[$entry] ?? [],
            static fn(string $page): bool => self::canOpen($page, $user)
        ));
    }

    /**
     * Rubriques de l'administration visibles pour ce compte (AdminMenu::ENTRIES
     * filtrées), chacune menant à SA première page ouvrable.
     *
     * @return array<string, array{label:string, href:string, group:?string, icon:string, desc:string}>
     */
    public static function visibleEntries(?array $user): array
    {
        $out = [];
        foreach (AdminMenu::ENTRIES as $key => $entry) {
            $pages = self::openPagesOf($key, $user);
            if ($pages === []) {
                continue;
            }
            $entry['href'] = self::PAGE_HREFS[$pages[0]] ?? $entry['href'];
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * L'administration (bouton « Admin » du bas de la barre latérale) : au
     * moins une rubrique ouverte. Un créateur ne l'a pas — ses
     * assistants et ses bases sont des pages de l'espace de travail.
     */
    public static function hasAdministration(?array $user): bool
    {
        return self::visibleEntries($user) !== [];
    }

    /**
     * Où mène /admin pour ce compte : le tableau de bord s'il l'ouvre, sinon la
     * première page de ses rubriques ; à défaut, les pages de l'espace de
     * travail hébergées sous /admin (assistants, puis bases) — l'adresse
     * qu'un créateur gardait en favori. Null : aucune.
     */
    public static function home(?array $user): ?string
    {
        $entries = self::visibleEntries($user);
        if ($entries !== []) {
            return (string)reset($entries)['href'];
        }
        foreach (['profiles', 'knowledge-bases'] as $page) {
            if (self::canOpen($page, $user)) {
                return self::PAGE_HREFS[$page];
            }
        }
        return null;
    }

    /**
     * Points à régler (AdminAttention) que ce compte peut corriger : ceux des
     * pages qu'il ouvre. Un administrateur les voit tous.
     *
     * @param list<array<string, mixed>> $attention
     * @return list<array<string, mixed>>
     */
    public static function filterAttention(array $attention, ?array $user): array
    {
        if ($user !== null && (string)($user['role'] ?? '') === Role::ADMIN) {
            return $attention;
        }
        return array_values(array_filter(
            $attention,
            static fn(array $s): bool => self::canOpen((string)($s['entry'] ?? ''), $user)
        ));
    }
}
