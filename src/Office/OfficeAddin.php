<?php

declare(strict_types=1);

/**
 * « Claude dans Office » — réglages de l'instance, SOURCE UNIQUE.
 *
 * L'add-in officiel d'Anthropic pour Excel, Word et PowerPoint sait se
 * brancher sur une « passerelle » de l'organisation au lieu d'un compte Claude
 * par personne (doc Anthropic « Use Claude for M365 with third-party
 * platforms »). MIA joue ce rôle : l'add-in parle à `<MIA>/office/v1/…`
 * (OfficeGateway), MIA relaie à Anthropic avec la clé de l'instance et compte
 * chaque réponse en crédits, au compte de la personne.
 *
 * Deux façons de reconnaître la personne (`office_addin_auth`) :
 *   - `entra` : l'add-in envoie son jeton Microsoft 365 (SSO). La DSI règle
 *     l'application Entra une fois et déploie le manifeste ; personne n'a
 *     rien à saisir ;
 *   - `token` : chacun génère un jeton personnel dans Réglages → Comptes
 *     connectés → Claude dans Office et le colle une fois dans l'add-in.
 *     Aucune action dans Entra ;
 *   - `both` (défaut) : les deux sont acceptés.
 *
 * Coupé par défaut : rien ne passe tant qu'un administrateur ne l'a pas activé.
 */
final class OfficeAddin
{
    public const SETTING_ENABLED     = 'office_addin_enabled';
    public const SETTING_AUTH        = 'office_addin_auth';
    public const SETTING_CLIENT_ID   = 'office_addin_client_id';
    public const SETTING_TENANT      = 'office_addin_tenant';
    public const SETTING_WEB_SEARCH  = 'office_addin_web_search';
    public const SETTING_MANIFEST_ID = 'office_addin_manifest_id';
    public const SETTING_MANIFEST_VERSION = 'office_addin_manifest_version';
    public const SETTING_APPS        = 'office_addin_apps';

    /**
     * Les applications que sert le même add-in d'Anthropic (son manifeste en
     * déclare trois : `Workbook`, `Document`, `Presentation`). L'administrateur
     * choisit celles qui recevront l'add-in : le manifeste ne garde que leurs
     * blocs (OfficeManifest::build). `label` = nom de produit, jamais traduit.
     * Icônes dessinées ici (appIcon) : une page aux couleurs de l'application
     * et sa lettre, pas le logo officiel.
     */
    public const APPS = [
        'excel'      => ['label' => 'Excel',      'host' => 'Workbook',     'color' => '#107C41', 'tint' => '#CDEBD8', 'letter' => 'X'],
        'word'       => ['label' => 'Word',       'host' => 'Document',     'color' => '#185ABD', 'tint' => '#D3E2F9', 'letter' => 'W'],
        'powerpoint' => ['label' => 'PowerPoint', 'host' => 'Presentation', 'color' => '#C43E1C', 'tint' => '#F8D9CF', 'letter' => 'P'],
    ];

    public const AUTH_ENTRA = 'entra';
    public const AUTH_TOKEN = 'token';
    public const AUTH_BOTH  = 'both';
    public const AUTH_MODES = [self::AUTH_BOTH, self::AUTH_ENTRA, self::AUTH_TOKEN];

    /** Permission exposée par l'application Entra (« Exposer une API »). */
    public const SCOPE = 'access_as_user';

    /** Chemin de la passerelle : `gateway_url` = APP_URL + ce préfixe. */
    public const PATH_PREFIX = '/office';

    /** Seule origine autorisée (CORS) : l'add-in est servi par Anthropic depuis ce domaine. */
    public const ALLOWED_ORIGIN = 'https://pivot.claude.ai';

    /** Adresses de retour SPA à déclarer dans Entra (doc du plugin d'installation Anthropic). */
    public const REDIRECT_URIS = [
        'brk-multihub://pivot.claude.ai',
        'https://pivot.claude.ai/msal-redirect.html',
    ];

    public static function isEnabled(): bool
    {
        return (string)Settings::get(self::SETTING_ENABLED, '0') === '1';
    }

    public static function authMode(): string
    {
        $mode = (string)Settings::get(self::SETTING_AUTH, self::AUTH_BOTH);
        return in_array($mode, self::AUTH_MODES, true) ? $mode : self::AUTH_BOTH;
    }

    /** Identifiant d'application Entra : le réglage propre, sinon celui du SSO Microsoft. */
    public static function clientId(): string
    {
        $own = strtolower(trim((string)Settings::get(self::SETTING_CLIENT_ID, '')));
        if ($own !== '') {
            return $own;
        }
        $sso = strtolower(trim(SsoRegistry::microsoftClientId()));
        return EntraJwt::isGuid($sso) ? $sso : '';
    }

    /** Annuaire (GUID) : le réglage propre, sinon celui du SSO s'il est un GUID. */
    public static function tenant(): string
    {
        $own = strtolower(trim((string)Settings::get(self::SETTING_TENANT, '')));
        if ($own !== '') {
            return $own;
        }
        $sso = strtolower(trim(SsoRegistry::microsoftTenant()));
        return EntraJwt::isGuid($sso) ? $sso : '';
    }

