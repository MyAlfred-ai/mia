<?php

declare(strict_types=1);

/**
 * Outils de la MÉMOIRE de l'utilisateur, hors connecteurs :
 *
 *   - `rechercher_conversations` : retrouve un échange passé dans ses autres
 *     conversations (ConversationSearch) ;
 *   - `memoriser` : enregistre un fait durable dans « MIA pour vous » ;
 *   - `relire_fichier_conversation` : relit un fichier joint plus tôt dans
 *     CETTE conversation, que la fenêtre de contexte ne renvoie plus à chaque
 *     message (ContextWindow). Exposé seulement dans ce cas.
 *
 * `memoriser` n'est exposé que lorsque la boucle d'outils tourne de toute
 * façon (connecteurs, outils de l'assistant) : la capture historique,
 * déclenchée par « souviens-toi que… » avant la réponse (captureUserMemory),
 * reste le chemin de tous les modèles ; l'outil rattrape ce qu'elle laisse
 * passer — une tournure qu'elle ne reconnaît pas, ou le « oui » qui accepte
 * une proposition de retenir quelque chose.
 *
 * Ce ne sont pas des connecteurs : aucune donnée externe, rien à relier.
 */
final class ConversationTools extends AbstractToolProvider
{
    public function __construct(
        AgentTurn $turn,
        private readonly bool $withSearch,
        private readonly bool $withMemory,
        private readonly bool $withFiles = false,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'conversations';
    }

