<?php

declare(strict_types=1);

/**
 * Outils Microsoft Teams du chat (OAuth délégué) : conversations, canaux,
 * recherche, lecture d'une conversation ou d'un fil, et publication soumise à
 * approbation. Aucune modification ni suppression de message : l'outil
 * n'existe pas (cf. MicrosoftTeamsOAuth). Chaque outil est gardé par son
 * propre droit — un consentement partiel (lecture des canaux non accordée par
 * l'administrateur, par exemple) n'expose que ce qu'il permet.
 *
 * Même contrat que les autres fournisseurs : toute erreur revient AU MODÈLE en
 * ['error' => message FR], jamais une exception qui casserait le flux ; toute
 * écriture passe par la file d'approbation ($this->turn->queueWrite), rien
 * n'est exécuté pendant le flux.
 */
final class TeamsTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserTeamsService $teams)
    {
        parent::__construct($turn);
        // Dates des messages dans le fuseau du navigateur.
        $teams->setTimeZone($turn->timeZone);
    }

    public function key(): string
    {
        return 'teams';
    }

    public function connector(): ?string
    {
        return 'teams';
    }

    public function definitions(): array
    {
        $t = $this->teams;
        return self::definitionsFor($t->canReadChats(), $t->canListChannels(), $t->canReadChannels(),
            $t->canSearch(), $t->canWrite());
    }

    public function guidance(): string
    {
        $t = $this->teams;
        return self::guidanceFor($t->canReadChannels(), $t->canWrite());
    }

    public function externalContentTools(): array
    {
        return [
            'lister_conversations_teams',
            'rechercher_teams',
            'lire_conversation_teams',
            'lire_fil_teams',
        ];
    }

    public function handlers(): array
    {
        $teams               = $this->teams;
        $textBudget          = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        if ($teams->canReadChats()) {
            $toolHandlers['lister_conversations_teams'] = function (array $args) use ($teams) {
                $this->sseEmit(['phase' => 'teams_search']);
                try {
                    return self::hintIfEmpty(
                        ['conversations' => $teams->listChats(
                            (string)($args['recherche'] ?? ''),
                            (int)($args['max_resultats'] ?? 20)
                        )],
                        'conversations',
                        "Aucune conversation ne correspond. Réessaie sans filtre, ou cherche dans les messages "
                            . "avec rechercher_teams (le nom d'une conversation est souvent celui de ses participants)."
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($teams->canListChannels()) {
            $toolHandlers['lister_canaux_teams'] = function (array $args) use ($teams) {
                $this->sseEmit(['phase' => 'teams_search']);
                try {
                    return self::hintIfEmpty(
                        ['canaux' => $teams->listChannels(
                            (string)($args['recherche'] ?? ''),
                            (int)($args['max_resultats'] ?? 30)
                        )],
                        'canaux',
                        "Aucun canal ne correspond. Réessaie sans filtre : l'utilisateur ne voit que les canaux "
                            . "des équipes dont il est membre."
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($teams->canSearch()) {
            $toolHandlers['rechercher_teams'] = function (array $args) use ($teams) {
                $this->sseEmit(['phase' => 'teams_search']);
                try {
                    return self::hintIfEmpty(
                        ['messages' => $teams->searchMessages(
                            (string)($args['requete'] ?? ''),
                            (int)($args['max_resultats'] ?? 10)
                        )],
                        'messages',
                        "Aucun message trouvé. Élargis les termes ou retire un filtre (from:, sent>), ou lis "
                            . "directement la conversation visée avec lire_conversation_teams."
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($teams->canRead()) {
            $toolHandlers['lire_conversation_teams'] = function (array $args) use ($teams, $textBudget) {
                $this->sseEmit(['phase' => 'teams_read']);
                try {
                    return $teams->readConversation(
                        (string)($args['conversation'] ?? ''),
                        (int)($args['max_messages'] ?? 20),
                        $textBudget
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($teams->canReadChannels()) {
            $toolHandlers['lire_fil_teams'] = function (array $args) use ($teams, $textBudget) {
                $this->sseEmit(['phase' => 'teams_read']);
                try {
                    return $teams->readThread(
                        (string)($args['conversation'] ?? ''),
                        (string)($args['message'] ?? ''),
                        $textBudget
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($teams->canWrite()) {
            $toolHandlers['envoyer_message_teams'] = function (array $args) use ($teams, $queueConnectorWrite) {
                // Validation ET résolution amont : la carte nomme la
                // conversation ou le canal RÉELS, une erreur (nom ambigu,
                // droit manquant, fil impossible) revient au modèle pendant
                // qu'il rédige — jamais dans une carte qu'on validerait pour rien.
                try {
                    $n      = UserTeamsService::normalizePostArgs($args);
                    $target = $teams->resolveConversation($n['conversation']);
                    if ($target['kind'] === 'chat') {
                        if (!$teams->canSendChats()) {
                            return ['error' => UserTeamsService::MSG_RECONNECT_WRITE];
                        }
                        if ($n['repondre_a'] !== '') {
                            return ['error' => UserTeamsService::MSG_CHAT_NO_THREAD];
                        }
                    } else {
                        if (!$teams->canSendChannels()) {
                            return ['error' => UserTeamsService::MSG_RECONNECT_WRITE];
                        }
                        // Un lien de message collé vise son fil.
                        if ($n['repondre_a'] === '' && $target['message'] !== '') {
                            $n['repondre_a'] = $target['message'];
                        }
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('envoyer_message_teams', [
                    'conversation'     => UserTeamsService::targetRef($target, false),
                    'conversation_nom' => $target['nom'],
                    'lieu'             => $target['kind'] === 'chat' ? 'conversation' : 'canal',
                    'texte'            => $n['texte'],
                    'repondre_a'       => $n['repondre_a'],
                    'lien'             => $target['lien'],
                ], null);
            };
        }

        return $toolHandlers;
    }

    /**
     * Définitions selon les droits réellement accordés — forme PURE, testable
     * sans réseau. Annoncer au modèle un outil que Graph refusera ensuite en
     * 403 produit une réponse qui promet puis échoue.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitionsFor(
        bool $canReadChats,
        bool $canListChannels,
        bool $canReadChannels,
        bool $canSearch,
        bool $canWrite
    ): array {
        $refHelp = "Référence rendue par les autres outils Teams, lien Teams collé par l'utilisateur, ou NOM : "
            . "sujet ou participant d'une conversation (« Marie Dupont »), « Équipe / Canal » pour un canal.";
        $defs = [];

        if ($canReadChats) {
            $defs[] = [
                'name'        => 'lister_conversations_teams',
                'description' => "Liste les conversations Teams de l'utilisateur (privées, de groupe, de réunion), "
                    . "de la plus récemment active à la plus ancienne : référence, nom, participants, dernier "
                    . "message, non lu. Pour « mes derniers messages Teams » ou pour retrouver une conversation.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'recherche'     => ['type' => 'string', 'description' => "Filtre sur le nom ou un participant (facultatif)."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Nombre maximum de conversations (1 à 50). Défaut : 20."],
                    ],
                ],
            ];
        }
        if ($canListChannels) {
            $defs[] = [
                'name'        => 'lister_canaux_teams',
                'description' => "Liste les canaux des équipes Teams dont l'utilisateur est membre (« Équipe / "
                    . "Canal », référence, description). À n'appeler que pour retrouver un canal : les autres "
                    . "outils acceptent directement « Équipe / Canal ».",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'recherche'     => ['type' => 'string', 'description' => "Filtre sur le nom de l'équipe ou du canal (facultatif)."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Nombre maximum de canaux (1 à 100). Défaut : 30."],
                    ],
                ],
            ];
        }
        if ($canSearch) {
            $defs[] = [
                'name'        => 'rechercher_teams',
                'description' => "Recherche des messages dans tout ce que l'utilisateur peut lire sur Teams"
                    . ($canReadChannels ? " (conversations et canaux)" : " (conversations)") . ". La syntaxe "
                    . "KQL est acceptée : `from:\"Marie Dupont\"`, `sent>2026-09-01`, `\"expression exacte\"`. "
                    . "Renvoie pour chaque message : référence de sa conversation ou de son canal, identifiant, "
                    . "auteur, date et extrait — à passer à lire_conversation_teams ou lire_fil_teams.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete'       => ['type' => 'string', 'description' => "Termes recherchés, avec ou sans opérateurs."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Nombre maximum de messages (1 à 25). Défaut : 10."],
                    ],
                    'required'   => ['requete'],
                ],
            ];
        }
        if ($canReadChats || $canReadChannels) {
            $defs[] = [
                'name'        => 'lire_conversation_teams',
                'description' => "Lit les derniers messages d'une conversation ou d'un canal Teams, du plus ANCIEN "
                    . "au plus récent. Dans un canal, chaque message porte le nombre de réponses de son fil et "
                    . "les dernières d'entre elles. " . $refHelp,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'conversation' => ['type' => 'string', 'description' => $refHelp],
                        'max_messages' => ['type' => 'integer', 'description' => "Nombre maximum de messages (1 à 50). Défaut : 20."],
                    ],
                    'required'   => ['conversation'],
                ],
            ];
        }
        if ($canReadChannels) {
            $defs[] = [
                'name'        => 'lire_fil_teams',
                'description' => "Lit un fil complet d'un canal Teams : le message d'origine et toutes ses "
                    . "réponses, dans l'ordre. L'identifiant du message vient de lire_conversation_teams ou de "
                    . "rechercher_teams ; un lien de message Teams est aussi accepté dans `conversation`.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'conversation' => ['type' => 'string', 'description' => "Canal (référence, lien ou « Équipe / Canal »)."],
                        'message'      => ['type' => 'string', 'description' => "Identifiant du message qui ouvre le fil."],
                    ],
                    'required'   => ['conversation'],
                ],
            ];
        }
        if ($canWrite) {
            $defs[] = [
                'name'        => 'envoyer_message_teams',
                'description' => "Publie un message Teams AU NOM de l'utilisateur, dans une conversation ou un "
                    . "canal, ou en réponse dans le fil d'un canal. Chaque publication est soumise à son "
                    . "approbation explicite (carte de confirmation) ; une fois approuvée, le message est "
                    . "IMMÉDIATEMENT VISIBLE et ne peut plus être ni modifié ni supprimé par MIA. Écris le texte "
                    . "en Markdown : la mise en forme est convertie pour Teams.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'conversation' => ['type' => 'string', 'description' => "Destination. " . $refHelp],
                        'texte'        => ['type' => 'string', 'description' => "Contenu du message, en Markdown."],
                        'repondre_a'   => ['type' => 'string', 'description' => "Identifiant du message d'un CANAL "
                            . "auquel répondre : le message part dans son fil. Vide = nouveau message. Une "
                            . "conversation n'a pas de fil."],
                    ],
                    'required'   => ['conversation', 'texte'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt — forme PURE. */
    public static function guidanceFor(bool $canReadChannels, bool $canWrite): string
    {
        $txt = "\n\nTu peux consulter le Microsoft Teams de l'utilisateur. Tu ne vois QUE ce qu'il voit lui-même : "
            . "une équipe dont il n'est pas membre n'existe pas pour toi. Pour une question ouverte (« qu'a-t-on "
            . "dit du budget ? »), commence par `rechercher_teams` ; pour une conversation ou un canal précis, "
            . "va directement à `lire_conversation_teams` avec son nom. Quand tu rapportes un message, cite "
            . "l'auteur et la date. Si un outil renvoie un champ `error`, transmets ce message tel quel à "
            . "l'utilisateur sans l'inventer ni le reformuler.";
        if (!$canReadChannels) {
            $txt .= " Les messages des CANAUX ne te sont pas accessibles (consentement administrateur non "
                . "accordé) : seules les conversations le sont. Dis-le si l'utilisateur parle d'un canal.";
        }
        if ($canWrite) {
            $txt .= " Tu peux publier avec `envoyer_message_teams`, uniquement sur demande claire. IMPORTANT, et "
                . "à dire à l'utilisateur : un message Teams approuvé part RÉELLEMENT et devient aussitôt visible "
                . "des participants — tu ne pourras ni le corriger ni le supprimer ensuite. Chaque publication "
                . "est soumise à son approbation : l'outil renvoie `en_attente_approbation` et une carte "
                . "s'affiche sous ta réponse. N'affirme JAMAIS que le message est parti : annonce qu'il attend "
                . "son approbation, en résumant la destination et le contenu. Pour répondre dans un canal à un "
                . "message existant, passe son identifiant dans `repondre_a` plutôt que d'ouvrir un nouveau fil.";
        } else {
            $txt .= " Tu ne peux PAS publier de message : la connexion Teams actuelle est en lecture seule. "
                . "Propose de rédiger le texte que l'utilisateur copiera, et invite-le à reconnecter Teams dans "
                . "Réglages → Comptes connectés s'il veut que tu publies toi-même.";
        }
        return $txt;
    }
}
