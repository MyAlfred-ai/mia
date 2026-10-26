<?php

declare(strict_types=1);

/**
 * État de santé de l'installation — SOURCE UNIQUE des contrôles.
 *
 * Une seule liste alimente TROIS surfaces :
 *   - `GET /healthz`            sonde publique et HEALTHCHECK Docker (liveness)
 *   - `php scripts/doctor.php`  diagnostic en ligne de commande
 *   - `/admin/diagnostics`      même chose dans l'interface
 *
 * Rien n'est réimplémenté : chaque contrôle appelle la sonde qui existait déjà
 * (`QdrantStore::healthCheck`, `JobQueue::workerAlive`, `LLMFactory::available`,
 * `EmbeddingService::availableProviders`, `StorageInfo::snapshot`…). Ce qui
 * manquait n'était pas les sondes, c'était un endroit où les lire ensemble.
 *
 * Chaque contrôle porte un REMÈDE. Un diagnostic qui dit « Qdrant KO » sans
 * dire quoi faire ne fait que déplacer le problème.
 *
 * Libellés, valeurs, messages et remèdes sont TRADUITS (clé = phrase
 * française) : la page s'affiche dans la langue de l'administrateur.
 * `doctor.php` (CLI, aucune locale posée) et `/healthz` (identifiants et
 * statuts seulement) restent en français. Ce qui sert à décider (`id`,
 * `statut`, le « ≠ » de la valeur du worker) ne dépend pas de la langue.
 */
final class Diagnostics
{
    // Repris de Requirements, chargé avant : un seul jeu de statuts.
    public const OK   = Requirements::OK;
    public const WARN = Requirements::WARN;
    public const FAIL = Requirements::FAIL;
    /** Purement informatif : ne pèse jamais sur le verdict global. */
    public const INFO = 'info';

    private const RANG = [self::INFO => 0, self::OK => 1, self::WARN => 2, self::FAIL => 3];

    /** Hôte sondé pour vérifier la sortie Internet — même cible que scripts/worker.php. */
    private const HOTE_SONDE = 'api.openai.com';

    /**
     * Diagnostic complet.
     *
     * @param bool $deep `false` = contrôles locaux uniquement (aucun appel
     *                   réseau, aucun calcul d'empreinte disque). Utilisé par
     *                   les sondes appelées toutes les 30 secondes.
     * @return list<array<string,mixed>>
     */
    public static function run(bool $deep = true): array
    {
        $controles = array_merge(
            Requirements::all(),
            self::configuration(),
            self::base()
        );

        if ($deep) {
            $controles = array_merge($controles, self::services());
        }

        return $controles;
    }

    /**
     * Sous-ensemble « l'application est-elle vivante ? » : rapide, local, sans
     * secret. C'est ce que renvoie /healthz, appelé par le HEALTHCHECK Docker.
     *
     * @return list<array<string,mixed>>
     */
    public static function liveness(): array
    {
        return array_merge(
            [Requirements::php(), Requirements::writablePaths()],
            self::base()
        );
    }

    /** Pire statut de la liste. INFO n'entre jamais dans le verdict. */
    public static function worst(array $controles): string
    {
        $pire = self::OK;
        foreach ($controles as $c) {
            $statut = (string) ($c['statut'] ?? self::OK);
            if ((self::RANG[$statut] ?? 0) > (self::RANG[$pire] ?? 0)) {
                $pire = $statut;
            }
        }

        return $pire;
    }

    /** Regroupe par catégorie, dans l'ordre d'apparition. */
    public static function parCategorie(array $controles): array
    {
        $groupes = [];
        foreach ($controles as $c) {
            $groupes[(string) ($c['categorie'] ?? 'divers')][] = $c;
        }

        return $groupes;
    }

    public static function libelleCategorie(string $cle): string
    {
        return match ($cle) {
            'socle'         => t('Socle technique'),
            'configuration' => t('Configuration'),
            'base'          => t('Base de données'),
            'services'      => t('Services et fournisseurs'),
            default         => t('Divers'),
        };
    }

