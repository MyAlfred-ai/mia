<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Administration → Personnes → Comptes : deux défauts relevés chez un
 * client connecté par Microsoft.
 *
 *  - La fenêtre « Nouvel utilisateur » annonçait « Un compte Google se crée
 *    tout seul à la première connexion Google » : lu « MIA va créer un compte
 *    Google », l'administrateur renonçait. Elle crée en réalité un compte à
 *    mot de passe ; l'aide nomme désormais les fournisseurs proposés.
 *  - Le filtre « méthode de connexion » proposait Google / Email / Admin
 *    local et comparait à l'ÉGALITÉ avec le libellé assemblé (« Google + Mot
 *    de passe ») : « Email » ne trouvait rien, Microsoft n'existait pas, un
 *    compte à deux méthodes ne ressortait jamais.
 */
final class AdminUsersAuthFilterTest extends TestCase
{
    private function gabarit(): string
    {
        return (string)file_get_contents(ROOT . '/templates/admin/users.php');
    }

    // ============ Méthodes de connexion (pures) ============

    public function testLesMethodesDUnCompteSontListeesSansDoublon(): void
    {
        $this->assertSame([], UserIdentity::authMethods([], false));
        $this->assertSame(['microsoft'], UserIdentity::authMethods([UserIdentity::MICROSOFT], false));
        $this->assertSame(
            ['google', 'microsoft', 'password'],
            UserIdentity::authMethods([UserIdentity::GOOGLE, UserIdentity::MICROSOFT, UserIdentity::GOOGLE], true)
        );
        $this->assertSame(['local_admin'], UserIdentity::authMethods([], false, true));
    }

    public function testLeLibelleEstAssembleDepuisLesMemesMethodes(): void
    {
        $this->assertSame('', UserIdentity::authLabel([], false));
        $this->assertSame('Microsoft', UserIdentity::authLabel([UserIdentity::MICROSOFT], false));
        $this->assertSame('Google + Mot de passe', UserIdentity::authLabel([UserIdentity::GOOGLE], true));
        $this->assertSame('Admin local', UserIdentity::authLabel([], false, true));
        // Deux identités Google sur le même compte : un seul « Google ».
        $this->assertSame('Google', UserIdentity::authLabel([UserIdentity::GOOGLE, UserIdentity::GOOGLE], false));
    }

    public function testChaqueMethodePossibleAUneOptionDeFiltre(): void
    {
        $options = UserIdentity::methodLabels();
        $tout    = UserIdentity::authMethods(array_keys(UserIdentity::LABELS), true, true);

        foreach ($tout as $methode) {
            $this->assertArrayHasKey($methode, $options, 'Méthode sans option de filtre : ' . $methode);
        }
        $this->assertSame('Microsoft', $options[UserIdentity::MICROSOFT]);
        $this->assertSame('Mot de passe', $options[UserIdentity::METHOD_PASSWORD]);
        foreach (array_keys($options) as $methode) {
            // Les clés voyagent séparées par des espaces dans data-auth.
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $methode);
        }
    }

    // ============ Câblage du filtre ============

    public function testLeFiltreProposeLesMethodesEtTesteLAppartenance(): void
    {
        $html = $this->gabarit();

        $this->assertSame(1, preg_match('#<select id="umFilterAuth"[^>]*>(.*?)</select>#s', $html, $m));
        $this->assertStringContainsString('UserIdentity::methodLabels()', $m[1], 'Les options viennent de la source unique.');
        $this->assertStringNotContainsString('value="Email"', $m[1]);
        $this->assertStringNotContainsString('value="Google"', $m[1], 'Plus aucune option écrite en dur.');

        $this->assertStringContainsString('UserIdentity::authMethods(', $html);
        $this->assertStringContainsString("data-auth=\"<?= htmlspecialchars(implode(' ', \$r['authMethods'])) ?>\"", $html);

        $this->assertStringNotContainsString('tr.dataset.auth !== a', $html, 'Égalité stricte : un compte à deux méthodes ne ressort jamais.');
        $this->assertStringContainsString(".split(' ').includes(a)", $html);
    }

    // ============ Aide de la fenêtre « Nouvel utilisateur » ============

    public function testLAideNommeLesFournisseursProposes(): void
    {
        $seul = SsoRegistry::newAccountHint(['Microsoft'], true);
        $this->assertStringContainsString('compte qui se connecte par e-mail et mot de passe', $seul);
        $this->assertStringContainsString('par Microsoft, inutile de le créer ici', $seul);
        $this->assertStringNotContainsString('Google', $seul);

        $deux = SsoRegistry::newAccountHint(['Google', 'Microsoft'], true);
        $this->assertStringContainsString('par Google ou Microsoft,', $deux);

        $aucun = SsoRegistry::newAccountHint([], true);
        $this->assertStringNotContainsString('première connexion', $aucun);
        $this->assertStringNotContainsString('désactivée', $aucun);
    }

    public function testLAidePrevientQuandLeMotDePasseNeServiraPas(): void
    {
        $texte = SsoRegistry::newAccountHint(['Microsoft'], false);
        $this->assertStringContainsString('connexion par mot de passe est désactivée', $texte);
        $this->assertStringContainsString('ne servira pas', $texte);
    }

    public function testLaFenetreAfficheLAideCalculee(): void
    {
        $html = $this->gabarit();
        $this->assertStringNotContainsString('Un compte Google se crée tout seul', $html);
        $this->assertStringContainsString('<p class="profile-section-hint"><?= htmlspecialchars($newUserHint) ?></p>', $html);

        $controleur = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertMatchesRegularExpression(
            '/\$newUserHint = SsoRegistry::newAccountHint\(\s*array_map\(\[SsoRegistry::class, \'label\'\], SsoRegistry::usableProviders\(\)\),\s*SsoRegistry::passwordEnabled\(\)\s*\);/',
            $controleur
        );
    }
}
