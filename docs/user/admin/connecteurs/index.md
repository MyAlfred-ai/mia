---
title: Les connecteurs
description: Choisir les services Google, Microsoft, Slack et Internet proposés à l'organisation, et accorder à MIA les droits exacts qu'ils demandent dans Microsoft Entra ID, Google Workspace et Slack.
sidebar:
  order: 5
---

L'administrateur décide **quels connecteurs sont proposés** à l'organisation, et prépare les
**applications Google et Microsoft** par lesquelles chaque utilisateur relie ensuite son propre
compte. Menu **Administration → Capacités → Connecteurs**, et **Organisation → Authentification**.

## À quoi ça sert

À donner à MIA l'accès aux documents, mails, agendas et tâches de chacun — avec ses droits à lui, et
sans jamais rien envoyer ni supprimer. Ou, au contraire, à fermer ce qui ne correspond pas à votre
politique : une organisation sous Microsoft 365 masque les services Google, une autre coupe la
recherche Internet.

## Comment ça marche

**Proposé = visible et configuré.** Un connecteur apparaît chez les utilisateurs s'il est **Visible**
dans **Administration → Capacités → Connecteurs** — c'est le réglage par défaut — **et** si son prérequis existe :

| Connecteurs | Prérequis |
|---|---|
| Google Drive, Gmail, Google Agenda, Google Tasks | Les identifiants Google de **Organisation → Authentification** |
| Outlook, Calendrier Outlook, Microsoft To Do, Microsoft Teams | L'application Microsoft de **Organisation → Authentification** — et, pour Teams, le **consentement d'un administrateur de l'annuaire** |
| Slack | Une application Slack dédiée, dans **Connecteurs → Configurer l'application Slack** |
| OneDrive — pour les bases de connaissances | Une application Microsoft dédiée, dans **Sources synchronisées** |
| Recherche Internet | Aucun : elle dépend du modèle choisi |

Il n'est **pas** nécessaire d'activer la connexion par Google ou par Microsoft pour que les
connecteurs fonctionnent : seuls les identifiants comptent.

**Les droits se donnent chez Google et chez Microsoft.** Chaque connecteur demande ses propres
autorisations, que votre service informatique déclare et accorde une fois : dans Microsoft Entra ID,
les autorisations Microsoft Graph et leur consentement ; chez Google, les API, les scopes, et parfois un
réglage de la console d'administration. Ces droits sont souvent plus larges que ce que MIA en fait,
faute de plus étroits chez Google et Microsoft. Le détail, droit par droit et cas par cas :
[Autoriser MIA dans Microsoft Entra ID](autoriser-mia-dans-microsoft-entra-id.md) et
[Autoriser MIA dans Google Workspace](autoriser-mia-dans-google-workspace.md).

**Chacun relie son compte.** L'administrateur ne relie rien pour les autres : chaque utilisateur
autorise MIA sur son propre compte, depuis **Réglages → Comptes connectés**. Vous ne voyez que le nombre de
comptes reliés, pas lesquels.

**Pas de réglage par groupe.** Un connecteur visible l'est pour toute l'instance. Les groupes jouent
indirectement, par les modèles qu'ils autorisent : presque tous savent se servir des connecteurs,
mais pas Perplexity, ni le LLM local tant que vous ne l'avez pas déclaré capable.

**Garde-fous.** MIA n'envoie jamais d'email et ne supprime jamais de fichier ; seul Google Tasks
permet de supprimer — une tâche —, et chaque utilisateur peut l'interdire. Microsoft To Do, lui, ne
supprime jamais : une tâche faite est marquée terminée. Slack et Microsoft Teams publient un message
qui part aussitôt — mais jamais sans l'accord de la personne. Toute écriture passe par
une approbation de l'utilisateur, et la carte nomme les mails, documents ou messages que MIA avait
lus avant de la préparer.

**Allumés à la main, ou par MIA.** Chaque utilisateur allume ses connecteurs dans le menu **+**, ou
laisse MIA les activer elle-même au besoin (**Laisser MIA choisir**, proposé par défaut, désactivable
dans **Administration → Capacités → Connecteurs**). Dans les deux cas, MIA n'utilise que les services que la
personne a reliés.

