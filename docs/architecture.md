# myLLM — Architecture

**Date :** 2026-05-06
**Type :** Monolith MVC PHP fullstack
**Version PHP :** 8.1+ (prod 8.3)

---

## 1. Vue d'ensemble

myLLM est un monolithe PHP qui agrège plusieurs APIs LLM derrière une interface chat unifiée. L'architecture suit un pattern MVC sans framework, avec plusieurs points d'extension par interface :

```
                    ┌────────────────────────────────────────┐
                    │           Navigateur (Vanilla JS)      │
                    └────────────────┬───────────────────────┘
                                     │ HTTP / SSE
                    ┌────────────────▼───────────────────────┐
                    │  Apache 2.4 + mod_rewrite (Docker)     │
                    └────────────────┬───────────────────────┘
                                     │
                    ┌────────────────▼───────────────────────┐
                    │  public/index.php (Front Controller)   │
                    │  ── Bootstrap (env, requires, session) │
                    │  ── Setup wizard si .env absent        │
                    │  ── Vérif CSRF sur POST                │
                    │  ── Router regex                       │
                    └──┬──────────────────────────────┬──────┘
                       │                              │
              ┌────────▼─────────┐          ┌─────────▼────────┐
              │ Controllers      │          │ Templates (PHP)  │
              │ Auth/Chat/Admin  │          │ HTML rendering   │
              │ Profile/Setup    │          └──────────────────┘
              │ UserSettings     │
              └───┬─────────┬────┘
                  │         │
        ┌─────────▼──┐  ┌───▼────────────────┐
        │  Models    │  │  Helpers / LLM     │
        │  (statiques│  │  / RAG / Tools     │
        │   + PDO)   │  │  Adapters          │
        └─────┬──────┘  └─────┬──────────┬───┘
              │               │          │
        ┌─────▼─────┐    ┌────▼────┐ ┌───▼──────────────┐
        │  MySQL 8  │    │  APIs   │ │  ChromaDB /      │
        │  InnoDB   │    │  LLM ×7 │ │  Qdrant (RAG)    │
        └───────────┘    └─────────┘ └──────────────────┘
```

---

## 2. Architecture Pattern

**MVC monolithique** avec extensions :

- **Front controller** : `public/index.php` charge les classes (requires manuels), démarre la session, vérifie CSRF, dispatch
- **Router** : `src/Router.php` — regex maison, params nommés `{id}`, dispatch sur `[Class::class, 'method']` ou closure
- **Modèles** : classes statiques avec méthodes statiques (pas d'ORM, pas d'instances)
- **Contrôleurs** : un par feature top-level (`AuthController`, `ChatController`, `AdminController`, `ProfileController`, `UserSettingsController`, `SetupController`, `AboutController`)
- **Templates** : PHP natif dans `templates/`, organisés par feature

### Patterns de conception utilisés

| Pattern | Lieu | Pourquoi |
|---|---|---|
| Singleton | `Database::getInstance()` | PDO partagé + migrations au premier appel |
| Factory | `LLMFactory::make("provider::model")` | Instancier le bon adapter selon slug |
| Adapter | `OpenAIAdapter`, `AnthropicAdapter`, etc. (impl. `LLMInterface`) | Uniformiser API LLMs hétérogènes |
| Service | `QdrantStore`, `EmbeddingService`, `KbVisionDescriber` | Bases de connaissances (Qdrant embarqué + embeddings multi-provider) |
| Strategy | `routing` field dans `llm_catalog` (`chat_completions` / `responses_api` / `responses_web_search` / `search_preview`) | Adapter OpenAI choisit endpoint selon modèle |
| Static helpers | `Csrf`, `Encryption`, `Session`, `AuthMiddleware` | Stateless utilities |

---

## 3. Bootstrap & lifecycle d'une requête

```
1. Apache reçoit la requête → DocumentRoot = /var/www/html/public
2. .htaccess réécrit toutes les URLs → public/index.php
3. index.php :
   ├── ROOT défini, env loadé via config/app.php
   ├── Composer autoload (vendor uniquement)
   ├── ~30 require manuels pour les classes du projet
   ├── Headers de sécurité (X-Frame-Options DENY, X-Content-Type-Options nosniff…)
   ├── Session::start() (cookie myLLM_session, 7j, httponly, samesite=Lax)
   ├── SetupController::isNeeded() ?
   │     └── oui → routes /setup uniquement, exit
   ├── Si POST → Csrf::verify() obligatoire (sinon 403)
   ├── Router::dispatch()
   │     └── match URL → instancie controller → appelle méthode
   └── Méthode controller :
         ├── AuthMiddleware::requireAuth() / requireAdmin() en premier
         ├── Logique métier (Models, Helpers, LLM Factory)
         └── Render template OU stream SSE OU JSON
```

---

## 4. Contrats d'extension

### `LLMInterface`

