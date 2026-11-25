<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Recherche de membres de la fenêtre d'un groupe (Personnes → Groupes).
 *
 * Le défaut corrigé : la recherche ne masquait AUCUNE ligne. Le filtre posait
 * `item.style.display = 'none'`, mais `.ug-check-item` porte
 * `display: flex !important` (posé pour battre `.modal-box label`, qui empile
 * ses enfants) — une règle `!important` de la feuille de style l'emporte sur
 * un style en ligne qui ne l'est pas. Tous les comptes restaient affichés, et
 * « Aucun utilisateur ne correspond » pouvait apparaître sous la liste
 * entière. Remède maison (piège `hidden`) : l'attribut `hidden` + une règle
 * `[hidden] { display: none !important }` dédiée.
 */
final class UserGroupMemberSearchTest extends TestCase
{
    private function gabarit(): string
    {
        return (string)file_get_contents(ROOT . '/templates/admin/user-groups.php');
    }

    private function filtre(): string
    {
        $html = $this->gabarit();
        $this->assertSame(1, preg_match('#function filterMembers\(\) \{(.*?)\n    \}#s', $html, $m), 'filterMembers introuvable');
        return $m[1];
    }

    public function testLeFiltreMasqueLesLignesParLAttributHidden(): void
    {
        $corps = $this->filtre();
        $this->assertMatchesRegularExpression('#item\.hidden\s*=\s*!match#', $corps);
        $this->assertStringNotContainsString('item.style.display', $corps,
            'un style en ligne perd contre display: flex !important');
    }

    public function testUneLigneMasqueeLEstVraimentMalgreLeFlexImpose(): void
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertMatchesRegularExpression('#\.ug-check-item\s*\{[^}]*display:\s*flex\s*!important#', $css,
            'contexte du test : la règle qui imposait le flex');
        $this->assertMatchesRegularExpression('#\.ug-check-item\[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}#', $css);
    }

    public function testLaRechercheIgnoreLesAccentsDesDeuxCotes(): void
    {
        $corps = $this->filtre();
        // « helene » doit trouver « Hélène » : la requête ET le texte de la
        // ligne passent par le même pliage.
        $this->assertMatchesRegularExpression('#const q = fold\(memberSearch\.value\)#', $corps);
        $this->assertMatchesRegularExpression('#fold\(item\.dataset\.search#', $corps);
        $this->assertMatchesRegularExpression("#const fold = .*normalize\('NFD'\)#", $this->gabarit());
    }
}
