---
title: Fixer un budget IA mensuel
description: Plafonner ce que l'IA coûte chaque mois à l'organisation, choisir ce qui se passe au plafond, accorder une rallonge et en déduire les quotas.
sidebar:
  order: 4
---

**Administration → Coûts → Budget.** Le budget plafonne ce que l'IA coûte **réellement** à votre
organisation chaque mois : ce que facturent les fournisseurs, hors marge. Les quotas, eux,
répartissent entre les personnes. Le budget est le filet de sécurité, les quotas le partage.

## Avant de commencer

- **Vérifiez les tarifs** : le coût réel est estimé d'après **Tarifs & crédits**. Une opération sans
  tarif compte 0 € et le budget la sous-estime ; la page le signale.
- **Tout compte** : les conversations et créations des utilisateurs **et** la maintenance
  (alimentation des bases de connaissances, tests, recherche des prix).
- Le mois est un **mois civil** : le compteur repart à zéro le 1er, à 00 h 00 en temps universel.

## Lire la page

- **La jauge** : la part du budget déjà dépensée, avec le seuil d'alerte en repère. À côté : ce qui
  **reste** et pour combien de jours, le **rythme** actuel par jour comparé au rythme **tenable**
  (ce qu'on peut encore dépenser chaque jour pour finir sous le plafond), et la **fin de mois**
  estimée, avec la date où le plafond serait atteint.
- **Rythme trop rapide** : la dépense avance plus vite que le mois. La pastille s'affiche dès que
  l'estimation de fin de mois dépasse le plafond, même sous le seuil d'alerte.
- **Trajectoire du mois** : la dépense cumulée jour par jour, prolongée en pointillés au rythme
  actuel, avec le plafond, le seuil et le mois précédent en fond. Survolez un jour pour sa dépense.

L'estimation de fin de mois apparaît à partir du 3. Quand le service est suspendu, elle n'est plus
prolongée : la dépense s'arrête au plafond.

## Voir les dépenses d'une période

La section **Dépenses** montre ce que l'IA a coûté sur la période de votre choix — le mois en
cours par défaut.

1. Choisissez une tranche : **Mois en cours**, **Mois dernier**, **7 jours**, **30 jours**,
   **12 mois**, **Cette année** ou **Tout** (depuis la première opération). Ce sont les mêmes que
   sur la page **Consommation**.
2. Pour d'autres dates, cliquez **Personnalisée**, choisissez **Du** et **Au** (bornes comprises,
   jusqu'à aujourd'hui), puis **Afficher**.

La section change sans recharger la page ; le bouton Retour du navigateur revient à la période
d'avant, et l'adresse de la page garde la période choisie. Sous les tranches, une ligne dit les
dates affichées et la période de **comparaison** : les mêmes jours du mois précédent pour le mois
en cours, le mois d'avant pour le mois dernier, le même moment de l'année précédente pour l'année,
sinon la période de même durée juste avant.

- **Quatre chiffres** : **Dépensé**, avec la part du budget prévue pour ces jours ; **Par jour**,
  en moyenne ; **Évolution** face à la période de comparaison ; **Plus forte dépense**.
- **L'histogramme** compte par **jour** jusqu'à deux mois, par **semaine** jusqu'à six mois, par
  **mois** au-delà. La ligne rouge est la **part du budget** de chaque barre (le budget du mois
  divisé par ses jours) ; une barre orange a dépensé plus que sa part. La ligne en pointillés donne
  la moyenne. Survolez une barre pour son montant.
- **Où part l'argent** : la part des comptes et celle de la maintenance, puis les catégories
  d'opérations qui coûtent le plus. **Détail de la période dans Consommation** ouvre Consommation
  sur les mêmes dates.
- **Par fournisseur** : ce que chaque fournisseur d'IA facturera pour la période, estimé d'après
  les tarifs.

MIA ne garde pas l'historique de vos budgets : sur une période passée, la part du budget est
calculée avec le montant d'aujourd'hui, et l'écran l'indique.

## Étapes

1. Dans **Règles**, cliquez **Modifier les règles** (sans budget, le formulaire est déjà ouvert),
   puis saisissez le **Budget mensuel (€)**. Vide = aucun budget ; 0 = service suspendu.
