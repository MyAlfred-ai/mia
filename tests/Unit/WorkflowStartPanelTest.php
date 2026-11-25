<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Le volet du Départ d'un workflow : « Quand démarre-t-il ? ».
 *
 * Le problème résolu : le déclencheur se réglait en deux endroits (« Comment
 * il démarre », puis « Démarrer sur un évènement »), cinq grandes tuiles
 * bavardes, et tous les filtres d'un email visibles d'un coup. Désormais
 * quatre façons de démarrer, une ligne et un interrupteur chacune ;
 * l'essentiel visible (la boîte, De, Objet, pièce jointe), le reste replié
 * (« Plus de critères », « Options avancées ») ; et une phrase « en clair »
 * qui dit ce qui lancera le workflow, à chaque frappe.
 */
final class WorkflowStartPanelTest extends TestCase
{
    private static function editor(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/workflow-editor.js');
    }

    /** Le corps d'une fonction de l'éditeur (déclarée à 4 espaces, close par « } » seul sur sa ligne). */
    private static function fn(string $src, string $signature): string
    {
        $start = strpos($src, '    function ' . $signature . ' {');
        self::assertNotFalse($start, "Fonction $signature introuvable.");
        $end = strpos($src, "\n    }\n", $start);
        self::assertNotFalse($end);
        return substr($src, $start, $end - $start + 6);
    }

    public function testQuatreFaconsDeDemarrerEnUnSeulBloc(): void
    {
        $ed = self::editor();
        $start = self::fn($ed, 'startInspector()');
        $this->assertStringContainsString("sec('trigger', T('Quand démarre-t-il ?')", $start, 'Un seul bloc : « Quand démarre-t-il ? ».');
        $this->assertStringContainsString("ctrl: '<span class=\"wf-way-always\">'", $start, 'À la main : toujours possible, sans interrupteur.');
        $this->assertStringContainsString("bind: 'start.chat'", $start);
        $this->assertStringContainsString("bind: 'start.schedule'", $start);
        $this->assertStringContainsString('eventWay(s)', $start);
        $this->assertStringContainsString("bind: 'start.event'", self::fn($ed, 'eventWay(s)'));
        $this->assertStringNotContainsString('function eventSection', $ed, 'Plus de second bloc « Démarrer sur un évènement ».');
        $this->assertStringNotContainsString("id: 'trigger', title: T('Comment il démarre')", $ed);
        // Le trigger reste la cible des points « À régler » (programmation) et l'évènement la sienne.
        $this->assertStringContainsString("schedule: 'trigger'", $ed);
        $this->assertStringContainsString("sec: 'event'", self::fn($ed, 'eventWay(s)'));
    }