```php
interface LLMInterface {
    public function stream(
        array $messages,           // [['role'=>'user','content'=>'...'], ...]
        string $systemPrompt,
        callable $onChunk,         // fn(string $textChunk): void
        ?float $temperature = null,
        bool $webSearch = false
    ): array;                      // ['input' => int, 'output' => int]

    public function getProviderName(): string;
}
```

**7 implémentations actuelles :**
- `OpenAIAdapter` (Chat Completions OU Responses API selon `routing`)
- `AnthropicAdapter`
- `MistralAdapter` (Conversations API + fallback web_search)
- `GeminiAdapter`
- `PerplexityAdapter`
- `DeepSeekAdapter`
- `MoonshotAdapter` (Kimi, OpenAI-compat)
- `ZaiAdapter` (GLM, OpenAI-compat)
- `LocalAdapter` (OpenAI-compat custom `base_url`)

**Slug canonique :** `"provider::model"` (ex: `"openai::gpt-5.5"`). Format `"provider"` seul = fallback legacy lu sur `api_keys.model`.

### Bases de connaissances (RAG vectoriel)

Collections de documents vectorisés dans un **Qdrant embarqué** (service compose interne, une collection par base : `kb_{id}`), partagées entre assistants via la table N-N `profile_knowledge_bases`.

- `KnowledgeBase` / `KnowledgeBaseDocument` (modèles) — CRUD, droits admin/creator, pipeline d'ingestion (extraction → chunks MySQL → embeddings → upsert Qdrant), fallback lexical FULLTEXT.
- `QdrantStore` — client REST du Qdrant embarqué (`QDRANT_URL`).
- `EmbeddingService` — embeddings multi-provider (`config/embeddings_catalog.php`).
- `KbVisionDescriber` — description d'images par LLM vision à l'ingestion.
- Retrieval côté chat : `ChatController::buildKnowledgeBaseContext()` (recherche vectorielle, fusion par score, budget partagé avec les documents de référence, bascule lexicale automatique si Qdrant/embeddings indisponibles).

### Catalogue LLM (`config/llm_catalog.php`)

