---
title: Faire créer un assistant par MIA
description: Décrire l'assistant voulu dans le chat — MIA propose consignes, modèle, bases, outils et avatar, vous approuvez ou ajustez.
sidebar:
  order: 4
---

Réservé aux comptes **Créateur** et aux administrateurs. Plutôt que de remplir la fiche vous-même,
décrivez dans une conversation l'assistant dont vous avez besoin : MIA le compose et vous le montre
dans une **carte**. Rien n'est créé avant que vous l'approuviez.

## Avant de commencer

- Le droit de créer des assistants — le rôle **Créateur** ou administrateur, par défaut —, et une
  place libre parmi vos **10 assistants personnels**.
- Un modèle qui sait utiliser des outils pour la conversation : avec un modèle qui n'en a pas, MIA
  vous invite à en changer.

## Étapes

1. Dans le chat, demandez-le simplement : *« Crée-moi un assistant qui prépare mes rendez-vous
   clients à partir de notre catalogue »*. Dites pour qui il travaille, ce qu'il doit faire, sur
   quelles sources, et le ton voulu ; si la demande est floue, MIA vous pose une question.
2. MIA propose l'assistant dans une carte **Créer un assistant** :
   - son **nom**, son **avatar** ou la pastille de sa **couleur**, sa **description** ;
   - son **modèle** et sa **température** ;
   - les **bases de connaissances** et les **outils** qu'il utilisera ;
   - son **prompt système**, à déplier, rédigé dans les six sections habituelles — voir
     [Rédiger les consignes d'un assistant](rediger-les-consignes-d-un-assistant.md).
3. Relisez, puis choisissez :
   - **Approuver** : l'assistant est créé tel quel ;
   - demander un changement dans la conversation — *« mets-le en vert »*, *« retire la base RH »*,
     *« plus formel »* : MIA présente une nouvelle carte et la précédente se grise, marquée
     « Remplacée par la nouvelle proposition ci-dessous » ;
   - **« Ajuster »** : la fiche complète de création s'ouvre, déjà remplie. Corrigez ce que vous
     voulez, ajoutez des documents de référence, puis **Créer** ; la carte se ferme d'elle-même ;
   - **Annuler** : rien n'est créé.

## Ce que MIA choisit pour vous, et dans quelles limites

MIA ne propose que ce que vous pourriez choisir vous-même dans la fiche :

- les **modèles** que vos groupes vous autorisent — le plus souvent le modèle de l'application,
  qu'il suivra s'il change ; un autre seulement si la mission le demande (analyse d'images,
  raisonnement poussé, réponses simples et fréquentes) ;
- les **bases de connaissances** de votre périmètre — les vôtres et celles qu'on vous a
  partagées —, et seulement celles qui servent la mission ;
- les **outils** de l'instance, s'ils lui sont utiles ;
- un **avatar** de la bibliothèque, ou un avatar **créé pour lui** quand aucun ne convient ou que
  vous le demandez (*« avec une chouette comme avatar »*).

Au clic sur **Approuver**, vos droits sont vérifiés de nouveau : un modèle ou une base qui ne vous
est plus ouvert entre-temps empêche la création, et la carte le dit.

## Résultat

La carte confirme **Assistant créé**, avec un lien vers sa fiche et un bouton **Discuter avec lui**
qui ouvre une conversation avec l'assistant. Comme tout assistant, il est **personnel** : vous seul
le voyez dans le chat jusqu'à ce qu'un administrateur le partage. Il rejoint la liste des
assistants de la zone de saisie et celle de vos assistants.

Ses **documents de référence** s'ajoutent ensuite depuis sa fiche — voir
[Créer un assistant](creer-un-assistant.md).

## Le coût

La rédaction de la proposition est un message de chat comme un autre. Un **avatar créé** est une
image générée : elle est décomptée de vos crédits, même si vous n'approuvez pas l'assistant. Une
retouche qui ne touche pas l'avatar le reprend tel quel, sans nouvelle image.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| MIA répond qu'elle ne peut pas créer d'assistant | Votre rôle ne permet pas de créer des assistants : demandez ce droit à un administrateur. |
| « Vous avez déjà 10 assistants personnels… » | Supprimez un assistant, puis cliquez de nouveau **Approuver** : la carte attend. |
| « Modèle non permis pour votre compte » ou « Base de connaissances hors de votre périmètre » | Vos droits ont changé depuis la proposition : demandez une nouvelle proposition à MIA. |
| MIA propose un avatar de la bibliothèque alors que vous en vouliez un créé | Aucun outil de génération d'image ne vous est ouvert : choisissez dans la bibliothèque, ou demandez l'outil à un administrateur. |
| La carte a disparu au rechargement | Une proposition expire au bout de 30 minutes sans décision : redemandez-la. |
