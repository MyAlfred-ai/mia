<?php

declare(strict_types=1);

/**
 * Notifications des boîtes mail suivies en push (MailPush) :
 *  - POST /hooks/mail/outlook          Microsoft Graph (validation de
 *                                      l'adresse, notifications, cycle de vie) ;
 *  - POST /hooks/mail/gmail/<jeton>    abonnement push Google Pub/Sub.
 *
 * Traitées AVANT la session et le contrôle CSRF (public/index.php). La
 * réponse part TOUT DE SUITE — Graph exige une réponse sous 3 secondes,
 * faute de quoi il ralentit puis jette les notifications —, la suite
 * (réveiller les vérifications) se fait après. Une notification ne porte
 * rien d'utile en soi : elle n'est qu'un signal, la boîte est relue avec le
 * compte de la personne.
 */
final class MailPushHookController
{
    public static function outlook(): void
    {
        $res = MailPush::handleOutlook($_GET, self::body());
        self::reply($res['status'], $res['type'], $res['body']);
        foreach ($res['renew'] as $uid) {
            MailPush::syncSoon((int)$uid);
        }
        foreach ($res['wake'] as $uid) {
            MailPush::wake((int)$uid, 'outlook');
        }
    }

    public static function gmail(string $token): void
    {
        $res = MailPush::handleGmail($token, self::body());
        self::reply($res['status'], 'text/plain; charset=utf-8', '');
        foreach ($res['wake'] as $uid) {
            MailPush::wake((int)$uid, 'gmail');
        }
    }

    private static function body(): string
    {
        return (string)file_get_contents('php://input', false, null, 0, MailPush::BODY_MAX);
    }

    /** Répond et referme la connexion avant de continuer. */
    private static function reply(int $status, string $type, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $type);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
        echo $body;
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @ignore_user_abort(true);
    }
}
