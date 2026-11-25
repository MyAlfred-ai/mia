---
title: Créer et tenir une table
description: Garder un fichier clients, une grille de tarifs ou un suivi comme table permanente — depuis Excel ou CSV, à la main, ou depuis une conversation — et la faire lire à MIA ligne par ligne.
sidebar:
  order: 7
---

Une **table** est une base de connaissances d'un autre genre : elle ne contient pas des documents,
mais des **lignes** et des **colonnes** — un fichier clients, une grille de tarifs, un suivi de
fournisseurs. MIA ne la découpe pas en extraits : elle la lit **ligne par ligne**, et peut donc
compter, filtrer et croiser sans rien oublier.

## À quoi ça sert

- Poser une question exacte sur une liste : *« combien de clients à Lyon ont dépassé 10 000 € ? »*.
- Réutiliser la même liste d'une conversation à l'autre, ou la donner à un assistant.
- Garder une table que MIA a établie dans une conversation, au lieu de la perdre avec elle.
- Laisser MIA la tenir à jour — toujours après votre accord.

## Avant de commencer

- Être **créateur** ou administrateur : une table se crée et se gère comme une base.
- Pour importer : un fichier Excel (.xlsx, .xls), OpenDocument (.ods) ou CSV, dont la première ligne
  remplie donne le nom des colonnes.

## Créer une table

1. Tuile **Bases** de la barre latérale, puis la carte **Nouvelle table**, sous la recherche. La
   fiche s'ouvre en pleine page, comme celle d'une base : le menu de ses sections à gauche, et en
   haut **Annuler** et **Créer la table**.
2. **Identité** : un nom, et si vous voulez une description.
3. **Contenu** : d'où viennent ses lignes.
   - **Importer un fichier** — choisissez le fichier Excel ou CSV. D'un classeur à plusieurs
     onglets, le premier est lu.
   - **Partir de colonnes vides** — donnez les colonnes, séparées par des virgules. Vous ajouterez
     les lignes ensuite.
4. **Qui la voit** : **Privée**, **Commune** ou **Publique**, comme une base — voir
   [Créer une base de connaissances](creer-une-base-de-connaissances.md).
5. **MIA et assistants** : ce que MIA peut y faire, et les assistants qui la lisent.
6. Tout en bas, la **Vue d'ensemble** montre la table telle qu'elle sera. Cliquez **Créer la
   table** : la page de la table s'ouvre, avec ses lignes.

**Depuis une conversation.** Une table établie par MIA se garde aussi : ouvrez le volet **Tables**
de la conversation, choisissez la table, puis **Garder**. Une copie permanente est rangée dans vos
bases ; la table de la conversation, elle, ne change pas. Voir
[Voir, renommer ou supprimer une table](../connecteurs/voir-ses-tables.md).

## Tenir la table

La page de la table montre ses lignes, à lire et à filtrer. Pour les corriger : **Modifier**, ou
**Corriger les lignes**, puis la section **Lignes** de la fiche. Tout s'y enregistre au fil des
gestes ; **Terminé** ramène à la page de la table.

| Vous voulez… | Geste |
|---|---|
| Corriger une valeur | Cliquez la cellule, tapez, **Entrée**. **Échap** annule |
| Ajouter une ligne | **+ Ligne**, sous la grille |
| Supprimer une ligne | La corbeille en bout de ligne |
| Ajouter une colonne | **+ Colonne** : son nom et son type |
| Renommer une colonne, changer son type, la supprimer | Cliquez son nom, dans **Colonnes** |
| Retrouver des lignes | **Filtrer les lignes** — sans tenir compte des majuscules ni des accents |
| Remplacer tout le contenu | **Remplacer par un fichier** |
| Ajouter les lignes d'un autre fichier | **Ajouter les lignes d'un fichier** : les colonnes de même nom se rejoignent, une colonne inconnue est créée |
| Récupérer la table | **Excel** ou **CSV** : le fichier contient toutes les lignes |

Le **type** d'une colonne (texte, nombre, date) sert à trier et à compter juste : un nombre
s'additionne, une date se compare.

**L'historique.** La page de la table dit quand elle a été créée et modifiée, et par qui. Chaque
changement — un fichier importé, des cellules corrigées, une colonne supprimée, une modification
faite par MIA ou par un workflow — s'inscrit dans son **Historique** : on y regarde la table telle
qu'elle était à un moment, et **Revenir à cet état** la remet ainsi, sans rien effacer. Voir [Voir
l'historique d'une table et revenir en arrière](revenir-en-arriere-dans-une-table.md).

