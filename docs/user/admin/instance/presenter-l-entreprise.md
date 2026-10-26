---
title: Présenter l'entreprise
description: Donner à MIA le nom, le descriptif et le vocabulaire de l'entreprise, habiller ses documents (logo, mention de pied de page) et déposer ses modèles PowerPoint et Word.
sidebar:
  order: 1
---

**Administration → Organisation → Entreprise.** La page s'enregistre seule.

L'entreprise, c'est l'organisation qui **utilise** MIA. Elle a sa page, distincte de
[Marque & apparence](personnaliser-l-apparence.md), qui habille l'**application** : vous pouvez garder
« MIA » et ses couleurs à l'écran, et vouloir que l'assistant sache qui vous êtes et que les documents
portent le logo de votre entreprise.

## Présentation

| Réglage | Où il sert |
|---|---|
| **Nom de l'entreprise** | Donné à l'assistant dans chaque conversation ; pied de page des documents, à défaut de mention |
| **Descriptif de l'entreprise** | 1 000 caractères au plus. Ajouté au début de **chaque conversation**, pour tous les assistants et tous les modèles, et aux présentations de l'outil **Présentation** |

Au-dessus du descriptif, des **rubriques** — Activité, Clients, Offre, Implantations, Chiffres clés,
Organisation, Valeurs — aident à ne rien oublier : un clic ajoute la ligne « Rubrique : » (ou y place
le curseur si elle existe), et une rubrique remplie est cochée. Sous le champ, **Partir d'un exemple**
pose un descriptif d'exemple (industrie, services, association) à adapter ; si vous aviez déjà écrit
quelque chose, MIA demande avant de le remplacer.

Le descriptif est une **information**, pas une consigne : MIA s'en sert pour comprendre « notre
offre » ou « nos clients », et ne le cite que si c'est utile. Une consigne de comportement (ton,
tutoiement) va dans le prompt d'un assistant.

## Habillage des documents

- **Logo de l'entreprise** : PNG ou JPG, idéalement sur fond transparent — les autres formats ne
  s'affichent ni dans PowerPoint, ni dans Word, ni dans un PDF. Il se place en en-tête des documents
  Word et PDF, en couverture et en pied des présentations. Il ne change **pas** le logo de l'interface,
  réglé dans [Marque & apparence](personnaliser-l-apparence.md).
- **Mention de pied de page** : une ligne, 200 caractères au plus — par exemple *« Acme SA · 12 rue
  des Forges, 44000 Nantes · SIRET 123 456 789 00012 · Document interne »*. Elle s'affiche au pied de
  chaque page, avec le numéro de page, dans les documents Word et PDF et les présentations que MIA
  produit, **exports d'une réponse compris**. Vide, c'est le nom de l'entreprise qui s'affiche.

Un modèle PowerPoint ou Word déposé plus bas apporte son propre logo et, s'il en a un, son propre pied
de page : le modèle l'emporte. Une présentation retouchée dans l'éditeur (**Modifier les slides**)
garde la mise en page de MIA, aux couleurs de l'entreprise.

## Vocabulaire de l'entreprise

Les sigles et le lexique maison, pour que l'assistant comprenne *« un CRA »* ou *« le TJM »* sans
explication : un terme par ligne, sous la forme `terme : définition` (le tiret, la flèche ou le signe
`=` conviennent aussi). Une ligne sans définition est du **contexte général** (*« Nos clients sont
appelés des partenaires »*) ; un titre (`## RH`) ne sert qu'à vous relire.

**Ce qui part à l'assistant, à chaque message :**

