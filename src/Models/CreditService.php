<?php

declare(strict_types=1);

/**
 * Conversion « coût réel d'un service IA » → crédits — SOURCE UNIQUE.
 *
 * Vocabulaire (le même que sur les écrans Crédits et Usages) :
 *   - coût réel (`cost_eur`)   : ce que le fournisseur facture, en € HT,
 *                                 AVANT toute marge — la somme des tarifs du
 *                                 catalogue × quantités. C'est ce qui est
 *                                 journalisé ligne à ligne dans le grand livre.
 *   - crédits (`total_credits`) : l'unité décomptée aux utilisateurs =
 *                                 (coût réel pondéré × coefficient global +
 *                                 frais fixes) / valeur du crédit, arrondi.
 *   - valorisation              : crédits × valeur du crédit (`creditsToEur`),
 *                                 dérivée, jamais stockée.
 *
 * Règles de calcul d'une OPÉRATION (un appel LLM, une image, une recherche…) :
 *   1. chaque composante (tokens input, output, cache, raisonnement, ou une
 *      quantité d'unités) trouve son tarif : service configuré dans
 *      /admin/credits (`catalog_key`), sinon tarif CURÉ du catalogue
 *      (`config/llm_catalog.php`, `config/embeddings_catalog.php`) converti
 *      USD → EUR au taux configuré ;
 *   2. les composantes sont SOMMÉES en coût réel, puis en coût pondéré (avec
 *      le coefficient spécifique de chaque service) ;
 *   3. marge globale et frais fixes s'appliquent UNE fois par opération,
 *      l'arrondi et le minimum aussi. (Avant : par composante — un appel à
 *      deux directions payait deux fois les frais fixes et deux arrondis.)
 *   4. une composante consommée SANS tarif trouvable rend l'opération
 *      « non tarifée » (`priced = false`) : elle est journalisée au minimum
 *      de crédits configuré et signalée sur la page Usages, au lieu de
 *      disparaître dans un coût à zéro.
 */
class CreditService
{
    /** Ratio par défaut input cached / input full price (10% — convention OpenAI/Anthropic). */
    public const DEFAULT_CACHED_RATIO = 0.10;

    /** Ratio par défaut input cache write / input full price (1.25 — convention Anthropic). */
    public const DEFAULT_CACHE_WRITE_RATIO = 1.25;

    /** Taux 1 EUR → USD par défaut (le même que l'écran Crédits). */
    public const DEFAULT_FX_RATE_EUR_USD = 1.08;

    /** Fournisseur local (Ollama, LM Studio) : aucun coût fournisseur par construction. */
    public const FREE_PROVIDERS = ['local'];

    public static function globalConfig(): array
    {
        $raw = Settings::get('credits_config_json', '');
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        $config = is_array($decoded) ? $decoded : [];
        $global = is_array($config['global'] ?? null) ? $config['global'] : [];

        return [
            'credit_value_eur' => self::positiveFloat($global['credit_value_eur'] ?? 0.001, 0.001, 0.000001),
            'rounding_mode' => self::roundingMode((string)($global['rounding_mode'] ?? 'ceil')),
            'margin_multiplier' => self::positiveFloat($global['margin_multiplier'] ?? 1.30, 1.30, 0.0001),
            'fixed_fee_eur' => self::positiveFloat($global['fixed_fee_eur'] ?? 0.0, 0.0, 0.0),
            'min_credits_per_operation' => max(0, (int)($global['min_credits_per_operation'] ?? 1)),
            'fx_rate_eur_usd' => self::positiveFloat($global['fx_rate_eur_usd'] ?? self::DEFAULT_FX_RATE_EUR_USD, self::DEFAULT_FX_RATE_EUR_USD, 0.0001),
        ];
    }

    /**
     * Crédits par euro de coût RÉEL : coefficient global ÷ valeur du crédit
     * (1,30 ÷ 0,001 € = 1 300 par défaut). Approximatif par construction : il
     * ignore frais fixes, arrondi, minimum par opération et coefficients par
     * service — le dire partout où il est affiché. Pure si `$global` est fourni.
     */
    public static function creditsPerRealEur(?array $global = null): float
    {
        $global = $global ?? self::globalConfig();
        $value  = max(0.000001, (float)($global['credit_value_eur'] ?? 0.001));
        return (float)($global['margin_multiplier'] ?? 1.30) / $value;
    }

    /** Valeur € d'un crédit telle que configurée dans /admin/credits. */
    public static function creditValueEur(): float
    {
        return (float)self::globalConfig()['credit_value_eur'];
    }

    /**
     * Point de conversion UNIQUE crédits → euros (valeur actuelle du crédit).
     * Toute conversion affichée/exportée doit passer par ici — jamais de
     * multiplication inline dans les contrôleurs ou templates.
     */
    public static function creditsToEur(int|float $credits): float
    {
        return round($credits * self::creditValueEur(), 6);
    }

    /** Taux 1 EUR → USD configuré (écran Crédits), borné > 0. */
    public static function fxRateEurUsd(): float
    {
        return (float)self::globalConfig()['fx_rate_eur_usd'];
    }

    /** USD → EUR au taux configuré — même conversion que l'écran Crédits. */
    public static function usdToEur(float $usd): float
    {
        return round(max(0.0, $usd) / self::fxRateEurUsd(), 10);
    }

