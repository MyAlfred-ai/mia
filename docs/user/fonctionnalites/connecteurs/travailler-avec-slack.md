---
title: Travailler avec Slack
description: Faire résumer un canal ou un fil, retrouver un message, et publier dans Slack en votre nom après approbation — dans un canal ou à une personne.
sidebar:
  order: 4
---

Une fois votre Slack relié et l'interrupteur **Messages Slack** allumé, MIA cherche et lit vos
conversations, et publie à votre demande. Elle ne voit **que ce que vous voyez** : vos canaux
publics, les canaux privés dont vous êtes membre, et vos messages directs.

## Avant de commencer

- Slack relié : [Relier un service](relier-un-service.md).
- L'interrupteur **Messages Slack** allumé dans le volet **Connecteurs** du menu **+** de la
  conversation.
- Un modèle qui sait piloter les connecteurs — le bandeau affiche **✓ Connecteurs**. Sinon, MIA vous
  propose la liste des modèles compatibles au moment où vous allumez l'interrupteur.

## Retrouver et résumer

Demandez en langage normal. MIA choisit elle-même entre chercher partout et lire un canal précis.

| Vous demandez… | Ce que MIA fait |
|---|---|
| *« Qu'a-t-on dit du budget sur Slack ? »* | Cherche dans tout ce que vous pouvez lire |
| *« Résume #projet-x depuis hier »* | Lit le canal, du plus ancien au plus récent |
| *« Déroule le fil du message de Paul sur la livraison »* | Lit le message d'origine et toutes ses réponses |
| *« Retrouve les messages de Marie en juillet »* | Cherche avec les filtres de Slack |

La syntaxe de recherche de Slack passe telle quelle : `in:#canal`, `from:@paul`, `during:july`,
`has:link`, ou une `"expression exacte"`. Un canal se désigne par son nom (`#projet-x`), et un lien
Slack collé dans le message fait aussi l'affaire.

Chaque message rapporté est cité avec son **auteur**, sa **date** et son **lien** : un clic vous
ramène dans Slack, au bon endroit. Les mentions (`@`, `#`) sont remplacées par des noms lisibles.

## Publier un message

1. Demandez : *« Préviens #projet-x que la livraison passe à jeudi »*, ou *« Réponds dans ce fil que
   je m'en occupe »*.
2. MIA rédige le message et affiche une **carte d'approbation** : le canal visé, l'aperçu du texte,
   et le rappel de ce qui suit.
3. Cliquez **Approuver**.

**Un message Slack n'est pas un brouillon.** Contrairement à Gmail et à Outlook, où MIA prépare un
brouillon que vous relisez, un message Slack approuvé **part immédiatement** et devient visible de
tous les membres du canal. MIA ne peut ensuite ni le modifier ni le supprimer : c'est à vous de le
faire dans Slack.

**Annuler** ne publie rien. Sans réponse de votre part, la carte expire au bout de 30 minutes.

## Écrire à une personne

Demandez : *« Écris à Marie sur Slack que la réunion est déplacée »*. MIA retrouve la personne par
son nom, son pseudo (`@marie`) ou son adresse email, et la carte d'approbation la nomme : le message
lui arrive en **message direct**, elle seule le voit. Si le nom désigne plusieurs personnes, MIA vous
demande laquelle plutôt que de choisir.

Une connexion Slack ancienne ne porte pas cette autorisation : reliez à nouveau Slack depuis
**Réglages → Comptes connectés**.

Le texte est mis en forme **pour Slack** : écrivez votre demande normalement, le gras, l'italique,
les listes et les liens sont convertis — rien n'arrive avec des astérisques en toutes lettres.

## Résultat

Le message publié apparaît dans la conversation avec un lien pour l'ouvrir dans Slack. MIA garde le
fil : au message suivant, *« ajoute un mot dans ce fil »* désigne bien celui-là.

## Limites

- **Pas de canal que vous ne voyez pas.** Un canal privé dont vous n'êtes pas membre est invisible
  pour MIA ; si elle ne trouve rien, c'est parfois qu'il faut d'abord le rejoindre dans Slack.
- **Pas de conversation de groupe.** MIA écrit dans un canal ou à une seule personne.
- **Pas de modification ni de suppression** d'un message, qu'il vienne de vous ou d'un autre.
- **Pas de fichier lu.** Les fichiers partagés dans Slack ne sont pas lus. Joindre des fichiers à un
  message se fait depuis un workflow : [Lire ou publier dans Slack](../workflows/lire-ou-publier-dans-slack.md).

## Voir aussi

- [Relier un service](relier-un-service.md) — connecter votre Slack, vérifier les autorisations
- [Utiliser un connecteur](utiliser-un-connecteur.md) — allumer, approuver, comprendre la trace
- [Les modèles de LLM](../modeles/index.md) — lesquels savent piloter les connecteurs
