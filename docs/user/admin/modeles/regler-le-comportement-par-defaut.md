---
title: Régler le comportement par défaut
description: Désigner le modèle par défaut et le modèle de secours.
sidebar:
  order: 3
---

**Administration → Modèles IA → Fournisseurs et modèles**, carte **Qui répond par défaut**, en tête de
page : un schéma « Conversation sans assistant → Par défaut → en cas de panne → Secours ». Deux réglages qui valent pour toute
l'instance.

Le vocabulaire de l'organisation (sigles, lexique maison) se règle désormais avec l'entreprise :
[Présenter l'entreprise](../instance/presenter-l-entreprise.md#vocabulaire-de-lentreprise).

## Avant de commencer

Au moins un modèle est activé — voir [Activer les modèles](activer-les-modeles.md).

## Étapes

1. **Par défaut** : choisissez le modèle. La liste propose tous les modèles
   activés et le LLM local, sans filtre de groupe.
2. **Secours** (facultatif) : choisissez un modèle — idéalement chez un **autre** éditeur —,
   ou **— Aucun (échec sans bascule) —**. Le modèle par défaut est grisé dans cette liste.
3. L'enregistrement est automatique : « Comportement par défaut enregistré. »

## Résultat

Dans le panneau de chaque fournisseur, les badges **Par défaut** et **Secours** désignent les deux
modèles, et le logo de leur éditeur s'affiche dans le schéma.

## Quand aucun modèle par défaut n'est enregistré

Le premier modèle que vous activez devient le modèle par défaut. Si vous retirez ensuite le modèle
par défaut, MIA en désigne un autre : celui que le catalogue propose pour son éditeur, s'il est
activé, sinon le premier de la liste. Vérifiez ce choix ici : il sert à tout le groupe ★ Par défaut.

Si la page affiche « Aucun modèle par défaut n'est enregistré : MIA utilise … », le modèle montré
dans la liste est celui que MIA utilise déjà, faute de mieux. Cliquez **Confirmer ce modèle**, ou
choisissez-en un autre : l'avertissement disparaît dès l'enregistrement.

## Ce que fait chaque réglage

**Le LLM par défaut** répond aux nouvelles conversations sans assistant, et sert à l'assistant
**MIA : mode d'emploi**, au découpage des documents des bases de connaissances et à l'aide à la
rédaction des consignes d'assistant. Pour un utilisateur qui n'est que dans le groupe ★ Par défaut
sans liste de modèles, c'est le **seul** modèle proposé.

Chaque personne peut **choisir son propre modèle par défaut** parmi ceux que ses groupes lui
proposent : l'étoile du sélecteur de modèle, ou **Réglages → Préférences IA**. Il ouvre alors ses
nouvelles conversations à la place du vôtre ; tant qu'elle n'en a pas choisi, elle suit le vôtre et
ses changements. Un modèle que vous retirez de ses groupes cesse aussitôt de servir : le vôtre
reprend. Les assistants n'en tiennent jamais compte.

**Le LLM de secours** prend le relais quand le modèle d'une conversation — quel qu'il soit — échoue
**avant son premier mot** : panne ou surcharge de l'éditeur, saturation, délai dépassé, ou compte de
l'éditeur **sans crédit**. L'utilisateur voit « Bascule sur le LLM de secours : A → B », avec la
raison entre parenthèses ; la liste des actions de la réponse la garde. Le secours :

- garde la **recherche Internet** si son modèle sait la faire, et les **connecteurs et outils** de
  la conversation s'il sait s'en servir (modèles Claude et OpenAI) ; sinon il le dit à l'utilisateur
  au lieu de prétendre les avoir consultés ;
- ne prend **jamais** le relais une fois qu'une action a été menée dans la réponse (fichier lu, carte
  d'approbation proposée) : reprendre depuis le début referait tout en double ; l'encadré d'erreur
  s'affiche alors, et la liste des actions dit ce qui a été fait ;
- n'est **pas** limité par les listes des groupes ;
- n'intervient ni sur une erreur de configuration — clé absente ou refusée, modèle inexistant —, ni
  sur ce qu'un autre modèle refuserait pareil — conversation trop longue, pièce jointe refusée, filtre
  de sécurité —, ni en cours de réponse ;
- ne bascule jamais une seconde fois.

## Exemples de configuration

- Défaut : un modèle polyvalent et économique chez Mistral. Secours : un modèle équivalent chez
  Anthropic. Une panne d'un éditeur ne bloque personne.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Aucun modèle par défaut n'est enregistré : MIA utilise … » | **Confirmer ce modèle**, ou en choisir un autre. |
| Aucun badge **Par défaut** dans **Fournisseurs et modèles** | Aucun modèle n'est activé, ou aucune clé n'est enregistrée : voir [Activer les modèles](activer-les-modeles.md). |
| Un utilisateur reçoit « Ce LLM n'est pas autorisé pour votre groupe. » | Le modèle qu'il a choisi n'est coché dans aucun de ses groupes : le cocher, ou lui faire choisir un modèle autorisé. |
| Des réponses arrivent d'un autre modèle que celui choisi | Le secours a pris le relais : l'éditeur du modèle choisi était en panne, ou son compte sans crédit. La raison est dans le message de bascule : voir [Réagir à une erreur de modèle](reagir-a-une-erreur-de-modele.md). |
