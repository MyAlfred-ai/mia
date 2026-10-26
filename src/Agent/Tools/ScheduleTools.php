<?php

declare(strict_types=1);

/**
 * « Programme-moi ça chaque lundi » : MIA PRÉPARE une tâche programmée.
 *
 * Le modèle ne crée rien. Il traduit la demande en une tâche complète —
 * consigne autonome, rythme, heure, assistant ou modèle, sources, bases de
 * connaissances, outils — que le handler valide avec les MÊMES règles que le
 * formulaire (ScheduledTask::normalizeInput, choix permis au compte : les
 * assistants qu'il voit, les modèles de ses groupes, les bases de son
 * périmètre, les outils que ses groupes lui ouvrent) puis dépose en carte
 * d'approbation : la tâche n'existe qu'au clic « Approuver »
 * (ChatController::decideAction), et « Ajuster » ouvre le formulaire
 * pré-rempli depuis la carte. Pas d'analyseur de langage maison : c'est le
 * modèle qui comprend « le dernier vendredi du mois », la carte qui montre ce
 * qu'il en a fait.
 *
 * Les AJUSTEMENTS passent par la conversation, comme pour un assistant
 * préparé (AssistantTools) : « plutôt à 9 h » rappelle l'outil avec
 * `remplace: true` et seulement ce qui change — le reste est repris de la
 * tâche en attente, rappelée au modèle (pendingNote : les résultats d'outils
 * ne reviennent pas d'un message à l'autre, il réécrirait sinon la consigne
 * de mémoire). La carte d'avant est close, la nouvelle la remplace.
 *
 * Exposé quand le message demande qu'une chose se répète
 * (ScheduledTask::looksLikeScheduleRequest), qu'une tâche attend dans la
 * conversation, ou que la boucle d'outils tourne déjà — jamais pendant
 * l'exécution d'une tâche (une routine ne programme pas de routine). Dans ce
 * dernier cas seulement, la définition reste LÉGÈRE (choix lus sans bases ni
 * outils) : les listes d'assistants, de modèles, de bases et d'outils ne
 * sont décrites que sur demande — quelques milliers de jetons à ne pas payer
 * à chaque message d'une conversation qui consulte un connecteur.
 */
final class ScheduleTools extends AbstractToolProvider
{
    public const TOOL = 'programmer_tache';

    /** Entrées de catalogue décrites au modèle, au plus (le reste reste accepté par nom). */
    private const DESCRIBED_MAX = 30;

    /** Rythmes proposés au modèle → fréquences de Recurrence. */
    private const FREQUENCES = [
        'chaque_jour'    => Recurrence::DAILY,
        'jours_ouvres'   => Recurrence::WEEKDAYS,
        'chaque_semaine' => Recurrence::WEEKLY,
        'chaque_mois'    => Recurrence::MONTHLY,
    ];

