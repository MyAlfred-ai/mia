---
title: Régler les tâches programmées
description: Proposer ou suspendre les demandes récurrentes des utilisateurs, décider si elles peuvent agir sans validation, et savoir ce qu'elles coûtent.
sidebar:
  order: 6
---

Une tâche programmée est une demande qu'un utilisateur confie à MIA pour qu'elle se répète toute
seule — le point sur ses mails chaque lundi à 8 h, par exemple. Elle se règle sur la page **Outils**,
carte **Tâches programmées**, marquée **Intégré**. Côté utilisateur :
[Programmer une tâche](../../fonctionnalites/conversations/programmer-une-tache.md).

## Avant de commencer

Les tâches s'exécutent sur le **serveur de tâches de fond** (le conteneur *worker* d'une installation
Docker standard), qui vérifie toutes les trente secondes ce qui est échu. Sans lui, elles ne partent
qu'au rythme des visites du site, au mieux une fois par minute : vérifiez dans **Maintenance →
Diagnostic** que le worker est vivant.

Aucune clé supplémentaire : une exécution passe par le modèle de l'assistant choisi, ou par celui que
l'utilisateur a retenu, exactement comme un message.

## Étapes

- **Proposer les tâches programmées** : interrupteur sur **Actif** — c'est le réglage par défaut.
  La tuile **Tâches** apparaît dans la barre latérale de chacun, avec le bouton **Refaire
  régulièrement** sous les réponses et l'horloge **Programmer** de la zone de saisie quand on parle à
  un assistant ; MIA sait aussi préparer une tâche quand on le lui demande, sous forme de carte à
  approuver.
- **Les suspendre** : interrupteur sur **Inactif**. La tuile, les boutons et la préparation par MIA
  disparaissent, et **plus aucune tâche ne s'exécute**. Les tâches restent enregistrées : réactivé, chacune reprend à sa
  prochaine échéance, sans rattraper les créneaux passés.
- **Proposer ou non « Agir sans validation »** : case **Proposer « Agir sans validation »** de la même
  carte, cochée par défaut, enregistrée au clic. Cochée, chacun peut laisser une tâche effectuer
  elle-même ses actions, sans carte à approuver. Décochée, le choix est grisé dans le formulaire des
  tâches avec sa raison, et les tâches déjà réglées ainsi **préparent** leurs actions, qui attendent
  la validation de leur propriétaire — dès leur prochaine exécution, sans rien perdre.

Il n'y a pas de réglage par groupe : le réglage vaut pour toute l'instance.

## Ce qu'il faut savoir

- **Le coût.** Chaque exécution est un message : elle consomme des crédits selon le modèle et les
  sources utilisés, **dans le quota de l'utilisateur** — et dans le budget IA de l'organisation. Quota
  ou budget atteint, l'exécution est sautée (pas d'échec, pas de pause).
- **Le plafond.** Cinq tâches actives au plus par personne, une heure par tâche : le rythme le plus
  soutenu est quotidien.
- **Lecture seule par défaut.** Une tâche lit et rédige ; elle ne prépare aucune action. Son
  propriétaire peut choisir **Préparer des actions à approuver** : la tâche dépose alors des cartes
  d'approbation (brouillon d'email, évènement, tâche, message Slack, workflow n8n, présentation Gamma)
  dans sa conversation, qui attendent son accord **une semaine** au lieu de 30 minutes. Rien ne
  s'exécute sans son clic.
- **Agir sans validation.** Si vous le proposez, le propriétaire peut aussi choisir que la tâche
  **exécute** ces actions elle-même, par le même chemin qu'un clic « Approuver » : connexion, droits
  et réglages vérifiés au moment de l'action. Garde-fous : le choix ne se fait que dans le formulaire
  de la tâche (jamais par une demande à MIA, jamais pendant un essai) ; un email reste un brouillon
  tant que **Envoyer les emails** n'est pas coché ; dix actions au plus par exécution, les suivantes
  redevenant des cartes ; une automatisation n8n ou une présentation Gamma n'agit seule que si son
  outil a été coché dans la tâche — celle d'un outil apporté par l'assistant (que vous pouvez modifier
  à tout moment, pour tous ceux qui s'en servent) dépose une carte à approuver ; chaque action est notée dans la conversation de la tâche, et la liste des
  tâches signale celles qui agissent seules. Le risque à peser : une tâche lit des contenus écrits par
  d'autres (emails reçus, pages web) et pourrait, malgré la consigne qui le lui interdit, suivre une
  instruction qui s'y cache. Décochez l'option si votre organisation ne veut aucune action sans
  validation humaine.
- **Bases et outils.** En plus de ceux de l'assistant, une tâche peut interroger trois bases de
  connaissances de son propriétaire (créées par lui ou partagées avec lui) et engager un outil par
  type parmi ceux que **ses groupes** lui ouvrent : image, automatisation n8n, présentation Gamma. Un
  outil n8n ou Gamma agissant, le choisir exclut la lecture seule : la tâche prépare ses actions, ou
  les effectue si elle agit sans validation. Retirer un partage, désactiver un outil ou le retirer d'un groupe le fait sortir des
  tâches dès leur prochaine échéance : elles s'exécutent sans lui.
- **Les notifications.** Chacun peut être prévenu sur ses appareils quand une tâche a un résultat :
  rien à configurer, la paire de clés de l'instance est créée au premier besoin. Le serveur doit
  pouvoir joindre en HTTPS les services de notification des navigateurs (`fcm.googleapis.com`,
  `updates.push.services.mozilla.com`, `web.push.apple.com`, `*.notify.windows.com`) ; il n'écrit à
  aucune autre adresse. Une notification ne contient jamais la réponse, et elle est chiffrée pour
  l'appareil. Ni les clés ni les appareils inscrits ne font partie de la sauvegarde.
- **L'essai.** Le formulaire propose **Lancer un essai** : une exécution complète, tout de suite,
  sans rien enregistrer, dans une conversation « Essai — *nom* ». Elle compte comme un message, dans
  le quota de l'utilisateur. Un seul essai à la fois par personne.
- **Les droits du moment.** À chaque échéance, MIA revérifie le compte (désactivé : la tâche se met en
  pause), l'accès à l'assistant (retiré : pause) et le quota. Trois échecs d'affilée du modèle mettent
  aussi la tâche en pause : une tâche qui échoue chaque matin ne coûte pas indéfiniment.
- **Les sauvegardes.** Comme les conversations, les tâches et leur historique ne font pas partie de la
  sauvegarde de configuration : une restauration ne relance jamais d'exécutions automatiques ailleurs.

## Vérifier que tout fonctionne

1. Avec un compte de test, programmez une tâche puis cliquez **Exécuter maintenant** : la conversation
   de la tâche s'ouvre et la réponse s'y écrit.
2. Dans la liste des tâches, la dernière exécution est marquée **Réussie**.
3. Pour « Agir sans validation » : avec ce même compte relié à Google Tasks, programmez une tâche
   *« Crée une tâche Test MIA pour demain »*, source **Tâches Google**, mode **Agir sans validation**,
   puis **Exécuter maintenant** : le message **Ce que la tâche a fait sans validation** apparaît avant
   le compte rendu, et la tâche existe dans Google Tasks.

## Voir aussi

- [Les outils](index.md) — les autres outils intégrés de la page
- [La gestion des crédits](../credits/index.md) — quota et budget, qui s'appliquent aux exécutions
