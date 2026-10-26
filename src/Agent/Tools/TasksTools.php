<?php

declare(strict_types=1);

/**
 * Outils Google Tasks du chat (OAuth) : listes, tâches, lecture, et écritures (création,
 * modification, suppression si l'utilisateur la laisse active) soumises à approbation.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class TasksTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserTasksService $tasks)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'tasks';
    }

    public function connector(): ?string
    {
        return 'tasks';
    }

    public function definitions(): array
    {
        return $this->tasksToolDefinitions($this->tasks);
    }

    public function guidance(): string
    {
        return $this->tasksToolGuidance($this->tasks);
    }

    public function externalContentTools(): array
    {
        return [
            'lister_taches',
            'lire_tache',
        ];
    }

    public function handlers(): array
    {
        $tasksService        = $this->tasks;
        $tasksTextBudget     = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['lister_listes_taches'] = function (array $args) use ($tasksService) {
            $this->sseEmit(['phase' => 'tasks_read']);
            try {
                return ['listes' => $tasksService->listTaskLists()];
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lister_taches'] = function (array $args) use ($tasksService) {
            $this->sseEmit(['phase' => 'tasks_read']);
            try {
                return self::hintIfEmpty($tasksService->listTasks(
                    (string)($args['liste'] ?? ''),
                    (string)($args['statut'] ?? ''),
                    (string)($args['debut'] ?? ''),
                    (string)($args['fin'] ?? ''),
                    (int)($args['max_resultats'] ?? 20)
                ), 'taches', "Aucune tâche. Si tu as filtré par échéance, réessaie SANS bornes (les tâches sans échéance sont exclues d'une fenêtre) ; un filtre de statut peut aussi tout masquer.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_tache'] = function (array $args) use ($tasksService, $tasksTextBudget) {
            $this->sseEmit(['phase' => 'tasks_read']);
            try {
                return $tasksService->readTask(
                    (string)($args['liste'] ?? ''),
                    (string)($args['tache'] ?? ''),
                    $tasksTextBudget
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };

        if ($tasksService->canWrite()) {
            // Contexte de la carte d'approbation : nom de la liste visée et,
            // pour une tâche existante, son titre actuel. Résolu ICI (le
            // service est encore en main) plutôt qu'au clic « Approuver ».
            // Best-effort : un échec de lecture ne doit pas empêcher de
            // proposer l'action, la carte sera juste moins bavarde.
            $tasksPreviewCtx = function (array $args, bool $withTask) use ($tasksService): array {
                $ctx = [];
                try {
                    $listes = $tasksService->listTaskLists();
                    $ref    = UserTasksService::normalizeListRef((string)($args['liste'] ?? ''));
                    foreach ($listes as $l) {
                        if ($l['id'] === $ref || ($ref === '' && $l['defaut'])) {
                            $ctx['liste_nom'] = $l['nom'];
                            break;
                        }
                    }
                    if ($withTask) {
                        $tache = $tasksService->readTask(
                            (string)($args['liste'] ?? ''),
                            (string)($args['tache'] ?? ''),
                            200
                        );
                        $ctx['titre'] = (string)($tache['titre'] ?? '');
                    }
                } catch (\RuntimeException) {
                    // Carte moins détaillée, mais action toujours proposable.
                }
                return $ctx;
            };

            $toolHandlers['creer_tache'] = function (array $args) use ($queueConnectorWrite, $tasksPreviewCtx) {
                // Validation amont (titre, échéance, statut) : une erreur
                // revient au modèle, jamais dans une carte cassée.
                try {
                    UserTasksService::buildTaskPayload($args);
                    UserTasksService::normalizeListRef((string)($args['liste'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_tache', $args, $tasksPreviewCtx($args, false));
            };
            $toolHandlers['modifier_tache'] = function (array $args) use ($queueConnectorWrite, $tasksPreviewCtx) {
                try {
                    UserTasksService::assertTaskId((string)($args['tache'] ?? ''));
                    UserTasksService::normalizeListRef((string)($args['liste'] ?? ''));
                    if (UserTasksService::buildTaskPatch($args) === [] && !UserTasksService::clearsDueDate($args)) {
                        return ['error' => 'Aucune modification demandée (titre, notes, échéance ou statut).'];
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('modifier_tache', $args, $tasksPreviewCtx($args, true));
            };

            // Suppression : exposée seulement si l'utilisateur ne l'a pas
            // coupée dans les préférences du connecteur. Google ne sait pas
            // donner ce demi-droit — MIA si.
            if ($tasksService->canDelete()) {
                $toolHandlers['supprimer_tache'] = function (array $args) use ($queueConnectorWrite, $tasksPreviewCtx) {
                    try {
                        UserTasksService::assertTaskId((string)($args['tache'] ?? ''));
                        UserTasksService::normalizeListRef((string)($args['liste'] ?? ''));
                    } catch (\RuntimeException $e) {
                        return ['error' => $e->getMessage()];
                    }
                    return $queueConnectorWrite('supprimer_tache', $args, $tasksPreviewCtx($args, true));
                };
            }
        }

        return $toolHandlers;
    }

    /** Définitions function-calling des outils Google Tasks (selon les scopes et les préférences). */
    private function tasksToolDefinitions(UserTasksService $tasks): array
    {
        $defs = [
            [
                'name'        => 'lister_listes_taches',
                'description' => "Liste les listes de tâches Google de l'utilisateur (id, nom, laquelle est sa "
                    . "liste par défaut). Utile UNIQUEMENT quand il mentionne une liste précise (« ma liste "
                    . "Travail ») : sinon, travaille sur sa liste par défaut sans appeler cet outil.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'lister_taches',
                'description' => "Liste les tâches d'une liste Google de l'utilisateur. Renvoie pour chaque "
                    . "tâche : id, liste, titre, statut (a_faire|terminee), échéance et un aperçu des notes. "
                    . "À utiliser AVANT lire_tache, modifier_tache ou supprimer_tache pour obtenir l'id. "
                    . "ATTENTION : dès qu'une fenêtre d'échéance (debut/fin) s'applique, Google EXCLUT les "
                    . "tâches sans échéance — ne fournis ces dates que si l'utilisateur parle d'une période.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'liste' => [
                            'type'        => 'string',
                            'description' => "Id de la liste (via lister_listes_taches). Défaut : la liste par défaut de l'utilisateur.",
                        ],
                        'statut' => [
                            'type'        => 'string',
                            'description' => "Filtre : « a_faire », « terminee » ou « toutes ». Défaut : la préférence de l'utilisateur.",
                        ],
                        'debut' => [
                            'type'        => 'string',
                            'description' => "Échéance minimum, format AAAA-MM-JJ (optionnel).",
                        ],
                        'fin' => [
                            'type'        => 'string',
                            'description' => "Échéance maximum, format AAAA-MM-JJ (optionnel).",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de tâches (1 à 100). Défaut : 20.",
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'lire_tache',
                'description' => "Lit une tâche complète (titre, statut, échéance et notes intégrales). "
                    . "Lecture seule. Utile quand l'aperçu des notes renvoyé par lister_taches est tronqué.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tache' => [
                            'type'        => 'string',
                            'description' => "ID de la tâche (obtenu via lister_taches).",
                        ],
                        'liste' => [
                            'type'        => 'string',
                            'description' => "Id de la liste d'où vient la tâche. Défaut : liste par défaut.",
                        ],
                    ],
                    'required'   => ['tache'],
                ],
            ],
        ];

        if ($tasks->canWrite()) {
            $defs[] = [
                'name'        => 'creer_tache',
                'description' => "Crée une tâche dans une liste Google de l'utilisateur. Chaque création est "
                    . "soumise à son approbation explicite avant exécution (carte de confirmation).",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'    => ['type' => 'string', 'description' => "Titre de la tâche."],
                        'notes'    => ['type' => 'string', 'description' => "Détail de la tâche (optionnel)."],
                        'echeance' => ['type' => 'string', 'description' => "Échéance au format AAAA-MM-JJ (optionnel). Google Tasks ne retient QUE la date, jamais une heure."],
                        'liste'    => ['type' => 'string', 'description' => "Id de la liste cible. Défaut : liste par défaut de l'utilisateur."],
                    ],
                    'required'   => ['titre'],
                ],
            ];
            $defs[] = [
                'name'        => 'modifier_tache',
                'description' => "Modifie une tâche existante : titre, notes, échéance ou statut (la marquer "
                    . "terminée, ou la rouvrir). Seuls les champs fournis changent. Chaque modification est "
                    . "soumise à l'approbation explicite de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tache'    => ['type' => 'string', 'description' => "ID de la tâche (via lister_taches)."],
                        'liste'    => ['type' => 'string', 'description' => "Id de la liste d'où vient la tâche. Défaut : liste par défaut."],
                        'titre'    => ['type' => 'string', 'description' => "Nouveau titre (optionnel)."],
                        'notes'    => ['type' => 'string', 'description' => "Nouvelles notes (optionnel ; une chaîne vide efface les notes)."],
                        'echeance' => ['type' => 'string', 'description' => "Nouvelle échéance AAAA-MM-JJ, ou « aucune » pour retirer l'échéance (optionnel)."],
                        'statut'   => ['type' => 'string', 'description' => "« terminee » pour cocher la tâche, « a_faire » pour la rouvrir (optionnel)."],
                    ],
                    'required'   => ['tache'],
                ],
            ];
        }

        // Suppression : présente seulement si l'utilisateur l'a laissée active.
        if ($tasks->canDelete()) {
            $defs[] = [
                'name'        => 'supprimer_tache',
                'description' => "Supprime DÉFINITIVEMENT une tâche. Action irréversible, soumise à "
                    . "l'approbation explicite de l'utilisateur. Ne l'utilise que s'il demande clairement une "
                    . "SUPPRESSION : « c'est fait », « j'ai terminé », « coche-la » appellent modifier_tache "
                    . "avec statut=terminee, qui préserve la trace.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tache' => ['type' => 'string', 'description' => "ID de la tâche à supprimer (via lister_taches)."],
                        'liste' => ['type' => 'string', 'description' => "Id de la liste d'où vient la tâche. Défaut : liste par défaut."],
                    ],
                    'required'   => ['tache'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Google Tasks sont disponibles. */
    private function tasksToolGuidance(UserTasksService $tasks): string
    {
        // Date courante indispensable : « demain », « avant vendredi »… se
        // traduisent en échéances absolues (même besoin que pour l'agenda).
        $daysFr = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $now    = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));

        $txt = "\n\nTu disposes des outils `lister_taches` et `lire_tache` pour consulter les tâches Google "
            . "personnelles de l'utilisateur. Nous sommes le "
            . $daysFr[(int)$now->format('N')] . ' ' . $now->format('d/m/Y')
            . " : convertis les expressions relatives (« demain », « avant vendredi ») en dates AAAA-MM-JJ. "
            . "Google Tasks ne gère QUE des dates d'échéance, jamais des heures : ne promets pas de rappel à "
            . "une heure précise. Cite toujours l'échéance des tâches que tu rapportes. Si un outil renvoie un "
            . "champ `error`, transmets ce message tel quel à l'utilisateur sans l'inventer ni le reformuler.";

        if ($tasks->canWrite()) {
            $txt .= " Tu peux créer et modifier des tâches (`creer_tache`, `modifier_tache`), uniquement sur "
                . "demande claire de l'utilisateur. IMPORTANT : chaque écriture est soumise à son approbation "
                . "— l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta réponse. "
                . "N'affirme JAMAIS qu'une tâche a été créée, modifiée ou supprimée : annonce qu'elle attend "
                . "son approbation, en résumant ce qui va changer.";
        } else {
            $txt .= " Tu ne peux PAS créer ni modifier de tâches : la connexion actuelle est en lecture seule. "
                . "Si l'utilisateur le demande, invite-le à reconnecter ses tâches dans Réglages → Comptes connectés.";
        }

        if ($tasks->canDelete()) {
            $txt .= " `supprimer_tache` efface DÉFINITIVEMENT une tâche : réserve-le aux demandes explicites de "
                . "suppression. Quand l'utilisateur dit qu'une tâche est faite, utilise `modifier_tache` avec "
                . "statut=terminee — c'est réversible, la suppression non.";
        } elseif ($tasks->canWrite()) {
            $txt .= " La suppression de tâches est DÉSACTIVÉE par l'utilisateur : aucun outil ne permet "
                . "d'effacer. Si on t'en demande une, propose de marquer la tâche comme terminée, et indique "
                . "que la suppression se réactive dans Réglages → Comptes connectés.";
        }

        // Ce que l'utilisateur a stipulé, puis l'invitation à enrichir ses
        // règles au fil de l'eau (sans quoi la capture n'aurait jamais lieu :
        // personne ne dicte spontanément ses conventions de rangement).
        return $txt
            . UserTasksService::prefsPromptFragment($tasks->prefs())
            . UserTasksService::ruleNudgeFragment($tasks->prefs());
    }
}
