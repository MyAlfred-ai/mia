<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * L'agent montre où il en est, et reprend là où il s'est arrêté :
 *
 *  - plan de travail visible (outil plan_de_travail → carte au-dessus de la
 *    réponse, persistée dans messages.agent_json, migration 078) ;
 *  - lignes « en cours » dans la trace, avant l'exécution d'un outil ;
 *  - budget d'allers-retours relevé quand le plan est long, effort `high`
 *    sur un tour à connecteurs ;
 *  - bouton « Continuer » quand la réponse peut être poursuivie (plan
 *    inachevé, budget atteint), et plan inachevé rappelé au tour suivant.
 */
final class AgentPlanTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------- Outil plan_de_travail ----------

    public function testLePlanEstNormalise(): void
    {
        $plan = PlanTools::normalize([
            'etapes' => [
                ['titre' => "  Retrouver\nle mail  ", 'statut' => 'fait'],
                ['titre' => 'Créer l’évènement', 'statut' => 'bientot'],
                ['titre' => '', 'statut' => 'fait'],
                'Prévenir Paul',
            ],
            'commentaire' => ' Mail trouvé. ',
        ]);
        $this->assertSame([
            ['titre' => 'Retrouver le mail', 'statut' => 'fait'],
            ['titre' => 'Créer l’évènement', 'statut' => 'a_faire'],
            ['titre' => 'Prévenir Paul', 'statut' => 'a_faire'],
        ], $plan['etapes']);
        $this->assertSame('Mail trouvé.', $plan['commentaire']);
        $this->assertSame(['Créer l’évènement', 'Prévenir Paul'], PlanTools::unfinished($plan));

        $this->assertNull(PlanTools::normalize(['etapes' => []]));
        $this->assertNull(PlanTools::normalize([]));
        $json = PlanTools::normalize(['etapes' => '[{"titre":"A","statut":"en_cours"}]']);
        $this->assertSame('en_cours', $json['etapes'][0]['statut'], 'Certains modèles sérialisent le tableau.');
        $long = PlanTools::normalize(['etapes' => array_fill(0, 20, ['titre' => 'x', 'statut' => 'a_faire'])]);
        $this->assertCount(PlanTools::MAX_STEPS, $long['etapes']);
    }

    public function testLOutilAfficheLePlanEtLeRetient(): void
    {
        $events = [];
        $retenu = null;
        $turn = new AgentTurn(1, 2, [], 1000, function (array $e) use (&$events): void { $events[] = $e; },
            static fn(string $t, array $a, ?array $r): array => []);
        $tools = new PlanTools($turn, function (array $p) use (&$retenu): void { $retenu = $p; });

        $res = $tools->handlers()[PlanTools::TOOL](['etapes' => [['titre' => 'A', 'statut' => 'fait'], ['titre' => 'B', 'statut' => 'a_faire']]]);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['restant']);
        $this->assertSame($retenu, $events[0]['plan'], 'Même plan à l’écran et dans l’état du tour.');
        $this->assertArrayHasKey('error', $tools->handlers()[PlanTools::TOOL](['etapes' => []]));

        $this->assertSame([], $tools->externalContentTools(), 'Le plan ne lit rien.');
        $this->assertNull($tools->connector(), 'Pas un connecteur : il ne déclenche pas la méthode agent à lui seul.');
        $this->assertStringContainsString('MÊME tour', $tools->definitions()[0]['description']);
        $this->assertNull(ChatController::actionEntryForTool(PlanTools::TOOL, [], []), 'Le plan a sa carte : pas de ligne de trace.');
    }

    public function testLePlanRejointLesTourAConnecteursEtEstPersiste(): void
    {
        $src = AgentSource::chat();
        $this->assertMatchesRegularExpression(
            '/if \(\$registry->engagedConnectors\(\) !== \[\] \|\| \$autoConnectors !== \[\]\) \{\s*\$registry->add\(new PlanTools\(/', $src);
        $this->assertStringContainsString('$this->turnPlan = null;', $src, 'Remis à zéro à chaque tour (instance réutilisée par le worker).');
        $this->assertStringContainsString("Message::setAgent((int)\$msg['id'], ['plan' => \$this->turnPlan, 'suite' => \$followUp]);", $src);
        $this->assertStringContainsString("'plan' => \$this->turnPlan,", $src, 'Rejoué dans le payload `done` (attache tardive).');
        $this->assertStringContainsString("'suite'        => \$m['role'] === 'assistant'", $src, 'Historique JSON.');
        $this->assertStringContainsString('PlanTools::TOOL', (string)file_get_contents(ROOT . '/src/Agent/AgentGuidance.php'),
            'La méthode agent renvoie à l’outil, pas à une phrase que les Claude récents masquent.');

        $migration = (string)file_get_contents(ROOT . '/database/migrations/078_message_agent.sql');
        $this->assertStringContainsString('agent_json', $migration);
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString("/src/Agent/Tools/PlanTools.php'", (string)file_get_contents(ROOT . '/' . $f), $f);
        }
    }

    // ---------- Lignes « en cours » ----------

    public function testLaLigneEnCoursPrecedeLExecution(): void
    {
        $ordre = [];
        $registry = new ToolRegistry(
            function (string $n, array $a, array $r) use (&$ordre): void { $ordre[] = 'fin'; },
            function (string $n, array $a) use (&$ordre): void { $ordre[] = 'debut'; }
        );
        $registry->add(new class implements ToolProvider {
            public function key(): string { return 't'; }
            public function connector(): ?string { return null; }
            public function definitions(): array { return [['name' => 'lire', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]]; }
            public function guidance(): string { return ''; }
            public function handlers(): array { return ['lire' => function (array $a): array { return ['ok' => 1]; }]; }
            public function externalContentTools(): array { return []; }
        });
        $registry->dispatch('lire', ['q' => 1]);
        $registry->dispatch('lire', ['q' => 1]); // refusé par la garde : ni début ni fin
        $this->assertSame(['debut', 'fin'], $ordre);

        $entry = ChatController::runningActionEntry('rechercher_gmail', ['requete' => 'facture EDF']);
        $this->assertSame('gmail_search', $entry['t']);
        $this->assertSame('facture EDF', $entry['d']);
        $this->assertSame(ChatController::actionLabel('gmail_search'), $entry['l']);
        $this->assertNull(ChatController::runningActionEntry(PlanTools::TOOL, []));
        $this->assertStringContainsString("\$this->sseEmit(['action_start' => \$entry]);", AgentSource::chat());
    }

    // ---------- Budget, effort, poursuite ----------

    public function testUnPlanLongEleveLeBudgetDAllersRetours(): void
    {
        $this->assertSame(LLMInterface::TOOL_MAX_ROUNDS, ChatController::roundsForPlan(null));
        $trois = ['etapes' => array_fill(0, 3, ['titre' => 'x', 'statut' => 'a_faire'])];
        $quatre = ['etapes' => array_fill(0, 4, ['titre' => 'x', 'statut' => 'a_faire'])];
        $this->assertSame(LLMInterface::TOOL_MAX_ROUNDS, ChatController::roundsForPlan($trois));
        $this->assertSame(LLMInterface::TOOL_MAX_ROUNDS_EXTENDED, ChatController::roundsForPlan($quatre));
        $this->assertStringContainsString("'max_rounds'  => fn(): int => self::roundsForPlan(\$this->turnPlan),", AgentSource::chat(),
            'Relu à chaque aller : un plan affiché en cours de route élargit la boucle.');
    }

    public function testLEffortEstReleveSurUnTourAConnecteurs(): void
    {
        $this->assertSame('high', ChatController::toolLoopOptions(1, ['drive'])['effort'] ?? null);
        $this->assertArrayNotHasKey('effort', ChatController::toolLoopOptions(1, []), 'Une question simple garde l’effort du modèle.');
        $this->assertArrayNotHasKey('task_budget', ChatController::toolLoopOptions(1, ['drive']),
            'Budget de tâche déconseillé par Anthropic en session interactive.');
    }

    public function testLaReponsePeutEtrePoursuivie(): void
    {
        $inacheve = ['etapes' => [['titre' => 'A', 'statut' => 'fait'], ['titre' => 'B', 'statut' => 'en_cours']]];
        $fini = ['etapes' => [['titre' => 'A', 'statut' => 'fait'], ['titre' => 'B', 'statut' => 'abandonne']]];
        $this->assertSame('budget', ChatController::followUpReason(['rounds_exhausted' => true], $fini, false));
        $this->assertSame('plan', ChatController::followUpReason([], $inacheve, false));
        $this->assertNull(ChatController::followUpReason([], $fini, false));
        $this->assertNull(ChatController::followUpReason([], null, false));
        $this->assertNull(ChatController::followUpReason(['rounds_exhausted' => true], $inacheve, true), 'Réponse en erreur : rien à poursuivre.');
    }

    public function testLePlanInacheveEstRappeleAuTourSuivant(): void
    {
        $plan = json_encode(['plan' => ['etapes' => [['titre' => 'Lire le mail', 'statut' => 'fait'], ['titre' => 'Créer la tâche', 'statut' => 'en_cours']]]]);
        $history = [
            ['role' => 'user', 'content' => 'Fais-le'],
            ['role' => 'assistant', 'content' => 'Carte préparée.', 'agent_json' => $plan],
            ['role' => 'assistant', 'content' => '✅ Tâche créée'],
        ];
        $fragment = ActionJournal::planFragment($history);
        $this->assertStringContainsString('1. Lire le mail (fait) ; 2. Créer la tâche (en cours)', $fragment);
        $this->assertStringContainsString('issues des cartes', $fragment);
        $this->assertStringContainsString($fragment, ActionJournal::promptFragment($history));

        $history[] = ['role' => 'user', 'content' => 'Autre chose'];
        $this->assertSame('', ActionJournal::planFragment($history), 'Un plan d’un échange plus ancien n’engage plus rien.');
        $this->assertSame('', ActionJournal::planFragment([['role' => 'assistant', 'content' => 'x',
            'agent_json' => json_encode(['plan' => ['etapes' => [['titre' => 'A', 'statut' => 'fait']]]])]]), 'Plan terminé : rien à rappeler.');
    }

    // ---------- Rendu ----------

    public function testLesTroisRendusMontrentLePlanEtLaSuite(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        foreach (['function renderPlanCard(', 'function renderProgressNote(', 'function syncContinueButton(', 'json.action_start', "data-icon=\"running\""] as $motif) {
            $this->assertStringContainsString($motif, $js, $motif);
        }
        $this->assertStringContainsString("msgInput.value = t('Continue.');", $js, '« Continuer » part comme un message ordinaire.');
        $this->assertStringContainsString('plan: msg.plan || null, suite: msg.suite || null', $js, 'Historique JSON.');
        $this->assertStringContainsString('if (pendingBatchCards().length > 0', $js, 'Pas de « Continuer » tant qu’une carte attend.');

        $tpl = (string)file_get_contents(ROOT . '/templates/partials/message.php');
        $this->assertStringContainsString('class="msg-plan"', $tpl);
        $this->assertStringContainsString('data-suite=', $tpl);
        $this->assertLessThan(strpos($tpl, '<div class="msg-trace">'), strpos($tpl, '<div class="msg-plan">'), 'Le plan, puis ce qui a été fait.');

        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        foreach (['.msg-plan-step[data-status="fait"]', '.msg-trace-icon[data-icon="running"]', '.msg-progress', '.msg-continue-btn'] as $motif) {
            $this->assertStringContainsString($motif, $css, $motif);
        }
        $client = require ROOT . '/lang/en.client.php';
        foreach (['Plan de travail', 'Continuer', 'Continue.', 'À faire', 'En cours', 'Fait', 'Abandonnée'] as $cle) {
            $this->assertArrayHasKey($cle, $client, $cle);
        }
    }

    /**
     * Le modèle coche le plan AVANT sa dernière étape, la mène, puis répond
     * sans rappeler l'outil : sans clôture, la réponse finie restait sous un
     * plan « 3/4 — en cours » et proposait « Continuer ».
     */
    public function testLePlanEstClosQuandLeTourSeTermineSansAttente(): void
    {
        $plan = ['etapes' => [
            ['titre' => 'Lire l’agenda', 'statut' => 'fait'],
            ['titre' => 'Rédiger et envoyer la synthèse', 'statut' => 'en_cours'],
            ['titre' => 'Archiver', 'statut' => 'a_faire'],
        ], 'commentaire' => 'Contexte réuni.'];

        $clos = PlanTools::settle($plan, 1, 0, 0, true);
        $this->assertSame(['fait', 'fait', 'a_faire'], array_column($clos['etapes'], 'statut'), 'Seule l’étape en cours se clôt ; « à faire » ne bouge jamais.');
        $this->assertSame('Contexte réuni.', $clos['commentaire']);
        $this->assertSame(['Archiver'], PlanTools::unfinished($clos));

        // Tout confirmé, sinon rien ne change.
        $this->assertSame($plan, PlanTools::settle($plan, 1, 0, 0, false), 'Erreur, coupure ou budget épuisé.');
        $this->assertSame($plan, PlanTools::settle($plan, 1, 0, 1, true), 'Une carte attend : l’étape reste en cours.');
        $this->assertSame($plan, PlanTools::settle($plan, 2, 1, 0, true), 'Un outil a échoué depuis la dernière mise à jour.');
        $this->assertSame($plan, PlanTools::settle($plan, 0, 0, 0, true), 'Aucun outil après la mise à jour : rien ne prouve l’étape menée.');
        $this->assertNull(PlanTools::settle(null, 3, 0, 0, true));

        // Plan entièrement clos : plus de « Continuer ».
        $deux = ['etapes' => [['titre' => 'A', 'statut' => 'fait'], ['titre' => 'B', 'statut' => 'en_cours']]];
        $this->assertSame('plan', ChatController::followUpReason([], $deux, false));
        $this->assertNull(ChatController::followUpReason([], PlanTools::settle($deux, 1, 0, 0, true), false));
    }

    public function testLaClotureEstCableeAvantLaPersistance(): void
    {
        $src = AgentSource::chat();
        // Issue de chaque outil (hors plan) et aller-retour de la dernière mise à jour.
        $this->assertStringContainsString("\$this->turnToolOutcomes[] = ['round' => \$this->turnRound, 'ok' => !isset(\$result['error'])];", $src);
        $this->assertStringContainsString('$this->turnPlanRound = $this->turnRound;', $src);
        // Une action exécutée sans validation qui échoue compte comme un échec.
        $this->assertStringContainsString("\$this->turnToolOutcomes[array_key_last(\$this->turnToolOutcomes)]['ok'] = false;", $src);
        // Les outils de l'aller de la mise à jour comptent (ordre des appels non garanti).
        $this->assertStringContainsString("if (\$outcome['round'] >= \$this->turnPlanRound) {", $src);
        $this->assertStringContainsString('$pending = count($this->turnActionIds) - $this->turnAutoCount;', $src);
        // Clos AVANT la suite, la persistance et le payload final ; le client le reçoit.
        $clos = strpos($src, '$this->turnPlan = $this->settledPlan(');
        $suite = strpos($src, '$followUp = self::followUpReason($tokens ?? [], $this->turnPlan, $streamError !== null);');
        $this->assertIsInt($clos);
        $this->assertIsInt($suite);
        $this->assertLessThan($suite, $clos);
        $this->assertStringContainsString("\$this->sseEmit(['plan' => \$settled]);", $src);
        // Remis à zéro en tête de tour (le worker réutilise l'instance).
        $this->assertStringContainsString('$this->turnToolOutcomes = [];', $src);
        $this->assertStringContainsString('$this->turnPlanRound = 0;', $src);
        // L'aller-retour courant est tenu par la relecture des outils, une fois par aller.
        $this->assertStringContainsString('$this->turnRound = $loopRounds;', $src);
    }
}
