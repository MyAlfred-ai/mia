<?php

declare(strict_types=1);

/**
 * Catalogue des connecteurs — SOURCE UNIQUE.
 *
 * Un « connecteur » = une source externe que l'utilisateur relie à MIA :
 * Recherche Internet, Google Drive, Gmail, Google Agenda, Google Tasks, Outlook,
 * le calendrier Outlook, Microsoft To Do, Microsoft Teams et Slack (surface
 * `chat`, ligne du menu « + » d'une conversation) et OneDrive (surface `kb`, source d'une base de connaissances).
 *
 * TROIS FAMILLES de connecteurs personnels cohabitent, chacune avec son socle :
 * `google` (GoogleUserOAuth + GoogleUserApiService, client OAuth du login
 * Google), `microsoft` (MicrosoftUserOAuth + MicrosoftUserApiService,
 * application Azure du login Microsoft) et `slack` (SlackUserOAuth +
 * SlackUserApiService, application Slack propre — Slack n'étant pas un moyen
 * de connexion à MIA, ses identifiants vivent dans SlackApp et non dans
 * SsoRegistry). Le champ `family` est ce qui permet aux gardes de tester le bon
 * socle ; tout le reste — visibilité, jetons, expressions, câblage chat — est
 * identique et ne connaît pas le fournisseur.
 *
 * L'administrateur décide, connecteur par connecteur, s'il apparaît ou non
 * dans l'interface des utilisateurs (`/admin/connecteurs`). Un connecteur
 * masqué disparaît PARTOUT : ligne absente du menu « + », carte absente de
 * Réglages → Comptes connectés — jamais une option morte —, et le serveur ignore
 * l'option même si elle arrive quand même dans le POST. Seule exception, et
 * elle est délibérée : la carte reste visible pour qui a DÉJÀ relié son
 * compte, en lecture seule, afin qu'il puisse se déconnecter (cf.
 * `connectionCardState`).
 *
 * Les connecteurs à service dédié réutilisent la clé Settings déclarée par ce
 * service (`UserDriveService::SETTING_ENABLED`…) : une seule clé en base, deux
 * accesseurs (le service pour le chat, ce registre pour l'admin). Chaque
 * connecteur personnel déclare aussi ses classes `oauth` et `service` (socle
 * Google ou Microsoft selon sa `family`) : le prérequis technique
 * (isConfigured), l'état chat (chatFeatureState) et les écrans Connexions les
 * lisent ICI au lieu de les connaître en dur.
 * Ajouter un connecteur = une entrée dans `catalog()` (+ sa clé Settings).
 *
 * Deux modèles d'accès cohabitent, et la différence tient dans `needs_link` :
 * les connecteurs Google et Microsoft sont **personnels** (chaque utilisateur
 * relie SON compte), la Recherche Internet est une brique **d'organisation**
 * (un réglage global, aucun jeton par utilisateur).
 *
 * n8n a quitté ce catalogue : déclencher une automatisation n'est pas
 * consulter une source, c'est une capacité confiée à un assistant — donc un
 * OUTIL (`chat_tools`, page Outils), cf. N8nService.
 */
final class ConnectorRegistry
{
    public const WEB      = 'web';
    public const DRIVE    = 'drive';
    public const GMAIL    = 'gmail';
    public const CALENDAR = 'calendar';
    public const TASKS    = 'tasks';
    public const ONEDRIVE = 'onedrive';
    public const OUTLOOK  = 'outlook';
    /** Slack : troisième famille (ni Google ni Microsoft), cf. SlackUserOAuth. */
    public const SLACK    = 'slack';
    /**
     * Calendrier Outlook. Clé volontairement distincte de CALENDAR (Google) :
     * ce sont deux sources, deux consentements, deux jetons — et un utilisateur
     * peut très bien avoir les deux.
     */
    public const MSCALENDAR = 'mscalendar';
    /**
     * Microsoft To Do. Distinct de TASKS (Google Tasks) pour la même raison :
     * deux sources, deux consentements, deux jetons.
     */
    public const MSTODO = 'mstodo';
    /**
     * Microsoft Teams (conversations et canaux). Famille Microsoft, jeton
     * distinct — et le seul connecteur dont le consentement exige un
     * administrateur de l'annuaire (lecture des canaux).
     */
    public const TEAMS = 'teams';

    /**
     * Surface d'usage — où le connecteur se manifeste pour l'utilisateur.
     * `chat` : ligne du menu « + » d'une conversation (et donc expressions
     * déclencheuses). `kb` : sert à bâtir une base de connaissances, jamais
     * exposé au chat (OneDrive). Les deux surfaces partagent le MÊME
     * interrupteur d'administration : c'est lui qui décide si le connecteur
     * apparaît, ou non, dans l'interface des utilisateurs.
     */
    public const SURFACE_CHAT = 'chat';
    public const SURFACE_KB   = 'kb';

    /** Connecteurs sans service dédié : leur clé Settings vit ici. */
    public const SETTING_WEB      = 'connector_web_enabled';
    public const SETTING_ONEDRIVE = 'connector_onedrive_enabled';

    /**
     * « Laisser MIA choisir » (ConnectorLoaderTools) : les connecteurs reliés
     * mais éteints deviennent activables par le modèle. Clé pseudo-connecteur
     * de la page d'administration et du menu « + » ; réglage global, défaut
     * '1' — l'utilisateur doit encore l'allumer lui-même.
     */
    public const AUTO         = 'auto';
    public const SETTING_AUTO = 'connectors_auto_enabled';

    /**
     * Expressions déclencheuses de la suggestion automatique (tool-suggest.js) :
     * une clé Settings par connecteur, valeur = une expression par ligne.
     * Clé ABSENTE (null) = liste par défaut ci-dessous — et c'est ce que
     * `setTriggers` ré-écrit quand l'admin enregistre exactement les défauts,
     * pour qu'une amélioration future des défauts profite aux installations
     * qui n'ont rien personnalisé. Valeur VIDE = suggestion désactivée.
     */
    public const TRIGGERS_PREFIX = 'connector_triggers_';
    public const MAX_TRIGGERS    = 40;
    public const MAX_TRIGGER_LEN = 60;

    /** Prérequis Google commun aux quatre connecteurs personnels Google. */
    private const WARN_GOOGLE = 'Identifiants Google absents — renseignez-les dans Organisation → '
        . 'Authentification. Même autorisé, ce connecteur restera indisponible tant qu\'ils manquent.';

    /**
     * Prérequis Microsoft. L'application Azure est celle du LOGIN, mais la
     * connexion Microsoft n'a PAS besoin d'être activée pour autant : seuls
     * les identifiants comptent. Le dire ici évite qu'un administrateur sous
     * login Google conclue que le connecteur lui est fermé.
     */
    private const WARN_MICROSOFT = 'Identifiants Microsoft absents — renseignez-les dans Organisation → '
        . 'Authentification (l\'application Azure suffit : la connexion Microsoft n\'a pas besoin d\'être '
        . 'activée). Même autorisé, ce connecteur restera indisponible tant qu\'ils manquent.';

