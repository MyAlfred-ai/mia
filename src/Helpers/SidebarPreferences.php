<?php

declare(strict_types=1);

/**
 * Sections repliées du volet de gauche, PAR COMPTE (migration 109).
 *
 * SOURCE UNIQUE de l'état des replis : sections (Espaces, Épinglées,
 * Historique, carte du compte), périodes de l'historique, défauts, forme
 * gardée en base. Le rendu et les gestes vivent dans sidebar-sections.js ;
 * l'état est posé sur <html> avant le premier rendu par
 * sidebar-sections-boot.php (requis par head-meta.php).
 *
 * L'état suit le COMPTE, pas le navigateur : il était gardé en localStorage,
 * donc perdu d'un appareil à l'autre, après un nettoyage du navigateur ou dans
 * l'application mobile. Il vit désormais dans `users.ui_sidebar` — User fait
 * des SELECT *, la colonne remonte dans la session sans une requête de plus.
 *
 * Seuls les CHOIX de la personne sont gardés : une section jamais touchée
 * suit `DEFAULTS`, et suivra donc un défaut qui changerait un jour (même
 * principe que le thème « Par défaut » de DisplayPreferences).
 */
final class SidebarPreferences
{
    /** Sections repliables, dans l'ordre du volet. */
    public const SECTIONS = ['spaces', 'pinned', 'history', 'account'];

    /**
     * Section dépliée (true) ou repliée (false) tant que la personne n'y a pas
     * touché. Tout est déplié par défaut (2026-11-14, décision d'Axel, après
     * un essai Espaces et Épinglées repliés) ; chacun replie ce qu'il veut, et
     * son choix suit son compte.
     */
    public const DEFAULTS = [
        'spaces'  => true,
        'pinned'  => true,
        'history' => true,
        'account' => true,
    ];

    /**
     * Périodes de l'historique : today, yesterday, 7d, 30d, et un mois
     * `mAAAA-M` (mois de 0 à 11, comme getMonth() du navigateur).
     */
    public const GROUP_PATTERN = '/^(?:today|yesterday|7d|30d|m\d{4}-(?:1[01]|\d))$/';

    /** Périodes retenues au plus (le navigateur oublie déjà les mois disparus). */
    public const MAX_GROUPS = 40;

    /**
     * État normalisé : ne garde que des sections connues et des périodes de
     * forme valide, chacune à true (dépliée) ou false (repliée). Accepte un
     * tableau ou sa forme JSON ; tout le reste rend un état vide.
     *
     * @return array{groups: array<string, bool>}&array<string, mixed>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }
        $state = [];
        if (!is_array($raw)) {
            return $state + ['groups' => []];
        }
        foreach (self::SECTIONS as $k) {
            if (array_key_exists($k, $raw) && is_bool($raw[$k])) {
                $state[$k] = $raw[$k];
            }
        }
        $groups = [];
        if (isset($raw['groups']) && is_array($raw['groups'])) {
            foreach ($raw['groups'] as $key => $open) {
                if (count($groups) >= self::MAX_GROUPS) {
                    break;
                }
                if (is_string($key) && is_bool($open) && preg_match(self::GROUP_PATTERN, $key)) {
                    $groups[$key] = $open;
                }
            }
        }
        $state['groups'] = $groups;
        return $state;
    }

    /** État gardé pour ce compte (vide = rien de choisi). */
    public static function stateOf(?array $user): array
    {
        return self::normalize($user['ui_sidebar'] ?? null);
    }

    /** La personne a-t-elle déjà un état enregistré sur son compte ? */
    public static function isSaved(?array $user): bool
    {
        return is_string($user['ui_sidebar'] ?? null) && $user['ui_sidebar'] !== '';
    }

    /** Section dépliée ? Le choix de la personne, sinon le défaut. */
    public static function isOpen(array $state, string $section): bool
    {
        if (array_key_exists($section, $state) && is_bool($state[$section])) {
            return $state[$section];
        }
        return self::DEFAULTS[$section] ?? true;
    }

    /**
     * Ce que lit le navigateur (window.MIA_SIDEBAR) : l'état gardé, les
     * défauts, et si un état existe déjà sur le compte — sans quoi le
     * navigateur reprend une fois celui qu'il gardait en localStorage.
     */
    public static function clientConfig(?array $user): array
    {
        return [
            'state'    => self::forJson(self::stateOf($user)),
            'defaults' => self::DEFAULTS,
            'saved'    => self::isSaved($user),
        ];
    }

    /**
     * Forme à encoder : les périodes restent un OBJET même vides — un `[]`
     * deviendrait un tableau côté navigateur, dont JSON.stringify perd les
     * clés posées ensuite (« today », « 7d »).
     */
    public static function forJson(array $state): array
    {
        $state['groups'] = (object)($state['groups'] ?? []);
        return $state;
    }

    /** Enregistre l'état normalisé sur le compte ; rend ce qui a été gardé. */
    public static function save(int $userId, mixed $raw): array
    {
        $state = self::normalize($raw);
        Database::getInstance()
            ->prepare('UPDATE users SET ui_sidebar = ? WHERE id = ?')
            ->execute([json_encode(self::forJson($state), JSON_UNESCAPED_SLASHES), $userId]);
        return $state;
    }
}
