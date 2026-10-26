<?php

declare(strict_types=1);

/**
 * « Monte-moi un workflow qui… » : MIA PRÉPARE un workflow (départ, étapes,
 * conditions, validations, actions, livrable) et le dépose en carte
 * d'approbation. Rien n'existe avant « Approuver » : le workflow naît alors
 * réglé au plus complet (ChatController::approveWorkflowDraft — ajusté aux
 * services de la personne, publié s'il ne démarre qu'à la demande et que rien
 * ne bloque) et la carte ouvre l'éditeur, où elle l'ajuste.
 *
 * La définition passe en UNE chaîne JSON (forme décrite par
 * WorkflowDraft::schemaGuide) plutôt qu'en objets imbriqués : un schéma
 * d'outil de cette profondeur dépasse ce que plusieurs fournisseurs
 * acceptent (Gemini refuse un objet sans propriétés), et c'est la même forme
 * que « Décrire à MIA » (/workflows/new) — une seule conversion, testée.
 *
 * Retouches : `remplace: true` et le workflow ENTIER (la proposition en
 * attente est rappelée au modèle, cf. WorkflowDraft::pendingNote) ; la carte
 * d'avant est close.
 *
 * Exposé aux seuls créateurs et administrateurs, sur demande explicite ou
 * proposition en attente — jamais pendant une tâche ou un workflow.
 */
final class WorkflowTools extends AbstractToolProvider
{
    /**
     * @param array      $choices WorkflowCatalog::choices($user)
     * @param array|null $pending arguments de la proposition en attente (rappelée au modèle)
     */
    public function __construct(
        AgentTurn $turn,
        private readonly array $choices,
        private readonly ?array $pending = null,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'workflows';
    }

