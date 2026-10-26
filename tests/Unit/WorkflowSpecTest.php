<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Définition d'un workflow : forme canonique, pièces, enchaînement, pièces
 * disponibles à chaque point, « À régler ». Entièrement pur.
 */
final class WorkflowSpecTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Le workflow d'appel d'offres de la proposition, en abrégé. */
    public static function aoDefinition(): array
    {
        return WorkflowSpec::normalize([
            'objective' => 'Remettre un mémoire technique relu.',
            'start' => ['fields' => [
                ['key' => 'dossier', 'label' => 'Dossier de consultation', 'type' => 'file', 'required' => true],
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'contact', 'label' => 'Contact acheteur', 'type' => 'email', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'analyse', 'type' => 'step', 'title' => 'Analyser le dossier', 'instruction' => 'Résume @{dossier}.',
                 'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'fiche', 'fields' => [
                     ['label' => 'Objet', 'type' => 'text'], ['label' => 'Budget', 'type' => 'number'],
                 ]]],
                ['key' => 'go', 'type' => 'condition', 'title' => 'On y va ?', 'mode' => 'rule',
                 'rule' => ['items' => [['piece' => 'synthese', 'field' => 'budget', 'op' => 'gte', 'value' => '20000']]],
                 'yes' => ['then' => 'continue'],
                 'no' => ['then' => 'stop', 'nodes' => [
                     ['key' => 'decline', 'type' => 'step', 'title' => 'Courrier de déclinaison', 'instruction' => 'Rédige un refus poli à @{depart}.',
                      'output' => ['key' => 'courrier', 'name' => 'Courrier', 'form' => 'text']],
                 ]]],
                ['key' => 'memoire', 'type' => 'step', 'title' => 'Rédiger le mémoire', 'instruction' => 'Rédige à partir de @{synthese}.',
                 'review' => true,
                 'output' => ['key' => 'doc', 'name' => 'Mémoire technique', 'form' => 'document', 'format' => 'docx']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Préparer l’email', 'target' => 'gmail', 'instruction' => 'Envoie @{doc} à @{depart}.'],
            ],
            'end' => ['pieces' => ['doc', 'synthese', 'inconnue']],
        ]);
    }

    public function testNormaliserBorneEtComplete(): void
    {
        $def = self::aoDefinition();
        $this->assertSame(WorkflowSpec::VERSION, $def['v']);
        $this->assertSame('Départ', $def['start']['title']);
        $this->assertCount(4, $def['nodes']);
        $this->assertSame(['doc', 'synthese'], $def['end']['pieces'], 'une pièce inconnue du livrable est retirée');
        $this->assertSame('budget', $def['nodes'][0]['output']['fields'][1]['key']);
        $this->assertSame('approve', $def['nodes'][3]['write'], 'une action est à approuver par défaut');
        $this->assertTrue($def['notify']);
    }

    public function testNormaliserNeLeveJamais(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [['type' => 'inconnu'], 'texte', ['type' => 'step']], 'start' => 'x']);
        $this->assertCount(1, $def['nodes']);
        $this->assertSame('step', $def['nodes'][0]['key']);
        $this->assertSame([], $def['start']['fields']);
    }

    public function testClesUniquesEtValides(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'A B', 'type' => 'step', 'title' => 'Étape'],
            ['key' => 'etape', 'type' => 'step', 'title' => 'Étape'],
            ['type' => 'gate', 'title' => 'Étape'],
        ]]);
        $keys = array_column($def['nodes'], 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
        foreach ($keys as $k) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,23}$/', $k);
        }
    }

    public function testUneVoieNePorteJamaisDeCondition(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [[
            'type' => 'condition', 'title' => 'C', 'no' => ['nodes' => [['type' => 'condition', 'title' => 'D'], ['type' => 'gate', 'title' => 'G']]],
        ]]]);
        $this->assertSame(['gate'], array_column($def['nodes'][0]['no']['nodes'], 'type'));
        $this->assertSame('continue', $def['nodes'][0]['yes']['then'], '« si oui » reprend le rail par défaut');
        $this->assertSame('stop', $def['nodes'][0]['no']['then'], '« si non » l’arrête par défaut');
    }

    public function testPiecesDuDepartEtDesEtapes(): void
    {
        $pieces = WorkflowSpec::pieces(self::aoDefinition());
        $this->assertSame(['depart', 'dossier', 'synthese', 'courrier', 'doc'], array_keys($pieces));
        $this->assertSame('fiche', $pieces['depart']['form']);
        $this->assertSame(['client', 'contact'], array_column($pieces['depart']['fields'], 'key'));
        $this->assertSame('email', $pieces['depart']['fields'][1]['type']);
        $this->assertSame('file', $pieces['dossier']['form']);
        $this->assertSame('go:no', $pieces['courrier']['branch']);
    }

    public function testEnchainement(): void
    {
        $def = self::aoDefinition();
        $this->assertSame('analyse', WorkflowSpec::next($def, 'start'));
        $this->assertSame('go', WorkflowSpec::next($def, 'analyse'));
        $this->assertSame('memoire', WorkflowSpec::next($def, 'go', 'yes'));
        $this->assertSame('decline', WorkflowSpec::next($def, 'go', 'no'));
        $this->assertSame(WorkflowSpec::STOP, WorkflowSpec::next($def, 'decline'), 'voie « si non » qui s’arrête');
        $this->assertSame('mail', WorkflowSpec::next($def, 'memoire'));
        $this->assertSame(WorkflowSpec::END_KEY, WorkflowSpec::next($def, 'mail'));

        $def['nodes'][1]['no']['then'] = 'continue';
        $this->assertSame('memoire', WorkflowSpec::next($def, 'decline'), 'voie qui reprend le rail après la condition');
        $def['nodes'][1]['no']['nodes'] = [];
        $this->assertSame('memoire', WorkflowSpec::next($def, 'go', 'no'));
        $def['nodes'][1]['yes'] = ['nodes' => [['key' => 'oui1', 'type' => 'gate', 'title' => 'G', 'reviews' => []]], 'then' => 'stop'];
        $this->assertSame('oui1', WorkflowSpec::next($def, 'go', 'yes'));
        $this->assertSame(WorkflowSpec::STOP, WorkflowSpec::next($def, 'oui1'));
    }

    public function testPiecesDisponibles(): void
    {
        $def = self::aoDefinition();
        $this->assertSame(['depart', 'dossier'], array_keys(WorkflowSpec::available($def, 'analyse')));
        $this->assertSame(['depart', 'dossier', 'synthese'], array_keys(WorkflowSpec::available($def, 'decline')));
        $this->assertArrayNotHasKey('courrier', WorkflowSpec::available($def, 'mail'), 'une voie qui s’arrête ne remet rien au rail');
        $this->assertArrayHasKey('doc', WorkflowSpec::available($def, 'mail'));

        // Une pièce de « si oui » est garantie après la condition quand « si non » s'arrête.
        $def['nodes'][1]['yes']['nodes'] = [['key' => 'precis', 'type' => 'step', 'title' => 'P', 'instruction' => 'x', 'output' => ['key' => 'notes', 'name' => 'Notes', 'form' => 'text']]];
        $def = WorkflowSpec::normalize($def);
        $this->assertArrayHasKey('notes', WorkflowSpec::available($def, 'memoire'));
        $def['nodes'][1]['no']['then'] = 'continue';
        $this->assertArrayNotHasKey('notes', WorkflowSpec::available($def, 'memoire'), 'deux voies qui reprennent : rien n’est garanti');
    }

    public function testLecturesDUneBrique(): void
    {
        $def = self::aoDefinition();
        $this->assertSame(['dossier'], WorkflowSpec::readsOf($def, $def['nodes'][0]));
        $this->assertSame(['synthese'], WorkflowSpec::readsOf($def, $def['nodes'][1]), 'une règle lit sa Fiche');
        $this->assertSame(['doc', 'depart'], WorkflowSpec::readsOf($def, $def['nodes'][3]));
    }

    public function testMentions(): void
    {
        $pieces = WorkflowSpec::pieces(self::aoDefinition());
        $this->assertSame(['synthese', 'doc'], WorkflowSpec::mentions('Lis @{synthese} puis @{doc} et @{synthese}.'));
        $this->assertSame('Lis « Synthèse ».', WorkflowSpec::renderMentions('Lis @{synthese}.', $pieces));
        $this->assertSame('Lis @{zzz}.', WorkflowSpec::renderMentions('Lis @{zzz}.', $pieces));
    }

    public function testRenvoiDUneValidation(): void
    {
        $def = self::aoDefinition();
        $gate = EngineView::reviewGate($def, 'memoire');
        $this->assertSame('memoire', WorkflowSpec::returnTarget($def, $gate));
        $this->assertNull(WorkflowSpec::returnTarget($def, ['key' => 'g', 'reviews' => ['depart'], 'returns_to' => ''] + $gate), 'une pièce du départ ne se refait pas');
    }

    public function testSilhouetteEtComptes(): void
    {
        $def = self::aoDefinition();
        $this->assertSame('dscsgxe', WorkflowSpec::silhouette($def));
        $c = WorkflowSpec::counts($def);
        $this->assertSame(3, $c['steps']);
        $this->assertSame(1, $c['actions']);
        $this->assertSame(1, $c['conditions']);
        $this->assertSame(1, $c['gates']);
        $this->assertSame(5, $c['pieces']);
    }

    public function testARegler(): void
    {
        $ctx = ['profiles' => [], 'llms' => [], 'sources' => ['gmail' => ['label' => 'Gmail', 'connected' => false]], 'tools' => [], 'auto' => false];
        $def = self::aoDefinition();
        $issues = WorkflowSpec::issues($def, $ctx);
        $texts = array_column($issues, 'text', 'node');
        $this->assertStringContainsString('Reliez d’abord « Gmail »', $texts['mail'] ?? '');
        $this->assertFalse(WorkflowSpec::publishable($issues));

        $ctx['sources']['gmail']['connected'] = true;
        $this->assertTrue(WorkflowSpec::publishable(WorkflowSpec::issues($def, $ctx)));

        $def['nodes'][0]['instruction'] = '';
        $def['nodes'][2]['instruction'] = 'Lis @{courrier}.';
        $blocks = array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => $i['level'] === 'block');
        $nodes = array_column($blocks, 'node');
        $this->assertContains('analyse', $nodes);
        $this->assertContains('memoire', $nodes, 'une consigne qui cite une pièce de voie est à régler');
    }

    public function testUnDepartProgrammeNExigeNiFichierNiValeurSansDefaut(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => [
                'fields' => [['key' => 'f', 'label' => 'Fichier', 'type' => 'file', 'required' => true], ['key' => 't', 'label' => 'Thème', 'type' => 'text', 'required' => true]],
                'schedule' => ['rule' => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], 'timezone' => 'Europe/Paris'],
            ],
            'nodes' => [['type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['name' => 'R', 'form' => 'text']]],
        ]);
        $this->assertNotNull($def['start']['schedule']);
        $issues = array_filter(WorkflowSpec::issues($def, ['profiles' => [], 'llms' => [], 'auto' => true]), static fn($i) => $i['target'] === 'schedule');
        $this->assertCount(2, $issues);
    }

    public function testSlug(): void
    {
        $this->assertSame('memoire_technique', WorkflowSpec::slug('Mémoire technique'));
        $this->assertSame('p_2026_bilan', WorkflowSpec::slug('2026 — bilan'));
        $this->assertSame('l_offre', WorkflowSpec::slug('L’offre'));
    }
}

/** Petite aide de test : la validation implicite d'une étape relue, telle que le moteur la synthétise. */
final class EngineView
{
    public static function reviewGate(array $def, string $stepKey): array
    {
        return WorkflowEngine::nodeFor($def, $stepKey . WorkflowEngine::REVIEW_SUFFIX);
    }
}
