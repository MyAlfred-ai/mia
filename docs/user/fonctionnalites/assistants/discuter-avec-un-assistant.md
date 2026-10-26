---
title: Discuter avec un assistant
description: Trouver un assistant, démarrer une conversation avec lui et savoir ce qu'il sait faire.
sidebar:
  order: 1
---

Un assistant se choisit au moment de démarrer une conversation. Ce qu'il est, et ce qu'il impose :
[Les assistants](index.md).

## Démarrer avec un assistant

Deux chemins, au choix :

- **La liste** : cliquez l'icône **tête de robot** sous le champ de saisie. La liste **Démarrer avec
  un assistant** s'ouvre, en deux sections : **👤 Mes assistants personnels** puis **🏢 Assistants
  de l'organisation**. Le badge **RAG** signale un assistant relié à une base de connaissances.
- **Le clavier** : tapez **@** puis les premières lettres du nom. La liste se filtre au fil des
  lettres, sans tenir compte des majuscules ni des accents. **↑** et **↓** la parcourent, **Entrée**
  ou **Tab** valide, **Échap** la referme.

Dans les deux cas, **une nouvelle conversation s'ouvre** avec l'assistant. Le texte déjà tapé est
conservé — le « @nom » en moins — et **rien n'est envoyé** : votre message vous attend dans le
champ de saisie.

Si vous aviez déjà coché des bases de connaissances, elles suivent dans la nouvelle conversation.

## Reconnaître l'assistant engagé

Tant que la conversation est vide, une carte au centre de l'écran le présente : avatar, nom,
description.

Ensuite, il se présente sur la première ligne du champ de saisie : son avatar, la mention
**Assistant** et son nom. La bordure du champ prend sa couleur — c'est ce contour qui dit à qui vous
écrivez. La même couleur marque la conversation dans la barre latérale.

## Voir ce qu'il sait faire

Cliquez son nom dans le champ de saisie. Ses caractéristiques se déplient :

| Ligne | Ce qu'elle dit |
|---|---|
| Description | Son rôle, tel que son créateur l'a décrit |
| Modèle | Le modèle qu'il impose — c'est pour cela que le sélecteur de modèle a disparu |
| Outils | Ce qu'il peut produire lui-même : image, présentation, automatisation |
| Bases de connaissances | Le nombre de bases qu'il consulte |

Un bouton **Modifier** apparaît si vous gérez cet assistant.

Pendant qu'il répond, les étapes **Recherche dans mes documents de référence…** et **Recherche dans
les bases de connaissances…** indiquent qu'il consulte ses sources.

## Changer d'assistant

On ne change pas d'assistant dans une conversation : en choisir un autre ouvre un nouveau fil.
**+ Nouvelle conversation** ramène à l'écran d'accueil **sans** assistant, en conversation standard.

## Cas particuliers

- **Ajouter vos propres bases.** Le bouton **livre ouvert** permet d'ajouter jusqu'à trois bases à
  celles de l'assistant — voir
  [Interroger ses bases de connaissances](../bases-de-connaissances/interroger-ses-bases-de-connaissances.md).
- **Allumer un connecteur.** Si le modèle imposé ne sait pas consulter vos données, MIA l'indique et
  propose d'ouvrir une conversation sans assistant.
- **L'assistant d'aide.** **MIA : mode d'emploi** est proposé à tout le monde. Il répond aux
  questions sur l'usage de MIA.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Le sélecteur de modèle a disparu | C'est normal : l'assistant impose son modèle. |
| Pas d'icône **tête de robot** sous le champ | Aucun assistant ne vous est encore accessible. Voir avec un administrateur. |
| Un assistant attendu est introuvable | Il est inactif, ou il n'est pas autorisé dans l'un de vos groupes. Voir avec un administrateur. |
| « Aucun assistant ne correspond » après **@** | Aucun nom ne commence par ces lettres. **Entrée** envoie alors le message tel quel. |
| Une ancienne conversation a perdu son assistant | L'assistant a été désactivé, retiré de vos groupes ou supprimé. La conversation continue en mode standard. |
