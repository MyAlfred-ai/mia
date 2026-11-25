<?php

declare(strict_types=1);

/**
 * Réglages → Usage & crédits → Historique, sur la vraie base : l'origine figée
 * à l'écriture (et reprise par la migration 105), le regroupement par message,
 * les filtres et la recherche, les facettes, la série, les totaux, l'export —
 * toujours la seule personne connectée.
 */
final class UsageHistoryPipelineTest extends DatabaseTestCase
{
    private int $juriste = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
        $this->juriste = $this->insertProfile('Juriste', false);
        $db = $this->db();
        $db->exec("UPDATE profiles SET color = '#ec4899' WHERE id = {$this->juriste}");
        $db->exec("INSERT INTO workflows (id, user_id, name, color, draft_json) VALUES (7, 1, 'Veille concurrence', '#0ea5e9', '{}')");
        $db->exec("INSERT INTO workflow_runs (id, workflow_id, user_id, definition_json) VALUES (70, 7, 1, '{}')");
        $db->exec("INSERT INTO scheduled_tasks (id, user_id, name, instruction, rule_json) VALUES (9, 1, 'Point du lundi', 'Résume', '{}')");
        $db->exec("INSERT INTO conversations (id, user_id, profile_id, title) VALUES (41, 1, {$this->juriste}, 'Contrat de bail')");
        $db->exec("INSERT INTO conversations (id, user_id, title, workflow_run_id) VALUES (42, 1, 'Veille — 3 nov.', 70)");
        $db->exec("INSERT INTO conversations (id, user_id, title, scheduled_task_id) VALUES (43, 1, 'Point du lundi — 2 nov.', 9)");
        $db->exec("INSERT INTO conversations (id, user_id, title) VALUES (44, 2, 'Secret de l’autre')");
        $db->exec("INSERT INTO messages (id, conversation_id, role, content) VALUES (501, 41, 'assistant', 'r'), (502, 42, 'assistant', 'r'), (503, 43, 'assistant', 'r')");
        $db->exec("INSERT INTO chat_tools (id, name, type, provider, model) VALUES (3, 'Images maison', 'image', 'openai', 'gpt-image-2.5-flare')");
        $db->exec("INSERT INTO knowledge_bases (id, name, created_by, embedding_provider, embedding_model, embedding_dims) VALUES (5, 'Procédures', 1, 'openai', 'text-embedding-3-large', 1536)");
    }

    /** Une ligne LLM par le vrai chemin d'écriture, datée. */
    private function llm(array $row, string $at): int
    {
        $id = UsageLog::insertLlm($row + ['user_id' => 1, 'credits_used' => 10]);
        $this->db()->prepare('UPDATE usage_logs SET created_at = ? WHERE id = ?')->execute([$at, $id]);
        return $id;
    }

    private function tool(array $row, string $at): int
    {
        $id = UsageLog::insertTool($row + ['user_id' => 1, 'tool_id' => 3, 'tool_type' => 'image', 'credits_used' => 40]);
        $this->db()->prepare('UPDATE tool_usage_logs SET created_at = ? WHERE id = ?')->execute([$at, $id]);
        return $id;
    }

    private function seed(): void
    {
        // Un tour de chat dans la conversation de l'assistant : réponse, recherche, titre, image — même message.
        $this->llm(['conversation_id' => 41, 'message_id' => 501, 'profile_id' => $this->juriste, 'llm_provider' => 'anthropic', 'model' => 'claude-opus-5',
                    'tokens_input' => 1200, 'tokens_output' => 300, 'credits_used' => 30, 'source' => UsageSource::CHAT], '2026-10-05 09:00:00');
        $this->llm(['conversation_id' => 41, 'message_id' => 501, 'profile_id' => $this->juriste, 'llm_provider' => 'anthropic', 'model' => 'claude-opus-5',
                    'quantity' => 2, 'credits_used' => 6, 'source' => UsageSource::WEB_SEARCH], '2026-10-05 09:00:02');
        $this->llm(['conversation_id' => 41, 'message_id' => 501, 'llm_provider' => 'openai::gpt-5.6', 'credits_used' => 1, 'source' => UsageSource::TITLE], '2026-10-05 09:00:03');
        $this->tool(['conversation_id' => 41, 'message_id' => 501, 'profile_id' => $this->juriste], '2026-10-05 09:00:20');
        // Une étape de workflow, une exécution de tâche (origine figée à l'écriture).
        $this->llm(['conversation_id' => 42, 'message_id' => 502, 'llm_provider' => 'openai', 'model' => 'gpt-5.6', 'credits_used' => 12, 'source' => UsageSource::CHAT], '2026-10-20 08:00:00');
        $this->llm(['conversation_id' => 43, 'message_id' => 503, 'llm_provider' => 'openai', 'model' => 'gpt-5.6', 'credits_used' => 8, 'source' => UsageSource::CHAT], '2026-11-02 08:00:00');
        // Office, alimentation d'une base (hors quota), conception d'un prompt.
        $this->llm(['llm_provider' => 'anthropic', 'model' => 'claude-opus-5', 'credits_used' => 15, 'source' => UsageSource::OFFICE_ADDIN], '2026-11-03 10:00:00');
        $this->llm(['kb_id' => 5, 'llm_provider' => 'openai', 'model' => 'text-embedding-3-large', 'credits_used' => 4, 'quota_exempt' => true, 'source' => UsageSource::KB_EMBEDDING], '2026-11-03 11:00:00');
        $this->llm(['llm_provider' => 'openai', 'model' => 'gpt-5.6', 'credits_used' => 3, 'source' => UsageSource::PROMPT_ASSIST], '2026-11-04 11:00:00');
        // Une autre personne, dans SA conversation : jamais visible.
        $this->llm(['user_id' => 2, 'conversation_id' => 44, 'llm_provider' => 'openai', 'model' => 'gpt-5.6', 'credits_used' => 999, 'source' => UsageSource::CHAT], '2026-11-03 12:00:00');
        // Hors période.
        $this->llm(['llm_provider' => 'openai', 'model' => 'gpt-5.6', 'credits_used' => 50, 'source' => UsageSource::CHAT], '2025-01-10 12:00:00');
    }

    public function testOrigineFigeeALEcritureEtSurvitALaConversation(): void
    {
        $this->seed();
        $stored = $this->db()->query("SELECT conversation_id, origin FROM usage_logs WHERE user_id = 1 AND conversation_id IN (41, 42, 43) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['41' => '', '42' => 'workflow', '43' => 'task'], array_map('strval', $stored));
        $this->assertSame('', (string)$this->db()->query('SELECT origin FROM tool_usage_logs LIMIT 1')->fetchColumn());

        // La conversation du workflow et celle de la tâche disparaissent (élagage) : l'origine reste.
        $this->db()->exec('DELETE FROM conversations WHERE id IN (42, 43)');
        $dims = UsageHistory::dimensions(1, '2026-10-01', '2026-11-30');
        $byOrigin = [];
        foreach ($dims as $d) {
            $byOrigin[$d['origin']] = ($byOrigin[$d['origin']] ?? 0) + $d['credits'];
        }
        $this->assertSame(12, $byOrigin['workflow'] ?? null);
        $this->assertSame(8, $byOrigin['task'] ?? null);
        $this->assertSame(30 + 6 + 1 + 40, $byOrigin['chat'] ?? null);
        $this->assertSame(15, $byOrigin['office'] ?? null);
        $this->assertSame(4, $byOrigin['knowledge'] ?? null);
        $this->assertSame(3, $byOrigin['other'] ?? null);
    }

    public function testLaMigrationRepriseLExistant(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO usage_logs (user_id, conversation_id, llm_provider, credits_used, source, origin) VALUES (1, 42, 'openai', 5, 'chat', ''), (1, 43, 'openai', 5, 'chat', ''), (1, 41, 'openai', 5, 'chat', '')");
        $db->exec("INSERT INTO tool_usage_logs (user_id, conversation_id, tool_id, tool_type, source, credits_used, origin) VALUES (1, 42, 3, 'image', 'chat', 9, '')");
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/105_usage_origin.sql');
        preg_match_all('/^UPDATE .*?;/ms', $sql, $m);
        $this->assertCount(2, $m[0], 'Deux reprises : usage_logs et tool_usage_logs');
        foreach ($m[0] as $statement) {
            $db->exec($statement);
        }
        $this->assertSame(['41' => '', '42' => 'workflow', '43' => 'task'],
            array_map('strval', $db->query('SELECT conversation_id, origin FROM usage_logs ORDER BY conversation_id')->fetchAll(PDO::FETCH_KEY_PAIR)));
        $this->assertSame('workflow', (string)$db->query('SELECT origin FROM tool_usage_logs')->fetchColumn());
    }

    public function testJournalRegroupeParMessageEtNommeLeContexte(): void
    {
        $this->seed();
        $page = UsageHistory::page(1, '2026-10-01', '2026-11-30', UsageHistory::filters([]), 0);
        $this->assertFalse($page['more']);
        $rows = array_map(static fn(array $g): array => UsageHistory::row($g, ['workflow_links' => true]), $page['rows']);
        $this->assertCount(6, $rows, 'Le tour de chat (4 lignes) est UNE opération ; ni l\'autre personne ni le hors-période');
        $this->assertSame(['prompt_assist', 'kb_embedding', 'office_addin', 'chat', 'chat', 'chat'],
            array_map(static fn(array $r): string => $r['lines'][0]['source'], $rows), 'Les plus récentes d\'abord');

        $tour = $rows[5];
        $this->assertSame('m501', $tour['id']);
        $this->assertSame('Réponse', $tour['title'], 'La réponse nomme le tour, pas l\'image ni la recherche');
        $this->assertSame(77, $tour['credits']);
        $this->assertSame('Contrat de bail', $tour['context']['label']);
        $this->assertSame('/chat/41', $tour['context']['href']);
        $this->assertSame('Juriste', $tour['profile']['name']);
        $this->assertSame('#ec4899', $tour['profile']['color']);
        $this->assertSame('Claude Opus 5', $tour['who']);
        $this->assertSame(['Image', 'Recherche Internet', 'Titre de la conversation'], array_column($tour['extras'], 'label'));
        $this->assertSame('Images maison', array_values(array_filter($tour['lines'], static fn(array $l): bool => $l['kind'] === 'tool'))[0]['who']);

        $this->assertSame('Étape de workflow', $rows[4]['title']);
        $this->assertSame('Exécution de la tâche', $rows[3]['title']);
        $this->assertSame('Veille concurrence', $rows[4]['context']['label']);
        $this->assertSame('/workflows/runs/70', $rows[4]['context']['href']);
        $this->assertSame('Point du lundi', $rows[3]['context']['label']);
        $this->assertSame('/chat/43', $rows[3]['context']['href']);
        $this->assertSame('office', $rows[2]['origin']);
        $this->assertSame('Procédures', $rows[1]['context']['label']);
        $this->assertTrue($rows[1]['all_exempt']);

        // Pagination : une de plus est lue pour savoir s'il en reste.
        $first = UsageHistory::page(1, '2026-10-01', '2026-11-30', UsageHistory::filters([]), 0, 4);
        $this->assertTrue($first['more']);
        $next = UsageHistory::page(1, '2026-10-01', '2026-11-30', UsageHistory::filters([]), 4, 4);
        $this->assertFalse($next['more']);
        $this->assertSame(['m502', 'm501'], array_column($next['rows'], 'grp'));
    }

    public function testFiltresEtRecherche(): void
    {
        $this->seed();
        $models = UsageHistory::modelKeys(1, '2026-10-01', '2026-11-30');
        $this->assertContains('anthropic::claude-opus-5', $models);
        $this->assertContains('openai::gpt-5.6', $models, 'llm_provider « fournisseur::modèle » relu comme un modèle');
        $count = fn(array $query): int => UsageHistory::totals(1, '2026-10-01', '2026-11-30', UsageHistory::filters($query), $models)['operations'];
        $credits = fn(array $query): int => UsageHistory::totals(1, '2026-10-01', '2026-11-30', UsageHistory::filters($query), $models)['credits'];

        $this->assertSame(6, $count([]));
        $this->assertSame(1, $count(['origine' => 'workflow']));
        $this->assertSame(1, $count(['origine' => 'task']));
        $this->assertSame(6, $credits(['type' => 'web']), 'Le filtre porte sur les lignes : les seuls crédits des recherches');
        $this->assertSame(1, $count(['type' => 'image']));
        $this->assertSame(2, $count(['ia' => 'anthropic::claude-opus-5']), 'Le tour de chat et la demande dans Office');
        $this->assertSame(1, $count(['ia' => 'openai::gpt-5.6', 'type' => 'assist']), 'Le titre, écrit « openai::gpt-5.6 » dans llm_provider');
        $this->assertSame(1, $count(['assistant' => (string)$this->juriste]));
        // Les filtres portent sur les LIGNES, comme l'anneau « Par assistant » du mois : le titre
        // du tour du Juriste est écrit sans assistant, il compte donc parmi « Sans assistant ».
        $this->assertSame(6, $count(['assistant' => UsageHistory::NO_PROFILE]));
        $this->assertSame(1, $credits(['assistant' => UsageHistory::NO_PROFILE, 'q' => 'bail']), 'Du tour du Juriste, seul le titre');

        // Recherche : titre de conversation, nom de workflow, de tâche, d'outil, de base ; libellé d'un modèle, d'un type.
        $this->assertSame(1, $count(['q' => 'bail']));
        $this->assertSame(1, $count(['q' => 'concurrence']));
        $this->assertSame(1, $count(['q' => 'lundi']));
        $this->assertSame(1, $count(['q' => 'maison']));
        $this->assertSame(1, $count(['q' => 'procedures']), 'Sans accent');
        $this->assertSame(2, $count(['q' => 'Opus']), 'Le nom lisible du modèle, absent de son identifiant en majuscules');
        $this->assertSame(1, $count(['q' => 'images']), 'Le libellé du type');
        $this->assertSame(0, $count(['q' => 'secret']), 'Jamais la conversation d\'une autre personne');
        $this->assertSame(1, $count(['q' => 'bail opus']), 'Chaque mot doit trouver');
        $this->assertSame(0, $count(['q' => '100%']), 'Les jokers de LIKE sont échappés');
    }

    public function testFacettesSerieEtComparaison(): void
    {
        $this->seed();
        $filters = UsageHistory::filters(['origine' => 'chat']);
        $facets = UsageHistory::facets(UsageHistory::dimensions(1, '2026-10-01', '2026-11-30'), $filters);
        $this->assertSame(['chat', 'workflow', 'task', 'office', 'knowledge', 'other'], array_keys($facets['origins']), 'Puces : les autres origines restent visibles, dans l\'ordre du catalogue');
        $this->assertSame(['answer', 'web', 'assist', 'image'], array_keys($facets['types']), 'Types : sous le filtre d\'origine');
        $this->assertSame('Claude Opus 5', $facets['models'][0]['label'], 'Modèle le plus utilisé d\'abord');
        $this->assertSame(['Juriste', 'Sans assistant'], array_column($facets['profiles'], 'label'));

        $series = UsageHistory::series(1, '2026-10-01', '2026-11-30', UsageHistory::filters([]));
        $this->assertSame(77, $series['2026-10-05']['chat']);
        $this->assertSame(['office' => 15, 'knowledge' => 4], $series['2026-11-03']);

        $bars = UsageHistory::bars(['from' => '2026-10-01', 'to' => '2026-11-30'], $series, new DateTimeImmutable('2026-11-09 12:00'));
        $this->assertSame(AiBudget::GRAIN_DAY, $bars['grain']);
        $this->assertSame(77 + 12 + 8 + 15 + 4 + 3, $bars['total']);

        $totals = UsageHistory::totals(1, '2026-10-01', '2026-11-30', UsageHistory::filters([]));
        $this->assertSame(['credits' => 119, 'exempt' => 4, 'operations' => 6, 'lines' => 9], $totals);
        $before = UsageHistory::totals(1, '2024-12-01', '2025-01-31', UsageHistory::filters([]));
        $this->assertSame(50, $before['credits']);
        $this->assertSame('2025-01-10', UsageHistory::firstDay(1));
    }

    public function testExportUneLigneParOperationFacturee(): void
    {
        $this->seed();
        $rows = array_map([UsageHistory::class, 'exportRow'], iterator_to_array(UsageHistory::exportLines(1, '2026-10-01', '2026-11-30', UsageHistory::filters(['origine' => 'chat'])), false));
        $this->assertCount(4, $rows, 'Le tour de chat, ligne par ligne');
        $this->assertSame(UsageHistory::exportHeader()[0], 'Date');
        $image = array_values(array_filter($rows, static fn(array $r): bool => $r[4] === 'Image'))[0];
        $this->assertSame(['2026-10-05', '09:00', 'Conversations', 'Images', 'Image', 'Contrat de bail', 'Images maison', 'Juriste'], array_slice($image, 0, 8));
        $this->assertSame(40, $image[10]);
        $search = array_values(array_filter($rows, static fn(array $r): bool => $r[4] === 'Recherche Internet'))[0];
        $this->assertSame('2 recherches', $search[9]);
    }
}
