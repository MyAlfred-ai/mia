<?php

declare(strict_types=1);

/**
 * MIA prépare un workflow : depuis une conversation (outil `creer_workflow`,
 * carte à approuver) ou depuis « Décrire à MIA » (/workflows/new). Les deux
 * passent par la MÊME forme d'arguments, en mots simples (« etape »,
 * « consigne », « piece »…), traduite ici en définition (WorkflowSpec).
 *
 * Les pièces se citent par leur NOM entre crochets — « [Synthèse] » — : un
 * modèle ne connaît pas les clés ; la conversion les retrouve.
 */
final class WorkflowDraft
{
    public const TOOL = 'creer_workflow';
    public const ADJUST_URL = '/workflows/new';

    private const FIELD_TYPES = [
        'texte' => 'text', 'texte_court' => 'text', 'texte_long' => 'longtext', 'nombre' => 'number', 'date' => 'date',
        'choix' => 'choice', 'email' => 'email', 'oui_non' => 'yesno', 'fichier' => 'file',
    ];
    private const FICHE_TYPES = [
        'texte' => 'text', 'nombre' => 'number', 'date' => 'date', 'email' => 'email', 'oui_non' => 'yesno', 'liste' => 'list',
    ];
    private const FORMS = [
        'texte' => 'text', 'liste' => 'list', 'tableau' => 'table', 'fiche' => 'fiche', 'document' => 'document',
    ];
    private const WRITES = [
        'lire' => WorkflowSpec::WRITE_NONE, 'approuver' => WorkflowSpec::WRITE_APPROVE, 'sans_validation' => WorkflowSpec::WRITE_AUTO,
    ];
    private const OPS = [
        'egal' => 'eq', 'different' => 'ne', 'superieur' => 'gt', 'superieur_ou_egal' => 'gte', 'inferieur' => 'lt',
        'inferieur_ou_egal' => 'lte', 'contient' => 'contains', 'vide' => 'empty', 'renseigne' => 'notempty', 'oui' => 'yes', 'non' => 'no',
    ];
    /** Ce qu'une boucle parcourt (« parcourir ») → découpeur (LoopSources). */
    private const LOOP_KINDS = [
        'liste' => LoopSources::LIST, 'lignes' => LoopSources::ROWS, 'pieces_jointes' => LoopSources::ATTACHMENTS,
        'slides' => LoopSources::SLIDES, 'pages' => LoopSources::PAGES, 'sections' => LoopSources::SECTIONS,
        'fichiers_drive' => LoopSources::FILES, 'fichiers' => LoopSources::FILES, 'emails' => LoopSources::EMAILS,
        'reperes_par_mia' => LoopSources::AI, 'reperes' => LoopSources::AI,
    ];
    /** Une condition dans une boucle : ce qu'on fait de l'élément. */
    private const BODY_THEN = [
        'continuer' => WorkflowSpec::THEN_CONTINUE, 'element_suivant' => WorkflowSpec::THEN_SKIP, 'passer' => WorkflowSpec::THEN_SKIP,
        'arreter' => WorkflowSpec::THEN_STOP,
    ];
    private const FREQS = [
        'chaque_jour' => 'daily', 'jours_ouvres' => 'weekdays', 'chaque_semaine' => 'weekly', 'chaque_mois' => 'monthly',
    ];
    private const UNITS = ['minutes' => 'minutes', 'minute' => 'minutes', 'heures' => 'hours', 'heure' => 'hours', 'jours' => 'days', 'jour' => 'days'];
    private const MAIL_MODES = ['approuver' => 'approve', 'brouillon' => 'draft', 'envoyer' => 'send'];
    private const DRIVE_KINDS = ['document' => 'doc', 'tableur' => 'sheet', 'presentation' => 'slides', 'pdf' => 'pdf'];
    /** Natures d'une brique « creation » (WorkflowSpec::CREATE_KINDS). */
    private const CREATE_KINDS = ['image' => 'image', 'page' => 'page', 'page_web' => 'page', 'site' => 'page', 'carte' => 'map', 'itineraire' => 'map'];
    private const IMAGE_FORMATS = ['carre' => 'square', 'paysage' => 'landscape', 'portrait' => 'portrait'];

    /** La demande parle-t-elle de préparer un workflow ? (FR / EN) */
    public static function looksLikeWorkflowRequest(string $text): bool
    {
        $t = mb_strtolower($text);
        return (bool)preg_match('/\b(workflows?|flux de travail|processus automatis\w*|enchaînement automatique)\b/u', $t)
            && (bool)preg_match('/\b(cré\w*|prépar\w*|fai[st]\w*|monte\w*|construi\w*|automatis\w*|mets? en place|mettre en place|lanc\w*|create|build|make|set up|run|start)\b/u', $t);
    }

