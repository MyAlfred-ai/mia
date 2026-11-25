<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Écriture fluide d'une réponse : vitesse, blocs figés, syntaxe retenue, et
 * chaîne serveur qui alimente tout cela.
 *
 * Le défaut mesuré au départ (faux modèle à 350 caractères/s, vraie pile) : 13
 * mises à jour pour toute une réponse, ~95 caractères d'un coup toutes les
 * 308 ms, et ~430 ms de retard avant le premier mot, hors modèle. Ces tests
 * rejouent le vrai stream-render.js sous node, avec une horloge et un DOM
 * minimal : une lecture du code ne dit pas si la vitesse tient face à des
 * rafales, ni si un bloc terminé reste le même nœud.
 *
 * Sans node (conteneur applicatif), la partie JavaScript est SKIPPÉE ; elle
 * tourne sur le poste de développement et en CI.
 */
final class StreamRenderTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    // ---------------------------------------------------------------- Vitesse

    public function testUnDebitRegulierSEcritSansSautNiArret(): void
    {
        $r = self::js()['regulier'];
        $this->assertSame(0, $r['arrets'], 'Plus aucune image sans nouvelle lettre, une fois lancé.');
        $this->assertLessThanOrEqual(12, $r['pas_max'], 'Quelques lettres par image, jamais un saut.');
        $this->assertLessThanOrEqual(260, $r['retard_max_ms'], 'Le texte ne prend pas de retard sur le modèle.');
    }

    public function testUneRafaleSEtaleDansLeTemps(): void
    {
        $r = self::js()['rafales'];
        $this->assertSame(0, $r['arrets'], 'Entre deux rafales espacées, l\'écriture ne s\'arrête pas.');
        $this->assertLessThanOrEqual(3.2, $r['ecart_vitesse'], 'La vitesse n\'oscille pas entre le sprint et l\'arrêt.');
    }

    public function testUnGrosPaquetSeRattrapeVite(): void
    {
        $r = self::js();
        $this->assertLessThan(900, $r['paquet_ms'], 'Un texte reçu d\'un coup ne se fait pas attendre.');
        $this->assertLessThan(300, $r['fin_ms'], 'Le dernier paquet se termine aussitôt, la réponse est finie.');
    }

    public function testUnEmojiNEstJamaisCoupe(): void
    {
        $r = self::js();
        $this->assertSame(1, $r['surrogate']);
        $this->assertSame(2, $r['surrogate_entier']);
    }

    // ---------------------------------------------------------------- Blocs

    public function testSeulUnBlocTermineSeFige(): void
    {
        $r = self::js()['blocs'];
        $this->assertSame(['cut' => 6, 'inFence' => false], $r['paragraphe']);
        $this->assertSame(0, $r['liste_aeree']['cut'], 'Une liste aérée continue : la couper donnerait deux listes.');
        $this->assertSame(5, $r['apres_liste']['cut'], 'Un paragraphe en colonne 0 referme la liste.');
        $this->assertSame(0, $r['chiffre_seul']['cut'], '« 1 » seul peut devenir un item de liste.');
        $this->assertTrue($r['bloc_ouvert']['inFence']);
        $this->assertSame(0, $r['bloc_ouvert']['cut'], 'Jamais de coupe dans un bloc de code.');
        $this->assertSame(13, $r['apres_bloc']['cut']);
        $this->assertTrue($r['fichier_imbrique']['inFence'], 'Le bloc intérieur d\'un fichier déclaré ne le referme pas.');
        $this->assertFalse($r['code_en_ligne']['inFence'], '« ```x``` » en début de ligne est du code en ligne.');
    }

    /**
     * Chaque longueur d'une vraie réponse est rejouée : à aucun instant l'écran
     * ne montre une syntaxe en cours, et le texte final est complet.
     */
    public function testAucuneSyntaxeNeSeVoitPendantLEcriture(): void
    {
        $r = self::js()['prefixes'];
        $this->assertGreaterThan(300, $r['longueurs']);
        $this->assertSame([], $r['fautes']);
        $this->assertTrue($r['coupes_croissantes']);
        $this->assertTrue($r['final_complet']);
    }

    public function testLaSyntaxeEnCoursEstRetenueOuRefermee(): void
    {
        $r = self::js()['retenues'];
        $this->assertSame('Bonjour **gra**', $r['gras']);
        $this->assertSame('un ', $r['lien']);
        $this->assertSame('', $r['titre']);
        $this->assertSame('', $r['puce']);
        $this->assertSame('', $r['bloc']);
        $this->assertSame('', $r['entete_tableau']);
        $this->assertSame('', $r['separateur_partiel']);
        $this->assertStringContainsString('| 1 | 2', $r['ligne_tableau']);
        $this->assertSame('2 * 3 = 6', $r['multiplication']);
        $this->assertSame('a*b', $r['intramot']);
        $this->assertSame('mon_fichier', $r['souligne']);
        $this->assertSame('`co`', $r['code']);
        $this->assertSame('Texte ', $r['citation']);
        $this->assertSame('x ', $r['balise']);
    }

    // ---------------------------------------------------------------- Écran

    public function testUnBlocTermineResteLeMemeNoeud(): void
    {
        $r = self::js()['ecran'];
        $this->assertGreaterThan(20, $r['etapes'], 'Le texte apparaît par petites étapes, pas en quelques sauts.');
        $this->assertTrue($r['croissant']);
        $this->assertTrue($r['premier_conserve'], 'Le DOM d\'un paragraphe terminé n\'est plus touché.');
        $this->assertSame(2, $r['paragraphes']);
    }

    public function testLeCurseurSuitLeTexteEtDisparaitALaFin(): void
    {
        $r = self::js()['ecran'];
        $this->assertSame('P', $r['curseur_dans']);
        $this->assertTrue($r['curseur_dernier'], 'Le curseur suit la dernière lettre.');
        $this->assertTrue($r['attente'], 'Plus rien n\'arrive : le curseur se met à respirer.');
        $this->assertFalse($r['attente_apres_texte'], 'Le texte reprend : le curseur redevient plein.');
        $this->assertTrue($r['ecrit_avant_fin'], 'La fin de réponse attend les dernières lettres.');
        $this->assertSame(0, $r['curseurs_finaux']);
    }

    public function testUnTexteRemplaceRepartDeZero(): void
    {
        $this->assertSame('Autre', self::js()['ecran']['remplace']);
    }

    public function testLAttenteResteSobre(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach (['.stream-converge', '.stream-phase-label', '.stream-caret', '@keyframes stream-converge-turn', '@keyframes stream-converge-join', '@keyframes stream-caret-pulse'] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.stream-phase,/', $css, 'Mouvement réduit respecté.');
        $this->assertStringNotContainsString('.msg-content.streaming::after', $css, 'L\'ancien curseur tombait seul sur une ligne, sous le dernier paragraphe.');

        // Une mini pieuvre animée a été retirée à la demande : jugée trop enfantine.
        foreach (['public/assets/css/main.css', 'public/assets/js/chat.js', 'public/assets/js/stream-render.js'] as $fichier) {
            $source = self::lire($fichier);
            $this->assertStringNotContainsString('mia-octo', $source, $fichier);
            $this->assertStringNotContainsString('octopus', $source, $fichier);
            $this->assertStringNotContainsString('pieuvre', $source, $fichier);
        }
        $js = self::lire('public/assets/js/chat.js');
        $construction = substr($js, (int)strpos($js, 'function buildStreamPhaseEl'), 500);
        $this->assertStringContainsString('class="stream-converge" aria-hidden="true"', $construction);
        $this->assertSame(1, substr_count($js, 'class="stream-converge"'), 'Un seul endroit fabrique l\'animation.');
        // Aux couleurs de l'organisation, et un triangle immobile si le mouvement est réduit.
        $bloc = substr($css, (int)strpos($css, '.stream-converge {'));
        $this->assertStringContainsString('background: var(--accent);', substr($bloc, 0, 700));
        $this->assertStringContainsString('transform: translateY(-.36em);', substr($bloc, 0, 700));
        $this->assertStringContainsString('.stream-converge > span > span,', $css);
    }

    // ---------------------------------------------------------------- Câblage

    public function testLeModuleSeChargeAvantLeChat(): void
    {
        $page = self::lire('templates/chat/index.php');
        $this->assertGreaterThan(0, strpos($page, '/assets/js/stream-render.js'));
        $this->assertLessThan(strpos($page, '/assets/js/chat.js?v='), strpos($page, '/assets/js/stream-render.js'));
    }

    public function testToutPasseParLeModule(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('window.MiaStream.feed(contentEl', $js);
        $this->assertStringContainsString('follow: glideToBottom', $js);
        $this->assertStringContainsString('window.MiaStream.swap(mdEl, renderMarkdown(raw))', $js);
        $this->assertStringContainsString("scheduleStreamingMarkdownRender(newEl, { instant: true })", $js, 'Retour sur un fil : pas de réécriture de ce qui est déjà reçu.');
        $this->assertStringNotContainsString('__streamRenderScheduled', $js, 'Plus de rendu complet à chaque fragment.');
    }

    /** `scroll-behavior: smooth` relancé à chaque image ne se terminerait jamais. */
    public function testLeFilGlisseSansAnimationRelancee(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("chatMsgs.scrollTo({ top: top, behavior: 'instant' })", $js);
        $glide = substr($js, (int)strpos($js, 'function glideToBottom'), 700);
        $this->assertStringContainsString('FOLLOW_BOTTOM_THRESHOLD', $glide, 'Sinon l\'écouteur de défilement se croirait abandonné.');
    }

    public function testUneReponseSeConclutUneFoisEcrite(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $attache = substr($js, (int)strpos($js, 'function attachChatJob'), 1800);
        $this->assertStringContainsString('(json.done || json.job_end) && window.MiaStream.isWriting(state.el)', $attache);
        $this->assertStringContainsString('state.held', $attache, 'Les évènements arrivés entre-temps gardent leur ordre.');
        $this->assertStringContainsString('await new Promise((written) => window.MiaStream.drain(newContent, written))', $js, 'La régénération aussi.');
    }

    /** L'écran réagit avant le réseau, et se défait si le serveur refuse. */
    public function testLeMessageSAfficheAvantTouteRequete(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $envoi = substr($js, (int)strpos($js, 'async function submitForm'));
        $envoi = substr($envoi, 0, (int)strpos($envoi, 'function notifyChatTurnEnded'));

        $bulle = strpos($envoi, "appendMessage('user', text, snapshotFiles)");
        $this->assertLessThan(strpos($envoi, 'await createConversation()'), $bulle);
        $this->assertLessThan(strpos($envoi, '/send`'), $bulle);
        // Vérification de session réservée aux outils : le texte passe par /send.
        $this->assertSame(2, substr_count($envoi, 'ensureSessionActive()'));
        $this->assertLessThan($bulle, strpos($envoi, 'if (!await ensureSessionActive()) return false;'));
        $this->assertStringContainsString("response.status === 403 && !await ensureSessionActive()", $envoi, 'La barrière CSRF répond avant l\'authentification.');
        $this->assertStringContainsString('cancelTurn()', $envoi);
        $this->assertStringContainsString('restoreFiles(snapshotFiles)', $envoi, 'Le texte et les fichiers reviennent dans le champ.');
    }

    public function testLaPremiereConversationNeFabriquePlusDePage(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $creation = substr($js, (int)strpos($js, 'async function createConversation'), 1800);
        $this->assertStringContainsString("'Accept': 'application/json'", $creation);
        $this->assertStringNotContainsString("redirect: 'follow'", $creation);

        $php = self::lire('src/Controllers/ChatController.php');
        $create = substr($php, (int)strpos($php, 'public function create(): void'), 4200);
        $this->assertStringContainsString("\$this->json(['id' => (int)\$conv['id']])", $create);
        $this->assertStringContainsString("header('Location: /chat/' . \$conv['id'])", $create, 'Les formulaires classiques restent redirigés.');
    }

    public function testLaChaineServeurNeRetientPlusLeTexte(): void
    {
        $worker = self::lire('scripts/worker.php');
        $this->assertSame(1, preg_match('/const IDLE_SLEEP_US\s+= (\d[\d_]*);/', $worker, $m));
        $this->assertLessThanOrEqual(150_000, (int)str_replace('_', '', $m[1]), 'Ce délai s\'ajoute à chaque message.');
        $this->assertStringContainsString('JobQueue::hasPending() ? JobQueue::claimNext() : null', $worker, 'Une boucle fréquente ne pose aucun verrou.');

        $contexte = self::lire('src/Jobs/JobContext.php');
        $this->assertSame(1, preg_match('/FLUSH_MS\s+= (\d+);/', $contexte, $m));
        $this->assertLessThanOrEqual(80, (int)$m[1]);

        require_once ROOT . '/src/Controllers/JobController.php';
        $this->assertLessThanOrEqual(30_000, JobController::pollDelay(true, 0.0), 'Le texte coule : relecture immédiate.');
        $this->assertLessThanOrEqual(60_000, JobController::pollDelay(false, 3.0), 'Le modèle réfléchit : le premier mot ne doit pas attendre.');
        $this->assertGreaterThanOrEqual(200_000, JobController::pollDelay(false, 60.0), 'Un long silence ne coûte presque plus rien.');
    }

    public function testLesActionsAttendentLaFinDeLEcriture(): void
    {
        $this->assertStringContainsString(
            '.msg-content.streaming ~ .msg-actions { opacity: 0; pointer-events: none; }',
            self::lire('public/assets/css/main.css')
        );
    }

    public function testDocumentation(): void
    {
        $this->assertStringContainsString('id: reponses-fluides', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('lettre à lettre', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('lettre à lettre', self::lire('docs/user/fonctionnalites/conversations/mener-une-conversation.md'));
    }

    // ---------------------------------------------------------------- Exécution

    /** @return array<string, mixed> */
    private static function js(): array
    {
        if (self::$sortie !== null) {
            return self::$sortie;
        }
        $node = null;
        foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $candidat) {
            if (is_file($candidat) && is_executable($candidat)) {
                $node = $candidat;
                break;
            }
        }
        if ($node === null) {
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
                $candidat = rtrim($dossier, '/') . '/node';
                if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                    $node = $candidat;
                    break;
                }
            }
        }
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $script = self::harnais() . self::lire('public/assets/js/stream-render.js') . "\n" . self::scenarios();
        $fichier = tempnam(sys_get_temp_dir(), 'mia-ecriture-js-');
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
        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("stream-render.js a échoué sous node (code $code) :\n" . $erreurs);
        }

        return self::$sortie = $resultats;
    }

    /** DOM et horloge minimaux, avant le chargement du module. */
    private static function harnais(): string
    {
        return <<<'JS'
            'use strict';
            let clock = 1000;
            const frames = [];
            const timers = [];
            Object.defineProperty(globalThis, 'performance', { value: { now: () => clock }, configurable: true });
            globalThis.requestAnimationFrame = (cb) => { frames.push(cb); return frames.length; };
            globalThis.cancelAnimationFrame = () => { frames.length = 0; };
            globalThis.setTimeout = (cb, ms) => { const t = { cb, at: clock + ms }; timers.push(t); return t; };
            globalThis.clearTimeout = (t) => { const i = timers.indexOf(t); if (i >= 0) timers.splice(i, 1); };
            function step(dt = 16) {
                clock += dt;
                timers.filter((t) => t.at <= clock).forEach((t) => { timers.splice(timers.indexOf(t), 1); t.cb(); });
                frames.splice(0).forEach((cb) => cb(clock));
            }
            globalThis.Node = { ELEMENT_NODE: 1, TEXT_NODE: 3 };
            class N {
                constructor(type, name, value = null) {
                    this.nodeType = type; this.nodeName = name; this.nodeValue = value;
                    this.childNodes = []; this.parentNode = null; this.attrs = {}; this.classes = new Set();
                    this.isConnected = true;
                    const self = this;
                    this.classList = { add: (c) => self.classes.add(c), remove: (c) => self.classes.delete(c) };
                }
                get attributes() { return Object.keys(this.attrs).map((name) => ({ name, value: this.attrs[name] })); }
                hasAttribute(k) { return k in this.attrs; }
                getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
                setAttribute(k, v) { this.attrs[k] = String(v); }
                removeAttribute(k) { delete this.attrs[k]; }
                set className(v) { this.classes = new Set(String(v).split(' ')); }
                get lastChild() { return this.childNodes[this.childNodes.length - 1] || null; }
                sibling(d) { if (!this.parentNode) return null; const l = this.parentNode.childNodes; return l[l.indexOf(this) + d] || null; }
                get nextSibling() { return this.sibling(1); }
                get previousSibling() { return this.sibling(-1); }
                appendChild(c) { c.remove(); c.parentNode = this; this.childNodes.push(c); return c; }
                remove() { if (this.parentNode) { const l = this.parentNode.childNodes; l.splice(l.indexOf(this), 1); this.parentNode = null; } }
                replaceWith(o) { const l = this.parentNode.childNodes; o.remove(); o.parentNode = this.parentNode; l[l.indexOf(this)] = o; this.parentNode = null; }
                get textContent() { return this.nodeType === 3 ? this.nodeValue : this.childNodes.map((c) => c.textContent).join(''); }
                set textContent(v) { this.childNodes.slice().forEach((c) => c.remove()); if (v !== '') this.appendChild(new N(3, '#text', v)); }
                set innerHTML(html) { this.textContent = ''; parseInto(this, html); }
                get content() { return this; }
            }
            function parseInto(root, html) {
                const stack = [root];
                html.replace(/<(\/?)([a-zA-Z0-9]+)[^>]*>|([^<]+)/g, (all, close, tag, text) => {
                    const top = stack[stack.length - 1];
                    if (text) top.appendChild(new N(3, '#text', text));
                    else if (close) stack.pop();
                    else { const el = new N(1, tag.toUpperCase()); top.appendChild(el); if (!/^(path|circle|ellipse)$/i.test(tag)) stack.push(el); }
                    return '';
                });
            }
            globalThis.document = { hidden: false, createElement: (tag) => new N(1, tag.toUpperCase()) };
            globalThis.window = globalThis;
            globalThis.MiaFiles = { parseInfo: (info) => ({ name: /fichier="([^"]+)"/.test(info) ? RegExp.$1 : '' }) };

            JS;
    }

    private static function scenarios(): string
    {
        return <<<'JS'

            const S = globalThis.MiaStream;
            const out = {};

            // --- Vitesse : un flux simulé, images de 16 ms.
            function simule(paquets, duree) {
                const v = S.newSpeed();
                let t = 0, prev = 0, reste = 0, arrets = 0, pasMax = 0, retard = 0, parti = false;
                const pas = [];
                const file = paquets.slice();
                while (t < duree) {
                    t += 16;
                    while (file.length && file[0][0] <= t) {
                        const p = file.shift();
                        S.noteArrival(v, 1000 + p[0], p[1]);
                        v.target += p[1];
                    }
                    S.advance(v, 16);
                    const pasCourant = Math.floor(v.shown) - Math.floor(prev);
                    prev = v.shown;
                    if (v.shown > 0) parti = true;
                    if (t > 600 && file.length) {
                        pas.push(v.shown - reste);
                        if (v.shown === reste) arrets++;
                        pasMax = Math.max(pasMax, pasCourant);
                        retard = Math.max(retard, (v.target - v.shown) / Math.max(v.rate, .01));
                    }
                    reste = v.shown;
                }
                return { arrets, pasMax, retard, pas };
            }
            const regulier = [];
            for (let t = 0; t < 6000; t += 60) regulier.push([t, 20]);
            const a = simule(regulier, 6000);
            out.regulier = { arrets: a.arrets, pas_max: a.pasMax, retard_max_ms: Math.round(a.retard) };

            const rafales = [];
            for (let t = 0; t < 9000; t += 450) rafales.push([t, 300]);
            const b = simule(rafales, 9000);
            const pas = b.pas.slice(-200).sort((x, y) => x - y);
            out.rafales = { arrets: b.arrets, ecart_vitesse: pas[pas.length - 1] / Math.max(pas[0], .01) };

            const d = S.newSpeed();
            S.noteArrival(d, 1000, 20000); d.target = 20000;
            let ms = 0;
            while (d.shown < d.target * .99 && ms < 5000) { ms += 16; S.advance(d, 16); }
            out.paquet_ms = ms;

            const f = S.newSpeed();
            f.target = 300; f.finishing = true;
            ms = 0;
            while (f.shown < f.target && ms < 5000) { ms += 16; S.advance(f, 16); }
            out.fin_ms = ms;

            out.surrogate = S.safeLength('a😀', 2);
            out.surrogate_entier = S.safeLength('😀', 2);

            // --- Blocs
            out.blocs = {
                paragraphe: S.scanBlocks('para\n\nsuite', 0),
                liste_aeree: S.scanBlocks('- a\n\n- b', 0),
                apres_liste: S.scanBlocks('- a\n\nfin', 0),
                chiffre_seul: S.scanBlocks('fin\n\n1', 0),
                bloc_ouvert: S.scanBlocks('```py\nx\n\n\ny', 0),
                apres_bloc: S.scanBlocks('```py\nx\n```\n\nfin', 0),
                fichier_imbrique: S.scanBlocks('```md fichier="a.md"\n# T\n\n```py\nx\n```\n\nsuite\n', 0),
                code_en_ligne: S.scanBlocks('```x``` fin\n\nsuite', 0),
            };

            // --- Chaque longueur d'une réponse réelle
            const reponse = [
                'Bonjour ! Voici **le point** et *une nuance* avec `du code`.', '',
                '## Ce qui avance', '',
                '- **Maquettes** : validées.', '- Doc : [ici](https://example.com/doc).', '',
                '| Lot | Prévu |', '|---|---|', '| Conception | 40 j |', '| Recette | 30 j |', '',
                '```sql', 'SELECT 1;', '', 'SELECT 2;', '```', '',
                '1. Finaliser', '2. Relire', '',
                '> Attention au **9 novembre**.', '',
                'Fin de la réponse.',
            ].join('\n');
            const fautes = [];
            let coupe = 0, croissant = true, longueurs = 0;
            for (let n = 1; n <= reponse.length; n++) {
                const p = S.plan(reponse.slice(0, n), coupe, false);
                if (p.cut < coupe) croissant = false;
                coupe = p.cut;
                longueurs++;
                const ligne = p.tail.split('\n').pop();
                const ouvert = reponse.slice(0, n).split('```').length % 2 === 0;
                if (ouvert || ligne.trim() === '```') continue;
                const motif = /^(#{1,6}|[-*+]|\d+[.)]?|>)\s*$/.test(ligne)
                    || /\]\([^)]*$/.test(ligne)
                    || (ligne.split('**').length % 2 === 0)
                    || (ligne.replace(/\*\*/g, '').split('*').length % 2 === 0 && !/^\s*\*\s/.test(ligne))
                    || (ligne.split('`').length % 2 === 0)
                    || /^\|-/.test(ligne);
                if (motif) fautes.push(reponse.slice(0, n).slice(-30) + ' => ' + ligne);
            }
            const finale = S.plan(reponse, coupe, true);
            out.prefixes = { longueurs, fautes: fautes.slice(0, 5), coupes_croissantes: croissant, final_complet: reponse.slice(0, finale.cut) + finale.tail === reponse };

            const queue = (t) => S.plan(t, 0, false).tail;
            out.retenues = {
                gras: queue('Bonjour **gra'), lien: queue('un [lien](https://ex'), titre: queue('##'), puce: queue('-'),
                bloc: queue('```py'), entete_tableau: queue('| a | b |\n'), separateur_partiel: queue('| a | b |\n|--'),
                ligne_tableau: queue('| a | b |\n|---|---|\n| 1 | 2'), multiplication: queue('2 * 3 = 6'), intramot: queue('a*b'),
                souligne: queue('mon_fichier'), code: queue('`co'), citation: queue('Texte cite'), balise: queue('x <di'),
            };

            // --- Écran : le vrai cycle, sur un DOM minimal
            const conteneur = new (document.createElement('div').constructor)(1, 'DIV');
            const md = document.createElement('span');
            conteneur.appendChild(md);
            conteneur.querySelector = (s) => (s === '.md-render' ? md : null);
            S.configure({
                render: (t) => t.split(/\n{2,}/).filter((b) => b.trim() !== '').map((b) => '<p>' + b.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, ' ') + '</p>').join('\n'),
                follow: null,
            });
            const texte = 'Premier paragraphe entier, déjà terminé.\n\nSecond paragraphe qui s’écrit **encore** à ce moment.';
            let recu = texte.slice(0, 30);
            S.feed(conteneur, recu, {});
            let etapes = 0, precedent = -1, croit = true, premier = null;
            for (let i = 0; i < 140; i++) {
                if (i % 4 === 0 && recu.length < texte.length) { recu = texte.slice(0, recu.length + 8); S.feed(conteneur, recu, {}); }
                step();
                const l = md.textContent.length;
                if (l < precedent) croit = false;
                if (l !== precedent) etapes++;
                precedent = l;
                if (!premier && md.childNodes[0] && md.childNodes[0].nodeName === 'P') premier = md.childNodes[0];
            }
            const curseur = conteneur.__stream.cursor;
            const ecran = {
                etapes, croissant: croit,
                premier_conserve: premier !== null && md.childNodes[0] === premier,
                paragraphes: md.childNodes.filter((c) => c.nodeName === 'P').length,
                curseur_dans: curseur.parentNode && curseur.parentNode.nodeName,
                curseur_dernier: curseur.parentNode === md.childNodes[md.childNodes.length - 1],
            };
            for (let i = 0; i < 60; i++) step(16);
            ecran.attente = curseur.classes.has('is-waiting');
            S.feed(conteneur, texte + ' Suite', {});
            ecran.attente_apres_texte = curseur.classes.has('is-waiting');

            let conclu = false, ecrit = false;
            S.feed(conteneur, texte + ' Suite de la réponse après la pause, encore quelques mots.', {});
            S.drain(conteneur, () => { conclu = true; ecrit = md.textContent.includes('quelques mots.'); });
            for (let i = 0; i < 40 && !conclu; i++) step();
            ecran.ecrit_avant_fin = conclu && ecrit;
            S.stop(conteneur);
            ecran.curseurs_finaux = curseur.parentNode ? 1 : 0;

            S.feed(conteneur, 'Un long texte déjà là', { instant: true });
            step();
            S.feed(conteneur, 'Autre', { instant: true });
            step();
            ecran.remplace = md.textContent;
            S.stop(conteneur);
            out.ecran = ecran;

            process.stdout.write(JSON.stringify(out));
            JS;
    }
}
