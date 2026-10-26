<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// ---- Gestionnaire global d'erreurs, AVANT tout le reste ----
// Enregistré ici pour voir jusqu'aux fatales de config/app.php et de l'autoload.
// Il fixe aussi error_log immédiatement : le chemin par défaut était un reliquat
// Windows (C:/laragon/...), donc tout ce qui cassait avant cette ligne partait
// dans le vide, et display_errors=0 ne laissait qu'une page blanche.
require ROOT . '/src/Helpers/ErrorHandler.php';
ErrorHandler::register();

require ROOT . '/config/app.php';
ErrorHandler::setEnvironment(APP_ENV); // trace complète à l'écran en local seulement

$composerAutoload = ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

require ROOT . '/config/classes.php';

// ---- Headers de sécurité (fallback PHP si mod_headers absent) ----
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---- Méthode + URI robustes (bots/scans envoient des requêtes malformées) ----
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) ?: 'GET';
$requestUri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$requestPath   = parse_url($requestUri, PHP_URL_PATH);
$requestPath   = is_string($requestPath) && $requestPath !== '' ? (rtrim($requestPath, '/') ?: '/') : '/';

// ---- Sonde de santé : traitée AVANT tout le reste ----
// Avant Session::start(), avant la page « configuration incomplète » (la sonde
// doit répondre du JSON en toutes circonstances) et surtout avant les semis
// paresseux : appelée toutes les 30 s par le HEALTHCHECK Docker, elle
// déclencherait sinon le calcul de signature du RAG à chaque battement.
if ($requestMethod === 'GET' && $requestPath === '/healthz') {
    HealthController::healthz();
    exit;
}

// ---- Configuration rédhibitoire : page qui NOMME la variable manquante ----
// Passe AVANT la détection de setup : sous Docker, un APP_SECRET vide envoyait
// en boucle vers /setup, dont le formulaire écrit un .env à l'intérieur du
// conteneur — perdu au rebuild, et de toute façon jamais relu (l'environnement
// du process prime sur le fichier, cf. config/app.php).
$problemesConfig = Bootstrap::configProblems();
if ($problemesConfig !== []) {
    Bootstrap::renderConfigError($problemesConfig);
}

// ---- Adresse d'appel d'un workflow (webhook) : AVANT la session ----
// Un autre logiciel la poste, sans cookie ni jeton CSRF : le jeton secret de
// l'adresse fait foi (WorkflowHookController). Ni session ouverte, ni semis
// paresseux, ni contrôle CSRF — ce chemin exact seulement.
if ($requestMethod === 'POST' && preg_match('#^/hooks/workflows/([A-Za-z0-9_-]{1,80})$#', $requestPath, $hookMatch)) {
    WorkflowHookController::handle($hookMatch[1]);
    exit;
}

Session::start();

// ---- Langue de l'interface ----
// Juste après la session (resolve() la lit) et AVANT tout rendu : détection
// de setup, page 403, CSRF et routage produisent tous du texte affiché.
I18n::use(I18n::resolve());

require ROOT . '/src/Controllers/SetupController.php';

// ---- Assistant système « MIA : mode d'emploi » (semé/re-synchronisé, idempotent) ----
DefaultAssistant::ensureSeeded();

// ---- Bases de connaissances liées à un dossier distant (Drive / SharePoint) :
// déclencheur paresseux (dispatch en tâche de fond des bases dont l'intervalle
// est écoulé ; no-op sans identifiants configurés ou sans fork POSIX — ne
// ralentit ni ne casse jamais la requête)
KbSync::maybeRunDue();

// ---- File de générations asynchrones : publication de la version servie (un
// worker resté sur une image périmée cesse alors de réclamer des jobs) puis
// entretien paresseux (reprise des jobs orphelins, purge du flux rejouable).
// No-op dès qu'un conteneur `worker` tourne — il s'en charge lui-même.
JobQueue::publishCodeVersion();
JobQueue::maybeMaintain();

// ---- Vérification CSRF sur tous les POST ----
if ($requestMethod === 'POST') {
    if (!Csrf::verify()) {
        $csrfPath = parse_url($requestUri, PHP_URL_PATH);
        $csrfPath = is_string($csrfPath) && $csrfPath !== '' ? (rtrim($csrfPath, '/') ?: '/') : '/';
        // Les sessions PHP vivent DANS le conteneur : un redéploiement (ou une
        // expiration) les efface. Une page restée ouverte — typiquement la page
        // de connexion de l'app mobile en arrière-plan — poste alors un jeton
        // périmé et se prend un 403 sans issue. Le log distingue les deux cas.
        error_log('[CSRF] Rejet POST ' . $csrfPath . ' — jeton '
            . (Csrf::submitted() === '' ? 'absent' : 'périmé'));

        // Page de connexion : renvoyer une page FRAÎCHE (jeton neuf) plutôt
        // qu'un mur « Requête invalide » dont on ne sort qu'en rechargeant.
        if ($csrfPath === '/auth/login') {
            header('Location: /auth/login?error=expired');
            exit;
        }

        http_response_code(403);
        // Réponse JSON pour les requêtes AJAX, HTML sinon
        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
                  || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        if ($wantsJson) {
            header('Content-Type: application/json');
            // `code` machine (le front rejoue avec un jeton frais), `error`
            // humain conservé pour les appelants existants.
            echo json_encode(['error' => 'Requête invalide (CSRF).', 'code' => 'csrf']);
        } else {
            echo 'Requête invalide. <a href="javascript:history.back()">Retour</a>';
        }
        exit;
    }
}

