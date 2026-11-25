---
title: Voir l'historique d'une table et revenir en arrière
description: Savoir qui a créé et modifié une table, quand et comment, et la remettre dans l'état d'un moment précédent — sans rien perdre.
sidebar:
  order: 8
---

Chaque **table** garde son **historique** : sa création, puis chaque changement — un fichier
importé, des cellules corrigées, une colonne supprimée, une modification faite par MIA ou par un
workflow. On voit qui a fait quoi, et quand, et on peut remettre la table **dans l'état d'un moment
précédent**. Revenir en arrière n'efface rien : l'état quitté reste dans l'historique.

## À quoi ça sert

- Savoir d'un coup d'œil quand la table a été créée et modifiée pour la dernière fois, et par qui.
- Retrouver ce qu'a changé une modification de MIA, un import ou un workflow.
- Annuler une erreur : un mauvais fichier importé, une colonne supprimée par mégarde.

## Avant de commencer

- Voir la table suffit pour lire son historique et regarder un état passé.
- Revenir en arrière demande de pouvoir **modifier** la table (son créateur, un administrateur, ou
  une personne à qui elle est partagée avec ce droit).

## Voir l'historique

1. Ouvrez la table : tuile **Bases** de la barre latérale, puis la table dans la liste.
2. Sous son nom, la page dit **Créée le … par …** et, si elle a changé depuis, **Modifiée il y a …
   par …**.
3. Cliquez **Historique**, en haut à droite. Un volet s'ouvre : les états de la table, du plus récent
   au plus ancien, rangés par jour. Chacun dit **l'heure**, **qui** (vous, une personne, MIA ou un
   workflow) et **ce qui a changé**, en une ligne. Le premier porte **Maintenant** : c'est la table
   en service.

Les retouches que vous faites à la main d'affilée — corriger des cellules, ajouter ou supprimer des
lignes, renommer une colonne — forment **un seul état** tant que vous y travaillez (un quart
d'heure) : l'historique dit par exemple *« 3 cellules corrigées, 1 ligne ajoutée »*.

## Regarder un état passé

1. Dans le volet, cliquez un état. Il se déplie : ce qu'il était, et ce qui diffère de maintenant
   (*« 1 ligne de moins, 4 cellules différentes par rapport à maintenant »*).
2. La grille de la page montre la table **telle qu'elle était à ce moment**. Les cellules qui ont
   changé depuis sont surlignées : survolez-en une pour lire sa valeur actuelle. Une ligne qui
   n'existe plus aujourd'hui est légèrement teintée : elle reviendrait avec cet état.
3. Un bandeau au-dessus de la grille rappelle de quel moment il s'agit. **Revenir au présent**
   ramène la table en service ; toucher de nouveau l'état dans le volet aussi.

Rien n'est modifié tant que vous regardez.

## Revenir à un état

1. Ouvrez l'état voulu, puis cliquez **Revenir à cet état** — dans le volet ou dans le bandeau.
2. Une fenêtre dit ce que le retour change (*« La table repasse à 120 lignes et 4 colonnes »*).
   Confirmez par **Revenir à cet état**.
3. La page se recharge, la table dans l'état choisi. Le retour s'inscrit en tête de l'historique
   (*« Retour à l'état du 17 nov. à 9 h 12 »*) ; l'état que vous venez de quitter y reste.

**Vous vous êtes trompé ?** La notification qui suit propose **Annuler**, et le volet **Annuler ce
retour**, pendant un quart d'heure : la table reprend l'état d'avant, et le retour disparaît de
l'historique. Passé ce délai — ou si quelqu'un a modifié la table depuis —, revenez simplement à
l'état voulu depuis l'historique.

## Limites

- L'historique garde les états des **90 derniers jours** — au moins les 30 plus récents, quel que
  soit leur âge, et 60 au plus. Au-delà, un état disparaît. La **création** reste toujours inscrite,
  mais une fois trop ancienne, son contenu n'est plus conservé : on ne peut plus y revenir.
- Revenir en arrière remet les **lignes et les colonnes** de l'état choisi. Le nom de la table, sa
  description, qui la voit et ce que MIA peut y faire ne changent pas.
- Les heures s'affichent dans le fuseau de votre navigateur.

## En cas de problème

- **« Cet état n'est plus conservé : on ne peut plus y revenir »** : il dépasse la durée de
  conservation. Choisissez un état plus récent.
- **« Ce retour en arrière ne peut plus être annulé »** : le quart d'heure est passé, ou la table a
  été modifiée depuis. Revenez à l'état voulu depuis l'historique.
- **Je ne vois pas Revenir à cet état** : vous n'avez pas le droit de modifier la table, ou l'état
  ouvert est celui en service.

## Voir aussi

- [Créer et tenir une table](tenir-une-table.md)
- [Consulter une base](consulter-une-base.md)