    public function definitions(): array
    {
        return [[
            'name'        => WorkflowDraft::TOOL,
            'description' => "Prépare un WORKFLOW pour l'utilisateur : un travail en plusieurs étapes menées par MIA "
                . "ou ses assistants, qui se passent des pièces (fiche, texte, tableau, document), avec des conditions, "
                . "des validations et des actions dans ses outils. Rien n'est créé avant son approbation ; il est alors "
                . "créé, réglé et ouvert dans l'éditeur, où l'utilisateur l'ajuste.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'workflow' => [
                        'type'        => 'string',
                        'description' => "Le workflow COMPLET, en un objet JSON (forme décrite dans la consigne).",
                    ],
                    'remplace' => [
                        'type'        => 'boolean',
                        'description' => "Vrai pour MODIFIER la proposition encore en attente dans cette conversation "
                            . "(renvoie alors le workflow entier, corrigé) ; faux pour un nouveau workflow.",
                    ],
                ],
                'required'   => ['workflow'],
            ],
        ]];
    }

    public function guidance(): string
    {
        return "\n\nWorkflows : avec `" . WorkflowDraft::TOOL . "`, tu PRÉPARES un workflow pour l'utilisateur ; une "
            . "carte lui demande son accord, puis il est créé et s'ouvre dans l'éditeur (s'il ne démarre qu'à la "
            . "demande et que rien ne bloque, il est même publié, prêt à lancer ; rien ne démarre jamais sans lui). Ne "
            . "l'appelle que s'il demande un workflow, un processus en plusieurs étapes ou l'automatisation d'un "
            . "enchaînement. Si le processus est flou (ce qui arrive au départ, ce qu'il veut à la fin), pose UNE "
            . "question courte avant ; sinon propose directement, il corrigera.\n"
            . "Construis-le COMPLET, prêt à servir tel quel : le bon déclencheur, une fiche de départ avec les champs "
            . "utiles, chaque étape confiée à l'assistant dont c'est le métier, avec les services (reliés de préférence) "
            . "et les bases qu'elle doit consulter et des filtres précis, des pièces au nom parlant (une Fiche quand une "
            . "condition doit lire un champ), une validation là où une erreur coûte (envoi, montant, client), « essais » "
            . "et « si_erreur » sur ce qui touche un service, toute écriture dans un outil en ACTION à approuver, le "
            . "livrable et sa livraison si la demande dit où va le résultat. Des titres courts ; pas de brique superflue. "
            . "N'invente aucune donnée que la demande ne donne pas (adresse, dossier, canal) : laisse le champ vide, "
            . "l'éditeur le signalera.\n"
            . WorkflowDraft::schemaGuide($this->choices)
            . "Après l'appel, résume en deux phrases le déroulé (départ → étapes → livrable) sans recopier le JSON — "
            . "la carte montre le rail —, et rappelle qu'en l'approuvant il le retrouve réglé dans l'éditeur, ou qu'il "
            . "peut demander des changements avant. N'affirme jamais que le workflow est créé ni lancé. Si l'outil "
            . "renvoie `error`, corrige l'appel."
            . ($this->pending !== null ? WorkflowDraft::pendingNote((array)($this->pending['source'] ?? $this->pending)) : '');
    }

    public function handlers(): array
    {
        return [
            WorkflowDraft::TOOL => function (array $args): array {
                $this->sseEmit(['phase' => 'workflow_prepare']);
                $user = $this->turn->user;
                if (!Workflow::allows($user, 'create')) {
                    return ['error' => 'Cet utilisateur ne peut pas créer de workflow : son rôle ne le permet pas '
                        . '(Personnes → Rôles), ou la fonctionnalité n\'est pas proposée par l\'administrateur. Dis-le-lui.'];
                }
                if (Workflow::countForUser($this->turn->userId) >= Workflow::MAX_PER_USER) {
                    return ['error' => 'L\'utilisateur a atteint sa limite de ' . Workflow::MAX_PER_USER
                        . ' workflows : il doit en supprimer un (Workflows) avant d\'en créer un autre. Dis-le-lui.'];
                }
                $source = self::decode($args['workflow'] ?? null);
                if ($source === null) {
                    return ['error' => 'Le paramètre `workflow` doit être un objet JSON valide, de la forme décrite '
                        . 'dans la consigne. Corrige l\'appel.'];
                }
                $replace  = filter_var($args['remplace'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $previous = $replace
                    ? DrivePendingAction::latestPendingForTool($this->turn->convId, $this->turn->userId, WorkflowDraft::TOOL)
                    : null;

                $draft = WorkflowDraft::fromArgs($source, $this->choices);
                $def = $draft['definition'];
                if ($def['nodes'] === []) {
                    return ['error' => 'Ce workflow n\'a aucune brique : ajoute au moins une étape (« briques »).'];
                }
                $issues = WorkflowSpec::issues($def, $this->choices);
                $notes = $draft['notes'];
                foreach ($issues as $i) {
                    if ($i['level'] === WorkflowSpec::LEVEL_BLOCK) {
                        $notes[] = $i['text'];
                    }
                }
                $result = ($this->turn->queueWrite)(
                    WorkflowDraft::TOOL,
                    ['name' => $draft['name'], 'color' => WorkflowDraft::colorFor($draft['name']), 'definition' => $def, 'source' => $source],
                    ['preview' => WorkflowDraft::actionPreview($draft['name'], $def, $this->choices, $notes)]
                );
                if ($previous !== null && isset($result['statut'])
                    && DrivePendingAction::supersede((int)$previous['id'], $this->turn->userId, $this->turn->convId)) {
                    $this->sseEmit(['drive_superseded' => ['ids' => [(int)$previous['id']]]]);
                    $result['proposition_remplacee'] = true;
                }
                if ($notes !== []) {
                    $result['note'] = 'Ajusté ou à régler dans l\'éditeur (dis-le à l\'utilisateur) : '
                        . implode(' ', array_slice($notes, 0, 4));
                }
                return $result;
            },
        ];
    }

    /** Le JSON du modèle (chaîne, ou déjà un tableau) → tableau, ou null. Pure. */
    public static function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        return ArtifactContract::lastJsonObject($raw);
    }
}
