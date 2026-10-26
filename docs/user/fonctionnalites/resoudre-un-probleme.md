---
title: Résoudre un problème
description: Les situations les plus fréquentes, ce qu'elles veulent dire et ce qu'il faut faire.
sidebar:
  order: 21
---

Les situations les plus fréquentes, classées par sujet. Chaque page de tâche a aussi son propre
tableau « En cas de problème ».

## Je ne peux plus envoyer de message

Votre quota est probablement atteint : la carte de votre compte, en bas de la barre latérale, affiche **Quota épuisé**
et une fenêtre **Quota mensuel atteint** s'ouvre. Attendez le 1er du mois, ou demandez une
augmentation à un administrateur. Si c'est **Service IA suspendu** qui s'affiche, c'est le budget
IA de votre organisation qui est atteint, pour tout le monde : le service reprend le 1er du mois.
Voir [Suivre sa consommation](credits/suivre-sa-consommation.md).

## Ma réponse affiche un encadré ⚠️

Le titre de l'encadré dit la cause, son texte dit quoi faire. Les plus fréquents :

- **Service surchargé**, **Service temporairement indisponible**, **Service momentanément saturé** :
  l'éditeur du modèle ne répond pas, votre message n'y est pour rien. Réessayez, ou changez de modèle.
- **Crédit épuisé chez** l'éditeur : le crédit de votre organisation chez cet éditeur est épuisé. Ce
  n'est **pas** votre quota, ni votre message. Changez de modèle et prévenez un administrateur.
- **Trop de contenu pour ce modèle** : un fichier joint, ou le message lui-même, est trop volumineux
  pour ce modèle (une longue conversation, elle, se résume d'elle-même). Découpez le fichier, ou
  choisissez un modèle qui accepte plus de contenu.
- **Modèle indisponible**, **Configuration requise**, **Accès au service refusé** : le réglage de
  l'instance est en cause. Changez de modèle et prévenez un administrateur.

Quand un administrateur doit intervenir, l'encadré porte un bouton **Prévenir un administrateur** : il
déplie la liste des administrateurs, et chaque nom ouvre un courriel déjà rédigé avec le message
affiché. **Copier le message** le met dans le presse-papiers, pour l'envoyer par une messagerie.

Le tableau complet est dans [Choisir un modèle](modeles/choisir-un-modele.md). Changer de modèle
règle la plupart des situations.

## « Génération interrompue » s'affiche à la place de la réponse

Le serveur qui rédigeait la réponse s'est arrêté avant la fin — un redémarrage ou une mise à jour de
MIA, le plus souvent. Rien n'a été perdu d'autre que cette réponse : renvoyez votre message. Le
message reste dans la conversation, pour que vous le retrouviez si vous étiez ailleurs à ce moment-là.

## Ma réponse s'arrête au milieu d'une phrase

Une ligne en italique sous le texte dit pourquoi : « Réponse interrompue : … ». Si le modèle a atteint
sa longueur maximale, écrivez **continue** : il reprend où il s'était arrêté. Si c'est l'éditeur qui a
lâché en route — surcharge, incident —, cliquez **Regénérer**. Voir
[Choisir un modèle](modeles/choisir-un-modele.md).

## « Analyse de votre demande… » reste affiché longtemps

Certains modèles réfléchissent avant d'écrire — les plus avancés, parfois plusieurs minutes sur une
question difficile. MIA le sait : la réponse n'est pas considérée comme bloquée tant que le modèle
travaille. Si l'attente ne vous convient pas, cliquez **Arrêter la génération**, puis renvoyez le
message avec un modèle plus rapide.

## La réponse vient d'un autre modèle que celui choisi

C'est voulu. Le modèle choisi a décliné un sujet sensible, ou votre organisation n'y a pas accès :
MIA a fait reprendre la demande par un autre modèle. La liste des actions au-dessus du message le
dit, et l'étiquette sous la réponse nomme le modèle qui l'a rédigée. Voir
[Choisir un modèle](modeles/choisir-un-modele.md).

## MIA ne lit pas mon image ou mon PDF

Le modèle choisi ne lit pas ce type de fichier : le bandeau affiche **✗ Images** ou **✗ Fichiers**.
Choisissez un modèle qui les lit. Voir [Joindre des fichiers](conversations/joindre-des-fichiers.md).

## Je ne trouve pas le sélecteur de modèle

Il n'existe qu'en conversation **standard**. Avec un assistant, le modèle est imposé et le sélecteur
disparaît. Voir [Discuter avec un assistant](assistants/discuter-avec-un-assistant.md).

## Un assistant a disparu, ou une ancienne conversation a perdu le sien

L'assistant a été désactivé, retiré de vos groupes ou supprimé : la conversation continue en mode
standard. Voir avec un administrateur.

## Un outil est grisé dans le menu

Son dernier test a échoué. Signalez-le à un administrateur. Voir [Les outils](outils/index.md).

## MIA n'accède pas à mes fichiers ou à mes mails

Dans l'ordre, vérifiez que :

1. le connecteur est **allumé** dans le menu **+** de la conversation ;
2. le bandeau affiche **✓ Connecteurs** — sinon, changez de modèle ;
3. la page **Réglages → Comptes connectés** montre le service **Connecté**, sans badge à tiret ;
4. vous avez les droits sur le fichier, côté Google ou Microsoft.

Voir [Utiliser un connecteur](connecteurs/utiliser-un-connecteur.md).

## Je ne vois pas la recherche Internet, un connecteur, la dictée ou la conversation vocale

Votre administrateur décide de ce qui est proposé à l'organisation. Une ligne absente du menu **+**,
un micro ou un bouton onde sonore absent du champ de saisie, correspond à un service masqué ou non
configuré sur votre instance.

## « Votre rôle ne vous donne pas accès à cette action »

Chaque compte porte un **rôle**, et le rôle décide de ce que vous pouvez faire : relier vos comptes,
programmer des tâches, créer des assistants, ouvrir des pages de l'administration… Une page ou un
bouton absent, ou ce message, signifie que votre rôle ne le permet pas. Demandez ce droit à un
administrateur : il se règle pour tout un rôle, pas compte par compte.

## Une base de connaissances est grisée

Ses documents ne sont pas encore indexés, ou ne contiennent pas de texte lisible. La raison est
écrite sous son nom. Voir
[Interroger ses bases de connaissances](bases-de-connaissances/interroger-ses-bases-de-connaissances.md).

## Une conversation ou une création a disparu

Votre administrateur peut élaguer les conversations anciennes et les créations anciennes pour libérer
de la place. **Épinglez** les conversations que vous voulez conserver, et téléchargez les créations
qui comptent. Voir [Gérer ses conversations](conversations/gerer-ses-conversations.md).

## Ma session a expiré

MIA le détecte d'elle-même et affiche une fenêtre avec **Se reconnecter**. **Le message en cours
d'écriture n'est pas perdu** : il est restauré après reconnexion.

## Puis-je envoyer une image sans texte ?

Oui. Ajouter une consigne reste préférable pour préciser le résultat attendu.

## Une question qui n'est pas ici

Posez-la à l'assistant **MIA : mode d'emploi**, proposé à tout le monde dans la liste des assistants.

La page **À propos** (**À propos de MIA**, en bas du menu de vos réglages, ou lien sous la conversation) résume ce que MIA sait faire et renvoie
vers cette documentation, vers le formulaire **Déclarer une idée ou un bug** et vers les **Nouveautés** de la
version installée.
