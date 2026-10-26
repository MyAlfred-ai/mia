<?php

declare(strict_types=1);

/**
 * « Crée-moi un assistant qui prépare mes rendez-vous clients » : MIA
 * PRÉPARE un assistant — prompt système, modèle, température, bases de
 * connaissances, outils, couleur et avatar.
 *
 * Le modèle ne crée rien. Il compose une proposition que le handler valide
 * avec les règles d'AssistantDraft (modèles permis aux groupes de la
 * personne, bases de SON périmètre, outils liables, avatars de la
 * bibliothèque) puis dépose en carte d'approbation : l'assistant n'existe
 * qu'au clic « Approuver » (ChatController::approveAssistantDraft), et
 * « Ajuster » ouvre la fiche de création pré-remplie depuis la carte.
 *
 * Les AJUSTEMENTS passent par la conversation : « mets-le en bleu » rappelle
 * l'outil avec `remplace: true` et seulement ce qui change — le reste est
 * repris de la proposition en attente (les résultats d'outils ne reviennent
 * pas au modèle d'un message à l'autre : sans cette reprise, il devrait
 * réécrire de mémoire un prompt qu'il ne voit plus). La carte d'avant est
 * close, la nouvelle la remplace.
 *
 * Avatar : un de la bibliothèque, ou un avatar CRÉÉ pour l'assistant avec
 * l'outil image que les groupes de la personne lui ouvrent — généré pendant
 * la proposition pour que la carte le montre, facturé comme une image du
 * chat, importé dans la bibliothèque seulement à la création.
 *
 * Exposé aux seuls créateurs et administrateurs, quand le message demande un
 * assistant (AssistantDraft::looksLikeAssistantRequest) ou qu'une proposition
 * attend dans la conversation — jamais « parce que la boucle tourne » (la
 * définition porte les modèles, bases et avatars permis : des milliers de
 * jetons), jamais pendant l'exécution d'une tâche programmée.
 */
final class AssistantTools extends AbstractToolProvider
{
    /** Entrées de catalogue décrites au modèle, au plus (le reste reste accepté par nom). */
    private const DESCRIBED_MAX = 30;

    /** Listes qu'une retouche peut vider (`vider`) → champ de la proposition en attente. */
    private const REVISION_LISTS = ['bases' => 'kb_ids', 'outils' => 'tool_ids'];

    /** @var list<array> résultats d'ImageGenerator des avatars créés pendant ce tour (facturation) */
    private array $produced = [];

    /**
     * @param array      $choices    AssistantDraft::choicesFor($user)
     * @param array|null $avatarTool outil image avec lequel créer un avatar (null = bibliothèque seule)
     * @param \Closure(): list<string> $turnImages images générées pendant CE tour (pas encore en base)
     * @param array|null $pending    arguments de la proposition en attente dans la conversation
     *                               (rappelée au modèle, cf. AssistantDraft::pendingNote)
     */
    public function __construct(
        AgentTurn $turn,
        private readonly array $choices,
        private readonly ?array $avatarTool,
        private readonly \Closure $turnImages,
        private readonly ?array $pending = null,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'assistants';
    }

    /** Outil image des avatars créés (facturation de producedImages()). */
    public function avatarTool(): ?array
    {
        return $this->avatarTool;
    }

    /** @return list<array> résultats d'ImageGenerator, à facturer après la réponse */
    public function producedImages(): array
    {
        return $this->produced;
    }

