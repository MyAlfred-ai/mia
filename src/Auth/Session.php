<?php

declare(strict_types=1);

class Session
{
    /**
     * La requête arrive-t-elle en HTTPS ? Détermine le drapeau « Secure » du
     * cookie de session.
     *
     * Un reverse-proxy qui termine le TLS (nginx, Caddy, Traefik) parle en clair
     * au conteneur : `$_SERVER['HTTPS']` est absent et le cookie partait donc
     * sans « Secure », alors que la connexion de l'utilisateur, elle, est
     * chiffrée. On accepte X-Forwarded-Proto — mais UNIQUEMENT si
     * `TRUSTED_PROXY=1` : cet en-tête est trivialement falsifiable par
     * n'importe quel client tant qu'aucun proxy de confiance ne le réécrit.
     */
    private static function requeteChiffree(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        if ((string) getenv('TRUSTED_PROXY') !== '1') {
            return false;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Durée de vie serveur alignée sur le cookie (7 jours). Sans ce réglage,
        // gc_maxlifetime par défaut (1440 s) détruit la session serveur en ~24 min
        // alors que le cookie promet 7 jours — déconnexions intempestives, surtout
        // depuis l'app mobile revenant d'arrière-plan. Aussi posé dans php-env.ini
        // (Docker) ; l'ini_set couvre le dev hors conteneur.
        ini_set('session.gc_maxlifetime', (string)(86400 * 7));

        $sessionName = 'myLLM_session';
        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'secure'   => self::requeteChiffree(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Garde-fou : un cookie de session forgé/corrompu (bots, scans) avec un
        // identifiant trop long ou contenant des caractères illégaux fait
        // émettre un warning par session_start() puis échouer la lecture.
        // PHP n'autorise que [A-Za-z0-9,-] et une longueur raisonnable.
        if (isset($_COOKIE[$sessionName])) {
            $sid = $_COOKIE[$sessionName];
            if (!is_string($sid) || !preg_match('/^[A-Za-z0-9,\-]{1,128}$/', $sid)) {
                // Identifiant invalide → on l'ignore : PHP en génère un propre.
                unset($_COOKIE[$sessionName]);
            }
        }

        // Supprime le warning éventuel : on gère l'échec proprement et on
        // régénère une session vierge si la lecture échoue malgré tout.
        if (!@session_start()) {
            @session_id(session_create_id() ?: bin2hex(random_bytes(16)));
            @session_start();
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        session_destroy();
        $_SESSION = [];
    }

    public static function currentUser(): ?array
    {
        return self::get('user');
    }

    public static function isAuthenticated(): bool
    {
        return self::has('user');
    }

    /**
     * Rôle Administrateur (livré, verrouillé) : tous les droits. Réservé à ce
     * qui ne se délègue pas — l'écran des rôles, les données (sauvegarde,
     * élagage) — et aux contacts « Prévenir un administrateur ». Tout le
     * reste se décide par droit : Session::can().
     */
    public static function isAdmin(): bool
    {
        $user = self::currentUser();
        return $user && $user['role'] === 'admin';
    }

    /** Vrai si l'utilisateur courant porte l'un des rôles fournis. */
    public static function hasRole(string ...$roles): bool
    {
        $user = self::currentUser();
        return $user && in_array($user['role'] ?? '', $roles, true);
    }

    /** Rôle livré « Créateur » (libellés seulement — les droits passent par can()). */
    public static function isCreator(): bool
    {
        return self::hasRole('creator');
    }

    /**
     * Le compte connecté a-t-il ce droit (« assistants.edit », « users.view »…) ?
     * Source unique : Role::userCan (catalogue : Permissions).
     */
    public static function can(string $permission): bool
    {
        return Role::userCan(self::currentUser(), $permission);
    }

    /** Au moins un de ces droits. */
    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }

    /** Peut ouvrir la page Assistants (droit « Assistants — Voir »). */
    public static function canManageProfiles(): bool
    {
        return self::can('assistants.view');
    }

    public static function flash(string $key, string $message): void
    {
        $_SESSION['flash'][$key] = $message;
    }

    public static function getFlash(string $key): ?string
    {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
}
