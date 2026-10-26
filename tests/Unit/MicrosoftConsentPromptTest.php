<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Connecteurs Microsoft : la demande d'autorisation ne force JAMAIS l'écran de
 * consentement (`prompt=consent`).
 *
 * Le défaut corrigé (constaté chez un client) : Outlook, le calendrier Outlook,
 * Microsoft To Do et OneDrive envoyaient `prompt=consent`. Entra bloque cet
 * écran pour toute personne qui n'a pas le droit de consentir elle-même
 * (réglage par défaut de Microsoft, consentement utilisateur interdit) : elle
 * voyait « Approbation de l'administrateur requise » (AADSTS90094) alors même
 * qu'un administrateur avait accordé le consentement pour toute
 * l'organisation. Le compte se connectait à MIA, les droits étaient accordés,
 * et ni les mails ni l'agenda ne se reliaient.
 *
 * Ces tests construisent la VRAIE adresse d'autorisation de chaque flux (sans
 * base : les réglages sont posés en mémoire) et lisent son paramètre `prompt`.
 */
// Absente de tests/bootstrap.php : le flux natif (sans session) en a besoin.
require_once ROOT . '/src/Auth/NativeOauthState.php';

final class MicrosoftConsentPromptTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cacheAvant = null;
    private bool $chargeAvant = false;

    protected function setUp(): void
    {
        $cache = new \ReflectionProperty(Settings::class, 'cache');
        $loaded = new \ReflectionProperty(Settings::class, 'loaded');
        $this->cacheAvant = $cache->getValue();
        $this->chargeAvant = (bool)$loaded->getValue();
        $cache->setValue(null, [
            SsoRegistry::SETTING_MS_CLIENT     => 'client-login-mia',
            SsoRegistry::SETTING_MS_TENANT     => '11111111-2222-3333-4444-555555555555',
            MicrosoftOAuth::SETTING_CLIENT     => 'client-onedrive-mia',
            MicrosoftOAuth::SETTING_TENANT     => '11111111-2222-3333-4444-555555555555',
        ]);
        $loaded->setValue(null, true);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Settings::class, 'cache'))->setValue(null, $this->cacheAvant ?? []);
        (new \ReflectionProperty(Settings::class, 'loaded'))->setValue(null, $this->chargeAvant);
    }

    /** Paramètres de l'adresse d'autorisation. @return array<string, string> */
    private static function parametres(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    /** @return array<string, array{class-string}> */
    public static function connecteurs(): array
    {
        return [
            'Outlook'            => [MicrosoftOutlookOAuth::class],
            'Calendrier Outlook' => [MicrosoftCalendarOAuth::class],
            'Microsoft To Do'    => [MicrosoftTodoOAuth::class],
            'Microsoft Teams'    => [MicrosoftTeamsOAuth::class],
        ];
    }

    #[DataProvider('connecteurs')]
    public function testUnConnecteurMicrosoftNeForcePasLEcranDeConsentement(string $classe): void
    {
        $url = $classe::getAuthUrlNative(42);
        $q = self::parametres($url);

        $this->assertStringStartsWith('https://login.microsoftonline.com/', $url);
        $this->assertSame('client-login-mia', $q['client_id'] ?? null, 'Application Azure du login.');
        $this->assertSame('select_account', $q['prompt'] ?? null);
        // Le jeton de rafraîchissement vient du scope, pas de l'écran.
        $this->assertContains('offline_access', explode(' ', (string)($q['scope'] ?? '')));
        $this->assertStringStartsWith($classe::STATE_PREFIX, (string)($q['state'] ?? ''));
    }

    public function testOneDriveNeForcePasNonPlusLEcranDeConsentement(): void
    {
        $q = self::parametres(MicrosoftOAuth::getAuthUrlNative(42));
        $this->assertSame('client-onedrive-mia', $q['client_id'] ?? null);
        $this->assertSame('select_account', $q['prompt'] ?? null);
        $this->assertContains('offline_access', explode(' ', (string)($q['scope'] ?? '')));
    }

    public function testAucunFluxMicrosoftNEcritConsentDansSonCode(): void
    {
        // Sur le CODE seul (les commentaires expliquent justement pourquoi
        // `consent` est écarté) : aucune chaîne 'consent' dans les flux
        // d'autorisation Microsoft, socle compris.
        $fichiers = [
            'src/Auth/MicrosoftUserOAuth.php', 'src/Auth/MicrosoftOutlookOAuth.php',
            'src/Auth/MicrosoftCalendarOAuth.php', 'src/Auth/MicrosoftTodoOAuth.php',
            'src/Auth/MicrosoftTeamsOAuth.php', 'src/Auth/MicrosoftOAuth.php',
            'src/Auth/Sso/MicrosoftSsoProvider.php',
        ];
        foreach ($fichiers as $f) {
            foreach (token_get_all((string)file_get_contents(ROOT . '/' . $f)) as $t) {
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $this->assertNotSame("'consent'", $t[1], "$f force l'écran de consentement.");
                }
            }
        }
    }

    public function testLesConnecteursGoogleGardentLeConsentementForce(): void
    {
        // Côté Google, c'est l'inverse : sans prompt=consent, Google ne renvoie
        // pas de jeton de rafraîchissement à une seconde connexion.
        $this->assertStringContainsString(
            "'prompt'                 => 'consent'",
            (string)file_get_contents(ROOT . '/src/Auth/GoogleUserOAuth.php')
        );
    }

    public function testLaDocumentationNeDecritPlusLeBlocage(): void
    {
        $entra = (string)file_get_contents(ROOT . '/docs/user/admin/connecteurs/autoriser-mia-dans-microsoft-entra-id.md');
        $this->assertStringNotContainsString('fait réafficher l\'écran d\'autorisation', $entra);
        $this->assertStringContainsString('microsoft-sans-consentement-force', (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md'));
    }
}
