<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Internationalisation FR/EN — contrat du socle.
 *
 * Le mécanisme tient en quatre morceaux qui doivent rester alignés :
 *   1. `I18n` est chargé par les TROIS listes de « require » du dépôt
 *      (config/classes.php pour le web ET le worker, tests/bootstrap.php) ;
 *   2. la locale est POSÉE par requête et par job, jamais une fois pour la vie
 *      d'un process ;
 *   3. les gabarits déclarés traduits ne réintroduisent pas de français en dur ;
 *   4. ce qui n'est PAS destiné à l'écran (erreurs d'outils rendues au modèle,
 *      journaux, identifiants persistés) ne passe jamais par le dictionnaire.
 *
 * Casser l'un des quatre sans les autres donne une panne difficile à voir :
 * un worker qui fatale en production seulement, un écran anglais aux dates
 * fausses, une trace d'actions vide, ou des decks invalidés en base.
 */
final class I18nContractTest extends TestCase
{
    /**
     * Le FRONT MOUVANT. Un gabarit entre ici quand son lot est terminé ; le
     * test garantit ensuite qu'aucun littéral français n'y revient.
     *
     * Sans cette liste, le français réapparaît en deux sprints : `develop` est
     * auto-déployée, et rien ne signale une chaîne oubliée.
     *
     * Vide au lot 0 : le socle ne traduit encore aucun écran.
     */
    /**
     * Fichiers JavaScript déclarés traduits. Même rôle de front mouvant que
     * GABARITS_TRADUITS, mais pour le dictionnaire CLIENT (lang/en.client.php).
     */
    private const JS_TRADUITS = [
        'public/assets/js/chat.js',
        // Lot 2.
        'public/assets/js/creations.js',
        'public/assets/js/deck.js',
        'public/assets/js/share.js',
        'public/assets/js/whats-new.js',
        'public/assets/js/autosave.js',
        'public/assets/js/tool-suggest.js',
        'public/assets/js/native-bridge.js',
        'public/assets/js/user-menu.js',
        // Cartes de fichier, boutons de téléchargement des blocs de code et des images.
        'public/assets/js/generated-files.js',
        // Choix proposés en boutons sous une réponse.
        'public/assets/js/choice-buttons.js',
        // Idées d'utilisation de la zone de saisie (phrases traduites côté serveur).
        'public/assets/js/composer-hints.js',
        // Tâches programmées : formulaire (aperçu, contrôles) et liste.
        'public/assets/js/schedules.js',
        // Workflows : rail, éditeur, exécution, fenêtre de lancement.
        'public/assets/js/workflow-rail.js',
        'public/assets/js/workflow-editor.js',
        'public/assets/js/workflow-run.js',
        'public/assets/js/workflows.js',
        // Aperçu d'une page web d'un workflow (historique, exécution) : aucun texte.
        'public/assets/js/html-preview.js',
        // Palette ⌘K : aucun texte (tout vient de window.MIA_PALETTE).
        'public/assets/js/command-palette.js',
        // Bases de connaissances : fiche (sources, documents, explorateur,
        // extraits) et schéma (libellés posés par le serveur).
        'public/assets/js/kb-form.js',
        'public/assets/js/kb-diagram.js',
        // Administration (lot 5) : rôles, journaux, sélecteur de période, menu.
        'public/assets/js/roles.js',
        'public/assets/js/admin-logs.js',
        'public/assets/js/period-picker.js',
        'public/assets/js/nav-signals.js',
        'public/assets/js/nav-drawer.js',
    ];

    /**
     * Le front mouvant côté SOURCES (lot 3). Ces fichiers portent les seuls
     * textes de `src/` destinés à l'écran ou citant l'interface : prompts qui
     * nomment un bouton, et cartes d'approbation des connecteurs.
     *
     * Un fichier n'entre ici QUE si toutes ses clés sont traduites — le test de
     * couverture le vérifie. Le reste de `src/` (erreurs d'outils rendues au
     * modèle, journaux, prompts qui ne citent rien) n'a rien à y faire.
     */
    private const SOURCES_TRADUITES = [
        // Prompts qui CITENT l'interface.
        'src/Controllers/ChatController.php',
        'src/Helpers/UserPreferences.php',
        'src/Models/UserMemory.php',
        'src/Connectors/ConnectorContext.php',
        'src/Connectors/ConnectorRegistry.php',
        // Cartes d'approbation (affichées à l'utilisateur avant toute écriture).
        'src/Drive/UserDriveService.php',
        'src/Gmail/UserGmailService.php',
        'src/Outlook/UserOutlookService.php',
        // Carte de relecture d'un brouillon d'email : préréglages, notes de
        // révision, erreurs de saisie affichées dans la carte.
        'src/Connectors/MailDraftReview.php',
        'src/Calendar/UserCalendarService.php',
        'src/Outlook/UserOutlookCalendarService.php',
        'src/Tasks/UserTasksService.php',
        'src/Todo/UserMicrosoftTodoService.php',
        'src/Teams/UserTeamsService.php',
        'src/Slack/UserSlackService.php',
        'src/N8n/N8nService.php',
        'src/Gamma/GammaService.php',
        // Refus d'un outil dont le modèle est arrêté par son fournisseur
        // (bulle d'erreur du chat, message laissé dans le fil).
        'src/Tools/ToolCatalog.php',
        // Trace « Consultation du cerveau MyBrain » (fiches, panne) sous la bulle.
        'src/MyBrain/MyBrainContext.php',
        // Refus d'une photo de profil (format, poids, adresse injoignable) affichés sur Mon profil.
        'src/Helpers/AvatarStorage.php',
        'src/MyBrain/MyBrainFeed.php',
        'src/Jobs/MyBrainFeedJob.php',
        // Refus « quota atteint » / « service IA suspendu » (modale, job refusé, Mon usage).
        'src/Helpers/CreditQuota.php',
        // Encadrés d'erreur d'un modèle et notes « réponse interrompue » : la
        // cause d'une panne doit se lire dans la langue de qui la subit.
        'src/LLM/LlmErrorPresenter.php',
        // Fichiers livrés dans une conversation : le prompt qui cite le bouton
        // « Télécharger » et les entrées du bouton « Outils », les libellés des
        // formats (carte, menu « Autres formats ») et les échecs affichés dans
        // la carte.
        'src/Files/FileDelivery.php',
        // Choix en boutons : le prompt qui en enseigne la forme (exemple compris).
        'src/Helpers/ChoiceButtons.php',
        // Idées d'utilisation affichées dans la zone de saisie vide.
        'src/Helpers/ComposerHints.php',
        'src/Files/FileFormats.php',
        'src/Files/FileBuildException.php',
        // Titre d'une slide découpée (« Contexte (suite) ») dans une présentation livrée.
        'src/Files/MarkdownDeck.php',
        // Refus d'un modèle PowerPoint d'entreprise (Organisation → Entreprise, carte d'un fichier).
        'src/Slides/PptxTemplate.php',
        // Tâches programmées : la phrase de la règle (« Chaque lundi à 8 h 00 »),
        // les raisons d'une pause ou d'un échec, les messages de l'écran.
        'src/Schedule/Recurrence.php',
        'src/Schedule/Scheduler.php',
        'src/Models/ScheduledTask.php',
        'src/Controllers/ScheduledTaskController.php',
        // Schéma d'une tâche : libellés traduits côté serveur, le script n'en a aucun.
        'src/Schedule/TaskDiagram.php',
        // Réglages → Affichage : libellés des thèmes et des tailles, message d'enregistrement.
        'src/Helpers/DisplayPreferences.php',
        // Assistant préparé par MIA : carte d'approbation, refus de la
        // proposition, message laissé dans la conversation.
        'src/Assistants/AssistantDraft.php',
        // Workflows : libellés de l'éditeur, messages de l'exécution, cartes,
        // modèles livrés, contrôles « À régler ».
        'src/Workflow/WorkflowSpec.php',
        'src/Workflow/WorkflowValues.php',
        'src/Workflow/ArtifactContract.php',
        'src/Workflow/WorkflowEngine.php',
        'src/Workflow/WorkflowCatalog.php',
        'src/Workflow/WorkflowTemplates.php',
        'src/Workflow/WorkflowDraft.php',
        'src/Workflow/WorkflowJournal.php',
        'src/Workflow/WorkflowContentView.php',
        'src/Workflow/WorkflowNotifier.php',
        'src/Workflow/WorkflowLoop.php',
        'src/Workflow/LoopSources.php',
        'src/Workflow/LoopFiles.php',
        'src/Workflow/LoopRunner.php',
        'src/Workflow/WorkflowDeliveries.php',
        'src/Workflow/WorkflowTriggers.php',
        'src/Workflow/WorkflowFiles.php',
        'src/Workflow/WorkflowCreations.php',
        'src/Connectors/OutgoingAttachments.php',
        'src/Controllers/WorkflowHookController.php',
        'src/Models/Workflow.php',
        'src/Models/WorkflowRun.php',
        'src/Controllers/WorkflowController.php',
        // Espaces → Assistants et Bases de connaissances : schémas, état d'une
        // base, racines de l'explorateur de dossiers, erreurs d'import.
        'src/Assistants/AssistantDiagram.php',
        'src/Sync/KbDiagram.php',
        'src/Sync/KbStatus.php',
        'src/Sync/GoogleDriveProvider.php',
        'src/Sync/OneDriveProvider.php',
        'src/Models/AssistantAvatar.php',
        'src/Models/KnowledgeBase.php',
        'src/Models/ProfileDocument.php',
        'src/Models/Conversation.php',
        'src/Controllers/AdminController.php',
        // Administration (lot 5) : menu, onglets, points à régler, diagnostic,
        // rôles et droits, authentification, entreprise, budget et
        // consommation, journaux, sauvegarde, élagage, connecteurs, outils.
        'src/Helpers/AdminMenu.php',
        'src/Helpers/AdminTabs.php',
        'src/Helpers/AdminAccess.php',
        'src/Helpers/NavRegistry.php',
        'src/Helpers/AdminAttention.php',
        'src/Helpers/Diagnostics.php',
        'src/Helpers/Requirements.php',
        'src/Agent/AgentTelemetry.php',
        'src/Controllers/AgentStatsController.php',
        'src/Auth/Permissions.php',
        'src/Models/Role.php',
        'src/Controllers/RoleController.php',
        'src/Models/UserIdentity.php',
        'src/Auth/Sso/SsoRegistry.php',
        'src/Auth/Sso/GoogleSsoProvider.php',
        'src/Auth/Sso/MicrosoftSsoProvider.php',
        'src/Sync/SharePointProvider.php',
        'src/Helpers/CompanyProfile.php',
        'src/Files/DocxTemplate.php',
        'src/Files/OfficePackage.php',
        'src/Billing/AiBudget.php',
        'src/Billing/UsagePeriod.php',
        'src/Billing/UsageInsights.php',
        'src/Billing/UsageSource.php',
        'src/Controllers/BudgetController.php',
        'src/Logs/LogCatalog.php',
        'src/Logs/LogQuery.php',
        'src/Logs/LogView.php',
        'src/Controllers/LogsController.php',
        'src/Backup/BackupService.php',
        'src/Controllers/BackupController.php',
        'src/Controllers/PruningController.php',
        'src/Slack/SlackApp.php',
        'src/Sync/KbSync.php',
        'src/Tools/ToolTriggers.php',
        'src/Models/ChatTool.php',
        'src/RAG/QdrantHealth.php',
        'src/Models/UsageLog.php',
    ];

