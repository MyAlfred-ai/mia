<?php

declare(strict_types=1);

class Conversation
{
    /**
     * Titre posé à la création, en attendant le titre résumé après la première
     * réponse. Valeur PERSISTÉE et comparée telle quelle (elle sert de témoin
     * « pas encore titrée ») : elle ne se traduit jamais en base, seulement à
     * l'affichage (displayTitle).
     */
    public const DEFAULT_TITLE = 'Nouvelle conversation';

    /**
     * Titre à afficher : le titre par défaut dans la langue du lecteur, un
     * titre vide rendu « Conversation » ; tout autre titre tel quel (c'est du
     * contenu, rédigé dans la langue de qui l'a produit).
     */
    public static function displayTitle(?string $title): string
    {
        $title = trim((string)$title);
        if ($title === '') {
            return t('Conversation');
        }
        return $title === self::DEFAULT_TITLE ? t('Nouvelle conversation') : $title;
    }

    public static function create(int $userId, ?int $profileId, ?string $llm): array
    {
        $db = Database::getInstance();
        $db->prepare('INSERT INTO conversations (user_id, profile_id, llm_used, title) VALUES (?,?,?,?)')
           ->execute([$userId, $profileId, $llm, self::DEFAULT_TITLE]);

        return self::findById((int)$db->lastInsertId());
    }

