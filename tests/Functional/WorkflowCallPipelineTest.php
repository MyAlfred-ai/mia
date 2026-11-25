<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Brique « Appeler un workflow », sur la vraie base et le VRAI moteur : un
 * workflow en appelle un autre avec ses paramètres, attend qu'il se termine
 * et récupère sa pièce. Chaque cas de figure y passe : pièce typée ou livrable
 * en texte, appelé sans rien à attendre, paramètre refusé, appelé qui s'arrête
 * avant son livrable, en échec puis repris (d'un côté comme de l'autre),
 * brique ignorée, arrêts propagés, boucle, fichier transmis, garde-fous
 * d'exécution, pré-contrôle des appelés.
 *
 * Même méthode que les autres tests du moteur : la fin d'un job d'étape est
 * SIMULÉE (la réponse du modèle écrite dans la conversation, puis la clôture).
 */
final class WorkflowCallPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
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

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private function publie(string $nom, array $def): array
    {
        $id = Workflow::create($this->userId, $nom, WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function republie(int $id, array $def): array
    {
        $wf = Workflow::findById($id);
        Workflow::saveDraft($id, $this->userId, (string)$wf['name'], (string)$wf['color'], WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf, array $values): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), $values, [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    private function repond(int $jobId, string $reponse): int
    {
        $job = $this->ligne('SELECT conversation_id FROM async_jobs WHERE id = ?', [$jobId]);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    private function echoue(int $jobId, string $kind = 'auth'): void
    {
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'failed', null, ['kind' => $kind, 'title' => 'Clé refusée', 'message' => 'Panne simulée.']);
    }

    private function ligne(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function jobEnCours(int $runId): int
    {
        $step = $this->ligne("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND status = 'running' AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$runId]);
        $this->assertNotSame([], $step, 'Une étape tourne.');
        return (int)$step['job_id'];
    }

    /** L'exécution appelée par la brique `$key` de `$runId` (la plus récente). */
    private function appele(int $runId, string $key = 'appel'): ?array
    {
        $step = WorkflowRun::lastStep($runId, $key);
        return $step !== null ? WorkflowRun::calledBy($step['id']) : null;
    }

    private function messages(int $convId): string
    {
        $stmt = $this->db()->prepare('SELECT content FROM messages WHERE conversation_id = ? ORDER BY id');
        $stmt->execute([$convId]);
        return implode("\n---\n", $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** L'appelé : deux paramètres typés, une étape qui remet une Fiche. */
    private static function analyse(array $extra = []): array
    {
        return array_replace_recursive([
            'start' => ['fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true],
                ['key' => 'priorite', 'label' => 'Priorité', 'type' => 'choice', 'options' => ['Haute', 'Basse'], 'default' => 'Basse'],
            ]],
            'nodes' => [
                ['key' => 'analyser', 'type' => 'step', 'title' => 'Analyser', 'instruction' => 'Analyse @{depart}.', 'on_error' => ['retries' => 0],
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'fiche', 'fields' => [
                        ['key' => 'verdict', 'label' => 'Verdict', 'type' => 'text'], ['key' => 'score', 'label' => 'Score', 'type' => 'number'],
                    ]]],
            ],
            'end' => ['pieces' => ['synthese']],
        ], $extra);
    }

    /** L'appelant : appelle `$childId`, puis rédige avec la pièce récupérée. */
    private static function dossier(int $childId, array $call = [], array $extra = []): array
    {
        return array_replace_recursive([
            'start' => ['fields' => [
                ['key' => 'nom', 'label' => 'Nom du client', 'type' => 'text', 'required' => true],
                ['key' => 'budget', 'label' => 'Budget', 'type' => 'text', 'required' => true],
            ]],
            'nodes' => [
                array_replace_recursive([
                    'key' => 'appel', 'type' => 'call', 'title' => 'Analyser le client', 'workflow_id' => $childId,
                    'params' => ['client' => '@{depart.nom}', 'montant' => '@{depart.budget}'],
                    'output' => ['key' => 'analyse', 'name' => 'Analyse', 'from' => 'synthese', 'form' => 'fiche', 'fields' => [
                        ['key' => 'verdict', 'label' => 'Verdict', 'type' => 'text'], ['key' => 'score', 'label' => 'Score', 'type' => 'number'],
                    ]],
                ], $call),
                ['key' => 'rediger', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Rédige d’après @{analyse}.',
                    'output' => ['key' => 'note', 'name' => 'Note', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['note']],
        ], $extra);
    }

    private const FICHE = "```json\n{\"Verdict\": \"Bon client\", \"Score\": 8}\n```";

    // ------------------------------------------------------------------
    // Le cas nominal
    // ------------------------------------------------------------------

    public function testLAppelLanceLAppeleAttendEtRecupereSaPieceTypee(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1 200 €']);
        $run = WorkflowRun::find($res['run_id']);

        // L'appelant attend sur la brique, l'appelé est une exécution à part entière.
        $step = WorkflowRun::lastStep($run['id'], 'appel');
        $this->assertSame('running', $step['status']);
        $this->assertSame(['running', 'appel'], [$run['status'], $run['current_key']]);
        $child = $this->appele($run['id']);
        $this->assertNotNull($child);
        $this->assertSame([WorkflowCall::TRIGGER, $run['id'], $step['id'], $enfant['id']],
            [$child['trigger_kind'], $child['parent_run_id'], $child['caller_step_id'], $child['workflow_id']]);
        $this->assertNotSame($run['conversation_id'], $child['conversation_id'], 'L’appelé a sa propre conversation.');
        $this->assertSame($child['id'], $step['detail']['child_run']);

        // Les paramètres : lus dans le dossier, typés par le départ de l'appelé, défauts compris.
        $depart = WorkflowRun::artifact($child['id'], WorkflowSpec::START_PIECE)['content']['fields'];
        $this->assertSame(['client' => 'Durand SA', 'montant' => 1200, 'priorite' => 'Basse'], $depart);
        $this->assertStringContainsString('Appelé par « Dossier »', $this->messages((int)$child['conversation_id']));
        $appel = $this->messages((int)$run['conversation_id']);
        $this->assertStringContainsString('appel de [« Analyse client »](/workflows/runs/' . $child['id'] . ')', $appel);
        $this->assertStringContainsString('- Client : Durand SA', $appel);
        $this->assertSame($res['job_ids'], [$this->jobEnCours($child['id'])], 'Le premier job à déclencher est celui de l’appelé.');

        // L'appelé termine : sa Fiche entre au dossier de l'appelant, qui continue.
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $this->assertSame('done', WorkflowRun::find($child['id'])['status']);
        $step = WorkflowRun::lastStep($run['id'], 'appel');
        $this->assertSame(['done', 'done'], [$step['status'], $step['outcome']]);
        $piece = WorkflowRun::artifact($run['id'], 'analyse');
        $this->assertSame('fiche', $piece['form']);
        $this->assertSame(['verdict' => 'Bon client', 'score' => 8], $piece['content']['fields']);
        $this->assertSame('appel', $piece['node_key']);
        $run = WorkflowRun::find($run['id']);
        $this->assertSame(['running', 'rediger'], [$run['status'], $run['current_key']]);
        $this->assertStringContainsString('« Analyse client » a terminé — pièce « Analyse » remise.', $this->messages((int)$run['conversation_id']));

        // L'étape suivante lit la pièce récupérée comme n'importe quelle pièce.
        $job = $this->jobEnCours($run['id']);
        $payload = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$job])['payload_json'], true);
        $this->assertStringContainsString('Bon client', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->repond($job, 'Note rédigée.');
        $this->assertSame('done', WorkflowRun::find($run['id'])['status']);

        // La fin de l'appelé, rejouée (entretien, double évènement), ne fait rien de plus.
        $after = new \ReflectionMethod(WorkflowEngine::class, 'afterCall');
        $this->assertSame([], $after->invoke(null, $child['id'], $step['id']));
        $this->assertSame(1, (int)$this->ligne("SELECT COUNT(*) AS n FROM workflow_run_steps WHERE run_id = ? AND node_key = 'rediger'", [$run['id']])['n']);
    }

    public function testToutLeLivrableRevientEnTexte(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id'], ['output' => ['key' => 'analyse', 'name' => 'Analyse', 'from' => '', 'form' => 'text']]));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '300']);
        $child = $this->appele($res['run_id']);
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $piece = WorkflowRun::artifact($res['run_id'], 'analyse');
        $this->assertSame('text', $piece['form']);
        $this->assertStringContainsString('## Synthèse', $piece['content']['text']);
        $this->assertStringContainsString('Bon client', $piece['content']['text']);
    }

    public function testUnAppeleSansRienAAttendreRendLaMainToutDeSuite(): void
    {
        // L'appelé n'a qu'une brique « Me prévenir » : il finit pendant son lancement.
        $enfant = $this->publie('Prévenir', [
            'start' => ['fields' => [['key' => 'qui', 'label' => 'Qui', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 'dire', 'type' => 'notify', 'title' => 'Dire', 'message' => 'Dossier de @{depart.qui} ouvert.']],
            'end' => ['pieces' => ['depart']],
        ]);
        $parent = $this->publie('Dossier', self::dossier($enfant['id'], [
            'params' => ['qui' => 'Client @{depart.nom}'], 'output' => ['key' => 'analyse', 'name' => 'Analyse', 'from' => '', 'form' => 'text'],
        ]));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '300']);
        $child = $this->appele($res['run_id']);
        $this->assertSame('done', $child['status']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['running', 'rediger'], [$run['status'], $run['current_key']], 'L’appelant a déjà repris.');
        $this->assertSame([$this->jobEnCours($run['id'])], $res['job_ids'], 'Le job à déclencher est celui de la brique suivante de l’appelant.');
        $step = WorkflowRun::lastStep($run['id'], 'appel');
        $this->assertSame($child['id'], $step['detail']['child_run'], 'Le passage garde l’exécution appelée.');
        $fil = $this->messages((int)$run['conversation_id']);
        $this->assertLessThan(strpos($fil, 'a terminé'), strpos($fil, 'appel de [«'), 'L’appel est annoncé avant son retour.');
        $this->assertStringContainsString('Client Durand SA', WorkflowRun::artifact($run['id'], 'analyse')['content']['text']);
    }

    // ------------------------------------------------------------------
    // Paramètres
    // ------------------------------------------------------------------

    public function testUnParametreDUneAutreNatureArreteLAppelAvantToutLancement(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => 'à définir']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['failed', 'appel'], [$run['status'], $run['current_key']]);
        $this->assertSame('call', $run['error']['kind']);
        $this->assertStringContainsString('« Analyse client » n’a pas pu être appelé — « Montant » attend un nombre.', $run['error']['message']);
        $this->assertNull($this->appele($run['id']), 'Aucune exécution de l’appelé n’a été créée.');
        $this->assertSame(0, (int)$this->ligne('SELECT COUNT(*) AS n FROM workflow_runs WHERE workflow_id = ?', [$enfant['id']])['n']);
        $this->assertStringContainsString('attend un nombre', $this->messages((int)$run['conversation_id']));

        // Un paramètre obligatoire sans valeur : même arrêt, cause nommée.
        $parent2 = $this->publie('Dossier 2', self::dossier($enfant['id'], ['params' => ['client' => '', 'montant' => '12']]));
        $r2 = WorkflowRun::find($this->lance($parent2, ['nom' => 'X', 'budget' => '1'])['run_id']);
        $this->assertSame('failed', $r2['status']);
        $this->assertStringContainsString('Renseignez « Client ».', $r2['error']['message']);

        // Un choix hors de ses options.
        $parent3 = $this->publie('Dossier 3', self::dossier($enfant['id'], ['params' => ['priorite' => 'Urgente']]));
        $r3 = WorkflowRun::find($this->lance($parent3, ['nom' => 'X', 'budget' => '1'])['run_id']);
        $this->assertStringContainsString('Choisissez une des options de « Priorité ».', $r3['error']['message']);
    }

    public function testUnePieceEstTransmiseEnFichierEtUnTexteEntierEnParametre(): void
    {
        $enfant = $this->publie('Relire', [
            'start' => ['fields' => [
                ['key' => 'doc', 'label' => 'Document', 'type' => 'file', 'required' => true],
                ['key' => 'contexte', 'label' => 'Contexte', 'type' => 'longtext'],
            ]],
            'nodes' => [['key' => 'relire', 'type' => 'step', 'title' => 'Relire', 'instruction' => 'Relis @{doc}.',
                'output' => ['key' => 'avis', 'name' => 'Avis', 'form' => 'text']]],
            'end' => ['pieces' => ['avis']],
        ]);
        $parent = $this->publie('Mémoire', [
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'ecrire', 'type' => 'step', 'title' => 'Écrire', 'instruction' => 'Écris sur @{depart.sujet}.',
                    'output' => ['key' => 'memoire', 'name' => 'Mémoire', 'form' => 'document', 'format' => 'md']],
                ['key' => 'resumer', 'type' => 'step', 'title' => 'Résumer', 'instruction' => 'Résume @{memoire}.',
                    'output' => ['key' => 'resume', 'name' => 'Résumé', 'form' => 'text']],
                ['key' => 'appel', 'type' => 'call', 'title' => 'Faire relire', 'workflow_id' => $enfant['id'],
                    'params' => ['doc' => '@{memoire}', 'contexte' => "Résumé :\n@{resume}"],
                    'output' => ['key' => 'avis_recu', 'name' => 'Avis reçu', 'from' => 'avis', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['avis_recu']],
        ]);
        $res = $this->lance($parent, ['sujet' => 'les marchés publics']);
        $this->repond($res['job_ids'][0], "````markdown fichier=\"Mémoire.md\"\n# Mémoire\n\nLe texte du mémoire.\n````");
        $this->repond($this->jobEnCours($res['run_id']), 'Un résumé en deux lignes.');
        $child = $this->appele($res['run_id']);
        $this->assertNotNull($child);
        $doc = WorkflowRun::artifact($child['id'], 'doc');
        $this->assertSame('file', $doc['form']);
        $this->assertSame('Mémoire.md', $doc['content']['name']);
        $this->assertFileExists($doc['content']['path']);
        $this->assertStringContainsString('Le texte du mémoire.', (string)file_get_contents($doc['content']['path']));
        @unlink($doc['content']['path']);
        $this->assertSame("Résumé :\nUn résumé en deux lignes.", WorkflowRun::artifact($child['id'], WorkflowSpec::START_PIECE)['content']['fields']['contexte']);

        $this->repond($this->jobEnCours($child['id']), 'Avis : très bien.');
        $this->assertSame('Avis : très bien.', WorkflowRun::artifact($res['run_id'], 'avis_recu')['content']['text']);
        $this->assertSame('done', WorkflowRun::find($res['run_id'])['status']);
    }

    // ------------------------------------------------------------------
    // L'appelé s'arrête, échoue, est arrêté
    // ------------------------------------------------------------------

    /** Un appelé qui s'arrête avant son livrable quand le montant est trop faible. */
    private static function analyseQuiTrie(): array
    {
        $def = self::analyse();
        array_unshift($def['nodes'], [
            'key' => 'seuil', 'type' => 'condition', 'title' => 'Assez gros ?', 'mode' => 'rule',
            'rule' => ['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'montant', 'op' => 'gte', 'value' => '1000']]],
            'yes' => ['nodes' => [], 'then' => 'continue'], 'no' => ['nodes' => [], 'then' => 'stop'],
        ]);
        return $def;
    }

    public function testLAppeleQuiSArreteAvantSonLivrableArreteLAppelantOuLeLaisseContinuer(): void
    {
        $enfant = $this->publie('Analyse client', self::analyseQuiTrie());

        // Réglage par défaut : l'appelant s'arrête aussi, proprement.
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $run = WorkflowRun::find($this->lance($parent, ['nom' => 'Petit', 'budget' => '50'])['run_id']);
        $child = $this->appele($run['id']);
        $this->assertSame('stopped', $child['status']);
        $this->assertSame(['stopped', 'appel'], [$run['status'], $run['current_key']]);
        $this->assertSame(['done', 'stopped'], [WorkflowRun::lastStep($run['id'], 'appel')['status'], WorkflowRun::lastStep($run['id'], 'appel')['outcome']]);
        $this->assertStringContainsString('« Analyse client », appelé par la brique « Analyser le client », s’est arrêté avant son livrable', $this->messages((int)$run['conversation_id']));
        $this->assertNull(WorkflowRun::lastStep($run['id'], 'rediger'), 'La suite n’a pas démarré.');

        // Réglé pour continuer : la suite se passe de la pièce.
        $parent2 = $this->publie('Dossier 2', self::dossier($enfant['id'], ['on_stop' => 'continue']));
        $run2 = WorkflowRun::find($this->lance($parent2, ['nom' => 'Petit', 'budget' => '50'])['run_id']);
        $this->assertSame(['running', 'rediger'], [$run2['status'], $run2['current_key']]);
        $this->assertNull(WorkflowRun::artifact($run2['id'], 'analyse'));
        $this->assertStringContainsString('le workflow continue sans sa pièce', $this->messages((int)$run2['conversation_id']));

        // Au-dessus du seuil : l'appelé va au bout, comme d'habitude.
        $run3 = WorkflowRun::find($this->lance($parent, ['nom' => 'Gros', 'budget' => '5000'])['run_id']);
        $this->assertSame('running', $this->appele($run3['id'])['status']);
    }

    public function testLAppeleEnEchecArreteLAppelantSurLaBriqueEtLaRepriseReprendLAppele(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        $this->echoue($this->jobEnCours($child['id']));

        $child = WorkflowRun::find($child['id']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame('failed', $child['status']);
        $this->assertSame(['failed', 'appel'], [$run['status'], $run['current_key']]);
        $this->assertSame(['call', $child['id']], [$run['error']['kind'], $run['error']['child_run']]);
        $this->assertStringContainsString('« Analyse client », appelé par la brique « Analyser le client », s’est arrêté sur une erreur (Clé refusée)', $run['error']['message']);
        $this->assertSame('running', WorkflowRun::lastStep($run['id'], 'appel')['status'], 'La brique reste ouverte : elle attend la reprise.');

        // Reprendre l'APPELANT reprend l'appelé, là où il s'est arrêté — pas de second appel.
        WorkflowEngine::retry($run);
        $this->assertSame('running', WorkflowRun::find($run['id'])['status']);
        $this->assertSame('running', WorkflowRun::find($child['id'])['status']);
        $this->assertSame(1, (int)$this->ligne('SELECT COUNT(*) AS n FROM workflow_runs WHERE workflow_id = ?', [$enfant['id']])['n']);
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $run = WorkflowRun::find($run['id']);
        $this->assertSame(['running', 'rediger'], [$run['status'], $run['current_key']]);
        $this->assertSame(8, WorkflowRun::artifact($run['id'], 'analyse')['content']['fields']['score']);
    }

    public function testReprendreLAppeleRelanceAussiLAppelant(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        $this->echoue($this->jobEnCours($child['id']));
        $this->assertSame('failed', WorkflowRun::find($res['run_id'])['status']);

        // La personne ouvre l'exécution de l'appelé et la reprend là-bas.
        WorkflowEngine::retry(WorkflowRun::find($child['id']));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['running', 'appel'], [$run['status'], $run['current_key']]);
        $this->assertNull($run['error']);
        $this->assertStringContainsString('« Analyse client » a repris', $this->messages((int)$run['conversation_id']));
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $this->assertSame('rediger', WorkflowRun::find($run['id'])['current_key']);
    }

    public function testIgnorerLaBriqueClotLAppeleEtContinue(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        $this->echoue($this->jobEnCours($child['id']));

        WorkflowEngine::skip(WorkflowRun::find($res['run_id']));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['running', 'rediger'], [$run['status'], $run['current_key']]);
        $this->assertSame('cancelled', WorkflowRun::find($child['id'])['status'], 'L’appelé, que plus personne n’attend, est clos.');
        $this->assertNull(WorkflowRun::artifact($run['id'], 'analyse'));

        // Réglée d'avance pour être ignorée : même suite, sans geste.
        $parent2 = $this->publie('Dossier 2', self::dossier($enfant['id'], ['on_error' => ['then' => 'skip']]));
        $res2 = $this->lance($parent2, ['nom' => 'Durand SA', 'budget' => '1200']);
        $this->echoue($this->jobEnCours($this->appele($res2['run_id'])['id']));
        $run2 = WorkflowRun::find($res2['run_id']);
        $this->assertSame(['running', 'rediger'], [$run2['status'], $run2['current_key']]);
        $this->assertSame(['done', 'skipped'], [WorkflowRun::lastStep($run2['id'], 'appel')['status'], WorkflowRun::lastStep($run2['id'], 'appel')['outcome']]);
    }

    public function testArreterLAppelantArreteLAppeleEtInversement(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));

        // Arrêter l'appelant : l'appelé s'arrête avec lui.
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        WorkflowEngine::cancel(WorkflowRun::find($res['run_id']));
        $this->assertSame('cancelled', WorkflowRun::find($res['run_id'])['status']);
        $this->assertSame('cancelled', WorkflowRun::find($child['id'])['status']);
        $this->assertStringContainsString('l’exécution qui l’appelait ne l’attend plus', $this->messages((int)$child['conversation_id']));

        // Arrêter l'appelé : l'appelant s'arrête sur la brique, reprenable — la reprise relance un appel.
        $res = $this->lance($parent, ['nom' => 'Martin', 'budget' => '900']);
        $child = $this->appele($res['run_id']);
        WorkflowEngine::cancel(WorkflowRun::find($child['id']));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['failed', 'appel'], [$run['status'], $run['current_key']]);
        $this->assertStringContainsString('a été arrêté avant la fin', $run['error']['message']);
        $this->assertSame('failed', WorkflowRun::lastStep($run['id'], 'appel')['status']);
        WorkflowEngine::retry($run);
        $second = $this->appele($run['id']);
        $this->assertNotSame($child['id'], $second['id']);
        $this->assertSame('running', $second['status']);
        $this->assertSame('running', WorkflowRun::find($run['id'])['status']);
    }

    public function testRefaireDepuisUneBriqueFermeLAppelRestéOuvert(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        $this->echoue($this->jobEnCours($child['id']));

        WorkflowEngine::restartFrom(WorkflowRun::find($res['run_id']), 'appel');
        $this->assertSame('cancelled', WorkflowRun::find($child['id'])['status']);
        $second = $this->appele($res['run_id']);
        $this->assertNotSame($child['id'], $second['id']);
        $this->assertSame(['running', 'running'], [$second['status'], WorkflowRun::find($res['run_id'])['status']]);
        // La fin tardive de l'ancien appelé (déjà clos) ne touche pas le nouveau passage.
        $this->assertSame('running', WorkflowRun::lastStep($res['run_id'], 'appel')['status']);
    }

    // ------------------------------------------------------------------
    // Chaînes
    // ------------------------------------------------------------------

    public function testUnAppelDansUneBoucleLanceUnAppeleParElementEtRassembleLesFiches(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Tournée', [
            'start' => ['fields' => [['key' => 'clients', 'label' => 'Clients', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste @{depart.clients}.',
                    'output' => ['key' => 'liste', 'name' => 'Liste', 'form' => 'list']],
                ['key' => 'boucle', 'type' => 'loop', 'title' => 'Pour chaque client', 'mode' => 'each', 'over' => 'liste', 'max' => 10,
                    'item' => ['key' => 'client', 'name' => 'Client en cours'],
                    'body' => [[
                        'key' => 'appel', 'type' => 'call', 'title' => 'Analyser', 'workflow_id' => $enfant['id'],
                        'params' => ['client' => '@{client}', 'montant' => '1500'],
                        'output' => ['key' => 'analyse', 'name' => 'Analyse', 'from' => 'synthese', 'form' => 'fiche', 'fields' => [
                            ['key' => 'verdict', 'label' => 'Verdict', 'type' => 'text'], ['key' => 'score', 'label' => 'Score', 'type' => 'number'],
                        ]],
                    ]],
                    'output' => ['key' => 'analyses', 'name' => 'Analyses']],
            ],
            'end' => ['pieces' => ['analyses']],
        ]);
        $res = $this->lance($parent, ['clients' => 'Alpha, Beta']);
        $this->repond($res['job_ids'][0], "- Alpha\n- Beta");

        $premier = $this->appele($res['run_id'], 'appel#1');
        $this->assertNotNull($premier, 'Premier tour : un appel.');
        $this->assertSame('Alpha', WorkflowRun::artifact($premier['id'], WorkflowSpec::START_PIECE)['content']['fields']['client']);
        $this->assertNull($this->appele($res['run_id'], 'appel#2'), 'Le second tour attend la fin du premier.');
        $this->repond($this->jobEnCours($premier['id']), "```json\n{\"Verdict\": \"Solide\", \"Score\": 7}\n```");

        $second = $this->appele($res['run_id'], 'appel#2');
        $this->assertNotNull($second);
        $this->assertSame('Beta', WorkflowRun::artifact($second['id'], WorkflowSpec::START_PIECE)['content']['fields']['client']);
        $this->repond($this->jobEnCours($second['id']), "```json\n{\"Verdict\": \"Fragile\", \"Score\": 3}\n```");

        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame('done', $run['status']);
        $table = WorkflowRun::artifact($run['id'], 'analyses');
        $this->assertSame('table', $table['form']);
        $plat = json_encode($table['content'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Solide', $plat);
        $this->assertStringContainsString('Fragile', $plat);
    }

    public function testTroisNiveauxSEnchainentEtLaFinRemonteJusquEnHaut(): void
    {
        $c = $this->publie('C', self::analyse());
        $b = $this->publie('B', self::dossier($c['id']));
        $a = $this->publie('A', [
            'start' => ['fields' => [['key' => 'qui', 'label' => 'Qui', 'type' => 'text', 'required' => true]]],
            'nodes' => [[
                'key' => 'appel', 'type' => 'call', 'title' => 'Monter le dossier', 'workflow_id' => $b['id'],
                'params' => ['nom' => '@{depart.qui}', 'budget' => '2000'],
                'output' => ['key' => 'dossier', 'name' => 'Dossier', 'from' => 'note', 'form' => 'text'],
            ]],
            'end' => ['pieces' => ['dossier']],
        ]);
        $res = $this->lance($a, ['qui' => 'Durand']);
        $runB = $this->appele($res['run_id']);
        $runC = $this->appele($runB['id']);
        $this->assertSame(2, WorkflowRun::chainDepth($runC['parent_run_id']));

        // Une panne tout en bas remonte : chaque appelant s'arrête sur sa brique.
        $this->echoue($this->jobEnCours($runC['id']));
        $this->assertSame(['failed', 'failed', 'failed'], [WorkflowRun::find($runC['id'])['status'], WorkflowRun::find($runB['id'])['status'], WorkflowRun::find($res['run_id'])['status']]);

        // Reprendre tout en haut reprend tout en bas.
        WorkflowEngine::retry(WorkflowRun::find($res['run_id']));
        $this->assertSame(['running', 'running', 'running'], [WorkflowRun::find($runC['id'])['status'], WorkflowRun::find($runB['id'])['status'], WorkflowRun::find($res['run_id'])['status']]);
        $this->repond($this->jobEnCours($runC['id']), self::FICHE);
        $this->repond($this->jobEnCours($runB['id']), 'La note du dossier.');
        $top = WorkflowRun::find($res['run_id']);
        $this->assertSame('done', $top['status']);
        $this->assertSame('La note du dossier.', WorkflowRun::artifact($top['id'], 'dossier')['content']['text']);
    }

    public function testLesGardeFousDExecutionRefusentUneBoucleDAppelsEtTropDeNiveaux(): void
    {
        // A appelle B, puis B est republié pour appeler A : la publication le
        // refuse dans l'éditeur ; ici le modèle publie sans contrôle — le
        // moteur doit refuser quand même.
        $a = $this->publie('A', self::analyse());
        $b = $this->publie('B', self::dossier($a['id']));
        $a = $this->republie($a['id'], [
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true]]],
            'nodes' => [['key' => 'retour', 'type' => 'call', 'title' => 'Rappeler B', 'workflow_id' => $b['id'],
                'params' => ['nom' => '@{depart.client}', 'budget' => '1'],
                'output' => ['key' => 'r', 'name' => 'R', 'from' => '', 'form' => 'text']]],
            'end' => ['pieces' => ['r']],
        ]);
        $res = $this->lance($b, ['nom' => 'Durand', 'budget' => '10']);
        $runB = WorkflowRun::find($res['run_id']);
        $this->assertSame(['failed', 'appel'], [$runB['status'], $runB['current_key']], 'L’appelant s’arrête, sans tourner en rond.');
        $this->assertStringContainsString('« A » n’a pas pu être appelé', $runB['error']['message']);
        $this->assertStringContainsString('Appels en boucle : « B » appelle déjà ce workflow', $runB['error']['message']);
        $this->assertSame(0, (int)$this->ligne('SELECT COUNT(*) AS n FROM workflow_runs WHERE workflow_id = ?', [$a['id']])['n'], 'A n’est même pas lancé.');
        $this->assertSame(1, (int)$this->ligne('SELECT COUNT(*) AS n FROM workflow_runs WHERE workflow_id = ?', [$b['id']])['n']);

        // Second filet, si le pré-contrôle de l'appelé ne voyait rien : la chaîne des exécutions.
        $garde = new \ReflectionMethod(WorkflowEngine::class, 'startCall');
        $def = WorkflowRun::find($res['run_id'])['definition'];
        $fauxAppelant = WorkflowRun::find($res['run_id']);
        $fauxAppelant['workflow_id'] = $a['id']; // comme si A, déjà en cours, rappelait A
        $fauxAppelant['status'] = 'running';
        $garde->invoke(null, $fauxAppelant, $def['nodes'][0]);
        $this->assertStringContainsString('est déjà en cours plus haut dans cette chaîne d’appels', WorkflowRun::find($res['run_id'])['error']['message']);

        // Quatre niveaux d'appels : le quatrième est refusé.
        $w4 = $this->publie('N4', self::analyse());
        $prev = $w4;
        foreach (['N3', 'N2', 'N1', 'N0'] as $nom) {
            $prev = $this->publie($nom, self::dossier($prev['id'], $nom === 'N3' ? [] : [
                'params' => ['nom' => '@{depart.nom}', 'budget' => '@{depart.budget}', 'client' => '', 'montant' => ''],
                'output' => ['key' => 'analyse', 'name' => 'Analyse', 'from' => '', 'form' => 'text'],
            ]));
        }
        $res = $this->lance($prev, ['nom' => 'Durand', 'budget' => '10']);
        $r1 = $this->appele($res['run_id']);
        $r2 = $this->appele($r1['id']);
        $r3 = $this->appele($r2['id']);
        $this->assertSame('failed', $r3['status']);
        $this->assertStringContainsString('Trop de workflows imbriqués : ' . WorkflowCall::DEPTH_MAX . ' niveaux d’appels au plus.', $r3['error']['message']);
        $this->assertNull($this->appele($r3['id']), 'Le quatrième niveau n’est pas lancé.');
    }

    public function testUnAppeleDisparuEnPauseOuNonPublieArreteLAppelAvecSaCause(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));

        Workflow::setActive($enfant['id'], $this->userId, false, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $run = WorkflowRun::find($this->lance($parent, ['nom' => 'D', 'budget' => '1'])['run_id']);
        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('« Analyse client » est en pause : réactivez-le, puis reprenez.', $run['error']['message']);

        // Réactivé : la reprise relance l'appel.
        Workflow::setActive($enfant['id'], $this->userId, true, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        WorkflowEngine::retry($run);
        $this->assertSame('running', $this->appele($run['id'])['status']);

        Workflow::delete($enfant['id'], $this->userId);
        $run2 = WorkflowRun::find($this->lance($parent, ['nom' => 'D', 'budget' => '1'])['run_id']);
        $this->assertStringContainsString('Le workflow appelé n’existe plus', $run2['error']['message']);

        // Le workflow d'une AUTRE personne ne s'appelle pas.
        $autre = $this->insertUser(9, 'creator');
        $id = Workflow::create($autre, 'Pas à moi', WorkflowSpec::normalize(self::analyse()));
        Workflow::publish($id, $autre, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $parent3 = $this->publie('Dossier 3', self::dossier($id));
        $run3 = WorkflowRun::find($this->lance($parent3, ['nom' => 'D', 'budget' => '1'])['run_id']);
        $this->assertStringContainsString('Le workflow appelé n’existe plus', $run3['error']['message']);
        $this->assertSame(0, (int)$this->ligne('SELECT COUNT(*) AS n FROM workflow_runs WHERE workflow_id = ?', [$id])['n']);
    }

    public function testLaPieceQuiAChangeDeFormeArreteLaBriqueAuRetour(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        // L'appelé remet finalement un texte là où l'appelant attend une Fiche
        // (republié entre-temps) : simulé en réécrivant sa pièce.
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $this->assertSame('rediger', WorkflowRun::find($res['run_id'])['current_key']);

        $res2 = $this->lance($parent, ['nom' => 'Martin', 'budget' => '900']);
        $child2 = $this->appele($res2['run_id']);
        WorkflowRun::saveArtifact($child2['id'], 'synthese', 'analyser', 'Synthèse', 'text', ['text' => 'un texte']);
        $fin = new \ReflectionMethod(WorkflowEngine::class, 'finishRun');
        $this->db()->prepare("UPDATE workflow_run_steps SET status = 'done' WHERE run_id = ?")->execute([$child2['id']]);
        $fin->invoke(null, WorkflowRun::find($child2['id']));
        $run2 = WorkflowRun::find($res2['run_id']);
        $this->assertSame('failed', $run2['status']);
        $this->assertStringContainsString('n’a plus la forme attendue', $run2['error']['message']);
    }

    // ------------------------------------------------------------------
    // Pré-contrôle, enchaînement, entretien
    // ------------------------------------------------------------------

    public function testLePreControleDeLAppelantVoitCeQuiManqueALAppele(): void
    {
        $user = User::findById($this->userId);
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $this->assertSame([], WorkflowEngine::preflight($parent, $user), 'Tout est prêt : rien à régler.');

        // L'appelé est mis en pause après la publication de l'appelant.
        Workflow::setActive($enfant['id'], $this->userId, false, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $items = WorkflowEngine::preflight(Workflow::findById($parent['id']), $user);
        $this->assertCount(1, $items);
        $this->assertSame(['appel', 'Analyser le client'], [$items[0]['node'], $items[0]['brick']]);
        $this->assertStringContainsString('« Analyse client » est en pause', $items[0]['text']);
        try {
            WorkflowEngine::assertReady(Workflow::findById($parent['id']), $user);
            $this->fail('Le lancement aurait dû être refusé.');
        } catch (WorkflowPreflightException $e) {
            $this->assertStringContainsString('« Dossier » ne peut pas démarrer', $e->getMessage());
        }
        Workflow::setActive($enfant['id'], $this->userId, true, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        // L'appelé a lui-même un point bloquant (son assistant n'existe plus) : dit dès le lancement de l'appelant.
        $def = self::analyse();
        $def['nodes'][0]['profile_id'] = 99999;
        $this->republie($enfant['id'], $def);
        $items = WorkflowEngine::preflight(Workflow::findById($parent['id']), $user);
        $this->assertNotSame([], $items);
        $this->assertSame('appel', $items[0]['node']);
        $this->assertStringContainsString('« Analyse client » n’est pas prêt — « Analyser » : Cet assistant n’est plus accessible', $items[0]['text']);

        // …et l'appel lui-même le refuse, si l'appelant était déjà parti.
        $run = WorkflowRun::find($this->lance(Workflow::findById($parent['id']), ['nom' => 'D', 'budget' => '1'])['run_id']);
        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('« Analyse client » ne peut pas démarrer', $run['error']['message']);
    }

    public function testLEntretienRejoueUneFinDAppelPerdueEtFermeUnAppelSansExecution(): void
    {
        $enfant = $this->publie('Analyse client', self::analyse());
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);

        // L'appelé a fini, mais l'appelant n'a pas repris (process abattu entre les deux).
        WorkflowRun::saveArtifact($child['id'], 'synthese', 'analyser', 'Synthèse', 'fiche', ['fields' => ['verdict' => 'Rattrapé', 'score' => 5]]);
        $this->db()->prepare("UPDATE workflow_run_steps SET status = 'done' WHERE run_id = ?")->execute([$child['id']]);
        $this->db()->prepare("UPDATE workflow_runs SET status = 'done', current_key = 'end', updated_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?")->execute([$child['id']]);
        $this->assertSame('running', WorkflowRun::lastStep($res['run_id'], 'appel')['status']);
        WorkflowEngine::maintain();
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame('rediger', $run['current_key']);
        $this->assertSame('Rattrapé', WorkflowRun::artifact($run['id'], 'analyse')['content']['fields']['verdict']);

        // Un appel dont l'exécution a disparu (workflow appelé supprimé) : l'appelant s'arrête, reprenable.
        $res2 = $this->lance($parent, ['nom' => 'Martin', 'budget' => '900']);
        $this->db()->prepare('DELETE FROM workflow_runs WHERE id = ?')->execute([$this->appele($res2['run_id'])['id']]);
        $this->db()->prepare("UPDATE workflow_run_steps SET started_at = NOW() - INTERVAL 1 HOUR WHERE run_id = ? AND node_key = 'appel'")->execute([$res2['run_id']]);
        WorkflowEngine::maintain();
        $run2 = WorkflowRun::find($res2['run_id']);
        $this->assertSame(['failed', 'appel'], [$run2['status'], $run2['current_key']]);
        $this->assertStringContainsString('n’existe plus', $run2['error']['message']);
    }

    public function testUneValidationDansLAppeleFaitAttendreLAppelantSansLeBloquer(): void
    {
        $def = self::analyse();
        $def['nodes'][0]['review'] = true;
        $enfant = $this->publie('Analyse client', $def);
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $child = $this->appele($res['run_id']);
        $this->repond($this->jobEnCours($child['id']), self::FICHE);

        // L'appelé attend la personne ; l'appelant, lui, reste « en cours » sur sa brique.
        $child = WorkflowRun::find($child['id']);
        $this->assertSame('waiting', $child['status']);
        $this->assertSame(['running', 'appel'], [WorkflowRun::find($res['run_id'])['status'], WorkflowRun::find($res['run_id'])['current_key']]);

        // La personne approuve dans l'appelé : il termine, l'appelant reprend.
        $gate = WorkflowRun::lastStep($child['id'], 'analyser' . WorkflowEngine::REVIEW_SUFFIX);
        WorkflowEngine::gateResolved($child, $gate, 'approved');
        $this->assertSame('done', WorkflowRun::find($child['id'])['status']);
        $this->assertSame('rediger', WorkflowRun::find($res['run_id'])['current_key']);
    }

    // ------------------------------------------------------------------
    // Ce que montrent les écrans : état, journal, crédits, estimation
    // ------------------------------------------------------------------

    private function ecran(string $methode, array $args): mixed
    {
        $m = new \ReflectionMethod(WorkflowController::class, $methode);
        return $m->invokeArgs(new WorkflowController(), $args);
    }

    private function debite(int $convId, int $credits): void
    {
        UsageLog::insertLlm([
            'user_id' => $this->userId, 'conversation_id' => $convId, 'llm_provider' => 'openai::gpt-5.6', 'model' => 'gpt-5.6',
            'tokens_input' => 3000, 'tokens_output' => 300, 'credits_used' => $credits, 'cost_eur' => 0.001, 'source' => 'chat', 'quota_exempt' => false,
        ]);
    }

    public function testLesEcransDisentLAppelSonEtatEtCeQuIlCoute(): void
    {
        require_once ROOT . '/src/Controllers/WorkflowController.php';
        $def = self::analyse();
        $def['nodes'][0]['review'] = true;
        $enfant = $this->publie('Analyse client', $def);
        $parent = $this->publie('Dossier', self::dossier($enfant['id']));
        $res = $this->lance($parent, ['nom' => 'Durand SA', 'budget' => '1200']);
        $run = WorkflowRun::find($res['run_id']);
        $child = $this->appele($run['id']);

        // L'appelé travaille : la page de l'appelant le dit, avec le lien et ce qui lui a été donné.
        $state = $this->ecran('runState', [$run]);
        $called = $state['nodes']['appel']['child'];
        $this->assertSame(['Analyse client', '/workflows/runs/' . $child['id'], 'running', false, false],
            [$called['name'], $called['url'], $called['status'], $called['waiting'], $called['blocked']]);
        $this->assertSame(['Client', 'Montant'], array_slice(array_column($called['params'], 'label'), 0, 2));
        $this->assertSame('call', WorkflowProgress::attention($state)['kind']);
        $this->assertNull($state['caller']);

        // Il attend la personne : c'est dit chez l'appelant (page, volet du chat, journal).
        $this->repond($this->jobEnCours($child['id']), self::FICHE);
        $state = $this->ecran('runState', [WorkflowRun::find($run['id'])]);
        $this->assertTrue($state['nodes']['appel']['child']['waiting']);
        $this->assertSame(['kind' => 'call_wait', 'name' => 'Analyse client', 'url' => '/workflows/runs/' . $child['id']], WorkflowProgress::attention($state));
        $rows = WorkflowProgress::rows(WorkflowRun::find($run['id'])['definition'], $state);
        $row = array_values(array_filter($rows, static fn(array $r): bool => ($r['key'] ?? '') === 'appel'))[0] ?? null;
        $this->assertSame(['wait', '« Analyse client » vous attend'], [$row['state'] ?? null, $row['note'] ?? null]);
        $journal = $this->ecran('journalData', [WorkflowRun::find($run['id']), $this->userId]);
        $entry = array_values(array_filter($journal['entries'], static fn(array $e): bool => $e['key'] === 'appel'))[0];
        $this->assertSame(['« Analyse client » attend votre décision', 'wait', '/workflows/runs/' . $child['id']], [$entry['status'], $entry['tone'], $entry['link']['href']]);

        // Côté appelé : d'où il vient.
        $childRun = WorkflowRun::find($child['id']);
        $from = $this->ecran('runState', [$childRun])['caller'];
        $this->assertSame([$run['id'], 'Dossier', 'Analyser le client'], [$from['run_id'], $from['name'], $from['brick']]);
        $head = $this->ecran('journalData', [$childRun, $this->userId])['head'];
        $this->assertSame(['Appelé par un workflow', 'Exécution appelante', '/workflows/runs/' . $run['id']], [$head['trigger'], $head['parent_label'], $head['parent']]);

        // Ce que l'appelé a dépensé compte dans le coût de l'appelant.
        $this->debite((int)$child['conversation_id'], 20);
        $this->debite((int)$run['conversation_id'], 5);
        $this->assertSame(20, WorkflowRun::calledCredits($run['id']));
        $this->assertSame(25, $this->ecran('runState', [WorkflowRun::find($run['id'])])['credits']);

        // La personne approuve chez l'appelé, l'appelant termine.
        WorkflowEngine::gateResolved($childRun, WorkflowRun::lastStep($child['id'], 'analyser' . WorkflowEngine::REVIEW_SUFFIX), 'approved');
        $this->repond($this->jobEnCours($run['id']), 'Note rédigée.');
        $this->assertSame('done', WorkflowRun::find($run['id'])['status']);
        $done = $this->ecran('journalData', [WorkflowRun::find($run['id']), $this->userId]);
        $entry = array_values(array_filter($done['entries'], static fn(array $e): bool => $e['key'] === 'appel'))[0];
        $this->assertSame(['« Analyse client » a terminé', 'ok', 'Analyse'], [$entry['status'], $entry['tone'], $entry['piece']['name'] ?? null]);
        $this->assertSame(25, $done['head']['credits']);

        // Coût constaté d'une exécution de l'appelant : l'appelé compris. Estimation : l'appel au coût réel de l'appelé.
        $this->assertSame(['runs' => 1, 'avg' => 25, 'min' => 25, 'max' => 25], WorkflowRun::recentCredits($parent['id']));
        $choices = WorkflowCatalog::choices(User::findById($this->userId));
        $est = WorkflowCatalog::estimate(Workflow::findById($parent['id'])['published'], $choices);
        $line = array_values(array_filter($est['lines'], static fn(array $l): bool => $l['title'] === 'Analyser le client'))[0];
        $this->assertSame([20, '« Analyse client » · le coût de sa dernière exécution'], [$line['credits'], $line['note']]);

        // Les exécutions appelées se voient dans l'historique de l'appelé, avec leur origine.
        $history = WorkflowRun::history($this->userId, $enfant['id'], 10);
        $this->assertSame([WorkflowCall::TRIGGER], array_column($history, 'trigger_kind'));
    }
}
