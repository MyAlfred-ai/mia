<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validation des claims d'un idToken Google (connexion native de l'app
 * mobile) : la sécurité de POST /auth/google-token repose entièrement sur
 * validateIdTokenClaims (audience, émetteur, expiration, email vérifié) —
 * la signature étant déjà vérifiée par l'endpoint tokeninfo de Google.
 */
final class GoogleIdTokenClaimsTest extends TestCase
{
    private const AUD_WEB = 'web-client-id.apps.googleusercontent.com';
    private const AUD_IOS = 'ios-client-id.apps.googleusercontent.com';
    private const NOW     = 1_700_000_000;

    /** Claims valides de référence (format tokeninfo : booléens en chaînes). */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub'            => '1234567890',
            'aud'            => self::AUD_WEB,
            'iss'            => 'https://accounts.google.com',
            'exp'            => (string)(self::NOW + 3600),
            'email'          => 'test@exemple.fr',
            'email_verified' => 'true',
        ], $overrides);
    }

    private function audiences(): array
    {
        return [self::AUD_WEB, self::AUD_IOS];
    }

    public function testNominalAudienceWeb(): void
    {
        // Cas Android : l'audience est le client WEB (serverClientId).
        $this->assertTrue(GoogleOAuth::validateIdTokenClaims($this->claims(), $this->audiences(), self::NOW));
    }

    public function testNominalAudienceIos(): void
    {
        $claims = $this->claims(['aud' => self::AUD_IOS]);
        $this->assertTrue(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testIssuerSansSchemaAccepte(): void
    {
        $claims = $this->claims(['iss' => 'accounts.google.com']);
        $this->assertTrue(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testAudienceInconnueRefusee(): void
    {
        $claims = $this->claims(['aud' => 'attaquant.apps.googleusercontent.com']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testAudienceAbsenteRefusee(): void
    {
        $claims = $this->claims();
        unset($claims['aud']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testListeAudiencesVideRefusee(): void
    {
        // GOOGLE_CLIENT_ID non configuré → aucune audience → tout refuser.
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($this->claims(), [], self::NOW));
    }

    public function testEmetteurInvalideRefuse(): void
    {
        $claims = $this->claims(['iss' => 'https://evil.example.com']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testTokenExpireRefuse(): void
    {
        $claims = $this->claims(['exp' => (string)(self::NOW - 10)]);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testExpirationAbsenteRefusee(): void
    {
        $claims = $this->claims();
        unset($claims['exp']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testEmailNonVerifieRefuse(): void
    {
        $claims = $this->claims(['email_verified' => 'false']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testEmailVerifiedBooleenAccepte(): void
    {
        // Certains décodeurs renvoient un vrai booléen plutôt que "true".
        $claims = $this->claims(['email_verified' => true]);
        $this->assertTrue(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }

    public function testSubAbsentRefuse(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);
        $this->assertFalse(GoogleOAuth::validateIdTokenClaims($claims, $this->audiences(), self::NOW));
    }
}
