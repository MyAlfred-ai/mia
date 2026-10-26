<?php

declare(strict_types=1);

/**
 * Rubriques de l'administration — SOURCE UNIQUE de leur libellé, de leurs
 * pages et de leur icône, lue par le menu latéral de l'administration
 * (templates/partials/admin-nav.php) et la palette ⌘K
 * (NavRegistry::paletteEntries).
 *
 * Sept rubriques, chacune regroupant des pages sœurs (AdminAccess::ENTRY_PAGES
 * en donne l'ordre, AdminAccess::PAGE_HREFS les adresses) : Tableau de bord,
 * Personnes, Modèles IA, Capacités, Coûts, Organisation, Maintenance. Les
 * assistants et les bases de connaissances n'y sont PAS : ce sont des pages de
 * l'espace de travail (NavRegistry::WORKSPACE), où l'on conçoit — un
 * administrateur les y trouve comme tout créateur.
 *
 * Les libellés sont les clés de traduction (le français est le code, cf. I18n),
 * constantes qui ne peuvent pas appeler t() : ils se traduisent à l'ACCESSEUR
 * — label() pour une rubrique, pageText() pour une page, pathText() pour le
 * chemin « Rubrique › Page » —, le même pour le menu latéral, la palette ⌘K et
 * le tableau de bord : les trois disent la même chose dans les deux langues.
 * Le texte d'une pastille (bulle) vient d'AdminAttention, ou de budgetSignal()
 * pour le budget.
 */
final class AdminMenu
{
    /**
     * Ordre = ordre du menu. `group` désigne le groupe d'AdminAttention dont la
     * rubrique relaie la pastille (null : aucune, ou celle du budget pour Coûts),
     * `icon` une clé d'ICONS.
     *
     * @var array<string, array{label:string, href:string, group:?string, icon:string}>
     */
    public const ENTRIES = [
        'dashboard'    => ['label' => 'Tableau de bord', 'href' => '/admin',             'group' => null,           'icon' => 'dashboard'],
        'people'       => ['label' => 'Personnes',       'href' => '/admin/users',       'group' => 'people',       'icon' => 'users'],
        'models'       => ['label' => 'Modèles IA',      'href' => '/admin/llms',        'group' => 'models',       'icon' => 'llms'],
        'capabilities' => ['label' => 'Capacités',       'href' => '/admin/tools',       'group' => 'capabilities', 'icon' => 'tools'],
        'costs'        => ['label' => 'Coûts',           'href' => '/admin/budget',      'group' => null,           'icon' => 'costs'],
        'organisation' => ['label' => 'Organisation',    'href' => '/admin/company',     'group' => 'organisation', 'icon' => 'organisation'],
        'maintenance'  => ['label' => 'Maintenance',     'href' => '/admin/diagnostics', 'group' => 'maintenance',  'icon' => 'maintenance'],
    ];

    /**
     * Libellé de chaque page dans sa rubrique (menu latéral, palette). Une rubrique d'une seule page (Tableau de bord) n'en a pas
     * besoin d'autre que le sien.
     *
     * @var array<string, string>
     */
    public const PAGE_LABELS = [
        'dashboard'            => 'Tableau de bord',
        'users'                => 'Comptes',
        'user-groups'          => 'Groupes',
        'roles'                => 'Rôles',
        'llms-keys'            => 'Clés API',
        'llms-models'          => 'Modèles',
        'llms-tests'           => 'Tests',
        'tools'                => 'Outils',
        'connectors'           => 'Connecteurs',
        'knowledge-bases-sync' => 'Sources synchronisées',
        'budget'               => 'Budget',
        'usage'                => 'Consommation',
        'credits'              => 'Tarifs & crédits',
        'company'              => 'Entreprise',
        'settings'             => 'Marque & apparence',
        'sso'                  => 'Authentification',
        'diagnostics'          => 'Diagnostic',
        'backup'               => 'Données',
    ];

    /**
     * Contexte de traduction d'un libellé de page quand le même mot français
     * se traduit autrement ailleurs (« Modèles » des workflows = templates).
     *
     * @var array<string, string>
     */
    public const PAGE_CONTEXTS = [
        'llms-models' => 'llm',
    ];