    /**
     * Arguments (outil ou composition) → définition. Ne lève pas : ce qui ne
     * se lit pas est écarté et signalé dans `notes`.
     *
     * @return array{name: string, definition: array, notes: list<string>}
     */
    public static function fromArgs(array $args, array $choices): array
    {
        $notes = [];
        $used = [];
        $names = []; // nom normalisé → clé de pièce

        $fields = [];
        foreach (array_slice(self::listOf($args['depart']['champs'] ?? []), 0, WorkflowSpec::MAX_FIELDS) as $f) {
            if (!is_array($f) || trim((string)($f['nom'] ?? '')) === '') {
                continue;
            }
            $label = trim((string)$f['nom']);
            $type = self::FIELD_TYPES[self::fold((string)($f['type'] ?? 'texte'))] ?? 'text';
            $key = self::freshKey($label, $used);
            $fields[] = [
                'key' => $key, 'label' => $label, 'type' => $type,
                'required' => array_key_exists('obligatoire', $f) ? (bool)$f['obligatoire'] : true,
                'options' => array_map('strval', self::listOf($f['options'] ?? [])),
                'default' => (string)($f['defaut'] ?? ''),
            ];
            if ($type === 'file') {
                $names[self::fold($label)] = $key;
            }
        }
        $ficheName = trim((string)($args['depart']['nom_fiche'] ?? '')) ?: t('Informations de départ');
        if (array_filter($fields, static fn($f) => $f['type'] !== 'file') !== []) {
            $names[self::fold($ficheName)] = WorkflowSpec::START_PIECE;
            foreach (['depart', 'informations_de_depart', 'formulaire'] as $alias) {
                $names[$alias] = $names[$alias] ?? WorkflowSpec::START_PIECE;
            }
        }
        $schedule = null;
        $prog = $args['depart']['programmation'] ?? null;
        if (is_array($prog) && isset(self::FREQS[self::fold((string)($prog['frequence'] ?? ''))])) {
            $schedule = [
                'rule' => [
                    'freq' => self::FREQS[self::fold((string)$prog['frequence'])],
                    'days' => array_map('intval', self::listOf($prog['jours'] ?? [])),
                    'day'  => $prog['jour_du_mois'] ?? 1,
                    'time' => (string)($prog['heure'] ?? '08:00'),
                ],
                'timezone' => (string)($args['fuseau'] ?? ''),
            ];
        }

        $event = null;
        $ev = $args['depart']['evenement'] ?? null;
        if (is_array($ev)) {
            $kind = self::fold((string)($ev['type'] ?? ''));
            $event = match ($kind) {
                'email' => ['kind' => 'email', 'source' => self::fold((string)($ev['source'] ?? '')) === 'outlook' ? 'outlook' : 'gmail', 'query' => (string)($ev['filtre'] ?? '')],
                'fichier', 'file' => ['kind' => 'file', 'folder' => (string)($ev['dossier'] ?? '')],
                'appel', 'webhook' => ['kind' => 'webhook'],
                'workflow' => ['kind' => 'workflow', 'workflow_id' => self::workflowId((string)($ev['nom'] ?? ''), $choices, $notes)],
                default => null,
            };
            if ($event !== null) {
                $event['timezone'] = (string)($args['fuseau'] ?? '');
                $pieceName = WorkflowSpec::EVENT_PIECE_NAMES[$event['kind']];
                $names[self::fold($pieceName)] = WorkflowSpec::EVENT_PIECE;
                foreach (['evenement', 'email', 'email_recu', 'fichier', 'fichier_recu', 'donnees', 'donnees_recues'] as $alias) {
                    $names[$alias] = $names[$alias] ?? WorkflowSpec::EVENT_PIECE;
                }
            }
        }

        $count = 0;
        $nodes = self::nodes(self::listOf($args['briques'] ?? []), $choices, $used, $names, $notes, true, $count);

        $deliveries = [];
        foreach (array_slice(self::listOf($args['livraisons'] ?? []), 0, WorkflowSpec::MAX_DELIVERIES) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $type = self::fold((string)($l['type'] ?? ''));
            $keys = self::pieceKeys($l['pieces'] ?? $l['joindre'] ?? [], $names);
            $d = match ($type) {
                'email' => [
                    'type' => 'email', 'source' => self::fold((string)($l['source'] ?? '')) === 'outlook' ? 'outlook' : 'gmail',
                    'to' => (string)($l['a'] ?? ''), 'to_field' => self::fieldRef((string)($l['a_champ'] ?? ''), $names),
                    'subject' => self::mentions((string)($l['objet'] ?? ''), $names),
                    'message' => self::mentions((string)($l['message'] ?? ''), $names), 'attach' => self::pieceKeys($l['joindre'] ?? [], $names),
                    'mode' => self::MAIL_MODES[self::fold((string)($l['envoi'] ?? 'approuver'))] ?? 'approve',
                ],
                'drive' => ['type' => 'drive', 'pieces' => $keys, 'folder' => (string)($l['dossier'] ?? ''), 'mode' => 'approve'],
                'slack' => ['type' => 'slack', 'channel' => (string)($l['canal'] ?? ''), 'message' => self::mentions((string)($l['message'] ?? ''), $names), 'pieces' => self::pieceKeys($l['pieces'] ?? [], $names), 'mode' => 'approve'],
                'base', 'kb' => ['type' => 'kb', 'kb_id' => self::kbId((string)($l['base'] ?? ''), $choices, $notes), 'pieces' => $keys, 'replace' => true],
                default => null,
            };
            if ($d !== null) {
                $deliveries[] = $d;
            }
        }

        $end = [];
        foreach (self::listOf($args['livrable'] ?? []) as $n) {
            $k = $names[self::fold((string)$n)] ?? null;
            if ($k !== null) {
                $end[] = $k;
            }
        }
        $def = WorkflowSpec::normalize([
            'objective' => (string)($args['objectif'] ?? ''),
            'start' => ['fields' => $fields, 'fiche_name' => $ficheName, 'schedule' => $schedule, 'chat' => true, 'event' => $event],
            'nodes' => $nodes,
            'end' => ['pieces' => $end, 'deliveries' => $deliveries],
        ]);
        if ($def['end']['pieces'] === []) {
            // À défaut, la dernière pièce remise sur le rail.
            $pieces = array_filter(WorkflowSpec::pieces($def), static fn($p) => $p['producer'] !== WorkflowSpec::START_KEY && $p['branch'] === null);
            if ($pieces !== []) {
                $def['end']['pieces'] = [array_key_last($pieces)];
            }
        }
        // Ajusté à la personne : ses services, ses outils (WorkflowFit).
        $def = WorkflowFit::apply($def, $choices, $notes);
        return [
            'name' => mb_substr(trim((string)($args['nom'] ?? '')) ?: t('Nouveau workflow'), 0, WorkflowSpec::NAME_MAX),
            'definition' => $def,
            'notes' => $notes,
        ];
    }

