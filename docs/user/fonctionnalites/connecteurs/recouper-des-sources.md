---
title: Recouper beaucoup d'éléments
description: Faire travailler MIA sur des centaines de mails, de fichiers ou de lignes d'un classeur — tout collecter, croiser deux sources, compter, et recevoir la liste complète en Excel.
sidebar:
  order: 3
---

Pour une demande qui porte sur **tout** — tous les mails d'un client, tous les fichiers d'un dossier,
chaque ligne d'un classeur — MIA ne lit pas les éléments un par un : elle les range dans une
**liste** gardée par la conversation (un **résultat de MIA**), puis croise, filtre et compte
dessus. Rien n'est oublié en route, et les chiffres de sa réponse sont comptés, pas estimés.

## À quoi ça sert

- *« Parmi les clients de ce fichier, lesquels ne m'ont pas écrit depuis septembre ? »*
- *« Liste tous les fichiers du dossier Contrats 2026, par sous-dossier, avec leur date. »*
- *« Combien de mails ai-je reçus de chaque fournisseur ce trimestre ? »*
- *« Dédoublonne ce classeur sur l'adresse email et dis-moi combien il reste de lignes. »*

## Avant de commencer

- Pour collecter des **mails** ou des **fichiers Drive** : le service est relié et son connecteur
  allumé dans le menu **+** — ou **Laisser MIA choisir** l'est. Voir
  [Utiliser un connecteur](utiliser-un-connecteur.md).
- Pour travailler sur un **classeur** (Excel, CSV, Google Sheets) : joignez-le à la conversation, ou
  désignez-le dans votre Drive. Un classeur joint de plus d'une centaine de lignes est rangé dans
  une table **d'office**, sans que vous ayez à le demander : MIA compte alors sur toutes ses lignes,
  au lieu d'en lire le début.
- Pour travailler sur une **table permanente** (un fichier clients gardé dans vos bases) : cochez-la
  avec le bouton des bases de connaissances. Voir
  [Créer et tenir une table](../bases-de-connaissances/tenir-une-table.md).
- Le modèle de la conversation sait se servir des connecteurs : le bandeau affiche **✓ Connecteurs**.

## Étapes

1. Écrivez votre demande en disant **ce qu'il faut tout prendre** et **ce que vous voulez savoir** :
   *« prends tous les mails reçus depuis le 1er septembre et dis-moi quels clients du fichier joint
   n'ont pas écrit »*.
2. MIA collecte. La pile d'actions le montre, avec le nombre de lignes :
   « Collecte dans une liste — Emails Gmail → « mails » (412 lignes) ». Une longue
   collecte affiche son avancement ; **Stop** l'arrête.
3. Elle croise et compte : « Croisement de deux tables », « Liste tirée d'une table » — ou « Calcul
   sur une table » quand elle ne fait que compter, sans rien créer. Sur une table permanente, un
   filtre ou un tri donne une « Vue filtrée d'une table » : rien n'est copié.
4. Sa réponse donne les chiffres et quelques lignes utiles — pas la table entière, pour que la
   conversation reste lisible. Un simple chiffre (*« combien de mails ce mois-ci ? »*) ne crée pas
   de liste de plus : la réponse suffit. Sous la réponse, une ligne nomme la liste : cliquez sur **« Voir »**
   pour l'ouvrir dans un volet à droite, la parcourir, la filtrer et la télécharger en Excel ou en
   CSV. Vous pouvez aussi demander le fichier : *« exporte-moi la liste en Excel »* — un lien de
   téléchargement arrive dans la réponse.

## Ce que MIA sait collecter

| Source | Ce qui est rangé dans la table |
|---|---|
| **Emails Gmail**, **Emails Outlook** | Tous les mails d'une recherche : date, expéditeur, adresse, destinataires, objet, extrait |
| **Fichiers Google Drive** | Tous les fichiers d'un dossier, sous-dossiers compris si vous le demandez : nom, type, dossier, date, taille, lien |
| **Un classeur de votre Drive** | Chaque ligne d'un fichier Excel, CSV ou Google Sheets, avec ses colonnes |
| **Un classeur joint à la conversation** | Chaque ligne du fichier joint, avec ses colonnes |

