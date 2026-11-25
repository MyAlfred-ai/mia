<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur relié = connecteur allumé dans le menu « + » du chat.
 *
 * Relier un service depuis Réglages → Comptes connectés, c'est vouloir s'en
 * servir : la connexion pose un drapeau sur la ligne du jeton (migration 096),
 * le premier affichage du chat qui suit le lit et l'efface, et chat.js allume
 * l'interrupteur — que la personne peut éteindre ensuite comme les autres.
 *
 * Partie PURE (aucune base : le prédicat de visibilité est injecté) et câblage
 * vérifié sur le code. La lecture unique sur base réelle est gardée par
 * tests/Functional/ConnectorJustConnectedPipelineTest.php.
 */
final class ConnectorJustConnectedTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");

        return (string)file_get_contents($abs);
    }

    // ---------- Forme pure ----------

    public function testProvidersReliesVersClesDuChatDansLOrdreDuCatalogue(): void
    {
        $keys = ConnectorRegistry::justConnectedKeys(
            ['slack', 'gmail', 'google_drive', 'inconnu', 'gmail'],
            static fn (string $k): bool => true
        );
        $this->assertSame(['drive', 'gmail', 'slack'], $keys,
            "L'ordre est celui du catalogue ; un provider inconnu ou en double est ignoré.");
    }

    /** OneDrive alimente des bases de connaissances : aucune ligne à allumer dans le chat. */
    public function testOneDriveNAllumeRienDansLeChat(): void
    {
        $this->assertSame([], ConnectorRegistry::justConnectedKeys(['onedrive'], static fn (string $k): bool => true));
    }

    /** Masqué par l'administrateur, le connecteur n'a pas de ligne : rien à allumer. */
    public function testUnConnecteurMasqueNEstPasAllume(): void
    {
        $keys = ConnectorRegistry::justConnectedKeys(
            ['gmail', 'google_drive'],
            static fn (string $k): bool => $k !== 'gmail'
        );
        $this->assertSame(['drive'], $keys);
    }

    /** Chaque connecteur du chat à relier doit pouvoir s'allumer — un oubli resterait muet. */
    public function testChaqueConnecteurDuChatARelierPeutSAllumer(): void
    {
        foreach (ConnectorRegistry::chatCatalog() as $c) {
            if (empty($c['needs_link'])) {
                continue;
            }
            $this->assertSame(
                [(string)$c['key']],
                ConnectorRegistry::justConnectedKeys([(string)$c['token']], static fn (string $k): bool => true),
                'Connecteur ' . $c['key'] . ' : sa connexion doit l\'allumer.'
            );
        }
    }

    /** Sans le droit « Connecteurs — Utiliser », aucune ligne : rien n'est lu ni allumé. */
    public function testRienSansLeDroitDUtiliserLesConnecteurs(): void
    {
        $this->assertSame([], ConnectorRegistry::takeJustConnected([]));
    }

    // ---------- Serveur ----------

    public function testLaMigrationAjouteLeDrapeauEteintParDefaut(): void
    {
        $sql = self::lire('database/migrations/096_connector_chat_enable_pending.sql');
        $this->assertMatchesRegularExpression(
            '/ALTER TABLE user_drive_tokens\s+ADD COLUMN chat_enable_pending TINYINT\(1\) NOT NULL DEFAULT 0/',
            $sql,
            'Défaut 0 : les comptes déjà reliés ne s\'allument pas au déploiement.'
        );
    }

    /** Connexion ET reconnexion posent le drapeau — l'une comme l'autre passent par saveConnection. */
    public function testLaConnexionPoseLeDrapeau(): void
    {
        $src   = self::lire('src/Models/UserDriveToken.php');
        $debut = strpos($src, 'public static function saveConnection(');
        $fin   = strpos($src, 'public static function fitScope(');
        $this->assertIsInt($debut);
        $this->assertIsInt($fin);
        $corps = substr($src, $debut, $fin - $debut);
        $this->assertStringContainsString("status, chat_enable_pending)", $corps);
        $this->assertStringContainsString("VALUES (?,?,?,?,?,?,?,\\'ok\\',1)", $corps);
        $this->assertStringContainsString('chat_enable_pending = 1', $corps,
            'Une reconnexion (ligne déjà présente) doit aussi rallumer le connecteur.');

        // Le rafraîchissement d'un jeton n'est PAS une connexion.
        foreach (['updateAccessToken', 'updateScope', 'updateRefreshToken'] as $methode) {
            $d = strpos($src, 'public static function ' . $methode . '(');
            $this->assertIsInt($d);
            $f = strpos($src, "\n    }\n", $d);
            $this->assertStringNotContainsString('chat_enable_pending', substr($src, $d, $f - $d), $methode);
        }
    }

    /** Les trois chemins de connexion (web, OneDrive, natif) passent par saveConnection. */
    public function testTousLesRetoursOAuthEnregistrentParSaveConnection(): void
    {
        $src = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertSame(3, substr_count($src, 'UserDriveToken::saveConnection('));
    }

    public function testLeChatLitLeDrapeauAuxDeuxRendus(): void
    {
        $src = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($src, '$connectorsJustConnected = ConnectorRegistry::takeJustConnected($user);'),
            'index() et show() rendent tous deux le chat.');

        $gabarit = self::lire('templates/chat/index.php');
        $this->assertStringContainsString(
            'window.CONNECTORS_JUST_CONNECTED = <?= json_encode(array_values($connectorsJustConnected ?? [])) ?>;',
            $gabarit
        );
        // Posé avant le chargement de chat.js, qui le lit à son initialisation.
        $pose  = strpos($gabarit, 'window.CONNECTORS_JUST_CONNECTED');
        $script = strpos($gabarit, '/assets/js/chat.js');
        $this->assertIsInt($script);
        $this->assertLessThan($script, $pose);
    }

    // ---------- Navigateur ----------

    /**
     * L'allumage devient une PRÉFÉRENCE, appliquée par la restauration
     * existante (garde-fous compris) : il doit donc précéder cette restauration
     * et s'écrire sans passer par saveConnectorPrefs, qui relirait des
     * interrupteurs encore éteints.
     */
    public function testLeNavigateurAllumeAvantLaRestauration(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $lecture     = strpos($js, 'window.CONNECTORS_JUST_CONNECTED');
        $restaure    = strpos($js, 'Object.keys(connectorToggles).forEach(restoreConnectorToggle);');
        $this->assertIsInt($lecture);
        $this->assertIsInt($restaure);
        $this->assertLessThan($restaure, $lecture);

        $bloc = substr($js, $lecture, 900);
        $this->assertStringContainsString('connectorPrefs[name] = true', $bloc);
        $this->assertStringContainsString('localStorage.setItem(CONNECTOR_PREFS_KEY', $bloc);
        $this->assertStringNotContainsString('saveConnectorPrefs()', $bloc);
        // Ni Internet ni « Laisser MIA choisir » : ce ne sont pas des comptes reliés.
        $this->assertStringContainsString("name !== 'web' && name !== 'auto'", $bloc);
    }

    // ---------- Documentation ----------

    public function testLaDocumentationLeDit(): void
    {
        $this->assertStringContainsString('connecteur-allume-a-la-connexion', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('s\'allume tout seul', self::lire('docs/user/fonctionnalites/connecteurs/relier-un-service.md'));
        $this->assertStringContainsString('s\'allume tout seul', self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md'));
        $this->assertStringNotContainsString('désactivé tant que vous ne l\'avez pas activé', self::lire('docs/GUIDE-UTILISATEUR.md'));
    }
}
