---
title: Générer une image
description: Créer une image à partir d'une description, puis l'ajuster message après message.
sidebar:
  order: 1
---

Un outil image fabrique une image à partir de votre description. Ce qu'est un outil, et comment il
engage la conversation : [Les outils](index.md).

## Étapes

1. Cliquez le bouton **+** sous le champ de saisie, section **Créer**, puis choisissez un outil
   **image**. Vous pouvez aussi accepter la suggestion **Utiliser …** qui s'affiche quand votre
   message décrit une image.
2. Décrivez l'image voulue : sujet, style, cadrage, couleurs. Vous pouvez joindre une image de
   départ.
3. Envoyez. Le message « 🎨 Génération en cours… (15–30 s) » s'affiche.

## Résultat

L'image apparaît avec ses actions : **Copier**, **Regénérer**, **Télécharger l'image** et
**Partager**.

Le format — carré, portrait, paysage — et la qualité sont réglés par votre administrateur sur
l'outil. Pour un autre format, demandez-le dans votre description : le moteur en tient compte s'il
le peut.

## Ajuster sans tout recommencer

Demandez une correction — *« mets-lui un chapeau »*, *« en bleu nuit »*, *« la même en format
paysage »* — et MIA **repart de la dernière image** au lieu d'en créer une nouvelle. Seul le
changement demandé est appliqué.

Pour repartir de zéro, formulez une **nouvelle** demande : *« génère une nouvelle image de… »*.

La conversation est prise en compte : après avoir discuté d'une affiche, *« fais-en une version
verticale »* suffit. Avec un assistant, sa personnalité et ses documents — une charte graphique,
un logo — guident aussi l'image.

## Reprendre les images d'un assistant ou d'une base

Les images déposées dans les **documents de référence** d'un assistant, ou dans une **base de
connaissances** de la conversation — celles de l'assistant et celles que vous cochez sous le champ
de saisie —, servent de **modèles**. Nommez-les, ou décrivez ce qu'elles montrent : *« notre mascotte
qui fait du ski »*, *« le flacon de la photo produit, sur fond de plage »*. MIA choisit les images
visées et les transmet au moteur avec votre demande : il les **voit**, et reprend fidèlement le
personnage, le produit ou le logo.

- Jusqu'à **4 images** de référence par image, en plus de celle que vous ajustez.
- Sous l'image, la ligne **Génération d'une image — D'après « … »** nomme celles qui ont servi.
- Le moteur **redessine** : la ressemblance est forte, jamais identique au pixel près. Pour l'image
  elle-même, sans changement, ouvrez-la dans la base ou dans les documents de l'assistant.
- Un assistant pensé autour d'une image — un personnage, une charte — part de la **première image de
  ses documents** quand vous n'en nommez aucune.
- Pour préparer un tel assistant *(rôle Créateur)* :
  [Faire dessiner un assistant d'après vos images](../assistants/faire-dessiner-un-assistant-d-apres-vos-images.md).

## Cas particuliers

- **Tous les moteurs ne savent pas retoucher.** Certains repartent toujours de zéro et ignorent
  l'image jointe, sans message. Si chaque demande produit une image sans lien avec la précédente,
  c'est le moteur de l'outil : voyez avec votre administrateur.
- **Regénérer**, sous une image, en produit une autre version : même demande, même outil. La
  nouvelle image remplace l'ancienne. Si l'outil qui l'avait créée n'est plus disponible, l'image
  reste en place et un message vous invite à choisir un autre outil image dans le menu **+**.
- Pour revenir aux réponses en texte, cliquez la **croix** de la pastille d'outil.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| L'outil est grisé : « Arrêté par son fournisseur le … » | Le modèle n'existe plus chez son fournisseur. Choisir un autre outil image dans le menu **+**, et prévenir un administrateur. |
| L'outil est grisé dans le menu | Son dernier test a échoué. Prévenir un administrateur. |
| « Clé API … non configurée. » | Le fournisseur de l'outil n'a pas de clé sur l'instance. Prévenir un administrateur. |
| « Le compte … de l'instance n'a plus de crédit disponible… » | Le compte de l'organisation chez ce fournisseur est à sec — ce n'est pas votre quota. Choisir un autre outil image, et prévenir un administrateur. |
| « … a bloqué cette demande ou sa réponse : son filtre de sécurité l'a jugée sensible. » | Reformuler la demande, ou choisir un autre outil image. |
| « … est surchargé en ce moment », « … reçoit trop de requêtes en ce moment » | Le fournisseur ne répond pas : réessayer un peu plus tard, ou choisir un autre outil image. |
| « Aucun outil de génération d'image autorisé sélectionné ou introuvable. » | L'outil a été retiré, désactivé ou n'est plus autorisé pour votre groupe. En choisir un autre dans le menu **+**. |
| « L'outil qui a créé cette image n'est plus disponible… » après **Regénérer** | Choisir un outil image dans le menu **+**, puis cliquer à nouveau **Regénérer** — ou redemander l'image. |
| Chaque demande crée une image sans tenir compte de la précédente | Le moteur ne sait pas retoucher. Voir avec un administrateur. |
| L'image ne reprend pas le modèle voulu, pas de ligne « D'après … » | Nommez l'image (son nom de fichier) dans la demande. Vérifiez que sa base est cochée sous le champ de saisie, ou liée à l'assistant. Le moteur doit savoir partir d'une image. |
