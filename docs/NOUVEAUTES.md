# Nouveautés de MIA

<!--
  SOURCE UNIQUE des nouveautés annoncées aux utilisateurs (clic sur le numéro
  de version, page « À propos », et réponses de l'assistant « MIA : mode
  d'emploi » à « quoi de neuf ? »).

  RÈGLE : à chaque évolution FONCTIONNELLE, ce fichier est mis à jour dans le
  MÊME commit — comme les guides docs/. Deux gestes possibles, et le second
  est le plus fréquent :

    • la fonctionnalité est nouvelle  → AJOUTER une entrée en tête ;
    • la fonctionnalité existe déjà   → ENRICHIR son entrée (reformuler,
      compléter, corriger). Une entrée couvre volontiers cinq commits :
      c'est ce qui distingue cette liste d'un journal de commits.

  Un commit qui ne change rien pour un utilisateur ni pour un administrateur
  (outillage, déploiement, dette technique) n'a RIEN à écrire ici.

  FORME — le titre ouvre l'entrée, la ligne de métadonnées la qualifie :

      ## Titre lisible, à hauteur d'utilisateur
      <!-- id: slug-stable | genre: nouveaute | pour: tous -->

      Une à trois phrases.

    - `id`      : minuscules, chiffres, tirets. STABLE À VIE : c'est lui qui
                  date l'entrée (premier commit où il apparaît). Le changer
                  re-date l'entrée et la fait remonter en tête.
    - `genre`   : nouveaute | amelioration | correctif
    - `pour`    : tous | createurs | administrateurs  (informatif : tout le
                  monde voit toute la liste)
    - `majeure` : oui — facultatif, RARE. Seulement l'ARRIVÉE d'une
                  fonctionnalité entière (les workflows, Claude dans Office),
                  jamais une amélioration ni un correctif. Elle est alors aussi
                  montrée une seule fois à chaque personne qui peut s'en servir,
                  dans la fenêtre « Quoi de neuf » (titre court, deux phrases,
                  un schéma animé sur un exemple concret), puis signalée par une pastille verte sur
                  l'endroit où la trouver. Sa présentation s'écrit dans
                  src/Changelog/MajorFeatures.php (CATALOG), sous le même `id` —
                  un test vérifie que les deux se répondent.
    - `date`, `version` : facultatives. Posées automatiquement depuis
                  l'historique git ; ne les écrire à la main que pour les
                  entrées antérieures à la mise en place de ce fichier.

  ÉCRITURE — apprises en rédigeant les vingt premières :
    - dire ce que la personne PEUT FAIRE, jamais ce qui a été codé ;
    - aucun nom de fichier, d'écran technique, de table ni de réglage interne ;
    - un correctif se raconte par son SYMPTÔME (« le bouton passait pour
      désactivé »), jamais par sa cause ;
    - un titre court, un corps d'une à trois phrases ;
    - nommer les écrans comme ils s'appellent à l'écran (« Réglages →
      Comptes connectés »), c'est la seule façon d'être suivi.

  L'ordre du fichier fait foi : le plus récent en tête. Les 20 premières sont
  publiées, les suivantes restent ici comme mémoire du produit.
-->

## Workflows : une pièce se corrige aussi dans la discussion
<!-- id: workflow-piece-corrigee-discussion | genre: correctif | pour: createurs -->

Pendant une validation, donnez ou corrigez les valeurs directement dans la conversation de l'exécution (« l'Ad Spend d'octobre est de 15 539 € HT ») : MIA met à jour la pièce relue, la carte de validation montre la nouvelle version, et la suite du workflow la lit. Avant, ces valeurs restaient dans la discussion et la suite écrivait encore « en attente de saisie ». MIA ne mène plus non plus elle-même les étapes du workflow quand vous lui écrivez : elle ne prépare plus d'email en double.

## Les tableaux d'un email arrivent en vrais tableaux
<!-- id: emails-tableaux | genre: correctif | pour: tous -->

Un tableau rédigé par MIA dans un email (Gmail, Outlook, livrable d'un workflow) arrive mis en forme chez le destinataire, avec ses bordures et ses colonnes de chiffres alignées. Avant, il s'affichait en lignes de barres verticales illisibles.

## Plus besoin de se reconnecter après une mise à jour
<!-- id: sessions-persistantes | genre: correctif | pour: tous -->

Chaque mise à jour de MIA déconnectait tout le monde, et une connexion expirait au bout de sept jours, même pour qui venait tous les jours. Vous restez désormais connecté tant que vous revenez au moins une fois tous les 30 jours, mises à jour comprises.

## Version soignée par Claude : beaucoup moins de crédits
<!-- id: version-soignee-claude-cache | genre: correctif | pour: tous -->

Une présentation, un classeur ou un document mis en page par un modèle Claude coûte désormais bien moins cher : Claude relit son travail déjà fait au lieu de le repayer à chaque étape. Le coût annoncé avant de lancer s'appuie aussi sur les réalisations de votre organisation, et un plafond est affiché : au-delà, la mise en page s'arrête et le fichier déjà prêt vous est livré.

## Workflows : l'étape qui lit un email reçu reçoit ses pièces jointes
<!-- id: workflow-email-pieces-jointes | genre: correctif | pour: createurs -->

Une étape qui cite « Email reçu » reçoit désormais ses pièces jointes en fichiers : un classeur Excel se lit en entier et se compte ligne à ligne, sans rien cocher dans « Peut consulter ». Avant, l'étape ne voyait que le nom du fichier et répondait qu'elle n'y avait pas accès. Une étape ou une tâche à qui manque un service indique aussi le bon geste (« Peut consulter », « Avec quoi ») au lieu du menu « + » du chat.

## Comptes : filtrer par groupe
<!-- id: comptes-filtre-groupe | genre: amelioration | pour: administrateurs -->

Dans **Personnes → Comptes**, un filtre **groupe** rejoint ceux du rôle, du statut et de la connexion : choisissez un groupe pour n'afficher que ses membres, ou **Sans autre groupe** pour retrouver les comptes à qui aucun groupe n'a encore été donné. Il se combine avec la recherche et les autres filtres, et **Copier les emails** ne prend que les comptes affichés.

## Groupes : la recherche de membres filtre à nouveau la liste
<!-- id: groupes-recherche-membres | genre: correctif | pour: administrateurs -->

Dans **Personnes → Groupes**, taper un nom ou une adresse dans la recherche des membres laissait toute la liste affichée : seules les personnes qui correspondent restent désormais visibles, en création comme en modification d'un groupe. Les accents ne comptent plus : « helene » trouve « Hélène ».

## Une page par assistant, et une fiche plus claire
<!-- id: assistant-page-et-fiche | genre: amelioration | pour: createurs -->

Chaque assistant a désormais **sa page** : son état, qui le voit, une case **Posez-lui une question** qui ouvre la conversation la question prête, ses chiffres des 30 derniers jours, sa consigne, ce qu'il connaît, son modèle, ses outils et son schéma — chaque bloc mène à son réglage. Sa **fiche** de création et de modification prend la mise en page des bases de connaissances : une barre en haut qui reste en vue, un menu de sections (Identité, Consigne, Modèle, Capacités, Connaissances) et le schéma tout en bas ; les documents de référence se déposent sur une zone, plusieurs à la fois.

## Vos tables ne sont plus copiées dans la conversation
<!-- id: tables-sans-copie | genre: amelioration | pour: tous -->

Quand vous demandez « les clients de Lyon » ou « trie par chiffre d'affaires » sur une table cochée, MIA n'en fait plus de copie : elle en fait une **vue**, qui suit votre table quand elle change. Faire lire chaque ligne ou préparer une action par ligne s'applique directement à votre table, et une carte propose d'y écrire les valeurs calculées. Le volet **Tables** sépare **Vos tables** des **Résultats de MIA**, temporaires ; un résultat tiré d'une table qui a changé depuis est marqué **Périmé**, et **Garder** propose d'ajouter à votre table les colonnes qu'il apporte plutôt que d'en créer une seconde.

## La page des assistants, épurée : une recherche d'abord
<!-- id: assistants-recherche-d-abord | genre: amelioration | pour: createurs -->

La page **Assistants** s'ouvre sur une recherche — par nom, description, modèle ou créateur — et deux cartes pour en créer un : **Nouvel assistant** (le formulaire) ou **Le décrire à MIA** (une conversation s'ouvre, la demande déjà commencée). Chaque assistant tient sur une ligne : son avatar, son modèle, ce qu'il connaît, une icône pour qui le voit et son état. Sa description apparaît en bulle quand la souris s'y attarde, ou sur le petit **?** qui suit son nom (au doigt, touchez le **?**). Cliquez la ligne pour ouvrir sa page ; au survol, **Discuter** et le menu **⋯**. La bibliothèque d'avatars s'ouvre depuis un lien en bas de la page.

## Gemini 4 Neon au catalogue, Gemini Embedding 2 pour les bases
<!-- id: catalogue-google-19-novembre-2026 | genre: nouveaute | pour: administrateurs -->

**Gemini 4 Neon**, le nouveau modèle de Google placé entre Gemini 4 Hydrogen et Gemini 4 Carbon, s'active dans **Modèles IA → Fournisseurs et modèles** ; il ne devient pas le modèle par défaut. Pour les bases de connaissances, **Gemini Embedding 2**, le modèle que Google recommande désormais, est proposé à la création d'une base vectorisée par Gemini ; les bases existantes gardent le leur, et **Réindexer avec** permet d'en changer.

## L'historique d'une table, et revenir en arrière
<!-- id: table-historique | genre: nouveaute | pour: createurs -->

La page d'une table dit quand elle a été créée et modifiée, et par qui. Son bouton **Historique** liste chaque changement — import, cellules corrigées, modification de MIA ou d'un workflow — et montre la table telle qu'elle était à un moment, cellules changées surlignées. **Revenir à cet état** la remet ainsi sans rien effacer, et s'annule pendant un quart d'heure.

## Comprendre et corriger un document qui ne s'indexe pas
<!-- id: kb-fiche-document | genre: amelioration | pour: createurs -->

Dans une base de connaissances, chaque document dit quand il a été indexé pour la dernière fois ; cliquez-le : sa fiche montre ses étapes et ses extraits, et, s'il pose problème, dit pourquoi en clair (document numérisé, fichier protégé, fournisseur indisponible…). **Réessayer** le relance quand un nouvel essai peut réussir ; sinon, **Exclure de la base** l'écarte des recherches et des problèmes de la base, et **Rétablir** le reprend.

## Essayer un workflow sur un de vos derniers emails
<!-- id: workflow-essai-email-recent | genre: amelioration | pour: createurs -->

Pour essayer à la main un workflow qui démarre à l'arrivée d'un email, la fenêtre de lancement propose maintenant vos dix derniers emails : choisissez-en un, il est traité comme s'il venait d'arriver, avec son expéditeur, ses pièces jointes et la réponse possible dans son fil. Si vous lancez sans rien choisir, MIA vous prévient que le workflow n'aura aucun email à traiter.

## Quota mensuel : le plus généreux l'emporte toujours
<!-- id: quota-le-plus-genereux | genre: correctif | pour: administrateurs -->

Relever le quota d'un groupe relève désormais tous ses membres, même ceux qui avaient un ancien quota individuel plus bas : MIA retient le plus élevé entre le quota de la personne et ceux de ses groupes actifs. Un quota individuel sert à relever quelqu'un au-dessus de ses groupes, plus à le limiter en dessous ; la fiche du compte dit, pendant la saisie, lequel s'appliquera.

## Bases de connaissances : une recherche d'abord, une page par base, trois visibilités
<!-- id: bases-recherche-page-visibilites | genre: amelioration | pour: createurs -->

La page des bases s'ouvre sur une recherche qui trouve une base par son nom, un document, un créateur ou un assistant, et chaque base a désormais sa propre page : son état, ce qu'elle sait — une recherche dans ses extraits, gratuite —, qui s'en sert, et son schéma en bas. Une icône dit qui la voit : **Privée** (vous seul), **Commune** (vous, les administrateurs et ceux à qui vous la partagez) ou **Publique** (toute l'organisation la coche dans le chat). Créer et modifier une base ou une table se fait sur la même fiche, avec un menu de sections et le schéma qui suit vos réglages en direct.

## Réunions Google Agenda : répétition, invités, privée ou publique — et modification
<!-- id: agenda-google-reunions | genre: amelioration | pour: tous -->

Avec **Agenda Google**, MIA prépare de vraies réunions : elle les fait répéter comme vous le dites (« un mardi sur deux », « le premier lundi du mois », « chaque jour ouvré jusqu'à fin juin, sauf le 25 décembre »), invite les personnes nommées en obligatoire ou en facultatif, les rend **privées** ou **publiques**, et vous montre **disponible** ou **occupé** pendant la réunion. Elle sait aussi modifier un évènement existant — ajouter ou retirer quelqu'un, le décaler, le passer en privé. Par défaut, Google Agenda envoie l'invitation dès votre approbation : la carte nomme chaque invité avant.

## Supprimer un workflow depuis sa vignette
<!-- id: supprimer-un-workflow-vignette | genre: amelioration | pour: createurs -->

Une corbeille sur chaque vignette de **Mes workflows**, et en haut de l'éditeur, supprime un workflow — il fallait jusqu'ici la chercher au bas de ses Réglages. Une fenêtre dit d'abord ce qui part (ses versions, l'historique de ses lancements) et quelles exécutions en cours s'arrêtent ; rien n'est supprimé sans votre confirmation, et les conversations des lancements restent.

## GPT-6.2, Gemini 4 Hydrogen et Gemini 4 Carbon au catalogue
<!-- id: catalogue-llm-17-novembre-2026 | genre: nouveaute | pour: administrateurs -->

De nouveaux modèles s'activent dans **Modèles IA → Fournisseurs et modèles** : **GPT-6.2 Astra**, le plus capable d'OpenAI et moins cher que GPT-6 Astra, **GPT-6.2 Sol**, son modèle de tous les jours, **Gemini 4 Hydrogen**, le modèle généraliste de Google, et **Gemini 4 Carbon**, son plus capable ; aucun ne devient le modèle par défaut. **Mistral Large (latest)**, le modèle par défaut de Mistral, répond désormais avec Mistral Large 4 : il est compté à ce tarif et ne s'arrêtera plus le 30 avril 2027. Et **DeepSeek** cherche maintenant sur Internet, en citant ses sources.

## Vos tables dans les workflows
<!-- id: workflow-tables | genre: nouveaute | pour: createurs -->

Une table de vos bases de connaissances se branche maintenant sur un workflow : une étape la lit en entier pour compter, filtrer ou croiser, et remet son résultat comme un Tableau sans recopier ses lignes ; une boucle la parcourt ligne par ligne ; une livraison y écrit à la fin (ajouter, mettre à jour un statut, tout remplacer), après votre accord sur une carte qui montre les valeurs avant et après. Les tables établies par une étape restent disponibles pour les suivantes.

## Le logo de MIA sur ce qu'elle fait dans vos workflows
<!-- id: workflow-logo-mia | genre: amelioration | pour: createurs -->

Sur le rail d'un workflow, une étape sans assistant porte désormais le logo de MIA quand elle travaille avec le modèle de votre organisation, et le logo de l'éditeur du modèle quand elle en impose un autre. Les questions et les décisions de MIA portent son petit logo au coin de leur pictogramme : on voit d'un coup d'œil qui fait quoi.

## Un workflow peut en appeler un autre
<!-- id: workflow-appelle-workflow | genre: nouveaute | pour: createurs -->

La brique **Appeler un workflow** lance un autre de vos workflows au milieu du vôtre : vous lui donnez ses paramètres (une valeur, une pièce ou un champ du dossier), et son résultat revient comme une pièce. MIA vous prévient avant de publier si un paramètre manque ou n'est pas de la bonne nature, si le workflow appelé n'est pas publié, ou si deux workflows s'appelleraient sans fin. Sur le rail, elle se reconnaît d'un coup d'œil : l'icône de l'espace Workflows, une pile de cartes, le workflow appelé en miniature.

## Des tables permanentes dans vos bases de connaissances
<!-- id: tables-permanentes | genre: nouveaute | pour: tous -->

Un fichier clients, une grille de tarifs, un suivi : gardez-les comme **tables** dans Bases de connaissances — depuis un fichier Excel ou CSV, à la main, ou en cliquant « Garder » sur un résultat établi par MIA dans une conversation. Cochée dans le chat ou liée à un assistant, une table est lue ligne par ligne : MIA compte, filtre et croise jusqu'à 20 000 lignes sans en oublier, et vous l'ouvrez en entier par « Voir » sous sa réponse. Demandez-lui de la modifier — changer des valeurs, ajouter ou supprimer des lignes ou une colonne : elle le fait dans la table même, après votre accord sur une carte qui montre le changement ; la version d'avant reste récupérable.

## Recoupez des centaines de mails, de fichiers ou de lignes sans rien perdre
<!-- id: tables-de-travail | genre: nouveaute | pour: tous -->

