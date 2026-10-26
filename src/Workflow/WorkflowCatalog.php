<?php

declare(strict_types=1);

/**
 * Ce qu'une personne peut mettre dans un workflow — assistants, modèles,
 * sources, bases, outils, outils où agir — et le catalogue que l'éditeur et
 * les schémas affichent (libellés traduits, icônes de l'application).
 *
 * Même périmètre que les tâches programmées (ScheduledTask::choicesFor) : la
 * porte suit la personne, et chaque exécution le revérifie au moment T.
 */
final class WorkflowCatalog
{
    /**
     * Couleur des pièces du dossier : UNE COULEUR PAR NATURE (texte, liste,
     * tableau, Fiche, document, fichier, image, page web, carte) et une pour
     * le champ d'une Fiche — partout où une pièce se montre : puces, points du
     * rail, paquets qui circulent, citations « @ » dans les champs.
     */
    public const FORM_COLORS = [
        'text'     => '#3b82f6',
        'list'     => '#14b8a6',
        'table'    => '#22c55e',
        'fiche'    => '#a855f7',
        'document' => '#f97316',
        'file'     => '#64748b',
        'image'    => '#eab308',
        'page'     => '#6366f1',
        'map'      => '#84cc16',
        'field'    => '#ec4899',
    ];

    /** Teintes des natures de brique (celles des schémas). */
    public const HUES = [
        'start'     => '#f59e0b',
        'step'      => '#10a37f',
        'action'    => '#06b6d4',
        'create'    => '#ca8a04',
        'condition' => '#8b5cf6',
        'route'     => '#4f46e5',
        'loop'      => '#2563eb',
        'gate'      => '#f97316',
        'wait'      => '#64748b',
        'notify'    => '#db2777',
        'stop'      => '#dc2626',
        'end'       => '#10a37f',
    ];

    /**
     * Choix de la personne (ScheduledTask::choicesFor) + `tool_llms` (slug →
     * le modèle pilote des outils) + `default_llm` : le contexte de
     * WorkflowSpec::issues().
     */
    public static function choices(array $user): array
    {
        $choices = ScheduledTask::choicesFor($user, true);
        $choices['tool_llms'] = [];
        foreach (array_keys($choices['llms']) as $slug) {
            $choices['tool_llms'][$slug] = self::toolUse((string)$slug);
        }
        $choices['default_llm'] = (string)(LLMFactory::appDefault($choices['llms']) ?? '');
        $choices['auto'] = Workflow::autoAllowed();
        // Outils de création (image, page web, carte) ouverts à la personne.
        $choices['creations'] = WorkflowCreations::toolsFor($user);
        // Livraison et enchaînement : les autres workflows de la personne,
        // les bases qu'elle gère (où ranger un livrable).
        $choices['workflows'] = [];
        foreach (Workflow::forUser((int)$user['id']) as $w) {
            $choices['workflows'][$w['id']] = ['name' => $w['name'], 'published' => $w['published'] !== null];
        }
        $choices['delivery_kbs'] = [];
        try {
            foreach (KnowledgeBase::forManager($user) as $kb) {
                if (!KnowledgeBase::isRemote($kb) && empty($kb['is_system'])) {
                    $choices['delivery_kbs'][(int)$kb['id']] = ['name' => (string)$kb['name']];
                }
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] bases de livraison : ' . $e->getMessage());
        }
        return $choices;
    }

    public static function toolUse(string $slug): bool
    {
        if ($slug === 'local') {
            $row = ApiKey::findByProvider('local');
            return LlmCatalog::supportsToolUse('local', (string)($row['model'] ?? ''));
        }
        if (!str_contains($slug, '::')) {
            $model = ApiKey::getModel($slug) ?: LlmCatalog::defaultModel($slug);
            return LlmCatalog::supportsToolUse($slug, (string)$model);
        }
        [$provider, $model] = explode('::', $slug, 2);
        return LlmCatalog::supportsToolUse($provider, $model);
    }

