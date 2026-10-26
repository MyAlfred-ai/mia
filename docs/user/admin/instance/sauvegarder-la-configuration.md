---
title: Sauvegarder la configuration
description: Exporter la configuration de l'instance, savoir ce qu'elle contient et ne contient pas, et la restaurer.
sidebar:
  order: 5
---

**Administration → Maintenance → Données → Sauvegarde.** L'export de la configuration est une copie de ce que vous avez
réglé : à faire avant une série de changements, ou pour garder une trace. Ce n'est **pas** une
sauvegarde complète de l'instance.

## Ce que contient l'export

| Contient | Ne contient pas |
|---|---|
| Utilisateurs et groupes, assistants, bases de connaissances et leurs documents, outils, clés, réglages, avatars, logos, modèle PowerPoint de l'entreprise | Les **conversations** et leurs messages, les liens de partage, l'historique de consommation, les photos de profil |

L'archive contient les mots de passe hachés et les clés chiffrées de l'instance : **conservez-la en
lieu sûr**, comme une clé.

Les sauvegardes complètes — conversations comprises — sont assurées par MyAlfred.

## Exporter

Cliquez **Télécharger la sauvegarde**. Vous obtenez une archive nommée d'après l'application et la
date et l'heure : `MIA-sauvegarde-AAAA-MM-JJ-HHMMSS.zip`.

## Restaurer

**La restauration remplace** les utilisateurs, groupes, assistants, bases, outils, clés et réglages
par ceux de l'archive. **C'est tout ou rien.**

1. Choisissez l'archive `.zip`.
2. Tapez **REMPLACER** pour confirmer, puis **Restaurer**.

Les bases de connaissances sont ensuite réindexées en arrière-plan. Une archive issue d'une version
plus récente de MIA est refusée.

Avant de restaurer, contactez MyAlfred : une restauration mal choisie peut séparer des conversations
de leurs utilisateurs.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| L'archive est refusée | Elle vient d'une version plus récente de MIA. Voir avec MyAlfred. |
| Des bases affichent « Vectorisation en cours » après une restauration | Normal : la réindexation tourne en arrière-plan. |
