---
title: La gestion des crédits
description: L'unité dans laquelle se compte tout ce que vous consommez, votre quota mensuel, et où suivre l'un et l'autre.
sidebar:
  order: 8
---

Le **crédit** est l'unité dans laquelle MIA compte tout ce que vous consommez : chaque réponse,
chaque recherche Internet, chaque image générée. Votre **quota** est le nombre de crédits dont vous
disposez chaque mois, fixé par votre administrateur.

## À quoi ça sert

Les services d'IA sont payants, et leurs prix varient d'un facteur cent d'un modèle à l'autre. Le
crédit ramène tout à une seule unité : vous savez ce que coûte une réponse, ce qui vous reste, et ce
qui a consommé votre quota. Votre organisation, elle, maîtrise son budget.

## Comment ça marche

**Ce qui consomme.** Tout ce qu'un message déclenche est compté :

| Poste | Ce qu'il comprend |
|---|---|
| **Conversations** | Les réponses et les régénérations |
| **Assistance de conversation** | Le titre automatique, la mémorisation sur « souviens-toi », la préparation d'une demande d'image, la recherche dans les bases de connaissances, le résumé du début d'une très longue conversation, la synthèse d'un résultat volumineux, la suite proposée dans la zone de saisie |
| **Recherche Internet** | Chaque recherche effectuée, en plus de la réponse |
| **Créations par outil** | Images, vidéos, pages web, itinéraires, présentations |
| **Voix** | Les minutes dictées ou écoutées, et les minutes lues à voix haute en conversation vocale |
| **Conception d'assistants** | L'aide à la rédaction des consignes d'un assistant |

**Le prix d'une opération** dépend de ce qu'elle mobilise : la longueur de la question et de la
réponse, le tarif du modèle, le nombre d'images. Une conversation longue coûte plus cher à chaque
message, parce que tout l'historique est relu. Un modèle puissant coûte plus qu'un modèle rapide.
Les pages lues pendant une recherche Internet comptent comme du texte relu, et une réponse arrêtée
avec le bouton carré compte ce qu'elle a déjà lu et écrit.

**Le hors quota.** Certaines opérations sont comptées sans être retirées de votre quota : alimenter
une base de connaissances, tester un outil d'administration. Elles apparaissent à part, marquées
**hors quota**.

**Le quota.** Il vaut pour le mois calendaire : le 1er, vous repartez avec votre quota plein. Les
crédits non utilisés ne se reportent pas. Quand le quota est atteint, les nouvelles demandes sont
refusées jusqu'au mois suivant, ou jusqu'à ce qu'un administrateur l'augmente. Sans quota, vous êtes
**Illimité**.

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Savoir où vous en êtes, ce qui a consommé vos crédits, et que faire quand le quota est épuisé | [Suivre sa consommation](suivre-sa-consommation.md) |

## Ce que vous pouvez régler

Rien sur le crédit lui-même : sa valeur, les tarifs et votre quota sont fixés par votre
administrateur. Vous agissez sur votre consommation par vos choix :

- le **modèle** — voir le repère **€** du sélecteur, dans [Les modèles de LLM](../modeles/index.md) ;
- la **longueur des conversations** — une nouvelle conversation repart sans historique à relire ;
- les **outils** — une vidéo de 12 secondes coûte trois fois celle de 4 secondes.

## Exemples

**Comprendre l'étiquette d'une réponse.** Sous la réponse : « · 9 crédits · 4,2 s ». Les 9 crédits
comprennent la réponse et les deux recherches Internet qu'elle a demandées.

**Repérer ce qui coûte.** Sur la page **Usage & crédits**, le **Top 10** du mois montre que trois vidéos
représentent la moitié de la consommation : c'est là que se jouent les économies.

**Anticiper la fin du mois.** Le 12, la page **Usage & crédits** annonce « À ce rythme, quota atteint vers le
24 ». Le camembert **Par modèle** montre que le modèle le plus puissant fait l'essentiel de la
consommation : vous le réservez aux analyses qui le méritent.

**Alimenter une base sans entamer son quota.** Un créateur dépose vingt documents dans une base : la
consommation apparaît dans **Usage & crédits**, marquée **hors quota** ; son quota de conversation est
intact.

## Voir aussi

- [Les modèles de LLM](../modeles/index.md) — le premier facteur de coût
- [Les outils](../outils/index.md) — ce que coûte une création
- [Les bases de connaissances](../bases-de-connaissances/index.md) — l'alimentation, hors quota
