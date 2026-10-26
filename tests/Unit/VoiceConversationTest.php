<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Conversation vocale — écouter → envoyer → MIA répond → lire.
 *
 * Trois choses à garder :
 *   1. les FORMES PURES de la synthèse (texte lisible, découpage en parties,
 *      durée MP3 mesurée sur les trames) — c'est la durée qui est facturée ;
 *   2. la session Realtime de conversation : modèles PAR TOUR seulement (la
 *      détection de tour serveur dit quand l'utilisateur a fini), sémantique
 *      d'abord, serveur en repli ;
 *   3. le câblage : réglage d'administration respecté par le bouton ET par
 *      les deux routes (le serveur reste juge), quota AVANT l'appel payant,
 *      un seul socle Realtime pour la dictée et la conversation, un seul
 *      chemin d'envoi (submitForm) et de lecture (le message relu en base).
 */
final class VoiceConversationTest extends TestCase
{
    private static function src(string $relative): string
    {
        $path = ROOT . '/' . $relative;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    private static function chatController(): string
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        return self::src('src/Controllers/ChatController.php');
    }

    // ------------------------------------------------------------------
    // 1. Texte lisible
    // ------------------------------------------------------------------

    public function testLeMarkdownDevientDuTexteQuiSeLit(): void
    {
        $md = "## Titre\n\nVoici une **réponse** avec *emphase*, du `code` et un [lien](https://ex.fr/p) vers https://ex.fr/brut.\n\n"
            . "- premier point\n- snake_case_name intact\n1. numéro un\n2) numéro deux\n\n> Une citation.\n\n"
            . "```php\necho 'jamais lu';\n```\n\n| Col A | Col B |\n|---|---|\n| 1 | deux |\n\n![image](/assets/img/generated/x.png)\n\n---\n\nFin.";
        $texte = SpeechSynthesizer::speakableText($md);

        $this->assertSame(
            "Titre\nVoici une réponse avec emphase, du code et un lien vers\npremier point\nsnake_case_name intact\nnuméro un\nnuméro deux\nUne citation.\nCol A, Col B.\n1, deux.\nFin.",
            $texte
        );
        // Rien de ce qui ne se lit pas ne survit.
        foreach (['```', 'echo', 'https://', '![', '**', '##', '| ', '---'] as $bruit) {
            $this->assertStringNotContainsString($bruit, $texte, "« $bruit » ne devrait pas être lu");
        }
    }

    public function testUnBlocDeCodeLaisseOuvertEstRetireJusquALaFin(): void
    {
        $texte = SpeechSynthesizer::speakableText("Voici le code :\n```js\nconst a = 1;\nconsole.log(a);");
        $this->assertSame('Voici le code :', $texte);
    }

    public function testLesMarqueursDeCitationChatgptDisparaissentAvecLeurContenu(): void
    {
        $texte = SpeechSynthesizer::speakableText("Paris est la capitale\u{E200}cite\u{E202}turn0search0\u{E201} de la France.");
        $this->assertSame('Paris est la capitale de la France.', $texte);
    }

    public function testLeHtmlEtLesEntitesSontNettoyes(): void
    {
        $this->assertSame('Bonjour & merci', SpeechSynthesizer::speakableText('<p>Bonjour &amp; <strong>merci</strong></p>'));
        $this->assertSame('', SpeechSynthesizer::speakableText("   \n\n  "));
    }

    // ------------------------------------------------------------------
    // 2. Découpage en parties
    // ------------------------------------------------------------------

    public function testLeDecoupageRespecteLesBornesEtCoupeEnFinDePhrase(): void
    {
        $this->assertSame([], SpeechSynthesizer::splitForSpeech(''));
        $this->assertSame(['Une seule phrase.'], SpeechSynthesizer::splitForSpeech('Une seule phrase.'));
        // À peine plus long que la première borne : une seule partie, pas une queue de trois mots.
        $courte = trim(str_repeat('Une phrase brève. ', 30)); // ~540 caractères
        $this->assertGreaterThan(SpeechSynthesizer::FIRST_PART_CHARS, mb_strlen($courte));
        $this->assertSame([$courte], SpeechSynthesizer::splitForSpeech($courte));

        $phrase = 'Une phrase de taille moyenne qui sert de test. ';
        $parts  = SpeechSynthesizer::splitForSpeech(str_repeat($phrase, 60));

        $this->assertGreaterThan(1, count($parts));
        // La première partie est courte (la voix démarre vite), les suivantes plus longues.
        $this->assertLessThanOrEqual(SpeechSynthesizer::FIRST_PART_CHARS, mb_strlen($parts[0]));
        $this->assertGreaterThan(SpeechSynthesizer::FIRST_PART_CHARS, mb_strlen($parts[1]));
        foreach ($parts as $i => $p) {
            $this->assertLessThanOrEqual(SpeechSynthesizer::PART_CHARS, mb_strlen($p), "partie $i trop longue");
            $this->assertLessThanOrEqual(SpeechSynthesizer::MAX_INPUT_CHARS, mb_strlen($p));
            $this->assertStringEndsWith('.', $p, "partie $i ne se termine pas en fin de phrase");
        }
        // Rien n'est perdu ni dupliqué : les mots se retrouvent dans l'ordre.
        $this->assertSame(trim(str_repeat($phrase, 60)), implode(' ', $parts));
    }

    public function testUnTexteSansBlancEstCoupeAuCaractereSansJamaisDepasser(): void
    {
        $parts = SpeechSynthesizer::splitForSpeech(str_repeat('a', 5000));
        // Les morceaux d'une même unité sont rejoints par un blanc : rien n'est perdu.
        $this->assertSame(str_repeat('a', 5000), str_replace(' ', '', implode('', $parts)));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(SpeechSynthesizer::PART_CHARS, mb_strlen($p));
        }
        // Les bornes sont plafonnées par la limite de l'API.
        foreach (SpeechSynthesizer::splitForSpeech(str_repeat('b ', 6000), 10000, 10000) as $p) {
            $this->assertLessThanOrEqual(SpeechSynthesizer::MAX_INPUT_CHARS, mb_strlen($p));
        }
    }

    // ------------------------------------------------------------------
    // 2 bis. Lecture en direct : la réponse se lit PENDANT qu'elle s'écrit
    // ------------------------------------------------------------------

    public function testLaVoixDemarreDesLaPremierePhraseSansAttendreLaFin(): void
    {
        $ecrit = "Bonjour Alex ! Demain, il fera 18 degrés à Paris avec un grand soleil. Pense à ta crème solaire. Et le soir";
        $apres = strpos($ecrit, 'soleil.') + strlen('soleil.');

        // Premier passage : la première fin de phrase qui fait au moins
        // LIVE_FIRST_MIN caractères — « Bonjour Alex ! » seul est trop court.
        $premier = SpeechSynthesizer::liveSegment($ecrit, 0, false, true);
        $this->assertSame(['to' => $apres, 'text' => 'Bonjour Alex ! Demain, il fera 18 degrés à Paris avec un grand soleil.'], $premier);
        $this->assertGreaterThanOrEqual(SpeechSynthesizer::LIVE_FIRST_MIN_CHARS, mb_strlen($premier['text']));

        // Ensuite : une phrase complète trop courte, puis une phrase qui
        // s'écrit encore — rien n'est lu tant que la réponse continue…
        $this->assertNull(SpeechSynthesizer::liveSegment($ecrit, $apres, false, false, 200));
        // … et tout, jusqu'au dernier mot, une fois qu'elle est finie.
        $this->assertSame(
            ['to' => strlen($ecrit), 'text' => 'Pense à ta crème solaire. Et le soir'],
            SpeechSynthesizer::liveSegment($ecrit, $apres, true, false, 200)
        );

        // Le texte ne fait que s'allonger : une position rendue reste valable,
        // le passage déjà lu ne change pas, et la suite reprend exactement après.
        $plus = $ecrit . ', quelques nuages. Rien de grave.';
        $this->assertSame($premier, SpeechSynthesizer::liveSegment($plus, 0, false, true));
        $this->assertSame(
            ['to' => strpos($plus, 'nuages.') + strlen('nuages.'), 'text' => 'Pense à ta crème solaire. Et le soir, quelques nuages.'],
            SpeechSynthesizer::liveSegment($plus, $apres, false, false, 200),
            'la dernière phrase, sans blanc derrière, peut encore s\'allonger : elle attend'
        );

        // Une réponse courte n'est lue qu'une fois finie.
        $this->assertNull(SpeechSynthesizer::liveSegment('Oui.', 0, false, true));
        $this->assertSame(['to' => 4, 'text' => 'Oui.'], SpeechSynthesizer::liveSegment('Oui.', 0, true, true));
        // Plus rien après la fin.
        $this->assertNull(SpeechSynthesizer::liveSegment('Oui.', 4, true));
    }

    public function testUnPassageNeFinitJamaisAuMilieuDUneConstruction(): void
    {
        // Ni après un numéro de liste ou une initiale, ni dans une parenthèse,
        // ni dans du code en ligne : la coupure attend la vraie fin de phrase.
        $this->assertSame(
            'Premier point. M. Dupont (voir plus bas. Oui) viendra demain matin, à neuf heures.',
            SpeechSynthesizer::liveSegment("1. Premier point. M. Dupont (voir plus bas. Oui) viendra demain matin, à neuf heures. Merci !", 0, false, true)['text']
        );
        $this->assertSame(
            'Tape ls. -la pour lister les fichiers du dossier courant, avec leurs droits.',
            SpeechSynthesizer::liveSegment("Tape `ls. -la` pour lister les fichiers du dossier courant, avec leurs droits. Ensuite", 0, false, true)['text']
        );
        // Guillemets français : la phrase citée garde son guillemet fermant.
        $cite = "Il a dit : « Je viens. » Puis il est reparti vers la gare, sans un mot de plus pour personne. Fin.";
        $this->assertSame(
            'Puis il est reparti vers la gare, sans un mot de plus pour personne.',
            SpeechSynthesizer::liveSegment($cite, strpos($cite, '»') + strlen('»'), false, false, 200)['text']
        );
    }

    public function testUnBlocDeCodeNeSeCoupePasEtNeSeLitPas(): void
    {
        $intro = "Voici le code exact qu'il faut lancer sur le serveur de production :\n";
        $ouvert = $intro . "```bash\nls -la. ok\n";
        $this->assertSame(['to' => strlen($intro), 'text' => rtrim($intro)], SpeechSynthesizer::liveSegment($ouvert, 0, false, true));
        // Bloc ouvert : ce qui suit est du code tant que la clôture n'est pas arrivée.
        $this->assertNull(SpeechSynthesizer::liveSegment($ouvert, strlen($intro), false, false, 200));
        // Bloc clos puis du texte : le passage le traverse, le code n'est pas lu.
        $clos = $ouvert . "```\nEnsuite, relancez le service et vérifiez les journaux. ";
        $this->assertSame(
            ['to' => strlen($clos) - 1, 'text' => 'Ensuite, relancez le service et vérifiez les journaux.'],
            SpeechSynthesizer::liveSegment($clos, strlen($intro), false, false, 200)
        );
        // Un bloc seul : passage sans rien de lisible, que l'appelant saute.
        $seul = $ouvert . "```\n";
        $this->assertSame(['to' => strlen($seul), 'text' => ''], SpeechSynthesizer::liveSegment($seul, strlen($intro), true, false, 200));
    }

    public function testUnePhraseSansFinEstCoupeeAuDernierBlancSousLaBorne(): void
    {
        $fleuve = str_repeat('mot ', 80) . 'fin';
        $passage = SpeechSynthesizer::liveSegment($fleuve, 0, false, true);
        $this->assertNotNull($passage);
        $this->assertLessThanOrEqual(SpeechSynthesizer::LIVE_FIRST_MAX_CHARS, mb_strlen($passage['text']));
        $this->assertSame(' ', $fleuve[$passage['to']], 'coupé à un blanc, jamais dans un mot');
        // Les passages suivants restent sous la borne demandée — jamais sous le
        // plancher, jamais au-delà d'une partie ordinaire.
        $long  = $fleuve . str_repeat(' mot', 600);
        $suite = SpeechSynthesizer::liveSegment($long, $passage['to'], false, false, 5);
        $this->assertLessThanOrEqual(SpeechSynthesizer::LIVE_MIN_CHARS, mb_strlen($suite['text']));
        $this->assertGreaterThan(SpeechSynthesizer::LIVE_MIN_CHARS / 2, mb_strlen($suite['text']));
        $suite = SpeechSynthesizer::liveSegment($long, $passage['to'], false, false, 99999);
        $this->assertLessThanOrEqual(SpeechSynthesizer::PART_CHARS, mb_strlen($suite['text']));
        $this->assertGreaterThan(SpeechSynthesizer::PART_CHARS / 2, mb_strlen($suite['text']));
        // Une position forgée au milieu d'un caractère est ramenée au suivant.
        $accent  = 'Élan, élan, élan : une phrase assez longue pour franchir le seuil du premier passage. ';
        $attendu = SpeechSynthesizer::liveSegment($accent, 2, false, true);
        $this->assertNotNull($attendu);
        $this->assertSame($attendu, SpeechSynthesizer::liveSegment($accent, 1, false, true));
    }

    /**
     * Quelle que soit la cadence d'arrivée du texte — mot à mot ou par gros
     * blocs —, la lecture en direct dit TOUTE la réponse, une seule fois, dans
     * l'ordre : bout à bout, ses passages valent la lecture du texte entier.
     */
    public function testRienNEstPerduNiLuDeuxFoisQuelleQueSoitLaCadence(): void
    {
        $reponses = [
            "Bonjour Alex ! Demain, il fera 18 degrés à Paris avec un grand soleil. Pense à ta crème solaire. Le soir, quelques nuages arrivent de l'ouest, sans pluie.",
            "## Les trois points\n\n1. **Le budget** est validé. Il reste à signer.\n2. Le planning (voir plus bas. Oui) glisse d'une semaine.\n3. L'équipe est au complet !\n\n"
                . "Voici la commande :\n```bash\nls -la. ok\necho 'fin.'\n```\nEnsuite, relancez le service. C'est tout.",
            "Tape `ls. -la` pour lister. Puis [la doc](https://ex.fr/a.b) explique le reste. Enfin, M. Dupont validera. « Je viens. » dit-il. Voilà…",
            "| Jour | Temps |\n|---|---|\n| Lundi | Soleil |\n| Mardi | Pluie |\n\nEn résumé : beau début de semaine, puis de la pluie. "
                . str_repeat('Une longue phrase sans fin qui continue encore et encore ', 12) . 'fin.',
        ];
        $aplatir = static fn(string $s): string => trim((string)preg_replace('/\s+/u', ' ', $s));
        foreach ($reponses as $i => $reponse) {
            foreach ([[1, 3], [7, 12], [42, 60], [5, 400]] as [$graine, $fragment]) {
                $passages = self::lireEnDirect($reponse, $graine, $fragment);
                $this->assertSame(
                    $aplatir(SpeechSynthesizer::speakableText($reponse)),
                    $aplatir(implode(' ', $passages)),
                    "réponse $i, fragments de 1 à $fragment octets"
                );
                $this->assertGreaterThan(1, count($passages), "réponse $i : la voix doit pouvoir démarrer avant la fin");
            }
        }
    }

    /**
     * Rejoue une réponse qui arrive par fragments de taille aléatoire, en
     * demandant après chaque fragment tous les passages prêts — le cas le plus
     * haché possible. Rend les textes lus, dans l'ordre.
     *
     * @return list<string>
     */
    private static function lireEnDirect(string $reponse, int $graine, int $fragment): array
    {
        mt_srand($graine);
        $recu = '';
        $lus  = [];
        $from = 0;
        $max  = 0;
        $pos  = 0;
        $len  = strlen($reponse);
        do {
            if ($pos < $len) {
                $fin = min($len, $pos + mt_rand(1, $fragment));
                while ($fin < $len && (ord($reponse[$fin]) & 0xC0) === 0x80) {
                    $fin++;
                }
                $recu .= substr($reponse, $pos, $fin - $pos);
                $pos   = $fin;
            }
            $finie = $pos >= $len;
            while (($passage = SpeechSynthesizer::liveSegment($recu, $from, $finie, $max === 0, $max ?: SpeechSynthesizer::PART_CHARS)) !== null) {
                self::assertGreaterThan($from, $passage['to'], 'un passage avance toujours');
                $from = $passage['to'];
                if ($passage['text'] !== '') {
                    $lus[] = $passage['text'];
                    $max   = (int)round(mb_strlen($passage['text']) * 2.5);
                }
            }
        } while (!$finie);
        return $lus;
    }

    public function testLaReponseEnDirectEstRelueDansLeFluxDeLaGeneration(): void
    {
        self::chatController();
        $flux = [
            '{"phase":"thinking"}',
            '{"chunk":"Bonjour. "}',
            '{"action":{"t":"web_search","l":"Recherche sur Internet","ok":true}}',
            '{"chunk":"Il fera beau."}',
            'pas du JSON',
        ];
        $this->assertSame(
            ['text' => 'Bonjour. Il fera beau.', 'final' => false, 'error' => false, 'msg_id' => null],
            ChatController::liveReplyFromEvents($flux)
        );
        // Fin de la réponse : `done` (avec son message), arrêt demandé, ou erreur.
        $fini = ChatController::liveReplyFromEvents(array_merge($flux, ['{"done":true,"msg_id":12}']));
        $this->assertTrue($fini['final']);
        $this->assertSame(12, $fini['msg_id']);
        $this->assertTrue(ChatController::liveReplyFromEvents(['{"chunk":"Voilà"}', '{"aborted":true,"msg_id":3}'])['final']);
        $erreur = ChatController::liveReplyFromEvents(['{"llm_error":{"title":"Service surchargé"}}']);
        $this->assertTrue($erreur['error']);
        $this->assertTrue($erreur['final']);
    }

    // ------------------------------------------------------------------
    // 3. Durée MP3
    // ------------------------------------------------------------------

    /** Trame MPEG-2 Layer III, 24 kHz, 32 kbit/s, mono : 96 octets, 576 échantillons. */
    private static function trameMpeg2(): string
    {
        return "\xFF\xF3\x44\xC0" . str_repeat("\x00", 92);
    }

    /** Trame MPEG-1 Layer III, 44,1 kHz, 128 kbit/s : 417 octets, 1152 échantillons. */
    private static function trameMpeg1(): string
    {
        return "\xFF\xFB\x90\xC0" . str_repeat("\x00", 413);
    }

    public function testLaDureeEstLueDansLesEnTetesDeTrames(): void
    {
        $this->assertEqualsWithDelta(10 * 576 / 24000, SpeechSynthesizer::mp3DurationSeconds(str_repeat(self::trameMpeg2(), 10)), 1e-9);
        $this->assertEqualsWithDelta(5 * 1152 / 44100, SpeechSynthesizer::mp3DurationSeconds(str_repeat(self::trameMpeg1(), 5)), 1e-9);
    }

    public function testLeTagId3EtLaTrameXingNeComptentPas(): void
    {
        $id3  = "ID3\x04\x00\x00" . "\x00\x00\x00\x14" . str_repeat("\x00", 20);
        $xing = "\xFF\xF3\x44\xC0" . str_repeat("\x00", 13) . 'Xing' . str_repeat("\x00", 96 - 17 - 4);
        $flux = $id3 . $xing . str_repeat(self::trameMpeg2(), 10);
        $this->assertEqualsWithDelta(10 * 576 / 24000, SpeechSynthesizer::mp3DurationSeconds($flux), 1e-9);
    }

    public function testUnFluxVideOuInconnuNeDonneJamaisUneDureeInventee(): void
    {
        $this->assertSame(0.0, SpeechSynthesizer::mp3DurationSeconds(''));
        $this->assertSame(0.0, SpeechSynthesizer::mp3DurationSeconds(str_repeat("\x12\x34\x56", 200)));
        // Une erreur JSON renvoyée en 200 par erreur n'est pas de l'audio.
        $this->assertSame(0.0, SpeechSynthesizer::mp3DurationSeconds('{"error":{"message":"x"}}'));
    }

    // ------------------------------------------------------------------
    // 4. Appel de synthèse (transport factice)
    // ------------------------------------------------------------------

    public function testLaSyntheseRendLAudioLeModeleEtLaDureeMesuree(): void
    {
        $audio    = str_repeat(self::trameMpeg2(), 20);
        $captured = null;
        $transport = static function (string $url, array $headers, string $body) use (&$captured, $audio): array {
            $captured = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
            return ['status' => 200, 'body' => $audio];
        };

        $r = SpeechSynthesizer::synthesize('Bonjour, comment allez-vous ?', 'sk-test', $transport);

        $this->assertSame($audio, $r['audio']);
        $this->assertSame(SpeechSynthesizer::MODEL, $r['model']);
        $this->assertEqualsWithDelta(20 * 576 / 24000, $r['seconds'], 1e-9);
        $this->assertSame(SpeechSynthesizer::ENDPOINT, $captured['url']);
        $this->assertContains('Authorization: Bearer sk-test', $captured['headers']);
        $this->assertSame(SpeechSynthesizer::MODEL, $captured['body']['model']);
        $this->assertSame(SpeechSynthesizer::VOICE, $captured['body']['voice']);
        $this->assertSame('mp3', $captured['body']['response_format']);
        $this->assertSame('Bonjour, comment allez-vous ?', $captured['body']['input']);
    }

    public function testUnRefusDuFournisseurEstTraduitSansFuiteDeCle(): void
    {
        $transport = static fn(): array => ['status' => 401, 'body' => '{"error":{"message":"Incorrect API key provided: sk-test"}}'];
        try {
            SpeechSynthesizer::synthesize('Bonjour', 'sk-test', $transport);
            $this->fail('Un 401 doit lever');
        } catch (\RuntimeException $e) {
            // Même cause, mêmes mots que dans une conversation — ni statut
            // HTTP, ni message brut du fournisseur.
            $this->assertInstanceOf(SpeechSynthesisException::class, $e);
            $this->assertSame('auth', $e->payload()['kind']);
            $this->assertStringContainsString('Prévenez un administrateur', $e->getMessage());
            $this->assertStringNotContainsString('sk-test', $e->getMessage());
            $this->assertStringNotContainsString('Incorrect', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        SpeechSynthesizer::synthesize('Bonjour', 'sk-test', static fn(): array => ['status' => 0, 'body' => '']);
    }

    public function testUnTexteAuDelaDeLaLimiteDeLApiEstRefuseAvantLAppel(): void
    {
        $appele = false;
        $transport = static function () use (&$appele): array { $appele = true; return ['status' => 200, 'body' => 'x']; };
        try {
            SpeechSynthesizer::synthesize(str_repeat('a', SpeechSynthesizer::MAX_INPUT_CHARS + 1), 'k', $transport);
            $this->fail('Trop long : doit lever');
        } catch (\RuntimeException $e) {
            $this->assertFalse($appele, 'Aucun appel ne doit partir');
        }
        // Chaque partie du découpage tient dans cette limite : le découpage
        // et l'appel sont accordés.
        $this->assertLessThanOrEqual(SpeechSynthesizer::MAX_INPUT_CHARS, SpeechSynthesizer::PART_CHARS);
        $this->assertLessThanOrEqual(SpeechSynthesizer::PART_CHARS, SpeechSynthesizer::FIRST_PART_CHARS);
    }

    // ------------------------------------------------------------------
    // 4 bis. Annonces mises en cache
    // ------------------------------------------------------------------

    public function testLaCleDeCacheDUneAnnonceSuitLaVoixLaLangueEtLeTexte(): void
    {
        $a = SpeechSynthesizer::cueCacheKey('Recherche sur Internet', 'fr');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $a);
        $this->assertSame($a, SpeechSynthesizer::cueCacheKey("  Recherche sur Internet \n", 'fr'), 'les blancs de bord ne changent rien');
        $this->assertNotSame($a, SpeechSynthesizer::cueCacheKey('Recherche sur Internet', 'en'), 'une langue = une voix');
        $this->assertNotSame($a, SpeechSynthesizer::cueCacheKey('Recherche dans Gmail', 'fr'));
        // Le chemin vit sous le dossier de cache donné (le cache d'instance par défaut).
        $this->assertSame('/tmp/x/' . $a . '.mp3', SpeechSynthesizer::cueCachePath('Recherche sur Internet', 'fr', '/tmp/x/'));
        $this->assertStringStartsWith(ROOT . SpeechSynthesizer::CACHE_DIR . '/', SpeechSynthesizer::cueCachePath('Recherche sur Internet', 'fr'));
        $this->assertStringStartsWith('/storage/cache/', SpeechSynthesizer::CACHE_DIR, 'à côté des autres caches d\'instance (volume storage_cache)');
    }

    public function testUneAnnonceEstSynthetiseeUneFoisPuisServieDuCacheSansFacturation(): void
    {
        $dir = sys_get_temp_dir() . '/mia-cue-' . bin2hex(random_bytes(4));
        $audio  = str_repeat(self::trameMpeg2(), 20);
        $appels = 0;
        $transport = static function () use (&$appels, $audio): array {
            $appels++;
            return ['status' => 200, 'body' => $audio];
        };
        try {
            $this->assertNull(SpeechSynthesizer::readCachedCue('Recherche sur Internet', 'fr', $dir));

            $premier = SpeechSynthesizer::synthesizeCached('Recherche sur Internet', 'sk-test', 'fr', $transport, $dir);
            $this->assertFalse($premier['cached']);
            $this->assertSame($audio, $premier['audio']);
            $this->assertEqualsWithDelta(20 * 576 / 24000, $premier['seconds'], 1e-9);
            $this->assertSame(1, $appels);
            $this->assertSame($audio, file_get_contents(SpeechSynthesizer::cueCachePath('Recherche sur Internet', 'fr', $dir)));
            $this->assertSame([], array_values(array_filter(scandir($dir), static fn(string $f): bool => str_starts_with($f, 'cue-'))), 'aucun fichier temporaire ne reste');

            $second = SpeechSynthesizer::synthesizeCached('Recherche sur Internet', 'sk-test', 'fr', $transport, $dir);
            $this->assertTrue($second['cached'], 'servie du disque : rien à facturer');
            $this->assertSame($audio, $second['audio']);
            $this->assertEqualsWithDelta($premier['seconds'], $second['seconds'], 1e-9);
            $this->assertSame(1, $appels, 'aucun second appel au fournisseur');
            $this->assertSame($audio, SpeechSynthesizer::readCachedCue('Recherche sur Internet', 'fr', $dir));

            // Une autre langue = une autre annonce.
            SpeechSynthesizer::synthesizeCached('Recherche sur Internet', 'sk-test', 'en', $transport, $dir);
            $this->assertSame(2, $appels);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testLAnnonceDUneActionEstSonLibelleEtRienPourUnTypeInconnu(): void
    {
        self::chatController();
        $this->assertSame('Recherche sur Internet', ChatController::actionCuePhrase('web_search'));
        $this->assertSame('Consultation du cerveau MyBrain', ChatController::actionCuePhrase('mybrain_search'));
        $this->assertSame('Recherche dans les bases de connaissances', ChatController::actionCuePhrase('searching_knowledge_bases'));
        $this->assertSame('', ChatController::actionCuePhrase('inconnu'));
        $this->assertSame('', ChatController::actionCuePhrase(''));
        // Chaque type tracé a une phrase : rien n'est lu comme un slug.
        foreach (array_keys(ChatController::ACTION_LABELS) as $type) {
            $this->assertNotSame('', ChatController::actionCuePhrase($type), $type);
            $this->assertStringNotContainsString('_', ChatController::actionCuePhrase($type), $type);
        }
    }

    public function testLAnnonceEstGardeeReglageCacheQuotaPuisFactureeSeulementSiSynthetisee(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function voiceCue(): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 3500);

        $auth    = strpos($corps, 'AuthMiddleware::requireAuth();');
        $reglage = strpos($corps, 'SpeechSynthesizer::isEnabled()');
        $phrase  = strpos($corps, 'self::cuePhrase(');
        $cache   = strpos($corps, 'SpeechSynthesizer::readCachedCue(');
        $quota   = strpos($corps, 'isCreditQuotaExceeded(');
        $appel   = strpos($corps, 'SpeechSynthesizer::synthesizeCached(');
        $facture = strpos($corps, 'UsageMeter::speech(');
        foreach (['auth', 'reglage', 'phrase', 'cache', 'quota', 'appel', 'facture'] as $v) {
            $this->assertIsInt($$v, "$v absent de voiceCue");
        }
        $this->assertLessThan($reglage, $auth);
        $this->assertLessThan($phrase, $reglage);
        $this->assertLessThan($cache, $phrase);
        $this->assertLessThan($quota, $cache, 'une annonce en cache ne coûte rien : le quota ne se contrôle que si une synthèse doit partir');
        $this->assertLessThan($appel, $quota, 'le quota se contrôle AVANT l\'appel payant');
        $this->assertLessThan($facture, $appel);
        // Facturée seulement quand le fournisseur a été appelé ; type inconnu = 204, audio en GET cachable.
        $this->assertStringContainsString("if (!\$result['cached'])", $corps);
        $this->assertStringContainsString('http_response_code(204)', $corps);
        $this->assertStringContainsString("header('Content-Type: audio/mpeg');", $corps);
        $this->assertStringContainsString("header('Cache-Control: private, max-age=", $corps);
        $this->assertStringContainsString('I18n::locale()', $corps);

        // Route GET à deux segments, déclarée AVANT /chat/{id}.
        $routes = self::src('public/index.php');
        $cue    = strpos($routes, "\$router->get('/chat/voice/cue',");
        $show   = strpos($routes, "\$router->get('/chat/{id}',");
        $this->assertIsInt($cue);
        $this->assertLessThan($show, $cue);
    }

    // ------------------------------------------------------------------
    // 5. Session Realtime de conversation
    // ------------------------------------------------------------------

    public function testLaConversationNUtiliseQueDesModelesParTourSemantiqueDAbord(): void
    {
        self::chatController();
        $candidats = ChatController::conversationSessionCandidates();
        $this->assertNotEmpty($candidats);

        $parTour = array_keys(array_filter(ChatController::TRANSCRIPTION_MODELS, static fn($vad): bool => $vad !== null));
        $live    = array_keys(array_filter(ChatController::TRANSCRIPTION_MODELS, static fn($vad): bool => $vad === null));
        $this->assertCount(2 * count($parTour), $candidats, 'deux variantes de détection par modèle par tour');

        foreach ($candidats as $i => [$model, $vad]) {
            $this->assertContains($model, $parTour, "« $model » n'est pas un modèle par tour");
            $this->assertNotContains($model, $live, 'un modèle live refuse la détection de tour : aucun tour à envoyer');
            $this->assertIsArray($vad, 'la conversation exige une détection de tour SERVEUR');
            // Sémantique d'abord (le modèle juge la fin de phrase), serveur en repli.
            $this->assertSame($i % 2 === 0 ? 'semantic_vad' : 'server_vad', $vad['type']);
        }
        $this->assertSame($parTour[0], $candidats[0][0], 'l\'ordre de préférence de TRANSCRIPTION_MODELS est conservé');
    }

    public function testLeJetonRealtimeDistingueDicteeEtConversation(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function realtimeTranscriptionToken');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 6000);

        $this->assertStringContainsString("(\$_POST['purpose'] ?? '') === 'conversation'", $corps);
        // Chaque usage a SON interrupteur d'administration, contrôlé AVANT la session.
        $garde   = strpos($corps, 'SpeechSynthesizer::isEnabled()');
        $mic     = strpos($corps, "Settings::get('mic_enabled'");
        $quota   = strpos($corps, 'isCreditQuotaExceeded(');
        $session = strpos($corps, 'client_secrets');
        $this->assertIsInt($garde);
        $this->assertIsInt($mic);
        $this->assertLessThan($quota, $garde);
        $this->assertLessThan($session, $garde);
        $this->assertStringContainsString('self::conversationSessionCandidates()', $corps);
        // La langue suit l'interface, jamais un `fr` en dur.
        $this->assertStringContainsString("'language' => \$language,", $corps);
        $this->assertStringContainsString('I18n::locale()', $corps);
        $this->assertStringNotContainsString("'language' => 'fr'", $corps);
    }

    // ------------------------------------------------------------------
    // 6. Route de lecture et consigne orale
    // ------------------------------------------------------------------

    public function testLaLectureEstGardeeReglageAccesQuotaPuisFacturee(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function speakMessage(string $id): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 5000);

        $reglage  = strpos($corps, 'SpeechSynthesizer::isEnabled()');
        $acces    = strpos($corps, 'Conversation::belongsToUser(');
        $quota    = strpos($corps, 'isCreditQuotaExceeded(');
        $appel    = strpos($corps, 'SpeechSynthesizer::synthesize(');
        $facture  = strpos($corps, 'UsageMeter::speech(');
        foreach (['reglage', 'acces', 'quota', 'appel', 'facture'] as $v) {
            $this->assertIsInt($$v, "$v absent de speakMessage");
        }
        $this->assertLessThan($acces, $reglage);
        $this->assertLessThan($quota, $acces);
        $this->assertLessThan($appel, $quota, 'le quota se contrôle AVANT l\'appel payant');
        $this->assertLessThan($facture, $appel, 'la facturation suit l\'audio réellement reçu');
        // Le texte lu est celui du message EN BASE, découpé côté serveur ; jamais un texte reçu du client.
        $this->assertStringContainsString('Message::findById((int)$id)', $corps);
        $this->assertStringContainsString('SpeechSynthesizer::splitForSpeech(SpeechSynthesizer::speakableText($content))', $corps);
        $this->assertStringNotContainsString("\$_POST['text']", $corps);
        // Une réponse en erreur ne se lit pas (204), seule une réponse d'assistant se lit.
        $this->assertStringContainsString('looksLikeApiError($content)', $corps);
        $this->assertStringContainsString('http_response_code(204)', $corps);
        $this->assertStringContainsString("!== 'assistant'", $corps);
        $this->assertStringContainsString("'quota_exceeded' => true", $corps);
        $this->assertStringContainsString("header('X-Speech-Parts: '", $corps);
    }

    public function testLaLectureEnDirectEstGardeeCommeLaLectureDUnMessage(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function speakLive(string $id): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, (int)strpos($src, 'public static function liveReplyFromEvents(', $debut) - $debut);

        $reglage = strpos($corps, 'SpeechSynthesizer::isEnabled()');
        $tache   = strpos($corps, 'JobQueue::findForUser(');
        $acces   = strpos($corps, 'Conversation::belongsToUser(');
        $texte   = strpos($corps, 'JobQueue::eventPayloads(');
        $quota   = strpos($corps, 'isCreditQuotaExceeded(');
        $appel   = strpos($corps, 'SpeechSynthesizer::synthesize(');
        $facture = strpos($corps, 'UsageMeter::speech(');
        foreach (['reglage', 'tache', 'acces', 'texte', 'quota', 'appel', 'facture'] as $v) {
            $this->assertIsInt($$v, "$v absent de speakLive");
        }
        $this->assertLessThan($tache, $reglage);
        $this->assertLessThan($acces, $tache, 'une génération n\'est lisible que par son propriétaire');
        $this->assertLessThan($texte, $acces);
        $this->assertLessThan($appel, $quota, 'le quota se contrôle AVANT l\'appel payant');
        $this->assertLessThan($facture, $appel, 'la facturation suit l\'audio réellement reçu');
        // Une réponse de chat seulement, et le texte est RELU côté serveur (le
        // flux de la génération) : jamais un texte reçu du client.
        $this->assertStringContainsString("(\$job['type'] ?? '') !== 'chat'", $corps);
        $this->assertStringNotContainsString("\$_POST['text']", $corps);
        $this->assertStringContainsString('SpeechSynthesizer::liveSegment(', $corps);
        // « Pas encore » (202) et « plus rien » (204) ne coûtent rien : ils partent avant le quota.
        $this->assertLessThan($quota, strpos($corps, 'http_response_code($final || $reply[\'error\'] ? 204 : 202);'));
        foreach (["header('X-Speech-Next: '", "header('X-Speech-Final: '", "header('X-Speech-Chars: '"] as $entete) {
            $this->assertStringContainsString($entete, $corps);
        }

        $routes = self::src('public/index.php');
        $this->assertStringContainsString("\$router->post('/jobs/{id}/speak',         [ChatController::class, 'speakLive']);", $routes);
        // Le flux complet, dans l'ordre (pas une tranche de 200 évènements).
        $queue = self::src('src/Jobs/JobQueue.php');
        $this->assertStringContainsString("'SELECT payload FROM job_events WHERE job_id = ? ORDER BY seq'", $queue);
    }

    public function testLaVoixLitLaReponsePendantQuElleSEcrit(): void
    {
        $js = self::src('public/assets/js/chat.js');
        // Chaque fragment reçu peut compléter la phrase attendue : il est signalé.
        $this->assertStringContainsString("document.dispatchEvent(new CustomEvent('mia:chat-progress', { detail: { convId: cidStr, jobId: state.jobId || null } }));", $js);
        $this->assertStringContainsString("document.addEventListener('mia:chat-progress'", $js);
        // La lecture démarre dès l'envoi du tour (sur SA génération), pas à la fin de la réponse.
        $debutEnvoi = strpos($js, 'async function submitTurn() {');
        $this->assertIsInt($debutEnvoi);
        $corpsEnvoi = substr($js, $debutEnvoi, 900);
        $this->assertStringContainsString('turnStream  = activeStreams.get(turnConvId) || null;', $corpsEnvoi);
        $this->assertStringContainsString('if (turnJobId) readReply(turnJobId);', $corpsEnvoi);
        $this->assertStringContainsString('fetchWithSessionGuard(`/jobs/${jobId}/speak`', $js);
        $this->assertStringNotContainsString('/chat/message/${msgId}/speak', $js);
        // Les évènements d'une AUTRE génération (réponse arrêtée qui se clôt en
        // retard) ne pilotent jamais la lecture : les trois portent leur jobId.
        $this->assertSame(3, preg_match_all('/notifyChatTurnEnded\(cidStr, \{[^\n]*jobId: state\.jobId \|\| null/', $js));
        $this->assertStringContainsString('return !d.jobId || !turnJobId || String(d.jobId) === turnJobId;', $js);
        // « Stop » pendant l'écriture arrête aussi la réponse ; la question
        // suivante attend qu'elle soit close.
        $this->assertStringContainsString('fetch(`/jobs/${turnJobId}/cancel`', $js);
        $debutTour = strpos($js, 'async function sendTurn(text) {');
        $this->assertIsInt($debutTour);
        $corpsTour = substr($js, $debutTour, 900);
        $this->assertLessThan(strpos($corpsTour, 'submitTurn();'), strpos($corpsTour, 'await previousReplySettled();'));
    }

    public function testLeMessageDicteRecoitUneConsigneDeReponseOrale(): void
    {
        $src = self::chatController();
        // Transportée dans la charge du job (le tour s'exécute dans le worker),
        // gardée par le réglage côté serveur, posée dans runSend.
        $this->assertStringContainsString("(\$_POST['voice_mode'] ?? '0') === '1' && SpeechSynthesizer::isEnabled()", $src);
        $this->assertStringContainsString("'voice_mode'      => \$voiceMode,", $src);
        $this->assertStringContainsString("\$voiceMode         = !empty(\$payload['voice_mode']);", $src);
        $this->assertStringContainsString('$systemPrompt .= "\n\n" . self::voiceModeNote($voiceLength);', $src);

        $note = ChatController::voiceModeNote();
        $this->assertStringContainsString('synthèse vocale', $note);
        $this->assertStringContainsString('sans mise en forme Markdown', $note);
        // Prompt de MÉTHODE : rien de l'écran n'est cité, il reste en français.
        $this->assertStringNotContainsString('bouton', $note);
    }

    // ------------------------------------------------------------------
    // 6 bis. Réglages de voix : vitesse de lecture et longueur des réponses
    // ------------------------------------------------------------------

    public function testUneReponseLueEstPlusCourteQuUneReponseEcriteParDefaut(): void
    {
        // LE défaut demandé : à l'oral, on répond plus court qu'à l'écrit.
        $this->assertSame('bref', VoicePreferences::DEFAULT_LENGTH);
        $this->assertArrayHasKey(VoicePreferences::DEFAULT_LENGTH, VoicePreferences::LENGTHS);
        // Colonne à '' (« n'a jamais choisi ») → défaut applicatif, jamais une
        // consigne muette ni un slug inventé.
        $this->assertSame('bref', VoicePreferences::sanitizeLength(''));
        $this->assertSame('bref', VoicePreferences::sanitizeLength(null));
        $this->assertSame('bref', VoicePreferences::sanitizeLength('inconnu'));
        $this->assertSame('detaille', VoicePreferences::sanitizeLength(' detaille '));
        $this->assertSame('bref', VoicePreferences::lengthOf(null));
        $this->assertSame('standard', VoicePreferences::lengthOf(['voice_length' => 'standard']));
    }

    public function testChaqueLongueurPorteLeTroncCommunEtSaPropreClause(): void
    {
        // Charge le contrôleur (dernière assertion) : il n'est pas dans le
        // bootstrap, et sans cet appel le test ne passait qu'après un autre.
        self::chatController();
        $notes = [];
        foreach (array_keys(VoicePreferences::LENGTHS) as $slug) {
            $note = VoicePreferences::promptNote($slug);
            // Tronc commun : ce qui ne dépend pas de la longueur est dit partout.
            $this->assertStringContainsString('synthèse vocale', $note);
            $this->assertStringContainsString('sans mise en forme Markdown', $note);
            $this->assertStringContainsString('Longueur :', $note);
            // Prompt de MÉTHODE : ne cite rien de l'écran, reste en français.
            $this->assertStringNotContainsString('bouton', $note);
            $notes[$slug] = $note;
        }
        // Trois consignes réellement distinctes — sinon le réglage ne règle rien.
        $this->assertCount(count($notes), array_unique($notes));
        $this->assertStringContainsString('très courte', $notes['bref']);
        $this->assertStringContainsString('aussi longuement qu\'à l\'écrit', $notes['detaille']);
        // Un slug absent n'éteint pas la consigne : elle retombe sur le défaut.
        $this->assertSame($notes['bref'], VoicePreferences::promptNote(''));
        $this->assertSame($notes['bref'], ChatController::voiceModeNote());
    }

    public function testLaVitesseEstUnCranConnuEtSonLibelleVientDuServeur(): void
    {
        $this->assertContains(VoicePreferences::DEFAULT_RATE, VoicePreferences::RATES);
        $this->assertSame(100, VoicePreferences::DEFAULT_RATE, 'le défaut est la vitesse naturelle');
        // Hors liste → défaut : une vitesse arbitraire ne doit pas atteindre le lecteur.
        $this->assertSame(125, VoicePreferences::sanitizeRate('125'));
        $this->assertSame(100, VoicePreferences::sanitizeRate(133));
        $this->assertSame(100, VoicePreferences::sanitizeRate(-1));
        $this->assertSame(100, VoicePreferences::rateOf(null));
        $this->assertSame(150, VoicePreferences::rateOf(['voice_rate' => 150]));

        // Libellés calculés UNE fois côté serveur (séparateur décimal selon la
        // langue) : les zéros de queue ne sont pas lus à l'écran.
        $this->assertSame('1×', VoicePreferences::rateLabel(100));
        $this->assertSame('1,5×', VoicePreferences::rateLabel(150));
        $this->assertSame('0,75×', VoicePreferences::rateLabel(75));
        $options = VoicePreferences::rateOptions();
        $this->assertCount(count(VoicePreferences::RATES), $options);
        $this->assertSame(['value', 'label'], array_keys($options[0]));

        // Le JavaScript LIT cette table, il n'en reconstruit aucun libellé.
        $tpl = self::src('templates/settings/ai.php');
        $this->assertStringContainsString('data-labels="', $tpl);
        $this->assertStringNotContainsString('toLocaleString', $tpl);
    }

    public function testLaVitesseNeChangeNiLaSyntheseNiLaFacturation(): void
    {
        // Appliquée par le LECTEUR : rien n'est re-synthétisé, donc le cache
        // des annonces reste valable et la minute facturée ne bouge pas.
        $speech = self::src('src/Helpers/SpeechSynthesizer.php');
        $this->assertStringNotContainsString('voice_rate', $speech);
        $this->assertStringNotContainsString("'speed'", $speech);
        $this->assertStringNotContainsString('$rate', $speech);

        $chat  = self::chatController();
        $debut = strpos($chat, 'public function speakMessage(string $id): void');
        $this->assertIsInt($debut);
        $this->assertStringNotContainsString('voice_rate', substr($chat, $debut, 5000));

        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('function applyRate(player_, rate)', $js);
        $this->assertStringContainsString('preservesPitch', $js);
        // La voix et les annonces suivent la vitesse…
        $this->assertStringContainsString('await enqueueAudio(seg.url, () => mode === \'speaking\' && seq === speakSeq, voiceRate);', $js);
        $this->assertStringContainsString('enqueueAudio(url, () => replyAlive(seq), voiceRate);', $js);
        // … les repères sonores JAMAIS (aucun `rate` passé : défaut 100).
        foreach (['sent', 'listen', 'tick'] as $repere) {
            $this->assertStringContainsString("enqueueAudio(earcon('$repere'), ", $js);
            $this->assertStringNotContainsString("enqueueAudio(earcon('$repere'), () => mode === 'x', voiceRate)", $js);
        }
        $this->assertStringNotContainsString('voiceRate)', substr($js, strpos($js, "earcon('tick')"), 200));
    }

    public function testLesDeuxSurfacesEcriventLaMemeColonneEtRienDAutre(): void
    {
        // Une seule source (users.voice_*), deux écrans : la carte des
        // Préférences IA et le bandeau de la conversation.
        $migration = self::src('database/migrations/075_user_voice_prefs.sql');
        $this->assertStringContainsString('voice_rate', $migration);
        $this->assertStringContainsString('voice_length', $migration);
        $this->assertStringContainsString("DEFAULT ''", $migration, 'longueur : « n\'a jamais choisi » se distingue d\'un choix');

        $routes = self::src('public/index.php');
        $this->assertStringContainsString("\$router->post('/settings/voice',", $routes);

        $ctrl = self::src('src/Controllers/UserSettingsController.php');
        // Un champ ABSENT du POST n'est pas écrit : la carte n'existe pas quand
        // la conversation vocale est coupée, et un choix ne doit pas s'effacer
        // pour autant (piège maison « champ absent = valeur par défaut »).
        $this->assertStringContainsString("array_key_exists('voice_rate', \$_POST)", $ctrl);
        $this->assertStringContainsString("array_key_exists('voice_length', \$_POST)", $ctrl);
        $this->assertStringContainsString("array_key_exists('rate', \$_POST)", $ctrl);
        $this->assertStringContainsString("array_key_exists('length', \$_POST)", $ctrl);
        $this->assertStringContainsString('SpeechSynthesizer::isEnabled()', $ctrl);
        // La réponse rend l'état ENREGISTRÉ (le front s'y aligne, il ne devine pas).
        $debut = strpos($ctrl, 'public function updateVoicePrefs(): void');
        $this->assertIsInt($debut);
        $corps = substr($ctrl, $debut, 2000);
        $this->assertStringContainsString('AuthMiddleware::requireAuth();', $corps);
        $this->assertStringContainsString("'length' => VoicePreferences::lengthOf(\$fresh)", $corps);

        // Le helper n'écrit que ce qu'on lui donne.
        $helper = self::src('src/Helpers/VoicePreferences.php');
        $this->assertStringContainsString('if ($rate !== null)', $helper);
        $this->assertStringContainsString('if ($length !== null)', $helper);
        $this->assertStringContainsString("implode(', ', \$sets)", $helper);
    }

    public function testLeBandeauEtLaCarteProposentLesMemesLongueurs(): void
    {
        $chat = self::chatController();
        // Helper PARTAGÉ par les deux points d'entrée de la page (index, show).
        $this->assertSame(2, substr_count($chat, '$voicePrefs    = self::voicePrefsPayload($user);'));
        $this->assertStringContainsString('public static function voicePrefsPayload(?array $user): array', $chat);

        $tpl = self::src('templates/chat/index.php');
        $this->assertStringContainsString('window.VOICE_PREFS = ', $tpl);
        $this->assertStringContainsString('id="voiceChatLength"', $tpl);
        // Les options viennent du serveur : aucune liste de longueurs en dur
        // dans le gabarit ni dans le JavaScript.
        $this->assertStringContainsString("(\$voicePrefs['lengths'] ?? [])", $tpl);
        $js = self::src('public/assets/js/chat.js');
        foreach (array_keys(VoicePreferences::LENGTHS) as $slug) {
            $this->assertStringNotContainsString("'$slug'", $js, "le slug « $slug » ne doit pas vivre dans le JavaScript");
        }
        // Écriture par la route commune, et l'affichage suit la réponse serveur.
        $this->assertStringContainsString("fetchWithSessionGuard('/settings/voice'", $js);
        $this->assertStringContainsString('lengthSel.value = voiceLength;', $js);
        $this->assertStringContainsString('lengthSel.disabled = true;', $js);
        // Le bandeau est DANS le formulaire du chat : Entrée sur une de ses
        // commandes enverrait le message en cours de frappe (soumission
        // implicite). La touche est confisquée, et elle seule.
        $this->assertStringContainsString('[lengthSel, volInput].forEach((commande)', $js);
        $this->assertStringContainsString("if (ev.key === 'Enter') ev.preventDefault();", $js);
        $this->assertStringContainsString('id="voiceChatBar"', $tpl);
        $this->assertLessThan(strpos($tpl, '</form>'), strpos($tpl, 'id="voiceChatBar"'), 'le bandeau vit dans le formulaire du chat');

        $settings = self::src('templates/settings/ai.php');
        $this->assertStringContainsString('name="voice_rate"', $settings);
        $this->assertStringContainsString('name="voice_length"', $settings);
        // Carte absente quand la conversation vocale n'est pas proposée.
        $this->assertStringContainsString('if (!empty($voiceEnabled)):', $settings);
    }

    public function testLeHelperEstChargeParLesDeuxListes(): void
    {
        $classes = self::src('config/classes.php');
        $posSpeech = strpos($classes, 'src/Helpers/SpeechSynthesizer.php');
        $posVoice  = strpos($classes, 'src/Helpers/VoicePreferences.php');
        $posChat   = strpos($classes, 'src/Controllers/ChatController.php');
        $this->assertIsInt($posVoice);
        $this->assertLessThan($posChat, $posVoice, 'ChatController appelle VoicePreferences');
        $this->assertIsInt($posSpeech);
        $this->assertStringContainsString("'/src/Helpers/VoicePreferences.php'", self::src('tests/bootstrap.php'));
    }

    // ------------------------------------------------------------------
    // 7. Câblage : réglage, routes, gabarit, administration, front, chargement
    // ------------------------------------------------------------------

    public function testLeReglageGouverneLeBoutonEtLesDeuxRoutes(): void
    {
        $this->assertSame('voice_chat_enabled', SpeechSynthesizer::SETTING_ENABLED);
        $this->assertStringContainsString("'/chat/message/{id}/speak'", self::src('public/index.php'));
        $this->assertStringContainsString("'/jobs/{id}/speak'", self::src('public/index.php'));

        $chat = self::chatController();
        // Les deux pages du chat calculent le drapeau, le gabarit conditionne bouton ET bandeau.
        $this->assertSame(2, substr_count($chat, '$voiceChatEnabled = SpeechSynthesizer::isEnabled();'));
        $tpl = self::src('templates/chat/index.php');
        // … et le module des ordres vocaux (voice-commands.js), chargé sous la même condition.
        $this->assertSame(3, substr_count($tpl, "if (!empty(\$hasOpenaiKey) && !empty(\$voiceChatEnabled)):"));
        $this->assertStringContainsString('id="voiceChatBtn"', $tpl);
        $this->assertStringContainsString('id="voiceChatBar"', $tpl);
        foreach (['voiceChatStatus', 'voiceChatLive', 'voiceChatSkip', 'voiceChatEnd', 'voiceChatBargeIn'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $tpl);
        }
        // À côté du micro : le bouton suit #micBtn, avant Envoyer.
        $mic   = strpos($tpl, 'id="micBtn"');
        $voice = strpos($tpl, 'id="voiceChatBtn"');
        $send  = strpos($tpl, 'id="sendBtn"');
        $this->assertLessThan($voice, $mic);
        $this->assertLessThan($send, $voice);
    }

    public function testLeBoutonDeConversationPorteUneOndeSonoreCentree(): void
    {
        $tpl   = self::src('templates/chat/index.php');
        $debut = strpos($tpl, 'id="voiceChatBtn"');
        $this->assertNotFalse($debut);
        $svg = substr($tpl, $debut, (int)strpos($tpl, '</button>', $debut) - $debut);

        // Dessinée ici même : aucune icône tierce à servir (l'app s'installe en
        // PWA, cf. la règle d'auto-hébergement des polices).
        $this->assertStringContainsString('class="voice-wave-icon"', $svg);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
        $this->assertStringContainsString('stroke-linecap="round"', $svg);
        $this->assertStringNotContainsString('<img', $svg);
        $this->assertStringNotContainsString('<rect', $svg);

        // Accordée au micro voisin : même trait, mêmes caps, même empreinte
        // horizontale. Une icône plus large ou plus dense pèserait deux fois
        // son voisin dans la pilule.
        $mic = substr($tpl, (int)strpos($tpl, 'id="micBtn"'), 600);
        preg_match('/stroke-width="([\d.]+)"/', $mic, $traitMic);
        preg_match('/stroke-width="([\d.]+)"/', $svg, $traitOnde);
        $this->assertSame($traitMic[1], $traitOnde[1], 'Trait différent de celui du micro.');
        $this->assertStringContainsString('width="18" height="18"', $svg);

        // Cinq barres verticales, de gauche à droite, à pas régulier.
        $this->assertSame(5, preg_match_all('#<path d="M([\d.]+) ([\d.]+)v([\d.]+)"\s*/>#', $svg, $m));
        $xs  = array_map('floatval', $m[1]);
        $pas = [];
        foreach ($xs as $i => $x) {
            if ($i > 0) {
                $pas[] = round($x - $xs[$i - 1], 2);
            }
        }
        $this->assertSame([$pas[0]], array_values(array_unique($pas)), 'Barres irrégulièrement espacées.');
        $this->assertGreaterThan(0, $pas[0], 'Les barres doivent être écrites de gauche à droite.');

        // Chacune centrée sur l'axe (y = 12), contenue dans le viewBox cap
        // arrondi compris, et dans l'empreinte horizontale du micro (5 → 19).
        $demi = (float)$traitOnde[1] / 2;
        foreach ($xs as $i => $x) {
            $y = (float)$m[2][$i];
            $h = (float)$m[3][$i];
            $this->assertSame(12.0, round($y + $h / 2, 2), 'Barre ' . ($i + 1) . ' hors de l\'axe.');
            $this->assertGreaterThanOrEqual(0.0, round($y - $demi, 2));
            $this->assertLessThanOrEqual(24.0, round($y + $h + $demi, 2));
            $this->assertGreaterThanOrEqual(5.0, $x, 'Barre ' . ($i + 1) . ' plus à gauche que le micro.');
            $this->assertLessThanOrEqual(19.0, $x, 'Barre ' . ($i + 1) . ' plus à droite que le micro.');
        }
        // Des hauteurs qui varient : une onde, pas un peigne.
        $this->assertGreaterThan(3, count(array_unique($m[3])));
    }

    public function testLaCarteDAdministrationEtSonHandlerNeTouchentQuALeurCle(): void
    {
        $tpl = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('id="builtinVoiceChatCard"', $tpl);
        $this->assertStringContainsString('data-builtin-key="mic_enabled"', $tpl);
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(SpeechSynthesizer::SETTING_ENABLED) ?>"', $tpl);
        $this->assertStringContainsString("const builtinKey = card.dataset.builtinKey || '';", $tpl);
        $this->assertStringContainsString("body.append(isBuiltin ? builtinKey : 'enabled', wanted ? '1' : '0');", $tpl);
        // Dictée, conversation vocale, suggestions de suite, tâches programmées, workflows : cinq cartes intégrées.
        $this->assertStringContainsString('count($tools) + 5', $tpl);

        $admin = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString('public const BUILTIN_TOOL_KEYS = [', $admin);
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertSame(
            ['mic_enabled', SpeechSynthesizer::SETTING_ENABLED, FollowUpSuggestion::SETTING_ENABLED,
             ScheduledTask::SETTING_ENABLED, ScheduledTask::SETTING_AUTO, Workflow::SETTING_ENABLED, Workflow::SETTING_AUTO],
            AdminController::BUILTIN_TOOL_KEYS
        );
        $debut = strpos($admin, 'public function saveBuiltinTools(): void');
        $this->assertIsInt($debut);
        $corps = substr($admin, $debut, 1500);
        // Une clé absente du POST n'est pas touchée : basculer une carte n'éteint pas l'autre.
        $this->assertStringContainsString('if (!array_key_exists($key, $_POST))', $corps);
        $this->assertStringContainsString('foreach (self::BUILTIN_TOOL_KEYS as $key)', $corps);
        $this->assertStringNotContainsString("Settings::set('mic_enabled'", $corps);
    }

    public function testLeFrontPartageLeSocleEtReutiliseLEnvoiEtLaClotureDuTour(): void
    {
        $js = self::src('public/assets/js/chat.js');
        // Un seul socle Realtime, deux usages.
        $this->assertSame(1, substr_count($js, 'function createRealtimeTranscriber('));
        $this->assertStringContainsString("purpose: 'dictation',", $js);
        $this->assertStringContainsString("purpose: 'conversation',", $js);
        $this->assertStringContainsString("body.append('purpose', purpose);", $js);
        // Micro RELÂCHÉ dès que le message part : rien ne repasse dans un
        // message, et la capture est fermée (iOS ne rend le haut-parleur et le
        // volume du média qu'à cette condition) ; repris à l'écoute suivante.
        // Seule exception, et c'est un réglage : l'interruption à la voix
        // (cf. testLInterruptionALaVoixEstUnReglageParAppareil).
        $this->assertStringContainsString('sender.replaceTrack(null)', $js);
        $this->assertStringContainsString('transcriber.releaseMic();', $js);
        $this->assertStringNotContainsString('detachMic', $js);
        $debutRelease = strpos($js, 'function releaseMic() {');
        $this->assertIsInt($debutRelease);
        $corpsRelease = substr($js, $debutRelease, 700);
        $this->assertStringContainsString('mediaStream?.getTracks().forEach(piste => piste.stop());', $corpsRelease);
        $this->assertStringContainsString('mediaStream = micTrack = null;', $corpsRelease);
        $this->assertStringContainsString("navigator.mediaDevices.getUserMedia({ audio: true })", substr($js, strpos($js, 'async function attachMic() {'), 1500));
        // Repères sonores synthétisés sur place (aucun fichier à servir), un
        // seul élément audio en file, tics tant que MIA travaille.
        $this->assertStringContainsString('function synthWav(', $js);
        foreach (['sent:', 'listen:', 'tick:'] as $repere) {
            $this->assertStringContainsString($repere, substr($js, strpos($js, 'const EARCON_SPECS = {'), 400));
        }
        $this->assertStringContainsString('function enqueueAudio(url, guard, rate)', $js);
        $this->assertStringContainsString("enqueueAudio(earcon('sent')", $js);
        $this->assertStringContainsString("enqueueAudio(earcon('listen')", $js);
        $this->assertStringContainsString("enqueueAudio(earcon('tick')", $js);
        // Annonces parlées : chaque action tracée est émise en évènement DOM et
        // consommée par la conversation (audio du libellé, en cache).
        $this->assertStringContainsString("document.dispatchEvent(new CustomEvent('mia:chat-action', { detail: { convId: cidStr, jobId: state.jobId || null, action: json.action } }));", $js);
        $this->assertStringContainsString("document.addEventListener('mia:chat-action'", $js);
        $this->assertStringContainsString("'/chat/voice/cue?type='", $js);
        $this->assertStringContainsString('if (!type || action.ok === false) return;', $js);
        $this->assertStringContainsString('const MAX_CUES_PER_TURN = 4;', $js);
        // Volume : curseur mémorisé par compte, masqué là où le volume n'est pas réglable (iOS).
        $this->assertStringContainsString("'voicePrefs:' + (window.CURRENT_USER_ID ?? 'anon')", $js);
        $this->assertStringContainsString('function volumeIsWritable()', $js);
        $this->assertStringContainsString('volWrap.hidden = true;', $js);
        $tpl = self::src('templates/chat/index.php');
        $this->assertStringContainsString('id="voiceChatVolumeWrap"', $tpl);
        $this->assertStringContainsString('<input type="range" id="voiceChatVolume"', $tpl);
        // Le tour part par submitForm (UN chemin d'envoi), marqué voice_mode.
        $this->assertStringContainsString("fd.append('voice_mode', voiceModeActive ? '1' : '0');", $js);
        $this->assertStringContainsString('outcome = await submitForm();', $js);
        $this->assertStringContainsString("return 'tool';", $js);
        // La fin d'un tour est signalée par les DEUX clôtures et consommée par la conversation.
        $this->assertSame(3, substr_count($js, 'notifyChatTurnEnded(cidStr, {'), 'done, quota, flux clos');
        $this->assertStringContainsString("document.addEventListener('mia:chat-turn-ended'", $js);
        $this->assertStringContainsString('/speak`', $js);
        // Exclusion mutuelle et changement de conversation.
        $this->assertStringContainsString('window.stopDictation = stopRecording;', $js);
        $this->assertStringContainsString('window.stopVoiceChat = () => endConversation();', $js);
        $this->assertStringContainsString('window.stopVoiceChat?.();', $js);
        $this->assertStringContainsString('window.stopDictation?.();', $js);

        $css = self::src('public/assets/css/main.css');
        foreach (['.btn-voice.voice-listening', '.btn-voice.voice-connecting', '.voice-chat-bar', '.voice-chat-bar[hidden]', '.voice-chat-skip[hidden]', '.voice-chat-volume[hidden]', '.voice-chat-barge', '.voice-chat-barge[hidden]', '.voice-chat-barge.is-on'] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
    }

    /**
     * Interrompre MIA d'un « stop » suppose le micro OUVERT pendant la
     * lecture : des minutes d'écoute en plus, et sur iOS une voix moins forte
     * (la raison même du micro relâché). C'est donc un réglage par appareil,
     * éteint par défaut sur iPhone et iPad — et pendant la lecture, seul un
     * ordre d'arrêt est retenu.
     */
    public function testLInterruptionALaVoixEstUnReglageParAppareil(): void
    {
        $js = self::src('public/assets/js/chat.js');

        // Réglage mémorisé avec le volume (par compte, sur ce navigateur), défaut selon l'appareil.
        $this->assertStringContainsString('const saved = readVoicePrefs().bargeIn;', $js);
        $this->assertStringContainsString("return typeof saved === 'boolean' ? saved : !IS_IOS;", $js);
        $this->assertStringContainsString('writeVoicePrefs({ bargeIn });', $js);
        $this->assertStringContainsString('if (!VC) return false;', $js, 'sans le module des ordres, personne ne saurait reconnaître « stop »');

        // Le micro n'est ouvert pendant la lecture QUE si le réglage le veut…
        $debutPrise = strpos($js, 'async function beginSpeaking(seq) {');
        $this->assertIsInt($debutPrise);
        $corpsPrise = substr($js, $debutPrise, (int)strpos($js, "\n    }\n", $debutPrise) - $debutPrise);
        $garde  = strpos($corpsPrise, "if (bargeIn && mode === 'speaking' && seq === speakSeq) {");
        $attach = strpos($corpsPrise, 'transcriber.attachMic(),');
        $this->assertIsInt($garde);
        $this->assertIsInt($attach);
        $this->assertLessThan($attach, $garde);
        $this->assertSame(1, substr_count($corpsPrise, 'attachMic('));
        // … et il est repris AVANT la première note de la voix.
        $debutLecture = strpos($js, 'function readReply(jobId) {');
        $this->assertIsInt($debutLecture);
        $corpsLecture = substr($js, $debutLecture, 2500);
        $prise = strpos($corpsLecture, 'await beginSpeaking(seq);');
        $voix  = strpos($corpsLecture, 'await enqueueAudio(seg.url,');
        $this->assertIsInt($prise);
        $this->assertIsInt($voix);
        $this->assertLessThan($voix, $prise, 'le micro est repris AVANT la première note de la voix');

        // … et il est relâché AVANT le repère « je vous écoute ».
        $debutEcoute = strpos($js, 'async function startListening() {');
        $corpsEcoute = substr($js, (int)$debutEcoute, 700);
        $this->assertLessThan(strpos($corpsEcoute, "enqueueAudio(earcon('listen')"), strpos($corpsEcoute, 'if (bargeMicOpen) {'));

        // Pendant la lecture, seul un ordre d'arrêt est consulté — jamais un ordre de carte.
        $debutEntendre = strpos($js, 'function hearWhileSpeaking(transcript) {');
        $this->assertIsInt($debutEntendre);
        $corpsEntendre = substr($js, $debutEntendre, (int)strpos($js, "\n    }\n", $debutEntendre) - $debutEntendre);
        // (et la phrase est comparée au texte que MIA est en train de lire : sa propre voix n'interrompt rien).
        $this->assertStringContainsString('VC.interruption(said, spokenText())', $corpsEntendre);
        foreach (['cardOrder(', 'cardCommand(', 'sendTurn(', 'submitForm('] as $interdit) {
            $this->assertStringNotContainsString($interdit, $corpsEntendre);
        }

        // Une phrase COMMENCÉE pendant la lecture n'est jamais un message : le
        // socle transmet l'identifiant du tour, la conversation note son origine.
        $this->assertStringContainsString("const itemId = m.item_id || '';", $js);
        $this->assertStringContainsString("opts.onCompleted?.(m.transcript || m.delta || '', itemId);", $js);
        $debutFin = strpos($js, 'onCompleted: (transcript, itemId) => {', (int)strpos($js, "purpose: 'conversation',"));
        $this->assertIsInt($debutFin);
        $corpsFin = substr($js, $debutFin, 500);
        $this->assertLessThan(strpos($corpsFin, 'endTurn();'), strpos($corpsFin, "if (originOf(itemId) === 'speaking') {"));

        // Le silence de l'utilisateur pendant la lecture n'est pas une panne.
        $this->assertStringContainsString("expectsSpeech: () => mode === 'listening',", $js);
        $this->assertStringContainsString('if (opts.expectsSpeech && !opts.expectsSpeech()) return;', $js);

        // Le bandeau porte la bascule, à côté de Passer.
        $tpl = self::src('templates/chat/index.php');
        $this->assertLessThan(strpos($tpl, 'id="voiceChatSkip"'), strpos($tpl, 'id="voiceChatBargeIn"'));
        $this->assertStringContainsString('aria-pressed="false"', substr($tpl, (int)strpos($tpl, 'id="voiceChatBargeIn"'), 200));
    }

    /**
     * Sur un téléphone, une conversation vocale est faite de silences (MIA
     * réfléchit, MIA parle) : passé le délai de veille, l'écran s'éteignait, le
     * système suspendait la page et la conversation se coupait net. Le verrou
     * d'écran est demandé à l'entrée dans le mode, rendu à la sortie, redemandé
     * au retour au premier plan — et la dictée, elle, n'y touche pas.
     */
    public function testLEcranResteAllumePendantLaConversationVocaleEtSeulementAlors(): void
    {
        $js = self::src('public/assets/js/chat.js');
        $verrou = "navigator.wakeLock.request('screen')";
        // Un seul verrou, dans l'IIFE de la conversation : le socle Realtime et
        // la dictée, déclarés avant elle, n'en portent aucun.
        $this->assertSame(1, substr_count($js, $verrou));
        $debutConversation = strpos($js, 'Conversation vocale — écouter → envoyer → MIA répond → lire');
        $this->assertIsInt($debutConversation);
        $this->assertGreaterThan($debutConversation, strpos($js, $verrou));
        $this->assertStringNotContainsString('wakeLock', substr($js, 0, $debutConversation), 'la dictée ne garde pas l\'écran allumé');

        // Pris et rendu au point UNIQUE de changement d'état : `on` = mode ≠ off.
        $debutSetMode = strpos($js, 'function setMode(next, statusText) {');
        $this->assertIsInt($debutSetMode);
        $corpsSetMode = substr($js, $debutSetMode, 400);
        $this->assertStringContainsString("const on = next !== 'off';", $corpsSetMode);
        $this->assertStringContainsString('keepScreenAwake(on);', $corpsSetMode);

        // Rendu explicitement à la sortie du mode ; relâché par le navigateur
        // quand la page est masquée, donc redemandé au retour au premier plan.
        $debutKeep = strpos($js, 'function keepScreenAwake(on) {');
        $this->assertIsInt($debutKeep);
        $corpsKeep = substr($js, $debutKeep, 500);
        $this->assertStringContainsString('wakeLockWanted = false;', $corpsKeep);
        $this->assertStringContainsString('if (sentinel) dropWakeLock(sentinel);', $corpsKeep);
        $this->assertStringContainsString('const p = sentinel.release();', $js);
        $this->assertStringContainsString(
            "document.addEventListener('visibilitychange', () => { if (!document.hidden) acquireWakeLock(); });",
            $js
        );

        // Jamais bloquant : sans verrou (navigateur ancien, page non sécurisée)
        // ou sur refus (économiseur de batterie), la conversation continue.
        $debutAcquire = strpos($js, 'async function acquireWakeLock() {');
        $this->assertIsInt($debutAcquire);
        $corpsAcquire = substr($js, $debutAcquire, 1200);
        $this->assertStringContainsString('document.hidden', $corpsAcquire);
        $this->assertStringContainsString('if (!navigator.wakeLock', $corpsAcquire);
        $this->assertStringContainsString('} catch (e) {', $corpsAcquire);
        $this->assertStringContainsString('wakeLockPending = false;', $corpsAcquire);

        // Les guides le disent, et les nouveautés l'annoncent.
        $this->assertStringContainsString('reste allumé', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('reste allumé', self::src('docs/user/fonctionnalites/conversations/converser-a-la-voix.md'));
        $this->assertStringContainsString('conversation-vocale-ecran-allume', self::src('docs/NOUVEAUTES.md'));
    }

    public function testLaClasseEstChargeeParLesDeuxListes(): void
    {
        $classes = self::src('config/classes.php');
        $posSettings = strpos($classes, "src/Models/Settings.php");
        $posSpeech   = strpos($classes, "src/Helpers/SpeechSynthesizer.php");
        $posChat     = strpos($classes, "src/Controllers/ChatController.php");
        $this->assertIsInt($posSpeech);
        $this->assertLessThan($posSpeech, $posSettings, 'SpeechSynthesizer lit Settings');
        $this->assertLessThan($posChat, $posSpeech, 'ChatController appelle SpeechSynthesizer');
        $this->assertStringContainsString("'/src/Helpers/SpeechSynthesizer.php'", self::src('tests/bootstrap.php'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationParleDeLaConversationVocale(): void
    {
        $this->assertStringContainsString('conversation vocale', strtolower(self::src('docs/GUIDE-UTILISATEUR.md')));
        $this->assertStringContainsString('Conversation vocale', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('conversation-vocale', self::src('docs/NOUVEAUTES.md'));
        $this->assertFileExists(ROOT . '/docs/user/fonctionnalites/conversations/converser-a-la-voix.md');
        $this->assertFileExists(ROOT . '/docs/user/admin/outils/regler-la-conversation-vocale.md');
        $this->assertStringContainsString('converser-a-la-voix.md', self::src('docs/user/fonctionnalites/conversations/index.md'));
        $this->assertStringContainsString('regler-la-conversation-vocale.md', self::src('docs/user/admin/outils/index.md'));
        $this->assertStringContainsString('## Conversation vocale', self::src('CLAUDE.md'));
        // Réglages de voix : la page des Préférences IA les décrit, et les
        // nouveautés annoncent le changement de défaut (plus court à l'oral).
        $this->assertStringContainsString('Régler la voix', self::src('docs/user/fonctionnalites/personnalisation/personnaliser-les-reponses.md'));
        $this->assertStringContainsString('voix-reglages', self::src('docs/NOUVEAUTES.md'));
        // Lecture en direct : les guides disent quand la voix démarre, les nouveautés l'annoncent.
        $this->assertStringContainsString('conversation-vocale-lecture-directe', self::src('docs/NOUVEAUTES.md'));
        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/user/fonctionnalites/conversations/converser-a-la-voix.md'] as $guide) {
            $this->assertStringContainsString('dès sa première phrase écrite', self::src($guide), $guide);
        }
        $this->assertStringContainsString('dès sa première phrase', self::src('docs/user/admin/outils/regler-la-conversation-vocale.md'));
    }
}