**Source unique de vérité** pour les modèles. Chaque entrée porte :
- `routing` : `chat_completions` / `responses_api` / `responses_web_search` / `search_preview`
- `temperature` : `bool` (false = paramètre rejeté par l'API)
- `web_native` : capacité de recherche web native
- `tags`, `limits` : metadata d'affichage UI

L'admin peut activer un sous-ensemble via la table `llm_models` qui override le catalogue (champ `display_name`, désactivation, etc.).

---

## 5. Sécurité

| Domaine | Mécanisme | Lieu |
|---|---|---|
| CSRF | Token session 32 bytes hex, `hash_equals` timing-safe, vérif POST + header `X-CSRF-Token` | `src/Security/Csrf.php` |
| Headers HTTP | `X-Frame-Options DENY`, `X-Content-Type-Options nosniff`, `Referrer-Policy strict-origin-when-cross-origin`, `X-XSS-Protection 1; mode=block` | `public/index.php` |
| Session | `myLLM_session`, lifetime 7j, `httponly`, `samesite=Lax`, `secure` si HTTPS | `src/Auth/Session.php` |
| Mots de passe | `password_hash($p, PASSWORD_BCRYPT)` + `password_verify` | `src/Models/User.php` |
| Clés API LLM | AES-256-CBC, IV random 16 bytes, base64(IV ‖ ciphertext), clé = `sha256(ENCRYPTION_KEY)` | `src/Helpers/Encryption.php` |
| Auth middleware | `requireAuth()` / `requireAdmin()` détecte JSON/SSE pour renvoyer 401 JSON au lieu de 302 HTML | `src/Auth/AuthMiddleware.php` |
| SQL | `prepare()` + `execute([])` partout, `EMULATE_PREPARES=false` (vraies préparées côté MySQL) | partout dans Models |
| XSS | `htmlspecialchars()` sur tout contenu utilisateur affiché | templates |
| Upload | `UPLOAD_ALLOWED_TYPES` whitelist + `UPLOAD_MAX_SIZE` 10 Mo | `config/app.php` |

### Setup wizard

Si `.env` absent ou `APP_SECRET` vide → `SetupController::isNeeded() === true` → toutes les requêtes redirigées vers `/setup` (CSRF désactivé sur cette route uniquement). Génère secrets cryptographiques aléatoires.

---

## 6. Streaming SSE

Toutes les réponses LLM sont streamées :

```
Client                     Server
  │                          │
  │  POST /chat/{id}/send    │
  ├─────────────────────────►│
  │                          │ AuthMiddleware::requireAuth()
  │                          │ ChatController::send()
  │                          │ LLMFactory::make($llmSlug)
  │                          │ header('Content-Type: text/event-stream')
  │                          │
  │  data: {chunk: "Hel"}    │ $adapter->stream(..., fn($c) => echo "data: {$c}\n\n"; flush())
  │◄─────────────────────────┤
  │  data: {chunk: "lo"}     │
  │◄─────────────────────────┤
  │  data: [DONE]            │
  │◄─────────────────────────┤
```

**Règles critiques :**
- `flush(); ob_flush();` après chaque chunk
- Ne JAMAIS renvoyer une 302 redirect dans un flux SSE → `AuthMiddleware::wantsJson()` détecte SSE/AJAX et renvoie 401 JSON
- Compteurs `tokens_input` / `tokens_output` retournés par l'adapter pour comptabilité

---

## 7. Crédits & quotas

- **Unité** : crédit interne. `Settings.credits_config_json` paramètre `credit_value_eur` (default 0.001 €), marges, frais fixes.
- **Calcul LLM** : `CreditService::computeLlmCredits($llmKey, $tokensIn, $tokensOut)` lit le catalogue de prix par modèle, applique marge, retourne `total_credits`, `input_credits`, `output_credits`.
- **Calcul outils** : `CreditService::computeToolCredits($toolId, $type, $seconds, $count)` pour image/vidéo/HTML.
- **Quota utilisateur** : `User::effectiveCreditQuotaK()` cascade user (`credit_quota_k`) → groupe (`UserGroup::effectiveQuotaForUserId`) → null (illimité). Unité = milliers de crédits / mois.
- **Logs** : table `usage_logs` stocke chaque opération (LLM input/output ou tool). `UsageLog::creditsBreakdownThisMonth()` agrège mensuellement.

---

## 8. Versioning automatique

| Fichier | Géré par | Contenu |
|---|---|---|
| `VERSION` | `scripts/hooks/post-commit` | `MAJOR.MINOR.<count>` où count = nb commits `feat/fix/refactor/perf/docs --no-merges` |
| `VERSION_BASE` | manuel | `MAJOR.MINOR` à bumper pour release |
| `public/changelog.json` | post-commit | 30 dernières améliorations, chacune avec sa propre version dégressive |
| `scripts/hooks/pre-commit` | no-op | Toute la logique est en post-commit qui amende silencieusement |

**Conséquence :** deux branches partageant les mêmes commits ancêtres ont le même `VERSION` (cherry-pick / rebase préservent les sujets). Comparable d'un coup d'œil entre staging et prod.

Hooks installés via `./scripts/install-hooks.sh` (à lancer une fois après clone).

---

## 9. Décisions architecturales clés

| Décision | Choix | Raison |
|---|---|---|
| Framework | Aucun (MVC maison) | Transparence, dette légère, easier debug prod |
| Autoloader | Pas pour code projet (Composer pour deps tierces uniquement) | Lisibilité ordre de chargement, fail-fast sur fichier manquant |
| ORM | Aucun (PDO + classes statiques) | Contrôle SQL fin, pas de magie |
| Catalogue LLM | Fichier PHP versionné + override DB | Fichier = SSOT, DB = activation/customization admin |
| Streaming | SSE natif (pas WebSocket) | Simple, compatible HTTP/HTTPS sans config Traefik spéciale |
| RAG | Adapter pattern, 2 backends | Pluggable, admin choisit par profil |
| Auth | OAuth Google + local bcrypt | SSO entreprise + accounts indépendants |
| Versioning | Calculé sur git log | Pas d'effort manuel, comparable cross-env |
| Migrations | SQL plain idempotent (`CREATE TABLE IF NOT EXISTS`, `ALTER ... IF NOT EXISTS`) | Simple, lisible, ré-applicable safe |
| Docker prod | Apache + PHP-FPM intégré (image `php:8.5-apache`) | Un seul container web, Traefik externe pour TLS |

---

## 10. Anti-patterns à éviter

- ❌ Ajouter un fichier `src/*.php` sans `require` dans `public/index.php`
- ❌ Hardcoder un nom de modèle LLM hors `config/llm_catalog.php`
- ❌ Renvoyer redirect 302 dans endpoint SSE/AJAX
- ❌ Modifier une migration déjà appliquée (créer `NNN+1_fix.sql` à la place)
- ❌ Édit manuel `VERSION` ou `public/changelog.json`
- ❌ Lire `$_SESSION` directement (utiliser `Session::get/set/has`)
- ❌ Logger une clé API ou mot de passe
- ❌ Lire `user_id` depuis la requête sans vérifier `Session::currentUser()['id']`

Voir [project-context.md](./project-context.md) pour la liste exhaustive.

---

## 11. Évolutions identifiées

Voir le résumé fonctionnel/technique dans [index.md](./index.md). Pistes naturelles :

1. **Tests automatisés** — actuellement zéro suite de tests, blocage majeur pour évolution sereine
2. **Autoloader PSR-4** pour `src/` — réduirait la liste de requires fragile dans `index.php`
3. **Refactor `index.php`** — actuellement 197 lignes mêlant bootstrap et table de routage. Extraire les routes dans `config/routes.php`
4. **CI lint + static analysis** — PHPStan / Psalm / php-cs-fixer non configurés
5. **Couche service** — actuellement contrôleurs appellent directement les Models, peu testable
