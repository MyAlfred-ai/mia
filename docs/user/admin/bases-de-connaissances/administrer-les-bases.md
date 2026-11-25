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

En tête, la **recherche** : un nom, une description, le nom d'un document, d'un créateur ou d'un
assistant lié — sans tenir compte des majuscules ni des accents. Dessous, les filtres **Tout**,
**Bases**, **Tables**, puis **Privées**, **Communes**, **Publiques**, avec leur nombre ; les cartes
**Nouvelle base** et **Nouvelle table** ; puis une ligne par base :

- sa source (fichiers déposés, Drive, OneDrive, SharePoint, Google Agenda, cerveau MyBrain) ;
- ses documents ou ses lignes, et sa dernière utilisation — ou « jamais utilisée » ;
- l'icône de qui la voit : cadenas (**Privée**), groupe (**Commune**), globe (**Publique**) ;
- son état, mis à jour **en direct** : synchronisation en attente ou en cours, indexation en cours,
  **À jour** ou **Prête**, documents sans texte ou en échec, synchronisation en échec. Un point de couleur le résume : vert, bleu, ambre,
  rouge.

**Cliquer une ligne ouvre la page de la base** : son état et sa source (**Synchroniser
maintenant**), ses chiffres, **Que sait-elle ?** — une recherche dans ses extraits, sans appel à un
modèle —, ses documents, les assistants qui l'interrogent, qui la voit, et tout en bas son schéma.
**Modifier** ouvre sa fiche, en pleine page : un menu de sections à gauche (**Identité**,
**Documents**, **Qui la voit**, **Assistants**, **Avancé**, **Vue d'ensemble**) ; tout s'y
enregistre seul, **Terminé** ramène à la page. **Réindexer** et le détail des extraits sont dans la
section **Avancé** ; **Supprimer cette base** est dans le menu **…** de la barre du haut.

Une base indexée mais peu exploitable — scans sans texte, presque rien à lire, plusieurs versions
d'un même document, surtout des tableaux, ou un fourre-tout très volumineux — affiche **N points à
améliorer** sur sa page ; un clic mène à leurs conseils sur la fiche.

Une base sans créateur affiche **Système** comme propriétaire.

**Les trois visibilités.** **Commune** (le choix par défaut) : son créateur, les administrateurs, et
les personnes ou groupes à qui elle est partagée. **Publique** : toute l'organisation la voit et la
coche dans le chat ; seuls son créateur et les administrateurs la modifient. **Privée** : son
créateur seul. Un administrateur peut passer une base en **Commune** ou en **Publique**, jamais en
**Privée** à la place de son créateur.

**Les bases « Privées » n'y figurent pas.** Un créateur peut réserver une base à lui seul : aucun
administrateur ne la voit, ne la modifie, ne la partage, ne la coche dans le chat ni ne l'interroge
à travers un assistant — et seul son créateur peut lever la réserve. Dans les écrans
d'ensemble (**Coûts → Consommation**, le moteur d'indexation), elle reste comptée sans son nom. Elle
est supprimée avec le compte de son créateur. Les sauvegardes de l'instance la contiennent : la
règle vaut dans MIA, pas pour qui détient le serveur ou ses sauvegardes.

## Les tables

Une **table** est une base de lignes — un fichier clients, une grille de tarifs — que MIA lit ligne
par ligne au lieu de la découper en extraits. Elle figure dans la même liste, sous **Tables** ;
cliquer sa ligne ouvre sa page : ses lignes, à lire et à filtrer, ce que MIA peut y faire et qui la
voit.

- **Qui en crée.** Le droit de **créer** une base (créateurs et administrateurs par défaut) : par
  la carte **Nouvelle table**, ou par **Garder** depuis le volet **Tables** d'une conversation. Un créateur
  tient **10 tables** au plus, comptées à part de ses bases de documents ; avec le droit **Gérer**,
  il n'y a pas de limite.
- **Ce qu'elle contient.** 20 000 lignes et 40 colonnes au plus. Un fichier plus long est refusé.
- **Qui la voit, qui s'en sert.** Les trois visibilités, le partage, les assistants liés et
  l'interrupteur **Active** valent comme pour une base.
- **Ce que MIA y écrit.** Rien sans accord : une carte montre les valeurs avant et après, et seule
  une personne qui peut **modifier** la table se la voit proposer. Sur la fiche, **MIA peut → Lire
  seulement** ferme l'écriture pour tout le monde. Une tâche programmée réglée sur **Agir sans
  validation** dépose quand même une carte.
- **Son historique.** Chaque changement s'y inscrit — qui, quand, quoi —, gardé 90 jours (au moins
  les 30 derniers états, 60 au plus). Le bouton **Historique** de sa page l'ouvre à quiconque voit
  la table ; seule une personne qui peut la **modifier** la remet dans un état précédent.
- **Ce qui ne la concerne pas.** Ni vectorisation, ni réindexation, ni synchronisation avec un
  dossier, ni dépôt de documents : le moteur d'indexation ne la connaît pas.

Les tables font partie de la sauvegarde de l'instance, lignes et historique compris. Voir
[Créer et tenir une table](../../fonctionnalites/bases-de-connaissances/tenir-une-table.md).

## Désactiver une base

Ouvrez sa fiche et coupez l'interrupteur **Active**, à côté du titre. Elle n'est plus consultée par
les assistants ni proposée dans le chat, **sans message** pour personne. Sa synchronisation, elle,
continue. Préférez la désactivation à la suppression tant que vous n'êtes pas sûr.

## Supprimer une base

**…** à côté du titre de sa fiche, **Supprimer cette base** : « Supprimer la base « X » et tous ses documents ? Les
assistants liés perdront cette source. Action irréversible. »

## Réindexer une base — et changer de modèle d'embedding

**Réindexer**, dans la section **Avancé** de la fiche de la base, recalcule l'empreinte de tous les
documents à partir des textes déjà extraits, en tâche de fond. Utile après une panne du moteur
d'indexation, ou pour changer de moteur.

1. Ouvrez la fiche de la base, puis la section **Avancé** : **Réindexer avec** présélectionne le
   **Modèle d'embedding** actuel.
2. Gardez-le, ou choisissez-en un autre parmi ceux dont la clé est enregistrée dans **Modèles IA →
   Fournisseurs et modèles**. Le texte sous la liste dit ce qui va changer.
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
créateur, partagez-la-lui avec **peut modifier**. Ses bases **Privées**, elles, sont
supprimées avec le compte : elles ne servaient qu'à lui.

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
| La carte **Nouvelle base** est grisée — « Une clé API d'embedding est requise. » | Enregistrer une clé OpenAI, Mistral ou Gemini dans **Modèles IA → Fournisseurs et modèles**. |
| « Aucun modèle d'embedding disponible… » | Même cause. |
| « Modèle d'embedding indisponible : la base n'a pas été réindexée. » | La clé du fournisseur choisi a été retirée entre-temps. Rouvrir **Avancé** et choisir un modèle de la liste. |
| Une base est « Inactive » sans que personne ne l'ait voulu | Son créateur ou un co-gestionnaire l'a désactivée. La réactiver. |
| « Base système : … Seule la réindexation est possible. » | La base de l'assistant d'aide ne se modifie pas. |
