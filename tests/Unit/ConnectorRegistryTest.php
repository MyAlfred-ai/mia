<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Catalogue des connecteurs : partie pure (aucune base).
 *
 * Le registre est la source unique des connecteurs du menu « + » : l'admin
 * (`/admin/connectors`), le chat et les services personnels doivent parler de
 * la MÊME clé Settings — deux clés divergentes laisseraient un connecteur
 * masqué en admin mais toujours actif dans le chat.
 */
final class ConnectorRegistryTest extends TestCase
{
    public function testLesConnecteursSontCatalogues(): void
    {
        $this->assertSame(
            ['web', 'drive', 'gmail', 'calendar', 'tasks', 'outlook', 'mscalendar', 'mstodo', 'teams', 'slack'],
            ConnectorRegistry::keys(),
            "L'ordre du catalogue est celui du menu « + » du chat."
        );
    }

    public function testChaqueConnecteurPorteLesChampsAttendus(): void
    {
        foreach (ConnectorRegistry::catalog() as $c) {
            foreach (['key', 'surface', 'family', 'setting', 'label', 'chat_label', 'summary',
                      'capabilities', 'icon', 'needs_oauth', 'needs_link', 'token', 'warn',
                      'settings_url'] as $champ) {
                $this->assertArrayHasKey($champ, $c, "Champ « $champ » manquant sur « {$c['key']} ».");
            }
            $this->assertNotSame('', trim((string)$c['label']));
            $this->assertNotSame('', trim((string)$c['summary']));
            $this->assertNotEmpty($c['capabilities']);

            // Le libellé du menu « + » n'a de sens que pour un connecteur de
            // conversation ; OneDrive, lui, ne s'y montre jamais.
            if (ConnectorRegistry::isChatConnector((string)$c['key'])) {
                $this->assertNotSame('', trim((string)$c['chat_label']));
            } else {
                $this->assertSame('', trim((string)$c['chat_label']),
                    "« {$c['key']} » n'est pas dans le chat : il ne doit pas annoncer de ligne de menu.");
            }

            // Un connecteur qui exige une connexion personnelle doit dire quel
            // jeton compter, sinon la page d'admin annoncerait « 0 compte relié ».
            if ($c['needs_link']) {
                $this->assertNotNull($c['token'], "« {$c['key']} » exige une connexion mais ne déclare aucun jeton.");
            }
        }
    }

    /**
     * Un prérequis technique qui peut manquer doit s'annoncer : sans message,
     * la carte reste muette et l'admin cherche pourquoi le connecteur autorisé
     * ne fait rien. Seul « web » n'a aucun prérequis, donc rien à dire.
     */
    public function testUnConnecteurAPrerequisPorteSonAvertissement(): void
    {
        foreach (ConnectorRegistry::catalog() as $c) {
            if ((string)$c['key'] === ConnectorRegistry::WEB) {
                $this->assertSame('', trim((string)$c['warn']));
                continue;
            }
            $this->assertNotSame('', trim((string)$c['warn']), "« {$c['key']} » ne dit pas ce qui lui manque.");
        }
    }

    /**
     * n8n a quitté le catalogue : déclencher une automatisation est une
     * capacité d'assistant (outil `chat_tools`), pas une source personnelle à
     * consulter. L'y remettre recréerait une case du menu « + » que le serveur
     * n'écoute plus.
     */
    public function testN8nNestPlusUnConnecteur(): void
    {
        $this->assertNotContains('n8n', ConnectorRegistry::keys());
        $this->assertNull(ConnectorRegistry::settingKey('n8n'));
        $this->assertSame([], ConnectorRegistry::defaultTriggers('n8n'));

        $chat = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringNotContainsString('n8nAccessToggle', $chat);
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringNotContainsString("n8n_access", $js);
    }

    // ---------- Expressions déclencheuses de la suggestion automatique ----------