    private static function nodes(array $list, array $choices, array &$used, array &$names, array &$notes, bool $allowCondition, int &$count, bool $inLane = false, bool $inLoop = false): array
    {
        $out = [];
        foreach ($list as $b) {
            if (!is_array($b) || $count >= WorkflowSpec::MAX_NODES) {
                continue;
            }
            $type = self::fold((string)($b['type'] ?? ''));
            $title = trim((string)($b['titre'] ?? ''));
            $key = self::freshKey($title !== '' ? $title : $type, $used);
            switch ($type) {
                case 'etape':
                    $count++;
                    $piece = is_array($b['piece'] ?? null) ? $b['piece'] : [];
                    $pieceName = trim((string)($piece['nom'] ?? '')) ?: $title;
                    $pieceKey = self::freshKey($pieceName, $used);
                    $form = self::FORMS[self::fold((string)($piece['forme'] ?? 'texte'))] ?? 'text';
                    $ficheFields = [];
                    foreach (self::listOf($piece['champs'] ?? []) as $cf) {
                        if (is_array($cf) && trim((string)($cf['nom'] ?? '')) !== '') {
                            $ficheFields[] = ['label' => trim((string)$cf['nom']), 'type' => self::FICHE_TYPES[self::fold((string)($cf['type'] ?? 'texte'))] ?? 'text'];
                        }
                    }
                    [$profileId, $llm] = self::assistant($b['assistant'] ?? null, $choices, $notes, $title);
                    $node = [
                        'key' => $key, 'type' => 'step', 'title' => $title,
                        'on_error' => self::onError($b),
                        'profile_id' => $profileId, 'llm' => $llm,
                        'instruction' => self::mentions((string)($b['consigne'] ?? ''), $names),
                        'reads' => self::pieceKeys($b['lit'] ?? [], $names),
                        // Ajustées ensuite (WorkflowFit) : l'équivalent que la
                        // personne a remplace un service qu'elle n'a pas.
                        'sources' => array_map(static fn($v): string => self::fold((string)$v), self::listOf($b['sources'] ?? [])),
                        'kb_ids' => self::kbIds($b['bases'] ?? [], $choices, $notes, $title),
                        'write' => self::WRITES[self::fold((string)($b['ecritures'] ?? 'lire'))] ?? WorkflowSpec::WRITE_NONE,
                        'review' => !empty($b['relecture']),
                        'filters' => self::filters($b, $names),
                        'output' => ['key' => $pieceKey, 'name' => $pieceName, 'form' => $form, 'fields' => $ficheFields,
                            'format' => in_array((string)($piece['format'] ?? ''), WorkflowSpec::DOC_FORMATS, true) ? (string)$piece['format'] : 'docx'],
                    ];
                    $names[self::fold($pieceName)] = $pieceKey;
                    // Une validation renvoie à la brique par son titre ou sa pièce.
                    $names['#' . self::fold($title)] = $key;
                    $names['#' . self::fold($pieceName)] = $key;
                    $out[] = $node;
                    break;

                case 'action':
                    $count++;
                    $target = self::fold((string)($b['outil'] ?? ''));
                    $node = [
                        'key' => $key, 'type' => 'action', 'title' => $title,
                        'on_error' => self::onError($b),
                        'target' => array_key_exists($target, WorkflowSpec::ACTION_TARGETS) ? $target : '',
                        'instruction' => self::mentions((string)($b['consigne'] ?? ''), $names),
                        'reads' => self::pieceKeys($b['lit'] ?? [], $names),
                        'write' => self::fold((string)($b['ecritures'] ?? '')) === 'sans_validation' ? WorkflowSpec::WRITE_AUTO : WorkflowSpec::WRITE_APPROVE,
                        'filters' => self::filters($b, $names),
                    ];
                    if (str_starts_with(WorkflowSpec::ACTION_TARGETS[$node['target']] ?? '', 'tool:')) {
                        $toolType = substr(WorkflowSpec::ACTION_TARGETS[$node['target']], 5);
                        foreach ($choices['tools'] ?? [] as $tool) {
                            if ($tool['type'] === $toolType && $tool['reason'] === null) {
                                $node['tool_id'] = $tool['id'];
                                break;
                            }
                        }
                    }
                    if ($node['target'] === '') {
                        $notes[] = t('« {titre} » : outil non reconnu, à choisir dans l’éditeur.', ['titre' => $title]);
                    }
                    $out[] = $node;
                    break;

                case 'creation':
                    $count++;
                    $kind = self::CREATE_KINDS[self::fold((string)($b['nature'] ?? 'image'))] ?? 'image';
                    $pieceRaw = $b['piece'] ?? '';
                    $pieceName = trim((string)(is_array($pieceRaw) ? ($pieceRaw['nom'] ?? '') : $pieceRaw)) ?: $title;
                    $pieceKey = self::freshKey($pieceName, $used);
                    // L'outil : le premier de cette nature ouvert à la personne et en service.
                    $toolId = null;
                    foreach ($choices['creations'] ?? [] as $tool) {
                        if ($tool['kind'] === $kind && $tool['reason'] === null) {
                            $toolId = $tool['id'];
                            break;
                        }
                    }
                    if ($toolId === null) {
                        $notes[] = t('« {titre} » : aucun outil de création de ce type ne vous est ouvert, à choisir dans l’éditeur.', ['titre' => $title]);
                    }
                    $out[] = [
                        'key' => $key, 'type' => 'create', 'title' => $title, 'kind' => $kind, 'tool_id' => $toolId,
                        'on_error' => self::onError($b),
                        'instruction' => self::mentions((string)($b['consigne'] ?? ''), $names),
                        'reads' => self::pieceKeys($b['lit'] ?? [], $names),
                        'review' => !empty($b['relecture']),
                        'options' => ['format' => self::IMAGE_FORMATS[self::fold((string)($b['format'] ?? ''))] ?? '', 'style' => (string)($b['style'] ?? '')],
                        'output' => ['key' => $pieceKey, 'name' => $pieceName, 'form' => $kind],
                    ];
                    $names[self::fold($pieceName)] = $pieceKey;
                    // Une validation renvoie à la brique par son titre ou sa pièce.
                    $names['#' . self::fold($title)] = $key;
                    $names['#' . self::fold($pieceName)] = $key;
                    break;

                case 'validation':
                    $count++;
                    $gate = [
                        'key' => $key, 'type' => 'gate', 'title' => $title,
                        'reviews' => self::pieceKeys($b['relit'] ?? [], $names),
                        'note' => self::mentions((string)($b['consigne'] ?? ''), $names),
                    ];
                    // Renvoyer à une étape d'avant (par son titre ou sa pièce),
                    // corriger soi-même la pièce, délai de réponse.
                    $back = self::fold((string)($b['renvoie_a'] ?? ''));
                    if ($back !== '') {
                        $gate['returns_to'] = $names['#' . $back] ?? '';
                    }
                    if (array_key_exists('peut_corriger', $b)) {
                        $gate['can_correct'] = filter_var($b['peut_corriger'], FILTER_VALIDATE_BOOLEAN);
                    }
                    if ((int)($b['delai_jours'] ?? 0) > 0) {
                        $gate['days'] = (int)$b['delai_jours'];
                    }
                    $out[] = $gate;
                    break;

                case 'attente':
                    $count++;
                    $jusqua = is_array($b['jusqu_a'] ?? null) ? $b['jusqu_a'] : null;
                    $node = ['key' => $key, 'type' => 'wait', 'title' => $title !== '' ? $title : t('Attendre')];
                    if ($jusqua !== null) {
                        $node += [
                            'mode' => 'date', 'piece' => $names[self::fold((string)($jusqua['piece'] ?? ''))] ?? '',
                            'field' => WorkflowSpec::slug((string)($jusqua['champ'] ?? '')), 'time' => (string)($b['heure'] ?? '09:00'),
                            'offset' => (int)($b['decalage'] ?? 0),
                        ];
                    } else {
                        $node += ['mode' => 'delay', 'amount' => max(1, (int)($b['duree'] ?? 1)), 'unit' => self::UNITS[self::fold((string)($b['unite'] ?? 'jours'))] ?? 'days'];
                    }
                    $out[] = $node;
                    break;

                case 'arreter':
                case 'arret':
                    if (!$inLane) {
                        $notes[] = t('Une brique « Arrêter le workflow » hors d’une voie a été écartée : elle termine une voie de condition ou de décision.');
                        break;
                    }
                    $count++;
                    $out[] = [
                        'key' => $key, 'type' => WorkflowSpec::TYPE_STOP, 'title' => $title !== '' ? $title : t('Arrêter le workflow'),
                        'message' => self::mentions((string)($b['message'] ?? ''), $names),
                        'outcome' => self::fold((string)($b['statut'] ?? '')) === 'arretee' ? WorkflowSpec::STOP_STOPPED : WorkflowSpec::STOP_DONE,
                    ];
                    break;

                case 'prevenir':
                    $count++;
                    $out[] = ['key' => $key, 'type' => 'notify', 'title' => $title !== '' ? $title : t('Me prévenir'), 'message' => self::mentions((string)($b['message'] ?? ''), $names)];
                    break;

                case 'boucle':
                    if (!$allowCondition) {
                        $notes[] = t('Une boucle dans une voie ou une autre boucle a été écartée.');
                        break;
                    }
                    $count++;
                    $until = trim((string)($b['jusqu_a'] ?? ''));
                    $node = ['key' => $key, 'type' => 'loop', 'title' => $title, 'mode' => $until !== '' ? 'until' : 'each',
                        'max' => (int)($b['max'] ?? 0)];
                    if ($until === '') {
                        $node += self::loopSource($b, $names, $notes, $title);
                        $itemName = trim((string)($b['element'] ?? '')) ?: t('Élément en cours');
                        $itemKey = self::freshKey($itemName, $used);
                        $names[self::fold($itemName)] = $itemKey;
                        $node['item'] = ['key' => $itemKey, 'name' => $itemName];
                        // Quels éléments garder : une règle sur leurs champs, sans doublon.
                        $keep = is_array($b['garder_si'] ?? null) ? $b['garder_si'] : null;
                        $node['filter'] = [
                            'rule' => $keep !== null && trim((string)($keep['champ'] ?? '')) !== '' ? ['join' => 'all', 'items' => [[
                                'piece' => $itemKey, 'field' => WorkflowSpec::slug((string)$keep['champ']),
                                'op' => self::OPS[self::fold((string)($keep['operateur'] ?? 'egal'))] ?? 'eq', 'value' => (string)($keep['valeur'] ?? ''),
                            ]]] : ['join' => 'all', 'items' => []],
                            'dedupe' => trim((string)($b['sans_doublon'] ?? '')) !== '' ? WorkflowSpec::slug((string)$b['sans_doublon']) : '',
                            'require_label' => true,
                        ];
                        $node['on_item_error'] = self::fold((string)($b['si_erreur_element'] ?? '')) === 'arreter' ? WorkflowSpec::ITEM_ERROR_STOP : WorkflowSpec::ITEM_ERROR_CONTINUE;
                        $node['confirm'] = !empty($b['relire_liste']);
                        $node['review_actions'] = !empty($b['relire_actions']);
                    } else {
                        $node['until'] = ['mode' => 'question', 'question' => '', 'reads' => []];
                    }
                    $node['body'] = self::nodes(self::listOf($b['briques'] ?? []), $choices, $used, $names, $notes, false, $count, false, $until === '');
                    if ($until !== '') {
                        $node['until']['question'] = self::mentions($until, $names);
                    }
                    $outName = trim((string)($b['resultat'] ?? '')) ?: ($title !== '' ? $title . ' — ' . t('résultats') : t('Résultats'));
                    $outKey = self::freshKey($outName, $used);
                    $node['output'] = ['key' => $outKey, 'name' => $outName];
                    $names[self::fold($outName)] = $outKey;
                    $out[] = $node;
                    break;

                case 'decision':
                case 'aiguillage':
                    if (!$allowCondition) {
                        $notes[] = t('Une décision dans une voie a été écartée : une voie ne porte pas de décision.');
                        break;
                    }
                    $count++;
                    $routes = [];
                    $fallback = WorkflowSpec::FALLBACK_ASK;
                    $doute = self::fold((string)($b['si_doute'] ?? 'demander'));
                    foreach (array_slice(self::listOf($b['voies'] ?? []), 0, WorkflowSpec::MAX_ROUTES) as $v) {
                        if (!is_array($v)) {
                            continue;
                        }
                        $label = trim((string)($v['nom'] ?? ''));
                        $laneKey = WorkflowSpec::slug($label) ?: 'voie_' . (count($routes) + 1);
                        $routes[] = [
                            'key'   => ctype_alpha($laneKey[0]) ? $laneKey : 'voie_' . $laneKey,
                            'label' => $label,
                            'when'  => self::mentions((string)($v['quand'] ?? ''), $names),
                            'nodes' => self::nodes(self::listOf($v['briques'] ?? []), $choices, $used, $names, $notes, false, $count, true),
                            'then'  => self::fold((string)($v['ensuite'] ?? 'continuer')) === 'arreter' ? WorkflowSpec::THEN_STOP : WorkflowSpec::THEN_CONTINUE,
                        ];
                        if ($doute !== 'demander' && $doute === self::fold($label)) {
                            $fallback = end($routes)['key'];
                        }
                    }
                    $out[] = [
                        'key' => $key, 'type' => 'route', 'title' => $title !== '' ? $title : t('Décision de MIA'),
                        'instruction' => self::mentions((string)($b['criteres'] ?? ''), $names),
                        'reads' => self::pieceKeys($b['lit'] ?? [], $names),
                        'routes' => $routes,
                        'fallback' => $fallback,
                        'on_error' => self::onError($b),
                    ];
                    break;

                case 'condition':
                    if (!$allowCondition && !$inLoop) {
                        $notes[] = t('Une condition dans une voie a été écartée : une voie ne porte pas de condition.');
                        break;
                    }
                    $count++;
                    $rule = is_array($b['regle'] ?? null) ? $b['regle'] : null;
                    $node = ['key' => $key, 'type' => 'condition', 'title' => $title];
                    if ($rule !== null && !empty($rule['piece'])) {
                        $pk = $names[self::fold((string)$rule['piece'])] ?? '';
                        $node['mode'] = 'rule';
                        $node['rule'] = ['join' => 'all', 'items' => [[
                            'piece' => $pk,
                            'field' => WorkflowSpec::slug((string)($rule['champ'] ?? '')),
                            'op' => self::OPS[self::fold((string)($rule['operateur'] ?? 'egal'))] ?? 'eq',
                            'value' => (string)($rule['valeur'] ?? ''),
                        ]]];
                    } else {
                        $node['mode'] = 'question';
                        $node['question'] = self::mentions((string)($b['question'] ?? ''), $names);
                        $node['reads'] = self::pieceKeys($b['lit'] ?? [], $names);
                        // Si MIA hésite : vous demander (par défaut) ou répondre « non ».
                        $node['doubt'] = self::fold((string)($b['si_doute'] ?? '')) === 'non' ? WorkflowSpec::DOUBT_NO : WorkflowSpec::DOUBT_ASK;
                    }
                    if ($inLoop) {
                        // Dans une boucle : une condition de garde, sans voie — elle décide
                        // de l'élément (continuer le tour, passer au suivant, tout arrêter).
                        foreach (['yes' => 'si_oui', 'no' => 'si_non'] as $lane => $arg) {
                            $then = is_array($b[$arg] ?? null) ? (string)($b[$arg]['ensuite'] ?? '') : (string)($b[$arg] ?? '');
                            $node[$lane] = ['nodes' => [], 'then' => self::BODY_THEN[self::fold($then)] ?? ($lane === 'yes' ? WorkflowSpec::THEN_CONTINUE : WorkflowSpec::THEN_SKIP)];
                        }
                        $out[] = $node;
                        break;
                    }
                    foreach (['yes' => 'si_oui', 'no' => 'si_non'] as $lane => $arg) {
                        $l = is_array($b[$arg] ?? null) ? $b[$arg] : [];
                        $node[$lane] = [
                            'nodes' => self::nodes(self::listOf($l['briques'] ?? []), $choices, $used, $names, $notes, false, $count, true),
                            'then' => self::fold((string)($l['ensuite'] ?? ($lane === 'yes' ? 'continuer' : 'arreter'))) === 'arreter'
                                ? WorkflowSpec::THEN_STOP : WorkflowSpec::THEN_CONTINUE,
                        ];
                    }
                    $out[] = $node;
                    break;
            }
        }
        return $out;
    }

