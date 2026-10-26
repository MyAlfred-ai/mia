---
title: Laisser MIA choisir la voie
description: Une brique « Décision de MIA » lit une pièce, suit vos critères et choisit la suite du workflow parmi plusieurs scénarios.
sidebar:
  order: 3
---

Une **décision de MIA** est un aiguillage : MIA lit une ou plusieurs pièces du dossier, suit les
critères que vous lui donnez, et choisit **une** voie parmi celles que vous décrivez — deux à six.
Chaque voie a ses propres briques. C'est la brique à prendre quand il y a plus de deux suites
possibles, ou quand la question n'a pas de réponse oui/non : *« demande de devis, réclamation ou
simple question ? »*.

## Avant de commencer

La décision s'appuie sur des pièces remises **avant** elle (le départ, une étape, l'email reçu…).
Choisir une voie consomme quelques crédits, comme une question à MIA. Pour un choix calculé sans IA
(un montant, une date), préférez une **condition par règle** — voir [Conditions, validations et
actions](conditions-validations-actions.md).

## Étapes

1. **+** sur le rail → **Décision de MIA** (rangée sous **Intelligence**).
2. **Sur quoi MIA choisit** : vos critères, en citant les pièces avec **@** — *« Lis @[Email reçu] et
   choisis selon ce que demande le client. »*
3. **Les voies** : donnez à chacune un **nom** (*Devis*, *Réclamation*, *Question*) et décrivez
   **quand MIA la prend** (*« la demande porte sur un prix »*). Ajoutez-en jusqu'à six, réordonnez-les,
   et choisissez pour chacune **puis reprendre le rail** ou **puis arrêter le workflow**.
4. Sur le rail, ajoutez les briques de chaque voie avec **+ Ajouter**, dans sa colonne : les voies
   s'ouvrent côte à côte sous la décision, de même largeur, chacune de sa couleur, avec son numéro
   et son nom en tête. Trois voies ou plus élargissent le rail ; s'il ne tient pas à l'écran, il
   défile de côté.
5. **Si MIA ne sait pas choisir** : **Me demander** (par défaut), ou une voie à prendre sans
   attendre.

## Ce qu'il faut savoir

- **MIA choisit par le nom et la description des voies** : plus elles sont claires et distinctes,
  plus le choix est sûr. Sa raison, en une phrase, s'écrit dans la conversation de l'exécution et
  dans le détail de la brique.
- **Quand MIA hésite et qu'elle vous demande**, le workflow attend : la page de l'exécution vous
  montre ce qu'elle a vu et **toutes les voies**, la plus proche signalée ; un clic choisit. Dans la
  conversation, la carte prend la voie la plus proche (**Approuver**) ou arrête le workflow
  (**Annuler**). Le détail de la brique dit ensuite que la voie a été **choisie par vous**.
- **Une pièce remise dans une voie** n'est disponible après la décision que si toutes les autres
  voies arrêtent le workflow — sinon, rien ne garantit qu'elle existe.
- Une voie ne contient ni décision, ni condition, ni boucle. Une décision ne s'ignore pas en cas
  d'erreur : elle peut réessayer seule, puis l'exécution s'arrête et se reprend d'un clic.
