---
title: Créer un fichier de tout format
description: La brique « Un fichier » du groupe Créer fait écrire par une étape un fichier du format de votre choix — script Python, Markdown, JSON, CSV, HTML, ou toute autre extension de fichier texte.
sidebar:
  order: 4
---

La brique **Un fichier** fait écrire par MIA, ou par un de vos assistants, un **fichier prêt à
l'emploi** dans le format que vous choisissez : un script Python qui nettoie un export, une note en
Markdown, un fichier JSON de configuration, un CSV d'import, une page HTML, une requête SQL… Le
fichier devient une **pièce** du dossier : vous le téléchargez tel quel, une validation le relit, une
livraison le joint à un email ou le publie dans Slack.

C'est une **étape** comme les autres : mêmes réglages (qui fait le travail, consigne, pièces lues,
sources consultées, relecture, reprise sur erreur), avec en plus le **type de fichier**.

## Étapes

1. **+** sur le rail → sous **Créer** : **Un fichier**. La brique s'ajoute, titrée « Créer un
   fichier ».
2. Dans son volet, **Remet au dossier** → **Forme** : choisissez le **type de fichier**.
   - **Documents** : Word, PDF, PowerPoint, Excel, Markdown — rédigés, puis mis en forme.
   - **Fichiers** : Python, JavaScript, TypeScript, JSON, CSV, HTML, CSS, SQL, Shell, YAML, XML,
     Texte, PHP, PowerShell, iCalendar, LaTeX — écrits tels quels.
   - **Autre extension…** : tapez l'extension d'un autre fichier texte (`conf`, `env`, `rb`, `go`…).
3. Donnez un **nom** à la pièce : c'est aussi le nom du fichier (« Nettoyage » → `Nettoyage.py`).
4. Écrivez la **consigne** : ce que le fichier doit faire ou contenir — son but, ses entrées, ses
   règles. Tapez **@** pour citer une pièce du dossier (« un script qui lit les colonnes de
   @[Export] et retire les doublons »).
5. Réglez le reste comme pour une étape : **Qui fait le travail**, ce qu'elle **peut consulter**,
   **Je relis la pièce avant la suite** pour la vérifier ou la corriger avant d'aller plus loin.

## À savoir

- Un fichier se fabrique **à partir du texte** : MIA ne crée ni image, ni archive (.zip), ni
  programme exécutable (.exe), ni ancien format Office. Pour une image, une page web mise en page ou
  une carte, utilisez les autres entrées du groupe **Créer**.
- L'étape écrit le **contenu exact** du fichier : vérifiez un script avant de l'exécuter, comme tout
  code que vous n'avez pas écrit vous-même.
- Sur la page de l'exécution, le fichier s'affiche tel quel et se télécharge dans son format. À la
  relecture, **Corriger** permet de le modifier à la main.
- Enregistré dans Google Drive, un fichier de code devient un Google Doc qui garde le contenu en bloc
  de code ; pour le garder dans son format, joignez-le plutôt à un email ou à un message Slack.
