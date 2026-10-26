# Guide administrateur — MIA

> Configuration complète de la plateforme **MIA** : installation, utilisateurs, groupes, assistants, modèles, outils de génération, crédits, suivi d'usage, bases de connaissances, organisation et maintenance de l'instance.
>
> Tout se passe depuis l'**Administration** : le bouton **Admin**, en bas de la barre latérale, à côté de **Réglages**, y mène directement — sur le **Tableau de bord**, ou sur la première page que vos droits ouvrent. Le menu de gauche y porte, après **Retour au chat**, le **Tableau de bord** puis six rubriques repliables : **Personnes** (Comptes, Groupes, Rôles), **Modèles IA** (Clés API, Modèles, Tests), **Capacités** (Outils, Connecteurs, Sources synchronisées), **Coûts** (Budget, Consommation, Tarifs & crédits), **Organisation** (Entreprise, Marque & apparence, Authentification) et **Maintenance** (Diagnostic, Données). Chaque rubrique et chaque page portent leur pastille de points à régler. Le bouton **Admin** n'apparaît qu'à qui administre au moins une partie de l'instance, et le menu ne montre que les rubriques que son rôle lui ouvre ; le bouton porte une pastille avec le nombre de points à régler. La barre latérale garde la même largeur sur le chat, les réglages et l'administration. Les **assistants** et les **bases de connaissances** ne sont pas dans ce menu : ils s'ouvrent par leurs tuiles **Assistants** et **Bases**, dans la rangée **Espaces** de la barre latérale, où un administrateur voit ceux de toute l'organisation. Vos réglages personnels s'ouvrent par le bouton **Réglages**, qui mène directement à **Mon profil** : le menu de gauche de ces pages réunit, sous **Mon compte**, **Mon profil**, **Préférences IA**, **Affichage**, **Comptes connectés** et **Usage & crédits** — par exemple **Réglages → Affichage** —, puis **À propos de MIA** et **Se déconnecter**. Sur **mobile**, le bouton **☰** en haut à **gauche** ouvre en tiroir le menu des pages d'administration (ou des réglages), et l'**avatar** en haut à **droite** mène directement à **Mon profil** ; sur le chat, les boutons **Réglages** et **Admin** restent en bas du tiroir. Les indications renvoient à ce qui est **affiché à l'écran** (pages, menus, boutons, champs). Voir aussi le **guide utilisateur** et le **guide créateur**.

---

## Table des matières

