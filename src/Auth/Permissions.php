<?php

declare(strict_types=1);

/**
 * Droits des rôles — SOURCE UNIQUE du catalogue (pure : aucune base, aucune
 * session).
 *
 * Un droit = « fonctionnalité.action » (`assistants.edit`, `users.view`…).
 * Six actions, les colonnes de l'écran Personnes → Rôles : Voir (voir
 * apparaître la fonctionnalité : son entrée dans le menu, sa page en lecture),
 * Utiliser (s'en servir sans rien régler : conversation, tâches, workflows,
 * tests), Créer, Modifier, Supprimer et Gérer (ceux de toute l'organisation,
 * pas seulement les siens : les partager, en changer la portée — assistants et
 * bases de connaissances) — cf. ACTION_DEFINITIONS. Chaque fonctionnalité ne
 * déclare que celles qui ont un sens pour elle, avec la phrase qui dit
 * EXACTEMENT ce que la case ouvre — l'écran l'affiche en infobulle, le code
 * l'applique ; une case sans objet dit pourquoi (NA_REASONS).
 *
 * « Gérer » remplace l'ancienne portée « toute l'organisation » (`.all`) : une
 * carte de droits enregistrée avant lui est relue par decode() (LEGACY_KEYS),
 * sans qu'un rôle gagne un droit en silence.
 *
 * Trois rôles livrés, qu'on ne supprime pas :
 *   - `admin`   : TOUS les droits, verrouillé — il doit toujours rester
 *                 quelqu'un capable de tout régler, rôles compris ;
 *   - `creator` : DEFAULTS['creator'], modifiable ;
 *   - `user`    : DEFAULTS['user'], modifiable.
 * Les défauts reproduisent EXACTEMENT ce que faisaient les trois rôles avant
 * l'écran des rôles : passer à la version 1.5 ne change les droits de personne.
 *
 * Deux domaines restent réservés aux administrateurs, quel que soit le rôle
 * (ADMIN_ONLY) : les rôles eux-mêmes (sinon un rôle pourrait s'accorder tous
 * les droits) et les données (restaurer une sauvegarde réécrit comptes et
 * rôles — même porte dérobée).
 *
 * Les libellés vivent ici en français, et ce français est la CLÉ de
 * traduction (cf. I18n) : une constante ne peut pas appeler t(). L'écran ne
 * lit donc jamais les constantes de texte directement — il passe par les
 * accesseurs (actionLabel, actionDefinition, viewUseNote, sections,
 * adminOnly, feature, featuresIn, naReason, requiredLabels, label, summary,
 * clientCatalog), qui les rendent dans la langue de l'écran. Les constantes
 * restent la source : le wiki et les tests en lisent le français mot pour
 * mot. Les refus présentés aux utilisateurs passent, eux, par t() là où ils
 * sont émis.
 */
final class Permissions
{
    public const VIEW   = 'view';
    public const USE    = 'use';
    public const CREATE = 'create';
    public const EDIT   = 'edit';
    public const DELETE = 'delete';
    /**
     * Gérer : ceux de toute l'organisation, pas seulement les siens — les voir
     * tous, les modifier, les partager, en changer la portée (assistants, bases
     * de connaissances). Suppose « Modifier ».
     */
    public const MANAGE = 'manage';

    /** Colonnes de l'écran, dans l'ordre. */
    public const ACTIONS = [self::VIEW, self::USE, self::CREATE, self::EDIT, self::DELETE, self::MANAGE];

    /**
     * Contexte de traduction des libellés de colonnes (cf. I18n::t) : « Voir »
     * y est un droit (« View »), pas le bouton « Voir » d'ailleurs (« See »).
     */
    public const LABEL_CONTEXT = 'droit';

    /** Libellé des colonnes (clés de traduction — cf. actionLabel()). */
    public const ACTION_LABELS = [
        self::VIEW   => 'Voir',
        self::USE    => 'Utiliser',
        self::CREATE => 'Créer',
        self::EDIT   => 'Modifier',
        self::DELETE => 'Supprimer',
        self::MANAGE => 'Gérer',
    ];

