---
title: Appeler un autre workflow
description: La brique « Appeler un workflow » lance un autre de vos workflows au milieu du vôtre, avec ses paramètres, attend qu'il ait fini et récupère son résultat comme une pièce.
sidebar:
  order: 9
---

La brique **Appeler un workflow** lance, à une étape du vôtre, **un autre de vos workflows** : vous
lui donnez ce qu'il attend au départ (ses **paramètres**), il fait son travail, et **son résultat
revient au dossier** comme une pièce que les briques suivantes reprennent. Un travail réglé une fois
— analyser un client, chiffrer un devis, préparer un compte rendu — se réutilise ainsi dans
plusieurs workflows, au lieu d'être recopié dans chacun.

Sur le rail, elle ne ressemble à aucune autre : l'**icône et la teinte de l'espace Workflows** (comme
la tuile de la barre latérale), une **pile de cartes** sous la brique — un workflow entier tient
dedans —, le surtitre **Appel de workflow**, et le workflow appelé **en miniature** : sa couleur et
sa silhouette, une station par brique.

## Avant de commencer

- Le workflow appelé doit être **à vous**, **publié** et **actif** (pas en pause). C'est toujours sa
  **version publiée** qui est lancée : le modifier puis le publier change ce que font tous les
  workflows qui l'appellent.
- Un workflow ne peut pas **s'appeler lui-même**, ni appeler un workflow qui finirait par le
  rappeler. Trois niveaux d'appels au plus (A appelle B, qui appelle C, qui appelle D).
- Le workflow appelé tourne **avec vos droits**, comme tout workflow : ses étapes consomment vos
  crédits, et ce qu'il dépense s'ajoute au coût de l'exécution qui l'a appelé.

## Étapes

1. **+** sur le rail → sous **Vos workflows**, choisissez celui à appeler (ou **Un autre workflow…**
   pour voir toute la liste). La brique s'ajoute, titrée « Appeler » suivi de son nom.
2. **Ce que vous lui donnez** : un champ par **champ de son départ**, avec sa nature (texte, nombre,
   date, email, oui/non, choix, fichier) et la mention **obligatoire** s'il l'est.
   - Écrivez une **valeur** (« Durand SA », « 12 500 », « 15/12/2026 »), ou tapez **@** pour citer une
     **pièce** ou un **champ d'une Fiche** de votre dossier.
   - Un champ **Choix** montre ses options : touchez-en une.
   - Un champ **Fichier** reçoit **une pièce** du dossier, choisie dans la liste : elle lui est
     transmise en fichier.
   - Les champs qui portent le même nom dans votre dossier sont **remplis d'office** : le plus
     souvent, il ne reste qu'à vérifier.
3. **Ce que vous récupérez** : une **pièce de son livrable**, sous sa forme (une Fiche garde ses
   champs, qu'une règle ou une consigne peut citer), ou **Tout son livrable, en un texte**. Donnez-lui
   un **nom** : c'est sous ce nom que la suite la cite avec **@**.
4. Au besoin, réglez **S'il s'arrête avant la fin** (voir plus bas) et **S'il échoue**.
5. **Publiez**. Tant qu'un paramètre obligatoire manque ou qu'une valeur n'est pas de la bonne
   nature, la publication est refusée et **À régler** dit quoi corriger.

## Ce que MIA vérifie pour vous

Dès que vous réglez la brique, puis à la publication et **avant chaque lancement** :

| MIA vous prévient quand… | Ce qui se passe |
|---|---|
| un paramètre **obligatoire** n'a pas de valeur | publication refusée |
| une valeur écrite n'est **pas de la bonne nature** (« bientôt » pour une date, « beaucoup » pour un nombre, une option qui n'existe pas) | publication refusée |
| vous citez un champ d'une **autre nature** (une date là où il faut un nombre) | publication refusée |
| vous citez un **texte libre** là où il faut un nombre, une date ou une adresse | avertissement : vérifiez qu'il ne contiendra que cela |
| un champ **Fichier** reçoit autre chose qu'une pièce | publication refusée |
| le workflow appelé n'existe plus, n'est **pas publié** ou est **en pause** | publication refusée |
| les deux workflows **s'appelleraient sans fin**, ou il y a trop de niveaux | publication refusée |
| le workflow appelé a **changé** depuis : un champ a disparu, la pièce récupérée n'est plus dans son livrable ou a changé de forme | à corriger en rouvrant la brique |

Au lancement, MIA vérifie aussi que le workflow appelé **pourra aller au bout** (un compte à relier,
un outil hors service) : sinon rien ne démarre, et la fenêtre dit quoi régler.

## Pendant l'exécution

- La brique passe **en cours** pendant que le workflow appelé travaille. Il a **sa propre
  exécution** et **sa propre conversation** : un lien **Ouvrir son exécution** y mène, et sa page dit
  par quel workflow il a été appelé.
- S'il **vous attend** (une validation, une action à approuver), c'est dit sur la page de votre
  workflow et dans le volet **Avancement** : décidez **dans son exécution**, la vôtre reprend toute
  seule dès qu'il a terminé.
- S'il **échoue**, votre workflow s'arrête sur la brique avec la cause. **Reprendre** — d'un côté
  comme de l'autre — le relance **là où il s'est arrêté** : rien de ce qu'il a déjà fait n'est refait.
- S'il **s'arrête avant son livrable** (une voie réglée sur « arrêter », une brique **Arrêter le
  workflow**), ce n'est pas une erreur : par défaut votre workflow **s'arrête aussi**, proprement ;
  réglez **S'il s'arrête avant la fin** sur **Continuer sans son résultat** pour poursuivre sans sa
  pièce.
- **Arrêter** votre exécution arrête aussi celle du workflow appelé.

## Exemples

- **Dossier client complet** : une étape prépare le brief, la brique appelle « Analyse client » avec
  le nom du client et le montant du devis, récupère sa Fiche (verdict, score), et une étape rédige la
  proposition d'après elle.
- **Le même traitement pour chaque ligne** : dans une boucle « pour chaque », l'appel lance le
  workflow une fois par élément ; les Fiches revenues sont rassemblées en tableau à la sortie.
- **Un workflow qui démarre d'habitude sur un email** : appelé, il reçoit à la place de l'email le
  texte ou la pièce que vous lui donnez.

## À savoir

- Un appel à l'intérieur d'un workflow appelé fonctionne de la même façon : la fin, une attente ou
  une erreur **remontent** de proche en proche jusqu'au premier workflow.
- Le coût d'une exécution **comprend** celui des workflows qu'elle a appelés ; l'estimation avant
  lancement compte l'appel au coût constaté des dernières exécutions du workflow appelé.
- Chaque appel apparaît dans **l'historique** du workflow appelé, avec la mention **Appelé par un
  workflow** et un lien vers l'exécution appelante.
- Ne confondez pas avec le départ **à la fin d'un autre workflow** ([Démarrer sur un
  évènement](demarrer-sur-un-evenement.md)) : là, le second démarre **après** le premier et rien ne
  lui revient ; ici, le premier **attend** le second et **reprend avec son résultat**.
- Vous pouvez aussi le demander à MIA dans le chat : « crée un workflow qui appelle Analyse client
  puis rédige la proposition » — elle pose la brique et ses paramètres ; vous vérifiez sur la carte.
