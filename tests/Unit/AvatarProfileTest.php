<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Photo de profil (Mon profil) : un fichier choisi OU une adresse collée, deux
 * gestes équivalents qui aboutissent à une copie locale.
 *
 * Le défaut corrigé : le champ « Ou URL d'image » était de type `url` et
 * pré-rempli avec l'avatar enregistré — un chemin LOCAL qu'un champ `url`
 * déclare invalide. L'enregistrement automatique refusait alors tout envoi,
 * fichier compris : pour changer de photo, il fallait d'abord vider le champ.
 */
final class AvatarProfileTest extends TestCase
{
    /** PNG 1×1 valide. */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $dir;
    private string|false $journal = false;

    protected function setUp(): void
    {
        I18n::use('fr');
        // Les refus d'import écrivent une ligne de journal : pas dans la sortie des tests.
        $this->journal = ini_set('error_log', '/dev/null');
        $this->dir = sys_get_temp_dir() . '/mia-avatars-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        AvatarStorage::overrideDir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->journal !== false) {
            ini_set('error_log', $this->journal);
        }
        AvatarStorage::overrideDir(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private static function png(): string
    {
        return (string)base64_decode(self::PNG_1PX);
    }

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    // ------------------------------------------------------------------
    // Ce que demande un envoi
    // ------------------------------------------------------------------

    public function testUnChampDAdresseVideOuInchangeGardeLaPhoto(): void
    {
        $local = '/assets/img/avatars/avatar_7_abc.png';
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction([], null, $local));
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction(['avatar_url' => ''], null, $local));
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction(['avatar_url' => '  '], null, $local));
        // Ancienne page encore ouverte : elle reposte le chemin local enregistré.
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction(['avatar_url' => $local], null, $local));
        // Un chemin qui n'est pas la photo actuelle ne désigne rien à télécharger
        // (et ne s'enregistre plus tel quel : la page d'un autre, un `..`).
        $this->assertSame(
            AvatarStorage::ACTION_KEEP,
            AvatarStorage::requestedAction(['avatar_url' => '/assets/img/avatars/../../index.php'], null, $local)
        );
        // Une adresse web déjà enregistrée, renvoyée telle quelle : rien ne change.
        $web = 'https://exemple.fr/moi.png';
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction(['avatar_url' => $web], null, $web));
    }

    public function testUnFichierChoisiLEmporteSurLAdresse(): void
    {
        $ok = ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/x'];
        $this->assertSame(AvatarStorage::ACTION_UPLOAD, AvatarStorage::requestedAction(
            ['avatar_url' => 'https://exemple.fr/a.png', 'remove_avatar' => '1'],
            $ok,
            '/assets/img/avatars/avatar_7_abc.png'
        ));
        // Un téléversement refusé par PHP reste un téléversement : sa raison s'affiche.
        $this->assertSame(
            AvatarStorage::ACTION_UPLOAD,
            AvatarStorage::requestedAction([], ['error' => UPLOAD_ERR_INI_SIZE], null)
        );
        // Aucun fichier choisi : l'entrée vide envoyée par le navigateur ne compte pas.
        $this->assertSame(
            AvatarStorage::ACTION_KEEP,
            AvatarStorage::requestedAction([], ['error' => UPLOAD_ERR_NO_FILE], null)
        );
    }

    public function testUneAdresseColleeEstImporteeMemeAvecUnePhotoDejaEnregistree(): void
    {
        foreach (['/assets/img/avatars/avatar_7_abc.png', '/assets/img/avatars/google_1f2e.jpg', '', null] as $current) {
            $this->assertSame(
                AvatarStorage::ACTION_IMPORT,
                AvatarStorage::requestedAction(['avatar_url' => 'https://exemple.fr/photo.jpg'], null, $current)
            );
        }
        // Sans schéma : lue en https par WebPageReader.
        $this->assertSame(
            AvatarStorage::ACTION_IMPORT,
            AvatarStorage::requestedAction(['avatar_url' => 'exemple.fr/photo.jpg'], null, '')
        );
        // L'adresse passe avant le retrait.
        $this->assertSame(
            AvatarStorage::ACTION_IMPORT,
            AvatarStorage::requestedAction(['avatar_url' => 'https://exemple.fr/p.jpg', 'remove_avatar' => '1'], null, '')
        );
    }

    public function testLeRetraitDemandeLeDrapeau(): void
    {
        $local = '/assets/img/avatars/avatar_7_abc.png';
        $this->assertSame(AvatarStorage::ACTION_REMOVE, AvatarStorage::requestedAction(['remove_avatar' => '1'], null, $local));
        $this->assertSame(AvatarStorage::ACTION_KEEP, AvatarStorage::requestedAction(['remove_avatar' => ''], null, $local));
    }

    public function testLeChampDAdresseNAfficheJamaisUnCheminLocal(): void
    {
        $this->assertSame('', AvatarStorage::urlFieldValue('/assets/img/avatars/avatar_7_abc.png'));
        $this->assertSame('', AvatarStorage::urlFieldValue('/assets/img/avatars/google_1f2e.jpg'));
        $this->assertSame('', AvatarStorage::urlFieldValue(''));
        $this->assertSame('', AvatarStorage::urlFieldValue(null));
        $this->assertSame('https://exemple.fr/moi.png', AvatarStorage::urlFieldValue('https://exemple.fr/moi.png'));
    }

    public function testLesErreursDeTeleversementSontNommees(): void
    {
        $this->assertNull(AvatarStorage::uploadError(UPLOAD_ERR_OK));
        $this->assertStringContainsString('2 Mo', (string)AvatarStorage::uploadError(UPLOAD_ERR_INI_SIZE));
        $this->assertStringContainsString('2 Mo', (string)AvatarStorage::uploadError(UPLOAD_ERR_FORM_SIZE));
        $this->assertStringContainsString('échoué', (string)AvatarStorage::uploadError(UPLOAD_ERR_PARTIAL));
    }

    // ------------------------------------------------------------------
    // Enregistrement d'une image
    // ------------------------------------------------------------------

    public function testUneImageValideEstEnregistreeSousUnNomDeLUtilisateur(): void
    {
        $url = AvatarStorage::storeUserImage(7, self::png());
        $this->assertMatchesRegularExpression('#^/assets/img/avatars/avatar_7_[a-f0-9]{16}\.png$#', $url);
        $this->assertSame(self::png(), file_get_contents($this->dir . '/' . basename($url)));
        // Fichier choisi : protégé de l'écrasement par la photo Google à la connexion.
        $this->assertTrue(AvatarStorage::isUserUpload($url));
    }

    public function testCeQuiNEstPasUneImageEstRefuse(): void
    {
        foreach (['<?php echo 1;', "\x89PNG\r\n\x1a\nPAS UNE IMAGE", ''] as $octets) {
            try {
                AvatarStorage::storeUserImage(7, $octets);
                $this->fail('Contenu non-image accepté.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Format', $e->getMessage());
            }
        }
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testUneImageTropLourdeEstRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2 Mo');
        AvatarStorage::storeUserImage(7, self::png() . str_repeat("\0", AvatarStorage::MAX_BYTES));
    }

    public function testLesMessagesSontTraduits(): void
    {
        I18n::use('en');
        try {
            AvatarStorage::storeUserImage(7, 'texte');
            $this->fail('Contenu non-image accepté.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unsupported image format (JPEG, PNG, WebP or GIF).', $e->getMessage());
        } finally {
            I18n::use('fr');
        }
    }

    // ------------------------------------------------------------------
    // Suppression de l'ancienne photo
    // ------------------------------------------------------------------

    public function testSeuleLaPhotoChoisieParLUtilisateurEstSupprimee(): void
    {
        $mienne = AvatarStorage::storeUserImage(7, self::png());
        $autre  = AvatarStorage::storeUserImage(8, self::png());
        file_put_contents($this->dir . '/google_1f2e.png', self::png());

        $this->assertFalse(AvatarStorage::deleteUserFile($autre, 7), 'La photo d\'un autre compte reste.');
        $this->assertFalse(AvatarStorage::deleteUserFile('/assets/img/avatars/google_1f2e.png', 7));
        $this->assertFalse(AvatarStorage::deleteUserFile('/assets/img/avatars/../avatars/' . basename($mienne), 7));
        $this->assertFalse(AvatarStorage::deleteUserFile('https://exemple.fr/moi.png', 7));
        $this->assertFalse(AvatarStorage::deleteUserFile('', 7));
        $this->assertFileExists($this->dir . '/' . basename($mienne));

        $this->assertTrue(AvatarStorage::deleteUserFile($mienne, 7));
        $this->assertFileDoesNotExist($this->dir . '/' . basename($mienne));
        $this->assertFileExists($this->dir . '/' . basename($autre));
        $this->assertFileExists($this->dir . '/google_1f2e.png');
    }

    // ------------------------------------------------------------------
    // Adresse collée : téléchargée à travers les verrous de WebPageReader
    // ------------------------------------------------------------------

    /**
     * @param list<array{status:int, headers:array<string,string>, body:string, truncated?:bool}> $reponses
     * @param array<string, list<string>> $dns
     */
    private static function lecteur(array $reponses, array &$appels, array $dns = []): WebPageReader
    {
        return new WebPageReader(
            static function (array $cible, string $ip, string $accept = '') use (&$reponses, &$appels): array {
                $appels[] = ['url' => $cible['url'], 'ip' => $ip, 'accept' => $accept];
                return array_shift($reponses) + ['truncated' => false];
            },
            static fn(string $hote): array => $dns[$hote] ?? ['93.184.216.34']
        );
    }

    public function testUneAdresseDImageEstCopieeCommeUnFichierChoisi(): void
    {
        $appels = [];
        $url = AvatarStorage::importFromUrl(7, 'https://exemple.fr/moi.png', self::lecteur([
            ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => self::png()],
        ], $appels));

        $this->assertMatchesRegularExpression('#^/assets/img/avatars/avatar_7_[a-f0-9]{16}\.png$#', $url);
        $this->assertSame(self::png(), file_get_contents($this->dir . '/' . basename($url)));
        $this->assertCount(1, $appels);
        $this->assertStringContainsString('image/', $appels[0]['accept']);
    }

    public function testUneRedirectionVersLeReseauInterneEstRefusee(): void
    {
        $appels = [];
        try {
            AvatarStorage::importFromUrl(7, 'https://exemple.fr/moi.png', self::lecteur([
                ['status' => 302, 'headers' => ['location' => 'http://127.0.0.1/install.php'], 'body' => ''],
                ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => self::png()],
            ], $appels));
            $this->fail('Redirection interne suivie.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Impossible de récupérer', $e->getMessage());
        }
        $this->assertCount(1, $appels, 'Aucune requête ne part vers 127.0.0.1.');
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testUnNomQuiDesigneUnReseauInterneNEstJamaisAppele(): void
    {
        $appels = [];
        foreach (['https://intranet.exemple.fr/a.png', 'http://localhost/a.png', 'http://10.0.0.5/a.png', 'file:///etc/passwd'] as $adresse) {
            try {
                AvatarStorage::importFromUrl(7, $adresse, self::lecteur([], $appels, [
                    'intranet.exemple.fr' => ['10.1.2.3'],
                ]));
                $this->fail("Adresse acceptée : $adresse");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Impossible de récupérer', $e->getMessage());
            }
        }
        $this->assertSame([], $appels);
    }

    public function testUneAdresseQuiNeMenePasAUneImageEstRefusee(): void
    {
        $appels = [];
        try {
            AvatarStorage::importFromUrl(7, 'https://exemple.fr/profil', self::lecteur([
                ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>Bonjour</body></html>'],
            ], $appels));
            $this->fail('Page HTML acceptée comme photo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ne mène pas à une image', $e->getMessage());
        }
    }

    public function testUneImageDistanteTropLourdeEstRefusee(): void
    {
        $appels = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2 Mo');
        AvatarStorage::importFromUrl(7, 'https://exemple.fr/grande.png', self::lecteur([
            ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => self::png(), 'truncated' => true],
        ], $appels));
    }

    public function testUnePageIntrouvableEstRefusee(): void
    {
        $appels = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Impossible de récupérer');
        AvatarStorage::importFromUrl(7, 'https://exemple.fr/absente.png', self::lecteur([
            ['status' => 404, 'headers' => [], 'body' => ''],
        ], $appels));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeControleurPasseParLesReglesDeLaPhoto(): void
    {
        $src = self::lire('src/Controllers/UserSettingsController.php');
        $pos = strpos($src, 'function updateProfile(');
        $this->assertNotFalse($pos);
        $corps = substr($src, $pos, (int)strpos($src, 'function aiPreferences(') - $pos);

        foreach (['AvatarStorage::requestedAction(', 'AvatarStorage::storeUpload(', 'AvatarStorage::importFromUrl(',
                  'AvatarStorage::deleteUserFile('] as $appel) {
            $this->assertStringContainsString($appel, $corps);
        }
        // L'ancien repli enregistrait le texte du champ tel quel (chemin local
        // compris, `..` compris).
        $this->assertStringNotContainsString("\$_POST['avatar_url'] ?? \$user['avatar_url']", $corps);
        $this->assertStringNotContainsString('move_uploaded_file', $corps);
        // Une photo refusée n'empêche pas le nom et l'e-mail de partir.
        $this->assertStringContainsString("'warning' => \$avatarWarning", $corps);
        $this->assertLessThan(
            strpos($corps, 'AvatarStorage::deleteUserFile('),
            strpos($corps, 'UPDATE users SET name=?, email=?, avatar_url=?'),
            'L\'ancienne photo n\'est supprimée qu\'après l\'écriture en base.'
        );
    }

    public function testLeChampDAdresseNeBloquePlusLEnregistrement(): void
    {
        $page = self::lire('templates/settings/profile.php');

        $this->assertMatchesRegularExpression('#<input type="text" name="avatar_url" id="avatarUrlInput"#', $page);
        $this->assertDoesNotMatchRegularExpression('#type="url"[^>]*name="avatar_url"|name="avatar_url"[^>]*type="url"#', $page);
        $this->assertStringContainsString('AvatarStorage::urlFieldValue(', $page);
        $this->assertMatchesRegularExpression('#id="avatarUrlInput"[^>]*data-autosave-silent#s', $page);

        // Retrait : drapeau remis à zéro après chaque enregistrement.
        $this->assertMatchesRegularExpression('#name="remove_avatar"[^>]*data-autosave-reset#', $page);
        $this->assertStringContainsString('id="avatarRemoveBtn"', $page);

        // Après l'enregistrement, le champ n'est jamais recalé sur un chemin local.
        $this->assertStringContainsString("urlField.value = /^https?:\\/\\//i.test(url) ? url : '';", $page);
        $this->assertStringContainsString('data.avatar_failed', $page);
    }

    public function testLeTelechargementPasseParLaPorteDeWebPageReader(): void
    {
        $src = self::lire('src/Helpers/AvatarStorage.php');
        $pos = strpos($src, 'function importFromUrl(');
        $corps = substr($src, $pos, (int)strpos($src, 'function storeUserImage(') - $pos);
        $this->assertStringContainsString('->download(', $corps);
        $this->assertStringNotContainsString('file_get_contents', $corps);
        $this->assertStringNotContainsString('curl_', $corps);
    }
}
