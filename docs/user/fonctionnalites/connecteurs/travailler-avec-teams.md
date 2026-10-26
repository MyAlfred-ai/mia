---
title: Travailler avec Microsoft Teams
description: Faire résumer une conversation ou un canal Teams, retrouver un message, et publier dans Teams en votre nom après approbation.
sidebar:
  order: 4
---

Une fois votre Teams relié et l'interrupteur **Messages Teams** allumé, MIA cherche et lit vos
conversations et les canaux de vos équipes, et publie à votre demande. Elle ne voit **que ce que vous
voyez** : vos conversations privées, de groupe et de réunion, et les canaux des équipes dont vous êtes
membre.

## Avant de commencer

- Teams relié : [Relier un service](relier-un-service.md). Il faut un **compte professionnel** avec
  Teams, et qu'un administrateur de votre organisation ait autorisé MIA dans Microsoft — sans quoi
  Microsoft affiche « Approbation de l'administrateur requise ».
- L'interrupteur **Messages Teams** allumé dans le volet **Connecteurs** du menu **+** de la
  conversation.
- Un modèle qui sait piloter les connecteurs — le bandeau affiche **✓ Connecteurs**. Sinon, MIA vous
  propose la liste des modèles compatibles au moment où vous allumez l'interrupteur.

## Retrouver et résumer

Demandez en langage normal. MIA choisit elle-même entre chercher partout et lire une conversation
précise.

| Vous demandez… | Ce que MIA fait |
|---|---|
| *« Qu'a dit Marie sur le budget dans Teams ? »* | Cherche dans tous les messages que vous pouvez lire |
| *« Résume ma conversation avec Paul »* | Lit la conversation, du plus ancien au plus récent |
| *« Quoi de neuf dans Projet Atlas / Général ? »* | Lit le canal ; chaque message indique combien de réponses a son fil |
| *« Déroule le fil sur la livraison »* | Lit le message d'origine et toutes ses réponses |

Une conversation se désigne par son sujet ou le nom de la personne ; un canal par **« Équipe /
Canal »**. Un **lien Teams** collé dans le message (« Copier le lien » d'une conversation, d'un canal
ou d'un message) fait aussi l'affaire. Un nom qui vaut pour plusieurs — « Général » existe dans chaque
équipe — : MIA vous demande lequel.

Chaque message rapporté est cité avec son **auteur** et sa **date** ; les mentions (`@Marie`) et les
pièces jointes sont nommées.

## Publier un message

1. Demandez : *« Réponds à Marie que je regarde le devis demain »*, ou *« Publie dans Projet Atlas /
   Général que la réunion passe à jeudi »*.
2. MIA rédige le message et affiche une **carte d'approbation** : la destination, l'aperçu du texte,
   et le rappel de ce qui suit.
3. Cliquez **Approuver**.

**Un message Teams n'est pas un brouillon.** Un message approuvé **part immédiatement**, en votre nom,
et devient visible des participants de la conversation ou des membres du canal. MIA ne peut ensuite ni
le modifier ni le supprimer : c'est à vous de le faire dans Teams.

**Annuler** ne publie rien. Sans réponse de votre part, la carte expire au bout de 30 minutes.

Dans un **canal**, MIA peut répondre **dans le fil** d'un message plutôt que d'ouvrir une nouvelle
discussion. Une **conversation** n'a pas de fil : le message s'y ajoute. Écrivez votre demande
normalement : le gras, l'italique, les listes et les liens sont mis en forme pour Teams.

## Résultat

Le message publié apparaît dans la conversation de MIA avec un lien pour l'ouvrir dans Teams. MIA garde
la destination : au message suivant, *« ajoute que j'apporte le dossier »* vise bien la même
conversation.

## Limites

- **Pas d'équipe que vous ne voyez pas.** Une équipe dont vous n'êtes pas membre est invisible pour MIA.
- **Canaux non lus** tant que l'administrateur n'a pas accordé l'autorisation de les lire : les
  conversations restent disponibles, la page **Comptes connectés** montre le badge **Canaux —**.
- **Pas de modification ni de suppression** d'un message, qu'il vienne de vous ou d'un autre.
- **Pas de fichier.** Les fichiers partagés dans Teams sont nommés, pas lus.

## Voir aussi

- [Relier un service](relier-un-service.md) — connecter votre Teams, vérifier les autorisations
- [Utiliser un connecteur](utiliser-un-connecteur.md) — allumer, approuver, comprendre la trace
- [Travailler avec Slack](travailler-avec-slack.md) — le même usage, côté Slack
