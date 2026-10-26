---
title: La gestion des crédits
description: Fixer la valeur du crédit et la marge, les tarifs des services d'IA, les quotas des utilisateurs et des groupes, et suivre la consommation.
sidebar:
  order: 7
---

L'administrateur fixe **ce que vaut un crédit**, **ce que coûte chaque service** et **combien chacun
peut consommer**, puis suit la consommation et le coût réel. Rubrique **Administration → Coûts**
(**Budget**, **Consommation**, **Tarifs & crédits**), et le **Tableau de bord**.

## À quoi ça sert

À transformer une facture d'IA imprévisible — des dizaines de services, des tarifs en dollars par
million de tokens — en une unité simple, plafonnée par personne. Vous savez ce que coûte l'instance,
ce que coûte chaque équipe, et personne ne vide le budget du mois en une semaine.

## Comment ça marche

**Une opération, un calcul.** Chaque appel facturable — une réponse, un titre, une recherche Internet,
une image, une minute de dictée — est une **opération**. Son nombre de crédits se calcule ainsi :

1. chaque composante — tokens d'entrée, de sortie, en cache, de raisonnement, ou unités — est
   multipliée par son **tarif** : celui que vous avez saisi, sinon le tarif public du catalogue de
   MIA — y compris pour les outils image et vidéo ;
2. la somme est le **coût réel**, conservé tel quel ;
3. le **coefficient global** (votre marge), les **frais fixes**, l'**arrondi** et le **minimum**
   s'appliquent **une fois par opération** ;
4. le résultat est divisé par la **valeur d'un crédit**.

Une opération sans aucun tarif est **non tarifée** : elle coûte le minimum de crédits, son coût réel
est compté à 0 €, et la page **Consommation** la signale.

**Hors quota.** Alimenter une base de connaissances, tester un outil, rechercher les prix : ces
opérations sont comptées et attribuées, mais n'entament aucun quota.

**Le budget.** Un plafond mensuel en euros sur le **coût réel** de toute l'organisation,
maintenance comprise. Atteint, il suspend les nouvelles demandes jusqu'au 1er (ou seulement le
signale, au choix). C'est le filet de sécurité ; les quotas font le partage.

**Le quota.** Par personne et par mois calendaire, en **milliers de crédits**. Le quota individuel
prime ; sinon, c'est le plus élevé des quotas définis parmi ses groupes actifs ; sinon, illimité. Un
quota s'applique **à chaque membre** d'un groupe, ce n'est pas une enveloppe partagée.

**La valorisation.** Crédits × valeur **actuelle** du crédit. Changer la valeur du crédit réévalue
tout l'historique en euros, mais ne change pas les crédits déjà comptés — seulement ceux des
opérations suivantes, donc la vitesse à laquelle les quotas se vident.

## Configurer

| Réglage | Où | Effet | Page |
|---|---|---|---|
| Budget mensuel, seuil, rallonge | Coûts → Budget | Ce que l'IA peut coûter au mois, pour toute l'organisation | [Fixer un budget IA mensuel](fixer-un-budget.md) |
| Valeur du crédit, coefficient, arrondi, tarifs | Coûts → Tarifs & crédits | Combien de crédits coûte chaque opération | [Régler les crédits](regler-les-credits.md) |
| Quota d'un utilisateur, d'un groupe | Personnes → Comptes, Groupes | Combien chacun peut consommer | [Fixer les quotas](fixer-les-quotas.md) |
| Suivi, exports, alertes | Coûts → Consommation, Tableau de bord | Ce qui a été consommé, par qui, pour quel coût | [Suivre la consommation](suivre-les-usages.md) |

## Exemples de configuration

**Le calcul d'une réponse.** Coefficient global 1,5, frais fixes à 0, arrondi à l'entier supérieur,
minimum 1. Une réponse dont le coût réel est de 0,036 € devient 0,054 € après coefficient ; divisée
par la valeur du crédit, elle donne ses crédits, arrondis au-dessus. Le simulateur de **Tarifs &
crédits** fait ce calcul pour vous.

**Un budget plafonné.** Budget 500 € par mois, 25 comptes actifs : la page **Budget** donne le repère
de quota par compte qui garantit de ne jamais l'atteindre, et suspend tout au plafond, maintenance et
comptes sans quota compris. Voir [Fixer un budget IA mensuel](fixer-un-budget.md).

**Des quotas par équipe.** ★ Par défaut à 5, *Direction* à 50, un stagiaire à 2 en individuel —
voir [Fixer les quotas](fixer-les-quotas.md).

**Un tarif oublié.** Un outil vidéo ajouté sans tarif : chaque vidéo coûte le minimum, et la page
**Consommation** affiche « ⚠ *N* opérations sans tarif ». Vous saisissez son prix dans **Vue services**.

## Vérifier que tout fonctionne

- **Consommation** : aucun bandeau « opérations sans tarif ».
- **Tarifs & crédits → Ouvrir simulateur** : le coût d'une réponse type correspond à ce que vous
  attendez.
- **Coûts → Budget** : la projection de fin de mois reste sous le plafond.

## Voir aussi

- [La gestion des crédits, côté utilisateur](../../fonctionnalites/credits/index.md)
- [Les modèles de LLM](../modeles/index.md) — les lignes de tarif naissent des modèles activés
- [Les outils](../outils/index.md) — et des outils actifs
- [Les utilisateurs et les accès](../utilisateurs/index.md) — les quotas de groupe
- [Fixer un budget IA mensuel](fixer-un-budget.md)
