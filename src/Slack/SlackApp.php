<?php

declare(strict_types=1);

/**
 * Identifiants de l'application Slack — SOURCE UNIQUE (même rôle que
 * `SsoRegistry` pour Google et Microsoft, `MicrosoftOAuth` pour OneDrive).
 *
 * ── POURQUOI UNE CLASSE À PART ─────────────────────────────────────────────
 * Slack n'est pas un moyen de CONNEXION à MIA : aucune ligne de `SsoRegistry`
 * ne lui correspond, et y ajouter une section ferait apparaître un bouton
 * « Se connecter avec Slack » qui n'existe pas. Ses identifiants vivent donc
 * ici, saisis sur leur propre écran (Connecteurs → « Configurer l'application
 * Slack »), exactement comme l'application Azure de OneDrive vit dans
 * « Sources synchronisées ».
 *
 * ── EN BASE, JAMAIS DANS L'ENVIRONNEMENT ───────────────────────────────────
 * Le chat s'exécute dans le conteneur `worker`, qui ne voit pas les variables
 * d'environnement de `app` : des identifiants lus dans une constante y
 * seraient absents et le connecteur ne serait JAMAIS exposé au modèle, sans
 * une ligne de log. C'est la panne muette déjà payée côté Google, et la raison
 * pour laquelle tout vit en base (`settings`), secret chiffré par `Encryption`.
 *
 * L'URI de redirection est celle du LOGIN (`/auth/callback`) : c'est le seul
 * point d'entrée OAuth de l'application, et `AuthController::callback()` route
 * le retour sur le préfixe de `state`. Une seule URL à déclarer dans la
 * configuration de l'application Slack.
 */
final class SlackApp
{
    /** Identifiant public de l'application Slack (« 1234567890.9876543210 »). */
    public const SETTING_CLIENT_ID = 'slack_client_id';

    /** Secret de l'application, CHIFFRÉ en base (AES-256, Encryption). */
    public const SETTING_CLIENT_SECRET = 'slack_client_secret_enc';

    /** Racine de l'API Web Slack — un seul endroit à bouger. */
    public const API_BASE = 'https://slack.com/api';

    /** Le prérequis technique est-il en place ? */
    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function clientId(): string
    {
        return trim((string)Settings::get(self::SETTING_CLIENT_ID, ''));
    }

    /**
     * Secret déchiffré, '' si absent OU indéchiffrable (clé de chiffrement
     * changée) : `Encryption::decrypt` ne lève jamais, il rend ''.
     */
    public static function clientSecret(): string
    {
        $chiffre = (string)Settings::get(self::SETTING_CLIENT_SECRET, '');
        return $chiffre === '' ? '' : Encryption::decrypt($chiffre);
    }

    /** Enregistre l'identifiant public (vide = effacé). */
    public static function setClientId(string $id): void
    {
        Settings::set(self::SETTING_CLIENT_ID, trim($id));
    }

    /**
     * Enregistre le secret. Vide = CONSERVÉ : le formulaire ne réaffiche jamais
     * un secret enregistré, donc un champ laissé vide signifie « ne touche à
     * rien ». L'écraser par le chiffré d'une chaîne vide est exactement le
     * piège déjà payé sur `saveApiKey`.
     */
    public static function setClientSecret(string $secret): void
    {
        $secret = trim($secret);
        if ($secret === '') {
            return;
        }
        Settings::set(self::SETTING_CLIENT_SECRET, Encryption::encrypt($secret));
    }

    /** Retrait explicite du secret (case « supprimer » de l'écran). */
    public static function clearClientSecret(): void
    {
        Settings::set(self::SETTING_CLIENT_SECRET, '');
    }

    /**
     * URI de redirection : celle du login, seule URL de retour OAuth de
     * l'application. À déclarer telle quelle dans « OAuth & Permissions » de
     * l'application Slack — Slack refuse tout retour sur une URL non déclarée
     * (`bad_redirect_uri`), sans rien dire de plus.
     */
    public static function redirectUri(): string
    {
        return SsoRegistry::redirectUri();
    }

    /** Longueur maximale du nom d'une application Slack (`display_information.name`). */
    public const MANIFEST_NAME_MAX = 35;

