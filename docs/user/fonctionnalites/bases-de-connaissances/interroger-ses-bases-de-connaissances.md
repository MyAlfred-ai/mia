---
title: Interroger ses bases de connaissances
description: Ajouter des bases de documents à une conversation pour que MIA fonde ses réponses dessus.
sidebar:
  order: 1
---

Cocher une base dans une conversation fait fouiller ses documents avant chaque réponse. Ce qu'est
une base, et comment elle est construite : [Les bases de connaissances](index.md).

## Avant de commencer

Le bouton **livre ouvert**, sous le champ de saisie, n'apparaît que si au moins une base active vous
est accessible. Un utilisateur reçoit les bases par ses **groupes** ; un créateur voit aussi les
siennes et celles qu'on lui a partagées.

## Étapes

1. Cliquez le bouton **livre ouvert** — « Bases de connaissances à interroger ».
2. Cochez jusqu'à **3 bases**. Sous chacune, le nombre de documents ; le badge **Partagée** signale
   une base reçue.
3. Écrivez votre message : MIA fouillera les bases cochées avant de répondre, à chaque message de la
   conversation.

Vous pouvez cocher **avant même d'écrire** votre premier message. Une base peut aussi être déjà
cochée quand la conversation commence : un administrateur l'a cochée **par défaut** pour votre groupe.
Elle se décoche comme les autres.

## Résultat

Pendant la réponse, l'étape **Recherche dans les bases de connaissances…** s'affiche. Ensuite, une
ligne reste au-dessus de la réponse : elle nomme les bases interrogées, en distinguant celles que vous
avez ajoutées de celles de l'assistant.

MIA fonde sa réponse sur les extraits les plus proches de votre question, et peut citer le document
d'où ils viennent.

## Ce qu'il faut savoir

- **Vos bases s'ajoutent à celles de l'assistant**, elles ne les remplacent pas. Celles de
  l'assistant apparaissent en tête, cochées et grisées, avec la mention **Fournie par l'assistant**
  et le badge **Assistant**. Elles n'entrent pas dans la limite de trois.
- **Le choix vaut pour toute la conversation.** Une fois la conversation créée, il est enregistré
  avec elle : vous le retrouvez sur un autre appareil.
- **Une base cochée n'est pas une base utile.** La ligne au-dessus de la réponse nomme les bases
  **interrogées**, pas celles qui ont fourni un extrait.

## Une base grisée n'est pas une panne

Une base n'est interrogeable qu'une fois au moins un de ses documents analysé et indexé. Tant que ce
n'est pas le cas, elle apparaît grisée avec la raison :

| Ce que vous lisez | Quoi faire |
|---|---|
| **Aucun document** | Déposer des documents dans la base. |
| **Vectorisation en cours** | Patienter, puis **recharger la page** : l'état est calculé à l'ouverture. |
| **Aucun texte exploitable dans les documents** | Les fichiers ne contiennent pas de texte lisible — PDF scanné, image sans contenu. |
| **Vectorisation en échec** | Prévenir le créateur de la base ou un administrateur. |
| **Connexion MyBrain refusée — à vérifier par un administrateur** | Le cerveau MyBrain n'accepte plus la connexion de MIA. Prévenir un administrateur. Voir [Interroger le cerveau MyBrain](interroger-le-cerveau-mybrain.md). |

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « 3 bases maximum — décochez-en une pour en ajouter » | La limite est atteinte. Décocher une base. |
| « Une base n'a pas pu être ajoutée : déjà fournie par l'assistant, ou plus accessible. » | Elle est déjà fournie par l'assistant, ou son partage vient de vous être retiré. |
| Pas de bouton **livre ouvert** | Aucune base ne vous est accessible. Demander à un créateur ou à un administrateur de vous en partager une. |
| Une base a disparu de la liste | Elle a été désactivée, son partage vous a été retiré, ou votre groupe est inactif. Votre choix n'est pas perdu : elle revient cochée dès que l'accès est rétabli. Une base **supprimée**, elle, ne revient pas. |
