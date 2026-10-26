<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Socle des connecteurs personnels : la plomberie commune
 * (ConnectorApiService), les deux socles de fournisseur — Google
 * (GoogleUserOAuth + GoogleUserApiService) et Microsoft (MicrosoftUserOAuth +
 * MicrosoftUserApiService) — et leur déclaration par connecteur dans
 * ConnectorRegistry (`family` / `oauth` / `service`).
 *
 * Ces gardes encodent le contrat qui permet d'ajouter ou de faire évoluer un
 * connecteur sans réveiller les pannes déjà payées : un préfixe de state non
 * routé rend le retour OAuth muet, une classe absente du chargement « require
 * manuel » ne plante QUE dans le worker, un provider de jeton divergent ferait
 * lire les jetons d'un autre connecteur, et un refresh token roulant non
 * persisté fait expirer une connexion Microsoft en silence.
 */
final class ConnectorFrameworkTest extends TestCase
{
    /**
     * TOUS les connecteurs à connexion personnelle qui portent un service —
     * le périmètre de ce qui doit être branché au chat et cohérent avec le
     * registre, quelle que soit la famille. OneDrive en est exclu : il a une
     * connexion mais aucun service de chat (`service => null`).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function serviceRows(): array
    {
        return array_values(array_filter(
            ConnectorRegistry::catalog(),
            fn($c) => !empty($c['needs_link']) && is_string($c['service'] ?? null) && $c['service'] !== ''
        ));
    }

    /** @return array<int, array<string, mixed>> connecteurs personnels d'une famille */
    private static function rowsForFamily(string $family): array
    {
        return array_values(array_filter(
            ConnectorRegistry::catalog(),
            fn($c) => !empty($c['needs_link']) && (string)($c['family'] ?? '') === $family
        ));
    }

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Valeur d'une constante, même protected (identité API des services). */
    private static function constante(string $classe, string $nom): mixed
    {
        return (new \ReflectionClass($classe))->getConstant($nom);
    }

    /**
     * Chaque connecteur personnel déclare les classes du socle de SA famille.
     * Se tromper de socle compile — et casse au premier refresh (jetons
     * roulants Microsoft) ou au premier 403 (erreurs Google Cloud).
     */
    public function testChaqueConnecteurPersonnelDeclareLesClassesDeSaFamille(): void
    {
        $socles = [
            'google'    => [GoogleUserOAuth::class, GoogleUserApiService::class],
            'microsoft' => [MicrosoftUserOAuth::class, MicrosoftUserApiService::class],
            'slack'     => [SlackUserOAuth::class, SlackUserApiService::class],
        ];

        $vues = [];
        foreach (self::serviceRows() as $c) {
            $key    = (string)$c['key'];
            $family = (string)($c['family'] ?? '');
            $this->assertArrayHasKey($family, $socles, "« $key » : famille « $family » sans socle connu.");
            [$socleOauth, $socleService] = $socles[$family];
            $vues[$family] = true;

            $this->assertIsString($c['oauth'], "« $key » ne déclare pas sa classe OAuth.");
            $this->assertTrue(
                is_subclass_of((string)$c['oauth'], $socleOauth),
                "« $key » : {$c['oauth']} doit étendre $socleOauth."
            );

            $this->assertTrue(
                is_subclass_of((string)$c['service'], $socleService),
                "« $key » : {$c['service']} doit étendre $socleService."
            );

            $this->assertNotNull($c['token'], "« $key » : provider de jeton manquant.");
        }

        // Les trois familles sont réellement représentées : sinon ce test
        // passerait au vert en ne vérifiant plus qu'un seul socle.
        foreach (['google', 'microsoft', 'slack'] as $famille) {
            $this->assertArrayHasKey($famille, $vues);
            $this->assertNotEmpty(self::rowsForFamily($famille));
        }

        // La Recherche Internet n'a ni OAuth ni service : brique d'organisation.
        $web = ConnectorRegistry::row(ConnectorRegistry::WEB);
        $this->assertNull($web['oauth']);
        $this->assertNull($web['service']);
    }