    /**
     * Catalogue de l'éditeur (JSON) : tout ce qui s'affiche, déjà traduit.
     */
    public static function forEditor(array $choices): array
    {
        $logos = LLMFactory::logos();
        $llms = [];
        foreach ($choices['llms'] as $slug => $label) {
            $provider = LLMFactory::provider((string)$slug);
            $llms[] = [
                'slug'  => (string)$slug,
                'label' => (string)$label,
                'logo'  => $logos[$provider] ?? '',
                'tools' => !empty($choices['tool_llms'][$slug]),
            ];
        }
        $cards = ScheduledTaskController::assistantCards($choices['profiles'], $choices['profile_kbs'] ?? [], $choices['llms'], $choices['profile_tools'] ?? []);
        $profiles = [];
        foreach ($cards as $id => $card) {
            $profiles[] = ['id' => (int)$id] + $card;
        }
        $sources = [];
        foreach ($choices['sources'] as $key => $s) {
            $sources[] = ['key' => (string)$key, 'label' => (string)$s['label'], 'icon' => (string)$s['icon'], 'connected' => !empty($s['connected'])];
        }
        $kbs = array_values(array_map(static fn(array $kb): array => [
            'id' => $kb['id'], 'name' => $kb['name'], 'reason' => $kb['reason'], 'live' => $kb['live'], 'docs' => $kb['docs'],
        ], $choices['kbs'] ?? []));
        $tools = [];
        foreach ($choices['tools'] ?? [] as $tool) {
            $tools[] = [
                'id' => $tool['id'], 'name' => $tool['name'], 'type' => $tool['type'], 'reason' => $tool['reason'],
                'type_label' => ScheduledTask::toolTypeLabel((string)$tool['type']),
                'acting' => in_array($tool['type'], ScheduledTask::ACTING_TOOL_TYPES, true),
            ];
        }

        $creations = [];
        foreach ($choices['creations'] ?? [] as $tool) {
            $creations[] = [
                'id' => $tool['id'], 'name' => $tool['name'], 'type' => $tool['type'], 'kind' => $tool['kind'],
                'reason' => $tool['reason'], 'can_edit' => !empty($tool['can_edit']), 'type_label' => WorkflowCreations::typeLabel((string)$tool['type']),
            ];
        }

        $workflows = [];
        foreach ($choices['workflows'] ?? [] as $id => $w) {
            $workflows[] = ['id' => (int)$id, 'name' => (string)$w['name'], 'published' => !empty($w['published'])];
        }
        $deliveryKbs = [];
        foreach ($choices['delivery_kbs'] ?? [] as $id => $kb) {
            $deliveryKbs[] = ['id' => (int)$id, 'name' => (string)$kb['name']];
        }
        return [
            'profiles'    => $profiles,
            'llms'        => $llms,
            'default_llm' => $choices['default_llm'],
            'sources'     => $sources,
            'kbs'         => $kbs,
            'tools'       => $tools,
            'creations'   => $creations,
            'workflows'   => $workflows,
            'delivery_kbs' => $deliveryKbs,
            'email_fields' => WorkflowSpec::EMAIL_FIELDS,
            // Ce qu'une boucle « pour chaque » sait parcourir (LoopSources).
            'loop_sources' => LoopSources::catalog(),
            'event_names' => WorkflowSpec::EVENT_PIECE_NAMES,
            'targets'     => self::targets($choices),
            'auto'        => !empty($choices['auto']),
            'colors'      => self::FORM_COLORS,
            'hues'        => self::HUES,
            'icons'       => self::icons(),
            'labels'      => self::labels(),
            'limits'      => [
                'nodes' => WorkflowSpec::MAX_NODES, 'fields' => WorkflowSpec::MAX_FIELDS, 'fiche' => WorkflowSpec::MAX_FICHE,
                'rule' => WorkflowSpec::MAX_RULE_ITEMS, 'kbs' => WorkflowSpec::MAX_KBS, 'instruction' => WorkflowSpec::INSTRUCTION_MAX,
                'gate_days' => WorkflowSpec::GATE_DAYS_MAX, 'each_max' => Workflow::loopMax(), 'until_max' => WorkflowSpec::LOOP_UNTIL_MAX,
                'columns' => LoopSources::MAX_COLUMNS, 'ai_fields' => LoopSources::MAX_AI_FIELDS, 'confirm_over' => WorkflowSpec::LOOP_CONFIRM_OVER,
                'deliveries' => WorkflowSpec::MAX_DELIVERIES, 'retries' => WorkflowSpec::RETRIES_MAX, 'wait_days' => (int)(WorkflowSpec::WAIT_MAX_SEC / 86400),
                'routes_min' => WorkflowSpec::MIN_ROUTES, 'routes_max' => WorkflowSpec::MAX_ROUTES, 'filter_days' => WorkflowFilters::DAYS_MAX,
            ],
        ];
    }