Demandez à MIA un travail complet sur beaucoup d'éléments — « parmi les clients de ce fichier, lesquels ne m'ont pas écrit depuis septembre ? » : elle range tout dans une liste de la conversation (mails Gmail ou Outlook, fichiers d'un dossier Drive, lignes d'un classeur — un gros classeur joint y va d'office), puis croise, filtre et compte sans rien oublier. Elle peut lire chaque ligne pour la classer ou la résumer, et préparer un brouillon ou une tâche pour chacune : vous approuvez une seule carte. La liste reste hors du fil : sous la réponse, « Voir » l'ouvre dans un volet où vous la parcourez, la filtrez, la téléchargez, la renommez, la supprimez ou la gardez.

## Une pastille vous invite à vous présenter à MIA
<!-- id: contexte-pastille | genre: amelioration | pour: tous -->

Tant que « Votre contexte » est vide, une pastille bleue le signale sur le bouton **Réglages**, l'entrée **Mémoire** et la carte elle-même. Survolez-la pour lire à quoi il sert : quelques mots sur votre métier, vos outils et votre public, que MIA relit au début de chaque conversation pour vous répondre au plus juste.

## MIA remarque vos habitudes et vous propose d'en faire des préférences
<!-- id: memoire-gestes | genre: nouveaute | pour: tous -->

Vous redemandez souvent « plus court » ou « en tableau » après une réponse, vous passez vos brouillons d'email au vouvoiement, vous choisissez toujours « Plus formel » ? Au bout de trois fois, MIA vous propose d'en faire une habitude — pour vos emails, vos documents ou toutes vos réponses —, et rien ne change sans votre « oui ». Dans **Réglages → Mémoire**, chaque souvenir dit aussi à quoi il s'applique : partout, emails, documents et fichiers, ou code.

## Les pastilles mènent jusqu'à ce qui est concerné
<!-- id: pastilles-jusqu-a-l-element | genre: amelioration | pour: administrateurs -->

Une pastille de l'administration ne s'arrête plus au menu : sur la page, la carte, la ligne ou la section concernée (un outil, un modèle, un compte, un groupe, une base, un connecteur) porte la sienne, qui dit ce qui ne va pas pour lui seul, et « Régler → » du tableau de bord y mène directement. Le texte d'une pastille (points à régler, nouveautés, connexions à reconnecter) s'affiche désormais en info-bulle, au survol ou d'un toucher sur la pastille, jamais en texte toujours visible.

## La mise à jour des prix prend quelques secondes
<!-- id: mise-a-jour-prix-rapide | genre: amelioration | pour: administrateurs -->

« Mettre à jour coûts Internet (USD) », dans Coûts → Tarifs & crédits, ne cherche plus en ligne que ce que le catalogue de MIA ne tarife pas, en petits lots menés en même temps : quelques secondes au lieu de plusieurs minutes. Les outils sans prix unitaire (pages web, cartes, Gamma, n8n, modèles locaux) ne sont plus cherchés, et un modèle d'image facturé au jeton retrouve son calcul image par image. Elle fonctionne aussi avec beaucoup de modèles activés — au-delà d'une vingtaine, l'enregistrement échouait — et un échec dit désormais sa cause.

## À la voix : coupez la parole à MIA, et laissez-la se servir de vos connecteurs
<!-- id: conversation-vocale-fluide | genre: amelioration | pour: tous -->

En conversation vocale, la voix de MIA baisse dès que vous commencez à parler et « stop » est compris en une seconde environ — ce que vous dites dans la foulée (« stop, et pour demain ? ») part comme la question suivante ; la barre d'espace, Échap, un toucher sur le bandeau ou le bouton de votre casque l'interrompent aussi, sur-le-champ. Vos connecteurs reliés s'activent d'eux-mêmes quand votre demande en a besoin — le bandeau dit lesquels —, et « active Gmail » ou « coupe l'agenda » les règlent à la voix. Avant d'approuver une action ou d'envoyer un email, dites « lis-le » : MIA vous lit la carte.

## Votre modèle par défaut, d'une étoile
<!-- id: modele-par-defaut-personnel | genre: nouveaute | pour: tous -->

Dans le sélecteur de modèle, touchez l'étoile au bout d'une ligne : ce modèle ouvre désormais chacune de vos nouvelles conversations, sur tous vos appareils. Le même choix se fait dans Réglages → Préférences IA, et retoucher l'étoile revient au modèle de votre organisation ; un assistant garde toujours le sien.

## Réunions Outlook : invités, salle, Teams, répétition — et modification
<!-- id: outlook-reunions-invites | genre: amelioration | pour: tous -->

Avec le calendrier Outlook, MIA prépare désormais de vraies réunions : invités obligatoires ou facultatifs, **salle de réunion** réservée (par son nom, en vérifiant qu'elle est libre — *« quelles salles sont libres jeudi à 10 h ? »*), **réunion Teams**, répétition (chaque jour, chaque semaine, chaque mois…), rappel et disponibilité. Elle sait aussi **modifier** un évènement existant — le décaler, changer de salle ou de lieu, ajouter ou retirer un invité, le passer en Teams. Outlook écrit aux invités dès que vous approuvez : la carte les nomme, dit qui sera prévenu et si la salle est libre avant que rien ne parte — c'est ensuite la salle qui accepte ou refuse, d'elle-même.

## Réponses Outlook et fils Teams plus fiables
<!-- id: outlook-teams-fiabilite | genre: correctif | pour: tous -->

Une réponse préparée dans Outlook à un message en texte simple garde les sauts de ligne du message cité, et suit l'adresse de réponse indiquée par l'expéditeur ; « Améliorer » ne retire plus votre formule de fin. Dans Teams, après une réponse dans un fil, MIA continue dans le bon fil ; un fil dont le premier message a été supprimé reste lisible, et un message publié dans une conversation garde son lien.

## Les dates d'arrêt d'OpenAI relevées chaque jour
<!-- id: openai-dates-arret-relevees | genre: amelioration | pour: administrateurs -->

Avec une clé OpenAI, MIA demande chaque jour à OpenAI les dates d'arrêt qu'il annonce pour ses modèles. Un arrêt annoncé par courriel mais encore inconnu de MIA apparaît désormais dans **Modèles IA → Fournisseurs et modèles** — badge **Arrêt le …**, mention « Date relevée chez OpenAI » et point à régler — et le modèle quitte le chat à sa date, au lieu d'échouer à chaque message. Le panneau d'OpenAI dit quand les dates ont été relevées pour la dernière fois.

## OpenAI arrête o3 et GPT-5 le 11 décembre
<!-- id: openai-arret-decembre-2026 | genre: correctif | pour: administrateurs -->

OpenAI coupe le 11 décembre 2026 o3, o3-pro et la première génération de GPT-5 (GPT-5, mini, nano et pro). Dans **Modèles IA → Fournisseurs et modèles**, ceux que vous proposez affichent leur date et leur remplaçant, à activer dès maintenant — GPT-6.1 Sol et GPT-6 Luna coûtent moins cher que les remplaçants désignés par OpenAI. Un modèle activé que MIA ne propose plus, comme o4-mini, DeepSeek V3, Mistral Medium 3.1 ou Gemini 2.0 Flash, restait dans le chat et échouait à chaque message : il est désormais listé dans le panneau de son fournisseur, avec la mention « Hors du catalogue de MIA », pour être éteint, et il quitte le chat à sa date d'arrêt.

## Le bouton Stop arrête la réponse sur-le-champ
<!-- id: stop-immediat | genre: correctif | pour: tous -->

Cliquer **Arrêter la génération** semblait parfois sans effet : la réponse continuait de s'écrire plusieurs secondes, surtout quand MIA réfléchissait ou consultait une source. Désormais elle s'arrête au clic, marquée « Réponse interrompue », et le bouton d'envoi revient aussitôt ; c'est cette réponse, telle que vous l'avez vue, qui reste dans la conversation. Pour une image ou une page web, le bouton indique « Arrêt en cours… » le temps que la génération se termine.

## Conversation vocale : choisissez la voix qui lit les réponses
<!-- id: voix-de-lecture | genre: amelioration | pour: administrateurs -->

OpenAI arrête le 6 janvier 2027 la voix qui lit les réponses de la conversation vocale. Choisissez dès maintenant sa remplaçante sur la carte **Conversation vocale** de **Capacités → Outils** : **OpenAI Realtime**, celle qu'OpenAI désigne pour la suite, ou **Voxtral** de Mistral, facturée au texte lu ; chacune n'est retenue qu'après un essai réussi. Sans choix de votre part, la lecture passe d'elle-même sur OpenAI Realtime le 6 janvier 2027, et un point à régler vous le rappelle d'ici là.

## Claude Fable 5.2, Gemini 4 Argon, GPT-6.1 Terra et des tarifs corrigés
<!-- id: catalogue-llm-novembre-2026 | genre: nouveaute | pour: administrateurs | majeure: oui -->

Les derniers modèles s'activent dans **Modèles IA → Fournisseurs et modèles** : Claude Fable 5.2, plus capable et moins cher que Fable 5.1, Claude Haiku 5.5, le plus économique d'Anthropic, Gemini 4 Argon, le modèle de raisonnement de Google, et GPT-6.1 Terra ; côté images, GPT Image 2.6 et Nano Banana 2.1. Mistral Large 4 est désormais compté à son vrai tarif, deux fois moins cher qu'avant, la promotion de GPT-5.6 Sol court jusqu'au 30 novembre, et les recherches Internet d'OpenAI ne sont plus surcomptées. Les modèles que leur éditeur remplace (Gemini 3.7 et 3.5 Flash, bientôt Gemini 2.5, Mistral Large 3 et Medium 3.5, GPT Image 2) portent leur date d'arrêt et le nom de leur remplaçant.

## Une barre latérale plus claire, qui se replie
<!-- id: volet-repliable | genre: amelioration | pour: tous -->

Vos espaces deviennent de grandes tuiles colorées qui disent l'essentiel d'un coup d'œil : assistants et bases à votre disposition, tâches actives, workflows qui vous attendent. Espaces, Épinglées, Historique et chaque période de l'historique se replient d'un clic, ou tous ensemble avec le bouton **Tout replier** en haut de la barre ; repliés, les espaces restent une rangée d'icônes cliquables. La carte de votre compte, tout en bas, se replie aussi en une ligne : votre avatar, cerclé de vos crédits restants, et les boutons Réglages et Admin. Ce que vous repliez le reste, et vos choix suivent votre compte d'un appareil à l'autre.

## Votre historique d'usage, mois après mois
<!-- id: historique-des-usages | genre: nouveaute | pour: tous -->

La page **Usage & crédits** a un onglet **Historique** : toutes vos opérations depuis votre première utilisation, sur la période de votre choix (douze mois par défaut, ou vos propres dates). Filtrez par origine — conversations, workflows, tâches programmées, Claude dans Office —, par type d'usage (réponses, recherches Internet, images, pages web, voix…), par modèle d'IA ou par assistant, cherchez une conversation, un workflow ou un modèle par son nom, et exportez le résultat en CSV. Chaque opération rouvre sa conversation ou son exécution d'un clic.

## Les petites consommations ne se perdent plus dans « Autres »
<!-- id: petites-consommations-visibles | genre: correctif | pour: tous -->

Dans **Réglages → Usage & crédits**, la part **Autres** des camemberts détaille désormais ce qu'elle regroupe, avec les crédits de chacun : quelques demandes de **Claude dans Office** à côté de beaucoup de conversations y restent lisibles. Côté administration, la vue d'ensemble de **Consommation** nomme sous sa carte les catégories trop petites pour porter leur nom.

## Workflows : une conversation qui suit mieux, des fiches lisibles
<!-- id: workflow-recette-navigateur | genre: correctif | pour: createurs -->

Après un renvoi avec remarque, la validation suivante apparaît aussitôt dans la conversation, et une carte tranchée ou arrêtée ailleurs n'y garde plus ses boutons. Les Fiches remises par une étape s'affichent avec le nom de leurs champs, une étape confiée à un assistant ne crée plus d'image en plus de la brique prévue pour cela, et « Regénérer » n'est plus proposé dans la conversation d'un workflow, où il effaçait les messages des étapes.

## Les grandes nouveautés vous sont présentées
<!-- id: quoi-de-neuf-majeures | genre: nouveaute | pour: tous -->

Quand une fonctionnalité importante arrive — les workflows, Claude dans Office —, une fenêtre **Quoi de neuf** vous la présente à votre prochaine visite : une seule fois, et seulement si vous pouvez vous en servir, en deux phrases, un **schéma animé** qui la montre sur un exemple concret — un email reçu qui devient une réponse envoyée, une question posée à Claude dans un classeur Excel — et un bouton **Aller voir**. Une **pastille verte** reste ensuite sur l'endroit où la trouver (une tuile des **Espaces**, le bouton **Réglages**…) jusqu'à ce que vous l'ouvriez.

## Claude dans Excel, Word et PowerPoint, guidé pas à pas
<!-- id: claude-dans-office-pas-a-pas | genre: amelioration | pour: tous -->

Relier l'add-in Claude à Excel, Word ou PowerPoint se fait désormais en suivant un guide illustré : sur la carte **Claude dans Office** de **Réglages → Comptes connectés**, le bouton **Me guider pas à pas** ouvre l'assistant **MIA : mode d'emploi**, qui vous accompagne depuis le début, captures d'écran à l'appui. Il répond aussi en images quand vous lui demandez *« comment relier Excel à Claude ? »*.

## Claude dans Office, avec votre compte MIA
<!-- id: claude-dans-excel | genre: nouveaute | pour: tous | majeure: oui -->

L'add-in **Claude** passe par MIA dans **Excel, Word et PowerPoint** : plus de compte Claude à créer, ses demandes comptent dans vos crédits MIA à leur coût réel — leur propre catégorie dans **Usage & crédits** — et il propose les modèles Claude ouverts à votre compte. Votre organisation le déploie une fois pour tous avec la connexion Microsoft, en choisissant les applications qui le reçoivent ; sinon, générez votre jeton dans **Réglages → Comptes connectés** et collez-le dans l'add-in. Côté administration : **Capacités → Connecteurs → Claude dans Office**.

## Un écran de conversation plus clair
<!-- id: chat-fond-clair | genre: amelioration | pour: tous -->

En thème clair, l'écran de conversation prend le fond blanc de l'administration et de vos réglages, et la barre latérale le même gris très léger : on passe de l'un à l'autre sans changement de teinte.

## Les tableaux des réponses sont fermés en bas
<!-- id: tableaux-fermes-en-bas | genre: correctif | pour: tous -->

Un tableau rédigé par MIA s'affichait sans son dernier trait : la ligne du bas restait ouverte, comme coupée. Il est désormais encadré jusqu'en bas, dans la conversation comme dans le suivi des workflows.

## Un workflow se suit dans sa conversation
<!-- id: workflow-suivi-dans-la-conversation | genre: nouveaute | pour: createurs -->

Lancer un workflow vous amène dans **sa conversation** : un volet **Avancement** montre les étapes une à une, la voie prise par chaque condition et ce qui vous attend. Les décisions se prennent sans quitter la discussion — approuver, **renvoyer l'étape avec une remarque**, **choisir une autre voie** quand MIA hésite, **reprendre** après une erreur — et les réponses de chaque étape s'y ajoutent au fil de l'exécution. Le **Suivi détaillé** reste à un clic.

## Un workflow vérifie tout avant de démarrer
<!-- id: workflow-pre-controle | genre: amelioration | pour: createurs -->

Avant de lancer un workflow, MIA vérifie qu'il pourra aller au bout : un compte à relier (Gmail pour l'email de remise, Google Drive pour une étape…), un outil retiré, un modèle disparu. La fenêtre de lancement liste ce qui est **à régler avant de lancer**, avec un lien **Relier**, et rien ne part — ni temps ni crédits perdus. Un workflow programmé ou déclenché par un email ne démarre pas non plus, et vous prévient.

## Les versions d'un workflow se revoient
<!-- id: workflow-historique-versions | genre: nouveaute | pour: createurs -->

Chaque publication d'un workflow est gardée : le bouton **Versions** de l'éditeur les liste, montre le déroulé de chacune et permet d'en **faire repartir le brouillon**, sans toucher à la version en service tant que vous ne republiez pas. Le bouton **Lancements** ouvre, lui, l'historique des exécutions.

## Des documents de référence et ce que MIA sait de votre entreprise, dans les workflows
<!-- id: workflow-documents-reference | genre: nouveaute | pour: createurs -->

Un champ **Fichier** du départ peut porter un **document de référence** fourni d'office à chaque lancement — un modèle de réponse, un texte de loi, vos références — que les étapes citent avec @. Une condition ou une décision posée à MIA lit la présentation de votre entreprise et peut consulter les **bases de connaissances** de votre choix : « cet appel d'offres correspond-il à notre offre ? » trouve enfin de quoi répondre.

## Les conditions par règle disent ce qu'elles font
<!-- id: workflow-regles-claires | genre: correctif | pour: createurs -->

Trois lignes « Départ est égal à Oui », « … à Non », « … à Peut-être » réunies par « Toutes les conditions » ne pouvaient jamais être vraies : le workflow prenait toujours la voie « Si non », « Oui » saisi compris. Une phrase dit désormais quand la voie « Si oui » est prise, une règle contradictoire est signalée avant de publier, un champ Choix propose ses options et une condition accepte jusqu'à huit lignes. Le verdict « Oui » ou « Non » prend la couleur de la voie suivie, et « Lancé », « Fait », « Livré » passent en vert.

## Les PDF avec le logo de l'entreprise se téléchargent à nouveau
<!-- id: pdf-logo-demesure | genre: correctif | pour: tous -->

Un document téléchargé en PDF avec le logo de l'entreprise pouvait échouer sur « Une erreur interne est survenue », quel que soit son contenu, quand le logo était une très grande image. Le logo est maintenant mis à la bonne taille avant la mise en page : le PDF se crée, logo compris. Si un fichier ne peut pas être créé, la carte le dit et propose un autre format.

## Chaque conversation a son repère, et la recherche Internet se voit
<!-- id: repere-conversations-internet-visible | genre: amelioration | pour: tous -->

Dans la barre latérale, chaque conversation porte un repère à gauche de son titre — l'horloge d'une tâche, l'icône des workflows, la couleur de l'assistant ou un petit anneau — : les titres sont alignés et aucune ne se perd parmi les autres. La **Recherche Internet** apparaît à côté de vos connecteurs, dans la pastille des sources actives près du bouton +, et la couper ne pose plus de point orange sur le +.

## Workflows et tâches demandés dans la conversation : prêts tels que vous les décrivez
<!-- id: workflow-tache-depuis-chat-fiables | genre: correctif | pour: createurs -->

Un workflow décrit dans la conversation confiait parfois ses étapes à MIA au lieu de l'assistant demandé, restait en brouillon « déclenché par son adresse » alors qu'il devait se lancer à la main, ou laissait en clair un champ cité comme « Email client » : il arrive désormais avec les bons assistants, le bon départ, chaque champ relié, et programmé à votre heure plutôt qu'à celle du serveur. Une règle « montant supérieur à 0 » ne met plus de côté toutes les lignes d'un fichier Excel. Une tâche programmée « toutes les deux semaines » est maintenant comprise (MIA propose le rythme le plus proche), et une tâche qui prépare un brouillon d'email inclut votre messagerie dans ce qu'elle consulte.

## Un workflow démarre dès qu'un email arrive, sur les emails que vous choisissez
<!-- id: workflow-email-instantane | genre: nouveaute | pour: createurs -->

Un workflow qui démarre sur un email part dès l'arrivée du message quand votre organisation a activé les notifications de Gmail ou d'Outlook, sinon la boîte est vérifiée chaque minute. Le départ se règle d'une question, **Quand démarre-t-il ?** : **De**, **Objet contient** et **Avec une pièce jointe** sont à portée de main, **Plus de critères** ajoute **Sauf de**, **Adressés à** et **Contient les mots**, et une phrase dit en clair ce qui lancera le workflow. **Tester sur mes derniers emails** montre lesquels le lanceraient, et pourquoi les autres seraient écartés ; une pièce jointe envoyée par l'iPhone ou Apple Mail compte.

## Les champs à choix du départ d'un workflow fonctionnent
<!-- id: workflow-champ-choix | genre: correctif | pour: createurs -->

Dans l'éditeur d'un workflow, ajouter au départ un champ **Choix** faisait perdre ses options et figeait le volet dès qu'on passait à une autre brique. Les options sont maintenant gardées, et la valeur par défaut d'un champ Choix se choisit dans la liste de ses options.

## Une mémoire qui apprend, sous votre contrôle
<!-- id: memoire-geree-par-mia | genre: nouveaute | pour: tous -->

« MIA pour vous » devient la **Mémoire**, page **Réglages → Mémoire** : vos souvenirs par thème, à corriger ou retirer d'un clic. MIA **apprend d'elle-même** ce que vous dites de vous (votre métier, vos préférences) ; ce qu'elle déduit attend votre accord, et vous pouvez refuser. Faites aussi **corriger** ou **oublier** un souvenir en conversation (*« oublie que j'habite à Lyon »*). De temps en temps, une courte **question** vous demande ce que vous préférez : un clic, et c'est retenu.