// ---- Premier démarrage : aucun compte en base ----
// APRÈS la vérification CSRF : l'ancien emplacement était avant, et son `exit`
// court-circuitait le contrôle — POST /setup n'était donc jamais vérifié.
// La configuration, elle, a déjà été validée plus haut : ce qui manque ici,
// c'est uniquement un administrateur.
if (SetupController::isNeeded()) {
    if ($requestPath !== '/setup') {
        header('Location: /setup');
        exit;
    }
    $router = new Router();
    $router->get('/setup',  [SetupController::class, 'index']);
    $router->post('/setup', [SetupController::class, 'save']);
    $router->dispatch($requestMethod, $requestUri);
    exit;
}

$router = new Router();

// Fin d'installation : reste accessible une fois le compte créé (on y arrive
// par redirection depuis /setup, alors que la table n'est plus vide).
$router->get('/setup/suivant', [SetupController::class, 'nextSteps']);
// Installation déjà faite : /setup traîne dans les notes et les signets, mieux
// vaut renvoyer vers la connexion qu'une page introuvable.
$router->get('/setup', static function (): void {
    header('Location: /auth/login');
    exit;
});

// Auth
$router->get('/auth/login',          [AuthController::class, 'login']);
$router->post('/auth/login',         [AuthController::class, 'localLogin']);
$router->get('/auth/callback',       [AuthController::class, 'callback']);
$router->get('/auth/logout',         [AuthController::class, 'logout']);
$router->get('/auth/session-status', [AuthController::class, 'sessionStatus']);
$router->get('/auth/csrf',           [AuthController::class, 'csrf']);           // jeton frais (rejeu après 403)
$router->post('/auth/google-token',  [AuthController::class, 'googleTokenLogin']); // app mobile (idToken natif)

// PWA (public, hors auth : le navigateur récupère le manifest sans cookies)
$router->get('/manifest.webmanifest',     [PwaController::class, 'manifest']);

// App mobile (public, hors auth : lien d'installation partageable)
$router->get('/app',                      [MobileAppController::class, 'download']);

// Médias générés (images/vidéos) — NE SONT PLUS servis en statique par Apache
// (cf. public/.htaccess) : chaque requête passe par MediaController, qui exige
// une session OU une URL signée. `$type` figé par route (le motif ne capture
// que le nom de fichier). L'ordre importe peu : ces chemins ne collisionnent
// avec aucune autre route.
$router->get('/assets/img/generated/{name}',   static fn(string $name) => (new MediaController())->serve('img', $name));
$router->get('/assets/video/generated/{name}', static fn(string $name) => (new MediaController())->serve('video', $name));

// Chat
$router->get('/',                         [ChatController::class, 'index']);
$router->get('/a-propos',                 [AboutController::class, 'index']);

// Paramètres utilisateur
$router->get('/settings',                 [UserSettingsController::class, 'profile']);
$router->get('/settings/ai',              [UserSettingsController::class, 'aiPreferences']);
$router->get('/settings/usage',           [UserSettingsController::class, 'usage']);
$router->get('/settings/connections',     [UserSettingsController::class, 'connections']);
$router->get('/settings/creations',       [UserSettingsController::class, 'creations']);
$router->get('/settings/display',         [UserSettingsController::class, 'display']);
// Tâches programmées. `preview` et `trial` (littérales) sont déclarées AVANT
// `/settings/schedules/{id}` : `{id}` capture un segment et les avalerait.
$router->get('/settings/schedules',              [ScheduledTaskController::class, 'index']);
$router->get('/settings/schedules/new',          [ScheduledTaskController::class, 'create']);
$router->post('/settings/schedules',             [ScheduledTaskController::class, 'store']);
$router->post('/settings/schedules/preview',     [ScheduledTaskController::class, 'preview']);
$router->post('/settings/schedules/trial',       [ScheduledTaskController::class, 'trial']);
$router->get('/settings/schedules/trial/{id}',   [ScheduledTaskController::class, 'trialResult']);
$router->get('/settings/schedules/{id}/edit',    [ScheduledTaskController::class, 'edit']);
$router->post('/settings/schedules/{id}',        [ScheduledTaskController::class, 'update']);
$router->post('/settings/schedules/{id}/toggle', [ScheduledTaskController::class, 'toggle']);
$router->post('/settings/schedules/{id}/run',    [ScheduledTaskController::class, 'runNow']);
$router->post('/settings/schedules/{id}/delete', [ScheduledTaskController::class, 'delete']);