    /** Noms de jours acceptés en plus des numéros ISO (le modèle écrit souvent « lundi »). */
    private const JOURS = [
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    /** « assistant » qui retire l'assistant : la tâche répond avec un modèle. */
    private const NO_ASSISTANT = ['0', 'aucun', 'aucune', 'sans', 'sans assistant', 'none', 'no', 'mia'];

    /** Listes qu'une retouche peut vider (`vider`) → champ de la tâche en attente. */
    private const REVISION_LISTS = ['sources' => 'sources', 'bases' => 'kb_ids', 'outils' => 'tool_ids'];

    /** « modele » qui revient au modèle de l'application. */
    private const APP_MODEL = ['defaut', 'défaut', 'default', 'application'];

    /**
     * @param array $choices  ScheduledTask::choicesFor($user, $complet) : de quoi
     *        DÉCRIRE l'outil. Lus en entier (bases, outils), ils font une
     *        définition détaillée — assistants, modèles, bases et outils
     *        permis ; légers, seulement les sources. La validation d'un appel,
     *        elle, relit toujours les choix en entier.
     * @param array{profile_id: ?int, llm: string, sources: list<string>, kb_ids?: list<int>} $defaults
     *        ce que la tâche reprend du tour quand le modèle ne précise rien :
     *        l'assistant de la conversation, son modèle, les sources engagées,
     *        les bases cochées
     * @param array|null $pending arguments de la tâche en attente dans la
     *        conversation (rappelée au modèle, cf. pendingNote)
     */
    public function __construct(
        AgentTurn $turn,
        private readonly array $choices,
        private readonly array $defaults,
        private readonly ?array $pending = null,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'schedules';
    }

    /** Choix lus en entier : la définition décrit assistants, modèles, bases et outils. */
    private function detailed(): bool
    {
        return isset($this->choices['kbs']);
    }

    public function definitions(): array
    {
        $sources = array_keys($this->choices['sources']);
        $sourceProperty = [
            'type'        => 'array',
            'items'       => ['type' => 'string'],
            'description' => "Sources que la tâche consultera à chaque exécution"
                . ($sources !== [] ? ', parmi : ' . implode(', ', $sources) : '')
                . ". Par défaut : celles de ce message.",
        ];
        if ($sources !== []) {
            $sourceProperty['items']['enum'] = $sources;
        }

        $properties = [
            'consigne' => [
                'type'        => 'string',
                'description' => "La demande à refaire, AUTONOME : elle sera exécutée seule, sans cette "
                    . "conversation. Écris-la comme l'utilisateur l'écrirait dans le chat, avec tout le "
                    . "contexte utile (sujet, périmètre, forme attendue). Rédige-la en " . I18n::promptLanguage()
                    . ", la langue de l'utilisateur.",
            ],
            'nom' => [
                'type'        => 'string',
                'description' => "Nom court de la tâche (ex. « Point du lundi »), en " . I18n::promptLanguage() . ".",
            ],
            'frequence' => [
                'type'        => 'string',
                'enum'        => array_keys(self::FREQUENCES),
                'description' => "Rythme : chaque_jour, jours_ouvres (lundi au vendredi), chaque_semaine "
                    . "(préciser `jours`), chaque_mois (préciser `jour_du_mois`).",
            ],
            'jours' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => "Pour chaque_semaine : jours ISO, 1 = lundi … 7 = dimanche.",
            ],
            'jour_du_mois' => [
                'type'        => 'string',
                'description' => "Pour chaque_mois : de « 1 » à « 28 », ou « dernier ».",
            ],
            'heure' => [
                'type'        => 'string',
                'description' => "Heure locale de l'utilisateur, HH:MM. Sans précision de sa part : 08:00.",
            ],
            'sources' => $sourceProperty,
        ];
        if ($this->detailed()) {
            $properties += $this->resourceProperties();
        }
        $properties += [
            'destination' => [
                'type'        => 'string',
                'enum'        => ['suite', 'nouvelle'],
                'description' => "suite : une même conversation, résultats à la suite (défaut) ; "
                    . "nouvelle : une conversation par exécution.",
            ],
            'actions_a_approuver' => [
                'type'        => 'boolean',
                'description' => "Vrai SEULEMENT si l'utilisateur veut que la tâche prépare des actions "
                    . "(brouillons, évènements, tâches) — elles attendront son approbation. Sinon lecture seule.",
            ],
            'me_prevenir' => [
                'type'        => 'boolean',
                'description' => "Prévenir l'utilisateur sur ses appareils quand le résultat arrive. Vrai par "
                    . "défaut ; faux seulement s'il ne le veut pas.",
            ],
            'remplace' => [
                'type'        => 'boolean',
                'description' => "Vrai pour MODIFIER la tâche encore en attente dans cette conversation : ne passe "
                    . "alors QUE les champs qui changent — tout le reste est repris tel quel (un champ vide, \"\" ou "
                    . "[], compte pour inchangé), la carte précédente est remplacée. Ne renvoie pas `consigne` si la "
                    . "demande ne porte pas sur elle. Faux (défaut) pour une nouvelle tâche.",
            ],
            'vider' => [
                'type'        => 'array',
                'items'       => ['type' => 'string', 'enum' => $this->detailed() ? ['sources', 'bases', 'outils'] : ['sources']],
                'description' => "Avec `remplace` seulement : listes à VIDER entièrement (dans une retouche, une liste "
                    . "vide passée seule ne change rien).",
            ],
        ];

        return [[
            'name'        => self::TOOL,
            'description' => "Prépare une TÂCHE PROGRAMMÉE : une demande que MIA refera toute seule, au rythme "
                . "choisi, avec la réponse dans une conversation dédiée. À n'utiliser QUE si l'utilisateur demande "
                . "qu'une chose se répète. Rien n'est créé avant son approbation : une carte s'affiche, qu'il "
                . "approuve, ajuste dans le formulaire ou fait corriger ici.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => $properties,
                // Rien d'obligatoire : une retouche (`remplace`) ne passe que ce
                // qui change. Une nouvelle tâche exige consigne et fréquence
                // (vérifiées par le handler).
                'required'   => [],
            ],
        ]];
    }

    /** Assistant, modèle, bases et outils permis — définition détaillée seulement. */
    private function resourceProperties(): array
    {
        $profileLines = [];
        foreach (array_slice($this->choices['profiles'], 0, self::DESCRIBED_MAX, true) as $id => $profile) {
            $line = $id . ' = « ' . (string)$profile['name'] . ' »';
            $desc = trim((string)($profile['description'] ?? ''));
            if ($desc !== '') {
                $line .= ' — ' . self::shorten($desc, 80);
            }
            $brings = array_map(static fn(array $kb): string => (string)$kb['name'], (array)($this->choices['profile_kbs'][$id] ?? []));
            if ($brings !== []) {
                $line .= ' [ses bases : ' . implode(', ', $brings) . ']';
            }
            $profileLines[] = $line;
        }
        $current = $this->defaults['profile_id'] !== null
            ? '« ' . (string)($this->choices['profiles'][$this->defaults['profile_id']]['name'] ?? $this->defaults['profile_id']) . ' »'
            : 'aucun';

        $llmLines = [];
        foreach ($this->choices['llms'] as $slug => $label) {
            $tools = (bool)(AssistantDraft::llmInfo((string)$slug)['tools'] ?? false);
            $llmLines[] = '« ' . $slug . ' » = ' . $label . ($tools ? ' [outils]' : ' [sans outils]');
        }

        $properties = [
            'assistant' => [
                'type'        => 'string',
                'description' => "Assistant qui exécutera la tâche (numéro ou nom) — il apporte son modèle, ses "
                    . "consignes et ses bases : " . ($profileLines !== [] ? implode(' ; ', $profileLines) : 'aucun')
                    . ". « aucun » = sans assistant, avec `modele`. Par défaut : celui de cette conversation ("
                    . $current . ").",
            ],
            'modele' => [
                'type'        => 'string',
                'enum'        => array_merge([''], array_map('strval', array_keys($this->choices['llms']))),
                'description' => "Seulement SANS assistant (un assistant impose le sien). Chaîne vide = modèle de "
                    . "l'application (dans une retouche : inchangé ; « defaut » pour revenir au modèle de "
                    . "l'application). Pour consulter des connecteurs ou des outils, un modèle marqué [outils]. "
                    . "Modèles permis : " . ($llmLines !== [] ? implode(' ; ', $llmLines) : 'aucun') . '.',
            ],
        ];

        $kbLines = [];
        foreach (array_slice($this->choices['kbs'], 0, self::DESCRIBED_MAX, true) as $id => $kb) {
            if ($kb['reason'] !== null) {
                continue;
            }
            $desc = $kb['description'] !== '' ? ' — ' . self::shorten($kb['description'], 90) : '';
            $kbLines[] = $id . ' = « ' . $kb['name'] . ' »' . $desc . ($kb['live'] ? ' [cerveau MyBrain en direct]' : '');
        }
        if ($kbLines !== []) {
            $properties['bases'] = [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => "Bases de connaissances que la tâche interrogera EN PLUS de celles de l'assistant, "
                    . ConversationKnowledgeBase::MAX_SELECTED . " au plus, SEULEMENT celles qui servent la consigne "
                    . "(numéros) : " . implode(' ; ', $kbLines) . ". Par défaut : celles cochées dans cette conversation.",
            ];
        }

        $toolLines = [];
        foreach ($this->choices['tools'] as $id => $tool) {
            if ($tool['reason'] !== null) {
                continue;
            }
            $acting = in_array($tool['type'], ScheduledTask::ACTING_TOOL_TYPES, true);
            $toolLines[] = $id . ' = « ' . $tool['name'] . ' » (' . ScheduledTask::toolTypeLabel($tool['type'])
                . ($acting ? ', agit : actions à approuver' : '') . ')';
        }
        if ($toolLines !== []) {
            $properties['outils'] = [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => "Outils que la tâche pourra utiliser (numéros), un par type, seulement s'ils "
                    . "servent la consigne : " . implode(' ; ', $toolLines) . ". Ils exigent un modèle marqué [outils].",
            ];
        }
        return $properties;
    }

    public function guidance(): string
    {
        $choose = $this->detailed()
            ? "Compose une tâche COHÉRENTE avec ce qu'il a le droit d'utiliser (les listes de l'outil sont les "
                . "siennes) : l'assistant de la conversation, sauf s'il en demande un autre ou qu'un autre sert "
                . "mieux la consigne ; sans assistant, le modèle de l'application sauf besoin réel, et un modèle "
                . "marqué [outils] si la tâche consulte des connecteurs ou des outils ; les sources, bases et outils "
                . "seulement s'ils servent la consigne — un outil qui agit (n8n, Gamma) fait préparer des actions à "
                . "approuver. "
            : '';
        return "\n\nTâches programmées : avec `programmer_tache`, tu PRÉPARES une demande que MIA refera toute "
            . "seule (« chaque lundi », « tous les matins », « le 1er du mois »). Ne l'appelle que si l'utilisateur "
            . "demande qu'une chose se répète. La consigne doit être autonome : la tâche s'exécutera sans cette "
            . "conversation. Nom et consigne s'écrivent en " . I18n::promptLanguage() . ", la langue que l'utilisateur "
            . "a choisie pour MIA. Sans heure précisée, choisis 08:00 et dis-le. " . $choose
            . "L'outil dépose une carte : la tâche "
            . "n'existe qu'après l'approbation de l'utilisateur — n'affirme jamais qu'elle est programmée ; résume "
            . "le rythme et la première exécution, et rappelle qu'il peut approuver, te demander des changements, "
            . "ou l'ajuster dans le formulaire (« Ajuster »). Pour changer une tâche encore en attente, rappelle "
            . "l'outil avec `remplace: true` et SEULEMENT les champs qui changent. Si l'outil renvoie `error`, "
            . "corrige l'appel ou transmets le message tel quel."
            . ($this->pending !== null ? self::pendingNote($this->pending, $this->choices) : '');
    }

    public function handlers(): array
    {
        return [
            self::TOOL => function (array $args): array {
                $this->sseEmit(['phase' => 'schedule_prepare']);
                $replace  = filter_var($args['remplace'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $previous = $replace
                    ? DrivePendingAction::latestPendingForTool($this->turn->convId, $this->turn->userId, self::TOOL)
                    : null;
                if ($previous === null) {
                    if ($replace && trim((string)($args['consigne'] ?? '')) === '') {
                        return ['error' => 'Aucune tâche n\'attend dans cette conversation (déjà approuvée, annulée '
                            . 'ou expirée) : propose-la en entier, sans `remplace`. Si elle est déjà programmée, '
                            . 'elle se modifie dans Espaces → Tâches programmées.'];
                    }
                    if (trim((string)($args['consigne'] ?? '')) === '') {
                        return ['error' => '`consigne` manquante : écris la demande à refaire, autonome.'];
                    }
                    if (trim((string)($args['frequence'] ?? '')) === '') {
                        return ['error' => '`frequence` manquante : chaque_jour, jours_ouvres, chaque_semaine ou chaque_mois.'];
                    }
                }

                $ignored = [];
                if ($previous !== null) {
                    // Retouche : un champ vide veut dire « inchangé » (les modèles
                    // renvoient souvent tous les paramètres, vides compris).
                    // Une liste vidée par erreur se signale au modèle.
                    $ignored = self::ignoredEmptyLists($args, (array)$previous['args'], self::REVISION_LISTS);
                    $args = self::revisionArgs($args, array_keys(self::REVISION_LISTS));
                }

                $choices = ScheduledTask::choicesFor($this->turn->user);
                try {
                    $input = self::resolveRefs(
                        self::input($args, $this->defaults, $this->turn->timeZone, (array)($previous['args'] ?? [])),
                        $args,
                        $choices
                    );
                    $data = ScheduledTask::normalizeInput($input, ScheduledTask::allowedFrom($choices));
                } catch (\InvalidArgumentException $e) {
                    return ['error' => $e->getMessage()];
                }

                $llm = self::effectiveLlm($data, $choices, LLMFactory::appDefault($choices['llms']), LLMFactory::available());
                $issues = self::coherenceIssues(
                    $data,
                    $choices,
                    $llm,
                    $llm !== '' && (bool)(AssistantDraft::llmInfo($llm)['tools'] ?? false)
                );
                if ($issues !== []) {
                    return ['error' => implode(' ', $issues)];
                }
                if (ScheduledTask::countActive($this->turn->userId) >= ScheduledTask::MAX_ACTIVE_PER_USER) {
                    return ['error' => 'L\'utilisateur a déjà ' . ScheduledTask::MAX_ACTIVE_PER_USER
                        . ' tâches actives : il doit en mettre une en pause (Espaces → Tâches programmées) '
                        . 'avant d\'en programmer une autre. Dis-le-lui.'];
                }

                $preview = ScheduledTask::actionPreview(
                    $data,
                    $choices,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $result = ($this->turn->queueWrite)(self::TOOL, ScheduledTask::toInput($data), ['preview' => $preview]);
                if ($previous !== null && isset($result['statut'])
                    && DrivePendingAction::supersede((int)$previous['id'], $this->turn->userId, $this->turn->convId)) {
                    // La carte d'avant se ferme à l'écran (« remplacée »).
                    $this->sseEmit(['drive_superseded' => ['ids' => [(int)$previous['id']]]]);
                    $result['proposition_remplacee'] = true;
                }
                $notes = self::notes($data, $input, $args, $choices);
                if ($ignored !== []) {
                    $notes[] = 'Listes vides laissées telles quelles : ' . implode(', ', $ignored)
                        . ' (pour les vider : `vider`).';
                }
                if ($notes !== []) {
                    $result['note'] = implode(' ', $notes);
                }
                return $result;
            },
        ];
    }

    /**
     * Arguments du modèle → champs du formulaire. Ce qu'il ne précise pas vient
     * de `$base` — la tâche en attente qu'il retouche (`remplace`) — sinon du
     * tour. Assistant, modèle, bases et outils sont résolus à part
     * (resolveRefs : ils se désignent aussi par leur nom). Pure.
     */
    public static function input(array $args, array $defaults, string $timeZone, array $base = []): array
    {
        $in = $base + [
            'name'          => '',
            'instruction'   => '',
            'profile_id'    => $defaults['profile_id'] !== null ? (string)$defaults['profile_id'] : '',
            'llm'           => (string)$defaults['llm'],
            'freq'          => '',
            'days'          => [],
            'day'           => '1',
            'time'          => '08:00',
            'timezone'      => $timeZone,
            'sources'       => array_values(array_map('strval', (array)$defaults['sources'])),
            'kb_ids'        => array_map('strval', (array)($defaults['kb_ids'] ?? [])),
            'tool_ids'      => [],
            'destination'   => ScheduledTask::DEST_THREAD,
            'allow_actions' => '',
            // Prévenir sur les appareils quand le résultat arrive : le défaut
            // du formulaire (sans appareil inscrit, cela ne fait rien).
            'notify'        => '1',
        ];
        $has = static fn(string $key): bool => array_key_exists($key, $args) && $args[$key] !== null;

        if ($has('nom')) {
            $in['name'] = (string)$args['nom'];
        }
        if ($has('consigne')) {
            $in['instruction'] = (string)$args['consigne'];
        }
        if ($has('frequence')) {
            $in['freq'] = self::FREQUENCES[(string)$args['frequence']] ?? (string)$args['frequence'];
        }
        if ($has('jours')) {
            $in['days'] = self::dayNumbers($args['jours']);
        }
        if ($has('jour_du_mois')) {
            $in['day'] = self::monthDay($args['jour_du_mois']);
        }
        if ($has('heure') && trim((string)$args['heure']) !== '') {
            $in['time'] = (string)$args['heure'];
        }
        if ($has('sources')) {
            $sources = $args['sources'];
            if (is_string($sources)) {
                $sources = array_filter(array_map('trim', explode(',', $sources)));
            }
            $in['sources'] = array_values(array_map('strval', (array)$sources));
        }
        if ($has('destination')) {
            $in['destination'] = $args['destination'] === 'nouvelle' ? ScheduledTask::DEST_NEW : ScheduledTask::DEST_THREAD;
        }
        if ($has('actions_a_approuver')) {
            $in['allow_actions'] = filter_var($args['actions_a_approuver'], FILTER_VALIDATE_BOOLEAN) ? '1' : '';
        }
        if ($has('me_prevenir')) {
            $in['notify'] = filter_var($args['me_prevenir'], FILTER_VALIDATE_BOOLEAN) ? '1' : '';
        }
        return $in;
    }

    /**
     * Assistant, modèle, bases et outils DEMANDÉS par le modèle → identifiants
     * du compte. Numéro ou nom (comparé sans casse ni accents), parmi ce que la
     * personne peut choisir ; une désignation inconnue est une erreur qui
     * nomme ce qui existe (deviner programmerait autre chose). Seuls les
     * champs présents dans l'appel sont touchés : ce qui vient du tour ou de
     * la tâche en attente est validé ensuite par normalizeInput. Pure.
     *
     * @param array $choices ScheduledTask::choicesFor($user) — en entier
     * @throws \InvalidArgumentException message pour le modèle
     */
    public static function resolveRefs(array $in, array $args, array $choices): array
    {
        if (array_key_exists('assistant', $args) && $args['assistant'] !== null) {
            $raw = $args['assistant'];
            if (is_array($raw)) {
                $raw = $raw['id'] ?? $raw['nom'] ?? $raw['name'] ?? '';
            }
            $raw = trim((string)$raw);
            if ($raw === '') {
                // Vide : rien à changer (un modèle envoie souvent le champ vide
                // plutôt que de l'omettre). « aucun » retire l'assistant.
            } elseif (in_array(mb_strtolower($raw), self::NO_ASSISTANT, true)) {
                $in['profile_id'] = '';
            } else {
                $names = array_slice(array_map(static fn(array $p): string => '« ' . (string)$p['name'] . ' »', $choices['profiles']), 0, 15);
                $ids = AssistantDraft::idsWithin([$raw], $choices['profiles'], 'Assistant « {ref} » inconnu ou inaccessible '
                    . 'à l\'utilisateur. Assistants disponibles : ' . ($names !== [] ? implode(', ', $names) : 'aucun') . '.');
                $in['profile_id'] = (string)$ids[0];
            }
        }

        if (array_key_exists('modele', $args) && $args['modele'] !== null) {
            $raw = trim((string)$args['modele']);
            if (in_array(mb_strtolower($raw), self::APP_MODEL, true)) {
                $raw = '';
            }
            if ($raw !== '' && !isset($choices['llms'][$raw])) {
                // Le modèle écrit parfois le libellé (« GPT-5.6 ») plutôt que
                // l'identifiant : comparé sans casse ni accents, comme un nom.
                $slugs = array_map('strval', array_keys($choices['llms']));
                $byLabel = [];
                foreach ($slugs as $i => $slug) {
                    $byLabel[$i + 1] = ['name' => (string)$choices['llms'][$slug]];
                }
                $unknown = 'Modèle « {ref} » non permis à l\'utilisateur : choisis parmi la liste de l\'outil, ou '
                    . 'laisse vide pour celui de l\'application.';
                if (ctype_digit($raw)) {
                    throw new \InvalidArgumentException(strtr($unknown, ['{ref}' => $raw]));
                }
                $raw = $slugs[AssistantDraft::idsWithin([$raw], $byLabel, $unknown)[0] - 1];
            }
            $in['llm'] = $raw;
        }

        if (array_key_exists('bases', $args) && $args['bases'] !== null) {
            $catalog = array_filter((array)($choices['kbs'] ?? []), static fn(array $kb): bool => $kb['reason'] === null);
            $in['kb_ids'] = array_map('strval', AssistantDraft::idsWithin(
                $args['bases'],
                $catalog,
                'Base de connaissances « {ref} » inconnue ou indisponible pour l\'utilisateur : choisis parmi celles de l\'outil.'
            ));
        }

        if (array_key_exists('outils', $args) && $args['outils'] !== null) {
            $catalog = array_filter((array)($choices['tools'] ?? []), static fn(array $tool): bool => $tool['reason'] === null);
            $in['tool_ids'] = array_map('strval', AssistantDraft::idsWithin(
                $args['outils'],
                $catalog,
                'Outil « {ref} » inconnu ou indisponible pour l\'utilisateur : choisis parmi ceux de l\'outil.'
            ));
        }
        return $in;
    }

    /**
     * Le modèle qui répondra à la tâche — la règle du tour
     * (ChatController::scheduledTurnLlm) : celui de l'assistant s'il en impose
     * un encore actif, sinon celui de la tâche, sinon le défaut de
     * l'application. Pure.
     *
     * @param array<string, mixed> $catalog LLMFactory::available()
     */
    public static function effectiveLlm(array $data, array $choices, ?string $appDefault, array $catalog): string
    {
        if ($data['profile_id'] !== null) {
            $own = trim((string)($choices['profiles'][$data['profile_id']]['default_llm'] ?? ''));
            return $own !== '' && isset($catalog[$own]) ? $own : (string)$appDefault;
        }
        return $data['llm'] !== '' ? (string)$data['llm'] : (string)$appDefault;
    }

    /**
     * Incohérences à renvoyer AU MODÈLE avant de déposer la carte : une tâche
     * qui consulte des connecteurs ou des outils avec un modèle qui ne sait pas
     * en utiliser ne les consulterait jamais — sans une erreur. La recherche
     * Internet n'est pas en cause (elle passe par le modèle lui-même). Pure.
     *
     * @return list<string>
     */
    public static function coherenceIssues(array $data, array $choices, string $llm, bool $llmUsesTools): array
    {
        if ($llm === '' || $llmUsesTools) {
            return [];
        }
        $needs = [];
        foreach ($data['sources'] as $key) {
            if ($key !== ConnectorRegistry::WEB) {
                $needs[] = (string)($choices['sources'][$key]['label'] ?? $key);
            }
        }
        foreach ($data['tool_ids'] as $id) {
            $needs[] = (string)($choices['tools'][$id]['name'] ?? $id);
        }
        if ($needs === []) {
            return [];
        }
        $label = (string)($choices['llms'][$llm] ?? $llm);
        $who = $data['profile_id'] !== null
            ? 'l\'assistant « ' . (string)($choices['profiles'][$data['profile_id']]['name'] ?? '') . ' » répond avec le modèle « ' . $label . ' »'
            : 'le modèle « ' . $label . ' »';
        return ['Incohérent : ' . $who . ', qui ne sait pas utiliser d\'outils — la tâche ne pourrait pas consulter '
            . implode(', ', $needs) . '. Choisis un modèle marqué [outils] (sans assistant), un assistant dont le '
            . 'modèle en a, ou retire ces sources et outils.'];
    }

    /**
     * Ce que le modèle doit savoir de la tâche telle qu'elle sera proposée,
     * sans que ce soit une erreur : source pas encore reliée, base déjà
     * apportée par l'assistant, modèle ignoré, outil écarté, actions imposées
     * par un outil qui agit. Pure.
     *
     * @return list<string>
     */
    public static function notes(array $data, array $input, array $args, array $choices): array
    {
        $notes = [];
        if ($data['profile_id'] !== null && trim((string)($args['modele'] ?? '')) !== '') {
            $notes[] = 'Modèle ignoré : un assistant impose le sien (pour choisir le modèle, `assistant: "aucun"`).';
        }
        $missing = [];
        foreach ($data['sources'] as $key) {
            if (isset($choices['sources'][$key]) && empty($choices['sources'][$key]['connected'])) {
                $missing[] = (string)$choices['sources'][$key]['label'];
            }
        }
        if ($missing !== []) {
            $notes[] = 'Pas encore relié au compte : ' . implode(', ', $missing) . ' — la tâche ne pourra pas le lire '
                . 'tant que l\'utilisateur ne l\'a pas connecté (Réglages → Comptes connectés). Dis-le-lui.';
        }
        if ($data['profile_id'] !== null) {
            $brought = [];
            foreach ((array)($choices['profile_kbs'][$data['profile_id']] ?? []) as $kb) {
                if (in_array((string)$kb['id'], array_map('strval', (array)($input['kb_ids'] ?? [])), true)) {
                    $brought[] = (string)$kb['name'];
                }
            }
            if ($brought !== []) {
                $notes[] = 'Déjà apportées par l\'assistant, interrogées d\'office : ' . implode(', ', $brought) . '.';
            }
        }
        $dropped = [];
        foreach ((array)($input['tool_ids'] ?? []) as $id) {
            if (!in_array((int)$id, $data['tool_ids'], true) && isset($choices['tools'][(int)$id])) {
                $dropped[] = (string)$choices['tools'][(int)$id]['name'];
            }
        }
        if ($dropped !== []) {
            $notes[] = 'Un seul outil par type : non retenu(s) ' . implode(', ', $dropped) . '.';
        }
        if (!empty($data['allow_actions']) && (string)($input['allow_actions'] ?? '') === '') {
            $notes[] = 'La tâche préparera des actions à approuver : un de ses outils agit (n8n ou Gamma).';
        }
        return $notes;
    }

    /**
     * Rappel, pour le modèle, de la tâche EN ATTENTE dans la conversation :
     * sans lui, « plutôt à 9 h » ou « ajoute mes tâches Google » feraient
     * réécrire de mémoire une consigne que le modèle ne voit plus (même
     * constat que pour un assistant préparé). La consigne est donnée en
     * entier, le rythme dans le vocabulaire de l'outil. Données écrites par le
     * modèle lui-même au tour précédent. Pure.
     *
     * @param array $args arguments de la carte en attente (ScheduledTask::toInput)
     */
    public static function pendingNote(array $args, array $choices): string
    {
        $in = $args + [
            'name' => '', 'instruction' => '', 'profile_id' => '', 'llm' => '', 'freq' => '', 'days' => [],
            'day' => '1', 'time' => '', 'timezone' => '', 'sources' => [], 'kb_ids' => [], 'tool_ids' => [],
            'destination' => ScheduledTask::DEST_THREAD, 'allow_actions' => '', 'notify' => '1',
        ];
        try {
            $rhythm = Recurrence::describe(Recurrence::normalize([
                'freq' => $in['freq'], 'days' => $in['days'], 'day' => $in['day'], 'time' => $in['time'],
            ]));
        } catch (\InvalidArgumentException) {
            $rhythm = '?';
        }
        $freq = array_search((string)$in['freq'], self::FREQUENCES, true);
        $detail = 'frequence ' . ($freq !== false ? $freq : (string)$in['freq']);
        if ($in['freq'] === Recurrence::WEEKLY) {
            $detail .= ', jours ' . implode(', ', array_map('strval', (array)$in['days']));
        } elseif ($in['freq'] === Recurrence::MONTHLY) {
            $detail .= ', jour_du_mois ' . ($in['day'] === Recurrence::LAST_DAY ? 'dernier' : (string)$in['day']);
        }
        $detail .= ', heure ' . (string)$in['time'];

        $pid = (int)$in['profile_id'];
        $names = static fn(array $ids, array $catalog): string => $ids === [] ? 'aucune' : implode(', ', array_map(
            static fn($id): string => (int)$id . ' = « ' . (string)($catalog[(int)$id]['name'] ?? '?') . ' »',
            $ids
        ));
        $sources = array_map(
            static fn($key): string => (string)$key . ' (' . (string)($choices['sources'][(string)$key]['label'] ?? $key) . ')',
            (array)$in['sources']
        );
        // Une carte ne porte jamais « sans validation » (choix du seul formulaire).
        $actions = (string)$in['allow_actions'] !== '' ? 'à approuver' : 'lecture seule';

        return "\n\nTâche programmée EN ATTENTE dans cette conversation (carte affichée, pas encore approuvée). "
            . "Les demandes de changement portent sur elle :\n"
            . '- nom : ' . (string)$in['name'] . "\n"
            . '- rythme : ' . $rhythm . ' (' . $detail . ', fuseau ' . (string)$in['timezone'] . ")\n"
            . '- assistant : ' . ($pid > 0 ? $pid . ' = « ' . (string)($choices['profiles'][$pid]['name'] ?? '?') . ' »' : 'aucun') . "\n"
            . '- modèle : ' . ($pid > 0 ? "celui de l'assistant" : ((string)$in['llm'] !== '' ? (string)$in['llm'] : "celui de l'application")) . "\n"
            . '- sources : ' . ($sources !== [] ? implode(', ', $sources) : 'aucune') . "\n"
            . '- bases : ' . $names((array)$in['kb_ids'], (array)($choices['kbs'] ?? [])) . "\n"
            . '- outils : ' . $names((array)$in['tool_ids'], (array)($choices['tools'] ?? [])) . "\n"
            . '- résultat : ' . ($in['destination'] === ScheduledTask::DEST_NEW ? 'nouvelle (une conversation par exécution)' : 'suite (une même conversation)') . "\n"
            . '- actions : ' . $actions . "\n"
            . '- me prévenir : ' . ((string)$in['notify'] !== '' ? 'oui' : 'non') . "\n"
            . "- consigne :\n<<<\n" . (string)$in['instruction'] . "\n>>>\n"
            . "Pour la changer : `programmer_tache` avec `remplace: true` et UNIQUEMENT les champs modifiés. "
            . "Ne renvoie `consigne` que si la demande porte sur elle, et alors en partant de ce texte (corrigé, "
            . "jamais réécrit de mémoire). Pour une AUTRE tâche, sans `remplace`.";
    }

    /**
     * Jours en numéros ISO : entiers, chaînes numériques, noms français ou
     * anglais, liste ou « lundi, jeudi ». Pure.
     *
     * @return list<int>
     */
    public static function dayNumbers(mixed $jours): array
    {
        if (is_string($jours)) {
            $jours = preg_split('/[\s,;]+/u', $jours) ?: [];
        }
        $out = [];
        foreach ((array)$jours as $jour) {
            $cle = mb_strtolower(trim((string)$jour));
            $n = self::JOURS[$cle] ?? filter_var($cle, FILTER_VALIDATE_INT);
            if (is_int($n) && $n >= 1 && $n <= 7) {
                $out[$n] = $n;
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** « dernier » / « last » → 'last' ; sinon le nombre tel quel (validé ensuite). Pure. */
    public static function monthDay(mixed $jour): string
    {
        $jour = mb_strtolower(trim((string)$jour));
        return in_array($jour, ['dernier', 'last', 'dernier jour'], true) ? Recurrence::LAST_DAY : $jour;
    }

    private static function shorten(string $text, int $max): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
