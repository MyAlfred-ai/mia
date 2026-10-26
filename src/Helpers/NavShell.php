<?php
declare(strict_types=1);

/**
 * Volet de gauche des pages de l'ESPACE DE TRAVAIL — Assistants, Bases de
 * connaissances, Tâches programmées, Workflows, Mes créations
 * (NavRegistry::WORKSPACE).
 *
 * Ces pages gardent TOUJOURS le volet du chat (nouvelle conversation,
 * recherche, tuiles d'espace, historique) : elles font partie de l'espace où
 * l'on travaille, pas de l'administration ni du compte. Changer de volet en
 * passant d'une conversation à la liste de ses assistants faisait croire à un
 * autre outil.
 *
 * Avant les menus en trois espaces, ce volet ne restait que si l'on venait du
 * chat (drapeau de session) : ces pages vivaient AUSSI dans les menus de
 * l'administration et des paramètres. Elles n'y sont plus — une page, une
 * entrée — donc plus rien à retenir entre deux requêtes.
 */
final class NavShell
{
    /** Valeurs de `$page` des pages qui gardent le volet du chat. */
    public const PAGES = ['profiles', 'profiles-new', 'knowledge-bases', 'schedules', 'workflows', 'creations'];

    /** Tuile d'espace allumée pour une page (clé de NavRegistry::WORKSPACE). */
    public const SHORTCUT_OF = [
        'profiles'        => 'profiles',
        'profiles-new'    => 'profiles',
        'knowledge-bases' => 'kb',
        'schedules'       => 'schedules',
        'workflows'       => 'workflows',
        'creations'       => 'creations',
    ];

    /** Cette page garde-t-elle le volet du chat ? (pure) */
    public static function keepsChatShell(string $page): bool
    {
        return in_array($page, self::PAGES, true);
    }

    /** Décide pour la page rendue par l'ossature commune (admin-nav.php). */
    public static function resolve(string $page): bool
    {
        return self::keepsChatShell($page);
    }
}
