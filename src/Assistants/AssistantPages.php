<?php

declare(strict_types=1);

/**
 * Espaces → Assistants (piste C, « recherche d'abord ») : ce que la LISTE
 * montre d'un assistant — qui le voit, sa couleur, son initiale, la ligne
 * qui le résume. Pur, sans base de données : la liste ne recalcule rien de
 * ce que dit déjà la fiche.
 *
 * Trois visibilités, lues sur la ligne `profiles` :
 *   - global   (globe)  : tout le monde, sans filtre de groupe (is_global) ;
 *   - partagé  (groupe) : assistant d'organisation, proposé aux groupes qui
 *                         l'autorisent (Personnes → Groupes) ;
 *   - personnel(cadenas): son créateur seul (is_personal).
 */
final class AssistantPages
{
    public const VIS_GLOBAL   = 'global';
    public const VIS_SHARED   = 'shared';
    public const VIS_PERSONAL = 'personal';

    /** Couleur d'un assistant sans couleur valide. */
    public const DEFAULT_COLOR = '#64748b';

    /** Visibilité d'un assistant (personnel l'emporte : il ne peut pas être global pour autrui). */
    public static function visibility(array $profile): string
    {
        if (!empty($profile['is_personal'])) {
            return self::VIS_PERSONAL;
        }
        return !empty($profile['is_global']) ? self::VIS_GLOBAL : self::VIS_SHARED;
    }

    /**
     * Libellé et phrase d'une visibilité, traduits.
     *
     * @return array{label: string, hint: string}
     */
    public static function visibilityText(string $vis): array
    {
        return match ($vis) {
            self::VIS_GLOBAL   => ['label' => t('Global'), 'hint' => t('Tout le monde, sans filtre de groupe.')],
            self::VIS_PERSONAL => ['label' => t('Personnel'), 'hint' => t('Son créateur seul.')],
            default            => ['label' => t('Partagé'), 'hint' => t('Les groupes qui l’autorisent (Personnes → Groupes).')],
        };
    }

    /** Pictogramme d'une visibilité (globe, groupe, cadenas), prêt à poser. */
    public static function visibilityIcon(string $vis): string
    {
        return KbPages::icon(match ($vis) {
            self::VIS_GLOBAL   => 'globe',
            self::VIS_PERSONAL => 'lock',
            default            => 'group',
        }, '');
    }

    /** Couleur d'un assistant, seulement si c'est un « #rrggbb » : elle part dans un style. */
    public static function color(array $profile): string
    {
        $c = trim((string)($profile['color'] ?? ''));
        return preg_match('/^#[0-9a-f]{6}$/i', $c) === 1 ? strtolower($c) : self::DEFAULT_COLOR;
    }

    /** Initiale du nom (pastille sans avatar). */
    public static function initial(string $name): string
    {
        $name = trim($name);
        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
    }

    /**
     * Les gestes offerts sur un assistant — la liste et sa page disent les
     * mêmes. « Discuter » : actif ET utilisable par la personne (un
     * administrateur n'ouvre pas le chat d'un assistant personnel d'un autre).
     * Partage (Gérer + Modifier) : jamais sur l'assistant global, ni sur un
     * assistant d'organisation sans créateur, qui deviendrait orphelin s'il
     * redevenait personnel. Groupes : un assistant partagé. Pure.
     *
     * @param array{manage?: bool, edit?: bool, delete?: bool} $rights droits de la personne
     * @return array{use: bool, toggle: bool, groups: bool, delete: bool, edit: bool}
     */
    public static function actions(array $profile, array $rights, int $viewerId): array
    {
        $vis     = self::visibility($profile);
        $ownerId = (int)($profile['created_by'] ?? 0);
        $active  = !empty($profile['is_active']);
        $manage  = !empty($rights['manage']);
        return [
            'use'    => $active && ($vis !== self::VIS_PERSONAL || $ownerId === $viewerId),
            'toggle' => $manage && !empty($rights['edit']) && $vis !== self::VIS_GLOBAL
                && ($vis === self::VIS_PERSONAL || $ownerId > 0),
            'groups' => $manage && $vis === self::VIS_SHARED,
            'delete' => !empty($rights['delete']),
            'edit'   => !empty($rights['edit']),
        ];
    }

    /**
     * La température telle que la page la dit : sa valeur et son effet, ou
     * qui la règle. Les crans sont ceux du curseur de la fiche (0 précis,
     * 1 équilibré, 2 créatif). Pure.
     *
     * @param mixed $temperature valeur enregistrée (null ou '' : le modèle décide)
     */
    public static function temperatureText(mixed $temperature, bool $supported): string
    {
        if (!$supported) {
            return t('Ce modèle règle lui-même sa température.');
        }
        if ($temperature === null || $temperature === '') {
            return t('Température : celle du modèle');
        }
        $t = (float)$temperature;
        $mood = $t < 0.5 ? t('précise') : ($t < 1.3 ? t('équilibrée') : t('créative'));
        $value = rtrim(rtrim(number_format($t, 1, '.', ''), '0'), '.');
        if ($value === '') {
            $value = '0';
        }
        if (I18n::locale() === 'fr') {
            $value = str_replace('.', ',', $value);
        }
        return t('Température {t} · {humeur}', ['t' => $value, 'humeur' => $mood]);
    }

    /** Lignes de la consigne montrées avant « Afficher toute la consigne ». */
    public const PROMPT_LINES = 12;

    /** La consigne est-elle assez longue pour être repliée sur la page ? Pure. */
    public static function promptIsLong(string $prompt): bool
    {
        $prompt = trim($prompt);
        return substr_count($prompt, "\n") + 1 > self::PROMPT_LINES || mb_strlen($prompt) > 900;
    }

    /**
     * La ligne sous le nom : le modèle, puis ce qu'il sait et ce qu'il fait —
     * « Claude Opus 5 · 2 bases · 3 docs · 1 outil ». Les comptes nuls ne
     * s'écrivent pas.
     */
    public static function meta(string $modelLabel, int $kbCount, int $docCount, int $toolCount): string
    {
        $parts = [$modelLabel];
        if ($kbCount > 0) {
            $parts[] = I18n::plural($kbCount, '{n} base', '{n} bases');
        }
        if ($docCount > 0) {
            $parts[] = I18n::plural($docCount, '{n} doc', '{n} docs');
        }
        if ($toolCount > 0) {
            $parts[] = I18n::plural($toolCount, '{n} outil', '{n} outils');
        }
        return implode(' · ', $parts);
    }
}
