---
title: Installer MIA sur le serveur
description: Lancer l'installeur, répondre à ses questions, et obtenir MIA en service avec son compte administrateur.
sidebar:
  order: 2
---

L'installeur fait tout ce qui se passe sur le serveur : il contrôle les prérequis, pose ses questions,
écrit la configuration avec des secrets tirés au hasard, construit et démarre MIA, lance le diagnostic
et crée le compte administrateur. Il ne modifie rien tant que ses contrôles ne sont pas tous passés.

## Avant de commencer

- Le serveur préparé : Docker, le mode choisi, le proxy et le domaine s'il y a lieu — voir
  [Préparer le serveur](preparer-le-serveur.md).
- Un terminal sur le serveur.

## Étapes

1. Récupérez MIA, puis placez-vous dans son dossier :

   ```bash
   git clone https://github.com/MyAlfred-ai/myLLM.git mia
   cd mia
   ```

   Choisissez son emplacement une fois pour toutes : ce dossier ne se déplace plus ensuite.
2. Contrôlez le serveur, sans rien modifier :

   ```bash
   ./install.sh --check
   ```

   Chaque point bloquant est suivi de sa correction. Relancez jusqu'à « Contrôles préalables tous
   passés. »
3. Lancez l'installation :

   ```bash
   ./install.sh
   ```

4. Répondez aux questions :

   | Question | Votre réponse |
   |---|---|
   | **Comment MIA sera-t-elle jointe ?** | Le mode choisi en préparant le serveur : **1** Autonome, **2** Derrière Traefik, **3** Derrière nginx ou Caddy |
   | **Port publié sur l'hôte** | 8080, ou un autre port libre. Pas demandé derrière Traefik |
   | **Nom de domaine (sans https://)** | Derrière Traefik seulement : `mia.exemple.fr` |
   | **Adresse publique de MIA** | Dans les autres modes : l'adresse complète que les utilisateurs ouvriront, `https://mia.exemple.fr`. Celle proposée par défaut ne vaut que pour un essai sur le serveur lui-même |
   | **Hôte MySQL** | Vide : l'installeur installe sa propre base. Sinon, l'adresse de la base en service, puis le mot de passe de son utilisateur |
   | **Google Client ID** | Vide : la connexion Google se règle ensuite dans MIA, où elle se modifie sans redémarrer |
   | **E-mail du premier administrateur** | Une adresse réelle et complète : la vôtre, ou celle de la personne qui administrera MIA. Aucune n'est proposée, et une adresse refusée est redemandée |
   | **Mot de passe de l'administrateur** | 8 caractères au moins, en saisie masquée ; plus court, il est redemandé. Vide : l'installeur en tire un au hasard |

5. L'installeur écrit `.env`, construit et démarre MIA, attend qu'elle réponde — trois minutes au
   plus —, lance le diagnostic, puis crée le compte administrateur. Un contrôle en échec au diagnostic
   est signalé sans arrêter l'installation.
6. Lisez le récapitulatif « MIA est installée » : l'adresse, l'identifiant et le mot de passe. **Un
   mot de passe tiré au hasard n'est affiché qu'une fois** : notez-le avant de fermer le terminal.
7. Copiez le fichier `.env` du dossier en lieu sûr, hors du serveur. Il porte la clé qui chiffre les
   clés d'API enregistrées : sans elle, celles-ci deviennent illisibles.

## Résultat

L'adresse de MIA ouvre la page de connexion. Le récapitulatif liste aussi ce qu'il reste à faire dans
l'administration, à commencer par une clé d'API : voir
[Faire les réglages de base](faire-les-reglages-de-base.md).

## Cas particuliers

- **Un fichier `.env` existe déjà** : l'installeur s'arrête sans rien toucher. Pour seulement
  redémarrer MIA : `docker compose up -d`.
- **Changer de mode, d'adresse ou de port : `./install.sh --force`**. Les questions sont reposées et
  `.env` réécrit ; l'ancien est mis de côté, dans `.env.bak.` suivi de la date. Ses secrets sont
  **repris** : la base installée avec MIA s'ouvre toujours, et les clés d'API enregistrées restent
  lisibles. MIA peut rester en service pendant ce temps. Si un compte existe déjà à l'adresse
  donnée, il est laissé tel quel, mot de passe compris. Ce que vous aviez ajouté à la main dans
  `.env` n'est pas repris : retrouvez-le dans la copie mise de côté.
- **Installer sans questions**, depuis un script : `./install.sh --non-interactive`, les réponses
  données d'avance par des variables `MIA_…` — `MIA_MODE`, `MIA_URL`, `MIA_ADMIN_EMAIL`… La liste
  complète : `./install.sh --help`. `MIA_ADMIN_EMAIL` est obligatoire : absente ou refusée,
  l'installeur s'arrête sans avoir rien écrit.
- **Plus de générations en même temps** : MIA fait tourner 4 workers, qui mènent chacun une
  génération à la fois. Pour une équipe plus nombreuse, mettez `MIA_WORKERS=8` (par exemple) dans
  `.env`, puis `docker compose up -d`. Comptez un worker pour 3 à 5 personnes qui conversent en
  même temps.
- **Sauvegarder** : `./backup.sh` copie la base et les fichiers de MIA dans `~/backups/`, et garde les
  trois dernières copies. Il n'est lancé seul qu'avant une [mise à jour](mettre-a-jour-mia.md) : planifiez-le, par exemple
  chaque nuit, et gardez les copies hors du serveur.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « le port 8080 est déjà utilisé sur cette machine. » | Choisissez-en un autre, ou libérez celui-ci. |
| « le réseau Docker « … » n'existe pas. » | Ce n'est pas le réseau de votre Traefik : `docker network ls` le donne. Relancez avec son nom : `TRAEFIK_NETWORK=<nom> ./install.sh`. |
| « MIA n'a pas répondu au bout de 180 secondes. » | Les derniers journaux s'affichent. Le diagnostic complet : `docker compose exec app php scripts/doctor.php`. |
| « MIA_ADMIN_EMAIL manque » ou « MIA_ADMIN_EMAIL refusée » | Sans questions, l'adresse de l'administrateur se donne d'avance, complète : `MIA_ADMIN_EMAIL=vous@exemple.fr`. Rien n'a été écrit : relancez. |
| « le compte n'a pas pu être créé » | L'adresse et le mot de passe sont contrôlés dès les questions : le message juste au-dessus nomme l'incident survenu. Créez le compte sans attendre : dans le dossier de MIA, `docker compose exec -T app php scripts/admin-create.php --email=<adresse>`, le mot de passe lu sur l'entrée standard. Ou ouvrez l'adresse de MIA : l'écran « Bienvenue dans MIA » crée le compte administrateur — pour quiconque y arrive le premier. |
| Page « MIA n'est pas encore configurée » | Un secret manque dans `.env` : la page nomme la variable. |
| Page « MIA ne peut pas joindre sa base de données » | La base est arrêtée, ou son adresse est fausse dans `.env`. `docker compose logs db` en dit plus. |
| Page 404 de Traefik | Le domaine ne correspond pas au DNS, ou MIA n'est pas sur le réseau de Traefik. |
| Les réponses n'arrivent qu'à la fin, ou pas du tout | Le proxy retient le flux : voir [Préparer le serveur](preparer-le-serveur.md), étape 5. |
| Toutes les générations échouent, alors que les tests de l'administration sont au vert | Le worker ne sort pas vers Internet : les tests partent de l'application, pas de lui. |
