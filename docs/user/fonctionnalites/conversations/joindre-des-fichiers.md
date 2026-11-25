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
| Formats | PDF, Word (.docx et l'ancien .doc), Excel (.xlsx et l'ancien .xls), PowerPoint (.pptx et l'ancien .ppt), OpenDocument (.odt, .ods, .odp), EPUB, pages web (.html), RTF, emails enregistrés (.eml, .msg), archives ZIP, images (JPG, PNG, GIF, WebP — et HEIC, AVIF, TIFF, BMP, convertis en JPEG), et **tout fichier texte** : Markdown, CSV, JSON, XML, YAML, journaux, configuration, code, scripts (.bat, .ps1, .sh, .py…), agenda (.ics), sous-titres… quelle que soit son extension |
| Nombre | 10 fichiers par message |
| Taille | 20 Mo pour un PDF, 50 Mo pour une présentation PowerPoint (.pptx), 10 Mo pour tout autre fichier |

Le contenu des documents Word, Excel, PowerPoint, OpenDocument, EPUB, pages web, RTF et texte est lu par tous
les modèles, y compris celui des anciens fichiers Word 97-2003 (.doc) et Excel 97-2003 (.xls) : texte, tableaux,
notes de bas de page, chaque onglet d'un classeur, dates comprises — et des anciennes présentations PowerPoint
97-2003 (.ppt) : chaque diapositive dans l'ordre, notes du présentateur comprises. Un **email enregistré** (.eml,
.msg) se lit avec son objet, son expéditeur, ses destinataires, sa date, son texte et ses pièces jointes. Une
**archive ZIP** montre la liste de son contenu, et MIA lit les fichiers qu'elle sait lire (jusqu'à 20 par archive ;
une archive dans l'archive n'est pas ouverte). Une photo **HEIC** (iPhone, Mac), **AVIF**, **TIFF** (scanner) ou
**BMP** est convertie en JPEG à l'envoi. Un fichier protégé par un mot de passe ne se lit pas. **Un fichier dont le contenu est du texte se lit, même avec une extension que MIA ne connaît pas** ; un
fichier binaire (archive, exécutable, plan de CAO…) est refusé avec un message qui dit son format et ce que MIA
lit. Un **script** est lu comme du texte, jamais exécuté : MIA peut l'expliquer, le corriger ou le commenter. Les scripts
enregistrés par Windows en « Unicode » ou en « ANSI » sont lus correctement, accents compris. Les **PDF** et les
**images** sont transmis tels quels : seuls les modèles qui affichent **✓ Fichiers** et **✓ Images**
dans le bandeau les lisent — les autres **les ignorent sans prévenir**. Voir
[Les modèles de LLM](../modeles/index.md).

Pourquoi ces tailles : un **PDF** est envoyé entier au modèle, pour qu'il en voie aussi les images et la
mise en page — plus lourd, il ne passerait pas. D'une **présentation PowerPoint**, MIA ne lit que le texte :
elle peut donc être plus lourde. Un fichier trop lourd est refusé dès que vous le joignez, avec son poids et
la limite qui s'applique.

## Résultat

MIA répond en s'appuyant sur le contenu des fichiers joints.

Un **très long fichier texte** (journal de plusieurs Mo, export complet) n'est envoyé au modèle qu'en partie,
pour laisser la place à la conversation : MIA le dit, et lit la suite quand votre question l'exige (ligne
« Relecture d'un fichier de la conversation » dans la trace).

Dans une **très longue conversation**, un fichier joint il y a longtemps ne repart plus avec chaque
message : seul son nom reste. Quand votre question porte sur lui, MIA le relit (ligne
« Relecture d'un fichier de la conversation » dans la trace), par parties s'il est long. Une image ne
se relit pas, et un modèle qui n'a pas accès aux outils vous demandera de joindre le fichier à
nouveau.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « … pèse … : un PDF ne peut pas dépasser 20 Mo » | Réduire sa taille (« Réduire la taille » ou « Optimiser » dans votre lecteur PDF), ou le découper en plusieurs parties. |
| « … pèse … : une présentation PowerPoint ne peut pas dépasser 50 Mo » | Compresser ses images (dans PowerPoint : Fichier, puis Compresser les images) ou en retirer les vidéos. |
| « … pèse … : ce type de fichier ne peut pas dépasser 10 Mo » | Réduire le fichier, ou le découper. |
| « MIA ne sait pas lire … » | Le format n'est pas lu (archive .7z ou .rar, plan, logiciel…) : l'enregistrer en PDF, Word ou texte, ou le compresser en .zip. |
| « L'image … n'a pas pu être convertie en JPEG » | L'enregistrer en JPEG ou en PNG, puis la joindre à nouveau. |
| MIA ne semble pas lire l'image ou le PDF | Le bandeau indique **✗ Images** ou **✗ Fichiers** : choisir un autre modèle. |
| Le fichier est ignoré avec un outil engagé | Les outils page web, carte et vidéo ne lisent pas les fichiers joints. Retirer la pastille d'outil, ou décrire le contenu dans le message. |

> Une image peut être envoyée **sans texte** : c'est utile pour demander une retouche. Ajouter une
> consigne reste préférable pour préciser le résultat attendu.
