<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Questions de MIA (mémoire, lot 2) — formes pures et câblage :
 *   - rythme sans inversion entre « rarement » et « de temps en temps » ;
 *   - un tour qui a déjà autre chose à décider ne reçoit pas de question ;
 *   - choix de la question (propositions revues, puis la banque) ;
 *   - carte et réponses traduites, aucune clé manquante (clés calculées) ;
 *   - câblage : fin de tour, rechargement, route, carte d'administration,
 *     sauvegarde, copie.
 */
final class MemoryQuestionsTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    // ---------------------------------------------------------------------
    // Rythme
    // ---------------------------------------------------------------------

    public function testLeRythmeNeSInversePasEntreLesReglages(): void
    {
        foreach ([0, 4, 5, 20, 60] as $actifs) {
            $rarement = MemoryQuestions::gapFor(MemoryQuestions::FREQ_RARELY, $actifs);
            $souvent  = MemoryQuestions::gapFor(MemoryQuestions::FREQ_OFTEN, $actifs);
            $this->assertNotNull($rarement);
            $this->assertNotNull($souvent);
            $this->assertLessThanOrEqual($rarement, $souvent, "« de temps en temps » ne pose jamais moins que « rarement » ($actifs souvenirs).");
            $this->assertNull(MemoryQuestions::gapFor(MemoryQuestions::FREQ_OFF, $actifs));
        }
        // Démarrage commun : une par jour tant que MIA fait connaissance.
        $this->assertSame(MemoryQuestions::GAP_BOOTSTRAP, MemoryQuestions::gapFor(MemoryQuestions::FREQ_RARELY, MemoryQuestions::BOOTSTRAP_BELOW - 1));
        $this->assertSame(MemoryQuestions::GAP_BOOTSTRAP, MemoryQuestions::gapFor(MemoryQuestions::FREQ_OFTEN, 0));
        $this->assertSame(7 * 86400, MemoryQuestions::gapFor(MemoryQuestions::FREQ_RARELY, MemoryQuestions::BOOTSTRAP_BELOW));
        $this->assertSame(3 * 86400, MemoryQuestions::gapFor(MemoryQuestions::FREQ_OFTEN, MemoryQuestions::BOOTSTRAP_BELOW));
    }

    public function testUneQuestionAttendSonEcart(): void
    {
        $this->assertTrue(MemoryQuestions::dueNow(null, '', 0), 'Jamais posée : maintenant.');
        $this->assertFalse(MemoryQuestions::dueNow(3600, '', 0));
        $this->assertTrue(MemoryQuestions::dueNow(86400, '', 0));
        $this->assertFalse(MemoryQuestions::dueNow(6 * 86400, '', 10));
        $this->assertTrue(MemoryQuestions::dueNow(7 * 86400, '', 10));
        $this->assertTrue(MemoryQuestions::dueNow(3 * 86400, 'often', 10));
        $this->assertFalse(MemoryQuestions::dueNow(null, 'off', 0), '« Jamais » coupe tout.');
    }

    public function testLeReglageDeLaPersonneEstNormalise(): void
    {
        $this->assertSame('', MemoryQuestions::frequencyOf(['memory_questions' => '']));
        $this->assertSame('often', MemoryQuestions::frequencyOf(['memory_questions' => 'often']));
        $this->assertSame('off', MemoryQuestions::frequencyOf(['memory_questions' => 'off']));
        $this->assertSame('', MemoryQuestions::frequencyOf(['memory_questions' => 'toujours']));
        $this->assertSame('', MemoryQuestions::frequencyOf([]));
        $this->assertSame(['', 'often', 'off'], array_keys(MemoryQuestions::frequencies()));
    }

    // ---------------------------------------------------------------------
    // Le tour s'y prête-t-il ?
    // ---------------------------------------------------------------------

    public function testUnTourOrdinaireSePreteAUneQuestion(): void
    {
        $this->assertTrue(MemoryQuestions::turnAllows($this->tour()));
    }

    public function testJamaisQuandLaPersonneAAutreChoseADecider(): void
    {
        foreach (['voice', 'unattended', 'error', 'interrupted', 'choice_block', 'polish_offer', 'captured', 'empty'] as $drapeau) {
            $this->assertFalse(MemoryQuestions::turnAllows($this->tour([$drapeau => true])), $drapeau);
        }
        $this->assertFalse(MemoryQuestions::turnAllows($this->tour(['cards' => 1])), 'Une carte attend une décision.');
        $this->assertFalse(MemoryQuestions::turnAllows($this->tour(['prior_replies' => 0])), 'Pas sur la première réponse d\'une conversation.');
        $this->assertFalse(MemoryQuestions::turnAllows($this->tour(['conversations' => 1])), 'Pas pendant la toute première conversation.');
    }

    public function testDesChoixEnBoutonsSontReperes(): void
    {
        $this->assertTrue(ChoiceButtons::hasBlock("Que préférez-vous ?\n\n```choix\n- A\n- B\n```"));
        $this->assertTrue(ChoiceButtons::hasBlock("```choices multiple\n- A\n- B\n```"));
        $this->assertTrue(ChoiceButtons::hasBlock("~~~choice\n- A\n~~~"));
        $this->assertFalse(ChoiceButtons::hasBlock("```php\necho 'choix';\n```"));
        $this->assertFalse(ChoiceButtons::hasBlock('Faites votre choix parmi ces options.'));
    }

    // ---------------------------------------------------------------------
    // Quelle question ?
    // ---------------------------------------------------------------------

    public function testUnePropositionReviseePasseDAbord(): void
    {
        $picked = MemoryQuestions::pick([
            'proposals' => [
                ['id' => 3, 'content' => 'Une seule fois', 'hits' => 1, 'since' => null],
                ['id' => 7, 'content' => 'Lit ses emails le matin', 'hits' => 2, 'since' => null],
                ['id' => 9, 'content' => 'Revue trois fois', 'hits' => 3, 'since' => null],
            ],
            'style_empty' => true,
        ]);
        $this->assertSame(['kind' => 'proposal', 'key' => 'proposition', 'memory_id' => 9, 'content' => 'Revue trois fois'], $picked);

        // Une proposition déjà soumise récemment attend son tour.
        $picked = MemoryQuestions::pick([
            'proposals' => [['id' => 9, 'content' => 'Revue trois fois', 'hits' => 3, 'since' => 3600]],
            'style_empty' => true,
        ]);
        $this->assertSame('bank', $picked['kind']);
    }

    public function testLaBanqueSuitSonOrdreEtSesConditions(): void
    {
        $this->assertSame('reponses.longueur', MemoryQuestions::pick(['style_empty' => true])['key']);
        // Style déjà choisi : la question de longueur n'a plus lieu d'être.
        $this->assertSame('reponses.structure', MemoryQuestions::pick(['style_empty' => false])['key']);

        // Déjà répondues, ou posées récemment : on passe à la suivante.
        $picked = MemoryQuestions::pick([
            'style_empty' => true,
            'bank' => [
                'reponses.longueur'  => ['answered' => true, 'since' => 400 * 86400],
                'reponses.structure' => ['answered' => false, 'since' => 3 * 86400],
            ],
        ]);
        $this->assertSame('methode.clarifier', $picked['key']);
        // Passée il y a plus de 60 jours : elle peut revenir.
        $picked = MemoryQuestions::pick([
            'style_empty' => false,
            'bank' => ['reponses.structure' => ['answered' => false, 'since' => 61 * 86400]],
        ]);
        $this->assertSame('reponses.structure', $picked['key']);

        // Outils connectés et métier : seulement quand ils ont du sens.
        $toutRepondu = [];
        foreach (array_keys(MemoryQuestions::BANK) as $key) {
            $toutRepondu[$key] = ['answered' => true, 'since' => 0];
        }
        unset($toutRepondu['outils.initiative'], $toutRepondu['profil.role']);
        $this->assertNull(MemoryQuestions::pick(['bank' => $toutRepondu]));
        $this->assertSame('outils.initiative', MemoryQuestions::pick(['bank' => $toutRepondu, 'has_connector' => true])['key']);
        $this->assertSame('profil.role', MemoryQuestions::pick(['bank' => $toutRepondu, 'profile_empty' => true])['key']);
    }

    public function testChaqueQuestionDeLaBanqueEstComplete(): void
    {
        $themes = array_keys(UserMemory::THEMES);
        foreach (MemoryQuestions::BANK as $key => $q) {
            $this->assertContains(MemoryQuestions::themeOf($key), $themes, $key);
            $this->assertNotSame('', $q['question'], $key);
            $this->assertLessThanOrEqual(40, strlen($key), 'qkey tient dans sa colonne.');
            foreach ($q['options'] as $value => $option) {
                $this->assertNotSame('', $option['label'], "$key.$value");
                $this->assertTrue(isset($option['style']) || isset($option['memoire']) || count($option) === 1, "$key.$value");
                if (isset($option['style'])) {
                    $this->assertTrue(UserPreferences::isValidStyle($option['style']), "$key.$value");
                }
                if (isset($option['memoire'])) {
                    $this->assertLessThanOrEqual(UserMemory::MAX_LEN, mb_strlen($option['memoire']), "$key.$value");
                    $this->assertFalse(UserMemory::looksSensitive($option['memoire']));
                }
            }
            if ($q['other'] !== null) {
                $this->assertStringContainsString('{reponse}', $q['other'], $key);
            }
            $this->assertTrue($q['options'] !== [] || $q['other'] !== null, "$key : une question sans option attend une réponse libre.");
        }
    }

    public function testChaqueTexteCalculeEstTraduit(): void
    {
        $dict = (array)(require ROOT . '/lang/en.php');
        $keys = array_merge(array_values(MemoryQuestions::FREQUENCIES), array_values(MemoryQuestions::FREQUENCY_HINTS));
        foreach (MemoryQuestions::BANK as $q) {
            $keys[] = $q['question'];
            if ($q['other'] !== null) {
                $keys[] = $q['other'];
            }
            foreach ($q['options'] as $option) {
                $keys[] = $option['label'];
                if (isset($option['memoire'])) {
                    $keys[] = $option['memoire'];
                }
            }
        }
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $dict, "Non traduit : $key");
        }
    }

    // ---------------------------------------------------------------------
    // Carte, réponse libre, messages
    // ---------------------------------------------------------------------

    public function testLaCarteDUneQuestionDeLaBanque(): void
    {
        $card = MemoryQuestions::payload(['id' => 12, 'kind' => 'bank', 'key' => 'reponses.structure', 'message_id' => 40]);
        $this->assertSame(12, $card['id']);
        $this->assertSame(40, $card['message_id']);
        $this->assertSame('Quand une réponse est un peu longue, qu\'est-ce qui vous aide le plus ?', $card['question']);
        $this->assertSame(['listes', 'tableaux', 'paragraphes'], array_column($card['options'], 'value'));
        $this->assertSame('Autre…', $card['other']);
        $this->assertNull($card['quote']);

        // Sans réponse libre : `other` vaut null.
        $this->assertNull(MemoryQuestions::payload(['id' => 1, 'kind' => 'bank', 'key' => 'reponses.longueur'])['other']);
        // Question ouverte : champ seul (`other` = '').
        $role = MemoryQuestions::payload(['id' => 2, 'kind' => 'bank', 'key' => 'profil.role']);
        $this->assertSame('', $role['other']);
        $this->assertSame([], $role['options']);
        $this->assertSame([], MemoryQuestions::payload(['id' => 3, 'kind' => 'bank', 'key' => 'inconnue']));
    }

    public function testLaCarteDUneProposition(): void
    {
        $card = MemoryQuestions::payload(['id' => 5, 'kind' => 'proposal', 'key' => 'proposition', 'content' => 'Lit ses emails le matin', 'message_id' => 8]);
        $this->assertSame('Lit ses emails le matin', $card['quote']);
        $this->assertSame(['oui', 'non'], array_column($card['options'], 'value'));
        $this->assertSame('Corriger…', $card['other']);
    }

    public function testUneReponseLibreDevientUnSouvenirAvecSonGabarit(): void
    {
        $this->assertSame('Métier : Responsable qualité', MemoryQuestions::otherMemory('profil.role', "  Responsable qualité.\n"));
        $this->assertSame('Pour les sources : seulement pour les chiffres', MemoryQuestions::otherMemory('methode.sources', 'seulement pour les chiffres'));
        $this->assertSame('', MemoryQuestions::otherMemory('reponses.longueur', 'peu importe'), 'Pas de réponse libre sur cette question.');
        $this->assertSame('', MemoryQuestions::otherMemory('profil.role', '   '));
        $this->assertLessThanOrEqual(MemoryQuestions::OTHER_MAX + 20, mb_strlen(MemoryQuestions::otherMemory('profil.role', str_repeat('a', 500))));
    }

    public function testChaqueIssueALeSienMessage(): void
    {
        $this->assertSame('Retenu : Aime les tableaux', MemoryQuestions::message(['status' => 'ok', 'saved' => ['kind' => 'memory', 'text' => 'Aime les tableaux']]));
        $this->assertSame('Style de réponse : Concis', MemoryQuestions::message(['status' => 'ok', 'saved' => ['kind' => 'style', 'text' => 'Concis']]));
        $messages = [];
        foreach (['confirmed', 'rejected', 'skipped', 'stopped', 'full', 'gone', 'none'] as $kind) {
            $messages[] = MemoryQuestions::message(['status' => 'ok', 'saved' => ['kind' => $kind, 'text' => 'x']]);
        }
        foreach (['not_found', 'already', 'invalid', 'sensitive'] as $status) {
            $messages[] = MemoryQuestions::message(['status' => $status]);
        }
        $this->assertSame(count($messages), count(array_unique($messages)), 'Chaque issue se dit autrement.');
    }

    public function testLeNavigateurRecoitSesLibellesTraduits(): void
    {
        $cfg = MemoryQuestions::clientConfig(['id' => 1]);
        $this->assertSame(['id' => 1], $cfg['pending']);
        foreach (['title', 'why', 'send', 'skip', 'stop', 'link', 'error'] as $label) {
            $this->assertNotSame('', $cfg['labels'][$label] ?? '', $label);
        }
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLaFinDuTourPoseLaQuestionSansAppelAuModele(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString('$memoryQuestion = MemoryQuestions::forTurn($user, $convId, (int)$msg[\'id\'], [', $chat);
        foreach (["'voice'", "'unattended'", "'error'", "'interrupted'", "'empty'", "'cards'", "'choice_block'", "'polish_offer'", "'captured'", "'prior_replies'"] as $fait) {
            $this->assertStringContainsString($fait . ' ', $chat, $fait);
        }
        $this->assertStringContainsString("'memory_question' => \$memoryQuestion,", $chat);
        // Rechargement : seulement si la réponse est encore la dernière.
        $this->assertStringContainsString('$memoryQuestion = self::pendingMemoryQuestion((int)$user[\'id\'], $convId, $messages);', $chat);
        $this->assertStringContainsString("'memory_question' => self::pendingMemoryQuestion((int)\$user['id'], \$convId, \$rawMessages),", $chat);

        $questions = self::lire('src/Memory/MemoryQuestions.php');
        $this->assertStringNotContainsString('LLMFactory', $questions, 'Aucune question ni réponse ne passe par un modèle.');
        $this->assertStringNotContainsString('UsageMeter', $questions);
        $this->assertStringContainsString('UNIQUE', self::lire('database/migrations/099_memory_questions.sql'));
    }

    public function testLaRouteDeReponseEstLitteraleEtLAdministrateurPeutCouper(): void
    {
        $routes = self::lire('public/index.php');
        $this->assertLessThan(strpos($routes, "post('/settings/memory/{id}'"), strpos($routes, "post('/settings/memory/answer'"));
        $this->assertStringContainsString("[MemoryController::class, 'answer']", $routes);

        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertContains(MemoryQuestions::SETTING_ENABLED, AdminController::BUILTIN_TOOL_KEYS);
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(MemoryQuestions::SETTING_ENABLED) ?>"', self::lire('templates/admin/tools.php'));
        $this->assertContains('user_memory_questions', BackupService::EXCLUDED_TABLES);

        // La page Mémoire règle le rythme, dans son formulaire.
        $page = self::lire('templates/settings/memory.php');
        $this->assertMatchesRegularExpression('#name="memory_questions" value="[^"]*" form="memoryPrefsForm"#', $page);
        $this->assertStringContainsString("MemoryQuestions::saveFrequency(\$userId, (string)\$_POST['memory_questions'])", self::lire('src/Controllers/MemoryController.php'));
    }

    public function testLaCarteVitHorsDuContenuEtNePorteAucunTexte(): void
    {
        $js = self::lire('public/assets/js/memory-question.js');
        $this->assertStringContainsString("'/settings/memory/answer'", $js);
        $this->assertStringContainsString("insertAdjacentElement('afterend', card)", $js, 'Hors de .msg-content.');
        $this->assertStringContainsString('window.MiaMemoryQuestion = { attach, prune };', $js);
        // Aucun texte : libellés et questions viennent du serveur.
        $sansCommentaires = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        $this->assertDoesNotMatchRegularExpression("/'[^']*[àâçéèêëîïôûùüÿœ][^']*'/u", $sansCommentaires);

        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('window.MiaMemoryQuestion?.attach(json.memory_question)', $chat);
        $this->assertStringContainsString('window.MiaMemoryQuestion?.attach(data.memory_question || null)', $chat);
        $this->assertStringContainsString(".msg-trace, .memq-card, .memq-done, .msg-error-actions", $chat, 'Retirée des copies et des exports.');

        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('window.MIA_MEMORY_Q = <?= json_encode(MemoryQuestions::clientConfig($memoryQuestion ?? null)', $tpl);
        $this->assertStringContainsString('/assets/js/memory-question.js?v=', $tpl);
        $this->assertStringContainsString('.memq-card', self::lire('public/assets/css/main.css'));
    }

    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $faits */
    private function tour(array $faits = []): array
    {
        return $faits + [
            'voice' => false, 'unattended' => false, 'error' => false, 'interrupted' => false,
            'empty' => false, 'cards' => 0, 'choice_block' => false, 'polish_offer' => false,
            'captured' => false, 'prior_replies' => 1, 'conversations' => 2,
        ];
    }
}
