---
title: Reprendre après une erreur
description: Laisser un workflow réessayer seul, ignorer une brique facultative, reprendre une exécution arrêtée — avec la dernière version au besoin — ou la refaire depuis une brique.
sidebar:
  order: 12
---

Une étape peut échouer : un service saturé, une connexion coupée, une pièce mal formée. Un workflow
sait réessayer seul, passer une brique facultative, et vous laisse reprendre une exécution arrêtée
sans refaire ce qui est fait.

## Avant de commencer

Le réglage se fait brique par brique, dans le volet de l'étape, de l'action ou de la condition, sous
**Si une erreur survient**.

## Étapes

1. **Réessayer seul** : choisissez une à trois tentatives. Quand le service est saturé ou le réseau
   coupé, la brique recommence après 1, 5 puis 15 minutes, sans vous.
2. **Si l'erreur persiste** : **arrêter le workflow** (par défaut), ou **ignorer la brique et
   continuer** — la suite se passe de sa pièce. Réservez-le aux briques facultatives : le rail les
   marque **Facultative**.
3. Sur une exécution arrêtée, la page de l'exécution montre la cause et propose :
   - **Reprendre à cette brique** : elle repart là où elle s'est arrêtée.
   - **Reprendre avec la version *n*** : si vous avez corrigé et republié le workflow depuis, vos
     nouveaux réglages s'appliquent à cette exécution.
   - **Ignorer et continuer** : la brique est passée, le workflow continue.
   - **Refaire depuis une brique…** : les pièces remises à partir de la brique choisie sont refaites,
     celles d'avant sont gardées. Possible aussi sur une exécution terminée ou arrêtée.

## Ce qu'il faut savoir

- **Ce qui n'est pas réessayé** : une clé refusée, un crédit épuisé, un assistant devenu
  inaccessible — attendre n'y change rien. L'exécution s'arrête (ou se met en pause pour les
  crédits) avec la cause, et vous la reprenez une fois le problème réglé.
- **Un nouvel essai prévu** s'affiche avec son heure ; **Réessayer maintenant** l'avance.
- **Une condition ou une validation ne s'ignore pas** : c'est leur réponse qui décide de la suite.
- **Une étape renvoyée avec une remarque**, puis tombée en échec, repart avec sa remarque à la
  reprise.
- Une pièce mal formée est redemandée une fois au modèle, avec la raison, avant d'être comptée comme
  une erreur.
- **Dans une boucle**, un élément en échec ne bloque pas les autres : la boucle passe au suivant
  (sauf si vous l'avez réglée sur **Arrêter le workflow**). Trois échecs d'affilée la mettent en pause ;
  **Reprendre** refait l'élément qui a mis en pause. À la fin, **Refaire les éléments en échec** ne
  rejoue qu'eux : voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
