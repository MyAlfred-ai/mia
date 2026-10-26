<?php

declare(strict_types=1);

/**
 * Partage d'une création par lien unique (/share/{token}).
 *
 * Une ligne = un lien actif pour un message-création (page HTML, image, vidéo,
 * carte). UNIQUE(message_id) garantit un seul lien par création ; révoquer =
 * supprimer la ligne, régénérer = DELETE + INSERT (l'ancien token meurt).
 * La page de partage reste réservée aux utilisateurs connectés — le token
 * n'est pas un accès anonyme, juste un pointeur non devinable vers la création.
 */
class CreationShare
{
    /** Retourne le partage actif d'un message, ou null. */
    public static function forMessage(int $messageId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM creation_shares WHERE message_id = ?');
        $stmt->execute([$messageId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Tokens de tous les messages partagés d'une conversation, en une requête
     * (pas de N+1 au rendu du fil) : [message_id => token].
     */
    public static function forConversation(int $conversationId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT cs.message_id, cs.token
             FROM creation_shares cs
             JOIN messages m ON m.id = cs.message_id
             WHERE m.conversation_id = ?'
        );
        $stmt->execute([$conversationId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['message_id']] = (string)$row['token'];
        }
        return $map;
    }

    /** Crée le lien s'il n'existe pas, sinon renvoie l'existant (idempotent). */
    public static function createOrGet(int $messageId, int $userId): array
    {
        $existing = self::forMessage($messageId);
        if ($existing !== null) {
            return $existing;
        }
        return self::insert($messageId, $userId);
    }

    /** Remplace le lien : l'ancien token devient immédiatement invalide. */
    public static function regenerate(int $messageId, int $userId): array
    {
        self::revoke($messageId);
        return self::insert($messageId, $userId);
    }

    /** Révoque le lien (token absent = 404 sur /share/{token}). */
    public static function revoke(int $messageId): void
    {
        Database::getInstance()->prepare('DELETE FROM creation_shares WHERE message_id = ?')
            ->execute([$messageId]);
    }

    /**
     * Révoque les liens de PLUSIEURS messages d'un coup (élagage des créations).
     * Un seul DELETE plutôt que N appels à revoke() : un élagage en traite des
     * centaines par lot. Retourne le nombre de liens réellement supprimés.
     *
     * Le CASCADE de creation_shares ne joue pas ici : le message SURVIT à
     * l'élagage (son contenu devient un marqueur), seul le lien doit mourir.
     */
    public static function revokeMany(array $messageIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare("DELETE FROM creation_shares WHERE message_id IN ($in)");
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /**
     * Résout un token vers sa création : colonnes du message + nom du partageur
     * (shared_by) + date de partage (shared_at). Null si token inconnu/révoqué.
     * Volontairement AUCUNE donnée de conversation (ni id, ni titre) : le lien
     * ne doit exposer que la création.
     */
    public static function findByToken(string $token): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT m.id, m.role, m.content, m.file_type, m.files_json,
                    cs.created_at AS shared_at, u.name AS shared_by
             FROM creation_shares cs
             JOIN messages m ON m.id = cs.message_id
             JOIN users u    ON u.id = cs.created_by
             WHERE cs.token = ?'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    private static function insert(int $messageId, int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        Database::getInstance()->prepare(
            'INSERT INTO creation_shares (message_id, created_by, token) VALUES (?,?,?)'
        )->execute([$messageId, $userId, $token]);

        return self::forMessage($messageId);
    }
}
