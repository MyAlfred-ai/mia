<?php

declare(strict_types=1);

/**
 * Instance neuve : les membres du seul groupe « ★ Par défaut » doivent avoir
 * un modèle, même quand `default_llm` vaut encore la valeur semée « openai ».
 *
 * Avant : la liste blanche valait ['openai'], intersectée avec des modèles
 * `provider::modèle` elle était vide, et chaque message répondait « Ce LLM
 * n'est pas autorisé pour votre groupe. ».
 */
final class LlmDefaultGroupTest extends DatabaseTestCase
{
    private mixed $defautAvant  = null;
    private mixed $secoursAvant = null;

    protected function setUp(): void
    {
        parent::setUp();
        // api_keys et llm_models ne font pas partie des tables vidées par le
        // socle : on les isole ici, et on les rend vides en sortant.
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        $this->defautAvant  = Settings::get('default_llm');
        $this->secoursAvant = Settings::get('default_llm_fallback');

        // Un modèle premium activé AVANT le modèle par défaut du catalogue :
        // en tête de liste, c'est lui qu'une règle « premier de la liste »
        // aurait imposé à tout le groupe « ★ Par défaut ».
        ApiKey::save('openai', 'sk-test-factice');
        $this->activer('openai', 'gpt-6-astra', 'GPT-6 Astra');
        $this->activer('openai', self::catalogue(), 'Défaut du catalogue');
    }

    /** Modèle par défaut du catalogue OpenAI — lu, jamais recopié. */
    private static function catalogue(): string
    {
        return LlmCatalog::defaultModel('openai');
    }

    protected function tearDown(): void
    {
        // Base absente : le socle a sauté le test dans setUp(), mais PHPUnit
        // appelle tearDown() quand même — rien à remettre en état.
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::set('default_llm', $this->defautAvant);
            Settings::set('default_llm_fallback', $this->secoursAvant);
        }
        parent::tearDown();
    }

    public function testLeGroupeParDefautAUnModeleSurUneInstanceNeuve(): void
    {
        Settings::set('default_llm', 'openai'); // valeur semée par 004_settings.sql
        $groupe = $this->groupeParDefaut();

        $this->assertSame(['openai::' . self::catalogue()], UserGroup::allowedLlmKeysForUser([$groupe]));
    }

    public function testSansGroupeActifLeDefautEffectifEstAutorise(): void
    {
        Settings::set('default_llm', 'openai');

        $this->assertSame(['openai::' . self::catalogue()], UserGroup::allowedLlmKeysForUser([]));
    }

    public function testUnDefautEnregistreResteLeSeulModeleDuGroupeParDefaut(): void
    {
        Settings::set('default_llm', 'openai::gpt-6-astra');
        $groupe = $this->groupeParDefaut();

        $this->assertSame(['openai::gpt-6-astra'], UserGroup::allowedLlmKeysForUser([$groupe]));
    }

    public function testLaReparationEnregistreLeDefautEffectif(): void
    {
        Settings::set('default_llm', 'openai');

        $this->assertSame('openai::' . self::catalogue(), LLMFactory::repairStoredDefault());
        Settings::refresh();
        $this->assertSame('openai::' . self::catalogue(), Settings::get('default_llm'));
    }

    public function testLaReparationNeTouchePasUnDefautValide(): void
    {
        Settings::set('default_llm', 'openai::gpt-6-astra');

        $this->assertNull(LLMFactory::repairStoredDefault());
        Settings::refresh();
        $this->assertSame('openai::gpt-6-astra', Settings::get('default_llm'));
    }

    public function testLaReparationVideUnSecoursDevenuIdentiqueAuDefaut(): void
    {
        Settings::set('default_llm', 'openai');
        Settings::set('default_llm_fallback', 'openai::' . self::catalogue());

        LLMFactory::repairStoredDefault();
        Settings::refresh();
        $this->assertSame('', (string)Settings::get('default_llm_fallback'));
    }

    public function testSansAucunModeleLaReparationNeTouchePasAuReglage(): void
    {
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::set('default_llm', 'openai::' . self::catalogue());

        $this->assertNull(LLMFactory::repairStoredDefault());
        Settings::refresh();
        $this->assertSame('openai::' . self::catalogue(), Settings::get('default_llm'));
    }

    private function activer(string $provider, string $model, string $label): void
    {
        LlmModel::save([
            'provider'     => $provider,
            'model'        => $model,
            'display_name' => $label,
            'is_active'    => 1,
            'sort_order'   => 0,
        ]);
    }

    private function groupeParDefaut(): int
    {
        $this->db()->prepare('INSERT INTO user_groups (name, is_default, is_active) VALUES (?, 1, 1)')
            ->execute(['Par défaut']);
        return (int)$this->db()->lastInsertId();
    }
}