    private const GABARITS_TRADUITS = [
        // Lot 1 — le chat.
        'templates/chat/index.php',
        'templates/partials/message.php',
        // Palette « Rechercher ou aller à… » (⌘K) : textes posés par le serveur.
        'templates/partials/command-palette.php',
        'templates/partials/user-account-trigger.php',
        'templates/partials/user-account-actions.php',
        'templates/partials/sidebar-shortcuts.php',
        'templates/partials/header-avatar-btn.php',
        'templates/partials/whats-new-modal.php',
        'templates/partials/autosave-hint.php',
        // Lot 2 — paramètres, connexion, partage, à propos.
        'templates/settings/profile.php',
        'templates/settings/ai.php',
        'templates/settings/display.php',
        'templates/settings/usage.php',
        'templates/settings/connections.php',
        'templates/workflows/index.php',
        'templates/workflows/new.php',
        'templates/workflows/edit.php',
        'templates/workflows/run.php',
        'templates/workflows/partials/history.php',
        'templates/workflows/partials/history-runs.php',
        'templates/workflows/partials/history-journal.php',
        'templates/settings/creations.php',
        'templates/settings/oauth-done.php',
        'templates/settings/partials/memory-card.php',
        'templates/settings/schedules.php',
        'templates/settings/schedule-form.php',
        'templates/auth/login.php',
        'templates/share/show.php',
        'templates/about/index.php',
        'templates/app/download.php',
        // Espaces → Assistants et Bases de connaissances (listes, fiches, état).
        'templates/admin/profiles/list.php',
        'templates/admin/profiles/edit.php',
        'templates/admin/knowledge-bases.php',
        'templates/admin/knowledge-bases/edit.php',
        'templates/partials/kb-status.php',
        'templates/partials/kb-status-overview.php',
        // Administration (lot 5) : tout l'arbre templates/admin et ses partiels.
        'templates/partials/admin-nav.php',
        'templates/partials/admin-tabs.php',
        'templates/partials/period-picker.php',
        'templates/partials/company-template-status.php',
        'templates/partials/company-word-template-status.php',
        'templates/admin/agent.php',
        'templates/admin/backup.php',
        'templates/admin/budget-period.php',
        'templates/admin/budget.php',
        'templates/admin/company.php',
        'templates/admin/connectors-slack.php',
        'templates/admin/connectors.php',
        'templates/admin/credits.php',
        'templates/admin/diagnostics.php',
        'templates/admin/index.php',
        'templates/admin/knowledge-bases-sync.php',
        'templates/admin/llms/_foot.php',
        'templates/admin/llms/_head.php',
        'templates/admin/llms/keys.php',
        'templates/admin/llms/models.php',
        'templates/admin/llms/tests.php',
        'templates/admin/logs.php',
        'templates/admin/logs/entries.php',
        'templates/admin/pruning.php',
        'templates/admin/roles.php',
        'templates/admin/settings.php',
        'templates/admin/sso.php',
        'templates/admin/tools.php',
        'templates/admin/usage-overview.php',
        'templates/admin/usage-pdf.php',
        'templates/admin/usage.php',
        'templates/admin/user-groups.php',
        'templates/admin/users.php',
        'templates/admin/vector-database.php',
    ];

    /**
     * Gabarits qui gardent volontairement `lang="fr"`, avec la raison.
     * Tout le reste doit rendre la locale courante.
     */
    private const LANG_FR_ASSUME = [
        // Rendus SANS base de données (Bootstrap::renderConfigError, setup) :
        // ils doivent s'afficher quand tout le reste est mort, donc aucune
        // dépendance — cf. l'en-tête d'ErrorHandler.
        'templates/errors/config.php',
        'templates/errors/database.php',
        'templates/errors/500.php',
        'templates/setup/index.php',
        'templates/setup/next-steps.php',
    ];

    /**
     * Préfixes encore entièrement français. `lang` doit décrire la langue RÉELLE
     * du contenu : annoncer « en » sur une page française tromperait lecteurs
     * d'écran et propositions de traduction du navigateur. Ce préfixe disparaît
     * avec le lot 5.
     */
    private const PREFIXES_FR_ASSUMES = [
        // Vide depuis le lot 5 : l'administration est traduite, ses pages
        // rendent la locale comme les autres.
    ];

    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");