## S'en servir dans une conversation

Une table se coche comme une base : le bouton des bases de connaissances, sous le champ de saisie,
la range sous **Tables**. Elle peut aussi être **liée à un assistant**, qui la lit pour tous ses
utilisateurs.

Posez ensuite votre question, sans rien préciser : *« combien de clients avons-nous à Paris ? »*.
MIA lit la table entière, compte ou filtre, et vous donne les chiffres. Un simple chiffre ne crée
rien : la réponse suffit.

**Voir la table.** Une table cochée se nomme dans la puce sous le champ de saisie (*« Clients
2026 »*, suivi du nombre de résultats de MIA s'il y en a) : cliquez-la pour l'ouvrir dans un volet à
droite, en tête, sous **Vos tables** — toutes ses lignes, cinquante par page, un champ **Filtrer les
lignes**, les boutons **Excel** et **CSV**, et **Ouvrir sa fiche**. Sous une réponse qui s'appuie
sur elle, **Voir** ouvre le même volet. Elle y porte la mention **Votre table** : vous la lisez là,
vous la tenez dans sa fiche.

**Quand vous demandez une liste, un tri, une sélection.** *« Donne-moi les clients de Paris »*,
*« trie la table par chiffre d'affaires »*, *« exporte en Excel les clients à relancer »* : MIA ne
copie pas votre table. Elle en fait une **vue** — votre table filtrée, triée ou réduite. Sous la
réponse, **Voir** l'ouvre avec un bandeau **Filtrée par MIA** qui dit ce qui est filtré et sur
combien de lignes, et **Tout afficher** revient à la table entière. Rien n'est copié : la vue suit
votre table, une ligne modifiée ou supprimée l'est aussi dans la vue, et l'export en Excel contient
les lignes du moment. Une vue que MIA nomme pour s'en resservir se range sous votre table, dans le
volet.

**Quand MIA établit autre chose.** Un regroupement (*« combien de clients par ville ? »*) ou un
recoupement avec vos emails donne une liste nouvelle : elle se range sous **Résultats de MIA**,
marquée **Temporaire** et « Tirée de « Clients 2026 » », et disparaît avec la conversation. Si votre
table change ensuite, ce résultat est marqué **Périmé** : demandez à MIA de le refaire.

**Faire compléter chaque ligne.** *« Ajoute une colonne Secteur et devine-le d'après le nom »*,
*« prépare une tâche de relance pour chaque client à relancer »* : MIA travaille directement sur
votre table (ou sur la vue concernée), sans copie. Les valeurs calculées sont proposées dans une
**carte** — rien n'est écrit avant votre accord ; les actions arrivent dans une seule carte, comme
pour une liste de la conversation.

Dans un **workflow**, une étape la lit de la même façon, une boucle la parcourt ligne par ligne, et
une livraison peut y écrire à la fin — voir [Utiliser des tables dans un
workflow](../workflows/utiliser-des-tables.md).

## Laisser MIA y écrire

MIA ne modifie jamais une table d'elle-même. Quand vous le lui demandez, elle prépare le changement
**dans la table elle-même** — elle n'en crée pas une autre — et vous montre une **carte**. Rien n'est
écrit tant que vous n'avez pas cliqué **Approuver**.

| Vous demandez… | La carte montre |
|---|---|
| *« Passe les clients de Lyon en statut « à relancer » »* | Les lignes visées, leur nombre, et quelques valeurs **avant** et **après** |
| *« Ajoute ce client : Dune, Lille, 3 000 € »* | Les lignes ajoutées |
| *« Supprime les lignes dont le statut est « perdu » »* | Les lignes visées et celles qui seront supprimées |
| *« Ajoute une colonne Priorité »*, *« renomme Statut en État »*, *« supprime la colonne Notes »* | La colonne créée, renommée ou supprimée |
| *« Note « relancé » pour les clients qui n'ont pas répondu »* (après un recoupement) | Les lignes retrouvées, avant et après, et celles qui ne se retrouvent pas |

Vérifiez sur la carte les **lignes visées** : c'est là que se lit ce que MIA a compris de votre
demande. Si ce n'est pas ça, cliquez **Annuler** et précisez.

Après l'accord, la table s'ouvre à jour par **Voir**, et la modification s'inscrit dans son
historique, au nom de MIA : on peut revenir à l'état d'avant depuis la page de la table.

Sur la fiche, section **MIA et assistants**, **MIA peut** règle ce qu'elle a le droit de proposer :

- **Lire seulement** — elle compte et croise, sans jamais proposer de modification.
- **Lire, et écrire après mon accord** — le réglage de départ.

Seules les personnes qui peuvent **modifier** la table se voient proposer une écriture. Les autres
la lisent.

## Limites

- Une table contient **20 000 lignes** et **40 colonnes** au plus. Un fichier plus long est
  **refusé**, avec le nombre de lignes trouvé — jamais coupé sans le dire.
- Vous pouvez tenir **10 tables**, en plus de vos bases de documents.
- Une vue n'a pas de limite de lignes (rien n'est copié). Un résultat (regroupement, croisement)
  reste dans la conversation, limité à 5 000 lignes : au-delà, MIA le dit, et un regroupement porte
  toujours sur tout.
