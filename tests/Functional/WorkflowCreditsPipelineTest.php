<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Ce que coûte un workflow, AVANT (estimation) et APRÈS (pages d'usage), sur
 * base réelle.
 *
 *   - une étape s'estime sur les étapes de workflow RÉELLES de son modèle (bien
 *     plus longues qu'une réponse de chat : elles lisent le dossier) ;
 *   - l'estimation dit, brique par brique, sur quoi elle repose, et les
 *     dernières exécutions terminées donnent le coût constaté ;
 *   - les pages Consommation et Usage & crédits montrent les crédits débités
 *     par chaque workflow (jusqu'ici fondus dans « Conversations »).
 */
final class WorkflowCreditsPipelineTest extends DatabaseTestCase
{
    private const ALEX  = 31;
    private const AUTRE = 32;

    private const CHOIX = [
        'llms' => ['openai::gpt-5.6' => 'GPT-5.6', 'openai::gpt-6-luna' => 'GPT-6 Luna'],
        'default_llm' => 'openai::gpt-6-luna',
        'profiles' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        CreditEstimate::forget();
        $this->insertUser(self::ALEX, 'creator');
        $this->insertUser(self::AUTRE, 'creator');
        // Une clé : le modèle auxiliaire (questions à MIA, demande d'image) a un tarif.
        ApiKey::save('openai', 'sk-test-factice');
    }

    protected function tearDown(): void
    {
        CreditEstimate::forget();
        Settings::refresh();
        parent::tearDown();
    }

    private function workflow(int $user, string $name, string $color = '#6366f1'): int
    {
        $this->db()->prepare('INSERT INTO workflows (user_id, name, color, draft_json) VALUES (?, ?, ?, ?)')
            ->execute([$user, $name, $color, '{}']);
        return (int)$this->db()->lastInsertId();
    }

    /** Une exécution et sa conversation ; renvoie l'id de la conversation. */
    private function execution(int $wf, int $user, string $status = WorkflowRun::DONE): int
    {
        $this->db()->prepare('INSERT INTO workflow_runs (workflow_id, user_id, status, definition_json) VALUES (?, ?, ?, ?)')
            ->execute([$wf, $user, $status, '{}']);
        $run = (int)$this->db()->lastInsertId();
        $this->db()->prepare('INSERT INTO conversations (user_id, title, workflow_run_id) VALUES (?, ?, ?)')->execute([$user, 'Exécution', $run]);
        $conv = (int)$this->db()->lastInsertId();
        $this->db()->prepare('UPDATE workflow_runs SET conversation_id = ? WHERE id = ?')->execute([$conv, $run]);
        return $conv;
    }

    private function conversation(int $user): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$user, 'Chat']);
        return (int)$this->db()->lastInsertId();
    }

    private function ligne(int $user, int $conv, int $credits, string $source = 'chat', string $llm = 'openai::gpt-5.6', int $in = 3000, int $out = 300, bool $exempt = false): void
    {
        UsageLog::insertLlm([
            'user_id' => $user, 'conversation_id' => $conv, 'llm_provider' => $llm, 'model' => explode('::', $llm)[1],
            'tokens_input' => $in, 'tokens_output' => $out, 'credits_used' => $credits, 'cost_eur' => 0.001,
            'source' => $source, 'quota_exempt' => $exempt,
        ]);
    }

    private function outil(string $type = 'image'): int
    {
        $this->db()->prepare("INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES ('Visuels', ?, 'openai', 'gpt-image-2.5-flare', 1)")->execute([$type]);
        return (int)$this->db()->lastInsertId();
    }

    private static function def(array $nodes): array
    {
        return WorkflowSpec::normalize(['name' => 'Essai', 'nodes' => $nodes]);
    }

    // ------------------------------------------------------------------
    // Estimation
    // ------------------------------------------------------------------

    public function testUneEtapeSEstimeSurLesEtapesDeWorkflowReellesDeSonModele(): void
    {
        $wf = $this->workflow(self::ALEX, 'Veille');
        for ($i = 0; $i < 6; $i++) {
            // Les étapes lisent le dossier : bien plus longues qu'une réponse de chat.
            $this->ligne(self::ALEX, $this->execution($wf, self::ALEX), 30, 'chat', 'openai::gpt-5.6', 30000, 2000);
            $this->ligne(self::ALEX, $this->conversation(self::ALEX), 5, 'chat', 'openai::gpt-5.6', 3000, 300);
        }
        CreditEstimate::forget();

        $etape = CreditEstimate::workflowStep('openai::gpt-5.6');
        $this->assertSame('workflow', $etape['basis']);
        $this->assertSame(6, $etape['samples']);
        $this->assertSame((int)CreditService::computeLlmCredits('openai::gpt-5.6', 30000, 2000)['total_credits'], $etape['credits']);
        // Le chat, lui, n'est pas mélangé aux étapes.
        $chat = CreditEstimate::chatReply('openai::gpt-5.6');
        $this->assertSame('model', $chat['basis']);
        $this->assertSame((int)CreditService::computeLlmCredits('openai::gpt-5.6', 3000, 300)['total_credits'], $chat['credits']);
        // Trop peu d'étapes d'un modèle : une réponse de chat de ce modèle.
        $this->assertSame(CreditEstimate::chatReply('openai::gpt-6-luna'), CreditEstimate::workflowStep('openai::gpt-6-luna'));

        $est = WorkflowCatalog::estimate(self::def([
            ['type' => 'step', 'key' => 'e1', 'title' => 'Synthèse', 'llm' => 'openai::gpt-5.6', 'instruction' => 'Résume.'],
        ]), self::CHOIX);
        $this->assertSame(CreditEstimate::round($etape['credits']), $est['typical']);
        $this->assertSame('Synthèse', $est['lines'][0]['title']);
        $this->assertSame($etape['credits'], $est['lines'][0]['credits']);
        $this->assertStringContainsString('moyenne de 6 étapes de workflow réelles', $est['lines'][0]['note']);
        $this->assertStringContainsString('moyenne de 6 étapes de workflow réelles', $est['html']);
        $this->assertStringContainsString('Détail de l’estimation', $est['html']);
    }

    public function testLEstimationDitSurQuoiElleReposeBriqueParBrique(): void
    {
        $outil = $this->outil();
        CreditEstimate::forget();
        $aide  = (string)SemanticChunker::resolveChunkingLlm();
        $juge  = (int)CreditEstimate::helperCall(UsageSource::WORKFLOW_JUDGE, $aide);
        $redac = (int)CreditEstimate::helperCall(UsageSource::IMAGE_PROMPT, $aide);
        $this->assertGreaterThan(0, $juge);
        $this->assertGreaterThan(0, $redac, 'La rédaction de la demande d’image a un coût.');
        $luna  = CreditEstimate::workflowStep('openai::gpt-6-luna')['credits'];
        $image = CreditEstimate::toolUse(ChatTool::findById($outil))['credits'];

        $def = self::def([
            ['type' => 'step', 'key' => 'e1', 'title' => 'Lire <b>tout</b>', 'instruction' => 'Lis.'],
            ['type' => 'create', 'key' => 'c1', 'title' => 'Affiche', 'kind' => 'image', 'tool_id' => $outil, 'instruction' => 'Une affiche.'],
            ['type' => 'condition', 'key' => 'q1', 'title' => 'Urgent ?', 'mode' => 'question', 'question' => 'Est-ce urgent ?',
             'yes' => ['nodes' => [['type' => 'step', 'key' => 'o1', 'title' => 'Alerter', 'instruction' => 'Alerte.']]],
             'no'  => ['nodes' => [
                ['type' => 'step', 'key' => 'n1', 'title' => 'Classer', 'instruction' => 'Classe.'],
                ['type' => 'step', 'key' => 'n2', 'title' => 'Archiver', 'instruction' => 'Archive.'],
             ]]],
            ['type' => 'loop', 'key' => 'b1', 'title' => 'Chaque client', 'mode' => 'each', 'max' => 10,
             'body' => [['type' => 'step', 'key' => 'bs', 'title' => 'Relancer', 'instruction' => 'Relance.']]],
        ]);
        $est = WorkflowCatalog::estimate($def, self::CHOIX);
        $lignes = array_column($est['lines'], null, 'title');

        $this->assertStringContainsString('sur une réponse type (pas encore d’historique)', $lignes['Lire <b>tout</b>']['note']);
        $this->assertSame($image + $redac, $lignes['Affiche']['credits']);
        $this->assertStringContainsString('rédaction de la demande comprise', $lignes['Affiche']['note']);
        $this->assertStringContainsString('« Visuels » · son tarif', $lignes['Affiche']['note']);
        $this->assertSame($juge, $lignes['Urgent ?']['credits']);
        $this->assertSame('Question posée à MIA', $lignes['Urgent ?']['note']);
        // Une seule voie est prise : la plus chère (deux étapes) est comptée.
        $this->assertSame(2 * $luna, $lignes['Urgent ? — ses voies']['credits']);
        $this->assertStringContainsString('la plus chère est comptée', $lignes['Urgent ? — ses voies']['note']);
        // Une boucle se paie par tour : la moitié des éléments permis.
        $this->assertSame(5 * $luna, $lignes['Chaque client']['credits']);
        $this->assertStringContainsString('5 tours supposés', $lignes['Chaque client']['note']);
        $this->assertContains('Une boucle se paie par tour : son coût réel suit le nombre d’éléments qu’elle traite.', $est['notes']);

        $total = $luna + $image + $redac + $juge + 2 * $luna + 5 * $luna;
        $this->assertSame(CreditEstimate::round($total), $est['typical']);
        $this->assertSame((int)floor($total * WorkflowCatalog::ESTIMATE_LOW), $est['min']);
        $this->assertSame((int)ceil($total * WorkflowCatalog::ESTIMATE_HIGH), $est['max']);
        // Rendu échappé : un titre ne pose jamais de balise.
        $this->assertStringContainsString('Lire &lt;b&gt;tout&lt;/b&gt;', $est['html']);
        $this->assertStringNotContainsString('<b>tout</b>', $est['html']);

        // Éléments comptés au lancement : plus de supposition.
        $connu = WorkflowCatalog::estimate($def, self::CHOIX, ['b1' => 3]);
        $boucle = array_column($connu['lines'], null, 'title')['Chaque client'];
        $this->assertSame(3 * $luna, $boucle['credits']);
        $this->assertStringContainsString('3 tours (éléments comptés au lancement)', $boucle['note']);
        $this->assertNotContains('Une boucle se paie par tour : son coût réel suit le nombre d’éléments qu’elle traite.', $connu['notes']);
    }

    public function testUnFournisseurSeulSEstimeSurLeModeleReellementAppele(): void
    {
        // Aucun modèle activé : le chat désigne « openai » et appelle le
        // modèle de la clé, sinon le défaut du catalogue — l'estimation aussi.
        $this->assertSame('openai::' . LlmCatalog::defaultModel('openai'), CreditEstimate::modelKey('openai'));
        $this->assertSame('openai::gpt-6-luna', CreditEstimate::modelKey('openai::gpt-6-luna'));
        $this->assertNotNull(CreditEstimate::workflowStep('openai'));
        ApiKey::save('openai', 'sk-test-factice', 'gpt-6-luna');
        $this->assertSame('openai::gpt-6-luna', CreditEstimate::modelKey('openai'));
        $this->assertSame(CreditEstimate::chatReply('openai::gpt-6-luna'), CreditEstimate::chatReply('openai'));
    }

    public function testSansTarifNiHistoriqueRienNEstAnnonce(): void
    {
        $choix = ['llms' => ['openai::modele-inconnu' => 'Inconnu'], 'default_llm' => 'openai::modele-inconnu', 'profiles' => []];
        $def = self::def([['type' => 'step', 'key' => 'e1', 'title' => 'Étape', 'instruction' => 'Fais.']]);
        $this->assertNull(WorkflowCatalog::estimate($def, $choix));

        // Mais le coût constaté des dernières exécutions se montre quand même.
        $est = WorkflowCatalog::estimate($def, $choix, [], ['runs' => 1, 'avg' => 40, 'min' => 40, 'max' => 40]);
        $this->assertSame(0, $est['max']);
        $this->assertStringContainsString('aucun tarif connu', $est['lines'][0]['note']);
        $this->assertStringContainsString('La dernière exécution a coûté 40 crédits.', $est['html']);
    }

    public function testLesDernieresExecutionsTermineesDonnentLeCoutReel(): void
    {
        $wf = $this->workflow(self::ALEX, 'Rapport');
        $a = $this->execution($wf, self::ALEX);
        $this->ligne(self::ALEX, $a, 20);
        $b = $this->execution($wf, self::ALEX, WorkflowRun::STOPPED);
        $this->ligne(self::ALEX, $b, 30);
        UsageLog::insertTool(['user_id' => self::ALEX, 'conversation_id' => $b, 'tool_id' => $this->outil(), 'tool_type' => 'image', 'credits_used' => 10]);
        $this->execution($wf, self::ALEX);                       // rien de débité : écartée
        $enCours = $this->execution($wf, self::ALEX, WorkflowRun::RUNNING);
        $this->ligne(self::ALEX, $enCours, 500);                 // pas terminée : écartée

        $this->assertSame(['runs' => 2, 'avg' => 30, 'min' => 20, 'max' => 40], WorkflowRun::recentCredits($wf));
        $this->assertNull(WorkflowRun::recentCredits($this->workflow(self::ALEX, 'Neuf')));
        $this->assertNull(WorkflowRun::summarizeCredits([0, 0]));

        $est = WorkflowCatalog::estimate(self::def([['type' => 'step', 'key' => 'e1', 'title' => 'É', 'instruction' => 'x']]), self::CHOIX, [], WorkflowRun::recentCredits($wf));
        $this->assertStringContainsString('Les 2 dernières exécutions ont coûté 30 crédits en moyenne (de 20 à 40).', $est['html']);
    }

    // ------------------------------------------------------------------
    // Pages d'usage
    // ------------------------------------------------------------------

    public function testLesPagesDUsageMontrentCeQueCoutentLesWorkflows(): void
    {
        $veille = $this->workflow(self::ALEX, 'Veille', '#ef4444');
        $r1 = $this->execution($veille, self::ALEX);
        $this->ligne(self::ALEX, $r1, 40);
        $this->ligne(self::ALEX, $r1, 7, UsageSource::KB_EMBEDDING, 'openai::text-embedding-3-large', 900, 0, true);
        $r2 = $this->execution($veille, self::ALEX);
        $this->ligne(self::ALEX, $r2, 60);
        UsageLog::insertTool(['user_id' => self::ALEX, 'conversation_id' => $r2, 'tool_id' => $this->outil('html'), 'tool_type' => 'html', 'credits_used' => 20]);
        $autre = $this->workflow(self::AUTRE, 'Relances', 'rouge');
        $this->ligne(self::AUTRE, $this->execution($autre, self::AUTRE), 15);
        $this->ligne(self::ALEX, $this->conversation(self::ALEX), 900); // une conversation ordinaire

        $tout = UsageLog::workflowCredits();
        $this->assertSame(['Veille', 'Relances'], array_column($tout, 'name'));
        $this->assertSame(127, $tout[0]['credits'], 'Toute ligne de ses exécutions, outils et maintenance compris.');
        $this->assertSame(2, $tout[0]['runs']);
        $this->assertSame('#ef4444', $tout[0]['color']);
        $this->assertSame('', $tout[1]['color'], 'Une couleur invalide n’est jamais posée dans un style.');
        $this->assertSame('User 31', $tout[0]['owner']);

        // La page de la personne : ses workflows, ce qui entame son quota.
        $alex = UsageLog::workflowCredits(null, null, self::ALEX, true);
        $this->assertCount(1, $alex);
        $this->assertSame(120, $alex[0]['credits']);

        $ov = UsageInsights::overview(null, null, null, UsageLog::byCategory(null, null, null), null);
        $this->assertSame(142, $ov['workflows_credits']);
        $this->assertSame(3, $ov['workflows_runs']);
        $adoption = array_column($ov['adoption']['used'], null, 'key');
        $this->assertSame(2, $adoption['workflows']['people']);
        $this->assertSame(3, $adoption['workflows']['uses']);

        // Sur une période et pour une personne (filtres de la page Consommation).
        $jour = date('Y-m-d');
        $ovAlex = UsageInsights::overview($jour, $jour, self::ALEX, UsageLog::byCategory($jour, $jour, self::ALEX), null);
        $this->assertSame(127, $ovAlex['workflows_credits']);
        $this->assertSame(['Veille'], array_column($ovAlex['workflows'], 'name'));
        $this->assertSame(2, array_column($ovAlex['adoption']['used'], null, 'key')['workflows']['uses']);
        $hier = date('Y-m-d', strtotime('-1 day'));
        $this->assertSame([], UsageLog::workflowCredits($hier, $hier));
    }

    public function testLesGabaritsPosentLesCartes(): void
    {
        $admin = (string)file_get_contents(ROOT . '/templates/admin/usage-overview.php');
        $this->assertStringContainsString("\$ov['workflows']", $admin);
        $this->assertStringContainsString("'workflows'    => Workflow::ICON_PATHS", $admin);
        $perso = (string)file_get_contents(ROOT . '/templates/settings/usage.php');
        $this->assertStringContainsString('$workflowRows', $perso);
        $this->assertStringContainsString('/workflows?vue=historique&amp;workflow=', $perso);
        $controleur = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('UsageLog::workflowCredits($monthStart, $monthEnd, $userId, true)', $controleur);
        $wf = (string)file_get_contents(ROOT . '/src/Controllers/WorkflowController.php');
        $this->assertSame(4, substr_count($wf, 'WorkflowRun::recentCredits('), 'L’éditeur, son enregistrement, la fenêtre de lancement et son aperçu.');
        foreach (['public/assets/js/workflow-editor.js', 'public/assets/js/workflows.js'] as $js) {
            $this->assertStringContainsString('estimate.html', (string)file_get_contents(ROOT . '/' . $js), "$js pose le bloc rendu par le serveur.");
        }
    }
}
