<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur Microsoft To Do : logique PURE du service, appels Graph rejoués
 * sans réseau (sous-classe qui remplace `apiJson`), fournisseur d'outils et
 * câblage dans l'application.
 *
 * Les gardes visent les pièges RÉELS de Microsoft Graph, documentés en tête de
 * UserMicrosoftTodoService :
 *  - une tâche n'existe que DANS sa liste → identifiant composé `liste~tâche` ;
 *  - l'échéance est un jour déguisé en horodatage, parfois converti en UTC
 *    (minuit à Paris = 22:00 UTC la veille) ;
 *  - la liste des emails marqués est en lecture seule ;
 *  - `Tasks.Read` est un PRÉFIXE de `Tasks.ReadWrite` ET de `Tasks.Read.Shared` ;
 *  - la page suivante n'est suivie que si elle vise Graph (le jeton part avec).
 */
final class UserMicrosoftTodoServiceTest extends TestCase
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0/me/todo';

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /**
     * Service dont les appels Graph sont servis par `$routes` (préfixe d'URL →
     * réponse, ou closure). Chaque appel est noté dans `$calls`.
     */
    private static function service(array $routes, string $scope = 'Tasks.ReadWrite User.Read'): UserMicrosoftTodoService
    {
        return new class($routes, $scope) extends UserMicrosoftTodoService {
            public array $calls = [];

            public function __construct(private array $routes, string $scope)
            {
                parent::__construct(1, 'refresh', 'access', null, $scope, 'alex@example.com');
            }

            protected function apiJson(string $method, string $url, ?array $body = null, int $timeout = 60): array
            {
                $this->calls[] = [$method, $url, $body];
                foreach ($this->routes as $prefix => $reponse) {
                    if (str_starts_with($method . ' ' . $url, $prefix)) {
                        return $reponse instanceof \Closure ? $reponse($url, $body) : $reponse;
                    }
                }
                throw new \RuntimeException('Appel inattendu : ' . $method . ' ' . $url);
            }
        };
    }

    private static function listes(): array
    {
        return ['value' => [
            ['id' => 'LTRAVAIL=', 'displayName' => 'Travail', 'wellknownListName' => 'none'],
            ['id' => 'LDEFAUT=', 'displayName' => 'Tâches', 'wellknownListName' => 'defaultList'],
            ['id' => 'LFLAG=', 'displayName' => 'Emails marqués', 'wellknownListName' => 'flaggedEmails'],
        ]];
    }

    // ===================== Scopes =====================

    public function testLesScopesSontComparesExactement(): void
    {
        $this->assertTrue(UserMicrosoftTodoService::scopeGranted('Tasks.Read User.Read', 'Tasks.Read'));
        $this->assertTrue(UserMicrosoftTodoService::scopeGranted('https://graph.microsoft.com/Tasks.ReadWrite', 'Tasks.ReadWrite'));
        // LE piège : Tasks.Read préfixe Tasks.ReadWrite ET Tasks.Read.Shared.
        $this->assertFalse(UserMicrosoftTodoService::scopeGranted('Tasks.Read.Shared', 'Tasks.Read'));
        $this->assertFalse(UserMicrosoftTodoService::scopeGranted('Tasks.Read', 'Tasks.ReadWrite'));

        $lecture = self::service([], 'Tasks.Read User.Read');
        $this->assertTrue($lecture->canRead());
        $this->assertFalse($lecture->canWrite());
        $partage = self::service([], 'Tasks.Read.Shared');
        $this->assertFalse($partage->canRead(), 'Un droit sur les listes partagées seules ne lit pas les listes de la personne.');
        $this->assertTrue(self::service([])->canWrite());
    }

    public function testLeConsentementNeDemandeNiLesListesPartageesNiPlus(): void
    {
        $this->assertSame(['offline_access', 'User.Read', 'Tasks.Read', 'Tasks.ReadWrite'], MicrosoftTodoOAuth::SCOPES);
        $this->assertSame('mstodo_', MicrosoftTodoOAuth::STATE_PREFIX);
        $this->assertTrue(is_subclass_of(MicrosoftTodoOAuth::class, MicrosoftUserOAuth::class));
    }

    // ===================== Identifiant composé =====================

    public function testLIdentifiantComposePorteLaListe(): void
    {
        $ref = UserMicrosoftTodoService::composeTaskRef('LTRAVAIL=', 'AAMkT_123-abc=');
        $this->assertSame('LTRAVAIL=~AAMkT_123-abc=', $ref);
        $this->assertSame(['LTRAVAIL=', 'AAMkT_123-abc='], UserMicrosoftTodoService::splitTaskRef($ref, 'AUTRE'));
        // Identifiant nu : la liste passée à part s'applique.
        $this->assertSame(['Courses', 'AAMkT_123'], UserMicrosoftTodoService::splitTaskRef('AAMkT_123', 'Courses'));
        $this->assertSame(['', 'AAMkT_123'], UserMicrosoftTodoService::splitTaskRef('AAMkT_123', 'principale'));

        foreach (['', 'abc', 'id avec espace', 'LISTE~'] as $faux) {
            try {
                UserMicrosoftTodoService::splitTaskRef($faux);
                $this->fail("« $faux » aurait dû être refusé.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('lister_taches_todo', $e->getMessage());
            }
        }
    }

    // ===================== Échéance et rappel =====================

    public function testLEcheanceLueEstLeJourDeLUtilisateur(): void
    {
        // Posée à minuit dans son fuseau : le jour tel quel.
        $this->assertSame('2026-10-05', UserMicrosoftTodoService::dueDate(
            ['dateTime' => '2026-10-05T00:00:00.0000000', 'timeZone' => 'Europe/Paris'], 'America/New_York'));
        // Convertie en UTC par un autre client : minuit à Paris = 22:00 UTC la veille.
        $this->assertSame('2026-10-05', UserMicrosoftTodoService::dueDate(
            ['dateTime' => '2026-10-04T22:00:00.0000000', 'timeZone' => 'UTC'], 'Europe/Paris'));
        $this->assertNull(UserMicrosoftTodoService::dueDate(null));
        $this->assertNull(UserMicrosoftTodoService::dueDate(['dateTime' => 'illisible']));
        $this->assertSame('lundi 05/10/2026', UserMicrosoftTodoService::formatDue(
            ['dateTime' => '2026-10-05T00:00:00', 'timeZone' => 'UTC']));
    }

    public function testLaCreationPoseMinuitDansLeFuseauEtUnRappelHoraire(): void
    {
        $p = UserMicrosoftTodoService::buildTaskPayload([
            'titre' => '  Relancer Durand ', 'notes' => 'Devis v2', 'echeance' => '2026-10-05 15:00',
            'rappel' => '2026-10-05 09:30', 'importante' => true, 'fuseau' => 'Europe/Paris',
        ]);
        $this->assertSame('Relancer Durand', $p['title']);
        $this->assertSame(['content' => 'Devis v2', 'contentType' => 'text'], $p['body']);
        $this->assertSame(['dateTime' => '2026-10-05T00:00:00', 'timeZone' => 'Europe/Paris'], $p['dueDateTime'],
            'To Do ne retient qu\'un jour : l\'heure fournie est ignorée.');
        $this->assertTrue($p['isReminderOn']);
        $this->assertSame(['dateTime' => '2026-10-05T09:30:00', 'timeZone' => 'Europe/Paris'], $p['reminderDateTime']);
        $this->assertSame('high', $p['importance']);

        $min = UserMicrosoftTodoService::buildTaskPayload(['titre' => 'X', 'importante' => 'false']);
        $this->assertSame(['title' => 'X'], $min);

        foreach ([
            [['titre' => ''], 'Titre'],
            [['titre' => 'X', 'echeance' => 'vendredi'], 'Échéance invalide'],
            [['titre' => 'X', 'echeance' => '2026-02-30'], 'Échéance invalide'],
            [['titre' => 'X', 'rappel' => '2026-10-05'], 'Rappel invalide'],
            [['titre' => 'X', 'statut' => 'peut-être'], 'Statut invalide'],
        ] as [$args, $attendu]) {
            try {
                UserMicrosoftTodoService::buildTaskPayload($args);
                $this->fail('Entrée invalide acceptée : ' . json_encode($args));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage());
            }
        }
    }

    public function testLaModificationNEnvoieQueLesChampsFournis(): void
    {
        $this->assertSame([], UserMicrosoftTodoService::buildTaskPatch(['tache' => 'x']));
        $this->assertSame(['status' => 'completed'], UserMicrosoftTodoService::buildTaskPatch(['statut' => 'terminee']));
        $this->assertSame(['status' => 'inProgress'], UserMicrosoftTodoService::buildTaskPatch(['statut' => 'en_cours']));
        $this->assertSame(['status' => 'notStarted'], UserMicrosoftTodoService::buildTaskPatch(['statut' => 'a_faire']));

        $retrait = UserMicrosoftTodoService::buildTaskPatch(['echeance' => 'aucune', 'rappel' => 'aucun', 'importante' => false]);
        $this->assertArrayHasKey('dueDateTime', $retrait);
        $this->assertNull($retrait['dueDateTime'], 'Retirer l\'échéance = dueDateTime à null.');
        $this->assertFalse($retrait['isReminderOn']);
        $this->assertSame('normal', $retrait['importance'], 'Un booléen false est une valeur, pas une absence.');

        $notes = UserMicrosoftTodoService::buildTaskPatch(['notes' => '']);
        $this->assertSame(['content' => '', 'contentType' => 'text'], $notes['body'], 'Une chaîne vide efface les notes.');
    }

    // ===================== Résumés et tri =====================

    public function testLeResumeEstStableEtTrie(): void
    {
        $taches = [
            ['id' => 'T3', 'title' => 'Sans échéance', 'status' => 'notStarted'],
            ['id' => 'T2', 'title' => 'Faite', 'status' => 'completed', 'dueDateTime' => ['dateTime' => '2026-01-01T00:00:00', 'timeZone' => 'UTC']],
            ['id' => 'T1', 'title' => 'Urgente', 'status' => 'inProgress', 'importance' => 'high',
             'dueDateTime' => ['dateTime' => '2026-10-02T00:00:00', 'timeZone' => 'UTC'],
             'isReminderOn' => true, 'reminderDateTime' => ['dateTime' => '2026-10-02T07:00:00', 'timeZone' => 'UTC'],
             'body' => ['content' => '<p>Appeler <b>Paul</b></p>', 'contentType' => 'html']],
        ];
        $tries = UserMicrosoftTodoService::sortTasks($taches);
        $this->assertSame(['T1', 'T3', 'T2'], array_column($tries, 'id'), 'À faire avant terminées, échéance proche d\'abord, sans échéance ensuite.');

        $r = UserMicrosoftTodoService::summarizeTask($tries[0], 'LTRAVAIL=', 'Europe/Paris');
        $this->assertSame('LTRAVAIL=~T1', $r['id']);
        $this->assertSame('en_cours', $r['statut']);
        $this->assertTrue($r['importante']);
        $this->assertSame('vendredi 02/10/2026', $r['echeance']);
        $this->assertSame('vendredi 02/10/2026 09:00', $r['rappel'], 'Rappel affiché dans le fuseau de l\'utilisateur.');
        $this->assertStringContainsString('Appeler', $r['apercu']);
        $this->assertStringNotContainsString('<b>', $r['apercu']);
    }

    // ===================== Appels Graph (sans réseau) =====================

    public function testLeListingPartDeLaListeParDefautEtFiltre(): void
    {
        $svc = self::service([
            'GET ' . self::GRAPH . '/lists?' => self::listes(),
            'GET ' . self::GRAPH . '/lists/LDEFAUT%3D/tasks' => static function (string $url): array {
                if (str_contains($url, 'page2')) {
                    return ['value' => [['id' => 'T9', 'title' => 'Page 2', 'status' => 'notStarted']]];
                }
                return [
                    'value' => [
                        ['id' => 'T1', 'title' => 'Payer la facture', 'status' => 'notStarted',
                         'dueDateTime' => ['dateTime' => '2026-10-06T00:00:00', 'timeZone' => 'UTC']],
                        ['id' => 'T2', 'title' => 'Ancienne', 'status' => 'completed'],
                    ],
                    '@odata.nextLink' => self::GRAPH . '/lists/LDEFAUT%3D/tasks?page2',
                ];
            },
        ]);
        $r = $svc->listTasks('', '', '', '', 20);
        $this->assertSame('Tâches', $r['liste_nom']);
        $this->assertSame(['LDEFAUT=~T1', 'LDEFAUT=~T9'], array_column($r['taches'], 'id'), 'Terminées masquées par défaut, page suivante lue.');
        $this->assertSame('', $r['filtre_echeance']);

        // Fenêtre d'échéance : les tâches SANS échéance sortent, et c'est annoncé.
        $f = $svc->listTasks('', 'toutes', '2026-10-01', '2026-10-31', 20);
        $this->assertSame(['LDEFAUT=~T1'], array_column($f['taches'], 'id'));
        $this->assertNotSame('', $f['filtre_echeance']);
    }

    public function testUnePageSuivanteHorsGraphNEstJamaisSuivie(): void
    {
        $this->assertSame('', UserMicrosoftTodoService::nextPage(['@odata.nextLink' => 'https://evil.example/steal']));
        $this->assertSame('', UserMicrosoftTodoService::nextPage(['@odata.nextLink' => 'https://graph.microsoft.com.evil.example/v1.0/x']));
        $this->assertSame(self::GRAPH . '/x', UserMicrosoftTodoService::nextPage(['@odata.nextLink' => self::GRAPH . '/x']));
    }

    public function testUneListeSeDesigneParSonNomEtLaListeDesEmailsMarquesEstEnLectureSeule(): void
    {
        $svc = self::service([
            'GET ' . self::GRAPH . '/lists?' => self::listes(),
            'GET ' . self::GRAPH . '/lists/LTRAVAIL%3D/tasks' => ['value' => []],
            'POST ' . self::GRAPH . '/lists/LTRAVAIL%3D/tasks' => static fn(string $u, ?array $b): array => ['id' => 'NEW1'] + (array)$b,
        ]);
        $this->assertSame('LTRAVAIL=', $svc->listTasks('travail', '', '', '', 5)['liste'], 'Nom sans casse.');

        $cree = $svc->createTask(['titre' => 'Préparer le comité', 'liste' => 'Travail', 'echeance' => '2026-10-09', 'fuseau' => 'Europe/Paris']);
        $this->assertSame('LTRAVAIL=~NEW1', $cree['id']);
        $this->assertSame('Travail', $cree['liste_nom']);
        $this->assertSame('vendredi 09/10/2026', $cree['echeance']);

        try {
            $svc->createTask(['titre' => 'X', 'liste' => 'Emails marqués']);
            $this->fail('La liste des emails marqués ne reçoit pas de tâche.');
        } catch (\RuntimeException $e) {
            $this->assertSame(UserMicrosoftTodoService::MSG_FLAGGED_READONLY, $e->getMessage());
        }
        try {
            $svc->listTasks('Inconnue', '', '', '', 5);
            $this->fail('Liste inconnue acceptée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lister_listes_todo', $e->getMessage());
        }
        $this->assertCount(1, array_filter($svc->calls, static fn(array $c): bool => str_contains($c[1], '/lists?')),
            'Les listes sont lues une fois par tour (cache).');
    }

    public function testLaModificationViseLaListeDeLIdentifiantCompose(): void
    {
        $svc = self::service([
            'GET ' . self::GRAPH . '/lists?' => self::listes(),
            'PATCH ' . self::GRAPH . '/lists/LTRAVAIL%3D/tasks/TASK1' => static fn(string $u, ?array $b): array => ['id' => 'TASK1', 'title' => 'Payer'] + (array)$b,
        ]);
        $r = $svc->updateTask(['tache' => 'LTRAVAIL=~TASK1', 'liste' => 'Tâches', 'statut' => 'terminee']);
        $this->assertSame('LTRAVAIL=~TASK1', $r['id']);
        $patch = end($svc->calls);
        $this->assertSame(['status' => 'completed'], $patch[2]);

        $lecture = self::service([], 'Tasks.Read');
        try {
            $lecture->createTask(['titre' => 'X']);
            $this->fail('Écriture sans Tasks.ReadWrite.');
        } catch (\RuntimeException $e) {
            $this->assertSame(UserMicrosoftTodoService::MSG_RECONNECT_WRITE, $e->getMessage());
        }
    }

    public function testAucuneSuppressionNExisteDansLeCode(): void
    {
        $code = '';
        foreach (['src/Todo/UserMicrosoftTodoService.php', 'src/Agent/Tools/MicrosoftTodoTools.php'] as $f) {
            foreach (token_get_all(self::lire($f)) as $t) {
                if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($t) ? $t[1] : $t;
                }
            }
        }
        $this->assertStringNotContainsString("'DELETE'", $code);
        $this->assertStringNotContainsString('supprimer_tache_todo', $code);
    }

    // ===================== Carte d'approbation =====================

    public function testLaCarteNommeLaListeEtLeRappel(): void
    {
        $c = UserMicrosoftTodoService::actionPreview('creer_tache_todo',
            ['titre' => 'Payer', 'echeance' => '2026-10-06', 'rappel' => '2026-10-06 09:00', 'fuseau' => 'Europe/Paris', 'notes' => 'Facture EDF'],
            ['liste_nom' => 'Travail']);
        $this->assertSame('Créer une tâche Microsoft To Do', $c['action']);
        $this->assertSame('Payer — 2026-10-06', $c['fichier']);
        $this->assertStringContainsString('« Travail »', implode(' ', $c['impacts']));
        $this->assertStringContainsString('2026-10-06 09:00 (Europe/Paris)', implode(' ', $c['impacts']));
        $this->assertSame('/assets/img/microsoft-todo.svg', $c['icone']);
        $this->assertSame('Facture EDF', $c['apercu']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public' . $c['icone']);

        $m = UserMicrosoftTodoService::actionPreview('modifier_tache_todo', ['statut' => 'terminee', 'echeance' => 'aucune'], ['titre' => 'Payer']);
        $this->assertSame('Modifier une tâche Microsoft To Do', $m['action']);
        $this->assertStringContainsString('échéance, statut', $m['impacts'][0]);
        $this->assertSame('Payer', $m['fichier']);
    }

    // ===================== Fournisseur d'outils =====================

    public function testLesOutilsSuiventLeDroitDEcriture(): void
    {
        $noms = static fn(bool $w): array => array_column(MicrosoftTodoTools::definitionsFor($w), 'name');
        $this->assertSame(['lister_listes_todo', 'lister_taches_todo', 'lire_tache_todo'], $noms(false));
        $this->assertSame(['lister_listes_todo', 'lister_taches_todo', 'lire_tache_todo', 'creer_tache_todo', 'modifier_tache_todo'], $noms(true));

        $now = new \DateTimeImmutable('2026-10-02 10:00', new \DateTimeZone('Europe/Paris'));
        $this->assertStringContainsString('vendredi 02/10/2026', MicrosoftTodoTools::guidanceFor(true, $now));
        $this->assertStringContainsString('en_attente_approbation', MicrosoftTodoTools::guidanceFor(true, $now));
        $this->assertStringContainsString('lecture seule', MicrosoftTodoTools::guidanceFor(false, $now));
    }

    public function testLesHandlersValidentAvantDeDeposerUneCarte(): void
    {
        $deposes = [];
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            function (string $t, array $a, ?array $r) use (&$deposes): array {
                $deposes[] = [$t, $a, $r];
                return ['statut' => 'en_attente_approbation'];
            }, 'Europe/Paris');
        $svc = self::service([
            'GET ' . self::GRAPH . '/lists?' => self::listes(),
            'GET ' . self::GRAPH . '/lists/LTRAVAIL%3D/tasks/TASK1' => ['id' => 'TASK1', 'title' => 'Payer'],
        ]);
        $outils = new MicrosoftTodoTools($turn, $svc);
        $this->assertSame('mstodo', $outils->connector());
        $h = $outils->handlers();

        $this->assertArrayHasKey('error', $h['creer_tache_todo'](['titre' => '']));
        $this->assertArrayHasKey('error', $h['modifier_tache_todo'](['tache' => 'LTRAVAIL=~TASK1']));
        $this->assertSame([], $deposes, 'Une entrée invalide ne dépose aucune carte.');

        $h['creer_tache_todo'](['titre' => 'Préparer', 'liste' => 'travail']);
        $h['modifier_tache_todo'](['tache' => 'LTRAVAIL=~TASK1', 'statut' => 'terminee']);
        $this->assertSame('creer_tache_todo', $deposes[0][0]);
        $this->assertSame('Europe/Paris', $deposes[0][1]['fuseau'], 'Le fuseau voyage avec l\'action.');
        $this->assertSame(['liste_nom' => 'Travail'], $deposes[0][2]);
        $this->assertSame(['titre' => 'Payer', 'liste_nom' => 'Travail'], $deposes[1][2]);
    }

    // ===================== Câblage =====================

    public function testLeConnecteurEstCableDeBoutEnBout(): void
    {
        $this->assertSame(UserMicrosoftTodoService::class, ConnectorRegistry::serviceClass('mstodo'));
        $this->assertSame(MicrosoftTodoOAuth::class, ConnectorRegistry::oauthClass('mstodo'));
        $this->assertSame(UserDriveToken::PROVIDER_MSTODO, ConnectorRegistry::tokenProvider('mstodo'));
        $this->assertContains('mstodo_', SsoRegistry::CONNECTOR_PREFIXES);
        $this->assertSame(['Tasks.Read', 'Tasks.ReadWrite'], ConnectorRegistry::delegatedScopes('microsoft')['Microsoft To Do'] ?? null,
            'La page Authentification doit nommer la permission à déclarer sur l\'application Azure.');
        $this->assertSame('mstodo_access', Scheduler::ACCESS_FLAGS['mstodo']);

        $auth = self::lire('src/Controllers/AuthController.php');
        $this->assertStringContainsString("str_starts_with((string)\$state, 'mstodo_')", $auth);
        $this->assertStringContainsString('->mstodoCallback()', $auth);

        $chat = self::lire('src/Controllers/ChatController.php');
        foreach ([
            "\$mstodoAccess   = (\$_POST['mstodo_access'] ?? '0') === '1';",
            "'mstodo_access'   => \$mstodoAccess,",
            'ConnectorRegistry::MSTODO   => $mstodoAccess,',
            'new MicrosoftTodoTools($turn, $service)',
            "['creer_tache_todo', 'modifier_tache_todo'], true)) {",
            "ConnectorContext::record(\$convId, 'mstodo_task'",
            "'mstodo_read'               => 'tasks',",
        ] as $attendu) {
            $this->assertStringContainsString($attendu, $chat);
        }

        $gabarit = self::lire('templates/chat/index.php');
        $this->assertStringContainsString("'toggle'    => 'mstodoAccessToggle'", $gabarit);
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("fd.append('mstodo_access'", $js);
        $this->assertStringContainsString("{ name: 'mstodo',   row: document.getElementById('plusMenuMsTodoRow')", $js);
        $this->assertStringContainsString("mstodo_read:", $js);
        $this->assertStringContainsString("connectorRule('mstodo'", self::lire('public/assets/js/tool-suggest.js'));
        $this->assertStringContainsString('a[href^="/settings/mstodo/connect"]', self::lire('public/assets/js/native-bridge.js'));
        $this->assertStringContainsString('id="connector-mstodo"', self::lire('templates/settings/connections.php'));

        // Ordre de chargement : le service réutilise des statiques du calendrier Outlook.
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::lire($f);
            $this->assertLessThan(strpos($src, '/src/Todo/UserMicrosoftTodoService.php'), strpos($src, '/src/Outlook/UserOutlookCalendarService.php'), $f);
            $this->assertLessThan(strpos($src, '/src/Auth/MicrosoftTodoOAuth.php'), strpos($src, '/src/Auth/MicrosoftUserOAuth.php'), $f);
            $this->assertLessThan(strpos($src, '/src/Agent/Tools/MicrosoftTodoTools.php'), strpos($src, '/src/Agent/AbstractToolProvider.php'), $f);
        }

        // Mémoire de conversation : la tâche lue est rappelée au tour suivant.
        $e = ConnectorContext::entryForTool('lire_tache_todo', ['tache' => 'L~T1'], ['id' => 'L~T1', 'titre' => 'Payer', 'echeance' => 'lundi']);
        $this->assertSame(['mstodo_task', 'mstodo', 'L~T1', 'Payer'], [$e['kind'], $e['connector'], $e['ref'], $e['name']]);
    }

    public function testLaRechercheMultiSourcesTrouveLesTachesToDo(): void
    {
        $todo = new class {
            public function listTasks(string $l, string $s, string $f, string $t, int $n): array
            {
                // Forme RÉELLE d'un listing : la liste des tâches est sous `taches`.
                return ['liste' => 'L', 'liste_nom' => 'Tâches', 'taches' => [
                    ['id' => 'L~1', 'titre' => 'Relancer Durand', 'apercu' => ''],
                    ['id' => 'L~2', 'titre' => 'Courses', 'apercu' => ''],
                ], 'filtre_echeance' => ''];
            }
        };
        $turn  = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            static fn(string $t, array $a, ?array $r): array => [], 'Europe/Paris');
        $tools = new IntentTools($turn, static fn(): array => ['mstodo' => $todo, 'tasks' => $todo]);
        $r = $tools->handlers()['rechercher_partout'](['requete' => 'Durand']);
        $this->assertSame(['Relancer Durand'], array_column($r['sources']['Microsoft To Do']['elements'], 'titre'));
        $this->assertSame('lire_tache_todo (tache = id)', $r['sources']['Microsoft To Do']['lire_avec']);
        $this->assertSame(['Relancer Durand'], array_column($r['sources']['Google Tasks']['elements'], 'titre'),
            'La réponse entière d\'un listing Google Tasks est dépliée elle aussi.');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationSuit(): void
    {
        $this->assertStringContainsString('Microsoft To Do', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Microsoft To Do', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Microsoft To Do', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Microsoft To Do', self::lire('docs/user/fonctionnalites/connecteurs/index.md'));
        $this->assertStringContainsString('Microsoft To Do', self::lire('docs/user/admin/connecteurs/index.md'));
        $this->assertStringContainsString('Microsoft To Do', self::lire('CLAUDE.md'));
    }
}
