---
title: Télécharger un fichier créé par MIA
description: Obtenir un tableau Excel, un document Word, une présentation PowerPoint, un CSV ou un texte prêt à enregistrer, directement dans la conversation.
sidebar:
  order: 5
---

## Étapes

1. Demandez un **fichier**, en nommant le format voulu : *« Prépare le budget 2026 dans un fichier
   Excel »*, *« Rédige le compte rendu en Word »*, *« Fais-moi une présentation PowerPoint du
   projet »*, *« Exporte la liste des clients en CSV »*, *« Donne-moi ces notes dans un fichier
   texte »*.
2. Le fichier apparaît dans la réponse sous forme de **carte** : son nom, son type et un aperçu — les
   premières lignes d'un tableau, le document ou le plan de la présentation mis en forme, le début
   d'un texte ou d'un script.
   **Afficher tout** déplie un aperçu long.
3. Cliquez **Télécharger** : le fichier est enregistré dans le format annoncé.
4. Pour le même contenu dans un autre format, ouvrez la **flèche** à côté de **Télécharger**.

Pour modifier le fichier, demandez-le dans la conversation — *« Ajoute une colonne TVA »* :
MIA renvoie le fichier complet, dans une nouvelle carte.

## Les formats

| Vous demandez | Vous obtenez | Autres formats proposés |
|---|---|---|
| Un tableau | Classeur **Excel** (.xlsx) ou fichier **CSV** | Excel, CSV |
| Un document mis en forme | **Word** (.docx), **PDF** ou **RTF** | Word, PDF, RTF, Markdown, texte |
| Une présentation | **PowerPoint** (.pptx) | PowerPoint, PDF (une page par slide), Word, Markdown |
| Un texte simple | Fichier **texte** (.txt) ou **Markdown** (.md) | — |
| Des données ou du code | Le fichier du format demandé (données, configuration, script…) | — |

Dans un classeur, les nombres, montants, pourcentages et dates sont de **vraies valeurs** : les
sommes et les tris fonctionnent. Les codes comme un code postal « 01000 » gardent leur zéro, et les
formules de calcul — une somme, une moyenne — sont conservées. Une formule qui irait ouvrir un lien
ou un autre fichier est écrite comme du **texte**, par sécurité.

## Une présentation PowerPoint

MIA rédige le contenu et la mise en page est automatique : une **couverture** sur fond sombre (le
titre et son sous-titre), puis **une slide par partie** — une idée par slide, en quelques puces
courtes. Selon le contenu, une slide prend la forme qui lui convient :

| Contenu de la partie | Slide obtenue |
|---|---|
| 2 à 6 notions avec leur explication (« **Délais** — trois jours au lieu de dix ») | **Cartes** numérotées |
| 2 à 6 chiffres avec leur légende | **Chiffres clés**, en grand |
| Des dates et ce qui s'y est passé | **Chronologie** |
| Un tableau | Un **vrai tableau**, découpé sur plusieurs slides s'il est long |
| Deux sous-parties (avant / après, option A / option B) | **Comparaison** en deux colonnes |
| 3 à 6 étapes numérotées | **Étapes** |
| Beaucoup de points très courts | **Pastilles** sur deux colonnes |
| Rien d'autre que son titre | **Intercalaire** numéroté (01, 02…) |
| Une citation seule | **Citation** |

Pour une forme précise, demandez-la : *« mets les chiffres clés en avant »*, *« présente les ventes
dans un tableau »*. Ce que vous demandez comme **notes du présentateur** se retrouve sous chaque slide
dans PowerPoint : c'est là que vont les détails, la slide garde l'essentiel. Une slide trop chargée
est découpée en deux — « Titre (suite) » — plutôt que rapetissée. Sans modèle d'entreprise, le
thème de MIA prend la **couleur de votre organisation** : aplats, cartes arrondies, pastilles, sans
image.

**Aux couleurs de votre entreprise.** Si votre organisation a déposé son **modèle PowerPoint**, la
carte le dit (« au modèle de … ») et la présentation est construite dans ce modèle : ses mises en
page, ses polices, ses couleurs, la mention de l'entreprise en pied de page. Sinon, elle porte le **logo** et le **nom** (ou la mention de pied de
page) de l'entreprise s'ils ont été renseignés. Le PDF « une page par slide » garde la mise en page de
MIA, aux couleurs du modèle.

