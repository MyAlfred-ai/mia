---
title: Synchroniser une base avec un dossier
description: Alimenter une base depuis un dossier Google Drive, OneDrive ou SharePoint, et la laisser se tenir à jour seule.
sidebar:
  order: 3
---

Une base synchronisée reprend le contenu d'un dossier en ligne et suit ses changements : un fichier
ajouté, modifié ou retiré du dossier l'est aussi dans la base, sans que personne ne redépose rien.

## Avant de commencer

- Une base à vous, ou qu'on vous a partagée avec **peut modifier** — voir
  [Créer une base de connaissances](creer-une-base-de-connaissances.md).
- Pour un dossier **Google Drive**, l'une des deux voies :
  - **Ma connexion Google Drive** : votre Drive est relié dans **Réglages → Comptes connectés** — voir
    [Relier un service](../connecteurs/relier-un-service.md) ;
  - **Clé API globale** : le dossier est partagé en « Tous les utilisateurs disposant du lien », et
    votre administrateur a enregistré la clé de l'instance.
- Pour **OneDrive**, votre OneDrive relié dans **Réglages → Comptes connectés**. Pour **SharePoint**, une
  configuration faite par votre administrateur.

## Étapes

1. Ouvrez la fiche de la base (**Modifier** sur sa carte, ou **+ Nouvelle base**), section
   **Sources**, et choisissez la tuile **Google Drive** — ou **OneDrive**, **SharePoint**.
2. Choisissez l'**Accès au dossier** :
   - **Ma connexion Google Drive** : **Parcourir…** ouvre l'explorateur sous le champ ; choisissez
     **Mon Drive**, **Drive partagés** ou **Partagés avec moi**, descendez jusqu'au dossier et
     cliquez **Choisir ce dossier** ;
   - **Clé API globale** : collez le lien du dossier, puis **Tester**.
3. Laissez cochée **Synchronisation automatique, toutes les N minutes**.
4. Sur une base existante, c'est enregistré : rien à valider. Pour une nouvelle base, cliquez
   **Créer la base**. Un premier cycle démarre aussitôt.

## Résultat

La carte de la base montre où en est la synchronisation, **en direct** : la page se met à jour
seule.

| Sur la carte | Signification |
|---|---|
| **Synchronisation en attente** | Le passage est demandé ; il démarre dès que le serveur est disponible |
| **Synchronisation en cours** · 12 / 40 | Le passage traite le 12ᵉ fichier sur 40, dont le nom s'affiche |
| **À jour** · il y a 4 min | Dernier passage réussi, documents interrogeables |
| **N documents en échec** ou **sans texte** | Le passage a réussi, mais des documents sont à vérifier |
| **Synchronisation en échec** | Le passage n'a pas abouti, le motif s'affiche. La base sert son dernier contenu |
| **Synchronisation interrompue** | Le passage s'est arrêté avant la fin. Relancez-le |
| **Synchronisation en retard** | Les passages attendus n'ont plus lieu |

Le bouton ↻ de la carte relance un passage sans ouvrir la base.

Sur sa fiche, le panneau d'état, en tête, détaille : bilan du dernier passage — fichiers **ajoutés**, **mis
à jour**, **renommés**, **retirés**, en **échec**, **non repris** —, prochain passage, nombre de
documents interrogeables, et la liste des fichiers **non repris** avec leur motif. **Synchroniser
maintenant** lance un passage sans attendre.

Chaque fichier repris suit ensuite les mêmes états qu'un document déposé à la main.

## Ce qui est repris

- Les fichiers de formats acceptés, **sous-dossiers compris**, jusqu'à 50 Mo chacun.
- Les Google Docs, Sheets et Slides, convertis en Word, Excel et PowerPoint.
- **100 fichiers au plus** : au-delà, les premiers par ordre alphabétique.
- Les ajouts, modifications, renommages, suppressions, sorties du dossier et mises à la corbeille.

Les documents déposés à la main dans la même base ne sont jamais touchés. Un document synchronisé,
lui, se retire **du dossier source**, pas de la base.

## Cas particuliers

- **Un Drive partagé** : **Drive partagés** liste ceux dont vous êtes membre. Ouvrez-en un, puis
  cliquez **Choisir ce dossier** pour reprendre le Drive entier, ou descendez jusqu'au dossier voulu.
  Avec la **Clé API globale**, collez plutôt le lien d'un dossier du Drive partagé, partagé en « Tous
  les utilisateurs disposant du lien » — si votre organisation l'autorise.
- **Changer de dossier** : les documents de l'ancien dossier quittent la base au premier cycle du
  nouveau. Un avertissement le rappelle.
- **Revenir à « Fichiers déposés »** : la synchronisation s'arrête, les documents restent et
  redeviennent supprimables à la main.
- **Agenda Google** : une source à part, qui indexe un agenda public, mois par mois — utile pour un
  calendrier de formations ou d'événements.
- **Une base synchronisée est une copie.** Ceux qui interrogent la base lisent son contenu sans
  avoir besoin d'accès au dossier d'origine. Ne synchronisez que ce que tous les utilisateurs de la
  base peuvent lire.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Synchronisation en échec » sur la carte, avec un motif | La base continue de servir son dernier contenu. Corriger la cause indiquée, puis **Réessayer** : ↻ sur la carte. |
| « Synchronisation interrompue » | Le serveur a redémarré pendant le passage. Relancer : ↻ sur la carte. |
| « Synchronisation en retard », ou « en attente » qui dure | Les passages automatiques ne tournent plus sur le serveur : prévenir un administrateur. |
| « Dossier introuvable ou non partagé… » | Vérifier le lien et le partage du dossier, ou passer par **Ma connexion Google Drive**. |
| « Cette base se synchronise via la connexion Google Drive de … , qui n'est plus disponible » | La personne qui a relié le dossier a déconnecté son Drive, ou son compte a été supprimé. Relier le dossier avec une autre connexion. |
| **Ma connexion Google Drive** est grisée | Votre Drive n'est pas relié : **Réglages → Comptes connectés**. |
| « Votre compte n'est membre d'aucun Drive partagé » | Un dossier qu'on vous a partagé sans vous ajouter à son Drive se trouve dans **Partagés avec moi**. Sinon, demandez à être ajouté au Drive partagé. |
| « MIA n'a pas l'autorisation de voir les fichiers de ce Drive… » | À la connexion de votre Drive, une case de l'écran Google a été décochée. **Réglages → Comptes connectés** → **Reconnecter et autoriser**, en laissant toutes les cases cochées. |
| Des fichiers « au-delà des 100 fichiers repris » parmi les fichiers non repris | Le dossier dépasse 100 fichiers. Le découper en plusieurs bases. |
