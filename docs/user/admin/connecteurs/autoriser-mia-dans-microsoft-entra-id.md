---
title: Autoriser MIA dans Microsoft Entra ID
description: Les inscriptions d'applications, les autorisations Microsoft Graph et les consentements exacts à accorder dans Entra ID pour la connexion, Outlook, le calendrier Outlook, Microsoft To Do, Microsoft Teams, OneDrive et SharePoint.
sidebar:
  order: 2
---

Tout ce que MIA demande dans Microsoft Entra ID — l'annuaire de votre Microsoft 365 —, fonction par
fonction : ce qu'il faut inscrire, les autorisations exactes, qui doit les accorder. À faire avec la
personne qui administre votre annuaire. Seules les fonctions que vous proposez ont besoin de leurs
droits : une organisation qui ne se sert que d'Outlook n'a rien à faire pour SharePoint.

## En un coup d'œil

| Fonction de MIA | Inscription d'application | Autorisations Microsoft Graph | Type |
|---|---|---|---|
| **Continuer avec Microsoft** | *MIA* | `openid`, `profile`, `email` | Déléguées |
| **Outlook** | *MIA* | `offline_access`, `User.Read`, `Mail.Read`, `Mail.ReadWrite`, `Mail.Send` | Déléguées |
| **Calendrier Outlook** | *MIA* | `offline_access`, `User.Read`, `Calendars.Read`, `Calendars.ReadWrite` | Déléguées |
| **Microsoft To Do** | *MIA* | `offline_access`, `User.Read`, `Tasks.Read`, `Tasks.ReadWrite` | Déléguées |
| **Microsoft Teams** | *MIA* | `offline_access`, `User.Read`, `Chat.Read`, `ChatMessage.Send`, `Team.ReadBasic.All`, `Channel.ReadBasic.All`, `ChannelMessage.Read.All`, `ChannelMessage.Send` | Déléguées |
| **OneDrive**, source d'une base de connaissances | *MIA*, ou une inscription dédiée facultative | `offline_access`, `User.Read`, `Files.Read.All` | Déléguées |
| **SharePoint**, source d'une base de connaissances | *MIA — SharePoint* | `Files.Read.All` | **Application** |

