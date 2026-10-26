<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Modèles de workflows livrés (WorkflowTemplates) — cf. CLAUDE.md
 * « Workflows ». Ils doivent se publier tels quels chez une personne qui a
 * les services, montrer À EUX TOUS l'étendue des workflows, et s'adapter à ce
 * que la personne a (Outlook plutôt que Gmail, outil de création ouvert).
 */
final class WorkflowTemplatesTest extends TestCase
{
    /** Méthode qui écrit chaque modèle (lecture du modèle brut, avant normalisation). */
    private const METHODS = [
        'tri_demandes' => 'triDemandes', 'appel_offres' => 'appelOffres', 'compte_rendu' => 'compteRendu',
        'rendez_vous' => 'rendezVous', 'facture' => 'facture', 'veille' => 'veille',
        'lancement' => 'lancement', 'accueil' => 'accueil', 'demande_site' => 'demandeSite',
        'relance_clients' => 'relanceClients', 'pieces_jointes' => 'piecesJointes',
    ];

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Ce qu'a une personne équipée (forme de WorkflowCatalog::choices). */
    private static function choices(array $sources = []): array
    {
        $src = static fn(string $label, bool $connected = true): array => ['label' => $label, 'connected' => $connected];
        return [
            'profiles'    => [],
            'llms'        => ['openai::gpt-5.6' => 'GPT-5.6'],
            'tool_llms'   => ['openai::gpt-5.6' => true],
            'default_llm' => 'openai::gpt-5.6',
            'sources'     => $sources !== [] ? $sources : [
                'web' => $src('Internet'), 'gmail' => $src('Gmail'), 'outlook' => $src('Outlook', false),
                'calendar' => $src('Agenda Google'), 'tasks' => $src('Tâches Google'),
                'slack' => $src('Slack'), 'drive' => $src('Google Drive'),
            ],
            'kbs'         => [],
            'tools'       => [],
            'creations'   => [
                11 => ['id' => 11, 'name' => 'Images', 'type' => 'image', 'kind' => 'image', 'reason' => null, 'can_edit' => true],
                12 => ['id' => 12, 'name' => 'Pages', 'type' => 'html', 'kind' => 'page', 'reason' => null, 'can_edit' => false],
                13 => ['id' => 13, 'name' => 'Cartes', 'type' => 'map', 'kind' => 'map', 'reason' => null, 'can_edit' => false],
            ],
            'delivery_kbs' => [],
            'auto'        => true,
        ];
    }

