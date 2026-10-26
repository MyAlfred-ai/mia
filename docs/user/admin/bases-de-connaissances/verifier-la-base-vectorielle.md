---
title: Vérifier la base vectorielle
description: Contrôler que le moteur d'indexation répond et que chaque base est opérationnelle, et savoir quand appeler MyAlfred.
sidebar:
  order: 3
---

**Administration → Maintenance → Diagnostic → Moteur vectoriel.** La base vectorielle est
le moteur qui retrouve les extraits par le sens. Cette page est un **diagnostic** : rien ne s'y règle.

## Lire la page

En tête, l'état du moteur : **En ligne**, avec le nombre de collections et de vecteurs, ou
**Injoignable**.

Dessous, une ligne par base de connaissances, avec son verdict et son volume — *N* extraits, *N*
vecteurs :

| Verdict | Signification | Quoi faire |
|---|---|---|
| **Opérationnelle** | Tout va bien | Rien |
| **Vectorisation en cours** | Indexation en train de se faire | Patienter |
| **Base vide** | Aucun document | Rien, ou prévenir son créateur |
| **N documents en échec** | Des fichiers n'ont pas pu être lus | Les faire redéposer par le créateur |
| **Vectorisation incomplète**, **Aucun vecteur** | L'indexation s'est interrompue | **Réindexer** |
| **Collection absente**, **Dimension incohérente** | Le moteur a perdu ou mélangé des données | **Réparer**, puis **Réindexer** |
| **Moteur injoignable** | Le service ne répond pas | Contacter MyAlfred |
| **Collection orpheline** | Des données sans base associée | **Purger les orphelines** |

**Réindexer** n'apparaît que sur les verdicts qui le justifient.

## Les boutons

- **Revérifier** relance le diagnostic.
- **Réparer** recrée les collections manquantes. Il faut ensuite réindexer les bases concernées.
- **Purger les orphelines** supprime les données sans base associée. **Irréversible.**

## Pendant une panne

Si le moteur est injoignable, les conversations continuent : MIA cherche dans les bases **par les
mots** plutôt que par le sens. Les réponses sont moins pertinentes, mais personne n'est bloqué. Au
retour du moteur, **Revérifier**, puis **Réindexer** les bases qui affichent **Aucun vecteur**.

## Ce qui relève de MyAlfred

Le service du moteur, sa mémoire et son espace disque sont assurés par MyAlfred. Contactez-nous si le
moteur reste **Injoignable**, ou si **Réparer** ne rétablit pas les collections. Joignez le rapport
de **Maintenance → Diagnostic** — voir
[Consulter les logs et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md).