    /**
     * Crédits + coût réel d'un appel LLM, composante par composante (input
     * plein tarif, output, cache lu, cache écrit, raisonnement).
     *
     * @return array{
     *   total_credits:int, cost_eur:float, billed_eur:float, priced:bool,
     *   components:array<string, array{tokens:int, cost_eur:float, catalog_key:string}>
     * }
     */
    public static function computeLlmCredits(
        string $llmKey,
        int $tokensIn,
        int $tokensOut,
        int $tokensInCached = 0,
        int $tokensInCacheWrite = 0,
        int $tokensOutReasoning = 0,
        array $longContext = [],
        int $containers = 0
    ): array {
        $config = self::config();
        [$provider, $model] = self::splitLlmKey($llmKey);

        $tokensIn           = max(0, $tokensIn);
        $tokensOut          = max(0, $tokensOut);
        $tokensInCached     = max(0, $tokensInCached);
        $tokensInCacheWrite = max(0, $tokensInCacheWrite);
        $tokensOutReasoning = max(0, $tokensOutReasoning);

        // Part facturée au palier « contexte long » (cf. longContextPart) :
        // bornée aux totaux, et ignorée si le modèle n'a pas de palier.
        $rule = $longContext !== [] ? LlmCatalog::longContext($provider, $model) : null;
        $long = ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0];
        if ($rule !== null) {
            $long['input']             = min($tokensIn, max(0, (int)($longContext['input'] ?? 0)));
            $long['output']            = min($tokensOut, max(0, (int)($longContext['output'] ?? 0)));
            $long['input_cached']      = min($tokensInCached, $long['input'], max(0, (int)($longContext['input_cached'] ?? 0)));
            $long['input_cache_write'] = min($tokensInCacheWrite, $long['input'] - $long['input_cached'], max(0, (int)($longContext['input_cache_write'] ?? 0)));
            $long['output_reasoning']  = min($tokensOutReasoning, $long['output'], max(0, (int)($longContext['output_reasoning'] ?? 0)));
        }

        // Input plein tarif = total − cached − cache_write (les providers
        // comptent input_tokens « complet »). Le raisonnement est inclus dans
        // output_tokens (compté une fois par le provider).
        $normalIn       = $tokensIn - $long['input'];
        $normalCached   = $tokensInCached - $long['input_cached'];
        $normalWrite    = $tokensInCacheWrite - $long['input_cache_write'];
        $normalOut      = $tokensOut - $long['output'];
        $normalReason   = $tokensOutReasoning - $long['output_reasoning'];
        $parts = [
            'input'             => max(0, $normalIn - $normalCached - $normalWrite),
            'output'            => max(0, $normalOut - $normalReason),
            'input_cached'      => max(0, $normalCached),
            'input_cache_write' => max(0, $normalWrite),
            'output_reasoning'  => max(0, $normalReason),
        ];

        // Tarifs de référence : input plein et output. Les trois variantes
        // (cache lu, cache écrit, raisonnement) ont leur propre tarif si
        // l'administrateur ou le catalogue en déclare un, sinon elles
        // dérivent du tarif de référence par les ratios conventionnels
        // (cache lu 10 %, cache écrit 125 %, raisonnement = output).
        $inputTariff  = self::resolveLlmTariff($config, $provider, $model, $llmKey, 'input');
        $outputTariff = self::resolveLlmTariff($config, $provider, $model, $llmKey, 'output');
        $tariffs = [
            'input'             => $inputTariff,
            'output'            => $outputTariff,
            'input_cached'      => self::resolveLlmTariff($config, $provider, $model, $llmKey, 'input_cached')
                ?? self::ratioOf($inputTariff, self::DEFAULT_CACHED_RATIO, 'input_cached'),
            'input_cache_write' => self::resolveLlmTariff($config, $provider, $model, $llmKey, 'input_cache_write')
                ?? self::ratioOf($inputTariff, self::DEFAULT_CACHE_WRITE_RATIO, 'input_cache_write'),
            'output_reasoning'  => self::resolveLlmTariff($config, $provider, $model, $llmKey, 'output_reasoning')
                ?? self::ratioOf($outputTariff, 1.0, 'output_reasoning'),
        ];

        $components = [];
        foreach ($parts as $direction => $tokens) {
            if ($tokens <= 0) {
                continue;
            }
            $service = $tariffs[$direction];
            $components[$direction] = self::component($service, self::tokenQuantity($service, $tokens), $tokens);
        }

        // Palier « contexte long » : mêmes tarifs, multipliés par les ratios
        // du catalogue — un tarif enregistré dans l'écran Tarifs & crédits
        // suit donc lui aussi le palier.
        if ($rule !== null) {
            $longParts = [
                'input'             => max(0, $long['input'] - $long['input_cached'] - $long['input_cache_write']),
                'output'            => max(0, $long['output'] - $long['output_reasoning']),
                'input_cached'      => $long['input_cached'],
                'input_cache_write' => $long['input_cache_write'],
                'output_reasoning'  => $long['output_reasoning'],
            ];
            foreach ($longParts as $direction => $tokens) {
                if ($tokens <= 0) {
                    continue;
                }
                $ratio   = str_starts_with($direction, 'input') ? $rule['input_ratio'] : $rule['output_ratio'];
                $service = self::ratioOf($tariffs[$direction], $ratio, $direction . '_long');
                $components[$direction . '_long'] = self::component($service, self::tokenQuantity($service, $tokens), $tokens);
            }
        }

        // Sessions de conteneur d'exécution de code ouvertes pendant l'appel
        // (Code Interpreter d'OpenAI, « Version soignée » d'une présentation
        // ou d'un classeur) : facturées à la session, en plus des jetons.
        $containers = max(0, $containers);
        if ($containers > 0) {
            $service = self::findByCatalogKey($config['services'], 'container:' . $provider, true)
                ?? self::findByCatalogKey($config['services'], 'container:' . $provider, false);
            if (!self::hasPrice($service)) {
                $usd = class_exists('LlmCatalog') ? (LlmCatalog::provider($provider)['container_pricing_usd_per_session'] ?? null) : null;
                $service = is_numeric($usd)
                    ? self::virtualService('catalog:container:' . $provider, 'request', self::usdToEur((float)$usd))
                    : $service;
            }
            $components['container'] = self::component($service, (float)$containers, $containers);
        }

