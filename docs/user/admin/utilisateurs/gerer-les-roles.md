---
title: Gérer les rôles
description: Décider de ce que chaque rôle peut voir, utiliser, créer, modifier, supprimer et gérer — et créer des rôles sur mesure.
sidebar:
  order: 3
---

**Administration → Personnes → Rôles**, réservé aux administrateurs. Chaque compte porte un rôle ;
le rôle décide de ce que la personne **peut faire** dans MIA, ses [groupes](organiser-les-groupes.md)
décident des assistants, outils et modèles **qui lui sont ouverts**.

## Avant de commencer

- Trois rôles sont livrés : **Utilisateur**, **Créateur** et **Administrateur**. Ils ne se suppriment
  pas. Leurs droits par défaut sont ceux que MIA a toujours donnés : rien ne change tant que vous ne
  les modifiez pas.
- L'**Administrateur** a tous les droits et ne se modifie pas : il reste toujours quelqu'un capable de
  tout régler.
- Deux domaines restent aux administrateurs, quel que soit le rôle : **les rôles eux-mêmes** et **les
  données** (sauvegarde, restauration, élagage).

## Lire l'écran

À gauche, la liste des rôles, avec le nombre de comptes qui portent chacun. À droite, les droits du
rôle choisi — le premier de la liste à l'ouverture —, en deux tableaux :

- **Fonctionnalités** : assistants, bases de connaissances, tâches programmées, workflows, outils,
  connecteurs — ce que la personne fait dans MIA ;
- **Administration** : tableau de bord, comptes, groupes, gestion des LLM, authentification, coûts,
  entreprise et apparence, diagnostic — les écrans qu'elle voit.

Une ligne par fonctionnalité, une case par droit. Aucune légende n'encombre l'écran : **survolez**,
**touchez** ou atteignez **au clavier** un en-tête de colonne, le nom d'une ligne ou une case, et une
bulle dit ce qu'il ouvre. Celle d'une case précise ce qu'elle coche avec elle ; un tiret signale une
action sans objet, et sa bulle dit pourquoi. Au téléphone, chaque fonctionnalité devient une carte,
et les colonnes, une rangée de pastilles à toucher.

Les colonnes sont les mêmes partout :

| Colonne | Ce qu'elle permet |
|---|---|
| **Voir** | Voir la fonctionnalité apparaître : son entrée dans le menu et sa page, en lecture seule. Sans cette case, elle reste cachée — sauf dans la conversation, où « Utiliser » décide. |
| **Utiliser** | S'en servir, sans rien régler : dans la conversation (assistants, bases, outils, connecteurs), en faisant tourner ses tâches et ses workflows, ou, dans l'administration, en lançant tests et réparations. |
| **Créer** | En ajouter. Suppose « Voir ». |
| **Modifier** | En changer les réglages. Suppose « Voir ». |
| **Supprimer** | En retirer, définitivement. Suppose « Voir ». |
| **Gérer** | Ceux de toute l'organisation, pas seulement les siens : les voir tous, les modifier, les partager et en changer la portée. Suppose « Modifier ». |

« Voir » et « Utiliser » sont indépendants pour ce qui sert dans la conversation : le rôle Utilisateur discute avec les assistants sans voir la page Assistants. Ce qu'on lance depuis sa page — tâches programmées, workflows, tests des modèles, réparations du diagnostic — demande aussi « Voir ».

Ligne par ligne :

