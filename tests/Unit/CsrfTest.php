<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Barrière CSRF : distinction jeton ABSENT / jeton PÉRIMÉ (diagnostic du
 * 403 sur la connexion Google native de l'app mobile) et acceptation des
 * deux transports (champ POST des formulaires, header des requêtes fetch).
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['csrf' => str_repeat('a', 64)];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testSubmittedVideQuandAucunJeton(): void
    {
        $this->assertSame('', Csrf::submitted());
        $this->assertFalse(Csrf::verify());
    }

    public function testJetonDeFormulaire(): void
    {
        $_POST['csrf_token'] = str_repeat('a', 64);
        $this->assertSame(str_repeat('a', 64), Csrf::submitted());
        $this->assertTrue(Csrf::verify());
    }

    public function testJetonDeHeaderFetch(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
        $this->assertTrue(Csrf::verify());
    }

    /** Page chargée avant une expiration / un redéploiement : jeton présent mais mort. */
    public function testJetonPerimeRefuseMaisVisiblePourLeLog(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
        $this->assertNotSame('', Csrf::submitted());
        $this->assertFalse(Csrf::verify());
    }

    /** Session vidée côté serveur : un nouveau jeton est semé, l'ancien ne vaut plus rien. */
    public function testSessionVideSemeUnJetonEtRefuseLAncien(): void
    {
        $_SESSION = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
        $this->assertFalse(Csrf::verify());
        $this->assertNotEmpty($_SESSION['csrf'] ?? '');
    }
}
