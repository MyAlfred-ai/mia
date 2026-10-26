---
title: Générer une vidéo
description: Produire une courte vidéo à partir d'une description, avec sa durée, son format et une image de référence.
sidebar:
  order: 2
---

Un outil vidéo produit une séquence de quelques secondes à partir de votre description. Ce qu'est un
outil : [Les outils](index.md).

## Étapes

1. Cliquez le bouton **+**, section **Créer**, puis choisissez un outil **vidéo**.
2. Décrivez la scène : sujet, mouvement, ambiance, cadrage.
3. Envoyez. La fenêtre **Paramètres de la vidéo** s'ouvre :

| Paramètre | Choix |
|---|---|
| **API fournisseur** | En lecture seule : qui fabrique la vidéo |
| **Modèle** | Les modèles vidéo proposés par l'outil |
| **Durée** | 4, 8 ou 12 secondes |
| **Taille** | 720×1280 portrait *(par défaut)*, 1280×720 paysage, 1024×1792 ou 1792×1024 |
| **Format de sortie** | MP4 |
| **Image de référence** | *Aucune*, *Image de début* ou *Image de fin* — à partir de l'image jointe |

4. Cliquez **Générer**. Le message « 🎬 Génération vidéo en cours… » s'affiche. **Annuler** referme la
   fenêtre sans rien perdre : votre texte et l'outil restent en place.

## Résultat

La vidéo s'affiche dans un lecteur, avec **Ouvrir**, **Télécharger** et **Partager**. Comptez
plusieurs minutes : la génération peut durer jusqu'à six minutes. Vous pouvez changer de conversation
pendant ce temps.

## Cas particuliers

- **Le coût est à la seconde** : une vidéo de 12 secondes coûte trois fois celle de 4 secondes.
- **Quand un fournisseur arrête son modèle**, l'outil vidéo reste dans le menu, grisé, avec la date
  de l'arrêt sous son nom : il ne peut plus rien produire.
- Pour revenir aux réponses en texte, cliquez la **croix** de la pastille d'outil.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| L'outil est grisé : « Arrêté par son fournisseur le … » | Le modèle n'existe plus chez son fournisseur. Prévenir un administrateur. |
| L'outil est grisé dans le menu | Son dernier test a échoué. Prévenir un administrateur. |
| « Clé API … non configurée. » | Le fournisseur vidéo n'a pas de clé sur l'instance. Prévenir un administrateur. |
