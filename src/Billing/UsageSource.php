<?php

declare(strict_types=1);

/**
 * Taxonomie des opérations IA journalisées — SOURCE UNIQUE.
 *
 * Chaque ligne du grand livre (`usage_logs`, `tool_usage_logs`) porte une
 * `source` : la NATURE de l'opération (un tour de chat, un titre généré, un
 * embedding de question, la vectorisation d'un document…). Les écrans
 * d'usage ne connaissent pas les sources une à une : ils les regroupent en
 * CATÉGORIES lisibles, elles-mêmes rangées en quatre FAMILLES (les barres du
 * graphique mensuel).
 *
 * Ajouter une opération IA = une constante ici, sa ligne dans SOURCES, et
 * son passage par `UsageMeter`. Libellés, regroupements, unité et exemption
 * de quota suivent partout (pages Usages, exports, quota).
 *
 * Quota : une source « hors quota » est journalisée avec son coût (elle a
 * réellement été payée au fournisseur) mais n'entame pas le quota mensuel
 * de l'utilisateur auquel elle est imputée. C'est le cas de tout ce qu'un
 * gestionnaire déclenche pour la plateforme et non pour lui : alimentation
 * d'une base de connaissances, tests d'outils, recherche de prix. Un
 * créateur qui vectorise 20 documents ne doit pas se retrouver privé de
 * chat pour le reste du mois.
 */
final class UsageSource
{
    // --- Conversations ---------------------------------------------------
    public const CHAT       = 'chat';
    public const REGENERATE = 'regenerate';

    // --- Assistance d'une conversation (appels auxiliaires d'un tour) ----
    public const TITLE        = 'title';        // titre automatique de la conversation
    public const MEMORY       = 'memory';       // juge « souviens-toi que… »
    public const TASK_RULES   = 'task_rules';   // juge des règles d'organisation des tâches
    public const IMAGE_PROMPT = 'image_prompt'; // contextualisation d'une demande d'image
    public const MAIL_ASSIST  = 'mail_assist';  // réécriture d'un brouillon d'email depuis sa carte de relecture
    public const KB_QUERY     = 'kb_query';     // embedding de la question (RAG)
    public const CONTEXT_SUMMARY = 'context_summary'; // résumé du début d'une longue conversation (fenêtre de contexte)
    public const TOOL_CONDENSE   = 'tool_condense';   // condensation d'un résultat d'outil démesuré
    public const WORKFLOW_JUDGE  = 'workflow_judge';  // question d'une condition de workflow (oui / non)
    public const FOLLOW_UP       = 'follow_up';       // suggestion de suite proposée dans la zone de saisie

    // --- Recherche Internet (outil serveur du fournisseur) ---------------
    public const WEB_SEARCH = 'web_search';

    // --- Créations par outil : la part LLM (images/vidéos → tool_usage_logs)
    public const HTML_TOOL         = 'html_tool';
    public const MAP_TOOL          = 'map_tool';
    public const PRESENTATION_TOOL = 'presentation_tool';
    public const NATIVE_DECK       = 'native_deck';      // présentation ou classeur réalisés par le modèle (exécution de code)

    // --- Voix : dictée (minutes écoutées) et lecture à voix haute --------
    public const TRANSCRIBE = 'transcribe'; // transcription Realtime (dictée ET conversation vocale)
    public const SPEECH     = 'speech';     // synthèse vocale de la réponse (conversation vocale)

    // --- Alimentation des bases de connaissances (hors quota) ------------
    public const KB_CHUNKING  = 'kb_chunking';  // découpage sémantique (LLM)
    public const KB_VISION    = 'kb_vision';    // description d'une image (LLM vision)
    public const KB_EMBEDDING = 'kb_embedding'; // vectorisation des chunks

    // --- Conception d'assistants -----------------------------------------
    public const PROMPT_ASSIST = 'prompt_assist';
    public const WORKFLOW_COMPOSE = 'workflow_compose'; // workflow composé par MIA d'après une description

    // --- Administration (hors quota) -------------------------------------
    public const TOOL_TEST     = 'tool_test';
    public const PRICE_REFRESH = 'price_refresh';

    /**
     * Appels auxiliaires d'un TOUR (avant ou après la réponse, sans message
     * encore créé) : rattachés à la réponse une fois persistée, pour que son
     * badge dise tout ce que le tour a coûté (UsageLog::attachTurn). Pas la
     * suggestion de suite ni l'amélioration d'un brouillon : demandées après
     * coup, elles restent au compte de la conversation.
     */
    public const TURN_HELPERS = [
        self::TITLE, self::MEMORY, self::TASK_RULES, self::IMAGE_PROMPT,
        self::KB_QUERY, self::CONTEXT_SUMMARY, self::TOOL_CONDENSE,
    ];

