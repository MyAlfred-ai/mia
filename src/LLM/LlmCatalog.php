<?php

declare(strict_types=1);

/**
 * Lecture unifiée du catalogue LLM (`config/llm_catalog.php`).
 *
 * Toute l'application (adapters, factory, UI) passe par cette classe.
 * Pour ajouter ou modifier un modèle, éditer uniquement le catalogue —
 * pas besoin de toucher aux adapters ni à la factory.
 */
class LlmCatalog
{
    private static ?array $cache = null;

    /** Jour imposé par les tests (null = horloge du serveur), cf. setToday(). */
    private static ?string $today = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $path = ROOT . '/config/llm_catalog.php';
            $data = is_file($path) ? require $path : [];
            self::$cache = is_array($data) ? $data : [];
        }
        return self::$cache;
    }

    /**
     * Recharge le catalogue depuis le disque (utile pour les tests).
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string, mixed> */
    public static function provider(string $provider): array
    {
        return self::all()[$provider] ?? [];
    }

    /**
     * Modèles d'un provider sous la forme `[id => metadata]`, pour l'UI.
     * @return array<string, array<string, mixed>>
     */
    public static function models(string $provider): array
    {
        return self::provider($provider)['models'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public static function model(string $provider, string $model): ?array
    {
        return self::models($provider)[$model] ?? null;
    }

    /**
     * Modèle par défaut d'un provider : celui marqué `default:true` en priorité,
     * sinon `default_model` déclaré au niveau provider, sinon le premier listé.
     */
    public static function defaultModel(string $provider): string
    {
        foreach (self::models($provider) as $id => $info) {
            if (!empty($info['default'])) {
                return (string)$id;
            }
        }
        $declared = self::provider($provider)['default_model'] ?? '';
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }
        $first = array_key_first(self::models($provider) ?? []);
        return is_string($first) ? $first : '';
    }

    public static function endpoint(string $provider, string $name): ?string
    {
        $url = self::provider($provider)['endpoints'][$name] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function apiVersion(string $provider): ?string
    {
        $v = self::provider($provider)['api_version'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Retourne l'id du tool web_search pour un provider.
     * @param bool $preferLatest true = primary, false = fallback
     */
    public static function webSearchTool(string $provider, bool $preferLatest): ?string
    {
        $tools = self::provider($provider)['web_search_tools'] ?? [];
        $key   = $preferLatest ? 'primary' : 'fallback';
        $t     = $tools[$key] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    /**
     * Tarif officiel d'un modèle (USD / 1M tokens), s'il est déclaré au catalogue,
     * TEL QU'IL S'APPLIQUE AUJOURD'HUI (cf. effectivePricing). Source unique de
     * vérité pour CreditService et pour l'écran Admin → Crédits quand le lookup
     * web échoue (modèles trop récents). Retourne null si aucun tarif curé.
     *
     * @return array<string, mixed>|null
     */
    public static function pricing(string $provider, string $model): ?array
    {
        $info = self::model($provider, $model);
        if (is_array($info) && isset($info['pricing']) && is_array($info['pricing'])) {
            return self::effectivePricing($info['pricing'], self::today());
        }
        return null;
    }

    /**
     * Palier « contexte long » d'un modèle (`pricing.long_context`) : au-delà
     * de `above` jetons d'entrée DANS UNE MÊME REQUÊTE (cache compris), toute
     * la requête est facturée plus cher — entrée (cache lu et écrit compris)
     * × `input_ratio`, sortie (raisonnement compris) × `output_ratio`. OpenAI
     * (272 000 : GPT-6, GPT-5.6 Sol, GPT-5.5, GPT-5.4) et Gemini Pro (200 000)
     * en ont un ; un bloc absent ou mal formé = aucun palier.
     *
     * @return array{above:int, input_ratio:float, output_ratio:float}|null
     */
    public static function longContext(string $provider, string $model): ?array
    {
        $rule = self::model($provider, $model)['pricing']['long_context'] ?? null;
        if (!is_array($rule)) {
            return null;
        }
        $above = $rule['above'] ?? null;
        $in    = $rule['input_ratio'] ?? null;
        $out   = $rule['output_ratio'] ?? null;
        if (!is_numeric($above) || (int)$above <= 0 || !is_numeric($in) || !is_numeric($out)
            || (float)$in < 1.0 || (float)$out < 1.0) {
            return null;
        }
        return ['above' => (int)$above, 'input_ratio' => (float)$in, 'output_ratio' => (float)$out];
    }

    /**
     * Tarif NORMAL d'un modèle, bloc `launch` écarté — celui qu'enregistre
     * l'écran Tarifs & crédits (« Mettre à jour les prix »). Un tarif
     * enregistré prime sur le catalogue et ne suit aucune date : y figer un
     * tarif de lancement ferait sous-facturer en silence dès sa fin. Sans
     * tarif enregistré, CreditService lit pricing(), qui applique le lancement
     * jusqu'à sa date.
     *
     * @return array<string, mixed>|null
     */
    public static function standardPricing(string $provider, string $model): ?array
    {
        $info = self::model($provider, $model);
        if (!is_array($info) || !is_array($info['pricing'] ?? null)) {
            return null;
        }
        $pricing = $info['pricing'];
        unset($pricing['launch']);
        return $pricing;
    }

    /**
     * Tarif applicable un jour donné — pure. Un tarif de lancement (`launch`)
     * remplace les tarifs qu'il déclare jusqu'à `until` INCLUS, puis s'efface
     * de lui-même : sans cette borne, il fallait choisir entre surfacturer
     * toute la période de lancement (tarif normal) et sous-facturer en silence
     * dès sa fin (tarif réduit oublié au catalogue). Un `until` absent ou mal
     * formé annule le bloc — jamais un tarif réduit appliqué sans limite.
     * Tarif réduit en vigueur : `launch_until` porte sa date de fin.
     *
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>
     */
    public static function effectivePricing(array $pricing, string $today): array
    {
        $launch = $pricing['launch'] ?? null;
        unset($pricing['launch']);
        if (!is_array($launch)) {
            return $pricing;
        }
        $until = (string)($launch['until'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1 || $today > $until) {
            return $pricing;
        }
        foreach (['input', 'output', 'cached_input', 'cache_write', 'output_reasoning'] as $key) {
            if (isset($launch[$key]) && is_numeric($launch[$key])) {
                $pricing[$key] = (float)$launch[$key];
            }
        }
        $pricing['launch_until'] = $until;
        return $pricing;
    }

    /**
     * Jour courant `AAAA-MM-JJ`, fuseau du serveur. Un tarif daté doit se
     * vérifier la veille ET le lendemain de sa fin : les tests fixent le jour ici.
     */
    public static function today(): string
    {
        return self::$today ?? date('Y-m-d');
    }

    /** Fixe le jour courant (tests) ; null rend la main à l'horloge. */
    public static function setToday(?string $ymd): void
    {
        self::$today = $ymd;
    }

    /**
     * Arrêt d'un modèle ANNONCÉ par son éditeur (bloc `retirement` du
     * catalogue) — SOURCE UNIQUE, pendant de `ToolCatalog::retirement()` pour
     * les modèles d'outils. Null tant qu'aucun arrêt n'est annoncé (ou modèle
     * inconnu au catalogue).
     *
     * `retired` = la date est atteinte (jour du serveur, `today()`) : le modèle
     * a quitté le chat (`LLMFactory::available`). Avant, l'arrêt n'est
     * qu'annoncé : badge et point à régler dans l'administration. Rien n'est
     * écrit en base : tout se lit à la date, et une date repoussée par
     * l'éditeur se corrige ici seulement.
     *
     * @return array{date:string, replacement:string, replacement_label:string, source_url:string, retired:bool}|null
     */
    public static function retirement(string $provider, string $model): ?array
    {
        $bloc = self::model($provider, $model)['retirement'] ?? null;
        if (!is_array($bloc)) {
            return null;
        }
        $arret = self::retirementFrom($bloc, self::today());
        if ($arret === null) {
            return null;
        }
        // Libellé du remplaçant s'il est au catalogue du même éditeur, sinon
        // son identifiant (OpenAI peut désigner un modèle que MIA ne propose pas).
        $remplacant = $arret['replacement'];
        $arret['replacement_label'] = $remplacant !== ''
            ? (string)(self::model($provider, $remplacant)['label'] ?? $remplacant)
            : '';
        return $arret;
    }

    /**
     * Forme pure du bloc `retirement` : date valide exigée (sinon null — un
     * bloc mal écrit n'arrête jamais un modèle), arrêté le jour même.
     *
     * @param array<string,mixed> $bloc
     * @return array{date:string, replacement:string, source_url:string, retired:bool}|null
     */
    public static function retirementFrom(array $bloc, string $today): ?array
    {
        $date = (string)($bloc['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        return [
            'date'        => $date,
            'replacement' => trim((string)($bloc['replacement'] ?? '')),
            'source_url'  => (string)($bloc['source_url'] ?? ''),
            'retired'     => $date <= $today,
        ];
    }

    /** Modèle arrêté par son éditeur À CE JOUR. Avant la date annoncée : false. */
    public static function isRetired(string $provider, string $model): bool
    {
        return (bool)(self::retirement($provider, $model)['retired'] ?? false);
    }

    /**
     * Tarif d'une recherche web exécutée par le fournisseur, en USD les 1 000 :
     * celui du MODÈLE s'il en déclare un, sinon celui du fournisseur, sinon null
     * (recherche non tarifée — signalée, jamais gratuite en silence). Le modèle
     * prime parce qu'un fournisseur peut changer de grille d'une génération à
     * l'autre : Gemini 3.x facture la requête 14 $, Gemini 2.5 le prompt ancré 35 $.
     */
    public static function webSearchPricingUsdPer1k(string $provider, string $model): ?float
    {
        $usd = self::model($provider, $model)['web_search_pricing_usd_per_1k'] ?? null;
        if (!is_numeric($usd)) {
            $usd = self::provider($provider)['web_search_pricing_usd_per_1k'] ?? null;
        }
        return is_numeric($usd) ? (float)$usd : null;
    }

    /**
     * Ce que le fournisseur facture comme UNE recherche : `request`, chaque
     * requête lancée — une réponse peut en compter plusieurs (OpenAI, Anthropic,
     * Gemini 3.x) —, ou `prompt`, une réponse ancrée quel que soit le nombre de
     * requêtes (Gemini 2.5). Le modèle prime sur le fournisseur ; `request` par
     * défaut.
     */
    public static function webSearchBilling(string $provider, string $model): string
    {
        $unit = self::model($provider, $model)['web_search_billing']
            ?? (self::provider($provider)['web_search_billing'] ?? null);
        return $unit === 'prompt' ? 'prompt' : 'request';
    }

    public static function fallbackSearchModel(string $provider): ?string
    {
        $m = self::provider($provider)['fallback_search_model'] ?? null;
        return is_string($m) && $m !== '' ? $m : null;
    }

    /**
     * Stratégie de routage pour un modèle.
     * Valeurs : 'chat_completions' | 'responses_api' | 'responses_web_search' | 'search_preview'.
     *
     * Si le modèle n'est pas explicitement listé :
     * - Pour OpenAI, on applique `gpt5_default_routing` si l'id commence par "gpt-5".
     * - Sinon, fallback 'chat_completions'.
     */
    public static function routing(string $provider, string $model): string
    {
        $info = self::model($provider, $model);
        if (is_array($info) && !empty($info['routing']) && is_string($info['routing'])) {
            return $info['routing'];
        }

        $providerConfig = self::provider($provider);
        $gpt5Default    = $providerConfig['gpt5_default_routing'] ?? null;
        if ($gpt5Default && is_string($gpt5Default) && str_starts_with(strtolower(trim($model)), 'gpt-5')) {
            return $gpt5Default;
        }

        return 'chat_completions';
    }

    /**
     * Le modèle sait-il piloter une boucle de function-calling (connecteurs
     * Drive/Gmail, génération d'image en conversation) ? Source unique,
     * partagée entre les adapters (supportsToolUse) et l'UI du chat
     * (chip « Connecteurs », grisage des toggles du menu « + »).
     * Anthropic : tous les modèles. OpenAI : tous sauf les modèles de
     * recherche dédiés (`search_preview`). Autres providers : non (trait
     * ToolUseUnsupportedTrait côté adapter).
     */
    /**
     * Le modèle peut-il chercher sur Internet quand l'utilisateur coche
     * « Recherche Internet » ? Source unique, lue depuis le catalogue plutôt
     * que d'une liste de providers codée en dur : un provider web-capable
     * ajouté au catalogue serait sinon silencieusement ignoré (c'était le cas
     * de Perplexity, dont tous les modèles sont pourtant `web_native`).
     *
     * Deux voies possibles :
     *  - le modèle est nativement capable (`web_native` : outil web natif,
     *    grounding Google, ou recherche intégrée façon Sonar) ;
     *  - le provider déclare un `fallback_search_model` sur lequel basculer
     *    (OpenAI : un modèle sans recherche bascule sur un modèle de recherche).
     */
    public static function supportsWebSearch(string $provider, string $model): bool
    {
        // Modèle inconnu au catalogue (slug réduit au provider, override admin
        // d'un modèle non listé…) : on se prononce sur le modèle par défaut du
        // provider plutôt que de répondre « non » par défaut, ce qui
        // désactiverait la recherche web sans raison visible.
        $info = self::model($provider, $model);
        if (!is_array($info)) {
            $info = self::model($provider, self::defaultModel($provider));
        }
        if (is_array($info) && !empty($info['web_native'])) {
            return true;
        }

        $fallback = self::provider($provider)['fallback_search_model'] ?? null;

        return is_string($fallback) && $fallback !== '';
    }

    /**
     * Le modèle sait-il piloter des outils (function-calling) avec MIA ?
     *
     * Lu au catalogue : drapeau `tool_use` du modèle, sinon du fournisseur. Un
     * modèle inconnu (slug réduit au fournisseur, modèle activé hors liste) est
     * jugé sur le modèle par défaut du fournisseur, comme supportsWebSearch.
     * Deux exceptions, et elles sont structurelles : OpenAI exige en plus un
     * routage qui porte les outils (un `*-search-preview` n'en porte pas), et
     * le LLM local, absent du catalogue, dépend d'une case de l'administrateur
     * (on ne peut pas savoir ce que sait le modèle servi par Ollama).
     */
    public static function supportsToolUse(string $provider, string $model): bool
    {
        if ($provider === 'local') {
            return LocalAdapter::toolUseEnabled();
        }
        if ($provider === 'openai' && !in_array(
            self::routing('openai', $model),
            ['chat_completions', 'responses_api', 'responses_web_search'],
            true
        )) {
            return false;
        }
        return self::modelFlag($provider, $model, 'tool_use');
    }

    /**
     * Le modèle sait-il composer LUI-MÊME un fichier PowerPoint (exécution de
     * code chez son fournisseur, NativeDeck) ? Drapeau `native_pptx` du
     * modèle, sinon du fournisseur ; seuls Anthropic et OpenAI l'exposent.
     */
    public static function supportsNativePptx(string $provider, string $model): bool
    {
        return in_array($provider, ['anthropic', 'openai'], true) && self::modelFlag($provider, $model, 'native_pptx');
    }

    /**
     * Recherche Internet ET outils dans la MÊME réponse ? Faux chez Mistral
     * (la recherche passe par la Conversations API, les outils par Chat
     * Completions) : le contrôleur arbitre alors (ChatController::runSend).
     * Vrai chez Anthropic, OpenAI (Responses API) et Gemini 3 (« built-in
     * tools and custom function calling tools in the same API call »).
     */
    public static function supportsWebSearchWithTools(string $provider, string $model): bool
    {
        return self::supportsToolUse($provider, $model)
            && self::supportsWebSearch($provider, $model)
            && self::modelFlag($provider, $model, 'tools_with_web_search');
    }

    /**
     * Dialecte de function-calling d'un fournisseur au format Chat Completions
     * (cf. ChatCompletionsToolLoopTrait). Bloc `tool_calling` du fournisseur,
     * complété par les valeurs du format OpenAI.
     *
     * @return array{tool_choice_none: bool, tool_message_name: bool, reasoning_echo: bool}
     */
    public static function toolCallingDialect(string $provider): array
    {
        $declared = self::provider($provider)['tool_calling'] ?? [];
        $declared = is_array($declared) ? $declared : [];
        return [
            'tool_choice_none'  => (bool)($declared['tool_choice_none'] ?? true),
            'tool_message_name' => (bool)($declared['tool_message_name'] ?? false),
            'reasoning_echo'    => (bool)($declared['reasoning_echo'] ?? true),
        ];
    }

    /**
     * Drapeau booléen d'un modèle, sinon de son fournisseur, sinon faux. Un
     * modèle absent du catalogue hérite du modèle par défaut du fournisseur.
     */
    private static function modelFlag(string $provider, string $model, string $flag): bool
    {
        $info = self::model($provider, $model);
        if (!is_array($info)) {
            $info = self::model($provider, self::defaultModel($provider));
        }
        if (is_array($info) && array_key_exists($flag, $info)) {
            return (bool)$info[$flag];
        }
        return (bool)(self::provider($provider)[$flag] ?? false);
    }

    /**
     * Le modèle accepte-t-il le paramètre `temperature` ?
     * Par défaut oui (true), sauf mention explicite `temperature => false`
     * ou appartenance à `no_temperature_models`.
     */
    public static function supportsTemperature(string $provider, string $model): bool
    {
        $info = self::model($provider, $model);
        if (is_array($info) && array_key_exists('temperature', $info)) {
            return (bool)$info['temperature'];
        }

        $list = self::provider($provider)['no_temperature_models'] ?? [];
        if (is_array($list) && in_array($model, $list, true)) {
            return false;
        }

        return true;
    }

    /**
     * Température à ENVOYER pour ce modèle — source unique : null quand
     * l'assistant n'en fixe pas (rien n'est envoyé) ou que le modèle refuse
     * le paramètre ; sinon la valeur bornée à [0, `temperature_max`] (modèle,
     * sinon fournisseur, sinon aucune borne).
     *
     * Le curseur de MIA va de 0 à 2 (échelle OpenAI) alors qu'Anthropic et
     * GLM refusent au-delà de 1 et Mistral au-delà de 1,5 : sans borne, un
     * assistant réglé à 1,8 recevait un 400 à chaque message sur ces modèles.
     */
    public static function temperatureFor(string $provider, string $model, ?float $temperature): ?float
    {
        if ($temperature === null || !self::supportsTemperature($provider, $model)) {
            return null;
        }
        $info = self::model($provider, $model);
        $max  = (is_array($info) && isset($info['temperature_max']))
            ? $info['temperature_max']
            : (self::provider($provider)['temperature_max'] ?? null);
        $temperature = max(0.0, $temperature);
        return is_numeric($max) ? min($temperature, (float)$max) : $temperature;
    }

    /**
     * Fenêtre de contexte (en tokens) du modèle, pour calculer un budget RAG
     * qui ne déborde jamais. Résolution : `context_window` du modèle →
     * `default_context_window` du provider → plancher conservateur 8192
     * (couvre les modèles non listés, ex. endpoints locaux Ollama/LM Studio).
     */
    public static function contextWindow(string $provider, string $model): int
    {
        $info = self::model($provider, $model);
        if (is_array($info) && !empty($info['context_window'])) {
            return (int)$info['context_window'];
        }
        $providerDefault = self::provider($provider)['default_context_window'] ?? null;
        if (is_numeric($providerDefault) && (int)$providerDefault > 0) {
            return (int)$providerDefault;
        }
        return 8192;
    }

    /**
     * Plafond de sortie (`max_tokens`) déclaré pour un modèle, ou null : l'adapter
     * applique alors son propre défaut (Anthropic : 64 000 ; DeepSeek : rien
     * d'envoyé, l'API borne alors à 8K). Le raisonnement COMPTE dans ce
     * plafond — un modèle qui pense toujours (Claude Fable) doit en déclarer
     * un plus haut, sinon ses réponses longues sont tronquées, voire vides
     * quand le raisonnement a tout consommé.
     */
    public static function maxOutputTokens(string $provider, string $model): ?int
    {
        $v = self::model($provider, $model)['max_output_tokens'] ?? null;
        return is_int($v) && $v > 0 ? $v : null;
    }

    /**
     * Le modèle lit-il les images jointes ? Capacité `vision` de ses tags au
     * catalogue ; un modèle inconnu est jugé sur le modèle par défaut du
     * fournisseur, comme supportsToolUse. Sert aux adaptateurs Chat
     * Completions qui ne savent pas d'avance ce que lit le modèle appelé
     * (DeepSeek, GLM) : envoyer une image à un modèle texte serait refusé.
     */
    public static function acceptsImages(string $provider, string $model): bool
    {
        $info = self::model($provider, $model);
        if (!is_array($info)) {
            $info = self::model($provider, self::defaultModel($provider));
        }
        return is_array($info) && in_array('vision', (array)($info['tags'] ?? []), true);
    }

    /**
     * Mode de réflexion imposé par MIA à un modèle (champ `thinking` du
     * catalogue) : false → coupé, true → activé, null → rien d'envoyé, le
     * défaut de l'API s'applique. DeepSeek V4 réfléchit PAR DÉFAUT : MIA le
     * coupe, parce qu'en mode réflexion `temperature` est ignorée et qu'avec
     * des outils le raisonnement de TOUS les tours passés doit être renvoyé
     * (400 sinon), ce que MIA ne conserve pas.
     */
    public static function thinkingMode(string $provider, string $model): ?bool
    {
        $info = self::model($provider, $model);
        if (!is_array($info)) {
            $info = self::model($provider, self::defaultModel($provider));
        }
        if (is_array($info) && array_key_exists('thinking', $info) && is_bool($info['thinking'])) {
            return $info['thinking'];
        }
        return null;
    }

    /**
     * Repli CÔTÉ SERVEUR sur refus des classifieurs (paramètre Anthropic
     * `fallbacks`) : `'default'` = le fournisseur choisit le modèle de relais
     * selon la catégorie du refus. Null = aucun repli, le refus reste un refus.
     */
    public static function refusalFallback(string $provider, string $model): ?string
    {
        $v = self::model($provider, $model)['refusal_fallback'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Modèle de relais quand l'organisation ne remplit pas une condition
     * d'accès du modèle — Claude Fable exige la conservation des données
     * 30 jours et répond 400 à une organisation en « zéro rétention ». Null =
     * pas de relais, l'erreur est présentée telle quelle.
     */
    public static function retentionFallback(string $provider, string $model): ?string
    {
        $v = self::model($provider, $model)['retention_fallback'] ?? null;
        return is_string($v) && $v !== '' && $v !== $model && self::model($provider, $v) !== null ? $v : null;
    }

    /**
     * Le modèle rend-il ses notes d'avancement entre deux appels d'outils
     * (Anthropic `thinking.display: "updates"`, bêta) ? Sur Fable, Opus 5.5 et
     * Sonnet 5.5, ces notes reviennent en blocs de raisonnement, VIDES par
     * défaut : sans ce réglage, une longue boucle d'outils reste muette.
     */
    public static function supportsProgressUpdates(string $provider, string $model): bool
    {
        return (self::model($provider, $model)['progress_updates'] ?? false) === true;
    }

    /**
     * Effort par défaut du modèle quand il n'est PAS « high » (Anthropic
     * `output_config.effort`), sinon null. Seul ce cas justifie d'envoyer un
     * effort explicite : le fixer à sa valeur par défaut ne change rien.
     */
    public static function defaultEffort(string $provider, string $model): ?string
    {
        $v = self::model($provider, $model)['effort_default'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Liste tous les modèles d'un provider qui utilisent un routage donné.
     * @return list<string>
     */
    public static function modelsWithRouting(string $provider, string $routing): array
    {
        $ids = [];
        foreach (self::models($provider) as $id => $info) {
            if (($info['routing'] ?? null) === $routing) {
                $ids[] = (string)$id;
            }
        }
        return $ids;
    }
}
