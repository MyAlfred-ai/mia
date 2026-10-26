---
title: Répéter pour chaque élément
description: Faire le même travail pour chaque ligne d'un fichier Excel, chaque pièce jointe d'un email, chaque slide, chaque fichier d'un dossier ou chaque sujet repéré par MIA — avec un filtre, une relecture et une reprise des échecs.
sidebar:
  order: 8
---

Une boucle **pour chaque élément** répète des briques autant de fois qu'il y a d'éléments à traiter :
une relance pour chaque client d'un fichier Excel, une analyse pour chaque pièce jointe reçue, un
résumé pour chaque fichier d'un dossier, une réponse pour chaque sujet d'un email. Vous dites **ce
que la boucle parcourt** ; MIA découpe, compte, et traite les éléments un par un.

## Avant de commencer

- Ajoutez d'abord **ce que la boucle va parcourir** : un champ **Fichier** au départ (pour un Excel,
  une présentation, un PDF, un document), une étape qui remet une **Liste** ou un **Tableau**, le
  déclencheur **Un email arrive** (pour ses pièces jointes) — ou rien de tout cela si la boucle va
  chercher elle-même ses éléments dans Google Drive, Gmail ou Outlook.
- Pour Google Drive, Gmail ou Outlook, reliez le service dans **Réglages → Comptes connectés** : la
  boucle lit avec **votre** connexion.
- Chaque tour est un vrai travail, qui consomme des crédits comme un message : une boucle de trente
  éléments coûte trente fois ses briques. La fenêtre de lancement vous le chiffre avant de partir.

## Les façons de parcourir

| Parcourir… | Un élément, c'est… | Ce que l'élément porte | D'où viennent les éléments |
|---|---|---|---|
| **Lignes d'un tableau ou d'un Excel** | une ligne | les colonnes que vous déclarez (Client, Email, Montant…) | un fichier Excel ou CSV du départ, un Tableau remis par une étape, ou un fichier Google Drive |
| **Éléments d'une liste** | une ligne de la liste | son texte | une Liste, un champ liste d'une Fiche, ou un texte (une ligne = un élément) |
| **Pièces jointes d'un email** | un fichier joint | nom, type, taille, objet et expéditeur de l'email — et le fichier lui-même | l'email qui lance le workflow, ou les emails d'une recherche |
| **Slides d'une présentation** | une slide | numéro, titre, texte, notes du présentateur | un PowerPoint (.pptx) du départ, une présentation remise par une étape, ou un fichier Google Drive |
| **Pages d'un PDF** | une page | numéro, texte | un PDF du départ ou de Google Drive |
| **Sections d'un document** | une partie, d'un titre au suivant | titre, contenu | un Word, un Markdown, un texte, ou un document remis par une étape |
| **Fichiers d'un dossier Drive** | un fichier | nom, type, date de modification, lien — et le fichier lui-même | un dossier Google Drive |
| **Emails d'une recherche** | un email | expéditeur, objet, date, contenu, pièces jointes | une recherche dans Gmail ou Outlook |
| **Repérés par MIA** | ce que vous décrivez : un sujet, une référence produit, une personne… | les champs que vous demandez | n'importe quel texte, email ou document du dossier |

## Étapes

1. Dans l'éditeur, **+** à l'endroit voulu → **Boucle**. **Comment répéter** : **Pour chaque
   élément**.
2. **1. Que parcourir ?** Touchez une tuile, puis réglez-la :
   - **Lignes** : choisissez la pièce ou **Un fichier Google Drive** (son lien, son nom ou son chemin,
     comme `Suivi/Clients.xlsx`), l'**onglet** (le premier si vide) et la **ligne d'en-tête**
     (détectée seule : un titre posé au-dessus du tableau ne la trompe pas). Puis **Les colonnes que
     les briques citent** : écrivez leur nom tel qu'il est dans le fichier, ou touchez **Lire les
     colonnes d'un fichier exemple** — elles se remplissent toutes seules, avec leur type. Choisissez
     **La colonne qui nomme chaque ligne** (le client, la référence…).
   - **Pièces jointes** : **L'email reçu** ou **Les emails d'une recherche** (la boîte, la recherche,
     la période), et **Quelles pièces jointes** — toutes, les PDF, les images, les documents Office,
     les textes et CSV. Les logos et signatures collés dans le corps de l'email sont écartés, sauf si
     vous cochez **Garder les images intégrées au corps**.
   - **Fichiers d'un dossier Drive** : le dossier, et si vous voulez **Nom contenant** et la
     **nature** des fichiers.
   - **Emails d'une recherche** : la boîte, la recherche (la syntaxe du service :
     `facture has:attachment` dans Gmail), la période.
   - **Repérés par MIA** : la pièce à lire, **Ce que MIA repère** (« chaque sujet abordé dans l'email,
     avec ce qui est demandé ») et, si vous voulez, **les champs de chaque élément** (Sujet, Urgence…).
     Un seul appel à MIA découpe la pièce au départ ; les tours ne lui redemandent rien.
   - **Essayer sur un fichier exemple** : déposez un fichier, la boucle dit combien d'éléments elle y
     trouve. Le fichier est lu puis oublié, rien n'est gardé.