        return (string) file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // 1. Le socle est chargé partout
    // ------------------------------------------------------------------

    /**
     * Les trois listes de « require ». L'oubli dans config/classes.php ne casse
     * que le worker, et seulement en production — le mode de panne que ce
     * fichier documente lui-même en tête.
     */
    public function testI18nEstChargeParLesTroisListes(): void
    {
        $this->assertStringContainsString(
            "require ROOT . '/src/Helpers/I18n.php';",
            self::lire('config/classes.php'),
            'config/classes.php ne charge pas I18n : le worker fatalera en production.'
        );

        $this->assertStringContainsString(
            "'/src/Helpers/I18n.php'",
            self::lire('tests/bootstrap.php'),
            'tests/bootstrap.php ne charge pas I18n.'
        );
    }

    /** I18n ne doit dépendre de rien AU CHARGEMENT : il est requis très tôt. */
    public function testLeSocleNaAucuneDependanceAuChargement(): void
    {
        $source = self::lire('src/Helpers/I18n.php');
        // `require $fichier` (chargement d'un dictionnaire) est une lecture de
        // DONNÉES, à l'exécution. Ce qui est interdit ici, c'est de dépendre
        // d'une autre CLASSE du dépôt : I18n est requis avant presque tout.
        $this->assertStringNotContainsString('require ROOT', $source);
        $this->assertStringNotContainsString('Database::getInstance', $source);
    }

    // ------------------------------------------------------------------
    // 2. La locale est posée par requête ET par job
    // ------------------------------------------------------------------

    public function testLaLocaleEstPoseeApresLaSession(): void
    {
        $index = self::lire('public/index.php');
        $this->assertStringContainsString('I18n::use(I18n::resolve());', $index);

        $posSession = strpos($index, 'Session::start();');
        $posLocale  = strpos($index, 'I18n::use(I18n::resolve());');
        $posDispatch = strpos($index, '$router->dispatch(');

        $this->assertIsInt($posSession);
        $this->assertIsInt($posLocale);
        $this->assertIsInt($posDispatch);
        $this->assertGreaterThan(
            $posSession,
            $posLocale,
            'resolve() lit la session : elle doit être démarrée avant.'
        );
        $this->assertLessThan(
            $posDispatch,
            $posLocale,
            'La locale doit être posée AVANT tout rendu.'
        );
    }

    /**
     * Par JOB, à côté du Settings::refresh() existant — jamais une fois au
     * démarrage du worker. I18n::$locale est un statique de process, et le
     * worker enchaîne les jobs de plusieurs comptes : la panne serait celle,
     * déjà documentée, du cache de Settings empoisonné pour la vie du process.
     */
    public function testLaLocaleEstReposeeAChaqueJob(): void
    {
        $queue = self::lire('src/Jobs/JobQueue.php');
        $this->assertStringContainsString('I18n::use(I18n::forUser($user));', $queue);
        // Langue de l'écran qui a lancé le job, sinon celle du compte.
        $this->assertStringContainsString('I18n::use(self::localeFor($user, $payload));', $queue);

        $this->assertStringNotContainsString(
            'I18n::use',
            self::lire('scripts/_cli_bootstrap.php'),
            "Le bootstrap CLI ne doit poser AUCUNE locale : elle se pose par job."
        );
    }

    // ------------------------------------------------------------------
    // 3. Les gabarits
    // ------------------------------------------------------------------

    /**
     * `lang` doit décrire la langue RÉELLE du contenu. Les exceptions sont
     * explicites et motivées (cf. LANG_FR_ASSUME) ; tout autre gabarit qui
     * ouvre un <html> doit rendre la locale.
     */
    public function testLesGabaritsRendentLaLocaleSaufExceptionsMotivees(): void
    {
        $racine = dirname(__DIR__, 2);

        foreach ($this->gabarits() as $chemin) {
            $source = file_get_contents($racine . '/' . $chemin) ?: '';
            if (!str_contains($source, '<html lang="fr"')) {
                continue;
            }

            foreach (self::PREFIXES_FR_ASSUMES as $prefixe) {
                if (str_starts_with($chemin, $prefixe)) {
                    continue 2;
                }
            }

            $this->assertContains(
                $chemin,
                self::LANG_FR_ASSUME,
                "$chemin fige lang=\"fr\" sans figurer dans LANG_FR_ASSUME."
            );
        }
    }

    /**
     * Le pont client. Placé dans head-meta.php, inclus par toutes les pages
     * applicatives (page de connexion comprise), donc une seule édition.
     *
     * Le drapeau JSON_UNESCAPED_UNICODE est INTERDIT ici, contrairement au
     * reste du dépôt : ce partial est requis AVANT <meta charset>, et seul un
     * bloc pur ASCII est insensible à l'encodage supposé par le navigateur.
     */
    public function testLePontClientEstPoseSansUnicodeEchappe(): void
    {
        $meta = self::lire('templates/partials/head-meta.php');

        $this->assertStringContainsString('window.I18N', $meta);

        // On vise l'APPEL, pas le fichier : le commentaire du partial cite
        // justement le drapeau interdit pour expliquer son absence.
        $this->assertSame(
            1,
            preg_match('/json_encode\(I18n::clientDictionary\(\)([^)]*)\)/', $meta, $appel),
            'head-meta ne sérialise pas le dictionnaire client.'
        );
        $this->assertStringNotContainsString(
            'JSON_UNESCAPED_UNICODE',
            $appel[1],
            'head-meta est requis avant <meta charset> : le bloc doit rester ASCII.'
        );
        $this->assertStringContainsString('window.t =', $meta);
        $this->assertStringContainsString('window.tn =', $meta);
    }

    /**
     * Aucun `<?= t(` ni `<?= te(` DANS un bloc <script> : une apostrophe
     * typographique — omniprésente dans le corpus français — casserait le
     * script sans erreur PHP. C'est tj() qui pose ses propres guillemets.
     */
    public function testLesGabaritsNutilisentQueTjDansLeJavaScript(): void
    {
        $racine = dirname(__DIR__, 2);

        foreach ($this->gabarits() as $chemin) {
            $source = file_get_contents($racine . '/' . $chemin) ?: '';

            foreach ($this->blocsScript($source) as $bloc) {
                $this->assertDoesNotMatchRegularExpression(
                    '/<\?=\s*te?\s*\(/',
                    $bloc,
                    "$chemin : t()/te() dans un <script> — utiliser tj()."
                );
            }
        }
    }

    /** Le front mouvant : plus de français en dur dans ce qui est déclaré traduit. */
    public function testLesGabaritsTraduitsNontPlusDeFrancaisEnDur(): void
    {
        if (self::GABARITS_TRADUITS === []) {
            $this->assertTrue(true, 'Lot 0 : aucun gabarit encore traduit.');

            return;
        }

        foreach (self::GABARITS_TRADUITS as $chemin) {
            $restes = $this->litterauxFrancais(self::lire($chemin));
            $this->assertSame(
                [],
                $restes,
                "$chemin est déclaré traduit mais porte encore : " . implode(' | ', array_slice($restes, 0, 5))
            );
        }
    }

    // ------------------------------------------------------------------
    // 4. Le dictionnaire
    // ------------------------------------------------------------------

    /** Trié, sans doublon : le fichier fera des milliers de lignes, les diffs doivent rester lisibles. */
    public function testLeDictionnaireEstTrieEtSansDoublon(): void
    {
        foreach (['lang/en.php', 'lang/en.client.php'] as $chemin) {
            $dict = require dirname(__DIR__, 2) . '/' . $chemin;
            $this->assertIsArray($dict, "$chemin ne rend pas un tableau.");

            $cles = array_keys($dict);
            $tri  = $cles;
            sort($tri, SORT_STRING);

            $this->assertSame($tri, $cles, "$chemin n'est pas trié par clé.");
        }
    }

