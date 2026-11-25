<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Déclencheur « email reçu » : filtres appliqués par MIA au message
 * (WorkflowFilters::emailMatches) et notifications instantanées (MailPush) —
 * formes pures et câblage. Le parcours sur la vraie base est dans
 * tests/Functional/MailPushPipelineTest.php.
 */
final class MailPushTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    private static function msg(array $over = []): array
    {
        return $over + [
            'de' => 'Paul Dupont <paul@client.fr>',
            'a' => 'Factures <factures@acme.fr>, Marie <marie@acme.fr>',
            'cc' => '"Martin, Léa" <lea@acme.fr>',
            'objet' => 'Demande de DEVIS pour l’école',
            'contenu' => 'Bonjour, c’est urgent : merci de rappeler.',
            'pieces_jointes' => [['nom' => 'devis.pdf']],
        ];
    }

    // ------------------------------------------------------------------
    // Filtres
    // ------------------------------------------------------------------

    public function testDeAccepteAdresseDomaineEtNom(): void
    {
        $m = self::msg();
        foreach (['paul@client.fr', 'PAUL@client.fr', '@client.fr', 'client.fr', 'Dupont', 'dupont', 'autre@x.fr, @client.fr', 'Paul <paul@client.fr>'] as $from) {
            $this->assertTrue(WorkflowFilters::emailMatches(['from' => $from], $m), "« $from » désigne l’expéditeur.");
        }
        foreach (['paul@client.com', '@lient.fr', 'aul@client.fr', 'Durand', 'client.com'] as $from) {
            $this->assertFalse(WorkflowFilters::emailMatches(['from' => $from], $m), "« $from » ne le désigne pas.");
        }
        $this->assertTrue(WorkflowFilters::emailMatches(['from' => 'client.fr'], self::msg(['de' => 'Service <noreply@mail.client.fr>'])), 'Sous-domaine compris.');
        $this->assertFalse(WorkflowFilters::emailMatches(['from' => 'client.fr'], self::msg(['de' => 'Pirate <x@client.fr.evil.com>'])), 'Un domaine se lit par la fin.');
    }

    public function testSaufDeEcarte(): void
    {
        $this->assertFalse(WorkflowFilters::emailMatches(['exclude' => 'noreply, @client.fr'], self::msg()));
        $this->assertFalse(WorkflowFilters::emailMatches(['exclude' => 'noreply'], self::msg(['de' => 'Banque <noreply@banque.fr>'])));
        $this->assertTrue(WorkflowFilters::emailMatches(['exclude' => 'noreply'], self::msg()));
        $this->assertFalse(WorkflowFilters::emailMatches(['from' => '@client.fr', 'exclude' => 'paul@client.fr'], self::msg()), 'Sauf de l’emporte.');
    }

    public function testAdresseAVoitLesDestinatairesEtLaCopie(): void
    {
        $m = self::msg();
        $this->assertTrue(WorkflowFilters::emailMatches(['to' => 'factures@acme.fr'], $m));
        $this->assertTrue(WorkflowFilters::emailMatches(['to' => 'lea@acme.fr'], $m), 'La copie compte, et une virgule dans un nom ne coupe pas.');
        $this->assertTrue(WorkflowFilters::emailMatches(['to' => '@acme.fr'], $m));
        $this->assertFalse(WorkflowFilters::emailMatches(['to' => 'compta@acme.fr'], $m));
    }

    public function testObjetEtMots(): void
    {
        $m = self::msg();
        $this->assertTrue(WorkflowFilters::emailMatches(['subject' => 'devis'], $m), 'Casse ignorée.');
        $this->assertTrue(WorkflowFilters::emailMatches(['subject' => 'ecole devis'], $m), 'Tous les mots, accents ignorés.');
        $this->assertFalse(WorkflowFilters::emailMatches(['subject' => 'devis facture'], $m), 'Un mot manque.');
        $this->assertTrue(WorkflowFilters::emailMatches(['subject' => 'facture, devis'], $m), 'Virgule = l’une ou l’autre.');
        $this->assertTrue(WorkflowFilters::emailMatches(['words' => 'urgent'], $m), 'Dans le corps.');
        $this->assertTrue(WorkflowFilters::emailMatches(['words' => 'ecole'], $m), 'Ou dans l’objet.');
        $this->assertFalse(WorkflowFilters::emailMatches(['words' => 'résiliation'], $m));
    }

    public function testPieceJointeSansLesImagesDuCorps(): void
    {
        $this->assertTrue(WorkflowFilters::emailMatches(['attachment' => true], self::msg()));
        $this->assertFalse(WorkflowFilters::emailMatches(['attachment' => true], self::msg(['pieces_jointes' => [['nom' => 'logo.png', 'en_ligne' => true]]])));
        $this->assertFalse(WorkflowFilters::emailMatches(['attachment' => true], ['de' => 'a@b.fr', 'objet' => 'x']), 'Message entier sans pièce jointe.');
        $this->assertTrue(WorkflowFilters::emailMatches(['attachment' => true], ['pieces' => true], false), 'Outlook l’annonce dans les en-têtes.');
    }

    public function testSeuleUnePetiteImageEnLigneEstCollee(): void
    {
        $this->assertTrue(MailAttachments::pastedImage(true, 'image/png', 'logo.png', 4000), 'Logo de signature.');
        $this->assertFalse(MailAttachments::pastedImage(true, 'application/pdf', 'devis.pdf', 84000), 'Apple Mail joint les PDF en ligne.');
        $this->assertFalse(MailAttachments::pastedImage(true, 'image/jpeg', 'IMG_0042.jpg', 2400000), 'Une photo jointe depuis l’iPhone.');
        $this->assertFalse(MailAttachments::pastedImage(false, 'image/png', 'logo.png', 4000), 'Pas en ligne : une pièce jointe.');
        $this->assertTrue(MailAttachments::pastedImage(true, 'application/octet-stream', 'sig.PNG', 0), 'Une image se reconnaît aussi à son nom.');
        $graph = MailAttachments::fromGraphList([
            ['id' => 'a', 'name' => 'devis.pdf', 'contentType' => 'application/pdf', 'size' => 90000, 'isInline' => true],
            ['id' => 'b', 'name' => 'image001.png', 'contentType' => 'image/png', 'size' => 3000, 'isInline' => true],
        ]);
        $this->assertSame([false, true], array_column($graph, 'en_ligne'));
        $this->assertStringContainsString("'&labelIds=INBOX'", self::src('src/Gmail/UserGmailService.php'),
            'La boîte de réception par son libellé : la recherche Gmail retarde sur un email tout juste arrivé.');
    }

    public function testEnTetesSeulsNeJugentQueCeQuIlsPortent(): void
    {
        $meta = ['de' => 'Paul <paul@client.fr>', 'objet' => 'Bonjour'];
        $filters = ['from' => 'client.fr', 'words' => 'urgent', 'attachment' => true];
        $this->assertTrue(WorkflowFilters::emailMatches($filters, $meta, false), 'Corps et pièces jointes jugés une fois le message lu.');
        $this->assertFalse(WorkflowFilters::emailMatches(['from' => 'autre.fr'] + $filters, $meta, false), 'L’expéditeur se juge tout de suite.');
        $this->assertFalse(WorkflowFilters::emailMatches($filters, $meta + ['contenu' => 'rien'], true));
        $this->assertTrue(WorkflowFilters::emailMatches([], []), 'Sans filtre, tout email.');
    }

    public function testLEcartDitPourquoi(): void
    {
        $m = self::msg();
        $this->assertNull(WorkflowFilters::emailMismatch(['from' => '@client.fr', 'subject' => 'devis'], $m));
        $this->assertSame('from', WorkflowFilters::emailMismatch(['from' => '@autre.fr'], $m));
        $this->assertSame('exclude', WorkflowFilters::emailMismatch(['exclude' => 'paul'], $m));
        $this->assertSame('to', WorkflowFilters::emailMismatch(['to' => 'compta@acme.fr'], $m));
        $this->assertSame('subject', WorkflowFilters::emailMismatch(['subject' => 'facture'], $m));
        $this->assertSame('words', WorkflowFilters::emailMismatch(['words' => 'résiliation'], $m));
        $this->assertSame('attachment', WorkflowFilters::emailMismatch(['attachment' => true], self::msg(['pieces_jointes' => []])));
        $this->assertSame('objet sans « facture »', WorkflowFilters::mismatchLabel('subject', ['subject' => 'facture']));
        $this->assertSame('expéditeur écarté par « noreply »', WorkflowFilters::mismatchLabel('exclude', ['exclude' => 'noreply']));
        // Le message entier n'est lu que si les filtres le demandent.
        $this->assertFalse(WorkflowFilters::needsFullMessage(['from' => 'x', 'subject' => 'y'], []));
        $this->assertTrue(WorkflowFilters::needsFullMessage(['words' => 'urgent'], []));
        $this->assertTrue(WorkflowFilters::needsFullMessage(['attachment' => true], []));
        $this->assertFalse(WorkflowFilters::needsFullMessage(['attachment' => true], ['pieces' => true]), 'Outlook le dit dans les en-têtes.');
    }

    public function testUneLectureIncompleteNEffacePasLesEnTetes(): void
    {
        $m = WorkflowTriggers::mergeMessage(['de' => '', 'objet' => null, 'contenu' => 'Corps'], ['de' => 'Paul <paul@client.fr>', 'objet' => 'Devis', 'cc' => 'x@y.fr']);
        $this->assertSame(['de' => 'Paul <paul@client.fr>', 'objet' => 'Devis', 'contenu' => 'Corps', 'cc' => 'x@y.fr'], $m);
        $this->assertTrue(WorkflowFilters::emailMatches(['from' => '@client.fr', 'words' => 'corps'], $m));
        $this->assertSame('Lu', WorkflowTriggers::mergeMessage(['objet' => 'Lu'], ['objet' => 'En-tête'])['objet'], 'Une valeur lue l’emporte.');
    }

    public function testLeResultatDeLEssaiEstEchappeEtCompte(): void
    {
        $html = WorkflowTriggers::filterTestHtml(['items' => [
            ['de' => 'Paul <paul@client.fr>', 'objet' => '<b>Devis</b>', 'received' => 1793700000, 'match' => true, 'reason' => ''],
            ['de' => 'noreply@x.fr', 'objet' => '', 'received' => null, 'match' => false, 'reason' => 'expéditeur écarté par « noreply »'],
        ], 'matched' => 1], 'Gmail', 'Europe/Paris');
        $this->assertStringContainsString('&lt;b&gt;Devis&lt;/b&gt;', $html, 'Un objet d’email est une donnée : échappé.');
        $this->assertStringNotContainsString('<b>Devis</b>', $html);
        $this->assertStringContainsString('1 des 2 derniers emails correspond aux filtres.', $html);
        $this->assertStringContainsString('is-match', $html);
        $this->assertStringContainsString('(sans objet)', $html);
        $this->assertStringContainsString('lancerait le workflow', $html);
        $this->assertStringContainsString('Rien n’est lancé par cet essai.', $html);
        $this->assertStringContainsString('Aucun email', WorkflowTriggers::filterTestHtml(['items' => [], 'matched' => 0], 'Outlook', ''));
        $this->assertStringContainsString('Aucun des 3 derniers emails', WorkflowTriggers::filterTestHtml(['items' => array_fill(0, 3, ['de' => 'a@b.fr', 'objet' => 'x', 'received' => null, 'match' => false, 'reason' => 'r']), 'matched' => 0], 'Gmail', ''));
    }

    public function testLEssaiEtLesAidesDeLEditeur(): void
    {
        $index = self::src('public/index.php');
        $this->assertStringContainsString("\$router->post('/workflows/{id}/email-test',", $index);
        $ctl = self::src('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('public function testEmailFilters(string $id): void', $ctl);
        $this->assertStringContainsString("\$this->requireUser('edit');", $ctl);
        $ed = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('data-act="ftest"', $ed);
        $this->assertStringContainsString("case 'ftest': runFilterTest(b); return;", $ed);
        $this->assertStringContainsString("'/workflows/' + state.workflow.id + '/email-test'", $ed);
        $this->assertStringContainsString('function hintDot(label, hint, examples)', $ed);
        $this->assertStringContainsString("bubble.className = 'wf-bubble';", $ed, 'Une bulle unique posée sur la page.');
        $this->assertStringContainsString('window.miaUiZoom', $ed);
        // Placeholders COURTS : la règle et les exemples vont dans l'infobulle.
        preg_match_all("/fin\\('(\\w+)', T\\('[^']*'\\), (?:T\\()?'([^']*)'/u", $ed, $m, PREG_SET_ORDER);
        $this->assertCount(5, $m, 'Les cinq champs du filtre.');
        foreach ($m as [$_, $key, $ph]) {
            $this->assertLessThanOrEqual(12, mb_strlen($ph), "Le placeholder de « $key » doit tenir dans le champ.");
        }
        $this->assertStringContainsString('.wf-bubble { position: fixed;', self::src('public/assets/css/workflows.css'));
    }

    public function testLesNouveauxFiltresVivantDansLaForme(): void
    {
        $f = WorkflowFilters::normalize(['mail' => ['exclude' => ' noreply ', 'to' => 'a@b.fr']]);
        $this->assertSame('noreply', $f['mail']['exclude']);
        $this->assertFalse(WorkflowFilters::mailIsEmpty($f));
        $this->assertStringContainsString('sauf de noreply', WorkflowFilters::summary($f));
        $this->assertStringContainsString('adressés à a@b.fr', WorkflowFilters::summary($f));

        $def = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'email', 'source' => 'outlook',
            'filters' => ['mail' => ['from' => '@client.fr', 'exclude' => 'noreply @{x}', 'to' => 'factures@acme.fr', 'words' => 'urgent', 'unread' => true, 'days' => 3]]]]]);
        $mail = $def['start']['event']['filters']['mail'];
        $this->assertSame('factures@acme.fr', $mail['to']);
        $this->assertSame('urgent', $mail['words']);
        $this->assertStringNotContainsString('@{', $mail['exclude'], 'Un déclencheur ne cite pas le dossier : il n’existe pas encore.');
        $this->assertFalse($mail['unread']);
        $this->assertSame(0, $mail['days']);
    }

    public function testLEmpreinteDUneSurveillanceNeBougePasPourDesFiltresVides(): void
    {
        $ancien = ['kind' => 'email', 'source' => 'gmail', 'query' => '', 'filters' => ['mail' => [
            'from' => 'paul@client.fr', 'subject' => '', 'words' => '', 'attachment' => false, 'unread' => false, 'days' => 0,
        ], 'drive' => ['folder' => '', 'name' => '', 'kind' => '', 'days' => 0], 'recipients' => '']];
        $neuf = $ancien;
        $neuf['filters']['mail'] += ['exclude' => '', 'to' => ''];
        $this->assertSame(WorkflowTriggers::watchSignature($ancien), WorkflowTriggers::watchSignature($neuf),
            'Sinon chaque surveillance reposerait sa ligne de base au déploiement.');
        $neuf['filters']['mail']['exclude'] = 'noreply';
        $this->assertNotSame(WorkflowTriggers::watchSignature($ancien), WorkflowTriggers::watchSignature($neuf));
    }

    public function testDecrireAMiaPorteLesFiltres(): void
    {
        $args = ['nom' => 'Devis', 'depart' => ['evenement' => ['type' => 'email', 'source' => 'outlook', 'de' => '@client.fr',
            'sauf_de' => 'noreply', 'a' => 'devis@acme.fr', 'objet' => 'devis', 'mots' => 'urgent', 'piece_jointe' => true]],
            'briques' => [['type' => 'etape', 'titre' => 'Lire', 'consigne' => 'Lis [Email reçu].', 'piece' => ['nom' => 'Note', 'forme' => 'texte']]]];
        $src = static fn(string $label): array => ['label' => $label, 'connected' => true];
        $d = WorkflowDraft::fromArgs($args, ['profiles' => [], 'llms' => [], 'tool_llms' => [], 'default_llm' => '', 'sources' => ['outlook' => $src('Outlook')], 'kbs' => [], 'tools' => [], 'auto' => true]);
        $mail = $d['definition']['start']['event']['filters']['mail'];
        $this->assertSame(['@client.fr', 'noreply', 'devis@acme.fr', 'devis', 'urgent', true],
            [$mail['from'], $mail['exclude'], $mail['to'], $mail['subject'], $mail['words'], $mail['attachment']]);
        $this->assertStringContainsString('"sauf_de"', WorkflowDraft::schemaGuide([]));
    }

    // ------------------------------------------------------------------
    // Notifications instantanées
    // ------------------------------------------------------------------

    public function testLAdresseDoitEtrePubliqueEnHttps(): void
    {
        $this->assertSame('https://mia.client.fr', MailPush::publicBaseFrom('https://mia.client.fr/'));
        $this->assertSame('https://mia.client.fr:8443/mia', MailPush::publicBaseFrom('https://mia.client.fr:8443/mia/'));
        foreach (['http://mia.client.fr', 'https://localhost', 'https://mia.localhost', 'https://app', 'https://10.0.0.4', 'https://mia.local', 'https://[::1]', '', 'ftp://x.fr'] as $url) {
            $this->assertNull(MailPush::publicBaseFrom($url), $url);
        }
    }

    public function testTopicEtJetonGmail(): void
    {
        $this->assertTrue(MailPush::validTopic('projects/mon-projet-12/topics/mia-gmail'));
        foreach (['mia-gmail', 'projects/X/topics/t', 'projects/mon-projet/topics/', 'projects/mon-projet/subscriptions/abc', 'projects/mon projet/topics/abc'] as $t) {
            $this->assertFalse(MailPush::validTopic($t), $t);
        }
        $this->assertTrue(MailPush::validGmailToken('mgp_' . str_repeat('a', 43)));
        $this->assertFalse(MailPush::validGmailToken('mgp_court'));
    }

    public function testLaNotificationPubSubDitLAdresse(): void
    {
        $data = rtrim(strtr(base64_encode('{"emailAddress": "Paul@Client.fr", "historyId": "9876543210"}'), '+/', '-_'), '=');
        $this->assertSame('paul@client.fr', MailPush::gmailAddressFrom(json_encode(['message' => ['data' => $data, 'messageId' => '1'], 'subscription' => 'projects/p/subscriptions/s'])));
        $this->assertSame('', MailPush::gmailAddressFrom('{"message":{"data":"!!!"}}'));
        $this->assertSame('', MailPush::gmailAddressFrom('pas du json'));
        $bad = rtrim(strtr(base64_encode('{"emailAddress": "pas une adresse"}'), '+/', '-_'), '=');
        $this->assertSame('', MailPush::gmailAddressFrom(json_encode(['message' => ['data' => $bad]])));
    }

    public function testUnAbonnementEnService(): void
    {
        $now = new \DateTimeImmutable('2026-11-03 10:00:00', new \DateTimeZone('UTC'));
        $row = ['provider' => 'outlook', 'status' => 'active', 'external_id' => 'sub-1', 'expires_at' => '2026-11-04 10:00:00'];
        $this->assertTrue(MailPush::rowIsLive($row, $now));
        $this->assertFalse(MailPush::rowIsLive(['expires_at' => '2026-11-03 09:00:00'] + $row, $now), 'Expiré.');
        $this->assertTrue(MailPush::rowIsLive(['expires_at' => null] + $row, $now), 'Réautorisation demandée : en service le temps du renouvellement.');
        $this->assertFalse(MailPush::rowIsLive(['status' => 'error'] + $row, $now));
        $this->assertFalse(MailPush::rowIsLive(null, $now));
    }

    public function testUneVerificationAlaFoisParWorkflow(): void
    {
        $this->assertSame('wfwatch:12', JobQueue::laneFor('workflow', null, ['op' => 'watch', 'workflow_id' => 12]));
        $this->assertNull(JobQueue::laneFor('workflow', null, ['op' => 'mail_push_sync']));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLesNotificationsSontTraiteesAvantLaSession(): void
    {
        $index = self::src('public/index.php');
        $session = strpos($index, 'Session::start();');
        foreach (["\$requestPath === '/hooks/mail/outlook'", "'#^/hooks/mail/gmail/([A-Za-z0-9_-]{1,80})$#'"] as $route) {
            $at = strpos($index, $route);
            $this->assertNotFalse($at, $route);
            $this->assertLessThan($session, $at, 'Ni session ni CSRF : Microsoft et Google n’en ont pas.');
        }
        $ctl = self::src('src/Controllers/MailPushHookController.php');
        $this->assertLessThan(strpos($ctl, 'MailPush::wake'), strpos($ctl, 'self::reply('), 'Graph exige une réponse sous 3 secondes : on répond AVANT de réveiller.');
        $this->assertStringContainsString("'/admin/tools/mail-push'", $index);
        $this->assertLessThan(strpos($index, "'/admin/tools/{id}'"), strpos($index, "'/admin/tools/mail-push'"), 'Avant {id}.');
    }

    public function testLeConnecteurOutlookNeSupprimeToujoursRien(): void
    {
        // Les abonnements (DELETE, PATCH) vivent à part : le connecteur de messagerie garde sa promesse.
        $push = self::src('src/Outlook/UserOutlookPushService.php');
        $this->assertStringContainsString('final class UserOutlookPushService extends UserOutlookService', $push);
        $this->assertStringContainsString("'DELETE', self::GRAPH_BASE . '/subscriptions/'", $push);
        $this->assertStringNotContainsString('/messages/', $push, 'Jamais un message : des abonnements seulement.');
        $this->assertStringContainsString('UserOutlookPushService::forUser', self::src('src/Workflow/MailPush.php'));
    }

    public function testChargementEtSauvegarde(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $s = self::src($f);
            $this->assertLessThan(strpos($s, 'Workflow/MailPush.php'), strpos($s, 'Models/MailPushSubscription.php'), $f);
            $this->assertNotFalse(strpos($s, 'Outlook/UserOutlookPushService.php'), $f);
        }
        $this->assertContains('mail_push_subscriptions', BackupService::EXCLUDED_TABLES);
        $this->assertContains(MailPush::SETTING_SYNC_AT, BackupService::EXCLUDED_SETTINGS_KEYS);
        $this->assertContains(MailPush::SETTING_GMAIL_TOKEN, BackupService::EXCLUDED_SETTINGS_KEYS);
        $this->assertStringContainsString('CREATE TABLE mail_push_subscriptions', self::src('database/migrations/101_mail_push.sql'));
        $this->assertStringContainsString("'mail_push_sync' => MailPush::runSyncJob(\$payload)", self::src('src/Jobs/WorkflowJob.php'));
        $this->assertStringContainsString('MailPush::tick($now, $dispatch);', self::src('src/Workflow/WorkflowEngine.php'));
        $ctl = self::src('src/Controllers/WorkflowController.php');
        $this->assertSame(3, substr_count($ctl, 'MailPush::syncSoon((int)$user[\'id\']);'), 'Publier, mettre en pause, supprimer.');
    }

    public function testEcrans(): void
    {
        $tools = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(MailPush::SETTING_ENABLED) ?>"', $tools);
        $this->assertStringContainsString('action="/admin/tools/mail-push"', $tools);
        $this->assertStringContainsString('name="gmail_push_topic"', $tools);
        $this->assertStringContainsString('gmail-api-push@system.gserviceaccount.com', $tools);

        $ed = self::src('public/assets/js/workflow-editor.js');
        foreach (["fin('exclude'", "fin('to'", "fin('words'", "fin('subject'", "fin('from'"] as $champ) {
            $this->assertStringContainsString($champ, $ed);
        }
        $this->assertStringContainsString("exclude: '', to: ''", $ed, 'Filtres vierges complets.');
        $this->assertStringContainsString('watch.push.text', $ed, 'L’éditeur dit si la boîte prévient MIA.');
        $this->assertStringContainsString("'push' => (\$w['published']['start']['event']['kind'] ?? '') === 'email'", self::src('src/Controllers/WorkflowController.php'));
    }

    public function testDocumentation(): void
    {
        $this->assertStringContainsString('notifications instantanées', mb_strtolower(self::src('docs/user/fonctionnalites/workflows/demarrer-sur-un-evenement.md')));
        $this->assertStringContainsString('Pub/Sub', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('id: workflow-email-instantane', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('MailPush', self::src('CLAUDE.md'));
    }
}
