---
title: Gérer les utilisateurs
description: Créer et approuver des comptes, attribuer des rôles et des quotas, retrouver un compte, supprimer en connaissance de cause.
sidebar:
  order: 1
---

**Administration → Personnes → Comptes.** Les règles générales — rôles, groupes, héritage des droits — sont
dans [Les utilisateurs et les accès](index.md).

## La liste

Cinq tuiles en haut de page : **Comptes**, **Actifs**, **Inactifs**, **Admins** et **Quota ≥ 90 %**.
**Cliquer une tuile filtre la liste** ; re-cliquer revient à l'affichage complet.

Dessous, une recherche par nom ou e-mail, et trois filtres : rôle, statut, méthode de connexion. Les
colonnes — Utilisateur, Rôle, Groupes, Quota mensuel, Activité — se trient par leur en-tête. Sous le
rôle, la méthode de connexion : **Google**, **Microsoft**, **Mot de passe**, **Admin local**. Un compte
qui se connecte de plusieurs façons — *Google + Mot de passe* — apparaît sous chacune dans le filtre.

La colonne **Quota mensuel** montre la consommation, le plafond et le pourcentage — barre orange à
partir de 70 %, rouge à partir de 90 % —, l'origine du quota (*Individuel*, *Groupes (max)*,
*Illimité*) et sa valeur en euros.

Le bouton **Copier les emails** met dans le presse-papier les adresses des utilisateurs **actuellement
affichés**, au format `Prénom NOM <email>` — prêt à coller dans un champ destinataires.

## Approuver les nouveaux comptes

La carte **Règles d'accès**, sous les tuiles, vaut pour toute l'instance :

- **Activer automatiquement les nouveaux utilisateurs** — **cochée** sur une instance neuve. Décochée,
  chaque compte créé par Google ou Microsoft arrive **inactif** et doit être approuvé : la tuile
  **Inactifs** et l'alerte du tableau de bord les signalent. Pour approuver : **Modifier**, cocher
  **Compte actif (peut se connecter et consommer)**, **Fermer**.
- **Domaine e-mail de l'équipe** — réservé à l'éditeur de MIA : il affiche le détail technique des
  nouveautés. Laissez-le vide.

MIA n'envoie aucun e-mail : prévenez vous-même la personne que son compte est actif.

## Créer un compte

**+ Nouvel utilisateur**, en haut à droite, crée un compte qui se connecte **par e-mail et mot de
passe** : **Nom**, **Email**, **Mot de passe** (8 caractères au moins), **Rôle**, **Statut** et
**Groupes** — **Par défaut** est coché d'office. Le quota se règle après la création, en modifiant le
compte.

**Une personne qui se connecte par Google ou Microsoft n'a pas besoin d'être créée ici** : son compte
apparaît de lui-même à sa première connexion — actif ou en attente selon la règle d'accès ci-dessus. Il
vous reste à régler son rôle, ses groupes et son quota. Créer quand même son compte ici, avec la même
adresse, sert à les préparer avant sa venue : à sa première connexion, MIA y rattache son identité
Google, ou son identité Microsoft si l'annuaire de votre organisation ou votre domaine est déclaré —
voir [Configurer la connexion](configurer-la-connexion.md). Si la connexion par mot de passe est
désactivée, le mot de passe saisi ne sert à rien ; la fenêtre le rappelle.

## Modifier un compte

**Modifier** sur sa ligne. La fiche s'enregistre seule ; **Fermer** la referme.

| Réglage | Effet |
|---|---|
| **Rôle** | **Utilisateur**, **Créateur — crée ses assistants**, **Administrateur — accès complet**, et les rôles créés dans **Personnes → Rôles** — voir [Gérer les rôles](gerer-les-roles.md) |
| **Quota mensuel (k crédits)** | Voir ci-dessous |
| **Compte actif (peut se connecter et consommer)** | Décoché, la personne ne peut plus se connecter |
| **Groupes** | Les droits et le quota qu'elle hérite |
| **Réinitialiser le mot de passe** | Seulement pour un compte qui a déjà un mot de passe |

### Le quota — attention à l'unité

**Le champ se saisit en milliers de crédits par mois.** Saisir **10** donne **10 000 crédits par
mois**, pas 10.

- **Renseigné**, il **prime** sur ceux des groupes, même s'il est plus bas. **0 bloque toute
  consommation.**
- **Vide**, la personne reçoit le quota **le plus élevé** parmi ceux de ses groupes actifs. Elle n'est
  illimitée que si aucun de ses groupes ne définit de quota.

Le texte sous le champ dit d'où viendra le quota appliqué. Un changement vaut dès l'action suivante de
la personne. Détails et exemples : [Fixer les quotas](../credits/fixer-les-quotas.md).

## Supprimer un compte

**Supprimer ce compte**, en bas de la fiche. Avant de confirmer, MIA affiche l'**impact réel** :
conversations supprimées définitivement, assistants et bases de connaissances qui perdent leur
créateur, partages qui tombent, synchronisations Drive ou OneDrive portées par ce compte qui
casseront, connexions personnelles effacées.

**Lisez cette liste avant de confirmer : l'action est irréversible.**

- Un **créateur** laisse des assistants « créés par Système » : ses assistants personnels deviennent
  invisibles de tous. Partagez d'abord ceux à garder — voir
  [Gérer les assistants](../assistants/gerer-les-assistants.md).
- Les connexions Google et Microsoft sont effacées dans MIA, mais pas révoquées chez le fournisseur :
  la personne peut le faire depuis son compte Google ou Microsoft.
- Pour suspendre plutôt que supprimer, décochez **Compte actif**.

## Déléguer la gestion des comptes

Un rôle qui coche **Comptes** dans [Gérer les rôles](gerer-les-roles.md) ouvre cette page à des
personnes qui ne sont pas administrateurs. Leurs gestes sont bornés :

- la liste **Rôle** ne propose que les rôles dont les droits sont compris dans les leurs — jamais
  **Administrateur** ; une phrase sous la liste le rappelle ;
- un compte aux droits plus larges que les leurs — un administrateur, par exemple — s'ouvre par
  **Voir**, en lecture seule ;
- sans **Comptes — Créer** ou **Comptes — Supprimer**, les boutons correspondants n'apparaissent pas.

## Garder la main

Votre propre rôle ne se change pas depuis votre fiche, et votre propre compte ne se désactive ni ne
se supprime : un autre administrateur s'en charge. Le compte d'**administration locale** (l'accès de
secours) reste toujours administrateur. MIA ne vous empêche pas, en revanche, de désactiver le dernier
autre administrateur : **gardez toujours au moins deux administrateurs actifs**.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Nom, email et mot de passe (8 car. min.) sont requis. » | Compléter les trois champs. |
| « Un utilisateur avec cet email existe déjà. » | Retrouver le compte par la recherche, et le modifier. |
| Une personne voit « Votre compte est en attente de validation par un administrateur. » | L'activation automatique est décochée : activer son compte. |
| Une personne est bloquée alors que son groupe a un quota élevé | Elle a un quota individuel, qui prime. Le vider ou l'augmenter. |
| « Votre propre rôle se change par un autre administrateur. » | Demander à un autre administrateur de changer votre rôle. |
| « Ce compte a des droits plus larges que les vôtres : seul un administrateur peut le modifier. » | Votre rôle gère les comptes, pas ceux qui le dépassent : passer par un administrateur. |
| « Vous ne pouvez pas donner ce rôle : il ouvre plus de droits que le vôtre. » | Choisir un rôle de la liste proposée, ou passer par un administrateur. |
