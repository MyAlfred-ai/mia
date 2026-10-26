<?php

declare(strict_types=1);

/**
 * Outils n8n du chat : un OUTIL confié à l'assistant (ligne chat_tools de type
 * n8n), pas un connecteur personnel — rien à cocher pour l'utilisateur.
 * Découverte des workflows exposés (tag), description, exécution soumise à la
 * carte d'approbation sauf workflow déclaré en lecture seule avec l'exécution
 * directe autorisée par l'administrateur.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels.
 */
final class N8nTools extends AbstractToolProvider
{
    /** @param array<string, mixed> $tool ligne chat_tools de l'outil n8n */
    public function __construct(AgentTurn $turn, private readonly N8nService $n8n, private readonly array $tool)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'n8n';
    }

    /**
     * n8n compte comme connecteur engagé : ses workflows s'enchaînent avec les
     * autres outils (méthode agent) et ConnectorContext se souvient du dernier
     * workflow touché.
     */
    public function connector(): ?string
    {
        return 'n8n';
    }

    public function definitions(): array
    {
        return $this->n8nToolDefinitions();
    }

    public function guidance(): string
    {
        return $this->n8nToolGuidance($this->n8n);
    }

    /** Sortie d'un workflow exécuté directement : données produites hors de MIA. */
    public function externalContentTools(): array
    {
        return [
            'executer_workflow_n8n',
        ];
    }

    public function handlers(): array
    {
        $n8nService          = $this->n8n;
        $n8nTool             = $this->tool;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['lister_workflows_n8n'] = function (array $args) use ($n8nService) {
            $this->sseEmit(['phase' => 'n8n_list']);
            try {
                return ['workflows' => $n8nService->listWorkflows(
                    (string)($args['recherche'] ?? ''),
                    (int)($args['max_resultats'] ?? 10)
                )];
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['decrire_workflow_n8n'] = function (array $args) use ($n8nService) {
            $this->sseEmit(['phase' => 'n8n_list']);
            try {
                return $n8nService->describe((string)($args['workflow'] ?? ''));
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['executer_workflow_n8n'] = function (array $args) use ($n8nService, $n8nTool, $queueConnectorWrite) {
            // Résolution AMONT : le workflow existe, il est exposé, il est
            // lançable, et les données sont exploitables. Une erreur revient
            // au modèle plutôt que de produire une carte d'approbation qui
            // échouera au clic.
            try {
                $wf   = $n8nService->resolveWorkflow((string)($args['workflow'] ?? ''));
                $data = N8nService::normalizePayload($args['donnees'] ?? []);
                if (N8nService::webhookFromWorkflow($wf) === null) {
                    return ['error' => 'Le workflow « ' . (string)($wf['name'] ?? '') . ' » n\'a pas de '
                        . 'déclencheur Webhook actif : il ne peut pas être lancé depuis le chat.'];
                }
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }

            // Workflow déclaré sans effet de bord ET exécution directe
            // autorisée par l'administrateur : pas de carte d'approbation.
            if ($n8nService->runsWithoutApproval($wf)) {
                $this->sseEmit(['phase' => 'n8n_run']);
                try {
                    return $n8nService->run($wf, $data);
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            }

            // Cas normal : la file d'approbation. On y dépose l'ID RÉSOLU —
            // au clic « Approuver », le workflow est re-résolu par cet id et
            // ses conditions d'exposition re-vérifiées au moment T.
            return $queueConnectorWrite(
                'executer_workflow_n8n',
                [
                    'workflow' => (string)($wf['id'] ?? ''),
                    'donnees'  => $data,
                    // Quel outil exécutera : au clic « Approuver », il est
                    // re-résolu par cet id et ses droits re-vérifiés au
                    // moment T (l'assistant a pu perdre l'outil entre-temps).
                    'tool_id'  => (int)$n8nTool['id'],
                ],
                [
                    'id'            => (string)($wf['id'] ?? ''),
                    'nom'           => (string)($wf['name'] ?? ''),
                    'lecture_seule' => N8nService::isReadOnly($wf, $n8nService->tagName()),
                ]
            );
        };

        return $toolHandlers;
    }

    /**
     * Définitions function-calling des outils n8n.
     *
     * Le paramètre `donnees` est déclaré en **chaîne JSON** et non en objet :
     * tous les fournisseurs ne digèrent pas un objet à propriétés libres dans
     * un schéma d'outil (Gemini refuse un `object` sans `properties`). Une
     * chaîne passe partout, et `N8nService::normalizePayload` accepte de toute
     * façon les deux formes — un modèle qui envoie un vrai objet reste servi.
     */
    private function n8nToolDefinitions(): array
    {
        return [
            [
                'name'        => 'lister_workflows_n8n',
                'description' => "Liste les workflows d'automatisation n8n de l'organisation accessibles depuis "
                    . "cette conversation : nom, identifiant, description et s'ils sont lançables. À appeler EN "
                    . "PREMIER — tu ne peux pas deviner ce qui existe, et un workflow non listé n'existe pas pour "
                    . "toi. Lecture seule, aucun effet de bord.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'recherche' => [
                            'type'        => 'string',
                            'description' => "Filtre optionnel sur le nom et la description (ex. « ticket », « CRM »). "
                                . "Vide = tous les workflows disponibles.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de workflows renvoyés (1 à " . N8nService::MAX_RESULTS . "). Défaut : 10.",
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'decrire_workflow_n8n',
                'description' => "Donne la notice complète d'un workflow n8n : ce qu'il fait et les champs qu'il "
                    . "attend. À appeler avant executer_workflow_n8n quand la description courte de la liste ne "
                    . "suffit pas à remplir les données. Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'workflow' => [
                            'type'        => 'string',
                            'description' => "Identifiant ou nom exact du workflow (obtenu via lister_workflows_n8n).",
                        ],
                    ],
                    'required'   => ['workflow'],
                ],
            ],
            [
                'name'        => 'executer_workflow_n8n',
                'description' => "Déclenche un workflow n8n avec les données fournies. À n'utiliser QUE sur demande "
                    . "explicite de l'utilisateur : un workflow peut avoir des effets réels et irréversibles "
                    . "(création dans un outil métier, envoi, facturation). Sauf exception déclarée par "
                    . "l'administration, l'exécution est soumise à l'approbation de l'utilisateur avant de partir.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'workflow' => [
                            'type'        => 'string',
                            'description' => "Identifiant ou nom exact du workflow (obtenu via lister_workflows_n8n).",
                        ],
                        'donnees'  => [
                            'type'        => 'string',
                            'description' => "Champs transmis au workflow, au format JSON "
                                . "(ex. {\"sujet\":\"Panne imprimante\",\"priorite\":\"haute\"}). "
                                . "Utilise exactement les noms de champs annoncés par la description du workflow. "
                                . "Mets {} si le workflow n'attend rien.",
                        ],
                    ],
                    'required'   => ['workflow'],
                ],
            ],
        ];
    }

    /** Consigne ajoutée au system prompt quand les outils n8n sont disponibles. */
    private function n8nToolGuidance(N8nService $n8n): string
    {
        $txt = "\n\nTu disposes des outils `lister_workflows_n8n`, `decrire_workflow_n8n` et "
            . "`executer_workflow_n8n` pour les automatisations n8n de l'organisation. Tu ne connais AUCUN "
            . "workflow a priori : commence toujours par `lister_workflows_n8n`, et ne prétends jamais qu'un "
            . "workflow existe s'il n'est pas dans la liste. Renseigne `donnees` en suivant strictement la "
            . "description du workflow ; si un champ obligatoire manque, demande-le à l'utilisateur au lieu de "
            . "l'inventer. Si un outil renvoie un champ `error`, transmets ce message tel quel sans le reformuler. "
            . "Ne déclenche un workflow que sur demande explicite — jamais « pour voir », jamais deux fois pour "
            . "vérifier.";

        if ($n8n->autoReadAllowed()) {
            $txt .= " Les workflows marqués `lecture_seule` s'exécutent immédiatement et te renvoient leur "
                . "sortie : tu peux la restituer directement. Les autres passent par l'approbation de "
                . "l'utilisateur : l'outil renvoie alors `en_attente_approbation` et une carte s'affiche sous ta "
                . "réponse — n'affirme JAMAIS que le workflow a été exécuté, annonce qu'il attend son approbation "
                . "en résumant le workflow visé et les données transmises.";
        } else {
            $txt .= " CHAQUE exécution est soumise à l'approbation de l'utilisateur : l'outil renvoie "
                . "`en_attente_approbation` et une carte s'affiche sous ta réponse. N'affirme JAMAIS que le "
                . "workflow a été exécuté ni qu'un résultat est disponible : annonce qu'il attend son "
                . "approbation, en résumant le workflow visé et les données transmises.";
        }
        $txt .= " Quand un workflow répond « dès réception », son résultat final n'est pas disponible ici : "
            . "dis-le plutôt que de l'inventer.";

        return $txt;
    }
}
