# myLLM — Development Guide

Guide de mise en place locale + conventions de développement.

---

## Prérequis

Docker et Docker Compose v2. Rien d'autre : l'installeur vérifie le reste et le
dit s'il manque quelque chose.

## Setup initial

```bash
git clone https://github.com/MyAlfred-ai/myLLM.git mia
cd mia
MIA_MODE=dev MIA_PORT=8080 ./install.sh
./scripts/install-hooks.sh      # une fois : versioning et changelog automatiques
```

Le mode `dev` monte le code source de l'hôte dans les conteneurs : une
modification est visible au rechargement, sans reconstruire l'image. Il fixe
aussi `APP_ENV=local`, ce qui affiche la trace complète des erreurs à l'écran, et
publie le tableau de bord Qdrant sur `127.0.0.1:6333`.

Lancez `composer install` sur l'hôte au préalable : le montage recouvre aussi
`vendor/`.

**Attention** : hors mode `dev`, le conteneur ne monte pas le code. Une
modification n'a aucun effet tant que l'image n'est pas reconstruite
(`docker compose build && docker compose up -d`) — c'est la source d'erreur la
plus coûteuse de ce projet en temps perdu.

Prérequis détaillés, autres modes d'installation, variables d'environnement et
dépannage : **[INSTALLATION.md](INSTALLATION.md)**.
Mise à jour, sauvegarde, restauration : **[EXPLOITATION.md](EXPLOITATION.md)**.

### Vérifier son environnement

```bash
docker compose exec app php scripts/doctor.php
```

### Repartir de zéro

```bash
docker compose down -v      # supprime AUSSI les volumes : base, fichiers, index
rm -f .env
MIA_MODE=dev ./install.sh
```

## Workflow git

### Branches

| Branche | Rôle | Auto-deploy |
|---|---|---|
| `main` | Production | ✅ via GitHub Actions → VPS prod |
| `develop` | Staging | ✅ via GitHub Actions → environnement staging |
| `feat/*`, `fix/*` | Feature branches | ❌ — merger dans `develop` puis `main` |

### Commit conventions

Préfixes **comptés** dans `VERSION` (les autres sont ignorés du compteur) :
- `feat:` nouvelle fonctionnalité
- `fix:` correction de bug
- `refactor:` réorganisation sans changement de comportement
- `perf:` optimisation
- `docs:` documentation

Préfixes ignorés du compteur (mais utilisables) :
- `chore:`, `test:`, `style:`, `ci:`, `build:`

**Exemple :**
```
feat: ajoute le streaming SSE pour Anthropic
fix: corrige la fuite XSS dans templates/chat/index.php
refactor: extrait CreditService du controller admin
```

### Versioning automatique

À chaque commit, le hook `post-commit` :
1. Compte les commits `feat|fix|refactor|perf|docs --no-merges` dans l'historique
2. Réécrit `VERSION = MAJOR.MINOR.<count>` (lit base depuis `VERSION_BASE`)
3. Régénère `public/changelog.json` (30 dernières améliorations, version dégressive)
4. Amende silencieusement le commit pour inclure ces changements

**À NE JAMAIS faire :**
- Éditer `VERSION` à la main
- Éditer `public/changelog.json` à la main
- Utiliser `git commit --no-verify` (skip le hook)

**Bumper version majeure/mineure :**
```bash
echo "1.1" > VERSION_BASE
git add VERSION_BASE
git commit -m "feat: bump à 1.1"
```

### Pre-push

Le hook `pre-push` est installé mais documenté ailleurs (vérifier `scripts/hooks/pre-push`).

---

## Conventions de code

### PHP

- `declare(strict_types=1);` ligne 2 obligatoire
- Types de paramètres et de retour OBLIGATOIRES
- Pas d'autoloader pour le code interne — ajouter chaque nouveau fichier `src/*.php` dans `public/index.php` (ordre : interfaces → modèles → adapters → contrôleurs)
- Préférer `match()` à `switch`
- `??` plutôt que `isset() ? : `
- DocBlocks `/** */` sur méthodes publiques non-triviales

### Naming

- Classes : `PascalCase`
- Méthodes : `camelCase`
- Constantes : `UPPER_SNAKE_CASE`
- Migrations : `NNN_snake_case.sql`
- Variables PHP : `camelCase` ou `snake_case` selon contexte (rows DB en snake_case)
- Routes : kebab-case (ex: `/admin/api-keys`)

### Langue