// Workflows (créateurs et administrateurs). Routes littérales (`new`,
// `launchable`, `runs/…`) déclarées AVANT `/workflows/{id}…`.
$router->get('/workflows',                         [WorkflowController::class, 'index']);
$router->get('/workflows/new',                     [WorkflowController::class, 'newPage']);
$router->post('/workflows',                        [WorkflowController::class, 'create']);
$router->get('/workflows/launchable',              [WorkflowController::class, 'launchable']);
$router->get('/workflows/history',                 [WorkflowController::class, 'historyList']);
$router->post('/workflows/loop/sample',            [WorkflowController::class, 'loopSample']);
$router->get('/workflows/runs/{id}',               [WorkflowController::class, 'run']);
$router->get('/workflows/runs/{id}/state',         [WorkflowController::class, 'state']);
$router->get('/workflows/runs/{id}/journal',       [WorkflowController::class, 'journal']);
$router->post('/workflows/runs/{id}/gate',         [WorkflowController::class, 'gate']);
$router->post('/workflows/runs/{id}/retry',        [WorkflowController::class, 'retry']);
$router->post('/workflows/runs/{id}/skip',         [WorkflowController::class, 'skip']);
$router->post('/workflows/runs/{id}/restart',      [WorkflowController::class, 'restart']);
$router->post('/workflows/runs/{id}/resume',       [WorkflowController::class, 'resume']);
$router->post('/workflows/runs/{id}/cancel',       [WorkflowController::class, 'cancel']);
$router->post('/workflows/runs/{id}/loops/{key}/retry', [WorkflowController::class, 'retryFailed']);
$router->get('/workflows/runs/{id}/pieces/{key}/download', [WorkflowController::class, 'download']);
$router->post('/workflows/runs/{id}/pieces/{key}', [WorkflowController::class, 'correctPiece']);
$router->get('/workflows/{id}/edit',               [WorkflowController::class, 'edit']);
$router->get('/workflows/{id}/form',               [WorkflowController::class, 'form']);
$router->post('/workflows/{id}/draft',             [WorkflowController::class, 'saveDraft']);
$router->post('/workflows/{id}/publish',           [WorkflowController::class, 'publish']);
$router->post('/workflows/{id}/toggle',            [WorkflowController::class, 'toggle']);
$router->post('/workflows/{id}/delete',            [WorkflowController::class, 'delete']);
$router->post('/workflows/{id}/duplicate',         [WorkflowController::class, 'duplicate']);
$router->post('/workflows/{id}/launch',            [WorkflowController::class, 'launch']);
$router->post('/workflows/{id}/preview',           [WorkflowController::class, 'preview']);
$router->post('/workflows/{id}/hook',              [WorkflowController::class, 'hook']);
// Notifications sur cet appareil (tâches programmées). Littérales AVANT
// `/settings/push/{id}/delete`.
$router->post('/settings/push/subscribe',   [PushController::class, 'subscribe']);
$router->post('/settings/push/unsubscribe', [PushController::class, 'unsubscribe']);
$router->post('/settings/push/test',        [PushController::class, 'test']);
$router->post('/settings/push/{id}/delete', [PushController::class, 'remove']);
$router->get('/settings/drive/connect',   [UserSettingsController::class, 'driveConnect']);
$router->get('/settings/drive/callback',  [UserSettingsController::class, 'driveCallback']);
$router->post('/settings/drive/disconnect', [UserSettingsController::class, 'driveDisconnect']);
$router->get('/settings/gmail/connect',    [UserSettingsController::class, 'gmailConnect']);
$router->get('/settings/gmail/callback',   [UserSettingsController::class, 'gmailCallback']);
$router->post('/settings/gmail/disconnect', [UserSettingsController::class, 'gmailDisconnect']);
$router->get('/settings/onedrive/connect',    [UserSettingsController::class, 'oneDriveConnect']);
$router->get('/settings/onedrive/callback',   [UserSettingsController::class, 'oneDriveCallback']);
$router->post('/settings/onedrive/disconnect', [UserSettingsController::class, 'oneDriveDisconnect']);
$router->get('/settings/calendar/connect',    [UserSettingsController::class, 'calendarConnect']);
$router->get('/settings/calendar/callback',   [UserSettingsController::class, 'calendarCallback']);
$router->post('/settings/calendar/disconnect', [UserSettingsController::class, 'calendarDisconnect']);
$router->get('/settings/tasks/connect',    [UserSettingsController::class, 'tasksConnect']);
$router->get('/settings/tasks/callback',   [UserSettingsController::class, 'tasksCallback']);
$router->post('/settings/tasks/disconnect', [UserSettingsController::class, 'tasksDisconnect']);
$router->post('/settings/tasks/prefs',      [UserSettingsController::class, 'saveTasksPrefs']);
$router->get('/settings/outlook/connect',    [UserSettingsController::class, 'outlookConnect']);
$router->get('/settings/outlook/callback',   [UserSettingsController::class, 'outlookCallback']);
$router->post('/settings/outlook/disconnect', [UserSettingsController::class, 'outlookDisconnect']);
$router->get('/settings/mscalendar/connect',    [UserSettingsController::class, 'mscalendarConnect']);
$router->get('/settings/mscalendar/callback',   [UserSettingsController::class, 'mscalendarCallback']);
$router->post('/settings/mscalendar/disconnect', [UserSettingsController::class, 'mscalendarDisconnect']);
$router->get('/settings/mstodo/connect',    [UserSettingsController::class, 'mstodoConnect']);
$router->get('/settings/mstodo/callback',   [UserSettingsController::class, 'mstodoCallback']);
$router->post('/settings/mstodo/disconnect', [UserSettingsController::class, 'mstodoDisconnect']);
$router->get('/settings/teams/connect',     [UserSettingsController::class, 'teamsConnect']);
$router->get('/settings/teams/callback',    [UserSettingsController::class, 'teamsCallback']);
$router->post('/settings/teams/disconnect', [UserSettingsController::class, 'teamsDisconnect']);
$router->get('/settings/slack/connect',    [UserSettingsController::class, 'slackConnect']);
$router->get('/settings/slack/callback',   [UserSettingsController::class, 'slackCallback']);
$router->post('/settings/slack/disconnect', [UserSettingsController::class, 'slackDisconnect']);
$router->post('/settings/profile',        [UserSettingsController::class, 'updateProfile']);
$router->post('/settings/ai',             [UserSettingsController::class, 'updateAiPreferences']);
$router->post('/settings/display',        [UserSettingsController::class, 'updateDisplayPrefs']);
// Bascule FR/EN du menu de compte. Deux segments littéraux : aucune route
// paramétrée ne peut l'attraper. Volontairement HORS auth — la page de
// connexion s'en sert aussi (cf. updateLang).
$router->post('/settings/lang',           [UserSettingsController::class, 'updateLang']);
// Réglages de voix (vitesse de lecture, longueur des réponses orales) : même
// colonne que la carte des Préférences IA, appelée aussi depuis le bandeau de
// la conversation vocale, dans le chat.
$router->post('/settings/voice',          [UserSettingsController::class, 'updateVoicePrefs']);
$router->post('/settings/memory',         [UserSettingsController::class, 'addMemory']);
$router->post('/settings/memory/{id}',    [UserSettingsController::class, 'updateMemory']);
$router->post('/settings/memory/{id}/delete', [UserSettingsController::class, 'deleteMemory']);
$router->post('/settings/password',       [UserSettingsController::class, 'updatePassword']);
$router->get('/chat/search',              [ChatController::class, 'search']);
// Conversation vocale : annonce d'une action (audio MP3 mis en cache). Deux
// segments après /chat, hors de portée de /chat/{id} — déclarée avant lui
// par prudence, comme /chat/search.
$router->get('/chat/voice/cue',           [ChatController::class, 'voiceCue']);
$router->get('/chat/{id}',                [ChatController::class, 'show']);
$router->post('/chat/new',                [ChatController::class, 'create']);
$router->post('/chat/{id}/send',          [ChatController::class, 'send']);
$router->post('/chat/{id}/regenerate',    [ChatController::class, 'regenerate']);
// Suggestion de suite de la dernière réponse (zone de saisie, Tab) — FollowUpSuggestion.
$router->post('/chat/{id}/suggest',       [ChatController::class, 'suggestFollowUp']);
// Bases de connaissances cochées dans le composer. Deux segments après
// {id}, qui n'en capture qu'un : aucune collision possible.
$router->post('/chat/{id}/knowledge-bases', [ChatController::class, 'updateKnowledgeBases']);
$router->post('/chat/{id}/drive-action',  [ChatController::class, 'driveAction']);
// « Tout approuver » / « Tout refuser » : les cartes en attente d'une
// conversation, emails exclus. Segment distinct, aucune collision.
$router->post('/chat/{id}/drive-actions', [ChatController::class, 'driveActionsBatch']);
// « Version soignée › Par Gamma » sur la carte d'une présentation livrée dans le chat.
$router->post('/chat/{id}/gamma-deck',    [ChatController::class, 'gammaFromFile']);
// « Version soignée » : un modèle compose le fichier lui-même (NativeDeck) —
// fenêtre de confirmation (quel modèle, pour combien, ce qui part ; lecture
// seule), lancement, puis téléchargement (30 jours).
$router->post('/chat/{id}/native-deck/plan', [ChatController::class, 'nativeDeckPlan']);
$router->post('/chat/{id}/native-deck',   [ChatController::class, 'nativeDeckFromFile']);
// « Modifier les slides » : la présentation de la carte devient éditable (volet).
$router->post('/chat/{id}/deck-from-file', [ChatController::class, 'deckFromFile']);
$router->get('/chat/{id}/presentation/{token}', [ChatController::class, 'downloadNativeDeck']);
// Relecture d'un brouillon d'email en attente d'approbation (carte mail) :
// modification à la main ou réécriture par le modèle, l'action reste en
// attente — c'est /drive-action qui la crée. Deux segments après {id}.
$router->post('/chat/{id}/mail-draft/revise', [ChatController::class, 'reviseMailDraft']);
$router->post('/chat/{id}/upload',        [ChatController::class, 'upload']);
$router->post('/chat/realtime-transcription', [ChatController::class, 'realtimeTranscriptionToken']);
$router->post('/chat/realtime-transcription/usage', [ChatController::class, 'realtimeTranscriptionUsage']); // minutes dictées (fin de session)
// Conversation vocale : lecture à voix haute d'une réponse (audio MP3 par partie).
$router->post('/chat/message/{id}/speak', [ChatController::class, 'speakMessage']);
$router->post('/chat/{id}/imagine',          [ChatController::class, 'imagine']);
$router->post('/chat/{id}/generate-html',  [ChatController::class, 'generateHtml']);
$router->post('/chat/{id}/generate-map',   [ChatController::class, 'generateMap']);
$router->post('/chat/{id}/generate-video', [ChatController::class, 'generateVideo']);
// Outil présentation : plan d'abord (éditable), puis construction du deck
$router->post('/chat/{id}/presentation/outline', [ChatController::class, 'presentationOutline']);
$router->post('/chat/{id}/presentation/build',   [ChatController::class, 'presentationBuild']);
$router->get('/chat/message/{id}/deck',    [ChatController::class, 'getMessageDeck']);
$router->post('/chat/message/{id}/deck',   [ChatController::class, 'updateMessageDeck']);
// Insertion d'image dans l'éditeur de présentation : dépôt d'un fichier
// (même traitement que l'éditeur de pages web) ou génération par l'outil image.
$router->post('/chat/message/{id}/deck-image', [ChatController::class, 'uploadHtmlImage']);
$router->post('/chat/message/{id}/deck-image/generate', [ChatController::class, 'generateDeckImage']);
$router->post('/chat/message/{id}/deck/pptx', [ChatController::class, 'exportDeckPptx']);
$router->post('/chat/message/{id}/deck/pdf',  [ChatController::class, 'exportDeckPdf']);
$router->get('/chat/message/{id}/map',     [ChatController::class, 'getMessageMap']);
$router->get('/chat/message/{id}/html',    [ChatController::class, 'getMessageHtml']);
$router->post('/chat/message/{id}/html',   [ChatController::class, 'updateMessageHtml']);
$router->post('/chat/message/{id}/html-image', [ChatController::class, 'uploadHtmlImage']);
$router->get('/chat/message/{id}/video',   [ChatController::class, 'getMessageVideo']);
// Partage de créations par lien unique (utilisateurs connectés uniquement)
$router->post('/chat/message/{id}/share',   [ShareController::class, 'create']);
$router->post('/chat/message/{id}/unshare', [ShareController::class, 'revoke']);
$router->get('/share/{token}',              [ShareController::class, 'show']);
$router->post('/chat/message/{id}/pdf',    [ChatController::class, 'exportMessagePdf']);
$router->post('/chat/message/{id}/docx',   [ChatController::class, 'exportMessageDocx']);
$router->post('/chat/{id}/delete',         [ChatController::class, 'delete']);
$router->post('/chat/{id}/rename',          [ChatController::class, 'rename']);
$router->post('/chat/{id}/pin',             [ChatController::class, 'togglePin']);
$router->get('/chat/{id}/json',            [ChatController::class, 'loadJson']);