    /** Aucune valeur vide : elle afficherait du blanc là où le repli français ferait mieux. */
    public function testLeDictionnaireNaAucuneValeurVide(): void
    {
        // Les dictionnaires sont vides au lot 0 : l'assertion vaut alors pour
        // leur existence, sans quoi PHPUnit signale un test sans assertion.
        $this->assertNotSame([], $this->dictionnaires(), 'Aucun dictionnaire trouvé.');

        foreach (['lang/en.php', 'lang/en.client.php'] as $chemin) {
            foreach ((array) (require dirname(__DIR__, 2) . '/' . $chemin) as $cle => $valeur) {
                $this->assertIsString($valeur, "$chemin : « $cle » n'est pas une chaîne.");
                $this->assertNotSame('', trim($valeur), "$chemin : « $cle » est vide.");
            }
        }
    }

    /** Les substituants {nom} doivent survivre à la traduction, des deux côtés. */
    public function testLesSubstituantsSurviventALaTraduction(): void
    {
        $this->assertNotSame([], $this->dictionnaires(), 'Aucun dictionnaire trouvé.');

        foreach (['lang/en.php', 'lang/en.client.php'] as $chemin) {
            foreach ((array) (require dirname(__DIR__, 2) . '/' . $chemin) as $source => $traduction) {
                $this->assertSame(
                    $this->substituants((string) $source),
                    $this->substituants((string) $traduction),
                    "$chemin : les substituants diffèrent pour « $source »."
                );
            }
        }
    }

    /**
     * Une clé est du TEXTE, jamais du HTML : aucune entité (`&nbsp;`, `&amp;`…)
     * dans une clé appelée, ni dans le dictionnaire.
     *
     * te() échappe tout ce qu'il rend, l'entité comprise : `&nbsp;` ressort
     * `&amp;nbsp;`. L'étiquette sous chaque réponse affichait ainsi
     * « 6,4&nbsp;s » en toutes lettres, et la page « À propos » titrait
     * « Images &amp; vidéos ». Les deux viennent de la migration, qui a
     * enveloppé du HTML dans te() sans en convertir les entités. Le caractère
     * s'écrit lui-même — U+00A0 pour l'espace insécable, `&` pour
     * l'esperluette — et l'échappement reste l'affaire de la fonction de sortie.
     */
    public function testAucuneEntiteHtmlDansUneCleNiDansUneTraduction(): void
    {
        $entite = '/&(?:[a-z][a-z0-9]*|#[0-9]+|#x[0-9a-f]+);/i';
        $fautes = [];

        $sources = $this->fichiersPhpDeSrc() + $this->fichiersJs();
        foreach ($this->gabarits() as $chemin) {
            $sources[$chemin] = self::lire($chemin);
        }
        foreach ($sources as $chemin => $source) {
            foreach ($this->clesAppelees($source) as $cle) {
                if (preg_match($entite, $cle) === 1) {
                    $fautes[] = "$chemin : « $cle »";
                }
            }
        }

        foreach ($this->dictionnaires() as $chemin => $dict) {
            foreach ($dict as $cle => $traduction) {
                if (preg_match($entite, (string) $cle) === 1 || preg_match($entite, (string) $traduction) === 1) {
                    $fautes[] = "$chemin : « $cle » => « $traduction »";
                }
            }
        }

        $this->assertSame(
            [],
            $fautes,
            "Entité HTML dans une clé ou une traduction — écrire le caractère lui-même (U+00A0, &) :\n"
            . implode("\n", $fautes)
        );

        // Le cas qui l'a révélé : la clé à espace insécable réel est trouvée,
        // et rien n'est échappé deux fois.
        I18n::use('en');
        $this->assertSame("12\u{a0}credits", te("{n}\u{a0}crédits", ['n' => '12']));
        I18n::use('fr');
    }

    /**
     * Couverture des ensembles FERMÉS de libellés : un libellé ajouté au
     * catalogue sans sa traduction resterait en français au milieu d'un écran
     * anglais, sans qu'aucune erreur ne le signale. Le test tombe alors, et
     * nomme la chaîne manquante.
     */
    public function testLesLibellesDActionSontTousTraduits(): void
    {
        // ChatController dépend de vendor/, il n'est pas dans tests/bootstrap.php
        // — même chargement à la demande que dans ActionTraceTest.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';

        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.php');

        foreach (ChatController::ACTION_LABELS as $type => $libelle) {
            $this->assertArrayHasKey(
                $libelle,
                $dict,
                "Le libellé de « $type » n'a pas de traduction : « $libelle »."
            );
        }
    }

    /**
     * Toute clé APPELÉE par un gabarit déclaré traduit doit exister dans le
     * dictionnaire. Sans ce test, un te('…') ajouté sans sa traduction laisse
     * un mot français au milieu d'un écran anglais — et rien ne le signale,
     * puisque le repli sur la source est précisément conçu pour être discret.
     */
    public function testLesGabaritsTraduitsOntToutesLeursClesAuDictionnaire(): void
    {
        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.php');
        $manquantes = [];

        foreach (self::GABARITS_TRADUITS as $chemin) {
            foreach ($this->clesAppelees(self::lire($chemin)) as $cle) {
                if (!array_key_exists($cle, $dict)) {
                    $manquantes[] = $chemin . ' : ' . $cle;
                }
            }
        }

        $this->assertSame([], $manquantes, "Clés sans traduction :\n" . implode("\n", $manquantes));
    }

    /**
     * Même règle pour les SOURCES traduites (lot 3) : une clé appelée sans
     * traduction laisserait une carte d'approbation à moitié française devant
     * un utilisateur anglophone — et, côté prompt, enverrait l'assistant citer
     * un bouton qui n'existe pas dans sa langue.
     */
    public function testLesSourcesTraduitesOntToutesLeursClesAuDictionnaire(): void
    {
        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.php');
        $manquantes = [];

        foreach (self::SOURCES_TRADUITES as $chemin) {
            foreach ($this->clesAppelees(self::lire($chemin)) as $cle) {
                if (!array_key_exists($cle, $dict)) {
                    $manquantes[] = $chemin . ' : ' . $cle;
                }
            }
        }

        $this->assertSame([], $manquantes, "Clés sans traduction :\n" . implode("\n", $manquantes));
    }

    /**
     * Une énumération de libellés cités passe par I18n::listeCitee.
     *
     * Le piège, mesuré : `implode(' », « ', $items)` à l'intérieur d'une phrase
     * qui ouvre le premier guillemet et ferme le dernier. Traduite, la phrase
     * prend les guillemets anglais et le séparateur reste français —
     * `“Google Drive files », « Gmail messages”`. Les guillemets doivent donc
     * vivre AVEC le libellé.
     */
    public function testUneEnumerationDeLibellesPasseParLeHelper(): void
    {
        $this->assertStringContainsString(
            'public static function listeCitee(array $items): string',
            self::lire('src/Helpers/I18n.php')
        );

        foreach ($this->fichiersPhpDeSrc() as $chemin => $source) {
            // Le helper lui-même a le droit de citer le motif : son docblock
            // explique précisément le piège qu'il corrige.
            if (str_ends_with($chemin, 'src/Helpers/I18n.php')) {
                continue;
            }
            $this->assertStringNotContainsString(
                "implode(' », « '",
                $source,
                "$chemin joint des libellés cités à la main : passer par I18n::listeCitee."
            );
        }

        // Comportement : les guillemets suivent la locale des DEUX côtés.
        I18n::use('fr');
        $this->assertSame('« A », « B »', I18n::listeCitee(['A', 'B']));
        I18n::use('en');
        $this->assertSame('“A”, “B”', I18n::listeCitee(['A', 'B']));
        I18n::use('fr');
    }

