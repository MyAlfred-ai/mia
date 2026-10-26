<?php

declare(strict_types=1);

/**
 * Appareils inscrits aux notifications (migration 084) : une ligne par
 * navigateur, retrouvée par l'EMPREINTE de son adresse d'envoi (l'adresse est
 * un secret : qui la possède peut écrire à l'appareil).
 *
 * Un navigateur n'appartient qu'à un compte à la fois : réinscrit par un autre
 * compte, il change de propriétaire (UNIQUE sur l'empreinte). La validation de
 * l'adresse et des clés est celle de WebPush ; ce modèle ne fait que ranger.
 */
final class PushSubscription
{
    /** Appareils par compte : au-delà, le plus ancien laisse sa place. */
    public const MAX_PER_USER = 10;

    /** Échecs d'affilée (hors « n'existe plus ») avant de retirer un appareil. */
    public const MAX_FAILURES = 10;

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Inscrit (ou réinscrit) un navigateur pour ce compte. Les clés sont
     * rangées telles que le navigateur les donne (base64url).
     */
    public static function upsert(int $userId, string $endpoint, string $p256dh, string $auth, string $label): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, label)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint),
                 p256dh = VALUES(p256dh), auth = VALUES(auth), label = VALUES(label), failures = 0'
        )->execute([$userId, $endpoint, self::hash($endpoint), $p256dh, $auth, mb_substr($label, 0, 120)]);

        // Plafond : les plus anciens appareils du compte sortent.
        $stmt = $db->prepare('SELECT id FROM push_subscriptions WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $extra = array_slice($ids, self::MAX_PER_USER);
        if ($extra !== []) {
            $in = implode(',', array_fill(0, count($extra), '?'));
            $db->prepare("DELETE FROM push_subscriptions WHERE id IN ($in)")->execute($extra);
        }
    }

    /**
     * Retire un navigateur, quel que soit son compte : posséder l'adresse,
     * c'est être cet appareil (déconnexion, changement de compte).
     */
    public static function deleteByEndpoint(string $endpoint): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
        $stmt->execute([self::hash($endpoint)]);
        return $stmt->rowCount() > 0;
    }

    public static function deleteForUser(int $id, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array{id: int, endpoint: string, p256dh: string, auth: string, label: string, created_at: string, last_success_at: ?string, failures: int}> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, endpoint, p256dh, auth, label, created_at, last_success_at, failures
             FROM push_subscriptions WHERE user_id = ? ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['failures'] = (int)$row['failures'];
            return $row;
        }, $stmt->fetchAll());
    }

    public static function countForUser(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Issue d'un envoi, appareil par appareil (WebPush::send) : distribué →
     * compteur remis à zéro ; « n'existe plus » → effacé ; sinon un échec de
     * plus, et l'appareil sort au-delà de MAX_FAILURES.
     *
     * @param array<int, int> $statuses identifiant → statut HTTP
     * @return array{sent: int, removed: int, failed: int}
     */
    public static function recordResults(array $statuses): array
    {
        $db = Database::getInstance();
        $out = ['sent' => 0, 'removed' => 0, 'failed' => 0];
        foreach ($statuses as $id => $status) {
            $outcome = WebPush::outcome((int)$status);
            if ($outcome === 'ok') {
                $db->prepare('UPDATE push_subscriptions SET failures = 0, last_success_at = ? WHERE id = ?')
                   ->execute([gmdate('Y-m-d H:i:s'), (int)$id]);
                $out['sent']++;
            } elseif ($outcome === 'gone') {
                $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([(int)$id]);
                $out['removed']++;
            } else {
                $db->prepare('UPDATE push_subscriptions SET failures = LEAST(failures + 1, 255) WHERE id = ?')->execute([(int)$id]);
                $db->prepare('DELETE FROM push_subscriptions WHERE id = ? AND failures >= ?')->execute([(int)$id, self::MAX_FAILURES]);
                $out['failed']++;
            }
        }
        return $out;
    }

    /** Toutes les inscriptions — nouvelle paire de clés de l'instance : elles sont caduques. */
    public static function purgeAll(): void
    {
        Database::getInstance()->exec('DELETE FROM push_subscriptions');
    }

    /**
     * Nom lisible d'un appareil, tiré de l'agent utilisateur : « Chrome ·
     * macOS », « Safari · iPhone ». Indicatif seulement (la liste des
     * appareils), jamais une décision. Pure.
     */
    public static function deviceLabel(string $userAgent): string
    {
        $ua = $userAgent;
        $browser = match (true) {
            str_contains($ua, 'Edg/')                                  => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')   => 'Opera',
            str_contains($ua, 'SamsungBrowser')                         => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/')                                => 'Safari',
            default                                                     => '',
        };
        $system = match (true) {
            str_contains($ua, 'iPhone')                                     => 'iPhone',
            str_contains($ua, 'iPad')                                       => 'iPad',
            str_contains($ua, 'Android')                                    => 'Android',
            str_contains($ua, 'Windows')                                    => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS')                                       => 'ChromeOS',
            str_contains($ua, 'Linux')                                      => 'Linux',
            default                                                         => '',
        };
        $label = implode(' · ', array_filter([$browser, $system]));
        return $label !== '' ? $label : t('Navigateur');
    }
}
