<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage du contrôle d'accès aux médias générés : les fichiers ne sont plus
 * servis en statique, chaque porte de sortie vers un contexte SANS cookie
 * (iframe blob, partage, export PDF) émet des URL signées, et le nommage vidéo
 * reste une capacité non devinable. Tests sur le CODE (pas d'exécution HTTP).
 */
final class MediaAccessContractTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // Interception : plus de service statique des médias générés
    // ------------------------------------------------------------------

    public function testHtaccessIntercepteLesMediasGeneresAvantLaConditionFichier(): void
    {
        $ht = self::lire('public/.htaccess');

        $posRegle = strpos($ht, 'RewriteRule ^assets/(img|video)/generated/ index.php');
        $posCond  = strpos($ht, 'RewriteCond %{REQUEST_FILENAME} !-f');

        $this->assertNotFalse($posRegle, 'La règle d\'interception des médias générés est absente du .htaccess.');
        $this->assertNotFalse($posCond, 'La condition !-f attendue est absente.');
        $this->assertLessThan(
            $posCond,
            $posRegle,
            'L\'interception doit précéder la condition !-f, sinon Apache sert le fichier en statique.'
        );
    }

    public function testLeRouteurDevNeSertPasLesMediasEnStatique(): void
    {
        $router = self::lire('router.php');
        $this->assertStringContainsString('assets/(img|video)/generated/', $router,
            'Le routeur du serveur dev doit détourner les médias générés vers index.php.');
        // La détection doit précéder le court-circuit `is_file → return false`.
        $this->assertLessThan(
            strpos($router, 'is_file($path)'),
            strpos($router, 'assets/(img|video)/generated/'),
            'La détection média doit passer avant le service statique dev.'
        );
    }

    public function testLesRoutesMediaSontDeclarees(): void
    {
        $index = self::lire('public/index.php');
        $this->assertStringContainsString("/assets/img/generated/{name}", $index);
        $this->assertStringContainsString("/assets/video/generated/{name}", $index);
        $this->assertStringContainsString("MediaController", $index);
    }

    public function testLesClassesMediaSontChargees(): void
    {
        $classes = self::lire('config/classes.php');
        $this->assertStringContainsString('/src/Helpers/MediaUrl.php', $classes);
        $this->assertStringContainsString('/src/Controllers/MediaController.php', $classes);

        $bootstrap = self::lire('tests/bootstrap.php');
        $this->assertStringContainsString('/src/Helpers/MediaUrl.php', $bootstrap,
            'MediaUrl (pur) doit aussi être chargé pour les tests.');
    }

    // ------------------------------------------------------------------
    // Contrôleur : session OU signature, jamais de redirection
    // ------------------------------------------------------------------

    public function testLeControleurExigeSessionOuSignature(): void
    {
        $ctrl = self::lire('src/Controllers/MediaController.php');
        $this->assertStringContainsString('Session::isAuthenticated()', $ctrl);
        $this->assertStringContainsString('MediaUrl::verify', $ctrl);
        // Jamais de requireAuth (qui redirige vers /login — inexploitable pour une <img>).
        $this->assertStringNotContainsString('requireAuth', $ctrl,
            'Le média ne doit pas rediriger vers /login ; il répond 403.');
        $this->assertStringContainsString('http_response_code(403)', $ctrl);
    }

    public function testLeControleurGereLeRangePourLaVideo(): void
    {
        $ctrl = self::lire('src/Controllers/MediaController.php');
        $this->assertStringContainsString('HTTP_RANGE', $ctrl);
        $this->assertStringContainsString('206', $ctrl);
        $this->assertStringContainsString('Content-Range', $ctrl);
    }

    // ------------------------------------------------------------------
    // Portes sans cookie : signature systématique
    // ------------------------------------------------------------------

    public function testLesApercusBlobSontServisSignes(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        // getMessageHtml et getMessageDeck signent le HTML hors mode ?inline.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($chat, 'MediaUrl::signHtmlAssets'),
            'getMessageHtml ET getMessageDeck doivent signer les assets de l\'aperçu.'
        );
    }

    public function testLaPageDePartageSigneLesApercus(): void
    {
        $share = self::lire('src/Controllers/ShareController.php');
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($share, 'MediaUrl::signHtmlAssets'),
            'Les cas html_page et slide_deck de /share doivent signer leurs images.'
        );
    }

    public function testLExportPdfEmbarqueLesImagesAvantLeRendu(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        // Dans exportMessagePdf, inlineLocalImages doit être appelé avant buildFromHtml
        // (Dompdf n'effectue aucune requête distante pendant le rendu).
        $pos = strpos($chat, 'public function exportMessagePdf');
        $this->assertNotFalse($pos);
        $extrait = substr($chat, $pos, 3200);
        $this->assertStringContainsString('inlineLocalImages', $extrait);
        $this->assertLessThan(
            strpos($extrait, 'buildFromHtml'),
            strpos($extrait, 'inlineLocalImages'),
            'Les images doivent être embarquées avant le rendu PDF.'
        );
    }

    public function testLeContenuHtmlEstStockeSansSignature(): void
    {
        // Persistance : le HTML édité repasse par stripSignedAssets (serveur) et
        // par relativizeGeneratedAssets (client) — jamais une signature figée.
        $chat = self::lire('src/Controllers/ChatController.php');
        $pos  = strpos($chat, 'public function updateMessageHtml');
        $this->assertNotFalse($pos);
        $extrait = substr($chat, $pos, 2500);
        $this->assertStringContainsString('MediaUrl::stripSignedAssets', $extrait,
            'updateMessageHtml doit canonicaliser le HTML avant de le stocker.');

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('function relativizeGeneratedAssets', $js);
        $this->assertMatchesRegularExpression(
            '/rel\s*=\s*relativizeGeneratedAssets\(html\)/',
            $js,
            'La sauvegarde de l\'aperçu doit relativiser (origine + query de signature).'
        );
    }

    // ------------------------------------------------------------------
    // Nommage vidéo : capacité non devinable
    // ------------------------------------------------------------------

    public function testLeNommageVideoUtilise128BitsDAlea(): void
    {
        $vg = self::lire('src/Helpers/VideoGenerator.php');
        $this->assertStringContainsString('random_bytes(16)', $vg,
            'Le nom de vidéo doit porter 128 bits d\'aléa (16 octets).');
        $this->assertStringNotContainsString('random_bytes(4)', $vg,
            'L\'ancien nommage 32 bits doit avoir disparu.');
    }

    // ------------------------------------------------------------------
    // Persistance : volume dédié aux vidéos
    // ------------------------------------------------------------------

    public function testLesVideosSontMonteesSurUnVolumeDedie(): void
    {
        foreach (['docker-compose.yml'] as $fichier) {
            $compose = self::lire($fichier);
            $this->assertStringContainsString(
                'generated_media_video:/var/www/html/public/assets/video/generated',
                $compose,
                "Volume vidéo absent de $fichier — perte à chaque redeploy."
            );
            // Déclaré dans la liste des volumes nommés.
            $this->assertMatchesRegularExpression(
                '/^\s*generated_media_video:\s*$/m',
                $compose,
                "generated_media_video non déclaré dans les volumes de $fichier."
            );
        }
    }
}
