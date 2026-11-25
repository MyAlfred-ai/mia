<?php

declare(strict_types=1);

class Session
{
    /**
     * Durée de vie d'une session, en jours d'INACTIVITÉ : le cookie est
     * renvoyé au plus une fois par jour (refreshCookie), si bien qu'une
     * personne qui se sert de MIA ne se reconnecte jamais ; elle se reconnecte
     * après 30 jours sans venir. Le ramasse-miettes de PHP suit la même durée
     * (gc_maxlifetime, posé ici et dans php-env.ini). Un compte désactivé est
     * déconnecté à la page suivante quoi qu'il arrive (requireAuth relit le
     * compte en base à chaque requête).
     */
    public const LIFETIME_DAYS = 30;

    /** Délai minimal entre deux renvois du cookie (une écriture de session par jour au plus). */
    public const COOKIE_REFRESH_SEC = 86400;

    public const COOKIE_NAME = 'myLLM_session';

    /** Clé de session : dernier renvoi du cookie (horodatage Unix). */
    private const COOKIE_AT = '_cookie_at';

    public static function lifetimeSeconds(): int
    {
        return self::LIFETIME_DAYS * 86400;
    }

    /**
     * Dossier des fichiers de session : storage/sessions, monté sur le volume
     * `storage_sessions`. Ils vivaient dans /tmp du conteneur (save_path vide
     * dans l'image), effacé à chaque recréation du conteneur — donc à chaque
     * déploiement : tout le monde devait se reconnecter.
     */
    public static function savePath(): string
    {
        $racine = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        return $racine . '/storage/sessions';
    }

    /**
     * Range les sessions dans $dossier (créé privé s'il manque). Rend faux,
     * sans rien changer, si le dossier est inutilisable : PHP garde alors son
     * réglage — une session dans /tmp vaut mieux qu'aucune session.
     */
    public static function useSaveDir(string $dossier): bool
    {
        if (!is_dir($dossier)) {
            if (!@mkdir($dossier, 0770, true) && !is_dir($dossier)) {
                error_log('[Session] Dossier des sessions impossible à créer : ' . $dossier
                    . ' — sessions dans le dossier temporaire de PHP, perdues au redéploiement.');
                return false;
            }
            // mkdir applique le umask : on pose les droits voulus explicitement.
            @chmod($dossier, 0770);
        }
        if (!is_writable($dossier)) {
            error_log('[Session] Dossier des sessions non inscriptible : ' . $dossier
                . ' — sessions dans le dossier temporaire de PHP, perdues au redéploiement.');
            return false;
        }
        ini_set('session.save_path', $dossier);
        return true;
    }

    /**
     * Faut-il renvoyer le cookie ? PHP ne l'envoie qu'à la CRÉATION de la
     * session : sans renvoi, il expirait à date fixe après la connexion, même
     * pour qui venait tous les jours. Une fois par jour suffit à le prolonger
     * sans écrire la session à chaque page.
     */
    public static function cookieNeedsRefresh(?int $dernierRenvoi, int $maintenant): bool
    {
        if ($dernierRenvoi === null || $dernierRenvoi > $maintenant) {
            return true;
        }
        return $maintenant - $dernierRenvoi >= self::COOKIE_REFRESH_SEC;
    }

    /** Paramètres du cookie de session, les mêmes à la création et au renvoi. */
    private static function cookieParams(): array
    {
        return [
            'path'     => '/',
            'secure'   => self::requeteChiffree(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * Prolonge le cookie d'une personne connectée (au plus une fois par jour).
     * Une visite anonyme — page de connexion, robot — ne déclenche rien : elle
     * n'a rien à garder, et chaque renvoi écrit la session.
     */
    private static function refreshCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent() || !isset($_SESSION['user'])) {
            return;
        }
        $maintenant = time();
        $dernier = isset($_SESSION[self::COOKIE_AT]) ? (int) $_SESSION[self::COOKIE_AT] : null;
        if (!self::cookieNeedsRefresh($dernier, $maintenant)) {
            return;
        }
        $id = session_id();
        if ($id === '' || $id === false) {
            return;
        }
        setcookie(session_name(), $id, self::cookieParams() + ['expires' => $maintenant + self::lifetimeSeconds()]);
        $_SESSION[self::COOKIE_AT] = $maintenant;
    }

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

        // Sessions dans storage/sessions (volume storage_sessions) : elles
        // survivent aux redéploiements. Cf. savePath().
        self::useSaveDir(self::savePath());

        // Durée de vie serveur alignée sur le cookie. Sans ce réglage,
        // gc_maxlifetime par défaut (1440 s) détruit la session serveur en ~24 min
        // alors que le cookie promet bien plus — déconnexions intempestives, surtout
        // depuis l'app mobile revenant d'arrière-plan. Aussi posé dans php-env.ini
        // (Docker) ; l'ini_set couvre le dev hors conteneur.
        ini_set('session.gc_maxlifetime', (string) self::lifetimeSeconds());

        $sessionName = self::COOKIE_NAME;
        session_name($sessionName);
        session_set_cookie_params(self::cookieParams() + ['lifetime' => self::lifetimeSeconds()]);

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

        self::refreshCookie();
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
