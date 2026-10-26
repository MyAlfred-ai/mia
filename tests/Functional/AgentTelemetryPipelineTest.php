<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Télémétrie de l'agent sur base réelle (migration 080) : écriture, agrégats
 * de période, détail par outil et par modèle, devenir des cartes, purge des
 * lignes trop anciennes.
 */
final class AgentTelemetryPipelineTest extends DatabaseTestCase
{
    private static function turn(string $model, int $rounds, array $tools, array $extra = []): array
    {
        $calls = array_sum(array_column($tools, 'n'));
        return AgentTelemetry::turnRow($extra + [
            'provider' => 'anthropic', 'model' => $model, 'rounds' => $rounds,
            'registry' => ['tools' => $tools, 'calls' => $calls, 'errors' => array_sum(array_column($tools, 'err')),
                           'duplicates' => 0, 'unknown' => 0],
            'tokens'   => ['input' => 1000, 'input_cached' => 400, 'output' => 100],
            'duration_ms' => 2000,
        ]);
    }

    public function testLesAgregatsDeLaPeriode(): void
    {
        AgentTelemetry::record(self::turn('claude-opus-5', 2, ['lire_gmail' => ['n' => 1, 'err' => 0, 'ms' => 300, 'max' => 300]]));
        AgentTelemetry::record(self::turn('claude-opus-5', 4, ['lire_gmail' => ['n' => 2, 'err' => 1, 'ms' => 500, 'max' => 400],
                                                             'rechercher_gmail' => ['n' => 1, 'err' => 0, 'ms' => 900, 'max' => 900]],
            ['tokens' => ['input' => 1000, 'input_cached' => 400, 'output' => 100, 'rounds_exhausted' => true], 'follow_up' => 'budget']));
        AgentTelemetry::record(self::turn('gpt-5.6', 1, []));
        // Hors période : 40 jours.
        AgentTelemetry::record(self::turn('vieux', 9, []));
        $this->db()->exec("UPDATE agent_turn_stats SET created_at = NOW() - INTERVAL 40 DAY WHERE model = 'vieux'");

        $s = AgentTelemetry::summary(30);
        $this->assertSame(3, $s['turns']);
        $this->assertSame(7, $s['rounds']);
        $this->assertSame(4, $s['tool_calls']);
        $this->assertSame(1, $s['tool_errors']);
        $this->assertSame(1, $s['exhausted']);
        $this->assertSame(1, $s['follow_up']);
        $this->assertEqualsWithDelta(7 / 3, $s['avg_rounds'], 0.001);
        $this->assertSame(0.4, $s['cache_rate']);
        $this->assertSame(0, $s['chat_turns'], 'Aucune réponse dans messages : la part reste nulle, sans division par zéro.');
        $this->assertNull($s['tool_share']);
        $this->assertSame(4, AgentTelemetry::summary(90)['turns']);

        $models = AgentTelemetry::byModel(30);
        $this->assertSame('claude-opus-5', $models[0]['model']);
        $this->assertSame(2, $models[0]['turns']);
        $this->assertSame(3.0, $models[0]['avg_rounds']);

        $tools = AgentTelemetry::byTool(30);
        $this->assertSame(['lire_gmail', 'rechercher_gmail'], array_column($tools, 'tool'));
        $this->assertSame(3, $tools[0]['n']);
        $this->assertSame(400, $tools[0]['max_ms']);
    }

    public function testLeDevenirDesCartesVientDesCartes(): void
    {
        $this->insertUser(1);
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (1, ?)')->execute(['Cartes']);
        $conv = (int)$this->db()->lastInsertId();
        $add = function (string $tool, string $status, string $createdAgo, ?int $decidedAfterS = null) use ($conv): void {
            $this->db()->prepare(
                "INSERT INTO drive_pending_actions (user_id, conversation_id, tool, args_json, preview_json, status, created_at, resolved_at)
                 VALUES (1, ?, ?, '{}', '{}', ?, NOW() - INTERVAL $createdAgo, " . ($decidedAfterS !== null ? "NOW() - INTERVAL $createdAgo + INTERVAL $decidedAfterS SECOND" : 'NULL') . ')'
            )->execute([$conv, $tool, $status]);
        };
        $add('creer_tache', 'executed', '2 DAY', 30);
        $add('creer_tache', 'executed', '2 DAY', 10);
        $add('creer_tache', 'cancelled', '1 DAY', 20);
        $add('creer_tache', 'pending', '2 HOUR');        // au-delà de la durée de vie : sans décision
        $add('creer_tache', 'pending', '1 MINUTE');      // encore en attente
        $add('creer_tache', 'executed', '60 DAY', 5);    // hors période

        $cards = AgentTelemetry::cards(30);
        $tache = $cards[0];
        $this->assertSame('creer_tache', $tache['tool']);
        $this->assertSame(5, $tache['proposed']);
        $this->assertSame(2, $tache['executed']);
        $this->assertSame(1, $tache['cancelled']);
        $this->assertSame(1, $tache['expired']);
        $this->assertSame(1, $tache['pending']);
        $this->assertSame(20, $tache['avg_decision_s']);
        $this->assertEqualsWithDelta(2 / 3, $tache['approval_rate'], 0.001);
        $this->assertSame('', end($cards)['tool']);
    }

    public function testLesLignesTropAnciennesSontPurgeesAuFilDeLEau(): void
    {
        AgentTelemetry::record(self::turn('ancien', 1, []));
        $this->db()->exec('UPDATE agent_turn_stats SET created_at = NOW() - INTERVAL ' . (AgentTelemetry::RETENTION_DAYS + 5) . ' DAY');
        $this->db()->exec('ALTER TABLE agent_turn_stats AUTO_INCREMENT = 200');
        AgentTelemetry::record(self::turn('neuf', 1, []));
        $this->assertSame(['neuf'], $this->db()->query('SELECT model FROM agent_turn_stats')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
