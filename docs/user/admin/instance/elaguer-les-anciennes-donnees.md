---
title: Élaguer les anciennes données
description: Fixer la durée de conservation des conversations et des créations, et lancer les élagages qui libèrent de la place.
sidebar:
  order: 4
---

**Administration → Maintenance → Données → Élagage.** Supprimer les conversations inactives et les créations
anciennes libère de la place. **Rien ne s'exécute automatiquement : chaque élagage demande un clic, et
une confirmation.**

## Avant de commencer

- **Prévenez les utilisateurs** : qu'ils épinglent les conversations à garder — cinq chacun au plus —
  et téléchargent leurs créations importantes depuis **Mes créations**.
- **L'élagage est définitif**, et la sauvegarde de l'interface ne contient pas les conversations.
  Avant un premier élagage important, demandez à MyAlfred de vérifier qu'une sauvegarde complète
  récente existe.

## L'état des lieux

La page mesure le volume : **Conversations** — dont épinglées, jamais élaguées —, **Créations**,
**Médias sur disque** — dont orphelins. La mesure est gardée dix minutes ; **Recalculer** la
rafraîchit.

## Les durées de conservation

| Réglage | Compté depuis | Par défaut |
|---|---|---|
| **Conversations — jours d'activité à conserver** | Le dernier message de la conversation | 120 jours |
| **Créations — jours à conserver** | La date de génération | 120 jours |

De 1 à 3 650 jours ; vide ou 0 revient à 120. L'enregistrement est automatique — mais régler une durée
ne supprime rien : il faut lancer l'élagage.

## Étapes

Trois actions, chacune confirmée en tapant **ELAGUER** :

1. **Élaguer les conversations** : supprime définitivement les conversations plus anciennes que la
   durée choisie. Sont épargnées les conversations **épinglées**, celles qui ont un message récent et
   celles dont une génération est en cours.
2. **Élaguer les créations** : retire les fichiers des créations plus anciennes que la durée. Le
   message reste, avec la mention « Création élaguée le … — le fichier et le lien de partage ont été
   supprimés. ». **L'épinglage ne protège pas les créations.**
3. **Nettoyer les fichiers orphelins** : supprime les fichiers que plus aucun message n'utilise,
   vieux de plus de 24 heures. Il est aussi relancé à la fin des deux autres élagages.

## Résultat

Le volume diminue dans l'état des lieux. La base de données, elle, ne rétrécit pas sur le disque après
une suppression : c'est normal. L'historique de consommation et les quotas ne sont pas touchés.

## Cas particuliers

- **Un élagage long** s'arrête au bout d'environ 25 secondes : « Budget de temps atteint : il en
  reste N. Relancez pour continuer. ». Relancez jusqu'à ce qu'il ne reste rien.
- **Un seul élagage à la fois** : « Un élagage est déjà en cours. Réessayez dans un instant. »
- Côté utilisateur, une conversation élaguée **disparaît sans prévenir**.

## Exemple

Conversations à 180 jours, créations à 90 jours. Vous annoncez la règle aux utilisateurs une semaine à
l'avance, puis vous lancez **Élaguer les créations** et **Élaguer les conversations** le premier lundi
de chaque trimestre.
