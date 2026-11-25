<?php

declare(strict_types=1);

/**
 * Historique des usages d'une personne — SOURCE UNIQUE
 * (Réglages → Usage & crédits, onglet « Historique »).
 *
 * Le problème résolu : la page Usage & crédits ne parlait que du mois en
 * cours. Le grand livre (`usage_logs`, `tool_usage_logs`) garde pourtant tout,
 * depuis toujours : rien ne le purge. Ici, on le relit pour UNE personne, sur
 * la période de son choix (UsagePeriod, le sélecteur partagé avec les écrans
 * Coûts), filtré par ORIGINE (conversations, workflows, tâches programmées,
 * Claude dans Office, bases…), par TYPE d'usage (réponses, recherches, images,
 * pages web, voix…), par modèle d'IA, par assistant, et par une recherche
 * libre (titre de conversation, workflow, tâche, assistant, outil, modèle…).
 *
 * Trois parties :
 *   1. le CATALOGUE — origines, types, libellé d'une opération au singulier ;
 *   2. les FORMES PURES — filtres lus dans l'adresse, SQL des filtres et de la
 *      recherche, facettes, lignes du journal, histogramme (testables sans base) ;
 *   3. les LECTURES — une personne, une période : facettes, série par jour,
 *      totaux, page du journal, lignes de l'export.
 *
 * UNE opération du journal = toutes les lignes du grand livre d'un même
 * message (la réponse, ses recherches Internet, le titre de la conversation,
 * la recherche dans les bases, l'image générée…) : ce que dit l'étiquette sous
 * la réponse. Une ligne sans message est une opération à elle seule. Les
 * filtres portent sur les LIGNES : « Recherche Internet » montre les messages
 * qui en ont fait, avec les seuls crédits de leurs recherches.
 *
 * L'ORIGINE workflow / tâche programmée est figée à l'écriture
 * (`SQL_STORED_ORIGIN`, colonne `origin`, migration 105) : la conversation qui
 * la disait peut disparaître (élagage, suppression), l'opération reste rangée
 * au bon endroit. Le reste se déduit de la source.
 *
 * AUCUN euro : la personne raisonne en crédits, comme son quota.
 */
final class UsageHistory
{
    // =====================================================================
    // 1. Catalogue
    // =====================================================================

    public const ORIGIN_CHAT      = 'chat';
    public const ORIGIN_WORKFLOW  = 'workflow';
    public const ORIGIN_TASK      = 'task';
    public const ORIGIN_OFFICE    = 'office';
    public const ORIGIN_KNOWLEDGE = 'knowledge';
    public const ORIGIN_OTHER     = 'other';

    /**
     * Origine d'une opération lue sur sa conversation AU MOMENT de l'écrire,
     * pour la colonne `origin` (UsageLog::insertLlm / insertTool) : 'workflow',
     * 'task' ou ''. Un seul paramètre, l'identifiant de la conversation.
     */
    public const SQL_STORED_ORIGIN = "COALESCE((SELECT CASE WHEN c.workflow_run_id IS NOT NULL THEN 'workflow'"
        . " WHEN c.scheduled_task_id IS NOT NULL THEN 'task' ELSE '' END FROM conversations c WHERE c.id = ?), '')";

    /**
     * Origines, dans l'ordre d'affichage (et d'empilement de l'histogramme).
     * Textes français = clés de traduction : lus par originLabel() / originHint().
     */
    private const ORIGINS = [
        self::ORIGIN_CHAT      => ['label' => 'Conversations',          'color' => '#6366f1', 'hint' => 'Vos messages dans le chat et ce qu’ils ont déclenché : réponses, recherches, créations, voix.'],
        self::ORIGIN_WORKFLOW  => ['label' => 'Workflows',              'color' => '#0ea5e9', 'hint' => 'Les étapes, questions et décisions de vos workflows.'],
        self::ORIGIN_TASK      => ['label' => 'Tâches programmées',     'color' => '#f59e0b', 'hint' => 'Les exécutions de vos tâches programmées.'],
        self::ORIGIN_OFFICE    => ['label' => 'Claude dans Office',     'color' => '#d97757', 'hint' => 'Les demandes de l’add-in Claude dans Excel, Word et PowerPoint.'],
        self::ORIGIN_KNOWLEDGE => ['label' => 'Bases de connaissances', 'color' => '#10b981', 'hint' => 'L’alimentation de vos bases : découpage, description des images, vectorisation.'],
        self::ORIGIN_OTHER     => ['label' => 'Autres',                 'color' => '#94a3b8', 'hint' => 'Conception d’assistants, mémoire automatique, administration.'],
    ];

    /**
     * Types d'usage, dans l'ordre du sélecteur. `llm` = sources d'`usage_logs`,
     * `tool` = types d'outil de `tool_usage_logs` (`*` = tout autre type ;
     * un test d'outil est toujours de l'administration). Chaque ligne du grand
     * livre a UN type — testé : aucune source orpheline, aucune en double.
     */
    private const TYPES = [
        'answer'       => ['label' => 'Réponses',                        'category' => UsageSource::CAT_CHAT,        'llm' => [UsageSource::CHAT, UsageSource::REGENERATE]],
        'web'          => ['label' => 'Recherche Internet',              'category' => UsageSource::CAT_WEB_SEARCH,  'llm' => [UsageSource::WEB_SEARCH, UsageSource::OFFICE_SEARCH]],
        'assist'       => ['label' => 'Assistance de conversation',      'category' => UsageSource::CAT_CHAT_ASSIST, 'llm' => [
            UsageSource::TITLE, UsageSource::MEMORY, UsageSource::TASK_RULES, UsageSource::IMAGE_PROMPT,
            UsageSource::MAIL_ASSIST, UsageSource::KB_QUERY, UsageSource::CONTEXT_SUMMARY, UsageSource::TOOL_CONDENSE,
            UsageSource::TABLE_ENRICH,
            UsageSource::WORKFLOW_JUDGE, UsageSource::FOLLOW_UP, UsageSource::MEMORY_LEARN,
        ]],
        'image'        => ['label' => 'Images',                          'category' => UsageSource::CAT_CREATION,    'tool' => ['image']],
        'video'        => ['label' => 'Vidéos',                          'category' => UsageSource::CAT_CREATION,    'tool' => ['video']],
        'page'         => ['label' => 'Pages web',                       'category' => UsageSource::CAT_CREATION,    'llm' => [UsageSource::HTML_TOOL], 'tool' => ['html', 'html_illustrated']],
        'map'          => ['label' => 'Itinéraires',                     'category' => UsageSource::CAT_CREATION,    'llm' => [UsageSource::MAP_TOOL], 'tool' => ['map']],
        'presentation' => ['label' => 'Présentations',                   'category' => UsageSource::CAT_CREATION,    'llm' => [UsageSource::PRESENTATION_TOOL], 'tool' => ['presentation', 'gamma']],
        'file'         => ['label' => 'Fichiers réalisés par le modèle', 'category' => UsageSource::CAT_CREATION,    'llm' => [UsageSource::NATIVE_DECK]],
        'automation'   => ['label' => 'Automatisations n8n',             'category' => UsageSource::CAT_CREATION,    'tool' => ['n8n']],
        'tool'         => ['label' => 'Autres outils',                   'category' => UsageSource::CAT_CREATION,    'tool' => ['*']],
        'voice'        => ['label' => 'Voix',                            'category' => UsageSource::CAT_VOICE,       'llm' => [UsageSource::TRANSCRIBE, UsageSource::SPEECH]],
        'office'       => ['label' => 'Demandes dans Office',            'category' => UsageSource::CAT_OFFICE,      'llm' => [UsageSource::OFFICE_ADDIN]],
        'knowledge'    => ['label' => 'Bases de connaissances',          'category' => UsageSource::CAT_KNOWLEDGE,   'llm' => [UsageSource::KB_CHUNKING, UsageSource::KB_VISION, UsageSource::KB_EMBEDDING]],
        'design'       => ['label' => 'Conception d’assistants',         'category' => UsageSource::CAT_DESIGN,      'llm' => [UsageSource::PROMPT_ASSIST, UsageSource::WORKFLOW_COMPOSE]],
        'admin'        => ['label' => 'Administration',                  'category' => UsageSource::CAT_ADMIN,       'llm' => [UsageSource::TOOL_TEST, UsageSource::PRICE_REFRESH]],
    ];