**Ce qui ne demande aucun droit de plus.** Lire les **pièces jointes** des mails (Gmail, Outlook) et
trouver des **créneaux libres** (Google Agenda, Calendrier Outlook) passent par les autorisations de
lecture déjà accordées. Pour un créneau commun, MIA n'obtient des collègues nommés que leurs plages
**occupées**, jamais le titre ni le contenu de leurs évènements, et seulement si leur agenda est
visible du compte (même organisation, ou agenda partagé). La **lecture d'une page web** suit
l'interrupteur **Recherche Internet** : la masquer coupe aussi la lecture de pages. MIA n'y lit que des
pages publiques : adresses de réseau interne, intranet et services du serveur sont refusés, y compris
après une redirection.

**Une exception à connaître : Slack.** C'est le seul connecteur dont l'écriture est immédiatement
visible — un message approuvé part dans le canal et tout le monde le voit, là où Gmail et Outlook
s'arrêtent au brouillon. MIA ne peut ensuite ni le modifier ni le supprimer, et ne voit jamais un
canal privé dont la personne n'est pas membre. Si ce périmètre ne convient pas, laissez le
connecteur **Masqué**.

**Le coût.** Les appels à Google et Microsoft ne coûtent rien ; les recherches Internet sont facturées
à l'unité, sur les lignes « Recherche web » de **Tarifs & crédits**.

## Configurer

| Réglage | Où | Effet | Page |
|---|---|---|---|
| **Visible** / **Masqué**, expressions déclencheuses, **Laisser MIA choisir** | Administration → Capacités → Connecteurs | Les connecteurs proposés, les suggestions dans le chat, et l'activation des connecteurs par MIA elle-même | [Activer les connecteurs](activer-les-connecteurs.md) |
| Inscriptions d'applications, autorisations Microsoft Graph, consentement | Microsoft Entra ID, puis Organisation → Authentification | Outlook, Calendrier Outlook, Microsoft To Do, OneDrive, SharePoint, et la connexion par Microsoft | [Autoriser MIA dans Microsoft Entra ID](autoriser-mia-dans-microsoft-entra-id.md) |
| Projet, API, scopes, audience, commandes des API | Console Google Cloud, console d'administration Google, puis Organisation → Authentification | Google Drive, Gmail, Google Agenda, Google Tasks, les clés API des bases, et la connexion par Google | [Autoriser MIA dans Google Workspace](autoriser-mia-dans-google-workspace.md) |
| Application Slack, autorisations utilisateur, URL de retour | api.slack.com, puis Connecteurs → Configurer l'application Slack | Le connecteur Slack | [Configurer l'application Slack](configurer-l-application-slack.md) |
| Application OneDrive, application SharePoint, clés API | Capacités → Sources synchronisées | La synchronisation des bases | [Configurer les sources synchronisées](../bases-de-connaissances/configurer-les-sources-synchronisees.md) |

## Exemples de configuration

**Une organisation sous Microsoft 365, connexion par Google.** L'application Microsoft est renseignée
dans **Authentification** sans activer la connexion par Microsoft ; les autorisations de messagerie
et d'agenda sont déclarées et consenties, et l'annuaire laisse chacun consentir à ces autorisations
pour MIA. Dans **Connecteurs**, Gmail, Google Drive, Google Agenda et Google Tasks sont **Masqués**.

**Pas de tâches, pas d'Internet.** Pour des raisons de conformité, **Tâches Google** et **Recherche
Internet** sont masqués. Les lignes disparaissent du menu **+** de tous les utilisateurs ; les comptes
déjà reliés restent reliés, et reviennent tels quels si vous rendez le connecteur visible.

**Des suggestions dans votre vocabulaire.** Vos équipes disent « mon classeur » pour un Google Sheets :
vous ajoutez l'expression aux déclencheurs de Google Drive ; MIA propose le connecteur quand elle
apparaît dans un message.

## Vérifier que tout fonctionne

1. **Maintenance → Diagnostic** : les contrôles « Connecteurs Google personnels », « Connecteurs
   Microsoft personnels » et « Connecteur Slack » sont au vert.
2. Avec un compte de test, sans rôle d'administrateur dans votre annuaire Microsoft : **Réglages →
   Comptes connectés**, relier le service, puis l'allumer dans une conversation et poser une question simple.
3. Sur la carte du connecteur, le compteur **N comptes reliés** augmente.

## Voir aussi

- [Les connecteurs, côté utilisateur](../../fonctionnalites/connecteurs/index.md)
- [Configurer la connexion](../utilisateurs/configurer-la-connexion.md) — les mêmes identifiants
- [Les modèles de LLM](../modeles/index.md) — les modèles compatibles
