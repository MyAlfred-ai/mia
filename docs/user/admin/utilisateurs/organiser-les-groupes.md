---
title: Organiser les groupes
description: Créer des groupes, y ranger les utilisateurs, et décider des modèles, assistants, outils et quota de chacun.
sidebar:
  order: 2
---

**Administration → Personnes → Groupes.** Les groupes portent les droits : c'est ici que se décide
qui a accès à quels modèles, assistants et outils, et avec quel quota.

## Avant de commencer

Les règles de cumul, résumées dans [Les utilisateurs et les accès](index.md) : un utilisateur a
l'**union** des droits de ses groupes actifs, et le **plus élevé** de leurs quotas définis.

## Étapes

1. Cliquez **Créer le groupe**, donnez-lui un **Nom du groupe**.
2. Ouvrez sa carte. Trois volets :
   - **Paramètres** : **Nom du groupe**, **Quota (k crédits/mois)** — 10 = 10 000 crédits par mois et
     **par membre** —, **Groupe actif** ;
   - **Membres** : recherchez et cochez les personnes ;
   - **Droits d'accès** : trois colonnes, **Assistants**, **Outils**, **LLMs**, avec les raccourcis
     **Tout** et **Aucun**.
3. Cochez ce que le groupe autorise. Tout s'enregistre au fil de l'eau.

## Résultat

La carte du groupe résume : nombre de membres, d'assistants, d'outils et de modèles — « — (neutre) »
sans liste de modèles —, et le quota mensuel ou **∞**. Les droits valent dès l'action suivante des
membres.

## Le groupe ★ Par défaut

- Il contient **automatiquement tout le monde** ; ses membres ne se gèrent pas à la main, et il ne se
  supprime pas.
- Ce qu'on y coche est le **socle commun** : l'assistant d'accueil, les outils de base, un modèle
  économique.

## Les règles, cas par cas

**Modèles (colonne LLMs).**

| Situation de l'utilisateur | Modèles proposés |
|---|---|
| Au moins un de ses groupes actifs a une liste | L'union des listes — les groupes sans liste n'ajoutent rien |
| Aucun groupe n'a de liste, et il n'est que dans ★ Par défaut | Le seul **modèle par défaut** de l'instance |
| Aucun groupe n'a de liste, et il est dans un autre groupe actif | **Tous** les modèles activés |

Un modèle nouvellement activé **n'est ajouté à aucune liste** : cochez-le dans les groupes qui y ont
droit. Un modèle imposé par un assistant échappe à ces listes.

**Assistants.** Seuls les assistants **partagés et actifs** sont proposés — voir
[Distribuer les assistants](../assistants/distribuer-les-assistants.md).

**Outils.** Un outil nouvellement ajouté n'est autorisé à aucun groupe : tant que vous ne l'avez
coché nulle part, seuls les administrateurs le voient. Voir [Les outils](../outils/index.md).

**Quota.** Le plus élevé des quotas **définis** parmi les groupes actifs de la personne ; un groupe
sans quota est neutre et ne rend personne illimité. Un quota individuel prime sur tout. Voir
[Fixer les quotas](../credits/fixer-les-quotas.md).

**Groupe inactif.** Ses droits et son quota sont ignorés, pour tous ses membres.

## Cas particuliers

- **Connecteurs et dictée** ne se règlent pas ici : ils valent pour toute l'instance.
- **Supprimer un groupe** : ses membres restent actifs, mais perdent les droits de ce groupe.
- Un administrateur voit tous les assistants partagés et tous les outils actifs, quels que soient
  ses groupes ; pour les modèles, les listes s'appliquent aussi à lui.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Un utilisateur reçoit « Ce LLM n'est pas autorisé pour votre groupe. » | Cocher le modèle dans l'un de ses groupes, ou lui faire choisir un modèle autorisé. |
| Tout le monde n'a plus qu'un seul modèle | Aucun groupe n'a de liste et les utilisateurs ne sont que dans ★ Par défaut : cocher des modèles dans ce groupe. |
| Un nouveau modèle n'apparaît chez personne | Il n'est coché dans aucune liste de groupe. |
| Un outil ajouté n'apparaît que chez les administrateurs | Il n'est autorisé dans aucun groupe : le cocher dans la colonne **Outils**. |
| Un membre du groupe *Direction*, sans quota, reste plafonné | Un autre de ses groupes a un quota : c'est lui qui s'applique. Lui donner un quota individuel élevé. |