    public function definitions(): array
    {
        $defs = [];
        if ($this->withSearch) {
            $defs[] = [
                'name'        => 'rechercher_conversations',
                'description' => "Recherche dans les AUTRES conversations de l'utilisateur avec MIA (pas celle-ci) : "
                    . "à utiliser quand il fait allusion à un échange passé (« la dernière fois », « tu m'avais "
                    . "conseillé… », « reprends le plan de la semaine dernière »). Renvoie, par conversation : "
                    . "titre, date, lien et extraits où apparaissent les mots cherchés.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete'       => ['type' => 'string', 'description' => "Mots précis à retrouver (sujet, nom, outil, chiffre) — pas une phrase."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Nombre maximum de conversations (1 à " . ConversationSearch::MAX_CONVERSATIONS . ", défaut : " . ConversationSearch::DEFAULT_RESULTS . ")."],
                    ],
                    'required'   => ['requete'],
                ],
            ];
        }
        if ($this->withMemory) {
            $defs[] = [
                'name'        => 'memoriser',
                'description' => "Enregistre un fait DURABLE sur l'utilisateur dans sa mémoire « MIA pour vous » (rôle, "
                    . "préférence, contrainte, habitude de travail), réutilisé dans toutes ses conversations. "
                    . "UNIQUEMENT quand il le demande (« retiens que… », « note pour la suite… ») ou accepte "
                    . "explicitement ta proposition de le retenir. Jamais un détail passager, jamais une donnée "
                    . "sensible (mot de passe, numéro de carte, santé).",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fait' => ['type' => 'string', 'description' => "Une phrase courte et autoportante, sans pronom initial (ex. « Préfère des réponses courtes, en anglais. »)."],
                    ],
                    'required'   => ['fait'],
                ],
            ];
        }
        if ($this->withFiles) {
            $defs[] = [
                'name'        => 'relire_fichier_conversation',
                'description' => "Relit un fichier joint plus tôt DANS CETTE conversation, dont le contenu ne t'est "
                    . "plus renvoyé à chaque message (conversation longue : le message indique alors « [Fichier "
                    . "« … » joint à ce message …] »). Seulement si la question en a besoin. Renvoie le texte du "
                    . "fichier, par parties s'il est long (`partie`). Une image ne se relit pas.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier' => ['type' => 'string', 'description' => "Nom du fichier tel qu'il apparaît dans la conversation, ou son numéro d'ordre (1 = le premier joint)."],
                        'partie'  => ['type' => 'integer', 'description' => "Partie à lire pour un long fichier (1 = début, défaut). Le résultat indique le nombre de parties."],
                    ],
                    'required'   => ['fichier'],
                ],
            ];
        }
        return $defs;
    }

    public function guidance(): string
    {
        $txt = '';
        if ($this->withSearch) {
            $txt .= "\n\nSi l'utilisateur fait allusion à un échange passé avec toi, retrouve-le avec "
                . "`rechercher_conversations` (des mots précis, pas une phrase) au lieu de lui demander de "
                . "le recopier ; cite la conversation retrouvée par son lien Markdown [titre](lien). Si rien "
                . "n'est trouvé, dis-le : n'invente pas ce qui aurait été dit.";
        }
        if ($this->withMemory) {
            $txt .= "\n\nTu peux enregistrer un fait durable sur l'utilisateur avec `memoriser`, seulement s'il "
                . "le demande ou accepte ta proposition ; annonce-le en une phrase, il pourra le retirer dans "
                . "Réglages → Préférences IA.";
        }
        if ($this->withFiles) {
            $txt .= "\n\nPour relire un fichier ancien de cette conversation (par son nom ou son numéro), appelle "
                . "`relire_fichier_conversation` plutôt que de demander à l'utilisateur de le joindre à nouveau.";
        }
        return $txt;
    }

    public function handlers(): array
    {
        $turn = $this->turn;
        $handlers = [];

        if ($this->withSearch) {
            $handlers['rechercher_conversations'] = function (array $args) use ($turn): array {
                $this->sseEmit(['phase' => 'conversation_search']);
                $q = $args['requete'] ?? '';
                $result = ConversationSearch::search(
                    $turn->userId,
                    $turn->convId,
                    is_scalar($q) ? (string)$q : '',
                    isset($args['max_resultats']) && is_numeric($args['max_resultats'])
                        ? (int)$args['max_resultats'] : ConversationSearch::DEFAULT_RESULTS
                );
                if (isset($result['error'])) {
                    return $result;
                }
                return self::hintIfEmpty(
                    $result + ['note' => "Cite la conversation d'où vient l'information : [titre](lien)."],
                    'conversations',
                    "Aucune conversation ne contient ces mots. Essaie d'autres mots (synonyme, nom propre, "
                    . "chiffre), un seul mot-clé, ou dis à l'utilisateur que tu ne retrouves pas cet échange."
                );
            };
        }

        if ($this->withMemory) {
            $handlers['memoriser'] = function (array $args) use ($turn): array {
                $this->sseEmit(['phase' => 'memorizing']);
                $fait = UserMemory::sanitize(is_scalar($args['fait'] ?? null) ? (string)$args['fait'] : '');
                if ($fait === '') {
                    return ['error' => 'Rien à mémoriser : donne le fait en une phrase.'];
                }
                if (UserMemory::looksSensitive($fait)) {
                    return ['error' => "Donnée sensible (mot de passe, numéro de carte, IBAN…) : elle n'est pas "
                        . "mémorisée. Explique-le à l'utilisateur."];
                }
                try {
                    $res = UserMemory::add($turn->userId, $fait, UserMemory::SOURCE_CHAT);
                } catch (\Throwable $e) {
                    error_log('[Mémoire] outil memoriser en échec (user ' . $turn->userId . ') : ' . $e->getMessage());
                    return ['error' => 'La mémoire est indisponible pour le moment : rien n\'a été enregistré.'];
                }
                if ($res['status'] === 'ok') {
                    // Même pastille que la capture historique (renderMemoryChip).
                    $this->sseEmit(['memory_saved' => ['faits' => [$res['content']], 'doublons' => 0, 'plein' => false]]);
                    return ['statut' => 'memorise', 'fait' => $res['content']];
                }
                return match ($res['status']) {
                    'duplicate' => ['statut' => 'deja_connu', 'fait' => $res['content'],
                        'note' => 'Ce fait figurait déjà dans la mémoire : rien de neuf à enregistrer.'],
                    'full'      => ['error' => 'La mémoire est pleine (' . UserMemory::MAX_ENTRIES . ' souvenirs) : '
                        . 'l\'utilisateur doit en retirer dans Réglages → Préférences IA.'],
                    default     => ['error' => 'Rien à mémoriser : donne le fait en une phrase.'],
                };
            };
        }

        if ($this->withFiles) {
            $handlers['relire_fichier_conversation'] = function (array $args) use ($turn): array {
                $this->sseEmit(['phase' => 'conversation_file']);
                $wanted = $args['fichier'] ?? '';
                try {
                    $file = ContextWindow::pickFile(
                        ContextWindow::conversationFiles(Message::forConversation($turn->convId)),
                        is_scalar($wanted) ? (string)$wanted : ''
                    );
                    $page = TextPager::page(
                        self::fileText($file),
                        max(TextPager::MIN_CHARS, $turn->textBudget * 4),
                        is_numeric($args['partie'] ?? null) ? (int)$args['partie'] : 1
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return ['nom' => $file['nom'], 'numero' => $file['numero']] + $page;
            };
        }

        return $handlers;
    }

    /**
     * Texte d'un fichier joint : tel qu'extrait à l'envoi pour un document
     * texte, ré-extrait du PDF (stocké en base64 pour l'envoi natif). Une image
     * n'a pas de texte.
     *
     * @throws \RuntimeException message FR
     */
    private static function fileText(array $file): string
    {
        $type = (string)$file['type'];
        $name = (string)$file['nom'];
        if (str_starts_with($type, 'image/')) {
            throw new \RuntimeException('« ' . $name . ' » est une image : elle ne se relit pas comme du texte. '
                . 'Si la question en a besoin, demande à l\'utilisateur de la joindre à nouveau.');
        }
        if ($type === 'application/pdf') {
            $bytes = base64_decode((string)$file['extracted'], true);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('Le PDF « ' . $name . ' » n\'est plus lisible : demande à l\'utilisateur de le joindre à nouveau.');
            }
            return MailAttachments::extractText($bytes, 'application/pdf', $name);
        }
        $text = (string)$file['extracted'];
        if (trim($text) === '') {
            throw new \RuntimeException('Aucun texte dans « ' . $name . ' ».');
        }
        return $text;
    }
}
