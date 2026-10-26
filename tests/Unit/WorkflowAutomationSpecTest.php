<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Workflows, second lot : boucles, attentes, « Me prévenir », déclencheurs
 * par évènement, livraisons du livrable, reprise sur erreur — la FORME
 * (WorkflowSpec), les boucles (WorkflowLoop), et le rail du navigateur, qui
 * doit compter les pièces exactement comme le serveur.
 */
final class WorkflowAutomationSpecTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Une liste de clients → pour chaque client : une Fiche, relue → tableau rassemblé → attente → prévenir. */
    public static function loopDefinition(): array
    {
        return WorkflowSpec::normalize([
            'objective' => 'Une fiche par client.',
            'start' => ['fields' => [['key' => 'secteur', 'label' => 'Secteur', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister les clients', 'instruction' => 'Liste les clients de @{depart}.',
                    'output' => ['key' => 'clients', 'name' => 'Clients', 'form' => 'list'],
                    'on_error' => ['retries' => 7, 'then' => 'skip']],
                ['key' => 'chaque', 'type' => 'loop', 'title' => 'Pour chaque client', 'mode' => 'each', 'over' => 'clients', 'max' => 9999,
                    'item' => ['key' => 'client', 'name' => 'Client en cours'],
                    'body' => [
                        ['key' => 'fiche_c', 'type' => 'step', 'title' => 'Fiche', 'instruction' => 'Décris @{client}.',
                            'output' => ['key' => 'fiche', 'name' => 'Fiche client', 'form' => 'fiche', 'fields' => [
                                ['label' => 'Nom', 'type' => 'text'], ['label' => 'Relance', 'type' => 'date'],
                            ]]],
                        ['key' => 'rel', 'type' => 'gate', 'title' => 'Relire', 'reviews' => ['fiche']],
                        ['type' => 'route', 'title' => 'Décision interdite dans une boucle'],
                        ['type' => 'loop', 'title' => 'Boucle dans une boucle'],
                    ],
                    'output' => ['key' => 'tableau', 'name' => 'Tableau des clients']],
                ['key' => 'pause', 'type' => 'wait', 'title' => 'Attendre', 'mode' => 'delay', 'amount' => 900, 'unit' => 'days'],
                ['key' => 'ping', 'type' => 'notify', 'title' => 'Prévenir', 'message' => 'Fini pour @{depart.secteur} : @{tableau}.'],
            ],
            'end' => ['pieces' => ['tableau', 'fiche'], 'deliveries' => [
                ['type' => 'email', 'source' => 'outlook', 'to' => 'a@exemple.fr; Bob <b@exemple.fr>', 'subject' => 'Clients @{depart.secteur}', 'message' => '', 'attach' => ['tableau', 'inconnue'], 'mode' => 'nimporte'],
                ['type' => 'kb', 'kb_id' => '4', 'pieces' => ['tableau']],
                ['type' => 'inconnu'],
            ]],
        ]);
    }

    public function testLaFormeEstBorneeEtComplete(): void
    {
        $def = self::loopDefinition();
        $loop = $def['nodes'][1];
        $this->assertSame('loop', $loop['type']);
        $this->assertSame(WorkflowSpec::LOOP_EACH_MAX, $loop['max'], 'au plus LOOP_EACH_MAX éléments');
        $this->assertSame(['step', 'gate'], array_column($loop['body'], 'type'), 'ni décision ni boucle dans une boucle');
        $this->assertSame(['retries' => WorkflowSpec::RETRIES_MAX, 'then' => 'skip'], $def['nodes'][0]['on_error']);
        $wait = $def['nodes'][2];
        $this->assertSame(30, $wait['amount'], 'une attente est bornée à 30 jours');
        $this->assertSame('09:00', $wait['time']);
        $this->assertCount(2, $def['end']['deliveries'], 'une livraison de type inconnu est retirée');
        $mail = $def['end']['deliveries'][0];
        $this->assertSame(['tableau'], $mail['attach'], 'une pièce inconnue ne se joint pas');
        $this->assertSame('approve', $mail['mode'], 'un mode inconnu vaut « à approuver »');
        $this->assertSame(4, $def['end']['deliveries'][1]['kb_id']);
        $this->assertTrue($def['end']['deliveries'][1]['replace']);

        $again = WorkflowSpec::normalize($def);
        $this->assertSame($def, $again, 'normaliser deux fois ne change rien');

        $cond = WorkflowSpec::normalize(['nodes' => [['type' => 'condition', 'title' => 'C', 'yes' => ['nodes' => [['type' => 'loop', 'title' => 'L'], ['type' => 'wait', 'title' => 'W'], ['type' => 'notify', 'title' => 'N']]]]]]);
        $this->assertSame(['wait', 'notify'], array_column($cond['nodes'][0]['yes']['nodes'], 'type'), 'une voie accepte attente et prévenir, pas une boucle');
        $this->assertSame(['retries' => 1, 'then' => 'stop'], $cond['nodes'][0]['on_error'], 'une condition ne s’ignore pas');
    }

    public function testLesPiecesDUneBoucle(): void
    {
        $pieces = WorkflowSpec::pieces(self::loopDefinition());
        $this->assertSame(['depart', 'clients', 'client', 'fiche', 'tableau'], array_keys($pieces));
        $this->assertSame('loop:chaque', $pieces['client']['branch']);
        $this->assertTrue($pieces['client']['item']);
        $this->assertSame('loop:chaque', $pieces['fiche']['branch']);
        $this->assertNull($pieces['tableau']['branch']);
        $this->assertSame('chaque', $pieces['tableau']['producer']);
        $this->assertSame('table', $pieces['tableau']['form'], 'une Fiche par élément se rassemble en tableau');

        $until = self::loopDefinition();
        $until['nodes'][1]['mode'] = 'until';
        $until = WorkflowSpec::normalize($until);
        $p = WorkflowSpec::pieces($until);
        $this->assertArrayNotHasKey('client', $p, 'pas d’élément en cours dans une boucle « jusqu’à »');
        $this->assertSame('fiche', $p['tableau']['form'], 'jusqu’à : la dernière version, sous sa forme');
        $this->assertSame(5, $until['nodes'][1]['max'], 'jusqu’à : 5 tours au plus');
    }

    public function testEnchainementEtDisponibilite(): void
    {
        $def = self::loopDefinition();
        $this->assertSame('chaque', WorkflowSpec::next($def, 'lister'));
        $this->assertSame('rel', WorkflowSpec::next($def, 'fiche_c'));
        $this->assertSame(WorkflowSpec::LOOP_END . 'chaque', WorkflowSpec::next($def, 'rel'), 'la fin du corps rend la main à la boucle');
        $this->assertSame('pause', WorkflowSpec::next($def, 'chaque'), 'après la boucle : la suite du rail');

        $this->assertSame(['depart', 'clients', 'client'], array_keys(WorkflowSpec::available($def, 'fiche_c')));
        $this->assertSame(['depart', 'clients', 'client', 'fiche'], array_keys(WorkflowSpec::available($def, 'rel')));
        $this->assertSame(['depart', 'clients', 'tableau'], array_keys(WorkflowSpec::available($def, 'pause')), 'après la boucle, seule sa pièce rassemblée');
        $this->assertSame(['depart', 'clients', 'client', 'fiche'], array_keys(WorkflowSpec::available($def, 'chaque' . WorkflowSpec::UNTIL_SUFFIX)));
        $this->assertSame(['clients'], WorkflowSpec::readsOf($def, $def['nodes'][1]), 'une boucle lit ce qu’elle parcourt');
        $this->assertSame(['depart', 'tableau'], WorkflowSpec::readsOf($def, $def['nodes'][3]), 'un message cite pièces et champs');
        $this->assertSame('fiche_c', WorkflowSpec::returnTarget($def, $def['nodes'][1]['body'][1]), 'une validation de boucle renvoie à l’étape de la boucle');
        $this->assertSame('dslwne', WorkflowSpec::silhouette($def));
        $c = WorkflowSpec::counts($def);
        $this->assertSame([2, 1, 1, 1, 1, 2], [$c['steps'], $c['loops'], $c['gates'], $c['waits'], $c['notifies'], $c['deliveries']]);
    }

    public function testMentionsDeChamp(): void
    {
        $def = self::loopDefinition();
        $pieces = WorkflowSpec::pieces($def);
        $this->assertSame(['depart', 'tableau'], WorkflowSpec::mentions('@{depart.secteur} et @{tableau}'));
        $this->assertSame([['depart', 'secteur']], WorkflowSpec::fieldMentions('@{depart.secteur} et @{tableau}'));
        $this->assertSame('« Informations de départ › Secteur » et « Tableau des clients »', WorkflowSpec::renderMentions('@{depart.secteur} et @{tableau}', $pieces));
        $this->assertSame('@{depart.zzz}', WorkflowSpec::renderMentions('@{depart.zzz}', $pieces));
    }

    private static function ctx(array $over = []): array
    {
        return $over + [
            'profiles' => [], 'llms' => [], 'tools' => [], 'auto' => true,
            'sources' => [
                'gmail' => ['label' => 'Gmail', 'connected' => true], 'outlook' => ['label' => 'Outlook', 'connected' => true],
                'drive' => ['label' => 'Google Drive', 'connected' => true], 'slack' => ['label' => 'Slack', 'connected' => true],
            ],
            'workflows' => [1 => ['name' => 'Soi'], 2 => ['name' => 'Autre']], 'self_id' => 1,
            'delivery_kbs' => [4 => ['name' => 'Base']],
        ];
    }

    private static function texts(array $def, array $ctx, string $level = WorkflowSpec::LEVEL_BLOCK): array
    {
        return array_column(array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => $i['level'] === $level), 'text');
    }

    public function testARegler(): void
    {
        $def = self::loopDefinition();
        $this->assertSame([], self::texts($def, self::ctx()), implode("\n", self::texts($def, self::ctx())));

        $bad = $def;
        $bad['nodes'][1]['over'] = '';
        $bad['nodes'][1]['body'] = [];
        $bad['nodes'][3]['message'] = '';
        $bad['end']['deliveries'][0]['to'] = 'pas-une-adresse';
        $texts = self::texts(WorkflowSpec::normalize($bad), self::ctx(['delivery_kbs' => []]));
        foreach (['Choisissez la pièce à parcourir', 'Ajoutez au moins une brique dans la boucle', 'Écrivez le message de la notification',
                  'Adresse invalide : pas-une-adresse', 'Choisissez une base de connaissances que vous gérez'] as $attendu) {
            $this->assertNotEmpty(array_filter($texts, static fn($t) => str_contains($t, $attendu)), $attendu . "\n" . implode("\n", $texts));
        }

        $noAuto = $def;
        $noAuto['end']['deliveries'][0]['mode'] = 'send';
        $this->assertContains('« Agir sans validation » n’est pas autorisé par l’administrateur.', self::texts(WorkflowSpec::normalize($noAuto), self::ctx(['auto' => false])));

        $date = $def;
        $date['nodes'][2] = ['key' => 'pause', 'type' => 'wait', 'title' => 'Attendre', 'mode' => 'date', 'piece' => 'depart', 'field' => 'secteur'];
        $this->assertContains('Choisissez le champ date d’une Fiche remise avant l’attente.', self::texts(WorkflowSpec::normalize($date), self::ctx()), 'un champ texte n’est pas une date');
    }

    public function testDeclencheurs(): void
    {
        $base = ['start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'Lis @{evenement}.', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]]];
        $email = $base;
        $email['start']['event'] = ['kind' => 'email', 'source' => 'outlook', 'query' => 'from:x@y.fr', 'timezone' => 'Europe/Paris'];
        $def = WorkflowSpec::normalize($email);
        $this->assertSame('Email reçu', $def['start']['event']['piece_name']);
        $this->assertSame('Europe/Paris', $def['start']['event']['timezone']);
        $p = WorkflowSpec::pieces($def);
        $this->assertSame('fiche', $p['evenement']['form']);
        $this->assertSame(array_column(WorkflowSpec::EMAIL_FIELDS, 'key'), array_column($p['evenement']['fields'], 'key'));
        $this->assertSame(['evenement'], WorkflowSpec::readsOf($def, $def['nodes'][0]));
        $texts = self::texts($def, self::ctx());
        $this->assertContains('Lancé par un évènement, le workflow démarre seul : donnez une valeur par défaut à « Client » ou rendez-le facultatif.', $texts);
        $this->assertNotEmpty(self::texts($def, self::ctx(['sources' => ['outlook' => ['label' => 'Outlook', 'connected' => false]]])));

        $hook = $base;
        $hook['start']['event'] = ['kind' => 'webhook'];
        $this->assertSame([], self::texts(WorkflowSpec::normalize($hook), self::ctx()), 'un appel peut fournir les champs requis');
        $this->assertSame('text', WorkflowSpec::pieces(WorkflowSpec::normalize($hook))['evenement']['form']);

        $self = $base;
        $self['start']['event'] = ['kind' => 'workflow', 'workflow_id' => 1];
        $this->assertContains('Un workflow ne peut pas se lancer lui-même.', self::texts(WorkflowSpec::normalize($self), self::ctx()));

        $auto = $hook;
        $auto['nodes'][0]['write'] = 'auto';
        $warn = self::texts(WorkflowSpec::normalize($auto), self::ctx(), WorkflowSpec::LEVEL_WARN);
        $this->assertNotEmpty(array_filter($warn, static fn($t) => str_contains($t, 'venu de l’extérieur')), 'agir sans validation sur un contenu externe se signale');

        $this->assertNull(WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'pigeon']]])['start']['event']);
    }

    public function testElementsEtRassemblementDUneBoucle(): void
    {
        $list = ['form' => 'list'];
        $this->assertSame(['items' => ['a', 'b'], 'total' => 3], WorkflowLoop::items($list, ['items' => ['a', ' ', 'b', 'c']], '', 2));
        $table = ['form' => 'table'];
        $this->assertSame(['items' => ['Nom : Dupont — Ville : Lyon', 'Nom : Durand'], 'total' => 2],
            WorkflowLoop::items($table, ['rows' => [['Nom', 'Ville'], ['Dupont', 'Lyon'], ['Durand', '']]], '', 10));
        $fiche = ['form' => 'fiche'];
        $this->assertSame(['items' => ['x', 'y'], 'total' => 2], WorkflowLoop::items($fiche, ['fields' => ['l' => ['x', 'y']]], 'l', 10));

        $this->assertSame(['memoire', 3], WorkflowLoop::splitIter('memoire#3'));
        $this->assertSame(['a~review', 12], WorkflowLoop::splitIter('a~review#12'));
        $this->assertSame(['a', null], WorkflowLoop::splitIter('a'));
        $this->assertSame('a#2', WorkflowLoop::iterKey('a', 2));
        $this->assertSame('a', WorkflowLoop::iterKey('a', null));

        $arts = ['depart' => ['content' => ['x' => 1]], 'fiche#1' => ['content' => ['t' => 1]], 'fiche#2' => ['content' => ['t' => 2]], 'client#2' => ['content' => ['text' => 'B']]];
        $view = WorkflowLoop::view($arts, ['fiche', 'client'], 2);
        $this->assertSame(['depart', 'fiche', 'client'], array_keys($view));
        $this->assertSame(['t' => 2], $view['fiche']['content']);
        $this->assertSame(['depart'], array_keys(WorkflowLoop::view($arts, [], 0)), 'hors boucle, les pièces de tour ne se voient pas');

        $src = ['form' => 'fiche', 'fields' => [['key' => 'nom', 'label' => 'Nom', 'type' => 'text'], ['key' => 'n', 'label' => 'Note', 'type' => 'number']]];
        $agg = WorkflowLoop::aggregate(['form' => 'table'], $src, [
            ['item' => 'Client A', 'content' => ['fields' => ['nom' => 'A', 'n' => 3]]],
            ['item' => "Client B\nsuite", 'content' => null, 'note' => 'ignoré'],
        ]);
        $this->assertSame([['Élément', 'Nom', 'Note'], ['Client A', 'A', '3'], ['Client B', 'ignoré', 'ignoré']], $agg['rows']);
        $text = WorkflowLoop::aggregate(['form' => 'text'], ['form' => 'text'], [['item' => 'Un', 'content' => ['text' => 'Premier']], ['item' => 'Deux', 'content' => ['text' => 'Second']]]);
        $this->assertSame("### Un\n\nPremier\n\n### Deux\n\nSecond", $text['text']);
        $this->assertSame(['items' => ['a', 'b', 'c']], WorkflowLoop::aggregate(['form' => 'list'], ['form' => 'list'], [['item' => '1', 'content' => ['items' => ['a', 'b']]], ['item' => '2', 'content' => ['items' => ['c']]]]));
        $last = WorkflowLoop::aggregate(['form' => 'text', 'until' => true], ['form' => 'text'], [['item' => 'T1', 'content' => ['text' => 'v1']], ['item' => 'T2', 'content' => ['text' => 'v2']]]);
        $this->assertSame(['text' => 'v2'], $last, 'jusqu’à : la dernière version');
    }

    /**
     * Le rail du navigateur compte les pièces comme le serveur : mêmes clés,
     * mêmes formes, mêmes branches, mêmes pièces disponibles à chaque brique.
     */
    public function testLeRailDuNavigateurCompteCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $defs = [
            'boucle' => self::loopDefinition(),
            'jusqua' => WorkflowSpec::normalize(['nodes' => [
                ['key' => 'r', 'type' => 'loop', 'title' => 'Améliorer', 'mode' => 'until', 'body' => [
                    ['key' => 'red', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'x', 'output' => ['key' => 'texte', 'name' => 'Texte', 'form' => 'document', 'format' => 'docx']],
                    ['key' => 'note', 'type' => 'step', 'title' => 'Noter', 'instruction' => '@{texte}', 'output' => ['key' => 'avis', 'name' => 'Avis', 'form' => 'fiche', 'fields' => [['label' => 'Note', 'type' => 'number']]]],
                ], 'collect' => 'texte', 'output' => ['key' => 'final', 'name' => 'Version finale']],
            ], 'start' => ['event' => ['kind' => 'email']]]),
            'appel' => WorkflowSpec::normalize(['start' => ['fields' => [['key' => 'f', 'label' => 'Pièce', 'type' => 'file']], 'event' => ['kind' => 'file', 'folder' => 'X']],
                'nodes' => [['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => '@{evenement}',
                    'yes' => ['nodes' => [['key' => 'oui', 'type' => 'step', 'title' => 'O', 'instruction' => 'x', 'output' => ['key' => 'o', 'name' => 'O', 'form' => 'list']]], 'then' => 'continue'],
                    'no' => ['nodes' => [], 'then' => 'stop']],
                    ['key' => 'apres', 'type' => 'loop', 'title' => 'Chaque', 'over' => 'o', 'body' => [['key' => 'w', 'type' => 'wait', 'title' => 'W'], ['key' => 'n', 'type' => 'notify', 'title' => 'N', 'message' => '@{oui}']]]]]),
        ];
        $expected = [];
        foreach ($defs as $name => $def) {
            $keys = array_merge(array_map(static fn($n) => $n['key'], WorkflowSpec::flatNodes($def)), ['end']);
            foreach ($def['nodes'] as $n) {
                if ($n['type'] === 'loop') {
                    $keys[] = $n['key'] . WorkflowSpec::UNTIL_SUFFIX;
                }
            }
            $avail = [];
            foreach ($keys as $k) {
                $avail[$k] = array_keys(WorkflowSpec::available($def, $k));
            }
            $expected[$name] = [
                'pieces' => array_map(static fn($p) => [$p['key'], $p['form'], $p['branch'], $p['producer']], array_values(WorkflowSpec::pieces($def))),
                'available' => $avail,
            ];
        }
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var defs = ' . json_encode($defs, JSON_UNESCAPED_UNICODE) . ';'
            . 'var exp = ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . '; var out = {};'
            . 'Object.keys(defs).forEach(function (n) { var d = defs[n]; var av = {};'
            . ' Object.keys(exp[n].available).forEach(function (k) { av[k] = Object.keys(R.available(d, k)); });'
            . ' out[n] = { pieces: R.pieces(d).map(function (p) { return [p.key, p.form, p.branch, p.producer]; }), available: av }; });'
            . 'process.stdout.write(JSON.stringify(out));';
        $file = tempnam(sys_get_temp_dir(), 'wfrail') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertSame($expected, $got);
    }

    public function testLesChampsDeLEmailRecuSontLesMemesDesDeuxCotes(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/workflow-rail.js');
        foreach (WorkflowSpec::EMAIL_FIELDS as $f) {
            $this->assertStringContainsString("key: '" . $f['key'] . "', label: '" . $f['label'] . "', type: '" . $f['type'] . "'", $js);
        }
        foreach (WorkflowSpec::EVENT_PIECE_NAMES as $kind => $name) {
            $this->assertStringContainsString($kind . ": '" . $name . "'", $js);
        }
    }
}
