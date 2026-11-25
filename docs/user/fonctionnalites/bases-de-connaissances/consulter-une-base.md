---
title: Consulter une base
description: La page d'une base ou d'une table — son état, ses documents ou ses lignes, ce qu'elle sait, qui s'en sert et qui la voit — et la liste qui la retrouve.
sidebar:
  order: 8
---

Chaque base a sa **page** : ce qu'elle contient, si elle est prête, qui s'en sert, qui la voit. On y
vérifie ce qu'elle sait avant de la confier à un assistant, sans rien envoyer à un modèle.

## Avant de commencer

- Voir la base : l'avoir créée, la recevoir par un partage ou un groupe, ou qu'elle soit
  **Publique**. Une base **Privée** d'un autre n'apparaît jamais, pas même à un administrateur.

## Retrouver une base

1. Tuile **Bases** de la barre latérale, dans la rangée **Espaces**.
2. Tapez dans la recherche, en tête de page — **/** y met le curseur. Elle trouve un nom, une
   description, le nom d'un document, d'un créateur ou d'un assistant lié, sans tenir compte des
   majuscules ni des accents. **Entrée** ouvre la base quand il n'en reste qu'une.
3. Resserrez si besoin : **Tout**, **Bases** ou **Tables**, et les icônes **Privées**, **Communes**,
   **Publiques**.

Chaque ligne porte la source de la base (fichiers, dossier synchronisé, cerveau MyBrain, table), son
état, ses documents ou ses lignes, sa dernière utilisation et l'icône de sa visibilité : cadenas
(**Privée**), groupe (**Commune**), globe (**Publique**). Le survol de l'icône dit qui la voit.

## Lire la page d'une base

Cliquez la ligne. La page montre :

- **en tête** : le nom, la description, l'état (**Prête**, **Indexation en cours**, « 2 documents en échec »…),
  la visibilité, la source et la dernière utilisation ; **Modifier** ouvre sa fiche, **Interroger
  dans le chat** ouvre une conversation où la base est déjà cochée ;
- **les chiffres** : documents, documents interrogeables, extraits, utilisations — pour une table,
  ses lignes sur 20 000, ses colonnes et ce que MIA peut y faire ;
- **Que sait-elle ?** : tapez une question ou des mots-clés ; les extraits qui les contiennent
  s'affichent, mots surlignés, avec leur document. Rien n'est envoyé à un modèle : c'est une
  vérification gratuite ;
- **les documents**, onglets **Tous**, **Indexés**, « À vérifier » et **Exclus**, ou **les lignes**
  d'une table. Chaque document dit quand il a été indexé pour la dernière fois (« indexé le
  19/11/2026 14:32 », dans l'heure de votre appareil). Cliquez un document : sa **fiche** s'ouvre dans un volet — ses étapes, ses extraits
  et, s'il pose problème, pourquoi et quoi faire. Voir
  [Corriger un document en échec](corriger-un-document-en-echec.md) ;
- **à droite** : la source et son état (**Synchroniser maintenant** pour qui gère la base), les
  assistants qui s'en servent, et qui la voit ;
- **tout en bas**, la **Vue d'ensemble** : le schéma de la base, décrit dans
  [Créer une base de connaissances](creer-une-base-de-connaissances.md).

L'état et la progression se mettent à jour seuls pendant une indexation ou une synchronisation.

## Exemples

**Vérifier avant de lier.** Dans *Conditions générales*, tapez *pénalités de retard* : le passage
de l'article 9 s'affiche. La base sait répondre ; vous la liez à l'assistant *Commercial*.

**Repérer une base qui ne sert à rien.** La ligne dit « utilisée il y a 4 mois » et la page ne
compte aucun assistant : désactivez-la ou supprimez-la depuis sa fiche.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Que sait-elle ? » ne trouve rien | La recherche cherche les mots tels quels. Essayez d'autres termes : MIA, elle, cherche aussi par le sens |
| **Modifier** n'apparaît pas | Vous ne gérez pas cette base : elle vous est partagée en lecture, ou elle est publique |
| Une base attendue manque à la liste | Elle ne vous est pas partagée, ou elle est **Privée** à son créateur |
| Un document reste « À vérifier » | Ouvrez sa fiche : elle dit pourquoi, et propose de le relancer ou de l'exclure |

## Voir aussi

- [Corriger un document en échec](corriger-un-document-en-echec.md)
- [Créer une base de connaissances](creer-une-base-de-connaissances.md)
- [Interroger ses bases de connaissances](interroger-ses-bases-de-connaissances.md)
- [Créer et tenir une table](tenir-une-table.md)
