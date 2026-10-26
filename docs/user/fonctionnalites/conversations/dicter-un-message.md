---
title: Dicter un message
description: Écrire un message à la voix plutôt qu'au clavier.
sidebar:
  order: 3
---

## Avant de commencer

Le bouton **micro** apparaît quand la dictée est disponible sur votre instance : elle est active par
défaut, mais votre administrateur peut la masquer, et elle demande un service de transcription
configuré. S'il est absent du champ de saisie, c'est à lui qu'il faut le demander.

La transcription suit la **langue de l'interface** (français ou anglais). La dictée est décomptée à la
minute, sur votre quota : quand il est atteint, elle ne démarre plus.

Pour parler avec MIA et l'écouter répondre, sans clavier, voir
[Converser à la voix](converser-a-la-voix.md).

## Étapes

1. Cliquez le bouton **micro** — « Dicter le message ».
2. Autorisez l'accès au micro dans votre navigateur.
3. Parlez : votre voix se transcrit dans le champ en direct.
4. Re-cliquez pour arrêter — « Arrêter la dictée ».

Pendant l'établissement de la liaison, le bouton tourne et indique « Connexion du micro… ». Si la
liaison se coupe en cours de dictée, elle se rétablit toute seule : continuez à parler.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Accès au micro refusé. » | Autoriser le micro dans les réglages du navigateur. |
| « Dictée : aucun texte reçu. Vérifiez que le micro capte du son. » | Vérifier l'entrée micro choisie par votre système, et son niveau. |
| Pas de bouton micro | La dictée est masquée ou non configurée sur votre instance. Voir avec votre administrateur. |
| La fenêtre **Quota mensuel atteint** s'ouvre au clic sur le micro, ou la dictée s'arrête d'elle-même | Votre quota du mois est épuisé : attendre le 1er du mois, ou demander une augmentation à un administrateur. |
| « La dictée vocale est désactivée par l'administrateur. » | Le micro a été masqué pendant que votre page était ouverte : rechargez-la. |
| « Dictée impossible — Crédit épuisé chez OpenAI » (ou **Accès au service refusé**) | Le service qui transcrit la voix ne répond plus pour votre organisation : ce n'est ni votre micro ni votre compte. Prévenez un administrateur ; en attendant, tapez votre message. |
