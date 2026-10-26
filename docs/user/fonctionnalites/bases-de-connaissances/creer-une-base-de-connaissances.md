---
title: Créer une base de connaissances
description: Constituer une base de documents, suivre son indexation, la lier à des assistants et la partager.
sidebar:
  order: 2
---

Réservé aux comptes **Créateur** et aux administrateurs. Une base se crée une fois, puis vit : on y
ajoute des documents, on la relie à de nouveaux assistants, on l'ouvre à d'autres équipes.

## Avant de commencer

- Le droit de créer des bases — le rôle **Créateur** ou administrateur, par défaut.
- Une place libre : un créateur a droit à **5 bases**. Les bases qu'on vous partage ne comptent pas.
- Des documents **dont le texte est lisible** : PDF texte, Word, Excel, PowerPoint, OpenDocument,
  EPUB, HTML, RTF, texte, Markdown, CSV, JSON. Les images (JPG, PNG, GIF, WebP) sont décrites par
  l'IA, et servent aussi de modèles à l'outil image. Un PDF scanné ne donne aucun texte.
- 20 documents au plus, 20 Mo chacun. Au-delà, ou pour un contenu qui change souvent, préférez
  [un dossier synchronisé](synchroniser-une-base-avec-un-dossier.md).

## Étapes

1. Ouvrez vos bases : la tuile **Bases** de la barre latérale du chat, dans la rangée **Espaces**.
2. Cliquez **+ Nouvelle base**. La fiche de la base s'ouvre en pleine page ; à sa droite (sous le
   formulaire sur un écran étroit), le schéma de la base se compose à mesure que vous la remplissez.
3. **Identité** :
   - **Nom** *(obligatoire)* ;
   - **Description** : aide à reconnaître la base ; elle n'est pas envoyée au modèle.
4. **Sources** : laissez **Fichiers déposés** et glissez vos documents sur la zone de dépôt — ou
   cliquez-la pour les choisir, plusieurs à la fois.
5. **Assistants liés** : cochez les assistants qui consulteront la base. Vous ne voyez que les vôtres.
6. **Partage** : laissez tout décoché pour garder la base pour vous (voir plus bas).
7. **Avancé**, en bas, replié : le **Modèle d'embedding**, le moteur qui calcule l'empreinte de sens
   des extraits. Laissez le choix proposé, sauf consigne de votre administrateur. Pour en changer plus
   tard, **Réindexer** recalcule toutes les empreintes avec le modèle de votre choix.
8. Cliquez **Créer la base**.

## Résultat

Le message « Base de connaissances créée. » s'affiche avec le nombre de documents ajoutés, et la
liste s'ouvre sur la nouvelle base, son schéma affiché. La carte suit l'avancement **en direct**,
sans recharger la page : **Indexation en cours** avec le nombre de documents prêts, puis **Prête**
quand tout est interrogeable.

Sur la fiche de la base, chaque document porte son état :

| État | Signification |
|---|---|
| **En attente** | Le document attend son tour |
| **Lecture…** | Le texte du fichier est lu |
| **Vectorisation…** | Ses extraits reçoivent leur empreinte |
| **Indexé** · N extraits interrogeables | Le document est interrogeable |
| **Sans texte** | Aucun texte exploitable — document scanné, image sans texte : il ne ressortira d'aucune recherche |
| **Illisible** | Le fichier n'a pas pu être lu ; le motif s'affiche sous son nom |
| **Non vectorisé** | Absent de la recherche par le sens, consultable par mots-clés seulement ; le motif s'affiche |

Les documents à vérifier sont listés en premier, et **Voir seulement ceux-là** les isole. La base
devient interrogeable dès qu'**un** document est prêt.

## Lire le schéma de la base

Cliquez une carte de la liste : le schéma de la base s'affiche à droite (sous la carte sur un écran
étroit ; un second clic le replie). C'est le même que celui d'un assistant ou d'une tâche programmée :

- **au-dessus, ce qui l'alimente** : les fichiers déposés, avec leur état, et le dossier
  synchronisé — **Auto** ou **Manuelle**, son rythme ;
- **à gauche, comment elle est cherchée** : le modèle d'embedding, ses dimensions et le nombre
  d'extraits ;
