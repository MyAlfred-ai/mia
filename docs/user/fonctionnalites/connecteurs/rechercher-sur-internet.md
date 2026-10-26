---
title: Rechercher sur Internet
description: Laisser MIA vérifier une information en ligne avant de répondre, ou l'en empêcher.
sidebar:
  order: 4
---

La **Recherche Internet** est un connecteur sans compte à relier : MIA cherche sur le web public avant
de répondre, quand votre question le demande — actualité, chiffre récent, texte officiel. Elle sait
aussi **lire une page précise** dont vous collez l'adresse.

## Avant de commencer

Le modèle de la conversation sait chercher : le bandeau affiche **✓ Internet**. Sinon, choisissez un
autre modèle — voir [Choisir un modèle](../modeles/choisir-un-modele.md).

## Étapes

1. Ouvrez le menu **+**, section **Sources** : la ligne **Recherche Internet** est allumée par
   défaut.
2. Posez votre question. MIA décide elle-même de chercher, quand sa réponse le demande.

## Résultat

La ligne **Recherche sur Internet** apparaît dans la pile d'actions au-dessus de la réponse, qui cite
ses sources. Certains modèles spécialisés ouvrent aussi un panneau de sources.

## Lire une page précise

Collez l'adresse dans votre message : *« résume https://exemple.fr/article »*. MIA lit la page — ou le
PDF en ligne — et répond d'après son contenu réel ; la ligne **Lecture d'une page web** l'indique dans
la pile d'actions. Une très longue page se lit partie par partie.

MIA ne lit que les **pages publiques** : une page qui demande une connexion, une adresse de réseau
interne (intranet, serveur de l'entreprise) ou un fichier de plus de 5 Mo est refusé, et MIA vous le
dit. Éteindre la Recherche Internet éteint aussi la lecture de pages.

## Cas particuliers

- **Éteindre la recherche** : décochez la ligne dans le menu **+**. Le point ambre du bouton **+**
  rappelle qu'elle est éteinte. Utile pour une question où seul le contenu de vos documents compte.
- **Un modèle qui ne sait pas chercher** le dit : il indique qu'il n'a pas pu vérifier en ligne,
  plutôt que d'inventer.
- **Regénérer** une réponse ne relance pas la recherche.
- **Le coût** : chaque recherche réellement effectuée est décomptée à part, en plus de la réponse.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| La ligne **Recherche Internet** n'apparaît pas | Votre administrateur l'a masquée pour l'organisation. |
| MIA répond sans chercher une information récente | Vérifier que la ligne est allumée et que le bandeau affiche **✓ Internet** ; sinon changer de modèle. |
| MIA refuse de lire une adresse | La page est privée, interne ou trop lourde : copiez son texte dans le message, ou joignez le fichier. |
