---
title: Filtrer les emails et les fichiers
description: Limiter ce qu'une étape ou une action voit dans Gmail, Outlook ou Google Drive, et à qui ses emails peuvent partir.
sidebar:
  order: 5
---

Une étape qui consulte vos emails ou votre Google Drive, ou une action qui y agit, peut être
**filtrée** : elle ne voit que les emails d'un expéditeur, d'un objet, avec une pièce jointe, d'une
période ; que les fichiers d'un dossier, d'une nature, dont le nom contient un mot. Le filtre
s'applique **aux recherches elles-mêmes** : MIA ne voit rien en dehors, quoi qu'elle cherche.

## Avant de commencer

Reliez le service concerné dans **Réglages → Comptes connectés**. Les filtres apparaissent dès que
l'étape consulte Gmail, Outlook ou Google Drive, ou que l'action agit dans l'un d'eux.

## Étapes

1. Dans l'éditeur, touchez l'étape, dépliez **Peut consulter** et cochez Gmail, Outlook ou Google
   Drive — ou, pour une action, choisissez l'outil puis dépliez **Filtres**.
2. **Emails** : **De**, **Objet contient**, **Mots**, **Reçus** (le dernier jour, les 7, 30, 90
   derniers jours…), **Avec pièce jointe**, **Non lus seulement**.
3. **Fichiers Google Drive** : **Dans le dossier** (son lien ou son chemin), **Dont le nom
   contient**, la nature (documents, feuilles de calcul, présentations, PDF), **Modifiés** depuis.
4. Pour une brique qui **agit**, **Ses emails partent seulement vers** : des adresses ou des domaines
   (*@client.fr*), séparés par des virgules.

## Ce qu'il faut savoir

- **Un filtre peut citer le dossier avec @** : *De* = l'expéditeur de l'email reçu, *Dans le
  dossier* = *Clients/@[Client en cours]* dans une boucle. La valeur est reprise à chaque exécution.
- **Un email vers une adresse non permise est refusé** avant même sa carte d'approbation — et aussi
  quand la brique agit sans validation. Une réponse dans un fil doit alors nommer son destinataire.
- **Un fichier créé par une action Drive filtrée** est rangé dans le dossier du filtre.
- **Le déclencheur aussi se filtre** : un workflow qui démarre à l'arrivée d'un email ne part que
  pour les emails d'un expéditeur, d'un objet, avec une pièce jointe ; à l'arrivée d'un fichier, que
  pour une nature ou un nom — voir [Démarrer sur un évènement](demarrer-sur-un-evenement.md).
- Sur le rail, une brique filtrée l'indique (**Filtrée**) ; le volet résume ses filtres sur une ligne.
