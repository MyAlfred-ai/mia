---
title: Gérer les assistants
description: Lire la liste de tous les assistants, désactiver ou supprimer, reprendre ceux d'un compte supprimé, tenir la bibliothèque d'avatars.
sidebar:
  order: 2
---

**Espaces → Assistants** — la tuile **Assistants** de la barre latérale — montre tous les assistants
de l'instance, y compris les assistants personnels des créateurs.

## Lire la liste

Deux sections : **Partagés — organisation** et **Personnels**. Chaque ligne montre :

- l'**identité** : avatar, nom, description, auteur — « créé par Système » quand l'assistant n'a plus
  de créateur — et les badges **Global** et **Inactif** ;
- la **nature** : badge **Partagé** ou **Personnel**, avec l'interrupteur **Partage organisation** ;
- les **réglages clés** en pastilles : modèle, température, documents de référence, bases de
  connaissances, outils ;
- les **actions** : **Utiliser**, **Modifier**, et le menu **⋯** — **Autoriser dans les groupes →**,
  **🗑 Supprimer**.

La barre du haut recherche par nom, description, auteur ou modèle, filtre par **Statut** et par
**LLM**, et trie par nom, auteur, modèle, température ou nombre de documents. Le tri s'applique à
l'intérieur de chaque section.

**Cliquer une ligne** affiche à droite le **schéma** de l'assistant : son modèle et sa température,
le début de son prompt, son état, qui le voit, ses bases, ses documents et ses outils — voir
[Lire le schéma d'un assistant](../../fonctionnalites/assistants/creer-un-assistant.md#lire-le-schéma-dun-assistant).
Une base liée hors du périmètre d'un créateur reste comptée dans son schéma, sans son nom.

Vous ne pouvez pas **Utiliser** l'assistant personnel d'un autre : il n'est visible que de son
créateur dans le chat.

## Désactiver un assistant

Ouvrez sa fiche et passez l'interrupteur du haut sur **Inactif**. Il disparaît du chat pour tout le
monde, créateur compris, et de la colonne **Assistants** des groupes. Ses conversations continuent en
mode standard. Le réactiver le rend à nouveau visible, dans les mêmes groupes.

## Supprimer un assistant

Menu **⋯ → 🗑 Supprimer**, ou **Supprimer cet assistant** en bas de sa fiche. **L'action est
irréversible** : ses documents de référence, ses liens aux bases, aux outils et aux groupes
disparaissent ; ses conversations redeviennent des conversations standard.

Préférez la désactivation tant que vous n'êtes pas sûr.

## Reprendre les assistants d'un compte supprimé

Quand un créateur est supprimé, ses assistants restent, marqués « créé par Système » :

- ses assistants **partagés** continuent de servir, dans les mêmes groupes ;
- ses assistants **personnels** ne sont plus visibles de personne dans le chat.

Pour conserver un assistant personnel orphelin, activez son **Partage organisation** et cochez-le
dans les groupes voulus. Mieux vaut le faire **avant** de supprimer le compte : la fenêtre de
confirmation de suppression annonce le nombre d'assistants qui perdront leur créateur.

## Tenir la bibliothèque d'avatars

La section **Gestion des avatars d'assistants**, repliée en haut de la liste, rassemble les images
proposées à la création d'un assistant.

- **Ajouter** : **Image** (PNG, JPG, GIF ou WebP, 2 Mo au plus), un **Nom** facultatif, puis
  **Ajouter**.
- **Renommer, supprimer** : les avatars marqués **Système** sont livrés avec MIA et ne se suppriment
  pas. Les créateurs peuvent aussi gérer les avatars qu'ils ont eux-mêmes ajoutés.
- **Supprimer un avatar le retire de tous les assistants** qui l'utilisaient.

## L'assistant MIA : mode d'emploi

Livré avec MIA, **global** — badge **Global** —, il est proposé à tout le monde sans réglage de
groupe et utilise le modèle par défaut de l'application. Il répond à partir d'une base système
alimentée par les guides de MIA.

Il est géré par l'application : supprimé, il est recréé ; modifié ou désactivé, il est remis dans
son état d'origine à la mise à jour suivante des guides. N'en faites pas la base d'un assistant
maison : créez-en un nouveau.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Un assistant « créé par Système » que personne ne voit | Son créateur a été supprimé. Le partager et l'autoriser dans un groupe, ou le supprimer. |
| Un avatar a disparu de plusieurs assistants | Il a été supprimé de la bibliothèque. Choisir un autre avatar sur chaque fiche. |
