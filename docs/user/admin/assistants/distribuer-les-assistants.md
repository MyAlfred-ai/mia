---
title: Distribuer les assistants
description: Rendre un assistant visible par l'organisation et l'autoriser groupe par groupe.
sidebar:
  order: 1
---

Un assistant n'est visible que de son créateur tant qu'un administrateur ne l'a pas distribué. La
distribution se fait en deux gestes, et le premier seul ne suffit pas.

## Avant de commencer

- L'assistant est **Actif** : un assistant inactif n'est proposé dans aucun groupe.
- Les groupes existent — voir [Organiser les groupes](../utilisateurs/organiser-les-groupes.md).

## Étapes

1. **Espaces → Assistants** : la tuile **Assistants** de la barre latérale.
2. Sur la ligne de l'assistant, activez l'interrupteur **Partage organisation**. Le message confirme
   qu'il est désormais partagé et rappelle l'étape suivante. L'assistant passe dans la section
   **Partagés — organisation**.
3. **Administration → Personnes → Groupes.** Ouvrez la carte du groupe concerné, section **Droits
   d'accès**, colonne **Assistants**, et cochez l'assistant. L'enregistrement est automatique.
4. Recommencez l'étape 3 pour chaque groupe. Cocher dans le groupe **★ Par défaut** donne l'accès à
   tout le monde.

Le menu **⋯** de la ligne propose **Autoriser dans les groupes →** : c'est un raccourci vers la page
des groupes, il n'autorise rien par lui-même.

## Résultat

Les membres des groupes cochés trouvent l'assistant dans la section **🏢 Assistants de
l'organisation** de leur liste, et par **@** suivi de son nom. Sur la carte du groupe, le compteur
**N assistants** a augmenté.

Vérifiez avec un compte **non administrateur** : un administrateur voit tous les assistants
partagés, qu'ils soient autorisés ou non.

## Cas particuliers

- **Partagé mais autorisé nulle part** : l'assistant reste visible de son créateur et des
  administrateurs, de personne d'autre.
- **Retirer l'accès à un groupe** : décochez-le dans sa colonne **Assistants**. Les conversations
  déjà ouvertes avec l'assistant redeviennent des conversations standard pour ses membres.
- **Rendre l'assistant personnel à nouveau** : désactivez **Partage organisation**. **Toutes** ses
  autorisations de groupe sont supprimées ; un nouveau partage demandera de les cocher à nouveau.
- **Le créateur garde la main** : une fois l'assistant partagé, il peut toujours le modifier, le
  désactiver ou le supprimer. Ses changements s'appliquent à tous.
- **Un assistant sans créateur** — son auteur a été supprimé — affiche « créé par Système ». Il ne
  peut pas redevenir personnel : l'interrupteur de partage n'est plus proposé une fois partagé.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Un utilisateur ne voit pas l'assistant | Vérifier dans l'ordre : l'assistant est actif, il est partagé, il est coché dans l'un des groupes de l'utilisateur, ce groupe est actif. |
| L'assistant n'apparaît pas dans la colonne **Assistants** d'un groupe | Il est inactif, ou encore personnel. |
| Des autorisations ont disparu | L'assistant a été repassé en personnel : les autorisations sont effacées à ce moment-là. |
