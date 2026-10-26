---
title: Relier un service
description: Autoriser MIA à accéder à votre Google Drive, Gmail, Outlook, vos agendas, vos tâches ou votre Slack, vérifier ce qui est accordé, et se déconnecter.
sidebar:
  order: 1
---

Relier un service se fait une fois, depuis la page **Comptes connectés**. Ensuite, vous l'allumez dans les
conversations où vous en avez besoin : [Utiliser un connecteur](utiliser-un-connecteur.md).

## Avant de commencer

- Votre compte Google, Microsoft ou Slack professionnel.
- Vous ne voyez que les services que votre administrateur propose. Si aucune carte n'apparaît, la
  page l'indique : « Aucun service externe n'est proposé par votre administrateur pour le moment. »

## Étapes

1. Cliquez **Réglages**, en bas de la barre latérale, puis choisissez **Comptes connectés** dans le
   menu de gauche.
2. Sur la carte du service, cliquez **Connecter mon Google Drive** — ou **Connecter mon Gmail**,
   **Connecter mon Google Agenda**, **Connecter mes tâches Google**, **Connecter mon Outlook**,
   **Connecter mon calendrier Outlook**, **Connecter Microsoft To Do**, **Connecter Microsoft Teams**,
   **Connecter mon Slack**.
3. Choisissez votre compte sur l'écran de Google, de Microsoft ou de Slack.
4. **Laissez toutes les cases cochées** sur l'écran de consentement. Elles se décochent une à une, et
   chaque case oubliée retire en silence une capacité. Côté Microsoft, cet écran n'apparaît pas si
   votre organisation a déjà donné son accord pour tout le monde : le compte choisi, la connexion se
   fait.
5. Validez : vous revenez sur la page **Comptes connectés**.

## Résultat

La carte affiche **Connecté**, l'adresse du compte relié et la date. Des badges disent ce qui a été
accordé :

| Service | Badges |
|---|---|
| Google Drive | **Lecture**, **Écriture Docs**, **Écriture Sheets**, **Renommer / déplacer**, **Dupliquer** |
| Gmail | **Lecture**, **Brouillons**, **Signature** |
| Google Agenda, Calendrier Outlook | **Lecture**, **Création d'évènements** |
| Google Tasks | **Lecture**, **Ajout / modification / suppression** |
| Outlook | **Lecture**, **Brouillons** |
| Microsoft To Do | **Lecture**, **Création et modification de tâches** |
| Microsoft Teams | **Conversations**, **Canaux**, **Publication de messages** |
| Slack | **Recherche**, **Lecture**, **Publication** |

Un badge avec un tiret à la place du ✓ signale une autorisation manquante : **Reconnecter et
autoriser** la réaccorde.

**Outlook, le calendrier Outlook, Microsoft To Do et OneDrive** peuvent aussi l'exiger, selon les
règles de votre organisation. Si Microsoft affiche « Approbation de l'administrateur requise »,
transmettez la demande à votre administrateur ; une fois son accord donné pour toute l'organisation,
relancez **Connecter mon…** : la connexion se fait sans écran d'autorisation.

**Microsoft Teams demande l'accord d'un administrateur.** La lecture des canaux exige qu'un
administrateur de votre organisation autorise MIA dans Microsoft. Tant que ce n'est pas fait,
Microsoft affiche « Approbation de l'administrateur requise » : transmettez la demande ; une fois
l'accord donné, la connexion se fait en un clic. Teams ne se relie qu'avec un compte professionnel.

## Régler Google Tasks

La carte **Google Tasks** porte un bloc **Vos préférences**, enregistré tout seul et conservé même
si vous vous déconnectez :

- **Liste par défaut** : celle où MIA ajoute les tâches quand vous ne précisez rien ;
- **Fenêtre d'échéance par défaut** : aucune limite, 7, 14, 30 ou 90 jours — toute fenêtre écarte les
  tâches sans échéance ;
