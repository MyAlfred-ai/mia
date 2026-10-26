<?php

declare(strict_types=1);

class LLMFactory
{
    /**
     * Crée un adapter LLM à partir d'un identifiant.
     * Formats acceptés :
     *   "openai::gpt-4o"  → OpenAIAdapter avec model gpt-4o (nouveau format)
     *   "openai"           → OpenAIAdapter avec le modèle configuré dans api_keys (ancien format)
     */
    public static function make(string $llm): LLMInterface
    {
        if (str_contains($llm, '::')) {
            [$provider, $model] = explode('::', $llm, 2);
            return match($provider) {
                'openai'      => new OpenAIAdapter($model),
                'mistral'     => new MistralAdapter($model),
                'anthropic'   => new AnthropicAdapter($model),
                'perplexity'  => new PerplexityAdapter($model),
                'gemini'      => new GeminiAdapter($model),
                'deepseek'    => new DeepSeekAdapter($model),
                'moonshot'    => new MoonshotAdapter($model),
                'zai'         => new ZaiAdapter($model),
                'local'       => new LocalAdapter(),
                default       => throw new \InvalidArgumentException("Provider inconnu : $provider"),
            };
        }

        return match($llm) {
            'openai'    => new OpenAIAdapter(),
            'mistral'   => new MistralAdapter(),
            'anthropic' => new AnthropicAdapter(),
            'local'     => new LocalAdapter(),
            default     => throw new \InvalidArgumentException("Provider inconnu : $llm"),
        };
    }

    /**
     * Retourne la liste des LLMs disponibles pour les utilisateurs.
     * Clés = slug "provider::model" (ou "local" / provider seul en fallback).
     * Priorité : instances configurées dans llm_models, sinon fallback vers api_keys.
     */
    public static function available(): array
    {
        $result = [];

        // Instances LLM configurées par l'admin — sauf un modèle arrêté par son
        // éditeur (bloc `retirement` du catalogue, date atteinte) : il ne
        // répondrait plus que « modèle introuvable ». Il quitte le chat, et le
        // modèle par défaut prend la relève partout où il était choisi
        // (réglage, assistants, conversations), sans rien écrire en base.
        foreach (LlmModel::active() as $m) {
            if (LlmCatalog::isRetired((string)$m['provider'], (string)$m['model'])) {
                continue;
            }
            $result[$m['provider'] . '::' . $m['model']] = $m['display_name'];
        }

        // Local : toujours depuis api_keys (config base_url spécifique)
        $local = ApiKey::findByProvider('local');
        if ($local) {
            $result['local'] = !empty($local['display_name']) ? $local['display_name'] : 'LLM Local';
        }

        // Fallback : si aucune instance cloud configurée, afficher les providers avec clé API
        $hasSlugs = !empty(array_filter(array_keys($result), fn($k) => str_contains($k, '::')));
        if (!$hasSlugs) {
            foreach (['openai' => 'ChatGPT (OpenAI)', 'anthropic' => 'Claude (Anthropic)', 'mistral' => 'Mistral'] as $p => $label) {
                if (ApiKey::findByProvider($p)) {
                    $result[$p] = $label;
                }
            }
        }

        // Affichage du chat : regroupé par fournisseur (ordre Z→A sur la clé
        // provider), puis du plus récent au plus ancien au sein de chaque
        // fournisseur (ordre du catalogue, maintenu newest-first). Les clés sont
        // préservées, donc les filtres par clé (intersect/union) restent valides.
        $catalogIndex = static function (string $key): int {
            if (!str_contains($key, '::')) {
                return PHP_INT_MAX; // local / provider seul → après les modèles connus
            }
            [$prov, $model] = explode('::', $key, 2);
            $i = 0;
            foreach (array_keys(LlmCatalog::models($prov)) as $id) {
                if ((string) $id === $model) {
                    return $i;
                }
                $i++;
            }
            return PHP_INT_MAX; // hors catalogue → après les modèles connus
        };

        $rows = [];
        foreach ($result as $key => $label) {
            $rows[] = [
                'key'   => $key,
                'label' => $label,
                'prov'  => self::provider($key),
                'idx'   => $catalogIndex($key),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $p = strnatcasecmp($b['prov'], $a['prov']); // fournisseur Z→A
            if ($p !== 0) {
                return $p;
            }
            if ($a['idx'] !== $b['idx']) {
                return $a['idx'] <=> $b['idx']; // index catalogue bas = plus récent → en haut
            }
            return strnatcasecmp($b['label'], $a['label']); // tiebreak hors-catalogue : libellé décroissant
        });

        $result = [];
        foreach ($rows as $r) {
            $result[$r['key']] = $r['label'];
        }

        return $result;
    }

