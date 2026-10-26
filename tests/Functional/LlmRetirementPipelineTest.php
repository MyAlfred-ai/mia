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
}