| Ligne | **Voir** fait apparaître | **Utiliser** permet |
|---|---|---|
| Assistants | La tuile **Assistants** de la barre latérale et sa page : les siens (tous, avec **Gérer**) | Discuter avec les assistants de ses groupes, avec les siens et avec ceux proposés à tous |
| Bases de connaissances | La tuile **Bases** de la barre latérale et sa page : les siennes et celles qu'on lui partage (toutes, avec **Gérer**) | Cocher des bases à interroger dans la conversation. Une base liée à un assistant répond avec lui sans cette case — sauf un cerveau MyBrain, qui l'exige |
| Tâches programmées | La tuile **Tâches** de la barre latérale et sa page : ses propres tâches et leurs exécutions | Les faire tourner : à leur heure, **Exécuter maintenant**, essai |
| Workflows | La tuile **Workflows** de la barre latérale et sa page : ses propres workflows et le suivi de leurs exécutions | Les lancer et trancher leurs validations |
| Outils | La page **Capacités → Outils** de l'administration | Générer avec les outils de ses groupes (menu **+**) et avec ceux de ses assistants |
| Connecteurs | La page **Capacités → Connecteurs** de l'administration | Relier ses comptes et laisser MIA s'en servir dans la conversation |
| Gestion des LLM | Les clés API (masquées), les modèles, le comportement par défaut, la page Tests | Lancer les tests, sans rien changer aux réglages |
| Diagnostic | Les contrôles, les journaux, le moteur vectoriel, les statistiques de l'agent | Réparer et nettoyer le moteur vectoriel |
| Les autres écrans d'administration | L'écran, en lecture seule | — |

### Gérer : ceux des autres, le partage, la portée

**Gérer** n'existe que pour les **assistants** et les **bases de connaissances**, les deux choses
qu'on crée puis qu'on partage. Sans cette case, la personne tient ce qu'elle a créé (et, pour les
bases, celles qu'on lui partage) ; avec, elle tient ceux de toute l'organisation :

- **Assistants** : voir et modifier tous les assistants, les partager aux groupes ou les rendre
  personnels, sans le plafond des créateurs ; dans la conversation, discuter avec tous, sans passer
  par les groupes. Avec **Supprimer**, supprimer ceux des autres.
- **Bases de connaissances** : voir et modifier toutes les bases, en régler le partage, brancher un
  cerveau MyBrain, régler les sources synchronisées, sans le plafond des créateurs ; dans la
  conversation, les interroger toutes. Avec **Supprimer**, supprimer celles des autres.

Ailleurs, la case est sans objet : tâches programmées, workflows et connecteurs sont propres à chaque
personne, et les outils appartiennent déjà à l'organisation (**Modifier** les règle, les groupes
décident qui s'en sert).

**En résumé**, en tête, récapitule ce que le rôle ouvre, et se met à jour à chaque case.

## Étapes

1. Choisissez un rôle dans la liste, ou cliquez **Nouveau rôle** : un nom, une description, le rôle
   dont il part (**Partir des droits de**) et une couleur.
2. Cochez les cases. Cocher une case coche ce qu'elle suppose — **Modifier** suppose **Voir**,
   **Gérer** suppose **Modifier** —, en décocher une décoche ce qui en dépend : la case touchée par
   ricochet pulse, pour que vous la voyiez.
3. Cliquez **Enregistrer**, en bas. Chaque case changée porte un anneau, et la barre compte les
   changements ; **Annuler** revient à l'état enregistré.

## Résultat

Le message dit combien de droits ont été ajoutés ou retirés, et pour combien de comptes. Les droits
valent dès la page suivante des personnes concernées ; une tâche programmée ou un workflow les relit à
sa prochaine exécution.

Si des comptes portent le rôle et que vous **retirez** des droits, MIA les liste avant d'enregistrer.

## Les rôles livrés, par défaut

| Ce que le rôle permet | Utilisateur | Créateur | Administrateur |
|---|:-:|:-:|:-:|
| Discuter avec les assistants de ses groupes, choisir les bases qu'on lui partage | ✓ | ✓ | ✓ |
| Utiliser les outils de ses groupes, relier ses comptes (connecteurs) | ✓ | ✓ | ✓ |
| Programmer ses tâches | ✓ | ✓ | ✓ |
| Créer et modifier ses assistants, ses bases de connaissances, ses workflows | — | ✓ | ✓ |
| Gérer les assistants et les bases de toute l'organisation | — | — | ✓ |
| Les écrans d'administration, les rôles et les données | — | — | ✓ |

Un rôle livré modifié porte la mention **modifié** ; **Rétablir les droits par défaut**, en bas de
l'écran, le ramène à ce tableau.

## Les rôles personnalisés

- **Nouveau rôle** part des droits d'un rôle existant, ou d'**Aucun droit**. Partir d'*Administrateur*
  donne tout, sauf les rôles et les données.
- **Dupliquer** crée une copie du rôle affiché, avec ses droits enregistrés.
- Le nom, la description et la couleur se modifient dans l'éditeur ; la couleur colore le badge du
  rôle dans la liste des comptes.
