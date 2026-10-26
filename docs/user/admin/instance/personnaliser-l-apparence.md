---
title: Personnaliser l'apparence
description: Donner à l'application le nom, les logos, les couleurs et le message d'accueil de l'organisation, et choisir sa langue par défaut.
sidebar:
  order: 2
---

**Administration → Organisation → Marque & apparence.** La page s'enregistre seule ; un aperçu
montre le résultat.

Elle habille l'**application** : ce qu'on lit et ce qu'on voit à l'écran. L'entreprise qui utilise
MIA — son nom, son descriptif, son logo et son modèle PowerPoint — a sa propre page :
[Présenter l'entreprise](presenter-l-entreprise.md).

## Identité

| Réglage | Par défaut | Où il s'affiche |
|---|---|---|
| **Nom de l'application** | MIA | Barre latérale, en-tête mobile, page de connexion, application installée |
| **Punchline (sous le nom)** | « Votre assistant IA multi-modèles » | Barre latérale et page de connexion, sur une ligne |
| **Message d'accueil** | « Comment puis-je vous aider ? » | Écran d'une nouvelle conversation : « Prénom, comment puis-je vous aider ? » |
| **Langue par défaut** | Langue du navigateur, sinon français | Voir ci-dessous |

Laissés tels que livrés, la punchline et le message d'accueil sont **traduits** dans la langue de
chacun. Réécrits, ils s'affichent tels quels, dans toutes les langues.

Le texte affiché **sur la page de connexion** se règle ailleurs : **Organisation → Authentification**,
section **Message sur la page de connexion**.

## La langue par défaut

MIA choisit la langue de chaque personne dans cet ordre : son choix enregistré, celui fait sur la page
de connexion, la langue de son navigateur, **la langue par défaut de l'instance**, et enfin le
français. La langue par défaut ne sert donc qu'aux navigateurs réglés ni en français ni en anglais.
Elle fixe aussi la langue de l'application installée.

Les écrans d'administration suivent, eux aussi, la langue choisie par chaque administrateur.

## Logos

- **Logo principal** : PNG, JPG, GIF, WebP ou SVG, carré — 256 × 256 pixels recommandés. Il sert
  aussi d'icône d'onglet.
- **Logo au survol** *(facultatif)* : PNG, JPG ou GIF. Il remplace aussi le logo de la page **À
  propos**.

Un fichier d'un autre format est ignoré. L'icône de l'application installée sur un téléphone ne
change pas avec le logo. Le logo qui habille les présentations est celui de l'entreprise, déposé sur
la page [Entreprise](presenter-l-entreprise.md#logo).

## Apparence

- **Thème** : **Sombre** *(par défaut)* ou **Clair**. C'est le thème de toute personne qui n'en a
  pas choisi d'autre : chacun peut imposer le sien — clair, sombre ou celui de son appareil —,
  renforcer le contraste et régler la taille de l'interface dans ses propres réglages,
  **Réglages → Affichage**. Changer le thème ici ne modifie pas ces choix personnels.
- En **contraste renforcé** et en thème clair, la couleur d'accentuation s'affiche plus foncée chez
  la personne qui l'a choisi : sur fond blanc, une couleur de marque claire serait illisible.
- **Couleur d'accentuation** : un sélecteur, ou l'une des six pastilles proposées.

## Liens & contact

**Déclarer une idée ou un bug** — le lien du pied du chat —, **Adresse de contact**, **Nom de
l'éditeur**, **Site web de l'éditeur** : affichés dans le pied du chat et sur la page **À propos**.

## Application mobile

Le lien **TestFlight** de l'application iPhone, s'il a été publié pour votre organisation. Il doit
commencer par `https://`. Vide, la page `/app` propose d'installer MIA comme application web.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Le nouveau logo ne s'affiche pas | Vérifier son format ; recharger la page sans cache. |
| Le message d'accueil ne se traduit plus en anglais | Il a été personnalisé : un texte réécrit s'affiche tel quel, dans toutes les langues. Écrivez-le dans la langue de la majorité, ou en deux langues. |
| Le lien TestFlight n'apparaît pas sur `/app` | Il ne commence pas par `https://`. |