De même, un document **Word** suit le **modèle Word** de l'entreprise s'il a été déposé — ses styles,
ses polices, ses en-têtes et pieds de page —, et un document **Word** ou **PDF** porte sinon le logo
de l'entreprise en en-tête, sa mention et le numéro de page en pied. C'est vrai aussi pour
**Exporter en Word** et **Exporter en PDF** sous une réponse.

**Une version soignée.** La carte d'une présentation porte aussi le bouton **Version soignée** : un
modèle, ou Gamma, compose lui-même la mise en page — voir [plus bas](#la-version-soignée-par-un-modèle-ou-par-gamma).

**Retouchée à l'écran.** Le bouton **Modifier les slides** de la carte ouvre la présentation dans
l'éditeur, dans le volet de droite : déplacer, réécrire, ajouter des illustrations, puis exporter —
voir [Créer une présentation](../outils/creer-une-presentation.md).

## La version soignée, par un modèle ou par Gamma

La carte d'une **présentation** ou d'un **classeur** porte le bouton **Version soignée**. Le fichier
n'est plus mis en page automatiquement par MIA : un **modèle** le compose lui-même — ou, pour une
présentation, **Gamma**, si votre organisation l'utilise. Quand les deux sont possibles, un petit
menu propose **Par …** (le modèle, avec le temps et le coût estimés) et **Par Gamma** ; votre dernier
choix y est signalé. La version rapide de MIA, immédiate et sans crédit, reste au bouton
**Télécharger**.

### Par un modèle

Une fenêtre s'ouvre avant le lancement — dans la carte sur ordinateur, en bas de l'écran sur
téléphone. Elle dit tout avant que rien ne parte :

- **Réalisée par** : le modèle qui composera le fichier. C'est celui de la conversation s'il en est
  capable ; sinon MIA en choisit un — le modèle par défaut de votre organisation, ou un modèle
  standard qui sait le faire, jamais un modèle haut de gamme d'office — et le dit. La conversation,
  elle, garde son modèle. Vous pouvez en choisir un autre parmi ceux qui vous sont ouverts : chacun
  affiche son coût estimé et, s'il y a lieu, *Modèle de la conversation*, *Modèle par défaut de
  votre organisation*, *Le plus économique* ou *Haut de gamme*.
- **Ce qui part chez** l'éditeur du modèle (Anthropic ou OpenAI) : le **contenu de la carte** et
  votre **demande** — vos derniers messages avant la carte : *« mets la photo de l'équipe en
  couverture »*, *« un graphique des ventes par mois »* — partent toujours. Le reste se décoche un à
  un : les **fichiers joints à la conversation** avant la carte (photos, PDF, classeurs, documents,
  les huit plus récents : une photo peut finir sur sa slide, les chiffres d'un classeur nourrir un
  graphique) et, pour une présentation, le **modèle PowerPoint de l'entreprise** — ses slides
  d'exemple lui montrent le style maison, sans se retrouver dans le fichier rendu — avec le **logo**.
  Un fichier joint plus tôt dans la conversation est signalé, et décoché d'office. Les fichiers
  envoyés sont effacés chez l'éditeur une fois le fichier reçu.
- **Une précision pour la mise en page ?** (facultatif) : *« style sobre, un chiffre clé par
  slide »*.
- **Le coût estimé**, en crédits : la moyenne de vos dernières réalisations du même genre avec ce
  modèle, sinon une estimation d'après son tarif.

**Lancer la mise en page** (**Lancer la réalisation** pour un classeur) : comptez **1 à 3 minutes** ;
un message ⏳ l'annonce, puis un **lien** vers le fichier arrive dans la conversation ; le fichier est
**gardé 30 jours**. La réalisation est **comptée dans vos crédits**, comme une réponse.

Un classeur réalisé par le modèle garde vos lignes et vos valeurs, ajoute des **formules** là où
elles servent (totaux, écarts), met en forme et fige l'en-tête, et ajoute un graphique Excel quand
votre demande en suggère un.

### Par Gamma

Après confirmation, la présentation part chez **gamma.app**, qui la met en page — thème,
illustrations, une carte par slide — **sans réécrire votre texte** ; les notes du présentateur n'y
vont pas. Comptez **1 à 3 minutes** : un message ⏳ l'annonce, puis le **lien** vers la présentation
et son **export** (un fichier PowerPoint, ou un PDF selon le réglage de votre organisation) arrivent
dans la conversation, même si vous êtes passé à une autre. Chaque création consomme des **crédits
Gamma** de l'organisation, pas vos crédits MIA.

## Ce que MIA ne crée pas

Un ancien classeur .xls, un document .doc ou une présentation .ppt, un fichier Keynote, une archive
.zip, une image : la carte l'explique et propose le format le plus proche — par exemple
**Télécharger en PowerPoint (.pptx)** pour une présentation demandée en Keynote. Une image se crée
avec l'outil **Image** du menu **+**. Voir [Les outils](../outils/index.md).

## Télécharger sans carte

- **Un bloc de code** affiché dans une réponse — un tableau CSV, un script — porte aussi un bouton
  **Télécharger** dans son en-tête, à côté de **Copier**.
- **Une image** de la réponse porte un bouton de téléchargement : au survol sur ordinateur, en
  permanence sur téléphone, et dans l'image agrandie.

Dans l'application mobile, le fichier s'ouvre dans la **feuille de partage** du téléphone :
enregistrement dans Fichiers, envoi par mail ou messagerie.

## Ce qu'il faut savoir

- **Rien n'est stocké à part.** Le fichier est fabriqué au moment du clic, à partir du contenu affiché
  dans la conversation : tant que la conversation existe, vous pouvez le télécharger à nouveau, dans
  le format de votre choix. Seul un fichier **réalisé par le modèle** est gardé, 30 jours.
- **L'icône Copier** de la carte copie le contenu tel que MIA l'a écrit.
- **Copier ou exporter la réponse** en PDF ou Word reprend le nom du fichier et son aperçu, sans les
  boutons.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| La réponse contient un tableau mais aucune carte | MIA n'a pas livré de fichier : demandez-le explicitement (*« dans un fichier Excel »*). Un bloc de code a aussi son bouton **Télécharger**. |
| « Le serveur n'a pas répondu… » | Vérifiez votre connexion, puis **Réessayer**. **Contenu brut** enregistre le texte tel quel, sans passer par le serveur. |
| « Ce fichier est trop volumineux pour être fabriqué ici… » | Choisissez un format proposé dans la carte (un grand tableau passe en CSV), ou demandez un fichier plus court. |
| « Cette présentation compterait … slides… » | Une présentation créée ici compte au plus 30 slides : demandez une version plus courte, ou téléchargez-la en Word. |
| « Fichier incomplet : la réponse s'est arrêtée avant la fin. » | La réponse a été interrompue pendant l'écriture du fichier : téléchargez-le tel quel, ou **Regénérez** la réponse. |
| « Session expirée — rechargez la page. » | Rechargez la page et reconnectez-vous ; le fichier est toujours dans la conversation. |
| « Téléchargement direct impossible : l'image s'ouvre dans un nouvel onglet. » | L'image vient d'un autre site : enregistrez-la depuis l'onglet qui s'est ouvert. |
| **Version soignée** ne propose pas **Par Gamma** | Votre organisation n'utilise pas Gamma, ou ne vous l'a pas ouvert : voyez avec votre administrateur. |
| **Version soignée** est grisé | Aucun modèle qui vous est ouvert ne sait fabriquer ce fichier lui-même : téléchargez la version de MIA, ou voyez avec votre administrateur. |
| « Choisissez le modèle qui réalisera le fichier. » | Seuls des modèles haut de gamme savent le faire pour vous : cochez celui que vous voulez sous **Réalisée par**. |
| « Le modèle n'a pas rendu de fichier PowerPoint… » ou « …de classeur Excel… » | Relancez **Version soignée**, ou téléchargez la version de MIA. Les crédits déjà consommés par la tentative sont comptés. |
| Une photo ou un fichier joint n'a pas servi | Vérifiez qu'il était coché dans la fenêtre. Seuls les fichiers joints **avant** la carte, dans la **même** conversation, partent au modèle : joignez-le, demandez à nouveau la présentation, puis **Version soignée** sur la nouvelle carte. |
| « Ce fichier n'existe plus… » | Le fichier réalisé par le modèle est gardé 30 jours : relancez la réalisation depuis sa carte. |
| « Cette présentation compte … slides : l'administrateur a fixé à … cartes le maximum… » | Demandez à MIA une version plus courte, ou à l'administrateur de relever ce plafond. |
| « Crédits Gamma épuisés : la génération a été refusée… » | Le compte Gamma de l'organisation n'a plus de crédits : prévenez un administrateur. |
| « Cette présentation est déjà en cours de création chez Gamma… » | Elle est partie au premier clic : son lien arrivera dans la conversation. |
