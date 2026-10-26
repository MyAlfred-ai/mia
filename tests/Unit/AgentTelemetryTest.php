<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT . '/src/Controllers/ChatController.php';
require_once ROOT . '/src/Controllers/AgentStatsController.php';

/**
 * Télémétrie de l'agent (AgentTelemetry, ToolRegistry::stats, écran
 * « Agent ») : ce que fait la boucle d'outils, mesuré — sans contenu.
 */
final class AgentTelemetryTest extends TestCase
{
    private static function lire(string $f): string
    {
        return (string)file_get_contents(ROOT . '/' . $f);
    }

    public function testLaLigneEcriteEstBorneeEtSansContenu(): void
    {
        $row = AgentTelemetry::turnRow([
            'provider'    => 'anthropic',
            'model'       => 'claude-opus-5',
            'rounds'      => 3,
            'registry'    => ['calls' => 4, 'errors' => 1, 'duplicates' => 2, 'unknown' => 70000,
                              'tools' => ['lire_gmail' => ['n' => 1, 'err' => 1, 'ms' => 120, 'max' => 120],
                                          'rechercher_gmail' => ['n' => 3, 'err' => 0, 'ms' => 900, 'max' => 500]]],
            'cards'       => 1,
            'tokens'      => ['input' => 12000, 'input_cached' => 9000, 'input_cache_write' => 1000, 'output' => 800, 'rounds_exhausted' => true],
            'duration_ms' => 4321,
            'failover'    => true,
            'follow_up'   => 'n\'importe quoi',
        ]);
        $this->assertSame(65535, $row['unknown_tools'], 'Bornes de la colonne.');
        $this->assertSame(1, $row['rounds_exhausted']);
        $this->assertSame(9000, $row['cached_tokens']);
        $this->assertSame('', $row['follow_up'], 'Seules les raisons connues passent.');
        $this->assertSame(['lire_gmail', 'rechercher_gmail'], array_keys(json_decode($row['tools_json'], true)), 'Outils triés.');
        $this->assertNull(AgentTelemetry::turnRow([])['tools_json']);

        $sql = self::lire('database/migrations/080_agent_turn_stats.sql');
        foreach (['user_id', 'conversation_id', 'message_id', 'content', 'prompt'] as $col) {
            $this->assertStringNotContainsString($col, $sql, "Aucun identifiant ni contenu : $col");
        }
        $this->assertSame(array_keys($row), array_values(array_filter(
            array_map(static fn(string $l): ?string => preg_match('/^\s{4}([a-z_]+)\s+[A-Z]/', $l, $m) ? $m[1] : null, explode("\n", $sql)),
            static fn(?string $c): bool => $c !== null && !in_array($c, ['id', 'created_at'], true)
        )), 'Chaque clé de turnRow est une colonne de la table, dans l\'ordre.');
    }

    public function testLesRapportsNeDivisentJamaisParZero(): void
    {
        $r = AgentTelemetry::ratios([]);
        $this->assertNull($r['avg_rounds']);
        $this->assertNull($r['error_rate']);
        $this->assertNull($r['tool_share']);
        $r = AgentTelemetry::ratios(['turns' => 4, 'rounds' => 10, 'tool_calls' => 8, 'tool_errors' => 2,
            'input_tokens' => 1000, 'cached_tokens' => 250, 'exhausted' => 1, 'chat_turns' => 16]);
        $this->assertSame(2.5, $r['avg_rounds']);
        $this->assertSame(0.25, $r['error_rate']);
        $this->assertSame(0.25, $r['cache_rate']);
        $this->assertSame(0.25, $r['exhausted_rate']);
        $this->assertSame(0.25, $r['tool_share']);
        $this->assertSame(30, AgentTelemetry::period('abc'));
        $this->assertSame(7, AgentTelemetry::period('7'));
        $this->assertSame(30, AgentTelemetry::period(365));
    }

    public function testLesDetailsParOutilSeFusionnent(): void
    {
        $merged = AgentTelemetry::mergeToolStats([
            '{"lire_gmail":{"n":2,"err":1,"ms":400,"max":300},"rechercher_gmail":{"n":1,"err":0,"ms":100,"max":100}}',
            '{"lire_gmail":{"n":2,"err":0,"ms":200,"max":150}}',
            'illisible',
            null,
        ]);
        $this->assertSame('lire_gmail', $merged[0]['tool']);
        $this->assertSame(4, $merged[0]['n']);
        $this->assertSame(1, $merged[0]['err']);
        $this->assertSame(150, $merged[0]['avg_ms']);
        $this->assertSame(300, $merged[0]['max_ms']);
        $this->assertSame(0.25, $merged[0]['error_rate']);
        $this->assertSame('rechercher_gmail', $merged[1]['tool']);
    }