    /**
     * Ce que veut dire chaque colonne, pour TOUTES les lignes : l'infobulle de
     * l'en-tête de colonne (le wiki la reprend mot pour mot, gardé par test).
     * Chaque case précise ensuite, pour SA ligne, ce qu'elle ouvre
     * (FEATURES[…]['actions']). « Voir » = voir la fonctionnalité apparaître ;
     * « Utiliser » = s'en servir — deux droits distincts.
     */
    public const ACTION_DEFINITIONS = [
        self::VIEW   => 'Voir la fonctionnalité apparaître : son entrée dans le menu et sa page, en lecture seule. Sans cette case, elle reste cachée — sauf dans la conversation, où « Utiliser » décide.',
        self::USE    => 'S’en servir, sans rien régler : dans la conversation (assistants, bases, outils, connecteurs), en faisant tourner ses tâches et ses workflows, ou, dans l’administration, en lançant tests et réparations.',
        self::CREATE => 'En ajouter. Suppose « Voir ».',
        self::EDIT   => 'En changer les réglages. Suppose « Voir ».',
        self::DELETE => 'En retirer, définitivement. Suppose « Voir ».',
        self::MANAGE => 'Ceux de toute l’organisation, pas seulement les siens : les voir tous, les modifier, les partager et en changer la portée. Suppose « Modifier ».',
    ];

    /**
     * La confusion la plus fréquente : « Voir » n'est pas la condition de
     * « Utiliser » — sauf pour ce qui se lance depuis sa page (les `requires`
     * USE → VIEW du catalogue : tâches, workflows, tests des LLM, diagnostic ;
     * gardé par test). Dans l'infobulle des colonnes Voir et Utiliser, et dans
     * le wiki mot pour mot.
     */
    public const VIEW_USE_NOTE = '« Voir » et « Utiliser » sont indépendants pour ce qui sert dans la conversation : le rôle Utilisateur discute avec les assistants sans voir la page Assistants. Ce qu’on lance depuis sa page — tâches programmées, workflows, tests des modèles, réparations du diagnostic — demande aussi « Voir ».';

    /**
     * Pourquoi une case est sans objet (le tiret de l'écran, en infobulle) :
     * par fonctionnalité et action, sinon par action, sinon une phrase commune.
     * « Gérer » n'a de sens que là où l'on partage ce qu'on crée (assistants,
     * bases) : ce qui est propre à chacun ne se gère pas pour les autres, et ce
     * qui appartient à l'organisation se règle déjà par « Modifier ».
     *
     * @var array<string, string>
     */
    public const NA_REASONS = [
        'manage'            => '« Gérer » ne vaut que pour ce qui se partage : les assistants et les bases de connaissances.',
        'personal.manage'   => 'Propre à chaque personne : chacun ne voit et ne gère que les siens, administrateurs compris.',
        'tools.manage'      => 'Les outils appartiennent à l’organisation : « Modifier » les règle, les groupes décident qui s’en sert.',
        'connectors.create' => 'Les connecteurs sont livrés avec MIA : on les propose ou on les masque (« Modifier »), on ne les crée pas.',
        'connectors.delete' => 'Les connecteurs sont livrés avec MIA : on les masque (« Modifier »), on ne les supprime pas.',
        'use'               => 'Rien à lancer depuis cet écran.',
        'create'            => 'Rien à ajouter sur cet écran.',
        'edit'              => 'Rien à régler sur cet écran : il se consulte.',
        'delete'            => 'Rien à retirer sur cet écran.',
        ''                  => 'Sans objet sur cette ligne.',
    ];

    /**
     * Clés d'avant la colonne « Gérer » → leur remplaçante. L'ancienne portée
     * « toute l'organisation » ne supposait que « Voir » ; « Gérer » suppose
     * « Modifier ». decode() ne la reprend donc que si la carte enregistrée
     * accordait aussi « Modifier » : sinon le rôle gagnerait « Modifier » en
     * silence. (Une portée sans « Modifier » ne donnait que la lecture de tout :
     * elle tombe — à recocher en connaissance de cause.)
     */
    public const LEGACY_KEYS = [
        'assistants.all'      => 'assistants.manage',
        'knowledge_bases.all' => 'knowledge_bases.manage',
    ];

    public const SECTION_FEATURES = 'features';
    public const SECTION_ADMIN    = 'admin';