Chaque ligne se termine par le **consentement administrateur** — voir [Le consentement](#le-consentement),
qui dit aussi quand il ne suffit pas. MIA ne demande rien d'autre : ni les contacts, ni les
boîtes et agendas partagés, ni la modification des messages Teams. `Mail.Send` ne sert qu'au bouton **Envoyer** de la carte où la personne
relit son email, après sa confirmation ; refusez-le au consentement et MIA n'enverra jamais rien — les
brouillons restent possibles, seul ce bouton disparaît.

## Avant de commencer

| Pour… | Rôle Entra, au minimum |
|---|---|
| Créer les inscriptions | **Développeur d'applications** (*Application Developer*) — aucun rôle si votre annuaire laisse les utilisateurs inscrire des applications |
| Accorder le consentement aux autorisations **déléguées** | **Administrateur d'application cloud** (*Cloud Application Administrator*) ou **Administrateur d'application** |
| Accorder l'autorisation **d'application** de SharePoint | **Administrateur de rôle privilégié** (*Privileged Role Administrator*) ou **Administrateur général** |
| Changer les paramètres de consentement de l'annuaire | **Administrateur général** |

Les adresses de retour s'affichent dans MIA : en haut de **Organisation → Authentification** pour la
connexion et les connecteurs, OneDrive compris ; dans la **Marche à suivre** de **Capacités →
Sources synchronisées** pour une inscription OneDrive dédiée.

## Inscrire MIA : connexion, Outlook et calendrier

1. Dans le centre d'administration Microsoft Entra, **Entra ID → Inscriptions d'applications →
   Nouvelle inscription** (*App registrations → New registration*).
2. Nom : *MIA*. **Types de comptes pris en charge** : **locataire unique** — voir
   [Les comptes pris en charge](#les-comptes-pris-en-charge).
3. **URI de redirection** : plateforme **Web** — pas « Application monopage (SPA) » —, avec l'URI
   affichée en haut de **Organisation → Authentification**.
4. **Certificats & secrets → Nouveau secret client** : copiez sa **valeur**, pas son ID, et notez sa
   date d'expiration.
5. **Autorisations d'API → Ajouter une autorisation → Microsoft Graph → Autorisations déléguées**
   (*API permissions → Delegated permissions*) : cochez celles du tableau ci-dessous, pour les
   fonctions que vous proposez.
6. **Accorder le consentement administrateur pour** *votre organisation* (*Grant admin consent*) :
   chaque ligne passe à « Accordé ».
7. Dans MIA, **Organisation → Authentification**, section **Microsoft** : l'**ID d'application
   (client)**, le **secret client**, et dans **Annuaire (locataire)** l'**ID de l'annuaire** — les
   deux ID sont sur la page **Vue d'ensemble** de l'inscription. **Enregistrer**, puis **Tester les
   identifiants**.

Il n'est pas nécessaire d'activer l'interrupteur de connexion par Microsoft : les connecteurs n'ont
besoin que des identifiants.

| Autorisation déléguée | Pour | Ce que Microsoft permet | Ce que MIA en fait |
|---|---|---|---|
| `openid`, `profile`, `email` | Continuer avec Microsoft | Identifier la personne | La connecter — aucune donnée lue |
| `offline_access` | Outlook, calendrier, To Do, Teams | Garder l'accès entre deux connexions | Renouveler l'accès sans redemander |
| `User.Read` | Outlook, calendrier, To Do, Teams | Lire le profil de la personne | Afficher l'adresse du compte relié |
| `Mail.Read` | Outlook | Lire toute la boîte | Rechercher et lire les mails |
| `Mail.ReadWrite` | Outlook | Lire, créer, modifier et supprimer des mails | Créer des brouillons, après approbation — ni modification, ni suppression, ni envoi |
| `Calendars.Read` | Calendrier Outlook | Lire les agendas de la personne | Consulter ses évènements |
| `Calendars.ReadWrite` | Calendrier Outlook | Créer, modifier et supprimer des évènements | Créer des évènements sans participant, après approbation |
| `Tasks.Read` | Microsoft To Do | Lire les listes et les tâches de la personne | Consulter ses tâches |
| `Tasks.ReadWrite` | Microsoft To Do | Créer, modifier et supprimer des tâches et des listes | Créer et modifier des tâches, après approbation — jamais de suppression |
| `Chat.Read` | Microsoft Teams | Lire les conversations de la personne (privées, de groupe, de réunion) | Rechercher et lire ses conversations |
| `ChatMessage.Send` | Microsoft Teams | Envoyer un message dans une conversation | Publier en son nom, après approbation |
| `Team.ReadBasic.All` | Microsoft Teams | Lire le nom des équipes dont la personne est membre | Lister ses équipes |
| `Channel.ReadBasic.All` | Microsoft Teams | Lire le nom des canaux | Retrouver un canal par son nom |
| `ChannelMessage.Read.All` | Microsoft Teams | Lire les messages des canaux de la personne — **consentement administrateur obligatoire** | Rechercher et lire les canaux et leurs fils |
| `ChannelMessage.Send` | Microsoft Teams | Envoyer un message dans un canal | Publier ou répondre dans un fil, après approbation |

`Mail.ReadWrite`, `Calendars.ReadWrite` et `Tasks.ReadWrite` sont les droits les plus étroits qui permettent de créer un
brouillon, un évènement ou une tâche : Microsoft n'en propose pas qui s'arrête à la création. Ce sont les
garde-fous de MIA qui tiennent le reste.

Pour Teams, MIA ne demande **jamais** `Chat.ReadWrite`, qui ouvrirait la modification et la
suppression de messages : les deux droits d'envoi ne font qu'envoyer, et chaque publication attend
l'accord de la personne.

Un consentement accordé après coup est pris en compte au renouvellement suivant de l'accès, sans que
personne ait à relier son compte de nouveau — comptez jusqu'à une heure.

## Les comptes pris en charge

Le type choisi à l'inscription et le champ **Annuaire (locataire)** de MIA vont ensemble :

| Types de comptes pris en charge | Annuaire (locataire) dans MIA | Qui peut entrer et relier |
|---|---|---|
| **Locataire unique** — le choix par défaut d'Entra | **L'ID de votre annuaire**, obligatoire | Les comptes de votre organisation |
| **Multilocataire** | `organizations`, ou l'ID de votre annuaire | Tout compte professionnel Microsoft ; l'ID le restreint au vôtre |
| **Multilocataire et comptes Microsoft personnels** | `common` — la valeur quand le champ est vide —, ou `consumers` | Tout compte, y compris Outlook.com ; `consumers` : les comptes personnels seulement |

Une inscription à locataire unique avec un champ laissé vide échoue avec **AADSTS50194**. Recommandé :
**locataire unique et l'ID de l'annuaire** — c'est aussi ce qui permet à MIA de rattacher une
connexion Microsoft à un compte existant, voir
[Configurer la connexion](../utilisateurs/configurer-la-connexion.md).

## Le consentement

**Accordez le consentement administrateur**, pour chaque inscription. Le réglage par défaut de
Microsoft, **Laisser Microsoft gérer vos paramètres de consentement**, réserve aux administrateurs
l'accès aux mails, aux agendas et aux fichiers : sans ce consentement, personne d'autre ne peut relier
Outlook, le calendrier Outlook, Microsoft To Do ni OneDrive. Microsoft Teams l'exige dans tous les cas
(`ChannelMessage.Read.All`) : tant qu'il n'est pas accordé, **personne** ne peut relier Teams,
administrateurs compris.

**Une fois accordé, il suffit**, quel que soit le réglage de votre annuaire : MIA ne fait jamais
réafficher l'écran d'autorisation de Microsoft. Chacun relie son compte en un clic, sans rôle
d'administrateur, et Microsoft ne lui demande rien que l'organisation n'ait déjà accepté.

L'écran d'autorisation n'apparaît que si MIA demande une autorisation **qui n'a pas encore été
accordée** — une autorisation ajoutée par une version plus récente de MIA, par exemple. Ce qui se
passe alors dépend du réglage de votre annuaire, **Applications d'entreprise → Consentement et
autorisations → Paramètres de consentement de l'utilisateur** :

| Paramètre de consentement de l'utilisateur | Autorisation pas encore accordée, pour une personne sans rôle d'administrateur |
|---|---|
| **Autoriser le consentement de l'utilisateur pour les applications** | Elle accepte l'écran de Microsoft |
| **Autoriser le consentement de l'utilisateur pour les applications d'éditeurs vérifiés, pour les autorisations sélectionnées** | Elle accepte l'écran, si l'autorisation est classée **faible impact** |
| **Laisser Microsoft gérer vos paramètres de consentement** — le défaut actuel | « Approbation de l'administrateur requise » (**AADSTS90094**) : accordez le consentement administrateur, puis elle relie de nouveau |
| **Ne pas autoriser le consentement de l'utilisateur** | Idem |

**Continuer avec Microsoft** n'est pas concerné : une fois le consentement administrateur accordé, la
connexion à MIA fonctionne avec chacun de ces réglages. Pour vérifier, **testez avec un compte
ordinaire** : un compte qui porte un rôle d'administrateur des applications passe partout.

## OneDrive, pour les bases de connaissances

Seulement si des bases se synchronisent depuis OneDrive.

**Le plus simple : aucune inscription de plus.** Sans inscription dédiée, OneDrive passe par
l'inscription *MIA* de la connexion, comme Outlook : ajoutez-lui l'autorisation déléguée
`Files.Read.All`, puis **accordez le consentement administrateur** — le réglage par défaut de
Microsoft réserve cette autorisation aux administrateurs. **Organisation → Authentification** la liste
avec les autres, et l'adresse de retour est déjà la bonne.

**Une inscription dédiée reste possible**, pour cantonner `Files.Read.All` à une application à part ou
viser un autre annuaire. Elle l'emporte dès que son **ID d'application** est saisi dans MIA. Passer de
l'une à l'autre oblige chacun à relier son OneDrive de nouveau. La **Marche à suivre** de **Capacités
→ Sources synchronisées** reprend ces étapes à côté des champs.

1. **Nouvelle inscription** : *MIA — OneDrive*, **locataire unique** en règle générale.
2. **URI de redirection** : plateforme **Web**, avec l'adresse de la **Marche à suivre** — elle
   diffère de celle de la connexion.
3. **Nouveau secret client** : sa **valeur**, et sa date d'expiration.
4. **Autorisations déléguées** : `Files.Read.All`, `User.Read`, `offline_access`. Aucune autorisation
   d'écriture.
5. **Accorder le consentement administrateur** : le réglage par défaut de Microsoft réserve
   `Files.Read.All` aux administrateurs.
6. Dans MIA, section **OneDrive** : l'**ID de l'annuaire** — obligatoire avec une inscription à
   locataire unique, le champ valant `common` sinon —, l'**ID d'application**, le **secret**, puis
   **Vérifier la configuration**. La carte OneDrive dit en tête quelle inscription sert ; une
   inscription dédiée sans secret rend OneDrive indisponible, MIA ne se rabat pas sur *MIA*.

`Files.Read.All` laisse lire, au nom de la personne, tout ce qu'elle peut ouvrir : son OneDrive, ce
qu'on lui partage, les bibliothèques SharePoint où elle a accès. MIA ne lit que le dossier choisi pour
la base, et n'écrit jamais. Ce que dit [Le consentement](#le-consentement) vaut aussi ici.

## SharePoint, pour les bases de connaissances

Seulement si des bases se synchronisent depuis un dossier SharePoint. Ici, MIA agit **sans
utilisateur**, avec une autorisation d'application.

1. **Nouvelle inscription** : *MIA — SharePoint*, **locataire unique**. Aucune URI de redirection.
2. **Nouveau secret client** : sa **valeur**, et sa date d'expiration.
3. **Autorisations d'API → Microsoft Graph → Autorisations d'application** (*Application
   permissions*) : `Files.Read.All`.
4. **Accorder le consentement administrateur** — **Administrateur de rôle privilégié** ou
   **Administrateur général**.
5. Dans MIA, section **SharePoint** : **ID de l'annuaire (tenant)**, **ID d'application (client)**,
   **Secret client**, puis **Tester la connexion** : « Authentification Azure acceptée et permission
   Files.Read.All présente. »

**Mesurez ce droit.** En autorisation d'application, `Files.Read.All` ouvre la lecture de tous les
fichiers de tous les sites SharePoint et de tous les OneDrive de l'organisation. MIA ne lit que les
dossiers dont on lui donne l'adresse, sans jamais écrire. `Sites.Selected`, qui limiterait l'accès à
quelques sites, n'est pas pris en charge. Une autorisation plus large déjà accordée — `Sites.Read.All`,
par exemple — est acceptée ; n'en ajoutez pas pour MIA.

OneDrive et SharePoint peuvent partager une même inscription, qui porte alors les deux types
d'autorisations. Gardez celle de la connexion à part : ses consentements sont d'une autre nature.

## Cas particuliers

| Situation | Ce qu'il faut savoir |
|---|---|
| **Réserver MIA à certaines personnes** | **Applications d'entreprise → MIA → Propriétés → Affectation requise ? : Oui**, puis **Utilisateurs et groupes**. Microsoft interdit alors à chacun de consentir lui-même : accordez le consentement administrateur, et les personnes affectées relient Outlook, le calendrier, To Do et OneDrive sans écran d'autorisation — voir [Le consentement](#le-consentement). |
| **Accès conditionnel** | Vos stratégies s'appliquent à la connexion et à chaque liaison. MIA renouvelle ensuite l'accès **depuis son serveur** : une stratégie par emplacement doit connaître l'adresse de sortie du serveur ; une fréquence de connexion imposée oblige chacun à relier son compte de nouveau à l'échéance. |
| **Pas de boîte Exchange Online** | Outlook, le calendrier Outlook et Microsoft To Do exigent une boîte Exchange Online, donc une licence avec messagerie. Une boîte restée sur un Exchange local n'est pas lue. |
| **Microsoft Teams** | Réservé aux comptes de votre organisation disposant d'une licence Teams : les comptes Microsoft personnels n'ont pas d'accès Teams pour les applications. MIA ne voit que les équipes dont la personne est membre. |
| **Invités de votre annuaire** | Ils peuvent se connecter à MIA, pas relier Outlook ni leur calendrier : leur boîte dépend de leur propre organisation. |
| **Boîtes, agendas et listes de tâches partagés** | Non lus : MIA ne demande aucune autorisation `.Shared`. Seuls la boîte, les agendas et les listes de tâches de la personne comptent. |
| **Comptes Microsoft personnels** | Seulement avec une inscription multilocataire et comptes personnels, et l'annuaire `common` ou `consumers`. Aucun consentement administrateur n'existe pour eux : chacun accepte lui-même. |
| **Secrets qui expirent** | Une à trois inscriptions, donc autant de dates. À l'échéance, ce qui en dépend s'arrête : créez un secret, collez sa valeur dans MIA, puis supprimez l'ancien. |
| **Couper l'accès de MIA** | Pour tout l'annuaire : **Applications d'entreprise → MIA → Propriétés → Activé pour que les utilisateurs se connectent ? : Non**. **Déconnecter**, dans MIA, oublie le compte relié sans retirer l'accord chez Microsoft : chacun le retire depuis son compte Microsoft. |
| **Annuaire ouvert** — `common` ou `organizations` | Pour que MIA rattache une connexion Microsoft à un compte existant : **Configuration du jeton → Ajouter une revendication facultative → ID** (*Token configuration*), cochez `email` et `xms_edov`. |

## Vérifier

1. **Organisation → Authentification → Tester les identifiants** : annuaire, ID et secret acceptés.
2. **Maintenance → Diagnostic** : « Connecteurs Microsoft personnels » au vert.
3. **Sources synchronisées** : **Vérifier la configuration** pour OneDrive, **Tester la connexion**
   pour SharePoint.
4. Avec un compte **sans rôle d'administrateur** : **Réglages → Comptes connectés → Connecter mon
   Outlook**, **Connecter mon calendrier Outlook**, **Connecter Microsoft To Do**, puis **Connecter
   Microsoft Teams**. Tous les badges affichent ✓.

## En cas de problème

MIA nomme la cause d'un refus de Microsoft ; le code **AADSTS** est aussi dans **Maintenance → Diagnostic →
Journaux** — voir [Consulter les logs et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md).

| Code ou message | Cause | Quoi faire |
|---|---|---|
| **AADSTS90094**, **AADSTS65001**, « Permissions non consenties dans cet annuaire Microsoft… » | Une autorisation demandée n'a pas reçu le consentement administrateur | L'accorder (**Accorder le consentement administrateur**, toutes les lignes « Accordé »), puis relier de nouveau — voir [Le consentement](#le-consentement) |
| « Permission Outlook non accordée… », « Permission de calendrier non accordée… » | Autorisation d'écriture absente de l'accès | Déclarer `Mail.ReadWrite` ou `Calendars.ReadWrite`, consentir, puis faire relier le service |
| **AADSTS65004** | La personne a refusé l'écran de Microsoft | Relancer la liaison et accepter |
| **AADSTS50105** | Affectation requise, personne non affectée | L'ajouter dans **Utilisateurs et groupes** |
| **AADSTS50194** | Inscription à locataire unique, annuaire `common` dans MIA | Renseigner l'ID de l'annuaire |
| **AADSTS50020**, **AADSTS500200** | Compte hors de l'annuaire configuré | Se connecter avec un compte de l'organisation, ou élargir l'inscription |
| **AADSTS50011** | URI de redirection non déclarée | L'ajouter, plateforme **Web**, telle que MIA l'affiche |
| **AADSTS9002326** | URI déclarée en « Application monopage (SPA) » | La retirer de SPA et l'ajouter sous **Web** |
| **AADSTS7000215**, **AADSTS7000222** | Secret mal copié, ou expiré | Coller la **valeur** du secret, ou en créer un |
| **AADSTS700016**, **AADSTS90002** | ID d'application ou d'annuaire erroné | Les recopier depuis la **Vue d'ensemble** de l'inscription |
| **AADSTS650053** | Autorisation mal orthographiée | Vérifier les autorisations déclarées |
| « Ce compte Microsoft n'a pas de boîte aux lettres Exchange Online… » | Pas de licence de messagerie | Rien à régler dans MIA |
| « Authentification réussie, mais la permission d'application Files.Read.All … manque au token… » | Autorisation déléguée au lieu d'application, ou consentement absent | **Autorisations d'application**, consentement, puis réessayer quelques minutes plus tard |
