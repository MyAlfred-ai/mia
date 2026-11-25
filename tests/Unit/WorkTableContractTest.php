<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage des tables de travail : ce qu'aucun test d'outil ne voit — le
 * chargement des classes, la route, l'exposition dans le tour de chat, le
 * lien de téléchargement reconnu par l'écran, la base, la sauvegarde, et les
 * limites annoncées aux utilisateurs (les mêmes que celles du code).
 */
final class WorkTableContractTest extends TestCase
{
    private static function read(string $path): string
    {
        $content = @file_get_contents(ROOT . '/' . ltrim($path, '/'));
        self::assertIsString($content, $path . ' illisible');
        return $content;
    }

    public function testLesClassesSontChargeesDansLOrdreDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $list) {
            $src = self::read($list);
            $pos = [];
            foreach ([
                'src/Agent/AbstractToolProvider.php', 'src/Tables/WorkTableOps.php', 'src/Tables/WorkTableStore.php',
                'src/Tables/SqlWorkTableStore.php', 'src/Tables/WorkTableCollector.php', 'src/Tables/WorkTableExport.php',
                'src/Agent/Tools/WorkTableTools.php',
            ] as $file) {
                $at = strpos($src, "'/" . $file . "'");
                $this->assertNotFalse($at, $file . ' absent de ' . $list . ' (pas d’autoloader : le worker ne le trouverait pas).');
                $pos[$file] = $at;
            }
            $this->assertLessThan($pos['src/Tables/SqlWorkTableStore.php'], $pos['src/Tables/WorkTableStore.php'], 'L’interface du magasin avant son implémentation (' . $list . ').');
            $this->assertLessThan($pos['src/Agent/Tools/WorkTableTools.php'], $pos['src/Agent/AbstractToolProvider.php'], 'Le socle des fournisseurs avant le fournisseur (' . $list . ').');
        }
        $this->assertStringContainsString("'/src/Controllers/WorkTableController.php'", self::read('config/classes.php'));
    }

    public function testLeToursDeChatExposeLesOutilsQuandIlsServent(): void
    {
        $src = self::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString('new WorkTableTools(', $src);
        $this->assertStringContainsString('new SqlWorkTableStore()', $src);
        // Avec un connecteur engagé, une table déjà là, des pièces Tableau d'une étape de workflow, ou sur demande explicite.
        // Une étape de workflow y a droit aussi (WorkflowTables) : ses tables vivent dans la conversation de l'exécution.
        $this->assertMatchesRegularExpression('/\$tablesPossible\s*=\s*\$supportsTools;/', $src);
        $this->assertMatchesRegularExpression('/if \(\$tablesPossible && \(\$tableRequest \|\| \$tablesKept \|\| \$permanentTables !== \[\] \|\| \$stepTables !== \[\]\s*\|\| \$registry->engagedConnectors\(\) !== \[\]\)\)/', $src);
        // Une table déjà là garde ses outils, sauf si cela écarterait la recherche Internet (modèle qui ne cumule pas).
        $this->assertMatchesRegularExpression(
            '/\$tablesKept = \(\$tableNames !== \[\] \|\| \$bigSheets !== \[\]\) && \(!\$nativeWebSearch\s*\|\| LlmCatalog::supportsWebSearchWithTools\(/',
            $src
        );
        // Un GROS classeur joint à ce message vaut demande de table, sans mot-clé ; joint plus tôt, il garde les outils.
        $this->assertStringContainsString('WorkTableCollector::sized(WorkTableCollector::sheetAttachments($history), (array)($contextPlan[\'files_cut\'] ?? []))', $src);
        $this->assertStringContainsString('$tableRequest = $bigSheetNow || ((', $src);
        $this->assertStringContainsString('(int)$f[\'message_id\'] === (int)$userMsgId', $src);
        $this->assertGreaterThan(
            strpos($src, 'if (self::webSearchYieldsToTools('),
            strpos($src, '$tablesKept = '),
            'Décidé APRÈS l’arbitrage Internet / outils : il lit ce qu’il reste de la recherche.'
        );
        $this->assertStringNotContainsString('$tableRequest || $registry->engagedConnectors() !== [] || $autoConnectors !== []', $src,
            '« Laisser MIA choisir » seul n’expose pas les tables : elles pèseraient sur chaque message.');
        // Demande explicite : il faut de quoi travailler (un classeur joint, des tables déjà là) ET une demande de table.
        $this->assertStringContainsString('WorkTableOps::looksLikeTableRequest($content, $tableNames)', $src);
        $this->assertStringContainsString('$explicitToolSources = $explicitToolSources || $tableRequest;', $src);
        // Un connecteur activé par MIA entre dans les sources collectables : le fournisseur est ré-ajouté.
        $this->assertMatchesRegularExpression('/if \(\$tablesPossible\) \{\s*\$tableTools \?\?= \$newTableTools\(\);\s*\$registry->add\(\$tableTools\);/', $src);
        // Trace : type et détail passent par le fournisseur (une seule définition).
        $this->assertStringContainsString('WorkTableTools::traceType($name, $result)', $src);
        $this->assertStringContainsString('WorkTableTools::traceDetail($name, $args, $result)', $src);
    }

    public function testLaRouteDeTelechargementVerifieLaConversationPuisLaTable(): void
    {
        $this->assertStringContainsString("\$router->get('/chat/{id}/tables/{table}/{file}', [WorkTableController::class, 'download']);", self::read('public/index.php'));
        $src = self::read('src/Controllers/WorkTableController.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth();', $src);
        $this->assertStringContainsString('Conversation::belongsToUser($convId, (int)$user[\'id\'])', $src);
        $this->assertStringContainsString('$store->findById($convId, (int)$table)', $src, 'La table se cherche DANS la conversation, jamais par son seul identifiant.');
        $this->assertStringContainsString('WorkTableExport::isFileName($file)', $src);
        $this->assertStringContainsString('session_write_close();', $src);
        $this->assertStringContainsString('nosniff', $src);
    }

    public function testLeLienRenduParLOutilEstCeluiQueLEcranTelecharge(): void
    {
        $js = self::read('public/assets/js/generated-files.js');
        $this->assertSame(1, preg_match('#const TABLE_LINK = (/.+/);#', $js, $m), 'Motif des liens de table introuvable dans generated-files.js.');
        $pattern = '#' . str_replace('#', '\\#', substr($m[1], 1, -1)) . '#';
        $link = WorkTableExport::link(12, 5, WorkTableExport::fileName(['name' => 'clients_sans_mail'], 'Clients sans mail', 'xlsx'));
        $this->assertSame('/chat/12/tables/5/clients-sans-mail.xlsx', $link);
        $this->assertSame(1, preg_match($pattern, $link), 'L’écran ne reconnaîtrait pas le lien rendu par exporter_table.');
        $this->assertSame(1, preg_match($pattern, WorkTableExport::link(3, 44, 'table.csv')));
        $this->assertSame(0, preg_match($pattern, '/chat/12/tables/5/../x.xlsx'));
        $this->assertStringContainsString('NATIVE_LINK.test(href) || TABLE_LINK.test(href)', $js, 'Le lien passe par le chemin unique d’enregistrement (application mobile).');

        $chat = self::read('public/assets/js/chat.js');
        foreach (['table_collect:', 'table_work:'] as $phase) {
            $this->assertStringContainsString($phase, $chat, 'Phase ' . $phase . ' sans libellé : la ligne d’attente resterait sur « Analyse ».');
        }
    }

    public function testLaBaseGardeLesTablesAvecLaConversationEtHorsSauvegarde(): void
    {
        $sql = self::read('database/migrations/111_work_tables.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS work_tables', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS work_table_rows', $sql);
        $this->assertStringContainsString('UNIQUE KEY uniq_work_table_name (conversation_id, name)', $sql, 'Un nom = une table par conversation.');
        $this->assertSame(3, substr_count($sql, 'ON DELETE CASCADE'), 'Conversation, compte et table : tout part avec eux.');

        foreach (['work_tables', 'work_table_rows'] as $table) {
            $this->assertContains($table, BackupService::EXCLUDED_TABLES, 'Du contenu, comme les conversations : hors de la sauvegarde.');
            $this->assertStringContainsString("'" . $table . "'", self::read('tests/Support/DatabaseTestCase.php'), 'Vidée entre deux tests fonctionnels.');
        }
        $this->assertStringContainsString('Tables', LogCatalog::SOURCES['ia']['tags'], 'L’étiquette [Tables] a un domaine dans les Journaux.');
    }

    public function testLesLimitesAnnonceesSontCellesDuCode(): void
    {
        $this->assertSame(1000, UserGmailService::COLLECT_MAX);
        $this->assertSame(1000, UserOutlookService::COLLECT_MAX);
        $this->assertSame(2000, UserDriveService::COLLECT_FILES_MAX);
        $this->assertSame(5000, WorkTableOps::MAX_ROWS);
        $this->assertSame(20, WorkTableOps::MAX_TABLES);

        $page = self::read('docs/user/fonctionnalites/connecteurs/recouper-des-sources.md');
        $guide = self::read('docs/GUIDE-UTILISATEUR.md');
        foreach ([$page, $guide] as $doc) {
            $doc = (string)preg_replace('/\s+/u', ' ', $doc);
            foreach (['1 000 mails', '2 000 fichiers', '5 000 lignes', '20 tables'] as $limit) {
                $this->assertStringContainsString($limit, $doc);
            }
            $this->assertStringContainsString('Résultats de MIA', $doc);
            // Les libellés de la trace cités dans la doc sont ceux que l'écran affiche.
            $this->assertStringContainsString('Collecte dans une liste', $doc);
        }
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->assertSame('Collecte dans une liste', ChatController::ACTION_LABELS['table_collect']);
        $this->assertSame('Croisement de deux tables', ChatController::ACTION_LABELS['table_join']);

        $index = self::read('docs/user/fonctionnalites/connecteurs/index.md');
        $this->assertStringContainsString('(recouper-des-sources.md)', $index, 'Une page nouvelle est annoncée dans la vue d’ensemble.');
        $this->assertStringContainsString('id: tables-de-travail | genre: nouveaute', self::read('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('tables de travail', self::read('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('## Tables de travail', self::read('CLAUDE.md'));
    }

    public function testLaConsigneEtLesDefinitionsRestentLegeres(): void
    {
        $turn  = new AgentTurn(1, 1, [], 4000, static function (array $e): void {}, static fn(string $t, array $a, ?array $p = null): array => []);
        $tools = new WorkTableTools($turn, new MemoryWorkTableStore(), static fn(): array => ['gmail' => new \stdClass(), 'outlook' => new \stdClass(), 'drive' => new \stdClass()], [['numero' => 1]]);
        $size  = strlen((string)json_encode($tools->definitions(), JSON_UNESCAPED_UNICODE)) + strlen($tools->guidance());
        // Envoyées à chaque message où des connecteurs sont engagés : elles se paient à chaque fois.
        // 8 500 depuis les vues (2026-11-20) : la règle « liste ou résultat, jamais table de travail » et `titre`.
        $this->assertLessThan(8500, $size, 'Définitions et consigne des tables de travail : ' . $size . ' caractères.');
        $this->assertCount(6, $tools->definitions());
    }
}
