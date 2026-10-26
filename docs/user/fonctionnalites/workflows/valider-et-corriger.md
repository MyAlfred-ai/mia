---
title: Valider et corriger
description: Approuver, corriger une pièce, renvoyer une étape avec une remarque, trancher à la place de MIA, reprendre une exécution arrêtée.
sidebar:
  order: 7
---

Quand une exécution atteint une validation, elle s'arrête et vous attend. Vous décidez depuis la page
de l'exécution — ou depuis la carte qui apparaît dans sa conversation.

## Avant de commencer

L'exécution apparaît en tête de **Workflows**, sous **Ce qui vous attend**. Sans réponse dans le délai
réglé (sept jours par défaut), elle s'arrête.

## Étapes

1. Ouvrez l'exécution : l'encadré **Validation**, en tête, montre ce qu'il faut vérifier et les
   pièces à relire. Touchez une pièce : elle s'ouvre dans le volet du dossier.
2. Décidez :
   - **Approuver et continuer** : le workflow reprend.
   - **Corriger** une pièce (si la validation le permet) : dans le volet, modifiez-la, **Enregistrer**,
     puis approuvez. Les étapes suivantes liront votre version.
   - **Renvoyer avec une remarque** : dites ce qu'il faut changer ; l'étape recommence avec votre
     remarque et remet une nouvelle version de sa pièce. Deux fois au plus par validation.
   - **Arrêter** : l'exécution s'arrête, le dossier reste.
3. **Quand MIA hésite** sur une condition réglée « me demander », l'encadré montre sa question et ce
   qu'elle a vu : répondez **Oui** ou **Non** à sa place.

## Ce qu'il faut savoir

- **Des actions à approuver** (un email, un évènement préparés par une étape) se décident dans la
  **conversation de l'exécution**, comme toute carte du chat : le workflow reprend dès qu'elles sont
  approuvées ou annulées.
- **Une exécution en échec ou en pause** (modèle indisponible, crédits épuisés, assistant devenu
  inaccessible) affiche sa cause : **Reprendre à cette brique** la relance là où elle s'est arrêtée,
  sans refaire ce qui est fait — voir [Reprendre après une erreur](reprendre-apres-une-erreur.md).
- **Une validation dans une boucle** s'ouvre à chaque tour ; la renvoyer refait l'étape du même tour.
- **Les relectures d'une boucle** : **Relire la liste avant de commencer** vous montre les éléments
  trouvés, tous cochés — décochez, puis **Lancer avec la sélection**. **Relire toutes les actions
  ensemble** vous montre, à la fin de la boucle, les actions préparées à chaque tour — cochez celles
  qui partent, choisissez **Créer les brouillons** ou **Envoyer les emails**, puis **Exécuter la
  sélection** ; **N'en exécuter aucune** écarte tout et le workflow continue. Dans la conversation de
  l'exécution, la carte **Approuver** garde tout ; **Ajuster** ouvre la page de l'exécution pour
  choisir.
- **Une image, une page web ou une carte** remise par une création ne se corrige pas à la main :
  renvoyez-la avec une remarque, elle est refaite à partir de sa version précédente.
