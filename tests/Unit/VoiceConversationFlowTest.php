<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * La boucle de la conversation vocale, EXÉCUTÉE.
 *
 * `chat.js` porte une machine à états (écoute → envoi → réflexion → lecture →
 * écoute) que trois nouveautés traversent : les ordres dits à voix haute
 * (« approuve », « envoie-le », « confirme »), l'interruption de la lecture
 * (« stop ») et la lecture EN DIRECT (la voix démarre pendant que la réponse
 * s'écrit encore). Une lecture du code ne dit pas dans quel état la boucle se
 * retrouve après un « stop », si le micro est ouvert au bon moment, si un
 * « oui » peut envoyer un email, ni si MIA rend la parole avant la fin d'une
 * réponse qu'elle lit en direct.
 *
 * Le VRAI code est donc exécuté sous node : l'IIFE « Conversation vocale » et
 * `runVoiceCardCommand` sont extraits de `chat.js`, le module
 * `voice-commands.js` est chargé tel quel, et seuls les bords sont factices —
 * le micro (socle Realtime), le lecteur audio, le réseau, les cartes.
 *
 * Sans node (conteneur applicatif), ces tests sont SKIPPÉS ; ils tournent sur
 * le poste de développement et en CI.
 */
final class VoiceConversationFlowTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $scenarios = [];

    // ------------------------------------------------------------------
    // Tour ordinaire et lecture
    // ------------------------------------------------------------------

    public function testUnMessagePartPuisSaReponseEstLueMicroOuvertPourLInterruption(): void
    {
        $debut = self::scenario('start');
        $this->assertSame('listening', $debut['mode']);
        $this->assertTrue($debut['mic']);
        $this->assertTrue($debut['body'], 'les cartes doivent pouvoir afficher quoi dire');

        $message = self::scenario('message');
        $this->assertSame('thinking', $message['mode']);
        $this->assertFalse($message['mic'], 'micro relâché dès que le message part');
        $this->assertContains(['submit', 'Bonjour, quel temps fait-il ?'], $message['log']);

        $lecture = self::scenario('speaking');
        $this->assertSame('speaking', $lecture['mode']);
        $this->assertTrue($lecture['mic'], 'interruption à la voix : le micro reste ouvert pendant la lecture');
        $this->assertStringContainsString('stop', $lecture['live'], 'le bandeau dit comment interrompre');
        // Le micro est repris AVANT la première note de la voix.
        $evenements = array_map(static fn(array $e): string => $e[0] . ':' . (str_starts_with((string)$e[1], 'blob:speech') ? 'voix' : $e[1]), $lecture['log']);
        $this->assertLessThan(array_search('play:voix', $evenements, true), array_search('mic:attach', $evenements, true));
    }

    public function testPendantLaLectureSeulStopEstEntendu(): void
    {
        // Une remarque, puis la voix de MIA revenue par le micro : la lecture continue.
        foreach (['remarque', 'echo'] as $nom) {
            $s = self::scenario($nom);
            $this->assertSame('speaking', $s['mode'], "$nom : la lecture ne doit pas s'interrompre");
            $this->assertSame([], $s['log'], "$nom : rien ne doit partir ni jouer");
        }

        $stop = self::scenario('stop');
        $this->assertSame('listening', $stop['mode'], '« stop » rend la parole');
        $this->assertTrue($stop['mic']);
        // Micro relâché AVANT le repère « je vous écoute », repris après.
        $this->assertSame(['mic', 'release'], $stop['log'][0]);
        $this->assertSame('play', $stop['log'][1][0]);
        $this->assertSame(['mic', 'attach'], $stop['log'][2]);
        $this->assertNotContains('submit', array_column($stop['log'], 0), '« stop » n\'est jamais un message');
        $this->assertSame('', $stop['live'], 'l\'invite « dites stop » disparaît avec la lecture');
    }

    public function testUnePhraseCommenceePendantLaLectureNEstJamaisUnMessage(): void
    {
        // Elle s'achève APRÈS la lecture, alors que MIA écoute déjà : sans la
        // règle d'origine, la dernière phrase lue reviendrait comme une question.
        $tardif = self::scenario('tardif');
        $this->assertSame('listening', $tardif['mode']);
        $this->assertSame([], $tardif['log']);
    }

    public function testStopHorsLectureNEnvoieRienEtStopSuiviDUneDemandeLEnvoie(): void
    {
        $hors = self::scenario('stop_hors_lecture');
        $this->assertSame('listening', $hors['mode']);
        $this->assertTrue($hors['mic'], 'on continue d\'écouter');
        $this->assertSame([], $hors['log'], '« stop » n\'a rien à dire à l\'assistant');

        $suite = self::scenario('stop_suite');
        $this->assertSame('thinking', $suite['mode']);
        $this->assertContains(['submit', 'et pour après-demain ?'], $suite['log'], 'la suite de l\'ordre part comme le tour suivant');
        $this->assertFalse($suite['mic']);

        // Fin de lecture naturelle : même retour à l'écoute qu'après un « stop ».
        $finie = self::scenario('lecture_finie');
        $this->assertSame('listening', $finie['mode']);
        $this->assertTrue($finie['mic']);
        $micro = array_values(array_filter($finie['log'], static fn(array $e): bool => $e[0] === 'mic'));
        $this->assertSame([['mic', 'attach'], ['mic', 'release'], ['mic', 'attach']], $micro);
    }

    // ------------------------------------------------------------------
    // Lecture en direct : la voix n'attend pas la fin de la réponse
    // ------------------------------------------------------------------

    public function testLaVoixDemarreAvantQueLaReponseSoitFinie(): void
    {
        $debut = self::scenario('direct_debut');
        $this->assertSame('speaking', $debut['mode'], 'MIA parle dès la première phrase écrite');
        $this->assertFalse($debut['fini'], 'la réponse s\'écrit encore quand la voix démarre');
        $this->assertSame(1, self::voix($debut['log']));
        // Premier passage sans borne (le serveur le veut court) ; le suivant,
        // demandé PENDANT que le premier joue, borné par sa taille.
        $this->assertSame([0, 0], [$debut['requetes'][0]['from'], $debut['requetes'][0]['max']]);
        $this->assertSame([1, 200], [$debut['requetes'][1]['from'], $debut['requetes'][1]['max']]);

        // Tout ce qui était écrit a été lu, la suite s'écrit encore : MIA garde
        // la parole — la lecture n'est pas finie — sans redemander en boucle.
        $attente = self::scenario('direct_attente');
        $this->assertSame('speaking', $attente['mode']);
        $this->assertSame([], $attente['log']);
        $this->assertSame([], $attente['requetes'], 'sans nouveau fragment, pas de nouvelle demande avant le délai d\'attente');
        $this->assertTrue($attente['passer'], 'Passer reste à portée pendant l\'attente');

        // La fin arrive : elle est lue à son tour, puis MIA écoute.
        $fin = self::scenario('direct_fin');
        $this->assertSame(1, self::voix($fin['log']));
        $this->assertSame([1, 200], [$fin['requetes'][0]['from'], $fin['requetes'][0]['max']], 'la reprise suit le passage déjà lu');
        $this->assertSame('listening', $fin['mode']);
        $this->assertTrue($fin['mic']);
    }

    public function testStopPendantLEcritureArreteAussiLaReponse(): void
    {
        $stop = self::scenario('direct_stop');
        $this->assertSame('listening', $stop['mode'], '« stop » rend la parole tout de suite');
        $this->assertTrue($stop['aborted'], 'la réponse est marquée interrompue');
        $this->assertSame('cancel', $stop['log'][0][0], 'l\'écriture est arrêtée, comme par le bouton Stop du chat');

        // Sa clôture arrive ensuite : elle ne passe pas pour la fin d'un autre tour.
        $cloture = self::scenario('direct_stop_cloture');
        $this->assertSame('listening', $cloture['mode']);
        $this->assertSame([], $cloture['log']);

        // « Stop, et … » : la question suivante attend que la réponse arrêtée
        // soit close — deux réponses ne s'écrivent jamais à la fois.
        $attente = self::scenario('direct_suite_attente');
        $this->assertSame('sending', $attente['mode']);
        $this->assertContains('cancel', array_column($attente['log'], 0));
        $this->assertNotContains('submit', array_column($attente['log'], 0));
        $suite = self::scenario('direct_suite');
        $this->assertContains(['submit', 'et pour la fin du mois ?'], $suite['log']);
        $this->assertSame('thinking', $suite['mode']);
    }

    // ------------------------------------------------------------------
    // Cartes en attente
    // ------------------------------------------------------------------

    public function testUnOuiNApprouveRienEtLeMotDeLActionExecute(): void
    {
        $oui = self::scenario('approbation_oui');
        $this->assertSame('listening', $oui['mode']);
        $this->assertNotContains('resolve', array_column($oui['log'], 0), '« oui » seul : aucune exécution');
        $this->assertNotContains('submit', array_column($oui['log'], 0), '… et aucun message non plus');
        $this->assertContains(['cue', 'vc_say_approve'], $oui['log'], 'MIA redit quoi dire');

        // Une négation n'est pas un ordre : elle part vers l'assistant.
        $negation = self::scenario('approbation_negation');
        $this->assertContains(['submit', 'Ne l\'approuve pas'], $negation['log']);
        $this->assertNotContains('resolve', array_column($negation['log'], 0));

        $approuve = self::scenario('approbation');
        $this->assertSame('listening', $approuve['mode']);
        $this->assertContains(['resolve', 'approve', 'B'], $approuve['log'], 'la carte la plus récente est visée');
        $this->assertNotContains(['resolve', 'approve', 'A'], $approuve['log']);
        $joues = self::annonces($approuve['log']);
        $this->assertSame(['vc_done', 'vc_more'], $joues, 'l\'issue est dite, et la carte qui reste aussi');

        $annule = self::scenario('annulation');
        $this->assertContains(['resolve', 'cancel', 'A'], $annule['log']);
        $this->assertSame(['vc_cancelled'], self::annonces($annule['log']));
    }

    public function testUnEmailNePartQueSurUneConfirmationDite(): void
    {
        $envoyer = self::scenario('mail_envoyer');
        $this->assertSame('send-confirm', $envoyer['cardMode'], '« envoie-le » ouvre la confirmation');
        $this->assertContains(['mail', 'send', null], $envoyer['log']);
        $this->assertNotContains(['mail', 'send-confirm', null], $envoyer['log'], '… et n\'envoie rien');
        $this->assertSame(['vc_send_confirm'], self::annonces($envoyer['log']));

        $oui = self::scenario('mail_oui');
        $this->assertFalse($oui['done'], 'un « oui » nu ne doit JAMAIS envoyer un email');
        $this->assertNotContains('mail', array_column($oui['log'], 0));
        $this->assertSame(['vc_send_confirm'], self::annonces($oui['log']), 'MIA redemande');

        $confirme = self::scenario('mail_confirme');
        $this->assertTrue($confirme['done']);
        $this->assertContains(['mail', 'send-confirm', null], $confirme['log']);
        $this->assertSame(['vc_sent'], self::annonces($confirme['log']));
        $this->assertSame('listening', $confirme['mode']);
    }

    public function testUneRetoucheEtUnBrouillonPassentParLaCarte(): void
    {
        $prereglage = self::scenario('mail_preset');
        $this->assertContains(['mail', 'preset', ['preset' => 'plus_court']], $prereglage['log']);
        $this->assertSame(['vc_improving', 'vc_improved'], self::annonces($prereglage['log']));

        $consigne = self::scenario('mail_consigne');
        $this->assertContains(['mail', 'instruct', ['instruction' => 'Change l\'objet en Réunion de jeudi']], $consigne['log']);

        $brouillon = self::scenario('mail_brouillon');
        $this->assertContains(['mail', 'approve', null], $brouillon['log']);
        $this->assertSame(['vc_draft'], self::annonces($brouillon['log']));

        // Aucun de ces ordres n'est parti comme un message.
        foreach (['mail_preset', 'mail_consigne', 'mail_brouillon', 'mail_envoyer', 'mail_confirme', 'approbation', 'annulation'] as $nom) {
            $this->assertNotContains('submit', array_column(self::scenario($nom)['log'], 0), $nom);
        }
    }

    // ------------------------------------------------------------------
    // Fin de conversation, réglage de l'interruption
    // ------------------------------------------------------------------

    public function testAuRevoirTermineLaConversation(): void
    {
        $fin = self::scenario('au_revoir');
        $this->assertSame('off', $fin['mode']);
        $this->assertFalse($fin['mic']);
        $this->assertFalse($fin['body'], 'hors conversation, les cartes ne promettent plus rien à la voix');
        $this->assertSame(['vc_bye'], self::annonces($fin['log']));
        $this->assertNotContains('submit', array_column($fin['log'], 0));
    }

    public function testLInterruptionEstUnReglageDeLAppareil(): void
    {
        $reglage = self::scenario('reglage');
        $this->assertFalse($reglage['bargeIn']);
        $this->assertFalse($reglage['stored'], 'mémorisé pour cet appareil');
        $this->assertSame('false', $reglage['pressed']);

        // Éteinte : le micro reste FERMÉ pendant la lecture (le correctif du volume sur iOS).
        $lecture = self::scenario('lecture_sans_interruption');
        $this->assertSame('speaking', $lecture['mode']);
        $this->assertFalse($lecture['mic']);
        $this->assertNotContains(['mic', 'attach'], $lecture['log']);

        // Défaut : allumée partout, sauf sur iPhone et iPad.
        $this->assertTrue(self::scenario('defauts')['ordinateur']);
        $this->assertFalse(self::executer('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', 'defauts')['defauts']['ordinateur']);
    }

    // ------------------------------------------------------------------
    // Harnais
    // ------------------------------------------------------------------

    /**
     * Annonces parlées JOUÉES, dans l'ordre (les téléchargements anticipés
     * ne comptent pas).
     *
     * @param  list<array<int, mixed>> $log
     * @return list<string>
     */
    private static function annonces(array $log): array
    {
        $out = [];
        foreach ($log as $e) {
            if ($e[0] === 'play' && preg_match('/^blob:cue-(vc_[a-z_]+):/', (string)$e[1], $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /**
     * Passages de la VOIX joués (ni repères, ni annonces).
     *
     * @param list<array<int, mixed>> $log
     */
    private static function voix(array $log): int
    {
        return count(array_filter($log, static fn(array $e): bool => $e[0] === 'play' && str_starts_with((string)$e[1], 'blob:speech')));
    }

    /** @return array<string, mixed> */
    private static function scenario(string $nom): array
    {
        $resultats = self::executer('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');
        if (!isset($resultats[$nom])) {
            self::fail("Scénario « $nom » absent de la sortie du harnais.");
        }
        return $resultats[$nom];
    }

    /**
     * `$portee` : `tout` rejoue tous les scénarios ; `defauts` s'arrête au
     * réglage par défaut de l'appareil (la lecture en direct attend de vrais
     * délais : inutile de la rejouer pour lire une valeur).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function executer(string $userAgent, string $portee = 'tout'): array
    {
        $cle = $userAgent . '|' . $portee;
        if (isset(self::$scenarios[$cle])) {
            return self::$scenarios[$cle];
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $fichier = tempnam(sys_get_temp_dir(), 'mia-voice-flow-');
        file_put_contents($fichier, self::HARNAIS);
        try {
            $processus = proc_open(
                [$node, $fichier, ROOT, $userAgent, $portee],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $tubes
            );
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

        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("La conversation vocale a échoué sous node (code $code) :\n" . $erreurs);
        }
        return self::$scenarios[$cle] = $resultats;
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

    /**
     * Le banc : tout ce que l'IIFE touche hors d'elle-même est factice, le
     * reste est le code livré. Deux marqueurs bornent ce qui est extrait de
     * `chat.js` — s'ils bougent, le harnais le dit (« marqueur absent »)
     * plutôt que de rester au vert sur du code qui n'est plus celui du chat.
     */
    private const HARNAIS = <<<'JS'
        'use strict';
        const fs = require('fs');
        const ROOT = process.argv[2];
        const chat = fs.readFileSync(ROOT + '/public/assets/js/chat.js', 'utf8');
        require(ROOT + '/public/assets/js/voice-commands.js');

        function between(src, from, to) {
            const a = src.indexOf(from);
            if (a < 0) throw new Error('marqueur absent : ' + from);
            const b = src.indexOf(to, a);
            if (b < 0) throw new Error('marqueur absent : ' + to);
            return src.slice(a, b);
        }
        const bloc = between(chat, '   Conversation vocale — écouter → envoyer → MIA répond → lire', "// ---- Cartes d'approbation Google Drive en attente");
        const iife = bloc.slice(bloc.indexOf('(() => {'));
        const cartesVoix = between(chat, "/** Annonce d'une issue d'exécution", "/**\n * Re-affiche les cartes d'approbation encore en attente");

        // ---------------------------------------------------------- bords factices
        const log = [];
        const els = {};
        function el(id) {
            if (els[id]) return els[id];
            const listeners = {};
            const classes = new Set();
            return els[id] = {
                id, hidden: false, title: '', textContent: '', value: '100', disabled: false, dataset: {},
                classList: {
                    toggle(c, on) { (on === undefined ? !classes.has(c) : on) ? classes.add(c) : classes.delete(c); },
                    add(c) { classes.add(c); }, remove(c) { classes.delete(c); }, contains(c) { return classes.has(c); },
                },
                setAttribute(k, v) { this['@' + k] = String(v); },
                getAttribute(k) { return this['@' + k] ?? null; },
                addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
                fire(type) { (listeners[type] || []).forEach((fn) => fn({ key: '', preventDefault() {} })); },
            };
        }
        const docListeners = {};
        let spokenRaw = '';
        // node < 19 n'a pas de CustomEvent global.
        if (typeof globalThis.CustomEvent === 'undefined') {
            globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = (init || {}).detail; } };
        }
        globalThis.window = globalThis;
        globalThis.document = {
            hidden: false,
            documentElement: { lang: 'fr' },
            body: el('body'),
            getElementById: (id) => el(id),
            addEventListener(type, fn) { (docListeners[type] = docListeners[type] || []).push(fn); },
            dispatchEvent(ev) { (docListeners[ev.type] || []).forEach((fn) => fn(ev)); return true; },
            querySelector: () => ({ dataset: { raw: spokenRaw } }),
        };
        Object.defineProperty(globalThis, 'navigator', {
            value: { userAgent: process.argv[3], platform: 'MacIntel', maxTouchPoints: 0 }, configurable: true,
        });
        const store = {};
        globalThis.localStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } };
        globalThis.CURRENT_USER_ID = 7;
        globalThis.VOICE_PREFS = { rate: 100, length: 'bref' };
        globalThis.t = (s) => String(s);
        globalThis.showToast = (msg, kind) => log.push(['toast', kind, msg]);
        globalThis.csrfToken = 'x';
        globalThis.convId = () => '42';
        globalThis.handleQuotaExceededPayload = () => false;
        globalThis.msgInput = { value: '', dispatchEvent() {} };
        console.info = () => {};

        // Générations : chaque message envoyé en ouvre une, dont le texte arrive
        // par passages (`ready`) et se termine (`final`). Le serveur factice de
        // la lecture en direct rend l'audio d'un passage prêt, 202 tant que le
        // suivant s'écrit, 204 une fois tout lu.
        const jobs = {};
        let jobSeq = 0;
        let current = null;      // flux de la réponse du dernier message envoyé
        const speakLog = [];     // demandes de lecture (à part : le journal principal ne bouge pas)
        globalThis.activeStreams = new Map();
        globalThis.submitForm = async () => {
            log.push(['submit', msgInput.value]);
            msgInput.value = '';
            const jobId = 'J' + (++jobSeq);
            jobs[jobId] = { ready: 0, final: false };
            current = { jobId, rawText: '', done: false, aborted: false };
            activeStreams.set('42', current);
            return true;
        };
        function speakResponse(jobId, body) {
            const job = jobs[jobId];
            const from = Number(body.get('from') || 0);
            speakLog.push({ job: jobId, from, max: Number(body.get('max') || 0) });
            const headers = {};
            const reponse = (status) => ({
                status, ok: status >= 200 && status < 300,
                headers: { get: (k) => (k in headers ? headers[k] : null) },
                blob: async () => ({ kind: 'speech' }),
                json: async () => ({}),
            });
            if (from < job.ready) {
                headers['X-Speech-Next'] = String(from + 1);
                headers['X-Speech-Final'] = job.final && from + 1 >= job.ready ? '1' : '0';
                headers['X-Speech-Chars'] = '80';
                return reponse(200);
            }
            headers['X-Speech-Next'] = String(from);
            return reponse(job.final ? 204 : 202);
        }
        // Arrêt d'une génération (« stop » pendant qu'elle s'écrit) : le
        // serveur s'arrête un instant plus tard, et la clôture de son flux le dit.
        globalThis.fetch = async (url) => {
            const m = /^\/jobs\/([^/]+)\/cancel$/.exec(url);
            if (!m) throw new Error('appel inattendu : ' + url);
            log.push(['cancel', m[1]]);
            const stream = current && current.jobId === m[1] ? current : null;
            setTimeout(() => {
                if (stream) stream.done = true;
                jobs[m[1]].final = true;
                document.dispatchEvent(new CustomEvent('mia:chat-turn-ended', { detail: { convId: '42', jobId: m[1], status: 'cancelled', msgId: null, hadError: false } }));
            }, 30);
            return { ok: true, json: async () => ({ cancelled: true }) };
        };

        // Lecteur : les sons courts finissent seuls ; la VOIX (« speech ») dure
        // jusqu'à ce que le scénario la laisse finir ou que la conversation la coupe.
        let holdSpeech = false;
        globalThis.Audio = class {
            constructor() { this.volume = 1; this.playbackRate = 1; this.src = ''; }
            play() {
                const src = this.src;
                log.push(['play', src]);
                if (!(holdSpeech && src.startsWith('blob:speech'))) {
                    setTimeout(() => { if (this.src === src && this.onended) this.onended(); }, 0);
                }
                return Promise.resolve();
            }
            pause() {}
            load() {}
            removeAttribute() { this.src = ''; }
        };
        let blobSeq = 0;
        URL.createObjectURL = (blob) => 'blob:' + (blob && blob.kind ? blob.kind : 'wav') + ':' + (++blobSeq);
        URL.revokeObjectURL = () => {};
        globalThis.fetchWithSessionGuard = async (url, opts) => {
            const cue = /\/chat\/voice\/cue\?type=([^&]+)/.exec(url);
            if (cue) { log.push(['cue', cue[1]]); return { status: 200, ok: true, blob: async () => ({ kind: 'cue-' + cue[1] }) }; }
            const live = /^\/jobs\/([^/]+)\/speak$/.exec(url);
            if (live) {
                if (opts && opts.signal && opts.signal.aborted) throw new Error('abandon');
                return speakResponse(live[1], opts.body);
            }
            throw new Error('appel inattendu : ' + url);
        };

        // Socle Realtime : seuls l'état du micro et les évènements comptent.
        const mic = { attached: false, opts: null };
        globalThis.createRealtimeTranscriber = (opts) => {
            mic.opts = opts;
            return {
                start: async () => { opts.onStarted(); return true; },
                stop: () => { mic.attached = false; log.push(['mic', 'stop']); },
                attachMic: async () => { mic.attached = true; log.push(['mic', 'attach']); },
                releaseMic: () => { if (mic.attached) log.push(['mic', 'release']); mic.attached = false; },
                isRecording: () => true, isConnecting: () => false, model: () => 'x',
            };
        };

        // Cartes : factices. Leur exécution (resolveDriveAction, runMailDraftAct)
        // aussi — c'est le VRAI runVoiceCardCommand qui les appelle.
        let cards = [];
        function card(kind, extra) {
            const classes = new Set(['drive-confirm-card'].concat(kind === 'mail' ? ['mail-draft-card'] : []));
            return Object.assign({
                kind, title: 'Créer un évènement Google Agenda', dataset: { mode: 'preview' }, done: false,
                classList: { contains: (c) => classes.has(c) },
                scrollIntoView() {},
            }, extra || {});
        }
        globalThis.pendingActionCards = () => cards.filter((c) => !c.done);
        globalThis.voiceCardContext = (c) => (c.kind === 'mail'
            ? { kind: 'mail', mode: c.dataset.mode, recipients: 'Jean Dupont <jean@acme.fr>', presets: ['plus_court', 'plus_formel', 'corriger'], canUndo: false }
            : { kind: 'approval', title: c.title });
        globalThis.resolveDriveAction = async (c, decision) => {
            log.push(['resolve', decision, c.name || '']);
            c.done = true;
            return decision === 'cancel' ? 'cancelled' : 'executed';
        };
        globalThis.runMailDraftAct = async (c, act, btn, extra) => {
            log.push(['mail', act, extra || null]);
            if (act === 'send') { c.dataset.mode = 'send-confirm'; return 'confirm'; }
            if (act === 'send-cancel') { c.dataset.mode = 'preview'; return 'back'; }
            if (act === 'send-confirm' || act === 'approve') { c.done = true; return 'executed'; }
            if (act === 'cancel') { c.done = true; return 'cancelled'; }
            if (act === 'improve') { c.dataset.mode = 'improve'; return 'panel'; }
            if (act === 'panel-close') { c.dataset.mode = 'preview'; return 'closed'; }
            return 'revised';
        };

        (0, eval)('let voiceModeActive = false;\n' + cartesVoix + '\n' + iife.replace(/\}\)\(\);\s*$/, `
            globalThis.__voice = { mode: () => mode, bargeIn: () => bargeIn };
        })();`));

        // ---------------------------------------------------------------- scénarios
        const flush = async (n = 12) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
        let item = 0;
        async function say(text) {
            const id = 'item' + (++item);
            mic.opts.onSpeechStart(id);
            mic.opts.onCompleted(text, id);
            await flush();
        }
        const take = () => log.splice(0, log.length);
        // La lecture redemande un passage « pas encore prêt » au plus tôt
        // 350 ms après la demande précédente : on laisse passer ce délai.
        const settle = async () => { await new Promise((r) => setTimeout(r, 420)); await flush(); };
        // La réponse du dernier message s'écrit : `ready` passages prêts, `final` si elle est finie.
        const write = async (ready, final) => {
            const job = jobs[current.jobId];
            job.ready = ready;
            job.final = !!final;
            current.rawText = spokenRaw;
            document.dispatchEvent(new CustomEvent('mia:chat-progress', { detail: { convId: '42', jobId: current.jobId } }));
            if (final) {
                current.done = true;
                document.dispatchEvent(new CustomEvent('mia:chat-turn-ended', { detail: { convId: '42', jobId: current.jobId, status: 'done', msgId: 1, hadError: false } }));
            }
            await settle();
        };
        // Réponse complète d'un coup (un seul passage).
        const answer = () => write(1, true);
        const voix = (entries) => entries.filter((e) => e[0] === 'play' && String(e[1]).startsWith('blob:speech')).length;

        (async () => {
            const out = { defauts: { ordinateur: globalThis.__voice.bargeIn() } };
            if (process.argv[4] === 'defauts') {
                process.stdout.write(JSON.stringify(out));
                process.exit(0);
            }
            const snap = (name, extra) => {
                out[name] = Object.assign({ mode: globalThis.__voice.mode(), mic: mic.attached, log: take() }, extra || {});
            };

            el('voiceChatBtn').fire('click');
            await flush();
            snap('start', { body: el('body').classList.contains('voice-chat-on') });

            await say('Bonjour, quel temps fait-il ?');
            snap('message');

            holdSpeech = true;
            spokenRaw = 'Il fera beau. Arrête-toi à la boulangerie en rentrant, puis prends le pain.';
            await answer();
            snap('speaking', { live: el('voiceChatLive').textContent });

            await say('C\'est intéressant');
            snap('remarque');
            await say('Arrête-toi à la boulangerie en rentrant');
            snap('echo');
            const tardif = 'item' + (++item);
            mic.opts.onSpeechStart(tardif);             // commence pendant la lecture…
            await say('Stop');
            snap('stop', { live: el('voiceChatLive').textContent });
            mic.opts.onCompleted('Merci à bientôt et bonne route', tardif);   // … s'achève après
            await flush();
            snap('tardif');

            await say('Stop');
            snap('stop_hors_lecture');

            await say('Et demain ?');
            await answer();
            take();
            await say('Stop, et pour après-demain ?');
            snap('stop_suite');
            holdSpeech = false;
            await answer();
            snap('lecture_finie');

            // Lecture EN DIRECT : la voix démarre dès la première phrase écrite,
            // sans attendre la fin de la réponse.
            await say('Raconte-moi la journée de demain');
            take();
            speakLog.length = 0;
            await write(1, false);                  // la première phrase est écrite, la suite s'écrit
            snap('direct_debut', { requetes: speakLog.splice(0), fini: current.done });
            await settle();                         // passage lu ; la suite n'est pas encore là
            snap('direct_attente', { requetes: speakLog.splice(0), passer: !el('voiceChatSkip').hidden });
            await write(2, true);                   // la réponse se termine
            snap('direct_fin', { requetes: speakLog.splice(0) });

            // « Stop » alors que la réponse s'écrit encore : MIA se tait, et
            // l'écriture s'arrête aussi. Sa clôture, qui arrive ensuite, ne
            // passe pas pour la fin d'un autre tour.
            holdSpeech = true;
            await say('Et la semaine prochaine ?');
            await write(1, false);
            take();
            const coupee = current;
            await say('Stop');
            snap('direct_stop', { aborted: coupee.aborted });
            await settle();
            snap('direct_stop_cloture');

            // « Stop, et … » pendant l'écriture : la question suivante attend
            // que la réponse arrêtée soit close — jamais deux à la fois.
            await say('Et le mois prochain ?');
            await write(1, false);
            take();
            await say('Stop, et pour la fin du mois ?');
            snap('direct_suite_attente');
            await settle();
            snap('direct_suite');
            holdSpeech = false;
            await answer();
            take();

            cards = [card('approval', { name: 'A' }), card('approval', { name: 'B' })];
            await say('Oui');
            snap('approbation_oui');
            await say('Ne l\'approuve pas');
            snap('approbation_negation');
            document.dispatchEvent(new CustomEvent('mia:chat-turn-ended', { detail: { convId: '42', jobId: current.jobId, status: 'cancelled', msgId: null, hadError: false } }));
            await flush();
            take();
            await say('Approuve');
            snap('approbation');
            await say('Annule tout');
            snap('annulation');

            const mail = card('mail');
            cards = [mail];
            await say('Envoie-le');
            snap('mail_envoyer', { cardMode: mail.dataset.mode });
            await say('Oui');
            snap('mail_oui', { done: mail.done });
            await say('Confirme');
            snap('mail_confirme', { done: mail.done });

            cards = [card('mail')];
            await say('Plus court');
            snap('mail_preset');
            await say('Change l\'objet en Réunion de jeudi');
            snap('mail_consigne');
            await say('Crée le brouillon');
            snap('mail_brouillon');

            await say('Au revoir');
            snap('au_revoir', { body: el('body').classList.contains('voice-chat-on') });

            // Interruption éteinte : le micro reste fermé pendant la lecture.
            if (globalThis.__voice.bargeIn()) el('voiceChatBargeIn').fire('click');
            out.reglage = {
                bargeIn: globalThis.__voice.bargeIn(),
                stored: (JSON.parse(store['voicePrefs:7'] || '{}')).bargeIn ?? null,
                pressed: el('voiceChatBargeIn')['@aria-pressed'],
            };
            el('voiceChatBtn').fire('click');
            await flush();
            await say('Bonjour');
            take();
            holdSpeech = true;
            await answer();
            snap('lecture_sans_interruption');
            el('voiceChatEnd').fire('click');
            await flush();

            process.stdout.write(JSON.stringify(out));
            process.exit(0);
        })().catch((e) => { console.error(e); process.exit(1); });
        JS;
}