2. Réglez **Alerte à (%)** (80 par défaut) : au-delà, le tableau de bord, le menu et le
   Diagnostic vous préviennent.
3. Choisissez **Quand le budget est atteint** :
   - **Suspendre les nouvelles demandes jusqu'au 1er du mois suivant** (recommandé) ;
   - **Laisser passer, seulement signaler** : utile le premier mois, pour calibrer le montant.
4. Cliquez **Enregistrer**. Cette page ne s'enregistre pas toute seule : un montant à moitié saisi
   suspendrait tout le monde. Un budget déjà dépassé demande confirmation.

## Ce qui se passe au plafond

En mode **suspendre**, pour **tout le monde, administrateurs compris** :

| S'arrête | Continue, compté |
|---|---|
| Messages, régénérations, images, vidéos, pages web, cartes, présentations, dictée, rédaction assistée des assistants | Dépôt de documents, « Synchroniser maintenant », réindexations |
| Synchronisation automatique des bases de connaissances | Tests d'outils et de modèles, recherche des prix |

Les utilisateurs voient la fenêtre **Service IA suspendu**, avec la date de reprise, et la même
mention sous leur nom. Ils ne voient jamais de montant.

## Débloquer le mois : la rallonge

Dans **Règles**, cliquez **Accorder une rallonge**, saisissez un montant et cliquez **Accorder** : il s'ajoute au plafond **de
ce mois seulement** et expire de lui-même le 1er. Un nouveau montant remplace le précédent ;
**Retirer la rallonge** l'annule. Pour changer tous les mois suivants, modifiez plutôt le budget.

## En déduire les quotas

La carte **Répartir entre les comptes** répond à « combien de crédits puis-je donner à chacun ? » :

- **1 000 crédits ≈ X € de coût réel**, et son pendant **1 € ≈ N crédits** (coefficient global ÷
  valeur du crédit, approximatif) ;
- **Repère** : le budget converti en crédits, divisé par le nombre de comptes actifs. Avec ce quota
  pour tous, les comptes ne peuvent pas dépasser le budget ;
- **Quotas en place** : la consommation maximale possible aujourd'hui, comparée au budget, ou
  « non bornée » si des comptes n'ont aucun quota ;
- **Mois dernier** : la consommation réelle, moyenne et maximale, par compte actif.

Le même repère s'affiche sous le champ **Quota** d'un utilisateur et d'un groupe. Rien ne s'applique
tout seul : vous fixez les quotas comme d'habitude, voir [Fixer les quotas](fixer-les-quotas.md).

## Exemples

**Un premier mois de calibrage.** Budget 500 €, mode **signaler**. Le 15, la projection annonce 620 € :
vous relevez le budget à 650 € ou baissez le quota du groupe ★ Par défaut, puis passez en mode
**suspendre** le mois suivant.

**Un pic le 26.** Le budget est atteint, le service est suspendu. Une démonstration client est prévue
le 28 : vous accordez une rallonge de 100 €. Le 1er, le budget normal reprend, sans rallonge.

**Des quotas cohérents.** 500 €, 40 comptes actifs, 1 € ≈ 1 300 crédits : repère ≈ 16 000 crédits
par compte. Vous fixez ★ Par défaut à 10 (10 000) et *Direction* à 30 : la consommation maximale
reste proche du budget, qui arrête le reste.

## Ce que le budget ne voit pas

- les opérations sans tarif (comptées 0 €) ;
- les modèles locaux, gratuits ;
- les crédits Gamma et les workflows n8n, facturés par ces services ;
- les tests de clés de Modèles IA.

Un **léger dépassement** reste possible : le contrôle a lieu avant chaque opération, une réponse en
cours ou une génération déjà en file peut finir au-delà du plafond.

## Problèmes courants

| Constat | Cause | Remède |
|---|---|---|
| Tout le monde voit « Service IA suspendu » | Budget atteint en mode suspendre | Accordez une rallonge ou relevez le budget |
| Le budget semble trop bas par rapport à la facture | Opérations sans tarif, ou usage hors de MIA sur la même clé | Renseignez les tarifs ; le budget ne voit que MIA |
| Les bases ne se synchronisent plus seules | Synchronisation automatique en pause au plafond | « Synchroniser maintenant », ou débloquez le mois |
