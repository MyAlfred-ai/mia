<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Conversation vocale « mains libres » : couper la parole à MIA, se servir de
 * ses connecteurs, entendre une carte avant de la décider.
 *
 * Trois défauts corrigés, chacun gardé ici :
 *
 *   1. « stop » n'était entendu qu'une fois le tour clos ET transcrit, pendant
 *      que MIA continuait à pleine voix — pendant la lecture, le micro part
 *      désormais vers une SECONDE session Realtime, ouverte au silence court
 *      (« stop » compris en moins d'une seconde) ; si elle manque, la
 *      détection de la session d'écoute est raccourcie (servie par le
 *      serveur, posée par le socle), et un refus de ce réglage ne coupe
 *      jamais la conversation ;
 *   2. à la voix, personne n'ouvre le menu « + » — les connecteurs reliés
 *      s'activent à la demande, sans relever l'effort de réflexion ;
 *   3. un email ne se relit pas les mains occupées — « lis-le » le fait lire,
 *      d'un texte RELU EN BASE, jamais reçu du navigateur.
 *
 * Le comportement de la boucle (voix baissée, pause, reprise, gestes) est
 * exécuté sous node par VoiceConversationFlowTest ; la reconnaissance des
 * ordres par VoiceCommandsTest. Ici : les formes pures du serveur et le
 * câblage.
 */
final class VoiceHandsFreeTest extends TestCase
{
    private static function src(string $relatif): string
    {
        $chemin = ROOT . '/' . $relatif;
        self::assertFileExists($chemin);
        return (string)file_get_contents($chemin);
    }