## Images à fond transparent, au format de votre choix
<!-- id: images-fond-transparent-format | genre: nouveaute | pour: tous -->

Un outil image peut désormais produire des images à **fond transparent** — un logo, un pictogramme, un produit détouré —, enregistrées en PNG, ainsi qu'un **format du fichier** (PNG, JPEG ou WebP) et une **orientation** (carré, paysage, portrait). L'administrateur les règle dans la fiche de l'outil, un assistant peut les préciser pour ses propres images, et la brique de création d'image d'un workflow aussi. Seuls les réglages que le moteur d'image accepte sont proposés.

## Des réponses qui s'écrivent avec fluidité
<!-- id: reponses-fluides | genre: amelioration | pour: tous -->

Les réponses de MIA apparaissent désormais lettre à lettre, de façon continue, au lieu d'arriver par blocs, et elles commencent plus vite : votre message s'affiche à l'instant où vous l'envoyez, et le premier mot arrive sans le délai qui s'ajoutait à celui du modèle. Pendant que MIA réfléchit ou cherche, une petite animation géométrique aux couleurs de votre organisation accompagne ce qu'elle est en train de faire. Le fil suit le texte en glissant, sans à-coups.

## Version rapide ou version soignée, au choix avant l'écriture
<!-- id: version-soignee-avant-ecriture | genre: nouveaute | pour: tous -->

Quand vous demandez un PDF, un document Word ou une présentation, MIA vous propose d'abord deux boutons : **Version rapide**, prête tout de suite, ou **Version soignée**, mise en page par un modèle dont le nom et le coût estimé sont affichés. Choisie, la version soignée part dès que le contenu est rédigé : le document n'est fabriqué qu'une fois. Le bouton **Version soignée** existe aussi désormais sur la carte d'un PDF ou d'un document Word. Et si votre entreprise a déposé son modèle ou son logo, une ligne **Embarquer** vous laisse les garder ou non, avant de télécharger comme avant de lancer la version soignée — qui reçoit désormais le modèle en premier, puis vos fichiers et votre demande.

## Des PDF qui ne débordent plus
<!-- id: pdf-mise-en-page-propre | genre: correctif | pour: tous -->

Dans les PDF créés par MIA, le texte collait au bord de la feuille, le pied de page de l'entreprise n'apparaissait pas, les titres en gras perdaient certains caractères et les tableaux larges sortaient de la page. Les PDF ont maintenant de vraies marges, leur pied de page, et des tableaux dont chaque colonne prend la largeur de son contenu — en paysage au-delà de six colonnes.

## Les versions soignées rejoignent Mes créations
<!-- id: versions-soignees-dans-creations | genre: amelioration | pour: tous -->

Les présentations et classeurs réalisés par un modèle avec **Version soignée**, et les présentations Gamma, apparaissent désormais dans **Mes créations**, sous le type **Version soignée**. Un clic télécharge le fichier tant qu'il est disponible (30 jours) ; une présentation Gamma s'ouvre sur gamma.app, avec son export PDF ou PowerPoint.

## PDF jusqu'à 20 Mo et présentations PowerPoint jusqu'à 50 Mo
<!-- id: pdf-20-pptx-50 | genre: amelioration | pour: tous -->

Vous pouvez joindre à une conversation un PDF jusqu'à 20 Mo et une présentation PowerPoint (.pptx) jusqu'à 50 Mo ; les autres fichiers restent limités à 10 Mo. Un fichier trop lourd est refusé dès que vous le joignez, avec son poids, la limite qui s'applique, la raison de cette limite et la façon de réduire le fichier.

## Les PDF numérisés se joignent sans erreur
<!-- id: pdf-scanne-sans-erreur | genre: correctif | pour: tous -->

Un PDF fait de pages numérisées (un scan, une facture photographiée) faisait échouer l'envoi : la pièce jointe n'arrivait jamais dans la conversation ni dans une base de connaissances. Il se joint désormais comme les autres, quel que soit son nombre de pages.

## Des pages d'administration plus claires, sur ordinateur comme sur téléphone
<!-- id: admin-socle-commun | genre: amelioration | pour: administrateurs -->

Toutes les pages de l'administration et de vos réglages suivent désormais le même plan : une barre du haut qui dit où vous êtes, si vos modifications sont enregistrées et donne accès à la recherche, puis le titre de la page, sa phrase d'explication et ses boutons. En thème clair, le fond est blanc et les cadres sont nets ; sur téléphone, le bouton principal de la page reste sous le pouce et les listes s'affichent en cartes.

## Emails, anciens PowerPoint, archives ZIP et photos HEIC se lisent
<!-- id: emails-ppt-zip-heic | genre: nouveaute | pour: tous -->

Un email enregistré (.eml, .msg d'Outlook) se joint et se lit avec son objet, son expéditeur, ses destinataires, son texte et ses pièces jointes ; un email joint à un mail Gmail ou Outlook aussi. Les anciennes présentations PowerPoint (.ppt) se lisent diapositive par diapositive, notes comprises, et une archive ZIP montre son contenu puis MIA en lit les fichiers. Une photo HEIC d'iPhone ou de Mac, une image AVIF, TIFF ou BMP est convertie en JPEG à l'envoi.

## Tout fichier texte se lit, et la conversation accepte les mêmes formats que les bases
<!-- id: tout-fichier-texte | genre: amelioration | pour: tous -->

Un fichier dont le contenu est du texte se joint et se lit maintenant quelle que soit son extension : code, données, journaux, configuration, agenda, sous-titres… La conversation accepte aussi PowerPoint, OpenDocument, EPUB, pages web et RTF, comme les bases de connaissances ; les pièces jointes des emails, les fichiers Drive et les liens suivent la même règle. Un très long fichier texte n'est envoyé au modèle qu'en partie et MIA en lit la suite quand votre question l'exige ; un format non lu est refusé avec un message qui le nomme.

## Modèles IA : fournisseurs, clés et modèles sur une seule page
<!-- id: modeles-ia-une-page | genre: amelioration | pour: administrateurs -->
Les pages des clés et des modèles n'en font plus qu'une, **Fournisseurs et modèles** : une carte par fournisseur avec son logo et son état, la clé se colle directement dans sa carte, et ses modèles s'activent d'un interrupteur, coût par réponse à l'appui. En tête, un schéma montre qui répond par défaut et qui prend le relais en cas de panne.

## Workflows : une brique pour créer un fichier de tout format
<!-- id: workflow-brique-fichier | genre: nouveaute | pour: createurs -->

Dans l'éditeur d'un workflow, le groupe **Créer** propose **Un fichier** : une étape qui écrit un fichier du format de votre choix — script Python, Markdown, CSV, page HTML, requête SQL… ou tout autre fichier texte. Vous réglez son type, sa consigne, son assistant, ses sources et sa relecture comme pour une étape ; le fichier se télécharge tel quel et part en pièce jointe d'un email ou d'un message Slack.

## Les anciens fichiers Word et Excel (.doc, .xls) se lisent
<!-- id: anciens-doc-xls | genre: nouveaute | pour: tous -->

Un document Word ou un classeur Excel enregistré dans l'ancien format (.doc, .xls, Office 97-2003) se joint maintenant à un message, se dépose dans une base de connaissances ou parmi les documents d'un assistant : il était refusé avec « Type de fichier non supporté ». MIA en lit le texte, les tableaux et les notes de bas de page, chaque onglet d'un classeur avec ses dates, et aussi dans les pièces jointes d'un email ou un dossier synchronisé ; seul un fichier protégé par un mot de passe reste illisible.

## Une fenêtre des nouveautés repensée, avec tout l'historique
<!-- id: nouveautes-historique | genre: amelioration | pour: tous -->

Cliquer le numéro de version ouvre une fenêtre **Nouveautés** plus lisible : rangée par mois, filtrable entre **Nouveautés**, **Améliorations** et **Correctifs**, avec une recherche. Le bouton **Voir les nouveautés plus anciennes** déroule tout l'historique, et ce qui est arrivé depuis votre dernière visite porte **Nouveau pour vous**.

## La dictée peut passer par Voxtral, de Mistral
<!-- id: dictee-voxtral | genre: nouveaute | pour: administrateurs -->

Sur la carte **Dictée vocale** de la page **Outils**, l'administrateur choisit désormais le moteur de transcription : OpenAI, qui écrit au fil de la parole, ou **Voxtral** de Mistral, qui écrit chaque phrase dès que vous marquez une pause. Voxtral n'est proposé qu'avec une clé Mistral, et n'est retenu qu'après un essai réussi de cette clé. Pour les utilisateurs, rien ne change : le même bouton micro, le même texte à relire avant d'envoyer. La conversation à voix haute passe toujours par OpenAI : sans compte OpenAI relié, son bouton porte une pastille rouge et dit pourquoi, au lieu de disparaître.

## Un service relié s'allume tout seul dans le chat
<!-- id: connecteur-allume-a-la-connexion | genre: amelioration | pour: tous -->

Quand vous reliez un service dans **Réglages → Comptes connectés** — Google Drive, Gmail, Outlook, vos agendas, vos tâches, Teams ou Slack —, son interrupteur du menu **+** s'allume de lui-même : MIA peut s'en servir dès votre prochain message. Vous l'éteignez comme d'habitude dans le volet **Connecteurs**, et il reste éteint ; une nouvelle connexion le rallume.

## Des conseils pour une base de connaissances efficace
<!-- id: kb-conseils | genre: nouveaute | pour: createurs -->

La fiche d'une base explique, en quelques lignes, ce qui la rend utile à un assistant : un sujet par base, du vrai texte, une seule version de chaque document, des titres clairs. Et quand une base sert mal — des scans sans texte, presque rien à lire, deux versions d'un même document, surtout des tableaux —, une pastille « À améliorer » le signale sur sa carte et dit quoi changer.

## Les extraits d'un document, d'un clic
<!-- id: kb-extraits-document | genre: amelioration | pour: createurs -->

Sur la fiche d'une base, cliquez un document indexé : ses extraits s'ouvrent dans un volet, à droite, avec une recherche dans le document et le passage au document suivant. C'est ce que l'assistant lit vraiment quand il consulte la base.

## Les scripts .bat, .cmd et .ps1 se joignent enfin
<!-- id: scripts-joints-comme-texte | genre: correctif | pour: tous -->

Un script Windows (.bat, .cmd, .ps1, .vbs) ou Unix (.sh, .py…) se joint désormais à un message ou se dépose dans une base de connaissances : il était refusé avec « Type de fichier non supporté », et le bouton **Joindre des fichiers** ne le proposait pas. MIA le lit comme du texte, sans jamais l'exécuter, accents compris quand Windows l'a enregistré en « Unicode » ou en « ANSI ».

## La fiche d'une base de connaissances, en trois questions
<!-- id: kb-fiche-trois-questions | genre: amelioration | pour: createurs -->

Créer ou modifier une base de connaissances se fait désormais en trois questions : ce qu'elle contient, d'où viennent ses documents, qui s'en sert. Seules les sources configurées sont proposées, le détail de chaque réglage est dans le petit **ⓘ**, et qui s'en sert apparaît en pastilles. Chaque question se replie sur une ligne qui la résume — la fiche s'en souvient, et rouvre les documents à chaque problème nouveau —, et la liste des documents montre d'abord ceux à vérifier, cinq à la fois, avec filtres et recherche.

## Une base de connaissances réservée à vous seul
<!-- id: base-uniquement-pour-moi | genre: nouveaute | pour: createurs -->

Le créateur d'une base peut la réserver à lui seul : en la rendant **Privée**, dans « Qui la voit » sur sa fiche, personne d'autre — pas même un administrateur — ne la voit, ne la coche dans le chat, ne la partage ni ne l'interroge, même à travers un assistant que d'autres ouvrent. Lui seul peut lever la réserve ; la poser retire les partages accordés.

## La version soignée se suit dans une carte, et le fichier y arrive
<!-- id: version-soignee-carte-de-realisation | genre: amelioration | pour: tous -->

Quand un modèle réalise la version soignée d'une présentation ou d'un classeur, une carte suit son travail dans la conversation : le modèle à l'œuvre, le temps écoulé, l'étape en cours et une barre d'avancement, avec un bouton **Arrêter**. Le fichier arrive dans la même carte, devenue **Présentation prête** ou **Classeur prêt**, avec un bouton **Télécharger** ; on peut continuer à discuter ou recharger la page entre-temps.

## Des comptes de démonstration, avec une enveloppe de crédits
<!-- id: comptes-demo | genre: nouveaute | pour: administrateurs -->

Un administrateur peut créer un compte de démonstration pour faire découvrir MIA : une enveloppe de crédits (5 000 par défaut, modifiable) utilisable une seule fois, sans renouvellement mensuel. Une fois les crédits épuisés, MIA cesse de répondre, la personne garde ses conversations et un message l'invite à prendre contact ; Personnes → Comptes signale les démos épuisées, et un clic convertit le compte en compte normal, historique conservé.

## Workflows : la brique Slack publie ou lit, l'un ou l'autre
<!-- id: workflows-brique-slack | genre: nouveaute | pour: createurs -->

La brique Slack d'un workflow s'ouvre sur un choix : publier un message ou lire des messages, chacun avec ses propres réglages. Elle publie dans un canal ou directement à une personne, avec des images ou des documents du dossier en pièces jointes ; elle lit un canal, une conversation ou une recherche sur une période, et en tire une pièce. Dans une conversation aussi, MIA peut maintenant écrire à une personne sur Slack : reliez à nouveau votre Slack pour l'autoriser.

## Workflows : leurs conversations se repèrent dans l'historique
<!-- id: workflows-icone-historique | genre: amelioration | pour: createurs -->

Dans l'historique de la barre latérale, la conversation d'une exécution de workflow porte désormais l'icône des workflows à gauche de son titre, comme l'horloge d'une tâche programmée.

## Workflows : plus de fausse alerte sur les étapes confiées à un assistant
<!-- id: workflows-alerte-etape-assistant | genre: correctif | pour: createurs -->

Une étape confiée à un assistant, ou qui consulte Internet, n'affiche plus « aucun outil n'est relié » dans **À régler** : l'assistant travaille avec ses propres bases, documents et outils. L'alerte ne reste que pour une étape qui n'a vraiment rien pour travailler.

## Workflows : une étape peut remettre une image, une page web ou une carte
<!-- id: workflows-etape-image | genre: nouveaute | pour: createurs -->

Dans **Remet au dossier**, une étape propose désormais **Image**, **Page web** et **Carte**, à côté du texte et des documents — selon les outils de création que vos groupes vous ouvrent ; une forme sans outil en service est grisée avec sa raison. L'étape prépare la demande avec son assistant, ses sources et ses bases, puis l'outil choisi fabrique la pièce : une affiche aux couleurs de la marque, la page d'un produit rédigée d'après vos documents. Le reste suit comme pour une création : relecture, dossier, livraisons, **Mes créations**, et MIA sait le proposer quand elle compose un workflow.

## Workflows : un canal Slack introuvable est signalé avant de publier
<!-- id: workflows-canal-slack-verifie | genre: correctif | pour: createurs -->

Un workflow pouvait finir en échec sur « canal Slack introuvable » alors que son message était bien publié : une action l'avait publié, et la livraison du livrable visait un canal qui n'existe pas. Le canal d'une livraison est désormais cherché avant de publier, l'erreur dit que c'est la livraison et comment la corriger, **À régler** refuse un canal qui n'en est pas un (« Canal Slack ») et signale qu'une action publie déjà dans Slack. « Décrire à MIA » ne remplit plus un canal que vous n'avez pas donné.

## Workflows : ce que coûte une exécution, expliqué
<!-- id: workflows-cout-explique | genre: amelioration | pour: createurs -->

Le coût estimé d'un workflow se détaille désormais brique par brique, avec ce sur quoi chaque chiffre repose, et montre ce qu'ont réellement coûté ses dernières exécutions. Il s'appuie sur les étapes de workflow déjà menées, plus longues qu'une réponse de chat, et compte la rédaction d'une demande d'image. Après coup, **Réglages → Usage & crédits** et, pour les administrateurs, **Consommation** donnent ce que chaque workflow a consommé.

## Workflows : l'email contient vraiment les pièces citées
<!-- id: workflows-pieces-dans-email | genre: correctif | pour: createurs -->

Une action « Préparer ou envoyer un email » qui devait contenir une pièce du dossier n'en reprenait que le nom, et l'image citée n'apparaissait pas. Désormais la pièce figure telle quelle, dans la mise en forme de chaque destination (email, message Slack, tâche, évènement, document), l'image s'affiche dans le corps du message, et une pièce rédigée à partir d'autres pièces arrive complète, avec le contenu de celles qu'elle cite. Par sécurité, un texte venu de l'extérieur, comme un email reçu, n'est jamais complété ainsi.

## Workflows : relire ce qui a circulé d'une étape à l'autre
<!-- id: workflows-contenu-relu | genre: amelioration | pour: createurs -->

Dans l'historique des exécutions, chaque pièce se déplie désormais en entier — texte, tableau, Fiche, fichier reçu, image, page web — et chaque action montre ce qu'elle a porté : **Voir l'email** affiche les destinataires, l'objet et le message tel qu'il est parti, ses pièces jointes, et dit s'il a été **envoyé** ou seulement rangé en **brouillon**. La page d'une exécution montre de même, brique par brique, les emails écrits, les messages publiés et les livraisons.

## OneDrive sans inscription Azure de plus
<!-- id: onedrive-application-de-connexion | genre: amelioration | pour: administrateurs -->

OneDrive peut désormais passer par l'application Microsoft de la connexion, comme Outlook : il suffit d'y ajouter la permission Files.Read.All, listée sur Organisation → Authentification. L'inscription dédiée de Capacités → Sources synchronisées devient facultative, et la carte OneDrive dit en tête quelle application sert. Quand Microsoft refuse une liaison, y compris depuis l'application mobile, l'écran en donne la cause (secret expiré, consentement manquant…) au lieu d'un simple échec.

## L'administration dans votre langue
<!-- id: administration-en-anglais | genre: amelioration | pour: administrateurs -->

Avec l'anglais choisi, toute l'administration s'affiche en anglais : menu, tableau de bord, Comptes, Groupes, Rôles, Modèles IA, Outils, Connecteurs, Budget, Consommation, Tarifs & crédits, Entreprise, Marque & apparence, Authentification, Diagnostic, Journaux, Sauvegarde et Élagage, avec leurs messages et les points à régler.

## MIA parle la langue que vous avez choisie
<!-- id: langue-choisie-partout | genre: amelioration | pour: tous -->

Avec l'anglais choisi (sélecteur de langue en haut à droite, ou Réglages → Mon profil), MIA répond en anglais par défaut, titre vos conversations en anglais et prépare en anglais les tâches programmées, les workflows et les assistants qu'elle vous propose — sauf si vous lui écrivez ou lui demandez une autre langue. Les pages Assistants et Bases de connaissances, leurs fiches et leurs modèles de prompt passent elles aussi en anglais.

## Outlook, calendrier Outlook, To Do et OneDrive se relient sans être administrateur
<!-- id: microsoft-sans-consentement-force | genre: correctif | pour: tous -->

Dans une organisation où seuls les administrateurs peuvent consentir aux applications, relier Outlook, le calendrier Outlook, Microsoft To Do ou OneDrive affichait « Approbation de l'administrateur requise », même après le consentement donné pour toute l'organisation. Une fois ce consentement accordé, chacun relie désormais ces services depuis Réglages → Comptes connectés, sans écran d'autorisation.

## Assistants et bases de connaissances de retour sur téléphone
<!-- id: telephone-boutons-assistants-bases | genre: correctif | pour: tous -->

Sur téléphone, dès qu'un connecteur ou un outil était allumé, les boutons des assistants et des bases de connaissances sortaient de la barre sous le champ de saisie, sans aucun moyen de les atteindre. Ils restent désormais toujours visibles : les connecteurs allumés se comptent sur le bouton « + », et la pastille de l'outil actif se place juste après ces boutons.

## Workflows : des boucles pour chaque ligne, chaque pièce jointe, chaque fichier
<!-- id: workflows-boucles-intelligentes | genre: nouveaute | pour: createurs -->

Une boucle de workflow parcourt ce que vous lui donnez : chaque ligne d'un Excel, chaque pièce jointe d'un email, chaque slide, page ou section, chaque fichier d'un dossier Drive, chaque email d'une recherche, ou chaque sujet repéré par MIA. Une règle garde les éléments utiles, la fenêtre de lancement compte les tours, un élément en échec n'arrête plus les autres et se refait seul ; les actions de chaque tour se relisent ensemble à la fin. Un guide pas à pas explique la mise en place d'un workflow.

## Des journaux qui disent ce qui ne va pas
<!-- id: journaux-surveillance | genre: amelioration | pour: administrateurs -->

**Maintenance → Diagnostic → Journaux** devient un tableau de bord : un verdict en tête, les pannes regroupées (une erreur répétée mille fois tient en une ligne, avec sa courbe, sa fréquence et la mention « Nouveau »), une frise par gravité où toucher une barre zoome sur le créneau, et un suivi en direct. Les erreurs internes et les avertissements, jusqu'ici rangés dans « Autres », sont classés par gravité et par domaine ; un identifiant d'incident collé dans la recherche ouvre la panne avec sa pile d'appels et sa cause, et les heures suivent votre fuseau horaire.

## Les dépenses de l'IA sur la période de votre choix
<!-- id: budget-depenses-periode | genre: amelioration | pour: administrateurs -->

Dans **Coûts → Budget**, la section **Dépenses** montre ce que l'IA a coûté sur une période toute prête — mois en cours, mois dernier, 7 ou 30 jours, 12 mois, cette année, depuis le début — ou entre deux dates de votre choix : le total, la moyenne par jour, l'évolution face à la période précédente, un histogramme par jour, semaine ou mois avec la part du budget, les catégories et les fournisseurs qui coûtent. Les mêmes tranches et la plage personnalisée servent aussi dans **Coûts → Consommation**.

## Changer de photo de profil, sans détour
<!-- id: photo-profil-url-ou-fichier | genre: correctif | pour: tous -->

Dans **Mon profil**, choisir une nouvelle image ne faisait rien tant qu'une photo était déjà enregistrée : il fallait d'abord vider le champ d'adresse. Désormais un fichier choisi ou une adresse d'image collée changent la photo aussitôt, et **Retirer la photo** revient à vos initiales. Une image collée par son adresse est copiée dans MIA : elle ne disparaît plus si le site d'origine la retire, et la photo de votre compte Google ne la remplace plus à la connexion.

## Un menu « + » plus court et plus clair
<!-- id: menu-plus-volet | genre: amelioration | pour: tous -->

Le menu « + » tient sur une ligne par entrée, sans défilement : Joindre des fichiers, Créer (avec le coût de chaque outil en crédits) et Sources. Vos connecteurs vivent dans un volet « Connecteurs », replié par défaut, qui montre d'un coup d'œil lesquels sont allumés ; menu fermé, leurs logos s'affichent à côté du « + » (sur téléphone, leur nombre s'affiche sur le « + »). Les services pas encore reliés tiennent en une seule ligne « Relier ».

