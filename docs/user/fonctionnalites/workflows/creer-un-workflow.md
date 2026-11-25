---
title: Créer un workflow
description: Décrire un processus à MIA, partir d'un modèle ou composer un workflow brique par brique, puis le publier.
sidebar:
  order: 1
---

Un workflow se crée de quatre façons ; toutes ouvrent l'**éditeur** sur un **brouillon**, que vous
relisez avant de le publier. Rien ne s'exécute avant la publication. Pour une mise en place complète,
du résultat visé au workflow qui tourne seul, suivez [Mettre en place un workflow, pas à
pas](mettre-en-place-un-workflow.md).

## Avant de commencer

Réservé aux créateurs et aux administrateurs : la tuile **Workflows** de la barre latérale. Pensez au
processus comme vous l'expliqueriez à un collègue : ce qui arrive au départ, qui fait quoi, où vous
voulez valider, ce que vous voulez obtenir à la fin.

## Étapes

### Le décrire à MIA

1. **Workflows → Nouveau workflow**.
2. Dans **Décrire à MIA**, écrivez le processus en quelques phrases — ou touchez un **exemple**.
3. **Composer le workflow** : MIA dessine le départ, les étapes, les pièces, les validations. La
   composition consomme quelques crédits.

### Le demander dans le chat

Écrivez par exemple : *« Monte-moi un workflow qui lit les factures que je dépose, me les fait
valider au-delà de 5 000 € et crée une tâche de paiement »*. MIA le compose au plus complet avec ce
que vous avez — votre messagerie et votre agenda, vos assistants, vos bases, vos outils — et affiche
une carte avec le déroulé et ce qui se passera. Vous pouvez lui demander des changements avant
(« ajoute une relecture après la synthèse »).

**Approuver** crée le workflow et ouvre aussitôt son éditeur, où vous l'ajustez :

- s'il ne démarre qu'à la demande et que rien n'est à régler, il est **déjà publié**, prêt à lancer ;
- programmé ou lancé par un évènement, il attend que vous le **publiiez** : il démarrera seul ensuite ;
- s'il reste des points à régler, **Réglages** s'ouvre dessus.

Un bandeau en tête de l'éditeur dit où il en est et vous ramène à la conversation. Il faut un modèle
compatible avec les outils.

### Refaire une réponse du chat en workflow

Quand MIA a suivi un **plan de travail** pour vous répondre, la carte du plan porte **Refaire en
workflow** : chaque étape du plan devient une étape du workflow, et votre demande d'origine devient
le champ de départ.

### Partir d'un modèle, ou d'une page blanche

**Workflows → Nouveau workflow → Partir d'un modèle**, ou **Page blanche** pour tout composer
vous-même. L'onglet **Modèles** de la page Workflows présente chaque modèle : son déroulé, quand il
démarre, ce qu'il produit et les briques qu'il emploie. Ce sont des usages réels — trier les demandes
reçues par email, répondre à un appel d'offres, faire d'une réunion un compte rendu et des tâches,
préparer ses rendez-vous du jour, traiter une facture déposée dans Drive, tenir une veille, lancer
un évènement, accueillir un collaborateur, répondre à une demande de démo venue du site, relancer
chaque client d'un fichier Excel, analyser chaque pièce jointe reçue — qui, à eux tous, montrent ce
que savent faire les workflows.

En l'utilisant, le modèle s'adapte à vous : il prend la messagerie, l'agenda et la liste de tâches
que vous avez reliés (Microsoft ou Google), l'outil de création que vos groupes vous ouvrent, et
retire une livraison vers un service que votre organisation ne propose pas. Il s'ouvre en brouillon
dans l'éditeur ; ce qui reste à votre choix (le dossier Drive surveillé, le canal Slack, vos
assistants) apparaît dans **À régler**.

## Régler le workflow dans l'éditeur

![L'éditeur du workflow « Trier les demandes reçues par email », publié en version 1, avec Versions, Lancements, Réglages, Lancer et Publier en haut. Le rail descend du Départ (un email arrive) à « Comprendre la demande », qui remet la Fiche de la demande, puis à « Aiguiller la demande », où MIA choisit parmi quatre voies : Demande de devis, Réclamation, Prise de rendez-vous et Rien à traiter, chacune avec ses briques — étapes de MIA, actions dans Gmail ou Google Tasks à approuver, notification — et un bouton Ajouter.](../../images/workflow-editeur.png)

- **Le départ** : les champs que vous remplissez au lancement (texte, date, nombre, choix, email,
  oui/non, fichier). Un fichier devient une pièce à lui seul ; les autres champs forment ensemble la
  **Fiche de départ**. Un champ **Choix** reçoit ses options en une ligne, séparées par des virgules
  (au moins deux), et sa valeur par défaut se choisit parmi elles. Un champ **Fichier** peut porter un
  **document de référence** (**Fournir un document de référence**) : un document toujours le même —
  un modèle de réponse, un texte de loi, vos références — joint d'office à chaque lancement, que les
  étapes citent avec @ comme toute pièce ; au lancement, on peut le remplacer pour une fois. **Quand démarre-t-il ?**, en tête
  du volet, réunit les façons de le lancer, chacune avec son interrupteur : toujours **Quand vous le
  lancez** ; **Depuis le chat** pour le lancer avec « @ » ; **À heure fixe** pour qu'il démarre seul
  (chaque jour, jours ouvrés, chaque semaine, chaque mois) avec les valeurs par défaut ; **Quand
  quelque chose arrive** pour le lancer à l'arrivée d'un email, d'un fichier, d'un appel ou à la fin
  d'un autre workflow — voir [Démarrer sur un évènement](demarrer-sur-un-evenement.md).
- **Ajouter une brique** : le bouton **+** entre deux briques du rail ouvre la liste des briques,
  rangées par ce qu'elles font — **Intelligence** (étape, décision de MIA), **Logique** (condition,
  boucle, attente), **Agir dans un outil** (un outil relié choisi directement : Gmail, Agenda,
  Drive…), **Vous et le temps** (validation, me prévenir) ; tapez pour chercher. Au survol d'une
  carte, ses boutons la déplacent, la dupliquent ou la suppriment.
