<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Dictée vocale avec Voxtral (Mistral) — moteur au choix de l'administrateur.
 *
 * Garde : le moteur en vigueur (choix + clés), l'appel Voxtral rejoué sur un
 * transport factice (repli de modèle, arrêt sur une clé refusée, durée lue
 * dans la réponse de Mistral), l'essai réel de la clé AVANT d'enregistrer le
 * choix, la route (gardes dans l'ordre, facturation au fournisseur mistral),
 * le tarif au catalogue, le câblage du chat et de l'administration, et le
 * VRAI découpeur aux pauses du navigateur exécuté sous node.
 */
final class VoxtralDictationTest extends TestCase
{
    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    // ------------------------------------------------------------------
    // Moteur en vigueur
    // ------------------------------------------------------------------

    public function testLeMoteurSuitLeChoixEtLesCles(): void
    {
        $m = Dictation::ENGINE_MISTRAL;
        $o = Dictation::ENGINE_OPENAI;
        $this->assertSame($m, Dictation::resolveEngine($m, true, true));
        $this->assertSame($m, Dictation::resolveEngine($m, false, true));
        // Voxtral choisi, clé Mistral retirée depuis : retour sur OpenAI.
        $this->assertSame($o, Dictation::resolveEngine($m, true, false));
        $this->assertNull(Dictation::resolveEngine($m, false, false));
        $this->assertSame($o, Dictation::resolveEngine($o, true, true));
        // Voxtral n'est jamais pris sans avoir été choisi (donc essayé).
        $this->assertNull(Dictation::resolveEngine($o, false, true));
    }

    public function testLaLangueSuitLInterface(): void
    {
        $this->assertSame('fr', Dictation::languageFor('fr'));
        $this->assertSame('en', Dictation::languageFor('en_US'));
        $this->assertNull(Dictation::languageFor(''));
    }

    // ------------------------------------------------------------------
    // Appel Voxtral (transport factice)
    // ------------------------------------------------------------------

    public function testUnModeleRefuseFaitEssayerLeSuivant(): void
    {
        $appels = [];
        $transport = function (string $url, array $headers, array $fields) use (&$appels): array {
            $appels[] = $fields;
            if ($fields['model'] === Dictation::MISTRAL_MODELS[0]) {
                return ['status' => 400, 'body' => '{"object":"error","message":"Invalid model: voxtral-mini-transcribe-3","type":"invalid_request_error","code":"1500"}'];
            }
            return ['status' => 200, 'body' => '{"model":"voxtral-mini-2602","text":" Bonjour à tous. ","usage":{"prompt_audio_seconds":4.2}}'];
        };
        $r = Dictation::transcribeMistral(Dictation::silentWav(0.5), 'cle', 'fr', 'dictee.wav', $transport);

        $this->assertCount(2, $appels);
        $this->assertSame(Dictation::MISTRAL_MODELS[1], $appels[1]['model']);
        $this->assertSame('fr', $appels[0]['language']);
        $this->assertSame('dictee.wav', $appels[0]['file']['name']);
        $this->assertTrue(Dictation::looksLikeWav($appels[0]['file']['content']));
        $this->assertSame('Bonjour à tous.', $r['text']);
        // La durée facturée est celle que MISTRAL a mesurée.
        $this->assertSame(4.2, $r['seconds']);
        $this->assertSame('voxtral-mini-2602', $r['model']);
    }

    public function testUneCleRefuseeArreteTout(): void
    {
        $appels = 0;
        $transport = function () use (&$appels): array {
            $appels++;
            return ['status' => 401, 'body' => '{"message":"Unauthorized","request_id":"x"}'];
        };
        try {
            Dictation::transcribeMistral(Dictation::silentWav(0.5), 'mauvaise', null, 'dictee.wav', $transport);
            $this->fail('Une clé refusée doit lever.');
        } catch (SpeechSynthesisException $e) {
            $this->assertSame('auth', $e->payload()['kind'] ?? null);
            $this->assertStringNotContainsString('Unauthorized', $e->getMessage(), 'Jamais le message brut du fournisseur.');
        }
        $this->assertSame(1, $appels, 'Le modèle suivant n\'y changerait rien.');
    }

    public function testLEssaiDeLaCleDitSiElleMarche(): void
    {
        $ok = fn(): array => ['status' => 200, 'body' => '{"text":"","usage":{"prompt_audio_seconds":1}}'];
        $this->assertNull(Dictation::checkMistral('cle', $ok));

        $credit = fn(): array => ['status' => 402, 'body' => '{"message":"Payment required: insufficient credit"}'];
        $cause = Dictation::checkMistral('cle', $credit);
        $this->assertIsArray($cause);
        $this->assertNotSame('', $cause['message']);
    }

    public function testFormesPures(): void
    {
        $wav = Dictation::silentWav(1.0);
        $this->assertTrue(Dictation::looksLikeWav($wav));
        $this->assertSame(44 + 32000, strlen($wav));
        $this->assertFalse(Dictation::looksLikeWav('ID3' . str_repeat("\0", 60)));
        $this->assertTrue(Dictation::isModelRefusal(400, '{"message":"Invalid model: x"}'));
        $this->assertFalse(Dictation::isModelRefusal(401, '{"message":"Invalid model"}'));
        $this->assertFalse(Dictation::isModelRefusal(400, '{"message":"Invalid language alpha2 code"}'));
        $this->assertNull(Dictation::parseResponse('{"oops":1}', 'm'));
    }

    // ------------------------------------------------------------------
    // Tarif, route, administration, chat
    // ------------------------------------------------------------------

    public function testChaqueModeleVoxtralEstTarife(): void
    {
        $grille = LlmCatalog::provider('mistral')['transcription_pricing_usd_per_minute'] ?? [];
        foreach (Dictation::MISTRAL_MODELS as $modele) {
            $this->assertArrayHasKey($modele, $grille, "$modele sans tarif : la dictée serait facturée au défaut.");
        }
        $this->assertSame(0.0035, $grille['voxtral-mini-transcribe-3']);
        $this->assertArrayHasKey('default', $grille);
    }

    public function testLaFacturationConnaitLeFournisseur(): void
    {
        $meter = self::src('src/Billing/UsageMeter.php');
        $this->assertStringContainsString("public static function transcription(string \$model, float \$seconds, array \$ctx = [], string \$provider = 'openai'): array", $meter);
        $this->assertStringContainsString("'llm_provider' => \$provider . '::' . trim(\$model),", $meter);
        $credits = self::src('src/Models/CreditService.php');
        $this->assertStringContainsString("'transcribe:' . \$provider . '::' . \$model", $credits);
        $this->assertStringContainsString("preg_match('/^transcribe:([a-z0-9_.-]+)::(.+)$/i'", $credits);
        $admin = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString("'catalog_key' => 'transcribe:mistral::' . \$modelName,", $admin);
    }

    public function testLaRouteGardeReglageMoteurQuotaPuisFactureMistral(): void
    {
        $this->assertStringContainsString("\$router->post('/chat/dictation/transcribe', [ChatController::class, 'dictationTranscribe']);", self::src('public/index.php'));
        $src   = self::src('src/Controllers/ChatController.php');
        $debut = strpos($src, 'public function dictationTranscribe(): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 4000);

        $reglage = strpos($corps, "Settings::get('mic_enabled'");
        $moteur  = strpos($corps, 'Dictation::engine() !== Dictation::ENGINE_MISTRAL');
        $quota   = strpos($corps, 'isCreditQuotaExceeded(');
        $wav     = strpos($corps, 'Dictation::looksLikeWav(');
        $appel   = strpos($corps, 'Dictation::transcribeMistral(');
        $facture = strpos($corps, 'UsageMeter::transcription(');
        foreach ([$reglage, $moteur, $quota, $wav, $appel, $facture] as $pos) {
            $this->assertIsInt($pos);
        }
        $this->assertLessThan($moteur, $reglage);
        $this->assertLessThan($quota, $moteur);
        $this->assertLessThan($appel, $quota, 'Quota AVANT l\'appel payant.');
        $this->assertLessThan($appel, $wav);
        $this->assertLessThan($facture, $appel);
        $this->assertStringContainsString("], 'mistral');", $corps);
        $this->assertStringContainsString('session_write_close();', $corps);
        $this->assertStringContainsString('Dictation::languageFor(I18n::locale())', $corps);
    }

    public function testVoxtralNEstChoisiQuApresUnEssaiReussi(): void
    {
        $admin = self::src('src/Controllers/AdminController.php');
        $debut = strpos($admin, 'public function saveBuiltinTools(): void');
        $corps = substr($admin, (int)$debut, 5000);
        $essai = strpos($corps, 'Dictation::checkMistral(');
        $ecrit = strpos($corps, 'Settings::set(Dictation::SETTING_ENGINE, $engine);');
        $this->assertIsInt($essai);
        $this->assertIsInt($ecrit);
        $this->assertLessThan($ecrit, $essai);
        $this->assertStringContainsString('http_response_code(422);', $corps);

        $tpl = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(Dictation::SETTING_ENGINE) ?>"', $tpl);
        $this->assertStringContainsString('Dictation::resolveEngine($micEngine, $hasOpenAiKey, $hasMistralKey)', $tpl);
        // Un refus du serveur est dit, pas seulement « réessayez ».
        $this->assertStringContainsString("refusal.reason = (data && data.error) || '';", $tpl);
    }

    public function testLeChatProposeLeMicroSelonLeMoteur(): void
    {
        $tpl = self::src('templates/chat/index.php');
        $this->assertStringContainsString('<?php if (!empty($dictationEngine) && !empty($micEnabled)): ?>', $tpl);
        $this->assertStringContainsString('data-engine="<?= htmlspecialchars((string)$dictationEngine) ?>"', $tpl);
        $ctrl = self::src('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($ctrl, '$dictationEngine = Dictation::engine();'), 'index() ET show()');

        $js = self::src('public/assets/js/chat.js');
        $this->assertSame(1, substr_count($js, 'function createVoxtralTranscriber('));
        $this->assertStringContainsString("fetchWithSessionGuard('/chat/dictation/transcribe'", $js);
        // Rien de ce qui arrive après l'envoi ne retombe dans un champ vidé.
        $this->assertStringContainsString('transcriber.stop({ discard: true });', $js);
        $this->assertStringContainsString('.btn-mic.mic-transcribing', self::src('public/assets/css/main.css'));

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Helpers/Dictation.php', self::src($f), $f);
        }
    }

    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('Voxtral', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Voxtral', self::src('docs/GUIDE-ADMIN.md'));
    }

    // ------------------------------------------------------------------
    // Découpeur aux pauses (navigateur), exécuté sous node
    // ------------------------------------------------------------------

    public function testLeDecoupeurRendUnePhraseParPause(): void
    {
        $r = self::js();
        // 0,5 s de silence, 1 s de parole, 1 s de silence, 0,6 s de parole, arrêt.
        $this->assertSame(2, $r['segments'], 'Une phrase par pause, la dernière rendue à l\'arrêt.');
        // Un bruit bref (100 ms) n'est pas une phrase.
        $this->assertSame(0, $r['clic']);
        // La parole d'un seul tenant est coupée d'office à MAX_SEGMENT_MS.
        $this->assertGreaterThanOrEqual(2, $r['longue']);
        // Le début du premier mot (entendu avant le seuil) est gardé.
        $this->assertGreaterThan(16000, $r['premier'], '1 s de parole + le début entendu avant le seuil');
        $this->assertSame('RIFFWAVE', $r['entete']);
        $this->assertSame(16000, $r['rate']);
        $this->assertSame(8000, $r['echantillons48k']);
    }

    private static function js(): array
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $chat  = self::src('public/assets/js/chat.js');
        $debut = strpos($chat, 'const VoxtralCut = {');
        $fin   = strpos($chat, "if (typeof window !== 'undefined') window.MiaVoxtralCut");
        self::assertIsInt($debut);
        self::assertIsInt($fin);
        $script = tempnam(sys_get_temp_dir(), 'mia-voxtral-');
        file_put_contents($script, substr($chat, $debut, $fin - $debut) . self::HARNAIS);
        try {
            $processus = proc_open([$node, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($script);
        }
        $r = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($r)) {
            self::fail("Découpeur Voxtral en échec sous node (code $code) :\n" . $erreurs);
        }
        return $r;
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

    private const HARNAIS = <<<'JS'

        'use strict';
        const RATE = 48000, BUF = 4096;
        function feed(cutter, plan) {
            // plan : [[secondes, amplitude], …] — sinusoïde de 220 Hz (parole) ou bruit faible.
            let t = 0;
            for (const [sec, amp] of plan) {
                const total = Math.round(sec * RATE);
                for (let o = 0; o < total; o += BUF) {
                    const n = Math.min(BUF, total - o);
                    const buf = new Float32Array(n);
                    for (let i = 0; i < n; i++, t++) {
                        buf[i] = amp > 0 ? amp * Math.sin(2 * Math.PI * 220 * t / RATE) : (Math.random() - .5) * 0.002;
                    }
                    cutter.push(buf, RATE);
                }
            }
        }
        const a = VoxtralCut.cutter();
        feed(a, [[0.5, 0], [1, 0.3], [1, 0], [0.6, 0.3]]);
        a.flush();
        const segs = a.take();
        const b = VoxtralCut.cutter();
        feed(b, [[0.5, 0], [0.1, 0.3], [1, 0]]);
        b.flush();
        const c = VoxtralCut.cutter();
        feed(c, [[0.2, 0], [VoxtralCut.MAX_SEGMENT_MS / 1000 * 1.5, 0.3]]);
        c.flush();
        const w = new Uint8Array(VoxtralCut.wav(new Float32Array(10), 16000));
        const dv = new DataView(w.buffer);
        const ascii = (o, n) => String.fromCharCode(...w.slice(o, o + n));
        process.stdout.write(JSON.stringify({
            segments: segs.length,
            premier: segs.length ? segs[0].length : 0,
            clic: b.take().length,
            longue: c.take().length,
            entete: ascii(0, 4) + ascii(8, 4),
            rate: dv.getUint32(24, true),
            echantillons48k: VoxtralCut.downsample(new Float32Array(24000), 48000).length,
        }));
        JS;
}
