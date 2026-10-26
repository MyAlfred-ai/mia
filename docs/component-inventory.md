# myLLM — Component Inventory

Inventaire de toutes les classes PHP du projet, classifiées par responsabilité.

---

## 1. Core (1 fichier)

| Classe | Fichier | Rôle |
|---|---|---|
| `Router` | `src/Router.php` | Routeur regex maison, params nommés `{id}`, dispatch sur tuple `[Class, method]` ou closure |

---

## 2. Auth (3 fichiers)

| Classe | Fichier | Rôle |
|---|---|---|
| `AuthMiddleware` | `src/Auth/AuthMiddleware.php` | `requireAuth()`, `requireAdmin()`, `refreshUser()`, détection JSON/SSE pour 401 propre |
| `GoogleOAuth` | `src/Auth/GoogleOAuth.php` | Flow OAuth 2.0 Google (auth URL, callback, exchange code → token → userinfo) |
| `Session` | `src/Auth/Session.php` | Gestion sécurisée du démarrage PHP session, stockage clé/valeur, rôles et utilisateur courant, flash messages |
| `GoogleDriveOAuth` | `src/Auth/GoogleDriveOAuth.php` | OAuth 2.0 Google Drive par utilisateur : URL d’auth “drive”, échange code→tokens, refresh/révoque l’accès, stocke scopes et email, gestion reconnexion/consentement écriture |

---

## 3. Controllers (7 fichiers)

| Classe | Fichier | Routes gérées |
|---|---|---|
| `AuthController` | `src/Controllers/AuthController.php` | Contrôleur d’authentification gérant login (OAuth Google, email/mot de passe, admin local), logout, callback OAuth, statut de session et rate-limiting |
| `ChatController` | `src/Controllers/ChatController.php` | Contrôleur principal des chats utilisateurs : création, affichage, envoi et régénération de messages (textes, images, HTML, vidéos), orchestration appels LLM, outils IA, fichiers, gestion des conversations et export. |
| `AdminController` | `src/Controllers/AdminController.php` | Contrôleur d'administration centralisé : gestion complète des utilisateurs, groupes, assistants, bases de connaissances (RAG/Synced), outils de génération, clés API, logs, crédits, settings, pricing, dashboards et exports. |
| `ProfileController` | `src/Controllers/ProfileController.php` | `/profiles` (lecture liste publique) |
| `UserSettingsController` | `src/Controllers/UserSettingsController.php` | Contrôleur gestion des paramètres utilisateur : affichage et mise à jour du profil, préférences IA, usage, connexions Google Drive, changement de mot de passe |
| `AboutController` | `src/Controllers/AboutController.php` | `/a-propos` |
| `SetupController` | `src/Controllers/SetupController.php` | `/setup` (wizard `.env`), méthode statique `isNeeded()` |

---

## 4. Models (15 fichiers — couche données)

