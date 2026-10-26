---
title: L'instance
description: L'entreprise, son vocabulaire et ses modèles PowerPoint et Word, l'apparence de MIA, sa langue par défaut, la lecture des logs et du diagnostic, le suivi de l'agent, l'élagage des anciennes données et la sauvegarde de la configuration.
sidebar:
  order: 8
---

L'**instance**, c'est votre MIA : son nom, ses couleurs, sa langue par défaut, son état de santé et
ses données. L'administrateur la règle depuis **Administration → Organisation** et **Administration →
Maintenance**.

## À quoi ça sert

À ce que MIA soit **votre** outil — à votre nom, à vos couleurs —, à comprendre un problème avant
d'appeler à l'aide, et à décider combien de temps les conversations et les créations sont gardées.

## Comment ça marche

**Ce qui se règle ici, et ce qui relève de MyAlfred.**

| Écran | Vous y faites | MyAlfred y fait |
|---|---|---|
| **Entreprise** | Nom, descriptif et vocabulaire de l'organisation qui utilise MIA ; logo, mention de pied de page, modèles PowerPoint et Word de ses documents | — |
| **Marque & apparence** | Nom de l'application, logos, couleurs, message d'accueil, langue par défaut, liens | Le lien de l'application iPhone, selon votre contrat |
| **Diagnostic** | Lire l'état de l'instance, copier le rapport | Corriger ce qui se règle sur le serveur |
| **Journaux** | Surveiller les pannes (problèmes regroupés, frise, suivi en direct) et chercher la cause d'une erreur | L'analyse approfondie |
| **Agent** | Voir si MIA se sert bien de ses outils : erreurs, lenteurs, cartes refusées | — |
| **Élagage** | Décider des durées de conservation, lancer les élagages | — |
| **Sauvegarde** | Exporter la configuration | Les sauvegardes complètes et les restaurations |

**L'élagage n'est jamais automatique.** Rien n'est supprimé tant qu'un administrateur n'a pas cliqué
et confirmé. Les conversations épinglées sont épargnées ; leurs créations, non.

**La sauvegarde de l'interface ne contient pas les conversations.** Elle exporte la configuration :
utilisateurs, groupes, assistants, bases, outils, clés, réglages. Les sauvegardes complètes sont
assurées par MyAlfred.

## Configurer

| Réglage | Où | Effet | Page |
|---|---|---|---|
| Entreprise : nom, descriptif, vocabulaire, logo, mention de pied de page, modèles PowerPoint et Word | Organisation → Entreprise | Ce que MIA sait de l'organisation ; l'habillage des documents produits | [Présenter l'entreprise](presenter-l-entreprise.md) |
| Nom, logos, thème, couleur, message d'accueil, langue par défaut, liens | Organisation → Marque & apparence | L'identité de MIA | [Personnaliser l'apparence](personnaliser-l-apparence.md) |
| Diagnostic, journaux | Maintenance → Diagnostic, onglets **Contrôles** et **Journaux** | Comprendre un problème | [Consulter les logs et le diagnostic](consulter-les-logs-et-le-diagnostic.md) |
| Suivi de l'agent | Maintenance → Diagnostic → Agent | Repérer un outil qui échoue, un modèle qui tourne en rond, des cartes refusées | [Suivre le travail de l'agent](suivre-le-travail-de-l-agent.md) |
| Durées de conservation, élagages | Maintenance → Données → Élagage | La place occupée, la durée de vie des données | [Élaguer les anciennes données](elaguer-les-anciennes-donnees.md) |
| Export et restauration de la configuration | Maintenance → Données → Sauvegarde | Une trace, un retour arrière | [Sauvegarder la configuration](sauvegarder-la-configuration.md) |

## Exemples de configuration

**L'identité d'un cabinet.** Nom *Nova*, punchline *L'IA de l'atelier*, thème clair, couleur
d'accentuation bleue, logo carré. Le message d'accueil est laissé tel que livré, pour rester traduit
en anglais pour ceux qui l'ont choisi.

**L'entreprise et son modèle.** Nom *Acme Industries* ; descriptif : *« Fabricant de pièces de
robinetterie, 400 salariés, sites à Nantes et Lyon. Clients : grande distribution et professionnels du
bâtiment. »* ; le modèle PowerPoint et le modèle Word officiels déposés, la mention *« Acme SA ·
Document interne »*. Une présentation demandée dans la conversation sort aux couleurs et aux masques
d'Acme ; un compte rendu exporté en Word, dans ses styles, avec sa mention en pied de page.

**Une politique de conservation.** Conversations : 180 jours d'inactivité ; créations : 90 jours. Vous
prévenez les utilisateurs d'épingler ce qui compte et de télécharger leurs créations, puis vous lancez
le premier élagage.

**Un refus de connexion Microsoft.** **Journaux**, plage 24 h, recherche **AADSTS** : le code indique un
consentement manquant ; vous le faites accorder dans Entra.

## Vérifier que tout fonctionne

**Maintenance → Diagnostic** : le verdict est « Tout est opérationnel ». Sinon, la section **À
traiter** dit quoi faire, et **Copier le rapport** prépare votre message à MyAlfred.

## Voir aussi

- [La personnalisation, côté utilisateur](../../fonctionnalites/personnalisation/index.md) — la langue
  de chacun
- [Les conversations, côté utilisateur](../../fonctionnalites/conversations/index.md) — l'épinglage
- [Les utilisateurs et les accès](../utilisateurs/index.md) — la page de connexion