    /**
     * Actions correctives d'un contrôle NON vert : liens internes de l'app et
     * commandes à copier. La page Diagnostic les rend en boutons — un remède
     * qu'il faut retaper à la main est un remède qu'on n'applique pas.
     *
     * Les commandes restent au `docker compose` générique : le remède textuel
     * du contrôle donne déjà le contexte, et la prod ajoute son `-f` habituel.
     *
     * @param  array<string,mixed> $c
     * @return list<array{type:'link'|'cmd', label:string, value:string}>
     */
    public static function actions(array $c): array
    {
        $statut = (string) ($c['statut'] ?? self::OK);
        if ($statut === self::OK || $statut === self::INFO) {
            return [];
        }
        $valeur = (string) ($c['valeur'] ?? '');

        return match ((string) ($c['id'] ?? '')) {
            'secrets'             => [['type' => 'cmd', 'label' => t('Générer un secret'), 'value' => 'openssl rand -hex 32']],
            'local_password_hash' => [['type' => 'link', 'label' => t('Créer un compte admin'), 'value' => '/admin/users']],
            'database'            => [['type' => 'cmd', 'label' => t('Vérifier les services'), 'value' => 'docker compose ps']],
            'migrations'          => [['type' => 'cmd', 'label' => t('Rejouer les migrations'), 'value' => 'docker compose restart app']],
            // Le worker a DEUX pannes distinctes : image divergente (rebuild
            // obligatoire) vs absent/silencieux (up suffit).
            'worker' => str_contains($valeur, '≠')
                ? [['type' => 'cmd', 'label' => t('Aligner le worker'), 'value' => 'docker compose build && docker compose up -d worker']]
                : [
                    ['type' => 'cmd', 'label' => t('Démarrer le worker'), 'value' => 'docker compose up -d worker'],
                    ['type' => 'cmd', 'label' => t('Voir ses logs'), 'value' => 'docker compose logs --tail=100 worker'],
                ],
            'qdrant' => [
                ['type' => 'link', 'label' => t('Ouvrir le moteur vectoriel'), 'value' => '/admin/knowledge-bases/vector'],
                ['type' => 'cmd', 'label' => t('Redémarrer Qdrant'), 'value' => 'docker compose up -d qdrant'],
            ],
            'llm'        => [['type' => 'link', 'label' => t('Ouvrir Modèles IA'), 'value' => '/admin/llms']],
            'embeddings' => [
                ['type' => 'link', 'label' => t('Enregistrer une clé'), 'value' => '/admin/llms'],
                ['type' => 'link', 'label' => t('Réindexer les bases'), 'value' => '/admin/knowledge-bases'],
            ],
            'connecteurs'    => [['type' => 'link', 'label' => t('Ouvrir les connecteurs'), 'value' => '/admin/connectors']],
            'outils_arretes' => [['type' => 'link', 'label' => t('Ouvrir les outils'), 'value' => '/admin/tools']],
            'budget_ia'      => [['type' => 'link', 'label' => t('Ouvrir le budget'), 'value' => '/admin/budget']],
            'bases_mybrain'  => [['type' => 'link', 'label' => t('Ouvrir les bases de connaissances'), 'value' => '/admin/knowledge-bases']],
            'assistant_aide' => [['type' => 'cmd', 'label' => t('Consulter les logs'), 'value' => 'docker compose logs --tail=100 app']],
            default          => [],
        };
    }

    // ---------------------------------------------------------------- Configuration

    /** @return list<array<string,mixed>> */
    private static function configuration(): array
    {
        $controles = [];

        // Longueur des secrets. Volontairement WARN et non FAIL : un secret
        // court mais stable fonctionne, et transformer une instance en service
        // d'erreur au premier déploiement serait pire que le défaut signalé.
        // L'absence pure, elle, est bloquée en amont par Bootstrap.
        $courts = [];
        foreach (['APP_SECRET' => APP_SECRET, 'ENCRYPTION_KEY' => ENCRYPTION_KEY] as $nom => $valeur) {
            if (strlen((string) $valeur) < 32) {
                $courts[] = $nom;
            }
        }
        $controles[] = self::controle(
            'secrets',
            'configuration',
            t('Longueur des secrets'),
            $courts === [] ? self::OK : self::WARN,
            $courts === [] ? t('≥ 32 caractères') : implode(', ', $courts),
            $courts === [] ? '' : t('Secrets plus courts que 32 caractères : {noms}.', ['noms' => implode(', ', $courts)]),
            $courts === [] ? '' : t('Régénérez-les avec openssl rand -hex 32. Attention : changer ENCRYPTION_KEY rend illisibles les clés API déjà enregistrées.')
        );

        // Hash bcrypt d'urgence tronqué par l'interpolation « $ » de compose :
        // le symptôme visible est « mot de passe invalide », sans autre indice.
        $hash = (string) LOCAL_PASSWORD_HASH;
        if ($hash === '') {
            $controles[] = self::controle(
                'local_password_hash',
                'configuration',
                t('Accès de secours (LOCAL_PASSWORD_HASH)'),
                self::INFO,
                t('non configuré'),
                t('Aucun mot de passe de secours. Les comptes administrateurs en base restent le seul accès.'),
                ''
            );
        } elseif (preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) === 1) {
            $controles[] = self::controle(
                'local_password_hash',
                'configuration',
                t('Accès de secours (LOCAL_PASSWORD_HASH)'),
                self::OK,
                t('hash bcrypt valide'),
                '',
                ''
            );
        } else {
            $controles[] = self::controle(
                'local_password_hash',
                'configuration',
                t('Accès de secours (LOCAL_PASSWORD_HASH)'),
                self::WARN,
                t('hash invalide'),
                t('Le hash n\'a pas la forme d\'un bcrypt. La connexion de secours répondra « mot de passe invalide » sans autre explication. Cause habituelle : docker compose interprète les « $ » du hash dans le fichier .env.'),
                t('Dans .env, doublez chaque « $ » du hash ($2y$12$… devient $$2y$$12$$…). Ou mieux : créez un compte administrateur en base (php scripts/admin-create.php) et laissez LOCAL_PASSWORD_HASH vide.')
            );
        }

