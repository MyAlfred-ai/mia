<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ce qui a circulé dans un workflow, relu après coup — cf. CLAUDE.md
 * « Workflows » (puce Historique) : WorkflowContentView rend en HTML sûr le
 * contenu entier d'une pièce et d'une action (l'email écrit par « Préparer
 * ou envoyer un email », le message publié…), l'historique et la page de
 * l'exécution le déplient ; html-preview.js montre une page web dans un
 * cadre isolé. L'aperçu de page est exécuté sous node (sauté sans node).
 */
final class WorkflowContentViewTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    private static function mail(array $args = [], array $extra = []): array
    {
        return $extra + [
            'id' => 70, 'tool' => 'creer_brouillon_gmail', 'status' => 'executed', 'outcome' => 'sent', 'auto' => true,
            'url' => 'https://mail.google.com/mail/u/0/#sent/abc', 'error' => '', 'message_id' => 50,
            'args' => $args + [
                'destinataire' => 'achats@ecoles.fr', 'cc' => 'direction@ecoles.fr', 'objet' => 'Devis rénovation',
                'corps' => "Bonjour,\n\nVoici le **devis** pour les trois écoles :\n\n- École Jaurès\n- École Ferry\n\nCordialement",
            ],
            'preview' => ['action' => 'Nouvel email Gmail', 'fichier' => 'Devis rénovation → achats@ecoles.fr', 'apercu' => 'Bonjour, voici…',
                'provenance' => ['Lecture d’un email — « Demande de devis »']],
        ];
    }

    // ------------------------------------------------------------------
    // Une action
    // ------------------------------------------------------------------

    public function testUnEmailSeRelitEnEntierAvecSesDestinataires(): void
    {
        $html = WorkflowContentView::action(self::mail(), ['memoire' => 'Mémoire technique']);
        foreach (['achats@ecoles.fr', 'direction@ecoles.fr', 'Devis rénovation', 'École Ferry', 'Cordialement'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringContainsString('<dt>À</dt>', $html);
        $this->assertStringContainsString('<dt>Cc</dt>', $html);
        $this->assertStringContainsString('<dt>Objet</dt>', $html);
        $this->assertStringContainsString('<strong>devis</strong>', $html, 'Le corps est rendu comme le brouillon (MailHtml).');
        $this->assertStringContainsString('class="wf-view-mail"', $html);
        $this->assertStringContainsString('Ouvrir dans Gmail', $html);
        $this->assertStringContainsString('href="https://mail.google.com/mail/u/0/#sent/abc"', $html);
        $this->assertStringContainsString('Demande de devis', $html, 'Les contenus lus avant l’action sont dits.');
        $this->assertSame('Voir l’email', WorkflowContentView::actionLabel('creer_brouillon_gmail'));
        $this->assertSame('Voir le contenu', WorkflowContentView::actionLabel('envoyer_message_slack'));
    }

    public function testUnCorpsLongNestPasCoupeAPartirDeLExtraitDeLaCarte(): void
    {
        $long = str_repeat('Une phrase du mémoire technique. ', 400) . 'FIN-DU-CORPS';
        $html = WorkflowContentView::action(self::mail(['corps' => $long]));
        $this->assertStringContainsString('FIN-DU-CORPS', $html, 'Le corps vient des arguments, pas de l’aperçu de 280 caractères.');
    }

    public function testLesPiecesJointesDUneLivraisonSontNommees(): void
    {
        $card = self::mail([WorkflowDeliveries::ATTACH_KEY => [['run_id' => 9, 'piece' => 'memoire'], ['run_id' => 9, 'piece' => 'chiffrage']]]);
        $html = WorkflowContentView::action($card, ['memoire' => 'Mémoire technique', 'chiffrage' => 'Chiffrage']);
        $this->assertStringContainsString('<dt>Pièces jointes</dt>', $html);
        $this->assertStringContainsString('Mémoire technique, Chiffrage', $html);
        $this->assertStringNotContainsString('_wf_attach', $html);
        $this->assertStringNotContainsString('run_id', $html);
    }

    public function testUneReponseDitQueLObjetEtLeDestinataireSontRepris(): void
    {
        $card = self::mail(['destinataire' => '', 'objet' => '', 'repondre_a' => '18c2f']);
        $html = WorkflowContentView::action($card);
        $this->assertStringContainsString('(objet repris du message)', $html);
        $this->assertStringContainsString('expéditeur du message', $html);
    }

    public function testRienDeCeQuEcritLeModeleNeDevientUneBaliseActive(): void
    {
        $card = self::mail([
            'objet' => '<script>alert(1)</script>',
            'corps' => '<p>Bonjour</p><img src="https://pisteur.example/p.gif" onerror="alert(1)"><a href="javascript:alert(1)">clic</a><script>x()</script>',
        ], ['url' => 'javascript:alert(1)']);
        $html = WorkflowContentView::action($card);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html, 'Aucune image distante : elle partirait au chargement.');
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'L’objet est échappé.');
        $this->assertStringNotContainsString('Ouvrir dans Gmail', $html, 'Un lien de résultat qui n’est pas http(s) est ignoré.');

        $md = WorkflowContentView::markdown("[clic](javascript:alert(1)) ![p](https://pisteur.example/p.gif) [ok](https://exemple.fr)");
        $this->assertStringNotContainsString('javascript:', $md);
        $this->assertStringNotContainsString('<img', $md);
        $this->assertStringContainsString('<a target="_blank" rel="noopener noreferrer" href="https://exemple.fr">', $md, 'Les liens s’ouvrent à part.');
    }

    public function testChaqueActionMontreCeQuElleAPorte(): void
    {
        $slack = WorkflowContentView::action(['tool' => 'envoyer_message_slack', 'args' => ['canal' => '#ventes', 'texte' => "*Bilan* de la semaine\n• 3 devis"], 'preview' => []]);
        $this->assertStringContainsString('<dt>Canal</dt>', $slack);
        $this->assertStringContainsString('*Bilan* de la semaine', $slack, 'Le mrkdwn Slack est montré tel quel, pas relu en Markdown.');
        $this->assertStringContainsString('wf-view-pre', $slack);

        $teams = WorkflowContentView::action(['tool' => 'envoyer_message_teams', 'args' => ['conversation_nom' => 'Équipe / Général', 'lieu' => 'canal', 'texte' => '**Point** du jour'], 'preview' => []]);
        $this->assertStringNotContainsString('<dt>Lieu</dt>', $teams, 'Chez Teams, « lieu » n’est pas un lieu.');
        $this->assertStringContainsString('<strong>Point</strong>', $teams);

        $event = WorkflowContentView::action(['tool' => 'creer_evenement_agenda', 'args' => [
            'titre' => 'Visite école', 'debut' => '2026-10-30 09:00', 'fin' => '2026-10-30 10:00', 'lieu' => 'Lyon', 'invites' => ['a@x.fr', 'b@x.fr'],
            'description' => 'Prévoir les plans.', 'fuseau' => 'Europe/Paris',
        ], 'preview' => []]);
        foreach (['<dt>Titre</dt>', '<dt>Début</dt>', '<dt>Fin</dt>', '<dt>Lieu</dt>', 'a@x.fr, b@x.fr', 'Prévoir les plans.'] as $attendu) {
            $this->assertStringContainsString($attendu, $event);
        }
        $this->assertStringNotContainsString('Europe/Paris', $event, 'Ce qui ne se lit pas (fuseau, identifiants) reste hors de l’en-tête.');

        $sheet = WorkflowContentView::action(['tool' => 'creer_google_sheet', 'args' => ['titre' => 'Suivi', 'valeurs' => '[["Client","Montant"],["Lyon",6200]]'], 'preview' => []]);
        $this->assertStringContainsString('<th>Client</th>', $sheet);
        $this->assertStringContainsString('<td>6200</td>', $sheet);

        $n8n = WorkflowContentView::action(['tool' => 'executer_workflow_n8n', 'args' => ['workflow' => 'Relance', 'donnees' => '{"client":"Lyon"}'], 'preview' => []]);
        $this->assertStringContainsString('wf-view-code', $n8n);
        $this->assertStringContainsString('&quot;client&quot;: &quot;Lyon&quot;', $n8n);

        // Rien dans les arguments (action d'avant, outil inconnu) : l'extrait de la carte.
        $old = WorkflowContentView::action(['tool' => 'outil_inconnu', 'args' => [], 'preview' => ['apercu' => 'Extrait gardé']]);
        $this->assertStringContainsString('Extrait gardé', $old);
        $this->assertSame('', WorkflowContentView::action(['tool' => 'outil_inconnu', 'args' => [], 'preview' => []]));
    }

    public function testUnTexteTropLongEstAnnonceCoupe(): void
    {
        $html = WorkflowContentView::markdown(str_repeat('a', WorkflowContentView::TEXT_MAX + 50));
        $this->assertStringContainsString('caractères au total', $html);
    }

    // ------------------------------------------------------------------
    // Une pièce
    // ------------------------------------------------------------------

    public function testChaquePieceSeDeplieEnEntier(): void
    {
        $text = WorkflowContentView::piece(['form' => 'text'], ['text' => "## Synthèse\n\nTrois **écoles** à rénover."]);
        $this->assertStringContainsString('<h2', $text);
        $this->assertStringContainsString('<strong>écoles</strong>', $text);

        $items = array_map(static fn(int $i): string => 'Piste ' . $i, range(1, WorkflowContentView::ITEMS_MAX + 3));
        $list = WorkflowContentView::piece(['form' => 'list'], ['items' => $items]);
        $this->assertSame(WorkflowContentView::ITEMS_MAX, substr_count($list, '<li>'));
        $this->assertStringContainsString('+ 3 autres éléments', $list);

        $table = WorkflowContentView::piece(['form' => 'table'], ['rows' => [['Client', 'Montant'], ['Lyon', '6 200'], ['<b>x</b>', '1']]]);
        $this->assertStringContainsString('<th>Client</th>', $table);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $table);

        $fiche = WorkflowContentView::piece(
            ['form' => 'fiche', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
                ['key' => 'lots', 'label' => 'Lots', 'type' => 'list'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
                ['key' => 'contenu', 'label' => 'Contenu', 'type' => 'longtext'],
            ]],
            ['fields' => ['client' => 'Ville de Lyon', 'lots' => ['Peinture', 'Toiture'], 'note' => null, 'contenu' => str_repeat('mot ', 300) . 'DERNIER-MOT']]
        );
        $this->assertStringContainsString('Ville de Lyon', $fiche);
        $this->assertStringContainsString('<li>Toiture</li>', $fiche);
        $this->assertStringContainsString('<dt>Note</dt><dd><span class="wf-view-empty">—</span>', $fiche, 'Un champ vide se voit vide.');
        $this->assertStringContainsString('DERNIER-MOT', $fiche, 'Un email reçu se lit jusqu’au bout.');

        $file = WorkflowContentView::piece(['form' => 'file'], ['name' => 'cctp.pdf', 'type' => 'application/pdf', 'url' => 'javascript:alert(1)', 'text' => 'Article 1 — objet du marché']);
        $this->assertStringContainsString('cctp.pdf', $file);
        $this->assertStringContainsString('Article 1', $file);
        $this->assertStringNotContainsString('javascript:', $file);
        $drive = WorkflowContentView::piece(['form' => 'file'], ['name' => 'x.docx', 'url' => 'https://drive.google.com/file/d/1', 'text' => '']);
        $this->assertStringContainsString('Ouvrir dans Drive', $drive);
    }

    public function testUneImageNeVientQueDeLApplication(): void
    {
        $ok = WorkflowContentView::piece(['form' => 'image'], ['url' => '/assets/img/generated/a.png', 'prompt' => 'Une école'], ['image' => '/assets/img/generated/a.png']);
        $this->assertStringContainsString('<img src="/assets/img/generated/a.png"', $ok);
        $this->assertStringContainsString('Une école', $ok);
        $gone = WorkflowContentView::piece(['form' => 'image'], ['url' => '/assets/img/generated/a.png'], ['image' => '']);
        $this->assertStringNotContainsString('<img', $gone);
        $this->assertStringContainsString('Image retirée', $gone);
        $remote = WorkflowContentView::piece(['form' => 'image'], [], ['image' => 'https://pisteur.example/p.gif']);
        $this->assertStringNotContainsString('<img', $remote);
    }

    public function testUnePageWebSeVoitDansUnCadreIsole(): void
    {
        $html = WorkflowContentView::piece(['form' => 'page'], ['title' => 'Offre', 'html' => '<script>alert(1)</script>'], [
            'download' => '/workflows/runs/9/pieces/site/download', 'conversation' => '/chat/40',
        ]);
        $this->assertStringContainsString('data-html-preview="/workflows/runs/9/pieces/site/download?format=html"', $html);
        $this->assertStringContainsString('<iframe sandbox="allow-scripts" referrerpolicy="no-referrer"', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html, 'Origine opaque : la page ne touche ni à la session ni à l’application.');
        $this->assertStringNotContainsString('alert(1)', $html, 'La page n’est jamais insérée telle quelle.');
        $this->assertStringContainsString('href="/chat/40"', $html);
        $this->assertStringContainsString('data-preview-error hidden', $html);
    }

    public function testLeContenuSeLitEnAnglais(): void
    {
        I18n::use('en');
        $html = WorkflowContentView::action(self::mail());
        $this->assertStringContainsString('<dt>To</dt>', $html);
        $this->assertStringContainsString('<dt>Subject</dt>', $html);
        $this->assertStringContainsString('Open in Gmail', $html);
        $this->assertSame('View the email', WorkflowContentView::actionLabel('creer_brouillon_outlook'));
    }

    public function testChaqueLibelleDEnTeteEstTraduit(): void
    {
        $en = require ROOT . '/lang/en.php';
        foreach (WorkflowContentView::HEADER_ARGS as $label) {
            $this->assertArrayHasKey($label, $en, "Libellé « $label » absent du dictionnaire anglais (clé calculée, le test de couverture ne la voit pas).");
        }
    }

    // ------------------------------------------------------------------
    // Ce qu'il est advenu d'un email
    // ------------------------------------------------------------------

    public function testUnEmailDitSIlEstPartiOuResteEnBrouillon(): void
    {
        $line = static fn(array $c): array => WorkflowJournal::cardLine($c + ['status' => 'executed', 'preview' => ['action' => 'Nouvel email Gmail'], 'tool' => 'creer_brouillon_gmail']);
        $this->assertSame('Envoyé sans validation', $line(['outcome' => 'sent', 'auto' => true])['state']);
        $this->assertSame('Envoyé', $line(['outcome' => 'sent', 'auto' => false])['state']);
        $this->assertSame('Brouillon créé', $line(['outcome' => 'draft'])['state']);
        $this->assertSame('Brouillon créé sans validation', $line(['outcome' => 'draft', 'auto' => true])['state']);
        $this->assertSame('Approuvée et faite', $line(['outcome' => null])['state'], 'Une action d’avant la colonne garde son libellé.');
        $this->assertSame('Voir l’email', $line([])['view_label']);
    }

    public function testUneLivraisonFaiteSansCarteGardeSonEmail(): void
    {
        $cards = [70 => self::mail()];
        $lines = WorkflowJournal::deliveryLines([['status' => 'done', 'label' => 'Email à achats@ecoles.fr', 'action_ids' => [70]]], $cards, []);
        $this->assertSame('Envoyé sans validation', $lines[0]['state']);
        $this->assertCount(1, $lines[0]['cards']);
        $this->assertStringContainsString('École Jaurès', $lines[0]['cards'][0]['view']);
        // Livraison d'avant (aucune action citée) : rien ne casse.
        $old = WorkflowJournal::deliveryLines([['status' => 'done', 'label' => 'Email']], $cards, []);
        $this->assertSame('Livrée', $old[0]['state']);
        $this->assertSame([], $old[0]['cards']);
    }

    public function testLeLienDeResultatNeGardeQueDuHttp(): void
    {
        $this->assertSame('https://x.fr/a', DrivePendingAction::resultUrl(' https://x.fr/a '));
        $this->assertNull(DrivePendingAction::resultUrl('javascript:alert(1)'));
        $this->assertNull(DrivePendingAction::resultUrl("https://x.fr/a\nb"));
        $this->assertNull(DrivePendingAction::resultUrl('https://x.fr/"><script>'));
        $this->assertNull(DrivePendingAction::resultUrl(''));
        $this->assertNull(DrivePendingAction::resultUrl('https://x.fr/' . str_repeat('a', 1000)));
    }

    public function testLeJournalDeplieLEmailDUneActionEtTouteLaDemande(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'besoin', 'label' => 'Besoin', 'type' => 'longtext'],
                ['key' => 'cctp', 'label' => 'CCTP', 'type' => 'file'],
            ]],
            'nodes' => [
                ['key' => 'envoi', 'type' => 'action', 'title' => 'Répondre au client', 'target' => 'gmail', 'instruction' => 'Réponds à @{depart}.', 'write' => 'auto', 'auto_send_mail' => true],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $run = [
            'id' => 9, 'workflow_id' => 3, 'user_id' => 7, 'conversation_id' => 40, 'version' => 1, 'trigger_kind' => 'manual',
            'status' => 'done', 'current_key' => 'end', 'definition' => $def, 'inputs' => [], 'error' => null,
            'timezone' => 'Europe/Paris', 'parent_run_id' => null, 'started_at' => '2026-10-16 08:00:00', 'finished_at' => '2026-10-16 08:01:00',
            'updated_at' => '2026-10-16 08:01:00', 'timer_at' => null,
        ];
        $step = static fn(int $id, string $key, array $detail = []): array => [
            'id' => $id, 'run_id' => 9, 'node_key' => $key, 'attempt' => 1, 'status' => 'done', 'outcome' => 'done', 'job_id' => null,
            'message_id' => $detail['reply_id'] ?? null, 'pending_action_id' => null, 'resume_at' => null, 'detail' => $detail,
            'started_at' => '2026-10-16 08:00:00', 'finished_at' => '2026-10-16 08:00:30',
        ];
        $long = str_repeat('Rénover trois écoles avant l’été. ', 20) . 'FIN-DU-BESOIN';
        $ctx = [
            'artifacts' => [
                'depart' => ['key' => 'depart', 'node_key' => 'start', 'name' => 'Demande', 'form' => 'fiche', 'content' => ['fields' => ['client' => 'Ville de Lyon', 'besoin' => $long]], 'version' => 1],
                'cctp' => ['key' => 'cctp', 'node_key' => 'start', 'name' => 'CCTP', 'form' => 'file', 'content' => ['name' => 'cctp.pdf', 'type' => 'application/pdf', 'text' => 'Article 1 — objet du marché'], 'version' => 1],
            ],
            'actions' => [],
            'cards' => [70 => self::mail()],
            'profiles' => [], 'llms' => [],
        ];
        $e = WorkflowJournal::entries($run, [$step(1, 'start'), $step(2, 'envoi', ['reply_id' => 50]), $step(3, 'end')], $ctx, new \DateTimeImmutable('2026-10-16 12:00:00'));

        // Le départ : le champ long coupé en ligne, entier à déplier ; le fichier reçu, lisible.
        $this->assertNotNull($e[0]['more']);
        $this->assertStringContainsString('FIN-DU-BESOIN', $e[0]['more']['html']);
        $this->assertSame('cctp.pdf', $e[0]['files'][0]['summary']);
        $this->assertStringContainsString('Article 1', $e[0]['files'][0]['view']);

        // L'action : l'email parti, en entier.
        $card = $e[1]['cards'][0];
        $this->assertSame('Envoyé sans validation', $card['state']);
        $this->assertSame('Voir l’email', $card['view_label']);
        $this->assertStringContainsString('achats@ecoles.fr', $card['view']);
        $this->assertStringContainsString('École Jaurès', $card['view']);

        // Rendu de l'historique : les dépliants sont là.
        $icons = WorkflowCatalog::icons();
        $head = WorkflowJournal::head($run, [], 1, 1, 0, new \DateTimeImmutable('2026-10-16 12:00:00'));
        $entries = $e;
        ob_start();
        require ROOT . '/templates/workflows/partials/history-journal.php';
        $html = (string)ob_get_clean();
        $this->assertStringContainsString('data-view="card:70"', $html);
        $this->assertStringContainsString('Voir l’email', $html);
        $this->assertStringContainsString('data-view="piece:cctp"', $html);
        $this->assertStringContainsString('Voir tout le départ', $html);
        $this->assertStringContainsString('<strong>devis</strong>', $html);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLEnvoiEtLeBrouillonSontConsignes(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString("DrivePendingAction::resolve(\$actionId, 'executed', null, DrivePendingAction::OUTCOME_SENT, (string)(\$result['url'] ?? ''));", $chat);
        $this->assertStringContainsString("DrivePendingAction::resolve(\$actionId, 'executed', null, DrivePendingAction::OUTCOME_DRAFT, (string)(\$result['url'] ?? ''));", $chat);
        $migration = self::src('database/migrations/092_pending_action_outcome.sql');
        $this->assertStringContainsString('ADD COLUMN outcome', $migration);
        $this->assertStringContainsString('ADD COLUMN result_url', $migration);
        $model = self::src('src/Models/DrivePendingAction.php');
        $journal = substr($model, (int)strpos($model, 'function journalForConversation'));
        $this->assertStringContainsString('args_json', $journal, 'Le contenu entier vient des arguments.');
        $this->assertStringContainsString('outcome, result_url', $journal);
        $deliveries = self::src('src/Workflow/WorkflowDeliveries.php');
        // Email et Slack faits sans carte, Drive aussi : l'action qui les a portés reste citée.
        $this->assertSame(2, substr_count($deliveries, "+ ['action_ids' => [\$actionId]]"));
        $this->assertStringContainsString("return ['status' => 'done', 'action_ids' => \$ids];", $deliveries);
    }

    public function testLHistoriqueEtLExecutionDeplientLeContenu(): void
    {
        $tpl = self::src('templates/workflows/partials/history-journal.php');
        $this->assertStringContainsString('class="wf-tl-view" data-view="', $tpl);
        $this->assertStringContainsString("\$viewBox(\$c['view'], \$c['view_label'], 'card:' . \$c['id'])", $tpl);
        $this->assertStringContainsString("\$p['view']", $tpl);
        $this->assertStringContainsString("\$e['files']", $tpl);
        $hist = self::src('public/assets/js/workflow-history.js');
        $this->assertStringContainsString("getAttribute('data-view')", $hist, 'Un dépliant ouvert le reste quand le journal se met à jour.');
        $this->assertStringContainsString('MiaHtmlPreview.scan(journal)', $hist);
        $run = self::src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString('function setHtml(el, html)', $run);
        $this->assertStringContainsString('setHtml(banner, decision())', $run);
        $this->assertStringContainsString('cardList(st.cards)', $run);
        $this->assertStringContainsString("viewBox(it.view, it.view_label, 'card:' + it.id)", $run, 'La relecture groupée montre l’email entier.');
        $this->assertStringContainsString('data-html-preview', $run);
        $ctrl = self::src('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString("\$entry['cards'] = array_map(static fn(array \$c): array => WorkflowJournal::cardLine(\$c, \$names, \$images), \$byMessage[\$reply]);", $ctrl);
        $this->assertStringNotContainsString('DrivePendingAction::pendingForConversation((int)$run', $ctrl, 'La relecture groupée lit les arguments (journalForConversation).');
        foreach (['templates/workflows/index.php', 'templates/workflows/run.php'] as $page) {
            $this->assertStringContainsString('/assets/js/html-preview.js', self::src($page), $page);
        }
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('WorkflowContentView.php', self::src($f), $f);
        }
        $css = self::src('public/assets/css/workflows.css');
        foreach (['.wf-tl-view', '.wf-view-mail', '.wf-view-page', '.wf-view-meta'] as $sel) {
            $this->assertStringContainsString($sel, $css);
        }
    }

    // ------------------------------------------------------------------
    // L'aperçu d'une page (node)
    // ------------------------------------------------------------------

    public function testLAperçuDUnePagePoseSesGardesEtNeLitQueLApplication(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $script = <<<'JS'
const fetched = [];
globalThis.window = globalThis;
globalThis.location = { origin: 'https://mia.exemple' };
let toggle = null;
globalThis.document = { addEventListener: (t, f) => { if (t === 'toggle') { toggle = f; } }, querySelectorAll: () => [] };
globalThis.fetch = (u) => { fetched.push(u); return new Promise(() => {}); };
JS;
        $script .= "\n" . self::src('public/assets/js/html-preview.js') . "\n";
        $script .= <<<'JS'
const g = window.MiaHtmlPreview.guard;
const box = (src) => {
  const attrs = { 'data-html-preview': src };
  return {
    classList: { add() {}, remove() {} },
    getAttribute: (n) => attrs[n] || null, setAttribute: (n, v) => { attrs[n] = v; }, removeAttribute: (n) => { delete attrs[n]; },
    querySelector: (s) => s === 'iframe' ? {} : null, closest: () => null,
  };
};
const root = (boxes) => ({ querySelectorAll: () => boxes });
window.MiaHtmlPreview.scan(root([box('//pisteur.example/x'), box('https://pisteur.example/x'), box('/workflows/runs/9/pieces/site/download?format=html')]));
const full = g('<!DOCTYPE html><html lang="fr"><head><script>evil()</script></head><body>x</body></html>');
process.stdout.write(JSON.stringify({
  fetched,
  toggle: typeof toggle,
  bare: g('<p>Bonjour</p>'),
  full,
  cspFirst: full.indexOf('Content-Security-Policy') < full.indexOf('evil()'),
  nohead: g('<html><body>y</body></html>'),
  empty: g(''),
  dollar: g('<html><head></head><body>$& $1</body></html>'),
}));
JS;
        $fichier = tempnam(sys_get_temp_dir(), 'mia-preview-') . '.js';
        file_put_contents($fichier, $script);
        try {
            $sortie = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $r = json_decode($sortie, true);
        $this->assertIsArray($r, 'Sortie node illisible : ' . $sortie);
        $this->assertSame(['/workflows/runs/9/pieces/site/download?format=html'], $r['fetched'], 'Seule une adresse de l’application est lue.');
        $this->assertSame('function', $r['toggle'], 'L’ouverture d’un dépliant monte ses aperçus.');
        foreach (['bare', 'full', 'nohead', 'empty'] as $k) {
            $this->assertStringContainsString("connect-src 'none'", $r[$k], $k);
            $this->assertStringContainsString("form-action 'none'", $r[$k], $k);
            $this->assertStringContainsString('myllm-preview-guard', $r[$k], $k);
        }
        $this->assertTrue($r['cspFirst'], 'La CSP précède tout script de la page.');
        $this->assertStringContainsString('<body><p>Bonjour</p></body>', $r['bare']);
        $this->assertStringContainsString('$& $1', $r['dollar'], 'Les motifs de remplacement ne sont pas interprétés.');
        // Les mêmes gardes que l'aperçu du chat.
        $chat = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString("connect-src 'none'; form-action 'none'; base-uri 'none'", $chat);
        $this->assertStringContainsString('html-preview.js', $chat, 'La note de synchronisation de chat.js cite la réplique.');
    }

    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }
}