## Microsoft Teams dans la conversation
<!-- id: connecteur-microsoft-teams | genre: nouveaute | pour: tous -->

Reliez votre Teams dans **Réglages → Comptes connectés**, puis allumez **Messages Teams** dans le menu **+** : l'assistant cherche et lit vos conversations et les canaux de vos équipes (« qu'a dit Marie sur le budget ? »), et publie un message en votre nom après votre accord — dans une conversation, un canal ou le fil d'un message. Un administrateur de votre organisation doit d'abord autoriser MIA dans Microsoft.

## Le coût de chaque fournisseur, mieux relevé
<!-- id: couts-reels-fournisseurs | genre: correctif | pour: administrateurs -->

Certaines consommations échappaient au décompte : les réponses de Kimi, celles arrêtées avec le bouton Stop, les pages lues par Claude et Mistral pendant une recherche Internet, et les recherches de Mistral, qui apparaissent désormais dans Consommation (sans tarif publié, leur prix se saisit dans Tarifs & crédits). Les très longues requêtes suivent le tarif majoré d'OpenAI et de Gemini Pro, la session de code de la « Version soignée » par GPT est comptée et la lecture de cache de Claude Sonnet 5.5 suit son vrai prix. Une réponse appuyée sur Internet n'est plus arrondie deux fois.

## Des coûts en crédits réalistes, partout
<!-- id: couts-credits-realistes | genre: amelioration | pour: tous -->

Le sélecteur de modèle et le menu **+** annoncent le coût d'une réponse ou d'une génération (« ≈ 35 crédits par réponse »), calculé comme il sera décompté, sur la taille réelle des réponses récentes. L'étiquette sous une réponse compte tout le tour — titre, mémoire, recherche dans les bases — et reste la même au rechargement ; la ligne au-dessus du champ suit les crédits utilisés dans la conversation. Onze modèles OpenAI (o3, GPT-4o, GPT-5.4…) comptaient 1 crédit par réponse faute de tarif : ils suivent désormais celui d'OpenAI.

## Tarifs & crédits montre le tarif vraiment appliqué
<!-- id: tarifs-appliques | genre: correctif | pour: administrateurs -->

Une ligne sans prix saisi affichait « 0 € → 1 crédit » alors que la facturation lui appliquait le tarif public du modèle : elle montre désormais ce tarif (« Tarif public appliqué »), et les crédits par unité, le simulateur et le rapport PDF calculent avec lui. Le coût estimé d'un workflow tient compte du modèle de chaque étape et de ses recherches Internet, au lieu d'une moyenne de tous les modèles.

## Une suite proposée après chaque réponse
<!-- id: suggestion-de-suite | genre: nouveaute | pour: tous -->

Après une réponse de MIA, la zone de saisie vous propose en grisé la suite logique de l'échange — « Oui, prépare le brouillon pour Marie », « Compare avec le devis de l'an dernier »… Appuyez sur **Tab** pour la reprendre (au doigt : **Utiliser**), modifiez-la si besoin, puis envoyez : rien ne part sans vous. Les idées d'utilisation, elles, ne défilent plus que dans une conversation vide.

## Des menus repensés : vos espaces, vos réglages, l'administration
<!-- id: menus-centre-de-reglages | genre: amelioration | pour: tous -->

La barre latérale range ce que vous faites avec MIA en tuiles **Espaces** — Assistants, Bases, Tâches, Workflows, Créations, selon vos droits —, chaque page n'a plus qu'une entrée, et **Rechercher ou aller à…** (⌘K, ou Ctrl+K) mène en quelques lettres à une page, une action ou une conversation. En bas, deux vrais boutons mènent droit à leurs pages, menus à gauche : **Réglages** pour votre compte (dont **Comptes connectés** et **Usage & crédits**) et, pour qui administre, **Admin**, avec ses six rubriques et le nombre de points à régler. La barre latérale garde la même largeur partout ; sur téléphone, l'avatar en haut à droite ouvre vos réglages.

## Le bandeau du haut se fait discret
<!-- id: bandeau-replie | genre: amelioration | pour: tous -->

Au-dessus de la conversation, le bandeau qui détaille le modèle (Internet, images, fichiers, connecteurs, historique) est désormais replié par défaut : il ne montre que le modèle choisi, et laisse la place aux messages. La flèche à sa droite le déplie ; si vous le préférez ouvert, MIA s'en souvient sur cet appareil.

## Workflows : l'historique des exécutions
<!-- id: workflows-historique | genre: nouveaute | pour: createurs -->

L'onglet **Historique des exécutions** de la page Workflows rassemble tous vos lancements, rangés par jour, filtrables par workflow et par état, chacun avec ce qu'il traitait (le client saisi, l'objet de l'email reçu…) ; le bouton **Historique** d'un workflow y mène directement. Choisir un lancement ouvre son journal à côté : ce qu'il a remis, sa durée et son coût, puis, brique par brique, ce qu'elle a fait, lu et remis, les actions proposées et leur sort, les erreurs et les reprises. De là, on décide, on reprend ou on suit l'exécution, et **Relancer** la rejoue avec les mêmes données.

## Sur téléphone, une zone de saisie plus grande
<!-- id: saisie-mobile-plus-grande | genre: amelioration | pour: tous -->

Sur téléphone, la zone de saisie montre deux lignes au repos — trois sur l'écran d'accueil — et grandit jusqu'à près de la moitié de l'écran quand vous écrivez une longue demande : on se relit sans faire défiler une fente d'une ligne.

## Des idées d'utilisation dans la zone de saisie
<!-- id: idees-zone-de-saisie | genre: amelioration | pour: tous -->

Tant que la zone de saisie est vide, son texte d'aide laisse place, en fondu, à des idées de demandes pour votre travail : résumer un document, préparer un tableau Excel, répondre au dernier email d'un client, trouver un créneau… Elles ne citent que ce que vous pouvez réellement faire — vos connecteurs reliés, les outils qui vous sont ouverts, les tâches programmées et workflows si votre rôle le permet — et disparaissent dès la première lettre. Elles ne servent qu'à démarrer : dès le premier message, le champ ne montre plus que son texte d'aide, ou la suite proposée par MIA.

## MIA vous propose des choix en boutons
<!-- id: choix-en-boutons | genre: nouveaute | pour: tous -->

Quand la suite dépend d'une décision de votre part — une piste parmi plusieurs, un ton, un format, la prochaine étape —, MIA termine sa réponse par des boutons : un clic envoie l'option comme votre réponse, sans rien retaper. Les choix multiples se cochent puis s'envoient ensemble, et vous pouvez toujours écrire autre chose à la place. MIA ne le fait que lorsque c'est utile, avec tous les modèles.

## Assistants, tâches, workflows et bases : la barre latérale reste celle du chat
<!-- id: volet-chat-espaces-createur | genre: amelioration | pour: createurs -->

Depuis une conversation, **Assistants**, **Tâches programmées**, **Workflows** et **Bases de connaissances** s'ouvrent sans changer de barre latérale : vos conversations, la recherche et **Nouvelle conversation** restent sous la main, et le raccourci de la page ouverte est mis en évidence. Atteintes par le menu **Paramètres** ou l'**Administration**, ces pages gardent le menu de ces sections, comme avant.

## Workflows : demandé dans le chat, prêt à servir
<!-- id: workflows-chat-complet | genre: amelioration | pour: createurs -->

Un workflow que MIA vous propose dans une conversation est réglé au plus complet : votre messagerie et votre agenda, vos assistants, vos bases, vos outils de création, les validations et la livraison du résultat. **Approuver** le crée et ouvre aussitôt son éditeur pour l'ajuster, avec le retour à la conversation à un clic. S'il ne démarre qu'à la demande et que rien n'est à régler, il est déjà publié, prêt à lancer ; programmé ou lancé par un évènement, il attend que vous le publiiez.

## Rôles : une colonne « Gérer », et l'aide au survol
<!-- id: roles-gerer-infobulles | genre: amelioration | pour: administrateurs -->

L'écran **Personnes → Rôles** gagne une colonne **Gérer** : sur les assistants et les bases de connaissances, elle ouvre ceux de toute l'organisation — les voir tous, les modifier, les partager, en changer la portée. Il s'ouvre directement sur le détail d'un rôle, plus compact : ce que veut dire une colonne, ce que couvre une ligne et ce qu'ouvre une case s'affichent dans une bulle, au survol, au clavier ou d'un toucher. « Voir » dit désormais simplement si la fonctionnalité apparaît pour la personne.

## Workflows : neuf modèles qui montrent tout ce qu'un workflow sait faire
<!-- id: workflows-modeles-vitrine | genre: amelioration | pour: createurs -->

Les modèles de l'onglet **Modèles** ont été refaits : neuf usages réels — trier les demandes reçues par email, répondre à un appel d'offres, faire d'une réunion un compte rendu et des tâches, préparer ses rendez-vous du jour, traiter une facture déposée dans Drive, tenir une veille, lancer un évènement avec affiche et page d'invitation, accueillir un collaborateur, répondre à une demande de démo venue du site. Chaque vignette montre son déroulé, quand il démarre, ce qu'il produit et les briques qu'il emploie. En l'utilisant, le modèle prend votre messagerie, votre agenda et vos outils de création.

## Workflows : une couverture par workflow, et toute la vignette s'ouvre
<!-- id: workflows-vignettes-couverture | genre: amelioration | pour: createurs -->

Dans **Mes workflows**, chaque vignette porte une couverture à la couleur du workflow où son déroulé est tracé comme une ligne de métro, avec, dessous, quand il démarre et ce qu'il produit. Une exécution en cours ou qui vous attend s'y voit d'un coup d'œil : sa pastille le dit et l'étape concernée est cerclée sur la ligne. Toucher la vignette n'importe où ouvre le workflow ; la pastille ouvre l'exécution, et **Lancer** reste à portée de main.

## Workflows : les embranchements se lisent comme un arbre
<!-- id: workflows-embranchements-arbre | genre: amelioration | pour: createurs -->

Sous une condition ou une décision de MIA, les voies s'ouvrent côte à côte, en colonnes de même largeur, chacune de sa couleur et avec son étiquette (Si oui, Si non, ou le numéro et le nom de la voie). Des liaisons partent de la question, descendent chaque voie et se rejoignent sur un point avant la suite ; une voie qui arrête le workflow ne revient pas. Pendant une exécution, la voie prise est soulignée et les autres s'estompent.

## Workflows : arrêter proprement au bout d'une voie
<!-- id: workflows-brique-arret | genre: nouveaute | pour: createurs -->

Une nouvelle brique **Arrêter le workflow** termine une voie de condition ou de décision là où elle en est : l'exécution s'affiche « Terminée » (ou « Arrêtée », au choix), avec un mot de la fin facultatif dans la conversation, sans livrable ni livraison. Et toucher une pièce sur le rail ne laisse plus le reste grisé quand on referme le volet ou qu'on change de brique.

## La conversation d'un workflow s'affiche sans astérisques
<!-- id: workflows-messages-texte-simple | genre: correctif | pour: createurs -->

Dans la conversation d'une exécution, le message de lancement et ceux des étapes et des créations montraient des astérisques autour de leur titre et des champs de départ. Ils s'écrivent désormais en texte simple, comme si vous les aviez tapés : le nom du workflow, son objectif et les valeurs saisies au départ, puis pour chaque étape son titre et sa consigne.

## Workflows : créer des images, des pages web et des cartes en route
<!-- id: workflows-creations | genre: nouveaute | pour: createurs -->

Une nouvelle brique **Création** fait travailler vos outils de création — image, page web, carte d'itinéraire — à partir des pièces du dossier : une illustration pour l'article tout juste rédigé, une page qui présente l'offre, l'itinéraire de la tournée. Ce qu'elle produit devient une pièce : l'étape suivante voit l'image, l'email du livrable la joint, une base de connaissances l'accueille. Relue par vous, elle se refait d'après votre remarque, à partir de sa version précédente.

## Des rôles sur mesure : qui peut voir, utiliser, créer, modifier, supprimer
<!-- id: roles-et-droits | genre: nouveaute | pour: administrateurs -->

Nouvel écran **Personnes → Rôles** : pour chaque rôle, cochez ce qu'il permet de voir, utiliser, créer, modifier et supprimer — assistants, bases, tâches programmées, workflows, outils, connecteurs, et chaque écran de l'administration. « Voir » fait apparaître une fonctionnalité, « Utiliser » permet de s'en servir, dans la conversation comme ailleurs : une bulle le rappelle sur chaque colonne. Utilisateur, Créateur et Administrateur restent proposés avec les droits de toujours ; créez à côté vos propres rôles, comme un responsable des comptes ou un support en lecture seule — les rôles et les sauvegardes restant réservés aux administrateurs.

## Workflows : MIA choisit la voie, et vos filtres cadrent ce qu'elle voit
<!-- id: workflows-decision-filtres | genre: nouveaute | pour: createurs -->

Une nouvelle brique **Décision de MIA** lit une pièce, suit vos critères et choisit une suite parmi deux à six scénarios que vous décrivez — devis, réclamation, simple question… ; si elle hésite, c'est vous qui tranchez. Les étapes et actions qui touchent à vos emails ou à Google Drive se filtrent (expéditeur, objet, pièce jointe, période, dossier, nature de fichier, destinataires permis), et le filtre s'applique aux recherches elles-mêmes. L'éditeur ne montre plus que vos choix, repliés sur une ligne ; « @ » cite une pièce du dossier dans tous les champs, chaque nature de pièce a sa couleur, et des paquets animés montrent ce qui passe d'une étape à l'autre.

## Workflows : boucles, déclencheurs, livraisons et reprise
<!-- id: workflows-automatisation | genre: nouveaute | pour: createurs -->