- **Masquer les tâches déjà terminées** ;
- **Autoriser la suppression de tâches** : décochée, MIA ne peut plus supprimer, seulement cocher ;
- **Vos règles d'organisation** : les règles que vous avez demandé à MIA de retenir en conversation,
  à relire ou supprimer.

## Ce que Slack affiche à la place d'une adresse

Slack ne communique pas votre adresse email : la carte affiche **@votre-identifiant · Nom de
l'espace de travail**. Si votre espace de travail exige l'approbation des applications, un
administrateur Slack doit valider MIA avant que la connexion aboutisse.

## Se déconnecter

**Déconnecter**, sur la carte, après confirmation. MIA perd l'accès au service. Pour **Slack**,
l'autorisation est en plus retirée côté Slack ; pour Microsoft, elle se retire depuis
myaccount.microsoft.com.

- Pour Outlook, le calendrier Outlook, Microsoft To Do et Microsoft Teams, Microsoft ne permet pas de révoquer l'autorisation depuis
  MIA : la page vous indique où le faire dans votre compte Microsoft.
- Si une base de connaissances se synchronise **via votre connexion Google Drive**, elle cessera de
  se mettre à jour.

## Quand une connexion expire

Un service relié peut cesser de l'être sans que vous ayez rien fait : mot de passe changé, accès retiré
par votre organisation, autorisation arrivée à échéance. MIA le signale par une **pastille ambre** :

- sur votre **avatar**, en bas de la barre latérale (en haut à droite, sur mobile) ;
- dans le menu de gauche de vos réglages, sur l'entrée **Comptes connectés** : son infobulle nomme le
  ou les services, par exemple *« Connexion à reconnecter : Gmail »* ;
- dans le menu **+** d'une conversation : un point orange sur l'en-tête du volet **Connecteurs**,
  et, une fois déplié, sur la ligne du connecteur : *« Connexion expirée — cliquer pour reconnecter »*.

Chaque rappel mène à la page **Comptes connectés** — celui du menu **+**, droit à la carte du service.
Deux issues :

1. **Reconnecter mon Google Drive** (ou Gmail, Outlook…) : l'écran de consentement s'ouvre, comme à la
   première connexion. Laissez toutes les cases cochées.
2. **Retirer cette connexion**, si vous ne voulez plus relier ce service.

La pastille disparaît d'elle-même dans les deux cas. Elle ne concerne que les services encore proposés
par votre administrateur.

## Depuis l'application mobile

Le bouton **Connecter mon…** ouvre l'écran d'autorisation dans le navigateur du téléphone. À la fin,
la page affiche « Vous pouvez fermer cet onglet et revenir dans l'application » ; la page Comptes connectés
se recharge à votre retour.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Un badge porte un tiret | **Reconnecter et autoriser**, en laissant toutes les cases cochées. |
| « La connexion a expiré ou a été révoquée côté Google… », pastille ambre sur l'avatar | **Reconnecter mon…** sur la carte, ou **Retirer cette connexion**. Arrive après un changement de mot de passe ou un retrait d'accès. Voir [Quand une connexion expire](#quand-une-connexion-expire). |
| « Connexion … non configurée sur cette instance. » | L'administrateur n'a pas encore configuré ce fournisseur. Voir avec lui. |
| « Connexion … annulée. » | Vous avez fermé l'écran de consentement : recommencer. |
| « … n'est plus proposé par votre administrateur. » | Le service a été masqué. Votre compte reste relié ; vous pouvez le déconnecter. |
| « Votre rôle ne vous permet pas de relier vos comptes à MIA. » | Votre rôle n'ouvre pas les connecteurs. Les comptes déjà reliés restent affichés : vous pouvez les déconnecter. Demandez ce droit à un administrateur. |
| Microsoft refuse la connexion et parle de consentement | Un administrateur de votre annuaire Microsoft doit valider l'application. Voir avec votre service informatique. |
| « Lien de connexion expiré ou invalide. » sur mobile | Relancer la connexion depuis l'application. |
