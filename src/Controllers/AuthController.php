<?php

declare(strict_types=1);

require_once ROOT . '/src/Auth/GoogleOAuth.php';

class AuthController
{
    public function login(): void
    {
        if (Session::isAuthenticated()) {
            header('Location: /');
            exit;
        }

        // Jamais de mise en cache : la page porte un jeton CSRF lié à la
        // session courante. Une copie servie depuis le cache (WebView de l'app
        // mobile, proxy) rejouerait un jeton mort → POST rejeté en 403.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $error = $_GET['error'] ?? null;
        // Le gabarit ne décide plus seul de ce qu'il affiche : les moyens de
        // connexion viennent du registre (Administration → Organisation →
        // Authentification).
        $passwordEnabled = SsoRegistry::passwordEnabled();
        require ROOT . '/templates/auth/login.php';
    }

    /**
     * GET /auth/csrf — jeton CSRF frais (JSON).
     *
     * Filet pour les pages restées ouvertes pendant une expiration ou un
     * redémarrage de session (app mobile en arrière-plan, redéploiement du
     * conteneur qui efface les sessions PHP) : le front récupère un jeton neuf
     * et rejoue sa requête au lieu d'échouer en 403 sans issue.
     *
     * Aucun secret exposé : sans en-tête CORS, seule une page de MÊME origine
     * peut lire la réponse, et le jeton n'est valable que pour la session du
     * demandeur.
     */
    public function csrf(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['token' => Csrf::token()]);
    }

    public function callback(): void
    {
        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';

        // Retour OAuth du connecteur Google Drive personnel : le flux Drive
        // réutilise l'URI de redirection du login (seule URI garantie déclarée
        // dans la console Google) avec un state préfixé `drive_` — renvoyé par
        // Google aussi bien sur succès que sur refus (?error=). On délègue au
        // contrôleur des connexions.
        if (str_starts_with((string)$state, 'drive_')) {
            (new UserSettingsController())->driveCallback();
            return;
        }
        // Même mécanique pour le connecteur Gmail (state préfixé gmail_).
        if (str_starts_with((string)$state, 'gmail_')) {
            (new UserSettingsController())->gmailCallback();
            return;
        }
        // Même mécanique pour le connecteur Google Agenda (state préfixé gcal_).
        if (str_starts_with((string)$state, 'gcal_')) {
            (new UserSettingsController())->calendarCallback();
            return;
        }
        // Même mécanique pour le connecteur Google Tasks (state préfixé gtasks_).
        if (str_starts_with((string)$state, 'gtasks_')) {
            (new UserSettingsController())->tasksCallback();
            return;
        }
        // Connecteur Outlook : même principe, côté Microsoft. Il réutilise
        // l'application Azure ET l'URI de redirection du LOGIN Microsoft (la
        // seule déjà déclarée dans le portail), d'où le state préfixé
        // `outlook_` — testé AVANT le SSO, sinon un retour de connecteur
        // partirait dans le flux de connexion.
        if (str_starts_with((string)$state, 'outlook_')) {
            (new UserSettingsController())->outlookCallback();
            return;
        }
        // Calendrier Outlook (state préfixé mscal_) : même application Azure et
        // même URI que le login, consentement DISTINCT de celui d'Outlook.
        if (str_starts_with((string)$state, 'mscal_')) {
            (new UserSettingsController())->mscalendarCallback();
            return;
        }
        // Microsoft To Do (state préfixé mstodo_) : même application Azure et
        // même URI que le login, consentement DISTINCT des deux autres.
        if (str_starts_with((string)$state, 'mstodo_')) {
            (new UserSettingsController())->mstodoCallback();
            return;
        }
        // Microsoft Teams (state préfixé msteams_) : même application Azure et
        // même URI que le login, consentement DISTINCT (administrateur requis).
        if (str_starts_with((string)$state, 'msteams_')) {
            (new UserSettingsController())->teamsCallback();
            return;
        }
        // OneDrive (state préfixé onedrive_) : sans inscription dédiée, il
        // retombe sur l'application du LOGIN Microsoft et revient donc ici.
        // Avec son inscription dédiée, il revient sur /settings/onedrive/callback.
        if (str_starts_with((string)$state, MicrosoftOAuth::STATE_PREFIX)) {
            (new UserSettingsController())->oneDriveCallback();
            return;
        }
        // Connecteur Slack (state préfixé slack_). Slack n'est pas un moyen de
        // connexion à MIA : son application a ses propres identifiants, mais
        // elle déclare la MÊME URL de retour que le login — c'est le seul point
        // d'entrée OAuth de l'application, et le préfixe suffit à trancher.
        if (str_starts_with((string)$state, 'slack_')) {
            (new UserSettingsController())->slackCallback();
            return;
        }

        // REFUS DU FOURNISSEUR : il revient par `error` + `error_description`,
        // sans `code` — donc il tombait jusqu'ici et la page de connexion
        // affichait « Requête invalide », qui ne désigne rien. Or chez Entra
        // c'est le cas le PLUS fréquent en entreprise, et la description porte
        // un code AADSTS qui nomme la vraie cause : consentement interdit aux
        // utilisateurs par l'annuaire (65001/90094), compte non attribué à
        // l'application d'entreprise (50105), refus sur l'écran de
        // consentement (65004). Rien de tout cela ne se règle du côté de MIA.
        //
        // Le message détaillé ne voyage PAS dans l'URL — il serait ré-affiché
        // tel quel, donc contrôlé par le fournisseur : il part dans les logs,
        // la page de connexion ne reçoit qu'un code d'erreur stable qui
        // désigne le bon interlocuteur.
        $refus = (string)($_GET['error'] ?? '');
        if ($refus !== '') {
            $desc = (string)($_GET['error_description'] ?? '');
            error_log('[AUTH] Connexion refusée par le fournisseur : ' . self::pourLog($refus)
                . ' — ' . MicrosoftSsoProvider::aadstsCode($desc));
            header('Location: /auth/login?error='
                . (MicrosoftOAuth::isConsentOrAssignmentError($refus . ' ' . $desc)
                    ? 'consent_required'
                    : 'oauth_denied'));
            exit;
        }

        if (!$code || !$state) {
            header('Location: /auth/login?error=invalid');
            exit;
        }

        // Un state SANS préfixe connu retombe sur Google : au moment du
        // déploiement, une connexion ouverte juste avant porte encore l'ancien
        // format nu et doit aboutir plutôt qu'échouer sous les yeux de
        // l'utilisateur.
        $cle      = SsoRegistry::providerFromState((string)$state);
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $provider = $cle !== null ? SsoRegistry::provider($cle) : null;

        if ($provider === null) {
            header('Location: /auth/login?error=invalid');
            exit;
        }
        if (!SsoRegistry::isUsable($cle)) {
            // Le fournisseur a été désactivé pendant que l'utilisateur était
            // sur l'écran de consentement.
            header('Location: /auth/login?error=provider_disabled');
            exit;
        }
        if (LoginRateLimit::depasse(LoginRateLimit::SSO, $ip)) {
            header('Location: /auth/login?error=too_many_attempts');
            exit;
        }

        $identite = $provider->handleCallback((string)$code, (string)$state);

        if ($identite === null) {
            LoginRateLimit::echec(LoginRateLimit::SSO, $ip);
            error_log('[AUTH] Échec OAuth ' . SsoRegistry::label($cle) . ' depuis ' . $ip);
            header('Location: /auth/login?error=oauth_failed');
            exit;
        }

        LoginRateLimit::reussite(LoginRateLimit::SSO, $ip);

        $err = $this->openSsoSession($identite);
        if ($err !== null) {
            header('Location: /auth/login?error=' . $err);
            exit;
        }
        header('Location: /');
        exit;
    }

    /**
     * Valeur venue du fournisseur, rendue sûre pour une ligne de log : sans
     * cela un `error` porteur de retours à la ligne fabriquerait de fausses
     * entrées dans le journal.
     */
    private static function pourLog(string $valeur): string
    {
        return mb_substr((string)preg_replace('/[\r\n\x00]+/', ' ', $valeur), 0, 120);
    }

    /**
     * Ouvre la session applicative pour une identité vérifiée (OAuth web de
     * n'importe quel fournisseur, OU idToken natif de l'app mobile). Retourne
     * null si OK, sinon un code d'erreur repris par la page de connexion.
     *
     * @param array{provider:string,subject:string,email:string,email_verified:bool,name:string,picture:string} $identite
     */
    private function openSsoSession(array $identite): ?string
    {
        $provider = (string)$identite['provider'];
        $subject  = (string)$identite['subject'];
        $email    = strtolower(trim((string)$identite['email']));

        $existant = UserIdentity::findUser($provider, $subject);

        // Compte inconnu de ce fournisseur : peut-on le rattacher à un compte
        // existant, ou en créer un ? Les deux passent par l'adresse e-mail, et
        // c'est là qu'est le risque.
        if ($existant === null) {
            if ($email === '') {
                error_log('[AUTH] ' . $provider . ' : aucune adresse e-mail dans le profil');
                return 'no_email';
            }

            // Restriction de domaine : elle ne s'applique qu'à l'arrivée d'un
            // NOUVEAU compte. Un compte déjà rattaché continue de se connecter
            // même si le réglage change ensuite — sinon modifier la liste
            // éjecterait des utilisateurs en place, dont peut-être le dernier
            // administrateur.
            if (!SsoRegistry::emailDomainAllowed($email)) {
                error_log('[AUTH] Domaine refusé pour ' . $email . ' (' . $provider . ')');
                return 'domain_denied';
            }

            $parEmail = User::findByEmailAnyType($email);
            if ($parEmail !== null) {
                // RAPPROCHEMENT : on s'apprête à donner accès à un compte que
                // cette connexion n'a pas créé. Il faut donc que le fournisseur
                // ATTESTE l'adresse. Sans cela, l'administrateur d'un annuaire
                // tiers pourrait y inscrire l'adresse d'un de nos utilisateurs
                // et récupérer son compte. Un domaine explicitement déclaré par
                // l'administration vaut également attestation : il a été
                // reconnu comme nôtre.
                $atteste = !empty($identite['email_verified'])
                    || SsoRegistry::allowedDomains() !== [];
                if (!$atteste) {
                    error_log('[AUTH] Rapprochement refusé (adresse non attestée) : ' . $email . ' via ' . $provider);
                    return 'email_taken';
                }
                $existant = $parEmail;
            }
        }

        // Photo de profil : on télécharge la photo du fournisseur dans notre
        // volume persistant plutôt que de stocker une URL CDN (qui expire après
        // quelques jours → la photo « disparaît »). Un upload manuel existant
        // est préservé.
        $avatar = AvatarStorage::resolveForSsoUser(
            $existant['avatar_url'] ?? null,
            $provider,
            $subject,
            (string)($identite['picture'] ?? '')
        );
        $nom = trim((string)$identite['name']) !== '' ? (string)$identite['name'] : $email;

        if ($existant === null) {
            $user = User::createFromSso($email, $nom, $avatar);
        } else {
            User::touchLogin((int)$existant['id'], $nom, $avatar);
            $user = User::findById((int)$existant['id']);
        }

        if ($user === null) {
            error_log('[AUTH] Compte introuvable après provisionnement : ' . $email);
            return 'oauth_failed';
        }

        UserIdentity::link((int)$user['id'], $identite);

        if (!$user['is_active']) {
            error_log('[AUTH] Connexion refusée (compte inactif) : ' . $email);
            return 'inactive';
        }

        session_regenerate_id(true);
        Session::set('user', $user);
        // Langue choisie sur l'écran de connexion : elle n'y vit qu'en session,
        // faute de compte. On l'adopte maintenant, sauf si le compte en a déjà une.
        I18n::adoptSessionChoice($user);
        error_log('[AUTH] Connexion ' . SsoRegistry::label($provider) . ' : ' . ($user['email'] ?? '')
            . ' (' . ($user['name'] ?? '') . ')');
        return null;
    }

    /**
     * POST /auth/google-token — connexion Google native (app mobile).
     * La page de connexion étant servie dans la WebView, la requête porte le
     * token CSRF standard (header X-CSRF-Token) : pas d'exemption.
     * Body JSON : {"id_token": "..."} (fourni par le plugin Google Sign-In).
     */
    public function googleTokenLogin(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (LoginRateLimit::depasse(LoginRateLimit::NATIF, $ip)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'too_many_attempts']);
            return;
        }

        if (!SsoRegistry::isUsable(SsoRegistry::GOOGLE)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'provider_disabled']);
            return;
        }

        $body    = json_decode(file_get_contents('php://input') ?: '', true);
        $idToken = is_array($body) ? (string)($body['id_token'] ?? '') : '';

        $googleUser = $idToken !== '' ? GoogleOAuth::verifyIdToken($idToken) : null;
        if (!$googleUser) {
            $tentatives = LoginRateLimit::echec(LoginRateLimit::NATIF, $ip);
            error_log('[AUTH] Échec connexion Google native depuis ' . $ip
                . ' (' . $tentatives . '/' . LoginRateLimit::MAX_TENTATIVES . ')');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'oauth_failed']);
            return;
        }

        // verifyIdToken() a déjà exigé `email_verified` (validateIdTokenClaims) :
        // l'adresse est attestée par Google, le rapprochement est légitime.
        $err = $this->openSsoSession([
            'provider'       => SsoRegistry::GOOGLE,
            'subject'        => (string)$googleUser['id'],
            'email'          => (string)($googleUser['email'] ?? ''),
            'email_verified' => true,
            'name'           => (string)($googleUser['name'] ?? ''),
            'picture'        => (string)($googleUser['picture'] ?? ''),
        ]);
        if ($err !== null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $err]);
            return;
        }

        LoginRateLimit::reussite(LoginRateLimit::NATIF, $ip);
        echo json_encode(['ok' => true]);
    }

    public function localLogin(): void
    {
        $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $email  = trim($_POST['email'] ?? '');

        // Si un email est fourni → authentification email/mot de passe
        if ($email !== '') {
            $this->emailLogin($email, $_POST['password'] ?? '', $ip);
            return;
        }

        // Sinon → authentification admin locale (mot de passe global).
        //
        // ACCÈS DE SECOURS : volontairement indépendant du réglage « connexion
        // par mot de passe » de l'administration. C'est le recours quand un
        // fournisseur SSO est cassé ou mal configuré — le désactiver depuis
        // l'écran d'administration reviendrait à supprimer le filet.
        if (LoginRateLimit::depasse(LoginRateLimit::ADMIN_LOCAL, $ip)) {
            header('Location: /auth/login?error=too_many_attempts');
            exit;
        }

        $password = $_POST['password'] ?? '';

        if (!LOCAL_PASSWORD_HASH || !password_verify($password, LOCAL_PASSWORD_HASH)) {
            $tentatives = LoginRateLimit::echec(LoginRateLimit::ADMIN_LOCAL, $ip);
            error_log('[AUTH] Tentative de connexion locale échouée depuis ' . $ip
                . ' (' . $tentatives . '/' . LoginRateLimit::MAX_TENTATIVES . ')');
            header('Location: /auth/login?error=invalid_password');
            exit;
        }

        LoginRateLimit::reussite(LoginRateLimit::ADMIN_LOCAL, $ip);

        $db   = Database::getInstance();
        $user = User::findByGoogleId('local_admin');

        if (!$user) {
            $db->prepare(
                "INSERT INTO users (google_id, email, name, avatar_url, role, is_active) VALUES ('local_admin', 'admin@local', 'Admin local', '', 'admin', 1)"
            )->execute();
            $user = User::findByGoogleId('local_admin');
        }

        session_regenerate_id(true);
        Session::set('user', $user);
        // Langue choisie sur l'écran de connexion : elle n'y vit qu'en session,
        // faute de compte. On l'adopte maintenant, sauf si le compte en a déjà une.
        I18n::adoptSessionChoice($user);
        error_log('[AUTH] Connexion locale admin depuis ' . $ip);
        header('Location: /');
        exit;
    }

    private function emailLogin(string $email, string $password, string $ip): void
    {
        // Le serveur reste juge : masquer le formulaire ne suffit pas, un POST
        // forgé doit être refusé aussi.
        if (!SsoRegistry::passwordEnabled()) {
            error_log('[AUTH] Connexion par mot de passe désactivée — tentative depuis ' . $ip);
            header('Location: /auth/login?error=provider_disabled');
            exit;
        }

        if (LoginRateLimit::depasse(LoginRateLimit::EMAIL, $ip)) {
            header('Location: /auth/login?error=too_many_attempts');
            exit;
        }

        $user = User::findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            $tentatives = LoginRateLimit::echec(LoginRateLimit::EMAIL, $ip);
            error_log('[AUTH] Échec email/password pour ' . $email . ' depuis ' . $ip
                . ' (' . $tentatives . '/' . LoginRateLimit::MAX_TENTATIVES . ')');
            header('Location: /auth/login?error=invalid_credentials');
            exit;
        }

        if (!$user['is_active']) {
            header('Location: /auth/login?error=inactive');
            exit;
        }

        LoginRateLimit::reussite(LoginRateLimit::EMAIL, $ip);
        session_regenerate_id(true);
        Session::set('user', $user);
        // Langue choisie sur l'écran de connexion : elle n'y vit qu'en session,
        // faute de compte. On l'adopte maintenant, sauf si le compte en a déjà une.
        I18n::adoptSessionChoice($user);
        error_log('[AUTH] Connexion email : ' . $email . ' depuis ' . $ip);
        header('Location: /');
        exit;
    }

    public function logout(): void
    {
        $user = Session::currentUser();
        error_log('[AUTH] Déconnexion : ' . ($user['email'] ?? 'unknown'));
        Session::destroy();
        header('Location: /auth/login');
        exit;
    }

    public function sessionStatus(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $loginUrl = '/auth/login';
        if (!Session::isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['authenticated' => false, 'login_url' => $loginUrl]);
            return;
        }

        $user = Session::currentUser();
        $freshUser = isset($user['id']) ? User::findById((int)$user['id']) : null;
        if (!$freshUser || empty($freshUser['is_active'])) {
            Session::destroy();
            http_response_code(401);
            echo json_encode(['authenticated' => false, 'login_url' => $loginUrl . '?error=inactive']);
            return;
        }

        Session::set('user', $freshUser);
        echo json_encode(['authenticated' => true]);
    }
}
