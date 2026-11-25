<?php

declare(strict_types=1);

/**
 * Écrans de « Claude dans Office » (la passerelle elle-même est
 * OfficeGatewayController) :
 *   - Capacités → Connecteurs → Claude dans Office (`/admin/connectors/office`) :
 *     activer, choisir comment reconnaître les personnes, l'application Entra,
 *     télécharger le manifeste à déposer dans Microsoft 365, tester ;
 *   - Réglages → Comptes connectés, carte « Claude dans Office » : le jeton
 *     personnel (générer, révoquer) quand l'organisation l'autorise.
 *
 * Enregistrement MANUEL de l'écran d'administration, comme la page Slack et
 * Authentification : des identifiants Entra ne partent jamais à moitié saisis,
 * et activer la passerelle ouvre une dépense.
 */
final class OfficeAddinController
{
    // ================================================================ Administration

    public function admin(): void
    {
        AuthMiddleware::requirePermission('connectors.view');

        $enabled       = OfficeAddin::isEnabled();
        $authMode      = OfficeAddin::authMode();
        $clientId      = OfficeAddin::clientId();
        $tenant        = OfficeAddin::tenant();
        $ownClientId   = (string)Settings::get(OfficeAddin::SETTING_CLIENT_ID, '');
        $ownTenant     = (string)Settings::get(OfficeAddin::SETTING_TENANT, '');
        $inheritsSso   = OfficeAddin::inheritsSso();
        $entraReady    = OfficeAddin::entraConfigured();
        $webSearch     = OfficeAddin::webSearchAllowed();
        $apps          = OfficeAddin::apps();
        $gatewayUrl    = OfficeAddin::gatewayUrl();
        $gatewayHttps  = OfficeAddin::gatewayIsHttps();
        $hasKey        = OfficeAddin::hasAnthropicKey();
        $redirectUris  = OfficeAddin::REDIRECT_URIS;
        $scope         = OfficeAddin::SCOPE;
        $entraScope    = $clientId !== '' ? OfficeAddin::entraScope($clientId) : 'api://<ID>/' . OfficeAddin::SCOPE;
        $manifestEntra = OfficeAddin::entraAllowed();
        try {
            $tokenCount = OfficeAddinToken::count();
        } catch (\Throwable $e) {
            $tokenCount = null;
        }
        try {
            $officeMonth = UsageLog::officeSummary(date('Y-m-01'), date('Y-m-t'));
        } catch (\Throwable $e) {
            $officeMonth = null;
        }

        $success = Session::getFlash('office_addin_success');
        $error   = Session::getFlash('office_addin_error');
        require ROOT . '/templates/admin/connectors-office.php';
    }

    public function saveAdmin(): void
    {
        AuthMiddleware::requirePermission('connectors.edit');

        $in = OfficeAddin::normalizeInput($_POST);
        Settings::set(OfficeAddin::SETTING_ENABLED, $in['enabled'] ? '1' : '0');
        Settings::set(OfficeAddin::SETTING_AUTH, $in['auth']);
        Settings::set(OfficeAddin::SETTING_WEB_SEARCH, $in['web_search'] ? '1' : '0');
        Settings::set(OfficeAddin::SETTING_APPS, implode(',', $in['apps']));
        // Un champ refusé garde la valeur d'avant (jamais effacée par une faute de frappe).
        if (trim((string)($_POST['client_id'] ?? '')) === '' || $in['client_id'] !== '') {
            Settings::set(OfficeAddin::SETTING_CLIENT_ID, $in['client_id']);
        }
        if (trim((string)($_POST['tenant'] ?? '')) === '' || $in['tenant'] !== '') {
            Settings::set(OfficeAddin::SETTING_TENANT, $in['tenant']);
        }

        $user = Session::currentUser();
        error_log('[Claude dans Office] réglages enregistrés par #' . (int)($user['id'] ?? 0)
            . ' — ' . ($in['enabled'] ? 'activé' : 'désactivé') . ', mode ' . $in['auth']
            . ', applications ' . implode('+', $in['apps']));

        if ($in['errors'] !== []) {
            Session::flash('office_addin_error', implode(' ', $in['errors']));
        } else {
            Session::flash('office_addin_success', t('Réglages de Claude dans Office enregistrés.'));
        }
        header('Location: /admin/connectors/office');
        exit;
    }

