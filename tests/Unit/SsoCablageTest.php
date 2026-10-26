<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage du SSO — gardes au niveau des sources, dans l'esprit
 * d'AutosaveContractTest.
 *
 * Ces invariants ne se voient dans aucun test fonctionnel : ils protègent des
 * pannes qui ne surviennent qu'en production, et qui sont muettes (un
 * connecteur jamais exposé, un retour OAuth attribué au mauvais flux, une
 * classe absente de la liste de `require` côté CLI seulement).
 */
final class SsoCablageTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $absolu = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($absolu, 'Fichier attendu : ' . $chemin);
        return (string)file_get_contents($absolu);
    }

    // ---- Chargement (pas d'autoloader dans ce projet) ----------------------

    public function testLesClassesSsoSontChargeesParLeSocleCommun(): void
    {
        // config/classes.php est partagé par public/index.php ET
        // scripts/_cli_bootstrap.php : une classe oubliée ici ne planterait que
        // dans le worker, en production.
        $socle = self::lire('config/classes.php');

        foreach ([
            'src/Auth/Sso/SsoProvider.php',
            'src/Auth/Sso/SsoRegistry.php',
            'src/Auth/Sso/GoogleSsoProvider.php',
            'src/Auth/Sso/MicrosoftSsoProvider.php',
            'src/Models/UserIdentity.php',
            'src/Security/LoginRateLimit.php',
        ] as $fichier) {
            $this->assertStringContainsString($fichier, $socle, 'Classe absente du socle commun : ' . $fichier);
        }
    }

    public function testLInterfaceEstChargeeAvantSesImplementations(): void
    {
        // `implements` est résolu à la DÉFINITION de la classe, pas à l'appel :
        // charger une implémentation avant son interface est une erreur fatale.
        $socle = self::lire('config/classes.php');

        $this->assertLessThan(
            strpos($socle, 'src/Auth/Sso/GoogleSsoProvider.php'),
            strpos($socle, 'src/Auth/Sso/SsoProvider.php'),
            'SsoProvider doit être requis avant GoogleSsoProvider.'
        );
        $this->assertLessThan(
            strpos($socle, 'src/Auth/Sso/MicrosoftSsoProvider.php'),
            strpos($socle, 'src/Auth/Sso/SsoProvider.php'),
            'SsoProvider doit être requis avant MicrosoftSsoProvider.'
        );
    }

    // ---- Routes et navigation ----------------------------------------------

    public function testLesRoutesDAuthentificationSontDeclarees(): void
    {
        $routes = self::lire('public/index.php');

        $this->assertStringContainsString("'/admin/sso'", $routes);
        $this->assertStringContainsString("'/admin/sso/settings'", $routes);
        $this->assertStringContainsString("'/admin/sso/test-credentials'", $routes);
    }

    public function testLaPageEstAtteignableDepuisLaNavigation(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');

        $this->assertStringContainsString("\$navSub('sso',", $nav, 'Page injoignable : aucun lien dans la navigation.');
        $this->assertSame('/admin/sso', AdminAccess::PAGE_HREFS['sso']);
        $this->assertContains('sso', AdminAccess::ENTRY_PAGES['organisation'], "Sans 'sso' dans sa rubrique, Organisation se referme sur la page elle-même.");
    }

    // ---- Fin des constantes en dur -----------------------------------------

    public function testAucuneBriqueOAuthNeLitPlusLesConstantesGoogle(): void
    {
        // Les identifiants vivent en base (avec repli .env dans SsoRegistry, le
        // SEUL endroit qui a le droit de lire ces constantes). Une lecture
        // directe résiduelle rendrait le worker aveugle aux identifiants
        // saisis en administration — le bug que ce changement corrige.
        foreach ([
            'src/Auth/GoogleOAuth.php',
            'src/Auth/GoogleDriveOAuth.php',
            'src/Auth/GoogleGmailOAuth.php',
            'src/Auth/GoogleCalendarOAuth.php',
        ] as $fichier) {
            $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', self::lire($fichier));

            $this->assertDoesNotMatchRegularExpression(
                '/\bGOOGLE_CLIENT_SECRET\b/',
                (string)$source,
                $fichier . ' lit encore GOOGLE_CLIENT_SECRET au lieu de SsoRegistry.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bGOOGLE_REDIRECT_URI\b/',
                (string)$source,
                $fichier . ' lit encore GOOGLE_REDIRECT_URI au lieu de SsoRegistry.'
            );
        }
    }

    public function testLaPageDeConnexionNeCodePlusAucunFournisseurEnDur(): void
    {
        $login = self::lire('templates/auth/login.php');

        $this->assertStringNotContainsString(
            'GoogleOAuth::getAuthUrl',
            $login,
            'Le bouton Google était rendu même sans identifiants, et menait à un écran d\'erreur Google.'
        );
        $this->assertStringContainsString('SsoRegistry::loginProviders()', $login);
        $this->assertStringContainsString('$passwordEnabled', $login);
    }

    // ---- Démultiplexage du retour OAuth ------------------------------------

    public function testLeCallbackTraiteLesConnecteursAvantLaConnexion(): void
    {
        // Les trois connecteurs personnels partagent l'URI de redirection du
        // login. Si le SSO passait devant, un branchement de Drive ouvrirait
        // une session à la place.
        $source = self::lire('src/Controllers/AuthController.php');

        $positionSso = strpos($source, 'SsoRegistry::providerFromState');
        $this->assertIsInt($positionSso);

        foreach (['drive_', 'gmail_', 'gcal_'] as $prefixe) {
            $position = strpos($source, "str_starts_with((string)\$state, '" . $prefixe . "')");
            $this->assertIsInt($position, 'Aiguillage du connecteur ' . $prefixe . ' introuvable.');
            $this->assertLessThan(
                $positionSso,
                $position,
                'Le connecteur ' . $prefixe . ' doit être traité AVANT le SSO.'
            );
        }
    }

    public function testLaConnexionParMotDePasseEstRefuseeCoteServeurQuandElleEstDesactivee(): void
    {
        // Masquer le formulaire ne suffit pas : un POST forgé doit être refusé.
        $source = self::lire('src/Controllers/AuthController.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(!SsoRegistry::passwordEnabled\(\)\)/',
            $source,
            'Le serveur doit rester juge de la disponibilité du mot de passe.'
        );
    }

    public function testLAccesDeSecoursNeDependDAucunReglage(): void
    {
        // C'est le recours quand une connexion externe casse : le soumettre au
        // réglage « mot de passe » reviendrait à supprimer le filet.
        $source = self::lire('src/Controllers/AuthController.php');
        $debut  = strpos($source, 'public function localLogin');
        $fin    = strpos($source, 'private function emailLogin');
        $corps  = substr($source, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString('LOCAL_PASSWORD_HASH', $corps);
        $this->assertStringNotContainsString('passwordEnabled', $corps);
    }

    // ---- Migration ---------------------------------------------------------

    public function testLeBackfillIgnoreLesIdentifiantsSynthetiques(): void
    {
        // `local_admin` et `pw_…` n'ont jamais été des identités Google : les
        // reporter créerait des identités qui n'existent pas, et un futur
        // utilisateur Google portant ce sujet serait rattaché au mauvais compte.
        $migration = self::lire('database/migrations/062_sso_identites.sql');

        $this->assertStringContainsString("google_id <> 'local_admin'", $migration);
        $this->assertStringContainsString("google_id NOT LIKE 'pw\\_%'", $migration);
        $this->assertStringContainsString('UNIQUE KEY uq_ui_provider_subject (provider, subject)', $migration);
        $this->assertStringContainsString('ON DELETE CASCADE', $migration);
    }

    /**
     * Les connecteurs Microsoft du chat réutilisent l'inscription Azure du
     * LOGIN. Un annuaire Entra qui interdit le consentement utilisateur exige
     * que leurs permissions déléguées y soient DÉCLARÉES pour qu'un
     * administrateur puisse les accorder : l'écran doit donc les nommer.
     * Sans cela on configure « une application qui ne demande rien », et les
     * connecteurs échouent ensuite en AADSTS65001 sans que rien ne relie les
     * deux écrans.
     */
    public function testLEcranNommeLesPermissionsGraphDesConnecteurs(): void
    {
        $scopes = ConnectorRegistry::delegatedScopes('microsoft');
        $this->assertArrayHasKey('Outlook', $scopes);
        $this->assertContains('Mail.ReadWrite', $scopes['Outlook']);
        $this->assertArrayHasKey('Calendrier Outlook', $scopes);
        $this->assertContains('Calendars.ReadWrite', $scopes['Calendrier Outlook']);

        // Bruit écarté : offline_access et User.Read n'ont rien à faire dans
        // l'écran des autorisations d'API.
        foreach ($scopes as $liste) {
            $this->assertNotContains('offline_access', $liste);
            $this->assertNotContains('User.Read', $liste);
        }

        // OneDrive n'y figure que lorsqu'il retombe sur cette application
        // (aucune inscription dédiée) : vérifié, réglages posés, par
        // OneDriveLoginAppFallbackTest.

        $vue = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/admin/sso.php');
        $this->assertStringContainsString('$microsoftDelegatedScopes', $vue);
        $this->assertStringContainsString('consentement administrateur', $vue);
        $this->assertStringContainsString(
            '$microsoftDelegatedScopes = ConnectorRegistry::delegatedScopes(\'microsoft\');',
            (string)file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AdminController.php'),
            'La vue /admin/sso ne recevrait pas la liste.'
        );
    }

    // ---- Enregistrement section par section --------------------------------

    /**
     * La page d'authentification n'est PAS auto-enregistrée : chaque carte est
     * son propre formulaire, avec son bouton « Enregistrer » et un champ
     * `section`. Des identifiants de connexion ne partent jamais à moitié
     * saisis, et couper un moyen de connexion est un geste qu'on assume d'un
     * clic — c'est la demande produit, et c'est ce que ce test fige.
     */
    public function testLaPageDAuthentificationSEnregistreSectionParSection(): void
    {
        $vue = self::lire('templates/admin/sso.php');

        $this->assertStringNotContainsString('data-autosave', $vue, 'Aucun enregistrement automatique sur cette page.');
        $this->assertStringNotContainsString('autosave-hint.php', $vue, 'La mention « enregistré automatiquement » y mentirait.');

        $sections = SsoRegistry::SECTIONS;
        $this->assertSame(
            count($sections),
            preg_match_all('#<form method="POST" action="/admin/sso/settings"#', $vue),
            'Un formulaire par section, tous sur la route d\'enregistrement.'
        );
        foreach ([
            'SECTION_GOOGLE', 'SECTION_MICROSOFT', 'SECTION_PASSWORD', 'SECTION_DOMAINS', 'SECTION_LOGIN',
        ] as $constante) {
            $this->assertStringContainsString(
                '<input type="hidden" name="section" value="<?= SsoRegistry::' . $constante . ' ?>">',
                $vue,
                "Le formulaire de $constante doit dire quelle section il poste."
            );
        }

        preg_match_all('#<button[^>]*type="submit"[^>]*>(.*?)</button>#s', $vue, $boutons);
        // Le libellé est traduit (te('Enregistrer')) : on lit la clé, pas le
        // rendu — strip_tags() effacerait la balise PHP et le texte avec.
        $libelles = array_map(
            static fn(string $l): string => trim(strip_tags(preg_replace("/<\?=\s*te\('([^']*)'\)\s*\?>/", '$1', $l) ?? $l)),
            $boutons[1]
        );
        $this->assertSame(
            array_fill(0, count($sections), 'Enregistrer'),
            $libelles,
            'Chaque section porte son bouton « Enregistrer », et rien d\'autre ne soumet.'
        );
    }

    /**
     * Un formulaire ne poste QUE sa section. Le handler doit donc refuser une
     * section inconnue et, pour la garde anti-verrouillage, compléter l'état
     * posté par l'état ENREGISTRÉ des deux autres méthodes — lire leurs cases
     * dans un POST qui ne les porte pas les ferait passer pour décochées :
     * Google enregistré = mot de passe et Microsoft éteints, en silence.
     */
    public function testLeHandlerNeToucheQuALaSectionPostee(): void
    {
        $source = self::lire('src/Controllers/AdminController.php');
        $debut  = strpos($source, 'public function saveSso');
        $fin    = strpos($source, 'public function testSsoCredentials');
        $corps  = substr($source, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString('in_array($section, SsoRegistry::SECTIONS, true)', $corps);
        $this->assertStringContainsString(
            'SsoRegistry::mergeDesiredState(SsoRegistry::usableState(), $section, $souhaite)',
            $corps,
            'La garde raisonne sur l\'état enregistré des sections absentes du POST.'
        );
        $this->assertStringContainsString('SsoRegistry::setEnabled($section,', $corps);
        foreach (['SsoRegistry::setEnabled(SsoRegistry::GOOGLE,', 'SsoRegistry::setEnabled(SsoRegistry::MICROSOFT,'] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit,
                $corps,
                'Un interrupteur d\'une autre section ne doit jamais être écrit depuis un POST partiel.'
            );
        }
        // Les deux issues (JSON / redirection) ramènent à la section enregistrée.
        $this->assertStringContainsString("'/admin/sso#sso-' . \$section", $corps);
    }

    /**
     * Message libre de la page de connexion : saisi et borné sur /admin/sso,
     * normalisé par le registre, rendu par la page de connexion SANS nl2br
     * (les retours à la ligne passent par `white-space: pre-line`).
     */
    public function testLeMessageDeLaPageDeConnexionEstBrancheDeBoutEnBout(): void
    {
        $vue = self::lire('templates/admin/sso.php');
        $this->assertMatchesRegularExpression(
            '#<textarea[^>]*name="login_message"[^>]*maxlength="<\?= SsoRegistry::LOGIN_MESSAGE_MAX \?>"#s',
            $vue,
            'La borne de saisie est celle du registre, jamais un nombre recopié.'
        );

        $handler = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString('SsoRegistry::normalizeLoginMessage(', $handler);
        $this->assertStringContainsString('SsoRegistry::SETTING_LOGIN_MESSAGE', $handler);

        $login = self::lire('templates/auth/login.php');
        $this->assertStringContainsString('SsoRegistry::loginMessage()', $login);
        $this->assertStringContainsString('class="login-notice"', $login);
        $this->assertStringNotContainsString('nl2br', $login);

        $css = self::lire('public/assets/css/main.css');
        $this->assertMatchesRegularExpression('/\.login-notice \{[^}]*white-space: pre-line;/s', $css);
    }
}
