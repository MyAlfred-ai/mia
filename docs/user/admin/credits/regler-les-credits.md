---
title: Régler les crédits
description: Définir la valeur du crédit, la marge et les tarifs des services d'IA, simuler, et garder une trace de la configuration.
sidebar:
  order: 1
---

**Administration → Coûts → Tarifs & crédits.** C'est ici que se définit la conversion entre le coût réel des
services d'IA et les crédits consommés par vos utilisateurs. Le calcul d'une opération est détaillé
dans [La gestion des crédits](index.md).

La page s'enregistre seule, un instant après la dernière modification : les pastilles affichent
« Tout est enregistré » et « Configuration synchronisée ».

## Les paramètres généraux

- **Valeur d'un crédit (€)** — par défaut 0,001 €.
- **Coefficient global** — le multiplicateur appliqué à tous les services, par défaut 1,30. **C'est
  lui qui porte votre marge.** Il peut descendre sous 1.

Sous **Paramètres avancés** : **Frais fixes par opération (€)**, **Mode d'arrondi** (**Entier
supérieur** par défaut, **Entier le plus proche**, **Entier inférieur**), **Minimum crédits /
opération** (1 par défaut), **Taux 1 EUR → USD**, **Devise affichée** — EUR ou USD, pour l'affichage
seulement.

Une valeur hors limites est ramenée au minimum admis, sans message. Après avoir changé la valeur d'un
crédit, **rechargez la page et vérifiez la valeur affichée** : c'est elle qui sera enregistrée à la
prochaine modification.

## Les tarifs — Vue services

La **Vue services** *(par défaut)* est le tableau où se règle chaque service : **Service**, **Unité**,
**Coût réel (EUR)** par unité, **Coeff. spécifique**, **Crédits finaux** pour une unité, **Commentaire
interne**. Cliquer une ligne détaille son calcul.

- **Les lignes naissent seules** : quatre ou cinq par modèle **actif** — entrée, sortie, entrée en
  cache, raisonnement —, une par outil **actif**, plus les lignes d'indexation, de recherche web et de
  dictée. On n'en ajoute pas à la main.
- **Une ligne laissée vide** (ou à 0) reprend le tarif public du catalogue de MIA, quand il existe —
  outils image et vidéo compris. Le tableau le montre : le champ affiche ce tarif en grisé, avec
  « **Tarif public appliqué** » dessous, et les **Crédits finaux**, le simulateur et le rapport PDF
  calculent avec lui — exactement ce que la facturation compte. « **Non tarifé : compté au minimum** »
  signale une ligne qu'aucun tarif ne couvre. Pour une image dont le prix dépend de sa taille et de sa qualité, MIA
  calcule le coût réel de chaque image d'après la consommation que l'éditeur lui renvoie. Sans tarif
  public connu, l'opération devient **non tarifée** : elle coûte le minimum, et la page **Consommation**
  la signale.
- **Un tarif de lancement** annoncé par un éditeur, limité dans le temps, s'applique jusqu'à sa date
  de fin ; le tarif normal prend ensuite le relais, sans intervention — tant qu'aucun coût n'est
  enregistré sur la ligne : un coût enregistré prime et ne suit aucune date.
- **Supprimer** une ligne la remet à 0 ; elle revient au rechargement.
- **Ce que la facturation compte en plus des jetons** :
  - une **très longue requête** suit le tarif majoré de l'éditeur quand il en a un (OpenAI au-delà
    de 272 000 jetons d'entrée, Gemini Pro au-delà de 200 000 : entrée ×2, sortie ×1,5) ; chaque
    étape d'une réponse qui enchaîne des actions est jugée à part, comme l'éditeur le fait ;
  - la **session de code** que GPT ouvre pour la « Version soignée » d'une présentation ou d'un
    classeur (tarif d'OpenAI par session) ;
  - les **recherches Internet**, à l'unité. Celles de Mistral n'ont pas de prix publié : elles
    apparaissent comme **non tarifées** tant que la ligne de recherche web de Mistral n'a pas de
    coût saisi ;
  - une réponse **arrêtée** avec le bouton Stop : l'éditeur facture ce qu'il a déjà lu et écrit,
    mais ne le rapporte qu'à la fin ; MIA l'estime d'après la conversation envoyée et le texte déjà
    affiché.
- **Une opération reste une opération** : une réponse appuyée sur Internet s'inscrit sur deux lignes
  (la réponse, ses recherches) dans **Consommation**, mais les frais fixes, l'arrondi et le minimum
  ne s'appliquent qu'une fois pour les deux.
- **Les lignes de cache servent aux réponses qui enchaînent des actions** : entre deux étapes d'une
  même réponse (connecteurs, outils), MIA demande à l'éditeur de garder le début de la conversation
  en cache. Claude et les modèles OpenAI le relisent ensuite à une fraction du prix de l'entrée ; la
  première écriture coûte un peu plus que l'entrée chez Claude.

Deux vues en lecture : **Tarif par modèle**, qui ne montre que l'entrée et la sortie — le cache et le
raisonnement ne se règlent que dans la Vue services —, et **Tarif par outil**.

## Mettre à jour les tarifs publics

**Mettre à jour coûts Internet (USD)** récupère les tarifs publics : d'abord ceux du catalogue de MIA
— outils image et vidéo compris, sauf ceux dont le coût se calcule image par image —, puis, pour le
reste, une recherche en ligne — qui demande une clé OpenAI ou Perplexity.

- La recherche tourne côté serveur et peut durer **plusieurs minutes** : vous pouvez changer d'écran,
  elle continue. **Arrêter** l'interrompt.
- Elle **remplace le coût réel** saisi à la main, garde vos coefficients, note sa source dans le
  commentaire, et passe l'affichage en USD.
- Pour un modèle du catalogue en tarif de lancement, c'est le **tarif normal** qui est recopié, la
  remise étant signalée dans le commentaire : recopiée, elle resterait en place après sa date et
  l'usage serait compté sous le prix réel. Pour en profiter pendant qu'elle dure, laissez la ligne à
  0.
- Un tarif trouvé par la recherche en ligne peut, lui, être un tarif promotionnel : relancez la mise
  à jour une fois la promotion passée.
- Ce qu'elle consomme est hors quota.

## Simuler, synthétiser, exporter

- **Ouvrir simulateur** — estimer le coût d'un usage sur un service : quantité, tokens. Il calcule
  service par service ; une opération réelle combine plusieurs composantes et n'applique frais fixes
  et arrondi qu'une fois.
- **Ouvrir synthèse** — le panorama des services actifs et de leurs coûts unitaires.
- **Exporter la configuration** — un fichier JSON daté, à conserver comme trace. **Il n'existe pas
  d'import** : l'export ne permet pas de revenir en arrière automatiquement, mais il dit quelles
  valeurs ressaisir.
- **Exporter PDF (avec simulations)** — pour un comité ou un devis.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Aucune clé OpenAI ou Perplexity active… » | La recherche en ligne des tarifs a besoin de l'une de ces clés. |
| « Aucun coût exploitable trouvé en ligne. » | Saisir les prix restants à la main. |
| Une opération « non tarifée » dans **Consommation** | Renseigner le prix du service concerné dans la Vue services. |
| Tous les modèles affichent €€€ chez les utilisateurs | Aucun coût réel n'est saisi : le repère € est calculé à partir de cette page. |
