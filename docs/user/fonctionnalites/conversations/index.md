---
title: Les conversations
description: Échanger avec MIA, lui confier vos fichiers ou votre voix, suivre ce qu'elle fait et retrouver vos échanges.
sidebar:
  order: 1
---

Une **conversation** est un fil d'échanges entre vous et MIA. C'est le point de départ de tout le
reste : c'est dans une conversation que vous choisissez un modèle ou un assistant, que vous joignez
des fichiers, que vous allumez un connecteur ou un outil.

## À quoi ça sert

Poser une question, faire rédiger, résumer, analyser un document, préparer un mail — puis affiner la
réponse message après message, sans tout réexpliquer : MIA garde le fil de ce qui s'est dit dans la
conversation.

## Comment ça marche

- **Écrire crée la conversation.** Votre premier message, tapé dans le champ de l'écran d'accueil,
  ouvre un nouveau fil ; son titre est rédigé automatiquement après ce premier message.
- **Chaque message part avec l'historique du fil**, vers le modèle choisi — ou vers celui qu'impose
  l'assistant avec lequel vous dialoguez. Voir [Les modèles de LLM](../modeles/index.md) et
  [Les assistants](../assistants/index.md).
- **Une très longue conversation continue sans erreur.** Quand le fil dépasse ce que le modèle peut
  relire d'un coup, les fichiers joints aux messages anciens ne repartent plus à chaque message (MIA
  les relit si votre question en a besoin), puis le début du fil est remplacé par un résumé. La trace
  au-dessus de la réponse le signale : « Résumé du début de la conversation ».
- **Ce que MIA consulte avant de répondre s'affiche au-dessus de la réponse**, en direct : une
  recherche Internet, un document Google Drive, une base de connaissances. Cette trace reste dans
  l'historique.
- **MIA peut vous proposer des choix en boutons** quand la suite dépend d'une décision de votre part :
  un clic envoie l'option comme votre réponse, et vous pouvez toujours écrire autre chose. Voir
  [Mener une conversation](mener-une-conversation.md).
- **MIA livre aussi des fichiers.** Demandez un tableau Excel, un document Word, une présentation
  PowerPoint ou un CSV : il arrive dans la réponse sous forme de carte, avec un aperçu et un bouton
  **Télécharger**.
- **Une génération lancée continue sur le serveur.** Vous pouvez changer de conversation, recharger
  la page ou fermer l'onglet : la réponse vous attend dans son fil.
- **Ce qui est allumé dans une conversation y reste** jusqu'à ce que vous l'éteigniez : un outil de
  génération, un connecteur, des bases de connaissances. Des pastilles, à côté du bouton **+**, le
  rappellent.
- **Chaque réponse consomme des crédits.** L'étiquette sous la réponse indique le modèle utilisé, les
  crédits consommés et le temps de réponse. Voir [La gestion des crédits](../credits/index.md).
- **Les conversations se rangent par date** dans la barre latérale : aujourd'hui, hier, les 7 et
  30 derniers jours, puis un groupe par mois. Une conversation épinglée remonte en tête, dans le cadre
  **Épinglées**, et échappe au ménage automatique — cinq au plus.
- **Une demande peut se répéter toute seule.** Programmée — chaque lundi à 8 h, chaque matin… —, elle
  repart à l'heure dite et sa réponse vous attend dans sa conversation, signalée par une pastille.
  Demandez-le à MIA dans le chat, ou passez par l'horloge **Programmer** de l'assistant.

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Poser une question, suivre la réponse, l'interrompre, demander à MIA de retenir quelque chose | [Mener une conversation](mener-une-conversation.md) |
| Faire lire un document ou analyser une image | [Joindre des fichiers](joindre-des-fichiers.md) |
| Obtenir un tableau Excel, un document Word, une présentation PowerPoint, un CSV ou un texte prêt à enregistrer | [Télécharger un fichier créé par MIA](telecharger-un-fichier.md) |
| Parler plutôt qu'écrire | [Dicter un message](dicter-un-message.md) |
| Parler avec MIA et l'écouter répondre, l'interrompre ou approuver une carte à la voix, sans clavier | [Converser à la voix](converser-a-la-voix.md) |
| Renommer, épingler, rechercher ou supprimer une conversation | [Gérer ses conversations](gerer-ses-conversations.md) |
| Faire refaire une demande chaque semaine, chaque jour ou chaque mois, sans y penser | [Programmer une tâche](programmer-une-tache.md) |

## Ce que vous pouvez régler

- Le style des réponses, votre contexte de travail et ce que MIA retient de vous :
  [La personnalisation](../personnalisation/index.md).
- Le moteur qui répond : [Les modèles de LLM](../modeles/index.md), ou un assistant préconfiguré :
  [Les assistants](../assistants/index.md).

Quatre choses dépendent de votre administrateur : la **dictée**, la **conversation vocale** et les
**tâches programmées**, qu'il active ou non, et la **durée de conservation** des conversations
anciennes.

## Exemples

**Analyser un contrat.** Vous joignez le PDF et demandez : *« Liste les clauses de résiliation et
leurs délais. »* Puis, dans le même fil : *« Rédige le mail de résiliation pour le 30 juin. »* MIA
repart de ce qu'elle vient de lire.

**Lancer une longue analyse et passer à autre chose.** Vous demandez la synthèse de dix pages de
notes, puis vous ouvrez une autre conversation. Le bandeau « 1 génération en cours » reste visible ;
**Voir** vous ramène au résultat quand il est prêt.

**Préparer une réunion sur le trajet.** Sur votre téléphone, vous dictez les points à aborder ; MIA
les remet en ordre du jour. Ou vous ouvrez une conversation vocale : vous posez vos questions à
haute voix et MIA vous lit ses réponses.

**Repartir avec un fichier.** Vous demandez : *« Prépare le budget prévisionnel 2026 dans un fichier
Excel, avec les totaux. »* La carte **budget-previsionnel-2026.xlsx** montre les premières lignes ;
**Télécharger** enregistre le classeur, totaux calculés. La flèche à côté propose le même tableau en
CSV.

**Garder un échange de référence.** Vous épinglez la conversation « Procédure d'accueil des
stagiaires » : elle reste en tête de liste et n'est jamais supprimée par le ménage automatique.

## Voir aussi

- [Les assistants](../assistants/index.md) — dialoguer avec une IA préconfigurée pour un métier
- [Les outils](../outils/index.md) — faire produire une image, une présentation, une page web
- [Les connecteurs](../connecteurs/index.md) — donner accès à vos documents, mails et agendas
