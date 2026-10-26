---
title: Tester les modèles
description: Vérifier que chaque modèle activé répond, cherche sur Internet et lit les images — après une mise en service ou une mise à jour.
sidebar:
  order: 4
---

**Administration → Modèles IA → Tests.** Une carte par modèle activé, trois vérifications par
modèle, un score global.

## Étapes

1. Cliquez **Lancer tous les tests** — ou **Tester ce LLM** sur une carte.
2. Suivez l'avancement : chaque vérification passe de ○ à ◐, puis ✓ ou ✗, avec sa durée.

## Résultat

Pour chaque modèle, trois vérifications :

| Vérification | Ce qu'elle prouve |
|---|---|
| **Conversation de base** | Le modèle répond |
| **Recherche web** | Il cherche sur Internet — « Non supporté nativement par ce modèle » sinon |
| **Upload image / fichier** | Il lit une image ou un document — « Vision/fichiers non supportés par ce modèle » sinon |

Un score « x/y OK » par modèle, et « x/y tests OK (z %) » au global. Les tests ne sont pas décomptés
en crédits.

## Quand tester

- à la mise en service ;
- après chaque **mise à jour de MIA** : un modèle retiré par son éditeur échoue ici avant d'échouer
  chez les utilisateurs ;
- après un changement de clé.

## Ce que les tests ne prouvent pas

- Ils ne testent ni le **LLM local**, ni les **connecteurs**.
- Ils partent du serveur web de MIA, quand les conversations tournent en tâche de fond : un test vert
  ne garantit pas qu'une conversation fonctionne. Complétez par une vraie question, depuis un compte
  **non administrateur**.
- Ils interrogent chaque modèle **seul** : la reprise par un autre modèle, après un refus ou faute de
  conservation des données, n'y joue pas. Un échec signale donc une vraie limite de l'organisation,
  même si le chat, lui, répond déjà.

## En cas de problème

| Résultat | Quoi faire |
|---|---|
| ✗ **Requête refusée** | Le modèle n'existe plus chez son éditeur : activer son successeur, puis retirer l'ancien. |
| ✗ **Accès au service refusé** | La clé est refusée : la vérifier dans **Clés API**. |
| ✗ **Service momentanément saturé** ou **indisponible** | Incident chez l'éditeur : relancer plus tard. |
| ✗ **Configuration requise** | La clé de l'éditeur a été supprimée : la réenregistrer, ou retirer le modèle. |
| ✗ **Conservation des données requise** | L'organisation ne conserve pas les données chez l'éditeur : voir [Activer les modèles](activer-les-modeles.md), section « Un modèle qui exige la conservation des données ». Le chat fait déjà répondre un autre modèle à la place. |
| « Réponse : (vide — arrêt : max_tokens) » | Le modèle a réfléchi sans rien écrire dans ce test court : relancer. |
