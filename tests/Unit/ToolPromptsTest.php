<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prompts système livrés avec les outils à LLM.
 *
 * Deux régressions silencieuses sont gardées ici :
 *  - le CONTRAT DE SORTIE (HTML seul, JSON de carte, JSON de deck) qui
 *    passerait dans la partie éditable : un administrateur le supprimerait
 *    sans le savoir, et la génération rendrait un texte que l'affichage ne
 *    sait pas lire — sans une ligne d'erreur ;
 *  - le formulaire qui cesserait de pré-remplir le champ : on reviendrait au
 *    champ vide et au « comportement par défaut » invisible.
 */
final class ToolPromptsTest extends TestCase
{
    // ---------- Défauts ----------

    public function testLesQuatreTypesALlmOntUnPromptLivre(): void
    {
        foreach (ToolPrompts::TYPES as $type) {
            $this->assertNotSame('', trim(ToolPrompts::defaultFor($type)), "Type sans prompt livré : {$type}");
        }
        $this->assertSame(
            ['html', 'html_illustrated', 'map', 'presentation'],
            ToolPrompts::TYPES
        );
    }

    public function testLesTypesSansLlmNOntAucunPrompt(): void
    {
        // Image, vidéo, n8n et Gamma ne passent par aucun prompt système
        // éditable : leur en inventer un afficherait un champ sans effet.
        foreach (['image', 'video', 'n8n', 'gamma', 'inconnu'] as $type) {
            $this->assertSame('', ToolPrompts::defaultFor($type));
        }
    }

    public function testLesPromptsLivresNeContiennentAucunContratDeSortie(): void
    {
        // Le format attendu par l'affichage est ajouté par le code appelant :
        // s'il fuitait ici, il deviendrait effaçable d'un coup de touche.
        foreach (ToolPrompts::TYPES as $type) {
            $prompt = ToolPrompts::defaultFor($type);
            $this->assertStringNotContainsString('JSON', $prompt, "Contrat JSON dans le prompt éditable de {$type}");
            $this->assertStringNotContainsString('format exact', mb_strtolower($prompt));
            $this->assertStringNotContainsString('"waypoints"', $prompt);
            $this->assertStringNotContainsString('"slides"', $prompt);
        }
    }

    public function testLeContratDeCarteEtDeDeckVitDansLeCodeAppelant(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('mapToolJsonContract', $chat);
        $this->assertStringContainsString("ToolPrompts::resolve('map', \$config) . self::mapToolJsonContract()", $chat);
        // L'ancien « CONSIGNES SUPPLÉMENTAIRES » ajoutait le texte admin APRÈS
        // le défaut : pré-remplir le champ aurait dupliqué la persona.
        $this->assertStringNotContainsString("CONSIGNES SUPPLÉMENTAIRES DE L'ADMINISTRATEUR", $chat);

        $deck = (string)file_get_contents(ROOT . '/src/Slides/DeckGenerator.php');
        $this->assertStringContainsString('Format EXACT', $deck);
        $this->assertStringContainsString('public static function outlineSystemPrompt(array $options, string $persona = \'\')', $deck);
        $this->assertStringContainsString('public static function slidesSystemPrompt(array $options, string $persona = \'\')', $deck);
    }

    // ---------- resolve / fromPost ----------

    public function testUnChampVideRetombeSurLeDefaut(): void
    {
        $this->assertSame(ToolPrompts::defaultFor('html'), ToolPrompts::resolve('html', []));
        $this->assertSame(ToolPrompts::defaultFor('html'), ToolPrompts::resolve('html', ['system_prompt' => '   ']));
        $this->assertSame('Consigne maison.', ToolPrompts::resolve('html', ['system_prompt' => 'Consigne maison.']));
    }

    public function testLeDefautLaisseTelQuelNEstPasStocke(): void
    {
        // Sinon le texte du jour serait figé dans chaque outil créé, et une
        // amélioration ultérieure du défaut ne profiterait qu'aux nouveaux.
        $this->assertNull(ToolPrompts::fromPost('map', ToolPrompts::defaultFor('map')));
        $this->assertNull(ToolPrompts::fromPost('map', "\n" . ToolPrompts::defaultFor('map') . "  \n"));
        // Copier-coller depuis le navigateur : CRLF, même texte.
        $crlf = str_replace("\n", "\r\n", ToolPrompts::defaultFor('presentation'));
        $this->assertNull(ToolPrompts::fromPost('presentation', $crlf));
    }

