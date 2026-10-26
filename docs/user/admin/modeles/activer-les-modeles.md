---
title: Activer les modèles
description: Choisir dans le catalogue les modèles proposés aux utilisateurs, comprendre leurs capacités, en retirer un sans rien casser, et préparer l'arrêt annoncé d'un modèle.
sidebar:
  order: 2
---

**Administration → Modèles IA → Modèles**, liste **Modèles activés**. Le catalogue, éditeur par éditeur : on y active
les modèles à proposer. Une clé enregistrée ne propose rien tant qu'aucun modèle n'est activé.

## Avant de commencer

La clé de l'éditeur est enregistrée — voir [Enregistrer les clés API](enregistrer-les-cles-api.md).
Sinon, la ligne affiche **Clé API manquante**.

## Étapes

1. Repérez le modèle dans le tableau de son éditeur. Chaque ligne donne sa **Description**, ses
   **Capacités** — vision, fichiers, web, raisonnement, code — et ses **Limites**.
2. Cliquez **Activer** : « Instance LLM ajoutée. » Sur une instance neuve, le message ajoute le
   **Modèle par défaut** désigné du même coup — voir ci-dessous.
3. **Cochez-le dans les groupes** qui y ont droit : un modèle nouvellement activé n'est ajouté à
   aucune liste existante — voir [Organiser les groupes](../utilisateurs/organiser-les-groupes.md).
4. Vérifiez son tarif dans **Tarifs & crédits** — voir [Régler les crédits](../credits/regler-les-credits.md).

## Résultat

L'**État** du modèle passe à **Actif**. Les badges **Défaut** et **Secours** signalent les modèles qui
jouent ces rôles. Le modèle apparaît dans le sélecteur des utilisateurs de ses groupes.

**Le premier modèle activé devient le modèle par défaut** : sans lui, les membres du seul groupe
★ Par défaut n'auraient aucun modèle. Commencez donc par le modèle polyvalent que vous destinez à
tout le monde, ou changez le défaut ensuite dans
[Régler le comportement par défaut](regler-le-comportement-par-defaut.md).

## Alias « latest » ou version datée ?

| | Alias `latest` | Version datée |
|---|---|---|
| Suit la génération courante | Oui, automatiquement | Non |
| Comportement et tarif | Peuvent changer **sans préavis**, au gré de l'éditeur | Figés |
| Durée de vie | Illimitée | Arrêtée un jour par l'éditeur — annoncée à l'avance, voir ci-dessous |

Préférez la version datée quand la stabilité compte — un assistant juridique, un traitement répété ;
l'alias quand vous voulez toujours le dernier modèle sans intervenir.

Un modèle dont les **Limites** portent **Aperçu public** n'a pas encore de version définitive : il
n'a pas d'alias `latest`, et son éditeur peut le modifier sans préavis, voire le retirer avant sa
sortie. Essayez-le, mais gardez une version datée pour les usages qui doivent rester stables. Le
jour de sa sortie définitive, l'alias `latest` de sa famille bascule sur lui — tarif compris.

## Un modèle qui exige la conservation des données

Un modèle dont les **Limites** portent **Rétention 30 j requise** n'est ouvert qu'aux organisations
qui conservent les données 30 jours chez leur éditeur. C'est le réglage par défaut des éditeurs :
seules les organisations en « zéro rétention », par accord particulier, sont refusées. Lancez
**Tests** après l'activation ; si le test l'indique, trois voies côté éditeur :

- activer la conservation 30 jours pour l'organisation ;
- créer un **espace de travail dédié** où elle est activée, et enregistrer sa clé dans **Clés API** —
  tous les appels de cet éditeur passent alors par cet espace ;
- demander une autorisation expresse à l'éditeur.

En attendant, les demandes adressées à ce modèle sont reprises par un autre modèle du même éditeur,
et la conversation le signale.

## Retirer un modèle

**Retirer**, après confirmation : « Instance LLM supprimée. » Les conséquences sont silencieuses :

- ses conversations passent sur le modèle par défaut ;
- les assistants qui l'imposaient passent sur le modèle par défaut — leur fiche garde un identifiant
  brut, à corriger ;
- s'il était le **LLM par défaut**, un autre modèle le devient aussitôt — celui que le catalogue
  propose par défaut pour son éditeur, s'il est activé, sinon le premier de la liste — et le message
  le nomme : « Nouveau modèle par défaut : … ». Changez-le au besoin dans **Comportement par
  défaut**.

## Quand un éditeur arrête un modèle

Les éditeurs annoncent l'arrêt de leurs anciens modèles quelques mois à l'avance. Dès l'annonce :

- la ligne du modèle porte le badge **Arrêt le …** et nomme son **Remplaçant désigné** ;
- les listes **LLM par défaut** et **LLM de secours** affichent la date à côté de son nom ;
- tant qu'il est activé, une pastille sur **Modèles** le rappelle, et une autre sur **Assistants**
  nomme ceux qui l'imposent.

**Avant la date** : activez le remplaçant, cochez-le dans les groupes, choisissez-le comme défaut ou
secours et dans les fiches des assistants concernés, puis retirez l'ancien.

**Le jour de l'arrêt**, même si vous n'avez rien fait, MIA retire le modèle du sélecteur : ses
conversations, les assistants qui l'imposaient et le modèle par défaut, s'il l'était, passent sur le
modèle par défaut. La ligne affiche **Arrêté le …** et ne propose plus **Activer**. Il ne reste qu'à
le **Retirer**.

Plus rarement, un modèle a été retiré du catalogue par une mise à jour de MIA : il disparaît de
cette page — on ne peut plus le **Retirer** — mais il reste proposé là où il était sélectionné, et
ses appels échouent. Après chaque mise à jour de MIA :

1. **Tests → Lancer tous les tests** : un modèle en échec « Requête refusée » est à remplacer ;
2. vérifiez les badges **Défaut** et **Secours** ;
3. mettez à jour les listes **LLMs** des groupes ;
4. repérez les assistants dont la pastille de modèle affiche un identifiant brut ;
5. vérifiez les tarifs dans **Tarifs & crédits**.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| **Clé API manquante** sur la ligne | Enregistrer la clé de l'éditeur. |
| Badge **Arrêt le …** sur un modèle activé | Activer le **Remplaçant désigné** et le choisir là où l'ancien servait avant la date. |
| Le modèle activé n'apparaît chez aucun utilisateur | Il n'est coché dans aucune liste de groupe. |
| Les utilisateurs voient tous le repère €€€ | Aucun tarif n'est saisi dans **Tarifs & crédits** : le repère est calculé à partir de ces prix. |
