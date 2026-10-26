<?php

declare(strict_types=1);

/**
 * Résumé du début d'une longue conversation (migration 079) : il remplace,
 * dans ce qui part au modèle, les messages jusqu'à `upto` (ContextWindow).
 *
 * Lecture et écriture best-effort : un résumé illisible ou non enregistré
 * coûte au pire une régénération au message suivant — jamais un tour en échec.
 */
final class ConversationSummary
{
    /** Longueur maximale conservée (caractères). */
    public const MAX_CHARS = 6000;

    /** @return array{upto:int, text:string}|null */
    public static function forConversation(int $convId): ?array
    {
        try {
            $st = Database::getInstance()->prepare(
                'SELECT upto_message_id, summary FROM conversation_context_summaries WHERE conversation_id = ?'
            );
            $st->execute([$convId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[Contexte] résumé illisible (conv ' . $convId . ') : ' . $e->getMessage());
            return null;
        }
        if (!is_array($row) || trim((string)$row['summary']) === '') {
            return null;
        }
        return ['upto' => (int)$row['upto_message_id'], 'text' => (string)$row['summary']];
    }

    public static function save(int $convId, int $upto, string $text): void
    {
        $text = trim($text);
        if ($upto <= 0 || $text === '') {
            return;
        }
        try {
            Database::getInstance()->prepare(
                'INSERT INTO conversation_context_summaries (conversation_id, upto_message_id, summary)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE upto_message_id = VALUES(upto_message_id), summary = VALUES(summary)'
            )->execute([$convId, $upto, mb_substr($text, 0, self::MAX_CHARS)]);
        } catch (\Throwable $e) {
            error_log('[Contexte] résumé non enregistré (conv ' . $convId . ') : ' . $e->getMessage());
        }
    }
}