3. **2. Quels éléments garder ?**
   - **Éléments au plus** : au-delà, les éléments suivants sont signalés, pas traités.
   - Une **règle** sur les champs de l'élément (*Montant dû est supérieur à 0*), calculée sans IA.
   - **Sans doublon sur** un champ (l'email du client : un seul envoi par adresse).
   - **Écarter les lignes de total et les éléments sans nom** (« Total », « Sous-total », une note en
     bas d'un tableau) : coché par défaut.
   - **Relire la liste avant de commencer** : la boucle vous montre les éléments trouvés ; vous
     décochez ce qu'il ne faut pas traiter, rien ne part avant votre accord.
4. **L'élément en cours** : donnez-lui un nom (« Client en cours »). Dans les briques de la boucle,
   citez-le avec **@** — entier, ou un seul de ses champs (« @[Client en cours › Email] »). Quand
   l'élément porte un fichier (une pièce jointe, un fichier d'un dossier), ce fichier est joint au
   message des étapes qui le lisent : MIA le lit comme un fichier que vous lui auriez envoyé.
5. **Les briques répétées** : ajoutez-les sur le rail, **dans le cadre de la boucle**, avec
   **+ Ajouter** — étapes, créations, actions, validations, attentes, notifications, et une
   **condition** qui trie les éléments (voir plus bas).
6. **3. Si un élément échoue** : **Passer au suivant, le marquer en échec** (par défaut) ou **Arrêter
   le workflow**.
7. **Les actions préparées** (si des briques de la boucle agissent dans un outil) : **Une carte à
   approuver par élément**, ou **Relire toutes les actions ensemble, à la fin de la boucle** — chaque
   tour prépare son action sans attendre, et vous les relisez d'un coup à la fin.
8. **4. Ce que la boucle remet** : nommez la pièce rassemblée ; les briques qui suivent la citent comme
   les autres.

## Trier les éléments en route

Une **condition** placée dans la boucle (**+ Ajouter** → **Condition**, proposée sous « Garder cet
élément ? ») décide de l'élément sur ce que le tour vient de produire : *« À traiter » est oui*,
*« La relance est-elle utile ? »*. Pour chaque réponse, choisissez la suite : **continuer le tour**,
**passer à l'élément suivant** (l'élément est compté comme écarté) ou **arrêter le workflow**. Elle
n'ouvre pas de voies : les briques de la boucle restent sur une seule ligne.

## Au lancement

- **La fenêtre de lancement compte les tours** quand la boucle parcourt un fichier du départ (lignes,
  slides, pages, sections) : dès que vous le déposez, elle affiche « « Relancer chaque client » :
  42 tours », les éléments trouvés, ceux laissés par le filtre ou en double, les premiers d'entre eux,
  et le coût recalculé. Au-delà de cinquante tours, elle vous demande de confirmer. Les autres sources
  (un dossier, une recherche, MIA) se comptent au démarrage, dans la conversation de l'exécution.
- **Une colonne déclarée absente du fichier** arrête la boucle avant le premier tour, sans rien
  dépenser, en disant les colonnes trouvées.
- **Les éléments sont figés au départ** : une reprise, un rechargement, « Refaire les éléments en
  échec » traitent exactement les mêmes.

## Pendant l'exécution

- Le rail montre le tour en cours (« 3 / 42 ») ; le volet de la boucle liste chaque élément avec son
  état — traité, **en échec** (avec sa cause), **écarté**, en cours, à venir — et sa pièce.
