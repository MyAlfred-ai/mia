---
title: Créer un assistant
description: Configurer un assistant — identité, consignes, modèle, documents, bases et outils — puis le tester et le faire évoluer.
sidebar:
  order: 2
---

Réservé aux comptes **Créateur** et aux administrateurs. Un assistant se crée en quelques minutes ;
ce qui fait sa qualité, ce sont ses consignes — voir
[Rédiger les consignes d'un assistant](rediger-les-consignes-d-un-assistant.md). Vous pouvez aussi
le décrire dans le chat et laisser MIA le composer — voir
[Faire créer un assistant par MIA](creer-un-assistant-avec-mia.md).

## Avant de commencer

- Le droit de créer des assistants — le rôle **Créateur**, par défaut. La tuile **Assistants**
  figure alors dans la rangée **Espaces** de la barre latérale. Sinon, demandez ce droit à un
  administrateur : il se règle par rôle.
- Une place libre : un créateur a droit à **10 assistants personnels**. Le compteur **N / 10** de la
  liste l'indique ; un assistant partagé par un administrateur ne compte plus.
- Les documents que l'assistant doit connaître, s'il y en a : 20 fichiers au plus, 20 Mo chacun.

## Étapes

1. Ouvrez la liste de vos assistants : la tuile **Assistants** de la barre latérale du chat, dans la
   rangée **Espaces**.
2. Cliquez **+ Nouvel assistant**. À droite, le **schéma de l'assistant** se compose au fil de la
   saisie — voir [Lire le schéma d'un assistant](#lire-le-schéma-dun-assistant).
3. **Identité** — renseignez :
   - **Nom** *(obligatoire)* : c'est lui qu'on tape après **@** ;
   - **Couleur** : celle du contour du champ de saisie et de la barre de la conversation ;
   - **Description** : une phrase affichée sous le nom, qui dit à quoi il sert. Elle n'est **pas**
     envoyée au modèle ;
   - **Avatar** : choisissez dans la bibliothèque, ou **＋ Nouveau** pour déposer une image (PNG,
     JPG, GIF ou WebP, 2 Mo au plus). L'image rejoint la bibliothèque commune.
4. **Comportement** — rédigez le **System prompt** *(obligatoire)* : ce que l'assistant est et doit
   faire. Le bloc **Partir d'un modèle** propose huit points de départ ; **✨ Rédiger avec l'IA**
   écrit une première version à partir de votre description.
5. Choisissez le **LLM par défaut** : le modèle que l'assistant imposera. La liste montre les modèles
   que vos groupes vous autorisent. **Défaut application** — le premier choix, qui nomme le modèle
   actuel — fait suivre à l'assistant le modèle par défaut de l'instance, y compris quand
   l'administrateur le change.
6. Réglez la **Température**, de 0 à 2 : basse pour des réponses constantes et factuelles, haute
   pour des réponses variées et créatives. Laissée vide, le modèle garde son réglage par défaut.
   Certains modèles ne la gèrent pas : le curseur est alors grisé. D'autres plafonnent plus bas
   que 2 : une valeur au-delà est ramenée à leur maximum, sans erreur.
7. **Capacités** — cochez les outils que l'assistant pourra appeler lui-même : génération d'image,
   automatisations n8n, présentations Gamma. Chaque bloc n'apparaît que si un outil de ce type
   existe sur l'instance. Avec un outil image, les **images** de ses documents de référence et de
   ses bases servent de modèles : il reprend le personnage, le produit ou le logo qu'elles montrent
   — voir [Faire dessiner un assistant d'après vos images](faire-dessiner-un-assistant-d-apres-vos-images.md).
8. **Connaissances** — cochez ses **Bases de connaissances** et sélectionnez ses **Documents de
   référence**.
9. Laissez l'interrupteur en haut sur **Actif**, puis cliquez **Créer**.

## Résultat

Le message « Assistant créé. » s'affiche, avec le nombre de documents ajoutés, et la liste s'ouvre
sur l'assistant, son schéma à droite. Il est **personnel** : vous seul le voyez dans le chat.

Pour l'essayer, cliquez **Utiliser** sur sa ligne, ou tapez **@** et son nom dans le chat.

## Lire le schéma d'un assistant

Le schéma montre d'un coup d'œil comment l'assistant est composé, avec les couleurs et les icônes du
schéma des tâches programmées :

- **au centre, l'assistant** : avatar, nom, description, début du prompt système, état — **Actif**
  ou **Inactif**, traits en pointillés — et qui le voit : **Personnel**, **Partagé** ou **Global** ;
- **à gauche, le modèle** qui répondra — **imposé à ses conversations**, ou **suit le modèle par
  défaut** de l'application — et la **température** envoyée : **non réglable** quand le modèle n'en
  accepte pas, **ignorée** si l'assistant en garde une d'un modèle précédent ;
- **en dessous**, un rectangle pour ses **bases de connaissances** (une base inactive y est
  signalée), un pour ses **documents de référence** (tokens, extraction en cours ou en échec) et un
  pour ses **outils**. Au-delà de cinq éléments, le rectangle dit combien il en reste.

Dans la liste, **cliquer un assistant** affiche son schéma à droite ; sur un écran étroit, il se
déplie sous la ligne, et un second clic le replie. Sur la fiche, le schéma suit chaque geste, reste
en vue pendant que vous faites défiler, et chacun de ses éléments mène au réglage correspondant :
**Identité**, **Comportement**, **Connaissances** ou l'interrupteur **Actif**. Il n'enregistre rien.

## Documents de référence ou base de connaissances ?

| | Documents de référence | Base de connaissances |
|---|---|---|
| Rattachés à | Cet assistant seul | Autant d'assistants que voulu, et au chat |
| Recherche | Par les mots | Par le sens |
| Images | Vues par les modèles « vision », trois au plus — utiles pour une charte ou un logo ; modèles pour l'outil image | Décrites puis indexées ; modèles pour l'outil image |
| Limite | 20 fichiers | 20 fichiers déposés, ou un dossier synchronisé |

Une consigne courte et stable — un règlement, un glossaire — va en document de référence. Une
documentation qui vit, ou qui sert à plusieurs assistants, va en base :
[Créer une base de connaissances](../bases-de-connaissances/creer-une-base-de-connaissances.md).

## Faire évoluer l'assistant

- **Modifier** : **Modifier** sur sa ligne ou dans son schéma, ou **Modifier** dans ses
  caractéristiques depuis le chat. Les changements s'enregistrent seuls — « Assistant mis à jour. » — et valent **tout de
  suite, pour tous ses utilisateurs**, conversations en cours comprises.
- **Ajouter un document** après coup : section **Documents de référence**, **Ajouter un document**
  puis **Uploader**. Le texte est extrait, puis compté en tokens.
- **Désactiver** : interrupteur **Inactif** en haut de la fiche. Plus personne ne le voit, vous non
  plus, jusqu'à ce que vous le réactiviez.
- **Supprimer** : menu **⋯** de la liste, ou **Supprimer cet assistant** en bas de la fiche.
  **Irréversible** : ses conversations redeviennent des conversations standard.

Il n'y a pas de bouton pour dupliquer un assistant.

## Le faire partager à l'organisation

Un créateur ne partage pas lui-même. Demandez à un administrateur de le **partager** et de
l'**autoriser** dans les groupes voulus. Une fois partagé, vous gardez le droit de le modifier, de
le désactiver et de le supprimer.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Limite atteinte : vous ne pouvez pas dépasser 10 assistants personnels. » | Supprimez un assistant, ou demandez à un administrateur de partager l'un des vôtres. |
| « Nom : valeur incomplète, rien n'a été enregistré. » | Le nom ou le prompt a été vidé. Remplissez-le : l'enregistrement reprend. |
| « Fichier « X » invalide (taille max 20 Mo). » | Réduisez ou découpez le fichier. |
| « Limite atteinte : 20 documents maximum par assistant. » | Retirez un document, ou passez par une base de connaissances. |
| « Avatar invalide : taille maximale 2 Mo. » | Réduisez l'image. |
| Un document reste en « ⚠ échec de l'extraction » | Le fichier ne contient pas de texte lisible — un PDF scanné, par exemple. |
| L'outil coché n'est jamais appelé | Les outils ne fonctionnent qu'avec certains modèles, et doivent aussi être autorisés dans les groupes de l'utilisateur. Voir [Les outils](../outils/index.md). |
| **✨ Rédiger avec l'IA** est absent | Aucun modèle par défaut n'est disponible sur l'instance. Voir avec un administrateur. |
