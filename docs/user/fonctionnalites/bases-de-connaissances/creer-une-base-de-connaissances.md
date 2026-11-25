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
- Des documents **dont le texte est lisible** : PDF texte, Word, Excel et PowerPoint (y compris les
  anciens .doc, .xls et .ppt), OpenDocument, emails enregistrés (.eml, .msg), archives ZIP,
  EPUB, HTML, RTF, et tout fichier dont le contenu est du texte, quelle que soit son extension
  (Markdown, CSV, JSON, XML, YAML, code, scripts .bat, .ps1, .sh, .py…). Les images (JPG, PNG, GIF, WebP) sont décrites par
  l'IA, et servent aussi de modèles à l'outil image. Un PDF scanné ne donne aucun texte, un fichier
  protégé par un mot de passe non plus.
- 20 documents au plus, 20 Mo chacun. Au-delà, ou pour un contenu qui change souvent, préférez
  [un dossier synchronisé](synchroniser-une-base-avec-un-dossier.md).

## Étapes

1. Ouvrez vos bases : la tuile **Bases** de la barre latérale du chat, dans la rangée **Espaces**.
2. Cliquez la carte **Nouvelle base**, sous la recherche. La fiche s'ouvre en pleine page : à gauche,
   le menu de ses sections ; en haut, une barre qui reste en vue, avec **Annuler** et **Créer la
   base**. Un point **ⓘ** à côté d'un réglage en donne le détail — au survol, ou en le touchant.