Un workflow peut maintenant démarrer tout seul — à l'arrivée d'un email ou d'un fichier dans Drive, à l'appel de son adresse par un autre logiciel, à la fin d'un autre workflow —, répéter des étapes pour chaque élément d'une liste ou jusqu'à un bon résultat, attendre une date et vous prévenir en route. Le livrable part par email avec ses pièces jointes, dans Drive, Slack ou une base de connaissances. Une panne passagère est réessayée seule, une brique facultative peut être passée, et une exécution arrêtée se reprend là où elle en était, avec la dernière version du workflow si vous l'avez corrigé.

## Les workflows : un travail en plusieurs étapes, mené toujours de la même façon
<!-- id: workflows | genre: nouveaute | pour: createurs | majeure: oui -->

Nouvelle entrée **Workflows** dans la barre latérale, en bêta : décrivez un processus à MIA (ou partez d'un modèle), elle le dessine en étapes confiées à MIA ou à vos assistants, qui se passent des pièces — fiche, tableau, document au modèle de l'entreprise — jusqu'au livrable. Ajoutez conditions, validations (relire, corriger, renvoyer avec une remarque) et actions dans vos outils ; lancez-le à la main, depuis le chat avec « @ » ou à heure fixe. Une ligne par étape, le détail dans un volet.

## Les modèles bientôt arrêtés par leur éditeur sont signalés
<!-- id: modeles-arretes-editeur | genre: amelioration | pour: administrateurs -->

Dans Modèles IA → Modèles, un modèle dont l'éditeur annonce l'arrêt affiche sa date et le remplaçant à activer, et une pastille le rappelle tant qu'il est proposé : c'est le cas de GPT-4o, GPT-4o mini, GPT-4.1 et GPT-4.1 mini, arrêtés par OpenAI le 14 janvier 2027 au profit de GPT-6 Luna. Le jour de l'arrêt, MIA le retire du chat, et les conversations et assistants qui l'utilisaient passent sur le modèle par défaut au lieu d'échouer à chaque message.

## Une seule façon de créer une présentation, et ses slides se retouchent
<!-- id: presentation-modifier-les-slides | genre: amelioration | pour: tous -->

Une présentation se demande désormais dans la conversation, et sa carte porte un bouton « Modifier les slides » : la présentation s'ouvre dans l'éditeur, à droite, pour déplacer, réécrire, ajouter des illustrations et exporter. L'outil Présentation du menu « + », qui faisait doublon, disparaît ; les présentations qu'il a créées restent ouvrables dans leurs conversations.

## Une version soignée de vos présentations et classeurs
<!-- id: presentation-realisee-par-le-modele | genre: nouveaute | pour: tous -->

La carte d'une présentation PowerPoint ou d'un classeur Excel porte un bouton « Version soignée » : un modèle compose lui-même le fichier, à partir de votre demande, des fichiers joints à la conversation et, pour une présentation, du modèle PowerPoint de l'entreprise — ou Gamma s'en charge, quand votre organisation l'utilise. Avant de lancer, une fenêtre montre le modèle retenu (celui de la conversation s'il en est capable, sinon un modèle choisi pour vous, jamais un haut de gamme d'office), ce qui part chez son éditeur — chaque fichier se décoche —, une précision facultative pour la mise en page et le coût estimé. Une à trois minutes, compté dans vos crédits ; le fichier est gardé 30 jours.

## Des présentations plus soignées, et un modèle d'entreprise expliqué
<!-- id: presentations-style-aplats | genre: amelioration | pour: tous -->

Les présentations PowerPoint livrées dans la conversation changent de visage : couverture et conclusion sur fond sombre, intercalaires numérotés, points clés en cartes, chiffres sur un bandeau, étapes en pastilles reliées — aux couleurs de votre organisation, et dans le PDF comme dans PowerPoint. Quand l'entreprise a déposé son modèle, la carte de la présentation l'annonce, et la page Organisation → Entreprise montre, slide par slide, la mise en page du modèle que MIA remplit.

## Votre page Usage dit si votre quota tiendra
<!-- id: usage-perso-solde | genre: amelioration | pour: tous -->

Dans Réglages → Usage & crédits, votre solde de crédits s'accompagne d'une prévision : votre quota tiendra-t-il jusqu'à la fin du mois, et sinon vers quelle date il sera atteint, avec ce que vous pouvez encore utiliser chaque jour. Trois camemberts montrent ce qui consomme vos crédits — par usage, par assistant, par modèle —, avec le coût moyen d'une réponse ou d'une image ; le top 10 de vos opérations les plus coûteuses rouvre leur conversation d'un clic, et quelques conseils tirés de votre usage aident à faire durer vos crédits.

## La page Usages montre ce que MIA fait
<!-- id: usages-vue-ensemble | genre: amelioration | pour: administrateurs -->

Dans Coûts → Consommation, la nouvelle Vue d'ensemble montre la part de chaque usage, combien de personnes se servent de chaque fonctionnalité et comment cela évolue, la rapidité des modèles et la part de leurs réponses relancées, les assistants et les bases de connaissances qui servent ou dorment. Les indicateurs du haut parlent d'usage et de qualité ; les montants en euros restent sur la page Budget.

## Votre présentation mise en forme par Gamma
<!-- id: presentation-chat-gamma | genre: nouveaute | pour: tous -->

Quand votre organisation utilise Gamma, le bouton « Version soignée » d'une présentation PowerPoint livrée dans le chat propose « Par Gamma » : la présentation part chez gamma.app, qui la met en page — thème, illustrations — sans réécrire votre texte, et le lien revient dans la conversation au bout d'une à trois minutes, avec le fichier PowerPoint. Chaque création consomme des crédits Gamma de l'organisation : une confirmation vous est demandée avant l'envoi.

## La page Budget montre où va le mois
<!-- id: budget-trajectoire | genre: amelioration | pour: administrateurs -->

Dans Coûts → Budget, une jauge, le rythme de dépense et la courbe du mois jour par jour, prolongée jusqu'au 31 et comparée au mois précédent, montrent d'un coup d'œil si le plafond sera atteint, et quand. Un histogramme donne la dépense de chaque jour et repère ceux qui ont dépassé leur part du budget. La page signale un rythme trop rapide avant même le seuil d'alerte, donne ce qu'on peut encore dépenser par jour pour tenir, ce que coûtent environ 1 000 crédits, et classe les dépenses par catégorie. Les règles et la rallonge s'ouvrent d'un clic depuis leur résumé.

## Le vocabulaire de l'entreprise n'envoie que ce qui sert
<!-- id: vocabulaire-entreprise-selectif | genre: amelioration | pour: administrateurs -->

Le vocabulaire commun devient le vocabulaire de l'entreprise et rejoint la page Organisation → Entreprise, repris tel quel. Court, il part toujours en entier ; long, MIA n'envoie à chaque message que les sigles cités dans la conversation — un glossaire de trois cents termes ne pèse plus sur chaque réponse. La carte « Ce que l'assistant lit » montre le texte exact et, pour une question d'essai, les termes retenus.

## MIA connaît votre entreprise et suit ses modèles PowerPoint et Word
<!-- id: entreprise-modele-powerpoint | genre: nouveaute | pour: administrateurs -->

La nouvelle page Organisation → Entreprise reçoit le nom de l'entreprise, sa présentation (guidée par des rubriques et des exemples), son logo, une mention de pied de page et ses modèles PowerPoint et Word officiels — Marque & apparence ne garde que le nom, les logos et les couleurs de l'application. MIA connaît l'entreprise dans toutes les conversations ; les présentations et les documents Word sont construits dans vos modèles, et les PDF portent votre logo et votre mention avec le numéro de page. Un bouton télécharge une présentation ou un document d'essai pour vérifier le rendu.

## Une réponse perdue en route le dit dans la conversation
<!-- id: generation-interrompue-signalee | genre: correctif | pour: tous -->

Quand le serveur s'arrêtait pendant qu'il rédigeait une réponse (redémarrage, mise à jour), la conversation restait sans réponse ni explication. Le message « Génération interrompue » apparaît désormais à sa place, en direct comme au retour dans la conversation : il suffit de renvoyer votre message.

## Plus d'attente quand vous êtes nombreux à écrire
<!-- id: reponses-en-parallele | genre: amelioration | pour: tous -->

MIA rédige désormais plusieurs réponses en même temps : votre question ne fait plus la queue derrière celles de vos collègues, ni derrière la mise à jour d'une base de connaissances ou une tâche programmée. Dans une même conversation, les réponses gardent l'ordre de vos messages. Les administrateurs règlent le nombre de réponses menées de front selon la taille de l'équipe.

## Une tâche programmée se prépare et se corrige dans le chat
<!-- id: mia-prepare-vos-taches | genre: amelioration | pour: tous -->

Demandez à MIA de refaire une chose régulièrement : elle choisit l'assistant, le modèle, les bases de connaissances et les outils qui conviennent, parmi ceux que vous pouvez utiliser, et vous montre la tâche dans une carte. « Plutôt à 9 h » ou « avec l'assistant Juriste » la corrige sans toucher au reste, la carte précédente se grise ; une fois la tâche approuvée, « Exécuter maintenant » lance une première exécution sans attendre son heure.

## Des images qui reprennent vos modèles
<!-- id: images-de-reference | genre: nouveaute | pour: tous -->

Les images d'un assistant (ses documents de référence) et celles des bases de connaissances de la conversation servent désormais de modèles à l'outil image : demandez « notre mascotte au ski » ou « le flacon de la photo produit sur fond de plage », et MIA transmet les bonnes images au générateur, qui reprend fidèlement le personnage, le produit ou le logo — jusqu'à quatre à la fois. Ça marche aussi bien quand l'assistant génère l'image lui-même qu'avec l'outil Image du menu « + », et la ligne « D'après … » sous l'image dit lesquelles ont servi.

## MIA crée vos assistants
<!-- id: mia-cree-vos-assistants | genre: nouveaute | pour: createurs -->

Décrivez dans le chat l'assistant dont vous avez besoin : MIA le compose — consignes, modèle, température, bases de connaissances, outils, couleur, et même un avatar créé pour lui — en ne proposant que ce que vos droits vous permettent, et vous le présente dans une carte. Vous l'approuvez, lui demandez des changements dans la conversation, ou cliquez « Ajuster » pour finir dans la fiche complète, déjà remplie ; rien n'est créé sans votre accord. Au passage, une carte à approuver — tâche programmée, message Slack — ne disparaît plus quand on recharge la page.

## Le menu « + » s'ouvre au-dessus de la zone de saisie sur téléphone
<!-- id: menu-plus-telephone | genre: correctif | pour: tous -->

Sur iPhone et sur l'application web installée, le menu « + » recouvrait la zone de saisie : sur l'écran d'accueil, le choix du modèle et le bouton Envoyer apparaissaient à travers et prenaient le doigt, et un long message en masquait le début. Le menu, comme les listes des assistants et des bases de connaissances, s'ouvre désormais juste au-dessus de la zone de saisie, et le bouton ne reste plus grisé après avoir été touché.

## Des présentations PowerPoint à télécharger depuis la conversation
<!-- id: presentations-powerpoint-conversation | genre: nouveaute | pour: tous -->

Demandez « une présentation PowerPoint » dans la conversation : elle arrive sous forme de carte, prête à télécharger, avec une couverture, une slide par partie, des intercalaires et les notes du présentateur — et des slides dédiées quand le contenu s'y prête : chiffres clés en grand, chronologie, vrai tableau, comparaison en deux colonnes, étapes numérotées ; la flèche de la carte la propose aussi en PDF, une page par slide, en Word ou en Markdown. Pour des slides illustrées et retouchables à l'écran, l'outil Présentation reste là. Au passage, les présentations ouvertes dans PowerPoint ne soulignent plus chaque mot comme une faute, et le texte des puces ne s'écarte plus de sa puce.

## Le filtre des comptes retrouve Microsoft et les mots de passe
<!-- id: filtre-comptes-connexion | genre: correctif | pour: administrateurs -->

Dans Administration → Personnes → Comptes, le filtre « méthode de connexion » ne retrouvait ni les comptes Microsoft, ni ceux à mot de passe, ni ceux qui se connectent de plusieurs façons : il propose désormais Google, Microsoft, Admin local et Mot de passe, et chaque compte apparaît sous chacune de ses méthodes. La fenêtre « Nouvel utilisateur » laissait croire qu'elle allait créer un compte Google : elle dit maintenant qu'elle crée un compte à mot de passe, et qu'une personne qui se connecte par Google ou Microsoft n'a pas besoin d'y passer.

## Une barre latérale mieux découpée
<!-- id: volet-gauche-decoupe | genre: amelioration | pour: tous -->

Dans la barre latérale, les conversations épinglées ont désormais leur propre cadre, en tête, avec leur nombre sur le maximum ; chaque période — aujourd'hui, hier, les 7 derniers jours… — s'ouvre sur son nom, prolongé d'un trait fin, et se repère d'un coup d'œil. Pour qui gère des assistants, les raccourcis Assistants, Tâches programmées et Bases de connaissances sont réunis dans un cadre, chacun terminé par une flèche : on voit tout de suite qu'ils ouvrent une page ; dans les paramètres et l'administration, « Retour au chat » prend la même forme et reste en vue en haut du menu.

## Thème, contraste, taille de l'affichage et touche d'envoi, pour vous seul
<!-- id: affichage-personnel | genre: nouveaute | pour: tous -->

Une nouvelle page, Réglages → Affichage, laisse chacun choisir son thème — clair, sombre, ou automatique pour suivre le réglage de son appareil —, renforcer le contraste, agrandir ou réduire toute l'interface au curseur, de 80 à 150 %, avec un aperçu qui suit le geste — texte, boutons et images compris —, et envoyer ses messages avec Ctrl + Entrée plutôt qu'Entrée. Le choix suit votre compte sur tous vos appareils, application mobile comprise, sans rien changer pour les autres.

## Mistral Large 4, GPT-6.1 Sol et GPT-6 Luna au catalogue
<!-- id: mistral-large-4-gpt-61-sol | genre: nouveaute | pour: administrateurs -->

Trois modèles s'activent dans Modèles IA → Modèles activés : Mistral Large 4, en aperçu, multimodal, fenêtre d'un million de tokens et moitié prix pendant deux semaines ; GPT-6.1 Sol, proche de GPT-6 Astra pour bien moins cher ; GPT-6 Luna, le plus économique d'OpenAI. Le modèle par défaut ne change pas. Les tarifs de GPT-5.6, de la dictée vocale et des anciens Claude sont corrigés, les modèles arrêtés par OpenAI retirés ; si vos tarifs sont enregistrés, cliquez « Mettre à jour coûts Internet (USD) » dans Tarifs & crédits — le tarif normal est enregistré, jamais une remise passagère.

## Un assistant réglé très créatif répond de nouveau sur Claude et Mistral
<!-- id: temperature-bornee-editeurs | genre: correctif | pour: createurs -->

Un assistant dont la température dépassait 1 échouait à chaque message sur Claude et GLM, et au-delà de 1,5 sur Mistral : la valeur est désormais ramenée au maximum que l'éditeur accepte.

## Crédit épuisé chez un éditeur : MIA dit qui prévenir
<!-- id: erreurs-prevenir-administrateur | genre: amelioration | pour: tous -->

Quand un modèle ne peut plus répondre — crédit épuisé chez son éditeur, accès refusé, panne qui dure —, l'encadré l'explique sans jargon et porte un bouton **Prévenir un administrateur** : la liste des administrateurs s'ouvre, et chaque nom ouvre un courriel déjà rédigé ; la dictée et la lecture à voix haute disent la même cause au lieu d'un message en anglais. Les administrateurs voient aussitôt un point rouge sur **Modèles IA › Clés API**, même quand le modèle de secours répond à la place, et un plafond de dépense mensuel atteint chez Claude ou Mistral ne passe plus pour un « service saturé » qu'il suffirait d'attendre.

## Les bases de connaissances, en pleine page et en schéma
<!-- id: kb-fiche-schema | genre: amelioration | pour: createurs -->

Créer ou modifier une base de connaissances ouvre désormais sa fiche en pleine page, sans fenêtre par-dessus : identité, sources (fichiers à glisser-déposer, plusieurs à la fois, ou dossier à parcourir sur place), assistants liés et partage. Cliquer une base de la liste affiche à droite son schéma — ce qui l'alimente, son moteur d'indexation, les assistants qui l'interrogent et qui y a accès —, aux couleurs de ceux des assistants et des tâches programmées. Le modèle d'embedding et la réindexation sont rangés dans une section « Avancé », en bas de la fiche, qui montre aussi chaque extrait enregistré et s'il a bien son vecteur.

## Une tâche programmée ne change plus d'assistant sans vous
<!-- id: taches-assistant-outils | genre: correctif | pour: tous -->

Quand l'assistant d'une tâche n'est plus disponible pour votre compte, son formulaire l'affiche « Assistant indisponible — à remplacer » au lieu de basculer en silence sur « sans assistant » au premier enregistrement. Le formulaire et le schéma montrent aussi les outils qu'apporte l'assistant ; en « Agir sans validation », une automatisation n8n ou une présentation Gamma d'un de ces outils attend votre validation, sauf si vous cochez l'outil dans la tâche.

## Microsoft To Do rejoint les connecteurs
<!-- id: connecteur-microsoft-to-do | genre: nouveaute | pour: tous -->

Reliez vos listes Microsoft To Do dans Réglages → Comptes connectés, puis allumez « Tâches Microsoft To Do » dans le menu + : MIA consulte vos tâches, en ajoute et les modifie — échéance, rappel à une heure précise, la marquer terminée — après votre approbation. Aucune tâche n'est jamais supprimée. La recherche « dans toutes vos sources » trouve désormais aussi vos tâches, Google comme Microsoft.

## Le schéma d'un assistant, d'un coup d'œil
<!-- id: assistants-schema | genre: nouveaute | pour: createurs -->

Sur la page Assistants, cliquer un assistant affiche à droite sa composition — modèle et température, début du prompt système, bases de connaissances, documents de référence, outils, état et partage —, avec les couleurs et les icônes du schéma des tâches programmées. Sur sa fiche, en création comme en modification, le schéma suit chaque geste et chacun de ses éléments mène droit au réglage correspondant ; après Créer, la liste s'ouvre sur le nouvel assistant.

## Une barre latérale plus légère
<!-- id: volet-gauche-allege | genre: amelioration | pour: tous -->

« Nouvelle conversation » et la recherche forment désormais un seul bloc en tête de la barre latérale ; le texte est plus fin et les lignes plus serrées, si bien que davantage de conversations tiennent à l'écran, sur ordinateur comme sur mobile. Une pastille de couleur rappelle l'assistant d'une conversation, et la conversation ouverte se repère à son fond gris.

## Le plan de travail se termine avec la réponse
<!-- id: plan-travail-clos | genre: correctif | pour: tous -->

La carte « Plan de travail » restait parfois bloquée avant la fin — « 3/4 », dernière étape « En cours » — sous une réponse pourtant terminée, avec un bouton « Continuer » inutile. Quand MIA conclut sans rien attendre de vous, l'étape en cours est désormais cochée ; elle reste « En cours » si une carte attend votre approbation ou si une action a échoué.

## Le formulaire d'une tâche programmée guide mieux
<!-- id: taches-formulaire-guide | genre: amelioration | pour: tous -->

Dans Espaces → Tâches programmées, la consigne — ce que l'assistant reçoit à chaque exécution — est mise en avant, avec des exemples à poser d'un clic : Point du lundi, Veille quotidienne, Bilan du mois, Relances du vendredi. Les bases de connaissances, connecteurs et outils se choisissent dans des cases alignées, toujours à plusieurs d'un clic. Le schéma de la tâche reste en vue pendant que vous faites défiler le formulaire, et cliquer un de ses éléments mène droit au réglage correspondant.

## Le volet de gauche mène droit à vos espaces
<!-- id: volet-gauche-espaces-createur | genre: amelioration | pour: createurs -->

Sous « Nouvelle conversation », qui gère des assistants trouve désormais trois raccourcis dans la barre latérale : Assistants, Tâches programmées et Bases de connaissances — un clic ouvre la page, sans passer par un menu. ⌘K (Ctrl+K sur Windows et Linux) mène à la recherche depuis n'importe où, même volet replié. En bas, la roue crantée mène directement à vos Paramètres (Mon profil) et, pour un administrateur, le bouclier au Tableau de bord ; les menus Paramètres et Administration restent dans le menu du compte, derrière votre avatar.

## Une tâche programmée peut agir sans attendre votre validation
<!-- id: taches-sans-validation | genre: nouveaute | pour: tous -->

Dans le formulaire d'une tâche, « Ce que la tâche peut faire » propose désormais « Agir sans validation » : la tâche crée elle-même évènements, tâches, messages Slack ou fichiers, sans carte à approuver, et chaque action est notée dans sa conversation. Les emails restent des brouillons sauf si vous cochez « Envoyer les emails », dix actions au plus par exécution, et un essai n'agit jamais sans vous. Votre administrateur peut retirer cette option.

## Le schéma d'une tâche programmée, d'un coup d'œil
<!-- id: taches-schema | genre: amelioration | pour: tous -->

Dans Espaces → Tâches programmées, cliquez une tâche : son schéma s'affiche à droite — ce qui la déclenche, l'assistant qui l'exécute, le modèle qu'il utilise, les connecteurs, bases de connaissances et outils qu'elle consulte. Le même schéma accompagne le formulaire et suit chacun de vos réglages pendant que vous créez ou modifiez une tâche.

## Une tâche programmée vous prévient sur vos appareils
<!-- id: taches-notifications | genre: nouveaute | pour: tous -->

Activez les notifications sur votre ordinateur ou votre téléphone, en bas de Espaces → Tâches programmées : quand une tâche a un résultat, des actions à valider ou un échec, une notification vous prévient, même MIA fermée, et ouvre sa conversation. Elle dit ce qui s'est passé, jamais le contenu de la réponse ; sur iPhone et iPad, ajoutez d'abord MIA à l'écran d'accueil.

## Programmez une demande : elle se répète toute seule
<!-- id: taches-programmees | genre: nouveaute | pour: tous -->

Confiez une demande à MIA ou à un assistant — le point sur vos mails chaque lundi à 8 h, une revue de presse chaque matin — et elle s'exécute sur le serveur, même MIA fermée : la réponse vous attend dans sa conversation, signalée par une pastille. Demandez-le dans le chat (MIA prépare la tâche dans une carte à approuver), ou passez par l'horloge « Programmer » de l'assistant, « Refaire régulièrement » sous une réponse ou Espaces → Tâches programmées. Une tâche interroge les bases de connaissances et utilise les outils de votre choix, prépare si vous le permettez des brouillons qui attendent votre accord, et « Lancer un essai » montre sur place ce qu'elle donnera.

## Slack se configure pas à pas, et l'écran dit ce que l'assistant en fera
<!-- id: slack-configuration-guidee | genre: amelioration | pour: administrateurs -->

Connecteurs → Configurer l'application Slack propose désormais un manifeste prêt à coller dans Slack, qui déclare d'un coup l'adresse de retour et les autorisations, et un bandeau qui dit où en est la mise en place. Pour chaque autorisation, l'écran explique ce qu'elle permet à l'assistant et ce qui manque si un utilisateur la refuse ; il montre aussi, mot pour mot, la consigne que reçoit l'assistant quand Slack est allumé dans une conversation.

## De nouveaux modèles GLM et Kimi, Perplexity mis à jour
<!-- id: glm-kimi-perplexity-octobre | genre: amelioration | pour: administrateurs -->

GLM 5.3, GLM 5.3 Flash (qui lit les images) et Kimi K2.6 rejoignent Modèles IA → Modèles. Sonar Reasoning, retiré par Perplexity, a quitté le catalogue : choisissez Sonar Reasoning Pro à sa place. Le coût d'une réponse Perplexity compte désormais les frais que Perplexity facture à chaque réponse.

## Mon profil : votre langue, et un seul endroit pour vos souvenirs
<!-- id: mon-profil-langue-renvoi-memoire | genre: amelioration | pour: tous -->

Mon profil propose désormais la langue de l'interface, le même réglage que le sélecteur en haut à droite, et ne duplique plus la liste « MIA pour vous » : une carte y renvoie vers Préférences IA, où vos souvenirs vivent sous votre contexte. La page Usage porte enfin le même nom que l'entrée du menu.

## Préférences IA : une page qui se lit en trois temps
<!-- id: preferences-ia-trois-sections | genre: amelioration | pour: tous -->

Réglages → Préférences IA se lit désormais en trois parties : Comment MIA vous répond (le style), Ce que MIA sait de vous (votre contexte, puis vos souvenirs « MIA pour vous », enfin réunis au même endroit) et À l'oral (la voix). Un sommaire en tête, des cartes qui disent en une ligne ce que chaque style change, une coche sur le choix en cours, et une page aussi lisible sur téléphone. En anglais, les styles sont enfin traduits.
## DeepSeek répond de nouveau, et lit vos images
<!-- id: deepseek-v4-flash | genre: correctif | pour: administrateurs -->

DeepSeek avait retiré ses anciens modèles fin juillet : toute conversation sur DeepSeek échouait depuis. MIA propose désormais son modèle unique, DeepSeek V4.1 Flash, qui lit aussi les images jointes — si DeepSeek était le modèle d'un fournisseur ou imposé par un assistant, re-sélectionnez-le dans Modèles IA → Modèles. Côté Gemini, trois modèles rejoignent le catalogue (3.6 Flash, 3.5 Flash, 3.1 Flash-Lite) et la génération 2.5, que Google réserve désormais aux comptes qui l'utilisaient déjà, le dit dans sa colonne Limites.

## Vos réglages et l'administration à portée de clic
<!-- id: menu-compte-roue-crantee-bouclier | genre: amelioration | pour: tous -->

En bas de la barre latérale, une roue crantée à droite de votre nom mène à vos Paramètres (Mon profil, Mes créations, Préférences IA, Connexions, Usage) ; un créateur d'assistants y trouve aussi son Espace créateur. Les administrateurs ont en plus un bouclier, qui mène au Tableau de bord et porte une pastille sur ce qui reste à régler. Le menu du compte, lui, se recentre sur qui vous êtes : Paramètres ›, Administration › (les deux menus complets), À propos, déconnexion.

## La page À propos fait peau neuve
<!-- id: page-a-propos-refonte | genre: amelioration | pour: tous -->

La page À propos (menu du compte, ou lien sous la conversation) présente désormais ce que MIA sait faire aujourd'hui — modèles, Internet, assistants, bases de connaissances, connecteurs, actions enchaînées, créations, voix — et renvoie vers la documentation complète sur myDocs, où chaque fonctionnalité a sa page. Le formulaire d'idées et de bugs et les Nouveautés restent à portée de clic.

## Voyez comment MIA se sert de ses outils
<!-- id: ecran-agent | genre: nouveaute | pour: administrateurs -->

Un nouvel onglet Agent, dans Maintenance → Diagnostic, montre sur 7, 30 ou 90 jours ce que fait MIA quand elle consulte vos connecteurs : outils qui échouent ou traînent, réponses qui atteignent leur limite d'allers-retours, cartes d'approbation refusées ou laissées sans décision. Aucun contenu n'est enregistré, seulement des nombres.

## Les très longues conversations ne s'arrêtent plus
<!-- id: conversations-longues-resumees | genre: amelioration | pour: tous -->

Une conversation qui s'allongeait finissait par échouer en « trop de contenu pour ce modèle ». MIA l'allège désormais d'elle-même : les fichiers joints il y a longtemps ne repartent plus avec chaque message (elle les relit si votre question en a besoin), puis le début du fil laisse la place à un résumé. Un long document ou un long email se lit en entier, partie par partie.

## Une seule recherche dans toutes vos sources
<!-- id: recherche-toutes-sources | genre: nouveaute | pour: tous -->

Quand plusieurs services sont allumés, MIA cherche un sujet dans tous à la fois (mails, Drive, Slack, agendas, tâches, bases de connaissances) au lieu de les interroger l'un après l'autre : la réponse arrive plus vite et dit d'où vient chaque élément. Gmail et Outlook, ou vos deux agendas, se consultent de même d'un seul coup.

## Un évènement créé par MIA tombe à la bonne heure
<!-- id: agenda-fuseau-utilisateur | genre: correctif | pour: tous -->

Un évènement demandé « demain à 14 h » pouvait arriver deux heures plus tard dans votre agenda, et les heures du calendrier Outlook s'affichaient décalées. MIA lit et écrit désormais les heures à l'heure de votre appareil, et la carte d'approbation précise le fuseau.

## Lisez une page web, retrouvez une ancienne conversation
<!-- id: page-web-anciennes-conversations | genre: nouveaute | pour: tous -->

Collez une adresse dans votre message : MIA lit la page, ou le PDF en ligne, et répond d'après son contenu réel ; les pages privées et les adresses internes sont refusées. Faites allusion à un échange passé (« la dernière fois », « tu m'avais conseillé… ») : MIA le retrouve dans vos autres conversations et cite celle d'où vient l'information.