    /** Clés de toutes les briques d'un modèle brut, voies et corps de boucle compris. */
    private static function rawKeys(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = $n['key'];
            foreach (['yes', 'no'] as $lane) {
                $out = array_merge($out, self::rawKeys($n[$lane]['nodes'] ?? []));
            }
            foreach ($n['routes'] ?? [] as $r) {
                $out = array_merge($out, self::rawKeys($r['nodes'] ?? []));
            }
            $out = array_merge($out, self::rawKeys($n['body'] ?? []));
        }
        return $out;
    }

    private static function raw(string $key): array
    {
        $m = new ReflectionMethod(WorkflowTemplates::class, self::METHODS[$key]);
        return $m->invoke(null);
    }

    public function testChaqueModeleSePublieSansRienARegler(): void
    {
        $this->assertSame(array_keys(self::METHODS), WorkflowTemplates::KEYS);
        $colors = [];
        foreach (WorkflowTemplates::KEYS as $key) {
            $tpl = WorkflowTemplates::get($key, self::choices());
            $this->assertNotNull($tpl, $key);
            $def = $tpl['definition'];
            $raw = self::raw($key)['definition'];

            // Rien de perdu à la normalisation : ni brique, ni pièce du livrable.
            $this->assertSame(self::rawKeys($raw['nodes']), array_column(WorkflowSpec::flatNodes($def), 'key'), "$key : briques intactes (clés et ordre, borne de 15)");
            $this->assertSame($raw['end']['pieces'], $def['end']['pieces'], "$key : pièces du livrable");
            $this->assertCount(count($raw['end']['deliveries'] ?? []), $def['end']['deliveries'], "$key : livraisons");
            $this->assertNotSame([], $def['end']['pieces'], "$key : un livrable");
            $this->assertNotSame('', $def['objective'], "$key : un objectif");

            // Rien à régler : ni blocage, ni avertissement.
            $issues = WorkflowSpec::issues($def, self::choices());
            $this->assertSame([], array_map(static fn($i) => $i['node'] . ' — ' . $i['text'], $issues), "$key : rien « À régler »");

            // Les citations désignent des pièces disponibles à leur brique.
            foreach (WorkflowSpec::flatNodes($def) as $node) {
                $avail = WorkflowSpec::available($def, $node['key']);
                foreach (['instruction', 'question', 'message', 'note'] as $f) {
                    foreach (WorkflowSpec::mentions(is_string($node[$f] ?? null) ? $node[$f] : '') as $k) {
                        $this->assertArrayHasKey($k, $avail, "$key / {$node['key']} cite @{{$k}}");
                    }
                }
            }
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $tpl['color']);
            $colors[] = strtolower($tpl['color']);
            $this->assertLessThanOrEqual(260, mb_strlen($tpl['description']), "$key : description courte");
        }
        $this->assertSame($colors, array_values(array_unique($colors)), 'Une couleur par modèle.');
        $this->assertNull(WorkflowTemplates::get('inconnu'));
        $this->assertCount(count(WorkflowTemplates::KEYS), WorkflowTemplates::catalog());
    }

    /** À eux tous, les modèles montrent l'étendue des workflows. */
    public function testLesModelesCouvrentLesFonctionnalites(): void
    {
        $seen = ['type' => [], 'loop' => [], 'condition' => [], 'trigger' => [], 'delivery' => [], 'action' => [],
            'create' => [], 'format' => [], 'wait' => [], 'form' => [], 'stop' => [], 'misc' => []];
        foreach (WorkflowTemplates::KEYS as $key) {
            $def = WorkflowTemplates::get($key)['definition'];
            $start = $def['start'];
            $seen['trigger'][] = $start['event']['kind'] ?? ($start['schedule'] !== null ? 'schedule:' . $start['schedule']['rule']['freq'] : 'manual');
            if ($start['chat'] && $start['event'] === null) {
                $seen['trigger'][] = 'chat';
            }
            foreach ($start['fields'] as $f) {
                $seen['misc'][] = 'field:' . $f['type'];
            }
            foreach ($def['end']['deliveries'] as $d) {
                $seen['delivery'][] = $d['type'];
                if (($d['to_field'] ?? '') !== '') {
                    $seen['misc'][] = 'to_field';
                }
                if (($d['attach'] ?? []) !== []) {
                    $seen['misc'][] = 'attach';
                }
            }
            foreach (WorkflowSpec::flatNodes($def) as $n) {
                $seen['type'][] = $n['type'];
                match ($n['type']) {
                    'loop'      => $seen['loop'] = array_merge($seen['loop'], [$n['mode'] . ($n['over_field'] !== '' ? ':field' : '')],
                        ($n['source']['kind'] ?? '') !== '' ? ['source:' . $n['source']['kind']] : [],
                        !empty($n['review_actions']) ? ['review_actions'] : [],
                        ($n['filter']['rule']['items'] ?? []) !== [] ? ['filter'] : [],
                        ($n['filter']['dedupe'] ?? '') !== '' ? ['dedupe'] : [],
                        array_values(array_map(static fn($c) => 'body:condition', array_filter($n['body'], static fn($c) => $c['type'] === 'condition')))),
                    'condition' => $seen['condition'][] = $n['mode'] . ($n['mode'] === 'rule' ? ':' . $n['rule']['join'] : ':doubt-' . $n['doubt']),
                    'action'    => $seen['action'][] = $n['target'],
                    'create'    => $seen['create'][] = $n['kind'],
                    'wait'      => $seen['wait'][] = $n['mode'],
                    'stop'      => $seen['stop'][] = $n['outcome'],
                    default     => null,
                };
                if (isset($n['output']['form'])) {
                    $seen['form'][] = $n['output']['form'];
                }
                if (($n['output']['form'] ?? '') === 'document') {
                    $seen['format'][] = $n['output']['format'];
                }
                if (!empty($n['review'])) {
                    $seen['misc'][] = 'review';
                }
                if (($n['filters']['recipients'] ?? '') !== '') {
                    $seen['misc'][] = 'filter:recipients';
                }
                if (($n['filters']['mail']['days'] ?? 0) > 0) {
                    $seen['misc'][] = 'filter:mail';
                }
                if (($n['on_error']['retries'] ?? 0) > 0) {
                    $seen['misc'][] = 'retries';
                }
                if (($n['on_error']['then'] ?? '') === 'skip') {
                    $seen['misc'][] = 'skip';
                }
                foreach ((array)($n['sources'] ?? []) as $s) {
                    $seen['misc'][] = 'source:' . $s;
                }
                if ($n['type'] === 'route' && count($n['routes']) >= 3) {
                    $seen['misc'][] = 'route:3+';
                }
            }
            if (($start['event']['filters']['drive']['kind'] ?? '') !== '') {
                $seen['misc'][] = 'filter:event';
            }
        }
        $has = function (string $what, array $expected) use ($seen): void {
            $missing = array_values(array_diff($expected, $seen[$what]));
            $this->assertSame([], $missing, "Aucun modèle ne montre : $what " . implode(', ', $missing));
        };
        $has('type', ['step', 'action', 'create', 'condition', 'route', 'loop', 'gate', 'wait', 'notify', 'stop']);
        $has('loop', ['each:field', 'until', 'source:rows', 'source:attachments', 'review_actions', 'filter', 'dedupe', 'body:condition']);
        $has('condition', ['rule:all', 'rule:any', 'question:doubt-ask', 'question:doubt-no']);
        $has('trigger', ['manual', 'chat', 'schedule:weekdays', 'schedule:weekly', 'email', 'file', 'webhook']);
        $has('delivery', ['email', 'drive', 'slack']);
        $has('action', ['gmail', 'outlook', 'calendar', 'tasks', 'mstodo', 'drive']);
        $has('create', ['image', 'page', 'map']);
        $has('format', ['docx', 'pdf', 'pptx']);
        $has('wait', ['delay', 'date']);
        $has('form', ['text', 'list', 'table', 'fiche', 'document']);
        $has('stop', ['done', 'stopped']);
        $has('misc', ['review', 'filter:recipients', 'filter:mail', 'filter:event', 'retries', 'skip', 'source:web',
            'source:drive', 'source:gmail', 'source:calendar', 'route:3+', 'to_field', 'attach',
            'field:file', 'field:email', 'field:date', 'field:longtext', 'field:choice']);
    }

    public function testUnConnecteurAbsentCedeLaPlaceASonEquivalent(): void
    {
        $src = static fn(string $label, bool $connected = true): array => ['label' => $label, 'connected' => $connected];
        $microsoft = self::choices([
            'web' => $src('Internet'), 'outlook' => $src('Outlook'), 'mscalendar' => $src('Calendrier Outlook'),
            'mstodo' => $src('Microsoft To Do'), 'gmail' => $src('Gmail', false), 'drive' => $src('Google Drive'),
        ]);

        // Un email reçu dans Outlook, les réponses par Outlook, l'agenda Outlook.
        $tri = WorkflowTemplates::get('tri_demandes', $microsoft)['definition'];
        $this->assertSame('outlook', $tri['start']['event']['source']);
        $byKey = array_column(WorkflowSpec::flatNodes($tri), null, 'key');
        $this->assertSame('outlook', $byKey['reponse_devis']['target']);
        $this->assertSame('mstodo', $byKey['suivi']['target']);
        $this->assertSame(['mscalendar'], $byKey['creneaux']['sources']);
        $this->assertSame([], WorkflowSpec::issues($tri, $microsoft), 'Adapté, il se publie sans rien à régler.');

        // La livraison par email suit, la livraison Slack (absente) est retirée.
        $offre = WorkflowTemplates::get('appel_offres', $microsoft)['definition'];
        $this->assertSame('outlook', $offre['end']['deliveries'][0]['source']);
        $veille = WorkflowTemplates::get('veille', $microsoft)['definition'];
        $this->assertSame(['drive'], array_column($veille['end']['deliveries'], 'type'), 'Slack absent de l’instance : sa livraison disparaît, Drive reste.');

        // Et dans l'autre sens : un modèle écrit pour Microsoft chez qui a Google.
        $cr = WorkflowTemplates::get('compte_rendu', self::choices())['definition'];
        $byKey = array_column(WorkflowSpec::flatNodes($cr), null, 'key');
        $this->assertSame('tasks', $byKey['taches']['target'], 'To Do absent : Google Tasks.');
        $this->assertSame('gmail', $byKey['recap']['target'], 'Outlook non relié, Gmail relié : Gmail.');

        // Aucun service de messagerie sur l'instance : l'email du livrable ne
        // pourrait jamais partir, il est retiré ; l'action, elle, reste à régler.
        $sansMail = self::choices(['web' => $src('Internet'), 'drive' => $src('Google Drive'), 'slack' => $src('Slack')]);
        $offre = WorkflowTemplates::get('appel_offres', $sansMail)['definition'];
        $this->assertSame(['drive'], array_column($offre['end']['deliveries'], 'type'));
        $this->assertSame('gmail', array_column(WorkflowSpec::flatNodes($offre), null, 'key')['decliner']['target']);

        // Rien chez la personne : le modèle reste tel qu'il est écrit.
        $nu = WorkflowTemplates::get('compte_rendu')['definition'];
        $byKey = array_column(WorkflowSpec::flatNodes($nu), null, 'key');
        $this->assertSame('mstodo', $byKey['taches']['target']);
        $this->assertSame('outlook', $byKey['recap']['target']);

        // Un connecteur relié l'emporte sur un connecteur seulement proposé, jamais l'inverse.
        $deux = self::choices();
        $deux['sources']['outlook'] = $src('Outlook');
        $cr = WorkflowTemplates::get('compte_rendu', $deux)['definition'];
        $this->assertSame('outlook', array_column(WorkflowSpec::flatNodes($cr), null, 'key')['recap']['target'], 'Les deux reliés : le choix du modèle reste.');
    }

    public function testUneCreationRecoitLOutilOuvertALaPersonne(): void
    {
        $def = WorkflowTemplates::get('lancement', self::choices())['definition'];
        $byKey = array_column(WorkflowSpec::flatNodes($def), null, 'key');
        $this->assertSame(11, $byKey['affiche']['tool_id']);
        $this->assertSame(12, $byKey['invitation']['tool_id']);
        $this->assertSame(13, $byKey['plan']['tool_id']);

        // Un outil hors service est écarté ; aucun outil : « À régler » le dit.
        $choices = self::choices();
        $choices['creations'][11]['reason'] = 'Hors service';
        $choices['creations'][14] = ['id' => 14, 'name' => 'Autres images', 'type' => 'image', 'kind' => 'image', 'reason' => null, 'can_edit' => false];
        unset($choices['creations'][13]);
        $def = WorkflowTemplates::get('lancement', $choices)['definition'];
        $byKey = array_column(WorkflowSpec::flatNodes($def), null, 'key');
        $this->assertSame(14, $byKey['affiche']['tool_id']);
        $this->assertNull($byKey['plan']['tool_id']);
        $texts = array_column(array_filter(WorkflowSpec::issues($def, $choices), static fn($i) => $i['node'] === 'plan'), 'text');
        $this->assertContains(t('Choisissez l’outil qui fait la création.'), $texts);
    }

    /** Galerie : la couverture des vignettes, et les briques que chaque modèle met en avant. */
    public function testLaGalerieMontreLeDerouleEtLesBriques(): void
    {
        $byKey = array_column(WorkflowTemplates::catalog(), null, 'key');
        $this->assertSame(['route', 'action', 'gate', 'notify', 'stop'], $byKey['tri_demandes']['features']);
        $this->assertSame(['condition', 'loop', 'notify', 'stop'], $byKey['rendez_vous']['features']);
        $this->assertContains('create', $byKey['lancement']['features']);
        $this->assertContains('wait', $byKey['accueil']['features']);
        foreach ($byKey as $key => $tpl) {
            $this->assertSame(WorkflowTemplates::get($key)['definition'], $tpl['definition'], "$key : la couverture dessine le modèle tel qu’il est écrit");
            $this->assertSame(array_values(array_intersect(WorkflowCover::FEATURE_ORDER, $tpl['features'])), $tpl['features']);
        }
        // Une relecture d'étape compte pour une validation.
        $this->assertSame(['gate'], WorkflowCover::features(WorkflowSpec::normalize(['nodes' => [
            ['type' => 'step', 'title' => 'A', 'instruction' => 'x', 'review' => true, 'output' => ['name' => 'P']],
        ]])));

        $tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/workflows/index.php');
        $block = substr($tpl, (int)strpos($tpl, '$templates as $tpl'));
        $this->assertStringContainsString('WorkflowCover::svg($tdef)', $block);
        $this->assertStringContainsString('class="wf-cover-card is-template"', $block);
        $this->assertStringContainsString('class="wf-cc-feats"', $block);
        $this->assertStringContainsString('name="mode" value="template"', $block);
        $this->assertStringNotContainsString('wf-cc-link', $block, 'Utiliser un modèle crée un workflow : pas de lien étiré, un bouton.');
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.wf-cover-card.is-template:hover { transform: none; }', $css);
        $this->assertStringContainsString('.wf-cc-feats li svg', $css);
    }

    public function testLeControleurAdapteLeModeleALaPersonne(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/WorkflowController.php');
        $this->assertStringContainsString("WorkflowTemplates::get((string)(\$_POST['template'] ?? ''), WorkflowCatalog::choices(\$user))", $src);
    }

    public function testLesModelesSontTraduits(): void
    {
        I18n::use('en');
        try {
            $tpl = WorkflowTemplates::get('facture');
            $this->assertSame('Supplier invoice → payment', $tpl['name']);
            $this->assertSame('Start', $tpl['definition']['start']['title']);
            $byKey = array_column(WorkflowSpec::flatNodes($tpl['definition']), null, 'key');
            $this->assertSame('Three days before the due date', $byKey['avant_echeance']['title']);
        } finally {
            I18n::use('fr');
        }
    }
}
