<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Décision pure de la limitation des tentatives de connexion.
 *
 * Le code d'origine était recopié trois fois dans AuthController et faisait
 * confiance au contenu du fichier d'état : un fichier tronqué produisait un
 * `count` non entier et la comparaison de blocage devenait imprévisible.
 */
final class LoginRateLimitTest extends TestCase
{
    public function testUnEtatAbsentOuvreUneFenetreNeuve(): void
    {
        $etat = LoginRateLimit::etatApres(null, 1_000);

        $this->assertSame(0, $etat['count']);
        $this->assertSame(1_000, $etat['since']);
    }

    public function testUnEtatCorrompuRepartDUneFenetreNeuve(): void
    {
        foreach (['pas un tableau', ['count' => 'trois'], ['since' => 10], []] as $corrompu) {
            $etat = LoginRateLimit::etatApres($corrompu, 1_000);
            $this->assertSame(0, $etat['count'], 'Un état illisible ne doit jamais bloquer ni laisser passer par accident.');
        }
    }

    public function testLesTentativesSAccumulentDansLaFenetre(): void
    {
        $etat = LoginRateLimit::etatApres(['count' => 3, 'since' => 1_000], 1_000 + LoginRateLimit::FENETRE_SEC - 1);

        $this->assertSame(3, $etat['count']);
        $this->assertSame(1_000, $etat['since'], 'La fenêtre ne doit pas glisser à chaque tentative.');
    }

    public function testUneFenetreEcouleeRemetLeCompteurAZero(): void
    {
        $etat = LoginRateLimit::etatApres(['count' => 5, 'since' => 1_000], 1_000 + LoginRateLimit::FENETRE_SEC + 1);

        $this->assertSame(0, $etat['count']);
        $this->assertSame(1_000 + LoginRateLimit::FENETRE_SEC + 1, $etat['since']);
    }

    public function testLeCompartimentHistoriqueNaPasDeSuffixe(): void
    {
        // Le nom de fichier vaut md5($ip . $compartiment) : changer ce
        // compartiment remettrait à zéro, au déploiement, les compteurs des
        // adresses en cours de blocage.
        $this->assertSame('', LoginRateLimit::ADMIN_LOCAL);
    }

    public function testLesCompartimentsSontDistincts(): void
    {
        $compartiments = [
            LoginRateLimit::ADMIN_LOCAL,
            LoginRateLimit::EMAIL,
            LoginRateLimit::NATIF,
            LoginRateLimit::SSO,
        ];

        $this->assertSame(
            $compartiments,
            array_values(array_unique($compartiments)),
            'Deux chemins de connexion qui partageraient un compartiment se bloqueraient mutuellement.'
        );
    }
}