1. [Première installation](#1-première-installation)
2. [Tableau de bord](#2-tableau-de-bord)
3. [Personnes — Comptes](#3-personnes--comptes)
4. [Groupes d'utilisateurs](#4-groupes-dutilisateurs)
5. [Assistants](#5-assistants)
6. [Modèles IA (clés, modèles, défaut, tests)](#6-modèles-ia-clés-modèles-défaut-tests)
7. [Outils de génération](#7-outils-de-génération)
8. [Coûts : budget, tarifs & crédits](#8-coûts--budget-tarifs--crédits)
9. [Consommation & journaux](#9-consommation--journaux)
10. [Bases de connaissances](#10-bases-de-connaissances)
11. [Connecteurs](#11-connecteurs)
12. [Organisation — Entreprise, Marque & apparence](#12-organisation--entreprise-marque--apparence)
13. [Organisation — Authentification](#13-organisation--authentification)
14. [Sécurité](#14-sécurité)
15. [Application mobile (iOS / Android)](#15-application-mobile-ios--android)
16. [Maintenance — Diagnostic](#16-maintenance--diagnostic)
17. [Maintenance — Données : sauvegarde](#17-maintenance--données--sauvegarde)
18. [Maintenance — Données : élagage](#18-maintenance--données--élagage)

→ [Rôles et droits](#rôles-et-droits) *(Personnes → Rôles : ce que chaque rôle peut voir, utiliser, créer, modifier, supprimer)*
→ [Enregistrement automatique](#enregistrement-automatique) *(principe commun à tous les écrans de configuration)*
→ [Points à régler](#points-à-régler) *(les pastilles du menu d'administration)*

---

## Enregistrement automatique

**Il n'y a plus de bouton « Enregistrer » dans l'administration ni dans vos réglages personnels**
— à deux exceptions près, **Organisation → Authentification** et **Coûts → Budget** (voir ci-dessous).
Chaque modification part toute seule, peu après que vous l'avez faite : le temps d'une courte
pause quand vous tapez du texte, immédiatement quand vous cochez une case, choisissez dans une
liste ou déplacez un curseur.

Une **pastille** apparaît en bas de l'écran et dit ce qui se passe :

| Pastille | Ce que ça veut dire |
|---|---|
| **Enregistrement…** | La modification est partie vers le serveur. |
| **Enregistré** ✓ | Le serveur a confirmé. La pastille disparaît au bout de deux secondes. |
| Message **ambre** | Un champ obligatoire est vide, ou l'enregistrement a réussi mais une partie du réglage demande votre attention. **Rien n'est perdu** : corrigez, ça repart. |
| Message **rouge** + **Réessayer** | Le serveur a refusé, ou le réseau a lâché. La modification est **conservée** ; cliquez **Réessayer**. |

Points à retenir :

- **Rien n'est jamais enregistré à moitié.** L'écran envoie le formulaire **entier** à chaque fois :
  une case décochée est bien enregistrée comme décochée.
- **Un champ obligatoire laissé vide bloque l'enregistrement** de tout l'écran, et la pastille
  nomme le champ en cause. Tant qu'il est vide, plus rien n'est enregistré — remplissez-le.
- **Créer reste un acte volontaire.** Les boutons **Créer** / **Ajouter** subsistent : on ne crée
  pas un utilisateur, un groupe, un assistant, un outil ou une base de connaissances au fil de la
  frappe. C'est seulement la **modification** d'un objet existant qui s'enregistre toute seule.
- **Les mots de passe gardent leur bouton** (« Changer le mot de passe », « Définir le mot de
  passe ») : un mot de passe à moitié saisi ne doit jamais partir.
- **La page Authentification garde un bouton Enregistrer par section** (Google, Microsoft,
  e-mail et mot de passe, domaines autorisés, message de la page de connexion) : rien n'y part
  avant le clic, et chaque bouton n'enregistre que sa section — un identifiant de connexion à
  moitié saisi ne doit jamais partir, et couper un moyen de se connecter est un geste qu'on
  assume d'un clic (section 13).
- **La page Budget garde son bouton Enregistrer** : un montant à moitié saisi (« 5 » en route
  vers « 500 ») suspendrait l'IA de toute l'organisation à la frappe. Un budget déjà dépassé
  demande en plus une confirmation (section 8).
- **Les actions restent des actions** : Supprimer, Réindexer, Restaurer, Tester, Synchroniser
  maintenant, Filtrer — tous ces boutons sont inchangés.
- **Dans une fenêtre de modification** (utilisateur, groupe, outil), le
  bouton **Fermer** remplace **Enregistrer** : tout est déjà enregistré au moment où vous fermez.
  La liste derrière se rafraîchit alors pour refléter vos changements.
- **Sur la fiche d'un assistant ou d'une base de connaissances** — une page, plus une fenêtre —,
  **Retour à la liste** remplace **Enregistrer** : rien à valider avant de partir.
- **Si vous quittez la page** avec une modification qui n'est pas encore partie, elle est envoyée
  au passage. Le navigateur ne vous avertit que dans les cas où l'envoi est réellement impossible
  (image en cours de téléversement, champ obligatoire vide).
- **Page restée ouverte longtemps** : si la session a expiré entre-temps, MIA reprend un jeton de
  sécurité valide et rejoue l'enregistrement automatiquement, une fois. Si cela échoue quand même,
  la pastille vous demande de recharger la page.

---

## Points à régler

Le menu de l'administration signale de lui-même ce qui reste à configurer — ou ce qui ne marche
plus. Une **pastille** apparaît sur l'entrée du menu où l'on corrige, et sur la rubrique qui la
contient (**Personnes**, **Modèles IA**, **Capacités**, **Organisation**, **Maintenance** ; **Coûts**
porte celle du budget) : dépliez la rubrique pour trouver l'entrée marquée. Les constats sur les
assistants et les bases de connaissances se lisent sur leurs tuiles **Assistants** et **Bases**, dans
la barre latérale.

- **Au survol de l'entrée** (ou au clavier), une **bulle au-dessus de la pastille** explique le
  constat et la marche à suivre. Sur une rubrique repliée, la bulle résume chaque point en une ligne ;
  sur l'entrée, elle donne le détail.
- **Au doigt** (téléphone, tablette), l'explication s'affiche en petit sous l'entrée. Sur
  téléphone, le bouton **☰** porte lui aussi une pastille quand il y a quelque chose à régler.
- **Couleur** : **ambre** — quelque chose reste invisible aux utilisateurs, est remplacé en
  silence ou n'est plus tenu à jour ; **rouge** — quelque chose échoue chez les utilisateurs.
- **Aucune pastille ne s'ignore** : chacune s'éteint d'elle-même dès que la cause est corrigée,
  au chargement de page suivant.
- Le **Tableau de bord** liste les mêmes points dans sa carte **Alertes**, avec un lien
  **Régler →** ; le bouton **Admin**, en bas de la barre latérale, porte une pastille avec leur
  nombre — jusque dans le chat.

| Entrée du menu | Ce qui est signalé | Pour l'éteindre |
|---|---|---|
| Personnes → Comptes | Comptes **en attente d'activation** : inactifs et jamais utilisés — l'arrivée d'un collègue par Google ou Microsoft quand l'activation automatique est coupée | Activer le compte, ou le supprimer |
| Personnes → Groupes | Groupe dont **aucun modèle autorisé n'est activé** : ses membres n'ont plus de modèle (rouge) | Cocher un modèle activé dans ses droits d'accès |
| Modèles IA → Clés API | **Aucune clé API** (pastille clignotante, section 6) (rouge) | Renseigner une clé |
| | **Crédit épuisé chez un éditeur**, ou **clé refusée** : les demandes des utilisateurs sont refusées, depuis combien de temps et combien (rouge) — même quand le LLM de secours répond à leur place | Recharger le compte (ou remplacer la clé) chez l'éditeur, puis **Tester** ; le point s'éteint aussi à la prochaine réponse aboutie de l'éditeur, ou après trois jours sans nouveau refus |
| Modèles IA → Modèles | **Clé enregistrée sans aucun modèle activé** | Activer les modèles à proposer |
| | **Modèle activé sans clé API** : toujours proposé dans le chat, il échoue (rouge) | Renseigner la clé, ou retirer le modèle |
| | **Modèle par défaut sans clé API** (rouge), ou **à confirmer** : le réglage ne désigne plus aucun modèle | Carte **Comportement par défaut** |
| | **Modèle de secours** retiré ou sans clé : aucune bascule en cas de panne | En choisir un autre |
| | **Modèle bientôt arrêté** par son éditeur (date et remplaçant désigné), ou **arrêté** et encore activé — déjà retiré du chat | Activer le remplaçant, le choisir là où l'ancien servait, puis retirer l'ancien |
| Tuile **Assistants** (barre latérale) | **Assistant partagé autorisé dans aucun groupe** : seuls les administrateurs le voient | Le cocher dans **Groupes › Assistants autorisés**, ou le repasser en personnel |
| | Assistant dont le **modèle n'est plus activé** (il répond avec le modèle par défaut), ou n'a plus de clé (rouge) | Lui choisir un modèle activé, ou « Défaut application » |
| | Assistant qui impose un **modèle bientôt arrêté** par son éditeur | Lui choisir le remplaçant, ou « Défaut application » |
| Tuile **Bases** (barre latérale) | **Cerveau MyBrain refusé** : base grisée dans le chat (rouge) | « Modifier », puis **Tester et enregistrer** avec une clé valide |
| | **Synchronisation en échec** | Lire le motif dans la base, puis **Synchroniser maintenant** |
| | **Aucune clé de vectorisation** : recherche par mots seulement | Enregistrer une clé OpenAI, Mistral ou Gemini, puis **Réindexer** |
| Capacités → Sources synchronisées | Base synchronisée dont la **source n'a plus d'identifiants** : elle n'est plus mise à jour | Renseigner les identifiants de la source |
| Capacités → Outils | Outil actif **inutilisable** — clé, URL n8n ou outil image source manquant (rouge) ; **modèle arrêté** par son fournisseur ou **arrêt annoncé** ; **dernier test en échec** ; **autorisé dans aucun groupe** ; **dictée ou conversation vocale sans clé OpenAI** | Selon le cas : la clé, un autre modèle (« Modifier »), un nouveau **Tester**, un groupe, ou désactiver |
| Capacités → Connecteurs | Connecteur **activé mais sans identifiants** : il n'apparaît pas chez les utilisateurs | Renseigner les identifiants, ou masquer le connecteur |
| Organisation → Authentification | Connexion **Google ou Microsoft activée sans identifiants** : son bouton manque sur la page de connexion | Renseigner les identifiants, ou désactiver la connexion |
| Maintenance → Diagnostic | **Worker** arrêté, ou resté sur une ancienne version : générations en mode dégradé | Le démarrer, ou le reconstruire |
| Coûts → Budget | Seuil d'alerte ou plafond du **budget IA** (pastille propre, section 8) | Rallonge, budget relevé ou quotas |

Le menu ne lit que l'état de l'instance, sans aucun appel extérieur : ce qui demande d'interroger
un service — base vectorielle joignable, cerveau MyBrain en ligne, accès Internet — reste au
**Diagnostic** (section 16).

---

## 1. Première installation

L'installation du serveur se fait en une commande, `./install.sh`, qui pose les questions
nécessaires et vérifie tout au fur et à mesure. La procédure technique complète est décrite
dans le fichier `docs/INSTALLATION.md` du projet.

**Le compte administrateur est créé par l'installeur**, avec l'adresse e-mail et le mot de passe
donnés à ses questions : l'adresse est redemandée tant qu'elle est incomplète, le mot de passe
tant qu'il fait moins de huit caractères. Le récapitulatif de fin d'installation donne l'adresse
de MIA, l'identifiant et le mot de passe — un mot de passe tiré au hasard n'y est affiché qu'une
fois. L'adresse de MIA ouvre alors la page de connexion.

**Écran « Bienvenue dans MIA »** — il n'apparaît que si l'installeur n'a pas pu créer ce compte :
tant qu'aucun compte n'existe, l'adresse de MIA ouvre cet écran à la place de la connexion. Il ne
demande qu'une chose, le **compte administrateur** (nom affiché, adresse e-mail, mot de passe
d'au moins huit caractères), et disparaît définitivement une fois le premier créé. **Remplissez-le
sans attendre** : il est ouvert à quiconque y arrive le premier. Toute la configuration du
serveur — base de données, adresse publique, secrets — a déjà été traitée par l'installeur ; cet
écran n'y touche pas.

**Écran « Compte créé — MIA est prête »** — la liste de ce qui reste à configurer, chaque ligne
indiquant son état réel et menant directement à la page concernée. Il suit l'écran « Bienvenue
dans MIA ». Sinon, une fois connecté comme administrateur : tant qu'aucune clé d'API n'est
enregistrée, le bandeau de démarrage du **Tableau de bord** y mène (**Voir la liste de
démarrage →**) ; à tout moment, l'adresse de MIA suivie de `/setup/suivant` l'ouvre.

1. **Enregistrer une clé API de modèle.** *Seule étape indispensable* : sans clé, l'assistant
   ne peut rien répondre. Tant qu'elle n'est pas faite, la barre latérale d'administration fait
   **clignoter Modèles IA / Clés API** (pastille ambre) et le tableau de bord affiche un bandeau
   de démarrage — impossible de la manquer.
2. **Activer la vectorisation** des bases de connaissances (facultatif : sans elle, la
   recherche documentaire reste possible, mais moins pertinente).
3. **Configurer les outils** image et vidéo (facultatif).
4. **Personnaliser** le nom, le logo et les couleurs (facultatif).
5. **Vérifier l'état de l'installation** sur la page Diagnostic.

> **Un problème avant même la page de connexion ?** MIA ne présente plus de page blanche. Trois
> écrans distincts signalent les trois pannes possibles au démarrage : une **configuration
> incomplète** (la variable manquante est nommée à l'écran), une **base de données injoignable**
> (l'adresse essayée et la marche à suivre sont affichées), ou une **erreur interne** (un
> identifiant d'incident est affiché ; il permet de retrouver la trace exacte dans les journaux).

> **Service « worker » (générations en arrière-plan).** L'installation Docker démarre, à côté de l'application, un conteneur **worker** qui exécute les générations longues (pages HTML, sites illustrés) hors de la page de l'utilisateur. C'est lui qui permet de **quitter la conversation, changer de page ou recharger** pendant une génération sans la perdre.
> - Il démarre tout seul avec `docker compose up -d` — rien à configurer.
> - **Plusieurs workers tournent en parallèle** (4 par défaut), chacun menant une génération à la fois : les réponses de plusieurs personnes avancent ensemble au lieu de faire la queue. Leur nombre se règle par `MIA_WORKERS` dans le `.env` (comptez un worker pour 3 à 5 personnes qui conversent en même temps, ~25 Mo de mémoire chacun au repos), pris en compte au prochain `docker compose up -d` ou déploiement. N'utilisez pas `--scale` : le déploiement suivant reviendrait au nombre du `.env`.
> - Une personne qui attend une réponse passe toujours avant les tâches programmées, elles-mêmes avant la maintenance (ingestion de documents, synchronisation, réindexation), qui ne mobilise **qu'un worker à la fois** : une grosse synchronisation ne retient plus les conversations. Dans une même conversation, les réponses partent **une par une, dans l'ordre** des messages.
> - **Son absence n'est pas bloquante** : l'application détecte qu'aucun worker ne tourne et exécute la génération autrement (au prix d'une requête plus longue). Aucune tâche n'est perdue.
> - En cas d'arrêt brutal d'un worker (redémarrage, mémoire épuisée), une génération restée en plan est repérée par un autre worker dans les deux à trois minutes et **marquée en échec** — jamais laissée « en cours » indéfiniment. La conversation reçoit alors le message **« Génération interrompue »**, qui invite à relancer la demande ; une tâche interne (ingestion, synchronisation) est simplement relancée.
> - Le worker a besoin d'un **accès Internet sortant** (il appelle les API des fournisseurs). Il l'annonce au démarrage : `[worker] accès Internet sortant OK`. Si le journal affiche `⚠ pas d'accès Internet sortant`, aucune génération n'aboutira — et les tests de la page **Modèles IA** resteront pourtant au vert, car ils partent du serveur web, pas du worker.
> - Le worker traite aussi les **tâches internes** : ingestion des documents de bases de connaissances, réindexation, synchronisation des dossiers distants (Drive / SharePoint / Agenda) et mise à jour des prix. Elles n'ont plus besoin de la commande système `exec` pour tourner en arrière-plan.
> - Le worker doit avoir **exactement la même configuration que le serveur web** : c'est le même code qui y tourne. Il le vérifie au démarrage et l'annonce : `[worker] configuration complète`. Si le journal affiche `⚠ configuration incomplète`, la ligne nomme ce qui manque — par exemple les identifiants Google, sans lesquels les **connecteurs Drive, Gmail, Agenda et Tasks disparaissent silencieusement des conversations** (les assistants répondent qu'ils ne peuvent pas les utiliser) alors que la page **Comptes connectés** de chaque utilisateur reste, elle, parfaitement verte. Dans ce cas : `docker compose up -d --force-recreate worker`.

---

## 2. Tableau de bord

**Administration → Tableau de bord**

> **Revenir au chat.** Le menu latéral commence par le lien **← Retour au chat**, dans un cadre qui reste en vue quand le menu défile : il ramène aux conversations depuis n'importe quelle page d'administration ou de vos réglages personnels. Sur mobile, il se trouve en haut du volet ouvert par l'icône ☰.

Page d'accueil de l'administration : vue synthétique pensée pour l'administrateur **et** le suivi financier, centrée sur le **mois en cours**.

- **Indicateurs du mois** (comparés au mois précédent) : **crédits consommés**, **coût réel IA** (ce que les fournisseurs facturent, hors marge), **valorisation des crédits** (crédits × valeur actuelle du crédit), **opérations** et **utilisateurs actifs** (sur le total de comptes actifs).
- **Projection fin de mois** : estimation du **coût réel** du mois complet **au rythme quotidien actuel** (celui que plafonne le budget IA), disponible à partir du 3 du mois, avec l'évolution en % par rapport au coût réel du mois précédent (rouge = hausse, vert = baisse). Le % n'apparaît que si le mois précédent offre une base de comparaison significative. Sans budget, un lien **Fixer un budget** mène à **Coûts → Budget**.
- **Budget IA** (si un budget est fixé) : barre du mois avec le seuil d'alerte, dépensé / plafond, projection et date estimée d'atteinte du plafond, lien **Gérer le budget**.
- **Consommation des 12 derniers mois** : graphique en barres empilées par **famille d'opérations** (Conversations / Créations par outil / Bases de connaissances / Conception & admin) ; le survol affiche le détail et l'équivalent en euros. Le lien **Analyse détaillée** ouvre la page **Consommation**.
- **Top consommateurs du mois** : les 5 utilisateurs les plus consommateurs (crédits, coût €, part du total). Le lien **Tout voir** ouvre la vue par utilisateur filtrée sur le mois en cours.
- **Alertes** :
  - utilisateurs à **80 % ou plus de leur quota mensuel** (badge jaune, rouge à 100 % — l'utilisateur est alors bloqué ; seules les opérations qui entament le quota comptent, pas la maintenance) ;
  - **points à régler** : les mêmes constats que les pastilles du menu, chacun avec son chemin et un lien **Régler →** (voir [Points à régler](#points-à-régler)) — les comptes en attente d'activation en font partie ;
  - absence de **clé API LLM active** (plateforme inutilisable).
  - **budget IA** : seuil d'alerte franchi, plafond atteint (demandes suspendues, ou dépassement en mode signalement), ou projection du mois au-dessus du plafond.
- **Plateforme** : comptes actifs / total, nouveaux comptes du mois, assistants actifs, fournisseurs LLM actifs, valeur du crédit, jetons consommés le mois en cours. Chaque ligne renvoie vers la page de gestion concernée.
- **Stockage** : place disque occupée par la plateforme, avec le **total** et une **barre de répartition** détaillée par composant — **base de données MySQL** (données + index), **base vectorielle Qdrant** (taille **réelle mesurée sur le disque**, WAL et index compris, quand le volume est accessible — c'est le cas du déploiement Docker standard ; sinon estimation d'après l'index, signalée comme telle), **médias générés** (images et vidéos créées par les outils), **documents & fichiers** (pièces jointes du chat, bases de connaissances, documents des assistants) et **application** (code, assets, logs, cache). Les tailles s'affichent en Mo ou en Go selon l'ordre de grandeur ; la mesure est **actualisée toutes les 10 minutes** (heure du dernier calcul indiquée sous la carte). Un composant injoignable (MySQL ou Qdrant en panne) apparaît « indisponible » et est exclu du total.

---

## 3. Personnes — Comptes

**Administration → Personnes → Comptes**

En haut de page, **cinq tuiles de synthèse** : **Comptes**, **Actifs**, **Inactifs** (en rouge s'il y a des comptes à approuver ou à nettoyer), **Admins** et **Quota ≥ 90 %** (utilisateurs proches de leur plafond mensuel). **Cliquer une tuile filtre la liste** ; re-cliquer revient à l'affichage complet.

Juste dessous, une **barre de recherche** (nom ou e-mail, filtrage instantané) et trois filtres : **rôle**, **statut**, **méthode de connexion**. Les en-têtes de colonnes **Utilisateur**, **Rôle**, **Quota mensuel** et **Activité** sont **cliquables pour trier**. À droite de la barre, le bouton **Copier les emails** met dans le presse-papier les adresses des utilisateurs **actuellement affichés** (recherche, filtres et tuiles pris en compte) au format `Prénom NOM <email>`, séparées par des virgules — prêt à coller dans le champ destinataires d'un e-mail. Le bouton confirme brièvement le nombre d'adresses copiées.

**La liste** montre par compte : avatar, nom et e-mail, badge de **rôle** (Admin / Créateur / Utilisateur) avec la méthode de connexion dessous (Google / Microsoft / Mot de passe / Admin local — un compte qui en a plusieurs apparaît sous chacune dans le filtre), les **groupes** (les deux premiers + « +n » au survol), le **quota mensuel** (voir plus bas), et la colonne **Activité** — **dernière utilisation réelle** (message ou outil : « aujourd'hui », « hier », « il y a N j »…, ou « — » si jamais) et date d'inscription. Les comptes **inactifs** apparaissent grisés. Votre propre compte porte le badge **vous**.

- **+ Nouvel utilisateur** (bouton en haut à droite) : nom, e-mail, mot de passe (au moins 8 caractères), rôle, statut et **groupes** — le tout dans une fenêtre dédiée. Ce formulaire crée un compte **à mot de passe**. Une personne qui se connecte par **Google ou Microsoft** n'a pas besoin d'y passer : son compte apparaît tout seul à sa première connexion (actif ou en attente selon la règle d'accès). Le créer quand même, avec la même adresse, permet de préparer rôle et groupes : MIA y rattache son identité à la première connexion (pour Microsoft, si l'annuaire ou le domaine est déclaré dans Authentification).
- **Modifier** (bouton en bout de ligne) ouvre la **fiche du compte** : en tête, sa consommation (**crédits du mois**, **total historique**, **dernière activité**) ; puis le **rôle**, le **quota mensuel**, le statut **Compte actif**, les **groupes** (cases à cocher — le groupe ★ Par défaut est verrouillé, tout le monde en fait partie), et la section **Sécurité** : **Réinitialiser le mot de passe** (comptes locaux uniquement) et **Supprimer ce compte** (impossible sur votre propre compte).
- **Supprimer un compte — confirmation informée :** avant la suppression, MIA affiche l'**impact réel** du geste, particulièrement important pour un **créateur** ou un **administrateur** : conversations supprimées définitivement, **assistants et bases de connaissances qui perdent leur créateur** (ils survivent mais ne sont plus gérables que par les administrateurs), partages accordés qui tombent, **synchronisations Google Drive portées par sa connexion personnelle qui casseront** (⚠ signalées nommément — rouvrez ces bases pour choisir un autre accès avant ou après la suppression), et connexions personnelles (Drive, Gmail, Agenda) révoquées. Lisez cette liste avant de confirmer : l'action est irréversible.

**Règles d'accès** *(carte sous les tuiles de synthèse)* — deux réglages qui valent pour toute l'instance :

- **Activer automatiquement les nouveaux utilisateurs.** Décochée, chaque nouveau compte arrive **inactif** et doit être approuvé à la main dans la liste (la tuile **Inactifs** les compte).
- **Domaine e-mail de l'équipe.** Les comptes dont l'adresse se termine par ce domaine voient les éléments réservés à l'équipe (détail des correctifs dans l'historique des versions). Vide = personne.

*(Ces deux réglages se trouvaient auparavant dans l'ancienne page Configuration → Général, devenue **Organisation → Marque & apparence**.)*

**Le quota mensuel — attention à l'unité :**

- Le champ de quota de la fiche se saisit en **milliers de crédits par mois** (**k crédits**). Saisir **10** = **10 000 crédits par mois**, pas 10.
- **Laisser le champ vide ne signifie pas « illimité »** : l'utilisateur reçoit alors le **quota le plus généreux parmi ses groupes actifs**. Il n'est réellement illimité que si **aucun** de ses groupes ne définit de quota — le texte sous le champ indique précisément d'où viendra le quota appliqué.
- La colonne **Quota mensuel** de la liste indique la consommation du mois, le plafond, le pourcentage atteint et — en italique — **d'où vient le quota appliqué** : *Individuel*, *Groupes (max)* ou *Illimité*. La barre passe à l'orange puis au rouge à l'approche du plafond, et l'équivalent en euros s'affiche dessous.
- Un quota saisi directement sur l'utilisateur **prime** sur celui de ses groupes.
- Quand un **budget IA** est fixé (section 8), un **repère** s'affiche sous le champ : le quota par compte actif qui garantit de ne jamais atteindre le budget.

### Rôles et droits

**Administration → Personnes → Rôles** *(administrateurs seuls)*

Chaque compte porte **un rôle**. Le rôle décide de ce que la personne **peut faire** ; ses **groupes** (section 4) décident des assistants, outils et modèles **qui lui sont ouverts**. Trois rôles sont livrés — ils ne se suppriment pas, et leurs droits par défaut sont exactement ceux que MIA a toujours donnés :

| Rôle | Par défaut |
|---|---|
| **Utilisateur** | Discute avec MIA et les assistants de ses groupes, choisit les bases qu'on lui partage, utilise les outils de ses groupes, relie ses comptes (connecteurs), programme ses tâches. |
| **Créateur** | + crée, modifie et supprime **ses** assistants (10 au plus), **ses** bases de connaissances (5 au plus) et **ses** workflows. |
| **Administrateur** | Tous les droits, rôles et données compris. **Verrouillé** : il reste toujours quelqu'un capable de tout régler. |

**L'écran.** À gauche, la liste des rôles (livrés, puis personnalisés) avec le nombre de comptes qui portent chacun ; à droite, les droits du rôle choisi (le premier de la liste à l'ouverture), en deux tableaux — **Fonctionnalités** (assistants, bases de connaissances, tâches programmées, workflows, outils, connecteurs) et **Administration** (tableau de bord, comptes, groupes, gestion des LLM, authentification, coûts, entreprise et apparence, diagnostic). Six colonnes partout : **Voir** — voir la fonctionnalité apparaître : son entrée dans le menu et sa page, en lecture seule. Sans cette case, elle reste cachée — sauf dans la conversation, où « Utiliser » décide ; **Utiliser** — s'en servir, sans rien régler : dans la conversation (assistants, bases, outils, connecteurs), en faisant tourner ses tâches et ses workflows, ou, dans l'administration, en lançant tests et réparations ; **Créer**, **Modifier**, **Supprimer** — en ajouter, en changer les réglages, en retirer définitivement, chacun supposant « Voir » ; **Gérer** — ceux de toute l'organisation, pas seulement les siens : les voir tous, les modifier, les partager et en changer la portée (suppose « Modifier »). « Voir » et « Utiliser » sont indépendants pour ce qui sert dans la conversation : le rôle Utilisateur discute avec les assistants sans voir la page Assistants ; ce qu'on lance depuis sa page (tâches programmées, workflows, tests des modèles, réparations du diagnostic) demande aussi « Voir ». Les tâches programmées et les workflows sont propres à chaque personne : chacun ne voit et ne gère que les siens, administrateurs compris — le rôle dit seulement ce qu'elle peut en faire. **Aucune légende n'occupe la page** : survoler, toucher ou atteindre au clavier un en-tête de colonne, le nom d'une ligne ou une case affiche une bulle au-dessus de lui — ce que veut dire la colonne, ce que couvre la ligne (et chacune de ses cases), ce qu'ouvre la case et ce qu'elle coche avec elle. Un tiret signale une action sans objet, et sa bulle dit pourquoi. **En résumé**, en tête, récapitule le rôle et suit chaque case. Au téléphone, chaque fonctionnalité devient une carte, et les colonnes une rangée de pastilles à toucher.

- **Implications.** Cocher une case coche ce qu'elle suppose (« Modifier » suppose « Voir », « Gérer » suppose « Modifier » ; « Utiliser » suppose « Voir » pour ce qui se lance depuis sa page — tâches programmées, workflows, tests des modèles, diagnostic ; pour les tâches et les workflows, « Créer » suppose aussi « Utiliser ») ; en décocher une décoche ce qui en dépend. La case touchée par ricochet pulse. Le serveur rejoue la même règle sur ce qu'il reçoit.
- **Gérer** (assistants et bases de connaissances seulement) : sans la case, la personne tient **ce qu'elle a créé** (ou qu'on lui partage) ; avec, **tous ceux de l'organisation** — voir et modifier tous les assistants et toutes les bases, les partager aux groupes, rendre un assistant personnel, régler le partage d'une base, brancher un cerveau MyBrain, régler les sources synchronisées, sans le plafond des créateurs ; dans la conversation, tous les assistants sans passer par les groupes et toutes les bases. Avec **Supprimer**, supprimer ceux des autres. Sans objet ailleurs : tâches, workflows et connecteurs sont propres à chacun, les outils appartiennent à l'organisation. Un rôle personnalisé créé avant cette colonne garde la portée « toute l'organisation » sous forme de **Gérer** s'il avait aussi **Modifier** ; sinon il la perd (Gérer supposant Modifier, la lui donner aurait élargi ses droits).
- **Enregistrement manuel** — exception assumée à l'enregistrement automatique, comme Authentification et Budget : des droits ne changent pas à moitié cochés. Chaque case changée porte un anneau, la barre du bas compte les changements ; **Annuler** revient à l'état enregistré, **Enregistrer** applique. Si des comptes portent le rôle et que des droits partent, MIA les liste avant d'enregistrer. Le message dit combien de droits ont été ajoutés ou retirés, et pour combien de comptes. Chaque écriture est journalisée (`[Rôles]`, avec l'auteur).
- **Effet** : dès la page suivante des comptes concernés ; une tâche programmée ou un workflow relit les droits à sa prochaine exécution.

**Rôles livrés.** **Créateur** et **Utilisateur** se modifient ; un rôle modifié porte la mention **modifié**, et **Rétablir les droits par défaut** le ramène au tableau ci-dessus. **Administrateur** ne se modifie pas.

**Rôles personnalisés** (30 au plus). **+ Nouveau rôle** : un nom, une description, une couleur et le rôle dont il part (**Partir des droits de** — *Administrateur* donne tout sauf les rôles et les données ; **Aucun droit** part de zéro). **Dupliquer** copie le rôle affiché, avec ses droits enregistrés. Nom, description et couleur se modifient dans l'éditeur ; la couleur colore le badge du rôle dans la liste des comptes. **Supprimer le rôle** demande quel rôle prendront ses comptes — *Administrateur* n'est jamais proposé : une suppression ne promeut personne.

**Réservé aux administrateurs, quel que soit le rôle** : les **rôles** eux-mêmes (un rôle qui pourrait modifier les rôles pourrait s'accorder tous les droits) et les **données** (sauvegarde, restauration, élagage — restaurer réécrit les comptes et les rôles).

**Déléguer sans risque.** Un rôle qui ouvre **Comptes** à quelqu'un qui n'est pas administrateur reste borné : il ne donne que des rôles dont les droits sont compris dans les siens (jamais *Administrateur*), ne modifie pas un compte plus large que le sien — il le consulte (**Voir**) —, et personne ne change son propre rôle ni ne désactive son propre compte. Le compte d'administration locale (accès de secours) reste toujours administrateur. Une page qu'un rôle **voit** sans pouvoir y écrire s'ouvre en **lecture seule** (bandeau, champs désactivés) ; le menu d'administration, le bouton **Admin** et les **points à régler** ne montrent que les pages que le rôle ouvre.

**Ce que retirer un droit change** :

- **Assistants — Utiliser** : plus aucun assistant, pas même ceux du groupe ★ Par défaut.
- **Outils — Utiliser** : plus aucun outil, ni dans le menu **+**, ni via un assistant.
- **Connecteurs — Utiliser** : connecteurs absents du chat et de **Réglages → Comptes connectés** (les comptes déjà reliés restent affichés pour pouvoir les déconnecter) ; une action préparée n'est plus approuvable.
- **Tâches programmées — Utiliser** : les tâches de ces comptes **sautent** leurs exécutions (motif dans l'historique), sans pause : elles repartent seules quand le droit revient.
- Une fonctionnalité éteinte par l'administrateur (tâches programmées, workflows, un connecteur) le reste, quel que soit le rôle.

*Exemples* : un **responsable des comptes** (part d'*Utilisateur*, + **Comptes** Voir/Créer/Modifier et **Groupes** Voir/Modifier) ; un **référent des assistants** (part de *Créateur*, **Gérer** sur les assistants) ; un **support en lecture** (**Voir** sur **Gestion des LLM** et **Diagnostic**) ; des **stagiaires sans connecteurs** (*Utilisateur*, **Connecteurs — Utiliser** décoché).

---

## 4. Groupes d'utilisateurs

**Administration → Personnes → Groupes**

La page affiche une **carte par groupe** ; un clic sur une carte (ou sur **+ Nouveau groupe**) ouvre la fenêtre de gestion en trois volets : **Paramètres** (nom, quota, statut), **Membres** (recherche + cases à cocher) et **Droits d'accès** (assistants, outils, modèles, avec raccourcis *Tout / Aucun*).

- **Groupe « Par défaut »** (affiché en premier avec une étoile ★) : il contient **automatiquement tous les utilisateurs**, y compris chaque nouveau compte. Il **ne peut pas être supprimé** et ses membres ne se gèrent pas à la main. Les droits qu'on y coche constituent le **socle commun** de la plateforme.
- Les groupes **cumulent** les droits : un utilisateur voit l'**union** de ce que ses groupes actifs autorisent (assistants, outils et modèles).
- **Quota** : on retient le **plus élevé** parmi les groupes actifs ; un quota défini directement sur l'utilisateur **est prioritaire**.
- Par groupe : **nom du groupe**, **Quota (k crédits/mois)**, **statut** (*Groupe actif*), **membres**, **assistants autorisés**, **outils autorisés**, **modèles autorisés**.
- Le quota du groupe se saisit lui aussi en **milliers de crédits par mois** et par membre : **10 = 10 000 crédits par mois**. **Vide = groupe neutre**, pas illimité : la carte affiche « Quota mensuel : aucun (neutre) », et le membre reçoit le plus élevé des quotas de ses autres groupes — il n'est illimité que si aucun n'en définit.
- Avec un **budget IA** (section 8), le champ affiche le même **repère** de quota par compte actif.
- **Liste de modèles vide** = groupe neutre. Un utilisateur dont seul le groupe par défaut est actif — sans liste de modèles cochée — n'a accès qu'au **modèle par défaut** de l'application.
- Les groupes **inactifs** sont ignorés (ni droits, ni quota).

---

## 5. Assistants

La tuile **Assistants** de la barre latérale (rangée **Espaces**) ouvre directement la liste (page **Assistants**) ; les assistants ne sont pas dans le menu de l'administration, et un administrateur y voit, depuis la même tuile, tous ceux de l'organisation. Le bouton **+ Nouvel assistant** de la liste et l'action **Modifier** ouvrent de **vraies pages** — plus de fenêtre pop-up.

La liste est organisée en **deux sections** : **Partagés — organisation** (distribués aux utilisateurs via les groupes) et **Personnels** (visibles de leur seul créateur). Chaque ligne montre d'un coup d'œil :
- l'**identité** : avatar, nom, description, **auteur** (« créé par … » ; « Système » pour les assistants intégrés), et les badges d'état — **Global** et **Inactif**. Le badge **Global** signale un assistant visible par tous, sans filtrage de groupe : il est **réservé à l'assistant d'aide livré avec MIA** et ne se règle pas depuis l'interface — c'est un simple affichage ;
- la **nature** : badge **Partagé** / **Personnel**, accompagné de l'interrupteur **Partage organisation** pour basculer directement un assistant **personnel ↔ partagé** (réservé aux administrateurs ; absent si l'assistant n'a pas de créateur). Un assistant partagé doit ensuite être **autorisé dans les groupes** pour être visible — raccourci dans le menu **⋯** ;
- les **réglages clés** en pastilles compactes : LLM par défaut (logo + nom lisible), température, nombre de documents de référence, bases de connaissances liées, outils liés ;
- les **actions** : **Utiliser** (ouvre une conversation), **Modifier** (ouvre la page d'édition), menu **⋯** (autoriser dans les groupes, supprimer).

La barre du haut permet de **rechercher** (nom, description, auteur, LLM), **filtrer** (statut, LLM) et **trier** (nom, auteur, LLM, température, documents — bouton ↑/↓ pour inverser l'ordre).

**Cliquer une ligne affiche, à droite, le schéma de l'assistant** — le même que celui des tâches programmées, sans déclencheur : modèle et température, début du prompt système, état, partage, bases de connaissances, documents de référence et outils (sur un écran étroit, il se déplie sous la ligne). Il accompagne aussi la fiche, en création comme en modification, et chacun de ses éléments y mène au réglage correspondant — détail dans le **guide créateur** (« Lire le schéma d'un assistant »).

Mêmes réglages d'édition que dans le **guide créateur** (3 sections : Identité, Comportement, Connaissances — plus l'interrupteur **Actif / Inactif** en haut de la page), avec en plus, côté administrateur :
- créer des assistants en **nombre illimité** (les créateurs, eux, sont plafonnés) ;
- faire basculer un assistant **personnel ↔ partagé** (interrupteur **Partage organisation** de la liste) ;
- gérer la **bibliothèque d'avatars** (téléverser, renommer, supprimer).

> **Un assistant naît toujours personnel.** Il n'existe pas de case « partagé » à la création : quel que soit l'auteur, le nouvel assistant n'est visible que de son créateur. Pour le distribuer, créez-le d'abord, puis activez l'interrupteur **Partage organisation** sur sa ligne dans la liste, et enfin **autorisez-le dans les groupes** concernés (menu **⋯**).

---

## 6. Modèles IA (clés, modèles, défaut, tests)

**Administration → Modèles IA**

La rubrique **Modèles IA** du menu d'administration est **repliable**, comme les autres, et regroupe trois pages : **Clés API**, **Modèles** (comportement par défaut en tête, puis les modèles activés) et **Tests**. Elle vient juste après **Personnes**, avant **Capacités** : les clés et les modèles d'abord, ce qui s'en sert ensuite.

> **Signal de configuration initiale.** Tant qu'**aucune clé API n'est enregistrée** (ni fournisseur
> cloud, ni LLM local), MIA ne peut répondre à personne. Le menu d'administration le signale de lui-même :
> une **pastille ambre clignotante** apparaît sur **Modèles IA** et sur **Clés API** (au survol, une
> bulle le dit), la rubrique reste dépliée, et le **Tableau de bord** comme la page **Clés API** affichent un bandeau expliquant
> la marche à suivre. Tout s'éteint dès la première clé enregistrée (ou le premier LLM local
> configuré) — rien à faire pour le retirer.

**Fournisseurs disponibles :** **ChatGPT (OpenAI)**, **Claude (Anthropic)**, **Mistral**, **Perplexity**, **Gemini (Google)**, **DeepSeek**, **Kimi (Moonshot)**, **GLM (Z.ai)** — ce sont les intitulés exacts de la liste déroulante **Fournisseur**. S'y ajoute le **LLM local (OpenAI-compatible)**.

### Clés API
- **Fournisseurs cloud** : choisir le **Fournisseur**, le **Modèle**, coller la **Clé API**. L'enregistrement est [automatique](#enregistrement-automatique). La clé est **chiffrée** et n'est jamais réaffichée en clair (seuls ses premiers caractères restent visibles).
  - Le sélecteur **Fournisseur** désigne la fiche que vous configurez : en changer n'enregistre rien, cela ouvre simplement les réglages d'un autre fournisseur.
  - Pour un fournisseur **déjà configuré**, le champ Clé API affiche *« Clé enregistrée — laissez vide pour la conserver »* : vous pouvez donc **changer le modèle sans ressaisir la clé**. Ne remplissez ce champ que pour remplacer la clé.
  - Pour un fournisseur **sans clé**, le champ est obligatoire : tant qu'il est vide, rien n'est enregistré et la pastille le signale.
- Une fois enregistrée, chaque clé apparaît dans un tableau (**Fournisseur**, **Clé API**, **Statut**, **Modifié**, **Actions**) avec les boutons **Tester**, **Modifier** et **Supprimer**.
- **LLM local (OpenAI-compatible)** : connecter un serveur local tel qu'**Ollama**, **LM Studio** ou **Jan**, ou le **cloud Ollama** (adresse `https://ollama.com/v1`, clé API obligatoire) — renseigner un nom affiché, le modèle, l'adresse du serveur et, au besoin, une clé. La case **Ce modèle sait appeler des outils (function calling)** ouvre au LLM local les connecteurs et les outils des assistants ; elle est décochée par défaut, car MIA ne peut pas savoir ce que sait le modèle servi par votre serveur — ne la cochez que pour un modèle qui le prend en charge (Llama 3.1 et suivants, Qwen 2.5, Mistral…), sinon chaque message qui engage un outil échouera.
- **Tester une clé** : un bouton **Tester** vérifie chaque modèle actif et affiche le résultat (réussite ou message d'erreur).

### Modèles
- **Catalogue des modèles** : activer/retirer chaque modèle, consulter ses capacités (vision, fichiers, web, raisonnement, code) et ses limites. Un modèle ne peut être activé que si la clé API de son fournisseur est enregistrée (page **Clés API**).
- Les badges **Défaut** et **Secours** signalent d'un coup d'œil quels modèles jouent ces rôles.
- **Connecteurs et outils sur presque tous les éditeurs.** ChatGPT, Claude, Gemini (génération 3), Mistral, DeepSeek (V4.1 Flash), Kimi et GLM pilotent les connecteurs et les outils des assistants ; le LLM local aussi, si vous cochez sa case. N'en ont pas : **Perplexity** (son API n'en offre pas), les modèles **Gemini 2.5** (ils ne savent pas chercher sur Internet en même temps qu'ils utilisent un outil : la recherche, cochée par défaut, aurait été éteinte à chaque message) et **DeepSeek R1** (en mode réflexion, DeepSeek exige le raisonnement de toutes les réponses passées, que MIA ne conserve pas). Avec **Mistral**, recherche Internet et outils ne se cumulent pas dans une même réponse : un connecteur allumé ou un outil d'assistant passe avant, la recherche est mise de côté et la réponse le dit ; **Laisser MIA choisir**, simple préférence, cède au contraire la place à la recherche.
- **Le premier modèle activé devient le modèle par défaut** (message « Instance LLM ajoutée. Modèle par défaut : … »). Sans ce geste, sur une instance neuve, les membres du seul groupe ★ Par défaut n'avaient aucun modèle et recevaient « Ce LLM n'est pas autorisé pour votre groupe. ». Retirer le modèle par défaut en désigne aussitôt un autre — celui que le catalogue propose par défaut pour son éditeur s'il est activé, sinon le premier de la liste — et le message le nomme.
- **Claude Opus 5.5 et Claude Sonnet 5.5.** Les derniers modèles d'Anthropic, en tête de leur éditeur dans le catalogue. **Opus 5.5** est l'Opus courant : plus capable qu'Opus 5 et **moins cher** (4 $ le million de tokens en entrée, 20 $ en sortie, contre 5 $ et 25 $). **Sonnet 5.5** offre le meilleur équilibre entre vitesse et intelligence, au prix de Sonnet 5 (2 $ et 10 $), avec une lecture de cache deux fois moins chère (0,10 $ le million au lieu de 0,20 $ — le catalogue la comptait au prix de Sonnet 5 jusqu'au 17/10/2026). Le modèle proposé par défaut par le catalogue reste **Claude Opus 5** : pour passer à Opus 5.5, activez-le puis choisissez-le dans la carte **Comportement par défaut**. Tous deux réfléchissent avant de répondre ; quand ils utilisent des connecteurs, les notes d'avancement qu'ils écrivent entre deux actions s'affichent en petit sous la liste des actions (comme avec Claude Fable). Sur un message qui engage des connecteurs, MIA demande à **Opus 5.5** un effort de raisonnement **élevé** (son réglage par défaut est moyen) : le travail en plusieurs étapes y gagne en fiabilité, pour un peu plus de tokens ; une question simple garde le réglage par défaut. Le tarif de **Claude Sonnet 5** est corrigé au catalogue (2 $ et 10 $ : Anthropic a gardé son tarif de lancement au lieu de la hausse annoncée pour le 1er septembre 2026) ; si les tarifs de l'instance sont enregistrés dans **Coûts → Tarifs & crédits**, cliquez **Mettre à jour coûts Internet (USD)** pour appliquer la correction.
- **Modèles premium : Claude Fable 5.1 et GPT-6 Astra.** Ce sont les modèles les plus capables d'Anthropic et d'OpenAI, en tête de leur éditeur dans le catalogue — et les plus chers, environ **deux fois Claude Opus 5 ou GPT-5.6** (10 $ le million de tokens en entrée, 50 $ en sortie). Ils ne deviennent le modèle par défaut que si vous les choisissez, ou s'ils sont le tout premier modèle activé de l'instance : activez-les à dessein, par exemple pour quelques assistants qui en ont réellement besoin.
  - **Refus d'un sujet sensible — reprise automatique par Opus.** Claude Fable 5.1 et Fable 5 (la plupart des contenus de cybersécurité, la recherche en biologie), Claude Opus 5.5 et Sonnet 5.5 (cybersécurité, biologie, demandes qui cherchent à leur faire recopier leur raisonnement ; Sonnet 5.5 aussi le développement de modèles d'IA concurrents), et dans une moindre mesure Claude Opus 5 (cybersécurité), peuvent **décliner** une demande. MIA demande alors à Anthropic de la faire reprendre, dans la même réponse, par le modèle qu'il recommande pour ce type de sujet (**Claude Opus 4.8 ou Opus 5** ; **Claude Sonnet 5** pour Sonnet 5.5, qui ne fait reprendre que la cybersécurité et le développement de modèles concurrents) : l'utilisateur reçoit sa réponse, le badge du message nomme le modèle qui a répondu, et la liste des actions indique **« Réponse reprise par un autre modèle après un refus »**. La consommation est comptée au tarif de chaque modèle qui a travaillé. Un refus survenu avant toute réponse n'est facturé par Anthropic que dans certaines catégories (biologie, développement de modèles concurrents, extraction du raisonnement) : MIA le compte alors dans les usages, au tarif du modèle qui a refusé. Si aucun relais n'est prévu pour le sujet, l'encadré **« Demande déclinée »** invite à reformuler ou à changer de modèle — ce refus n'est pas une panne et ne bascule pas sur le **LLM de secours**.
  - **Conservation des données.** Claude Fable 5.1 et Fable 5 ne sont ouverts qu'aux organisations Anthropic qui **conservent les données 30 jours** — c'est la règle par défaut d'Anthropic ; seules les organisations en **« zéro rétention »** (accord particulier) sont concernées. Dans ce cas, le test **Conversation de base** l'indique en toutes lettres, et le chat ne reste pas bloqué : la demande est **reprise par Claude Opus 5**, et la liste des actions l'annonce (**« conservation des données requise »**). Pour utiliser réellement Fable, trois voies côté Anthropic : activer la conservation 30 jours pour l'organisation ; ou créer un **workspace dédié** avec la conservation activée et enregistrer sa clé à la place de la clé actuelle (tous les appels Anthropic de MIA passent alors par ce workspace) ; ou demander une autorisation expresse à Anthropic.
  - **Réponses longues.** Fable raisonne toujours avant d'écrire, et ce raisonnement compte dans la longueur maximale d'une réponse : MIA laisse **64 000 tokens** à tous les modèles Claude, Fable compris, assez pour un long rapport ou une page web complète. Une demande difficile peut réfléchir plusieurs minutes avant le premier mot : la réponse n'est pas pour autant considérée comme bloquée.
  - **GPT-6 Astra** : au-delà d'environ **272 000 tokens envoyés dans une même requête** (très longs documents joints, bases de connaissances volumineuses), OpenAI facture toute la requête plus cher (×2 en entrée, ×1,5 en sortie). Ce palier n'est pas reflété dans le coût affiché par MIA.
- **Gemini 3.8 Flash, 3.7 Flash et 3.5 Flash-Lite.** Les derniers modèles de Google, en tête de leur éditeur : **3.8 Flash** est le plus capable (code, demandes en plusieurs étapes) mais réfléchit plus longtemps sur les tâches complexes, **3.7 Flash** consomme moins pour le quotidien, **3.5 Flash-Lite** est le plus rapide et le plus économique. Le modèle Gemini proposé par défaut par le catalogue reste **Gemini 3 Flash (preview)**.
  - **Tarif de lancement.** Jusqu'au **31/12/2026**, Google facture 3.8 et 3.7 Flash **0,75 $ / 3,75 $** le million de tokens (entrée / sortie), puis **1,50 $ / 7,50 $** : le coût affiché par MIA suit le tarif du jour et change seul le 1er janvier 2027 (sans tarif enregistré dans **Tarifs & crédits** — sinon c'est le tarif normal qui est compté, cf. *Remises datées et tarifs enregistrés*).
  - **Réflexion et température.** Comme tous les modèles Gemini 3, ils **réfléchissent avant de répondre** ; cette réflexion est facturée comme la réponse, et comptée dans le coût affiché. Google déconseille de régler leur **température** : MIA ne la leur transmet pas, et le curseur de la fiche d'un assistant est grisé.
  - **Recherche Internet.** Pour les modèles Gemini 3, Google facture **chaque recherche lancée** (14 $ les 1 000) — une réponse peut en compter plusieurs ; pour les modèles Gemini 2.5, **chaque réponse** qui s'appuie sur une recherche (35 $ les 1 000). MIA compte l'une ou l'autre selon le modèle.
  - **Gemini 3.1 Flash-Lite (preview)**, arrêté par Google le 25/05/2026, a quitté le catalogue : le remplacer par **Gemini 3.5 Flash-Lite** là où il était encore choisi (voir ci-dessous).
  - **Gemini 3.6 Flash, 3.5 Flash et 3.1 Flash-Lite** sont au catalogue (3.6 Flash au même tarif de lancement que 3.7 et 3.8 ; 3.5 Flash plus cher, classé « legacy » par Google ; 3.1 Flash-Lite le moins cher de la génération 3, dont Google annonce l'arrêt au plus tôt le 07/05/2027 au profit de 3.5 Flash-Lite). **Gemini 2.5** : depuis le 18/09/2026, Google en réserve l'accès aux comptes qui l'utilisaient déjà — une instance qui l'avait activé continue de le servir, une nouvelle ne l'obtiendra pas ; la colonne **Limites** le dit, et le bouton **Tester** d'une clé Gemini sans modèle activé interroge désormais 3.5 Flash-Lite.
- **DeepSeek V4.1 Flash** (10/09/2026) est le **seul** modèle que DeepSeek sert encore : `deepseek-chat` (V3) et `deepseek-reasoner` (R1), les deux seules entrées d'avant, sont **retirés et inaccessibles depuis le 24/07/2026** — tout message DeepSeek échouait depuis. Après la mise à jour, vérifier la page **Clés API**, la carte **Comportement par défaut** et les assistants qui imposaient un modèle DeepSeek, puis activer V4.1 Flash. Il **lit les images** jointes (pas les PDF), pilote les connecteurs, et MIA le fait répondre **sans sa phase de réflexion** (réponse rapide, température honorée). Tarif compté **aux heures de pointe** (0,30 $ / 1,20 $ le million de tokens, lecture de cache 0,006 $) : DeepSeek facture moitié prix la nuit, le week-end et les jours fériés chinois, ce que MIA ne modélise pas.
- **Mistral Large 4** (06/10/2026) ouvre Mistral dans le catalogue : modèle multimodal (texte et images), fenêtre de **1 million de tokens**, raisonnement réglable. Il est en **aperçu public** : Mistral peut le modifier sans préavis avant sa disponibilité générale, et le retirer avec un mois de préavis — la colonne **Limites** le dit. Tarif : **1,36 $ / 4,18 $** le million de tokens (entrée / sortie), à **moitié prix pendant les deux semaines de lancement** (MIA applique la remise jusqu'au **19/10/2026** inclus, puis le tarif normal tout seul — voir ci-dessous pour les tarifs enregistrés). Le modèle Mistral proposé par défaut reste **Mistral Large (latest)** — mais cet alias **basculera sur Large 4** le jour de sa disponibilité générale, tarif compris (2,7 fois Large 3) : pour figer coût et comportement, choisissez **Mistral Large 3 (25.12)**.
  - **Mistral Medium 3.5** porte désormais son identifiant officiel (**Mistral Medium 3.5**) ; l'entrée **Mistral Medium 3.5 (26.04)**, identifiant daté que la documentation de Mistral ne cite pas, reste proposée pour les instances qui l'ont activée (même modèle, même tarif) — préférez la nouvelle.
  - Quand un modèle Mistral raisonne avant de répondre (Small 4, Medium 3.5, Large 4), MIA n'affiche que la réponse et laisse la réflexion de côté ; elle reste comptée dans la consommation.
- **GPT-6.1 Sol et GPT-6 Luna** (OpenAI, septembre 2026) ouvrent OpenAI dans le catalogue. **GPT-6.1 Sol** approche GPT-6 Astra pour bien moins cher (**2 $ / 10 $** le million de tokens) ; **GPT-6 Luna** est le modèle le plus économique d'OpenAI (**0,10 $ / 0,50 $**). Tous deux lisent les images, cherchent sur Internet et pilotent les connecteurs ; comme les autres GPT-6, ils n'acceptent pas de température. Le modèle OpenAI proposé par défaut reste **GPT-5.6**.
  - **Tarifs GPT-5.6 corrigés** : **Terra** (2 $ / 12 $) et **Luna** (0,20 $ / 1,20 $, cinq fois moins qu'avant) ont baissé le 30/07/2026 ; **Sol** et l'alias **GPT-5.6** sont en promotion à **4 $ / 20 $** au moins jusqu'au **21/11/2026** — MIA applique la promotion jusqu'à cette date, puis le tarif normal (5 $ / 30 $).
  - **Remises datées et tarifs enregistrés.** Une remise de lancement ou une promotion (Mistral Large 4, GPT-5.6 Sol, Gemini 3.6 à 3.8 Flash) ne s'applique d'elle-même, puis ne s'arrête à sa date, que pour un modèle **sans tarif enregistré** dans **Coûts → Tarifs & crédits**. Un tarif enregistré prime et ne suit aucune date : **Mettre à jour coûts Internet (USD)** enregistre donc toujours le **tarif normal** (la note de la ligne mentionne la remise en cours) — pendant la remise, l'usage est compté un peu au-dessus du prix réel, jamais en dessous une fois la remise finie. La **dictée vocale** est comptée au prix de chaque modèle (0,017 $ la minute pour les modèles en direct qu'elle essaie en premier, au lieu de 0,006 $). Si les tarifs de l'instance sont enregistrés dans **Coûts → Tarifs & crédits**, cliquez **Mettre à jour coûts Internet (USD)** pour appliquer ces corrections.
  - **Retirés du catalogue** : **GPT-5.3 Chat (latest)** (arrêté par OpenAI le 10/08/2026), **GPT-4.5** (arrêté le 14/07/2025) et **GPT-5.6 Pro**, qui n'existe pas chez OpenAI (le mode Pro passe par GPT-5.6 Sol). **o3** et **GPT-5 Mini** seront arrêtés par OpenAI le **11/12/2026**, **GPT-5.3 Codex** le **01/04/2027** : prévoyez leur remplacement.
  - **Crédit épuisé chez OpenAI** : les nouveaux refus d'OpenAI (crédit prépayé épuisé, plafond mensuel de votre palier d'usage) sont reconnus comme tels — message clair et point rouge sur **Clés API** — au lieu de passer pour une saturation passagère.
- **Claude, modèles précédents** : **Opus 4.7, Opus 4.6, Sonnet 4.6 et Haiku 4.5** n'avaient aucun tarif au catalogue — leurs usages restaient « non tarifés » sans prix saisi dans **Tarifs & crédits** ; ils sont désormais comptés au tarif d'Anthropic. Sonnet 4.6 et Opus 4.6 disposent de leur fenêtre réelle d'un million de tokens (davantage de documents de référence et de bases de connaissances dans une réponse).
- **Température bornée par éditeur** : le curseur d'un assistant va de 0 à 2, mais Claude et GLM refusent au-delà de 1 et Mistral au-delà de 1,5 — un assistant réglé plus haut échouait à chaque message sur ces modèles. MIA ramène désormais la valeur au maximum de l'éditeur.
- **GLM 5.3 et GLM 5.3 Flash** (Z.ai, août 2026) sont au catalogue : 5.3 au même tarif que 5.2, texte seul ; **5.3 Flash** très économique (0,15 $ / 0,50 $ le million de tokens) et **lit les images jointes**. Tous deux réfléchissent toujours avant de répondre. Le modèle par défaut de l'éditeur reste GLM 5.2 tant que vous n'en choisissez pas un autre.
- **Kimi K2.6** (Moonshot) rejoint Kimi K3 : généraliste économique (0,95 $ / 4 $), lit les images, contexte de 256 000 tokens. **Kimi K2.7 Code n'est pas proposé** : il exige de recevoir le raisonnement de tous les messages précédents, ce que MIA ne conserve pas. Les anciens modèles Kimi (K2.5, K2, moonshot-v1) sont arrêtés par Moonshot depuis l'été 2026.
- **Perplexity.** **Sonar Reasoning**, retiré par Perplexity le 15/12/2025, a quitté le catalogue : le remplacer par **Sonar Reasoning Pro** là où il était choisi. Perplexity a mis fin au support de l'interface qu'utilise MIA le 27/09/2026 ; elle continue de répondre (Perplexity la redirige vers sa nouvelle interface), mais un comportement peut changer sans préavis — le signaler si les sources citées disparaissent. Chaque réponse Perplexity paie aussi des **frais de requête** (5 à 14 $ les 1 000 selon le modèle) : MIA les compte désormais, comme une recherche Internet.
- **Alias `latest` ou version pinée ?** Chez plusieurs fournisseurs, un même modèle est proposé sous deux entrées : l'alias (`Mistral Medium (latest)`) et la version figée (`Mistral Medium 3.5`). L'alias suit automatiquement la génération courante — pratique, mais le fournisseur peut le rebasculer **sans préavis**, capacités et **tarif compris** (le 29/04/2026, `mistral-medium-latest` est passé de Medium 3.1 à Medium 3.5, et de 0,40 $ à 1,50 $ le million de tokens en entrée). Choisir le snapshot daté fige coût et comportement ; en contrepartie, il finit par être retiré par le fournisseur et il faut alors le remplacer à la main. Un modèle en **aperçu** (mention dans sa colonne **Limites**, comme Mistral Large 4) n'a pas d'alias `latest` et se comporte comme lui : l'éditeur peut le modifier sans préavis, voire le retirer avant sa sortie définitive.
- **Modèles arrêtés par leur éditeur.** Les éditeurs annoncent l'arrêt de leurs anciens modèles quelques mois à l'avance. Dès l'annonce, la ligne du modèle porte le badge **Arrêt le …** et nomme son **Remplaçant désigné**, les listes **LLM par défaut** et **LLM de secours** affichent la date, et une pastille sur **Modèles** (et sur **Assistants** pour ceux qui l'imposent) le rappelle tant qu'il est activé. Avant la date : activer le remplaçant, le cocher dans les groupes, le choisir comme défaut, secours et dans les fiches concernées, puis retirer l'ancien. **Le jour de l'arrêt, sans rien faire**, MIA retire le modèle du chat : ses conversations, les assistants qui l'imposaient et le modèle par défaut s'il l'était passent sur le modèle par défaut ; la ligne affiche **Arrêté le …** et ne s'active plus ; un modèle choisi avec une clé API (page **Clés API**) cède la place au modèle par défaut de l'éditeur. Il reste à le retirer (pastille « modèle arrêté par son éditeur »).
  - **OpenAI, série GPT-4** : **GPT-4o, GPT-4o mini, GPT-4.1 et GPT-4.1 mini** s'arrêtent le **14/01/2027** (annonce du 14/10/2026), remplaçant désigné **GPT-6 Luna**, moins cher que chacun d'eux. Déjà annoncés : **o3** et **GPT-5 Mini** le **11/12/2026** (remplaçants GPT-5.6 Sol et GPT-5.6 Terra), **GPT-5.3 Codex** le **01/04/2027**.
  - **Un modèle retiré du catalogue** par une mise à jour plus ancienne de MIA (avant ce mécanisme) disparaît de cette page, mais s'il était sélectionné comme modèle d'un fournisseur (page **Clés API**) ou comme LLM d'un assistant, ce choix **reste en base** et les appels échouent : vérifier la page **Clés API**, la carte **Comportement par défaut** et les assistants qui pinent un modèle.

### Comportement par défaut (en tête de la page Modèles)

La carte **Comportement par défaut** ouvre la page **Modèles** : le défaut se choisit parmi les modèles activés juste en dessous. L'ancienne adresse de la page dédiée mène directement à cette carte.

- **LLM par défaut** : le modèle qui répond aux conversations **sans assistant** (seuls les modèles activés sont proposés). Si le réglage enregistré ne désigne plus aucun modèle disponible, la page le signale — « Aucun modèle par défaut n'est enregistré : MIA utilise … » — et le bouton **Confirmer ce modèle** l'enregistre.
- **LLM de secours** (facultatif) : utilisé automatiquement quand le modèle choisi (par défaut ou par un assistant) tombe en panne **avant le premier mot** — coupure réseau, erreur serveur ou surcharge du fournisseur, délai dépassé, limite de débit, et **compte du fournisseur sans crédit** (un éditeur à sec est indisponible pour toute l'instance, comme en panne). Il n'intervient pas sur ce qu'un autre modèle refuserait pareil (conversation trop longue, pièce jointe refusée, filtre de sécurité) ni sur un défaut de configuration (clé absente ou refusée, modèle retiré), que la bascule vous masquerait. Elle ne se déclenche jamais en cours de génération, ni une fois qu'une action a été menée dans la réponse (fichier lu, carte d'approbation proposée) : reprendre depuis le début referait tout en double, c'est donc l'encadré d'erreur qui s'affiche. Le secours garde la recherche Internet si son modèle la sait, et les connecteurs et outils de la conversation s'il sait s'en servir (modèles Claude et OpenAI) ; sinon il le dit à l'utilisateur au lieu de prétendre les avoir consultés. L'utilisateur lit la raison dans le message de bascule et dans la liste des actions de la réponse. Un secours identique au défaut est ignoré.
- *Le **vocabulaire commun**, qui se réglait ici, est devenu le **vocabulaire de l'entreprise** : Organisation → Entreprise (section 12), repris tel quel.*

### Ce que voient les utilisateurs quand un modèle échoue

Une panne n'est jamais muette : l'utilisateur lit un encadré dont le **titre nomme la cause**, et c'est ce titre qu'il vous rapportera. Ceux qui appellent un geste de votre part :

| Titre de l'encadré | Cause | Ce que vous avez à faire |
|---|---|---|
| **Crédit épuisé chez** l'éditeur | Le compte de l'instance chez cet éditeur n'a plus de crédit : solde prépayé épuisé, plafond de dépense (y compris le plafond mensuel qu'Anthropic signale comme une limite de débit) ou quota atteint, facturation inactive. | Recharger le compte ou relever son plafond, dans la console de l'éditeur, puis **Tester** la clé. D'ici là, un **LLM de secours** chez un autre éditeur répond à la place. Un point rouge sur **Clés API** vous le signale (cf. *Points à régler*). |
| **Configuration requise** | Aucune clé n'est enregistrée pour cet éditeur. | **Modèles IA → Clés API**. |
| **Accès au service refusé** | La clé est invalide, expirée ou révoquée. | Remplacer la clé, puis **Tester**. |
| **Accès au modèle refusé** | La clé est valide mais n'a pas le droit d'utiliser ce modèle : modèle non ouvert au compte, organisation à vérifier chez l'éditeur, région non prise en charge, API non activée. | Ouvrir l'accès chez l'éditeur, ou retirer le modèle de **Modèles activés**. |
| **Modèle indisponible** | L'éditeur ne connaît plus ce modèle : retiré, renommé, ou fermé à la clé. | Choisir un autre modèle (page **Clés API**, carte **Comportement par défaut**, assistants qui l'imposent). |
| **Modèle indisponible pour votre organisation** | Le modèle exige la conservation des données chez l'éditeur. | Activer l'option chez l'éditeur, ou laisser le relais répondre. |
| **Requête refusée** | L'éditeur refuse un réglage envoyé par MIA. | Lire le message de l'éditeur dans **Maintenance → Diagnostic → Journaux**. |

Les autres encadrés ne demandent rien à l'administrateur : **Service surchargé**, **Service temporairement indisponible**, **Service momentanément saturé**, **Délai de réponse dépassé** (l'éditeur ne répond pas — un LLM de secours prend le relais s'il est réglé) ; **Trop de contenu pour ce modèle**, **Pièce jointe refusée**, **Limite de longueur atteinte**, **Demande bloquée par le filtre de sécurité** (la demande est à reprendre). Une réponse qui s'arrête en route se termine par une ligne *Réponse interrompue : …* qui en dit la raison.

**Prévenir un administrateur.** Sur les encadrés qui appellent un geste de votre part — et sur les pannes qui durent —, l'utilisateur voit un bouton **Prévenir un administrateur** : il déplie la liste des comptes administrateurs **actifs** (nom et adresse ; l'accès de secours local n'y figure pas), chacun ouvrant un courriel déjà rédigé avec le titre et le texte de l'encadré. Un administrateur voit à la place un lien vers la page où régler la cause (**Ouvrir les clés API**, **Ouvrir les modèles**). Gardez donc une adresse valide sur les comptes administrateurs.

Le message **exact** de l'éditeur — avec son code (`insufficient_quota`, `context_length_exceeded`, `overloaded_error`…) — n'est jamais montré aux utilisateurs : il peut contenir un fragment de clé ou un identifiant de compte. Il est écrit dans les journaux (**Maintenance → Diagnostic → Journaux**), à la ligne de l'adaptateur concerné.

> **Réponses coupées par la longueur maximale.** La ligne *Réponse interrompue : le modèle a atteint sa longueur maximale de réponse* signale qu'un modèle a atteint son plafond de sortie. Chez les modèles qui raisonnent, la réflexion compte dans ce plafond. Les modèles Claude disposent de **64 000 tokens**, réflexion comprise — le maximum que les plus petits d'entre eux acceptent : la mention y est rare, et signale le plus souvent une demande démesurée (une page ou une présentation énorme), à découper. Si elle revient souvent sur un autre modèle, c'est son plafond qui est trop bas pour l'usage qui en est fait.

### Tests
Vérifications par modèle activé :
- **Conversation de base** — une réponse simple ;
- **Recherche web** — une recherche Internet (si le modèle la prend en charge) ;
- **Upload image / fichier** — l'analyse d'une image / d'un document (si le modèle la prend en charge).

Le résultat (réussite ou message) et la durée s'affichent par test ; un **score par modèle** et un **score global** se calculent au fil de l'eau. Le bouton **Lancer tous les tests** enchaîne toutes les vérifications.

Les modèles qui **raisonnent avant de répondre** (Claude Opus 5, Sonnet 5, Fable 5 et 5.1, GPT-5.x, GPT-6 Astra) peuvent mettre quelques secondes de plus. Si l'un d'eux s'arrête sans rien écrire, le test en donne la raison — par exemple `(vide — arrêt : max_tokens)` — au lieu d'un simple « (vide) ». Les tests interrogent le modèle **lui-même**, sans reprise par Opus : un échec sur Claude Fable signale une vraie limite de votre organisation (par exemple **« Conservation des données requise »**, avec les remèdes possibles), même si le chat, lui, fait déjà répondre un autre modèle.

---

## 7. Outils de génération

**Administration → Capacités → Outils**

- **Types :** image, vidéo, page HTML, **site illustré** (HTML + images générées), **carte / itinéraire** (trajet dessiné sur une carte OpenStreetMap), **automatisation n8n** (déclenchement de vos workflows), **présentation Gamma** (générée par gamma.app), plus quatre outils intégrés : la **dictée vocale**, la **conversation vocale**, les **tâches programmées** et les **workflows** (voir ci-dessous).
- **Dictée vocale (outil intégré) :** première carte de la grille. Rien à configurer ni à tester : son interrupteur, enregistré **immédiatement**, ajoute (ou retire) le **bouton micro** dans la zone de message de **tous les utilisateurs** (parole transcrite en texte par l'**API Realtime d'OpenAI**, en direct : l'audio va du navigateur à OpenAI sans passer par le serveur MIA). Sans clé API OpenAI, la carte affiche **Clé requise** et renvoie vers **Modèles IA**. La dictée est **soumise au quota** (minutes facturées à la fin de chaque session) : un utilisateur au quota épuisé ne peut plus en ouvrir, et une carte **Inactive** refuse aussi les sessions demandées depuis une page restée ouverte. La transcription suit la **langue de l'interface** de l'utilisateur (français ou anglais).
- **Conversation vocale (outil intégré) :** deuxième carte, même mécanique (interrupteur enregistré immédiatement, **Clé requise** sans clé OpenAI, réglage global sans distinction de groupe). Active, elle ajoute un **bouton onde sonore à côté du micro** : l'utilisateur parle, la fin de sa phrase est détectée côté fournisseur, le tour part **comme un message ordinaire** (assistant, bases de connaissances, connecteurs, quota — rien de parallèle) avec une consigne de **réponse orale** (courte, sans mise en forme), puis la réponse est **lue à voix haute** par la synthèse vocale d'OpenAI — dès sa première phrase, pendant que la suite s'écrit, sans coût supplémentaire — et MIA écoute de nouveau. **Interruption à la voix** : pendant la lecture, l'utilisateur peut dire « stop » (une réponse qui s'écrit encore s'arrête alors aussi, comme avec le bouton Stop du chat) — le micro reste alors ouvert et ces minutes s'ajoutent aux minutes écoutées ; c'est un réglage de l'utilisateur, par appareil (bouton **« Stop »** du bandeau), allumé par défaut sur ordinateur et Android, éteint par défaut sur iPhone et iPad, où un micro ouvert fait baisser la voix. Pendant la lecture, seul un ordre d'arrêt est retenu : rien de ce que MIA lit ne devient un message. **Les cartes se disent** : « approuve », « annule », « envoie-le », « confirme », « plus court »… actionnent les boutons de la carte en attente — reconnus par le navigateur de l'utilisateur, exécutés par le même code et les mêmes contrôles serveur qu'un clic, sans appel à un modèle pour décider ; un « oui » seul ne déclenche rien, un envoi d'email demande toujours une confirmation dite, et l'assistant ne reçoit aucun moyen d'approuver ou d'envoyer. Deux postes de coût, tous deux dans le quota : les **minutes écoutées** (ligne **Dictée** de Tarifs & crédits) et les **minutes lues** (ligne **Lecture à voix haute**, mesurées sur l'audio réellement produit). Pendant la préparation de la réponse, MIA **annonce ses actions** à voix haute (recherche Internet, bases de connaissances, cerveau MyBrain, connecteurs — les libellés de la trace d'actions) : chaque annonce est synthétisée **une seule fois par instance et par langue** (quelques secondes de lecture, comptées à l'utilisateur qui la déclenche la première fois) puis servie du cache de l'application ; les repères sonores (envoi, attente, écoute) sont produits par le navigateur et ne coûtent rien. Le volume se règle dans le bandeau (curseur mémorisé par appareil ; sur iPhone et iPad, boutons de l'appareil). Chaque utilisateur règle en plus, dans **Réglages → Préférences IA** (carte **Voix**), la **vitesse de lecture** (0,75× à 2×, appliquée par son navigateur : aucun coût, aucune re-synthèse) et la **longueur des réponses à l'oral** — **Brèves** par défaut, donc plus courtes qu'à l'écrit, ce qui réduit d'autant les tokens et les minutes lues ; la longueur se change aussi depuis le bandeau du chat, sans quitter la conversation. Les deux interrupteurs sont indépendants : on peut proposer la dictée sans la conversation, ou l'inverse. Inactive, la carte refuse aussi les demandes venues d'une page restée ouverte.
- **Suggestions de suite (outil intégré) :** carte voisine, même mécanique (interrupteur enregistré immédiatement, réglage global, **Actif** par défaut, aucun prérequis de clé). Active, la zone de saisie propose après chaque réponse la **suite logique** de l'échange, en grisé, que l'utilisateur reprend d'un appui sur **Tab** (au doigt : **Utiliser**) — rien n'est envoyé sans qu'il la relise et valide. Préparée **une fois par réponse** par le **modèle de la conversation**, à la demande du navigateur (seulement pour la conversation affichée, jamais pour une tâche programmée ou une étape de workflow que personne ne regarde) et gardée avec la réponse : un rechargement la relit sans nouvel appel, et rouvrir une conversation de plus de douze heures n'en prépare aucune. Quelques centaines de tokens par réponse, ligne **Suggestions de suite** de la catégorie *Assistance de conversation* (Consommation), **dans le quota** de l'utilisateur ; quota ou budget atteint, aucune suggestion n'est proposée. Inactive, plus aucune suggestion n'est demandée ni affichée.
- **Tâches programmées (outil intégré) :** troisième carte, même mécanique (interrupteur enregistré immédiatement, réglage global sans distinction de groupe), **active par défaut** et sans clé à fournir. Active, chaque utilisateur trouve la tuile **Tâches** dans la barre latérale (**Espaces → Tâches programmées**), le bouton **Refaire régulièrement** sous les réponses du chat, l'**horloge Programmer** à côté du nom de l'assistant dans la zone de saisie, et MIA sait **préparer une tâche quand on le lui demande** (« chaque lundi à 8 h, fais-moi le point… ») sous forme de carte à approuver ou à ajuster : une demande confiée à MIA ou à un assistant repart toute seule à l'heure choisie (chaque jour, jours ouvrés, certains jours de la semaine, un jour du mois), et la réponse arrive dans la conversation de la tâche, signalée par une pastille. Chaque exécution est **un message ordinaire**, envoyé par le **planificateur du worker** (vérification toutes les 30 s ; sans worker, au rythme des visites, au mieux une fois par minute) : même assistant, mêmes bases et sources, **dans le quota de l'utilisateur et le budget IA**. En plus de ceux de l'assistant, une tâche peut interroger **trois bases de connaissances** de son propriétaire (celles qu'il crée ou qui lui sont partagées) et engager **un outil par type** parmi ceux que ses groupes lui ouvrent — **image**, **automatisation n8n**, **présentation Gamma** ; bases et outils sont **revérifiés à chaque échéance** (partage retiré, outil désactivé, retiré du groupe, arrêté ou en échec à son dernier test : la tâche s'exécute sans eux). Le formulaire propose un **essai** (« Lancer un essai ») : une exécution complète, tout de suite, sans rien enregistrer, dans une conversation « Essai — *nom* », comptée comme un message. Garde-fous : **lecture seule par défaut** — une tâche réglée sur **Préparer des actions à approuver** dépose des cartes d'approbation (brouillons, évènements, tâches, messages Slack, workflows n8n, présentations Gamma) dans sa conversation, valables **une semaine** au lieu de 30 minutes, et rien ne s'exécute sans le clic de son propriétaire ; choisir un outil n8n ou Gamma exclut la lecture seule —, **cinq tâches actives** par personne, une heure par tâche ; à chaque échéance le compte, l'accès à l'assistant et le quota sont revérifiés — quota atteint : exécution **sautée** ; compte désactivé ou assistant devenu inaccessible : tâche **en pause** avec la raison ; **trois échecs d'affilée** du modèle : pause aussi. Un serveur arrêté plus de 12 heures ne déclenche aucun rattrapage en rafale : le créneau est **manqué**, la tâche reprend au suivant. Un créneau ne part jamais deux fois, même avec plusieurs workers. **Notifications sur les appareils** (Web Push) : chacun les active sur ses appareils depuis la page Tâches programmées ; aucune configuration, aucun compte tiers — la paire de clés de l'instance (VAPID) est créée au premier besoin, gardée chiffrée, et ni elle ni les appareils inscrits ne partent dans la sauvegarde (une restauration garde ceux de l'instance qui restaure). Le serveur (le worker) doit pouvoir joindre en HTTPS les services de notification des navigateurs — `fcm.googleapis.com` (Chrome, Edge sur Android, Samsung), `updates.push.services.mozilla.com` (Firefox), `web.push.apple.com` (Safari), `*.notify.windows.com` (Edge sur Windows) — et n'écrit à aucune autre adresse. Une notification ne contient jamais la réponse, seulement le nom de la tâche et ce qui s'est passé ; le message est chiffré pour l'appareil (le service de notification ne peut pas le lire). **Agir sans validation** — case **Proposer « Agir sans validation »** de la même carte, **cochée par défaut**, enregistrée au clic : chacun peut alors laisser une tâche **exécuter elle-même** les actions qu'elle prépare, par le même chemin qu'un clic « Approuver » (connexion, droits et réglages revérifiés au moment de l'action), sans carte. Garde-fous : le choix ne se fait que dans le formulaire de la tâche, par son propriétaire — jamais par une demande à MIA, jamais pendant un essai (l'essai prépare, à approuver) ; un email reste un **brouillon** tant que **Envoyer les emails** n'est pas coché ; **dix actions au plus** par exécution, les suivantes redevenant des cartes ; une automatisation n8n ou une présentation Gamma n'agit seule que si son outil a été **coché dans la tâche** — celle d'un outil apporté par l'assistant (que vous ou un créateur pouvez modifier à tout moment) dépose une carte à approuver ; chaque action est notée dans la conversation (message « Ce que la tâche a fait sans validation », trace « Exécution sans validation »), la notification les compte, la liste des tâches signale celles qui agissent seules. Ces actions restent dans la file d'approbation, marquées, et sortent des statistiques des cartes (**Diagnostic → Agent**) : personne n'a décidé. Le risque à peser : une tâche lit des contenus écrits par d'autres (emails reçus, pages web) et pourrait, malgré la consigne qui le lui interdit, suivre une instruction qui s'y cache. **Décochée**, l'option est grisée dans le formulaire avec sa raison, et les tâches déjà réglées ainsi **préparent** leurs actions (cartes à approuver) dès leur prochaine exécution — rien n'est perdu, rien ne part seul. **Inactive**, la carte suspend toutes les exécutions (les tâches restent enregistrées et reprennent à leur prochaine échéance). Les tâches et leur historique ne font pas partie de la **sauvegarde** de configuration, comme les conversations.
- **Workflows (outil intégré) :** quatrième carte, même mécanique (interrupteur enregistré immédiatement, réglage global sans distinction de groupe), **active par défaut** et sans clé à fournir. Active, les **créateurs et les administrateurs** trouvent la tuile **Workflows** dans la barre latérale : un travail en plusieurs étapes confiées à MIA ou à leurs assistants, qui se passent des **pièces** (texte, liste, tableau, fiche aux champs typés, document au modèle de l'entreprise), avec des **conditions** (une règle sur une fiche, sans IA, ou une question à MIA), des **validations** (approuver, corriger la pièce, renvoyer l'étape avec une remarque, arrêter) et des **actions** dans les outils reliés (email, agenda, tâches, Slack, Drive, n8n, Gamma) ; lancé à la main, depuis le chat (« @ ») ou à heure fixe, et MIA sait en **préparer un quand on le lui demande** (carte à approuver, créé en brouillon). Chaque étape est **un message ordinaire** exécuté par le worker, dans le **quota de la personne qui lance** et le budget IA ; seule la **version publiée** s'exécute. Garde-fous : une étape lit et rédige par défaut, ses actions attendent l'accord de la personne (cartes dans la conversation de l'exécution, valables une semaine) ; **30 workflows** par personne, **15 briques** par workflow ; une validation sans réponse arrête l'exécution au bout de son délai (7 jours par défaut, 14 au plus). Un workflow peut aussi **démarrer sur un évènement** : un email reçu (Gmail ou Outlook) ou un nouveau fichier dans un dossier Drive — vérifiés **toutes les 5 minutes** par le worker, avec la connexion de la personne qui a créé le workflow —, l'**appel de son adresse** par un autre logiciel (`POST /hooks/workflows/<jeton>` : le jeton, secret, est la seule protection — ni session ni cookie —, **60 appels par heure** au plus ; l'adresse publique de l'instance doit être joignable par le logiciel qui appelle) ou la fin d'un autre de ses workflows (**trois** enchaînés au plus). Son livrable peut **partir tout seul** : par email (pièces jointes comprises, **5 fichiers et 3 Mo** au plus), dans Google Drive, Slack ou une base de connaissances. Une panne passagère du modèle est **réessayée** jusqu'à trois fois (après 1, 5 puis 15 minutes) si l'étape le prévoit. Case **Proposer « Agir sans validation » dans les workflows**, **cochée par défaut** : décochée, tout ce qu'un workflow prépare attend la validation — livraisons du livrable comprises. Liste **Éléments traités au plus par une boucle « pour chaque »** — **50, 100, 200 (par défaut) ou 500** : une boucle répète ses briques pour chaque ligne d'un Excel, chaque pièce jointe, chaque fichier d'un dossier Drive, chaque email d'une recherche, chaque sujet repéré par MIA… ; au-delà de ce plafond, les éléments suivants sont signalés dans la conversation de l'exécution, pas traités (chaque créateur règle aussi le maximum de chaque boucle, 50 par défaut, dessous). Chaque élément est un vrai travail : la fenêtre de lancement chiffre les tours et demande confirmation au-delà de cinquante ; un élément en échec ne bloque pas les autres, trois échecs d'affilée mettent la boucle en pause. Les fichiers lus par une boucle (pièces jointes, fichiers d'un dossier) sont copiés dans le stockage des pièces jointes et suivent la rétention des conversations. **Inactive**, la carte retire l'entrée, plus rien ne démarre, et une exécution en cours se met **en pause** à sa prochaine étape (reprenable). Les workflows et leurs exécutions ne font pas partie de la **sauvegarde**, comme les tâches programmées.
- **Créer un outil :** bouton **Ajouter un outil** en haut de page, ou carte **« + Ajouter un outil »** en fin de grille — un formulaire compact s'ouvre en accordéon, en **3 étapes séquentielles** : **1. Que voulez-vous générer ?** (le type), **2. Réglez le moteur** (fournisseur, modèle et options), **3. Nommez et activez**. Choisir un type ouvre automatiquement l'étape suivante ; l'en-tête de chaque étape déjà visitée reste cliquable pour y revenir (un résumé du choix y est affiché). Réglages demandés : nom affiché, type, **Fournisseur**, modèle, options propres au type :
  - *Image* : **rien à régler à la création** — la taille se décide au moment de la génération et la qualité est **standard** par défaut. Une valeur figée ici s'appliquerait à *toutes* les images de l'outil ; si vous y tenez, la fiche de l'outil (**Modifier**) permet toujours d'imposer une taille, une qualité ou un format. ⚠ **Le modèle choisi décide de la retouche** : GPT Image, les modèles ChatGPT natifs (GPT-5.x) et Nano Banana savent repartir de l'image précédente (« mets-lui un chapeau » ne recrée pas tout) ; **DALL-E 2/3 et Imagen non** — chaque demande y produit une image entièrement nouvelle, et les pièces jointes de l'utilisateur sont ignorées comme base. Le même critère décide des **images de référence** : avec un modèle qui retouche, les images des documents de référence d'un assistant et celles des bases de connaissances de la conversation (bases de l'assistant, bases cochées par l'utilisateur, dans la limite de ses droits) servent de modèles — l'assistant, ou l'outil **Image** du menu **+**, en choisit jusqu'à 4 par image, et le générateur les reprend fidèlement ; la ligne « D'après … » sous l'image les nomme. Chaque image transmise est réduite à 2 048 px (12 Mo au plus pour l'ensemble) et, chez OpenAI, facturée en jetons d'entrée — comptés dans le coût réel de l'image. Rien à configurer ; les sites illustrés et les présentations ne s'en servent pas encore ;
  - *Vidéo* : durée, résolution, format de sortie ;
  - *Page HTML* : **system prompt** et créativité (température). Le champ arrive **pré-rempli** avec les instructions livrées pour ce type d'outil : vous voyez ce que l'outil demande réellement au modèle, et vous pouvez le réécrire. **Vider le champ remet le texte par défaut.** Le *format de sortie* (page HTML seule, spécification JSON d'une carte ou d'une présentation) est ajouté automatiquement et n'est pas éditable — c'est lui qui fait fonctionner l'affichage.
  - *Carte / Itinéraire* (pastille **En bêta test**, sur la carte de choix du type et sur celle de l'outil créé : l'outil fonctionne, mais son comportement peut encore évoluer et présenter des imperfections) : comme *Page HTML* — un **LLM du catalogue** rédige l'itinéraire (choisir fournisseur + modèle ; system prompt pré-rempli et température en option). Le texte livré couvre le réalisme des temps de marche, les coordonnées des lieux sans adresse et la cohérence entre la carte et le commentaire. **Aucune clé cartographique à configurer** : la carte (OpenStreetMap), le géocodage des étapes (Nominatim) et le tracé par la route (OSRM) reposent sur des services publics gratuits. L'utilisateur peut ensuite affiner le trajet au fil de la conversation, exporter la carte **et ses notes en PDF** ou l'ouvrir dans **Google Maps**.
  - *Site illustré* : comme *Page HTML*, plus le choix d'un **outil image existant** (source des illustrations), le **nombre d'images max par page** (1–6, défaut 3) et un **style graphique par défaut** (Photoréaliste, Illustration, Minimaliste, **3D / rendu**, ou *Personnalisé…* avec votre propre description). L'IA décrit des emplacements d'image ; MIA les remplit via l'outil image choisi, dans le style retenu. Ce style est **appliqué par défaut** : au lancement l'utilisateur le voit affiché en clair et génère directement, sans rien régler. Il peut toutefois le changer ponctuellement via **« changer »** (presets, *Libre*, ou *Aucun* pour laisser l'IA décider), ainsi que choisir un autre générateur d'images. ⚠ Créez **d'abord** un outil *Image*, puis sélectionnez-le ici.
  - *Présentation* : **n'est plus proposé** (type grisé à la création). Une présentation se demande dans la conversation : sa carte la télécharge (au modèle PowerPoint de l'entreprise s'il y en a un), la retouche dans l'éditeur (**Modifier les slides** — déplacer, réécrire, illustrer avec les outils image ouverts à la personne, exporter), la fait mettre en page par un modèle ou par Gamma (**Version soignée**). Un outil Présentation déjà créé n'apparaît plus dans le menu **+** — sa carte le signale — et peut être supprimé ; les présentations qu'il a produites restent ouvrables et exportables depuis leurs conversations.
    - **Exports** : l'utilisateur peut exporter en **PowerPoint (.pptx)**, en **PDF paysage** ou en page HTML autonome. Les trois partent de la présentation enregistrée (positions éditées comprises) et ne demandent aucune configuration.
  - *Automatisation n8n* : voir la section dédiée ci-dessous — ni fournisseur ni modèle (aucun LLM n'intervient), mais l'adresse de votre instance n8n, une clé API et un tag.
  - *Présentation Gamma* : voir la section dédiée ci-dessous — ni fournisseur ni modèle de MIA (la génération est faite par gamma.app), mais une clé API Gamma et quelques réglages (thème, plafond de slides, images, export).
  - + une clé d'accès si besoin, le statut et l'ordre d'affichage.
- **Liste en cartes :** tous les outils — dictée vocale comprise — apparaissent en **cartes** (même présentation que la page Connecteurs), avec une **couleur par type** (liseré et icône) : type, moteur, réglages, résultat du dernier test, et un interrupteur enregistré **immédiatement** — pas de formulaire à valider, et la bascule ne touche ni la configuration ni le dernier test. L'état est affiché **en toutes lettres** à côté de l'interrupteur : **Actif**, suivi du nombre de groupes actifs qui autorisent l'outil (« 2 groupes », ou « Aucun groupe — admins seuls »), ou **Inactif — masqué du chat** (conservé ici, invisible de tous). **Actif** ne suffit pas à proposer l'outil : il faut aussi le cocher dans **Personnes → Groupes → Droits d'accès → Outils**. Un outil neuf n'est autorisé dans aucun groupe — le message de création le rappelle, et sa carte porte l'avertissement « Autorisé dans aucun groupe : seuls les administrateurs le voient. » tant que c'est le cas.
- **Expressions déclencheuses :** chaque carte porte, comme les connecteurs, les **expressions** qui font suggérer l'outil pendant la frappe d'un message (bannière au-dessus de la saisie). Une expression par ligne ; ses mots doivent apparaître **dans l'ordre**, d'autres mots peuvent s'intercaler dans la même phrase (« génère une image » reconnaît « génère-moi vite une image de chat »). La liste est **commune à tous les outils du même type**, modifiable et réinitialisable carte par carte ; vide = aucune suggestion. Les automatisations n8n et les présentations Gamma n'en ont pas : c'est l'**assistant** qui les déclenche, avec approbation.
- **Tester :** bouton **Tester** sur la carte d'un outil, ou **Tester tous les outils**. Un outil dont le test échoue apparaît **grisé** côté utilisateur.
- **Modèle arrêté par son fournisseur :** MIA connaît les arrêts annoncés par les fournisseurs (un modèle, ou tout un service). Avant la date, la carte de l'outil affiche « Arrêt annoncé le … » avec un lien vers l'annonce, et le **Diagnostic** le signale. À partir de la date, badge **Hors service** : l'outil reste dans le menu **+** des utilisateurs mais **grisé**, avec la mention « Arrêté par son fournisseur le … », plus aucune demande ne part chez le fournisseur, et les assistants, sites illustrés et présentations cessent de s'en servir. Le modèle n'est plus proposé à la création (un type sans aucun modèle en service y est grisé). Remède : **Modifier** l'outil et lui choisir un autre modèle — il repart aussitôt —, sinon le passer en **Inactif**. Rien n'est écrit en base : l'arrêt se lit à la date.
- **Clé manquante :** un outil dont le fournisseur n'a pas de clé API affiche sur sa carte un badge **Clé requise** à la place de l'interrupteur, et son bouton **Tester** est **désactivé**. Un lien vous renvoie vers **Modèles IA** pour saisir la clé attendue (ou vers **Modifier** pour n8n et Gamma, dont la clé vit sur l'outil).

### Automatisation n8n

Cet outil permet à un assistant de **déclencher vos automatisations n8n** pendant une conversation : créer un
ticket, alimenter un outil métier, relever un statut… L'utilisateur formule sa demande en français, l'assistant
choisit le bon workflow, remplit les champs et **lui soumet l'exécution pour approbation**.

**Ce n'est pas un connecteur.** Les connecteurs (Google Drive, Gmail, Agenda, Tasks — section 11) branchent les données
**personnelles** de l'utilisateur, qui relie son compte et décide message par message. Une automatisation n8n est
au contraire une **capacité que vous confiez à un assistant** : elle se configure ici, se lie à des assistants
précis, et l'utilisateur n'a rien à activer — parler à l'assistant suffit.

**Étape 1 — préparer n8n** (le formulaire rappelle ces trois points) :

1. **Créer une clé API** : dans n8n, *menu utilisateur → Settings → n8n API → Create an API key*. Elle ne sert
   qu'à **lister et décrire** les workflows, jamais à les exécuter. Si l'API publique est désactivée sur votre
   instance (`N8N_PUBLIC_API_DISABLED`), réactivez-la : sans elle, aucun workflow n'est découvert.
2. **Ajouter un déclencheur Webhook** au workflow — seul ce nœud est appelable (un déclencheur planifié ou manuel
   ne l'est pas). Method `POST` (les données arrivent dans `{{ $json.body }}`) et Respond *When Last Node
   Finishes*, sinon n8n renvoie un simple accusé et l'assistant n'a aucun résultat à restituer.
   **Les Notes du nœud Webhook sont la notice de l'outil** : le seul texte que l'assistant lit pour savoir quand
   l'utiliser et quels champs lui passer. Exemple : `Crée un ticket au support. Champs : sujet (obligatoire),
   description, priorite (haute|normale|basse).`
3. **Taguer et activer** le workflow (tag `mia` par défaut). **Ce tag est l'unique liste blanche** : un workflow
   sans ce tag, ou inactif, est totalement invisible de l'assistant. Retirer le tag coupe l'accès immédiatement,
   sans repasser par MIA.

**Étape 2 — créer l'outil** : **Ajouter un outil → Automatisation n8n**, puis l'**URL de l'instance** (adresse
racine ; un copier-coller de la barre d'adresse est nettoyé automatiquement — le serveur MIA doit pouvoir la
joindre), la **clé API** (stockée chiffrée, jamais réaffichée) et le **tag d'exposition**.

**Étape 3 — sécuriser les appels** (optionnel mais recommandé) : un webhook n8n est un point d'entrée **public**.
Protégez-le côté n8n (nœud Webhook → *Authentication → Header Auth*) et recopiez le **nom** et la **valeur** du
même en-tête dans l'outil. Cet en-tête est envoyé à tous les workflows exposés ; la clé API n'est **jamais**
envoyée à un webhook.

**Étape 4 — approbation** : par défaut, **chaque exécution** s'affiche en carte d'approbation dans la
conversation (workflow visé, données transmises, rappel d'irréversibilité) et n'est lancée qu'au clic
**Approuver** de l'utilisateur. Une case permet d'exécuter **sans approbation** les workflows portant *en plus*
le tag de lecture (`mia-lecture`) — à réserver aux workflows **strictement en lecture**.

**Étape 5 — lier l'outil à des assistants** : **Assistants → Gérer → Modifier**, section *Comportement*,
**Capacités — automatisations n8n**. Sans liaison, l'outil existe mais aucun assistant ne le déclenche. La
liaison n'ouvre rien de plus que les droits de l'utilisateur : le périmètre de groupe s'applique comme pour tout
outil, et l'assistant doit tourner sur un **modèle capable de piloter des outils** (*function calling*).

**Tester** : le bouton **Tester** de la liste vérifie la connexion, compte les workflows réellement exposés
(dont ceux qui, faute de webhook, ne seront pas lançables) et contrôle la cohérence de leur protection —
**sans déclencher aucun workflow**, ce qui produirait leurs effets réels. Il signale notamment : workflows en
*Header Auth* alors qu'aucun en-tête n'est configuré (403 à chaque exécution), workflows en *Basic Auth* ou
*JWT* (que MIA ne sait pas fournir), webhooks laissés ouverts, en-tête configuré que personne ne lit. La
**valeur** du secret, elle, vit dans un credential n8n que l'API n'expose pas : elle reste invérifiable à
distance.

**Diagnostic** : *« Aucun workflow n'est exposé »* → workflow inactif ou sans le tag. *« n'a pas de déclencheur
Webhook actif »* → ajoutez un nœud Webhook (non désactivé). *Webhook introuvable (404)* à l'exécution → le
workflow n'est pas activé dans n8n ; tant qu'il ne l'est pas, seule son URL de **test** répond.

### Présentation Gamma (gamma.app)

Cet outil permet à un assistant de **générer une présentation via gamma.app** pendant une conversation :
l'utilisateur décrit ce qu'il veut, l'assistant prépare la demande (sujet, nombre de slides, consignes) et **lui
soumet la génération pour approbation**. Comme n8n, c'est une **capacité confiée à un assistant** (pas un
connecteur) : elle se configure ici, se lie à des assistants précis, et l'utilisateur n'a rien à activer.

**Prérequis côté gamma.app** :

1. **Un plan payant** — l'accès API demande un plan **Pro, Ultra, Teams ou Business**. Chaque génération consomme
   des **crédits Gamma** du compte, indépendants des crédits MIA (elle n'entame pas le quota MIA de
   l'utilisateur).
2. **Une clé API** — *gamma.app → paramètres du compte → API keys* (format `sk-gamma-…`). Stockée chiffrée,
   jamais réaffichée.

**Créer l'outil** : **Ajouter un outil → Présentation Gamma**, puis la **clé API** et les réglages :
**thème** (identifiant d'un thème du workspace ; vide = thème par défaut du compte), **plafond de slides**
(1 à 75 selon le plan — l'assistant ne peut pas le dépasser, quoi que demande l'utilisateur), **images des
slides** (générées par IA, pictogrammes, photos libres ou sans image), **export automatique** (aucun, PPTX ou
PDF — le fichier est produit par Gamma à chaque génération, lien valable environ une semaine) et des
**consignes par défaut** (ton, charte, marque… ajoutées devant les instructions de l'assistant à chaque
génération).

**Approbation systématique** : chaque génération étant **payante**, elle passe **toujours** par une carte
d'approbation dans la conversation (sujet, nombre de slides, rappel des crédits). Rien ne part sans le clic
**Approuver**. Après approbation, la génération dure **1 à 3 minutes** ; elle est suivie en tâche de fond
(visible dans le tiroir « générations en cours ») et le **lien vers la présentation** (plus le lien d'export
éventuel) est ajouté à la conversation dès qu'elle est prête.

**Lier l'outil à des assistants** : **Assistants → Gérer → Modifier**, section *Comportement*, **Capacités —
présentations Gamma**. Sans liaison, aucun assistant ne le propose. Le périmètre de groupe s'applique comme pour
tout outil, et l'assistant doit tourner sur un **modèle capable de piloter des outils** (*function calling*).

**Tester** : le bouton **Tester** de la liste valide la clé en **listant les thèmes du workspace** — sans
consommer un seul crédit — et signale un thème configuré introuvable. Un test en échec (clé refusée, plan sans
accès API) **retire l'outil des assistants** jusqu'à correction.

**« Version soignée › Par Gamma » des présentations du chat** : l'outil sert aussi, **sans assistant**, les
présentations PowerPoint que MIA livre dans une réponse. Pour les utilisateurs des groupes qui autorisent l'outil
(**Droits d'accès → Outils**), le bouton **Version soignée** de la carte propose **Par Gamma** : après une
confirmation dans la page (service externe, crédits Gamma), la présentation part chez gamma.app **avec son texte
tel qu'écrit** — Gamma la met en page, une carte par slide, sans la réécrire ; les notes du présentateur ne
partent pas. Le **thème**, les **images des slides** et les **consignes par défaut** s'appliquent ; le
**plafond de slides** aussi (une présentation plus longue est **refusée**, jamais coupée) ; sans **export
automatique** choisi, un **PowerPoint** est demandé. Pas de carte d'approbation : le clic, confirmé, est le geste
de l'utilisateur. Si l'assistant de la conversation est lié à un outil Gamma, c'est le sien qui sert ; sinon le
premier ouvert par les groupes. Un outil sans clé ou en échec à son dernier test ne fait pas apparaître le
bouton. Même suivi qu'après une approbation (message ⏳, tiroir des générations, lien et export dans la
conversation), et deux clics sur la même présentation ne lancent qu'une génération.

---

## 8. Coûts : budget, tarifs & crédits

La rubrique **Coûts** réunit les trois écrans de la dépense : **Budget** (combien l'IA peut coûter
chaque mois), **Consommation** (qui a consommé quoi, section 9) et **Tarifs & crédits** (combien coûte
chaque opération). Deux leviers se complètent : le **budget**, en euros, protège l'organisation ;
les **quotas**, en crédits, répartissent entre les personnes (sections 3 et 4).

### Budget IA mensuel

**Administration → Coûts → Budget**

Le budget plafonne ce que l'IA coûte **réellement** à l'organisation chaque mois civil : ce que
facturent les fournisseurs, hors marge, estimé d'après les tarifs de *Tarifs & crédits*. Tout
compte : les conversations et créations des utilisateurs **et** la maintenance (alimentation des
bases de connaissances, tests, recherche des prix), affichées en deux parts. Le compteur repart à
zéro le 1er de chaque mois (00 h 00, temps universel).

**En tête de page** : une **jauge** (part du budget dépensée, seuil d'alerte en repère) et trois
chiffres : le **reste** et les jours restants, le **rythme** par jour comparé au rythme **tenable**
(ce qu'on peut encore dépenser chaque jour pour finir sous le plafond), la **fin de mois** estimée
et la date d'atteinte du plafond. La pastille **Rythme trop rapide** s'affiche dès que l'estimation
dépasse le plafond, même sous le seuil d'alerte. Dessous, la **Trajectoire du mois** : dépense
cumulée jour par jour, prolongée en pointillés au rythme actuel, avec plafond, seuil et mois
précédent (survoler un jour donne sa dépense).

**Dépenses** — ce que l'IA a coûté sur la **période de votre choix**. Choisissez une tranche :
**Mois en cours** (par défaut), **Mois dernier**, **7 jours**, **30 jours**, **12 mois**, **Cette
année** ou **Tout** (depuis la première opération) ; ou **Personnalisée** : deux dates, bornes
comprises, jusqu'à aujourd'hui. Ce sont les mêmes tranches que sur la page **Consommation**, et la
section change sans recharger la page (le bouton Retour du navigateur revient à la période d'avant ;
l'adresse de la page garde la période, pour la partager ou la mettre en favori). Sous les tranches,
une ligne dit les dates affichées, leur nombre de jours et la période de **comparaison** : les mêmes
jours du mois précédent pour le mois en cours, le mois d'avant pour le mois dernier, le même moment
de l'année précédente pour l'année, sinon la période de même durée juste avant.
- Quatre chiffres : **Dépensé** (et la part du budget correspondant à ces jours), **Par jour** en
  moyenne, **Évolution** face à la période de comparaison (pas de pourcentage sous 1 € de dépense
  avant), **Plus forte dépense**.
- L'histogramme compte **par jour** jusqu'à deux mois, **par semaine** (du lundi au dimanche)
  jusqu'à six mois, **par mois** au-delà. La ligne rouge est la **part du budget** de chaque barre
  (budget du mois ÷ ses jours, pour chacun de ses jours : une semaine à cheval sur deux mois ou un
  mois entamé ont leur juste part) ; une barre orange a dépensé plus que sa part ; les pointillés
  donnent la moyenne. Aucun historique des budgets n'est gardé : sur une période passée, la part est
  calculée avec le montant **d'aujourd'hui** (l'écran le précise).
- **Où part l'argent** : part des comptes et de la maintenance, catégories d'opérations les plus
  coûteuses, opérations sans tarif de la période ; le lien **Détail de la période dans
  Consommation** ouvre Consommation sur la même période.
- **Par fournisseur** : ce que chaque fournisseur d'IA facturera pour la période (OpenAI, Anthropic,
  Gemini…, outils compris), estimé d'après les tarifs.

**Règles** (résumé, puis **Modifier les règles** ouvre le formulaire ; bouton **Enregistrer**, pas
d'enregistrement automatique) :
- **Budget mensuel (€)** : vide = aucun budget ; **0** = service suspendu.
- **Alerte à (%)** : 80 par défaut. Au-delà, le tableau de bord, le menu (pastille ambre sur
  *Coûts*) et le Diagnostic le signalent.
- **Quand le budget est atteint** :
  - **Suspendre les nouvelles demandes jusqu'au 1er du mois suivant** (recommandé) : plus aucune
    conversation, régénération, image, vidéo, page web, carte, présentation, dictée ni rédaction
    assistée, **pour tout le monde, administrateurs compris**, et la **synchronisation automatique
    des bases** se met en pause. Restent possibles, et comptés : dépôt de documents, **Synchroniser
    maintenant**, réindexations, tests d'outils et de modèles, recherche des prix. Les utilisateurs
    voient la fenêtre **Service IA suspendu** (sans aucun montant) et la même mention sous leur nom ;
  - **Laisser passer, seulement signaler** : rien n'est bloqué. Utile le premier mois, pour
    calibrer le montant.
- Enregistrer un budget **déjà dépassé** en mode suspendre demande confirmation : la suspension est
  immédiate.

**Rallonge du mois** (bouton **Accorder une rallonge** de la carte Règles). Pour débloquer le mois en cours sans toucher au budget des mois suivants,
**Accorder** une rallonge : elle s'ajoute au plafond de ce mois seulement et **expire d'elle-même
le 1er**. Un nouveau montant remplace le précédent ; **Retirer la rallonge** l'annule. Retirer le
budget retire aussi la rallonge.

La **projection de fin de mois** apparaît à partir du 3 ; quand le service est suspendu, elle n'est
plus prolongée (la dépense s'arrête au plafond). Un avertissement signale les **opérations sans tarif** : leur coût réel est compté 0 €, le budget les
sous-estime — renseignez leur tarif.

**Répartir entre les comptes** — combien de crédits donner à chacun :
- **1 000 crédits ≈ X € de coût réel** et **1 € de coût réel ≈ N crédits** : coefficient global ÷ valeur du crédit (approximatif : les
  frais fixes, l'arrondi et le minimum par opération s'y ajoutent) ;
- **Repère** : budget converti en crédits, divisé par le nombre de **comptes actifs**. Si chacun
  consomme tout son quota, les comptes dépensent exactement le budget (la maintenance s'y ajoute).
  Le même repère s'affiche sous le champ **Quota** des fenêtres d'un utilisateur et d'un groupe ;
- **Quotas en place** : consommation maximale possible si chacun épuisait son quota, comparée au
  budget, ou **non bornée** dès qu'un compte n'a aucun quota ;
- **Mois dernier** : consommation moyenne et maximale par compte actif, coût réel du mois.
Le repère n'est qu'une indication : rien ne s'applique tout seul (le quota le plus généreux des
groupes l'emporte, une application automatique surprendrait).

**Ce que le budget ne voit pas** : opérations sans tarif, modèles locaux (gratuits), crédits Gamma
et workflows n8n (facturés par ces services), tests de clés de Modèles IA. Un **léger
dépassement** reste possible : le contrôle a lieu avant chaque opération, une réponse en cours ou
une génération déjà en file peut finir au-delà du plafond.

### Tarifs & crédits

**Administration → Coûts → Tarifs & crédits**

Sous les deux réglages principaux, une ligne indique en direct **1 € de coût réel ≈ N crédits**,
le pont avec le budget.

Le **crédit** est l'unité de facturation interne ; c'est ici que se définit la conversion entre le coût réel des services IA et les crédits consommés par les utilisateurs.

### Comment une opération est comptée

Toute consommation d'un fournisseur IA passe par un **compteur unique** et atterrit dans un **grand livre** (la page **Consommation** en est la lecture). Une *opération* est un appel facturable : une réponse de chat, une régénération, un titre de conversation généré, un embedding de question, une recherche Internet exécutée par le fournisseur, une image, une vidéo, une minute de dictée, la vectorisation d'un document… Pour chacune :

1. chaque composante (tokens en entrée, en sortie, lus depuis le cache, de raisonnement ; ou une quantité d'unités — images, secondes, recherches, minutes) trouve son **tarif** : le service renseigné dans ce tableau, sinon le **tarif curé du catalogue** de MIA (prix officiel connu du modèle, converti en euros au taux configuré ; un **tarif de lancement** annoncé par l'éditeur s'y applique jusqu'à sa date de fin, puis le tarif normal prend le relais seul) — outils **image et vidéo** compris : à l'image, à la seconde selon la taille de la vidéo, ou, pour une image dont le prix dépend de sa taille et de sa qualité, d'après la consommation que l'éditeur renvoie pour chaque image. Une ligne d'outil laissée à 0 n'est donc plus « non tarifée » quand le modèle a un tarif public ;
2. les composantes sont additionnées : c'est le **coût réel IA** (hors marge), mémorisé tel quel ;
3. le **coefficient global** (marge), les **frais fixes**, l'**arrondi** et le **minimum** s'appliquent **une fois par opération** (et non par composante) : c'est le nombre de **crédits** décompté. Une réponse appuyée sur Internet s'inscrit sur deux lignes du grand livre (la réponse, ses recherches) mais reste UNE opération : la ligne des recherches reçoit les crédits de l'opération entière moins ceux de la réponse — elles ajoutaient auparavant un arrondi et un minimum de plus.

**Ce qui est compté en plus des jetons ordinaires** :

- **Palier « contexte long »** : OpenAI (GPT-6, GPT-5.6 Sol, GPT-5.5, GPT-5.4, au-delà de 272 000 jetons d'entrée) et Gemini Pro (3.1 Pro, 2.5 Pro, au-delà de 200 000) facturent toute la requête plus cher — entrée (cache compris) ×2, sortie ×1,5. MIA applique ce palier requête par requête : dans une réponse qui enchaîne des actions, chaque étape est jugée à part, comme l'éditeur le fait.
- **Pages lues pendant une recherche Internet** : Claude et Mistral comptent les résultats d'une recherche comme de l'entrée ; ils sont relevés (ils échappaient au décompte, Claude ne rapportant ce total qu'en fin de réponse et Mistral à part).
- **Recherches de Mistral** : comptées à l'unité ; Mistral ne publie pas leur prix, elles apparaissent donc comme **non tarifées** tant que la ligne de recherche web de Mistral n'a pas de coût saisi.
- **Session de code de GPT** : la « Version soignée » d'une présentation ou d'un classeur par un modèle GPT ouvre un conteneur d'exécution facturé par OpenAI à la session (0,03 $), en plus des jetons.
- **Réponse arrêtée** (bouton Stop) : l'éditeur facture ce qu'il a déjà lu et écrit, mais ne le rapporte qu'en fin de réponse ; MIA l'estime d'après la conversation envoyée et le texte déjà affiché (auparavant : rien n'était décompté).
- **Kimi** : l'usage de ses réponses n'était pas toujours relevé ; il l'est désormais.
- **Non compté, à dessein** : les franchises mensuelles gratuites des éditeurs (recherches Google des 5 000 premières requêtes, heures de code d'Anthropic), propres à chaque compte fournisseur — MIA compte tout au tarif, jamais moins que ce qui est dû.

**Le tableau montre le tarif réellement appliqué.** Une ligne laissée vide (ou à 0) affiche en grisé le tarif public que la facturation lui applique, avec « **Tarif public appliqué** » (« **Dérivé du prix de référence** » pour une ligne de cache ou de raisonnement d'un modèle sans tarif public, calculée depuis le prix d'entrée ou de sortie saisi) ; les **Crédits finaux**, le **simulateur**, la vue **Tarif par modèle** et le rapport PDF calculent avec ce tarif. Avant, une telle ligne affichait « 0 € → 1 crédit » pendant que chaque message était compté au tarif public. **Tous les modèles du catalogue ont désormais un tarif** : o3, GPT-4o, GPT-4o mini, GPT-4.1, GPT-4.1 mini, GPT-5 mini, GPT-5.2, GPT-5.3 Codex, GPT-5.4 et GPT-5.5 Pro n'en avaient pas — chaque réponse y était comptée au minimum (1 crédit) ; ils sont comptés au tarif standard d'OpenAI (relu le 17/10/2026).

**Les coûts annoncés aux utilisateurs suivent ces réglages.** Le repère **€** du sélecteur de modèle et des outils du menu **+**, leur infobulle (« ≈ 35 crédits par réponse, ≈ 13 par recherche Internet », « ≈ 40 crédits par génération »), le bandeau du modèle et le coût estimé d'un workflow sont calculés avec les mêmes tarifs, le même coefficient et le même arrondi que le décompte, appliqués à la consommation réelle des 30 derniers jours (taille moyenne des réponses du modèle, crédits moyens des générations de l'outil ; à défaut, une réponse type). Sous une réponse, l'étiquette compte tout le tour — titre automatique, mémorisation, recherche dans les bases, résumé d'une longue conversation compris —, la même en direct et au rechargement.

Une opération dont une composante n'a **aucun tarif trouvable** (ni service renseigné, ni tarif au catalogue) est dite **non tarifée** : elle est créditée au minimum configuré, son coût réel est compté à 0 € et la page **Consommation** la signale — renseignez le prix ici pour que le grand livre redevienne exact. Les fournisseurs locaux (Ollama, LM Studio) sont gratuits par construction.

Certaines opérations sont **hors quota** : journalisées avec leur coût, imputées à qui les a déclenchées, mais sans entamer son quota mensuel — l'alimentation d'une base de connaissances (découpage sémantique, description d'images, vectorisation, imputée au créateur de la base), les tests d'outils et la recherche des prix (imputés à l'administrateur). Un créateur qui vectorise vingt documents n'est pas privé de chat pour le mois.

### Paramètres généraux

Deux réglages sont visibles directement :
- **Valeur d'un crédit (€)** ;
- **Coefficient global** — le multiplicateur appliqué à tous les services (c'est lui qui porte votre marge).

Les autres réglages sont repliés sous **Paramètres avancés** (cliquez pour les déplier) :
- **Frais fixes par opération (€)** ;
- **Mode d'arrondi** (*Entier supérieur*, *Entier le plus proche*, *Entier inférieur*) ;
- **Minimum crédits / opération** ;
- **Taux 1 EUR → USD** ;
- **Devise affichée** (*EUR* ou *USD*).

Le bouton **Mettre à jour coûts Internet (USD)** récupère les tarifs publics les plus récents. La recherche interroge des dizaines de services et peut durer plusieurs minutes : elle **tourne côté serveur**, pas dans votre navigateur.

- Un bandeau **Recherche des coûts sur Internet…** s'affiche pendant l'opération, sans bloquer la page.
- Vous pouvez **changer d'écran, recharger ou fermer l'onglet** : la recherche continue et les prix trouvés sont enregistrés tout seuls. En revenant sur *Tarifs & crédits*, le bandeau se raccroche à la recherche en cours (y compris si elle a été lancée depuis un autre onglet ou un autre appareil).
- Le bouton **Arrêter** du bandeau interrompt la recherche.
- Quand elle aboutit, la page se recharge et affiche le nombre de services mis à jour ; la devise affichée bascule sur **USD** et chaque service concerné reçoit une ligne `Maj Internet <date> (USD) — source` dans son **commentaire interne** (elle remplace la précédente, elle ne s'empile pas).
- Un clic supplémentaire pendant qu'une recherche tourne **rejoint celle en cours** au lieu d'en lancer une seconde.
- Un **tarif de lancement** recopié par la mise à jour reste dans le tableau après sa date de fin : relancez la mise à jour une fois cette date passée (une ligne restée à 0, elle, suit le catalogue d'elle-même).

Une pastille indique en permanence l'état de la configuration (**Tout est enregistré**, ou **Enregistrement en cours…** juste après une modification).

### Les trois vues

Une barre de boutons bascule entre trois affichages :
- **Vue services** *(affichage par défaut)* — le tableau **Services IA**, avec les colonnes **Service**, **Unité**, **Coût réel (EUR)**, **Coeff. spécifique**, **Crédits finaux**, **Commentaire interne** et **Actions**. Configurez ici le coût réel et le coefficient de chaque service ; **cliquez sur une ligne** pour afficher son détail de calcul. Les colonnes Service, Coût réel et Crédits finaux sont triables. Outre les modèles de chat (entrée, sortie, cache lu, cache écrit, raisonnement) et les outils, le tableau liste les **embeddings** (par modèle, en tokens — vectorisation des bases et embedding de chaque question), la **recherche web** (par fournisseur, prix d'une recherche exécutée pendant une réponse) la **dictée** (par modèle, à la minute d'audio) et la **lecture à voix haute** (conversation vocale, à la minute d'audio produit). Une ligne laissée à 0 n'est pas « gratuite » : MIA applique alors le tarif curé de son catalogue, et **Mettre à jour coûts Internet (USD)** la remplit. Les lignes de **cache** servent surtout aux réponses qui enchaînent des actions : entre deux étapes d'une même réponse (connecteurs, outils), MIA demande à l'éditeur de garder le début de la conversation en cache ; Claude et les modèles OpenAI le relisent ensuite à une fraction du prix de l'entrée (la première écriture coûte un peu plus que l'entrée chez Claude).
- **Tarif par modèle** — une synthèse en lecture, regroupée par fournisseur : **Modèle**, **Coût €/5** (indicateur de cherté), **Coût input (€/1M tok.)**, **Crédits/tok. input**, **Coût output (€/1M tok.)** et **Crédits/tok. output**. ⚠ Cette vue ne montre que l'**entrée** et la **sortie** : les autres lignes de coût d'un modèle — **cache en lecture**, **cache en écriture** et **raisonnement**, soit deux directions de cache et non une — n'apparaissent que dans la **Vue services**, où elles se règlent.
- **Tarif par outil** — la même lecture pour les outils de génération (image, vidéo…).

### Simuler, exporter, revenir en arrière

- **Ouvrir simulateur** — estimer le coût d'un usage avant de valider un réglage.
- **Ouvrir synthèse** — une vue d'ensemble de la configuration.
- **Exporter la configuration** et **Exporter PDF (avec simulations)**.
- La configuration s'[enregistre automatiquement](#enregistrement-automatique) : les boutons **Annuler les modifications**, **Réinitialiser** et **Enregistrer** ont disparu. Les deux premiers ne servaient qu'à revenir à la dernière version enregistrée — qui est désormais toujours celle affichée. Pour revenir en arrière sur un réglage, corrigez la valeur, ou repartez d'un fichier **Exporter la configuration** conservé au préalable.
- Une configuration **invalide n'est jamais enregistrée** : le message d'erreur s'affiche sous les boutons et la pastille passe en ambre. Corrigez la valeur en cause et l'enregistrement repart.

---

## 9. Consommation & journaux

**Administration → Coûts → Consommation**

Lecture du **grand livre des opérations IA** (cf. [Comment une opération est comptée](#comment-une-opération-est-comptée)) : tout ce que la plateforme a consommé chez les fournisseurs, sous quatre angles.

Deux grandeurs en euros, toujours distinguées :
- **Coût réel IA** : ce que les fournisseurs ont facturé (tarifs du catalogue ou de *Tarifs & crédits*, hors marge), additionné opération par opération au moment où elle a eu lieu ;
- **Valorisation** : crédits consommés × **valeur actuelle du crédit** — si vous changez cette valeur, les valorisations historiques sont recalculées avec la nouvelle.

- **Filtres** (appliqués à toute la page et aux exports) : période par tranche — **Mois en cours**, **Mois dernier**, **7 jours**, **30 jours**, **12 mois** (défaut), **Cette année**, **Tout** — ou **Personnalisée** (deux dates, bornes comprises), les mêmes que la section *Dépenses* du Budget ; la ligne sous les tranches dit les dates affichées et la période de comparaison des évolutions. Puis **filtre par utilisateur** (**Filtrer**).
- **Indicateurs de période** (sans euros : les montants sont sur la page **Budget**) : crédits consommés (avec la part **hors quota**), **opérations** (chaque appel facturable, auxiliaires compris) et jetons, **personnes actives** sur le nombre de comptes actifs, **temps de réponse** médian (et celui sous lequel arrivent 9 réponses sur 10), **réponses relancées** (part des réponses régénérées) et **réponses en erreur**. Un bandeau ambre signale les **opérations non tarifées** (consommation réelle sans prix trouvable) et nomme les services à renseigner dans *Tarifs & crédits*.
- **Cinq vues** :
  - **Vue d'ensemble** (par défaut) : ce que MIA fait, sans euros. **Répartition de l'usage** — une carte proportionnelle des crédits par catégorie (une tuile ouvre *Par catégorie* ; sur téléphone, une liste) ; **Adoption des fonctionnalités** — pour chacune (conversations, assistants, recherche Internet, bases de connaissances, connecteurs, images, pages web, présentations, cartes, vidéos, dictée et voix, mémoire, automatisations n8n, tâches programmées) le nombre de personnes qui s'en servent, ses usages et leur évolution par rapport à la période précédente de même durée, puis celles qui n'ont pas servi ; **Modèles** — part des réponses, temps de réponse, réponses relancées ; **Assistants** — conversations, personnes, réponses relancées, et assistants actifs sans aucune conversation ; **Bases de connaissances** — réponses nourries depuis leur création et bases qui n'ont rien nourri depuis 30 jours. Un usage est une opération IA, une action menée pendant une réponse (consultation d'une base, lecture d'un email…) ou une exécution de tâche programmée.
  - **Vue mensuelle** : graphique en barres empilées par **famille** (Conversations / Créations par outil / Bases de connaissances / Conception & admin) + tableau mensuel (crédits par famille, total, coût réel, valorisation, opérations, utilisateurs actifs, évolution % vs mois précédent).
  - **Par utilisateur** : cumul de la période par utilisateur (crédits, **hors quota**, coût réel, valorisation, % du total, opérations) ; **cliquer sur une ligne** déplie le détail mensuel de l'utilisateur avec le **cumul progressif**. Les usages sans utilisateur (comptes supprimés, tâches de l'instance comme la base système) apparaissent sous « Instance / utilisateurs supprimés ».
  - **Par catégorie** : une carte par nature de coût — *Conversations*, *Assistance de conversation* (titres automatiques, mémoire, règles de tâches, préparation d'images, amélioration de brouillons d'email depuis leur carte, embedding des questions, **résumés de longues conversations**, **condensation de résultats d'outils démesurés** et **suggestions de suite**), *Recherche Internet*, *Créations par outil*, *Voix* (dictée et lecture à voix haute), *Bases de connaissances* (hors quota), *Conception d'assistants*, *Administration* (hors quota : tests d'outils, recherche des prix, banc d'essai de l'agent quand MyAlfred le lance sur votre instance) — avec le détail de chaque source, son volume (tokens, recherches, minutes) et ses éventuelles opérations non tarifées ; puis le **coût par base de connaissances**.
  - **Répartition** : camemberts par fournisseur, assistant et outil (en crédits), **détail par modèle** (chat, embeddings, dictée — tokens en cache et de raisonnement compris) et tableau consolidé par utilisateur.
- **Exports** : boutons **Export CSV** (séparateur `;` + BOM, ouvre proprement dans Excel FR) et **Export PDF** (rapport tabulaire) sur les vues mensuelle et par utilisateur — l'export reflète les filtres actifs et porte les deux grandeurs (coût réel, valorisation). Un **export brut** (500 dernières lignes de logs, avec la source de chaque ligne) reste disponible.
- **Backfill estimatif outils** : reconstitue les usages d'outils historiques à partir des messages (sans recalcul de crédits — ces lignes comptent 0 crédit).

**Administration → Maintenance → Diagnostic → Journaux**

Le journal serveur de la plateforme (`errors.log`), transformé en tableau de bord de surveillance. L'écran **lit** le fichier, il ne le modifie jamais. Les heures sont données dans le **fuseau de votre navigateur** (le serveur, lui, écrit en UTC).

- **En-tête** : un verdict — **Calme** (aucune erreur), **Stable** (des avertissements seulement), **À surveiller** (des erreurs), **Pannes graves** (des critiques) —, la taille du fichier, la période qu'il couvre et la date de dernière écriture. Seule la **fin** d'un journal très volumineux est analysée (quelques mégaoctets : la période couverte s'affiche) ; au-delà de 100 Mo, la mention **Fichier volumineux** rappelle de le faire tourner sur le serveur.
- **Quatre gravités**, chacune une tuile qui l'affiche ou la masque : **Critiques** (l'application ou une demande est tombée : exception non rattrapée qui arrête tout, base injoignable, migration en échec, fichier de code absent, mémoire épuisée), **Erreurs** (une opération n'a pas abouti : appel refusé par un fournisseur, exception non rattrapée, base de connaissances non vectorisée…), **Avertissements** (abouti autrement que prévu : repli, connecteur indisponible, mot de passe faux, jeton périmé, avertissement PHP), **Infos** (connexions, démarrages — masquées par défaut). La tuile **Erreurs** compare avec la **période précédente** de même durée quand le journal la couvre. **Problèmes distincts** compte les pannes différentes et les **nouvelles** (jamais vues avant la période) ; **Dernière erreur** mène à la plus récente.
- **Période** : **1 h**, **6 h**, **24 h** (par défaut, et qui glisse : actualiser garde « les dernières 24 h »), **7 jours**, **30 jours**, **Tout**, ou **Personnalisé** (du… au…). **Recherche** : plusieurs mots (tous requis), « expression exacte » entre guillemets, **-mot** pour exclure. Un **identifiant d'incident** collé tel quel ouvre directement l'incident, sur tout le journal.
- **Domaines** : IA & modèles, Créations & fichiers, Bases & RAG, Connecteurs, Tâches & workflows, File & workers, Connexion & sécurité, Base & stockage, Coûts & usage, PHP, Autres — chacun avec son compte. Une ligne est rangée par son étiquette (`[KB Sync]`, `[OpenAIAdapter]`…) ; une exception sans étiquette, par l'endroit du code où elle est née.
- **Frise** : le volume dans le temps, empilé par gravité, par tranches de 1 minute à 1 semaine selon la période. Le survol donne le détail ; **toucher une barre zoome** sur ce créneau.
- **Vue Problèmes** (par défaut) : les entrées qui disent la même chose — au nombre, à l'adresse, à l'identifiant près — forment **un seul problème** : une panne répétée 2 757 fois tient en une ligne, avec sa gravité, son domaine, l'endroit du code, sa courbe sur la période, sa première et sa dernière apparition, et la mention **Nouveau**. Tri par **Gravité**, **Plus récents** ou **Plus fréquents**. Toucher un problème ouvre ses occurrences dans le Flux.
- **Vue Flux** : les entrées une à une, de la plus récente à la plus ancienne, séparées par journée. Les trois lignes qu'écrit une panne (le message, la pile d'appels, sa cause) n'en font qu'**une**. Une entrée s'ouvre sur son détail : horodatage, gravité, domaine, emplacement, identifiant d'incident, nombre d'occurrences du même problème, **pile d'appels lisible** (le code de MIA en évidence, les bibliothèques en retrait), chaîne des causes, texte brut, et les gestes **Copier l'entrée**, **Copier le lien**, **Autour de cet instant** (toutes les entrées, toutes gravités, cinq minutes avant et après), **Même problème**, **Tout l'incident**. **Afficher plus** charge la suite. Au clavier : **j** / **k** pour passer d'une entrée à l'autre, **Entrée** pour l'ouvrir, **/** pour chercher.
- **En direct** : les nouvelles entrées arrivent au fil de l'eau (toutes les 5 secondes, en pause quand l'onglet est caché) ; dans la vue Problèmes, un bandeau dit combien sont arrivées et propose d'actualiser. Le choix est retenu par le navigateur. Indisponible sur une période personnalisée (elle est fixe).
- **Exporter** télécharge en `.txt` les entrées correspondant aux filtres courants, à l'octet près, dans l'ordre chronologique — à joindre à un signalement. **Actualiser** relit le journal.

Les journaux contiennent des adresses e-mail et IP : ne les transmettez qu'à MyAlfred.

---

## 10. Bases de connaissances

**Espaces → Bases de connaissances** — la tuile **Bases** de la barre latérale. Les bases ne sont pas dans le menu de l'administration : un administrateur y arrive par la même tuile que les créateurs, et y voit toutes les bases de l'organisation. Les identifiants des sources en ligne, eux, se règlent dans **Capacités → Sources synchronisées** (voir plus bas).

Une base de connaissances est une **collection nommée de documents vectorisés** (recherche sémantique embarquée), **partageable entre plusieurs assistants**. À chaque question posée à un assistant lié, les extraits les plus pertinents de ses bases sont automatiquement injectés dans le prompt.

**La liste :** chaque base est présentée sous forme de **carte** : source d'alimentation (fichiers déposés, Drive, OneDrive, SharePoint, Google Agenda ou cerveau MyBrain), état en direct, volumétrie (documents, extraits, taille), **utilisation**, nombre d'**assistants liés** et **qui y a accès** (privée, partagée, partagée avec vous). **Cliquer une carte affiche, à droite, le schéma de la base** — le même que celui d'un assistant ou d'une tâche programmée : au-dessus ce qui l'alimente (dossier synchronisé, fichiers déposés, cerveau MyBrain), à gauche comment elle est cherchée (modèle d'embedding, dimensions, extraits), au centre la base (état, documents, utilisations), en dessous ce qu'elle sert (assistants liés, partage). Sur un écran étroit, il se déplie sous la carte. Son bouton **Modifier** — ou le nom de la base — ouvre la **fiche**, en pleine page ; le schéma l'accompagne, et chacun de ses éléments y mène au réglage correspondant (détail dans le **guide créateur**, « Lire le schéma de la base »).

**Filtrer et trier (dès qu'il y a plus d'une base) :** une barre d'outils surmonte la grille.
- Les **puces de source** (Toutes, Fichiers déposés, Google Drive, OneDrive, SharePoint, Google Agenda) isolent une origine en un clic. Le nombre porté par chaque puce est le nombre de bases concernées, et **une puce n'apparaît que si elle a de quoi filtrer** : sur une instance sans SharePoint, la puce SharePoint n'existe pas.
- Le champ **Filtrer** cherche dans le nom, la description, le créateur, les assistants liés et les bénéficiaires du partage. Il se combine avec la puce active.
- **Trier par** : *Nom (A → Z)*, *Utilisation*, *Utilisée récemment*, *Taille*, *Documents*, *Assistants liés*, *Ajoutée récemment*. À égalité, l'ordre alphabétique tranche.

Le filtre et le tri choisis sont **mémorisés dans votre navigateur** et retrouvés au retour sur la page (par compte : un navigateur partagé ne mélange pas les préférences).

**Utilisation d'une base :** la ligne de synthèse de chaque carte indique le nombre de réponses dans lesquelles la base a **réellement injecté des extraits** — pas le nombre de fois où elle a été fouillée. Une base liée à un assistant est interrogée à chaque message : ce qui distingue une base utile d'une base morte, c'est qu'il en sorte quelque chose. Une base qui n'a jamais servi porte la mention **jamais utilisée** : soit elle n'est liée à aucun assistant, soit son contenu ne ressort jamais des recherches (documents hors sujet, mal extraits, ou base inactive). Le compteur démarre à zéro à la mise en service de cette version : une base ancienne mais très utilisée met quelques jours à le montrer. Il agrège **les deux usages** : les recherches déclenchées par un assistant lié, et celles d'utilisateurs qui ont attaché la base à une conversation depuis le chat.

**Créer une base :** **+ Nouvelle base** ouvre la fiche en pleine page — **Identité**, **Sources**, **Assistants liés**, **Partage**, puis, repliée en bas, la section **Avancé** ; **Créer la base** l'enregistre. En modification, la même fiche s'enregistre toute seule.
- **Nom** et **description** (section **Identité**).
- **Modèle d'embedding** (section **Avancé**, choisi parmi les fournisseurs dont une clé API est configurée : OpenAI, Mistral ou Gemini). Pour en changer ensuite, utilisez **Réindexer** dans cette même section.
- **Documents** (section **Sources**, zone de dépôt : glisser-déposer ou choix de plusieurs fichiers à la fois) : mêmes formats que les documents de référence des assistants (PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, HTML, RTF, texte, Markdown, CSV/JSON/code, images). **20 fichiers max**, **20 Mo par fichier**. Les images sont décrites pour la recherche, et leur fichier sert de **modèle à l'outil image** des assistants (jusqu'à 4 par image générée).
- **Images** : décrites automatiquement par une IA vision, la description est indexée — l'image devient cherchable.
- **Assistants liés** : cases à cocher ; une base peut alimenter plusieurs assistants, un assistant peut consulter plusieurs bases (le rattachement se fait aussi depuis la section **Connaissances** de la page d'édition d'un assistant).
- **Statut** : l'interrupteur **Active**, à côté du titre de la fiche. Une base désactivée porte la pastille **Inactive** dans la liste et n'est **plus interrogée du tout** — les assistants liés continuent de répondre, mais sans son contenu, sans aucun message d'erreur. En cas d'assistant qui « a oublié » ses documents, **vérifiez ce statut en premier**.

**Qui voit quoi :** une base n'est visible et modifiable **dans cette page** que par **son créateur** et **tous les administrateurs** (le partage, décrit plus bas, l'ouvre en plus à ses bénéficiaires — y compris, via un groupe, à des utilisateurs sans droits d'administration, qui l'interrogent alors depuis le chat). Un créateur ne voit donc jamais les bases de ses collègues, ni dans cette page, ni dans la page d'édition de ses assistants. En tant qu'administrateur, vous voyez et gérez **toutes** les bases, quel que soit leur créateur (le propriétaire apparaît dans la rangée **Accès** de la carte).

**Partage (section Partage de la fiche d'une base) :** le créateur — ou un administrateur — peut ouvrir l'accès à des **personnes** (créateurs et administrateurs) et/ou à des **groupes**, ce qui évite de repartager à chaque nouvel arrivant. Deux niveaux :
- **coché seul** → le bénéficiaire voit la base, peut la **rattacher à ses assistants** et l'**interroger directement depuis le chat** (bouton livre ouvert sous le champ de saisie, 3 bases maximum par conversation), sans toucher aux documents ;
- **+ peut modifier** → il gère aussi documents, métadonnées et réindexation.

> **Pour ouvrir une base à des utilisateurs simples, passez par un GROUPE.** La liste des **personnes** ne propose que les créateurs et les administrateurs. Un partage à un **groupe**, lui, profite à tous ses membres quel que soit leur rôle : c'est le seul chemin pour qu'un utilisateur sans droits d'administration puisse cocher la base dans son chat.

> **Le partage vaut désormais accès direct au chat.** Avant, une base partagée ne servait qu'à être rattachée à un assistant — donc pour tout le monde et de façon permanente. Le bénéficiaire peut maintenant l'ajouter lui-même à une conversation, pour lui seul et pour la durée de cette conversation. Le contenu de la base ne devient visible que par la réponse de l'assistant, comme avant. Une base **inactive** ou **non vectorisée** apparaît grisée avec sa raison, et n'est pas cochable.

**Supprimer la base et modifier son partage restent réservés au créateur et aux administrateurs** : un partage, même en écriture, ne permet ni l'un ni l'autre. Côté bénéficiaire, la base porte la pastille **Partagée avec vous** et le bouton **Supprimer** n'apparaît pas. Les bases reçues **ne comptent pas** dans le quota de 5 bases par créateur.

**Poids des bases :** le total occupé par l'ensemble des bases s'affiche en haut de page (avec le nombre de bases et de documents), et chaque carte affiche sa volumétrie (documents, extraits, taille) sur sa ligne de synthèse.

**Découpage intelligent :** le texte est découpé en passages (**chunks**) par le **LLM par défaut de l'application**, qui regroupe les paragraphes traitant du même sujet — chaque passage vectorisé reste ainsi thématiquement cohérent, ce qui améliore la pertinence des réponses. Si aucune clé LLM n'est disponible ou en cas d'erreur, le découpage bascule automatiquement sur un mode dimensionnel (par taille) sans interrompre l'ingestion.

**Suivi de l'ingestion :** chaque document passe par deux étapes visibles en direct — **extraction** du texte, puis **vectorisation**. Un échec de vectorisation n'est pas bloquant : la base reste interrogeable en recherche texte (mode dégradé), relancez **Réindexer** une fois le problème réglé.

**Réindexer :** re-vectorise tous les documents de la base (textes extraits conservés). Dans la section **Avancé** de la fiche, **Réindexer avec** présélectionne le **modèle d'embedding** actuel : gardez-le après une indisponibilité du moteur vectoriel, ou choisissez-en un autre pour changer de modèle — seuls sont proposés les fournisseurs dont la clé est configurée ; l'écran dit ce qui va se passer, et **Réindexer la base** demande confirmation. Un modèle devenu indisponible entre-temps est refusé (« Modèle d'embedding indisponible : la base n'a pas été réindexée. ») : la base n'est jamais basculée sur un autre modèle sans votre choix. Pendant l'opération, la base répond par les mots. Le bouton **Réindexer** de la page *Administrer la base vectorielle* garde, lui, le modèle actuel.

**Extraits vectorisés (section Avancé) :** le détail de ce que la recherche retrouve vraiment — nombre d'extraits et de tokens, puis chaque extrait tel qu'il est en base de données, document par document (numéro, tokens, caractères), avec son vecteur : **Vectorisé**, **Sans vecteur** (seule la recherche par mots le retrouve — **Réindexer** le recalcule) ou **Vecteur non vérifié** (moteur vectoriel injoignable). Filtre par document et recherche dans le texte des extraits. Lecture seule, chargée à l'ouverture de la section, réservée à qui peut modifier la base ; rien n'est affiché pour un cerveau MyBrain, dont rien n'est copié dans MIA.

**Alimentation — cinq sources possibles :** la section **Sources** de la fiche d'une base propose cinq tuiles : **Fichiers déposés** (ajout manuel), **Google Drive**, **OneDrive**, **SharePoint** ou **Google Agenda**. Une base ne peut refléter qu'**une seule source en ligne à la fois** ; le dépôt de fichiers reste possible en complément dans tous les cas. Une sixième tuile, **Cerveau MyBrain**, réservée aux administrateurs, ne copie rien : voir ci-dessous.

**Brancher un cerveau MyBrain (base en direct) :** le wiki d'équipe MyBrain se branche comme une base dont le contenu **reste dans MyBrain** : MIA l'interroge à chaque message où elle est engagée (cochée dans la conversation ou liée à l'assistant), sans rien copier ni vectoriser, et la réponse cite ses fiches avec un lien vers MyBrain.
- **Prérequis :** une **clé d'API** créée dans MyBrain (Administration → Clés d'API), avec le droit **lire** (et **nourrir** pour laisser des groupes lui proposer des textes) — nommez-la d'après son usage (« MIA »), c'est ce nom que MIA affiche. Elle n'est montrée qu'une fois.
- **Créer :** **+ Nouvelle base** → tuile **Cerveau MyBrain** (le modèle d'embedding et les documents disparaissent de la fiche) → **adresse du MyBrain** (https ; http seulement sur le réseau interne du serveur) et **clé d'application** → **Tester la connexion** (nom de l'application et droits) → **Créer**. La base n'est créée que si le test passe. Le bouton **+ Nouvelle base** reste disponible pour un administrateur même sans clé d'embedding.
- **Qui l'interroge :** les **groupes** cochés dans **Partage**, et les administrateurs. La case **peut modifier** y est remplacée par **cochée par défaut** : pour ces groupes, la base est déjà cochée dans chaque **nouvelle** conversation (une conversation existante garde son choix), et se décoche comme une autre. **Le droit suit la personne, pas l'assistant** : vérifié à chaque message, même quand la base est liée à un assistant ouvert à d'autres groupes — pour eux, le cerveau n'est simplement pas interrogé. Retirer un groupe coupe l'accès au message suivant. La gestion (connexion, partage) reste aux administrateurs : un partage ne donne jamais le droit de modifier.
- **Qui le nourrit :** la case **peut nourrir** de chaque groupe. Ses membres peuvent demander à MIA de proposer un texte au cerveau (compte rendu, décision) : ils approuvent une carte dans MIA, puis le texte part **à leur nom** dans la **revue** de MyBrain — rien n'entre dans le wiki sans validation. Il faut en plus une clé qui porte le droit **nourrir** (sinon la case est sans effet) ; interroger le cerveau ne donne pas ce droit. Le résultat (lien vers la proposition à trancher) s'ajoute à la conversation quand MyBrain a fini son analyse.
- **Ce que la clé ouvre :** tout le **wiki partagé** de MyBrain — jamais sa zone RH (réservée à sa direction), ni les documents bruts, ni les données personnelles (mails, Drive). Choisissez les groupes en conséquence.
- **Changer de clé :** **Modifier** → nouvelle clé dans **Clé d'application** → **Tester et enregistrer**. C'est le seul chemin qui écrit la connexion : l'enregistrement automatique de la fiche ne la touche jamais, et un test raté laisse la connexion en place. Champ laissé vide = clé actuelle.
- **États de la carte :** **En direct** (application, droits, dernier contact) ; **MyBrain injoignable** (ambre, passager : les réponses partent sans le cerveau et le disent — après trois échecs d'affilée, MIA cesse de l'appeler une minute) ; **Connexion refusée** (rouge : clé supprimée, droit retiré, adresse fausse — la base est grisée dans le chat jusqu'au prochain test réussi). Le **Diagnostic** vérifie chaque cerveau branché.
- **Débrancher** retire la base de MIA ; la clé reste valide dans MyBrain, supprimez-la là-bas. Une base en direct n'a rien à **réindexer** ni à synchroniser : ces boutons n'apparaissent pas.
- **Coût :** pas de crédit à part — les extraits du cerveau allongent la question envoyée au modèle, décomptée comme d'habitude. Avec un modèle compatible avec les outils, MIA n'envoie d'abord qu'un extrait (les fiches les plus pertinentes, environ 4 000 tokens au lieu d'environ 10 000) et creuse seulement si la question l'exige — nouvelle recherche, fiche lue en entier, en lecture seule : deux à trois fois moins par message pour un cerveau coché par défaut.

**Synchroniser une source en ligne (Google Drive, OneDrive, SharePoint ou Google Agenda) :** la base reflète automatiquement le contenu de la source — tout ajout, modification, renommage ou suppression est répercuté dans la base, sans upload manuel.

- **Prérequis :** des identifiants globaux configurés une fois dans **Administration → Capacités → Sources synchronisées** (voir ci-dessous) — **clé API Google** pour Drive, **application Azure** (tenant + client + secret, permissions *d'application*) pour SharePoint, **application Microsoft** en permissions *déléguées* pour OneDrive (celle de la connexion Microsoft, ou une inscription dédiée), **clé API Google (Agenda)** pour Google Agenda. Tant qu'ils ne sont pas renseignés, la tuile correspondante est grisée dans la fiche des bases.
- **Deux familles d'accès, à ne pas confondre.** Les sources **partagées** (Drive par clé API, SharePoint, Agenda) sont lues par MIA lui-même : il faut donc un partage (« lien », application de l'organisation, agenda public). Les sources **personnelles** (**Ma connexion Google Drive**, **OneDrive**) sont lues **avec le compte de la personne qui enregistre la base**, avec ses droits : rien à partager publiquement, et un bouton **Parcourir…** affiche l'arborescence de ses dossiers pour choisir la source **sans coller le moindre lien** (voir plus bas).
- **Côté Google Drive — deux accès possibles**, au choix dans la fiche de la base (« Accès au dossier ») :
  - **Clé API globale** : le dossier doit être partagé **« Tous les utilisateurs disposant du lien » (Lecteur)** — clic droit sur le dossier → **Partager** → **Accès général**. Attention : ce mode de partage rend le dossier lisible par quiconque possède le lien — n'y placez pas de documents confidentiels.
  - **Ma connexion Google Drive** : la base est lue avec **votre propre compte Google** (connecté une fois dans **Réglages → Comptes connectés**). Il suffit que le dossier **vous appartienne, soit partagé avec vous ou se trouve dans un Drive partagé dont vous êtes membre** — aucun partage public. C'est l'accès à privilégier pour des documents internes, et le seul qui ouvre un **Drive partagé** entier. À savoir : la synchronisation est alors **portée par votre connexion** (son nom s'affiche dans l'état de la base) — si vous la déconnectez ou si votre compte est supprimé, la synchronisation s'arrête avec un message explicite ; toute personne qui ré-enregistre la base en ce mode la bascule sur sa propre connexion.

  Dans les deux cas, les **Google Docs, Sheets et Slides** sont convertis automatiquement (Word/Excel/PowerPoint).
- **Côté OneDrive — uniquement votre connexion :** l'application Microsoft configurée par l'administrateur n'accède à **rien par elle-même** ; elle autorise chaque personne à connecter **son propre OneDrive** dans **Réglages → Comptes connectés** (lecture seule). La base est alors lue avec **votre** compte Microsoft : vos dossiers, et ceux **partagés avec vous**. Il n'y a donc pas de choix d'accès à faire — et pas de lien à copier : le bouton **Parcourir…** ouvre l'explorateur (voir ci-dessous). Comme pour « Ma connexion Google Drive », la synchronisation est **portée par votre connexion** : la déconnecter (ou supprimer le compte) arrête la synchronisation avec un message explicite.
- **Parcourir ses dossiers (Drive « ma connexion » et OneDrive) :** le bouton **Parcourir…**, à côté du champ **Dossier**, ouvre sous lui l'arborescence de votre compte : **Mon Drive / Mon OneDrive**, **Drive partagés** (côté Google : ceux dont vous êtes membre — un Drive se choisit entier ou dossier par dossier) et **Partagés avec moi**, puis les sous-dossiers de proche en proche (fil d'Ariane cliquable, bouton ↑ pour remonter). Chaque dossier affiche aussi **ses fichiers**, marqués **repris** (📄) ou **format ignoré** (🚫), et un compteur annonce combien seront indexés — vous voyez ce que contiendra la base **avant** de valider. **Choisir ce dossier** remplit le champ et coche la synchronisation automatique ; les sous-dossiers seront inclus au cycle. L'explorateur n'existe pas pour SharePoint ni pour Drive en clé API globale : aucune identité n'y est attachée, il n'y a donc pas de « mes dossiers » à afficher — collez le lien.
- **Côté SharePoint :** aucun partage public n'est requis — l'accès passe par l'application Azure de l'organisation. Collez simplement l'**URL du dossier** (bouton **Copier le lien** de SharePoint, ou l'URL de la barre d'adresse) ; le dossier doit appartenir au tenant de l'application. Les fichiers Office sont récupérés tels quels.
- **Côté Google Agenda :** l'agenda doit être rendu **public** — Google Agenda → **Paramètres et partage de l'agenda** → **Autorisations d'accès aux événements** → **Rendre disponible publiquement** (n'utilisez donc pas un agenda contenant des évènements confidentiels). Collez son **adresse** (Paramètres → **Intégrer l'agenda** → *ID de l'agenda*, par ex. `xxx@group.calendar.google.com`) ou son URL publique. L'agenda est reflété sous forme d'**un document par mois** (fenêtre glissante : 3 mois en arrière, 12 en avant) listant chaque évènement — date, horaire, titre, lieu, description — que les assistants peuvent alors interroger (« quelles réunions la semaine prochaine ? »). Tout évènement créé, modifié ou supprimé met à jour le document du mois concerné au cycle suivant.
- **Côté MIA :** dans la section **Sources**, choisissez la tuile de la source, puis **Parcourir…** (Drive « ma connexion », OneDrive) ou collez l'**URL (ou l'adresse)** et vérifiez l'accès avec **Tester** (le nom de la source s'affiche) ; cochez **Synchronisation automatique** (à la création, **Créer la base** ; en modification, tout s'enregistre seul). Un premier cycle démarre aussitôt. Pour Drive, OneDrive et SharePoint, les sous-dossiers sont parcourus aussi.
- **Rythme :** un cycle tourne automatiquement selon l'intervalle global (10 minutes par défaut, réglable dans **Capacités → Sources synchronisées**, commun à toutes les sources) ; le bouton **Synchroniser maintenant** (ou ↻ sur la carte) lance un cycle immédiat. La carte de la base suit chaque cycle **en direct, sans recharger la page** : **Synchronisation en attente**, **en cours** avec le fichier traité et la progression (« 12 / 40 »), **À jour** avec l'heure du dernier passage, **documents à vérifier**, **en échec** avec le motif, **interrompue** (cycle arrêté net par un redémarrage) ou **en retard** (les cycles n'ont plus lieu — service de tâches de fond arrêté). Une pastille de couleur au coin du logo de la source le résume, et un compteur en haut de page totalise les bases en cours, en échec et à vérifier. Sur sa fiche, le panneau d'état détaille le bilan du dernier cycle (ajoutés, mis à jour, renommés, retirés, en échec, non repris), le prochain, et le nombre de documents **interrogeables par le sens**.
- **Contenus :** mêmes formats que l'upload manuel, **jusqu'à 50 Mo par fichier** (les fichiers synchronisés sont téléchargés côté serveur, hors de la limite d'upload manuel de 20 Mo). **100 fichiers maximum** par base synchronisée (au-delà : les premiers par ordre alphabétique). Les fichiers écartés — format non pris en charge, trop lourds, au-delà des 100 — sont listés **avec leur motif** parmi les **fichiers non repris**, dans le panneau d'état de la fiche.
- **Documents pilotés par la sync :** ils portent une pastille **Drive**, **OneDrive**, **SharePoint** ou **Agenda** et ne se suppriment pas à la main tant que la sync est active — retirez le fichier du dossier source (ou l'évènement de l'agenda, ou désactivez la synchronisation). Les documents **uploadés manuellement cohabitent** librement dans la même base : la synchronisation ne les touche jamais, et ils gardent leur propre plafond de 20.
- **Changer de source :** sélectionnez une autre tuile — les documents synchronisés depuis l'ancienne source sont retirés de la base au **premier cycle** de la nouvelle (un avertissement le rappelle dans la fiche). Repasser sur **Fichiers déposés** retire simplement la synchronisation : les documents déjà synchronisés restent et redeviennent supprimables à la main.
- **En cas d'erreur** (« dossier introuvable ou non partagé », identifiants invalides, secret Azure expiré…) : le **motif exact est affiché en clair sur la carte de la base**, avec l'heure de la dernière tentative. La base reste servie avec son dernier contenu ; corrigez le partage ou les identifiants, puis relancez avec **Réessayer** (↻ sur la carte). Un document illisible ou non vectorisé porte, lui, son propre motif dans la liste des documents de la base, où les documents à vérifier sont listés en premier. Causes les plus fréquentes : partage du dossier Drive repassé en privé (ou dossier déplacé/supprimé), clé API Google expirée ou son quota dépassé, secret de l'application Azure arrivé à échéance, agenda Google redevenu privé.

**La base système (assistant d'aide de MIA) :** l'assistant **« MIA : mode d'emploi »** s'appuie sur sa propre base de connaissances, repérable dans la liste par la pastille **Système**. Son contenu provient des **guides officiels de MIA** et il est **resynchronisé automatiquement à chaque déploiement d'une nouvelle version** — l'aide reste donc toujours à jour, sans intervention.

Cette base est **verrouillée** : elle ne peut être ni modifiée, ni supprimée, et vous ne pouvez ni y ajouter ni en retirer des documents (sa carte propose **Ouvrir** au lieu de **Modifier**, et sa fiche se lit sans se modifier). Dans la page d'édition de l'assistant d'aide, sa case est **grisée** : la liaison est posée automatiquement et ne peut pas être décochée. Seul **Réindexer**, dans la section **Avancé** de sa fiche, reste disponible — utilisez-le pour re-vectoriser la base après une panne du moteur vectoriel, ou pour changer son modèle d'embedding.

**Qui peut gérer les bases ?** Les **administrateurs** (toutes) et les **créateurs** (les leurs uniquement, **5 bases maximum** par créateur). Un créateur ne peut lier une base qu'à ses propres assistants ; les liaisons posées par un administrateur sur d'autres assistants sont préservées.

**Infrastructure :** le moteur vectoriel (Qdrant) est embarqué dans l'application (service Docker interne, non exposé). En cas d'indisponibilité, les recherches basculent automatiquement en mode texte — aucune interruption pour les utilisateurs. Les appels d'embedding consomment l'API du fournisseur choisi et sont **comptés dans le grand livre** : à l'**ingestion** (découpage sémantique, description des images, vectorisation), ils sont imputés au **créateur de la base**, **hors quota** ; à **chaque question** posée à un assistant qui utilise la base, l'embedding de la question est imputé à l'utilisateur, dans son quota (quelques tokens). La page **Consommation → Par catégorie** donne le coût par base.

**Page « Moteur vectoriel » (administrateurs) :** les bases elles-mêmes (les documents, le partage, la synchronisation) s'ouvrent par la tuile **Bases** de la barre latérale ; leurs identifiants de synchronisation sont dans **Capacités → Sources synchronisées**. L'état du moteur vectoriel est un onglet de **Maintenance → Diagnostic** (**Contrôles · Journaux · Moteur vectoriel · Agent**) : il donne l'état du moteur et celui de chaque base, et permet d'agir sans passer par le serveur. Un créateur ne voit que la gestion des bases.

- **En-tête :** pastille verte (tout va bien), **ambre** (points d'attention) ou **rouge** (moteur injoignable), version du service, nombre de collections et total de vecteurs. **Revérifier** relance le diagnostic.
- **Une ligne par base :** verdict, nombre d'**extraits** (le texte découpé, stocké en base de données — la source de vérité) face au nombre de **vecteurs** présents dans le moteur. Les deux doivent être égaux.
  - *Opérationnelle* — rien à faire.
  - *Vectorisation en cours* — une ingestion tourne, l'écart va se combler seul.
  - *Aucun vecteur* / *Vectorisation incomplète* — la base répond en mode texte : cliquez **Réindexer**.
  - *Collection absente* — cliquez **Réparer** (recrée la collection sans toucher aux vecteurs existants), puis **Réindexer** la base.
  - *Dimension incohérente* — le modèle d'embedding a changé sans réindexation : **Réindexer** est le seul chemin sûr.
  - *N documents en échec* — ouvrez la base pour voir lesquels, puis **Réindexer**.
  - *Collection orpheline* — reliquat d'une base supprimée pendant une panne du moteur : **Purger les orphelines** (irréversible, mais plus personne n'interroge ces vecteurs).
- **Réindexer** ne re-télécharge ni ne ré-extrait rien : il recalcule les vecteurs à partir des textes déjà extraits, en tâche de fond. Revérifiez quelques instants après pour suivre la remontée.
- **Si le moteur est injoignable :** vérifiez que le conteneur `qdrant` tourne (`docker compose ps`), relancez-le si besoin, puis **Revérifier**. Tant qu'il est absent, les assistants continuent de répondre en recherche texte.

---

**Budget IA atteint.** En mode « suspendre », la **synchronisation automatique** des bases se met en
pause jusqu'au 1er (ou jusqu'à une rallonge) : un bandeau le signale en tête de page et l'état d'une
base passée l'heure de son passage devient **Synchronisation suspendue**. **Synchroniser maintenant**,
le dépôt de documents et la réindexation restent possibles, et leur coût est compté.

### Sources synchronisées (identifiants globaux)

**Administration → Capacités → Sources synchronisées** *(admins seuls)*

Les identifiants qui autorisent une base à refléter une source en ligne, et l'**intervalle** entre deux cycles (défaut : 10 minutes, commun à toutes les sources). Ils sont **globaux** : une fois renseignés, tous les gestionnaires de bases peuvent activer une synchronisation. *(Ces réglages se trouvaient auparavant dans l'ancienne page Configuration → Général, devenue **Organisation → Marque & apparence**.)*

- **Google Drive** — une **clé API Google** (stockée chiffrée, jamais réaffichée). Pour la créer : [console Google Cloud](https://console.cloud.google.com/apis/credentials) → activez l'**API Google Drive** sur le projet → **Créer des identifiants → Clé API** → restreignez la clé à la seule API Drive. **N'ajoutez pas de restriction « référents HTTP »** : les appels viennent du serveur MIA, sans en-tête de provenance, et seraient tous refusés. La case **Supprimer la clé** désactive toutes les synchronisations Drive.
- **SharePoint** — une **application Azure** de votre organisation. Pour la créer : [portail Azure](https://portal.azure.com) → **Microsoft Entra ID → Inscriptions d'applications → Nouvelle inscription** (comptes de votre organisation uniquement). Notez l'**ID de l'annuaire (tenant)** et l'**ID d'application (client)** affichés sur la page de l'app. Puis **Certificats & secrets → Nouveau secret client** : copiez la **valeur** immédiatement (elle n'est plus réaffichée ensuite) et surveillez sa **date d'expiration** (24 mois maximum — à renouveler ici le moment venu). Enfin **API autorisées → Ajouter une autorisation → Microsoft Graph → Autorisations d'application → `Files.Read.All`**, puis **Accorder un consentement d'administrateur**. Saisissez les trois valeurs dans MIA : le secret est stocké chiffré et jamais réaffiché ; tenant et client restent visibles. La case **Supprimer les identifiants** désactive toutes les synchronisations SharePoint.
- **Google Agenda** — une **clé API Google (Agenda)** (stockée chiffrée, jamais réaffichée), dédiée aux agendas publics. Même démarche que pour Drive dans la [console Google Cloud](https://console.cloud.google.com/apis/credentials), mais en activant l'**API Google Calendar** ; la clé peut être distincte de la clé Drive ou commune (l'important est que l'API Calendar soit autorisée pour elle), toujours **sans restriction « référents HTTP »**. La case **Supprimer la clé** désactive toutes les synchronisations Google Agenda.
- **OneDrive** — une **application Microsoft** en permissions **déléguées** : elle n'accède à rien par elle-même, elle autorise chaque utilisateur à connecter **son propre OneDrive**. **Le plus simple : aucune inscription dédiée.** OneDrive utilise alors l'application de la **connexion Microsoft** (Organisation → Authentification, section 13), comme Outlook, le calendrier Outlook, To Do et Teams : même URI de redirection, déjà déclarée, et la page Authentification liste `Files.Read.All` parmi les permissions déléguées à y ajouter (puis « Accorder le consentement administrateur » si votre annuaire l'exige). La carte OneDrive dit en tête quelle application sert, et ce qui manque.

  **Une inscription dédiée reste possible**, et **l'emporte dès que son ID d'application est saisi** : utile pour cantonner `Files.Read.All` à une application à part, ou viser un autre annuaire que la connexion. Une inscription dédiée **incomplète** (secret manquant) rend OneDrive indisponible : MIA ne bascule jamais en silence sur l'application de la connexion. **Changer d'application impose à chacun de reconnecter son OneDrive** (un jeton n'est valable que pour l'application qui l'a émis : la connexion passe « à reconnecter »). Pour créer l'inscription dédiée : [portail Azure](https://portal.azure.com) → **Inscriptions d'applications → Nouvelle inscription** ; notez l'**ID d'application (client)**. Ajoutez une plateforme **Web** avec l'**URI de redirection affichée dans MIA sous le champ** (`https://votre-domaine/settings/onedrive/callback`) — c'est l'oubli le plus fréquent, il produit l'erreur `AADSTS50011` au moment du consentement, et MIA la traduit en clair. Créez un **secret client** (**Certificats & secrets**, copiez la *valeur*, surveillez l'expiration). Enfin **API autorisées → Microsoft Graph → Autorisations **déléguées** → `Files.Read.All`, `User.Read`, `offline_access`**. L'**ID de l'annuaire (tenant)** peut rester vide (`common` : comptes professionnels et personnels) ou porter votre tenant pour restreindre à votre organisation. Aucune permission d'écriture n'est demandée : la connexion est en **lecture seule**. La case **Supprimer l'inscription dédiée** fait repasser OneDrive par l'application de la connexion Microsoft, si elle est configurée (chacun reconnecte alors son OneDrive).

  **La marche à suivre complète est rappelée sur l'écran**, dans le bloc dépliant *Inscription dédiée (facultative)* de la carte OneDrive (huit étapes, liens vers le portail Azure, et l'URI de redirection avec un bouton **Copier** — elle doit être recopiée à l'identique). Le bloc s'ouvre de lui-même tant qu'aucune application n'est utilisable.

  Une fois l'application déclarée, chaque créateur connecte son compte dans **Réglages → Comptes connectés → OneDrive**, puis choisit un dossier avec **Parcourir…** dans la fiche de sa base. **Une même application Azure peut servir à SharePoint et à OneDrive**, mais les deux blocs d'identifiants restent distincts dans MIA : les permissions n'y sont pas du même type (*application* d'un côté, *déléguées* de l'autre).

- **Tester la connexion** — sous chaque fournisseur, un bouton **Tester la connexion** vérifie immédiatement les identifiants, y compris ceux tout juste saisis dans le formulaire (avant même d'enregistrer). Côté Google (Drive comme Agenda) : clé acceptée et API concernée (Drive / Calendar) activée sur le projet. Côté SharePoint : authentification Azure réussie et permission `Files.Read.All` présente. Côté OneDrive, le bouton s'appelle **Vérifier la configuration** et ne contrôle que la présence des identifiants de l'application retenue (dédiée, sinon celle de la connexion Microsoft) : il n'y a rien de global à tester, l'accès réel se vérifie avec le bouton **Tester** (ou **Parcourir…**) de la base, avec la connexion d'un utilisateur. Le résultat s'affiche en clair sous le bouton — succès, ou cause précise de l'échec (clé invalide, API non activée, restriction « référents HTTP » à retirer, tenant ou client introuvable, secret invalide ou expiré, consentement administrateur manquant…).

---

## 11. Connecteurs

**Administration → Capacités → Connecteurs**

La page liste **tous les services** que vos utilisateurs peuvent relier à MIA, en **cartes côte à côte** (deux à
trois de front selon la largeur de l'écran), chacune avec un interrupteur **Visible / Masqué** qui s'applique à
**tous les utilisateurs**. C'est ici, et nulle part ailleurs, que vous décidez **ce qui apparaît dans
l'interface** de vos utilisateurs. L'enregistrement est immédiat : aucun bouton à valider.

Chaque carte indique ce que le connecteur autorise (badges **Lecture**, **Écriture approuvée**, **Brouillons**…),
où il se manifeste pour l'utilisateur (ligne du menu **+**, ou page **Réglages → Comptes connectés**), et — pour les
connecteurs personnels — le **nombre de comptes reliés**.

| Connecteur | Où il apparaît | Ce qu'il permet |
|---|---|---|
| **Recherche Internet** | Menu **+** → Recherche Internet | Consulter le web avant de répondre |
| **Google Drive** | Menu **+** → Fichiers Google Drive | Chercher et lire les documents, créer et modifier Docs/Sheets (après approbation) |
| **Gmail** | Menu **+** → Emails Gmail | Chercher et lire les emails, préparer des brouillons (jamais d'envoi) |
| **Google Agenda** | Menu **+** → Agenda Google | Consulter les agendas, créer des évènements (après approbation) |
| **Google Tasks** | Menu **+** → Tâches Google | Consulter les tâches, en ajouter, modifier et supprimer (après approbation) |
| **Outlook** | Menu **+** → Emails Outlook | Chercher et lire les emails, préparer des brouillons (jamais d'envoi) |
| **Calendrier Outlook** | Menu **+** → Agenda Outlook | Consulter les agendas, créer des évènements sans participant (après approbation) |
| **Microsoft To Do** | Menu **+** → Tâches Microsoft To Do | Consulter les tâches, en ajouter et les modifier — échéance, rappel, terminée (après approbation, jamais de suppression) |
| **Microsoft Teams** | Menu **+** → Messages Teams | Chercher et lire les conversations et les canaux de l'utilisateur, publier un message en son nom (après approbation) — **consentement administrateur obligatoire** |
| **Slack** | Menu **+** → Messages Slack | Chercher et lire les conversations visibles par l'utilisateur, publier un message en son nom (après approbation) |
| **OneDrive** | Réglages → Comptes connectés | Alimenter une **base de connaissances** avec un dossier OneDrive (lecture seule, jamais dans le chat) |

> **Slack et Microsoft Teams sont les deux connecteurs dont l'écriture est IMMÉDIATEMENT VISIBLE.** Là où Gmail
> et Outlook s'arrêtent au brouillon, un message Slack ou Teams approuvé **part dans le canal ou la conversation**
> et tout le monde le voit — MIA ne peut ensuite ni le modifier ni le supprimer. La carte d'approbation le dit en toutes lettres, et le consentement porte sur
> `chat:write`, qui couvre aussi — côté API — la modification et la suppression des messages de l'utilisateur :
> MIA n'expose aucun outil de ce genre. Les jetons sont personnels : un assistant ne voit que les conversations
> dont la personne qui lui parle est déjà membre.

> **Google Tasks est le seul connecteur capable de SUPPRIMER une donnée.** Ce n'est pas un choix de MIA : Google
> ne propose aucun droit d'écriture sans suppression sur cette API. Trois garde-fous compensent — chaque écriture
> (ajout, modification, suppression) passe par une **carte d'approbation**, l'aperçu d'une suppression annonce son
> **irréversibilité**, et chaque utilisateur peut **désactiver la suppression pour son compte** (Réglages →
> Comptes connectés). Si cette capacité n'a pas sa place dans votre organisation, laissez le connecteur **Masqué**.

**Deux modèles d'accès.** Les connecteurs **Google**, **Microsoft** (Outlook, calendrier Outlook, Microsoft To Do, Microsoft Teams), **Slack** et **OneDrive** sont **personnels** : chaque utilisateur
relie son propre compte depuis **Réglages → Comptes connectés**, et un assistant ne voit jamais que les données de la
personne qui lui parle. La **Recherche Internet** est au contraire un réglage **d'organisation** : une seule
configuration pour tout le monde, aucun compte à relier.

> **OneDrive n'est pas un connecteur de chat.** Il ne sert qu'à faire d'un dossier OneDrive la source d'une
> **base de connaissances** (section 9), il n'apparaît donc jamais dans le menu **+** et n'a pas d'expressions
> déclencheuses. Il figure ici pour une seule raison : vous devez pouvoir décider, comme pour les autres, s'il
> est proposé ou non à vos utilisateurs. Sa carte reste par ailleurs réservée aux **administrateurs et
> créateurs** — les seuls à gérer des bases.

> Les **automatisations n8n** ne sont pas ici : déclencher un workflow n'est pas consulter une source, c'est une
> capacité confiée à un assistant. Elles se configurent en **Outils** (section 7).

**Masqué = invisible partout.** C'est le sens exact de l'interrupteur :

- la ligne **disparaît** du menu **+** de tout le monde (masquée, jamais grisée — aucune option morte), et le
  serveur refuse l'accès même si la demande lui parvient quand même ;
- la **carte disparaît** de **Réglages → Comptes connectés** : plus personne ne peut relier un compte à un service
  que vous ne proposez pas. Si plus aucun service n'est proposé, la page l'annonce en une phrase plutôt que de
  rester vide sans explication.

**Une exception, volontaire :** l'utilisateur qui avait **déjà relié** son compte garde sa carte, en lecture
seule. Elle affiche « *ce service n'est plus proposé par votre administrateur* » et ne propose plus que
**Déconnecter** — sans quoi il n'aurait plus aucun moyen de retirer un accès qu'il a lui-même accordé à Google
ou Microsoft. Les connexions existantes **sont conservées** : l'accès revient à l'identique dès la
réactivation, sans reconnexion.

### Laisser MIA choisir (activation des connecteurs par MIA)

La carte **Laisser MIA choisir**, en tête de page, est **Proposé** par défaut. Le menu **+** de chaque utilisateur
gagne alors une ligne du même nom, qu'il allume s'il le souhaite : ses connecteurs **reliés** mais éteints deviennent
activables **par MIA elle-même**, pour la réponse en cours, quand la demande en a besoin — plus besoin de penser à
allumer Gmail avant de demander « retrouve le devis Durand dans mes mails ».

- **Rien de plus que ce que l'utilisateur a relié** : seuls les connecteurs **visibles**, et reliés par la personne,
  sont activables. Masquer un connecteur le retire aussi de ce choix.
- **Aucune écriture de plus** : chaque action préparée passe par sa carte d'approbation. Chaque activation apparaît
  dans la liste des actions de la réponse (« Activation d'un connecteur — Gmail »).
- **Coût maîtrisé** : tant qu'aucun connecteur n'est activé, seule une courte définition part au modèle ; allumer
  d'office tous les connecteurs reliés aurait envoyé des dizaines d'outils à chaque message. Un connecteur activé
  coûte ensuite exactement comme s'il avait été allumé à la main.
- **Désactivé** : la ligne disparaît du menu **+**, et le serveur refuse le mode même si un navigateur avait gardé
  l'interrupteur allumé.

**Provenance des cartes.** Quand MIA a lu des emails, des documents, des messages ou des évènements avant de préparer
une action, la carte d'approbation les nomme (« Préparé après la lecture de : … ») et rappelle à l'utilisateur de
vérifier que l'action correspond bien à sa demande. C'est la parade, au moment où elle sert, à une consigne glissée
dans un contenu écrit par un tiers.

### Capacités ajoutées sans autorisation de plus

- **Pièces jointes des emails** (Gmail, Outlook) : lues avec les droits de lecture déjà accordés
  (`gmail.readonly`, `Mail.Read` / `Mail.ReadWrite`). Formats lus : PDF, Word, Excel, PowerPoint, OpenDocument,
  RTF, HTML, texte, CSV, JSON ; 20 Mo au plus par pièce jointe.
- **Créneaux libres** (Google Agenda, Calendrier Outlook) : `freeBusy` chez Google, `calendarView` et `getSchedule`
  chez Microsoft, couverts par la lecture d'agenda déjà accordée. Pour un collègue nommé, MIA n'obtient que ses plages
  **occupées** (jamais titres ni contenus), et seulement si son agenda est visible du compte de l'utilisateur.
- **Lecture d'une page web** : suit l'interrupteur **Recherche Internet** (le masquer coupe aussi la lecture de pages).
  Protections : http et https sur les ports 80 et 443 seulement, aucune adresse de réseau interne, réservée ou de
  métadonnées du cloud — vérifiée pour chaque adresse résolue et à chaque redirection, la connexion étant épinglée sur
  l'adresse vérifiée —, 5 Mo et 15 secondes au plus. Les pages qui demandent une connexion ne se lisent pas.
- **Anciennes conversations** : chaque utilisateur ne retrouve que les siennes.
- **Recherches multi-sources** (« Recherche dans toutes vos sources », Gmail + Outlook, Google Agenda + Outlook) : quand
  plusieurs connecteurs sont allumés pour un message, une seule recherche les interroge **en parallèle** (25 secondes au
  plus, une source trop lente est abandonnée et nommée). Elle ne touche que les sources déjà allumées ou activées pour
  ce message, en lecture seule. Côté coût : un seul aller-retour avec le modèle au lieu d'un par source.
- **Fuseau horaire** : les heures d'agenda suivent le fuseau du navigateur de l'utilisateur (le serveur tourne en
  UTC). Un évènement créé par MIA tombe donc à l'heure demandée, et la carte d'approbation nomme le fuseau.

### Expressions déclencheuses (suggestion pendant la frappe)

Chaque carte porte un bloc dépliant **Expressions déclencheuses**. Quand un message en cours de frappe contient
l'une de ces expressions, MIA propose à l'utilisateur d'activer le connecteur, dans une bannière au-dessus de la
zone de saisie (rien n'est activé sans son accord — voir le guide utilisateur). **Ces listes sont modifiables** :
une expression par ligne ; la liste s'[enregistre automatiquement](#enregistrement-automatique) peu après votre
dernière frappe (ou dès que vous quittez la zone), et **Réinitialiser** rétablit la liste livrée avec
l'application.

- **Comparaison souple** : accents, majuscules et espaces multiples sont ignorés — inutile de saisir les
  variantes. La correspondance se fait sur des **mots entiers** : `gmail` ne déclenche pas dans `gmailer`.
- **Une liste vide désactive la suggestion** pour ce connecteur (il reste utilisable via le menu **+**).
- **Doublons retirés et casse normalisée** à l'enregistrement : la zone se recharge avec la liste réellement
  active — ce que vous voyez après enregistrement est ce qui s'applique.
- **La Recherche Internet n'est suggérée que si son interrupteur est éteint** (il est actif par défaut), donc
  quasiment jamais en usage normal.
- **À éviter : un mot générique seul.** `agenda` ferait suggérer l'Agenda Google sur « l'agenda de la réunion »,
  `document` sur « le document ci-joint ». Les listes livrées s'appuient toujours sur la marque
  (`google drive`, `gmail`), un possessif (`mon agenda`, `mes mails`) ou un verbe explicite
  (`réponds à ce mail`) — gardez ce principe en les complétant.
- Les listes modifiées s'appliquent au **prochain chargement du chat** de chaque utilisateur.

**Prérequis technique.** Un connecteur autorisé mais dont le prérequis manque reste indisponible — la carte
l'annonce alors en clair. La **Recherche Internet** n'en a aucun (elle dépend en revanche du modèle : seuls les
modèles capables de recherche native, ou disposant d'un modèle de secours, interrogent le web) ; les trois
connecteurs Google exigent le client OAuth du login (`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` du `.env`) et
les APIs correspondantes, détaillés ci-dessous.

**Recherche Internet demandée mais impossible.** Si l'utilisateur active l'interrupteur sur un modèle incapable
de chercher en ligne, la réponse le dit explicitement et le journal porte une ligne
`[connecteurs] Recherche Internet demandée mais indisponible … : modèle <slug> sans recherche web ni modèle de
secours au catalogue`. Utile pour trancher entre « le modèle ne sait pas » et « le fournisseur est en panne ».

- **Google Drive personnel des utilisateurs** (Réglages → Comptes connectés) : chaque utilisateur peut connecter **son propre Drive** pour que les assistants **lisent** ses documents en conversation, et **créent/modifient** ses Google Docs et Sheets à sa demande. Cela réutilise le **client OAuth Google du login** (`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` du `.env`) **et son URI de redirection existante** : si le login Google fonctionne, la connexion Drive fonctionne — **aucune URI supplémentaire à déclarer**. Pour un fonctionnement complet, dans la [console Google Cloud](https://console.cloud.google.com/apis/credentials) :
  - activer les APIs **Google Drive**, **Google Docs** et **Google Sheets** sur le projet (**APIs et services → Bibliothèque**). Attention : la **lecture** passe par l'API Drive, mais l'**écriture** passe par les APIs Docs et Sheets, **distinctes** — la lecture peut donc fonctionner alors que toute écriture échoue. Dans ce cas le message est explicite : *« L'API Google Docs (ou Sheets) n'est pas activée sur le projet Google Cloud de l'application »* → activez l'API nommée puis refaites la demande ;
  - déclarer les scopes `https://www.googleapis.com/auth/drive.readonly`, `…/auth/documents` et `…/auth/spreadsheets` sur l'**écran de consentement** (scopes sensibles : app interne Workspace = aucun souci ; app publique = validation Google requise). Un scope refusé au consentement = fonctionnalité correspondante simplement absente (badges sur la page Comptes connectés).
  Les jetons sont stockés **chiffrés** par utilisateur ; la déconnexion (ou une révocation côté Google) coupe l'accès immédiatement. Un utilisateur connecté **avant** l'arrivée de l'écriture reste en lecture seule tant qu'il n'a pas reconnu (bouton « Reconnecter et autoriser l'écriture »). Aucune suppression de fichier n'est possible. C'est indépendant de la **clé API** de synchronisation des bases de connaissances (section 10).
  - **Interrupteur global** : carte **Google Drive** de cette page (visible par défaut). Masqué, l'option **Fichiers Google Drive** du menu **+** du chat **disparaît** pour tout le monde (ligne masquée, pas grisée — aucune option morte dans le menu) et les assistants n'accèdent plus à aucun Drive, même pour les utilisateurs déjà connectés — leurs jetons sont conservés et l'accès revient dès réactivation. Même effet tant que la **clé/identifiants Google** ne sont pas configurés.
  - **Côté utilisateur, l'accès est opt-in explicite** : même Drive connecté et fonctionnalité active, l'assistant ne consulte le Drive que si l'utilisateur a activé l'interrupteur **Fichiers Google Drive** du menu **+** (jamais activé d'office). Ce choix est ensuite **mémorisé sur son navigateur** (localStorage, cloisonné par compte) et reste actif au rechargement comme dans une nouvelle conversation, jusqu'à ce qu'il le désactive. Il ne survit ni à un autre navigateur, ni à un nettoyage des données du site ; l'interrupteur redevient inactif si l'admin coupe la fonctionnalité ou si l'utilisateur se déconnecte de Google.
  - **Toute écriture est soumise à approbation** : quand un assistant veut créer ou modifier un Doc/Sheet, rien n'est exécuté immédiatement — une **carte d'approbation** s'affiche dans la conversation (action exacte, fichier concerné, impacts, aperçu du contenu) et l'écriture n'a lieu qu'au clic **Approuver** de l'utilisateur. Sans réponse, la demande expire en 30 minutes. La suppression de fichiers reste impossible, approbation ou pas.
- **Gmail personnel des utilisateurs** (Réglages → Comptes connectés) : même modèle que le Drive — chaque utilisateur connecte **son propre Gmail** pour que les assistants **recherchent** et **lisent** ses emails en conversation, et préparent des **emails** à sa demande, qu'il relit dans une carte avant de les **enregistrer en brouillon** ou de les **envoyer** d'un clic. Réutilise le client OAuth Google du login (aucune URI supplémentaire). Côté console Google Cloud :
  - activer l'**API Gmail** ([bibliothèque](https://console.cloud.google.com/apis/library/gmail.googleapis.com)) **sur LE projet du client OAuth** — le même que le login et le Drive ; en cas d'oubli, le message d'erreur en conversation nomme l'API et le **numéro du projet** exact ;
  - déclarer les scopes `gmail.readonly`, `gmail.compose` et `gmail.settings.basic` sur l'écran de consentement. **`gmail.compose` couvre l'envoi** (Google le décrit « Gérer les brouillons et envoyer des e-mails ») : c'est lui que le bouton **Envoyer** utilise, `gmail.send` n'est pas demandé, il n'ajouterait rien. Ce qui tient la promesse « rien ne part sans le clic de l'utilisateur », c'est l'application : aucun assistant n'a d'outil d'envoi, l'envoi n'existe que comme bouton de la carte de relecture, après confirmation. Il n'y a pas de réglage pour retirer ce bouton côté Google — si votre organisation ne veut aucun envoi depuis MIA, laissez le connecteur **Masqué**.
  - **Interrupteur global** : carte **Gmail** de cette page (visible par défaut). Masqué, l'option **Emails Gmail** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Côté utilisateur : **opt-in explicite** (interrupteur du menu **+**, jamais activé d'office, puis mémorisé sur son navigateur comme pour le Drive) et **chaque email passe par la carte de relecture** — brouillon ou envoi, rien n'existe avant son clic.
- **Google Agenda personnel des utilisateurs** (Réglages → Comptes connectés) : même modèle que le Drive et Gmail — chaque utilisateur connecte **son propre agenda** pour que les assistants **consultent** ses agendas et évènements en conversation (« quelles réunions demain ? »), et **créent des évènements** à sa demande. Réutilise le client OAuth Google du login (aucune URI supplémentaire). Côté console Google Cloud :
  - activer l'**API Google Calendar** ([bibliothèque](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)) **sur LE projet du client OAuth** — le même que le login, le Drive et Gmail ; en cas d'oubli, le message d'erreur en conversation nomme l'API et le **numéro du projet** exact ;
  - déclarer les scopes `calendar.readonly` et `calendar.events` sur l'écran de consentement. **Le scope `calendar` complet n'est jamais demandé** : aucun assistant ne peut modifier ni supprimer d'évènement existant, ni toucher aux partages d'agendas — seule la **création** est possible, et chaque création passe par la carte d'approbation. Les évènements créés n'envoient **aucune invitation automatique** aux participants.
  - **Interrupteur global** : carte **Google Agenda** de cette page (visible par défaut). Masqué, l'option **Agenda Google** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Côté utilisateur : **opt-in explicite** (interrupteur du menu **+**, jamais activé d'office, puis mémorisé sur son navigateur comme pour le Drive) et **chaque création d'évènement passe par la carte d'approbation** avant exécution. C'est indépendant de la **clé API** Google Agenda de synchronisation des bases de connaissances (section 10) : ici l'accès est personnel, via OAuth, agenda privé compris.

- **Outlook personnel des utilisateurs** (Réglages → Comptes connectés) : même modèle que Gmail, côté Microsoft — chaque utilisateur connecte **sa propre boîte Outlook** pour que les assistants **recherchent** et **lisent** ses emails en conversation, et préparent des **emails** à sa demande, qu'il relit dans une carte avant de les **enregistrer en brouillon** ou de les **envoyer** d'un clic. **Rien ne part sans ce clic** : aucun assistant n'a d'outil d'envoi.
  - **L'application Azure est celle du login Microsoft** (Organisation → Authentification, section 13) : **aucune URI de redirection supplémentaire à déclarer**, elle est déjà la bonne. Point important : **la connexion Microsoft n'a pas besoin d'être ACTIVÉE** pour autant — seuls les **identifiants** comptent. Une organisation qui se connecte à MIA par Google peut donc renseigner l'application Azure, laisser le bouton de connexion Microsoft éteint, et utiliser le connecteur Outlook.
  - Côté portail Entra, sur **cette** inscription d'application → **API autorisées** → **Microsoft Graph** → **Autorisations déléguées** : ajoutez `Mail.Read`, `Mail.ReadWrite` et `Mail.Send`. Selon la politique de votre annuaire, un **consentement administrateur** peut être nécessaire (bouton « Accorder un consentement administrateur »). Sans lui, l'utilisateur voit une connexion en **lecture seule** (badge **Brouillons —** sur sa page Comptes connectés) plutôt qu'une erreur. La page **Organisation → Authentification** rappelle la liste exacte des permissions à déclarer, connecteur par connecteur.
  - **Une fois le consentement administrateur accordé, chacun relie Outlook sans écran d'autorisation**, quel que soit le réglage de consentement de votre annuaire : MIA ne force jamais l'écran de consentement de Microsoft (il bloquait « Approbation de l'administrateur requise » les personnes sans droit de consentir, consentement administrateur accordé ou non). Même règle pour le calendrier Outlook, Microsoft To Do, Microsoft Teams et OneDrive.
  - **Un consentement accordé APRÈS coup est pris en compte tout seul.** Microsoft redit les permissions du jeton à chaque renouvellement : dès qu'un administrateur accorde `Mail.ReadWrite`, l'utilisateur retrouve les brouillons à sa prochaine utilisation — **sans se reconnecter**, et le badge de sa page Comptes connectés se met à jour. L'inverse est vrai aussi : une permission retirée côté Microsoft retire l'outil au lieu de le laisser échouer.
  - **`Mail.Send` ne sert qu'au bouton Envoyer** de la carte de relecture, après confirmation de l'utilisateur ; aucun assistant n'a d'outil d'envoi, et ce qui part est toujours le brouillon que MIA vient de créer, jamais un message assemblé à part. **C'est le levier de votre organisation** : refusez `Mail.Send` au consentement et les brouillons restent possibles, seul le bouton disparaît (badge **Envoi —**, explication au clic). Une connexion faite **avant** l'arrivée de ce bouton n'a pas cette permission : l'utilisateur la voit à « Envoi — » et la débloque par **Reconnecter et autoriser**.
  - **À savoir sur `Mail.ReadWrite`** : c'est le **seul** droit Graph qui permette de créer un brouillon, et il couvre aussi — côté API — la modification et la suppression de messages. Microsoft n'offre pas d'équivalent du `gmail.compose` de Google. MIA n'expose **aucun outil** de modification ni de suppression : recherche, lecture, brouillons et envoi sur clic, rien d'autre. Si ce périmètre de consentement ne convient pas à votre organisation, laissez le connecteur **Masqué**.
  - **Interrupteur global** : carte **Outlook** de la page **Connecteurs** (visible par défaut). Masqué, l'option **Emails Outlook** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Côté utilisateur : **opt-in explicite** (interrupteur du menu **+**, jamais activé d'office, puis mémorisé sur son navigateur) et **chaque email passe par la carte de relecture** — brouillon ou envoi, rien n'existe avant son clic.
  - **Un compte sans boîte Exchange Online** (licence absente, compte d'annuaire sans messagerie) reçoit un message qui le dit clairement, plutôt qu'une invitation à se reconnecter en boucle.
  - **Déconnexion** : Microsoft n'expose aucune révocation de jeton délégué. Retirer la connexion dans MIA supprime le jeton et coupe l'accès ; pour retirer aussi l'autorisation côté Microsoft, l'utilisateur passe par [myaccount.microsoft.com](https://myaccount.microsoft.com/).

- **Calendrier Outlook personnel des utilisateurs** (Réglages → Comptes connectés) : même modèle que Google Agenda, côté Microsoft — chaque utilisateur connecte **son propre calendrier** pour que les assistants **consultent** ses agendas et évènements en conversation (« quelles réunions demain ? »), et **créent des évènements** à sa demande. Connexion **distincte** de celle d'Outlook : un utilisateur peut relier son agenda sans donner accès à sa messagerie.
  - **Même application Azure que le login** (donc **aucune URI supplémentaire**), et là aussi la connexion Microsoft n'a **pas besoin d'être activée** : seuls les identifiants comptent.
  - Côté portail Entra, sur **cette** inscription → **API autorisées** → **Microsoft Graph** → **Autorisations déléguées** : ajoutez `Calendars.Read` et `Calendars.ReadWrite`. Un **consentement administrateur** peut être nécessaire selon votre annuaire ; sans lui, l'utilisateur voit une connexion en **lecture seule** (badge **Création d'évènements —**) plutôt qu'une erreur. Là aussi, un consentement accordé **après** la connexion est repris tout seul à la prochaine utilisation, sans reconnexion.
  - **`Calendars.ReadBasic` n'est jamais demandé** : il ne rendrait que des créneaux occupé/libre, sans titre ni participants — un agenda illisible.
  - **À savoir sur `Calendars.ReadWrite`** : c'est le **seul** droit Graph qui permette de créer un évènement, et il couvre aussi — côté API — la modification et la suppression. MIA n'expose **aucun outil** de ce genre : consultation et création approuvée, rien d'autre. Si ce périmètre ne convient pas à votre organisation, laissez le connecteur **Masqué**.
  - **Aucune invitation n'est possible, et c'est une vraie différence avec Google.** Microsoft n'offre pas d'équivalent du `sendUpdates=none` : un évènement créé avec des participants **envoie l'invitation aussitôt**. MIA n'envoyant jamais de message au nom d'un utilisateur, elle crée les évènements **sans participant** — l'assistant le dit et invite à les ajouter dans Outlook. Une demande d'invités est explicitement refusée, jamais ignorée en silence.
  - **Interrupteur global** : carte **Calendrier Outlook** de la page **Connecteurs** (visible par défaut). Masqué, l'option **Agenda Outlook** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Côté utilisateur : **opt-in explicite** (interrupteur du menu **+**, jamais activé d'office, puis mémorisé sur son navigateur) et **chaque création passe par la carte d'approbation** avant exécution.
  - **Fuseau horaire** : c'est celui du **serveur** qui fait foi (bornes de recherche, heures affichées, création). MIA ne lit pas le fuseau déclaré dans Outlook — cela exigerait une autorisation supplémentaire pour un gain nul dans une organisation d'un même pays.

- **Microsoft To Do des utilisateurs** (Réglages → Comptes connectés) : chaque utilisateur connecte **ses propres listes** pour que les assistants **consultent** ses tâches (« qu'ai-je à faire cette semaine ? »), en **ajoutent** et les **modifient** à sa demande — échéance, rappel horaire, importance, la marquer terminée. Connexion **distincte** d'Outlook et du calendrier Outlook.
  - **Même application Azure que le login** (aucune URI supplémentaire ; la connexion Microsoft n'a pas besoin d'être activée).
  - Côté portail Entra, sur **cette** inscription → **API autorisées** → **Microsoft Graph** → **Autorisations déléguées** : ajoutez `Tasks.Read` et `Tasks.ReadWrite`. Sans consentement à `Tasks.ReadWrite`, l'utilisateur obtient une connexion en **lecture seule** (badge **Création et modification de tâches —**).
  - **`Tasks.Read.Shared` / `Tasks.ReadWrite.Shared` ne sont jamais demandés** : MIA ne voit que les listes de la personne, pas celles que d'autres lui partagent.
  - **À savoir sur `Tasks.ReadWrite`** : c'est le seul droit Graph qui permette d'écrire une tâche, et il couvre aussi — côté API — la suppression. Contrairement à Google Tasks, MIA n'expose **aucun outil de suppression** : « c'est fait » marque la tâche terminée. Chaque création ou modification passe par la **carte d'approbation**.
  - **Fuseau horaire** : échéances et rappels sont posés dans le fuseau du **navigateur** de l'utilisateur. La liste **Emails marqués d'un indicateur** (remplie par Outlook) se consulte mais ne reçoit pas de tâche.
  - **Interrupteur global** : carte **Microsoft To Do** de la page **Connecteurs** (visible par défaut). Masqué, l'option **Tâches Microsoft To Do** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Comme Outlook, un compte **sans boîte Exchange Online** reçoit un message clair, et Microsoft n'expose aucune révocation de jeton délégué (l'utilisateur retire l'autorisation sur [myaccount.microsoft.com](https://myaccount.microsoft.com/)).

- **Microsoft Teams des utilisateurs** (Réglages → Comptes connectés) : chaque utilisateur connecte **son propre Teams** pour que les assistants **cherchent et lisent** ses conversations (privées, de groupe, de réunion) et les **canaux** de ses équipes (« qu'a dit Marie sur le budget ? », « résume le canal Projet Atlas / Général »), et **publient un message en son nom** à sa demande — dans une conversation, un canal, ou en réponse dans le fil d'un canal. Connexion **distincte** des autres connecteurs Microsoft.
  - **Même application Azure que le login** (aucune URI supplémentaire ; la connexion Microsoft n'a pas besoin d'être activée).
  - Côté portail Entra, sur **cette** inscription → **API autorisées** → **Microsoft Graph** → **Autorisations déléguées** : ajoutez `Chat.Read`, `ChatMessage.Send`, `Team.ReadBasic.All`, `Channel.ReadBasic.All`, `ChannelMessage.Read.All` et `ChannelMessage.Send`.
  - **Consentement administrateur OBLIGATOIRE.** `ChannelMessage.Read.All` (lire les messages des canaux) ne peut être accordé que par un **administrateur de l'annuaire**, pour toute l'organisation : Microsoft Entra → **Applications d'entreprise** → l'application → **Autorisations** → **Accorder un consentement administrateur**. Tant que ce n'est pas fait, **personne ne peut relier Teams** — Microsoft affiche « Approbation de l'administrateur requise » à l'utilisateur. Une fois accordé, chacun se connecte sans écran de consentement.
  - **`Chat.ReadWrite` n'est jamais demandé** : il ouvrirait aussi la modification et la suppression de messages. `ChatMessage.Send` et `ChannelMessage.Send` ne servent qu'à publier ; MIA n'expose **aucun outil** de modification ni de suppression, et chaque publication passe par la **carte d'approbation**, qui annonce que le message sera aussitôt visible.
  - MIA ne voit **que ce que voit la personne** : une équipe dont elle n'est pas membre n'existe pas pour l'assistant. Les **comptes Microsoft personnels** (outlook.com, hotmail) n'ont pas d'API Teams : le connecteur ne sert qu'aux comptes d'organisation disposant d'une licence Teams.
  - **Interrupteur global** : carte **Microsoft Teams** de la page **Connecteurs** (visible par défaut). Masqué, l'option **Messages Teams** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Comme les autres connecteurs Microsoft, aucune révocation de jeton délégué n'existe : l'utilisateur retire l'autorisation sur [myaccount.microsoft.com](https://myaccount.microsoft.com/).

- **Slack personnel des utilisateurs** (Réglages → Comptes connectés) : chaque utilisateur connecte **son propre Slack** pour que les assistants **cherchent et lisent** ses conversations (« qu'a-t-on dit du budget ? », « résume #projet-x ») et **publient un message en son nom** à sa demande.
  - **Une application Slack dédiée**, à créer une fois sur [api.slack.com/apps](https://api.slack.com/apps) : Slack n'étant pas un moyen de connexion à MIA, ses identifiants ne vivent pas dans **Organisation → Authentification** mais sur leur propre écran — carte **Slack** de la page **Connecteurs** → **Configurer l'application Slack**. La marche à suivre complète y figure : un **manifeste** prêt à coller (« Create New App → From a manifest ») qui déclare d'un coup l'URL de retour et les autorisations, un bandeau d'état (identifiants, visibilité, comptes reliés) et un bouton **Tester** qui valide les identifiants sans aller-retour de consentement.
  - **Ce que chaque autorisation ouvre** : l'écran dit, pour chacune, ce que MIA peut faire, l'outil donné au modèle et ce qui manque si l'utilisateur ne l'accorde pas — un consentement partiel donne un connecteur réduit, jamais cassé. Il montre aussi, dans la carte **Ce que reçoit le modèle**, la **consigne** ajoutée au prompt système quand Slack est allumé, la description des outils et la règle de sécurité commune aux connecteurs (un message lu est une donnée, jamais une instruction) : les textes exacts du code, pas une paraphrase.
  - **Une seule URL de retour à déclarer**, celle du reste de l'application (`/auth/callback`) : elle est affichée sur l'écran, à recopier telle quelle dans **OAuth & Permissions → Redirect URLs**. Un oubli donne l'erreur `bad_redirect_uri`, que le bouton **Tester** nomme.
  - **Des autorisations UTILISATEUR** (*User Token Scopes*), jamais de jeton de bot : MIA agit au nom de chaque personne et ne voit **que** les conversations dont elle est déjà membre. Un canal privé où elle n'est pas reste invisible, quoi que fasse l'assistant.
  - **À savoir sur `chat:write`** : c'est le seul droit qui permette de publier, et il couvre aussi — côté API — la modification et la suppression des messages de la personne. MIA n'expose **aucun outil** de ce genre : recherche, lecture et publication approuvée, rien d'autre. `chat:write.public` (publier dans un canal dont on n'est pas membre), `im:write` (ouvrir un message direct) et `users:read.email` ne sont **jamais** demandés.
  - **Approbation des applications** : sur un espace de travail qui l'exige, un administrateur Slack doit valider MIA — sans quoi la connexion des utilisateurs échoue ou revient en autorisations partielles (badges à tiret sur la page Comptes connectés).
  - **Rotation des jetons** facultative : MIA la gère si vous l'activez dans Slack, et s'en passe sinon.
  - **Interrupteur global** : carte **Slack** de la page **Connecteurs** (visible par défaut). Masquée, l'option **Messages Slack** du menu **+** disparaît pour tout le monde ; jetons conservés, accès rétabli dès réactivation.
  - Côté utilisateur : **opt-in explicite** (interrupteur du menu **+**, jamais activé d'office, puis mémorisé sur son navigateur) et **chaque publication passe par la carte d'approbation**, qui annonce que le message sera immédiatement visible. **Déconnecter** révoque aussi l'autorisation côté Slack, contrairement à Microsoft.

> **Jusqu'à trois inscriptions Microsoft, à ne pas confondre.** L'application du **login** (Organisation → Authentification) sert la connexion **et** les connecteurs Outlook, calendrier Outlook, To Do et Teams, **et OneDrive** quand il n'a pas d'inscription dédiée. L'inscription **OneDrive dédiée**, facultative (Capacités → Sources synchronisées), a sa propre URI de redirection et ne sert qu'aux bases. L'application **SharePoint** (même page) s'authentifie sans utilisateur, pour la synchronisation. Chaque connecteur demande son propre consentement : se connecter à MIA ne demande jamais de permission sur les fichiers.

---

## 12. Organisation — Entreprise, Marque & apparence

La rubrique **Organisation** regroupe trois entrées : **Entreprise** et **Marque & apparence** (cette section) et **Authentification** (section 13). La rubrique **Maintenance** en regroupe deux : **Diagnostic** (section 16 ; onglets **Contrôles**, **Journaux** — section 9 —, **Moteur vectoriel** — section 10 — et **Agent**) et **Données** (onglets **Sauvegarde** — section 17 — et **Élagage** — section 18).

Deux pages, deux sujets à ne pas confondre : **Entreprise** décrit l'organisation qui **utilise** MIA (ce que l'assistant sait d'elle, l'habillage des présentations) ; **Marque & apparence** habille l'**application** (son nom, ses logos, ses couleurs à l'écran). On peut garder « MIA » et ses couleurs à l'écran, et vouloir que les présentations portent le logo de l'entreprise. Chaque page s'enregistre seule et renvoie à l'autre.

### Entreprise

**Administration → Organisation → Entreprise**

La page se lit en six cartes : **Présentation** et **Habillage des documents** côte à côte, puis **Vocabulaire de l'entreprise** et **Ce que l'assistant lit**, puis **Modèle PowerPoint** et **Modèle Word** en pleine largeur (une seule colonne sur un écran étroit). C'est la première entrée de la rubrique **Organisation** du menu de l'administration.

- **Présentation** — **Nom de l'entreprise** et **Descriptif** (1 000 caractères : activité, clients, implantations, offre ; un compteur suit la saisie) : ajoutés au début de **chaque conversation**, pour tous les assistants et tous les modèles, régénérations comprises, et aux présentations de l'outil **Présentation**. MIA comprend ainsi *« notre offre »* ou *« nos clients »* sans explication, et ne cite l'entreprise que si c'est utile. C'est une **information**, pas une consigne : les sigles vont dans le **vocabulaire de l'entreprise** (plus bas), une consigne de comportement dans le prompt d'un assistant. Le nom sert aussi de pied de page aux présentations téléchargées.
  Des **rubriques** au-dessus du descriptif (Activité, Clients, Offre, Implantations, Chiffres clés, Organisation, Valeurs) ajoutent d'un clic la ligne « Rubrique : » ou y placent le curseur ; une rubrique remplie est cochée. **Partir d'un exemple** pose un descriptif type (industrie, services, association), après confirmation si un texte était déjà écrit.
- **Habillage des documents** — **Logo** (PNG ou JPG, idéalement sur fond transparent ; les autres formats ne s'affichent ni dans PowerPoint, ni dans Word, ni dans un PDF) : en-tête des documents Word et PDF, couverture et pied des présentations, quand aucun modèle n'est déposé ; sans logo, le nom de l'entreprise en tient lieu. Il ne remplace **pas** le logo de l'interface (barre latérale, page de connexion), qui reste celui de **Marque & apparence**. **Mention de pied de page** (une ligne, 200 caractères : raison sociale, adresse, SIRET, « Document interne ») : au pied de chaque page des documents Word et PDF et des présentations produits par MIA — exports d'une réponse compris —, avec le numéro de page ; vide, le nom de l'entreprise. Un modèle qui a son propre pied de page le garde.
- **Vocabulaire de l'entreprise** (venu de Modèles IA → Modèles, où il s'appelait « vocabulaire commun », repris tel quel) : un terme par ligne, `terme : définition`. Un vocabulaire **court** (moins de 2 000 caractères) part **en entier** à chaque message ; un vocabulaire **long** est **trié à chaque message** : seules les entrées dont le terme — ou le début de la définition — apparaît dans la conversation (message, derniers échanges, consignes de l'assistant) sont envoyées, avec les lignes sans définition (contexte général). Recherche sans majuscules ni accents, pluriel accepté, mots entiers seulement. L'assistant est prévenu qu'un sigle absent de la liste se demande plutôt que de s'inventer. Un glossaire de trois cents sigles ne coûte donc plus des milliers de jetons à chaque « merci ».
- **Ce que l'assistant lit** : le texte **exact** ajouté au début des conversations (présentation et vocabulaire) et son poids en jetons, tenu à jour pendant la saisie ; **Essayer avec une question** montre les entrées du vocabulaire retenues pour cette question. Rien n'est enregistré ni envoyé à un modèle.
- **Modèle PowerPoint** (.pptx ou .potx, 20 Mo au plus) : le modèle officiel de l'entreprise. Les présentations que les utilisateurs téléchargent **en PowerPoint depuis la conversation** sont alors construites **dans ce modèle** : ses mises en page, ses polices, ses couleurs, la mention de l'entreprise (ou son nom) en pied de page avec le numéro de slide, les notes du présentateur. Au dépôt, MIA **analyse** le fichier : format, nombre de mises en page, couleurs et polices du thème, puis le tableau **Comment MIA remplit votre modèle** — pour chaque forme de slide que MIA écrit (couverture, partie, puces et points clés, deux colonnes, comparaison, chiffres / tableau / étapes / chronologie dessinés aux couleurs du modèle), la mise en page du modèle qu'elle prend, **Trouvée** ou **À défaut** (pas de mise en page dédiée : MIA prend la plus proche) ; dessous, ce qui vient du modèle et ce que MIA ajoute. La carte d'une présentation dans le chat l'annonce : « au modèle de … ». **Les slides d'exemple du modèle ne sont pas reprises**, et le logo de l'entreprise n'y est pas ajouté (ses masques portent déjà les siens). Un modèle **refusé** (macros, ancien .ppt, fichier Word renommé, trop lourd) l'est avec son motif, et l'ancien reste en place. Quand un utilisateur fait réaliser la présentation par le modèle de la conversation (**Réaliser avec …** sur la carte, Claude et GPT récents seulement), le modèle PowerPoint part **en entier** chez le fournisseur (Anthropic ou OpenAI), **slides d'exemple comprises**, avec le logo de l'entreprise : c'est la **référence** du style maison, et le fichier rendu n'en garde aucune slide d'exemple. Partent aussi la demande de l'utilisateur et les fichiers qu'il a joints à la conversation ; tout est effacé chez le fournisseur une fois le fichier reçu. Le fichier réalisé est gardé 30 jours, la consommation comptée dans « Présentations et classeurs réalisés par le modèle » de la page **Consommation** (un classeur Excel peut être réalisé de la même façon). **Retirer** remet la mise en page de MIA — son thème en aplats, à la couleur d'accentuation de **Marque & apparence**. Le PDF « une page par slide » garde la mise en page de MIA, aux couleurs du modèle ; l'outil **Présentation** du menu **+** garde la sienne, et **Version soignée › Par Gamma** le thème choisi dans Gamma ; **Version soignée › Par un modèle** l'envoie en entier, comme référence du style maison — l'utilisateur voit cette ligne avant de lancer et peut la décocher. Au dépôt, la **miniature** enregistrée dans le modèle s'affiche (ou, à défaut, un aperçu indicatif à ses couleurs et polices) ; **Télécharger une présentation d'essai** fabrique, comme dans la conversation, une présentation qui passe par chaque mise en page — à ouvrir dans PowerPoint avant de prévenir les utilisateurs. Le fichier n'est pas accessible depuis le web et fait partie de la sauvegarde de la configuration (section 17).
- **Modèle Word** (.docx ou .dotx, 20 Mo au plus) : les documents Word que MIA produit — fichiers livrés dans la conversation, **Exporter en Word** d'une réponse — sont construits **dans ce modèle** : ses styles de titres (retrouvés quelle que soit la langue de Word) et de texte, ses polices, sa mise en page, ses en-têtes et pieds de page. Au dépôt, MIA affiche le format de page, les styles de titres retrouvés (**Titre**, **Titre 1** à **Titre 3** ; un style absent est remplacé par celui de MIA), l'en-tête, le pied de page, les couleurs et polices du thème. Le contenu d'exemple du modèle n'est pas repris, ses commentaires non plus, et une protection en écriture est levée. Un modèle **sans pied de page** reçoit celui de MIA (mention et numéro de page). **Télécharger un document d'essai** fabrique un document avec trois niveaux de titres, des listes, un tableau, un lien et une citation. **Retirer** remet la mise en page de MIA. Mêmes refus que pour PowerPoint (macros, ancien .doc, fichier d'un autre type, trop lourd), l'ancien modèle restant en place.

### Marque & apparence

**Administration → Organisation → Marque & apparence**

Elle ne porte que l'habillage de l'application — ce qu'on lit et ce qu'on voit. La page se lit sur **deux colonnes de cartes** qui occupent tout l'écran (une seule colonne sur un écran étroit) ; la mention « enregistré automatiquement » est en haut, à côté du titre.

- **Identité :** **Nom de l'application**, **Punchline (sous le nom)**, **Message d'accueil**, **Langue par défaut**. Chaque champ dit où il s'affiche.
- **Logos :** le **logo principal** et le **logo au survol** (facultatif), côte à côte, chacun prévisualisé sur un damier — la transparence se voit. **Changer**, **Ajouter** ou **Supprimer** ; l'aperçu se met à jour dès le choix du fichier.
- **Apparence :** le **thème par défaut** se choisit sur deux vignettes (sombre / clair) — c'est celui de toute personne qui n'en a pas choisi d'autre : chacun peut imposer le sien (clair, sombre, ou *Automatique* pour suivre son appareil), renforcer le contraste et agrandir l'interface dans **Réglages → Affichage**, sans effet pour les autres ; changer le thème par défaut ne touche pas ces choix personnels. En **contraste renforcé** et en thème clair, la couleur d'accentuation est affichée plus foncée, pour rester lisible sur fond blanc. La **couleur d'accentuation** au sélecteur ou parmi six pastilles. Un **aperçu** en bas de la carte montre la barre latérale (logo, nom, punchline) et un bouton dans le thème et la couleur choisis, **avant même l'enregistrement** — ce qu'on voit est ce qu'on aura. Ces choix habillent toute l'interface, **y compris les schémas Mermaid** rendus dans les réponses (nœuds, traits et fonds déclinés depuis la couleur d'accentuation ; les exports PNG/SVG suivent).
- **Typographie :** elle n'est pas réglable ici — c'est une constante de la marque. Toute l'interface est composée en **Lexend**, et le **nom de l'application** (le « MIA » de la barre latérale, de l'en-tête mobile et de l'écran de connexion) en **Congenial**. Les polices sont servies par l'application elle-même, jamais par un service extérieur : l'interface s'affiche à l'identique hors ligne et aucune requête ne part chez un tiers. Congenial étant sous licence commerciale, ses fichiers ne sont pas livrés avec le code — tant qu'ils n'ont pas été déposés sur le serveur, le nom de l'application s'affiche en Lexend (marche à suivre : `public/assets/fonts/README.md`).
- **Liens & contact :** **lien « Déclarer une idée ou un bug »** (pied de page du chat et page À propos), **adresse de contact**, **nom** et **site web de l'éditeur** (ces deux derniers forment le bloc « Contact » de la page À propos). Un champ vide masque simplement l'élément.
- **Application mobile** (carte pleine largeur, en bas) : le **lien public TestFlight** affiché sur la page d'installation `/app` (voir section 15). Vide = « Bientôt disponible » et installation PWA proposée à la place.

#### Langue de l'interface (français / anglais)

MIA s'affiche en **français** ou en **anglais**. Chaque utilisateur choisit la sienne avec le sélecteur en **haut à droite** de l'écran, et ce choix suit son compte sur tous ses appareils.

Le champ **Langue par défaut** ne tranche donc que pour ceux qui n'ont rien choisi. Trois valeurs :

| Valeur | Ce que voit un visiteur qui n'a jamais choisi |
|---|---|
| *Langue du navigateur, sinon français* (défaut) | sa langue si son navigateur est en anglais, sinon le français |
| 🇫🇷 Français | le français, quel que soit son navigateur |
| 🇬🇧 English | l'anglais, quel que soit son navigateur |

Changer ce réglage ne touche **aucun** choix déjà enregistré par un utilisateur : la valeur ne sert qu'à défaut.

> **Les textes livrés suivent la langue, les vôtres non.** Tant que vous laissez **Punchline** et **Message d'accueil** tels qu'ils sont livrés, ils s'affichent traduits : un anglophone est accueilli par « *Alex, how can I help you?* ». Dès que vous en réécrivez un, il s'affiche **tel que vous l'avez écrit**, dans toutes les langues — c'est votre texte, MIA ne le traduit pas à votre place. Pour retrouver la traduction automatique, remettez le texte d'origine (« Comment puis-je vous aider ? », « Votre assistant IA multi-modèles ») ou videz le champ. Si votre instance sert deux langues et que vous tenez à un message personnalisé, écrivez-le dans la langue de la majorité de vos utilisateurs.

**L'administration suit la langue de chacun** : menu, tableau de bord, chaque écran, messages et points à régler s'affichent dans la langue choisie par l'administrateur qui les consulte. Les réponses de MIA, le titre des nouvelles conversations et ce qu'elle prépare (tâches programmées, workflows, assistants) suivent la langue de la personne, sauf si elle écrit ou demande une autre langue.

**Ce qui reste en français, quelle que soit la langue choisie :**
- **ce que vous rédigez** : nom et description des assistants, descriptif et vocabulaire de l'entreprise, prompts système, ainsi que la punchline et le message d'accueil **si vous les avez personnalisés** (voir l'encadré ci-dessus). Ces textes sont éditoriaux et propres à votre instance ; MIA ne les traduit jamais à votre place ;
- **les contenus des utilisateurs** : titres de conversations, messages, souvenirs, présentations. Rien n'est retraduit après coup ;
- **l'assistant « MIA : mode d'emploi »**, qui s'appuie sur les guides livrés — rédigés en français. Il répond dans la langue de la personne, mais peut citer un intitulé de bouton en français ;
- **l'écran d'installation** et les **pages d'erreur fatale**, qui doivent s'afficher quand la base est injoignable, donc sans rien lui demander.

**L'application installée (PWA)** porte le nom et les couleurs de l'instance, dans la **langue par défaut** ci-dessus : son manifeste est servi sans session, il ne peut pas connaître la langue de la personne qui installe.

> Le **nom affiché de l'application** (« MIA ») se règle ici, dans le champ **Nom de l'application**.
> Le **modèle par défaut** et le **modèle de secours** se règlent dans **Modèles IA → Modèles**, carte **Comportement par défaut** (voir section 6) ; le **vocabulaire de l'entreprise**, dans **Organisation → Entreprise**.
> L'**activation automatique des nouveaux comptes** et le **domaine e-mail de l'équipe** se règlent dans **Personnes → Comptes** (voir section 3), les **identifiants de synchronisation** dans **Capacités → Sources synchronisées** (voir section 10), et les **moyens de connexion** dans **Organisation → Authentification** (voir section 13).

---

## 13. Organisation — Authentification

**Administration → Organisation → Authentification** *(admins seuls)*

Cette page décide de **comment on entre dans MIA**. Trois moyens, activables indépendamment : **Google**, **Microsoft** et **e-mail + mot de passe**. La page de connexion n'affiche que ceux qui sont à la fois **activés** et **renseignés** — un fournisseur sans identifiants n'apparaît jamais, plutôt que d'offrir un bouton qui mène à une erreur.

**Cette page ne s'enregistre pas toute seule** — c'est l'exception à la règle de l'[enregistrement automatique](#enregistrement-automatique). Chaque section (Google, Microsoft, e-mail et mot de passe, domaines autorisés, message de la page de connexion) porte son propre bouton **Enregistrer**, en bas à droite de sa carte, et **n'enregistre que sa section** : l'interrupteur, les identifiants et l'annuaire d'un fournisseur partent ensemble, les autres sections restent telles quelles. La confirmation s'affiche dans la section concernée. Rien ne part avant le clic : vous pouvez saisir, **Tester les identifiants**, corriger, puis enregistrer.

### URI de redirection

Affichée en haut de page, en lecture seule, avec un bouton **Copier**. C'est l'adresse à déclarer **telle quelle** dans la console Google *et* dans le portail Microsoft. **Une seule URI suffit pour tout** : les deux connexions et les huit connecteurs personnels (Drive, Gmail, Agenda, Tasks, Outlook, calendrier Outlook, Microsoft To Do, Microsoft Teams — section 11) y reviennent et se distinguent en interne.

### Google

1. Dans la [console Google Cloud](https://console.cloud.google.com/apis/credentials), créez un **ID client OAuth 2.0** de type **Application Web**.
2. Ajoutez-y l'URI de redirection affichée par la page, dans « URI de redirection autorisés ».
3. Recopiez l'**ID client** et le **secret client** ici, puis activez l'interrupteur.

Le secret est **stocké chiffré** et n'est jamais réaffiché : un champ laissé vide conserve la valeur enregistrée. Ces identifiants servent **aussi aux connecteurs personnels** Drive, Gmail, Agenda et Tasks (section 11) — les renseigner ici les active partout, y compris dans les traitements en arrière-plan.

> Si votre installation portait déjà ces identifiants dans le fichier `.env` du serveur, **rien ne change** : ils continuent de fonctionner, et la page vous le signale. Les saisir ici permet simplement de les modifier ensuite sans redéploiement.

### Microsoft

1. Dans le [portail Microsoft Entra](https://entra.microsoft.com/) → **Applications** → **Inscriptions d'applications**, inscrivez une application.
2. Ajoutez l'URI de redirection affichée par la page, en type **Web**.
3. Dans **Certificats & secrets**, créez un secret client et copiez sa **valeur** (pas son identifiant).
4. Renseignez l'**ID d'application (client)**, le **secret** et l'**annuaire**, puis activez l'interrupteur.

Ces identifiants servent **aussi au connecteur Outlook** (section 11) — et pour lui, **l'interrupteur de connexion n'a pas besoin d'être activé** : renseigner l'application suffit. Si vous comptez utiliser Outlook, ajoutez les autorisations déléguées `Mail.Read` et `Mail.ReadWrite` sur cette même inscription (**API autorisées** → **Microsoft Graph**) ; pour le **calendrier Outlook**, ajoutez-y `Calendars.Read` et `Calendars.ReadWrite` ; pour **Microsoft To Do**, `Tasks.Read` et `Tasks.ReadWrite` ; pour **Microsoft Teams**, `Chat.Read`, `ChatMessage.Send`, `Team.ReadBasic.All`, `Channel.ReadBasic.All`, `ChannelMessage.Read.All` et `ChannelMessage.Send` — ce dernier connecteur exige le **consentement d'administrateur**. La page les rappelle sous le bloc Microsoft — elle liste ce que chaque connecteur demandera, pour que vous puissiez tout déclarer en une fois et accorder le consentement d'administrateur si votre annuaire l'exige.

**Quand Microsoft refuse, MIA nomme la cause.** Un refus Entra vient rarement d'un identifiant mal recopié, et le message le dit désormais explicitement — sur la page de connexion comme au branchement d'un connecteur :

| Ce que dit Microsoft | Ce qu'il faut faire |
|---|---|
| Consentement manquant (`AADSTS65001`, `AADSTS90094`) | Votre annuaire interdit aux utilisateurs de consentir : **Applications d'entreprise → Autorisations → Accorder le consentement administrateur**, toutes les autorisations demandées comprises ; ensuite chacun relie son compte sans écran d'autorisation |
| Consentement refusé sur l'écran (`AADSTS65004`) | L'utilisateur a cliqué « Annuler » : relancer et accepter |
| Compte non autorisé (`AADSTS50105`) | L'application d'entreprise exige l'**attribution nominative** : ajouter la personne dans **Utilisateurs et groupes**, ou passer « Attribution requise » à Non |
| Compte hors annuaire (`AADSTS50020`) | Compte personnel ou invité d'une autre organisation : élargir l'annuaire, ou se connecter avec un compte de l'organisation |
| Redirection en « monopage » (`AADSTS9002326`) | L'URI a été déclarée sous **Application monopage (SPA)** : la retirer et l'ajouter sous **Web** |
| URI non déclarée (`AADSTS50011`) | Ajouter l'URI de redirection affichée en haut de page, plateforme **Web** |
| Secret refusé ou expiré (`AADSTS7000215`, `AADSTS7000222`, `AADSTS7000218`) | Coller la **valeur** du secret (pas son identifiant), ou en créer un nouveau ; vérifier que l'application n'est pas déclarée comme client public |
| Annuaire ou application introuvable (`AADSTS90002`, `AADSTS700016`) | Vérifier l'ID d'annuaire et l'ID d'application |

Sur la **page de connexion**, le message reste volontairement générique (il ne doit pas exposer la réponse du fournisseur) mais distingue les deux familles : « votre organisation n'a pas autorisé cette application » d'un côté, un refus à investiguer de l'autre. Le **code AADSTS exact figure dans les logs** (Maintenance → Diagnostic → Journaux).

**Annuaire (locataire)** — le champ qui compte :

| Valeur | Qui peut se connecter |
|---|---|
| `common` *(défaut)* | tout compte Microsoft, professionnel ou personnel |
| `organizations` | comptes professionnels uniquement |
| **ID de votre annuaire** | votre organisation seule |

> **Sur un annuaire ouvert (`common` ou `organizations`), Microsoft ne garantit pas qu'une adresse e-mail appartient à la personne qui se connecte** : n'importe qui peut créer un annuaire et y déclarer l'adresse de son choix. MIA refuse donc de rattacher une connexion Microsoft à un compte existant dans ce cas, et affiche un message explicite. Trois façons de lever la limite : renseigner **l'ID de votre annuaire** (recommandé), déclarer vos **domaines autorisés** (ci-dessous), ou activer le champ facultatif `xms_edov` sur l'application (Entra → **Configuration du jeton** → ajouter le champ facultatif `email`, puis cocher l'option).

### E-mail et mot de passe

L'interrupteur affiche ou masque le formulaire de connexion. Les mots de passe se définissent **compte par compte** dans **Personnes → Comptes** (section 3) : création d'un utilisateur avec mot de passe, ou **Réinitialiser le mot de passe** sur une fiche existante. La carte rappelle combien de comptes actifs en disposent.

### Domaines autorisés

Un domaine par ligne. Seules les adresses de ces domaines peuvent **créer un compte** par Google ou Microsoft. Laissez vide pour n'imposer aucune restriction.

- La règle ne s'applique qu'à la **création** : un compte déjà existant continue de se connecter même si son domaine sort de la liste. Modifier ce champ n'éjecte donc jamais personne.
- Déclarer vos domaines vaut **reconnaissance** : une connexion Microsoft venant d'un de ces domaines peut alors rejoindre un compte existant.
- L'**activation automatique** des comptes ainsi créés se règle dans **Personnes → Comptes** (section 3). Sans elle, un nouveau compte arrive en attente de validation.

### Message sur la page de connexion

Un **texte court** (500 caractères au plus), affiché sur la page de connexion **sous le nom de l'application et avant les boutons** : mot de bienvenue, consigne d'accès (« réservé aux collaborateurs »), adresse à contacter pour obtenir un compte. **Vide par défaut** — tant que vous n'écrivez rien, la page de connexion reste telle quelle.

- Les **retours à la ligne** sont conservés ; un compteur suit la longueur, et un **aperçu** à droite du champ montre le résultat tel qu'il s'affichera.
- C'est **votre texte** : il s'affiche tel que vous l'écrivez, dans toutes les langues (comme une punchline personnalisée, section 12).
- Le style suit la couleur d'accentuation de l'instance, d'un simple filet — il reste lisible quel que soit le thème.

### Tester les identifiants

Chaque fournisseur porte un bouton **Tester les identifiants**, qui vérifie l'annuaire, l'ID client et le secret **sans ouvrir d'écran de connexion et sans rien enregistrer** — les valeurs que vous venez de saisir sont utilisées telles quelles, avant même d'avoir cliqué **Enregistrer**. Les erreurs sont nommées : secret invalide, secret expiré, application inconnue de l'annuaire, ou URI de redirection non déclarée (la panne la plus fréquente).

### On ne peut pas se verrouiller dehors

Si l'enregistrement d'une section devait laisser **aucun** moyen de connexion utilisable — compte tenu de ce qui est enregistré dans les autres —, MIA réactive d'office la connexion par mot de passe et vous le signale dans la section concernée. Par ailleurs, si un **accès de secours** est configuré sur le serveur (`LOCAL_PASSWORD_HASH`), il reste disponible quoi qu'il arrive, sous « Connexion admin locale » en bas de la page de connexion : c'est le recours si une connexion externe cesse de fonctionner.

---

## 14. Sécurité

- **Cloisonnement** : chaque conversation est strictement réservée à son utilisateur — aucune fuite entre comptes.
- **Chiffrement** : toutes les clés d'accès sont chiffrées.
- **Protection des formulaires** sur toutes les actions de modification.
- **Sessions** sécurisées (et HTTPS en production).
- **Messages d'erreur** : les détails techniques bruts des fournisseurs ne sont jamais montrés à l'utilisateur — seul un message clair s'affiche.
- **Contenus lus par les connecteurs = des données, jamais des consignes** : chaque email, fichier, message ou évènement rapporté par un connecteur arrive au modèle avec un avertissement, et une règle du prompt interdit d'obéir à une instruction qu'il contiendrait (demande d'envoi, de transfert, de lien à ouvrir…). Aucune consigne n'étant infaillible face à un modèle, le vrai verrou reste la carte d'approbation : aucune écriture ne part sans le clic de l'utilisateur.
- **Images d'autres sites bloquées jusqu'au clic** dans les réponses : une image se charge d'elle-même à l'affichage, et une adresse piégée pourrait emporter des informations vers le site qui l'héberge. L'utilisateur voit un bouton « Afficher » qui nomme le site. Les copies et les exports PDF/Word remplacent toute image externe par un lien.
- **Fichiers créés dans une conversation** (Excel, CSV, Word, PDF, RTF, texte…) : fabriqués **à la demande**, depuis le contenu affiché dans la conversation, par un utilisateur connecté — **rien n'est stocké**, ni sur le disque ni en base, et il n'y a rien à configurer ni à sauvegarder. Dans un classeur, une formule qui irait ouvrir un lien, un service web ou un autre fichier est écrite comme du **texte**, et dans un CSV une cellule qu'un tableur exécuterait est désamorcée : un tableau recopié d'une page web ou d'un email ne peut pas piéger celui qui l'ouvre.

---

## 15. Application mobile (iOS / Android)

MIA existe en **application mobile** (dossier `mobile/` du projet). Principe : l'application charge directement le **site web déployé** dans une vue embarquée — **chaque déploiement web est donc répercuté instantanément dans l'application**, sans re-publication sur les stores. Seuls les changements *natifs* (icônes, permissions, plugins) demandent un nouveau build.

### Configuration serveur

- **`GOOGLE_CLIENT_ID_IOS`** (`.env`) : client OAuth **iOS** créé dans la console Google Cloud (même projet que le client web existant), bundle `ai.myalfred.myllm`. Requis pour la connexion Google depuis l'app iOS.
- **Android** : créer aussi un client OAuth **Android** dans la console (package `ai.myalfred.myllm` + empreintes SHA-1 des clés de signature *debug et release*). Aucune variable serveur : les connexions Android sont vérifiées avec le client **web** existant (`GOOGLE_CLIENT_ID`).
- La connexion Google de l'app passe par `POST /auth/google-token` (vérification du jeton Google côté serveur, mêmes règles de validation de compte que le web : un compte inactif est refusé).
- **Erreur « connexion Google (côté serveur) … [HTTP 403] » dans l'app** : l'écran de connexion était ouvert depuis avant une mise à jour du serveur (un redéploiement efface les sessions) — son jeton anti-CSRF ne valait plus rien. L'app récupère désormais un jeton frais et réessaie toute seule ; si le message revient malgré tout, fermer et rouvrir l'application. Le rejet est tracé dans les logs (`[CSRF] Rejet POST … — jeton absent|périmé`).
- Les sessions durent **7 jours** (web comme mobile).

### Distribution (interne)

- **Page publique d'installation `/app`** (hors connexion, partageable, QR codes) : bouton APK Android + bouton TestFlight iPhone.
- **Android — publication automatique** : pousser un tag `mobile-v*` (ou lancer l'action GitHub **Build mobile Android**) → l'APK est construit puis déposé sur le VPS recette (`public/downloads/mia.apk`, monté dans le conteneur) → le bouton de la page `/app` s'active tout seul. APK signé si les secrets `ANDROID_KEYSTORE_*` sont configurés, sinon APK debug (installable).
- **iOS** : build et envoi **TestFlight** depuis Xcode (procédure pas à pas dans `mobile/README.md`), testeurs gérés dans App Store Connect. Activer le **lien public** TestFlight puis le coller dans **Organisation → Marque & apparence**, carte **Application mobile**, champ **Lien public TestFlight** : le bouton iPhone de `/app` s'active.
- L'application vise la **production** ; un geste caché (7 taps sur le logo de l'écran de connexion) bascule sur la **recette** (badge « STAGING » affiché). Tant que la production n'est pas déployée, l'application pointe directement la recette (badge « STAGING » visible en permanence, geste désactivé).

> Publication **publique** App Store (plus tard) : Apple exige d'ajouter « Sign in with Apple » dès qu'un login Google est proposé.

---

## 16. Maintenance — Diagnostic

**Administration → Maintenance → Diagnostic** réunit quatre onglets : **Contrôles** (ci-dessous), **Journaux** (section 9), **Moteur vectoriel** (section 10) et **Agent** (ci-dessous). L'onglet **Contrôles** affiche l'état réel de l'installation : une vingtaine de
contrôles regroupés par thème, chacun avec sa valeur, et — pour ceux qui ne sont pas au vert —
la conséquence concrète et le remède.

- **Socle technique** : version de PHP, extensions installées, dépendances, répertoires de
  stockage inscriptibles.
- **Configuration** : longueur des secrets, validité du mot de passe de secours, cohérence de
  l'adresse publique avec l'adresse réellement servie.
- **Base de données** : connexion, migrations appliquées.
- **Services** : worker de générations, base vectorielle, modèles disponibles, vectorisation,
  accès Internet sortant, connecteurs Google, outils actifs dont le modèle est arrêté par son
  fournisseur (ou va l'être — avec la date), **budget IA mensuel** (▲ au seuil d'alerte, au
  plafond, quand la projection le dépasse ou quand des opérations sans tarif le sous-estiment ;
  jamais ✕), empreinte disque.

Trois niveaux : **✓** tout va bien, **▲** à surveiller (l'application fonctionne, une
fonctionnalité est dégradée), **✕** à corriger.

Le bouton **Copier le rapport** produit un résumé en texte brut, remèdes compris, à joindre tel
quel à une demande d'assistance.

Deux points méritent une attention particulière, car ils échouent en silence :

- **Le worker sur une version différente de l'application.** Il paraît vivant mais fait échouer
  tous les types de générations ajoutés depuis. Le diagnostic le signale explicitement.
- **L'absence d'accès Internet sortant du worker.** Toutes les générations échouent, alors que
  les tests de la page Modèles IA restent au vert — ils partent du serveur web, pas du worker.

Les mêmes contrôles sont disponibles en ligne de commande (`php scripts/doctor.php`) et sous
forme de sonde automatique consultée par l'hébergeur.

### Onglet Agent

L'onglet **Agent** mesure ce que fait MIA quand elle se sert de ses outils et connecteurs, sur
**7, 30 ou 90 jours** :

- **Indicateurs** : réponses passées par les outils (et leur part dans toutes les réponses),
  allers-retours moyens avec le modèle, appels d'outils, taux d'erreur, réponses qui ont atteint le
  **budget d'allers-retours** (l'utilisateur voit alors **Continuer**), appels identiques bloqués et
  noms d'outils inexistants, part de l'entrée relue du **cache**, durée moyenne, bascules sur le
  modèle de secours.
- **À surveiller** : des phrases qui nomment ce qui mérite un coup d'œil — outil qui échoue dans plus
  d'un appel sur cinq, outil qui met plus de 10 s, cartes d'une action refusées plus d'une fois sur
  deux ou restées sans décision, budget souvent atteint. Rien en deçà de dix cas.
- **Par outil** (appels, erreurs, durée moyenne et maximale de l'outil seul), **Cartes
  d'approbation** (proposées, approuvées, refusées, en échec à l'exécution, sans décision au-delà de
  30 minutes, délai moyen, taux d'approbation) et **Par modèle**.

**Aucun contenu n'est enregistré** : ni question, ni réponse, ni nom de fichier, ni identifiant de
personne ou de conversation — des nombres, des noms d'outils et le modèle. Les mesures sont gardées
180 jours, effacées ensuite au fil de l'eau, et ne font pas partie de la sauvegarde. Le devenir des
cartes vient des cartes elles-mêmes : celles des conversations supprimées ne sont plus comptées.

---

## 17. Maintenance — Données : sauvegarde

L'entrée **Données** de la rubrique **Maintenance** réunit deux onglets : **Sauvegarde** (ci-dessous) et **Élagage** (section 18).

**Administration → Maintenance → Données → Sauvegarde**

Exporte ou restaure la **configuration complète** de MIA en une archive ZIP.

**Exporter la configuration.** Le bouton **Télécharger la sauvegarde** produit une archive contenant :
- les **utilisateurs** : comptes, rôles, quotas, mémoires « MIA pour vous », connexions Google ;
- les **groupes** et tous leurs droits (assistants, outils, modèles autorisés) ;
- les **assistants** : réglages, documents de référence, liaisons aux bases et aux outils ;
- les **bases de connaissances** : documents (fichiers inclus), extraits, partages, configuration de synchronisation ;
- les **outils de génération**, les **clés API** (chiffrées) et les **modèles activés** ;
- les **paramètres de l'application** (identité visuelle, crédits, connecteurs…), avatars, logos et modèle PowerPoint de l'entreprise compris.

Ne sont **pas** sauvegardés : les conversations et leurs médias, l'historique de consommation,
les résultats de tests et les tâches en cours. Les vecteurs de recherche (Qdrant) ne voyagent pas
non plus : ils sont **reconstruits automatiquement** après une restauration.

> **Toujours à jour.** Le module n'énumère pas ce qu'il exporte : il embarque **tout le schéma**
> sauf une courte liste d'exclusions. Une donnée ajoutée par une future version de MIA est donc
> incluse d'office, sans intervention.

> **Secrets et clé de chiffrement.** L'archive contient les clés API et jetons de connexion
> **chiffrés avec la clé de l'instance** (`ENCRYPTION_KEY`). Restaurée sur une instance portant la
> même clé, tout fonctionne à l'identique ; sur une instance dont la clé diffère, ces secrets
> seront illisibles et devront être re-saisis. Conservez l'archive en lieu sûr.

**Restaurer une sauvegarde.** Choisir l'archive, taper **REMPLACER** dans le champ de
confirmation, puis **Restaurer**. La restauration **remplace** la configuration actuelle
(tout-ou-rien : en cas d'erreur, rien n'est modifié). Les conversations existantes sont
conservées. À l'issue, chaque base de connaissances est réindexée en arrière-plan et un
récapitulatif s'affiche (tables et fichiers restaurés). Une sauvegarde issue d'une version **plus
récente** de MIA est refusée — mettez d'abord l'application à jour ; une sauvegarde plus ancienne
est acceptée.

---

## 18. Maintenance — Données : élagage

**Maintenance → Données → Élagage** (`/admin/pruning`) sert à faire de la place : supprimer les
conversations et les créations trop anciennes, et récupérer l'espace disque qu'elles occupent.

> **Rien ne s'exécute automatiquement.** Aucun élagage n'a lieu sans un clic de votre part.
> Les durées se règlent d'un côté, s'appliquent de l'autre.

### État des lieux

Cinq indicateurs, recalculés à chaque visite de la page (mesure mise en cache 10 minutes, un lien
**↻ Recalculer** force le recalcul) :

| Indicateur | Ce qu'il mesure |
|---|---|
| **Conversations** | nombre total, tous utilisateurs confondus, et volume de leurs messages |
| **dont épinglées** | celles qui ne seront **jamais** élaguées |
| **Créations** | images, vidéos, pages web, présentations et itinéraires générés |
| **Médias sur disque** | ce que pèsent réellement les fichiers (images, vidéos, pièces jointes) |
| **dont orphelins** | fichiers que plus aucun message ne référence |

> **La taille de la base ne diminue pas après une suppression, et c'est normal.** MySQL réutilise
> l'espace libéré pour les données suivantes au lieu de le rendre au système d'exploitation.
> L'espace disque des **médias**, lui, est bien rendu immédiatement.

### Ancienneté à conserver

Deux durées, en jours, **enregistrées automatiquement** (120 par défaut, de 1 à 3650) :

- **Conversations** — comptées depuis la **dernière activité**, pas depuis la création. Une
  conversation ouverte il y a un an mais utilisée hier n'est jamais concernée.
- **Créations** — comptées depuis la date de génération.

> **« Favori » = conversation épinglée.** Une conversation épinglée par son propriétaire (icône
> punaise dans la liste des conversations, 5 maximum par personne) est **exclue de l'élagage**.
> C'est le seul moyen pour un utilisateur de mettre une conversation à l'abri : dites-le-lui avant
> un premier élagage.

Si vous changez une durée sans recharger la page, les estimations affichées sous chaque bouton
s'estompent et proposent un recalcul : elles datent d'avant votre modification. Le rapport
d'élagage rappelle toujours le seuil réellement appliqué.

### Élaguer les conversations

Supprime **définitivement** les conversations sans activité depuis N jours, avec leurs messages,
leurs créations et leurs fichiers. Sont automatiquement épargnées :

- les conversations **épinglées** ;
- celles qui portent un message récent (même si leur date d'activité paraît ancienne) ;
- celles dont une **génération est en cours** — la supprimer casserait le travail du worker.

**Ne sont pas affectés** : l'historique de consommation, les crédits et les quotas. Les statistiques
d'usage restent justes après un élagage.

### Élaguer les créations

Supprime les médias générés il y a plus de N jours **sans casser les conversations**. Le message
n'est pas supprimé : son contenu est remplacé par une mention « *Création élaguée le …* », le
fichier part du disque et le lien de partage est révoqué. Le fil reste continu — ni image cassée,
ni bouton mort, ni lien `/partage` en erreur.

Quand une réponse rédigée accompagne les images (l'assistant a expliqué son travail), **le texte
est conservé** : seules les images sont remplacées par une mention.

### Nettoyer les fichiers orphelins

Supprime du disque les médias que plus aucun message ne référence — notamment ceux des
conversations que les utilisateurs ont supprimées eux-mêmes. C'est souvent le poste le plus lourd
d'une installation ancienne. Ce balayage tourne **aussi automatiquement** à la fin des deux
élagages ci-dessus.

Les fichiers de **moins de 24 heures** sont toujours conservés : une génération peut être en cours.
Si le rapport signale des suppressions refusées, vérifiez les droits d'écriture des répertoires de
médias.

### Précautions

- **Chaque action demande de taper `ELAGUER`** dans un champ de confirmation.
- **Un élagage est définitif.** La sauvegarde de MIA ne couvre **pas** les conversations
  (cf. [Sauvegarde](#17-maintenance--données--sauvegarde)) : seule une sauvegarde MySQL prise en dehors de l'application
  permettrait de revenir en arrière. Prenez-en une avant un premier élagage massif.
- **Un élagage volumineux s'arrête au bout de quelques dizaines de secondes** et annonce ce qu'il
  reste à traiter. Relancez-le autant de fois que nécessaire : chaque passage reprend là où le
  précédent s'est arrêté.
- Deux élagages ne peuvent pas tourner en même temps ; un second clic est refusé poliment.
