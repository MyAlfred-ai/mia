---
title: Corriger un document en échec
description: La fiche d'un document — ses extraits, ce qui ne va pas et pourquoi — et quoi faire d'un document qui ne s'indexe pas — le relancer, ou l'exclure de la base.
sidebar:
  order: 9
---

Chaque document d'une base a sa **fiche** : où en est son traitement, ses extraits, et — s'il n'est
pas interrogeable — **pourquoi**, avec le geste qui convient : le **relancer** quand un nouvel essai
peut réussir, l'**exclure** quand c'est le fichier qu'il faut corriger.

## Avant de commencer

- Voir la fiche : voir la base. Relancer, exclure ou rétablir : **gérer** la base (l'avoir créée, ou
  l'avoir reçue avec **peut modifier**).
- La base système de l'assistant d'aide ne se modifie pas.

## Ouvrir la fiche d'un document

1. Ouvrez la page de la base (tuile **Bases**, puis la base) — ou sa fiche, avec **Modifier**.
2. Dans la liste des documents, cliquez un document — n'importe lequel. L'onglet **À vérifier**
   rassemble ceux qui posent problème.
3. Sa fiche s'ouvre dans un volet, à droite (en plein écran sur un téléphone) :
   - **en tête**, son nom et son état ;
   - ce qu'il est : type, poids, déposé ou repris d'un dossier, et quand ;
   - **ce qui ne va pas**, s'il y a lieu : la cause en clair, **Que faire**, et les boutons ;
   - **les trois étapes** de son traitement : **Lecture du texte** (ou **Description de l'image**),
     **Découpage en extraits**, **Recherche par le sens** — chacune faite, en cours, en échec ou à
     faire —, puis l'heure de sa **dernière indexation**, dans l'heure de votre appareil ;
   - **ses extraits**, avec une recherche dans ce seul document.

‹ › passent au document précédent ou suivant de la liste ; la croix ou Échap referment. La fiche se
met à jour seule pendant un traitement.

## Relancer un document

Quand la cause est passagère — le fournisseur ne répondait pas, le traitement s'est arrêté en route,
une clé manquait et a été ajoutée depuis —, la fiche propose **Réessayer**. MIA ne refait que ce qui
a échoué : si le texte a déjà été lu et découpé, seule la recherche par le sens est recalculée.

Un document repris d'un dossier synchronisé et disparu du stockage de MIA est retéléchargé au
prochain passage de la synchronisation.

**Réessayer** n'apparaît pas quand un nouvel essai ne changerait rien : la fiche le dit.

## Exclure un document

Quand c'est le fichier qu'il faut corriger — un PDF numérisé sans texte, un fichier protégé par un
mot de passe, un format que MIA ne lit pas —, **Exclure de la base** l'écarte : il n'est plus cherché
et ne compte plus parmi les problèmes de la base. Le fichier est gardé : **Rétablir**, sur sa fiche,
le relit depuis le début. L'onglet **Exclus** les rassemble.

Un document repris d'un dossier synchronisé ne se supprime pas depuis MIA : on l'exclut. La
synchronisation ne le reprend pas tant qu'il ne change pas ; corrigé dans le dossier, il est réessayé
de lui-même.

## Ce que la fiche peut dire

| Ce qui ne va pas | Ce que ça veut dire | Le geste |
|---|---|---|
| **Traitement interrompu** | Le traitement s'est arrêté en route (redémarrage, mémoire) et plus rien ne tourne pour lui | **Réessayer** |
| **Lecture impossible** | Le texte du fichier n'a pas pu être lu | **Réessayer** une fois, puis l'exclure ou le remplacer |
| **Image non décrite** | Le modèle qui décrit les images n'a pas répondu, ou il n'y en avait aucun | **Réessayer** (quand un modèle est disponible) |
| **Vectorisation refusée par le fournisseur** | Le fournisseur n'a pas répondu, a limité les demandes, ou a refusé la clé ; les extraits restent trouvables par mots-clés | **Réessayer** |
| **Moteur vectoriel injoignable** | Le moteur qui range les extraits ne répondait pas | **Réessayer** quand il répond |
| **Document numérisé** | Un PDF fait d'images de pages : pas de texte, et MIA ne fait pas de reconnaissance de caractères | Le passer à l'OCR et le remplacer, ou l'**exclure** |
| **Protégé par un mot de passe** | Le fichier est chiffré | En enregistrer une copie sans mot de passe, ou l'**exclure** |
| **Fichier vide**, **Aucun texte trouvé** | Il n'y a rien à lire | Le remplacer, ou l'**exclure** |
| **Format non pris en charge** | MIA ne lit pas ce type de fichier | L'enregistrer en PDF, Word, Excel ou texte, ou l'**exclure** |
| **Fichier introuvable** | Le fichier n'est plus sur le stockage de MIA | Le redéposer — ou **Réessayer** pour un dossier synchronisé |

Qui gère la base voit aussi un **Détail technique** : le message d'origine de l'erreur.

## Exemples

**Un fournisseur saturé.** Trois documents déposés pendant une panne s'affichent **Non vectorisé**.
Une heure plus tard, la fiche de chacun propose **Réessayer** : un clic, et ils passent **Indexé**.

**Des scans dans un dossier synchronisé.** Deux contrats scannés gardent la base « À vérifier ».
**Exclure de la base** les écarte : la base redevient **Prête**. Le jour où une version passée à
l'OCR remplace l'un d'eux dans le dossier, la synchronisation le relit.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| **Réessayer** et **Exclure** n'apparaissent pas | Vous ne gérez pas cette base : demandez à son créateur |
| « Une synchronisation ou une réindexation de la base est en cours » | Attendez la fin du passage, puis recommencez |
| Le même échec revient après **Réessayer** | Ouvrez le **Détail technique** ; si la cause tient au fichier, excluez-le ou remplacez-le |

## Voir aussi

- [Consulter une base](consulter-une-base.md)
- [Créer une base de connaissances](creer-une-base-de-connaissances.md)
- [Synchroniser une base avec un dossier](synchroniser-une-base-avec-un-dossier.md)