    /**
     * Ce que parcourt une boucle « pour chaque » : « parcourir » (liste, lignes,
     * pieces_jointes, slides, pages, sections, fichiers_drive, emails,
     * reperes_par_mia), « pour_chaque » (la pièce lue, « Fiche.Champ » pour un
     * champ liste), « dossier » (fichier ou dossier Drive), « recherche »
     * (emails), « colonnes », « consigne_reperage » + « champs » (MIA repère).
     */
    private static function loopSource(array $b, array $names, array &$notes, string $title): array
    {
        $kind = self::LOOP_KINDS[self::fold((string)($b['parcourir'] ?? ''))] ?? '';
        $ref = trim((string)($b['pour_chaque'] ?? ''));
        $overField = '';
        $over = $names[self::fold($ref)] ?? '';
        if ($over === '' && str_contains($ref, '.')) {
            // « Nom de Fiche.Nom du champ liste ».
            [$pieceName, $fieldName] = array_map('trim', explode('.', $ref, 2));
            $over = $names[self::fold($pieceName)] ?? '';
            $overField = $over !== '' ? WorkflowSpec::slug($fieldName) : '';
            $kind = $kind === '' ? LoopSources::LIST : $kind;
        }
        $drive = self::mentions(trim((string)($b['dossier'] ?? '')), $names);
        $source = ['kind' => $kind];
        if (in_array($kind, LoopSources::DRIVE_FILE_KINDS, true)) {
            $source['from'] = $over === '' && $drive !== '' ? LoopSources::FROM_DRIVE : LoopSources::FROM_PIECE;
            $source['drive'] = $drive;
        }
        if ($kind === LoopSources::FILES) {
            $source += ['from' => LoopSources::FROM_DRIVE, 'drive' => $drive, 'name' => trim((string)($b['nom_fichier'] ?? '')),
                'file_kind' => ['document' => 'doc', 'tableur' => 'sheet', 'presentation' => 'slides', 'pdf' => 'pdf'][self::fold((string)($b['nature'] ?? ''))] ?? ''];
        }
        if (in_array($kind, [LoopSources::EMAILS, LoopSources::ATTACHMENTS], true)) {
            $query = self::mentions(trim((string)($b['recherche'] ?? '')), $names);
            $source += [
                'from' => $kind === LoopSources::EMAILS || ($over === '' && $query !== '') ? LoopSources::FROM_SEARCH : LoopSources::FROM_PIECE,
                'service' => self::fold((string)($b['source'] ?? '')) === 'outlook' ? 'outlook' : 'gmail',
                'query' => $query, 'days' => (int)($b['jours'] ?? 7), 'unread' => !empty($b['non_lus']),
            ];
        }
        if ($kind === LoopSources::ROWS) {
            $source['columns'] = array_values(array_filter(array_map(static fn($c): ?array => trim((string)(is_array($c) ? ($c['nom'] ?? '') : $c)) !== ''
                ? ['label' => trim((string)(is_array($c) ? $c['nom'] : $c)), 'type' => self::FICHE_TYPES[self::fold((string)(is_array($c) ? ($c['type'] ?? 'texte') : 'texte'))] ?? 'text']
                : null, self::listOf($b['colonnes'] ?? []))));
        }
        if ($kind === LoopSources::AI) {
            $source['instruction'] = self::mentions(trim((string)($b['consigne_reperage'] ?? '')), $names);
            $source['fields'] = array_values(array_filter(array_map(static fn($c): ?array => is_array($c) && trim((string)($c['nom'] ?? '')) !== ''
                ? ['label' => trim((string)$c['nom']), 'type' => self::FICHE_TYPES[self::fold((string)($c['type'] ?? 'texte'))] ?? 'text']
                : null, self::listOf($b['champs'] ?? []))));
        }
        if ($over === '' && LoopSources::readsPiece($source)) {
            $notes[] = t('« {titre} » : la liste à parcourir n’a pas été reconnue, à choisir dans l’éditeur.', ['titre' => $title]);
        }
        return ['over' => LoopSources::readsPiece($source) ? $over : '', 'over_field' => $overField, 'source' => $kind === '' ? ['kind' => ''] : $source];
    }