- Pour enrichir ou agir ligne par ligne sur une table de plus de 5 000 lignes, demandez d'abord une
  sélection (une vue) : MIA travaille sur 5 000 lignes au plus à la fois.
- L'historique garde les états des 90 derniers jours (au moins les 30 plus récents, 60 au plus).
- MIA ajoute jusqu'à **200 lignes** dictées dans la conversation en une fois. Pour davantage,
  joignez un fichier : elle le range dans une table de la conversation, puis l'ajoute.
- Une table ne se synchronise pas avec un dossier, et n'accepte pas de documents : pour des
  procédures ou des contrats, créez une base.
- Avec un modèle qui ne sait pas se servir des outils, MIA ne voit qu'un **aperçu** de la table —
  les lignes qui citent votre question, sinon son début — et le dit : elle ne donne alors aucun
  total. Une étape de workflow, elle, la lit en entier (avec un modèle à outils).
- Dans une tâche programmée réglée sur **Agir sans validation**, une modification de table attend
  quand même votre accord.

## En cas de problème

- **La table est grisée, « Table vide »** : elle n'a aucune ligne. Ajoutez-en, ou importez un
  fichier.
- **« Cette table compterait … lignes »** : le fichier dépasse la limite. Réduisez-le, ou
  répartissez-le en deux tables.
- **« Voir » ouvre une liste filtrée** : c'est une vue de votre table, avec son bandeau **Filtrée
  par MIA**. **Tout afficher** revient à la table entière.
- **Un résultat est marqué Périmé** : votre table a changé depuis que MIA l'a établi. Demandez-lui de
  le refaire ; vous pouvez supprimer l'ancien dans le volet.
- **La puce ne nomme pas votre table** : elle n'est pas cochée pour cette conversation. Cochez-la
  avec le bouton des bases de connaissances.
- **MIA répond « rien à écrire »** : la table porte déjà ces valeurs, ou aucune ligne ne correspond
  à votre demande. Vérifiez l'orthographe de la valeur cherchée — ouvrez la table et filtrez-la.
- **« La colonne … n'existe plus dans la table »** : la colonne a été renommée ou supprimée entre la
  carte et votre accord. Redemandez la modification.
- **« La liste … a changé depuis la préparation »** : la modification reportait une liste de la
  conversation, qui a changé entre la carte et votre accord. Redemandez-la.
- **« … a changé depuis le calcul »** : des valeurs calculées par MIA (une colonne devinée pour
  chaque ligne) attendaient votre accord, et la table a changé entre-temps — une ligne ajoutée ou
  supprimée décalerait tout. Redemandez l'opération.
- **MIA ne propose pas d'écrire** : la table est réglée sur **Lire seulement**, ou vous n'avez pas
  le droit de la modifier.

## Voir aussi

- [Interroger ses bases de connaissances](interroger-ses-bases-de-connaissances.md)
- [Recouper beaucoup d'éléments](../connecteurs/recouper-des-sources.md)
- [Voir, renommer ou supprimer une table](../connecteurs/voir-ses-tables.md)
- [Voir l'historique d'une table et revenir en arrière](revenir-en-arriere-dans-une-table.md)
- [Réserver une base à vous seul](reserver-une-base.md)
- [Utiliser des tables dans un workflow](../workflows/utiliser-des-tables.md) : la lire en entier dans
  une étape, la parcourir ligne par ligne, y écrire le résultat
