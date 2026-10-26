<?php

declare(strict_types=1);

/**
 * Où mène chaque menu — SOURCE UNIQUE des destinations de MIA, rangées en
 * trois espaces qu'on ne confond plus, chaque page dans un seul :
 *
 *   - l'ESPACE DE TRAVAIL (ce que je fais) : assistants, bases de
 *     connaissances, tâches programmées, workflows, mes créations — tuiles du
 *     volet du chat (sidebar-shortcuts.php), dont les pages gardent toujours
 *     le volet du chat (NavShell) ;
 *   - MON COMPTE (ce qui me concerne) : profil, préférences IA, affichage,
 *     comptes connectés, usage et crédits — menu latéral des pages /settings,
 *     où mène directement le bouton « Réglages » du bas de la barre latérale ;
 *   - l'ADMINISTRATION (ce qui concerne l'organisation) : les sept rubriques
 *     d'AdminMenu, filtrées par AdminAccess — menu latéral de
 *     l'administration, où mène directement le bouton « Admin ».
 *
 * La palette ⌘K (command-palette.php) lit les trois par paletteEntries().
 *
 * Le problème résolu : la même page s'atteignait par quatre chemins (raccourcis
 * du chat, menu Paramètres, menu Administration, menus latéraux), et les menus
 * rangeaient ensemble des pages qui n'avaient rien à voir (« Paramètres »
 * contenait des créations, des tâches et des workflows). Les adresses, elles,
 * n'ont pas bougé : liens, favoris et documentation restent valables.
 *
 * Les libellés sont les clés de traduction (le français est le code). Les
 * formes sont pures sauf les filtres de droits, qui lisent le rôle du compte.
 */
final class NavRegistry
{
    /**
     * Espace de travail, dans l'ordre des tuiles. `page` = valeur de `$page`
     * du gabarit (NavShell), `short` = libellé de la tuile.
     *
     * @var array<string, array{label:string, short:string, href:string, icon:string, page:string, beta?:bool}>
     */
    public const WORKSPACE = [
        'profiles'  => ['label' => 'Assistants',             'short' => 'Assistants', 'href' => '/admin/profiles',        'icon' => 'profiles',  'page' => 'profiles'],
        'kb'        => ['label' => 'Bases de connaissances', 'short' => 'Bases',      'href' => '/admin/knowledge-bases', 'icon' => 'kb',        'page' => 'knowledge-bases'],
        'schedules' => ['label' => 'Tâches programmées',     'short' => 'Tâches',     'href' => '/settings/schedules',    'icon' => 'schedules', 'page' => 'schedules'],
        'workflows' => ['label' => 'Workflows',              'short' => 'Workflows',  'href' => '/workflows',             'icon' => 'workflows', 'page' => 'workflows', 'beta' => true],
        'creations' => ['label' => 'Mes créations',          'short' => 'Créations',  'href' => '/settings/creations',    'icon' => 'creations', 'page' => 'creations'],
    ];

    /**
     * Mon compte, dans l'ordre du menu latéral. `page` = valeur de
     * `$page` du gabarit de la page.
     *
     * @var array<string, array{label:string, href:string, icon:string, page:string}>
     */
    public const ACCOUNT = [
        'profile'     => ['label' => 'Mon profil',        'href' => '/settings',             'icon' => 'profile',    'page' => 'profile'],
        'ai'          => ['label' => 'Préférences IA',    'href' => '/settings/ai',          'icon' => 'ai',         'page' => 'ai'],
        'display'     => ['label' => 'Affichage',         'href' => '/settings/display',     'icon' => 'display',    'page' => 'display'],
        'connections' => ['label' => 'Comptes connectés', 'href' => '/settings/connections', 'icon' => 'connectors', 'page' => 'connections'],
        'usage'       => ['label' => 'Usage & crédits',   'href' => '/settings/usage',       'icon' => 'usage',      'page' => 'usage'],
    ];

    /**
     * Pages sœurs reliées par une barre d'onglets (AdminTabs) que la palette
     * propose aussi : on y cherche « journaux » ou « élagage » sans savoir
     * qu'ils vivent sous Diagnostic ou Données. $page → rubrique ; libellé et
     * adresse sont ceux de l'onglet (AdminTabs, source unique).
     */
    private const ADMIN_TAB_PAGES = [
        'logs'                   => 'maintenance',
        'knowledge-bases-vector' => 'maintenance',
        'agent'                  => 'maintenance',
        'pruning'                => 'maintenance',
    ];

