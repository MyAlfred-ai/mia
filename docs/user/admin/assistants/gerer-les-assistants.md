---
title: Gérer les assistants
description: Lire la liste de tous les assistants, désactiver ou supprimer, reprendre ceux d'un compte supprimé, tenir la bibliothèque d'avatars.
sidebar:
  order: 2
---

**Espaces → Assistants** — la tuile **Assistants** de la barre latérale — montre tous les assistants
de l'instance, y compris les assistants personnels des créateurs.

## Lire la liste

La page s'ouvre sur une recherche — **Qui vous aide aujourd'hui ?** — qui trouve un assistant par son
nom, sa description, son modèle ou son créateur. Dessous, un filtre : **Tous**, **Organisation**,
**Personnels**, et **Inactifs** s'il y en a. Puis deux colonnes, **Organisation** et **Personnels** — un créateur sans assistant partagé ne voit que la sienne.
Chaque ligne montre :

- l'**identité** : avatar ou initiale à sa couleur, nom — sa **description** vient en bulle au survol
  de la ligne, ou sur le petit **?** qui suit le nom (au doigt : toucher le **?**) ;
- en petit, ce qu'il emploie : son **modèle** (ou « Modèle de l'organisation »), le nombre de
  **bases**, de **documents de référence** et d'**outils** — et, pour l'assistant personnel d'un
  autre, **de** son créateur, ou **sans créateur** quand le compte a été supprimé ;
- une icône pour **qui le voit** — globe **Global**, groupe **Partagé**, cadenas **Personnel**, le
  détail au survol — et son état, **Actif** ou **Inactif** ;
- au survol, la bulle **Discuter** et le menu **⋯** : **Partager à l'organisation** ou **Rendre
  personnel**, **Autoriser dans les groupes →**, **Supprimer…**.

**Cliquer une ligne** ouvre la page de l'assistant : son état, qui le voit, son créateur et sa
dernière réponse ; ses chiffres des 30 derniers jours (réponses, personnes, conversations, crédits) ;
sa consigne, ce qu'il connaît, son modèle, ses outils ; **Qui le voit** — pour un assistant partagé,
les **groupes qui l'autorisent**, ou « Autorisé dans aucun groupe » ; et tout en bas son **schéma** :
son modèle et sa température, le début de son prompt, son état, qui le voit, ses bases, ses
documents et ses outils — voir [Lire le schéma d'un assistant](../../fonctionnalites/assistants/creer-un-assistant.md#lire-le-schéma-dun-assistant).
Une base liée hors du périmètre d'un créateur reste comptée dans son schéma, sans son nom. Son menu
**…** reprend celui de la ligne ; **Modifier** ouvre sa fiche.

La bulle **Discuter** n'apparaît pas sur l'assistant personnel d'un autre : il n'est visible que de
son créateur dans le chat.

## Désactiver un assistant

Ouvrez sa fiche et éteignez l'interrupteur **Actif** de la section **Identité**. Il disparaît du chat pour tout le
monde, créateur compris, et de la colonne **Assistants** des groupes. Ses conversations continuent en
mode standard. Le réactiver le rend à nouveau visible, dans les mêmes groupes.

## Supprimer un assistant

Menu **⋯** de sa ligne ou de sa page → **Supprimer…**, ou menu **…** de sa fiche → **Supprimer cet
assistant**. **L'action est
irréversible** : ses documents de référence, ses liens aux bases, aux outils et aux groupes
disparaissent ; ses conversations redeviennent des conversations standard.

Préférez la désactivation tant que vous n'êtes pas sûr.

## Reprendre les assistants d'un compte supprimé

Quand un créateur est supprimé, ses assistants restent ; ses assistants personnels sont marqués
**sans créateur** :

- ses assistants **partagés** continuent de servir, dans les mêmes groupes ;
- ses assistants **personnels** ne sont plus visibles de personne dans le chat.

Pour conserver un assistant personnel orphelin, menu **⋯** de sa ligne → **Partager à
l'organisation**, puis cochez-le dans les groupes voulus. Mieux vaut le faire **avant** de supprimer le compte : la fenêtre de
confirmation de suppression annonce le nombre d'assistants qui perdront leur créateur.

## Tenir la bibliothèque d'avatars

Le lien **Bibliothèque d'avatars**, en bas de la page, ouvre une fenêtre qui rassemble les images
proposées à la création d'un assistant. Après chaque geste, la fenêtre se rouvre.

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
| Un assistant personnel **sans créateur** que personne ne voit | Son créateur a été supprimé. Le partager et l'autoriser dans un groupe, ou le supprimer. |
| Un avatar a disparu de plusieurs assistants | Il a été supprimé de la bibliothèque. Choisir un autre avatar sur chaque fiche. |
