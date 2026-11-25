<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réglages → Usage & crédits → Historique : formes pures (UsageHistory) et
 * câblage. Les lectures sur la vraie base sont gardées par
 * tests/Functional/UsageHistoryPipelineTest.php.
 */
final class UsageHistoryTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    /** Noms des colonnes d'une liste SELECT : l'alias, sinon la colonne (virgules de premier niveau). */
    private static function selectNames(string $list): array
    {
        $parts = [];
        $depth = 0;
        $quote = false;
        $cur = '';
        foreach (str_split($list) as $ch) {
            if ($ch === "'") {
                $quote = !$quote;
            } elseif (!$quote && $ch === '(') {
                $depth++;
            } elseif (!$quote && $ch === ')') {
                $depth--;
            } elseif (!$quote && $depth === 0 && $ch === ',') {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $parts[] = trim($cur);
        return array_map(static function (string $p): string {
            if (preg_match('/\bAS (\w+)$/', $p, $m)) {
                return $m[1];
            }
            return (string)preg_replace('/^\w+\./', '', $p);
        }, $parts);
    }

    // ---- Catalogue ---------------------------------------------------------------

    public function testChaqueSourceAUnTypeUnSeulEtUnNomAuSingulier(): void
    {
        $sources = [];
        $ref = new ReflectionClassConstant(UsageHistory::class, 'TYPES');
        foreach ($ref->getValue() as $key => $type) {
            foreach ($type['llm'] ?? [] as $source) {
                $this->assertArrayNotHasKey($source, $sources, "$source est rangée dans deux types");
                $sources[$source] = $key;
            }
        }
        foreach (UsageSource::all() as $source) {
            $this->assertArrayHasKey($source, $sources, "La source « $source » n'a aucun type d'usage : l'historique la rangerait parmi les réponses.");
            $this->assertSame($sources[$source], UsageHistory::typeOf('llm', $source));
            $this->assertContains($source, UsageHistory::labelledSources(), "La source « $source » n'a pas de nom au singulier.");
        }
        $this->assertSame(UsageSource::all(), array_values(array_intersect(UsageSource::all(), array_keys($sources))));
        $this->assertSame([], array_diff(UsageHistory::labelledSources(), UsageSource::all()), 'Aucun nom pour une source qui n\'existe plus');
    }

    public function testTypesDesLignesDOutil(): void
    {
        $this->assertSame('image', UsageHistory::typeOf('tool', 'chat', 'image'));
        $this->assertSame('page', UsageHistory::typeOf('tool', 'chat', 'html_illustrated'));
        $this->assertSame('presentation', UsageHistory::typeOf('tool', 'chat', 'gamma'));
        $this->assertSame('automation', UsageHistory::typeOf('tool', 'chat', 'n8n'));
        $this->assertSame('tool', UsageHistory::typeOf('tool', 'chat', 'inconnu'));
        $this->assertSame('admin', UsageHistory::typeOf('tool', UsageSource::TOOL_TEST, 'image'), 'Un test d\'outil reste de l\'administration');
        $this->assertSame('answer', UsageHistory::typeOf('llm', 'source_inconnue'), 'Comme UsageSource::normalize');
    }

    public function testChaqueLibelleDuCatalogueEstTraduit(): void
    {
        $dict = require dirname(__DIR__, 2) . '/lang/en.php';
        foreach (['ORIGINS', 'TYPES'] as $const) {
            foreach ((new ReflectionClassConstant(UsageHistory::class, $const))->getValue() as $key => $entry) {
                foreach (['label', 'hint'] as $field) {
                    if (isset($entry[$field])) {
                        $this->assertArrayHasKey($entry[$field], $dict, "{$const}[{$key}][{$field}] sans traduction");
                    }
                }
            }
        }
        foreach (['OPERATION_LABELS', 'TOOL_LABELS'] as $const) {
            foreach ((new ReflectionClassConstant(UsageHistory::class, $const))->getValue() as $key => $label) {
                $this->assertArrayHasKey($label, $dict, "{$const}[{$key}] sans traduction");
            }
        }
        $this->assertArrayHasKey('Utilisation d’un outil', $dict);
    }

    public function testOrigineDUneLigne(): void
    {
        $o = [UsageHistory::class, 'originOf'];
        $this->assertSame('office', $o('llm', UsageSource::OFFICE_ADDIN, '', false));
        $this->assertSame('office', $o('llm', UsageSource::OFFICE_SEARCH, 'workflow', true), 'Office se lit sur la source, avant tout');
        $this->assertSame('workflow', $o('llm', UsageSource::CHAT, 'workflow', false), 'Figée à l\'écriture : survit à la conversation');
        $this->assertSame('task', $o('llm', UsageSource::WEB_SEARCH, 'task', true));
        $this->assertSame('workflow', $o('llm', UsageSource::WORKFLOW_JUDGE, '', false));
        $this->assertSame('workflow', $o('llm', UsageSource::WORKFLOW_COMPOSE, '', false));
        $this->assertSame('knowledge', $o('llm', UsageSource::KB_EMBEDDING, '', false));
        $this->assertSame('chat', $o('llm', UsageSource::CHAT, '', true));
        $this->assertSame('chat', $o('llm', UsageSource::CHAT, '', false), 'Conversation supprimée : la source dit « chat »');
        $this->assertSame('other', $o('llm', UsageSource::PROMPT_ASSIST, '', false));
        $this->assertSame('other', $o('llm', UsageSource::MEMORY_LEARN, '', false));
        $this->assertSame('chat', $o('llm', UsageSource::MEMORY_LEARN, '', true));
        $this->assertSame('chat', $o('tool', 'chat', '', false));
        $this->assertSame('other', $o('tool', UsageSource::TOOL_TEST, '', false));
        $this->assertSame('workflow', $o('tool', 'chat', 'workflow', true));
    }

    public function testLeSqlDOrigineEtDeTypeSuitLesMemesRegles(): void
    {
        // Le CASE SQL est bâti depuis le même catalogue que originOf/typeOf : chaque source y figure
        // à la place que la forme pure lui donne.
        $type = UsageHistory::sqlType('llm', 'l');
        foreach (UsageSource::all() as $source) {
            $this->assertStringContainsString("WHEN '$source' THEN '" . UsageHistory::typeOf('llm', $source) . "'", $type);
        }
        $origin = UsageHistory::sqlOrigin('llm', 'l');
        $this->assertStringStartsWith("CASE WHEN l.source IN ('office_addin', 'office_search') THEN 'office' WHEN l.origin IN ('workflow', 'task') THEN l.origin", $origin);
        $this->assertStringContainsString("'kb_embedding'", $origin);
        $this->assertStringContainsString("WHEN l.conversation_id IS NOT NULL THEN 'chat'", $origin);
        $this->assertStringEndsWith("ELSE 'chat' END", $origin);
        $this->assertStringContainsString("WHEN t.source = 'tool_test' THEN 'admin'", UsageHistory::sqlType('tool', 't'));
        $this->assertStringContainsString("WHEN t.tool_type IN ('html', 'html_illustrated') THEN 'page'", UsageHistory::sqlType('tool', 't'));
    }

    // ---- Filtres ------------------------------------------------------------------

    public function testFiltresLusDansLAdresse(): void
    {
        $f = UsageHistory::filters([
            'origine' => 'workflow', 'type' => 'image', 'ia' => 'anthropic::claude-opus-5',
            'assistant' => '12', 'q' => "  contrat\n de  bail ",
        ]);
        $this->assertSame(['origin' => 'workflow', 'type' => 'image', 'model' => 'anthropic::claude-opus-5', 'profile' => '12', 'q' => 'contrat de bail'], $f);
        $this->assertSame(['origine' => 'workflow', 'type' => 'image', 'ia' => 'anthropic::claude-opus-5', 'assistant' => '12', 'q' => 'contrat de bail'], UsageHistory::query($f));
        $this->assertTrue(UsageHistory::isFiltered($f));

        $bad = UsageHistory::filters(['origine' => 'pirate', 'type' => "image'; DROP", 'ia' => 'sans-separateur', 'assistant' => '-3', 'q' => str_repeat('x', 300)]);
        $this->assertSame('', $bad['origin']);
        $this->assertSame('', $bad['type']);
        $this->assertSame('', $bad['model']);
        $this->assertSame('', $bad['profile']);
        $this->assertSame(UsageHistory::SEARCH_MAX, mb_strlen($bad['q']));
        $this->assertSame(UsageHistory::NO_PROFILE, UsageHistory::filters(['assistant' => UsageHistory::NO_PROFILE])['profile']);
        $this->assertFalse(UsageHistory::isFiltered(UsageHistory::filters([])));
        $this->assertSame('openai::gpt-5.6', UsageHistory::validModelKey('openai::gpt-5.6'));
        $this->assertSame('', UsageHistory::validModelKey("openai::g\x01pt"), 'Aucun caractère de contrôle');
        $this->assertSame('', UsageHistory::validModelKey('OpenAI::gpt-5.6'), 'Fournisseur en minuscules, comme le grand livre');
    }

    public function testRechercheParMotsEtParLibelles(): void
    {
        $this->assertSame(['contrat', 'Opus'], UsageHistory::searchWords('« contrat » a Opus contrat'));
        $this->assertSame('%100!%!_!!%', UsageHistory::likePattern('100%_!'));

        $m = UsageHistory::labelMatches('image', ['anthropic::claude-opus-5']);
        $this->assertContains('image', $m['types']);
        $this->assertContains(UsageSource::IMAGE_PROMPT, $m['sources'], '« Préparation d’une image »');
        $this->assertContains(UsageSource::KB_VISION, $m['sources'], '« Description d’une image »');
        $this->assertContains('image', $m['tools']);
        $this->assertSame([], $m['models']);
        $this->assertSame(['anthropic::claude-opus-5'], UsageHistory::labelMatches('OPUS', ['anthropic::claude-opus-5', 'openai::gpt-5.6'])['models']);
        $this->assertContains('task', UsageHistory::labelMatches('tâches')['origins']);
        $this->assertContains('task', UsageHistory::labelMatches('taches')['origins'], 'Sans accent');

        [$where, $params] = UsageHistory::whereSql(['q' => 'bail opus'], ['anthropic::claude-opus-5']);
        $this->assertSame(2, substr_count($where, "x.conv_title LIKE ? ESCAPE '!'"), 'Chaque mot cherche partout');
        $this->assertStringContainsString("CONCAT(x.provider, '::', x.model) IN (?)", $where);
        $this->assertContains('%bail%', $params);
        $this->assertContains('anthropic::claude-opus-5', $params);
        $this->assertSame(substr_count($where, '?'), count($params), 'Autant de paramètres que de marques');
    }

    public function testClauseDesFiltresEtExceptions(): void
    {
        $f = UsageHistory::filters(['origine' => 'task', 'type' => 'web', 'ia' => 'openai::gpt-5.6', 'assistant' => UsageHistory::NO_PROFILE]);
        [$where, $params] = UsageHistory::whereSql($f);
        $this->assertSame("WHERE (x.origin = ?) AND (x.type = ?) AND (x.kind = 'llm' AND x.provider = ? AND x.model = ?) AND (x.profile_id IS NULL)", $where);
        $this->assertSame(['task', 'web', 'openai', 'gpt-5.6'], $params);
        [$sansOrigine] = UsageHistory::whereSql($f, [], ['origin']);
        $this->assertStringNotContainsString('x.origin', $sansOrigine);
        $this->assertSame(['', []], UsageHistory::whereSql(UsageHistory::filters([])));
    }

    public function testLesSousRequetesDesDeuxTablesOntLeMemeSchema(): void
    {
        foreach ([true, false] as $labels) {
            $cols = [];
            foreach (['llm', 'tool'] as $kind) {
                $sql = UsageHistory::linesSql($kind, $labels);
                preg_match('/^SELECT (.*) FROM (?:usage_logs|tool_usage_logs) /s', $sql, $m);
                $cols[$kind] = self::selectNames($m[1]);
                $this->assertStringEndsWith('.user_id = ? AND ' . ($kind === 'tool' ? 't' : 'l') . '.created_at BETWEEN ? AND ?', $sql, 'Toujours la seule personne, sur la période');
            }
            $this->assertSame($cols['llm'], $cols['tool'], 'Mêmes alias, même ordre : sinon l\'UNION mélange les colonnes');
            $this->assertContains('origin', $cols['llm']);
            $this->assertContains('grp', $cols['llm']);
        }
        $labelled = UsageHistory::linesSql('llm', true);
        $this->assertStringContainsString('LEFT JOIN conversations c ON c.id = l.conversation_id AND c.user_id = l.user_id', $labelled, 'Le titre d\'une conversation d\'autrui n\'est jamais lu');
        $this->assertStringContainsString('w.user_id = l.user_id', $labelled);
        $this->assertStringContainsString('st.user_id = l.user_id', $labelled);
        $this->assertStringContainsString('kb.is_private = 1', $labelled, 'Une base privée d\'autrui n\'est pas nommée');
        $this->assertStringNotContainsString('JOIN conversations', UsageHistory::linesSql('llm', false), 'Sans recherche, les agrégats ne joignent rien de plus');
        [$union, $params] = UsageHistory::unionSql(3, '2026-10-01', '2026-10-31', false);
        $this->assertSame([3, '2026-10-01 00:00:00', '2026-10-31 23:59:59', 3, '2026-10-01 00:00:00', '2026-10-31 23:59:59'], $params);
        $this->assertStringContainsString(' UNION ALL ', $union);
    }

    // ---- Facettes, histogramme, journal ---------------------------------------------

    public function testFacettes(): void
    {
        $d = static fn(string $o, string $t, string $k, string $p, string $m, ?int $pid, int $c, ?string $name = null): array => [
            'origin' => $o, 'type' => $t, 'kind' => $k, 'provider' => $p, 'model' => $m,
            'profile_id' => $pid, 'profile_name' => $name, 'profile_color' => $pid ? '#ec4899' : null, 'lines' => 1, 'credits' => $c,
        ];
        $dims = [
            $d('chat', 'answer', 'llm', 'anthropic', 'claude-opus-5', 4, 100, 'Juriste'),
            $d('chat', 'web', 'llm', 'anthropic', 'claude-opus-5', 4, 20, 'Juriste'),
            $d('workflow', 'answer', 'llm', 'openai', 'gpt-5.6', null, 50),
            $d('chat', 'image', 'tool', '', '', null, 40),
        ];
        $f = UsageHistory::facets($dims, UsageHistory::filters(['type' => 'answer']));
        $this->assertSame(['chat' => ['credits' => 100, 'lines' => 1], 'workflow' => ['credits' => 50, 'lines' => 1]], $f['origins'], 'Origines sous le filtre de type');
        $this->assertSame(['answer', 'web', 'image'], array_keys($f['types']), 'Types sans leur propre filtre, ordre du catalogue');
        $this->assertSame(['anthropic::claude-opus-5', 'openai::gpt-5.6'], array_column($f['models'], 'key'));
        $this->assertSame(['Juriste', 'Sans assistant'], array_column($f['profiles'], 'label'));
        $this->assertSame('#ec4899', $f['profiles'][0]['color']);

        $kept = UsageHistory::facets([], UsageHistory::filters(['ia' => 'mistral::mistral-large-latest', 'assistant' => '99']));
        $this->assertSame(['mistral::mistral-large-latest'], array_column($kept['models'], 'key'), 'Un filtre posé reste dans son sélecteur');
        $this->assertSame(['99'], array_column($kept['profiles'], 'key'));
    }

    public function testHistogrammeEmpileParOrigine(): void
    {
        $now = new DateTimeImmutable('2026-10-12 15:00');
        $bars = UsageHistory::bars(['from' => '2026-10-01', 'to' => '2026-10-12'], [
            '2026-10-02' => ['task' => 30, 'chat' => 70],
            '2026-10-12' => ['office' => 10],
        ], $now);
        $this->assertSame(AiBudget::GRAIN_DAY, $bars['grain']);
        $this->assertCount(12, $bars['bars']);
        $this->assertSame(110, $bars['total']);
        $b = $bars['bars'][1];
        $this->assertSame(['chat', 'task'], array_column($b['segments'], 'origin'), 'Ordre du catalogue, du bas vers le haut');
        $this->assertSame(0.0, $b['segments'][0]['bottom']);
        $this->assertSame($b['segments'][0]['h'], $b['segments'][1]['bottom']);
        $this->assertTrue($bars['bars'][11]['current']);
        $this->assertSame(['chat', 'task', 'office'], $bars['origins']);
        $this->assertSame(100, $bars['peak']['credits']);
        $this->assertSame(9, $bars['average'], '110 crédits sur 12 jours');
        $this->assertSame(0, $bars['y_ticks'][0]['v']);
        $this->assertGreaterThanOrEqual(100, end($bars['y_ticks'])['v']);

        $months = UsageHistory::bars(['from' => '2025-12-01', 'to' => '2026-11-09'], ['2026-03-15' => ['chat' => 5]], new DateTimeImmutable('2026-11-09'));
        $this->assertSame(AiBudget::GRAIN_MONTH, $months['grain']);
        $this->assertCount(12, $months['bars']);
        $this->assertTrue(end($months['bars'])['partial'], 'Novembre entamé');
        $empty = UsageHistory::bars(['from' => '2026-10-01', 'to' => '2026-10-07'], [], $now);
        $this->assertNull($empty['peak']);
        $this->assertNull($empty['average']);
    }

    public function testChiffresEtEvolution(): void
    {
        $s = UsageHistory::summary(['credits' => 300, 'exempt' => 10, 'operations' => 12], ['credits' => 200, 'exempt' => 0, 'operations' => 9], 30);
        $this->assertSame(50.0, $s['evolution']);
        $this->assertSame(10, $s['per_day']);
        $this->assertSame(25, $s['per_op']);
        $this->assertNull(UsageHistory::summary(['credits' => 300, 'exempt' => 0, 'operations' => 3], ['credits' => 5, 'exempt' => 0, 'operations' => 1], 30)['evolution'], 'Trop peu pour comparer');
        $this->assertNull(UsageHistory::summary(['credits' => 0, 'exempt' => 0, 'operations' => 0], null, 7)['evolution']);
        $this->assertSame(0, UsageHistory::summary(['credits' => 0, 'exempt' => 0, 'operations' => 0], null, 7)['per_op']);
    }

    public function testUneOperationDuJournal(): void
    {
        $g = [
            'grp' => 'm77', 'at' => '2026-11-03 14:05:00', 'credits' => 58, 'exempt' => 0, 'origin' => 'chat',
            'conversation_id' => 41, 'conv_title' => 'Nouvelle conversation', 'profile_id' => 4, 'profile_name' => 'Juriste', 'profile_color' => 'red',
            'lines' => [
                ['k' => 'llm', 's' => 'web_search', 'ty' => 'web', 'p' => 'anthropic', 'm' => 'claude-opus-5', 'c' => 6, 'x' => 0, 'tk' => 0, 'q' => 2],
                ['k' => 'tool', 's' => 'chat', 'ty' => 'image', 'tt' => 'image', 'c' => 40, 'x' => 0, 'tk' => 0, 'q' => 1, 'n' => 'Images'],
                ['k' => 'llm', 's' => 'chat', 'ty' => 'answer', 'p' => 'anthropic', 'm' => 'claude-opus-5', 'c' => 10, 'x' => 0, 'tk' => 4600, 'q' => 0],
                ['k' => 'llm', 's' => 'web_search', 'ty' => 'web', 'p' => 'anthropic', 'm' => 'claude-opus-5', 'c' => 2, 'x' => 0, 'tk' => 0, 'q' => 1],
            ],
        ];
        $r = UsageHistory::row($g);
        $this->assertSame('Réponse', $r['title'], 'La réponse nomme le tour, même moins chère que l\'image');
        $this->assertSame('Claude Opus 5', $r['who']);
        $this->assertSame('2026-11-03', $r['day']);
        $this->assertSame('Nouvelle conversation', $r['context']['label']);
        $this->assertSame('/chat/41', $r['context']['href']);
        $this->assertSame('', $r['profile']['color'], 'Couleur invalide écartée');
        $this->assertSame([['label' => 'Image', 'count' => 1, 'credits' => 40], ['label' => 'Recherche Internet', 'count' => 2, 'credits' => 8]], $r['extras']);
        $searches = array_values(array_filter($r['lines'], static fn(array $l): bool => $l['source'] === 'web_search'));
        $this->assertSame(['2 recherches', '1 recherche'], array_column($searches, 'volume'));
        $this->assertSame('4,6 k tokens', $r['lines'][0]['volume']);
        $this->assertFalse($r['all_exempt']);

        $wf = UsageHistory::row(['grp' => 'm8', 'at' => '2026-11-03 08:00:00', 'credits' => 12, 'exempt' => 0, 'origin' => 'workflow',
            'conversation_id' => 9, 'conv_title' => 'x', 'run_id' => 70, 'workflow_name' => 'Veille',
            'lines' => [['k' => 'llm', 's' => 'chat', 'p' => 'openai', 'm' => 'gpt-5.6', 'c' => 12]]]);
        $this->assertSame('Étape de workflow', $wf['title']);
        $this->assertSame('/chat/9', $wf['context']['href'], 'Sans droit de voir les workflows : la conversation');
        $this->assertSame('/workflows/runs/70', UsageHistory::row(['grp' => 'm8', 'at' => '2026-11-03 08:00:00', 'origin' => 'workflow', 'run_id' => 70, 'workflow_name' => 'Veille', 'lines' => []], ['workflow_links' => true])['context']['href']);
        $gone = UsageHistory::row(['grp' => 'l5', 'at' => '2026-11-03 08:00:00', 'origin' => 'task', 'conversation_id' => null, 'lines' => [['k' => 'llm', 's' => 'chat', 'c' => 3]]]);
        $this->assertSame('Tâche supprimée', $gone['context']['label']);
        $this->assertNull($gone['context']['href']);
        $this->assertSame('Exécution de la tâche', $gone['title']);
        $deleted = UsageHistory::row(['grp' => 'l6', 'at' => '2026-11-03 08:00:00', 'origin' => 'chat', 'conversation_id' => null, 'lines' => [['k' => 'llm', 's' => 'chat', 'c' => 3]]]);
        $this->assertSame('Conversation supprimée', $deleted['context']['label']);
        $kb = UsageHistory::row(['grp' => 'l7', 'at' => '2026-11-03 08:00:00', 'origin' => 'knowledge', 'credits' => 4, 'exempt' => 4, 'kb_name' => 'Procédures',
            'lines' => [['k' => 'llm', 's' => 'kb_embedding', 'p' => 'openai', 'm' => 'text-embedding-3-large', 'c' => 4, 'x' => 1, 'tk' => 9000]]]);
        $this->assertTrue($kb['all_exempt']);
        $this->assertSame('Recherche dans les bases', $kb['who'], 'Un modèle d\'embedding se nomme par ce à quoi il sert');
    }

    public function testJoursDuJournal(): void
    {
        $now = new DateTimeImmutable('2026-11-09 10:00');
        $this->assertSame('Aujourd’hui', UsageHistory::dayLabel('2026-11-09', $now));
        $this->assertSame('Hier', UsageHistory::dayLabel('2026-11-08', $now));
        $this->assertSame('lun. 2 nov.', UsageHistory::dayLabel('2026-11-02', $now));
        $this->assertSame('mer. 31 déc. 2025', UsageHistory::dayLabel('2025-12-31', $now), 'Année d\'avant : écrite');
        $rows = [['day' => '2026-11-09', 'x' => 1], ['day' => '2026-11-09', 'x' => 2], ['day' => '2026-11-02', 'x' => 3]];
        $days = UsageHistory::days($rows, ['2026-11-09' => ['chat' => 30, 'task' => 5]], $now);
        $this->assertSame(['2026-11-09', '2026-11-02'], array_column($days, 'day'));
        $this->assertSame(35, $days[0]['credits'], 'Le total du jour couvre toute la période filtrée, pas la seule page');
        $this->assertCount(2, $days[0]['rows']);
    }

    public function testExport(): void
    {
        $row = UsageHistory::exportRow([
            'kind' => 'llm', 'source' => 'web_search', 'tool_type' => '', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
            'created_at' => '2026-11-03 14:05:09', 'origin' => 'workflow', 'type' => 'web', 'workflow_name' => 'Veille',
            'profile_id' => null, 'tokens' => 0, 'quantity' => 3, 'credits' => 9, 'quota_exempt' => 0,
        ]);
        $this->assertSame(['2026-11-03', '14:05', 'Workflows', 'Recherche Internet', 'Recherche Internet', 'Veille', 'Claude Opus 5', '', 0, '3 recherches', 9, ''], $row);
        $this->assertCount(count(UsageHistory::exportHeader()), $row);
    }

    // ---- Câblage ------------------------------------------------------------------

    public function testLOrigineEstFigeeALEcriture(): void
    {
        $log = self::lire('src/Models/UsageLog.php');
        $this->assertSame(2, substr_count($log, "' . UsageHistory::SQL_STORED_ORIGIN . ')'"), 'Les deux tables');
        $this->assertStringContainsString('WHERE c.id = ?', UsageHistory::SQL_STORED_ORIGIN);
        $this->assertSame(1, substr_count(UsageHistory::SQL_STORED_ORIGIN, '?'), 'Un seul paramètre : la conversation');
        $migration = self::lire('database/migrations/105_usage_origin.sql');
        $this->assertStringContainsString('ALTER TABLE usage_logs ADD COLUMN origin', $migration);
        $this->assertStringContainsString('ALTER TABLE tool_usage_logs ADD COLUMN origin', $migration);
        $this->assertStringContainsString("NOT NULL DEFAULT ''", $migration, 'Une ligne écrite par une version d\'avant reste valide');
    }

    public function testLaPageEstCablee(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Billing/UsageHistory.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Billing/UsageHistory.php',", self::lire('tests/bootstrap.php'));

        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString("public const USAGE_HISTORY_VIEW = 'historique';", $ctrl);
        $this->assertStringContainsString('if (($_GET[\'vue\'] ?? \'\') === self::USAGE_HISTORY_VIEW) {', $ctrl);
        foreach (['UsageHistory::series(', 'UsageHistory::page(', 'UsageHistory::totals(', 'UsageHistory::dimensions(', 'UsageHistory::facets(',
                  'UsageHistory::bars(', 'UsageHistory::summary(', 'UsageHistory::exportLines(', 'CsvExport::stream(', "\$fragment === 'rows'",
                  "\$fragment === 'period' || \$fragment === 'history'"] as $appel) {
            $this->assertStringContainsString($appel, $ctrl, $appel);
        }
        $this->assertStringContainsString('$first > $previous[\'from\']', $ctrl, 'Pas de comparaison avec une période d\'avant l\'historique');
        $this->assertStringNotContainsString('->prepare(', substr($ctrl, (int)strpos($ctrl, 'private function usageHistory(')), 'Aucun SQL dans le contrôleur');

        $page = self::lire('templates/settings/usage.php');
        $this->assertStringContainsString("require ROOT . '/templates/settings/usage-history.php';", $page);
        $this->assertStringContainsString('class="admin-tabs pu-tabs"', $page);
        $this->assertStringContainsString('/assets/js/period-picker.js', $page);
        $this->assertStringContainsString('/assets/js/usage-history.js', $page);
        $this->assertStringContainsString('/assets/css/usage-history.css', $page);

        $region = self::lire('templates/settings/usage-history.php');
        $this->assertStringContainsString('id="puHistory"', $region);
        $this->assertStringContainsString("'swap'    => '#puHistory'", $region, 'Le sélecteur de période partagé remplace la même région');
        $this->assertStringContainsString("require ROOT . '/templates/partials/period-picker.php';", $region);
        $this->assertStringContainsString("require ROOT . '/templates/settings/usage-history-rows.php';", $region);
        foreach (['data-history-filters', 'data-history-search', 'data-history-select', 'data-history-link', 'data-history-toggle', 'data-history-days'] as $attr) {
            $this->assertStringContainsString($attr, $region . self::lire('templates/settings/usage-history-rows.php'), $attr);
            $this->assertStringContainsString($attr, self::lire('public/assets/js/usage-history.js'), "$attr lu par le script");
        }
        $this->assertStringContainsString('data-history-more', self::lire('templates/settings/usage-history-rows.php'));
        foreach (['Format::eur', 'creditsToEur', '€', 'cost_eur'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $region . self::lire('templates/settings/usage-history-rows.php'), "Aucun euro côté utilisateur ($interdit)");
        }
    }

    public function testLaRechercheMeneALHistorique(): void
    {
        // L'entrée elle-même est vérifiée par NavRegistryTest (réglages préparés sans base).
        $this->assertStringContainsString("'paramètres usage consommation quota solde historique'", self::lire('src/Helpers/NavRegistry.php'));
        $this->assertStringContainsString("'/settings/usage?vue=historique'", self::lire('src/Helpers/NavRegistry.php'));
    }

    public function testLeScriptNAAucunLibelle(): void
    {
        $js = self::lire('public/assets/js/usage-history.js');
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
        $this->assertDoesNotMatchRegularExpression('/[éèêàçùôî]/u', $code, 'Tout le texte vient du serveur');
        $this->assertStringContainsString("'history'", $js);
        $this->assertStringContainsString('history.replaceState', $js, 'Un filtre n\'empile pas d\'entrée dans l\'historique du navigateur');
        $this->assertStringContainsString("'#puHistory'", $js);
        if (trim((string)shell_exec('command -v node 2>/dev/null')) !== '') {
            exec('node --check ' . escapeshellarg(dirname(__DIR__, 2) . '/public/assets/js/usage-history.js') . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    public function testFeuilleDeStyle(): void
    {
        $css = self::lire('public/assets/css/usage-history.css');
        $this->assertStringContainsString('.hx-js .hx-submit { display: none; }', $css, 'Sans script, le bouton « Filtrer » reste');
        $this->assertStringContainsString('body.admin-page .admin-content .card.hx-filters { overflow: visible; }', $css, 'La carte des filtres ne défile pas sur téléphone');
        $this->assertStringContainsString('.hx-search input { font-size: 16px; }', $css, 'iOS ne zoome pas au focus');
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertDoesNotMatchRegularExpression('/\b\d+(\.\d+)?(vh|dvh|svh|vw)\b/', $css, 'Aucune unité d\'écran (taille d\'affichage)');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testDocumentation(): void
    {
        $this->assertStringContainsString('Historique', self::lire('docs/user/fonctionnalites/credits/suivre-sa-consommation.md'));
        $this->assertStringContainsString('**Onglet Historique** (Usage & crédits)', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('id: historique-des-usages', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('UsageHistory', self::lire('CLAUDE.md'));
    }
}
