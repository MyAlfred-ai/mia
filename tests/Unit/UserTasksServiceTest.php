<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Logique pure du connecteur Google Tasks : scopes, validation des saisies,
 * corps insert/patch, fenêtre d'échéance, préférences utilisateur et aperçus
 * d'approbation — sans aucun appel réseau.
 */
final class UserTasksServiceTest extends TestCase
{
    /** Le contrôleur porte le gate lexical et le parseur (mêmes statiques pures que la mémoire). */
    public static function setUpBeforeClass(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------
    // Scopes — le piège du préfixe
    // ---------------------------------------------------------------

    /**
     * `…/auth/tasks` est un PRÉFIXE de `…/auth/tasks.readonly` : un
     * `str_contains` ferait passer une connexion en lecture seule pour une
     * connexion en écriture, et le connecteur exposerait des outils que Google
     * refuserait ensuite en 403.
     */
    public function testUnScopeLectureSeuleNAccordePasLEcriture(): void
    {
        $lecture = 'openid email ' . UserTasksService::SCOPE_READONLY;

        $this->assertTrue(UserTasksService::scopeGranted($lecture, UserTasksService::SCOPE_READONLY));
        $this->assertFalse(
            UserTasksService::scopeGranted($lecture, UserTasksService::SCOPE_WRITE),
            'tasks.readonly ne doit JAMAIS valoir tasks (écriture).'
        );
    }

    public function testUnScopeEcritureEstReconnu(): void
    {
        $ecriture = UserTasksService::SCOPE_WRITE . ' openid';
        $this->assertTrue(UserTasksService::scopeGranted($ecriture, UserTasksService::SCOPE_WRITE));
        $this->assertFalse(UserTasksService::scopeGranted('', UserTasksService::SCOPE_WRITE));
    }

    // ---------------------------------------------------------------
    // Saisies
    // ---------------------------------------------------------------

    public function testNormalizeListRef(): void
    {
        $this->assertSame('', UserTasksService::normalizeListRef(''));
        $this->assertSame('', UserTasksService::normalizeListRef(' principale '));
        $this->assertSame('MTIzNDU2', UserTasksService::normalizeListRef(' MTIzNDU2 '));
    }

    public function testAssertTaskId(): void
    {
        $this->assertSame('abc123XYZ', UserTasksService::assertTaskId(' abc123XYZ '));

        $this->expectException(RuntimeException::class);
        UserTasksService::assertTaskId('trop court');
    }

    /**
     * Google Tasks ne stocke QUE la date d'échéance : l'heure fournie est
     * ignorée côté Google, on normalise donc à minuit UTC plutôt que de
     * laisser croire à une heure d'échéance qui n'existe pas.
     */
    public function testParseDueDateNormaliseAMinuitUtc(): void
    {
        $this->assertSame('2026-08-07T00:00:00.000Z', UserTasksService::parseDueDate('2026-08-07'));
        $this->assertSame('2026-08-07T00:00:00.000Z', UserTasksService::parseDueDate('2026-08-07 17:45'));
        $this->assertNull(UserTasksService::parseDueDate('  '));
    }

    public function testParseDueDateInvalide(): void
    {
        $this->expectException(RuntimeException::class);
        UserTasksService::parseDueDate('07/08/2026');
    }

    public function testNormalizeStatus(): void
    {
        $this->assertSame('completed', UserTasksService::normalizeStatus('terminee'));
        $this->assertSame('completed', UserTasksService::normalizeStatus('Terminée'));
        $this->assertSame('needsAction', UserTasksService::normalizeStatus('a_faire'));

        $this->expectException(RuntimeException::class);
        UserTasksService::normalizeStatus('en cours');
    }

    /** Sans consigne du modèle, la préférence « masquer les terminées » décide. */
    public function testNormalizeStatusFilterSuitLaPreference(): void
    {
        $masque = [UserTasksService::PREF_HIDE_DONE => true];
        $tout   = [UserTasksService::PREF_HIDE_DONE => false];

        $this->assertSame('a_faire', UserTasksService::normalizeStatusFilter('', $masque));
        $this->assertSame('toutes',  UserTasksService::normalizeStatusFilter('', $tout));
        // Une demande explicite prime sur la préférence.
        $this->assertSame('terminee', UserTasksService::normalizeStatusFilter('terminee', $masque));
        $this->assertSame('toutes',   UserTasksService::normalizeStatusFilter('toutes', $masque));
    }

    // ---------------------------------------------------------------
    // Corps d'API
    // ---------------------------------------------------------------

    public function testBuildTaskPayload(): void
    {
        $p = UserTasksService::buildTaskPayload([
            'titre'    => 'Relancer le fournisseur',
            'notes'    => 'Devis du 12/07',
            'echeance' => '2026-08-07',
        ]);
        $this->assertSame('Relancer le fournisseur', $p['title']);
        $this->assertSame('Devis du 12/07', $p['notes']);
        $this->assertSame('2026-08-07T00:00:00.000Z', $p['due']);
        $this->assertArrayNotHasKey('status', $p);
    }

    public function testBuildTaskPayloadSansTitre(): void
    {
        $this->expectException(RuntimeException::class);
        UserTasksService::buildTaskPayload(['echeance' => '2026-08-07']);
    }

    /** Un champ absent ne doit JAMAIS écraser l'existant. */
    public function testBuildTaskPatchNeTouchePasAuxChampsAbsents(): void
    {
        $patch = UserTasksService::buildTaskPatch(['titre' => 'Nouveau titre']);
        $this->assertSame(['title' => 'Nouveau titre'], $patch);
    }

    /**
     * Repasser une tâche « à faire » sans effacer `completed` la laisse
     * terminée côté Google : les deux champs vont ensemble.
     */
    public function testBuildTaskPatchReouvreVraimentUneTache(): void
    {
        $patch = UserTasksService::buildTaskPatch(['statut' => 'a_faire']);
        $this->assertSame('needsAction', $patch['status']);
        $this->assertArrayHasKey('completed', $patch);
        $this->assertNull($patch['completed']);
    }

    /**
     * Le retrait d'échéance ne passe PAS par le patch (un `due: null` est
     * ignoré par l'API) : il se joue par absence de `due` dans un PUT.
     */
    public function testLeRetraitDEcheanceEstDetecteEtHorsPatch(): void
    {
        $this->assertTrue(UserTasksService::clearsDueDate(['echeance' => 'aucune']));
        $this->assertTrue(UserTasksService::clearsDueDate(['echeance' => '-']));
        $this->assertFalse(UserTasksService::clearsDueDate(['echeance' => '2026-08-07']));
        $this->assertFalse(UserTasksService::clearsDueDate([]));

        $this->assertSame([], UserTasksService::buildTaskPatch(['echeance' => 'aucune']));
    }

    // ---------------------------------------------------------------
    // Fenêtre d'échéance
    // ---------------------------------------------------------------

    /**
     * Une fenêtre EXCLUT les tâches sans échéance côté Google : sans horizon
     * configuré ni dates demandées, aucune borne ne doit être posée.
     */
    public function testDueWindowRienParDefaut(): void
    {
        $this->assertNull(UserTasksService::dueWindow('', '', UserTasksService::defaultPrefs()));
    }

    public function testDueWindowHorizonConfigure(): void
    {
        $prefs = [UserTasksService::PREF_HORIZON => 7];
        $w = UserTasksService::dueWindow('', '', $prefs);
        $this->assertIsArray($w);

        $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')));
        $this->assertSame($today->format('Y-m-d\T00:00:00.000\Z'), $w[0]);
        $this->assertSame($today->modify('+7 days')->format('Y-m-d\T00:00:00.000\Z'), $w[1]);
    }

    public function testDueWindowDatesExplicitesPriment(): void
    {
        $w = UserTasksService::dueWindow('2026-08-01', '2026-08-31', [UserTasksService::PREF_HORIZON => 7]);
        $this->assertSame('2026-08-01T00:00:00.000Z', $w[0]);
        $this->assertSame('2026-08-31T00:00:00.000Z', $w[1]);
    }

    public function testDueWindowFinAvantDebut(): void
    {
        $this->expectException(RuntimeException::class);
        UserTasksService::dueWindow('2026-08-31', '2026-08-01', []);
    }

    // ---------------------------------------------------------------
    // Restitution
    // ---------------------------------------------------------------

    public function testSummarizeTask(): void
    {
        $t = UserTasksService::summarizeTask([
            'id'     => 'abc123XYZ',
            'title'  => 'Relancer',
            'status' => 'completed',
            'due'    => '2026-08-07T00:00:00.000Z',
            'notes'  => 'Note courte',
        ], 'LISTE1');

        $this->assertSame('abc123XYZ', $t['id']);
        $this->assertSame('LISTE1', $t['liste']);
        $this->assertSame('terminee', $t['statut']);
        $this->assertSame('vendredi 07/08/2026', $t['echeance']);
        $this->assertSame('Note courte', $t['apercu']);
    }

    public function testFormatDue(): void
    {
        $this->assertSame('', UserTasksService::formatDue(null));
        $this->assertSame('', UserTasksService::formatDue(''));
        $this->assertSame('vendredi 07/08/2026', UserTasksService::formatDue('2026-08-07T00:00:00.000Z'));
    }

    // ---------------------------------------------------------------
    // Préférences utilisateur
    // ---------------------------------------------------------------

    public function testSanitizePrefsBorneEtComplete(): void
    {
        $p = UserTasksService::sanitizePrefs([
            UserTasksService::PREF_HORIZON      => '9999',
            UserTasksService::PREF_NOTES        => str_repeat('a', 900),
            UserTasksService::PREF_HIDE_DONE    => '0',
            UserTasksService::PREF_ALLOW_DELETE => '1',
        ]);

        $this->assertSame(UserTasksService::MAX_HORIZON_DAYS, $p[UserTasksService::PREF_HORIZON]);
        $this->assertFalse($p[UserTasksService::PREF_HIDE_DONE]);
        $this->assertTrue($p[UserTasksService::PREF_ALLOW_DELETE]);
        // Clés toujours présentes : les appelants n'ont pas à re-fusionner.
        foreach (array_keys(UserTasksService::defaultPrefs()) as $cle) {
            $this->assertArrayHasKey($cle, $p);
        }
    }

    /** Le libellé de liste ne survit pas sans son id : il n'aurait plus de cible. */
    public function testSanitizePrefsOublieLeLibelleSansId(): void
    {
        $p = UserTasksService::sanitizePrefs([
            UserTasksService::PREF_LIST_ID   => '',
            UserTasksService::PREF_LIST_NAME => 'Travail',
        ]);
        $this->assertSame('', $p[UserTasksService::PREF_LIST_NAME]);
    }

    /** Défauts : lecture confortable, suppression possible mais approuvée. */
    public function testDefautsDuConnecteur(): void
    {
        $d = UserTasksService::defaultPrefs();
        $this->assertTrue($d[UserTasksService::PREF_HIDE_DONE]);
        $this->assertTrue($d[UserTasksService::PREF_ALLOW_DELETE]);
        $this->assertSame(0, $d[UserTasksService::PREF_HORIZON]);
    }

    public function testPromptFragmentVideSansPreference(): void
    {
        // Défauts « neutres » : seule la préférence de masquage parle.
        $frag = UserTasksService::prefsPromptFragment([
            UserTasksService::PREF_HIDE_DONE    => false,
            UserTasksService::PREF_ALLOW_DELETE => true,
            UserTasksService::PREF_HORIZON      => 0,
        ]);
        $this->assertSame('', $frag);
    }

    public function testPromptFragmentPorteLesPreferencesEtLesInfos(): void
    {
        $frag = UserTasksService::prefsPromptFragment([
            UserTasksService::PREF_LIST_ID      => 'L1',
            UserTasksService::PREF_LIST_NAME    => 'Travail',
            UserTasksService::PREF_HIDE_DONE    => true,
            UserTasksService::PREF_HORIZON      => 14,
            UserTasksService::PREF_ALLOW_DELETE => false,
            UserTasksService::PREF_RULES        => [
                ['id' => 'aaaabbbb', 'texte' => 'Toute tâche porte une échéance.', 'source' => 'chat'],
            ],
        ]);

        $this->assertStringContainsString('Travail', $frag);
        $this->assertStringContainsString('terminées', $frag);
        $this->assertStringContainsString('14', $frag);
        $this->assertStringContainsString('DÉSACTIVÉ la suppression', $frag);
        $this->assertStringContainsString('Toute tâche porte une échéance.', $frag);
        // Le texte libre ne doit pas être présenté comme une consigne pouvant
        // élargir les droits du modèle.
        $this->assertStringContainsString('sans jamais élargir', $frag);
    }

    // ---------------------------------------------------------------
    // Règles d'organisation (long terme)
    // ---------------------------------------------------------------

    public function testSanitizeRuleTextBorneEtAplatit(): void
    {
        $this->assertSame('Les relances vont dans Travail', UserTasksService::sanitizeRuleText("  Les relances\n vont   dans Travail "));
        $this->assertSame('', UserTasksService::sanitizeRuleText("   \n  "));

        $long = UserTasksService::sanitizeRuleText(str_repeat('a', 400));
        $this->assertSame(UserTasksService::MAX_RULE_LEN, mb_strlen($long));
    }

    /** Deux formulations de la même consigne mangeraient deux fois le prompt. */
    public function testLesReglesSontDedupliquees(): void
    {
        $prefs = [UserTasksService::PREF_RULES => []];
        $a = UserTasksService::addRule($prefs, 'Les relances vont dans Travail', 'chat');
        $this->assertSame('ok', $a['status']);

        $b = UserTasksService::addRule($a['prefs'], '  les   RELANCES vont dans travail !', 'manuel');
        $this->assertSame('duplicate', $b['status']);
        $this->assertCount(1, $b['prefs'][UserTasksService::PREF_RULES]);
    }

    public function testLesReglesSontBornees(): void
    {
        $prefs = [UserTasksService::PREF_RULES => []];
        for ($i = 0; $i < UserTasksService::MAX_RULES; $i++) {
            $prefs = UserTasksService::addRule($prefs, 'Règle numéro ' . $i, 'manuel')['prefs'];
        }
        $this->assertSame('full', UserTasksService::addRule($prefs, 'Une de trop', 'manuel')['status']);
        $this->assertSame('empty', UserTasksService::addRule($prefs, '   ', 'manuel')['status']);
    }

    /**
     * Le formulaire poste une map id => texte. Vider un champ SUPPRIME la
     * règle : c'est ce qui évite une route de suppression à part.
     */
    public function testNormalizeRulesAccepteLaFormeFormulaireEtSupprimeLesVides(): void
    {
        $regles = UserTasksService::normalizeRules([
            'aaaabbbb' => 'Les relances vont dans Travail',
            'ccccdddd' => '   ',
            'eeeeffff' => 'Toute tâche porte une échéance',
        ]);

        $this->assertCount(2, $regles);
        $this->assertSame('aaaabbbb', $regles[0]['id']);
        $this->assertSame('Toute tâche porte une échéance', $regles[1]['texte']);
    }

    /** Un id absent ou forgé est remplacé, jamais recopié tel quel. */
    public function testNormalizeRulesRegenereUnIdInvalide(): void
    {
        $regles = UserTasksService::normalizeRules([['texte' => 'Sans id', 'source' => 'chat']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $regles[0]['id']);
        $this->assertSame('chat', $regles[0]['source']);
    }

    /**
     * Le blob hérité (500 caractères en une zone de texte) devient une règle,
     * et le champ est vidé pour de bon : deux endroits disant la même chose
     * finiraient par diverger, un seul serait injecté au modèle.
     */
    public function testLeBlobHeriteEstAbsorbeDansLesRegles(): void
    {
        $p = UserTasksService::sanitizePrefs([
            UserTasksService::PREF_NOTES => 'Mes tâches pro vont dans Travail.',
        ]);

        $this->assertSame('', $p[UserTasksService::PREF_NOTES]);
        $this->assertCount(1, $p[UserTasksService::PREF_RULES]);
        $this->assertSame('Mes tâches pro vont dans Travail.', $p[UserTasksService::PREF_RULES][0]['texte']);
    }

    public function testLeNudgeSeTaitQuandLaListeEstPleine(): void
    {
        $vide = UserTasksService::ruleNudgeFragment([UserTasksService::PREF_RULES => []]);
        $this->assertStringContainsString('Je retiens', $vide);

        $regles = [];
        for ($i = 0; $i < UserTasksService::MAX_RULES; $i++) {
            $regles[] = ['id' => sprintf('%08x', $i), 'texte' => 'Règle ' . $i, 'source' => 'manuel'];
        }
        // Proposer de retenir alors qu'il n'y a plus de place, c'est promettre
        // pour rien.
        $this->assertSame('', UserTasksService::ruleNudgeFragment([UserTasksService::PREF_RULES => $regles]));
    }

    // ---------------------------------------------------------------
    // Capture depuis le chat : gate lexical et extraction
    // ---------------------------------------------------------------

    /** Porte 1 : marqueur de DURÉE **et** mot du DOMAINE, dans le même message. */
    public function testLeGateSOuvreSurUneConventionDurable(): void
    {
        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('Range toujours mes relances dans la liste Travail'));
        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('Mets systématiquement une échéance'));
        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('Retiens que mes tâches perso vont dans Maison'));
    }

    /** Une demande ponctuelle n'est pas une convention : rien ne doit être retenu. */
    public function testLeGateResteFermeSurUneDemandePonctuelle(): void
    {
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('Ajoute une tâche pour vendredi'));
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('Qu\'est-ce qu\'il me reste à faire ?'));
        // Marqueur de durée SANS domaine : « je fais toujours ça le lundi ».
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('Je pars toujours en vacances en août'));
        // Domaine SANS marqueur de durée.
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('Supprime cette tâche'));
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest(''));
    }

    /**
     * Porte 2 — la plus importante : l'assistant PROPOSE de retenir, l'utilisateur
     * répond « oui », et ce « oui » ne porte ni marqueur de durée ni mot du
     * domaine. Sans cette porte, la proposition serait acceptée et RIEN ne
     * serait enregistré : la promesse la plus coûteuse qui soit.
     */
    public function testLeGateSOuvreSurUneConfirmationDeProposition(): void
    {
        $propose = 'Je retiens que les relances client vont dans Travail ?';

        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('oui', $propose));
        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('Ok, vas-y', $propose));
        $this->assertTrue(ChatController::looksLikeTaskRuleRequest('exactement', $propose));

        // Sans proposition précédente, « oui » ne veut rien dire.
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('oui', ''));
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('oui', 'Voici tes 4 tâches en retard.'));
        // Un refus ne déclenche rien non plus.
        $this->assertFalse(ChatController::looksLikeTaskRuleRequest('non merci', $propose));
    }

    public function testLastAssistantMessage(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'salut'],
            ['role' => 'assistant', 'content' => 'première réponse'],
            ['role' => 'user', 'content' => 'et ça ?'],
            ['role' => 'assistant', 'content' => 'Je retiens ça ?'],
        ];
        $this->assertSame('Je retiens ça ?', ChatController::lastAssistantMessage($history));
        $this->assertSame('', ChatController::lastAssistantMessage([['role' => 'user', 'content' => 'x']]));
        $this->assertSame('', ChatController::lastAssistantMessage([]));
    }

    public function testParseTaskRuleExtractionJson(): void
    {
        $ok = ChatController::parseTaskRuleExtractionJson(
            "```json\n{\"retenir\": true, \"regles\": [\"Les relances vont dans Travail\"]}\n```"
        );
        $this->assertTrue($ok['retenir']);
        $this->assertSame(['Les relances vont dans Travail'], $ok['regles']);

        // Borne à 3 règles, comme la mémoire long terme.
        $trop = ChatController::parseTaskRuleExtractionJson(
            '{"retenir": true, "regles": ["a1","b2","c3","d4","e5"]}'
        );
        $this->assertCount(3, $trop['regles']);

        // Rien d'exploitable → null, et l'appelant n'enregistre rien.
        $this->assertNull(ChatController::parseTaskRuleExtractionJson('désolé, je ne peux pas'));
        $this->assertNull(ChatController::parseTaskRuleExtractionJson(''));
    }

    /**
     * La capture ne doit tourner QUE si le connecteur est engagé — coché, ou
     * activable par MIA (« Laisser MIA choisir », compte relié) : sinon elle
     * coûterait un appel LLM à chaque message de chaque utilisateur, connecté
     * ou non — exactement ce qu'on a évité en ne passant pas par un outil.
     */
    public function testLaCaptureEstConditionneeAuConnecteurEngage(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression(
            '/if \(\$tasksService !== null \|\| isset\(\$autoConnectors\[ConnectorRegistry::TASKS\]\)\) \{\s*\n\s*\$priorHistory/',
            $chat,
            'La capture de règles doit être imbriquée dans la garde « connecteur coché ou activable ».'
        );
        // Préférences relues après capture : l'instance porte celles d'AVANT.
        $this->assertStringContainsString(
            '$tasksService = UserTasksService::forUser((int)$user[\'id\']) ?? $tasksService;',
            $chat
        );
    }

    // ---------------------------------------------------------------
    // Aperçus d'approbation
    // ---------------------------------------------------------------

    public function testActionPreviewCreation(): void
    {
        $p = UserTasksService::actionPreview('creer_tache', [
            'titre'    => 'Relancer',
            'echeance' => '2026-08-07',
            'notes'    => 'Devis',
        ], ['liste_nom' => 'Travail']);

        $this->assertSame('Créer une tâche Google', $p['action']);
        $this->assertStringContainsString('Relancer', $p['fichier']);
        $this->assertStringContainsString('Travail', $p['impacts'][0]);
        $this->assertSame('/assets/img/google-tasks.svg', $p['icone']);
    }

    /** L'irréversibilité doit être écrite noir sur blanc sur la carte. */
    public function testActionPreviewSuppressionAnnonceLIrreversibilite(): void
    {
        $p = UserTasksService::actionPreview('supprimer_tache', ['tache' => 'abc123XYZ'], [
            'titre'     => 'Vieille tâche',
            'liste_nom' => 'Maison',
        ]);

        $this->assertSame('Supprimer une tâche Google', $p['action']);
        $this->assertSame('Vieille tâche', $p['fichier']);
        $joint = implode(' ', $p['impacts']);
        $this->assertStringContainsString('IRRÉVERSIBLE', $joint);
        $this->assertStringContainsString('Maison', $joint);
        // Une porte de sortie non destructive est proposée.
        $this->assertStringContainsString('terminée', $joint);
    }

    // ---------------------------------------------------------------
    // Câblage — un maillon oublié rend le connecteur muet en production
    // ---------------------------------------------------------------

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /**
     * Pas d'autoloader : une classe absente de la liste partagée web + CLI
     * plante en 500 — et seulement dans le worker si la liste diverge.
     */
    public function testLesClassesSontChargeesParLeSocleCommun(): void
    {
        $classes = self::lire('config/classes.php');
        foreach ([
            '/src/Auth/GoogleTasksOAuth.php',
            '/src/Tasks/UserTasksService.php',
            '/src/Models/UserConnectorPrefs.php',
        ] as $fichier) {
            $this->assertStringContainsString($fichier, $classes);
        }
    }

    public function testLesRoutesDuConnecteurSontDeclarees(): void
    {
        $routes = self::lire('public/index.php');
        foreach ([
            '/settings/tasks/connect',
            '/settings/tasks/callback',
            '/settings/tasks/disconnect',
            '/settings/tasks/prefs',
        ] as $route) {
            $this->assertStringContainsString($route, $routes, "Route « $route » absente.");
        }
    }

    /**
     * Le retour OAuth arrive sur l'URI du LOGIN : sans la reconnaissance du
     * préfixe `gtasks_`, AuthController le traiterait comme une connexion SSO
     * et la connexion échouerait sans explication.
     */
    public function testLeRetourOauthEstAiguilleParSonPrefixe(): void
    {
        $auth = self::lire('src/Controllers/AuthController.php');
        $this->assertStringContainsString("str_starts_with((string)\$state, 'gtasks_')", $auth);
        $this->assertStringContainsString('tasksCallback', $auth);
    }

    /** Le connecteur doit exister dans le registre, avec sa clé Settings. */
    public function testLeConnecteurEstAuCatalogue(): void
    {
        $this->assertContains(ConnectorRegistry::TASKS, ConnectorRegistry::keys());
        $this->assertSame(
            UserTasksService::SETTING_ENABLED,
            ConnectorRegistry::settingKey(ConnectorRegistry::TASKS),
            'Deux clés Settings divergentes masqueraient le connecteur en admin sans le couper dans le chat.'
        );
        $this->assertNotEmpty(ConnectorRegistry::defaultTriggers(ConnectorRegistry::TASKS));
    }

    /**
     * Les trois conditions d'exposition (opt-in du message, autorisation admin,
     * connexion) et le passage par la file d'approbation. Une case cochée côté
     * serveur, ou une écriture exécutée pendant le stream, casserait le contrat
     * commun à tous les connecteurs.
     */
    public function testLeConnecteurEstBrancheAuChat(): void
    {
        $chat = AgentSource::chat();

        // Opt-in par message, jamais coché côté serveur.
        $this->assertStringContainsString("\$_POST['tasks_access'] ?? '0'", $chat);
        // Le job porte l'option (le chat s'exécute dans le worker).
        $this->assertStringContainsString("'tasks_access'    => \$tasksAccess", $chat);
        // Trois conditions réunies avant d'instancier le service. La règle est
        // commune aux quatre connecteurs : une seule boucle, alimentée par le
        // registre — Tasks doit y figurer, et le service en sortir.
        $this->assertStringContainsString(
            'ConnectorRegistry::TASKS    => $tasksAccess,',
            $chat,
            'Le connecteur Tasks doit être dans la table des options du menu « + ».'
        );
        $this->assertStringContainsString(
            '($supportsTools && $cnxAsked && $connectorsAllowed && ConnectorRegistry::isEnabled($cnxKey))' . "\n"
            . '                ? $cnxClass::forUser((int)$user[\'id\'])',
            $chat,
            'Les conditions (opt-in, droit du rôle, autorisation admin, connexion) doivent rester réunies.'
        );
        $this->assertStringContainsString(
            '$tasksService    = $connectorServices[ConnectorRegistry::TASKS];',
            $chat
        );
        // Écritures : file d'approbation, jamais d'exécution pendant le stream.
        foreach (['creer_tache', 'modifier_tache', 'supprimer_tache'] as $outil) {
            $this->assertStringContainsString("\$queueConnectorWrite('$outil'", $chat);
        }
        // Exécution réelle uniquement au clic « Approuver ».
        $this->assertStringContainsString('$tasks->deleteTask($action[\'args\'])', $chat);
    }

    /**
     * La préférence « autoriser la suppression » est re-lue AU MOMENT T : sans
     * ça, une action déposée avant que l'utilisateur ne coupe la suppression
     * s'exécuterait quand même — exactement le réglage à ne pas contourner.
     */
    public function testLaSuppressionEstReverifieeAuClic(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString(
            "(string)\$action['tool'] === 'supprimer_tache' && !\$tasks->canDelete()",
            $chat
        );
    }

    /** Ligne du menu « + », envoi de l'option et phase SSE côté front. */
    public function testLeFrontExposeLeConnecteur(): void
    {
        $gabarit = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('plusMenuTasksRow', $gabarit);
        $this->assertStringContainsString('tasksAccessToggle', $gabarit);
        // Ligne rendue seulement si l'admin a configuré ET autorisé la source.
        $this->assertStringContainsString('$tasksFeatureEnabled', $gabarit);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("fd.append('tasks_access'", $js);
        $this->assertStringContainsString('tasks_read:', $js, 'Phase SSE sans libellé = bulle muette.');
        // Persistance du choix + garde de compatibilité du modèle passent par
        // la même map que les autres connecteurs.
        $this->assertStringContainsString('tasks:    tasksToggle', $js);
        $this->assertStringContainsString("{ name: 'tasks',    row: document.getElementById('plusMenuTasksRow')", $js);

        $suggest = self::lire('public/assets/js/tool-suggest.js');
        $this->assertStringContainsString("connectorRule('tasks'", $suggest);
    }

    public function testLIconeDuConnecteurExiste(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 2) . '/public/assets/img/google-tasks.svg',
            'La carte d\'approbation et le menu « + » la référencent.'
        );
    }

    public function testActionPreviewModificationListeLesChamps(): void
    {
        $p = UserTasksService::actionPreview('modifier_tache', [
            'tache'  => 'abc123XYZ',
            'titre'  => 'Titre revu',
            'statut' => 'terminee',
        ], ['liste_nom' => 'Travail']);

        $this->assertSame('Modifier une tâche Google', $p['action']);
        $this->assertStringContainsString('titre', $p['impacts'][0]);
        $this->assertStringContainsString('statut', $p['impacts'][0]);
    }
}
