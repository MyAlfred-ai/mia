<?php

declare(strict_types=1);

/**
 * Jeton personnel de « Claude dans Office » (migration 103).
 *
 * Le repli quand l'organisation ne règle pas Entra ID : la personne génère son
 * jeton dans Réglages → Comptes connectés → Claude dans Office et le colle une fois dans l'add-in
 * (« Cloud provider or gateway » → Gateway). Un par personne : en générer un
 * nouveau révoque l'ancien. Seule l'EMPREINTE est gardée — le jeton n'est
 * montré qu'une fois, à sa création, comme une clé d'API.
 */
final class OfficeAddinToken
{
    /** Préfixe : reconnu d'un coup d'œil, et distinct d'un JWT Entra. */
    public const PREFIX = 'mia_office_';

    /** last_used_at n'est réécrit qu'au-delà de ce délai (une écriture par requête serait inutile). */
    public const TOUCH_EVERY_SEC = 600;

    /** Forme d'un jeton émis par MIA (pure). */
    public static function looksValid(string $token): bool
    {
        return preg_match('/^' . self::PREFIX . '[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Fin du jeton montrée à l'écran pour le reconnaître (pure). */
    public static function hintOf(string $token): string
    {
        return '…' . substr($token, -6);
    }

    /**
     * Crée le jeton de la personne (remplace l'ancien) et le rend EN CLAIR —
     * la seule fois où il existe ailleurs que dans l'add-in.
     */
    public static function issue(int $userId): string
    {
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO office_addin_tokens (user_id, token_hash, hint, created_at, last_used_at)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), hint = VALUES(hint),
                                     created_at = NOW(), last_used_at = NULL'
        )->execute([$userId, self::hash($token), self::hintOf($token)]);
        return $token;
    }

    public static function revoke(int $userId): void
    {
        Database::getInstance()->prepare('DELETE FROM office_addin_tokens WHERE user_id = ?')->execute([$userId]);
    }

    /** État du jeton d'une personne (sans empreinte), null s'il n'y en a pas. */
    public static function forUser(int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT hint, created_at, last_used_at FROM office_addin_tokens WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Compte rattaché à un jeton, null s'il est inconnu. Note l'usage (borné). */
    public static function findUser(string $token): ?array
    {
        if (!self::looksValid($token)) {
            return null;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.id AS token_id, TIMESTAMPDIFF(SECOND, t.last_used_at, NOW()) AS idle, u.*
             FROM office_addin_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? LIMIT 1'
        );
        $stmt->execute([self::hash($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $idle = $row['idle'];
        if ($idle === null || (int)$idle >= self::TOUCH_EVERY_SEC) {
            try {
                $db->prepare('UPDATE office_addin_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['token_id']]);
            } catch (\Throwable $e) {
                error_log('[Claude dans Office] horodatage du jeton impossible : ' . $e->getMessage());
            }
        }
        unset($row['token_id'], $row['idle']);
        return $row;
    }

    /** Nombre de jetons en circulation (écran d'administration). */
    public static function count(): int
    {
        return (int)Database::getInstance()->query('SELECT COUNT(*) FROM office_addin_tokens')->fetchColumn();
    }
}
