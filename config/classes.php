<?php

declare(strict_types=1);

/**
 * Chargement des classes — SOURCE UNIQUE.
 *
 * Il n'y a pas d'autoloader : chaque classe doit être `require`d explicitement.
 * Ce fichier est inclus par `public/index.php` ET par `scripts/_cli_bootstrap.php`
 * (workers). Deux listes séparées dérivaient : une classe ajoutée côté web et
 * oubliée côté CLI faisait planter le worker en production seulement.
 *
 * Ordre significatif : interfaces et traits avant leurs implémentations.
 * `ROOT` doit être défini et l'autoload Composer déjà chargé.
 */

// Socle : aucune dépendance, chargé en premier. ErrorHandler peut déjà avoir été
// requis par le point d'entrée (web ou CLI) — require_once, donc sans effet.
require_once ROOT . '/src/Helpers/ErrorHandler.php';
require ROOT . '/src/Helpers/Requirements.php';
// I18n : socle sans dépendance (ni base, ni session — la locale est POSÉE
// plus tard, par public/index.php ou par JobQueue). Chargé ici parce que les
// fonctions globales t()/te()/tj() qu'il déclare peuvent être appelées par
// n'importe quelle classe chargée ensuite. L'oublier ne casse que le worker,
// et seulement en production : c'est le mode de panne décrit plus haut.
require ROOT . '/src/Helpers/I18n.php';

