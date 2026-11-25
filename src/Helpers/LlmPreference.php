<?php

declare(strict_types=1);

/**
 * Modèle préféré de chaque personne : celui qui ouvre ses NOUVELLES
 * conversations sans assistant (migration 107, colonne users.preferred_llm).
 *
 * SOURCE UNIQUE de « quel modèle par défaut pour cette personne ? ». Deux
 * surfaces l'écrivent — l'étoile du sélecteur de modèle (le geste rapide, là
 * où l'on choisit un modèle) et Réglages → Préférences IA (le réglage qu'on
 * cherche) —, et quatre lectures en dépendent : la page d'accueil du chat, la
 * création d'une conversation, l'envoi et la régénération d'un message dont la
 * conversation n'a pas encore de modèle.
 *
 * Ce que le réglage NE touche PAS, à dessein :
 *   - une conversation existante : elle garde le modèle qu'elle porte (on
 *     change de modèle pour une conversation depuis le sélecteur) ;
 *   - un assistant : son modèle est imposé, ou suit le défaut de
 *     l'organisation (« Défaut application ») — choix de qui l'a configuré ;
 *   - les tâches programmées et les workflows, qui ont leur propre choix.
 *
 * '' = « celui de votre organisation » (LLMFactory::appDefault), jamais une
 * recopie du défaut du jour : qui n'a rien choisi suit les changements de
 * l'administrateur. Un choix qui n'est plus proposé (modèle retiré des groupes,
 * désactivé, arrêté par son éditeur) n'est pas effacé : il est IGNORÉ à la
 * lecture (resolve) et revient de lui-même s'il est rendu.
 */
final class LlmPreference
{
    public const COLUMN  = 'preferred_llm';
    public const MAX_LEN = 100;

    // ------------------------------------------------------------------
    // Périmètre
    // ------------------------------------------------------------------

    /**
     * Modèles proposés dans les limites des groupes actifs d'une personne :
     * la liste blanche de ses groupes appliquée au catalogue activé. Règle
     * du sélecteur du chat (ChatController::filterLlmsForUserScope y délègue).
     *
     * @param array<string,string> $llms     slug => libellé
     * @param int[]                $groupIds groupes ACTIFS de la personne
     * @return array<string,string>
     */
    public static function withinGroups(array $llms, array $groupIds): array
    {
        $allowed = UserGroup::allowedLlmKeysForUser($groupIds);
        return $allowed === null ? $llms : array_intersect_key($llms, array_flip($allowed));
    }

    /**
     * Modèles qu'une personne peut choisir, et donc préférer.
     *
     * @return array<string,string> slug => libellé
     */
    public static function choicesFor(array $user): array
    {
        $scope = UserGroup::membershipScopeForUser((int)($user['id'] ?? 0));
        return self::withinGroups(LLMFactory::available(), (array)($scope['active_group_ids'] ?? []));
    }

    // ------------------------------------------------------------------
    // Formes pures
    // ------------------------------------------------------------------

    /** Choix enregistré d'une personne ('' = celui de l'organisation). */
    public static function of(?array $user): string
    {
        return self::clean($user[self::COLUMN] ?? '');
    }

    /** Slug lisible ou '' : une ligne, borné, sans caractère de contrôle ni espace. */
    public static function clean(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $v = trim((string)$value);
        if ($v === '' || mb_strlen($v) > self::MAX_LEN || preg_match('/[\x00-\x20\x7f]/', $v)) {
            return '';
        }
        return $v;
    }

    /**
     * Le choix, s'il est encore proposé à la personne ; null sinon (aucun
     * choix, ou modèle retiré). Pure.
     *
     * @param array<string,string> $available modèles proposés (choicesFor)
     */
    public static function resolve(string $preferred, array $available): ?string
    {
        return $preferred !== '' && isset($available[$preferred]) ? $preferred : null;
    }

    /** Défaut de l'organisation dans ce périmètre (même règle que le chat). */
    public static function organizationDefault(array $available): string
    {
        return LLMFactory::appDefault($available) ?? 'openai';
    }

    /**
     * LE modèle qui ouvre une nouvelle conversation de cette personne : son
     * choix s'il est encore proposé, sinon celui de l'organisation.
     */
    public static function defaultFor(?array $user, array $available): string
    {
        return self::resolve(self::of($user), $available) ?? self::organizationDefault($available);
    }

    /**
     * Valide un choix reçu : '' (revenir à celui de l'organisation) ou un
     * modèle proposé. null = refusé. `$current` = choix déjà enregistré : le
     * renvoyer tel quel est accepté même s'il n'est plus proposé — le
     * formulaire de Préférences IA est posté en entier, et changer de style
     * ne doit ni échouer ni effacer ce choix en attendant le modèle.
     */
    public static function normalize(mixed $value, array $available, string $current = ''): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }
        $v = self::clean($raw);
        if ($v === '') {
            return null;
        }
        if (isset($available[$v]) || ($current !== '' && $v === $current)) {
            return $v;
        }
        return null;
    }

    /**
     * Ce que les écrans montrent. Pure (hors lecture du défaut de
     * l'organisation, un réglage).
     *
     * @param array<string,string> $available
     * @param string|null          $organization défaut de l'organisation déjà connu (tests)
     * @return array{preferred:string, mine:string, effective:string, organization:string, unavailable:bool, choosable:bool}
     */
    public static function state(?array $user, array $available, ?string $organization = null): array
    {
        $preferred = self::of($user);
        $mine      = self::resolve($preferred, $available);
        $org       = $organization ?? self::organizationDefault($available);
        return [
            'preferred'    => $preferred,           // ce que la personne a choisi
            'mine'         => $mine ?? '',          // ce choix, s'il s'applique
            'effective'    => $mine ?? $org,        // ce qui ouvre ses nouvelles conversations
            'organization' => $org,
            'unavailable'  => $preferred !== '' && $mine === null,
            'choosable'    => count($available) > 1,
        ];
    }

    /**
     * Libellé d'un modèle : celui de la liste proposée, sinon celui du
     * catalogue (modèle retiré : on sait encore le nommer).
     */
    public static function label(string $slug, array $available): string
    {
        if (isset($available[$slug])) {
            return (string)$available[$slug];
        }
        if (str_contains($slug, '::')) {
            [$provider, $model] = explode('::', $slug, 2);
            return LlmCatalog::label($provider, $model);
        }
        return $slug;
    }

    /**
     * Charge du navigateur (window.LLM_PREFERENCE) : l'état et les textes
     * TRADUITS ici — le script n'a aucun libellé, il remplace {model}.
     */
    public static function clientConfig(array $state): array
    {
        return [
            'mine'         => $state['mine'],
            'effective'    => $state['effective'],
            'organization' => $state['organization'],
            'texts'        => [
                'set'   => t('Faire de {model} votre modèle par défaut'),
                'mine'  => t('{model} est votre modèle par défaut. Touchez l\'étoile pour revenir à celui de votre organisation.'),
                'org'   => t('{model} est le modèle par défaut de votre organisation. Touchez l\'étoile pour le garder comme le vôtre.'),
                'saved' => t('{model} ouvrira désormais vos nouvelles conversations.'),
                'reset' => t('Vos nouvelles conversations s\'ouvriront avec le modèle de votre organisation : {model}.'),
                'error' => t('Le modèle par défaut n\'a pas pu être enregistré. Réessayez.'),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    public static function save(int $userId, string $llm): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET preferred_llm = ? WHERE id = ?')
            ->execute([self::clean($llm), $userId]);
    }
}
