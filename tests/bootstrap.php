<?php

declare(strict_types=1);

/**
 * Bootstrap des tests PHPUnit.
 *
 * - Force un environnement de test (DB dédiée myllm_test) AVANT le chargement de
 *   config/app.php : ce dernier ne pose une variable que si elle n'est pas déjà
 *   définie, donc nos putenv() ont priorité sur un éventuel .env local.
 * - Reproduit le chargement « require manuel » de public/index.php (l'app n'a pas
 *   d'autoloader) pour le sous-ensemble de classes nécessaire aux tests, sans
 *   tirer les contrôleurs ni les helpers qui dépendent de vendor/ (dompdf, phpword).
 *
 * Variables surchargeables par l'environnement (utilisées en CI) :
 *   TEST_DB_HOST / TEST_DB_PORT / TEST_DB_NAME / TEST_DB_USER / TEST_DB_PASS
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ROOT', dirname(__DIR__));

$testEnv = [
    'APP_ENV'        => 'testing',
    'APP_SECRET'     => str_repeat('a', 64),
    'ENCRYPTION_KEY' => str_repeat('b', 64),
    'DB_HOST'        => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'DB_PORT'        => getenv('TEST_DB_PORT') ?: '3306',
    'DB_NAME'        => getenv('TEST_DB_NAME') ?: 'myllm_test',
    'DB_USER'        => getenv('TEST_DB_USER') ?: 'root',
    'DB_PASS'        => getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : '',
];
foreach ($testEnv as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key]    = $value;
    $_SERVER[$key] = $value;
}

require ROOT . '/config/app.php';

// Sous-ensemble de classes sans dépendance vendor/ ni héritage croisé.
$classes = [
    // I18n en premier : il déclare les fonctions globales t()/te()/tj(), que
    // les classes chargées ensuite peuvent appeler. Même raison que sa place
    // en tête de config/classes.php — les deux listes doivent rester alignées.
    '/src/Helpers/I18n.php',
    '/src/Router.php',
    '/src/Models/Database.php',
    '/src/Models/Settings.php',
    '/src/Models/User.php',
    // Rôles et droits : catalogue pur, puis le modèle (même ordre que config/classes.php).
    '/src/Auth/Permissions.php',
    '/src/Models/Role.php',
    '/src/Models/UserIdentity.php',
    '/src/Models/Profile.php',
    '/src/Helpers/TextChunker.php',
    '/src/Models/ProfileDocument.php',
    '/src/Models/KnowledgeBase.php',
    '/src/Models/KnowledgeBaseDocument.php',
    '/src/Models/ConversationKnowledgeBase.php',
    '/src/Models/ConversationSummary.php',
    '/src/Models/KnowledgeBaseRemote.php',
    '/src/Models/ApiKey.php',
    // Instances de modèles activées : LLMFactory::available() les lit (défaut
    // effectif de l'instance, liste blanche du groupe « ★ Par défaut »).
    '/src/Models/LlmModel.php',
    '/src/Helpers/Encryption.php',
    '/src/RAG/SemanticChunker.php',
    '/src/Models/UserGroup.php',
    // Outils de génération : portée par groupe (écran Outils) et accès du chat.
    '/src/Models/ChatTool.php',
    '/src/Models/AssistantAvatar.php',
    '/src/Models/UsageLog.php',
    '/src/Models/CreditService.php',
    '/src/Helpers/CreditQuota.php',
    // Grand livre des opérations IA : taxonomie puis compteur (qui lit les deux précédents).
    '/src/Billing/UsageSource.php',
    '/src/Billing/UsageMeter.php',
    // Conversation vocale : formes pures (texte lisible, découpage, durée MP3) et réglage.
    '/src/Helpers/SpeechSynthesisException.php',
    '/src/Helpers/SpeechSynthesizer.php',
    // Réglages de voix par utilisateur (vitesse de lecture, longueur des réponses).
    '/src/Helpers/VoicePreferences.php',
    // Réglages d'affichage par utilisateur (thème, taille d'affichage).
    '/src/Helpers/DisplayPreferences.php',
    // Budget IA mensuel (lu par CreditQuota).
    '/src/Billing/UsagePeriod.php',
    '/src/Billing/AiBudget.php',
    '/src/Billing/UsageInsights.php',
    '/src/Billing/PersonalUsage.php',
    '/src/Billing/CreditEstimate.php',
    // Catalogue d'embeddings : CreditService y lit les tarifs curés (aucune dépendance vendor/).
    '/src/RAG/EmbeddingService.php',
    '/src/Models/Conversation.php',
    '/src/Models/Message.php',
    '/src/Models/CreationShare.php',
    // Tâches programmées (Scheduler instancie ChatController au moment T :
    // les tests qui l'exécutent chargent le contrôleur eux-mêmes).
    '/src/Schedule/Recurrence.php',
    '/src/Models/ScheduledTask.php',
    '/src/Models/ScheduledTaskRun.php',
    '/src/Schedule/Scheduler.php',
    '/src/Push/WebPush.php',
    '/src/Models/PushSubscription.php',
    '/src/Schedule/TaskNotifier.php',
    '/src/Schedule/TaskDiagram.php',
    '/src/Schedule/InstructionExamples.php',
    '/src/Workflow/WorkflowSpec.php',
    '/src/Workflow/WorkflowValues.php',
    '/src/Workflow/WorkflowFilters.php',
    '/src/Workflow/ArtifactContract.php',
    '/src/Workflow/WorkflowLoop.php',
    '/src/Workflow/LoopSources.php',
    '/src/Workflow/LoopFiles.php',
    '/src/Workflow/LoopRunner.php',
    '/src/Models/Workflow.php',
    '/src/Models/WorkflowRun.php',
    '/src/Workflow/WorkflowPrompt.php',
    '/src/Workflow/WorkflowAi.php',
    '/src/Workflow/WorkflowNotifier.php',
    '/src/Workflow/WorkflowFiles.php',
    '/src/Workflow/WorkflowDeliveries.php',
    '/src/Workflow/WorkflowCreations.php',
    '/src/Workflow/WorkflowTriggers.php',
    '/src/Workflow/WorkflowEngine.php',
    '/src/Workflow/WorkflowCatalog.php',
    '/src/Workflow/WorkflowCover.php',
    '/src/Workflow/WorkflowFit.php',
    '/src/Workflow/WorkflowJournal.php',
    '/src/Workflow/WorkflowContentView.php',
    '/src/Workflow/WorkflowInserts.php',
    '/src/Workflow/WorkflowTemplates.php',
    '/src/Workflow/WorkflowDraft.php',
    '/src/Helpers/UserPreferences.php',
    '/src/Helpers/ChoiceButtons.php',
    '/src/Helpers/ComposerHints.php',
    '/src/Helpers/FollowUpSuggestion.php',
    '/src/Helpers/CompanyProfile.php',
    '/src/Helpers/CompanyVocabulary.php',
    '/src/Models/UserMemory.php',
    '/src/Helpers/FileExtractor.php',
    '/src/Helpers/WebPageReader.php',
    // Photo de profil : fichier choisi ou adresse collée (téléchargée par WebPageReader).
    '/src/Helpers/AvatarStorage.php',
    '/src/Helpers/Format.php',
    // Onglets entre pages sœurs de l'administration (menu + partial).
    '/src/Helpers/AdminTabs.php',
    // Points à régler de l'administration : formes pures, testables sans base.
    '/src/Helpers/AdminAttention.php',
    '/src/Helpers/AdminMenu.php',
    '/src/Helpers/NavShell.php',
    '/src/Helpers/AdminAccess.php',
    '/src/Helpers/NavRegistry.php',
    // Administrateurs à prévenir (encadré d'erreur du chat).
    '/src/Helpers/AdminContacts.php',
    '/src/Helpers/RouteThumbnail.php',
    // Élagage : StorageInfo puis MediaFile (garde de confinement) puis le
    // service, qui appelle les deux. Aucun n'a de dépendance vendor/.
    '/src/Helpers/StorageInfo.php',
    '/src/Helpers/MediaFile.php',
    '/src/Helpers/MediaUrl.php',
    '/src/Helpers/ReferenceImages.php',
    '/src/Maintenance/PruningService.php',
    '/src/LLM/LlmCatalog.php',
    // Adapters LLM : chargés pour les tests de contrat (signatures, échelle de
    // dégradation de la recherche web). Aucun n'appelle vendor/.
    '/src/LLM/LLMInterface.php',
    '/src/LLM/LlmRetryableException.php',
    '/src/LLM/LlmErrorPresenter.php',
    // Incidents de compte chez un fournisseur (crédit épuisé, clé refusée).
    '/src/LLM/ProviderIncident.php',
    '/src/LLM/ToolUseUnsupportedTrait.php',
    '/src/LLM/ChatCompletionsToolLoopTrait.php',
    '/src/LLM/LlmAdapterHelpers.php',
    '/src/LLM/OpenAIAdapter.php',
    '/src/LLM/MistralAdapter.php',
    '/src/LLM/AnthropicAdapter.php',
    '/src/LLM/PerplexityAdapter.php',
    '/src/LLM/GeminiAdapter.php',
    '/src/LLM/DeepSeekAdapter.php',
    '/src/LLM/MoonshotAdapter.php',
    '/src/LLM/ZaiAdapter.php',
    '/src/LLM/LocalAdapter.php',
    '/src/LLM/LLMFactory.php',
    '/src/Auth/Session.php',
    '/src/Security/Csrf.php',
    '/src/Security/LoginRateLimit.php',
    '/src/Auth/Sso/SsoProvider.php',
    '/src/Auth/Sso/SsoRegistry.php',
    '/src/Auth/GoogleOAuth.php',
    '/src/Sync/RemoteFolderProvider.php',
    '/src/Sync/BrowsableFolderProvider.php',
    '/src/Sync/GoogleDriveProvider.php',
    '/src/Sync/SharePointProvider.php',
    // Après SharePointProvider : MicrosoftSsoProvider lui délègue la traduction
    // des codes AADSTS plutôt que de la recopier.
    '/src/Auth/Sso/GoogleSsoProvider.php',
    '/src/Auth/Sso/MicrosoftSsoProvider.php',
    '/src/Sync/GoogleCalendarProvider.php',
    '/src/Sync/OneDriveProvider.php',
    '/src/Sync/KbSync.php',
    '/src/Sync/KbStatus.php',
    '/src/Sync/KbDiagram.php',
    '/src/MyBrain/MyBrainException.php',
    '/src/MyBrain/MyBrainClient.php',
    '/src/MyBrain/MyBrainContext.php',
    '/src/MyBrain/MyBrainFeed.php',
    '/src/RAG/QdrantStore.php',
    '/src/RAG/QdrantHealth.php',
    // Socles des connecteurs Google AVANT leurs sous-classes déclaratives.
    '/src/Auth/GoogleUserOAuth.php',
    '/src/Auth/GoogleDriveOAuth.php',
    '/src/Models/UserDriveToken.php',
    '/src/Models/DrivePendingAction.php',
    '/src/Auth/GoogleGmailOAuth.php',
    '/src/Auth/GoogleCalendarOAuth.php',
    '/src/Auth/GoogleTasksOAuth.php',
    '/src/Auth/MicrosoftOAuth.php',
    // Socle des OAuth Microsoft par utilisateur (après MicrosoftOAuth, dont il
    // réutilise la traduction AADSTS) AVANT ses sous-classes.
    '/src/Auth/MicrosoftUserOAuth.php',
    '/src/Auth/MicrosoftOutlookOAuth.php',
    '/src/Auth/MicrosoftCalendarOAuth.php',
    '/src/Auth/MicrosoftTodoOAuth.php',
    '/src/Auth/MicrosoftTeamsOAuth.php',
    // Famille Slack : SlackApp (identifiants) avant le socle, le socle avant
    // sa sous-classe.
    '/src/Slack/SlackApp.php',
    '/src/Auth/SlackUserOAuth.php',
    '/src/Auth/SlackMessagesOAuth.php',
    // Plomberie commune, puis le socle de chaque fournisseur, puis les services.
    '/src/Connectors/ParallelHttp.php',
    '/src/Connectors/ConnectorApiService.php',
    '/src/Connectors/GoogleUserApiService.php',
    '/src/Connectors/MicrosoftUserApiService.php',
    '/src/Connectors/SlackUserApiService.php',
    '/src/Drive/DocsFormat.php',
    '/src/Drive/SheetsFormat.php',
    '/src/Drive/UserDriveService.php',
    '/src/Drive/UserOneDriveService.php',
    '/src/Gmail/UserGmailService.php',
    '/src/Calendar/UserCalendarService.php',
    '/src/Calendar/FreeSlots.php',
    '/src/Models/UserConnectorPrefs.php',
    '/src/Tasks/UserTasksService.php',
    '/src/Outlook/UserOutlookService.php',
    // Après UserOutlookService et UserCalendarService : statiques pures partagées.
    '/src/Outlook/UserOutlookCalendarService.php',
    // Microsoft To Do : après le calendrier Outlook (statiques partagées).
    '/src/Todo/UserMicrosoftTodoService.php',
    // Microsoft Teams : mêmes statiques partagées.
    '/src/Teams/UserTeamsService.php',
    // Slack : la traduction mrkdwn ↔ Markdown précède le service, qui s'en sert
    // dans les deux sens (lecture et publication).
    '/src/Slack/SlackMrkdwn.php',
    '/src/Slack/UserSlackService.php',
    '/src/N8n/N8nService.php',
    '/src/Gamma/GammaService.php',
    '/src/Connectors/ConnectorRegistry.php',
    '/src/Connectors/ConnectorContext.php',
    '/src/Connectors/MailHtml.php',
    // Relecture d'un brouillon d'email (carte mail) : après MailHtml, qu'elle appelle.
    '/src/Connectors/MailDraftReview.php',
    '/src/Connectors/MailAttachments.php',
    '/src/Connectors/OutgoingAttachments.php',
    // Registre d'outils du chat : interface et socle AVANT les fournisseurs.
    '/src/Agent/SearchScope.php',
    '/src/Agent/AgentTurn.php',
    '/src/Agent/ToolProvider.php',
    '/src/Agent/AbstractToolProvider.php',
    '/src/Agent/ToolRegistry.php',
    '/src/Agent/AgentGuidance.php',
    '/src/Agent/ExternalContent.php',
    '/src/Agent/ActionJournal.php',
    '/src/Agent/Provenance.php',
    '/src/Agent/TextPager.php',
    '/src/Agent/ConversationSearch.php',
    '/src/Agent/ContextWindow.php',
    '/src/Agent/ResultCondenser.php',
    '/src/Agent/AgentTelemetry.php',
    // Journal serveur : formes pures (lecture, classement, analyse, mise en forme).
    '/src/Logs/LogCatalog.php',
    '/src/Logs/LogParser.php',
    '/src/Logs/LogFile.php',
    '/src/Logs/LogQuery.php',
    '/src/Logs/LogAnalysis.php',
    '/src/Logs/LogView.php',
    '/src/Agent/Tools/ImageTools.php',
    '/src/Agent/Tools/DriveTools.php',
    '/src/Agent/Tools/GmailTools.php',
    '/src/Agent/Tools/OutlookTools.php',
    '/src/Agent/Tools/GoogleCalendarTools.php',
    '/src/Agent/Tools/OutlookCalendarTools.php',
    '/src/Agent/Tools/SlackTools.php',
    '/src/Agent/Tools/TasksTools.php',
    '/src/Agent/Tools/MicrosoftTodoTools.php',
    '/src/Agent/Tools/TeamsTools.php',
    '/src/Agent/Tools/N8nTools.php',
    '/src/Agent/Tools/GammaTools.php',
    '/src/Agent/Tools/MyBrainTools.php',
    '/src/Agent/Tools/PlanTools.php',
    '/src/Agent/Tools/ConnectorLoaderTools.php',
    '/src/Agent/Tools/WebPageTools.php',
    '/src/Agent/Tools/ScheduleTools.php',
    '/src/Agent/Tools/AssistantTools.php',
    '/src/Agent/Tools/WorkflowTools.php',
    '/src/Agent/Tools/ConversationTools.php',
    '/src/Agent/Tools/IntentTools.php',
    '/src/Changelog/Nouveautes.php',
    // Catalogue des outils : CreditService y lit les tarifs publics image/vidéo.
    '/src/Tools/ToolCatalog.php',
    '/src/Tools/ToolTriggers.php',
    '/src/Tools/ToolPrompts.php',
    '/src/Assistants/PromptStarters.php',
    '/src/Assistants/AssistantDiagram.php',
    '/src/Assistants/AssistantDraft.php',
    '/src/Jobs/JobQueue.php',
    '/src/Helpers/ImageBytes.php',
    '/src/Helpers/GeneratedHtml.php',
    '/src/Jobs/JobHandler.php',
    '/src/Jobs/HtmlPageJob.php',
    '/src/Jobs/NativeDeckJob.php',
    '/src/Jobs/WorkflowJob.php',
    '/src/Backup/BackupService.php',
    '/src/Slides/DeckSpec.php',
    '/src/Slides/LayoutEngine.php',
    '/src/Slides/DeckGenerator.php',
    '/src/Slides/DeckRenderer.php',
    '/src/Slides/SlideImage.php',
    '/src/Slides/PptxTemplateException.php',
    '/src/Slides/PptxTemplate.php',
    // Fichiers livrés dans une conversation. Tout le module se charge sans
    // vendor/ : les fabricants Excel et Word ne touchent à leur bibliothèque
    // qu'à l'exécution (et la disent absente proprement sinon).
    '/src/Files/FileDelivery.php',
    '/src/Files/FileFormats.php',
    '/src/Files/FileNaming.php',
    '/src/Files/FileBuildException.php',
    '/src/Files/GeneratedFile.php',
    '/src/Files/FileSource.php',
    '/src/Files/FileScratch.php',
    '/src/Files/FileLibrary.php',
    '/src/Files/TabularData.php',
    '/src/Files/SpreadsheetCell.php',
    '/src/Files/SpreadsheetFormula.php',
    '/src/Files/MarkdownDocument.php',
    '/src/Files/MarkdownHtml.php',
    '/src/Files/MarkdownPlain.php',
    '/src/Files/MarkdownRtf.php',
    '/src/Files/MarkdownDeck.php',
    '/src/Files/FileBuilder.php',
    '/src/Files/TextFileBuilder.php',
    '/src/Files/CsvFileBuilder.php',
    '/src/Files/XlsxFileBuilder.php',
    '/src/Files/OfficeTemplateException.php',
    '/src/Files/OfficePackage.php',
    '/src/Files/DocxTemplate.php',
    '/src/Files/CompanyDocx.php',
    '/src/Files/DocxFileBuilder.php',
    '/src/Files/RtfFileBuilder.php',
    '/src/Files/PdfFileBuilder.php',
    '/src/Files/PptxFileBuilder.php',
    '/src/Files/FileFactory.php',
    '/src/Files/NativeDeck.php',
    '/src/Files/NativeDeckStore.php',
];
foreach ($classes as $relative) {
    require ROOT . $relative;
}

// Les incidents de compte fournisseur sont consignés en MÉMOIRE pendant les
// tests : des tests unitaires présentent des « crédit épuisé » à la chaîne, et
// la table `settings` de la base de test ne doit pas en garder la trace (un
// test des points à régler la lirait). Les tests qui éprouvent la persistance
// rendent la table explicitement (ProviderIncident::useSettingsStore()).
ProviderIncident::useMemoryStore();

require ROOT . '/tests/Support/DatabaseTestCase.php';
require ROOT . '/tests/Support/RagFixtures.php';
require ROOT . '/tests/Support/AgentSource.php';
