<?php

declare(strict_types=1);

/**
 * Barres d'onglets ENTRE routes de l'administration — source unique.
 *
 * Une entrée du menu peut réunir plusieurs pages sœurs qui gardent chacune leur
 * route (liens, favoris, redirections, tests) : « Maintenance → Diagnostic »
 * (contrôles, journaux, moteur vectoriel, agent) et « Maintenance → Données »
 * (sauvegarde, élagage). Le menu s'allume sur toutes les pages du groupe
 * (`pages()`), le partial `templates/partials/admin-tabs.php` affiche les
 * onglets (`onglets()`). Ajouter une page à un groupe = une ligne ici.
 *
 * Les libellés sont des clés de traduction (le français est le code) : une
 * constante ne peut pas appeler t(), ils se traduisent donc à l'ACCESSEUR
 * (libelleText(), ongletText()), lu par les onglets ET la palette ⌘K.
 * `onglets()` et `libelle()` rendent la source française, pour comparer.
 */
final class AdminTabs
{
    public const DIAGNOSTIC = 'diagnostic';
    public const DONNEES    = 'donnees';

    /** groupe → [libellé du groupe, [$page => [href, libellé de l'onglet]]] */
    private const GROUPES = [
        self::DIAGNOSTIC => ['Diagnostic', [
            'diagnostics'            => ['/admin/diagnostics', 'Contrôles'],
            'logs'                   => ['/admin/logs', 'Journaux'],
            'knowledge-bases-vector' => ['/admin/knowledge-bases/vector', 'Moteur vectoriel'],
            'agent'                  => ['/admin/agent', 'Agent'],
        ]],
        self::DONNEES => ['Données', [
            'backup'  => ['/admin/backup', 'Sauvegarde'],
            'pruning' => ['/admin/pruning', 'Élagage'],
        ]],
    ];

    /** @return list<string> valeurs de $page du groupe */
    public static function pages(string $groupe): array
    {
        return array_keys(self::GROUPES[$groupe][1] ?? []);
    }

    /** @return array<string, array{0:string, 1:string}> $page => [href, libellé] */
    public static function onglets(string $groupe): array
    {
        return self::GROUPES[$groupe][1] ?? [];
    }

    public static function libelle(string $groupe): string
    {
        return self::GROUPES[$groupe][0] ?? '';
    }

    /** Libellé TRADUIT d'un groupe (« Données » → « Data »), '' s'il est inconnu. */
    public static function libelleText(string $groupe): string
    {
        $libelle = self::libelle($groupe);

        return $libelle !== '' ? t($libelle) : '';
    }

    /** Libellé TRADUIT de l'onglet d'une page (« Élagage » → « Pruning »), '' hors des groupes. */
    public static function ongletText(string $page): string
    {
        $groupe = self::groupeDe($page);

        return $groupe !== null ? t(self::GROUPES[$groupe][1][$page][1]) : '';
    }

    /** Adresse de l'onglet d'une page, null hors des groupes. */
    public static function href(string $page): ?string
    {
        $groupe = self::groupeDe($page);

        return $groupe !== null ? self::GROUPES[$groupe][1][$page][0] : null;
    }

    /** Groupe d'une page, ou null. */
    public static function groupeDe(string $page): ?string
    {
        foreach (self::GROUPES as $groupe => [, $onglets]) {
            if (isset($onglets[$page])) {
                return $groupe;
            }
        }
        return null;
    }
}
