---
title: Les workflows
description: Confier à MIA et à vos assistants un travail en plusieurs étapes, qui se passent des pièces, avec vos validations et un livrable à la fin.
sidebar:
  order: 7
---

## À quoi ça sert

Un **workflow** est un travail en plusieurs étapes que MIA mène pour vous, toujours de la même façon :
répondre à un appel d'offres, transformer les notes d'une réunion en compte rendu et en tâches, faire
valider une facture avant de la payer. Chaque **étape** est confiée à MIA ou à l'un de vos
assistants ; elle **remet une pièce au dossier** (une synthèse, un tableau, une fiche, un document
Word…) que les étapes suivantes reprennent. Vous **validez** là où il le faut, et vous obtenez un
**livrable** à la fin.

## Avant de commencer

- Les workflows sont en **bêta** (pastille « Bêta » sur la tuile) : ils fonctionnent, mais
  peuvent encore évoluer.
- Les workflows sont réservés aux **créateurs d'assistants et aux administrateurs**. Ils se trouvent
  dans la rangée **Espaces** de la barre latérale, tuile **Workflows** — si la tuile est absente, votre
  administrateur ne les propose pas.
- Un workflow tourne **avec vos droits** : vos assistants, vos connexions (Gmail, Agenda…), vos bases
  de connaissances, votre quota. Chaque étape consomme des crédits, comme un message.
- Trente workflows au plus par personne, quinze briques au plus par workflow (celles d'une boucle
  comprises). Une boucle traite, elle, autant d'éléments que votre organisation le permet.

## Comment ça marche

- **Le rail se lit de haut en bas.** Le départ, puis les briques dans l'ordre du travail, puis le
  livrable — une ligne par brique : qui la fait (l'avatar de l'assistant, ou MIA), son titre, les
  pièces qu'elle lit (des points de leur couleur) et celle qu'elle remet (une étiquette). Entre deux
  briques, de petits **paquets** de la couleur des pièces descendent le lien : vous voyez ce qui
  passe d'une étape à l'autre. Survolez une pièce : les briques qui la touchent restent, les autres
  pâlissent.
- **Une couleur par nature de pièce** : texte, liste, tableau, Fiche, document, fichier, image, page
  web, carte, et le champ d'une Fiche — sur le rail, dans les volets et dans les champs où vous citez
  une pièce.
- **Le détail s'ouvre dans un volet.** Touchez une brique : ses réglages (dans l'éditeur) ou ce
  qu'elle a fait (pendant une exécution) s'ouvrent sur le côté, et l'écran reste lisible. Le volet ne
  montre que ce que vous avez **choisi**, une ligne par réglage ; touchez-la pour changer.
- **Dix briques.** Une **étape** fait travailler MIA ou un assistant et remet une pièce ; une
  **création** fait produire une image, une page web ou une carte par un outil de création du chat ;
  une **action** agit dans un outil relié (email, agenda, tâches, Slack, Drive, automatisation n8n,
  présentation Gamma) ; une **décision de MIA** choisit une suite parmi deux à six scénarios ; une
  **condition** ouvre deux voies, « si oui » et « si non » ; une **validation** attend votre
  décision ; une **boucle** répète des briques pour chaque élément — chaque ligne d'un Excel,
  chaque pièce jointe d'un email, chaque slide, page ou section, chaque fichier d'un dossier Drive,
  chaque email d'une recherche, chaque sujet repéré par MIA — ou jusqu'à un bon résultat ; **Attendre** fait patienter le workflow ; **Me prévenir** vous envoie un message en
  route ; **Arrêter le workflow**, au bout d'une voie, le termine proprement là où il en est.
- **« @ » cite le dossier partout** : une consigne, des critères, un filtre, l'objet d'un email, un
  dossier Drive. Seul ce qui a un sens dans le champ est proposé (un destinataire n'accepte qu'un
  champ email).
- **Plusieurs façons de démarrer.** À la main, depuis le chat, à heure fixe — ou tout seul, à
  l'arrivée d'un email, d'un fichier dans Drive, à l'appel de son adresse par un autre logiciel, ou à
  la fin d'un autre workflow.
- **Un livrable qui part tout seul, si vous voulez.** Par email avec ses pièces jointes, dans Google
  Drive, dans Slack ou dans une base de connaissances.
- **Des filtres pour vos emails et vos fichiers.** Une étape ou une action qui touche à Gmail, à
  Outlook ou à Google Drive ne voit que ce que vous avez filtré (expéditeur, objet, période,
  dossier, nature de fichier), et ses emails ne partent que vers les destinataires permis.
