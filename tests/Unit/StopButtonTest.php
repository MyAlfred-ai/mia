<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bouton Stop d'une réponse de MIA.
 *
 * Le défaut corrigé : le clic envoyait une demande d'arrêt au serveur et ne
 * changeait RIEN à l'écran. Le worker ne la voit qu'à sa prochaine lecture du
 * flux du modèle (une demi-seconde quand le texte coule, bien plus pendant
 * qu'il réfléchit ou qu'un outil travaille) : le texte continuait de s'écrire,
 * le bouton restait là — il semblait sans effet. Désormais l'écran s'arrête
 * au clic, et la réponse enregistrée est celle qu'on a vue s'arrêter.
 *
 * Le comportement du navigateur est vérifié en EXÉCUTANT le vrai code de
 * chat.js sous node (flux factice, clic simulé) : une lecture du code ne dit
 * pas dans quel état la bulle se retrouve. Sans node, ces tests sont sautés.
 */
final class StopButtonTest extends TestCase
{
    private const MARQUE = "*[Réponse interrompue par l'utilisateur]*";

    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/JobController.php';
    }

    // ------------------------------------------------------------------
    // Serveur : formes pures
    // ------------------------------------------------------------------

    public function testLeTexteVuSArreteAuRepereDeLEcran(): void
    {
        $rows = [
            ['seq' => 1, 'payload' => '{"phase":"analyzing"}'],
            ['seq' => 2, 'payload' => '{"chunk":"Un "}'],
            ['seq' => 3, 'payload' => '{"chunk":"deux "}'],
            ['seq' => 4, 'payload' => '{"chunk":"trois "}'],
            ['seq' => 5, 'payload' => '{"action":{"t":"web_search"}}'],
        ];
        $this->assertSame('Un deux ', JobQueue::seenTextFromEvents($rows, 3));
        $this->assertSame('Un deux trois ', JobQueue::seenTextFromEvents($rows, 9));
        $this->assertSame('', JobQueue::seenTextFromEvents($rows, 1), 'rien d\'écrit à l\'écran : rien de vu');
        $this->assertSame('', JobQueue::seenTextFromEvents([], 0));
    }

    public function testUneReponseAvecUneImageNEstJamaisCoupee(): void
    {
        $avant = [
            ['seq' => 1, 'payload' => '{"chunk":"Voici "}'],
            ['seq' => 2, 'payload' => '{"tool_image":{"url":"/assets/img/generated/a.png"}}'],
            ['seq' => 3, 'payload' => '{"chunk":"la suite"}'],
        ];
        $this->assertNull(JobQueue::seenTextFromEvents($avant, 3), 'image vue : la réponse ne se recoupe plus');
        $this->assertNull(JobQueue::seenTextFromEvents($avant, 1), 'image arrivée après le clic : gardée, avec son texte');
    }

    public function testLaReponseEstCoupeeSeulementSiLeTexteVuEnEstLeDebut(): void
    {
        $this->assertSame('Un deux ', ChatController::cutToSeen('Un deux trois ', 'Un deux '));
        $this->assertSame('', ChatController::cutToSeen('Un deux', ''));
        $this->assertSame('Un deux', ChatController::cutToSeen('Un deux', null), 'sans repère : tout est gardé');
        $this->assertSame(
            "Un\n\n![x](/i.png)\n\ndeux",
            ChatController::cutToSeen("Un\n\n![x](/i.png)\n\ndeux", 'Un deux'),
            'un texte vu qui ne se recoupe pas ne coupe rien'
        );
    }

    public function testLaMarqueEnregistreeEstCelleQueLEcranAPosee(): void
    {
        $this->assertSame("Un deux\n\n" . self::MARQUE, ChatController::markAsAborted("Un deux \n"));
        $this->assertSame(self::MARQUE, ChatController::markAsAborted('  '), 'arrêtée avant le premier mot');
        $this->assertStringContainsString(
            't("*[Réponse interrompue par l\'utilisateur]*")',
            self::chatJs(),
            'chat.js pose la même marque'
        );
    }

    public function testLeRepereDAffichageEstUnEntierPositif(): void
    {
        $this->assertSame(12, JobController::seenSeq('12'));
        $this->assertSame(0, JobController::seenSeq('0'));
        $this->assertSame(7, JobController::seenSeq(7));
        $this->assertNull(JobController::seenSeq(null));
        $this->assertNull(JobController::seenSeq(''));
        $this->assertNull(JobController::seenSeq('-3'));
        $this->assertNull(JobController::seenSeq('3; DROP'));
        $this->assertNull(JobController::seenSeq(['3']));
    }

    // ------------------------------------------------------------------
    // Serveur : câblage
    // ------------------------------------------------------------------

    public function testLaMigrationAjouteLeRepere(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/106_job_cancel_seq.sql');
        $this->assertStringContainsString('ADD COLUMN cancel_seq INT UNSIGNED NULL', $sql);
    }

    public function testUneReponseEnFileEstCloseToutDeSuite(): void
    {
        $src = self::fonctionPhp((string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php'), 'requestCancel');
        $this->assertMatchesRegularExpression("/SET status = 'cancelled'.*WHERE id = \\? AND user_id = \\? AND status = 'pending'/s", $src);
        $this->assertStringContainsString("self::finish(\$jobId, 'cancelled', null, null)", $src,
            'une tâche programmée ou un workflow apprend la fin comme pour un job arrêté en cours');
        $this->assertStringContainsString('cancel_seq = COALESCE(cancel_seq, ?)', $src);
    }

    public function testUneGenerationArreteeQuitteLeTiroirEtNeSeRattachePlus(): void
    {
        $src = self::fonctionPhp((string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php'), 'activeForUser');
        $this->assertStringContainsString('AND cancel_requested = 0', $src);
    }

    public function testLeTourDeChatCoupeLaReponseApresLeCalculDeLUsage(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $usage = strpos($src, 'LlmAdapterHelpers::fillAbortedUsage(');
        $coupe = strpos($src, 'self::markAsAborted(self::cutToSeen($fullResp, self::textSeenAtStop()))');
        $this->assertNotFalse($usage);
        $this->assertNotFalse($coupe, 'runSend coupe la réponse au texte vu');
        $this->assertLessThan($coupe, $usage, 'le fournisseur facture ce qu\'il a produit : l\'usage d\'abord');

        $job = (string)file_get_contents(ROOT . '/src/Jobs/ChatJob.php');
        $this->assertStringContainsString('ChatController::setStopTextSource(static function', $job);
        $this->assertStringContainsString('JobQueue::textSeenUpTo($ctx->jobId(), $seq)', $job);
        $this->assertStringContainsString('ChatController::setStopTextSource(null);', $job, 'remise à null en fin de job');

        $ctx = (string)file_get_contents(ROOT . '/src/Jobs/JobContext.php');
        $this->assertStringContainsString('SELECT cancel_requested, cancel_seq FROM async_jobs', $ctx);
    }

    public function testLaRouteDArretTransmetLeRepere(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/JobController.php');
        $this->assertStringContainsString("JobQueue::requestCancel((int)\$id, (int)\$user['id'], self::seenSeq(\$_POST['seq'] ?? null))", $src);
    }

    public function testArreterUnWorkflowNeLeDitQuUneFois(): void
    {
        // Un job encore en file est clos sur-le-champ : sa fin repasserait
        // par onJobFinished (« ⏹ Workflow arrêté pendant… ») si l'étape
        // n'était pas close AVANT.
        $src = self::fonctionPhp((string)file_get_contents(ROOT . '/src/Workflow/WorkflowEngine.php'), 'cancel');
        $etape = strpos($src, 'WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED');
        $arret = strpos($src, 'JobQueue::requestCancel(');
        $this->assertNotFalse($etape);
        $this->assertNotFalse($arret);
        $this->assertLessThan($arret, $etape);
    }

    // ------------------------------------------------------------------
    // Navigateur : câblage
    // ------------------------------------------------------------------

    public function testToutesLesPortesDArretPassentParLeMemeChemin(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('window.miaStopJob = stopJobById;', $js);
        $abort = self::fonctionJs($js, 'abortVisibleStream');
        $this->assertStringContainsString('stopChatJobNow(bgState, cidStr)', $abort);
        $this->assertStringContainsString('stopJobNow(jobState)', $abort);
        $this->assertStringNotContainsString('/cancel', $abort, 'plus d\'appel direct : tout passe par postJobCancel');
        // Tiroir des générations et « stop » dit en conversation vocale.
        $this->assertStringContainsString("stopJobById(jobId);", $js);
        $this->assertStringContainsString("if (typeof window.miaStopJob === 'function') { window.miaStopJob(turnJobId); return; }", $js);
    }

    public function testLeBoutonMontreUnArretEnCoursEtLesTextesSontTraduits(): void
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.btn-send.btn-stop.is-stopping', $css);
        $this->assertStringContainsString('prefers-reduced-motion', substr($css, (int)strpos($css, '.btn-send.btn-stop.is-stopping')));
        $client = require ROOT . '/lang/en.client.php';
        foreach (['Arrêt en cours…', 'Arrêter la génération', '*[Génération arrêtée]*', self::MARQUE] as $cle) {
            $this->assertArrayHasKey($cle, $client, "« $cle » manque au dictionnaire du navigateur");
        }
    }

    // ------------------------------------------------------------------
    // Navigateur : le vrai code, exécuté
    // ------------------------------------------------------------------

    public function testLeClicStopFigeLaReponseToutDeSuite(): void
    {
        $r = $this->scenario(<<<'JS'
            const { state, el, es } = lancer();
            es.emit({ phase: 'analyzing' }, 1);
            es.emit({ chunk: 'Bonjour ' }, 2);
            es.emit({ chunk: 'le monde' }, 3);
            abortVisibleStream();
            const apresClic = {
                raw: el.dataset.raw, finalized: el.finalized, phase: el.phaseRemoved,
                running: renderedRunning, stream: activeStreams.has('42'),
                stop: stopBtn.style.display, send: sendBtn.style.display,
                indicator: indicator, notified: notified.length,
            };
            es.emit({ chunk: ' — suite jamais montrée' }, 4);
            es.emit({ aborted: true, msg_id: 77, quota_status: null, conversation_credits_total: 12 }, 5);
            es.emit({ job_end: true, status: 'cancelled' });
            await tick();
            out({ apresClic, fetches, rawFin: el.dataset.raw, messageId: el.dataset.messageId,
                  credits, esClosed: es.closed, notified });
        JS);

        $clic = $r['apresClic'];
        $this->assertSame("Bonjour le monde\n\n" . self::MARQUE, $clic['raw'], 'la réponse s\'arrête au clic, marquée');
        $this->assertTrue($clic['finalized']);
        $this->assertTrue($clic['phase']);
        $this->assertSame([], $clic['running'], 'plus aucune ligne « en cours »');
        $this->assertFalse($clic['stream'], 'la conversation n\'a plus de réponse en cours');
        $this->assertSame('none', $clic['stop']);
        $this->assertSame('', $clic['send'], 'Envoyer revient aussitôt');
        $this->assertSame(['42', false], $clic['indicator']);
        $this->assertSame(0, $clic['notified'], 'fin du tour annoncée hors de la pile du clic');

        $this->assertSame([['url' => '/jobs/J1/cancel', 'seq' => '3']], $r['fetches'], 'le serveur reçoit le repère du dernier évènement montré');
        $this->assertSame("Bonjour le monde\n\n" . self::MARQUE, $r['rawFin'], 'le texte arrivé après le clic n\'est pas montré');
        $this->assertSame('77', $r['messageId']);
        $this->assertSame(12, $r['credits']);
        $this->assertTrue($r['esClosed']);
        $this->assertSame([['status' => 'cancelled', 'jobId' => 'J1']], $r['notified'], 'une seule fin de tour');
    }

    public function testUneReponseTermineeJusteAvantLeClicEstRendueEnEntier(): void
    {
        $r = $this->scenario(<<<'JS'
            const { state, el, es } = lancer();
            es.emit({ chunk: 'Réponse ' }, 1);
            abortVisibleStream();
            es.emit({ chunk: 'complète.' }, 2);
            es.emit({ done: true, msg_id: 9 }, 3);
            await tick();
            out({ raw: state.rawText, applied: applied.map((j) => Object.keys(j)[0]), stopping: state.stopping, notified });
        JS);

        $this->assertSame('Réponse complète.', $r['raw'], 'le serveur l\'a enregistrée entière : l\'écran aussi');
        $this->assertContains('done', $r['applied']);
        $this->assertFalse($r['stopping']);
        $this->assertCount(1, $r['notified']);
    }

    public function testUneImageArriveeApresLeClicEstGardeeAvecSonTexte(): void
    {
        $r = $this->scenario(<<<'JS'
            const { el, es } = lancer();
            es.emit({ chunk: 'Voici ' }, 1);
            abortVisibleStream();
            es.emit({ tool_image: { url: '/assets/img/generated/a.png' } }, 2);
            es.emit({ chunk: 'ton image.' }, 3);
            es.emit({ aborted: true, msg_id: 5 }, 4);
            out({ raw: el.dataset.raw });
        JS);

        $this->assertStringContainsString('](/assets/img/generated/a.png)', $r['raw']);
        $this->assertStringContainsString('ton image.', $r['raw']);
        $this->assertStringEndsWith(self::MARQUE, $r['raw']);
    }

    public function testUneReponseQuiFinitDeSEcrireNEstPasArretee(): void
    {
        $r = $this->scenario(<<<'JS'
            const { state, es } = lancer();
            es.emit({ chunk: 'Fin' }, 1);
            state.held = [{ done: true }];
            abortVisibleStream();
            out({ fetches, streamStopped, stopping: !!state.stopping });
        JS);

        $this->assertSame([], $r['fetches'], 'rien à arrêter : la réponse est complète');
        $this->assertTrue($r['streamStopped'], 'les dernières lettres s\'affichent d\'un coup');
        $this->assertFalse($r['stopping']);
    }

    public function testUneCreationDitQueLArretEstDemande(): void
    {
        $r = $this->scenario(<<<'JS'
            const label = { textContent: 'Génération de l\'image…' };
            const job = { id: 'K7', type: 'image', convId: '42', done: false, el: { querySelector: () => label } };
            activeJobs.set('K7', job);
            refreshSendStopUi();
            const avant = { stop: stopBtn.style.display, stopping: stopBtn.classList.contains('is-stopping') };
            abortVisibleStream();
            out({ avant, label: label.textContent, fetches, stopping: stopBtn.classList.contains('is-stopping'),
                  disabled: stopBtn.disabled, title: stopBtn.title, second: (abortVisibleStream(), fetches.length) });
        JS);

        $this->assertSame(['stop' => '', 'stopping' => false], $r['avant']);
        $this->assertSame('Arrêt en cours…', $r['label']);
        $this->assertSame([['url' => '/jobs/K7/cancel', 'seq' => null]], $r['fetches']);
        $this->assertTrue($r['stopping'], 'le bouton montre que l\'arrêt est demandé');
        $this->assertTrue($r['disabled']);
        $this->assertSame('Arrêt en cours…', $r['title']);
        $this->assertSame(1, $r['second'], 'un second clic ne renvoie rien');
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /** Exécute un scénario sur les vraies fonctions de chat.js, bords factices. */
    private function scenario(string $corps): array
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $js = self::chatJs();
        $fonctions = '';
        foreach ([
            'refreshSendStopUi', 'jobStopping', 'postJobCancel', 'stopChatJobNow', 'renderStoppedReply',
            'applyStoppedChatEvent', 'stopJobNow', 'stopJobById', 'abortVisibleStream', 'attachChatJob',
            'finishChatJobStream',
        ] as $nom) {
            $fonctions .= self::fonctionJs($js, $nom) . "\n";
        }

        $script = <<<'JS'
            globalThis.window = globalThis;
            const fetches = [];
            const notified = [];
            const applied = [];
            let renderedRunning = null;
            let indicator = null;
            let credits = null;
            let streamStopped = false;
            const t = (s) => s;
            const csrfToken = 'x';
            let visibleConvId = '42';
            const activeStreams = new Map();
            const inlineControllers = new Map();
            const activeJobs = new Map();
            const JOB_TYPES = {};
            const pendingFiles = [];
            const msgInput = { value: '' };
            function bouton() {
                const classes = new Set();
                return {
                    style: { display: '' }, disabled: false, title: '', attrs: {},
                    classList: { toggle(c, on) { on ? classes.add(c) : classes.delete(c); }, contains: (c) => classes.has(c) },
                    setAttribute(k, v) { this.attrs[k] = v; },
                };
            }
            const stopBtn = bouton();
            const sendBtn = bouton();
            globalThis.fetch = (url, opts) => {
                const seq = opts && opts.body && typeof opts.body.get === 'function' ? opts.body.get('seq') : null;
                fetches.push({ url, seq });
                return Promise.resolve({ ok: true, json: async () => ({ cancelled: true }) });
            };
            function updateStreamingIndicator(cid, on) { indicator = [cid, on]; }
            function signalJobsChanged() {}
            function syncChoicePrompts() {}
            function jobIsVisible(state) { return String(state.convId) === String(visibleConvId); }
            function notifyChatTurnEnded(cid, detail) { notified.push({ status: detail.status, jobId: detail.jobId }); }
            function turnErrorTitle() { return ''; }
            function renderActionTrace(body, actions, running) { renderedRunning = running; }
            function renderProgressNote() {}
            function renderInlineError() {}
            function finalizeAssistantMessage(el) { el.finalized = true; }
            function applyQuotaStatus() {}
            function applyConversationCreditsTotal(n) { credits = n; }
            function applyChatStreamEvent(state, cid, json) {
                applied.push(json);
                if (typeof json.chunk === 'string') state.rawText += json.chunk;
                if (json.done) { state.done = true; if (!state.turnEndSent) notifyChatTurnEnded(cid, { status: 'done', jobId: state.jobId }); }
            }
            window.MiaStream = {
                isWriting: () => false,
                drain: (el, done) => done(),
                stop: (el) => { streamStopped = true; },
            };
            let lastEs = null;
            class EventSource {
                constructor(url) { this.url = url; this.closed = false; lastEs = this; }
                close() { this.closed = true; }
                emit(json, seq) { this.onmessage({ data: JSON.stringify(json), lastEventId: seq ? String(seq) : '' }); }
            }
            function nouvelleBulle() {
                const message = { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
                const body = {};
                const el = {
                    dataset: {}, finalized: false, phaseRemoved: false,
                    querySelector(sel) { return sel === '.stream-phase' ? { remove: () => { el.phaseRemoved = true; } } : null; },
                    closest(sel) { return sel === '.msg-body' ? body : (sel === '.message' ? message : null); },
                    parentElement: body,
                };
                return el;
            }
            function lancer() {
                const el = nouvelleBulle();
                const state = attachChatJob('J1', '42', el);
                return { state, el, es: lastEs };
            }
            const tick = () => new Promise((r) => setTimeout(r, 5));
            function out(o) { process.stdout.write(JSON.stringify(o)); }
        JS;

        return self::executerJs($node, $script . "\n" . $fonctions . "\n(async () => {\n" . $corps . "\n})().catch((e) => { console.error(e.stack || e); process.exit(1); });\n");
    }

    private static function chatJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    /** Source d'une fonction déclarée au niveau du fichier, accolades équilibrées. */
    private static function fonctionJs(string $source, string $nom): string
    {
        $debut = strpos($source, "\nfunction " . $nom . '(');
        self::assertNotFalse($debut, "Fonction $nom introuvable dans chat.js.");
        return self::bloc($source, (int)$debut + 1, $nom);
    }

    /** Source d'une méthode PHP, accolades équilibrées. */
    private static function fonctionPhp(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertNotFalse($debut, "Méthode $nom introuvable.");
        return self::bloc($source, (int)$debut, $nom);
    }

    private static function bloc(string $source, int $debut, string $nom): string
    {
        $ouvre = strpos($source, '{', $debut);
        $niveau = 0;
        for ($i = $ouvre, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $niveau++;
            } elseif ($source[$i] === '}') {
                $niveau--;
                if ($niveau === 0) {
                    return substr($source, $debut, $i - $debut + 1);
                }
            }
        }
        self::fail("$nom : accolades non équilibrées.");
    }

    private static function executerJs(string $node, string $script): array
    {
        $fichier = tempnam(sys_get_temp_dir(), 'mia-stop-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }
        $resultat = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultat)) {
            self::fail("chat.js a échoué sous node (code $code) :\n" . $erreurs . "\n" . $sortie);
        }
        return $resultat;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
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