Gmail et Outlook se réunissent dans une même table. MIA peut aussi y écrire une liste qu'elle a
établie elle-même.

## Ce qu'elle sait en faire

- **Croiser** deux tables : par adresse email, par domaine (`@acme.fr`), par nom exact, ou quand un
  nom figure dans un texte (le nom d'un client dans l'objet d'un mail). Elle obtient ceux qui
  correspondent — ou **ceux qui manquent**.
- **Filtrer**, **dédoublonner**, **trier** : par texte, par nombre, par date.
- **Regrouper et compter** : combien par client, par ville, par mois ; une somme, un minimum, la
  date la plus récente.
- **Exporter** en Excel ou en CSV.
- **Lire chaque ligne** pour la classer, la résumer ou y relever une valeur, puis **agir sur
  chacune** (un brouillon, une tâche par ligne) : voir
  [Classer, compléter et agir ligne par ligne](traiter-chaque-ligne.md).

## Résultat

Les listes restent dans la conversation : vous pouvez enchaîner — *« et parmi ceux-là, lesquels sont
à Lyon ? »*, *« ajoute les mails d'Outlook »* — sans que MIA recommence la collecte. La puce sous
le champ de saisie les retrouve toutes, rangées sous **Résultats de MIA** dans le volet : voir
[Voir, renommer ou supprimer une table](voir-ses-tables.md). Elles disparaissent avec la
conversation — sauf celles que vous **gardez** : le bouton **Garder** du volet en range une copie
permanente dans vos bases de connaissances. Une liste tirée d'une table permanente qui a changé
depuis est marquée **Périmé** : demandez à MIA de la refaire.

Le fichier exporté est fabriqué au moment où vous cliquez : il reflète la table telle qu'elle est à
cet instant.

## Limites

- Une collecte rapporte **1 000 mails** ou **2 000 fichiers** au plus, une table **5 000 lignes**,
  une conversation **20 tables**. Au-delà, MIA le dit et vous propose de resserrer la demande (une
  période, un expéditeur, un sous-dossier). Pour libérer de la place, supprimez dans le volet les
  tables qui ne servent plus.
- La table d'emails garde l'**extrait** de chaque mail, pas son texte entier ni ses pièces jointes.
  Pour le contenu d'un mail précis, demandez-le : MIA l'ouvre.
- MIA ne lit que ce que **vous** voyez : vos mails, les fichiers auxquels vous avez accès.
- Collecter, croiser, compter et exporter sont de la lecture : rien n'est modifié dans votre
  messagerie ni dans votre Drive. Seule une action par ligne crée quelque chose, et jamais sans
  votre accord.
- Une table trop grande pour un classeur Excel est remise en CSV, qui s'ouvre dans Excel.

## En cas de problème

- **« La table est incomplète »** : la collecte a atteint sa limite, ou vous l'avez arrêtée. Précisez
  la période ou l'expéditeur, ou demandez explicitement plus d'éléments.
- **Aucune correspondance au croisement** : les deux colonnes ne se ressemblent pas (un nom d'un
  côté, une adresse de l'autre). Dites à MIA sur quoi rapprocher : *« rapproche par le domaine de
  l'adresse »*.
- **MIA répond sans collecter** : le connecteur n'est pas allumé, ou le modèle de la conversation ne
  sait pas s'en servir. Vérifiez le menu **+** et le bandeau.

## Voir aussi

- [Classer, compléter et agir ligne par ligne](traiter-chaque-ligne.md)
- [Voir, renommer ou supprimer une table](voir-ses-tables.md)
- [Créer et tenir une table](../bases-de-connaissances/tenir-une-table.md)
- [Utiliser un connecteur](utiliser-un-connecteur.md)
- [Joindre des fichiers](../conversations/joindre-des-fichiers.md)
- [Télécharger un fichier créé par MIA](../conversations/telecharger-un-fichier.md)