    /** Le manifeste à déposer dans le Centre d'administration Microsoft 365. */
    public function manifest(): void
    {
        AuthMiddleware::requirePermission('connectors.view');
        try {
            [$xml, $version] = OfficeManifest::current();
        } catch (\Throwable $e) {
            Session::flash('office_addin_error', $e->getMessage());
            header('Location: /admin/connectors/office');
            exit;
        }
        $host = (string)(parse_url((string)APP_URL, PHP_URL_HOST) ?: 'mia');
        $name = 'claude-mia-' . preg_replace('/[^a-z0-9.-]/i', '-', $host) . '-' . $version . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $xml;
        exit;
    }

    /**
     * Bouton « Tester » : ce que MIA peut vérifier d'elle-même, sans Excel ni
     * compte Microsoft. Chaque point rend ok | warn | fail et une phrase.
     */
    public function testAdmin(): void
    {
        AuthMiddleware::requirePermission('connectors.edit');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['checks' => self::checks()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @return list<array{status:string, label:string, detail:string}> */
    private static function checks(): array
    {
        $out = [];
        $out[] = OfficeAddin::isEnabled()
            ? ['status' => 'ok', 'label' => t('Passerelle activée'), 'detail' => '']
            : ['status' => 'warn', 'label' => t('Passerelle désactivée'), 'detail' => t('Rien ne passe tant qu’elle n’est pas activée.')];
        $out[] = OfficeAddin::hasAnthropicKey()
            ? ['status' => 'ok', 'label' => t('Clé Anthropic de l’instance'), 'detail' => '']
            : ['status' => 'fail', 'label' => t('Aucune clé Anthropic'), 'detail' => t('Ajoutez-la dans Modèles IA → Fournisseurs et modèles.')];
        $out[] = OfficeAddin::gatewayIsHttps()
            ? ['status' => 'ok', 'label' => t('Adresse en HTTPS'), 'detail' => OfficeAddin::gatewayUrl()]
            : ['status' => 'fail', 'label' => t('Adresse sans HTTPS'), 'detail' => t('L’add-in n’accepte qu’une passerelle en HTTPS : vérifiez APP_URL ({url}).', ['url' => OfficeAddin::gatewayUrl()])];

        // Préflight CORS par l'adresse publique : prouve que la requête arrive
        // jusqu'à MIA (proxy, HTTPS) et repart avec l'en-tête attendu.
        $preflight = self::probePreflight(OfficeAddin::gatewayUrl() . '/v1/messages');
        $out[] = $preflight;

        if (OfficeAddin::authMode() !== OfficeAddin::AUTH_TOKEN) {
            if (!OfficeAddin::entraConfigured()) {
                $out[] = ['status' => 'warn', 'label' => t('Connexion Microsoft incomplète'), 'detail' => t('Renseignez l’ID d’application et l’ID de l’annuaire (GUID).')];
            } else {
                // Lecture des clés de l'annuaire : un annuaire inexistant n'en rend aucune.
                $count = EntraJwt::probe(OfficeAddin::tenant());
                $out[] = $count > 0
                    ? ['status' => 'ok', 'label' => t('Annuaire Microsoft joint'), 'detail' => t('{n} clés de signature lues.', ['n' => $count])]
                    : ['status' => 'fail', 'label' => t('Annuaire Microsoft introuvable'), 'detail' => t('Vérifiez l’ID de l’annuaire : Microsoft ne renvoie aucune clé pour lui.')];
            }
        }
        if (OfficeAddin::tokensAllowed()) {
            $out[] = ['status' => 'ok', 'label' => t('Jetons personnels acceptés'), 'detail' => ''];
        }
        return $out;
    }

    private static function probePreflight(string $url): array
    {
        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'OPTIONS',
            CURLOPT_HTTPHEADER     => [
                'Origin: ' . OfficeAddin::ALLOWED_ORIGIN,
                'Access-Control-Request-Method: POST',
                'Access-Control-Request-Headers: authorization, content-type, anthropic-version',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($status === 0) {
            return ['status' => 'warn', 'label' => t('Adresse publique non jointe depuis le serveur'), 'detail' => t('Ce n’est pas forcément un problème (certains réseaux n’autorisent pas un serveur à se joindre lui-même) : testez depuis Excel. Détail : {e}', ['e' => $err])];
        }
        if (($headers['access-control-allow-origin'] ?? '') === OfficeAddin::ALLOWED_ORIGIN && $status === 204) {
            return ['status' => 'ok', 'label' => t('Passerelle jointe par son adresse publique'), 'detail' => t('Réponse CORS correcte pour pivot.claude.ai.')];
        }
        return ['status' => 'fail', 'label' => t('Réponse inattendue de l’adresse publique'), 'detail' => t('Statut {s} : un proxy intercepte peut-être /office.', ['s' => $status])];
    }

    // ================================================================ Réglages de la personne

    /**
     * Carte de la page Comptes connectés, null si elle n'a pas lieu d'être
     * (passerelle coupée, ou droit absent).
     */
    public static function cardFor(?array $user): ?array
    {
        if ($user === null || !OfficeAddin::isEnabled() || !Role::userCan($user, 'office_addin.use')) {
            return null;
        }
        $token = null;
        if (OfficeAddin::tokensAllowed()) {
            try {
                $token = OfficeAddinToken::forUser((int)$user['id']);
            } catch (\Throwable $e) {
                $token = null;
            }
        }
        try {
            $usage = UsageLog::officeSummary(date('Y-m-01'), date('Y-m-t'), (int)$user['id']);
        } catch (\Throwable $e) {
            $usage = null;
        }
        // L'assistant d'aide guide pas à pas, captures à l'appui : la carte
        // ouvre une conversation avec lui. Global, il demande seulement le
        // droit d'utiliser les assistants.
        $help = Role::userCan($user, 'assistants.use') ? DefaultAssistant::profileId() : null;
        return [
            'usage'   => $usage,
            'help_profile_id' => $help,
            'gateway' => OfficeAddin::gatewayUrl(),
            'apps'    => OfficeAddin::apps(),
            'entra'   => OfficeAddin::entraAllowed(),
            'tokens'  => OfficeAddin::tokensAllowed(),
            'token'   => $token,
            'models'  => array_values(OfficeGatewayController::modelsFor($user)),
        ];
    }

    /** Génère (ou remplace) le jeton personnel ; il n'est montré qu'une fois. */
    public function issueToken(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        if (!OfficeAddin::isEnabled() || !OfficeAddin::tokensAllowed() || !Role::userCan($user, 'office_addin.use')) {
            $this->settingsReply(false, t('Les jetons personnels de Claude dans Office ne sont pas proposés.'), null);
            return;
        }
        $token = OfficeAddinToken::issue((int)$user['id']);
        error_log('[Claude dans Office] jeton personnel généré pour #' . (int)$user['id']);
        $this->settingsReply(true, t('Jeton généré. Copiez-le maintenant : il ne sera plus affiché.'), $token);
    }

    public function revokeToken(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        OfficeAddinToken::revoke((int)$user['id']);
        error_log('[Claude dans Office] jeton personnel révoqué pour #' . (int)$user['id']);
        $this->settingsReply(true, t('Jeton révoqué : l’add-in qui l’utilisait ne répondra plus.'), null);
    }

    private function settingsReply(bool $ok, string $message, ?string $token): void
    {
        $wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code($ok ? 200 : 403);
            echo json_encode(['ok' => $ok, 'message' => $message, 'token' => $token], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Sans script : le jeton passe par la session le temps d'un affichage.
        Session::set($ok ? 'settings_success' : 'settings_error', $message);
        if ($token !== null) {
            Session::set('office_addin_new_token', $token);
        }
        header('Location: /settings/connections#office-addin');
        exit;
    }
}