    private static function chatController(): string
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        return self::src('src/Controllers/ChatController.php');
    }

    /** Corps d'une fonction du chat, de sa signature à sa fermeture (indentation de l'IIFE). */
    private static function corpsJs(string $js, string $signature): string
    {
        $debut = strpos($js, $signature);
        self::assertIsInt($debut, "« $signature » introuvable dans chat.js");
        $fin = strpos($js, "\n    }\n", $debut);
        self::assertIsInt($fin);
        return substr($js, $debut, $fin - $debut);
    }

    // ------------------------------------------------------------------
    // 1. Ce que MIA lit d'une carte
    // ------------------------------------------------------------------

    public function testUnEmailSeLitDestinataireObjetPuisTexte(): void
    {
        $texte = SpeechSynthesizer::cardText(['mail' => [
            'destinataire' => 'Jean Dupont <jean@acme.fr>, marie@acme.fr',
            'cc'           => 'Paul Martin <paul@acme.fr>',
            'objet'        => 'Réunion de **jeudi**',
            'corps'        => "Bonjour Jean,\n\nJe confirme notre réunion de jeudi à 14 h.\n\nBien à vous",
            'reponse'      => false,
        ]]);

        $this->assertStringStartsWith('Email pour Jean Dupont, marie@acme.fr. En copie : Paul Martin. Objet : Réunion de jeudi.', $texte);
        $this->assertStringContainsString('Je confirme notre réunion de jeudi à 14 h.', $texte);
        // Rien qui ne se dise : ni adresse entre chevrons, ni mise en forme.
        foreach (['<', '>', '**', 'jean@acme.fr'] as $bruit) {
            $this->assertStringNotContainsString($bruit, $texte, "« $bruit » ne se lit pas");
        }
        $this->assertStringNotContainsString('La suite est affichée', $texte, 'un message court est lu en entier');

        // Une réponse : le destinataire vient du message d'origine, parfois absent de la carte.
        $this->assertStringStartsWith('Réponse à Jean Dupont.', SpeechSynthesizer::cardText(['mail' => ['destinataire' => 'Jean Dupont <j@a.fr>', 'corps' => 'Oui.', 'reponse' => true]]));
        $this->assertStringStartsWith('Réponse dans le fil.', SpeechSynthesizer::cardText(['mail' => ['destinataire' => '', 'corps' => 'Oui.', 'reponse' => true]]));
    }

    public function testUnLongMessageEstCoupeAUneFinDePhraseEtMiaLeDit(): void
    {
        $phrase = 'Voici une phrase assez longue pour remplir le message.';
        $texte  = SpeechSynthesizer::cardText(['mail' => ['destinataire' => 'x@y.fr', 'objet' => 'Long', 'corps' => str_repeat($phrase . ' ', 60)]]);

        $this->assertStringEndsWith($phrase . ' La suite est affichée à l\'écran.', $texte, 'coupé à une fin de phrase, et dit');
        $this->assertLessThan(SpeechSynthesizer::MAX_INPUT_CHARS, mb_strlen($texte), 'une seule synthèse suffit');
        $this->assertLessThan(SpeechSynthesizer::CARD_BODY_CHARS + 200, mb_strlen($texte));

        // Corps déjà raccourci dans l'aperçu de la carte : MIA le dit aussi.
        $this->assertStringEndsWith('La suite est affichée à l\'écran.', SpeechSynthesizer::cardText(['mail' => ['destinataire' => 'x@y.fr', 'corps' => 'Début du message.', 'tronque' => true]]));
    }

    public function testUneCarteDApprobationSeLitParSonIntituleCeQuElleViseEtSonExtrait(): void
    {
        $texte = SpeechSynthesizer::cardText([
            'action'  => 'Créer un évènement Google Agenda',
            'fichier' => 'Point client',
            'impacts' => ['Un évènement sera créé dans votre agenda Google principal.'],
            'apercu'  => 'Ordre du jour : le budget.',
        ]);
        $this->assertSame('Créer un évènement Google Agenda. Point client. Ordre du jour : le budget.', $texte);

        // Rien à lire : la route répond 204, MIA ne synthétise pas du vide.
        $this->assertSame('', SpeechSynthesizer::cardText([]));
        $this->assertSame('', SpeechSynthesizer::cardText(['mail' => ['destinataire' => '', 'corps' => '']]));
    }

    public function testLesDestinatairesSeDisentParLeurNomQuatreAuPlus(): void
    {
        $this->assertSame('Jean Dupont', SpeechSynthesizer::spokenRecipients('Jean Dupont <jean@acme.fr>'));
        $this->assertSame('jean@acme.fr', SpeechSynthesizer::spokenRecipients('<jean@acme.fr>'));
        $this->assertSame('a@b.fr, c@d.fr, e@f.fr, g@h.fr et 2 autres', SpeechSynthesizer::spokenRecipients('a@b.fr; c@d.fr, e@f.fr, g@h.fr, i@j.fr, k@l.fr'));
        $this->assertSame('', SpeechSynthesizer::spokenRecipients(' , ; '));
    }

    public function testLaCarteSeLitDansLaLangueDeLInterface(): void
    {
        I18n::use('en');
        try {
            $texte = SpeechSynthesizer::cardText(['mail' => ['destinataire' => 'Jean Dupont <j@a.fr>', 'objet' => 'Thursday', 'corps' => 'See you then.', 'tronque' => true]]);
        } finally {
            I18n::use('fr');
        }
        $this->assertSame('Email to Jean Dupont. Subject: Thursday. See you then. The rest is shown on screen.', $texte);
    }

    // ------------------------------------------------------------------
    // 2. Route de lecture d'une carte
    // ------------------------------------------------------------------

    public function testLaLectureDUneCarteRelitLaCarteEnBaseDerriereLesMemesPortes(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function voiceCard(string $id): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 3200);

        // Même ordre que la lecture d'une réponse : réglage → conversation de
        // la personne → carte EN ATTENTE → quota avant l'appel payant →
        // synthèse → facturation de l'audio reçu.
        $etapes = [
            'SpeechSynthesizer::isEnabled()',
            'Conversation::belongsToUser(',
            'DrivePendingAction::findPending(',
            'isCreditQuotaExceeded(',
            'session_write_close();',
            'SpeechSynthesizer::speak(',
            'UsageMeter::speech(',
        ];
        $avant = -1;
        foreach ($etapes as $etape) {
            $ici = strpos($corps, $etape);
            $this->assertIsInt($ici, "« $etape » absent de voiceCard");
            $this->assertGreaterThan($avant, $ici, "« $etape » n'est pas à sa place");
            $avant = $ici;
        }
        // Le texte vient de l'aperçu persisté — seul l'identifiant de la carte arrive du navigateur.
        $this->assertStringContainsString("SpeechSynthesizer::cardText((array)\$action['preview'])", $corps);
        $this->assertStringContainsString("\$_POST['action_id']", $corps);
        foreach (["\$_POST['text']", "\$_POST['texte']", "\$_POST['corps']", "\$_POST['content']"] as $interdit) {
            $this->assertStringNotContainsString($interdit, $corps, 'la route ne lit jamais un texte envoyé par le navigateur');
        }
        $this->assertStringContainsString('http_response_code(204);', $corps, 'carte tranchée ou vide : rien à lire');

        $this->assertMatchesRegularExpression(
            "#\\\$router->post\\('/chat/\\{id\\}/voice/card',\\s+\\[ChatController::class, 'voiceCard'\\]\\);#",
            self::src('public/index.php')
        );

        // Côté navigateur : seul l'identifiant part, et lire ne décide de rien.
        $js      = self::src('public/assets/js/chat.js');
        $lecture = self::corpsJs($js, 'async function readCardAloud(card, alive) {');
        $this->assertStringContainsString("fd.append('action_id', id);", $lecture);
        $this->assertStringContainsString('/voice/card`', $lecture);
        foreach (['resolveDriveAction(', 'runMailDraftAct(', 'submitForm(', 'innerHTML', 'textContent ||'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $lecture);
        }
        $this->assertStringContainsString("if (cmd.act !== 'approve' && cmd.act !== 'cancel') return [];", $js,
            'sur une carte ordinaire, tout autre ordre qu\'approuver ou annuler ne doit jamais passer pour un refus');
    }

    public function testLeModeleRappelleQueLEmailPeutSEcouter(): void
    {
        self::chatController();
        $this->assertStringContainsString('« lis-le »', ChatController::voiceCardWords(true));
        $this->assertStringNotContainsString('« lis-le »', ChatController::voiceCardWords(false),
            'pour une carte ordinaire, le modèle dit déjà en une phrase ce qu\'il a préparé');
    }

    // ------------------------------------------------------------------
    // 3. Détection de fin de tour pendant la lecture
    // ------------------------------------------------------------------

    public function testLaDetectionDeTourPendantLaLectureEstCourteEtServieParLeServeur(): void
    {
        $src = self::chatController();

        foreach (ChatController::conversationSessionCandidates() as [$modele, $base]) {
            $vad = ChatController::interruptionTurnDetection($base);
            // TOUJOURS du même type : constaté contre l'API, une session passée
            // en sémantique ignore un retour à la détection au silence.
            $this->assertSame($base['type'], $vad['type'], "$modele : OpenAI n'applique pas un changement de TYPE en cours de session");
            $this->assertNotSame($base, $vad, 'la lecture a sa propre détection, plus vive');
            // Chaque champ de la détection de lecture est nommé par celle de la
            // session : un `session.update` ne remet pas à sa valeur un champ
            // qu'il ne nomme pas (le seuil relevé resterait pour l'écoute).
            foreach (array_keys($vad) as $champ) {
                $this->assertArrayHasKey($champ, $base, "le retour à l'écoute doit redire « $champ »");
            }
            if ($vad['type'] === 'semantic_vad') {
                $this->assertSame('high', $vad['eagerness']);
                $this->assertSame('medium', $base['eagerness'], 'à l\'écoute, une phrase hésitante garde le temps de finir');
            } else {
                $this->assertLessThanOrEqual(500, $vad['silence_duration_ms'], '« stop » doit être entendu en une demi-seconde');
                $this->assertGreaterThanOrEqual(200, $vad['silence_duration_ms'], 'trop court, un mot serait coupé en deux');
                $this->assertLessThan($base['silence_duration_ms'], $vad['silence_duration_ms']);
                $this->assertGreaterThan($base['threshold'], $vad['threshold'], 'un reste de la voix de MIA ne doit pas ouvrir un tour à lui seul');
                $this->assertLessThan(0.9, $vad['threshold']);
            }
        }

        // Servie avec le jeton, pour la conversation seulement : aucun réglage recopié en JavaScript.
        $debut = strpos($src, 'public function realtimeTranscriptionToken');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 16000);
        $this->assertStringContainsString("'turn_detections' => (\$conversation && is_array(\$usedVad))", $corps);
        $this->assertStringContainsString("['base' => \$usedVad, 'fast' => self::interruptionTurnDetection(\$usedVad)]", $corps);
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringNotContainsString('silence_duration_ms', $js, 'la détection de tour se règle côté serveur');
        $this->assertStringContainsString('turnDetections = (data && data.turn_detections) || {};', $js);
    }

    public function testLaDetectionChangeEnCoursDeSessionEtUnRefusNEstJamaisFatal(): void
    {
        $js = self::src('public/assets/js/chat.js');

        // session.update d'une session de TRANSCRIPTION, identifié par nous.
        $pose = self::corpsJs($js, 'function setTurnDetection(name) {');
        $this->assertStringContainsString("type: 'session.update',", $pose);
        $this->assertStringContainsString("event_id: 'mia_td_' + (++tdSeq),", $pose);
        $this->assertStringContainsString("session: { type: 'transcription', audio: { input: { turn_detection: td } } },", $pose);
        $this->assertStringContainsString('if (!td || tdRejected || name === tdCurrent) return false;', $pose, 'ni doublon, ni insistance après un refus');
        $this->assertStringContainsString('setTurnDetection,', $js);

        // Refus d'OpenAI : reconnu à NOTRE identifiant, AVANT le traitement
        // d'une erreur ordinaire (qui arrête la session).
        $erreur = substr($js, (int)strpos($js, "} else if (type === 'error') {"), 1200);
        // … ou, sans identifiant rendu, à ce qu'il arrive pendant qu'un
        // changement attend sa confirmation (`session.updated`).
        $refus  = strpos($erreur, "if (String(e.event_id || '').startsWith('mia_td_') || turnDetectionPending()) {");
        $this->assertStringContainsString("} else if (type === 'session.updated' || type === 'transcription_session.updated') {", $js);
        $this->assertStringContainsString('tdPendingAt = Date.now();', $pose);
        $fatal  = strpos($erreur, 'stopRecording();');
        $this->assertIsInt($refus);
        $this->assertIsInt($fatal);
        $this->assertLessThan($fatal, $refus);
        $this->assertStringContainsString('tdRejected = true;', substr($erreur, $refus, 400));

        // Courte AVANT d'ouvrir le micro pour la lecture — quand c'est la
        // session d'écoute qui l'ouvre (repli : la seconde session n'est pas
        // là) ; celle de la session pour écouter.
        $prise = self::corpsJs($js, 'function openBargeMic() {');
        $this->assertStringContainsString('if (ear === transcriber) {', $prise);
        $this->assertLessThan(strpos($prise, 'return ear.attachMic();'), strpos($prise, "transcriber.setTurnDetection?.('fast');"));
        $this->assertSame(1, substr_count($js, "setTurnDetection?.('fast')"), 'la détection courte de la session d\'écoute n\'est posée qu\'en repli');
        $ecoute = self::corpsJs($js, 'async function startListening() {');
        $this->assertLessThan(strpos($ecoute, 'transcriber.attachMic();'), strpos($ecoute, "transcriber.setTurnDetection?.('base');"));
        // Une nouvelle session repart sur SA détection.
        $this->assertStringContainsString("tdCurrent = 'base';", $js);
    }

    // ------------------------------------------------------------------
    // 3 bis. Seconde session : l'oreille de la lecture
    // ------------------------------------------------------------------

    public function testLaSecondeSessionEstOuverteAuSilenceCourtSurLesModelesParTour(): void
    {
        self::chatController();
        $candidats = ChatController::interruptionSessionCandidates();
        $parTour   = array_keys(array_filter(ChatController::TRANSCRIPTION_MODELS, static fn($td): bool => $td !== null));

        // Les modèles PAR TOUR, dans leur ordre de préférence : un modèle live
        // refuse toute détection de tour, il n'y aurait aucun « stop » à lire.
        $this->assertSame($parTour, array_column($candidats, 0));
        $court = ChatController::interruptionTurnDetection(['type' => 'server_vad']);
        foreach ($candidats as [$modele, $vad]) {
            // AU SILENCE dès l'ouverture : c'est tout l'intérêt d'une session à
            // part — OpenAI ignore un passage de la sémantique au silence en
            // cours de session (constaté contre l'API).
            $this->assertSame('server_vad', $vad['type'], $modele);
            $this->assertSame($court, $vad, 'une seule définition du « silence court »');
            $this->assertLessThanOrEqual(500, $vad['silence_duration_ms'], '« stop » doit être rendu en moins d\'une seconde');
            $this->assertGreaterThanOrEqual(200, $vad['silence_duration_ms'], 'trop court, un mot serait coupé en deux');
            $this->assertGreaterThan(0.5, $vad['threshold'], 'un reste de la voix de MIA ne doit pas ouvrir un tour à lui seul');
        }
        // La session d'ÉCOUTE, elle, reste sémantique d'abord : elle laisse finir une demande.
        $this->assertSame('semantic_vad', ChatController::conversationSessionCandidates()[0][1]['type']);
    }

    public function testLaRouteSertLaSecondeSessionDerriereLesGardesDeLaConversation(): void
    {
        $src   = self::chatController();
        $debut = strpos($src, 'public function realtimeTranscriptionToken');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 16000);

        // Même interrupteur d'administration et même quota que la conversation.
        $reglage = strpos($corps, 'if ($voiceChat && !SpeechSynthesizer::isEnabled()) {');
        $quota   = strpos($corps, 'isCreditQuotaExceeded(');
        $choix   = strpos($corps, '$candidates = self::interruptionSessionCandidates();');
        // L'APPEL à OpenAI (son adresse entière), pas le commentaire qui le nomme plus haut.
        $session = strpos($corps, "'https://api.openai.com/v1/realtime/client_secrets'");
        foreach (['reglage', 'quota', 'choix', 'session'] as $v) {
            $this->assertIsInt($$v, "$v absent de la route du jeton");
        }
        $this->assertLessThan($quota, $reglage);
        $this->assertLessThan($session, $quota, 'le quota se contrôle AVANT d\'ouvrir une session');
        // Ouverte pendant que l'on parle déjà : la session PHP est rendue avant
        // l'appel à OpenAI, sinon l'envoi du premier message attendrait derrière.
        $rendue = strpos($corps, 'session_write_close();');
        $this->assertIsInt($rendue);
        $this->assertLessThan($session, $rendue);
        $this->assertGreaterThan($quota, $rendue, 'les gardes lisent encore la session');
        // Elle n'a aucune détection à changer en route : rien n'est servi pour elle.
        $this->assertStringContainsString("'turn_detections' => (\$conversation && is_array(\$usedVad))", $corps);
    }

    public function testLeMicroDeLaLectureNePartQueVersUneSessionALaFois(): void
    {
        $js = self::src('public/assets/js/chat.js');

        // Le socle connaît ce troisième usage, et sait rester muet pour lui.
        $this->assertStringContainsString("const purpose = ['conversation', 'interruption'].includes(opts.purpose) ? opts.purpose : 'dictation';", $js);
        $this->assertStringContainsString('if (!opts.background && handleQuotaExceededPayload(err)) {', $js, 'une seule fenêtre de quota : celle de la session d\'écoute');

        // Deux sessions = deux captures du même micro : une piste devenue muette
        // (navigateur qui n'en sert qu'une à la fois) est reprise, jamais envoyée telle quelle.
        $prise = substr($js, (int)strpos($js, 'async function attachMic() {'), 1400);
        $muette = strpos($prise, "if (micTrack && micTrack.readyState === 'live' && micTrack.muted) {");
        $this->assertIsInt($muette);
        $this->assertLessThan(strpos($prise, "if (!micTrack || micTrack.readyState !== 'live') {"), $muette);

        // La seconde session : en arrière-plan, sans alerte de silence, pannes muettes.
        $debut = strpos($js, 'const interrupter = createRealtimeTranscriber({');
        $this->assertIsInt($debut);
        $oreille = substr($js, $debut, (int)strpos($js, "\n    });\n", $debut) - $debut);
        $this->assertStringContainsString("purpose: 'interruption',", $oreille);
        $this->assertStringContainsString('background: true,', $oreille);
        $this->assertStringContainsString('expectsSpeech: () => false,', $oreille);
        $this->assertStringContainsString('onFatal: () => {},', $oreille);
        // Ce qu'elle entend PENDANT la lecture n'est jamais un message : ni
        // envoi, ni routage d'un tour — un ordre d'arrêt, ou rien.
        foreach (['endTurn(', 'sendTurn(', 'routeTurn(', 'submitForm(', 'turnText'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $oreille, "la seconde session ne doit jamais produire un message ($interdit)");
        }
        // … et seulement tant que c'est elle qui tient le micro de la lecture.
        $this->assertStringContainsString("if (mode !== 'speaking' || bargeEar !== interrupter) return;", $oreille);
        $this->assertStringContainsString("if (bargeEar === interrupter) startHush('ear:' + itemId);", $oreille);
        $this->assertStringContainsString('if (!hearWhileSpeaking(transcript, order)) hushMissed(\'ear:\' + itemId);', $oreille);
        // Établie trop tard : refermée (jamais une session ouverte sans conversation).
        $this->assertStringContainsString("onStarted: () => { if (mode === 'off' || !bargeIn) interrupter.stop(); },", $oreille);

        // UNE session tient le micro de la lecture ; il est rendu avant d'écouter.
        $ouvre = self::corpsJs($js, 'function openBargeMic() {');
        $this->assertStringContainsString('const ear = bargeEar = interrupter.isRecording() ? interrupter : transcriber;', $ouvre);
        $this->assertSame(1, substr_count($ouvre, 'attachMic('));
        $ferme = self::corpsJs($js, 'function closeBargeMic() {');
        $this->assertStringContainsString('ear.releaseMic();', $ferme);
        $ecoute = self::corpsJs($js, 'async function startListening() {');
        $this->assertLessThan(strpos($ecoute, 'transcriber.attachMic();'), strpos($ecoute, 'closeBargeMic();'));

        // Ouverte sans micro avec la conversation, bornée, refermée avec elle.
        $assure = self::corpsJs($js, 'function ensureInterrupter() {');
        $this->assertStringContainsString("if (!bargeIn || mode === 'off') return;", $assure);
        $this->assertStringContainsString('if (interrupterTries >= INTERRUPTER_TRIES_MAX) return;', $assure);
        $this->assertLessThan(strpos($assure, 'interrupter.start()'), strpos($assure, 'interrupter.releaseMic();'), 'ouverte SANS micro');
        $this->assertStringContainsString('onStarted: () => { startListening(); ensureInterrupter(); },', $js);
        $fin = self::corpsJs($js, 'function endConversation(message) {');
        $this->assertStringContainsString('interrupter.stop();', $fin);
        $this->assertStringContainsString('transcriber.stop();', $fin);
        // Le réglage de l'appareil la gouverne.
        $this->assertStringContainsString('if (bargeIn) ensureInterrupter(); else stopInterrupter();', $js);
    }

    public function testLaSuiteDUnStopPartCommeLeTourSuivantSansJamaisRepeterMia(): void
    {
        $js = self::src('public/assets/js/chat.js');

        // L'ordre entendu par la seconde session ouvre la suite ; par la
        // session d'écoute (repli) ou par un geste, l'écoute reprend aussitôt.
        $coupe = self::corpsJs($js, 'function interruptSpeech(remainder, earRank) {');
        $suite = strpos($coupe, 'beginSuite(remainder, spoken, earRank);');
        $this->assertIsInt($suite);
        $this->assertStringContainsString('if (earRank && bargeEar === interrupter) {', $coupe);
        // MIA se tait AVANT d'attendre la suite : l'attente ne retarde jamais le silence.
        $this->assertLessThan($suite, strpos($coupe, 'stopReading();'));
        $this->assertLessThan($suite, strpos($coupe, 'stopWriting();'));
        $this->assertStringContainsString("function skipSpeech() { interruptSpeech(''); }", $js, 'un geste n\'attend aucune suite');

        // Trois règles sur ce qui est repris.
        $entendu = self::corpsJs($js, 'function suiteHeard(s, transcript, order) {');
        $this->assertStringContainsString('const beforeOrder = order < s.stopOrder;', $entendu, 'commencé avant « stop » : jamais un message');
        $this->assertStringContainsString('s.duringReading.has(order) && !!VC && VC.looksLikeEcho(said, s.spoken)', $entendu, 'la voix de MIA revenue par le micro');
        $this->assertStringContainsString('if (said && !beforeOrder && !echo) {', $entendu);

        // La suite part par le MÊME routage qu'un tour ordinaire (message, ordre
        // pour une carte, connecteur), micro rendu d'abord ; rien à dire : écoute.
        $fin = self::corpsJs($js, 'function endSuite(s) {');
        $route = strpos($fin, 'if (routeTurn(text)) return;');
        $this->assertIsInt($route);
        $this->assertLessThan($route, strpos($fin, 'closeBargeMic();'));
        $this->assertGreaterThan($route, strpos($fin, 'startListening();'));
        $this->assertStringContainsString("if (mode !== 'listening' || s.seq !== turnSeq) {", $fin, 'une conversation qui a bougé n\'envoie rien');
        $this->assertStringContainsString("if (mode !== 'speaking') closeBargeMic();", $fin, '… et ne laisse pas le micro de la lecture ouvert');
        // Bornée, et jamais laissée derrière soi.
        $this->assertMatchesRegularExpression('/const SUITE_GRACE_MS = (\d+);/', $js);
        $this->assertStringContainsString('s.deadline = setTimeout(() => endSuite(s), SUITE_MAX_MS);', $js);
        $this->assertStringContainsString('cancelSuite();', self::corpsJs($js, 'async function startListening() {'));
        $this->assertStringContainsString('cancelSuite();', self::corpsJs($js, 'function endConversation(message) {'));
    }

    // ------------------------------------------------------------------
    // 4. Voix baissée, gestes
    // ------------------------------------------------------------------

    public function testLaVoixBaisseALaParoleEtNeResteJamaisBaissee(): void
    {
        $js = self::src('public/assets/js/chat.js');

        // Dès la parole détectée PENDANT la lecture — avant le texte.
        $this->assertStringContainsString("if (originOf(itemId) === 'speaking') { startHush(itemId); return; }", $js);
        $this->assertStringContainsString('onSpeechStop: (itemId) => { hushSpeechEnded(itemId); },', $js);
        // Le texte tranche : pas un ordre d'arrêt → la voix reprend.
        $this->assertStringContainsString("if (mode === 'speaking' && !hearWhileSpeaking(transcript)) hushMissed(itemId);", $js);
        $baisse = self::corpsJs($js, 'function startHush(itemId) {');
        $this->assertStringContainsString("if (hush || !bargeIn || mode !== 'speaking' || hushMisses >= HUSH_MISSES_MAX) return;", $baisse,
            'seulement micro ouvert pendant la lecture, et jamais après trop de fausses alertes');
        $this->assertStringContainsString('player.volume = h.volume * HUSH_LEVEL;', $baisse);
        $this->assertStringContainsString('h.pauseTimer = setTimeout(pause, HUSH_PAUSE_MS);', $baisse);
        // Là où le volume ne se règle pas (iOS), la voix se met en pause.
        $this->assertStringContainsString('const canDuck = volumeIsWritable();', $js);
        $this->assertSame(1, substr_count($js, 'volumeIsWritable();'), 'le test du volume le remet à 1 : il ne se rejoue pas');
        // Un vrai « stop » remet le compteur à zéro.
        $this->assertStringContainsString('hushMisses = 0; // un vrai « stop »', self::corpsJs($js, 'function interruptSpeech(remainder, earRank) {'));
        // Rétablie à chaque sortie de lecture.
        foreach (['function newTurn() {', 'function stopReading() {', 'function endConversation(message) {'] as $sortie) {
            $this->assertStringContainsString('endHush(false);', self::corpsJs($js, $sortie), $sortie);
        }
        // Le curseur de volume, bougé pendant une baisse, vaut pour la reprise.
        $this->assertStringContainsString('if (hush) hush.volume = level / 100;', $js);

        $css = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.voice-chat-bar.is-hushed .voice-chat-wave i', $css);
    }

    public function testLesGestesInterrompentSansConfisquerLeClavier(): void
    {
        $js = self::src('public/assets/js/chat.js');

        $geste = self::corpsJs($js, 'function interruptNow() {');
        $this->assertStringContainsString("if (mode === 'speaking') { skipSpeech(); return true; }", $geste);
        $this->assertStringContainsString("if (mode === 'acting' && cardReading) { stopPlayer(); return true; }", $geste, 'la lecture d\'une carte se coupe aussi');
        // Hors lecture, aucune touche n'est confisquée ; Espace jamais dans un champ.
        $this->assertStringContainsString('if (!canInterruptNow() || ev.defaultPrevented || ev.ctrlKey || ev.metaKey || ev.altKey) return;', $js);
        $this->assertStringContainsString('if (space && !spaceIsFree(ev.target)) return;', $js);
        $libre = self::corpsJs($js, 'function spaceIsFree(target) {');
        $this->assertStringContainsString("if (target === msgInput) return msgInput.value === '';", $libre);
        $this->assertStringContainsString('INPUT|TEXTAREA|SELECT|BUTTON|A|SUMMARY', $libre);
        $this->assertStringContainsString('target === btn', $libre, 'sur le bouton de la conversation, Espace la terminerait au lieu de couper MIA');
        // Bandeau (hors de ses commandes), Passer, touches média.
        $this->assertStringContainsString("target.closest('button, select, input, label')", $js);
        $this->assertStringContainsString("barSkip?.addEventListener('click', () => { interruptNow(); });", $js);
        $media = self::corpsJs($js, 'function bindMediaKeys(on) {');
        $this->assertStringContainsString("['pause', 'stop', 'nexttrack']", $media);
        $this->assertStringContainsString("set('play', on ? () => {} : null);", $media, '« lecture » ne relance jamais un son coupé');
        $this->assertStringContainsString('bindMediaKeys(on);', self::corpsJs($js, 'function setMode(next, statusText) {'));

        $css = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.voice-chat-bar.is-speaking { cursor: pointer; }', $css);
    }

    // ------------------------------------------------------------------
    // 5. Connecteurs à la demande
    // ------------------------------------------------------------------

    public function testLesConnecteursSActiventALaDemandeEnConversationVocale(): void
    {
        $js  = self::src('public/assets/js/chat.js');
        $src = self::chatController();

        // Le navigateur le demande d'office à la voix — seulement si la ligne
        // existe (permis par l'administrateur, un compte relié)…
        $this->assertStringContainsString("const autoConnectors = document.getElementById('connectorsAutoToggle');", $js);
        $this->assertStringContainsString("fd.append('connectors_auto', (autoConnectors?.checked || (voiceModeActive && !!autoConnectors)) ? '1' : '0');", $js);
        // … et le serveur reste juge, à l'envoi comme dans le job.
        $this->assertStringContainsString("\$connectorsAuto = (\$_POST['connectors_auto'] ?? '0') === '1' && ConnectorRegistry::isAutoModeEnabled();", $src);
        $this->assertStringContainsString("\$connectorsAuto    = !empty(\$payload['connectors_auto']) && ConnectorRegistry::isAutoModeEnabled();", $src);

        // Pas d'effort relevé à la voix : les connecteurs y sont engagés à chaque tour.
        $this->assertSame('high', ChatController::toolLoopOptions(1, ['gmail'])['effort'] ?? null);
        $this->assertArrayNotHasKey('effort', ChatController::toolLoopOptions(1, ['gmail'], true));
        $this->assertSame(
            ChatController::toolLoopOptions(1, ['gmail'])['cache_key'],
            ChatController::toolLoopOptions(1, ['gmail'], true)['cache_key'],
            'le cache de la conversation ne dépend pas du mode'
        );

        // Connecteur resté éteint : à la voix, le geste indiqué se DIT.
        $this->assertMatchesRegularExpression('/\$voiceMode\s*\);/', substr($src, (int)strpos($src, 'ConnectorRegistry::dormantConnectorsNote('), 400));

        // Le bandeau dit de quoi MIA dispose.
        $tpl     = self::src('templates/chat/index.php');
        $bandeau = substr($tpl, (int)strpos($tpl, 'id="voiceChatBar"'), 4500);
        $this->assertStringContainsString('id="voiceChatSources" hidden', $bandeau);
        $this->assertLessThan(strpos($bandeau, 'id="voiceChatSources"'), strpos($bandeau, 'id="voiceChatEnd"'));
        $css = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.voice-chat-sources[hidden] { display: none !important; }', $css);
        $peinture = self::corpsJs($js, 'function paintSources() {');
        foreach (["t('Connecteurs : {liste}'", "t('les autres à la demande')", "t('Connecteurs à la demande : {liste}'", "t('Connecteurs indisponibles avec ce modèle')"] as $libelle) {
            $this->assertStringContainsString($libelle, $peinture);
        }

        // Allumer à la voix passe par l'interrupteur du menu « + » — jamais sur un modèle sans outils.
        $bascule = self::corpsJs($js, 'function applyConnectorCommand(cmd) {');
        $this->assertStringContainsString("box.dispatchEvent(new Event('change', { bubbles: true }));", $bascule);
        $this->assertStringContainsString("return 'vc_connector_model';", $bascule);
        $this->assertStringContainsString("return 'vc_connector_unlinked';", $bascule);
        foreach (['vc_connector_on', 'vc_connector_off', 'vc_connector_unlinked', 'vc_connector_model'] as $annonce) {
            $this->assertArrayHasKey($annonce, ChatController::VOICE_CUES);
        }
    }

    // ------------------------------------------------------------------
    // 6. Documentation
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationLeDit(): void
    {
        $page = self::src('docs/user/fonctionnalites/conversations/converser-a-la-voix.md');
        foreach (['## Interrompre MIA sans la voix', '## Se servir de ses connecteurs à la voix', '« lis-le »', '« active Gmail »', 'Sa voix baisse dès que vous commencez à parler'] as $repere) {
            $this->assertStringContainsString($repere, $page);
        }
        $guide = self::src('docs/GUIDE-UTILISATEUR.md');
        foreach (['Interrompre MIA sans la voix', 'Connecteurs à la voix', '« **Lis-le** »', 'Sa voix baisse dès que vous commencez à parler'] as $repere) {
            $this->assertStringContainsString($repere, $guide);
        }
        $this->assertStringContainsString('En conversation vocale, le mode vaut d\'office', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Connecteurs à la demande', self::src('docs/user/admin/outils/regler-la-conversation-vocale.md'));
        $this->assertStringContainsString('conversation-vocale-fluide', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Couper la parole, connecteurs mains libres', self::src('CLAUDE.md'));

        // Seconde session : ce que l'utilisateur y gagne, ce que l'administrateur doit savoir.
        $this->assertStringContainsString('elle comprend l\'ordre en une seconde environ', $page);
        $this->assertStringContainsString('attend la fin de votre phrase', $page, 'la suite d\'un « stop » n\'est pas perdue');
        $this->assertStringContainsString('attend la fin de votre phrase', $guide);
        foreach (['docs/GUIDE-ADMIN.md', 'docs/user/admin/outils/regler-la-conversation-vocale.md'] as $doc) {
            $admin = self::src($doc);
            $this->assertStringContainsString('**deux sessions** chez OpenAI', $admin, $doc);
            $this->assertStringContainsString('les minutes', $admin, $doc);
            $this->assertStringContainsString('écoutées sont les mêmes', preg_replace('/\s+/', ' ', $admin), $doc);
        }
        $this->assertStringContainsString('en une seconde environ', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('« l\'oreille de la lecture »', self::src('CLAUDE.md'));
        $this->assertStringContainsString('La suite d\'un stop', self::src('CLAUDE.md'));
    }
}
