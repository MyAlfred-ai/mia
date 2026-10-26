---
title: Suivre la consommation
description: Lire le tableau de bord et les statistiques d'usage, repérer ce qui coûte, exporter un rapport et estimer le mois.
sidebar:
  order: 3
---

Deux écrans : le **Tableau de bord**, pour la vue du mois et les alertes, et **Consommation**, pour
l'analyse.

## Le tableau de bord

**Administration → Tableau de bord.**

- **Six indicateurs** : **Crédits ce mois-ci**, **Coût réel IA ce mois-ci**, **Valorisation des
  crédits**, **Projection fin de mois**, **Requêtes ce mois-ci**, **Utilisateurs actifs ce mois-ci**.
  La projection prolonge le rythme du mois, en **coût réel** (dès le 3 du mois) ; son évolution par
  rapport au mois précédent s'affiche en rouge à la hausse, en vert à la baisse.
- **Budget IA** (si un budget est fixé) : la barre du mois, la projection et la date estimée
  d'atteinte du plafond.
- **Consommation des 12 derniers mois**, avec **Analyse détaillée →** vers Consommation.
- **Top consommateurs** : les cinq premiers et leur part.
- **Alertes** : quota à 80 % ou plus en jaune, bloqué à 100 % en rouge, comptes inactifs à approuver,
  absence de clé d'IA, budget IA au seuil d'alerte, atteint, ou menacé par la projection. Le pourcentage des alertes est celui du quota réellement appliqué : les
  opérations hors quota (alimentation des bases, tests) n'y comptent pas.

## La page Consommation

**Administration → Coûts → Consommation**.

**Choisir la période** : **Mois en cours**, **Mois dernier**, **7 jours**, **30 jours**,
**12 mois** *(par défaut)*, **Cette année**, **Tout**, ou **Personnalisée** (deux dates, bornes
comprises, puis **Afficher**) — les mêmes tranches que la section **Dépenses** de **Coûts → Budget**.
Une ligne dessous dit les dates affichées et la période de comparaison des évolutions. Pour une
seule personne : **Utilisateur**, puis **Filtrer**.

**Les indicateurs** : **Crédits consommés** — dont hors quota —, **Opérations IA**, **Personnes actives**
(sur le nombre de comptes actifs), **Temps de réponse**, **Réponses relancées**, **Réponses en erreur**.
Les montants en euros sont sur **Coûts → Budget**.

**Le bandeau « ⚠ *N* opérations sans tarif »** liste les services à tarifer dans **Tarifs &
crédits**.

**Cinq onglets** :

| Onglet | Pour répondre à |
|---|---|
| **Vue d'ensemble** *(par défaut)* | Que fait MIA, qui s'en sert, avec quelle qualité ? |
| **Vue mensuelle** | Comment évolue la consommation, mois par mois et par famille ? |
| **Par utilisateur** | Qui consomme quoi, et combien cela coûte-t-il réellement ? Un clic ouvre le détail d'une personne |
| **Par catégorie** | Qu'est-ce qui consomme — conversations, créations, recherches, bases ? Avec le **Coût par base de connaissances** |
| **Répartition** | Quels fournisseurs, quels assistants, quels outils ? |

## La vue d'ensemble

- **Répartition de l'usage** : la part des crédits de chaque catégorie, en tuiles proportionnelles.
  Une tuile ouvre **Par catégorie**.
- **Adoption des fonctionnalités** : pour chaque fonctionnalité, combien de personnes s'en servent,
  combien d'usages, et l'évolution par rapport à la période de comparaison. Celles qui
  n'ont pas servi sont nommées dessous.
- **Modèles** : la part des réponses de chacun, son temps de réponse et la part de ses réponses
  relancées. Un modèle souvent relancé répond moins bien aux attentes.
- **Assistants** : conversations, personnes, réponses relancées, et les assistants que personne
  n'a utilisés sur la période.
- **Bases de connaissances** : les réponses que chaque base a nourries depuis sa création, et celles
  qui n'ont rien nourri depuis 30 jours.

Un usage est une opération IA, une action menée pendant une réponse (consultation d'une base,
lecture d'un email…) ou une exécution de tâche programmée.

## Exporter

- **Export CSV** et **Export PDF** — le PDF s'intitule « Rapport d'utilisation des crédits » — sur les
  onglets **Vue mensuelle** et **Par utilisateur**, selon les filtres choisis.
- **Export brut (logs, 500 dernières lignes)** : les dernières lignes de tokens, pour un contrôle
  technique.

## Estimer un budget

- **Coûts → Budget** fait le calcul : dépense réelle du mois, projection, consommation maximale
  des quotas en place et repère de quota par compte — voir [Fixer un budget IA mensuel](fixer-un-budget.md).
- Sans budget, les opérations **hors quota** et les comptes **illimités** échappent à tout plafond.
- Pour comparer ce que vous facturez à ce que vous payez : **Vue mensuelle**, colonnes **Coût réel
  IA** et **Valorisation**.

## Exemples

**Un pic en milieu de mois.** La projection annonce +40 %. **Par utilisateur** montre qu'une personne
représente la moitié de la hausse ; son détail révèle une série de vidéos. Vous en parlez avec elle,
ou réservez la vidéo à un groupe.

**Un service mal tarifé.** Le bandeau signale des opérations sans tarif sur un nouvel outil image :
vous saisissez son prix, les opérations suivantes sont correctement comptées.

**Le coût d'une base.** **Par catégorie → Coût par base de connaissances** : la base *Catalogue*,
resynchronisée souvent, coûte plus que prévu ; vous allongez l'intervalle de synchronisation.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Des consommations « Instance / utilisateurs supprimés » | Opérations de la base système, ou de comptes supprimés depuis. |
| Le coût réel est à 0 € sur des opérations | Elles sont non tarifées : renseigner leur tarif. |
