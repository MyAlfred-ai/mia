---
title: Brancher un cerveau MyBrain
description: Faire interroger le wiki d'équipe MyBrain par MIA, en direct, et choisir les groupes qui y ont accès.
sidebar:
  order: 4
---

Un **cerveau MyBrain** se branche comme une base de connaissances dont le contenu reste dans MyBrain :
MIA l'interroge à chaque message où la base est engagée — cochée dans la conversation ou liée à
l'assistant —, sans rien copier, et la réponse cite ses fiches. Réservé aux administrateurs.

## Avant de commencer

- Dans **MyBrain**, un administrateur crée une **clé d'API** (Administration → Clés d'API) avec le
  droit **lire** — et **nourrir** si des groupes doivent pouvoir lui proposer des textes. Nommez-la
  d'après son usage (« MIA ») : c'est ce nom que MIA affichera. La clé n'est montrée qu'une fois, à sa
  création.
- L'adresse du site MyBrain, en https.

## Étapes

1. **Espaces → Bases de connaissances → + Nouvelle base** (la tuile **Bases** de la barre latérale).
2. Donnez un **nom** (« Cerveau d'équipe ») et, si besoin, une description.
3. Dans **Sources**, choisissez la tuile **Cerveau MyBrain**. Le modèle d'embedding et les
   documents disparaissent de la fiche : il n'y a rien à indexer.
4. Renseignez l'**adresse du MyBrain** et la **clé d'application**, puis **Tester la connexion** :
   MIA affiche le nom de l'application et ses droits.
5. Dans **Partage**, cochez les **groupes** qui peuvent interroger le cerveau. Pour ceux qui doivent
   l'avoir sous la main, cochez aussi **cochée par défaut** ; pour ceux qui peuvent lui proposer des
   textes, **peut nourrir**.
6. Cochez, si vous le souhaitez, les **assistants** qui le consultent à chaque message.
7. **Créer la base.** Elle n'est créée que si le test de connexion passe.

## Résultat

La carte de la base porte l'icône du cerveau et l'état **En direct**, avec le nom de l'application et
ses droits. Les membres des groupes cochés trouvent le cerveau dans le sélecteur de bases du chat —
déjà coché dans leurs nouvelles conversations si vous l'avez coché par défaut.

## Ce qu'il faut savoir

- **Qui interroge le cerveau** : les membres des groupes partagés, et les administrateurs. C'est
  vérifié **à chaque message**, **même à travers un assistant** : un assistant ouvert à d'autres
  groupes ne leur ouvre pas le cerveau. Retirer un groupe coupe l'accès dès le message suivant.
- **Cochée par défaut** vaut pour les **nouvelles** conversations. Une conversation déjà ouverte garde
  le choix de la personne.
- **La clé lit tout le wiki partagé de MyBrain** : ni sa zone RH, réservée à sa direction, ni les
  documents bruts, ni les données personnelles (mails, Drive). Choisissez les groupes en conséquence.
- **Changer de clé** (rotation, clé supprimée) : **Modifier** sur la base, collez la nouvelle clé dans
  **Clé d'application**, puis **Tester et enregistrer**. La connexion ne s'enregistre jamais sans un
  test réussi ; laissez le champ vide pour garder la clé actuelle.
- **Débrancher ce cerveau**, en bas de sa fiche, retire la base de MIA. La clé reste valide dans
  MyBrain : supprimez-la là-bas si elle ne sert plus.
- **Le coût.** Interroger le cerveau ne coûte pas de crédit à part : ses extraits allongent la
  question envoyée au modèle, décomptée comme d'habitude. Avec un modèle compatible avec les outils,
  MIA n'envoie d'abord qu'un extrait — les fiches les plus pertinentes, environ 4 000 tokens au lieu
  d'environ 10 000 — et ne va chercher la suite que si la question l'exige : un cerveau coché par
  défaut pour tout un groupe coûte ainsi deux à trois fois moins par message.
- **Peut nourrir** : les membres de ces groupes peuvent demander à MIA de proposer un texte au
  cerveau — compte rendu, décision. La personne approuve la carte dans MIA, puis le texte part dans la
  **revue** de MyBrain, à son nom : rien n'entre dans le wiki sans validation. Il faut en plus que la
  clé ait le droit **nourrir** : sans lui, la case n'a aucun effet. Interroger le cerveau ne donne
  pas ce droit.
- Les outils qui creusent le cerveau (nouvelle recherche, lecture d'une fiche entière) ne font que
  lire.

## En cas de problème

| État sur la carte | Cause | Quoi faire |
|---|---|---|
| **MyBrain injoignable** (ambre) | Le serveur ne répond pas, ou limite les appels | Rien, si c'est passager : les réponses partent sans le cerveau et le disent. Sinon, vérifier MyBrain. |
| **Connexion refusée** (rouge) | Clé supprimée, droit « lire » retiré, adresse fausse | Nouvelle clé dans MyBrain, puis **Tester et enregistrer**. La base reste grisée dans le chat jusque-là. |
| « Cette adresse redirige ailleurs » | L'adresse saisie n'est pas la finale | Indiquer l'adresse finale du MyBrain, en https. |
| « Clé invalide : une clé MyBrain commence par mbk_ » | La clé est incomplète ou n'en est pas une | Recopier la clé entière, ou en créer une nouvelle. |

Le **Diagnostic** (Maintenance → Diagnostic) vérifie chaque cerveau branché.
