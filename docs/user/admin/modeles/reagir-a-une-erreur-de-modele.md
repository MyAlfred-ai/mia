---
title: Réagir à une erreur de modèle
description: Ce que lit un utilisateur quand un modèle échoue, ce que chaque encadré veut dire et ce que l'administrateur a à faire.
sidebar:
  order: 5
---

Quand un modèle échoue, la conversation le dit : un encadré ⚠️ dont le **titre nomme la cause**. C'est
ce titre que l'utilisateur vous rapportera. Cette page dit lesquels appellent un geste de votre part.

## Ce que voit l'utilisateur

- **Avant le premier mot** : un encadré remplace la réponse. Rouge quand l'éditeur ne répond pas,
  ambre quand quelque chose est à régler.
- **En cours de réponse** : le texte déjà écrit reste, suivi d'une ligne « Réponse interrompue : … »
  qui donne la raison.
- **Si un LLM de secours est réglé** et que la panne vient de l'éditeur : le secours répond, un
  message « Bascule sur le LLM de secours : A → B » donne la raison, et la liste des actions de la
  réponse en garde la trace.

Le message exact de l'éditeur n'est jamais montré aux utilisateurs : il peut contenir un fragment de
clé ou un identifiant de compte.

Quand la cause se règle dans l'administration — crédit épuisé, clé refusée, modèle retiré, incident
qui dure —, l'encadré porte un bouton **Prévenir un administrateur**. Il déplie la liste des
administrateurs actifs, nom et adresse, chacun ouvrant un courriel déjà rédigé (le titre et le texte de
l'encadré, la date) ; **Copier le message** sert à le transmettre par une messagerie. Vous-même, vous
voyez à la place un lien vers la page où régler la cause : **Ouvrir les clés API** ou **Ouvrir les
modèles**. La dictée et la lecture à voix haute disent les mêmes causes, dans une notification en bas
de l'écran.

## Le crédit épuisé vous est signalé

Un éditeur qui refuse les demandes faute de crédit, ou dont la clé est refusée, allume un point rouge
sur **Modèles IA › Clés API** dans le menu, et une alerte sur le tableau de bord : depuis combien de
temps, et combien de demandes ont été refusées. Vous n'attendez plus qu'un utilisateur vous l'écrive —
ni que quelqu'un remarque que le LLM de secours répond à la place du modèle habituel. Le point
s'éteint de lui-même dès que l'éditeur répond de nouveau (une réponse aboutie, un **Tester** réussi,
une clé enregistrée), ou après trois jours sans nouveau refus.

## Les encadrés qui vous concernent

| Titre de l'encadré | Cause | Ce que vous avez à faire |
|---|---|---|
| **Crédit épuisé chez** l'éditeur | Le compte de l'instance chez cet éditeur n'a plus de crédit : solde épuisé, plafond de dépense mensuel ou quota atteint, facturation inactive. | Recharger le compte ou relever son plafond, dans la console de l'éditeur, puis **Tester** la clé. |
| **Configuration requise** | Aucune clé n'est enregistrée pour cet éditeur. | [Enregistrer la clé](enregistrer-les-cles-api.md), ou retirer le modèle. |
| **Accès au service refusé** | La clé est invalide, expirée ou révoquée. | Remplacer la clé, puis **Tester**. |
| **Accès au modèle refusé** | La clé est valide mais n'a pas le droit d'utiliser ce modèle : modèle non ouvert au compte, organisation à vérifier chez l'éditeur, région non prise en charge. | Ouvrir l'accès chez l'éditeur, ou [retirer le modèle](activer-les-modeles.md). |
| **Modèle indisponible** | L'éditeur ne connaît plus ce modèle : retiré, renommé, ou fermé à la clé. | Activer son successeur, basculer le défaut et les assistants qui l'imposaient, puis retirer l'ancien. |
| **Modèle indisponible pour votre organisation** | Le modèle exige la conservation des données chez l'éditeur. | Voir [Activer les modèles](activer-les-modeles.md). |
| **Requête refusée** | L'éditeur refuse un réglage envoyé par MIA. | Lire le message de l'éditeur dans les journaux. |

## Les encadrés qui ne demandent rien

| Titre de l'encadré | Ce qui se passe |
|---|---|
| **Service surchargé**, **Service temporairement indisponible**, **Service momentanément saturé**, **Délai de réponse dépassé**, **Connexion impossible** | L'éditeur ne répond pas. Le LLM de secours prend le relais s'il est réglé ; sinon l'utilisateur réessaie ou change de modèle. |
| **Trop de contenu pour ce modèle**, **Pièce jointe refusée**, **Limite de longueur atteinte**, **Demande bloquée par le filtre de sécurité**, **Demande déclinée** | La demande est à reprendre par l'utilisateur : l'encadré lui dit comment. Le secours n'intervient pas, un autre modèle refuserait pareil. |

## Retrouver le message de l'éditeur

**Administration → Maintenance → Diagnostic → Journaux.** Chaque échec y laisse une ligne au nom de
l'éditeur, avec le statut, le code de l'erreur et le message d'origine. Voir
[Consulter les journaux et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md).

## Cas particuliers

- **Un éditeur sans crédit déclenche le secours**, comme une panne : pour l'instance, l'éditeur est
  indisponible. Les utilisateurs continuent de recevoir des réponses ; le point rouge de **Clés API**
  vous alerte quand même.
- **Un plafond de dépense mensuel atteint** se lit comme un crédit épuisé, même quand l'éditeur le
  signale comme une limite de débit (c'est le cas de Claude) : attendre quelques secondes n'y change
  rien, l'accès revient au début du mois suivant ou quand le plafond est relevé.
- **Une mention « longueur maximale de réponse » qui revient souvent** sur un modèle signale que son
  plafond de sortie est trop bas pour l'usage qui en est fait. Chez les modèles qui raisonnent, la
  réflexion compte dans ce plafond. Les modèles Claude disposent d'une marge large, réflexion
  comprise : chez eux, la mention signale plutôt une demande démesurée — une page web ou une
  présentation énorme — à découper.
- **Une page web ou une présentation coupée** le dit aussi : encadré **Page incomplète** sous la
  page, **Présentation trop longue pour ce modèle** pour une présentation.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Des utilisateurs signalent **Crédit épuisé**, d'autres non | Seuls les modèles de cet éditeur sont touchés : recharger son compte. |
| Le point rouge de **Clés API** reste allumé après la recharge | Cliquez **Tester** sur la clé de l'éditeur : un test réussi l'éteint. |
| Un utilisateur dit ne pas savoir à qui écrire | Le bouton **Prévenir un administrateur** liste les comptes administrateurs actifs : vérifiez qu'ils ont une adresse valide dans **Personnes › Comptes**. |
| Le secours répond à tout le monde depuis des heures | L'éditeur du modèle par défaut est en panne ou à sec : la raison est dans le message de bascule et dans les journaux. |
| Un encadré **Une erreur est survenue** sans cause nommée | L'éditeur a renvoyé une erreur que MIA ne sait pas classer : le message d'origine est dans les journaux. |
