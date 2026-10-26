<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rappel de reconnexion — une connexion de connecteur RÉVOQUÉE par son
 * fournisseur (jeton présent en base, statut `revoked`) est signalée par une
 * pastille ambre discrète : avatar du compte (pied de barre latérale, header
 * mobile), menu du compte, lien « Connexions » des paramètres, ligne du menu
 * « + » — et chaque surface mène droit à la carte à reconnecter.
 *
 * Partie PURE (aucune base : le prédicat de visibilité est injecté) et câblage
 * vérifié sur le code.
 */
final class ConnectorReconnectBadgeTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");

        return (string)file_get_contents($abs);
    }

    // ---------- Forme pure ----------

    public function testProvidersRevoquesVersClesDansLOrdreDuCatalogue(): void
    {
        $keys = ConnectorRegistry::keysToReconnect(
            ['slack', 'gmail', 'google_drive', 'inconnu', 'gmail'],
            'user',
            static fn (string $k): bool => true
        );
        $this->assertSame(
            ['drive', 'gmail', 'slack'],
            $keys,
            "L'ordre est celui du catalogue ; un provider inconnu ou en double est ignoré."
        );
    }

    /**
     * Masqué par l'administrateur, un connecteur ne peut plus être reconnecté :
     * la page Connexions ne propose que le retrait du jeton. Un rappel qu'on
     * ne peut pas éteindre n'est qu'une nuisance.
     */
    public function testUnConnecteurMasqueNestPasRappele(): void
    {
        $keys = ConnectorRegistry::keysToReconnect(
            ['gmail', 'google_drive'],
            'user',
            static fn (string $k): bool => $k !== 'gmail'
        );
        $this->assertSame(['drive'], $keys);
    }

    /** OneDrive ne sert qu'à bâtir des bases : seuls admins et créateurs le voient. */
    public function testOneDriveNestRappeleQuAQuiGereDesBases(): void
    {
        $tous = static fn (string $k): bool => true;
        $this->assertSame([], ConnectorRegistry::keysToReconnect(['onedrive'], 'user', $tous));
        $this->assertSame([], ConnectorRegistry::keysToReconnect(['onedrive'], 'pending', $tous));
        $this->assertSame(['onedrive'], ConnectorRegistry::keysToReconnect(['onedrive'], 'creator', $tous));
        $this->assertSame(['onedrive'], ConnectorRegistry::keysToReconnect(['onedrive'], 'admin', $tous));
    }

    public function testRienARappelerSansProviderRevoque(): void
    {
        $this->assertSame([], ConnectorRegistry::keysToReconnect([], 'admin', static fn (string $k): bool => true));
        $this->assertSame('', ConnectorRegistry::reconnectSummary([]));
        $this->assertSame('', ConnectorRegistry::reconnectBadgeText([]));
        // Clé inconnue : rien, jamais un identifiant à l'écran.
        $this->assertSame('', ConnectorRegistry::reconnectBadgeText(['inconnu']));
        $this->assertSame('', ConnectorRegistry::reconnectSummary(['inconnu']));
    }

    /**
     * Tout jeton du catalogue doit pouvoir déclencher le rappel — un provider
     * oublié dans la correspondance resterait muet à vie.
     */
    public function testChaqueConnecteurAConnexionEstRappelable(): void
    {
        $tous = static fn (string $k): bool => true;
        foreach (ConnectorRegistry::catalog() as $c) {
            if (empty($c['needs_link'])) {
                continue;
            }
            $this->assertSame(
                [(string)$c['key']],
                ConnectorRegistry::keysToReconnect([(string)$c['token']], 'admin', $tous),
                "Le jeton « {$c['token']} » ne rappelle pas « {$c['key']} »."
            );
        }
    }

    public function testLesTextesDuRappelNommentLeService(): void
    {
        I18n::use('fr');
        $this->assertSame('Connexion à reconnecter : Gmail', ConnectorRegistry::reconnectSummary(['gmail']));
        $this->assertSame(
            'Connexions à reconnecter : Google Drive, Gmail',
            ConnectorRegistry::reconnectSummary(['drive', 'gmail'])
        );
        $this->assertSame('Gmail à reconnecter', ConnectorRegistry::reconnectBadgeText(['gmail']));
        $this->assertSame('2 à reconnecter', ConnectorRegistry::reconnectBadgeText(['drive', 'gmail']));

        // Les noms de produit suivent la langue (« Google Agenda » → « Google
        // Calendar »), la phrase aussi.
        I18n::use('en');
        try {
            $this->assertSame('Connection to reconnect: Gmail', ConnectorRegistry::reconnectSummary(['gmail']));
            $this->assertSame(
                'Connections to reconnect: Google Drive, Google Calendar',
                ConnectorRegistry::reconnectSummary(['drive', 'calendar'])
            );
            $this->assertSame('Outlook Calendar needs reconnecting', ConnectorRegistry::reconnectBadgeText(['mscalendar']));
            $this->assertSame('3 to reconnect', ConnectorRegistry::reconnectBadgeText(['drive', 'gmail', 'slack']));
        } finally {
            I18n::use('fr');
        }
    }

    /** L'ancre de la carte EST la clé du catalogue : c'est ce que pose la ligne du menu « + ». */
    public function testLAncreDeLaPageConnexionsEstLaCleDuCatalogue(): void
    {
        $gabarit = self::lire('templates/settings/connections.php');
        foreach (ConnectorRegistry::allKeys() as $key) {
            if (ConnectorRegistry::tokenProvider($key) === null) {
                continue; // Recherche Internet : aucune carte, rien à relier.
            }
            $this->assertStringContainsString(
                '<div class="card" id="connector-' . $key . '">',
                $gabarit,
                "La carte « $key » n'a pas d'ancre : le rappel ne saurait pas où mener."
            );
        }
        // La carte visée se signale, sinon l'arrivée par l'ancre passe inaperçue.
        $this->assertStringContainsString('.card[id^="connector-"]:target', self::lire('public/assets/css/main.css'));
    }

    // ---------- Câblage ----------

    /** Une requête, sans déchiffrement, et best-effort : un rappel ne fait pas tomber une page. */
    public function testLeRappelLitUneSeuleRequeteSansDechiffrement(): void
    {
        $modele = self::lire('src/Models/UserDriveToken.php');
        $this->assertStringContainsString('public static function revokedProvidersForUser(int $userId): array', $modele);
        $this->assertStringContainsString("AND status = \\'revoked\\'", $modele);
        $this->assertStringNotContainsString(
            'Encryption::decrypt',
            substr($modele, (int)strpos($modele, 'function revokedProvidersForUser'), 500)
        );

        $registre = self::lire('src/Connectors/ConnectorRegistry.php');
        $this->assertStringContainsString('UserDriveToken::revokedProvidersForUser($userId)', $registre);
        $this->assertStringContainsString("self::keysToReconnect(\$revoked, \$role, [self::class, 'isProposed'])", $registre);
        $corps = substr($registre, (int)strpos($registre, 'function reconnectNeeded'), 600);
        $this->assertStringContainsString('catch (\Throwable', $corps);
    }

    /**
     * Les trois partials du compte partagent UNE liste : chacun ne la calcule
     * que si personne ne l'a fait avant lui (le contrôleur du chat, ou
     * admin-nav.php, rendu avant le header mobile).
     */
    public function testLesSurfacesDuComptePortentLaPastille(): void
    {
        foreach (['templates/partials/user-account-trigger.php', 'templates/partials/header-avatar-btn.php'] as $partial) {
            $src = self::lire($partial);
            $this->assertStringContainsString('if (!isset($connectorsToReconnect))', $src, "$partial recalculerait la liste.");
            $this->assertStringContainsString('ConnectorRegistry::reconnectNeeded(', $src);
            $this->assertStringContainsString('ConnectorRegistry::reconnectSummary($connectorsToReconnect)', $src);
            $this->assertStringContainsString('<span class="user-avatar-wrap">', $src);
            $this->assertStringContainsString('<span class="reconnect-dot" aria-hidden="true"></span>', $src);
        }

        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('ConnectorRegistry::reconnectNeeded(', $nav);
        $this->assertStringContainsString('<span class="reconnect-dot" aria-hidden="true"></span>', $nav);
        $this->assertLessThan(
            (int)strpos($nav, "partials/header-avatar-btn.php'"),
            (int)strpos($nav, 'ConnectorRegistry::reconnectNeeded('),
            'La liste doit être calculée AVANT le header mobile, premier partial rendu.'
        );

        $ctrl = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(
            2,
            substr_count($ctrl, '$connectorsToReconnect = ConnectorRegistry::reconnectNeeded('),
            'index() ET show() calculent la liste pour le gabarit du chat.'
        );
    }

    /** Discret : une pastille sans pulsation, contrairement à l'alerte de configuration initiale. */
    public function testLaPastilleEstDiscrete(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach ([
            '.reconnect-dot {',
            '.user-avatar-wrap {',
            '.user-avatar-wrap .reconnect-dot {',
            '.plus-menu-icon .reconnect-dot {',
            '.plus-menu-row.needs-reconnect {',
            '.connector-actions {',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css, "Règle CSS absente : $regle");
        }
        $bloc = substr($css, (int)strpos($css, '.reconnect-dot {'), 220);
        $this->assertStringNotContainsString('animation', $bloc);

        // La ligne grisée du menu « + » l'emporte sur `.is-disabled` : même
        // spécificité, la règle tardive gagne — donc déclarée APRÈS.
        $this->assertLessThan(
            (int)strpos($css, '.plus-menu-row.needs-reconnect {'),
            (int)strpos($css, '.plus-menu-row.is-disabled {')
        );
        // La pastille de l'icône a besoin d'un ancrage.
        $icone = substr($css, (int)strpos($css, '.plus-menu-icon {'), 120);
        $this->assertStringContainsString('position: relative;', $icone);
    }

    /**
     * Compte jamais relié et connexion EXPIRÉE ne se ressemblent plus : la
     * seconde dit pourquoi (data-reason="reconnect") et mène à SA carte.
     */
    public function testLeChatDistingueConnexionExpireeEtCompteJamaisRelie(): void
    {
        $chat = self::lire('templates/chat/index.php');
        $this->assertStringContainsString(
            "data-reason=\"<?= \$reconnect ? 'reconnect' : (\$notConnected ? 'connect' : '') ?>\"",
            $chat
        );
        $this->assertStringContainsString("in_array(\$c['key'], \$connectorsToReconnect ?? [], true)", $chat);
        $this->assertStringContainsString("t(ConnectorRegistry::label(\$c['key']))", $chat);
        $this->assertStringContainsString('data-service="<?= htmlspecialchars($service) ?>"', $chat);
        $this->assertStringContainsString("<?= \$reconnect ? ' needs-reconnect' : '' ?>", $chat);
        $this->assertStringContainsString("t('Connexion expirée — cliquer pour reconnecter')", $chat);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('.plus-menu-row[data-reason="reconnect"]', $js);
        $this->assertStringContainsString(
            "if (reason === 'reconnect') window.location.href = '/settings/connections#connector-' + c.name;",
            $js
        );
        // Le point ambre du « + » s'allume aussi : c'est dans ce menu que la ligne mène à la reconnexion.
        $this->assertStringContainsString('&& reconnectRows.length === 0', $js);
        $this->assertStringContainsString("t('{service} à reconnecter', { service: row.dataset.service", $js);

        $client = (array)(require ROOT . '/lang/en.client.php');
        $this->assertArrayHasKey('{service} à reconnecter', $client);
    }

    /**
     * La page Connexions : « Reconnecter » plutôt que « Connecter » quand la
     * connexion a expiré, et le retrait de la connexion morte — sans lui, le
     * rappel resterait allumé pour toujours chez qui ne veut plus de ce service.
     */
    public function testLaPageConnexionsProposeReconnexionEtRetrait(): void
    {
        $gabarit = self::lire('templates/settings/connections.php');
        $this->assertStringContainsString('$removeExpired = static function (string $key): void', $gabarit);
        foreach (['drive', 'gmail', 'calendar', 'tasks', 'outlook', 'mscalendar', 'mstodo', 'teams', 'slack', 'onedrive'] as $key) {
            $this->assertStringContainsString(
                "\$removeExpired('$key')",
                $gabarit,
                "La carte « $key » ne propose pas le retrait d'une connexion expirée."
            );
            $this->assertStringContainsString("/settings/$key/disconnect", $gabarit);
        }
        foreach ([
            'Reconnecter mon Google Drive', 'Reconnecter mon Gmail', 'Reconnecter mon Google Agenda',
            'Reconnecter mes tâches Google', 'Reconnecter mon Outlook', 'Reconnecter mon calendrier Outlook',
            'Reconnecter mon Slack', 'Reconnecter mon OneDrive', 'Reconnecter Microsoft To Do',
            'Reconnecter Microsoft Teams',
        ] as $libelle) {
            $this->assertStringContainsString("te('$libelle')", $gabarit);
        }

        // tj() rend un littéral JSON entre guillemets DOUBLES (apostrophes
        // échappées en séquence Unicode) : l'attribut qui le porte est délimité
        // par des apostrophes, sinon le premier " ferme l'attribut et la
        // confirmation ne s'affiche jamais — c'était le cas du lien « Se déconnecter ».
        $this->assertStringContainsString("onsubmit='return confirm(<?= tj(", $gabarit);
        $this->assertStringNotContainsString('="return confirm(<?= tj(', $gabarit);
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString("onclick='return confirm(<?= tj('Se déconnecter ?') ?>);'", $nav);
        $this->assertStringNotContainsString('="return confirm(<?= tj(', $nav);
    }

    public function testLaDocumentationDitOuChercherLaPastille(): void
    {
        $this->assertStringContainsString('id: connexion-expiree-rappel', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('pastille ambre', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('## Quand une connexion expire', self::lire('docs/user/fonctionnalites/connecteurs/relier-un-service.md'));
        $this->assertStringContainsString('pastille ambre', self::lire('docs/user/fonctionnalites/connecteurs/index.md'));
    }
}
