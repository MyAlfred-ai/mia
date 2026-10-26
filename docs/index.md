# myLLM — Documentation Index

> 📚 Point d'entrée principal pour le développement assisté IA et la navigation humaine.

**Date :** 2026-05-06
**Type :** Monolith web (PHP fullstack)
**Stack principale :** PHP 8.5 (8.3+) / MySQL 8 / Vanilla JS / Docker

---

## 🎯 Project Overview

- **Type** : Monolith — 1 part (`web`)
- **Langage primaire** : PHP 8.5 (8.3 au minimum) (`declare(strict_types=1)`)
- **Architecture** : MVC maison sans framework, adapters pluggables (LLM × 7, RAG × 2)

### Quick Reference

| Élément | Valeur |
|---|---|
| **Tech Stack** | PHP 8.3+, MySQL 8, Vanilla JS, Docker (php:8.5-apache), Traefik |
| **Entry Point** | `public/index.php` |
| **Architecture Pattern** | MVC monolithique + Adapters + Strategy (routing LLM) |
| **Authentification** | Google OAuth 2.0 + email/bcrypt local |
| **Streaming** | SSE natif (text/event-stream) |
| **Versioning** | Auto via git hook post-commit |

---

## 📖 Documentation générée

### Fondamentaux

- [Project Overview](./project-overview.md) — synthèse exécutive, stack, structure
- [Architecture](./architecture.md) — patterns, contrats, décisions, lifecycle requête
- [Project Context](./project-context.md) — ⭐ règles critiques agents IA (lire AVANT toute implémentation)

### Référence technique

- [Source Tree Analysis](./source-tree-analysis.md) — arborescence annotée
- [API Contracts](./api-contracts.md) — toutes les routes HTTP (~64 routes)
- [Data Models](./data-models.md) — schéma MySQL (16 tables, 63 migrations)
- [Component Inventory](./component-inventory.md) — 49 classes PHP par couche

### Guides opérationnels

- [Development Guide](./development-guide.md) — setup local, conventions, workflow git
- [Installation](./INSTALLATION.md) — prérequis, les quatre modes, variables, dépannage
- [Exploitation](./EXPLOITATION.md) — mise à jour, sauvegarde, restauration, journaux
- [DEPLOY.md](../DEPLOY.md) — guide ops production détaillé (existant)

### Méta

- [project-scan-report.json](./project-scan-report.json) — état du workflow document-project

---

## 📂 Documentation existante

- [README.md](../README.md) — présentation utilisateur des fonctionnalités
- [CLAUDE.md](../CLAUDE.md) — instructions Claude Code (caveman + conventions projet)
- [sonar-pro-api-chat-guide-2026-03.md](./sonar-pro-api-chat-guide-2026-03.md) — guide externe Perplexity Sonar Pro

---

## 🚀 Getting Started

### Pour développer

```bash
git clone git@github.com:MyAlfred-ai/myLLM.git myllm
cd myllm
./scripts/install-hooks.sh
docker compose up -d
# → http://localhost (wizard /setup au premier accès)
```

Détails complets : [Development Guide](./development-guide.md)

### Pour déployer en prod

VPS Linux + Docker + Traefik. Voir [Installation](./INSTALLATION.md).

### Pour comprendre l'architecture

1. Lire [Architecture](./architecture.md) — sections 1 à 9
2. Parcourir [Source Tree](./source-tree-analysis.md) — repère les `⭐`
3. Consulter [Component Inventory](./component-inventory.md) selon la couche d'intérêt

### Pour un agent IA

