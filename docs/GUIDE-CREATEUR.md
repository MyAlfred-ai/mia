# Guide créateur — MIA

> Le rôle **créateur** permet de concevoir ses **propres assistants personnels** (jusqu'à **10**). Les administrateurs créent en plus des assistants d'organisation. Ce que chaque rôle permet se règle dans **Personnes → Rôles** : un administrateur peut ouvrir la création d'assistants, de bases ou de workflows à d'autres rôles, ou la retirer — si une page ou un bouton de ce guide manque, c'est votre rôle qui ne le permet pas. Ce guide explique la configuration complète d'un assistant.
>
> Les indications renvoient à ce qui est **affiché à l'écran** (pages, menus, boutons, champs). Voir aussi le **guide utilisateur** et le **guide administrateur**.

---

## Table des matières

1. [Qu'est-ce qu'un assistant ?](#1-quest-ce-quun-assistant-)
2. [Créer un assistant](#2-créer-un-assistant)
3. [Section 1 — Identité](#3-section-1--identité)
4. [Section 2 — Comportement](#4-section-2--comportement)
5. [Section 3 — Connaissances (documents)](#5-section-3--connaissances-documents)
6. [Activer / désactiver l'assistant](#6-activer--désactiver-lassistant)
7. [Ce que « voit » l'assistant](#7-ce-que-voit-lassistant)
8. [Visibilité : personnel ou partagé](#8-visibilité--personnel-ou-partagé)
9. [Bonnes pratiques](#9-bonnes-pratiques)
10. [Limites récapitulatives](#10-limites-récapitulatives)
11. [Workflows : enchaîner les assistants](#11-workflows--enchaîner-les-assistants)

---

## 1. Qu'est-ce qu'un assistant ?

Un **assistant** est une personnalité préconfigurée que les utilisateurs choisissent dans le chat. Il définit :
- un **rôle / ton** (les instructions de fond),
- un **modèle imposé**,
- des **documents de référence** et des **bases de connaissances** consultés pour répondre,
- des **outils** de génération autorisés,
- une **apparence** (nom, couleur, avatar, description).

Quand un utilisateur ouvre une conversation avec un assistant, le modèle est **verrouillé** sur celui de l'assistant.

---

## 2. Créer un assistant

Cliquer la tuile **Assistants** dans la barre latérale du chat, dans la rangée **Espaces**, sous **Nouvelle conversation** et la recherche — les tuiles **Bases** (vos bases de connaissances), **Tâches**, **Workflows** et **Créations** l'accompagnent. C'est le seul chemin : les assistants ne figurent plus dans le menu de l'administration, et un administrateur y voit, depuis la même tuile, tous les assistants de l'organisation. Le bouton **+ Nouvel assistant** de la liste **Assistants** ouvre le formulaire de création. La page garde la barre latérale du chat (conversations, recherche, tuiles), la tuile **Assistants** mise en évidence.

> **Raccourci depuis le chat.** En conversation avec un assistant que vous gérez, le bouton **Modifier**, à droite de la ligne qui présente l'assistant en haut de la pilule de saisie, ouvre directement sa fiche. Le bouton n'apparaît que sur **vos** assistants (les administrateurs le voient sur tous) : inutile de chercher l'assistant dans la liste.

La création comme la modification se font sur une **page dédiée** (plus de fenêtre pop-up), organisée en **3 sections** : **Identité**, **Comportement**, **Connaissances**. En haut à droite, l'interrupteur **Actif / Inactif** contrôle la visibilité de l'assistant.

**À droite de la fiche**, le **schéma de l'assistant** se compose pendant que vous la remplissez (voir **Lire le schéma d'un assistant**, plus bas).

**À la création**, la barre d'actions en bas de page porte le bouton **Créer** : l'assistant n'existe qu'une fois que vous l'avez cliqué. La liste s'ouvre alors sur lui, son schéma à droite (sauf si un document a posé problème : le message reste en tête de page).

**En modification, il n'y a plus de bouton « Sauvegarder » : chaque changement est enregistré tout seul**, peu après que vous l'avez fait — le temps d'une courte pause quand vous écrivez le prompt système, immédiatement quand vous changez le modèle, la température, l'avatar, une base de connaissances ou l'interrupteur **Actif**. Une pastille en bas de l'écran confirme (**Enregistré** ✓), et signale en rouge tout échec, avec un bouton **Réessayer** — la modification n'est jamais perdue. Le lien **Retour à la liste** ramène à la liste : rien à valider avant de partir.

> **Deux champs sont obligatoires** : le **nom** et le **prompt système**. Si vous en videz un, l'enregistrement se bloque et la pastille passe en ambre en nommant le champ ; remplissez-le et tout repart.
>
> **L'interrupteur Actif fonctionne désormais dans les deux sens.** Auparavant, désactiver un assistant depuis sa fiche restait sans effet : il fallait passer par la liste. Le décocher masque bien l'assistant du chat pour tous.

La liste de vos assistants est organisée en deux sections — **Partagés — organisation** et **Personnels** — et chaque ligne récapitule l'essentiel : avatar, nom, description, badges, et des pastilles compactes montrant le **LLM par défaut**, la **température**, le nombre de **documents de référence**, les **bases de connaissances** et les **outils** liés. **Cliquer une ligne affiche, à droite, le schéma de l'assistant** (sur un écran étroit, il se déplie sous la ligne ; un second clic le replie) ; son bouton **Modifier** ouvre la fiche. Les boutons **Utiliser** (démarrer une conversation), **Modifier** et le menu **⋯** (supprimer) restent au bout de chaque ligne. La barre du haut permet de rechercher, filtrer par statut ou LLM, et trier.

**Les badges de la liste :**
- **Inactif** — l'assistant est masqué du chat.
- **Personnel** / **Partagé** — sa visibilité (voir §8).
- **Global** — visible par tous les utilisateurs, sans filtrage de groupe (voir §8).

À côté de ces badges, l'interrupteur **Partage organisation** bascule un assistant de personnel à partagé. Il n'apparaît que pour les administrateurs.

### Ou : MIA crée l’assistant pour vous

Vous pouvez aussi **décrire l'assistant voulu dans une conversation** : *« Crée-moi un assistant qui prépare mes rendez-vous clients à partir de notre catalogue, ton direct »*. MIA le compose et vous le présente dans une **carte Créer un assistant** : nom, avatar (ou la pastille de sa couleur), description, modèle et température, bases de connaissances, outils, et le **prompt système** à déplier — rédigé dans les mêmes six sections que l'aide à la rédaction. **Rien n'est créé avant votre approbation.**

- **Approuver** crée l'assistant tel quel : personnel, actif, ses bases et ses outils liés. La carte confirme et propose **Discuter avec lui**.
- **Demander un changement dans la conversation** (« mets-le en vert », « retire la base RH », « plus formel ») : MIA présente une nouvelle carte qui reprend tout le reste ; la précédente se grise (« Remplacée par la nouvelle proposition ci-dessous »).
- **« Ajuster »** ouvre la **fiche de création déjà remplie** : corrigez ce que vous voulez, ajoutez des documents de référence, puis **Créer** — la carte de la conversation se ferme d'elle-même.
- **Annuler** : rien n'est créé. Sans décision, la carte expire au bout de 30 minutes.

**Dans quelles limites.** MIA ne propose que ce que vous pourriez choisir dans la fiche : les **modèles autorisés à vos groupes** (le modèle de l'application par défaut, un autre seulement si la mission le demande — analyse d'images, raisonnement, réponses simples et fréquentes), les **bases de connaissances de votre périmètre** (les vôtres et celles qu'on vous a partagées, seulement celles qui servent la mission), les **outils** de l'instance, un **avatar** de la bibliothèque. Au clic sur **Approuver**, ces droits sont **revérifiés** ; le plafond de 10 assistants personnels aussi (atteint, la carte attend que vous en supprimiez un).

**Un avatar créé pour lui.** Quand aucun avatar de la bibliothèque ne colle — ou si vous le demandez (« avec une chouette comme avatar ») —, MIA en **génère un** avec l'outil image que vos groupes vous ouvrent, et la carte le montre. Il rejoint la **bibliothèque partagée** à la création de l'assistant seulement, recadré au carré et nommé comme lui. L'image est décomptée de vos crédits même si vous n'approuvez pas ; une retouche qui ne touche pas l'avatar le reprend tel quel. Sans outil image disponible, MIA choisit dans la bibliothèque.

**Ce qu'elle ne fait pas.** Elle ne modifie pas un assistant existant (sa fiche s'en charge), n'ajoute pas de documents de référence, et ne propose rien avec un modèle qui ne sait pas utiliser d'outils : elle vous invite alors à changer de modèle ou à passer par **+ Nouvel assistant**.

### Lire le schéma d'un assistant

Le schéma montre d'un coup d'œil comment l'assistant est composé, avec les mêmes couleurs et les mêmes icônes que le schéma des tâches programmées :

- **Au centre, l'assistant** : son avatar (ou la pastille de sa couleur, à son initiale), son nom, sa description, le début de son **prompt système**, son état — **Actif** ou **Inactif** (traits en pointillés) — et qui le voit : **Personnel**, **Partagé** ou **Global**.
- **À gauche, le modèle** qui répondra, et pourquoi : **imposé à ses conversations**, ou **suit le modèle par défaut** de l'application (y compris quand l'administrateur le change). Un modèle retiré depuis est signalé : le modèle par défaut répond à sa place. Dessous, la **température** envoyée — **non réglable** quand le modèle n'en accepte pas, **ignorée** si l'assistant en garde une d'un modèle précédent.
- **En dessous, ce qu'il consulte et ce qu'il peut faire** : un rectangle pour les **bases de connaissances** (une base inactive y est signalée : elle n'est pas interrogée ; une base liée par un administrateur hors de votre périmètre est comptée, sans son nom), un pour les **documents de référence** (avec leurs tokens, l'extraction en cours ou son échec) et un pour les **outils**. Au-delà de cinq éléments, le rectangle dit combien il en reste.

Sur la fiche — création comme modification —, le schéma montre l'assistant **tel que vous le saisissez** et n'enregistre rien. Il reste en vue pendant que vous faites défiler la page, et chacun de ses éléments se clique : le réglage correspondant vient à l'écran et s'allume un instant — l'assistant mène à **Identité** ; le modèle, la température, le prompt et les outils à **Comportement** ; les bases et les documents à **Connaissances** ; l'état à l'interrupteur **Actif**. Au clavier, un bouton apparaît sur chaque élément du schéma.

---

## 3. Section 1 — Identité
*« Comment l'assistant apparaît à l'utilisateur. »*

| Champ | Détail |
|---|---|
| **Nom** *(obligatoire)* | Le nom affiché de l'assistant. |
| **Couleur** | Sélecteur de couleur : teinte de la carte de l'assistant et, en conversation, du contour de la pilule de saisie et de l'anneau autour de son avatar. Préférer une couleur franche, lisible sur fond clair comme sur fond sombre. |
| **Description** | Texte court affiché sous le nom. Il n'est **pas** transmis au modèle. |
| **Avatar** | Choisir une image dans la bibliothèque, ou en ajouter une via la tuile **＋ Nouveau** en fin de liste (PNG, JPG, GIF ou WebP, jusqu'à 2 Mo). Le champ **Nom de l'avatar** sert à le nommer dans la bibliothèque ; laissé vide, un nom est déduit du fichier. Attention : un avatar ajouté ici **rejoint la bibliothèque partagée** — il est proposé à tous les créateurs, pas seulement à vous. |

---

## 4. Section 2 — Comportement
*« Définit le rôle, le ton et les règles données au modèle. »*

| Champ | Détail |
|---|---|
| **System prompt** *(obligatoire)* | Les instructions de fond de l'assistant (son rôle, son ton, ses règles). Deux aides pour ne pas partir de zéro : **Partir d'un modèle** (bibliothèque de points de départ à adapter) et ✨ **Rédiger avec l'IA** / **Améliorer** (premier jet rédigé par le modèle par défaut de MIA depuis quelques phrases, ou relecture du prompt saisi). Détail ci-dessous. |
| **LLM par défaut** | Le modèle **imposé** à l'utilisateur : dans une conversation avec cet assistant, le sélecteur de modèle disparaît et l'utilisateur ne peut pas en changer. L'option **Défaut application** — son libellé nomme le modèle actuel — fait **suivre** à l'assistant le modèle par défaut de MIA, y compris quand l'administrateur le change ; l'utilisateur n'a pas davantage le choix. Un assistant enregistré avant cette règle garde le modèle recopié à l'époque : rechoisissez **Défaut application** pour qu'il suive. Changer ce réglage s'applique **aussi aux conversations déjà ouvertes** avec cet assistant, pas seulement aux nouvelles. |
| **Température** | Réglage de créativité : **0** = précis et constant, **1** = équilibré, **2** = très créatif. Champ **laissé vide** = réglage par défaut du modèle. Le bouton **↺ Réinitialiser** vide le champ : indispensable pour les modèles qui refusent ce réglage. |
| **Capacités — génération d'image** | Cocher les outils image que l'assistant peut déclencher lui-même pendant la conversation. Requiert un **LLM qui sait appeler des outils**. L'outil reste soumis aux **autorisations de groupe** de l'utilisateur : si son groupe ne lui donne pas droit à cet outil, l'assistant ne le déclenchera pas pour lui. Les **images** des documents de référence et des bases liées servent de modèles (voir la note « Images de référence » plus bas). |
| **Capacités — automatisations n8n** | Cocher les instances n8n que l'assistant peut interroger et déclencher. Le périmètre reste borné par le tag configuré sur l'outil, et **chaque exécution est soumise à l'approbation de l'utilisateur** (carte dans la conversation). Mêmes prérequis : LLM compatible outils, autorisations de groupe. |
| **Capacités — présentations Gamma** | Cocher les comptes gamma.app avec lesquels l'assistant peut générer des présentations. **Chaque génération est soumise à l'approbation de l'utilisateur** et consomme des **crédits Gamma** (service payant, hors quota MIA). Après approbation, comptez 1 à 3 minutes ; le lien arrive dans la conversation. Mêmes prérequis : LLM compatible outils, autorisations de groupe. |

> **Interface bilingue, assistants dans votre langue.** L'interface de MIA s'affiche en français ou en anglais, au choix de chaque utilisateur. Le **nom**, la **description** et le **prompt système** de votre assistant, eux, ne sont pas traduits : ce sont vos textes. Un assistant répond dans la langue du message qu'il reçoit — un prompt système rédigé en français n'empêche pas de lui écrire en anglais, mais s'il doit servir surtout des anglophones, écrivez-le en anglais. MIA se charge de citer les boutons et les écrans dans la langue de la personne qui lit.

> **Température non disponible :** certains modèles ne la gèrent pas — le champ se désactive alors tout seul, avec la note « Ce modèle ne gère pas la température. »
>
> **Température plafonnée :** d'autres acceptent une plage plus étroite que 0 à 2 (jusqu'à 1 chez Claude et GLM, 1,5 chez Mistral). Une valeur plus haute est ramenée à leur maximum, sans erreur.

> **Images et suite de conversation.** Le modèle d'image ne voit pas l'échange : l'assistant lui transmet une description complète construite à partir de la conversation, avec le system prompt et les **documents de référence** en consigne de style. Quand l'utilisateur demande un ajustement (« mets-lui un chapeau », « en bleu »), la **dernière image de la conversation** sert de base et seule la modification demandée est appliquée. Cela suppose un modèle image-to-image (GPT Image, ChatGPT natif, Nano Banana) ; DALL-E et Imagen recréent toujours une image entièrement neuve.

> **Images de référence.** Les images des **documents de référence** de l'assistant et celles des **bases de connaissances** de la conversation (bases liées à l'assistant, bases cochées par l'utilisateur) forment un catalogue que l'assistant voit, avec le nom et la description de chacune. Quand la demande porte sur ce qu'elles montrent — un personnage, un produit, un logo, un lieu — ou que votre prompt système l'impose (« pars toujours de *mascotte.png* »), il les **transmet au générateur** (4 au plus par image, après l'image qu'il retouche) : le générateur les voit et les reprend fidèlement, ce qu'aucune description ne permet. Sans choix de sa part, la **première image** des documents de référence reste le point de départ quand la conversation n'en contient encore aucune. L'outil **Image** du menu « + » fait de même. La ligne **D'après « … »** sous l'image nomme les références utilisées. Au-delà de 25 images, seules les plus proches de la demande sont listées et l'assistant cherche les autres par leur nom ou leur description. Le générateur redessine : la ressemblance est forte, jamais identique au pixel près. Donnez à vos images des **noms parlants** (*mascotte-face.png*, *logo-couleur.png*) : c'est par eux que l'assistant et l'utilisateur les désignent. Mise en place pas à pas : § 5, **Un assistant qui génère des images d'après vos modèles**.

### Ne pas partir de la page blanche : modèles et aide à la rédaction

Le champ **System prompt** propose deux aides, au-dessus de la zone de saisie.

**Partir d'un modèle.** Un bloc dépliant présente une bibliothèque de **points de départ** rédigés, un par usage courant : Assistant généraliste, Expert métier, Analyste de documents, Support / FAQ interne, Rédacteur, Relecteur & traducteur, Formateur, Partenaire de brainstorming. Le bloc est **ouvert à la création** (le champ est vide) et **replié sur une fiche existante**. Cliquer une carte :
- colle le modèle dans le champ et **sélectionne le premier [crochet]** à compléter — `[nom de l'organisation]`, `[domaine]`, `[canal d'escalade]`… — pour que vous tapiez directement votre valeur ; passez ensuite de crochet en crochet, et supprimez les lignes qui ne s'appliquent pas ;
- propose une **description** et une **température** adaptées, **seulement si ces champs sont encore vides** (rien de ce que vous avez saisi n'est écrasé) ;
- replie le bloc, dont le titre garde le nom du modèle choisi (la coche disparaît dès que vous retouchez le texte : le prompt est devenu le vôtre).

Tous les modèles suivent les **mêmes six sections** — `## Rôle`, `## Mission`, `## Ton et format`, `## Règles et limites`, `## Sources`, `## Cas limites`. **Ils suivent la langue de votre interface** : en anglais, les modèles, le squelette affiché dans le champ vide et le texte rédigé par ✨ **Rédiger avec l'IA** / **Améliorer** sont en anglais, avec les sections `## Role`, `## Mission`, `## Tone and format`, `## Rules and limits`, `## Sources`, `## Edge cases`. Lire un seul modèle suffit pour comprendre la structure ; un prompt bien construit se relit et s'ajuste **section par section**. Le champ vide affiche d'ailleurs ce squelette en filigrane. La section **Sources** est celle qui relie l'assistant à ses documents de référence et bases de connaissances : gardez-la.

**Rédiger avec l'IA.** Le bouton ✨ **Rédiger avec l'IA** ouvre un petit panneau : décrivez l'assistant en quelques phrases (pour qui, ce qu'il doit faire, ce qu'il doit éviter), puis **Générer**. Le modèle par défaut de MIA rédige un premier jet **dans les six sections**, en posant des **[crochets]** partout où il lui manque une information plutôt que d'inventer (nom de l'organisation, canal d'escalade…). Le nom et la description déjà saisis sont pris en compte — un nom seul suffit d'ailleurs à lancer la rédaction. Le bouton **Améliorer** fait la même chose à partir du prompt **déjà saisi** : il le restructure et le précise **sans rien inventer ni retirer** ; le champ du panneau sert alors à indiquer ce qui doit changer (« vouvoiement », « plus strict sur les sources »), et peut rester vide. `Ctrl`/`⌘` + `Entrée` dans le panneau lance la génération.

> **Ce que vous devez savoir sur l'aide IA.**
> - Le texte obtenu est un **premier jet** : relisez-le, complétez les crochets, retirez ce qui ne vous ressemble pas. C'est vous qui enregistrez (bouton **Créer** ou enregistrement automatique de la fiche), rien n'est écrit tant que vous n'avez pas validé.
> - Elle **consomme vos crédits** comme un message de chat (une rédaction ≈ un message un peu long). Quota mensuel atteint : les boutons répondent par un message, la bibliothèque de modèles reste utilisable.
> - Les boutons n'apparaissent que si un modèle de langage par défaut est configuré sur l'instance.

**Jamais d'écrasement silencieux.** Si le champ contient un texte que vous avez écrit ou retouché, coller un modèle ou générer un prompt demande confirmation ; et dans tous les cas, le lien **↶ Revenir au texte précédent** sous le champ restaure la version d'avant la dernière insertion.

**Sur un assistant existant, rien ne part sans vous.** La fiche s'enregistre d'ordinaire toute seule, mais un modèle collé ou un prompt généré n'est **pas** enregistré automatiquement : l'assistant est peut-être en service, le texte se relit d'abord. Le champ passe en **« Prompt modifié, pas encore enregistré »** (liseré ambre), l'enregistrement automatique de **toute la fiche** est suspendu, et la barre du bas propose **Enregistrer** (applique le nouveau prompt — et tout autre changement fait entre-temps) ou **Annuler** (revient au prompt enregistré). Retouchez le texte autant que nécessaire avant de trancher ; quitter la page pendant cette relecture vous sera signalé. Une frappe directe dans le champ, sans modèle ni IA, reste enregistrée automatiquement comme avant.

**Rédiger un bon system prompt, si vous préférez l'écrire vous-même :**
- Définir le rôle (« Tu es un assistant juridique spécialisé en droit du travail français. »).
- Préciser le ton et le format de réponse attendu.
- Lister les règles et limites (ce que l'assistant ne doit pas faire).
- Inviter à s'appuyer sur les documents de référence (« Appuie-toi sur les documents fournis. »).
- Nommer deux ou trois cas délicats et la conduite à tenir (demande hors périmètre, information manquante).

---

## 5. Section 3 — Connaissances (documents)
*« Sources documentaires que l'assistant consulte pour répondre. »*

| Élément | Détail |
|---|---|
| **Bases de connaissances** | Collections de documents vectorisés, **partageables entre plusieurs assistants**. Cochez celles que l'assistant doit consulter. Facultatif. Un **cerveau MyBrain** (icône du cerveau, « MyBrain · pour ses groupes seulement ») peut aussi y figurer s'il vous est partagé : l'assistant ne l'interroge que pour les personnes de ses groupes autorisés. |
| **Documents de référence** | Des fichiers que vous ajoutez à l'assistant (à lui seul). **20 fichiers maximum**, **20 Mo par fichier**. |

**Formats acceptés :** PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, pages web, RTF, texte, Markdown, CSV, TSV, JSON, XML, YAML, journaux et fichiers de configuration, fichiers de code, et images.

**Comment ça marche :**
- Après l'ajout, le document est traité. Pendant le traitement, sa ligne affiche **⏳ extraction en cours…** ; en cas de problème, **⚠ échec de l'extraction**. Une fois prêt, la ligne affiche le nombre de **tokens** estimé — ou **image de référence** pour une image, ou **pas de texte extrait** si le fichier n'en contenait aucun.
- À chaque question, l'assistant ne consulte que les **passages pertinents** des documents, dans la limite d'un budget calculé selon le modèle.
- Un **avertissement** s'affiche si vos documents dépassent ce budget. Il se calibre sur le **plus petit modèle autorisé** : le dépassement ne concerne donc pas forcément le modèle que vous avez choisi. Dans tous les cas rien ne casse — seuls les extraits les plus pertinents à la question sont transmis.
- Les images ajoutées sont montrées au modèle (les 3 premières), ne sont pas indexées comme du texte, et servent de **modèles à l'outil image** de l'assistant (voir plus bas, **Un assistant qui génère des images d'après vos modèles**).

**Documents ou base de connaissances ?**

Les deux nourrissent l'assistant, mais **le savoir n'y est pas retrouvé de la même façon** — c'est le critère de choix :

| | Documents de référence | Base de connaissances |
|---|---|---|
| **Recherche** | par **mots** : un passage ne remonte que s'il contient les mots de la question | par **sens** : un passage remonte même s'il est formulé autrement que la question |
| **Découpage** | par **taille**, en tranches régulières — une idée peut être coupée en deux | par **section thématique**, découpé par l'IA — les passages restent cohérents |
| **Portée** | un seul assistant | **partagée entre plusieurs assistants** — et **attachable à une conversation** par l'utilisateur lui-même (voir § 7) |

- **Documents de référence** = simple, idéal pour quelques fichiers propres à UN assistant, dont vous connaissez le vocabulaire et que les utilisateurs citeront avec les mêmes mots.
- **Base de connaissances** = préférable dès que les utilisateurs poseront leurs questions **avec leurs propres mots**, ou que plusieurs assistants doivent partager la même source.

**Créer une base :** la tuile **Bases** de la barre latérale (**Espaces → Bases de connaissances** — un administrateur y voit toutes les bases de l'organisation), puis **+ Nouvelle base**. La fiche s'ouvre **en pleine page**, sans fenêtre par-dessus : **Identité** (nom, description), **Sources** (d'où viennent les documents — vous pouvez les déposer tout de suite), **Assistants liés**, **Partage**, et tout en bas une section **Avancé**, repliée, pour le modèle d'embedding. Cliquez **Créer la base**. Limites : **5 bases par créateur**, **20 documents par base**. Les images y sont décrites par l'IA et deviennent cherchables ; leur fichier sert aussi de modèle à l'outil image des assistants.

**Modifier une base :** sa fiche reprend les mêmes sections ; **chaque changement s'enregistre tout seul** (la mention en bas de page le confirme), et **Retour à la liste** ramène à la liste. En tête, le **panneau d'état** dit où en est la base (cf. plus bas) ; l'interrupteur **Active** est à côté du titre.

**La liste des bases :** chaque base est présentée sous forme de **carte** qui résume tout d'un coup d'œil : sa source d'alimentation (fichiers déposés, Drive, OneDrive, SharePoint, Google Agenda ou cerveau MyBrain), son état, sa volumétrie (documents, extraits, taille), son **utilisation**, le nombre d'**assistants** qui l'interrogent et **qui y a accès**. **Cliquer une carte affiche, à droite, le schéma de la base** (sur un écran étroit, il se déplie sous la carte ; un second clic le replie) ; son bouton **Modifier** — ou le nom de la base — ouvre la fiche.

### Lire le schéma de la base

Le même schéma que celui d'un assistant ou d'une tâche programmée — mêmes couleurs, mêmes pictogrammes, mêmes flèches —, affiché à droite de la liste quand on clique une base, et à droite de sa fiche pendant qu'on la crée ou la modifie (il suit chaque changement, avant même l'enregistrement) :

- **Au-dessus, ce qui l'alimente** : le dossier synchronisé (son nom, **Auto** ou **Manuelle**, le rythme, le compte dont la connexion le lit, le nombre de documents repris) et les **fichiers déposés** (avec leur état : *Indexé*, *Sans texte*, *Illisible*… — un document à vérifier est signalé en ambre). Une base sans source le dit. Pour un cerveau MyBrain : son adresse, interrogée en direct.
- **À gauche, comment elle est cherchée** : le **modèle d'embedding** (vectorisation), ses **dimensions** et le nombre d'**extraits** — ou « En direct » pour un cerveau MyBrain, dont rien n'est copié dans MIA.
- **Au centre, la base** : son nom, sa description, **Active** ou **Inactive** (couleurs éteintes), son état (*À jour*, *Indexation en cours*…), ses documents et ses utilisations.
- **En dessous, ce qu'elle sert** — les flèches partent de la base : les **assistants liés** (avatar, qui les voit ; un assistant hors de votre périmètre est compté, sans son nom) et le **partage** (vous, les groupes et personnes cochés avec leur droit, les administrateurs). Au-delà de cinq éléments, le rectangle dit combien il en reste.

Sur la fiche, **chaque élément du schéma mène à son réglage** : un clic sur le dossier ouvre la section **Sources**, sur la vectorisation la section **Avancé**, sur les assistants la section **Assistants liés**, etc. — la section visée s'éclaire un instant.

**Retrouver une base :** dès que vous en avez plus d'une, une barre d'outils surmonte la grille — des **puces de source** (Toutes, Fichiers déposés, Google Drive, OneDrive, SharePoint, Google Agenda, avec le nombre de bases de chacune), un champ **Filtrer** (nom, description, assistant, personne) qui se combine avec la puce active, et un menu **Trier par** : *Nom*, *Utilisation*, *Utilisée récemment*, *Taille*, *Documents*, *Assistants liés*, *Ajoutée récemment*. Vos choix sont mémorisés dans votre navigateur et retrouvés au retour sur la page.

**« Utilisation » et « jamais utilisée » :** le compteur d'une carte indique dans combien de réponses la base a **réellement fourni des extraits** — et non combien de fois elle a été fouillée. Une base liée à un assistant est consultée à chaque message, donc ce qui la distingue d'une base morte, c'est qu'il en sorte quelque chose. Une base marquée **jamais utilisée** n'est liée à aucun assistant, ou bien son contenu ne remonte jamais : vérifiez qu'elle est **Active**, qu'elle est bien cochée sur l'assistant, et que ses documents parlent des sujets sur lesquels on l'interroge.

**Qui voit vos bases :** par défaut, **vous seul et les administrateurs**. Les autres créateurs ne les voient pas, ni dans la liste des bases, ni dans la page d'édition de leurs assistants.

**Partager une base :** section **Partage** de la fiche d'une base. Cochez des **personnes** (les autres créateurs et administrateurs) et/ou des **groupes** — pratique pour ne pas avoir à repartager à chaque arrivée dans l'équipe. Un bénéficiaire voit alors la base et peut la **rattacher à ses propres assistants**. Cochez en plus **peut modifier** pour lui confier aussi les documents et la réindexation. Dans tous les cas, **supprimer la base et gérer son partage restent à vous** (et aux administrateurs). Une base reçue porte la pastille **Partagée avec vous** — suivie de **· lecture** quand vous n'avez pas le droit de modification.

**Alimenter la base — cinq sources au choix :** la section **Sources** de la fiche d'une base propose cinq tuiles :

- **Fichiers déposés** — vous ajoutez les documents à la main : glissez-les sur la zone de dépôt, ou cliquez-la pour les choisir (plusieurs à la fois) ;
- **Google Drive** — la base reflète automatiquement un dossier Drive : partagé « lien », ou **le vôtre**, choisi en le parcourant ;
- **OneDrive** — la base reflète automatiquement un dossier de **votre** OneDrive (ou partagé avec vous), choisi en le parcourant ;
- **SharePoint** — la base reflète automatiquement un dossier SharePoint (Microsoft 365) de votre organisation ;
- **Google Agenda** — la base reflète automatiquement un agenda Google **public**, sous forme d'un document par mois.

Une base ne peut suivre qu'**une seule source en ligne à la fois** ; l'upload manuel reste possible en complément dans tous les cas. Avec une source en ligne, vous (ou vos collègues) déposez, modifiez ou supprimez des fichiers dans le dossier — ou des évènements dans l'agenda — et la base se met à jour toute seule — au plus tard dans les minutes qui suivent (10 minutes par défaut), ou immédiatement avec le bouton **Synchroniser maintenant** (↻ sur la carte de la base, ou dans le panneau d'état de sa fiche).

**Savoir où en est la base :** sa carte le montre **en direct**, sans recharger la page — **Synchronisation en attente**, **en cours** avec le fichier traité et la progression (« 12 / 40 »), **À jour** avec l'heure du dernier passage, **documents à vérifier**, ou **en échec** avec le motif. Sur sa fiche, le panneau d'état indique combien de documents sont **interrogeables par le sens** et nomme les fichiers de la source **non repris** avec la raison ; la liste des documents, dans la section **Sources**, montre en premier ceux à vérifier avec leur motif (**Illisible**, **Non vectorisé**, **Sans texte**).

Pour **Google Drive**, deux accès au choix (« Accès au dossier ») :

- **Ma connexion Google Drive** — l'accès à privilégier pour des documents internes. Connectez votre Drive une fois dans **Réglages → Comptes connectés**, puis cliquez **Parcourir…** : l'explorateur s'ouvre sous le champ et affiche **Mon Drive**, vos **Drive partagés** et les dossiers **Partagés avec moi** ; vous descendez jusqu'au bon et cliquez **Choisir ce dossier**. Aucun partage public, aucun lien à copier. La synchronisation est alors **portée par votre connexion** : si vous déconnectez votre Drive, elle s'arrête (avec un message clair).
- **Un Drive partagé** (celui d'une équipe, qui appartient à l'organisation plutôt qu'à une personne) se synchronise avec **Ma connexion Google Drive** : **Drive partagés** liste ceux dont vous êtes membre ; ouvrez-en un, puis **Choisir ce dossier** reprend le Drive **entier** — ou descendez jusqu'au dossier voulu. Un dossier qu'on vous a partagé sans vous ajouter au Drive se trouve, lui, dans **Partagés avec moi**.
- **Clé API globale** — pour un dossier partagé publiquement : clic droit → **Partager** → **Accès général : « Tous les utilisateurs disposant du lien »** (Lecteur), puis collez l'**URL du dossier** et cliquez **Tester**. N'y placez pas de documents confidentiels : quiconque possède le lien peut les lire.

Pour **OneDrive** :

1. Connectez votre OneDrive une fois dans **Réglages → Comptes connectés** (lecture seule : MIA ne crée, ne modifie et ne supprime jamais rien chez Microsoft).
2. Dans la section **Sources**, choisissez la tuile **OneDrive**, cliquez **Parcourir…**, descendez dans **Mon OneDrive** ou **Partagés avec moi** jusqu'au dossier voulu, puis **Choisir ce dossier**. Cochez **Synchronisation automatique** si ce n'est pas déjà fait (à la création, cliquez ensuite **Créer la base** ; en modification, tout s'enregistre seul).

La fiche de la base rappelle elle-même la marche à suivre sous le champ **Dossier** — y compris quand votre compte n'est pas encore connecté, avec le lien pour le faire.

> **L'explorateur vous montre ce que la base contiendra.** Dans chaque dossier, les fichiers sont listés avec leur verdict : 📄 **repris** (il sera indexé) ou 🚫 **format ignoré**, et un compteur annonce le total repris. Les **sous-dossiers sont inclus** à la synchronisation même s'ils ne sont pas comptés ici.

Pour **SharePoint** :

1. Dans SharePoint, ouvrez le dossier et copiez son lien (bouton **Copier le lien**, ou l'URL de la barre d'adresse). Aucun partage public n'est requis : l'accès passe par l'application Azure configurée par vos administrateurs, et le dossier doit appartenir à votre organisation.
2. Dans la section **Sources**, choisissez la tuile **SharePoint** : collez l'**URL du dossier**, cliquez **Tester**, cochez **Synchronisation automatique**.

Pour **Google Agenda** :

1. Dans Google Agenda, rendez l'agenda public : **Paramètres et partage de l'agenda** → **Autorisations d'accès aux événements** → **Rendre disponible publiquement**. N'utilisez donc pas un agenda contenant des évènements confidentiels — quiconque connaît son adresse peut le lire.
2. Récupérez son **adresse** : **Paramètres** → **Intégrer l'agenda** → **ID de l'agenda** (par ex. `xxx@group.calendar.google.com`, ou votre adresse Gmail pour votre agenda principal).
3. Dans la section **Sources**, choisissez la tuile **Google Agenda** : collez l'**adresse** (ou l'URL publique), cliquez **Tester** (le nom de l'agenda doit s'afficher), cochez **Synchronisation automatique**.

L'agenda apparaît dans la base sous forme d'**un document par mois** (3 mois en arrière, 12 mois en avant) listant chaque évènement — date, horaire, titre, lieu, description. Vos assistants peuvent alors répondre à des questions comme « quelles réunions la semaine prochaine ? » ou « quand a lieu le séminaire ? ». Tout évènement créé, modifié ou supprimé met à jour le mois concerné au cycle suivant.

Suivez ensuite l'état dans le panneau d'état de la fiche (dernier cycle et son bilan : ajoutés, mis à jour, supprimés, ignorés…) et via la pastille **Drive**, **OneDrive**, **SharePoint** ou **Agenda** de la liste des bases.

Bon à savoir : les sous-dossiers sont inclus ; les **Google Docs/Sheets/Slides** sont convertis automatiquement ; **100 fichiers maximum** par base synchronisée (mêmes formats que l'upload, **jusqu'à 50 Mo par fichier** — les fichiers synchronisés sont téléchargés côté serveur, hors de la limite d'upload manuel de 20 Mo) ; les documents synchronisés portent une pastille **Drive**, **OneDrive**, **SharePoint** ou **Agenda** et se retirent **en les supprimant de la source** (pas depuis MIA tant que la sync est active). Vous pouvez toujours uploader des documents à la main dans la même base : la synchronisation ne les touche pas. Pour **changer de source**, sélectionnez une autre tuile : les documents de l'ancienne source seront retirés au premier cycle de la nouvelle (un avertissement le rappelle). Si une tuile est grisée, les identifiants correspondants ne sont pas configurés — contactez un administrateur.

**La section Avancé** (en bas de la fiche, repliée) réunit ce dont on n'a pas besoin au quotidien :

- **Modèle d'embedding** — ce qui permet la recherche par le sens. À la création, la liste propose les modèles des fournisseurs dont une clé est configurée : **OpenAI 3-large (1536d)** (proposé par défaut), **OpenAI 3-small (1536d)**, moins cher, **Mistral Embed (1024d)** et **Gemini Embedding (1536d)**. Pour en changer ensuite, choisissez un modèle dans **Réindexer avec** — l'écran dit ce qui va se passer — puis cliquez **Réindexer la base** et confirmez : tous les documents sont re-vectorisés avec le modèle choisi (garder le même modèle recalcule simplement les vecteurs). Pendant l'opération, la base répond par les mots.
- **Extraits vectorisés** — le détail de ce que la recherche retrouve vraiment : le nombre d'extraits et de tokens, puis chaque extrait tel qu'il est en base, document par document (numéro, tokens, caractères), avec son **vecteur** : **Vectorisé** (la recherche par le sens le retrouve), **Sans vecteur** (seule la recherche par mots le retrouve — « Réindexer » le recalcule) ou **Vecteur non vérifié** (le moteur vectoriel ne répond pas). Filtrez par document ou cherchez un mot dans le texte des extraits : c'est le bon endroit pour comprendre pourquoi une question ne remonte pas le passage attendu.

> **Ce que coûte une base.** Alimenter une base consomme de l'IA : le **découpage sémantique** de chaque document (un modèle de chat lit le texte pour repérer ses sections), la **description des images** (modèle vision) et la **vectorisation** (modèle d'embedding) — à l'upload, à chaque synchronisation d'un document modifié et à chaque **Réindexer**. Ces opérations sont **comptées sur votre compte** (page **Usage**, catégorie *Bases de connaissances*) mais **hors quota** : elles n'entament pas vos crédits mensuels de chat. Ensuite, chaque question posée à un assistant qui consulte la base coûte l'embedding de la question à l'utilisateur (quelques tokens).

> **Base système.** La base **MIA : mode d'emploi** porte la pastille **Système**. Livrée avec MIA, elle est alimentée par les guides officiels et resynchronisée automatiquement à chaque nouvelle version : c'est elle qui répond quand un utilisateur interroge l'assistant d'aide. Elle n'est pas modifiable, et sa case apparaît grisée dans la liste des bases d'un assistant — sa liaison à l'assistant d'aide est posée automatiquement.

### Un assistant qui génère des images d'après vos modèles

Pour qu'un assistant dessine **votre** personnage, **votre** produit ou **votre** logo — et non une version inventée —, donnez-lui des images de référence et un outil image qui sait les reprendre. C'est la réponse à « comment avoir un assistant qui génère des images d'après celles de ma base de connaissances ? ».

**Ce qu'il faut**

1. **Un outil image qui sait partir d'une image** : GPT Image, un modèle ChatGPT natif (GPT-5.x) ou Nano Banana. Cochez-le dans **Comportement → Capacités — génération d'image**. Avec DALL-E ou Imagen, les références ne peuvent pas être transmises (l'assistant le dit à l'utilisateur). L'outil doit aussi être autorisé dans les groupes des utilisateurs — c'est un réglage d'administrateur.
2. **Un modèle qui sait appeler des outils** — tous les modèles sauf Perplexity ; **Défaut application** convient.
3. **Les images, à l'un de ces deux endroits** :
   - les **Documents de référence** de l'assistant (section **Connaissances**) : quelques images propres à cet assistant — sa mascotte, sa charte. La **première** déposée est son point de départ quand rien d'autre n'est demandé ;
   - une **base de connaissances** liée à l'assistant : beaucoup d'images, partagées entre plusieurs assistants, ou tenues à jour depuis un dossier synchronisé (Google Drive, OneDrive, SharePoint). Chaque image y est décrite par l'IA : l'assistant la retrouve aussi par ce qu'elle montre (« le camion rouge », « la façade en briques »).

   Formats : JPG, PNG, WebP et GIF (converti automatiquement). Une très grande image est réduite à 2 048 pixels de côté avant l'envoi.
4. **Des noms parlants** : *mascotte-face.png*, *logo-couleur.png*, *flacon-500ml.jpg* plutôt que *IMG_0423.jpg*. C'est par leur nom que l'assistant et l'utilisateur les désignent ; deux images aux noms trop proches obligent à préciser laquelle.
5. **Une consigne dans le prompt système**, si l'assistant doit toujours partir des mêmes images : *« Pour toute illustration, reprends le personnage de mascotte-face.png ; ajoute logo-couleur.png quand le logo doit apparaître ; respecte la palette de la charte. »*
6. **Un essai** : demandez une image dans une conversation et vérifiez, sous l'image, la ligne **Génération d'une image — D'après « … »**.

**Comment ça se passe**

- À chaque message, l'assistant voit la liste des images de référence disponibles — celles de ses documents, de ses bases et des bases que l'utilisateur a cochées sous le champ de saisie —, avec leur nom et leur description. Au-delà de **25 images**, il voit d'abord les plus proches de la demande et cherche les autres par leur nom ou leur description.
- Il choisit celles que la demande vise — **4 au plus** par image générée — et les transmet au générateur avec sa consigne, après l'image qu'il retouche s'il y en a une. Le générateur **voit** ces images : il reprend fidèlement ce qu'elles montrent, ce qu'aucune description ne permet.
- Sous l'image, la ligne **Génération d'une image — D'après « … »** nomme les références utilisées.
- Quand l'utilisateur ne nomme rien et que la conversation ne contient encore aucune image, la **première image des documents de référence** sert de point de départ — jamais une image de base prise au hasard. Pour une image sans aucun lien avec elle, l'assistant s'en passe.
- L'outil **Image** du menu **+** fait de même : il choisit les références que la demande vise, dans les documents de l'assistant de la conversation et dans les bases de la conversation.
- Les images viennent des sources que la conversation interroge déjà : vos documents, les bases liées à l'assistant, et celles que l'utilisateur a cochées dans la limite de ses droits. Un cerveau MyBrain n'apporte aucune image.

**Ce qu'il ne faut pas attendre**

- **Une copie au pixel près** : le générateur redessine. La ressemblance est forte — forme, couleurs, détails —, jamais identique. Pour utiliser l'image elle-même, telle quelle, il faut le fichier d'origine : dans une présentation, par exemple, le bouton **Image → depuis votre ordinateur** l'insère sans la modifier.
- Les **sites illustrés** et les **présentations** ne reprennent pas encore ces références : leurs illustrations sont générées à partir d'une description.
- Chaque image de référence transmise est facturée en entrée par certains fournisseurs : une image générée d'après quatre références coûte un peu plus qu'une image seule (le coût réel est compté, page **Usage & crédits**).

**Si l'image ne reprend pas la référence voulue**

| Ce qui arrive | Cause probable | Remède |
|---|---|---|
| Aucune ligne « D'après … » sous l'image | L'outil image ne sait pas partir d'une image, ou l'image n'est dans aucune source de la conversation | Choisir un outil GPT Image ou Nano Banana ; lier la base à l'assistant, ou la cocher sous le champ de saisie |
| L'assistant reprend la mauvaise image | Deux images aux noms proches, ou des noms qui ne disent rien | Renommer les fichiers ; nommer l'image voulue dans la demande |
| L'assistant dit qu'une image ne peut pas être transmise (« trop lourde », « format non pris en charge ») | Fichier trop lourd même réduit, plus de 12 Mo de références à la fois, ou format d'image inhabituel | Alléger l'image, l'enregistrer en JPG ou PNG, ou demander moins de références à la fois |
| Une image de la base n'est jamais proposée | Base inactive, non liée à l'assistant ni cochée, ou image encore en cours de traitement | Vérifier l'état de la base et de l'image dans sa fiche |

---

## 6. Activer / désactiver l'assistant

- Interrupteur **Actif / Inactif** en haut à droite de la page d'édition (actif par défaut). Sur **Inactif**, l'assistant devient **invisible** dans le chat.

---

## 7. Ce que « voit » l'assistant

Pour répondre, l'assistant combine, dans l'ordre :

1. **Vos instructions** (le system prompt de l'assistant).
2. L'**entreprise** et son **vocabulaire** (définis par un administrateur, Organisation → Entreprise) : le vocabulaire part en entier s'il est court, sinon seules les entrées que cite la conversation — ou vos instructions — sont ajoutées, à chaque message.
3. Un **bloc de règles imposé par MIA** (voir ci-dessous).
4. Les **préférences de l'utilisateur** (son style et son contexte personnel).
5. La **mémoire long terme de l'utilisateur** (section « MIA pour vous » de ses **Préférences IA**).
6. Les **documents de référence** de l'assistant.
7. Les **bases de connaissances** rattachées à l'assistant.
8. Les **bases de connaissances que l'utilisateur a lui-même choisies** pour cette conversation.
9. Quand un outil image lui est confié, la **liste des images de référence** (noms et descriptions) tirée de ses documents et de ces bases — voir § 5.

> Les préférences de l'utilisateur s'ajoutent **après** vos instructions : un utilisateur peut affiner le style, mais pas remplacer le rôle que vous avez défini.

> **Les bases choisies par l'utilisateur.** Depuis le bouton **livre ouvert** sous le champ de saisie, chaque utilisateur peut ajouter jusqu'à **3** bases de connaissances auxquelles il a accès, pour la durée d'une conversation. Elles **s'ajoutent** aux vôtres et ne les remplacent jamais : les bases que vous avez rattachées à l'assistant restent cochées et non retirables de son côté. Vous n'y avez pas accès et n'avez rien à prévoir. Conséquence utile à connaître : votre assistant peut citer une source que vous ne lui avez pas fournie — c'est l'effet attendu, et la ligne d'activité affichée au-dessus de la réponse indique lesquelles viennent de l'utilisateur.

> **« MIA pour vous ».** Chaque utilisateur peut demander à MIA de retenir des informations durables sur lui (*« Souviens-toi que je travaille au marketing »*) ; elles sont injectées dans **toutes** ses conversations, y compris celles menées avec votre assistant. Vous n'y avez pas accès et n'avez rien à prévoir : c'est du contexte personnel, pas une consigne de rôle. Il peut donc arriver que votre assistant adapte spontanément son format ou ses exemples à un utilisateur — c'est l'effet attendu.

**Le bloc de règles imposé par MIA**

Il s'ajoute à **tous** les assistants et ne se règle pas. Le connaître évite deux mauvaises surprises : vos instructions ne peuvent pas le contredire, et il explique des comportements que vous n'avez pas demandés.

- **Livraison de fichiers.** Quand on lui demande un fichier — classeur Excel ou CSV, document Word, PDF ou RTF, présentation PowerPoint, texte, JSON, SQL, script… —, l'assistant écrit son contenu dans un bloc de code qui porte le **nom du fichier**. L'utilisateur voit une **carte** avec un aperçu et un bouton **Télécharger**, et le vrai fichier (classeur, document Word…) est fabriqué au moment du clic ; la flèche de la carte propose les autres formats possibles. Cela marche avec **tous les modèles**. Vous pouvez donc demander un livrable précis dans votre system prompt (*« livre toujours le planning dans un fichier Excel »*) sans expliquer comment faire : l'assistant connaît la convention.
- **Aucun lien de téléchargement inventé.** Il lui est interdit de fabriquer une adresse de téléchargement ou un fichier temporaire : ces liens seraient faux et ne fonctionneraient pas. Hors des fichiers ci-dessus, les images, pages web et vidéos passent par la section **Outils** du menu **+** du chat. Un format que MIA ne sait pas créer (ancien .ppt ou .doc, Keynote, archive .zip…) est annoncé comme tel, avec le format le plus proche. Une présentation PowerPoint livrée ainsi est mise en page automatiquement (couverture, une slide par partie, sans image) ; pour des slides illustrées et retouchables, c'est l'outil **Présentation**.
- **Schémas.** MIA sait afficher des diagrammes. L'assistant est autorisé à en produire quand un schéma clarifie vraiment la réponse (processus, architecture, séquence, organigramme, arbre de décision, planning, relations entre entités), et invité à ne pas en abuser.

> Inutile donc de réécrire ces règles dans votre system prompt. À l'inverse, si votre assistant renvoie une carte de fichier, un bloc de code ou un schéma que vous n'attendiez pas, l'explication est ici.

---

## 8. Visibilité : personnel ou partagé

Trois modes, signalés par un badge dans la liste des assistants :

- **Personnel** : l'assistant n'est visible **que par vous** (son créateur). Limite : **10 assistants personnels** par créateur.
- **Partagé** (organisation) : visible par les utilisateurs **via leurs groupes**.
- **Global** : visible par **tous** les utilisateurs, sans filtrage de groupe. Ce mode est réservé à l'assistant d'aide livré avec MIA (**MIA : mode d'emploi**) et ne se règle pas depuis l'interface.

Le passage **personnel ↔ partagé** est **réservé aux administrateurs** : c'est l'interrupteur **Partage organisation** de la liste.

> **Partager ne suffit pas à rendre visible.** Un assistant **Partagé** n'est visible de personne tant qu'il n'a pas **en plus** été coché dans **Assistants autorisés** d'un groupe. C'est une action d'administrateur, dans **Administration → Personnes → Groupes** ; le menu **⋯** de la ligne propose le raccourci **Autoriser dans les groupes →**. Un assistant partagé mais autorisé dans aucun groupe reste invisible pour tout le monde.

---

## 9. Bonnes pratiques

- **Tester** l'assistant dans une conversation avant de le partager.
- **Choisir le modèle** selon le besoin : raisonnement (analyse), rapidité/économie (tâches simples), vision (images).
- **Documents** : préférer des fichiers propres et ciblés ; trop de documents dilue la pertinence et coûte des crédits.
- **Images de référence** : des noms de fichiers parlants, une image par sujet (de face, sur fond neutre de préférence), et une consigne qui dit quand les reprendre — voir § 5.
- **Température** : basse (0–0,3) pour des réponses factuelles ; plus haute (0,8–1,2) pour la créativité.
- **Avatar + couleur** : aident l'utilisateur à reconnaître l'assistant d'un coup d'œil — en conversation, l'avatar et le nom s'affichent en haut de la pilule de saisie, et la couleur en dessine le contour.

---

## 10. Limites récapitulatives

| Élément | Limite |
|---|---|
| Assistants personnels par créateur | **10** |
| Documents par assistant | **20** |
| Taille par document | **20 Mo** |
| Bases de connaissances par créateur | **5** |
| Documents par base de connaissances (upload manuel) | **20** |
| Documents synchronisés (Google Drive, OneDrive, SharePoint ou Google Agenda), par base | **100** |
| Images analysées (documents) | 3 |
| Images de référence transmises par image générée | **4** (en plus de l'image retouchée) |
| Images de référence listées à l'assistant | **25** — les autres se retrouvent par recherche |
| Image de référence transmise | réduite à **2 048 px** de côté ; **12 Mo** au plus pour l'ensemble des références d'une image |
| Avatar (ajout d'image) | 2 Mo |
| Pièce jointe dans le chat | **10 Mo** |
| Température | 0 à 2 (ou vide) |

---

## 11. Workflows : enchaîner les assistants

Un **workflow** confie à MIA et à vos assistants un travail en plusieurs étapes, mené toujours de la même façon — répondre à un appel d'offres, transformer des notes de réunion en compte rendu et en tâches, faire valider une facture. Réservé aux créateurs et aux administrateurs : la tuile **Workflows** de la barre latérale, et la liste **@** de la zone de saisie. La fonctionnalité est en **bêta** (pastille « Bêta » sur la tuile) : elle fonctionne, mais peut encore évoluer.

**Créer** — **Workflows → Nouveau workflow** : **Décrire à MIA** (quelques phrases ; MIA compose le brouillon, pour quelques crédits), **Partir d'un modèle** ou **Page blanche**. Les neuf modèles sont des usages réels qui, à eux tous, montrent ce que savent faire les workflows : **trier les demandes reçues par email** (MIA comprend chaque email puis le range dans la bonne voie — devis, réclamation, rendez-vous, ou classé sans suite), **répondre à un appel d'offres** (décision d'y aller, réponse exigence par exigence, mémoire relu, email de remise), **compte rendu → actions**, **préparer mes rendez-vous du jour** (chaque matin, une fiche par rendez-vous), **facture fournisseur → paiement** (lancé par un fichier déposé dans Drive : contrôle, accord au-delà d'un seuil, rappel avant l'échéance), **veille hebdomadaire** (une note reprise jusqu'à être sourcée), **lancer un évènement** (affiche, page d'invitation et plan d'accès faits par vos outils de création), **accueil d'un nouveau collaborateur** (mot de bienvenue la veille, point à un mois) et **demande de démo depuis le site** (lancé par l'adresse d'appel du workflow). Chaque vignette de l'onglet **Modèles** montre le déroulé du modèle, quand il démarre, ce qu'il produit et les briques qu'il emploie. En l'utilisant, le modèle **s'adapte à vous** : Outlook à la place de Gmail, le calendrier Outlook à la place de l'agenda Google, Microsoft To Do à la place de Google Tasks si c'est ce que vous avez relié, l'outil de création que vos groupes vous ouvrent, et une livraison vers un service absent de votre instance est retirée. Il s'ouvre en brouillon dans l'éditeur : réglez-le (dossier Drive surveillé, canal Slack, assistants…) avant de le publier. Dans le chat : « Monte-moi un workflow qui… » — MIA le compose **au plus complet** avec ce que vous avez (messagerie et agenda reliés, assistants, bases, outils de création et d'automatisation, validations, livraisons) et propose une carte avec le déroulé et ce qui se passera. Demandez-lui des changements avant si besoin. **Approuver** le crée et **ouvre aussitôt son éditeur** pour l'ajuster (un bandeau dit où il en est et ramène à la conversation) : s'il ne démarre qu'à la demande et que rien n'est à régler, il est **déjà publié**, prêt à lancer ; programmé ou lancé par un évènement, il attend que vous le publiiez ; s'il reste des points à régler, **Réglages** s'ouvre dessus. Une réponse menée selon un **plan de travail** porte **Refaire en workflow** sur la carte du plan.

**La liste** — **Mes workflows** montre une vignette par workflow. En haut, une **couverture** à sa couleur (assombrie si besoin pour que le dessin reste lisible) trace son déroulé comme une **ligne de métro** : un point par brique, un losange là où le rail se sépare, des voies qui partent en biais et se rejoignent, une barre au bout d'une voie qui arrête le workflow, un cadre pointillé autour d'une boucle, un double cercle pour le livrable ; un brouillon a une couverture hachurée, un workflow en pause une ligne estompée. Sur la couverture, la **pastille d'état** (Brouillon, En pause, Publié, ou l'état de l'exécution : en cours avec l'étape atteinte « 2/5 », attend votre décision, terminé, en échec…) et l'icône de ce qui le lance. Une exécution **qui vous attend** passe avant tout le reste, même si le workflow est en pause, et l'étape concernée est **cerclée** sur la ligne (orange quand elle vous attend, bleu quand elle tourne, rouge en échec). Dessous : **Quand** (ce qui le lance) et **Résultat** (les pièces du livrable et où elles partent, sinon l'objectif), puis les assistants qui y travaillent et le nombre de briques. **Toucher la vignette n'importe où ouvre l'éditeur** (sans droit de modifier : la dernière exécution) ; la pastille ouvre l'exécution, **Lancer** ouvre la fenêtre de lancement, le bouton ‖ met en pause (**Réactiver** sur un workflow en pause).

**L'éditeur** — le **rail**, de haut en bas : le **départ** (les champs remplis au lancement ; un fichier devient une pièce, les autres champs forment la **Fiche de départ** ; déclencheurs : à la main, **Depuis le chat**, **Programmé**, et **Démarrer sur un évènement**, cf. plus bas), les **briques**, le **livrable** (les pièces obtenues à la fin, et ses **livraisons**). **Une ligne par brique** : qui la fait (l'avatar de l'assistant, ou MIA), son titre, les pièces qu'elle lit (des points de leur couleur) et celle qu'elle remet (une étiquette) ; entre deux briques, des **paquets** de la couleur des pièces descendent le lien (ce qui passe d'une étape à l'autre) ; survoler une pièce fait pâlir les briques qui ne la touchent pas. **Une couleur par nature de pièce** : texte (bleu), liste (sarcelle), tableau (vert), Fiche (violet), document (orange), fichier (ardoise), image (jaune), page web (indigo), carte (vert tilleul), champ d'une Fiche (rose). **Toucher une brique ouvre ses réglages dans un volet**, sur le côté (× ou Échap le referme) : chaque réglage s'y lit **sur une ligne** — ce que vous avez choisi — et **se déplie** pour être changé ; un point « À régler » mène au réglage concerné, déjà déplié. **Citer une pièce** : **@** dans n'importe quel champ (consigne, critères, filtre, objet ou destinataire d'un email, dossier Drive, mot de la fin), ou le bouton **@** du champ ; seul ce qui a un sens dans le champ est proposé (un destinataire n'accepte qu'un champ email), et la citation se colore de sa nature (soulignée en rouge si elle ne désigne plus rien ici). **Réglages**, en haut à côté du nom, ouvre ceux du workflow : l'objectif, ce qui reste **À régler** (la pastille en compte les points), le dossier, le coût estimé d'une exécution, **Me prévenir sur mes appareils**. **+** entre deux briques ouvre la liste des briques, rangées par ce qu'elles font (**Intelligence**, **Créer**, **Logique**, **Agir dans un outil** — chaque outil relié directement : Gmail, Agenda, Drive… —, **Vous et le temps**), avec une recherche :
- **Étape** — **qui fait le travail** (MIA avec un modèle au choix, ou un de vos **assistants** : il travaille avec son prompt, son modèle, ses bases et ses outils), la **consigne** (citer une pièce : taper **@**), ce qu'elle **peut consulter** (Internet, connecteurs, trois bases, un outil par type), **ce qu'elle peut faire dans les outils** (lire seulement, préparer des actions à approuver, agir sans validation), ce qu'elle **remet au dossier** (Texte, Liste, Tableau, Fiche aux champs typés, Document Word/PDF/PowerPoint/Excel au modèle de l'entreprise) et **Je relis la pièce avant la suite**.
- **Création** — fait produire par un **outil de création** du chat ouvert à vos groupes **une image**, **une page web** (simple ou illustrée) ou **une carte** d'itinéraire, à partir du dossier : **Avec quel outil**, **Ce qu'il faut créer** (citer une pièce avec **@** ; un champ de Fiche cité devient sa valeur), le **format** d'une image (celui de l'outil, carré, paysage, portrait) ou le **style des illustrations** d'une page illustrée, et **Je relis le résultat avant la suite**. Elle part du dossier, jamais de la conversation : pour une image, MIA tire des pièces lues la description à donner au modèle ; pour une page ou une carte, les pièces sont le contenu (une page peut mettre en page une image créée plus tôt). Une image lue — créée plus tôt ou reçue au départ — sert d'**image de départ** (si l'outil sait en partir, sinon « À régler » le dit) ; une page ou une carte lue est modifiée plutôt que refaite ; **renvoyée avec une remarque**, la création repart de sa version précédente (elle ne se corrige pas à la main). Elle remet une pièce **Image**, **Page web** ou **Carte** : dans le dossier de l'exécution (l'image s'y affiche, téléchargeable en PNG ou JPEG ; la page en .html, Word ou PDF ; l'itinéraire en Word, PDF ou Markdown), dans sa conversation, dans le message du livrable (l'image y est montrée, chaque création avec son fichier) et dans **Mes créations** (une seule fois, même reprise par le livrable). Une étape suivante **voit** l'image (jointe à son message). Livraisons : une image part en pièce jointe d'un email (allégée au-delà de 1 Mo) et entre dans une base, qui la décrit ; Drive et Slack la laissent de côté. Dans une boucle, elle sert aux briques du même tour (« jusqu'à » garde sa dernière version). Pas de vidéo dans un workflow. Un outil retiré (groupe, arrêt, test en échec) arrête l'exécution sur la brique avec sa raison.
- **Action** — un geste dans un outil relié (email Gmail/Outlook, agenda, tâches, Slack, Drive, n8n, Gamma), à approuver par défaut. Une pièce que la consigne demande de **mettre** dans l'email, le message ou le document y figure **telle quelle** (son contenu exact, jamais son nom ni un résumé ; un champ de Fiche, sa valeur) ; une **image** citée apparaît **dans le corps de l'email** (intégrée au message, pas en pièce jointe), même si MIA a oublié de la placer — hors email (Slack, Drive), elle reste nommée. La pièce prend la mise en forme de sa destination (mise en forme Slack dans un message Slack, texte simple dans les notes d'une tâche ou la description d'un évènement). Une pièce qui en cite d'autres arrive complète : une étape qui écrit sa pièce à partir des précédentes la remet avec leur contenu, et l'email reprend le tout. Un texte venu de l'extérieur (un email reçu, un fichier déposé) n'est jamais complété ainsi : ce qu'il contient reste tel qu'il est arrivé. Pour un résumé, le demander dans la consigne. Vaut aussi pour une étape qui agit dans les outils.
- **Décision de MIA** — un aiguillage : MIA lit des pièces, suit vos **critères** (« lis @Email reçu et choisis selon ce que demande le client ») et choisit **une** voie parmi deux à six que vous nommez et décrivez (*Devis* : « la demande porte sur un prix »), chacune avec ses briques, reprenant le rail ou arrêtant le workflow. Sa raison s'écrit dans la conversation. **Si MIA ne sait pas choisir** : vous demander (la page de l'exécution propose toutes les voies, la plus proche signalée, et le détail de la brique dit ensuite qu'elle a été choisie par vous ; dans le chat, la carte prend la plus proche ou arrête) ou prendre une voie par défaut. Une pièce remise dans une voie n'est disponible après la décision que si toutes les autres voies arrêtent le workflow.
- **Condition** — **une règle** sur les champs d'une Fiche (sans IA, sans crédit) ou **une question à MIA** (oui/non avec sa raison ; si elle hésite : vous demander, ou prendre « non ») ; deux voies **Si oui** / **Si non**, chacune reprenant le rail ou arrêtant le workflow.
- **Comment se lisent les voies** — sous une condition ou une décision, le rail se sépare : une **colonne par voie**, toutes de même largeur, chacune de **sa couleur** (la même pour son étiquette, son fond et ses liaisons). L'étiquette en tête dit **Si oui** / **Si non**, ou le **numéro et le nom** de la voie (sa description dessous). Les liaisons descendent chaque voie et se rejoignent sur un **point de jonction** avant la brique suivante ; une voie qui arrête le workflow s'arrête sur sa brique **Arrêter le workflow** (ou sur « Fin du workflow ») sans revenir. Trois voies ou plus élargissent le rail : s'il ne tient pas à l'écran, il défile de côté ; au téléphone, les voies passent l'une sous l'autre. Pendant une exécution, la voie prise porte « voie prise » et une liaison plus épaisse, les autres « écartée » et s'estompent.
- **Validation** — vous relisez des pièces : approuver, **corriger**, **renvoyer à l'étape avec une remarque** (deux fois au plus), arrêter ; sans réponse au bout du délai (sept jours par défaut), l'exécution s'arrête.
- **Boucle** — répète les briques placées dans son cadre. **Pour chaque élément** : le volet de la boucle se règle en quatre temps.
  - **1. Que parcourir ?** — neuf façons, en tuiles : **Lignes d'un tableau ou d'un Excel** (un fichier Excel ou CSV du départ, un Tableau remis par une étape, ou un fichier Google Drive désigné par son lien, son nom ou son chemin ; onglet et ligne d'en-tête réglables, en-tête détecté seul ; **les colonnes que les briques citent**, écrites comme dans le fichier ou lues d'un clic sur **Lire les colonnes d'un fichier exemple**, et **la colonne qui nomme chaque ligne**), **Éléments d'une liste** (une Liste, un champ liste d'une Fiche, un texte : une ligne = un élément), **Pièces jointes d'un email** (l'email qui lance le workflow, ou les emails d'une recherche ; toutes, PDF, images, documents Office, textes et CSV ; logos et signatures collés dans le corps écartés), **Slides d'une présentation** (titre, texte, notes du présentateur), **Pages d'un PDF**, **Sections d'un document** (Word, Markdown, texte : une section par titre), **Fichiers d'un dossier Drive** (nom contenant, nature), **Emails d'une recherche** (Gmail ou Outlook, recherche du service, période) et **Repérés par MIA** (MIA lit une pièce et en sort les éléments que vous décrivez — « chaque sujet abordé », « chaque référence produit » —, avec les champs que vous demandez ; un seul appel au départ). **Essayer sur un fichier exemple** dit combien d'éléments la boucle y trouve (le fichier est lu puis oublié).
  - **2. Quels éléments garder ?** — **Éléments au plus** (au-delà : signalés, pas traités ; 50 par défaut, sous le plafond de l'organisation), une **règle** sur les champs de l'élément (sans IA), **Sans doublon sur** un champ, **Écarter les lignes de total et les éléments sans nom** (« Total », « Sous-total », une note en bas d'un tableau : coché par défaut), **Relire la liste avant de commencer** (vous décochez ce qu'il ne faut pas traiter).
  - **L'élément en cours** — nommé, il se cite avec @, entier ou par un champ (« @Client en cours › Email ») ; un élément qui porte un fichier (pièce jointe, fichier d'un dossier) le joint au message des étapes qui le lisent. Les pièces remises dans la boucle sont celles du tour.
  - **3. Si un élément échoue** — **Passer au suivant, le marquer en échec** (par défaut ; trois échecs d'affilée mettent la boucle en pause, **Reprendre** refait l'élément qui a mis en pause) ou **Arrêter le workflow**. **Les actions préparées** : **une carte à approuver par élément**, ou **relire toutes les actions ensemble, à la fin de la boucle** (chaque tour prépare son action sans attendre ; à la fin, vous cochez celles qui partent).
  - **4. Ce que la boucle remet** — lignes d'un tableau et une Fiche par tour : le **fichier complété** (ses colonnes d'origine, puis les champs remis, et un **Statut** si un élément n'a pas été traité) ; une Fiche par tour : un tableau, une ligne par élément ; un texte ou un document : une section par élément ; une liste : tous les éléments.
  - Dans une boucle, une **condition** trie l'élément sur ce que le tour vient de produire, sans ouvrir de voies : pour chaque réponse, **continuer le tour**, **passer à l'élément suivant** (compté comme écarté) ou **arrêter le workflow**. Ni décision de MIA, ni autre boucle.
  - Ou **Jusqu'à un résultat** : l'étape recommence en reprenant sa version précédente, jusqu'à ce qu'une règle ou une question à MIA dise que c'est bon (« la note est-elle d'au moins 8 ? »), puis garde la dernière version — ou arrête le workflow si le nombre de tours est atteint.
- **Attendre** — une durée (minutes, heures, jours, 30 jours au plus) ou **une date du dossier** (un champ Date d'une Fiche, avec une heure et un décalage : « -2 » = deux jours avant). Le workflow reprend seul à l'heure dite, même si personne n'ouvre MIA ; une date déjà passée ne fait pas attendre, une date à plus de 90 jours arrête l'exécution. **Ne plus attendre** sur la page de l'exécution reprend tout de suite.
- **Me prévenir** — un message sur vos appareils inscrits (Espaces → Tâches programmées → Notifications) et dans la conversation, sans IA ni crédit ; un champ cité devient sa valeur (« Nouvelle demande de @Email reçu › Expéditeur »).
- **Arrêter le workflow** — au bout d'une voie de condition ou de décision (rubrique **Logique** du **+ Ajouter** de la voie ; une par voie, toujours en dernier : ce que vous ajoutez ensuite dans la voie se range avant elle). L'exécution se termine là, proprement : statut **Terminée** (une fin prévue, par défaut) ou **Arrêtée** (une sortie à remarquer), et un **mot de la fin** facultatif dans la conversation, qui peut citer les pièces. Ni livrable, ni livraison, ni workflow enchaîné ; ce qui a été produit reste dans le dossier. Une pièce remise dans l'autre voie reste citable après l'embranchement.

**Les filtres** — une étape qui consulte Gmail, Outlook ou Google Drive (dans **Peut consulter**), ou une action qui y agit (**Filtres**), ne voit que ce que vous filtrez : pour les emails, **De**, **Objet contient**, **Mots**, la période de réception, **Avec pièce jointe**, **Non lus seulement** ; pour Drive, **Dans le dossier**, **Dont le nom contient**, la nature (documents, feuilles de calcul, présentations, PDF), la période de modification. Le filtre accompagne **chaque recherche** des outils de l'étape, quoi que demande le modèle. Une brique qui agit peut limiter **ses destinataires** (adresses ou domaines : un email vers une autre adresse est refusé avant sa carte, et même sans validation) ; une action Drive filtrée range ses fichiers dans le dossier du filtre. Les filtres citent le dossier (« De : @Email reçu › Expéditeur », « Dans le dossier : Clients/@Client en cours ») ; la brique filtrée l'indique sur le rail. Le déclencheur **Un email arrive** se filtre de même (expéditeur, objet, pièce jointe), **Un fichier arrive** par nature et par nom.

**Si une erreur survient** — chaque étape, création, action, condition et décision a son réglage : **réessayer seul** une à trois fois quand le service du modèle est saturé ou le réseau coupé (après 1, 5 puis 15 minutes — jamais pour une clé refusée ou un crédit épuisé, qu'attendre ne règle pas), puis **arrêter le workflow** (par défaut) ou **ignorer la brique et continuer** (la suite se passe de sa pièce : à réserver aux briques facultatives ; une condition ou une décision ne s'ignore pas).

**Démarrer sur un évènement** — en plus des déclencheurs du départ, un workflow peut partir tout seul : **un email arrive** (Gmail ou Outlook, filtré par expéditeur, objet, pièce jointe, et une recherche avancée facultative : `label:`…), **un fichier arrive dans un dossier Drive**, **un logiciel appelle son adresse** (n8n, Zapier, un formulaire ou un script poste du JSON à une adresse secrète, affichée avec un exemple ; « Changer d'adresse » la renouvelle), ou **un autre de vos workflows se termine**. Ce qui est arrivé devient la première pièce (**Email reçu** — une Fiche : expéditeur, objet, date, contenu, pièces jointes —, **Fichier reçu**, **Données reçues**, **Résultat du workflow précédent**). Boîte et dossier sont vérifiés **toutes les 5 minutes** ; ce qui y est déjà au moment de la publication ne lance rien ; un même email ou fichier ne lance jamais deux exécutions. Ce contenu vient de l'extérieur : il est remis aux étapes comme une donnée, jamais comme une consigne, et « À régler » vous signale toute action qui agirait sans validation à partir de lui.

**Les livraisons du livrable** — dans le volet du livrable, **Puis le livrable part** : l'**envoyer par email** (Gmail ou Outlook, à des adresses fixes ou à celle d'un champ du dossier, objet et message qui citent les pièces, documents en **pièces jointes**), l'**enregistrer dans Google Drive** (un Google Doc ou un Google Sheet par pièce, dans le dossier choisi), le **publier dans Slack**, l'**ajouter à une base de connaissances** que vous gérez (à la place de la version précédente, ou daté à chaque exécution). Chacune part **après votre accord** (une carte, la même que dans le chat), en **brouillon**, ou **sans validation** si l'administrateur le permet. Une livraison en échec arrête le workflow sur son livrable : **Relancer les livraisons en échec** ne refait que celles-là, **Terminer sans elles** clôt l'exécution.

Le brouillon s'enregistre au fil de l'eau ; **Publier** (possible quand plus rien de bloquant n'est « À régler ») crée une version, la seule qui s'exécute — les exécutions en cours gardent la leur.

**Lancer et suivre** — **Lancer** (liste ou éditeur) ouvre une fenêtre : les champs du départ, et **ce qui va se passer** (les briques, les validations à venir, le coût estimé — chaque étape au coût d'une réponse de SON modèle, ses recherches Internet comprises si elle lit le web, chaque création au coût de son outil, chaque question posée à MIA au coût d'un appel court ; une fourchette, calculée comme le décompte). Depuis le chat, **@** propose les workflows réglés **Depuis le chat** ; les fichiers joints deviennent les fichiers de départ. La page de l'exécution montre **en tête** ce qui vous attend (validation, actions, cause d'un arrêt, pièces livrées), puis le rail animé (en cours en bleu, vous attend en orange, fait avec sa durée) ; toucher une brique ouvre dans un volet qui l'a faite, sa consigne, ce qu'elle a lu et remis — pour une action, **Ce qui a été fait** : chaque action, son sort et son contenu (**Voir l'email**) —, et **Dossier** ouvre toutes les pièces (une page web s'y affiche dans un cadre isolé) ; les livraisons, en tête, se déplient de même, et la relecture des actions d'une boucle montre chaque email en entier ; toucher une pièce fait pâlir les briques qui ne la touchent pas, jusqu'à ce que vous fermiez le volet, changiez de brique ou touchiez le vide (ou Échap) (à lire, corriger lors d'une validation, télécharger). Chaque exécution a sa **conversation** ; les actions préparées s'y approuvent comme toute carte. Quand une boucle parcourt le fichier du départ (lignes, slides, pages, sections), la fenêtre de lancement le lit dès le dépôt : **combien de tours**, les éléments laissés par le filtre ou en double, les premiers d'entre eux, et le coût recalculé ; au-delà de cinquante tours, **Lancer** demande confirmation. Dans une boucle, le rail montre le tour en cours (« 3 / 8 ») et le volet de la boucle chaque élément avec son état (traité, **en échec** avec sa cause, **écarté**, en cours, à venir) et sa pièce. **Relire la liste** : l'encadré montre les éléments, tous cochés — **Lancer avec la sélection**. **Relire les actions** : à la fin de la boucle, les actions préparées à chaque tour, à cocher, avec **Créer les brouillons** ou **Envoyer les emails** pour des emails — **Exécuter la sélection**, ou **N'en exécuter aucune**. **Refaire les éléments en échec** (en tête et dans le volet de la boucle) ne rejoue qu'eux : les éléments traités gardent leur pièce, ce qui suit la boucle est refait. En échec ou en pause (crédits, assistant devenu inaccessible), la page propose : **Reprendre à cette brique** (là où elle s'est arrêtée), **Reprendre avec la version *n*** (si vous avez corrigé et republié le workflow depuis : vos réglages s'appliquent à l'exécution en cours), **Ignorer et continuer** (une étape, une action, une attente), et **Refaire depuis une brique…** (aussi sur une exécution terminée ou arrêtée : les pièces remises à partir de cette brique sont refaites, celles d'avant gardées). Un nouvel essai automatique prévu s'affiche avec son heure, et **Réessayer maintenant** l'avance.

**Historique des exécutions** — un onglet de **Workflows** (le bouton **Historique** de l'éditeur, avec le nombre de lancements, y mène filtré sur ce workflow ; celui de la page d'une exécution l'ouvre sur ce lancement) : à gauche **tous vos lancements**, tous workflows confondus, rangés par jour, du plus récent au plus ancien, filtrables par **workflow** et par état (**Terminés**, **En échec**, **En cours**, **Arrêtés**, avec leur nombre) ; **Afficher les lancements plus anciens** charge la suite. Chaque ligne dit de quel workflow il s'agit (sa couleur, son nom, le numéro du lancement), **ce qu'il traitait** (les champs remplis au départ, l'objet d'un email reçu, le nom d'un fichier), l'heure, le déclencheur, la durée, la version, et une **bande** qui montre jusqu'où il est allé (une case par brique, de la couleur de son issue) ; un échec affiche sa cause, une exécution en cours la brique atteinte. Toucher un lancement ouvre son **journal** à droite (sur un écran étroit, à la place de la liste — **Tous les lancements** ou le retour du navigateur pour revenir ; au clavier, les flèches passent d'un lancement à l'autre) : en tête le workflow (son nom ouvre l'éditeur), l'état, la date, ce que le lancement traitait, le déclencheur, la durée, les briques faites, le coût en crédits, la version (signalée quand une plus récente est publiée), la cause d'un arrêt et le **résultat** d'un lancement terminé (les pièces du livrable, avec aperçu et téléchargement) ; les gestes : **Décider dans l'exécution** (il vous attend), **Reprendre l'exécution** (arrêté sur une erreur, en pause), **Suivre en direct** (en cours), sinon **Ouvrir l'exécution**, puis **Conversation** et **Relancer** (la fenêtre de lancement s'ouvre remplie avec les données de ce lancement ; les fichiers sont à rejoindre) ; puis le **déroulé**, une entrée par passage de brique à son heure — qui l'a faite (assistant et modèle, MIA, l'outil, vous) et en combien de temps, son issue (voie choisie et pourquoi, réponse d'une condition avec les valeurs lues, validation approuvée ou renvoyée avec votre remarque), la **pièce remise** avec un aperçu (début du texte, champs d'une Fiche, image créée) et son téléchargement — **Voir le contenu** la déplie en entier (texte et document mis en forme, liste et tableau complets, Fiche avec tous ses champs, texte lu dans un fichier reçu, image en grand, page web dans un cadre isolé, itinéraire et ses étapes) —, **ce qui a été fait** (recherches et lectures dans vos outils), les **actions proposées et leur sort** (approuvée et faite, faite sans validation, refusée, en échec, expirée, en attente ; un email dit s'il a été **envoyé** ou rangé en **brouillon**) avec **Voir l'email** (À, Cc, objet, message tel qu'il est parti, pièces jointes, contenus lus avant de l'écrire, **Ouvrir dans Gmail** ou Outlook) ou **Voir le contenu** (message Slack ou Teams, évènement, tâche, document ou feuille Drive, données n8n), les **livraisons** (chacune avec son contenu à déplier), au départ **Voir tout le départ** quand un champ est long et une carte par fichier reçu, les **erreurs**, les nouveaux essais automatiques et les passages **repris ensuite**. Les tours d'une boucle sont rangés sous elle, avec leur élément ; la boucle dit combien d'éléments ont été traités, en échec, écartés ou laissés par le filtre, et ses relectures combien d'éléments ou d'actions ont été gardés ; ce qui vous attend porte **Décider dans l'exécution** ; un lancement en cours se met à jour seul, dans la liste comme dans le journal. L'adresse de la page garde les filtres et le lancement ouvert. Supprimer la conversation d'une exécution laisse son lancement dans l'historique, sans le détail des actions.

| Élément | Limite |
|---|---|
| Workflows par personne | **30** |
| Briques par workflow | **15** |
| Bases de connaissances par étape | **3** |
| Renvois d'une étape par validation | **2** |
| Voies d'une décision de MIA | **2 à 6** |
| Délai d'une validation | **1 à 14 jours** (7 par défaut) |
| Éléments traités par une boucle « pour chaque » | **50** par défaut, sous le plafond de l'organisation (**200** par défaut ; 50, 100, 200 ou 500) |
| Colonnes déclarées d'une boucle sur des lignes / champs demandés à MIA | **20** / **8** |
| Fichier parcouru par une boucle | **25 Mo**, **5 000** lignes lues |
| Fichiers d'un dossier Drive / emails d'une recherche / éléments repérés par MIA | **200** / **100** / **200** |
| Tours d'une boucle « jusqu'à » | **5** (3 par défaut) |
| Attente d'une durée / d'une date | **30 jours** / **90 jours** |
| Nouveaux essais automatiques d'une brique | **3** |
| Livraisons du livrable | **5** |
| Pièces jointes d'un email | **5**, **3 Mo** en tout |
| Appels de l'adresse d'un workflow | **60** par heure |
| Exécutions lancées par vérification d'une boîte ou d'un dossier | **5** (les suivantes à la vérification d'après) |
| Workflows enchaînés l'un après l'autre | **3** |
