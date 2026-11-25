<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Carte de réalisation d'une « Version soignée » (NativeDeckRun) : état posé
 * sur le message d'attente, vue de la carte, rendu unique, et câblage
 * (lancement, job, page, historique JSON, chat.js, native-run.js, styles).
 */
final class NativeDeckRunTest extends TestCase
{
    private const URL = '/chat/12/presentation/0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    /** Espaces insécables d'une durée ou d'un titre (NativeDeckRun::duration, ::typo). */
    private static function nb(string $text): string
    {
        return str_replace(' ', "\u{00A0}", $text);
    }

    private static function row(array $run, string $role = 'assistant', int $id = 7): array
    {
        return ['id' => $id, 'role' => $role, 'agent_json' => json_encode(['progress' => $run, 'suggestion' => ''])];
    }

    // ------------------------------------------------------------- état

    public function testUnEtatEstPoseAuLancementPuisFermeALaFin(): void
    {
        $run = NativeDeckRun::start('pptx', 'anthropic::claude-fable-5-1', 'Claude Fable 5.1', 'Cap sur le drapeau !', 0, 1000);
        $this->assertSame(['type' => 'native_deck', 'kind' => 'pptx', 'status' => 'running', 'llm' => 'anthropic::claude-fable-5-1',
            'model' => 'Claude Fable 5.1', 'title' => 'Cap sur le drapeau !', 'job_id' => 0, 'started' => 1000], $run);
        $this->assertSame('xlsx', NativeDeckRun::start('xlsx', 'openai::gpt-6-luna', '', 'Budget', 3, 1)['kind']);
        $this->assertSame('openai::gpt-6-luna', NativeDeckRun::start('xlsx', 'openai::gpt-6-luna', '', 'Budget', 3, 1)['model'],
            'Sans libellé, le modèle se nomme par sa clé.');
        $this->assertSame('pdf', NativeDeckRun::start('pdf', 'x', 'X', 'T', 0, 1)['kind']);
        $this->assertSame('docx', NativeDeckRun::start('docx', 'x', 'X', 'T', 0, 1)['kind']);
        $this->assertSame('pptx', NativeDeckRun::start('odt', 'x', 'X', 'T', 0, 1)['kind'], 'Genre inconnu : présentation.');

        $done = NativeDeckRun::finish($run, NativeDeckRun::DONE, 1102, ['file' => 'cap.pptx', 'url' => self::URL, 'autre' => 'x']);
        $this->assertSame('done', $done['status']);
        $this->assertSame(1102, $done['ended']);
        $this->assertSame('cap.pptx', $done['file']);
        $this->assertArrayNotHasKey('autre', $done);
        $this->assertSame('failed', NativeDeckRun::finish($run, 'n’importe quoi', 1)['status']);
    }

    public function testLEtatSeLitSurUnMessageDuModeleSeulement(): void
    {
        $run = NativeDeckRun::start('pptx', 'openai::gpt-6-luna', 'GPT-6 Luna', 'Offre', 4, 10);
        $this->assertSame($run, NativeDeckRun::of(self::row($run)));
        $this->assertNull(NativeDeckRun::of(self::row($run, 'user')));
        $this->assertNull(NativeDeckRun::of(['id' => 1, 'role' => 'assistant', 'agent_json' => null]));
        $this->assertNull(NativeDeckRun::of(self::row(['type' => 'autre', 'status' => 'running'])));
        $this->assertNull(NativeDeckRun::of(self::row(['type' => 'native_deck', 'status' => 'bizarre'])));
    }

    public function testUneRealisationDontLeJobNeTournePlusEstInterrompue(): void
    {
        $run = NativeDeckRun::start('pptx', 'x', 'X', 'T', 4, 10);
        $this->assertSame('failed', NativeDeckRun::settle($run, false)['status']);
        $this->assertSame('running', NativeDeckRun::settle($run, true)['status']);
        $this->assertSame('running', NativeDeckRun::settle($run, null)['status'], 'File illisible : l’état est gardé.');
        $done = NativeDeckRun::finish($run, NativeDeckRun::DONE, 20);
        $this->assertSame('done', NativeDeckRun::settle($done, false)['status'], 'Une réalisation finie ne redevient pas interrompue.');

        // Aucune requête à la file : un état fini, ou en cours sans job.
        $views = NativeDeckRun::forMessages([
            self::row($done, 'assistant', 1),
            self::row(NativeDeckRun::start('pptx', 'x', 'X', 'T', 0, 10), 'assistant', 2),
            ['id' => 3, 'role' => 'assistant', 'agent_json' => null],
        ], 30, [1 => 120]);
        $this->assertSame([1, 2], array_keys($views));
        $this->assertSame('done', $views[1]['status']);
        $this->assertStringContainsString('120 crédits', $views[1]['by']);
        $this->assertSame('failed', $views[2]['status'], 'Lancement interrompu avant l’enfilage : jamais une carte qui tourne.');
    }

