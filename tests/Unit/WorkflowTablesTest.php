<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les workflows et les tables (WorkflowTables) : formes pures, spec, périmètre
 * des collectes, aperçu orienté par la question, rail du navigateur,
 * creer_workflow et câblage. Le parcours de bout en bout est dans
 * tests/Functional/WorkflowTablesPipelineTest.php.
 */
final class WorkflowTablesTest extends TestCase
{
    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // Pièce Tableau remise depuis une table de travail
    // ------------------------------------------------------------------

    public function testLaReponseDesigneUneTableParLaLigneTable(): void
    {
        $this->assertSame('clients_lyon', WorkflowTables::tableRef("Voici le résultat.\n\nTABLE : clients_lyon"));
        $this->assertSame('a_relancer', WorkflowTables::tableRef("**TABLE :** `a_relancer`"));
        $this->assertSame('suivi', WorkflowTables::tableRef("TABLE : brouillon\nencore un mot\nTABLE: suivi"), 'La dernière ligne compte.');
        $this->assertSame('liste_2', WorkflowTables::tableRef('> TABLE : « Liste_2 »'));
        $this->assertNull(WorkflowTables::tableRef('La table des clients compte 30 lignes.'), 'Une phrase n’est pas une désignation.');
        $this->assertNull(WorkflowTables::tableRef("| a | b |\n|---|---|\n| 1 | 2 |"));
    }

    public function testLesLignesDUnePieceVenueDUneTableGardentLEnTeteEtSontBornees(): void
    {
        $columns = WorkTableOps::columns([['label' => 'Nom'], ['label' => 'Ville'], ['label' => 'id', 'key' => 'id', 'tech' => true]]);
        $rows = [['nom' => 'Alpha', 'ville' => 'Lyon', 'id' => 'x1'], ['nom' => 'Beta', 'ville' => 'Paris', 'id' => 'x2'], ['nom' => 'Gamma', 'ville' => 'Nice', 'id' => 'x3']];
        $out = WorkflowTables::pieceRows($columns, $rows, 2);
        $this->assertSame([['Nom', 'Ville'], ['Alpha', 'Lyon'], ['Beta', 'Paris']], $out['rows'], 'Colonnes techniques écartées, en-tête d’abord.');
        $this->assertSame(3, $out['total']);
        $this->assertTrue($out['cut']);
        $this->assertSame(5000, WorkflowTables::PIECE_MAX_ROWS, 'Une pièce remise depuis une table va jusqu’au plafond d’une table de travail.');
    }

    public function testUnePieceSeRangeEnLignes(): void
    {
        $table = WorkflowTables::pieceAsTable(['form' => 'table'], ['rows' => [['Client', 'Montant'], ['Alpha', '10'], ['Beta', '0']]]);
        $this->assertSame(['client', 'montant'], WorkTableOps::keys($table['columns']));
        $this->assertSame('number', $table['columns'][1]['type'], 'Types devinés d’après les valeurs.');
        $this->assertCount(2, $table['rows']);

        $fiche = WorkflowTables::pieceAsTable(
            ['form' => 'fiche', 'fields' => [['key' => 'f1', 'label' => 'Email', 'type' => 'email'], ['key' => 'f2', 'label' => 'Sujets', 'type' => 'list']]],
            ['fields' => ['f1' => 'a@ex.fr', 'f2' => ['prix', 'délai']]]
        );
        $this->assertSame([['email' => 'a@ex.fr', 'sujets' => 'prix, délai']], $fiche['rows'], 'Une Fiche = une ligne, ses champs en colonnes.');

        $xlsx = WorkflowTables::pieceAsTable(['form' => 'document'], ['format' => 'xlsx', 'source' => "Nom;Ville\nAlpha;Lyon"]);
        $this->assertSame([['nom' => 'Alpha', 'ville' => 'Lyon']], $xlsx['rows']);

        $this->assertNull(WorkflowTables::pieceAsTable(['form' => 'text'], ['text' => 'bonjour']));
        $this->assertNull(WorkflowTables::pieceAsTable(['form' => 'image'], []));
    }

