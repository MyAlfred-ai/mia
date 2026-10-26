---
title: Revoir l'historique des exécutions
description: Retrouver chaque lancement de vos workflows et relire, brique par brique, ce qui a été fait, ce qui a été remis, et ce qui a échoué.
sidebar:
  order: 13
---

Chaque lancement d'un workflow laisse un **journal** : ce qu'il traitait, quand il est parti et
comment, ce qu'il a remis, combien de temps il a pris, ce que chaque brique a fait, les actions
proposées et leur sort, les erreurs et les reprises — et, à déplier, le **contenu** de tout ce qui a
circulé d'une brique à l'autre : chaque pièce en entier, chaque email écrit tel qu'il est parti.
L'onglet **Historique des exécutions** les rassemble tous, du plus récent au plus ancien.

## Avant de commencer

L'historique ne montre que **vos** lancements. Rien n'est à activer : un lancement apparaît dès qu'il
démarre, y compris ceux d'avant cette fonctionnalité.

## Étapes

1. Ouvrez **Workflows**, onglet **Historique des exécutions**. Depuis un workflow, le bouton
   **Historique** (en haut, à côté de **Réglages**, avec le nombre de lancements) y mène directement,
   filtré sur ce workflow ; depuis la page d'une exécution, **Historique** l'ouvre sur ce lancement.
2. À gauche, la liste des **lancements**, rangés par jour. Chaque ligne dit de quel workflow il s'agit
   (sa couleur, son nom, le numéro du lancement), **ce qu'il traitait** (les champs remplis au départ,
   l'objet d'un email reçu, le nom d'un fichier), l'heure, ce qui l'a lancé, sa durée, la version du
   workflow, et une bande qui montre jusqu'où il est allé (une case par brique, de la couleur de son
   issue). Un échec affiche sa cause, une exécution en cours la brique atteinte.
3. **Filtrez** en haut de la liste : un seul workflow, et les lancements **terminés**, **en échec**,
   **en cours** ou **arrêtés** (le nombre de chacun est indiqué). **Afficher les lancements plus
   anciens** charge la suite.
4. Touchez un lancement : son **journal** s'ouvre à droite (au téléphone et sur un écran étroit, à la
   place de la liste — **Tous les lancements** ou le retour du navigateur pour revenir). Au clavier,
   les flèches passent d'un lancement à l'autre.
5. En tête du journal, les gestes :
   - **Décider dans l'exécution** quand il vous attend, **Reprendre l'exécution** s'il est arrêté sur
     une erreur ou en pause, **Suivre en direct** s'il est en cours, sinon **Ouvrir l'exécution** ;
   - **Conversation**, le fil où les étapes ont écrit ;
   - **Relancer** : la fenêtre de lancement s'ouvre, remplie avec les données de ce lancement, à
     vérifier avant de lancer (les fichiers sont à rejoindre) ;
   - le **nom du workflow**, en haut, ouvre l'éditeur.

## Ce que montre le journal

- **En tête** : le workflow, l'état, la date, ce que le lancement traitait, le déclencheur, la durée,
  le nombre de briques faites, le coût en crédits, la version (signalée quand une plus récente est
  publiée), la **cause** d'un arrêt, et le **résultat** d'un lancement terminé — les pièces du
  livrable, avec un aperçu et leur téléchargement.
- **Le déroulé**, une entrée par passage de brique, à son heure :
  - qui l'a faite (l'assistant et son modèle, MIA, l'outil, ou vous) et combien de temps elle a pris ;
  - son issue : terminée, la voie choisie et pourquoi, la réponse d'une condition (avec les valeurs
    lues par la règle), une validation approuvée ou renvoyée avec votre remarque ;
  - la **pièce remise**, avec un aperçu (le début d'un texte, les champs d'une Fiche, l'image créée)
    et son téléchargement ; **Voir le contenu** la déplie en entier (cf. ci-dessous) ;
  - **ce qui a été fait** : les recherches et lectures menées dans vos outils ;
  - les **actions proposées** et leur sort : approuvée et faite, faite sans validation, refusée,
    en échec, expirée sans décision, ou encore en attente ; un email dit s'il a été **envoyé** ou
    seulement rangé en **brouillon**. **Voir l'email** (ou **Voir le contenu** pour une autre action)
    montre ce que l'action a porté ;
  - les **livraisons** du livrable et leur issue, chacune avec son contenu à déplier ;
  - les **erreurs**, les **nouveaux essais automatiques** et les briques **reprises ensuite**.
- **Au départ**, les champs remplis ; quand l'un d'eux est long, **Voir tout le départ** le montre en
  entier. Chaque fichier reçu au départ a sa carte : le texte lu dedans se déplie, le fichier se
  télécharge.
- Dans une boucle, chaque tour est rangé sous elle, avec l'élément traité. La boucle dit combien
  d'éléments ont été traités, **en échec**, **écartés** par une condition ou **laissés par le
  filtre** ; une condition de la boucle porte « Élément écarté » quand elle passe au suivant. Les
  relectures de la boucle disent combien d'éléments ont été gardés, ou combien d'actions ont été
  exécutées et écartées.
- Un lancement **en cours** se met à jour tout seul, dans la liste comme dans le journal ; ce qui vous
  attend porte **Décider dans l'exécution**.

## Relire ce qui a circulé

Tout ce qu'une brique a remis ou fait se déplie dans le journal, sans quitter la page :

- **un email** (préparé ou envoyé par une brique « Préparer ou envoyer un email », ou par une
  livraison) : les destinataires (**À**, **Cc**), l'**objet**, le message tel qu'il est parti, mise en
  forme comprise, les **pièces jointes**, les contenus lus avant de l'écrire, et **Ouvrir dans Gmail**
  (ou Outlook) pour le retrouver dans votre messagerie ;
- **un message** Slack ou Teams, **un évènement** (titre, début, fin, lieu, invités, description),
  **une tâche**, **un document** ou **une feuille** créés dans Drive, les **données** envoyées à un
  workflow n8n ;
- **un texte** ou **un document** en entier, mis en forme ; **une liste** et **un tableau** complets ;
  **une Fiche** avec tous ses champs ; **un fichier reçu** avec le texte lu dedans ; **une image** en
  grand avec sa demande ; **un itinéraire** et ses étapes ;
- **une page web** : **Voir la page** l'affiche dans un cadre isolé (elle ne touche ni à votre session
  ni à MIA) ; agrandissez-le à la souris, ou téléchargez la page.

Un dépliant ouvert le reste quand le journal se met à jour. Un texte très long s'arrête après
60 000 caractères : le téléchargement de la pièce a tout.

## Bon à savoir

- Une brique refaite (renvoyée avec une remarque, ou reprise après une erreur) apparaît à chacun de ses
  passages ; seul le dernier montre la pièce, que le dossier garde dans sa dernière version.
- L'adresse de la page garde les filtres et le lancement ouvert : un lien vers elle rouvre directement
  son journal.
- Supprimer la conversation d'une exécution ne retire pas le lancement de l'historique : le déroulé
  reste, sans le détail des actions menées (ni le contenu des emails). Supprimer le workflow efface ses
  lancements.
- Un email envoyé avant cette version dit « Approuvée et faite » sans préciser s'il est parti : son
  contenu, lui, se relit.