    /** L'identifiant et l'annuaire viennent-ils du SSO (aucun réglage propre) ? */
    public static function inheritsSso(): bool
    {
        return trim((string)Settings::get(self::SETTING_CLIENT_ID, '')) === ''
            && trim((string)Settings::get(self::SETTING_TENANT, '')) === '';
    }

    /** Les deux identifiants Entra sont-ils exploitables ? */
    public static function entraConfigured(): bool
    {
        return EntraJwt::isGuid(self::clientId()) && EntraJwt::isGuid(self::tenant());
    }

    public static function entraAllowed(): bool
    {
        return self::authMode() !== self::AUTH_TOKEN && self::entraConfigured();
    }

    public static function tokensAllowed(): bool
    {
        return self::authMode() !== self::AUTH_ENTRA;
    }

    /** Recherche Internet de l'add-in (servie par le fournisseur de recherche d'Anthropic). */
    /** Applications choisies (au moins une ; défaut : les trois). */
    public static function apps(): array
    {
        $raw = Settings::get(self::SETTING_APPS, null);
        if ($raw === null || $raw === '') {
            return array_keys(self::APPS);
        }
        $apps = self::normalizeApps((string)$raw);
        return $apps === [] ? array_keys(self::APPS) : $apps;
    }

    /**
     * Applications connues, dans l'ordre du catalogue, sans doublon. Pure.
     *
     * @param string|list<string> $value liste ou « excel,word »
     * @return list<string>
     */
    public static function normalizeApps(string|array $value): array
    {
        $asked = is_array($value) ? array_map('strval', $value) : explode(',', $value);
        $asked = array_map(static fn(string $a): string => strtolower(trim($a)), $asked);
        return array_values(array_filter(array_keys(self::APPS), static fn(string $k): bool => in_array($k, $asked, true)));
    }

    /** Hôtes Office (`Workbook`…) des applications choisies. Pure. */
    public static function hostsFor(array $apps): array
    {
        return array_values(array_map(static fn(string $a): string => self::APPS[$a]['host'], self::normalizeApps($apps)));
    }

    /**
     * Icône d'une application (SVG en ligne, ~22 px) : une page teintée avec
     * son motif (grille, lignes, diapositive) et un carré à sa lettre. Pure.
     */
    public static function appIcon(string $app, int $size = 22): string
    {
        $a = self::APPS[$app] ?? null;
        if ($a === null) {
            return '';
        }
        $c = $a['color'];
        $motif = match ($app) {
            'excel' => '<path d="M14.5 6.5h6M14.5 10h6M14.5 13.5h6M14.5 17h6M17.5 4.5v15" stroke="' . $c . '" stroke-width="1.2" stroke-linecap="round"/>',
            'word'  => '<path d="M14.5 6.5h6M14.5 9.5h6M14.5 12.5h6M14.5 15.5h4.5" stroke="' . $c . '" stroke-width="1.4" stroke-linecap="round"/>',
            default => '<circle cx="17.5" cy="9.5" r="3" fill="' . $c . '"/><path d="M14.5 15.5h6" stroke="' . $c . '" stroke-width="1.4" stroke-linecap="round"/>',
        };
        return '<svg class="office-app-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" role="img" aria-label="' . $a['label'] . '">'
            . '<rect x="8" y="2" width="14" height="20" rx="2.5" fill="' . $a['tint'] . '"/>'
            . $motif
            . '<rect x="2" y="6" width="12" height="12" rx="2.5" fill="' . $c . '"/>'
            . '<text x="8" y="15.2" text-anchor="middle" font-family="Segoe UI, Lexend, Helvetica, Arial, sans-serif" font-size="8.8" font-weight="700" fill="#fff">' . $a['letter'] . '</text>'
            . '</svg>';
    }

    /** Les icônes des applications données, côte à côte. Pure. */
    public static function appIcons(?array $apps = null, int $size = 22): string
    {
        $out = '';
        foreach (self::normalizeApps($apps ?? array_keys(self::APPS)) as $app) {
            $out .= self::appIcon($app, $size);
        }
        return '<span class="office-app-icons">' . $out . '</span>';
    }

    /** « Excel, Word et PowerPoint » — ou « …ou PowerPoint » (noms de produits, liaison traduite). */
    public static function appsSentence(array $apps, bool $or = false): string
    {
        $labels = array_map(static fn(string $a): string => self::APPS[$a]['label'], self::normalizeApps($apps));
        if (count($labels) <= 1) {
            return (string)($labels[0] ?? '');
        }
        $last = array_pop($labels);
        return $or
            ? t('{list} ou {last}', ['list' => implode(', ', $labels), 'last' => $last])
            : t('{list} et {last}', ['list' => implode(', ', $labels), 'last' => $last]);
    }

    public static function webSearchAllowed(): bool
    {
        return (string)Settings::get(self::SETTING_WEB_SEARCH, '1') !== '0';
    }

    /** `gateway_url` à donner à l'add-in (HTTPS obligatoire pour lui). */
    public static function gatewayUrl(): string
    {
        return rtrim((string)APP_URL, '/') . self::PATH_PREFIX;
    }