    /**
     * LLM par défaut EFFECTIF — SOURCE UNIQUE de la règle :
     *   1. le réglage `default_llm`, s'il désigne un LLM de $available ;
     *   2. sinon le modèle par défaut du CATALOGUE de son éditeur, premier
     *      trouvé dans l'ordre de $available — jamais un modèle premium, qui
     *      ne doit devenir le défaut que par un choix délibéré ;
     *   3. sinon le premier de $available ; sinon null.
     *
     * Le réglage seul ne suffit pas : 004_settings.sql sème « openai », un
     * slug de FOURNISSEUR qui ne désigne plus rien dès qu'un modèle est activé,
     * et un modèle retiré laisse le réglage pointer dans le vide. La liste
     * blanche du groupe « ★ Par défaut » valait alors ce slug mort : aucun
     * modèle, et « Ce LLM n'est pas autorisé pour votre groupe. ».
     *
     * Pure : $catalogDefaults (fournisseur → modèle par défaut) est injecté.
     *
     * @param array<string,string> $available       slug → libellé, dans l'ordre d'affichage
     * @param array<string,string> $catalogDefaults fournisseur → modèle par défaut du catalogue
     */
    public static function effectiveDefault(string $configured, array $available, array $catalogDefaults = []): ?string
    {
        $configured = trim($configured);
        if ($configured !== '' && isset($available[$configured])) {
            return $configured;
        }
        foreach (array_keys($available) as $slug) {
            $slug = (string)$slug;
            if (!str_contains($slug, '::')) {
                continue;
            }
            [$provider, $model] = explode('::', $slug, 2);
            if (($catalogDefaults[$provider] ?? null) === $model) {
                return $slug;
            }
        }
        $first = array_key_first($available);
        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * Défaut effectif de l'instance : réglage + LLM disponibles + catalogue.
     * $available permet de restreindre au périmètre d'un utilisateur (liste
     * filtrée par ses groupes) ; null = tous les LLM disponibles.
     *
     * @param array<string,string>|null $available
     */
    public static function appDefault(?array $available = null): ?string
    {
        $available ??= self::available();
        $catalogDefaults = [];
        foreach (array_keys($available) as $slug) {
            $provider = self::provider((string)$slug);
            if ($provider !== '' && !isset($catalogDefaults[$provider])) {
                $catalogDefaults[$provider] = LlmCatalog::defaultModel($provider);
            }
        }
        return self::effectiveDefault((string)Settings::get('default_llm', ''), $available, $catalogDefaults);
    }

    /**
     * ENREGISTRE le défaut effectif quand le réglage ne désigne plus aucun LLM
     * disponible. Appelée après chaque geste qui change la liste — activer ou
     * retirer un modèle, enregistrer ou supprimer une clé (AdminController).
     *
     * Sans cette persistance, le défaut effectif suivrait l'ordre de la liste
     * au fil des activations, en silence, pour tout le groupe « ★ Par
     * défaut » ; et la carte Comportement par défaut (page Modèles) montrerait un modèle
     * qu'elle n'a jamais enregistré. Un secours devenu identique au défaut
     * (inerte) est vidé, comme à l'enregistrement de la page.
     *
     * Rien de disponible (toutes les clés retirées) : le réglage est laissé
     * tel quel — il redevient valide avec la clé.
     *
     * @return string|null le nouveau défaut, null si rien n'a changé
     */
    public static function repairStoredDefault(): ?string
    {
        $available  = self::available();
        $configured = trim((string)Settings::get('default_llm', ''));
        if ($configured !== '' && isset($available[$configured])) {
            return null;
        }
        $effective = self::appDefault($available);
        if ($effective === null) {
            return null;
        }
        Settings::set('default_llm', $effective);
        if (trim((string)Settings::get('default_llm_fallback', '')) === $effective) {
            Settings::set('default_llm_fallback', '');
        }
        return $effective;
    }

