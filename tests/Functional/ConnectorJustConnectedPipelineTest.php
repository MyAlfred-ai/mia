<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Connecteur relié = connecteur allumé, sur base réelle.
 *
 * Ce que ces tests protègent : le drapeau posé par la connexion n'est rendu
 * qu'UNE fois — sinon chaque affichage du chat rallumerait un connecteur que
 * la personne vient d'éteindre —, une reconnexion le repose, et ni un
 * rafraîchissement de jeton ni une connexion révoquée ne l'allument.
 */
final class ConnectorJustConnectedPipelineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
    }

    private function relier(int $userId, string $provider): void
    {
        UserDriveToken::saveConnection($userId, 'refresh', 'access', 3600, 'scope', 'personne@example.test', $provider);
    }

    public function testUneConnexionEstRendueUneSeuleFois(): void
    {
        $this->relier(1, UserDriveToken::PROVIDER_GMAIL);
        $this->relier(1, UserDriveToken::PROVIDER);

        $this->assertSame(['gmail', 'google_drive'], UserDriveToken::takeChatEnablePending(1));
        $this->assertSame([], UserDriveToken::takeChatEnablePending(1),
            'Lu une fois : l\'affichage suivant ne rallume pas ce que la personne a éteint.');
    }

    public function testUneReconnexionRallume(): void
    {
        $this->relier(1, UserDriveToken::PROVIDER_GMAIL);
        UserDriveToken::takeChatEnablePending(1);

        $this->relier(1, UserDriveToken::PROVIDER_GMAIL);
        $this->assertSame(['gmail'], UserDriveToken::takeChatEnablePending(1));
    }

    public function testUnRafraichissementDeJetonNAllumeRien(): void
    {
        $this->relier(1, UserDriveToken::PROVIDER_SLACK);
        UserDriveToken::takeChatEnablePending(1);

        UserDriveToken::updateAccessToken(1, 'nouveau', 3600, UserDriveToken::PROVIDER_SLACK);
        UserDriveToken::updateScope(1, 'autre scope', UserDriveToken::PROVIDER_SLACK);
        UserDriveToken::updateRefreshToken(1, 'roulant', UserDriveToken::PROVIDER_SLACK);
        $this->assertSame([], UserDriveToken::takeChatEnablePending(1));
    }

    public function testUneConnexionRevoqueeEntreTempsNEstPasRendueEtSonDrapeauTombe(): void
    {
        $this->relier(1, UserDriveToken::PROVIDER_OUTLOOK);
        UserDriveToken::markRevoked(1, UserDriveToken::PROVIDER_OUTLOOK);

        $this->assertSame([], UserDriveToken::takeChatEnablePending(1));
        $reste = (int)$this->db()->query(
            'SELECT COUNT(*) FROM user_drive_tokens WHERE chat_enable_pending = 1'
        )->fetchColumn();
        $this->assertSame(0, $reste);
    }

    public function testChaqueCompteLitSesPropresConnexions(): void
    {
        $this->relier(1, UserDriveToken::PROVIDER_GMAIL);
        $this->relier(2, UserDriveToken::PROVIDER_TASKS);

        $this->assertSame(['google_tasks'], UserDriveToken::takeChatEnablePending(2));
        $this->assertSame(['gmail'], UserDriveToken::takeChatEnablePending(1));
    }
}