    public function testChaqueConnecteurPorteDesExpressionsParDefaut(): void
    {
        foreach (ConnectorRegistry::keys() as $key) {
            $this->assertNotEmpty(
                ConnectorRegistry::defaultTriggers($key),
                "« $key » ne serait jamais suggéré pendant la frappe."
            );
        }
        $this->assertSame([], ConnectorRegistry::defaultTriggers('facebook'));
    }

    /**
     * Invariant qui fait marcher « saisir exactement les défauts efface la
     * personnalisation » : les défauts doivent être leur propre forme
     * normalisée. Casse si un futur défaut arrive en majuscules, en doublon
     * (accents ignorés) ou trop court pour être retenu.
     */
    public function testLesDefautsSontDejaSousLeurFormeNormalisee(): void
    {
        foreach (ConnectorRegistry::keys() as $key) {
            $defauts = ConnectorRegistry::defaultTriggers($key);
            $this->assertSame(
                $defauts,
                ConnectorRegistry::sanitizeTriggerList(implode("\n", $defauts)),
                "Les expressions par défaut de « $key » ne survivent pas à leur propre normalisation."
            );
        }
    }

    /**
     * Règle de curation : un mot générique seul ferait déclencher « l'agenda
     * de la réunion » ou « le document ci-joint ». Il faut la marque, un
     * possessif, ou un verbe explicite.
     */
    public function testAucuneExpressionParDefautNestUnMotGeneriqueSeul(): void
    {
        $interdits = ['agenda', 'calendrier', 'planning', 'document', 'documents', 'fichier', 'fichiers',
                      'mail', 'mails', 'email', 'emails', 'workflow', 'workflows', 'drive', 'internet', 'web',
                      'tâche', 'tâches', 'liste', 'listes', 'todo', 'to-do', 'rappel', 'rappels'];
        foreach (ConnectorRegistry::keys() as $key) {
            foreach (ConnectorRegistry::defaultTriggers($key) as $phrase) {
                $this->assertNotContains(
                    $phrase,
                    $interdits,
                    "« $phrase » (connecteur $key) est un mot générique seul : il sur-déclencherait."
                );
            }
        }
    }

    public function testSaisieLibreNormaliseeEtDedupliquee(): void
    {
        $liste = ConnectorRegistry::sanitizeTriggerList(
            "  Mon Agenda \n mon   agenda\nMON AGÉNDA\n\nx\nmes réunions\n"
        );
        // Casse, espaces multiples et accents ramenés à une seule entrée ;
        // « x » écarté (1 caractère matcherait partout).
        $this->assertSame(['mon agenda', 'mes réunions'], $liste);
    }

    public function testSaisieAccepteVirgulesEtPointsVirgules(): void
    {
        $this->assertSame(
            ['gmail', 'boîte mail'],
            ConnectorRegistry::sanitizeTriggerList('gmail, boîte mail ; gmail')
        );
    }

    public function testListeVideAutoriseeEtBornee(): void
    {
        $this->assertSame([], ConnectorRegistry::sanitizeTriggerList("  \n \n"));

        $trop = [];
        for ($i = 0; $i < ConnectorRegistry::MAX_TRIGGERS + 15; $i++) {
            $trop[] = 'expression numero ' . $i;
        }
        $this->assertCount(
            ConnectorRegistry::MAX_TRIGGERS,
            ConnectorRegistry::sanitizeTriggerList(implode("\n", $trop))
        );
    }

    public function testExpressionTropLongueTronquee(): void
    {
        $liste = ConnectorRegistry::sanitizeTriggerList(str_repeat('a', 200));
        $this->assertCount(1, $liste);
        $this->assertSame(ConnectorRegistry::MAX_TRIGGER_LEN, mb_strlen($liste[0]));
    }

