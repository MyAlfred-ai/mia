<?php

declare(strict_types=1);

/**
 * Outils Gmail du chat (boîte PERSONNELLE de l'utilisateur, OAuth) : recherche, lecture,
 * brouillon soumis à la carte de relecture.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class GmailTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserGmailService $gmail)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'gmail';
    }

    public function connector(): ?string
    {
        return 'gmail';
    }

    public function definitions(): array
    {
        return $this->gmailToolDefinitions($this->gmail);
    }

    public function guidance(): string
    {
        return $this->gmailToolGuidance($this->gmail);
    }

    public function externalContentTools(): array
    {
        return [
            'rechercher_gmail',
            'lire_gmail',
            // Une pièce jointe est écrite par l'expéditeur, comme le message.
            'lire_piece_jointe_gmail',
        ];
    }

    public function handlers(): array
    {
        $gmailService        = $this->gmail;
        $gmailTextBudget     = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $scope = $this->turn->scope();
        $toolHandlers['rechercher_gmail'] = function (array $args) use ($gmailService, $scope) {
            $this->sseEmit(['phase' => 'gmail_search']);
            try {
                // Filtres d'une brique de workflow : ajoutés à CHAQUE recherche.
                $requete = $scope->gmailQuery((string)($args['requete'] ?? ''));
                return self::hintIfEmpty(['resultats' => $gmailService->search(
                    $requete !== '' ? $requete : 'in:inbox',
                    (int)($args['max_resultats'] ?? 10)
                )], 'resultats', "Aucun email trouvé. Élargis : retire un filtre (after:, from:…), essaie d'autres mots-clés — une expression exacte entre guillemets est souvent trop stricte.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_gmail'] = function (array $args) use ($gmailService, $gmailTextBudget) {
            $this->sseEmit(['phase' => 'gmail_read']);
            try {
                return $gmailService->readMessage((string)($args['message'] ?? ''), $gmailTextBudget, self::partArg($args));
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_piece_jointe_gmail'] = function (array $args) use ($gmailService, $gmailTextBudget) {
            $this->sseEmit(['phase' => 'gmail_attachment']);
            $voulue = $args['piece_jointe'] ?? '';
            try {
                return $gmailService->readAttachment(
                    (string)($args['message'] ?? ''),
                    is_scalar($voulue) ? (string)$voulue : '',
                    $gmailTextBudget,
                    (int)($args['partie'] ?? 1)
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        if ($gmailService->canDraft()) {
            $toolHandlers['creer_brouillon_gmail'] = function (array $args) use ($queueConnectorWrite) {
                // Validation amont (adresses/MIME) : une erreur revient au
                // modèle, jamais dans une carte cassée.
                //
                // En RÉPONSE, destinataire et objet sont déduits du message
                // d'origine à l'exécution : les exiger ici ferait échouer
                // le cas le plus courant (« réponds à ce mail »).
                $repondreA = trim((string)($args['repondre_a'] ?? ''));
                try {
                    if ($repondreA !== '') {
                        UserGmailService::assertMessageId($repondreA);
                        if (trim((string)($args['destinataire'] ?? '')) !== '') {
                            UserGmailService::buildDraftMime(
                                (string)$args['destinataire'],
                                (string)($args['objet'] ?? ''),
                                (string)($args['corps'] ?? ''),
                                (string)($args['cc'] ?? '')
                            );
                        }
                    } else {
                        UserGmailService::buildDraftMime(
                            (string)($args['destinataire'] ?? ''),
                            (string)($args['objet'] ?? ''),
                            (string)($args['corps'] ?? ''),
                            (string)($args['cc'] ?? '')
                        );
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_brouillon_gmail', $args, null);
            };
        }

        return $toolHandlers;
    }

    /** Définitions function-calling des outils Gmail (brouillon si scope compose). */
    private function gmailToolDefinitions(UserGmailService $gmail): array
    {
        $defs = [
            [
                'name'        => 'rechercher_gmail',
                'description' => "Recherche des emails dans la boîte Gmail PERSONNELLE de l'utilisateur. "
                    . "Accepte la syntaxe de recherche Gmail native : from:alice@ex.fr, to:, subject:, "
                    . "after:2026/01/15, before:, has:attachment, is:unread, label:, \"expression exacte\", "
                    . "OR, -exclusion. Renvoie pour chaque email : id, expéditeur, objet, date et un court "
                    . "extrait. À utiliser AVANT lire_gmail pour obtenir l'id d'un message.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete' => [
                            'type'        => 'string',
                            'description' => "Requête de recherche (mots-clés et/ou opérateurs Gmail : from:, subject:, after:…).",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de résultats (1 à 20). Défaut : 10.",
                        ],
                    ],
                    'required'   => ['requete'],
                ],
            ],
            [
                'name'        => 'lire_gmail',
                'description' => "Lit un email complet de la boîte Gmail PERSONNELLE de l'utilisateur : "
                    . "expéditeur, destinataires, objet, date, corps du message (texte ; un long email se "
                    . "lit par parties, `partie`) et liste de ses pièces jointes (`pieces_jointes` : "
                    . "numéro, nom, type, taille, lisible). Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => [
                            'type'        => 'string',
                            'description' => "ID du message (obtenu via rechercher_gmail).",
                        ],
                        'partie' => [
                            'type'        => 'integer',
                            'description' => "Partie à lire pour un long contenu (1 = début, défaut). Le résultat indique le nombre de parties (`parties`) et comment lire la suite.",
                        ],
                    ],
                    'required'   => ['message'],
                ],
            ],
            [
                'name'        => 'lire_piece_jointe_gmail',
                'description' => "Lit le TEXTE d'une pièce jointe d'un email Gmail (PDF, Word, Excel, PowerPoint, "
                    . "OpenDocument, texte, CSV, HTML…). Les pièces jointes d'un message sont listées par "
                    . "lire_gmail : désigne-la par son numéro ou son nom. Une image ou un format marqué "
                    . "`lisible: false` ne se lit pas. Un long document se lit par parties (`partie`). "
                    . "Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => [
                            'type'        => 'string',
                            'description' => "ID du message qui porte la pièce jointe (obtenu via rechercher_gmail ou lire_gmail).",
                        ],
                        'piece_jointe' => [
                            'type'        => 'string',
                            'description' => "Numéro (« 1 », « 2 »…) ou nom du fichier, tels que listés par lire_gmail.",
                        ],
                        'partie' => [
                            'type'        => 'integer',
                            'description' => "Partie à lire pour un long document (1 = début, défaut). Le résultat indique le nombre de parties.",
                        ],
                    ],
                    'required'   => ['message', 'piece_jointe'],
                ],
            ],
        ];

        if ($gmail->canDraft()) {
            $defs[] = [
                'name'        => 'creer_brouillon_gmail',
                'description' => "Prépare un email dans la boîte Gmail de l'utilisateur. N'ENVOIE RIEN toi-même : "
                    . "le message est présenté à l'utilisateur dans une carte de relecture, où il choisit "
                    . "seul de l'enregistrer en brouillon ou de l'envoyer. Chaque email est soumis à ce choix "
                    . "explicite (rien n'existe tant qu'il n'a pas cliqué). Pour RÉPONDRE à un email, "
                    . "renseigne `repondre_a` : le message se range alors dans la conversation d'origine "
                    . "au lieu d'en ouvrir une nouvelle.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'destinataire' => ['type' => 'string', 'description' => "Adresse(s) email du ou des destinataires, séparées par des virgules. Facultatif en réponse : l'expéditeur du message d'origine est repris."],
                        'objet'        => ['type' => 'string', 'description' => "Objet de l'email. Facultatif en réponse : « Re: » + objet d'origine."],
                        'corps'        => ['type' => 'string', 'description' => "Corps de l'email, en Markdown simple (**gras**, *italique*, listes -, 1., [liens](url), lignes vides entre paragraphes) : il est converti en HTML avant l'envoi. N'ajoute PAS de signature, elle est reprise automatiquement de la boîte."],
                        'cc'           => ['type' => 'string', 'description' => "Adresse(s) en copie, séparées par des virgules (optionnel)."],
                        'repondre_a'   => ['type' => 'string', 'description' => "ID du message Gmail auquel répondre (obtenu via rechercher_gmail ou lire_gmail). Rattache le brouillon à la conversation d'origine."],
                    ],
                    'required'   => ['corps'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Gmail sont disponibles. */
    private function gmailToolGuidance(UserGmailService $gmail): string
    {
        $txt = "\n\nTu disposes des outils `rechercher_gmail` et `lire_gmail` pour consulter la boîte "
            . "Gmail personnelle de l'utilisateur. Commence par `rechercher_gmail` (syntaxe Gmail : "
            . "from:, subject:, after:…) puis lis le bon message par son id. Cite toujours l'expéditeur "
            . "et l'objet de l'email que tu as lu. Si le contenu est marqué tronqué, signale-le. "
            . "Quand la question porte sur un fichier joint (facture, contrat, compte rendu…), lis-le avec "
            . "`lire_piece_jointe_gmail` (numéro ou nom tels que listés dans `pieces_jointes`) plutôt que "
            . "de répondre d'après le seul corps du message. "
            . "Si un outil renvoie un champ `error`, transmets ce message tel quel à l'utilisateur "
            . "sans l'inventer ni le reformuler.";

        if ($gmail->canDraft()) {
            $txt .= " Tu peux préparer des emails avec `creer_brouillon_gmail`. Pour RÉPONDRE à un "
                . "email, passe son id dans `repondre_a` — c'est la SEULE façon de rattacher le message "
                . "à la conversation d'origine ; sans lui, Gmail ouvre un fil neuf. Laisse alors "
                . "destinataire et objet vides, ils sont repris du message d'origine. Écris le corps en "
                . "Markdown simple (gras, italique, listes, liens) : il est converti en HTML. N'écris "
                . "AUCUNE signature ni formule de fin de type « Cordialement, <nom> » suivie de "
                . "coordonnées : la signature de la boîte est ajoutée automatiquement. Tu n'envoies JAMAIS "
                . "d'email toi-même : aucun outil d'envoi n'existe. C'est l'utilisateur qui, dans la carte, "
                . "choisit d'enregistrer le message en brouillon ou de l'envoyer ; n'affirme jamais qu'un "
                . "email a été envoyé. Ne prépare un email que sur demande claire de l'utilisateur. "
                . "IMPORTANT : chaque email est soumis à son choix — l'outil renvoie `en_attente_approbation` "
                . "et une carte s'affiche sous ta réponse. N'affirme JAMAIS que le brouillon a été créé : "
                . "annonce qu'il attend sa décision dans la carte ci-dessous, en résumant destinataire "
                . "et objet — SANS recopier le corps du message : la carte l'affiche en entier, et "
                . "l'utilisateur peut y modifier le texte ou le faire améliorer avant d'approuver.";
        } else {
            $txt .= " Tu ne peux PAS créer de brouillons : la connexion Gmail actuelle est en lecture "
                . "seule. Si l'utilisateur le demande, invite-le à reconnecter son Gmail dans "
                . "Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
