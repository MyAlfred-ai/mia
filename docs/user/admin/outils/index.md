---
title: Les outils
description: Ajouter les outils de génération — image, vidéo, page web, carte, présentation, automatisations —, les tester et les autoriser aux groupes.
sidebar:
  order: 6
---

Les outils n'existent que si l'administrateur les crée : c'est lui qui choisit, pour chacun, le
fournisseur et le modèle qui fabriquent les créations, puis qui l'autorise aux groupes. Menu
**Administration → Capacités → Outils**, page **Outils de génération**.

## À quoi ça sert

À offrir des capacités de production — affiches, vidéos, pages, présentations, itinéraires — avec un
moteur choisi pour sa qualité et son coût, et à les réserver aux équipes qui en ont besoin. Le
marketing a la vidéo, tout le monde a l'image.

## Comment ça marche

**Un outil = un type + un moteur.** À la création, on choisit ce que l'outil génère, puis le moteur :

| Type | Moteur | Où l'utilisateur le trouve |
|---|---|---|
| **Image** | Un modèle d'image, chez OpenAI ou Google | Menu **+** → Outils, et brique **Création** des workflows |
| **Vidéo** | Un modèle vidéo | Menu **+** → Outils |
| **Page HTML**, **Carte / Itinéraire** | Un modèle de langage, avec ses consignes | Menu **+** → Outils, et brique **Création** des workflows |
| **Présentation** | Un modèle de langage, avec ses consignes | Menu **+** → Outils |
| **Site illustré** | Un modèle de langage, plus un outil image | Menu **+** → Outils, et brique **Création** des workflows |
| **Automatisation n8n** | Votre instance n8n | Confié à un assistant |
| **Présentation Gamma** | Votre compte Gamma | Confié à un assistant, et choix **Par Gamma** du bouton **Version soignée** des présentations du chat |

La **Dictée vocale**, la **Conversation vocale**, les **Suggestions de suite**, les **Tâches
programmées** et les **Workflows** figurent aussi sur cette page, comme outils intégrés.

Le moteur d'un outil est **indépendant** du modèle choisi dans la conversation. Il utilise la clé de
son éditeur enregistrée dans **Modèles IA**, ou une **clé API dédiée** propre à l'outil.

**Qui voit quel outil.** Un outil n'est proposé à un utilisateur que s'il est **Actif** **et** coché
dans l'un de ses groupes actifs. Un outil nouvellement créé n'est coché **dans aucun groupe** : tant
que vous ne l'avez pas autorisé, seuls les administrateurs le voient. L'interrupteur **Actif** seul ne
suffit pas : sous lui, chaque carte affiche le nombre de groupes qui autorisent l'outil, ou « Aucun
groupe — admins seuls ».

**Un outil grisé.** Quand son **dernier test** a échoué, l'outil est grisé dans le menu des
utilisateurs et retiré des assistants. Il le reste jusqu'à un nouveau test réussi. Aucun test ne se
lance tout seul.

**Un modèle arrêté par son fournisseur.** MIA connaît les arrêts annoncés : la carte de l'outil
prévient avant la date, puis l'outil passe **Hors service** — grisé chez les utilisateurs, avec la
raison, et plus aucune demande n'est envoyée au fournisseur. Lui choisir un autre modèle le remet
en service. Voir [Tester et entretenir les outils](tester-et-entretenir-les-outils.md).

**Les outils des assistants.** Seuls les outils **image**, **n8n** et **Gamma** se confient à un
assistant, qui les appelle lui-même — à condition que son modèle soit OpenAI ou Anthropic, et que
l'outil soit autorisé dans le groupe de l'utilisateur.

**Le coût.** Une image se paie à l'unité, une vidéo à la seconde, sur la ligne de l'outil dans
**Tarifs & crédits** — laissée à 0, elle reprend le tarif public du modèle quand MIA le connaît ; une
page, une carte ou une présentation selon les tokens de son modèle. n8n et
Gamma ne coûtent aucun crédit MIA : ils sont facturés par leur propre service. Les tests sont hors
quota, imputés à l'administrateur qui les lance.

## Configurer

| Réglage | Où | Effet | Page |
|---|---|---|---|
| Création et moteur d'un outil | Outils → **Ajouter un outil** | L'outil existe | [Ajouter un outil](ajouter-un-outil.md) |
| **Actif** / **Inactif**, tests, consignes, expressions déclencheuses | Outils, carte de l'outil | L'outil fonctionne, et se fait suggérer | [Tester et entretenir les outils](tester-et-entretenir-les-outils.md) |
| Autorisation par groupe | Personnes → Groupes → Droits d'accès → **Outils** | Qui voit l'outil | [Organiser les groupes](../utilisateurs/organiser-les-groupes.md) |
| Outils d'un assistant | Fiche de l'assistant → **Capacités** | Ce que l'assistant produit seul | [Créer un assistant](../../fonctionnalites/assistants/creer-un-assistant.md) |
| Tarif | Tarifs & crédits → Vue services | Le coût en crédits | [Régler les crédits](../credits/regler-les-credits.md) |
| Dictée vocale | Outils, carte **Dictée vocale** | Le micro du chat | [Régler la dictée vocale](regler-la-dictee-vocale.md) |
| Conversation vocale | Outils, carte **Conversation vocale** | Le bouton onde sonore du chat | [Régler la conversation vocale](regler-la-conversation-vocale.md) |
| Suggestions de suite | Outils, carte **Suggestions de suite** | La suite proposée après chaque réponse | [Régler les suggestions de suite](regler-les-suggestions-de-suite.md) |
| Tâches programmées | Outils, carte **Tâches programmées** | Les demandes récurrentes des utilisateurs | [Régler les tâches programmées](regler-les-taches-programmees.md) |
| Workflows | Outils, carte **Workflows** | Les travaux en plusieurs étapes des créateurs | [Régler les workflows](regler-les-workflows.md) |

## Exemples de configuration

**L'image pour tous.** Outil *Visuels* — image, moteur OpenAI, ordre 1 —, testé, puis coché dans le
groupe **★ Par défaut**.

**La vidéo pour le marketing.** Outil *Clips* — vidéo —, coché dans le seul groupe *Marketing* : c'est
l'outil le plus cher au rendu.

**Un mini-site illustré.** Outil *Mini-site vitrine* — site illustré —, modèle Anthropic, outil image
*Visuels* pour les illustrations, 4 images par page, style photoréaliste.

**Un support qui ouvre des tickets.** Outil *Automatisations Support* — n8n —, lié à l'assistant
*Support IT* et coché dans le groupe *Support*. Chaque ticket passe par l'approbation de l'utilisateur.

## Vérifier que tout fonctionne

1. **Tester tous les outils** : chaque carte affiche **OK**.
2. Avec un compte **non administrateur** du groupe concerné : l'outil apparaît dans le menu **+**,
   et une demande simple aboutit.

## Voir aussi

- [Les outils, côté utilisateur](../../fonctionnalites/outils/index.md)
- [Les modèles de LLM](../modeles/index.md) — les clés qu'utilisent les outils
- [Les assistants](../assistants/index.md) — les outils qu'ils appellent
- [La gestion des crédits](../credits/index.md) — le coût des créations
