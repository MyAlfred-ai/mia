<?php

declare(strict_types=1);

/**
 * Notifications sur cet appareil (page Tâches programmées) : inscrire le
 * navigateur, le retirer, envoyer une notification de test. Mince : la
 * validation et le protocole vivent dans WebPush, le rangement dans
 * PushSubscription.
 *
 * Réservé aux tâches programmées pour l'instant : quand elles sont coupées
 * par l'administrateur, on n'inscrit plus rien (le retrait reste possible).
 */
final class PushController
{
    /** Inscrit ce navigateur pour le compte connecté (JSON). */
    public function subscribe(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        if (!ScheduledTask::isEnabled()) {
            AjaxResponse::fail(t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }

        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        $p256dh   = trim((string)($_POST['p256dh'] ?? ''));
        $auth     = trim((string)($_POST['auth'] ?? ''));
        if (!WebPush::isAllowedEndpoint($endpoint)) {
            // L'adresse vient du navigateur : le serveur n'écrit qu'aux
            // services de notification connus (jamais au réseau interne).
            AjaxResponse::fail(t('Ce navigateur passe par un service de notification inconnu : impossible de l’inscrire.'));
        }
        if (WebPush::decodeKeys($p256dh, $auth) === null) {
            AjaxResponse::fail(t('Inscription illisible : réessayez.'));
        }
        // Une inscription faite avec une autre clé publique que celle de
        // l'instance ne recevrait jamais rien : le navigateur la renvoie.
        $key = (string)($_POST['key'] ?? '');
        if ($key !== '' && !hash_equals(WebPush::publicKey(), $key)) {
            AjaxResponse::fail(t('Inscription périmée : réactivez les notifications.'), 409);
        }

        PushSubscription::upsert(
            (int)$user['id'],
            $endpoint,
            $p256dh,
            $auth,
            PushSubscription::deviceLabel((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
        );
        AjaxResponse::ok(['devices' => self::devices((int)$user['id'])]);
    }

    /**
     * Retire ce navigateur, quel que soit le compte qui l'avait inscrit :
     * posséder l'adresse, c'est être cet appareil (déconnexion, changement de
     * compte sur un ordinateur partagé).
     */
    public function unsubscribe(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        if ($endpoint !== '') {
            PushSubscription::deleteByEndpoint($endpoint);
        }
        AjaxResponse::ok(['devices' => self::devices((int)$user['id'])]);
    }

    /** Retire un appareil de la liste (« Retirer »), le sien seulement. */
    public function remove(string $id): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        PushSubscription::deleteForUser((int)$id, (int)$user['id']);
        AjaxResponse::ok(['devices' => self::devices((int)$user['id'])]);
    }

    /** Notification de test vers TOUS les appareils du compte, issue par appareil. */
    public function test(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        if (!ScheduledTask::isEnabled()) {
            AjaxResponse::fail(t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }
        $devices = PushSubscription::forUser((int)$user['id']);
        if ($devices === []) {
            AjaxResponse::fail(t('Aucun appareil inscrit : activez d’abord les notifications.'));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $result = PushSubscription::recordResults(WebPush::send($devices, [
            'title' => (string)Settings::get('app_name', 'MIA'),
            'body'  => t('Les notifications fonctionnent : vous serez prévenu quand une tâche programmée aura un résultat.'),
            'url'   => '/settings/schedules',
            'tag'   => 'mia-test',
        ], 'mia-test'));

        $message = $result['sent'] > 0
            ? I18n::plural($result['sent'], 'Notification envoyée à {n} appareil.', 'Notification envoyée à {n} appareils.')
            : t('Aucun appareil n’a reçu la notification. Réactivez-les sur cet appareil.');
        if ($result['sent'] > 0 && ($result['failed'] + $result['removed']) > 0) {
            $message .= ' ' . I18n::plural($result['failed'] + $result['removed'], '{n} appareil n’a pas répondu.', '{n} appareils n’ont pas répondu.');
        }
        AjaxResponse::ok(['message' => $message, 'sent' => $result['sent'], 'devices' => self::devices((int)$user['id'])]);
    }

    /**
     * Appareils du compte, pour l'écran : nom, date d'ajout, dernier envoi
     * réussi — jamais l'adresse d'envoi (un secret) ni les clés.
     *
     * @return list<array{id: int, label: string, added: string, last: string, hash: string}>
     */
    public static function devices(int $userId): array
    {
        return array_map(static fn(array $row): array => [
            'id'    => (int)$row['id'],
            'label' => (string)$row['label'],
            'added' => Format::shortDate(new \DateTimeImmutable((string)$row['created_at'], new \DateTimeZone('UTC'))),
            'last'  => $row['last_success_at'] !== null
                ? Format::shortDate(new \DateTimeImmutable((string)$row['last_success_at'], new \DateTimeZone('UTC')))
                : '',
            // Empreinte : l'écran reconnaît « cet appareil » sans connaître l'adresse.
            'hash'  => PushSubscription::hash((string)$row['endpoint']),
        ], PushSubscription::forUser($userId));
    }
}