    public const SECTIONS = [
        self::SECTION_FEATURES => [
            'label'   => 'Fonctionnalités',
            'summary' => 'Ce que la personne fait dans MIA (Utiliser), les pages où elle tient ce qu’elle crée (Voir, Créer, Modifier, Supprimer) et ce qu’elle gère pour toute l’organisation (Gérer).',
        ],
        self::SECTION_ADMIN => [
            'label'   => 'Administration',
            'summary' => 'Les écrans de l’administration qu’elle voit (Voir, en lecture seule), ce qu’elle y lance sans rien régler (Utiliser) et ce qu’elle y change (Créer, Modifier, Supprimer).',
        ],
    ];

    /**
     * Catalogue. Ordre = ordre de l'écran (et, pour l'administration, ordre
     * du menu latéral). `actions` : action → ce que la case ouvre ;
     * `requires` : ce qu'une action entraîne (cocher « Modifier » coche
     * « Voir » — la même fermeture côté serveur, cf. close()) ; `personal` :
     * propre à chaque personne (chacun ne voit et ne gère que les siens).
     *
     * @var array<string, array{section:string, label:string, summary:string,
     *     actions:array<string,string>, requires:array<string, list<string>>,
     *     personal?:bool}>
     */
    public const FEATURES = [
        // ---- Fonctionnalités --------------------------------------------
        'assistants' => [
            'section' => self::SECTION_FEATURES,
            'label'   => 'Assistants',
            'summary' => 'Discuter avec les assistants et les concevoir.',
            'actions' => [
                self::USE    => 'Dans la conversation : discuter avec les assistants de ses groupes, avec les siens et avec ceux proposés à tous. Sans cette case, aucun assistant, même du groupe ★ Par défaut.',
                self::VIEW   => 'La page Assistants apparaît dans son menu : la liste et la fiche des siens — de tous, avec « Gérer » —, en lecture seule. Inutile pour discuter avec eux.',
                self::CREATE => 'Créer des assistants, aussi en le demandant à MIA dans la conversation — en nombre limité, sauf avec « Gérer ».',
                self::EDIT   => 'Modifier ses assistants : consignes, modèle, bases, outils, documents, avatar.',
                self::DELETE => 'Supprimer ses assistants — avec « Gérer », ceux des autres aussi.',
                self::MANAGE => 'Tous les assistants de l’organisation, pas seulement les siens : les voir, les modifier, les partager aux groupes ou les rendre personnels, sans plafond. Dans la conversation, discuter avec tous, sans passer par les groupes.',
            ],
            'requires' => [
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
                self::MANAGE => [self::EDIT],
            ],
        ],
        'knowledge_bases' => [
            'section' => self::SECTION_FEATURES,
            'label'   => 'Bases de connaissances',
            'summary' => 'Interroger des bases dans la conversation et les alimenter.',
            'actions' => [
                self::USE    => 'Dans la conversation : cocher des bases à interroger, parmi les siennes et celles qu’on lui partage. Les bases liées à un assistant répondent avec lui même sans cette case — sauf un cerveau MyBrain, qui l’exige.',
                self::VIEW   => 'La page Bases de connaissances apparaît dans son menu : la liste et la fiche des siennes et de celles qu’on lui partage — de toutes, avec « Gérer » —, en lecture seule. Inutile pour s’en servir.',
                self::CREATE => 'Créer des bases : fichiers déposés, dossier synchronisé — en nombre limité, sauf avec « Gérer ».',
                self::EDIT   => 'Modifier ses bases et celles qu’on lui partage en écriture : documents, synchronisation, réindexation ; partager les siennes.',
                self::DELETE => 'Supprimer ses bases — avec « Gérer », celles des autres aussi.',
                self::MANAGE => 'Toutes les bases de l’organisation, pas seulement les siennes : les voir, les modifier, en régler le partage, sans plafond ; brancher un cerveau MyBrain, régler les sources synchronisées. Dans la conversation, les interroger toutes.',
            ],
            'requires' => [
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
                self::MANAGE => [self::EDIT],
            ],
        ],
        'schedules' => [
            'section' => self::SECTION_FEATURES,
            'personal' => true,
            'label'   => 'Tâches programmées',
            'summary' => 'Confier à MIA une demande refaite à date fixe. Propres à chaque personne : chacun ne voit et ne gère que les siennes, administrateurs compris.',
            'actions' => [
                self::VIEW   => 'La page Tâches programmées apparaît dans ses paramètres : ses tâches, leur rythme et leurs exécutions, en lecture seule.',
                self::USE    => 'Faire tourner ses tâches : à leur heure, « Exécuter maintenant », essai. Sans cette case, leurs exécutions sont sautées, sans mise en pause.',
                self::CREATE => 'Créer des tâches, aussi en le demandant à MIA dans la conversation.',
                self::EDIT   => 'Modifier ses tâches et les mettre en pause.',
                self::DELETE => 'Supprimer ses tâches.',
            ],
            'requires' => [
                self::USE    => [self::VIEW],
                self::CREATE => [self::USE],
                self::EDIT   => [self::USE],
                self::DELETE => [self::USE],
            ],
        ],
        'workflows' => [
            'section' => self::SECTION_FEATURES,
            'personal' => true,
            'label'   => 'Workflows',
            'summary' => 'Un travail en plusieurs étapes confiées à des assistants. Propres à chaque personne : chacun ne voit et ne gère que les siens, administrateurs compris.',
            'actions' => [
                self::VIEW   => 'La page Workflows apparaît dans son menu : ses workflows et le suivi de leurs exécutions, en lecture seule.',
                self::USE    => 'Lancer ses workflows (à la main, avec « @ » dans la conversation, à leur heure, sur un évènement) et trancher leurs validations.',
                self::CREATE => 'Créer et dupliquer des workflows, aussi en le demandant à MIA.',
                self::EDIT   => 'Modifier, publier, activer ou désactiver ses workflows.',
                self::DELETE => 'Supprimer ses workflows.',
            ],
            'requires' => [
                self::USE    => [self::VIEW],
                self::CREATE => [self::USE],
                self::EDIT   => [self::USE],
                self::DELETE => [self::USE],
            ],
        ],
        'tools' => [
            'section' => self::SECTION_FEATURES,
            'label'   => 'Outils',
            'summary' => 'Image, page web, vidéo, carte, automatisations : les capacités de génération.',
            'actions' => [
                self::USE    => 'Dans la conversation : générer avec les outils que ses groupes lui ouvrent (menu « + ») et avec ceux de ses assistants. Sans cette case, aucun outil.',
                self::VIEW   => 'La page Outils apparaît dans son menu d’administration : les outils et leurs réglages, en lecture seule. Inutile pour s’en servir.',
                self::CREATE => 'Ajouter des outils.',
                self::EDIT   => 'Modifier, tester, activer ou désactiver les outils et les fonctions intégrées (dictée, tâches, workflows).',
                self::DELETE => 'Supprimer des outils.',
            ],
            'requires' => [
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
            ],
        ],
        'connectors' => [
            'section' => self::SECTION_FEATURES,
            'personal' => true,
            'label'   => 'Connecteurs',
            'summary' => 'Google, Microsoft, Slack : les comptes personnels reliés à MIA. Chacun ne relie que les siens.',
            'actions' => [
                self::USE  => 'Relier ses comptes (Réglages → Comptes connectés) et laisser MIA s’en servir dans la conversation : chercher, lire, préparer des actions à approuver.',
                self::VIEW => 'La page Connecteurs apparaît dans son menu d’administration : les connecteurs proposés et leurs réglages, en lecture seule. Inutile pour s’en servir.',
                self::EDIT => 'Proposer ou masquer un connecteur, ses expressions déclencheuses, l’application Slack.',
            ],
            'requires' => [
                self::EDIT => [self::VIEW],
            ],
        ],

        // ---- Administration ---------------------------------------------
        'dashboard' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Tableau de bord',
            'summary' => 'La vue d’ensemble de l’instance.',
            'actions' => [
                self::VIEW => 'Le tableau de bord apparaît dans son menu d’administration : consommation, coûts, comptes, points à régler.',
            ],
            'requires' => [],
        ],
        'users' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Comptes',
            'summary' => 'Les comptes des utilisateurs.',
            'actions' => [
                self::VIEW   => 'La page Comptes apparaît dans son menu : la liste, la consommation et les groupes de chacun, en lecture seule.',
                self::CREATE => 'Créer des comptes.',
                self::EDIT   => 'Modifier un compte : rôle, activation, quota, groupes, mot de passe ; règles d’accès.',
                self::DELETE => 'Supprimer des comptes.',
            ],
            'requires' => [
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
            ],
        ],
        'groups' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Groupes',
            'summary' => 'Les groupes : membres, quotas, assistants, outils et modèles autorisés.',
            'actions' => [
                self::VIEW   => 'La page Groupes apparaît dans son menu : leurs membres, leurs quotas et ce qu’ils ouvrent, en lecture seule.',
                self::CREATE => 'Créer des groupes.',
                self::EDIT   => 'Modifier un groupe : membres, quota, assistants, outils et modèles autorisés.',
                self::DELETE => 'Supprimer des groupes.',
            ],
            'requires' => [
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
            ],
        ],
        'llms' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Gestion des LLM',
            'summary' => 'Clés API, modèles activés, comportement par défaut, tests.',
            'actions' => [
                self::VIEW   => 'Modèles IA apparaît dans son menu : les clés API (masquées), les modèles, le comportement par défaut et la page Tests, en lecture seule.',
                self::USE    => 'Lancer les tests (une clé, un modèle, la page Tests) sans rien changer aux réglages — ils consomment des crédits.',
                self::CREATE => 'Ajouter une clé API, activer un modèle.',
                self::EDIT   => 'Modifier une clé, un modèle, le comportement par défaut.',
                self::DELETE => 'Supprimer une clé API, retirer un modèle.',
            ],
            'requires' => [
                self::USE    => [self::VIEW],
                self::CREATE => [self::VIEW],
                self::EDIT   => [self::VIEW],
                self::DELETE => [self::VIEW],
            ],
        ],
        'sso' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Authentification (SSO)',
            'summary' => 'Connexion Google, Microsoft, mot de passe, domaines, message de connexion.',
            'actions' => [
                self::VIEW => 'Authentification apparaît dans son menu : les méthodes de connexion et leurs réglages (secrets masqués), en lecture seule.',
                self::EDIT => 'Modifier et tester les méthodes de connexion.',
            ],
            'requires' => [
                self::EDIT => [self::VIEW],
            ],
        ],
        'costs' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Coûts',
            'summary' => 'Budget mensuel, usages, tarifs et crédits.',
            'actions' => [
                self::VIEW => 'Budget, Consommation et Tarifs & crédits apparaissent dans son menu, en lecture seule ; les usages s’exportent.',
                self::EDIT => 'Régler le budget, accorder une rallonge, modifier tarifs et crédits.',
            ],
            'requires' => [
                self::EDIT => [self::VIEW],
            ],
        ],
        'company' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Entreprise et apparence',
            'summary' => 'Présentation de l’entreprise, modèles de documents, marque de l’application.',
            'actions' => [
                self::VIEW => 'Les pages Entreprise et Marque & apparence apparaissent dans son menu, en lecture seule.',
                self::EDIT => 'Les modifier : descriptif, vocabulaire, logos, modèles PowerPoint et Word, couleurs.',
            ],
            'requires' => [
                self::EDIT => [self::VIEW],
            ],
        ],
        'diagnostics' => [
            'section' => self::SECTION_ADMIN,
            'label'   => 'Diagnostic',
            'summary' => 'Contrôles de l’instance, journaux, moteur vectoriel, statistiques de l’agent.',
            'actions' => [
                self::VIEW => 'Diagnostic apparaît dans son menu : les contrôles, les journaux, le moteur vectoriel et les statistiques de l’agent, en lecture seule.',
                self::USE  => 'Réparer et nettoyer le moteur vectoriel, sans changer aucun réglage.',
            ],
            'requires' => [
                self::USE => [self::VIEW],
            ],
        ],
    ];

    /**
     * Domaines que seul un administrateur ouvre, quel que soit le rôle :
     * affichés verrouillés sur l'écran, avec leur raison.
     */
    public const ADMIN_ONLY = [
        'roles' => [
            'label'   => 'Rôles et droits',
            'summary' => 'Cet écran : un rôle qui pourrait modifier les rôles pourrait s’accorder tous les droits.',
        ],
        'data' => [
            'label'   => 'Données : sauvegarde, restauration, élagage',
            'summary' => 'Restaurer une sauvegarde réécrit les comptes et les rôles ; l’élagage supprime définitivement.',
        ],
    ];

    /**
     * Droits des rôles livrés. Ils reproduisent les droits d'avant l'écran des
     * rôles : un utilisateur discute (assistants, bases partagées, outils,
     * connecteurs) et programme ses tâches ; un créateur conçoit en plus ses
     * assistants, ses bases et ses workflows. L'administrateur a tout
     * (allKeys()), sans liste à tenir.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULTS = [
        'user' => [
            'assistants.use',
            'knowledge_bases.use',
            'schedules.view', 'schedules.use', 'schedules.create', 'schedules.edit', 'schedules.delete',
            'tools.use',
            'connectors.use',
        ],
        'creator' => [
            'assistants.use', 'assistants.view', 'assistants.create', 'assistants.edit', 'assistants.delete',
            'knowledge_bases.use', 'knowledge_bases.view', 'knowledge_bases.create', 'knowledge_bases.edit', 'knowledge_bases.delete',
            'schedules.view', 'schedules.use', 'schedules.create', 'schedules.edit', 'schedules.delete',
            'workflows.view', 'workflows.use', 'workflows.create', 'workflows.edit', 'workflows.delete',
            'tools.use',
            'connectors.use',
        ],
    ];

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    public static function key(string $feature, string $action): string
    {
        return $feature . '.' . $action;
    }

    /**
     * Tous les droits du catalogue, dans l'ordre de l'écran.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::FEATURES as $feature => $def) {
            foreach (self::ACTIONS as $action) {
                if (isset($def['actions'][$action])) {
                    $keys[] = self::key($feature, $action);
                }
            }
        }
        return $keys;
    }

    public static function isKnown(string $key): bool
    {
        [$feature, $action] = array_pad(explode('.', $key, 2), 2, '');
        return isset(self::FEATURES[$feature]['actions'][$action]);
    }

    /**
     * Pourquoi cette case est sans objet (infobulle du tiret) : la raison
     * propre à la ligne, puis celle des lignes propres à chacun, puis celle de
     * l'action, puis une phrase commune (NA_REASONS).
     */
    public static function naReason(string $feature, string $action): string
    {
        $def = self::FEATURES[$feature] ?? [];
        return t(self::NA_REASONS[$feature . '.' . $action]
            ?? (!empty($def['personal']) ? (self::NA_REASONS['personal.' . $action] ?? null) : null)
            ?? self::NA_REASONS[$action]
            ?? self::NA_REASONS['']);
    }

    /**
     * Ce qu'une case suppose directement (« Gérer » suppose « Modifier »), en
     * libellés de colonnes — l'infobulle de la case le dit.
     *
     * @return list<string>
     */
    public static function requiredLabels(string $feature, string $action): array
    {
        return array_map(
            static fn(string $a): string => self::actionLabel($a),
            self::FEATURES[$feature]['requires'][$action] ?? []
        );
    }

    // ------------------------------------------------------------------
    // Libellés affichés, dans la langue de l'écran
    // ------------------------------------------------------------------

    /** Libellé d'une colonne : « Voir », « Gérer »… (l'action brute si inconnue). */
    public static function actionLabel(string $action): string
    {
        return isset(self::ACTION_LABELS[$action])
            ? t(self::ACTION_LABELS[$action], [], self::LABEL_CONTEXT)
            : $action;
    }

    /** Ce que veut dire une colonne, pour toutes les lignes (ACTION_DEFINITIONS). */
    public static function actionDefinition(string $action): string
    {
        return isset(self::ACTION_DEFINITIONS[$action]) ? t(self::ACTION_DEFINITIONS[$action]) : '';
    }

    /** La note « Voir » / « Utiliser » (VIEW_USE_NOTE). */
    public static function viewUseNote(): string
    {
        return t(self::VIEW_USE_NOTE);
    }

    /** @return array<string, array{label: string, summary: string}> les sections, dans l'ordre */
    public static function sections(): array
    {
        return array_map(self::translatedEntry(...), self::SECTIONS);
    }

    /** @return array<string, array{label: string, summary: string}> les domaines réservés (ADMIN_ONLY) */
    public static function adminOnly(): array
    {
        return array_map(self::translatedEntry(...), self::ADMIN_ONLY);
    }

    /**
     * Une fonctionnalité du catalogue telle que l'écran l'affiche : nom,
     * phrase et phrase de chaque case traduits ; null si inconnue.
     *
     * @return array<string, mixed>|null
     */
    public static function feature(string $feature): ?array
    {
        $def = self::FEATURES[$feature] ?? null;
        if ($def === null) {
            return null;
        }
        $def = self::translatedEntry($def);
        $def['actions'] = array_map(static fn(string $phrase): string => t($phrase), $def['actions']);
        return $def;
    }

    /** Fonctionnalités d'une section, dans l'ordre, libellés traduits. @return array<string, array> */
    public static function featuresIn(string $section): array
    {
        $features = [];
        foreach (self::FEATURES as $feature => $def) {
            if ($def['section'] === $section) {
                $features[$feature] = self::feature($feature);
            }
        }
        return $features;
    }

    /**
     * `label` et `summary` d'une entrée, traduits.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function translatedEntry(array $entry): array
    {
        $entry['label']   = t((string)$entry['label']);
        $entry['summary'] = t((string)$entry['summary']);
        return $entry;
    }

    /**
     * Droits d'un rôle livré, sans surcharge ; null pour un rôle qui n'en est
     * pas un (les rôles personnalisés n'ont pas de défaut : un droit qu'aucun
     * administrateur n'a coché est refusé).
     *
     * @return list<string>|null
     */
    public static function defaultsFor(string $role): ?array
    {
        if ($role === 'admin') {
            return self::allKeys();
        }
        return isset(self::DEFAULTS[$role]) ? self::close(self::DEFAULTS[$role]) : null;
    }

    // ------------------------------------------------------------------
    // Ensembles de droits
    // ------------------------------------------------------------------

    /**
     * Fermeture d'un ensemble : droits inconnus écartés, droits entraînés
     * ajoutés (« Modifier » entraîne « Voir »), ordre du catalogue. Cocher une
     * case à l'écran coche ce qu'elle entraîne — le serveur rejoue la même
     * règle sur ce qu'il reçoit, quoi qu'on lui envoie.
     *
     * @param iterable<string> $keys
     * @return list<string>
     */
    public static function close(iterable $keys): array
    {
        $set = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key !== '' && self::isKnown($key)) {
                $set[$key] = true;
            }
        }
        // Les implications sont courtes (au plus deux crans : supprimer →
        // utiliser → voir) : on itère jusqu'à stabilité.
        do {
            $added = false;
            foreach (array_keys($set) as $key) {
                [$feature, $action] = explode('.', $key, 2);
                foreach (self::FEATURES[$feature]['requires'][$action] ?? [] as $needed) {
                    $neededKey = self::key($feature, $needed);
                    if (!isset($set[$neededKey]) && self::isKnown($neededKey)) {
                        $set[$neededKey] = true;
                        $added = true;
                    }
                }
            }
        } while ($added);

        return array_values(array_filter(self::allKeys(), static fn(string $k): bool => isset($set[$k])));
    }

    /**
     * Droits cochés d'un formulaire : `perms[assistants][]=view` ou
     * `perms[]=assistants.view`, les deux formes donnent le même ensemble
     * fermé.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function fromPost($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $keys = [];
        foreach ($raw as $feature => $actions) {
            if (is_array($actions)) {
                foreach ($actions as $action) {
                    $keys[] = self::key((string)$feature, (string)$action);
                }
            } elseif (is_string($actions)) {
                $keys[] = $actions;
            }
        }
        return self::close($keys);
    }

    /**
     * Forme stockée → ensemble de droits. Stockage = carte droit → oui/non
     * pour TOUS les droits connus au moment de l'enregistrement : un droit
     * ajouté par une version future n'y figure pas et prend la valeur de
     * `$fallback` (le défaut d'un rôle livré ; rien pour un rôle personnalisé).
     *
     * @param list<string> $fallback
     * @return list<string>
     */
    public static function decode(?string $json, array $fallback = []): array
    {
        $map = $json !== null && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($map)) {
            return self::close($fallback);
        }
        $fallbackSet = array_flip($fallback);
        $map = self::upgradeLegacy($map, $fallbackSet);
        $granted = [];
        foreach (self::allKeys() as $key) {
            $granted[$key] = array_key_exists($key, $map) ? (bool)$map[$key] : isset($fallbackSet[$key]);
        }
        return self::close(array_keys(array_filter($granted)));
    }

    /**
     * Carte enregistrée avant la colonne « Gérer » : l'ancienne portée
     * « toute l'organisation » devient « Gérer » si — et seulement si — la
     * carte accordait aussi « Modifier » (que « Gérer » suppose). Sans cette
     * condition, un rôle qui ne faisait que lire tout gagnerait « Modifier ».
     *
     * @param array<string, mixed> $map
     * @param array<string, int> $fallbackSet
     * @return array<string, mixed>
     */
    private static function upgradeLegacy(array $map, array $fallbackSet): array
    {
        foreach (self::LEGACY_KEYS as $old => $new) {
            if (array_key_exists($new, $map) || !array_key_exists($old, $map)) {
                continue;
            }
            [$feature] = explode('.', $new, 2);
            $edit = self::key($feature, self::EDIT);
            $hasEdit = array_key_exists($edit, $map) ? (bool)$map[$edit] : isset($fallbackSet[$edit]);
            $map[$new] = (bool)$map[$old] && $hasEdit;
        }
        return $map;
    }

    /**
     * Ensemble de droits → forme stockée (carte de TOUS les droits connus).
     *
     * @param list<string> $keys
     */
    public static function encode(array $keys): string
    {
        $set = array_flip(self::close($keys));
        $map = [];
        foreach (self::allKeys() as $key) {
            $map[$key] = isset($set[$key]);
        }
        return (string)json_encode($map);
    }

    /**
     * `$a` ⊆ `$b` : aucun droit de `$a` n'échappe à `$b`. C'est la garde
     * contre l'élévation de privilège : un gestionnaire de comptes qui n'est
     * pas administrateur ne donne jamais un rôle plus large que le sien.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function isSubset(array $a, array $b): bool
    {
        return array_diff($a, $b) === [];
    }

    /**
     * Ce qu'un enregistrement change, pour le résumé « 3 droits ajoutés ».
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return array{added: list<string>, removed: list<string>}
     */
    public static function diff(array $before, array $after): array
    {
        return [
            'added'   => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ];
    }

    /** Libellé lisible d'un droit : « Assistants — Modifier », « Bases de connaissances — Gérer ». */
    public static function label(string $key): string
    {
        [$feature, $action] = array_pad(explode('.', $key, 2), 2, '');
        $def = self::FEATURES[$feature] ?? null;
        if ($def === null) {
            return $key;
        }
        return t($def['label']) . ' — ' . self::actionLabel($action);
    }

    /**
     * Résumé d'un ensemble de droits pour la carte d'un rôle : les
     * fonctionnalités ouvertes (au moins un droit), et le nombre d'écrans
     * d'administration.
     *
     * @param list<string> $keys
     * @return array{features: list<string>, admin: list<string>, count: int}
     */
    public static function summary(array $keys): array
    {
        $byFeature = [];
        foreach ($keys as $key) {
            [$feature] = explode('.', $key, 2);
            $byFeature[$feature] = true;
        }
        $features = [];
        $admin    = [];
        foreach (self::FEATURES as $feature => $def) {
            if (!isset($byFeature[$feature])) {
                continue;
            }
            if ($def['section'] === self::SECTION_ADMIN) {
                $admin[] = t($def['label']);
            } else {
                $features[] = t($def['label']);
            }
        }
        return ['features' => $features, 'admin' => $admin, 'count' => count($keys)];
    }

    /**
     * Le catalogue tel que l'écran le dessine (JSON pour le script de la
     * page) : sections, fonctionnalités, actions, implications — libellés
     * dans la langue de l'écran, le script n'a rien à traduire.
     *
     * @return array<string, mixed>
     */
    public static function clientCatalog(): array
    {
        $features = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $def = (array)self::feature($feature);
            $features[] = [
                'key'      => $feature,
                'section'  => $def['section'],
                'label'    => $def['label'],
                'summary'  => $def['summary'],
                'actions'  => $def['actions'],
                'requires' => (object)$def['requires'],
            ];
        }
        $actions = [];
        foreach (self::ACTIONS as $action) {
            $actions[$action] = self::actionLabel($action);
        }
        return [
            'actions'  => $actions,
            'sections' => self::sections(),
            'features' => $features,
            'all_keys' => self::allKeys(),
        ];
    }
}