    /**
     * Mots qui retrouvent une page dans la palette sans figurer dans son
     * libellé — dont les ANCIENS noms des menus, que les habitués taperont
     * encore. Recherche seulement, jamais affichés.
     */
    private const KEYWORDS = [
        'profile'              => 'paramètres compte nom photo langue mot de passe',
        'ai'                   => 'paramètres style contexte mémoire mia pour vous voix',
        'display'              => 'paramètres thème sombre clair taille contraste',
        'connections'          => 'paramètres connexions gmail drive agenda outlook slack teams google microsoft',
        'usage'                => 'paramètres usage consommation quota solde',
        'profiles'             => 'assistant',
        'kb'                   => 'rag documents base',
        'schedules'            => 'routine planifier programmer',
        'workflows'            => 'automatisation',
        'creations'            => 'galerie images pages présentations',
        'dashboard'            => 'administration accueil',
        'users'                => 'utilisateurs personnes',
        'user-groups'          => 'utilisateurs personnes quotas',
        'roles'                => 'droits permissions',
        'llms-keys'            => 'gestion llms fournisseurs openai anthropic',
        'llms-models'          => 'gestion llms défaut',
        'llms-tests'           => 'gestion llms',
        'knowledge-bases-sync' => 'bases drive sharepoint onedrive',
        'usage-admin'          => 'usages',
        'company'              => 'configuration vocabulaire modèle powerpoint word',
        'settings'             => 'configuration logo couleur nom',
        'sso'                  => 'configuration connexion google microsoft mot de passe',
        'diagnostics'          => 'configuration santé contrôles',
        'backup'               => 'configuration sauvegarde restauration',
    ];

    /** Icône d'une destination, à la taille voulue. */
    public static function icon(string $key, int $size = 16): string
    {
        return match ($key) {
            'schedules' => ScheduledTask::icon($size),
            'workflows' => Workflow::icon($size),
            default     => AdminMenu::icon($key, $size),
        };
    }