    public function testLeDevenirDesCartesEtLeTotal(): void
    {
        $cards = AgentTelemetry::foldCards([
            ['tool' => 'creer_tache', 'state' => 'executed', 'n' => 6, 'avg_s' => 20],
            ['tool' => 'creer_tache', 'state' => 'cancelled', 'n' => 2, 'avg_s' => 60],
            ['tool' => 'creer_tache', 'state' => 'failed', 'n' => 2, 'avg_s' => 20],
            ['tool' => 'creer_tache', 'state' => 'expired', 'n' => 5, 'avg_s' => null],
            ['tool' => 'creer_brouillon_gmail', 'state' => 'pending', 'n' => 1, 'avg_s' => null],
        ]);
        $this->assertSame('creer_tache', $cards[0]['tool']);
        $this->assertSame(15, $cards[0]['proposed']);
        $this->assertSame(0.8, $cards[0]['approval_rate'], 'Approuvées (exécutées + en échec) sur tranchées ; sans décision exclues.');
        $this->assertSame(28, $cards[0]['avg_decision_s'], 'Délai pondéré par le nombre de cartes tranchées.');
        $this->assertSame(1, $cards[1]['pending']);
        $this->assertNull($cards[1]['approval_rate']);
        $total = end($cards);
        $this->assertSame('', $total['tool'], 'Le total ferme le tableau.');
        $this->assertSame(16, $total['proposed']);
        $this->assertSame([], AgentTelemetry::foldCards([]));
    }

    public function testLesSignauxAttendentDuVolumeEtNommentLOutil(): void
    {
        $summary = ['turns' => 40, 'exhausted_rate' => 0.15, 'unknown' => 2, 'duplicates' => 8];
        $tools   = [
            ['tool' => 'lire_gmail', 'n' => 20, 'err' => 6, 'avg_ms' => 12000, 'max_ms' => 30000, 'error_rate' => 0.3],
            ['tool' => 'rare', 'n' => 3, 'err' => 3, 'avg_ms' => 50000, 'max_ms' => 50000, 'error_rate' => 1.0],
        ];
        $cards = AgentTelemetry::foldCards([
            ['tool' => 'creer_tache', 'state' => 'cancelled', 'n' => 8, 'avg_s' => 5],
            ['tool' => 'creer_tache', 'state' => 'executed', 'n' => 3, 'avg_s' => 5],
            ['tool' => 'creer_tache', 'state' => 'expired', 'n' => 9, 'avg_s' => null],
        ]);
        $texts = implode("\n", array_column(
            AgentTelemetry::signals($summary, $tools, $cards, static fn(string $t): string => 'L:' . $t), 'text'));
        $this->assertStringContainsString('15 % des réponses atteignent le budget', $texts);
        $this->assertStringContainsString('« L:lire_gmail » échoue dans 30 %', $texts);
        $this->assertStringContainsString('« L:lire_gmail » met en moyenne 12,0 s', $texts);
        $this->assertStringNotContainsString('rare', $texts, 'Trois appels ne disent rien.');
        $this->assertStringContainsString('Les cartes « L:creer_tache » sont refusées', $texts);
        $this->assertStringContainsString('45 % des cartes « L:creer_tache » restent sans décision', $texts);
        $this->assertStringContainsString('2 fois un outil qui n\'existe', $texts);
        $this->assertStringContainsString('8 appels identiques', $texts);
        $this->assertSame([], AgentTelemetry::signals(['turns' => 3, 'exhausted_rate' => 1.0], [], []));
    }

