<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pièces jointes du chat — l'attribut `accept` du bouton « Fichiers ou photos »
 * (cf. CLAUDE.md « Pièces jointes du chat — attribut accept (Android) »).
 *
 * Le sélecteur de l'application Android (WebView Capacitor) prend le PREMIER
 * type MIME de la liste comme type de l'intent système : un type document en
 * tête n'appelle que les explorateurs de fichiers, un type image en tête fait
 * venir galerie ET explorateurs. La liste est dérivée de celle du serveur
 * (UPLOAD_ALLOWED_TYPES) par un helper pur : ces tests gardent sa forme et le
 * câblage du gabarit.
 */
final class ComposerFileInputTest extends TestCase
{
    private static function tpl(): string
    {
        return (string)file_get_contents(ROOT . '/templates/chat/index.php');
    }

    // ---- Forme du helper ----

    public function testLesImagesOuvrentLaListeParceQueAndroidLitLePremierType(): void
    {
        $accept = FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES);
        $first  = explode(',', $accept)[0];
        $this->assertStringStartsWith('image/', $first,
            'Le premier type MIME devient le type de l\'intent Android : un type document en tête '
            . 'exclut la galerie et le sélecteur de photos du choix proposé.');
    }

    public function testChaqueTypeAccepteParLeServeurEstPropose(): void
    {
        $tokens = explode(',', FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES));
        foreach (UPLOAD_ALLOWED_TYPES as $mime) {
            $this->assertContains($mime, $tokens, "$mime est accepté par le serveur mais absent du sélecteur");
        }
    }

    public function testLesTypesMimePrecedentLesExtensionsQuiLesDoublent(): void
    {
        $tokens = explode(',', FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES));
        $mimes  = array_filter($tokens, static fn(string $t): bool => !str_starts_with($t, '.'));
        $exts   = array_filter($tokens, static fn(string $t): bool => str_starts_with($t, '.'));
        $this->assertNotEmpty($exts, 'Les sélecteurs de bureau filtrent par extension : elles restent déclarées.');
        $this->assertGreaterThan(max(array_keys($mimes)), min(array_keys($exts)),
            'Types MIME d\'abord, extensions ensuite : l\'application Android lit le premier jeton.');
        foreach (['.pdf', '.txt', '.md', '.docx', '.xlsx', '.jpg', '.jpeg', '.png', '.gif', '.webp'] as $ext) {
            $this->assertContains($ext, $tokens, "$ext manque au sélecteur");
        }
    }

    public function testJamaisDeJokerImage(): void
    {
        // Sur iOS, `image/*` fait livrer les photos HEIC telles quelles au lieu
        // d'un JPEG — que le serveur refuse. Un joker glissé dans la liste
        // serveur est écarté plutôt que propagé.
        $this->assertStringNotContainsString('/*', FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES));
        $this->assertSame('image/png,.png', FileExtractor::acceptAttribute(['image/*', 'image/png']));
    }

    public function testUnTypeInconnuResteEnMimeSeulEtLesDoublonsTombent(): void
    {
        $this->assertSame('application/x-foo', FileExtractor::acceptAttribute(['application/x-foo']));
        $this->assertSame('image/jpeg,.jpg,.jpeg', FileExtractor::acceptAttribute(['image/jpeg', ' IMAGE/JPEG ']));
        $this->assertSame('', FileExtractor::acceptAttribute([]));
        // Images en tête quel que soit l'ordre d'entrée, ordre relatif conservé.
        $this->assertSame(
            'image/png,image/gif,application/pdf,text/plain,.png,.gif,.pdf,.txt',
            FileExtractor::acceptAttribute(['application/pdf', 'image/png', 'text/plain', 'image/gif'])
        );
    }

    // ---- Câblage ----

    public function testLeGabaritDuChatDeriveSonAttributDeLaListeDuServeur(): void
    {
        $tpl = self::tpl();
        $this->assertMatchesRegularExpression(
            '~<input type="file" id="fileInput" accept="<\?= htmlspecialchars\(FileExtractor::acceptAttribute\(UPLOAD_ALLOWED_TYPES\)\) \?>" multiple~',
            $tpl,
            'Le sélecteur du composer suit la liste du serveur via le helper, jamais une liste recopiée.'
        );
        $this->assertStringNotContainsString('accept=".pdf,', $tpl,
            'Une liste d\'extensions seules casse le sélecteur Android (.md inconnu, ordre non maîtrisé).');
        // La prise de photo reste un input dédié : capture + image/* (la caméra, pas la galerie).
        $this->assertStringContainsString('id="cameraInput" accept="image/*" capture="environment"', $tpl);
    }

    public function testLeGuideUtilisateurDecritLeChoixSurAndroid(): void
    {
        $doc = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/conversations/joindre-des-fichiers.md');
        $this->assertStringContainsString('Sur Android', $doc);
        $this->assertStringContainsString('galerie', $doc);
    }
}
