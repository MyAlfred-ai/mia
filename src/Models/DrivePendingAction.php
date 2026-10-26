<?php

declare(strict_types=1);

/**
 * Actions d'écriture Google Drive en attente d'approbation utilisateur.
 * Déposées par les handlers d'outils du chat (le LLM demande une écriture),
 * exécutées uniquement après clic « Approuver » sur la carte de confirmation.
 * Une action expire au bout de TTL_MINUTES si personne ne tranche — ou à son
 * `expires_at` propre quand elle en porte un (carte déposée par une tâche
 * programmée, qui attend le retour de la personne : cf. extend()).
 */
class DrivePendingAction
{
    /** Durée de validité d'une action en attente (minutes). */
    public const TTL_MINUTES = 30;

    /** Dépose une action en attente, renvoie son id. */
    public static function create(int $userId, int $conversationId, string $tool, array $args, array $preview): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO drive_pending_actions (user_id, conversation_id, tool, args_json, preview_json)
             VALUES (?,?,?,?,?)'
        )->execute([
            $userId,
            $conversationId,
            $tool,
            json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($preview, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Réserve une action pour traitement (approbation OU annulation) :
     * UPDATE atomique pending → processing, filtré user/conversation/TTL.
     * Null si introuvable, déjà traitée, expirée ou pas à cet utilisateur —
     * un double-clic ou un POST forgé ne passe pas.
     */
    public static function claim(int $id, int $userId, int $conversationId): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'UPDATE drive_pending_actions
             SET status = \'processing\'
             WHERE id = ? AND user_id = ? AND conversation_id = ? AND status = \'pending\'
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))'
        );
        $stmt->execute([$id, $userId, $conversationId]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $sel = $db->prepare('SELECT * FROM drive_pending_actions WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch();
        if (!$row) {
            return null;
        }
        $row['args']    = json_decode((string)$row['args_json'], true) ?: [];
        $row['preview'] = json_decode((string)$row['preview_json'], true) ?: [];
        return $row;
    }

    /**
     * Action encore EN ATTENTE (non expirée) de cet utilisateur dans cette
     * conversation, sans la réserver — pour la relire ou la réviser. Null
     * sinon : une action déjà traitée ne se modifie plus.
     */
    public static function findPending(int $id, int $userId, int $conversationId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM drive_pending_actions
             WHERE id = ? AND user_id = ? AND conversation_id = ? AND status = \'pending\'
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))'
        );
        $stmt->execute([$id, $userId, $conversationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['args']    = json_decode((string)$row['args_json'], true) ?: [];
        $row['preview'] = json_decode((string)$row['preview_json'], true) ?: [];
        return $row;
    }

    /**
     * Révision d'une action en attente (relecture d'un brouillon d'email :
     * modification à la main, réécriture par le modèle). Arguments ET aperçu
     * sont remplacés ensemble : c'est ce que « Approuver » exécutera et ce
     * qu'un rechargement de page ré-affichera. Mêmes filtres que claim() —
     * une action réservée, traitée ou expirée n'est jamais réécrite (false).
     */
    public static function updatePending(int $id, int $userId, int $conversationId, array $args, array $preview): bool
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET args_json = ?, preview_json = ?
             WHERE id = ? AND user_id = ? AND conversation_id = ? AND status = \'pending\'
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))'
        );
        $stmt->execute([
            json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($preview, JSON_UNESCAPED_UNICODE) ?: '{}',
            $id,
            $userId,
            $conversationId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Rend une action réservée à l'attente (processing → pending) : une
     * décision refusée AVANT toute exécution (envoi demandé sans le droit
     * d'envoi) ne doit pas consommer l'action — la carte reste ouverte et
     * l'utilisateur peut encore choisir le brouillon.
     */
    public static function release(int $id): void
    {
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET status = \'pending\' WHERE id = ? AND status = \'processing\''
        )->execute([$id]);
    }

    /** Issues d'une action exécutée qui méritent d'être distinguées (migration 092). */
    public const OUTCOME_SENT  = 'sent';
    public const OUTCOME_DRAFT = 'draft';

    /**
     * Statut final d'une action réservée (executed | cancelled | failed).
     *
     * `$outcome` : ce que l'exécution a réellement fait quand le statut ne le
     * dit pas — un email ENVOYÉ ou seulement rangé en BROUILLON. `$url` : le
     * lien vers ce qu'elle a produit (http(s) seulement, sinon ignoré). Les
     * deux se relisent dans l'historique d'un workflow.
     */
    public static function resolve(int $id, string $status, ?string $error = null, ?string $outcome = null, ?string $url = null): void
    {
        $outcome = in_array($outcome, [self::OUTCOME_SENT, self::OUTCOME_DRAFT], true) ? $outcome : null;
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET status = ?, error = ?, outcome = ?, result_url = ?, resolved_at = NOW() WHERE id = ?'
        )->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $outcome, self::resultUrl($url), $id]);
    }

    /** Lien de résultat gardé : http(s), une ligne, borné ; sinon null. Pure. */
    public static function resultUrl(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 1000 || preg_match('#^https?://[^\s<>"\']+$#i', $url) !== 1) {
            return null;
        }
        return $url;
    }

    /**
     * Action exécutée par une tâche programmée « sans validation » : aucune
     * carte, personne n'a décidé. Elle reste dans la file (même chemin
     * d'exécution, même historique) mais sort des statistiques des cartes
     * (AgentTelemetry::cards).
     */
    public static function markAutoExecuted(int $id): void
    {
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET auto_executed = 1 WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Clôt en échec une action restée en attente ou réservée — une exécution
     * sans validation interrompue, ou rendue à l'attente sans suite : sans
     * cette clôture, elle reparaîtrait en carte au rechargement. Une action
     * déjà tranchée n'est jamais réécrite.
     */
    public static function abandon(int $id, string $error): void
    {
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET status = \'failed\', error = ?, resolved_at = NOW()
             WHERE id = ? AND status IN (\'pending\', \'processing\')'
        )->execute([mb_substr($error, 0, 500), $id]);
    }

    /** Une action encore en attente écartée sans être exécutée (relecture groupée d'un workflow). */
    public static function cancelPending(int $id, string $note): void
    {
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET status = \'cancelled\', error = ?, resolved_at = NOW()
             WHERE id = ? AND status = \'pending\''
        )->execute([mb_substr($note, 0, 500), $id]);
    }

    /**
     * Actions exécutées sans validation et rattachées à une réponse (une
     * exécution de tâche programmée) : « 3 actions effectuées » dans la
     * notification.
     */
    public static function countAutoExecutedForMessage(int $messageId, int $userId): int
    {
        if ($messageId <= 0) {
            return 0;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM drive_pending_actions
             WHERE message_id = ? AND user_id = ? AND auto_executed = 1 AND status = \'executed\''
        );
        $stmt->execute([$messageId, $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Rattache les actions déposées pendant une réponse à cette réponse, une
     * fois persistée (la carte naît pendant le flux, le message après) : au
     * rechargement, la carte revient sous son message. Ne touche que les
     * actions de cet utilisateur encore sans message.
     *
     * @param list<int> $ids
     */
    public static function attachToMessage(array $ids, int $messageId, int $userId): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if ($ids === [] || $messageId <= 0) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET message_id = ?
             WHERE id IN (' . $marks . ') AND user_id = ? AND message_id IS NULL'
        )->execute(array_merge([$messageId], $ids, [$userId]));
    }

    /**
     * Allonge la vie d'une action en attente de CET utilisateur : carte
     * déposée par une tâche programmée, que personne n'est là pour trancher
     * dans les 30 minutes. Heure MySQL des deux côtés (NOW()).
     */
    public static function extend(int $id, int $userId, int $minutes): void
    {
        Database::getInstance()->prepare(
            'UPDATE drive_pending_actions SET expires_at = NOW() + INTERVAL ' . max(1, $minutes) . ' MINUTE
             WHERE id = ? AND user_id = ? AND status = \'pending\''
        )->execute([$id, $userId]);
    }

    /**
     * Cartes encore en attente (non expirées) rattachées à une réponse — une
     * étape de workflow attend qu'elles soient toutes tranchées.
     *
     * @return list<int>
     */
    public static function pendingIdsForMessage(int $messageId, int $userId): array
    {
        if ($messageId <= 0) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM drive_pending_actions
             WHERE message_id = ? AND user_id = ? AND status IN (\'pending\', \'processing\')
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))
             ORDER BY id'
        );
        $stmt->execute([$messageId, $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Cartes rattachées à une réponse dont la durée de vie est passée sans
     * décision : une étape de workflow qui les attendait ne repartira pas.
     *
     * @return list<int>
     */
    public static function expiredIdsForMessage(int $messageId, int $userId): array
    {
        if ($messageId <= 0) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM drive_pending_actions
             WHERE message_id = ? AND user_id = ? AND status = \'pending\'
               AND NOT (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))
             ORDER BY id'
        );
        $stmt->execute([$messageId, $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Statut EFFECTIF d'une carte (« expired » pour une carte en attente dont
     * la durée de vie est passée) et la réponse à laquelle elle est rattachée.
     *
     * @return array{status: string, message_id: ?int, tool: string}|null
     */
    public static function statusOf(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT status, message_id, tool,
                    (status = \'pending\' AND NOT (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))) AS expired
             FROM drive_pending_actions WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'status'     => (int)$row['expired'] === 1 ? 'expired' : (string)$row['status'],
            'message_id' => $row['message_id'] !== null ? (int)$row['message_id'] : null,
            'tool'       => (string)$row['tool'],
        ];
    }

    /**
     * Action encore en attente de cet utilisateur, QUELLE QUE SOIT sa
     * conversation (« Ajuster » ouvre le formulaire depuis une carte : la page
     * des paramètres ne connaît pas la conversation). Null sinon.
     */
    public static function findPendingForUser(int $id, int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT conversation_id FROM drive_pending_actions WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $convId = $stmt->fetchColumn();
        return $convId !== false ? self::findPending($id, $userId, (int)$convId) : null;
    }

    /**
     * Nombre d'actions en attente par conversation (liste des tâches
     * programmées : « 2 actions à approuver »). Une requête pour toute la liste.
     *
     * @param list<int> $conversationIds
     * @return array<int, int> identifiant de conversation → nombre
     */
    public static function countPendingByConversation(array $conversationIds, int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds), static fn(int $v): bool => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            'SELECT conversation_id, COUNT(*) FROM drive_pending_actions
             WHERE conversation_id IN (' . $marks . ') AND user_id = ? AND status = \'pending\'
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))
             GROUP BY conversation_id'
        );
        $stmt->execute(array_merge($ids, [$userId]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as [$convId, $count]) {
            $out[(int)$convId] = (int)$count;
        }
        return $out;
    }

    /**
     * Actions encore en attente (non expirées) d'une conversation — pour
     * re-afficher les cartes d'approbation au rechargement de la page, chacune
     * sous la réponse qui l'a proposée (`message_id`, null = en fin de fil).
     */
    public static function pendingForConversation(int $conversationId, int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, tool, preview_json, message_id FROM drive_pending_actions
             WHERE conversation_id = ? AND user_id = ? AND status = \'pending\'
               AND (expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE))
             ORDER BY id'
        );
        $stmt->execute([$conversationId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'         => (int)$row['id'],
                'tool'       => (string)$row['tool'],
                'preview'    => json_decode((string)$row['preview_json'], true) ?: [],
                'message_id' => $row['message_id'] !== null ? (int)$row['message_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Dernière PROPOSITION encore en attente d'un outil dans une conversation
     * (assistant ou tâche préparés par MIA), arguments compris : c'est sur
     * elle que portent les retouches demandées dans le chat (« plutôt à
     * 9 h »). Null s'il n'y en a pas.
     */
    public static function latestPendingForTool(int $conversationId, int $userId, string $tool): ?array
    {
        $latest = null;
        foreach (self::pendingForConversation($conversationId, $userId) as $row) {
            if ($row['tool'] === $tool) {
                $latest = $row;
            }
        }
        return $latest !== null ? self::findPending((int)$latest['id'], $userId, $conversationId) : null;
    }

    /** Une proposition de cet outil attend-elle dans la conversation ? */
    public static function hasPendingForTool(int $conversationId, int $userId, string $tool): bool
    {
        foreach (self::pendingForConversation($conversationId, $userId) as $row) {
            if ($row['tool'] === $tool) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ferme une proposition REMPLACÉE par sa version révisée : réservée puis
     * annulée, jamais exécutable. Faux si elle n'attendait plus (approuvée,
     * annulée ou expirée entre-temps).
     */
    public static function supersede(int $id, int $userId, int $conversationId): bool
    {
        if (self::claim($id, $userId, $conversationId) === null) {
            return false;
        }
        self::resolve($id, 'cancelled', 'Remplacée par une proposition révisée.');
        return true;
    }

    /**
     * Toutes les cartes d'une conversation, quel que soit leur sort — le
     * journal d'une exécution de workflow dit ce qu'il est advenu de chacune
     * (WorkflowJournal::cardLine). `expired` : en attente mais plus valable.
     *
     * @return array<int, array{id:int, tool:string, status:string, expired:bool, auto:bool, preview:array, error:string, message_id:?int}>
     */
    public static function journalForConversation(int $convId, int $userId): array
    {
        // COALESCE et non OR : sans durée propre, « expires_at >= NOW() » vaut
        // NULL, et NOT (NULL OR faux) n'est pas vrai — la carte ne serait
        // jamais dite expirée.
        $stmt = Database::getInstance()->prepare(
            'SELECT id, tool, status, args_json, preview_json, error, message_id, auto_executed, outcome, result_url,
                    (status IN (\'pending\', \'processing\')
                     AND NOT COALESCE(expires_at >= NOW(), created_at >= NOW() - INTERVAL ' . self::TTL_MINUTES . ' MINUTE)) AS expired
             FROM drive_pending_actions
             WHERE conversation_id = ? AND user_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$convId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['id']] = [
                'id'         => (int)$row['id'],
                'tool'       => (string)$row['tool'],
                'status'     => (string)$row['status'],
                'expired'    => (bool)$row['expired'],
                'auto'       => (bool)$row['auto_executed'],
                // Les arguments portent le contenu ENTIER (corps d'un email,
                // texte d'un message) ; l'aperçu n'en garde qu'un extrait.
                'args'       => json_decode((string)$row['args_json'], true) ?: [],
                'outcome'    => $row['outcome'] !== null ? (string)$row['outcome'] : null,
                'url'        => self::resultUrl($row['result_url'] !== null ? (string)$row['result_url'] : null),
                'preview'    => json_decode((string)$row['preview_json'], true) ?: [],
                'error'      => (string)($row['error'] ?? ''),
                'message_id' => $row['message_id'] !== null ? (int)$row['message_id'] : null,
            ];
        }
        return $out;
    }
}