    public function testLesPiecesTableauLuesSontListeesAvecLeurTableDOrigine(): void
    {
        $def = WorkflowSpec::normalize([
            'nodes' => [
                ['key' => 'a', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'x', 'output' => ['key' => 'clients', 'name' => 'Clients à relancer', 'form' => 'table']],
                ['key' => 'b', 'type' => 'step', 'title' => 'Note', 'instruction' => 'y', 'output' => ['key' => 'note', 'name' => 'Note', 'form' => 'text']],
                ['key' => 'c', 'type' => 'step', 'title' => 'Compter', 'instruction' => 'Compte @{clients} et lis @{note}.', 'output' => ['key' => 'bilan', 'name' => 'Bilan', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['bilan']],
        ]);
        $node = WorkflowSpec::locate($def, 'c')['node'];
        $artifacts = [
            'clients' => ['key' => 'clients', 'content' => ['rows' => [['Nom'], ['Alpha'], ['Beta']], 'table' => ['name' => 'a_relancer']]],
            'note'    => ['key' => 'note', 'content' => ['text' => 'ok']],
        ];
        $this->assertSame([['key' => 'clients', 'name' => 'Clients à relancer', 'rows' => 2, 'table' => 'a_relancer']],
            WorkflowTables::readTablePieces($def, $node, $artifacts));
        unset($artifacts['clients']['content']['table']);
        $this->assertNull(WorkflowTables::readTablePieces($def, $node, $artifacts)[0]['table']);
        $artifacts['clients']['content']['rows'] = [['Nom']];
        $this->assertSame([], WorkflowTables::readTablePieces($def, $node, $artifacts), 'Un tableau sans ligne n’est pas rangé.');
    }

    public function testLaConsigneDUneEtapeNommeSesTablesEtLaLigneTable(): void
    {
        $txt = WorkTableTools::workflowGuidance(['clients_a_relancer' => 'Clients à relancer'], 'Relances');
        $this->assertStringContainsString('`clients_a_relancer` — pièce « Clients à relancer »', $txt);
        $this->assertStringContainsString('« TABLE : nom_de_la_table »', $txt);
        $this->assertStringContainsString('ne les recopie pas', $txt);
        $this->assertStringNotContainsString('TABLE :', WorkTableTools::workflowGuidance(['x' => 'X'], ''), 'Sans pièce Tableau à remettre, rien sur la ligne TABLE.');
    }

    // ------------------------------------------------------------------
    // Périmètre d'une étape imposé aux collectes
    // ------------------------------------------------------------------

    public function testLesFiltresDUneEtapeSImposentAuxCollectes(): void
    {
        $scope = SearchScope::fromFilters(WorkflowFilters::normalize([
            'mail' => ['from' => '@client.fr', 'subject' => 'facture'],
            'drive' => ['folder' => 'Clients/Durand', 'kind' => 'pdf', 'name' => 'devis'],
        ]));
        $this->assertStringContainsString('from:(@client.fr)', $scope->collectQuery(WorkTableCollector::SOURCE_GMAIL, 'relance'));
        $this->assertStringContainsString('subject:facture', $scope->collectQuery(WorkTableCollector::SOURCE_OUTLOOK, ''));
        $this->assertSame('Clients/Durand', $scope->collectFolder(''), 'Sans dossier nommé : celui du filtre.');
        $this->assertNull($scope->collectRefusal(WorkTableCollector::SOURCE_DRIVE, 'clients/durand/2026'), 'Un sous-dossier reste dans le périmètre.');
        $this->assertNotNull($scope->collectRefusal(WorkTableCollector::SOURCE_DRIVE, 'Comptabilité'));
        $rows = [
            ['nom' => 'Devis 12.pdf', 'type' => 'PDF', 'modifie_le' => '2026-11-01'],
            ['nom' => 'Devis 13.docx', 'type' => 'Word (docx)', 'modifie_le' => '2026-11-01'],
            ['nom' => 'Facture.pdf', 'type' => 'PDF', 'modifie_le' => '2026-11-01'],
        ];
        $this->assertSame(['Devis 12.pdf'], array_column($scope->filterFileRows($rows), 'nom'));

        $outlook = SearchScope::fromFilters(WorkflowFilters::normalize(['mail' => ['unread' => true]]));
        $this->assertNotNull($outlook->collectRefusal(WorkTableCollector::SOURCE_OUTLOOK, ''), 'Non lus : ne se vérifie pas sur une collecte Outlook.');
        $this->assertNull($outlook->collectRefusal(WorkTableCollector::SOURCE_GMAIL, ''));
        $this->assertSame('x', SearchScope::none()->collectQuery(WorkTableCollector::SOURCE_GMAIL, 'x'), 'Hors workflow : rien d’imposé.');
    }

    // ------------------------------------------------------------------
    // Boucle sur les lignes d'une table permanente
    // ------------------------------------------------------------------

    private static function tableLoop(array $source): array
    {
        return WorkflowSpec::normalize([
            'nodes' => [['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque client', 'mode' => 'each',
                'source' => $source + ['kind' => 'rows', 'from' => 'table'],
                'item' => ['key' => 'client', 'name' => 'Client en cours'],
                'body' => [['key' => 'relancer', 'type' => 'step', 'title' => 'Relancer', 'instruction' => 'Relance @{client.email}.',
                    'output' => ['key' => 'relance', 'name' => 'Relance', 'form' => 'text']]],
                'output' => ['key' => 'relances', 'name' => 'Relances']]],
            'end' => ['pieces' => ['relances']],
        ]);
    }

    public function testUneBoucleParcourtUneTablePermanente(): void
    {
        $def = self::tableLoop(['table_id' => 12, 'table' => 'Clients 2026', 'columns' => [['label' => 'Email', 'type' => 'email'], ['label' => 'Ville', 'type' => 'text']]]);
        $loop = $def['nodes'][0];
        $this->assertSame('table', $loop['source']['from']);
        $this->assertSame(12, $loop['source']['table_id']);
        $this->assertFalse(LoopSources::readsPiece($loop['source']), 'Elle ne lit aucune pièce du dossier.');
        $this->assertSame('', $loop['over']);
        $this->assertSame('', LoopSources::connector($loop['source']), 'Aucun connecteur.');
        $this->assertSame('Pour chaque ligne de la table « Clients 2026 »', LoopSources::describe($loop, null));
        $this->assertSame(['email', 'ville'], array_column(LoopSources::itemShape($loop)['fields'], 'key'), 'Ses colonnes sont les champs de l’élément.');

        $ok = ['kbs' => [12 => ['name' => 'Clients 2026', 'table' => true, 'rows' => 40, 'reason' => null]]];
        $this->assertSame([], array_filter(WorkflowSpec::issues($def, $ok), static fn($i) => $i['target'] === 'source'));
        $msgs = static fn(array $def, array $ctx): array => array_column(array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => $i['target'] === 'source'), 'text');
        $this->assertContains('Cette table n’existe plus ou ne vous est plus partagée : choisissez-en une autre.', $msgs($def, ['kbs' => []]));
        $this->assertContains('La table « Clients 2026 » est vide : la boucle n’aura rien à parcourir.', $msgs($def, ['kbs' => [12 => ['name' => 'Clients 2026', 'table' => true, 'rows' => 0, 'reason' => null]]]));
        $this->assertContains('Choisissez la table à parcourir.', $msgs(self::tableLoop([]), $ok));
        $this->assertNull(WorkflowSpec::normalize(['nodes' => [['key' => 'l', 'type' => 'loop', 'mode' => 'each', 'source' => ['kind' => 'slides', 'from' => 'table', 'table_id' => 3]]]])['nodes'][0]['source']['table_id'] ?? null,
            'Une table ne se parcourt que ligne par ligne.');
    }

    public function testLeRailDuNavigateurDitLaMemeChose(): void
    {
        if (trim((string)shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node absent');
        }
        $script = 'global.window = global; window.T = function (s, v) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return v && v[k] !== undefined ? v[k] : m; }); };'
            . 'eval(require("fs").readFileSync(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ', "utf8"));'
            . 'var R = window.MiaWorkflowRail; var loop = {source: {kind: "rows", from: "table", table: "Clients 2026"}};'
            . 'console.log(JSON.stringify([R.readsPiece(loop.source), R.loopSentence(loop, null), R.readsPiece({kind: "rows", from: "piece"})]));';
        $out = json_decode((string)shell_exec('node -e ' . escapeshellarg($script) . ' 2>&1'), true);
        $this->assertSame([false, 'Pour chaque ligne de la table « Clients 2026 »', true], $out);
    }