## MIA lit vos pièces jointes et trouve vos créneaux libres
<!-- id: pieces-jointes-creneaux | genre: nouveaute | pour: tous -->

MIA lit le contenu des pièces jointes de vos emails Gmail et Outlook (PDF, Word, Excel, PowerPoint…) quand votre question porte dessus, et un long document se lit en entier, partie par partie. Elle trouve aussi les créneaux libres de votre agenda Google ou Outlook, en tenant compte des collègues que vous nommez quand leur agenda lui est visible, puis propose de bloquer celui que vous choisissez.

## Les connecteurs fonctionnent avec presque tous les modèles
<!-- id: connecteurs-tous-modeles | genre: nouveaute | pour: tous -->

Gemini, Mistral, DeepSeek, Kimi et GLM savent désormais consulter vos services reliés (Drive, Gmail, agendas…) et utiliser les outils des assistants, comme ChatGPT et Claude. Avec Mistral, un connecteur allumé passe avant la recherche Internet, et MIA vous le dit. Les administrateurs peuvent aussi ouvrir ces outils au LLM local, depuis la page Clés API.

## Laissez MIA choisir ses connecteurs
<!-- id: connecteurs-mia-choisit | genre: nouveaute | pour: tous -->

Plus besoin d'allumer Gmail ou Google Drive avant de poser votre question : avec **Laisser MIA choisir**, dans le menu **+**, MIA active elle-même, pour la réponse en cours, les services reliés dont votre demande a besoin, et laisse les autres éteints. Chaque activation s'affiche dans la liste des actions, et les écritures passent toujours par votre approbation.

## MIA affiche son plan et reprend là où elle s'est arrêtée
<!-- id: agent-plan-visible | genre: nouveaute | pour: tous -->

Pour une demande en plusieurs étapes, une carte **Plan de travail** montre les étapes prévues et les coche au fil de l'eau, et une ligne animée signale l'action en cours. Quand une étape attendait votre approbation, un bouton **Continuer** relance MIA sur les étapes restantes une fois les cartes tranchées.

## Approuvez plusieurs actions d'un coup
<!-- id: approbation-groupee | genre: nouveaute | pour: tous -->

Quand MIA prépare plusieurs actions (des tâches, des évènements, des fichiers), une carte récapitulative propose **Tout approuver** ou **Tout refuser** ; les emails, eux, se valident toujours un par un. L'issue de chaque action s'affiche aussitôt dans la conversation, et une carte en attente revient sous sa réponse quand vous rechargez la page.

## Les réponses qui enchaînent des actions coûtent moins cher
<!-- id: agent-cache-prompt | genre: amelioration | pour: administrateurs -->

Entre deux étapes d'une même réponse (connecteurs, outils), MIA demande désormais à l'éditeur de garder le début de la conversation en cache : Claude et les modèles OpenAI le relisent à une fraction du prix au lieu de le refacturer à chaque étape. Les lignes de cache de **Tarifs & crédits** en rendent compte.

## Des actions enchaînées plus fiables
<!-- id: agent-enchainements-fiables | genre: amelioration | pour: tous -->

Quand votre modèle est indisponible, le modèle de secours garde vos connecteurs s'il sait s'en servir, et ne recommence jamais une réponse où une action a déjà été menée ; quand vous arrêtez une réponse, plus aucune recherche ni carte d'approbation ne part après. Avec Claude, une recherche Internet longue ne coupe plus la réponse en plein milieu, le raisonnement suit d'une étape à l'autre quand MIA enchaîne plusieurs actions, et le texte écrit après un outil ne se colle plus à celui d'avant. D'un message à l'autre, MIA se souvient des recherches et des actions déjà menées, connaît votre date et votre heure, et lit vos documents en entier même sans assistant.

## MIA se méfie des contenus qu'elle lit et des images d'autres sites
<!-- id: securite-contenus-lus-images-externes | genre: amelioration | pour: tous -->

Un email, un fichier ou un message lu par un connecteur est traité comme une information, jamais comme un ordre : si un contenu tente de faire agir MIA à votre insu, elle vous le signale au lieu d'obéir, et une carte d'approbation nomme ce que MIA avait lu avant de la préparer. Dans les réponses, une image hébergée par un autre site ne s'affiche plus d'elle-même : un bouton **Afficher** nomme le site, et c'est vous qui décidez.

## Le menu d'administration montre ce qui reste à régler
<!-- id: administration-points-a-regler | genre: nouveaute | pour: administrateurs -->

Une pastille apparaît dans le menu d'administration sur ce qui demande votre attention : une clé enregistrée sans modèle activé, un assistant partagé qu'aucun groupe n'autorise, un modèle par défaut qui n'a plus de clé, un connecteur activé sans identifiants, un compte en attente d'activation… Survolez l'entrée : une bulle explique le problème et comment le corriger, et la pastille s'éteint d'elle-même une fois la cause réglée. Le tableau de bord liste les mêmes points, et le menu de votre compte affiche « N points à régler » à côté d'Administration.

## En conversation vocale, MIA parle dès sa première phrase
<!-- id: conversation-vocale-lecture-directe | genre: amelioration | pour: tous -->

En conversation vocale, MIA n'attend plus d'avoir écrit toute sa réponse pour commencer à parler : sa voix démarre dès la première phrase, et la suite est lue au fur et à mesure qu'elle s'écrit. L'échange devient plus rapide, sans coût supplémentaire. Si vous l'interrompez — « stop » ou **Passer** — alors qu'elle écrit encore, sa réponse s'arrête aussi, et elle vous écoute aussitôt.

## Téléchargez les fichiers que MIA crée pour vous
<!-- id: telecharger-fichiers-conversation | genre: nouveaute | pour: tous -->

Demandez un tableau Excel, un compte rendu Word, un CSV ou un simple fichier texte : il arrive dans la réponse sous forme de carte, avec un aperçu et un bouton **Télécharger**. La flèche à côté propose le même contenu dans un autre format — Excel ou CSV pour un tableau ; Word, PDF, RTF, Markdown ou texte pour un document. Les blocs de code et les images se téléchargent aussi d'un clic, et si un fichier ne peut pas être créé, la carte explique pourquoi et propose une autre solution.

## Claude Opus 5.5 et Sonnet 5.5 au catalogue
<!-- id: claude-opus-5-5-sonnet-5-5 | genre: nouveaute | pour: administrateurs -->

Les derniers modèles d'Anthropic s'activent dans Modèles IA → Modèles activés : Claude Opus 5.5, plus capable qu'Opus 5 et moins cher, et Claude Sonnet 5.5, au prix de Sonnet 5 ; le modèle par défaut ne change pas sans votre choix. Le tarif de Claude Sonnet 5 est corrigé, Anthropic ayant gardé son tarif de lancement : si vos tarifs sont enregistrés dans Coûts → Tarifs & crédits, cliquez « Mettre à jour coûts Internet (USD) » pour l'appliquer. Les refus que l'éditeur facture (biologie, extraction du raisonnement…) sont désormais comptés dans les usages.

## Claude ne coupe plus ses longues réponses
<!-- id: claude-reponses-longues | genre: amelioration | pour: tous -->

Les modèles Claude disposent d'une réponse bien plus longue : une réponse détaillée, une page web complète ou une présentation fournie ne s'arrêtent plus au milieu faute de place, même quand le modèle réfléchit longuement avant d'écrire. Claude Fable, qui en avait déjà davantage, voit la sienne doubler.

## Quand un modèle échoue, MIA vous dit pourquoi
<!-- id: erreurs-modeles-claires | genre: amelioration | pour: tous -->

L'encadré qui remplace une réponse en échec nomme maintenant la cause — **Crédit épuisé chez** l'éditeur, **Trop de contenu pour ce modèle**, **Service surchargé**, **Pièce jointe refusée**… — et dit quoi faire : réessayer, démarrer une nouvelle conversation, changer de modèle ou prévenir un administrateur. Une réponse qui s'arrête en route se termine par une ligne qui en donne la raison (écrivez **continue** si le modèle a atteint sa longueur maximale), une page web coupée porte l'encadré **Page incomplète**, et le message de bascule sur le modèle de secours indique pourquoi le vôtre n'a pas répondu.

## En conversation vocale, dites les boutons — et dites « stop »
<!-- id: conversation-vocale-ordres | genre: nouveaute | pour: tous -->

