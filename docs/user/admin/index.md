---
title: Administration
description: Ce qu'un administrateur de l'instance MIA règle depuis l'interface, sujet par sujet, et dans quel ordre.
sidebar:
  label: Vue d'ensemble
  order: 0
---

Ces pages sont réservées aux personnes qui administrent l'instance MIA de leur organisation. Elles
suivent les mêmes sujets que la documentation utilisateur : chaque sujet a sa page côté utilisateur
et sa page côté administration, reliées l'une à l'autre.

## Le menu Administration

Cliquez **Admin**, en bas de la barre latérale, à côté de **Réglages** : vous arrivez directement sur
le **Tableau de bord** — ou sur la première page que votre rôle vous ouvre. Le menu de gauche porte le
**Tableau de bord** puis six rubriques, chacune repliable ; chaque rubrique et chaque page portent leur
pastille de points à régler. Le bouton **Admin** n'apparaît que pour qui administre au moins une partie
de l'instance — vous ne voyez que les rubriques que votre rôle vous ouvre. Sur téléphone, ce menu
s'ouvre par le bouton **☰**, en haut à gauche.

| Rubrique du menu | Sujet |
|---|---|
| **Tableau de bord** | [La gestion des crédits](credits/index.md) — et l'état général de l'instance |
| **Personnes** → Comptes, Groupes, Rôles | [Les utilisateurs et les accès](utilisateurs/index.md) |
| **Modèles IA** → Clés API, Modèles (avec le comportement par défaut), Tests | [Les modèles de LLM](modeles/index.md) |
| **Capacités** → Outils | [Les outils](outils/index.md) — dictée et conversation vocales comprises |
| **Capacités** → Connecteurs | [Les connecteurs](connecteurs/index.md) |
| **Capacités** → Sources synchronisées | [Les bases de connaissances](bases-de-connaissances/index.md) |
| **Coûts** → Budget, Consommation, Tarifs & crédits | [La gestion des crédits](credits/index.md) |
| **Organisation** → Entreprise, Marque & apparence | [L'instance](instance/index.md) |
| **Organisation** → Authentification | [Les utilisateurs et les accès](utilisateurs/index.md) |
| **Maintenance** → Diagnostic (contrôles, journaux, moteur vectoriel, agent), Données (sauvegarde, élagage) | [L'instance](instance/index.md) |

Les **assistants** et les **bases de connaissances** ne sont pas dans ce menu : ils s'ouvrent par
leurs tuiles **Assistants** et **Bases**, dans la rangée **Espaces** de la barre latérale, où un
administrateur voit ceux de toute l'organisation — voir [Les assistants](assistants/index.md) et
[Les bases de connaissances](bases-de-connaissances/index.md).

## Les pastilles du menu

Le menu signale de lui-même ce qui reste à configurer, ou ce qui ne marche plus : une **pastille**
apparaît sur l'entrée où l'on corrige, et sur la rubrique qui la contient — dépliez-la pour trouver
l'entrée marquée. Passez la souris sur l'entrée : une **bulle** au-dessus de la pastille dit le
problème et la marche à suivre. Sur téléphone, l'explication s'affiche sous l'entrée, et le bouton
**☰** porte lui aussi une pastille.

Une pastille **ambre** signale quelque chose d'invisible pour les utilisateurs, de remplacé en
silence ou qui n'est plus tenu à jour ; une pastille **rouge**, quelque chose qui échoue chez eux.
Aucune ne s'ignore : chacune s'éteint dès que la cause est corrigée.

| Entrée | Exemples de ce qui est signalé |
|---|---|
| **Personnes** → Comptes, Groupes | Comptes en attente d'activation ; groupe dont aucun modèle autorisé n'est activé |
| **Modèles IA** → Clés API, Modèles | Aucune clé ; clé sans modèle activé ; modèle activé sans clé ; modèle par défaut ou de secours qui ne marche plus |
| **Capacités** → Outils | Outil inutilisable, hors service ou en échec au dernier test ; outil qu'aucun groupe n'autorise ; voix sans clé OpenAI |
| **Capacités** → Connecteurs | Connecteur activé dont les identifiants manquent |
| **Capacités** → Sources synchronisées | Source sans identifiants |
| **Organisation** → Authentification | Connexion Google ou Microsoft activée sans identifiants |
| **Maintenance** → Diagnostic | Worker arrêté ou à reconstruire |
| **Coûts** → Budget | Seuil d'alerte ou plafond du budget atteint |
| Tuile **Assistants** de la barre latérale | Assistant partagé qu'aucun groupe n'autorise ; modèle d'un assistant retiré ou sans clé |
| Tuile **Bases** de la barre latérale | Synchronisation en échec ; cerveau MyBrain refusé ; aucune clé de vectorisation |

Le **Tableau de bord** liste les mêmes points dans sa carte **Alertes**, chacun avec un lien
**Régler →**. Le bouton **Admin**, en bas de la barre latérale, porte une pastille avec leur nombre,
jusque dans le chat.

## Mettre une instance en service

L'installation sur le serveur, puis les réglages sans lesquels l'assistant ne peut rien répondre — une
clé d'IA, le modèle par défaut, la connexion, les groupes —, se font dans cet ordre :
[L'installation](installation/index.md). Sur une instance déjà installée, commencez à
[Faire les réglages de base](installation/faire-les-reglages-de-base.md).

Le **Tableau de bord** affiche un bandeau « Bienvenue ! Une étape indispensable reste à faire. » tant
qu'aucune clé n'est enregistrée.

## Le tableau de bord

**Administration → Tableau de bord** donne la vue du mois : crédits consommés, coût réel de l'IA,
valorisation, projection de fin de mois, requêtes, utilisateurs actifs, consommation des douze
derniers mois et top consommateurs. Les **alertes** signalent ce qui demande une action : aucune clé
d'IA active, utilisateurs à 80 % de leur quota ou bloqués, budget, et chaque point à régler du menu
— comptes en attente d'activation compris.

La carte **Stockage** détaille la place occupée, composant par composant ; la mesure est actualisée
toutes les dix minutes.

## Vos réglages s'enregistrent seuls — presque tous

La plupart des écrans enregistrent chaque modification peu après que vous l'avez faite ; une pastille
le confirme. Une configuration invalide n'est jamais enregistrée : le message d'erreur s'affiche et la
pastille passe en ambre.

Exceptions, qui gardent un bouton : **Authentification** (un **Enregistrer** par section), la
création d'un utilisateur, d'un groupe, d'un assistant ou d'une base, les mots de passe, et les
actions irréversibles, confirmées en tapant un mot — **ELAGUER**, **REMPLACER**.

## Qui fait quoi

| Relève de votre organisation | Relève de MyAlfred |
|---|---|
| Utilisateurs, groupes, quotas, connexion | Installation — voir [L'installation](installation/index.md) —, mises à jour, serveur |
| Clés d'IA, modèles, tarifs — selon votre contrat | Sauvegardes complètes et restauration |
| Assistants, bases, outils, connecteurs | Réparation du moteur des bases de connaissances |
| Apparence, élagage, lecture des logs et du diagnostic | Corrections signalées par le diagnostic hors de l'interface |

Pour tout ce qui relève de MyAlfred, contactez-nous en joignant, si possible, le rapport du
**Diagnostic** — voir [Consulter les logs et le diagnostic](instance/consulter-les-logs-et-le-diagnostic.md).
