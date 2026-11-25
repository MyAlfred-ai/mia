---
title: Régler les workflows
description: Proposer ou suspendre les workflows des créateurs et administrateurs, décider s'ils peuvent agir sans validation, borner leurs boucles, les faire démarrer dès qu'un email arrive, et savoir ce qu'ils coûtent.
sidebar:
  order: 8
---

Un workflow est un travail en plusieurs étapes que MIA et les assistants mènent pour un créateur ou
un administrateur : chaque étape remet une pièce à la suivante, avec des validations et un livrable.
Il se règle sur la page **Outils**, carte **Workflows**, marquée **Intégré**. Côté utilisateur :
[Les workflows](../../fonctionnalites/workflows/index.md).

## Avant de commencer

Les étapes s'exécutent sur le **serveur de tâches de fond** (le conteneur *worker*), comme les
tâches programmées : vérifiez dans **Maintenance → Diagnostic** qu'il est vivant. Aucune clé
supplémentaire : une étape passe par le modèle de son assistant, ou par celui que le créateur a
choisi.

## Étapes

- **Proposer les workflows** : interrupteur sur **Actif** — c'est le réglage par défaut. La tuile
  **Workflows** apparaît dans la barre latérale des créateurs et des administrateurs, et MIA sait en préparer un quand on le lui demande, sous forme de carte à approuver.
- **Les suspendre** : interrupteur sur **Inactif**. La tuile disparaît et plus rien ne démarre ; une
  exécution en cours se met **en pause** à sa prochaine étape, reprenable quand les workflows sont de
  nouveau proposés. Les workflows restent enregistrés.
- **Proposer ou non « Agir sans validation »** : case de la même carte, cochée par défaut. Décochée,
  tout ce qu'une étape ou une action prépare attend la validation de la personne qui a lancé le
  workflow.
- **Borner les boucles** : liste **Éléments traités au plus par une boucle « pour chaque »** de la
  même carte — 50, 100, 200 (par défaut) ou 500. Une boucle répète ses briques pour chaque ligne d'un
  Excel, chaque pièce jointe, chaque fichier d'un dossier… ; au-delà de ce nombre, les éléments
  suivants sont signalés dans la conversation de l'exécution, pas traités. Chaque créateur règle en
  plus le maximum de chacune de ses boucles, sous ce plafond. Le changement vaut pour les prochaines
  boucles qui démarrent.
- **Démarrer dès qu'un email arrive** : case **Démarrer dès qu'un email arrive (notifications
  instantanées)**, cochée par défaut. Un workflow qui démarre sur un email est alors prévenu par la
  boîte à l'arrivée du message, au lieu de la vérifier chaque minute (une vérification de
  secours passe encore toutes les heures, et une seconde suit chaque notification de près).
  **Gmail n'est instantané qu'une fois le topic Pub/Sub relié** (ci-dessous) : sans lui, la ligne
  Gmail le dit et la boîte est vérifiée chaque minute. Sous la case, une ligne par service dit s'il est prêt.
  - **Outlook** : rien à régler — la permission de lecture des emails du connecteur suffit.
  - **Gmail** : Google passe par un **topic Google Cloud Pub/Sub** que vous créez une fois, dans le
    projet Google Cloud de la connexion Google de MIA. Activez Cloud Pub/Sub, créez un topic, donnez
    au compte `gmail-api-push@system.gserviceaccount.com` le rôle **Pub/Sub Publisher** sur ce topic, <!-- secret-scan:ignore : adresse publique de Google, à recopier telle quelle -->
    collez son nom complet (`projects/<projet>/topics/<topic>`) dans **Gmail : relier un topic
    Google Cloud Pub/Sub**, puis créez dans Google Cloud un abonnement **push** du topic vers
    l'adresse que MIA affiche alors. **Tester le topic** l'essaie sur votre propre boîte Gmail
    (reliez-la d'abord dans **Réglages → Comptes connectés**).

## Ce qu'il faut savoir

- **Qui** : les créateurs d'assistants et les administrateurs, sans réglage par groupe.
- **Le coût** : chaque étape est un message, dans le quota de la personne qui lance et dans le budget
  IA de l'organisation ; une question posée à MIA par une condition coûte quelques crédits, une règle
  rien. Une boucle multiplie le coût de ses briques par son nombre d'éléments ; la fenêtre de
  lancement le chiffre, et demande confirmation au-delà de cinquante tours. Repérer les éléments d'un
  texte par MIA coûte un appel, au départ de la boucle. Quota ou budget atteint : l'exécution se met en
  pause, reprenable.
- **Les créations** : la brique **Création** utilise les outils image, page web, site illustré et
  carte que vous ouvrez aux groupes (même périmètre que le menu **+** du chat), et coûte ce qu'ils
  coûtent dans le chat. Un outil retiré d'un groupe arrête les exécutions qui s'en servent, avec la
  raison.
- **Les garde-fous** : seule la version publiée s'exécute ; une étape lit et rédige par défaut, et ses
  actions attendent l'accord de la personne sauf réglage contraire ; trente workflows au plus par
  personne, quinze briques par workflow. Dans une boucle, un élément en échec ne bloque pas les autres,
  et trois échecs d'affilée la mettent en pause plutôt que de dépenser sur une cause commune.
- **Les fichiers lus par une boucle** (une pièce jointe, un fichier d'un dossier Drive) sont copiés
  dans le stockage des pièces jointes le temps de l'exécution, et suivent la même rétention que les
  conversations (élagage).
- **Les notifications instantanées exigent une adresse publique** : MIA doit être joignable depuis
  Internet en HTTPS, à l'adresse déclarée pour l'instance. Sinon, ou si la case est décochée, les
  boîtes sont vérifiées chaque minute. Une notification ne fait que réveiller la
  vérification : les emails sont toujours relus avec le compte de la personne, et une adresse qui
  fuiterait ne permettrait au pire que de déclencher une vérification de plus. **Changer
  d'adresse** (Gmail) en crée une nouvelle, à reporter dans l'abonnement push de Google Cloud.
- **La sauvegarde** : les workflows et leurs exécutions n'en font pas partie, comme les tâches
  programmées et les conversations — ni les abonnements des boîtes mail, recréés d'eux-mêmes.
