---
title: Voir, renommer ou supprimer une table
description: Ouvrir dans le volet de la conversation votre table cochée, ses vues filtrées par MIA, ou un résultat établi par MIA — la parcourir, la filtrer, la télécharger, changer son nom, la garder ou la supprimer.
sidebar:
  order: 4
---

Une table ne s'affiche jamais d'elle-même dans la conversation : le fil reste lisible. Vous
l'ouvrez quand vous le voulez, dans un volet à droite, qui sépare deux choses :

- **Vos tables** : les tables permanentes cochées pour la conversation — un fichier clients gardé
  dans vos bases de connaissances, par exemple —, chacune suivie de ses **vues** : la même table
  filtrée, triée ou réduite par MIA, sans rien copier ;
- **Résultats de MIA** : les listes que MIA a établies dans la conversation
  ([Recouper beaucoup d'éléments](recouper-des-sources.md)) — une collecte d'emails, un
  recoupement, un regroupement. Elles sont **temporaires** : elles disparaissent avec la
  conversation.

## À quoi ça sert

- Vérifier ce que MIA a réellement lu ou collecté avant de vous fier à ses chiffres.
- Parcourir la liste complète, ou y chercher un nom, sans rien redemander.
- Télécharger une table, une vue ou un résultat en Excel ou en CSV.
- Donner un nom clair à un résultat, ou le supprimer pour faire de la place.
- Garder un résultat comme table permanente, dans vos bases de connaissances.

## Avant de commencer

La conversation compte au moins une table : une table permanente cochée avec le bouton des bases
de connaissances, ou un résultat que MIA a établi pour un travail complet sur beaucoup d'éléments.
Une conversation sans table n'affiche rien de plus.

## Étapes

1. Sous la réponse de MIA, une ligne nomme ce qu'elle vient de lire ou d'établir, avec son nombre
   de lignes — *« Clients 2026 › Clients à relancer · 5 lignes »* : votre table d'abord, puis ce
   que MIA en a tiré. Cliquez sur **« Voir »**. Si la réponse s'appuie sur plusieurs tables,
   **+ N autres tables** ouvre le volet sur leur liste.
2. À tout moment, la puce sous le champ de saisie ouvre le même volet. Elle nomme votre table
   (*« Clients 2026 · 2 résultats »*), ou compte les résultats de MIA s'il n'y en a pas. Un second
   clic la referme.
3. En haut du volet, **Vos tables**, puis **Résultats de MIA** (cliquez sur ce titre pour déplier
   ou replier la liste). Cliquez sur une table pour la voir. Sous son nom figurent son nombre de
   lignes, de colonnes, et d'où elle vient.
4. Parcourez les lignes : cinquante par page, les flèches en bas passent à la suite. Le numéro à
   gauche est le rang de la ligne dans la table.
5. Tapez dans **Filtrer les lignes** pour ne garder que celles qui contiennent vos mots — sans tenir
   compte des majuscules ni des accents. Le bas du volet dit combien de lignes sont trouvées.

## Une vue : votre table, filtrée par MIA

Quand vous demandez *« les clients de Lyon pas contactés depuis septembre »*, *« trie la table par
chiffre d'affaires »* ou *« exporte les clients à relancer »*, MIA ne copie pas votre table : elle
en fait une **vue**. Un bandeau **Filtrée par MIA** dit ce qu'elle filtre (*« Ville = Lyon ·
Dernier contact < 2026-09-01 — 5 lignes sur 40 »*) ; **Tout afficher** revient à la table entière.
Rien n'est copié : la vue suit votre table — une ligne modifiée ou supprimée l'est aussi dans la
vue, et un export contient les lignes du moment.

## Ce que vous pouvez faire d'une table

| Geste | Où | Effet |
|---|---|---|
| **Télécharger** | Boutons **Excel** et **CSV** | Le fichier est fabriqué au moment du clic : il contient toute la table (ou toute la vue), pas seulement la page affichée |
| **Renommer** | Crayon à côté du nom *(résultats et vues)* | Change le nom affiché, dans le volet et sous les réponses. MIA reconnaît toujours la liste |
| **Garder** | Bouton **Garder** *(créateurs et administrateurs)* | Range une copie permanente dans vos bases de connaissances, sous le nom que vous lui donnez. Elle ne disparaît plus avec la conversation, se partage et se coche ailleurs. Le résultat de la conversation ne change pas |
| **Supprimer** | Bouton **Supprimer** *(résultats et vues)* | Efface le résultat et toutes ses lignes, après confirmation, sans retour possible. Supprimer une vue ne touche pas votre table |

**Votre table** se lit dans le volet — lignes, filtre, **Excel**, **CSV** — et se tient dans sa
fiche : **Ouvrir sa fiche** y mène. Elle ne se renomme pas, ne se supprime pas et ne se « garde »
pas d'ici. Décochez-la avec le bouton des bases de connaissances pour la retirer de la
conversation ; elle reste dans vos bases.

**Garder un résultat tiré de votre table.** Le formulaire de **Garder** prévient qu'une **nouvelle**
table permanente naît, à côté de la vôtre, qui ne change pas. Si le résultat apporte des colonnes
que votre table n'a pas (une colonne **Secteur** devinée par MIA, par exemple) et que vous pouvez
la modifier, il propose plutôt **Ajouter la colonne « Secteur » à « Clients 2026 »** : les lignes
sont rapprochées par une colonne qui les identifie (l'email, une référence), une confirmation dit
combien, et le changement s'inscrit dans l'historique de votre table — on peut revenir en arrière.

**Un résultat périmé.** Un résultat tiré de votre table est marqué **Périmé** quand la table a
changé depuis (une ligne modifiée, ajoutée ou supprimée) : ses chiffres ne sont plus ceux de la
table. Demandez à MIA de le refaire.

## Résultat

Le volet se ferme par la croix, par la puce, ou en ouvrant un autre volet (une page web, une
présentation). La conversation n'a pas bougé : vous pouvez continuer à écrire pendant qu'il est
ouvert, et il se met à jour quand MIA modifie une table.

## Limites

- Une conversation garde **20 résultats ou vues** au plus. Supprimez ceux qui ne servent plus pour
  en libérer : sinon MIA doit réutiliser le nom d'un résultat existant, qu'elle remplace.
- Un résultat vit avec sa conversation : supprimer la conversation le supprime. Pour le conserver,
  **gardez-le** : voir [Créer et tenir une table](../bases-de-connaissances/tenir-une-table.md).
- Le volet **montre** la table ; il ne permet pas d'en modifier les lignes. Pour la changer,
  demandez-le à MIA : *« retire les clients de Lyon »*, *« ajoute une colonne Statut »*.
- Une vue se lit tant que sa table est cochée pour la conversation.
- Les colonnes d'identifiants internes (celles qui servent à MIA pour retrouver un mail ou un
  fichier) ne sont pas affichées.
- Une cellule très longue est coupée à l'écran ; survolez-la pour lire le texte entier. Le fichier
  téléchargé le contient en entier.
- Pendant qu'une opération en arrière-plan travaille sur les tables de la conversation, la
  suppression attend sa fin.

## En cas de problème

- **Pas de bouton « Voir » sous la réponse** : MIA a répondu sans rien lire ni établir — un simple
  chiffre (*« combien de clients à Lyon ? »*) donne seulement la réponse —, ou le résultat a été
  supprimé depuis.
- **« Voir » ouvre une liste filtrée** : c'est une vue de votre table. **Tout afficher** revient à
  la table entière.
- **« Cette vue ne s'applique plus »** : une colonne qu'elle filtrait a été renommée ou supprimée
  dans votre table. Redemandez la liste à MIA.
- **La puce n'apparaît pas** : la conversation ne compte aucune table.
- **« Cette table n'existe plus »** : elle a été supprimée ou remplacée entre-temps. Rouvrez le
  volet par la puce.

## Voir aussi

- [Recouper beaucoup d'éléments](recouper-des-sources.md)
- [Classer, compléter et agir ligne par ligne](traiter-chaque-ligne.md)
- [Créer et tenir une table](../bases-de-connaissances/tenir-une-table.md)
- [Télécharger un fichier créé par MIA](../conversations/telecharger-un-fichier.md)
