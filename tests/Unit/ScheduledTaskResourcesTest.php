<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tâches programmées — ce qu'une tâche engage EN PLUS de son assistant
 * (bases de connaissances, outils), la validation que certains outils
 * imposent, l'essai depuis le formulaire et l'aperçu de l'assistant.
 *
 * Formes pures d'abord (saisie, choix, résultat d'un essai), puis CÂBLAGE :
 * une base choisie qui ne serait jamais écrite sur la conversation, ou un
 * outil jamais passé au tour, ne lèverait aucune erreur — la tâche
 * répondrait simplement sans eux.
 */
final class ScheduledTaskResourcesTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
        // Les contrôleurs sont hors du bootstrap des tests.
        require_once ROOT . '/src/Controllers/ScheduledTaskController.php';
    }

    protected function tearDown(): void
    {
        ToolCatalog::setToday(null);
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Corps d'une méthode (jusqu'à la méthode suivante). */
    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $fin = preg_match('/\n    (public|private|protected)( static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10)
            ? $m[0][1] : strlen($source);
        return substr($source, $debut, $fin - $debut);
    }

    /**
     * Choix lus EN ENTIER (forme de choicesFor) : deux assistants dont un
     * porte la base 10, trois bases (la 12 sans document), quatre outils
     * (deux images, un n8n, un Gamma au dernier test raté).
     */
    private static function choixComplets(): array
    {
        return [
            'profiles' => [7 => ['id' => 7, 'name' => 'Veille'], 8 => ['id' => 8, 'name' => 'Rédacteur']],
            'llms'     => ['openai::gpt-5.6' => 'GPT-5.6'],
            'sources'  => ['web' => ['label' => 'Recherche Internet', 'connected' => true]],
            'kbs'      => [
                10 => ['id' => 10, 'name' => 'Clients', 'description' => '', 'reason' => null, 'live' => false, 'docs' => 4, 'shared' => false],
                11 => ['id' => 11, 'name' => 'Procédures', 'description' => '', 'reason' => null, 'live' => false, 'docs' => 2, 'shared' => true],
                12 => ['id' => 12, 'name' => 'Archives', 'description' => '', 'reason' => 'Aucun document', 'live' => false, 'docs' => 0, 'shared' => false],
                13 => ['id' => 13, 'name' => 'Contrats', 'description' => '', 'reason' => null, 'live' => false, 'docs' => 9, 'shared' => false],
                14 => ['id' => 14, 'name' => 'Marchés', 'description' => '', 'reason' => null, 'live' => false, 'docs' => 3, 'shared' => false],
            ],
            'profile_kbs' => [7 => [['id' => 10, 'name' => 'Clients']]],
            'tools'    => [
                1 => ['id' => 1, 'name' => 'Images GPT', 'type' => 'image', 'reason' => null],
                2 => ['id' => 2, 'name' => 'Images Gemini', 'type' => 'image', 'reason' => null],
                3 => ['id' => 3, 'name' => 'Automatisations', 'type' => 'n8n', 'reason' => null],
                4 => ['id' => 4, 'name' => 'Gamma', 'type' => 'gamma', 'reason' => 'Dernier test en échec'],
            ],
        ];
    }

    private static function saisie(array $surcharge = []): array
    {
        return $surcharge + [
            'name'        => 'Point du lundi',
            'instruction' => 'Fais le point.',
            'profile_id'  => '',
            'llm'         => 'openai::gpt-5.6',
            'freq'        => 'weekly',
            'days'        => ['1'],
            'time'        => '08:00',
            'timezone'    => 'Europe/Paris',
            'sources'     => ['web'],
            'destination' => 'thread',
        ];
    }

    private static function valider(array $surcharge = []): array
    {
        return ScheduledTask::normalizeInput(self::saisie($surcharge), ScheduledTask::allowedFrom(self::choixComplets()));
    }

    // ------------------------------------------------------------------
    // Choix permis
    // ------------------------------------------------------------------

    public function testLesChoixPermisEcartentCeQuiNePeutPasServir(): void
    {
        $allowed = ScheduledTask::allowedFrom(self::choixComplets());
        // Une base sans document, un outil en échec : visibles à l'écran, jamais retenus.
        $this->assertSame([10, 11, 13, 14], $allowed['kbs']);
        $this->assertSame([7 => [10]], $allowed['profile_kbs']);
        $this->assertSame([1 => 'image', 2 => 'image', 3 => 'n8n'], $allowed['tools']);
    }

    public function testDesChoixLegersNeRetiennentNiBaseNiOutil(): void
    {
        // Choix « légers » (description de l'outil programmer_tache) : rien à
        // valider pour les bases et outils — rien n'est retenu, rien n'est inventé.
        $legers = array_intersect_key(self::choixComplets(), array_flip(['profiles', 'llms', 'sources']));
        $allowed = ScheduledTask::allowedFrom($legers);
        $this->assertArrayNotHasKey('kbs', $allowed);
        $this->assertArrayNotHasKey('tools', $allowed);

        $data = ScheduledTask::normalizeInput(self::saisie(['kb_ids' => ['11'], 'tool_ids' => ['3']]), $allowed);
        $this->assertSame([], $data['kb_ids']);
        $this->assertSame([], $data['tool_ids']);
        $this->assertFalse($data['allow_actions']);
    }

    // ------------------------------------------------------------------
    // Bases de connaissances
    // ------------------------------------------------------------------

    public function testLesBasesChoisiesSontFiltreesSurLePerimetre(): void
    {
        $data = self::valider(['kb_ids' => ['11', '12', '99', '11', 'x']]);
        // 12 sans document, 99 hors périmètre, doublon et déchet : écartés.
        $this->assertSame([11], $data['kb_ids']);
    }

    public function testLesBasesDeLAssistantNeConsommentAucunCran(): void
    {
        // L'assistant 7 apporte la base 10 d'office : la cocher ne compte pas.
        $data = self::valider(['profile_id' => '7', 'kb_ids' => ['10', '11', '13', '14']]);
        $this->assertSame([11, 13, 14], $data['kb_ids']);
    }

    public function testTroisBasesAuPlusParTache(): void
    {
        try {
            self::valider(['kb_ids' => ['10', '11', '13', '14']]);
            $this->fail('Quatre bases acceptées.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('3 bases de connaissances au plus par tâche.', $e->getMessage());
        }
    }

    public function testLesBasesProposeesParUneAdresseSuiventLaMemeRegle(): void
    {
        $choix = self::choixComplets();
        $this->assertSame([11, 13], ScheduledTaskController::allowedKbIds(['11', '12', '13'], $choix, null));
        // Celle de l'assistant sort : elle est incluse d'office.
        $this->assertSame([11], ScheduledTaskController::allowedKbIds(['10', '11'], $choix, 7));
        // Au-delà du plafond, la sélection est bornée (pas d'erreur : c'est un pré-remplissage).
        $this->assertCount(ConversationKnowledgeBase::MAX_SELECTED, ScheduledTaskController::allowedKbIds(['10', '11', '13', '14'], $choix, null));
    }

    // ------------------------------------------------------------------
    // Outils et validation imposée
    // ------------------------------------------------------------------

    public function testUnSeulOutilParType(): void
    {
        $data = self::valider(['tool_ids' => ['2', '1', '4', '42']]);
        // La seconde image cède à la première ; Gamma en échec et 42 inconnu sont écartés.
        $this->assertSame([2], $data['tool_ids']);
        $this->assertFalse($data['allow_actions'], 'Une image n’agit pas : la lecture seule reste le défaut.');
    }

    public function testUnOutilQuiAgitImposeLaValidation(): void
    {
        $data = self::valider(['tool_ids' => ['3'], 'allow_actions' => '']);
        $this->assertSame([3], $data['tool_ids']);
        $this->assertTrue($data['allow_actions'], 'Un workflow n8n n’agit que par la carte d’approbation.');
        $this->assertContains('n8n', ScheduledTask::ACTING_TOOL_TYPES);
        $this->assertContains('gamma', ScheduledTask::ACTING_TOOL_TYPES);
        $this->assertNotContains('image', ScheduledTask::ACTING_TOOL_TYPES);
        $this->assertSame([], array_diff(ScheduledTask::ACTING_TOOL_TYPES, ScheduledTask::TOOL_TYPES));
    }

    public function testUnOutilIndisponibleEstNommeAvecSaRaison(): void
    {
        ToolCatalog::setToday('2026-10-01');
        $arrete = ScheduledTask::toolUnavailableReason(['type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3']);
        $this->assertNotNull($arrete, 'DALL-E 3 est arrêté depuis le 12 mai 2026.');

        $this->assertSame(
            'Dernier test en échec — prévenir un administrateur',
            ScheduledTask::toolUnavailableReason(['type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.5-flare', 'last_test_ok' => 0])
        );
        $this->assertSame(
            'Outil incomplet — prévenir un administrateur',
            ScheduledTask::toolUnavailableReason(['type' => 'n8n', 'config_json' => '{}', 'api_key_enc' => ''])
        );
        $this->assertSame(
            'Outil incomplet — prévenir un administrateur',
            ScheduledTask::toolUnavailableReason(['type' => 'gamma', 'api_key_enc' => ''])
        );
        $this->assertNull(ScheduledTask::toolUnavailableReason(['type' => 'gamma', 'api_key_enc' => Encryption::encrypt('sk-gamma-test')]));
        $this->assertNull(ScheduledTask::toolUnavailableReason(['type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.5-flare', 'last_test_ok' => 1]));
    }

    public function testChaqueTypeDOutilEstNomme(): void
    {
        foreach (ScheduledTask::TOOL_TYPES as $type) {
            $this->assertNotSame($type, ScheduledTask::toolTypeLabel($type), "Type sans libellé : $type");
        }
    }

    // ------------------------------------------------------------------
    // Persistance : identifiants, carte, rejeu
    // ------------------------------------------------------------------

    public function testUneListeDIdentifiantsSeRelitProprement(): void
    {
        $this->assertSame([3, 7], ScheduledTask::idList('3,7'));
        $this->assertSame([3, 7], ScheduledTask::idList(' 3 , ,7,3,-2,x,0'));
        $this->assertSame([], ScheduledTask::idList(''));
    }

    public function testUneSaisieRejoueeRedonneLaMemeTache(): void
    {
        // toInput est la forme rangée dans une carte « programmer_tache » : la
        // revalider (au clic, ou dans le formulaire « Ajuster ») ne perd rien.
        $data = self::valider(['kb_ids' => ['11', '13'], 'tool_ids' => ['1', '3']]);
        $input = ScheduledTask::toInput($data);
        $this->assertSame(['11', '13'], $input['kb_ids']);
        $this->assertSame(['1', '3'], $input['tool_ids']);
        $this->assertSame('1', $input['allow_actions']);
        $this->assertSame($data, ScheduledTask::normalizeInput($input, ScheduledTask::allowedFrom(self::choixComplets())));
    }

    public function testLaCarteDApprobationNommeBasesEtOutils(): void
    {
        $data = self::valider(['kb_ids' => ['11'], 'tool_ids' => ['3']]);
        $preview = ScheduledTask::actionPreview($data, self::choixComplets(), new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('UTC')));
        $impacts = implode("\n", (array)$preview['impacts']);
        $this->assertStringContainsString('Bases de connaissances : Procédures', $impacts);
        $this->assertStringContainsString('Outils : Automatisations', $impacts);
    }

    public function testLExecutionPorteLesOutilsDeLaTache(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 06:00', new \DateTimeZone('UTC'));
        $payload = Scheduler::payload(
            ['id' => 4, 'name' => 'Veille', 'instruction' => 'Le point.', 'timezone' => 'Europe/Paris',
             'sources_list' => [], 'tool_list' => [3, 1], 'allow_actions' => true],
            'openai::gpt-5.6', 12, $now, $now, false
        );
        $this->assertSame([3, 1], $payload['scheduled']['tool_ids']);
        $this->assertTrue($payload['scheduled']['allow_actions']);
    }

    // ------------------------------------------------------------------
    // Aperçu de l'assistant
    // ------------------------------------------------------------------

    public function testLaFicheDUnAssistantEstConstruiteCoteServeur(): void
    {
        $cards = ScheduledTaskController::assistantCards(
            [
                ['id' => 7, 'name' => 'Veille', 'avatar' => '../../etc/alfred-loupe.png', 'color' => '#8b5cf6',
                 'description' => 'Suit les clients.', 'default_llm' => 'openai::gpt-5.6'],
                ['id' => 8, 'name' => 'Rédacteur', 'avatar' => '', 'color' => 'red;background:url(x)', 'description' => '', 'default_llm' => null],
            ],
            [7 => [['id' => 10, 'name' => 'Clients']]],
            ['openai::gpt-5.6' => 'GPT-5.6']
        );
        // Avatar ramené au seul nom de fichier, sous le dossier des avatars.
        $this->assertSame('/assets/img/profils/alfred-loupe.png', $cards[7]['avatar']);
        $this->assertSame('#8b5cf6', $cards[7]['color']);
        $this->assertSame('Modèle : GPT-5.6 · Bases de l’assistant : Clients', $cards[7]['meta']);
        $this->assertSame([10], $cards[7]['kb_ids']);
        // Pas d'avatar : la pastille de couleur — jamais une valeur CSS libre.
        $this->assertSame('', $cards[8]['avatar']);
        $this->assertSame('', $cards[8]['color']);
        $this->assertSame('Modèle : Modèle par défaut de l’application', $cards[8]['meta']);
    }

    // ------------------------------------------------------------------
    // Résultat d'un essai
    // ------------------------------------------------------------------

    public function testUnEssaiSansReponseNEstPasPret(): void
    {
        $this->assertSame(['ready' => false, 'url' => '/chat/5', 'pending' => 0], ScheduledTaskController::trialResultPayload(null, 0, 5));
    }

    public function testLaReponseDUnEssaiEstRendueSansRienLaisserPasser(): void
    {
        $out = ScheduledTaskController::trialResultPayload([
            'content'      => "**Le point**\n\n- Durand\n\n[devis](https://exemple.test/d) <script>alert(1)</script> [x](javascript:alert(1)) ![img](https://pixel.test/p.png)",
            'actions_json' => json_encode([['t' => 'gmail_search', 'l' => 'Recherche dans Gmail', 'd' => 'lundi', 'ok' => true]]),
        ], 2, 5);

        $this->assertTrue($out['ready']);
        $this->assertSame(2, $out['pending']);
        $this->assertStringContainsString('<strong>Le point</strong>', $out['html']);
        $this->assertStringContainsString('<li>Durand</li>', $out['html']);
        $this->assertStringContainsString('href="https://exemple.test/d"', $out['html']);
        $this->assertStringNotContainsString('<script', $out['html']);
        $this->assertStringNotContainsString('javascript:', $out['html']);
        $this->assertStringNotContainsString('<img', $out['html'], 'Aucune ressource distante chargée par l’aperçu.');
        $this->assertSame('Recherche dans Gmail', $out['actions'][0]['l']);
        $this->assertArrayNotHasKey('error', $out);
    }

    public function testUnEssaiEnErreurEstPresenteJamaisRendu(): void
    {
        $out = ScheduledTaskController::trialResultPayload(
            ['content' => "⚠️ Crédit épuisé chez OpenAI\n\nLe compte n’a plus de crédit.", 'actions_json' => null],
            0,
            5
        );
        $this->assertSame(['title' => 'Crédit épuisé chez OpenAI', 'message' => 'Le compte n’a plus de crédit.'], $out['error']);
        $this->assertArrayNotHasKey('html', $out);

        $seul = ScheduledTaskController::trialResultPayload(['content' => '⚠ Délai dépassé.'], 0, 5);
        $this->assertSame(['title' => 'L’essai n’a pas abouti', 'message' => 'Délai dépassé.'], $seul['error']);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaMigrationAjouteLesDeuxListes(): void
    {
        $sql = self::src('database/migrations/083_scheduled_task_kb_tools.sql');
        $this->assertMatchesRegularExpression('/ADD COLUMN kb_ids\s+VARCHAR\(255\) NOT NULL DEFAULT \'\'/', $sql);
        $this->assertMatchesRegularExpression('/ADD COLUMN tool_ids\s+VARCHAR\(255\) NOT NULL DEFAULT \'\'/', $sql);
        $this->assertStringNotContainsString('FOREIGN KEY', $sql, 'Une base ou un outil supprimé ne doit jamais bloquer.');

        $modele = self::src('src/Models/ScheduledTask.php');
        $this->assertStringContainsString('sources, kb_ids, tool_ids', self::methode($modele, 'create'));
        $this->assertStringContainsString('kb_ids = ?, tool_ids = ?', self::methode($modele, 'update'));
        $this->assertStringContainsString("self::idList((string)(\$row['kb_ids'] ?? ''))", self::methode($modele, 'hydrate'));
    }

    public function testLExecutionEcritLesBasesSurSaConversation(): void
    {
        $start = self::methode(self::src('src/Schedule/Scheduler.php'), 'start');
        $this->assertStringContainsString('ConversationKnowledgeBase::setForConversation($convId', $start);
        // Écrite AVANT le message et le job : le tour lit la sélection en démarrant.
        $this->assertLessThan(strpos($start, 'JobQueue::enqueue'), strpos($start, 'ConversationKnowledgeBase::setForConversation'));
        // Dans la transaction du créneau : aucune transaction imbriquée.
        $this->assertStringContainsString('$db->inTransaction()', self::methode(self::src('src/Models/ConversationKnowledgeBase.php'), 'setForConversation'));
    }

    public function testLeTourPasseLesOutilsDeLaTacheAvantCeuxDeLAssistant(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $run = self::methode($chat, 'runSend');
        $this->assertStringContainsString("\$this->resolveTaskTools((array)((\$scheduled ?? \$workflowStep)['tool_ids'] ?? [])", $run);
        $this->assertStringContainsString("\$taskTools['image'] ?? \$this->resolveProfileImageTool(", $run);
        $this->assertStringContainsString("\$taskTools['n8n'] ?? \$this->resolveProfileN8nTool(", $run);
        $this->assertStringContainsString("\$taskTools['gamma'] ?? \$this->resolveProfileGammaTool(", $run);

        // Revérifiés au moment T : groupes de la personne, outil utilisable.
        $resolve = self::methode($chat, 'resolveTaskTools');
        $this->assertStringContainsString('ChatTool::findActiveByIdForUser(', $resolve);
        $this->assertStringContainsString('ScheduledTask::toolUnavailableReason($tool)', $resolve);
        $this->assertStringContainsString('ScheduledTask::TOOL_TYPES', $resolve);
    }

    public function testLOutilProgrammerTacheValideAvecLesChoixComplets(): void
    {
        $chat = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        // Décrire l'outil quand la boucle tourne déjà : choix légers (aucune
        // requête par assistant) ; en entier seulement sur demande explicite.
        $this->assertStringContainsString('ScheduledTask::choicesFor($user, $scheduleRequest)', $chat);
        // Les bases cochées dans la conversation passent à la tâche proposée.
        $this->assertStringContainsString("'kb_ids'     => array_map(static fn(array \$kb): int => (int)\$kb['id'], \$userKbs)", $chat);

        $tools = self::src('src/Agent/Tools/ScheduleTools.php');
        $this->assertStringContainsString('$choices = ScheduledTask::choicesFor($this->turn->user);', $tools);
        $this->assertStringContainsString('ScheduledTask::allowedFrom($choices)', $tools);
    }

    public function testLEssaiEstUneExecutionUniqueRejointeJamaisDoublee(): void
    {
        $sched = self::src('src/Schedule/Scheduler.php');
        $trial = self::methode($sched, 'startTrial');
        $this->assertStringContainsString('JobQueue::activeByDedupKey(self::trialDedupKey($userId))', $trial);
        $this->assertStringContainsString('self::context($task)', $trial, 'Mêmes vérifications qu’une exécution programmée.');
        $this->assertStringContainsString('self::payload($task, $llm', $trial, 'Même charge qu’une exécution programmée.');
        $this->assertStringContainsString('$db->rollBack()', $trial);
        $this->assertSame('sched_trial:12', Scheduler::trialDedupKey(12));

        $ctrl = self::src('src/Controllers/ScheduledTaskController.php');
        $action = self::methode($ctrl, 'trial');
        $this->assertStringContainsString('ScheduledTask::normalizeInput($_POST, ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user)))', $action);
        $this->assertStringContainsString('JobQueue::respondAndDispatch(', $action, 'La réponse part avant le déclenchement du job.');
        $this->assertStringContainsString('dropReplacedTrial(', $action);

        // Un essai n'est remplacé que s'il n'a servi à rien d'autre.
        $drop = self::methode($ctrl, 'dropReplacedTrial');
        $this->assertStringContainsString('self::TRIAL_SESSION_KEY', $drop);
        $this->assertStringContainsString('Conversation::belongsToUser($convId, $userId)', $drop);
        $this->assertStringContainsString("role = 'user'", $drop);
        $this->assertStringContainsString("status IN ('pending','running')", $drop);
        $this->assertStringContainsString('countPendingByConversation', $drop);

        $resultat = self::methode($ctrl, 'trialResult');
        $this->assertStringContainsString('Conversation::belongsToUser($convId, (int)$user[\'id\'])', $resultat);
    }

    public function testLesRoutesDeLEssaiPrecedentLaRouteParametree(): void
    {
        $index = self::src('public/index.php');
        $essai = strpos($index, "\$router->post('/settings/schedules/trial'");
        $resultat = strpos($index, "\$router->get('/settings/schedules/trial/{id}'");
        $param = strpos($index, "\$router->post('/settings/schedules/{id}'");
        $this->assertIsInt($essai);
        $this->assertIsInt($resultat);
        $this->assertIsInt($param);
        $this->assertLessThan($param, $essai, '« trial » serait pris pour un identifiant de tâche.');
    }

    public function testLeFormulairePorteBasesOutilsEssaiEtAvatar(): void
    {
        $tpl = self::src('templates/settings/schedule-form.php');
        foreach (['id="schedKbs"', 'name="kb_ids[]"', 'name="tool_ids[]"', 'id="schedActionsForced"', 'id="schedAssistantCard"',
                  'id="schedProfileAvatar"', 'id="schedTrialBtn"', 'id="schedTrialStop"', 'id="schedTrialResult"',
                  'window.SCHED_FORM'] as $marqueur) {
            $this->assertStringContainsString($marqueur, $tpl, "Formulaire : $marqueur absent.");
        }
        // Mêmes icônes que le menu et le composer : une seule source.
        $this->assertStringContainsString("AdminMenu::icon('kb'", $tpl);
        $this->assertStringContainsString("AdminMenu::icon('profiles'", $tpl);
        // Le plafond affiché est celui du serveur.
        $this->assertStringContainsString('data-max="<?= ConversationKnowledgeBase::MAX_SELECTED ?>"', $tpl);
        // Quitter le formulaire pour voir l'essai perdrait la saisie.
        $this->assertMatchesRegularExpression('/id="schedTrialLink"[^>]*target="_blank"/', $tpl);
        $this->assertStringContainsString('JSON_HEX_TAG', $tpl, 'Données injectées dans un <script> : jamais de </script> possible.');
    }

    public function testLeScriptSuitLEssaiSansJamaisInjecterLeFluxBrut(): void
    {
        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("new EventSource('/jobs/' + jobId + '/stream')", $js);
        $this->assertStringContainsString("fetch('/jobs/' + jobId + '/cancel'", $js);
        $this->assertStringContainsString("fetch('/settings/schedules/trial/' + convId", $js);
        // Pendant l'écriture : du TEXTE ; le HTML ne vient que du rendu serveur.
        $this->assertStringContainsString('textEl.textContent = streamPreview(rawText);', $js);
        $this->assertSame(1, substr_count($js, '.innerHTML = '), 'Un seul innerHTML : la réponse rendue par le serveur.');
        $this->assertStringContainsString('textEl.innerHTML = data.html;', $js);
        $this->assertStringContainsString("a.target = '_blank';", $js);
        // Un outil qui agit exclut la lecture seule, et rend le choix de la
        // personne en partant (lecture seule, à approuver, sans validation).
        $this->assertStringContainsString("chip.dataset.acting === '1'", $js);
        $this->assertStringContainsString("if (wanted === '' && acting) wanted = '1';", $js);
        $this->assertStringContainsString("modeInputs.forEach((opt) => { opt.checked = opt.value === wanted; });", $js);
    }

    public function testLaVueLegereDuFluxRetireLaSyntaxeSansRienPerdre(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $js = self::src('public/assets/js/schedules.js');
        $this->assertSame(1, preg_match('/function streamPreview\(md\) \{.*?\n    \}/s', $js, $m));
        $script = $m[0] . "\nprocess.stdout.write(JSON.stringify(["
            . "streamPreview('**Le point**\\n\\n- **3 clients** : Durand\\n## Titre\\nVoir [le devis](https://x.test/d) et `code`'),"
            . "streamPreview('Début **gras pas fini'),"
            . "streamPreview('2 * 3 = 6 et a*b')"
            . "]));";
        $fichier = tempnam(sys_get_temp_dir(), 'stream') . '.js';
        file_put_contents($fichier, $script);
        try {
            $out = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier)), true);
        } finally {
            @unlink($fichier);
        }
        $this->assertSame([
            "Le point\n\n• 3 clients : Durand\nTitre\nVoir le devis et code",
            'Début gras pas fini',
            '2 * 3 = 6 et a*b',
        ], $out);
    }

    public function testLesStylesSontAuPremierNiveau(): void
    {
        $css = self::src('public/assets/css/main.css');
        foreach (['.sched-assistant-card', '.sched-tile', '.sched-tile.is-locked', '.sched-forced', '.sched-trial-result', '.sched-trial-text.is-rendered'] as $sel) {
            $this->assertStringContainsString($sel, $css, "Style absent : $sel");
        }
        // Le piège `hidden` contre `display:flex` : chaque bloc masquable a sa règle.
        $this->assertStringContainsString('.sched-trial-result[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.sched-trial-failed[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.sched-forced[hidden] { display: none !important; }', $css);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $page = self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md');
        foreach (['**Avec quoi**', 'Lancer un essai', 'Préparer des actions à approuver', 'automatisation n8n'] as $mot) {
            $this->assertStringContainsString($mot, $page, "Page utilisateur : « $mot » absent.");
        }
        $this->assertStringContainsString('Lancer un essai', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('« Lancer un essai »', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Lancer un essai', self::src('docs/user/admin/outils/regler-les-taches-programmees.md'));
        $this->assertStringContainsString('Lancer un essai', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('083_scheduled_task_kb_tools.sql', self::src('CLAUDE.md'));
    }

    public function testLesRaisonsDesBasesSontTraduites(): void
    {
        $en = require ROOT . '/lang/en.php';
        // Toutes les raisons que KnowledgeBase::unavailableReason peut rendre.
        $kb = self::methode(self::src('src/Models/KnowledgeBase.php'), 'unavailableReason');
        preg_match_all("/return '([^']+)'/u", $kb, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $raison) {
            $this->assertArrayHasKey($raison, $en, "Raison non traduite : $raison");
        }
        $this->assertStringContainsString("'reason'      => \$reason !== null ? t(\$reason) : null", self::src('src/Models/ScheduledTask.php'));
        $this->assertStringContainsString('te((string)$kbReason)', self::src('templates/chat/index.php'));
    }
}