En conversation vocale, les boutons des cartes se disent : « approuve » ou « annule » pour une action préparée par MIA ; pour un email, « envoie-le » puis « confirme », « crée le brouillon », « plus court » ou « abandonne ». MIA annonce ce qui a été fait, et un simple « oui » n'approuve jamais rien. Pendant qu'elle lit sa réponse, dites « **stop** » : elle se tait et vous écoute — le bouton **« Stop »** du bandeau règle cette écoute sur chaque appareil (à allumer sur iPhone et iPad, où elle fait baisser la voix). « Au revoir » termine la conversation.

## Corrigez un email directement dans sa carte
<!-- id: email-edition-directe-carte | genre: amelioration | pour: tous -->

Plus besoin de passer par un bouton **Modifier** : dans la carte de relecture d'un email (Gmail ou Outlook), cliquez dans les destinataires, l'objet ou le texte et corrigez comme dans votre messagerie, gras et italique compris. Vos corrections sont enregistrées avant l'envoi comme avant la création du brouillon : c'est toujours le message que vous voyez qui part, et si une correction est refusée (adresse invalide, message vide), la carte le dit et rien ne part.

## L'écran de votre téléphone reste allumé pendant une conversation vocale
<!-- id: conversation-vocale-ecran-allume | genre: correctif | pour: tous -->

Sur téléphone et tablette, l'écran s'éteignait au bout du délai de veille pendant que MIA réfléchissait ou parlait, l'application était mise en pause et la conversation se coupait. L'écran reste désormais allumé tant qu'une conversation vocale est en cours, et retrouve son délai de veille habituel dès que vous la terminez. Si votre navigateur ne le permet pas (version ancienne, économiseur de batterie), rien ne change pour vous : la conversation fonctionne, et l'écran s'éteint comme avant.

## Envoyez un email relu d'un clic, depuis la carte
<!-- id: envoi-email-carte | genre: nouveaute | pour: tous -->

La carte de relecture d'un email (Gmail ou Outlook) gagne un bouton **Envoyer** : après une confirmation qui rappelle les destinataires et le caractère définitif, le message part immédiatement — dans le fil d'origine s'il s'agit d'une réponse — et un message ✅ mène à vos Envoyés. **Créer le brouillon** reste là pour qui préfère finir dans sa messagerie. Rien ne part sans ce clic : l'assistant n'a aucun moyen d'envoyer de lui-même. Côté Outlook, une connexion faite avant ce bouton demande une reconnexion (badge **Envoi** dans Réglages → Comptes connectés).

## Relisez, corrigez ou faites améliorer un email avant de l'approuver
<!-- id: relecture-brouillon-email | genre: amelioration | pour: tous -->

Quand MIA prépare un email (brouillon Gmail ou Outlook, nouveau message ou réponse), la carte sous sa réponse montre **le message tel qu'il partira** : destinataires, objet et corps mis en forme. Quatre boutons : **Créer le brouillon**, **Modifier** (les champs se retouchent en place, texte compris — depuis, directement dans la carte), **Améliorer** (*Plus court*, *Plus formel*, *Corriger la langue*… ou une consigne à vous : « propose un créneau jeudi matin ») et **Abandonner**. MIA réécrit en connaissant le mail d'origine et votre conversation ; **Version précédente** revient en arrière. C'est toujours la dernière version relue qui part dans vos brouillons.

## Un menu d'administration plus court
<!-- id: menu-administration-regroupe | genre: amelioration | pour: administrateurs -->

Le menu d'administration passe de douze à neuf entrées. **Utilisateurs** réunit les comptes et les groupes, **Coûts** le budget, les usages et les tarifs ; **Maintenance → Diagnostic** rassemble contrôles, journaux et moteur vectoriel en onglets, **Maintenance → Données** la sauvegarde et l'élagage. Le modèle par défaut se règle en tête de **Modèles IA → Modèles**, et **Assistants** ouvre directement la liste.

## L'alerte de quota du tableau de bord suit le vrai quota
<!-- id: alerte-quota-hors-exploitation | genre: correctif | pour: administrateurs -->

Les alertes « 80 % du quota » du **Tableau de bord** comptaient aussi l'alimentation des bases de connaissances et les tests, qui n'entament aucun quota : une personne pouvait y paraître bloquée sans l'être. Elles suivent maintenant exactement le quota appliqué.

## Un budget IA mensuel pour toute l'organisation
<!-- id: budget-ia-mensuel | genre: nouveaute | pour: administrateurs -->

Dans **Coûts → Budget**, fixez ce que l'IA peut coûter chaque mois à votre organisation, un seuil d'alerte, et ce qui se passe au plafond : suspendre les nouvelles demandes jusqu'au 1er, pour tout le monde, ou seulement prévenir. Une rallonge débloque le mois en cours et expire d'elle-même. La page donne aussi le repère de crédits par compte qui garantit de ne jamais atteindre le budget, repris sous le champ de quota des utilisateurs et des groupes. Le **Tableau de bord** projette le coût réel du mois et prévient dès le seuil d'alerte.

## Quand le budget IA de l'organisation est atteint
<!-- id: budget-ia-service-suspendu | genre: nouveaute | pour: tous -->

Si votre organisation a fixé un budget IA mensuel et qu'il est atteint, la fenêtre **Service IA suspendu** l'indique, avec la date de reprise, et la même mention s'affiche sous votre nom. Les demandes reprennent le 1er du mois suivant, ou plus tôt si un administrateur accorde une rallonge ; votre message reste dans le champ de saisie.

## MIA vous prévient quand une connexion a expiré
<!-- id: connexion-expiree-rappel | genre: amelioration | pour: tous -->

Quand Google, Microsoft ou Slack cesse d'accepter la connexion d'un service que vous aviez relié (mot de passe changé, accès retiré, autorisation expirée), MIA le signale sans bruit : une **pastille ambre** sur votre avatar, la mention **« Gmail à reconnecter »** dans le menu du compte et, dans le menu **+**, la ligne du connecteur qui dit **« Connexion expirée »** au lieu de se griser sans explication. Un clic mène droit à la carte concernée dans **Réglages → Comptes connectés**, où **Reconnecter** rétablit l'accès ; **Retirer cette connexion** l'oublie si vous n'en voulez plus. La pastille disparaît d'elle-même dans les deux cas.

## Réglez la voix de MIA : vitesse de lecture et longueur des réponses
<!-- id: voix-reglages | genre: amelioration | pour: tous -->

**Réglages → Préférences IA**, carte **Voix** : choisissez la vitesse à laquelle MIA lit ses réponses, de 0,75× à 2×, et leur longueur à l'oral — **Brèves**, **Standard** ou **Détaillées**. À la voix, MIA répond désormais plus court qu'à l'écrit par défaut : une réponse lue se subit, là où une réponse écrite se parcourt. La longueur se change aussi en pleine conversation, depuis le bandeau du chat ; elle vaut pour votre question suivante. Les deux réglages suivent votre compte sur tous vos appareils, et accélérer la lecture ne coûte rien de plus.

## Slack rejoint les connecteurs : chercher, lire, et publier après approbation
<!-- id: connecteur-slack | genre: nouveaute | pour: tous -->

Reliez votre Slack dans **Réglages → Comptes connectés**, allumez **Messages Slack** dans le menu **+**, et demandez à MIA ce qui s'est dit : elle cherche dans tout ce que vous pouvez déjà lire, résume un canal ou un fil, et cite l'auteur, la date et le lien du message. À votre demande, elle prépare aussi un message à publier en votre nom — présenté dans une carte à approuver, car contrairement à un brouillon d'email, un message Slack approuvé part aussitôt et devient visible de tout le canal. MIA ne peut ni le modifier ni le supprimer ensuite, et ne voit jamais un canal privé dont vous n'êtes pas membre.

## Conversez à la voix avec MIA
<!-- id: conversation-vocale | genre: nouveaute | pour: tous -->

Un bouton **onde sonore**, à côté du micro, ouvre une conversation à la voix : vous parlez, MIA répond et vous lit sa réponse, puis vous écoute de nouveau — sans clavier. Chaque tour passe par l'assistant, les bases et les connecteurs, comme un message écrit, et reste dans le fil. Des repères sonores disent où l'on en est (message parti, MIA au travail, à vous), et MIA annonce ce qu'elle fait : recherche sur Internet, bases de connaissances, MyBrain, connecteurs. Un curseur règle le volume (sur iPhone, les boutons de l'appareil). **Passer** saute la lecture, **Terminer** met fin. Les minutes écoutées et lues comptent dans votre quota. Réglage : **Outils**, carte **Conversation vocale**.

## Trois nouveaux modèles Gemini : 3.8 Flash, 3.7 Flash et 3.5 Flash-Lite
<!-- id: gemini-38-37-flash-35-lite | genre: nouveaute | pour: administrateurs -->

Les derniers modèles de Google s'activent dans **Modèles IA → Modèles activés** : **Gemini 3.8 Flash**, le plus capable pour le code et les demandes en plusieurs étapes, **Gemini 3.7 Flash**, plus sobre pour le quotidien, et **Gemini 3.5 Flash-Lite**, le plus rapide et le plus économique. Le coût des réponses Gemini compte désormais toute la réflexion du modèle et chaque recherche Google lancée, au tarif de Google — tarif de lancement compris, jusqu'au 31 décembre 2026. Gemini 3.1 Flash-Lite (preview), arrêté par Google, n'est plus proposé.

## Deux nouveaux modèles d'image OpenAI, dont un plus rapide
<!-- id: image-gpt-image-25 | genre: nouveaute | pour: administrateurs -->

Les outils d'image peuvent désormais s'appuyer sur **GPT Image 2.5 Flare**, aussi soigné que le modèle précédent mais deux fois plus rapide, et sur **GPT Image 2.5 Sunburst**, le plus capable quand la retouche doit être précise. Flare est proposé par défaut à la création d'un outil image, au même tarif que GPT Image 2. Deux niveaux de rendu s'ajoutent pour ces deux modèles, au-delà de « Haute » : « Très haute » et « Maximale ».

## Proposez un texte au cerveau MyBrain depuis une conversation
<!-- id: kb-nourrir-mybrain | genre: nouveaute | pour: tous -->

Si votre groupe peut nourrir le cerveau MyBrain, demandez à MIA d'y ajouter un compte rendu, une décision ou une information : il rédige le texte et vous le présente dans une carte à approuver. Le texte part alors à votre nom dans la revue de MyBrain, où une personne le valide, et le lien vers la proposition s'ajoute à la conversation.

## Interrogez le cerveau MyBrain de l'équipe depuis MIA
<!-- id: kb-cerveau-mybrain | genre: nouveaute | pour: tous -->

Un administrateur peut brancher le cerveau MyBrain — le wiki de l'équipe, tenu à jour à partir des mails et des documents — comme une base de connaissances : rien n'est copié, MIA l'interroge à chaque message et cite ses fiches, avec un lien pour les ouvrir. Il n'est proposé qu'aux groupes choisis, même à travers un assistant, et peut être déjà coché dans chaque nouvelle conversation, décochable à tout moment. Avec un modèle compatible, MIA creuse le cerveau quand la question l'exige : une nouvelle recherche sous un autre angle, ou une fiche lue en entier.

## Le temps de réponse et les crédits s'affichent de nouveau proprement
<!-- id: etiquette-reponse-espace | genre: correctif | pour: tous -->

Sous chaque réponse, l'étiquette du modèle affichait « 6,4&nbsp;s » au lieu de « 6,4 s », et les crédits consommés portaient le même défaut. Sur la page **À propos**, trois titres montraient « &amp; » à la place de « & ». Tout s'affiche de nouveau normalement.

## Suivez en direct la synchronisation de vos bases
<!-- id: kb-etat-en-direct | genre: amelioration | pour: createurs -->

Chaque carte de base de connaissances dit maintenant où elle en est, et se met à jour toute seule : synchronisation en attente, en cours avec le fichier traité (« 12 / 40 »), à jour, ou en échec avec le motif. Ouverte, la base indique combien de documents sont interrogeables, liste en premier ceux à vérifier avec la raison — fichier illisible, document scanné sans texte — et nomme les fichiers du dossier qui n'ont pas été repris.

## Le bouton « Créer » des bases de connaissances et des groupes répond de nouveau
<!-- id: creer-base-groupe-bouton | genre: correctif | pour: createurs -->

Dans **Bases de connaissances** comme dans **Groupes**, cliquer sur **Créer** dans la fenêtre de création ne faisait plus rien : ni base ni groupe créé, et aucun message. Le bouton crée de nouveau, et un nom oublié est signalé sans refermer la fenêtre. À côté du dossier d'une base synchronisée, **Tester** affiche aussi « Vérification de l'accès… » le temps de la vérification.

## Joindre un fichier depuis Android propose aussi la galerie
<!-- id: android-joindre-galerie | genre: correctif | pour: tous -->

Dans l'application Android, **Fichiers ou photos** n'ouvrait que les applications de fichiers (Fichiers, Drive…) : les photos s'y trouvaient, mais au fond d'un dossier. Le téléphone propose désormais aussi la galerie et le sélecteur de photos, à côté de l'explorateur de fichiers où les documents (PDF, Word, Excel, texte, Markdown) se choisissent comme avant.

## Une base de connaissances peut suivre un Drive partagé
<!-- id: kb-drive-partages | genre: amelioration | pour: createurs -->

Les Drive partagés de votre organisation deviennent une source de base de connaissances. Avec « Ma connexion Google Drive », **Parcourir…** propose désormais **Drive partagés**, à côté de **Mon Drive** et **Partagés avec moi** : on y choisit un Drive entier ou l'un de ses dossiers, sans aucun partage par lien, et la base suit ensuite ses ajouts, modifications et suppressions comme pour tout dossier synchronisé.

## Un outil dont le modèle est arrêté est signalé, au lieu d'échouer
<!-- id: outils-modeles-arretes | genre: amelioration | pour: tous -->

Quand un fournisseur arrête un modèle, l'outil qui s'en sert reste dans le menu **+**, grisé, avec la date de l'arrêt — au lieu d'échouer à chaque demande sur un message incompréhensible. Les administrateurs sont prévenus avant la date, sur la carte de l'outil et dans **Maintenance → Diagnostic**, et le modèle n'est plus proposé à la création d'un outil. Premier concerné : OpenAI arrête son service vidéo le 24 septembre 2026, sans remplaçant ; DALL-E et Imagen 4, déjà arrêtés, sont signalés eux aussi.

## Images et vidéos ne sont plus « non tarifées » par défaut
<!-- id: tarif-catalogue-outils | genre: correctif | pour: administrateurs -->

Tant que le prix d'un outil image ou vidéo restait à 0 dans Gestion crédits, chaque création était « non tarifée » : elle coûtait le minimum de crédits et son coût réel restait inconnu. Comme pour les modèles de langage, MIA reprend désormais le tarif public du modèle quand il le connaît — à l'image, à la seconde, ou d'après la consommation réelle de chaque image — et « Mettre à jour coûts Internet » remplit aussi ces lignes.

## Regénérer une image en donne une autre version
<!-- id: regenerer-image | genre: correctif | pour: tous -->

Le bouton **Regénérer** sous une image remplaçait l'image par une réponse rédigée. Il en produit désormais une autre version, avec la même demande et le même outil. Si l'outil qui l'avait créée n'est plus disponible, l'image reste en place et un message propose d'en choisir un autre dans le menu **+**.

## « Défaut application » suit enfin le modèle par défaut
<!-- id: assistant-defaut-application | genre: correctif | pour: createurs -->

Un assistant réglé sur « Défaut application » gardait le modèle par défaut du jour de son enregistrement : quand l'administrateur changeait ce modèle, l'assistant restait sur l'ancien, et sa fiche affichait un modèle précis. Il suit désormais le modèle par défaut, changements compris, et l'option nomme le modèle actuel. Pour un assistant enregistré avant, rechoisissez « Défaut application » dans sa fiche.

## La dictée respecte le quota mensuel
<!-- id: dictee-quota | genre: correctif | pour: tous -->

Un compte au quota épuisé pouvait encore dicter ses messages, et les minutes s'ajoutaient à sa consommation. Le micro ouvre désormais la même fenêtre « quota atteint » qu'un message, et une dictée en cours s'arrête au plus tard à sa prochaine reconnexion. Masquer la dictée depuis la page Outils la coupe aussi pour les pages restées ouvertes.

## Outils : l'écran dit enfin qui les voit
<!-- id: outils-portee-groupes | genre: correctif | pour: administrateurs -->

Un outil marqué « Actif — utilisable par tous les utilisateurs » n'était proposé qu'aux groupes qui l'autorisent — et un outil tout juste créé, à aucun : seuls les administrateurs le voyaient. Chaque carte de la page Outils affiche désormais le nombre de groupes qui l'autorisent, ou « Aucun groupe — admins seuls » avec un avertissement, et la confirmation de création rappelle où l'autoriser : Groupes utilisateurs → Droits d'accès → Outils.

## Quota de groupe : vide ne veut pas dire illimité
<!-- id: quota-groupe-neutre | genre: correctif | pour: administrateurs -->

Un groupe sans quota s'affichait « illimité », alors qu'il est neutre : ses membres gardent le quota le plus élevé de leurs autres groupes. La carte du groupe indique maintenant « aucun (neutre) », et l'aide du champ explique la règle.

## Une instance neuve propose enfin un modèle à tout le monde
<!-- id: modele-defaut-instance-neuve | genre: correctif | pour: administrateurs -->

Sur une instance neuve, les utilisateurs du seul groupe ★ Par défaut recevaient « Ce LLM n'est pas autorisé pour votre groupe. » à chaque message, et la page Comportement par défaut montrait un modèle qu'elle n'avait jamais enregistré. Le premier modèle activé devient désormais le modèle par défaut, retirer ce modèle en désigne un autre, et la page signale un défaut non enregistré avec un bouton **Confirmer ce modèle**.

## Changer le modèle d'embedding d'une base
<!-- id: kb-changer-modele-embedding | genre: correctif | pour: createurs -->

Le modèle d'embedding d'une base de connaissances ne pouvait plus changer après sa création, alors que l'aide renvoyait vers **Réindexer**. Ce bouton ouvre désormais une fenêtre qui propose le modèle actuel ou un autre : tous les documents sont recalculés avec le modèle choisi, et la base répond par les mots pendant l'opération. Le modèle OpenAI 3-small n'est plus présenté comme déprécié : OpenAI a corrigé l'annonce de son retrait.

## Claude Fable 5.1 et GPT-6 Astra au catalogue
<!-- id: fable-5-1-gpt-6-astra | genre: nouveaute | pour: administrateurs -->

Les modèles les plus capables d'Anthropic et d'OpenAI s'activent dans Modèles IA → Modèles activés ; ils coûtent environ deux fois Claude Opus 5 ou GPT-5.6. Quand Claude décline un sujet sensible, ou quand votre organisation n'a pas accès à Fable, Claude Opus reprend la demande et la liste des actions le signale. Fable dispose d'une réponse beaucoup plus longue, et peut réfléchir plusieurs minutes sans que la réponse soit considérée comme bloquée.

