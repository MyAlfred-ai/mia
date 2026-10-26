<?php

declare(strict_types=1);

/**
 * Outils Microsoft To Do du chat (OAuth délégué) : listes, tâches, lecture,
 * et écritures (création, modification) soumises à approbation. Aucune
 * suppression : l'outil n'existe pas (cf. MicrosoftTodoOAuth) — « c'est fait »
 * se traduit par statut=terminee.
 *
 * Même contrat que les autres fournisseurs : toute erreur revient AU MODÈLE en
 * ['error' => message FR], jamais une exception qui casserait le flux ; toute
 * écriture passe par la file d'approbation ($this->turn->queueWrite), rien
 * n'est exécuté pendant le flux.
 */
final class MicrosoftTodoTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserMicrosoftTodoService $todo)
    {
        parent::__construct($turn);
        // Échéances et rappels lus et posés dans le fuseau du navigateur.
        $todo->setTimeZone($turn->timeZone);
    }

    public function key(): string
    {
        return 'mstodo';
    }

    public function connector(): ?string
    {
        return 'mstodo';
    }

    public function definitions(): array
    {
        return self::definitionsFor($this->todo->canWrite());
    }

    public function guidance(): string
    {
        return self::guidanceFor(
            $this->todo->canWrite(),
            new \DateTimeImmutable('now', new \DateTimeZone($this->todo->timeZoneName()))
        );
    }

    public function externalContentTools(): array
    {
        return [
            'lister_taches_todo',
            'lire_tache_todo',
        ];
    }

    public function handlers(): array
    {
        $todo                = $this->todo;
        $textBudget          = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['lister_listes_todo'] = function (array $args) use ($todo) {
            $this->sseEmit(['phase' => 'mstodo_read']);
            try {
                return ['listes' => $todo->listTaskLists()];
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lister_taches_todo'] = function (array $args) use ($todo) {
            $this->sseEmit(['phase' => 'mstodo_read']);
            try {
                return self::hintIfEmpty($todo->listTasks(
                    (string)($args['liste'] ?? ''),
                    (string)($args['statut'] ?? ''),
                    (string)($args['debut'] ?? ''),
                    (string)($args['fin'] ?? ''),
                    (int)($args['max_resultats'] ?? 20)
                ), 'taches', "Aucune tâche. Par défaut seules les tâches À FAIRE sont listées : réessaie avec statut=toutes. "
                    . "Si tu as filtré par échéance, réessaie SANS bornes (les tâches sans échéance sont exclues d'une fenêtre), "
                    . "ou vérifie la liste visée avec lister_listes_todo.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_tache_todo'] = function (array $args) use ($todo, $textBudget) {
            $this->sseEmit(['phase' => 'mstodo_read']);
            try {
                return $todo->readTask(
                    (string)($args['tache'] ?? ''),
                    (string)($args['liste'] ?? ''),
                    $textBudget
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };

        if ($todo->canWrite()) {
            // Contexte de la carte d'approbation : nom de la liste visée et,
            // pour une tâche existante, son titre actuel. Résolu ICI (le
            // service est encore en main) plutôt qu'au clic « Approuver ».
            // Best-effort : un échec de lecture ne doit pas empêcher de
            // proposer l'action, la carte sera juste moins bavarde.
            $previewCtx = function (array $args, bool $withTask) use ($todo): array {
                $ctx = [];
                try {
                    if ($withTask) {
                        $tache = $todo->readTask((string)($args['tache'] ?? ''), (string)($args['liste'] ?? ''), 200);
                        $ctx['titre']     = (string)($tache['titre'] ?? '');
                        $ctx['liste_nom'] = (string)($tache['liste_nom'] ?? '');
                    } else {
                        $ref = UserMicrosoftTodoService::normalizeListRef((string)($args['liste'] ?? ''));
                        foreach ($todo->listTaskLists() as $l) {
                            if (($ref === '' && $l['defaut']) || $l['id'] === $ref
                                || ($ref !== '' && UserOutlookCalendarService::foldText($l['nom']) === UserOutlookCalendarService::foldText($ref))) {
                                $ctx['liste_nom'] = $l['nom'];
                                break;
                            }
                        }
                    }
                } catch (\RuntimeException) {
                    // Carte moins détaillée, mais action toujours proposable.
                }
                return $ctx;
            };

            $toolHandlers['creer_tache_todo'] = function (array $args) use ($queueConnectorWrite, $previewCtx) {
                // Le fuseau VOYAGE avec l'action : elle s'exécute au clic
                // « Approuver », dans une requête qui ne connaît plus le navigateur.
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // Validation amont : une erreur revient au modèle, jamais dans une carte cassée.
                try {
                    UserMicrosoftTodoService::buildTaskPayload($args);
                    UserMicrosoftTodoService::normalizeListRef((string)($args['liste'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_tache_todo', $args, $previewCtx($args, false));
            };
            $toolHandlers['modifier_tache_todo'] = function (array $args) use ($queueConnectorWrite, $previewCtx) {
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                try {
                    UserMicrosoftTodoService::splitTaskRef((string)($args['tache'] ?? ''), (string)($args['liste'] ?? ''));
                    if (UserMicrosoftTodoService::buildTaskPatch($args) === []) {
                        return ['error' => 'Aucune modification demandée (titre, notes, échéance, rappel, importance ou statut).'];
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('modifier_tache_todo', $args, $previewCtx($args, true));
            };
        }

        return $toolHandlers;
    }

    /** Définitions function-calling (selon le droit d'écriture accordé). Pure. */
    public static function definitionsFor(bool $canWrite): array
    {
        $defs = [
            [
                'name'        => 'lister_listes_todo',
                'description' => "Liste les listes Microsoft To Do de l'utilisateur (id, nom, laquelle est sa liste "
                    . "par défaut, partagée, lecture seule). Utile UNIQUEMENT quand il mentionne une liste précise "
                    . "(« ma liste Courses ») : sinon, travaille sur sa liste par défaut sans appeler cet outil.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'lister_taches_todo',
                'description' => "Liste les tâches d'une liste Microsoft To Do de l'utilisateur, triées par échéance. "
                    . "Renvoie pour chaque tâche : id, liste, titre, statut (a_faire|en_cours|terminee), échéance, rappel, "
                    . "importante et un aperçu des notes. Par défaut, SEULES les tâches à faire. À utiliser AVANT "
                    . "lire_tache_todo ou modifier_tache_todo pour obtenir l'id (à recopier tel quel). Une fenêtre "
                    . "d'échéance (debut/fin) EXCLUT les tâches sans échéance — ne la fournis que si l'utilisateur "
                    . "parle d'une période.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'liste' => [
                            'type'        => 'string',
                            'description' => "Id ou nom de la liste (via lister_listes_todo). Défaut : la liste par défaut de l'utilisateur.",
                        ],
                        'statut' => [
                            'type'        => 'string',
                            'description' => "Filtre : « a_faire » (défaut), « terminee » ou « toutes ».",
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
                'name'        => 'lire_tache_todo',
                'description' => "Lit une tâche Microsoft To Do complète (titre, statut, échéance, rappel, notes "
                    . "intégrales et étapes). Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tache' => [
                            'type'        => 'string',
                            'description' => "ID de la tâche, tel que rendu par lister_taches_todo (il désigne aussi sa liste).",
                        ],
                    ],
                    'required'   => ['tache'],
                ],
            ],
        ];

        if ($canWrite) {
            $defs[] = [
                'name'        => 'creer_tache_todo',
                'description' => "Crée une tâche dans une liste Microsoft To Do de l'utilisateur. Chaque création est "
                    . "soumise à son approbation explicite avant exécution (carte de confirmation).",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'      => ['type' => 'string', 'description' => "Titre de la tâche."],
                        'notes'      => ['type' => 'string', 'description' => "Détail de la tâche (optionnel)."],
                        'echeance'   => ['type' => 'string', 'description' => "Échéance au format AAAA-MM-JJ (optionnel). To Do ne retient que le JOUR."],
                        'rappel'     => ['type' => 'string', 'description' => "Rappel à une heure précise, format AAAA-MM-JJ HH:MM (optionnel) : To Do préviendra l'utilisateur à cette heure."],
                        'importante' => ['type' => 'boolean', 'description' => "true pour marquer la tâche comme importante (optionnel)."],
                        'liste'      => ['type' => 'string', 'description' => "Id ou nom de la liste cible. Défaut : liste par défaut de l'utilisateur."],
                    ],
                    'required'   => ['titre'],
                ],
            ];
            $defs[] = [
                'name'        => 'modifier_tache_todo',
                'description' => "Modifie une tâche Microsoft To Do existante : titre, notes, échéance, rappel, importance "
                    . "ou statut (la marquer terminée, en cours, ou la rouvrir). Seuls les champs fournis changent. "
                    . "Chaque modification est soumise à l'approbation explicite de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tache'      => ['type' => 'string', 'description' => "ID de la tâche (via lister_taches_todo, à recopier tel quel)."],
                        'titre'      => ['type' => 'string', 'description' => "Nouveau titre (optionnel)."],
                        'notes'      => ['type' => 'string', 'description' => "Nouvelles notes (optionnel ; une chaîne vide efface les notes)."],
                        'echeance'   => ['type' => 'string', 'description' => "Nouvelle échéance AAAA-MM-JJ, ou « aucune » pour la retirer (optionnel)."],
                        'rappel'     => ['type' => 'string', 'description' => "Nouveau rappel AAAA-MM-JJ HH:MM, ou « aucun » pour le retirer (optionnel)."],
                        'importante' => ['type' => 'boolean', 'description' => "true / false pour changer l'importance (optionnel)."],
                        'statut'     => ['type' => 'string', 'description' => "« terminee » pour cocher la tâche, « en_cours », ou « a_faire » pour la rouvrir (optionnel)."],
                    ],
                    'required'   => ['tache'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils To Do sont disponibles. Pure. */
    public static function guidanceFor(bool $canWrite, \DateTimeImmutable $now): string
    {
        $daysFr = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

        $txt = "\n\nTu disposes des outils `lister_taches_todo` et `lire_tache_todo` pour consulter les tâches "
            . "Microsoft To Do personnelles de l'utilisateur. Nous sommes le "
            . $daysFr[(int)$now->format('N')] . ' ' . $now->format('d/m/Y') . ' (' . $now->format('H:i') . ')'
            . " : convertis les expressions relatives (« demain », « avant vendredi ») en dates absolues. "
            . "Une échéance To Do est un JOUR ; seul un rappel porte une heure. Les identifiants de tâche rendus "
            . "désignent aussi leur liste : recopie-les tels quels. Cite toujours l'échéance des tâches que tu "
            . "rapportes. Si un outil renvoie un champ `error`, transmets ce message tel quel à l'utilisateur sans "
            . "l'inventer ni le reformuler.";

        if ($canWrite) {
            $txt .= " Tu peux créer et modifier des tâches (`creer_tache_todo`, `modifier_tache_todo`), uniquement "
                . "sur demande claire de l'utilisateur. IMPORTANT : chaque écriture est soumise à son approbation — "
                . "l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta réponse. N'affirme JAMAIS "
                . "qu'une tâche a été créée ou modifiée : annonce qu'elle attend son approbation, en résumant ce qui "
                . "va changer. Aucune suppression n'est possible : quand une tâche est faite, propose "
                . "`modifier_tache_todo` avec statut=terminee ; si l'utilisateur veut vraiment l'effacer, dis-lui de "
                . "le faire dans Microsoft To Do.";
        } else {
            $txt .= " Tu ne peux PAS créer ni modifier de tâches : la connexion actuelle est en lecture seule. "
                . "Si l'utilisateur le demande, invite-le à reconnecter Microsoft To Do dans Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
