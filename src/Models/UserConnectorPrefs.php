<?php

declare(strict_types=1);

/**
 * Préférences par utilisateur d'un connecteur du chat (table
 * user_connector_prefs, migration 063).
 *
 * Ce modèle ne connaît RIEN du contenu : il range et rend un tableau associatif
 * pour un couple (utilisateur, connecteur). La validation, les valeurs par
 * défaut et le fragment injecté au modèle vivent dans le service du connecteur
 * (`UserTasksService`), qui reste la source unique de sa propre configuration.
 *
 * Volontairement séparé de `user_drive_tokens` : les préférences survivent à une
 * déconnexion / reconnexion, là où la ligne de jeton est supprimée.
 */
class UserConnectorPrefs
{
    /**
     * Préférences brutes enregistrées, ou [] si aucune (jamais null : les
     * appelants fusionnent avec leurs défauts).
     *
     * @return array<string, mixed>
     */
    public static function get(int $userId, string $connector): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT prefs_json FROM user_connector_prefs WHERE user_id = ? AND connector = ?'
        );
        $stmt->execute([$userId, $connector]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $prefs Déjà validé par le service appelant. */
    public static function set(int $userId, string $connector, array $prefs): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO user_connector_prefs (user_id, connector, prefs_json)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)'
        )->execute([
            $userId,
            $connector,
            json_encode($prefs, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }

    public static function delete(int $userId, string $connector): void
    {
        Database::getInstance()->prepare(
            'DELETE FROM user_connector_prefs WHERE user_id = ? AND connector = ?'
        )->execute([$userId, $connector]);
    }
}
