<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verdict du bouton « Tester les identifiants » côté Google.
 *
 * Le test sollicite un échange de code avec un code volontairement faux : si
 * Google prend la peine de dire que le CODE est invalide, c'est qu'il a déjà
 * accepté le client. Confondre les deux erreurs afficherait « identifiants
 * invalides » sur une configuration parfaitement bonne.
 */
final class GoogleSsoProviderTest extends TestCase
{
    public function testUnCodeRefuseSignifieDesIdentifiantsValides(): void
    {
        $verdict = GoogleSsoProvider::credentialVerdict(400, '{"error":"invalid_grant"}');

        $this->assertTrue($verdict['ok']);
        $this->assertStringContainsString('acceptés', $verdict['detail']);
    }

    public function testUnClientRefuseEstUnEchec(): void
    {
        foreach (['invalid_client', 'unauthorized_client'] as $erreur) {
            $verdict = GoogleSsoProvider::credentialVerdict(401, '{"error":"' . $erreur . '"}');

            $this->assertFalse($verdict['ok']);
            $this->assertStringContainsString('console', $verdict['error']);
        }
    }

    public function testUneUriDeRedirectionNonDeclareeEstNommee(): void
    {
        // C'est la panne la plus fréquente en pratique : la nommer ici évite
        // de la découvrir sur le premier utilisateur.
        $verdict = GoogleSsoProvider::credentialVerdict(400, '{"error":"redirect_uri_mismatch"}');

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('redirection', $verdict['error']);
    }

    public function testUneReponseInattendueNestPasPrisePourUnSucces(): void
    {
        // Un 200 est impossible avec un code bidon.
        $this->assertFalse(GoogleSsoProvider::credentialVerdict(200, '{"access_token":"…"}')['ok']);
        $this->assertFalse(GoogleSsoProvider::credentialVerdict(500, 'Bad Gateway')['ok']);
    }
}
