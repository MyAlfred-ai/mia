<?php

declare(strict_types=1);

/**
 * Lecture unifiée du catalogue d'outils (`config/tools_catalog.php`).
 *
 * Toute l'application (ImageGenerator, VideoGenerator, AdminController,
 * templates admin/tools) passe par cette classe. Pour ajouter ou modifier
 * un modèle image/vidéo, éditer uniquement le catalogue.
 */
class ToolCatalog
{
    private static ?array $cache = null;

    /** Jour imposé par les tests (null = horloge du serveur), cf. setToday(). */
    private static ?string $today = null;

    /** @return array<string, array<string, array<string, mixed>>> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $path = ROOT . '/config/tools_catalog.php';
            $data = is_file($path) ? require $path : [];
            self::$cache = is_array($data) ? $data : [];
        }
        return self::$cache;
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /**
     * Styles graphiques des sites illustrés — liste ordonnée [key, label, directive].
     * Source unique partagée par l'admin (form outils) et le popup chat
     * (window.IMAGE_STYLES). 'directive' = fragment ajouté au prompt image.
     * @return list<array{key:string,label:string,directive:string}>
     */
    public static function imageStyles(): array
    {
        $styles = self::all()['image_styles'] ?? [];
        return is_array($styles) ? array_values($styles) : [];
    }

