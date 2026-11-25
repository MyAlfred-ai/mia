---
title: Envoyer le livrable
description: Faire partir le livrable à la fin d'un workflow — par email avec ses pièces jointes, dans Google Drive, dans Slack, dans une base de connaissances ou dans une table.
sidebar:
  order: 11
---

À la fin d'un workflow, le livrable peut partir tout seul vers ceux qui l'attendent : un email avec
les documents en pièces jointes, un Google Doc rangé dans le bon dossier, un message dans un canal
Slack, un document ajouté à une base de connaissances, ou des lignes ajoutées ou mises à jour dans
une table.

## Avant de commencer

- Reliez le service concerné (Gmail, Outlook, Google Drive, Slack) dans **Réglages → Comptes connectés** :
  la livraison part avec **votre** compte.
- Pour une base de connaissances, il faut la **gérer** (l'avoir créée, ou en avoir reçu la gestion).
  Pour une table, il faut aussi que sa fiche permette l'écriture par MIA.

## Étapes

1. Dans l'éditeur, touchez le **livrable**, puis, sous **Puis le livrable part**, ajoutez une
   livraison :
   - **Envoyer par email** : la boîte, les destinataires (des adresses, ou celle d'un champ du
     dossier, par exemple l'expéditeur d'un email reçu), l'objet, le message, et les pièces à
     joindre. Citez une pièce avec **@** : son contenu est inséré dans le message.
   - **Enregistrer dans Google Drive** : les pièces et le dossier. Un tableau devient un Google
     Sheet, le reste un Google Doc.
   - **Publier dans Slack** : le canal, écrit comme dans Slack (`#general` ; un nom de canal n'a
     jamais d'espace), un message, et les pièces publiées à la suite.
   - **Ajouter à une base de connaissances** : la base, les pièces, et s'il faut **remplacer la
     version précédente** (sinon, un document daté par exécution).
   - **Écrire dans une table** : la table, la pièce (un Tableau, une Fiche ou un classeur), et
     comment l'écrire — ajouter les lignes, mettre à jour celles qui existent d'après une colonne
     (Email, Référence…), ou tout remplacer. Voir [Utiliser des tables dans un
     workflow](utiliser-des-tables.md).
2. Choisissez **comment il part** : **après votre accord** (une carte à approuver dans la
   conversation de l'exécution, la même que dans le chat), **en brouillon** (pour un email), ou
   **sans validation** si votre administrateur le permet.
3. **Publiez**.

## Ce qu'il faut savoir

- **Rien ne part avant la fin** : les livraisons ont lieu une fois le livrable prêt, dans l'ordre.
  Un document est joint dans son format (Word, PDF, Excel…), un tableau en classeur Excel.
- **Les créations** : une image est jointe à l'email (allégée si elle est lourde) et entre dans une
  base de connaissances, qui la décrit ; une page web part en fichier .html, un itinéraire en
  document. Drive et Slack la laissent de côté : ils ne reçoivent ici que des documents et du texte
  — voir [Créer une image, une page web ou une carte](creer-une-image-une-page-ou-une-carte.md).
- **Une livraison qui échoue** (service déconnecté, adresse refusée, canal Slack introuvable)
  arrête l'exécution sur son livrable, avec la cause : **Relancer les livraisons en échec** ne
  refait que celles-là, **Terminer sans elles** clôt l'exécution. Les autres livraisons gardent
  leur issue. Un canal Slack est cherché avant de publier : introuvable, rien n'est publié.
- **Une action ou une livraison, pas les deux** : si une action du workflow publie déjà dans
  Slack, une livraison vers Slack publie un second message — **À régler** le signale.
- **Limites** : cinq livraisons par workflow ; cinq pièces jointes et 3 Mo au plus par email.
- L'ajout à une base de connaissances la rend interrogeable par vos assistants dès sa lecture
  terminée.
