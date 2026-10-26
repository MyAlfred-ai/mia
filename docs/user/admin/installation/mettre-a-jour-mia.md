---
title: Mettre à jour MIA
description: Installer la dernière version de MIA sur le serveur, après une sauvegarde automatique, et revenir à la précédente si besoin.
sidebar:
  order: 4
---

Une nouveauté de MIA n'arrive chez vos utilisateurs qu'avec la mise à jour de l'installation. Une
commande la fait sur le serveur : elle sauvegarde MIA, récupère la dernière version, reconstruit
l'application et la redémarre, en gardant la configuration et les données.

## Avant de commencer

- Un terminal sur le serveur, dans le dossier de MIA, et MIA en service : la sauvegarde la lit.
- **L'accès au dépôt de MIA**, obtenu auprès de MyAlfred en préparant le serveur : la commande le
  redemande à chaque mise à jour. Un jeton d'accès GitHub ; ou, si MIA a été récupérée par SSH et non
  par une adresse `https://`, une clé autorisée, chargée dans `ssh-agent`.
- La version installée : le numéro affiché en bas de la conversation, et sur la page **À propos**. La
  dernière version disponible, et ce que chaque version apporte, sont dans la page **Nouveautés** de
  cette documentation.

## Étapes

1. Donnez le jeton d'accès, sans qu'il s'affiche ni reste dans l'historique du terminal :

   ```bash
   read -rs GH_TOKEN && export GH_TOKEN
   ```

   Collez le jeton, puis **Entrée**. Avec une clé chargée dans `ssh-agent`, passez cette étape.
2. Lancez la mise à jour :

   ```bash
   ./update.sh
   ```

3. Suivez-la : elle annonce chaque étape.

   | Elle affiche | Ce qu'elle fait |
   |---|---|
   | `[update] Sauvegarde pré-déploiement…` | Copie la base et les fichiers de MIA dans `~/backups/`, sous le nom du dossier de MIA. Si la copie échoue, tout s'arrête avant le moindre changement |
   | `[update] git fetch + reset…` | Récupère la dernière version. **Ce qui a été modifié à la main dans les fichiers de MIA est perdu** ; `.env` et les données ne sont pas touchés |
   | `[update] Build images…` | Reconstruit l'application : c'est l'étape la plus longue. MIA reste en service pendant ce temps |
   | `[update] Recreate services…` | Redémarre MIA sur la nouvelle version. À son démarrage, MIA met sa base à jour elle-même |
   | `[update] OK` | Suivi de l'état des services |

4. Contrôlez l'installation :

   ```bash
   docker compose exec app php scripts/doctor.php
   ```

## Résultat

Le numéro affiché en bas de la conversation est celui de la dernière version, et le diagnostic est au
vert — le même, dans MIA : **Administration → Maintenance → Diagnostic**. L'application mobile suit
d'elle-même : elle affiche l'interface du serveur.

## Cas particuliers

- **Revenir en arrière tout seul si la mise à jour échoue** : lancez `./safe-update.sh` à la place
  de `./update.sh` à l'étape 2. Même sauvegarde ; puis, la nouvelle version démarrée, il vérifie
  qu'elle répond, que sa base est à jour et que son service de tâches de fond tourne. Sinon, il remet
  de lui-même la version précédente, et la base telle qu'elle était au moment de la bascule : seul ce
  qui a été fait pendant ces quelques minutes de vérification est perdu. Il refuse de commencer si MIA
  ne répond pas déjà, ou si l'espace disque manque — sans rien modifier.
- **Revenir à la version précédente** : `./rollback.sh` restaure la dernière sauvegarde — la version,
  la base et les fichiers. `./rollback.sh --list` liste les sauvegardes, et `./rollback.sh` suivi d'un
  horodatage en restaure une précise. Il demande de taper `oui` : **tout ce qui a été écrit depuis la
  sauvegarde est perdu**, conversations, documents déposés et réglages compris.
- **Les trois dernières sauvegardes sont gardées** ; chaque nouvelle sauvegarde supprime la plus
  ancienne. Le fichier `.env` n'y est jamais : gardez-en une copie hors du serveur.
- **L'index des bases de connaissances n'est pas sauvegardé**, il se reconstruit : après un retour
  arrière, **Réindexer** remet une base d'accord avec ses documents — voir
  [Administrer les bases](../bases-de-connaissances/administrer-les-bases.md).
- **Une génération en cours** quand MIA redémarre peut s'arrêter sur « Génération interrompue » :
  relancez la demande. MIA ne s'interrompt que le temps de ce redémarrage ; lancez la mise à jour à une
  heure creuse.
- **Rafraîchir aussi les images sur lesquelles MIA est construite**, pour leurs correctifs de
  sécurité : `REFRESH_BASE=1 ./update.sh`. La construction est alors plus longue.

La référence complète — sauvegarde planifiée, restauration, journaux — est dans le dépôt :
`docs/EXPLOITATION.md`.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Pas d'auth GitHub disponible (ni $GH_TOKEN ni ssh-agent). » | Rien n'a été modifié. Refaites l'étape 1 dans le même terminal, puis relancez. |
| « [backup] ÉCHEC : conteneur app introuvable → abandon » | MIA ne tourne pas : la sauvegarde ne peut pas la lire. Rien n'a été modifié. Démarrez-la, `docker compose up -d`, puis relancez. |
| « [backup] ÉCHEC : dump MySQL vide → abandon » | La base n'a pas pu être lue. Rien n'a été modifié. `docker compose logs db` en dit plus. |
| « Pas un repo git (…) » | Ce dossier n'a pas été récupéré par `git clone` : la mise à jour ne peut pas s'y faire. Voir [Installer MIA sur le serveur](installer-mia-sur-le-serveur.md). |
| La mise à jour s'arrête pendant `[update] Build images…` | MIA tourne toujours sur l'ancienne version. Corrigez la cause affichée — l'espace disque, l'accès du serveur à Internet —, puis relancez `./update.sh`. |
| MIA ne répond plus après `[update] OK` | `docker compose logs app` : une ligne `[migrate] ERREUR` nomme la mise à jour de la base qui a échoué. Pendant l'enquête, `./rollback.sh` remet la version précédente en service. |
| Le numéro de version n'a pas changé | Rechargez la page. S'il ne bouge toujours pas, la mise à jour ne s'est pas terminée : relisez ce qu'elle a affiché. |
| `./safe-update.sh` affiche « RETOUR ARRIÈRE AUTOMATIQUE », puis « Retour arrière terminé » | La nouvelle version n'a pas tenu ; la précédente est de nouveau en service. La cause est dans `echec-deploiement.log`, dans le dossier de la sauvegarde sous `~/backups/` : transmettez-le à MyAlfred. |
| `./safe-update.sh` annonce un retour arrière « intervention requise » | La version précédente n'a pas pu être remise en service seule. Lancez `./rollback.sh` suivi de l'horodatage affiché. |
