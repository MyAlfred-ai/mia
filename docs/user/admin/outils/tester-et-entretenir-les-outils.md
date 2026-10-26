---
title: Tester et entretenir les outils
description: Tester les outils, lire leur carte, les modifier, les suspendre ou les supprimer, et régler ce qui les fait suggérer.
sidebar:
  order: 2
---

**Administration → Capacités → Outils.** Une carte par outil, avec son état, son dernier test et ses actions.

## Lire une carte

- l'interrupteur **Actif** / **Inactif** (« Masqué du chat »), enregistré aussitôt — ou le badge
  **Clé requise** si un prérequis manque. Sous **Actif**, le nombre de groupes actifs qui
  autorisent l'outil : « 2 groupes », ou « Aucun groupe — admins seuls » ;
- le **dernier test** : **OK** ou **Erreur**, sa date et son message ;
- quand le modèle de l'outil est arrêté par son fournisseur : l'avertissement « Arrêt annoncé le … »
  avant la date, puis le badge **Hors service** — voir plus bas ;
- les **Expressions déclencheuses** — sauf pour n8n et Gamma ;
- les boutons **Tester**, **Modifier** et la corbeille.

**Actif** met l'outil en service ; il n'est proposé qu'aux membres des groupes qui l'autorisent, et
aux administrateurs. Une carte active autorisée dans aucun groupe porte l'avertissement « Autorisé
dans aucun groupe : seuls les administrateurs le voient. » Voir
[Organiser les groupes](../utilisateurs/organiser-les-groupes.md). Seules la **Dictée vocale** et la
**Conversation vocale**, réglages globaux, valent pour tous les utilisateurs.

## Tester

**Tester** sur une carte, ou **Tester tous les outils** en haut de page — y compris les outils
inactifs. Le résultat s'affiche au fil de l'eau : « Test en cours... », puis « OK (N ms) » ou l'erreur,
et au global « x/y OK, z en échec ».

Un test fait une **vraie génération** : une image, une vidéo, une page, un plan de deux slides. Il est
décompté **hors quota**, sur votre compte. Pour n8n, aucun workflow n'est déclenché ; pour Gamma,
aucun crédit Gamma n'est consommé.

**Un test en échec grise l'outil** chez les utilisateurs et le retire des assistants, jusqu'au
prochain test réussi. **Aucun test ne se lance tout seul** : testez après chaque modification, chaque
changement de clé, chaque mise à jour de MIA.

## Modifier

**Modifier** ouvre la fiche : tout se modifie sauf le type, et s'enregistre au fil de l'eau. Pour une
image, on peut y figer la **Taille** et la **Qualité** des images produites.

Les deux listes dépendent du **modèle** choisi : chaque modèle accepte ses propres formats et ses
propres niveaux de rendu, et changer de modèle les met à jour. Laissées sur **Automatique (défaut du
modèle)**, elles sont décidées à chaque génération. Si une valeur enregistrée n'existe pas pour le
nouveau modèle, elle reste affichée et signalée « non supportée par ce modèle » : choisissez-en une
autre, sinon les générations repartiront sur le réglage du modèle.

**Enregistrer une modification efface le résultat du dernier test** : retestez.

Les consignes — **System prompt** — des pages, sites, cartes et présentations sont livrées avec MIA et
modifiables. Vider le champ revient au texte d'origine.

## Un modèle arrêté par son fournisseur

Les fournisseurs retirent régulièrement des modèles, parfois tout un service. MIA connaît les arrêts
annoncés et les applique le jour dit, sans rien supprimer :

- **Avant la date** : la carte porte l'avertissement « Arrêt annoncé le … », avec un lien vers
  l'annonce du fournisseur. **Maintenance → Diagnostic** le signale aussi.
- **À partir de la date** : badge **Hors service**. L'outil reste dans le menu **+** des
  utilisateurs, mais grisé, avec la mention « Arrêté par son fournisseur le … — prévenir un
  administrateur ». Plus aucune demande ne part chez le fournisseur ; les assistants, les sites
  illustrés et les présentations cessent de s'en servir.
- Le modèle n'est plus proposé dans **Ajouter un outil**. Un type dont plus aucun modèle n'est en
  service y reste visible, mais grisé.

**Quoi faire** : **Modifier** l'outil et lui choisir un autre modèle — il est remis en service
aussitôt ; testez-le. Si le fournisseur n'en propose plus aucun, passez l'outil en **Inactif**. Un
test lancé sur un outil hors service échoue sans rien générer : il ne coûte rien.

## Régler les suggestions

Le bloc **Expressions déclencheuses** liste les tournures qui font proposer l'outil dans le chat —
*« une affiche »*, *« un logo »* pour une image. La liste est commune à tous les outils du même type.

- Une expression par ligne ; les mots doivent apparaître dans l'ordre, dans la même phrase.
- Une liste vide supprime la suggestion pour ce type d'outil.
- **Réinitialiser** rétablit la liste livrée avec MIA.

## Suspendre, supprimer

- **Inactif** : l'outil disparaît du chat, sans rien perdre. À préférer pour une suspension.
- **Supprimer** (corbeille) : « Supprimer cet outil ? ». Ses autorisations de groupe et ses liens aux
  assistants disparaissent avec lui, et son historique de consommation aussi. **Irréversible.**

## En cas de problème

| Ce que dit le test | Quoi faire |
|---|---|
| Erreur d'authentification | Vérifier la clé de l'éditeur dans **Modèles IA**, ou la clé dédiée de l'outil. |
| Modèle introuvable | Le modèle a été retiré par son éditeur : en choisir un autre dans **Modifier**. |
| « Modèle arrêté par son fournisseur le … » | Arrêt connu de MIA : choisir un autre modèle dans **Modifier**, ou passer l'outil en **Inactif**. |
| Délai dépassé | Relancer : les vidéos peuvent demander plusieurs minutes. |
| Un test déjà en cours bloque | Une seule campagne de tests à la fois ; une campagne bloquée est déclarée en échec au bout de 15 minutes. |
