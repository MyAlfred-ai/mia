<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Historique des exécutions — cf. CLAUDE.md « Workflows » (puce Historique) :
 * WorkflowJournal lit les passages que le moteur garde déjà et en fait un
 * déroulé lisible ; l'onglet de /workflows (workflow-history.js) le pose
 * sans rien savoir.
 */
final class WorkflowJournalTest extends TestCase
{
    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function def(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'synth', 'type' => 'step', 'title' => 'Synthétiser', 'instruction' => 'Résume @{depart}.',
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text'],
                    'on_error' => ['retries' => 2, 'then' => 'stop']],
                ['key' => 'tri', 'type' => 'route', 'title' => 'Quel type ?', 'instruction' => 'Trie @{synthese}.', 'reads' => ['synthese'],
                    'routes' => [
                        ['key' => 'devis', 'label' => 'Devis', 'when' => 'Un prix', 'nodes' => [], 'then' => 'continue'],
                        ['key' => 'autre', 'label' => 'Autre', 'when' => 'Le reste', 'nodes' => [], 'then' => 'stop'],
                    ], 'fallback' => 'devis'],
                ['key' => 'pistes', 'type' => 'loop', 'title' => 'Chaque piste', 'mode' => 'each',
                    'over' => ['piece' => 'synthese'], 'item' => ['key' => 'piste', 'name' => 'Piste'],
                    'body' => [['key' => 'note', 'type' => 'step', 'title' => 'Noter', 'instruction' => 'Note @{piste}.',
                        'output' => ['key' => 'evaluation', 'name' => 'Évaluation', 'form' => 'text']]]],
                ['key' => 'accord', 'type' => 'gate', 'title' => 'Accord', 'reviews' => ['synthese']],
            ],
            'end' => ['title' => 'Réponse prête', 'pieces' => ['synthese']],
        ]);
    }

    private static function step(int $id, string $key, string $status, string $outcome = '', array $detail = [], string $start = '2026-10-16 08:00:00', ?string $end = '2026-10-16 08:00:12', int $attempt = 1): array
    {
        return [
            'id' => $id, 'run_id' => 9, 'node_key' => $key, 'attempt' => $attempt, 'status' => $status, 'outcome' => $outcome,
            'job_id' => null, 'message_id' => $detail['reply_id'] ?? null, 'pending_action_id' => null,
            'resume_at' => $detail['resume'] ?? null, 'detail' => $detail,
            'started_at' => $start, 'finished_at' => $end,
        ];
    }

    private static function execution(string $status = 'done', ?array $error = null): array
    {
        return [
            'id' => 9, 'workflow_id' => 3, 'user_id' => 7, 'conversation_id' => 40, 'version' => 2, 'trigger_kind' => 'schedule',
            'status' => $status, 'current_key' => 'end', 'definition' => self::def(), 'inputs' => [], 'error' => $error,
            'timezone' => 'Europe/Paris', 'parent_run_id' => null,
            'started_at' => '2026-10-16 08:00:00', 'finished_at' => in_array($status, ['done', 'stopped', 'cancelled', 'failed'], true) ? '2026-10-16 08:05:30' : null,
            'updated_at' => '2026-10-16 08:05:30', 'timer_at' => null,
        ];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-16 12:00:00', new \DateTimeZone('UTC'));
    }

    // ------------------------------------------------------------------
    // Formes
    // ------------------------------------------------------------------

    public function testLesDureesSeLisentEnFrancaisEtEnAnglais(): void
    {
        $this->assertSame('8 s', WorkflowJournal::duration(8));
        $this->assertSame('2 min 05 s', WorkflowJournal::duration(125));
        $this->assertSame('14 min', WorkflowJournal::duration(14 * 60 + 9));
        $this->assertSame('1 h 07', WorkflowJournal::duration(3600 + 7 * 60));
        $this->assertSame('2 j 3 h', WorkflowJournal::duration(2 * 86400 + 3 * 3600));
        I18n::use('en');
        $this->assertSame('2 d 3 h', WorkflowJournal::duration(2 * 86400 + 3 * 3600));
    }

    public function testLeJourDUnLancementEstDitSimplement(): void
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable('2026-10-16 15:00', $tz);
        $this->assertSame('Aujourd’hui', WorkflowJournal::dayLabel(new \DateTimeImmutable('2026-10-16 08:00', $tz), $now));
        $this->assertSame('Hier', WorkflowJournal::dayLabel(new \DateTimeImmutable('2026-10-15 23:00', $tz), $now));
        $this->assertSame('lun. 5 oct.', WorkflowJournal::dayLabel(new \DateTimeImmutable('2026-10-05 09:00', $tz), $now));
        $this->assertSame('ven. 5 déc. 2025', WorkflowJournal::dayLabel(new \DateTimeImmutable('2025-12-05 09:00', $tz), $now));
    }

    public function testLesFiltresRangentChaqueEtat(): void
    {
        $this->assertSame('ok', WorkflowJournal::statusGroup(WorkflowRun::DONE));
        $this->assertSame('ok', WorkflowJournal::statusGroup(WorkflowRun::STOPPED), 'Arrêté comme prévu = terminé.');
        $this->assertSame('err', WorkflowJournal::statusGroup(WorkflowRun::FAILED));
        $this->assertSame('off', WorkflowJournal::statusGroup(WorkflowRun::CANCELLED));
        foreach ([WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED] as $s) {
            $this->assertSame('active', WorkflowJournal::statusGroup($s));
        }
    }

    public function testUnFiltreFiltreLaRequeteCommeIlRangeUneLigne(): void
    {
        // La requête (statusesOf) et le classement d'une ligne (statusGroup) lisent la même table.
        $vus = [];
        foreach (WorkflowJournal::GROUPS as $group => $statuses) {
            $this->assertSame($statuses, WorkflowJournal::statusesOf($group));
            foreach ($statuses as $st) {
                $this->assertSame($group, WorkflowJournal::statusGroup($st), $st);
                $vus[] = $st;
            }
        }
        $tous = [WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED, WorkflowRun::DONE, WorkflowRun::STOPPED, WorkflowRun::FAILED, WorkflowRun::CANCELLED];
        sort($tous);
        sort($vus);
        $this->assertSame($tous, $vus, 'Chaque état est dans un filtre, et un seul.');
        $this->assertSame([], WorkflowJournal::statusesOf('all'), '« Tous » ne filtre rien.');
        $this->assertSame([], WorkflowJournal::statusesOf('<script>'));
    }

    public function testUnApercuDeTexteRetireLaSyntaxe(): void
    {
        $this->assertSame(
            'Titre Gras et italique avec lien et code. un deux snake_case',
            WorkflowJournal::plain("# Titre\n\n**Gras** et *italique* avec [lien](http://x) et `code`.\n- un\n- deux\n```php\necho 1;\n```\nsnake_case")
        );
    }

    // ------------------------------------------------------------------
    // La liste
    // ------------------------------------------------------------------

    public function testLeResumeDUnLancementPorteSaBandeEtSonEtat(): void
    {
        $steps = [
            self::step(1, 'start', 'done', '', [], '2026-10-16 08:00:00', '2026-10-16 08:00:00'),
            self::step(2, 'synth', 'done', 'done', ['reply_id' => 50]),
            self::step(3, 'tri', 'failed', 'error', ['error' => ['title' => 'Service saturé', 'message' => 'Réessayez.']]),
        ];
        $s = WorkflowJournal::summary(self::execution('failed', ['title' => 'Service saturé', 'message' => 'Réessayez.']), $steps, 4, 3, self::now());
        $this->assertSame(4, $s['n']);
        $this->assertSame('err', $s['tone']);
        $this->assertSame('err', $s['group']);
        $this->assertSame('Programmé', $s['trigger']);
        $this->assertSame('clock', $s['trigger_icon']);
        $this->assertSame("10\u{00A0}h\u{00A0}00", $s['time'], 'Heure dans le fuseau de l’exécution.');
        $this->assertSame('Aujourd’hui', $s['day']);
        $this->assertSame('5 min 30 s', $s['duration']);
        $this->assertTrue($s['old_version'], 'La version 3 est publiée, ce lancement a tourné en 2.');
        $this->assertSame('Service saturé', $s['error']);
        $this->assertSame(['ok', 'ok', 'err', '', '', ''], array_column($s['track'], 'tone'), 'Départ, 4 briques, livrable.');
        $this->assertSame('/workflows/runs/9', $s['href']);
    }

    public function testUneLigneDitDeQuelWorkflowEtDeQuoiIlSAgissait(): void
    {
        $run = ['workflow_name' => 'Devis client', 'workflow_color' => '#0e7490',
            'inputs' => ['client' => 'Ville de Lyon']] + self::execution();
        $s = WorkflowJournal::summary($run, [], 2, 2, self::now());
        $this->assertSame(['id' => 3, 'name' => 'Devis client', 'color' => '#0e7490'], $s['workflow']);
        $this->assertSame('Client : Ville de Lyon', $s['context']);
        $this->assertFalse($s['old_version']);

        // Une couleur abîmée n'arrive jamais dans un style.
        $bad = WorkflowJournal::summary(['workflow_color' => 'red;}'] + $run, [], 2, 2, self::now());
        $this->assertSame(Workflow::DEFAULT_COLOR, $bad['workflow']['color']);

        I18n::use('en');
        $this->assertSame('Client: Ville de Lyon', WorkflowJournal::context($run));
    }

    public function testLeContexteLitLesChampsPuisCeQuiALanceLeWorkflow(): void
    {
        $def = WorkflowSpec::normalize(['start' => ['fields' => [
            ['key' => 'montant', 'label' => 'Montant', 'type' => 'number'],
            ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
            ['key' => 'pour', 'label' => 'Pour le', 'type' => 'date'],
            ['key' => 'vide', 'label' => 'Vide', 'type' => 'text'],
            ['key' => 'note', 'label' => 'Note', 'type' => 'longtext'],
        ]], 'nodes' => []]);
        $run = ['definition' => $def, 'inputs' => ['montant' => 6200, 'urgent' => true, 'pour' => '2026-10-30', 'vide' => null, 'note' => 'Hors sujet']];
        $this->assertSame("Montant : 6\u{202F}200 · Urgent : oui · Pour le : 30/10/2026", WorkflowJournal::context($run), 'Trois champs au plus, les vides sautés.');

        $long = ['definition' => $def, 'inputs' => ['note' => str_repeat('mot ', 40)]];
        $this->assertStringEndsWith('…', WorkflowJournal::context($long));
        $this->assertLessThanOrEqual(80, mb_strlen(WorkflowJournal::context($long)));

        // Sans champ : ce qui l'a lancé.
        $none = ['definition' => WorkflowSpec::normalize(['nodes' => []]), 'inputs' => []];
        $this->assertSame('« Facture octobre » — compta@exemple.fr', WorkflowJournal::context($none, ['fields' => ['objet' => 'Facture octobre', 'expediteur' => 'compta@exemple.fr']]));
        $this->assertSame('« devis.pdf »', WorkflowJournal::context($none, ['name' => 'devis.pdf']));
        $this->assertSame('Commande 42 reçue', WorkflowJournal::context($none, ['text' => "## Commande 42\n**reçue**"]));
        $this->assertSame('', WorkflowJournal::context($none));
    }

    public function testLeGesteDuJournalSuitLEtat(): void
    {
        $this->assertSame('Décider dans l’exécution', WorkflowJournal::openAction(self::execution('waiting'))['label']);
        $this->assertSame('Suivre en direct', WorkflowJournal::openAction(['timer_at' => '2026-10-16 13:00:00'] + self::execution('waiting'))['label'], 'Une attente qui reprendra seule.');
        $this->assertSame('Suivre en direct', WorkflowJournal::openAction(self::execution('running'))['label']);
        $this->assertSame('Reprendre l’exécution', WorkflowJournal::openAction(self::execution('failed'))['label']);
        $this->assertSame('retry', WorkflowJournal::openAction(self::execution('paused'))['icon']);
        $this->assertSame('Ouvrir l’exécution', WorkflowJournal::openAction(self::execution('done'))['label']);
        foreach (['waiting', 'running', 'failed', 'done'] as $st) {
            $this->assertArrayHasKey(WorkflowJournal::openAction(self::execution($st))['icon'], WorkflowCatalog::icons());
        }
        $head = WorkflowJournal::head(self::execution('failed'), [], 1, null, 0, self::now());
        $this->assertSame('Reprendre l’exécution', $head['open']['label']);
    }

    public function testLeResultatDUnLancementTermineMontreLeLivrable(): void
    {
        $art = ['synthese' => ['key' => 'synthese', 'node_key' => 'synth', 'name' => 'Synthèse', 'form' => 'text',
            'content' => ['text' => "# Bilan\nTout va **bien**."], 'provenance' => [], 'version' => 1, 'updated_at' => '']];
        $out = WorkflowJournal::outputs(self::execution('done'), $art);
        $this->assertCount(1, $out);
        $this->assertSame('Synthèse', $out[0]['name']);
        $this->assertSame('Bilan Tout va bien.', $out[0]['excerpt']);
        $this->assertSame('/workflows/runs/9/pieces/synthese/download', $out[0]['download']);
        $this->assertSame([], WorkflowJournal::outputs(self::execution('failed'), $art), 'Rien n’a été livré.');
        $this->assertSame([], WorkflowJournal::outputs(self::execution('done'), []));
    }

    public function testRelancerRepriseLesValeursQueLaVersionPublieeDemandeEncore(): void
    {
        $def = WorkflowSpec::normalize(['start' => ['fields' => [
            ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
            ['key' => 'montant', 'label' => 'Montant', 'type' => 'number'],
            ['key' => 'taux', 'label' => 'Taux', 'type' => 'number'],
            ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
            ['key' => 'pour', 'label' => 'Pour le', 'type' => 'date'],
            ['key' => 'canal', 'label' => 'Canal', 'type' => 'choice', 'options' => ['Email', 'Courrier']],
            ['key' => 'piece', 'label' => 'Pièce', 'type' => 'file'],
        ]], 'nodes' => []]);
        $inputs = [
            'client' => 'Ville de Lyon', 'montant' => 6200, 'taux' => 1.5, 'urgent' => false, 'pour' => '2026-10-30',
            'canal' => 'Fax', 'piece' => 'devis.pdf', 'ancien' => 'disparu',
        ];
        $this->assertSame([
            'client' => 'Ville de Lyon', 'montant' => '6200', 'taux' => '1.5', 'urgent' => 'non', 'pour' => '2026-10-30',
        ], WorkflowJournal::relaunchValues($def, $inputs), 'Une option retirée, un fichier, un champ disparu ne se rejouent pas.');
        $this->assertSame([], WorkflowJournal::relaunchValues($def, []));
    }

    public function testUnLancementEnCoursDitDepuisQuand(): void
    {
        $run = ['current_key' => 'synth'] + self::execution('running');
        $s = WorkflowJournal::summary($run, [self::step(1, 'synth', 'running', '', [], '2026-10-16 11:50:00', null)], 1, null, self::now());
        $this->assertSame('depuis 4 h 00', $s['duration']);
        $this->assertSame('Synthétiser', $s['where']);
        $this->assertSame('active', $s['group']);
    }

    // ------------------------------------------------------------------
    // Le déroulé
    // ------------------------------------------------------------------

    public function testChaquePassageDitSonIssueEtCeQuIlAFait(): void
    {
        $steps = [
            self::step(1, 'start', 'done', '', [], '2026-10-16 08:00:00', '2026-10-16 08:00:00'),
            self::step(2, 'synth', 'failed', 'error', ['error' => ['title' => 'Service saturé', 'message' => 'Le fournisseur est surchargé.'], 'llm' => 'openai::gpt-5.6']),
            self::step(3, 'synth', 'done', 'retried', ['kind' => 'retry', 'auto' => 1, 'of' => 2, 'cause' => 'Service saturé'], '2026-10-16 08:00:12', '2026-10-16 08:01:12', 2),
            self::step(4, 'synth', 'done', 'done', ['reply_id' => 50, 'auto' => 1, 'llm' => 'openai::gpt-5.6'], '2026-10-16 08:01:12', '2026-10-16 08:01:30', 3),
            self::step(5, 'tri', 'done', 'devis', ['reason' => 'On demande un prix.', 'fallback' => true, 'mode' => 'route']),
            self::step(6, 'pistes', 'done', 'done', ['mode' => 'each', 'i' => 2, 'count' => 2, 'total' => 5]),
            self::step(7, 'note#2', 'done', 'done', ['reply_id' => 60]),
            self::step(8, 'accord', 'done', 'returned', ['remark' => 'Plus court.']),
            self::step(9, 'end', 'done', 'done', ['deliveries' => [
                ['status' => 'done', 'label' => 'Email à Paul'],
                ['status' => 'card', 'label' => 'Slack #ventes', 'action_ids' => [72]],
                ['status' => 'failed', 'label' => 'Drive', 'error' => 'Drive : dossier introuvable'],
            ]]),
        ];
        $ctx = [
            'artifacts' => [
                'depart' => ['key' => 'depart', 'node_key' => 'start', 'name' => 'Demande', 'form' => 'fiche', 'content' => ['fields' => ['client' => 'Ville de Lyon']], 'version' => 1],
                'synthese' => ['key' => 'synthese', 'node_key' => 'synth', 'name' => 'Synthèse', 'form' => 'text', 'content' => ['text' => "**Trois** écoles à rénover."], 'version' => 1],
                'pistes~items' => ['key' => 'pistes~items', 'node_key' => 'pistes', 'name' => 'Éléments', 'form' => 'list', 'content' => ['items' => ['Isolation', 'Chauffage']], 'version' => 1],
            ],
            'actions' => [50 => [
                ['t' => 'drive_search', 'l' => 'Recherche dans Google Drive', 'd' => 'écoles', 'ok' => true, 'i' => 'drive'],
                ['t' => 'gmail_read', 'l' => 'Lecture d’un email', 'ok' => false, 'i' => 'mail'],
            ]],
            'cards' => [
                70 => ['id' => 70, 'tool' => 'creer_brouillon_gmail', 'status' => 'executed', 'expired' => false, 'auto' => true, 'preview' => ['action' => 'Créer un brouillon Gmail', 'fichier' => 'Devis écoles'], 'error' => '', 'message_id' => 50],
                71 => ['id' => 71, 'tool' => 'creer_tache', 'status' => 'pending', 'expired' => true, 'auto' => false, 'preview' => ['action' => 'Créer une tâche'], 'error' => '', 'message_id' => 50],
                72 => ['id' => 72, 'tool' => 'envoyer_message_slack', 'status' => 'cancelled', 'expired' => false, 'auto' => false, 'preview' => ['action' => 'Publier sur Slack'], 'error' => '', 'message_id' => 99],
                73 => ['id' => 73, 'tool' => WorkflowEngine::GATE_TOOL, 'status' => 'executed', 'expired' => false, 'auto' => false, 'preview' => ['action' => 'Valider'], 'error' => '', 'message_id' => 50],
            ],
            'profiles' => [],
            'llms' => ['openai::gpt-5.6' => 'GPT-5.6'],
        ];
        $e = WorkflowJournal::entries(self::execution(), $steps, $ctx, self::now());
        $by = static fn(int $i): array => $e[$i];

        $this->assertSame(['start', 'step', 'step', 'step', 'route', 'loop', 'step', 'gate', 'end'], array_column($e, 'type'));

        // Départ : déclencheur et champs de la Fiche.
        $this->assertSame('Programmé', $by(0)['status']);
        $this->assertSame([['label' => 'Client', 'value' => 'Ville de Lyon']], $by(0)['fields']);
        $this->assertSame('', $by(0)['duration'], 'Une brique instantanée ne dit pas « 0 s ».');

        // Un échec repris ensuite n'est pas rouge : il est repris.
        $this->assertSame('warn', $by(1)['tone']);
        $this->assertTrue($by(1)['later']);
        $this->assertStringContainsString('reprise ensuite', $by(1)['status']);
        $this->assertSame('Service saturé', $by(1)['error']['title']);
        $this->assertSame('MIA · GPT-5.6', $by(1)['who']);

        // Le nouvel essai automatique.
        $this->assertSame('retry', $by(2)['icon']);
        $this->assertSame('Nouvel essai automatique — Synthétiser', $by(2)['title']);
        $this->assertSame('Lancé (1/2)', $by(2)['status']);
        $this->assertSame('Service saturé', $by(2)['quote']['text']);

        // La réussite : la pièce et son aperçu, ce qui a été fait, les cartes et leur sort.
        $ok = $by(3);
        $this->assertSame('ok', $ok['tone']);
        $this->assertSame('Terminée', $ok['status']);
        $this->assertContains('Tentative 3', $ok['chips']);
        $this->assertContains('Essai automatique 1', $ok['chips']);
        $this->assertSame('Synthèse', $ok['piece']['name']);
        $this->assertSame('Trois écoles à rénover.', $ok['piece']['excerpt']);
        $this->assertSame('/workflows/runs/9/pieces/synthese/download', $ok['piece']['download']);
        $this->assertSame(['drive', 'mail'], array_column($ok['actions'], 'icon'));
        $this->assertFalse($ok['actions'][1]['ok']);
        $this->assertSame(['Faite sans validation', 'Expirée sans décision'], array_column($ok['cards'], 'state'), 'La carte d’une validation n’est pas une action.');
        $this->assertSame('Devis écoles', $ok['cards'][0]['object']);

        // La décision : sa voie, et qu'elle est la voie par défaut.
        $this->assertSame('Aucune voie ne convenait : voie par défaut « Devis »', $by(4)['status']);
        $this->assertSame('On demande un prix.', $by(4)['quote']['text']);

        // La boucle, puis un tour rangé dessous avec son élément.
        $this->assertSame('2 éléments traités', $by(5)['status']);
        $this->assertContains('5 trouvés, limités à 2', $by(5)['chips']);
        $this->assertTrue($by(6)['nested']);
        $this->assertContains('Élément 2 · Chauffage', $by(6)['chips']);

        // La validation renvoyée, avec la remarque.
        $this->assertSame('Renvoyée pour être reprise', $by(7)['status']);
        $this->assertSame('warn', $by(7)['tone']);
        $this->assertSame('Plus court.', $by(7)['quote']['text']);
        $this->assertSame('Vous', $by(7)['who']);

        // Le livrable et ses livraisons, cartes comprises.
        $this->assertSame(['Livrée', 'Refusée ou remplacée', 'Échec'], array_column($by(8)['deliveries'], 'state'));
        $this->assertSame(['ok', 'off', 'err'], array_column($by(8)['deliveries'], 'tone'));
        $this->assertSame('Drive : dossier introuvable', $by(8)['deliveries'][2]['error']);
        $this->assertContains('Pièces : « Synthèse »', $by(8)['chips']);
    }

    public function testUneFinQuAucunPassageNeDitEstAjoutee(): void
    {
        $steps = [self::step(1, 'start', 'done'), self::step(2, 'tri', 'done', 'autre', ['reason' => 'Publicité.'])];
        $e = WorkflowJournal::entries(self::execution('stopped'), $steps, [], self::now());
        $last = end($e);
        $this->assertSame('closing', $last['type']);
        $this->assertSame('Fin du workflow', $last['title']);
        $this->assertSame('off', $last['tone']);

        $failed = WorkflowJournal::entries(self::execution('failed'), [self::step(1, 'synth', 'failed', 'error')], [], self::now());
        $this->assertSame('Arrêt sur une erreur', end($failed)['title']);
        $this->assertSame('err', end($failed)['tone']);

        // Le livrable ferme la marche : rien à ajouter.
        $done = WorkflowJournal::entries(self::execution('done'), [self::step(1, 'end', 'done', 'done')], [], self::now());
        $this->assertSame(['end'], array_column($done, 'type'));
    }

    public function testUnPassageRefaitNeMontrePasLaPieceDUnAutre(): void
    {
        $steps = [self::step(1, 'synth', 'done', 'done'), self::step(2, 'accord', 'done', 'returned'), self::step(3, 'synth', 'done', 'done', [], '2026-10-16 08:01:00', '2026-10-16 08:01:20', 2)];
        $ctx = ['artifacts' => ['synthese' => ['key' => 'synthese', 'node_key' => 'synth', 'name' => 'Synthèse', 'form' => 'text', 'content' => ['text' => 'v2'], 'version' => 2]]];
        $e = WorkflowJournal::entries(self::execution(), $steps, $ctx, self::now());
        $this->assertNull($e[0]['piece'], 'Le dossier ne garde que la dernière version.');
        $this->assertContains('Refaite ensuite', $e[0]['chips']);
        $this->assertTrue($e[0]['redone']);
        $this->assertSame(2, $e[2]['piece']['version']);
    }

    public function testCeQuiAttendLaPersonneEstDitEtOuvreLExecution(): void
    {
        $steps = [self::step(1, 'accord', 'waiting', '', [], '2026-10-16 08:00:00', null)];
        $e = WorkflowJournal::entries(self::execution('waiting'), $steps, [], self::now());
        $this->assertSame('wait', $e[0]['tone']);
        $this->assertSame('Attend votre décision', $e[0]['status']);
        $this->assertStringContainsString("if (\$e['tone'] === 'wait')", self::src('templates/workflows/partials/history-journal.php'), 'Un lien mène à la décision.');
    }

    public function testLesCartesDisentLeurSort(): void
    {
        $line = static fn(array $c): array => WorkflowJournal::cardLine($c + ['preview' => ['action' => 'Créer']]);
        $this->assertSame(['Approuvée et faite', 'ok'], [$line(['status' => 'executed'])['state'], $line(['status' => 'executed'])['tone']]);
        $this->assertSame('Faite sans validation', $line(['status' => 'executed', 'auto' => true])['state']);
        $this->assertSame('Refusée ou remplacée', $line(['status' => 'cancelled'])['state']);
        $this->assertSame(['Approuvée, mais en échec', 'Boom'], [$line(['status' => 'failed', 'error' => 'Boom'])['state'], $line(['status' => 'failed', 'error' => 'Boom'])['error']]);
        $this->assertSame('Attend votre accord', $line(['status' => 'pending'])['state']);
        $this->assertSame('Expirée sans décision', $line(['status' => 'pending', 'expired' => true])['state']);
    }

    public function testLaTraceEstBorneeEtDitCeQuiResteCache(): void
    {
        $trace = array_fill(0, WorkflowJournal::MAX_ACTIONS + 3, ['l' => 'Lecture', 'i' => 'doc', 'ok' => true]);
        $lines = WorkflowJournal::actionLines($trace);
        $this->assertCount(WorkflowJournal::MAX_ACTIONS + 1, $lines);
        $this->assertSame('+ 3 autres actions', end($lines)['label']);
    }

    public function testLeJournalSeLitEnAnglais(): void
    {
        I18n::use('en');
        $e = WorkflowJournal::entries(self::execution(), [self::step(1, 'accord', 'done', 'approved')], [], self::now());
        $this->assertSame('Approved', $e[0]['status']);
        $this->assertSame('You', $e[0]['who']);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLesRoutesSontLecturesSeulementEtCloisonnees(): void
    {
        $routes = self::src('public/index.php');
        $this->assertStringContainsString("\$router->get('/workflows/history',", $routes);
        $this->assertStringContainsString("\$router->get('/workflows/runs/{id}/journal',", $routes);
        $this->assertStringNotContainsString("'/workflows/{id}/history'", $routes, 'Une seule liste : l’historique des exécutions.');
        $this->assertLessThan(strpos($routes, "'/workflows/{id}/edit'"), strpos($routes, "'/workflows/history'"), 'Route littérale avant les routes à paramètre.');
        $ctl = self::src('src/Controllers/WorkflowController.php');
        foreach (['public function historyList()', 'public function journal(string $id)'] as $sig) {
            $start = strpos($ctl, $sig);
            $this->assertNotFalse($start, $sig);
            $body = substr($ctl, $start, 900);
            $this->assertStringContainsString("requireUser('view')", $body, 'Lire l’historique = voir les workflows.');
            $this->assertStringContainsString('session_write_close()', $body);
        }
        $this->assertMatchesRegularExpression('/function journal\(string \$id\): void\s*\{[^}]*ownRun/s', $ctl, 'Seulement ses propres lancements.');
        // La liste : les lancements de la personne, et un filtre de workflow qui est à elle.
        $this->assertMatchesRegularExpression('/function historyQuery[^{]*\{.*?Workflow::findForUser\(\$wf, \$userId\)/s', $ctl);
        $this->assertStringContainsString("'vue'] ?? '')) {\n            'historique', 'executions' => 'historique',", $ctl, 'L’adresse de l’ancien onglet Exécutions mène à l’historique.');
        $this->assertStringNotContainsString('Dernières exécutions', self::src('templates/workflows/edit.php'), 'L’historique remplace la liste du bas.');
        $model = self::src('src/Models/WorkflowRun.php');
        $this->assertStringNotContainsString('public static function forUser(', $model, 'L’ancienne liste des exécutions a disparu.');
        $this->assertStringContainsString("\$where = 'r.user_id = ?';", $model, 'Toujours bornée à la personne.');
    }

    public function testUnSeulHistoriqueOuvertDepuisLeWorkflowEtUneExecution(): void
    {
        $index = self::src('templates/workflows/index.php');
        $this->assertStringContainsString("\$tab('historique', t('Historique des exécutions'), 0, t('Historique'))", $index);
        $this->assertStringNotContainsString("\$tab('executions'", $index, 'Exécutions et Historique ne font qu’un.');
        $this->assertStringContainsString("partials/history.php", $index);
        $this->assertStringContainsString('workflow-history.js', $index);
        $this->assertStringContainsString("wf-hx-content", $index, 'Le panneau collé exige un cadre qui ne défile pas.');
        $this->assertFileDoesNotExist(ROOT . '/templates/workflows/partials/history-shell.php', 'Plus de fenêtre à part.');

        // L'éditeur et la page d'une exécution y mènent, filtrés sur leur workflow.
        $edit = self::src('templates/workflows/edit.php');
        $this->assertStringContainsString("'/workflows?vue=historique&workflow=' . (int)\$workflow['id']", $edit);
        $this->assertStringContainsString('id="wfHistoryBtn" href=', $edit);
        $run = self::src('templates/workflows/run.php');
        $this->assertStringContainsString("'/workflows?vue=historique&run=' . (int)\$run['id']", $run, 'Le retour rouvre l’historique sur ce lancement.');
        $this->assertStringContainsString('id="wfRunHistory" href=', $run);
        foreach (['edit' => $edit, 'run' => $run] as $tpl => $src) {
            $this->assertStringNotContainsString('data-wf-history-open', $src, $tpl);
            $this->assertStringNotContainsString('workflow-history.js', $src, $tpl);
            $this->assertStringContainsString('#historique(?:-', $src, $tpl . ' : les anciens liens mènent à l’onglet.');
        }

        $page = self::src('templates/workflows/partials/history.php');
        $this->assertStringContainsString('method="get" action="/workflows"', $page, 'Sans script, les filtres marchent encore.');
        $this->assertStringContainsString('type="submit" name="etat"', $page);
        $this->assertStringContainsString('<select class="wf-select" name="workflow"', $page);
        $this->assertStringContainsString("partials/history-journal.php", $page, 'Le journal choisi est rendu dès le premier affichage.');

        $js = self::src('public/assets/js/workflow-history.js');
        $this->assertStringContainsString("'/workflows/history?'", $js);
        $this->assertStringContainsString("'/workflows/runs/' + encodeURIComponent(runId) + '/journal'", $js);
        $this->assertStringContainsString("p.set('vue', 'historique')", $js, 'L’adresse garde filtres et lancement.');
        $this->assertStringContainsString('history.pushState', $js, 'Au téléphone, le retour du navigateur ramène à la liste.');
        $this->assertStringContainsString('extra.upto = upto', $js, 'Une mise à jour garde la suite déjà chargée.');
        $this->assertStringNotContainsString('innerText', $js);
        $this->assertDoesNotMatchRegularExpression("/(?<![\\w.])t\\('/", $js, 'Aucun libellé côté script : tout arrive rendu.');
        $this->assertMatchesRegularExpression('/narrow = window\.matchMedia\(\'\(max-width: 1199px\)\'\)/', $js, 'Même seuil que la feuille de style.');

        $css = self::src('public/assets/css/workflows.css');
        foreach (['.wf-hx {', '.wf-hx-side', '.wf-hist-run', '.wf-tl-item', '.wf-jr-head', '.wf-jr-kicker', '.wf-jr-outputs', '@media (max-width: 1199px)'] as $sel) {
            $this->assertStringContainsString($sel, $css);
        }
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{\s*\.admin-content\.wf-hx-content \{ overflow: visible; \}/', $css);
        $this->assertStringNotContainsString('.wf-hist-panel', $css, 'Plus de fenêtre.');
        $this->assertStringNotContainsString('.wf-run-row', $css, 'Plus de liste Exécutions.');

        // Relancer : la fenêtre de lancement, remplie avec les données du lancement.
        $this->assertStringContainsString('data-launch-values', self::src('templates/workflows/partials/history-journal.php'));
        $this->assertStringContainsString("getAttribute('data-launch-values')", self::src('public/assets/js/workflows.js'));
    }

    public function testLaClasseEstChargeeEtLaBriquePrevenirGardeSonMessage(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('src/Workflow/WorkflowJournal.php', self::src($f), $f);
        }
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("WorkflowRun::STEP_DONE, 'done', ['text' => mb_substr(\$text, 0, 300)]", $engine);
        $this->assertArrayHasKey('history', WorkflowCatalog::icons());
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('Historique des exécutions', self::src('docs/user/fonctionnalites/workflows/lancer-et-suivre.md'));
        $this->assertStringContainsString('Historique des exécutions', self::src('docs/user/fonctionnalites/workflows/revoir-l-historique.md'));
        $this->assertStringNotContainsString('Workflows → Exécutions', self::src('docs/user/fonctionnalites/workflows/lancer-et-suivre.md'));
        $this->assertStringContainsString('workflows-historique', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('WorkflowJournal', self::src('CLAUDE.md'));
    }
}
