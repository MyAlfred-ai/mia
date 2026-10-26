# Guide utilisateur — MIA

> **MIA** réunit plusieurs intelligences artificielles derrière une interface unique. Ce guide s'adresse à l'**utilisateur** : discuter, générer du contenu, gérer ses préférences et son quota.
>
> Les indications renvoient à ce qui est **affiché à l'écran** : noms de pages, libellés de menus, intitulés de boutons. Voir aussi le **guide créateur** (créer des assistants) et le **guide administrateur** (configurer la plateforme).

---

## Table des matières

1. [Concepts de base](#1-concepts-de-base)
2. [Connexion](#2-connexion)
3. [Interface générale](#3-interface-générale) — dont [Changer la langue](#changer-la-langue-français--anglais)
4. [Démarrer et mener une conversation](#4-démarrer-et-mener-une-conversation)
5. [Pièces jointes](#5-pièces-jointes)
6. [Connecteurs (Internet et données personnelles)](#6-connecteurs-internet-et-données-personnelles)
7. [Dictée vocale et conversation vocale](#7-dictée-vocale-et-conversation-vocale)
8. [Choisir un assistant ou un modèle](#8-choisir-un-assistant-ou-un-modèle)
9. [Génération d'images](#9-génération-dimages)
10. [Génération de pages HTML](#10-génération-de-pages-html)
11. [Génération de vidéos](#11-génération-de-vidéos)
12. [Cartes et itinéraires](#12-cartes-et-itinéraires)
13. [Présentations (diaporamas)](#13-présentations-diaporamas)
14. [Partager une création par lien](#14-partager-une-création-par-lien)
15. [Schémas et diagrammes](#15-schémas-et-diagrammes)
16. [Actions sur les messages](#16-actions-sur-les-messages) — dont [Télécharger un fichier créé par MIA](#télécharger-un-fichier-créé-par-mia)
17. [Gérer ses conversations](#17-gérer-ses-conversations)
18. [Réglages personnels](#18-réglages-personnels) — dont [Tâches programmées](#tâches-programmées--une-demande-qui-se-répète-toute-seule)
19. [Crédits et quota](#19-crédits-et-quota)
20. [FAQ et dépannage](#20-faq-et-dépannage)
21. [Installer MIA comme application (PWA)](#21-installer-mia-comme-application-pwa)
22. [Application mobile MIA (iOS / Android)](#22-application-mobile-mia-ios--android)

---

## 1. Concepts de base

| Terme | Signification |
|---|---|
| **Conversation** | Un fil de discussion. Soit **standard** (modèle libre), soit liée à un **assistant**. |
| **Assistant** | Personnalité préconfigurée : rôle, ton, modèle imposé, documents de référence, outils. |
| **Modèle** | Le moteur d'IA qui répond (ex. Claude, GPT, Mistral). |
| **Crédit** | Unité de consommation. Chaque réponse / génération consomme des crédits. |
| **Quota** | Plafond mensuel de crédits. Remis à zéro le 1er du mois. |
| **Outil** | Capacité de génération : image, vidéo ou page HTML. |
| **Rôle** | Ce que votre compte peut faire : relier vos comptes, programmer des tâches, créer des assistants… Il est fixé par un administrateur. Vos **groupes**, eux, décident des assistants, outils et modèles qui vous sont ouverts. |

> **Une question sur MIA ? Demandez-la à MIA.** L'assistant **« MIA : mode d'emploi »** est proposé à **tout le monde** dans la liste des assistants. Posez-lui n'importe quelle question sur l'usage de la plateforme (« comment joindre un fichier ? », « comment changer mon style de réponse ? », « pourquoi mon envoi est bloqué ? »). Il répond en s'appuyant sur les guides officiels, tenus à jour à chaque nouvelle version de MIA.

---

## 2. Connexion

Sur la **page de connexion**, jusqu'à quatre façons d'entrer. **Votre administrateur choisit celles qui sont proposées** : la page n'affiche que les moyens réellement disponibles, il est donc normal de n'en voir qu'un ou deux.

1. **Continuer avec Google** — bouton blanc au logo Google.
2. **Continuer avec Microsoft** — bouton blanc au logo Microsoft (comptes professionnels ou personnels, selon le réglage de votre organisation).
3. **E-mail + mot de passe** — saisir l'e-mail et le mot de passe (icône en forme d'œil pour afficher le mot de passe).
4. **Connexion admin locale** — repliée sous le lien « **Connexion admin locale ›** », réservée au dépannage.

Une même personne peut utiliser **Google et Microsoft indifféremment** : si les deux comptes portent la même adresse e-mail, ils mènent au même compte MIA, avec les mêmes conversations.

**Messages d'erreur courants :**

| Message à l'écran | Cause |
|---|---|
| « Votre compte est en attente de validation par un administrateur. » | Compte pas encore activé. |
| « Email ou mot de passe incorrect. » | Identifiants erronés. |
| « Trop de tentatives. Réessayez dans 15 minutes. » | Sécurité anti-intrusion. |
| « Erreur lors de la connexion. » | Échec côté fournisseur (Google ou Microsoft), réessayer. |
| « Votre adresse e-mail n'appartient pas à un domaine autorisé sur cette application. » | Votre organisation restreint les inscriptions à certains domaines. Demander à un administrateur. |
| « Un compte existe déjà avec cette adresse. » | Un compte MIA porte déjà cette adresse, créé par un autre moyen. Se connecter avec la méthode habituelle, ou demander à un administrateur de rattacher ce mode de connexion. |
| « Ce moyen de connexion n'est plus disponible. » | Un administrateur vient de le désactiver. En utiliser un autre. |
| « Session expirée. Merci de réessayer. » | La page de connexion était ouverte depuis trop longtemps (ou le serveur a été mis à jour entre-temps). La page vient d'être rafraîchie : réessayer directement. |

> **Session expirée ?** MIA le détecte **d'elle-même** — dès le retour sur l'onglet et par une vérification régulière — sans attendre que l'envoi d'un message échoue. Une fenêtre « Session expirée » s'affiche alors avec le bouton « Se reconnecter » (redirection automatique au bout de quelques secondes). **Le message en cours d'écriture n'est pas perdu** : la fenêtre le confirme, et le texte est restauré dans le champ de saisie après reconnexion (il est conservé dans ce navigateur, par conversation, jusqu'à son envoi réussi).

---

## 3. Interface générale

**Barre latérale gauche :**
- Logo + nom de l'application en haut
- Le bouton **Nouvelle conversation** (aux couleurs du site) — un clic ramène directement à l'**écran d'accueil** pour démarrer (plus de fenêtre intermédiaire) — et, juste dessous dans le même bloc, le bouton « **Rechercher ou aller à…** » : une seule barre pour retrouver une page, une action ou une conversation (titre et contenu) ; **⌘K** (**Ctrl+K** sur Windows et Linux) l'ouvre depuis n'importe où, comme la tuile **Tout** des Espaces
- Sous ce bloc, la rangée **Espaces** : des tuiles qui ouvrent vos pages de travail, selon vos droits — **Assistants**, **Bases** (les bases de connaissances), **Tâches** (les tâches programmées), **Workflows** (en bêta) et **Créations** (vos créations). Tout le monde y voit au moins **Tâches** (si votre administrateur les propose) et **Créations** ; **Assistants**, **Bases** et **Workflows** sont réservés par défaut aux créateurs et aux administrateurs
- **Historique** des conversations, regroupé : les *Épinglées* dans leur propre cadre, en tête, avec leur nombre sur le maximum (par exemple **2/5**), puis *Aujourd'hui*, *Hier*, *7 derniers jours*, *30 derniers jours* et un groupe par mois — chaque période ouverte par son nom, prolongé d'un trait fin ; une pastille de couleur devant un titre rappelle l'assistant de la conversation, et la conversation ouverte est surlignée en gris
- Tout en bas : une carte avec votre avatar, votre nom et vos **crédits restants** (barre colorée), puis deux boutons — **Réglages**, pour tout le monde, qui mène à vos réglages personnels, et **Admin**, pour qui administre, qui mène à l'administration (voir ci-dessous)

**Zone de discussion (au centre) :** la barre du haut (infos du modèle — elle disparaît en conversation avec un assistant, qui se présente alors dans la pilule de saisie, voir [§ 8](#8-choisir-un-assistant-ou-un-modèle)), les messages, et le **composer** en bas — une **pilule de saisie** arrondie (avec un léger halo de la couleur du site, ou de la **couleur de l'assistant** quand un assistant est engagé) qui regroupe tout :
- **en haut de la pilule**, le champ de texte, sur toute la largeur ;
- **en bas de la pilule, à gauche** : le bouton **+** (un seul menu, une ligne par entrée : en tête **Joindre des fichiers** — **Fichiers** et **Photo** sur téléphone —, puis **Créer** — image, page web, site illustré, itinéraire, chacun avec son coût estimé en crédits — et **Sources** — l'interrupteur **Recherche Internet**, puis le volet **Connecteurs**, replié à chaque ouverture du menu : **Laisser MIA choisir**, **Fichiers Google Drive**, **Emails Gmail**, **Agenda Google**, **Tâches Google**, **Emails Outlook**, **Agenda Outlook**, **Tâches Microsoft To Do**, **Messages Teams** et **Messages Slack**, et une ligne **Relier N services** pour ceux qui ne sont pas encore reliés. Replié, le volet montre combien de connecteurs sont allumés et leurs logos ; menu fermé, ces logos s'affichent à côté du **+**, et un clic y rouvre le volet — sur téléphone, leur nombre s'affiche sur le **+**, pour que les boutons assistants et bases restent toujours visibles. Les descriptions s'affichent au survol) et l'icône **tête de robot** (démarrer avec un assistant — le raccourci **@** dans le champ de saisie ouvre la même liste, qui propose aussi, pour un créateur ou un administrateur, les **workflows** à **lancer** depuis le chat) ;
- **en bas de la pilule, à droite** : le **sélecteur de modèle** (logo + nom + flèche), le **micro** (si la dictée est activée) et le bouton d'**envoi** (avion en papier).

**Écran d'accueil :** une page épurée avec le logo, une salutation personnalisée (« *Prénom*, comment puis-je vous aider ? ») et le composer au centre. Écrire un message et l'envoyer démarre directement une conversation standard. Depuis n'importe quelle page (conversation, réglages, administration, À propos), cliquer le **logo** en haut de la barre latérale y ramène.

**Pied de page (sous la conversation) :** le **numéro de version** — cliquable, il ouvre les **Nouveautés** (voir ci-dessous) —, le lien **Déclarer une idée ou un bug** et le lien **À propos**. Sur mobile, il tient sur **une seule ligne compacte** (libellés raccourcis).

**Les nouveautés :** cliquer le **numéro de version** — en bas de la conversation, ou sur la page **À propos** — ouvre la liste des **20 dernières nouveautés**, de la plus récente à la plus ancienne. Chacune tient en un titre et une à trois phrases, avec sa date et une pastille qui dit s'il s'agit d'une **Nouveauté**, d'une **Amélioration** ou d'un **Correctif** ; celles qui ne concernent que l'administration ou la création d'assistants le précisent. Un **point orange** sur le numéro de version signale qu'une nouveauté n'a pas encore été ouverte sur ce navigateur ; il s'éteint à l'ouverture de la liste. Vous pouvez aussi poser la question à l'assistant **MIA : mode d'emploi** (« quoi de neuf ? », « depuis quand peut-on… ? »), qui connaît la même liste.

**La page À propos :** accessible depuis le pied de page, depuis **À propos de MIA** en bas du menu de vos réglages, et depuis la barre « **Rechercher ou aller à…** » (**⌘K**), elle résume ce que MIA sait faire (modèles, Internet, assistants, bases de connaissances, connecteurs, actions enchaînées, créations, voix, sécurité) et renvoie vers la documentation configurée par l'administrateur (bouton **Consulter la documentation** — l'assistant **MIA : mode d'emploi** répond aux questions rapides sans quitter le chat), vers le formulaire **Déclarer une idée ou un bug** et vers les **Nouveautés**. En bas, l'éditeur de l'application : origine du projet, contact, informations société.

**Bas de la barre latérale — Réglages et Admin :** sous la carte de votre compte (avatar, nom, crédits restants), deux boutons. **Réglages** mène directement à la page **Mon profil** de vos réglages. Le menu de gauche de ces pages porte la rubrique **Mon compte** — **Mon profil**, **Préférences IA**, **Affichage**, **Comptes connectés** et **Usage & crédits** —, puis, plus bas, **À propos de MIA** et **Se déconnecter** (avec confirmation). **Admin** n'apparaît que pour qui administre au moins une partie de l'instance ; il porte une pastille avec le nombre de points à régler, et mène directement à l'**Administration** — le **Tableau de bord**, ou la première page que vos droits ouvrent. Son menu de gauche porte le **Tableau de bord** puis six rubriques repliables — **Personnes**, **Modèles IA**, **Capacités**, **Coûts**, **Organisation**, **Maintenance** —, chacune avec ses pages et sa pastille de points à régler. Ce même bas de barre figure sur **toutes** les pages (chat, réglages, administration, à propos), la barre latérale garde partout la même largeur, et **Retour au chat**, en tête du menu de gauche, ramène à vos conversations.

**Depuis la barre latérale, vos espaces s'ouvrent sans la quitter :** les tuiles **Assistants**, **Bases**, **Tâches**, **Workflows** et **Créations** ouvrent leur page en gardant la barre latérale du chat — vos conversations, la recherche et **Nouvelle conversation** — avec la tuile de la page ouverte mise en évidence. Ces pages ne figurent dans aucun autre menu.

**Masquer la barre latérale :** l'**icône de volet** en haut, à droite du nom de l'application, replie la barre latérale pour gagner de la place. Une fois repliée, la même icône réapparaît en haut à gauche de l'écran et la redéploie. Votre choix est conservé d'une visite à l'autre.

**Sur mobile :** un **bandeau unique en haut de l'écran** regroupe toute la navigation, sur toutes les pages (chat, réglages, administration, à propos) :
- le bouton **☰** à **gauche** ouvre la barre latérale en tiroir (**Nouvelle conversation** et la recherche, les tuiles **Espaces**, l'historique — et le menu de gauche sur les pages de réglages et d'administration) ; on la referme en touchant le fond assombri ou avec Échap ;
- au centre, le logo et le nom de la page ;
- à droite, le bouton **+** démarre une **nouvelle conversation** en un geste (sur le chat), et votre **avatar** mène directement à **Mon profil**. Sur une page de réglages ou d'administration, le menu de gauche s'ouvre par le bouton **☰** ; sur le chat, les boutons **Réglages** et **Admin** restent disponibles en bas du tiroir ☰.

### Changer la langue (français / anglais)

MIA s'affiche en **français** ou en **anglais**. Le sélecteur se trouve **en haut à droite de l'écran**, sur toutes les pages : un drapeau suivi du code de la langue (**🇫🇷 FR**, **🇬🇧 EN**). Un clic ouvre la liste, un second choisit la langue ; la page se recharge aussitôt dans la langue retenue. Sur mobile, il se réduit au drapeau, à droite du bandeau du haut.

- **Votre choix suit votre compte**, pas le navigateur : reprenez MIA sur un autre appareil, elle est déjà dans votre langue.
- **La page de connexion aussi** : le sélecteur y fonctionne avant même de vous identifier, et le choix fait à ce moment-là est conservé quand vous vous connectez.
- **Sans choix de votre part**, MIA suit la langue de votre navigateur, puis la langue par défaut décidée par votre administrateur.
- **Dates, montants et tailles** suivent la langue : *07/09/2026* devient *09/07/2026*, *1 234,56 €* devient *€1,234.56*.

**MIA écrit dans votre langue** : les pages **Assistants** et **Bases de connaissances** (listes, fiches, schémas, modèles de prompt) s'affichent dans la langue choisie ; les réponses arrivent **par défaut** dans cette langue, les **titres** des nouvelles conversations de l'historique aussi, et ce que MIA vous prépare — **tâches programmées**, **workflows**, **assistants**, pages web — est rédigé dans cette langue. Écrivez-lui dans une autre langue, ou demandez-la, et MIA vous suit.

**L'administration** (menu, tableau de bord et chaque écran, messages et points à régler compris) s'affiche aussi dans la langue choisie.

**Ce qui reste en français :**
- ce que votre organisation a rédigé : **nom, description et consignes des assistants**, vocabulaire d'entreprise, et la phrase d'accueil **si elle a été personnalisée** (laissée telle que livrée, elle suit votre langue) ;
- vos **contenus** — titres de conversations déjà créées, messages déjà échangés, souvenirs, présentations. Ils ne sont jamais retraduits après coup ;
- l'assistant **MIA : mode d'emploi** répond dans votre langue, mais s'appuie sur des guides rédigés en français : il peut lui arriver de citer un intitulé de bouton en français.

**Les assistants** citent les boutons et les écrans tels qu'ils s'affichent **chez vous** : demandez en anglais où retrouver votre mémoire, MIA vous répondra *Settings → AI preferences → « MIA about you »*.

---

## 4. Démarrer et mener une conversation

Quatre façons de démarrer :
1. **Écrire directement** dans le champ « **Envoyer un message ou déposer vos fichiers…** » (sur l'accueil, l'envoi crée la conversation), ou
2. taper « **@** » suivi des premières lettres du nom d'un assistant, directement dans le champ de saisie (voir ci-dessous), ou
3. cliquer l'icône **tête de robot** sous la pilule pour **démarrer avec un assistant** (le texte déjà tapé est conservé), ou
4. cliquer **+ Nouvelle conversation** dans la barre latérale (retour direct à l'**écran d'accueil**).

**Sur téléphone**, la zone de saisie montre deux lignes au repos (trois sur l'écran d'accueil) et grandit avec votre texte jusqu'à près de la moitié de l'écran.

**Des idées dans la zone de saisie.** Tant que le champ est vide, son texte d'aide (« Envoyer un message ou déposer vos fichiers… ») laisse place, en fondu, à des **idées d'utilisation** de MIA dans votre travail : « Résume ce document en cinq points clés », « Fais un tableau Excel de suivi des commandes », « Trouve un créneau libre d'une heure jeudi matin »… Elles ne citent que ce que vous pouvez réellement faire : un connecteur n'y apparaît qu'une fois relié à votre compte (Réglages → Comptes connectés), un outil (image, page web, carte) que s'il vous est ouvert et en service, une tâche programmée, un workflow ou un assistant que si votre rôle permet d'en créer, une base de connaissances que si vous en avez une à interroger. Le texte disparaît à la première lettre tapée et revient quand le champ se vide. Sur téléphone, seules les idées qui tiennent sur la ligne s'affichent ; si votre système demande de réduire les animations, elles changent en simple fondu. Ces idées ne servent qu'à démarrer : **dès que la conversation est lancée, elles s'arrêtent**, et le champ ne montre plus que son texte d'aide — ou la suggestion de suite.

**Une suggestion de suite après chaque réponse.** Quand MIA a répondu, la zone de saisie vide propose en grisé la **suite logique** de l'échange : « Oui, prépare le brouillon pour Marie » quand MIA vient de le proposer, « Compare avec le devis de l'an dernier » après une analyse… Appuyez sur **Tab** pour la reprendre (au doigt : **Utiliser**) : elle s'écrit dans le champ, rien n'est envoyé — modifiez-la si besoin, puis **Entrée**. Tapez autre chose, elle disparaît ; videz le champ, elle revient. Elle n'apparaît que sous la **dernière** réponse terminée, jamais quand une carte attend votre décision, quand des choix en boutons sont proposés ou quand le bouton **Continuer** est là ; quand aucune suite n'est évidente, rien ne s'affiche. Préparée **une fois par réponse** par le modèle de la conversation (quelques crédits, *Assistance de conversation*), elle est retrouvée telle quelle au rechargement ; rouvrir une conversation ancienne n'en prépare aucune. Un administrateur peut couper cette suggestion pour toute l'organisation.

**Raccourcis clavier :**

| Touche | Action |
|---|---|
| **Entrée** | Envoyer |
| **Maj + Entrée** | Saut de ligne |
| **@** | Appeler un assistant (liste filtrée au fil des lettres) |
| **Tab** | Reprendre la suggestion de suite (champ vide) |

> Vous rédigez souvent de longs messages ? Dans **Réglages → Affichage**, carte **Envoi des messages**, choisissez **Ctrl + Entrée** (**⌘ + Entrée** sur Mac) : la touche **Entrée** va alors à la ligne, et le message ne part qu'avec ce raccourci ou le bouton **Envoyer**. L'infobulle du bouton **Envoyer** rappelle le raccourci en vigueur.
| **↑ / ↓** | Parcourir la liste des assistants (après **@**) |
| **Échap** | Fermer une fenêtre (ou la liste des assistants) |

- La réponse s'affiche **au fil de l'eau** (mot à mot).
- MIA connaît la **date et l'heure de votre appareil** (son fuseau horaire) : « demain », « ce soir » ou « la semaine prochaine » sont compris sans que vous précisiez la date.
- Le **titre** de la conversation est généré automatiquement après le premier message (modifiable).
- Sous chaque réponse, une **étiquette** indique le modèle utilisé, les crédits consommés et le temps de réponse.
- **Au-dessus de chaque réponse, une petite pile grise liste ce que MIA a fait avant de répondre** : « Recherche dans les bases de connaissances », « Recherche sur Internet », « Recherche dans Gmail : facture mars », « Lecture d'un document Google Drive : Rapport annuel.docx »… Les lignes s'empilent en direct pendant la génération, et **restent visibles dans l'historique** (y compris après un rechargement). Au-delà de 5 actions, un lien « +N autres actions » déplie le reste. Chaque ligne porte l'**icône de ce qu'elle a fait** — enveloppe pour un mail (Gmail comme Outlook), calendrier pour un agenda, case cochée pour les tâches, nuage pour une recherche dans le Drive, dossier pour son contenu, feuille pour un document — le libellé nommant, lui, le service exact. Une action en échec s'affiche en ambre avec un triangle d'avertissement. Cette trace est purement informative : elle n'est **jamais incluse** dans « Copier » ni dans les exports PDF/Word.
- **Une image venant d'un autre site n'est pas affichée d'office.** À sa place, un bouton « **Image externe · nom du site — Afficher** » : un clic l'affiche (pour le reste de la page). Une image se charge toute seule dès qu'elle est affichée, et son adresse peut transporter des informations vers le site qui l'héberge — MIA vous laisse donc décider. Les images créées par MIA s'affichent normalement. Copier une réponse ou l'exporter en PDF/Word remplace une image externe par un lien vers son adresse.
- Pendant la génération, le bouton d'envoi laisse place au bouton **Arrêter la génération** (carré) : un clic interrompt la réponse en cours. Une réponse arrêtée compte les crédits de ce qui a déjà été lu et écrit (le fournisseur le facture).
- On peut changer de conversation pendant une génération : elle continue en arrière-plan (point clignotant dans la liste, et un bouton « **Revenir à la génération en cours** »).
- **Une très longue conversation continue sans erreur.** Chaque message part avec l'historique du fil ; quand celui-ci dépasse ce que le modèle peut relire d'un coup, MIA l'allège d'elle-même, du plus doux au plus fort : d'abord, les fichiers joints aux messages anciens ne repartent plus à chaque message (seul leur nom reste, MIA les relit si votre question en a besoin — ligne **Relecture d'un fichier de la conversation**) ; ensuite, le début du fil est remplacé par un **résumé** (ligne **Résumé du début de la conversation**), produit une fois et complété au fil de l'eau. Tant que tout tient, rien ne change. Un détail précis du début qui manquerait au résumé : MIA vous le dit plutôt que de l'inventer. Le résumé consomme quelques crédits (*Assistance de conversation*).

> **Toutes les générations continuent sans vous.** Réponse de l'assistant, image, page HTML, vidéo, présentation : une fois lancée, la fabrication se poursuit **sur le serveur**. Vous pouvez changer de conversation, ouvrir vos réglages, **recharger la page** ou fermer l'onglet — rien n'est perdu. En revenant, vous retrouvez l'avancement en cours, et le résultat vous attend dans sa conversation. Le bouton **Arrêter la génération** l'interrompt réellement, même après un rechargement de page.
>
> **Le suivi est dans la barre latérale.** Dès qu'une génération tourne, un bandeau « *N* génération(s) en cours » apparaît sous le bouton « Rechercher ou aller à… ». Il se déplie : chaque ligne indique ce qui est en train d'être produit, avec **Voir** (aller à la conversation concernée) et **Arrêter**. **Voir** rouvre aussi le **volet de la génération** (page HTML, vidéo, présentation) sur son avancement en cours — même après un rechargement de page. Il reflète aussi ce que vous avez lancé depuis **un autre onglet ou un autre appareil**. Le bouton **🔔 M'avertir quand c'est terminé** demande l'autorisation d'afficher une notification du système : vous êtes alors prévenu même si MIA n'est pas la fenêtre active. Rien n'est notifié si le résultat arrive sous vos yeux.

### Répondre à un choix proposé par MIA (choix en boutons)

Quand la suite dépend d'une décision de votre part — une piste parmi plusieurs, un ton, un format, la prochaine étape —, MIA peut terminer sa réponse par **des boutons** : la question, puis une option par bouton, parfois avec une courte précision dessous. Elle ne le fait **que lorsque c'est utile** : jamais pour une question ouverte (un nom, une date, un texte à écrire), jamais par réflexe à la fin d'une réponse complète.

- **Un clic envoie l'option** comme votre réponse, exactement comme si vous l'aviez tapée. Le texte en cours de rédaction dans la zone de saisie et vos pièces jointes en attente y restent.
- **Choix multiple** (« Plusieurs choix possibles ») : cochez une ou plusieurs options, puis **Envoyer (n)** — elles partent ensemble, une par ligne.
- **Vous n'êtes jamais obligé de choisir** : écrivez votre propre réponse dans la zone de saisie (le lien **Autre réponse** y place le curseur). Une fois que vous avez répondu, de quelque façon que ce soit, les boutons se figent ; l'option envoyée reste cochée dans l'historique.
- Au clavier : **Tab** pour atteindre les boutons, les **flèches** pour passer de l'un à l'autre, **1** à **9** pour choisir l'option de ce rang.
- Seuls les boutons de la **dernière** réponse sont actifs. Ils n'apparaissent pas en conversation vocale (la réponse y est lue à voix haute), ni dans les réponses d'une tâche programmée ou d'un workflow, qui se déroulent sans vous. **Copier** et les exports PDF/Word reprennent la question et la liste des options.

### Demander à MIA de se souvenir

Dites-le simplement, en pleine conversation : *« Souviens-toi que je préfère les réunions du matin »*, *« Rappelle-toi que je travaille au département marketing »*, *« J'aimerais que tu retiennes que j'aime les réponses courtes sur les formations »*. MIA enregistre l'information dans votre mémoire personnelle **MIA pour vous** et la retrouve dans **toutes** vos conversations suivantes, quel que soit l'assistant.

Une **pastille** apparaît sous la réponse avec ce qui vient d'être retenu, et un lien **Gérer ma mémoire**. La liste complète est modifiable dans **Réglages → Préférences IA**, section **MIA pour vous** (voir [§ 18](#18-réglages-personnels)).

> MIA n'enregistre **que** ce que vous lui demandez explicitement de retenir. Un simple *« rappelle-moi demain de relancer le fournisseur »* n'est pas une demande de mémorisation et ne laisse aucune trace. MIA ne peut pas non plus **supprimer** un souvenir toute seule : cela se fait dans **Réglages → Préférences IA**.

### Demander à MIA ce qu'elle sait de vous

Posez la question en conversation : *« Que sais-tu de moi ? »*, *« Qu'est-ce que tu retiens sur moi ? »*, *« Montre-moi ma mémoire »*. MIA vous répond en reprenant **les deux sections de vos réglages**, avec leurs titres exacts :

- **MIA pour vous** — la liste complète de vos souvenirs, restituée telle quelle, sans rien omettre ni ajouter ;
- **Votre contexte** — le texte libre de vos Préférences IA, s'il est renseigné.

Une section vide n'apparaît pas : sans souvenir ni contexte, MIA vous dit simplement qu'elle n'a rien retenu. La réponse rappelle où corriger — **Réglages → Préférences IA** (voir [§ 18](#18-réglages-personnels)) —, car MIA ne peut ni modifier ni supprimer ces informations elle-même.

> C'est votre mémoire qui est restituée, pas celle des autres : ces informations sont **strictement personnelles** et un assistant n'a accès qu'aux vôtres.

MIA connaît aussi **votre entreprise** : son nom et la présentation qu'en a rédigée votre administrateur (activité, clients, implantations), communs à tous les utilisateurs et à tous les assistants. C'est ce qui lui fait comprendre *« notre offre »* ou *« nos clients »* sans explication. Elle ne s'en sert que si c'est utile.

### Retrouver une ancienne conversation

Faites allusion à un échange passé : *« reprends le plan de la dernière fois »*, *« tu m'avais conseillé un outil, lequel ? »*, *« on avait parlé de Qdrant »*. MIA cherche alors dans **vos autres conversations** (jamais dans celles des autres utilisateurs) et cite celle d'où vient l'information, avec son lien pour la rouvrir. La ligne **Recherche dans vos anciennes conversations** apparaît dans la liste des actions. Si rien ne correspond, MIA le dit au lieu d'inventer. Il faut un modèle capable d'utiliser les outils (**✓ Connecteurs**).

Quand MIA utilise déjà ses outils dans la réponse (connecteurs, outils d'un assistant), elle peut aussi **enregistrer elle-même** un fait dans **MIA pour vous** — si vous le lui demandez avec d'autres mots que « souviens-toi », ou si vous répondez « oui » quand elle propose de retenir quelque chose. Elle refuse un mot de passe, un numéro de carte ou un IBAN.

### L'outil reste actif tant que vous ne l'éteignez pas

Activer un outil (image, vidéo, page HTML, site illustré, carte) n'engage pas qu'un seul message : **il reste actif pour toute la conversation**, et sa **pastille** le rappelle en permanence à côté du bouton **+**. Vous pouvez donc affiner votre production message après message — « *mets le ciel en orange* », « *ajoute une étape à Aix-en-Provence* », « *change la couleur du header* » — sans re-sélectionner l'outil à chaque fois.

- La règle vaut **quelle que soit la façon dont l'outil a été activé** : choisi dans le menu **+** ou accepté depuis la bannière de suggestion.
- Elle vaut aussi **quel que soit le type d'outil** : image, vidéo, page HTML, carte se comportent à l'identique.
- L'outil actif **survit au rechargement de la page** et aux allers-retours entre conversations : chaque conversation garde le sien, indépendamment des autres.
- **Pour l'éteindre** — et revenir aux réponses en texte — cliquer la **croix** de la pastille, ou recliquer l'outil dans le menu **+**. C'est le seul moyen de le désactiver : rien ne l'éteint dans votre dos.

> Utile à savoir : tant qu'un outil est actif, **chaque** message part vers cet outil. Pour poser une question ordinaire (« *explique-moi ce que fait ce code* »), retirez d'abord la pastille — vous la remettrez ensuite via le menu **+**.

### Choisir les bases de connaissances d'une conversation

Le bouton **livre ouvert**, sous le champ de saisie (à droite du bouton **+** et de l'icône **assistant**), ouvre la liste des **bases de connaissances auxquelles vous avez accès** : celles que vous avez créées, et celles qu'on vous a partagées (directement ou via un de vos groupes). Cochez-en jusqu'à **3** : MIA les fouillera avant de répondre, à chaque message de cette conversation.

- **Elles s'ajoutent, elles ne remplacent pas.** Si l'assistant en face de vous a déjà ses propres bases, les vôtres viennent en plus. Les siennes apparaissent en tête de liste, **cochées et grisées**, avec le badge **Assistant** : elles sont toujours interrogées et ne se retirent pas ici.
- **Le choix vaut pour toute la conversation** et survit au rechargement, aux allers-retours entre conversations et au **changement d'appareil** — il est attaché à votre compte, pas à votre navigateur. Une **nouvelle** conversation démarre sans base cochée, sauf celles qu'un administrateur a cochées **par défaut** pour votre groupe : elles sont déjà cochées, et se décochent comme les autres.
- **Une pastille** sur le bouton compte les bases que *vous* avez ajoutées. Au maximum, les cases restantes se ferment et la liste affiche « 3 bases maximum — décochez-en une pour en ajouter » ; les cases cochées, elles, restent toujours décochables.
- Vous pouvez cocher **avant même d'écrire** votre premier message : le choix suit la conversation qui se crée.
- La ligne **Recherche dans les bases de connaissances**, au-dessus de la réponse, nomme les bases utilisées et distingue les vôtres (« ajoutées par vous ») de celles de l'assistant.

**Une base grisée n'est pas un bug.** Une base n'est interrogeable que lorsque ses documents ont été **vectorisés** (analysés puis indexés). Tant que ce n'est pas le cas, elle apparaît quand même, grisée, avec la raison :

| Ce que vous lisez | Ce qu'il faut faire |
|---|---|
| **Aucun document** | Déposer des documents dans la base (voir le guide du créateur). |
| **Vectorisation en cours** | Patienter, puis rouvrir la liste. |
| **Aucun texte exploitable dans les documents** | Les fichiers ne contiennent pas de texte lisible (PDF scanné, image sans légende). |
| **Vectorisation en échec** | Prévenir un administrateur : la base doit être réindexée. |

> Une base peut **disparaître** de votre liste : si un administrateur la désactive, ou si son partage vous est retiré. Votre choix n'est pas perdu pour autant — la base **revient cochée** dès que l'accès est rétabli.

**Le cerveau MyBrain, une base en direct.** Si votre organisation a branché son **cerveau MyBrain** — le wiki de l'équipe, tenu à jour à partir des mails, des comptes rendus et des documents —, il apparaît dans la même liste, avec l'icône du cerveau, la mention **Interrogé en direct** et le badge **MyBrain**. Rien n'y est copié : MIA l'interroge à chaque message où il est coché (ou lié à l'assistant), et cite ses fiches avec un lien vers MyBrain (il faut un compte MyBrain pour les ouvrir).

- Il n'est proposé qu'aux membres des **groupes** choisis par l'administrateur — et c'est vérifié à chaque message, **même à travers un assistant**.
- Au-dessus de la réponse, la ligne **Consultation du cerveau MyBrain** dit combien de fiches il a rendues et nomme les premières ; « rien de pertinent » veut dire qu'il ne sait rien sur la question.
- **Il creuse quand la question l'exige.** Avec un modèle compatible (le même qu'exigent les connecteurs), MIA part d'un extrait — les fiches les plus pertinentes — puis relance une recherche sous un autre angle ou lit une fiche en entier si la réponse le demande : lignes **Nouvelle recherche dans le cerveau MyBrain** et **Lecture d'une fiche MyBrain**. Avec un autre modèle, il reçoit d'emblée tout ce que le cerveau a trouvé.
- Les **questions de suite** (« et les chantiers ? ») fonctionnent : MIA transmet au cerveau le fil de la conversation.
- **Proposer un texte au cerveau.** Si votre groupe peut le **nourrir**, demandez-le explicitement (« ajoute au cerveau le compte rendu de la réunion d'hier : … ») : MIA rédige le texte et affiche une carte **Proposer au cerveau MyBrain**. **Approuver** l'envoie, à votre nom, dans la **revue** de MyBrain, où une personne le valide — rien n'entre dans le wiki sans validation. Un message ⏳ puis ✅ suit l'analyse, avec le lien **trancher la proposition**. Les informations RH sont rangées par MyBrain dans une zone réservée à sa direction.
- S'il ne répond pas, la ligne passe en ambre (« injoignable ») et la réponse part sans lui, en le disant. Grisé avec **Connexion MyBrain refusée — à vérifier par un administrateur** : la clé qui relie MIA à MyBrain ne fonctionne plus, prévenez un administrateur.

### Suggestion automatique d'outil et de connecteur

MIA lit votre message **pendant la frappe** et affiche une **bannière** au-dessus du champ de saisie quand elle reconnaît une intention. Deux familles :

**Outils** — création visuelle (« dessine-moi un chat », « génère une vidéo de… », « crée une page web pour… », « trace un itinéraire de Lyon à Nice ») → « **Générer une image avec « X » ?** ».

- **Utiliser X** : l'outil est **activé**, exactement comme si vous l'aviez choisi vous-même dans le menu **+** — sa **pastille** apparaît à côté du bouton **+** et il **reste actif pour la suite de la conversation** (voir « L'outil reste actif » ci-dessous). Envoyer lance la génération.
- **Non merci** : plus aucune suggestion de ce type jusqu'à la fin de votre session.
- La suggestion ne s'affiche jamais si un outil est déjà **actif** (pastille d'outil affichée — le choix en cours prime toujours) ou si l'assistant actif génère déjà des images de lui-même.

**Internet** — si vous avez éteint la recherche Internet et que votre message a besoin d'informations à jour (« quelles sont les **dernières actualités** ? », « la **météo** demain », « le **cours actuel** de… », « cherche **sur internet** »), MIA propose de la réactiver. Aucune suggestion tant qu'elle est allumée — c'est le réglage par défaut.

**Connecteurs** — votre message parle de vos données Google (« résume **mon dernier mail** de Marie », « ouvre **mon Google Doc** Budget 2026 », « **suis-je libre** jeudi ? », « ajoute une réunion **dans mon agenda** ») → « **Autoriser l'assistant à consulter vos emails Gmail ?** ».

- **Activer Gmail / Google Drive / l'agenda / les tâches / Outlook / l'agenda Outlook / Teams / Slack** : l'interrupteur correspondant du menu **+** s'allume — et **le reste** pour vos prochains messages (point ambre sur le **+**). Si le modèle de la conversation ne gère pas les connecteurs, la fenêtre de **choix d'un modèle compatible** s'ouvre (voir section 16).
- Un connecteur **déjà allumé**, **non connecté** ou **non proposé par l'administrateur** ne déclenche aucune suggestion.
- Les mots génériques ne suffisent pas : « l'agenda de la réunion » ou « le document ci-joint » ne déclenchent rien. Il faut la marque (**Gmail**, **Outlook**, **Google Drive**, **Google Agenda**, **Google Tasks**, **calendrier Outlook**, **Microsoft To Do**, **Microsoft Teams** ou « sur Teams », **Slack**), un possessif (**mon** agenda, **mes** mails, **mes** fichiers, **mes** tâches, **mes** canaux) ou un verbe explicite (**réponds** à ce mail).
- **La liste des expressions est propre à votre organisation** : votre administrateur peut l'ajuster, connecteur par connecteur (accents et majuscules sans importance). Une expression que vous employez souvent et qui ne déclenche rien ? Demandez-lui de l'ajouter.

Communs aux deux :
- **Non merci** : le message part en conversation normale, et ce type de suggestion ne réapparaît **plus durant la session** (fermer l'onglet réinitialise).
- Envoyer sans répondre à la bannière = réponse normale, sans conséquence sur les suggestions futures.
- Aucune suggestion quand des **fichiers sont joints** (votre message porte alors sur la pièce jointe).

### Quand l'assistant déclenche une automatisation

Certains assistants savent **déclencher les automatisations de votre organisation** (workflows n8n) : créer un
ticket, alimenter un outil métier, relever un statut. **Rien à activer de votre côté** — c'est une capacité de
l'assistant, décidée par votre administrateur : il suffit de la lui demander (*« crée un ticket support pour
l'imprimante du 2ᵉ étage, priorité haute »*).

- L'assistant ne connaît **que** les automatisations qui lui ont été ouvertes. S'il n'en trouve aucune qui
  corresponde, c'est qu'elle ne lui a pas été confiée — voyez avec un administrateur, insister ne servira à rien.
- **Vous approuvez, puis ça part.** Une **carte** s'affiche sous la réponse : l'action, l'automatisation visée,
  les données transmises, et un rappel que ses effets peuvent être irréversibles. Deux boutons —
  **Approuver** ou **Annuler**. Tant que vous n'avez pas cliqué **Approuver**, **rien n'a été fait** : si
  l'assistant affirme le contraire, c'est lui qui se trompe. Sans réponse, la demande **expire en 30 minutes**.
  Quelques automatisations de simple consultation, désignées comme telles par vos administrateurs, s'exécutent
  sans cette carte.
- **Le résultat revient dans la conversation** après approbation. Certaines automatisations répondent seulement
  « c'est parti » et continuent de tourner de leur côté : l'assistant vous le dit alors, plutôt que d'inventer
  un résultat.
- **Vérifiez ce que vous approuvez** : la carte affiche les données exactes qui seront envoyées. Un champ mal
  déduit se corrige en le disant à l'assistant (*« non, priorité normale »*), qui vous représentera l'action.

### Quand l'assistant génère une présentation Gamma

Certains assistants savent **générer une présentation via gamma.app** : demandez-la simplement (*« fais-moi une
présentation de 10 slides sur notre offre 2026 »*). Rien à activer de votre côté — c'est une capacité de
l'assistant, décidée par votre administrateur.

- **Vous approuvez, puis ça part.** Chaque génération consomme des **crédits Gamma payants** : une carte
  s'affiche sous la réponse (sujet, nombre de slides) avec **Approuver** / **Annuler**. Tant que vous n'avez pas
  approuvé, rien n'a été généré ni facturé.
- **Comptez 1 à 3 minutes** après approbation. La génération tourne en tâche de fond (visible dans le tiroir
  « générations en cours ») : vous pouvez continuer à discuter ou changer de conversation. Le **lien vers la
  présentation** — et le fichier PowerPoint ou PDF si votre administrateur l'a activé — est ajouté à la
  conversation dès qu'elle est prête.
- La présentation s'ouvre **sur gamma.app** (elle vit dans le compte Gamma de votre organisation), où elle reste
  modifiable comme n'importe quel document Gamma.
- **Sans assistant aussi** : une présentation PowerPoint livrée dans le chat se confie à Gamma par le bouton
  **Version soignée** de sa carte, puis **Par Gamma** (voir section 16, « Télécharger un fichier créé par MIA »).

---

## 5. Pièces jointes

- Cliquer le bouton **+** sous la pilule → **Joindre des fichiers** (max 10), ou **glisser-déposer** les fichiers dans le champ de saisie. Sur mobile, le bouton s'appelle **Fichiers**, et le bouton **Photo**, à côté, ouvre directement l'appareil photo.
- **Formats acceptés :** PDF, texte, Markdown, Word, Excel, et images (JPG, PNG, GIF, WebP).
- **Jusqu'à 10 fichiers** par message et **10 Mo maximum par fichier** (au-delà : « Fichier trop lourd (max 10Mo) ») ; ils apparaissent en vignettes au-dessus du champ.
- Le contenu des documents est lu automatiquement ; les images sont analysées par les modèles compatibles.
- **Dans une très longue conversation**, un fichier joint il y a longtemps ne repart plus avec chaque message : seul son nom reste, et MIA le relit quand votre question porte sur lui (par parties s'il est long). Une image ne se relit pas ; avec un modèle sans **✓ Connecteurs**, MIA vous demandera de joindre le fichier à nouveau.

---

## 6. Connecteurs (Internet et données personnelles)

Le menu **+** porte une section **Connecteurs** : les sources extérieures que l'assistant a le droit de consulter.
Certaines sont **personnelles** — Google Drive, Gmail, Google Agenda, Google Tasks, Outlook, le calendrier Outlook, Microsoft To Do, Microsoft Teams et Slack, à relier depuis **Réglages → Comptes connectés**
(voir section 18). La **Recherche Internet**, décrite ici, ne demande rien : elle est mise en place par votre
administrateur pour toute l'organisation.

**MIA se souvient de ce qu'elle a consulté dans la conversation.** Une fois qu'elle a ouvert un document, un mail,
un évènement ou une tâche, vous pouvez enchaîner sans le renommer : « ajoute-lui une conclusion », « réponds à ce
mail », « décale cet évènement ». Elle retient les derniers objets consultés dans **cette** conversation et repart du
plus récent. Deux précisions utiles :

- ce rappel ne vaut **que pour les connecteurs allumés au moment où vous écrivez** — si vous éteignez Google Drive, MIA
  ne peut plus reprendre le document même si elle l'a lu deux messages plus tôt ;
- si votre demande peut viser deux objets récents, elle **vous demande lequel** au lieu de choisir.

Elle se souvient aussi de ses **gestes** : les recherches et les actions de ses dernières réponses (une recherche restée
vide, un brouillon préparé). « Cherche encore » ou « et du côté d'Outlook ? » repartent de là, sans refaire à
l'identique ce qui n'a rien donné. Les documents lus par un connecteur le sont **en entier**, que la conversation
passe par un assistant ou non : un long document Drive ou un long email se lit **partie par partie**, MIA lisant la
suite quand votre question l'exige. Un résultat très volumineux (une recherche qui ramène des dizaines de mails, la
sortie brute d'une automatisation) est **résumé au regard de votre question** avant que MIA ne poursuive — les
identifiants, noms et liens utiles sont gardés tels quels.

Vous pouvez aussi désigner un fichier Drive **par son nom** (« modifie le compte rendu de lundi ») : nul besoin d'un
lien ni d'un identifiant. Si plusieurs fichiers portent un nom approchant, MIA vous liste les candidats et vous
demande lequel viser.

**Les pièces jointes des emails se lisent aussi.** Quand MIA ouvre un email Gmail ou Outlook, elle voit la liste de
ses pièces jointes (nom, type, taille) et lit celle dont votre question a besoin : *« que dit la facture jointe au
dernier mail d'Orange ? »*. PDF, Word, Excel, PowerPoint, OpenDocument, texte et CSV se lisent ; une image, un ancien
format .doc ou un fichier de plus de 20 Mo ne se lit pas, et MIA le dit. Un long document se lit **en entier, partie
par partie** : MIA lit la suite quand votre question l'exige.

**Trouver un créneau libre.** *« Trouve-moi une heure jeudi ou vendredi avec marie@exemple.fr »* : MIA calcule les
créneaux libres de votre agenda Google ou de votre calendrier Outlook (par défaut de 9 h à 18 h, du lundi au
vendredi, au quart d'heure), en tenant compte des collègues que vous nommez quand leur agenda lui est visible (même
organisation, ou agenda partagé). Elle ne voit d'eux que leurs plages occupées, jamais le contenu de leurs
évènements, et dit lesquels elle n'a pas pu consulter. Elle propose ensuite de bloquer le créneau choisi — carte
d'approbation comprise.

**Les heures sont celles de votre appareil.** Les agendas se lisent et s'écrivent dans le fuseau horaire de votre
navigateur : un évènement demandé « demain à 14 h » est créé à 14 h chez vous, et la carte d'approbation précise le
fuseau (par exemple « (Europe/Paris) »).

**Un connecteur éteint est signalé, pas subi.** Si vous demandez quelque chose qui exige une source connectée mais dont
l'interrupteur est éteint, MIA vous le dit et vous indique le geste exact — ouvrir le menu **+**, déplier **Connecteurs** et
activer la ligne concernée (« Fichiers Google Drive », « Emails Gmail »…), puis renvoyer votre demande. Elle vous propose au passage ce
qu'elle peut faire sans cet accès (répondre de mémoire, travailler sur un fichier que vous joignez au message). Elle
n'invente jamais le contenu d'une source à laquelle elle n'a pas accès.

**Ou laissez MIA choisir.** La première ligne du volet **Connecteurs**, **Laisser MIA choisir**, vous évite
d'allumer chaque connecteur avant de poser votre question : allumée, elle permet à MIA d'activer elle-même, **pour la
réponse en cours**, les services **reliés** dont votre demande a besoin — et de laisser les autres éteints (une question
générale n'active rien). Chaque activation s'affiche dans la liste des actions (« Activation d'un connecteur — Gmail »).
Rien ne change pour les écritures : elles passent toujours par une carte d'approbation. Comme les autres interrupteurs,
elle reste allumée sur ce navigateur ; la ligne n'apparaît que si au moins un service est relié et que votre
administrateur la propose.

**MIA enchaîne les étapes toute seule.** Pour une demande en plusieurs temps (« retrouve le mail de Marie sur le devis
et mets la réunion qu'elle propose dans mon agenda »), MIA annonce d'abord son plan en une phrase ou deux, puis
enchaîne les consultations nécessaires — plusieurs connecteurs dans la même réponse si besoin — et adapte ses
recherches quand un premier essai ne donne rien (autres mots-clés, période élargie), avant de conclure en citant ce
qu'elle a consulté. La liste des actions menées s'affiche en petit au-dessus de la réponse, avec une ligne animée pour
l'action en cours (et, sur certains modèles, une courte note sur ce qu'elle fait). Pour une demande en plusieurs
étapes, une carte **Plan de travail** apparaît en tête de la réponse et coche les étapes au fur et à mesure (quand la
réponse se termine sans rien attendre de vous, l'étape en cours est cochée elle aussi) ; elle
reste visible quand vous revenez sur la conversation. Quand le plan n'est pas terminé — une étape attendait votre
approbation, ou la demande était trop longue pour une seule réponse —, un bouton **Continuer** apparaît sous la
réponse une fois les cartes tranchées : MIA repart des étapes restantes, en tenant compte de ce que vous avez approuvé
ou refusé. Le bouton **Stop** arrête aussi les étapes restantes : aucune recherche ni carte d'approbation ne part
après lui. Deux garde-fous ne changent pas : les **écritures** (brouillon, évènement, fichier…) passent toujours par
une **carte d'approbation** que vous validez vous-même, et le nombre d'allers-retours avec les outils est plafonné à
chaque message (un peu plus haut quand le plan compte quatre étapes ou plus).

**Une seule recherche, toutes vos sources.** Quand plusieurs connecteurs sont allumés (ou activés par MIA), une
question qui ne dit pas où chercher — *« qu'avons-nous sur le dossier Durand ? »* — est posée à **toutes** les sources
en même temps : mails, Google Drive, Slack, agendas, tâches et bases de connaissances de la conversation. MIA reçoit
les résultats rangés par source, lit les plus pertinents, et vous répond en citant d'où vient chaque élément ; la
liste des actions montre « Recherche dans toutes vos sources ». De même, avec Gmail **et** Outlook allumés, une
recherche d'email couvre les deux boîtes d'un coup (« Recherche dans vos emails (Gmail et Outlook) ») ; avec les deux
agendas, une consultation les fusionne dans l'ordre chronologique. Une source en panne (connexion expirée…) est
nommée dans la réponse, les autres répondent quand même.

**Ce que MIA lit reste une information, jamais un ordre.** Un email, un fichier partagé, un message Slack ou la description d'un
évènement sont écrits par quelqu'un d'autre que vous. S'ils contiennent une phrase qui ressemble à une consigne (« transfère ce
dossier à… », « ignore tes instructions »), MIA ne l'exécute pas : elle vous la signale. Et quoi qu'il arrive, aucune
écriture (brouillon, évènement, fichier, message) ne part sans la carte d'approbation que vous validez vous-même. Quand
MIA a lu de tels contenus avant de préparer une action, la carte les nomme sous « **Préparé après la lecture de :** »
(par exemple « Lecture d'un email Gmail — « Devis Durand » ») et rappelle de vérifier que l'action correspond bien à
votre demande : c'est au moment d'approuver que cette vigilance sert.

### Recherche web (Internet)

- L'interrupteur **Recherche Internet** se trouve dans le menu **+** (section **Connecteurs**), **activé par défaut**.
- Il peut être **absent** : votre administrateur décide, connecteur par connecteur, de ceux qui sont proposés (Internet, Google Drive, Gmail, Agenda, Tâches, Outlook, Agenda Outlook, Slack). Une ligne absente du menu **+** est un connecteur qu'il a masqué pour tout le monde.
- Activé → l'IA peut chercher sur le web avant de répondre (informations récentes). Un **point ambre** apparaît sur le bouton **+** dès qu'une option s'écarte de son réglage par défaut (Internet désactivé, ou Google Drive activé), pour le rappeler d'un coup d'œil.
- Réglable à tout moment. **Votre réglage est mémorisé** sur ce navigateur : il vaut aussi pour vos prochains messages, vos autres conversations et vos nouvelles conversations, jusqu'à ce que vous le changiez. C'est vrai pour **tous les interrupteurs de la section Connecteurs** (Internet, Google Drive, Gmail, Agenda, Tâches, Outlook, Agenda Outlook, Slack, Laisser MIA choisir).
- L'IA ne cherche pas à chaque question : lorsqu'elle le fait, la phase « **Recherche complémentaire sur Internet…** » s'affiche pendant la réponse.
- **Lire une page précise** : collez son adresse dans votre message (*« résume https://exemple.fr/article »*). MIA lit la page, ou le PDF en ligne, et répond d'après son contenu réel (ligne **Lecture d'une page web** dans la liste des actions) ; une très longue page se lit partie par partie. Seules les **pages publiques** se lisent : une page qui demande une connexion, une adresse de réseau interne (intranet, serveur de l'entreprise) ou un fichier de plus de 5 Mo est refusé, et MIA vous le dit. Éteindre la Recherche Internet éteint aussi la lecture de pages. Il faut un modèle **✓ Connecteurs**.
- **La recherche Internet se cumule avec les autres connecteurs et avec les outils de l'assistant.** Avoir activé Google Drive, Gmail, l'Agenda ou l'un des connecteurs Outlook — ou discuter avec un assistant capable de générer des images — n'éteint pas l'accès au web : MIA peut chercher en ligne et consulter vos fichiers dans la même réponse.
- Si le modèle courant ne sait pas chercher sur le web alors que l'interrupteur est activé, MIA **le dit dans sa réponse** plutôt que de répondre de mémoire sans le signaler.
- **Tous les modèles ne savent pas chercher sur Internet.** En conversation standard, le bandeau au-dessus des messages l'indique (il est replié par défaut et ne montre que le modèle : la flèche à sa droite le déplie, et le choix est retenu sur cet appareil) : **✓ Internet** (le modèle peut chercher) ou **✗ Internet** (il ne peut pas — cocher la case ne changera rien). Pour une question d'actualité, choisir un modèle marqué **✓ Internet**. Le même bandeau porte aussi **✓/✗ Connecteurs** : seuls les modèles marqués **✓ Connecteurs** savent piloter Google Drive, Gmail, Google Agenda, Google Tasks, Outlook, le calendrier Outlook, Microsoft To Do et Slack (voir §15). Pas besoin de le vérifier avant d'agir : les connecteurs restent **toujours activables**, et si le modèle courant ne sait pas les piloter, MIA vous propose la liste des modèles compatibles au moment où vous activez l'interrupteur.

**Panneau « Sources Sonar Pro ».** Avec le modèle **Sonar Pro**, les sources citées s'affichent dans un panneau qui s'ouvre **automatiquement** à droite de la réponse. Trois boutons en haut du panneau : **Ouvrir la source sélectionnée dans un nouvel onglet**, **Copier toutes les URL des sources** et **Fermer le panneau**. Cliquer un renvoi de source dans la réponse ouvre le panneau directement sur cette source. Sans source détectée : « Aucune source détectée pour ce message. »

---

## 7. Dictée vocale et conversation vocale

**Dictée** : votre voix s'écrit dans le champ de saisie, vous relisez et envoyez. **Conversation vocale** : vous parlez, MIA répond et vous lit sa réponse, puis vous écoute de nouveau — sans clavier. Les deux suivent la **langue de l'interface** (français ou anglais).

- Le bouton **micro** n'apparaît que si l'administrateur a **activé la dictée** *et* configuré l'accès au fournisseur **OpenAI**, qui assure la transcription. Pas de bouton micro dans la pilule de saisie = à demander à un administrateur.
- Cliquer le bouton **micro** « **Dicter le message** » → autoriser le micro dans le navigateur → votre voix se transcrit dans le champ en direct.
- Le temps de l'établissement de la liaison, le bouton tourne et indique « **Connexion du micro…** ».
- Si la liaison se coupe en cours de dictée (réseau, inactivité), elle se **rétablit toute seule** : inutile de re-cliquer, continuez à parler.
- Re-cliquer pour arrêter (« **Arrêter la dictée** »). Si l'accès est refusé : message « Accès au micro refusé. »
- La dictée est **décomptée à la minute sur votre quota** : quota atteint, le clic sur le micro ouvre la fenêtre **Quota mensuel atteint** et la dictée ne démarre pas ; une dictée en cours s'arrête au plus tard à sa prochaine reconnexion.
- Si rien ne s'écrit au bout de quelques secondes, MIA le **signale** (« Dictée : aucun texte reçu. Vérifiez que le micro capte du son. ») plutôt que de laisser le bouton au rouge sans rien dire. Vérifiez alors l'entrée micro choisie par votre système et son niveau.

**Conversation vocale (bouton onde sonore, à côté du micro)**

- Le bouton **onde sonore** « **Converser à la voix** » n'apparaît que si l'administrateur a **activé la conversation vocale** *et* configuré l'accès à **OpenAI**. Cliquer → autoriser le micro → un bandeau apparaît au-dessus du champ : « **Je vous écoute** ».
- **Parlez, puis laissez une courte pause** : MIA détecte la fin de votre phrase, l'envoie comme un message ordinaire (il apparaît dans le fil, l'assistant, les bases et les connecteurs de la conversation s'appliquent), affiche « **MIA réfléchit…** », puis **lit sa réponse à voix haute** (« **MIA vous répond** ») — **dès sa première phrase écrite**, sans attendre la fin : la suite est lue au fur et à mesure qu'elle s'écrit, par passages coupés en fin de phrase. Si MIA a tout lu et que la suite tarde (un outil travaille), elle marque une pause, le tic revient, puis elle reprend. La réponse entièrement lue, MIA vous écoute de nouveau. Rien de ce que MIA lit ne repasse dans votre message : pendant sa lecture, elle n'entend qu'un ordre d'arrêt.
- **Interrompre MIA à la voix** : pendant qu'elle lit, dites « **stop** » (ou « arrête », « tais-toi », « attends », « ça suffit ») — elle se tait, la note claire retentit, elle vous écoute. « Stop, et pour demain ? » arrête la lecture **et** pose la question suivante. Si la réponse s'écrit encore quand vous l'interrompez (« stop » ou **Passer**), l'écriture s'arrête aussi, comme avec le bouton Stop du chat : le texte déjà écrit reste dans le fil, marqué « réponse interrompue ». Tout le reste est ignoré pendant la lecture (une remarque, un « oui », une question par-dessus sa voix) : pour lui parler, attendez la note claire ou dites « stop » d'abord. Le bouton **« Stop »** du bandeau allume ou éteint cette écoute, **par appareil** : allumé par défaut sur ordinateur et Android (le micro reste ouvert pendant la lecture, ces minutes comptent comme des minutes écoutées) ; éteint par défaut sur iPhone et iPad, où un micro ouvert fait baisser la voix de MIA — **Passer** y reste le moyen d'interrompre tant que vous ne l'allumez pas.
- **Répondre à une carte à la voix** : quand MIA prépare une action à approuver ou un email, la carte affiche une ligne « **À la voix :** … » et MIA vous rappelle quoi dire. Action à approuver : « **approuve** » (ou « valide », « vas-y, crée-le ») l'exécute, « **annule** » l'abandonne, « approuve tout » / « annule tout » traitent toutes les actions en attente. Email : « **envoie-le** » ouvre la confirmation — dites « **confirme** » pour envoyer, « retour » pour renoncer —, « **crée le brouillon** » le dépose dans votre messagerie, « **plus court** », « plus formel », « corrige les fautes » ou « améliore » suivi de votre consigne le font réécrire, « version précédente » revient en arrière, « **abandonne** » y renonce. MIA annonce l'issue (« C'est fait », « Email envoyé », « Brouillon créé », « C'est annulé ») puis vous écoute. La voix agit sur la carte en attente la plus récente ; les boutons restent cliquables.
- **Trois règles de prudence** : un « oui », « ok » ou « d'accord » seul **n'approuve rien** et ne confirme aucun envoi — il faut le mot de l'action, MIA vous le redit ; un envoi d'email se **confirme toujours** ; et dans le doute, votre phrase est un **message** (« envoie-le à Paul plutôt », « ne l'approuve pas » partent vers l'assistant). Un ordre dit à voix haute vaut un clic de votre part : l'assistant, lui, ne peut toujours ni approuver ni envoyer.
- **Des repères sonores** disent où l'on en est sans regarder l'écran : deux notes montantes quand votre message part, un léger tic de temps en temps tant que MIA travaille, une note claire quand elle vous écoute de nouveau.
- **MIA annonce ce qu'elle fait** pendant qu'elle prépare sa réponse — « Recherche sur Internet », « Recherche dans les bases de connaissances », « Consultation du cerveau MyBrain », « Recherche dans Gmail »… — avec les mêmes libellés que la trace affichée au-dessus de sa réponse. Une annonce par type d'action et par tour, quatre au plus, jamais pour une action en échec, et jamais par-dessus sa voix.
- **Volume** : le curseur **Volume de la voix** du bandeau règle la voix et les sons, et se souvient de votre réglage sur cet appareil. Sur iPhone et iPad, le curseur n'apparaît pas (le navigateur ne laisse pas une page régler le volume) : les boutons de l'appareil règlent, et la voix sort par le haut-parleur.
- **Longueur des réponses** : la liste du bandeau choisit **Brèves**, **Standard** ou **Détaillées**, en pleine conversation. Le changement vaut pour votre prochaine question, pas pour la réponse en cours. À l'oral, MIA répond **plus court qu'à l'écrit** par défaut : une réponse lue se subit, là où une réponse écrite se parcourt.
- **Vitesse de lecture** : **Réglages → Préférences IA**, carte **Voix**, de 0,75× à 2×. Elle s'applique à la voix de MIA et à ses annonces, jamais aux sons de repère, et ne consomme aucun crédit supplémentaire. Les deux réglages suivent votre compte, sur tous vos appareils.
- Les réponses sont **plus courtes et sans mise en forme** qu'à l'écrit : MIA sait qu'elles seront lues. Un tableau ou un bloc de code n'est pas lu ; MIA en donne l'essentiel et propose de détailler par écrit.
- **Passer** (dans le bandeau) saute la lecture en cours — et arrête la réponse si elle s'écrit encore — et vous redonne la parole ; **Terminer**, un nouveau clic sur l'onde sonore, ou « **au revoir** » dit à voix haute, met fin à la conversation. Changer de conversation la termine aussi.
- **Sur téléphone et tablette, l'écran reste allumé** tant que la conversation vocale est en cours — sans quoi la mise en veille suspendrait l'application et couperait le dialogue pendant que MIA réfléchit ou parle. Il retrouve son délai de veille habituel dès que vous terminez. Si votre navigateur ne le permet pas (version ancienne, économiseur de batterie), la conversation fonctionne quand même : touchez l'écran de temps en temps.
- Un message **tapé au clavier** pendant une conversation vocale part normalement, mais sa réponse n'est pas lue.
- **Si le modèle ne peut pas répondre** (crédit épuisé chez l'éditeur, service surchargé, conversation trop longue…), la conversation vocale s'arrête et le message « **Conversation vocale interrompue — …** » reprend la cause affichée dans le fil ; l'encadré ⚠️ dit quoi faire (cf. *FAQ et dépannage* — « Ma réponse affiche un encadré ⚠️ d'erreur »). Une réponse qui s'arrête en route est lue jusqu'à sa dernière ligne, qui en donne la raison.
- Coût : les **minutes écoutées** (comme la dictée — y compris pendant la lecture quand l'interruption à la voix est allumée) et les **minutes lues** sont décomptées de votre quota — postes *Dictée vocale* et *Lecture à voix haute* de **Mon usage**. Quota atteint, la conversation ne démarre pas, ou s'arrête à la prochaine étape payante.
- Si un outil de génération est engagé (image, page web…), un tour vocal lui est confié comme un message écrit ; il n'y a alors rien à lire, MIA vous écoute de nouveau.

---

## 8. Choisir un assistant ou un modèle

- **Choisir le modèle** : cliquer le **sélecteur de modèle** (logo + nom) à droite, sous la pilule de saisie. La liste est regroupée par éditeur, avec un repère de **coût** (les € indiquent le prix relatif, pas la qualité).
  - Les modèles les plus capables — **Claude Fable 5.1** chez Anthropic, **GPT-6 Astra** chez OpenAI, s'ils sont activés sur votre instance — sont aussi les plus chers (environ deux fois Claude Opus 5 ou GPT-5.6) : ils consomment vos crédits plus vite. Réservez-les aux demandes difficiles (analyse longue, raisonnement délicat) ; pour le quotidien, les modèles courants suffisent largement. Claude Fable réfléchit toujours avant d'écrire : sur une question difficile, le premier mot peut mettre une à plusieurs minutes à venir, sans que la réponse soit bloquée — et elle peut être longue (un rapport entier).
- **Choisir un assistant** : cliquer l'icône **tête de robot** sous la pilule → la liste s'ouvre (avatar, nom, description, badge « **RAG** » si l'assistant est relié à une **base de connaissances** — infobulle « Assistant relié à une base de connaissances »), groupée en **assistants personnels** et **assistants de l'organisation**. Le message déjà tapé est **conservé** au démarrage de la conversation.
- **Appeler un assistant avec « @ »** : taper **@** dans le champ de saisie ouvre la même liste, **filtrée au fil des lettres** — `@alf` ne laisse que « Alfred ». La recherche ignore **majuscules et accents** (`@elo` trouve « Élodie ») et porte aussi sur les mots du nom (`@mode` trouve « MIA : mode d'emploi »).
  - **Naviguer** : ↑ / ↓ pour changer de ligne, **Entrée** (ou **Tab**) pour valider, **Échap** pour refermer sans rien changer. Un clic sur la ligne fonctionne aussi.
  - **Validation** : une **nouvelle conversation** s'ouvre avec cet assistant. Le « @nom » **disparaît** du texte — c'est un raccourci, pas du contenu — et **le reste de votre message vous attend dans le champ de saisie**, prêt à être relu puis envoyé. Rien n'est envoyé automatiquement.
  - Le **@** ne déclenche la liste qu'en **début de message ou après un espace** : une adresse email tapée dans un message (`bob@exemple.fr`) n'ouvre rien. Si aucun assistant ne correspond, la liste l'indique et **Entrée envoie le message normalement**.
- **Conversation avec un assistant** : le modèle est **imposé** par l'assistant (le sélecteur de modèle disparaît, la barre du haut aussi). L'assistant se présente **sur la première ligne de la pilule de saisie** : son avatar cerclé de sa couleur, la mention **Assistant** et son nom. La bordure et le halo de la pilule prennent la même couleur — rien d'autre à l'écran ne change de teinte : ce contour dit « vous écrivez à cet assistant ».
  - Un clic sur son nom (ou sur le chevron) **déplie ses caractéristiques** : la description, le modèle et sa version, les outils qu'il peut appeler de lui-même, le nombre de bases de connaissances qu'il consulte (leurs noms au survol). Un second clic les replie. Elles sont **repliées par défaut**, et à chaque changement de conversation, pour laisser la place aux messages.
  - Si vous gérez cet assistant (créateur ou administrateur), un bouton **Modifier**, à droite de cette ligne, ouvre sa fiche.
  - Tant que la conversation est **vide**, l'assistant se présente aussi au centre de l'écran (avatar, nom, description) ; cette présentation s'efface au premier message.
  - **Sur mobile**, la ligne se réduit à l'avatar, au nom et au chevron ; le bouton **Modifier** devient une simple icône de crayon.
- **Assistant documenté** : avant de répondre, une étape s'affiche brièvement — « Recherche dans mes documents de référence… » (documents propres à l'assistant) ou « Recherche dans les bases de connaissances… » (bases partagées, celles du badge « RAG »). L'assistant fonde alors sa réponse sur les extraits les plus pertinents de ces sources.
  - Cette étape couvre **aussi** les bases que vous avez vous-même cochées sous le champ de saisie (voir [§ 4](#4-démarrer-et-mener-une-conversation)) : la ligne les nomme et précise lesquelles viennent de vous.

---

## 9. Génération d'images

1. Cliquer le bouton **+** sous la pilule de saisie → section **Créer** → choisir un outil **image** (icône image ; survolez un outil pour lire ce qu'il fait). (Ou accepter la **suggestion automatique** quand votre message décrit une image — voir section 4.)
2. Décrire l'image voulue ; possibilité de joindre une image de départ.
3. Envoyer → message « 🎨 Génération en cours… (15–30 s) ».
4. L'image s'affiche avec quatre actions : **Copier**, **Regénérer** (une autre version de l'image : même demande, même outil — la nouvelle remplace l'ancienne), **Télécharger l'image**, **Partager** (lien à envoyer à un collègue — voir section 14).

**La conversation est prise en compte automatiquement.** Le modèle d'image ne lit pas l'échange : MIA traduit donc votre demande en description complète à partir de ce qui précède. Après avoir discuté d'une affiche de festival, « fais-en une version verticale » suffit — inutile de tout redonner. Les références implicites (« ça », « le même », « lui ») sont résolues à partir de la conversation, et le style de l'assistant actif est appliqué.

**Ajuster l'image obtenue.** Demander une correction (« mets-lui un chapeau », « en bleu nuit », « enlève le texte en bas », « la même en format paysage ») **repart de la dernière image** de la conversation au lieu d'en recréer une de zéro : seul le changement demandé est appliqué. La dernière image envoyée en pièce jointe fonctionne de la même façon. Pour repartir d'une page blanche, formuler une **nouvelle** demande (« génère une nouvelle image de… »).

**Reprendre les images d'un assistant ou d'une base.** Les images des **documents de référence** d'un assistant, et celles des **bases de connaissances** de la conversation (celles de l'assistant et celles que vous cochez sous le champ de saisie), servent de modèles. Nommez-les ou décrivez ce qu'elles montrent — « notre mascotte qui fait du ski », « le flacon de la photo produit, sur fond de plage » — et MIA les transmet au moteur (4 au plus), qui reprend fidèlement le personnage, le produit ou le logo. La ligne **Génération d'une image — D'après « … »** sous l'image nomme celles qui ont servi. Le moteur redessine : la ressemblance est forte, jamais identique au pixel près.

> **Tous les modèles ne savent pas retoucher.** La retouche — et la reprise d'images de référence — nécessite un modèle image-to-image (GPT Image, modèles ChatGPT natifs, Nano Banana). Avec DALL-E ou Imagen, chaque demande produit une image entièrement nouvelle. L'outil est choisi par votre administrateur.

> **Revenir au mode normal.** L'outil reste actif jusqu'à ce que vous l'éteigniez : cliquer la **croix** de sa pastille (ou recliquer l'outil dans le menu **+**) remet la conversation sans outil (réponses en texte). Voir [§ 4](#4-démarrer-et-mener-une-conversation).

---

## 10. Génération de pages HTML

La page générée est un **site autonome** : tout (HTML, CSS, JavaScript) tient dans un seul fichier, soigné et responsive par défaut.

1. Cliquer le bouton **+** sous la pilule de saisie → section **Créer** → choisir un outil **page HTML** (icône code). (Ou accepter la **suggestion automatique** quand votre message décrit une page ou un site — voir section 4.)
2. Décrire la page voulue (ex. « un formulaire de contact responsive »).
3. Un **aperçu** s'ouvre à droite : onglet **Code** (pendant la génération), puis bascule automatique sur l'onglet **Aperçu** (page rendue).
4. Boutons disponibles : **Code / Aperçu**, **Bureau / Mobile** (tester le rendu responsive), **Rafraîchir** (rejouer animations), **Éditer** (crayon — retoucher le contenu à la main, voir ci-dessous), **Télécharger**, **Ouvrir dans un nouvel onglet**, **Fermer**.
5. **Modifier** : il suffit de continuer la conversation (« change la couleur en bleu », « ajoute un pied de page ») — MIA **repart de la page existante** et n'ajuste que ce que vous demandez, sans rien reconstruire ni perdre. Après chaque ajustement, un **court résumé des modifications** apparaît dans le fil, sous la carte de la page.

> **Le mode itération est automatique.** L'outil **Page HTML** reste actif comme tous les outils (voir [§ 4](#4-démarrer-et-mener-une-conversation)) : chaque nouveau message améliore la page. Mieux, une conversation qui **contient déjà une page générée** réarme la pastille toute seule, même ouverte depuis un autre appareil. Pour poser une question *sans* modifier la page (« explique-moi ce que fait ce code »), retirez la pastille via sa **croix** — vous pourrez la réactiver via le menu **+**. Sur un **site illustré**, les ajustements conservent les images déjà en place (pas de nouvelle fenêtre de choix du générateur d'images à chaque itération).

> **Vous n'êtes pas obligé d'attendre** : la page se construit sur le serveur, vous pouvez faire autre chose entre-temps (voir [§ 4](#4-démarrer-et-mener-une-conversation)).

**Éditer le contenu à la main.** Le bouton **Éditer** (icône crayon) de la barre d'aperçu bascule la page en mode édition :

- **survoler** un élément (titre, paragraphe, bouton, image…) le met en surbrillance ; **cliquer** dessus ouvre un petit menu flottant ;
- **texte** : l'élément devient directement modifiable au clavier ; les boutons **B** (gras), **I** (italique), **Normal** (retirer la mise en forme) et **🔗** (lien hypertexte, avec saisie de l'adresse) s'appliquent au texte sélectionné ;
- **images** : le bouton **🖼️** insère une image après l'élément cliqué — **depuis votre ordinateur** (JPEG, PNG, GIF ou WebP, 5 Mo max) ou **par URL** ; cliquer une image existante permet de la **remplacer** ou la **supprimer** ;
- **sauvegarde automatique** : chaque modification est enregistrée d'elle-même quelques instants après (indicateur « Enregistré ✓ » dans la barre) — rien à valider.

Les retouches sont conservées : le téléchargement, l'ouverture dans un nouvel onglet et les **modifications ultérieures demandées à l'IA** partent toujours de la dernière version éditée. Recliquer **Éditer** ressort du mode édition et rend la page à nouveau interactive.
6. **Redimensionner le panneau** : glisser son bord gauche ; double-clic pour revenir à la largeur par défaut.
7. **Arrêter** : cliquer la **croix** de la pastille d'outil (à côté du bouton **+**) pour revenir aux réponses en texte.
8. **Partager** : chaque page reste dans le fil sous forme de carte « page.html » — son bouton **Partager** génère un lien à envoyer à un collègue (voir section 14).

**Variante « Site illustré ».** Certains outils génèrent en plus des **images d'illustration** liées à votre demande (visuel d'en-tête, photos de sections…). Au lancement, une fenêtre vous laisse choisir le **générateur d'images** à utiliser (ceux sans clé d'accès configurée apparaissent **grisés**, non sélectionnables).

Le **style graphique** des images est celui choisi par le créateur de l'assistant : il est **déjà appliqué**, affiché en clair (ex. *Style des images : Illustration*). Vous n'avez rien à régler — lancez directement. Pour le changer ponctuellement, cliquez sur **« changer »** : vous pourrez choisir *Aucun* (l'IA décide), *Photoréaliste*, *Illustration*, *Minimaliste*, *3D / rendu*, ou *Libre* (vous décrivez le style voulu).

Pendant la création, une progression s'affiche (« Génération des images (n/N)… »), puis les visuels apparaissent dans la page. Le fichier **téléchargé** embarque ces images : le site reste autonome et s'ouvre hors ligne.

Le **coût en crédits** d'un site illustré cumule l'IA qui rédige le HTML **et** la génération de chaque image — le total est répercuté dans le coût de la conversation. Dans la liste des outils, l'indicateur de coût (€) d'un outil « Site illustré » reflète cette estimation globale (LLM + images).

> **Aperçu étanche.** La page s'affiche dans un bac à sable isolé de MIA : elle ne peut ni accéder à votre session, ni sortir du panneau, ni envoyer la moindre donnée vers l'extérieur (aucun appel réseau, aucun formulaire). Pour que les pages bâties sur un cadre CSS s'affichent correctement, l'aperçu autorise uniquement le chargement de styles, scripts et polices depuis quelques CDN publics de confiance (Tailwind, jsDelivr, cdnjs, unpkg, Google Fonts) ; tout autre hôte est bloqué. Les images affichées sont celles générées **par MIA** (variante « Site illustré »), celles ajoutées via le mode **Éditer** (fichier local ou URL https) — et rien d'autre. Le fichier **téléchargé** reste complet et utilisable tel quel.

---

## 11. Génération de vidéos

1. Cliquer le bouton **+** sous la pilule de saisie → section **Créer** → choisir un outil **vidéo** (icône lecture). (Ou accepter la **suggestion automatique** quand votre message décrit une vidéo — voir section 4.)
2. La fenêtre « **Paramètres de la vidéo** » s'ouvre : **API fournisseur** (en lecture seule — rappelle qui fabrique la vidéo, rien à saisir), **Modèle**, **Durée**, **Taille**, **Format de sortie**, et éventuellement une **Image de référence** (*Aucune* / *Image de début* / *Image de fin*).
3. Cliquer **Générer** → message « 🎬 Génération vidéo en cours… ».
4. La vidéo s'affiche dans un lecteur, avec des boutons de téléchargement et de **partage par lien** (voir section 14).
5. **Arrêter** : cliquer la **croix** de la pastille d'outil (à côté du bouton **+**) pour revenir aux réponses en texte.

> Si un outil apparaît **grisé** dans le menu, la raison est écrite sous son nom : son dernier test a échoué, ou son modèle a été arrêté par son fournisseur (« Arrêté par son fournisseur le … »). Dans les deux cas : prévenir un administrateur.

---

## 12. Cartes et itinéraires

MIA peut **dessiner un trajet sur une carte OpenStreetMap**, affichée dans un volet à droite de l'écran.

1. Cliquer le bouton **+** sous la pilule de saisie → section **Créer** → choisir un outil **carte** (icône carte).
2. Décrire le trajet en langage naturel : « *road-trip de 3 jours entre Lyon et Nice par les gorges du Verdon* », « *itinéraire à vélo Nantes → La Baule avec une pause déjeuner* »…
3. Le volet droit s'ouvre : la carte affiche les **étapes numérotées** (cliquer un marqueur → nom et indication de l'étape), le **tracé du trajet** et, sous la carte, les **notes** : résumé, mode de déplacement, **distance et durée estimées**, liste des étapes avec leurs conseils. Le tracé suit le réseau adapté au mode de déplacement : **routes** en voiture, **axes cyclables** à vélo, **sentiers et chemins piétons** à pied.
4. **Adapter le trajet** : rester dans la même conversation, outil toujours sélectionné, et demander une modification (« *ajoute une étape à Aix-en-Provence* », « *évite ce détour, passe en mode vélo* »). La carte est **mise à jour** en conservant ce qui n'était pas concerné.
5. Boutons du volet :
   - **📄 (notes)** — affiche / masque le panneau de notes sous la carte.
   - **⬇️ PDF** — télécharge un **PDF** contenant la carte affichée (cadrage et zoom actuels) **et les notes du trajet** : titre, mode de déplacement, distance et durée, résumé, étapes avec leurs conseils, avertissements éventuels.
   - **↗ Google Maps** — ouvre le même itinéraire dans **Google Maps** (nouvel onglet), pratique pour la navigation GPS. Au-delà de 11 étapes, Google Maps limite : les premières étapes sont conservées.
6. Chaque trajet reste accessible dans le fil : bouton **« Ouvrir la carte »** sur le message, à côté du bouton **Partager** (lien à envoyer à un collègue — voir section 14).

**Randonnées et trajets à pied.** L'assistant place les étapes **sur le sentier** (départ de sentier, col, refuge, lac…) avec leurs **coordonnées GPS** — y compris pour les lieux sans adresse postale — et le tracé suit les **chemins pédestres**, pas les routes. La **durée affichée tient compte du dénivelé** (estimation de l'assistant, ex. *≈ 5 h 30 de marche, dénivelé +800 m*) ; à défaut, la durée « à plat » du calculateur est signalée **« hors dénivelé »**. Les temps indiqués dans les notes et le résumé sont cohérents avec la carte.

> Certaines étapes peuvent être signalées **« non localisée »** si le lieu n'a pas pu être trouvé sur la carte : préciser la ville ou l'adresse et redemander. Si le calcul du tracé est momentanément indisponible, la carte relie les étapes en **ligne droite pointillée** (« tracé approximatif »).

---

## 13. Présentations (diaporamas)

Une présentation se demande **dans la conversation** : *« Fais-moi une présentation PowerPoint de notre offre, une dizaine de slides »*. MIA la livre sur une **carte** PowerPoint (voir [Télécharger un fichier créé par MIA](#télécharger-un-fichier-créé-par-mia)) qui porte quatre boutons : **Télécharger** (au modèle PowerPoint de l'entreprise s'il y en a un), **Modifier les slides** et **Version soignée** (un modèle compose lui-même le fichier, d'après votre demande, vos fichiers joints et le modèle de l'entreprise — ou, si votre organisation utilise Gamma, **Par Gamma**). Pour changer le contenu, demandez-le dans la conversation : MIA renvoie la présentation entière.

L'ancien outil **Présentation** du menu **+** n'est plus proposé : son éditeur est désormais derrière **Modifier les slides**. Les présentations qu'il a créées restent dans leurs conversations, ouvrables et exportables.

**Modifier les slides** ouvre la présentation dans un **volet à droite**, directement en mode édition sur ordinateur ; une carte de présentation rejoint aussi la conversation pour la rouvrir plus tard. Deux clics sur la même carte rouvrent la même présentation tant qu'elle n'a pas été retouchée ; retouchée, un nouveau clic repart du texte de la carte, dans une nouvelle. Dans le volet :

1. **Naviguer** : flèches du volet, **flèches du clavier**, ou clic sur une vignette. Clic dans l'aperçu : à gauche pour revenir, ailleurs pour avancer. Le bouton **plein écran** agrandit le diaporama.
2. **Modifier la présentation** (ordinateur uniquement — la retouche au doigt sur un écran de téléphone n'est pas assez précise, le bouton n'y apparaît donc pas) : le bouton **crayon** de la barre du volet passe en mode édition. Sur la slide affichée :
   - **cliquer** un élément (titre, texte, puces, image, bandeau) le sélectionne : un cadre violet et huit poignées apparaissent ;
   - **glisser** l'élément le déplace, **tirer une poignée** le redimensionne. Maintenir **Maj** conserve les proportions (pratique sur une image) ;
   - des **repères roses** s'affichent quand l'élément s'aligne sur un autre ou sur le centre de la slide : le déplacement « tombe juste » tout seul. Maintenir **Alt** désactive cet aimant ;
   - **double-cliquer** un texte permet de le réécrire sur place (**Échap** valide) ;
   - **Suppr** retire l'élément sélectionné, les **flèches** le déplacent finement (**Maj** pour de plus grands pas), **Échap** désélectionne.
   - un élément **recouvert par un autre** (l'image sous le voile d'une slide « image pleine page ») s'attrape en **cliquant une seconde fois** au même endroit : chaque clic descend d'un cran.
   Une **barre de mise en forme** s'affiche en haut du volet :
   - **+ Texte** insère une nouvelle zone de texte au centre de la slide affichée — double-cliquez-la pour écrire, puis placez-la où vous voulez ;
   - **Image** insère une illustration, au choix **depuis votre ordinateur** (JPEG, PNG, GIF ou WebP, 5 Mo maximum) ou **générée par l'IA** à partir d'une description. Elle arrive sélectionnée : tirez ses poignées pour la dimensionner, **Maj** conserve ses proportions. La génération n'est proposée que si un outil image est disponible ;
   - **G** et **I** mettent le texte sélectionné en gras ou en italique ;
   - la liste de **taille** (Très petit → Titre) et celle de **police** (thème, sans serif, serif, chasse fixe) s'appliquent à la zone entière ;
   - le **carré de couleur** change la couleur du texte ; la flèche circulaire à côté revient à la couleur du thème.
   La mise en forme vaut pour **toute la zone de texte** sélectionnée (ou toute la liste de puces), pas pour un mot isolé. Les polices proposées sont volontairement peu nombreuses : ce sont celles qui s'exportent à l'identique en PowerPoint et en PDF.
   Les modifications sont **enregistrées automatiquement** — l'indicateur « Enregistré ✓ » le confirme en haut du volet. Re-cliquer le crayon quitte le mode édition.
3. **Exporter** ouvre un menu avec trois formats :
   - **PowerPoint (.pptx)** — chaque slide est reconstruite en objets modifiables (zones de texte, puces, images, aplats de couleur) : le fichier s'ouvre dans PowerPoint, Keynote ou Google Slides et reste entièrement retouchable. Les notes du présentateur sont conservées.
   - **PDF (paysage)** — une page 16:9 par slide, prête à envoyer ou à imprimer.
   - **Page HTML** — un fichier autonome (images comprises) qui s'ouvre dans n'importe quel navigateur et se projette tel quel.
   L'export part de la présentation **telle qu'elle est enregistrée** : vos déplacements et réécritures y sont donc repris.

Le fil de conversation conserve la présentation : bouton **« Ouvrir »** sur le message, à côté de **Partager** (lien à envoyer à un collègue — voir section 14).

> **Sur mobile**, le volet s'ouvre en plein écran : la slide s'affiche en haut, les vignettes en dessous en grille — touchez-en une pour l'afficher. Consultation et exports fonctionnent ; seule la retouche des slides demande un ordinateur.

---

## 14. Partager une création par lien

Chaque **création générée** dans le chat — page HTML, image, vidéo, carte/itinéraire ou présentation — peut être partagée avec un collègue via un **lien unique**.

1. Sur la création (carte « page.html », carte vidéo, carte d'itinéraire, carte de présentation, ou sous une image générée), cliquer **Partager**.
2. Le lien est **copié dans le presse-papier** : le coller dans un e-mail, un chat d'équipe…
3. Le destinataire ouvre le lien : il voit **uniquement la création** (avec le nom du partageur et la date), jamais la conversation d'origine.

À savoir :
- Le lien n'est **visible que par les utilisateurs connectés** à MIA : un destinataire non connecté est renvoyé vers la page de connexion. Ce n'est pas un lien public.
- Le bouton devient **Partagé** : le re-cliquer ouvre un petit menu — **Copier le lien**, **Régénérer le lien** (l'ancien lien cesse de fonctionner) ou **Révoquer le lien** (plus personne ne peut l'ouvrir).
- Supprimer la conversation supprime aussi les liens de partage de ses créations.
- Sur une page HTML partagée, le destinataire voit la **dernière version** de la page (y compris vos modifications faites dans l'éditeur visuel).
- Une **présentation partagée** s'ouvre directement en diaporama : le destinataire navigue aux flèches du clavier ou en cliquant, comme dans MIA.

> Toutes vos créations, partagées ou non, sont regroupées au même endroit : **Mes créations**, la tuile **Créations** de la barre latérale (voir § 18).

---

## 15. Schémas et diagrammes

MIA peut afficher des **schémas Mermaid** directement dans ses réponses (flux/processus, architecture, séquence, organigramme, arbre de décision, planning Gantt, relations entre entités).

- **Obtenir un schéma** : le demander (« fais-moi un schéma… », « diagramme de… »). L'assistant en propose aussi de lui-même quand un schéma clarifie la réponse.
- **Affichage** : le diagramme est rendu comme une image dans la réponse, **aux couleurs de la plateforme** — il suit votre thème clair/sombre (**Réglages → Affichage**) et la couleur d'accentuation choisie par l'administrateur.
- **Télécharger** : au survol du schéma, deux boutons apparaissent — **PNG** (image, idéale pour coller dans un document) et **SVG** (vectoriel, net à toute taille, éditable). Les fichiers exportés conservent le fond et les couleurs affichés. Sur mobile, les boutons restent visibles.
- Si la syntaxe générée est invalide, une note « Schéma non rendu » s'affiche à la place : relancer ou reformuler la demande.

---

## 16. Actions sur les messages

Sous chaque réponse de l'assistant :
- **📋 Copier** — copie le texte (ou l'image).
- **🔄 Regénérer** — relance la même demande (même modèle, même réglage Internet, même outil). Sous une image créée par un outil image, c'est l'outil qui produit une **autre version de l'image** ; s'il n'est plus disponible, l'image reste en place et un message propose d'en choisir un autre dans le menu **+**. **Les connecteurs et la
  recherche Internet ne sont pas rejoués** : MIA ne peut donc pas relire un document Drive ou un mail consulté plus
  tôt. Elle vous le dit quand c'est le cas — pour une réponse qui s'appuyait sur ces sources, **renvoyez votre demande**
  plutôt que de la régénérer.
- **📄 Exporter en PDF** / **Word** — pour les réponses en texte. Le document porte le logo et la mention de pied de page de votre entreprise, ou suit son modèle Word s'il a été déposé.
- **⬇️ Télécharger** — pour une image ou une vidéo générée, et pour chaque fichier livré dans la réponse (voir ci-dessous).

**Vos propres messages** peuvent aussi être copiés : **cliquer sur le texte** d'un de vos messages fait apparaître un bouton **📋 Copier** juste en dessous (re-cliquer le texte, ou cliquer ailleurs, le masque). Pratique pour réutiliser un prompt dans une autre conversation.

**Copier une partie seulement.** Sélectionner un passage à la souris puis faire `Ctrl+C` (`Cmd+C` sur Mac) fonctionne aussi. Dans les deux cas — bouton ou sélection — le texte est collé **sur fond transparent**, en gardant sa mise en forme (titres, listes, tableaux, gras, liens) : plus de rectangle gris à effacer dans Word, Gmail ou Google Docs.

### Télécharger un fichier créé par MIA

Demandez un **fichier** en nommant le format voulu — *« prépare le budget 2026 dans un fichier Excel »*, *« rédige le compte rendu en Word »*, *« fais-moi une présentation PowerPoint du projet »*, *« exporte la liste en CSV »*, *« donne-moi ces notes dans un fichier texte »* — et MIA le livre dans la réponse, prêt à télécharger. Cela fonctionne avec **tous les modèles**, et avec les assistants.

- **La carte du fichier** affiche son nom, son type et un **aperçu** : les premières lignes d'un tableau (avec leur nombre de lignes et de colonnes), le document mis en forme, le début d'un texte ou d'un script. **Afficher tout** déplie un aperçu long ; pendant que la réponse s'écrit, la carte indique « Création en cours… ».
- **Télécharger** enregistre le fichier dans le format annoncé. La **flèche** à côté ouvre **Autres formats** : **Excel** ou **CSV** pour un tableau ; **Word**, **PDF**, **RTF**, **Markdown** ou **texte** pour un document ; **PowerPoint**, **PDF** (une page par slide), **Word** ou **Markdown** pour une présentation. L'icône **Copier** de la carte copie le contenu tel que MIA l'a écrit.
- **Formats créés** : Excel (.xlsx), CSV, Word (.docx), PDF, RTF, PowerPoint (.pptx), texte (.txt), Markdown (.md), et les fichiers de données et de code (JSON, XML, YAML, SQL, agenda .ics, script Python, JavaScript…).
- **Un classeur est prêt à l'emploi** : en-tête en gras et figé, filtre automatique, colonnes à la bonne largeur. Les nombres, montants, pourcentages et dates sont de **vraies valeurs** (sommes et tris fonctionnent), les codes comme un code postal « 01000 » gardent leur zéro, et les formules de calcul (somme, moyenne, condition…) sont conservées, déjà calculées. Une formule qui irait ouvrir un lien ou un autre fichier est écrite comme du **texte**, par sécurité — de même, dans un CSV, une cellule qu'un tableur exécuterait est désamorcée.
- **Un document Word** a de vrais titres (volet de navigation, table des matières), de vraies listes et des tableaux ; le **PDF** et le **RTF** reprennent la même mise en forme.
- **Une présentation PowerPoint** est mise en page automatiquement, sans image, aux couleurs de votre organisation : une **couverture** sur fond sombre (titre et sous-titre), puis **une slide par partie** — une idée par slide, en quelques puces courtes. Selon le contenu, une slide prend une forme dédiée : 2 à 6 notions avec leur explication (« **Délais** — trois jours au lieu de dix ») deviennent des **cartes** numérotées ; 2 à 6 chiffres avec leur légende, des **chiffres clés** en grand ; beaucoup de points très courts, des **pastilles** sur deux colonnes ; des dates, une **chronologie** ; un tableau, un **vrai tableau** (découpé sur plusieurs slides s'il est long) ; deux sous-parties (avant / après), une **comparaison** en deux colonnes ; 3 à 6 étapes numérotées, des **étapes**. Une partie sans contenu devient un **intercalaire** numéroté (01, 02…), une citation seule une **slide citation**, et les **notes du présentateur** demandées se retrouvent sous chaque slide. Pour une forme précise, demandez-la (*« mets les chiffres clés en avant »*). Une slide trop chargée est découpée en deux (« Titre (suite) ») plutôt que rapetissée ; au-delà de 30 slides, la carte propose le Word. Pour la retoucher à l'écran ou l'illustrer : **Modifier les slides** sur la carte (section 13).
- **Aux couleurs de votre entreprise** : si votre administrateur a déposé le **modèle PowerPoint** de l'entreprise, la carte l'annonce (« au modèle de … ») et la présentation téléchargée est construite **dans ce modèle** — ses mises en page, ses polices, ses couleurs, le nom de l'entreprise en pied de page. Sinon, elle porte le **logo** et le **nom** de l'entreprise s'ils ont été renseignés. Le PDF « une page par slide » garde la mise en page de MIA, aux couleurs du modèle.
- **Version soignée** : le bouton de la carte d'une **présentation** ou d'un **classeur** fait composer le fichier par un **modèle lui-même**, avec sa propre mise en page — ou, pour une présentation, par **Gamma** si votre organisation l'utilise : un petit menu propose alors **Par …** (le modèle, avec le temps et le coût estimés) et **Par Gamma**, votre dernier choix signalé. La version rapide de MIA, immédiate et sans crédit, reste au bouton **Télécharger**.
- **Par un modèle**, une fenêtre s'ouvre avant le lancement (dans la carte sur ordinateur, en bas de l'écran sur téléphone). **Réalisée par** : le modèle de la conversation s'il en est capable ; sinon MIA en choisit un — le modèle par défaut de votre organisation, ou un modèle standard qui sait le faire, jamais un modèle haut de gamme d'office — et le dit ; la conversation garde son modèle. Vous pouvez en choisir un autre parmi ceux qui vous sont ouverts, chacun avec son coût estimé (*Le plus économique*, *Haut de gamme*…). **Ce qui part chez** l'éditeur (Anthropic ou OpenAI) : le contenu de la carte et **votre demande** (vos derniers messages avant la carte : *« mets la photo de l'équipe en couverture »*, *« ajoute l'écart à l'objectif »*) partent toujours ; les **fichiers joints à la conversation** avant la carte (photos, PDF, classeurs, documents — les huit plus récents) et, pour une présentation, le **modèle PowerPoint de l'entreprise tout entier** — ses slides d'exemple lui montrent le style maison sans se retrouver dans le fichier — avec son **logo**, se décochent un à un ; un fichier joint plus tôt dans la conversation est signalé et décoché d'office. **Une précision pour la mise en page ?** (facultatif) : *« style sobre, un chiffre clé par slide »*. Le **coût estimé** est la moyenne de vos dernières réalisations du même genre avec ce modèle, sinon une estimation d'après son tarif. Un classeur réalisé par un modèle ajoute des formules là où elles servent (totaux, écarts) et un graphique Excel quand la demande en suggère un. Au lancement, comptez 1 à 3 minutes : un message ⏳ l'annonce, puis un **lien** vers le fichier arrive dans la conversation ; le fichier est **gardé 30 jours** et la réalisation est **comptée dans vos crédits**. Si aucun modèle qui vous est ouvert ne sait le faire, le bouton est grisé et dit pourquoi.
- **Par Gamma** : après confirmation, la présentation part chez **gamma.app**, qui la met en page — thème, illustrations, une carte par slide — **sans réécrire le texte** (les notes du présentateur n'y vont pas). Comptez 1 à 3 minutes : un message ⏳ l'annonce, puis le **lien** vers la présentation et son **export** (PowerPoint, ou PDF selon le réglage de l'organisation) arrivent dans la conversation. Chaque création consomme des **crédits Gamma** de l'organisation, pas vos crédits MIA ; une présentation plus longue que le plafond fixé par l'administrateur est refusée, et un second clic sur la même présentation ne lance pas une seconde création.
- **Formats impossibles** (ancien Excel .xls, Word .doc ou PowerPoint .ppt, Keynote, archive .zip, image, vidéo) : la carte l'explique et propose le plus proche — par exemple **Télécharger en PowerPoint (.pptx)** pour une présentation demandée en Keynote. Une image se crée avec l'outil **Image** du menu **+**.
- **Modifier un fichier** : demandez-le dans la conversation (« ajoute une colonne TVA ») — MIA renvoie le fichier complet, dans une nouvelle carte.
- **Sans carte** : un bloc de code ordinaire (un tableau CSV, un script) porte un bouton **Télécharger** dans son en-tête, à côté de **Copier** ; les images de la réponse ont leur bouton de téléchargement (au survol sur ordinateur, en permanence sur téléphone, et dans l'image agrandie).
- **En cas d'échec** (connexion coupée, fichier trop volumineux), la carte le dit et propose **Réessayer**, un autre format, ou le **Contenu brut** — le texte enregistré tel quel, sans passer par le serveur. Une réponse interrompue pendant l'écriture d'un fichier affiche « Fichier incomplet » : le fichier reste téléchargeable tel quel, ou vous pouvez regénérer la réponse.
- **Rien n'est stocké à part** : le fichier est fabriqué au moment du clic, à partir du contenu affiché. Tant que la conversation existe, vous pouvez le retélécharger, dans le format de votre choix. Seul un fichier **réalisé par le modèle** est gardé, 30 jours. **Copier** ou **exporter** la réponse en PDF / Word reprend le nom du fichier et son aperçu, sans les boutons.

---

## 17. Gérer ses conversations

Clic sur une conversation dans la liste → petit menu :
- **✎ Renommer**
- **📌 Épingler / Désépingler** (la fait remonter en haut)
- **🗑 Supprimer** (confirmation demandée — action définitive)

**Rechercher ou aller à…** : le bouton au-dessus de la liste (ou **⌘K**, **Ctrl+K** sur Windows et Linux, depuis n'importe quelle page, ou la tuile **Tout** des Espaces) ouvre une seule barre de recherche. Sans accents ni majuscules à respecter, elle trouve vos pages de travail et leurs actions (nouvelle conversation, nouvel assistant, nouvelle tâche, nouveau workflow selon vos droits), les pages de vos réglages (et « Reconnecter… » quand une connexion a expiré), celles de l'administration que vos droits ouvrent, et vos conversations dont le titre ou le contenu contient les mots tapés (dès deux lettres, termes surlignés). Un ancien nom retrouve la page renommée (« connexions » → **Comptes connectés**). **↑ ↓** pour se déplacer, **Entrée** pour ouvrir (**⌘/Ctrl + Entrée** : nouvel onglet), **Échap** pour fermer ; sur téléphone, elle prend tout l'écran et **Annuler** la referme.

### Épingler met une conversation à l'abri du ménage

Votre administrateur peut faire le ménage dans les conversations et les créations trop anciennes,
pour libérer de la place. **Une conversation épinglée n'est jamais supprimée par ce ménage** :
c'est le moyen de conserver celles auxquelles vous tenez. Vous pouvez en épingler **5**.

Deux choses à savoir si vous cherchez quelque chose qui a disparu :

- **Une vieille conversation manquante** a probablement été retirée faute d'activité depuis
  plusieurs mois. Épinglez celles que vous voulez garder — dès maintenant, pas après.
- **Une ancienne image, vidéo, page web ou présentation** peut avoir été retirée alors que la
  conversation, elle, est toujours là : à sa place, une mention « *Création élaguée le …* ».
  Le texte de l'échange est conservé, seul le fichier a été supprimé, et son lien de partage ne
  fonctionne plus. Téléchargez ce qui compte pour vous depuis **Mes créations** (bouton
  **Télécharger**) plutôt que de compter sur MIA comme espace de stockage.

Les durées de conservation sont fixées par votre administrateur — demandez-lui lesquelles
s'appliquent chez vous.

---

## 18. Réglages personnels

Cliquer **Réglages**, en bas de la barre latérale : la page **Mon profil** s'ouvre (sur mobile, votre **avatar**, en haut à droite, y mène aussi). Le menu de gauche réunit, sous **Mon compte**, les cinq pages — **Mon profil**, **Préférences IA**, **Affichage**, **Comptes connectés** et **Usage & crédits** —, puis, plus bas, **À propos de MIA** et **Se déconnecter** (avec confirmation). Il commence par le lien **← Retour au chat**, dans un cadre en tête du menu, qui ramène aux conversations et reste en vue quand le menu défile (sur mobile, le menu s'ouvre par l'icône ☰, ce lien en haut du volet). **Mes créations** et les **Tâches programmées**, décrites dans cette section, s'ouvrent, elles, par leurs tuiles **Créations** et **Tâches** de la barre latérale.

> **Vos réglages s'enregistrent tout seuls.** Il n'y a plus de bouton « Enregistrer » dans vos réglages :
> chaque modification part peu après que vous l'avez faite, et une **pastille** en bas de l'écran confirme
> (**Enregistré** ✓). En cas de problème — champ obligatoire vide, réseau coupé — elle passe en ambre ou en
> rouge et vous propose **Réessayer** ; votre saisie n'est jamais perdue. **Seul le mot de passe garde son
> bouton** (« Changer le mot de passe ») : il ne doit jamais partir à moitié saisi.

**Onglet Mon profil :**
- En tête : votre nom, votre adresse e-mail et votre **rôle** (Utilisateur, Créateur, Admin…)
- **Nom complet**, **adresse e-mail**
- **Langue de l'interface** — français ou anglais. C'est le même réglage que le sélecteur en haut à droite : le choix suit votre compte sur tous vos appareils, et la page se recharge aussitôt dans la langue choisie.
- **Photo de profil** : **Choisir une image** (JPEG, PNG, WebP ou GIF, 2 Mo au plus) **ou** coller l'adresse d'une image en ligne dans **Ou URL d'image** — la photo est enregistrée aussitôt, et MIA en garde une copie (elle reste affichée même si l'image d'origine disparaît). Le champ d'adresse reste vide : inutile de le vider pour changer de photo. **Retirer la photo** revient aux initiales (avec un compte Google, la photo du compte revient à la connexion suivante ; une photo que vous avez choisie, elle, n'est jamais remplacée par celle de Google). Une image refusée (adresse qui mène à une page, image privée, plus de 2 Mo) est signalée, et le reste du profil s'enregistre quand même.
- **Ce que MIA sait de vous** — un renvoi vers **Préférences IA**, où vivent votre contexte et vos souvenirs **MIA pour vous** (voir ci-dessous)
- **Sécurité** (comptes locaux) : champ **Mot de passe actuel**, **Nouveau mot de passe** (au moins 8 caractères), puis bouton **Changer le mot de passe**. Comptes Google : la connexion est gérée par Google, pas de mot de passe local.

**Mes créations** (tuile **Créations** de la barre latérale) **:**

Toutes vos créations, **toutes conversations confondues**, réunies dans une seule galerie : images, vidéos, pages web, présentations et itinéraires, les plus récentes d'abord (1 000 dernières).

- **Aperçu réel** sur chaque vignette : l'image elle-même, la première image de la vidéo, un mini-rendu de la page web ou de la présentation, et pour un itinéraire **le tracé du trajet** avec ses étapes, surmonté du départ et de l'arrivée.
- **Rechercher** : le champ en haut filtre sur le titre de la création **et** le titre de sa conversation (accents et majuscules ignorés). La liste déroulante à côté filtre par type, et le compteur affiche le nombre de résultats.
- **Ouvrir** : cliquer une carte affiche la création en plein écran (image, lecteur vidéo, page web ou diaporama). Un itinéraire s'ouvre directement dans sa conversation, sur sa carte interactive.
- **Revenir au contexte** : le nom de la conversation sous chaque carte — comme le bouton **Ouvrir la conversation** de l'aperçu — ramène au fil d'origine, pour reprendre la création ou la modifier.
- **Télécharger** : bouton sur chaque carte. Une image ou une vidéo est enregistrée telle quelle ; une page web est enregistrée avec ses illustrations **intégrées au fichier** (elle s'ouvre depuis votre disque, sans connexion) ; une présentation propose les trois mêmes formats que dans le chat — **PowerPoint (.pptx)**, **PDF (paysage)** et **Page HTML**. Les itinéraires n'ont pas de bouton : leur export PDF se fait depuis la carte interactive, dans la conversation.
- **Partager** : bouton identique à celui du chat. Le premier clic crée le lien **et le copie**. Le bouton passe alors à **Partagé** (en couleur d'accentuation) ; le recliquer ouvre le menu **Copier le lien** / **Régénérer le lien** / **Révoquer le lien**. Un lien créé ici est le même que celui créé depuis le chat — il n'y a qu'un lien par création (voir § 14).

**Onglet Préférences IA :** la page se lit en trois parties, avec un sommaire en tête qui mène à chacune.
- **Comment MIA vous répond** → **Style de réponse** — sept cartes au choix : *Par défaut* (aucune consigne de votre part), *Concis*, *Détaillé*, *Technique*, *Vulgarisé*, *Formel*, *Convivial*. Chaque carte dit en une ligne ce que le style change ; la carte choisie porte une coche. Un seul style à la fois, pour toutes vos conversations.
- **Ce que MIA sait de vous** → **Votre contexte** — un texte libre (jusqu'à 500 caractères) décrivant votre métier, vos outils, votre cible. Ex. : *« Chef de produit chez ACME. Outils : Notion, Linear. Cible : équipes RH. »* Un compteur de caractères s'affiche. MIA s'en sert en arrière-plan sans le citer, et vous le restitue si vous lui demandez ce qu'elle sait de vous. Juste en dessous, **MIA pour vous** — votre mémoire long terme (voir ci-dessous).
- **À l'oral** → carte **Voix** — la vitesse de lecture et la longueur des réponses dites à voix haute. Cette partie n'apparaît que si la conversation vocale est proposée sur votre instance.

> Ces préférences s'ajoutent **à la fin** des instructions de l'assistant choisi. Laissées vides, elles ne changent rien.

**Onglet Affichage :** l'apparence de MIA et la touche d'envoi, pour vous seul. Le choix s'applique **à la page dès le clic** — c'est l'aperçu —, s'enregistre tout seul et suit votre compte sur tous vos appareils, application mobile comprise.
- **Thème** — quatre vignettes : *Par défaut* (celui que votre administrateur a choisi pour l'organisation, et qui suit ses changements), *Clair*, *Sombre* et *Automatique*, qui suit le réglage clair ou sombre de votre appareil — et bascule de lui-même quand l'appareil passe en mode sombre le soir, sans recharger la page.
- **Contraste** — *Standard* ou *Renforcé* : dans le thème choisi, textes et textes secondaires plus marqués, bordures nettement visibles, focus clavier épais, liens des réponses soulignés ; en thème clair, la couleur d'accentuation est assombrie pour rester lisible.
- **Taille d'affichage** — un **curseur** de **80 % à 150 %**, par pas de 5 (repères cliquables à 80, 100, 125 et 150 %, lien **Rétablir 100 %**), et à droite un **aperçu** — un extrait de conversation — qui suit le curseur pendant que vous le faites glisser ; la page entière s'ajuste quand vous le **relâchez** (elle ne bouge pas sous votre doigt pendant le geste). Toute l'interface grandit ou rétrécit d'un même facteur : texte, boutons, icônes, images et marges, comme avec le zoom du navigateur — que l'application mobile n'offre pas. Sur un écran étroit, la taille est **limitée** pour que tout reste utilisable : 130 % jusqu'à 1 024 px de large (tablette, petit portable), 115 % sur un téléphone ; l'écran le dit sous le curseur, et votre choix reprend effet sur un écran plus grand. Les raccourcis du navigateur (**Ctrl** et **+**) restent possibles en plus, pour l'appareil en cours seulement.
- **Envoi des messages** — *Entrée* (le défaut : **Entrée** envoie, **Maj + Entrée** va à la ligne) ou *Ctrl + Entrée* (**Ctrl + Entrée**, ou **⌘ + Entrée** sur Mac, envoie ; **Entrée** va à la ligne). Au doigt, avec *Ctrl + Entrée*, seul le bouton **Envoyer** envoie : pratique pour écrire plusieurs paragraphes sur un téléphone.

### MIA pour vous — votre mémoire long terme

La section **MIA pour vous** rassemble ce que MIA retient de vous d'une conversation à l'autre. Elle vit dans l'onglet **Préférences IA**, juste sous **Votre contexte** ; l'onglet **Mon profil** y renvoie d'un clic.

- **Alimenter depuis le chat** : demandez-le pendant une conversation (*« Souviens-toi que… »*, *« Retiens que… »*, *« Garde en mémoire que… »*). MIA reformule l'information en une phrase courte et l'ajoute à la liste, avec la mention **via chat**.
- **Alimenter à la main** : le champ en bas de la carte, puis **Ajouter** (ou la touche **Entrée**). Mention **ajouté ici**.
- **Modifier** : cliquez le texte d'un souvenir, corrigez-le, puis cliquez ailleurs (ou **Entrée**) pour enregistrer. **Échap** annule la modification en cours.
- **Supprimer** : l'icône **corbeille** à droite du souvenir. La suppression est immédiate.
- **Limite** : **50 souvenirs**, **300 caractères** chacun. Le compteur en haut de la carte affiche l'occupation ; une fois la limite atteinte, MIA vous invite à faire le ménage au lieu d'enregistrer.
- Les doublons sont ignorés automatiquement (accents, majuscules et ponctuation ne comptent pas).

**Ce que MIA en fait :** la liste est ajoutée à *« votre contexte »* au **début de chaque conversation**, quel que soit l'assistant ou le modèle utilisé. MIA s'en sert spontanément quand c'est pertinent, sans les énumérer à chaque réponse — **sauf si vous le demandez** : *« Que sais-tu de moi ? »* fait ressortir la liste complète, telle quelle, aux côtés de **Votre contexte** (voir [§ 4](#demander-à-mia-ce-quelle-sait-de-vous)).

**Ce qu'il vaut mieux y mettre :** votre rôle et votre service, vos préférences de format ou de ton par sujet, vos contraintes et habitudes de travail, vos outils. **Ce qu'il vaut mieux éviter :** des informations éphémères (elles vieilliront mal) et toute donnée sensible que vous ne souhaitez pas voir réutilisée dans vos échanges.

> Cette mémoire est **strictement personnelle** : elle n'est visible ni par les autres utilisateurs, ni par les créateurs d'assistants.

> **Connecteurs et modèle : vous gardez la main.** Les interrupteurs **Fichiers Google Drive**, **Emails Gmail**, **Agenda Google**, **Tâches Google**, **Emails Outlook**, **Agenda Outlook** et **Messages Slack** du menu **+** sont **toujours visibles et toujours activables** — sauf s'ils n'ont pas été mis en place par l'administrateur, auquel cas la ligne n'apparaît simplement pas. Presque tous les modèles savent piloter les connecteurs — ChatGPT, Claude, Gemini 3, Mistral, DeepSeek, Kimi, GLM ; Perplexity et quelques anciens modèles ne le savent pas. Avec **Mistral**, la recherche Internet et un connecteur allumé ne vont pas ensemble dans une même réponse : le connecteur passe avant, et MIA vous dit qu'elle n'a pas cherché en ligne. Si le modèle de la conversation ne sait pas piloter les connecteurs, l'activation ouvre une fenêtre : **choisissez un des modèles compatibles proposés** puis **Changer de modèle** — le connecteur s'allume et la conversation continue avec ce modèle. Vous préférez garder votre modèle ? **Garder ce modèle** : rien ne change, le connecteur reste éteint, c'est un choix parfaitement valable. Si **aucun** modèle disponible sur votre compte ne gère les connecteurs, la fenêtre vous l'indique — contactez un administrateur. Dans une conversation portée par un **assistant**, le modèle est imposé par l'assistant : la fenêtre l'explique et vous invite à ouvrir une conversation sans assistant. Enfin, si vous choisissez vous-même un modèle incompatible alors qu'un connecteur est allumé, celui-ci s'éteint (il serait ignoré) et **se rallume tout seul** dès que vous revenez sur un modèle compatible.

> **Vous ne voyez que ce que votre administrateur propose.** La page Comptes connectés n'affiche que les services
> qu'il a rendus visibles : si Gmail ou OneDrive n'y figure pas, c'est qu'il n'est pas proposé dans votre
> organisation — inutile de le chercher ailleurs. Si un service que vous aviez **déjà connecté** cesse d'être
> proposé, sa carte reste affichée avec la mention *« n'est plus proposé par votre administrateur »* et le seul
> bouton **Déconnecter** : vous gardez toujours la possibilité de retirer un accès que vous avez accordé. Et
> quand aucun service n'est proposé, la page vous le dit en une phrase.

> **Une connexion a expiré ? MIA vous le dit, discrètement.** Quand Google, Microsoft ou Slack cesse
> d'accepter la connexion d'un service que vous aviez relié (mot de passe changé, accès retiré par votre
> organisation, autorisation expirée), une **pastille ambre** apparaît sur votre **avatar** (en bas de la
> barre latérale, ou en haut à droite sur mobile) et sur l'entrée **Comptes connectés** du menu de vos réglages —
> son infobulle nomme le service : *« Connexion à reconnecter : Gmail »* —, et dans le menu **+** la ligne du connecteur indique *« Connexion expirée — cliquer
> pour reconnecter »* au lieu de se griser sans explication. Chacun de ces rappels mène à la page
> **Comptes connectés** — celui du menu **+**, droit à la carte concernée —, qui propose **Reconnecter mon…** — et, si vous ne voulez plus de ce
> service, **Retirer cette connexion**. La pastille s'éteint d'elle-même dans les deux cas. Elle ne concerne
> que les services encore proposés par votre administrateur : un service masqué ne peut pas être
> reconnecté, sa carte n'offre que le retrait.

**Onglet Comptes connectés — Google Drive :**
- Bouton **Connecter mon Google Drive** : une fenêtre Google demande votre consentement (lecture de votre Drive, écriture Google Docs et Sheets, renommage, déplacement et duplication de fichiers), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Écriture Docs**, **Écriture Sheets**, **Renommer / déplacer**, **Dupliquer**). **Laissez toutes les cases cochées** sur l'écran de Google : elles se décochent une à une, et chaque case oubliée retire la capacité correspondante — un badge affichant un tiret plutôt qu'un ✓ le signale, et le bouton **Reconnecter et autoriser** permet de la réaccorder à tout moment.
- **Activation par vos soins** : une fois le Drive connecté, l'accès se déclenche via l'interrupteur **Fichiers Google Drive** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé** — l'assistant ne touche jamais à votre Drive sans votre feu vert. Une fois activé, **il le reste** : votre choix est mémorisé sur ce navigateur et se retrouve au rechargement de la page comme dans une **nouvelle conversation**, jusqu'à ce que vous l'éteigniez. Activé, un **point ambre** sur le bouton **+** le rappelle. L'interrupteur est **grisé** tant que votre Drive n'est pas connecté (cliquer la ligne mène à cette page Comptes connectés). Si l'administrateur a désactivé la fonctionnalité, la ligne **n'apparaît pas** du tout dans le menu **+**.
- **Lire** : les assistants peuvent **rechercher** et **lire** vos documents Drive pendant une conversation — demandez par exemple *« Résume mon doc Budget 2026 »* ou collez directement l'URL d'un Google Docs / Sheets dans le message. La recherche couvre **Mon Drive**, les fichiers **partagés avec vous** et les **Drives partagés** de votre organisation (dans la limite de vos droits d'accès Google). Pendant la lecture, la bulle affiche *« Recherche dans votre Google Drive… »* puis *« Lecture du document Google Drive… »*. Formats lus : Google Docs, Sheets, Slides, Word, Excel, PowerPoint, PDF, texte, CSV… (fichiers jusqu'à 20 Mo ; les très gros documents sont tronqués).
- **Chercher un dossier trouve le dossier.** Les noms sont interrogés **à part** du contenu des documents : un dossier nommé « INITECH » ne peut plus être noyé sous les dizaines de fichiers qui *mentionnent* INITECH (un dossier n'a pas de contenu — il ne peut apparaître que par son nom). Dites simplement *« le dossier INITECH »* ou *« le répertoire client »* et la recherche se limite aux dossiers.
- **La recherche ne tient plus compte de la casse ni des accents**, et cherche les mots **séparément** : *« le compte rendu de la réunion d'équipe »* retrouve un fichier nommé `Réunion_Équipe-2026.docx`. Si rien ne sort du premier essai, MIA élargit toute seule (moins de mots, puis balayage de votre Drive) avant de conclure que le fichier n'existe pas.
- **Donnez le dossier, MIA y va directement.** Si vous précisez où chercher (*« dans mon dossier 01-CLIENTS »*, un lien de dossier, ou un chemin *« 01-CLIENTS/INITECH/03-CR et echanges »*), MIA **navigue dans cette arborescence** au lieu de lancer une recherche par mot-clé sur tout votre Drive — votre structure de dossiers est une indication plus sûre qu'un mot-clé, qui remonte d'abord le bruit du reste du Drive. Un chemin complet est descendu **en une seule fois**. Et dans un dossier qui contient trop d'éléments pour être listé d'un coup, MIA filtre par nom au lieu d'annoncer à tort que le fichier n'y est pas.
- **Ouvrir un dossier** : demandez *« Qu'y a-t-il dans mon dossier Factures ? »* ou *« Liste les fichiers du dossier Comptabilité »*. Un dossier ne se **lit** pas comme un document — il se **parcourt** : MIA en liste le contenu (sous-dossiers puis fichiers), et vous pouvez descendre de niveau en niveau ou lui demander de lire l'un des fichiers listés. Le nom du dossier suffit (accents et majuscules indifférents), son lien ou « racine » pour Mon Drive fonctionnent aussi. La bulle affiche alors *« Contenu du dossier Google Drive… »*.
- **Créer et modifier** (si autorisé) : demandez *« Crée un doc Compte-rendu du 22/07 avec ce résumé »*, *« Ajoute cette section à la fin de mon doc Roadmap »*, *« Remplace "T3" par "T4" dans mon doc Planning »* ou *« Ajoute ces lignes à mon sheet Suivi budget »*. L'assistant ne modifie **que sur demande explicite** et lit d'abord le fichier pour une modification ciblée.
- **Les Docs sont créés MIS EN FORME** : titres et sous-titres réels, gras, italique, listes à puces et numérotées, liens — plus de `##` ou de `**` en toutes lettres dans le document. Et l'assistant peut **mettre en forme un texte déjà présent** sans le réécrire : *« Mets "Budget 2026" en gras et en rouge dans mon doc Roadmap »*, *« Passe la ligne "Conclusion" en titre 2 »*, *« Mets le paragraphe d'intro en taille 14 »*. Couleurs par nom (rouge, bleu, vert…) ou code `#RRGGBB` ; la carte d'approbation annonce le style exact et le nombre d'occurrences concernées avant que quoi que ce soit ne change.
- **Les Sheets sont créés PRÉSENTÉS** : la première ligne devient un vrai en-tête (gras, fond gris, **ligne gelée** qui reste visible au défilement) et les colonnes sont ajustées à leur contenu — plus de tableau où l'on ne distingue pas les intitulés et où le texte est tronqué. Dites-le si votre première ligne n'est **pas** un en-tête, l'assistant s'abstiendra.
- **Mettre en forme une feuille existante** : *« Mets la ligne d'en-tête de mon sheet Budget en gras sur fond bleu »*, *« Passe la colonne D en monnaie »*, *« Mets les taux de la colonne C en pourcentage »*, *« Centre la plage B2:D20 »*, *« Gèle la première ligne »*. La plage se donne en notation classique (`A1:D1`, `C:C` pour toute une colonne, `Feuille2!B2:B50` pour un autre onglet). Formats disponibles : nombre, entier, monnaie (€), pourcentage, date, date et heure, texte. **Les valeurs ne sont jamais modifiées** — seule leur présentation change ; la carte d'approbation annonce l'onglet, la plage et le nombre de cellules concernées.
- **Votre approbation est toujours requise** : aucune création ni modification n'est exécutée directement. L'assistant prépare l'action et une **carte d'approbation** s'affiche sous sa réponse : elle indique **l'action exacte** (créer / modifier), le **fichier concerné** (avec son lien), la **liste des impacts** (ex. *« ajout en fin de document, contenu existant conservé »* ou *« ⚠ l'intégralité du contenu actuel sera remplacée »*) et un **aperçu du contenu**. Cliquez **Approuver** pour exécuter (la carte affiche alors le lien du fichier créé/modifié, et un message ✅ le confirme aussitôt dans la conversation) ou **Annuler** (rien n'est touché). Quand **plusieurs cartes** attendent (l'assistant a préparé plusieurs actions), une carte récapitulative apparaît sous la dernière avec **Tout approuver** et **Tout refuser** : chaque action est traitée comme si vous aviez cliqué sa carte — les emails exceptés, qui se relisent et se valident sur leur propre carte. Sans réponse de votre part, la demande **expire au bout de 30 minutes** sans rien modifier. Les cartes en attente réapparaissent si vous rechargez la page, **sous la réponse qui les a proposées**, et l'issue (exécutée, annulée, échouée) est conservée dans l'historique de la conversation.
- **Une modification sans effet est signalée, jamais annoncée comme faite.** Un remplacement (*« remplace "T3" par "T4" »*) n'aboutit que si le texte recherché figure **exactement** tel quel dans le document : la casse est ignorée, mais pas les accents, les apostrophes typographiques (’ au lieu de '), les espaces insécables ni les tirets longs — et ces différences sont invisibles à l'œil. Si rien ne correspond, la carte se conclut par un message d'échec ⚠️ expliquant pourquoi, **et non par un ✅ trompeur**. Reprenez alors le passage tel qu'il figure dans le document, ou demandez de réécrire la section entière. Même règle côté feuilles : une écriture qui ne touche aucune cellule est signalée comme un échec.
- **Fichier sur un Drive partagé d'entreprise** : la lecture peut fonctionner alors que l'écriture est refusée, parce que vous y êtes **Lecteur** ou **Commentateur**. MIA vous le dit explicitement — se reconnecter n'y changerait rien, c'est le rôle **Éditeur** (ou **Contributeur**) qu'il faut demander au propriétaire du fichier ou du Drive.
- **« MIA n'a pas accès à mes fichiers » : deux causes, deux remèdes.** C'est la confusion la plus fréquente, et MIA nomme désormais celle qui s'applique.
  1. **Une autorisation manquante côté MIA.** L'écran de consentement de Google présente les autorisations en **cases à cocher séparées**, décochables une à une ; il suffit d'en oublier une pour perdre silencieusement la capacité correspondante. La plus déroutante est la **lecture** : sans elle, MIA ne voit que les fichiers qu'elle a elle-même créés, vos recherches ne ramènent rien et vos documents paraissent introuvables — alors que le compte est le bon et le dossier bien partagé. Vérifiez les badges de cette page (**Lecture**, **Écriture Docs**, **Écriture Sheets**, **Renommer / déplacer**, **Dupliquer**) : un tiret à la place du ✓ signale l'autorisation qui manque. Le bouton **Reconnecter et autoriser** la réaccorde — **laissez toutes les cases cochées** sur l'écran de Google.
  2. **Un manque de droits sur le fichier, côté Google.** Là, se reconnecter ne changerait rien : c'est le rôle **Éditeur** (ou **Contributeur**) qu'il faut demander au propriétaire du fichier ou du Drive partagé. MIA le vérifie **avant** de vous proposer une carte d'approbation, pour ne pas vous faire approuver un rangement voué à l'échec.
  Ces autorisations concernent **tous vos fichiers**, pas seulement ceux créés par MIA : renommer ou déplacer un document que vous avez créé vous-même fonctionne parfaitement, dès lors que l'autorisation correspondante a bien été accordée.
- **Dupliquer** (si autorisé, badge **Dupliquer**) : demandez *« Duplique mon modèle de compte rendu »* ou *« Fais une copie de ce doc dans mon dossier Archives »*. La copie est **fidèle** — mise en forme, images et tableaux conservés — et **l'original n'est jamais touché**. Par défaut elle s'appelle *« Copie de … »* et se place à côté de l'original ; vous pouvez demander un autre nom ou un autre dossier. Très pratique pour partir d'un modèle : *« duplique mon modèle de CR et remplis-le avec les notes de la réunion »* — l'assistant travaille alors sur la copie. Un cas ne passe pas : si Google refuse de copier un fichier que MIA n'a pas créé elle-même, l'assistant vous le dit et vous invite à le dupliquer d'un clic droit dans Drive (« Créer une copie »).
- **Renommer et déplacer** (si autorisé, badge **Renommer / déplacer**) : demandez *« Renomme mon PDF Devis en Devis Globex 2026 »* ou *« Range ce fichier dans mon dossier Factures »*. Cela fonctionne pour **tous vos fichiers**, pas seulement les Docs et Sheets (PDF, images, fichiers Office…), et **le contenu n'est jamais modifié** — seuls le nom ou l'emplacement changent, les liens de partage existants continuent de fonctionner. Le dossier de destination se donne par son **nom** (*« Factures »*), son lien, ou *« racine »* pour Mon Drive ; si plusieurs dossiers portent ce nom, l'assistant vous demande lequel plutôt que de choisir à votre place. Comme pour l'écriture, une **carte d'approbation** s'affiche d'abord : elle nomme le fichier, le nouveau nom ou la destination, et rappelle d'où part le fichier — un déplacement ne laissant aucune trace dans le Drive, notez la destination si vous voulez revenir en arrière.
- Aucun fichier n'est jamais **supprimé** ni mis à la corbeille : si vous le demandez, l'assistant vous le dira et proposera de le déplacer dans un dossier dédié. Connexion antérieure à l'arrivée de l'écriture ou du rangement : les assistants restent limités — un bouton **Reconnecter et autoriser** apparaît sur la page.
- **Déconnecter** retire l'accès immédiatement. Si la connexion **expire ou est révoquée** côté Google, l'assistant vous l'indique et un bandeau sur la page Comptes connectés propose de reconnecter.

**Onglet Comptes connectés — Gmail :**
- Bouton **Connecter mon Gmail** : une fenêtre Google demande votre consentement (lecture de vos emails + création de brouillons), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Brouillons**).
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Emails Gmail** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé** — l'assistant ne consulte jamais vos emails sans votre feu vert. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que Gmail n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Rechercher et lire** : demandez *« Résume le dernier email de Marie »* ou *« Retrouve les emails avec pièce jointe sur le budget »* — l'assistant utilise la recherche Gmail (expéditeur, objet, dates…) puis lit le message. La bulle affiche *« Recherche dans vos emails Gmail… »* puis *« Lecture de l'email… »*.
- **Préparer un email** : demandez *« Prépare une réponse à cet email »* — l'assistant rédige le message (destinataire, objet, corps) et une **carte de relecture** s'affiche sous sa réponse : le message **tel qu'il partira** (À, Cc, Objet, corps mis en forme comme dans votre messagerie) et cinq boutons. **Envoyer** l'expédie réellement (voir ci-dessous) ; **Créer le brouillon** le range dans votre dossier **Brouillons** Gmail sans rien envoyer, à relire et envoyer (ou supprimer) vous-même depuis Gmail. **Abandonner** ne crée rien ; sans réponse, la demande expire en 30 minutes. L'assistant ne recopie pas le message dans sa réponse : la carte suffit.
- **Envoyer d'un clic** : le bouton **Envoyer** demande d'abord confirmation dans la carte — les destinataires en toutes lettres, et le rappel que l'envoi est **définitif** — puis **Confirmer l'envoi** expédie le message immédiatement, avec votre signature Gmail. Un message ✅ dans la conversation le confirme et mène à votre dossier Envoyés ; en réponse à un mail, l'email part dans le fil d'origine. **Retour** renonce sans rien envoyer. L'assistant, lui, ne peut **jamais** envoyer de lui-même : seul ce bouton envoie, et il n'existe que dans la carte que vous relisez. Si l'envoi n'est pas autorisé pour votre connexion, le bouton le dit au clic et propose de reconnecter votre Gmail (Réglages → Comptes connectés, badge **Envoi**) — ou de créer le brouillon.
- **Relire et retoucher avant d'approuver** : le message se corrige **directement dans la carte** : cliquez dans les champs À, Cc, Objet ou dans le texte et tapez (Ctrl+B / Ctrl+I pour le gras et l'italique, ⌘ sur Mac ; un texte collé arrive sans mise en forme). Vos corrections sont enregistrées dès que vous quittez la carte et, de toute façon, **avant** l'envoi, la création du brouillon ou une amélioration — c'est toujours ce que vous voyez qui part ; une correction refusée (adresse invalide, message vide) est signalée et rien ne part. **Améliorer** propose des retouches en un clic — *Plus court*, *Plus formel*, *Plus chaleureux*, *Plus direct*, *Plus détaillé*, *Corriger la langue* — ou une consigne à vous (*« propose un créneau jeudi matin »*, *« mentionne le devis n° 4521 »*) : MIA réécrit le texte en connaissant le mail d'origine et votre conversation, sans toucher aux destinataires. Chaque version est numérotée (« Version 2 · Amélioré par MIA : plus court ») et **Version précédente** revient en arrière. C'est toujours la **dernière version relue** qui part dans vos brouillons, y compris si vous rechargez la page entre-temps. Une réécriture par MIA compte comme une petite opération IA sur votre quota ; la modification à la main ne coûte rien.
- **Répondre dans le fil** : demandez *« Réponds à ce mail »* ou *« Prépare une réponse au dernier message de Marie »* — le brouillon se range **dans la conversation d'origine**, avec l'objet « Re: … » et le destinataire repris automatiquement. Inutile de redonner l'adresse ou l'objet. La carte annonce *« Répondre à un email »*, montre *Expéditeur du message d'origine (automatique)* et *Objet repris du message d'origine* tant que vous ne les changez pas, et le bouton s'appelle **Créer la réponse en brouillon** ; l'objet d'une réponse ne se modifie pas (le changer détacherait la réponse du fil).
- **Les emails sont écrits en HTML** : gras, italique, listes à puces ou numérotées et liens cliquables arrivent réellement mis en forme chez le destinataire — plus de `**` ni de tirets en toutes lettres. Une version texte accompagne toujours le message pour les lecteurs qui n'affichent pas le HTML.
- **Votre signature Gmail est reprise automatiquement**, si vous en avez une : elle est ajoutée en bas du brouillon, après le séparateur habituel. Inutile de demander à l'assistant de signer — et s'il propose une formule de fin avec vos coordonnées, dites-lui de s'en tenir au message. **Connexion antérieure** : le badge **Signature —** de la page Comptes connectés indique que l'autorisation n'a pas encore été accordée ; le bouton **Reconnecter et autoriser** la débloque en un clic. Sans elle, les brouillons partent simplement sans signature.
- **Rien ne part sans votre clic** : l'assistant n'a aucun outil d'envoi — un email ne peut partir que par le bouton **Envoyer** de la carte, après votre confirmation. Tout le reste (recherche, lecture, brouillons) ne fait rien sortir de votre boîte.
- **Déconnecter** retire l'accès immédiatement ; connexion expirée ou révoquée → bandeau de reconnexion, comme pour le Drive.

**Onglet Comptes connectés — Outlook :**
- Bouton **Connecter mon Outlook** : Microsoft vous fait choisir votre compte et, si votre organisation n'a pas déjà donné son accord pour tout le monde, demande votre consentement (lecture de vos emails + création de brouillons), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Brouillons**). Fonctionne avec un compte professionnel (Microsoft 365) comme avec un compte personnel (outlook.com), selon ce qu'autorise votre administrateur.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Emails Outlook** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant qu'Outlook n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Rechercher et lire** : demandez *« Résume le dernier email de Marie »* ou *« Retrouve les emails avec pièce jointe sur le budget »* — l'assistant cherche dans votre boîte (expéditeur, objet, dates, pièces jointes…) puis lit le message. Sans critère particulier, il remonte simplement vos **derniers messages reçus**. La bulle affiche *« Recherche dans vos emails Outlook… »* puis *« Lecture de l'email Outlook… »*.
- **Préparer un email** : demandez *« Prépare une réponse à cet email »* — l'assistant rédige le message (destinataire, objet, corps) et la même **carte de relecture** que pour Gmail s'affiche : le message tel qu'il partira et les boutons **Envoyer**, **Créer le brouillon**, **Améliorer** (préréglages ou consigne libre, **Version précédente** pour revenir en arrière) et **Abandonner** ; le message se corrige directement dans la carte, comme pour Gmail. **Créer le brouillon** → il apparaît dans vos **Brouillons** Outlook, à relire et envoyer (ou supprimer) vous-même. **Envoyer** demande confirmation (**Confirmer l'envoi**) puis expédie le message immédiatement ; un message ✅ mène à vos Éléments envoyés. **Abandonner** ne crée rien ; sans réponse, la demande expire en 30 minutes.
- **Envoi et autorisation** : envoyer depuis MIA exige une autorisation Microsoft de plus que les brouillons. Une connexion faite avant l'arrivée du bouton ne l'a pas : le badge **Envoi —** de la page Comptes connectés le montre, le bouton l'explique au clic, et **Reconnecter et autoriser** la débloque — sauf si l'administrateur de votre annuaire Microsoft a refusé cette permission, auquel cas les brouillons restent possibles et l'envoi se fait depuis Outlook. Si l'envoi échoue après coup, le message reste dans vos Brouillons : rien n'est perdu, rien n'est parti.
- **Répondre dans le fil** : dites *« réponds à ce mail »* après avoir fait lire le message, et le brouillon se range **dans la conversation d'origine** — objet « RE: … » et destinataire repris automatiquement, inutile de les dicter. La carte d'approbation l'annonce (*« Répondre à un email »*). L'objet n'est pas réécrit : le modifier détacherait la réponse du fil chez certains destinataires.
- **Les emails sont écrits en HTML** (gras, listes, liens cliquables), comme côté Gmail. Une différence subsiste : Outlook ne rend pas votre **signature** par son interface de programmation, MIA ne peut donc pas la reprendre — ajoutez-la dans Outlook avant l'envoi si elle compte.
- **Rien ne part sans votre clic** : l'assistant n'a aucun outil d'envoi, ni de **modification** ou de **suppression** d'un message existant. Un email ne peut partir que par le bouton **Envoyer** de la carte, après votre confirmation, et c'est toujours le message que vous venez de relire qui part.
- **Si votre compte n'a pas de boîte Outlook** (licence Microsoft sans messagerie, compte d'annuaire seul), MIA vous le dit explicitement — inutile de tenter une reconnexion.
- **Déconnecter** retire l'accès immédiatement. Microsoft ne permet pas à MIA de révoquer elle-même l'autorisation : pour la retirer aussi côté Microsoft, passez par [myaccount.microsoft.com](https://myaccount.microsoft.com/).

**Onglet Comptes connectés — Calendrier Outlook :**
- Bouton **Connecter mon calendrier Outlook** : Microsoft vous fait choisir votre compte et, si votre organisation n'a pas déjà donné son accord pour tout le monde, demande votre consentement (lecture de vos agendas + création d'évènements), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Création d'évènements**). C'est une connexion **distincte** de celle d'Outlook (emails) : vous pouvez relier votre agenda sans donner accès à votre messagerie, et inversement.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Agenda Outlook** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que le calendrier n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Consulter** : demandez *« Quelles réunions demain ? »*, *« Suis-je libre jeudi après-midi ? »* ou *« Retrouve le séminaire de septembre »* — l'assistant interroge vos agendas (l'agenda principal par défaut, les autres si vous les mentionnez) sur la période concernée, puis lit les détails d'un évènement (horaires, lieu, description, organisateur, participants, lien de visioconférence). Les **occurrences des réunions récurrentes** sont bien vues une par une. La bulle affiche *« Consultation de votre agenda Outlook… »* puis *« Lecture de l'évènement Outlook… »*.
- **Créer un évènement** : demandez *« Bloque une réunion budget vendredi de 9 h à 10 h 30 en salle A »* — l'assistant prépare l'évènement et une **carte d'approbation** s'affiche : titre, date, lieu et aperçu de la description. Cliquez **Approuver** → l'évènement apparaît dans votre calendrier Outlook (la carte affiche son lien). **Annuler** ne crée rien ; sans réponse, la demande expire en 30 minutes.
- **L'assistant ne peut pas ajouter de participants**, et ce n'est pas un oubli : chez Microsoft, un évènement créé avec des invités **envoie l'invitation aussitôt**, sans réglage possible. MIA n'envoyant jamais de message en votre nom, elle crée l'évènement **sans participant** et vous invite à les ajouter vous-même dans Outlook. Elle ne peut **ni modifier ni supprimer** un évènement existant : aucun outil n'existe pour cela.
- **Si votre compte n'a pas de boîte Outlook** (licence Microsoft sans messagerie, compte d'annuaire seul), MIA vous le dit explicitement — inutile de tenter une reconnexion.
- **Déconnecter** retire l'accès immédiatement ; comme pour Outlook, l'autorisation côté Microsoft se retire depuis [myaccount.microsoft.com](https://myaccount.microsoft.com/).

**Onglet Comptes connectés — Microsoft To Do :**
- Bouton **Connecter Microsoft To Do** : Microsoft vous fait choisir votre compte et, si votre organisation n'a pas déjà donné son accord pour tout le monde, demande votre consentement (lecture de vos listes de tâches + ajout et modification), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Création et modification de tâches**). Connexion **distincte** d'Outlook et du calendrier Outlook. Fonctionne avec un compte professionnel (Microsoft 365) comme avec un compte personnel.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Tâches Microsoft To Do** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que To Do n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Consulter** : demandez *« Qu'ai-je à faire cette semaine ? »* ou *« Quelles tâches de ma liste Travail sont en retard ? »* — l'assistant lit votre liste par défaut (**Tâches**), ou celle que vous nommez, triée par échéance. Par défaut il ne remonte que les tâches **à faire** ; demandez les tâches terminées si vous les voulez. Il lit aussi le détail d'une tâche : notes, **étapes**, rappel, importance. La bulle affiche *« Consultation de vos tâches Microsoft To Do… »*.
- **Ajouter ou modifier** : demandez *« Ajoute "Relancer Durand" pour vendredi, rappel jeudi à 9 h »*, *« Marque la tâche facture comme terminée »* ou *« Décale-la à lundi »* — une **carte d'approbation** s'affiche (titre, liste, échéance, rappel, champs modifiés). Cliquez **Approuver** → la tâche est créée ou mise à jour dans Microsoft To Do. **Annuler** ne change rien ; sans réponse, la demande expire en 30 minutes.
- **Échéance et rappel** : l'échéance est un **jour** ; un **rappel** porte une heure précise, et c'est Microsoft To Do qui vous prévient à cette heure. Les heures sont celles de votre fuseau.
- **Aucune suppression** : l'assistant ne peut pas effacer une tâche. Quand elle est faite, il la **marque terminée** (elle reste dans l'historique de To Do) ; pour la supprimer vraiment, faites-le dans Microsoft To Do. La liste **Emails marqués d'un indicateur**, remplie par Outlook, se consulte mais ne reçoit pas de tâche.
- **Si votre compte n'a pas de boîte Outlook** (licence Microsoft sans messagerie), MIA vous le dit explicitement — inutile de tenter une reconnexion.
- **Déconnecter** retire l'accès immédiatement ; l'autorisation côté Microsoft se retire depuis [myaccount.microsoft.com](https://myaccount.microsoft.com/).

**Onglet Comptes connectés — Microsoft Teams :**
- Bouton **Connecter Microsoft Teams** : une fenêtre Microsoft s'ouvre, puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Conversations**, **Canaux**, **Publication de messages**). Connexion **distincte** des autres connecteurs Microsoft. **Compte professionnel uniquement** (Microsoft 365 avec Teams) : les comptes Microsoft personnels n'ont pas d'accès Teams pour les applications.
- **Un administrateur doit d'abord autoriser MIA** pour toute votre organisation : la lecture des canaux l'exige. Si Microsoft affiche *« Approbation de l'administrateur requise »*, transmettez-lui la demande ; une fois l'autorisation donnée, la connexion se fait en un clic.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Messages Teams** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que Teams n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Lire et chercher** : demandez *« Qu'a dit Marie sur le budget ? »*, *« Résume la conversation avec Paul »* ou *« Quoi de neuf dans Projet Atlas / Général ? »* — l'assistant cherche dans vos messages, lit une conversation (privée, de groupe, de réunion) ou un canal de vos équipes, dans l'ordre où les messages ont été écrits, et cite l'auteur et la date. Un **lien Teams** collé (« Copier le lien » d'une conversation, d'un canal ou d'un message) est compris aussi. Un nom ambigu (« Général » existe dans chaque équipe) ? L'assistant vous demande lequel. La bulle affiche *« Recherche dans vos conversations Teams… »* puis *« Lecture de la conversation Teams… »*.
- **Publier** : demandez *« Réponds à Marie que je regarde le devis demain »* ou *« Publie dans Projet Atlas / Général que la réunion est déplacée »* — une **carte d'approbation** s'affiche : la destination, le texte, et le rappel que **le message part aussitôt, en votre nom, visible de tous les participants**. Cliquez **Approuver** → le message est publié (la carte affiche le lien Teams). **Annuler** ne publie rien ; sans réponse, la demande expire en 30 minutes. Dans un canal, l'assistant peut répondre **dans le fil** d'un message ; une conversation, elle, n'a pas de fil.
- **Aucune modification ni suppression** : l'assistant ne peut ni corriger ni effacer un message publié — faites-le dans Teams.
- **Vous ne voyez que ce que vous voyez déjà** : une équipe dont vous n'êtes pas membre reste invisible pour l'assistant.
- **Déconnecter** retire l'accès immédiatement ; l'autorisation côté Microsoft se retire depuis [myaccount.microsoft.com](https://myaccount.microsoft.com/).

**Onglet Comptes connectés — Google Agenda :**
- Bouton **Connecter mon Google Agenda** : une fenêtre Google demande votre consentement (lecture de vos agendas + création d'évènements), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Création d'évènements**).
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Agenda Google** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé** — l'assistant ne consulte jamais votre agenda sans votre feu vert. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que l'agenda n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Consulter** : demandez *« Quelles réunions demain ? »*, *« Suis-je libre jeudi après-midi ? »* ou *« Retrouve le séminaire de septembre »* — l'assistant interroge vos agendas (l'agenda principal par défaut, les autres si vous les mentionnez) sur la période concernée puis lit les détails d'un évènement (horaires, lieu, description, participants, lien). La bulle affiche *« Consultation de votre agenda… »* puis *« Lecture de l'évènement… »*.
- **Créer un évènement** : demandez *« Ajoute une réunion budget vendredi de 9 h à 10 h 30 en salle A »* — l'assistant prépare l'évènement et une **carte d'approbation** s'affiche : titre, date, lieu, participants éventuels et aperçu de la description. Cliquez **Approuver** → l'évènement apparaît dans votre Google Agenda (la carte affiche son lien). **Annuler** ne crée rien ; sans réponse, la demande expire en 30 minutes.
- **Aucune invitation n'est envoyée automatiquement** : les participants éventuels sont ajoutés à l'évènement sans notification — vous les invitez vous-même depuis Google Agenda si besoin. L'assistant ne peut **ni modifier ni supprimer** un évènement existant : aucun outil n'existe pour cela.
- **Déconnecter** retire l'accès immédiatement ; connexion expirée ou révoquée → bandeau de reconnexion, comme pour le Drive.

**Onglet Comptes connectés — Google Tasks :**
- Bouton **Connecter mes tâches Google** : une fenêtre Google demande votre consentement (consultation de vos listes de tâches + ajout, modification et suppression), puis vous revenez sur la page avec le compte connecté et les autorisations accordées (badges **Lecture**, **Ajout / modification / suppression**).
- **Le seul connecteur qui peut supprimer.** Google ne propose aucun droit intermédiaire : autoriser l'écriture, c'est autoriser la suppression. MIA rétablit le choix que Google ne donne pas — voir la case **Autoriser la suppression de tâches** ci-dessous — et **toute** écriture reste soumise à votre approbation.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Tâches Google** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que vos tâches ne sont pas connectées (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a désactivé la fonctionnalité.
- **Consulter** : demandez *« Qu'est-ce qu'il me reste à faire cette semaine ? »* ou *« Montre-moi ma liste Travail »* — l'assistant lit vos tâches (votre liste par défaut, une autre si vous la nommez) et vous les restitue avec leurs échéances. La bulle affiche *« Consultation de vos tâches Google… »*.
- **Ajouter, modifier, cocher, supprimer** : demandez *« Ajoute “relancer le fournisseur” pour vendredi »*, *« Marque la relance comme faite »* ou *« Supprime cette tâche »* — l'assistant prépare l'opération et une **carte d'approbation** s'affiche : ce qui va changer, dans quelle liste, et — pour une suppression — le rappel que l'action est **irréversible** avec la suggestion de plutôt marquer la tâche comme terminée. Cliquez **Approuver** pour exécuter. **Annuler** ne change rien ; sans réponse, la demande expire en 30 minutes.
- **Échéances : des dates, jamais des heures.** Google Tasks ne stocke que le jour d'échéance — l'assistant ne vous promettra pas un rappel « à 14 h ».
- **Vos préférences** (sous la carte, enregistrées automatiquement) — ce que l'assistant doit savoir sur votre façon de travailler, valable dans **toutes** vos conversations :
  - **Liste par défaut** : la liste dans laquelle l'assistant travaille quand vous n'en désignez aucune. Le menu propose vos listes réelles ; sans choix, c'est la liste principale de Google Tasks.
  - **Fenêtre d'échéance par défaut** : sans période précisée dans votre demande, l'assistant se limite aux échéances des N prochains jours. ⚠️ Dès qu'une fenêtre s'applique, **Google exclut les tâches sans échéance** — laissez **Aucune limite** pour toutes les voir.
  - **Masquer les tâches déjà terminées** (coché par défaut) : l'assistant ne liste que ce qu'il reste à faire, sauf si vous demandez explicitement les tâches terminées.
  - **Autoriser la suppression de tâches** (coché par défaut) : décochée, l'assistant **n'a plus aucun moyen** de supprimer — il proposera de marquer la tâche comme terminée. Cochée, chaque suppression passe par une carte d'approbation, mais elle est **irréversible** (ni MIA ni Google ne peuvent restaurer une tâche supprimée).
  - **Vos règles d'organisation** : la liste des conventions que l'assistant respecte à chaque fois qu'il touche à vos tâches — *« les relances client vont dans la liste Travail »*, *« toute tâche porte une échéance »*. Une règle par ligne (15 maximum, 200 caractères chacune) ; **vider une ligne supprime la règle**. Les règles retenues depuis une conversation portent le badge **Chat**.
- **Vos règles se construisent en conversation, pas dans un formulaire.** Deux chemins :
  - **Vous les dictez** : *« range toujours mes relances dans Travail »*, *« retiens que mes tâches perso vont dans Maison »* — MIA l'enregistre et vous le confirme, avec une pastille **Règle retenue pour vos tâches** sous sa réponse.
  - **MIA vous les propose** : quand vous le corrigez sur un rangement, ou quand vous faites le même choix pour la troisième fois, il demande *« Je retiens que les relances client vont dans Travail ? »*. Un simple **« oui »** suffit à enregistrer — c'est votre réponse qui décide, jamais lui.
- Ces règles ne pèsent que sur les conversations où le connecteur **Tâches Google** est activé : elles n'encombrent pas vos autres échanges. À ne pas confondre avec **« MIA pour vous »** (Préférences IA), qui décrit *qui vous êtes* et vaut partout.
- Ces préférences **survivent à une déconnexion** : vous les retrouvez si vous reconnectez votre compte (par exemple pour élargir une autorisation).
- **Déconnecter** retire l'accès immédiatement ; connexion expirée ou révoquée → bandeau de reconnexion, comme pour le Drive.

**Onglet Comptes connectés — Slack :**
- Bouton **Connecter mon Slack** : une fenêtre Slack demande votre consentement (recherche et lecture de vos conversations + publication en votre nom), puis vous revenez sur la page avec le compte connecté — affiché sous la forme **@votre-identifiant · Nom de l'espace de travail**, Slack ne communiquant pas votre adresse email — et les autorisations accordées (badges **Recherche**, **Lecture**, **Publication**).
- **MIA ne voit que ce que vous voyez.** Un canal privé dont vous n'êtes pas membre n'existe pas pour elle ; un « je ne trouve rien » peut simplement vouloir dire « vous n'y avez pas accès ». Si votre espace de travail exige l'approbation des applications, un administrateur Slack doit valider MIA avant que la connexion aboutisse.
- **Activation par vos soins** : l'accès se déclenche via l'interrupteur **Messages Slack** du menu **+** du chat (section **Connecteurs**), **désactivé tant que vous ne l'avez pas activé**. Une fois activé, **il le reste** (mémorisé sur ce navigateur, y compris dans une nouvelle conversation). Activé, le **point ambre** sur le bouton **+** le rappelle. Interrupteur **grisé** tant que Slack n'est pas connecté (cliquer la ligne mène à cette page) ; ligne **absente** du menu si l'administrateur a masqué le connecteur.
- **Rechercher et lire** : demandez *« Qu'a-t-on dit du budget sur Slack ? »*, *« Résume #projet-x depuis hier »* ou *« Retrouve le message de Paul sur la livraison »*. MIA cherche dans tout ce que vous pouvez lire — canaux publics, canaux privés dont vous êtes membre, messages directs — et accepte la syntaxe de recherche de Slack (`in:#canal`, `from:@paul`, `during:july`). Elle sait aussi **lire un canal entier** dans l'ordre chronologique, ou **dérouler un fil** complet avec ses réponses. Les mentions sont rendues par des noms lisibles, et chaque message rapporté est cité avec son auteur, sa date et son lien. La bulle affiche *« Recherche dans vos conversations Slack… »* puis *« Lecture de la conversation Slack… »*.
- **Publier un message** : demandez *« Préviens #projet-x que la livraison est décalée à jeudi »* ou *« Réponds dans ce fil que je m'en occupe »*. MIA rédige le message et une **carte d'approbation** s'affiche : canal visé, aperçu du texte, et le rappel de ce qui distingue Slack de tout le reste — **le message part vraiment**. Cliquez **Approuver** → il est publié **en votre nom**, immédiatement visible par les membres du canal, et MIA ne peut plus ni le modifier ni le supprimer. **Annuler** ne publie rien ; sans réponse, la demande expire en 30 minutes.
- **Le texte est mis en forme pour Slack** : écrivez votre demande normalement, MIA convertit le gras, l'italique, les listes et les liens dans la syntaxe de Slack — rien n'arrive avec des astérisques en toutes lettres.
- **MIA ne peut pas ouvrir un nouveau message direct** ni rejoindre un canal : l'autorisation correspondante n'est pas demandée. Si un canal lui est inaccessible, elle vous invite à le rejoindre dans Slack.
- **Déconnecter** retire l'accès immédiatement, **et retire aussi l'autorisation côté Slack** — contrairement à Microsoft, il n'y a aucune démarche supplémentaire à faire.

**Onglet Comptes connectés — OneDrive :**
- Cette connexion ne sert **pas** aux conversations : elle permet de bâtir une **base de connaissances** à partir d'un dossier OneDrive (réservé aux créateurs et administrateurs — voir le guide du créateur). Elle est en **lecture seule** : MIA ne crée, ne modifie et ne supprime jamais rien chez Microsoft.
- Bouton **Connecter mon OneDrive** : Microsoft vous fait choisir votre compte et, si votre organisation n'a pas déjà donné son accord pour tout le monde, demande votre consentement (lecture de vos fichiers), puis vous revenez sur la page avec le compte connecté (badges **Lecture**, **Navigation dans les dossiers**). La carte rappelle les trois étapes (connecter → ouvrir une base → **Parcourir…**) avant comme après la connexion.
- La carte **n'apparaît que si vous gérez des bases de connaissances** (créateur ou administrateur) : c'est son seul usage.
- Une fois connecté, la fenêtre d'une base de connaissances propose la source **Dossier OneDrive** et un bouton **Parcourir…** qui affiche **vos** dossiers et ceux **partagés avec vous** : vous choisissez le dossier sans avoir à copier le moindre lien, et vous voyez avant de valider quels fichiers seront repris.
- La carte **n'apparaît pas** si votre administrateur n'a pas déclaré l'application Microsoft de l'instance, ou s'il a masqué ce connecteur pour l'organisation.
- **Déconnecter** retire l'accès immédiatement : les bases de connaissances qui lisaient un dossier avec **votre** connexion cessent alors de se synchroniser (leur contenu déjà indexé reste consultable).

### Tâches programmées — une demande qui se répète toute seule

Une **tâche programmée** est une demande que MIA refait toute seule, au rythme choisi — le point sur vos mails chaque lundi à 8 h, une revue de presse chaque matin, un bilan le 1er du mois. La réponse arrive dans la **conversation de la tâche**. Elle s'exécute **sur le serveur** : inutile de garder MIA ouverte, votre navigateur peut être fermé et votre ordinateur éteint.

- **Créer une tâche — quatre chemins** :
  - **En le demandant à MIA** : *« chaque lundi à 8 h, fais-moi le point sur mes mails »*, *« programme-moi une revue de presse tous les matins avec l'assistant Veille, sur notre base Catalogue »*. MIA prépare la tâche et l'affiche dans une **carte** (nom, consigne à déplier, rythme, première exécution, assistant ou modèle, sources, bases, outils, destination, ce que la tâche peut faire, notification) : **Approuver** la programme — la carte propose alors **Exécuter maintenant** —, **Annuler** l'abandonne, **Ajuster** ouvre le formulaire pré-rempli (la carte se ferme quand vous programmez), et un changement demandé dans la conversation (*« plutôt à 9 h »*, *« avec l'assistant Juriste »*) produit une nouvelle carte qui remplace la précédente, grisée — la consigne n'est réécrite que si vous le demandez. Sans précision, la tâche reprend l'assistant, les sources et les bases de la conversation ; MIA ne propose que les assistants, modèles, bases (trois au plus) et outils que vous pourriez choisir dans le formulaire, jamais un modèle incapable d'utiliser les connecteurs ou outils de la tâche, et signale « (à connecter) » une source pas encore reliée. **Agir sans validation** ne se choisit que dans le formulaire. Rien n'est programmé sans votre clic ; sans heure précisée, MIA propose 8 h.
  - **L'horloge Programmer de l'assistant** : quand vous parlez à un assistant, l'horloge à droite de son nom, en haut de la zone de saisie, ouvre le formulaire avec **cet assistant**, les sources cochées dans le menu **+**, les bases de connaissances cochées et le texte que vous étiez en train d'écrire.
  - **Sous une réponse qui vous plaît**, le bouton **Refaire régulièrement** ouvre le formulaire **pré-rempli** (votre demande devient la consigne ; l'assistant, le modèle, les sources et les bases de connaissances de la conversation suivent).
  - **La tuile Tâches de la barre latérale → Nouvelle tâche.**
- **Le formulaire** : **Quoi** (la **consigne**, mise en avant comme le cœur de la tâche — le texte que l'assistant reçoit tel quel à chaque exécution ; sous le champ, **Par exemple** propose des consignes types — **Point du lundi**, **Veille quotidienne**, **Bilan du mois**, **Relances du vendredi** — qu'un clic pose dans le champ, avec le nom de la tâche s'il est vide, sans jamais remplacer un texte déjà écrit sans le demander ; puis un nom et l'assistant — son avatar, sa description, son modèle, ses bases et ses outils s'affichent dessous — ou MIA et un modèle ; un assistant devenu inaccessible s'affiche **Assistant indisponible — à remplacer**, et la tâche ne s'enregistre pas tant qu'un autre choix n'est pas fait) ; **Avec quoi** (trois rangées de cases alignées, une par élément avec son icône, son nom, une note et une coche, cochables à plusieurs d'un clic : les **bases de connaissances**, trois au plus en plus de celles de l'assistant, cochées d'office en pointillé, avec le compteur de la rangée ; les **connecteurs**, ceux du menu **+** ; les **outils** que votre organisation vous ouvre — image, automatisation n8n, présentation Gamma, un par type, ceux de l'assistant marqués « apporté par l'assistant » ; et **Ce que la tâche peut faire** : **Lire et rédiger**, **Préparer des actions à approuver** ou **Agir sans validation**) ; **Quand** (**Chaque jour**, **Jours ouvrés**, **Chaque semaine** avec les jours à cocher, **Chaque mois** du 1er au 28 ou le dernier jour, puis l'heure — une phrase résume la règle et donne les trois prochaines exécutions, à l'heure de votre ville) ; **Où voir le résultat** (dans une même conversation à la suite, où l'assistant voit les résultats précédents, ou une nouvelle conversation à chaque fois). Bouton **Programmer** : cette page, elle, s'enregistre à la main — une tâche agit seule, elle ne part jamais à moitié saisie. Une base ou un outil indisponible est **grisé avec sa raison** (« Aucun document », « Dernier test en échec »…) ; un accès retiré après coup fait sortir la base ou l'outil de la tâche, qui s'exécute sans eux.
- **Essayer avant de programmer** : **Lancer un essai**, en bas du formulaire, exécute la tâche telle que saisie, une fois, tout de suite, sans rien enregistrer — les actions de MIA puis la réponse s'affichent sur place (**Arrêter** l'interrompt), avec le nombre d'actions préparées et le lien **Ouvrir la conversation de l'essai** (nouvel onglet). Un essai consomme des crédits comme un message ; sa conversation « Essai — *nom* » reste dans l'historique ; relancer remplace l'essai précédent s'il est resté tel quel. **Un essai n'agit jamais sans vous** : même réglée sur **Agir sans validation**, la tâche y prépare ses actions, qui attendent votre accord.
- **Retrouver les résultats** : la conversation d'une tâche porte une **horloge** dans la barre latérale et une **pastille** quand un nouveau résultat arrive ; un bandeau en tête du fil rappelle la tâche (**Gérer la tâche**). Conversation ouverte au moment de l'exécution : la réponse s'écrit sous vos yeux.
- **Notifications sur vos appareils** : carte **Notifications** en bas de la page **Tâches programmées** — **Activer sur cet appareil** (le navigateur demande l'autorisation), **Envoyer une notification de test**, **Désactiver sur cet appareil**, et la liste de vos appareils avec **Retirer**. Une notification annonce le nom de la tâche et ce qui s'est passé (résultat arrivé, actions effectuées sans validation, actions à valider, échec, exécution sautée faute de crédits, pause) — **jamais le contenu de la réponse** — et ouvre la conversation de la tâche. Seulement pour les exécutions programmées (ni **Exécuter maintenant**, ni l'essai) et pour les tâches dont la case **Me prévenir sur mes appareils** (formulaire, cochée par défaut) est cochée. Sur **iPhone et iPad**, elles ne marchent que dans MIA ajoutée à l'écran d'accueil (Safari → Partager → Sur l'écran d'accueil) ; l'application mobile ne les reçoit pas encore. **Se déconnecter retire l'appareil** (un ordinateur partagé ne montre jamais vos notifications à quelqu'un d'autre).
- **La liste** donne pour chaque tâche son rythme, la prochaine exécution et l'issue de la dernière ; l'**interrupteur** met en pause ou réactive, **Exécuter maintenant** envoie la consigne tout de suite (hors calendrier), **Modifier** ouvre la tâche et ses dernières exécutions, **Supprimer la tâche** (en bas de la page de modification) garde ses conversations.
- **Le schéma d'une tâche** : **cliquer une tâche** de la liste affiche son schéma à droite (sur un téléphone, il se déplie sous la tâche ; un second clic le replie), et le même schéma accompagne le **formulaire** : il reste en vue pendant que vous faites défiler la page, suit chacun de vos réglages avant tout enregistrement, et **cliquer un de ses éléments** fait venir à l'écran la section qui le règle, qui s'allume un instant (le déclencheur → **Quand** ; l'assistant, le modèle et la consigne → **Quoi** ; connecteurs, bases, outils et « ce que la tâche peut faire » → **Avec quoi** ; arrivée du résultat et notifications → **Où voir le résultat** ; au clavier, un bouton apparaît sur chaque élément). Il montre, avec les icônes habituelles de MIA : **en haut le déclencheur** (rythme, prochaine exécution à l'heure de votre ville, nombre d'exécutions par mois, **Active** ou **En pause** avec la raison — traits en pointillés pour une tâche en pause) ; **au centre l'assistant** (ou MIA sans assistant : avatar, nom, description, la consigne reçue, **Lecture seule**, **Actions à approuver** ou **Agit sans validation** — avec **Emails envoyés** si elle envoie ses emails ; option retirée par l'administrateur : **Actions à approuver**, ce qui se passera, où arrive le résultat, notifications) ; **à gauche le modèle** qui répondra, **imposé par l'assistant**, **choisi pour la tâche** ou **modèle par défaut** ; **en dessous** un rectangle pour les **connecteurs** (une source à reconnecter y est signalée), un pour les **bases de connaissances** (celles de l'assistant comprises, « via l'assistant ») et un pour les **outils** (ceux de l'assistant aussi, « via l'assistant »).
- **Préparer des actions à approuver** (choix **Ce que la tâche peut faire**, sur **Lire et rédiger** par défaut) : une tâche en **lecture seule** lit et rédige, sans rien préparer. Avec **Préparer des actions à approuver**, elle peut préparer des brouillons d'email, des évènements, des tâches ou des messages Slack : chacun arrive dans sa conversation sous forme de **carte d'approbation**, qui attend votre décision **une semaine** (30 minutes dans une conversation ordinaire) ; la liste des tâches affiche **N actions à approuver**. Une **automatisation n8n** ou une **présentation Gamma** agissant, les choisir **exclut la lecture seule** : la tâche prépare ses actions, ou les effectue si elle agit sans validation (les décocher rend votre choix précédent).
- **Agir sans validation** (troisième choix de **Ce que la tâche peut faire**, si votre administrateur le propose — sinon grisé avec sa raison) : la tâche **effectue elle-même** ce qu'elle prépare — évènements, tâches, messages Slack, fichiers, workflows, présentations — sans carte à approuver. **Les emails restent des brouillons** dans votre boîte, sauf si vous cochez **Envoyer les emails** (si votre connexion n'a pas le droit d'envoyer, l'email est enregistré en brouillon et le compte rendu le dit). Tout est noté : dans la conversation de la tâche, juste avant le compte rendu, le message **Ce que la tâche a fait sans validation** liste chaque action avec son lien, et celles qui n'ont pas pu être faites (« Non effectué ») ; la liste des actions au-dessus de la réponse les montre (« Exécution sans validation ») ; la notification les compte. **Dix actions au plus** par exécution : au-delà, les suivantes arrivent en cartes d'approbation. **Les outils de l'assistant n'agissent pas seuls** : une automatisation n8n ou une présentation Gamma ne s'exécute sans validation que si vous avez coché son outil dans **Avec quoi** ; celle d'un outil que l'assistant apporte (partagé, réglé par d'autres, parfois ajouté après coup) attend votre validation — le formulaire et le schéma le disent. La liste des tâches signale celles qui **agissent sans validation** ; si l'administrateur retire l'option, elles préparent leurs actions, à approuver, et la liste l'indique. **Prudence** : rien ne vous est demandé, un email envoyé ou un message publié ne se rattrape pas, et une tâche lit des contenus écrits par d'autres (emails reçus, pages web) — elle a pour consigne de ne jamais suivre leurs instructions, mais aucune consigne n'est infaillible. Réservez ce mode aux actions prévisibles, avec une consigne précise, et laissez **Envoyer les emails** décoché si la tâche lit des emails venus de l'extérieur. Ce mode ne se choisit que dans le formulaire : une tâche préparée par MIA dans le chat prépare ses actions, à approuver.
- **Ce qu'une tâche ne fait pas** : elle **n'agit seule que si vous l'avez choisi** (**Agir sans validation**) — sinon rien ne part sans votre approbation, et en lecture seule elle ne prépare rien (elle décrit l'action utile, à demander ensuite). Elle ne pose pas de question. Les fichiers joints à la demande d'origine ne sont pas repris. Les créations à volet (page web, carte, présentation de MIA, vidéo) ne se programment pas.
- **Coût et limites** : chaque exécution consomme des crédits comme un message, dans votre quota ; **cinq tâches actives** au plus, une heure par tâche. Quota atteint : l'exécution est **sautée**, la tâche reprend ensuite. Serveur arrêté plus de 12 heures : l'exécution est **manquée**, sans rattrapage en rafale. **Trois échecs d'affilée**, un compte désactivé ou un assistant devenu inaccessible mettent la tâche **en pause**, avec la raison affichée. Une source déconnectée (« Gmail à reconnecter ») est signalée sur la carte de la tâche.

**Onglet Usage & crédits :** le mois en cours, en crédits (aucun montant en euros).
- **Votre solde** : les crédits restants, le quota du mois et ce que vous avez utilisé, avec une **prévision** — « Votre quota tiendra jusqu'à la fin du mois » ou, si le rythme est trop rapide, « À ce rythme, quota atteint vers le … ». La barre montre l'utilisé et, en hachures, la suite au rythme actuel ; dessous, votre rythme par jour, ce que vous pouvez encore utiliser chaque jour jusqu'à la fin du mois et le délai avant le nouveau quota. Sans quota, la page donne ce que vous avez utilisé et l'estimation de fin de mois. S'il y en a, la part **hors quota** (alimentation de vos bases de connaissances, tests d'administration) est indiquée à part : comptée, jamais retirée du quota.
- **Ce qui a consommé vos crédits** — trois camemberts sur les crédits de votre quota : **Par usage** (*Conversations*, *Assistance de conversation* — titre automatique, mémoire, préparation d'image, résumé d'une longue conversation… —, *Recherche Internet*, *Créations par outil*, *Voix*, *Bases de connaissances*, *Conception d'assistants*), **Par assistant** (avec *Sans assistant*) et **Par modèle**. Dessous, le coût moyen d'une réponse, d'une image, d'une recherche Internet et d'une minute de voix.
- **Top 10 — opérations les plus coûteuses ce mois** : la nature, l'assistant, la date, le volume et les crédits ; une opération rattachée à une conversation la rouvre d'un clic.
- **Pour faire durer vos crédits** : deux ou trois conseils tirés de votre usage du mois (un modèle bien moins cher pour les questions simples, la recherche Internet à décocher quand elle ne sert pas, les réponses souvent relancées, les créations).
- **Détail technique par modèle** — carte à déplier pour voir la consommation **modèle par modèle** (modèles de chat, d'embedding et de dictée, tokens compris).

---

## 19. Crédits et quota

- Votre **quota mensuel** est affiché en permanence en bas de la barre latérale (« *N* crédits restants / *total* »).
- Couleur de la barre : **couleur d'accent du site** → **orange** (à partir de 70 % consommés) → **rouge** (à partir de 90 %).
- **Sans quota défini**, la barre latérale affiche simplement **Illimité** et aucune barre n'apparaît.
- Quota épuisé : la mention passe à **Quota épuisé**, l'envoi est bloqué et un message indique la date de remise à zéro. Demander une augmentation à un administrateur.
- **Service IA suspendu** : votre organisation peut fixer un **budget IA mensuel** pour tout le monde. Quand il est atteint, la mention passe à **Service IA suspendu**, la fenêtre du même nom s'ouvre à l'envoi et indique la date de reprise (le 1er du mois suivant, ou plus tôt si un administrateur accorde une rallonge). Votre quota personnel n'y est pour rien, et votre message reste dans le champ de saisie.
- **Coût de la conversation ouverte** : juste au-dessus du champ de saisie, la ligne « **Crédits utilisés dans cette conversation : *N*** » cumule tout ce qu'a coûté le fil en cours — y compris les suggestions de suite, la réécriture d'un email par MIA et la lecture à voix haute. Elle se met à jour dès qu'un crédit est consommé, et n'apparaît qu'une fois le premier crédit consommé.
- **Coût annoncé avant d'envoyer** : le repère **€** du sélecteur de modèle et des outils du menu **+** donne, au survol, le coût en clair (« ≈ 35 crédits par réponse, ≈ 13 par recherche Internet », « ≈ 40 crédits par génération ») ; le bandeau du modèle l'affiche aussi. C'est une estimation calculée comme le décompte — tarifs appliqués à vos crédits, taille réelle des réponses récentes du modèle (ou des générations récentes de l'outil) : une conversation longue, des fichiers ou des connecteurs coûtent davantage.
- **Tout ce qu'un message déclenche est compté** : les tokens de la réponse, mais aussi les recherches Internet exécutées par le fournisseur, le titre automatique de la conversation, la mémorisation (« souviens-toi que… »), la préparation d'une demande d'image, l'embedding de votre question pour les bases de connaissances, les images générées. L'étiquette sous la réponse porte le total du tour — titre, mémorisation et recherche dans les bases compris —, la même pendant l'écriture et au rechargement ; la page **Usage & crédits** en donne le détail par nature.
- Remise à zéro automatique le **1er de chaque mois**.

---

## 20. FAQ et dépannage

**« Je ne peux plus envoyer de message. »**
Votre quota est probablement atteint (voir la carte de votre compte, en bas de la barre latérale). Attendre le 1er du mois, ou demander une augmentation à un administrateur.

**« L'outil image ou vidéo est grisé. »**
Lire la mention sous son nom. « Dernier test en échec » : problème de configuration côté administrateur. « Arrêté par son fournisseur le … » : le modèle n'existe plus chez son fournisseur, l'outil ne peut plus rien produire — pour une image, choisir un autre outil image. Dans les deux cas, le signaler à un administrateur.

**« Votre rôle ne vous donne pas accès à cette action. »** — ou une page, un bouton qui manque.
Chaque compte porte un **rôle**, qui décide de ce que vous pouvez faire (relier vos comptes, programmer des tâches, créer des assistants, ouvrir certaines pages de l'administration). Demandez ce droit à un administrateur : il se règle pour tout un rôle, dans **Personnes → Rôles**.

**« Je ne vois pas l'option Internet ou la dictée. »**
La recherche Internet se règle par message (interrupteur **Recherche Internet** dans le menu **+**, activé par défaut) — sauf si votre administrateur a masqué ce connecteur, auquel cas la ligne n'apparaît pas du tout. Idem pour Google Drive, Gmail, l'Agenda, les Tâches et les deux connecteurs Outlook. La dictée doit être activée par un administrateur.

**« Ma réponse affiche un encadré ⚠️ d'erreur. »**
Le **titre** de l'encadré dit la cause, son texte dit quoi faire. L'encadré est **rouge** quand l'éditeur du modèle ne répond pas (réessayer ou changer de modèle suffit), **ambre** quand quelque chose est à régler — dans votre demande, ou sur l'instance. Il garde son titre quand vous rouvrez la conversation plus tard.

| Titre de l'encadré | Ce que cela veut dire | Ce qu'il faut faire |
|---|---|---|
| **Service momentanément saturé** | L'éditeur reçoit trop de requêtes de l'organisation. | Réessayer dans un instant (l'encadré donne le délai quand l'éditeur l'annonce), ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Service surchargé** | L'éditeur est débordé, pour tout le monde. Votre message n'y est pour rien. | Réessayer dans quelques instants, ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Service temporairement indisponible** | L'éditeur a un incident technique. Votre message n'y est pour rien. | Réessayer, ou changer de modèle ; si cela persiste, prévenir un administrateur. |
| **Délai de réponse dépassé** | Le modèle a mis trop de temps. | Réessayer ; raccourcir la demande ou choisir un modèle plus rapide. |
| **Crédit épuisé chez** l'éditeur | Le crédit de votre organisation chez cet éditeur est épuisé (solde, ou plafond de dépense du mois atteint). **Ce n'est pas votre quota**, ni votre message. | Changer de modèle et prévenir un administrateur. |
| **Trop de contenu pour ce modèle** | Le message, ses pièces jointes ou les documents consultés dépassent ce que le modèle peut lire en une fois (une longue conversation, elle, se résume d'elle-même). | Joindre un fichier plus court, découper la demande, ou choisir un modèle qui accepte plus de contenu. |
| **Pièce jointe refusée** | Un fichier est trop lourd, illisible, ou d'un type que ce modèle ne lit pas. | Renvoyer le message dans une nouvelle conversation avec un fichier plus léger ou converti, ou changer de modèle. |
| **Limite de longueur atteinte** | Le modèle a consommé toute sa longueur de réponse à réfléchir, avant d'écrire. | Demander une réponse plus courte, découper la demande, ou changer de modèle. |
| **Demande bloquée par le filtre de sécurité** | Le filtre de l'éditeur a jugé la demande, ou sa réponse, sensible. | Reformuler, ou changer de modèle. |
| **Modèle indisponible**, **Accès au modèle refusé**, **Configuration requise**, **Accès au service refusé** | Le réglage de l'instance est en cause : modèle retiré par son éditeur, clé absente ou refusée. | Changer de modèle et prévenir un administrateur. |
| **Requête refusée** | L'éditeur a refusé la demande pour une autre raison. | Essayer un autre modèle ; si cela se répète, prévenir un administrateur. |

**Prévenir un administrateur, en un clic.** Quand la cause se règle dans l'administration (crédit épuisé, clé refusée, modèle retiré, panne qui dure), l'encadré porte un bouton **Prévenir un administrateur**. Il déplie la liste des administrateurs de MIA — nom et adresse — et chaque nom ouvre un courriel déjà rédigé : le titre et le texte de l'encadré, la date. **Copier le message** le met dans le presse-papiers, pour l'envoyer par une messagerie. Les encadrés que vous réglez vous-même (trop de contenu, pièce jointe, filtre de sécurité) n'ont pas ce bouton. La dictée et la lecture à voix haute disent les mêmes causes, dans une notification en bas de l'écran (« Dictée impossible — Crédit épuisé chez OpenAI »).

**« Ma réponse s'arrête au milieu d'une phrase. »**
Une ligne en italique sous le texte dit pourquoi : *Réponse interrompue : …*. Si le modèle a atteint sa **longueur maximale de réponse**, écrivez **continue** : il reprend où il s'était arrêté. Si l'éditeur a lâché en route (*… est surchargé*, *… a rencontré un incident technique*, *… a cessé de répondre*), cliquez **Regénérer** ou changez de modèle. Le texte déjà écrit reste dans tous les cas. Une **page web** coupée de la même façon porte, sous sa carte, l'encadré **Page incomplète**.

**« Un message annonce "Bascule sur le LLM de secours". »**
Votre modèle était en panne avant d'avoir écrit son premier mot : un modèle de secours, désigné par l'administrateur, a répondu à sa place. Le message dit pourquoi entre parenthèses (service surchargé, crédit épuisé chez l'éditeur…), et la liste des actions au-dessus de la réponse garde la mention **« Réponse du modèle de secours : le modèle choisi était indisponible »**. Le modèle de secours garde vos connecteurs s'il sait s'en servir ; sinon il vous le dit. Il ne reprend jamais une réponse où une action a déjà été menée (fichier lu, carte proposée) : vous voyez alors l'encadré d'erreur, et renvoyer votre message suffit. Le message suivant retente votre modèle.

**« La réponse vient de Claude Opus alors que j'avais choisi Claude Fable. »**
C'est voulu. Les modèles les plus avancés d'Anthropic filtrent certains sujets sensibles — Claude Fable 5.1 et Fable 5 la plupart des contenus de cybersécurité et la recherche en biologie, Claude Opus 5.5 et Sonnet 5.5 la cybersécurité et la biologie, Claude Opus 5 la cybersécurité — et une demande légitime proche de ces thèmes (outillage de sécurité, sciences du vivant) peut être déclinée par excès de prudence. MIA la fait alors reprendre aussitôt par un autre modèle Claude (Claude Opus, ou Claude Sonnet 5 pour Sonnet 5.5) : vous recevez votre réponse, la liste des actions au-dessus du message indique **« Réponse reprise par un autre modèle après un refus »**, et le badge du message nomme le modèle qui a répondu. Même mention, avec **« conservation des données requise »**, quand votre organisation n'a pas accès à Claude Fable : c'est un réglage de l'administrateur, pas un problème de votre demande.

**« L'encadré dit "Demande déclinée". »**
Ce n'est pas une panne : le modèle a refusé de traiter la demande, et aucun autre modèle n'était prévu pour la reprendre sur ce sujet. Réessayer à l'identique donnera le même résultat : reformulez la demande, ou choisissez un autre modèle dans le sélecteur. Si le refus survient en cours de réponse, le début reste affiché, suivi de la mention *Réponse interrompue*.

**« Le texte "Analyse de votre demande…" reste affiché longtemps. »**
MIA surveille la réponse en continu : si le modèle tarde, le texte en italique se met à jour pour l'indiquer (avec le temps d'attente), puis une **nouvelle tentative est lancée automatiquement**. Si rien ne répond malgré tout, un encadré d'erreur clair s'affiche avec la marche à suivre (bouton **Regénérer**). Vous n'avez rien à faire pendant cette phase.

**« MIA m'a donné un tableau, mais pas de bouton Télécharger. »**
La carte n'apparaît que lorsque MIA livre un **fichier** : demandez-le explicitement (« dans un fichier Excel », « en CSV »). Un bloc de code affiché dans la réponse porte aussi un bouton **Télécharger** dans son en-tête. Voir [Télécharger un fichier créé par MIA](#télécharger-un-fichier-créé-par-mia).

**« Je voulais un PowerPoint, la carte propose un autre format. »**
Nommez le format dans la demande — *« en PowerPoint »* — : MIA livre alors un fichier .pptx mis en page automatiquement. Sur la carte d'une présentation, la flèche **Autres formats** propose aussi **PowerPoint**. Au-delà de 30 slides, la carte propose le Word. Pour retoucher les slides à l'écran ou les illustrer : **Modifier les slides** sur la carte (section 13). Voir [Télécharger un fichier créé par MIA](#télécharger-un-fichier-créé-par-mia).

**« Puis-je obtenir une présentation qui suit exactement le modèle PowerPoint de mon entreprise ? »**
Oui, pour les présentations demandées **dans la conversation** (*« fais-moi une présentation PowerPoint du projet »*) : si votre administrateur a déposé le modèle de l'entreprise (**Organisation → Entreprise**), le fichier téléchargé est construit dans ce modèle — masques, mises en page, polices, couleurs, pied de page. S'il ne l'a pas fait, demandez-le-lui. Une présentation retouchée dans l'éditeur (**Modifier les slides**) garde, elle, la mise en page de MIA aux couleurs de l'entreprise : pour le modèle exact, téléchargez depuis la carte.

**« L'image générée ne ressemble pas à celle de ma base de connaissances. »**
Vérifiez la ligne **Génération d'une image — D'après « … »** sous l'image : elle dit quelles images de référence ont servi. Absente, aucune n'a été transmise — nommez l'image voulue (son nom de fichier) dans la demande, et vérifiez que sa base est cochée sous le champ de saisie ou liée à l'assistant. Si rien n'y fait, l'outil image ne sait pas partir d'une image : voyez avec un administrateur. Voir [§ 9](#9-génération-dimages).

**« Je veux exactement l'image de la base, pas une variante. »**
Le générateur redessine toujours : la ressemblance est forte, jamais identique au pixel près. Pour l'image d'origine, demandez le fichier à la personne qui gère la base ou l'assistant.

**« Puis-je envoyer une image sans texte ? »**
Oui : joindre l'image et envoyer suffit (utile pour demander une retouche à un assistant). Ajouter une consigne texte reste recommandé pour préciser le résultat attendu.

**« Comment changer de modèle ? »**
Uniquement en conversation **standard** : le **sélecteur de modèle** se trouve **sous la pilule de saisie, à droite** (logo + nom + flèche). Avec un assistant, le modèle est imposé par sa configuration et le sélecteur disparaît.

---

## 21. Installer MIA comme application (PWA)

MIA peut s'installer comme une **application** sur ordinateur et mobile : icône **MIA** (la pieuvre) sur l'écran d'accueil, fenêtre dédiée sans barre d'adresse.

- **Chrome / Edge (ordinateur)** : icône **Installer** dans la barre d'adresse (ou menu ⋮ → **Installer MIA**).
- **Android (Chrome)** : menu ⋮ → **Ajouter à l'écran d'accueil** (ou **Installer l'application**).
- **iPhone / iPad (Safari)** : bouton **Partager** → **Sur l'écran d'accueil**.

L'application installée affiche le nom défini par l'administrateur (par défaut **MIA**) et s'ouvre directement sur vos conversations.

---

## 22. Application mobile MIA (iOS / Android)

En plus de la PWA, MIA existe en **application mobile native** pour iPhone/iPad et Android.

**Installation : ouvrez la page `/app` du site** (ex. `https://votre-mia/app`) depuis le téléphone — elle est accessible sans connexion et propose :
- **Android** : bouton « Télécharger l'application (APK) », puis ouvrir le fichier téléchargé et autoriser l'installation si demandé.
- **iPhone/iPad** : bouton « Installer via TestFlight » (si publié par votre organisation), sinon installation PWA (Safari → Partager → Sur l'écran d'accueil).

Depuis un ordinateur, la page affiche des **QR codes** à scanner avec le téléphone.

L'application affiche la même interface que le site web : **toutes les évolutions de MIA y apparaissent automatiquement**, sans mise à jour de l'application.

### Ce que l'application apporte en plus

- **Connexion Google native** : bouton « Continuer avec Google » avec le sélecteur de comptes du téléphone (la connexion email/mot de passe fonctionne aussi).
- **Partage natif des fichiers** : les exports (PDF, Word, pages HTML, vidéos, images) et les fichiers créés dans la conversation (Excel, CSV, Word…) s'ouvrent dans la **feuille de partage** du téléphone — enregistrement dans Fichiers, AirDrop, envoi par mail ou messagerie…
- **Photos et caméra** : le trombone des pièces jointes propose directement **Prendre une photo** ou la photothèque.
- **Dictée et conversation vocales** : le micro et l'onde sonore fonctionnent comme sur le web (autoriser l'accès au micro à la première utilisation). Pendant une conversation vocale, l'écran reste allumé ; il retrouve son délai de veille dès qu'elle se termine.
- **Liens externes** : les sites web s'ouvrent dans le navigateur du téléphone.
- **Connecteurs Google Drive, Gmail, Google Agenda, Google Tasks, Outlook, calendrier Outlook, Microsoft To Do et Microsoft Teams** (Réglages → Comptes connectés) : l'écran d'autorisation du fournisseur (Google ou Microsoft) s'ouvre dans le navigateur du téléphone ; une fois validé, revenez dans l'application — la connexion apparaît automatiquement.
- Un écran « Connexion impossible » avec bouton **Réessayer** s'affiche si le serveur est injoignable (avion, zone blanche).

### Bon à savoir

- Votre session reste ouverte 7 jours : au retour dans l'application, vos conversations sont là, sans reconnexion.
- Si votre compte vient d'être créé, il doit être **validé par un administrateur** avant la première connexion (même règle que sur le web).
