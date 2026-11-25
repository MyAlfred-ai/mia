---
title: Créer une image, une page web ou une carte
description: Une brique « Création », ou une étape dont la pièce est une image, une page web ou une carte, fait travailler un outil de création du chat ; ce qu'il produit devient une pièce.
sidebar:
  order: 4
---

Une **création** confie à un outil de création — ceux du menu **+** du chat : **image**, **page
web** (simple ou illustrée), **carte** d'itinéraire — une demande nourrie par le dossier : *« une
illustration pour @[Article] »*, *« une page qui présente @[Offre] »*, *« l'itinéraire de la tournée
de @[Rendez-vous] »*. Ce qu'il produit devient une **pièce** que les briques suivantes reprennent :
l'étape qui rédige l'annonce voit l'image, la livraison la joint à l'email.

## Avant de commencer

- Seuls les **outils que vous pouvez utiliser dans le chat** sont proposés : ouverts à vos groupes,
  et permis par votre rôle. Si la famille **Créer** du menu d'ajout ne propose rien, demandez à
  votre administrateur d'en ouvrir un.
- Une création consomme des crédits, comme la même création dans le chat.

Deux façons de le faire :

- une **brique Création**, quand la demande se suffit à elle-même : *« une affiche pour
  @[Informations de départ › Évènement] »* ;
- une **étape** dont la pièce est une image, une page web ou une carte, quand il faut d'abord un
  assistant, des sources ou des bases pour savoir quoi créer : l'étape prépare la demande, l'outil
  fabrique la pièce.

## Étapes, avec une brique Création

1. **+** sur le rail → sous **Créer** : **Une image**, **Une page web** ou **Une carte**.
2. **Avec quel outil** : choisissez parmi ceux que vous pouvez utiliser.
3. **Ce qu'il faut créer** : votre demande, en citant les pièces avec **@**. Un champ de Fiche cité
   est remplacé par sa valeur — *« une affiche pour @[Informations de départ › Évènement] »*.
4. Pour une image, **Format et fond** : le **format** (celui de l'outil, carré, paysage ou
   portrait), **Fond transparent** — le fichier passe alors en PNG — et le **format du fichier**
   (celui de l'outil, PNG, JPEG ou WebP). Seuls les réglages que l'outil accepte sont proposés. Pour
   une page illustrée, le **style des illustrations**.
5. **Remet au dossier** : nommez la pièce. Cochez **Je relis le résultat avant la suite** pour
   l'approuver, ou la renvoyer avec une remarque.
6. **Publiez**.

## Étapes, depuis une étape

1. Ouvrez une **étape** : **qui fait le travail** (MIA ou un assistant), sa **consigne**, ce qu'elle
   **peut consulter**, comme toute étape.
2. **Remet au dossier** → **Forme** : sous **Faite par un outil de création**, choisissez **Image**,
   **Page web** ou **Carte**. Une forme grisée n'a aucun outil en service pour vous : la raison
   s'affiche dessous.
3. Choisissez **l'outil** qui fabrique la pièce et, pour une image, son **format**, son **fond
   transparent** et son **format du fichier** (pour une page illustrée, le style des illustrations).
4. Nommez la pièce, cochez au besoin **Je relis la pièce avant la suite**, puis **publiez**.

À l'exécution, l'étape écrit d'abord sa demande dans la conversation de l'exécution — la description
de l'image, le contenu de la page, les arrêts de l'itinéraire — avec son assistant, ses sources et
ses bases ; l'outil fabrique ensuite la pièce d'après cette demande. Si l'outil n'est plus ouvert à
vos groupes, l'étape ne démarre pas.

## Ce qu'il faut savoir

- **Elle part du dossier, pas de la conversation.** L'outil reçoit votre demande et les pièces que
  la brique lit. Pour une image, MIA en tire la description à donner au modèle ; pour une page ou une
  carte, les pièces sont le contenu à mettre en forme. Une page peut mettre en page une image créée
  plus tôt dans le workflow.
- **Une image lue sert de point de départ.** Une image créée plus tôt, ou un fichier image reçu au
  départ, est montrée à l'outil, qui la reprend ou s'en inspire — s'il sait partir d'une image ;
  sinon, l'éditeur vous le signale. De même, une page ou une carte lue est **modifiée** plutôt que
  refaite.
- **Renvoyée avec une remarque**, la création repart de sa version précédente : *« fond bleu
  nuit »*, *« ajoute un arrêt à Vienne »*. Elle ne se corrige pas à la main.
- **Où la retrouver** : dans le dossier de l'exécution (l'image s'y affiche ; la page et la carte se
  voient dans la conversation de l'exécution), dans le message du livrable (l'image y est montrée,
  chaque création avec son fichier à télécharger), et dans **Mes créations**, comme celles du chat —
  une seule fois, même reprise par le livrable.
- **Ce qu'en font les livraisons** : une image part en pièce jointe d'un email (allégée si elle est
  lourde) et entre dans une base de connaissances, qui la décrit ; une page part en fichier .html, un
  itinéraire en document. Google Drive et Slack ne reçoivent ici que des documents et du texte : une
  image y est laissée de côté, et l'éditeur vous prévient.
- **Dans une boucle**, une création — brique Création ou étape qui remet une image, une page, une
  carte — sert aux briques du même tour. Une boucle « jusqu'à » garde sa dernière version ; une
  boucle « pour chaque » ne rassemble que les pièces rédigées (texte, liste, tableau, Fiche,
  document).
- **Une panne de l'outil** (service saturé) se réessaie seule si l'étape le prévoit, sans refaire la
  demande : seule la fabrication repart.
- **Pas de vidéo** dans un workflow.
- **Un outil retiré** (plus ouvert à vos groupes, arrêté par son fournisseur, en échec à son dernier
  test) arrête l'exécution sur la brique, avec la raison : choisissez-en un autre dans l'éditeur,
  publiez, puis reprenez avec la dernière version.
- **Le coût** d'une étape qui remet une création compte son tour et la fabrication de sa pièce.