    /**
     * Pages de l'espace de travail que ce compte ouvre, dans l'ordre. Un
     * utilisateur sans droit de création n'y voit que ses tâches et ses
     * créations.
     *
     * @return array<string, array{label:string, short:string, href:string, icon:string, page:string, beta?:bool}>
     */
    public static function workspaceFor(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        $ouvre = [
            'profiles'  => AdminAccess::canOpen('profiles', $user),
            'kb'        => AdminAccess::canOpen('knowledge-bases', $user),
            'schedules' => ScheduledTask::allows($user, 'view'),
            'workflows' => Workflow::allows($user, 'view'),
            'creations' => true,
        ];
        return array_filter(self::WORKSPACE, static fn(string $k): bool => !empty($ouvre[$k]), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Pages de « Mon compte » : toutes, pour tout compte connecté.
     *
     * @return array<string, array{label:string, href:string, icon:string, page:string}>
     */
    public static function accountFor(?array $user): array
    {
        return $user === null ? [] : self::ACCOUNT;
    }

    /**
     * Rubriques de l'administration que ce compte ouvre, chacune avec ses pages
     * ouvrables (libellé, adresse), dans l'ordre du menu.
     *
     * @return array<string, array{label:string, href:string, group:?string, icon:string, pages:array<string, array{label:string, text:string, href:string}>}>
     */
    public static function adminFor(?array $user): array
    {
        $out = [];
        foreach (AdminAccess::visibleEntries($user) as $key => $entry) {
            $pages = [];
            foreach (AdminAccess::openPagesOf($key, $user) as $page) {
                if (!isset(AdminAccess::PAGE_HREFS[$page])) {
                    continue;
                }
                $pages[$page] = ['label' => AdminMenu::pageLabel($page), 'text' => AdminMenu::pageText($page), 'href' => AdminAccess::PAGE_HREFS[$page]];
            }
            $entry['pages'] = $pages;
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * Ce que propose la palette ⌘K à ce compte : les pages des trois espaces et
     * quelques actions, libellés TRADUITS (la palette est une surface du chat).
     * `space` colore la pastille (work | me | admin), `kind` range le
     * résultat (page | action), `path` dit où vit la page, `keys` sert
     * seulement à la recherche, `icon` est une clé de NavRegistry::icon().
     *
     * @param list<string> $reconnect clés de connecteurs à reconnecter (ConnectorRegistry::reconnectNeeded)
     * @return list<array{kind:string, space:string, label:string, path:string, href:string, keys:string, icon:string}>
     */
    public static function paletteEntries(?array $user, array $reconnect = []): array
    {
        if ($user === null) {
            return [];
        }
        $out = [];
        $espace  = t('Espace de travail');
        $compte  = t('Mon compte');
        $admin   = t('Administration');

        $out[] = self::entry('action', 'work', t('Nouvelle conversation'), $espace, '/', 'discuter chat nouveau', 'chat');
        foreach (self::workspaceFor($user) as $key => $w) {
            $out[] = self::entry('page', 'work', t($w['label']), $espace, $w['href'], self::KEYWORDS[$key] ?? '', $w['icon']);
        }
        if (AdminAccess::canOpen('profiles-new', $user)) {
            $out[] = self::entry('action', 'work', t('Créer un assistant'), $espace . ' › ' . t('Assistants'), '/admin/profiles/new', 'nouvel assistant', 'plus');
        }
        if (Role::userCan($user, 'knowledge_bases.create')) {
            $out[] = self::entry('action', 'work', t('Créer une base de connaissances'), $espace . ' › ' . t('Bases de connaissances'), '/admin/knowledge-bases/new', 'nouvelle base', 'plus');
        }
        if (ScheduledTask::allows($user, 'create')) {
            $out[] = self::entry('action', 'work', t('Programmer une tâche'), $espace . ' › ' . t('Tâches programmées'), '/settings/schedules/new', 'nouvelle tâche routine', 'plus');
        }
        if (Workflow::allows($user, 'create')) {
            $out[] = self::entry('action', 'work', t('Créer un workflow'), $espace . ' › ' . t('Workflows'), '/workflows/new', 'nouveau workflow', 'plus');
        }

        foreach (self::accountFor($user) as $key => $a) {
            $out[] = self::entry('page', 'me', t($a['label']), t('Réglages') . ' › ' . $compte, $a['href'], self::KEYWORDS[$key] ?? '', $a['icon']);
        }
        foreach ($reconnect as $key) {
            $out[] = self::entry('action', 'me', t('Reconnecter {service}', ['service' => ConnectorRegistry::label($key)]), t('Réglages') . ' › ' . t('Comptes connectés'), '/settings/connections#connector-' . $key, 'reconnecter connexion expirée', 'connectors');
        }

        foreach (self::adminFor($user) as $rubrique => $r) {
            $chemin = $admin . ($rubrique === 'dashboard' ? '' : ' › ' . AdminMenu::label($rubrique));
            foreach ($r['pages'] as $page => $p) {
                $cle = $page === 'usage' ? 'usage-admin' : $page;
                $out[] = self::entry('page', 'admin', $p['text'], $chemin, $p['href'], self::KEYWORDS[$cle] ?? '', $r['icon']);
            }
        }
        foreach (self::ADMIN_TAB_PAGES as $page => $rubrique) {
            $href = AdminTabs::href($page);
            if ($href !== null && AdminAccess::canOpen($page, $user)) {
                $out[] = self::entry('page', 'admin', AdminTabs::ongletText($page), $admin . ' › ' . AdminMenu::label($rubrique), $href, '', AdminMenu::ENTRIES[$rubrique]['icon']);
            }
        }
        if (AdminAccess::canOpen('budget', $user) && Role::userCan($user, 'costs.edit')) {
            $out[] = self::entry('action', 'admin', t('Accorder une rallonge de budget'), $admin . ' › ' . t('Coûts'), '/admin/budget#budget-extra', 'budget rallonge', 'costs');
        }
        $out[] = self::entry('page', 'me', t('À propos de MIA'), t('Réglages'), '/a-propos', 'version nouveautés', 'info');
        return $out;
    }

    /** @return array{kind:string, space:string, label:string, path:string, href:string, keys:string, icon:string} */
    private static function entry(string $kind, string $space, string $label, string $path, string $href, string $keys, string $icon): array
    {
        return ['kind' => $kind, 'space' => $space, 'label' => $label, 'path' => $path, 'href' => $href, 'keys' => $keys, 'icon' => $icon];
    }
}