    // ------------------------------------------------------------- temps

    public function testLeTempsEtLEstimation(): void
    {
        $this->assertSame(self::nb('0 s'), NativeDeckRun::duration(-3));
        $this->assertSame(self::nb('42 s'), NativeDeckRun::duration(42));
        $this->assertSame(self::nb('1 min 05 s'), NativeDeckRun::duration(65), 'Une durée ne se coupe pas en fin de ligne.');
        $this->assertSame(self::nb('3 min 00 s'), NativeDeckRun::duration(180));
        $this->assertSame('Cap sur le drapeau' . self::nb(' !'), NativeDeckRun::typo('Cap sur le drapeau !'));
        $this->assertSame(self::nb('« Offre » :') . ' 2027', NativeDeckRun::typo('« Offre » : 2027'));

        $this->assertSame(0, NativeDeckRun::estimate(0));
        $previous = 0;
        foreach ([10, 30, 60, 120, 180, 400, 4000] as $s) {
            $p = NativeDeckRun::estimate($s);
            $this->assertGreaterThanOrEqual($previous, $p);
            $this->assertLessThanOrEqual(NativeDeckRun::PROGRESS_CAP, $p, 'Jamais 100 % avant la fin.');
            $previous = $p;
        }
        $this->assertGreaterThan(70, NativeDeckRun::estimate(120));
    }

    public function testLesEtapesSuiventLeTempsEtLeGenre(): void
    {
        $this->assertSame('Lecture de votre demande et de vos fichiers…', NativeDeckRun::phaseAt('pptx', 5));
        $this->assertSame('Composition des slides…', NativeDeckRun::phaseAt('pptx', 40));
        $this->assertSame('Construction du classeur…', NativeDeckRun::phaseAt('xlsx', 40));
        $this->assertSame('Mise en page et vérification…', NativeDeckRun::phaseAt('pptx', 120));
        $this->assertStringContainsString('plus long que d’habitude', NativeDeckRun::phaseAt('pptx', NativeDeckRun::SLOW_AFTER_SEC));
        $phases = NativeDeckRun::phases('xlsx');
        $this->assertSame([0, 20, 90], array_column($phases, 0));
    }

    // -------------------------------------------------------------- vue

    public function testLaVueDitCeQuiSePasseDansChaqueEtat(): void
    {
        $run = NativeDeckRun::start('pptx', 'anthropic::claude-fable-5-1', 'Claude Fable 5.1', 'Cap sur le drapeau !', 9, 1000);

        $v = NativeDeckRun::view($run, 7, 1072);
        $this->assertSame('running', $v['status']);
        $this->assertSame(72, $v['elapsed']);
        $this->assertSame(self::nb('1 min 12 s'), $v['elapsed_text']);
        $this->assertSame('Cap sur le drapeau' . self::nb(' !'), $v['title']);
        $this->assertSame('Claude Fable 5.1 réalise votre présentation', $v['by']);
        $this->assertSame('En cours', $v['state']);
        $this->assertSame('slides', $v['tone']);
        $this->assertSame('PPTX', $v['ext']);
        $this->assertSame('/assets/img/llm-anthropic.png', $v['logo']);
        $this->assertSame('Composition des slides…', $v['phase']);

        $done = NativeDeckRun::view(NativeDeckRun::finish($run, NativeDeckRun::DONE, 1102, ['file' => 'cap.pptx', 'url' => self::URL]), 7, 5000, 340);
        $this->assertSame('Présentation prête', $done['state']);
        $this->assertSame('Réalisée par Claude Fable 5.1 en ' . self::nb('1 min 42 s') . ' · 340 crédits', $done['by'], 'La durée est celle de la réalisation, pas de la relecture.');
        $this->assertSame(self::URL, $done['url']);

        $forged = NativeDeckRun::view(NativeDeckRun::finish($run, NativeDeckRun::DONE, 1102, ['file' => 'x', 'url' => 'https://ailleurs.example/x']), 7, 1200);
        $this->assertSame('', $forged['url'], 'Seule l’adresse d’un fichier réalisé est acceptée.');

        $xlsx = NativeDeckRun::view(NativeDeckRun::finish(NativeDeckRun::start('xlsx', 'openai::gpt-6-luna', 'GPT-6 Luna', 'Budget', 3, 0), NativeDeckRun::DONE, 50), 8, 60);
        $this->assertSame('Classeur prêt', $xlsx['state']);
        $this->assertSame('sheet', $xlsx['tone']);

        $this->assertSame('Arrêtée', NativeDeckRun::view(NativeDeckRun::finish($run, NativeDeckRun::CANCELLED, 1040), 7, 9999)['state']);
        $this->assertSame('Réalisation arrêtée après ' . self::nb('40 s') . '.', NativeDeckRun::view(NativeDeckRun::finish($run, NativeDeckRun::CANCELLED, 1040), 7, 9999)['by']);
        $this->assertSame('La réalisation n’a pas abouti.', NativeDeckRun::view(NativeDeckRun::settle($run, false), 7, 9999)['by']);
    }

