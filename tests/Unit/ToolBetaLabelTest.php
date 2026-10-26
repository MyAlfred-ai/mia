<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pastille « En bêta test » de la page Outils (/admin/tools).
 *
 * Les outils Carte / Itinéraire et Présentation fonctionnent mais sont encore
 * en phase de test : leur carte le dit, sur la liste des outils configurés
 * comme sur la carte de choix du type à la création. Une seule liste
 * (ChatTool::BETA_TYPES) décide des deux : sortir un type de la bêta ne doit
 * pas laisser une pastille oubliée sur l'autre écran.
 */
final class ToolBetaLabelTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function source(string $chemin): string
    {
        $s = file_get_contents(self::ROOT . '/' . $chemin);
        $this->assertIsString($s, $chemin);
        return $s;
    }

    /** Itinéraire en bêta ; l'outil Présentation n'est plus créable (remplacé par la carte du chat). */
    public function testItineraireEstEnBeta(): void
    {
        $this->assertSame(['map'], ChatTool::BETA_TYPES);
        $this->assertTrue(ChatTool::isBetaType('map'));
        foreach (['image', 'video', 'html', 'html_illustrated', 'n8n', 'gamma', 'presentation', ''] as $type) {
            $this->assertFalse(ChatTool::isBetaType($type), $type);
        }
        $this->assertNotSame('', trim(ChatTool::BETA_LABEL));
        $this->assertNotSame('', trim(ChatTool::BETA_HINT));
    }

    public function testLaCarteDUnOutilConfigurePorteLaPastilleSelonLaListe(): void
    {
        $tpl = $this->source('templates/admin/tools.php');
        $this->assertStringContainsString("ChatTool::isBetaType((string)\$tool['type'])", $tpl);
        // Pas de liste recopiée dans le gabarit : la règle reste dans ChatTool.
        $this->assertDoesNotMatchRegularExpression("/in_array\\(\\\$tool\\['type'\\],\\s*\\['map',\\s*'presentation'\\]/", $tpl);
    }

    public function testLesCartesDeTypeLaPortentHorsDuTitre(): void
    {
        $tpl = $this->source('templates/admin/tools.php');
        foreach (ChatTool::BETA_TYPES as $type) {
            $debut = strpos($tpl, '<label class="type-card" data-t="' . $type . '">');
            $this->assertNotFalse($debut, $type);
            $fin   = strpos($tpl, '</label>', (int)$debut);
            $carte = substr($tpl, (int)$debut, (int)$fin - (int)$debut);
            $this->assertStringContainsString("ChatTool::isBetaType('" . $type . "')", $carte);
            $this->assertStringContainsString('tool-beta-chip', $carte);
            // Le résumé de l'étape 1 relit le texte de .tc-title : la pastille
            // n'y entre pas, sinon il afficherait « PrésentationEn bêta test ».
            // Le titre ne porte que son libellé : texte, ou une seule clé traduite.
            $this->assertMatchesRegularExpression('#<span class="tc-title">(?:[^<]*|<\?= te\(\'[^\']*\'\) \?>)</span>#', $carte);
        }
    }

    public function testLaPastilleEstStyleeEtNeutre(): void
    {
        $css = $this->source('public/assets/css/main.css');
        $this->assertMatchesRegularExpression('/\.tool-beta-chip\s*\{[^}]*border:\s*1px dashed/s', $css);
        // Jamais teintée par le type : elle se confondrait avec la chip de type.
        preg_match('/\.tool-beta-chip\s*\{([^}]*)\}/s', $css, $m);
        $this->assertStringNotContainsString('--tool-accent', $m[1] ?? '');
    }

    public function testLeGuideAdministrateurLeDit(): void
    {
        $this->assertStringContainsString('En bêta test', $this->source('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('En bêta test', $this->source('docs/user/admin/outils/ajouter-un-outil.md'));
    }
}
