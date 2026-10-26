<?php

declare(strict_types=1);

/**
 * Prévenir la personne quand une tâche programmée a quelque chose à lui dire —
 * sur ses appareils inscrits (WebPush), même MIA fermée.
 *
 * Ce que dit la notification : le NOM de la tâche et ce qui s'est passé
 * (résultat arrivé, actions à valider, échec, exécution sautée, pause),
 * JAMAIS le contenu de la réponse — l'écran verrouillé d'un téléphone se lit
 * par-dessus l'épaule. Un toucher ouvre la conversation de la tâche.
 *
 * Quand : une exécution PROGRAMMÉE seulement. « Exécuter maintenant » et
 * l'essai du formulaire sont lancés par quelqu'un qui regarde l'écran.
 */
final class TaskNotifier
{
    /** Étiquette d'une notification : la suivante de la même tâche la remplace. */
    public static function tag(int $taskId): string
    {
        return 'mia-task-' . $taskId;
    }

    /**
     * Une exécution s'est terminée ou a été écartée. Best-effort : ne lève
     * jamais, et ne coûte qu'une requête quand personne n'est inscrit.
     * `$messageId` : la réponse de l'exécution (actions exécutées sans
     * validation qui lui sont rattachées), 0 si aucune.
     */
    public static function runRecorded(int $taskId, string $status, string $reason, ?int $conversationId, bool $manual, int $messageId = 0): void
    {
        if ($manual) {
            return;
        }
        try {
            if (!ScheduledTask::isEnabled()) {
                return;
            }
            $task = ScheduledTask::findById($taskId);
            if ($task === null || empty($task['notify'])) {
                return;
            }
            $userId = (int)$task['user_id'];
            $devices = PushSubscription::forUser($userId);
            if ($devices === []) {
                return;
            }
            $user = User::findById($userId);
            if (!$user || empty($user['is_active'])) {
                return;
            }
            $pending = $conversationId !== null && $conversationId > 0
                ? (int)(DrivePendingAction::countPendingByConversation([$conversationId], $userId)[$conversationId] ?? 0)
                : 0;
            $autoDone = DrivePendingAction::countAutoExecutedForMessage($messageId, $userId);

            // Dans la langue de la personne, la locale du processus rendue
            // ensuite (le worker enchaîne les comptes).
            $locale = I18n::locale();
            I18n::use(I18n::forUser($user));
            try {
                $message = self::message($task, $status, $reason, $pending, $conversationId, $autoDone);
            } finally {
                I18n::use($locale);
            }
            if ($message === null) {
                return;
            }
            PushSubscription::recordResults(WebPush::send($devices, $message, self::tag($taskId)));
        } catch (\Throwable $e) {
            error_log('[Push] notification de la tâche #' . $taskId . ' : ' . $e->getMessage());
        }
    }

    /**
     * Ce que dit la notification, ou null s'il n'y a rien à dire (exécution
     * arrêtée, manquée, ou écartée parce que la précédente tournait encore).
     * Pure, hors traduction.
     *
     * @param array $task tâche hydratée, relue APRÈS l'issue (statut de pause à jour)
     * @param int $autoDone actions exécutées sans validation pendant l'exécution
     * @return array{title: string, body: string, url: string, tag: string}|null
     */
    public static function message(array $task, string $status, string $reason, int $pending, ?int $conversationId, int $autoDone = 0): ?array
    {
        $taskId  = (int)$task['id'];
        $taskUrl = '/settings/schedules/' . $taskId . '/edit';
        $convUrl = $conversationId !== null && $conversationId > 0 ? '/chat/' . $conversationId : $taskUrl;
        $paused  = ($task['status'] ?? '') === 'paused';

        switch ($status) {
            case ScheduledTaskRun::DONE:
                if ($autoDone > 0) {
                    // Ce qui a eu lieu en son absence d'abord : c'est ce qu'elle
                    // doit savoir sans ouvrir MIA.
                    $body = I18n::plural($autoDone, 'Résultat prêt — {n} action effectuée sans validation.', 'Résultat prêt — {n} actions effectuées sans validation.');
                    if ($pending > 0) {
                        $body .= ' ' . I18n::plural($pending, '{n} autre attend votre validation.', '{n} autres attendent votre validation.');
                    }
                } else {
                    $body = $pending > 0
                        ? I18n::plural($pending, 'Résultat prêt — {n} action attend votre validation.', 'Résultat prêt — {n} actions attendent votre validation.')
                        : t('Le résultat est arrivé.');
                }
                $url = $convUrl;
                break;
            case ScheduledTaskRun::FAILED:
                $body = $paused && (string)($task['pause_reason'] ?? '') === ScheduledTask::PAUSE_FAILURES
                    ? t('Trois échecs d’affilée : la tâche est en pause.')
                    : t('L’exécution n’a pas abouti.');
                $url = $convUrl;
                break;
            case ScheduledTaskRun::SKIPPED:
                if (in_array($reason, [CreditQuota::REASON_QUOTA, CreditQuota::REASON_BUDGET], true)) {
                    $body = t('Exécution sautée : {raison}.', ['raison' => ScheduledTask::reasonLabel($reason)]);
                } elseif ($reason === ScheduledTask::PAUSE_ASSISTANT) {
                    $body = t('Tâche mise en pause : {raison}.', ['raison' => ScheduledTask::reasonLabel($reason)]);
                } else {
                    return null;
                }
                $url = $taskUrl;
                break;
            default:
                return null;
        }

        return [
            'title' => (string)$task['name'],
            'body'  => $body,
            'url'   => $url,
            'tag'   => self::tag($taskId),
        ];
    }
}
