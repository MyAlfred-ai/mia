<?php

declare(strict_types=1);

/**
 * Incidents de COMPTE chez un fournisseur d'IA — crédit épuisé, clé refusée —
 * constatés au fil des demandes des utilisateurs, pour que l'administration
 * le sache sans attendre qu'on vienne le lui dire.
 *
 * Le problème résolu : quand le crédit OpenAI de l'organisation tombait à
 * zéro, chaque utilisateur lisait « Crédit épuisé chez OpenAI » dans sa
 * conversation… et l'administrateur ne l'apprenait que par le premier qui
 * prenait la peine de le lui écrire. Pire, avec un LLM de secours réglé, la
 * bascule masquait la panne : tout le monde répondait, au prix d'un autre
 * modèle, et personne ne rechargeait le compte. Ici, chaque refus de ce genre
 * est consigné ; « Points à régler » (AdminAttention) en fait une pastille sur
 * Modèles IA › Clés API, et le tableau de bord une alerte.
 *
 * Règles :
 *   - seuls les incidents de COMPTE, qui valent pour tous les modèles du
 *     fournisseur et que seul un administrateur règle (`KINDS`) : une limite
 *     de débit ou une surcharge passent d'elles-mêmes ;
 *   - le constat S'ÉTEINT de lui-même : à la première opération facturée de
 *     ce fournisseur (UsageMeter — la preuve que le compte répond de nouveau),
 *     à l'enregistrement de sa clé, ou faute de récidive après `TTL_SEC` ;
 *   - une seule clé `settings` (JSON, une entrée par fournisseur) : rien à
 *     migrer, lu sans requête par la page d'administration (cache de
 *     Settings), écrit seulement quand quelque chose change ;
 *   - ne lève JAMAIS : un constat qui échoue ne doit pas faire tomber la
 *     réponse d'erreur qu'il accompagne ;
 *   - les formes (`merge`, `without`, `prune`, `decode`) sont PURES.
 */
final class ProviderIncident
{
    /** Clé `settings` : {"openai": {"kind": "quota", "first": …, "last": …, "count": …}, …} */
    public const SETTING = 'llm_provider_incidents';

    /** Genres consignés : ceux d'un COMPTE, que seul un administrateur règle. */
    public const KINDS = ['quota', 'auth'];

    /** Sans récidive depuis trois jours, le constat s'éteint (compte rechargé sans usage depuis). */
    public const TTL_SEC = 3 * 86400;

    /**
     * Fournisseurs des messages d'erreur → slug du catalogue. Les adaptateurs
     * signent leurs erreurs d'un LIBELLÉ (« Erreur Kimi … », « LLM local ») ;
     * la page des clés, la facturation et ce constat parlent en slug.
     */
    private const SLUGS = [
        'openai'          => 'openai',
        'anthropic'       => 'anthropic',
        'mistral'         => 'mistral',
        'gemini'          => 'gemini',
        'google'          => 'gemini',
        'imagen'          => 'gemini',
        'perplexity'      => 'perplexity',
        'deepseek'        => 'deepseek',
        'moonshot'        => 'moonshot',
        'kimi'            => 'moonshot',
        'kimi (moonshot)' => 'moonshot',
        'zai'             => 'zai',
        'z.ai'            => 'zai',
        'glm'             => 'zai',
        'glm (z.ai)'      => 'zai',
    ];

    /** Magasin de substitution (tests) : null = la table `settings`. */
    private static ?array $memory = null;

    // ================================================================ Écriture

    /**
     * Consigne un refus présenté à un utilisateur. `$desc` = descripteur ou
     * charge utile de LlmErrorPresenter (`kind`, `provider`). Sans effet pour
     * un autre genre ou un fournisseur inconnu. Ne lève jamais.
     */
    public static function note(array $desc, ?int $now = null): void
    {
        try {
            $kind     = (string)($desc['kind'] ?? '');
            $provider = self::slug(isset($desc['provider']) ? (string)$desc['provider'] : null);
            if ($provider === null || !in_array($kind, self::KINDS, true)) {
                return;
            }
            $now   = $now ?? time();
            $avant = self::read();
            $apres = self::merge(self::prune($avant, $now), $provider, $kind, $now);
            self::write($apres);
        } catch (\Throwable $e) {
            error_log('[Incident fournisseur] consignation impossible : ' . $e->getMessage());
        }
    }