    public function testLeRegistreCompteSansRienSavoirDuContenu(): void
    {
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
        $registry = new ToolRegistry();
        $registry->add(new class ($turn) extends AbstractToolProvider {
            public function key(): string { return 'test'; }
            public function definitions(): array { return [['name' => 'ok', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => []]], ['name' => 'ko', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => []]]]; }
            public function guidance(): string { return ''; }
            public function handlers(): array {
                return ['ok' => static fn(array $a): array => ['contenu' => 'secret'], 'ko' => static fn(array $a): array => ['error' => 'non']];
            }
        });
        $registry->dispatch('ok', ['q' => 1]);
        $registry->dispatch('ok', ['q' => 1]);   // répétition bloquée
        $registry->dispatch('ok', ['q' => 2]);
        $registry->dispatch('ko', []);
        $registry->dispatch('fantome', []);      // nom inconnu
        $stats = $registry->stats();
        $this->assertSame(3, $stats['calls']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(1, $stats['duplicates']);
        $this->assertSame(1, $stats['unknown']);
        $this->assertSame(2, $stats['tools']['ok']['n']);
        $this->assertSame(1, $stats['tools']['ko']['err']);
        $this->assertGreaterThanOrEqual(0, $stats['tools']['ok']['max']);
        $this->assertStringNotContainsString('secret', (string)json_encode($stats), 'Jamais un contenu.');
    }

    public function testLeTourDeChatEnregistreUneLigneParReponseAvecOutils(): void
    {
        $src  = self::lire('src/Controllers/ChatController.php');
        // Le corps de runSend entier, jusqu'à la méthode suivante (une fenêtre
        // de taille fixe finit toujours par couper la méthode qui grandit).
        $start = (int)strpos($src, 'public function runSend(');
        $next  = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $src, $m, PREG_OFFSET_CAPTURE, $start + 30)
            ? (int)$m[0][1] : strlen($src);
        $send  = substr($src, $start, $next - $start);
        $this->assertStringContainsString("'tools_source' => function () use (\$registry, &\$loopRounds): array {", $send);
        $this->assertStringContainsString('$loopRounds++;', $send, 'Une relecture des définitions par aller : le compteur d\'allers-retours.');
        $this->assertStringContainsString('$condensedCount++;', $send);
        $rec = substr($send, (int)strpos($send, 'AgentTelemetry::record('), 900);
        $this->assertStringContainsString("'registry'    => \$registry->stats(),", $rec);
        $this->assertStringContainsString("'failover'    => \$llm !== \$llmAsked,", $rec);
        $this->assertLessThan(strpos($send, 'AgentTelemetry::record('), strpos($send, 'if ($useToolLoop) {' . "\n" . '            AgentTelemetry::record('), 'Seulement les réponses passées par la boucle.');
        $this->assertLessThan(strpos($send, 'AgentTelemetry::record('), strpos($send, 'Message::setAgent('));
    }

    public function testLEcranAgentEstRelieEtSansSauvegarde(): void
    {
        $this->assertStringContainsString("get('/admin/agent',", self::lire('public/index.php'));
        $this->assertSame(['/admin/agent', 'Agent'], AdminTabs::onglets(AdminTabs::DIAGNOSTIC)['agent'] ?? null);
        $this->assertContains('agent_turn_stats', BackupService::EXCLUDED_TABLES);
        $ctrl = self::lire('src/Controllers/AgentStatsController.php');
        // Droit « Diagnostic — Voir » (Personnes → Rôles) : l'administrateur l'a toujours.
        $this->assertStringContainsString("AuthMiddleware::requirePermission('diagnostics.view');", $ctrl);
        $this->assertSame('diagnostics.view', AdminAccess::PAGES['agent'][0]);
        $tpl = self::lire('templates/admin/agent.php');
        foreach (['<h1 style="margin-bottom:4px"><?= te(\'Agent\') ?></h1>', 'Par outil', 'Cartes d\'approbation', 'Par modèle', 'À surveiller', '/admin/agent?jours='] as $needle) {
            $this->assertStringContainsString($needle, $tpl);
        }
        $this->assertStringContainsString('.agent-stats', self::lire('public/assets/css/main.css'));
        foreach (['config/classes.php' => ['src/Agent/AgentTelemetry.php', 'src/Controllers/AgentStatsController.php'],
                  'tests/bootstrap.php' => ['src/Agent/AgentTelemetry.php']] as $liste => $classes) {
            foreach ($classes as $classe) {
                $this->assertStringContainsString($classe, self::lire($liste), "$classe absent de $liste");
            }
        }
        $this->assertSame('Lecture d\'un email Gmail', AgentStatsController::toolLabel('lire_gmail'));
        $this->assertSame('outil_inconnu', AgentStatsController::toolLabel('outil_inconnu'));
    }
}