    /** Outils dans lesquels une brique Action peut agir. */
    public static function targets(array $choices): array
    {
        $out = [];
        foreach (WorkflowSpec::ACTION_TARGETS as $key => $conn) {
            if (str_starts_with($conn, 'tool:')) {
                $type = substr($conn, 5);
                $list = array_values(array_filter($choices['tools'] ?? [], static fn(array $t): bool => $t['type'] === $type));
                if ($list === []) {
                    continue;
                }
                $out[] = [
                    'key' => $key, 'kind' => 'tool', 'label' => $type === 'n8n' ? t('Automatisation n8n') : t('Présentation Gamma'),
                    'icon' => $type === 'n8n' ? '<img src="/assets/img/n8n.svg" width="20" height="20" alt="">' : '<img src="/assets/img/gamma.svg" width="20" height="20" alt="">',
                    'available' => true, 'reason' => null,
                    'tools' => array_map(static fn(array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'reason' => $t['reason']], $list),
                ];
                continue;
            }
            if (!isset($choices['sources'][$conn])) {
                continue;
            }
            $s = $choices['sources'][$conn];
            $out[] = [
                'key' => $key, 'kind' => 'connector', 'label' => (string)$s['label'], 'icon' => (string)$s['icon'],
                'available' => !empty($s['connected']),
                'reason' => !empty($s['connected']) ? null : t('Reliez d’abord ce service dans Réglages → Comptes connectés.'),
                'verb' => self::targetVerb($key),
            ];
        }
        return $out;
    }

    /** Ce que fait typiquement une action dans un outil (aide de l'écran). */
    public static function targetVerb(string $key): string
    {
        return match ($key) {
            'gmail', 'outlook'        => t('Préparer ou envoyer un email'),
            'calendar', 'mscalendar'  => t('Créer un évènement'),
            'tasks', 'mstodo'         => t('Créer ou modifier une tâche'),
            'slack', 'teams'          => t('Publier un message'),
            'drive'                   => t('Créer ou modifier un document'),
            default                   => '',
        };
    }