    public function testUnChampVideOuPersonnaliseEstTraiteCorrectement(): void
    {
        $this->assertNull(ToolPrompts::fromPost('html', ''));
        $this->assertNull(ToolPrompts::fromPost('html', "   \n "));
        $this->assertSame('Parle comme un pirate.', ToolPrompts::fromPost('html', "  Parle comme un pirate.\n"));
        // Un type sans défaut accepte quand même une consigne libre.
        $this->assertSame('X', ToolPrompts::fromPost('image', 'X'));
    }

    public function testAllRendLesQuatreDefauts(): void
    {
        $all = ToolPrompts::all();
        $this->assertSame(ToolPrompts::TYPES, array_keys($all));
        foreach ($all as $type => $prompt) {
            $this->assertSame(ToolPrompts::defaultFor($type), $prompt);
        }
    }

    // ---------- Câblage ----------

    public function testLaClasseEstChargeeAvantSesConsommateurs(): void
    {
        // Pas d'autoloader : un require trop tardif ferait échouer les jobs de
        // page HTML et les présentations, en production seulement.
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $file) {
            $src = (string)file_get_contents(ROOT . $file);
            $posPrompts = strpos($src, 'ToolPrompts.php');
            $this->assertNotFalse($posPrompts, "ToolPrompts absent de {$file}");
            foreach (['DeckGenerator.php', 'HtmlPageJob.php'] as $consumer) {
                $posConsumer = strpos($src, $consumer);
                $this->assertNotFalse($posConsumer, "{$consumer} absent de {$file}");
                $this->assertLessThan($posConsumer, $posPrompts, "ToolPrompts chargé après {$consumer} dans {$file}");
            }
        }
    }

    public function testLesGenerateursPassentParToolPrompts(): void
    {
        $job = (string)file_get_contents(ROOT . '/src/Jobs/HtmlPageJob.php');
        $this->assertStringContainsString('ToolPrompts::resolve($type, $config)', $job);
        $this->assertStringContainsString("buildSystemPrompt(\$config, (string)\$tool['type'])", $job);

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("outlineSystemPrompt(\$options, ToolPrompts::resolve('presentation', \$config))", $chat);
        $this->assertStringContainsString("slidesSystemPrompt(\$options, ToolPrompts::resolve('presentation', \$config))", $chat);

        // Le test d'administration doit voir le MÊME prompt que la production.
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("outlineSystemPrompt(\$options, ToolPrompts::resolve('presentation', \$config))", $admin);
        $this->assertStringContainsString("ToolPrompts::fromPost(\$type, \$_POST['config_system_prompt'] ?? '')", $admin);
        $this->assertSame(2, substr_count($admin, 'ToolPrompts::fromPost'), 'Création et modification doivent trier pareil.');
    }

    public function testLeFormulairePreRemplitLesDeuxEcrans(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString('ToolPrompts::all()', $tpl);
        $this->assertStringContainsString('TOOL_PROMPT_DEFAULTS', $tpl);
        // Création : le champ suit le type choisi tant qu'il porte un défaut.
        $this->assertStringContainsString('cCfgSystemPrompt', $tpl);
        $this->assertStringContainsString('syncCreatePrompt', $tpl);
        $this->assertStringContainsString('isDefaultToolPrompt', $tpl);
        // Fiche : prompt enregistré, sinon défaut du type.
        $this->assertStringContainsString("(cfg.system_prompt || '').trim() || (TOOL_PROMPT_DEFAULTS[tool.type] || '')", $tpl);
        // Plus de « (optionnel) » : le champ n'est plus vide par défaut.
        $this->assertStringNotContainsString('Laisser vide pour utiliser le prompt par défaut', $tpl);
    }

    public function testLAjoutDUnOutilImageNeDemandePlusTailleNiQualite(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        // Le formulaire de création est tout ce qui précède la modale d'édition.
        $split = strpos($tpl, 'id="editModal"');
        $this->assertNotFalse($split);
        $creation = substr($tpl, 0, $split);
        $this->assertStringNotContainsString('name="config_size"', $creation);
        $this->assertStringNotContainsString('name="config_quality"', $creation);
        // La fiche de l'outil, elle, permet toujours de les figer.
        $this->assertStringContainsString('id="eCfgSize"', $tpl);
        $this->assertStringContainsString('id="eCfgQuality"', $tpl);
    }
}
