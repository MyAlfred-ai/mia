---
title: Utiliser les outils d'un assistant
description: Laisser un assistant générer une image, déclencher une automatisation n8n ou produire une présentation Gamma, et approuver ce qu'il propose.
sidebar:
  order: 7
---

Certains assistants ont leurs propres outils : ils les appellent eux-mêmes, quand votre demande le
demande, sans que vous ayez à ouvrir le menu **+**. Ce qu'est un assistant :
[Les assistants](../assistants/index.md).

## Avant de commencer

Vérifiez ce que l'assistant sait faire : cliquez son nom dans le champ de saisie. La ligne **Outils**
liste ses outils, marqués « Outil de cet assistant — appelé automatiquement par le modèle ».

## Étapes

1. Démarrez une conversation avec l'assistant — voir
   [Discuter avec un assistant](../assistants/discuter-avec-un-assistant.md).
2. Faites votre demande en langage courant : *« ouvre un ticket pour l'imprimante du 3ᵉ étage »*,
   *« prépare une présentation de 10 slides sur notre offre »*, *« une illustration pour ce
   paragraphe »*.
3. Pour une **automatisation n8n** ou une **présentation Gamma**, une carte d'approbation s'affiche —
   « Exécuter un workflow n8n », « Générer une présentation Gamma » — avec « Votre approbation est
   requise — rien ne sera fait sans elle. ». Relisez, puis **Approuver** ou **Annuler**.

Une **image** est générée directement, sans carte.

## Résultat

- **Image** : elle apparaît dans la réponse ; l'assistant choisit seul de retoucher la précédente ou
  d'en créer une nouvelle, dans l'esprit de sa charte. Il peut aussi partir des **images** de ses
  documents de référence et des bases de la conversation — un personnage, un produit, un logo : la
  ligne **D'après « … »** sous l'image les nomme. Voir
  [Générer une image](generer-une-image.md).
- **n8n** : un message ✅ affiche le résultat renvoyé par l'automatisation.
- **Gamma** : un message ⏳ pendant la fabrication, puis ✅ avec le lien de la présentation et, si
  prévu, son export — valable environ une semaine : téléchargez-le.

## Cas particuliers

- n8n et Gamma ne consomment pas vos crédits MIA : ils sont facturés par leur propre service. Une
  image, elle, est décomptée comme toute création.
- Les résultats Gamma et n8n n'apparaissent pas dans **Mes créations**.
- Arrêter le suivi d'une présentation Gamma n'arrête pas sa fabrication chez Gamma.
- Une présentation PowerPoint livrée dans le chat se confie aussi à Gamma, sans assistant : bouton
  **Version soignée** de sa carte, puis **Par Gamma** — voir [Télécharger un fichier créé par MIA](../conversations/telecharger-un-fichier.md).

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Outil n8n/Gamma indisponible (désactivé, supprimé ou incomplet) — prévenez un administrateur. » | L'outil a été retiré ou n'est plus autorisé pour votre groupe. Prévenir un administrateur. |
| « Crédits Gamma épuisés : la génération a été refusée… » | L'abonnement Gamma de l'organisation est épuisé. Prévenir un administrateur. |
| L'assistant ne propose jamais son outil | L'outil n'est pas autorisé pour votre groupe, ou le modèle de l'assistant ne sait pas appeler d'outils. Voir avec un administrateur. |
