<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * URL signées des médias générés (MediaUrl) : signature, vérification
 * (fail-closed), réécriture HTML. APP_SECRET est fixé par tests/bootstrap.php.
 */
final class MediaUrlTest extends TestCase
{
    private const IMG   = '/assets/img/generated/abcdef0123456789.png';
    private const VIDEO = '/assets/video/generated/video_20260101_000000_abcdef.mp4';

    /** Extrait e/s d'une URL signée. */
    private static function params(string $signed): array
    {
        $q = parse_url($signed, PHP_URL_QUERY) ?: '';
        parse_str($q, $out);
        return $out;
    }

    public function testSigneUnCheminDeMediaGenere(): void
    {
        $signed = MediaUrl::sign(self::IMG);
        $this->assertStringStartsWith(self::IMG . '?', $signed);

        $p = self::params($signed);
        $this->assertArrayHasKey('e', $p);
        $this->assertArrayHasKey('s', $p);
        $this->assertTrue(MediaUrl::verify(self::IMG, $p['e'], $p['s']));
    }

    public function testVerifieUneVideoSignee(): void
    {
        $p = self::params(MediaUrl::sign(self::VIDEO));
        $this->assertTrue(MediaUrl::verify(self::VIDEO, $p['e'], $p['s']));
    }

    public function testRejetteUneSignatureAlteree(): void
    {
        $p = self::params(MediaUrl::sign(self::IMG));
        $this->assertFalse(MediaUrl::verify(self::IMG, $p['e'], $p['s'] . 'x'));
        $this->assertFalse(MediaUrl::verify(self::IMG, $p['e'], substr($p['s'], 0, -1)));
    }

    public function testRejetteUnCheminDifferentAvecLaMemeSignature(): void
    {
        // Une signature émise pour un fichier ne doit pas ouvrir un autre.
        $p = self::params(MediaUrl::sign(self::IMG));
        $autre = '/assets/img/generated/0000000000000000.png';
        $this->assertFalse(MediaUrl::verify($autre, $p['e'], $p['s']));
    }

    public function testRejetteUneSignatureExpiree(): void
    {
        // TTL négatif → expiration dans le passé.
        $signed = MediaUrl::sign(self::IMG, -10);
        $p = self::params($signed);
        $this->assertFalse(MediaUrl::verify(self::IMG, $p['e'], $p['s']));
    }

    public function testRejetteUneExpirationNonNumeriqueOuAbsente(): void
    {
        $p = self::params(MediaUrl::sign(self::IMG));
        $this->assertFalse(MediaUrl::verify(self::IMG, 'bientot', $p['s']));
        $this->assertFalse(MediaUrl::verify(self::IMG, null, $p['s']));
        $this->assertFalse(MediaUrl::verify(self::IMG, $p['e'], null));
        $this->assertFalse(MediaUrl::verify(self::IMG, $p['e'], ''));
    }

    public function testExpirationDansLaFenetreTtlEstAcceptee(): void
    {
        $signed = MediaUrl::sign(self::IMG);
        $p = self::params($signed);
        $this->assertGreaterThan(time(), (int)$p['e']);
        $this->assertLessThanOrEqual(time() + MediaUrl::TTL, (int)$p['e']);
    }

    public function testNeSignePasUnCheminHorsPerimetre(): void
    {
        foreach ([
            '/assets/img/avatars/x.png',
            '/assets/css/main.css',
            '/etc/passwd',
            '/assets/img/generated/../../etc/passwd',
        ] as $chemin) {
            $this->assertSame($chemin, MediaUrl::sign($chemin), "Ne doit pas signer : $chemin");
        }
    }

    public function testNeReSignePasUnCheminDejaSigne(): void
    {
        $signed = MediaUrl::sign(self::IMG);
        $this->assertSame($signed, MediaUrl::sign($signed), 'Un chemin déjà porteur d\'une query est laissé tel quel.');
    }

    public function testSignHtmlAssetsReecritLesImagesGenerees(): void
    {
        $html = '<img src="/assets/img/generated/pic.png"><img src="/assets/img/avatars/a.png">';
        $out  = MediaUrl::signHtmlAssets($html);

        // L'image générée est signée…
        $this->assertMatchesRegularExpression('#/assets/img/generated/pic\.png\?e=\d+&s=[a-f0-9]{64}#', $out);
        // …l'avatar (hors périmètre) est intact.
        $this->assertStringContainsString('src="/assets/img/avatars/a.png"', $out);
    }

    public function testSignHtmlAssetsEstStableSurUnDocumentDejaSigne(): void
    {
        $once  = MediaUrl::signHtmlAssets('<img src="/assets/img/generated/pic.png">');
        $twice = MediaUrl::signHtmlAssets($once);
        $this->assertSame($once, $twice, 'Re-signer un HTML déjà signé ne doit rien changer.');
    }

    public function testUneSignatureValideEstBienVerifieeApresReecritureHtml(): void
    {
        $out = MediaUrl::signHtmlAssets('<img src="' . self::IMG . '">');
        preg_match('#(' . preg_quote(self::IMG, '#') . ')\?e=(\d+)&s=([a-f0-9]+)#', $out, $m);
        $this->assertNotEmpty($m, 'URL signée introuvable dans la sortie.');
        $this->assertTrue(MediaUrl::verify($m[1], $m[2], $m[3]));
    }

    public function testStripSignedAssetsRestitueLesCheminsCanoniques(): void
    {
        $signe = MediaUrl::signHtmlAssets('<img src="' . self::IMG . '">');
        $this->assertSame('<img src="' . self::IMG . '">', MediaUrl::stripSignedAssets($signe));
    }

    public function testStripSignedAssetsTolereOrigineAbsolueEtAmpEchappe(): void
    {
        // Forme telle que sérialisée par le DOM de l'éditeur (origine + &amp;).
        $p = self::params(MediaUrl::sign(self::IMG));
        $absAmp = 'https://mia.example/assets/img/generated/pic.png?e=' . $p['e'] . '&amp;s=' . $p['s'];
        $html   = '<img src="' . $absAmp . '">';
        $this->assertSame(
            '<img src="/assets/img/generated/pic.png">',
            MediaUrl::stripSignedAssets($html)
        );
    }

    public function testStripSignedAssetsLaisseIntactUnCheminNonSigne(): void
    {
        $html = '<img src="/assets/img/generated/pic.png"><img src="/assets/img/avatars/a.png">';
        $this->assertSame($html, MediaUrl::stripSignedAssets($html));
    }
}
