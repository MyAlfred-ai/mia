---
title: Classer, compléter et agir ligne par ligne
description: Faire lire chaque ligne d'une table ou d'une liste par MIA — classer, résumer, relever une valeur — puis préparer un brouillon ou une tâche pour chacune, en une seule approbation.
sidebar:
  order: 4
---

Sur une liste établie par MIA ([Recouper beaucoup d'éléments](recouper-des-sources.md)) ou
directement sur une **table permanente** cochée pour la conversation, MIA peut aller plus loin que
compter :
**lire chaque ligne** pour y ajouter ce qui demande du jugement, puis **agir sur chacune** — un
brouillon d'email, une tâche par ligne. Rien n'est créé sans votre accord, et une seule carte suffit
pour toutes les lignes.

## À quoi ça sert

- *« Pour chacun de ces mails, dis si c'est une réclamation et sur quel produit. »*
- *« Résume chaque ligne en une phrase et relève le montant dû. »*
- *« Prépare un brouillon de relance pour chaque client en retard. »*
- *« Crée une tâche de rappel pour chaque dossier de la liste. »*

## Avant de commencer

- Une table est là : une collecte de mails ou de fichiers, les lignes d'un classeur, ou une table
  permanente cochée pour la conversation (ou une vue de celle-ci : *« les clients de Lyon »*).
- Pour préparer des **brouillons** : Gmail ou Outlook est relié et allumé dans le menu **+**. Pour
  des **tâches** : Google Tasks ou Microsoft To Do. Voir
  [Utiliser un connecteur](utiliser-un-connecteur.md).

## Faire lire chaque ligne

1. Dites ce qu'il faut établir **pour chaque ligne** : *« dans la table des mails, ajoute une colonne
   Réclamation (oui ou non) et une colonne Produit »*. Vous pouvez imposer les valeurs possibles :
   *« classe chaque ligne en Urgent, Normal ou À ignorer »*.
2. Jusqu'à **45 lignes**, c'est fait pendant la réponse : la pile d'actions affiche
   « Enrichissement d'une table — « mails » (40 lignes enrichies) ».
3. Au-delà, MIA vous propose une **opération en arrière-plan** dans une carte : le nombre de lignes,
   ce qui sera rempli, le **coût estimé** en crédits et la durée. Cliquez sur **Approuver** pour la
   lancer.
4. L'avancement s'affiche sous le message de lancement ; vous pouvez continuer à travailler dans la
   conversation. Le **bilan** arrive dans le fil quand c'est terminé.
5. Demandez ensuite ce que vous voulez de ces colonnes : *« combien de réclamations par produit ? »*,
   *« exporte les urgents en Excel »*.

Les nouvelles colonnes s'ajoutent à la table. Une valeur que la ligne ne permet pas d'établir reste
vide : MIA n'invente pas.

**Sur une table permanente**, MIA ne fait aucune copie : elle calcule les valeurs, puis une
**carte** propose de les écrire dans votre table (les lignes, les valeurs avant et après). Rien n'y
est écrit avant votre accord, et le changement s'inscrit dans l'historique de la table. Pour une
opération en arrière-plan, cette carte arrive avec le bilan. Si la table est en lecture seule pour
MIA, les colonnes s'ajoutent à un résultat « Clients 2026 — enrichie », tiré d'elle, et MIA vous le
dit. Une action par ligne (un brouillon, une tâche pour chacun) s'applique de même directement à
votre table ou à l'une de ses vues : votre table ne reçoit aucune colonne de statut.

## Agir sur chaque ligne

1. Décrivez l'action une fois, pour toutes les lignes : *« prépare un brouillon pour chaque client
   de la table : objet "Relance facture" suivi du numéro, et un message court qui rappelle le
   montant »*.
2. MIA prépare une action **par ligne**, puis vous présente **une seule carte** : combien d'actions,
   un exemple complet, la liste de toutes les lignes, et celles qu'elle a écartées avec la raison
   (une adresse manquante, par exemple).
3. Cliquez sur **Approuver** : les actions sont créées en arrière-plan, une par une. **Annuler** n'en
   crée aucune.
4. Le bilan arrive dans la conversation. Chaque ligne de la table porte son résultat — `fait`, ou la
   raison de l'échec — et le lien vers ce qui a été créé.

Ce que MIA peut créer ainsi :

| Action | Ce qui est créé |
|---|---|
| **Brouillon Gmail**, **brouillon Outlook** | Un brouillon par ligne, dans votre boîte. **Aucun email n'est envoyé** : vous relisez et envoyez vous-même. |
| **Tâche Google**, **tâche Microsoft To Do** | Une tâche par ligne, dans votre liste. |

## Arrêter, reprendre

- **Arrêter** : ouvrez les **générations en cours** (en bas de la barre latérale) et cliquez sur
  **Arrêter**. Ce qui est fait reste fait ; le bilan dit ce qui reste.
- **Reprendre** : redemandez la même chose. Seules les lignes encore vides sont lues ; une ligne
  dont l'action a déjà abouti n'est pas refaite — pas de brouillon en double.

## Limites

- Une action groupée porte sur **200 lignes** au plus. Au-delà, demandez d'abord à MIA de réduire la
  table (un filtre), ou procédez en plusieurs fois.
- Six colonnes au plus par enrichissement.
- Une opération en arrière-plan **s'arrête d'elle-même** si elle dépasse le plafond de crédits
  annoncé sur sa carte, ou si votre quota est atteint. Relancez-la pour continuer.
- Une seule opération à la fois par conversation.
- Si vous faites modifier la **même table** pendant l'opération (un tri, un filtre qui la remplace),
  l'opération s'arrête sans rien écraser : relancez-la sur la table telle qu'elle est devenue.
- Les emails restent des **brouillons** : MIA n'envoie rien en nombre. Elle ne publie pas non plus de
  message Slack ou Teams ligne par ligne, et n'envoie pas d'invitation.
- MIA lit, pour chaque ligne, ce que la table contient : l'**extrait** d'un mail, pas son texte
  entier.
- Ces opérations ne se lancent pas depuis une tâche programmée ni un workflow.

## En cas de problème

- **« Une opération est déjà en cours »** : attendez le bilan de la précédente, puis approuvez la
  carte.
- **Beaucoup de lignes écartées** : la colonne citée est vide pour ces lignes (pas d'adresse, pas de
  nom). Complétez la table, ou demandez à MIA de retirer cette information du message.
- **Des lignes « en échec »** : la raison est notée dans la table (connexion expirée, adresse
  refusée). Corrigez, puis redemandez l'action : seules ces lignes seront reprises.
- **Les valeurs ne conviennent pas** : précisez la consigne et demandez de **refaire** la colonne.

## Voir aussi

- [Recouper beaucoup d'éléments](recouper-des-sources.md)
- [Utiliser un connecteur](utiliser-un-connecteur.md)
- [Suivre sa consommation](../credits/suivre-sa-consommation.md)