    public function testLaVueSeLitEnAnglais(): void
    {
        I18n::use('en');
        $run = NativeDeckRun::start('pptx', 'openai::gpt-6-luna', 'GPT-6 Luna', 'Offer', 9, 1000);
        $v = NativeDeckRun::view($run, 7, 1030);
        $this->assertSame('GPT-6 Luna is building your presentation', $v['by']);
        $this->assertSame('Polished version', $v['kicker']);
        $this->assertSame('In progress', $v['state']);
        $done = NativeDeckRun::view(NativeDeckRun::finish($run, NativeDeckRun::DONE, 1065, ['file' => 'o.pptx', 'url' => self::URL]), 7, 1070);
        $this->assertSame('Presentation ready', $done['state']);
        $this->assertSame('Built by GPT-6 Luna in ' . self::nb('1 min 05 s'), $done['by']);
        $this->assertSame('File kept for 30 days', $done['kept']);
    }

    // ------------------------------------------------------------ rendu

    public function testLaCarteEnCoursPorteSesTextesEtSonArret(): void
    {
        $run  = NativeDeckRun::start('pptx', 'anthropic::claude-fable-5-1', 'Claude <Fable>', 'Cap & « drapeau »', 9, 1000);
        $html = NativeDeckRun::html(NativeDeckRun::view($run, 7, 1010));

        $this->assertStringContainsString('class="native-run is-running"', $html);
        $this->assertStringContainsString('data-job-id="9"', $html);
        $this->assertStringContainsString('data-message-id="7"', $html);
        $this->assertStringContainsString('data-elapsed="10"', $html);
        $this->assertStringContainsString('Cap &amp; ' . self::nb('« drapeau »'), $html, 'Le titre est échappé.');
        $this->assertStringContainsString('Claude &lt;Fable&gt; réalise votre présentation', $html);
        $this->assertStringNotContainsString('<Fable>', $html);
        $this->assertStringContainsString('class="native-run-stop"', $html);
        $this->assertStringContainsString('class="native-run-track"', $html);
        $this->assertStringContainsString('data-run-elapsed', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        // Les étapes partent en JSON dans un attribut, sans casser le HTML.
        preg_match('/data-phases="([^"]*)"/', $html, $m);
        $phases = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
        $this->assertSame('Composition des slides…', $phases[1][1] ?? null);
        $this->assertStringContainsString('data-failed-by="La réalisation n’a pas abouti."', $html);
    }

    public function testLaCartePreteOffreLeTelechargementEtPlusDArret(): void
    {
        $run  = NativeDeckRun::finish(NativeDeckRun::start('pptx', 'openai::gpt-6-luna', 'GPT-6 Luna', 'Offre', 9, 1000), NativeDeckRun::DONE, 1090,
            ['file' => 'offre.pptx', 'url' => self::URL]);
        $html = NativeDeckRun::html(NativeDeckRun::view($run, 7, 2000, 12));

        $this->assertStringContainsString('class="native-run is-done"', $html);
        $this->assertStringContainsString('<a class="native-run-download" href="' . self::URL . '" data-filename="offre.pptx"', $html);
        $this->assertStringContainsString('Fichier conservé 30 jours', $html);
        $this->assertStringNotContainsString('native-run-stop', $html);
        $this->assertStringNotContainsString('data-elapsed', $html, 'Rien à animer.');
        $this->assertStringContainsString('--run-progress: 100%', $html);
    }

    // ----------------------------------------------------------- câblage

    public function testCablage(): void
    {
        $src = static fn (string $f): string => (string)file_get_contents(ROOT . '/' . $f);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Files/NativeDeckRun.php', $src($f), $f);
        }

        $chat = $src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('NativeDeckRun::start($kind, $llm, $label, $titre, 0, time())', $chat);
        $this->assertStringContainsString('Message::setRun($msgId, $run);', $chat, 'L’état est posé AVANT l’enfilage.');
        $this->assertLessThan(strpos($chat, "JobQueue::enqueue('native_deck'"), strpos($chat, 'Message::setRun($msgId, $run);'));
        $this->assertStringContainsString("'message_id' => \$msgId,", $chat, 'Le job connaît son message d’attente.');
        $this->assertStringContainsString('Message::setRunJob($msgId, $jobId);', $chat);
        $this->assertStringContainsString("'run_html' => \$msgId > 0 ? NativeDeckRun::html(", $chat);
        $this->assertSame(2, substr_count($chat, '$nativeRuns = NativeDeckRun::forMessages('), 'show() et loadJson() relisent les cartes.');
        $this->assertStringContainsString("'run_html'     => isset(\$nativeRuns[", $chat);

        $job = $src('src/Jobs/NativeDeckJob.php');
        $this->assertStringContainsString("\$this->pendingMessage(\$convId, (int)(\$payload['message_id'] ?? 0))", $job);
        $this->assertStringContainsString('Message::updateContent($pending, $texte);', $job, 'Le fichier arrive dans le message d’attente.');
        $this->assertStringContainsString('NativeDeckRun::finish($run, NativeDeckRun::DONE', $job);
        $this->assertStringContainsString('NativeDeckRun::finish($run, NativeDeckRun::CANCELLED', $job);
        $this->assertStringContainsString('NativeDeckRun::finish($run, NativeDeckRun::FAILED', $job);
        $this->assertStringContainsString("'run_html' => \$this->runHtml(", $job);

        $message = $src('templates/partials/message.php');
        $this->assertStringContainsString("include ROOT . '/templates/partials/native-run-card.php'", $message);
        $this->assertStringContainsString('$hasRichAttachment = $msgRun !== null;', $message, 'Ni Copier ni Regénérer sur la carte.');

        $index = $src('templates/chat/index.php');
        $this->assertStringContainsString('/assets/js/native-run.js?v=', $index);
        $this->assertLessThan(strpos($index, '/assets/js/chat.js?v='), strpos($index, '/assets/js/native-run.js?v='));

        $chatJs = $src('public/assets/js/chat.js');
        $this->assertStringContainsString("if (role === 'assistant' && options?.runHtml) {", $chatJs);
        $this->assertStringContainsString("runHtml: msg.run_html || ''", $chatJs, 'Historique JSON.');
        $this->assertStringContainsString("runHtml: m.run_html || ''", $chatJs, 'Lancement.');
        $this->assertStringContainsString('window.MiaNativeRun?.replace(json.msg_id, json.run_html, { justDone: !json.cancelled })', $chatJs);
        $this->assertStringContainsString('window.MiaNativeRun?.fail(state.id);', $chatJs);
        $this->assertSame(2, substr_count($chatJs, 'state.serverError = true;'), 'Quota au démarrage et erreur du job, jamais un suivi perdu.');

        $js = $src('public/assets/js/native-run.js');
        $this->assertDoesNotMatchRegularExpression('/\bt\(\s*[\'"]/', $js, 'Aucun texte : tout vient de la carte.');
        $this->assertStringContainsString("'/jobs/' + jobId + '/cancel'", $js);
        $this->assertStringContainsString('root.MiaNativeRun = {', $js);

        $css = $src('public/assets/css/files.css');
        foreach (['.native-run {', '.native-run.is-running .native-run-orbit', '.native-run-download', '@property --run-angle'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        $reduced = substr($css, (int)strrpos($css, '@media (prefers-reduced-motion: reduce)'));
        $this->assertStringContainsString('.native-run-orbit', $reduced, 'Mouvement réduit : rien ne tourne.');
    }
}