// Fichiers livrés dans une conversation (classeur, document, texte) : le
// serveur fabrique le fichier depuis le contenu affiché et le rend aussitôt,
// sans rien stocker. Chemin littéral à deux segments, hors de portée de toute
// route paramétrée.
$router->post('/files/build',             [FileController::class, 'build']);

// Générations asynchrones : attache au flux d'un job (GET → EventSource, donc
// reconnexion et Last-Event-ID gérés par le navigateur).
$router->get('/jobs/active',              [JobController::class, 'active']);
$router->get('/jobs/{id}/stream',         [JobController::class, 'stream']);
$router->post('/jobs/{id}/cancel',        [JobController::class, 'cancel']);
// Conversation vocale : lecture de la réponse PENDANT qu'elle s'écrit.
$router->post('/jobs/{id}/speak',         [ChatController::class, 'speakLive']);

// Profils
$router->get('/profiles',                 [ProfileController::class, 'index']);

// Admin
$router->get('/admin',                    [AdminController::class, 'index']);
$router->get('/admin/users',                       [AdminController::class, 'users']);
$router->post('/admin/users/new',                  [AdminController::class, 'createUser']);
// AVANT /admin/users/{id} : le segment « access-rules » serait sinon avalé
// par {id} et arriverait dans updateUser.
$router->post('/admin/users/access-rules',         [AdminController::class, 'saveUserAccessRules']);
$router->post('/admin/users/{id}',                 [AdminController::class, 'updateUser']);
$router->post('/admin/users/{id}/delete',          [AdminController::class, 'deleteUser']);
$router->get('/admin/users/{id}/delete-impact',    [AdminController::class, 'userDeletionImpact']);
$router->post('/admin/users/{id}/password',        [AdminController::class, 'resetPassword']);
// Utilisateurs → Rôles (administrateurs seuls). Routes littérales avant {slug}.
$router->get('/admin/roles',                       [RoleController::class, 'index']);
$router->post('/admin/roles',                      [RoleController::class, 'create']);
$router->post('/admin/roles/{slug}/duplicate',     [RoleController::class, 'duplicate']);
$router->post('/admin/roles/{slug}/reset',         [RoleController::class, 'reset']);
$router->post('/admin/roles/{slug}/delete',        [RoleController::class, 'delete']);
$router->post('/admin/roles/{slug}',               [RoleController::class, 'update']);
$router->get('/admin/user-groups',                 [AdminController::class, 'userGroups']);
$router->post('/admin/user-groups',                [AdminController::class, 'createUserGroup']);
$router->post('/admin/user-groups/{id}',           [AdminController::class, 'updateUserGroup']);
$router->post('/admin/user-groups/{id}/delete',    [AdminController::class, 'deleteUserGroup']);
$router->get('/admin/profiles',           [AdminController::class, 'profiles']);
$router->get('/admin/profiles/new',       [AdminController::class, 'newProfile']);
$router->get('/admin/profiles/{id}/edit', [AdminController::class, 'editProfile']);
$router->post('/admin/profiles',          [AdminController::class, 'createProfile']);
// Littérale à DEUX segments après /profiles : `{id}` n'en capture qu'un, mais
// elle reste déclarée avant les routes paramétrées, par lisibilité (testé).
$router->post('/admin/profiles/prompt/generate', [AdminController::class, 'generateProfilePrompt']);
$router->post('/admin/profiles/avatar/upload', [AdminController::class, 'uploadProfileAvatar']);
$router->post('/admin/profiles/avatar/rename', [AdminController::class, 'renameProfileAvatar']);
$router->post('/admin/profiles/avatar/delete', [AdminController::class, 'deleteProfileAvatar']);
$router->post('/admin/profiles/{id}',     [AdminController::class, 'updateProfile']);
$router->post('/admin/profiles/{id}/delete', [AdminController::class, 'deleteProfile']);
$router->post('/admin/profiles/{id}/toggle-personal', [AdminController::class, 'togglePersonal']);
$router->post('/admin/profiles/{id}/documents',              [AdminController::class, 'uploadProfileDocument']);
$router->post('/admin/profiles/{id}/documents/{docId}/delete', [AdminController::class, 'deleteProfileDocument']);
$router->get('/admin/profiles/{id}/documents/{docId}/status',  [AdminController::class, 'profileDocumentStatus']);
// Bases de connaissances (RAG vectoriel partagé) — admins + creators
$router->get('/admin/knowledge-bases',                  [AdminController::class, 'knowledgeBases']);
// État en direct de toutes les bases (cartes rafraîchies sans recharger) —
// littérale, déclarée avant toute route /admin/knowledge-bases/{id}.
$router->get('/admin/knowledge-bases/status',           [AdminController::class, 'knowledgeBasesStatus']);
// Fiche d'une base en page pleine (création, modification) et, dans sa section
// « Avancé », le détail de ses extraits vectorisés.
$router->get('/admin/knowledge-bases/new',              [AdminController::class, 'newKnowledgeBase']);
$router->get('/admin/knowledge-bases/{id}/edit',        [AdminController::class, 'editKnowledgeBase']);
$router->get('/admin/knowledge-bases/{id}/chunks',      [AdminController::class, 'knowledgeBaseChunks']);
$router->post('/admin/knowledge-bases',                 [AdminController::class, 'createKnowledgeBase']);
$router->post('/admin/knowledge-bases/{id}',            [AdminController::class, 'updateKnowledgeBase']);
$router->post('/admin/knowledge-bases/{id}/delete',     [AdminController::class, 'deleteKnowledgeBase']);
$router->post('/admin/knowledge-bases/{id}/reindex',    [AdminController::class, 'reindexKnowledgeBase']);
$router->post('/admin/knowledge-bases/{id}/documents',  [AdminController::class, 'uploadKnowledgeBaseDocument']);
$router->post('/admin/knowledge-bases/{id}/documents/{docId}/delete', [AdminController::class, 'deleteKnowledgeBaseDocument']);
$router->get('/admin/knowledge-bases/{id}/documents/{docId}/status',  [AdminController::class, 'knowledgeBaseDocumentStatus']);
// Base vectorielle (santé Qdrant) — admins seuls
$router->get('/admin/knowledge-bases/vector',           [AdminController::class, 'vectorDatabase']);
$router->get('/admin/knowledge-bases/qdrant/status',    [AdminController::class, 'qdrantStatus']);
$router->post('/admin/knowledge-bases/qdrant/repair',   [AdminController::class, 'repairQdrant']);
$router->post('/admin/knowledge-bases/qdrant/prune',    [AdminController::class, 'pruneQdrant']);
$router->post('/admin/knowledge-bases/{id}/reindex-async', [AdminController::class, 'reindexKnowledgeBaseAjax']);
// Sources synchronisées : identifiants globaux des fournisseurs (admins seuls).
// Chemins à DEUX segments après « sync » : `{id}` ne capture qu'un segment,
// aucune collision avec POST /admin/knowledge-bases/{id} déclaré plus haut.
$router->get('/admin/knowledge-bases/sync',             [AdminController::class, 'knowledgeBaseSyncSettings']);
$router->post('/admin/knowledge-bases/sync/settings',   [AdminController::class, 'saveKnowledgeBaseSyncSettings']);
$router->post('/admin/knowledge-bases/sync/test-credentials', [AdminController::class, 'testSyncConnection']);
// Synchronisation d'un dossier distant (Google Drive / OneDrive / SharePoint)
$router->post('/admin/knowledge-bases/sync/test',       [AdminController::class, 'testKnowledgeBaseSyncFolder']);
// Explorateur de dossiers (connexion personnelle Google Drive / OneDrive)
$router->post('/admin/knowledge-bases/sync/browse',     [AdminController::class, 'browseSyncFolder']);
// Base en direct (MyBrain) : test de connexion — et enregistrement de la
// connexion d'une base existante quand il passe. Deux segments : aucune
// collision avec les routes /admin/knowledge-bases/{id}/….
$router->post('/admin/knowledge-bases/mybrain/test',    [AdminController::class, 'testMyBrainConnection']);
$router->post('/admin/knowledge-bases/{id}/sync',       [AdminController::class, 'runKnowledgeBaseSync']);
$router->get('/admin/knowledge-bases/{id}/sync-status', [AdminController::class, 'knowledgeBaseSyncStatus']);
// Gestion LLMs — Clés API, Modèles (activés + comportement par défaut), Tests
$router->get('/admin/llms',               [AdminController::class, 'llmsKeys']);
$router->get('/admin/llms/models',        [AdminController::class, 'llmsModels']);
$router->get('/admin/llms/tests',         [AdminController::class, 'llmsTests']);
$router->post('/admin/llms/defaults',     [AdminController::class, 'saveLlmDefaults']);
$router->post('/admin/api-keys',          [AdminController::class, 'saveApiKey']);
$router->post('/admin/api-keys/{id}/delete', [AdminController::class, 'deleteApiKey']);
$router->post('/admin/api-keys/{id}/test',   [AdminController::class, 'testApiKey']);
$router->post('/admin/llm-models',           [AdminController::class, 'saveLlmModel']);
$router->post('/admin/llm-models/{id}/delete', [AdminController::class, 'deleteLlmModel']);
$router->post('/admin/tests/run',         [AdminController::class, 'runTest']);
// Anciennes pages fusionnées puis re-découpées — redirection permanente
$router->get('/admin/api-keys', function (): void { header('Location: /admin/llms', true, 301); exit; });
$router->get('/admin/tests',    function (): void { header('Location: /admin/llms/tests', true, 301); exit; });
// « Comportement par défaut » fusionné dans la page Modèles (carte #defaut) ; le POST reste.
$router->get('/admin/llms/defaults', function (): void { header('Location: /admin/llms/models#defaut', true, 301); exit; });
// Coûts IA → Budget : plafond mensuel en euros (enregistrement manuel, cf. BudgetController).
$router->get('/admin/budget',               [BudgetController::class, 'index']);
$router->post('/admin/budget/settings',     [BudgetController::class, 'saveSettings']);
$router->post('/admin/budget/extra',        [BudgetController::class, 'grantExtra']);
$router->post('/admin/budget/extra/delete', [BudgetController::class, 'removeExtra']);
$router->get('/admin/usage',              [AdminController::class, 'usage']);
$router->get('/admin/usage/export-csv',   [AdminController::class, 'usageExportCsv']);
$router->get('/admin/usage/export-pdf',   [AdminController::class, 'usageExportPdf']);
$router->post('/admin/usage/backfill-tools', [AdminController::class, 'usageBackfillTools']);
$router->get('/admin/credits',            [AdminController::class, 'credits']);
$router->post('/admin/credits',           [AdminController::class, 'saveCredits']);
$router->post('/admin/credits/refresh-prices', [AdminController::class, 'refreshCreditsPrices']);
$router->get('/admin/diagnostics',        [HealthController::class, 'diagnostics']);
$router->get('/admin/agent',              [AgentStatsController::class, 'index']);
$router->get('/admin/logs',               [LogsController::class, 'index']);
$router->get('/admin/logs/feed',          [LogsController::class, 'feed']);
// Entreprise (qui utilise MIA) et Marque & apparence (l'application) : deux
// écrans, deux formulaires — chacun n'écrit que ses propres réglages.
$router->get('/admin/company',            [AdminController::class, 'company']);
$router->post('/admin/company',           [AdminController::class, 'saveCompany']);
// Aperçu de ce que lit l'assistant, documents d'essai, miniatures des modèles.
$router->post('/admin/company/preview',          [AdminController::class, 'previewCompany']);
$router->get('/admin/company/sample/{kind}',     [AdminController::class, 'companySample']);
$router->get('/admin/company/thumbnail/{kind}',  [AdminController::class, 'companyThumbnail']);
$router->get('/admin/settings',           [AdminController::class, 'settings']);
$router->post('/admin/settings',          [AdminController::class, 'saveSettings']);
$router->get('/admin/connectors',         [AdminController::class, 'connectors']);
$router->post('/admin/connectors',        [AdminController::class, 'saveConnectors']);
// Identifiants de l'application Slack : écran dédié (Slack n'est pas un moyen
// de connexion, ses identifiants n'ont rien à faire dans Configuration →
// Authentification). Aucune route paramétrée ne capte ces chemins.
$router->get('/admin/connectors/slack',          [AdminController::class, 'slackApp']);
$router->post('/admin/connectors/slack/settings', [AdminController::class, 'saveSlackApp']);
$router->post('/admin/connectors/slack/test',     [AdminController::class, 'testSlackApp']);
// Authentification (SSO + mot de passe). Chemins à DEUX segments après « sso » :
// aucune route paramétrée ne les capte.
$router->get('/admin/sso',                   [AdminController::class, 'sso']);
$router->post('/admin/sso/settings',         [AdminController::class, 'saveSso']);
$router->post('/admin/sso/test-credentials', [AdminController::class, 'testSsoCredentials']);
// Élagage des conversations et créations anciennes. Chemins littéraux à deux
// segments après « pruning » : aucune route paramétrée ne les capte.
$router->get('/admin/pruning',                [PruningController::class, 'index']);
$router->post('/admin/pruning/settings',      [PruningController::class, 'saveSettings']);
$router->post('/admin/pruning/conversations', [PruningController::class, 'pruneConversations']);
$router->post('/admin/pruning/creations',     [PruningController::class, 'pruneCreations']);
$router->post('/admin/pruning/orphans',       [PruningController::class, 'sweepOrphans']);
$router->get('/admin/backup',             [BackupController::class, 'index']);
$router->post('/admin/backup/export',     [BackupController::class, 'export']);
$router->post('/admin/backup/import',     [BackupController::class, 'import']);
$router->get('/admin/tools',              [AdminController::class, 'tools']);
$router->get('/admin/tools/tests/latest', [AdminController::class, 'latestToolsTestRun']);
$router->get('/admin/tools/tests/{id}',   [AdminController::class, 'toolsTestRunStatus']);
$router->post('/admin/tools/tests/start', [AdminController::class, 'startToolsTestRun']);
$router->post('/admin/tools/builtin',     [AdminController::class, 'saveBuiltinTools']);
// AVANT /admin/tools/{id} : le segment « triggers » serait sinon avalé par {id}.
$router->post('/admin/tools/triggers',    [AdminController::class, 'saveToolTriggers']);
$router->post('/admin/tools',             [AdminController::class, 'createTool']);
$router->post('/admin/tools/{id}',        [AdminController::class, 'updateTool']);
$router->post('/admin/tools/{id}/test',   [AdminController::class, 'testTool']);
$router->post('/admin/tools/{id}/toggle', [AdminController::class, 'toggleTool']);
$router->post('/admin/tools/{id}/delete', [AdminController::class, 'deleteTool']);

$router->dispatch($requestMethod, $requestUri);
