---
title: Régler les suggestions de suite
description: Proposer ou couper la suite logique affichée dans la zone de saisie après chaque réponse, et savoir ce qu'elle coûte.
sidebar:
  order: 5
---

Après chaque réponse de MIA, la zone de saisie vide propose en grisé la **suite logique** de
l'échange ; l'utilisateur la reprend d'un appui sur **Tab** (au doigt : **Utiliser**), la modifie au
besoin, puis l'envoie. Elle se règle sur la page **Outils**, carte **Suggestions de suite**, marquée
**Intégré**. Côté utilisateur :
[Mener une conversation](../../fonctionnalites/conversations/mener-une-conversation.md).

## Avant de commencer

Rien à configurer : la suggestion est préparée par le **modèle de la conversation**, avec la clé déjà
enregistrée pour lui.

## Étapes

- **Proposer les suggestions** : interrupteur sur **Actif** — c'est le réglage par défaut.
- **Les couper** : interrupteur sur **Inactif**. Plus aucune suggestion n'est préparée ni affichée,
  pour personne.

Il n'y a pas de réglage par groupe : le choix vaut pour toute l'instance.

## Ce qu'il faut savoir

- **Une suggestion par réponse, au plus.** Elle n'est préparée que pour la conversation que
  l'utilisateur a sous les yeux, sous sa **dernière** réponse, puis gardée avec elle : recharger la
  page ou rouvrir la conversation la relit sans nouvel appel. Une tâche programmée ou une étape de
  workflow, que personne ne regarde, n'en déclenche aucune.
- **Pas pour les vieilles conversations.** Rouvrir une conversation dont la dernière réponse a plus de
  douze heures ne prépare rien.
- **Le coût.** Quelques centaines de tokens du modèle de la conversation par réponse, sur la ligne
  **Suggestions de suite** de la catégorie *Assistance de conversation* (Consommation), **dans le
  quota** de l'utilisateur. Quota ou budget atteint : aucune suggestion, sans message.
- **Rien ne part tout seul.** La suggestion n'est qu'un texte proposé : Tab la pose dans le champ, et
  c'est l'utilisateur qui l'envoie. Le contenu de la conversation (un email lu, une page consultée)
  n'est jamais une consigne pour celui qui la prépare.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Aucune suggestion n'apparaît | Vérifier que la carte est **Active**. Elle n'apparaît pas non plus quand une carte attend une décision, quand MIA propose des choix en boutons ou le bouton **Continuer**, ni quand aucune suite n'est évidente. |
| Les suggestions pèsent sur la consommation | Les couper ici, ou choisir pour la conversation un modèle moins cher : c'est lui qui les prépare. |