    /**
     * Les deux socles de fournisseur partagent la MÊME plomberie : un second
     * exemplaire de la politique de retry / refresh / non-fuite dériverait du
     * premier sans que rien ne le signale.
     */
    public function testLesDeuxSoclesPartagentLaPlomberieCommune(): void
    {
        foreach ([GoogleUserApiService::class, MicrosoftUserApiService::class, SlackUserApiService::class] as $socle) {
            $this->assertTrue(
                is_subclass_of($socle, ConnectorApiService::class),
                "$socle doit étendre ConnectorApiService."
            );
        }
        // Méthodes de plomberie déclarées UNE fois, dans le parent : aucun
        // socle de fournisseur ne les redéclare.
        foreach (['getValidAccessToken', 'apiRaw', 'apiJson', 'presentHttpError', 'tokenRowForUser'] as $m) {
            foreach ([ConnectorApiService::class, GoogleUserApiService::class, MicrosoftUserApiService::class] as $classe) {
                $this->assertSame(
                    ConnectorApiService::class,
                    (new \ReflectionMethod($classe, $m))->getDeclaringClass()->getName(),
                    "$classe redéclare $m() : la plomberie doit rester en un seul exemplaire."
                );
            }
        }
    }

    /**
     * Le service et le registre doivent parler du MÊME provider de jetons et
     * de la MÊME clé Settings : une divergence ferait lire les jetons (ou
     * l'interrupteur admin) d'un autre connecteur — sans erreur, juste faux.
     */
    public function testLIdentiteDuServiceEstCoherenteAvecLeRegistre(): void
    {
        foreach (self::serviceRows() as $c) {
            $key     = (string)$c['key'];
            $service = (string)$c['service'];

            $this->assertSame(
                (string)$c['token'],
                self::constante($service, 'TOKEN_PROVIDER'),
                "« $key » : TOKEN_PROVIDER du service ≠ jeton du registre."
            );
            $this->assertSame(
                (string)$c['setting'],
                self::constante($service, 'SETTING_ENABLED'),
                "« $key » : SETTING_ENABLED du service ≠ clé Settings du registre."
            );
            $this->assertSame(
                (string)$c['oauth'],
                self::constante($service, 'OAUTH_CLASS'),
                "« $key » : OAUTH_CLASS du service ≠ classe OAuth du registre."
            );

            // Messages FR de la plomberie : un vide donnerait une erreur muette.
            foreach (['API_LABEL', 'LOG_LABEL', 'MSG_RECONNECT', 'MSG_SCOPE_INSUFFICIENT',
                      'MSG_NOT_FOUND', 'MSG_BAD_REQUEST', 'MSG_ACCESS_DENIED'] as $nom) {
                $this->assertNotSame('', (string)self::constante($service, $nom),
                    "« $key » : constante $nom vide sur $service.");
            }
        }

        // Deux connecteurs ne peuvent pas partager un provider de jetons : ils
        // s'écraseraient mutuellement (UNIQUE user_id+provider).
        $jetons = array_column(self::serviceRows(), 'token');
        $this->assertSame($jetons, array_unique($jetons));
    }

    /**
     * Les préfixes de state sont le SEUL aiguillage du retour OAuth commun
     * (/auth/callback) : uniques, jamais préfixes l'un de l'autre (le routage
     * est un str_starts_with), et effectivement routés par AuthController.
     */
    public function testLesPrefixesDeStateSontUniquesEtRoutes(): void
    {
        $auth     = self::lire('src/Controllers/AuthController.php');
        $prefixes = [];
        foreach (self::serviceRows() as $c) {
            $oauth  = (string)$c['oauth'];
            $prefix = (string)$oauth::STATE_PREFIX;

            $this->assertNotSame('', $prefix, "« {$c['key']} » : STATE_PREFIX vide.");
            $this->assertStringEndsWith('_', $prefix, "« {$c['key']} » : un préfixe se termine par « _ ».");
            $this->assertStringContainsString(
                "'" . $prefix . "'",
                $auth,
                "AuthController::callback() ne route pas le préfixe « $prefix » — le retour OAuth de « {$c['key']} » resterait muet."
            );
            // Et il doit être écarté du SSO : providerFromState() retombe sur
            // Google pour tout préfixe inconnu, donc un oubli ici enverrait un
            // retour de connecteur dans le flux de connexion le jour où
            // l'ordre du callback bouge.
            $this->assertContains(
                $prefix,
                SsoRegistry::CONNECTOR_PREFIXES,
                "« {$c['key']} » : préfixe absent de SsoRegistry::CONNECTOR_PREFIXES."
            );
            $prefixes[] = $prefix;
        }
        // Un préfixe préfixe d'un autre casserait le premier str_starts_with.
        $this->assertSame($prefixes, array_unique($prefixes));
        foreach ($prefixes as $a) {
            foreach ($prefixes as $b) {
                if ($a !== $b) {
                    $this->assertFalse(str_starts_with($a, $b), "« $a » commence par « $b » : routage ambigu.");
                }
            }
        }

        // Clés de session distinctes : deux consentements menés en parallèle
        // ne doivent pas s'écraser le state.
        $sessions = array_map(
            fn($c) => (string)self::constante((string)$c['oauth'], 'SESSION_KEY'),
            self::serviceRows()
        );
        $this->assertSame($sessions, array_unique($sessions));
        $this->assertNotContains('', $sessions);
    }