    /**
     * Ce qui nomme une opération de plusieurs lignes : la réponse avant ses
     * recherches et son titre, la page web avant ses illustrations, l'image
     * avant la préparation de sa demande.
     */
    private const PRIMARY = [
        'answer', 'office', 'page', 'map', 'presentation', 'file', 'video', 'image',
        'automation', 'tool', 'voice', 'design', 'knowledge', 'admin', 'web', 'assist',
    ];

    /** Une opération, au singulier (le journal) — clés de traduction. */
    private const OPERATION_LABELS = [
        UsageSource::CHAT              => 'Réponse',
        UsageSource::REGENERATE        => 'Réponse régénérée',
        UsageSource::OFFICE_ADDIN      => 'Demande dans Office',
        UsageSource::OFFICE_SEARCH     => 'Recherche Internet dans Office',
        UsageSource::TITLE             => 'Titre de la conversation',
        UsageSource::MEMORY            => 'Mise à jour de la mémoire',
        UsageSource::TASK_RULES        => 'Règle d’organisation des tâches',
        UsageSource::IMAGE_PROMPT      => 'Préparation d’une image',
        UsageSource::MAIL_ASSIST       => 'Amélioration d’un email',
        UsageSource::KB_QUERY          => 'Recherche dans les bases',
        UsageSource::CONTEXT_SUMMARY   => 'Résumé d’une longue conversation',
        UsageSource::TOOL_CONDENSE     => 'Condensation d’un résultat',
        UsageSource::TABLE_ENRICH      => 'Enrichissement d’une table',
        UsageSource::WORKFLOW_JUDGE    => 'Question d’une condition',
        UsageSource::FOLLOW_UP         => 'Suggestion de suite',
        UsageSource::MEMORY_LEARN      => 'Apprentissage de la mémoire',
        UsageSource::WEB_SEARCH        => 'Recherche Internet',
        UsageSource::HTML_TOOL         => 'Page web',
        UsageSource::MAP_TOOL          => 'Itinéraire',
        UsageSource::PRESENTATION_TOOL => 'Présentation',
        UsageSource::NATIVE_DECK       => 'Version soignée d’un fichier',
        UsageSource::TRANSCRIBE        => 'Dictée',
        UsageSource::SPEECH            => 'Lecture à voix haute',
        UsageSource::KB_CHUNKING       => 'Découpage d’un document',
        UsageSource::KB_VISION         => 'Description d’une image',
        UsageSource::KB_EMBEDDING      => 'Vectorisation de documents',
        UsageSource::PROMPT_ASSIST     => 'Rédaction d’un prompt',
        UsageSource::WORKFLOW_COMPOSE  => 'Composition d’un workflow',
        UsageSource::TOOL_TEST         => 'Test d’outil',
        UsageSource::PRICE_REFRESH     => 'Recherche des prix',
    ];

    /** Une génération d'outil, au singulier. */
    private const TOOL_LABELS = [
        'image'            => 'Image',
        'video'            => 'Vidéo',
        'html'             => 'Page web',
        'html_illustrated' => 'Site illustré',
        'map'              => 'Itinéraire',
        'presentation'     => 'Présentation',
        'n8n'              => 'Automatisation n8n',
        'gamma'            => 'Présentation Gamma',
    ];

    /** Pictogramme de chaque type (tracés SVG 24×24, trait), et de chaque origine. */
    private const ICONS = [
        'answer'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'web'          => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'assist'       => '<path d="M12 3l1.8 4.6L18 9l-4.2 1.4L12 15l-1.8-4.6L6 9l4.2-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'image'        => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'video'        => '<rect x="2" y="5" width="15" height="14" rx="2"/><polygon points="22 7 17 10.5 22 14 22 7"/>',
        'page'         => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><circle cx="6" cy="6.5" r=".5"/><circle cx="8.5" cy="6.5" r=".5"/>',
        'map'          => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
        'presentation' => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'file'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/>',
        'automation'   => '<circle cx="5" cy="12" r="2.5"/><circle cx="19" cy="6" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="M7.5 12h4l5-5.2M11.5 12l5 5.2"/>',
        'tool'         => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'voice'        => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/>',
        'office'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/>',
        'knowledge'    => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'design'       => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/>',
        'admin'        => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    ];

    /** Valeur du filtre assistant pour « Sans assistant ». */
    public const NO_PROFILE = 'aucun';

    /** Opérations par page du journal. */
    public const PAGE = 30;

    /** Lignes au plus dans un export (le reste se demande sur une période plus courte). */
    public const EXPORT_MAX = 20000;

    /** Longueur maximale de la recherche, et mots retenus au plus. */
    public const SEARCH_MAX = 80;
    public const SEARCH_WORDS = 6;

    /** Modèles et assistants proposés au plus dans les sélecteurs (les plus utilisés). */
    public const OPTIONS_MAX = 40;

    /** @return list<string> */
    public static function origins(): array
    {
        return array_keys(self::ORIGINS);
    }

    public static function isOrigin(string $key): bool
    {
        return isset(self::ORIGINS[$key]);
    }

    public static function originLabel(string $key): string
    {
        return isset(self::ORIGINS[$key]) ? t(self::ORIGINS[$key]['label']) : '';
    }

    public static function originHint(string $key): string
    {
        return isset(self::ORIGINS[$key]) ? t(self::ORIGINS[$key]['hint']) : '';
    }