**TOUT en français** : code, commentaires, noms de variables, messages d'erreur, logs, templates UI.

### SQL

- `prepare()` + `execute([])` partout — JAMAIS de concaténation
- Migrations idempotentes (`CREATE TABLE IF NOT EXISTS`, `ALTER ... ADD COLUMN IF NOT EXISTS` quand MySQL le supporte, sinon try/catch)
- Charset `utf8mb4_unicode_ci` partout
- Foreign keys explicites avec ON DELETE policy claire

---

## Ajouter une fonctionnalité

### Checklist nouvelle entité

1. Créer migration `database/migrations/NNN_xxx.sql` (numéro = max actuel + 1)
2. Créer modèle `src/Models/Xxx.php` (classe statique)
3. `require ROOT . '/src/Models/Xxx.php';` dans `public/index.php` (ordre : avant les contrôleurs)
4. Si exposé en HTTP : ajouter méthodes contrôleur + routes dans `public/index.php`
5. Mettre à jour `docs/data-models.md` et `docs/api-contracts.md`
6. Tester localement : `docker compose down && docker compose up -d` (la migration se joue au premier appel à `Database::getInstance()`)

### Checklist nouvel adapter LLM

1. Créer `src/LLM/XxxAdapter.php` impl. `LLMInterface`
2. Ajouter au `match` de `LLMFactory::make()`
3. Ajouter entrée provider dans `config/llm_catalog.php` (endpoints, default_model, models[])
4. `require` dans `public/index.php`
5. Tester via `/admin/llms` (bouton Test)

### Checklist nouvelle route

1. Méthode dans le contrôleur approprié (créer un nouveau contrôleur si feature dédiée)
2. Ajouter `$router->get/post(...)` dans `public/index.php`
3. Auth en début de méthode : `AuthMiddleware::requireAuth()` ou `requireAdmin()`
4. CSRF auto-vérifié pour POST par `index.php`
5. Mettre à jour `docs/api-contracts.md`

---

## Tests

### État actuel

**Aucune suite de tests automatisée.** Tests manuels uniquement :
- `/admin/llms` (section Tests) : test des LLMs activés (ping, recherche web, vision)
- `/admin/tools/tests/start` : test batch des outils image/vidéo/HTML

### Recommandations futures

Voir `docs/architecture.md#11-évolutions-identifiées`. Pistes :
- PHPUnit pour tests unitaires (Models, Helpers, CreditService)
- Tests d'intégration contre une vraie MySQL Docker
- Playwright pour tests E2E
- Convention : `tests/Unit/`, `tests/Integration/`, `tests/E2E/`

---

## Debugging

### Logs

- Erreurs PHP : `storage/logs/errors.log`
- En dev : `display_errors=1` au début de `public/index.php` (puis désactivé). Activer via `APP_ENV=development` (à câbler si besoin).
- Apache logs : `docker compose logs <service>`

### MySQL en dev

```bash
docker compose exec db mysql -u root myllm
```

### Inspecter une session

```bash
# Cookies dans devtools navigateur → session ID = myLLM_session
# Ou côté PHP, dans un contrôleur :
var_dump(Session::currentUser());
```

### Reset complet

```bash
docker compose down -v   # ⚠️ supprime le volume DB
docker compose up -d
# → wizard /setup réapparaît
```

---

## Linting / formatting

**Aucun outil configuré actuellement.** À envisager :
- `php-cs-fixer` ou `phpcs` (PSR-12 ou custom)
- `phpstan` ou `psalm` pour analyse statique
- `prettier` ou `stylelint` côté CSS si Vanilla JS gardé

---

## Documentation

Toute évolution structurelle doit être reflétée dans :
- [README.md](../README.md) — fonctionnalités utilisateur
- [docs/architecture.md](./architecture.md) — patterns
- [docs/api-contracts.md](./api-contracts.md) — routes
- [docs/data-models.md](./data-models.md) — schéma DB
- [docs/component-inventory.md](./component-inventory.md) — composants
- [docs/project-context.md](./project-context.md) — règles agents IA

Les agents IA lisent `project-context.md` AVANT toute implémentation. Si un nouveau pattern émerge, l'ajouter là en priorité.

---

## Ressources

- [DEPLOY.md](../DEPLOY.md) — déploiement production VPS
- [CLAUDE.md](../CLAUDE.md) — règles Claude Code (caveman + conventions)
- [docs/sonar-pro-api-chat-guide-2026-03.md](./sonar-pro-api-chat-guide-2026-03.md) — guide externe Perplexity