    public function definitions(): array
    {
        $llmSlugs = array_map('strval', array_keys($this->choices['llms']));
        $llmLines = [];
        foreach ($this->choices['llms'] as $slug => $label) {
            $note = (string)($this->choices['llm_info'][$slug]['note'] ?? '');
            $llmLines[] = '« ' . $slug . ' » = ' . $label . ($note !== '' ? ' (' . $note . ')' : '');
        }
        $default = $this->choices['app_default'] ?? null;
        $modelDescription = "Modèle de l'assistant. Chaîne vide = celui de l'application (dans une retouche : "
            . "inchangé ; « defaut » pour y revenir)"
            . ($default !== null ? ' (actuellement ' . $default['label'] . ($default['note'] !== '' ? ' — ' . $default['note'] : '') . ')' : '')
            . ", qu'il suivra s'il change : le bon choix sauf besoin particulier. Modèles permis : "
            . ($llmLines !== [] ? implode(' ; ', $llmLines) : 'aucun') . '.';

        $properties = [
            'nom' => [
                'type'        => 'string',
                'description' => "Nom court de l'assistant, tel qu'il apparaîtra dans la liste (ex. « Préparateur de rendez-vous »).",
            ],
            'description' => [
                'type'        => 'string',
                'description' => "Une phrase affichée sous le nom dans la liste des assistants (pas envoyée au modèle).",
            ],
            'prompt_systeme' => [
                'type'        => 'string',
                'description' => "Prompt système COMPLET de l'assistant, en Markdown, avec les six sections imposées "
                    . "(cf. consigne). Il s'adresse à l'assistant (« Tu … »).",
            ],
            'modele' => [
                'type'        => 'string',
                'enum'        => array_merge([''], $llmSlugs),
                'description' => $modelDescription,
            ],
            'temperature' => [
                'type'        => 'number',
                'description' => "0 à 2 : bas (0,2–0,4) pour un assistant factuel ou d'analyse, moyen (0,7) pour la "
                    . "rédaction, haut (1 et plus) pour la créativité. Omettre = valeur par défaut du modèle "
                    . "(certains modèles n'en règlent pas : elle est alors ignorée).",
            ],
            'couleur' => [
                'type'        => 'string',
                'description' => "Couleur de l'assistant, format #RRGGBB, accordée à son domaine.",
            ],
        ];

        if ($this->choices['kbs'] !== []) {
            $lines = [];
            foreach (array_slice($this->choices['kbs'], 0, self::DESCRIBED_MAX, true) as $id => $kb) {
                $desc = $kb['description'] !== '' ? ' — ' . self::shorten($kb['description'], 90) : '';
                $state = !$kb['active'] ? ' [désactivée]' : ($kb['live'] ? ' [cerveau MyBrain en direct]' : ' [' . $kb['docs'] . ' doc.]');
                $lines[] = $id . ' = « ' . $kb['name'] . ' »' . $desc . $state;
            }
            $properties['bases'] = [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => "Bases de connaissances que l'assistant consultera, SEULEMENT celles dont le contenu "
                    . "sert sa mission (numéros) : " . implode(' ; ', $lines) . '.',
            ];
        }

        if ($this->choices['tools'] !== []) {
            $lines = [];
            foreach ($this->choices['tools'] as $id => $tool) {
                $lines[] = $id . ' = « ' . $tool['name'] . ' » (' . self::toolKind($tool['type']) . ')';
            }
            $properties['outils'] = [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => "Outils que l'assistant pourra déclencher lui-même (numéros), seulement s'ils servent "
                    . "sa mission : " . implode(' ; ', $lines) . '. Ils exigent un modèle marqué « outils ».',
            ];
        }

        $avatarLines = [];
        foreach (array_slice($this->choices['avatars'], 0, self::DESCRIBED_MAX, true) as $file => $name) {
            $avatarLines[] = '« ' . $file . ' » = ' . $name;
        }
        $properties['avatar'] = [
            'type'        => 'string',
            'description' => "Avatar de la bibliothèque (nom de fichier)"
                . ($avatarLines !== [] ? ' : ' . implode(' ; ', $avatarLines) : '')
                . ". Accepte aussi l'adresse d'une image générée dans cette conversation (/assets/img/generated/…).",
        ];
        if ($this->avatarTool !== null) {
            $properties['avatar_a_generer'] = [
                'type'        => 'string',
                'description' => "Pour CRÉER un avatar propre à l'assistant quand aucun de la bibliothèque ne colle, "
                    . "ou si l'utilisateur le demande : description visuelle du sujet et du style (ex. « une chouette "
                    . "bienveillante portant des lunettes, illustration plate aux tons bleus »). Une image est générée "
                    . "(et facturée) à chaque appel : ne la redemande pas pour une simple retouche du reste.",
            ];
        }
        $properties['remplace'] = [
            'type'        => 'boolean',
            'description' => "Vrai pour MODIFIER la proposition encore en attente dans cette conversation : ne passe "
                . "alors QUE les champs qui changent — tout le reste est repris tel quel (un champ vide, \"\" ou [], "
                . "compte pour inchangé), la carte précédente est remplacée. Ne renvoie surtout pas `prompt_systeme` "
                . "si la demande ne porte pas sur lui : le réécrire changerait l'assistant. Faux (défaut) pour un "
                . "nouvel assistant.",
        ];
        $lists = array_values(array_intersect(array_keys(self::REVISION_LISTS), array_keys($properties)));
        if ($lists !== []) {
            $properties['vider'] = [
                'type'        => 'array',
                'items'       => ['type' => 'string', 'enum' => $lists],
                'description' => "Avec `remplace` seulement : listes à VIDER entièrement (dans une retouche, une liste "
                    . "vide passée seule ne change rien).",
            ];
        }

        return [[
            'name'        => AssistantDraft::TOOL,
            'description' => "Prépare un NOUVEL ASSISTANT (prompt système, modèle, température, bases de connaissances, "
                . "outils, couleur, avatar) pour l'utilisateur. Rien n'est créé avant son approbation : une carte "
                . "s'affiche, qu'il approuve, ajuste dans la fiche complète ou fait corriger ici.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => [],
            ],
        ]];
    }

    public function guidance(): string
    {
        $avatar = $this->avatarTool !== null
            ? "un de la bibliothèque s'il colle à la mission, sinon `avatar_a_generer` (un sujet, un style, des couleurs ; "
                . "jamais de texte) — ou aucun"
            : "un de la bibliothèque s'il colle à la mission, ou aucun (aucun outil d'image n'est disponible pour en créer un)";

        return "\n\nCréation d'assistants : avec `creer_assistant`, tu PRÉPARES un nouvel assistant pour "
            . "l'utilisateur ; une carte lui demande son accord, rien n'existe avant. Ne l'appelle que s'il demande un "
            . "assistant. Si sa mission est floue (pour qui, pour quoi faire, sur quelles sources), pose UNE question "
            . "courte avant ; sinon propose directement, il pourra corriger.\n"
            . "Compose une proposition COHÉRENTE avec ce qu'il a le droit d'utiliser (les listes de l'outil sont "
            . "les siennes) :\n"
            . "- modèle : celui de l'application sauf besoin réel — vision pour analyser des images, raisonnement "
            . "pour l'analyse complexe, modèle économique pour des réponses simples et fréquentes ; un modèle "
            . "marqué « outils » si tu lui lies des outils ;\n"
            . "- température : basse pour le factuel, moyenne pour la rédaction, haute pour la créativité ;\n"
            . "- bases de connaissances : seulement celles dont le contenu sert la mission, jamais toutes par "
            . "principe ; aucune si aucune ne convient ;\n"
            . "- outils : seulement s'ils servent la mission ;\n"
            . "- couleur et avatar : accordés à son domaine — avatar : " . $avatar . ".\n"
            . "Nom, description et prompt système s'écrivent en " . I18n::promptLanguage() . ", la langue que "
            . "l'utilisateur a choisie pour MIA.\n"
            . "Le prompt système suit cette structure :\n"
            . PromptStarters::structureDirective()
            . "Après l'appel, résume en deux ou trois phrases ce que tu proposes et pourquoi (modèle, bases) sans "
            . "recopier le prompt — la carte l'affiche —, et rappelle qu'il peut approuver, te demander des "
            . "changements, ou « Ajuster » dans la fiche complète. Pour changer une proposition encore en attente, "
            . "rappelle l'outil avec `remplace: true` et SEULEMENT les champs qui changent. N'affirme jamais que "
            . "l'assistant est créé. Si l'outil renvoie `error`, corrige l'appel ou transmets le message tel quel."
            . ($this->pending !== null ? AssistantDraft::pendingNote($this->pending, $this->choices) : '');
    }

    public function handlers(): array
    {
        return [
            AssistantDraft::TOOL => function (array $args): array {
                $this->sseEmit(['phase' => 'assistant_prepare']);
                $user = $this->turn->user;
                if (!AssistantDraft::canCreate($user)) {
                    return ['error' => 'L\'utilisateur n\'a pas le droit de créer des assistants (rôle créateur '
                        . 'ou administrateur requis). Dis-le-lui : un administrateur peut le lui ouvrir.'];
                }
                if (AssistantDraft::quotaReached($user)) {
                    return ['error' => 'L\'utilisateur a atteint sa limite de ' . Profile::MAX_PERSONAL_PER_CREATOR
                        . ' assistants personnels : il doit en supprimer un (Assistants) avant d\'en créer un autre. Dis-le-lui.'];
                }

                $replace  = filter_var($args['remplace'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $previous = $replace ? AssistantDraft::latestPending($this->turn->convId, $this->turn->userId) : null;
                if ($replace && $previous === null && trim((string)($args['prompt_systeme'] ?? '')) === '') {
                    return ['error' => 'Aucune proposition d\'assistant n\'attend dans cette conversation (déjà '
                        . 'approuvée, annulée ou expirée) : propose l\'assistant en entier, sans `remplace`. S\'il '
                        . 'a déjà été créé, il se modifie depuis sa fiche (Assistants).'];
                }

                $ignored = [];
                if ($previous !== null) {
                    // Retouche : un champ vide veut dire « inchangé » (les
                    // modèles renvoient souvent tous les paramètres, vides compris).
                    $ignored = self::ignoredEmptyLists($args, (array)$previous['args'], self::REVISION_LISTS);
                    $args = self::revisionArgs($args, array_keys(self::REVISION_LISTS));
                }

                $choices = AssistantDraft::choicesFor($user);
                $input   = self::input($args, (array)($previous['args'] ?? []));
                $wanted  = trim((string)($args['avatar_a_generer'] ?? ''));
                if ($wanted !== '') {
                    // Tout le reste est validé AVANT de payer une image.
                    $input['avatar'] = '';
                    $input['avatar_generated'] = '';
                }
                $allowed = array_merge(
                    AssistantDraft::conversationImages($this->turn->convId, $this->turn->userId),
                    ($this->turnImages)()
                );
                try {
                    $data = AssistantDraft::normalizeInput($input, $choices, $allowed);
                } catch (\InvalidArgumentException $e) {
                    return ['error' => $e->getMessage()];
                }
                $issues = AssistantDraft::coherenceIssues($data, $choices);
                if ($issues !== []) {
                    return ['error' => implode(' ', $issues)];
                }

                if ($wanted !== '') {
                    if ($this->avatarTool === null) {
                        return ['error' => 'Aucun outil de génération d\'image n\'est disponible pour cet utilisateur : '
                            . 'choisis un avatar de la bibliothèque (`avatar`) ou aucun.'];
                    }
                    $this->sseEmit(['phase' => 'generating_avatar']);
                    $result = ImageGenerator::generate(
                        AssistantDraft::squareTool($this->avatarTool),
                        AssistantDraft::avatarPrompt($wanted, $data)
                    );
                    if (isset($result['error']) || empty($result['image_url'])) {
                        return ['error' => 'Avatar non créé : ' . (string)($result['error'] ?? 'image vide')
                            . ' — propose l\'assistant avec un avatar de la bibliothèque, ou sans.'];
                    }
                    $this->produced[] = $result;
                    $data['avatar_generated'] = AssistantDraft::generatedUrl((string)$result['image_url']);
                    $data['avatar'] = null;
                }

                $result = ($this->turn->queueWrite)(
                    AssistantDraft::TOOL,
                    AssistantDraft::toArgs($data),
                    ['preview' => AssistantDraft::actionPreview($data, $choices)]
                );
                if ($previous !== null && isset($result['statut'])
                    && AssistantDraft::supersede((int)$previous['id'], $this->turn->userId, $this->turn->convId)) {
                    // La carte d'avant se ferme à l'écran (« remplacée »).
                    $this->sseEmit(['drive_superseded' => ['ids' => [(int)$previous['id']]]]);
                    $result['proposition_remplacee'] = true;
                }
                if ($data['avatar_generated'] !== null) {
                    $result['avatar'] = $data['avatar_generated'];
                }
                $notes = [];
                if (trim((string)($input['temperature'] ?? '')) !== ''
                    && !AssistantDraft::temperatureSupported($data['default_llm'], $choices)) {
                    $notes[] = 'Température ignorée : ce modèle ne la règle pas. Ne la mentionne pas comme réglée.';
                }
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
     * Arguments du modèle → champs de la fiche, par-dessus la proposition
     * remplacée : seul ce que le modèle précise change. Pure.
     *
     * @param array $base arguments de la carte remplacée ([] pour un nouvel assistant)
     */
    public static function input(array $args, array $base): array
    {
        $in = $base + [
            'name' => '', 'description' => '', 'system_prompt' => '', 'default_llm' => '', 'temperature' => '',
            'color' => '', 'kb_ids' => [], 'tool_ids' => [], 'avatar' => '', 'avatar_generated' => '',
        ];
        $map = [
            'nom'            => 'name',
            'description'    => 'description',
            'prompt_systeme' => 'system_prompt',
            'modele'         => 'default_llm',
            'temperature'    => 'temperature',
            'couleur'        => 'color',
            'bases'          => 'kb_ids',
            'outils'         => 'tool_ids',
        ];
        foreach ($map as $arg => $field) {
            if (array_key_exists($arg, $args) && $args[$arg] !== null) {
                $in[$field] = is_array($args[$arg]) ? $args[$arg] : (string)$args[$arg];
            }
        }
        // « defaut » / « default » : le modèle de l'application.
        if (in_array(mb_strtolower(trim((string)(is_array($in['default_llm']) ? '' : $in['default_llm']))), ['defaut', 'défaut', 'default', 'application'], true)) {
            $in['default_llm'] = '';
        }
        // Un avatar précisé remplace l'avatar repris (bibliothèque OU image générée).
        if (array_key_exists('avatar', $args) && $args['avatar'] !== null) {
            $avatar = trim((string)$args['avatar']);
            if (AssistantDraft::generatedUrl($avatar) !== null) {
                $in['avatar'] = '';
                $in['avatar_generated'] = $avatar;
            } else {
                $in['avatar'] = in_array(mb_strtolower($avatar), ['aucun', 'none', 'sans'], true) ? '' : $avatar;
                $in['avatar_generated'] = '';
            }
        }
        return $in;
    }

    /** Nature d'un outil liable, en mots. Pure. */
    private static function toolKind(string $type): string
    {
        return match ($type) {
            'image' => 'génération d\'images',
            'n8n'   => 'automatisations n8n',
            'gamma' => 'présentations Gamma',
            default => $type,
        };
    }

    private static function shorten(string $text, int $max): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
