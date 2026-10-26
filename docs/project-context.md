---
project_name: myLLM
user_name: MyAlfred
date: 2026-05-06
sections_completed:
  ['technology_stack', 'language_rules', 'framework_rules', 'testing_rules', 'quality_rules', 'workflow_rules', 'anti_patterns']
status: complete
rule_count: 56
optimized_for_llm: true
---

# Project Context for AI Agents — myLLM

_Règles critiques et patterns à suivre par tout agent IA implémentant du code dans ce projet. Focus : détails non-évidents qu'un LLM oublierait._

---

## Technology Stack & Versions

**Backend**
- PHP **8.1+** strict (`declare(strict_types=1)` obligatoire en haut de chaque fichier)
- MVC maison sans framework ni autoloader Composer pour les classes du projet
- Composer pour deps tierces uniquement : `mpdf/mpdf ^8.2`, `smalot/pdfparser ^2.12`
- PSR-4 **NON utilisé** pour les classes internes — chargement par `require` dans `public/index.php`

**Données**
- MySQL **8** (jamais SQLite — ne pas suggérer de fallback)
- PDO avec `ATTR_EMULATE_PREPARES => false`, charset `utf8mb4_unicode_ci`
- Migrations SQL dans `database/migrations/NNN_description.sql`, jouées au boot

**Frontend**
- Vanilla JS + HTML/CSS — **pas de framework, pas de build tool, pas de bundler**
- Templates PHP dans `templates/` (pas de moteur de template, `<?= ?>` direct)

**Infra**
- Docker dev (`docker-compose.yml`) + prod (`docker-compose.prod.yml` + Traefik)
- Git hooks dans `scripts/hooks/` (post-commit gère `VERSION` et `public/changelog.json`)

**Convention linguistique**
- Tout le code, commentaires, messages d'erreur, logs : **français**

---

## Critical Implementation Rules

### Architecture & chargement

- **Pas d'autoloader pour les classes du projet.** Tout nouveau fichier PHP dans `src/` DOIT être ajouté manuellement à la liste de `require` dans `public/index.php`. Ordre important : interfaces avant adapters, modèles avant contrôleurs.
- Routes : ajouter dans `public/index.php` après le bloc `$router = new Router();`. Format `{id}` pour params nommés.
- Toute action POST passe par `Csrf::verify()` automatiquement. Routes `/setup` exemptées (early return avant le check).

### LLM adapters

- Slug LLM canonique : `"provider::model"` (ex: `"openai::gpt-5.5"`). Format legacy `"provider"` seul accepté en fallback uniquement.
- Tout nouvel adapter implémente `LLMInterface::stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature, bool $webSearch): array{input:int, output:int}`.
- **Streaming SSE obligatoire** : pas de retour synchrone. Le contrôleur ouvre `text/event-stream`, l'adapter pousse via `$onChunk`.
- Catalogue modèles : **source unique de vérité = `config/llm_catalog.php`**. Ne jamais hardcoder un nom de modèle dans un adapter ou la Factory. Lire via `LlmCatalog`.
- JSON encode payloads via `LlmAdapterHelpers::encodeBody()` (gère `JSON_INVALID_UTF8_SUBSTITUTE` pour PDF mal extraits).
- Routing OpenAI dépend du champ `routing` du catalogue : `chat_completions` / `responses_api` / `responses_web_search` / `search_preview`. Respecter `temperature: false` quand le modèle le rejette.

### Sécurité

