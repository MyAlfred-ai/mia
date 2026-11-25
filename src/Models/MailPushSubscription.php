<?php

declare(strict_types=1);

/**
 * Boîtes mail suivies en mode push (table mail_push_subscriptions) : une
 * ligne par personne et par fournisseur (gmail | outlook). Cf. MailPush.
 *
 * Dates en UTC posées par PHP (ScheduledTask::sqlUtc), jamais comparées à
 * NOW() de MySQL.
 */
final class MailPushSubscription
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ERROR  = 'error';

    /** @return array<string, mixed>|null */
    public static function find(int $userId, string $provider): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM mail_push_subscriptions WHERE user_id = ? AND provider = ?');
        $stmt->execute([$userId, $provider]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /** Abonnement Graph désigné par une notification. */
    public static function findByExternal(string $provider, string $externalId): ?array
    {
        if ($externalId === '') {
            return null;
        }
        $stmt = Database::getInstance()->prepare('SELECT * FROM mail_push_subscriptions WHERE provider = ? AND external_id = ? LIMIT 1');
        $stmt->execute([$provider, $externalId]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /** Boîtes Gmail d'une adresse (Pub/Sub ne dit que l'adresse). @return list<array> */
    public static function forAccount(string $provider, string $account): array
    {
        $account = mb_strtolower(trim($account));
        if ($account === '') {
            return [];
        }
        $stmt = Database::getInstance()->prepare('SELECT * FROM mail_push_subscriptions WHERE provider = ? AND account = ?');
        $stmt->execute([$provider, $account]);
        return $stmt->fetchAll();
    }

    /** @return list<array> toutes, ou celles d'une personne */
    public static function all(?int $userId = null): array
    {
        if ($userId !== null) {
            $stmt = Database::getInstance()->prepare('SELECT * FROM mail_push_subscriptions WHERE user_id = ?');
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        return Database::getInstance()->query('SELECT * FROM mail_push_subscriptions')->fetchAll();
    }

    /** Abonnement en service (créé ou renouvelé). */
    public static function saveActive(int $userId, string $provider, array $fields, \DateTimeImmutable $now): void
    {
        $expires = $fields['expires'] ?? null;
        Database::getInstance()->prepare(
            'INSERT INTO mail_push_subscriptions (user_id, provider, account, external_id, secret_hash, endpoint, status, error, expires_at, renewed_at, retry_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'\', ?, ?, NULL)
             ON DUPLICATE KEY UPDATE account = VALUES(account), external_id = VALUES(external_id), secret_hash = VALUES(secret_hash),
                endpoint = VALUES(endpoint), status = VALUES(status), error = \'\', expires_at = VALUES(expires_at),
                renewed_at = VALUES(renewed_at), retry_at = NULL'
        )->execute([
            $userId, $provider, mb_substr((string)($fields['account'] ?? ''), 0, 255),
            ($fields['external_id'] ?? '') !== '' ? (string)$fields['external_id'] : null,
            ($fields['secret_hash'] ?? '') !== '' ? (string)$fields['secret_hash'] : null,
            mb_substr((string)($fields['endpoint'] ?? ''), 0, 500), self::STATUS_ACTIVE,
            $expires instanceof \DateTimeImmutable ? ScheduledTask::sqlUtc($expires) : null,
            ScheduledTask::sqlUtc($now),
        ]);
    }

    /** Mise en place impossible : la cause, et le prochain essai. */
    public static function saveError(int $userId, string $provider, string $error, \DateTimeImmutable $retryAt): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO mail_push_subscriptions (user_id, provider, status, error, retry_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error), retry_at = VALUES(retry_at)'
        )->execute([$userId, $provider, self::STATUS_ERROR, mb_substr($error, 0, 300), ScheduledTask::sqlUtc($retryAt)]);
    }

    /** Graph demande une réautorisation : l'abonnement est renouvelé au prochain passage. */
    public static function requestRenew(int $id): void
    {
        Database::getInstance()->prepare('UPDATE mail_push_subscriptions SET expires_at = NULL WHERE id = ?')->execute([$id]);
    }

    public static function touchNotified(int $userId, string $provider, \DateTimeImmutable $now): void
    {
        Database::getInstance()->prepare('UPDATE mail_push_subscriptions SET notified_at = ? WHERE user_id = ? AND provider = ?')
            ->execute([ScheduledTask::sqlUtc($now), $userId, $provider]);
    }

    public static function delete(int $userId, string $provider): void
    {
        Database::getInstance()->prepare('DELETE FROM mail_push_subscriptions WHERE user_id = ? AND provider = ?')->execute([$userId, $provider]);
    }

    /** Boîtes suivies par fournisseur : en service, en erreur. @return array<string, array{active:int, error:int}> */
    public static function counts(): array
    {
        $out = [];
        try {
            foreach (Database::getInstance()->query('SELECT provider, status, COUNT(*) AS n FROM mail_push_subscriptions GROUP BY provider, status')->fetchAll() as $r) {
                $out[(string)$r['provider']][(string)$r['status'] === self::STATUS_ACTIVE ? 'active' : 'error'] = (int)$r['n'];
            }
        } catch (\Throwable) {
            // table absente (migration en cours) : rien à compter
        }
        return $out;
    }
}
