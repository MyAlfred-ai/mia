<?php

declare(strict_types=1);

/**
 * Outils Outlook du chat (messagerie Microsoft de l'utilisateur, OAuth délégué) :
 * recherche, lecture, brouillon soumis à la carte de relecture.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class OutlookTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserOutlookService $outlook)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'outlook';
    }

    public function connector(): ?string
    {
        return 'outlook';
    }

    public function definitions(): array
    {
        return $this->outlookToolDefinitions($this->outlook);
    }

    public function guidance(): string
    {
        return $this->outlookToolGuidance($this->outlook);
    }

    public function externalContentTools(): array
    {
        return [
            'rechercher_outlook',
            'lire_outlook',
            // Une pièce jointe est écrite par l'expéditeur, comme le message.
            'lire_piece_jointe_outlook',
        ];
    }

    public function handlers(): array
    {
        $outlookService      = $this->outlook;
        $outlookTextBudget   = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $scope = $this->turn->scope();
        $toolHandlers['rechercher_outlook'] = function (array $args) use ($outlookService, $scope) {
            $this->sseEmit(['phase' => 'outlook_search']);
            try {
                // Filtres d'une brique de workflow : ajoutés à CHAQUE recherche.
                return self::hintIfEmpty(['resultats' => $scope->filterOutlook($outlookService->search(
                    $scope->outlookQuery((string)($args['requete'] ?? '')),
                    (int)($args['max_resultats'] ?? 10)
                ))], 'resultats', "Aucun email trouvé. Essaie d'autres mots-clés, ou une requête vide pour obtenir les derniers messages de la boîte de réception.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_outlook'] = function (array $args) use ($outlookService, $outlookTextBudget) {
            $this->sseEmit(['phase' => 'outlook_read']);
            try {
                return $outlookService->readMessage((string)($args['message'] ?? ''), $outlookTextBudget, self::partArg($args));
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_piece_jointe_outlook'] = function (array $args) use ($outlookService, $outlookTextBudget) {
            $this->sseEmit(['phase' => 'outlook_attachment']);
            $voulue = $args['piece_jointe'] ?? '';
            try {
                return $outlookService->readAttachment(
                    (string)($args['message'] ?? ''),
                    is_scalar($voulue) ? (string)$voulue : '',
                    $outlookTextBudget,
                    (int)($args['partie'] ?? 1)
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        if ($outlookService->canDraft()) {
            $toolHandlers['creer_brouillon_outlook'] = function (array $args) use ($queueConnectorWrite) {
                // Validation amont (adresses) : une erreur revient au
                // modèle, jamais dans une carte cassée.
                //
                // En RÉPONSE, destinataire et objet sont déduits du message
                // d'origine à l'exécution : les exiger ici ferait échouer
                // le cas le plus courant (« réponds à ce mail »).
                $repondreA = trim((string)($args['repondre_a'] ?? ''));
                try {
                    if ($repondreA !== '') {
                        UserOutlookService::assertMessageId($repondreA);
                        if (trim((string)($args['corps'] ?? '')) === '') {
                            return ['error' => 'Le corps de la réponse est vide.'];
                        }
                        if (trim((string)($args['destinataire'] ?? '')) !== '') {
                            UserOutlookService::parseAddressList((string)$args['destinataire']);
                        }
                        UserOutlookService::parseAddressList((string)($args['cc'] ?? ''));
                    } else {
                        UserOutlookService::buildDraftPayload(
                            (string)($args['destinataire'] ?? ''),
                            (string)($args['objet'] ?? ''),
                            (string)($args['corps'] ?? ''),
                            (string)($args['cc'] ?? '')
                        );
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_brouillon_outlook', $args, null);
            };
        }

        return $toolHandlers;
    }

    /** Définitions function-calling des outils Outlook (brouillon si scope Mail.ReadWrite). */
    private function outlookToolDefinitions(UserOutlookService $outlook): array
    {
        $defs = [
            [
                'name'        => 'rechercher_outlook',
                'description' => "Recherche des emails dans la boîte Outlook PERSONNELLE de l'utilisateur. "
                    . "Accepte la syntaxe de recherche Microsoft (KQL) : from:alice@ex.fr, to:, subject:, "
                    . "body:, received>=2026-01-15, hasAttachments:true, isRead:false, \"expression exacte\", "
                    . "AND/OR/NOT. Laisse `requete` VIDE pour obtenir simplement les derniers messages reçus. "
                    . "Renvoie pour chaque email : id, expéditeur, objet, date, extrait, lu et pièces jointes. "
                    . "À utiliser AVANT lire_outlook pour obtenir l'id d'un message.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete' => [
                            'type'        => 'string',
                            'description' => "Requête de recherche (mots-clés et/ou opérateurs KQL : from:, subject:, received>=…). "
                                . "Vide = les derniers messages reçus.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de résultats (1 à 20). Défaut : 10.",
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'lire_outlook',
                'description' => "Lit un email complet de la boîte Outlook PERSONNELLE de l'utilisateur : "
                    . "expéditeur, destinataires, objet, date, corps du message (texte ; un long email se "
                    . "lit par parties, `partie`) et liste de ses pièces jointes (`pieces_jointes` : "
                    . "numéro, nom, type, taille, lisible). Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => [
                            'type'        => 'string',
                            'description' => "ID du message (obtenu via rechercher_outlook).",
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
                'name'        => 'lire_piece_jointe_outlook',
                'description' => "Lit le TEXTE d'une pièce jointe d'un email Outlook (PDF, Word, Excel, PowerPoint, "
                    . "OpenDocument, texte, CSV, HTML…). Les pièces jointes d'un message sont listées par "
                    . "lire_outlook : désigne-la par son numéro ou son nom. Une image, un email joint, un lien "
                    . "de partage ou un format marqué `lisible: false` ne se lit pas. Un long document se lit "
                    . "par parties (`partie`). Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => [
                            'type'        => 'string',
                            'description' => "ID du message qui porte la pièce jointe (obtenu via rechercher_outlook ou lire_outlook).",
                        ],
                        'piece_jointe' => [
                            'type'        => 'string',
                            'description' => "Numéro (« 1 », « 2 »…) ou nom du fichier, tels que listés par lire_outlook.",
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

        if ($outlook->canDraft()) {
            $defs[] = [
                'name'        => 'creer_brouillon_outlook',
                'description' => "Prépare un email dans la boîte Outlook de l'utilisateur. N'ENVOIE RIEN toi-même : "
                    . "le message est présenté à l'utilisateur dans une carte de relecture, où il choisit "
                    . "seul de l'enregistrer en brouillon ou de l'envoyer. Chaque email est soumis à ce choix "
                    . "explicite (rien n'existe tant qu'il n'a pas cliqué). Pour RÉPONDRE à un email, "
                    . "renseigne `repondre_a` : le message se range alors dans la conversation d'origine "
                    . "au lieu d'en ouvrir une nouvelle.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'destinataire' => ['type' => 'string', 'description' => "Adresse(s) email du ou des destinataires, séparées par des virgules. Facultatif en réponse : l'expéditeur du message d'origine est repris."],
                        'objet'        => ['type' => 'string', 'description' => "Objet de l'email. Ignoré en réponse : Outlook reprend « RE: » + objet d'origine (le changer scinderait le fil)."],
                        'corps'        => ['type' => 'string', 'description' => "Corps de l'email, en Markdown simple (**gras**, *italique*, listes -, 1., [liens](url), lignes vides entre paragraphes) : il est converti en HTML avant l'envoi."],
                        'cc'           => ['type' => 'string', 'description' => "Adresse(s) en copie, séparées par des virgules (optionnel)."],
                        'repondre_a'   => ['type' => 'string', 'description' => "ID du message Outlook auquel répondre (obtenu via rechercher_outlook ou lire_outlook). Rattache le brouillon à la conversation d'origine."],
                    ],
                    // Seul le corps est indispensable : en réponse, destinataire
                    // et objet viennent du message d'origine, et les exiger
                    // ferait échouer le cas le plus courant.
                    'required'   => ['corps'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Outlook sont disponibles. */
    private function outlookToolGuidance(UserOutlookService $outlook): string
    {
        $txt = "\n\nTu disposes des outils `rechercher_outlook` et `lire_outlook` pour consulter la boîte "
            . "Outlook personnelle de l'utilisateur. Commence par `rechercher_outlook` (syntaxe KQL : "
            . "from:, subject:, received>=…, ou requête vide pour les derniers messages) puis lis le bon "
            . "message par son id. Cite toujours l'expéditeur et l'objet de l'email que tu as lu. Si le "
            . "contenu est marqué tronqué, signale-le. Quand la question porte sur un fichier joint "
            . "(facture, contrat, compte rendu…), lis-le avec `lire_piece_jointe_outlook` (numéro ou nom "
            . "tels que listés dans `pieces_jointes`) plutôt que de répondre d'après le seul corps du "
            . "message. Si un outil renvoie un champ `error`, transmets ce "
            . "message tel quel à l'utilisateur sans l'inventer ni le reformuler.";

        if ($outlook->canDraft()) {
            $txt .= " Tu peux préparer des emails avec `creer_brouillon_outlook`. Pour RÉPONDRE à un "
                . "email, passe son id dans `repondre_a` (et laisse `destinataire` et `objet` vides : ils "
                . "sont repris du message d'origine) — sans cela ta réponse ouvrirait une conversation "
                . "neuve au lieu de se ranger dans le fil. Tu n'envoies JAMAIS d'email toi-même, et tu ne "
                . "modifies ni ne supprimes jamais un message existant : aucun outil de ce genre n'existe. "
                . "C'est l'utilisateur qui, dans la carte, choisit d'enregistrer le message en brouillon ou "
                . "de l'envoyer ; n'affirme jamais qu'un email a été envoyé. Ne prépare un email que sur "
                . "demande claire de l'utilisateur. IMPORTANT : chaque email est soumis à son choix "
                . "— l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta réponse. "
                . "N'affirme JAMAIS que le brouillon a été créé : annonce qu'il attend sa décision dans "
                . "la carte ci-dessous, en résumant destinataire et objet — SANS recopier le corps du message : "
                . "la carte l'affiche en entier, et l'utilisateur peut y modifier le texte ou le faire améliorer "
                . "avant d'approuver.";
        } else {
            $txt .= " Tu ne peux PAS créer de brouillons : la connexion Outlook actuelle est en lecture "
                . "seule. Si l'utilisateur le demande, invite-le à reconnecter son Outlook dans "
                . "Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
