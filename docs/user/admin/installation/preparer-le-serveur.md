---
title: Préparer le serveur
description: Réunir avant l'installation ce que l'installeur ne fait pas — Docker, le nom de domaine, le proxy et, s'il y a lieu, la base de données.
sidebar:
  order: 1
---

L'installeur contrôle le serveur, mais il n'installe ni Docker, ni le proxy qui porte l'adresse de MIA,
ni le nom de domaine. Réunissez-les avant de le lancer : il s'arrête au premier point bloquant, sans
rien avoir modifié.

## Avant de commencer

- Un serveur Linux, et un compte qui a le droit de se servir de Docker.
- Le choix de l'adresse de MIA, par exemple `https://mia.exemple.fr`.

## Étapes

1. **Obtenez l'accès au dépôt de MIA** auprès de MyAlfred : l'installeur est dedans.
2. **Installez ce que l'installeur demande.**

   | Élément | Ce qu'il faut |
   |---|---|
   | Docker Engine | Version 24 ou plus, le service démarré |
   | Docker Compose | La version 2, la commande `docker compose` sans tiret. `docker-compose` n'est pas pris en charge |
   | `git` | MIA se récupère, puis se met à jour, par lui |
   | `openssl` et `curl` | Pour tirer les secrets et pour les vérifications de fin. Leur absence ne bloque pas : l'installeur a une solution de repli |
   | Espace disque | 5 Go libres : l'application en occupe environ 2 |

3. **Choisissez comment MIA sera jointe** : c'est la première question de l'installeur.

   | Réponse | Quand | Ce qu'il faut préparer |
   |---|---|---|
   | **1) Autonome** | Un serveur dédié à MIA, sans proxy | Un port libre, 8080 par défaut. MIA sert alors du HTTP : pour du HTTPS, il faut un proxy devant elle |
   | **2) Derrière Traefik** | Un Traefik tourne déjà sur ce serveur | Le nom de son réseau Docker (`docker network ls`), qui doit exister, et le nom de domaine qu'il servira |
   | **3) Derrière nginx ou Caddy** | Un nginx ou un Caddy tourne déjà sur ce serveur | Un port libre, l'adresse publique, et le réglage du proxy (étape 5) |

   La réponse **4) Développement local** est faite pour un poste de travail, jamais pour un serveur.
4. **Faites pointer le nom de domaine sur le serveur**, chez son gestionnaire, pour les réponses 2 et
   3. Faites-le tôt : un changement de DNS peut mettre du temps à se propager.
5. **Derrière nginx ou Caddy, réglez le proxy.** Il reçoit l'adresse en HTTPS, avec son certificat, et
   transmet au port choisi, sur le serveur lui-même. Quatre points comptent :
   - **laisser passer le flux des réponses** sans le retenir — `proxy_buffering off` pour nginx,
     `flush_interval -1` pour Caddy —, avec un délai de lecture de 300 secondes : sinon le texte
     n'apparaît qu'à la fin de la réponse, ou pas du tout ;
   - **réécrire l'en-tête `X-Forwarded-Proto`** à chaque requête : MIA s'y fie pour protéger ses
     cookies, et un en-tête transmis tel quel pourrait être forgé par un visiteur ;
   - transmettre l'adresse demandée et celle du visiteur (`Host`, `X-Forwarded-For`) ;
   - accepter des fichiers de 50 Mo, pour les dépôts.

   Les configurations complètes, prêtes à copier, sont dans le dépôt de MIA :
   `docs/INSTALLATION.md`, « Derrière nginx ou Caddy ».
6. **Pour une base MySQL déjà en service**, et seulement dans ce cas : MySQL 8.0 ou plus, une base
   `myllm` et un utilisateur `myllm` qui a le droit d'y créer des tables. D'autres noms se donnent à
   l'installeur avant de le lancer, par `MIA_DB_NAME` et `MIA_DB_USER`.

## Résultat

Une fois MIA récupérée — première étape de
[Installer MIA sur le serveur](installer-mia-sur-le-serveur.md) —, `./install.sh --check` contrôle
tout cela sans rien modifier, et répond « Contrôles préalables tous passés. »

## Ce que vous pouvez préparer pour la suite

| Pour… | Il faut… | Quand |
|---|---|---|
| Que l'assistant réponde | Une clé d'API d'au moins un éditeur d'IA | Juste après l'installation — voir [Enregistrer les clés API](../modeles/enregistrer-les-cles-api.md) |
| Le compte administrateur | L'adresse e-mail réelle de la personne qui administrera MIA, et un mot de passe de 8 caractères au moins — ou laisser l'installeur en tirer un | À l'installation |
| La connexion par Google ou par Microsoft | Une application déclarée chez Google Cloud ou dans Microsoft Entra | Après l'installation : l'URI de redirection à y déclarer s'affiche dans **Organisation → Authentification** — voir [Configurer la connexion](../utilisateurs/configurer-la-connexion.md) |
| Les connecteurs Google et Microsoft | Les droits à accorder par votre service informatique, qui dépendent des réglages de vos consoles | Avant de les proposer — voir [Autoriser MIA dans Microsoft Entra ID](../connecteurs/autoriser-mia-dans-microsoft-entra-id.md) et [Autoriser MIA dans Google Workspace](../connecteurs/autoriser-mia-dans-google-workspace.md) |

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « docker est introuvable. » | Installez Docker Engine, avec le plugin Compose. |
| « « docker compose » (v2) est introuvable. » | Installez le plugin Compose version 2 : `docker-compose` ne convient pas. |
| « le démon Docker ne répond pas. » | Démarrez Docker, ou ajoutez votre compte au groupe `docker`. |
| « architecture ARM détectée » | Un avertissement : l'installation continue. Si la construction échoue, la marche à suivre est dans `docs/INSTALLATION.md`, « Architecture ARM ». |
| « moins de 5 Go libres » | Libérez de la place avant de lancer l'installation. |
