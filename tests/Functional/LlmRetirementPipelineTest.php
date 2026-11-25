<?php

declare(strict_types=1);

/**
 * Modèle arrêté par son éditeur, de bout en bout sur la base de test : la
 * veille il est proposé comme les autres, le jour même il quitte
 * LLMFactory::available(), le défaut enregistré qui le désignait cède la place
 * au défaut effectif, et le modèle enregistré avec la clé n'est plus servi.
 * Rien n'est écrit en base : remettre la date en arrière le rend.
 */
final class LlmRetirementPipelineTest extends DatabaseTestCase
{
    private mixed $defautAvant = null;

    protected function setUp(): void
    {
        parent::setUp();
        // api_keys et llm_models ne sont pas vidées par le socle.
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        $this->defautAvant = Settings::get('default_llm');
        AdminAttention::forget();
    }

    protected function tearDown(): void
    {
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::set('default_llm', $this->defautAvant);
        }
        AdminAttention::forget();
        parent::tearDown();
    }

    private function activer(string $model, string $label): void
    {
        LlmModel::save(['provider' => 'openai', 'model' => $model, 'display_name' => $label, 'is_active' => 1]);
    }

    public function testLeModeleQuitteLeChatLeJourDeSonArret(): void
    {
        ApiKey::save('openai', 'sk-test-factice', 'gpt-4o');
        $this->activer('gpt-4o', 'GPT-4o');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');
        Settings::set('default_llm', 'openai::gpt-4o');

        LlmCatalog::setToday('2027-01-13');
        $this->assertArrayHasKey('openai::gpt-4o', LLMFactory::available());
        $this->assertSame('openai::gpt-4o', LLMFactory::appDefault());
        $this->assertSame('gpt-4o', ApiKey::getModel('openai'));
        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertArrayHasKey('llm_arret_annonce', $constats);

        LlmCatalog::setToday('2027-01-14');
        AdminAttention::forget();
        $dispo = LLMFactory::available();
        $this->assertArrayNotHasKey('openai::gpt-4o', $dispo);
        $this->assertArrayHasKey('openai::gpt-6-luna', $dispo);
        // Le réglage désigne un modèle arrêté : le défaut effectif prend la relève.
        $this->assertSame('openai::gpt-6-luna', LLMFactory::appDefault());
        $this->assertSame('openai::gpt-4o', Settings::get('default_llm'), 'rien n\'est écrit en base');
        // Le modèle de la clé n'est plus servi : les appelants retombent sur le catalogue.
        $this->assertNull(ApiKey::getModel('openai'));

        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertArrayHasKey('llm_modeles_arretes', $constats);
        $this->assertArrayNotHasKey('llm_arret_annonce', $constats);
        $this->assertArrayHasKey('llm_defaut_a_confirmer', $constats);
    }

    /**
     * Le défaut corrigé : un modèle activé avant d'être retiré du catalogue de
     * MIA (o4-mini) restait dans le chat après sa coupure, et un modèle de
     * référence de clé saisi en texte libre (gpt-5-nano) restait servi. Leur
     * arrêt est désormais connu (`retired_models`), sans écriture en base.
     */
    public function testUnModeleHorsCatalogueQuitteLeChatASaDate(): void
    {
        ApiKey::save('openai', 'sk-test-factice', 'gpt-5-nano');
        $this->activer('o4-mini', 'o4-mini');
        $this->activer('gpt-5-2025-08-07', 'GPT-5 (figé)');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');

        LlmCatalog::setToday('2026-10-22');
        $dispo = LLMFactory::available();
        $this->assertArrayHasKey('openai::o4-mini', $dispo);
        $this->assertArrayHasKey('openai::gpt-5-2025-08-07', $dispo);
        $this->assertSame('gpt-5-nano', ApiKey::getModel('openai'));
        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertStringContainsString('o4-mini (le 23/10/2026, remplaçant : GPT-5.6 Terra)', $constats['llm_arret_annonce']['text']);
        $this->assertStringContainsString('GPT-5 (figé) (le 11/12/2026, remplaçant : GPT-5.6 Sol)', $constats['llm_arret_annonce']['text']);

        LlmCatalog::setToday('2026-11-12');
        AdminAttention::forget();
        $dispo = LLMFactory::available();
        $this->assertArrayNotHasKey('openai::o4-mini', $dispo);
        $this->assertArrayHasKey('openai::gpt-5-2025-08-07', $dispo, 'encore servi jusqu\'au 11/12/2026');
        $this->assertSame('gpt-5-nano', ApiKey::getModel('openai'));

        LlmCatalog::setToday('2026-12-11');
        AdminAttention::forget();
        $dispo = LLMFactory::available();
        $this->assertSame(['openai::gpt-6-luna'], array_keys($dispo));
        $this->assertNull(ApiKey::getModel('openai'), 'le modèle de la clé n\'est plus servi');
        $this->assertSame(3, (int)$this->db()->query("SELECT COUNT(*) FROM llm_models")->fetchColumn(), 'rien n\'est écrit en base');
        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertStringContainsString('o4-mini', $constats['llm_modeles_arretes']['text']);
        $this->assertStringContainsString('GPT-5 (figé)', $constats['llm_modeles_arretes']['text']);
    }

    /**
     * Autres éditeurs : DeepSeek V3 resté activé avec son modèle de clé,
     * Mistral Medium 3.1 activé à côté de Mistral Large 4.
     */
    public function testUnAncienModeleDUnAutreEditeurQuitteLeChat(): void
    {
        ApiKey::save('deepseek', 'sk-test-factice', 'deepseek-chat');
        ApiKey::save('mistral', 'sk-test-factice', 'mistral-medium-2508');
        LlmModel::save(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'display_name' => 'DeepSeek V3', 'is_active' => 1]);
        LlmModel::save(['provider' => 'mistral', 'model' => 'mistral-medium-2508', 'display_name' => 'Mistral Medium 3.1', 'is_active' => 1]);
        LlmModel::save(['provider' => 'mistral', 'model' => 'mistral-large-4-0', 'display_name' => 'Mistral Large 4', 'is_active' => 1]);

        LlmCatalog::setToday('2026-07-23');
        $dispo = LLMFactory::available();
        $this->assertArrayHasKey('deepseek::deepseek-chat', $dispo);
        $this->assertArrayHasKey('mistral::mistral-medium-2508', $dispo);
        $this->assertSame('deepseek-chat', ApiKey::getModel('deepseek'));

        LlmCatalog::setToday('2026-11-12');
        AdminAttention::forget();
        $dispo = LLMFactory::available();
        $this->assertArrayNotHasKey('deepseek::deepseek-chat', $dispo);
        $this->assertArrayNotHasKey('mistral::mistral-medium-2508', $dispo);
        $this->assertArrayHasKey('mistral::mistral-large-4-0', $dispo);
        $this->assertNull(ApiKey::getModel('deepseek'), 'le défaut du catalogue (V4.1 Flash) prend la relève');
        $this->assertNull(ApiKey::getModel('mistral'));
        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertStringContainsString('DeepSeek V3', $constats['llm_modeles_arretes']['text']);
        $this->assertStringContainsString('Mistral Medium 3.1', $constats['llm_modeles_arretes']['text']);
    }
}