    /**
     * Manifeste d'application à coller dans Slack (« Create New App → From a
     * manifest ») — forme PURE. Il déclare d'un coup l'URL de retour et les
     * autorisations UTILISATEUR, les deux saisies où l'on se trompe : une URL
     * qui diffère d'un caractère (`bad_redirect_uri`), des autorisations
     * posées en « Bot Token Scopes » au lieu de « User Token Scopes ».
     *
     * Schéma relu sur docs.slack.dev/reference/app-manifest le 2026-10-01 :
     * seul `display_information.name` est requis (35 caractères au plus) ;
     * `oauth_config.redirect_urls` et `oauth_config.scopes.user` sont des
     * listes de chaînes. Aucun scope de bot, aucun réglage (`settings`) : la
     * rotation des jetons reste au choix de l'administrateur, MIA gère les deux.
     *
     * @param list<string> $userScopes
     * @return array<string, mixed>
     */
    public static function manifest(string $appName, string $redirectUri, array $userScopes): array
    {
        $nom = trim(preg_replace('/\s+/u', ' ', $appName) ?? '');
        $nom = $nom === '' ? 'MIA' : mb_substr($nom, 0, self::MANIFEST_NAME_MAX);

        return [
            // Le nom reste celui du produit ; la description, lue par les
            // utilisateurs sur l'écran d'autorisation de Slack, suit la langue
            // de l'administrateur qui copie le manifeste.
            'display_information' => [
                'name'        => $nom,
                'description' => t('Assistant IA : recherche, lecture et publication approuvée, au nom de chaque utilisateur.'),
            ],
            'oauth_config' => [
                'redirect_urls' => [$redirectUri],
                'scopes'        => ['user' => array_values($userScopes)],
            ],
        ];
    }

    /**
     * Éprouve les identifiants SANS aller-retour navigateur, sur le même
     * principe que les boutons « Tester » de Organisation → Authentification :
     * si le serveur d'autorisation se plaint de la DEMANDE, c'est qu'il a
     * accepté le CLIENT.
     *
     * On présente donc un code d'autorisation volontairement faux :
     *   - `invalid_code` → les identifiants sont bons (Slack a validé
     *     l'application avant de regarder le code) ;
     *   - `invalid_client_id` / `bad_client_secret` → ils ne le sont pas, et
     *     le message nomme LEQUEL des deux ;
     *   - `bad_redirect_uri` → l'URL de retour n'est pas déclarée dans
     *     l'application : c'est l'oubli le plus fréquent, et celui qui donne
     *     ensuite une erreur en plein flux de connexion.
     *
     * @return array{ok:bool,message:string}
     */
    public static function testCredentials(string $clientId, string $clientSecret): array
    {
        $clientId     = trim($clientId);
        $clientSecret = trim($clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'message' => t('Renseignez l\'identifiant ET le secret de l\'application.')];
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => 'mia-test-' . bin2hex(random_bytes(4)),
                'redirect_uri'  => self::redirectUri(),
            ]),
            'timeout'       => 12,
            'ignore_errors' => true,
        ]]);
        $json = @file_get_contents(self::API_BASE . '/oauth.v2.access', false, $ctx);
        if ($json === false) {
            return ['ok' => false, 'message' => t('Slack injoignable depuis ce serveur — réessayez dans un instant.')];
        }
        $data  = json_decode($json, true);
        $error = is_array($data) ? (string)($data['error'] ?? '') : '';

        return match ($error) {
            'invalid_code', 'code_already_used' => [
                'ok' => true,
                'message' => t('Identifiants valides. Slack a reconnu l\'application et n\'a refusé que le code de test.'),
            ],
            'invalid_client_id' => [
                'ok' => false,
                'message' => t('Identifiant d\'application (Client ID) inconnu de Slack.'),
            ],
            'bad_client_secret' => [
                'ok' => false,
                'message' => t('Secret d\'application (Client Secret) refusé par Slack.'),
            ],
            'bad_redirect_uri' => [
                'ok' => false,
                'message' => t('L\'URL de redirection n\'est pas déclarée dans l\'application Slack. Ajoutez exactement « {url} » dans OAuth & Permissions → Redirect URLs.', [
                    'url' => self::redirectUri(),
                ]),
            ],
            default => [
                'ok' => false,
                'message' => $error !== ''
                    ? t('Slack a refusé la demande ({code}).', ['code' => $error])
                    : t('Slack a refusé la demande.'),
            ],
        };
    }
}