    /**
     * Un prompt qui NOMME l'interface doit la nommer comme elle s'affiche.
     *
     * C'est la raison d'être du lot 3 : `capabilityGuardrails()` cite le bouton
     * « Outils » et ses entrées, `UserPreferences` reprend le titre « Votre
     * contexte », `UserMemory` celui de « MIA pour vous ». Traduire l'écran
     * sans ces fragments donnerait un assistant anglais qui renvoie vers des
     * libellés français — le seul couplage que la traduction casse en silence,
     * et qu'aucun test d'affichage ne verrait.
     *
     * On vérifie donc que chaque libellé cité EXISTE aussi comme clé appelée
     * par un gabarit (le nom que l'écran affiche vraiment).
     */
    public function testLesPromptsCitentDesLibellesQueLEcranAffiche(): void
    {
        $sources = '';
        foreach (self::SOURCES_TRADUITES as $chemin) {
            $sources .= self::lire($chemin);
        }
        $ecran = '';
        foreach (self::GABARITS_TRADUITS as $chemin) {
            $ecran .= implode("\n", $this->clesAppelees(self::lire($chemin))) . "\n";
        }
        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.php');

        // Libellés d'écran cités par les prompts, et le nom du menu du composer.
        foreach (['Créer', 'Connecteurs', 'Image', 'Page web', 'Vidéo', 'Comptes connectés', 'Préférences IA', 'Réglages'] as $libelle) {
            $this->assertStringContainsString(
                $libelle,
                $sources,
                "Aucun prompt ne cite « $libelle » : le lot 3 a-t-il été défait ?"
            );
            $this->assertStringContainsString(
                $libelle,
                $ecran,
                "« $libelle » est cité par un prompt mais n'est plus un libellé de l'écran."
            );
            $this->assertArrayHasKey(
                $libelle,
                $dict,
                "« $libelle » est cité par un prompt sans traduction : l'assistant anglais nommerait un bouton français."
            );
        }

        // Les deux sections de « ce que MIA sait de vous » : le nom est une
        // décision produit, il doit être identique des deux côtés.
        foreach (['MIA pour vous' => 'src/Models/UserMemory.php',
                  'Votre contexte' => 'src/Helpers/UserPreferences.php'] as $titre => $fichier) {
            $this->assertStringContainsString($titre, self::lire($fichier));
            $this->assertArrayHasKey($titre, $dict, "« $titre » doit avoir une traduction unique.");
        }
    }

    /**
     * Aucune variable locale ne doit s'appeler « t » dans un fichier qui appelle
     * la fonction de traduction du même nom.
     *
     * Le piège est silencieux à l'écriture et brutal à l'exécution : dans la
     * portée d'un `const t = …` ou d'un `.map(t => …)`, l'expression `t('…')`
     * n'appelle pas le traducteur mais tente d'invoquer un objet, et lève
     * « t is not a function ». Aucun test d'affichage ne l'attrape, puisque le
     * code plante avant d'afficher quoi que ce soit.
     *
     * Le remède est le renommage de la variable locale, pas un window.t() de
     * contournement : celui-ci laisserait le piège en place pour le prochain
     * libellé ajouté dans la même fonction.
     */
    public function testAucuneVariableLocaleNeMasqueLaFonctionDeTraduction(): void
    {
        $declaration = '/(?:\b(?:const|let|var)\s+t\s*=|\(\s*t\s*\)\s*=>'
            . '|\.\s*(?:map|forEach|filter|some|every|find|reduce)\s*\(\s*t\s*=>'
            . '|function\s*\(\s*t\s*\))/';

        foreach ($this->fichiersJs() as $chemin => $source) {
            if (!preg_match("/(?<![\\w.])t\\(\\s*['\"]/", $source)) {
                continue;   // ce fichier n'appelle pas t() : rien à protéger
            }

            $this->assertSame(
                0,
                preg_match_all($declaration, $source),
                "$chemin déclare une variable locale « t » alors qu'il appelle t() : "
                . "renommez la variable, elle masque le traducteur."
            );
        }
    }

    /**
     * Même garde côté client. Une clé passée à t() dans un .js sans entrée dans
     * lang/en.client.php reste en français : le repli est silencieux par
     * conception, donc seul un test peut le voir.
     */
    public function testLesFichiersJsTraduitsOntToutesLeursClesAuDictionnaire(): void
    {
        $dict = (array) (require dirname(__DIR__, 2) . '/lang/en.client.php');
        $manquantes = [];

        foreach (self::JS_TRADUITS as $chemin) {
            foreach ($this->clesAppelees(self::lire($chemin)) as $cle) {
                if (!array_key_exists($cle, $dict)) {
                    $manquantes[] = $chemin . ' : ' . $cle;
                }
            }
        }

        $this->assertSame([], $manquantes, "Clés client sans traduction :\n" . implode("\n", $manquantes));
    }