    /**
     * Liste des providers disponibles pour un type.
     * @return array<string, array<string, mixed>>
     */
    public static function providers(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /** @return array<string, mixed> */
    public static function provider(string $type, string $provider): array
    {
        return self::providers($type)[$provider] ?? [];
    }

    /**
     * Modèles d'un provider sous la forme `[id => metadata]`.
     * @return array<string, array<string, mixed>>
     */
    public static function models(string $type, string $provider): array
    {
        return self::provider($type, $provider)['models'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public static function model(string $type, string $provider, string $model): ?array
    {
        return self::models($type, $provider)[$model] ?? null;
    }

    /**
     * Modèle par défaut d'un provider : celui marqué `default:true` en priorité,
     * sinon `default_model` déclaré au niveau provider, sinon le premier listé.
     */
    public static function defaultModel(string $type, string $provider): string
    {
        foreach (self::models($type, $provider) as $id => $info) {
            if (!empty($info['default'])) {
                return (string)$id;
            }
        }
        $declared = self::provider($type, $provider)['default_model'] ?? '';
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }
        $first = array_key_first(self::models($type, $provider) ?? []);
        return is_string($first) ? $first : '';
    }

    /**
     * URL d'endpoint logique pour un provider. Si l'URL contient `{model}`
     * il est substitué par le nom de modèle passé.
     */
    public static function endpoint(string $type, string $provider, string $endpointKey, ?string $model = null): ?string
    {
        $url = self::provider($type, $provider)['endpoints'][$endpointKey] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        if ($model !== null && str_contains($url, '{model}')) {
            $url = str_replace('{model}', rawurlencode($model), $url);
        }
        return $url;
    }

    /**
     * Clé de routage (endpoint à utiliser) pour un modèle donné.
     */
    public static function routing(string $type, string $provider, string $model): ?string
    {
        $info = self::model($type, $provider, $model);
        if (is_array($info) && !empty($info['routing']) && is_string($info['routing'])) {
            return $info['routing'];
        }
        return null;
    }

    /**
     * Label UI pour un modèle (fallback : l'id).
     */
    public static function label(string $type, string $provider, string $model): string
    {
        $info = self::model($type, $provider, $model);
        if (is_array($info) && isset($info['label']) && is_string($info['label'])) {
            return $info['label'];
        }
        return $model;
    }

    /**
     * Récupère une option de configuration déclarée au niveau provider
     * (ex. sizes, qualities, aspect_ratios, responses_sizes…).
     * @return array<int, string>
     */
    public static function options(string $type, string $provider, string $optionKey): array
    {
        $list = self::provider($type, $provider)[$optionKey] ?? [];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * Options d'un MODÈLE (sizes, qualities…) — SOURCE UNIQUE des valeurs que
     * l'API accepte pour lui. Le bloc du modèle prime ; à défaut, celui du
     * fournisseur. Indispensable depuis GPT Image 2.5 : `standard`/`hd` sont
     * propres à DALL-E, `xhigh`/`max` n'existent que sur les deux modèles 2.5,
     * et une valeur hors liste est refusée par OpenAI — la connaître ici évite
     * de l'envoyer, et évite surtout de recopier ces listes dans le générateur
     * et dans l'écran d'administration.
     *
     * @return list<string>
     */
    public static function modelOptions(string $type, string $provider, string $model, string $optionKey): array
    {
        $info = self::model($type, $provider, $model);
        $list = is_array($info) ? ($info[$optionKey] ?? null) : null;
        if (is_array($list)) {
            return array_values(array_filter($list, 'is_string'));
        }
        return self::options($type, $provider, $optionKey);
    }

    /**
     * True si le modèle est connu du catalogue (utile pour valider les entrées
     * admin / rejeter les modèles obsolètes).
     */
    public static function isKnown(string $type, string $provider, string $model): bool
    {
        return self::model($type, $provider, $model) !== null;
    }

    // ------------------------------------------------------------------
    // Arrêts annoncés par les fournisseurs (bloc `retirement` du catalogue)
    // ------------------------------------------------------------------

    /**
     * Jour courant `AAAA-MM-JJ`, fuseau du serveur. Une coupure datée doit se
     * vérifier la veille ET le jour même : les tests fixent le jour ici.
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
     * Arrêt d'un modèle par son fournisseur — SOURCE UNIQUE (cf. l'en-tête du
     * catalogue). Le bloc du MODÈLE prime ; à défaut, celui du FOURNISSEUR :
     * quand c'est l'API entière qui disparaît, tous ses modèles tombent avec
     * elle, y compris un modèle que le catalogue ne connaît pas.
     *
     * `retired` passe à true le jour même de la date annoncée. Un bloc sans
     * date valide est ignoré — jamais un arrêt deviné (le test du catalogue
     * le signale).
     *
     * @return array{date:string, source_url:string, note:string, retired:bool}|null
     */
    public static function retirement(string $type, string $provider, string $model = ''): ?array
    {
        $bloc = $model !== '' ? (self::model($type, $provider, $model)['retirement'] ?? null) : null;
        if (!is_array($bloc)) {
            $bloc = self::provider($type, $provider)['retirement'] ?? null;
        }
        if (!is_array($bloc)) {
            return null;
        }
        $date = (string)($bloc['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return [
            'date'       => $date,
            'source_url' => (string)($bloc['source_url'] ?? ''),
            'note'       => (string)($bloc['note'] ?? ''),
            'retired'    => $date <= self::today(),
        ];
    }

    /** Modèle (ou fournisseur, sans modèle) arrêté À CE JOUR. Avant la date : false. */
    public static function isRetired(string $type, string $provider, string $model = ''): bool
    {
        return (bool)(self::retirement($type, $provider, $model)['retired'] ?? false);
    }

    /**
     * Ce que lit l'utilisateur quand il sollicite un modèle arrêté : route du
     * chat refusée, génération court-circuitée, test d'outil. Null tant que le
     * modèle est en service — y compris avant la date annoncée.
     */
    public static function retiredNotice(string $type, string $provider, string $model = ''): ?string
    {
        $arret = self::retirement($type, $provider, $model);
        if ($arret === null || !$arret['retired']) {
            return null;
        }

        return t(
            'Ce modèle a été arrêté par son fournisseur le {date} : l’outil ne peut plus rien produire. Prévenez votre administrateur.',
            ['date' => Format::dateFr($arret['date'])]
        );
    }

    /**
     * Modèles proposables à la CRÉATION d'un outil : les modèles arrêtés en
     * sont exclus. La fiche d'un outil existant, elle, affiche toujours son
     * modèle actuel — sans quoi l'enregistrement automatique le changerait en
     * silence.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function offerableModels(string $type, string $provider): array
    {
        if (self::isRetired($type, $provider)) {
            return [];
        }

        return array_filter(
            self::models($type, $provider),
            static fn($id): bool => !self::isRetired($type, $provider, (string)$id),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Fournisseurs proposables à la création : ceux qui gardent au moins un
     * modèle en service. Vide = le type d'outil ne peut plus être créé.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function offerableProviders(string $type): array
    {
        return array_filter(
            self::providers($type),
            static fn($provider): bool => self::offerableModels($type, (string)$provider) !== [],
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Liste tous les ids de modèles d'un provider qui utilisent une clé de routage donnée.
     * @return list<string>
     */
    public static function modelsWithRouting(string $type, string $provider, string $routingKey): array
    {
        $ids = [];
        foreach (self::models($type, $provider) as $id => $info) {
            if (($info['routing'] ?? null) === $routingKey) {
                $ids[] = (string)$id;
            }
        }
        return $ids;
    }
}
