---
title: Lancer et suivre un workflow
description: Lancer un workflow publié depuis sa page ou depuis le chat, suivre son exécution en direct et récupérer le livrable.
sidebar:
  order: 6
---

Un workflow **publié** se lance à la main, depuis le chat, ou tout seul s'il est programmé. Son
exécution se suit en direct, brique par brique.

## Avant de commencer

Le workflow doit être publié et actif (le bouton pause **‖** de sa vignette l'empêche de démarrer). Le
lancement vérifie votre quota de crédits.

## Lire la liste

Chaque vignette de **Mes workflows** porte, en haut, le déroulé du workflow tracé comme une ligne de
métro, à sa couleur, et dessous **Quand** il démarre et le **Résultat** qu'il produit. La pastille
d'état dit où il en est ; une exécution qui vous attend passe en premier, et l'étape concernée est
cerclée sur la ligne. Toucher la vignette ouvre le workflow, la pastille ouvre l'exécution.

## Étapes

### Depuis la liste ou l'éditeur

1. **Workflows** → **Lancer** sur la carte du workflow (ou **Lancer** dans l'éditeur).
2. Une fenêtre montre à gauche les champs du départ — déposez les fichiers demandés —, à droite **ce
   qui va se passer** : les briques dans l'ordre, les validations qui vous attendront et le coût
   estimé. Quand une boucle parcourt le fichier déposé (ses lignes, ses slides, ses pages, ses
   sections), la fenêtre le lit aussitôt et dit **combien de tours** elle fera, les éléments laissés
   par le filtre et les premiers d'entre eux ; le coût est recalculé. Au-delà de cinquante tours,
   **Lancer** demande confirmation.
3. **Lancer** : la page de l'exécution s'ouvre.

### Depuis le chat

Si le workflow est réglé **Depuis le chat**, tapez **@** dans la zone de saisie : il apparaît sous
**Lancer un workflow**, après les assistants. Les fichiers joints dans la zone de saisie deviennent
ses fichiers de départ, et le texte saisi remplit son premier champ texte.

### Programmé

Un workflow réglé **Programmé** démarre seul, à l'heure dite, avec les valeurs par défaut de ses
champs ; un créneau manqué de plus de douze heures n'est pas rattrapé.

## Suivre une exécution

- **Le rail s'anime** : la brique en cours est cerclée de bleu, celle qui vous attend d'orange, celles
  qui sont faites portent leur durée. Une condition (ou une décision de MIA) affiche sa réponse et sa
  raison ; la voie prise porte « voie prise » et une liaison plus épaisse, les autres « écartée » et
  s'estompent.
- **En tête** : ce qui vous attend (une validation, des actions à approuver dans la conversation, la
  liste des éléments d'une boucle ou ses actions préparées à relire), la cause d'un arrêt, ou les
  pièces livrées. Une boucle dont des éléments ont échoué propose **Refaire les éléments en échec** :
  voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
- **Touchez une brique** : un volet montre qui l'a faite, sa consigne, les pièces qu'elle a lues et
  celle qu'elle a remise ; pour une action (« Préparer ou envoyer un email »…), **Ce qui a été fait** :
  chaque action, son sort (un email **envoyé** ou rangé en **brouillon**) et **Voir l'email** pour le
  relire en entier (destinataires, objet, message, pièces jointes).
- **Dossier** (en haut) ouvre toutes les pièces, à lire et à télécharger (Word, PDF, Markdown ;
  Excel ou CSV pour un tableau) ; une page web s'y affiche dans un cadre isolé.
- Les **livraisons** du livrable (en tête, une fois l'exécution livrée) se déplient de même : l'email
  parti, le message publié. À la relecture des actions préparées par une boucle, chaque email se
  déplie en entier avant que vous ne le laissiez partir.
- **Vous pouvez quitter la page** : l'exécution continue sur le serveur. Avec **Me prévenir sur mes
  appareils**, une notification annonce chaque validation attendue, la fin ou un arrêt.
- **Conversation** ouvre le fil de l'exécution ; **Arrêter** l'interrompt (ce qui a été produit
  reste). Celles qui vous attendent sont rappelées en tête de la page Workflows.
- **Toutes vos exécutions**, passées et en cours, sont dans **Workflows → Historique des exécutions**,
  chacune avec son journal ; le bouton **Historique** (en haut de la page du workflow ou de
  l'exécution) y mène directement : cf. [Revoir l'historique des exécutions](revoir-l-historique.md).
