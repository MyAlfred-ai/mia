<?php

declare(strict_types=1);

class Message
{
    /**
     * Créations riches portées par files_json — jamais supprimées par
     * deleteLastAssistant, affichées en carte, partageables.
     */
    public const RICH_TYPES = ['html_page', 'map_route', 'video_file', 'slide_deck'];

    /**
     * Motif des images générées en markdown — PARTAGÉ par extractGeneratedImages()
     * et neutraliserImages() : deux exemplaires laisseraient l'élagage rater
     * exactement ce que la galerie affiche (ou l'inverse).
     */
    private const MOTIF_IMAGE_GENEREE = '/!\[([^\]]*)\]\((\/assets\/img\/generated\/[^)\s]+)\)/u';

    /**
     * Marqueurs posés par l'élagage (/admin/pruning).
     * INVARIANT : aucun des deux ne doit matcher creationPredicateSql(), sinon
     * chaque élagage ré-élaguerait les mêmes lignes et le compteur d'éligibles
     * ne tomberait jamais à zéro. Vrai par construction (pas de
     * `](/assets/img/generated/`), figé par tests/Unit/PruningTest.php.
     */
    public const MARQUEUR_CREATION = '🗑 Création élaguée le %s — le fichier et le lien de partage ont été supprimés.';
    public const MARQUEUR_IMAGE    = '*🗑 Image élaguée le %s*';

    /**
     * Bornes de la trace d'actions (actions_json, migration 064) — partagées
     * par normalizeActions() (persistance) et ChatController::traceAction()
     * (collecte live) : le flux SSE et la base racontent la même histoire.
     */
    public const ACTIONS_MAX       = 40;
    public const ACTION_LABEL_MAX  = 80;
    public const ACTION_DETAIL_MAX = 120;

    /**
     * $files : tableau de ['name','type','extracted','path'] — 0, 1 ou plusieurs fichiers.
     * $llmProvider / $llmModel : trace du LLM (ou outil) ayant généré la réponse — pour
     * messages assistant uniquement, NULL sinon.
     */
    public static function create(
        int $conversationId,
        string $role,
        string $content,
        array $files = [],
        ?string $llmProvider = null,
        ?string $llmModel = null,
        ?int $responseMs = null
    ): array {
        $db = Database::getInstance();

        $fileName      = null;
        $fileType      = null;
        $fileExtracted = null;
        $filesJson     = null;

        if (count($files) === 1) {
            $fileName      = $files[0]['name']      ?? null;
            $fileType      = $files[0]['type']       ?? null;
            $fileExtracted = $files[0]['extracted']  ?? null;
            $filesJson     = json_encode($files);
        } elseif (count($files) > 1) {
            $filesJson = json_encode($files);
        }

        $db->prepare(
            'INSERT INTO messages
             (conversation_id, role, llm_provider, llm_model, response_ms, content, file_name, file_type, file_extracted, files_json)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $conversationId, $role,
            $llmProvider !== null && $llmProvider !== '' ? $llmProvider : null,
            $llmModel    !== null && $llmModel    !== '' ? $llmModel    : null,
            $responseMs,
            $content,
            $fileName, $fileType, $fileExtracted, $filesJson,
        ]);

