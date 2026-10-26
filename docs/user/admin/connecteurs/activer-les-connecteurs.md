---
title: Activer les connecteurs
description: Rendre visible ou masquer chaque connecteur pour l'organisation, et régler les expressions qui le font suggérer dans le chat.
sidebar:
  order: 1
---

**Administration → Capacités → Connecteurs.** Une carte par connecteur : Recherche Internet, Google Drive, Gmail,
Google Agenda, Google Tasks, Outlook, Calendrier Outlook, Microsoft To Do, Microsoft Teams, Slack, OneDrive. Une carte en tête de
page, **Laisser MIA choisir**, règle l'activation des connecteurs par MIA elle-même.

## Lire une carte

- l'icône, le nom et les capacités du connecteur ;
- l'interrupteur **Visible** / **Masqué** ;
- où il apparaît chez les utilisateurs — dans le menu **+**, ou dans **Réglages → Comptes connectés** ;
- **N comptes reliés** : les connexions valides ;
- un avertissement si son prérequis manque, avec le lien vers la page à compléter — par exemple
  « Identifiants Google absents — renseignez-les dans Organisation → Authentification… ».

## Masquer ou rendre visible un connecteur

Basculez l'interrupteur : l'effet est immédiat.

- **Masqué** : la ligne disparaît du menu **+** et la carte de la page **Comptes connectés**, et MIA refuse de
  s'en servir même si la demande arrive. Les comptes reliés sont **conservés** : ils reviennent tels
  quels si vous rendez le connecteur visible. Un utilisateur déjà relié voit sa carte en lecture
  seule, et peut se déconnecter.
- **Visible** : le connecteur est proposé, à condition que son prérequis existe. Sans identifiants, il
  reste invisible des utilisateurs, exactement comme s'il était masqué.

## Laisser MIA choisir ses connecteurs

La carte **Laisser MIA choisir** est **Proposé** par défaut : le menu **+** de chaque utilisateur
gagne une ligne du même nom, qu'il allume s'il le souhaite. Ses connecteurs **reliés** mais éteints
deviennent alors activables par MIA, pour la réponse en cours, quand la demande en a besoin.

- Seuls les connecteurs **visibles** et reliés par l'utilisateur sont concernés : masquer un
  connecteur le retire aussi de ce choix.
- Aucune écriture de plus : chaque action préparée passe par sa carte d'approbation.
- Côté coût, rien ne part au modèle tant qu'aucun connecteur n'est activé, hormis une courte
  définition ; un connecteur activé coûte ensuite comme s'il avait été allumé à la main.
- **Désactivé** : la ligne disparaît du menu **+** et MIA ne peut plus rien activer d'elle-même,
  même si un navigateur avait gardé l'interrupteur allumé.

## Régler les suggestions

Chaque carte a un bloc **Expressions déclencheuses** : les mots qui, dans un message, font proposer le
connecteur par une bannière.

- Une expression par ligne ; mots entiers ; accents et majuscules ignorés.
- Une liste vide supprime toute suggestion pour ce connecteur.
- Le badge **Personnalisées** signale une liste modifiée ; **Réinitialiser** rétablit celle livrée
  avec MIA.
- L'enregistrement est automatique ; l'effet vaut au prochain chargement du chat.

## Ce qui ne se règle pas ici

- **Qui** a droit à un connecteur : il n'y a pas de réglage par groupe. Seul OneDrive est réservé aux
  créateurs et aux administrateurs.
- **Ce que** chaque connecteur peut faire : c'est fixé par MIA — jamais d'envoi d'email, jamais de
  suppression de fichier. Voir [Les connecteurs](index.md).
- Les connexions d'un utilisateur : lui seul les relie ou les déconnecte. La suppression de son compte
  les efface dans MIA.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Échec — le connecteur n'a pas été modifié. » | Réessayer ; si cela persiste, consulter les logs. |
| Un connecteur visible n'apparaît chez personne | Son prérequis manque : suivre l'avertissement de la carte. |
| Les utilisateurs ne voient pas la ligne **Recherche Internet** | Elle est masquée ici, ou leur modèle ne sait pas chercher. |
