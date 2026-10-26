---
title: Choisir un modèle
description: Passer d'un modèle d'IA à un autre, comparer deux réponses et comprendre ce que signalent le bandeau et les messages d'erreur.
sidebar:
  order: 1
---

Le modèle se choisit dans une conversation standard, c'est-à-dire sans assistant. Ce qu'est un
modèle et ce qui le distingue d'un autre : [Les modèles de LLM](index.md).

## Étapes

1. Cliquez le **sélecteur de modèle** — logo, nom et flèche — en bas à droite du champ de saisie.
2. Parcourez la liste, regroupée par éditeur. Chaque ligne porte un repère de coût, de **€** à
   **€€€€€** : le prix relatif, pas la qualité. Survolez-le pour lire le coût en clair :
   « ≈ 35 crédits par réponse, ≈ 13 par recherche Internet ».
3. Cliquez le modèle voulu. Il s'applique à vos prochains messages dans cette conversation.

## Résultat

Le bandeau au-dessus des messages affiche le modèle choisi et ce qu'il sait faire : **Internet**,
**Images**, **Fichiers**, **Connecteurs**, chacun précédé de ✓ ou ✗, puis ce que coûte une réponse
(« ≈ 35 crédits par réponse »). Il indique aussi le nombre de
messages d'historique envoyés au modèle. Il est **replié par défaut** — seul le modèle s'affiche : la
flèche à sa droite le déplie, et MIA retient votre choix sur cet appareil.

Sous chaque réponse, une étiquette rappelle le modèle qui l'a rédigée, les crédits consommés et le
temps de réponse.

**D'où vient le coût annoncé.** Des tarifs réellement appliqués à vos crédits (ceux de votre
organisation, sinon le prix public du modèle), appliqués à la taille réelle des réponses récentes de
ce modèle — historique, documents et raisonnement compris. Une conversation très longue, des fichiers
joints ou des connecteurs coûtent davantage ; une recherche Internet s'ajoute à la réponse.

## Comparer deux réponses

Sous une réponse, **Regénérer** la refait avec le modèle **actuellement sélectionné**. Pour comparer,
choisissez un autre modèle puis cliquez **Regénérer** : l'étiquette indique le nouveau modèle, qui
devient celui de la conversation.

La régénération ne rejoue ni la recherche Internet ni les connecteurs. Restez sur la page tant
qu'elle n'est pas terminée. Sous une image créée par un outil image, **Regénérer** ne passe pas par
le modèle de conversation : l'outil produit une autre version de l'image.

## Cas particuliers

- **Avec un assistant**, le sélecteur disparaît : l'assistant impose son modèle.
- **Nouvelle conversation** : elle démarre sur le modèle par défaut de l'instance, pas sur le dernier
  que vous avez utilisé.
- **Allumer un connecteur sur un modèle qui ne sait pas le faire** : la fenêtre « Ce modèle ne gère
  pas les connecteurs » propose les modèles compatibles, avec **Changer de modèle** ou **Garder ce
  modèle**. À l'inverse, passer sur un modèle incompatible éteint le connecteur, qui se rallume au
  retour sur un modèle compatible.
- **Bascule sur le secours** : le message « Bascule sur le LLM de secours : A → B » signale que votre
  modèle était en panne, et dit pourquoi entre parenthèses — service surchargé, crédit épuisé chez
  l'éditeur… La même information reste dans la liste des actions au-dessus du message : « Réponse du
  modèle de secours : le modèle choisi était indisponible ». La réponse vient du secours ; le message
  suivant retente votre modèle.
- **Modèles de raisonnement** : ils réfléchissent avant de répondre, sous la même mention « En
  réflexion… ». Leur réflexion n'est pas affichée, mais elle est décomptée. Sur une question
  difficile, les plus avancés peuvent réfléchir plusieurs minutes avant le premier mot : la réponse
  n'est pas bloquée, et elle peut être longue.
- **Réponse reprise par un autre modèle** : la liste des actions au-dessus du message indique
  « Réponse reprise par un autre modèle après un refus » — votre modèle a décliné un sujet sensible —
  ou « Réponse d'un autre modèle : conservation des données requise » — votre organisation n'a pas
  accès au modèle choisi. L'étiquette nomme le modèle qui a réellement répondu, et chacun est
  décompté à son tarif.

## En cas de problème

### Un encadré ⚠️ remplace la réponse

Son titre dit la cause, son texte dit quoi faire. L'encadré est **rouge** quand l'éditeur du modèle
ne répond pas — réessayer ou changer de modèle suffit — et **ambre** quand quelque chose est à
régler : dans votre demande, ou sur l'instance.