    /**
     * Bibliothèque d'icônes des menus (trait de 2, 24 × 24), rendues à la
     * taille voulue par icon() : rubriques de l'administration, pages de
     * l'espace et du compte (NavRegistry), et les schémas des tâches, des
     * assistants et des bases qui en reprennent quelques-unes.
     *
     * @var array<string, string>
     */
    public const ICONS = [
        'dashboard'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'users'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'llms'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="5" width="14" height="14" rx="2"/><rect x="10" y="10" width="4" height="4"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/></svg>',
        'profiles'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="9" width="16" height="11" rx="2"/><path d="M12 9V6"/><circle cx="12" cy="4" r="1.6"/><path d="M9 13.5v2M15 13.5v2"/></svg>',
        'kb'           => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'tools'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'connectors'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'costs'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 10c0-1.1 1.2-2 3-2s3 .9 3 2-1.2 2-3 2-3 .9-3 2 1.2 2 3 2 3-.9 3-2"/><path d="M12 7v10"/></svg>',
        'config'       => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
        'organisation' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/></svg>',
        'maintenance'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'creations'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'profile'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'ai'           => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4v1h1a3 3 0 0 1 3 3v1a3 3 0 0 1-2 2.83V16a4 4 0 0 1-4 4h-1v1a1 1 0 1 1-2 0v-1h-1a4 4 0 0 1-4-4v-2.17A3 3 0 0 1 4 11v-1a3 3 0 0 1 3-3h1V6a4 4 0 0 1 4-4z"/><circle cx="9.5" cy="11.5" r="1"/><circle cx="14.5" cy="11.5" r="1"/></svg>',
        'display'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18Z" fill="currentColor"/></svg>',
        'usage'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'gear'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'shield'       => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
        'search'       => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'chat'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'plus'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'info'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        'logout'       => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        'more'         => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>',
    ];

    /** Toutes les rubriques, dans l'ordre (sans filtre de droits). */
    public static function forAdmin(): array
    {
        return self::ENTRIES;
    }

    /** Icône à la taille voulue (le menu latéral la rend en 15 px). */
    public static function icon(string $key, int $size = 16): string
    {
        $svg = self::ICONS[$key] ?? '';
        return (string)preg_replace('/^<svg width="15" height="15"/', '<svg width="' . $size . '" height="' . $size . '" aria-hidden="true"', $svg);
    }

    /** Libellé TRADUIT d'une rubrique (« Personnes » → « People »), à défaut sa clé. */
    public static function label(string $key): string
    {
        return isset(self::ENTRIES[$key]) ? t(self::ENTRIES[$key]['label']) : $key;
    }

    /**
     * Libellé SOURCE d'une page (« Comptes »), à défaut sa clé : la clé de
     * traduction, en français — pour comparer, jamais pour afficher.
     */
    public static function pageLabel(string $page): string
    {
        return self::PAGE_LABELS[$page] ?? $page;
    }

    /** Libellé TRADUIT d'une page (menu latéral, palette ⌘K, tableau de bord). */
    public static function pageText(string $page): string
    {
        return t(self::pageLabel($page), [], self::PAGE_CONTEXTS[$page] ?? '');
    }

    /**
     * Chemin TRADUIT d'une page dans le menu : « Personnes › Comptes ». Une
     * page hors des rubriques (assistants, bases : espace de travail) rend
     * `$fallback` traduit, sinon son seul libellé. Le tableau de bord nomme
     * ainsi où corriger un point à régler.
     */
    public static function pathText(string $page, string $fallback = ''): string
    {
        foreach (AdminAccess::ENTRY_PAGES as $key => $pages) {
            if ($key !== 'dashboard' && in_array($page, $pages, true)) {
                return self::label($key) . ' › ' . self::pageText($page);
            }
        }
        return $fallback !== '' ? t($fallback) : self::pageText($page);
    }

    /**
     * Pastille du bouton « Admin » (nombre de points à régler) : TOUS les
     * constats, dédiés compris.
     *
     * @return array{level:string, count:int}|null
     */
    public static function badge(array $attention): ?array
    {
        if ($attention === []) {
            return null;
        }
        return ['level' => (string)AdminAttention::worstLevel($attention), 'count' => count($attention)];
    }

    /**
     * Pastille de « Coûts » depuis l'état du budget (AiBudget::status()) :
     * ambre dès le seuil d'alerte, rouge au plafond — même règle que le groupe
     * du menu latéral, mêmes phrases.
     *
     * @param array{state:string, blocks:bool, pct:int|float} $status
     * @return array{level:string, text:string}|null
     */
    public static function budgetSignal(array $status): ?array
    {
        $state = (string)($status['state'] ?? '');
        if (!in_array($state, [AiBudget::STATE_ALERT, AiBudget::STATE_REACHED], true)) {
            return null;
        }
        return [
            'level' => $state === AiBudget::STATE_REACHED ? AdminAttention::ALERT : AdminAttention::WARN,
            'text'  => !empty($status['blocks'])
                ? t('Budget IA atteint : les demandes sont suspendues pour tous.')
                : t('Budget IA : {pct} % consommé ce mois-ci.', ['pct' => (int)($status['pct'] ?? 0)]),
        ];
    }
}