require ROOT . '/src/Router.php';
require ROOT . '/src/Auth/Session.php';
// Juste après Session : AjaxResponse s'en sert pour la branche « flash + redirect ».
require ROOT . '/src/Http/AjaxResponse.php';
require ROOT . '/src/Auth/AuthMiddleware.php';
require ROOT . '/src/Models/Database.php';
// Après Database : Bootstrap sonde la connexion et rejoue ses exceptions.
require ROOT . '/src/Helpers/Bootstrap.php';
require ROOT . '/src/Models/User.php';
// Rôles et droits : le catalogue (pur) puis le modèle qui lit les rôles.
// Session::can() et AuthMiddleware::requirePermission() s'en servent.
require ROOT . '/src/Auth/Permissions.php';
require ROOT . '/src/Models/Role.php';
require ROOT . '/src/Models/UserIdentity.php';
require ROOT . '/src/Models/Conversation.php';
require ROOT . '/src/Models/Message.php';
require ROOT . '/src/Models/Profile.php';
require ROOT . '/src/Models/AssistantAvatar.php';
require ROOT . '/src/Models/ApiKey.php';
require ROOT . '/src/Models/LlmModel.php';
require ROOT . '/src/Models/UsageLog.php';
require ROOT . '/src/Models/UserMemory.php';
require ROOT . '/src/Helpers/TextChunker.php';
require ROOT . '/src/Helpers/BackgroundJob.php';
require ROOT . '/src/Models/ProfileDocument.php';
require ROOT . '/src/Models/KnowledgeBase.php';
require ROOT . '/src/Models/KnowledgeBaseDocument.php';
require ROOT . '/src/Models/ConversationKnowledgeBase.php';
require ROOT . '/src/Models/ConversationSummary.php';
require ROOT . '/src/Models/KnowledgeBaseRemote.php';
require ROOT . '/src/Models/Settings.php';
require ROOT . '/src/Changelog/Nouveautes.php';
require ROOT . '/src/Models/DefaultAssistant.php';
require ROOT . '/src/Models/ChatTool.php';
require ROOT . '/src/Models/ToolTestRun.php';
require ROOT . '/src/Models/UserGroup.php';
require ROOT . '/src/Models/UserDriveToken.php';
require ROOT . '/src/Models/UserConnectorPrefs.php';
require ROOT . '/src/Models/DrivePendingAction.php';
require ROOT . '/src/Models/CreditService.php';
// Grand livre des opérations IA : taxonomie puis compteur unique (lit UsageLog + CreditService).
require ROOT . '/src/Billing/UsageSource.php';
require ROOT . '/src/Billing/UsageMeter.php';
// Budget IA mensuel de l'organisation (lu par CreditQuota, la barrière commune).
require ROOT . '/src/Billing/UsagePeriod.php';
require ROOT . '/src/Billing/AiBudget.php';
require ROOT . '/src/Billing/UsageInsights.php';
require ROOT . '/src/Billing/PersonalUsage.php';
// Estimations avant usage : tarifs de CreditService appliqués à la consommation réelle.
require ROOT . '/src/Billing/CreditEstimate.php';
require ROOT . '/src/Models/CreationShare.php';
// Tâches programmées : récurrence pure, modèles, planificateur (cf. Scheduler).
require ROOT . '/src/Schedule/Recurrence.php';
require ROOT . '/src/Models/ScheduledTask.php';
require ROOT . '/src/Models/ScheduledTaskRun.php';
require ROOT . '/src/Schedule/Scheduler.php';
// Notifications sur les appareils (Web Push) : protocole, appareils inscrits,
// et ce qu'une tâche programmée en dit.
require ROOT . '/src/Push/WebPush.php';
require ROOT . '/src/Models/PushSubscription.php';
require ROOT . '/src/Schedule/TaskNotifier.php';
// Schéma d'une tâche (données ; le rendu vit dans diagram.js, le modèle dans schedule-diagram.js).
require ROOT . '/src/Schedule/TaskDiagram.php';
// Exemples de consigne du formulaire d'une tâche (source unique, partagée avec le guide).
require ROOT . '/src/Schedule/InstructionExamples.php';
// Workflows : définition (pure), valeurs et règles, contrat des pièces,
// modèles, consignes d'étape, appels courts, notifications, moteur.
require ROOT . '/src/Workflow/WorkflowSpec.php';
require ROOT . '/src/Workflow/WorkflowValues.php';
require ROOT . '/src/Workflow/WorkflowFilters.php';
require ROOT . '/src/Workflow/ArtifactContract.php';
require ROOT . '/src/Workflow/WorkflowLoop.php';
// Boucles « pour chaque » : découpeurs (pur), lecture des fichiers, découpage à l'exécution.
require ROOT . '/src/Workflow/LoopSources.php';
require ROOT . '/src/Workflow/LoopFiles.php';
require ROOT . '/src/Workflow/LoopRunner.php';
require ROOT . '/src/Models/Workflow.php';
require ROOT . '/src/Models/WorkflowRun.php';
require ROOT . '/src/Workflow/WorkflowPrompt.php';
require ROOT . '/src/Workflow/WorkflowAi.php';
require ROOT . '/src/Workflow/WorkflowNotifier.php';
require ROOT . '/src/Workflow/WorkflowFiles.php';
require ROOT . '/src/Workflow/WorkflowDeliveries.php';
// Brique « Création » : image, page web, carte par un outil du chat.
require ROOT . '/src/Workflow/WorkflowCreations.php';
require ROOT . '/src/Workflow/WorkflowTriggers.php';
require ROOT . '/src/Workflow/WorkflowEngine.php';
require ROOT . '/src/Workflow/WorkflowCatalog.php';
require ROOT . '/src/Workflow/WorkflowCover.php';
require ROOT . '/src/Workflow/WorkflowFit.php';
require ROOT . '/src/Workflow/WorkflowJournal.php';
require ROOT . '/src/Workflow/WorkflowContentView.php';
require ROOT . '/src/Workflow/WorkflowInserts.php';
require ROOT . '/src/Workflow/WorkflowTemplates.php';
require ROOT . '/src/Workflow/WorkflowDraft.php';
require ROOT . '/src/Tools/ToolCatalog.php';
require ROOT . '/src/Tools/ToolPrompts.php';
require ROOT . '/src/Assistants/PromptStarters.php';
// Schéma d'un assistant (données ; le rendu vit dans diagram.js, partagé avec les tâches).
require ROOT . '/src/Assistants/AssistantDiagram.php';
// MIA prépare un assistant (outil `creer_assistant`) : droits, choix permis, carte, création.
require ROOT . '/src/Assistants/AssistantDraft.php';
require ROOT . '/src/Helpers/ImageGenerator.php';
// Images de référence (documents de l'assistant, images des bases) envoyées au modèle image.
require ROOT . '/src/Helpers/ReferenceImages.php';
require ROOT . '/src/Helpers/VideoGenerator.php';
// Conversation vocale : synthèse des réponses (lit Settings ; aucune dépendance vendor/).
require ROOT . '/src/Helpers/SpeechSynthesisException.php';
require ROOT . '/src/Helpers/SpeechSynthesizer.php';
// … et les réglages de voix de l'utilisateur (vitesse de lecture, longueur des
// réponses orales) : lus par le chat, les paramètres et le worker.
require ROOT . '/src/Helpers/VoicePreferences.php';
// Réglages d'affichage de l'utilisateur (thème, taille) : lus par TOUS les
// gabarits pour les attributs de <html>.
require ROOT . '/src/Helpers/DisplayPreferences.php';
require ROOT . '/src/Helpers/Geocoder.php';
// Octets d'une image : type réel, JPEG allégé (pièces jointes).
require ROOT . '/src/Helpers/ImageBytes.php';
require ROOT . '/src/Helpers/GeneratedHtml.php';
require ROOT . '/src/Helpers/PdfExportService.php';
require ROOT . '/src/Helpers/DocxExportService.php';
require ROOT . '/src/Helpers/AvatarStorage.php';
require ROOT . '/src/Helpers/UserPreferences.php';
require ROOT . '/src/Helpers/ChoiceButtons.php';
require ROOT . '/src/Helpers/ComposerHints.php';
require ROOT . '/src/Helpers/FollowUpSuggestion.php';
require ROOT . '/src/Helpers/CompanyProfile.php';
require ROOT . '/src/Helpers/CompanyVocabulary.php';
require ROOT . '/src/Helpers/CreditQuota.php';
require ROOT . '/src/Helpers/HtmlIllustrator.php';
require ROOT . '/src/Helpers/Format.php';
require ROOT . '/src/Helpers/AdminTabs.php';
require ROOT . '/src/Helpers/RouteThumbnail.php';
require ROOT . '/src/Helpers/StorageInfo.php';
// Après StorageInfo, dont l'élagage réutilise la mesure disque, et AVANT tout
// contrôleur : AdminController::cleanupGeneratedAsset délègue à MediaFile.
require ROOT . '/src/Helpers/MediaFile.php';
// URL signées des médias générés (servis sous contrôle d'accès par MediaController).
require ROOT . '/src/Helpers/MediaUrl.php';
// Après MediaFile, Message, Settings, CreationShare et Format : le service les
// appelle tous.
require ROOT . '/src/Maintenance/PruningService.php';
require ROOT . '/src/Helpers/CsvExport.php';
require ROOT . '/src/Helpers/NativeApp.php';
require ROOT . '/src/Slides/DeckSpec.php';
require ROOT . '/src/Slides/LayoutEngine.php';
require ROOT . '/src/Slides/DeckGenerator.php';
require ROOT . '/src/Slides/DeckRenderer.php';
require ROOT . '/src/Slides/SlideImage.php';
require ROOT . '/src/Slides/PptxExporter.php';
require ROOT . '/src/Slides/SlidesPdfExporter.php';
require ROOT . '/src/Slides/PptxTemplateException.php';
require ROOT . '/src/Slides/PptxTemplate.php';
// Fichiers livrés dans une conversation (classeur, document, texte) : la
// convention et le catalogue d'abord, puis les analyses pures, l'interface
// AVANT ses fabricants, et la fabrique en dernier — elle les appelle tous.
require ROOT . '/src/Files/FileDelivery.php';
require ROOT . '/src/Files/FileFormats.php';
require ROOT . '/src/Files/FileNaming.php';
require ROOT . '/src/Files/FileBuildException.php';
require ROOT . '/src/Files/GeneratedFile.php';
require ROOT . '/src/Files/FileSource.php';
require ROOT . '/src/Files/FileScratch.php';
require ROOT . '/src/Files/FileLibrary.php';
require ROOT . '/src/Files/TabularData.php';
require ROOT . '/src/Files/SpreadsheetCell.php';
require ROOT . '/src/Files/SpreadsheetFormula.php';
require ROOT . '/src/Files/MarkdownDocument.php';
require ROOT . '/src/Files/MarkdownHtml.php';
require ROOT . '/src/Files/MarkdownPlain.php';
require ROOT . '/src/Files/MarkdownRtf.php';
require ROOT . '/src/Files/MarkdownDeck.php';
require ROOT . '/src/Files/FileBuilder.php';
require ROOT . '/src/Files/TextFileBuilder.php';
require ROOT . '/src/Files/CsvFileBuilder.php';
require ROOT . '/src/Files/XlsxFileBuilder.php';
require ROOT . '/src/Files/OfficeTemplateException.php';
require ROOT . '/src/Files/OfficePackage.php';
require ROOT . '/src/Files/DocxTemplate.php';
require ROOT . '/src/Files/CompanyDocx.php';
require ROOT . '/src/Files/DocxFileBuilder.php';
require ROOT . '/src/Files/RtfFileBuilder.php';
require ROOT . '/src/Files/PdfFileBuilder.php';
require ROOT . '/src/Files/PptxFileBuilder.php';
require ROOT . '/src/Files/FileFactory.php';
require ROOT . '/src/Files/NativeDeck.php';
require ROOT . '/src/Files/NativeDeckStore.php';
// Générations asynchrones (file de jobs) — interface avant handlers.
require ROOT . '/src/Jobs/JobErrorException.php';
require ROOT . '/src/Jobs/JobContext.php';
require ROOT . '/src/Jobs/JobHandler.php';
require ROOT . '/src/Jobs/HtmlPageJob.php';
// Briques OAuth Google. Elles n'étaient chargées QUE par ricochet, via les
// `require_once` en tête de UserSettingsController — `scripts/worker.php`
// appelle pourtant GoogleDriveOAuth::isConfigured() au démarrage. Le worker ne
// survivait que parce que ce contrôleur se trouve dans la même liste ; retirer
// une ligne sans rapport l'aurait cassé, en production seulement.
// SsoRegistry AVANT elles : les quatre classes lui demandent désormais le
// client OAuth (base, puis repli sur les constantes du .env).
require_once ROOT . '/src/Auth/Sso/SsoProvider.php';
require_once ROOT . '/src/Auth/Sso/SsoRegistry.php';
require_once ROOT . '/src/Auth/GoogleOAuth.php';
require_once ROOT . '/src/Auth/Sso/GoogleSsoProvider.php';
require_once ROOT . '/src/Auth/Sso/MicrosoftSsoProvider.php';
// Socle des OAuth par utilisateur AVANT ses quatre sous-classes déclaratives.
require_once ROOT . '/src/Auth/GoogleUserOAuth.php';
require_once ROOT . '/src/Auth/GoogleDriveOAuth.php';
require_once ROOT . '/src/Auth/GoogleGmailOAuth.php';
require_once ROOT . '/src/Auth/GoogleCalendarOAuth.php';
require_once ROOT . '/src/Auth/GoogleTasksOAuth.php';
require_once ROOT . '/src/Auth/NativeOauthState.php';
require_once ROOT . '/src/Auth/MicrosoftOAuth.php';
// Socle des OAuth Microsoft par utilisateur AVANT ses sous-classes. Il délègue
// à MicrosoftOAuth pour la traduction des codes AADSTS : charger celui-ci avant.
require_once ROOT . '/src/Auth/MicrosoftUserOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftOutlookOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftCalendarOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftTodoOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftTeamsOAuth.php';
// Famille Slack. SlackApp porte les identifiants de l'application : le socle
// OAuth les lui demande, il doit donc précéder — et le socle, sa sous-classe.
require_once ROOT . '/src/Slack/SlackApp.php';
require_once ROOT . '/src/Auth/SlackUserOAuth.php';
require_once ROOT . '/src/Auth/SlackMessagesOAuth.php';