    public static function gatewayIsHttps(): bool
    {
        return str_starts_with(self::gatewayUrl(), 'https://');
    }

    /** Permission complète à demander (`entra_scope`). */
    public static function entraScope(?string $clientId = null): string
    {
        return 'api://' . ($clientId ?? self::clientId()) . '/' . self::SCOPE;
    }

    /** Une clé Anthropic de l'instance existe-t-elle ? */
    public static function hasAnthropicKey(): bool
    {
        try {
            return (string)(ApiKey::getDecrypted('anthropic') ?? '') !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Paramètres de l'add-in (manifeste), selon le mode. Pure : tout est
     * passé. Les clés sont celles de la doc Anthropic ; aucune n'est un
     * secret en mode Entra comme en mode jeton (le jeton se saisit dans
     * l'add-in, il ne voyage jamais dans le manifeste).
     *
     * @return array<string, string>
     */
    public static function manifestParams(string $gatewayUrl, bool $entra, string $clientId, bool $webSearch): array
    {
        $params = [
            'gateway_url'        => $gatewayUrl,
            'gateway_api_format' => 'anthropic',
        ];
        if ($entra) {
            $params['entra_sso']           = '1';
            $params['graph_client_id']     = $clientId;
            $params['entra_scope']         = self::entraScope($clientId);
            $params['gateway_auth_source'] = 'entra';
        } else {
            // Chacun colle SON jeton : le formulaire s'affiche, adresse pré-remplie.
            $params['gateway_auth_header'] = 'authorization';
            $params['auto_connect']        = '0';
        }
        if (!$webSearch) {
            $params['disabled_features'] = 'web_search';
        }
        return $params;
    }

    /** Paramètres du manifeste pour la configuration enregistrée. */
    public static function currentManifestParams(): array
    {
        return self::manifestParams(self::gatewayUrl(), self::entraAllowed(), self::clientId(), self::webSearchAllowed());
    }

    /** Identifiant STABLE du manifeste de l'instance (créé au premier besoin). */
    public static function manifestId(): string
    {
        $id = strtolower(trim((string)Settings::get(self::SETTING_MANIFEST_ID, '')));
        if (EntraJwt::isGuid($id)) {
            return $id;
        }
        $id = self::uuid4();
        Settings::set(self::SETTING_MANIFEST_ID, $id);
        return $id;
    }

    /**
     * Quatrième segment de `<Version>` : le Centre d'administration Microsoft
     * 365 ignore un nouveau dépôt à la même version. Il n'avance que si les
     * paramètres ont changé depuis le dernier manifeste fabriqué — deux
     * téléchargements identiques donnent le même fichier.
     */
    public static function manifestRevision(array $params): int
    {
        $sig = hash('sha256', (string)json_encode($params, JSON_UNESCAPED_SLASHES));
        $stored = json_decode((string)Settings::get(self::SETTING_MANIFEST_VERSION, ''), true);
        $rev = is_array($stored) ? max(1, (int)($stored['rev'] ?? 1)) : 0;
        if (!is_array($stored) || ($stored['sig'] ?? '') !== $sig) {
            $rev++;
            Settings::set(self::SETTING_MANIFEST_VERSION, (string)json_encode(['rev' => $rev, 'sig' => $sig]));
        }
        return $rev;
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /**
     * Normalise ce que saisit l'administrateur (pure). Un identifiant vide
     * reprend celui du SSO ; un identifiant non vide doit être un GUID.
     *
     * @return array{enabled:bool, auth:string, client_id:string, tenant:string, web_search:bool, apps:list<string>, errors:list<string>}
     */
    public static function normalizeInput(array $post): array
    {
        $errors = [];
        $auth = (string)($post['auth'] ?? self::AUTH_BOTH);
        if (!in_array($auth, self::AUTH_MODES, true)) {
            $auth = self::AUTH_BOTH;
        }
        $client = strtolower(trim((string)($post['client_id'] ?? '')));
        if ($client !== '' && !EntraJwt::isGuid($client)) {
            $errors[] = t('L’ID d’application doit être un identifiant Entra (GUID), par exemple 1b2c3d4e-0000-4a5b-8c9d-0123456789ab.');
            $client = '';
        }
        $tenant = strtolower(trim((string)($post['tenant'] ?? '')));
        if ($tenant !== '' && !EntraJwt::isGuid($tenant)) {
            $errors[] = t('L’ID de l’annuaire doit être un identifiant Entra (GUID) : un nom de domaine ne suffit pas, Entra l’écrit en GUID dans chaque jeton.');
            $tenant = '';
        }
        $apps = self::normalizeApps((array)($post['apps'] ?? []));
        if ($apps === []) {
            $errors[] = t('Choisissez au moins une application : Excel, Word ou PowerPoint.');
            $apps = array_keys(self::APPS);
        }
        return [
            'enabled'    => !empty($post['enabled']),
            'auth'       => $auth,
            'client_id'  => $client,
            'tenant'     => $tenant,
            'web_search' => !empty($post['web_search']),
            'apps'       => $apps,
            'errors'     => $errors,
        ];
    }
}