- **Supprimer le rôle** demande quel rôle prendront ses comptes. *Administrateur* n'est jamais
  proposé : une suppression ne promeut personne.
- Trente rôles personnalisés au plus.

## Exemples

**Un responsable des comptes.** Partir d'*Utilisateur*, cocher **Comptes** (Voir, Créer, Modifier) et
**Groupes** (Voir, Modifier). Il crée les comptes des nouveaux arrivants et les range dans les groupes,
sans toucher aux modèles ni aux réglages.

**Un référent des assistants.** Partir de *Créateur*, cocher **Gérer** sur les assistants : il
entretient tous les assistants de l'organisation et les distribue aux groupes.

**Un responsable de la documentation.** Partir de *Créateur*, cocher **Gérer** sur les bases de
connaissances : il tient toutes les bases, en règle le partage et branche les sources synchronisées.

**Un support en lecture.** **Voir** sur **Gestion des LLM** et **Diagnostic**, rien d'autre : il
consulte les clés, les modèles, les contrôles et les journaux, sans rien pouvoir changer. Les pages
qu'il voit sans droit d'y écrire s'affichent en **lecture seule**.

**Des stagiaires sans connecteurs.** Un rôle *Stagiaire* qui part d'*Utilisateur*, **Connecteurs —
Utiliser** décoché : ils discutent, sans relier leurs comptes Google ou Microsoft.

## Ce que le rôle ne décide pas

- **À qui sont les tâches programmées et les workflows** : ils sont propres à chaque personne. Chacun
  ne voit et ne gère que les siens, administrateurs compris ; le rôle dit seulement si la personne peut
  en avoir, les lancer, les créer, les modifier ou les supprimer.

- **Quels** assistants, outils et modèles une personne voit : ses groupes.
- Ce qu'une fonctionnalité éteinte par l'administrateur permettrait : un rôle qui coche **Tâches
  programmées** ne les rouvre pas si la fonction est coupée sur la page Outils.

## Cas particuliers

- **Un gestionnaire des comptes qui n'est pas administrateur** ne donne que des rôles dont les droits
  sont compris dans les siens, jamais *Administrateur*, et ne modifie pas un compte plus large que le
  sien : il le consulte seulement. Personne ne change son propre rôle.
- **Retirer « Tâches programmées — Utiliser »** : les tâches de ces comptes sautent leurs exécutions
  sans se mettre en pause ; elles repartent seules quand le droit revient.
- **Retirer « Connecteurs — Utiliser »** : les connecteurs disparaissent du chat et de Réglages →
  Comptes connectés, et une action déjà préparée n'est plus approuvable.
- **Retirer « Assistants — Utiliser »** : plus aucun assistant, pas même ceux du groupe ★ Par défaut ;
  la personne discute avec MIA seule.
- **Un rôle personnalisé créé avant la colonne Gérer** : s'il avait la portée « tous ceux de
  l'organisation » avec **Modifier**, il a désormais **Gérer**. S'il l'avait sans **Modifier** (lecture
  de tout), il ne garde que ses propres assistants ou bases : **Gérer** supposant **Modifier**, le lui
  donner d'office aurait élargi ses droits. Cochez **Gérer** si c'est voulu.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Un compte voit « Votre rôle ne vous donne pas accès à cette action » | Cocher le droit dans son rôle, ou lui donner un autre rôle. |
| Un rôle n'apparaît pas dans la liste de choix d'un gestionnaire des comptes | Il ouvre des droits que le rôle du gestionnaire n'a pas : c'est voulu. Un administrateur peut le donner. |
| Des comptes portent un « rôle inconnu » | Leur rôle a disparu (sauvegarde partielle, base modifiée à la main) : ils ont les droits d'un utilisateur. Donnez-leur un rôle dans la liste des comptes. |
| Une page s'ouvre en lecture seule | Le rôle peut la voir, pas la modifier : cocher **Modifier** (ou **Créer**) sur la ligne concernée. |
| Une personne ne voit que ses propres assistants ou bases | Cocher **Gérer** sur la ligne, pour qu'elle tienne ceux de toute l'organisation. |