require ROOT . '/src/Helpers/Diagnostics.php';
// Journal serveur (Maintenance → Diagnostic → Journaux) : lecture, analyse, mise en forme.
require ROOT . '/src/Logs/LogCatalog.php';
require ROOT . '/src/Logs/LogParser.php';
require ROOT . '/src/Logs/LogFile.php';
require ROOT . '/src/Logs/LogQuery.php';
require ROOT . '/src/Logs/LogAnalysis.php';
require ROOT . '/src/Logs/LogView.php';
// Points à régler de l'administration (pastilles du menu, menu du compte,
// tableau de bord). Aucune dépendance de chargement : elle n'appelle les
// autres classes qu'à l'exécution.
require ROOT . '/src/Helpers/AdminAttention.php';
require ROOT . '/src/Helpers/AdminMenu.php';
require ROOT . '/src/Helpers/NavShell.php';
// Qui ouvre quelle page de l'administration (menu, bouton Admin, lecture seule).
require ROOT . '/src/Helpers/AdminAccess.php';
// Où mène chaque menu : espace de travail, mon compte, administration, palette.
require ROOT . '/src/Helpers/NavRegistry.php';
// Administrateurs à prévenir depuis l'encadré d'erreur du chat.
require ROOT . '/src/Helpers/AdminContacts.php';
require ROOT . '/src/Controllers/HealthController.php';
require ROOT . '/src/Controllers/MediaController.php';
require ROOT . '/src/Controllers/FileController.php';
require ROOT . '/src/Controllers/AuthController.php';
require ROOT . '/src/Controllers/ChatController.php';
require ROOT . '/src/Controllers/JobController.php';
// Handlers adossés à ChatController : le contrôleur doit être chargé avant.
require ROOT . '/src/Jobs/ChatJob.php';
require ROOT . '/src/Jobs/ImageJob.php';
require ROOT . '/src/Jobs/VideoJob.php';
require ROOT . '/src/Jobs/MapJob.php';
require ROOT . '/src/Jobs/PresentationJob.php';
require ROOT . '/src/Jobs/ChatSendJob.php';
require ROOT . '/src/Jobs/MaintenanceJob.php';
require ROOT . '/src/Jobs/CreditsRefreshJob.php';
require ROOT . '/src/Jobs/GammaGenerationJob.php';
require ROOT . '/src/Jobs/NativeDeckJob.php';
require ROOT . '/src/Jobs/MyBrainFeedJob.php';
require ROOT . '/src/Jobs/WorkflowJob.php';
require ROOT . '/src/Jobs/JobQueue.php';
require ROOT . '/src/Backup/BackupService.php';
require ROOT . '/src/Controllers/AdminController.php';
// Utilisateurs → Rôles : administrateurs seuls (Permissions::ADMIN_ONLY).
require ROOT . '/src/Controllers/RoleController.php';
require ROOT . '/src/Controllers/BackupController.php';
require ROOT . '/src/Controllers/PruningController.php';
require ROOT . '/src/Controllers/AgentStatsController.php';
require ROOT . '/src/Controllers/LogsController.php';
require ROOT . '/src/Controllers/BudgetController.php';
require ROOT . '/src/Controllers/ProfileController.php';
require ROOT . '/src/Controllers/AboutController.php';
require ROOT . '/src/Controllers/UserSettingsController.php';
require ROOT . '/src/Controllers/ScheduledTaskController.php';
require ROOT . '/src/Controllers/WorkflowController.php';
require ROOT . '/src/Controllers/WorkflowHookController.php';
require ROOT . '/src/Controllers/PushController.php';
require ROOT . '/src/Controllers/PwaController.php';
require ROOT . '/src/Controllers/MobileAppController.php';
require ROOT . '/src/Controllers/ShareController.php';
require ROOT . '/src/LLM/LLMInterface.php';
require ROOT . '/src/LLM/LlmRetryableException.php';
require ROOT . '/src/LLM/LlmErrorPresenter.php';
// Incidents de compte chez un fournisseur (crédit épuisé, clé refusée) :
// consignés par le chat et les jobs, lus par les points à régler.
require ROOT . '/src/LLM/ProviderIncident.php';
require ROOT . '/src/LLM/ToolUseUnsupportedTrait.php';
// Boucle d'outils au format Chat Completions (DeepSeek, Kimi, GLM, Mistral,
// LLM local) : déclarée AVANT les adaptateurs qui l'emploient.
require ROOT . '/src/LLM/ChatCompletionsToolLoopTrait.php';
require ROOT . '/src/LLM/LlmAdapterHelpers.php';
require ROOT . '/src/LLM/LlmCatalog.php';
require ROOT . '/src/LLM/OpenAIAdapter.php';
require ROOT . '/src/LLM/MistralAdapter.php';
require ROOT . '/src/LLM/AnthropicAdapter.php';
require ROOT . '/src/LLM/PerplexityAdapter.php';
require ROOT . '/src/LLM/GeminiAdapter.php';
require ROOT . '/src/LLM/DeepSeekAdapter.php';
require ROOT . '/src/LLM/MoonshotAdapter.php';
require ROOT . '/src/LLM/ZaiAdapter.php';
require ROOT . '/src/LLM/LocalAdapter.php';
require ROOT . '/src/LLM/LLMFactory.php';
require ROOT . '/src/RAG/QdrantStore.php';
require ROOT . '/src/RAG/QdrantHealth.php';
require ROOT . '/src/RAG/EmbeddingService.php';
require ROOT . '/src/RAG/SemanticChunker.php';
require ROOT . '/src/RAG/KbVisionDescriber.php';
require ROOT . '/src/Sync/RemoteFolderProvider.php';
require ROOT . '/src/Sync/BrowsableFolderProvider.php';
require ROOT . '/src/Sync/GoogleDriveProvider.php';
require ROOT . '/src/Sync/SharePointProvider.php';
require ROOT . '/src/Sync/GoogleCalendarProvider.php';
require ROOT . '/src/Sync/OneDriveProvider.php';
require ROOT . '/src/Sync/KbSync.php';
require ROOT . '/src/Sync/KbStatus.php';
require ROOT . '/src/Sync/KbDiagram.php';
require ROOT . '/src/MyBrain/MyBrainException.php';
require ROOT . '/src/MyBrain/MyBrainClient.php';
require ROOT . '/src/MyBrain/MyBrainContext.php';
require ROOT . '/src/MyBrain/MyBrainFeed.php';
// Plomberie commune des services de connecteurs, PUIS le socle de chaque
// fournisseur, PUIS leurs sous-classes : pas d'autoloader, l'ordre est le
// chargement.
require ROOT . '/src/Connectors/ParallelHttp.php';
require ROOT . '/src/Connectors/ConnectorApiService.php';
require ROOT . '/src/Connectors/GoogleUserApiService.php';
require ROOT . '/src/Connectors/MicrosoftUserApiService.php';
require ROOT . '/src/Connectors/SlackUserApiService.php';
require ROOT . '/src/Drive/DocsFormat.php';
require ROOT . '/src/Drive/SheetsFormat.php';
require ROOT . '/src/Drive/UserDriveService.php';
require ROOT . '/src/Drive/UserOneDriveService.php';
require ROOT . '/src/Gmail/UserGmailService.php';
require ROOT . '/src/Calendar/UserCalendarService.php';
require ROOT . '/src/Calendar/FreeSlots.php';
require ROOT . '/src/Tasks/UserTasksService.php';
require ROOT . '/src/Outlook/UserOutlookService.php';
// Après UserOutlookService (mise en forme des destinataires, HTML → texte) et
// UserCalendarService (analyse de date partagée) : le calendrier Outlook réutilise
// leurs statiques pures plutôt que d'en faire un second exemplaire.
require ROOT . '/src/Outlook/UserOutlookCalendarService.php';
// Microsoft To Do : après le calendrier Outlook, dont il réutilise la lecture
// des couples {dateTime, timeZone} et la conversion HTML → texte.
require ROOT . '/src/Todo/UserMicrosoftTodoService.php';
// Microsoft Teams : même réutilisation (conversion HTML → texte, comparaison
// sans accents, fuseau validé) ; MailHtml n'est appelé qu'à la publication.
require ROOT . '/src/Teams/UserTeamsService.php';
// Slack : la traduction mrkdwn ↔ Markdown est une statique pure utilisée par le
// service (lecture ET écriture), elle doit précéder.
require ROOT . '/src/Slack/SlackMrkdwn.php';
require ROOT . '/src/Slack/UserSlackService.php';
require ROOT . '/src/N8n/N8nService.php';
require ROOT . '/src/Gamma/GammaService.php';
require ROOT . '/src/Connectors/ConnectorRegistry.php';
require ROOT . '/src/Connectors/ConnectorContext.php';
require ROOT . '/src/Connectors/MailHtml.php';
// Relecture d'un brouillon d'email (carte d'approbation mail) : rend le corps
// par MailHtml, donc après lui.
require ROOT . '/src/Connectors/MailDraftReview.php';
require ROOT . '/src/Connectors/MailAttachments.php';
require ROOT . '/src/Connectors/OutgoingAttachments.php';
// Registre d'outils du chat (src/Agent/) : l'interface et le socle AVANT les
// fournisseurs qui les implémentent — pas d'autoloader, l'ordre est le
// chargement.
require ROOT . '/src/Agent/SearchScope.php';
require ROOT . '/src/Agent/AgentTurn.php';
require ROOT . '/src/Agent/ToolProvider.php';
require ROOT . '/src/Agent/AbstractToolProvider.php';
require ROOT . '/src/Agent/ToolRegistry.php';
require ROOT . '/src/Agent/AgentGuidance.php';
require ROOT . '/src/Agent/ExternalContent.php';
require ROOT . '/src/Agent/ActionJournal.php';
require ROOT . '/src/Agent/Provenance.php';
require ROOT . '/src/Agent/TextPager.php';
require ROOT . '/src/Agent/ConversationSearch.php';
require ROOT . '/src/Agent/ContextWindow.php';
require ROOT . '/src/Agent/ResultCondenser.php';
require ROOT . '/src/Agent/AgentTelemetry.php';
require ROOT . '/src/Agent/Tools/ImageTools.php';
require ROOT . '/src/Agent/Tools/DriveTools.php';
require ROOT . '/src/Agent/Tools/GmailTools.php';
require ROOT . '/src/Agent/Tools/OutlookTools.php';
require ROOT . '/src/Agent/Tools/GoogleCalendarTools.php';
require ROOT . '/src/Agent/Tools/OutlookCalendarTools.php';
require ROOT . '/src/Agent/Tools/SlackTools.php';
require ROOT . '/src/Agent/Tools/TasksTools.php';
require ROOT . '/src/Agent/Tools/MicrosoftTodoTools.php';
require ROOT . '/src/Agent/Tools/TeamsTools.php';
require ROOT . '/src/Agent/Tools/N8nTools.php';
require ROOT . '/src/Agent/Tools/GammaTools.php';
require ROOT . '/src/Agent/Tools/MyBrainTools.php';
require ROOT . '/src/Agent/Tools/PlanTools.php';
require ROOT . '/src/Agent/Tools/ConnectorLoaderTools.php';
require ROOT . '/src/Agent/Tools/WebPageTools.php';
require ROOT . '/src/Agent/Tools/ScheduleTools.php';
require ROOT . '/src/Agent/Tools/AssistantTools.php';
require ROOT . '/src/Agent/Tools/WorkflowTools.php';
require ROOT . '/src/Agent/Tools/ConversationTools.php';
require ROOT . '/src/Agent/Tools/IntentTools.php';
require ROOT . '/src/Tools/ToolTriggers.php';
require ROOT . '/src/Helpers/Encryption.php';
require ROOT . '/src/Helpers/FileExtractor.php';
require ROOT . '/src/Helpers/WebSearch.php';
require ROOT . '/src/Helpers/WebPageReader.php';
require ROOT . '/src/Security/Csrf.php';
require ROOT . '/src/Security/LoginRateLimit.php';
