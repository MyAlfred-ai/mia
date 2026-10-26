---
title: Autoriser MIA dans Google Workspace
description: Le projet Google Cloud, les API, les autorisations exactes et les réglages de la console d'administration Google pour la connexion, Google Drive, Gmail, Google Agenda, Google Tasks et les bases synchronisées.
sidebar:
  order: 3
---

Tout ce que MIA demande chez Google, fonction par fonction. Deux consoles entrent en jeu : la
**console Google Cloud**, où l'on déclare MIA, et la **console d'administration Google**, où l'on décide
ce que les applications peuvent faire dans votre organisation. À faire avec la personne qui administre
votre Google Workspace. Seules les fonctions que vous proposez ont besoin de leurs droits.

## En un coup d'œil

| Fonction de MIA | API à activer | Autorisations (scopes) |
|---|---|---|
| **Continuer avec Google** | Aucune | `openid`, `email`, `profile` |
| **Google Drive** | Google Drive API, Google Docs API, Google Sheets API | `drive.readonly`, `drive.metadata`, `drive.file`, `documents`, `spreadsheets` |
| **Gmail** | Gmail API | `gmail.readonly`, `gmail.compose`, `gmail.settings.basic` |
| **Google Agenda** | Google Calendar API | `calendar.readonly`, `calendar.events` |
| **Google Tasks** | Google Tasks API | `tasks.readonly`, `tasks` |
| Base synchronisée par **Ma connexion Google Drive** | Celles de Google Drive | Celles de Google Drive |
| Base synchronisée par **clé API** : dossier partagé par lien, agenda public | Google Drive API, Google Calendar API | Aucune : une clé API — voir [Les clés API des bases de connaissances](#les-clés-api-des-bases-de-connaissances) |

Chaque connecteur demande aussi `openid` et `email`. Hors `openid`, `email` et `profile`, un scope
s'écrit en entier : `https://www.googleapis.com/auth/drive.readonly`. MIA ne demande ni `gmail.send`
(`gmail.compose` couvre déjà l'envoi, réservé au bouton **Envoyer** de la carte de relecture), ni le
Drive complet (`drive`), ni l'agenda complet (`calendar`), ni délégation au niveau du domaine.

## Avant de commencer

- Dans la console Google Cloud : le rôle **Propriétaire** — ou **Éditeur** — d'un projet **rattaché à
  l'organisation** de votre Google Workspace. C'est la condition du type **Interne**.
- Dans la console d'administration Google : un **super-administrateur**.
- L'URI de redirection de MIA, en haut de **Organisation → Authentification**. **Une seule** sert à la
  connexion et aux quatre connecteurs.

## Déclarer MIA dans Google Cloud

1. Choisissez ou créez le projet, dans votre organisation.
2. **API et services → Bibliothèque** : activez les API des fonctions que vous proposez — tableau
   ci-dessus.
3. **Google Auth Platform → Audience** : type d'utilisateur **Interne** — voir
   [Interne ou externe](#interne-ou-externe).
4. **Google Auth Platform → Accès aux données → Ajouter ou supprimer des scopes** : les autorisations
   des connecteurs proposés. Facultatif en interne, indispensable en externe.
5. **Google Auth Platform → Clients → Créer un client** : type **Application Web**, et dans **URI de
   redirection autorisés**, l'URI de MIA telle quelle.
6. Recopiez l'**ID client** et le **secret client** dans **Organisation → Authentification**, section
   **Google**, puis **Enregistrer** et **Tester les identifiants**.

Il n'est pas nécessaire d'activer l'interrupteur de connexion par Google : les connecteurs n'ont
besoin que des identifiants.

## Les autorisations, une à une

| Scope | Classement Google | Ce que Google permet | Ce que MIA en fait |
|---|---|---|---|
| `drive.readonly` | Restreint | Lire tous les fichiers du Drive, Drive partagés compris | Rechercher, lire, parcourir |
| `drive.metadata` | Restreint | Modifier les métadonnées de tous les fichiers, y compris les mettre à la corbeille | Renommer et déplacer, après approbation — aucune suppression |
| `drive.file` | Non sensible | Gérer les fichiers créés par l'application | Dupliquer un fichier |
| `documents` | Sensible | Lire et modifier tous les Google Docs | Créer et modifier des Docs, après approbation |
| `spreadsheets` | Sensible | Lire et modifier tous les Google Sheets | Créer et modifier des Sheets, après approbation |
| `gmail.readonly` | Restreint | Lire tous les mails et les réglages | Rechercher et lire les mails |
| `gmail.compose` | Restreint | Gérer les brouillons, **et envoyer des mails** | Créer des brouillons, et envoyer un email **relu** — uniquement sur le bouton **Envoyer** de la carte, après confirmation de la personne ; aucun assistant n'a d'outil d'envoi |
| `gmail.settings.basic` | Restreint | Lire et modifier les réglages et les filtres | Lire la signature, rien d'autre |
| `calendar.readonly` | Sensible | Lire les agendas | Consulter les évènements |
| `calendar.events` | Sensible | Créer, modifier et supprimer des évènements | Créer des évènements, sans envoyer d'invitation, après approbation |
| `tasks.readonly` | Sensible | Lire les tâches | Lister les tâches |
| `tasks` | Sensible | Créer, modifier et supprimer des tâches | Ajouter, modifier, cocher et supprimer, après approbation — chacun peut interdire la suppression |

Google n'offre pas de droit plus étroit pour créer un brouillon, renommer un fichier ou écrire une
tâche : ce sont les garde-fous de MIA qui tiennent le reste. Chaque personne voit ces autorisations sur
l'écran de Google, et peut décocher les cases une à une : une case décochée retire une capacité, et le
badge correspondant porte un tiret dans **Réglages → Comptes connectés**.

## Interne ou externe

| Audience | Qui peut relier ses services | Ce que Google exige |
|---|---|---|
| **Interne** — recommandé | Les comptes de votre organisation Google Workspace | Rien : aucun examen, même pour les scopes restreints |
| **Externe**, état **Test** | Les **utilisateurs test** déclarés, 100 au plus | Rien, mais l'accès expire au bout de **7 jours** : chacun doit relier ses services de nouveau chaque semaine |
| **Externe**, **En production** | Tout compte Google | La **validation** de l'application par Google pour les scopes sensibles, et une **évaluation de sécurité** annuelle pour les restreints — Gmail et Drive. Sans elles, un écran « Google n'a pas validé cette application » et 100 utilisateurs au plus |

L'externe ne se justifie que si des comptes hors de votre organisation doivent relier leurs services :
des adresses Gmail personnelles, une seconde organisation. **Continuer avec Google** seul n'est touché ni
par l'examen, ni par l'expiration.

## Dans la console d'administration Google

Rien à faire si votre organisation laisse ses utilisateurs se servir des applications tierces : c'est
le réglage par défaut. Il faut agir dans trois cas :

| Dans votre console | Conséquence | Quoi faire |
|---|---|---|
| **Applications → Google Workspace** : Gmail, Drive et Docs, Agenda ou Tasks désactivé pour certains utilisateurs | Le connecteur ne marche pas pour eux | Activer le service, si c'est voulu |
| **Sécurité → Contrôle des accès et des données → Commandes des API → Gérer les services Google** : Gmail, Drive et Docs, Agenda ou Tasks en **Restreint** | Google refuse la liaison, sauf aux applications approuvées | Approuver MIA, ci-dessous |
| **Commandes des API → Paramètres → Applications tierces non configurées** : accès interdit, ou limité aux informations de « Se connecter avec Google » | Google refuse les connecteurs | Configurer MIA, ci-dessous |

Pour approuver MIA : **Commandes des API → Gérer l'accès des applications tierces → Configurer une
nouvelle application**, cherchez l'**ID client** de MIA, choisissez les unités organisationnelles, puis
l'accès :

- **Approuvée** : MIA accède à tous les services, restreints compris ;
- **Données Google spécifiques** : MIA n'accède qu'aux scopes que vous cochez — ceux du tableau
  [Les autorisations, une à une](#les-autorisations-une-à-une), pour les connecteurs proposés ;
- **Limitée** : les seuls services non restreints — elle suffit quand aucun service ne l'est.

Autre voie : **Commandes des API → Paramètres → Applications internes → Approuver les applications
internes**, qui approuve d'un coup les applications des projets de votre organisation, MIA comprise si
son projet y est rattaché.

Passer un service en **Restreint** coupe aussitôt les connexions des applications qui ne sont pas
approuvées : approuvez MIA d'abord.

## Les clés API des bases de connaissances

Pour synchroniser une base avec un dossier Google Drive partagé par lien, ou avec un agenda public :
mode **Clé API globale** de **Capacités → Sources synchronisées**.

1. **API et services → Identifiants → Créer des identifiants → Clé API**.
2. **Restrictions d'API** : **Google Drive API** — ou **Google Calendar API** pour la clé des agendas ;
   une même clé peut porter les deux.
3. **Restrictions d'application** : aucune, ou les **adresses IP** du serveur de MIA. Jamais **Sites
   Web** : les appels partent du serveur, pas d'un navigateur.
4. Collez la clé dans **Sources synchronisées**, puis **Tester la connexion**.

Une clé ne lit que ce qui est public : un dossier partagé « Tous les utilisateurs disposant du lien »,
un agenda « Rendu disponible publiquement ». Si votre console interdit ces partages hors de
l'organisation — **Applications → Google Workspace → Drive et Docs → Paramètres de partage**, et le
partage externe des agendas —, la clé ne voit rien : passez par **Ma connexion Google Drive**, qui lit
avec les droits du créateur de la base, Drive partagés compris.

## Cas particuliers

| Situation | Ce qu'il faut savoir |
|---|---|
| **Changement de mot de passe** | Google coupe l'accès à Gmail : la personne relie Gmail de nouveau. |
| **Plusieurs organisations, ou des comptes Gmail personnels** | Audience **Externe** — voir [Interne ou externe](#interne-ou-externe). |
| **Connexion par Google dans l'application mobile** | Deux identifiants clients de plus dans le même projet, de types Android et iOS, pour l'application `ai.myalfred.myllm` : l'empreinte de signature et la déclaration côté serveur se règlent avec MyAlfred. |
| **Couper l'accès de MIA** | Pour toute l'organisation : **Commandes des API**, MIA en **Bloquée**. Pour une personne : **Déconnecter**, dans MIA, retire l'accès chez Google ; elle peut aussi le retirer depuis son compte Google. |

## Vérifier

1. **Organisation → Authentification → Tester les identifiants** : ID client et secret acceptés.
2. **Maintenance → Diagnostic** : « Connecteurs Google personnels » au vert.
3. Avec un compte de test : **Réglages → Comptes connectés**, relier chaque service proposé. Tous les
   badges affichent ✓.
4. **Sources synchronisées → Tester la connexion**, pour chaque clé API.

## En cas de problème

| Ce qui arrive | Cause | Quoi faire |
|---|---|---|
| Google affiche **redirect_uri_mismatch** | URI absente de l'ID client, ou différente d'un caractère | La recopier depuis **Organisation → Authentification** |
| Google affiche « Accès bloqué », erreur **admin_policy_enforced** | Service restreint, ou applications non configurées interdites | Approuver MIA dans **Commandes des API** |
| Google affiche l'erreur **org_internal** | Audience **Interne**, compte hors de l'organisation | Se connecter avec un compte de l'organisation, ou passer en externe |
| Google affiche l'erreur **access_denied**, « n'a pas terminé la procédure de validation » | Externe en test, compte absent des utilisateurs test | L'ajouter aux **utilisateurs test** |
| « L'API … n'est pas activée sur le projet Google Cloud de l'application… » | API non activée | L'activer, sur ce projet précisément, puis patienter quelques minutes |
| Chacun doit relier ses services chaque semaine | Externe en test : accès de 7 jours | Passer en **Interne** |
| « La connexion a expiré ou a été révoquée côté Google… » | Mot de passe changé, accès retiré, service passé en **Restreint** | Relier le service de nouveau, après avoir approuvé MIA si besoin |
| Un badge porte un tiret | Case décochée sur l'écran de Google | **Reconnecter et autoriser**, toutes les cases cochées |
| **Tester la connexion** échoue sur une clé API | API non activée, ou clé restreinte à une autre API | Vérifier les **Restrictions d'API** de la clé |
