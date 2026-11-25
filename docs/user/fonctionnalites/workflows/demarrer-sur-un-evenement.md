---
title: Démarrer sur un évènement
description: Lancer un workflow tout seul à l'arrivée d'un email, d'un fichier dans Drive, à l'appel de son adresse par un autre logiciel ou à la fin d'un autre workflow.
sidebar:
  order: 10
---

Un workflow peut partir sans que vous le lanciez : quand un email arrive, quand un fichier apparaît
dans un dossier Drive, quand un autre logiciel appelle son adresse, ou quand un autre de vos
workflows se termine. Ce qui est arrivé devient sa première pièce.

## Avant de commencer

- Pour un email ou un fichier, reliez d'abord le service concerné (Gmail, Outlook ou Google Drive)
  dans **Réglages → Comptes connectés** : le workflow lit avec **votre** connexion.
- Un workflow lancé par un évènement démarre seul : ses champs de départ obligatoires doivent avoir
  une valeur par défaut — sauf s'il est lancé par son adresse, qui peut les fournir. Il ne peut pas
  exiger de fichier au départ.

## Étapes

1. Dans l'éditeur, touchez le **départ**. Le volet s'ouvre sur **Quand démarre-t-il ?** : quatre
   façons de démarrer, chacune avec son interrupteur — **Quand vous le lancez** (toujours
   possible), **Depuis le chat**, **À heure fixe** et **Quand quelque chose arrive**.