    /**
     * La détection d'intention des connecteurs ne doit plus vivre en dur dans
     * le JavaScript : elle est compilée depuis les expressions servies par le
     * serveur, sinon la page d'administration mentirait.
     */
    public function testLaDetectionConnecteurVientDuServeur(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/tool-suggest.js');
        $this->assertStringContainsString('window.CONNECTOR_TRIGGERS', $js);
        $this->assertStringContainsString('function connectorRule(', $js);
        // Forme littérale des anciennes règles connecteur : sa réapparition
        // signalerait un motif remis en dur, invisible depuis l'administration.
        foreach (['driveAccessToggle', 'gmailAccessToggle', 'calendarAccessToggle',
                  'webAccessToggle'] as $toggle) {
            $this->assertStringNotContainsString("toggle: '" . $toggle . "'", $js);
        }

        $chat = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString(
            'window.CONNECTOR_TRIGGERS = <?= json_encode(ConnectorRegistry::allTriggers()',
            $chat,
            'Sans injection, aucun connecteur ne serait jamais suggéré.'
        );
    }

    /**
     * Les cartes Drive/Gmail/Agenda ont quitté /admin/settings : les laisser
     * dans ce formulaire éteindrait les trois connecteurs à chaque
     * enregistrement des Paramètres (case absente du POST = décochée).
     */
    public function testLesInterrupteursNeSontPlusDansLesParametres(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/settings.php');
        foreach (['user_drive_enabled', 'user_gmail_enabled', 'user_calendar_enabled'] as $champ) {
            $this->assertStringNotContainsString(
                'name="' . $champ . '"',
                $gabarit,
                "Le champ $champ doit vivre uniquement sur /admin/connectors."
            );
        }

        $controleur = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $debut      = strpos($controleur, 'public function saveSettings');
        $fin        = strpos($controleur, 'public function ', (int)$debut + 10);
        $corps      = substr($controleur, (int)$debut, (int)$fin - (int)$debut);
        $this->assertStringNotContainsString('user_drive_enabled', $corps);
        $this->assertStringNotContainsString('user_gmail_enabled', $corps);
        $this->assertStringNotContainsString('user_calendar_enabled', $corps);
    }

    // ---------- Visibilité décidée par l'administrateur ----------

    /**
     * Deux surfaces, un seul interrupteur : les connecteurs du chat portent
     * une ligne du menu « + », OneDrive alimente les bases de connaissances —
     * et l'administrateur décide pour TOUS s'ils apparaissent aux utilisateurs.
     */
    public function testLeCatalogueDistingueLesSurfaces(): void
    {
        $this->assertSame(
            ['web', 'drive', 'gmail', 'calendar', 'tasks', 'outlook', 'mscalendar', 'mstodo', 'teams', 'slack'],
            ConnectorRegistry::keys(),
            'keys() reste le périmètre du chat : menu « + », expressions, état par utilisateur.'
        );
        $this->assertSame(
            ['web', 'drive', 'gmail', 'calendar', 'tasks', 'outlook', 'mscalendar', 'mstodo', 'teams', 'slack', 'onedrive'],
            ConnectorRegistry::allKeys(),
            'allKeys() couvre aussi les connecteurs hors chat — le périmètre de la page d\'administration.'
        );
        $this->assertTrue(ConnectorRegistry::isChatConnector(ConnectorRegistry::DRIVE));
        $this->assertFalse(ConnectorRegistry::isChatConnector(ConnectorRegistry::ONEDRIVE));
        $this->assertFalse(ConnectorRegistry::isChatConnector('inconnu'));

        // Hors chat = aucune suggestion pendant la frappe (et rien à éditer).
        // `triggers()`/`allTriggers()` lisent les réglages : elles sont bornées
        // par keys(), vérifié ci-dessus, sans toucher la base depuis ce test.
        $this->assertSame([], ConnectorRegistry::defaultTriggers(ConnectorRegistry::ONEDRIVE));
        $this->assertNotContains(ConnectorRegistry::ONEDRIVE, ConnectorRegistry::keys());
    }