3. **Identité** : le **Nom** *(obligatoire)* et une **Description**, qui aide à reconnaître la base
   (elle n'est pas envoyée au modèle). L'interrupteur **Active** est allumé d'office.
4. **Documents** : laissez **Fichiers déposés** et glissez vos documents sur la zone de dépôt — ou
   cliquez-la pour les choisir, plusieurs à la fois. Seules les sources que votre organisation a
   configurées sont proposées ; une ligne dit lesquelles un administrateur peut ajouter.
5. **Qui la voit** : trois choix, chacun avec son icône :
   - **Privée** (cadenas) : vous seul — pas même un administrateur (voir
     [Réserver une base à vous seul](reserver-une-base.md)) ;
   - **Commune** (groupe) : vous, les administrateurs, et les groupes ou personnes que vous ajoutez —
     le choix proposé ;
   - **Publique** (globe) : toute l'organisation la voit et la coche dans le chat ; seuls vous et les
     administrateurs la modifiez.
6. **Assistants** : **Lier un assistant** déplie vos assistants ; cochez ceux qui consulteront la
   base. Chacun apparaît en pastille ; la croix le retire.
7. **Avancé**, replié : le **Modèle d'embedding**, le moteur qui calcule l'empreinte de sens des
   extraits. Laissez le choix proposé, sauf consigne de votre administrateur.
8. Tout en bas, la **Vue d'ensemble** montre la base telle qu'elle sera, mise à jour à chaque
   réglage. Cliquez **Créer la base** — en haut, ou sous la vue d'ensemble.

## Résultat

Le message « Base de connaissances créée. » s'affiche et la **page de la base** s'ouvre : son état,
ses documents, qui s'en sert, qui la voit, et son schéma tout en bas. L'état suit l'avancement **en
direct** : **Indexation en cours** avec le nombre de documents prêts, puis **Prête** quand tout est
interrogeable. Voir [Consulter une base](consulter-une-base.md).

Chaque document porte son état :

| État | Signification |
|---|---|
| **En attente** | Le document attend son tour |
| **Lecture…** | Le texte du fichier est lu |
| **Vectorisation…** | Ses extraits reçoivent leur empreinte |
| **Indexé** · N extraits interrogeables | Le document est interrogeable |
| **Sans texte** | Aucun texte exploitable — document scanné, image sans texte : il ne ressortira d'aucune recherche |
| **Illisible** | Le fichier n'a pas pu être lu ; le motif s'affiche sous son nom |
| **Non vectorisé** | Absent de la recherche par le sens, consultable par mots-clés seulement ; le motif s'affiche |
| **Interrompu** | Son traitement s'est arrêté en route et plus rien ne tourne pour lui |
| **Exclu** | Écarté de la base : il n'est plus cherché ; on peut le rétablir |

Les documents à vérifier sont listés en premier. La base devient interrogeable dès qu'**un** document
est prêt. Cliquez un document pour ouvrir sa fiche : pourquoi il pose problème, et le geste qui
convient — le relancer ou l'exclure. Voir [Corriger un document en échec](corriger-un-document-en-echec.md).

## Lire le schéma de la base

Le schéma ferme la page de la base, sa fiche et la fiche de création, sous **Vue d'ensemble**. C'est
le même que celui d'un assistant ou d'une tâche programmée :

![La vue d'ensemble de la base Catalogue produits 2026, en bas de sa page : au-dessus, ses cinq fichiers déposés, tous indexés ; à gauche, le modèle d'embedding et ses extraits ; au centre, la base, Active et Prête ; dessous, l'assistant Fiches produits et qui la voit.](../../images/base-de-connaissances-schema.png)

- **au-dessus, ce qui l'alimente** : les fichiers déposés, avec leur état, et le dossier
  synchronisé — **Auto** ou **Manuelle**, son rythme ;
- **à gauche, comment elle est cherchée** : le modèle d'embedding, ses dimensions et le nombre
  d'extraits ;
- **au centre, la base** : **Active** ou **Inactive**, son état, ses documents, ses utilisations, et
  qui la voit — **Privée**, **Commune** ou **Publique** ;
- **en dessous, ce qu'elle sert** : les assistants liés et les personnes ou groupes qui y ont accès.

Au-delà de cinq éléments, un rectangle dit combien il en reste. Sur la fiche, le schéma suit vos
réglages en direct, et cliquer un de ses éléments mène à la section qui le règle.

## Pour une base efficace

Une base sert d'autant mieux que ses documents sont clairs. En bref — c'est aussi la section
**Pour une base efficace**, repliée sous les documents de sa fiche :

- **Un sujet par base** : les RH dans une base, les produits dans une autre. La recherche vise plus
  juste, et chacun coche celle qui lui sert.
- **Du vrai texte** : PDF issus d'un traitement de texte, Word, pages web, Markdown. Un scan ou une
  photo de page ne contient pas de texte : MIA n'y trouve rien.
- **Une seule version de chaque document** : deux versions qui se contredisent donnent des réponses
  qui se contredisent.
- **Des titres et des paragraphes** : MIA découpe chaque document en extraits de quelques centaines
  de mots, de préférence aux titres ; un document bien structuré donne des extraits qui ont du sens
  seuls.
- **Le volume ne coûte pas plus cher** : à chaque question, seuls les extraits les plus proches
  partent au modèle. Peu importe le nombre de documents, pourvu qu'ils parlent du sujet.
- **Des chiffres à calculer ?** La recherche retrouve des lignes de tableur, pas des totaux : joignez
  plutôt le fichier dans la conversation.

Quand une base indexée sert mal, MIA le signale : **N points à améliorer** sur la page de la base
et sous le titre de sa fiche, qui ouvre le détail.

| Point à améliorer | Ce qu'il faut changer |
|---|---|
| **Document sans texte** | Un scan ou une photo de page : déposez une version texte, ou passez le scan à la reconnaissance de texte |
| **Très peu de texte** | La base ne contient que quelques lignes : déposez les documents de référence complets |
| **Plusieurs versions d'un même document** | Gardez seulement la version à jour |
| **Surtout des tableaux** | Pour calculer sur des chiffres, joignez le fichier dans la conversation ; gardez dans la base les textes qui expliquent |
| **Base très volumineuse** | Si elle mêle plusieurs sujets, découpez-la en bases thématiques |

Ce sont des conseils : la base reste utilisable. Un point disparaît de lui-même une fois corrigé.

## Faire vivre la base

Sur la page de la base, **Modifier** ouvre sa fiche : les modifications s'enregistrent seules —
la barre du haut le dit —, **Terminé** ramène à la page de la base. Le menu de gauche mène à chaque
section (**Identité**, **Documents**, **Qui la voit**, **Assistants**, **Avancé**, **Vue d'ensemble**)
et suit la lecture ; sur un téléphone, il devient une rangée d'onglets en haut. Sous le titre, une
ligne résume la base : ses documents, son état, les points à améliorer. Les **Réglages du dossier**
d'une base synchronisée se replient sur une ligne (dossier, rythme, accès) : ils se rouvrent dès que
vous changez de source.

- **Ajouter des documents** : section **Documents**, glissez-les sur la zone de dépôt ou cliquez-la —
  plusieurs à la fois.
- **Retirer un document** : la corbeille sur sa ligne.
- **Lier à un assistant** : section **Assistants**, **Lier un assistant** — ou, depuis la fiche de
  l'assistant, section **Connaissances**.
- **Désactiver** : l'interrupteur **Active**, section **Identité**. Les assistants liés cessent de la
  consulter, sans message.
- **Réindexer** : section **Avancé** — ou **…** dans la barre du haut, **Réglages avancés**. Choisissez
  le **Modèle d'embedding** dans **Réindexer avec** : gardez l'actuel — après une panne d'indexation —
  ou choisissez-en un autre pour changer de moteur ; le texte dessous dit ce qui va se passer.
  **Réindexer la base** recalcule les empreintes de tous les documents, à partir des textes déjà
  extraits. Pendant l'opération, l'état affiche **Réindexation en cours** et sa progression, et la
  base répond par les mots. Un document **Illisible** se relance ou s'exclut depuis sa fiche : voir
  [Corriger un document en échec](corriger-un-document-en-echec.md).
- **Supprimer la base** : **…** dans la barre du haut, **Supprimer cette base**. **Irréversible** :
  les assistants liés perdent cette source.

## Voir les extraits de la base

**Cliquez un document** de la liste, sur la page de la base ou sur sa fiche : sa fiche s'ouvre dans
un volet, à droite (en plein écran sur un téléphone) — ses étapes, ce qui ne va pas s'il y a lieu,
puis ses extraits. Cherchez un mot dans ce document, passez au document précédent ou suivant avec
‹ ›, refermez avec la croix ou Échap.

Pour toute la base d'un coup : section **Avancé**, **Extraits vectorisés** : ce que la recherche
retrouve vraiment. Le nombre
d'extraits et de tokens, puis chaque extrait tel qu'il est enregistré, document par document — son
numéro, ses tokens, son texte — et son empreinte :

| Mention | Signification |
|---|---|
| **Vectorisé** | La recherche par le sens le retrouve |
| **Sans vecteur** | Seule la recherche par mots le retrouve — **Réindexer** recalcule l'empreinte |
| **Vecteur non vérifié** | Le moteur d'indexation ne répond pas : impossible de le vérifier pour l'instant |

Choisissez un document, ou cherchez un mot dans le texte des extraits : c'est le bon endroit pour
comprendre pourquoi une question ne fait pas remonter le passage attendu.

## Qui la voit

Section **Qui la voit**, réglée par son seul propriétaire. Trois choix, et la même icône partout
— liste, page de la base, schéma :

| Choix | Qui la voit | Qui la modifie |
|---|---|---|
| **Privée** (cadenas) | Vous seul, pas même un administrateur | Vous |
| **Commune** (groupe) | Vous, les administrateurs, et les groupes ou personnes ajoutés | Vous, les administrateurs, et ceux qui ont **peut modifier** |
| **Publique** (globe) | Toute l'organisation : chacun la coche dans le chat | Vous et les administrateurs |

Seul le créateur d'une base peut la rendre **Privée** — voir
[Réserver une base à vous seul](reserver-une-base.md).

Pour une base **Commune**, **+ Ajouter** déplie la liste des groupes et des personnes. Ceux que vous
cochez apparaissent en pastilles, avec leurs droits ; toucher une pastille rouvre sa ligne, la croix
retire le partage. Passer en **Privée** ou en **Publique** retire les partages accordés, après
confirmation : ils ne reviennent pas d'eux-mêmes.

- **Avec des personnes** : administrateurs et créateurs.
- **Avec des groupes** : pour ouvrir une base à une partie de l'organisation seulement.
- **peut modifier** : le destinataire gère les documents, la synchronisation et la réindexation. Il
  ne peut ni supprimer la base, ni la partager à son tour.

> **Partager une base, c'est partager son contenu.** Toute personne qui y a accès — directement ou
> par un assistant qui la consulte — peut en obtenir les passages en posant la bonne question,
> quels que soient ses droits sur les fichiers d'origine. Une base **Publique** s'ouvre à toute
> l'organisation : n'y mettez que ce que tout le monde peut lire.

## Ce que ça coûte

L'analyse et l'indexation des documents vous sont décomptées **hors quota** : alimenter une base ne
vous prive pas de conversation. Une réindexation est décomptée à nouveau.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| La carte **Nouvelle base** est grisée — « Une clé API d'embedding est requise. » | Aucun moteur d'indexation n'est configuré sur l'instance. Voir avec un administrateur. |
| La carte **Nouvelle base** est grisée — « Limite de 5 bases atteinte. » | Supprimez une base, ou demandez à un administrateur d'en reprendre une. |
| « Limite atteinte : vous ne pouvez pas dépasser 5 bases de connaissances. » | Même cause. |
| « Limite atteinte : 20 documents maximum par base. » | Retirez des documents, ou synchronisez un dossier. |
| « Fichier « X » invalide (taille max 20 Mo). » | Réduisez ou découpez le fichier. |
| « Format non supporté pour « X ». » | Convertissez le fichier dans un format accepté. |
| Un document reste **En attente** longtemps | Les documents passent l'un après l'autre : patientez. S'il ne bouge plus après quelques minutes, rechargez la page, puis voyez avec un administrateur. |