    public static function originColor(string $key): string
    {
        return self::ORIGINS[$key]['color'] ?? '#94a3b8';
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isType(string $key): bool
    {
        return isset(self::TYPES[$key]);
    }

    public static function typeLabel(string $key): string
    {
        return isset(self::TYPES[$key]) ? t(self::TYPES[$key]['label']) : '';
    }

    /** Couleur d'un type = celle de sa catégorie (les anneaux du mois). */
    public static function typeColor(string $key): string
    {
        return UsageSource::categoryColor(self::TYPES[$key]['category'] ?? UsageSource::CAT_CHAT);
    }

    public static function typeCategory(string $key): string
    {
        return self::TYPES[$key]['category'] ?? UsageSource::CAT_CHAT;
    }

    /** SVG d'un type (ou d'une origine sans type homonyme), `currentColor`. */
    public static function icon(string $key, int $size = 16): string
    {
        $paths = self::ICONS[$key] ?? match ($key) {
            self::ORIGIN_CHAT     => self::ICONS['answer'],
            self::ORIGIN_WORKFLOW => Workflow::ICON_PATHS,
            self::ORIGIN_TASK     => ScheduledTask::ICON_PATHS,
            default               => self::ICONS['tool'],
        };
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    /** Type d'une ligne du grand livre (la même règle que `sqlType`). */
    public static function typeOf(string $kind, string $source, string $toolType = ''): string
    {
        if ($kind === 'tool') {
            if ($source === UsageSource::TOOL_TEST) {
                return 'admin';
            }
            foreach (self::TYPES as $key => $type) {
                if (in_array($toolType, $type['tool'] ?? [], true)) {
                    return $key;
                }
            }
            return 'tool';
        }
        foreach (self::TYPES as $key => $type) {
            if (in_array($source, $type['llm'] ?? [], true)) {
                return $key;
            }
        }
        return 'answer';
    }

    /**
     * Origine d'une ligne (la même règle que `sqlOrigin`) : Office et les
     * bases se lisent sur la source ; le workflow et la tâche sur la valeur
     * figée à l'écriture ; une ligne rattachée à une conversation vient du
     * chat ; sans conversation (supprimée), la source tranche.
     */
    public static function originOf(string $kind, string $source, string $stored, bool $hasConversation): string
    {
        $fixed = $stored === self::ORIGIN_WORKFLOW || $stored === self::ORIGIN_TASK;
        if ($kind === 'tool') {
            if ($fixed) {
                return $stored;
            }
            if ($source === UsageSource::TOOL_TEST) {
                return self::ORIGIN_OTHER;
            }
            return $hasConversation || $source === UsageSource::TOOL_DEFAULT ? self::ORIGIN_CHAT : self::ORIGIN_OTHER;
        }
        if (in_array($source, UsageSource::OFFICE_SOURCES, true)) {
            return self::ORIGIN_OFFICE;
        }
        if ($fixed) {
            return $stored;
        }
        $bySource = self::sourceOrigin($source);
        if ($bySource === self::ORIGIN_WORKFLOW || $bySource === self::ORIGIN_KNOWLEDGE) {
            return $bySource;
        }
        if ($hasConversation) {
            return self::ORIGIN_CHAT;
        }
        return $bySource === self::ORIGIN_OTHER ? self::ORIGIN_OTHER : self::ORIGIN_CHAT;
    }

    /** Origine d'une source d'`usage_logs` quand rien d'autre ne la dit. */
    private static function sourceOrigin(string $source): string
    {
        if (in_array($source, UsageSource::OFFICE_SOURCES, true)) {
            return self::ORIGIN_OFFICE;
        }
        if (in_array($source, [UsageSource::WORKFLOW_COMPOSE, UsageSource::WORKFLOW_JUDGE], true)) {
            return self::ORIGIN_WORKFLOW;
        }
        if (in_array($source, self::TYPES['knowledge']['llm'], true)) {
            return self::ORIGIN_KNOWLEDGE;
        }
        if (in_array($source, [UsageSource::MEMORY_LEARN, UsageSource::PROMPT_ASSIST, UsageSource::TOOL_TEST, UsageSource::PRICE_REFRESH], true)) {
            return self::ORIGIN_OTHER;
        }
        return self::ORIGIN_CHAT;
    }

    /** Une opération au singulier : « Réponse », « Image », « Recherche Internet ». */
    public static function operationLabel(string $kind, string $source, string $toolType = ''): string
    {
        if ($kind === 'tool') {
            if ($source === UsageSource::TOOL_TEST) {
                return t(self::OPERATION_LABELS[UsageSource::TOOL_TEST]);
            }
            return t(self::TOOL_LABELS[$toolType] ?? 'Utilisation d’un outil');
        }
        return isset(self::OPERATION_LABELS[$source]) ? t(self::OPERATION_LABELS[$source]) : UsageSource::label($source);
    }

    /** @return list<string> sources nommées au singulier (garde de complétude). */
    public static function labelledSources(): array
    {
        return array_keys(self::OPERATION_LABELS);
    }

    /**
     * Nom lisible d'un modèle : celui du catalogue (« GPT-5.5 »), sinon ce à
     * quoi il a servi (dictée, lecture à voix haute, recherche dans les bases),
     * sinon son identifiant.
     */
    public static function modelLabel(string $provider, string $model): string
    {
        $m = $model !== '' && $provider !== '' ? LlmCatalog::model($provider, $model) : null;
        if ($m !== null && !empty($m['label'])) {
            return (string)$m['label'];
        }
        return match (true) {
            str_contains($model, 'whisper') || str_contains($model, 'transcribe') || str_contains($model, 'voxtral') => t('Dictée vocale'),
            str_contains($model, 'tts') || str_contains($model, 'speech')                                            => t('Lecture à voix haute'),
            str_contains($model, 'embed')                                                                             => t('Recherche dans les bases'),
            default => $model !== '' ? $model : ($provider !== '' ? ucfirst($provider) : ''),
        };
    }

    // =====================================================================
    // 2. Formes pures
    // =====================================================================

    /**
     * Filtres lus dans l'adresse (`origine`, `type`, `ia`, `assistant`, `q`).
     * Une valeur inconnue est ignorée, jamais une erreur.
     *
     * @param array<string,mixed> $query typiquement $_GET
     * @return array{origin:string, type:string, model:string, profile:string, q:string}
     */
    public static function filters(array $query): array
    {
        $origin  = (string)($query['origine'] ?? '');
        $type    = (string)($query['type'] ?? '');
        $profile = trim((string)($query['assistant'] ?? ''));
        return [
            'origin'  => self::isOrigin($origin) ? $origin : '',
            'type'    => self::isType($type) ? $type : '',
            'model'   => self::validModelKey((string)($query['ia'] ?? '')),
            'profile' => $profile === self::NO_PROFILE || (ctype_digit($profile) && (int)$profile > 0) ? $profile : '',
            'q'       => self::cleanSearch((string)($query['q'] ?? '')),
        ];
    }

    /** Paramètres d'adresse qui redonnent les filtres (vides omis). @return array<string,string> */
    public static function query(array $filters): array
    {
        return array_filter([
            'origine'   => (string)($filters['origin'] ?? ''),
            'type'      => (string)($filters['type'] ?? ''),
            'ia'        => (string)($filters['model'] ?? ''),
            'assistant' => (string)($filters['profile'] ?? ''),
            'q'         => (string)($filters['q'] ?? ''),
        ], static fn(string $v): bool => $v !== '');
    }

    /** Vrai si au moins un filtre (hors période) est posé. */
    public static function isFiltered(array $filters): bool
    {
        return self::query($filters) !== [];
    }

    /** « fournisseur::modèle » valide, sinon ''. */
    public static function validModelKey(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) > 200 || preg_match('/^([a-z0-9_-]{1,40})::([^\x00-\x1F\x7F]{1,160})$/', $raw) !== 1) {
            return '';
        }
        return $raw;
    }

    /** Recherche libre : une ligne, sans caractère de contrôle, bornée. */
    public static function cleanSearch(string $raw): string
    {
        $raw = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? '';
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        return mb_substr($raw, 0, self::SEARCH_MAX);
    }