Toutes ces classes sont **statiques** (pas d'instance, méthodes statiques uniquement) et accèdent à `Database::getInstance()`.

| Classe | Fichier | Rôle |
|---|---|---|
| `Database` | `src/Models/Database.php` | Singleton PDO + migrations auto au boot |
| `User` | `src/Models/User.php` | CRUD users, rôles, quota, recherche par Google ID / email, `effectiveCreditQuotaK()` |
| `UserGroup` | `src/Models/UserGroup.php` | Groupes + membres + quota hérité, `effectiveQuotaForUserId()` |
| `Conversation` | `src/Models/Conversation.php` | CRUD conversations isolées par `user_id`, `belongsToUser()` |
| `Message` | `src/Models/Message.php` | CRUD messages de conversation avec fichiers, suivi LLM, édition/effacement, recherche par conversation ou image assistant |
| `Profile` | `src/Models/Profile.php` | Assistants : prompt, accès, RAG, `accessibleByUser()` |
| `ProfileDocument` | `src/Models/ProfileDocument.php` | Documents de référence (texte extrait + tokens) |
| `ApiKey` | `src/Models/ApiKey.php` | Clés API LLM chiffrées AES-256, lookup par provider |
| `LlmModel` | `src/Models/LlmModel.php` | Override admin du catalogue LLM (`active()` retourne instances activées) |
| `UsageLog` | `src/Models/UsageLog.php` | Journalisation et agrégats détaillés de la consommation (crédits, tokens, coût, requêtes) LLM et outils par utilisateur, conversation, profil et message |
| `Settings` | `src/Models/Settings.php` | Settings globaux clé/valeur, cache mémoire process, `theme()`, `accentColor()` |
| `ChatTool` | `src/Models/ChatTool.php` | Outils image/vidéo/HTML, `activeByUser()` filtré rôle + groupe |
| `ToolTestRun` | `src/Models/ToolTestRun.php` | Résultats tests admin batch outils |
| `CreditService` | `src/Models/CreditService.php` | ⭐ Calcul crédits : `globalConfig()`, `computeLlmCredits()`, `computeToolCredits()` |
| `DefaultAssistant` | `src/Models/DefaultAssistant.php` | Assistant système d'aide global, synchronisé automatiquement avec les guides docs/ dans une base de connaissances RAG vectorielle, pour répondre aux questions d’utilisation sur MIA |
| `KnowledgeBase` | `src/Models/KnowledgeBase.php` | Gestion CRUD, partage, droits et synchronisation des bases de connaissances (collections vectorielles liées aux profils) entre MySQL et Qdrant |
| `KnowledgeBaseDocument` | `src/Models/KnowledgeBaseDocument.php` | Gestion des documents d’une base de connaissances avec extraction, découpage en chunks, vectorisation (embeddings/Qdrant), statuts d’ingestion et fallback lexical FULLTEXT |
| `UserDriveToken` | `src/Models/UserDriveToken.php` | Stockage chiffré (AES) et gestion du refresh/access token Google Drive utilisateur, avec statut révocation et mise à jour post-OAuth |

---

## 5. LLM Adapters (10 fichiers)

| Classe | Fichier | Implémente | Description |
|---|---|---|---|
| `LLMInterface` | `src/LLM/LLMInterface.php` | — | Contrat `stream()` + `getProviderName()` |
| `LLMFactory` | `src/LLM/LLMFactory.php` | — | `make("provider::model")`, `available()`, `models()`, `defaultModel()`, `logos()` |
| `LlmAdapterHelpers` | `src/LLM/LlmAdapterHelpers.php` | — | `encodeBody`, `extractHttpStatus`, message d'erreur standard |
| `LlmCatalog` | `src/LLM/LlmCatalog.php` | — | Lecture `config/llm_catalog.php`, `all()`, `defaultModel($provider)` |
| `OpenAIAdapter` | `src/LLM/OpenAIAdapter.php` | `LLMInterface` | Chat Completions OU Responses API selon `routing` |
| `AnthropicAdapter` | `src/LLM/AnthropicAdapter.php` | `LLMInterface` | Messages API + version header `anthropic-version` |
| `MistralAdapter` | `src/LLM/MistralAdapter.php` | `LLMInterface` | Conversations API + fallback `web_search_premium → web_search` |
| `GeminiAdapter` | `src/LLM/GeminiAdapter.php` | `LLMInterface` | generateContent + recherche web native |
| `PerplexityAdapter` | `src/LLM/PerplexityAdapter.php` | `LLMInterface` | sonar-* models, web natif |
| `DeepSeekAdapter` | `src/LLM/DeepSeekAdapter.php` | `LLMInterface` | OpenAI-compat |
| `MoonshotAdapter` | `src/LLM/MoonshotAdapter.php` | `LLMInterface` | OpenAI-compat (Kimi, vision base64) |
| `ZaiAdapter` | `src/LLM/ZaiAdapter.php` | `LLMInterface` | OpenAI-compat (GLM, texte uniquement) |
| `LocalAdapter` | `src/LLM/LocalAdapter.php` | `LLMInterface` | OpenAI-compat avec custom `base_url` (Ollama, vLLM, etc.) |

---

## 6. RAG Adapters (3 fichiers)

| Classe | Fichier | Implémente |
|---|---|---|
| `QdrantStore` | `src/RAG/QdrantStore.php` | Client REST Qdrant pour gestion des collections vectorielles knowledge base : upsert/search/count points, purge par document, estimation stockage, fallback en cas d’erreur |
| `EmbeddingService` | `src/RAG/EmbeddingService.php` | Service centralisant la génération d'embeddings multi-provider (OpenAI, Mistral, Gemini), avec sélection auto, gestion catalogue, découpe en batchs et gestion stricte des erreurs (exceptions) |
| `KbVisionDescriber` | `src/RAG/KbVisionDescriber.php` | — (description d'images) |
| `SemanticChunker` | `src/RAG/SemanticChunker.php` | Découpe les documents en chunks sémantiquement cohérents en détectant, via LLM en priorité, les frontières thématiques entre unités de texte, avec repli automatique sur une heuristique locale en cas d’échec |

---

## 7. Helpers (7 fichiers)

| Classe | Fichier | Rôle |
|---|---|---|
| `Encryption` | `src/Helpers/Encryption.php` | AES-256-CBC encrypt/decrypt, clé dérivée de `ENCRYPTION_KEY` via `sha256` |
| `FileExtractor` | `src/Helpers/FileExtractor.php` | Extraction texte PDF/DOCX/TXT/MD/XLSX (utilise `smalot/pdfparser`) |
| `ImageGenerator` | `src/Helpers/ImageGenerator.php` | Wrapper APIs DALL-E, Imagen, GPT Image, Gemini Nano Banana |
| `PdfExportService` | `src/Helpers/PdfExportService.php` | Export message → PDF via `dompdf/dompdf` |
| `UserPreferences` | `src/Helpers/UserPreferences.php` | Injection `ai_style` + `ai_user_context` dans prompt LLM |
| `VideoGenerator` | `src/Helpers/VideoGenerator.php` | Wrapper Sora |
| `WebSearch` | `src/Helpers/WebSearch.php` | Fallback recherche web pour modèles sans capacité native |
| `BackgroundJob` | `src/Helpers/BackgroundJob.php` | Lance des scripts CLI de scripts/ en arrière-plan via fork POSIX et bascule en exécution synchrone avec callback si indisponible |
| `Format` | `src/Helpers/Format.php` | Formatage unique FR d'affichage en euros, tokens, crédits, dates, tailles (Mo/Go), sans number_format inline ni ext-intl |
| `StorageInfo` | `src/Helpers/StorageInfo.php` | Mesure, totalise et met en cache l’empreinte disque (octets) de l’app (code, médias, documents, MySQL, Qdrant) pour le dashboard admin |
| `TextChunker` | `src/Helpers/TextChunker.php` | Découpe et regroupe un texte en chunks dimensionnels avec overlap configurable, sans dépendance externe, pour le RAG et la base de connaissances |

---

## 8. Security (1 fichier)

| Classe | Fichier | Rôle |
|---|---|---|
| `Csrf` | `src/Security/Csrf.php` | `token()`, `field()` (HTML hidden), `verify()` (POST + header `X-CSRF-Token`) |

---

## 9. Tools (1 fichier)

| Classe | Fichier | Rôle |
|---|---|---|
| `ToolCatalog` | `src/Tools/ToolCatalog.php` | Lecture `config/tools_catalog.php` (catalogue des modèles outils disponibles) |

---

## Récapitulatif

| Couche | Fichiers | Lignes (approx.) |
|---|---|---|
| Core (Router) | 1 | ~50 |
| Auth | 3 | ~200 |
| Controllers | 7 | ~2000+ (Admin et Chat dominent) |
| Models | 15 | ~1500 |
| LLM | 11 | ~2000+ |
| RAG | 3 | ~150 |
| Helpers | 7 | ~700 |
| Security | 1 | ~35 |
| Tools | 1 | ~50 |
| **Total `src/`** | **49** | **~6700** |

---

## Patterns de classe

### Modèles statiques (pas d'instance)

```php
class User {
    public static function findById(int $id): ?array { ... }
    public static function all(): array { ... }
    public static function update(int $id, array $data): void { ... }
}
```

**Pourquoi** : pas d'ORM, pas d'injection de dépendances. Trade-off : facile à utiliser, difficile à mocker en tests.

### Adapter LLM type

```php
class XAdapter implements LLMInterface {
    public function __construct(?string $model = null) { /* lit api_keys + catalogue */ }
    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array { /* HTTP streamé via fopen, push chunks via $onChunk */ }
    public function getProviderName(): string { return 'x'; }
}
```

### Helper stateless

```php
class Encryption {
    private const METHOD = 'AES-256-CBC';
    public static function encrypt(string $plaintext): string { ... }
    public static function decrypt(string $ciphertext): string { ... }
    private static function key(): string { return hash('sha256', ENCRYPTION_KEY, true); }
}
```

---

## Composants non-PHP

### Frontend JS (`public/assets/js/*`)

Vanilla JS sans bundler. Modules par feature dans templates.

### Templates PHP (`templates/`)

- `templates/chat/index.php` — vue chat principale (sidebar, messages, composer, modales)
- `templates/admin/*.php` — 12 vues admin
- `templates/partials/*.php` — header, footer, sidebar, modales partagées
- `templates/auth/*.php` — login
- `templates/setup/*.php` — wizard
- `templates/settings/*.php` — settings utilisateur
- `templates/about/*.php` — page À propos
- `templates/403.php`, `templates/404.php` — erreurs

---

## Points d'extension

| Pour | Faire |
|---|---|
| Nouvel LLM | Créer `src/LLM/XxxAdapter.php` impl. `LLMInterface` + ajouter au `match` de `LLMFactory::make` + ajouter entry au catalogue `config/llm_catalog.php` + `require` dans `public/index.php` |
| Nouveau provider d'embeddings | Déclarer dans `config/embeddings_catalog.php` + case dans `EmbeddingService::embedBatch` |
| Nouvelle entité DB | Créer migration `NNN_*.sql` + classe statique `src/Models/Xxx.php` + `require` dans `index.php` |
| Nouveau type d'outil | Étendre `ChatTool::type` + `ToolCatalog` + helper dédié + `CreditService::computeToolCredits` |
| Nouvelle route | Ajouter dans `public/index.php` + méthode contrôleur |