        return self::findById((int)$db->lastInsertId());
    }

    /**
     * Normalise une trace d'actions avant persistance : entrées invalides
     * écartées, textes bornés, nombre plafonné (les PREMIÈRES entrées sont
     * conservées — la fin d'une longue boucle d'outils est moins parlante que
     * son déclenchement). Pure, testée sans base.
     * La clé d'icône (`i`, posée par ChatController::traceAction pour le flux
     * live) est volontairement ÉCARTÉE : elle se redéduit du type au rendu
     * (ChatController::withActionIcons), si bien qu'un jeu d'icônes qui évolue
     * profite aussi aux messages déjà enregistrés.
     */
    public static function normalizeActions(array $actions): array
    {
        $clean = [];
        foreach ($actions as $a) {
            if (!is_array($a)) {
                continue;
            }
            $type  = trim((string)($a['t'] ?? ''));
            $label = trim((string)($a['l'] ?? ''));
            if ($type === '' || $label === '') {
                continue;
            }
            $entry = [
                't'  => mb_substr($type, 0, 40),
                'l'  => mb_substr($label, 0, self::ACTION_LABEL_MAX),
                'ok' => !array_key_exists('ok', $a) || (bool)$a['ok'],
            ];
            $detail = trim((string)($a['d'] ?? ''));
            if ($detail !== '') {
                $entry['d'] = mb_substr($detail, 0, self::ACTION_DETAIL_MAX);
            }
            $clean[] = $entry;
            if (count($clean) >= self::ACTIONS_MAX) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Traces d'actions de plusieurs messages d'une conversation (journal d'une
     * exécution de workflow). @return array<int, list<array>> id → trace normalisée
     */
    public static function actionsFor(int $convId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, actions_json FROM messages WHERE conversation_id = ? AND actions_json IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$convId], $ids));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string)$row['actions_json'], true);
            if (is_array($decoded)) {
                $out[(int)$row['id']] = self::normalizeActions($decoded);
            }
        }
        return $out;
    }

    /** Persiste la trace d'actions d'un message assistant (no-op si vide). */
    public static function setActions(int $messageId, array $actions): void
    {
        $clean = self::normalizeActions($actions);
        if ($clean === []) {
            return;
        }
        Database::getInstance()->prepare('UPDATE messages SET actions_json = ? WHERE id = ?')
            ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $messageId]);
    }

    /**
     * Persiste l'état « agent » d'un message assistant (plan de travail,
     * possibilité de poursuivre) — no-op si vide. Le contenu est déjà
     * normalisé par l'appelant (PlanTools::normalize).
     */
    public static function setAgent(int $messageId, array $agent): void
    {
        $agent = array_filter($agent, static fn($v): bool => $v !== null && $v !== [] && $v !== '');
        if ($agent === []) {
            return;
        }
        Database::getInstance()->prepare('UPDATE messages SET agent_json = ? WHERE id = ?')
            ->execute([json_encode($agent, JSON_UNESCAPED_UNICODE), $messageId]);
    }

    /**
     * Garde la suggestion de suite calculée pour une réponse (chaîne vide =
     * « aucune suite ») DANS son état « agent », sans toucher au plan ni à la
     * raison de poursuivre : JSON_SET fusionne, un état illisible repart de {}.
     */
    public static function setSuggestion(int $messageId, string $suggestion): void
    {
        Database::getInstance()->prepare(
            "UPDATE messages SET agent_json = JSON_SET(IF(JSON_VALID(agent_json), agent_json, '{}'), '$.suggestion', ?)"
            . " WHERE id = ? AND role = 'assistant'"
        )->execute([$suggestion, $messageId]);
    }

    /**
     * Derniers messages d'une conversation, du plus ancien au plus récent,
     * pour la suggestion de suite : contenu borné, types et noms des fichiers
     * lus par MySQL (jamais le base64 d'une pièce jointe), âge calculé par la
     * base (jamais comparé à l'horloge de PHP).
     *
     * @return list<array<string, mixed>>
     */
    public static function recentForSuggestion(int $conversationId, int $limit): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, role, LEFT(content, 12000) AS content, llm_provider, agent_json, file_type,"
            . " TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec,"
            . " IF(JSON_VALID(files_json), JSON_EXTRACT(files_json, '$[*].type'), NULL) AS file_types,"
            . " IF(JSON_VALID(files_json), JSON_EXTRACT(files_json, '$[*].name'), NULL) AS file_names"
            . ' FROM messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(20, $limit))
        );
        $stmt->execute([$conversationId]);
        return array_reverse($stmt->fetchAll());
    }

    /** État « agent » décodé d'une ligne `messages` (tableau vide sinon). */
    public static function agentOf(array $row): array
    {
        if (empty($row['agent_json'])) {
            return [];
        }
        $decoded = json_decode((string)$row['agent_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function forConversation(int $conversationId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll();
    }

    public static function updateTokens(int $id, int $tokens): void
    {
        Database::getInstance()->prepare('UPDATE messages SET tokens_used=? WHERE id=?')
            ->execute([$tokens, $id]);
    }

    /**
     * Remplace le contenu d'un message (édition visuelle d'une page HTML générée).
     * Les contrôles d'accès (propriété, type de message) sont faits par l'appelant.
     */
    public static function updateContent(int $id, string $content): void
    {
        Database::getInstance()->prepare('UPDATE messages SET content=? WHERE id=?')
            ->execute([$content, $id]);
    }

    /**
     * Supprime le(s) message(s) assistant situés APRÈS le dernier message
     * utilisateur (cible du "Regénérer"). Position-aware : si la conversation
     * se termine par un message utilisateur (réponse jamais persistée suite à
     * un timeout ou une coupure), rien n'est supprimé — la réponse du tour
     * précédent est préservée. Rend le retry automatique côté client sûr.
     *
     * Les CRÉATIONS riches (page HTML, carte, vidéo, deck) ne sont JAMAIS
     * supprimées : « Regénérer » rejoue une réponse texte — emporter une
     * création coûteuse postérieure au dernier message user (ex. « Regénérer »
     * cliqué sous la bulle de résumé d'une itération de page) la détruirait
     * sans pouvoir la reproduire.
     */
    public static function deleteLastAssistant(int $conversationId): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT * FROM messages
             WHERE conversation_id = ? AND role = "assistant"
               AND id > (
                   SELECT last_user_id FROM (
                       SELECT COALESCE(MAX(id), 0) AS last_user_id
                       FROM messages
                       WHERE conversation_id = ? AND role = "user"
                   ) AS t
               )'
        );
        $stmt->execute([$conversationId, $conversationId]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array(self::creationType($row), self::RICH_TYPES, true)) {
                $ids[] = (int)$row['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM messages WHERE id IN ($in)")->execute($ids);
    }

    /**
     * Dernière réponse assistant POSTÉRIEURE au dernier message utilisateur —
     * celle que « Regénérer » remplace (même sélection que deleteLastAssistant).
     * Null si la conversation se termine par une demande sans réponse.
     */
    public static function lastAnswer(int $conversationId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM messages
             WHERE conversation_id = ? AND role = "assistant"
               AND id > (
                   SELECT last_user_id FROM (
                       SELECT COALESCE(MAX(id), 0) AS last_user_id
                       FROM messages
                       WHERE conversation_id = ? AND role = "user"
                   ) AS t
               )
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$conversationId, $conversationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Dernier message utilisateur d'une conversation (la demande à rejouer). */
    public static function lastUserMessage(int $conversationId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM messages WHERE conversation_id = ? AND role = "user" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Réponse produite par l'OUTIL IMAGE (ChatController::runImagine) : une
     * image générée seule, suivie au plus du « *Prompt enrichi : …* » que pose
     * le fournisseur. « Regénérer » la rejoue en génération d'image — le LLM de
     * la conversation, lui, ne sait que rédiger. Une image noyée dans de la
     * prose (chemin function-calling `generer_image`) n'en est pas une : sa
     * régénération reste une réponse de chat. Pure.
     */
    public static function isImageToolAnswer(array $msg): bool
    {
        if (($msg['role'] ?? '') !== 'assistant') {
            return false;
        }
        return preg_match(
            '/^\s*!\[[^\]]*\]\(\/assets\/img\/generated\/[^)\s]+\)\s*(?:\*Prompt enrichi : [\s\S]*\*\s*)?$/u',
            (string)($msg['content'] ?? '')
        ) === 1;
    }

    /**
     * Type de création partageable porté par un message :
     * 'html_page' | 'map_route' | 'video_file' | 'slide_deck' | 'generated_image' | null.
     * Détection : files_json, puis file_type legacy (mono-fichier), puis
     * message image-only (markdown seul pointant une image générée).
     */
    public static function creationType(array $msg): ?string
    {
        $richTypes = self::RICH_TYPES;

        $files = [];
        if (!empty($msg['files_json'])) {
            $decoded = json_decode((string)$msg['files_json'], true);
            $files   = is_array($decoded) ? $decoded : [];
        }
        foreach ($files as $file) {
            $t = (string)($file['type'] ?? '');
            if (in_array($t, $richTypes, true)) {
                return $t;
            }
        }

        $legacyType = (string)($msg['file_type'] ?? '');
        if (in_array($legacyType, $richTypes, true)) {
            return $legacyType;
        }

        if (self::generatedImageUrl($msg) !== null) {
            return 'generated_image';
        }

        return null;
    }

    /**
     * URL de l'image générée si le message est "image seule" (même détection
     * que le partial message.php : uniquement une image markdown), restreinte
     * aux fichiers produits par MIA (/assets/img/generated/).
     */
    public static function generatedImageUrl(array $msg): ?string
    {
        $content = (string)($msg['content'] ?? '');
        if (preg_match('/^\s*!\[[^\]]*\]\((\/assets\/img\/generated\/[^)\s]+)\)\s*$/u', $content, $m) === 1) {
            return html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    /**
     * TOUTES les images générées présentes dans un contenu markdown, y compris
     * celles suivies d'un « *Prompt enrichi : …* » ou noyées dans de la prose
     * (chemin function-calling `generer_image`). NE remplace PAS
     * generatedImageUrl() — la détection stricte « image seule » reste la règle
     * du partage ; celle-ci sert la galerie « Mes créations ».
     * Retour : [['url' => ..., 'alt' => ...], ...] dédupliqué par URL,
     * restreint à /assets/img/generated/ (jamais d'URL tierce).
     */
    public static function extractGeneratedImages(string $content): array
    {
        if (preg_match_all(self::MOTIF_IMAGE_GENEREE, $content, $m, PREG_SET_ORDER) === 0) {
            return [];
        }
        $images = [];
        $seen   = [];
        foreach ($m as $match) {
            $url = html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $images[]   = ['url' => $url, 'alt' => trim((string)$match[1])];
        }
        return $images;
    }

    /**
     * Cartes de la galerie sans image répétée : une même image montrée par
     * deux messages (l'image générée, puis le livrable d'un workflow qui la
     * reprend) n'a qu'une carte — celle qui se partage, sinon la première.
     * L'ordre des autres cartes est gardé.
     *
     * @param list<array> $cards
     * @return list<array>
     */
    public static function withoutRepeatedImages(array $cards): array
    {
        $out = [];
        $at = [];
        foreach ($cards as $card) {
            $url = ($card['type'] ?? '') === 'generated_image' ? (string)($card['url'] ?? '') : '';
            if ($url === '') {
                $out[] = $card;
                continue;
            }
            if (!isset($at[$url])) {
                $at[$url] = count($out);
                $out[] = $card;
            } elseif (!empty($card['shareable']) && empty($out[$at[$url]]['shareable'])) {
                $out[$at[$url]] = $card;
            }
        }
        return $out;
    }

    /** Titre d'un document HTML généré (balise <title>), borné, null si absent. */
    public static function extractHtmlTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) !== 1) {
            return null;
        }
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim((string)preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return null;
        }
        return mb_substr($title, 0, 150);
    }

    /**
     * Champ "title" racine d'un contenu JSON (spec de deck ou d'itinéraire),
     * tolérant au JSON TRONQUÉ par le LEFT() SQL de creationsForUser :
     * décodage complet d'abord, sinon regex sur le début du document.
     */
    public static function extractJsonTitle(string $json): ?string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $title = trim((string)($decoded['title'] ?? ''));
            return $title !== '' ? mb_substr($title, 0, 150) : null;
        }
        if (preg_match('/"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $json, $m) === 1) {
            $title = json_decode('"' . $m[1] . '"');
            $title = is_string($title) ? trim($title) : '';
            return $title !== '' ? mb_substr($title, 0, 150) : null;
        }
        return null;
    }

    /**
     * Créations de l'utilisateur toutes conversations confondues (galerie
     * « Mes créations ») : messages assistant porteurs d'un type riche
     * (files_json/file_type) OU d'au moins une image générée dans le contenu.
     * Colonnes volontairement BORNÉES par LEFT() — files_json assistant est
     * minuscule (les uploads base64 de 20 Mo sont côté user) et 16 Ko de
     * contenu suffisent au titre (<title>, "title" JSON) comme aux images
     * markdown ; conséquences des troncatures : deck > 16 Ko → titre par regex
     * sans compteur de slides, image au-delà de 16 Ko de prose → absente
     * (cas rares, assumés). Cap dur à $limit lignes, plus récentes d'abord.
     */
    public static function creationsForUser(int $userId, int $limit = 1000): array
    {
        $db    = Database::getInstance();
        $limit = max(1, (int)$limit);

        $stmt = $db->prepare(
            'SELECT m.id, m.conversation_id, m.role, m.created_at, m.file_type,
                    LEFT(m.files_json, 600) AS files_json,
                    LEFT(m.content, 16000)  AS content,
                    c.title                 AS conversation_title,
                    cs.token                AS share_token
             FROM messages m
             JOIN conversations c        ON c.id = m.conversation_id AND c.user_id = ?
             LEFT JOIN creation_shares cs ON cs.message_id = m.id
             WHERE ' . self::creationPredicateSql('m') . '
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prédicat SQL « ce message porte une création » — SOURCE UNIQUE, partagée
     * par la galerie « Mes créations » et par l'élagage (/admin/pruning).
     *
     * Recopié, il dériverait : un type ajouté à RICH_TYPES sortirait de la
     * galerie sans sortir de l'élagage — ou l'inverse, bien pire : une création
     * élaguée mais toujours listée, avec un bouton « Ouvrir » qui ne mène nulle
     * part. Aucun paramètre lié : tout vient de constantes de classe.
     */
    public static function creationPredicateSql(string $alias = 'm'): string
    {
        $marqueurs = [];
        foreach (self::RICH_TYPES as $type) {
            $marqueurs[] = $alias . '.files_json LIKE \'%"type":"' . $type . '"%\'';
        }

        return $alias . ".role = 'assistant'\n               AND ( "
            . implode("\n                  OR ", $marqueurs)
            . "\n                  OR " . $alias . ".file_type IN ('" . implode("','", self::RICH_TYPES) . "')"
            . "\n                  OR " . $alias . ".content LIKE '%](/assets/img/generated/%' )";
    }

    /** Marqueur de création élaguée, daté du jour (format FR). */
    public static function marqueurCreation(?string $jour = null): string
    {
        return sprintf(self::MARQUEUR_CREATION, Format::dateFr($jour ?? date('Y-m-d')));
    }

    /**
     * Remplace CHAQUE image générée d'un contenu par un marqueur inline, en
     * laissant la prose intacte : ces messages (chemin function-calling
     * `generer_image`) portent une vraie réponse rédigée en plus des images —
     * tout remplacer créerait exactement le trou qu'on veut éviter.
     *
     * Ne traite que le markdown : les <img src> d'une page illustrée vivent
     * dans un message html_page, remplacé en entier par le marqueur de création.
     * Idempotente : rejouée, elle ne change plus rien (le marqueur ne contient
     * plus d'image markdown).
     */
    public static function neutraliserImages(string $content, ?string $jour = null): string
    {
        $marqueur = sprintf(self::MARQUEUR_IMAGE, Format::dateFr($jour ?? date('Y-m-d')));

        // Callback plutôt qu'une chaîne de remplacement : le marqueur est du
        // texte libre, un `$` ou un `\` y serait interprété comme une référence
        // de capture. Rien à échapper, donc rien à oublier d'échapper.
        return (string)preg_replace_callback(
            self::MOTIF_IMAGE_GENEREE,
            static fn(array $m): string => $marqueur,
            $content
        );
    }
}
