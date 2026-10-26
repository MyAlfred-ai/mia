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

1. Dans l'éditeur, touchez le **départ**, puis **Démarrer sur un évènement**.
2. Choisissez l'évènement :
   - **Un email arrive** : la boîte (Gmail ou Outlook) et, si vous voulez, **Seulement les
     emails…** — d'un expéditeur, dont l'objet contient un mot, avec une pièce jointe — et une
     **recherche avancée** du service (`label:`, `-from:`…). Vide : tout email de la boîte de réception.
   - **Un fichier arrive dans Drive** : le lien ou le chemin du dossier et, si vous voulez,
     **Seulement les fichiers…** d'une nature (documents, feuilles de calcul, présentations, PDF) ou
     dont le nom contient un mot.
   - **Un logiciel appelle son adresse** : l'adresse secrète du workflow s'affiche, avec un exemple
     d'appel à copier dans n8n, Zapier, un formulaire ou un script.
   - **Un autre workflow se termine** : choisissez lequel.
3. Citez la première pièce (**Email reçu**, **Fichier reçu**, **Données reçues** ou **Résultat du
   workflow précédent**) avec **@** dans les étapes, puis **Publiez**.

## Ce qu'il faut savoir

- **Email reçu** est une Fiche : expéditeur, objet, date de réception, contenu, noms des pièces
  jointes. Une étape qui consulte la même boîte peut relire l'email, ses pièces jointes, ou y
  répondre dans le fil. Pour traiter **chaque pièce jointe** une par une, ajoutez une boucle **Pièces
  jointes d'un email** sur **L'email reçu** ; pour chaque ligne d'un tableur déposé dans Drive, une
  boucle **Lignes** sur **Fichier reçu** — voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).
- **Boîte et dossier sont vérifiés toutes les cinq minutes.** Ce qui s'y trouve déjà quand vous
  publiez ne lance rien ; ensuite, chaque nouvel email ou fichier lance **une** exécution, jamais
  deux. Si beaucoup arrivent d'un coup, cinq partent par vérification, les autres à la suivante.
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
- **Essayer à la main** : la fenêtre de lancement d'un tel workflow vous laisse coller le texte d'un
  email, les données d'un appel ou joindre un fichier, pour un essai.
- **En pause**, le workflow ne surveille plus rien ; si la connexion est coupée, la carte du
  workflow le signale et vous êtes prévenu.