    /**
     * Filtres d'une étape ou d'une action : « filtres » = {de, objet, mots,
     * pieces_jointes, non_lus, jours, dossier, nom, nature, destinataires}.
     */
    private static function filters(array $b, array $names): array
    {
        $f = is_array($b['filtres'] ?? null) ? $b['filtres'] : [];
        $m = static fn(string $k): string => self::mentions(trim((string)($f[$k] ?? '')), $names);
        return WorkflowFilters::normalize([
            'mail' => [
                'from' => $m('de'), 'subject' => $m('objet'), 'words' => $m('mots'),
                'attachment' => !empty($f['pieces_jointes']), 'unread' => !empty($f['non_lus']), 'days' => (int)($f['jours'] ?? 0),
            ],
            'drive' => [
                'folder' => $m('dossier'), 'name' => $m('nom'), 'kind' => self::DRIVE_KINDS[self::fold((string)($f['nature'] ?? ''))] ?? '',
                'days' => (int)($f['jours'] ?? 0),
            ],
            'recipients' => $m('destinataires'),
        ]);
    }

    /** Reprise sur erreur d'une brique : « si_erreur » (arreter | ignorer), « essais » (0 à 3). */
    private static function onError(array $b): array
    {
        return [
            'retries' => array_key_exists('essais', $b) ? (int)$b['essais'] : WorkflowSpec::RETRIES_DEFAULT,
            'then' => self::fold((string)($b['si_erreur'] ?? '')) === 'ignorer' ? WorkflowSpec::ON_ERROR_SKIP : WorkflowSpec::ON_ERROR_STOP,
        ];
    }

    /** Un autre workflow de la personne, par son nom. */
    private static function workflowId(string $name, array $choices, array &$notes): ?int
    {
        foreach ($choices['workflows'] ?? [] as $id => $w) {
            if (self::fold((string)$w['name']) === self::fold($name)) {
                return (int)$id;
            }
        }
        if (trim($name) !== '') {
            $notes[] = t('Le workflow « {nom} » qui doit lancer celui-ci est introuvable : à choisir dans l’éditeur.', ['nom' => $name]);
        }
        return null;
    }

    /** Une base que la personne gère, par son nom. */
    private static function kbId(string $name, array $choices, array &$notes): ?int
    {
        foreach ($choices['delivery_kbs'] ?? [] as $id => $kb) {
            if (self::fold((string)$kb['name']) === self::fold($name)) {
                return (int)$id;
            }
        }
        $notes[] = t('La base « {nom} » est introuvable : à choisir dans l’éditeur.', ['nom' => $name]);
        return null;
    }