2. Allumez **Quand quelque chose arrive**, puis choisissez **ce qui le lance** :
   - **Un email arrive** :
     - **Dans quelle boîte ?** Gmail ou Outlook. Une boîte pas encore reliée propose un lien
       **Relier**.
     - **Quels emails ?** Les trois réglages courants sont visibles : **De** — une adresse
       (`paul@exemple.fr`), un domaine (`@exemple.fr`) ou un nom (`Dupont`) —, **Objet contient** et
       **Avec une pièce jointe**. **Plus de critères**, replié, ajoute **Sauf de** (les expéditeurs
       à écarter : `noreply`), **Adressés à** (l'adresse qui reçoit, pour une boîte partagée ou un
       alias), **Contient les mots** (dans l'objet ou le corps) et une **Recherche avancée** du
       service (`label:`, `-from:`…).

     Chaque champ accepte plusieurs valeurs séparées par des virgules : il suffit que l'une
     corresponde (`devis, commande`). Majuscules et accents ne comptent pas. Le **?** à côté de
     chaque champ en rappelle la règle, avec des exemples. Laissés vides, les champs ne filtrent
     rien : tout email de la boîte de réception compte.

     Sous les champs, une phrase dit **en clair** ce qui lancera le workflow (« Démarre pour chaque
     nouvel email reçu dans Gmail, envoyé par @exemple.fr, avec une pièce jointe. ») ; elle suit
     chaque frappe. **Tester sur mes derniers emails** lit les dix derniers emails de votre boîte de
     réception et dit, pour chacun, s'il lancerait le workflow ou pourquoi il serait écarté
     (« objet sans « devis » », « expéditeur écarté par « noreply » »…). L'essai porte sur vos
     réglages en cours, sans publier ; il ne lance rien.
   - **Un fichier arrive dans Drive** : **Quel dossier surveiller ?** (le lien du dossier ou son
     chemin, `Clients/À traiter`) puis, si vous voulez, **Quels fichiers ?** — une nature
     (documents, feuilles de calcul, présentations, PDF) et un mot du nom.
   - **Un logiciel appelle son adresse** : l'adresse secrète du workflow s'affiche, avec **Copier**,
     à coller dans n8n, Zapier, un formulaire ou un script.
   - **Un autre workflow se termine** : choisissez lequel.

   Changer d'avis ne perd rien : revenir à l'email retrouve ses filtres, et rallumer
   l'interrupteur retrouve le dernier évènement réglé (le temps de la page).
3. Citez la première pièce (**Email reçu**, **Fichier reçu**, **Données reçues** ou **Résultat du
   workflow précédent**) avec **@** dans les étapes, puis **Publiez**.

**Options avancées**, replié en bas du volet, réunit ce qui ne sert qu'aux habitués : le nom de
la pièce que remet l'évènement, celui de la Fiche de départ et, pour une adresse d'appel, un
exemple d'appel prêt à copier et **Changer d'adresse**.

## Ce qu'il faut savoir

- **Email reçu** est une Fiche : expéditeur, objet, date de réception, contenu, noms des pièces
  jointes. **Une étape qui cite Email reçu reçoit aussi ses pièces jointes**, en fichiers, sans
  rien cocher : un classeur Excel se lit en entier et MIA le range dans une table pour compter sur
  toutes ses lignes ; un PDF ou une photo lui arrivent tels quels. Cinq pièces jointes au plus ;
  une image collée dans la signature est ignorée, un fichier trop lourd ou illisible est nommé dans
  la réponse. Une étape qui consulte aussi la même boîte (**Peut consulter**) peut relire l'email
  en entier ou y répondre dans le fil. Pour traiter **chaque pièce jointe** une par une, ajoutez une boucle **Pièces
  jointes d'un email** sur **L'email reçu** ; pour chaque ligne d'un tableur déposé dans Drive, une
  boucle **Lignes** sur **Fichier reçu** — voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
- **Un email lance le workflow dès son arrivée** quand votre organisation a activé les
  **notifications instantanées** : Gmail ou Outlook prévient MIA, et une vérification de secours
  passe encore toutes les heures. Sinon — ou le temps de leur mise en place —, la boîte est vérifiée
  **chaque minute** (un dossier Drive, lui, toutes les cinq minutes). Sous **Quand quelque chose arrive**, une ligne
  dit lequel des deux s'applique, et pourquoi.
- **Les filtres sont appliqués par MIA au message lui-même**, de la même façon pour Gmail et
  Outlook ; un email qui n'y répond pas ne lance rien, et ne sera pas repris plus tard.
- Ce qui se trouve déjà dans la boîte ou le dossier quand vous publiez ne lance rien, pas plus
  qu'un ancien email ramené dans la boîte de réception ; ensuite, chaque nouvel email ou fichier
  lance **une** exécution, jamais deux. Si beaucoup arrivent d'un coup, cinq partent par
  vérification, les autres à la suivante.
- **L'adresse d'appel est un secret** : qui la connaît peut lancer le workflow. **Changer
  d'adresse** en crée une nouvelle et l'ancienne cesse aussitôt de répondre. Les données postées
  remplissent les champs du départ qui portent le même nom ; le tout devient la pièce **Données
  reçues**. Soixante appels par heure au plus ; un logiciel qui envoie l'en-tête `Idempotency-Key`
  peut renvoyer le même appel sans lancer deux fois.
- **Un enchaînement de workflows s'arrête à trois** : un workflow qui en relance un autre, qui en
  relance un autre… ne tourne pas sans fin.
- **Ce contenu vient de l'extérieur.** Il est remis aux étapes comme une donnée, jamais comme une
  consigne ; mais un email peut tenter de détourner un assistant. **À régler** vous signale toute
  étape ou livraison réglée pour agir sans validation sur un workflow lancé ainsi : préférez
  **à approuver**.
- **Essayer à la main** : pour un workflow lancé par un email, **Lancer** propose vos dix derniers
  emails ; choisissez-en un, il est traité comme s'il venait d'arriver (expéditeur, objet, pièces
  jointes, réponse possible dans son fil). Vous pouvez aussi coller le texte d'un email, mais il
  n'a alors ni expéditeur ni fil. Pour les autres évènements, collez les données d'un appel ou
  joignez un fichier. Si vous ne fournissez rien, MIA vous prévient : le workflow démarrerait sans
  la pièce de l'évènement, et ses étapes n'auraient rien à traiter.
- **En pause**, le workflow ne surveille plus rien ; si la connexion est coupée, la carte du
  workflow le signale et vous êtes prévenu.
