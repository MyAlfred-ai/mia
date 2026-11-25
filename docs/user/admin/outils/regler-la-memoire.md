---
title: Régler la mémoire
description: Proposer ou couper la mémoire de MIA — ce qu'elle sait de chaque personne d'une conversation à l'autre —, son apprentissage automatique, et ce qu'elle coûte.
sidebar:
  order: 6
---

La **mémoire** est ce que MIA sait de chaque personne d'une conversation à l'autre : son contexte de
travail et ses souvenirs, rangés par thème. MIA la relit au début de chaque conversation, retient,
corrige ou oublie ce qu'on lui demande, et apprend d'elle-même ce que chacun dit de lui. Elle se règle sur la page **Outils**, carte **Mémoire**,
marquée **Intégré**. Côté utilisateur :
[Gérer sa mémoire](../../fonctionnalites/personnalisation/gerer-sa-memoire.md).

## Avant de commencer

Rien à configurer : une demande faite en conversation (*« souviens-toi que… »*, *« oublie que… »*)
est traitée par le **modèle de la conversation**, avec la clé déjà enregistrée pour lui ;
l'apprentissage automatique, par le **modèle par défaut** de l'instance, sauf si vous en choisissez un
autre.

## Étapes

- **Proposer la mémoire** : interrupteur sur **Actif** — c'est le réglage par défaut.
- **La couper** : interrupteur sur **Inactif**. MIA ne relit plus la mémoire et ne retient plus rien ;
  si on le lui demande, elle répond que la mémoire est désactivée.

- **Les questions de MIA** : la case **Poser des questions de temps en temps** (cochée par défaut).
  Sous une réponse terminée, MIA pose une courte question à choix — la forme des réponses, la façon de
  travailler, les outils, le métier — ou fait confirmer ce qu'elle a cru comprendre. Décochée, plus
  aucune question.
- **L'apprentissage automatique** : la case **Apprendre des conversations** (cochée par défaut).
  Quelques minutes après une conversation où une personne parle d'elle ou de ses préférences, MIA
  relit ses derniers messages en un appel court et retient ce qu'elle y a dit. La case couvre aussi les
  **gestes** : les retouches que chacun redemande souvent (*« plus court »*, un email passé au
  vouvoiement, le même bouton **Améliorer**) lui sont proposées comme préférences — sans appel au
  modèle, sans rien garder des textes. Décochée, MIA n'apprend plus rien d'elle-même ; ce qu'elle a
  déjà appris reste.
- **Le modèle de l'apprentissage** : la liste **Modèle de l'apprentissage**, avec le coût estimé d'une
  relecture pour chaque modèle. Un modèle économique suffit.

Il n'y a pas de réglage par groupe : le choix vaut pour toute l'instance.

## Ce qu'il faut savoir

- **Strictement personnelle.** Chaque personne relit et corrige sa mémoire dans **Réglages →
  Mémoire**. Un administrateur ne la voit pas.
- **Hors de la sauvegarde.** La sauvegarde de l'instance ne contient pas la mémoire des personnes ; une
  restauration n'y touche pas.
- **Couper ne supprime rien.** Les souvenirs restent, chacun peut encore les relire et les retirer, et
  ils reviennent tels quels si la mémoire est réactivée. Le **contexte** de chaque personne, lui,
  continue d'être relu.
- **Ce qui est relu reste borné.** 2 000 caractères au plus par conversation : la mémoire ne fait pas
  grossir indéfiniment chaque message.
- **Rien n'est appris d'un contenu lu.** Ce que MIA note d'elle-même — ou après avoir lu un email, un
  fichier ou une page — attend la confirmation de la personne avant de servir. Pendant l'apprentissage,
  un souvenir n'est retenu d'emblée que si la personne l'a dit elle-même, dans un de ses messages ; ce
  que MIA déduit, ou ce qui vient d'un texte collé ou d'une de ses réponses, attend un « oui ».
- **Ce que l'apprentissage refuse.** Toute donnée sensible (santé, religion, opinions, vie privée), ce
  qui concerne d'autres personnes, les adresses, liens et numéros, les consignes d'action (*« envoyer »*,
  *« mettre en copie »*). Ce que la personne a écrit, demandé ou confirmé n'est jamais modifié.
- **Chacun est prévenu, chacun peut refuser.** Une carte **MIA apprend à vous connaître** précède tout
  apprentissage ; chacun le refuse ou le reprend dans **Réglages → Mémoire**. Rien n'est appris pour
  les comptes de démonstration.
- **Un fournisseur permis, sinon le modèle par défaut.** Le modèle choisi sert à chaque personne dont
  les groupes permettent au moins un modèle du même fournisseur — même si ce modèle-là n'est pas dans
  leur liste : ses messages partent chez un fournisseur qu'ils acceptent déjà. Pour les autres, le
  modèle par défaut. Jamais un fournisseur que les groupes d'une personne excluent, jamais Perplexity.
- **Les tâches programmées et les workflows** ne relisent que ce que la personne a écrit, demandé ou
  confirmé, et ne retiennent rien.
- **Les questions sont rares et gratuites.** Au plus une par jour pendant que MIA fait connaissance,
  puis une par semaine (chacun peut choisir *De temps en temps* ou *Jamais*), jamais deux dans une
  conversation. Elles sont écrites par MIA elle-même et la réponse est enregistrée sans appel au
  modèle.
- **Le coût.** Quelques centaines de tokens du modèle de la conversation par demande faite en
  conversation, sur la ligne **Mémoire (demandes explicites)** de la catégorie *Assistance de
  conversation* (Consommation), **dans le quota** de l'utilisateur. L'apprentissage — au plus six
  relectures par personne et par jour — est **hors quota** et **compté au budget IA**, ligne **Mémoire
  (apprentissage)** ; il se met en pause dès que le budget passe en alerte. Relire la mémoire ne coûte
  que la place qu'elle prend dans le message ; ce qui se fait sur la page **Mémoire** est gratuit.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| MIA dit ne rien pouvoir retenir | Vérifier que la carte **Mémoire** est **Active**. |
| Un souvenir n'est pas pris en compte | Il attend peut-être une confirmation : la personne le valide dans **Réglages → Mémoire**, partie **À confirmer**. Au-delà de 2 000 caractères, ce qu'elle a écrit, demandé ou confirmé passe en premier. |
| MIA n'apprend rien d'elle-même | Vérifier la case **Apprendre des conversations** et le budget IA (en pause dès l'alerte). La personne a peut-être refusé l'apprentissage, ou n'a pas encore vu la carte d'information. |