    /**
     * Bases d'une étape, par nom ou numéro, parmi celles que la personne peut
     * interroger ({@see WorkflowSpec::MAX_KBS} au plus).
     *
     * @return list<int>
     */
    private static function kbIds(mixed $list, array $choices, array &$notes, string $title): array
    {
        $out = [];
        foreach (self::listOf($list) as $ref) {
            $ref = trim((string)$ref);
            if ($ref === '') {
                continue;
            }
            $id = null;
            foreach ($choices['kbs'] ?? [] as $kid => $kb) {
                if ((ctype_digit($ref) && (int)$ref === (int)$kid) || self::fold((string)$kb['name']) === self::fold($ref)) {
                    $id = (int)$kid;
                    break;
                }
            }
            if ($id === null) {
                $notes[] = t('« {titre} » : base « {nom} » introuvable, à choisir dans l’éditeur.', ['titre' => $title, 'nom' => $ref]);
                continue;
            }
            $reason = $choices['kbs'][$id]['reason'] ?? null;
            if ($reason !== null) {
                $notes[] = t('« {titre} » : la base « {nom} » n’est pas interrogeable pour l’instant ({raison}).', [
                    'titre' => $title, 'nom' => (string)$choices['kbs'][$id]['name'], 'raison' => (string)$reason,
                ]);
                continue;
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, WorkflowSpec::MAX_KBS);
    }

    /**
     * « Nom de la fiche.Nom du champ » (ou « Nom du champ » seul : la fiche de
     * départ) → « clé.champ », ou '' s'il ne désigne rien.
     */
    private static function fieldRef(string $ref, array $names): string
    {
        $ref = trim($ref, " \t\n\r[]");
        if ($ref === '') {
            return '';
        }
        $piece = WorkflowSpec::START_PIECE;
        $field = $ref;
        if (str_contains($ref, '.')) {
            [$p, $field] = explode('.', $ref, 2);
            $piece = $names[self::fold(trim($p, ' []'))] ?? '';
        }
        $field = WorkflowSpec::slug($field);
        return $piece !== '' && $field !== '' ? $piece . '.' . $field : '';
    }

    /** Assistant désigné par numéro ou nom (« aucun » = MIA). @return array{0: ?int, 1: string} */
    private static function assistant(mixed $ref, array $choices, array &$notes, string $title): array
    {
        $ref = trim((string)($ref ?? ''));
        if ($ref === '' || in_array(self::fold($ref), ['aucun', 'mia', 'none'], true)) {
            return [null, ''];
        }
        if (ctype_digit($ref) && isset($choices['profiles'][(int)$ref])) {
            return [(int)$ref, ''];
        }
        foreach ($choices['profiles'] ?? [] as $id => $p) {
            if (self::fold((string)$p['name']) === self::fold($ref)) {
                return [(int)$id, ''];
            }
        }
        $notes[] = t('« {titre} » : assistant « {nom} » introuvable, l’étape est confiée à MIA.', ['titre' => $title, 'nom' => $ref]);
        return [null, ''];
    }

    /** « [Nom de la pièce] » et « @Nom » → `@{clé}`. */
    public static function mentions(string $text, array $names): string
    {
        return (string)preg_replace_callback('/\[([^\]\n]{1,80})\]|@\{([^}\n]{1,80})\}/u', static function (array $m) use ($names): string {
            $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            $key = $names[self::fold($name)] ?? null;
            if ($key === null && preg_match('/^[a-z0-9_]{1,24}$/', $name) && in_array($name, $names, true)) {
                $key = $name;
            }
            return $key !== null ? '@{' . $key . '}' : $m[0];
        }, $text);
    }

    private static function pieceKeys(mixed $list, array $names): array
    {
        $out = [];
        foreach (self::listOf($list) as $n) {
            $k = $names[self::fold((string)$n)] ?? null;
            if ($k !== null && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private static function freshKey(string $text, array &$used): string
    {
        $base = WorkflowSpec::slug($text) ?: 'b';
        $key = $base;
        $i = 2;
        while (isset($used[$key]) || in_array($key, [WorkflowSpec::START_KEY, WorkflowSpec::END_KEY, WorkflowSpec::START_PIECE], true)) {
            $suffix = '_' . $i++;
            $key = substr($base, 0, WorkflowSpec::KEY_MAX - strlen($suffix)) . $suffix;
        }
        $used[$key] = true;
        return $key;
    }

    /** Forme comparable d'un nom ou d'un mot-clé : minuscules sans accents, « _ ». */
    private static function fold(string $s): string
    {
        return WorkflowSpec::slug($s);
    }

    private static function listOf(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    // ==================================================================
    // Carte, composition
    // ==================================================================

    /** Le rail en lignes lisibles (carte du chat, aperçu). */
    public static function railLines(array $def, array $choices): array
    {
        $lines = [];
        $allPieces = WorkflowSpec::pieces($def);
        $line = static function (array $n, string $prefix = '') use ($choices, $allPieces): array {
            $who = '';
            if ($n['type'] === 'step') {
                $who = $n['profile_id'] !== null ? (string)($choices['profiles'][$n['profile_id']]['name'] ?? t('Assistant')) : 'MIA';
            } elseif ($n['type'] === 'action') {
                $who = WorkflowCatalog::targetVerb((string)$n['target']);
            } elseif ($n['type'] === 'create') {
                $who = (string)($choices['creations'][$n['tool_id']]['name'] ?? (WorkflowCatalog::labels()['createKinds'][$n['kind']] ?? ''));
            } elseif ($n['type'] === 'loop') {
                $who = $n['mode'] === WorkflowSpec::LOOP_UNTIL
                    ? t('jusqu’à une condition, {n} tours au plus', ['n' => $n['max']])
                    : (($n['source']['kind'] ?? '') !== ''
                        ? LoopSources::describe($n, $allPieces[$n['over']] ?? null) . ' — ' . t('{n} au plus', ['n' => $n['max']])
                        : t('pour chaque élément, {n} au plus', ['n' => $n['max']]));
            } elseif ($n['type'] === 'route') {
                $who = I18n::plural(count($n['routes']), 'MIA choisit parmi {n} voie', 'MIA choisit parmi {n} voies');
            } elseif ($n['type'] === WorkflowSpec::TYPE_STOP) {
                $who = $n['outcome'] === WorkflowSpec::STOP_STOPPED ? t('statut Arrêtée') : t('statut Terminée');
            } elseif ($n['type'] === 'wait') {
                $who = $n['mode'] === 'date' ? t('jusqu’à une date du dossier') : t('{n} {unite}', ['n' => $n['amount'], 'unite' => (string)(WorkflowCatalog::labels()['units'][$n['unit']] ?? $n['unit'])]);
            }
            return ['kind' => $n['type'], 'title' => $prefix . $n['title'], 'sub' => $who, 'review' => !empty($n['review'])];
        };
        $fields = array_map(static fn($f) => $f['label'], $def['start']['fields']);
        $event = $def['start']['event'] ?? null;
        $lines[] = ['kind' => 'start', 'title' => $event !== null ? WorkflowCatalog::eventSentence($event) : ($def['start']['schedule'] !== null
            ? Recurrence::describe($def['start']['schedule']['rule']) : t('Lancement à la main')),
            'sub' => $fields !== [] ? implode(', ', array_slice($fields, 0, 5)) : '', 'review' => false];
        foreach ($def['nodes'] as $n) {
            $lines[] = $line($n);
            if ($n['type'] === 'loop') {
                foreach ($n['body'] as $c) {
                    $lines[] = $line($c, t('Boucle') . ' · ');
                }
            }
            foreach (WorkflowSpec::branchesOf($n) as $lane => $l) {
                $label = $n['type'] === 'condition' ? ($lane === 'yes' ? t('Si oui') : t('Si non')) : WorkflowSpec::laneLabel($n, (string)$lane);
                foreach ($l['nodes'] as $c) {
                    $lines[] = $line($c, $label . ' · ');
                }
                if ($l['then'] === WorkflowSpec::THEN_STOP && !in_array(WorkflowSpec::TYPE_STOP, array_column($l['nodes'], 'type'), true)) {
                    $lines[] = ['kind' => 'stop', 'title' => $label . ' · ' . t('fin du workflow'), 'sub' => '', 'review' => false];
                }
            }
        }
        $pieces = WorkflowSpec::pieces($def);
        $lines[] = ['kind' => 'end', 'title' => $def['end']['title'], 'sub' => implode(', ', array_map(static fn($k) => $pieces[$k]['name'] ?? $k, $def['end']['pieces'])), 'review' => false];
        foreach ($def['end']['deliveries'] ?? [] as $d) {
            $lines[] = ['kind' => 'delivery', 'title' => WorkflowDeliveries::label($d), 'sub' => match ($d['mode'] ?? 'approve') {
                'approve' => t('après votre accord'), 'draft' => t('en brouillon'), default => t('sans validation'),
            }, 'review' => ($d['mode'] ?? 'approve') === 'approve'];
        }
        return $lines;
    }

    /** Couleurs proposées à un workflow que MIA prépare : celles des modèles livrés. */
    public const COLORS = ['#0ea5e9', '#6366f1', '#14b8a6', '#84cc16', '#f43f5e', '#8b5cf6', '#f59e0b', '#d946ef', '#f97316', '#10a37f'];

    public const PLAN_PUBLISH = 'publish';
    public const PLAN_TRIGGER = 'trigger';
    public const PLAN_ISSUES  = 'issues';

    /** Une couleur stable, tirée du nom : deux workflows préparés ne se ressemblent pas tous. Pure. */
    public static function colorFor(string $name): string
    {
        return self::COLORS[crc32(self::fold($name) ?: 'workflow') % count(self::COLORS)];
    }

    /**
     * Ce que devient un workflow approuvé dans une conversation :
     * - `publish` : rien n'est à régler et il ne démarre qu'à la demande
     *   (à la main, depuis le chat) — il est publié aussitôt, prêt à lancer ;
     * - `trigger` : il démarrerait SEUL (programmation, email, fichier,
     *   adresse d'appel, fin d'un autre workflow) — il reste en brouillon, la
     *   personne le met en service en le publiant ;
     * - `issues` : des points bloquent — brouillon, l'éditeur les liste.
     * Pure.
     *
     * @return array{mode: string, blocks: int}
     */
    public static function approvalPlan(array $def, array $choices): array
    {
        $blocks = count(array_filter(WorkflowSpec::issues($def, $choices), static fn(array $i): bool => $i['level'] === WorkflowSpec::LEVEL_BLOCK));
        if ($blocks > 0) {
            return ['mode' => self::PLAN_ISSUES, 'blocks' => $blocks];
        }
        $auto = ($def['start']['schedule'] ?? null) !== null || ($def['start']['event'] ?? null) !== null;
        return ['mode' => $auto ? self::PLAN_TRIGGER : self::PLAN_PUBLISH, 'blocks' => 0];
    }

    /** Aperçu de la carte d'approbation (chat). */
    public static function actionPreview(string $name, array $def, array $choices, array $notes = []): array
    {
        $c = WorkflowSpec::counts($def);
        $impacts = [
            t('{etapes} · {conditions} · {validations} · {actions} · {pieces}', [
                'etapes' => I18n::plural($c['steps'], '{n} étape', '{n} étapes'),
                'conditions' => I18n::plural($c['conditions'], '{n} condition', '{n} conditions'),
                'validations' => I18n::plural($c['gates'], '{n} validation', '{n} validations'),
                'actions' => I18n::plural($c['actions'], '{n} action', '{n} actions'),
                'pieces' => I18n::plural($c['pieces'], '{n} pièce', '{n} pièces'),
            ]),
            match (self::approvalPlan($def, $choices)['mode']) {
                self::PLAN_PUBLISH => t('À votre accord, il est créé, réglé avec vos services, publié et ouvert dans l’éditeur : prêt à lancer, vous l’ajustez si besoin. Rien ne démarre sans vous.'),
                self::PLAN_TRIGGER => t('À votre accord, il est créé, réglé avec vos services et ouvert dans l’éditeur. Il démarrera seul une fois que vous l’aurez publié.'),
                default            => t('À votre accord, il est créé et ouvert dans l’éditeur, qui liste ce qu’il reste à régler avant de le publier.'),
            },
        ];
        foreach (array_slice($notes, 0, 3) as $n) {
            $impacts[] = $n;
        }
        $lines = self::railLines($def, $choices);
        return [
            'action'       => t('Créer un workflow'),
            'fichier'      => $name,
            'apercu'       => (string)$def['objective'],
            'apercu_titre' => t('Objectif'),
            'impacts'      => $impacts,
            'icone'        => '/assets/img/workflow.svg',
            // Pas d'« Ajuster » : approuver ouvre déjà l'éditeur (la route
            // ADJUST_URL reste pour les cartes déposées avant).
            'rail'         => $lines,
        ];
    }

    /**
     * Description de la forme d'arguments (consigne de composition, schéma
     * de l'outil) avec ce que la personne peut utiliser.
     */
    public static function schemaGuide(array $choices): string
    {
        $assistants = [];
        foreach ($choices['profiles'] ?? [] as $id => $p) {
            $assistants[] = '  ' . $id . '. ' . $p['name'] . (trim((string)($p['description'] ?? '')) !== '' ? ' — ' . mb_substr((string)$p['description'], 0, 140) : '');
        }
        $sources = array_keys($choices['sources'] ?? []);
        $targets = array_keys(array_filter(WorkflowSpec::ACTION_TARGETS, static function (string $conn) use ($choices): bool {
            if (str_starts_with($conn, 'tool:')) {
                foreach ($choices['tools'] ?? [] as $t) {
                    if ($t['type'] === substr($conn, 5)) {
                        return true;
                    }
                }
                return false;
            }
            return isset($choices['sources'][$conn]);
        }));
        $have = [];
        foreach ($choices['sources'] ?? [] as $key => $src) {
            $have[] = $key . ' (' . $src['label'] . ', ' . (!empty($src['connected']) ? 'relié' : 'pas encore relié') . ')';
        }
        $bases = [];
        foreach ($choices['kbs'] ?? [] as $kb) {
            if (($kb['reason'] ?? null) === null) {
                $bases[] = (string)$kb['name'];
            }
        }
        $toolNames = [];
        foreach ($choices['tools'] ?? [] as $tool) {
            if (($tool['reason'] ?? null) === null && in_array($tool['type'], ['n8n', 'gamma'], true)) {
                $toolNames[] = $tool['type'] . ' : ' . $tool['name'];
            }
        }
        $context = "CE QUE L'UTILISATEUR A (utilise-le, n'invente rien d'autre) :\n"
            . '- services : ' . ($have !== [] ? implode(', ', $have) : 'aucun') . " — préfère ceux qui sont reliés ;\n"
            . ($bases !== [] ? '- bases de connaissances (« bases » d\'une étape) : ' . implode(', ', array_slice($bases, 0, 20)) . " ;\n" : '')
            . ($toolNames !== [] ? '- outils : ' . implode(', ', $toolNames) . " ;\n" : '')
            . (($choices['delivery_kbs'] ?? []) !== [] ? '- bases où ranger un livrable (« livraisons » de type base) : ' . implode(', ', array_map(static fn($k) => (string)$k['name'], array_slice($choices['delivery_kbs'], 0, 20))) . " ;\n" : '')
            . (($choices['workflows'] ?? []) !== [] ? '- ses autres workflows (départ « workflow ») : ' . implode(', ', array_map(static fn($w) => (string)$w['name'], array_slice($choices['workflows'], 0, 20))) . " ;\n" : '');
        $natures = [];
        foreach ($choices['creations'] ?? [] as $tool) {
            if ($tool['reason'] === null) {
                $natures[['image' => 'image', 'page' => 'page', 'map' => 'carte'][$tool['kind']]] = true;
            }
        }
        $creation = $natures !== []
            ? "   {\"type\": \"creation\", \"titre\": \"…\", \"nature\": \"" . implode('|', array_keys($natures)) . "\", \"consigne\": \"ce qu'il faut créer, en citant les pièces [Nom]\", \"piece\": \"Nom de la pièce créée\", \"format\": \"carre|paysage|portrait (image)\", \"relecture\": false},\n"
            : '';
        return "FORME D'UN WORKFLOW (JSON) :\n"
            . "{\"nom\": \"…\", \"objectif\": \"ce que la personne obtient à la fin\",\n"
            . " \"depart\": {\"nom_fiche\": \"…\", \"champs\": [{\"nom\": \"Client\", \"type\": \"texte|texte_long|nombre|date|choix|email|oui_non|fichier\", \"obligatoire\": true, \"options\": [\"…\"], \"defaut\": \"valeur prise quand personne ne la saisit\"}],\n"
            . "            \"programmation\": null | {\"frequence\": \"chaque_jour|jours_ouvres|chaque_semaine|chaque_mois\", \"jours\": [1], \"jour_du_mois\": 1, \"heure\": \"08:00\"},\n"
            . "            \"evenement\": null | {\"type\": \"email\", \"source\": \"gmail|outlook\", \"filtre\": \"from:… subject:…\"} | {\"type\": \"fichier\", \"dossier\": \"lien ou chemin Drive\"} | {\"type\": \"appel\"} | {\"type\": \"workflow\", \"nom\": \"un autre workflow\"}},\n"
            . " \"briques\": [\n"
            . "   {\"type\": \"etape\", \"titre\": \"…\", \"assistant\": \"numéro ou nom, ou aucun (MIA)\", \"consigne\": \"… cite les pièces entre crochets : [Nom de la pièce]\",\n"
            . "    \"sources\": [" . implode(', ', array_map(static fn($s) => '"' . $s . '"', $sources)) . "], \"bases\": [\"nom d'une base de connaissances\"], \"ecritures\": \"lire|approuver|sans_validation\", \"relecture\": false,\n"
            . "    \"piece\": {\"nom\": \"…\", \"forme\": \"texte|liste|tableau|fiche|document\", \"champs\": [{\"nom\": \"Montant\", \"type\": \"texte|nombre|date|email|oui_non|liste\"}], \"format\": \"docx|pdf|pptx|xlsx|md\"}},\n"
            . "   {\"type\": \"condition\", \"titre\": \"…\", \"question\": \"question fermée\" OU \"regle\": {\"piece\": \"Nom de Fiche\", \"champ\": \"Nom du champ\", \"operateur\": \"egal|different|superieur|superieur_ou_egal|inferieur|inferieur_ou_egal|contient|vide|renseigne|oui|non\", \"valeur\": \"…\"},\n"
            . "    \"si_doute\": \"demander|non\" (question seulement),\n"
            . "    \"si_oui\": {\"briques\": [], \"ensuite\": \"continuer|arreter\"}, \"si_non\": {\"briques\": [], \"ensuite\": \"continuer|arreter\"}},\n"
            . "   {\"type\": \"decision\", \"titre\": \"…\", \"criteres\": \"sur quoi MIA choisit, en citant les pièces [Nom]\", \"lit\": [\"Nom de la pièce\"], \"si_doute\": \"demander\" ou le nom d'une voie,\n"
            . "    \"voies\": [{\"nom\": \"Devis\", \"quand\": \"le cas où MIA prend cette voie\", \"briques\": [], \"ensuite\": \"continuer|arreter\"}, …2 à 6 voies]},\n"
            . "   {\"type\": \"validation\", \"titre\": \"…\", \"relit\": [\"Nom de la pièce\"], \"consigne\": \"ce que la personne vérifie\", \"renvoie_a\": \"titre de l'étape à refaire si la personne renvoie\", \"peut_corriger\": true, \"delai_jours\": 7},\n"
            . "   {\"type\": \"action\", \"titre\": \"…\", \"outil\": \"" . implode('|', $targets ?: array_keys(WorkflowSpec::ACTION_TARGETS)) . "\", \"consigne\": \"…\", \"ecritures\": \"approuver|sans_validation\"},\n"
            . "   {\"type\": \"boucle\", \"titre\": \"…\", \"parcourir\": \"liste|lignes|pieces_jointes|slides|pages|sections|fichiers_drive|emails|reperes_par_mia\", \"pour_chaque\": \"Nom de la pièce lue (liste, tableau, fichier, email reçu ; « Fiche.Champ » pour un champ liste)\",\n"
            . "    \"dossier\": \"fichier ou dossier Google Drive (fichiers_drive, ou lignes/slides/pages/sections d'un fichier Drive)\", \"recherche\": \"requête d'emails (emails)\", \"source\": \"gmail|outlook\", \"colonnes\": [\"Client\", \"Email\"] (lignes),\n"
            . "    \"consigne_reperage\": \"ce que MIA repère dans la pièce (reperes_par_mia)\", \"champs\": [{\"nom\": \"Sujet\", \"type\": \"texte\"}] (reperes_par_mia),\n"
            . "    \"garder_si\": {\"champ\": \"colonne ou champ de l'élément\", \"operateur\": \"…\", \"valeur\": \"…\"}, \"sans_doublon\": \"champ\", \"si_erreur_element\": \"continuer|arreter\", \"relire_liste\": false, \"relire_actions\": false,\n"
            . "    OU \"jusqu_a\": \"question fermée de fin\" ; \"max\": 50, \"element\": \"Nom de l'élément en cours\", \"briques\": [briques sans boucle ni décision ; une condition y garde l'élément : {\"type\": \"condition\", \"regle\" ou \"question\", \"si_oui\": \"continuer|element_suivant|arreter\", \"si_non\": \"…\"}], \"resultat\": \"Nom de la pièce rassemblée\"},\n"
            . "   {\"type\": \"attente\", \"titre\": \"…\", \"duree\": 2, \"unite\": \"minutes|heures|jours\"} OU {\"type\": \"attente\", \"jusqu_a\": {\"piece\": \"Nom de Fiche\", \"champ\": \"Nom du champ date\"}, \"heure\": \"09:00\"},\n"
            . $creation
            . "   {\"type\": \"prevenir\", \"titre\": \"…\", \"message\": \"…\"},\n"
            . "   {\"type\": \"arreter\", \"titre\": \"…\", \"message\": \"ce qui est dit dans la conversation, peut citer [Nom]\", \"statut\": \"terminee|arretee\"} (DERNIÈRE brique d'une voie seulement : le workflow s'y termine proprement, sans livrable)\n"
            . " ],\n"
            . " \"livrable\": [\"Nom de la pièce\"],\n"
            . " \"livraisons\": [{\"type\": \"email\", \"source\": \"gmail|outlook\", \"a\": \"adresse\" OU \"a_champ\": \"Nom de Fiche.Nom du champ email\", \"objet\": \"…\", \"message\": \"…\", \"joindre\": [\"Nom de la pièce\"], \"envoi\": \"approuver|brouillon|envoyer\"} | {\"type\": \"drive\", \"pieces\": [\"…\"], \"dossier\": \"…\"} | {\"type\": \"slack\", \"canal\": \"#…\", \"message\": \"…\"} | {\"type\": \"base\", \"base\": \"nom d'une base\", \"pieces\": [\"…\"]}]}\n\n"
            . "RÈGLES : chaque étape remet UNE pièce, au nom unique ; une brique ne lit que des pièces remises AVANT elle ; une voie de condition ne contient pas de condition ; une voie qui doit finir le workflow (rien à faire de plus) se termine par une brique « arreter » ; "
            . "une écriture dans un outil passe par une ACTION (à approuver par défaut) ; place une validation (ou « relecture »: true) là où une erreur coûte ; "
            . "une étape ou une action accepte « si_erreur »: \"arreter|ignorer\" et « essais »: 0 à 3, et « filtres »: {\"de\", \"objet\", \"mots\", \"pieces_jointes\": true, \"non_lus\": true, \"jours\": 7, \"dossier\", \"nom\", \"nature\": \"document|tableur|presentation|pdf\", \"destinataires\": \"adresses ou @domaine permis\"} (emails et fichiers qu'elle voit, à qui elle écrit) ; "
            . "plusieurs scénarios selon le contenu (« selon le type de demande… ») : une DÉCISION, chaque scénario une voie ; une boucle « pour_chaque » parcourt une pièce remise AVANT elle (les lignes d'un fichier Excel du départ, les pièces jointes de l'email reçu, les slides, les sujets repérés par MIA…) ou une source qu'elle va chercher (fichiers d'un dossier Drive, emails d'une recherche), ses briques citent « element » et ses colonnes ; un élément en échec n'arrête pas les autres ; « relire_actions » quand chaque tour prépare un email ; "
            . ($creation !== '' ? "une image, une page web ou un itinéraire à produire : une CRÉATION (elle remet une pièce comme une étape ; une image qu'elle lit lui sert de départ) ; " : '')
            . "n'ajoute « evenement », « livraisons » ou une attente que si la description les demande ; "
            . "un workflow programmé ou lancé par un évènement n'a personne pour remplir la fiche de départ : chaque champ obligatoire y porte un « defaut », ou n'existe pas ; "
            . "la pièce de départ s'appelle comme « nom_fiche » (sinon « Informations de départ »).\n"
            . "LANGUE : rédige le nom, l'objectif, les titres, les consignes, les questions, les messages et les noms de pièces, "
            . "de champs et de voies en " . I18n::promptLanguage() . " — la langue que la personne a choisie pour MIA ; "
            . "les clés JSON et les valeurs imposées (types, opérateurs, « continuer », « arreter »…) restent telles quelles.\n"
            . $context
            . ($assistants !== [] ? "ASSISTANTS DISPONIBLES (confie à chaque étape celui dont c'est le métier) :\n" . implode("\n", $assistants) . "\n" : "Aucun assistant : les étapes sont confiées à MIA.\n");
    }

    /**
     * « Décrire à MIA » : la description d'un processus → arguments, par le
     * modèle de l'instance. Rend aussi l'erreur présentable s'il y a lieu.
     *
     * @return array{args: ?array, error: ?array}
     */
    public static function compose(string $description, array $choices, array $user, string $timezone): array
    {
        $system = "Tu conçois un WORKFLOW d'entreprise à partir de la description d'une personne non technique. "
            . "Réponds UNIQUEMENT par un objet JSON de la forme ci-dessous, sans texte autour. Garde le workflow simple (3 à 8 briques), "
            . "des titres courts à l'infinitif, des consignes précises et des noms de pièces parlants, le tout en " . I18n::promptLanguage() . ".\n\n"
            . self::schemaGuide($choices);
        $res = WorkflowAi::complete($system, "DESCRIPTION :\n" . mb_substr($description, 0, 4000), UsageSource::WORKFLOW_COMPOSE, ['user_id' => (int)$user['id']], 0.3, 40000);
        if ($res['error'] !== null) {
            return ['args' => null, 'error' => $res['error']];
        }
        $json = ArtifactContract::lastJsonObject($res['text']);
        if ($json === null) {
            return ['args' => null, 'error' => ['title' => t('Réponse illisible'), 'message' => t('MIA n’a pas pu composer ce workflow. Reformulez, ou partez d’un modèle.')]];
        }
        $json['fuseau'] = $timezone;
        return ['args' => $json, 'error' => null];
    }

    /**
     * Rappel au modèle de la proposition en attente (révision) : les briques
     * telles qu'elles sont, à renvoyer entières si quelque chose change.
     */
    public static function pendingNote(array $args): string
    {
        return "\n\nWORKFLOW EN ATTENTE D'APPROBATION dans cette conversation (proposition actuelle, à reprendre telle quelle sauf ce que la personne demande de changer, en renvoyant TOUT avec « remplace »: true) :\n"
            . mb_substr((string)json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 12000);
    }
}