    /** Source par défaut des lignes d'outils (génération demandée au chat). */
    public const TOOL_DEFAULT = 'chat';

    // --- Catégories (regroupement lisible) --------------------------------
    public const CAT_CHAT        = 'chat';
    public const CAT_CHAT_ASSIST = 'chat_assist';
    public const CAT_WEB_SEARCH  = 'web_search';
    public const CAT_CREATION    = 'creation';
    public const CAT_VOICE       = 'voice';
    public const CAT_KNOWLEDGE   = 'knowledge';
    public const CAT_DESIGN      = 'assistant_design';
    public const CAT_ADMIN       = 'admin';

    // --- Familles (barres du graphique mensuel) ---------------------------
    public const FAM_CONVERSATION = 'conversation';
    public const FAM_CREATION     = 'creation';
    public const FAM_KNOWLEDGE    = 'knowledge';
    public const FAM_OPERATIONS   = 'operations';

    /** Unités de la colonne `quantity` (hors tokens). */
    public const UNIT_TOKENS   = 'tokens';
    public const UNIT_SEARCHES = 'searches';
    public const UNIT_MINUTES  = 'minutes';

    /**
     * Registre des sources : libellé, catégorie, unité, exemption de quota.
     * L'ordre est celui des listes déroulantes et des tableaux détaillés.
     */
    private const SOURCES = [
        self::CHAT          => ['label' => 'Réponses de chat',                  'category' => self::CAT_CHAT,        'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::REGENERATE    => ['label' => 'Régénérations',                     'category' => self::CAT_CHAT,        'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::TITLE         => ['label' => 'Titres de conversation',            'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::MEMORY        => ['label' => 'Mémoire « MIA pour vous »',         'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::TASK_RULES    => ['label' => 'Règles d\'organisation des tâches', 'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::IMAGE_PROMPT  => ['label' => 'Préparation de demandes d\'image',  'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::MAIL_ASSIST   => ['label' => 'Amélioration de brouillons d\'email', 'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,  'quota_exempt' => false],
        self::KB_QUERY      => ['label' => 'Embedding des questions (RAG)',     'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::CONTEXT_SUMMARY => ['label' => 'Résumés de longues conversations', 'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::TOOL_CONDENSE => ['label' => 'Condensation de résultats d\'outils', 'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,  'quota_exempt' => false],
        self::WORKFLOW_JUDGE => ['label' => 'Conditions de workflow',           'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::FOLLOW_UP     => ['label' => 'Suggestions de suite',              'category' => self::CAT_CHAT_ASSIST, 'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::WEB_SEARCH    => ['label' => 'Recherches Internet',               'category' => self::CAT_WEB_SEARCH,  'unit' => self::UNIT_SEARCHES, 'quota_exempt' => false],
        self::HTML_TOOL     => ['label' => 'Pages HTML (rédaction)',            'category' => self::CAT_CREATION,    'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::MAP_TOOL      => ['label' => 'Itinéraires (rédaction)',           'category' => self::CAT_CREATION,    'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::PRESENTATION_TOOL => ['label' => 'Présentations (rédaction)',     'category' => self::CAT_CREATION,    'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::NATIVE_DECK   => ['label' => 'Présentations et classeurs réalisés par le modèle', 'category' => self::CAT_CREATION, 'unit' => self::UNIT_TOKENS,  'quota_exempt' => false],
        self::TRANSCRIBE    => ['label' => 'Dictée vocale',                     'category' => self::CAT_VOICE,       'unit' => self::UNIT_MINUTES,  'quota_exempt' => false],
        self::SPEECH        => ['label' => 'Lecture à voix haute',              'category' => self::CAT_VOICE,       'unit' => self::UNIT_MINUTES,  'quota_exempt' => false],
        self::KB_CHUNKING   => ['label' => 'Découpage sémantique',              'category' => self::CAT_KNOWLEDGE,   'unit' => self::UNIT_TOKENS,   'quota_exempt' => true],
        self::KB_VISION     => ['label' => 'Description d\'images',             'category' => self::CAT_KNOWLEDGE,   'unit' => self::UNIT_TOKENS,   'quota_exempt' => true],
        self::KB_EMBEDDING  => ['label' => 'Vectorisation des documents',       'category' => self::CAT_KNOWLEDGE,   'unit' => self::UNIT_TOKENS,   'quota_exempt' => true],
        self::PROMPT_ASSIST => ['label' => 'Rédaction de prompts système',      'category' => self::CAT_DESIGN,      'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::WORKFLOW_COMPOSE => ['label' => 'Composition de workflows',       'category' => self::CAT_DESIGN,      'unit' => self::UNIT_TOKENS,   'quota_exempt' => false],
        self::TOOL_TEST     => ['label' => 'Tests d\'outils',                   'category' => self::CAT_ADMIN,       'unit' => self::UNIT_TOKENS,   'quota_exempt' => true],
        self::PRICE_REFRESH => ['label' => 'Recherche des prix',                'category' => self::CAT_ADMIN,       'unit' => self::UNIT_TOKENS,   'quota_exempt' => true],
    ];

    /** Registre des catégories : libellé, description courte, famille, couleur. */
    private const CATEGORIES = [
        self::CAT_CHAT => [
            'label'       => 'Conversations',
            'description' => 'Réponses et régénérations des assistants (tokens du modèle).',
            'family'      => self::FAM_CONVERSATION,
            'color'       => '#6366f1',
        ],
        self::CAT_CHAT_ASSIST => [
            'label'       => 'Assistance de conversation',
            'description' => 'Appels auxiliaires d\'un tour de chat : titre automatique, mémoire, règles de tâches, préparation d\'image, amélioration d\'un brouillon d\'email depuis sa carte, embedding de la question pour les bases de connaissances, résumé du début d\'une longue conversation, condensation d\'un résultat d\'outil démesuré, suggestion de suite proposée dans la zone de saisie.',
            'family'      => self::FAM_CONVERSATION,
            'color'       => '#8b5cf6',
        ],
        self::CAT_WEB_SEARCH => [
            'label'       => 'Recherche Internet',
            'description' => 'Recherches web exécutées par le fournisseur pendant une réponse (facturées à la recherche, en plus des tokens).',
            'family'      => self::FAM_CONVERSATION,
            'color'       => '#0ea5e9',
        ],
        self::CAT_CREATION => [
            'label'       => 'Créations par outil',
            'description' => 'Images, vidéos, pages HTML, itinéraires, présentations, workflows et présentations Gamma.',
            'family'      => self::FAM_CREATION,
            'color'       => '#f59e0b',
        ],
        self::CAT_VOICE => [
            'label'       => 'Voix',
            'description' => 'Minutes de transcription audio (dictée et conversation vocale, durée rapportée par le navigateur à la fin de chaque session) et minutes de lecture à voix haute des réponses (conversation vocale).',
            'family'      => self::FAM_CONVERSATION,
            'color'       => '#14b8a6',
        ],
        self::CAT_KNOWLEDGE => [
            'label'       => 'Bases de connaissances',
            'description' => 'Alimentation des bases : découpage sémantique, description des images, vectorisation. Imputée au créateur de la base, hors quota.',
            'family'      => self::FAM_KNOWLEDGE,
            'color'       => '#10b981',
        ],
        self::CAT_DESIGN => [
            'label'       => 'Conception d\'assistants',
            'description' => 'Rédaction ou amélioration d\'un prompt système par l\'IA.',
            'family'      => self::FAM_OPERATIONS,
            'color'       => '#ec4899',
        ],
        self::CAT_ADMIN => [
            'label'       => 'Administration',
            'description' => 'Tests d\'outils et recherche des prix sur Internet, déclenchés par un administrateur. Hors quota.',
            'family'      => self::FAM_OPERATIONS,
            'color'       => '#64748b',
        ],
    ];

    private const FAMILIES = [
        self::FAM_CONVERSATION => ['label' => 'Conversations',          'color' => '#6366f1'],
        self::FAM_CREATION     => ['label' => 'Créations par outil',    'color' => '#f59e0b'],
        self::FAM_KNOWLEDGE    => ['label' => 'Bases de connaissances', 'color' => '#10b981'],
        self::FAM_OPERATIONS   => ['label' => 'Conception & admin',     'color' => '#64748b'],
    ];

    /** @return list<string> toutes les sources connues, dans l'ordre d'affichage */
    public static function all(): array
    {
        return array_keys(self::SOURCES);
    }

    public static function isKnown(string $source): bool
    {
        return isset(self::SOURCES[$source]);
    }

    /**
     * Normalise une source venue de l'extérieur (base, POST) : inconnue →
     * `chat`, le défaut historique de la colonne.
     */
    public static function normalize(string $source): string
    {
        $source = trim($source);
        return isset(self::SOURCES[$source]) ? $source : self::CHAT;
    }

    /**
     * Libellé affiché, TRADUIT : les libellés du registre sont des clés de
     * traduction (une constante ne peut pas appeler t()). Même règle pour les
     * catégories et les familles ci-dessous.
     */
    public static function label(string $source): string
    {
        return isset(self::SOURCES[$source]) ? t(self::SOURCES[$source]['label']) : ucfirst(str_replace('_', ' ', $source));
    }

    /** Catégorie d'une source d'`usage_logs` (inconnue → Conversations). */
    public static function category(string $source): string
    {
        return self::SOURCES[$source]['category'] ?? self::CAT_CHAT;
    }

    /**
     * Catégorie d'une ligne de `tool_usage_logs` : une génération demandée
     * au chat est une création ; un test lancé par l'administration reste
     * de l'administration.
     */
    public static function toolCategory(string $source): string
    {
        return $source === self::TOOL_TEST ? self::CAT_ADMIN : self::CAT_CREATION;
    }

    public static function unit(string $source): string
    {
        return self::SOURCES[$source]['unit'] ?? self::UNIT_TOKENS;
    }

    public static function isQuotaExempt(string $source): bool
    {
        return (bool)(self::SOURCES[$source]['quota_exempt'] ?? false);
    }

    /** @return list<string> sources hors quota (pour les clauses SQL) */
    public static function quotaExemptSources(): array
    {
        return array_keys(array_filter(self::SOURCES, static fn(array $s): bool => $s['quota_exempt']));
    }

    /** @return list<string> sources d'une catégorie */
    public static function sourcesOf(string $category): array
    {
        return array_keys(array_filter(self::SOURCES, static fn(array $s): bool => $s['category'] === $category));
    }

    /** @return list<string> catégories dans l'ordre d'affichage */
    public static function categories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function categoryLabel(string $category): string
    {
        return isset(self::CATEGORIES[$category]) ? t(self::CATEGORIES[$category]['label']) : ucfirst($category);
    }

    public static function categoryDescription(string $category): string
    {
        return isset(self::CATEGORIES[$category]) ? t(self::CATEGORIES[$category]['description']) : '';
    }

    public static function categoryColor(string $category): string
    {
        return self::CATEGORIES[$category]['color'] ?? '#64748b';
    }

    public static function categoryFamily(string $category): string
    {
        return self::CATEGORIES[$category]['family'] ?? self::FAM_OPERATIONS;
    }

    /** Une catégorie est hors quota si TOUTES ses sources le sont. */
    public static function isCategoryQuotaExempt(string $category): bool
    {
        $sources = self::sourcesOf($category);
        if ($sources === []) {
            return false;
        }
        foreach ($sources as $source) {
            if (!self::isQuotaExempt($source)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> familles dans l'ordre des barres */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    public static function familyLabel(string $family): string
    {
        return isset(self::FAMILIES[$family]) ? t(self::FAMILIES[$family]['label']) : ucfirst($family);
    }

    public static function familyColor(string $family): string
    {
        return self::FAMILIES[$family]['color'] ?? '#64748b';
    }

    /**
     * Expression SQL `CASE` rendant la catégorie d'une ligne d'`usage_logs`
     * depuis sa colonne `source` — pour les GROUP BY côté base sans
     * recopier la table ici. Les sources sont des constantes de classe,
     * donc sûres à interpoler ; `$column` doit l'être aussi (jamais une
     * saisie).
     */
    public static function sqlCategoryCase(string $column = 'source'): string
    {
        $when = [];
        foreach (self::SOURCES as $source => $meta) {
            $when[] = "WHEN '" . $source . "' THEN '" . $meta['category'] . "'";
        }
        return 'CASE ' . $column . ' ' . implode(' ', $when) . " ELSE '" . self::CAT_CHAT . "' END";
    }

    /** Même expression pour une ligne de `tool_usage_logs` (cf. toolCategory()). */
    public static function sqlToolCategoryCase(string $column = 'source'): string
    {
        return 'CASE WHEN ' . $column . " = '" . self::TOOL_TEST . "' THEN '" . self::CAT_ADMIN . "' ELSE '" . self::CAT_CREATION . "' END";
    }
}
