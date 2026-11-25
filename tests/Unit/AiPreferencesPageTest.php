<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réglages → Préférences IA (templates/settings/ai.php).
 *
 * La page se lit en trois sections — comment MIA répond, ce qu'elle sait de
 * vous, à l'oral — et repose sur UN formulaire VIDE auquel chaque contrôle se
 * rattache par form="aiPrefsForm" : un contrôle peut ainsi vivre dans n'importe
 * quelle carte sans imbriquer deux formulaires. Un contrôle ajouté SANS
 * l'attribut ne serait jamais posté — ni par autosave.js, ni sans JavaScript —
 * et l'écran afficherait un réglage qui ne s'enregistre pas, sans une erreur.
 *
 * « Ce que MIA sait de vous » n'est plus qu'une carte de renvoi : « Votre
 * contexte » et les souvenirs ont leur page, Réglages → Mémoire (2026-11-03).
 */
final class AiPreferencesPageTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");

        return (string) file_get_contents($abs);
    }

    public function testTroisSectionsDansLOrdreEtLaMemoireRenvoieASaPage(): void
    {
        $tpl = self::lire('templates/settings/ai.php');

        $style = strpos($tpl, 'id="style"');
        $mem   = strpos($tpl, 'id="memoire"');
        $voix  = strpos($tpl, 'id="voix"');
        foreach (['style' => $style, 'mémoire' => $mem, 'voix' => $voix] as $nom => $pos) {
            $this->assertIsInt($pos, "Section absente : $nom");
        }
        $this->assertLessThan($mem, $style, 'Le style ouvre la page…');
        $this->assertLessThan($voix, $mem, '… la carte Mémoire précède la voix.');

        // La carte de renvoi mène à la page Mémoire ; ni « Votre contexte » ni
        // la liste des souvenirs ne sont recopiés ici.
        $this->assertStringContainsString('ai-prefs-pointer', $tpl);
        $this->assertStringContainsString('href="/settings/memory"', $tpl);
        $this->assertStringNotContainsString('id="contexte"', $tpl);
        $this->assertStringNotContainsString('memory-card.php', $tpl);

        // La carte Voix reste conditionnelle : le handler n'écrit que les
        // champs présents dans le POST (VoiceConversationTest le garde aussi).
        $this->assertStringContainsString('<?php if (!empty($voiceEnabled)): ?>', $tpl);

        // Le sommaire vise les sections et la page Mémoire.
        foreach (['href="#style"', 'href="/settings/memory"', 'href="#voix"'] as $ancre) {
            $this->assertStringContainsString($ancre, $tpl, $ancre);
        }
        // Plus de bouton « Retour au profil » : la navigation des paramètres suffit.
        $this->assertStringNotContainsString('Retour au profil', $tpl);
    }

    public function testUnSeulFormulaireVideEtChaqueControleSYRattache(): void
    {
        $tpl = self::lire('templates/settings/ai.php');

        // `<form\s` : le docblock du gabarit cite « un <form> dans un <form> ».
        preg_match_all('#<form\s[^>]*>(.*?)</form>#s', $tpl, $formulaires);
        $this->assertCount(1, $formulaires[0], 'Un seul formulaire sur la page.');
        $this->assertStringContainsString('id="aiPrefsForm"', $formulaires[0][0]);
        $this->assertStringContainsString('data-autosave', $formulaires[0][0]);
        $this->assertStringContainsString('action="/settings/ai"', $formulaires[0][0]);
        $this->assertSame('<?= Csrf::field() ?>', trim($formulaires[1][0]), 'Le formulaire ne porte que le jeton CSRF.');

        // Chaque contrôle nommé du gabarit se rattache au formulaire. Les
        // balises PHP sont retirées d'abord : leur chevron fermant terminerait
        // la balise HTML avant l'attribut cherché.
        $sansPhp = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $tpl);
        preg_match_all('#<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"[^>]*>#s', $sansPhp, $champs, PREG_SET_ORDER);
        $noms = [];
        foreach ($champs as $champ) {
            $this->assertStringContainsString('form="aiPrefsForm"', $champ[0], 'Contrôle non rattaché : ' . $champ[1]);
            $noms[$champ[1]] = true;
        }
        $this->assertSame(['preferred_llm', 'ai_style', 'voice_rate', 'voice_length'], array_keys($noms));
    }

    public function testLesCartesAffichentLesAidesTraduitesJamaisLeFragmentDePrompt(): void
    {
        $tpl = self::lire('templates/settings/ai.php');
        $this->assertStringNotContainsString("['fragment']", $tpl, 'Le fragment de prompt ne s\'affiche pas.');
        $this->assertStringContainsString("\$info['hint']", $tpl);

        $ctrl  = self::lire('src/Controllers/UserSettingsController.php');
        $debut = strpos($ctrl, 'public function aiPreferences(): void');
        $this->assertIsInt($debut);
        $corps = substr($ctrl, $debut, (int) strpos($ctrl, 'public function updateAiPreferences') - $debut);
        $this->assertStringContainsString('UserPreferences::styles()', $corps, 'Le contrôleur passe les libellés traduits.');
        $this->assertStringNotContainsString('UserPreferences::STYLES', $corps);

        // Les libellés et aides ont leur traduction : l'accesseur passe par t()
        // sur des variables, que le test de couverture des clés ne voit pas.
        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (UserPreferences::STYLES as $slug => $info) {
            $this->assertArrayHasKey($info['label'], $dict, "Libellé non traduit : $slug");
            $this->assertArrayHasKey($info['hint'], $dict, "Aide non traduite : $slug");
        }
    }

    public function testLesAncresEtLesStylesDeLaPageExistent(): void
    {
        // La pastille du chat mène à la page Mémoire.
        $this->assertStringContainsString("'/settings/memory'", self::lire('public/assets/js/chat.js'));
        $this->assertStringNotContainsString('/settings/ai#memoryCard', self::lire('public/assets/js/chat.js'));

        $css = self::lire('public/assets/css/main.css');
        foreach (['.ai-prefs-section', '.ai-prefs-nav', '.ai-prefs-pointer', '.ai-choice-card', '.ai-field-head', '.voice-rate-ticks', '.card-head-text'] as $classe) {
            $this->assertStringContainsString($classe, $css, "Style manquant : $classe");
        }
        // Ancres sous le header mobile collant, cartes-radio sur deux colonnes au doigt.
        $this->assertStringContainsString('scroll-margin-top: 72px', $css);
        $this->assertStringContainsString('.ai-choice-grid { grid-template-columns: repeat(2, minmax(0, 1fr))', $css);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLesGuidesDecriventLesTroisParties(): void
    {
        foreach ([
            'docs/GUIDE-UTILISATEUR.md',
            'docs/user/fonctionnalites/personnalisation/personnaliser-les-reponses.md',
        ] as $guide) {
            $src = self::lire($guide);
            foreach (['Comment MIA vous répond', 'Ce que MIA sait de vous', "À l'oral"] as $partie) {
                $this->assertStringContainsString($partie, $src, "$guide ne nomme pas « $partie ».");
            }
        }
        $this->assertStringContainsString('preferences-ia-trois-sections', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('form="aiPrefsForm"', self::lire('CLAUDE.md'));
    }
}
