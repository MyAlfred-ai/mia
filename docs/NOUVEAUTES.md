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
<!-- id: workflows | genre: nouveaute | pour: createurs -->

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
