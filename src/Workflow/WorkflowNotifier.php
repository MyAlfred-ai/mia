<?php

declare(strict_types=1);

/**
 * Prévenir la personne qu'une exécution de workflow a besoin d'elle ou a
 * abouti — sur ses appareils inscrits (WebPush, comme les tâches
 * programmées). Le NOM du workflow et ce qui se passe, JAMAIS le contenu des
 * pièces (un écran verrouillé se lit par-dessus l'épaule). Un toucher ouvre
 * la page de l'exécution.
 *
 * Best-effort : ne lève jamais, une requête quand personne n'est inscrit.
 */
final class WorkflowNotifier
{
    public const GATE    = 'gate';
    public const CARDS   = 'cards';
    public const DONE    = 'done';
    public const FAILED  = 'failed';
    public const PAUSED  = 'paused';
    public const STOPPED = 'stopped';
    /** Brique « Arrêter le workflow » réglée sur « Terminée ». */
    public const ENDED   = 'ended';
    /** Brique « Me prévenir » : son message, tel quel. */
    public const NOTE    = 'note';
    /** Une surveillance (boîte mail, dossier) ne peut plus être faite. */
    public const WATCH   = 'watch';

    public static function tag(int $runId): string
    {
        return 'mia-workflow-' . $runId;
    }

    /** @param bool $force prévenir même si le workflow ne le demande pas (brique « Me prévenir ») */
    public static function notify(array $run, string $event, string $detail = '', int $count = 0, bool $force = false): void
    {
        try {
            if (!$force && empty($run['definition']['notify'])) {
                return;
            }
            $userId = (int)$run['user_id'];
            $devices = PushSubscription::forUser($userId);
            if ($devices === []) {
                return;
            }
            $user = User::findById($userId);
            $workflow = Workflow::findById((int)$run['workflow_id']);
            if (!$user || empty($user['is_active']) || $workflow === null) {
                return;
            }
            $locale = I18n::locale();
            I18n::use(I18n::forUser($user));
            try {
                $message = self::message((string)$workflow['name'], (int)$run['id'], $event, $detail, $count);
            } finally {
                I18n::use($locale);
            }
            if ($message !== null) {
                PushSubscription::recordResults(WebPush::send($devices, $message, self::tag((int)$run['id'])));
            }
        } catch (\Throwable $e) {
            error_log('[Push] notification du workflow (exécution #' . (int)($run['id'] ?? 0) . ') : ' . $e->getMessage());
        }
    }

    /**
     * Ce que dit la notification, ou null. Pure, hors traduction.
     *
     * @return array{title: string, body: string, url: string, tag: string}|null
     */
    public static function message(string $name, int $runId, string $event, string $detail, int $count = 0): ?array
    {
        $body = match ($event) {
            self::GATE    => t('Votre relecture est attendue : {titre}.', ['titre' => $detail]),
            self::CARDS   => I18n::plural(max(1, $count), '{n} action attend votre validation.', '{n} actions attendent votre validation.'),
            self::DONE    => t('Le workflow est terminé : le livrable est prêt.'),
            self::FAILED  => t('Le workflow s’est arrêté sur une erreur. Vous pouvez le reprendre.'),
            self::PAUSED  => t('Le workflow est en pause (quota ou budget atteint).'),
            self::STOPPED => $detail !== ''
                ? t('Le workflow s’est arrêté comme prévu : « {titre} ».', ['titre' => $detail])
                : t('Le workflow s’est arrêté comme prévu.'),
            self::ENDED   => $detail !== ''
                ? t('Le workflow s’est terminé : « {titre} ».', ['titre' => $detail])
                : t('Le workflow s’est terminé, sans livrable.'),
            self::NOTE    => mb_substr($detail, 0, 240),
            self::WATCH   => t('La surveillance ne peut plus être faite : {cause}', ['cause' => $detail]),
            default       => null,
        };
        if ($body === null) {
            return null;
        }
        return ['title' => $name, 'body' => $body, 'url' => $runId > 0 ? '/workflows/runs/' . $runId : '/workflows', 'tag' => self::tag($runId)];
    }

    /**
     * Une surveillance de workflow est tombée (connexion révoquée, dossier
     * introuvable) : prévenir son propriétaire, une fois par panne.
     */
    public static function watchBroken(array $workflow, string $cause): void
    {
        try {
            $userId = (int)$workflow['user_id'];
            $devices = PushSubscription::forUser($userId);
            $user = User::findById($userId);
            if ($devices === [] || !$user || empty($user['is_active'])) {
                return;
            }
            $locale = I18n::locale();
            I18n::use(I18n::forUser($user));
            try {
                $message = self::message((string)$workflow['name'], 0, self::WATCH, $cause);
            } finally {
                I18n::use($locale);
            }
            if ($message !== null) {
                $message['url'] = '/workflows/' . (int)$workflow['id'] . '/edit';
                PushSubscription::recordResults(WebPush::send($devices, $message, 'mia-workflow-watch-' . (int)$workflow['id']));
            }
        } catch (\Throwable $e) {
            error_log('[Push] surveillance du workflow #' . (int)($workflow['id'] ?? 0) . ' : ' . $e->getMessage());
        }
    }
}