        // Cohérence APP_URL / schéma réellement servi. Les liens OAuth, les
        // redirections et le manifeste PWA sont construits sur APP_URL : une
        // incohérence casse la connexion Google sans message clair.
        $controles[] = self::appUrl();

        return $controles;
    }

    /** @return array<string,mixed> */
    private static function appUrl(): array
    {
        $schemaAttendu = parse_url(APP_URL, PHP_URL_SCHEME);

        if (PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            return self::controle(
                'app_url',
                'configuration',
                t('Adresse publique (APP_URL)'),
                APP_URL === '' ? self::WARN : self::INFO,
                APP_URL !== '' ? APP_URL : t('non définie'),
                APP_URL === '' ? t('Sans APP_URL, les liens de connexion Google et le manifeste PWA sont faux.') : '',
                APP_URL === '' ? t('Renseignez APP_URL dans .env (adresse publique complète, https comprise).') : ''
            );
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $schemaReel = $https ? 'https' : 'http';

        if ($schemaAttendu === $schemaReel) {
            return self::controle('app_url', 'configuration', t('Adresse publique (APP_URL)'), self::OK, APP_URL, '', '');
        }

        return self::controle(
            'app_url',
            'configuration',
            t('Adresse publique (APP_URL)'),
            self::WARN,
            t('{url} (servie en {schema})', ['url' => APP_URL, 'schema' => $schemaReel]),
            t('APP_URL annonce « {attendu} » alors que la requête arrive en « {reel} ». Les redirections OAuth et les liens absolus seront faux.', ['attendu' => (string) $schemaAttendu, 'reel' => $schemaReel]),
            t('Alignez APP_URL sur l\'adresse réellement servie. Derrière un proxy qui termine le TLS, vérifiez qu\'il transmet bien l\'en-tête X-Forwarded-Proto.')
        );
    }

    // ---------------------------------------------------------------- Base

    /** @return list<array<string,mixed>> */
    private static function base(): array
    {
        $panne = Bootstrap::databaseReachable();

        if ($panne !== null) {
            return [
                self::controle(
                    'database',
                    'base',
                    t('Connexion à la base'),
                    self::FAIL,
                    DB_HOST . ':' . DB_PORT,
                    $panne,
                    t('Vérifiez que le service est démarré (docker compose ps) puis DB_HOST, DB_PORT, DB_NAME, DB_USER et DB_PASS.')
                ),
                // Inutile de sonder les migrations sans connexion : le message
                // serait un doublon de la panne ci-dessus.
                self::controle(
                    'migrations',
                    'base',
                    t('Migrations de schéma'),
                    self::FAIL,
                    t('indéterminé'),
                    t('Impossible de vérifier le schéma : la base est injoignable.'),
                    ''
                ),
            ];
        }

        $controles = [self::controle(
            'database',
            'base',
            t('Connexion à la base'),
            self::OK,
            DB_HOST . ':' . DB_PORT . '/' . DB_NAME,
            '',
            ''
        )];

        try {
            $enAttente = Database::pendingMigrations();
            $total     = count(glob(ROOT . '/database/migrations/*.sql') ?: []);

            $controles[] = $enAttente === []
                ? self::controle('migrations', 'base', t('Migrations de schéma'), self::OK, t('{n} appliquées', ['n' => $total]), '', '')
                : self::controle(
                    'migrations',
                    'base',
                    t('Migrations de schéma'),
                    self::FAIL,
                    t('{n} en attente', ['n' => count($enAttente)]),
                    t('Le schéma est en retard. Non appliquées : {liste}.', ['liste' => implode(', ', $enAttente)]),
                    t('Redémarrez le conteneur applicatif (l\'entrypoint les applique), ou consultez storage/logs/errors.log : une migration a peut-être échoué.')
                );
        } catch (\Throwable $e) {
            $controles[] = self::controle(
                'migrations',
                'base',
                t('Migrations de schéma'),
                self::FAIL,
                t('en échec'),
                $e->getMessage(),
                t('Corrigez la base, puis relancez. Le détail est dans storage/logs/errors.log.')
            );
        }

        return $controles;
    }

    // ---------------------------------------------------------------- Services

    /** @return list<array<string,mixed>> */
    private static function services(): array
    {
        return [
            self::worker(),
            self::qdrant(),
            self::modeles(),
            self::embeddings(),
            self::sortieInternet(),
            self::connecteurs(),
            self::connecteursMicrosoft(),
            self::connecteurSlack(),
            self::outilsArretes(),
            self::budgetIa(),
            self::sourcesDistantes(),
            self::basesMyBrain(),
            self::assistantAide(),
            self::stockage(),
        ];
    }

    /** @return array<string,mixed> */
    private static function worker(): array
    {
        // `JobQueue::workerAlive()` répond false pour DEUX raisons très
        // différentes (absent / version divergente) : on relit le battement
        // pour dire laquelle, sinon le remède est indevinable.
        $brut = (string) Settings::get('job_worker_heartbeat_at', '');

        if ($brut === '') {
            return self::controle(
                'worker',
                'services',
                t('Worker de générations'),
                self::WARN,
                t('jamais vu'),
                t('Aucun worker n\'a signalé sa présence. Les générations longues (pages HTML, images, vidéos, présentations) s\'exécuteront en mode dégradé, dans la requête web.'),
                t('Démarrez-le : docker compose up -d worker')
            );
        }

        [$ts, $version] = array_pad(explode('|', $brut, 2), 2, '');
        $age            = time() - (int) $ts;
        $versionApp     = defined('APP_VERSION') ? APP_VERSION : '?';

        if ($age >= 45) {
            return self::controle(
                'worker',
                'services',
                t('Worker de générations'),
                self::WARN,
                t('silencieux depuis {n} s', ['n' => $age]),
                t('Le worker ne bat plus. Il est arrêté ou bloqué ; les jobs orphelins seront repris après deux minutes.'),
                t('docker compose logs worker puis docker compose up -d worker')
            );
        }

        if ($version !== $versionApp) {
            return self::controle(
                'worker',
                'services',
                t('Worker de générations'),
                self::FAIL,
                // « ≠ » garde son sens dans les deux langues : actions() le lit.
                t('version {version} ≠ {app}', ['version' => $version ?: t('inconnue'), 'app' => $versionApp]),
                t('Le worker tourne sur une image plus ancienne que l\'application : il ignore les types de jobs ajoutés depuis, et les fait TOUS échouer en silence.'),
                t('Reconstruisez et recréez : docker compose build && docker compose up -d worker')
            );
        }

        return self::controle('worker', 'services', t('Worker de générations'), self::OK, t('actif, version {version}', ['version' => $version]), '', '');
    }

    /** @return array<string,mixed> */
    private static function qdrant(): array
    {
        // WARN et non FAIL : l'application ne dépend JAMAIS durement de Qdrant,
        // elle retombe sur la recherche lexicale FULLTEXT de MySQL.
        $ok = (new QdrantStore())->healthCheck();

        return self::controle(
            'qdrant',
            'services',
            t('Base vectorielle (Qdrant)'),
            $ok ? self::OK : self::WARN,
            QDRANT_URL,
            $ok ? '' : t('Injoignable. Les bases de connaissances répondent en repli lexical : les réponses restent possibles mais moins pertinentes.'),
            $ok ? '' : t('docker compose up -d qdrant, puis vérifiez QDRANT_URL.')
        );
    }

    /** @return array<string,mixed> */
    private static function modeles(): array
    {
        $modeles = LLMFactory::available();

        return self::controle(
            'llm',
            'services',
            t('Modèles de langage disponibles'),
            $modeles === [] ? self::FAIL : self::OK,
            t('{n} modèle(s)', ['n' => count($modeles)]),
            $modeles === [] ? t('Aucun modèle utilisable : aucune clé API n\'est enregistrée. Le chat ne peut rien répondre.') : '',
            $modeles === [] ? t('Administration → Modèles IA → onglet Clés API.') : ''
        );
    }

    /** @return array<string,mixed> */
    private static function embeddings(): array
    {
        $fournisseurs = EmbeddingService::availableProviders();

        return self::controle(
            'embeddings',
            'services',
            t('Vectorisation (embeddings)'),
            $fournisseurs === [] ? self::WARN : self::OK,
            $fournisseurs === [] ? t('aucun') : implode(', ', array_keys($fournisseurs)),
            $fournisseurs === [] ? t('Aucune clé permettant de vectoriser. Les bases de connaissances resteront interrogeables en repli lexical, sans recherche sémantique.') : '',
            $fournisseurs === [] ? t('Enregistrez une clé OpenAI, Mistral ou Gemini, puis « Réindexer » les bases concernées.') : ''
        );
    }

    /** @return array<string,mixed> */
    private static function sortieInternet(): array
    {
        // Même sonde que scripts/worker.php : un réseau Docker `internal: true`
        // sans second réseau coupe TOUTE sortie, et le symptôme (« impossible
        // de contacter … » sur chaque job) ne désigne pas sa cause.
        $ok = gethostbyname(self::HOTE_SONDE) !== self::HOTE_SONDE;

        return self::controle(
            'sortie_internet',
            'services',
            t('Accès Internet sortant'),
            $ok ? self::OK : self::FAIL,
            $ok ? t('résolution DNS OK') : t('aucune résolution DNS'),
            $ok ? '' : t('Ce conteneur ne résout pas {hote} : tous les appels aux fournisseurs échoueront.', ['hote' => self::HOTE_SONDE]),
            $ok ? '' : t('Vérifiez ses réseaux Docker. Un réseau « internal: true » seul bloque toute sortie : app ET worker doivent aussi être sur le réseau externe.')
        );
    }

    /** @return array<string,mixed> */
    private static function connecteurs(): array
    {
        $actifs = [
            'Drive'       => UserDriveService::isGloballyEnabled(),
            'Gmail'       => UserGmailService::isGloballyEnabled(),
            t('Agenda')   => UserCalendarService::isGloballyEnabled(),
            'Tasks'       => UserTasksService::isGloballyEnabled(),
        ];
        $autorises = array_keys(array_filter($actifs));
        $oauth     = GoogleDriveOAuth::isConfigured();

        if ($autorises === []) {
            return self::controle(
                'connecteurs',
                'services',
                t('Connecteurs Google personnels'),
                self::INFO,
                t('désactivés'),
                '',
                ''
            );
        }

        if (!$oauth) {
            return self::controle(
                'connecteurs',
                'services',
                t('Connecteurs Google personnels'),
                self::WARN,
                t('{connecteurs} — OAuth absent', ['connecteurs' => implode(', ', $autorises)]),
                t('Ces connecteurs sont autorisés en administration mais les identifiants OAuth Google manquent : ils ne seront jamais proposés au modèle, sans erreur visible côté utilisateur.'),
                t('Renseignez l\'ID client et le secret client dans Organisation → Authentification. Stockés en base, ils sont vus aussi bien par l\'app que par le worker.')
            );
        }

        return self::controle(
            'connecteurs',
            'services',
            t('Connecteurs Google personnels'),
            self::OK,
            implode(', ', $autorises),
            '',
            ''
        );
    }

    /**
     * Même contrôle pour la famille Microsoft, et pour la même raison : un
     * connecteur autorisé sans identifiants d'application n'est JAMAIS exposé
     * au modèle, sans une erreur visible côté utilisateur.
     *
     * @return array<string,mixed>
     */
    private static function connecteursMicrosoft(): array
    {
        $actifs = [
            'Outlook'               => UserOutlookService::isGloballyEnabled(),
            t('Calendrier Outlook') => UserOutlookCalendarService::isGloballyEnabled(),
            'Microsoft To Do'     => UserMicrosoftTodoService::isGloballyEnabled(),
            'Microsoft Teams'     => UserTeamsService::isGloballyEnabled(),
        ];
        $autorises = array_keys(array_filter($actifs));

        if ($autorises === []) {
            return self::controle(
                'connecteurs_microsoft',
                'services',
                t('Connecteurs Microsoft personnels'),
                self::INFO,
                t('désactivés'),
                '',
                ''
            );
        }

        if (!MicrosoftOutlookOAuth::isConfigured()) {
            return self::controle(
                'connecteurs_microsoft',
                'services',
                t('Connecteurs Microsoft personnels'),
                self::WARN,
                t('{connecteurs} — application Azure absente', ['connecteurs' => implode(', ', $autorises)]),
                t('Ces connecteurs sont autorisés en administration mais les identifiants de l\'application Microsoft manquent : ils ne seront jamais proposés au modèle, sans erreur visible côté utilisateur.'),
                t('Renseignez l\'ID d\'application et le secret client dans Organisation → Authentification. La connexion Microsoft n\'a pas besoin d\'être ACTIVÉE pour autant : seuls les identifiants comptent.')
            );
        }

        return self::controle(
            'connecteurs_microsoft',
            'services',
            t('Connecteurs Microsoft personnels'),
            self::OK,
            implode(', ', $autorises),
            '',
            ''
        );
    }

    /**
     * Connecteur Slack : autorisé en administration mais application Slack non
     * renseignée = panne MUETTE (l'outil n'est jamais exposé au modèle, la
     * carte de la page Connexions n'apparaît pas, et rien ne le dit).
     *
     * @return array<string,mixed>
     */
    private static function connecteurSlack(): array
    {
        if (!UserSlackService::isGloballyEnabled()) {
            return self::controle(
                'connecteur_slack',
                'services',
                t('Connecteur Slack'),
                self::INFO,
                t('désactivé'),
                '',
                ''
            );
        }

        if (!SlackMessagesOAuth::isConfigured()) {
            return self::controle(
                'connecteur_slack',
                'services',
                t('Connecteur Slack'),
                self::WARN,
                t('application Slack absente'),
                t('Le connecteur est autorisé en administration mais les identifiants de l\'application Slack manquent : il ne sera jamais proposé au modèle, sans erreur visible côté utilisateur.'),
                t('Renseignez le Client ID et le Client Secret dans Connecteurs → Configurer l\'application Slack, puis utilisez le bouton Tester.')
            );
        }

        return self::controle(
            'connecteur_slack',
            'services',
            t('Connecteur Slack'),
            self::OK,
            'Slack',
            '',
            ''
        );
    }

    /**
     * Outils ACTIFS dont le modèle est arrêté, ou va l'être, par son
     * fournisseur (`retirement` dans config/tools_catalog.php). Sans ce
     * contrôle, la seule alerte est la carte de l'outil — qu'on n'ouvre que
     * lorsqu'un utilisateur se plaint.
     *
     * @return array<string,mixed>
     */
    private static function outilsArretes(): array
    {
        try {
            return self::controleOutilsArretes(ChatTool::all());
        } catch (\Throwable) {
            return self::controle('outils_arretes', 'services', t('Outils sur un modèle arrêté'), self::INFO, t('indisponible'), '', '');
        }
    }

    /**
     * Le contrôle lui-même, PUR (lignes `chat_tools` en entrée) : testable
     * sans base. Un outil inactif n'est proposé à personne, il ne compte pas.
     * Toujours un avertissement, jamais un échec : l'instance fonctionne, un
     * outil est dégradé — et `scripts/doctor.php` ne sort en erreur que sur
     * un échec.
     *
     * @param  list<array<string,mixed>> $outils
     * @return array<string,mixed>
     */
    public static function controleOutilsArretes(array $outils): array
    {
        $arretes  = [];
        $annonces = [];
        foreach ($outils as $outil) {
            if ((int)($outil['is_active'] ?? 0) !== 1) {
                continue;
            }
            $arret = ChatTool::retirement($outil);
            if ($arret === null) {
                continue;
            }
            $libelle = t('« {nom} » ({date})', ['nom' => (string)($outil['name'] ?? ''), 'date' => Format::dateFr($arret['date'])]);
            if ($arret['retired']) {
                $arretes[] = $libelle;
            } else {
                $annonces[] = $libelle;
            }
        }

        if ($arretes === [] && $annonces === []) {
            return self::controle('outils_arretes', 'services', t('Outils sur un modèle arrêté'), self::OK, t('aucun'), '', '');
        }

        $valeur  = [];
        $message = [];
        if ($arretes !== []) {
            $valeur[]  = t('{n} hors service', ['n' => count($arretes)]);
            $message[] = t('Modèle arrêté par son fournisseur, grisé dans le chat : {outils}.', ['outils' => implode(', ', $arretes)]);
        }
        if ($annonces !== []) {
            $valeur[]  = t('{n} arrêt(s) annoncé(s)', ['n' => count($annonces)]);
            $message[] = t('Arrêt annoncé par le fournisseur, l\'outil sera grisé à cette date : {outils}.', ['outils' => implode(', ', $annonces)]);
        }

        return self::controle(
            'outils_arretes',
            'services',
            t('Outils sur un modèle arrêté'),
            self::WARN,
            implode(', ', $valeur),
            implode(' ', $message),
            t('Administration → Capacités → Outils : choisissez un autre modèle dans « Modifier » si le fournisseur en propose un, sinon désactivez l\'outil. La carte de l\'outil renvoie vers l\'annonce du fournisseur.')
        );
    }

    /** @return array<string,mixed> */
    private static function budgetIa(): array
    {
        try {
            return self::controleBudgetIa(AiBudget::status(true));
        } catch (\Throwable) {
            return self::controle('budget_ia', 'services', t('Budget IA mensuel'), self::INFO, t('indisponible'), '', '');
        }
    }

    /**
     * Le contrôle lui-même, PUR (état AiBudget::status en entrée). Toujours un
     * avertissement, jamais un échec : un budget atteint est un choix de
     * l'organisation, pas une panne — et `scripts/doctor.php` ne sort en
     * erreur que sur un échec.
     *
     * @param  array<string,mixed> $s
     * @return array<string,mixed>
     */
    public static function controleBudgetIa(array $s): array
    {
        $libelle   = t('Budget IA mensuel');
        $sansTarif = (int)($s['unpriced_ops'] ?? 0) > 0
            ? ' ' . t('{n} opération(s) sans tarif ce mois-ci, comptée(s) 0 € : le budget les sous-estime.', ['n' => (int)$s['unpriced_ops']])
            : '';
        if (empty($s['enabled'])) {
            return self::controle('budget_ia', 'services', $libelle, self::INFO, t('aucun budget'), trim($sansTarif), '');
        }
        $valeur = t('{pct} % de {limite}', ['pct' => (int)$s['pct'], 'limite' => Format::eur((float)$s['limit_eur'])]);
        $remede = t('Coûts → Budget : accordez une rallonge pour ce mois, relevez le budget, ou ajustez les quotas.');

        if (($s['state'] ?? '') === AiBudget::STATE_REACHED) {
            $message = !empty($s['blocks'])
                ? t('Budget du mois atteint : les nouvelles demandes sont suspendues pour tous jusqu\'au 1er, et la synchronisation automatique des bases est en pause.')
                : t('Budget du mois dépassé (mode signalement : rien n\'est bloqué).');
            return self::controle('budget_ia', 'services', $libelle, self::WARN, $valeur, $message . $sansTarif, $remede);
        }
        if (($s['state'] ?? '') === AiBudget::STATE_ALERT) {
            return self::controle('budget_ia', 'services', $libelle, self::WARN, $valeur,
                t('Seuil d\'alerte franchi ({pct} %).', ['pct' => (int)$s['alert_pct']]) . $sansTarif, $remede);
        }
        if (!empty($s['projection']['over'])) {
            return self::controle('budget_ia', 'services', $libelle, self::WARN, $valeur,
                t('Au rythme actuel, le budget sera atteint avant la fin du mois (≈ {montant}).', ['montant' => Format::eur((float)$s['projection']['projected_eur'])]) . $sansTarif, $remede);
        }
        if ($sansTarif !== '') {
            return self::controle('budget_ia', 'services', $libelle, self::WARN, $valeur, trim($sansTarif),
                t('Coûts → Tarifs & crédits : renseignez le tarif des services signalés dans Consommation.'));
        }
        return self::controle('budget_ia', 'services', $libelle, self::OK, $valeur, '', '');
    }

    /** @return array<string,mixed> */
    private static function sourcesDistantes(): array
    {
        $configures = array_keys(array_filter(KbSync::configuredProviders()));

        return self::controle(
            'sources_distantes',
            'services',
            t('Sources distantes des bases de connaissances'),
            self::INFO,
            $configures === [] ? t('aucune') : implode(', ', $configures),
            '',
            ''
        );
    }

    /**
     * Bases EN DIRECT (MyBrain) actives : chaque cerveau est interrogé par son
     * point « qui suis-je » (GET /api/v1, lecture seule — il ne compte que
     * l'usage de la clé). Sans ce contrôle, une clé supprimée dans MyBrain ne
     * se verrait qu'au prochain message d'un utilisateur, sous la forme d'une
     * base grisée qu'il ne sait pas expliquer.
     *
     * @return array<string,mixed>
     */
    private static function basesMyBrain(): array
    {
        try {
            $bases = array_values(array_filter(
                KnowledgeBase::all(),
                static fn(array $kb): bool => KnowledgeBase::isRemote($kb) && (int)($kb['is_active'] ?? 0) === 1
            ));
        } catch (\Throwable) {
            return self::controle('bases_mybrain', 'services', t('Cerveaux MyBrain'), self::INFO, t('indisponible'), '', '');
        }
        $resultats = [];
        foreach ($bases as $kb) {
            $erreur = null;
            try {
                $row    = KnowledgeBaseRemote::find((int)$kb['id']);
                $client = $row !== null ? KnowledgeBaseRemote::client($row) : null;
                if ($client === null) {
                    $erreur = t('connexion non configurée');
                } else {
                    $moi = $client->whoami();
                    if (!in_array(MyBrainClient::RIGHT_READ, $moi['droits'], true)) {
                        $erreur = t('la clé n\'a plus le droit « lire »');
                    }
                }
            } catch (MyBrainException $e) {
                $erreur = $e->getMessage();
            } catch (\Throwable $e) {
                $erreur = t('vérification impossible');
            }
            $resultats[] = ['nom' => (string)$kb['name'], 'erreur' => $erreur];
        }
        return self::controleBasesMyBrain($resultats);
    }

    /**
     * Le contrôle lui-même, PUR : testable sans réseau. Toujours un
     * avertissement, jamais un échec — l'instance fonctionne, un cerveau est
     * injoignable et les réponses partent sans lui en le disant.
     *
     * @param list<array{nom: string, erreur: ?string}> $resultats
     * @return array<string,mixed>
     */
    public static function controleBasesMyBrain(array $resultats): array
    {
        if ($resultats === []) {
            return self::controle('bases_mybrain', 'services', t('Cerveaux MyBrain'), self::INFO, t('aucun'), '', '');
        }
        $enPanne = array_values(array_filter($resultats, static fn(array $r): bool => $r['erreur'] !== null));
        if ($enPanne === []) {
            return self::controle(
                'bases_mybrain',
                'services',
                t('Cerveaux MyBrain'),
                self::OK,
                I18n::plural(count($resultats), '{n} joignable', '{n} joignables'),
                '',
                ''
            );
        }
        $details = array_map(static fn(array $r): string => t('« {nom} » : {erreur}', ['nom' => $r['nom'], 'erreur' => (string)$r['erreur']]), $enPanne);
        return self::controle(
            'bases_mybrain',
            'services',
            t('Cerveaux MyBrain'),
            self::WARN,
            t('{n} / {total} en panne', ['n' => count($enPanne), 'total' => count($resultats)]),
            t('{details}. Les messages qui engagent ces bases partent sans le cerveau, et le disent.', ['details' => implode(' — ', $details)]),
            t('Bases de connaissances → « Modifier » sur la base → « Tester et enregistrer ». Clé refusée : créez-en une nouvelle dans MyBrain (Administration → Clés d\'API), droit « lire ».')
        );
    }

    /** @return array<string,mixed> */
    private static function assistantAide(): array
    {
        $id = (string) Settings::get('mia_help_assistant_id', '');

        return self::controle(
            'assistant_aide',
            'services',
            t('Assistant « MIA : mode d\'emploi »'),
            $id === '' ? self::WARN : self::INFO,
            $id === '' ? t('non semé') : t('semé'),
            $id === '' ? t('L\'assistant d\'aide intégré n\'a pas encore été créé. Il l\'est au premier démarrage complet ; son absence signale un semis interrompu.') : '',
            $id === '' ? t('Rechargez une page de l\'application, puis consultez storage/logs/errors.log.') : ''
        );
    }

    /** @return array<string,mixed> */
    private static function stockage(): array
    {
        try {
            $snapshot = StorageInfo::snapshot();
            $total    = $snapshot['total'] ?? null;

            return self::controle(
                'stockage',
                'services',
                t('Empreinte disque'),
                self::INFO,
                is_numeric($total) ? self::octets((int) $total) : t('indisponible'),
                '',
                ''
            );
        } catch (\Throwable) {
            return self::controle('stockage', 'services', t('Empreinte disque'), self::INFO, t('indisponible'), '', '');
        }
    }

    // ---------------------------------------------------------------- Outils

    public static function octets(int $n): string
    {
        $unites = I18n::locale() === 'en' ? ['B', 'KB', 'MB', 'GB', 'TB'] : ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        while ($n >= 1024 && $i < count($unites) - 1) {
            $n = intdiv($n, 1024);
            $i++;
        }

        return $n . ' ' . $unites[$i];
    }

    /** @return array<string,mixed> */
    private static function controle(
        string $id,
        string $categorie,
        string $libelle,
        string $statut,
        string $valeur,
        string $message,
        string $remede
    ): array {
        return [
            'id'        => $id,
            'categorie' => $categorie,
            'libelle'   => $libelle,
            'statut'    => $statut,
            'valeur'    => $valeur,
            'message'   => $message,
            'remede'    => $remede,
        ];
    }
}
