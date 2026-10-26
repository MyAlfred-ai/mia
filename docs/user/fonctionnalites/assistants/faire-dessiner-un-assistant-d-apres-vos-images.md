---
title: Faire dessiner un assistant d'après vos images
description: Donner à un assistant vos images de référence — personnage, produit, logo — pour qu'il les reprenne fidèlement dans les images qu'il génère.
sidebar:
  order: 5
---

Réservé aux comptes **Créateur** et aux administrateurs. Un assistant qui génère des images peut
partir de **vos** images : votre mascotte, votre produit, votre logo, vos locaux. Il les transmet au
moteur d'images, qui les **voit** et les reprend fidèlement — au lieu d'en inventer une version à
partir d'une description. Ce qu'est un assistant : [Les assistants](index.md).

## Avant de commencer

- Le droit de créer ou de modifier des assistants — le rôle **Créateur** ou administrateur, par
  défaut —, et un assistant à créer ou à modifier — voir
  [Créer un assistant](creer-un-assistant.md).
- Un **outil image qui sait retoucher une image**, autorisé pour les groupes de vos utilisateurs.
  Tous les moteurs ne le savent pas : la description du modèle de l'outil l'indique. En cas de
  doute, voyez avec votre administrateur.
- Vos images en **JPG, PNG, WebP ou GIF**, avec des **noms parlants** — *mascotte-face.png*,
  *logo-couleur.png* plutôt que *IMG_0423.jpg* : c'est par leur nom qu'on les désigne.

## Étapes

1. Ouvrez la fiche de l'assistant.
2. **Comportement → Capacités — génération d'image** : cochez l'outil image.
3. Déposez vos images à l'un de ces deux endroits :
   - **Connaissances → Documents de référence** : quelques images propres à cet assistant. La
     **première** déposée devient son point de départ quand rien d'autre n'est demandé ;
   - une **base de connaissances** cochée dans **Connaissances** : beaucoup d'images, partagées
     entre plusieurs assistants ou tenues à jour depuis un dossier synchronisé. Chaque image y est
     décrite par l'IA, ce qui permet de la retrouver par ce qu'elle montre — voir
     [Créer une base de connaissances](../bases-de-connaissances/creer-une-base-de-connaissances.md).
4. Si l'assistant doit toujours partir des mêmes images, dites-le dans son prompt système :
   *« Pour toute illustration, reprends le personnage de mascotte-face.png ; ajoute
   logo-couleur.png quand le logo doit apparaître. »*
5. Essayez-le : dans une conversation avec l'assistant, demandez *« notre mascotte qui fait du
   ski »*.

## Résultat

L'image apparaît dans la réponse. Juste au-dessus, la ligne **Génération d'une image — D'après
« … »** nomme les images de référence que l'assistant a transmises.

À chaque demande d'image, l'assistant voit la liste des images disponibles — ses documents, ses
bases, et les bases que l'utilisateur a cochées sous le champ de saisie —, avec leur nom et leur
description. Il choisit celles que la demande vise, **4 au plus** par image, en plus de l'image
qu'il retouche s'il y en a une. L'outil **Image** du menu **+** fait de même dans une conversation
avec l'assistant.

## Cas particuliers

- **Le moteur redessine.** La ressemblance est forte — forme, couleurs, détails —, jamais identique
  au pixel près. Pour l'image d'origine, sans changement, il faut le fichier lui-même.
- **Au-delà de 25 images**, l'assistant voit d'abord les plus proches de la demande et cherche les
  autres par leur nom ou leur description.
- **Sans image nommée**, et tant que la conversation n'en contient aucune, la première image des
  documents de référence sert de point de départ — jamais une image de base prise au hasard.
- **Une très grande image** est réduite à 2 048 pixels de côté avant l'envoi ; un GIF est converti.
- Les **sites illustrés** et les **présentations** ne reprennent pas encore ces images.
- Chaque image transmise compte dans le coût de l'image générée : quatre références coûtent un peu
  plus qu'une image seule.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Aucune ligne « D'après … » sous l'image | Nommer l'image voulue dans la demande. Vérifier que l'image est dans les documents de l'assistant, ou dans une base liée ou cochée. Si rien n'y fait, l'outil image ne sait pas retoucher : voir avec un administrateur. |
| L'assistant reprend la mauvaise image | Donner aux fichiers des noms distincts et parlants, puis nommer l'image voulue. |
| L'assistant dit qu'une image ne peut pas être transmise — « trop lourde », « format non pris en charge » | Alléger l'image ou l'enregistrer en JPG ou PNG ; demander moins de références à la fois. |
| Une image de la base n'est jamais proposée | Vérifier que la base est active et que l'image n'est plus en cours de traitement, dans la fiche de la base. |
