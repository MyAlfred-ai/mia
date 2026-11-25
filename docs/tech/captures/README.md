# Captures de la documentation utilisateur

Les images de `docs/user/images/` qui montrent l'écran se refont ici : MIA lancée en local, sur des
données fictives, en thème clair. **Jamais sur une instance réelle** : l'image est publiée telle quelle,
et le contrôle anti-fuite du wiki ne lit pas les images
([standard 13 de my-docs](https://github.com/MyAlfred-ai/my-docs/blob/main/standards/13-doc-utilisateur.md#captures-décran)).

## Refaire les captures

Il faut Docker (images `mysql:8.4` et `qdrant/qdrant:v1.15.1`), PHP 8.3 ou plus avec `pdo_mysql` (le
serveur intégré suffit ; `vendor/` est versionné), Google Chrome, et un clone de my-docs à côté, outil
installé : `npx --yes pnpm@12.3.4 install --dir tools/captures`.

1. `docs/tech/captures/lancer.sh` — MySQL et Qdrant jetables (ports 3307 et 6335), migrations, données
   fictives, serveur PHP sur le 8190. Il tourne à côté de la pile de dev sans la toucher. Ctrl+C arrête
   tout. Un second banc, depuis un autre worktree, prend d'autres ports et d'autres noms de conteneurs :
   `CAPTURE_PORT`, `CAPTURE_MYSQL_PORT`, `CAPTURE_QDRANT_PORT`, `CAPTURE_MYSQL_NOM`,
   `CAPTURE_QDRANT_NOM` ; changer alors l'`ORIGINE` de la capture à prendre.
2. Depuis my-docs : `node tools/captures/capture.mjs mia --chemin ../MIA/myLLM` — toutes, ou
   `--capture <nom>` pour une seule.
3. Relire chaque image, puis la commiter avec le changement d'écran qui l'a rendue nécessaire.

## Les captures

| Capture | Ce qu'elle montre | Page |
|---|---|---|
| `ecran-principal` | Une conversation ouverte, douze numéros dans l'ordre du tableau | [Premiers pas](../../user/premiers-pas.md), « Se repérer dans MIA » |
| `menu-plus` | Le menu « + », volet Connecteurs déplié, Gmail allumé | [Utiliser un connecteur](../../user/fonctionnalites/connecteurs/utiliser-un-connecteur.md), et en tête de [Les connecteurs](../../user/fonctionnalites/connecteurs/index.md) |
| `choix-en-boutons` | Une réponse qui se termine par des choix en boutons | [Mener une conversation](../../user/fonctionnalites/conversations/mener-une-conversation.md), et en tête de [Les conversations](../../user/fonctionnalites/conversations/index.md) |
| `reunion-outlook` | Une réunion Outlook préparée par MIA — invités nommés, salle Everest réservée, réunion Teams, répétition — et sa carte d'approbation (conversation 110) | [Organiser une réunion dans Outlook](../../user/fonctionnalites/connecteurs/organiser-une-reunion.md), et le guide de l'assistant d'aide |
| `rechercher-ou-aller-a` | La barre « Rechercher ou aller à… » sur la recherche « cre » | [Gérer ses conversations](../../user/fonctionnalites/conversations/gerer-ses-conversations.md) |
| `assistants` | La page Assistants d'une créatrice : la recherche, les cartes de création, ses assistants en deux colonnes | En tête de [Les assistants](../../user/fonctionnalites/assistants/index.md) |
| `assistant-schema` | La page de « Fiches produits », descendue jusqu’à son schéma | [Créer un assistant](../../user/fonctionnalites/assistants/creer-un-assistant.md), « Lire le schéma d'un assistant » |
| `bases-de-connaissances` | La liste des bases : la recherche, les cartes de création, les quatre bases et leurs trois visibilités | En tête de [Les bases de connaissances](../../user/fonctionnalites/bases-de-connaissances/index.md) |
| `base-de-connaissances-schema` | La page de « Catalogue produits 2026 », descendue jusqu'à sa vue d'ensemble | [Créer une base de connaissances](../../user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md), « Lire le schéma de la base » |
| `workflow-editeur` | L'éditeur, « Trier les demandes reçues par email » et ses quatre voies | [Créer un workflow](../../user/fonctionnalites/workflows/creer-un-workflow.md), et en tête de [Les workflows](../../user/fonctionnalites/workflows/index.md) |
| `workflow-historique` | L'historique des exécutions, un lancement ouvert sur son journal | [Revoir l'historique](../../user/fonctionnalites/workflows/revoir-l-historique.md) |
| `usage-et-credits` | La page Usage & crédits, quota et consommation du mois | [Suivre sa consommation](../../user/fonctionnalites/credits/suivre-sa-consommation.md), et en tête de [La gestion des crédits](../../user/fonctionnalites/credits/index.md) |
| `fournisseurs-et-modeles` | La page Fournisseurs et modèles de l'administration | [Les modèles de LLM, côté administration](../../user/admin/modeles/index.md) |
| `office-jeton` | Réglages → Comptes connectés, la carte Claude dans Office juste après **Générer mon jeton** (quatre repères) | [Utiliser Claude dans Excel, Word ou PowerPoint](../../user/fonctionnalites/connecteurs/claude-dans-office.md), étape 1 |
| `office-connexion`, `office-passerelle` | L'add-in Claude dans PowerPoint : son écran d'accueil, puis l'écran Gateway rempli. **Pris à la main**, voir « Les écrans de l'add-in » | Même page, étapes 2 et 3 |

**Ces images servent aussi l'assistant « MIA : mode d'emploi ».** `docs/GUIDE-UTILISATEUR.md` les
cite sous `/aide/images/<nom>.png` (HelpImageController, qui lit `docs/user/images/`), et l'assistant
les recopie dans ses réponses : une image renommée ici se renomme aussi dans le guide —
`HelpImagesTest` le vérifie.

## Les fichiers

| Fichier | Rôle |
|---|---|
| `lancer.sh` | MIA en local, base et index vectoriel en mémoire, client Google factice |
| `hors-ligne.php` | Chargé avant chaque page : PHP n'ouvre plus aucune adresse `https://`. Avec le proxy fermé que pose `lancer.sh` pour curl, les clés d'API factices ne partent nulle part, même sur un clic malheureux |
| `donnees-fictives.php` | Tout le jeu de données, inventé ; il s'appuie sur le code de MIA (`scripts/_cli_bootstrap.php`) pour chiffrer les clés et reprendre ses modèles de workflows. Voir son en-tête |
| `<nom>.capture.mjs` | L'écran à préparer, ce qu'on masque, les numéros s'il y en a |
| `office-addin.mjs` | Recadre les deux écrans de l'add-in pris à la main et y pose les numéros |

Deux comptes, connectés sans mot de passe par une session PHP écrite d'avance : **Camille Martin**,
créatrice (cookie `myLLM_session=capturesdocumentation`), et **Antoine Lefèvre**, administrateur
(`capturesadministration`), pour les pages de l'administration.

## Quand l'écran change

- **Un numéro qui ne trouve plus sa cible arrête l'outil** : corriger sa `cible` — un identifiant ou un
  libellé stable ; le JavaScript réécrit certains `title` (le bouton des bases de connaissances).
- **Une zone ajoutée ou retirée** : le repère et la ligne du tableau de la page changent dans le même
  commit.
- **Une donnée à montrer** : elle s'ajoute à `donnees-fictives.php`, inventée. Aucune donnée copiée
  d'une vraie base, même « anonymisée ». Le script est rejouable : il efface puis réécrit tout ce qu'il
  a posé.
- **Les identifiants changent à chaque lancement** (workflows, bases, lancements) : une capture passe par
  la liste et clique, elle ne vise jamais `/workflows/3/edit`.

## Les écrans de l'add-in

`office-connexion.png` et `office-passerelle.png` montrent l'add-in **Claude** d'Anthropic, pas MIA :
il ne s'affiche que dans Office, l'outil de my-docs ne peut pas l'ouvrir. Ce sont de vraies captures,
prises à la main, sans aucune donnée réelle :

1. PowerPoint pour Mac, une présentation vierge, la fenêtre à 1600 × 900 points. L'add-in installé
   depuis Microsoft Marketplace (**Claude for Microsoft 365**), **jamais connecté** à un compte Claude.
2. Onglet **Accueil** → **Claude** : l'écran d'accueil. Capturer la fenêtre seule, en double densité :
   `screencapture -x -o -l <numéro de fenêtre> connexion.png`.
3. **Cloud provider or gateway** ; dans **Gateway URL**, l'adresse d'exemple
   `https://mia.example.com/office` (domaine réservé, comme sur `office-jeton`) ; dans **Token**, un
   texte quelconque, masqué à l'écran. **Ne pas cliquer sur Continue.** Capturer de même :
   `passerelle.png`.
4. Recadrer et numéroter : `MY_DOCS=<clone de my-docs> node docs/tech/captures/office-addin.mjs
   connexion.png passerelle.png`. Si l'add-in a bougé, corriger les coordonnées du script, puis relire
   chaque image.

Libellés relevés le 2026-11-09 (Office pour Mac 16.114) : **Log in**, **Sign up**, **Cloud provider or
gateway** ; puis **Connect another way**, onglets **Gateway**, **Vertex**, **Bedrock**, **Azure**, champs
**Gateway URL** et **Token**, bouton **Continue** — et non « API token » ni « Connect », comme le
laisse croire la documentation d'Anthropic.
