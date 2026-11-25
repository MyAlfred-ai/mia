<?php

declare(strict_types=1);

/**
 * Abonnements push de la boîte Outlook (déclencheur « email reçu » des
 * workflows, MailPush) : Microsoft Graph prévient MIA de chaque message
 * arrivé dans la boîte de réception.
 *
 * À PART du connecteur de messagerie (UserOutlookService), à dessein : ce
 * fichier-là ne supprime ni ne modifie rien (UserOutlookServiceTest le
 * garde) ; ici, on crée, prolonge et retire des ABONNEMENTS
 * (/subscriptions), jamais un message. Même connexion, même permission de
 * lecture (Mail.Read suffit à Graph pour s'abonner aux messages).
 */
final class UserOutlookPushService extends UserOutlookService
{
    /** Ressource suivie par l'abonnement (la boîte de réception seule). */
    public const PUSH_RESOURCE = "me/mailFolders('Inbox')/messages";

    public static function forUser(int $userId): ?self
    {
        $row = self::tokenRowForUser($userId);
        if ($row === null) {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null,
            (string)($row['scope'] ?? ''),
            (string)($row['google_email'] ?? '')
        );
    }

    /**
     * Abonnement Graph : Microsoft prévient `$url` de chaque message arrivé
     * dans la boîte de réception (notifications de base : l'identifiant seul,
     * aucun contenu). Microsoft vérifie l'adresse PENDANT cet appel (jeton de
     * validation renvoyé tel quel) ; `$clientState` signe ensuite chaque
     * notification.
     *
     * @return array{id:string,expires:?int}
     * @throws \RuntimeException message FR ; statut HTTP en code (409 : abonnement déjà là)
     */
    public function createPushSubscription(string $url, string $clientState, \DateTimeImmutable $expires): array
    {
        try {
            $res = $this->apiJson('POST', self::GRAPH_BASE . '/subscriptions', [
                'changeType' => 'created',
                'notificationUrl' => $url,
                'lifecycleNotificationUrl' => $url,
                'resource' => self::PUSH_RESOURCE,
                'expirationDateTime' => $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'clientState' => $clientState,
            ]);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage(), $this->lastHttpStatus, $e);
        }
        $ts = strtotime((string)($res['expirationDateTime'] ?? ''));
        return ['id' => (string)($res['id'] ?? ''), 'expires' => $ts === false ? null : $ts];
    }

    /**
     * Prolonge un abonnement (et le réautorise : Graph le dit, une prolongation
     * vaut réautorisation). @throws \RuntimeException statut en code (404 : disparu)
     */
    public function renewPushSubscription(string $id, \DateTimeImmutable $expires): ?int
    {
        try {
            $res = $this->apiJson('PATCH', self::GRAPH_BASE . '/subscriptions/' . rawurlencode($id), [
                'expirationDateTime' => $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage(), $this->lastHttpStatus, $e);
        }
        $ts = strtotime((string)($res['expirationDateTime'] ?? ''));
        return $ts === false ? null : $ts;
    }

    /** Supprime un abonnement ; déjà disparu (404) n'est pas une erreur. */
    public function deletePushSubscription(string $id): void
    {
        try {
            $this->apiRaw('DELETE', self::GRAPH_BASE . '/subscriptions/' . rawurlencode($id));
        } catch (\RuntimeException $e) {
            if ($this->lastHttpStatus !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Abonnements de l'application pour cette personne qui visent `$url`
     * (un abonnement resté chez Microsoft quand MIA a perdu sa trace : Graph
     * refuse le doublon en 409). @return list<string> identifiants
     */
    public function pushSubscriptionsFor(string $url): array
    {
        $res = $this->apiJson('GET', self::GRAPH_BASE . '/subscriptions');
        $ids = [];
        foreach ((array)($res['value'] ?? []) as $sub) {
            if (is_array($sub) && (string)($sub['notificationUrl'] ?? '') === $url && (string)($sub['id'] ?? '') !== '') {
                $ids[] = (string)$sub['id'];
            }
        }
        return $ids;
    }

}
