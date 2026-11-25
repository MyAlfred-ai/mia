---
title: Utiliser des tables dans un workflow
description: Faire lire une table de vos bases en entier par une étape, la parcourir ligne par ligne, et y écrire le résultat du workflow — sans que MIA recopie jamais des centaines de lignes.
sidebar:
  order: 10
---

Une **table** de vos bases de connaissances (un fichier clients, une grille de tarifs, un suivi de
commandes) se branche sur un workflow de quatre façons :

- une **étape** la lit **en entier** : elle compte, filtre, regroupe ou croise ses lignes, et peut
  remettre le résultat comme une pièce **Tableau** — même de plusieurs milliers de lignes ;
- une **boucle** la parcourt **ligne par ligne** : une relance par client, une fiche par commande ;
- une **livraison** y **écrit** à la fin : ajouter des lignes, mettre à jour celles qui existent
  (un statut, une date de relance), ou tout remplacer ;
- une **condition** ou une **décision** la consulte pour trancher (« ce client est-il dans la table
  des comptes bloqués ? »).

## Avant de commencer

- Créez la table dans **Bases de connaissances → Nouvelle table** (voir [Tenir une
  table](../bases-de-connaissances/tenir-une-table.md)).
- Pour la lire en entier, l'étape doit travailler avec un modèle qui sait **utiliser les outils**
  (ceux qui pilotent les connecteurs). Sinon, elle n'en voit qu'un aperçu, et **À régler** vous le
  dit.
- Pour y écrire, il faut pouvoir **modifier** la table et que sa fiche autorise l'écriture (**MIA
  peut → Lire, et écrire après mon accord**).

## Une étape qui lit une table

1. Touchez l'étape → **Peut consulter** → cochez la table (elle porte la mention **Table · N
   lignes**).
2. Écrivez la consigne normalement : *« Liste les clients de Paris qui doivent plus de 100 € »*,
   *« Combien de commandes par mois ? »*.
3. Si la pièce remise est un **Tableau**, l'étape peut la remettre **sans recopier ses lignes** :
   elle désigne la table qu'elle a établie, et la pièce reçoit toutes ses lignes (jusqu'à
   5 000). Rien à régler : MIA le fait d'elle-même.

L'étape travaille dans la conversation de l'exécution : les tables qu'elle y établit restent
disponibles pour les **étapes suivantes**, et une pièce Tableau lue par une étape lui est aussi
présentée comme une table — elle peut donc la compter et la filtrer en entier, même si son texte est
trop long pour le prompt. Vous les retrouvez dans le volet **Tables** de la conversation.

## Une boucle qui parcourt une table

1. Ajoutez une **boucle** → **Lignes d'un tableau ou d'un Excel** → **Une table de vos bases**.
2. Choisissez la table : ses **colonnes** deviennent les champs de l'élément, à citer avec **@**
   (`@[Client en cours › Email]`) et à filtrer (**Quels éléments garder ?**).
3. La table est **relue au lancement de la boucle**, avec vos droits : ce sont ses lignes du moment
   qui sont parcourues.

## Écrire dans une table à la fin

1. Touchez le **livrable** → **Puis le livrable part** → **Écrire dans une table**.
2. Choisissez la **table**, puis la **pièce à écrire** : un **Tableau** (ses lignes), une **Fiche**
   (une ligne, ses champs en colonnes) ou un classeur Excel.
3. Choisissez **comment l'écrire** :
   - **Ajouter les lignes** ;
   - **Mettre à jour les lignes** : choisissez la **colonne qui identifie une ligne** (Email,
     Référence…) ; les autres colonnes de la pièce sont reportées sur les lignes retrouvées. Cochez
     **Ajouter les lignes introuvables** pour compléter la table ;
   - **Tout remplacer** : chaque exécution remplace tout le contenu de la table.
4. Choisissez **avant d'écrire** : **Je valide avant** (une carte montre les lignes touchées, avant
   et après) ou **Sans validation** (si votre administrateur le permet).

Une colonne de la pièce absente de la table y est **ajoutée**. L'écriture s'inscrit dans
l'**historique** de la table, au nom du workflow : on peut revenir à l'état d'avant depuis la page de
la table — voir [Voir l'historique d'une table et revenir en
arrière](../bases-de-connaissances/revenir-en-arriere-dans-une-table.md).

## Exemple

**Relancer les clients qui doivent de l'argent** : une boucle parcourt la table **Clients 2026**,
garde les lignes où **Montant dû** est supérieur à 0, rédige une relance pour chacun et remet une
Fiche *(Email, Statut)* ; à la fin, une livraison **met à jour** la table sur la colonne **Email** :
chaque client relancé y porte « Relancé le 17/11 ».

## À savoir

- Une étape d'un workflow ne lance pas d'**enrichissement** ligne par ligne en arrière-plan, ni
  d'**action par ligne** : utilisez une **boucle** pour lire ou traiter chaque ligne.
- Dans une étape, une écriture dans une table (« note le statut dans la table ») suit le réglage
  **Ce qu'elle peut faire dans les outils** : lecture seule, carte à approuver — jamais sans votre
  accord, même réglée sur **Agir sans validation**.
- Les filtres d'une étape (expéditeur, objet, dossier Drive…) s'appliquent aussi quand elle range
  des emails ou des fichiers dans une table.
- Une condition ou une décision qui consulte une table lit d'abord les lignes qui **citent sa
  question** (une adresse, une référence, une ville).
- Une table retirée, désactivée ou qui ne vous est plus partagée arrête la boucle **avant le premier
  tour**, sans rien dépenser ; une table passée en lecture seule fait échouer sa livraison, avec la
  cause.
- Si une colonne a été renommée ou supprimée depuis la publication, la boucle s'arrête avant de
  commencer et nomme les colonnes trouvées : corrigez-les dans l'éditeur, publiez, puis reprenez.

## En cas de problème

- **« ne sera lue qu'en aperçu »** dans **À régler** : choisissez pour l'étape un modèle qui sait
  utiliser les connecteurs.
- **La table n'apparaît pas dans la liste d'une livraison** : vous ne la gérez pas, ou sa fiche ne
  permet pas l'écriture par MIA.
- **La livraison dit « rien à écrire »** : la table porte déjà ces valeurs, ou aucune ligne ne se
  retrouve par la colonne choisie — vérifiez que la pièce a bien une colonne de ce nom.
