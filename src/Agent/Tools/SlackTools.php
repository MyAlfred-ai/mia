<?php

declare(strict_types=1);

/**
 * Outils Slack du chat (jeton UTILISATEUR) : canaux, recherche, lecture de canal et de
 * fil, publication soumise à approbation. Chaque outil est gardé par son propre droit.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class SlackTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserSlackService $slack)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'slack';
    }

    public function connector(): ?string
    {
        return 'slack';
    }

    public function definitions(): array
    {
        return $this->slackToolDefinitions($this->slack);
    }

    public function guidance(): string
    {
        return $this->slackToolGuidance($this->slack);
    }

    public function externalContentTools(): array
    {
        return [
            'lister_canaux_slack',
            'rechercher_slack',
            'lire_canal_slack',
            'lire_fil_slack',
        ];
    }

    public function handlers(): array
    {
        $slackService        = $this->slack;
        $slackTextBudget     = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        if ($slackService->canList()) {
            $toolHandlers['lister_canaux_slack'] = function (array $args) use ($slackService) {
                $this->sseEmit(['phase' => 'slack_search']);
                try {
                    return self::hintIfEmpty(
                        ['canaux' => $slackService->listChannels(
                            (string)($args['recherche'] ?? ''),
                            (int)($args['max_resultats'] ?? 20)
                        )],
                        'canaux',
                        "Aucun canal ne correspond. Réessaie sans filtre pour voir la liste, ou souviens-toi "
                            . "que l'utilisateur ne voit que les canaux dont il est membre."
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($slackService->canSearch()) {
            $toolHandlers['rechercher_slack'] = function (array $args) use ($slackService) {
                $this->sseEmit(['phase' => 'slack_search']);
                try {
                    return self::hintIfEmpty(
                        ['messages' => $slackService->searchMessages(
                            (string)($args['requete'] ?? ''),
                            (int)($args['max_resultats'] ?? 10)
                        )],
                        'messages',
                        "Aucun message trouvé. Élargis les termes, retire un filtre (in:, from:, during:), "
                            . "ou vérifie que la conversation visée est bien accessible à l'utilisateur."
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($slackService->canRead()) {
            $toolHandlers['lire_canal_slack'] = function (array $args) use ($slackService, $slackTextBudget) {
                $this->sseEmit(['phase' => 'slack_read']);
                try {
                    return $slackService->readChannel(
                        (string)($args['canal'] ?? ''),
                        (int)($args['max_messages'] ?? 20),
                        $slackTextBudget
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
            $toolHandlers['lire_fil_slack'] = function (array $args) use ($slackService, $slackTextBudget) {
                $this->sseEmit(['phase' => 'slack_read']);
                try {
                    return $slackService->readThread(
                        (string)($args['canal'] ?? ''),
                        (string)($args['message'] ?? ''),
                        $slackTextBudget
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            };
        }
        if ($slackService->canPost()) {
            $toolHandlers['envoyer_message_slack'] = function (array $args) use ($queueConnectorWrite) {
                // Validation amont (canal, texte, horodatage du fil) : une
                // erreur revient au modèle pendant qu'il rédige, jamais dans
                // une carte d'approbation que l'utilisateur validerait pour
                // rien. La conversion en mrkdwn a lieu ici aussi, pour que la
                // carte montre EXACTEMENT ce qui sera publié.
                try {
                    $normalise = UserSlackService::normalizePostArgs($args);
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('envoyer_message_slack', $normalise, null);
            };
        }

        return $toolHandlers;
    }

    /**
     * Définitions function-calling des outils Slack, selon les scopes réellement
     * accordés. Un consentement partiel n'expose que ce qu'il permet : annoncer
     * au modèle un outil que Slack refusera ensuite en 403 produit une réponse
     * qui promet puis échoue.
     */
    private function slackToolDefinitions(UserSlackService $slack): array
    {
        return self::definitionsFor($slack->canList(), $slack->canSearch(), $slack->canRead(), $slack->canPost());
    }

    /**
     * Définitions selon les capacités — forme PURE, SOURCE UNIQUE partagée par
     * le chat et par l'écran d'administration « Application Slack », qui montre
     * à l'administrateur ce que le modèle reçoit réellement. Deux textes
     * recopiés dériveraient au premier ajustement d'une description.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitionsFor(bool $canList, bool $canSearch, bool $canRead, bool $canPost): array
    {
        $defs = [];

        if ($canList) {
            $defs[] = [
                'name'        => 'lister_canaux_slack',
                'description' => "Liste les canaux Slack dont l'utilisateur est membre (id, nom, privé ou non, "
                    . "sujet). À n'appeler QUE pour retrouver le nom exact d'un canal ou en proposer un : "
                    . "`lire_canal_slack` et `envoyer_message_slack` acceptent directement un nom (« #projet-x »).",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'recherche' => [
                            'type'        => 'string',
                            'description' => "Filtre sur le nom du canal (facultatif).",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de canaux (1 à 100). Défaut : 20.",
                        ],
                    ],
                ],
            ];
        }

        if ($canSearch) {
            $defs[] = [
                'name'        => 'rechercher_slack',
                'description' => "Recherche des messages dans TOUT ce que l'utilisateur peut lire sur Slack "
                    . "(canaux publics, canaux privés dont il est membre, messages directs). La syntaxe "
                    . "native de Slack est acceptée et recommandée : `in:#canal`, `from:@personne`, "
                    . "`during:july`, `has:link`, `\"expression exacte\"`. Renvoie pour chaque message : "
                    . "canal, auteur, date, extrait, identifiant (`ts`) et lien permanent. À utiliser AVANT "
                    . "`lire_fil_slack` pour obtenir l'identifiant d'un message.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete' => [
                            'type'        => 'string',
                            'description' => "Termes recherchés, avec ou sans opérateurs Slack.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de messages (1 à 20). Défaut : 10.",
                        ],
                    ],
                    'required'   => ['requete'],
                ],
            ];
        }

        if ($canRead) {
            $defs[] = [
                'name'        => 'lire_canal_slack',
                'description' => "Lit les derniers messages d'un canal Slack, du plus ANCIEN au plus récent. "
                    . "Idéal pour « qu'est-ce qui s'est dit dans #projet-x ? ». Le canal se désigne par son "
                    . "nom (« projet-x » ou « #projet-x »), son identifiant, ou un lien Slack.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'canal' => [
                            'type'        => 'string',
                            'description' => "Nom, identifiant ou lien du canal.",
                        ],
                        'max_messages' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de messages (1 à 50). Défaut : 20.",
                        ],
                    ],
                    'required'   => ['canal'],
                ],
            ];
            $defs[] = [
                'name'        => 'lire_fil_slack',
                'description' => "Lit un fil de discussion complet : le message d'origine et toutes ses "
                    . "réponses. L'identifiant du message (`ts`, de la forme 1720000000.123456) vient de "
                    . "`rechercher_slack` ou de `lire_canal_slack` ; un lien permanent Slack est aussi accepté.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'canal'   => ['type' => 'string', 'description' => "Nom, identifiant ou lien du canal."],
                        'message' => ['type' => 'string', 'description' => "Identifiant `ts` du message, ou son lien permanent."],
                    ],
                    'required'   => ['canal', 'message'],
                ],
            ];
        }

        if ($canPost) {
            $defs[] = [
                'name'        => 'envoyer_message_slack',
                'description' => "Publie un message Slack AU NOM de l'utilisateur. Chaque publication est "
                    . "soumise à son approbation explicite (carte de confirmation) ; une fois approuvée, le "
                    . "message est IMMÉDIATEMENT VISIBLE par les membres du canal et ne peut plus être ni "
                    . "modifié ni supprimé par MIA. Écris le texte en Markdown normal : la conversion vers "
                    . "la syntaxe Slack est faite pour toi.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'canal' => [
                            'type'        => 'string',
                            'description' => "Nom, identifiant ou lien du canal de destination.",
                        ],
                        'texte' => [
                            'type'        => 'string',
                            'description' => "Contenu du message, en Markdown.",
                        ],
                        'repondre_a' => [
                            'type'        => 'string',
                            'description' => "Identifiant `ts` du message auquel répondre : le message part alors "
                                . "DANS SON FIL au lieu d'ouvrir une nouvelle discussion. Vide = nouveau message.",
                        ],
                    ],
                    'required'   => ['canal', 'texte'],
                ],
            ];
        }

        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Slack sont disponibles. */
    private function slackToolGuidance(UserSlackService $slack): string
    {
        return self::guidanceFor($slack->canPost());
    }

    /**
     * Ce qui change dans la consigne d'une connexion en LECTURE SEULE : la fin
     * de `guidanceFor(false)` à partir de la première phrase qui diffère de
     * `guidanceFor(true)`. Montré sur l'écran d'administration sans recopier la
     * phrase : si la consigne évolue, l'extrait suit. Coupe à la frontière de
     * phrase (« . ») qui précède la divergence, jamais au milieu d'un mot.
     */
    public static function readOnlyGuidanceTail(): string
    {
        $base  = self::guidanceFor(true);
        $other = self::guidanceFor(false);
        $n = min(strlen($base), strlen($other));
        $i = 0;
        while ($i < $n && $base[$i] === $other[$i]) {
            $i++;
        }
        if ($i >= strlen($other)) {
            return '';
        }
        $coupe = strrpos(substr($other, 0, $i), '. ');
        return trim(substr($other, $coupe === false ? 0 : $coupe + 1));
    }

    /**
     * Consigne selon le droit de publier — forme PURE, SOURCE UNIQUE partagée
     * avec l'écran d'administration (même raison que `definitionsFor`).
     */
    public static function guidanceFor(bool $canPost): string
    {
        $txt = "\n\nTu peux consulter le Slack personnel de l'utilisateur. Tu ne vois QUE ce qu'il voit "
            . "lui-même : un canal privé dont il n'est pas membre n'existe pas pour toi, et « je ne trouve "
            . "rien » peut simplement vouloir dire « il n'y a pas accès ». Pour une question ouverte "
            . "(« qu'a-t-on dit du budget ? »), commence par `rechercher_slack` ; pour une question sur un "
            . "canal précis (« quoi de neuf dans #projet-x ? »), va directement à `lire_canal_slack`. "
            . "Quand tu rapportes un message, cite l'auteur et la date, et donne le lien permanent s'il est "
            . "disponible. Un identifiant de message Slack rappelé dans le contexte d'une conversation "
            . "s'écrit « canal/horodatage » : sépare-le avant de le passer aux outils. Si un outil renvoie "
            . "un champ `error`, transmets ce message tel quel à l'utilisateur sans l'inventer ni le "
            . "reformuler.";

        if ($canPost) {
            $txt .= " Tu peux publier avec `envoyer_message_slack`, uniquement sur demande claire. "
                . "IMPORTANT, et à dire à l'utilisateur : contrairement à un brouillon d'email, un message "
                . "Slack approuvé part RÉELLEMENT et devient aussitôt visible de tout le canal — tu ne "
                . "pourras ni le corriger ni le supprimer ensuite. Chaque publication est donc soumise à son "
                . "approbation : l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta "
                . "réponse. N'affirme JAMAIS que le message est parti : annonce qu'il attend son approbation, "
                . "en résumant le canal visé et le contenu. Pour répondre à un message existant, passe son "
                . "identifiant dans `repondre_a` plutôt que d'ouvrir une nouvelle discussion dans le canal.";
        } else {
            $txt .= " Tu ne peux PAS publier de message : la connexion Slack actuelle est en lecture seule. "
                . "Si l'utilisateur le demande, propose-lui de rédiger le texte qu'il copiera, et invite-le "
                . "à reconnecter son Slack dans Réglages → Comptes connectés s'il veut que tu publies toi-même.";
        }
        return $txt;
    }
}
