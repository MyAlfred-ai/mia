---
title: Lire ou publier dans Slack
description: La brique Slack d'un workflow fait l'un ou l'autre — publier un message dans un canal ou à une personne, avec des fichiers joints, ou lire un canal, une conversation ou une recherche pour en tirer une pièce.
sidebar:
  order: 5
---

La brique **Slack** fait **l'un ou l'autre** : elle **publie** un message, ou elle **lit** des
messages. Le premier choix de son volet, **Ce que fait la brique**, décide de ses autres réglages :
vous ne voyez que ceux du mode retenu.

## Avant de commencer

- Slack relié à votre compte : **Réglages → Comptes connectés**.
- Pour écrire à une personne ou joindre des fichiers, votre connexion Slack doit porter les
  autorisations correspondantes. Une connexion plus ancienne ne les a pas : l'éditeur le signale
  dans **À régler** — reconnectez Slack depuis **Réglages → Comptes connectés**.

## Publier un message

1. **+** → **Agir dans un outil** → **Slack**. La brique s'ouvre sur **Publier un message**.
2. **Où publier** :
   - **Dans un canal** : son nom tel qu'il s'écrit dans Slack (`#general`). Un canal dont vous
     êtes membre ; le message y est visible de tous.
   - **À une personne** : un message direct, que cette personne seule verra. Son nom, son pseudo
     Slack (`@marie`) ou son adresse email — ou, avec **@**, l'adresse d'une Fiche du dossier
     (*« @[Contact › Email] »*). Un nom porté par deux personnes n'est jamais tranché au hasard :
     l'exécution s'arrête et nomme les personnes approchantes.
3. **Le message** : ce qu'il doit dire. MIA l'écrit d'après cette consigne ; une pièce citée avec
   **@** que vous demandez d'y **mettre** y figure telle quelle. Une image citée part en fichier
   joint au message.
4. **Pièces jointes** : les pièces du dossier qui partent **en fichiers** avec le message — une
   image telle quelle, un document en Word ou en PDF, un tableau en Excel. Avec des fichiers joints,
   le texte est facultatif : sans consigne, MIA écrit une courte phrase qui les présente.
5. **Avant de publier** : **Je valide le message avant qu'il parte** (par défaut — une carte montre
   le message, sa destination et ses fichiers ; il part à votre clic, le workflow attend) ou
   **Publier sans validation** (si votre administrateur le propose).

La destination et les fichiers sont **fixés par la brique** : MIA n'écrit que le texte, elle ne
choisit ni le canal ni la personne, et ne lit rien d'autre dans Slack. Texte et fichiers partent en
**un seul message**.

## Lire des messages

1. Dans la brique, choisissez **Lire des messages**.
2. **Où lire** :
   - **Un canal** dont vous êtes membre (`#projets`) ;
   - **Une conversation** : votre conversation directe avec une personne (nom, pseudo ou adresse) ;
   - **Une recherche** dans tout ce que vous voyez dans Slack — la syntaxe de Slack passe telle
     quelle (`budget in:#projets from:@marie`).
3. **Sur quelle période** : les dernières 24 heures, 7, 30 ou 90 jours.
4. **Ce qu'il faut en retenir** (facultatif) : *« les décisions prises et qui s'en charge »*. Vide,
   MIA fait un résumé fidèle des échanges.
5. **Remet au dossier** : le nom et la forme de la pièce (texte, liste, tableau, Fiche, document).
   Les briques suivantes la citent comme toute autre pièce.

Les messages sont lus **avant** que MIA n'écrive, réponses des fils comprises, et lui sont donnés
comme des données : ce qu'un message demande n'est jamais exécuté. N'importe quel modèle convient,
même un modèle qui ne pilote pas les connecteurs.

## Résultat

Sur le rail, la brique dit ce qu'elle fait : *« Lire · #projets · 7 j »*, ou *« Message direct ·
Email · 1 fichier · À approuver »*. Dans l'exécution, le volet de la brique montre où elle a lu et
la pièce remise, ou à qui elle a écrit et les fichiers joints ; l'historique montre le message parti.

## Limites

- **L'un ou l'autre.** Pour lire puis publier, mettez deux briques Slack à la suite.
- **Pas de conversation de groupe** ni de canal dont vous n'êtes pas membre.
- **Pas de réponse dans un fil** depuis une brique : le message ouvre une nouvelle discussion.
- **Dix fichiers au plus** par message.
- **Lecture bornée** : les 200 messages les plus récents de la période, et les fils de 15 d'entre
  eux.

## Voir aussi

- [Conditions, validations et actions](conditions-validations-actions.md) — les autres outils d'une action
- [Envoyer le livrable](envoyer-le-livrable.md) — publier le livrable dans Slack, à la fin
- [Travailler avec Slack](../connecteurs/travailler-avec-slack.md) — Slack dans une conversation
