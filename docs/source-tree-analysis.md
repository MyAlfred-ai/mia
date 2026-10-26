# myLLM — Source Tree Analysis

Arborescence annotée du projet avec rôle de chaque dossier et fichier critique.

---

## Vue d'ensemble

```
myLLM/                              # Racine projet
├── .github/
│   └── workflows/                  # CI/CD GitHub Actions
│       ├── deploy.yml              # main → VPS prod via SSH, MANUEL, une instance au choix
│       └── deploy-staging.yml      # develop → recette, automatique
│
├── public/                         # 🌐 DocumentRoot Apache (Docker)
│   ├── index.php                   # ⭐ Front controller (197 lignes)
│   │                               #   - Bootstrap : env + requires manuels
│   │                               #   - Setup wizard si .env absent
│   │                               #   - CSRF check sur POST
│   │                               #   - Table de routage complète
│   ├── assets/                     # JS, CSS, images, generated
│   ├── changelog.json              # Auto-généré post-commit
│   └── .htaccess                   # mod_rewrite vers index.php
│
├── config/                         # ⚙️ Configuration
│   ├── app.php                     # ⭐ Lecture .env, défini constantes (APP_*, DB_*, GOOGLE_*, ENCRYPTION_KEY)
│   ├── llm_catalog.php             # ⭐ SSOT modèles LLM (label, routing, temperature, web_native…)
│   └── tools_catalog.php           # Catalogue outils (image/vidéo/HTML modèles disponibles)
│
├── src/                            # 📦 Code applicatif PHP
│   │
│   ├── Auth/                       # Authentification
│   │   ├── AuthMiddleware.php      # requireAuth/requireAdmin + détection JSON/SSE
│   │   ├── GoogleOAuth.php         # OAuth 2.0 Google
│   │   └── Session.php             # Wrapper $_SESSION + helpers (currentUser, isAdmin, flash)
│   │
│   ├── Controllers/                # Contrôleurs HTTP (un par feature top-level)
│   │   ├── AboutController.php     # Page /a-propos
│   │   ├── AdminController.php     # ⭐ Toute l'administration (users, profils, clés, logs, crédits, outils, settings)
│   │   ├── AuthController.php      # Login/logout, callback OAuth
│   │   ├── ChatController.php      # ⭐ Chat principal (index, send SSE, regenerate, upload, imagine, generate-html, generate-video, exportPdf)
│   │   ├── ProfileController.php   # Liste publique des profils
│   │   ├── SetupController.php     # Wizard premier démarrage (.env)
│   │   └── UserSettingsController.php  # /settings (profil, IA, password, usage)
│   │
│   ├── Helpers/                    # Services stateless
│   │   ├── Encryption.php          # AES-256-CBC pour clés API
│   │   ├── FileExtractor.php       # Extraction texte PDF/DOCX/TXT/MD/XLSX
│   │   ├── ImageGenerator.php      # Wrapper APIs DALL-E/GPT Image/Imagen
│   │   ├── PdfExportService.php    # Export message → PDF via mpdf
│   │   ├── UserPreferences.php     # Injection style + contexte personnel dans prompt
│   │   ├── VideoGenerator.php      # Wrapper Sora
│   │   └── WebSearch.php           # Fallback recherche web (modèles sans web natif)
│   │
│   ├── LLM/                        # Adapters LLM (cœur métier)
│   │   ├── LLMInterface.php        # Contrat stream() + getProviderName()
│   │   ├── LLMFactory.php          # ⭐ make("provider::model") + available() + models()
│   │   ├── LlmAdapterHelpers.php   # encodeBody, extractHttpStatus, std error
│   │   ├── LlmCatalog.php          # Lecture du catalogue config/llm_catalog.php
│   │   ├── OpenAIAdapter.php       # Chat Completions OU Responses API selon routing
│   │   ├── AnthropicAdapter.php
│   │   ├── MistralAdapter.php      # Conversations API + fallback web_search
│   │   ├── GeminiAdapter.php
│   │   ├── PerplexityAdapter.php
│   │   ├── DeepSeekAdapter.php
│   │   └── LocalAdapter.php        # OpenAI-compat custom base_url
│   │
│   ├── Models/                     # Couche données (classes statiques + PDO)
│   │   ├── Database.php            # ⭐ Singleton PDO + migrations auto au boot
│   │   ├── User.php                # CRUD users, rôles, quota mensuel
│   │   ├── UserGroup.php           # Groupes + quota hérité
│   │   ├── Conversation.php        # Conversations filtrées par user_id
│   │   ├── Message.php             # Messages multi-fichiers (files_json)
│   │   ├── Profile.php             # Assistants : prompt, couleur, avatar, RAG
│   │   ├── ProfileDocument.php     # Documents de référence par assistant
│   │   ├── ApiKey.php              # Clés API chiffrées AES-256
│   │   ├── LlmModel.php            # Override admin du catalogue
│   │   ├── UsageLog.php            # Logs consommation, breakdown mensuel
│   │   ├── Settings.php            # Settings clé/valeur + cache mémoire
│   │   ├── KnowledgeBase.php        # Bases de connaissances (+ KnowledgeBaseDocument.php)
│   │   ├── ChatTool.php            # Outils image/vidéo/HTML
│   │   ├── ToolTestRun.php         # Résultats tests outils admin
│   │   └── CreditService.php       # ⭐ Calcul crédits LLM + outils selon catalogue
│   │
│   ├── RAG/                        # Bases de connaissances (Qdrant embarqué)
│   │   ├── QdrantStore.php         # Client REST Qdrant interne
│   │   ├── EmbeddingService.php    # Embeddings multi-provider
│   │   └── KbVisionDescriber.php   # Description d'images (LLM vision)
│   │
│   ├── Security/
│   │   └── Csrf.php                # Token session + verify (POST + header X-CSRF-Token)
│   │
│   ├── Tools/
│   │   └── ToolCatalog.php         # Lecture config/tools_catalog.php
│   │
│   └── Router.php                  # ⭐ Router regex maison (~50 lignes)
│
├── database/
│   └── migrations/                 # ⭐ Migrations SQL idempotentes
│       ├── 001_init.sql            # Schéma de base (users, profiles, conversations, messages, usage_logs, api_keys)
│       ├── 002_profile_color.sql
│       ├── 003_profile_temperature.sql
│       ├── 004_settings.sql
│       ├── 005_profile_avatar.sql
│       ├── 006_message_files_json.sql
│       ├── 007_api_keys_model.sql
│       ├── 008_user_token_quota.sql
│       ├── 009_api_keys_local.sql
│       ├── 010_llm_models.sql
│       ├── 011_user_password.sql
│       ├── 012_chat_tools.sql
│       ├── 013_chat_tools_test_status.sql
│       ├── 014_tool_test_runs.sql
│       ├── 015_user_groups.sql
│       ├── 016_user_group_members.sql
│       ├── 017_tool_usage_logs.sql
│       ├── 018_credits_quota_and_usage.sql
│       ├── 018_tool_usage_backfill_markers.sql   # ⚠️ Doublon de numéro 018
│       └── 019_logo_hover.sql
│
├── templates/                      # Vues PHP natif
│   ├── 403.php / 404.php
│   ├── about/
│   ├── admin/                      # Templates admin (credits, knowledge-bases, llms, logs, profiles, settings, tools, usage, user-groups, users, index)
│   ├── auth/
│   ├── chat/                       # index.php (vue chat principale)
│   ├── partials/                   # Header, footer, sidebar, modales
│   ├── settings/
│   └── setup/                      # Wizard /setup
│
├── docker/                         # Configs Docker
│   └── entrypoint.sh
│
├── scripts/
│   ├── hooks/                      # Git hooks
│   │   ├── pre-commit              # NO-OP (logique en post-commit)
│   │   ├── post-commit             # ⭐ Calcule VERSION + changelog.json + amend
│   │   └── pre-push
│   └── install-hooks.sh            # Installe les hooks dans .git/hooks
│
├── storage/                        # Volume Docker (persisté)
│   ├── uploads/                    # Fichiers chat utilisateurs
│   ├── logs/                       # errors.log
│   ├── cache/
│   ├── ratelimit/
│   └── profile_documents/          # Documents de référence par profil
│
├── docs/                           # 📚 Documentation projet (ICI)
│   ├── project-context.md          # ⭐ Règles critiques agents IA
│   ├── project-overview.md         # Vue d'ensemble
│   ├── architecture.md             # ⭐ Patterns, décisions techniques
│   ├── source-tree-analysis.md     # Ce document
│   ├── api-contracts.md            # Routes HTTP
│   ├── data-models.md              # Schéma DB
│   ├── component-inventory.md      # Inventaire composants
│   ├── development-guide.md        # Setup local + conventions
│   ├── INSTALLATION.md             # installer MIA (4 modes)
│   ├── EXPLOITATION.md             # mise à jour, sauvegarde, restauration
│   ├── index.md                    # Master index
│   └── project-scan-report.json    # State workflow document-project
│
├── vendor/                         # Composer deps (gitignored)
│
├── .env                            # ⚠️ Secrets — gitignored, généré par /setup
├── composer.json                   # mpdf/mpdf, smalot/pdfparser
├── composer.lock
├── Dockerfile                      # ⭐ php:8.5-apache + ext + Composer + permissions
├── docker-compose.yml              # Dev local (myLLM + MySQL)
├── docker-compose.prod.yml         # Prod (myLLM + MySQL + labels Traefik)
├── php-env.ini                     # Surcharge PHP (timeouts, upload size)
├── router.php                      # PHP built-in server (dev hors Docker)
├── install.sh                      # installeur guidé et idempotent
├── update.sh                       # Script déploiement (git pull + rebuild)
├── VERSION                         # Auto-généré (ex: 1.0.42)
├── VERSION_BASE                    # Manuel (ex: 1.0)
├── README.md
├── DEPLOY.md                       # Guide ops production
└── CLAUDE.md                       # Instructions Claude Code
```

