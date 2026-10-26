---
title: Les outils
description: Faire produire à MIA une image, une vidéo, une page web, une présentation ou un itinéraire — et retrouver, partager ce qu'elle a créé.
sidebar:
  order: 6
---

Un **outil** est une capacité de production : il fabrique un livrable — une image, une vidéo, une
page web, un itinéraire — avec son propre moteur, choisi par votre administrateur. Une présentation,
elle, se demande dans la conversation : voir [Créer une présentation](creer-une-presentation.md).
Ce que produit un outil s'appelle une **création**.

## À quoi ça sert

Un modèle répond en texte. Un outil **produit un objet** que vous pouvez télécharger, retoucher,
partager : l'affiche du salon, la présentation du comité, la page de l'événement, le trajet de la
tournée. Vous le décrivez en langage courant, puis vous l'affinez message après message.

Un tableau Excel, un document Word, un CSV ou un fichier texte ne demandent **aucun outil** : MIA
les livre directement dans sa réponse, prêts à télécharger. Voir
[Télécharger un fichier créé par MIA](../conversations/telecharger-un-fichier.md).

## Comment ça marche

**Les outils proposés.** Le menu **+**, section **Créer**, liste ceux auxquels vos groupes vous
donnent droit, chacun sur une ligne avec son coût estimé par génération (« ≈ 40 cr. ») — le prix,
pas la qualité. Survolez un outil pour lire ce qu'il fait.

| Famille | Ce qu'elle produit |
|---|---|
| **Image** | Une image, retouchable par la conversation |
| **Vidéo** | Une courte vidéo, de 4 à 12 secondes |
| **Page HTML** | Une page web autonome, retouchable à la main |
| **Site illustré** | Une page web accompagnée d'images générées |
| **Carte / Itinéraire** | Un trajet dessiné sur une carte, avec ses étapes |

Deux autres familles ne sont pas dans le menu : les **automatisations n8n** et les **présentations
Gamma** sont confiées à des assistants, qui les déclenchent après votre approbation. Une présentation
PowerPoint se demande directement dans la conversation : sa carte la télécharge, la retouche
(**Modifier les slides**), la fait réaliser par le modèle ou la confie à Gamma — voir
[Créer une présentation](creer-une-presentation.md).

Les **schémas** ne sont pas un outil : n'importe quelle réponse peut en contenir.

**Un outil engage la conversation.** Un seul outil est actif à la fois. Une fois choisi, sa pastille
s'affiche à côté du bouton **+**, et **chaque message part vers lui** jusqu'à ce que vous cliquiez sa
croix. C'est ce qui permet d'affiner : *« mets le titre en blanc »*, *« ajoute une étape à Aix »*.
Le choix est mémorisé pour la conversation, dans votre navigateur.

**MIA peut vous le proposer.** Quand votre message décrit une image, une page ou un trajet, une
bannière propose l'outil adapté : **Utiliser …** l'active, **Non merci** fait taire cette suggestion
jusqu'à la fermeture de l'onglet. Rien n'est envoyé sans vous.

**Le moteur de l'outil n'est pas celui de la conversation.** Chaque outil a son fournisseur et son
modèle, réglés par l'administrateur. Changer de modèle dans le sélecteur ne change pas l'outil.

**Un outil grisé** a échoué à son dernier test, ou son modèle a été arrêté par son fournisseur — la
raison s'affiche sous son nom. Il reste inutilisable jusqu'à ce qu'un administrateur le corrige.

**Le coût.** Une image se paie à l'unité, une vidéo à la seconde, une page ou un itinéraire selon le
texte rédigé — plus chaque image générée pour l'illustrer. Voir
[La gestion des crédits](../credits/index.md).

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Créer ou retoucher une image | [Générer une image](generer-une-image.md) |
| Produire une courte vidéo | [Générer une vidéo](generer-une-video.md) |
| Obtenir une page web, la retoucher, la télécharger | [Créer une page web](creer-une-page-web.md) |
| Demander une présentation, la retoucher et l'exporter | [Créer une présentation](creer-une-presentation.md) |
| Dessiner un trajet sur une carte | [Tracer un itinéraire](tracer-un-itineraire.md) |
| Illustrer une réponse d'un diagramme | [Obtenir un schéma](obtenir-un-schema.md) |
| Faire exécuter une automatisation ou une présentation Gamma par un assistant | [Utiliser les outils d'un assistant](utiliser-les-outils-d-un-assistant.md) |
| Retrouver vos créations et les envoyer à un collègue | [Retrouver et partager ses créations](retrouver-et-partager-ses-creations.md) |

## Ce que vous pouvez régler

Pour chaque création : ce que vous demandez, et les options de l'outil — durée d'une vidéo, nombre
de slides, ton, style visuel. Le reste — quels outils existent, leur moteur, leur format par défaut,
qui y a droit — dépend de votre administrateur.

## Exemples

**Une affiche retouchée en deux messages.** Outil image engagé : *« une affiche carrée pour le salon
Agrotech, tons verts »*, puis *« mets le titre en blanc »*. La seconde demande retouche l'image au
lieu d'en créer une autre, si le moteur de l'outil sait retoucher.

**La présentation du comité, à partir d'une note.** Sans outil : vous joignez la note en PDF et
demandez *« 8 slides PowerPoint pour le comité de direction »*. Sur la carte, **Modifier les slides**
pour ajuster une slide, puis **Exporter** en PowerPoint.

**Une tournée commerciale.** Outil carte : *« tournée de deux jours Lyon, Grenoble, Annecy, départ
8 h »*. Vous exportez en PDF pour la réunion, puis ouvrez le trajet dans Google Maps pour conduire.

**Partager sans exposer la conversation.** Sur l'image finale, **Partager** copie un lien : votre
collègue voit la création, jamais l'échange qui l'a produite.

## Voir aussi

- [Les assistants](../assistants/index.md) — un assistant peut appeler ses outils lui-même
- [Les conversations](../conversations/index.md) — un outil vit dans une conversation
- [La gestion des crédits](../credits/index.md) — ce que coûte une création