    /**
     * Conversation née d'une tâche programmée (Scheduler) : titrée d'emblée —
     * aucun titre à résumer après coup — et rattachée à sa tâche (icône, bandeau,
     * reprise du fil d'une exécution à l'autre).
     */
    public static function createForTask(int $userId, ?int $profileId, string $llm, string $title, int $taskId): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO conversations (user_id, profile_id, llm_used, title, scheduled_task_id) VALUES (?,?,?,?,?)'
        )->execute([$userId, $profileId, $llm !== '' ? $llm : null, mb_substr($title, 0, 255), $taskId]);
        return (int)$db->lastInsertId();
    }

    /**
     * Conversation titrée d'emblée, sans tâche : l'essai d'une tâche programmée
     * lancé depuis son formulaire (« Essai — Point du lundi »).
     */
    public static function createTitled(int $userId, ?int $profileId, string $llm, string $title): int
    {
        $db = Database::getInstance();
        $db->prepare('INSERT INTO conversations (user_id, profile_id, llm_used, title) VALUES (?,?,?,?)')
            ->execute([$userId, $profileId, $llm !== '' ? $llm : null, mb_substr($title, 0, 255)]);
        return (int)$db->lastInsertId();
    }

    /** Assistant et modèle de la tâche, recopiés sur sa conversation à chaque exécution. */
    public static function syncForTask(int $id, ?int $profileId, string $llm): void
    {
        Database::getInstance()->prepare('UPDATE conversations SET profile_id = ?, llm_used = ? WHERE id = ?')
            ->execute([$profileId, $llm !== '' ? $llm : null, $id]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM conversations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function allForUser(int $userId): array
    {
        // Épinglées d'abord (par pinned_at desc), puis les autres par updated_at desc.
        $stmt = Database::getInstance()->prepare(
            'SELECT c.*, p.name as profile_name, p.color as profile_color
             FROM conversations c
             LEFT JOIN profiles p ON c.profile_id = p.id
             WHERE c.user_id = ?
             ORDER BY c.is_pinned DESC, c.pinned_at DESC, c.updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public const MAX_PINNED = 5;

    public static function countPinned(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM conversations WHERE user_id=? AND is_pinned=1'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Bascule l'état épinglé d'une conversation.
     * Retourne ['pinned' => bool, 'count' => int] ou ['error' => 'max']
     * si le quota MAX_PINNED serait dépassé en épinglant.
     */
    public static function togglePin(int $id, int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT is_pinned FROM conversations WHERE id=? AND user_id=?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['error' => 'not_found'];
        }
        $isPinned = (int)$row['is_pinned'] === 1;
        if (!$isPinned && self::countPinned($userId) >= self::MAX_PINNED) {
            return ['error' => 'max', 'count' => self::countPinned($userId)];
        }
        if ($isPinned) {
            $db->prepare('UPDATE conversations SET is_pinned=0, pinned_at=NULL WHERE id=?')
               ->execute([$id]);
            return ['pinned' => false, 'count' => self::countPinned($userId)];
        }
        $db->prepare('UPDATE conversations SET is_pinned=1, pinned_at=NOW() WHERE id=?')
           ->execute([$id]);
        return ['pinned' => true, 'count' => self::countPinned($userId)];
    }

    /**
     * Recherche dans l'historique : titre de conversation OU contenu des messages.
     * Résultats triés du plus récent au plus ancien. Chaque ligne porte un
     * `title_match` (1/0) et un `snippet` (extrait autour du terme, ou null).
     */
    public static function search(int $userId, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $db = Database::getInstance();
        // Échappement des jokers LIKE (\ % _) — caractère d'échappement MySQL par défaut : \
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like    = '%' . $escaped . '%';

        $stmt = $db->prepare(
            'SELECT c.*, p.name AS profile_name, p.color AS profile_color,
                    (c.title LIKE ?) AS title_match
             FROM conversations c
             LEFT JOIN profiles p ON c.profile_id = p.id
             WHERE c.user_id = ?
               AND (
                   c.title LIKE ?
                   OR EXISTS (
                       SELECT 1 FROM messages m
                       WHERE m.conversation_id = c.id AND m.content LIKE ?
                   )
               )
             ORDER BY c.updated_at DESC
             LIMIT 50'
        );
        $stmt->execute([$like, $userId, $like, $like]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        // Extrait : le message correspondant le plus récent de chaque conversation
        $ids          = array_map(static fn($r) => (int)$r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $snipStmt = $db->prepare(
            "SELECT conversation_id, content
             FROM messages
             WHERE conversation_id IN ($placeholders) AND content LIKE ?
             ORDER BY created_at DESC"
        );
        $snipStmt->execute([...$ids, $like]);

        $snippets = [];
        foreach ($snipStmt->fetchAll() as $m) {
            $cid = (int)$m['conversation_id'];
            if (!isset($snippets[$cid])) {
                $snippets[$cid] = self::buildSnippet((string)$m['content'], $query);
            }
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['snippet'] = $snippets[(int)$row['id']] ?? null;
        }

        return $rows;
    }

    /** Extrait une fenêtre de texte centrée sur le terme recherché. */
    private static function buildSnippet(string $content, string $query, int $radius = 70): string
    {
        $clean = trim((string)preg_replace('/\s+/u', ' ', $content));
        $pos   = mb_stripos($clean, $query);

        if ($pos === false) {
            $head = mb_substr($clean, 0, $radius * 2);
            return mb_strlen($clean) > mb_strlen($head) ? $head . '…' : $head;
        }

        $start   = max(0, $pos - $radius);
        $length  = mb_strlen($query) + $radius * 2;
        $snippet = mb_substr($clean, $start, $length);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($start + $length < mb_strlen($clean)) {
            $snippet .= '…';
        }

        return $snippet;
    }

    public static function updateTitle(int $id, string $title): void
    {
        Database::getInstance()->prepare('UPDATE conversations SET title=?, updated_at=NOW() WHERE id=?')
            ->execute([$title, $id]);
    }

    public static function touch(int $id): void
    {
        Database::getInstance()->prepare('UPDATE conversations SET updated_at=NOW() WHERE id=?')
            ->execute([$id]);
    }

    /**
     * Un résultat est arrivé en l'absence de la personne (tâche programmée) :
     * pastille dans la barre latérale jusqu'à l'ouverture de la conversation.
     */
    public static function markUnread(int $id): void
    {
        Database::getInstance()->prepare('UPDATE conversations SET unread_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    /** Ouverture de la conversation : la pastille s'éteint. N'écrit rien s'il n'y avait rien à lire. */
    public static function markRead(int $id, int $userId): void
    {
        Database::getInstance()->prepare(
            'UPDATE conversations SET unread_at = NULL WHERE id = ? AND user_id = ? AND unread_at IS NOT NULL'
        )->execute([$id, $userId]);
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM conversations WHERE id=?')->execute([$id]);
    }

    /**
     * Supprime les conversations vides (aucun message) d'un utilisateur, pour
     * qu'elles ne polluent pas l'historique. $exceptId épargne la conversation
     * en cours d'affichage (vide tant que l'utilisateur n'a pas encore écrit).
     * Retourne le nombre de conversations supprimées.
     */
    public static function deleteEmptyForUser(int $userId, ?int $exceptId = null): int
    {
        $sql = 'DELETE FROM conversations
                WHERE user_id = ?
                  AND NOT EXISTS (
                        SELECT 1 FROM messages m WHERE m.conversation_id = conversations.id
                      )';
        $params = [$userId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function belongsToUser(int $id, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT id FROM conversations WHERE id=? AND user_id=?');
        $stmt->execute([$id, $userId]);
        return (bool)$stmt->fetch();
    }
}