- **Régler une brique** : touchez-la, ses réglages s'ouvrent dans un **volet** sur le côté ; **×** ou
  Échap le referme. Chaque réglage se lit sur une ligne — ce que vous avez choisi — et se déplie pour
  être changé. Un point **À régler** mène au réglage concerné, déjà déplié.
- **Citer une pièce** : tapez **@** dans un champ, ou touchez le bouton **@** à sa droite ; la liste
  propose les pièces et les champs de Fiche disponibles à cet endroit, et la citation se colore de
  la nature de la pièce. Une citation soulignée en rouge ne désigne plus rien d'existant ici.
- **Une étape** : choisissez **qui fait le travail** — MIA avec le modèle de votre choix, ou l'un de
  vos **assistants**, qui travaille alors avec son prompt, son modèle, ses bases de connaissances et
  ses outils (sauf son outil image : une image se fait par une brique **Création** ou une étape qui
  remet une image) ; son avatar s'affiche sur la carte —, écrivez sa **consigne** et citez les pièces avec **@**,
  ajoutez ce qu'elle **peut consulter** (Internet, vos connecteurs, trois bases de connaissances, un
  outil par type — et, pour vos emails et Drive, des filtres : voir [Filtrer les emails et les
  fichiers](filtrer-emails-et-fichiers.md) ; les pièces jointes d'un **Email reçu** cité lui arrivent
  sans rien cocher, voir [Démarrer sur un évènement](demarrer-sur-un-evenement.md)). Si la consigne
  demande un service que l'étape ne consulte pas, MIA le dit dans sa réponse : cochez-le dans **Peut
  consulter**, publiez et relancez. Puis indiquez ce qu'elle **remet au dossier** : un nom et une forme —
  rédigée par l'étape (texte, liste, tableau, Fiche, document) ou **faite par un outil de création**
  (image, page web, carte : l'étape prépare la demande, l'outil fabrique la pièce, voir [Créer une
  image, une page web ou une carte](creer-une-image-une-page-ou-une-carte.md)). Cochez **Je relis la
  pièce avant la suite** pour qu'une validation s'ouvre juste après.
- **Une décision de MIA** : voir [Laisser MIA choisir la voie](laisser-mia-choisir-la-voie.md).
- **Une boucle pour chaque élément** — chaque ligne d'un Excel, chaque pièce jointe, chaque slide,
  chaque fichier d'un dossier, chaque sujet repéré par MIA : voir [Répéter pour chaque
  élément](repeter-pour-chaque-element.md).
- **Une boucle jusqu'à un résultat, une attente, une notification** : voir [Boucles, attentes et
  notifications](boucles-et-attentes.md).
- **Si une erreur survient** : dans le volet d'une étape, d'une action ou d'une condition, réglez les
  nouveaux essais et ce qui se passe ensuite — voir [Reprendre après une erreur](reprendre-apres-une-erreur.md).
- **Le livrable** : les pièces que vous obtenez à la fin, un mot pour conclure, et, si vous voulez,
  ses livraisons — voir [Envoyer le livrable](envoyer-le-livrable.md).
- **Réglages** (en haut, à côté du nom) : l'objectif du workflow, ce qui reste **À régler** (la
  pastille en compte les points), le dossier et le trajet de chaque pièce avec la légende des
  couleurs, le coût estimé d'une exécution (avec son détail, brique par brique, et ce qu'ont
  coûté les dernières exécutions), et **Me prévenir sur mes appareils**.

## Publier

**Publier** n'est possible que lorsque la liste **À régler** ne signale plus de point bloquant (une
consigne vide, une pièce citée qui n'existe pas encore à cet endroit, un assistant devenu
inaccessible, une règle qui ne pourrait jamais être vraie…) — dans **Réglages**, un clic sur un point mène à la brique concernée. Chaque publication crée une
nouvelle version ; les modifications suivantes restent en brouillon jusqu'à la publication suivante.

## Revoir les versions

Chaque version publiée est gardée. Le bouton **Versions**, à côté de l'état « Publié · version *N* »,
les liste de la plus récente à la plus ancienne, avec leur date et leur nombre de briques :

- **Revoir** déroule les briques de cette version ;
- **Repartir de cette version** remplace le brouillon par elle (après confirmation) : ce que vous
  aviez changé depuis est remplacé, mais la **version en service ne change pas** tant que vous ne
  republiez pas.

Une version publiée avant l'arrivée de l'historique est « Reconstituée » d'une de ses exécutions. Le
bouton **Lancements**, à côté, ouvre l'historique des exécutions de ce workflow.
