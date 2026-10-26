<?php

declare(strict_types=1);

class AuthMiddleware
{
    /** Le compte de la session a été relu en base pendant cette requête. */
    private static bool $synced = false;

    public static function requireAuth(): void
    {
        if (!Session::isAuthenticated()) {
            self::denyUnauthenticated();
        }

        self::syncSessionUser();

        $user = Session::currentUser();
        if (!$user || !$user['is_active']) {
            Session::destroy();
            self::denyUnauthenticated('inactive');
        }
    }

    /**
     * Relit le compte en base, une fois par requête : un rôle changé, des
     * droits retirés ou un compte désactivé valent dès la requête suivante —
     * la session gardait sinon le rôle du jour de la connexion sur toutes
     * les pages qui ne le relisaient pas (l'administration entière). Compte
     * supprimé : déconnexion. Base injoignable : la session telle quelle, la
     * page échouera plus loin, proprement.
     */
    private static function syncSessionUser(): void
    {
        if (self::$synced) {
            return;
        }
        self::$synced = true;
        $id = (int)(Session::currentUser()['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        try {
            $fresh = User::findById($id);
        } catch (\Throwable $e) {
            return;
        }
        if ($fresh === null) {
            Session::destroy();
            self::denyUnauthenticated('inactive');
        }
        Session::set('user', $fresh);
    }

    /**
     * Réservé au rôle Administrateur : ce qui ne se délègue pas (rôles,
     * données). Le reste de l'administration passe par requirePermission().
     */
    public static function requireAdmin(): void
    {
        self::requireAuth();
        if (!Session::isAdmin()) {
            self::deny403('Accès réservé aux administrateurs.');
        }
    }

    /**
     * Exige un droit du rôle (« users.view », « assistants.edit »…), sinon
     * 403. Le serveur reste seul juge : masquer un bouton n'est qu'un confort.
     */
    public static function requirePermission(string $permission): void
    {
        self::requireAuth();
        if (!Session::can($permission)) {
            self::deny403(self::refusal());
        }
    }

    /** Exige au moins un de ces droits, sinon 403. */
    public static function requireAnyPermission(string ...$permissions): void
    {
        self::requireAuth();
        if (!Session::canAny(...$permissions)) {
            self::deny403(self::refusal());
        }
    }

    /** Exige TOUS ces droits, sinon 403 (« toutes les bases » ET « modifier »). */
    public static function requireAllPermissions(string ...$permissions): void
    {
        self::requireAuth();
        foreach ($permissions as $permission) {
            if (!Session::can($permission)) {
                self::deny403(self::refusal());
            }
        }
    }

    /** Message d'un refus de droit (rendu par la page 403 ou en JSON). */
    public static function refusal(): string
    {
        return t('Votre rôle ne vous donne pas accès à cette action. Un administrateur peut l’ouvrir dans Personnes → Rôles.');
    }

    /**
     * Exige que l'utilisateur porte l'un des rôles fournis (sinon 403).
     */
    public static function requireRole(array $roles): void
    {
        self::requireAuth();
        if (!Session::hasRole(...$roles)) {
            self::deny403('Accès refusé : permissions insuffisantes.');
        }
    }

    private static function deny403(string $message): void
    {
        if (self::wantsJson()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => $message]);
            exit;
        }
        http_response_code(403);
        require ROOT . '/templates/403.php';
        exit;
    }

    /**
     * Détecte si la requête attend une réponse JSON (XHR/fetch) plutôt qu'une
     * page HTML. Évite de renvoyer un redirect 302 HTML dans un flux SSE ou
     * dans une réponse fetch — ce qui provoquait un message d'erreur
     * « Erreur de connexion » bloquant et sans issue côté client.
     */
    private static function wantsJson(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json') || str_contains($accept, 'text/event-stream')) {
            return true;
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return true;
        }
        // fetch() moderne : envoie toujours Sec-Fetch-Mode: cors | same-origin
        $fetchMode = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        if ($fetchMode === 'cors') {
            return true;
        }
        // Heuristique dernier recours : présence d'un X-CSRF-Token (tous nos POST AJAX l'envoient)
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return true;
        }
        return false;
    }

    private static function denyUnauthenticated(string $reason = ''): void
    {
        $loginUrl = '/auth/login' . ($reason !== '' ? '?error=' . rawurlencode($reason) : '');

        if (self::wantsJson()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'error'         => 'session_expired',
                'message'       => 'Votre session a expiré. Veuillez vous reconnecter.',
                'login_url'     => $loginUrl,
                'authenticated' => false,
            ]);
            exit;
        }

        header('Location: ' . $loginUrl);
        exit;
    }

    public static function refreshUser(): void
    {
        if (Session::isAuthenticated()) {
            $user = User::findById(Session::currentUser()['id']);
            if ($user) {
                Session::set('user', $user);
            }
        }
    }
}