    /** Minuscules sans accents, pour comparer une recherche aux libellés. */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
            '’' => "'",
        ]);
    }

    /** @return list<string> mots de la recherche (2 caractères au moins). */
    public static function searchWords(string $q): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', self::cleanSearch($q)) ?: [] as $w) {
            $w = trim($w, " \t\"'«»“”.,;:!?()");
            if (mb_strlen($w) >= 2 && !in_array($w, $words, true)) {
                $words[] = $w;
            }
        }
        return array_slice($words, 0, self::SEARCH_WORDS);
    }

    /**
     * Ce que désigne un mot de la recherche, au-delà des textes de la base :
     * les types, origines, opérations et modèles dont le LIBELLÉ affiché le
     * contient (« image » trouve les images, « opus » le modèle Claude Opus
     * dont l'identifiant ne dit pas « Opus »).
     *
     * @param list<string> $modelKeys clés « fournisseur::modèle » connues de la période
     * @return array{types:list<string>, origins:list<string>, sources:list<string>, tools:list<string>, models:list<string>}
     */
    public static function labelMatches(string $word, array $modelKeys = []): array
    {
        $w = self::fold($word);
        $has = static fn(string $label): bool => $label !== '' && str_contains(self::fold($label), $w);
        $out = ['types' => [], 'origins' => [], 'sources' => [], 'tools' => [], 'models' => []];
        foreach (self::TYPES as $key => $_) {
            if ($has(self::typeLabel($key))) {
                $out['types'][] = $key;
            }
        }
        foreach (self::ORIGINS as $key => $_) {
            if ($has(self::originLabel($key))) {
                $out['origins'][] = $key;
            }
        }
        foreach (self::OPERATION_LABELS as $source => $label) {
            if ($has(t($label))) {
                $out['sources'][] = $source;
            }
        }
        foreach (self::TOOL_LABELS as $toolType => $label) {
            if ($has(t($label))) {
                $out['tools'][] = $toolType;
            }
        }
        foreach ($modelKeys as $key) {
            [$provider, $model] = explode('::', $key, 2) + [1 => ''];
            if ($has(self::modelLabel($provider, $model))) {
                $out['models'][] = $key;
            }
        }
        return $out;
    }

    /** Échappe un mot pour LIKE … ESCAPE '!'. */
    public static function likePattern(string $word): string
    {
        return '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * Clause WHERE des filtres, sur la sous-requête `x` (lignes ramenées à un
     * même schéma). `$except` = filtres à ignorer (facettes).
     *
     * @param list<string> $modelKeys modèles connus, pour la recherche par libellé
     * @param list<string> $except    'origin' | 'type' | 'model' | 'profile' | 'q'
     * @return array{0:string, 1:list<mixed>} ['' ou 'WHERE …', paramètres]
     */
    public static function whereSql(array $filters, array $modelKeys = [], array $except = []): array
    {
        $parts  = [];
        $params = [];
        if (($filters['origin'] ?? '') !== '' && !in_array('origin', $except, true)) {
            $parts[]  = 'x.origin = ?';
            $params[] = $filters['origin'];
        }
        if (($filters['type'] ?? '') !== '' && !in_array('type', $except, true)) {
            $parts[]  = 'x.type = ?';
            $params[] = $filters['type'];
        }
        if (($filters['model'] ?? '') !== '' && !in_array('model', $except, true)) {
            [$provider, $model] = explode('::', (string)$filters['model'], 2);
            $parts[]  = "x.kind = 'llm' AND x.provider = ? AND x.model = ?";
            $params[] = $provider;
            $params[] = $model;
        }
        if (($filters['profile'] ?? '') !== '' && !in_array('profile', $except, true)) {
            if ($filters['profile'] === self::NO_PROFILE) {
                $parts[] = 'x.profile_id IS NULL';
            } else {
                $parts[]  = 'x.profile_id = ?';
                $params[] = (int)$filters['profile'];
            }
        }
        if (($filters['q'] ?? '') !== '' && !in_array('q', $except, true)) {
            foreach (self::searchWords((string)$filters['q']) as $word) {
                $like = self::likePattern($word);
                $or = [];
                foreach (['conv_title', 'profile_name', 'workflow_name', 'task_name', 'tool_name', 'kb_name', 'model', 'provider'] as $col) {
                    $or[]     = "x.$col LIKE ? ESCAPE '!'";
                    $params[] = $like;
                }
                $m = self::labelMatches($word, $modelKeys);
                foreach (['types' => 'x.type', 'origins' => 'x.origin'] as $what => $col) {
                    if ($m[$what] !== []) {
                        $or[]   = $col . ' IN (' . implode(',', array_fill(0, count($m[$what]), '?')) . ')';
                        $params = array_merge($params, $m[$what]);
                    }
                }
                if ($m['sources'] !== []) {
                    $or[]   = "(x.kind = 'llm' AND x.source IN (" . implode(',', array_fill(0, count($m['sources']), '?')) . '))';
                    $params = array_merge($params, $m['sources']);
                }
                if ($m['tools'] !== []) {
                    $or[]   = "(x.kind = 'tool' AND x.tool_type IN (" . implode(',', array_fill(0, count($m['tools']), '?')) . '))';
                    $params = array_merge($params, $m['tools']);
                }
                if ($m['models'] !== []) {
                    $or[]   = "(x.kind = 'llm' AND CONCAT(x.provider, '::', x.model) IN (" . implode(',', array_fill(0, count($m['models']), '?')) . '))';
                    $params = array_merge($params, $m['models']);
                }
                $parts[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        return [$parts !== [] ? 'WHERE ' . implode(' AND ', array_map(static fn(string $p): string => "($p)", $parts)) : '', $params];
    }

    /** Expression SQL de l'origine d'une ligne (`$a` = alias de la table). */
    public static function sqlOrigin(string $kind, string $a): string
    {
        if ($kind === 'tool') {
            return "CASE WHEN $a.origin IN ('workflow', 'task') THEN $a.origin"
                . " WHEN $a.source = '" . UsageSource::TOOL_TEST . "' THEN 'other'"
                . " WHEN $a.conversation_id IS NOT NULL OR $a.source = '" . UsageSource::TOOL_DEFAULT . "' THEN 'chat'"
                . " ELSE 'other' END";
        }
        $bySource = [];
        foreach (UsageSource::all() as $source) {
            $bySource[self::sourceOrigin($source)][] = $source;
        }
        $in = static fn(array $sources): string => "'" . implode("', '", $sources) . "'";
        $sql = "CASE WHEN $a.source IN (" . $in(UsageSource::OFFICE_SOURCES) . ") THEN 'office'"
            . " WHEN $a.origin IN ('workflow', 'task') THEN $a.origin";
        foreach ([self::ORIGIN_WORKFLOW, self::ORIGIN_KNOWLEDGE] as $origin) {
            if (!empty($bySource[$origin])) {
                $sql .= " WHEN $a.source IN (" . $in($bySource[$origin]) . ") THEN '$origin'";
            }
        }
        return $sql . " WHEN $a.conversation_id IS NOT NULL THEN 'chat'"
            . (!empty($bySource[self::ORIGIN_OTHER]) ? " WHEN $a.source IN (" . $in($bySource[self::ORIGIN_OTHER]) . ") THEN 'other'" : '')
            . " ELSE 'chat' END";
    }

    /** Expression SQL du type d'une ligne (`$a` = alias de la table). */
    public static function sqlType(string $kind, string $a): string
    {
        if ($kind === 'tool') {
            $sql = "CASE WHEN $a.source = '" . UsageSource::TOOL_TEST . "' THEN 'admin'";
            foreach (self::TYPES as $key => $type) {
                $tools = array_values(array_filter($type['tool'] ?? [], static fn(string $t): bool => $t !== '*'));
                if ($tools !== []) {
                    $sql .= " WHEN $a.tool_type IN ('" . implode("', '", $tools) . "') THEN '$key'";
                }
            }
            return $sql . " ELSE 'tool' END";
        }
        $sql = "CASE $a.source";
        foreach (self::TYPES as $key => $type) {
            foreach ($type['llm'] ?? [] as $source) {
                $sql .= " WHEN '$source' THEN '$key'";
            }
        }
        return $sql . " ELSE 'answer' END";
    }

    /**
     * Lignes d'une table, ramenées à un même schéma pour l'union. Trois
     * paramètres : personne, début, fin (inclus). `$labels` ajoute les noms
     * (conversation, workflow, tâche, outil, base) — pour le journal et la
     * recherche ; sans eux, les agrégats ne font aucune jointure de plus.
     * Un nom n'est rendu que s'il appartient à la personne (ou, pour une
     * base, qu'elle peut la voir).
     */
    public static function linesSql(string $kind, bool $labels): string
    {
        $a     = $kind === 'tool' ? 't' : 'l';
        $table = $kind === 'tool' ? 'tool_usage_logs' : 'usage_logs';
        $cols = $kind === 'tool'
            ? "'tool' AS kind, t.id, IF(t.message_id IS NULL, CONCAT('t', t.id), CONCAT('m', t.message_id)) AS grp,
               t.created_at, t.source, t.tool_type, '' AS provider, '' AS model,
               t.credits_used AS credits, t.quota_exempt, 0 AS tokens, t.quantity_units AS quantity,
               t.conversation_id, t.profile_id, p.name AS profile_name, p.color AS profile_color, NULL AS kb_id"
            : "'llm' AS kind, l.id, IF(l.message_id IS NULL, CONCAT('l', l.id), CONCAT('m', l.message_id)) AS grp,
               l.created_at, l.source, '' AS tool_type,
               SUBSTRING_INDEX(l.llm_provider, '::', 1) AS provider,
               COALESCE(NULLIF(l.model, ''), IF(LOCATE('::', l.llm_provider) > 0, SUBSTRING_INDEX(l.llm_provider, '::', -1), '')) AS model,
               l.credits_used AS credits, l.quota_exempt, (l.tokens_input + l.tokens_output) AS tokens, l.quantity,
               l.conversation_id, l.profile_id, p.name AS profile_name, p.color AS profile_color, l.kb_id";
        $cols .= ', ' . self::sqlOrigin($kind, $a) . ' AS origin, ' . self::sqlType($kind, $a) . ' AS type';
        $joins = "LEFT JOIN profiles p ON p.id = $a.profile_id";
        if ($labels) {
            $cols .= ", c.title AS conv_title, c.workflow_run_id AS run_id, w.id AS workflow_id, w.name AS workflow_name,
                       w.color AS workflow_color, st.id AS task_id, st.name AS task_name";
            $cols .= $kind === 'tool'
                ? ', ct.name AS tool_name, NULL AS kb_name'
                : ', NULL AS tool_name, IF(kb.is_private = 1 AND (kb.created_by IS NULL OR kb.created_by <> l.user_id), NULL, kb.name) AS kb_name';
            $joins .= " LEFT JOIN conversations c ON c.id = $a.conversation_id AND c.user_id = $a.user_id
                        LEFT JOIN workflow_runs wr ON wr.id = c.workflow_run_id
                        LEFT JOIN workflows w ON w.id = wr.workflow_id AND w.user_id = $a.user_id
                        LEFT JOIN scheduled_tasks st ON st.id = c.scheduled_task_id AND st.user_id = $a.user_id";
            $joins .= $kind === 'tool'
                ? ' LEFT JOIN chat_tools ct ON ct.id = t.tool_id'
                : ' LEFT JOIN knowledge_bases kb ON kb.id = l.kb_id';
        } else {
            $cols .= ', NULL AS conv_title, NULL AS run_id, NULL AS workflow_id, NULL AS workflow_name,
                       NULL AS workflow_color, NULL AS task_id, NULL AS task_name, NULL AS tool_name, NULL AS kb_name';
        }
        return "SELECT $cols FROM $table $a $joins WHERE $a.user_id = ? AND $a.created_at BETWEEN ? AND ?";
    }

    /**
     * Union des deux tables, et ses paramètres.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    public static function unionSql(int $userId, string $from, string $to, bool $labels): array
    {
        $p = [$userId, $from . ' 00:00:00', $to . ' 23:59:59'];
        return [self::linesSql('llm', $labels) . ' UNION ALL ' . self::linesSql('tool', $labels), array_merge($p, $p)];
    }

    /**
     * Facettes, depuis les dimensions de la période (`dimensions()`) :
     * crédits par origine sous les AUTRES filtres (les puces), par type sous
     * les autres filtres (la répartition et le sélecteur), modèles et
     * assistants de toute la période (les sélecteurs — un filtre posé y
     * figure toujours).
     *
     * @param list<array{origin:string, type:string, kind:string, provider:string, model:string,
     *                   profile_id:?int, profile_name:?string, profile_color:?string, lines:int, credits:int}> $dims
     * @return array{origins:array<string,array{credits:int, lines:int}>, types:array<string,array{credits:int, lines:int}>,
     *               models:list<array{key:string, label:string, credits:int}>, profiles:list<array{key:string, label:string, color:string, credits:int}>}
     */
    public static function facets(array $dims, array $filters): array
    {
        $matches = static function (array $d, string $skip) use ($filters): bool {
            if ($skip !== 'origin' && ($filters['origin'] ?? '') !== '' && $d['origin'] !== $filters['origin']) {
                return false;
            }
            if ($skip !== 'type' && ($filters['type'] ?? '') !== '' && $d['type'] !== $filters['type']) {
                return false;
            }
            if ($skip !== 'model' && ($filters['model'] ?? '') !== ''
                && ($d['kind'] !== 'llm' || $d['provider'] . '::' . $d['model'] !== $filters['model'])) {
                return false;
            }
            if ($skip !== 'profile' && ($filters['profile'] ?? '') !== '') {
                $none = $filters['profile'] === self::NO_PROFILE;
                if ($none ? $d['profile_id'] !== null : $d['profile_id'] !== (int)$filters['profile']) {
                    return false;
                }
            }
            return true;
        };

        $origins = [];
        $types   = [];
        $models  = [];
        $profiles = [];
        foreach ($dims as $d) {
            if ($matches($d, 'origin')) {
                $origins[$d['origin']]['credits'] = ($origins[$d['origin']]['credits'] ?? 0) + $d['credits'];
                $origins[$d['origin']]['lines']   = ($origins[$d['origin']]['lines'] ?? 0) + $d['lines'];
            }
            if ($matches($d, 'type')) {
                $types[$d['type']]['credits'] = ($types[$d['type']]['credits'] ?? 0) + $d['credits'];
                $types[$d['type']]['lines']   = ($types[$d['type']]['lines'] ?? 0) + $d['lines'];
            }
            if ($d['kind'] === 'llm' && $d['provider'] !== '' && $d['model'] !== '') {
                $key = $d['provider'] . '::' . $d['model'];
                $models[$key] = ($models[$key] ?? 0) + $d['credits'];
            }
            $pKey = $d['profile_id'] === null ? self::NO_PROFILE : (string)$d['profile_id'];
            if (!isset($profiles[$pKey])) {
                $profiles[$pKey] = [
                    'key'     => $pKey,
                    'label'   => $d['profile_id'] === null ? t('Sans assistant') : ($d['profile_name'] !== null && $d['profile_name'] !== '' ? (string)$d['profile_name'] : t('Assistant supprimé')),
                    'color'   => self::safeColor((string)($d['profile_color'] ?? '')),
                    'credits' => 0,
                ];
            }
            $profiles[$pKey]['credits'] += $d['credits'];
        }
        // Ordre du catalogue pour les origines et les types : stable d'une période à l'autre.
        $origins = array_intersect_key(array_merge(array_fill_keys(self::origins(), null), $origins), $origins);
        $types   = array_intersect_key(array_merge(array_fill_keys(self::types(), null), $types), $types);

        // Modèles : un même nom lisible (deux identifiants du même modèle) n'apparaît qu'une fois.
        arsort($models);
        $modelList = [];
        $seenLabels = [];
        foreach ($models as $key => $credits) {
            [$provider, $model] = explode('::', $key, 2);
            $label = self::modelLabel($provider, $model);
            if (isset($seenLabels[$label])) {
                $label .= ' (' . $model . ')';
            }
            $seenLabels[$label] = true;
            $modelList[] = ['key' => $key, 'label' => $label, 'credits' => (int)$credits];
        }
        usort($profiles, static fn(array $a, array $b): int => $b['credits'] <=> $a['credits'] ?: strcmp($a['label'], $b['label']));

        // Un filtre posé reste dans son sélecteur, même hors des plus utilisés.
        $keep = static function (array $list, string $selected, int $max): array {
            $top = array_slice($list, 0, $max);
            if ($selected !== '' && !in_array($selected, array_column($top, 'key'), true)) {
                foreach ($list as $item) {
                    if ($item['key'] === $selected) {
                        $top[] = $item;
                    }
                }
            }
            return $top;
        };
        $modelList = $keep($modelList, (string)($filters['model'] ?? ''), self::OPTIONS_MAX);
        $profiles  = $keep(array_values($profiles), (string)($filters['profile'] ?? ''), self::OPTIONS_MAX);
        if (($filters['model'] ?? '') !== '' && !in_array($filters['model'], array_column($modelList, 'key'), true)) {
            [$provider, $model] = explode('::', (string)$filters['model'], 2);
            $modelList[] = ['key' => (string)$filters['model'], 'label' => self::modelLabel($provider, $model), 'credits' => 0];
        }
        if (($filters['profile'] ?? '') !== '' && !in_array($filters['profile'], array_column($profiles, 'key'), true)) {
            $profiles[] = ['key' => (string)$filters['profile'], 'label' => $filters['profile'] === self::NO_PROFILE ? t('Sans assistant') : t('Assistant supprimé'), 'color' => '', 'credits' => 0];
        }

        return ['origins' => $origins, 'types' => $types, 'models' => $modelList, 'profiles' => $profiles];
    }

    /** Couleur `#rrggbb` validée, sinon ''. */
    public static function safeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '';
    }

    /**
     * Histogramme empilé par origine : une barre par jour, semaine ou mois
     * (même règle que le budget, AiBudget::grain), chaque barre découpée en
     * segments d'origine (ordre du catalogue, du bas vers le haut). Positions
     * en POURCENTAGE de la zone de tracé : le gabarit ne calcule rien.
     *
     * @param array{from:string, to:string}     $range bornes incluses
     * @param array<string,array<string,int>>  $series 'Y-m-d' → origine → crédits
     */
    public static function bars(array $range, array $series, DateTimeImmutable $now): array
    {
        $from  = new DateTimeImmutable($range['from']);
        $to    = new DateTimeImmutable($range['to']);
        $today = $now->format('Y-m-d');
        $days  = (int)$from->diff($to)->days + 1;
        $grain = AiBudget::grain($days);

        $buckets = [];
        $total   = 0;
        $present = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $key = match ($grain) {
                AiBudget::GRAIN_DAY  => $ymd,
                AiBudget::GRAIN_WEEK => $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d'),
                default              => $d->format('Y-m'),
            };
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['start' => $ymd, 'end' => $ymd, 'days' => 0, 'credits' => 0, 'origins' => []];
            }
            $buckets[$key]['end'] = $ymd;
            $buckets[$key]['days']++;
            foreach ($series[$ymd] ?? [] as $origin => $credits) {
                $credits = max(0, (int)$credits);
                if ($credits === 0) {
                    continue;
                }
                $buckets[$key]['origins'][$origin] = ($buckets[$key]['origins'][$origin] ?? 0) + $credits;
                $buckets[$key]['credits'] += $credits;
                $total += $credits;
                $present[$origin] = true;
            }
        }
        $buckets = array_values($buckets);

        $peakCredits = $buckets !== [] ? max(array_column($buckets, 'credits')) : 0;
        $scale = AiBudget::niceScale(max(1.0, $peakCredits * 1.08), 3);
        $yMax  = max(1.0, (float)$scale['max']);
        $pct   = static fn(float $v): float => round(min($v, $yMax) / $yMax * 100, 2);

        $n    = count($buckets);
        $slot = $n > 0 ? 100 / $n : 100.0;
        $order = self::origins();
        $bars = [];
        foreach ($buckets as $i => $b) {
            $full = match ($grain) {
                AiBudget::GRAIN_DAY  => 1,
                AiBudget::GRAIN_WEEK => 7,
                default              => (int)(new DateTimeImmutable($b['start']))->format('t'),
            };
            $segments = [];
            $bottom = 0.0;
            foreach ($order as $origin) {
                $c = $b['origins'][$origin] ?? 0;
                if ($c <= 0) {
                    continue;
                }
                $h = $pct((float)$c);
                $segments[] = ['origin' => $origin, 'credits' => $c, 'bottom' => round($bottom, 2), 'h' => $h];
                $bottom += $h;
            }
            $bars[] = [
                'start'    => $b['start'],
                'end'      => $b['end'],
                'label'    => AiBudget::bucketLabel($grain, $b['start'], $b['end'], $b['days'] < $full),
                'x'        => round($i * $slot, 3),
                'w'        => round($slot, 3),
                'h'        => $pct((float)$b['credits']),
                'credits'  => $b['credits'],
                'segments' => $segments,
                'current'  => $b['start'] <= $today && $today <= $b['end'],
                'partial'  => $b['days'] < $full,
            ];
        }

        $yTicks = [];
        for ($v = 0.0; $v <= $yMax + 1e-6; $v += (float)$scale['step']) {
            $yTicks[] = ['v' => (int)round($v), 'y' => round(100 - $pct($v), 2)];
        }
        $xTicks = [];
        $step = max(1, (int)ceil($n / AiBudget::BARS_TICKS));
        $prev = null;
        foreach ($bars as $i => $bar) {
            if ($i % $step !== 0) {
                continue;
            }
            $xTicks[] = ['x' => round(($i + 0.5) * $slot, 3), 'label' => AiBudget::tickLabel($grain, $bar['start'], $prev)];
            $prev = $bar['start'];
        }
        $peak = null;
        foreach ($bars as $bar) {
            if ($bar['credits'] > 0 && ($peak === null || $bar['credits'] > $peak['credits'])) {
                $peak = $bar;
            }
        }
        $unitDays = match ($grain) {
            AiBudget::GRAIN_DAY  => 1.0,
            AiBudget::GRAIN_WEEK => 7.0,
            default              => 365.25 / 12,
        };

        return [
            'grain'   => $grain,
            'days'    => $days,
            'total'   => $total,
            'bars'    => $bars,
            'y_ticks' => $yTicks,
            'x_ticks' => $xTicks,
            'peak'    => $peak,
            'average' => $total > 0 ? (int)round($total / $days * $unitDays) : null,
            'origins' => array_values(array_filter($order, static fn(string $o): bool => isset($present[$o]))),
        ];
    }

    /**
     * Les chiffres en tête : total, part hors quota, opérations, moyenne par
     * jour, évolution face à la période précédente (mêmes filtres). Évolution
     * null quand la période d'avant est vide ou trop maigre pour comparer.
     *
     * @param array{credits:int, exempt:int, operations:int} $now
     * @param array{credits:int, exempt:int, operations:int}|null $before
     */
    public static function summary(array $now, ?array $before, int $days): array
    {
        $evolution = null;
        if ($before !== null && $before['credits'] >= 20) {
            $evolution = round(($now['credits'] - $before['credits']) / $before['credits'] * 100, 1);
        }
        return [
            'credits'    => $now['credits'],
            'exempt'     => $now['exempt'],
            'operations' => $now['operations'],
            'per_day'    => $days > 0 ? (int)round($now['credits'] / $days) : 0,
            'per_op'     => $now['operations'] > 0 ? (int)round($now['credits'] / $now['operations']) : 0,
            'previous'   => $before,
            'evolution'  => $evolution,
        ];
    }

    /**
     * Libellé d'un jour du journal : « Aujourd'hui », « Hier », sinon
     * « lun. 5 oct. » (année ajoutée hors de l'année en cours).
     */
    public static function dayLabel(string $ymd, DateTimeImmutable $now): string
    {
        $today = $now->format('Y-m-d');
        if ($ymd === $today) {
            return t('Aujourd’hui');
        }
        if ($ymd === $now->modify('-1 day')->format('Y-m-d')) {
            return t('Hier');
        }
        $d = new DateTimeImmutable($ymd);
        $label = Format::shortDate($d);
        return $d->format('Y') !== $now->format('Y') ? $label . ' ' . $d->format('Y') : $label;
    }

    /** Volume d'une ligne : « 12,4 k tokens », « 3 recherches », « 2,5 min », « 1 image ». */
    public static function volume(array $line): string
    {
        $tokens   = (int)($line['tk'] ?? 0);
        $quantity = (float)($line['q'] ?? 0);
        if (($line['k'] ?? 'llm') === 'tool') {
            return $quantity > 0 && $quantity !== 1.0
                ? I18n::plural((int)round($quantity), '{n} unité', '{n} unités', ['n' => Format::credits((int)round($quantity))])
                : '';
        }
        if ($tokens > 0) {
            return t('{n} tokens', ['n' => Format::tokens($tokens)]);
        }
        return match (UsageSource::unit((string)($line['s'] ?? ''))) {
            UsageSource::UNIT_SEARCHES => $quantity > 0 ? I18n::plural((int)round($quantity), '{n} recherche', '{n} recherches', ['n' => Format::credits((int)round($quantity))]) : '',
            UsageSource::UNIT_MINUTES  => $quantity > 0 ? t('{n} min', ['n' => number_format($quantity, 1, I18n::locale() === 'en' ? '.' : ',', ' ')]) : '',
            default                    => '',
        };
    }

    /**
     * Une opération du journal, prête à afficher : son nom (la ligne qui la
     * nomme, cf. PRIMARY), son type et sa couleur, où elle a eu lieu
     * (conversation, workflow, tâche, base, Office) avec un lien quand il
     * mène quelque part, le modèle ou l'outil, l'assistant, les crédits, et
     * le détail de ses lignes.
     *
     * @param array $g ligne de `page()` (lignes JSON déjà décodées dans `lines`)
     * @param array{workflow_links?:bool} $ctx
     */
    public static function row(array $g, array $ctx = []): array
    {
        $lines = [];
        foreach ((array)($g['lines'] ?? []) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $kind = ($l['k'] ?? '') === 'tool' ? 'tool' : 'llm';
            $source = (string)($l['s'] ?? '');
            $toolType = (string)($l['tt'] ?? '');
            $type = isset(self::TYPES[(string)($l['ty'] ?? '')]) ? (string)$l['ty'] : self::typeOf($kind, $source, $toolType);
            $who = $kind === 'tool'
                ? (string)($l['n'] ?? '')
                : self::modelLabel((string)($l['p'] ?? ''), (string)($l['m'] ?? ''));
            $lines[] = [
                'kind'    => $kind,
                'source'  => $source,
                'type'    => $type,
                'label'   => self::operationLabel($kind, $source, $toolType),
                'who'     => $who,
                'volume'  => self::volume($l + ['k' => $kind]),
                'credits' => max(0, (int)($l['c'] ?? 0)),
                'exempt'  => !empty($l['x']),
            ];
        }
        $rank = array_flip(self::PRIMARY);
        usort($lines, static fn(array $a, array $b): int => ($rank[$a['type']] ?? 99) <=> ($rank[$b['type']] ?? 99)
            ?: $b['credits'] <=> $a['credits'] ?: strcmp($a['label'], $b['label']));
        $primary = $lines[0] ?? ['type' => 'answer', 'label' => t('Opération'), 'who' => '', 'kind' => 'llm', 'source' => '', 'credits' => 0, 'volume' => '', 'exempt' => false];

        // Le reste de l'opération, regroupé par nature : « Recherche Internet × 2 · 6 crédits ».
        $extras = [];
        foreach (array_slice($lines, 1) as $l) {
            $k = $l['label'];
            if (!isset($extras[$k])) {
                $extras[$k] = ['label' => $l['label'], 'count' => 0, 'credits' => 0];
            }
            $extras[$k]['count']++;
            $extras[$k]['credits'] += $l['credits'];
        }

        $origin = self::isOrigin((string)($g['origin'] ?? '')) ? (string)$g['origin'] : self::ORIGIN_CHAT;
        $convId = isset($g['conversation_id']) && (int)$g['conversation_id'] > 0 && ($g['conv_title'] ?? null) !== null
            ? (int)$g['conversation_id'] : null;
        $convTitle = $convId !== null ? Conversation::displayTitle((string)$g['conv_title']) : null;
        $hadConversation = isset($g['conversation_id']) && (int)$g['conversation_id'] > 0;

        $context = ['label' => '', 'href' => null, 'sub' => ''];
        switch ($origin) {
            case self::ORIGIN_WORKFLOW:
                $context['label'] = !empty($g['workflow_name']) ? (string)$g['workflow_name'] : t('Workflow supprimé');
                if (!empty($ctx['workflow_links']) && !empty($g['run_id']) && !empty($g['workflow_name'])) {
                    $context['href'] = '/workflows/runs/' . (int)$g['run_id'];
                } elseif ($convId !== null) {
                    $context['href'] = '/chat/' . $convId;
                }
                break;
            case self::ORIGIN_TASK:
                $context['label'] = !empty($g['task_name']) ? (string)$g['task_name'] : t('Tâche supprimée');
                $context['href']  = $convId !== null ? '/chat/' . $convId : null;
                break;
            case self::ORIGIN_OFFICE:
                $context['label'] = t('Excel, Word ou PowerPoint');
                break;
            case self::ORIGIN_KNOWLEDGE:
                $context['label'] = !empty($g['kb_name']) ? (string)$g['kb_name'] : t('Base de connaissances');
                break;
            case self::ORIGIN_CHAT:
                if ($convTitle !== null) {
                    $context['label'] = $convTitle;
                    $context['href']  = '/chat/' . $convId;
                } elseif ($hadConversation || in_array($primary['type'], ['answer', 'web', 'assist'], true)) {
                    $context['label'] = t('Conversation supprimée');
                }
                break;
        }

        $profile = null;
        if (!empty($g['profile_id'])) {
            $profile = [
                'name'  => !empty($g['profile_name']) ? (string)$g['profile_name'] : t('Assistant supprimé'),
                'color' => self::safeColor((string)($g['profile_color'] ?? '')),
            ];
        }

        // La réponse d'une étape de workflow ou d'une exécution de tâche se nomme comme telle.
        $title = $primary['label'];
        if ($primary['type'] === 'answer' && $origin === self::ORIGIN_WORKFLOW) {
            $title = t('Étape de workflow');
        } elseif ($primary['type'] === 'answer' && $origin === self::ORIGIN_TASK) {
            $title = t('Exécution de la tâche');
        }

        $at = new DateTimeImmutable((string)$g['at']);
        $credits = max(0, (int)($g['credits'] ?? 0));
        $exempt  = max(0, (int)($g['exempt'] ?? 0));

        return [
            'id'           => (string)$g['grp'],
            'day'          => $at->format('Y-m-d'),
            'time'         => Format::time($at->format('H:i')),
            'title'        => $title,
            'type'         => $primary['type'],
            'color'        => self::typeColor($primary['type']),
            'origin'       => $origin,
            'origin_label' => self::originLabel($origin),
            'origin_color' => self::originColor($origin),
            'context'      => $context,
            'who'          => $primary['who'],
            'profile'      => $profile,
            'credits'      => $credits,
            'exempt'       => $exempt,
            'all_exempt'   => $credits > 0 && $exempt >= $credits,
            'extras'       => array_values($extras),
            'lines'        => $lines,
        ];
    }

    /**
     * Jours du journal, dans l'ordre des opérations : [jour, libellé, crédits
     * du jour (toute la période filtrée), opérations de la page].
     *
     * @param list<array> $rows sortie de row()
     * @param array<string,array<string,int>> $series
     * @return list<array{day:string, label:string, credits:int, rows:list<array>}>
     */
    public static function days(array $rows, array $series, DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($rows as $row) {
            $day = $row['day'];
            $last = array_key_last($out);
            if ($last === null || $out[$last]['day'] !== $day) {
                $out[] = [
                    'day'     => $day,
                    'label'   => self::dayLabel($day, $now),
                    'credits' => array_sum($series[$day] ?? []),
                    'rows'    => [],
                ];
                $last = array_key_last($out);
            }
            $out[$last]['rows'][] = $row;
        }
        return $out;
    }

    /** En-tête de l'export CSV. @return list<string> */
    public static function exportHeader(): array
    {
        return [
            t('Date'), t('Heure'), t('Origine'), t('Type d’usage'), t('Opération'), t('Où'),
            t('Modèle ou outil'), t('Assistant'), 'Tokens', t('Volume'), t('Crédits'), t('Hors quota'),
        ];
    }

    /**
     * Une ligne du grand livre (sortie de `exportLines()`), pour l'export :
     * mêmes libellés que l'écran, nombres bruts (le tableur les additionne).
     *
     * @return list<string|int>
     */
    public static function exportRow(array $r): array
    {
        $kind   = ($r['kind'] ?? '') === 'tool' ? 'tool' : 'llm';
        $origin = (string)($r['origin'] ?? '');
        $where  = match ($origin) {
            self::ORIGIN_WORKFLOW  => (string)($r['workflow_name'] ?? ''),
            self::ORIGIN_TASK      => (string)($r['task_name'] ?? ''),
            self::ORIGIN_KNOWLEDGE => (string)($r['kb_name'] ?? ''),
            self::ORIGIN_OFFICE    => t('Excel, Word ou PowerPoint'),
            default                => ($r['conv_title'] ?? null) !== null ? Conversation::displayTitle((string)$r['conv_title']) : '',
        };
        $at = new DateTimeImmutable((string)$r['created_at']);
        return [
            $at->format('Y-m-d'),
            $at->format('H:i'),
            self::originLabel($origin),
            self::typeLabel((string)($r['type'] ?? '')),
            self::operationLabel($kind, (string)($r['source'] ?? ''), (string)($r['tool_type'] ?? '')),
            $where,
            $kind === 'tool' ? (string)($r['tool_name'] ?? '') : self::modelLabel((string)($r['provider'] ?? ''), (string)($r['model'] ?? '')),
            !empty($r['profile_id']) ? (string)($r['profile_name'] ?? t('Assistant supprimé')) : '',
            (int)($r['tokens'] ?? 0),
            self::volume(['k' => $kind, 's' => $r['source'] ?? '', 'tk' => 0, 'q' => $r['quantity'] ?? 0]),
            (int)($r['credits'] ?? 0),
            !empty($r['quota_exempt']) ? t('oui') : '',
        ];
    }

    // =====================================================================
    // 3. Lectures (une personne, une période 'Y-m-d' incluse)
    // =====================================================================

    /**
     * Dimensions de la période : crédits et lignes par origine × type ×
     * modèle × assistant, sous la seule recherche (facettes et sélecteurs).
     *
     * @param list<string> $modelKeys modèles de la période (recherche par libellé)
     * @return list<array{origin:string, type:string, kind:string, provider:string, model:string,
     *                    profile_id:?int, profile_name:?string, profile_color:?string, lines:int, credits:int}>
     */
    public static function dimensions(int $userId, string $from, string $to, string $q = '', array $modelKeys = []): array
    {
        $search = $q !== '';
        [$union, $params] = self::unionSql($userId, $from, $to, $search);
        [$where, $wp] = $search ? self::whereSql(['q' => $q], $modelKeys) : ['', []];
        $stmt = Database::getInstance()->prepare(
            "SELECT x.origin, x.type, x.kind, x.provider, x.model, x.profile_id,
                    MAX(x.profile_name) AS profile_name, MAX(x.profile_color) AS profile_color,
                    COUNT(*) AS nb_lines, COALESCE(SUM(x.credits), 0) AS credits
             FROM ($union) x $where
             GROUP BY x.origin, x.type, x.kind, x.provider, x.model, x.profile_id"
        );
        $stmt->execute(array_merge($params, $wp));
        return array_map(static fn(array $r): array => [
            'origin'        => (string)$r['origin'],
            'type'          => (string)$r['type'],
            'kind'          => (string)$r['kind'],
            'provider'      => strtolower(trim((string)$r['provider'])),
            'model'         => trim((string)$r['model']),
            'profile_id'    => $r['profile_id'] !== null ? (int)$r['profile_id'] : null,
            'profile_name'  => $r['profile_name'] !== null ? (string)$r['profile_name'] : null,
            'profile_color' => $r['profile_color'] !== null ? (string)$r['profile_color'] : null,
            'lines'         => (int)$r['nb_lines'],
            'credits'       => (int)$r['credits'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> modèles « fournisseur::modèle » de la période (recherche par libellé). */
    public static function modelKeys(int $userId, string $from, string $to): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT SUBSTRING_INDEX(llm_provider, '::', 1) AS provider,
                    COALESCE(NULLIF(model, ''), IF(LOCATE('::', llm_provider) > 0, SUBSTRING_INDEX(llm_provider, '::', -1), '')) AS model
             FROM usage_logs WHERE user_id = ? AND created_at BETWEEN ? AND ?"
        );
        $stmt->execute([$userId, $from . ' 00:00:00', $to . ' 23:59:59']);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string)$r['provider'] !== '' && (string)$r['model'] !== '') {
                $keys[] = strtolower((string)$r['provider']) . '::' . (string)$r['model'];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Crédits par jour et par origine, sous tous les filtres.
     *
     * @param list<string> $modelKeys
     * @return array<string,array<string,int>> 'Y-m-d' → origine → crédits
     */
    public static function series(int $userId, string $from, string $to, array $filters, array $modelKeys = []): array
    {
        [$union, $params] = self::unionSql($userId, $from, $to, ($filters['q'] ?? '') !== '');
        [$where, $wp] = self::whereSql($filters, $modelKeys);
        $stmt = Database::getInstance()->prepare(
            "SELECT DATE(x.created_at) AS d, x.origin, COALESCE(SUM(x.credits), 0) AS credits
             FROM ($union) x $where GROUP BY DATE(x.created_at), x.origin"
        );
        $stmt->execute(array_merge($params, $wp));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['d']][(string)$r['origin']] = (int)$r['credits'];
        }
        ksort($out);
        return $out;
    }

    /**
     * Totaux sous tous les filtres : crédits, part hors quota, opérations
     * (messages distincts), lignes.
     *
     * @param list<string> $modelKeys
     * @return array{credits:int, exempt:int, operations:int, lines:int}
     */
    public static function totals(int $userId, string $from, string $to, array $filters, array $modelKeys = []): array
    {
        [$union, $params] = self::unionSql($userId, $from, $to, ($filters['q'] ?? '') !== '');
        [$where, $wp] = self::whereSql($filters, $modelKeys);
        $stmt = Database::getInstance()->prepare(
            "SELECT COALESCE(SUM(x.credits), 0) AS credits, COALESCE(SUM(IF(x.quota_exempt = 1, x.credits, 0)), 0) AS exempt,
                    COUNT(DISTINCT x.grp) AS operations, COUNT(*) AS nb_lines
             FROM ($union) x $where"
        );
        $stmt->execute(array_merge($params, $wp));
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'credits'    => (int)($r['credits'] ?? 0),
            'exempt'     => (int)($r['exempt'] ?? 0),
            'operations' => (int)($r['operations'] ?? 0),
            'lines'      => (int)($r['nb_lines'] ?? 0),
        ];
    }

    /**
     * Une page du journal : les opérations (lignes d'un même message
     * regroupées), les plus récentes d'abord. Une de plus que demandé est
     * lue pour savoir s'il en reste.
     *
     * @param list<string> $modelKeys
     * @return array{rows:list<array>, more:bool}
     */
    public static function page(int $userId, string $from, string $to, array $filters, int $offset, int $limit = self::PAGE, array $modelKeys = []): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, min(100, $limit));
        [$union, $params] = self::unionSql($userId, $from, $to, true);
        [$where, $wp] = self::whereSql($filters, $modelKeys);
        $stmt = Database::getInstance()->prepare(
            "SELECT x.grp, MAX(x.created_at) AS at,
                    COALESCE(SUM(x.credits), 0) AS credits, COALESCE(SUM(IF(x.quota_exempt = 1, x.credits, 0)), 0) AS exempt,
                    MAX(x.origin) AS origin, MAX(x.conversation_id) AS conversation_id, MAX(x.conv_title) AS conv_title,
                    MAX(x.profile_id) AS profile_id, MAX(x.profile_name) AS profile_name, MAX(x.profile_color) AS profile_color,
                    MAX(x.run_id) AS run_id, MAX(x.workflow_id) AS workflow_id, MAX(x.workflow_name) AS workflow_name,
                    MAX(x.task_id) AS task_id, MAX(x.task_name) AS task_name, MAX(x.kb_id) AS kb_id, MAX(x.kb_name) AS kb_name,
                    JSON_ARRAYAGG(JSON_OBJECT('k', x.kind, 's', x.source, 'ty', x.type, 'tt', x.tool_type, 'p', x.provider,
                        'm', x.model, 'c', x.credits, 'x', x.quota_exempt, 'tk', x.tokens, 'q', x.quantity, 'n', x.tool_name)) AS line_list
             FROM ($union) x $where
             GROUP BY x.grp
             ORDER BY at DESC, x.grp DESC
             LIMIT " . ($limit + 1) . ' OFFSET ' . $offset
        );
        $stmt->execute(array_merge($params, $wp));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            $decoded = json_decode((string)$r['line_list'], true);
            unset($r['line_list']);
            $r['lines'] = is_array($decoded) ? $decoded : [];
        }
        unset($r);
        return ['rows' => $rows, 'more' => $more];
    }

    /**
     * Lignes de l'export (une par ligne du grand livre, plus récentes
     * d'abord), sous tous les filtres, au plus EXPORT_MAX.
     *
     * @param list<string> $modelKeys
     * @return iterable<array>
     */
    public static function exportLines(int $userId, string $from, string $to, array $filters, array $modelKeys = []): iterable
    {
        [$union, $params] = self::unionSql($userId, $from, $to, true);
        [$where, $wp] = self::whereSql($filters, $modelKeys);
        $stmt = Database::getInstance()->prepare(
            "SELECT x.* FROM ($union) x $where ORDER BY x.created_at DESC, x.id DESC LIMIT " . self::EXPORT_MAX
        );
        $stmt->execute(array_merge($params, $wp));
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $r;
        }
    }

    /** Premier jour d'activité de la personne ('Y-m-d'), ou null. */
    public static function firstDay(int $userId): ?string
    {
        $db = Database::getInstance();
        $first = null;
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $stmt = $db->prepare("SELECT MIN(created_at) FROM $table WHERE user_id = ?");
            $stmt->execute([$userId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '' && ($first === null || $value < $first)) {
                $first = $value;
            }
        }
        return $first !== null ? substr($first, 0, 10) : null;
    }
}