- un vocabulaire **court** (moins de 2 000 caractères) part **en entier** ;
- un vocabulaire **long** est **trié** : seules les entrées dont le terme — ou le début de la
  définition, *« taux journalier moyen »* pour TJM — apparaît dans la conversation (message en cours,
  derniers échanges, consignes de l'assistant) sont envoyées, avec les lignes de contexte général. Un
  terme qui surgit au dixième message est pris à ce message-là. L'assistant est prévenu qu'un sigle
  absent de la liste se demande, et ne s'invente pas.

La recherche ignore majuscules et accents, accepte le pluriel (*« les CRAs »*) et ne prend que des
mots entiers (*CRA* ne se trouve pas dans *crayon*). Ce réglage vivait auparavant dans Modèles IA →
Modèles : votre vocabulaire y a été repris tel quel.

## Ce que l'assistant lit

La carte **Ce que l'assistant lit** montre le texte **exact** ajouté au début des conversations — la
présentation de l'entreprise et le vocabulaire — et son poids en jetons, tenu à jour pendant la
saisie, avant même l'enregistrement. Tapez une question dans **Essayer avec une question** : la carte
montre les entrées du vocabulaire qui seraient retenues pour ce message. Rien n'est enregistré ni
envoyé à un modèle.

## Modèle PowerPoint

Déposez le modèle officiel de l'entreprise (**.pptx** ou **.potx**, 20 Mo au plus) : les
présentations que les utilisateurs téléchargent en PowerPoint depuis la conversation sont alors
construites **dans ce modèle** — ses mises en page, ses polices, ses couleurs, la mention de
l'entreprise en pied de page avec le numéro de slide.

- **Au dépôt, MIA analyse le fichier** : la miniature enregistrée dans le modèle (ou, à défaut, un
  aperçu indicatif dessiné avec ses couleurs et ses polices), le format, le nombre de mises en page,
  ses couleurs et ses polices.
- **Comment MIA remplit votre modèle** : un tableau dit, pour chaque forme de slide que MIA écrit,
  la mise en page de votre modèle qu'elle prend — couverture, partie (intercalaire), puces et points
  clés, deux colonnes, comparaison, et « titre seul » pour les chiffres, tableaux, étapes et
  chronologies, que MIA dessine aux couleurs du modèle. **Trouvée** : le modèle a la mise en page
  dédiée ; **À défaut** : il n'en a pas, MIA prend la plus proche. Dessous, ce qui vient du modèle
  (polices, couleurs, arrière-plans, éléments du masque) et ce que MIA ajoute (le texte, les formes
  dessinées, le pied de page et le numéro de slide).
- **Où le modèle sert** : aux présentations PowerPoint livrées dans la conversation — leur carte
  l'annonce, « au modèle de … » —, et à celles qu'un modèle réalise lui-même (**Version soignée** sur
  la carte). Dans ce second cas, le modèle part **en entier** chez le fournisseur du modèle (Anthropic
  ou OpenAI), **slides d'exemple comprises**, avec le logo de l'entreprise : c'est sa **référence** du
  style maison — l'utilisateur voit cette ligne avant de lancer, et peut la décocher. Le fichier rendu
  reprend les mises en page du modèle, sans aucune de ses slides d'exemple ; les fichiers envoyés
  sont effacés chez le fournisseur une fois la présentation reçue. Une présentation retouchée dans
  l'éditeur (**Modifier les slides**) garde la mise en page de MIA, aux couleurs de l'entreprise, et
  **Version soignée › Par Gamma** le thème choisi dans Gamma.
- **Télécharger une présentation d'essai** fabrique, comme dans la conversation, une présentation qui
  passe par chaque mise en page : ouvrez-la dans PowerPoint pour vérifier le rendu avant de prévenir
  les utilisateurs. Sans modèle, elle montre la mise en page de MIA.
- **Les slides d'exemple du modèle ne sont pas reprises** dans les présentations : seuls ses masques,
  mises en page, polices et couleurs servent. Le logo de l'entreprise n'est pas ajouté aux slides d'un modèle : ses masques
  portent déjà les siens.
- **Le PDF** « une page par slide » garde la mise en page de MIA, aux couleurs du modèle.
- **Retirer** remet la mise en page de MIA : son thème, à la couleur d'accentuation de Marque &
  apparence. Un nouveau dépôt remplace le modèle en place ; un dépôt
  **refusé** l'est avec son motif, et l'ancien modèle reste en place.

## Modèle Word

Déposez le modèle officiel de l'entreprise (**.docx** ou **.dotx**, 20 Mo au plus) : les documents
Word que MIA produit — fichiers livrés dans la conversation, **Exporter en Word** d'une réponse — sont
alors construits **dans ce modèle** : ses styles de titres et de texte, ses polices, ses couleurs, sa
mise en page, ses en-têtes et ses pieds de page.

- **Au dépôt, MIA analyse le fichier** : format de page, styles de titres retrouvés (**Titre**,
  **Titre 1**, **Titre 2**, **Titre 3**), en-tête, pied de page, couleurs et polices du thème. Un style
  de titre absent du modèle est remplacé par celui de MIA.
- **Le contenu d'exemple du modèle n'est pas repris**, ses commentaires non plus, et une protection en
  écriture est levée : le document produit se retravaille.
- **Un modèle sans pied de page** reçoit celui de MIA : la mention de l'entreprise et le numéro de
  page. Un modèle qui a le sien le garde.
- **Télécharger un document d'essai** fabrique un document avec trois niveaux de titres, des listes, un
  tableau, un lien et une citation : ouvrez-le dans Word pour vérifier le rendu.
- **Retirer** remet la mise en page de MIA (A4, logo en en-tête, mention en pied).

Les fichiers des modèles ne sont pas accessibles depuis le web. Ils font partie de la
[sauvegarde de la configuration](sauvegarder-la-configuration.md).

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| L'assistant ignore l'entreprise | Vérifier la carte **Ce que l'assistant lit** ; une conversation déjà commencée garde ses réponses passées, la suivante en tient compte. |
| Un sigle n'est pas compris | Avec un vocabulaire long, seules les entrées citées partent : essayez la question dans **Essayer avec une question**. Vérifiez la forme `terme : définition` et l'orthographe du terme. |
| Le logo ne s'affiche pas dans les documents | Il est en SVG, WebP ou GIF : déposez un PNG ou un JPG. Avec un modèle, c'est normal : le modèle porte son logo. |
| La mention n'apparaît pas en pied de page | Le modèle Word ou PowerPoint a son propre pied de page : il l'emporte. |
| « Modèle refusé : ce modèle contient des macros… » | Ouvrez-le dans PowerPoint ou Word et enregistrez-le sans macros (**.pptx**, **.potx**, **.docx** ou **.dotx**). |
| « Modèle refusé : … pas une présentation PowerPoint » ou « … pas un document Word » | Le fichier n'est pas du bon type, ou c'est un ancien **.ppt** / **.doc** : enregistrez-le au format récent depuis PowerPoint ou Word. |
| « Modèle refusé : fichier trop volumineux » | Retirez du modèle les pages ou slides d'exemple et les images lourdes, puis déposez-le à nouveau. |
| Une mise en page est marquée **à défaut** | Le modèle PowerPoint n'a pas de mise en page de ce type : ajoutez-la dans PowerPoint (Affichage → Masque des diapositives), ou laissez MIA prendre la plus proche. |
| Les documents ne suivent pas le modèle | Le modèle a été retiré, ou le fichier est illisible depuis son dépôt : MIA reprend alors sa propre mise en page. Déposez-le à nouveau. |
