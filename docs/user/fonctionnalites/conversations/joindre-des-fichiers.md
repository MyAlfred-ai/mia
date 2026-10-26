---
title: Joindre des fichiers
description: Ajouter des documents ou des images à un message pour que MIA les lise.
sidebar:
  order: 2
---

## Étapes

1. Cliquez le bouton **+** sous le champ de saisie, puis **Joindre des fichiers** — ou faites
   glisser vos fichiers dans le champ de saisie.
2. Les fichiers apparaissent en vignettes au-dessus du champ.
3. Écrivez votre demande et envoyez.

Sur téléphone, le bouton s'appelle **Fichiers**, et le bouton **Photo**, à côté, ouvre directement
l'appareil photo.

Sur Android, le téléphone demande avec quelle application choisir : la galerie ou le sélecteur de
photos pour une image, **Fichiers**, Drive… pour un document. Les explorateurs de fichiers montrent
aussi les images ; s'ils s'ouvrent sur un dossier de photos, leur menu ☰ donne accès aux autres
dossiers (Téléchargements, Documents…).

## Ce qui est accepté

| | |
|---|---|
| Formats | PDF, texte, Markdown, Word, Excel, images (JPG, PNG, GIF, WebP) |
| Nombre | 10 fichiers par message |
| Taille | 10 Mo par fichier |

Le contenu des documents Word, Excel, texte et Markdown est lu par tous les modèles. Les **PDF** et les
**images** sont transmis tels quels : seuls les modèles qui affichent **✓ Fichiers** et **✓ Images**
dans le bandeau les lisent — les autres **les ignorent sans prévenir**. Voir
[Les modèles de LLM](../modeles/index.md).

## Résultat

MIA répond en s'appuyant sur le contenu des fichiers joints.

Dans une **très longue conversation**, un fichier joint il y a longtemps ne repart plus avec chaque
message : seul son nom reste. Quand votre question porte sur lui, MIA le relit (ligne
« Relecture d'un fichier de la conversation » dans la trace), par parties s'il est long. Une image ne
se relit pas, et un modèle qui n'a pas accès aux outils vous demandera de joindre le fichier à
nouveau.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Fichier trop lourd (max 10Mo) » | Réduire le fichier, ou le découper. |
| MIA ne semble pas lire l'image ou le PDF | Le bandeau indique **✗ Images** ou **✗ Fichiers** : choisir un autre modèle. |
| Le fichier est ignoré avec un outil engagé | Les outils page web, carte et vidéo ne lisent pas les fichiers joints. Retirer la pastille d'outil, ou décrire le contenu dans le message. |

> Une image peut être envoyée **sans texte** : c'est utile pour demander une retouche. Ajouter une
> consigne reste préférable pour préciser le résultat attendu.