- **au centre, la base** : **Active** ou **Inactive**, son état, ses documents, ses utilisations ;
- **en dessous, ce qu'elle sert** : les assistants liés et les personnes ou groupes qui y ont accès.

Au-delà de cinq éléments, un rectangle dit combien il en reste. Sur la fiche, le schéma suit chaque
changement, et un clic sur un élément mène à son réglage.

## Faire vivre la base

**Modifier** sur sa carte — ou son nom — ouvre sa fiche : les modifications s'enregistrent seules,
**Retour à la liste** ramène à la liste.

- **Ajouter des documents** : section **Sources**, glissez-les sur la zone de dépôt ou cliquez-la —
  plusieurs à la fois.
- **Retirer un document** : la corbeille sur sa ligne.
- **Lier à un assistant** : section **Assistants liés** — ou, depuis la fiche de l'assistant,
  section **Connaissances**.
- **Désactiver** : l'interrupteur **Active**, à côté du titre. Les assistants liés cessent de la
  consulter, sans message.
- **Réindexer** : section **Avancé**. Choisissez le **Modèle d'embedding** dans **Réindexer avec** :
  gardez l'actuel — après une panne d'indexation — ou choisissez-en un autre pour changer de moteur ;
  le texte dessous dit ce qui va se passer. **Réindexer la base** recalcule les empreintes de tous les
  documents, à partir des textes déjà extraits. Pendant l'opération, la carte affiche
  **Réindexation en cours** et sa progression, et la base répond par les mots. Un document
  **Illisible**, lui, doit être supprimé puis redéposé.
- **Supprimer la base** : **Supprimer cette base**, en bas de sa fiche. **Irréversible** : les
  assistants liés perdent cette source.

## Voir les extraits de la base

Section **Avancé**, **Extraits vectorisés** : ce que la recherche retrouve vraiment. Le nombre
d'extraits et de tokens, puis chaque extrait tel qu'il est enregistré, document par document — son
numéro, ses tokens, son texte — et son empreinte :

| Mention | Signification |
|---|---|
| **Vectorisé** | La recherche par le sens le retrouve |
| **Sans vecteur** | Seule la recherche par mots le retrouve — **Réindexer** recalcule l'empreinte |
| **Vecteur non vérifié** | Le moteur d'indexation ne répond pas : impossible de le vérifier pour l'instant |

Choisissez un document, ou cherchez un mot dans le texte des extraits : c'est le bon endroit pour
comprendre pourquoi une question ne fait pas remonter le passage attendu.

## Partager la base

Section **Partage** de la fiche, visible de son seul propriétaire. Par défaut, la base est **Privée — vous seul
et les administrateurs**.

- **Avec des personnes** : administrateurs et créateurs.
- **Avec des groupes** : c'est la seule façon d'ouvrir une base à des utilisateurs sans rôle de
  créateur.
- **peut modifier** : le destinataire gère les documents, la synchronisation et la réindexation. Il
  ne peut ni supprimer la base, ni la partager à son tour.

> **Partager une base, c'est partager son contenu.** Toute personne qui y a accès — directement ou
> par un assistant qui la consulte — peut en obtenir les passages en posant la bonne question,
> quels que soient ses droits sur les fichiers d'origine.

## Ce que ça coûte

L'analyse et l'indexation des documents vous sont décomptées **hors quota** : alimenter une base ne
vous prive pas de conversation. Une réindexation est décomptée à nouveau.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| **+ Nouvelle base** est grisé — « Configurez une clé API d'embedding » | Aucun moteur d'indexation n'est configuré sur l'instance. Voir avec un administrateur. |
| **+ Nouvelle base** est grisé — « Quota de bases atteint » | Supprimez une base, ou demandez à un administrateur d'en reprendre une. |
| « Limite atteinte : vous ne pouvez pas dépasser 5 bases de connaissances. » | Même cause. |
| « Limite atteinte : 20 documents maximum par base. » | Retirez des documents, ou synchronisez un dossier. |
| « Fichier « X » invalide (taille max 20 Mo). » | Réduisez ou découpez le fichier. |
| « Format non supporté pour « X ». » | Convertissez le fichier dans un format accepté. |
| Un document reste **En attente** longtemps | Les documents passent l'un après l'autre : patientez. S'il ne bouge plus après quelques minutes, rechargez la page, puis voyez avec un administrateur. |
