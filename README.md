# MIA

MIA est un assistant IA auto-hébergé qui réunit plusieurs fournisseurs de
modèles, des assistants configurables, des bases de connaissances et les outils
du quotidien dans une interface web unique.

## Fonctionnalités

- **Chat multi-modèles** : OpenAI, Anthropic, Mistral, Gemini, Perplexity,
  DeepSeek, Kimi, GLM et modèles locaux compatibles avec l'API OpenAI.
- **Assistants et bases de connaissances** : prompts configurables, RAG
  vectoriel, sources synchronisées et partage entre utilisateurs.
- **Connecteurs personnels** : Google Drive, Gmail, Agenda, Tasks, Outlook,
  OneDrive et SharePoint, avec contrôle de l'utilisateur sur les actions
  d'écriture.
- **Création** : images, vidéos, pages web, cartes, présentations et documents.
- **Administration** : utilisateurs, authentification, modèles, quotas,
  consommation, sauvegardes et diagnostics.
- **Web et mobile** : interface web installable (PWA) et applications natives
  iOS/Android via Capacitor.

## Prérequis

- Docker Engine et Docker Compose v2 pour une installation conteneurisée.
- PHP 8.3+ et Composer pour les commandes de développement et les tests.
- Node.js 20+ pour le wrapper mobile.

## Démarrage et développement

La configuration de l'application se fait dans `.env`. Le fichier
`.env.example` documente les variables et les fichiers Compose disponibles.
Le script racine `install.sh` installe MIA depuis ce dépôt ; pour travailler
sur le code, lancer `MIA_MODE=dev ./install.sh` et consulter le
[guide de développement](docs/development-guide.md).

Le [kit d'installation par images](kit/README.md) prépare un serveur à partir
des images publiées, sans compilation locale ni clone Git.

Les tests PHP s'exécutent avec :

```bash
composer install
vendor/bin/phpunit
```

Le déploiement mobile exige `MIA_SERVER_URL` au moment de la compilation.
Consulter le [guide mobile](mobile/README.md) pour les environnements et les
étapes de build.

## Documentation

| Document | Sujet |
|---|---|
| [Guide utilisateur](docs/GUIDE-UTILISATEUR.md) | Chat, connecteurs et créations |
| [Guide créateur](docs/GUIDE-CREATEUR.md) | Assistants, prompts et bases de connaissances |
| [Guide administrateur](docs/GUIDE-ADMIN.md) | Configuration et administration |
| [Nouveautés](docs/NOUVEAUTES.md) | Évolutions de l'application |
| [Guide de développement](docs/development-guide.md) | Architecture, conventions et tests |
| [Application mobile](mobile/README.md) | Capacitor, configuration et compilation |

## Version et changelog

La version est définie dans [`VERSION`](VERSION) ; chaque publication porte le
tag `vX.Y.Z` correspondant. Les nouveautés destinées aux utilisateurs sont
décrites dans [`docs/NOUVEAUTES.md`](docs/NOUVEAUTES.md).

## Licence

MIA est distribué sous les termes de la licence indiquée dans [`LICENSE`](LICENSE).
