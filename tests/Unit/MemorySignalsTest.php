<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mémoire — apprendre des GESTES (MemorySignals) et portée des souvenirs.
 *
 *   - reconnaissance d'une demande de retouche (FR/EN), prudente : négation,
 *     demande durable, gestes contraires, longueur ;
 *   - portée d'une réponse (brouillon d'email, fichier livré, code) ;
 *   - retouche d'un brouillon d'email : raccourci, tutoiement ↔ vouvoiement ;
 *   - seuils, clés, contraires, textes traduits ;
 *   - portée dans le prompt, l'empreinte, les juges ;
 *   - question posée plus tôt pour un geste ;
 *   - câblage (tour de chat, carte de relecture, entretien, page, base).
 */
final class MemorySignalsTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    // ---------------------------------------------------------------------
    // Demandes de retouche
    // ---------------------------------------------------------------------

    public function testLesRetouchesCourantesSontReconnues(): void
    {
        $cas = [
            'Plus court' => ['shorter'],
            'Tu peux raccourcir ?' => ['shorter'],
            'trop long' => ['shorter'],
            'Fais-le en trois lignes' => ['shorter'],
            'Shorter please' => ['shorter'],
            'Plus détaillé stp' => ['longer'],
            'Développe le deuxième point' => ['longer'],
            'more detail' => ['longer'],
            'Va droit au but' => ['direct'],
            'Plus chaleureux' => ['warm'],
            'Plus formel' => ['formal'],
            'Rends-le plus professionnel' => ['formal'],
            'Moins formel' => ['casual'],
            'plus décontracté' => ['casual'],
            'Plus simple, sans jargon' => ['simpler'],
            'Explique plus simplement' => ['simpler'],
            'Mets ça en tableau' => ['table'],
            'Sous forme de tableau' => ['table'],
            'in a table please' => ['table'],
            'En liste à puces' => ['bullets'],
            'as bullet points' => ['bullets'],
            'Vouvoie-le' => ['vouvoiement'],
            'tutoie-la' => ['tutoiement'],
            'Plus court et plus formel' => ['shorter', 'formal'],
        ];
        foreach ($cas as $texte => $attendu) {
            $this->assertSame($attendu, MemorySignals::detect($texte), $texte);
        }
    }

    public function testLaPrudenceLEmporte(): void
    {
        foreach ([
            'Pas trop long',                          // négation
            'Ne raccourcis pas',
            'Don\'t make it shorter',
            'Désormais, plus court',                  // demande durable : la capture s'en charge
            'À l\'avenir, en tableau',
            'From now on shorter',
            'Plus court… non, plus détaillé',         // contraires : rien
            'Merci !',
            'Fais un tableau comparatif des offres',  // nouvelle demande, pas « en tableau »
            '',
        ] as $texte) {
            $this->assertSame([], MemorySignals::detect($texte), $texte);
        }
        $this->assertSame([], MemorySignals::followUpGestures(str_repeat('blabla ', 30) . 'plus court'), 'Trop long : une nouvelle demande.');
        $this->assertSame(['shorter'], MemorySignals::followUpGestures('Plus court'));
        $this->assertCount(MemorySignals::MAX_PER_MESSAGE, MemorySignals::detect('Plus court, plus formel, plus simple, en tableau'));
    }

    // ---------------------------------------------------------------------
    // Portée d'une réponse
    // ---------------------------------------------------------------------

    public function testLaPorteeSeLitDansLaReponse(): void
    {
        $mail = ['content' => 'Voici le brouillon.', 'actions_json' => json_encode([['t' => 'creer_brouillon_gmail', 'l' => 'Préparation…', 'ok' => true]])];
        $this->assertSame('email', MemorySignals::scopeOfReply($mail));
        $outlook = ['content' => 'Voici.', 'actions_json' => json_encode([['t' => 'rechercher_outlook', 'ok' => true], ['t' => 'creer_brouillon_outlook', 'ok' => true]])];
        $this->assertSame('email', MemorySignals::scopeOfReply($outlook));

        $doc = ['content' => "Voici le compte rendu.\n```markdown fichier=\"compte-rendu.docx\"\n# Titre\n```\n"];
        $this->assertSame('document', MemorySignals::scopeOfReply($doc));
        $classeur = ['content' => "```csv fichier=\"budget.xlsx\"\na;b\n```"];
        $this->assertSame('document', MemorySignals::scopeOfReply($classeur));
        $script = ['content' => "```python fichier=\"calcul.py\"\nprint(1)\n```"];
        $this->assertSame('code', MemorySignals::scopeOfReply($script));

        $code = ['content' => "Voici :\n```php\n<?php\nfunction a() { return 1; }\nfunction b() { return 2; }\n```\nC'est tout."];
        $this->assertSame('code', MemorySignals::scopeOfReply($code));
        $petitCode = ['content' => str_repeat('Une longue explication rédigée en prose. ', 20) . "\n```bash\nls\n```"];
        $this->assertSame('all', MemorySignals::scopeOfReply($petitCode), 'Un petit bloc dans une longue prose : une réponse ordinaire.');
        $this->assertSame('all', MemorySignals::scopeOfReply(['content' => 'Une réponse ordinaire.']));
        $this->assertSame('all', MemorySignals::scopeOfReply(['content' => 'Hum', 'actions_json' => 'pas du json']));
    }

    // ---------------------------------------------------------------------
    // Retouche d'un brouillon d'email
    // ---------------------------------------------------------------------

    public function testUnBrouillonRetoucheParLaPersonne(): void
    {
        $long = str_repeat('Je reviens vers vous au sujet de la proposition commerciale envoyée la semaine dernière. ', 4);
        $this->assertSame(['shorter'], MemorySignals::mailEditGestures($long, mb_substr($long, 0, 150)));
        $this->assertSame([], MemorySignals::mailEditGestures($long, mb_substr($long, 0, (int)(mb_strlen($long) * 0.9))), 'Une coupe légère ne dit rien.');
        $this->assertSame([], MemorySignals::mailEditGestures('Bonjour Paul, merci pour ton retour.', 'Bonjour.'), 'Un corps court : rien.');
        $this->assertSame([], MemorySignals::mailEditGestures($long, $long . ' Et un fait de plus.'), 'Écrire plus, c\'est souvent ajouter un fait.');

        $tu   = 'Bonjour Paul, merci pour ton message. Tu trouveras ci-joint le devis, dis-moi si ta date convient.';
        $vous = 'Bonjour Paul, merci pour votre message. Vous trouverez ci-joint le devis, dites-moi si votre date convient.';
        $this->assertSame(['vouvoiement'], MemorySignals::mailEditGestures($tu, $vous));
        $this->assertSame(['tutoiement'], MemorySignals::mailEditGestures($vous, $tu));
        $this->assertSame([], MemorySignals::mailEditGestures($vous, $vous . ' Bonne journée.'));
    }

    public function testChaquePreReglageDAmeliorationEstPrevu(): void
    {
        foreach (array_keys(MailDraftReview::PRESETS) as $preset) {
            $gesture = MemorySignals::presetGesture($preset);
            if ($preset === 'corriger') {
                $this->assertNull($gesture, 'Corriger la langue n\'est pas une préférence.');
                continue;
            }
            $this->assertNotNull($gesture, $preset);
            $this->assertTrue(MemorySignals::allowed($gesture, 'email'), $preset);
        }
    }

    // ---------------------------------------------------------------------
    // Seuils, clés, contraires
    // ---------------------------------------------------------------------

    public function testUnGesteDevientUneProposition(): void
    {
        $this->assertFalse(MemorySignals::isDue(2, 2));
        $this->assertFalse(MemorySignals::isDue(3, 1), 'Une seule conversation : une humeur, pas une préférence.');
        $this->assertTrue(MemorySignals::isDue(3, 2));
        $this->assertFalse(MemorySignals::isDue(4, 3, 2), 'Contredit par le geste inverse.');
        $this->assertTrue(MemorySignals::isDue(5, 3, 2));
    }

    public function testClesEtContraires(): void
    {
        $this->assertSame('shorter:email', MemorySignals::signalKey('shorter', 'email'));
        $this->assertSame(['shorter', 'email'], MemorySignals::parseKey('shorter:email'));
        $this->assertNull(MemorySignals::parseKey('vouvoiement:all'), 'Le vouvoiement ne vaut que pour les emails.');
        $this->assertNull(MemorySignals::parseKey('inconnu:all'));
        $this->assertNull(MemorySignals::parseKey(''));
        $this->assertSame('longer:document', MemorySignals::oppositeKey('shorter:document'));
        $this->assertSame('tutoiement:email', MemorySignals::oppositeKey('vouvoiement:email'));
        $this->assertNull(MemorySignals::oppositeKey('table:all'));

        foreach (MemorySignals::GESTURES as $gesture => $def) {
            $this->assertArrayHasKey($gesture, MemorySignals::REASONS);
            $this->assertArrayHasKey($gesture, MemorySignals::QUESTIONS);
            $this->assertNotSame('', $def['memory']);
            foreach ($def['scopes'] as $scope) {
                $this->assertArrayHasKey($scope, UserMemory::SCOPES);
                $this->assertArrayHasKey($scope, MemorySignals::SCOPE_OBJECTS);
            }
            if ($def['opposite'] !== null) {
                $this->assertSame($gesture, MemorySignals::GESTURES[$def['opposite']]['opposite'], 'Contraires symétriques : ' . $gesture);
            }
        }
    }

    public function testLaQuestionDitCeQueMiaARemarque(): void
    {
        $this->assertSame(
            'Vous me demandez souvent de raccourcir mes emails. Je fais plus court d\'office désormais ?',
            MemorySignals::question('shorter:email')
        );
        $this->assertSame('Vous passez souvent mes emails au vouvoiement.', MemorySignals::reason('vouvoiement:email'));
        $this->assertSame('', MemorySignals::question('n-importe:quoi'));

        I18n::use('en');
        $this->assertSame(
            'You often ask me to shorten my emails. Shall I keep things shorter from now on?',
            MemorySignals::question('shorter:email')
        );
        $this->assertSame('Prefers shorter answers', MemorySignals::memoryText('shorter'));
    }

    public function testChaqueTexteEstTraduit(): void
    {
        $dico = require ROOT . '/lang/en.php';
        $cles = array_merge(
            array_column(MemorySignals::GESTURES, 'memory'),
            array_values(MemorySignals::REASONS),
            array_values(MemorySignals::QUESTIONS),
            array_values(MemorySignals::SCOPE_OBJECTS),
            array_values(UserMemory::SCOPES),
            array_values(UserMemory::SCOPE_PROMPT),
            ['Oui', 'Non merci', 'MIA remarque aussi vos gestes : une réponse que vous faites souvent raccourcir, un brouillon d\'email que vous passez au vouvoiement, le même bouton « Améliorer » choisi encore et encore. Quand un geste se répète, elle vous propose d\'en faire une préférence — rien ne change sans votre accord.']
        );
        foreach ($cles as $cle) {
            $this->assertArrayHasKey($cle, $dico, $cle);
        }
        $client = require ROOT . '/lang/en.client.php';
        $this->assertArrayHasKey('À quoi ce souvenir s\'applique', $client);
    }

    // ---------------------------------------------------------------------
    // Portée des souvenirs
    // ---------------------------------------------------------------------

    public function testLaPorteeEstNormalisee(): void
    {
        $this->assertSame('email', UserMemory::normalizeScope(' Emails '));
        $this->assertSame('email', UserMemory::normalizeScope('courriels'));
        $this->assertSame('document', UserMemory::normalizeScope('fichiers'));
        $this->assertSame('code', UserMemory::normalizeScope('code'));
        $this->assertSame('all', UserMemory::normalizeScope('n\'importe quoi'));
        $this->assertSame('all', UserMemory::normalizeScope(''));
        $this->assertSame('', UserMemory::scopeSuffix('all'));
        $this->assertSame(' (seulement pour les emails)', UserMemory::scopeSuffix('email'));
        $this->assertSame(['all', 'email', 'document', 'code'], array_keys(UserMemory::scopes()));
    }

    public function testLEmpreinteDePartoutNeChangePas(): void
    {
        // Les empreintes déjà en base (toutes « partout ») restent valables.
        $this->assertSame(sha1(UserMemory::normalizeForCompare('Préfère le matin')), UserMemory::fingerprintOf('Préfère le matin'));
        $this->assertSame(UserMemory::fingerprintOf('Préfère le matin'), UserMemory::fingerprintOf('Préfère le matin', 'all'));
        $this->assertNotSame(UserMemory::fingerprintOf('Préfère le matin'), UserMemory::fingerprintOf('Préfère le matin', 'email'));
    }

    public function testLePromptDitLaPortee(): void
    {
        $fragment = UserMemory::promptFragmentFromRows([
            ['id' => 1, 'content' => 'Préfère des réponses plus courtes', 'theme' => 'reponses', 'scope' => 'email', 'source' => 'auto', 'status' => 'active', 'is_locked' => 1],
            ['id' => 2, 'content' => 'Responsable qualité', 'theme' => 'profil', 'source' => 'manuel', 'status' => 'active', 'is_locked' => 1],
        ]);
        $this->assertStringContainsString('- Préfère des réponses plus courtes (seulement pour les emails)', $fragment);
        $this->assertStringContainsString("- Responsable qualité\n", $fragment);

        $selection = UserMemory::promptSelection([
            ['id' => 1, 'content' => 'abc', 'scope' => 'email', 'status' => 'active', 'is_locked' => 1],
        ]);
        $this->assertSame(mb_strlen('abc') + mb_strlen(' (seulement pour les emails)') + 3, $selection['used'], 'La portée compte dans la place.');
    }

    public function testLesJugesLisentEtEcriventLaPortee(): void
    {
        $capture = MemoryCapture::parseOps(json_encode(['operations' => [
            ['action' => 'ajouter', 'theme' => 'reponses', 'texte' => 'Vouvoie ses clients', 'portee' => 'emails'],
            ['action' => 'ajouter', 'theme' => 'reponses', 'texte' => 'Préfère les tableaux', 'portee' => 'partout'],
        ]]), 0);
        $this->assertSame('email', $capture['ops'][0]['scope']);
        $this->assertArrayNotHasKey('scope', $capture['ops'][1], 'Partout : rien à préciser.');

        $learned = MemoryLearner::parseOps(json_encode(['operations' => [
            ['action' => 'ajouter', 'theme' => 'reponses', 'texte' => 'Préfère des documents concis', 'nature' => 'dit', 'citation' => 'mes documents courts', 'conversation' => 1, 'portee' => 'documents'],
        ]]), 0, 1);
        $this->assertSame('document', $learned[0]['scope']);

        $this->assertStringContainsString('portee', MemoryCapture::systemPrompt());
        $this->assertStringContainsString('portee', MemoryLearner::systemPrompt('français'));
        $this->assertStringContainsString(
            '1. [reponses] [emails] Vouvoie',
            MemoryCapture::userPrompt('', 'x', [['content' => 'Vouvoie', 'theme' => 'reponses', 'scope' => 'email']])
        );
        $this->assertStringContainsString(
            '1. [reponses] [appris] [code] Commente',
            MemoryLearner::userPrompt([['content' => 'Commente', 'theme' => 'reponses', 'scope' => 'code', 'is_locked' => 0]], '')
        );
    }

    // ---------------------------------------------------------------------
    // Question posée plus tôt pour un geste
    // ---------------------------------------------------------------------

    public function testUnGesteSeDemandePlusTot(): void
    {
        $this->assertTrue(MemoryQuestions::signalDue(null, ''));
        $this->assertTrue(MemoryQuestions::signalDue(MemoryQuestions::GAP_BOOTSTRAP, ''));
        $this->assertFalse(MemoryQuestions::signalDue(3600, ''), 'Au plus une question par jour.');
        $this->assertFalse(MemoryQuestions::signalDue(null, MemoryQuestions::FREQ_OFF), '« Jamais » reste jamais.');

        $state = [
            'proposals' => [
                ['id' => 1, 'content' => 'Déduit', 'hits' => 5],
                ['id' => 2, 'content' => 'Préfère des réponses plus courtes', 'hits' => 3, 'signal' => 'shorter:email', 'scope' => 'email'],
            ],
            'bank' => [],
            'style_empty' => true,
        ];
        $only = MemoryQuestions::signalOnly($state);
        $this->assertSame([2], array_column($only['proposals'], 'id'));
        $this->assertArrayNotHasKey('style_empty', $only, 'Ni la banque, ni autre chose.');

        $picked = MemoryQuestions::pick($only);
        $this->assertSame('shorter:email', $picked['signal']);
        $payload = MemoryQuestions::payload($picked + ['id' => 9, 'message_id' => 3]);
        $this->assertSame('Vous me demandez souvent de raccourcir mes emails. Je fais plus court d\'office désormais ?', $payload['question']);
        $this->assertSame('Préfère des réponses plus courtes (seulement pour les emails)', $payload['quote']);
        $this->assertSame(['oui', 'non'], array_column($payload['options'], 'value'));
        $this->assertSame(['Oui', 'Non merci'], array_column($payload['options'], 'label'));

        // Une proposition ordinaire garde sa question.
        $ordinary = MemoryQuestions::payload(['id' => 1, 'kind' => 'proposal', 'key' => 'proposition', 'content' => 'Déduit']);
        $this->assertSame('J\'ai cru comprendre ceci à votre sujet. C\'est bien ça ?', $ordinary['question']);
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLesGestesSontComptesAuxBonsEndroits(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $suivi = strpos($chat, 'MemorySignals::fromFollowUp(');
        $question = strpos($chat, 'MemoryQuestions::forTurn(');
        $this->assertNotFalse($suivi);
        $this->assertLessThan($question, $suivi, 'Compté AVANT la question de fin de tour : elle peut le poser aussitôt.');
        $this->assertMatchesRegularExpression('/if \(!\$voiceMode\) \{\s*MemorySignals::fromFollowUp/', $chat, 'Jamais à l\'oral.');

        $revise = substr($chat, (int)strpos($chat, 'public function reviseMailDraft'));
        $revise = substr($revise, 0, (int)strpos($revise, 'private function improveMailDraft'));
        $this->assertLessThan(
            strpos($revise, 'MemorySignals::fromMailRevision('),
            strpos($revise, 'DrivePendingAction::updatePending('),
            'Compté une fois la révision enregistrée.'
        );
        $this->assertStringContainsString('$edited = $args;', $revise, 'La retouche à la main est comparée avant l\'amélioration.');

        $learner = (string)file_get_contents(ROOT . '/src/Memory/MemoryLearner.php');
        $this->assertStringContainsString('MemorySignals::purge()', $learner);
        $memory = (string)file_get_contents(ROOT . '/src/Controllers/MemoryController.php');
        $this->assertStringContainsString('MemorySignals::forgetUser($userId)', $memory);

        // Aucun appel au modèle, aucun texte gardé.
        $signals = (string)file_get_contents(ROOT . '/src/Memory/MemorySignals.php');
        foreach (['LLMFactory', '->stream(', 'UsageMeter'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $signals);
        }
        $this->assertStringContainsString('MemoryLearner::activeFor($user)', $signals, 'Mêmes portes que l\'apprentissage.');
    }

    public function testLaBaseEtLaSauvegarde(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/110_memory_signals.sql');
        foreach (['ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT \'all\'', 'ADD COLUMN signal_key', 'CREATE TABLE memory_signal_events', 'UNIQUE KEY uq_mse_ref (user_id, gesture, scope, ref)', 'ON DELETE CASCADE'] as $attendu) {
            $this->assertStringContainsString($attendu, $sql);
        }
        $this->assertStringNotContainsString('UPDATE user_memories', $sql, 'Reprise par les seuls défauts.');
        $this->assertContains('memory_signal_events', BackupService::EXCLUDED_TABLES);
        $support = (string)file_get_contents(ROOT . '/tests/Support/DatabaseTestCase.php');
        $this->assertStringContainsString("'memory_signal_events'", $support);
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $fichier) {
            $this->assertStringContainsString('/src/Memory/MemorySignals.php', (string)file_get_contents(ROOT . '/' . $fichier));
        }
    }

    public function testLaPageMontreLaPorteeEtLaRaison(): void
    {
        require_once ROOT . '/src/Controllers/MemoryController.php';
        $item = MemoryController::item([
            'id' => 4, 'content' => 'Préfère des réponses plus courtes', 'theme' => 'reponses', 'scope' => 'email',
            'source' => 'auto', 'status' => 'proposed', 'is_locked' => 0, 'signal_key' => 'shorter:email',
            'created_at' => '2026-11-15 10:00:00',
        ], null);
        $this->assertSame('email', $item['scope']);
        $this->assertSame('Vous me demandez souvent de raccourcir mes emails.', $item['reason']);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/memory.js');
        $this->assertStringContainsString('function scopeSelect(memory)', $js);
        $this->assertStringContainsString('memory.reason', $js);
        $this->assertStringContainsString('scope: select.value', $js);
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/memory.php');
        $this->assertStringContainsString("'scopes'  => UserMemory::scopes()", $tpl);
    }

    public function testLesGuidesEnParlent(): void
    {
        $guide = (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('remarque aussi vos gestes', $guide);
        $nouveautes = (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md');
        $this->assertStringContainsString('memoire-gestes', $nouveautes);
    }
}