- **Une panne n'oblige pas à tout refaire.** Une étape peut réessayer seule quand un service est
  saturé ; une brique facultative peut être passée ; une exécution arrêtée se reprend là où elle en
  était.
- **Cinq formes de pièce pour une étape.** Texte, Liste, Tableau (exportable en Excel), Fiche (des
  champs nommés : montant, date, email… qu'une condition peut lire) et Document (Word, PDF,
  PowerPoint, Excel, au modèle de votre entreprise). Une création remet une **image**, une **page
  web** ou une **carte**.
- **Un brouillon, puis une version publiée.** L'éditeur enregistre au fil de l'eau ; seule la version
  **publiée** s'exécute. Vous pouvez donc retoucher un workflow sans crainte : les exécutions en
  cours gardent la version avec laquelle elles sont parties.
- **Rien ne part sans vous, sauf si vous l'avez décidé.** Une étape lit et rédige ; elle n'agit dans
  vos outils que si vous l'avez réglée ainsi, et ses actions attendent alors votre accord — à moins
  que vous ne l'ayez réglée sur **Agir sans validation**.
- **Chaque exécution a sa conversation.** Tout ce que font les étapes y est écrit, et vous la
  retrouvez dans votre historique comme une autre conversation.

## Exemples

- **Répondre aux demandes de devis** : à chaque email reçu de la forme « demande de devis », une
  étape en extrait le besoin dans une Fiche, un assistant rédige le devis au modèle de l'entreprise,
  vous le relisez, et il part en pièce jointe d'une réponse à l'expéditeur.
- **Préparer les rendez-vous de la semaine** : chaque lundi matin, la liste des rendez-vous ; pour
  chacun, une fiche de préparation ; le tout rassemblé dans un tableau enregistré dans Drive.
- **Relancer chaque client d'un fichier Excel** : le fichier de suivi parcouru ligne par ligne, seuls
  les clients qui doivent encore de l'argent, une relance rédigée pour chacun, toutes relues ensemble
  avant de partir ; le fichier complété en sortie.
- **Relancer une échéance** : une Fiche porte la date d'échéance d'un contrat ; le workflow attend
  jusqu'à deux jours avant, vous prévient sur votre téléphone, et prépare le message de relance.

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Être guidé de l'idée au workflow qui tourne seul, avec un exemple complet | [Mettre en place un workflow, pas à pas](mettre-en-place-un-workflow.md) |
| Créer un workflow : le décrire à MIA, partir d'un modèle, ou le composer brique par brique | [Créer un workflow](creer-un-workflow.md) |
| Ajouter une condition, une validation ou une action dans un outil | [Conditions, validations et actions](conditions-validations-actions.md) |
| Laisser MIA choisir la suite parmi plusieurs scénarios | [Laisser MIA choisir la voie](laisser-mia-choisir-la-voie.md) |
| Faire créer une image, une page web ou une carte par un outil, à partir du dossier | [Créer une image, une page web ou une carte](creer-une-image-une-page-ou-une-carte.md) |
| Limiter les emails et les fichiers qu'une étape voit, et à qui elle écrit | [Filtrer les emails et les fichiers](filtrer-emails-et-fichiers.md) |
| Lancer un workflow, depuis sa page ou depuis le chat, et suivre son exécution | [Lancer et suivre un workflow](lancer-et-suivre.md) |
| Relire une pièce, la corriger, renvoyer une étape | [Valider et corriger](valider-et-corriger.md) |
| Faire le même travail pour chaque ligne d'un Excel, chaque pièce jointe, chaque fichier, chaque sujet — avec filtre, relecture et reprise des échecs | [Répéter pour chaque élément](repeter-pour-chaque-element.md) |
| Refaire une étape jusqu'à un bon résultat, faire patienter un workflow, être prévenu en route | [Boucles, attentes et notifications](boucles-et-attentes.md) |
| Lancer un workflow à l'arrivée d'un email, d'un fichier, d'un appel ou à la fin d'un autre | [Démarrer sur un évènement](demarrer-sur-un-evenement.md) |
| Envoyer le livrable par email, dans Drive, dans Slack ou dans une base | [Envoyer le livrable](envoyer-le-livrable.md) |
| Réessayer seul, ignorer une brique, reprendre ou refaire une exécution | [Reprendre après une erreur](reprendre-apres-une-erreur.md) |
| Retrouver chaque lancement, relire ce que chaque brique a fait, remis ou raté, et le relancer | [Revoir l'historique des exécutions](revoir-l-historique.md) |
