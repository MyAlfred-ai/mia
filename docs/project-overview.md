# myLLM — Project Overview

> Application web PHP fullstack offrant une interface unifiée pour interagir avec plusieurs LLMs (OpenAI, Anthropic, Mistral, Gemini, Perplexity, DeepSeek, Kimi, GLM, Local) avec profils d'assistants, RAG, recherche web, génération d'images/vidéos, gestion des crédits et administration complète.

**Type :** Monolith web (PHP fullstack)
**Date :** 2026-05-06
**Version courante :** lue depuis `VERSION` (gérée automatiquement par git hooks)

---

## Executive Summary

myLLM est un agrégateur multi-LLM self-hosted qui expose une interface chat unique vers 7 fournisseurs IA. L'application gère :

- **Authentification** : Google OAuth 2.0 + email/mot de passe local (bcrypt)
- **Multi-tenant** : isolation stricte par `user_id`, rôles `admin / creator / user` (nouveaux comptes inactifs jusqu'à validation admin), groupes avec quota hérité
- **Profils d'assistants** : system prompts, températures, documents de référence, RAG vectoriel
- **Streaming SSE** : toutes les réponses LLM streamées en temps réel
- **Outils** : génération images (DALL-E, Imagen, GPT Image), vidéo (Sora), HTML
- **Crédits** : facturation interne basée sur tokens × prix catalogue × marge
- **RAG** : ChromaDB ou Qdrant connectés par profil

L'architecture est volontairement simple (MVC PHP maison sans framework, sans autoloader interne) pour rester transparente et facile à débugger en production.

---

## Tech Stack Summary

| Catégorie | Technologie | Version | Justification |
|---|---|---|---|
| Langage | PHP | 8.1+ (prod 8.3) | `strict_types=1`, types unions, `match`, `?>` |
| Architecture | MVC maison | — | Pas de framework — contrôle total, dette légère |
| DB | MySQL | 8 | InnoDB, charset `utf8mb4_unicode_ci` |
| Persistance | PDO | natif | `EMULATE_PREPARES=false` pour requêtes vraies préparées |
| Frontend | Vanilla JS + HTML/CSS | — | Pas de bundler ni framework |
| Templating | PHP natif | — | `<?= ?>` direct, pas de moteur |
| Web server | Apache 2.4 (Docker) | — | `mod_rewrite`, `mod_headers` |
| Conteneurisation | Docker + Compose | — | Dev (`docker-compose.yml`) + Prod (`docker-compose.prod.yml` + Traefik) |
| TLS / Routing prod | Traefik | — | Resolver ACME `le` (Let's Encrypt) |
| Auth SSO | Google OAuth 2.0 | — | + auth locale email/bcrypt |
| Chiffrement clés API | OpenSSL AES-256-CBC | — | Clé dérivée de `ENCRYPTION_KEY` |
| RAG | Qdrant embarqué (compose) | v1.15 | Bases de connaissances partagées + fallback lexical MySQL FULLTEXT |
| Composer deps | `dompdf/dompdf` ^3.1, `smalot/pdfparser` ^2.12 | — | Export PDF + extraction PDF uniquement |
| CI/CD | GitHub Actions | — | `develop` → staging, `main` → VPS prod via SSH |
| Versioning | Git hook post-commit | — | `VERSION` + `public/changelog.json` auto-générés |

---

## Architecture Type

**Monolith MVC PHP** avec :
- Router regex maison (`src/Router.php`)
- Pattern adapter pour LLMs (`LLMInterface`) ; RAG via services dédiés (`QdrantStore`, `EmbeddingService`)
- Source unique de vérité catalogue modèles : `config/llm_catalog.php`
- Migrations SQL auto-jouées au boot (`Database::migrate()`)
- Pas d'ORM, pas de service container, pas d'autoloader pour les classes du projet

---

## Repository Structure

**Type :** monolith (1 part)

```
myLLM/
├── public/index.php       # Front controller, dispatch, requires
├── config/                # app.php (env), llm_catalog.php, tools_catalog.php
├── src/                   # Code applicatif (Auth, Controllers, Helpers, LLM, Models, RAG, Security, Tools, Router)
├── database/migrations/   # 19 migrations SQL idempotentes
├── templates/             # Vues PHP par feature
├── docker/                # Entrypoint et configs
├── scripts/hooks/         # Git hooks (post-commit gère VERSION)
├── storage/               # uploads, logs, cache (volume Docker)
└── docs/                  # Documentation projet (ici)
```

---

## Documentation détaillée

- [Architecture](./architecture.md) — patterns, contrats, décisions techniques
- [Source Tree](./source-tree-analysis.md) — arborescence annotée
- [API Contracts](./api-contracts.md) — toutes les routes
- [Data Models](./data-models.md) — schéma MySQL, relations
- [Component Inventory](./component-inventory.md) — modèles, contrôleurs, adapters
- [Development Guide](./development-guide.md) — setup local, conventions, workflow
- [Installation](./INSTALLATION.md) — prérequis, les quatre modes, variables, dépannage
- [Exploitation](./EXPLOITATION.md) — mise à jour, sauvegarde, restauration, journaux
- [Project Context](./project-context.md) — règles critiques pour agents IA

## Documentation existante

- [README.md](../README.md) — présentation utilisateur
- [DEPLOY.md](../DEPLOY.md) — guide opérationnel production
- [CLAUDE.md](../CLAUDE.md) — instructions Claude Code (caveman + conventions)

---

## Getting Started

```bash
git clone git@github.com:MyAlfred-ai/myLLM.git myllm
cd myllm
./scripts/install-hooks.sh   # une fois après clone
docker compose up -d         # dev local
# → ouvre http://localhost
# Si .env absent → wizard /setup automatique
```

Voir [development-guide.md](./development-guide.md) pour détails complets.
