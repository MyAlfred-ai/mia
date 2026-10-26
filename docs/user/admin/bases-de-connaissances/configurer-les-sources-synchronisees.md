---
title: Configurer les sources synchronisées
description: Permettre aux créateurs de synchroniser leurs bases avec Google Drive, OneDrive, SharePoint ou un agenda Google, et régler le rythme.
sidebar:
  order: 2
---

**Administration → Capacités → Sources synchronisées.** Les identifiants dont les
créateurs ont besoin pour relier une base à un dossier en ligne. Réservé aux administrateurs ;
enregistrement automatique — « Sources synchronisées enregistrées. ».

## Avant de commencer

Selon les sources voulues, l'aide de votre service informatique : une clé d'API Google, une
application inscrite dans Microsoft Entra. Les droits exacts à leur donner, et les réglages de vos
consoles qui peuvent les bloquer :
[Autoriser MIA dans Microsoft Entra ID](../connecteurs/autoriser-mia-dans-microsoft-entra-id.md) —
OneDrive et SharePoint — et [Autoriser MIA dans Google Workspace](../connecteurs/autoriser-mia-dans-google-workspace.md)
— clés API et Ma connexion Google Drive.

## Les réglages

| Section | Champs | Ce qu'elle permet |
|---|---|---|
| **Google Drive** | **Clé API Google**, **Tester la connexion** | Synchroniser un dossier partagé « à tous ceux qui ont le lien » — mode **Clé API globale** |
| **SharePoint** | **ID de l'annuaire (tenant)**, **ID d'application (client)**, **Secret client** | Synchroniser un dossier SharePoint de l'organisation |
| **OneDrive** | L'application utilisée, puis les mêmes champs pour une **inscription dédiée** facultative, sa **Marche à suivre** et **Vérifier la configuration** | Permettre aux créateurs de relier leur OneDrive, puis d'en synchroniser un dossier |
| **Agenda Google** | **Clé API Google (Agenda)** | Indexer un agenda public |
| **Rythme** | **Intervalle entre deux cycles (minutes)** — 10 par défaut, de 1 à 1 440 | La fréquence des synchronisations automatiques |

Le mode **Ma connexion Google Drive**, lui, ne demande pas de clé : il passe par le connecteur Google
Drive du créateur, donc par les identifiants Google de **Organisation → Authentification** — voir
[Autoriser MIA dans Google Workspace](../connecteurs/autoriser-mia-dans-google-workspace.md). C'est aussi le mode
qui ouvre les **Drive partagés** dont le créateur est membre, sans aucun partage par lien.

OneDrive utilise **l'application Microsoft de la connexion** (**Organisation → Authentification**),
comme Outlook : il suffit d'y ajouter l'autorisation déléguée `Files.Read.All`, et la section dit en
tête quelle application sert. Une **inscription dédiée** reste possible — sa propre adresse de retour,
les autorisations `Files.Read.All`, `User.Read` et `offline_access`, détaillées par sa **Marche à
suivre** — et l'emporte dès que son **ID d'application** est saisi. Changer d'application oblige
chacun à relier son OneDrive de nouveau. SharePoint demande, lui, une autorisation
**d'application** `Files.Read.All`, qui ouvre la lecture de tous les fichiers de l'organisation : à
peser avant de l'accorder.

## Comment tournent les synchronisations

Un premier cycle démarre quand le créateur enregistre sa source. Ensuite, un cycle au plus par
intervalle, en tâche de fond. Chaque cycle répercute les ajouts, modifications, renommages et
suppressions du dossier. La carte de la base le suit en direct — **Synchronisation en attente**, puis
**en cours** avec le fichier traité, puis **À jour** — et la base ouverte détaille le bilan du dernier
cycle, le prochain, et les fichiers non repris avec leur motif.

Une synchronisation en échec n'interrompt pas la base : elle continue de servir son dernier contenu,
et sa carte affiche le motif en clair. Un cycle arrêté net — redémarrage du serveur — s'affiche
**Synchronisation interrompue** ; des cycles qui n'ont plus lieu, **Synchronisation en retard**.

## Retirer une source

Cochez **Supprimer la clé (désactive toutes les synchronisations Google Drive)** pour couper toutes
les synchronisations Google Drive en mode clé. Les documents déjà indexés restent dans les bases.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| **Tester la connexion** échoue | Vérifier la clé, et que l'API Google Drive est activée sur son projet Google Cloud. |
| Des bases affichent « Synchronisation en échec » | Lire le motif sur la carte : dossier non partagé, clé invalide, quota Google atteint, connexion du créateur coupée. |
| Des bases affichent « Synchronisation en retard » | Les cycles automatiques ne tournent plus : vérifier que le service de tâches de fond du serveur est démarré. |
| Une base reste « Synchronisation en attente » | Le service de tâches de fond est occupé ou arrêté : même vérification. |
| Un créateur ne voit pas **Dossier OneDrive** | Aucune application Microsoft n'est utilisable (lire l'encadré en tête de la section OneDrive), ou il n'a pas relié son OneDrive dans **Réglages → Comptes connectés**. |
| La section OneDrive dit **Inscription dédiée incomplète** | Un ID d'application dédié est saisi sans son secret : renseignez le secret, ou cochez **Supprimer l'inscription dédiée** pour revenir à l'application de la connexion. |