---

## Critical folders summary

| Dossier | Rôle | Modifications fréquentes |
|---|---|---|
| `public/index.php` | Front controller + table de routage complète | Toute nouvelle route + tout nouveau fichier `src/*.php` |
| `config/llm_catalog.php` | Source unique de vérité modèles LLM | Ajout/maj modèle |
| `src/LLM/` | Adapters LLM | Nouvelle API LLM |
| `src/Controllers/` | Logique HTTP | Nouvelle feature |
| `src/Models/` | Accès DB | Nouvelle entité |
| `database/migrations/` | Schéma | Toute évolution DB → nouveau fichier `NNN+1_*.sql` |
| `templates/` | Vues HTML | Toute évolution UI |
| `scripts/hooks/post-commit` | Versioning auto | Très rare |

---

## Entry points

- **HTTP** : `public/index.php` (toutes les requêtes via Apache mod_rewrite)
- **CLI dev** : `router.php` (PHP built-in server, hors Docker)
- **Docker** : `docker/entrypoint.sh` (lance Apache, prépare permissions)
- **Setup** : `install.sh` (installation guidée, tous modes)
- **Update** : `update.sh` (redéploiement, déclenché par CI)

---

## Anomalies repérées

- ⚠️ **Numérotation migration** : `018_credits_quota_and_usage.sql` ET `018_tool_usage_backfill_markers.sql` partagent le même préfixe `018`. Ordre d'exécution déterminé par tri alphabétique → `018_credits_*` joué avant `018_tool_*`. Fonctionne mais fragile si on ajoute une migration entre les deux.
- ⚠️ **`router.php` racine vs `src/Router.php`** : noms identiques. `router.php` est utilisé uniquement par le serveur PHP built-in (`php -S`), `src/Router.php` est la classe de routing applicative.
- ⚠️ **`docs/sonar-pro-api-chat-guide-2026-03.md`** : doc tierce isolée, pas référencée ailleurs.