1. **OBLIGATOIRE** : lire [project-context.md](./project-context.md) AVANT toute implémentation
2. Référencer [api-contracts.md](./api-contracts.md) pour les endpoints existants
3. Référencer [data-models.md](./data-models.md) pour le schéma DB
4. Suivre les anti-patterns de [architecture.md#10](./architecture.md)

---

## 🗺️ Carte des features

| Feature | Contrôleur | Routes | Modèles | Adapters |
|---|---|---|---|---|
| Chat multi-LLM | `ChatController` | `/`, `/chat/*` | `Conversation`, `Message`, `Profile` | 7 × `LLMInterface` |
| Authentification | `AuthController` | `/auth/*` | `User`, `Session` | `GoogleOAuth` |
| Profils (assistants) | `AdminController`, `ProfileController` | `/admin/profiles/*`, `/profiles` | `Profile`, `ProfileDocument` | — |
| RAG vectoriel | (côté send) | — | `KnowledgeBase` + `KnowledgeBaseDocument` | `QdrantStore` + `EmbeddingService` |
| Génération images | `ChatController::imagine` | `/chat/{id}/imagine` | `ChatTool` | `ImageGenerator` |
| Génération vidéo | `ChatController::generateVideo` | `/chat/{id}/generate-video` | `ChatTool` | `VideoGenerator` |
| Génération HTML | `ChatController::generateHtml` | `/chat/{id}/generate-html` | `ChatTool` | — |
| Crédits & quotas | `AdminController` | `/admin/credits/*`, `/admin/usage` | `CreditService`, `UsageLog`, `User`, `UserGroup` | — |
| Settings utilisateur | `UserSettingsController` | `/settings/*` | `User`, `UserPreferences` | — |
| Settings globaux | `AdminController` | `/admin/settings` | `Settings` | — |
| Modèles IA (clés, instances, défaut, tests) | `AdminController` | `/admin/llms`, `/admin/api-keys/*`, `/admin/llm-models/*`, `/admin/tests/run` | `ApiKey`, `LlmModel`, `Settings` | `LLMFactory` |
| Tests outils | `AdminController` | `/admin/tools/tests/*` | `ToolTestRun` | — |
| Logs | `LogsController` | `/admin/logs`, `/admin/logs/feed` | (lecture fichier, `src/Logs/`) | — |
| Setup wizard | `SetupController` | `/setup` | (écriture `.env`) | — |
| Versioning | (aucun — hook git) | — | — | — |
| Export PDF | `ChatController::exportMessagePdf` | `/chat/message/{id}/pdf` | — | `PdfExportService` (Dompdf) |

---

## 📊 Statistiques projet

| Métrique | Valeur |
|---|---|
| Fichiers PHP `src/` | 49 |
| Lignes PHP estimées | ~6700 |
| Migrations SQL | 19 (1 doublon `018_*`) |
| Tables métier | 16 |
| Routes HTTP | ~64 |
| Adapters LLM | 7 |
| Adapters RAG | 2 |
| Templates PHP | ~25 |
| Composer deps prod | 2 (`dompdf/dompdf`, `smalot/pdfparser`) |
| Tests automatisés | **0** ⚠️ |

---

## 🔧 Évolutions techniques recommandées

Ordre suggéré (du plus impactant au moins) :

1. **Tests automatisés** — pré-requis pour évolution sereine. PHPUnit + tests d'intégration MySQL Docker
2. **Static analysis** — PHPStan ou Psalm + CI gate
3. **Formatter** — php-cs-fixer ou phpcs (PSR-12)
4. **Autoloader PSR-4** pour `src/` — réduit la liste de requires fragile dans `index.php`
5. **Refactor `index.php`** — extraire les routes dans `config/routes.php`, garder bootstrap minimal
6. **Couche service** — actuellement contrôleurs appellent directement les Models, peu testable
7. **Cleanup migrations** — résoudre doublon `018_*`, supprimer `token_quota` legacy après migration
8. **Healthcheck Docker** — pour monitoring + auto-restart

Voir [Architecture §11](./architecture.md) pour plus de détails.

---

## 🔁 Prochaines étapes BMad recommandées

1. **`[AR] Adversarial Review`** — `bmad-review-adversarial-general` sur ce projet pour traquer incohérences
2. **`[ECH] Edge Case Hunter`** — `bmad-review-edge-case-hunter` pour cas limites
3. **`[CA] Create Architecture`** — formaliser une architecture cible incluant les évolutions identifiées
4. **`[QA] QA Automation Test`** — bootstrapper la suite de tests
5. **`[US] Update Standards`** — encoder les conventions documentées ici comme standards `bmad-agent-tech-writer`

Chaque skill = nouvelle fenêtre de contexte fraîche.

---

**Verification Recap**
- Tests/extractions executed : analyse de 49 fichiers PHP, 63 migrations, 3 fichiers de config, 2 docker-compose, 3 workflows GitHub Actions
- Outstanding risks : aucun test automatisé, pas de static analysis, doublon migration `018_*`
- Recommended next checks before PR : faire tourner `[AR]` et `[ECH]` sur ce projet pour valider que la doc reflète bien l'état réel et identifier les incohérences
