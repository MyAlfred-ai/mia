<?php

declare(strict_types=1);

/**
 * Points à régler de l'administration, de bout en bout sur la base de test :
 * les LECTURES (clés, modèles, assistants et leurs groupes, listes de modèles
 * des groupes, comptes en attente, bases synchronisées) nourrissent bien les
 * formes pures — une requête fausse passerait tous les tests unitaires.
 *
 * On vérifie la présence ou l'absence de constats PRÉCIS, jamais la liste
 * entière : d'autres familles (connecteurs, connexion, worker) dépendent de
 * réglages que ce test ne contrôle pas.
 */
final class AdminAttentionPipelineTest extends DatabaseTestCase
{
    /** Réglages touchés ici, remis en état en sortant. */
    private const REGLAGES = [
        'default_llm',
        'default_llm_fallback',
        KbSync::SETTING_SP_TENANT,
        KbSync::SETTING_SP_CLIENT,
        KbSync::SETTING_SP_SECRET,
    ];

    /** @var array<string, mixed> */
    private array $avant = [];

    protected function setUp(): void
    {
        parent::setUp();
        // api_keys et llm_models ne sont pas vidées par le socle.
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach (self::REGLAGES as $cle) {
            $this->avant[$cle] = Settings::get($cle);
        }
        foreach ([KbSync::SETTING_SP_TENANT, KbSync::SETTING_SP_CLIENT, KbSync::SETTING_SP_SECRET] as $cle) {
            Settings::set($cle, '');
        }
        Settings::set('default_llm_fallback', '');
        AdminAttention::forget();
    }

