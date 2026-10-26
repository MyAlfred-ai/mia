<?php

declare(strict_types=1);

/**
 * SOURCE UNIQUE de la configuration d'authentification (SSO + mot de passe).
 *
 * Même patron que ConnectorRegistry et KbSync : un catalogue statique, une clé
 * `settings` par réglage, les secrets chiffrés par Encryption (suffixe `_enc`).
 *
 * POURQUOI la base et plus seulement le .env :
 *  - changer un client_id imposait un redéploiement (les identifiants sont des
 *    constantes PHP définies dans config/app.php AVANT toute connexion base) ;
 *  - le conteneur `worker` ne recevait pas toujours ces variables : les trois
 *    connecteurs Google (Drive, Gmail, Agenda) y étaient alors réputés non
 *    configurés et n'étaient JAMAIS exposés au LLM, sans une ligne de log.
 *    Lire la base supprime cette dérive : app et worker voient la même valeur.
 *
 * REPLI .env OBLIGATOIRE : une installation existante n'a rien saisi en base et
 * ne doit rien perdre. Toute lecture d'identifiant Google retombe donc sur la
 * constante correspondante, et `sso_google_enabled` vaut '1' par défaut dès
 * qu'un client_id existe (peu importe d'où il vient).
 *
 * Les statiques pures (normalizeDomains, domainAllowed, guardLockout,
 * mergeDesiredState, normalizeLoginMessage, providerFromState) sont testables
 * sans base ni réseau — convention du repo.
 */
final class SsoRegistry
{
    public const GOOGLE    = 'google';
    public const MICROSOFT = 'microsoft';
    public const PASSWORD  = 'password';

    /** Méthodes d'authentification, dans l'ordre d'affichage de la page de connexion. */
    public const PROVIDERS = [self::GOOGLE, self::MICROSOFT];

    // ---- Clés Settings -----------------------------------------------------

    public const SETTING_GOOGLE_ENABLED    = 'sso_google_enabled';
    public const SETTING_GOOGLE_CLIENT     = 'sso_google_client_id';
    public const SETTING_GOOGLE_SECRET     = 'sso_google_client_secret_enc';

    public const SETTING_MS_ENABLED        = 'sso_microsoft_enabled';
    public const SETTING_MS_CLIENT         = 'sso_microsoft_client_id';
    public const SETTING_MS_SECRET         = 'sso_microsoft_client_secret_enc';
    public const SETTING_MS_TENANT         = 'sso_microsoft_tenant';

    public const SETTING_PASSWORD_ENABLED  = 'sso_password_enabled';
    public const SETTING_ALLOWED_DOMAINS   = 'sso_allowed_domains';

    /**
     * Texte libre affiché sur la page de connexion, sous le nom de
     * l'application. Vide par défaut : rien n'est rendu. Contenu écrit par un
     * administrateur, donc jamais traduit (même règle que la punchline
     * personnalisée, cf. I18n::editorial).
     */
    public const SETTING_LOGIN_MESSAGE     = 'login_message';
    public const LOGIN_MESSAGE_MAX         = 500;

    // ---- Sections de la page /admin/sso ------------------------------------

    /**
     * La page d'authentification s'enregistre SECTION PAR SECTION, chacune
     * avec son bouton — pas d'enregistrement automatique ici : des
     * identifiants de connexion ne doivent jamais partir à moitié saisis, et
     * couper un moyen de connexion est un geste qu'on assume d'un clic.
     *
     * Corollaire : un formulaire ne poste QUE sa section. Le handler doit donc
     * ne toucher qu'à elle — la convention maison « case absente du POST =
     * décochée » éteindrait sinon les deux autres interrupteurs à chaque
     * enregistrement (cf. mergeDesiredState pour la garde anti-verrouillage).
     */
    public const SECTION_GOOGLE    = 'google';
    public const SECTION_MICROSOFT = 'microsoft';
    public const SECTION_PASSWORD  = 'password';
    public const SECTION_DOMAINS   = 'domains';
    public const SECTION_LOGIN     = 'login';

    public const SECTIONS = [
        self::SECTION_GOOGLE,
        self::SECTION_MICROSOFT,
        self::SECTION_PASSWORD,
        self::SECTION_DOMAINS,
        self::SECTION_LOGIN,
    ];

    /** Préfixes de `state` : le callback unique doit savoir à qui rendre la main. */
    public const STATE_PREFIXES = [
        'sso_google_' => self::GOOGLE,
        'sso_ms_'     => self::MICROSOFT,
    ];