| Titre de l'encadré | Ce que cela veut dire | Ce qu'il faut faire |
|---|---|---|
| **Service momentanément saturé** | L'éditeur reçoit trop de requêtes de l'organisation. | Réessayer dans un instant — l'encadré donne le délai quand l'éditeur l'annonce —, ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Service surchargé** | L'éditeur est débordé, pour tout le monde. Votre message n'y est pour rien. | Réessayer dans quelques instants, ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Service temporairement indisponible** | L'éditeur a un incident technique. Votre message n'y est pour rien. | Réessayer, ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Délai de réponse dépassé** | Le modèle a mis trop de temps et la demande a été interrompue. | Réessayer ; si cela se répète, raccourcir la demande ou choisir un modèle plus rapide. |
| **Connexion impossible** | MIA n'arrive pas à joindre l'éditeur. | Réessayer ; si cela persiste, prévenir un administrateur. |
| **Réponse vide** | Le modèle n'a rien renvoyé. | Reformuler, éventuellement avec un autre modèle. |
| **Crédit épuisé chez** l'éditeur | Le crédit de votre organisation chez cet éditeur est épuisé. **Ce n'est pas votre quota.** | Changer de modèle et prévenir un administrateur (bouton **Prévenir un administrateur** de l'encadré) : lui seul peut recharger ce crédit. |
| **Trop de contenu pour ce modèle** | La conversation, ses pièces jointes ou les documents consultés dépassent ce que le modèle peut lire en une fois. | Démarrer une nouvelle conversation, joindre un fichier plus court, ou choisir un modèle qui accepte plus de contenu. Réessayer à l'identique échoue pareil. |
| **Pièce jointe refusée** | Un fichier est trop lourd, illisible, ou d'un type que ce modèle ne lit pas. | Renvoyer le message dans une nouvelle conversation avec un fichier plus léger ou converti, ou changer de modèle. |
| **Limite de longueur atteinte** | Le modèle a consommé toute sa longueur de réponse à réfléchir, avant d'écrire. | Demander une réponse plus courte, découper la demande, ou changer de modèle. |
| **Demande bloquée par le filtre de sécurité** | Le filtre de l'éditeur a jugé la demande, ou sa réponse, sensible. | Reformuler, ou changer de modèle. |
| **Demande déclinée** | Le modèle a refusé ce sujet et aucun autre n'a pu reprendre. | Reformuler, ou changer de modèle : réessayer à l'identique donne le même résultat. |
| **Configuration requise** | Le modèle n'a pas de clé sur l'instance. | Prévenir un administrateur. |
| **Accès au service refusé** | La clé du modèle est refusée par l'éditeur. | Prévenir un administrateur. |
| **Accès au modèle refusé** | La clé de l'instance n'a pas le droit d'utiliser ce modèle. | Changer de modèle et prévenir un administrateur. |
| **Modèle indisponible** | Le modèle n'existe plus chez son éditeur, ou la clé n'y a pas accès. | Changer de modèle et prévenir un administrateur. |
| **Modèle indisponible pour votre organisation** | Le modèle exige un réglage que votre organisation n'a pas activé chez son éditeur. | Changer de modèle et prévenir un administrateur. |
| **Requête refusée** | L'éditeur a refusé la demande pour une autre raison : un réglage, un format, un contenu. | Essayer un autre modèle ; si cela se répète, prévenir un administrateur. |
| **Demande refusée par le serveur** — « Ce LLM n'est pas autorisé pour votre groupe. » | Le modèle n'est pas ouvert à votre groupe. | Choisir un autre modèle dans le sélecteur. |

L'encadré garde son titre quand vous rouvrez la conversation plus tard.

### La réponse s'arrête en route

Quand la réponse a déjà commencé, le texte écrit reste affiché et une ligne en italique dit pourquoi
il s'arrête là :

| Ligne sous la réponse | Ce qu'il faut faire |
|---|---|
| « Réponse interrompue : le modèle a atteint sa longueur maximale de réponse. » | Écrire **continue** : le modèle reprend où il s'était arrêté. |
| « Réponse interrompue : … est surchargé », « … a rencontré un incident technique », « … a cessé de répondre » | Cliquer **Regénérer**, ou changer de modèle. |
| « Réponse interrompue : le crédit de votre organisation chez … est épuisé » | Changer de modèle et prévenir un administrateur. |
| « Réponse interrompue : le filtre de sécurité de ce modèle a bloqué la suite » | Reformuler, ou changer de modèle. |
| « Réponse interrompue : la conversation dépasse ce que ce modèle peut traiter en une fois » | Démarrer une nouvelle conversation, ou choisir un modèle qui accepte plus de contenu. |

### Autres situations

| Ce qui arrive | Quoi faire |
|---|---|
| Le sélecteur de modèle a disparu | Vous êtes en conversation avec un assistant : il impose son modèle. |
| MIA ne voit pas l'image ou le PDF joint | Le bandeau indique **✗ Images** ou **✗ Fichiers** : choisir un autre modèle. |
| Un modèle attendu n'est pas dans la liste | Il n'est pas activé, ou pas autorisé pour votre groupe. Voir avec un administrateur. |
