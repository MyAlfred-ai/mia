---
title: Les bases de connaissances
description: Rendre les bases possibles, garder la main sur toutes celles de l'instance, relier les dossiers en ligne et surveiller l'indexation.
sidebar:
  order: 4
---

Les bases de connaissances sont créées par les créateurs ; l'administrateur les **rend possibles**
— une clé d'indexation, des sources en ligne configurées —, **garde la main** sur toutes celles de
l'instance et **surveille** leur indexation. Les bases s'ouvrent par la tuile **Bases** de la barre
latérale (**Espaces → Bases de connaissances**) ; les identifiants des sources en ligne se règlent
dans **Administration → Capacités → Sources synchronisées**.

## À quoi ça sert

Une base bien tenue, c'est un assistant qui répond juste, sur vos procédures, votre catalogue, vos
accords. Une base mal indexée, c'est un assistant qui répond de mémoire sans que personne ne s'en
aperçoive. Ces réglages évitent le second cas.

## Comment ça marche

**Ce qu'il faut pour créer une base.** Un moteur d'**embedding** — le calcul de l'empreinte de sens
des extraits —, fourni par une clé **OpenAI**, **Mistral** ou **Gemini** enregistrée dans **Modèles IA
→ Clés API**. Sans l'une de ces clés, le bouton **+ Nouvelle base** est grisé pour les créateurs ;
un administrateur peut toujours brancher un cerveau MyBrain, qui n'a rien à indexer.
Le moteur est choisi base par base, à la création ; il se change ensuite par **Réindexer**, dans la
section **Avancé** de la fiche de la base, qui recalcule toutes les empreintes avec le nouveau modèle.
La même section montre les **extraits vectorisés** de la base, un par un, avec leur empreinte.

**Le découpage et la description des images** passent par le **LLM par défaut** : un modèle vision
en défaut permet d'indexer les images.

**Qui crée, qui voit.**

| | Créer | Voir |
|---|---|---|
| **Créateur** | Jusqu'à 5 bases | Les siennes, et celles qu'on lui partage — directement ou par un groupe |
| **Utilisateur** | Non | Celles partagées avec l'un de ses **groupes** |
| **Administrateur** | Sans limite | **Toutes**, y compris inactives et orphelines |

Il n'y a pas de droit « bases » dans les groupes : l'accès se donne par le **partage** de chaque base,
à des personnes ou à des groupes. Une base liée à un assistant sert à **tous** ses utilisateurs, même
sans accès direct à la base — sauf un cerveau MyBrain, qui n'est interrogé que pour les membres des
groupes à qui il est partagé.

**Le cerveau MyBrain, une base en direct.** Le wiki d'équipe MyBrain se branche comme une base dont le
contenu reste dans MyBrain : MIA l'interroge à chaque message, sans rien copier, avec une clé d'API
émise dans MyBrain. Il peut être **coché par défaut** pour certains groupes.

**Les limites** sont fixes : 20 documents déposés par base, 100 fichiers synchronisés, 3 bases cochées
par conversation.

**Au plafond du budget IA** (mode « suspendre »), la synchronisation automatique se met en pause
jusqu'au 1er ; « Synchroniser maintenant » et le dépôt de documents restent possibles, et comptés.

**Le coût.** L'alimentation — découpage, description des images, indexation — est décomptée **hors
quota**, au créateur de la base. Chaque question posée avec des bases coûte une petite recherche,
dans le quota de celui qui la pose. Le détail par base est dans **Coûts → Consommation**.

**La base système.** L'assistant **MIA : mode d'emploi** s'appuie sur une base livrée avec MIA,
badge **Système** : on ne peut que la réindexer.

## Configurer

| Réglage | Où | Effet | Page |
|---|---|---|---|
| Clé OpenAI, Mistral ou Gemini | Modèles IA → Clés API | Rend la création de bases possible | [Enregistrer les clés API](../modeles/enregistrer-les-cles-api.md) |
| Toutes les bases : désactiver, reprendre, supprimer | Tuile **Bases** de la barre latérale | La maîtrise du contenu | [Administrer les bases](administrer-les-bases.md) |
| Clé Google, application Microsoft, rythme de synchronisation | Capacités → **Sources synchronisées** | Les dossiers en ligne | [Configurer les sources synchronisées](configurer-les-sources-synchronisees.md) |
| État du moteur d'indexation | Maintenance → Diagnostic → **Moteur vectoriel** | Le diagnostic | [Vérifier la base vectorielle](verifier-la-base-vectorielle.md) |
| Cerveau MyBrain : adresse, clé, groupes, coché par défaut, peut nourrir | Tuile **Bases** → **+ Nouvelle base** → carte **Cerveau MyBrain** | Le wiki d'équipe interrogé en direct | [Brancher un cerveau MyBrain](brancher-un-cerveau-mybrain.md) |
| Rôle **Créateur** | Personnes → Comptes | Qui peut créer des bases | [Gérer les utilisateurs](../utilisateurs/gerer-les-utilisateurs.md) |

## Exemples de configuration

**Démarrer.** Une clé Mistral dans **Clés API** : le bouton **+ Nouvelle base** s'active pour les
créateurs, avec Mistral comme moteur proposé.

**Une base pour toute l'entreprise.** Le créateur de *Procédures RH* la partage au groupe
*★ Par défaut* : chacun peut la cocher dans ses conversations.

**Un catalogue synchronisé.** Une clé Google dans **Sources synchronisées**, un dossier Drive partagé
par lien : la base *Catalogue produits* se met à jour toutes les 10 minutes.

**Un départ.** Avant de supprimer le compte d'un créateur, vous vérifiez ses bases : elles resteront,
sans créateur, gérables par les seuls administrateurs.

**Le wiki d'équipe pour les consultants.** Une clé « lire » créée dans MyBrain, la carte **Cerveau
MyBrain**, le groupe *Consultants* coché et **coché par défaut** : chaque nouvelle conversation d'un
consultant interroge le cerveau, et les réponses citent ses fiches.

## Vérifier que tout fonctionne

- **Maintenance → Diagnostic → Moteur vectoriel** : le moteur est **En ligne**, chaque base **Opérationnelle**.
- Dans le chat, avec un compte du groupe concerné : la base se coche, et la ligne **Recherche dans les
  bases de connaissances** apparaît au-dessus de la réponse.

## Voir aussi

- [Les bases de connaissances, côté utilisateur](../../fonctionnalites/bases-de-connaissances/index.md)
- [Les assistants](../assistants/index.md) — qui consultent les bases
- [Les connecteurs](../connecteurs/index.md) — les connexions Drive et OneDrive des créateurs
- [La gestion des crédits](../credits/index.md) — l'alimentation, hors quota
