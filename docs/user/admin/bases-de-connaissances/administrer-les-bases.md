---
title: Administrer les bases
description: Voir toutes les bases de l'instance, désactiver ou supprimer, reprendre celles d'un créateur parti, et réindexer — au besoin sur un autre modèle d'embedding.
sidebar:
  order: 1
---

**Espaces → Bases de connaissances** — la tuile **Bases** de la barre latérale. Un administrateur y
voit **toutes** les bases de l'instance — inactives, système et orphelines comprises — et peut les
modifier, les partager et les supprimer, sans limite de nombre.

## Lire la page

En tête, le volume total : taille, nombre de bases, nombre de documents — suivi d'un résumé des bases
**en cours**, **en échec** et **à vérifier**, quand il y en a. Dès deux bases, des filtres par source,
un champ **Filtrer (nom, assistant, personne…)** et un menu **Trier par**.

Chaque carte porte :

- des pastilles : **Système**, **Partagée avec vous**, **Inactive** ;
- l'état de la base, mis à jour **en direct** : synchronisation en attente ou en cours avec sa
  progression, indexation en cours, **À jour** ou **Prête**, documents à vérifier, échec avec son
  motif. Une pastille de couleur au coin du logo de la source le résume : vert, bleu, ambre, rouge ;
- le volume, le nombre d'utilisations — ou « jamais utilisée » ;
- le nombre d'assistants liés, qui y a accès, le bouton **Modifier** (**Ouvrir** pour la base
  système) et ↻ pour relancer une synchronisation.

**Cliquer une carte affiche, à droite, le schéma de la base** — sous la carte sur un écran étroit :
ce qui l'alimente, son moteur d'indexation, son état, les assistants qui l'interrogent et qui y a
accès. **Modifier** ouvre sa fiche, en pleine page, avec le même schéma à côté ; tout s'y enregistre
seul. **Réindexer** et le détail des extraits sont dans sa section **Avancé**, **Supprimer cette
base** en bas.

Une base sans créateur affiche **Système** comme propriétaire.

## Désactiver une base

Ouvrez sa fiche et coupez l'interrupteur **Active**, à côté du titre. Elle n'est plus consultée par
les assistants ni proposée dans le chat, **sans message** pour personne. Sa synchronisation, elle,
continue. Préférez la désactivation à la suppression tant que vous n'êtes pas sûr.

## Supprimer une base

**Supprimer cette base**, en bas de sa fiche : « Supprimer la base « X » et tous ses documents ? Les
assistants liés perdront cette source. Action irréversible. »

## Réindexer une base — et changer de modèle d'embedding

**Réindexer**, dans la section **Avancé** de la fiche de la base, recalcule l'empreinte de tous les
documents à partir des textes déjà extraits, en tâche de fond. Utile après une panne du moteur
d'indexation, ou pour changer de moteur.

1. Ouvrez la fiche de la base, puis la section **Avancé** : **Réindexer avec** présélectionne le
   **Modèle d'embedding** actuel.
2. Gardez-le, ou choisissez-en un autre parmi ceux dont la clé est enregistrée dans **Modèles IA →
   Clés API**. Le texte sous la liste dit ce qui va changer.
3. Cliquez **Réindexer la base**, puis confirmez : « Réindexation lancée — suivez l'avancement sur
   les documents de la base. » — avec le nom du nouveau modèle quand vous en avez changé.

Pendant l'opération, la base reste interrogeable : par les mots, le temps que ses documents
retrouvent leurs empreintes. La réindexation est décomptée à nouveau, hors quota. Elle ne répare pas
un document en échec d'extraction : il faut le supprimer et le redéposer.

Si le modèle actuel n'apparaît pas dans la liste, la clé de son fournisseur n'est plus enregistrée :
la base ne peut plus calculer ses empreintes et ne répond que par les mots. Réindexez-la sur un
modèle disponible.

Il n'existe pas de réindexation globale : chaque base se relance depuis sa fiche, ou depuis **Vérifier
la base vectorielle** — qui garde, elle, le modèle actuel.

**Vérifier une base de près.** La même section **Avancé** montre ses **extraits vectorisés** : chaque
extrait tel qu'il est enregistré, document par document, avec la mention **Vectorisé**, **Sans
vecteur** ou **Vecteur non vérifié**. Utile pour constater qu'une indexation est incomplète, ou
comprendre pourquoi une question ne remonte pas le passage attendu.

## Reprendre les bases d'un créateur parti

Quand un créateur est supprimé, ses bases restent, **sans créateur** : elles continuent de servir aux
assistants liés, et seuls les administrateurs peuvent les gérer. Pour en confier une à un autre
créateur, partagez-la-lui avec **peut modifier**.

## La base système

La base de l'assistant **MIA : mode d'emploi** porte le badge **Système**. Elle est alimentée par les
guides de MIA et mise à jour à chaque déploiement. Seule **Réindexer** est possible ; toute autre
modification est refusée.

## Point de vigilance : le contenu partagé

Une base — surtout synchronisée depuis un dossier — est une **copie** : quiconque y a accès, ou
dialogue avec un assistant qui la consulte, peut en obtenir le contenu, quels que soient ses droits
sur les fichiers d'origine. Vérifiez les partages des bases sensibles, et les groupes des assistants
qui les consultent.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| **+ Nouvelle base** grisé — « Configurez une clé API d'embedding » | Enregistrer une clé OpenAI, Mistral ou Gemini dans **Modèles IA → Clés API**. |
| « Aucun modèle d'embedding disponible… » | Même cause. |
| « Modèle d'embedding indisponible : la base n'a pas été réindexée. » | La clé du fournisseur choisi a été retirée entre-temps. Rouvrir **Avancé** et choisir un modèle de la liste. |
| Une base est « Inactive » sans que personne ne l'ait voulu | Son créateur ou un co-gestionnaire l'a désactivée. La réactiver. |
| « Base système : … Seule la réindexation est possible. » | La base de l'assistant d'aide ne se modifie pas. |