    /**
     * Prérequis Slack. Slack n'étant pas un moyen de connexion à MIA, ses
     * identifiants ne vivent pas dans Organisation → Authentification mais sur
     * leur propre écran, atteint depuis la carte ci-dessous.
     */
    private const WARN_SLACK = 'Application Slack absente — créez-la sur api.slack.com puis renseignez ses '
        . 'identifiants ci-dessous. Même autorisé, ce connecteur restera indisponible tant qu\'ils manquent.';

    /**
     * Descripteurs statiques — aucune lecture de base ni de réglage.
     *
     * L'ordre est celui du menu « + » du chat.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            [
                'key'          => self::WEB,
                'surface'      => self::SURFACE_CHAT,
                'family'       => null,
                'setting'      => self::SETTING_WEB,
                'label'        => 'Recherche Internet',
                'chat_label'   => 'Recherche Internet',
                'summary'      => "Laisse l'assistant consulter le web avant de répondre "
                                . "(actualité, chiffres du jour, pages publiques).",
                'capabilities' => ['Lecture web'],
                'icon'         => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                'needs_oauth'  => false,
                'oauth'        => null,
                'service'      => null,
                'needs_link'   => false,
                'token'        => null,
                'note'         => "Dépend aussi du modèle : seuls les modèles capables de recherche "
                                . "native (ou disposant d'un modèle de secours) interrogent le web.",
                'warn'         => '',
                'settings_url' => null,
                'settings_label' => '',
            ],
            [
                'key'          => self::DRIVE,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'google',
                'setting'      => UserDriveService::SETTING_ENABLED,
                'label'        => 'Google Drive',
                'chat_label'   => 'Fichiers Google Drive',
                'summary'      => "Chaque utilisateur connecte son propre Drive : l'assistant y cherche "
                                . "et lit ses documents, et crée ou modifie ses Docs et Sheets après "
                                . "approbation. Aucun fichier n'est jamais supprimé.",
                'capabilities' => ['Recherche', 'Lecture', 'Écriture approuvée'],
                'icon'         => '<img src="/assets/img/google-drive.svg" width="22" height="20" alt="">',
                'needs_oauth'  => true,
                'oauth'        => GoogleDriveOAuth::class,
                'service'      => UserDriveService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER,
                'note'         => '',
                'warn'         => self::WARN_GOOGLE,
                'settings_url' => null,
                'settings_label' => '',
            ],
            [
                'key'          => self::GMAIL,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'google',
                'setting'      => UserGmailService::SETTING_ENABLED,
                'label'        => 'Gmail',
                'chat_label'   => 'Emails Gmail',
                'summary'      => "Chaque utilisateur connecte son propre Gmail : l'assistant recherche "
                                . "et lit ses emails, et prépare des emails que l'utilisateur relit puis "
                                . "enregistre en brouillon ou envoie d'un clic. Rien ne part sans ce clic.",
                'capabilities' => ['Recherche', 'Lecture', 'Brouillons', 'Envoi sur clic'],
                'icon'         => '<img src="/assets/img/gmail.svg" width="22" height="17" alt="">',
                'needs_oauth'  => true,
                'oauth'        => GoogleGmailOAuth::class,
                'service'      => UserGmailService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_GMAIL,
                'note'         => '',
                'warn'         => self::WARN_GOOGLE,
                'settings_url' => null,
                'settings_label' => '',
            ],
            [
                'key'          => self::CALENDAR,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'google',
                'setting'      => UserCalendarService::SETTING_ENABLED,
                'label'        => 'Google Agenda',
                'chat_label'   => 'Agenda Google',
                'summary'      => "Chaque utilisateur connecte son propre agenda : l'assistant consulte "
                                . "ses évènements et en crée après approbation. Aucune invitation n'est "
                                . "envoyée, aucun évènement modifié ni supprimé.",
                'capabilities' => ['Lecture', 'Création approuvée'],
                'icon'         => '<img src="/assets/img/google-calendar.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => GoogleCalendarOAuth::class,
                'service'      => UserCalendarService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_CALENDAR,
                'note'         => '',
                'warn'         => self::WARN_GOOGLE,
                'settings_url' => null,
                'settings_label' => '',
            ],
            [
                'key'          => self::TASKS,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'google',
                'setting'      => UserTasksService::SETTING_ENABLED,
                'label'        => 'Google Tasks',
                'chat_label'   => 'Tâches Google',
                'summary'      => "Chaque utilisateur connecte ses propres tâches : l'assistant les consulte, "
                                . "en ajoute, les modifie et — s'il l'autorise — les supprime, chaque écriture "
                                . "étant soumise à son approbation.",
                'capabilities' => ['Lecture', 'Écriture approuvée', 'Suppression approuvée'],
                'icon'         => '<img src="/assets/img/google-tasks.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => GoogleTasksOAuth::class,
                'service'      => UserTasksService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_TASKS,
                // Seul connecteur qui supprime : Google Tasks n'a pas de scope
                // « écrire sans supprimer ». Le dire ici évite qu'un admin
                // l'autorise en croyant l'accès aussi étroit que les autres.
                'note'         => "Seul connecteur capable de SUPPRIMER une donnée : Google ne propose aucun "
                                . "droit d'écriture sans suppression. Chaque suppression passe par une carte "
                                . "d'approbation, et chaque utilisateur peut la désactiver pour son compte "
                                . "(Réglages → Comptes connectés).",
                'warn'         => self::WARN_GOOGLE,
                'settings_url' => null,
                'settings_label' => '',
            ],
            [
                'key'          => self::OUTLOOK,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'microsoft',
                'setting'      => UserOutlookService::SETTING_ENABLED,
                'label'        => 'Outlook',
                'chat_label'   => 'Emails Outlook',
                'summary'      => "Chaque utilisateur connecte sa propre boîte Outlook : l'assistant recherche "
                                . "et lit ses emails, et prépare des emails que l'utilisateur relit puis "
                                . "enregistre en brouillon ou envoie d'un clic. Rien ne part sans ce clic.",
                'capabilities' => ['Recherche', 'Lecture', 'Brouillons', 'Envoi sur clic'],
                'icon'         => '<img src="/assets/img/outlook.svg" width="22" height="20" alt="">',
                'needs_oauth'  => true,
                'oauth'        => MicrosoftOutlookOAuth::class,
                'service'      => UserOutlookService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_OUTLOOK,
                // Microsoft Graph n'a pas d'équivalent de gmail.compose : le
                // seul scope permettant de créer un brouillon (Mail.ReadWrite)
                // autorise aussi, CÔTÉ API, la modification et la suppression
                // de messages. MIA n'expose aucun outil pour cela — mais un
                // administrateur doit savoir ce que son annuaire consent.
                // Mail.Send ne sert qu'au bouton « Envoyer » de la carte de
                // relecture : le refuser au consentement retire le bouton,
                // pas les brouillons.
                'note'         => "Le consentement Microsoft porte sur Mail.ReadWrite et Mail.Send. Mail.ReadWrite "
                                . "est le seul droit qui permette de créer un brouillon, et il couvre aussi — côté "
                                . "API — la modification et la suppression de messages ; MIA n'expose aucun outil "
                                . "de ce genre. Mail.Send ne sert qu'au bouton « Envoyer » de la carte de relecture "
                                . "(après confirmation de l'utilisateur) : un annuaire qui le refuse garde les "
                                . "brouillons et perd seulement ce bouton.",
                'warn'         => self::WARN_MICROSOFT,
                'settings_url' => '/admin/sso',
                'settings_label' => 'Configurer l\'application Azure',
            ],
            [
                'key'          => self::MSCALENDAR,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'microsoft',
                'setting'      => UserOutlookCalendarService::SETTING_ENABLED,
                'label'        => 'Calendrier Outlook',
                'chat_label'   => 'Agenda Outlook',
                'summary'      => "Chaque utilisateur connecte son propre calendrier Outlook : l'assistant "
                                . "consulte ses évènements et en crée après approbation. Aucune invitation "
                                . "n'est envoyée, aucun évènement modifié ni supprimé.",
                'capabilities' => ['Lecture', 'Création approuvée'],
                'icon'         => '<img src="/assets/img/outlook-calendar.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => MicrosoftCalendarOAuth::class,
                'service'      => UserOutlookCalendarService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_MSCALENDAR,
                // Même situation qu'Outlook et que Google Tasks : le seul droit
                // permettant de CRÉER couvre aussi, côté API, la modification et
                // la suppression. Et une différence propre à Microsoft, qui doit
                // être dite : il n'existe aucun équivalent du `sendUpdates=none`
                // de Google, donc MIA ne peut pas créer un évènement AVEC
                // participants sans les inviter — il n'en ajoute donc jamais.
                'note'         => "Le consentement Microsoft porte sur Calendars.ReadWrite : c'est le seul droit "
                                . "qui permette de créer un évènement, et il couvre aussi — côté API — la "
                                . "modification et la suppression. MIA n'expose aucun outil de ce genre : "
                                . "consultation et création approuvée, rien d'autre. Aucune invitation n'est "
                                . "possible non plus : Microsoft les envoie dès la création, donc les évènements "
                                . "sont créés SANS participant (l'utilisateur les ajoute lui-même dans Outlook).",
                'warn'         => self::WARN_MICROSOFT,
                'settings_url' => '/admin/sso',
                'settings_label' => 'Configurer l\'application Azure',
            ],
            [
                'key'          => self::MSTODO,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'microsoft',
                'setting'      => UserMicrosoftTodoService::SETTING_ENABLED,
                'label'        => 'Microsoft To Do',
                'chat_label'   => 'Tâches Microsoft To Do',
                'summary'      => "Chaque utilisateur connecte ses propres listes Microsoft To Do : l'assistant "
                                . "consulte ses tâches, en ajoute et les modifie (échéance, rappel, la marquer "
                                . "terminée), chaque écriture étant soumise à son approbation. Aucune tâche "
                                . "n'est jamais supprimée.",
                'capabilities' => ['Lecture', 'Écriture approuvée'],
                'icon'         => '<img src="/assets/img/microsoft-todo.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => MicrosoftTodoOAuth::class,
                'service'      => UserMicrosoftTodoService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_MSTODO,
                // Même situation que Google Tasks et le calendrier Outlook : le
                // seul droit permettant d'ÉCRIRE (Tasks.ReadWrite) couvre aussi,
                // côté API, la suppression. Contrairement à Google Tasks, MIA
                // n'expose ici AUCUN outil de suppression.
                'note'         => "Le consentement Microsoft porte sur Tasks.ReadWrite : c'est le seul droit qui "
                                . "permette de créer ou de modifier une tâche, et il couvre aussi — côté API — la "
                                . "suppression. MIA n'expose aucun outil de ce genre : consultation, création et "
                                . "modification approuvées, rien d'autre. Les listes partagées par d'autres "
                                . "personnes ne sont pas demandées (Tasks.ReadWrite.Shared).",
                'warn'         => self::WARN_MICROSOFT,
                'settings_url' => '/admin/sso',
                'settings_label' => 'Configurer l\'application Azure',
            ],
            [
                'key'          => self::TEAMS,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'microsoft',
                'setting'      => UserTeamsService::SETTING_ENABLED,
                'label'        => 'Microsoft Teams',
                'chat_label'   => 'Messages Teams',
                'summary'      => "Chaque utilisateur connecte son propre Teams : l'assistant recherche et lit "
                                . "ses conversations et les canaux de ses équipes, et publie en son nom après "
                                . "approbation. Aucun message n'est jamais modifié ni supprimé.",
                'capabilities' => ['Recherche', 'Lecture', 'Publication approuvée'],
                'icon'         => '<img src="/assets/img/microsoft-teams.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => MicrosoftTeamsOAuth::class,
                'service'      => UserTeamsService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_TEAMS,
                // Deux choses à savoir AVANT d'ouvrir ce connecteur : la lecture
                // des canaux exige le consentement d'un ADMINISTRATEUR de
                // l'annuaire (Entra refuse sinon toute la connexion), et la
                // publication est immédiate, comme sur Slack.
                'note'         => "Consentement ADMINISTRATEUR obligatoire : la lecture des canaux "
                                . "(ChannelMessage.Read.All) ne peut être accordée que par un administrateur de "
                                . "l'annuaire Microsoft, pour toute l'organisation (Microsoft Entra → Applications "
                                . "d'entreprise → Autorisations → Accorder un consentement administrateur). Tant "
                                . "qu'il ne l'a pas fait, personne ne peut relier Teams. Comme sur Slack, un "
                                . "message approuvé part aussitôt et tout le monde le voit ; chaque publication "
                                . "passe par une carte d'approbation qui le dit. MIA ne modifie ni ne supprime "
                                . "aucun message, et les comptes Microsoft personnels n'ont pas d'API Teams.",
                'warn'         => self::WARN_MICROSOFT,
                'settings_url' => '/admin/sso',
                'settings_label' => 'Configurer l\'application Azure',
            ],
            [
                'key'          => self::SLACK,
                'surface'      => self::SURFACE_CHAT,
                'family'       => 'slack',
                'setting'      => UserSlackService::SETTING_ENABLED,
                'label'        => 'Slack',
                'chat_label'   => 'Messages Slack',
                'summary'      => "Chaque utilisateur connecte son propre Slack : l'assistant recherche et lit "
                                . "les messages qu'il peut déjà voir, et publie en son nom après approbation. "
                                . "Aucun message n'est jamais modifié ni supprimé.",
                'capabilities' => ['Recherche', 'Lecture', 'Publication approuvée'],
                'icon'         => '<img src="/assets/img/slack.svg" width="21" height="21" alt="">',
                'needs_oauth'  => true,
                'oauth'        => SlackMessagesOAuth::class,
                'service'      => UserSlackService::class,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_SLACK,
                // Deux choses qu'un administrateur doit savoir AVANT d'ouvrir ce
                // connecteur, et qu'aucun autre ne partage : la publication est
                // immédiate (là où Gmail et Outlook s'arrêtent au brouillon), et
                // le seul droit d'écriture de Slack couvre aussi, côté API, la
                // modification et la suppression des messages de la personne.
                'note'         => "Écriture IMMÉDIATEMENT VISIBLE (comme Teams) : là où Gmail et "
                                . "Outlook s'arrêtent au brouillon, un message Slack approuvé part dans le canal "
                                . "et tout le monde le voit. Chaque publication passe par une carte d'approbation "
                                . "qui le dit en toutes lettres. Le consentement porte sur chat:write, qui couvre "
                                . "aussi — côté API — la modification et la suppression des messages de "
                                . "l'utilisateur : MIA n'expose aucun outil de ce genre. Les jetons sont "
                                . "personnels : l'assistant ne voit que les conversations dont l'utilisateur est "
                                . "déjà membre.",
                'warn'         => self::WARN_SLACK,
                'settings_url' => '/admin/connectors/slack',
                'settings_label' => 'Configurer l\'application Slack',
            ],
            [
                // OneDrive n'est PAS un connecteur du chat : il sert à bâtir
                // une base de connaissances (miroir d'un dossier). Il vit
                // pourtant dans ce catalogue parce que l'administrateur doit
                // pouvoir décider, ici comme pour les autres, s'il apparaît ou
                // non sur la page Connexions des utilisateurs. Sa surface le
                // tient hors du menu « + » et hors des expressions.
                'key'          => self::ONEDRIVE,
                'surface'      => self::SURFACE_KB,
                'family'       => 'microsoft',
                'setting'      => self::SETTING_ONEDRIVE,
                'label'        => 'OneDrive',
                'chat_label'   => '',
                'summary'      => "Chaque gestionnaire relie son propre OneDrive pour qu'une base de "
                                . "connaissances reflète un dossier : les fichiers y sont lus et "
                                . "re-synchronisés. Jamais exposé au chat, jamais d'écriture.",
                'capabilities' => ['Lecture', 'Source de bases'],
                'icon'         => '<img src="/assets/img/onedrive.svg" width="22" height="18" alt="">',
                'needs_oauth'  => true,
                // OAuth Microsoft (application Azure), pas le socle Google.
                'oauth'        => MicrosoftOAuth::class,
                // Pas de service à interrupteur : UserOneDriveService n'est
                // appelé que par la synchronisation des bases, jamais par le
                // chat — la clé Settings vit donc dans ce registre.
                'service'      => null,
                'needs_link'   => true,
                'token'        => UserDriveToken::PROVIDER_ONEDRIVE,
                'note'         => "Réservé aux administrateurs et créateurs (ceux qui gèrent des bases de "
                                . "connaissances). Masquer ce connecteur retire la carte de leur page "
                                . "Comptes connectés ; une base déjà synchronisée continue de l'être.",
                'warn'         => "Application Azure absente — renseignez celle de la connexion Microsoft "
                                . "(Organisation → Authentification), ou une inscription dédiée dans "
                                . "Capacités → Sources synchronisées. Même autorisé, ce connecteur restera "
                                . "indisponible tant qu'elle manque.",
                'settings_url' => '/admin/knowledge-bases/sync',
                'settings_label' => 'Configurer l\'application Azure',
            ],
        ];
    }

    /**
     * Clés des connecteurs du CHAT, dans l'ordre du menu « + ». C'est le
     * périmètre historique : menu, expressions déclencheuses, état par
     * utilisateur. Un connecteur d'une autre surface (OneDrive) n'y figure
     * pas — il n'a rien à faire dans une conversation.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_column(self::chatCatalog(), 'key');
    }

    /**
     * TOUTES les clés, chat et hors chat : le périmètre de la page
     * d'administration et de l'enregistrement de son formulaire. Itérer sur
     * `keys()` pour enregistrer éteindrait tout connecteur hors chat à chaque
     * enregistrement complet (case absente du POST = décochée).
     *
     * @return array<int, string>
     */
    public static function allKeys(): array
    {
        return array_column(self::catalog(), 'key');
    }

