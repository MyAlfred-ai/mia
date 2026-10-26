<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OneDrive retombe sur l'application Microsoft du LOGIN quand il n'a pas
 * d'inscription dédiée.
 *
 * Le problème résolu : OneDrive exigeait sa PROPRE inscription Azure (page
 * Sources synchronisées), alors que les quatre autres connecteurs Microsoft
 * réutilisent celle de la connexion. Une organisation qui avait déjà déclaré
 * son application pour Outlook devait en créer une seconde, avec sa propre
 * URI, son secret et son consentement, pour un seul connecteur.
 *
 * Règle figée ici : l'inscription dédiée l'emporte dès que son ID
 * d'application est saisi — même incomplète (jamais de bascule silencieuse
 * vers une autre application) ; sinon, application, annuaire et URI du login.
 * S'y ajoutent les deux écarts corrigés dans la foulée : la cause AADSTS lue
 * dans le flux natif (application mobile), et celle d'un échange de code
 * refusé par le endpoint token.
 */
// Absente de tests/bootstrap.php : le flux natif (sans session) en a besoin.
require_once ROOT . '/src/Auth/NativeOauthState.php';

final class OneDriveLoginAppFallbackTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cacheAvant = null;
    private bool $chargeAvant = false;

    protected function setUp(): void
    {
        $this->cacheAvant  = (new \ReflectionProperty(Settings::class, 'cache'))->getValue();
        $this->chargeAvant = (bool)(new \ReflectionProperty(Settings::class, 'loaded'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Settings::class, 'cache'))->setValue(null, $this->cacheAvant ?? []);
        (new \ReflectionProperty(Settings::class, 'loaded'))->setValue(null, $this->chargeAvant);
    }

    /** Pose les réglages en mémoire (aucune base). @param array<string, mixed> $valeurs */
    private static function reglages(array $valeurs): void
    {
        (new \ReflectionProperty(Settings::class, 'cache'))->setValue(null, $valeurs);
        (new \ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
    }

    /** Application du login renseignée, aucune inscription dédiée. */
    private static function loginSeul(): void
    {
        self::reglages([
            SsoRegistry::SETTING_MS_CLIENT => 'client-login-mia',
            SsoRegistry::SETTING_MS_SECRET => Encryption::encrypt('secret-login'),
            SsoRegistry::SETTING_MS_TENANT => '11111111-2222-3333-4444-555555555555',
        ]);
    }

    /** @return array<string, string> */
    private static function parametres(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    // ---- Choix de l'application ---------------------------------------------

    public function testSansInscriptionDedieeOneDriveUtiliseLApplicationDuLogin(): void
    {
        self::loginSeul();

        $this->assertTrue(MicrosoftOAuth::usesLoginApp());
        $this->assertTrue(MicrosoftOAuth::isConfigured());
        $this->assertSame('client-login-mia', MicrosoftOAuth::clientId());
        $this->assertSame('secret-login', MicrosoftOAuth::clientSecret());
        $this->assertSame('11111111-2222-3333-4444-555555555555', MicrosoftOAuth::tenant());
        // L'URI du login, déjà déclarée : aucune URI de plus dans le portail.
        $this->assertSame(SsoRegistry::redirectUri(), MicrosoftOAuth::redirectUri());

        $url = MicrosoftOAuth::getAuthUrlNative(42);
        $q   = self::parametres($url);
        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/', $url
        );
        $this->assertSame('client-login-mia', $q['client_id'] ?? null);
        $this->assertSame(SsoRegistry::redirectUri(), $q['redirect_uri'] ?? null);
        $this->assertSame('select_account', $q['prompt'] ?? null);
        $this->assertStringStartsWith(MicrosoftOAuth::STATE_PREFIX, (string)($q['state'] ?? ''));
    }

    public function testLInscriptionDedieeLEmporteDesQueSonIdEstSaisi(): void
    {
        self::reglages([
            SsoRegistry::SETTING_MS_CLIENT    => 'client-login-mia',
            SsoRegistry::SETTING_MS_SECRET    => Encryption::encrypt('secret-login'),
            SsoRegistry::SETTING_MS_TENANT    => '11111111-2222-3333-4444-555555555555',
            MicrosoftOAuth::SETTING_CLIENT    => 'client-onedrive-mia',
            MicrosoftOAuth::SETTING_SECRET    => Encryption::encrypt('secret-onedrive'),
        ]);

        $this->assertTrue(MicrosoftOAuth::usesDedicatedApp());
        $this->assertFalse(MicrosoftOAuth::usesLoginApp());
        $this->assertTrue(MicrosoftOAuth::isConfigured());
        $this->assertSame('client-onedrive-mia', MicrosoftOAuth::clientId());
        $this->assertSame('secret-onedrive', MicrosoftOAuth::clientSecret());
        // Annuaire dédié non saisi : « common », jamais celui du login.
        $this->assertSame(MicrosoftOAuth::DEFAULT_TENANT, MicrosoftOAuth::tenant());
        $this->assertSame(MicrosoftOAuth::dedicatedRedirectUri(), MicrosoftOAuth::redirectUri());
        $this->assertStringEndsWith('/settings/onedrive/callback', MicrosoftOAuth::dedicatedRedirectUri());
    }

    public function testUneInscriptionDedieeIncompleteNeBasculeJamaisEnSilence(): void
    {
        // ID dédié saisi, secret oublié : OneDrive est INDISPONIBLE, même si
        // l'application du login est parfaitement configurée. Basculer
        // ferait demander le consentement sur une application que
        // l'administrateur ne croit pas utiliser.
        self::reglages([
            SsoRegistry::SETTING_MS_CLIENT => 'client-login-mia',
            SsoRegistry::SETTING_MS_SECRET => Encryption::encrypt('secret-login'),
            MicrosoftOAuth::SETTING_CLIENT => 'client-onedrive-mia',
        ]);

        $this->assertTrue(MicrosoftOAuth::usesDedicatedApp());
        $this->assertFalse(MicrosoftOAuth::isConfigured());
        $this->assertSame('client-onedrive-mia', MicrosoftOAuth::clientId());
    }

    public function testRienDeConfigureLaisseOneDriveIndisponible(): void
    {
        self::reglages([]);
        $this->assertTrue(MicrosoftOAuth::usesLoginApp());
        $this->assertFalse(MicrosoftOAuth::isConfigured());
    }

    public function testUnSecretIllisibleRendUneApplicationNonConfigureeSansLever(): void
    {
        self::reglages([
            SsoRegistry::SETTING_MS_CLIENT => 'client-login-mia',
            // Chiffré avec une AUTRE clé : IV valide, contenu indéchiffrable.
            SsoRegistry::SETTING_MS_SECRET => base64_encode(str_repeat('i', 16) . base64_encode('autre-cle')),
        ]);
        $this->assertSame('', MicrosoftOAuth::clientSecret());
        $this->assertFalse(MicrosoftOAuth::isConfigured());
    }

    // ---- Permissions listées sur /admin/sso ---------------------------------

    public function testFilesReadAllEstNommeSurLApplicationDuLoginSeulementQuandElleSert(): void
    {
        self::loginSeul();
        $scopes = ConnectorRegistry::delegatedScopes('microsoft');
        $this->assertSame(['Files.Read.All'], $scopes['OneDrive'] ?? null);

        self::reglages([MicrosoftOAuth::SETTING_CLIENT => 'client-onedrive-mia']);
        $this->assertArrayNotHasKey(
            'OneDrive',
            ConnectorRegistry::delegatedScopes('microsoft'),
            'Avec son inscription dédiée, Files.Read.All se déclare sur ELLE, pas sur celle du login.'
        );
    }

    // ---- Cause d'un échange de code refusé ----------------------------------

    public function testUnEchangeRefuseDitSaCause(): void
    {
        $this->assertStringContainsString('secret', mb_strtolower((string)MicrosoftOAuth::exchangeFailureMessage([
            'error'             => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided.',
        ])));
        $this->assertStringContainsString('expiré', (string)MicrosoftOAuth::exchangeFailureMessage([
            'error'             => 'invalid_client',
            'error_description' => 'AADSTS7000222: The provided client secret keys are expired.',
        ]));
        // L'URI citée est celle du flux en cause.
        $this->assertStringContainsString('https://mia.exemple.fr/auth/callback', (string)MicrosoftOAuth::exchangeFailureMessage([
            'error'             => 'invalid_grant',
            'error_description' => 'AADSTS50011: The redirect URI does not match.',
        ], 'https://mia.exemple.fr/auth/callback'));
        // Jeton d'accès sans jeton de rafraîchissement : offline_access manque.
        $this->assertStringContainsString('offline_access', (string)MicrosoftOAuth::exchangeFailureMessage([
            'access_token' => 'jeton',
        ]));
        // Rien d'exploitable (réseau) : l'appelant garde son message générique.
        $this->assertNull(MicrosoftOAuth::exchangeFailureMessage([]));
        $this->assertNull(MicrosoftOAuth::exchangeFailureMessage(['error' => 'temporarily_unavailable']));
    }

    public function testLesDeuxSoclesMemorisentLaCauseEtLaRemettentAZero(): void
    {
        foreach (['src/Auth/MicrosoftOAuth.php', 'src/Auth/MicrosoftUserOAuth.php'] as $f) {
            $source = self::lire($f);
            $this->assertStringContainsString('public static function lastError(): ?string', $source, $f);
            $this->assertMatchesRegularExpression('/(self|MicrosoftOAuth)::exchangeFailureMessage\(\$token/', $source, $f);
            // Remise à zéro au début de chaque retour (web et natif).
            $this->assertSame(2, substr_count($source, 'self::$lastError = null;'), $f);
        }
    }

    // ---- Câblage des retours ------------------------------------------------

    public function testLeRetourDuLoginRouteLeStateOneDriveAvantLesRefus(): void
    {
        $source = self::lire('src/Controllers/AuthController.php');
        $route  = strpos($source, 'str_starts_with((string)$state, MicrosoftOAuth::STATE_PREFIX)');
        $refus  = strpos($source, "\$refus = (string)(\$_GET['error'] ?? '');");
        $this->assertNotFalse($route, 'Le state onedrive_ doit être routé par le callback du login.');
        $this->assertNotFalse($refus);
        $this->assertLessThan($refus, $route, 'Un refus OneDrive partirait sinon vers la page de connexion.');
        $this->assertStringContainsString('->oneDriveCallback();', substr($source, $route, 200));
    }

    public function testLesRetoursAffichentLaCauseAuLieuDUnEchecGenerique(): void
    {
        $source = self::lire('src/Controllers/UserSettingsController.php');

        // Flux natif (application mobile) : la cause AADSTS, plus « annulée ».
        $natif = substr($source, (int)strpos($source, 'private function nativeOauthCallback('), 2600);
        $this->assertStringContainsString('$oauth::authErrorMessage($desc)', $natif);
        $this->assertStringContainsString('$oauth::lastError()', $natif);

        // Flux web : connecteurs du socle et OneDrive.
        $web = substr($source, (int)strpos($source, 'private function connectorCallback('), 3000);
        $this->assertStringContainsString('$oauth::lastError()', $web);
        $this->assertStringContainsString('MicrosoftOAuth::lastError() ??', $source);
    }

    public function testLaPageDesSourcesDitQuelleApplicationSertEtGardeLUriDediee(): void
    {
        $tpl = self::lire('templates/admin/knowledge-bases-sync.php');
        $this->assertStringContainsString('MicrosoftOAuth::usesDedicatedApp()', $tpl);
        $this->assertStringContainsString('id="onedriveAppStatus"', $tpl);
        // La marche à suivre décrit l'inscription DÉDIÉE : son URI, pas celle
        // de l'application retenue (qui peut être celle du login).
        $this->assertStringContainsString('MicrosoftOAuth::dedicatedRedirectUri()', $tpl);
        $this->assertStringNotContainsString('MicrosoftOAuth::redirectUri()', $tpl);
    }

    public function testLesGuidesDecriventLeRepli(): void
    {
        $this->assertStringContainsString('inscription dédiée', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString(
            'Files.Read.All',
            self::lire('docs/user/admin/connecteurs/autoriser-mia-dans-microsoft-entra-id.md')
        );
    }
}
