<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage des tables PERMANENTES (temps B) : ce qui tient entre la base, les
 * routes, les écrans, le chat et la documentation — et les règles qui ne
 * doivent pas se perdre au prochain ajustement :
 *   - une table est une base de nature « table », jamais une base à documents ;
 *   - MIA la lit, et n'y écrit que sous une carte, jamais sans validation ;
 *   - le plafond de 20 000 lignes est dit à l'écran, lu dans la constante ;
 *   - les scripts de la page n'ont aucun libellé.
 */
final class KbTableContractTest extends TestCase
{
    private static function read(string $path): string
    {
        $abs = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    // ---------------------------------------------------------------------
    // 1. Base et chargement
    // ---------------------------------------------------------------------

    public function testLaMigrationAjouteLaNatureEtLesDeuxTables(): void
    {
        $sql = self::read('database/migrations/112_kb_tables.sql');
        $this->assertMatchesRegularExpression("/MODIFY COLUMN backend ENUM\\('local',\\s*'mybrain',\\s*'table'\\)/", $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS knowledge_base_tables', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS knowledge_base_table_rows', $sql);
        $this->assertStringContainsString('PRIMARY KEY (kb_id, version, position)', $sql);
        $this->assertSame(2, substr_count($sql, 'ON DELETE CASCADE'), 'Fiche et lignes partent avec la base.');
        $this->assertStringContainsString('mia_write', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bmanual\b/i', $sql, '`manual` est un mot réservé de MySQL 8.4.');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneAnnonceLaDerniereMigration(): void
    {
        // Dernière migration du dépôt : CLAUDE.md annonce le bon numéro suivant.
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
        sort($files);
        $last = basename((string)end($files));
        $this->assertStringContainsString('ex. actuel = `' . substr($last, 0, 3) . '`', self::read('CLAUDE.md'));
    }

    public function testLesClassesSontChargeesDesDeuxCotesDansLeBonOrdre(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $list) {
            $src   = self::read($list);
            $rows  = strpos($src, '/src/Tables/WorkTableRows.php');
            $store = strpos($src, '/src/Tables/WorkTableStore.php');
            $this->assertNotFalse($rows, $list);
            $this->assertNotFalse($store, $list);
            $this->assertLessThan($store, $rows, "$list : l'interface des lignes précède le magasin qui l'étend.");
            foreach (['KbTableStore', 'KbTableImport', 'KbTableWrite', 'KbTableView', 'KbTablePrompt'] as $class) {
                $this->assertStringContainsString('/src/Tables/' . $class . '.php', $src, "$list ne charge pas $class.");
            }
        }
        $this->assertStringContainsString('/src/Controllers/KbTableController.php', self::read('config/classes.php'));
        $this->assertTrue(is_subclass_of(KbTableStore::class, WorkTableRows::class));
        $this->assertTrue(is_subclass_of(WorkTableStore::class, WorkTableRows::class));
    }

    public function testUneTableEstUneBaseDUneTroisiemeNature(): void
    {
        $table = ['id' => 3, 'backend' => 'table', 'is_active' => 1, 'table_rows' => 12];
        $local = ['id' => 1, 'backend' => 'local'];
        $live  = ['id' => 2, 'backend' => 'mybrain'];

        $this->assertTrue(KnowledgeBase::isTable($table));
        $this->assertFalse(KnowledgeBase::isTable($local));
        $this->assertFalse(KnowledgeBase::holdsDocuments($table), 'Ni dépôt, ni réindexation, ni extraits.');
        $this->assertFalse(KnowledgeBase::holdsDocuments($live));
        $this->assertTrue(KnowledgeBase::holdsDocuments($local));
        $this->assertSame([[$live], [$local], [$table]], KnowledgeBase::partitionByBackend([$local, $table, $live]));

        $this->assertNull(KnowledgeBase::unavailableReason($table));
        $this->assertSame('Table vide', KnowledgeBase::unavailableReason(['table_rows' => 0] + $table));

        // Elle part dans la sauvegarde (périmètre par exclusion), et rien n'y est réindexé au retour.
        $backup = self::read('src/Backup/BackupService.php');
        $this->assertStringNotContainsString("'knowledge_base_tables'", $backup);
        $this->assertStringNotContainsString("'knowledge_base_table_rows'", $backup);
        $this->assertStringContainsString("WHERE backend = 'local'", $backup);
    }

    // ---------------------------------------------------------------------
    // 2. Routes
    // ---------------------------------------------------------------------

    public function testLesRoutesExistentEtLesLitteralesPrecedentLesParametrees(): void
    {
        $index = self::read('public/index.php');
        $create  = strpos($index, "\$router->post('/admin/knowledge-bases/table',");
        $generic = strpos($index, "\$router->post('/admin/knowledge-bases/{id}',");
        $this->assertNotFalse($create);
        $this->assertNotFalse($generic);
        $this->assertLessThan($generic, $create, '« table » serait avalé par {id}.');
        foreach (['rows', 'cell', 'row', 'row-delete', 'column', 'column-update', 'column-delete', 'import', 'history', 'history/restore', 'history/undo'] as $action) {
            $this->assertStringContainsString("'/admin/knowledge-bases/{id}/table/" . $action . "'", $index);
        }
        $this->assertStringNotContainsString("'/admin/knowledge-bases/{id}/table/restore'", $index, 'L’ancienne « version d’avant » a laissé la place à l’historique.');
        $this->assertStringContainsString("'/admin/knowledge-bases/{id}/table/export/{file}'", $index);

        $keep     = strpos($index, "\$router->post('/chat/{id}/tables/{table}/keep', [WorkTableController::class, 'keep']);");
        $download = strpos($index, "\$router->get('/chat/{id}/tables/{table}/{file}',");
        $this->assertNotFalse($keep);
        $this->assertLessThan($download, $keep);
    }

    public function testChaqueGesteDeLaFicheVerifieLeDroitEtLaNature(): void
    {
        $ctrl = self::read('src/Controllers/KbTableController.php');
        $this->assertStringContainsString('KnowledgeBase::isTable($kb)', $ctrl);
        $this->assertStringContainsString('KnowledgeBase::manageableBy(', $ctrl, 'Seul qui gère la table la modifie.');
        $this->assertStringContainsString("'knowledge_bases.create'", $ctrl);
        $this->assertStringContainsString('KbTableTooLargeException', $ctrl, 'Un fichier trop long est refusé avec son compte.');
        // « Garder » : droit de créer une base, conversation et table de la personne.
        $work = self::read('src/Controllers/WorkTableController.php');
        $this->assertMatchesRegularExpression("/public function keep\\(string \\\$id, string \\\$table\\): void\\s*\\{\\s*\\[\\\$convId, \\\$found, \\\$store\\] = \\\$this->owned\\(/", $work);
        $this->assertStringContainsString("Session::can('knowledge_bases.create')", $work);
        $this->assertStringContainsString('KbTableImport::fromWorkTable(', $work);
        // Téléchargement d'une table permanente : conversation de la personne ET table engagée pour elle.
        $this->assertStringContainsString("preg_match('/^p(\\d+)\$/', \$table, \$m)", $work);
        $this->assertStringContainsString('KbTableStore::engaged($convId, $user, ', $work);
        // … « engagée » : les portes du tour de chat, une seule règle pour le volet, la puce et le téléchargement.
        $engaged = self::read('src/Tables/KbTableStore.php');
        $this->assertStringContainsString('ConversationKnowledgeBase::selectedKbsForTurn($convId, $user)', $engaged);
        $this->assertStringContainsString('KnowledgeBase::forProfileAndUser($profileId, $user)', $engaged);
    }

    // ---------------------------------------------------------------------
    // 3. Écrans
    // ---------------------------------------------------------------------

    public function testLaFicheReprendCelleDUneBaseAvecSesLignes(): void
    {
        $edit = self::read('templates/admin/knowledge-bases/edit.php');
        $this->assertMatchesRegularExpression('/\$noDocs\s+= \$isLive \|\| \$isTable;/', $edit);
        $this->assertMatchesRegularExpression('/id="kbTableSection"[^>]*data-autosave-skip/', $edit, 'Les gestes de la table ne partent pas avec la fiche.');
        $this->assertStringContainsString('name="table_form"', $edit);
        $this->assertStringContainsString('name="mia_write"', $edit);
        $this->assertStringContainsString("|| !empty(\$isTable)", $edit, 'Les lignes sont ouvertes d’office.');

        $section = self::read('templates/admin/knowledge-bases/partials/table-section.php');
        // Hors commentaires : le docblock du partiel explique justement pourquoi il n'en a pas.
        $markup = (string)preg_replace('#/\*.*?\*/#s', '', $section);
        $this->assertDoesNotMatchRegularExpression('/<form\b/', $markup, 'Pas de formulaire imbriqué dans celui de la fiche.');
        $this->assertDoesNotMatchRegularExpression('/\sname="/', $section, 'Aucun champ nommé : rien n’est posté avec la fiche.');
        // Le plafond affiché est lu dans la constante, jamais recopié dans le gabarit.
        $this->assertStringContainsString("\$ts['max_rows']", $section);
        $this->assertStringContainsString("'max_rows'   => KbTableStore::MAX_ROWS,", self::read('src/Tables/KbTableView.php'));
        $this->assertStringNotContainsString('20 000', $section);
        foreach (['data-kbt-grid', 'data-kbt-filter', 'data-kbt-row-add', 'data-kbt-col-add', 'data-kbt-import="replace"', 'data-kbt-import="append"'] as $hook) {
            $this->assertStringContainsString($hook, $section);
        }

        $ctrl = self::read('src/Controllers/AdminController.php');
        $this->assertStringContainsString("isset(\$_POST['table_form'])", $ctrl, 'Case absente ≠ décochée : le réglage n’est lu que si la fiche l’a posté.');
        $this->assertStringContainsString("/templates/admin/knowledge-bases/table-new.php'", $ctrl);
    }

    public function testLaListeEtLeSelecteurNommentLesTables(): void
    {
        // Piste D : la liste range les tables dans leur colonne, avec leur porte de création.
        $list = self::read('templates/admin/knowledge-bases.php');
        $this->assertStringContainsString('href="/admin/knowledge-bases/new?genre=table"', $list);
        $this->assertStringContainsString('data-group="table"', $list);
        $this->assertStringContainsString("\$kind  = KnowledgeBase::isTable(\$kb) ? 'table' : 'base';", $list);
        $this->assertStringContainsString("KbPages::tile(\$kb)", $list);
        $this->assertStringContainsString("\$isTable  = KnowledgeBase::isTable(\$kb);", self::read('src/Sync/KbPages.php'));

        $chat = self::read('templates/chat/index.php');
        $this->assertStringContainsString('KnowledgeBase::isTable($kbRow)', $chat);
        $this->assertStringContainsString('<li class="picker-section-label kb-picker-section" role="presentation">', $chat);
        // La première étiquette de section reste celle des bases : le script y pose les bases de l'assistant.
        $this->assertLessThan(
            strpos($chat, 'kb-picker-section'),
            strpos($chat, 'picker-section-label picker-section-first')
        );
    }

    public function testLesScriptsNOntAucunLibelle(): void
    {
        $js = self::read('public/assets/js/kb-table.js');
        $this->assertDoesNotMatchRegularExpression('/\bt\(\s*[\'"]/', $js, 'kb-table.js : tout texte vient du serveur.');
        $this->assertDoesNotMatchRegularExpression('/[éèêàùçÉÈÀ]/u', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $js), 'Aucun mot français hors des commentaires.');
        $this->assertStringContainsString("'/auth/csrf'", $js);
        $this->assertStringContainsString("new CustomEvent('mia:kb-table-changed')", $js);

        $panel = self::read('public/assets/js/work-tables.js');
        $this->assertStringContainsString("post(id, 'keep',", $panel);
        $this->assertStringContainsString('data.status_html', $panel, 'Le message de « Garder » est rendu par le serveur.');
        $this->assertDoesNotMatchRegularExpression('/\bt\(\s*[\'"]/', $panel);

        $view = self::read('templates/partials/work-tables-panel.php');
        $this->assertStringContainsString("!empty(\$tablesPanel['can_keep'])", $view);
        $this->assertStringContainsString('data-table-keep-form', $view);
    }

    // ---------------------------------------------------------------------
    // 4. Chat
    // ---------------------------------------------------------------------

    public function testLeTourEngageLesTablesEtNeLesEcritQueSousCarte(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $src = self::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString('$tableKbs      = KnowledgeBase::partitionByBackend($kbs)[2];', $src);
        $this->assertStringContainsString('self::permanentTablesFor($tableKbs, $user, $tableNames)', $src);
        $this->assertMatchesRegularExpression("/'tables'\\s*=> \\\$tablesByTools \\? 'tools' : 'preview'/", $src, 'Sans outils de tables : un aperçu, dit comme tel.');
        $this->assertStringContainsString('KbTablePrompt::preview(', $src);

        // L'accord revérifie tout et recalcule sur la table du moment.
        $this->assertSame(1, preg_match('/private function approvePermanentWrite\(.*?\n    \}\n/s', $src, $m));
        $body = $m[0];
        foreach ([
            "Role::userCan(\$user, 'knowledge_bases.edit')",
            'KnowledgeBase::manageableBy($kbId, $user)',
            "empty(\$table['mia_write'])",
            'WorkTableOps::stateStamp($source)',
            'KbTableWrite::plan(',
            'KbTableStore::stamp($table)',
            'DrivePendingAction::release($actionId)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }
        $this->assertLessThan(strpos($body, '$store->replace('), strpos($body, 'KbTableWrite::plan('), 'Le calcul précède l’écriture.');

        // Jamais sans validation, même dans une tâche qui agit seule.
        $this->assertFalse(Scheduler::autoExecutable(WorkTableTools::PERM_WRITE, [], []));
        // Ce n'est pas une écriture de connecteur : le droit « Connecteurs » ne la ferme pas.
        $this->assertSame(1, preg_match('/NON_CONNECTOR_ACTION_TOOLS\s*=\s*\[(.*?)\];/s', $src, $n));
        $this->assertStringContainsString('WorkTableTools::PERM_WRITE', $n[1]);
        $this->assertSame('Préparation d\'une modification de table', ChatController::ACTION_LABELS['table_perm_write']);

        // La carte montre avant / après ; le lien d'export d'une table permanente est reconnu.
        $chat = self::read('public/assets/js/chat.js');
        $this->assertStringContainsString('${changeGridHtml(data.grille)}', $chat);
        $this->assertSame(1, preg_match('/function changeGridHtml\(grid\) \{(.*?)\n\}/s', $chat, $g));
        $this->assertGreaterThanOrEqual(3, substr_count($g[1], 'escHtml('), 'Tout ce qui vient de la table est échappé.');
        $this->assertStringContainsString('\/tables\/p?\d+\/', self::read('public/assets/js/generated-files.js'));
        $this->assertSame('/chat/12/tables/p7/clients.xlsx', WorkTableExport::permanentLink(12, 7, 'clients.xlsx'));
    }

    // ---------------------------------------------------------------------
    // 5. Documentation
    // ---------------------------------------------------------------------

    public function testLesLimitesDesDocsSontCellesDuCode(): void
    {
        $rows    = number_format(KbTableStore::MAX_ROWS, 0, ',', ' ');
        $columns = (string)WorkTableOps::MAX_COLUMNS;
        $quota   = (string)KbTableStore::MAX_PER_CREATOR;

        $page = self::read('docs/user/fonctionnalites/bases-de-connaissances/tenir-une-table.md');
        $this->assertStringContainsString('**' . $rows . ' lignes** et **' . $columns . ' colonnes**', $page);
        $this->assertStringContainsString('**' . $quota . ' tables**', $page);
        $this->assertStringContainsString('**refusé**', $page, 'Jamais coupé sans le dire.');
        $this->assertStringNotContainsString('](../../admin/', $page, 'Une page utilisateur ne renvoie jamais vers admin/.');

        $index = self::read('docs/user/fonctionnalites/bases-de-connaissances/index.md');
        $this->assertStringContainsString('(tenir-une-table.md)', $index, 'La page est annoncée dans la vue d’ensemble.');

        foreach (['docs/GUIDE-CREATEUR.md', 'docs/GUIDE-ADMIN.md', 'docs/user/admin/bases-de-connaissances/administrer-les-bases.md'] as $doc) {
            $text = self::read($doc);
            $this->assertStringContainsString($rows . ' lignes', str_replace('**', '', $text), $doc);
            $this->assertStringContainsString($quota . ' tables', str_replace('**', '', $text), $doc);
        }
        $this->assertStringContainsString('Garder', self::read('docs/user/fonctionnalites/connecteurs/voir-ses-tables.md'));
        $this->assertStringContainsString('<!-- id: tables-permanentes | genre: nouveaute | pour: tous -->', self::read('docs/NOUVEAUTES.md'));

        // Le seuil du « gros classeur » dit dans la doc est celui du code.
        $this->assertSame(100, WorkTableCollector::BIG_SHEET_LINES);
        $this->assertStringContainsString("plus d'une centaine de lignes", self::read('docs/user/fonctionnalites/connecteurs/recouper-des-sources.md'));
    }
}
