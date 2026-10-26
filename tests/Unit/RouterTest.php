<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Router maison : extraction des paramètres nommés, normalisation du slash final,
 * isolation par méthode, ignore la query string.
 */
final class RouterTest extends TestCase
{
    public function testNamedParamIsCaptured(): void
    {
        $router = new Router();
        $captured = null;
        $router->get('/chat/{id}', function (string $id) use (&$captured): void {
            $captured = $id;
        });

        $router->dispatch('GET', '/chat/42');
        $this->assertSame('42', $captured);
    }

    public function testTrailingSlashIsNormalised(): void
    {
        $router = new Router();
        $captured = null;
        $router->get('/chat/{id}', function (string $id) use (&$captured): void {
            $captured = $id;
        });

        $router->dispatch('GET', '/chat/7/');
        $this->assertSame('7', $captured);
    }

    public function testQueryStringIsIgnored(): void
    {
        $router = new Router();
        $captured = null;
        $router->get('/chat/{id}', function (string $id) use (&$captured): void {
            $captured = $id;
        });

        $router->dispatch('GET', '/chat/9?ref=mail&x=1');
        $this->assertSame('9', $captured);
    }

    public function testMethodIsolation(): void
    {
        $router = new Router();
        $getHit = false;
        $postHit = false;
        $router->get('/send', function () use (&$getHit): void {
            $getHit = true;
        });
        $router->post('/send', function () use (&$postHit): void {
            $postHit = true;
        });

        $router->dispatch('GET', '/send');

        $this->assertTrue($getHit, 'le handler GET doit répondre à un GET');
        $this->assertFalse($postHit, 'le handler POST ne doit pas répondre à un GET');
    }
}