    /**
     * Préfixes des connecteurs personnels. Les quatre connecteurs Google et
     * Outlook partagent l'URI de redirection du login et sont routés AVANT le
     * SSO ; OneDrive a la sienne (`/settings/onedrive/callback`) et ne devrait
     * jamais arriver ici — il est listé quand même : `providerFromState`
     * retombe sur Google pour tout préfixe inconnu, et un retour OneDrive
     * égaré finirait sinon dans le flux de connexion Google.
     *
     * LA LISTE DOIT ÊTRE EXHAUSTIVE, et c'est tout son intérêt : un préfixe
     * oublié ici n'a aucun effet tant qu'AuthController le route en amont,
     * puis devient un retour de connecteur traité comme une tentative de
     * connexion le jour où cet ordre bouge. (`gtasks_` y manquait.)
     */
    public const CONNECTOR_PREFIXES = ['drive_', 'gmail_', 'gcal_', 'gtasks_', 'outlook_', 'mscal_', 'mstodo_', 'msteams_', 'onedrive_', 'slack_'];

    public const MAX_DOMAINS = 20;

    // ---- Catalogue ---------------------------------------------------------

    /**
     * Descripteurs statiques — aucune lecture de base ni de réglage.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            self::GOOGLE => [
                'key'          => self::GOOGLE,
                'label'        => 'Google',
                'setting'      => self::SETTING_GOOGLE_ENABLED,
                'state_prefix' => 'sso_google_',
                'console'      => 'https://console.cloud.google.com/apis/credentials',
                'console_label' => 'Google Cloud — Identifiants',
            ],
            self::MICROSOFT => [
                'key'          => self::MICROSOFT,
                'label'        => 'Microsoft',
                'setting'      => self::SETTING_MS_ENABLED,
                'state_prefix' => 'sso_ms_',
                'console'      => 'https://entra.microsoft.com/',
                'console_label' => 'Portail Microsoft Entra',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return self::PROVIDERS;
    }

    public static function label(string $provider): string
    {
        return (string)(self::catalog()[$provider]['label'] ?? $provider);
    }

    public static function statePrefix(string $provider): string
    {
        return (string)(self::catalog()[$provider]['state_prefix'] ?? '');
    }

    // ---- Identifiants ------------------------------------------------------

    /**
     * URI de redirection, UNIQUE pour tout : login Google, login Microsoft et
     * les trois connecteurs personnels. Une seule URI à déclarer dans la
     * console Google et dans le portail Azure.
     */
    public static function redirectUri(): string
    {
        return defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI !== ''
            ? GOOGLE_REDIRECT_URI
            : rtrim(APP_URL, '/') . '/auth/callback';
    }

    public static function googleClientId(): string
    {
        return self::valueOrEnv(self::SETTING_GOOGLE_CLIENT, 'GOOGLE_CLIENT_ID');
    }

    public static function googleClientSecret(): string
    {
        $stored = self::secret(self::SETTING_GOOGLE_SECRET);
        if ($stored !== '') {
            return $stored;
        }
        return defined('GOOGLE_CLIENT_SECRET') ? (string)GOOGLE_CLIENT_SECRET : '';
    }

    public static function microsoftClientId(): string
    {
        return trim((string)Settings::get(self::SETTING_MS_CLIENT, ''));
    }

    public static function microsoftClientSecret(): string
    {
        return self::secret(self::SETTING_MS_SECRET);
    }

    public static function microsoftTenant(): string
    {
        return self::normalizeTenant((string)Settings::get(self::SETTING_MS_TENANT, ''));
    }

