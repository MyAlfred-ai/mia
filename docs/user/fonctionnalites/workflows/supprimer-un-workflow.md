---
title: Supprimer un workflow
description: Retirer définitivement un workflow dont vous n'avez plus besoin, depuis sa vignette ou depuis l'éditeur, après une confirmation qui dit ce qui part.
sidebar:
  order: 14
---

Un workflow dont vous n'avez plus besoin se supprime en deux gestes : la corbeille, puis la
confirmation. Rien n'est supprimé sans elle.

## Avant de commencer

- La suppression est **définitive** : un workflow supprimé ne se récupère pas, ni ses versions
  publiées. Pour l'arrêter seulement un temps, mettez-le plutôt en **pause** (bouton **‖** de sa
  vignette) : il ne démarre plus, et **Réactiver** le remet en service.
- Supprimer un workflow demande le droit correspondant : si la corbeille n'apparaît pas, votre rôle ne
  le permet pas.

## Étapes

1. Ouvrez **Workflows**, onglet **Mes workflows**.
2. Sur la vignette du workflow, touchez la **corbeille**, en bas à droite (à côté de **Lancer** et de
   la pause). Depuis l'éditeur, la corbeille est aussi en haut, à côté de **Lancements** — et le bas
   des **Réglages** garde un bouton **Supprimer**.
3. Une fenêtre dit ce qui va partir : le workflow, son brouillon et ses versions, l'historique de ses
   lancements, et les exécutions encore en cours qui seront arrêtées.
4. Touchez **Supprimer définitivement**. **Annuler**, la croix ou la touche Échap referment la
   fenêtre sans rien supprimer.

Vous revenez à la liste, où un message confirme la suppression.

## Ce qui reste, ce qui part

- **Les conversations de ses lancements restent** dans votre historique, avec tout ce que les étapes
  y ont écrit.
- **L'historique de ses lancements part** avec lui (l'onglet **Historique des exécutions** ne les
  montre plus).
- **Une exécution en cours s'arrête** : l'étape qui tournait est interrompue, une validation qui vous
  attendait est fermée, et sa conversation le dit (« Workflow supprimé par vous : cette exécution
  s'arrête ici »).
- **Un workflow qui démarrait sur un évènement** cesse de surveiller la boîte mail ou le dossier, et
  son adresse d'appel ne répond plus.

## Limites

- Une action déjà faite (un email envoyé, un évènement créé) n'est pas défaite : seul le workflow
  disparaît.
- Un autre workflow réglé pour démarrer à la fin de celui-ci ne démarrera plus.