- **Un élément en échec n'arrête pas les autres** : la conversation le dit, la boucle passe au suivant.
  **Trois échecs d'affilée** mettent la boucle en pause : la cause est sans doute commune (une
  connexion coupée, un crédit épuisé). Réglez-la, puis **Reprendre** : l'élément qui a mis en pause est
  refait, et la boucle continue.
- **Relire la liste** : l'encadré en tête de l'exécution montre les éléments, tous cochés. Décochez,
  puis **Lancer avec la sélection** — ou **Arrêter le workflow**.
- **Relire les actions** : à la fin de la boucle, l'encadré liste les actions préparées à chaque tour
  (un email, une tâche…). Cochez celles qui partent ; pour des emails, choisissez **Créer les
  brouillons** ou **Envoyer les emails**. **Exécuter la sélection** les fait partir, les autres sont
  écartées ; **N'en exécuter aucune** écarte tout et le workflow continue.
- **Refaire les éléments en échec** : une fois la cause réglée, ce bouton (en tête de l'exécution et
  dans le volet de la boucle) ne rejoue **qu'eux**. Les éléments traités gardent leur pièce ; ce qui
  suit la boucle est refait avec le résultat complet.

## Ce que la boucle remet

- **Lignes d'un tableau** et une Fiche par tour : le **fichier complété** — ses colonnes d'origine,
  puis les champs remis à chaque tour, et une colonne **Statut** si un élément n'a pas été traité.
  Téléchargeable en Excel depuis le dossier.
- Une Fiche par tour (autres sources) : un tableau, une ligne par élément.
- Un texte ou un document par tour : une section par élément. Une liste : la liste de tous.

## Ce qu'il faut savoir

- **Combien d'éléments** : chaque boucle a son maximum (cinquante par défaut) ; votre organisation fixe
  un plafond pour toutes les boucles. Les éléments au-delà sont signalés dans la conversation.
- **Limites des sources** : un fichier de 25 Mo au plus ; 5 000 lignes lues ; 200 fichiers d'un dossier
  (par ordre de nom) ; 100 emails d'une recherche ; 200 éléments repérés par MIA. Un PDF scanné, sans
  texte, ne se lit pas page par page.
- **Un fichier d'élément est lu à son tour**, pas avant : un fichier supprimé entre-temps met **cet**
  élément en échec, pas la boucle.
- **Les contenus lus viennent de tiers** (emails, fichiers reçus) : ils sont remis aux briques comme des
  données, jamais comme des consignes. Pour des actions vers l'extérieur, préférez la relecture
  groupée ou les cartes à approuver.
- **Dans une boucle**, ni décision de MIA ni autre boucle. Une validation s'y ouvre à chaque tour.
- **Une boucle d'avant ces réglages** continue de parcourir sa Liste, son Tableau ou son champ liste ;
  choisissez une façon de parcourir pour profiter des colonnes, du filtre et des fichiers.

## Exemples

- **Relancer chaque client d'un fichier Excel** (modèle livré) : un champ Fichier au départ ; une boucle
  **Lignes** avec les colonnes Client, Email, Montant dû, Échéance, une règle *Montant dû supérieur à
  0* et **Sans doublon sur** Email ; dans la boucle, une étape rédige la relance et une action Gmail
  la prépare à l'adresse du client ; **Relire toutes les actions ensemble** : vous cochez les relances
  qui partent.
- **Analyser chaque pièce jointe reçue** (modèle livré) : déclencheur **Un email arrive**, avec pièce
  jointe ; une boucle **Pièces jointes** ; une étape lit chaque document et remet une Fiche (nature,
  résumé, action à mener, à traiter oui/non) ; une condition passe à l'élément suivant quand il n'y a
  rien à faire ; une action prépare une tâche pour les autres.
- **Commenter chaque slide** : un PowerPoint au départ ; une boucle **Slides** ; une étape propose pour
  chacune un texte plus court et des notes du présentateur.
- **Répondre à chaque sujet d'un email** : une boucle **Repérés par MIA** sur l'email reçu, « chaque
  question posée », avec un champ Urgence ; une étape prépare la réponse de chaque sujet ; une étape
  après la boucle assemble une réponse unique.
