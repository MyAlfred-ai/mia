<?php

declare(strict_types=1);

/**
 * Fil d'Ariane de la barre du haut des pages d'administration et de réglages
 * (templates/partials/admin-topbar.php).
 *
 * Source unique du chemin affiché : « Administration › Personnes › Comptes »,
 * « Administration › Maintenance › Diagnostic › Journaux », « Mon compte ›
 * Affichage ». Tout est LU dans les registres qui portent déjà les libellés
 * (AdminMenu pour les rubriques et les pages, AdminTabs pour les onglets d'une
 * page à onglets, NavRegistry pour le compte) : aucun libellé recopié, une page
 * renommée change de nom ici aussi.
 *
 * Forme pure : aucun accès base, aucun droit (une page qu'on voit est une page
 * qu'on a le droit d'ouvrir ; ses ancêtres sont des LIENS vers leur première
 * page, que le serveur garde de toute façon).
 */
final class AdminBreadcrumb
{
    /**
     * Étapes du chemin, de la racine à la page courante. La dernière n'a pas
     * d'adresse (`href` null) : c'est la page affichée.
     *
     * @return list<array{label:string, href:?string}>
     */
    public static function trail(string $page, string $variant = 'admin'): array
    {
        if ($variant === 'settings') {
            $trail = [['label' => t('Mon compte'), 'href' => '/settings']];
            foreach (NavRegistry::ACCOUNT as $entry) {
                if (($entry['page'] ?? '') === $page) {
                    $trail[] = ['label' => t($entry['label']), 'href' => null];
                    return $trail;
                }
            }
            $trail[0]['href'] = null;
            return $trail;
        }

        $root = ['label' => t('Administration'), 'href' => '/admin'];
        if ($page === '' || $page === 'dashboard') {
            return [$root, ['label' => AdminMenu::pageText('dashboard'), 'href' => null]];
        }

        // Page d'un groupe à onglets (Diagnostic, Données) : la rubrique, le
        // groupe (lien vers son premier onglet), puis l'onglet.
        $groupe = AdminTabs::groupeDe($page);
        if ($groupe !== null) {
            $onglets = AdminTabs::onglets($groupe);
            $premier = array_key_first($onglets);
            $trail = [$root];
            $entry = self::entryOf((string)$premier) ?? self::entryOf($page);
            if ($entry !== null) {
                $trail[] = ['label' => AdminMenu::label($entry), 'href' => AdminMenu::ENTRIES[$entry]['href']];
            }
            $trail[] = ['label' => AdminTabs::libelleText($groupe), 'href' => $premier !== null ? ($onglets[$premier][0] ?? null) : null];
            $trail[] = ['label' => AdminTabs::ongletText($page), 'href' => null];
            return $trail;
        }

        $entry = self::entryOf($page);
        if ($entry === null) {
            return [$root, ['label' => AdminMenu::pageText($page), 'href' => null]];
        }
        if ($entry === 'dashboard') {
            return [$root, ['label' => AdminMenu::pageText('dashboard'), 'href' => null]];
        }
        return [
            $root,
            ['label' => AdminMenu::label($entry), 'href' => AdminMenu::ENTRIES[$entry]['href']],
            ['label' => AdminMenu::pageText($page), 'href' => null],
        ];
    }

    /** Rubrique du menu qui porte une page (AdminAccess::ENTRY_PAGES), ou null. */
    public static function entryOf(string $page): ?string
    {
        foreach (AdminAccess::ENTRY_PAGES as $entry => $pages) {
            if (in_array($page, $pages, true)) {
                return $entry;
            }
        }
        return null;
    }
}
