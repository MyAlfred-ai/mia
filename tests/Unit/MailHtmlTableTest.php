<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tableaux Markdown dans un corps d'email (MailHtml).
 *
 * Le défaut corrigé (retour d'un client, 2026-11-25) : un tableau écrit par
 * le modèle (« | KPI | N | N−1 | ») arrivait chez le destinataire en lignes
 * de barres verticales collées par des <br>, illisibles — MailHtml ne
 * connaissait que titres, listes, liens et emphase. Gmail et Outlook passent
 * tous deux par lui, la carte de relecture d'un email aussi.
 */
final class MailHtmlTableTest extends TestCase
{
    /** Le tableau du reporting Amazon d'un client, tel que le modèle l'a écrit. */
    private const KPI = <<<'MD'
Comparaison exclusive des mois calendaires complets.

| KPI | N : octobre 2026 | N−1 : octobre 2025 | Écart | Périmètre |
|---|---:|---:|---|---|
| Sell-in reçu net | 442 092,82 € au coût | 275 101,79 € au coût | Hausse | FR, **réceptions nettes** |
| Ad Spend | En attente | En attente | Non comparable | Saisie manuelle |

Les écarts ne sont pas réévalués.
MD;

    public function testUnTableauMarkdownDevientUnVraiTableau(): void
    {
        $html = MailHtml::toHtml(self::KPI);

        $this->assertStringContainsString('<table', $html);
        $this->assertSame(1, substr_count($html, '<table'));
        $this->assertSame(5, substr_count($html, '<th '));
        $this->assertSame(10, substr_count($html, '<td '));
        $this->assertStringContainsString('>KPI</th>', $html);
        $this->assertStringContainsString('442 092,82 € au coût', $html);
        // Plus aucune ligne de barres ni de tirets dans le message.
        $this->assertStringNotContainsString('|', $html);
        $this->assertStringNotContainsString('---', $html);
        // Le texte autour reste en paragraphes.
        $this->assertStringContainsString('<p>Comparaison exclusive des mois calendaires complets.</p>', $html);
        $this->assertStringContainsString('<p>Les écarts ne sont pas réévalués.</p>', $html);
    }

    public function testLAlignementDesColonnesEstRespecte(): void
    {
        $html = MailHtml::toHtml(self::KPI);
        // « ---: » = colonne de nombres, alignée à droite ; « --- » = à gauche.
        $this->assertMatchesRegularExpression('#<td[^>]*text-align:right[^>]*>442 092,82 € au coût</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*text-align:left[^>]*>Sell-in reçu net</td>#u', $html);
    }

    public function testLesCellulesGardentLeurMiseEnFormeEtSontEchappees(): void
    {
        $html = MailHtml::toHtml(self::KPI);
        $this->assertStringContainsString('<strong>réceptions nettes</strong>', $html);

        $piege = MailHtml::toHtml("| A | B |\n|---|---|\n| <script>alert(1)</script> | [x](javascript:alert(1)) |");
        $this->assertStringNotContainsString('<script', $piege);
        $this->assertStringContainsString('&lt;script&gt;', $piege);
        $this->assertStringNotContainsString('javascript:', $piege);
    }

    public function testLeTableauEstStyleEnLignePourLesClientsMail(): void
    {
        // Gmail et Outlook ignorent souvent une feuille de style : bordures et
        // marges sont écrites sur chaque cellule.
        $html = MailHtml::toHtml(self::KPI);
        $this->assertMatchesRegularExpression('#<table[^>]*style="[^"]*border-collapse:collapse#', $html);
        $this->assertMatchesRegularExpression('#<th[^>]*style="[^"]*border:1px solid#', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*style="[^"]*padding:#', $html);
    }

    public function testUnTableauColleAuParagrapheQuiLePrecedeEstReconnu(): void
    {
        $html = MailHtml::toHtml("Voici les chiffres :\n| Pays | Montant |\n|:---|---:|\n| FR | 15 539 € |");
        $this->assertStringContainsString('<p>Voici les chiffres :</p>', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('>15 539 €</td>', $html);
    }

    public function testUneBarreEchappeeResteDansSaCellule(): void
    {
        $html = MailHtml::toHtml("| Formule | Sens |\n|---|---|\n| a \\| b | ou |");
        $this->assertStringContainsString('>a | b</td>', $html);
        $this->assertSame(2, substr_count($html, '<td '));
    }

    public function testUneLigneIncompleteEstCompleteeEtUneLigneEnTropCoupee(): void
    {
        $html = MailHtml::toHtml("| A | B | C |\n|---|---|---|\n| 1 |\n| 1 | 2 | 3 | 4 |");
        $this->assertSame(6, substr_count($html, '<td '));
        $this->assertStringNotContainsString('>4<', $html);
    }

    public function testDesBarresSansLigneDeTiretsNeFontPasUnTableau(): void
    {
        $html = MailHtml::toHtml("Choix : oui | non | peut-être\nÀ trancher.");
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('oui | non | peut-être', $html);
    }

    public function testUnTableauHtmlFourniParLeModeleGardeSaStructure(): void
    {
        $html = MailHtml::toHtml('<p>Chiffres</p><table onclick="x()" style="position:fixed"><tr><th>Pays</th><th>Montant</th></tr><tr><td>FR</td><td>12 €</td></tr></table>');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('>Pays</th>', $html);
        $this->assertStringContainsString('>FR</td>', $html);
        // Les attributs du modèle tombent ; seul notre style reste.
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('position:fixed', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*border:1px solid#', $html);
    }

    public function testLaPartTexteDUnTableauHtmlSepareLesCellules(): void
    {
        $txt = MailHtml::toPlain('<table><tr><th>Pays</th><th>Montant</th></tr><tr><td>FR</td><td>12 €</td></tr></table>');
        $this->assertStringContainsString('Pays | Montant', $txt);
        $this->assertStringContainsString('FR | 12 €', $txt);
    }
}
