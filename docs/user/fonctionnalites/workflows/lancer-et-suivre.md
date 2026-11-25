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
lancement vérifie votre quota de crédits, et que le workflow pourra aller au bout : un compte à relier
(Gmail pour l'email de remise, Google Drive pour une étape…), un outil retiré ou un modèle disparu
s'affichent dans la fenêtre de lancement sous **À régler avant de lancer**, avec un lien **Relier**.
Tant qu'il en reste, **Lancer** est grisé ; reliez le compte (dans un autre onglet), puis **Vérifier à
nouveau**. Rien n'est lancé ni facturé pour un workflow qui s'arrêterait en route.

## Lire la liste

Chaque vignette de **Mes workflows** porte, en haut, le déroulé du workflow tracé comme une ligne de
métro, à sa couleur, et dessous **Quand** il démarre et le **Résultat** qu'il produit. La pastille
d'état dit où il en est ; une exécution qui vous attend passe en premier, et l'étape concernée est
cerclée sur la ligne. Toucher la vignette ouvre le workflow, la pastille ouvre l'exécution ; la
corbeille, en bas à droite, le supprime après confirmation ([Supprimer un workflow](supprimer-un-workflow.md)).

## Étapes

### Depuis la liste ou l'éditeur

1. **Workflows** → **Lancer** sur la carte du workflow (ou **Lancer** dans l'éditeur).
2. Une fenêtre montre à gauche les champs du départ — déposez les fichiers demandés —, à droite **ce
   qui va se passer** : les briques dans l'ordre, les validations qui vous attendront et le coût
   estimé (voir *Ce que coûte une exécution*, plus bas). Quand une boucle parcourt le fichier déposé (ses lignes, ses slides, ses pages, ses
   sections), la fenêtre le lit aussitôt et dit **combien de tours** elle fera, les éléments laissés
   par le filtre et les premiers d'entre eux ; le coût est recalculé. Au-delà de cinquante tours,
   **Lancer** demande confirmation.
3. **Lancer** : la **conversation de l'exécution** s'ouvre, avec son volet **Avancement** (voir
   *Suivre une exécution*). Un champ Fichier qui porte un document de référence est déjà rempli
   (« Fourni par le workflow : … ») : déposez un autre fichier pour le remplacer cette fois-ci.

### Ce que coûte une exécution

La fenêtre de lancement et les **Réglages** de l'éditeur donnent :

- « ≈ *N* crédits par exécution », et une fourchette : une réponse plus longue ou une pièce plus
  lourde que d'habitude coûte plus ;
- ce qu'ont **réellement** coûté les dernières exécutions terminées de ce workflow ;
- **Détail de l'estimation**, à déplier : chaque brique, son coût et ce sur quoi il repose — les
  étapes déjà menées par son modèle, les dernières générations de son outil, ou son tarif quand il
  n'y a pas encore d'historique. Une boucle compte par tour ; d'un embranchement, la voie la plus
  chère est comptée.

Ne sont pas comptés : les nouveaux essais après une panne, les pièces renvoyées par une validation
et les recherches Internet en plus. Après coup, **Réglages → Usage & crédits** donne, workflow par
workflow, ce qui a été consommé ce mois-ci.

### Depuis le chat

Si le workflow est réglé **Depuis le chat**, tapez **@** dans la zone de saisie : il apparaît sous
**Lancer un workflow**, après les assistants. Les fichiers joints dans la zone de saisie deviennent
ses fichiers de départ, et le texte saisi remplit son premier champ texte.

### Programmé

Un workflow réglé **À heure fixe** démarre seul, à l'heure dite, avec les valeurs par défaut de ses
champs ; un créneau manqué de plus de douze heures n'est pas rattrapé. S'il ne peut pas aller au bout
(un compte à relier…), il ne démarre pas et vous êtes prévenu sur vos appareils ; le créneau suivant
partira une fois le point réglé. Un workflow lancé par un email ou un fichier attend de même, et sa
surveillance dit pourquoi dans l'éditeur.

## Suivre une exécution dans sa conversation

Un workflow lancé se suit **dans sa conversation**, comme un échange avec MIA :

- **Le volet Avancement**, à droite (au téléphone, le bouton **Avancement** en haut du fil) : les
  étapes une à une — faites (en vert), en cours, qui vous attendent, à venir —, la voie prise par
  chaque condition dans sa couleur, le nombre d'éléments traités d'une boucle et les crédits
  consommés. **✕** le masque, **Avancement** le rouvre.
- **Ce qui vous attend** est rappelé en haut du volet : **Voir la carte** fait défiler jusqu'à la
  validation ou aux actions à approuver, qui se décident **dans la conversation**. Sur la carte d'une
  validation, **Renvoyer avec une remarque** fait refaire l'étape ; quand MIA hésite sur une décision,
  **Prendre une autre voie** propose chacune des voies.
- **Après une erreur** (un compte non relié, un crédit épuisé), le volet dit la cause et propose
  **Reprendre** : ce qui est fait n'est pas refait.
- Les réponses des étapes et les messages du workflow s'ajoutent au fil, sans recharger la page.
- **Suivi détaillé** ouvre la page de l'exécution (le rail complet, le dossier, les corrections à la
  main) ; **Arrêter** interrompt l'exécution.

## La page de l'exécution

- **Le rail s'anime** : la brique en cours est cerclée de bleu, celle qui vous attend d'orange, celles
  qui sont faites portent leur durée. Une condition (ou une décision de MIA) affiche sa réponse et sa
  raison, dans la couleur de la voie qu'elle a prise ; la voie prise porte « voie prise » et une
  liaison plus épaisse, les autres « écartée » et s'estompent. Ce qui est fait (« Lancé », la durée
  d'une brique, « Livré ») est en vert.
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
  reste). Celles qui vous attendent sont rappelées en tête de la page Workflows. Dans l'historique de
  la barre latérale, la conversation d'une exécution porte l'**icône des workflows** à gauche de son
  titre.
- **Toutes vos exécutions**, passées et en cours, sont dans **Workflows → Historique des exécutions**,
  chacune avec son journal ; le bouton **Lancements** de l'éditeur (ou **Historique** sur la
  page de l'exécution) y mène directement : cf. [Revoir l'historique des exécutions](revoir-l-historique.md).