    /**
     * Libellé d'affichage d'un fournisseur — en-têtes de groupe du picker LLM.
     */
    public static function providerLabel(string $provider): string
    {
        return [
            'openai'     => 'OpenAI',
            'anthropic'  => 'Claude (Anthropic)',
            'mistral'    => 'Mistral',
            'perplexity' => 'Perplexity',
            'gemini'     => 'Gemini',
            'deepseek'   => 'DeepSeek',
            'moonshot'   => 'Kimi (Moonshot)',
            'zai'        => 'GLM (Z.ai)',
            'local'      => 'Local',
        ][$provider] ?? ucfirst($provider);
    }

    /** Extrait le provider depuis un slug LLM ("openai::gpt-4o" → "openai"). */
    public static function provider(string $llm): string
    {
        return explode('::', $llm)[0];
    }

    /**
     * Slug "provider::model" d'un modèle arrêté par son éditeur à ce jour
     * (cf. LlmCatalog::retirement). "local" et un provider seul : jamais.
     */
    public static function isRetired(string $llm): bool
    {
        if (!str_contains($llm, '::')) {
            return false;
        }
        [$provider, $model] = explode('::', $llm, 2);
        return LlmCatalog::isRetired($provider, $model);
    }

    /**
     * Le LLM (slug "provider::model", "local" ou provider seul) accepte-t-il le
     * paramètre `temperature` ? Résout le modèle effectif puis interroge le
     * catalogue (source unique de vérité). Utilisé côté UI pour griser le
     * curseur de température et côté adapters pour décider de l'envoi.
     */
    public static function supportsTemperature(string $llm): bool
    {
        // LLM local : endpoint OpenAI-compatible, temperature acceptée.
        if ($llm === 'local') {
            return true;
        }
        if (str_contains($llm, '::')) {
            [$provider, $model] = explode('::', $llm, 2);
        } else {
            $provider = $llm;
            $model    = ApiKey::getModel($provider) ?? LlmCatalog::defaultModel($provider);
        }
        return LlmCatalog::supportsTemperature($provider, $model);
    }

    public static function logos(): array
    {
        return [
            'openai'     => '/assets/img/llm-openai.png',
            'mistral'    => '/assets/img/llm-mistral.png',
            'anthropic'  => '/assets/img/llm-anthropic.png',
            'perplexity' => '/assets/img/llm-perplexity.png',
            'gemini'     => '/assets/img/llm-gemini.png',
            'deepseek'   => '/assets/img/llm-deepseek.png',
            'moonshot'   => '/assets/img/llm-moonshot.png',
            'zai'        => '/assets/img/llm-zai.png',
            'local'      => '/assets/img/llm-local.png',
        ];
    }

    /**
     * Catalogue des modèles par fournisseur.
     * Lecture centralisée dans `config/llm_catalog.php` via `LlmCatalog`.
     * Pour ajouter / modifier un modèle : éditer le catalogue, pas ce fichier.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function models(): array
    {
        $out = [];
        foreach (LlmCatalog::all() as $provider => $config) {
            $out[$provider] = $config['models'] ?? [];
        }
        return $out;
    }

    /** Retourne le modèle par défaut d'un fournisseur (lu depuis le catalogue). */
    public static function defaultModel(string $provider): string
    {
        return LlmCatalog::defaultModel($provider);
    }
}