    protected function tearDown(): void
    {
        // Base absente : le socle a sauté le test, PHPUnit appelle tearDown() quand même.
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->avant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
        }
        AdminAttention::forget();
        parent::tearDown();
    }

    /** @return array<string, array<string,mixed>> constats du moment, par id */
    private function constats(): array
    {
        AdminAttention::forget();
        $out = [];
        foreach (AdminAttention::collect() as $s) {
            $out[$s['id']] = $s;
        }
        return $out;
    }

    private function activer(string $provider, string $model, string $label): void
    {
        LlmModel::save(['provider' => $provider, 'model' => $model, 'display_name' => $label, 'is_active' => 1]);
    }

    public function testSansCleLeConstatDedieSeulParleDesModeles(): void
    {
        $s = $this->constats();
        $this->assertArrayHasKey(AdminAttention::AUCUNE_CLE, $s);
        $this->assertTrue($s[AdminAttention::AUCUNE_CLE]['dedicated']);
        $this->assertNull(AdminAttention::forEntry(array_values($s), 'llms-keys'), 'Rendu par sa propre pastille');
        foreach (['llm_fournisseur_sans_modele', 'llm_modeles_sans_cle', 'llm_defaut_sans_cle'] as $id) {
            $this->assertArrayNotHasKey($id, $s);
        }

        ApiKey::save('openai', 'sk-test');
        $this->activer('openai', LlmCatalog::defaultModel('openai'), 'Défaut OpenAI');
        LLMFactory::repairStoredDefault();
        $this->assertArrayNotHasKey(AdminAttention::AUCUNE_CLE, $this->constats());
    }

    public function testUneCleSansModeleEtUnModeleDontLaCleADisparu(): void
    {
        ApiKey::save('gemini', 'cle-gemini');
        $this->activer('anthropic', 'claude-opus-5', 'Claude Opus 5');
        Settings::set('default_llm', 'anthropic::claude-opus-5');

        $s = $this->constats();
        $this->assertStringContainsString('Gemini', $s['llm_fournisseur_sans_modele']['text']);
        $this->assertStringContainsString('Claude Opus 5', $s['llm_modeles_sans_cle']['text']);
        // Toujours proposé dans le chat : LLMFactory::available ne lit que llm_models.
        $this->assertArrayHasKey('anthropic::claude-opus-5', LLMFactory::available());
        $this->assertSame(AdminAttention::ALERT, $s['llm_defaut_sans_cle']['level']);

        // La clé revient : les trois constats s'éteignent, celui de Gemini reste.
        ApiKey::save('anthropic', 'sk-ant-test');
        $s = $this->constats();
        $this->assertArrayHasKey('llm_fournisseur_sans_modele', $s);
        $this->assertArrayNotHasKey('llm_modeles_sans_cle', $s);
        $this->assertArrayNotHasKey('llm_defaut_sans_cle', $s);
    }

    public function testUnAssistantPartageNestSignaleQueSansGroupeActif(): void
    {
        $id = $this->insertProfile('Juriste', false);
        $this->insertProfile('Carnet perso', true);
        $s = $this->constats();
        $this->assertStringContainsString('« Juriste »', $s['assistant_sans_groupe']['text']);
        $this->assertStringNotContainsString('Carnet perso', $s['assistant_sans_groupe']['text']);

        // Un groupe DÉSACTIVÉ n'ouvre rien (même portée que accessibleByUser).
        $this->linkGroupProfile($this->insertGroup('Archives', null, false), $id);
        $this->assertArrayHasKey('assistant_sans_groupe', $this->constats());

        $this->linkGroupProfile($this->insertGroup('Juridique'), $id);
        $this->assertArrayNotHasKey('assistant_sans_groupe', $this->constats());
    }

    public function testLeModeleImposeDUnAssistantRetireEstSignale(): void
    {
        ApiKey::save('openai', 'sk-test');
        $this->activer('openai', LlmCatalog::defaultModel('openai'), 'Défaut OpenAI');
        LLMFactory::repairStoredDefault();
        $id = $this->insertProfile('Ancien', true);
        $this->db()->prepare('UPDATE profiles SET default_llm = ? WHERE id = ?')->execute(['openai::modele-retire', $id]);

        $s = $this->constats();
        $this->assertStringContainsString('« Ancien »', $s['assistant_modele_retire']['text']);
    }

    public function testLesComptesEnAttenteSontCeuxQuiNontJamaisConverse(): void
    {
        $this->db()->prepare('INSERT INTO users (id, email, name, role, is_active) VALUES (?, ?, ?, ?, 0)')
            ->execute([41, 'nouvelle@test.local', 'Nouvelle Venue', 'user']);
        $this->db()->prepare('INSERT INTO users (id, email, name, role, is_active) VALUES (?, ?, ?, ?, 0)')
            ->execute([42, 'partie@test.local', 'Ancienne Collègue', 'user']);
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([42, 'Une conversation']);
        $this->insertUser(43);

        $s = $this->constats();
        $this->assertStringContainsString('Nouvelle Venue', $s['comptes_en_attente']['text']);
        $this->assertStringNotContainsString('Ancienne Collègue', $s['comptes_en_attente']['text'], 'Désactivée après usage : pas « en attente »');
        $this->assertStringNotContainsString('User 43', $s['comptes_en_attente']['text']);
    }

    public function testUnGroupeActifDontAucunModeleNestDisponible(): void
    {
        ApiKey::save('openai', 'sk-test');
        $this->activer('openai', LlmCatalog::defaultModel('openai'), 'Défaut OpenAI');
        LLMFactory::repairStoredDefault();
        $this->addGroupLlm($this->insertGroup('Commerciaux'), 'anthropic::claude-opus-4-8');
        $this->addGroupLlm($this->insertGroup('Veille', null, false), 'anthropic::claude-opus-4-8');
        $vivant = $this->insertGroup('Direction');
        $this->addGroupLlm($vivant, 'anthropic::claude-opus-4-8');
        $this->addGroupLlm($vivant, 'openai::' . LlmCatalog::defaultModel('openai'));

        $s = $this->constats();
        $this->assertStringContainsString('« Commerciaux »', $s['groupe_sans_modele']['text']);
        $this->assertStringNotContainsString('Veille', $s['groupe_sans_modele']['text'], 'Groupe désactivé : il n\'accorde rien, il ne retire rien');
        $this->assertStringNotContainsString('Direction', $s['groupe_sans_modele']['text']);
    }

    public function testUneBaseSynchroniseeSansIdentifiantsEtUnOutilSansGroupe(): void
    {
        ApiKey::save('openai', 'sk-test');
        $this->db()->prepare(
            'INSERT INTO knowledge_bases
                (name, embedding_provider, embedding_model, embedding_dims, is_active, is_system,
                 sync_provider, sync_folder_ref, sync_enabled, sync_status)
             VALUES (?, ?, ?, ?, 1, 0, ?, ?, 1, ?)'
        )->execute(['Contrats', 'openai', 'text-embedding-3-large', 1536, 'sharepoint', 'https://exemple.sharepoint.com/sites/x', 'ok']);
        $this->db()->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute(['Illustrateur', 'image', 'openai', 'gpt-image-2']);

        $s = $this->constats();
        $this->assertSame('knowledge-bases-sync', $s['bases_source_sans_identifiants']['entry']);
        $this->assertStringContainsString('« Contrats » (SharePoint)', $s['bases_source_sans_identifiants']['text']);
        $this->assertStringContainsString('« Illustrateur »', $s['outils_sans_groupe']['text']);
        $this->assertArrayNotHasKey('bases_sans_vectorisation', $s, 'Une clé OpenAI vectorise');
    }

    public function testLaListeEstMemoriseePourLaRequete(): void
    {
        $premiere = AdminAttention::current();
        $this->insertProfile('Arrivé après', false);
        $this->assertSame($premiere, AdminAttention::current(), 'Même requête : même liste (menu, menu du compte, tableau de bord)');
        AdminAttention::forget();
        $this->assertNotSame($premiere, AdminAttention::current());
    }
}
