---
title: Mettre en place un workflow, pas à pas
description: De l'idée au workflow qui tourne seul — le résultat visé, le départ, les étapes et leurs pièces, les consignes, les contrôles, les boucles, les actions, la livraison, puis l'essai et la mise en service.
sidebar:
  order: 0
---

Ce guide suit la mise en place d'un workflow du début à la fin, avec un exemple : **relancer chaque
client qui doit encore de l'argent**, à partir du fichier Excel de suivi. Les mêmes questions valent
pour n'importe quel processus ; chaque partie renvoie à la page qui détaille la brique.

## Avant de commencer

- Les workflows sont réservés aux **créateurs d'assistants et aux administrateurs** : tuile
  **Workflows** de la rangée **Espaces**.
- Reliez les services dont le travail a besoin (Gmail ou Outlook, Google Drive, l'agenda…) dans
  **Réglages → Comptes connectés** : un workflow lit et écrit avec **votre** connexion.
- Si un assistant connaît déjà le métier (son prompt, ses bases de connaissances), il fera mieux que
  MIA seule : préparez-le d'abord.

## 1. Partir du résultat

Écrivez en une phrase **ce que vous voulez obtenir à la fin** : c'est l'**objectif** du workflow
(**Réglages**, en haut de l'éditeur) et il guide chaque étape. Puis dites **ce que vous recevez** au
bout : un document, un tableau, des emails partis, des tâches créées.

> *Exemple* — objectif : « Relancer chaque client qui doit encore de l'argent, avec un email
> personnalisé, sans oublier personne ni écrire deux fois au même. » Résultat : les relances
> préparées, rassemblées dans le fichier complété.

Le plus simple pour démarrer : **Workflows → Nouveau workflow → Décrire à MIA** (ou le demander dans
le chat). MIA compose un brouillon complet avec ce que vous avez ; vous le relisez avec les questions
ci-dessous. Un **modèle** proche de votre besoin est aussi un bon départ : voir [Créer un
workflow](creer-un-workflow.md).

## 2. Choisir le départ

Deux questions : **quand** le workflow démarre, et **ce qu'il faut lui donner**.

- **À la main** (et, si vous cochez **Depuis le chat**, avec « @ » dans la zone de saisie) : vous
  remplissez ses champs au lancement.
- **Programmé** : chaque jour, chaque semaine… avec les valeurs par défaut de ses champs.
- **Sur un évènement** : un email qui arrive, un fichier déposé dans Drive, un logiciel qui appelle son
  adresse, un autre workflow qui se termine — voir [Démarrer sur un évènement](demarrer-sur-un-evenement.md).

Les **champs du départ** (texte, date, nombre, choix, email, oui/non, fichier) forment la **Fiche de
départ** ; un champ **Fichier** devient une pièce à lui seul. Ne demandez que ce que MIA ne peut pas
trouver ailleurs.

> *Exemple* — lancé à la main, un seul champ : **Fichier des clients** (Excel ou CSV).

## 3. Découper le travail en étapes et en pièces

Listez ce qu'un collègue ferait, dans l'ordre. **Chaque étape remet une seule pièce** au dossier, que
les suivantes reprennent : c'est ce qui rend un workflow fiable — chacun travaille sur le résultat
précis du précédent, pas sur un souvenir de conversation.

Choisissez la **forme** de chaque pièce selon l'usage qui suit :

| La suite doit… | Forme |
|---|---|
| lire un texte rédigé | **Texte** |
| parcourir plusieurs éléments | **Liste** ou **Tableau** |
| tester un montant, une date, un oui/non, écrire à une adresse | **Fiche** (des champs nommés et typés) |
| obtenir un fichier Word, PDF, PowerPoint, Excel | **Document** |

Donnez aux pièces des **noms parlants** (« Fiche facture », « Relance ») : vous les citerez partout.

## 4. Confier chaque étape

Dans le volet d'une étape :

- **Qui fait le travail** : MIA avec le modèle de votre choix, ou un **assistant** — il travaille alors
  avec son prompt, son modèle, ses bases et ses outils.
- **Ce qu'elle peut consulter** : Internet, vos connecteurs, jusqu'à trois bases de connaissances, un
  outil par type. Pour vos emails et vos fichiers, des **filtres** limitent ce qu'elle voit : voir
  [Filtrer les emails et les fichiers](filtrer-emails-et-fichiers.md).
- **Ce qu'elle peut faire dans les outils** : lire et rédiger (par défaut), préparer des actions à
  approuver, ou agir sans validation.

## 5. Écrire de bonnes consignes

- **Une consigne = un travail**, écrit comme pour un collègue : le but, ce qu'il faut regarder, la forme
  attendue, ce qu'il ne faut pas faire.
- **Citez les pièces avec @** plutôt que de les décrire : « Rédige une relance pour @[Client en cours]
  ». Citer un **champ** (« @[Client en cours › Email] ») met sa valeur exacte.
- **Dites les limites** : longueur, ton, « n'invente rien : laisse vide ce que le document ne dit pas
  ». Une étape tourne sans personne devant l'écran : elle ne peut pas vous poser de question.
- Pour une **Fiche**, nommez précisément ses champs et leur type : une condition ou une livraison les
  lira tels quels.

## 6. Placer les contrôles

- **Une validation** là où une erreur coûte (un montant, un engagement, un email à un client) — ou,
  plus simple, **Je relis la pièce avant la suite** dans l'étape. Vous pourrez approuver, corriger,
  renvoyer avec une remarque : voir [Valider et corriger](valider-et-corriger.md).
- **Une condition** pour bifurquer : une règle sur une Fiche (sans IA, toujours la même réponse) ou une
  question à MIA. **Une décision de MIA** quand il y a plus de deux scénarios. **Arrêter le
  workflow** au bout d'une voie qui n'a plus rien à faire : voir [Conditions, validations et
  actions](conditions-validations-actions.md) et [Laisser MIA choisir la voie](laisser-mia-choisir-la-voie.md).

## 7. Répéter ce qui doit l'être

Le même travail pour chaque ligne d'un fichier, chaque pièce jointe, chaque fichier d'un dossier,
chaque sujet d'un email ? Une **boucle pour chaque élément** : vous dites ce qu'elle parcourt, ce
qu'elle garde, ce qui se passe si un élément échoue, et si les actions préparées se relisent ensemble
à la fin — voir [Répéter pour chaque élément](repeter-pour-chaque-element.md). Pour refaire une étape
jusqu'à un bon résultat, faire patienter le workflow ou être prévenu en route : [Boucles, attentes et
notifications](boucles-et-attentes.md).

> *Exemple* — une boucle **Lignes d'un tableau ou d'un Excel** sur le **Fichier des clients** :
> colonnes Client, Email, Montant dû, Échéance (touchez **Lire les colonnes d'un fichier exemple**
> pour les remplir), règle *Montant dû supérieur à 0*, **Sans doublon sur** Email. Dans la boucle,
> l'étape **Rédiger la relance** remet un Texte, puis l'action Gmail **Préparer l'email** l'écrit à
> l'adresse du client, destinataire limité à @[Client en cours › Email]. **Relire toutes les actions
> ensemble, à la fin de la boucle** : vous cochez celles qui partent.

## 8. Agir dans vos outils

Une **action** écrit un email, crée un évènement, une tâche, un message Slack, un document Drive. Par
défaut, elle **prépare** : une carte attend votre accord. Ne la réglez sur **Agir sans validation**
que pour des gestes sans risque, et jamais sur un workflow lancé par un email reçu — un contenu venu de
l'extérieur peut tenter de détourner un assistant.

## 9. Livrer le résultat

Le **livrable** rassemble les pièces que vous obtenez à la fin, avec un mot pour conclure. Il peut
aussi **partir tout seul** : par email avec ses pièces jointes, dans Drive, dans Slack, dans une base
de connaissances — voir [Envoyer le livrable](envoyer-le-livrable.md).

## 10. Relire « À régler » et le coût

**Réglages** liste ce qui empêche de publier (une consigne vide, une pièce citée qui n'existe pas
encore à cet endroit, un service non relié…) et ce qui mérite un coup d'œil ; un clic mène au réglage.
Le **coût estimé** d'une exécution y figure aussi : une boucle le multiplie par son nombre d'éléments.

## 11. Publier, essayer, ajuster

1. **Publier** : seule la version publiée s'exécute ; vos retouches suivantes restent en brouillon.
2. **Lancer** un essai avec un petit fichier : la fenêtre de lancement dit ce qui va se passer et
   combien de tours fera chaque boucle.
3. Suivez l'exécution en direct ; touchez une brique pour voir ce qu'elle a lu et remis. Une pièce qui
   ne vous convient pas : renvoyez l'étape avec une remarque, ou corrigez la consigne, republiez, et
   **Reprendre avec la version *n*** — voir [Reprendre après une erreur](reprendre-apres-une-erreur.md).
4. Recommencez jusqu'à ce que le résultat soit celui que vous attendiez.

## 12. Le laisser tourner

Programmez-le ou branchez-le sur un évènement, cochez **Me prévenir sur mes appareils** (**Réglages**)
: vous êtes averti quand une validation vous attend, à la fin ou en cas d'arrêt. Chaque lancement
reste dans **Workflows → Historique des exécutions**, avec ce que chaque brique a fait : voir [Revoir
l'historique des exécutions](revoir-l-historique.md).

## Erreurs fréquentes

- **Une étape « fourre-tout »** qui lit, décide et rédige à la fois : découpez-la, le résultat sera
  plus sûr et plus facile à relire.
- **Une pièce citée trop tôt** : une brique ne lit que ce qui a été remis **avant** elle ; **À régler**
  le signale.
- **Une condition sur un texte** : une règle ne lit que les champs d'une Fiche — faites remettre une
  Fiche à l'étape qui précède.
- **Une boucle sans colonnes déclarées** : chaque tour reçoit la ligne entière, sans champ à citer ni à
  filtrer. Déclarez les colonnes que les briques utilisent.
- **Des actions vers l'extérieur sans contrôle** : gardez les cartes à approuver, ou la relecture
  groupée en fin de boucle.
