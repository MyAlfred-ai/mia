<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Historique d'un workflow sur la vraie base — cf. CLAUDE.md « Workflows » :
 * de vrais lancements menés par le moteur (fin de job SIMULÉE, comme dans
 * WorkflowEnginePipelineTest), puis ce que les deux routes rendent
 * (WorkflowController::historyData / journalData, et les gabarits).
 */
final class WorkflowJournalPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/WorkflowController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'connector_web_enabled', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(7, 'creator');
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    private static function definition(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Contexte', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'synth', 'type' => 'step', 'title' => 'Synthétiser', 'instruction' => 'Résume @{depart}.',
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text']],
                ['key' => 'seuil', 'type' => 'condition', 'title' => 'Gros montant ?', 'mode' => 'rule',
                    'rule' => ['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'montant', 'op' => 'gt', 'value' => '5000']]],
                    'yes' => ['nodes' => [['key' => 'accord', 'type' => 'gate', 'title' => 'Accord', 'reviews' => ['synthese']]], 'then' => 'continue'],
                    'no' => ['nodes' => [], 'then' => 'continue']],
            ],
            'end' => ['pieces' => ['synthese']],
        ]);
    }

    private function publie(): array
    {
        $id = Workflow::create($this->userId, 'Synthèse validée', self::definition());
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf, string $montant): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), ['client' => 'Ville de Lyon', 'montant' => $montant], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    private function repond(int $jobId, string $reponse, array $actions = []): int
    {
        $stmt = $this->db()->prepare('SELECT conversation_id FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $msg = Message::create((int)$stmt->fetchColumn(), 'assistant', $reponse);
        if ($actions !== []) {
            Message::setActions((int)$msg['id'], $actions);
        }
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    private function appelle(string $methode, array $args): mixed
    {
        $m = new ReflectionMethod(WorkflowController::class, $methode);
        return $m->invokeArgs(new WorkflowController(), $args);
    }

    public function testLeJournalDUnLancementMeneAuBout(): void
    {
        $wf = $this->publie();
        $res = $this->lance($wf, '6 200');
        $replyId = $this->repond($res['job_ids'][0], 'Le marché porte sur la rénovation de **trois écoles**.', [
            ['t' => 'drive_search', 'l' => 'Recherche dans Google Drive', 'd' => 'écoles'],
        ]);
        // Une action proposée par l'étape, refusée ensuite.
        $cardId = DrivePendingAction::create($this->userId, (int)$res['conversation_id'], 'creer_tache', ['titre' => 'x'], ['action' => 'Créer une tâche', 'fichier' => 'Relancer la mairie']);
        $this->db()->prepare("UPDATE drive_pending_actions SET message_id = ?, status = 'cancelled' WHERE id = ?")->execute([$replyId, $cardId]);

        $run = WorkflowRun::find($res['run_id']);
        $gate = WorkflowRun::lastStep($run['id'], 'accord');
        $this->assertNotNull(DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']));
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'executed');
        WorkflowEngine::gateResolved($run, $gate, 'approved');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);

        $data = $this->appelle('journalData', [$run, $this->userId]);
        $head = $data['head'];
        $this->assertSame(1, $head['n']);
        $this->assertSame('ok', $head['tone']);
        $this->assertSame('Terminé', $head['label']);
        $this->assertSame('/chat/' . $res['conversation_id'], $head['conversation_url']);
        $this->assertSame('Ouvrir l’exécution', $head['open']['label']);
        $this->assertSame("Client : Ville de Lyon · Montant : 6\u{202F}200", $head['context'], 'De quoi il s’agissait.');

        // Le workflow, à modifier ; le relancer avec les mêmes données ; ce qui a été remis.
        $this->assertSame(['id' => $wf['id'], 'name' => 'Synthèse validée', 'color' => $wf['color'], 'edit' => '/workflows/' . $wf['id'] . '/edit'], $data['wfInfo']);
        $this->assertSame(['id' => $wf['id'], 'values' => ['client' => 'Ville de Lyon', 'montant' => '6200']], $data['relaunch']);
        $this->assertSame(['Synthèse'], array_column($data['outputs'], 'name'));

        $entries = $data['entries'];
        $this->assertSame(['start', 'step', 'condition', 'gate', 'end'], array_column($entries, 'type'));
        $this->assertSame([['label' => 'Client', 'value' => 'Ville de Lyon'], ['label' => 'Montant', 'value' => '6 200']], array_map(
            static fn(array $f): array => ['label' => $f['label'], 'value' => str_replace("\u{202F}", ' ', str_replace("\u{00A0}", ' ', $f['value']))],
            $entries[0]['fields']
        ));
        $step = $entries[1];
        $this->assertSame('Synthèse', $step['piece']['name']);
        $this->assertStringContainsString('trois écoles', $step['piece']['excerpt']);
        $this->assertSame(['Recherche dans Google Drive'], array_column($step['actions'], 'label'));
        $this->assertSame([['Créer une tâche', 'Relancer la mairie', 'Refusée ou remplacée']], array_map(
            static fn(array $c): array => [$c['label'], $c['object'], $c['state']], $step['cards']
        ), 'La carte de la validation n’apparaît pas parmi les actions de l’étape.');
        $this->assertSame('Règle vérifiée : oui', $entries[2]['status']);
        $this->assertNotSame([], $entries[2]['lines']);
        $this->assertSame('Approuvée', $entries[3]['status']);
        $this->assertSame('Livrable remis', $entries[4]['status']);

        // Le gabarit rend tout, sans avertissement.
        $html = $this->appelle('partial', ['history-journal', $data]);
        foreach (['Lancement n° 1', 'Synthétiser', 'Recherche dans Google Drive', 'Relancer la mairie', 'Règle vérifiée : oui', '/workflows/runs/' . $run['id'] . '/pieces/synthese/download',
            'Synthèse validée', 'class="wf-jr-outputs"', 'data-launch="' . $wf['id'] . '"', 'data-launch-values="{&quot;client&quot;:&quot;Ville de Lyon&quot;'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringNotContainsString('**', $html, 'L’aperçu de la pièce ne montre pas la syntaxe.');
    }

    public function testSansDroitDeLancerNiDeModifierLeJournalNeLeProposePas(): void
    {
        $wf = $this->publie();
        $run = WorkflowRun::find($this->lance($wf, '100')['run_id']);
        $lecteur = User::findById($this->userId);
        Role::overrideRows([['id' => 2, 'slug' => 'creator', 'name' => 'Créateur', 'description' => '', 'color' => '', 'permissions' => Permissions::encode(['workflows.view'])]]);
        try {
            $data = $this->appelle('journalData', [$run, $this->userId, $lecteur]);
            $this->assertNull($data['relaunch']);
            $this->assertNull($data['wfInfo']['edit']);
        } finally {
            Role::overrideRows(null);
            Role::forget();
        }
        // Un workflow en pause ne se relance pas.
        Workflow::setActive($wf['id'], $this->userId, false, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->assertNull($this->appelle('journalData', [$run, $this->userId])['relaunch']);
    }

    public function testUneErreurPuisUneRepriseSeLisent(): void
    {
        $wf = $this->publie();
        $res = $this->lance($wf, '100');
        // Une clé refusée : pas une panne passagère, aucun essai automatique.
        WorkflowEngine::onJobFinished($res['job_ids'][0], 'failed', null, ['kind' => 'auth', 'title' => 'Service indisponible', 'message' => 'Le fournisseur ne répond pas.']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);

        $data = $this->appelle('journalData', [$run, $this->userId]);
        $this->assertSame('err', $data['head']['tone']);
        $this->assertSame('Service indisponible', $data['head']['error_box']['title']);
        $failed = $data['entries'][1];
        $this->assertSame('err', $failed['tone']);
        $this->assertSame('Service indisponible', $failed['error']['title']);
        $this->assertSame('Arrêt sur une erreur', end($data['entries'])['title']);

        // « Réessayer » : le passage en échec devient « repris ensuite », la fin d'erreur disparaît.
        $jobs = WorkflowEngine::retry($run);
        $this->repond($jobs[0], 'Synthèse refaite.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'Montant sous le seuil : jusqu’au livrable.');
        $data = $this->appelle('journalData', [$run, $this->userId]);
        $types = array_column($data['entries'], 'type');
        $this->assertNotContains('closing', $types);
        $this->assertSame('warn', $data['entries'][1]['tone']);
        $this->assertStringContainsString('reprise ensuite', $data['entries'][1]['status']);
        $this->assertSame('Règle vérifiée : non', $data['entries'][3]['status']);
    }

    public function testLaListeNumeroteFiltreEtPagine(): void
    {
        $wf = $this->publie();
        $ids = [];
        foreach (['100', '200', '300'] as $m) {
            $ids[] = $this->lance($wf, $m)['run_id'];
        }
        WorkflowEngine::cancel(WorkflowRun::find($ids[1]));

        $data = $this->appelle('historyData', [$this->userId, $wf['id'], 'all', 0]);
        $this->assertSame([3, 2, 1], array_column($data['items'], 'n'), 'Du plus récent au plus ancien, numérotés depuis le premier.');
        $this->assertSame(['all' => 3, 'ok' => 0, 'err' => 0, 'active' => 2, 'off' => 1], $data['groups']);
        $this->assertNull($data['moreBefore']);
        $this->assertTrue($data['scoped']);
        $this->assertSame('off', $data['items'][1]['group']);
        $this->assertSame('Client : Ville de Lyon · Montant : 200', str_replace("\u{202F}", ' ', $data['items'][1]['context']), 'De quoi il s’agissait.');

        // Page suivante : ce qui précède un lancement donné, numéros conservés.
        $runs = WorkflowRun::history($this->userId, $wf['id'], 1, $ids[2]);
        $this->assertSame([$ids[1]], array_column($runs, 'id'));
        $this->assertSame(2, (int)$runs[0]['ordinal']);

        // Le filtre d'état se joue côté serveur : la page et la suite ne montrent que lui.
        $off = $this->appelle('historyData', [$this->userId, $wf['id'], 'off', 0]);
        $this->assertSame([$ids[1]], array_column($off['items'], 'id'));
        $this->assertSame(3, $off['groups']['all'], 'Les compteurs restent ceux de tout le périmètre.');

        $html = $this->appelle('partial', ['history-runs', $data + ['firstPage' => true, 'query' => ['workflow' => $wf['id'], 'group' => 'all']]]);
        $this->assertSame(3, substr_count($html, 'class="wf-hist-item"'));
        $this->assertStringContainsString('Lancement n° 3', $html, 'Filtrée sur un workflow, la ligne dit son numéro.');
        $this->assertStringContainsString('href="/workflows?vue=historique&amp;workflow=' . $wf['id'] . '&amp;run=' . $ids[2] . '"', $html, 'Sans script, une ligne rouvre l’onglet sur ce lancement.');
        $this->assertStringContainsString('Client : Ville de Lyon', $html);

        $vide = $this->appelle('historyData', [$this->userId, $wf['id'], 'err', 0]);
        $html = $this->appelle('partial', ['history-runs', $vide + ['firstPage' => true, 'query' => ['workflow' => $wf['id'], 'group' => 'err']]]);
        $this->assertStringContainsString('Aucun lancement ne correspond à ces filtres.', $html);
        $this->assertStringContainsString('data-hist-reset', $html);
    }

    public function testLHistoriqueMeleLesWorkflowsEtGardeUnLancementChoisi(): void
    {
        $a = $this->publie();
        $b = Workflow::findById(Workflow::create($this->userId, 'Relance fournisseur', self::definition(), null, '#be123c'));
        Workflow::publish($b['id'], $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $b = Workflow::findById($b['id']);
        $premier = $this->lance($a, '100')['run_id'];
        $ids = [$premier];
        foreach (range(1, WorkflowJournal::PAGE + 4) as $i) {
            $ids[] = $this->lance($i % 2 ? $b : $a, (string)$i)['run_id'];
        }

        $all = $this->appelle('historyData', [$this->userId, null, 'all', 0]);
        $this->assertFalse($all['scoped']);
        $this->assertCount(WorkflowJournal::PAGE, $all['items']);
        $this->assertNotNull($all['moreBefore']);
        $this->assertSame(WorkflowJournal::PAGE + 5, $all['groups']['all']);
        $this->assertEqualsCanonicalizing(['Synthèse validée', 'Relance fournisseur'], array_unique(array_column(array_column($all['items'], 'workflow'), 'name')), 'Tous les workflows, dans la même liste.');
        $this->assertSame('#be123c', $all['items'][1]['workflow']['color'], 'Chaque ligne porte la couleur de son workflow.');
        $this->assertSame('Relance fournisseur', $all['items'][1]['workflow']['name']);
        $html = $this->appelle('partial', ['history-runs', $all + ['firstPage' => true, 'query' => ['workflow' => null, 'group' => 'all']]]);
        $this->assertStringContainsString('Relance fournisseur', $html, 'Mêlés, une ligne dit d’abord son workflow…');
        $this->assertStringContainsString('n° ', $html, '… puis son numéro.');
        $this->assertStringContainsString('Afficher les lancements plus anciens', $html);

        // Un lancement ancien ouvert par un lien reste dans la liste : on charge jusqu'à lui.
        $upto = $this->appelle('historyData', [$this->userId, null, 'all', 0, $premier]);
        $this->assertSame($premier, end($upto['items'])['id']);
        $this->assertNull($upto['moreBefore']);

        // Un autre compte n'y voit rien, même en visant ce workflow.
        $this->insertUser(8);
        $this->assertSame([], WorkflowRun::history(8, null, 10));
        $this->assertSame([], WorkflowRun::history(8, $a['id'], 10));
        $this->assertSame(0, WorkflowRun::countHistoryFrom(8, null, [], 0));
    }

    public function testCeQuiALanceUnLancementSeLitSansTirerSonContenu(): void
    {
        $wf = $this->publie();
        $email = $this->lance($wf, '100')['run_id'];
        $fichier = $this->lance($wf, '200')['run_id'];
        WorkflowRun::saveArtifact($email, WorkflowSpec::EVENT_PIECE, WorkflowSpec::START_KEY, 'Email reçu', 'fiche',
            ['fields' => ['expediteur' => 'compta@exemple.fr', 'objet' => 'Facture « octobre »', 'contenu' => str_repeat('long ', 5000)]]);
        WorkflowRun::saveArtifact($fichier, WorkflowSpec::EVENT_PIECE, WorkflowSpec::START_KEY, 'Fichier reçu', 'file',
            ['name' => 'devis.pdf', 'text' => str_repeat('texte ', 20000), 'path' => '']);
        $h = WorkflowRun::eventHeadlines([$email, $fichier, 0, $email]);
        $this->assertSame(['objet' => 'Facture « octobre »', 'expediteur' => 'compta@exemple.fr'], $h[$email]['fields']);
        $this->assertSame('', $h[$email]['name'], 'Une valeur absente n’est pas « null ».');
        $this->assertSame('devis.pdf', $h[$fichier]['name']);
        $this->assertSame(240, mb_strlen($h[$fichier]['text']), 'Le début du texte seulement.');
        $this->assertSame([], WorkflowRun::eventHeadlines([]));
    }

    public function testLOngletSOuvreSurLeLancementDeLAdresse(): void
    {
        $wf = $this->publie();
        $vieux = $this->lance($wf, '100')['run_id'];
        $this->lance($wf, '200');
        $user = User::findById($this->userId);
        $workflows = Workflow::forUser($this->userId);
        $avant = $_GET;
        try {
            $_GET = ['vue' => 'historique', 'run' => (string)$vieux, 'workflow' => (string)$wf['id'], 'etat' => 'n’importe quoi'];
            $page = $this->appelle('historyPage', [$user, $workflows]);
            $this->assertSame(['workflow' => $wf['id'], 'group' => 'all'], $page['query'], 'Un état inconnu = Tous.');
            $this->assertSame($vieux, $page['selected']);
            $this->assertTrue($page['explicit']);
            $this->assertSame($vieux, $page['journal']['head']['id']);
            $this->assertSame(2, $page['total']);
            $this->assertSame([['id' => $wf['id'], 'name' => 'Synthèse validée', 'color' => $wf['color'], 'count' => 2]], $page['options']);

            // Sans lancement dans l'adresse : le plus récent ; un workflow d'autrui est ignoré.
            $this->insertUser(8);
            $autre = Workflow::create(8, 'Pas à moi', self::definition());
            $_GET = ['vue' => 'historique', 'workflow' => (string)$autre, 'run' => '999999'];
            $page = $this->appelle('historyPage', [$user, $workflows]);
            $this->assertNull($page['query']['workflow']);
            $this->assertFalse($page['explicit']);
            $this->assertNotSame($vieux, $page['selected']);

            // Le gabarit de l'onglet se rend en entier.
            $history = $page;
            $icons = WorkflowCatalog::icons();
            ob_start();
            require ROOT . '/templates/workflows/partials/history.php';
            $html = (string)ob_get_clean();
            foreach (['id="wfHistory"', 'Tous les workflows', 'name="etat" value="active"', 'class="wf-jr"', 'Lancement n° 2'] as $attendu) {
                $this->assertStringContainsString($attendu, $html);
            }
        } finally {
            $_GET = $avant;
        }
    }

    public function testLesLancementsDUnAutreCompteNeSAffichentPas(): void
    {
        $wf = $this->publie();
        $res = $this->lance($wf, '100');
        $this->insertUser(8);
        $this->assertSame([], WorkflowRun::history(8, $wf['id'], 10));
        $this->assertSame([], WorkflowRun::historyCounts(8, $wf['id']));
        $this->assertNull(WorkflowRun::findForUser($res['run_id'], 8), 'La route du journal répond « introuvable ».');
        $this->assertNull(Workflow::findForUser($wf['id'], 8), 'La route de l’historique aussi.');
        $this->assertSame([], DrivePendingAction::journalForConversation((int)$res['conversation_id'], 8));
    }

    public function testUneCarteSansDecisionPasseeSaDureeEstDiteExpiree(): void
    {
        $wf = $this->publie();
        $res = $this->lance($wf, '100');
        $conv = (int)$res['conversation_id'];
        $vieille = DrivePendingAction::create($this->userId, $conv, 'creer_tache', [], ['action' => 'Créer une tâche']);
        $this->db()->prepare('UPDATE drive_pending_actions SET created_at = NOW() - INTERVAL 2 DAY WHERE id = ?')->execute([$vieille]);
        $neuve = DrivePendingAction::create($this->userId, $conv, 'creer_tache', [], ['action' => 'Créer une tâche']);
        $cartes = DrivePendingAction::journalForConversation($conv, $this->userId);
        $this->assertTrue($cartes[$vieille]['expired']);
        $this->assertFalse($cartes[$neuve]['expired']);
        $this->assertSame('Expirée sans décision', WorkflowJournal::cardLine($cartes[$vieille])['state']);
        $this->assertSame('Attend votre accord', WorkflowJournal::cardLine($cartes[$neuve])['state']);
    }
}