    // ------------------------------------------------------------------
    // Livraison dans une table
    // ------------------------------------------------------------------

    private static function tableDelivery(array $d, array $piece = ['key' => 'relances', 'name' => 'Relances', 'form' => 'table']): array
    {
        return WorkflowSpec::normalize([
            'nodes' => [['key' => 'a', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'x', 'output' => $piece]],
            'end' => ['pieces' => [$piece['key']], 'deliveries' => [$d + ['type' => 'table', 'pieces' => [$piece['key']]]]],
        ]);
    }

    public function testUneLivraisonEcritDansUneTable(): void
    {
        $def = self::tableDelivery(['kb_id' => 9, 'write' => 'mettre_a_jour', 'key' => 'Email', 'add_missing' => true, 'mode' => 'auto']);
        $d = $def['end']['deliveries'][0];
        $this->assertSame(['type' => 'table', 'kb_id' => 9, 'pieces' => ['relances'], 'write' => 'mettre_a_jour', 'key' => 'Email', 'key_source' => '', 'add_missing' => true, 'mode' => 'auto'], $d);
        $this->assertSame('ajouter', self::tableDelivery(['kb_id' => 9, 'write' => 'n_importe'])['end']['deliveries'][0]['write']);
        $this->assertSame('Écriture dans une table', WorkflowDeliveries::label($d));

        $columns = WorkTableOps::columns(['Email', 'Statut']);
        $args = WorkflowTables::specArgs($d, $columns);
        $this->assertSame(['mode' => 'mettre_a_jour', 'cle' => 'Email', 'cle_source' => 'Email', 'ajouter_absents' => true, 'colonnes' => ['email', 'statut']], $args);
        $spec = KbTableWrite::spec(WorkTableOps::columns(['Nom', 'Email']), $columns, $args);
        $this->assertSame(['statut'], $spec['columns'], 'La clé n’est pas reportée sur elle-même.');
        $this->assertSame(['mode' => 'ajouter'], WorkflowTables::specArgs(['write' => 'ajouter'], $columns));
    }