        return self::settle($components, $config['global'], $tokensIn + $tokensOut + $containers > 0);
    }

    /**
     * Crédits d'une ligne SECONDAIRE d'une même opération — recherches d'une
     * réponse, autre modèle facturé pendant un relais. Chaque ligne garde son
     * coût réel ; mais les frais fixes, l'arrondi et le minimum ne valent
     * qu'UNE fois pour l'opération : la ligne reçoit les crédits de
     * l'opération entière moins ceux déjà comptés. Réglée seule, une
     * recherche ajoutait un arrondi et un minimum de plus à chaque réponse.
     * Met à jour $op (ce que les lignes déjà écrites ont réglé).
     *
     * @param array{billed:float, credits:int} $op
     * @param array{total_credits:int, billed_eur:float} $line résultat d'un compute*Credits
     */
    public static function additionalCredits(array &$op, array $line): int
    {
        $lineCredits = max(0, (int)($line['total_credits'] ?? 0));
        $lineBilled  = max(0.0, (float)($line['billed_eur'] ?? 0.0));
        if ($op['credits'] <= 0 && $op['billed'] <= 0.0) {
            // Première ligne de l'opération : réglée comme d'habitude.
            $op = ['billed' => $lineBilled, 'credits' => $lineCredits];
            return $lineCredits;
        }
        if ($lineCredits <= 0 && $lineBilled <= 0.0) {
            return 0;
        }
        $global = self::config()['global'];
        $fee    = max(0.0, (float)($global['fixed_fee_eur'] ?? 0.0));
        // Les frais fixes de l'opération sont déjà dans $op['billed'].
        $billed  = $op['billed'] + max(0.0, $lineBilled - $fee);
        $credits = max(
            self::roundCredits($billed / max(0.000001, (float)($global['credit_value_eur'] ?? 0.001)), (string)($global['rounding_mode'] ?? 'ceil')),
            max(0, (int)($global['min_credits_per_operation'] ?? 0))
        );
        $share = max(0, $credits - $op['credits']);
        $op = ['billed' => $billed, 'credits' => $op['credits'] + $share];
        return $share;
    }

    /**
     * Part d'un appel facturée au palier « contexte long » d'un modèle
     * (cf. LlmCatalog::longContext). Le seuil vaut PAR REQUÊTE : une boucle
     * d'outils rapporte chaque aller-retour dans `rounds`, et seuls ceux dont
     * l'entrée dépasse le seuil comptent — trois allers de 100 000 jetons ne
     * font pas une requête de 300 000. Sans `rounds`, l'appel est une seule
     * requête. Rien sans palier.
     *
     * @param array<string, mixed> $tokens jetons rendus par un adaptateur
     * @return array<string, int> parties (input, output, input_cached, input_cache_write, output_reasoning), [] si aucune
     */
    public static function longContextPart(string $llmKey, array $tokens): array
    {
        [$provider, $model] = self::splitLlmKey($llmKey);
        $rule = LlmCatalog::longContext($provider, $model);
        if ($rule === null) {
            return [];
        }
        $rounds = $tokens['rounds'] ?? null;
        if (!is_array($rounds) || $rounds === []) {
            $rounds = [$tokens];
        }
        $keys = ['input', 'output', 'input_cached', 'input_cache_write', 'output_reasoning'];
        $long = array_fill_keys($keys, 0);
        foreach ($rounds as $round) {
            if (!is_array($round) || (int)($round['input'] ?? 0) <= $rule['above']) {
                continue;
            }
            foreach ($keys as $k) {
                $long[$k] += max(0, (int)($round[$k] ?? 0));
            }
        }
        return $long['input'] > 0 ? $long : [];
    }

    /**
     * Crédits + coût réel d'un lot d'embeddings (vectorisation d'une base,
     * embedding d'une question). Tarif : service `embedding:{provider}::{model}`
     * sinon `pricing_usd_per_1m` du catalogue d'embeddings.
     *
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool, components:array}
     */
    public static function computeEmbeddingCredits(string $provider, string $model, int $tokens): array
    {
        $config   = self::config();
        $tokens   = max(0, $tokens);
        $provider = strtolower(trim($provider));
        $model    = trim($model);

        $components = [];
        if ($tokens > 0) {
            $service = self::findByCatalogKey($config['services'], 'embedding:' . $provider . '::' . $model, true)
                ?? self::findByCatalogKey($config['services'], 'embedding:' . $provider . '::' . $model, false);
            if (!self::hasPrice($service)) {
                $service = self::catalogEmbeddingTariff($provider, $model) ?? $service;
            }
            $components['embedding'] = self::component($service, self::tokenQuantity($service, $tokens), $tokens);
        }

        return self::settle($components, $config['global'], $tokens > 0);
    }

    /**
     * Crédits + coût réel de N recherches web exécutées par le fournisseur
     * pendant une réponse. Tarif : service `websearch:{provider}` (unité
     * `request` = une recherche) sinon `web_search_pricing_usd_per_1k` du
     * catalogue LLM — celui du modèle, sinon celui du fournisseur.
     *
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool, components:array}
     */
    public static function computeWebSearchCredits(string $llmKey, int $searches): array
    {
        $config             = self::config();
        [$provider, $model] = self::splitLlmKey($llmKey);
        $searches           = max(0, $searches);

        $components = [];
        if ($searches > 0) {
            // Un modèle qui a SON tarif (Gemini 2.5 : 35 $ les 1 000 réponses
            // ancrées, contre 14 $ pour la génération 3) n'est pas tarifé par
            // la ligne du fournisseur, qu'enregistre l'écran Tarifs & crédits :
            // seule une ligne propre au modèle prime sur lui.
            $service = $model !== ''
                ? (self::findByCatalogKey($config['services'], 'websearch:' . $provider . '::' . $model, true)
                    ?? self::findByCatalogKey($config['services'], 'websearch:' . $provider . '::' . $model, false))
                : null;
            if (!self::hasPrice($service) && $model !== '' && class_exists('LlmCatalog')
                && is_numeric(LlmCatalog::model($provider, $model)['web_search_pricing_usd_per_1k'] ?? null)) {
                $service = self::catalogWebSearchTariff($provider, $model);
            }
            if (!self::hasPrice($service)) {
                $service = self::findByCatalogKey($config['services'], 'websearch:' . $provider, true)
                    ?? self::findByCatalogKey($config['services'], 'websearch:' . $provider, false)
                    ?? $service;
            }
            if (!self::hasPrice($service)) {
                $service = self::catalogWebSearchTariff($provider, $model) ?? $service;
            }
            $components['web_search'] = self::component($service, (float)$searches, $searches);
        }

        return self::settle($components, $config['global'], $searches > 0);
    }

    /**
     * Crédits + coût réel de minutes de transcription audio (dictée vocale).
     * Tarif : service `transcribe:openai::{model}` (unité `audio_minute`)
     * sinon `transcription_pricing_usd_per_minute` du catalogue LLM.
     *
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool, components:array}
     */
    public static function computeTranscriptionCredits(string $model, float $minutes): array
    {
        $config  = self::config();
        $model   = trim($model);
        $minutes = max(0.0, $minutes);

        $components = [];
        if ($minutes > 0) {
            $service = self::findByCatalogKey($config['services'], 'transcribe:openai::' . $model, true)
                ?? self::findByCatalogKey($config['services'], 'transcribe:openai::' . $model, false);
            if (!self::hasPrice($service)) {
                $service = self::catalogTranscriptionTariff($model) ?? $service;
            }
            $components['transcription'] = self::component($service, $minutes, (int)ceil($minutes));
        }

        return self::settle($components, $config['global'], $minutes > 0);
    }

    /**
     * Crédits + coût réel de minutes de lecture à voix haute (conversation
     * vocale). Tarif : service `speech:openai::{model}` (unité `audio_minute`)
     * sinon `speech_pricing_usd_per_minute` du catalogue LLM.
     *
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool, components:array}
     */
    public static function computeSpeechCredits(string $model, float $minutes): array
    {
        $config  = self::config();
        $model   = trim($model);
        $minutes = max(0.0, $minutes);

        $components = [];
        if ($minutes > 0) {
            $service = self::findByCatalogKey($config['services'], 'speech:openai::' . $model, true)
                ?? self::findByCatalogKey($config['services'], 'speech:openai::' . $model, false);
            if (!self::hasPrice($service)) {
                $service = self::catalogSpeechTariff($model) ?? $service;
            }
            $components['speech'] = self::component($service, $minutes, (int)ceil($minutes));
        }

        return self::settle($components, $config['global'], $minutes > 0);
    }

    /**
     * Calcule crédits + coût réel pour un outil image/vidéo/HTML avec lookup
     * en cascade sur les paramètres réels (modèle, qualité, taille, secondes).
     *
     * Résolution du tarif, dans l'ordre :
     *   1. ligne PROPRE à l'outil portant un prix (cascade modèle/qualité/
     *      taille, une ligne tarifée l'emportant sur une ligne semée à 0) ;
     *   2. tarif PUBLIC du modèle au catalogue des outils (par image, à la
     *      seconde selon la taille, ou au million de tokens depuis l'`usage`
     *      renvoyé par l'éditeur) ;
     *   3. ligne propre SANS prix → opération « non tarifée », signalée ;
     *   4. repli historique sur une ligne du même type, quand l'outil n'a
     *      aucune ligne à lui.
     * Avant, l'étape 2 n'existait pas : la ligne semée à 0 par l'écran
     * Crédits rendait toute image et toute vidéo « non tarifée », alors que
     * les modèles de langage retombaient sur leur tarif curé.
     *
     * @param array{model?:string,size?:string,quality?:string,seconds?:float|int,format?:string,count?:int,usage?:array|null} $params
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool,
     *               quantity_units:float, unit:string, catalog_key:string, meta:array<string, mixed>}
     */
    public static function computeToolCredits(
        int $toolId,
        string $toolType,
        array $params = []
    ): array {
        $config = self::config();
        $global = $config['global'];

        $model    = trim((string)($params['model']   ?? ''));
        $size     = trim((string)($params['size']    ?? ''));
        $quality  = trim((string)($params['quality'] ?? ''));
        $seconds  = isset($params['seconds']) ? (float)$params['seconds'] : null;
        $count    = max(1, (int)($params['count'] ?? 1));

        $own = self::findOwnToolService($config['services'], $toolId, $model, $quality, $size);
        if (self::hasPrice($own)) {
            $service = $own;
        } else {
            $usage   = is_array($params['usage'] ?? null) ? $params['usage'] : null;
            $service = self::catalogToolTariff($toolType, $model, $size, $usage)
                ?? $own
                ?? self::findToolServiceByType($config['services'], $toolType);
        }

        $meta = array_filter([
            'model'   => $model !== '' ? $model : null,
            'size'    => $size !== '' ? $size : null,
            'quality' => $quality !== '' ? $quality : null,
            'seconds' => $seconds,
            'format'  => isset($params['format']) ? trim((string)$params['format']) : null,
        ], fn($v) => $v !== null && $v !== '');

        $unit = (string)($service['unit'] ?? 'request');
        $quantityUnits = self::toolQuantityForUnit($unit, $seconds, $count);
        $settled = self::settle(
            ['tool' => self::component($service, $quantityUnits, $count)],
            $global,
            true
        );

        return $settled + [
            'quantity_units' => $quantityUnits,
            'unit' => $unit,
            'catalog_key' => (string)($service['catalog_key'] ?? ''),
            'meta' => $meta,
        ];
    }

    // =====================================================================
    // Résolution des tarifs
    // =====================================================================

    /**
     * Tarif TARIFÉ d'une direction LLM, dans l'ordre : service configuré
     * portant un prix (clé exacte `llm:{provider}::{model}:{dir}`, puis clé de
     * fournisseur `llm:{provider}:{dir}`), fournisseur gratuit (local), tarif
     * curé du catalogue. Null si aucun prix n'est trouvable : l'appelant
     * dérive (variantes) ou signale l'opération comme non tarifée.
     *
     * Un service configuré SANS prix (ligne semée par l'écran Crédits, jamais
     * renseignée) ne masque jamais un tarif connu. Et AUCUN repli sur « le
     * prix d'un autre modèle du même fournisseur » : facturer gpt-5.6 au
     * tarif de gpt-4o en silence serait pire qu'un signalement.
     */
    private static function resolveLlmTariff(array $config, string $provider, string $model, string $llmKey, string $direction): ?array
    {
        $service = self::findLlmService($config['services'], $llmKey, $direction);
        if (self::hasPrice($service)) {
            return $service;
        }
        if (in_array($provider, self::FREE_PROVIDERS, true)) {
            return self::virtualService('free:' . $provider . ':' . $direction, '1m_tokens', 0.0);
        }
        return self::catalogLlmTariff($provider, $model, $direction);
    }

    /** Tarif dérivé d'un tarif de référence par un ratio (variantes cache / raisonnement). */
    private static function ratioOf(?array $reference, float $ratio, string $direction): ?array
    {
        if ($reference === null) {
            return null;
        }
        $derived = self::virtualService(
            (string)($reference['catalog_key'] ?? 'ratio') . '*' . $ratio . ':' . $direction,
            (string)($reference['unit'] ?? '1m_tokens'),
            (float)($reference['real_cost_eur'] ?? 0.0) * $ratio
        );
        // Le coefficient spécifique du service de référence suit la variante.
        $derived['multiplier'] = max(0.0001, (float)($reference['multiplier'] ?? 1.0));
        return $derived;
    }

    /** Tarif curé du catalogue LLM (USD / 1M tokens → EUR), ou null. */
    private static function catalogLlmTariff(string $provider, string $model, string $direction): ?array
    {
        if ($provider === '' || $model === '' || !class_exists('LlmCatalog')) {
            return null;
        }
        $pricing = LlmCatalog::pricing($provider, $model);
        if (!is_array($pricing)) {
            return null;
        }
        $input  = isset($pricing['input'])  && is_numeric($pricing['input'])  ? (float)$pricing['input']  : null;
        $output = isset($pricing['output']) && is_numeric($pricing['output']) ? (float)$pricing['output'] : null;

        $usd = match ($direction) {
            'input'             => $input,
            'output'            => $output,
            'input_cached'      => isset($pricing['cached_input']) && is_numeric($pricing['cached_input'])
                ? (float)$pricing['cached_input']
                : ($input !== null ? $input * self::DEFAULT_CACHED_RATIO : null),
            'input_cache_write' => isset($pricing['cache_write']) && is_numeric($pricing['cache_write'])
                ? (float)$pricing['cache_write']
                : ($input !== null ? $input * self::DEFAULT_CACHE_WRITE_RATIO : null),
            'output_reasoning'  => isset($pricing['output_reasoning']) && is_numeric($pricing['output_reasoning'])
                ? (float)$pricing['output_reasoning']
                : $output,
            default             => null,
        };
        if ($usd === null) {
            return null;
        }
        return self::virtualService('catalog:llm:' . $provider . '::' . $model . ':' . $direction, '1m_tokens', self::usdToEur($usd));
    }

    /** Tarif curé du catalogue d'embeddings (USD / 1M tokens → EUR), ou null. */
    private static function catalogEmbeddingTariff(string $provider, string $model): ?array
    {
        if (!class_exists('EmbeddingService')) {
            return null;
        }
        $usd = EmbeddingService::catalog()[$provider]['models'][$model]['pricing_usd_per_1m'] ?? null;
        if (!is_numeric($usd)) {
            return null;
        }
        return self::virtualService('catalog:embedding:' . $provider . '::' . $model, '1m_tokens', self::usdToEur((float)$usd));
    }

    /**
     * Tarif curé d'une recherche web (USD / 1 000 recherches → EUR l'unité), ou
     * null. Celui du modèle prime sur celui du fournisseur (Gemini 2.5 / 3.x).
     */
    private static function catalogWebSearchTariff(string $provider, string $model = ''): ?array
    {
        if (!class_exists('LlmCatalog')) {
            return null;
        }
        $usdPer1k = LlmCatalog::webSearchPricingUsdPer1k($provider, $model);
        if ($usdPer1k === null) {
            return null;
        }
        $own = $model !== '' && is_numeric(LlmCatalog::model($provider, $model)['web_search_pricing_usd_per_1k'] ?? null);
        return self::virtualService('catalog:websearch:' . $provider . ($own ? '::' . $model : ''), 'request', self::usdToEur($usdPer1k / 1000));
    }

    /** Tarif curé d'une minute de transcription (USD / minute → EUR), ou null. */
    private static function catalogTranscriptionTariff(string $model): ?array
    {
        if (!class_exists('LlmCatalog')) {
            return null;
        }
        $table = LlmCatalog::provider('openai')['transcription_pricing_usd_per_minute'] ?? null;
        if (!is_array($table)) {
            return null;
        }
        $usd = $table[$model] ?? ($table['default'] ?? null);
        if (!is_numeric($usd)) {
            return null;
        }
        return self::virtualService('catalog:transcribe:openai::' . $model, 'audio_minute', self::usdToEur((float)$usd));
    }

    /** Tarif curé d'une minute de synthèse vocale (USD / minute → EUR), ou null. */
    private static function catalogSpeechTariff(string $model): ?array
    {
        if (!class_exists('LlmCatalog')) {
            return null;
        }
        $table = LlmCatalog::provider('openai')['speech_pricing_usd_per_minute'] ?? null;
        if (!is_array($table)) {
            return null;
        }
        $usd = $table[$model] ?? ($table['default'] ?? null);
        if (!is_numeric($usd)) {
            return null;
        }
        return self::virtualService('catalog:speech:openai::' . $model, 'audio_minute', self::usdToEur((float)$usd));
    }

    /**
     * Tarif PUBLIC qu'applique la facturation à une ligne de l'écran Tarifs &
     * crédits quand elle ne porte pas de prix — la même résolution que les
     * calculs ci-dessus, lue par clé de catalogue. Sans lui, l'écran affichait
     * « 0 € → 1 crédit » pour un modèle que chaque message facturait au tarif
     * curé : la page qui sert à régler les coûts mentait sur ce qui était
     * réellement compté. Null sans tarif public connu (l'opération est alors
     * réellement « non tarifée »).
     *
     * `unit` = unité du prix (`1m_tokens`, `request`, `audio_minute`,
     * `image_generated`, `video_second`).
     *
     * @return array{eur: float, unit: string}|null
     */
    public static function catalogUnitCost(string $catalogKey): ?array
    {
        $key = trim($catalogKey);
        $tariff = null;
        if (preg_match('/^llm:([a-z0-9_.-]+)::(.+):(input|output|input_cached|input_cache_write|output_reasoning)$/i', $key, $m) === 1) {
            $provider = strtolower($m[1]);
            $tariff = in_array($provider, self::FREE_PROVIDERS, true)
                ? self::virtualService('free:' . $provider, '1m_tokens', 0.0)
                : self::catalogLlmTariff($provider, $m[2], strtolower($m[3]));
        } elseif (preg_match('/^embedding:([^:]+)::(.+)$/', $key, $m) === 1) {
            $tariff = self::catalogEmbeddingTariff(strtolower($m[1]), $m[2]);
        } elseif (preg_match('/^websearch:([a-z0-9_.-]+)$/i', $key, $m) === 1) {
            $tariff = self::catalogWebSearchTariff(strtolower($m[1]));
        } elseif (preg_match('/^transcribe:openai::(.+)$/', $key, $m) === 1) {
            $tariff = self::catalogTranscriptionTariff($m[1]);
        } elseif (preg_match('/^speech:openai::(.+)$/', $key, $m) === 1) {
            $tariff = self::catalogSpeechTariff($m[1]);
        } elseif (preg_match('/^tool:(\d+)$/', $key, $m) === 1 && class_exists('ChatTool')) {
            $tool = ChatTool::findById((int)$m[1]);
            $pricing = $tool !== null ? self::catalogToolPricing((string)($tool['type'] ?? ''), (string)($tool['model'] ?? '')) : null;
            if (is_array($pricing) && is_numeric($pricing['per_image_usd'] ?? null)) {
                $tariff = self::virtualService('catalog:tool', 'image_generated', self::usdToEur((float)$pricing['per_image_usd']));
            } elseif (is_array($pricing) && is_array($pricing['per_second_usd'] ?? null)) {
                // Un prix par taille : affiché seulement s'il est le même pour toutes.
                $prices = array_values(array_unique(array_map('floatval', $pricing['per_second_usd'])));
                if (count($prices) === 1) {
                    $tariff = self::virtualService('catalog:tool', 'video_second', self::usdToEur($prices[0]));
                }
            }
        }

        return $tariff === null ? null : ['eur' => (float)$tariff['real_cost_eur'], 'unit' => (string)$tariff['unit']];
    }

    /**
     * Bloc `pricing` du catalogue des outils pour un modèle image ou vidéo
     * (tous fournisseurs confondus : un identifiant de modèle n'existe que
     * chez un éditeur), ou null. SOURCE UNIQUE de la lecture, partagée avec
     * « Mettre à jour les prix » de l'écran Crédits.
     *
     * @return array<string, mixed>|null
     */
    public static function catalogToolPricing(string $type, string $model): ?array
    {
        $type = strtolower(trim($type));
        if ($model === '' || !in_array($type, ['image', 'video'], true) || !class_exists('ToolCatalog')) {
            return null;
        }
        foreach (ToolCatalog::providers($type) as $entry) {
            $info = is_array($entry) ? ($entry['models'][$model] ?? null) : null;
            if (is_array($info) && is_array($info['pricing'] ?? null)) {
                return $info['pricing'];
            }
        }
        return null;
    }

    /**
     * Tarif public d'un modèle d'outil (config/tools_catalog.php, bloc
     * `pricing`), en service virtuel ; null sans tarif applicable :
     *   - `per_image_usd`     → unité `image_generated` ;
     *   - `per_second_usd`    → unité `video_second`, prix de la TAILLE
     *                           demandée (aucune taille publiée = null) ;
     *   - `tokens_usd_per_1m` → coût réel de l'opération depuis la
     *                           consommation renvoyée par l'éditeur (unité
     *                           `request`, quantité 1) ; sans `usage`, null —
     *                           un coût deviné serait pire qu'un signalement.
     *
     * @param array<string, mixed>|null $usage text_input / image_input / image_output
     */
    private static function catalogToolTariff(string $toolType, string $model, string $size, ?array $usage): ?array
    {
        $type    = strtolower(trim($toolType));
        $pricing = self::catalogToolPricing($type, $model);
        if ($pricing === null) {
            return null;
        }
        $key = 'catalog:tool:' . $type . ':' . $model;

        if (is_numeric($pricing['per_image_usd'] ?? null)) {
            return self::virtualService($key, 'image_generated', self::usdToEur((float)$pricing['per_image_usd']));
        }
        if (is_array($pricing['per_second_usd'] ?? null)) {
            $usd = $pricing['per_second_usd'][$size] ?? null;
            return is_numeric($usd)
                ? self::virtualService($key . ':' . $size, 'video_second', self::usdToEur((float)$usd))
                : null;
        }
        if (is_array($pricing['tokens_usd_per_1m'] ?? null) && $usage !== null) {
            $usd = 0.0;
            foreach (['text_input', 'image_input', 'image_output'] as $part) {
                $usd += max(0, (int)($usage[$part] ?? 0)) * (float)($pricing['tokens_usd_per_1m'][$part] ?? 0.0) / 1_000_000;
            }
            return $usd > 0.0 ? self::virtualService($key . ':tokens', 'request', self::usdToEur($usd)) : null;
        }
        return null;
    }

    private static function virtualService(string $catalogKey, string $unit, float $realCostEur): array
    {
        return [
            'id' => $catalogKey,
            'name' => $catalogKey,
            'provider' => '',
            'type' => 'catalog',
            'unit' => $unit,
            'real_cost_eur' => max(0.0, $realCostEur),
            'multiplier' => 1.0,
            'active' => true,
            'catalog_key' => $catalogKey,
        ];
    }

    /** Un service « porte un prix » s'il existe et que son coût réel est > 0 (ou qu'il est déclaré gratuit). */
    private static function hasPrice(?array $service): bool
    {
        if ($service === null) {
            return false;
        }
        if (str_starts_with((string)($service['catalog_key'] ?? ''), 'free:')) {
            return true;
        }
        return (float)($service['real_cost_eur'] ?? 0.0) > 0.0;
    }

    // =====================================================================
    // Composantes et règlement d'une opération
    // =====================================================================

    /**
     * Une composante = un service (ou aucun) × une quantité d'unités.
     * `cost_eur` = coût réel fournisseur ; `weighted_eur` = le même après
     * coefficient spécifique du service (assiette de la marge).
     */
    private static function component(?array $service, float $quantityUnits, int $rawQuantity): array
    {
        $qty = max(0.0, $quantityUnits);
        if ($service === null) {
            return ['quantity' => $rawQuantity, 'cost_eur' => 0.0, 'weighted_eur' => 0.0, 'priced' => false, 'catalog_key' => ''];
        }
        $real = max(0.0, (float)($service['real_cost_eur'] ?? 0.0)) * $qty;
        $multiplier = max(0.0001, (float)($service['multiplier'] ?? 1.0));
        return [
            'quantity'     => $rawQuantity,
            'cost_eur'     => $real,
            'weighted_eur' => $real * $multiplier,
            'priced'       => self::hasPrice($service),
            'catalog_key'  => (string)($service['catalog_key'] ?? ''),
        ];
    }

    /**
     * Règlement d'une opération : somme des composantes, marge et frais
     * fixes UNE fois, arrondi et minimum UNE fois.
     *
     * @param array<string, array> $components
     * @return array{total_credits:int, cost_eur:float, billed_eur:float, priced:bool, components:array}
     */
    private static function settle(array $components, array $global, bool $consumed): array
    {
        $real = 0.0;
        $weighted = 0.0;
        $priced = true;
        foreach ($components as $c) {
            $real     += (float)$c['cost_eur'];
            $weighted += (float)$c['weighted_eur'];
            if (empty($c['priced'])) {
                $priced = false;
            }
        }
        if ($components === [] || !$consumed) {
            return ['total_credits' => 0, 'cost_eur' => 0.0, 'billed_eur' => 0.0, 'priced' => true, 'components' => $components];
        }

        $margin = max(0.0001, (float)($global['margin_multiplier'] ?? 1.0));
        $billed = $weighted * $margin + max(0.0, (float)($global['fixed_fee_eur'] ?? 0.0));
        $rawCredits = $billed / max(0.000001, (float)($global['credit_value_eur'] ?? 0.001));
        $credits = max(
            self::roundCredits($rawCredits, (string)($global['rounding_mode'] ?? 'ceil')),
            max(0, (int)($global['min_credits_per_operation'] ?? 0))
        );

        $out = [];
        foreach ($components as $key => $c) {
            $out[$key] = [
                'tokens'      => (int)$c['quantity'],
                'cost_eur'    => round((float)$c['cost_eur'], 8),
                'catalog_key' => (string)$c['catalog_key'],
                'priced'      => (bool)$c['priced'],
            ];
        }

        return [
            'total_credits' => $credits,
            'cost_eur'      => round($real, 6),
            'billed_eur'    => round($billed, 6),
            'priced'        => $priced,
            'components'    => $out,
        ];
    }

    /** Quantité d'unités d'un service « tokens » pour N tokens. */
    private static function tokenQuantity(?array $service, int $tokens): float
    {
        $unit = (string)($service['unit'] ?? '1m_tokens');
        return match ($unit) {
            '1m_input_tokens', '1m_output_tokens', '1m_tokens' => $tokens / 1_000_000,
            '1k_tokens' => $tokens / 1_000,
            'api_call', 'request' => 1.0,
            default => $tokens / 1_000_000,
        };
    }

    // =====================================================================
    // Configuration et recherche de services
    // =====================================================================

    private static function config(): array
    {
        $raw = Settings::get('credits_config_json', '');
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        $config = is_array($decoded) ? $decoded : [];

        $global = self::globalConfig();
        $services = [];
        foreach ((array)($config['services'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $services[] = [
                'id' => trim((string)($row['id'] ?? '')),
                'name' => trim((string)($row['name'] ?? '')),
                'provider' => trim((string)($row['provider'] ?? '')),
                'type' => trim((string)($row['type'] ?? 'other')),
                'unit' => trim((string)($row['unit'] ?? 'request')),
                'real_cost_eur' => self::positiveFloat($row['real_cost_eur'] ?? 0.0, 0.0, 0.0),
                'multiplier' => self::positiveFloat($row['multiplier'] ?? 1.0, 1.0, 0.0001),
                'active' => !empty($row['active']),
                'catalog_key' => trim((string)($row['catalog_key'] ?? '')),
            ];
        }

        return [
            'global' => $global,
            'services' => $services,
        ];
    }

    private static function findLlmService(array $services, string $llmKey, string $direction): ?array
    {
        $llmKey = trim($llmKey);
        [$provider, $model] = self::splitLlmKey($llmKey);

        $keys = [];
        if ($llmKey !== '') {
            $keys[] = 'llm:' . $llmKey . ':' . $direction;
        }
        if ($provider !== '' && $model !== '' && $model !== $provider) {
            $keys[] = 'llm:' . $provider . '::' . $model . ':' . $direction;
        }
        if ($provider !== '') {
            $keys[] = 'llm:' . $provider . ':' . $direction;
        }

        // Un service TARIFÉ l'emporte toujours sur une ligne semée sans prix,
        // à chaque étage de la cascade ; un service actif sur un inactif.
        foreach ([true, false] as $pricedOnly) {
            foreach ($keys as $key) {
                $exactActive = self::findByCatalogKey($services, $key, true, $pricedOnly);
                if ($exactActive !== null) {
                    return $exactActive;
                }
                $exact = self::findByCatalogKey($services, $key, false, $pricedOnly);
                if ($exact !== null) {
                    return $exact;
                }
            }
        }

        return null;
    }

    /**
     * Ligne propre à un outil (`tool:{id}[:modèle[:qualité][:taille]]`), du
     * plus spécifique au plus générique. Une ligne TARIFÉE l'emporte sur une
     * ligne semée à 0, à chaque étage (même règle que findLlmService) ; une
     * active sur une inactive.
     */
    private static function findOwnToolService(
        array $services,
        int $toolId,
        string $model = '',
        string $quality = '',
        string $size = ''
    ): ?array {
        // Lookup en cascade : du plus spécifique au plus générique
        $candidates = [];
        if ($model !== '' && $quality !== '' && $size !== '') {
            $candidates[] = 'tool:' . $toolId . ':' . $model . ':' . $quality . ':' . $size;
        }
        if ($model !== '' && $quality !== '') {
            $candidates[] = 'tool:' . $toolId . ':' . $model . ':' . $quality;
        }
        if ($model !== '' && $size !== '') {
            $candidates[] = 'tool:' . $toolId . ':' . $model . ':' . $size;
        }
        if ($model !== '') {
            $candidates[] = 'tool:' . $toolId . ':' . $model;
        }
        $candidates[] = 'tool:' . $toolId;

        foreach ([true, false] as $pricedOnly) {
            foreach ($candidates as $key) {
                $exactActive = self::findByCatalogKey($services, $key, true, $pricedOnly);
                if ($exactActive !== null) {
                    return $exactActive;
                }
                $exact = self::findByCatalogKey($services, $key, false, $pricedOnly);
                if ($exact !== null) {
                    return $exact;
                }
            }
        }

        return null;
    }

    /**
     * Repli historique : première ligne du même type (image, vidéo…), pour un
     * outil qui n'a AUCUNE ligne à lui (configuration jamais enregistrée
     * depuis sa création). Consulté après le tarif public du catalogue.
     */
    private static function findToolServiceByType(array $services, string $toolType): ?array
    {
        $normalizedType = match (strtolower(trim($toolType))) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default => 'other',
        };

        foreach ([true, false] as $activeOnly) {
            foreach ($services as $service) {
                if ($activeOnly && empty($service['active'])) {
                    continue;
                }
                if ((string)($service['type'] ?? '') !== $normalizedType) {
                    continue;
                }
                return $service;
            }
        }

        return null;
    }

    private static function findByCatalogKey(array $services, string $catalogKey, bool $activeOnly, bool $pricedOnly = false): ?array
    {
        foreach ($services as $service) {
            if ($activeOnly && empty($service['active'])) {
                continue;
            }
            if ($pricedOnly && !self::hasPrice($service)) {
                continue;
            }
            if ((string)($service['catalog_key'] ?? '') === $catalogKey) {
                return $service;
            }
        }
        return null;
    }

    private static function toolQuantityForUnit(string $unit, ?float $seconds, int $count): float
    {
        $safeSeconds = max(0.0, (float)($seconds ?? 0.0));
        $safeCount = max(1, $count);

        return match ($unit) {
            'video_second', 'second' => max(1.0, $safeSeconds),
            'audio_minute', 'minute' => max(1.0, $safeSeconds > 0 ? ($safeSeconds / 60.0) : 1.0),
            'image_generated' => (float)$safeCount,
            'request', 'api_call' => 1.0,
            default => 1.0,
        };
    }

    private static function roundCredits(float $value, string $mode): int
    {
        return match ($mode) {
            'floor' => (int)floor($value),
            'nearest' => (int)round($value),
            default => (int)ceil($value),
        };
    }

    /**
     * « provider::model » → [provider, model]. Clé vide → openai ; clé sans
     * « :: » → [clé, ''] (slug réduit au provider).
     */
    public static function splitLlmKey(string $key): array
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return ['openai', ''];
        }
        if (!str_contains($trimmed, '::')) {
            return [strtolower($trimmed), ''];
        }

        [$provider, $model] = explode('::', $trimmed, 2);
        $provider = strtolower(trim($provider));
        $model = trim($model);
        return [$provider !== '' ? $provider : 'openai', $model];
    }

    private static function positiveFloat(mixed $value, float $default, float $min): float
    {
        $raw = str_replace(',', '.', trim((string)$value));
        if ($raw === '' || !is_numeric($raw)) {
            return max($default, $min);
        }

        $number = (float)$raw;
        if (!is_finite($number)) {
            return max($default, $min);
        }

        return max(round($number, 6), $min);
    }

    private static function roundingMode(string $value): string
    {
        $mode = strtolower(trim($value));
        if (in_array($mode, ['ceil', 'floor', 'nearest'], true)) {
            return $mode;
        }
        return 'ceil';
    }
}