    /**
     * Pas d'autoloader : les socles doivent être chargés AVANT leurs
     * sous-classes, dans le chargement web + CLI ET dans celui des tests —
     * sinon fatal « class not found » au premier require d'une sous-classe.
     */
    public function testLesSoclesSontChargesAvantLeursSousClasses(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $fichier) {
            $source = self::lire($fichier);
            foreach ([
                ['/src/Auth/GoogleUserOAuth.php', '/src/Auth/GoogleDriveOAuth.php'],
                ['/src/Connectors/ConnectorApiService.php', '/src/Connectors/GoogleUserApiService.php'],
                ['/src/Connectors/GoogleUserApiService.php', '/src/Drive/UserDriveService.php'],
                // Famille Microsoft. MicrosoftUserOAuth délègue la traduction
                // des codes AADSTS à MicrosoftOAuth : lui aussi doit précéder.
                ['/src/Auth/MicrosoftOAuth.php', '/src/Auth/MicrosoftUserOAuth.php'],
                ['/src/Auth/MicrosoftUserOAuth.php', '/src/Auth/MicrosoftOutlookOAuth.php'],
                ['/src/Auth/MicrosoftUserOAuth.php', '/src/Auth/MicrosoftCalendarOAuth.php'],
                ['/src/Connectors/ConnectorApiService.php', '/src/Connectors/MicrosoftUserApiService.php'],
                ['/src/Connectors/MicrosoftUserApiService.php', '/src/Outlook/UserOutlookService.php'],
                // Le calendrier Outlook réutilise les statiques pures de
                // UserOutlookService (destinataires, HTML → texte) et de
                // UserCalendarService (analyse de date) : les deux d'abord.
                ['/src/Outlook/UserOutlookService.php', '/src/Outlook/UserOutlookCalendarService.php'],
                ['/src/Calendar/UserCalendarService.php', '/src/Outlook/UserOutlookCalendarService.php'],
                // Famille Slack. Le socle OAuth lit ses identifiants dans
                // SlackApp, et le service se sert de SlackMrkdwn dans les deux
                // sens : les deux doivent précéder.
                ['/src/Slack/SlackApp.php', '/src/Auth/SlackUserOAuth.php'],
                ['/src/Auth/SlackUserOAuth.php', '/src/Auth/SlackMessagesOAuth.php'],
                ['/src/Connectors/ConnectorApiService.php', '/src/Connectors/SlackUserApiService.php'],
                ['/src/Connectors/SlackUserApiService.php', '/src/Slack/UserSlackService.php'],
                ['/src/Slack/SlackMrkdwn.php', '/src/Slack/UserSlackService.php'],
            ] as [$socle, $sousClasse]) {
                $posSocle  = strpos($source, $socle);
                $posEnfant = strpos($source, $sousClasse);
                $this->assertNotFalse($posSocle, "$fichier ne charge pas $socle.");
                $this->assertNotFalse($posEnfant, "$fichier ne charge pas $sousClasse.");
                $this->assertLessThan($posEnfant, $posSocle, "$fichier : $socle doit précéder $sousClasse.");
            }
        }
    }

    /**
     * Les identifiants OAuth vivent dans SsoRegistry (base + repli .env) : les
     * socles ne doivent pas relire les constantes en direct — le worker serait
     * aveugle aux identifiants saisis en administration. C'est la panne muette
     * qui a privé le chat des connecteurs Google pendant tout un déploiement.
     */
    public function testLesSoclesOauthNeLisentPasLesConstantesDEnvironnement(): void
    {
        foreach (['src/Auth/GoogleUserOAuth.php', 'src/Auth/MicrosoftUserOAuth.php'] as $fichier) {
            $source = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', self::lire($fichier));
            $this->assertDoesNotMatchRegularExpression('/\bGOOGLE_CLIENT_SECRET\b/', $source, $fichier);
            $this->assertDoesNotMatchRegularExpression('/\bGOOGLE_REDIRECT_URI\b/', $source, $fichier);
            $this->assertStringContainsString('SsoRegistry::', $source, "$fichier doit lire ses identifiants au registre.");
        }
    }

    /**
     * Microsoft renvoie un refresh token ROULANT à chaque rafraîchissement :
     * le socle DOIT le persister. Sans cette écriture, la connexion meurt à
     * l'expiration du premier jeton, sans une ligne de log — piège déjà payé
     * sur OneDrive.
     */
    public function testLeSocleMicrosoftPersisteLeJetonRoulant(): void
    {
        $source = self::lire('src/Connectors/MicrosoftUserApiService.php');
        $this->assertStringContainsString('function afterRefresh', $source);
        $this->assertStringContainsString('UserDriveToken::updateRefreshToken(', $source);

        // Et le hook doit être appelé par la plomberie commune, sinon il ne
        // s'exécuterait jamais.
        $this->assertStringContainsString(
            '$this->afterRefresh($fresh);',
            self::lire('src/Connectors/ConnectorApiService.php')
        );
    }

    /**
     * LES DEUX socles OAuth doivent rendre le `scope` du rafraîchissement.
     *
     * Google et Microsoft redisent tous deux, à chaque refresh, les permissions
     * réellement portées par le jeton — et c'est
     * ConnectorApiService::syncGrantedScope() qui les persiste. L'omettre ne
     * casse RIEN de visible : les autorisations restent simplement figées au
     * jour de la connexion. Un utilisateur dont l'annuaire élargit ensuite le
     * consentement garde des outils désactivés (badge « Brouillons — », outil
     * non exposé au modèle) ; à l'inverse, un accès retiré laisse des outils
     * exposés, qui échouent en 403. Aucune ligne de log dans les deux cas.
     *
     * Le cas est ORDINAIRE chez Entra, où le consentement utilisateur est
     * souvent interdit et où un administrateur accorde les permissions APRÈS
     * la première connexion.
     */
    public function testLesSoclesOauthRendentLeScopeDuRafraichissement(): void
    {
        foreach ([
            'src/Auth/GoogleUserOAuth.php',
            'src/Auth/MicrosoftUserOAuth.php',
            'src/Auth/MicrosoftOAuth.php',
            'src/Auth/SlackUserOAuth.php',
        ] as $fichier) {
            $source = self::lire($fichier);
            $refresh = strpos($source, 'function refreshAccessToken');
            $this->assertIsInt($refresh, "$fichier : refreshAccessToken introuvable.");
            $this->assertStringContainsString(
                "'scope'",
                substr($source, $refresh, 900),
                "$fichier doit rendre le scope du rafraîchissement, sinon les autorisations "
                    . 'restent figées au jour de la connexion, sans erreur.'
            );
        }

        // Et la plomberie commune doit s'en servir : rendre le scope sans le
        // persister ne changerait rien.
        $socle = self::lire('src/Connectors/ConnectorApiService.php');
        $this->assertStringContainsString("\$this->syncGrantedScope((string)(\$fresh['scope'] ?? ''));", $socle);
        $this->assertStringContainsString('UserDriveToken::updateScope(', $socle);
    }

    /**
     * Les routes de public/index.php nomment des méthodes PAR connecteur
     * (driveConnect…) : les délégués publics doivent survivre au socle
     * paramétré, sinon 500 sur chaque clic « Connecter ».
     */
    public function testLesDeleguesPublicsDuControleurExistent(): void
    {
        // Vérification textuelle : le bootstrap des tests ne charge pas les
        // contrôleurs (dépendances vendor/), method_exists serait aveugle.
        $source = self::lire('src/Controllers/UserSettingsController.php');
        $routes = self::lire('public/index.php');
        foreach (['drive', 'gmail', 'calendar', 'tasks', 'outlook', 'mscalendar', 'mstodo', 'teams', 'slack'] as $key) {
            foreach (['Connect', 'Callback', 'Disconnect'] as $verbe) {
                $this->assertStringContainsString(
                    'public function ' . $key . $verbe . '(',
                    $source,
                    "UserSettingsController::{$key}{$verbe}() manquant."
                );
            }
            $this->assertStringContainsString(
                "'/settings/" . $key . "/connect'",
                $routes,
                "Route de connexion de « $key » absente de public/index.php."
            );
        }
    }

    /**
     * Le chat expose les connecteurs par une SEULE boucle (opt-in du message +
     * autorisation admin + compte connecté), alimentée par le registre. Un
     * connecteur oublié dans la table des options ne serait jamais exposé au
     * modèle — et, faute de trace, la panne serait muette.
     */
    public function testLesConnecteursSontTousBranchesAuChat(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');

        foreach (self::serviceRows() as $c) {
            if (!ConnectorRegistry::isChatConnector((string)$c['key'])) {
                continue; // hors conversation : rien à exposer au modèle
            }
            $key   = (string)$c['key'];
            $const = 'ConnectorRegistry::' . strtoupper($key);

            $this->assertMatchesRegularExpression(
                '/' . preg_quote($const, '/') . '\s*=>\s*\$' . $key . 'Access,/',
                $chat,
                "« $key » absent de la table des options du menu « + » dans send()."
            );
            $this->assertStringContainsString(
                "\$_POST['" . $key . "_access'] ?? '0'",
                $chat,
                "« $key » : l'opt-in doit venir du POST, jamais être coché côté serveur."
            );
            // La charge du job doit transporter l'opt-in : le chat s'exécute
            // dans le worker, où $_POST n'existe pas.
            $this->assertStringContainsString(
                "'" . $key . "_access'",
                $chat,
                "« $key » : opt-in absent de la charge utile du job."
            );
            $this->assertStringContainsString(
                "\$connectorServices[$const]",
                $chat,
                "« $key » : le service doit sortir de la boucle commune."
            );
        }

        // La boucle de trace « demandé mais indisponible » lit le registre :
        // sans elle, un connecteur coché qui n'aboutit pas resterait muet.
        $this->assertStringContainsString('[connecteurs] \' . $cnxLabel . \' demandé mais indisponible', $chat);
    }

    /**
     * L'héritage doit préserver l'API publique historique appelée partout
     * (ChatController, KbSync, worker, Diagnostics, tests) : un renommage
     * silencieux casserait ces appels au premier déploiement.
     */
    public function testLApiPubliqueHistoriqueEstPreservee(): void
    {
        foreach (self::serviceRows() as $c) {
            $oauth   = (string)$c['oauth'];
            $service = (string)$c['service'];
            foreach (['isConfigured', 'redirectUri', 'getAuthUrl', 'getAuthUrlNative',
                      'handleCallback', 'handleCallbackNative', 'refreshAccessToken', 'revoke'] as $m) {
                $this->assertTrue(method_exists($oauth, $m), "$oauth::$m() manquant.");
            }
            foreach (['isGloballyEnabled', 'forUser', 'accountEmail'] as $m) {
                $this->assertTrue(method_exists($service, $m), "$service::$m() manquant.");
            }
        }
        // Les aides d'erreur Google restent adressables via UserDriveService
        // (appelées ainsi par les tests et d'anciens call-sites).
        $this->assertSame('Gmail', UserDriveService::disabledApiFromBody(
            '{"reason":"SERVICE_DISABLED","message":"gmail.googleapis.com"}'
        ));
    }

    /**
     * Le squelette d'erreur ne doit renvoyer au client QUE des codes
     * structurés : le corps du fournisseur peut contenir une adresse, un objet
     * de message — et sur un 401 Google, la clé API elle-même.
     */
    public function testLesErreursNeFuitentPasLeCorpsDuFournisseur(): void
    {
        $socle = self::lire('src/Connectors/ConnectorApiService.php');
        // Le corps ne sert qu'aux hooks et au log tronqué, jamais au message.
        $this->assertStringContainsString('mb_substr($response, 0, 300)', $socle);
        $this->assertStringContainsString('$detail = $this->errorDetail($body);', $socle);

        // Le détail Microsoft est le code Graph, rien d'autre.
        $this->assertSame(
            'ErrorAccessDenied',
            MicrosoftUserApiService::graphErrorCode('{"error":{"code":"ErrorAccessDenied","message":"secret"}}')
        );
        $this->assertSame('', MicrosoftUserApiService::graphErrorCode('pas du json'));
    }
}
