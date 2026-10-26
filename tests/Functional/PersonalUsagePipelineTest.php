<?php

declare(strict_types=1);

/**
 * Réglages → Usage & crédits sur la vraie base : le top 10 porte la conversation et
 * l'assistant de chaque opération (pour la rouvrir et la nommer), et le coût
 * par réponse ne compte que les réponses (ni titres, ni embeddings).
 */
final class PersonalUsagePipelineTest extends DatabaseTestCase
{
    public function testTopDixEtReponsesParModele(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        $juriste = $this->insertProfile('Juriste', false);
        $this->db()->exec("INSERT INTO conversations (id, user_id, profile_id, title, created_at, updated_at)
                           VALUES (41, 1, $juriste, 'Contrat', '2026-05-10 09:00:00', '2026-05-10 09:00:00')");
        $this->db()->exec("INSERT INTO chat_tools (id, name, type, provider, model) VALUES (3, 'Images', 'image', 'openai', 'gpt-image-2.5-flare')");

        $ins = $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, conversation_id, profile_id, llm_provider, model, tokens_input, tokens_output, credits_used, cost_eur, source, created_at)
             VALUES (?, ?, ?, ?, ?, 100, 100, ?, 0.01, ?, ?)'
        );
        $ins->execute([1, 41, $juriste, 'anthropic', 'claude-opus-5', 90, 'chat', '2026-05-10 09:00:10']);
        $ins->execute([1, 41, $juriste, 'anthropic', 'claude-opus-5', 30, 'regenerate', '2026-05-10 09:05:00']);
        $ins->execute([1, null, null, 'mistral', 'mistral-large-latest', 8, 'chat', '2026-05-11 10:00:00']);
        $ins->execute([1, 41, $juriste, 'openai', 'gpt-5.6', 2, 'title', '2026-05-10 09:00:12']);       // pas une réponse
        $ins->execute([2, null, null, 'anthropic', 'claude-opus-5', 500, 'chat', '2026-05-12 10:00:00']); // autre personne
        $this->db()->exec("INSERT INTO tool_usage_logs (user_id, conversation_id, profile_id, tool_id, tool_type, source, credits_used, cost_eur, created_at)
                           VALUES (1, 41, $juriste, 3, 'image', 'chat', 120, 0.04, '2026-05-10 09:10:00')");

        $top = UsageLog::topOperations('2026-05-01', '2026-05-31', 1, 10);
        $this->assertSame([120, 90, 30, 8, 2], array_column($top, 'credits_used'), 'Les deux tables, cette personne seulement');
        $this->assertSame('tool', $top[0]['op_type']);
        $this->assertSame('image', $top[0]['tool_type']);
        $this->assertSame(41, $top[0]['conversation_id']);
        $this->assertSame('Juriste', $top[0]['profile_name']);
        $this->assertSame(41, $top[1]['conversation_id']);
        $this->assertSame('Juriste', $top[1]['profile_name']);
        $this->assertNull($top[3]['conversation_id'], 'Sans conversation : rien à rouvrir');
        $this->assertNull($top[3]['profile_name']);

        // Maintenance hors quota : hors du top 10 « quota » et des anneaux.
        $this->db()->exec("INSERT INTO usage_logs (user_id, llm_provider, model, tokens_input, tokens_output, credits_used, cost_eur, quota_exempt, source, created_at)
                           VALUES (1, 'openai', 'text-embedding-3-large', 9000, 0, 999, 0.03, 1, 'kb_embedding', '2026-05-03 07:00:00')");
        $this->assertSame(999, UsageLog::topOperations('2026-05-01', '2026-05-31', 1, 10)[0]['credits_used'], 'Vue complète : la maintenance y figure');
        $this->assertSame(120, UsageLog::topOperations('2026-05-01', '2026-05-31', 1, 10, true)[0]['credits_used'], 'Vue « quota » : elle en sort');

        $split = UsageLog::quotaCreditsSplit('2026-05-01', '2026-05-31', 1);
        // La ligne « Sans assistant » (nom null) est écartée : un null en clé
        // d'index est déprécié en PHP 8.5. Elle est vérifiée juste dessous.
        $profiles = array_column(array_filter($split['profiles'], static fn(array $p): bool => $p['name'] !== null), 'credits', 'name');
        $this->assertSame(242, $profiles['Juriste'], 'Réponses, relance, titre ET image de la conversation d\'assistant');
        $this->assertSame(8, $split['profiles'][1]['credits']);
        $this->assertNull($split['profiles'][1]['profile_id'], 'Sans assistant');
        $this->assertNotContains('text-embedding-3-large', array_column($split['models'], 'model'));
        $this->assertSame(['claude-opus-5', 'mistral-large-latest', 'gpt-5.6'], array_column($split['models'], 'model'));

        $models = UsageLog::responsesByModel('2026-05-01', '2026-05-31', 1);
        $byModel = array_column($models, null, 'model');
        $this->assertSame(['responses' => 2, 'credits' => 120], ['responses' => $byModel['claude-opus-5']['responses'], 'credits' => $byModel['claude-opus-5']['credits']]);
        $this->assertSame(1, $byModel['mistral-large-latest']['responses']);
        $this->assertArrayNotHasKey('gpt-5.6', $byModel, 'Un titre n\'est pas une réponse');
    }
}