    /** Pictogrammes des natures de brique et des formes de pièce (SVG, 24×24). */
    public static function icons(): array
    {
        $svg = static fn(string $paths): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
        return [
            'start'     => $svg('<path d="M7 4.5v15l12-7.5z"/>'),
            'step'      => $svg('<rect x="4" y="9" width="16" height="11" rx="2"/><path d="M12 9V6"/><circle cx="12" cy="4" r="1.6"/><path d="M9 13.5v2M15 13.5v2"/>'),
            'action'    => $svg('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>'),
            'condition' => $svg('<circle cx="12" cy="5" r="2.5"/><circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M12 7.5V10a3 3 0 0 1-3 3 3 3 0 0 0-3 3v.5"/><path d="M12 10a3 3 0 0 0 3 3 3 3 0 0 1 3 3v.5"/>'),
            'gate'      => $svg('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 11l2 2 4-4"/>'),
            'end'       => $svg('<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>'),
            'text'      => $svg('<path d="M4 6h16M4 10h16M4 14h10M4 18h7"/>'),
            'list'      => $svg('<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>'),
            'table'     => $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>'),
            'fiche'     => $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="10" r="2"/><path d="M5.5 16c.6-1.6 1.7-2.4 3-2.4s2.4.8 3 2.4"/><path d="M14 9h4M14 12.5h4M14 16h3"/>'),
            'document'  => $svg('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>'),
            'slides'    => $svg('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M12 16v4M8 20h8"/><path d="M7 8h6M7 11h4"/>'),
            'pdf'       => $svg('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M8.5 16v-4h1.2a1.2 1.2 0 0 1 0 2.4H8.5M13 12v4h.8a2 2 0 0 0 0-4zM17.5 12H16v4M16 14h1.2"/>'),
            'file'      => $svg('<path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>'),
            'kb'        => $svg('<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>'),
            'tool'      => $svg('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'),
            'clock'     => ScheduledTask::icon(16),
            'chat'      => $svg('<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'),
            'hand'      => $svg('<path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v6"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>'),
            'plus'      => $svg('<path d="M12 5v14M5 12h14"/>'),
            'up'        => $svg('<path d="M18 15l-6-6-6 6"/>'),
            'down'      => $svg('<path d="M6 9l6 6 6-6"/>'),
            'trash'     => $svg('<path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'),
            'copy'      => $svg('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'),
            'check'     => $svg('<path d="M20 6L9 17l-5-5"/>'),
            'x'         => $svg('<path d="M18 6L6 18M6 6l12 12"/>'),
            'alert'     => $svg('<path d="M12 3l9.5 17H2.5z"/><path d="M12 10v4"/><path d="M12 17.5h.01"/>'),
            'stop'      => $svg('<rect x="6" y="6" width="12" height="12" rx="2"/>'),
            'pause'     => $svg('<path d="M9 5.5v13M15 5.5v13"/>'),
            'back'      => $svg('<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/>'),
            'spark'     => $svg('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>'),
            'eye'       => $svg('<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'),
            'download'  => $svg('<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>'),
            'pencil'    => $svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
            'workflow'  => Workflow::icon(16),
            'mia'       => $svg('<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>'),
            'arrow'     => $svg('<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>'),
            'settings'  => $svg('<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>'),
            'folder'    => $svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'),
            'loop'      => $svg('<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>'),
            'wait'      => $svg('<path d="M6 2h12M6 22h12"/><path d="M7 2c0 5 10 5 10 10S7 17 7 22"/><path d="M17 2c0 5-10 5-10 10s10 5 10 10"/>'),
            'notify'    => $svg('<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'),
            'mail'      => $svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>'),
            'webhook'   => $svg('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'),
            'chain'     => $svg('<rect x="2" y="6" width="8" height="8" rx="2"/><path d="M10 10h4"/><path d="M14 6l4 4-4 4"/><rect x="18" y="8" width="4" height="4" rx="1"/>'),
            'skip'      => $svg('<path d="M5 4l10 8-10 8z"/><path d="M19 5v14"/>'),
            'retry'     => $svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>'),
            'history'   => $svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>'),
            'send'      => $svg('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>'),
            'cloud'     => $svg('<path d="M18 10h-1.3A7 7 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>'),
            'link'      => $svg('<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 0 1 0 10h-2"/><path d="M8 12h8"/>'),
            'route'     => $svg('<path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.17-2.87L3 3"/><path d="M15 9l6-6"/>'),
            'filter'    => $svg('<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>'),
            'at'        => $svg('<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/>'),
            'user'      => $svg('<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
            'search'    => $svg('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
            'inbox'     => $svg('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'),
            'shield'    => $svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
            'chev'      => $svg('<path d="M9 18l6-6-6-6"/>'),
            'field'     => $svg('<path d="M4 7V5h16v2"/><path d="M9 19h6"/><path d="M12 5v14"/>'),
            'people'    => $svg('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
            'logic'     => $svg('<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><path d="M10 6.5h4a3 3 0 0 1 3 3V14"/>'),
            // Créations : les pictogrammes du menu « + » du chat (image, page web, carte).
            'create'    => $svg('<path d="M21.6 3.6l-1.3-1.3a1.2 1.2 0 0 0-1.7 0L2.4 18.6a1.2 1.2 0 0 0 0 1.7l1.3 1.3a1.2 1.2 0 0 0 1.7 0L21.6 5.4a1.2 1.2 0 0 0 0-1.8z"/><path d="M14 7l3 3"/><path d="M5 6v4M19 14v4M10 2v2M7 8H3M21 16h-4M11 3H9"/>'),
            'image'     => $svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>'),
            'page'      => $svg('<path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/>'),
            'map'       => $svg('<path d="M1 6l7-3 8 3 7-3v15l-7 3-8-3-7 3z"/><path d="M8 3v15"/><path d="M16 6v15"/>'),
        ];
    }

    /**
     * Libellés de l'éditeur calculés côté serveur : natures, formes, types de
     * champ, opérateurs, formats — le script n'en a aucun en dur.
     */
    public static function labels(): array
    {
        $ops = [];
        foreach (WorkflowSpec::RULE_OPS as $op) {
            $ops[$op] = WorkflowValues::opLabel($op);
        }
        $opsFor = [];
        foreach (WorkflowSpec::FICHE_TYPES as $type) {
            $opsFor[$type] = WorkflowValues::opsFor($type);
        }
        return [
            'kinds' => [
                'start' => t('Départ'), 'step' => t('Étape'), 'action' => t('Action'), 'create' => t('Création'),
                'condition' => t('Condition'), 'route' => t('Décision de MIA'), 'loop' => t('Boucle'), 'gate' => t('Validation'),
                'wait' => t('Attendre'), 'notify' => t('Me prévenir'), 'stop' => t('Arrêter le workflow'), 'end' => t('Livrable'),
            ],
            'kindHelp' => [
                'step'      => t('Un assistant lit des pièces et en remet une.'),
                'action'    => t('Agir dans un outil : email, agenda, tâches, Slack, Drive…'),
                'create'    => t('Un outil de création fabrique une image, une page web ou une carte à partir du dossier.'),
                'condition' => t('Si… sinon : une règle, ou une question à MIA.'),
                'route'     => t('MIA lit des pièces et choisit une voie parmi les scénarios que vous décrivez.'),
                'loop'      => t('Répéter des briques pour chaque élément d’une liste, ou jusqu’à un résultat.'),
                'gate'      => t('Vous relisez une pièce avant la suite.'),
                'wait'      => t('Patienter une durée, ou jusqu’à une date du dossier.'),
                'notify'    => t('Un message sur vos appareils, sans IA.'),
                'stop'      => t('Termine l’exécution au bout d’une voie, proprement, sans livrable.'),
            ],
            // Statut d'une exécution finie par « Arrêter le workflow ».
            'stopOutcomes' => [
                WorkflowSpec::STOP_DONE    => t('Terminée'),
                WorkflowSpec::STOP_STOPPED => t('Arrêtée'),
            ],
            // Menu d'ajout : les briques rangées par ce qu'elles font.
            'groups' => [
                'ai'     => t('Intelligence'),
                'create' => t('Créer'),
                'logic'  => t('Logique'),
                'tools'  => t('Agir dans un outil'),
                'people' => t('Vous et le temps'),
            ],
            // Ce qu'une brique Création fabrique (menu d'ajout, volet, rail).
            'createKinds' => [
                'image' => t('Une image'),
                'page'  => t('Une page web'),
                'map'   => t('Une carte'),
            ],
            'createHelp' => [
                'image' => t('Un visuel, une illustration, une affiche — à partir du dossier.'),
                'page'  => t('Une page web complète, illustrée ou non, rédigée avec les pièces.'),
                'map'   => t('Un itinéraire tracé sur une carte, avec ses étapes.'),
            ],
            'imageFormats' => [
                ''          => t('Selon l’outil'),
                'square'    => t('Carré'),
                'landscape' => t('Paysage'),
                'portrait'  => t('Portrait'),
            ],
            'fallback' => [
                'ask' => t('Me demander'),
            ],
            'driveKinds' => array_combine(WorkflowFilters::DRIVE_KINDS, array_map([WorkflowFilters::class, 'kindLabel'], WorkflowFilters::DRIVE_KINDS)),
            'events' => [
                ''         => t('Aucun'),
                'email'    => t('Un email arrive'),
                'file'     => t('Un fichier arrive dans Drive'),
                'webhook'  => t('Un logiciel appelle son adresse'),
                'workflow' => t('Un autre workflow se termine'),
            ],
            'eventHelp' => [
                'email'    => t('Gmail ou Outlook, vérifié toutes les 5 minutes. Seuls les emails arrivés après la publication le lancent.'),
                'file'     => t('Un dossier Google Drive, vérifié toutes les 5 minutes. Chaque nouveau fichier lance une exécution.'),
                'webhook'  => t('n8n, Zapier, un formulaire ou un script poste du JSON à une adresse secrète.'),
                'workflow' => t('Son livrable devient la première pièce de celui-ci.'),
            ],
            'deliveries' => [
                'email' => t('Envoyer par email'),
                'drive' => t('Enregistrer dans Google Drive'),
                'slack' => t('Publier dans Slack'),
                'kb'    => t('Ajouter à une base de connaissances'),
            ],
            'deliveriesShort' => [
                'email' => t('Email'), 'drive' => t('Drive'), 'slack' => t('Slack'), 'kb' => t('Base'),
            ],
            'deliveryHelp' => [
                'email' => t('Le livrable en message, les documents en pièces jointes.'),
                'drive' => t('Un Google Doc ou un Google Sheet par pièce.'),
                'slack' => t('Un message dans un canal.'),
                'kb'    => t('Pour que vos assistants puissent s’en servir ensuite.'),
            ],
            'mailModes' => [
                'approve' => t('Je relis et j’envoie'),
                'draft'   => t('Préparer un brouillon'),
                'send'    => t('Envoyer sans validation'),
            ],
            'deliveryModes' => [
                'approve' => t('Je valide avant'),
                'auto'    => t('Sans validation'),
            ],
            'units' => ['minutes' => t('minutes'), 'hours' => t('heures'), 'days' => t('jours')],
            'attachmentTypes' => LoopSources::attachmentTypeLabels(),
            'itemError' => [
                WorkflowSpec::ITEM_ERROR_CONTINUE => t('Passer au suivant, le marquer en échec'),
                WorkflowSpec::ITEM_ERROR_STOP     => t('Arrêter le workflow'),
            ],
            'bodyThen' => [
                WorkflowSpec::THEN_CONTINUE => t('continuer le tour'),
                WorkflowSpec::THEN_SKIP     => t('passer à l’élément suivant'),
                WorkflowSpec::THEN_STOP     => t('arrêter le workflow'),
            ],
            'onError' => [
                'stop' => t('Arrêter le workflow'),
                'skip' => t('Ignorer la brique et continuer'),
            ],
            'forms' => array_combine(WorkflowSpec::FORMS, array_map([ArtifactContract::class, 'formLabel'], WorkflowSpec::FORMS))
                + ['file' => ArtifactContract::formLabel('file')]
                + array_combine(array_keys(WorkflowSpec::CREATE_KINDS), array_map([ArtifactContract::class, 'formLabel'], array_keys(WorkflowSpec::CREATE_KINDS))),
            'formHelp' => [
                'text'     => t('Des paragraphes rédigés.'),
                'list'     => t('Un élément par ligne.'),
                'table'    => t('Lignes et colonnes, exportable en Excel.'),
                'fiche'    => t('Des champs nommés : montant, date, email… Une règle peut les lire.'),
                'document' => t('Un fichier Word, PDF, PowerPoint ou Excel.'),
            ],
            'fieldTypes' => [
                'text' => t('Texte court'), 'longtext' => t('Texte long'), 'number' => t('Nombre'), 'date' => t('Date'),
                'choice' => t('Choix'), 'email' => t('Email'), 'yesno' => t('Oui / non'), 'file' => t('Fichier'),
            ],
            'ficheTypes' => [
                'text' => t('Texte'), 'number' => t('Nombre'), 'date' => t('Date'), 'email' => t('Email'),
                'yesno' => t('Oui / non'), 'list' => t('Liste'),
            ],
            'docFormats' => array_combine(WorkflowSpec::DOC_FORMATS, array_map([ArtifactContract::class, 'formatLabel'], WorkflowSpec::DOC_FORMATS)),
            'ops'    => $ops,
            'opsFor' => $opsFor,
            'write'  => [
                'none'    => t('Lire seulement'),
                'approve' => t('Préparer des actions à approuver'),
                'auto'    => t('Agir sans validation'),
            ],
        ];
    }

    /** Silhouette d'un workflow (même dessin que MiaWorkflowRail.silhouette). */
    public static function silhouetteHtml(string $seq): string
    {
        $k = ['d' => 'start', 's' => 'step', 'x' => 'action', 'k' => 'create', 'c' => 'condition', 'a' => 'route', 'l' => 'loop', 'g' => 'gate', 'w' => 'wait', 'n' => 'notify', 'q' => 'stop', 'e' => 'end'];
        $out = '';
        foreach (str_split($seq) as $i => $c) {
            $shape = in_array($c, ['c', 'a'], true) ? 'd' : ($c === 'g' ? 'r' : ($c === 'l' ? 'o' : ''));
            $out .= ($i > 0 ? '<b></b>' : '') . '<i class="' . $shape . '" style="--k:' . self::HUES[$k[$c] ?? 'step'] . '"></i>';
        }
        return '<div class="wf-sil" aria-hidden="true">' . $out . '</div>';
    }

    /**
     * Libellé et ton d'un statut d'exécution (liste, éditeur, exécution).
     *
     * @return array{0: string, 1: string}
     */
    public static function statusLabel(string $status, bool $timer = false): array
    {
        if ($status === WorkflowRun::WAITING && $timer) {
            // Une brique « Attendre » : l'exécution reprendra seule.
            return [t('Reprendra seule'), 'run'];
        }
        return match ($status) {
            WorkflowRun::RUNNING   => [t('En cours'), 'run'],
            WorkflowRun::WAITING   => [t('Attend votre décision'), 'wait'],
            WorkflowRun::PAUSED    => [t('En pause'), 'wait'],
            WorkflowRun::DONE      => [t('Terminé'), 'ok'],
            WorkflowRun::STOPPED   => [t('Arrêté comme prévu'), ''],
            WorkflowRun::FAILED    => [t('En échec'), 'err'],
            WorkflowRun::CANCELLED => [t('Arrêté'), ''],
            default                => [$status, ''],
        };
    }

    /** Ce qui lance un workflow à évènement, en une phrase. */
    public static function eventSentence(?array $event): string
    {
        if ($event === null) {
            return '';
        }
        $filters = WorkflowFilters::summary((array)($event['filters'] ?? []));
        $sentence = match ($event['kind']) {
            'email'    => trim((string)$event['query']) !== ''
                ? t('À chaque email reçu dans {service} qui correspond à « {filtre} »', ['service' => $event['source'] === 'outlook' ? 'Outlook' : 'Gmail', 'filtre' => (string)$event['query']])
                : t('À chaque email reçu dans {service}', ['service' => $event['source'] === 'outlook' ? 'Outlook' : 'Gmail']),
            'file'     => t('À chaque nouveau fichier dans « {dossier} »', ['dossier' => (string)($event['folder_name'] ?: $event['folder'])]),
            'webhook'  => t('À chaque appel de son adresse'),
            'workflow' => t('À la fin d’un autre workflow'),
            default    => '',
        };
        return $sentence !== '' && $filters !== '' ? $sentence . ' · ' . $filters : $sentence;
    }

    /** Comment une exécution a démarré. */
    public static function triggerLabel(string $trigger): string
    {
        return match ($trigger) {
            'schedule' => t('Programmé'),
            'chat'     => t('Depuis le chat'),
            'email'    => t('Un email reçu'),
            'file'     => t('Un nouveau fichier'),
            'webhook'  => t('Appel de son adresse'),
            'workflow' => t('Après un autre workflow'),
            default    => t('À la main'),
        };
    }

    /**
     * Coût estimé d'une exécution (CreditEstimate : les tarifs qu'applique la
     * facturation, sur la consommation réelle des réponses de chaque modèle).
     * Une étape = une réponse de son modèle, plus ses recherches Internet si
     * elle lit le web ; une création = une génération de son outil ; une
     * question à MIA (condition, décision) = un appel du modèle auxiliaire.
     * Avant, la moyenne d'un modèle n'était jamais trouvée (clé
     * « fournisseur::modèle::modèle » cherchée) : toutes les étapes valaient la
     * moyenne de tous les modèles, recherches Internet oubliées. Null quand
     * rien n'est estimable.
     *
     * @return array{min: int, max: int}|null
     */
    public static function estimate(array $def, array $choices, array $counts = []): ?array
    {
        $judgeLlm = class_exists('SemanticChunker') ? (string)(SemanticChunker::resolveChunkingLlm() ?? '') : '';
        $judge = $judgeLlm !== '' ? (float)(CreditEstimate::helperCall(UsageSource::WORKFLOW_JUDGE, $judgeLlm) ?? 0) : 0.0;
        $cost = static function (array $node) use ($choices, $judge): float {
            if ($node['type'] === WorkflowSpec::TYPE_STEP || $node['type'] === WorkflowSpec::TYPE_ACTION) {
                $llm = WorkflowSpec::effectiveLlm($node, $choices);
                $credits = (float)(CreditEstimate::chatReply($llm)['credits'] ?? 0);
                if (in_array('web', (array)($node['sources'] ?? []), true)) {
                    $credits += (float)(CreditEstimate::webSearch($llm) ?? 0) * CreditEstimate::searchesPerReply();
                }
                return $credits;
            }
            if ($node['type'] === WorkflowSpec::TYPE_CREATE) {
                $tool = (int)($node['tool_id'] ?? 0) > 0 ? ChatTool::findById((int)$node['tool_id']) : null;
                return (float)(($tool !== null ? CreditEstimate::toolUse($tool) : null)['credits'] ?? 0);
            }
            if (($node['type'] === WorkflowSpec::TYPE_CONDITION && $node['mode'] === 'question') || $node['type'] === WorkflowSpec::TYPE_ROUTE) {
                return $judge;
            }
            return 0.0;
        };
        $total = 0.0;
        foreach ($def['nodes'] as $node) {
            $total += $cost($node);
            // Une seule voie est prise : on compte la plus chère.
            $lanes = 0.0;
            foreach (WorkflowSpec::branchesOf($node) as $l) {
                $laneCost = 0.0;
                foreach ($l['nodes'] as $c) {
                    $laneCost += $cost($c);
                }
                $lanes = max($lanes, $laneCost);
            }
            $total += $lanes;
            if ($node['type'] === WorkflowSpec::TYPE_LOOP) {
                // Une boucle se paie par tour : le nombre d'éléments connu (un
                // fichier joint à la fenêtre de lancement), sinon la moitié des
                // éléments permis (au plus 25) pour « pour chaque », deux tours
                // pour « jusqu'à ». Repérer les éléments avec MIA : un appel.
                $turns = $node['mode'] === WorkflowSpec::LOOP_EACH
                    ? (isset($counts[$node['key']]) ? max(0, (int)$counts[$node['key']]) : max(1, min(25, (int)ceil(min((int)$node['max'], Workflow::loopMax()) / 2))))
                    : 2;
                if (($node['source']['kind'] ?? '') === LoopSources::AI) {
                    $total += $judge;
                }
                foreach ($node['body'] as $c) {
                    $total += $cost($c) * $turns;
                }
                if ($node['mode'] === WorkflowSpec::LOOP_UNTIL && $node['until']['mode'] === 'question') {
                    $total += $judge * $turns;
                }
            }
        }
        if ($total <= 0) {
            return null;
        }
        return ['min' => (int)max(1, floor($total * 0.6)), 'max' => (int)max(1, ceil($total * 1.6))];
    }
}