## Un mot d'accueil sur la page de connexion
<!-- id: message-page-connexion | genre: nouveaute | pour: administrateurs -->

Organisation → Authentification accueille un texte court — bienvenue, consigne d'accès, adresse à contacter pour obtenir un compte — affiché sous le nom de l'application sur la page de connexion. Un aperçu le montre pendant la frappe, et il reste vide tant que vous n'écrivez rien. Sur cette même page, chaque section (Google, Microsoft, e-mail et mot de passe, domaines autorisés, message) garde désormais son propre bouton **Enregistrer** : des identifiants de connexion ne partent plus à moitié saisis, et la confirmation s'affiche dans la section concernée.

## Marque & apparence sur tout l'écran
<!-- id: marque-apparence-refonte | genre: amelioration | pour: administrateurs -->

La page se lit désormais sur deux colonnes de cartes — identité, logos, apparence, liens et contact, application mobile — au lieu d'une longue colonne. Les deux logos se prévisualisent côte à côte, le thème se choisit sur des vignettes, et un aperçu montre la barre latérale et les boutons dans la couleur et le thème choisis, avant même l'enregistrement. Dans le menu d'administration, **Modèles IA** passe au-dessus d'**Assistants**.

## MIA parle anglais
<!-- id: interface-anglais | genre: nouveaute | pour: tous -->

Le sélecteur de langue, en haut à droite de l'écran, bascule toute l'interface entre le français et l'anglais : conversation, paramètres, connexion, partages et pages publiques. Votre choix suit votre compte, sur tous vos appareils, et vaut dès la page de connexion ; sans choix de votre part, MIA suit la langue de votre navigateur. L'écran d'accueil vous salue dans votre langue, et les assistants vous renvoient vers les boutons tels qu'ils s'affichent chez vous. Les écrans d'administration restent en français pour le moment.

## Microsoft : un refus de connexion dit enfin quoi corriger
<!-- id: microsoft-entra-diagnostic | genre: amelioration | pour: administrateurs -->

Quand Microsoft refusait une connexion ou le branchement d'Outlook, l'écran répondait « Requête invalide » ou renvoyait vers les identifiants de l'application — même lorsque la cause était tout autre. MIA nomme désormais la vraie raison et le geste qui la règle : consentement à accorder par un administrateur de l'annuaire, compte à autoriser sur l'application, adresse de retour déclarée au mauvais endroit, secret expiré. **Organisation → Authentification** liste en plus les permissions à déclarer côté Microsoft pour que les connecteurs Outlook fonctionnent.

## L'assistant se présente au plus près de votre message
<!-- id: assistant-identite-composer | genre: amelioration | pour: tous -->

En conversation avec un assistant, le grand bandeau du haut et le dégradé de couleur qui teintait tout l'écran disparaissent. L'assistant s'affiche désormais sur la première ligne de la pilule de saisie — avatar, nom — et c'est la pilule elle-même qui prend sa couleur, d'un trait discret. Un clic sur son nom déplie ses caractéristiques (description, modèle et version, outils, bases de connaissances) ; les créateurs et administrateurs y retrouvent le bouton **Modifier**. Tant que la conversation est vide, l'assistant se présente au centre de l'écran.

## Le texte copié ne colle plus sur un fond gris
<!-- id: copie-fond-transparent | genre: correctif | pour: tous -->

Sélectionner une réponse de MIA et la coller dans Word, Gmail ou Google Docs posait le texte sur un rectangle gris clair, qu'il fallait effacer à la main. Le fond est désormais transparent, à la souris comme avec le bouton **Copier**, et le reste de la mise en forme est conservé : titres, listes, tableaux, gras et liens.

## Google Drive : fini les « je n'ai pas accès » sans explication
<!-- id: drive-acces-explique | genre: correctif | pour: tous -->

Rechercher, créer ou déplacer un fichier échouait par moments, sur le bon compte et un dossier pourtant bien partagé, sans qu'on puisse comprendre pourquoi. Les autorisations accordées à Google étaient mal retenues dès que plusieurs services Google étaient reliés : **Réglages → Comptes connectés** les affiche maintenant une à une, lecture comprise, avec un bouton pour réaccorder ce qui manque. Et devant un refus, MIA dit laquelle des deux causes s'applique : une autorisation à lui redonner, ou le rôle « Éditeur » à demander au propriétaire du fichier.

## Les nouveautés, en clair, au clic sur le numéro de version
<!-- id: nouveautes-lisibles | genre: amelioration | pour: tous -->

Le numéro de version ouvrait une liste de notes d'ingénierie, une ligne par modification livrée : une même fonctionnalité y apparaissait en trois entrées illisibles, à côté de travaux qui ne changeaient rien pour personne. Place aux 20 dernières nouveautés rédigées — un titre, deux ou trois phrases, une date —, avec un point orange sur le numéro de version tant qu'il en reste une que vous n'avez pas ouverte. L'assistant « MIA : mode d'emploi » répond aussi à « quoi de neuf ? ».

## Piocher une base de connaissances le temps d'une conversation
<!-- id: kb-choix-chat | genre: nouveaute | pour: tous | date: 2026-08-24 | version: 1.2.1.40 -->

Le bouton en forme de livre ouvert, dans la zone de saisie, permet de cocher jusqu'à trois bases de connaissances pour la conversation en cours. Elles s'ajoutent à celles que l'assistant consulte déjà : plus besoin de changer d'assistant ni de faire modifier sa configuration pour un besoin ponctuel. Le choix suit votre compte, la conversation reprise sur un autre appareil garde ses sources.

## Retrouver une base de connaissances, et voir celles qui servent
<!-- id: kb-tri-usage | genre: amelioration | pour: createurs | date: 2026-08-22 | version: 1.2.1.39 -->

La liste des bases se filtre par origine des documents, se cherche par nom, description, créateur ou assistant lié, et se trie — dont deux tris nouveaux : les plus utilisées, et les plus récemment utilisées. Chaque base affiche désormais le nombre de fois où son contenu a réellement servi à répondre, et non le nombre de fois où elle a été fouillée.

## Le détail de ce que l'IA consomme, opération par opération
<!-- id: usages-grand-livre | genre: nouveaute | pour: administrateurs | date: 2026-08-21 | version: 1.2.1.38 -->

Une dizaine d'opérations n'étaient comptées nulle part : titres automatiques des conversations, recherches Internet, dictée, vectorisation des bases de connaissances, tests d'outils. Tout est désormais journalisé et rangé par catégorie, avec le coût réel facturé par le fournisseur d'un côté et la valeur en crédits de l'autre. Ce qu'un gestionnaire déclenche pour la plateforme est visible sans entamer son propre quota, et les opérations dont le tarif est inconnu sont signalées au lieu d'être comptées à zéro.

## Écrire le prompt d'un assistant sans partir de la page blanche
<!-- id: prompt-assistants | genre: nouveaute | pour: createurs | date: 2026-08-21 | version: 1.2.1.36 -->

La fiche d'un assistant propose huit modèles de prompt rédigés (généraliste, expert métier, analyste de documents, support, rédacteur, relecteur, formateur, brainstorming) et deux boutons : rédiger le prompt à partir d'une simple description, ou améliorer celui déjà saisi. Les six sections sont toujours les mêmes, on retouche donc section par section. Un texte posé par l'IA n'est jamais enregistré tout seul : il s'affiche pour relecture, avec Annuler et Enregistrer.

## Retrouver un dossier Google Drive par son nom
<!-- id: drive-recherche | genre: correctif | pour: tous | date: 2026-08-21 | version: 1.2.1.33 -->

Demander « le dossier Contrats » ramenait trente documents qui mentionnent Contrats, sans jamais le dossier lui-même — et un dossier trouvé était annoncé « illisible », sans moyen d'atteindre ce qu'il contient. La recherche ignore désormais accents et majuscules, trouve un mot au milieu d'un autre, sait se limiter à un dossier précis ou à un chemin complet, et l'assistant peut parcourir une arborescence niveau par niveau comme dans un explorateur.

## Voir et modifier ce qui est demandé au modèle pour chaque outil
<!-- id: outils-prompts | genre: amelioration | pour: administrateurs | date: 2026-08-20 | version: 1.2.1.32 -->

Le formulaire d'un outil (page web, site illustré, carte, présentation) arrive pré-rempli avec les consignes réellement envoyées au modèle, au lieu d'un champ vide doublé d'un comportement invisible. Vider le champ revient au texte livré, qui continue alors de suivre les améliorations. Ajouter un outil d'image ne demande plus de figer une taille ni une qualité pour toutes les images à venir.

## Une installation neuve dit ce qui lui manque
<!-- id: admin-signal-config | genre: amelioration | pour: administrateurs | date: 2026-08-19 | version: 1.2.1.30 -->

Tant qu'aucune clé d'accès à une IA n'est enregistrée, l'application ne peut rien répondre. Un signal visible dans l'administration le rappelle et mène directement à l'écran concerné, au lieu de laisser découvrir le problème à la première question posée.

## Répondre à un mail dans son fil, avec votre signature
<!-- id: gmail-reponses | genre: nouveaute | pour: tous | date: 2026-08-19 | version: 1.2.1.28 -->

Un brouillon de réponse rejoint enfin la conversation d'origine chez votre destinataire, au lieu d'ouvrir un échange neuf. Le corps part en texte mis en forme — titres, gras, listes et liens ne s'affichent plus en toutes lettres — et la signature de votre boîte Gmail est reprise automatiquement.

## L'assistant garde en tête le fichier qu'il vient d'ouvrir
<!-- id: connecteurs-memoire | genre: amelioration | pour: tous | date: 2026-08-19 | version: 1.2.1.26 -->

« Ajoute une conclusion à ce document » repartait d'une recherche à l'aveugle : les fichiers, mails, évènements et tâches consultés étaient oubliés d'un message à l'autre. Ils sont désormais retenus pour la durée de la conversation. Et quand un connecteur est prêt mais que son interrupteur est éteint, l'assistant le dit au lieu de répondre qu'il n'a pas accès.

## Vos Google Docs et Sheets écrits déjà mis en forme
<!-- id: drive-mise-en-forme | genre: nouveaute | pour: tous | date: 2026-08-18 | version: 1.2.1.23 -->

Un document rédigé par l'assistant arrivait truffé de symboles d'écriture bruts au lieu de titres et de gras. Le texte est maintenant réellement mis en forme à l'écriture, et deux outils permettent de styler un passage existant ou une plage de cellules sans réécrire quoi que ce soit : gras, couleur, taille, police, format de nombre, ligne d'en-tête figée. Un nouveau tableau reçoit d'office son en-tête en gras, gelé, colonnes ajustées.

## Renommer, déplacer et dupliquer un fichier Drive depuis le chat
<!-- id: drive-rangement | genre: nouveaute | pour: tous | date: 2026-08-18 | version: 1.2.1.22 -->

Trois gestes de rangement s'ajoutent à la lecture et à l'écriture, chacun soumis à votre approbation avant exécution — la carte annonce le nom du fichier et sa destination, jamais un identifiant. La duplication passe par Google, la copie est donc fidèle (mise en forme, images, tableaux) et sert de point de départ à un modèle de document. Aucune suppression n'est possible.

## L'assistant annonce son plan et enchaîne les étapes
<!-- id: connecteurs-agentiques | genre: amelioration | pour: tous | date: 2026-08-18 | version: 1.2.1.20 -->

Une demande qui traverse plusieurs services — chercher un fichier, le lire, en tirer un mail — était traitée par petits bouts, avec une question à chaque étape. L'assistant annonce désormais son plan en une phrase, enchaîne les lectures sans redemander la permission, croise plusieurs sources dans le même tour et adapte sa recherche quand elle ne donne rien. Les écritures, elles, restent soumises à approbation.

## Une zone de saisie plus compacte, un brouillon jamais perdu
<!-- id: chat-composer | genre: amelioration | pour: tous | date: 2026-08-18 | version: 1.2.1.15 -->

Les boutons d'action sont intégrés à la zone de saisie, l'historique et les messages sont plus denses : il tient beaucoup plus de conversation à l'écran. Une session expirée est détectée avant l'envoi et votre message est conservé, au lieu d'être perdu sur une page de connexion. Un texte proposé entre repères de code s'affiche enfin comme du texte.

## Voir ce que l'assistant a fait avant de répondre
<!-- id: chat-trace-actions | genre: nouveaute | pour: tous | date: 2026-08-18 | version: 1.2.1.13 -->

Au-dessus de chaque réponse s'affiche la liste des actions menées pour la produire : bases de connaissances consultées, recherche Internet, fichiers lus, mails ouverts, image générée, souvenir enregistré. Chaque ligne porte l'icône de ce qu'elle a fait — une enveloppe pour un mail, un calendrier pour un agenda, une case cochée pour les tâches, un dossier ou une feuille pour un fichier — au lieu de la même coche partout. Elle apparaît au fil de la génération et reste consultable en revenant sur la conversation, y compris pour les réponses d'avant.

## Faire le ménage dans les conversations et créations anciennes
<!-- id: admin-elagage | genre: nouveaute | pour: administrateurs | date: 2026-08-18 | version: 1.2.1.12 -->

Rien n'avait jamais été supprimé : les fichiers des conversations effacées par leurs propriétaires restaient sur le disque. Un écran d'administration permet d'élaguer au-delà d'une durée choisie, en épargnant les conversations épinglées. Une création ancienne voit son image ou sa vidéo libérée, mais la réponse écrite qui l'accompagnait reste en place — la conversation n'est jamais trouée.

## Vos mails et votre agenda Outlook
<!-- id: outlook | genre: nouveaute | pour: tous | date: 2026-08-17 | version: 1.2.1.10 -->

Deux connexions Microsoft s'ajoutent aux Comptes connectés : la messagerie Outlook (recherche, lecture, brouillons soumis à approbation) et le calendrier Outlook (consultation et création d'évènements). Elles sont indépendantes l'une de l'autre : relier son agenda ne donne aucun accès à ses mails. Aucun envoi de mail n'est possible, et un évènement Outlook ne peut pas convier de participants. Demandez « réponds à ce mail » et le brouillon se range dans la conversation d'origine, avec l'objet et le destinataire repris — il ouvrait jusqu'ici un fil neuf. Enfin, une autorisation accordée après coup par votre organisation est reconnue à la prochaine utilisation, sans avoir à se reconnecter.

## Choisir les connecteurs proposés aux utilisateurs
<!-- id: connecteurs-visibilite | genre: amelioration | pour: administrateurs | date: 2026-08-17 | version: 1.2.1.9 -->

Une page d'administration dédiée liste tous les connecteurs, avec le nombre de comptes reliés à chacun et un interrupteur enregistré à la volée. Un connecteur masqué disparaît partout côté utilisateur — menu de la zone de saisie comme page Connexions. Les expressions qui déclenchent une suggestion pendant la frappe s'y modifient aussi.

## La dictée vocale écrit à nouveau
<!-- id: dictee-muette | genre: correctif | pour: tous | date: 2026-08-17 | version: 1.2.1.7 -->

Le micro passait au rouge, la parole était captée, et aucun mot n'apparaissait — sans le moindre message d'erreur. La dictée fonctionne, et si plus rien n'arrive au bout de quelques secondes, elle le dit désormais au lieu de rester muette.

## Vos tâches Google, tenues depuis la conversation
<!-- id: google-tasks | genre: nouveaute | pour: tous | date: 2026-08-13 | version: 1.2.1.4 -->

Reliez Google Tasks dans Réglages → Comptes connectés pour consulter vos listes, ajouter et modifier des tâches en conversation. Chacun règle sa liste par défaut, l'horizon d'échéance affiché et l'autorisation de supprimer. Vos conventions de rangement s'apprennent au fil des échanges : dites-les une fois, elles sont retenues et proposées à la confirmation.

## Choisir comment on se connecte
<!-- id: sso-configurable | genre: nouveaute | pour: administrateurs | date: 2026-08-12 | version: 1.2.1.3 -->

La connexion à l'application se règle depuis Organisation → Authentification : Google, Microsoft et mot de passe s'activent indépendamment, avec un bouton pour vérifier les identifiants sans quitter la page. Un bouton n'apparaît sur l'écran de connexion que s'il est activé ET configuré. Un compte peut porter plusieurs identités, et un enregistrement qui ne laisserait aucun moyen de se connecter est refusé.

## Une typographie propre à MIA
<!-- id: typographie | genre: amelioration | pour: tous | date: 2026-08-11 | version: 1.2.1.1 -->

L'interface adopte une police unique, lisible et servie par l'application elle-même : aucune requête vers un service extérieur, donc aucun texte qui clignote au chargement et rien qui fuite sur les installations fermées. Le nom du produit porte la police de marque.

## OneDrive comme source, et un explorateur de dossiers
<!-- id: onedrive-explorateur | genre: nouveaute | pour: createurs | date: 2026-08-11 | version: 1.2.0.171 -->

Une base de connaissances peut se nourrir d'un dossier OneDrive, aux côtés de Google Drive, SharePoint et Google Agenda. Et il n'est plus nécessaire de coller un lien ni de rendre un dossier public : quand la source est lue avec votre connexion personnelle, on parcourt l'arborescence pour choisir le dossier, chaque fichier indiquant s'il sera repris ou non.

## L'outil choisi reste engagé toute la conversation
<!-- id: outil-engage | genre: amelioration | pour: tous | date: 2026-08-10 | version: 1.2.0.169 -->

Un outil sélectionné (image, vidéo, page web, carte, présentation) le reste jusqu'à ce que vous l'éteigniez : message suivant, rechargement de page, aller-retour entre conversations. On affine donc sa production — « mets le ciel en orange », « ajoute une étape » — sans le re-choisir à chaque fois.

## Les écrans de configuration s'enregistrent tout seuls
<!-- id: autosave-config | genre: amelioration | pour: administrateurs | date: 2026-08-08 | version: 1.2.0.159 -->

Les boutons « Enregistrer » ont disparu des écrans de réglage : une valeur modifiée est sauvegardée, et une pastille discrète le confirme. Les créations, les mots de passe et les actions destructives gardent leur bouton — on ne crée pas un objet à la frappe.

## Toutes vos créations rassemblées dans une galerie
<!-- id: galerie-creations | genre: nouveaute | pour: tous | date: 2026-08-08 | version: 1.2.0.156 -->

Espaces → Mes créations réunit tout ce que vous avez produit : images, vidéos, pages web, présentations et itinéraires, avec un aperçu réel de chacun. Chaque carte permet d'ouvrir, de télécharger dans les formats disponibles et de partager par lien, avec les mêmes boutons que dans la conversation.