    public function testCeQueAReglerDitDUneLivraisonDansUneTable(): void
    {
        $ctx = ['delivery_tables' => [9 => ['name' => 'Clients 2026', 'columns' => [['key' => 'email', 'label' => 'Email'], ['key' => 'statut', 'label' => 'Statut']], 'rows' => 3]], 'auto' => true];
        $msgs = static fn(array $def, array $ctx): array => array_column(array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => str_starts_with($i['target'], 'delivery:')), 'text');

        $this->assertSame([], $msgs(self::tableDelivery(['kb_id' => 9]), $ctx));
        $this->assertContains('Choisissez la table où écrire.', $msgs(self::tableDelivery([]), $ctx));
        $this->assertContains('Cette table n’existe plus, vous ne la gérez plus, ou elle est en lecture seule pour MIA (réglage de sa fiche).', $msgs(self::tableDelivery(['kb_id' => 4]), $ctx));
        $this->assertContains('Choisissez la colonne de la table qui identifie une ligne (email, nom, référence).', $msgs(self::tableDelivery(['kb_id' => 9, 'write' => 'mettre_a_jour']), $ctx));
        $this->assertContains('La colonne « Téléphone » n’est plus dans la table : choisissez-en une autre.', $msgs(self::tableDelivery(['kb_id' => 9, 'write' => 'mettre_a_jour', 'key' => 'Téléphone']), $ctx));
        $fiche = ['key' => 'suivi', 'name' => 'Suivi', 'form' => 'fiche', 'fields' => [['key' => 'f1', 'label' => 'Statut', 'type' => 'text']]];
        $this->assertContains('La Fiche « Suivi » n’a pas de champ « Email » : la ligne à mettre à jour ne se retrouverait pas.',
            $msgs(self::tableDelivery(['kb_id' => 9, 'write' => 'mettre_a_jour', 'key' => 'Email'], $fiche), $ctx));
        $this->assertContains('« Note » ne s’écrit pas dans une table : choisissez un Tableau, une Fiche ou un classeur Excel.',
            $msgs(self::tableDelivery(['kb_id' => 9], ['key' => 'note', 'name' => 'Note', 'form' => 'text']), $ctx));
        $this->assertContains('Chaque exécution remplace TOUT le contenu de la table (son historique permet de revenir en arrière).',
            $msgs(self::tableDelivery(['kb_id' => 9, 'write' => 'remplacer']), $ctx));
        $this->assertContains('« Agir sans validation » n’est pas autorisé par l’administrateur.', $msgs(self::tableDelivery(['kb_id' => 9, 'mode' => 'auto']), ['auto' => false] + $ctx));
        $this->assertTrue(WorkflowSpec::publishable(WorkflowSpec::issues(self::tableDelivery(['kb_id' => 9, 'write' => 'remplacer']), $ctx)), 'Remplacer avertit, sans bloquer.');
    }

    public function testUneEtapeQuiLitUneTableAvecUnModeleSansOutilsEstAvertie(): void
    {
        $def = WorkflowSpec::normalize([
            'nodes' => [['key' => 'a', 'type' => 'step', 'title' => 'Compter', 'instruction' => 'Compte les clients de Paris.', 'kb_ids' => [12], 'llm' => 'deepseek::x',
                'output' => ['key' => 'n', 'name' => 'Nombre', 'form' => 'text']]],
            'end' => ['pieces' => ['n']],
        ]);
        $ctx = ['kbs' => [12 => ['name' => 'Clients 2026', 'table' => true, 'rows' => 300, 'reason' => null]], 'llms' => ['deepseek::x' => 'X', 'openai::o3' => 'o3'], 'tool_llms' => ['deepseek::x' => false, 'openai::o3' => true], 'default_llm' => 'openai::o3'];
        $warn = array_column(WorkflowSpec::issues($def, $ctx), 'text');
        $this->assertContains('Le modèle de cette étape ne sait pas utiliser les outils : la table « Clients 2026 » ne sera lue qu’en aperçu (ses 30 premières lignes). Choisissez un modèle qui sait utiliser les connecteurs pour la lire en entier.', $warn);
        $def['nodes'][0]['llm'] = 'openai::o3';
        $this->assertSame([], array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => str_contains($i['text'], 'aperçu')));
    }

    // ------------------------------------------------------------------
    // Aperçu orienté par la question (condition, décision, sans outils)
    // ------------------------------------------------------------------

    public function testLAperçuMontreDAbordLesLignesQuiCitentLaQuestion(): void
    {
        $rows = [];
        for ($i = 1; $i <= 80; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'ville' => $i === 57 ? 'Bordeaux' : 'Lyon'];
        }
        $pick = KbTablePrompt::pickRows($rows, 'Le client de Bordeaux a-t-il payé ?', 5);
        $this->assertTrue($pick['matched']);
        $this->assertSame(['Client 57'], array_column($pick['rows'], 'nom'), '« client » est partout : seul « Bordeaux » distingue une ligne.');
        $rows[3]['ville'] = 'Bordeaux';
        $rows[9]['nom'] = 'Payé Bordeaux';
        $this->assertSame(['Client 4', 'Payé Bordeaux', 'Client 57'], array_column(KbTablePrompt::pickRows($rows, 'Le client de Bordeaux a-t-il payé ?', 5)['rows'], 'nom'),
            'Les lignes qui citent la question, dans l’ordre de la table.');
        $this->assertSame(['Client 1', 'Client 2'], array_slice(array_column(KbTablePrompt::pickRows($rows, 'xyzw', 5)['rows'], 'nom'), 0, 2), 'Aucun mot trouvé : le début.');
        $this->assertFalse(KbTablePrompt::pickRows(array_slice($rows, 0, 3), 'Bordeaux', 5)['matched'], 'Une petite table se montre en entier.');
    }

    // ------------------------------------------------------------------
    // creer_workflow
    // ------------------------------------------------------------------

    public function testCreerWorkflowConnaitLesTables(): void
    {
        $choices = [
            'profiles' => [], 'llms' => ['openai::o3' => 'o3'], 'sources' => [], 'kbs' => [12 => ['name' => 'Clients 2026', 'table' => true, 'rows' => 40, 'reason' => null]],
            'tools' => [], 'creations' => [], 'workflows' => [], 'delivery_kbs' => [],
            'tables' => [['id' => 12, 'name' => 'Clients 2026', 'rows' => 40, 'columns' => [['label' => 'Nom', 'type' => 'text'], ['label' => 'Email', 'type' => 'text'], ['label' => 'Montant', 'type' => 'number']]]],
            'delivery_tables' => [12 => ['name' => 'Clients 2026', 'columns' => [['key' => 'email', 'label' => 'Email']], 'rows' => 40]],
        ];
        $guide = WorkflowDraft::schemaGuide($choices);
        $this->assertStringContainsString('Clients 2026 (table, 40 lignes)', $guide);
        $this->assertStringContainsString('« parcourir »: « lignes » + « table »', $guide);
        $this->assertStringContainsString('{"type": "table", "table": "nom d\'une table"', $guide);

        $draft = WorkflowDraft::fromArgs([
            'nom' => 'Relances', 'objectif' => 'Relancer',
            'briques' => [[
                'type' => 'boucle', 'titre' => 'Chaque client', 'parcourir' => 'lignes', 'table' => 'clients 2026', 'colonnes' => ['Email', 'Montant'],
                'element' => 'Client', 'briques' => [['type' => 'etape', 'titre' => 'Relancer', 'consigne' => 'Relance [Client.Email].', 'piece' => ['nom' => 'Relance', 'forme' => 'fiche', 'champs' => [['nom' => 'Email'], ['nom' => 'Statut']]]]],
                'resultat' => 'Relances',
            ]],
            'livrable' => ['Relances'],
            'livraisons' => [['type' => 'table', 'table' => 'Clients 2026', 'pieces' => ['Relances'], 'ecriture' => 'mettre_a_jour', 'cle' => 'Email']],
        ], $choices);
        $def = $draft['definition'];
        $notes = (array)($draft['notes'] ?? []);
        $loop = $def['nodes'][0];
        $this->assertSame(['kind' => 'rows', 'from' => 'table', 'table_id' => 12, 'table' => 'Clients 2026'], array_intersect_key($loop['source'], array_flip(['kind', 'from', 'table_id', 'table'])));
        $this->assertSame(['Email', 'Montant'], array_column($loop['source']['columns'], 'label'));
        $this->assertSame('number', $loop['source']['columns'][1]['type']);
        $d = $def['end']['deliveries'][0];
        $this->assertSame(['table', 12, 'mettre_a_jour', 'Email'], [$d['type'], $d['kb_id'], $d['write'], $d['key']]);
        $this->assertSame([], array_filter($notes, static fn($n) => str_contains((string)$n, 'table')), implode("\n", $notes));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeTourDUneEtapeOuvreLesTablesSansEnrichissementNiActionParLigne(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('$tablesByTools = $supportsTools && $tableKbs !== [];', $chat, 'Une table cochée pour une étape se lit par les outils.');
        $this->assertStringContainsString("WorkflowTables::materialize(\$convId, \$userId, (int)(\$workflowStep['run_id'] ?? 0), (array)(\$workflowStep['tables'] ?? []))", $chat);
        $this->assertLessThan(strpos($chat, '$tableNames       = $tablesPossible ? SqlWorkTableStore::names($convId)'), strpos($chat, '$stepTables       ='),
            'Les pièces sont rangées AVANT de lire les noms : une table permanente ne prend pas leur nom.');
        $this->assertStringContainsString("\$workflowStep !== null ? '' : (string)\$llm,", $chat, 'Pas d’enrichissement de fond dans une étape.');
        $this->assertStringContainsString("\$workflowStep !== null ? ['pieces' => \$stepTables, 'output' => \$stepOutputTable] : []", $chat);
        $this->assertStringContainsString('$tableActions = $unattended ? [] : [', $chat, 'Aucune action par ligne dans un tour sans personne.');

        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("'tables'         => WorkflowTables::readTablePieces(\$def, \$node, \$artifacts),", $engine);
        $extract = strpos($engine, 'ArtifactContract::extract($piece, $text)');
        $fromReply = strpos($engine, "WorkflowTables::fromReply(\$text, (int)\$run['conversation_id'])");
        $this->assertNotFalse($fromReply);
        $this->assertLessThan($extract, $fromReply, 'La table désignée passe AVANT la lecture du tableau Markdown.');
        $this->assertStringContainsString('$content = $fromTable !== null ? $read[\'content\'] : WorkflowInserts::expandContent(', $engine,
            'Les cellules d’une table (données de tiers) ne sont jamais remplies par des repères.');

        $tools = self::src('src/Agent/Tools/WorkTableTools.php');
        $this->assertStringContainsString('$refusal  = $scope->collectRefusal($source, self::str($args[\'dossier\'] ?? \'\'));', $tools);
        $this->assertStringContainsString('$query    = $scope->collectQuery($source, $asked);', $tools);
        $this->assertStringContainsString('$res[\'rows\'] = $scope->filterFileRows($res[\'rows\']);', $tools);

        $runner = self::src('src/Workflow/LoopRunner.php');
        $this->assertStringContainsString("WorkflowTables::loopGrid((int)(\$source['table_id'] ?? 0), \$user)", $runner);
        $tables = self::src('src/Workflow/WorkflowTables.php');
        $this->assertStringContainsString('KnowledgeBase::selectableForUser($user)', $tables, 'Une table se parcourt avec les droits de la personne, au moment T.');
        $this->assertStringContainsString("WorkTableTools::PERM_WRITE", $tables, 'La livraison passe par la carte d’écriture de table.');
        $this->assertStringContainsString("'table' => self::table(\$run, \$user, \$d, \$messageId) + ['label' => \$label],", self::src('src/Workflow/WorkflowDeliveries.php'));
        $this->assertStringContainsString("decideForWorkflow(\$convId, \$user, \$id, 'approve')", self::src('src/Workflow/WorkflowDeliveries.php'));
    }

    public function testLEditeurProposeLesTables(): void
    {
        $editor = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("froms.push(['table', T('Une table de vos bases'), 'table'])", $editor);
        $this->assertStringContainsString('data-act-change="loop-table"', $editor);
        $this->assertStringContainsString("['email', 'drive', 'slack', 'kb', 'table']", $editor);
        $this->assertStringContainsString("case 'dl-write':", $editor);
        $this->assertStringContainsString("if (kb.table) { return T('Table · {lignes}'", $editor);
        $catalog = self::src('src/Workflow/WorkflowCatalog.php');
        $this->assertStringContainsString("\$choices['tables'] = WorkflowTables::loopTables(", $catalog);
        $this->assertStringContainsString("\$choices['delivery_tables'] = WorkflowTables::deliveryTables(\$user);", $catalog);
        $this->assertStringContainsString("'tableWrites' => [", $catalog);
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $s = self::src($f);
            $this->assertGreaterThan(strpos($s, 'WorkflowCall.php'), strpos($s, 'WorkflowTables.php'), $f);
        }
    }
}
