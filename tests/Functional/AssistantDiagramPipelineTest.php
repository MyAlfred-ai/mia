<?php

declare(strict_types=1);

/**
 * Schéma d'un assistant, avec la vraie base : le catalogue (modèles actifs et
 * leur température, défaut effectif, bases du gestionnaire et leurs notes,
 * outils liés) et la configuration d'un assistant enregistré (bases liées,
 * hors périmètre comprises ; outils ; documents de référence).
 *
 * Les formes pures sont gardées par tests/Unit/AssistantDiagramTest.php ; ici,
 * ce que les requêtes rapportent vraiment.
 */
final class AssistantDiagramPipelineTest extends DatabaseTestCase
{
    private mixed $defautAvant = null;

    protected function setUp(): void
    {
        parent::setUp();
        // api_keys et llm_models ne font pas partie des tables vidées par le socle.
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        $this->defautAvant = Settings::get('default_llm');

        ApiKey::save('openai', 'sk-test-factice');
        ApiKey::save('anthropic', 'sk-ant-factice');
        LlmModel::save(['provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'), 'display_name' => 'Défaut OpenAI', 'is_active' => 1, 'sort_order' => 1]);
        LlmModel::save(['provider' => 'anthropic', 'model' => LlmCatalog::defaultModel('anthropic'), 'display_name' => 'Défaut Claude', 'is_active' => 1, 'sort_order' => 2]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
    }

    protected function tearDown(): void
    {
        // Base absente : le socle a sauté le test dans setUp(), PHPUnit appelle tearDown() quand même.
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::set('default_llm', $this->defautAvant);
        }
        parent::tearDown();
    }

    private function base(string $nom, int $createdBy, array $extra = []): int
    {
        return KnowledgeBase::create($extra + [
            'name' => $nom, 'is_active' => 1, 'created_by' => $createdBy,
            'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536,
        ]);
    }

    public function testLeCatalogueDitCeQueLaBaseRapporte(): void
    {
        $admin   = $this->insertUser(1, 'admin');
        $createur = $this->insertUser(2, 'creator');

        $fiches  = $this->base('Fiches clients', $admin);
        $doc = $this->db()->prepare("INSERT INTO knowledge_base_documents (kb_id, file_name, stored_name, mime_type, file_size, extraction_status, vector_status, chunk_count) VALUES (?,?,?,?,?,?,?,?)");
        foreach (['a.pdf', 'b.pdf'] as $f) {
            $doc->execute([$fiches, $f, $f, 'application/pdf', 10, 'done', 'done', 2]);
        }
        $drive   = $this->base('Procédures', $admin);
        KnowledgeBase::setSyncConfig($drive, 'drive', '1AbCdEfGhIjKlMnOp', true);
        $cerveau = $this->base('Cerveau', $admin, ['backend' => KnowledgeBase::BACKEND_MYBRAIN]);
        $eteinte = $this->base('Archives', $admin, ['is_active' => 0]);

        $outil  = ChatTool::save(['name' => 'Illustrations', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2', 'is_active' => 1]);
        $ancien = ChatTool::save(['name' => 'Ancien', 'type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3', 'is_active' => 0]);

        $catalogue = AssistantDiagram::catalog(KnowledgeBase::forManager(User::findById($admin)), ChatTool::findByIds([$outil, $ancien]));
        $kbs = (array)$catalogue['kbs'];
        $this->assertSame('2 docs', $kbs[$fiches]['note']);
        $this->assertSame('Google Drive · aucun document', $kbs[$drive]['note']);
        $this->assertTrue($kbs[$cerveau]['live']);
        $this->assertTrue($kbs[$eteinte]['warn']);
        $tools = (array)$catalogue['tools'];
        $this->assertSame('Génération d’image', $tools[$outil]['note']);
        $this->assertSame('Outil désactivé', $tools[$ancien]['note']);

        // Les modèles actifs, avec ce que dit le catalogue de leur température ; le défaut effectif.
        $llms = (array)$catalogue['llms'];
        $openai = 'openai::' . LlmCatalog::defaultModel('openai');
        $this->assertArrayHasKey($openai, $llms);
        $this->assertSame(LLMFactory::supportsTemperature($openai), $llms[$openai]['temp']);
        $this->assertSame('/assets/img/llm-openai.png', $llms[$openai]['logo']);
        $this->assertSame($openai, $catalogue['default_llm']);

        // Un créateur ne voit pas les bases de l'administrateur : elles ne sont pas nommées pour lui.
        $pourLui = (array)AssistantDiagram::catalog(KnowledgeBase::forManager(User::findById($createur)), [])['kbs'];
        $this->assertArrayNotHasKey($fiches, $pourLui);
    }

    public function testLaConfigurationDUnAssistantEnregistre(): void
    {
        $admin = $this->insertUser(1, 'admin');
        $kb    = $this->base('Fiches clients', $admin);
        $outil = ChatTool::save(['name' => 'Illustrations', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2', 'is_active' => 1]);
        $pid = Profile::create([
            'name' => 'Juriste', 'description' => 'Relit vos contrats.', 'system_prompt' => "## Rôle\nTu es juriste.",
            'default_llm' => null, 'is_active' => 1, 'color' => '#8b5cf6', 'temperature' => '0.4',
            'avatar' => 'alfred-loupe.png', 'created_by' => $admin, 'is_personal' => 1,
        ]);
        KnowledgeBase::setForProfile($pid, [$kb], [$kb]);
        Profile::setTools($pid, [$outil]);
        $this->db()->prepare("INSERT INTO profile_documents (profile_id, file_name, stored_name, mime_type, file_size, extracted_text, token_count, extraction_status) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$pid, 'Charte.pdf', 'c.pdf', 'application/pdf', 100, 'texte', 321, 'done']);

        $spec = AssistantDiagram::spec(
            Profile::findById($pid),
            KnowledgeBase::kbIdsForProfile($pid),
            ChatTool::idsForProfile($pid),
            ProfileDocument::allForProfile($pid)
        );
        $this->assertSame('Juriste', $spec['name']);
        $this->assertSame('', $spec['llm'], '« Défaut application » : aucun modèle enregistré.');
        $this->assertSame('0.4', $spec['temperature']);
        $this->assertSame('personal', $spec['sharing']);
        $this->assertSame('/assets/img/profils/alfred-loupe.png', $spec['avatar']);
        $this->assertSame([$kb], $spec['kb_ids']);
        $this->assertSame([$outil], $spec['tool_ids']);
        $this->assertCount(1, $spec['docs']);
        $this->assertSame('Charte.pdf', $spec['docs'][0]['file_name']);
        $this->assertTrue($spec['docs'][0]['has_text']);
        $this->assertSame(321, $spec['docs'][0]['token_count']);
    }
}
