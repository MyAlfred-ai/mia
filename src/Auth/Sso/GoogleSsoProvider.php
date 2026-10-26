<?php

declare(strict_types=1);

/**
 * Connexion Google — enveloppe de GoogleOAuth, qui reste la SEULE
 * implémentation du protocole côté Google.
 *
 * GoogleOAuth n'est pas absorbé ici parce qu'il porte aussi `verifyIdToken()`,
 * utilisé par le chemin natif de l'app mobile (POST /auth/google-token), qui
 * n'a ni state ni redirection : deux flux, une seule mécanique de jeton.
 */
final class GoogleSsoProvider implements SsoProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function key(): string
    {
        return SsoRegistry::GOOGLE;
    }

    public function label(): string
    {
        return 'Google';
    }

    public function isConfigured(): bool
    {
        return SsoRegistry::isConfigured(SsoRegistry::GOOGLE);
    }

    public function authUrl(): string
    {
        return GoogleOAuth::getAuthUrl();
    }

    public function handleCallback(string $code, string $state): ?array
    {
        $profil = GoogleOAuth::handleCallback($code, $state);
        if (!is_array($profil) || (string)($profil['id'] ?? '') === '') {
            return null;
        }

        return [
            'provider'       => SsoRegistry::GOOGLE,
            'subject'        => (string)$profil['id'],
            'email'          => (string)($profil['email'] ?? ''),
            // userinfo ne renvoie `verified_email` que sur certains comptes ;
            // absent, on considère l'adresse attestée : Google n'expose une
            // adresse @gmail ou d'un domaine Workspace qu'après vérification.
            'email_verified' => ($profil['verified_email'] ?? true) !== false,
            'name'           => (string)($profil['name'] ?? ($profil['email'] ?? '')),
            'picture'        => (string)($profil['picture'] ?? ''),
        ];
    }

    /**
     * Test des identifiants sans navigateur : on demande un échange de code
     * avec un code volontairement faux. Google distingue les deux échecs, et
     * c'est exactement ce qui nous intéresse — `invalid_client` accuse les
     * identifiants, `invalid_grant` n'accuse que le code, donc les
     * identifiants sont bons.
     */
    public function testCredentials(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('Renseignez l\'ID client et le secret client Google ci-dessus (ou enregistrez-les d\'abord).')];
        }

        $reponse = $this->httpPost(self::TOKEN_URL, [
            'code'          => 'mia-verification-' . bin2hex(random_bytes(8)),
            'client_id'     => SsoRegistry::googleClientId(),
            'client_secret' => SsoRegistry::googleClientSecret(),
            'redirect_uri'  => SsoRegistry::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if ($reponse === null) {
            return ['ok' => false, 'error' => t('Google injoignable depuis ce serveur — vérifiez son accès Internet sortant.')];
        }

        return self::credentialVerdict($reponse['status'], $reponse['body']);
    }

    /**
     * Verdict à partir de la réponse du endpoint token (pur, testable).
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public static function credentialVerdict(int $status, string $body): array
    {
        $data   = json_decode($body, true);
        $erreur = is_array($data) ? (string)($data['error'] ?? '') : '';

        // Le code bidon est refusé : les identifiants, eux, ont été acceptés.
        if ($erreur === 'invalid_grant') {
            return ['ok' => true, 'detail' => t('ID client et secret acceptés par Google.')];
        }
        if ($erreur === 'invalid_client' || $erreur === 'unauthorized_client') {
            return [
                'ok'    => false,
                'error' => t('ID client ou secret refusé par Google — vérifiez les deux valeurs dans la console Google Cloud (Identifiants → ID client OAuth 2.0).'),
            ];
        }
        if ($erreur === 'redirect_uri_mismatch') {
            return [
                'ok'    => false,
                'error' => t('URI de redirection non déclarée pour ce client — ajoutez celle affichée ci-dessus dans la console Google Cloud (« URI de redirection autorisés »).'),
            ];
        }
        // Un 200 est impossible avec un code bidon : réponse inattendue.
        if ($status < 400) {
            return ['ok' => false, 'error' => t('Réponse inattendue de Google (HTTP {status}).', ['status' => $status])];
        }
        return [
            'ok'    => false,
            'error' => $erreur !== ''
                ? t('Google a refusé la vérification (HTTP {status}) : {erreur}.', ['status' => $status, 'erreur' => $erreur])
                : t('Google a refusé la vérification (HTTP {status}).', ['status' => $status]),
        ];
    }

    /** @return array{status:int, body:string}|null */
    private function httpPost(string $url, array $data): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($data),
            'timeout'       => 20,
            'ignore_errors' => true, // lire le corps d'erreur, pas seulement le statut
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $entete) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $entete, $m)) {
                $status = (int)$m[1];
            }
        }
        return ['status' => $status, 'body' => (string)$body];
    }
}
