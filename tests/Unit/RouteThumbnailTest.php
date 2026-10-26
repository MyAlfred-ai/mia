<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Vignette d'itinéraire : projection et garde-fous (pur, sans base ni réseau).
 */
final class RouteThumbnailTest extends TestCase
{
    /** @return array<int, array{x: float, y: float}> points du <polyline> rendu */
    private function polylinePoints(string $svg): array
    {
        self::assertMatchesRegularExpression('/<polyline points="([^"]+)"/', $svg);
        preg_match('/<polyline points="([^"]+)"/', $svg, $m);
        $points = [];
        foreach (explode(' ', $m[1]) as $pair) {
            [$x, $y] = explode(',', $pair);
            $points[] = ['x' => (float)$x, 'y' => (float)$y];
        }
        return $points;
    }

    public function testMoinsDeDeuxEtapesGeocodeesRendNull(): void
    {
        $this->assertNull(RouteThumbnail::svg([]));
        $this->assertNull(RouteThumbnail::svg([['lat' => 45.0, 'lon' => 4.0]]));
        // Étapes présentes mais jamais géocodées (Nominatim en échec).
        $this->assertNull(RouteThumbnail::svg([
            ['name' => 'Lyon',  'lat' => null, 'lon' => null],
            ['name' => 'Paris', 'lat' => null, 'lon' => null],
        ]));
    }

    public function testCoordonneesAberrantesIgnorees(): void
    {
        $this->assertNull(RouteThumbnail::svg([
            ['lat' => 91.0,  'lon' => 4.0],   // hors domaine
            ['lat' => 45.0,  'lon' => 999.0], // hors domaine
            ['lat' => 45.5,  'lon' => 4.5],   // seule valide → moins de 2 points
        ]));
    }

    /**
     * Le facteur 180/π de la projection : sans lui, X est en degrés et Y en
     * radians, et tout trajet nord-sud s'aplatit d'un facteur ~57.
     */
    public function testLesDeuxAxesSontALaMemeEchelle(): void
    {
        // Trajet en L de 1° vers l'est puis 1° vers le nord, à l'équateur : le
        // tracé doit être aussi large que haut. Le tout est mesuré sur UN seul
        // dessin, car un tracé est toujours étiré pour remplir la vignette —
        // comparer deux dessins ne dirait rien de la projection.
        $points = $this->polylinePoints((string)RouteThumbnail::svg([
            ['lat' => 0.0, 'lon' => 0.0],
            ['lat' => 0.0, 'lon' => 1.0],
            ['lat' => 1.0, 'lon' => 1.0],
        ]));

        $largeur = max(array_column($points, 'x')) - min(array_column($points, 'x'));
        $hauteur = max(array_column($points, 'y')) - min(array_column($points, 'y'));
        $this->assertGreaterThan(0.0, $hauteur);
        $this->assertEqualsWithDelta($largeur, $hauteur, $largeur * 0.05);
    }

    /** Le nord est en haut : une étape plus septentrionale a un Y plus petit. */
    public function testLAxeNordSudEstOriente(): void
    {
        $points = $this->polylinePoints((string)RouteThumbnail::svg([
            ['lat' => 43.30, 'lon' => 5.37], // Marseille (sud)
            ['lat' => 50.63, 'lon' => 3.06], // Lille (nord)
        ]));
        $this->assertGreaterThan($points[1]['y'], $points[0]['y']);
    }

    public function testLeTraceTientDansLaBoite(): void
    {
        $svg = (string)RouteThumbnail::svg([
            ['lat' => 45.7605, 'lon' => 4.8595],
            ['lat' => 45.1885, 'lon' => 5.7245],
            ['lat' => 45.5646, 'lon' => 5.9178],
            ['lat' => 45.8992, 'lon' => 6.1294],
        ]);
        foreach ($this->polylinePoints($svg) as $p) {
            $this->assertGreaterThanOrEqual(0.0, $p['x']);
            $this->assertGreaterThanOrEqual(0.0, $p['y']);
            $this->assertLessThanOrEqual(160.0, $p['x']);
            // Le bandeau « Départ → Arrivée » occupe le bas : aucun point ne
            // doit s'y glisser, sinon une étape disparaît sous le texte.
            $this->assertLessThanOrEqual(78.0, $p['y']);
        }
        // Départ et arrivée en pastilles pleines, étapes intermédiaires creuses.
        $this->assertSame(4, substr_count($svg, '<circle'));
    }

    /** Étapes confondues : aucune division par zéro, un tracé quand même. */
    public function testEtapesConfondues(): void
    {
        $svg = RouteThumbnail::svg([
            ['lat' => 48.8566, 'lon' => 2.3522],
            ['lat' => 48.8566, 'lon' => 2.3522],
        ]);
        $this->assertIsString($svg);
        foreach ($this->polylinePoints($svg) as $p) {
            $this->assertTrue(is_finite($p['x']) && is_finite($p['y']));
        }
    }

    /** Un tracé long reste borné : SVG léger, lisible en vignette. */
    public function testNombreDePointsBorne(): void
    {
        $waypoints = [];
        for ($i = 0; $i < 60; $i++) {
            $waypoints[] = ['lat' => 45.0 + $i * 0.05, 'lon' => 4.0 + $i * 0.05];
        }
        $svg = (string)RouteThumbnail::svg($waypoints);
        $this->assertLessThanOrEqual(25, count($this->polylinePoints($svg)));
    }

    /** Jamais de notation scientifique dans les coordonnées : le SVG serait illisible. */
    public function testAucuneNotationScientifique(): void
    {
        $svg = (string)RouteThumbnail::svg([
            ['lat' => 0.0000001, 'lon' => 0.0000001],
            ['lat' => 0.0000002, 'lon' => 0.0000002],
        ]);
        preg_match('/<polyline points="([^"]+)"/', $svg, $m);
        $this->assertStringNotContainsStringIgnoringCase('e', $m[1]);
    }

    /** Câblage : la galerie sert le tracé et retombe sur la tuile d'icône sinon. */
    public function testCablageDansLaGalerie(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/creations.php');
        $this->assertStringContainsString('RouteThumbnail::svg', $tpl);
        $this->assertStringContainsString('has-route', $tpl);

        $controller = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString("'route'     => \$steps", $controller);
        $this->assertStringContainsString('routeEnds', $controller);

        // Chargée par les deux points d'entrée (web et CLI) — une liste qui
        // dérive ne casse qu'en production, dans le worker.
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $file) {
            $this->assertStringContainsString(
                'Helpers/RouteThumbnail.php',
                (string)file_get_contents(ROOT . $file),
                "RouteThumbnail absente de $file"
            );
        }
    }
}
