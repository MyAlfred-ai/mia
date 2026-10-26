<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Deux libellés d'administration qui promettaient plus que le code.
 *
 *  - Outils : « Actif — utilisable par tous les utilisateurs », alors qu'un
 *    outil n'est proposé qu'aux membres des groupes qui l'autorisent
 *    (ChatTool::activeByUser) et qu'un outil neuf n'est autorisé dans aucun
 *    groupe : seuls les administrateurs le voyaient.
 *  - Groupes : « Vide = illimité » sous le quota, alors qu'un groupe sans
 *    quota est NEUTRE (UserGroup::effectiveQuotaForUserId retient le plus
 *    élevé des quotas DÉFINIS) : un membre d'un groupe « illimité » restait
 *    plafonné par le quota d'un autre de ses groupes.
 */
final class AdminAccessLabelsTest extends TestCase
{
    private function gabarit(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    /** Contenu d'un <label for="…"> (texte + bulle d'aide éventuelle). */
    private function libelle(string $html, string $for): string
    {
        $this->assertSame(1, preg_match('#<label for="' . preg_quote($for, '#') . '"[^>]*>(.*?)</label>#s', $html, $m), $for);
        return $m[1];
    }

    // ============ Outils ============

    public function testLInterrupteurActifNePrometPlusTousLesUtilisateurs(): void
    {
        $html = $this->gabarit('templates/admin/tools.php');

        foreach (['cActive', 'eActive'] as $for) {
            $texte = $this->libelle($html, $for);
            $this->assertStringNotContainsString('tous les utilisateurs', $texte, $for);
            $this->assertStringContainsString('groupe', $texte, $for . ' : le libellé doit dire que l\'accès passe par les groupes.');
        }

        $this->assertSame(1, preg_match('#<p class="tools-list-hint">(.*?)</p>#s', $html, $m));
        $this->assertStringNotContainsString('tous les utilisateurs', $m[1]);
        $this->assertStringContainsString('groupes', $m[1]);

        $this->assertSame(1, preg_match('#<p class="tools-intro">(.*?)</p>#s', $html, $m));
        $this->assertStringNotContainsString('tous les utilisateurs', $m[1]);
    }

    public function testLaCarteDitDansCombienDeGroupesLOutilEstAutorise(): void
    {
        $html = $this->gabarit('templates/admin/tools.php');

        // Portée réelle calculée côté serveur, reprise par le script à la bascule.
        $this->assertStringContainsString('$toolGroupCounts[', $html);
        $this->assertStringContainsString('data-on="', $html);
        $this->assertStringContainsString('sub.dataset.on', $html);
        // Un outil actif autorisé nulle part le dit, avec le chemin du réglage.
        $this->assertStringContainsString('id="tool-no-group-', $html);

        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertStringContainsString('ChatTool::activeGroupCounts()', $this->corps(AdminController::class, 'tools'));
        $this->assertStringContainsString('Droits d\'accès', $this->corps(AdminController::class, 'createTool'),
            'La confirmation de création doit dire où autoriser l\'outil.');
    }

    // ============ Quota de groupe ============

    public function testUnGroupeSansQuotaNEstPlusPresenteCommeIllimite(): void
    {
        $html = $this->gabarit('templates/admin/user-groups.php');

        $this->assertStringNotContainsString('Vide = illimité', $html);
        $this->assertStringNotContainsString('placeholder="∞ illimité"', $html);
        $this->assertStringNotContainsString("'∞ illimité'", $html);
        // L'aide dit la vraie règle : neutre, c'est le plus élevé des quotas définis qui s'applique.
        $this->assertSame(1, preg_match('#id="ugQuota".*?<p class="profile-hint">(.*?)</p>#s', $html, $m));
        $this->assertStringContainsString('neutre', $m[1]);
    }

    private function corps(string $classe, string $methode): string
    {
        $m      = new ReflectionMethod($classe, $methode);
        $lignes = file((string)$m->getFileName()) ?: [];
        return implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }
}
