<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pages du compte (Paramètres) : trois décisions de répartition prises le
 * 2026-10-01, chacune gardée ici parce qu'un retour en arrière serait muet.
 *
 *  1. « MIA pour vous » n'a qu'UNE porte : la carte vit sur Préférences IA,
 *     sous « Votre contexte » ; Mon profil y RENVOIE au lieu de dupliquer
 *     l'éditeur (deux exemplaires obligeaient la doc à expliquer « deux
 *     portes, une seule liste »).
 *  2. La langue de l'interface se règle aussi dans Mon profil, par le MÊME
 *     chemin que le sélecteur flottant (POST /settings/lang, rechargement,
 *     comportement dans user-menu.js) — et hors du formulaire auto-enregistré,
 *     sinon un changement de langue partirait avec le profil, ou un
 *     enregistrement du profil emporterait la langue.
 *  3. La page Usage porte le nom de l'entrée du menu : un seul nom.
 */
final class AccountSettingsPagesTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");

        return (string) file_get_contents($abs);
    }

    public function testLaMemoireNaQuUnePorteEtMonProfilYRenvoie(): void
    {
        $partial = 'templates/settings/partials/memory-card.php';
        $profil  = self::lire('templates/settings/profile.php');
        $prefs   = self::lire('templates/settings/ai.php');

        $this->assertStringNotContainsString($partial, $profil, 'Mon profil ne rend plus la carte mémoire.');
        $this->assertStringContainsString($partial, $prefs, 'La carte mémoire vit sur Préférences IA.');
        $this->assertStringContainsString('href="/settings/ai#contexte"', $profil, 'Mon profil renvoie vers la section « Ce que MIA sait de vous ».');
        $this->assertStringContainsString("te('Ce que MIA sait de vous')", $profil);
        $this->assertStringContainsString("te('Ouvrir les Préférences IA')", $profil);

        // Le contrôleur ne charge plus les souvenirs pour Mon profil.
        $ctrl  = self::lire('src/Controllers/UserSettingsController.php');
        $debut = strpos($ctrl, 'public function profile(): void');
        $this->assertIsInt($debut);
        $corps = substr($ctrl, $debut, (int) strpos($ctrl, 'public function creations') - $debut);
        $this->assertStringNotContainsString('UserMemory::allForUser', $corps);

        // Les prompts renvoient vers la seule porte qui reste.
        foreach (['src/Models/UserMemory.php', 'src/Controllers/ChatController.php'] as $source) {
            $this->assertStringContainsString('Préférences IA → « MIA pour vous »', self::lire($source), $source);
        }
    }

    public function testLaLangueSeRegleDansMonProfilParLeMemeChemin(): void
    {
        $profil = self::lire('templates/settings/profile.php');

        preg_match('#<select\b[^>]*id="profileLang"[^>]*>#', $profil, $m);
        $this->assertNotEmpty($m, 'Le champ Langue de Mon profil est absent.');
        $select = $m[0];
        $this->assertStringContainsString('data-lang-select', $select);
        $this->assertStringContainsString('data-autosave-skip', $select, 'Changer de langue ne doit pas enregistrer le profil.');
        $this->assertStringNotContainsString(' name=', $select, 'Sans name : la langue ne part jamais avec le profil.');
        $this->assertStringContainsString('I18n::LOCALES', $profil, 'Les langues proposées viennent du socle, jamais d\'une liste recopiée.');
        $this->assertStringContainsString("te('Langue de l’interface')", $profil, 'Même libellé que le sélecteur flottant.');

        // Le comportement vit dans user-menu.js, à côté de la bascule du
        // sélecteur flottant, et passe par la même fonction.
        $js = self::lire('public/assets/js/user-menu.js');
        $this->assertStringContainsString("select[data-lang-select]", $js);
        $pos = strpos($js, "select[data-lang-select]");
        $this->assertIsInt($pos);
        $this->assertStringContainsString('basculerLangue(select.value, [select]);', substr($js, $pos, 400));

        // La route reste celle du sélecteur, ouverte aussi hors connexion.
        $this->assertStringContainsString("\$router->post('/settings/lang',", self::lire('public/index.php'));
    }

    public function testLaPageUsagePorteLeNomDeLEntreeDuMenu(): void
    {
        $usage = self::lire('templates/settings/usage.php');
        $this->assertSame('Usage & crédits', NavRegistry::ACCOUNT['usage']['label'], 'Le menu latéral et la palette lisent ce libellé.');
        $this->assertStringContainsString("<h1 style=\"margin-bottom:6px\"><?= te('Usage & crédits') ?></h1>", $usage);
        foreach (['templates/settings/usage.php', 'templates/partials/admin-nav.php'] as $gabarit) {
            $this->assertStringNotContainsString('Mon usage', self::lire($gabarit), "$gabarit nomme encore « Mon usage ».");
        }
        $this->assertArrayNotHasKey('Mon usage', (array) (require dirname(__DIR__, 2) . '/lang/en.php'), 'Clé orpheline.');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLesGuidesSuiventLaRepartition(): void
    {
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString("- **Langue de l'interface**", $guide);
        $this->assertStringNotContainsString('**Réglages → Mon profil** ou **Réglages → Préférences IA**', $guide);
        $this->assertStringNotContainsString('page « **Mon usage** »', $guide);

        $this->assertStringContainsString(
            'Mon profil',
            self::lire('docs/user/fonctionnalites/personnalisation/changer-la-langue.md'),
            'La page « Changer la langue » doit nommer la seconde surface.'
        );
        $this->assertStringNotContainsString(
            'deux portes, une seule liste',
            self::lire('docs/user/fonctionnalites/personnalisation/personnaliser-les-reponses.md')
        );
        $this->assertStringContainsString('mon-profil-langue-renvoi-memoire', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('data-lang-select', self::lire('CLAUDE.md'));
    }
}
