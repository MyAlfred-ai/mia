---
title: Régler la dictée vocale
description: Proposer ou masquer le micro du chat, et savoir ce qu'il faut pour qu'il fonctionne.
sidebar:
  order: 3
---

La dictée transcrit la voix dans le champ de saisie, en direct. Elle se règle sur la page **Outils**,
carte **Dictée vocale**, marquée **Intégré**. Côté utilisateur :
[Dicter un message](../../fonctionnalites/conversations/dicter-un-message.md).

## Avant de commencer

La dictée repose sur le service de transcription d'OpenAI : une clé **OpenAI** doit être enregistrée
dans **Modèles IA → Clés API**. Sans elle, la carte affiche **Clé requise** et le micro n'apparaît
pas.

## Étapes

- **Proposer la dictée** : interrupteur sur **Actif** — c'est le réglage par défaut. Le micro apparaît
  dans le champ de saisie de tous les utilisateurs.
- **La masquer** : interrupteur sur **Inactif**. Le micro disparaît pour tout le monde, et une dictée
  ne peut plus démarrer, même depuis une page restée ouverte.

Il n'y a pas de réglage par groupe : la dictée vaut pour toute l'instance.

## Ce qu'il faut savoir

- La transcription suit la **langue de l'interface** de l'utilisateur (français ou anglais).
- La carte voisine, **Conversation vocale**, propose un échange parlé complet (MIA lit ses
  réponses) : [Régler la conversation vocale](regler-la-conversation-vocale.md).
- Elle est facturée **à la minute** d'audio, sur la ligne « Dictée » de **Tarifs & crédits**, et
  compte dans le quota : un utilisateur au quota épuisé ne peut plus ouvrir de dictée — il voit la
  même fenêtre « quota atteint » que pour un message.
- La voix part vers le service de transcription, puis le texte s'écrit dans le champ : rien n'est
  envoyé à MIA tant que l'utilisateur n'a pas validé son message.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Personne n'a de micro | Vérifier que la carte est **Active** et qu'une clé OpenAI est enregistrée. |
| « Accès au micro refusé. » chez un utilisateur | C'est le navigateur de l'utilisateur qui bloque le micro : à autoriser de son côté. |