- Clés API stockées chiffrées AES-256-CBC via `Encryption::encrypt()`. Jamais en clair en base, jamais loguées.
- `ENCRYPTION_KEY` et `APP_SECRET` lus depuis `.env`. Si absents → `SetupController::isNeeded()` redirige vers `/setup`.
- Headers de sécurité fixés dans `public/index.php` : `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
- Mots de passe : `password_hash($p, PASSWORD_BCRYPT)`. Vérification `password_verify`. Jamais MD5/SHA1.
- CSRF : token via `Csrf::token()`, vérifié soit POST `csrf_token`, soit header `X-CSRF-Token`. Comparaison `hash_equals` (timing-safe).
- Session : nom `myLLM_session`, cookie `httponly`, `samesite=Lax`, `secure` si HTTPS, lifetime 7 jours.

### Auth & autorisation

- `AuthMiddleware::requireAuth()` au début de TOUT endpoint authentifié. `requireAdmin()` pour admin.
- `wantsJson()` détecte SSE/AJAX/fetch — ne JAMAIS renvoyer une 302 HTML dans un flux stream (renvoie 401 JSON).
- Premier utilisateur créé = admin automatiquement (`User::createOrUpdate` compte les rows).
- Users email/password ont un `google_id` synthétique préfixé `pw_` + bytes random.
- Isolation conversations : `Conversation::belongsToUser($id, $userId)` AVANT toute lecture/écriture.

### Modèles & base de données

- **Aucun ORM.** Tous les modèles sont des classes statiques avec méthodes statiques (`User::findById`, `Profile::all`, etc.).
- `Database::getInstance()` est singleton, déclenche les migrations au premier appel.
- Migrations idempotentes : ne jamais modifier une migration `NNN_*.sql` déjà appliquée. Toujours créer un nouveau numéro.
- Préparer les requêtes (`prepare` + `execute([$args])`) — jamais d'interpolation directe.
- `Settings` cache mémoire process : `Settings::set()` met à jour DB + cache. Pas besoin de relire.

### Quotas & crédits

- Quota utilisateur : `User::effectiveCreditQuotaK()` cascade user → groupe → null (illimité).
- `credit_quota_k` est en **milliers** de crédits. Multiplier par 1000 pour valeur absolue.
- Compat legacy : `token_quota` (ancien champ) lu si `credit_quota_k` null.
- `CreditService::computeLlmCredits($llmKey, $tokensIn, $tokensOut)` : seul point d'entrée pour calculer un coût LLM. Idem `computeToolCredits` pour outils.

### Versioning

- `VERSION` à la racine = `MAJOR.MINOR.<count>`. **Géré par git hook post-commit.** Ne jamais éditer à la main, sauf `VERSION_BASE` pour bump majeur/mineur.
- `public/changelog.json` régénéré au commit (30 derniers `feat/fix/refactor/perf/docs --no-merges`).
- Hook `pre-commit` actuellement no-op — toute la logique est en `post-commit` qui amende silencieusement.

---

## Testing Rules

**État actuel : suite PHPUnit : 34 fichiers de test.** Tests manuels uniquement via `/admin/llms` (section Tests) et `/admin/tools` (outils).

Quand des tests seront introduits :
- PHPUnit recommandé pour tests unitaires (deps Composer)
- Tests d'intégration : taper sur une vraie MySQL Docker (jamais mock PDO)
- Tests E2E : Playwright ou équivalent contre `docker-compose.yml`
- Convention de nommage : `tests/Unit/`, `tests/Integration/`, `tests/E2E/`
- Toujours tester l'isolation par `user_id` sur les ressources partagées (Conversation, Message, ProfileDocument)
- Migrations testées en runnant un container MySQL frais — vérifier que `001 → NNN` s'applique sans erreur

---

## Code Quality & Style Rules

### Conventions de nommage

- Classes : `PascalCase` (ex: `ChatController`, `LlmCatalog`)
- Méthodes/fonctions : `camelCase` (ex: `findByGoogleId`, `effectiveCreditQuotaK`)
- Constantes : `UPPER_SNAKE_CASE` (ex: `APP_SECRET`, `UPLOAD_MAX_SIZE`)
- Fichiers de migration : `NNN_snake_case.sql` (ex: `015_user_groups.sql`)
- Variables : `camelCase` ou `snake_case` selon contexte (snake_case dominant pour rows DB pour matcher les colonnes)

### Style PHP

- `declare(strict_types=1);` ligne 2 de chaque fichier
- Types de paramètres et de retour OBLIGATOIRES (`?array`, `string`, `void`, `int|null|false`)
- Préférer `match()` à `switch` pour dispatching (`LLMFactory::make`)
- Null coalescing `??` plutôt que `isset()` ternaire
- Pas de classes anonymes ni traits sauf nécessité forte

### Commentaires

- Français uniquement
- DocBlocks `/** */` sur méthodes publiques non-triviales (with `@param`, `@return`)
- Commenter le **pourquoi**, pas le **quoi**
- Exemples concrets utiles (voir `LLMFactory::make` qui documente les formats de slug)

### Organisation fichiers

```
src/
  Auth/         AuthMiddleware, GoogleOAuth, Session
  Controllers/  *Controller.php (un par feature top-level)
  Helpers/      Services stateless (Encryption, FileExtractor, WebSearch)
  LLM/          Adapters + Factory + Catalog + Interface
  Models/       Couche données statique
  RAG/          Adapters bases vectorielles + Interface
  Security/     Csrf
  Tools/        Catalog d'outils
  Router.php
