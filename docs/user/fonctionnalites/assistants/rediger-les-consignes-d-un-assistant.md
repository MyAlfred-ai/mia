---
title: Rédiger les consignes d'un assistant
description: Écrire le prompt système d'un assistant — partir d'un modèle, se faire aider par l'IA, choisir la température.
sidebar:
  order: 3
---

Les consignes — le champ **System prompt** — sont ce qui distingue un assistant d'un autre. Le modèle
les relit avant chaque réponse. Le nom et la description, eux, ne lui sont jamais transmis : tout ce
que l'assistant doit savoir de son rôle est dans ses consignes.

## Avant de commencer

Être sur la fiche de l'assistant, en création ou en modification — voir
[Créer un assistant](creer-un-assistant.md).

## Partir d'un modèle

Le bloc **Partir d'un modèle** propose huit points de départ. Chacun remplit le prompt avec une
trame en six parties — **Rôle**, **Mission**, **Ton et format**, **Règles et limites**, **Sources**,
**Cas limites** — et suggère une température. Les modèles suivent la langue de votre interface : en
anglais, ils sont rédigés en anglais, avec les parties **Role**, **Mission**, **Tone and format**,
**Rules and limits**, **Sources**, **Edge cases** ; l'aide à la rédaction aussi.

| Point de départ | Pour | Température suggérée |
|---|---|---|
| Assistant généraliste | Un usage large, sans métier précis | 0,7 |
| Expert métier | Répondre avec précision dans un domaine | 0,3 |
| Analyste de documents | Extraire, comparer, synthétiser des documents | 0,2 |
| Support / FAQ interne | Répondre à partir de procédures établies | 0,2 |
| Rédacteur | Produire des textes | 0,8 |
| Relecteur & traducteur | Corriger, reformuler, traduire | 0,2 |
| Formateur | Expliquer, faire progresser | 0,6 |
| Partenaire de brainstorming | Proposer des idées, contredire | 1,0 |

La description et la température ne sont posées que si leurs champs sont vides. Complétez ensuite
la trame avec votre contexte : c'est lui qui fait la différence.

## Se faire aider par l'IA

- **✨ Rédiger avec l'IA** écrit des consignes complètes à partir de la description de l'assistant
  (1 000 caractères au plus) — ou, à défaut, de son nom.
- **Améliorer** reprend le prompt existant (20 caractères au moins) et le restructure.

Dans les deux cas, **rien n'est enregistré tant que vous n'avez pas validé** : la fiche affiche
« Prompt modifié, pas encore enregistré », avec **Annuler** et **Enregistrer**. Le lien **↶ Revenir
au texte précédent** rétablit votre version.

Cette aide consomme des crédits **sur votre quota**, poste « Conception d'assistants ».

## Écrire de bonnes consignes

- **Dites qui il est et pour qui il travaille.** *« Tu es le support informatique de niveau 1 d'une
  PME de 80 personnes. Tu t'adresses à des utilisateurs non techniciens. »*
- **Dites ce qu'il doit faire, et ce qu'il ne doit pas faire.** *« Tu ne communiques jamais de mot
  de passe. Au-delà de trois étapes de diagnostic, tu proposes d'ouvrir un ticket. »*
- **Nommez ses sources.** *« Appuie-toi d'abord sur la base Procédures IT ; si la réponse n'y est
  pas, dis-le au lieu d'inventer. »*
- **Fixez le format.** *« Réponses en étapes numérotées, dix lignes au plus. »*
- **Prévoyez les cas limites.** *« Si la demande concerne la paie, oriente vers le service RH. »*

Les préférences personnelles de chaque utilisateur (style de réponse, contexte) et sa mémoire
s'ajoutent à vos consignes : écrivez ce qui vaut pour tout le monde, pas pour une personne.

## Choisir la température

| Température | Effet | Pour |
|---|---|---|
| 0 à 0,3 | Réponses constantes, prudentes, collées aux sources | Juridique, support, analyse |
| 0,4 à 0,8 | Équilibre | Usage général, formation |
| 0,9 à 2 | Réponses variées, inventives | Rédaction créative, idées |

## Résultat

Les consignes s'appliquent dès l'enregistrement, à toutes les conversations de l'assistant — y
compris celles déjà ouvertes.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Il n'y a pas encore de prompt à améliorer… » | Écrivez au moins quelques phrases avant de cliquer **Améliorer**. |
| « Décrivez l'assistant en quelques phrases, ou donnez-lui au moins un nom. » | Remplissez la description ou le nom avant **✨ Rédiger avec l'IA**. |
| « Quota mensuel de crédits atteint : la rédaction assistée est suspendue… » | Votre quota du mois est épuisé. Rédigez à la main, ou voyez avec un administrateur. |
| L'assistant ignore une consigne | Rendez-la explicite et placez-la dans **Règles et limites**. Baissez la température. |