    /**
     * Le défaut d'instance est RÉGLABLE, et son seul écran de réglage le pose
     * bien. Sans champ, la clé `default_lang` existerait en base sans qu'aucun
     * administrateur puisse la toucher — et le guide documenterait un réglage
     * fantôme. Un `<select>` (et non une case) parce qu'il est TOUJOURS présent
     * dans le POST : le piège maison « case absente = décochée » ne peut donc
     * pas l'effacer au premier enregistrement de la page.
     */
    public function testLaLangueParDefautDeLInstanceEstReglable(): void
    {
        $gabarit = self::lire('templates/admin/settings.php');
        $this->assertStringContainsString('I18n::SETTING_DEFAULT', $gabarit);
        $this->assertStringContainsString('<select name="<?= I18n::SETTING_DEFAULT ?>"', $gabarit);
        $this->assertStringContainsString('I18n::LOCALES', $gabarit);

        // Écrit par le handler, et refusé si la langue n'est pas servie.
        $ctrl = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString('I18n::SETTING_DEFAULT,', $ctrl);
        $this->assertStringContainsString(
            "\$key === I18n::SETTING_DEFAULT && \$value !== '' && !I18n::isSupported(\$value)",
            $ctrl,
            'Une langue inconnue enregistrée rendrait toute l\'instance au repli français, sans le dire.'
        );

        // Le manifeste PWA est servi SANS session : il ne peut porter que ce
        // défaut. Le laisser à « fr » en dur ferait annoncer une application
        // française sur une instance anglophone.
        $this->assertStringContainsString(
            "'lang'             => I18n::instanceDefault(),",
            self::lire('src/Controllers/PwaController.php')
        );

        // Les guides annoncent la bascule et ses limites.
        $this->assertStringContainsString('Changer la langue (français / anglais)', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Langue par défaut', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('interface-anglais', self::lire('docs/NOUVEAUTES.md'));
    }

    /**
     * Un réglage éditorial LIVRÉ suit la langue du lecteur ; un texte écrit par
     * l'administrateur, jamais.
     *
     * Le repli d'un `Settings::get($cle, t('…'))` est un LEURRE pour ces deux
     * clés : `welcome_message` est semée en base par 004_settings.sql et
     * l'écran d'administration pré-remplit ses champs avec le texte français,
     * qu'un simple enregistrement persiste. Le défaut n'est donc jamais atteint
     * et l'écran d'accueil saluait en français au milieu d'une interface
     * anglaise. Ce test interdit le motif au lieu d'attendre le prochain écran
     * qui le recopierait.
     */
    public function testLesReglagesEditoriauxLivresPassentParLeHelper(): void
    {
        // Comportement : livré (ou vide) => traduit ; personnalisé => intact.
        I18n::use('en');
        $this->assertSame('How can I help you?', I18n::editorial('Comment puis-je vous aider ?', 'Comment puis-je vous aider ?'));
        $this->assertSame('How can I help you?', I18n::editorial('', 'Comment puis-je vous aider ?'));
        $this->assertSame('How can I help you?', I18n::editorial(null, 'Comment puis-je vous aider ?'));
        // Blancs de bord et retours à la ligne d'un champ recopié : un octet
        // d'écart ferait retomber sur du français en pleine interface anglaise.
        $this->assertSame('How can I help you?', I18n::editorial("  Comment puis-je\n vous aider ?  ", 'Comment puis-je vous aider ?'));
        $this->assertSame('Bienvenue chez ACME', I18n::editorial('Bienvenue chez ACME', 'Comment puis-je vous aider ?'));
        I18n::use('fr');
        $this->assertSame('Comment puis-je vous aider ?', I18n::editorial('Comment puis-je vous aider ?', 'Comment puis-je vous aider ?'));

        // Câblage : tout écran affichant ces clés passe par le helper, et aucun
        // ne garde le repli traduit, qui ne peut pas s'exécuter.
        foreach ([
            'templates/chat/index.php'          => ['welcome_message', 'app_tagline'],
            'templates/auth/login.php'          => ['app_tagline'],
            'templates/partials/admin-nav.php'  => ['app_tagline'],
        ] as $gabarit => $cles) {
            $code = self::lire($gabarit);
            foreach ($cles as $cle) {
                $this->assertMatchesRegularExpression(
                    "/I18n::editorial\\(Settings::get\\('" . preg_quote($cle, '/') . "', ''\\)/",
                    $code,
                    "$gabarit doit servir « $cle » par I18n::editorial()."
                );
                $this->assertDoesNotMatchRegularExpression(
                    "/Settings::get\\('" . preg_quote($cle, '/') . "',\\s*t\\(/",
                    $code,
                    "$gabarit : repli traduit inatteignable — la valeur est en base, le défaut n'est jamais lu."
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 5. Ce qui ne se traduit PAS
    // ------------------------------------------------------------------

    /**
     * Les erreurs des gestionnaires d'outils reviennent AU MODÈLE, qui les
     * reformule dans la langue de l'utilisateur. Les traduire n'aurait aucun
     * effet visible et casserait une trentaine d'assertions.
     * Le commentaire de ChatController devient ici exécutable.
     */
    public function testLesErreursDOutilNePassentPasParLeDictionnaire(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');

        $this->assertDoesNotMatchRegularExpression(
            "/return\s*\[\s*'error'\s*=>\s*te?\(/",
            $chat,
            "Les erreurs d'outils partent au modèle : elles restent en français."
        );
    }

    /** Les journaux ne sont lus par personne : jamais de t() dedans. */
    public function testLesJournauxNeSontJamaisTraduits(): void
    {
        foreach ($this->fichiersPhpDeSrc() as $chemin => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/error_log\(\s*te?\(/',
                $source,
                "$chemin traduit un journal."
            );
        }
    }

    /**
     * Les prompts LLM restent en français (décision de périmètre), à la seule
     * exception de ceux qui CITENT l'interface — traités au lot 3.
     * Ces trois fichiers sont du contenu rédigé, jamais du libellé.
     */
    public function testLesBibliothequesDePromptsRestentHorsDuDictionnaire(): void
    {
        foreach ([
            'src/Assistants/PromptStarters.php',
            'src/Tools/ToolPrompts.php',
            'src/Models/DefaultAssistant.php',
        ] as $chemin) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z_>$])te?\(\s*[\'"]/',
                self::lire($chemin),
                "$chemin ne doit pas être internationalisé (contenu rédigé)."
            );
        }
    }

    /**
     * Les identifiants persistés en base restent en ASCII. Les traduire
     * invaliderait les decks existants (DeckSpec), les journaux d'usage
     * (UsageSource) et les préférences (ai_style).
     */
    public function testLesIdentifiantsPersistesRestentDesSlugsAscii(): void
    {
        $listes = [
            'src/Slides/DeckSpec.php'          => ['ROLES', 'LAYOUTS'],
            'src/Helpers/UserPreferences.php'  => ['STYLES'],
        ];

        foreach ($listes as $chemin => $constantes) {
            $source = self::lire($chemin);
            foreach ($constantes as $constante) {
                $this->assertStringContainsString(
                    'const ' . $constante,
                    $source,
                    "$chemin : constante $constante introuvable (renommée ?)."
                );
            }
        }

        // Les valeurs elles-mêmes : aucun accent, sinon elles ont été traduites.
        $this->assertSame(
            [],
            array_filter(DeckSpec::ROLES, static fn (string $r): bool => (bool) preg_match('/[^a-z0-9_-]/', $r)),
            'DeckSpec::ROLES doit rester des slugs ASCII : ils sont persistés dans les specs.'
        );
        $this->assertSame(
            [],
            array_filter(array_keys(UserPreferences::STYLES), static fn ($k): bool => (bool) preg_match('/[^a-z0-9_-]/', (string) $k)),
            'Les clés de UserPreferences::STYLES sont stockées dans users.ai_style.'
        );
    }

    // ------------------------------------------------------------------
    // 6. La bascule
    // ------------------------------------------------------------------

    /**
     * Le sélecteur est une commande FLOTTANTE, rendue par un partial unique.
     *
     * Il ne peut être rattaché ni à la toolbar du chat (masquée en mode
     * assistant) ni à une barre d'administration (inexistante ailleurs) : il
     * serait absent la moitié du temps. D'où la position fixe, pendant du
     * bouton de réouverture de la barre latérale, qui occupe le haut à gauche.
     */
    public function testLeSelecteurDeLangueEstUnPartialUniqueEtFlottant(): void
    {
        $partial = self::lire('templates/partials/lang-switcher.php');
        $this->assertStringContainsString('I18n::LOCALES', $partial);
        $this->assertStringContainsString('role="listbox"', $partial);
        $this->assertStringContainsString('aria-selected', $partial);
        $this->assertStringContainsString("localeInfo(", $partial);

        // Un seul markup : le contrôle ne doit PAS être recopié dans un autre
        // partial, sinon deux exemplaires du même réglage divergeraient.
        $this->assertStringNotContainsString(
            'lang-switcher',
            self::lire('templates/partials/user-account-actions.php'),
            'Le sélecteur ne doit exister qu\'une fois, dans son partial.'
        );

        // Inclus par les trois surfaces : le chat, l'ossature admin/paramètres
        // (qui sert aussi « À propos ») et la page de CONNEXION — c'est là
        // qu'un anglophone arrive en premier.
        foreach ([
            'templates/chat/index.php',
            'templates/partials/admin-nav.php',
            'templates/auth/login.php',
        ] as $hote) {
            $this->assertStringContainsString(
                "partials/lang-switcher.php'",
                self::lire($hote),
                "$hote n'inclut pas le sélecteur de langue."
            );
        }

        // user-menu.js est le SEUL script chargé par toutes les pages
        // applicatives : le comportement doit y vivre, pas dans autosave.js
        // (absent du chat). La page de connexion le charge explicitement.
        $js = self::lire('public/assets/js/user-menu.js');
        $this->assertStringContainsString('.lang-switcher-option', $js);
        $this->assertStringContainsString("'/settings/lang'", $js);
        $this->assertStringContainsString('X-CSRF-Token', $js);
        $this->assertStringContainsString(
            'user-menu.js',
            self::lire('templates/auth/login.php'),
            'La page de connexion doit charger le comportement du sélecteur.'
        );

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.lang-switcher-option', $css);
        // display:… l'emporte sur l'attribut hidden — piège déjà payé quatre
        // fois dans ce dépôt (.kb-card, .pf-ai-panel, .kb-picker-panel…).
        $this->assertStringContainsString('.lang-switcher-list[hidden] { display: none !important; }', $css);
        // Un listbox qui ne se parcourt qu'à la souris n'en est pas un : Échap
        // referme, les flèches circulent entre les langues.
        foreach (["'Escape'", "'ArrowDown'", "'ArrowUp'", "'Home'", "'End'"] as $touche) {
            $this->assertStringContainsString($touche, $js, "user-menu.js n'écoute pas la touche $touche sur le sélecteur.");
        }
        // Le bouton fixe suit la safe-area (app installée) comme la rangée du
        // header qu'il rejoint — sinon il passe sous la barre d'état.
        $this->assertStringContainsString('.lang-switcher { top: max(8px, var(--safe-top));', $css);

        // Les headers mobiles réservent la place du bouton flottant, sinon il
        // recouvre l'avatar du compte. Et l'ORDRE compte autant que la règle :
        // la DERNIÈRE déclaration de padding horizontal de chaque header doit
        // être la réserve. Placée plus haut dans le fichier, elle perdait la
        // cascade face au `padding: 8px 10px` puis au `padding-inline: 8px`
        // (≤ 420 px, spécificité 0,2,0) de l'admin — chevauchement de 42 px
        // mesuré sur l'avatar, sans une erreur.
        foreach (['.mobile-header', '.admin-mobile-header'] as $header) {
            $blocs = self::blocsCssAPaddingHorizontal($css, $header);
            $this->assertNotEmpty($blocs, "$header : aucune règle de padding trouvée.");
            $this->assertStringContainsString(
                '40px',
                end($blocs),
                "$header : la réserve du sélecteur de langue (40 px) doit être la DERNIÈRE règle de padding horizontal — cascade."
            );
        }
    }

    /**
     * Corps des blocs CSS dont un sélecteur se TERMINE par $classe (la classe
     * elle-même — ni ses descendants, ni ses dérivés « -titre ») et qui posent
     * un padding horizontal (`padding`, `padding-inline`, `padding-right`),
     * dans l'ordre du fichier. Commentaires retirés d'abord : ils citent
     * volontiers des règles, accolades comprises.
     *
     * @return list<string>
     */
    private static function blocsCssAPaddingHorizontal(string $css, string $classe): array
    {
        $css   = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $blocs = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as [, $selecteurs, $corps]) {
            $vise = false;
            foreach (explode(',', $selecteurs) as $sel) {
                if (preg_match('/(?:^|\s)' . preg_quote($classe, '/') . '$/', trim($sel)) === 1) {
                    $vise = true;
                    break;
                }
            }
            if ($vise && preg_match('/(?:^|[\s;])padding(?:-inline|-right)?\s*:/', $corps) === 1) {
                $blocs[] = $corps;
            }
        }

        return $blocs;
    }

    /** Chaque langue servie déclare son nom natif et son drapeau. */
    public function testChaqueLangueDeclareSonNomEtSonDrapeau(): void
    {
        foreach (I18n::LOCALES as $code => $meta) {
            $this->assertArrayHasKey('court', $meta, $code);
            $this->assertArrayHasKey('nom', $meta, $code);
            $this->assertArrayHasKey('drapeau', $meta, $code);
            $this->assertNotSame('', trim($meta['nom']), $code);
            $this->assertNotSame('', trim($meta['drapeau']), $code);
        }

        // Nom NATIF : « English », jamais « Anglais ». C'est la convention des
        // sélecteurs de langue, et la seule lisible pour qui ne comprend pas la
        // langue courante.
        $this->assertSame('English', I18n::LOCALES['en']['nom']);
        $this->assertSame('Français', I18n::LOCALES['fr']['nom']);

        // Code inconnu : repli sur le français, jamais d'exception — c'est un
        // libellé d'interface, il ne doit pas faire tomber une page.
        $this->assertSame(I18n::LOCALES['fr'], I18n::localeInfo('xx'));
    }

    public function testLaRouteDeBasculeExisteEtEstServie(): void
    {
        $this->assertStringContainsString(
            "\$router->post('/settings/lang',",
            self::lire('public/index.php')
        );
        $this->assertStringContainsString(
            'public function updateLang(): void',
            self::lire('src/Controllers/UserSettingsController.php')
        );
    }

    /**
     * La bascule sert AUSSI la page de connexion, où aucun compte ne peut
     * porter le choix. Exiger l'authentification y rendrait le bouton mort.
     */
    public function testLaBasculeNexigePasDetreConnecte(): void
    {
        $source = self::lire('src/Controllers/UserSettingsController.php');
        $debut  = strpos($source, 'public function updateLang(): void');
        $this->assertIsInt($debut);

        // Borné à la méthode SUIVANTE : une fenêtre de taille fixe déborderait
        // sur le voisin, et l'assertion « pas de requireAuth » passerait alors
        // pour fausse à cause d'une méthode qui, elle, a tout à fait raison
        // de l'appeler.
        $suite  = strpos($source, "\n    public function ", $debut + 10);
        $corps  = substr($source, $debut, ($suite !== false ? $suite - $debut : 1400));
        $this->assertStringNotContainsString('AuthMiddleware::requireAuth', $corps);
        $this->assertStringContainsString("Session::set('lang'", $corps);
        $this->assertStringContainsString('UserPreferences::saveLang', $corps);
    }

    /** Le choix fait avant connexion doit être adopté par le compte. */
    public function testLeChoixDeSessionEstAdopteALaConnexion(): void
    {
        $auth = self::lire('src/Controllers/AuthController.php');
        $this->assertSame(
            3,
            substr_count($auth, 'I18n::adoptSessionChoice'),
            'Les trois points de connexion doivent adopter le choix de session.'
        );
    }

    // ------------------------------------------------------------------
    // 7. Comportement du socle
    // ------------------------------------------------------------------

    public function testUneCleAbsenteRendLeTexteSource(): void
    {
        I18n::use('en');
        $this->assertSame('Texte jamais traduit', I18n::t('Texte jamais traduit'));
        I18n::use('fr');
    }

    public function testUneLocaleInconnueRetombeSurLeFrancais(): void
    {
        I18n::use('de');
        $this->assertSame('fr', I18n::locale());
    }

    public function testLesSubstituantsSontRemplaces(): void
    {
        I18n::use('fr');
        $this->assertSame(
            'Bonjour Alex, 3 messages',
            I18n::t('Bonjour {nom}, {n} messages', ['nom' => 'Alex', 'n' => 3])
        );
    }

    /** Un substituant absent des variables reste littéral : jamais de trou. */
    public function testUnSubstituantNonFourniResteLitteral(): void
    {
        I18n::use('fr');
        $this->assertSame('Reste {inconnu}', I18n::t('Reste {inconnu}', ['autre' => 'x']));
    }

    /**
     * LE piège du pluriel : le français met 0 au singulier, l'anglais au
     * pluriel. Les « > 1 » codés en dur dans les gabarits sont donc faux
     * en anglais.
     */
    public function testLePluralDiffereEntreFrancaisEtAnglais(): void
    {
        I18n::use('fr');
        $this->assertSame('0 action', I18n::plural(0, '{n} action', '{n} actions'));
        $this->assertSame('1 action', I18n::plural(1, '{n} action', '{n} actions'));
        $this->assertSame('2 actions', I18n::plural(2, '{n} action', '{n} actions'));

        I18n::use('en');
        $this->assertSame('0 actions', I18n::plural(0, '{n} action', '{n} actions'));
        $this->assertSame('1 action', I18n::plural(1, '{n} action', '{n} actions'));
        I18n::use('fr');
    }

    public function testLaNegociationDuNavigateur(): void
    {
        $this->assertSame('en', I18n::fromAcceptLanguage('en-US,en;q=0.9'));
        $this->assertSame('fr', I18n::fromAcceptLanguage('fr-CA,fr;q=0.9,en;q=0.8'));
        $this->assertSame('en', I18n::fromAcceptLanguage('de,en;q=0.7,fr;q=0.3'));
        $this->assertNull(I18n::fromAcceptLanguage('de-DE,de;q=0.9'));
        $this->assertNull(I18n::fromAcceptLanguage(''));
        $this->assertNull(I18n::fromAcceptLanguage('fr;q=0'), 'q=0 signifie « surtout pas ».');
    }

    /** En français le dictionnaire client est un OBJET vide : window.I18N.s[k] planterait sur []. */
    public function testLeDictionnaireClientEstUnObjetEnFrancais(): void
    {
        I18n::use('fr');
        $client = I18n::clientDictionary();
        $this->assertSame('fr', $client['lang']);
        $this->assertSame('{"lang":"fr","s":{}}', json_encode($client));
    }

    /** tj() pose ses guillemets et neutralise l'apostrophe typographique. */
    public function testTjRendUnLitteralJavaScriptSur(): void
    {
        I18n::use('fr');
        $rendu = tj('Impossible d’enregistrer.');
        $this->assertStringStartsWith('"', $rendu);
        $this->assertStringEndsWith('"', $rendu);
        $this->assertStringNotContainsString('</', tj('</script>'));
    }

    /** te() échappe : une traduction peut légitimement contenir « & » ou « < ». */
    public function testTeEchappeLeHtml(): void
    {
        I18n::use('fr');
        $this->assertSame('Fichiers &amp; dossiers', te('Fichiers & dossiers'));
    }

    // ------------------------------------------------------------------
    // 8. Formatage
    // ------------------------------------------------------------------

    /**
     * LE bug que ce chantier corrige : `07/09/2026` se lit « 7 septembre » en
     * français et « 9 juillet » en anglais. Un écran anglais aux dates
     * françaises tromperait l'utilisateur sur des chiffres, en silence.
     */
    public function testLeFormatageSuitLaLocale(): void
    {
        I18n::use('fr');
        $this->assertSame('07/09/2026', Format::dateFr('2026-09-07'));
        $this->assertSame('juil. 2026', Format::monthLabel('2026-07'));
        $this->assertSame('12 840', Format::credits(12840));
        $this->assertSame('5,0 Mo', Format::sizeMo(5 * 1024 * 1024));

        I18n::use('en');
        $this->assertSame('09/07/2026', Format::dateFr('2026-09-07'));
        $this->assertSame('Jul 2026', Format::monthLabel('2026-07'));
        $this->assertSame('12,840', Format::credits(12840));
        $this->assertSame('5.0 MB', Format::sizeMo(5 * 1024 * 1024));

        I18n::use('fr');
    }

    /** Les deux tables de mois couvrent les douze mois. */
    public function testLesDeuxTablesDeMoisSontCompletes(): void
    {
        foreach (['fr', 'en'] as $locale) {
            I18n::use($locale);
            for ($m = 1; $m <= 12; $m++) {
                $this->assertNotSame(
                    '',
                    Format::monthLabel(sprintf('2026-%02d', $m)),
                    "Mois $m manquant en $locale."
                );
            }
        }
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /**
     * Clés passées à t()/te()/tj()/plural() dans une source.
     *
     * Les guillemets DOUBLES sont acceptés aussi : le corpus alterne selon que
     * la chaîne contient une apostrophe. N'extrait que les littéraux simples —
     * une clé construite par concaténation ne peut pas être vérifiée, et n'a
     * de toute façon rien à faire dans un dictionnaire.
     *
     * @return list<string>
     */
    private function clesAppelees(string $source): array
    {
        $cles = [];

        if (preg_match_all("/\\b(?:teb|te|tj|tn|t|plural)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $m)) {
            foreach ($m[1] as $brut) {
                $cles[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $brut);
            }
        }
        if (preg_match_all('/\b(?:teb|te|tj|tn|t|plural)\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $m)) {
            foreach ($m[1] as $brut) {
                $cles[] = stripcslashes($brut);
            }
        }
        // plural() prend DEUX formes : la seconde est un argument, pas la première.
        if (preg_match_all("/(?:tn|plural)\\([^,]+,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $paire) {
                $cles[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $paire[1]);
                $cles[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $paire[2]);
            }
        }

        // \uXXXX : le corpus JavaScript écrit parfois l'apostrophe typographique
        // ainsi. C'est le moteur JS qui la décode, donc la clé réellement passée
        // à t() est le caractère — pas la séquence d'échappement.
        $cles = array_map(
            static fn (string $c): string => (string) preg_replace_callback(
                '/\\\\u\{([0-9a-fA-F]+)\}|\\\\u([0-9a-fA-F]{4})/',
                static fn (array $m): string => mb_chr((int) hexdec(($m[1] ?? '') !== '' ? $m[1] : $m[2]), 'UTF-8'),
                $c
            ),
            $cles
        );

        return array_values(array_unique($cles));
    }

    /** @return array<string, array<string, string>> chemin => dictionnaire */
    private function dictionnaires(): array
    {
        $out = [];
        foreach (['lang/en.php', 'lang/en.client.php'] as $chemin) {
            $abs = dirname(__DIR__, 2) . '/' . $chemin;
            if (is_file($abs)) {
                $out[$chemin] = (array) require $abs;
            }
        }

        return $out;
    }

    /** @return list<string> chemins relatifs des gabarits */
    private function gabarits(): array
    {
        $racine = dirname(__DIR__, 2);
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/templates'));
        $out = [];
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = ltrim(str_replace($racine, '', $f->getPathname()), '/');
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> contenu de chaque bloc <script> … </script> */
    private function blocsScript(string $source): array
    {
        return preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $source, $m) ? $m[1] : [];
    }

    /**
     * Fichiers JavaScript du dépôt, hors bibliothèques tierces et minifiées.
     *
     * @return array<string, string> chemin => source
     */
    private function fichiersJs(): array
    {
        $racine = dirname(__DIR__, 2);
        $out = [];
        foreach (glob($racine . '/public/assets/js/*.js') ?: [] as $abs) {
            if (preg_match('/\.min\.js$/', $abs)) {
                continue;
            }
            $out[ltrim(str_replace($racine, '', $abs), '/')] = (string) file_get_contents($abs);
        }

        return $out;
    }

    /** @return array<string, string> chemin => source */
    private function fichiersPhpDeSrc(): array
    {
        $racine = dirname(__DIR__, 2);
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/src'));
        $out = [];
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $rel = ltrim(str_replace($racine, '', $f->getPathname()), '/');
                $out[$rel] = (string) file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    /** @return list<string> substituants {nom} présents, triés */
    private function substituants(string $texte): array
    {
        preg_match_all('/\{(\w+)\}/', $texte, $m);
        $out = array_values(array_unique($m[1]));
        sort($out);

        return $out;
    }

    /**
     * Littéraux français restants d'un gabarit déclaré traduit.
     * Heuristique volontairement étroite : nœuds texte accentués hors PHP et
     * hors balises. Le but est d'attraper une régression, pas de faire
     * l'inventaire — c'est le rôle de scripts/i18n-scan.php.
     *
     * @return list<string>
     */
    private function litterauxFrancais(string $source): array
    {
        // Retire le PHP, les scripts, les styles et les commentaires HTML.
        $net = preg_replace('/<\?(php|=).*?\?>/s', ' ', $source) ?? $source;
        $net = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $net) ?? $net;
        $net = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $net) ?? $net;
        $net = preg_replace('/<!--.*?-->/s', ' ', $net) ?? $net;

        $restes = [];
        if (preg_match_all('/>([^<>]*[à-öø-ÿÀ-ÖØ-Þ][^<>]*)</u', $net, $m)) {
            foreach ($m[1] as $texte) {
                $texte = trim($texte);
                if ($texte !== '' && preg_match('/\p{L}{2,}/u', $texte)) {
                    $restes[] = $texte;
                }
            }
        }

        return array_values(array_unique($restes));
    }
}
