---
title: Fixer les quotas
description: Plafonner la consommation mensuelle d'un utilisateur ou d'un groupe, comprendre quel quota s'applique, et débloquer quelqu'un.
sidebar:
  order: 2
---

Un quota plafonne la consommation d'une personne sur le mois calendaire. Il se fixe sur l'utilisateur,
sur ses groupes, ou nulle part.

## Avant de commencer

**Les quotas se saisissent en milliers de crédits par mois.** Saisir **20** donne **20 000 crédits**.

**Un budget IA est fixé ?** Sous le champ de quota, un **repère** indique le quota par compte actif
qui garantit de ne jamais l'atteindre — voir [Fixer un budget IA mensuel](fixer-un-budget.md).

## Étapes

- **Sur un groupe** : **Administration → Personnes → Groupes**, carte du groupe, volet
  **Paramètres**, champ **Quota (k crédits/mois)**. Il s'applique à **chaque membre**. Laissé vide, le
  groupe est **neutre** — sa carte affiche « Quota mensuel : aucun (neutre) » : il n'impose rien et
  n'ouvre rien.
- **Sur une personne** : **Administration → Personnes → Comptes**, **Modifier**, champ **Quota mensuel
  (k crédits)**. Le quota ne se règle pas à la création du compte, seulement ensuite.

Un changement vaut dès l'action suivante de la personne.

## Quel quota s'applique

**Le plus généreux l'emporte, toujours.** MIA compare le quota individuel de la personne, s'il est
renseigné, et les quotas **définis** de ses groupes **actifs**, et retient **le plus élevé**. Un
quota ne restreint jamais ce qu'un autre accorde : ajouter quelqu'un à un groupe plus généreux suffit
à le relever, même s'il a un ancien quota individuel.

- Aucun quota nulle part : **illimité**.
- Un quota individuel de **0** ne bloque que s'il est seul : un groupe doté passe devant.

Le texte sous le champ de la fiche dit, pendant la saisie, d'où viendra le quota appliqué : « Ses
groupes sont plus généreux… », « Plus généreux que ses groupes… », « Vide : hérite du plus généreux
des groupes… », « Vide et aucun quota de groupe : illimité. »

**Un compte de démonstration** n'a pas de quota mensuel : son enveloppe de crédits, utilisable une
seule fois, remplace tout le reste tant que dure la démo — voir [Comptes de
démonstration](../utilisateurs/comptes-de-demonstration.md).

```mermaid
flowchart TB
  accTitle: Quel quota s'applique
  accDescr: MIA réunit le quota individuel, s'il est renseigné, et les quotas définis des groupes actifs. S'il y en a au moins un, le plus élevé s'applique. Sinon, illimité.
  A["Quota individuel<br>(s'il est renseigné)"] --> R{"Au moins un<br>quota défini ?"}
  G["Quotas des groupes actifs<br>(ceux qui en définissent un)"] --> R
  R -- "oui" --> M["Le plus élevé<br>s'applique"]
  R -- "non" --> L["Illimité"]
```

> **Un groupe sans quota n'ouvre rien.** Il est neutre : un membre de *Direction* (sans quota) et de
> *★ Par défaut* (quota 5) est plafonné à 5 000 crédits. Pour libérer une personne, donnez un quota à
> *Direction*, ou un quota individuel élevé.
>
> **Pour limiter une personne**, un quota individuel plus bas ne suffit plus : retirez-la du groupe
> généreux.

## Exemples

| Situation | Quota appliqué |
|---|---|
| ★ Par défaut vide, *Support* 20, *Direction* 50 | **50 000** |
| La même, *Direction* devenu inactif | **20 000** |
| La même, avec un quota individuel de 5 | **50 000** — le plus généreux l'emporte |
| La même, avec un quota individuel de 80 | **80 000** |
| ★ Par défaut 10, ses autres groupes vides | **10 000** — pas illimité |
| Aucun quota nulle part | **Illimité** |
| Quota individuel 0, aucun quota de groupe | **Bloqué** dès maintenant |

## Ce que voient les utilisateurs

Sous leur nom, dans la barre latérale : « *N* crédits restants / *total* » — barre orange à 70 %,
rouge à 90 %, « Quota épuisé » à 100 %. Au quota atteint, une fenêtre les invite à contacter un
administrateur ou à attendre le 1er du mois ; leurs demandes, leurs créations et leurs dictées sont
refusées.

La dernière opération avant le blocage peut dépasser légèrement le quota : le contrôle se fait avant
chaque opération.

## Débloquer quelqu'un

1. Le repérer : **Alertes** du tableau de bord — à partir de 80 % —, ou tuile **Quota ≥ 90 %** de la
   page **Comptes**.
2. Relever son **quota individuel**, ou celui d'un de ses groupes, ou l'ajouter à un groupe plus
   généreux : le plus généreux de tous s'applique.
3. L'effet est immédiat. Il n'existe ni remise à zéro du compteur, ni recharge ponctuelle.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Une personne est bloquée alors que son groupe a un gros quota | Le groupe est-il **actif** ? Un groupe inactif ne compte pas. Sinon, sa consommation du mois a atteint ce quota. |
| Un membre d'un groupe sans quota — « aucun (neutre) » — est plafonné | Un autre de ses groupes a un quota : c'est le plus élevé des quotas **définis** qui s'applique. |
| Le quota semble dix fois trop petit ou trop grand | Les champs sont en **milliers** de crédits. |
