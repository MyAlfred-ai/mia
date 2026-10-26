<?php

declare(strict_types=1);

/**
 * La connexion d'une base de connaissances « en direct » (migration 073) :
 * l'adresse d'un cerveau MyBrain, sa clé d'application chiffrée, et le dernier
 * constat — ce que MyBrain a répondu au test, et si les appels passent.
 *
 * SOURCE UNIQUE de trois règles :
 *   - le chiffrement de la clé (jamais en clair en base, jamais renvoyée à
 *     l'écran : seul l'aperçu `key_hint` en sort) ;
 *   - le passage d'une panne à l'état affiché (`markFailure`) : une clé
 *     refusée grise la base, une panne passagère non ;
 *   - le disjoncteur : après BREAKER_THRESHOLD échecs d'affilée, on cesse
 *     d'appeler BREAKER_PAUSE_SEC — sans lui, chaque message de chaque
 *     utilisateur paierait le délai d'attente d'un serveur absent.
 */
final class KnowledgeBaseRemote
{
    public const STATUS_OK          = 'ok';
    public const STATUS_REFUSED     = 'refused';
    public const STATUS_UNREACHABLE = 'unreachable';

    /** Échecs d'affilée avant la pause. */
    public const BREAKER_THRESHOLD = 3;

    /** Durée de la pause, en secondes. */
    public const BREAKER_PAUSE_SEC = 60;

    /** Un succès ne réécrit l'état que s'il change, ou toutes les 10 min (écriture évitée à chaque message). */
    private const OK_REFRESH_SEC = 600;

    /**
     * La connexion d'une base, avec `paused` (disjoncteur ouvert) calculé par
     * MySQL : `retry_after` est écrit avec NOW() de MySQL, le relire avec
     * l'horloge de PHP supposerait les deux dans le même fuseau (même choix
     * que KbStatus et son TIMESTAMPDIFF).
     */
    public static function find(int $kbId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT r.*,
                    (r.retry_after IS NOT NULL AND r.retry_after > NOW()) AS paused,
                    TIMESTAMPDIFF(SECOND, r.checked_at, NOW())              AS checked_age
               FROM knowledge_base_remotes r WHERE r.kb_id = ?'
        );
        $stmt->execute([$kbId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Enregistre une connexion VÉRIFIÉE (le test vient de passer) : adresse,
     * clé chiffrée, et ce que MyBrain a répondu. Remet le disjoncteur à zéro.
     *
     * @param array{application:string, droits:list<string>} $whoami
     */
    public static function save(int $kbId, string $baseUrl, string $apiKey, array $whoami): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO knowledge_base_remotes
                (kb_id, base_url, api_key_enc, key_hint, app_name, rights, status, status_error, checked_at, failures, retry_after)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NOW(), 0, NULL)
             ON DUPLICATE KEY UPDATE
                base_url = VALUES(base_url), api_key_enc = VALUES(api_key_enc), key_hint = VALUES(key_hint),
                app_name = VALUES(app_name), rights = VALUES(rights), status = VALUES(status),
                status_error = NULL, checked_at = NOW(), failures = 0, retry_after = NULL'
        )->execute([
            $kbId,
            $baseUrl,
            Encryption::encrypt($apiKey),
            MyBrainClient::keyHint($apiKey),
            mb_substr((string)$whoami['application'], 0, 120),
            implode(',', $whoami['droits']),
            self::STATUS_OK,
        ]);
    }

    /** La clé en clair, pour un appel — null si elle ne se déchiffre pas (clé d'instance changée). */
    public static function apiKey(array $row): ?string
    {
        try {
            $key = Encryption::decrypt((string)($row['api_key_enc'] ?? ''));
        } catch (\Throwable) {
            return null;
        }
        return $key !== '' ? $key : null;
    }

    /** Le client de cette connexion, ou null si elle est inutilisable en l'état. */
    public static function client(array $row, ?callable $transport = null): ?MyBrainClient
    {
        $url = MyBrainClient::normalizeBaseUrl((string)($row['base_url'] ?? ''));
        $key = self::apiKey($row);
        return $url !== null && $key !== null ? new MyBrainClient($url, $key, $transport) : null;
    }

    /** @return list<string> les droits constatés au dernier test */
    public static function rights(array $row): array
    {
        $rights = (string)($row['rights'] ?? '');
        return $rights === '' ? [] : array_values(array_filter(explode(',', $rights)));
    }

    /** Le disjoncteur est-il ouvert ? PURE — lit `paused`, posé par find(). */
    public static function isPaused(array $row): bool
    {
        return (int)($row['paused'] ?? 0) === 1;
    }

    /**
     * Un appel vient de passer. N'écrit que si l'état change ou si le dernier
     * constat a plus de 10 min : un succès à chaque message ne vaut pas une
     * écriture à chaque message. Best effort, ne lève jamais.
     */
    public static function markOk(array $row): void
    {
        $age       = $row['checked_age'] ?? null;
        $unchanged = (string)($row['status'] ?? '') === self::STATUS_OK
            && (int)($row['failures'] ?? 0) === 0
            && $age !== null
            && (int)$age < self::OK_REFRESH_SEC;
        if ($unchanged) {
            return;
        }
        try {
            Database::getInstance()->prepare(
                'UPDATE knowledge_base_remotes
                    SET status = ?, status_error = NULL, checked_at = NOW(), failures = 0, retry_after = NULL
                  WHERE kb_id = ?'
            )->execute([self::STATUS_OK, (int)$row['kb_id']]);
        } catch (\Throwable $e) {
            error_log('[MyBrain] état de la base ' . (int)($row['kb_id'] ?? 0) . ' : ' . $e->getMessage());
        }
    }

    /**
     * Un appel vient d'échouer. Une panne DURABLE (clé refusée, adresse qui
     * ne mène pas à un MyBrain) passe l'état à « refused » : la base se grise
     * dans le chat, avec la raison, jusqu'au prochain test réussi. Une panne
     * PASSAGÈRE compte pour le disjoncteur. Un refus de la demande elle-même
     * (400) ne dit rien de la connexion : on le journalise, sans plus.
     * Best effort, ne lève jamais.
     */
    public static function markFailure(array $row, MyBrainException $e): void
    {
        $kbId = (int)($row['kb_id'] ?? 0);
        error_log('[MyBrain] base ' . $kbId . ' — ' . $e->kind . ' : ' . $e->getMessage());
        if ($e->kind === MyBrainException::REQUEST) {
            return;
        }
        try {
            $status = $e->isDurable() ? self::STATUS_REFUSED : self::STATUS_UNREACHABLE;
            // `retry_after` AVANT `failures` : MySQL évalue les affectations d'un
            // UPDATE de gauche à droite, avec les valeurs DÉJÀ modifiées — dans
            // l'autre ordre, le seuil serait atteint un échec trop tôt.
            Database::getInstance()->prepare(
                'UPDATE knowledge_base_remotes
                    SET retry_after = IF(failures + 1 >= ?, NOW() + INTERVAL ? SECOND, NULL),
                        failures = failures + 1,
                        status = ?, status_error = ?, checked_at = NOW()
                  WHERE kb_id = ?'
            )->execute([
                self::BREAKER_THRESHOLD,
                self::BREAKER_PAUSE_SEC,
                $status,
                mb_substr($e->getMessage(), 0, 500),
                $kbId,
            ]);
        } catch (\Throwable $dbError) {
            error_log('[MyBrain] état de la base ' . $kbId . ' : ' . $dbError->getMessage());
        }
    }
}
