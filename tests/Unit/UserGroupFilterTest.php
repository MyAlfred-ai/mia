<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Filtre par groupe de la page Comptes (Personnes → Comptes), à côté des
 * filtres par rôle, statut et connexion.
 *
 * Un compte appartient à plusieurs groupes : il apparaît sous CHACUN. Le
 * groupe par défaut contient tout le monde — l'option « Sans autre groupe »
 * retrouve les comptes qui n'ont reçu aucun groupe particulier.
 */
final class UserGroupFilterTest extends TestCase
{
    private function gabarit(): string
    {
        return (string)file_get_contents(ROOT . '/templates/admin/users.php');
    }

    public function testLaListeDesGroupesEstDansLaBarreDeFiltres(): void
    {
        $html = $this->gabarit();
        $this->assertSame(1, preg_match('#<div class="um-toolbar">(.*?)<span class="um-count"#s', $html, $m));
        $barre = $m[1];

        $this->assertStringContainsString('id="umFilterGroup"', $barre);
        $this->assertStringContainsString("te('Tous les groupes')", $barre);
        // Une option par groupe de la page (actifs comme inactifs)…
        $this->assertMatchesRegularExpression('#foreach \(\$allGroups as \$g\):.*?<option value="<\?= \(int\)\$g\[.id.\] \?>">#s', $barre);
        // … puis les comptes sans autre groupe que celui par défaut.
        $this->assertStringContainsString('<option value="none" title=', $barre);
        $this->assertStringContainsString("te('Sans autre groupe')", $barre);
        $this->assertStringContainsString("te('Comptes qui ne sont dans aucun groupe en dehors de « {nom} »'", $barre);
        // L'ordre des filtres : rôle, groupe, statut, connexion.
        $this->assertLessThan(strpos($barre, 'umFilterGroup'), strpos($barre, 'umFilterRole'));
        $this->assertLessThan(strpos($barre, 'umFilterStatus'), strpos($barre, 'umFilterGroup'));
    }

    public function testChaqueLignePorteSesGroupes(): void
    {
        $html = $this->gabarit();
        $this->assertSame(1, preg_match('#<tbody id="umTbody">(.*?)<td data-label#s', $html, $m));
        $ligne = $m[1];

        $this->assertStringContainsString('data-groups="', $ligne);
        $this->assertMatchesRegularExpression('#data-groups="<\?= htmlspecialchars\(implode\(\' \', array_map\(.*?\$r\[.userGroups.\]\)\)\) \?>"#', $ligne);
        // Les groupes particuliers : tout sauf le groupe par défaut.
        $this->assertMatchesRegularExpression('#data-own-groups="<\?= count\(array_filter\(\$r\[.userGroups.\], .*?empty\(\$g\[.is_default.\]\)\)\) \?>"#', $ligne);
    }

    public function testLeFiltreSAppliqueAvecLesAutres(): void
    {
        $html = $this->gabarit();
        $this->assertSame(1, preg_match('#function applyFilters\(\) \{(.*?)\n    \}#s', $html, $m));
        $corps = $m[1];

        $this->assertStringContainsString('const g  = fGroup.value;', $corps);
        // Un compte de plusieurs groupes apparaît sous chacun d'eux.
        $this->assertStringContainsString("(tr.dataset.groups || '').split(' ').includes(g)", $corps);
        $this->assertStringContainsString("if (g === 'none')", $corps);
        $this->assertStringContainsString("tr.dataset.ownGroups !== '0'", $corps);

        $this->assertStringContainsString("const fGroup   = document.getElementById('umFilterGroup');", $html);
        $this->assertStringContainsString('[search, fRole, fGroup, fStatus, fAuth].forEach(el => el.addEventListener(\'input\', applyFilters));', $html);
    }

    public function testLesLibellesSontTraduits(): void
    {
        $dict = require ROOT . '/lang/en.php';
        $this->assertSame('All groups', $dict['Tous les groupes'] ?? null);
        $this->assertSame('No other group', $dict['Sans autre groupe'] ?? null);
        $this->assertSame('Accounts in no group other than “{nom}”', $dict['Comptes qui ne sont dans aucun groupe en dehors de « {nom} »'] ?? null);
        $this->assertSame('Accounts in no group', $dict['Comptes qui ne sont dans aucun groupe'] ?? null);
    }
}
