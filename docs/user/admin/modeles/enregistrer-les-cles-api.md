---
title: Enregistrer les clés API
description: Rendre un éditeur d'IA utilisable en enregistrant sa clé, la tester, et déclarer un LLM local.
sidebar:
  order: 1
---

**Administration → Modèles IA → Clés API.** C'est la première étape de toute instance : tant
qu'aucune clé n'est enregistrée, MIA ne peut répondre à personne.

> Sans clé, une pastille ambre clignote sur **Modèles IA** dans le menu, un bandeau « Première
> configuration : c'est ici que tout commence. » s'affiche sur cette page, et le tableau de bord
> signale « Aucune clé API LLM active ». Tout s'éteint à la première clé enregistrée.

## Avant de commencer

Une clé API de l'éditeur, créée dans son espace développeur, avec un moyen de paiement actif chez
lui. Selon votre contrat, les clés peuvent être fournies et enregistrées par MyAlfred.

## Enregistrer une clé

1. Dans la carte **Fournisseurs cloud**, choisissez le **Fournisseur** — ChatGPT (OpenAI), Claude
   (Anthropic), Mistral, Perplexity, Gemini (Google), DeepSeek, Kimi (Moonshot), GLM (Z.ai).
   Changer de fournisseur ouvre sa fiche ; cela n'enregistre rien.
2. Choisissez un **Modèle** — le modèle de référence de l'éditeur. Il sert aux vérifications et aux
   tâches internes ; ce n'est pas lui qui est proposé aux utilisateurs, ce sont les modèles activés.
3. Collez la **Clé API**. L'enregistrement est automatique : « Configuration enregistrée. »

La clé est **chiffrée** ; le tableau n'en montre que les premiers caractères. Pour un fournisseur déjà
configuré, le champ affiche « Clé enregistrée — laissez vide pour la conserver » : vous pouvez changer
le modèle de référence sans ressaisir la clé.

**Enregistrer une clé ne suffit pas** : il faut ensuite activer les modèles de cet éditeur — voir
[Activer les modèles](activer-les-modeles.md).

## Tester une clé

Dans le tableau des clés, **Tester** envoie une question minimale à chaque modèle activé de
l'éditeur et affiche la réponse ou l'erreur. Ce test n'est pas décompté en crédits.

## Déclarer un LLM local

La carte **LLM local (OpenAI-compatible)** déclare un serveur de modèles hébergé chez vous :

| Champ | Contenu |
|---|---|
| **Nom affiché aux utilisateurs** | Le nom du sélecteur |
| **Modèle** | Le nom du modèle sur votre serveur |
| **URL de base** | L'adresse du serveur, joignable **depuis le serveur de MIA** |
| **Clé API** | Facultative |
| **Ce modèle sait appeler des outils** | À cocher si votre modèle prend en charge le *function calling* (Llama 3.1 et suivants, Qwen 2.5, Mistral…) |

**Tester la connexion** apparaît après le premier enregistrement. Le LLM local est proposé aussitôt,
dans le groupe **Local** du sélecteur, sans activation. Il répond en texte seul, sans recherche
Internet, et ne coûte aucun crédit. Un seul LLM local est possible.

La case **Ce modèle sait appeler des outils** ouvre au LLM local les connecteurs et les outils des
assistants. Elle est décochée par défaut : MIA ne peut pas savoir ce que sait le modèle servi par
votre serveur, et un modèle qui ne le sait pas ferait échouer chaque message qui engage un outil.

## Supprimer une clé

**Supprimer** dans le tableau, après confirmation. **Les modèles de l'éditeur restent activés** : ils
restent dans le sélecteur et répondent « Configuration requise ». Retirez-les aussi dans **Modèles
activés**.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Renseignez une clé API pour ce fournisseur. » | Le champ **Clé API** est vide pour un nouveau fournisseur. |
| **Tester** échoue avec une erreur d'authentification | Clé mal copiée, révoquée, ou compte de l'éditeur sans crédit. |
| « L'URL du serveur local est obligatoire. » | Renseigner l'**URL de base** du LLM local. |
| « Impossible de contacter … » avec le LLM local | Le serveur n'est pas joignable depuis MIA. `localhost` désigne le serveur de MIA, pas votre poste. |
