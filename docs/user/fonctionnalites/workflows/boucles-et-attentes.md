---
title: Boucles, attentes et notifications
description: Refaire une étape jusqu'à un bon résultat, faire patienter un workflow, être prévenu en route — et où régler les boucles « pour chaque élément ».
sidebar:
  order: 9
---

Trois briques règlent le rythme d'un workflow : la **boucle** répète un morceau du travail, l'attente
le fait patienter, **Me prévenir** vous envoie un message en route.

Une boucle répète de deux façons : **pour chaque élément** (chaque ligne d'un Excel, chaque pièce
jointe, chaque fichier d'un dossier, chaque sujet d'un email…) — tout est détaillé dans [Répéter pour
chaque élément](repeter-pour-chaque-element.md) — ou **jusqu'à un résultat**, décrite ici.

## Avant de commencer

- Une boucle **jusqu'à un résultat** refait une étape : ajoutez-la dans la boucle, avec la brique
  qui juge le résultat si besoin (une étape qui remet une Fiche notée).
- Une attente **jusqu'à une date** lit le champ Date d'une Fiche : un champ Date au départ, ou une
  étape qui remet une Fiche avec une date.
- **Me prévenir** écrit sur les appareils que vous avez inscrits dans **Espaces → Tâches
  programmées**, carte **Notifications**. Sans appareil inscrit, le message reste dans la conversation de
  l'exécution.

## Étapes

1. Dans l'éditeur, touchez **+** à l'endroit voulu, puis **Boucle**, **Attendre** ou **Me prévenir**.
2. Pour une **boucle**, choisissez **comment répéter** :
   - **Pour chaque élément** : voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
   - **Jusqu'à un résultat** : l'étape recommence en reprenant sa version précédente, jusqu'à ce
     qu'une règle (« la note est au moins 8 ») ou une question à MIA dise que c'est bon. Choisissez le
     nombre de tours, et ce qui se passe s'il est atteint : garder la dernière version, ou arrêter le
     workflow.
3. Ajoutez les briques à répéter **dans le cadre de la boucle**, avec **+ Ajouter** : étapes,
   créations, actions, validations, attentes, notifications, et une condition qui trie les éléments.
4. Nommez la pièce que la boucle remet à la fin (**Résultats**) : les étapes qui suivent la citent
   comme n'importe quelle pièce.
5. Pour **Attendre**, choisissez une durée, ou une date du dossier avec une heure et un décalage
   (« -2 » : deux jours avant la date).
6. Pour **Me prévenir**, écrivez le message ; citer un champ avec **@** y met sa valeur.

## Ce qu'il faut savoir

- **Ce que la boucle remet** : une boucle « jusqu'à » garde la dernière version de la pièce suivie.
  Pour une boucle « pour chaque », voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
- **Pendant une exécution**, le rail montre le tour en cours (« 3 / 8 ») et le volet de la boucle
  chaque tour avec sa pièce. Une validation placée dans la boucle s'ouvre à chaque tour, et la
  renvoyer refait l'étape du même tour.
- **Une attente** reprend seule à l'heure dite, même si personne n'ouvre MIA. Une date déjà passée
  ne fait pas attendre ; une date trop lointaine (plus de 90 jours) arrête l'exécution. Sur la page
  de l'exécution, **Ne plus attendre** reprend tout de suite.
- **Limites** : une boucle « jusqu'à » cinq tours au plus ; une attente trente jours au plus. Une
  boucle ne contient ni décision de MIA ni autre boucle ; une condition y trie les éléments sans
  ouvrir de voies.
- **Coût** : chaque tour est un vrai travail — une boucle de dix éléments coûte dix fois son étape.
  L'estimation de la fenêtre de lancement en tient compte. Attendre et prévenir ne coûtent rien.
