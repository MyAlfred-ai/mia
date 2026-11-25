<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les captures que l'assistant « MIA : mode d'emploi » affiche dans ses réponses, et le pas-à-pas
 * « Claude dans Office » qui en est le premier usage.
 *
 * Le guide que lit l'assistant (docs/GUIDE-UTILISATEUR.md) cite ses captures sous /aide/images/ ;
 * HelpImageController les sert depuis docs/user/images/, le dossier du wiki. Une capture renommée
 * ou retirée d'un côté seulement donnerait une image cassée dans la réponse de l'assistant, sans
 * une erreur nulle part : c'est ce que ces tests attrapent.
 */
final class HelpImagesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT . '/src/Controllers/HelpImageController.php';
    }

    private static function lire(string $rel): string
    {
        $s = file_get_contents(ROOT . '/' . $rel);
        self::assertIsString($s, $rel);
        return $s;
    }

    public function testUnNomDeCaptureNeDesigneQueCeDossier(): void
    {
        foreach (['office-carte.png', 'ecran-principal.png', 'a.jpg', 'schema-2.svg', 'x.webp'] as $ok) {
            self::assertTrue(HelpImageController::isValidName($ok), $ok);
        }
        foreach (['', '../.env', 'office-carte.png/..', 'Office.png', 'office carte.png', '.htaccess',
                  'office-carte.php', 'office-carte', '-x.png', "x.png\0.php", 'a/b.png'] as $ko) {
            self::assertFalse(HelpImageController::isValidName($ko), $ko);
        }
        self::assertNull(HelpImageController::pathFor('../GUIDE-UTILISATEUR.md'));
        self::assertNull(HelpImageController::pathFor('inexistante-capture.png'));
        self::assertSame(
            realpath(ROOT . '/docs/user/images/office-jeton.png'),
            HelpImageController::pathFor('office-jeton.png')
        );
    }

    public function testChaqueCaptureCiteeParLesGuidesExiste(): void
    {
        $vues = 0;
        foreach (glob(ROOT . '/docs/GUIDE-*.md') ?: [] as $guide) {
            preg_match_all('#!\[[^\]]*\]\((/[^)\s]+)\)#', (string) file_get_contents($guide), $m);
            foreach ($m[1] as $url) {
                self::assertStringStartsWith(HelpImageController::URL_PREFIX, $url,
                    basename($guide) . ' : une image des guides passe par ' . HelpImageController::URL_PREFIX);
                $nom = substr($url, strlen(HelpImageController::URL_PREFIX));
                self::assertNotNull(HelpImageController::pathFor($nom), basename($guide) . ' cite ' . $url . ', introuvable');
                $vues++;
            }
        }
        self::assertGreaterThanOrEqual(3, $vues, 'le pas-à-pas Claude dans Office porte ses captures');
    }

    public function testLePasAPasOfficeDuWikiCiteSesCapturesEtElleExistent(): void
    {
        $page = self::lire('docs/user/fonctionnalites/connecteurs/claude-dans-office.md');
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        foreach (['office-jeton', 'office-connexion', 'office-passerelle'] as $nom) {
            self::assertStringContainsString('](../../images/' . $nom . '.png)', $page, $nom);
            self::assertFileExists(ROOT . '/docs/user/images/' . $nom . '.png');
            // Le guide de l'assistant montre la même image, au même endroit du parcours.
            self::assertStringContainsString('](/aide/images/' . $nom . '.png)', $guide, $nom);
        }
        // La carte de MIA se refait par l'outil de capture ; les deux écrans de l'add-in, pris à la
        // main dans Office, par le script qui les recadre et les numérote.
        self::assertFileExists(ROOT . '/docs/tech/captures/office-jeton.capture.mjs');
        $addin = self::lire('docs/tech/captures/office-addin.mjs');
        foreach (['office-connexion', 'office-passerelle'] as $nom) {
            self::assertStringContainsString("'" . $nom . ".png'", $addin, $nom);
        }
        // Les libellés du vrai add-in, relevés dans Office (docs/tech/captures/README.md) : ni
        // « API token » ni « Connect », que suggère la documentation d'Anthropic.
        foreach ([$page, $guide] as $texte) {
            foreach (['Cloud provider or gateway', 'Gateway URL', '**Token**', '**Continue**'] as $libelle) {
                self::assertStringContainsString($libelle, $texte, $libelle);
            }
            self::assertStringNotContainsString('API token', $texte);
            self::assertStringNotContainsString('**Connect**', $texte);
        }
        self::assertStringContainsString('Me guider pas à pas', $page);
    }

    public function testLaRouteEstDeclareeEtLaClasseChargee(): void
    {
        self::assertStringContainsString(
            "\$router->get('/aide/images/{nom}',",
            self::lire('public/index.php')
        );
        $classes = self::lire('config/classes.php');
        self::assertStringContainsString("require ROOT . '/src/Controllers/HelpImageController.php';", $classes);
        self::assertSame(HelpImageController::URL_PREFIX, '/aide/images/');
        // Pas sous /assets/img/generated : ce chemin-là est gardé par MediaController et compté
        // comme une création dans la galerie.
        self::assertStringNotContainsString('generated', HelpImageController::URL_PREFIX);
    }

    public function testLAssistantDAideRecopieLesCapturesTellesQuelles(): void
    {
        $src = self::lire('src/Models/DefaultAssistant.php');
        self::assertStringContainsString('![description](/aide/images/nom.png)', $src);
        self::assertStringContainsString('recopie chaque capture utile TELLE QUELLE', $src);
        self::assertStringContainsString("n'en ajoute aucune qui ne figure pas dans la", $src);
        self::assertStringContainsString('public static function profileId(): ?int', $src);
    }

    public function testLaCarteOfficeOuvreLAssistantDAideAvecLaQuestion(): void
    {
        $carte = self::lire('templates/settings/partials/office-addin-card.php');
        self::assertStringContainsString('action="/chat/new"', $carte);
        self::assertStringContainsString('name="profile_id" value="<?= (int)$officeCard[\'help_profile_id\'] ?>"', $carte);
        self::assertStringContainsString("sessionStorage.setItem('composerDraft'", $carte);
        self::assertStringContainsString("te('Me guider pas à pas')", $carte);

        $ctrl = self::lire('src/Controllers/OfficeAddinController.php');
        self::assertStringContainsString("Role::userCan(\$user, 'assistants.use') ? DefaultAssistant::profileId() : null", $ctrl);
        self::assertStringContainsString("'help_profile_id' => \$help", $ctrl);

        // La conversation ouverte reprend la question : même relais que le choix d'un assistant.
        self::assertStringContainsString("sessionStorage.getItem('composerDraft')", self::lire('public/assets/js/chat.js'));
    }

    public function testUneImageDeLAideNEstPasPriseParLeChatPourUneImageExterne(): void
    {
        // isSameOriginResource : une adresse « /… » (mais pas « //hôte ») s'affiche d'office.
        $js = self::lire('public/assets/js/chat.js');
        self::assertStringContainsString("if (value.startsWith('/') && !value.startsWith('//')) return true;", $js);
    }
}
