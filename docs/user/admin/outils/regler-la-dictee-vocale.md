---
title: Régler la dictée vocale
description: Proposer ou masquer le micro du chat, et savoir ce qu'il faut pour qu'il fonctionne.
sidebar:
  order: 3
---

La dictée transcrit la voix dans le champ de saisie. Elle se règle sur la page **Outils**, carte
**Dictée vocale**, marquée **Intégré**. Côté utilisateur :
[Dicter un message](../../fonctionnalites/conversations/dicter-un-message.md).

## Avant de commencer

La dictée repose sur un service de transcription, au choix :

- **OpenAI Realtime** (par défaut) : le texte s'écrit au fil de la parole. Demande une clé **OpenAI**.
- **Mistral Voxtral** : le texte s'écrit phrase par phrase, à chaque pause. Demande une clé
  **Mistral**.

Les clés s'enregistrent dans **Modèles IA → Fournisseurs et modèles**. Sans clé utilisable, la carte affiche
**Clé requise** et le micro n'apparaît pas.

## Étapes

- **Proposer la dictée** : interrupteur sur **Actif** — c'est le réglage par défaut. Le micro apparaît
  dans le champ de saisie de tous les utilisateurs.
- **La masquer** : interrupteur sur **Inactif**. Le micro disparaît pour tout le monde, et une dictée
  ne peut plus démarrer, même depuis une page restée ouverte.
- **Choisir le moteur** : liste **Moteur de transcription**. En choisissant **Mistral Voxtral**, MIA
  essaie d'abord la clé Mistral (« Essai de la clé Mistral… ») : si l'essai réussit, la dictée passe
  par Voxtral ; sinon la cause s'affiche sous la liste et rien ne change.

Il n'y a pas de réglage par groupe : la dictée vaut pour toute l'instance.

## Ce qu'il faut savoir

- La transcription suit la **langue de l'interface** de l'utilisateur (français ou anglais).
- La carte voisine, **Conversation vocale**, propose un échange parlé complet (MIA lit ses
  réponses) : [Régler la conversation vocale](regler-la-conversation-vocale.md).
- Si la clé Mistral est retirée alors que Voxtral est choisi, la dictée repasse sur OpenAI (si sa clé
  est là) et la carte le signale.
- La conversation vocale passe toujours par OpenAI, quel que soit le moteur de la dictée. Avec
  Voxtral et sans clé OpenAI, son bouton porte une pastille rouge et ne démarre pas :
  [Régler la conversation vocale](regler-la-conversation-vocale.md).
- Elle est facturée **à la minute** d'audio, sur la ligne « Dictée » de **Tarifs & crédits** (une ligne
  par modèle, OpenAI ou Voxtral), et compte dans le quota : un utilisateur au quota épuisé ne peut plus
  ouvrir de dictée — il voit la même fenêtre « quota atteint » que pour un message.
- La voix part vers le service de transcription, puis le texte s'écrit dans le champ : rien n'est
  envoyé à l'assistant tant que l'utilisateur n'a pas validé son message. Avec Voxtral, chaque phrase
  passe par le serveur de MIA, qui la confie à Mistral avec la clé de l'organisation.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Personne n'a de micro | Vérifier que la carte est **Active** et que la clé du moteur choisi (OpenAI ou Mistral) est enregistrée. |
| « Voxtral n'a pas répondu à l'essai » en choisissant Voxtral | La clé Mistral est refusée ou son crédit épuisé : la cause est affichée. La corriger dans **Modèles IA → Fournisseurs et modèles**, puis choisir à nouveau Voxtral. |
| « Accès au micro refusé. » chez un utilisateur | C'est le navigateur de l'utilisateur qui bloque le micro : à autoriser de son côté. |
