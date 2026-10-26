<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * LLM par défaut EFFECTIF de l'instance — règle pure et câblage.
 *
 * Le réglage `default_llm` seul ne suffisait pas : 004_settings.sql sème
 * « openai », un slug de FOURNISSEUR qui ne désigne plus rien dès qu'un modèle
 * est activé (les slugs deviennent `provider::modèle`). La page Comportement
 * par défaut affichait un premier modèle sans l'enregistrer, et les membres
 * du seul groupe « ★ Par défaut » n'avaient AUCUN modèle.
 */
final class LlmEffectiveDefaultTest extends TestCase
{
    private const DISPONIBLES = [
        'openai::gpt-6-astra' => 'GPT-6 Astra',
        'openai::gpt-5.6'     => 'GPT-5.6',
        'anthropic::claude-opus-5' => 'Claude Opus 5',
    ];

    /** Modèle par défaut du catalogue, par fournisseur (jamais un premium). */
    private const DEFAUTS_CATALOGUE = ['openai' => 'gpt-5.6', 'anthropic' => 'claude-opus-5'];

    public function testUnReglageDisponibleEstLeDefaut(): void
    {
        $this->assertSame(
            'anthropic::claude-opus-5',
            LLMFactory::effectiveDefault('anthropic::claude-opus-5', self::DISPONIBLES, self::DEFAUTS_CATALOGUE)
        );
    }

    public function testLeSlugDeFournisseurSemeNeDesignePlusRien(): void
    {
        // « openai » (valeur semée) n'est pas un LLM disponible : le défaut
        // effectif est le modèle par défaut du catalogue, pas le premier de la
        // liste — qui est ici le modèle premium, deux fois plus cher.
        $this->assertSame(
            'openai::gpt-5.6',
            LLMFactory::effectiveDefault('openai', self::DISPONIBLES, self::DEFAUTS_CATALOGUE)
        );
    }

    public function testSansModeleDeCatalogueLePremierDeLaListe(): void
    {
        $this->assertSame(
            'openai::gpt-6-astra',
            LLMFactory::effectiveDefault('', ['openai::gpt-6-astra' => 'GPT-6 Astra', 'local' => 'LLM Local'], self::DEFAUTS_CATALOGUE)
        );
    }

    public function testRienDeDisponibleNeDonneAucunDefaut(): void
    {
        $this->assertNull(LLMFactory::effectiveDefault('openai::gpt-5.6', [], self::DEFAUTS_CATALOGUE));
    }

    public function testLaListeBlancheDuGroupeParDefautPasseParLeDefautEffectif(): void
    {
        $code = (string)file_get_contents(ROOT . '/src/Models/UserGroup.php');
        $this->assertStringContainsString('LLMFactory::appDefault()', $code);
        $this->assertStringNotContainsString("Settings::get('default_llm'", $code,
            'Le réglage brut peut désigner un LLM mort : la liste blanche doit lire le défaut effectif.');
    }

    public function testLesGestesQuiChangentLaListeReparentLeReglage(): void
    {
        require_once ROOT . '/src/Controllers/AdminController.php';
        foreach (['saveLlmModel', 'deleteLlmModel', 'saveApiKey', 'deleteApiKey'] as $methode) {
            $m      = new ReflectionMethod(AdminController::class, $methode);
            $lignes = file((string)$m->getFileName()) ?: [];
            $corps  = implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
            $this->assertStringContainsString('LLMFactory::repairStoredDefault()', $corps, $methode);
        }
    }

    public function testLaPageDitQuandLeDefautNEstPasEnregistre(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/llms/models.php');
        $this->assertStringContainsString('id="defaultLlmUnsaved"', $gabarit);
        $this->assertStringContainsString('id="defaultLlmConfirm"', $gabarit);
    }
}