config/         app.php (env), llm_catalog.php, tools_catalog.php
database/migrations/  NNN_*.sql
public/         index.php, assets, uploads publics
templates/      Vues PHP par feature
storage/        logs/, uploads/ (volume Docker en prod)
scripts/        hooks/, install-hooks.sh
```

---

## Development Workflow Rules

- **Branche dev** : `develop` → auto-deploy staging via GitHub Actions
- **Branche prod** : `main`
- **Commit prefixes** comptés dans `VERSION` : `feat:`, `fix:`, `refactor:`, `perf:`, `docs:`. Autres prefixes ignorés du compteur.
- Hooks installés via `./scripts/install-hooks.sh` (à lancer une fois après clone)
- `VERSION_BASE` à bumper manuellement pour release majeure/mineure
- PR : la `develop → main` reflète une release ; merges fréquents petits commits préférés

---

## Critical Don't-Miss Rules

### Anti-patterns à éviter absolument

- ❌ **Ajouter un fichier `src/*.php` sans require dans `public/index.php`** → fatal error au runtime, pas détecté par le linter
- ❌ **Hardcoder un nom de modèle LLM** ailleurs que dans `config/llm_catalog.php` → break de la single source of truth
- ❌ **Renvoyer un redirect 302 dans un endpoint SSE/AJAX** → casse le client (utiliser `wantsJson()`)
- ❌ **Modifier une migration déjà appliquée** → désync entre envs (créer un `NNN+1_fix.sql`)
- ❌ **Édit manuel de `VERSION` ou `public/changelog.json`** → écrasés au prochain commit
- ❌ **Logger une clé API ou un mot de passe** → fuite dans `storage/logs/errors.log`
- ❌ **Oublier `AuthMiddleware::requireAuth()` ou `requireAdmin()` en début de méthode contrôleur**
- ❌ **Lire `$user_id` depuis la requête** sans vérifier `Session::currentUser()['id']` → cross-user leak
- ❌ **Utiliser `$_SESSION` directement** au lieu de `Session::set/get/has` → pas d'init garanti

### Edge cases connus à respecter

- Adapter LLM doit gérer `$http_response_header` null (timeout DNS) → `LlmAdapterHelpers::extractHttpStatus`
- Texte extrait de PDF peut contenir UTF-8 invalide → toujours `LlmAdapterHelpers::encodeBody`
- Modèles avec `temperature: false` (search-preview) rejettent le param → ne pas l'envoyer
- `LLMFactory::make("local")` ne nécessite pas de `::model` (config dans `api_keys.base_url`)
- `LLMFactory::available()` fallback : si aucune instance `llm_models` configurée, retombe sur `api_keys` providers (compat ancienne installation)
- Premier utilisateur Google = admin auto. Si tu vides `users` en dev, le prochain login devient admin.

### Sécurité — règles strictes

- Toute requête SQL : `prepare()` + `execute([])` — JAMAIS de concaténation
- Tout input utilisateur affiché : `htmlspecialchars()` (XSS)
- Upload : valider via `UPLOAD_ALLOWED_TYPES` + `UPLOAD_MAX_SIZE` (10 Mo)
- `ENCRYPTION_KEY` ≠ `APP_SECRET`. Rotation `ENCRYPTION_KEY` casse les `api_keys` existantes (pas de re-chiffrement automatique)
- Clés API testées via `/admin/api-keys/{id}/test` — utiliser ce mécanisme plutôt qu'écrire un test custom

### Performance gotchas

- `Database::getInstance()` déclenche les migrations au premier appel — éviter de l'appeler dans une boucle de boot
- `Settings::load()` charge tout en mémoire au premier `get()` — OK car petit (clé/valeur)
- SSE : flusher après chaque chunk (`flush(); ob_flush();`) sinon le client voit un blob unique
- `LlmCatalog::all()` lit le fichier PHP — caché par opcache en prod, pas un souci dev

---

## Usage Guidelines

**Pour les agents IA :**
- Lire ce fichier AVANT toute implémentation
- Suivre TOUTES les règles à la lettre
- Dans le doute, choisir l'option la plus restrictive
- Mettre à jour ce fichier si un nouveau pattern émerge (avec validation utilisateur)

**Pour les humains :**
- Garder le fichier lean et focalisé sur les besoins agents
- Mettre à jour quand la stack ou un pattern évolue
- Revue trimestrielle pour purger les règles devenues évidentes
- Toute migration de règle doit être reflétée ici (sinon les agents implémenteront l'ancien pattern)

Last Updated: 2026-05-06