    public function testLInterrupteurEstUneVraieCaseACocher(): void
    {
        $way = self::fn(self::editor(), 'way(o)');
        $this->assertStringContainsString('<label class="wf-way-row">', $way, 'Toute la ligne est l’étiquette : au doigt, on touche la ligne.');
        $this->assertStringContainsString('type="checkbox" class="wf-switch" role="switch"', $way);
        $this->assertStringContainsString('aria-label=', $way);
        $css = (string)file_get_contents(ROOT . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.wf-switch { appearance: none;', $css);
        $this->assertStringContainsString('.wf-switch:focus-visible', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce) { .wf-switch', $css);
        $this->assertStringContainsString('@media (pointer: coarse) { .wf-switch { width: 44px;', $css);
    }

    public function testLEvenementGardeSesReglagesQuandOnChangeDAvis(): void
    {
        $ed = self::editor();
        $set = self::fn($ed, 'setEvent(kind)');
        $this->assertStringContainsString('stash[start.event.kind] = start.event;', $set, 'Quitter l’email garde ses filtres.');
        $this->assertStringContainsString('start.event = stash[kind] ||', $set, 'Y revenir les retrouve.');
        $this->assertStringContainsString("if (path === 'start.event') {", $ed);
        $this->assertStringContainsString("setEvent(el.checked ? ((state.eventStash || {}).last || 'email') : '');", $ed, 'Rallumé : le dernier évènement réglé revient.');
        $this->assertStringContainsString("if (state.def.start.event && state.def.start.event.kind === val) { return; }", $ed, 'Retoucher le genre choisi ne remet rien à zéro.');
    }

    public function testLEssentielVisibleLeResteReplie(): void
    {
        $email = self::fn(self::editor(), 'emailEvent(ev)');
        $fold = strpos($email, "id: 'event-more'");
        $this->assertNotFalse($fold, 'Les critères secondaires sont repliés dans « Plus de critères ».');
        $visible = substr($email, 0, $fold);
        $folded = substr($email, $fold);
        foreach (["fin('from'", "fin('subject'", 'start.event.filters.mail.attachment'] as $k) {
            $this->assertStringContainsString($k, $visible, "« $k » reste visible.");
        }
        foreach (["fin('exclude'", "fin('to'", "fin('words'", 'start.event.query'] as $k) {
            $this->assertStringContainsString($k, $folded, "« $k » est replié.");
            $this->assertStringNotContainsString($k, $visible);
        }
        $this->assertStringContainsString("T('Dans quelle boîte ?')", $email);
        $this->assertStringContainsString("T('Quels emails ?')", $email);
        $this->assertStringContainsString('/settings/connections#connector-', $email, 'Une boîte non reliée mène à sa reconnexion.');

        $adv = self::fn(self::editor(), 'startAdvanced(s, hasFiche)');
        $this->assertStringContainsString("id: 'start-adv'", $adv);
        foreach (['start.event.piece_name', 'start.fiche_name', 'id="wfHookExample"', 'data-act="hook-renew"'] as $k) {
            $this->assertStringContainsString($k, $adv, "« $k » vit dans « Options avancées ».");
        }
    }

    public function testLesContratsDuVoletSontGardes(): void
    {
        $ed = self::editor();
        foreach (['data-act="ftest"', 'id="wfFilterTest"', 'id="wfHookUrl"', 'data-act="hook-copy"', 'id="wfSchedSay"', 'id="wfEventSay"',
            'data-bind="start.event.folder"', 'data-bind="start.event.workflow_id"', "kindSeg('start.event.filters.drive.kind'"] as $k) {
            $this->assertStringContainsString($k, $ed);
        }
        // La phrase suit la frappe sans redessiner le volet (le focus reste dans le champ).
        $this->assertStringContainsString("indexOf('start.event.') === 0) { staleFilterTest(); refreshEventSay(); }", $ed);
    }

    /** La phrase « en clair », exécutée sous node avec des doublures minimales. */
    public function testLaPhraseEnClairDitCeQuiLanceraLeWorkflow(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $cases = [
            'vide'      => ['kind' => 'email', 'source' => 'gmail', 'query' => '', 'filters' => ['mail' => []]],
            'complet'   => ['kind' => 'email', 'source' => 'outlook', 'query' => 'label:clients', 'filters' => ['mail' => [
                'from' => 'paul@client.fr, @client.fr', 'subject' => 'devis, commande', 'words' => 'urgent', 'to' => 'factures@acme.fr',
                'attachment' => true, 'exclude' => 'noreply']]],
            'injection' => ['kind' => 'email', 'source' => 'gmail', 'query' => '', 'filters' => ['mail' => ['from' => '<img src=x onerror=alert(1)>']]],
            'dossier0'  => ['kind' => 'file', 'folder' => '', 'filters' => ['drive' => []]],
            'dossier'   => ['kind' => 'file', 'folder' => 'Clients/À traiter', 'folder_name' => '', 'filters' => ['drive' => ['kind' => 'sheet', 'name' => 'facture']]],
            'appel'     => ['kind' => 'webhook'],
            'suite'     => ['kind' => 'workflow', 'workflow_id' => 7],
            'suite0'    => ['kind' => 'workflow', 'workflow_id' => null],
        ];
        $script = "var T = function (s, v) { return String(s).replace(/\\{(\\w+)\\}/g, function (m, k) { return v && k in v ? v[k] : m; }); };\n"
            . "var esc = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;'); };\n"
            . "var toDisplay = function (s) { return s; }; var icon = function () { return '<i></i>'; };\n"
            . "var L = { driveKinds: { sheet: 'Feuilles de calcul' } }; var cat = { workflows: [{ id: 7, name: 'Relances' }] };\n"
            . self::fn(self::editor(), 'eventSentence(ev)') . "\n"
            . 'var cases = ' . json_encode($cases, JSON_UNESCAPED_UNICODE) . "; var out = {};\n"
            . "Object.keys(cases).forEach(function (k) { out[k] = eventSentence(cases[k]).replace('<span class=\"wf-say-ic\"><i></i></span><span>', '').replace(/<\\/span>$/, ''); });\n"
            . 'process.stdout.write(JSON.stringify(out));';
        $file = tempnam(sys_get_temp_dir(), 'wfsay') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'La phrase se calcule sans erreur.');

        $this->assertSame('Démarre pour chaque nouvel email reçu dans <b>Gmail</b>. Aucun critère : tous les emails comptent.', $got['vide']);
        $this->assertSame('Démarre pour chaque nouvel email reçu dans <b>Outlook</b>, envoyé par <b>paul@client.fr</b> ou <b>@client.fr</b>, '
            . 'adressé à <b>factures@acme.fr</b>, dont l’objet contient <b>« devis »</b> ou <b>« commande »</b>, qui contient <b>« urgent »</b>, '
            . 'avec une pièce jointe, qui répond à la recherche <b>« label:clients »</b>, sauf s’il vient de <b>noreply</b>.', $got['complet'],
            'Chaque critère en mots, les possibilités jointes par « ou », l’exclusion en dernier.');
        $this->assertStringNotContainsString('<img', $got['injection'], 'Une valeur saisie est échappée.');
        $this->assertStringContainsString('<b>&lt;img src=x onerror=alert(1)&gt;</b>', $got['injection']);
        $this->assertSame('Indiquez le dossier : chaque nouveau fichier qui y arrive lancera le workflow.', $got['dossier0']);
        $this->assertSame('Démarre pour chaque nouveau fichier déposé dans <b>« Clients/À traiter »</b> (<b>Feuilles de calcul</b> seulement), dont le nom contient <b>« facture »</b>.', $got['dossier']);
        $this->assertStringStartsWith('Démarre à chaque appel de son adresse', $got['appel']);
        $this->assertSame('Démarre quand <b>« Relances »</b> se termine ; son livrable devient sa première pièce.', $got['suite']);
        $this->assertSame('Choisissez le workflow qui le lance.', $got['suite0']);
    }

    public function testLesPagesDAideDecriventLeNouveauVolet(): void
    {
        $doc = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/workflows/demarrer-sur-un-evenement.md');
        $this->assertStringContainsString('Quand démarre-t-il ?', $doc);
        $this->assertStringContainsString('Plus de critères', $doc);
        $this->assertStringContainsString('Options avancées', $doc);
    }
}