    /**
     * L'enregistrement du formulaire complet (sans JavaScript) doit itérer sur
     * TOUS les connecteurs rendus par la page. Itérer sur les seuls
     * connecteurs du chat éteindrait OneDrive à chaque enregistrement — case
     * absente du POST = décochée, le piège maison déjà payé sur /admin/settings.
     */
    public function testLEnregistrementCouvreTousLesConnecteursRendus(): void
    {
        $controleur = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $debut      = strpos($controleur, 'public function saveConnectors');
        $fin        = strpos($controleur, 'public function ', (int)$debut + 10);
        $corps      = substr($controleur, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString('ConnectorRegistry::allKeys()', $corps);
        $this->assertStringNotContainsString('ConnectorRegistry::keys()', $corps);
    }

    /**
     * Un connecteur masqué ne doit plus apparaître NULLE PART côté
     * utilisateur : ni ligne du menu « + », ni carte sur la page Connexions.
     * `isProposed` est la source unique de cette décision (autorisation admin
     * ET prérequis technique) — une carte laissée visible ferait relier un
     * compte qui ne servirait à rien.
     */
    public function testLaPageConnexionsMasqueUnConnecteurNonPropose(): void
    {
        $controleur = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $debut      = strpos($controleur, 'public function connections');
        $fin        = strpos($controleur, 'public function ', (int)$debut + 10);
        $corps      = substr($controleur, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString('ConnectorRegistry::connectionCardState', $corps);
        foreach (['driveVisible', 'gmailVisible', 'calendarVisible', 'tasksVisible', 'outlookVisible',
                  'msCalendarVisible'] as $variable) {
            $this->assertStringContainsString('$' . $variable, $corps, "\$$variable n'est pas calculé.");
        }

        $gabarit = (string)file_get_contents(ROOT . '/templates/settings/connections.php');
        foreach (['driveVisible', 'gmailVisible', 'calendarVisible', 'tasksVisible', 'outlookVisible',
                  'msCalendarVisible', 'oneDriveVisible'] as $variable) {
            $this->assertStringContainsString(
                'if (!empty($' . $variable . ')): ?>',
                $gabarit,
                "La carte gouvernée par \$$variable est rendue sans condition de visibilité."
            );
        }
        // Page sans aucune carte : le dire, plutôt qu'un écran nu inexpliqué.
        $this->assertStringContainsString('anyConnectionVisible', $gabarit);
    }

    /**
     * Masquer un connecteur ne doit jamais emprisonner un accès déjà accordé :
     * qui a relié son compte garde sa carte (en lecture seule) et donc son
     * bouton de déconnexion — sinon le jeton resterait en base sans aucun
     * moyen de le retirer depuis MIA.
     */
    public function testUnCompteDejaRelieGardeDeQuoiSeDeconnecter(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/settings/connections.php');

        // Une carte masquée annonce pourquoi, et propose le retrait. Dix
        // boutons en dur (un par carte masquée) + celui de la closure
        // $removeExpired, qui sert les dix cartes quand la connexion a expiré
        // (cf. ConnectorReconnectBadgeTest).
        $this->assertStringContainsString('$connectorHiddenNotice', $gabarit);
        $this->assertSame(
            11,
            substr_count($gabarit, "te('Retirer cette connexion')"),
            'Chaque connecteur à connexion doit proposer le retrait de son jeton une fois masqué.'
        );

        // Et surtout : plus aucune invitation à (re)connecter un connecteur masqué.
        foreach ([
            ['drive', 'driveFeatureEnabled'],
            ['gmail', 'gmailFeatureEnabled'],
            ['calendar', 'calendarFeatureEnabled'],
            ['tasks', 'tasksFeatureEnabled'],
            ['outlook', 'outlookFeatureEnabled'],
            ['mscalendar', 'msCalendarFeatureEnabled'],
            ['mstodo', 'msTodoFeatureEnabled'],
            ['teams', 'teamsFeatureEnabled'],
        ] as [$route, $feature]) {
            $pos = strpos($gabarit, '/settings/' . $route . '/connect');
            $this->assertNotFalse($pos);
            $this->assertStringContainsString(
                'empty($' . $feature . ')',
                $gabarit,
                "La carte $route ne teste pas l'autorisation de l'administrateur."
            );
        }
    }
}