    /**
     * Le fournisseur répond de nouveau (opération facturée, clé enregistrée,
     * test réussi) : son constat s'éteint. N'écrit que s'il y en avait un.
     * Ne lève jamais.
     */
    public static function resolve(?string $provider): void
    {
        try {
            $slug = self::slug($provider);
            if ($slug === null) {
                return;
            }
            $avant = self::read();
            if (!isset($avant[$slug])) {
                return;
            }
            self::write(self::without($avant, $slug));
        } catch (\Throwable $e) {
            error_log('[Incident fournisseur] levée impossible : ' . $e->getMessage());
        }
    }

    /**
     * Constats en cours, par fournisseur (les éteints faute de récidive sont
     * écartés). Lecture locale, sans requête au-delà du cache de Settings.
     *
     * @return array<string, array{kind:string, first:int, last:int, count:int}>
     */
    public static function active(?int $now = null): array
    {
        return self::prune(self::read(), $now ?? time());
    }

    // ================================================================ Formes pures

    /**
     * Ajoute un refus à l'état. Un changement de genre (clé refusée après un
     * crédit épuisé) repart de zéro : c'est un autre remède.
     *
     * @param array<string, array> $state
     * @return array<string, array{kind:string, first:int, last:int, count:int}>
     */
    public static function merge(array $state, string $provider, string $kind, int $now): array
    {
        $prev = $state[$provider] ?? null;
        if (is_array($prev) && ($prev['kind'] ?? '') === $kind) {
            $state[$provider] = [
                'kind'  => $kind,
                'first' => (int)($prev['first'] ?? $now),
                'last'  => $now,
                'count' => max(0, (int)($prev['count'] ?? 0)) + 1,
            ];
        } else {
            $state[$provider] = ['kind' => $kind, 'first' => $now, 'last' => $now, 'count' => 1];
        }
        ksort($state);
        return $state;
    }

    /** @param array<string, array> $state */
    public static function without(array $state, string $provider): array
    {
        unset($state[$provider]);
        return $state;
    }

    /**
     * Retire les entrées illisibles et celles sans récidive depuis TTL_SEC.
     *
     * @param array<mixed> $state
     * @return array<string, array{kind:string, first:int, last:int, count:int}>
     */
    public static function prune(array $state, int $now): array
    {
        $out = [];
        foreach ($state as $provider => $e) {
            if (!is_string($provider) || !is_array($e) || !in_array($e['kind'] ?? null, self::KINDS, true)) {
                continue;
            }
            $last = (int)($e['last'] ?? 0);
            if ($last <= 0 || $now - $last > self::TTL_SEC) {
                continue;
            }
            $out[$provider] = [
                'kind'  => (string)$e['kind'],
                'first' => min((int)($e['first'] ?? $last), $last),
                'last'  => $last,
                'count' => max(1, (int)($e['count'] ?? 1)),
            ];
        }
        ksort($out);
        return $out;
    }

    /** @return array<mixed> */
    public static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Slug du catalogue d'après un slug ou un libellé de message d'erreur
     * (« OpenAI », « Kimi (Moonshot) », « LLM local »). Null si inconnu : le
     * modèle local n'a pas de compte à recharger.
     */
    public static function slug(?string $provider): ?string
    {
        $p = strtolower(trim((string)$provider));
        return $p === '' ? null : (self::SLUGS[$p] ?? null);
    }

    // ================================================================ Magasin

    /** Magasin en mémoire (tests unitaires : aucune écriture en base). */
    public static function useMemoryStore(array $state = []): void
    {
        self::$memory = $state;
    }

    /** Rend la table `settings` comme magasin (comportement de l'application). */
    public static function useSettingsStore(): void
    {
        self::$memory = null;
    }

    /** @return array<mixed> */
    private static function read(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }
        return self::decode(Settings::get(self::SETTING, ''));
    }

    private static function write(array $state): void
    {
        if (self::$memory !== null) {
            self::$memory = $state;
            return;
        }
        Settings::set(self::SETTING, $state === [] ? '' : (string)json_encode($state, JSON_UNESCAPED_UNICODE));
    }
}
