<?php

declare(strict_types=1);

/**
 * Usages → Vue d'ensemble sur la vraie base : trace d'actions lue par
 * JSON_TABLE, réponses en erreur (⚠), relances, assistants, bases endormies,
 * période précédente, filtre par personne.
 */
final class UsageInsightsPipelineTest extends DatabaseTestCase
{
    private function conversation(int $userId, ?int $profileId, string $at): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, profile_id, title, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$userId, $profileId, 'Test', $at, $at]);
        return (int)$this->db()->lastInsertId();
    }

    private function answer(int $convId, string $at, string $content = 'Réponse', ?array $actions = null, string $model = 'claude-opus-5', int $ms = 4000): void
    {
        $this->db()->prepare(
            'INSERT INTO messages (conversation_id, role, llm_provider, llm_model, response_ms, content, actions_json, created_at)
             VALUES (?, \'assistant\', ?, ?, ?, ?, ?, ?)'
        )->execute([$convId, 'anthropic', $model, $ms, $content, $actions !== null ? json_encode($actions) : null, $at]);
    }

    private function ledger(int $userId, string $source, string $at, ?int $profileId = null): void
    {
        $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, profile_id, llm_provider, model, tokens_input, tokens_output, credits_used, cost_eur, source, created_at)
             VALUES (?, ?, \'anthropic\', \'claude-opus-5\', 10, 10, 5, 0.01, ?, ?)'
        )->execute([$userId, $profileId, $source, $at]);
    }

    public function testVueDEnsembleSurLaVraieBase(): void
    {
        $this->insertUser(1, 'admin');
        $this->insertUser(2);
        $this->insertUser(3);
        $juriste = $this->insertProfile('Juriste', false);
        $this->insertProfile('Inutilisé', false);

        $c1 = $this->conversation(1, $juriste, '2026-05-10 09:00:00');
        $this->answer($c1, '2026-05-10 09:00:10', 'Réponse', [['t' => 'gmail_search', 'l' => 'x', 'ok' => true], ['t' => 'searching_knowledge_bases', 'l' => 'x', 'ok' => true]], 'claude-opus-5', 2000);
        $this->answer($c1, '2026-05-11 09:00:10', 'Réponse', [['t' => 'drive_read', 'l' => 'x', 'ok' => true]], 'claude-opus-5', 6000);
        $c2 = $this->conversation(2, null, '2026-05-12 09:00:00');
        $this->answer($c2, '2026-05-12 09:00:10', "⚠️ Service momentanément saturé\n\nRéessayez.", null);
        $this->answer($c2, '2026-05-12 10:00:10', 'Réponse', [['t' => 'searching_knowledge_bases', 'l' => 'x', 'ok' => true]], 'claude-opus-5', 4000);
        $this->db()->exec("UPDATE messages SET actions_json = 'pas du json' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM messages) t)");
        $c3 = $this->conversation(3, null, '2026-04-20 09:00:00');                // période précédente
        $this->answer($c3, '2026-04-20 09:00:10', 'Réponse', [['t' => 'gmail_search', 'l' => 'x', 'ok' => true]]);

        foreach (['chat', 'chat', 'chat', 'regenerate'] as $i => $s) {
            $this->ledger(1, $s, '2026-05-1' . ($i + 1) . ' 12:00:00', $juriste);
        }
        $this->ledger(2, 'chat', '2026-05-12 12:00:00');
        $this->ledger(3, 'chat', '2026-04-20 12:00:00');

        $this->db()->exec("INSERT INTO knowledge_bases (name, embedding_provider, embedding_model, embedding_dims, is_active, query_count, last_used_at)
                           VALUES ('Vivante', 'openai', 'x', 1536, 1, 12, NOW()), ('Endormie', 'openai', 'x', 1536, 1, 4, NOW() - INTERVAL 40 DAY),
                                  ('Jamais', 'openai', 'x', 1536, 1, 0, NULL), ('Éteinte', 'openai', 'x', 1536, 0, 0, NULL)");

        $o = UsageInsights::overview('2026-05-01', '2026-05-31', null, [
            ['category' => 'chat', 'label' => 'Conversations', 'color' => '#6366f1', 'credits' => 75, 'quota_exempt' => false],
            ['category' => 'knowledge', 'label' => 'Bases', 'color' => '#10b981', 'credits' => 25, 'quota_exempt' => true],
        ]);

        $this->assertSame(3, $o['accounts']);
        $this->assertSame(4, $o['responses']);
        $this->assertSame(1, $o['errors'], 'La réponse ⚠ et elle seule');
        $this->assertSame(25.0, $o['error_rate']);
        $this->assertSame(20.0, $o['regen_rate'], '1 relance pour 4 réponses');
        $this->assertSame(4000.0, $o['median_ms'], 'Médiane des réponses abouties seulement');

        $used = array_column($o['adoption']['used'], null, 'key');
        // Le premier message a un JSON invalide : ignoré sans casser la lecture.
        $this->assertSame(['people' => 1, 'uses' => 1], ['people' => $used['connectors']['people'], 'uses' => $used['connectors']['uses']]);
        $this->assertSame(1, $used['kb']['uses'], 'Une consultation lisible (l\'autre est dans le JSON invalide)');
        $this->assertSame(0, $used['connectors']['trend'], 'Avril : un Gmail ; mai : un Drive');
        $this->assertSame(1, $used['assistants']['people']);
        $this->assertSame(2, $used['chat']['people']);
        $this->assertSame(400, $used['chat']['trend'], 'Avril : 1 réponse ; mai : 5 opérations');
        $this->assertContains('Vidéos', $o['adoption']['unused']);

        $this->assertSame('Juriste', $o['assistants'][0]['label']);
        $this->assertSame(1, $o['assistants'][0]['conversations']);
        $this->assertSame(25.0, $o['assistants'][0]['regen_rate']);
        $this->assertSame(1, $o['idle_assistants'], '« Inutilisé » n\'a aucune conversation');

        $this->assertSame(['Vivante', 'Endormie'], array_column($o['kbs'], 'name'));
        $this->assertSame(['Endormie', 'Jamais'], array_values(array_intersect(['Endormie', 'Jamais'], $o['kb_dormant'])));
        $this->assertNotContains('Éteinte', $o['kb_dormant'], 'Une base désactivée ne dort pas, elle est éteinte');
        $this->assertCount(2, $o['treemap']);
        $this->assertSame(75.0, $o['treemap'][0]['share']);

        // Filtre par personne.
        $solo = UsageInsights::overview('2026-05-01', '2026-05-31', 2, []);
        $this->assertSame(1, $solo['accounts']);
        $this->assertSame(2, $solo['responses']);
        $this->assertNull(array_column($solo['adoption']['used'], null, 'key')['connectors'] ?? null, 'La personne 2 n\'a touché à aucun connecteur');
    }
}
