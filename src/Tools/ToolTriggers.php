<?php

declare(strict_types=1);

/**
 * Expressions déclencheuses des OUTILS de génération — source unique.
 *
 * Même architecture que les connecteurs (ConnectorRegistry) : la détection
 * d'intention a quitté le JavaScript. Les listes vivent ici (défauts) et en
 * `settings` (`tool_triggers_<règle>`, personnalisables sur /admin/tools,
 * affichées sur chaque carte d'outil), sont injectées côté chat en
 * `window.TOOL_TRIGGERS` et compilées en regex par tool-suggest.js.
 *
 * DIFFÉRENCE DE MOTEUR avec les connecteurs : une expression de connecteur
 * matche mot à mot (« mon agenda »), alors qu'une demande de génération est
 * une phrase libre (« génère-moi une belle image de chat »). Les mots d'une
 * expression d'outil doivent donc apparaître DANS L'ORDRE mais peuvent être
 * séparés par d'autres mots de la même phrase (« génère une image » matche
 * « génère-moi vite une image »). C'est `toolTriggerToRegex` (tool-suggest.js)
 * qui compile cette tolérance ; ici on ne stocke que des mots lisibles.
 *
 * Les expressions sont portées par la RÈGLE de détection, pas par l'outil :
 * deux outils image partagent la même liste (la suggestion propose ensuite le
 * premier outil dispo du type). La règle `html` couvre les deux types
 * html/html_illustrated. n8n et Gamma n'ont AUCUNE règle — capacités
 * d'assistant, jamais suggérées pendant la frappe (délibéré, testé).
 */
final class ToolTriggers
{
    /** Préfixe des clés settings — clé absente = défauts, valeur vide = suggestion coupée. */
    public const PREFIX = 'tool_triggers_';

    /**
     * Règles de suggestion, DANS L'ORDRE DE PRIORITÉ de tool-suggest.js
     * (vidéo avant image : « génère une vidéo d'un chat » ne doit pas matcher
     * image ; présentation et carte avant leurs voisins génériques).
     * `types` = types d'outils `chat_tools` que la règle sait proposer.
     *
     * @var array<string, array{label: string, types: array<int, string>}>
     */
    public const RULES = [
        'video'        => ['label' => 'Vidéo',        'types' => ['video']],
        'html'         => ['label' => 'Page HTML',    'types' => ['html', 'html_illustrated']],
        'map'          => ['label' => 'Carte / Itinéraire', 'types' => ['map']],
        'image'        => ['label' => 'Image',        'types' => ['image']],
    ];

    /** @return array<int, string> identifiants de règles, ordre = priorité */
    public static function ruleIds(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Libellé AFFICHÉ d'une règle, traduit. Une constante ne peut pas appeler
     * t() : le libellé français de RULES est la clé, traduite ici. Vide pour
     * une règle inconnue.
     */
    public static function label(string $rule): string
    {
        return isset(self::RULES[$rule]) ? t(self::RULES[$rule]['label']) : '';
    }

    /** Règle qui couvre un type d'outil, ou null (n8n, gamma : jamais suggérés). */
    public static function ruleForType(string $type): ?string
    {
        foreach (self::RULES as $rule => $meta) {
            if (in_array($type, $meta['types'], true)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Expressions livrées avec l'application. Curation : jamais un nom
     * générique seul (« image », « site ») — il faut un verbe de création ou
     * un déterminant qui marque la demande, sinon « analyse cette image »
     * déclencherait. Les mots d'une expression peuvent être espacés dans la
     * phrase par le moteur (cf. en-tête).
     *
     * @return array<int, string>
     */
    public static function defaults(string $rule): array
    {
        return match ($rule) {
            'video' => [
                'génère une vidéo', 'crée une vidéo', 'fais une vidéo', 'produis une vidéo',
                'une vidéo de', 'une vidéo montrant', 'un clip de', 'une animation de',
                'filme-moi', 'anime-moi',
            ],
            'html' => [
                'crée une page web', 'fais une page web', 'génère une page web',
                'crée une page html', 'génère une page html', 'code une page',
                'crée un site', 'fais un site', 'développe un site', 'un site vitrine',
                'une landing page', 'une maquette html', 'un formulaire html',
            ],
            'map' => [
                'un itinéraire', 'un road trip', 'un roadtrip',
                'trace un trajet', 'planifie un trajet', 'calcule un trajet', 'prépare un trajet',
                'un parcours de', 'un parcours entre', 'trajet sur une carte', 'parcours sur une carte',
            ],
            'image' => [
                'génère une image', 'crée une image', 'fais une image', 'produis une image',
                'dessine', 'dessine-moi', 'illustre', 'peins-moi', 'croque-moi',
                'une illustration de', 'une image de', 'une image représentant',
                'un logo', 'une affiche', 'un visuel', 'un pictogramme', 'une bannière',
                'un croquis', 'un portrait de',
            ],
            default => [],
        };
    }

    /** Expressions actives d'une règle : personnalisées, sinon défauts. */
    public static function triggers(string $rule): array
    {
        if (!isset(self::RULES[$rule])) {
            return [];
        }
        $stored = Settings::get(self::PREFIX . $rule, null);
        // null = jamais personnalisé ; '' = suggestion volontairement coupée.
        if ($stored === null) {
            return self::defaults($rule);
        }
        return ConnectorRegistry::sanitizeTriggerList((string)$stored);
    }

    /** L'administrateur a-t-il remplacé la liste par défaut ? */
    public static function triggersAreCustom(string $rule): bool
    {
        return Settings::get(self::PREFIX . $rule, null) !== null;
    }

    /**
     * Enregistre la saisie de l'admin et renvoie la liste retenue.
     * Saisir exactement les défauts efface la personnalisation (la liste
     * suivra les améliorations futures des défauts) — même règle que les
     * connecteurs, même normalisation (`sanitizeTriggerList`, source unique).
     */
    public static function setTriggers(string $rule, string $raw): array
    {
        if (!isset(self::RULES[$rule])) {
            return [];
        }
        $list = ConnectorRegistry::sanitizeTriggerList($raw);
        Settings::set(
            self::PREFIX . $rule,
            $list === self::defaults($rule) ? null : implode("\n", $list)
        );
        return $list;
    }

    /** Retour explicite aux défauts (bouton « Réinitialiser »). */
    public static function resetTriggers(string $rule): array
    {
        if (isset(self::RULES[$rule])) {
            Settings::set(self::PREFIX . $rule, null);
        }
        return self::defaults($rule);
    }

    /**
     * Toutes les listes actives, pour l'injection côté chat
     * (`window.TOOL_TRIGGERS`, lu par tool-suggest.js).
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ruleIds() as $rule) {
            $out[$rule] = self::triggers($rule);
        }
        return $out;
    }
}