    /**
     * Descripteurs des seuls connecteurs du chat.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function chatCatalog(): array
    {
        return array_values(array_filter(
            self::catalog(),
            static fn(array $c): bool => (string)$c['surface'] === self::SURFACE_CHAT
        ));
    }

    /** Le connecteur se manifeste-t-il dans une conversation ? */
    public static function isChatConnector(string $key): bool
    {
        return (string)(self::row($key)['surface'] ?? '') === self::SURFACE_CHAT;
    }

    /** Descripteur d'un connecteur, ou null si la clé est inconnue. */
    public static function row(string $key): ?array
    {
        foreach (self::catalog() as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        return null;
    }

    /** Clé Settings d'un connecteur, ou null si la clé est inconnue. */
    public static function settingKey(string $key): ?string
    {
        $row = self::row($key);
        return $row !== null ? (string)$row['setting'] : null;
    }

    /** Libellé FR d'un connecteur (« Google Drive »…), '' si inconnu. */
    public static function label(string $key): string
    {
        return (string)(self::row($key)['label'] ?? '');
    }

    /**
     * Libellé EXACT de la ligne du menu « + » du composer (« Fichiers Google
     * Drive »…), '' si le connecteur n'y figure pas.
     *
     * C'est ce libellé — et pas le nom du produit — qu'il faut citer à
     * l'utilisateur pour qu'il trouve l'interrupteur : « active Google Drive »
     * ne désigne rien à l'écran.
     */
    public static function chatLabel(string $key): string
    {
        $label = (string)(self::row($key)['chat_label'] ?? '');

        // Traduit ICI, à l'accesseur — le catalogue est une constante de
        // structure, il ne peut pas appeler t(). La clé est le libellé
        // français, donc EXACTEMENT celle que le gabarit du chat passe déjà à
        // t() pour la même ligne du menu « + » : une seule traduction, aucun
        // risque de citer un libellé que l'écran n'affiche pas. La page
        // d'administration passe par ici aussi (withState()).
        return $label !== '' ? t($label) : '';
    }

    /**
     * Connecteurs PRÊTS (autorisés par l'admin, configurés, compte connecté)
     * mais dont l'interrupteur du menu « + » est resté éteint pour ce message.
     *
     * Sans eux, l'assistant est aveugle ET muet : l'outil n'est pas exposé,
     * aucune note ne part au system prompt (celle de `runSend` ne couvre que
     * « demandé mais indisponible »), et le modèle répond « je n'ai pas accès à
     * ton Drive » sans dire quoi faire. Le seul filet était `tool-suggest.js`,
     * côté navigateur, pendant la frappe — donc rien du tout dès que la
     * tournure de phrase ne correspond à aucune expression déclencheuse.
     *
     * @param array<string,bool> $asked interrupteurs reçus du composer
     * @return list<string> libellés des lignes du menu « + », dans l'ordre du catalogue
     */
    public static function dormantChatConnectors(int $userId, array $asked): array
    {
        $etat = self::chatFeatureState($userId);
        $out  = [];
        foreach ($etat as $key => $st) {
            if (!empty($asked[$key]) || empty($st['connected'])) {
                continue;
            }
            $label = self::chatLabel($key);
            if ($label !== '') {
                $out[] = $label;
            }
        }
        return $out;
    }

    /**
     * Note système décrivant ces connecteurs dormants. PURE (testable sans
     * base). Vide si la liste est vide → zéro token pour qui n'a rien connecté.
     *
     * La consigne borne volontairement l'usage : sans le « seulement si »,
     * l'assistant proposerait d'activer Gmail à chaque question de cuisine.
     *
     * @param list<string> $chatLabels
     */
    public static function dormantConnectorsNote(array $chatLabels): string
    {
        $chatLabels = array_values(array_filter(array_map('trim', $chatLabels), static fn(string $l): bool => $l !== ''));
        if ($chatLabels === []) {
            return '';
        }
        // Guillemets portés PAR chaque libellé (I18n::listeCitee) : ouvrir le
        // premier et fermer le dernier depuis la phrase laisserait un
        // séparateur français au milieu d'une phrase anglaise.
        $liste = I18n::listeCitee($chatLabels);

        return "\n\n" . I18n::plural(
            count($chatLabels),
            "Note : l'utilisateur a connecté {liste}, mais cet interrupteur est ÉTEINT pour ce message — tu n'as donc pas accès à ces données maintenant, et tu ne dois rien en inventer.",
            "Note : l'utilisateur a connecté {liste}, mais ces interrupteurs sont ÉTEINTS pour ce message — tu n'as donc accès à AUCUNE de ces données maintenant, et tu ne dois rien en inventer.",
            ['liste' => $liste]
        ) . ' ' . t("Si, et SEULEMENT si, sa demande en avait besoin : dis-lui que tu ne peux pas y accéder tant que l'interrupteur est éteint, puis indique le geste exact — ouvrir le menu « + » sous le champ de saisie, déplier « Connecteurs », activer {premier} (ou celui qui convient) et renvoyer sa demande. Propose au passage ce que tu PEUX faire sans cet accès (répondre de mémoire, travailler sur un fichier qu'il joint au message). Si sa demande n'a rien à voir, ne mentionne pas cette note.", ['premier' => I18n::listeCitee([$chatLabels[0]])]);
    }

    /**
     * Permissions DÉLÉGUÉES demandées par les connecteurs d'une famille, par
     * libellé de connecteur — lues aux classes OAuth, jamais recopiées.
     *
     * Sert à l'écran de configuration : les connecteurs Microsoft réutilisent
     * l'application Azure du LOGIN, or un annuaire Entra qui interdit le
     * consentement utilisateur exige que ces permissions soient DÉCLARÉES sur
     * l'inscription pour qu'un administrateur puisse les accorder. Sans cette
     * liste, l'administrateur configurait une application « qui ne demande
     * aucune permission » et les connecteurs échouaient ensuite en AADSTS65001,
     * sans que rien ne relie les deux.
     *
     * `offline_access` et `User.Read` sont écartés : le premier n'apparaît pas
     * dans l'écran des autorisations d'API, le second est accordé d'office à
     * toute inscription.
     *
     * SEULS les connecteurs qui réutilisent l'application du LOGIN sont
     * retenus — ceux dont la classe OAuth dérive du socle « par utilisateur »
     * de leur famille, plus OneDrive QUAND il n'a pas d'inscription dédiée
     * (MicrosoftOAuth::usesLoginApp()). Avec son inscription dédiée, l'afficher
     * ici ferait déclarer `Files.Read.All` sur la mauvaise application.
     *
     * @return array<string, list<string>>
     */
    public static function delegatedScopes(string $family): array
    {
        $socles  = ['google' => GoogleUserOAuth::class, 'microsoft' => MicrosoftUserOAuth::class];
        $socle   = $socles[$family] ?? null;
        $ignores = ['offline_access', 'User.Read', 'openid', 'profile', 'email'];
        $out     = [];
        foreach (self::catalog() as $row) {
            if ($socle === null || ($row['family'] ?? null) !== $family) {
                continue;
            }
            $oauth = is_string($row['oauth'] ?? null) ? $row['oauth'] : '';
            $loginApp = $oauth !== '' && (is_subclass_of($oauth, $socle)
                || ($oauth === MicrosoftOAuth::class && self::oneDriveSharesLoginApp()));
            if (!$loginApp || !defined($oauth . '::SCOPES')) {
                continue;
            }
            $scopes = array_values(array_diff((array)constant($oauth . '::SCOPES'), $ignores));
            if ($scopes !== []) {
                $out[(string)$row['label']] = $scopes;
            }
        }
        return $out;
    }

    /**
     * OneDrive passe-t-il par l'application du login ? Lecture d'un réglage
     * en échec = non : la liste des permissions est une aide d'écran, elle ne
     * doit pas faire tomber la page qui l'affiche.
     */
    private static function oneDriveSharesLoginApp(): bool
    {
        try {
            return MicrosoftOAuth::usesLoginApp();
        } catch (\Throwable $e) {
            error_log('[Connecteurs] application OneDrive indéterminée : ' . $e->getMessage());
            return false;
        }
    }

    /** Classe OAuth du connecteur (socle Google OU Microsoft), null si aucune (web, inconnu). */
    public static function oauthClass(string $key): ?string
    {
        $oauth = self::row($key)['oauth'] ?? null;
        return is_string($oauth) && $oauth !== '' ? $oauth : null;
    }

    /** Classe de service du connecteur (socle Google OU Microsoft), null si aucune. */
    public static function serviceClass(string $key): ?string
    {
        $service = self::row($key)['service'] ?? null;
        return is_string($service) && $service !== '' ? $service : null;
    }

    /** Provider des jetons (user_drive_tokens) du connecteur, null si aucun. */
    public static function tokenProvider(string $key): ?string
    {
        $token = self::row($key)['token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Le connecteur est-il autorisé pour tous les utilisateurs ?
     * Défaut '1' : une installation existante ne perd aucun connecteur.
     */
    public static function isEnabled(string $key): bool
    {
        $setting = self::settingKey($key);
        return $setting !== null && Settings::get($setting, '1') === '1';
    }

    /** L'administrateur permet-il le mode « Laisser MIA choisir » ? */
    public static function isAutoModeEnabled(): bool
    {
        return Settings::get(self::SETTING_AUTO, '1') === '1';
    }

    public static function setAutoModeEnabled(bool $enabled): void
    {
        Settings::set(self::SETTING_AUTO, $enabled ? '1' : '0');
    }

    /**
     * Connecteurs du chat ACTIVABLES par le modèle pour ce message : reliés
     * (autorisés, configurés, compte connecté) et dont l'interrupteur est
     * éteint — un connecteur allumé est déjà chargé. Ordre du catalogue.
     *
     * @param array<string, bool> $asked état des interrupteurs du message
     * @return array<string, string> clé → libellé (nom de produit)
     */
    public static function autoLoadableConnectors(int $userId, array $asked): array
    {
        $out = [];
        foreach (self::chatFeatureState($userId) as $key => $st) {
            if (!empty($asked[$key]) || empty($st['connected'])) {
                continue;
            }
            $out[$key] = self::label($key);
        }
        return $out;
    }

    public static function setEnabled(string $key, bool $enabled): void
    {
        $setting = self::settingKey($key);
        if ($setting !== null) {
            Settings::set($setting, $enabled ? '1' : '0');
        }
    }

    /**
     * Le prérequis technique du connecteur est-il en place ? Lu depuis le
     * catalogue : un connecteur sans classe OAuth (web) n'a aucun prérequis,
     * les personnels délèguent à leur classe OAuth — c'est elle qui sait où
     * vivent les identifiants de son fournisseur.
     */
    public static function isConfigured(string $key): bool
    {
        $row = self::row($key);
        if ($row === null) {
            return false;
        }
        $oauth = self::oauthClass($key);
        return $oauth === null || $oauth::isConfigured();
    }

    /**
     * Le connecteur est-il PROPOSÉ aux utilisateurs ? Deux conditions
     * indissociables : l'administrateur l'a laissé visible, et son prérequis
     * technique est en place. Source unique de la visibilité côté utilisateur
     * — menu « + » du chat comme cartes de la page Connexions. Un connecteur
     * non proposé ne doit apparaître nulle part : une option morte fait
     * connecter un compte qui ne servira à rien.
     */
    public static function isProposed(string $key): bool
    {
        return self::isEnabled($key) && self::isConfigured($key);
    }

    /**
     * État des connecteurs PERSONNELS pour le chat, en une passe : la
     * fonctionnalité est-elle offerte (prérequis technique + autorisation
     * admin), et le compte de CET utilisateur est-il relié ? Source unique de
     * l'accueil, de la conversation et du menu « + » — quatre paires de
     * booléens recopiées ailleurs finiraient par diverger.
     *
     * @return array<string, array{feature: bool, connected: bool}>
     */
    public static function chatFeatureState(int $userId): array
    {
        $out = [];
        foreach (self::chatCatalog() as $c) {
            if (empty($c['needs_link'])) {
                continue;
            }
            $key     = (string)$c['key'];
            $feature = self::isProposed($key);
            $out[$key] = [
                'feature'   => $feature,
                'connected' => $feature && UserDriveToken::isConnected($userId, (string)$c['token']),
            ];
        }
        return $out;
    }

    /**
     * État par connecteur PERSONNEL pour la page Connexions : le connecteur
     * est-il proposé, l'utilisateur a-t-il un jeton, et la carte doit-elle
     * être rendue ?
     *
     * La carte survit à un masquage administrateur **quand un jeton existe** :
     * sans elle, l'utilisateur n'aurait plus aucun moyen de retirer un accès
     * qu'il a lui-même accordé à Google (le jeton, lui, resterait en base).
     * Elle est alors en lecture seule — déconnexion possible, reconnexion non.
     *
     * `$allowed` : droit « Connecteurs — Utiliser » du rôle (usableBy). Sans
     * lui rien n'est « proposé » à cette personne : seules les cartes de ses
     * comptes déjà reliés restent, en lecture seule (déconnexion possible).
     *
     * @param string[] $keys clés à évaluer (défaut : tous les connecteurs à connexion)
     * @return array<string, array{proposed: bool, linked: bool, visible: bool}>
     */
    public static function connectionCardState(int $userId, array $keys = [], bool $allowed = true): array
    {
        if ($keys === []) {
            $keys = array_column(
                array_filter(self::catalog(), static fn(array $c): bool => !empty($c['needs_link'])),
                'key'
            );
        }
        $out = [];
        foreach ($keys as $key) {
            $provider = self::tokenProvider((string)$key);
            $linked   = $provider !== null && UserDriveToken::forUser($userId, $provider) !== null;
            $proposed = $allowed && self::isProposed((string)$key);
            $out[(string)$key] = [
                'proposed' => $proposed,
                'linked'   => $linked,
                'visible'  => $proposed || $linked,
            ];
        }
        return $out;
    }

    // ================= Connexion expirée : rappel de reconnexion =================

    /**
     * Ce compte relie-t-il ses comptes personnels et s'en sert-il dans la
     * conversation ? Droit « Connecteurs — Utiliser » de son rôle
     * (Personnes → Rôles). SOURCE UNIQUE du chat (menu « + », envoi,
     * « Laisser MIA choisir ») et de la page Connexions.
     */
    public static function usableBy(?array $user): bool
    {
        return Role::userCan($user, 'connectors.use');
    }

    /**
     * Rôles pour qui OneDrive a un usage : il ne sert qu'à bâtir une base de
     * connaissances (cf. la carte de la page Connexions, masquée aux autres) —
     * qui relie ses comptes ET crée ou modifie des bases.
     */
    public static function oneDriveUsefulFor(string $role): bool
    {
        return Role::grants($role, 'connectors.use')
            && (Role::grants($role, 'knowledge_bases.create') || Role::grants($role, 'knowledge_bases.edit'));
    }

    /**
     * Connecteurs dont la connexion de CET utilisateur a été RÉVOQUÉE par son
     * fournisseur et qu'il peut rétablir — dans l'ordre du catalogue.
     *
     * « Révoqué » est exactement le cas « il l'était avant » : la ligne de
     * jeton n'existe que si la personne a relié son compte un jour, et son
     * statut ne bascule que lorsque le fournisseur refuse le jeton
     * (invalid_grant, 401 persistant — cf. ConnectorApiService). Jusqu'ici
     * rien ne le disait hors de la page Connexions : la ligne du menu « + »
     * se grisait comme pour un compte jamais relié, l'assistant répondait
     * « je n'ai pas accès », et la personne ignorait qu'un clic suffirait.
     * D'où le rappel : pastille sur l'avatar du compte, mention dans le menu
     * du compte et sur le lien « Connexions » des paramètres, ligne du
     * menu « + » qui nomme la cause et mène droit à la carte.
     *
     * Une révocation n'est retenue que si le connecteur est encore PROPOSÉ
     * (autorisé par l'administrateur, prérequis en place) : masqué, il ne
     * peut plus être reconnecté — la page Connexions ne propose alors que le
     * retrait du jeton, et un rappel qu'on ne peut pas éteindre n'est qu'une
     * nuisance. OneDrive n'est retenu que pour qui gère des bases.
     *
     * UNE requête, sans déchiffrement, et best-effort : un rappel ne fait
     * jamais tomber une page.
     *
     * @return list<string> clés de connecteur
     */
    public static function reconnectNeeded(int $userId, string $role = 'user'): array
    {
        try {
            $revoked = UserDriveToken::revokedProvidersForUser($userId);
        } catch (\Throwable $e) {
            error_log('[connecteurs] rappel de reconnexion non calculé : ' . $e->getMessage());
            return [];
        }
        if ($revoked === []) {
            return [];
        }
        return self::keysToReconnect($revoked, $role, [self::class, 'isProposed']);
    }

    /**
     * Forme PURE de reconnectNeeded : providers révoqués → clés à reconnecter.
     * Testable sans base, le prédicat de visibilité étant injecté.
     *
     * @param list<string>          $revokedProviders providers de `user_drive_tokens` au statut `revoked`
     * @param callable(string):bool $isProposed       le connecteur est-il encore proposé ?
     * @return list<string> clés, dans l'ordre du catalogue, sans doublon
     */
    public static function keysToReconnect(array $revokedProviders, string $role, callable $isProposed): array
    {
        $revoked = array_flip(array_map('strval', $revokedProviders));
        $out     = [];
        foreach (self::catalog() as $c) {
            $token = $c['token'] ?? null;
            if (empty($c['needs_link']) || !is_string($token) || !isset($revoked[$token])) {
                continue;
            }
            $key = (string)$c['key'];
            if ($key === self::ONEDRIVE) {
                if (!self::oneDriveUsefulFor($role)) {
                    continue;
                }
            } elseif (!Role::grants($role, 'connectors.use')) {
                // Plus le droit de relier ses comptes : rien à reconnecter.
                continue;
            }
            if (!$isProposed($key)) {
                continue;
            }
            $out[] = $key;
        }
        return $out;
    }

    /**
     * Phrase complète du rappel, pour les infobulles : « Connexion à
     * reconnecter : Gmail », « Connexions à reconnecter : Google Drive,
     * Gmail ». Vide si rien à reconnecter. PURE.
     *
     * Les noms sont ceux des produits, pas des libellés cités : une simple
     * virgule les sépare, sans guillemets.
     *
     * @param list<string> $keys
     */
    public static function reconnectSummary(array $keys): string
    {
        $labels = self::reconnectLabels($keys);
        if ($labels === []) {
            return '';
        }
        return I18n::plural(
            count($labels),
            'Connexion à reconnecter : {liste}',
            'Connexions à reconnecter : {liste}',
            ['liste' => implode(', ', $labels)]
        );
    }

    /**
     * Texte COURT du rappel, pour un menu : le nom du service s'il n'y en a
     * qu'un (« Gmail à reconnecter »), le nombre sinon (« 2 à reconnecter »).
     * Vide si rien à reconnecter. PURE.
     *
     * @param list<string> $keys
     */
    public static function reconnectBadgeText(array $keys): string
    {
        $labels = self::reconnectLabels($keys);
        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return t('{service} à reconnecter', ['service' => $labels[0]]);
        }
        return t('{n} à reconnecter', ['n' => count($labels)]);
    }

    /**
     * Noms de produit traduits des connecteurs demandés (« Google Drive »,
     * « Gmail »…), clés inconnues ignorées.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private static function reconnectLabels(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $label = self::label((string)$key);
            if ($label !== '') {
                $out[] = t($label);
            }
        }
        return $out;
    }

    /**
     * Descripteurs enrichis de l'état courant, pour la page d'administration :
     * autorisation, prérequis technique, nombre de comptes reliés, expressions
     * déclencheuses de la suggestion automatique. Les textes affichés (nom,
     * résumé, capacités, notes) y sont TRADUITS — jamais ceux de catalog().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function withState(): array
    {
        $rows = [];
        foreach (self::catalog() as $c) {
            $key = (string)$c['key'];
            $c['enabled']         = self::isEnabled($key);
            $c['configured']      = self::isConfigured($key);
            $c['connected']       = $c['token'] !== null
                ? UserDriveToken::countConnected((string)$c['token'])
                : null;
            $c['triggers']        = self::triggers($key);
            $c['triggers_custom'] = self::triggersAreCustom($key);
            // Hors chat : aucune frappe à surveiller, donc pas d'éditeur
            // d'expressions (une zone vide et inopérante ferait croire à un
            // réglage possible).
            $c['has_triggers']    = self::isChatConnector($key);
            // Ce que la page AFFICHE est traduit ICI, à l'accesseur — le
            // catalogue est une structure statique, et ses `label` /
            // `chat_label` bruts restent français pour les traces et les
            // prompts qui les citent. Clé = le texte français du catalogue ;
            // le libellé du menu « + » passe par chatLabel(), la même clé que
            // le gabarit du chat.
            $c['label']          = t((string)$c['label']);
            $c['chat_label']     = self::chatLabel($key);
            $c['summary']        = t((string)$c['summary']);
            $c['capabilities']   = array_map(static fn ($cap): string => t((string)$cap), (array)$c['capabilities']);
            $c['note']           = t((string)$c['note']);
            $c['warn']           = t((string)$c['warn']);
            $c['settings_label'] = t((string)$c['settings_label']);
            $rows[] = $c;
        }
        return $rows;
    }

    // ================= Expressions déclencheuses (suggestion automatique) =================

    /**
     * Liste par défaut d'un connecteur — la détection d'intention du chat EST
     * cette liste (tool-suggest.js n'a plus de motif connecteur en dur).
     * Règle de curation, à préserver en l'éditant : jamais un mot générique
     * seul — la marque (« google drive », « gmail », « n8n »), un possessif
     * (« mon agenda », « mes mails ») ou un verbe explicite (« lance le
     * workflow »), sinon « l'agenda de la réunion » ou « le document ci-joint »
     * déclencheraient à tort.
     *
     * @return array<int, string>
     */
    public static function defaultTriggers(string $key): array
    {
        return match ($key) {
            self::WEB => [
                'cherche sur internet', 'recherche sur internet', 'cherche en ligne', 'recherche en ligne',
                'sur le web', 'va sur internet', 'actualités', 'dernières nouvelles', 'dernières infos',
                'quoi de neuf', 'météo', 'prix actuel', 'cours actuel', 'taux actuel', 'en temps réel',
                'résultat du match',
            ],
            self::DRIVE => [
                'google drive', 'google doc', 'google docs', 'google sheet', 'google sheets', 'google slides',
                'docs.google.com', 'drive.google.com', 'mon drive', 'dans le drive',
                'mes fichiers', 'mon fichier', 'mes documents', 'mon document', 'mon tableur', 'mes tableurs',
                'feuille de calcul', 'ma présentation', 'mes présentations', 'mon dossier', 'mes dossiers',
            ],
            self::GMAIL => [
                'gmail', 'boîte mail', 'boîte de réception', 'ma messagerie',
                'mes mails', 'mon mail', 'mes emails', 'mon email', 'mes e-mails', 'mes courriels',
                'dernier mail', 'derniers mails', 'dernier email',
                'réponds à ce mail', 'réponds à cet email', 'rédige un mail', 'rédige un email',
                'écris un mail', 'écris un email', 'envoie un mail', 'envoie un email',
                'brouillon de mail', "brouillon d'email",
            ],
            self::CALENDAR => [
                'google agenda', 'google calendar', 'mon agenda', 'dans mon agenda', 'mon calendrier',
                'mon planning', 'mes rendez-vous', 'mon rendez-vous', 'mes réunions', 'ma réunion',
                'mes créneaux', 'mes disponibilités', 'suis-je libre', 'suis-je disponible',
                'ai-je une réunion', 'ai-je un rendez-vous', 'quelles réunions', 'combien de réunions',
                'ajoute un rendez-vous', 'bloque un créneau', 'planifie une réunion', 'cale une réunion',
            ],
            self::TASKS => [
                'google tasks', 'google task', 'tasks.google.com',
                'mes tâches', 'ma tâche', 'ma liste de tâches', 'mes listes de tâches',
                'ma todo', 'ma to-do', 'todo list', 'to-do list', 'liste de choses à faire',
                'mon pense-bête', 'mes rappels',
                'ajoute une tâche', 'ajouter une tâche', 'crée une tâche', 'nouvelle tâche',
                'coche la tâche', 'marque la tâche', 'termine la tâche', 'supprime la tâche',
                'à faire cette semaine', 'ce qu\'il me reste à faire',
            ],
            // Marque Microsoft + les MÊMES possessifs génériques que Gmail :
            // « mes mails » ne dit pas quel fournisseur. `detectIntents()`
            // renvoie toutes les règles qui matchent et `evaluate()` retient la
            // première réellement disponible — une organisation qui n'a
            // qu'Outlook reçoit donc la bonne suggestion, sans que la règle
            // Gmail (déclarée avant) ait à disparaître.
            self::OUTLOOK => [
                'outlook', 'outlook.com', 'outlook.office.com',
                'boîte mail', 'boîte de réception', 'ma messagerie',
                'mes mails', 'mon mail', 'mes emails', 'mon email', 'mes e-mails', 'mes courriels',
                'dernier mail', 'derniers mails', 'dernier email',
                'réponds à ce mail', 'réponds à cet email', 'rédige un mail', 'rédige un email',
                'écris un mail', 'écris un email', 'envoie un mail', 'envoie un email',
                'brouillon de mail', "brouillon d'email",
            ],
            // Marque Microsoft + les MÊMES possessifs que l'agenda Google :
            // « mon agenda » ne dit pas quel fournisseur. La règle Outlook est
            // déclarée APRÈS celle de Google dans tool-suggest.js, et
            // `evaluate()` retient la première réellement disponible — une
            // organisation qui n'a que Microsoft reçoit donc la bonne
            // suggestion sans que la règle Google ait à disparaître.
            self::MSCALENDAR => [
                'calendrier outlook', 'agenda outlook', 'outlook calendar',
                'mon agenda', 'dans mon agenda', 'mon calendrier',
                'mon planning', 'mes rendez-vous', 'mon rendez-vous', 'mes réunions', 'ma réunion',
                'mes créneaux', 'mes disponibilités', 'suis-je libre', 'suis-je disponible',
                'ai-je une réunion', 'ai-je un rendez-vous', 'quelles réunions', 'combien de réunions',
                'ajoute un rendez-vous', 'bloque un créneau', 'planifie une réunion', 'cale une réunion',
            ],
            // Marque Microsoft + les MÊMES possessifs que Google Tasks : « mes
            // tâches » ne dit pas quel fournisseur. `evaluate()` retient la
            // première règle réellement disponible (cf. Outlook, plus haut).
            self::MSTODO => [
                'microsoft to do', 'microsoft todo', 'ms to do', 'to do de microsoft', 'to-do.office.com',
                'mes tâches', 'ma tâche', 'ma liste de tâches', 'mes listes de tâches',
                'ma todo', 'ma to-do', 'todo list', 'to-do list', 'liste de choses à faire',
                'mon pense-bête', 'mes rappels',
                'ajoute une tâche', 'ajouter une tâche', 'crée une tâche', 'nouvelle tâche',
                'coche la tâche', 'marque la tâche', 'termine la tâche',
                'à faire cette semaine', 'ce qu\'il me reste à faire',
            ],
            // Marque seulement : « teams » seul est un mot anglais courant (« our
            // teams »), « mes canaux » et « mes messages » désignent aussi Slack.
            self::TEAMS => [
                'microsoft teams', 'ms teams', 'sur teams', 'dans teams', 'via teams', 'teams.microsoft.com',
                'message teams', 'messages teams', 'mes messages teams',
                'conversation teams', 'mes conversations teams', 'discussion teams', 'mes discussions teams',
                'canal teams', 'mes canaux teams', 'équipe teams', 'mes équipes teams', 'fil teams',
                'poste sur teams', 'publie sur teams', 'envoie sur teams', 'écris sur teams',
                'réponds sur teams', 'préviens l\'équipe sur teams', 'qu\'est-ce qui s\'est dit sur teams',
            ],
            // Marque en tête, puis les tournures qui ne désignent QUE Slack.
            // « canal » seul est écarté : un canal de distribution, un canal de
            // diffusion et un canal d'irrigation n'ont rien à voir avec Slack.
            self::SLACK => [
                'slack', 'sur slack', 'dans slack', 'slack.com',
                'mes canaux', 'mes canaux slack', 'canal slack', 'fil slack',
                'message slack', 'messages slack', 'mes messages slack',
                'poste sur slack', 'publie sur slack', 'envoie sur slack',
                'préviens l\'équipe sur slack', 'annonce sur slack',
                'qu\'est-ce qui s\'est dit sur slack', 'réponds sur slack',
            ],
            default => [],
        };
    }

    /**
     * Saisie libre (une expression par ligne, virgule et point-virgule
     * tolérés) → liste propre : espaces normalisés, minuscules, doublons
     * retirés (accents ignorés — « métèo » et « meteo » sont le même
     * déclencheur), une expression < 2 caractères sur-déclencherait.
     * PURE — testable sans base.
     *
     * @return array<int, string>
     */
    public static function sanitizeTriggerList(string $raw): array
    {
        $out  = [];
        $seen = [];
        foreach (preg_split('/[\r\n,;]+/u', $raw) ?: [] as $phrase) {
            $phrase = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $phrase);
            $phrase = trim((string)preg_replace('/\s+/u', ' ', $phrase));
            $phrase = mb_strtolower(mb_substr($phrase, 0, self::MAX_TRIGGER_LEN));
            if (mb_strlen($phrase) < 2) {
                continue;
            }
            $fold = self::foldForCompare($phrase);
            if (isset($seen[$fold])) {
                continue;
            }
            $seen[$fold] = true;
            $out[]       = $phrase;
            if (count($out) >= self::MAX_TRIGGERS) {
                break;
            }
        }
        return $out;
    }

    /** Expressions actives d'un connecteur : personnalisées, sinon défauts. */
    public static function triggers(string $key): array
    {
        if (!in_array($key, self::keys(), true)) {
            return [];
        }
        $stored = Settings::get(self::TRIGGERS_PREFIX . $key, null);
        // null = jamais personnalisé (ou revenu au défaut) ; '' = tout retiré.
        if ($stored === null) {
            return self::defaultTriggers($key);
        }
        return self::sanitizeTriggerList((string)$stored);
    }

    /** L'administrateur a-t-il remplacé la liste par défaut ? */
    public static function triggersAreCustom(string $key): bool
    {
        return Settings::get(self::TRIGGERS_PREFIX . $key, null) !== null;
    }

    /**
     * Enregistre la saisie de l'admin et renvoie la liste retenue.
     * Saisir exactement les défauts efface la personnalisation (la liste
     * suivra les améliorations futures des défauts).
     */
    public static function setTriggers(string $key, string $raw): array
    {
        if (!in_array($key, self::keys(), true)) {
            return [];
        }
        $list = self::sanitizeTriggerList($raw);
        Settings::set(
            self::TRIGGERS_PREFIX . $key,
            $list === self::defaultTriggers($key) ? null : implode("\n", $list)
        );
        return $list;
    }

    /** Retour explicite aux défauts (bouton « Réinitialiser »). */
    public static function resetTriggers(string $key): array
    {
        if (in_array($key, self::keys(), true)) {
            Settings::set(self::TRIGGERS_PREFIX . $key, null);
        }
        return self::defaultTriggers($key);
    }

    /**
     * Toutes les listes actives, pour l'injection côté chat
     * (`window.CONNECTOR_TRIGGERS`, lu par tool-suggest.js).
     *
     * @return array<string, array<int, string>>
     */
    public static function allTriggers(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::triggers($key);
        }
        return $out;
    }

    /** Minuscules sans accents — clé de comparaison des doublons. */
    private static function foldForCompare(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', '’' => "'", 'ʼ' => "'",
        ]);
    }
}