    /**
     * Tenant Entra : `common` (tout compte Microsoft), `organizations`
     * (comptes professionnels uniquement), `consumers` (comptes personnels)
     * ou l'identifiant d'un annuaire précis. Pur, testable.
     */
    public static function normalizeTenant(string $saisie): string
    {
        $tenant = strtolower(trim($saisie));
        if ($tenant === '') {
            return 'common';
        }
        if (in_array($tenant, ['common', 'organizations', 'consumers'], true)) {
            return $tenant;
        }
        // GUID d'annuaire, ou domaine vérifié (contoso.onmicrosoft.com).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant)) {
            return $tenant;
        }
        if (preg_match('/^[a-z0-9][a-z0-9.\-]{0,251}[a-z0-9]$/', $tenant) && str_contains($tenant, '.')) {
            return $tenant;
        }
        return 'common';
    }

    /**
     * True si le tenant désigne UNE organisation précise. Le rapprochement d'un
     * compte Microsoft avec un compte existant par e-mail n'est sûr que dans ce
     * cas (ou par domaine déclaré) : sur `common`, l'administrateur d'un tenant
     * tiers peut placer n'importe quelle adresse dans l'annuaire.
     */
    public static function microsoftTenantIsNamed(): bool
    {
        return !in_array(self::microsoftTenant(), ['common', 'organizations', 'consumers'], true);
    }

    // ---- Activation --------------------------------------------------------

    /**
     * Google est actif par défaut dès que des identifiants existent : une
     * installation qui se connectait déjà par Google ne doit pas se retrouver
     * sans bouton après la mise à jour.
     */
    public static function isEnabled(string $method): bool
    {
        return match ($method) {
            self::GOOGLE    => Settings::get(self::SETTING_GOOGLE_ENABLED, self::googleClientId() !== '' ? '1' : '0') === '1',
            self::MICROSOFT => Settings::get(self::SETTING_MS_ENABLED, '0') === '1',
            self::PASSWORD  => Settings::get(self::SETTING_PASSWORD_ENABLED, '1') === '1',
            default         => false,
        };
    }

    public static function setEnabled(string $method, bool $enabled): void
    {
        $key = match ($method) {
            self::GOOGLE    => self::SETTING_GOOGLE_ENABLED,
            self::MICROSOFT => self::SETTING_MS_ENABLED,
            self::PASSWORD  => self::SETTING_PASSWORD_ENABLED,
            default         => null,
        };
        if ($key !== null) {
            Settings::set($key, $enabled ? '1' : '0');
        }
    }

    public static function isConfigured(string $provider): bool
    {
        return match ($provider) {
            self::GOOGLE    => self::googleClientId() !== '' && self::googleClientSecret() !== '',
            self::MICROSOFT => self::microsoftClientId() !== '' && self::microsoftClientSecret() !== '',
            default         => false,
        };
    }

    /** Un fournisseur n'est proposé que s'il est à la fois autorisé ET utilisable. */
    public static function isUsable(string $provider): bool
    {
        return self::isEnabled($provider) && self::isConfigured($provider);
    }

    public static function passwordEnabled(): bool
    {
        return self::isEnabled(self::PASSWORD);
    }

    /**
     * Aide de la fenêtre « Nouvel utilisateur » (pure, testable). Elle crée un
     * compte À MOT DE PASSE — l'ancienne phrase, figée sur Google, se lisait
     * « MIA va créer un compte Google » et faisait renoncer un administrateur
     * dont l'organisation se connecte par Microsoft. Elle nomme donc les
     * fournisseurs RÉELLEMENT proposés, et prévient quand le mot de passe ne
     * servira à rien. Traduit dans la langue de l'écran : chaque phrase est une
     * clé entière, l'énumération des fournisseurs (« Google ou Microsoft »)
     * passe par sa propre clé — l'ordre et la conjonction changent d'une
     * langue à l'autre.
     *
     * @param list<string> $ssoLabels libellés des fournisseurs proposés
     */
    public static function newAccountHint(array $ssoLabels, bool $passwordEnabled): string
    {
        $phrases = [t('Ce formulaire crée un compte qui se connecte par e-mail et mot de passe.')];

        $ssoLabels = array_values(array_filter(array_map('strval', $ssoLabels), static fn(string $l): bool => $l !== ''));
        if ($ssoLabels !== []) {
            $dernier = array_pop($ssoLabels);
            $noms    = $ssoLabels === []
                ? $dernier
                : t('{noms} ou {dernier}', ['noms' => implode(', ', $ssoLabels), 'dernier' => $dernier]);
            $phrases[] = t(
                'Pour une personne qui se connecte par {noms}, inutile de le créer ici : son compte apparaît de lui-même à sa première connexion.',
                ['noms' => $noms]
            );
        }

        if (!$passwordEnabled) {
            $phrases[] = t('La connexion par mot de passe est désactivée (Organisation → Authentification) : le mot de passe saisi ici ne servira pas.');
        }

        return implode(' ', $phrases);
    }

    /** @return list<string> fournisseurs affichables sur la page de connexion */
    public static function usableProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, static fn(string $p): bool => self::isUsable($p)));
    }

    /**
     * Instancie un fournisseur. Aucune mémoïsation : les instances ne portent
     * pas d'état, et un cache de configuration se serait périmé dans le worker
     * (process long) exactement comme le cache de Settings avant son drapeau
     * `$loaded`.
     */
    public static function provider(string $key): ?SsoProvider
    {
        return match ($key) {
            self::GOOGLE    => new GoogleSsoProvider(),
            self::MICROSOFT => new MicrosoftSsoProvider(),
            default         => null,
        };
    }

    /**
     * Fournisseurs prêts à être affichés, sous forme d'instances.
     *
     * @return list<SsoProvider>
     */
    public static function loginProviders(): array
    {
        $providers = [];
        foreach (self::usableProviders() as $key) {
            $provider = self::provider($key);
            if ($provider !== null) {
                $providers[] = $provider;
            }
        }
        return $providers;
    }

    /**
     * État RÉELLEMENT utilisable de chaque méthode, tel qu'enregistré : c'est
     * ce contre quoi la garde anti-verrouillage raisonne quand une seule
     * section est repostée.
     *
     * @return array{google:bool, microsoft:bool, password:bool}
     */
    public static function usableState(): array
    {
        return [
            self::GOOGLE    => self::isUsable(self::GOOGLE),
            self::MICROSOFT => self::isUsable(self::MICROSOFT),
            self::PASSWORD  => self::passwordEnabled(),
        ];
    }

    /**
     * Remplace, dans un état courant, la seule méthode que la section postée
     * a le droit de changer (pure, testable).
     *
     * Le formulaire Google ne dit rien de Microsoft ni du mot de passe : les
     * lire dans le POST les ferait passer pour décochés, et la garde
     * réactiverait le mot de passe à chaque enregistrement de Google — ou pire,
     * `setEnabled` les éteindrait. D'où cette fusion : ce qui n'est pas dans
     * la section garde sa valeur enregistrée.
     *
     * @param array{google:bool, microsoft:bool, password:bool} $courant
     * @return array{google:bool, microsoft:bool, password:bool}
     */
    public static function mergeDesiredState(array $courant, string $section, bool $souhaite): array
    {
        $etat = [
            self::GOOGLE    => !empty($courant[self::GOOGLE]),
            self::MICROSOFT => !empty($courant[self::MICROSOFT]),
            self::PASSWORD  => !empty($courant[self::PASSWORD]),
        ];
        if (array_key_exists($section, $etat)) {
            $etat[$section] = $souhaite;
        }
        return $etat;
    }

    /**
     * GARDE ANTI-VERROUILLAGE (pure, testable).
     *
     * Éteindre toutes les méthodes fermerait l'application à tout le monde, y
     * compris à l'administrateur qui vient de le faire. On rétablit alors le
     * mot de passe — la seule méthode qui ne dépend d'aucun tiers — et on le
     * signale à l'appelant plutôt que d'enregistrer un état inutilisable.
     *
     * @param array{google:bool, microsoft:bool, password:bool} $souhaite
     * @return array{google:bool, microsoft:bool, password:bool, corrige:bool}
     */
    public static function guardLockout(array $souhaite): array
    {
        $etat = [
            self::GOOGLE    => !empty($souhaite[self::GOOGLE]),
            self::MICROSOFT => !empty($souhaite[self::MICROSOFT]),
            self::PASSWORD  => !empty($souhaite[self::PASSWORD]),
        ];

        $resteUneMethode = $etat[self::PASSWORD] || $etat[self::GOOGLE] || $etat[self::MICROSOFT];
        $etat['corrige']  = false;
        if (!$resteUneMethode) {
            $etat[self::PASSWORD] = true;
            $etat['corrige']      = true;
        }
        return $etat;
    }

    // ---- Domaines autorisés ------------------------------------------------

    /**
     * Normalise une saisie libre de domaines (une par ligne ou séparés par des
     * virgules) : minuscules, `@` et espaces retirés, doublons supprimés.
     * Pur, testable.
     *
     * @return list<string>
     */
    public static function normalizeDomains(string $saisie): array
    {
        $morceaux = preg_split('/[\s,;]+/', $saisie) ?: [];
        $domaines = [];
        foreach ($morceaux as $brut) {
            $domaine = strtolower(trim((string)$brut));
            $domaine = ltrim($domaine, '@');
            $domaine = trim($domaine, '.');
            if ($domaine === '' || !str_contains($domaine, '.')) {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $domaine)) {
                continue;
            }
            if (!in_array($domaine, $domaines, true)) {
                $domaines[] = $domaine;
            }
            if (count($domaines) >= self::MAX_DOMAINS) {
                break;
            }
        }
        return $domaines;
    }

    /**
     * True si l'adresse relève d'un domaine autorisé. Liste VIDE = aucune
     * restriction (comportement historique). Pur, testable.
     *
     * @param list<string> $domaines
     */
    public static function domainAllowed(string $email, array $domaines): bool
    {
        if ($domaines === []) {
            return true;
        }
        $position = strrpos($email, '@');
        if ($position === false) {
            return false;
        }
        $domaine = strtolower(trim(substr($email, $position + 1)));
        return in_array($domaine, $domaines, true);
    }

    /** @return list<string> */
    public static function allowedDomains(): array
    {
        return self::normalizeDomains((string)Settings::get(self::SETTING_ALLOWED_DOMAINS, ''));
    }

    public static function emailDomainAllowed(string $email): bool
    {
        return self::domainAllowed($email, self::allowedDomains());
    }

    // ---- Message de la page de connexion -----------------------------------

    /**
     * Normalise le texte saisi par l'administrateur (pure, testable) : fins de
     * ligne unifiées, caractères de contrôle retirés (les retours à la ligne
     * restent — c'est une mise en forme légitime, rendue par `pre-line`),
     * lignes vides en série ramenées à une seule, bornage à LOGIN_MESSAGE_MAX
     * caractères — des CARACTÈRES, pas des octets : « é » ou un emoji ne
     * doivent pas couper le texte plus tôt que ce que le compteur de l'écran
     * annonce.
     */
    public static function normalizeLoginMessage(string $saisie): string
    {
        $texte = str_replace(["\r\n", "\r"], "\n", $saisie);
        $texte = (string)preg_replace('/[^\P{C}\n]+/u', '', $texte);
        $texte = (string)preg_replace('/[ \t]+\n/', "\n", $texte);
        $texte = (string)preg_replace('/\n{3,}/', "\n\n", $texte);
        $texte = trim($texte);
        if (mb_strlen($texte) > self::LOGIN_MESSAGE_MAX) {
            $texte = rtrim(mb_substr($texte, 0, self::LOGIN_MESSAGE_MAX));
        }
        return $texte;
    }

    /** Texte affiché sur la page de connexion ; '' = rien à afficher. */
    public static function loginMessage(): string
    {
        return self::normalizeLoginMessage((string)Settings::get(self::SETTING_LOGIN_MESSAGE, ''));
    }

    // ---- State -------------------------------------------------------------

    /**
     * Fournisseur visé par un `state` de retour OAuth (pur, testable).
     *
     * Les préfixes des connecteurs personnels sont explicitement écartés : ils
     * sont traités en amont par AuthController::callback() et ne doivent jamais
     * être confondus avec une connexion.
     *
     * Un state SANS préfixe connu retombe sur Google : au moment du
     * déploiement, des connexions ouvertes juste avant portent encore l'ancien
     * format nu et doivent aboutir au lieu d'échouer.
     */
    public static function providerFromState(string $state): ?string
    {
        if ($state === '') {
            return null;
        }
        foreach (self::CONNECTOR_PREFIXES as $prefixe) {
            if (str_starts_with($state, $prefixe)) {
                return null;
            }
        }
        foreach (self::STATE_PREFIXES as $prefixe => $provider) {
            if (str_starts_with($state, $prefixe)) {
                return $provider;
            }
        }
        return self::GOOGLE;
    }

    // ---- Interne -----------------------------------------------------------

    /** Valeur en base, sinon la constante d'environnement de même rôle. */
    private static function valueOrEnv(string $cle, string $constante): string
    {
        $stored = trim((string)Settings::get($cle, ''));
        if ($stored !== '') {
            return $stored;
        }
        return defined($constante) ? (string)constant($constante) : '';
    }

    /**
     * Secret déchiffré, '' si absent OU indéchiffrable (clé de chiffrement
     * changée) : Encryption::decrypt ne lève jamais, il renvoie ''.
     */
    private static function secret(string $cle): string
    {
        $chiffre = (string)Settings::get($cle, '');
        return $chiffre === '' ? '' : Encryption::decrypt($chiffre);
    }
}
