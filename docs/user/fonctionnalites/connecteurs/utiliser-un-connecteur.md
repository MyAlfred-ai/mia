---
title: Utiliser un connecteur
description: Allumer un connecteur dans une conversation, faire chercher, lire ou préparer un brouillon, et approuver ce que MIA propose d'écrire.
sidebar:
  order: 2
---

Un connecteur relié s'allume dans la conversation, puis MIA s'en sert quand votre demande le
demande. Pour relier un service : [Relier un service](relier-un-service.md).

## Avant de commencer

- Le service est relié : sa carte affiche **Connecté** dans **Réglages → Comptes connectés**.
- Le modèle de la conversation sait se servir des connecteurs : le bandeau affiche **✓ Connecteurs**.

## Étapes

1. Ouvrez le menu **+** sous le champ de saisie, puis dépliez **Connecteurs**, sous **Recherche
   Internet**. Il est replié à chaque ouverture du menu ; replié, il affiche combien de
   connecteurs sont allumés et leurs logos.
2. Allumez la ligne voulue : **Fichiers Google Drive**, **Emails Gmail**, **Agenda Google**,
   **Tâches Google**, **Emails Outlook**, **Agenda Outlook**, **Tâches Microsoft To Do**,
   **Messages Teams** ou **Messages Slack**.
3. Écrivez votre demande comme vous la diriez à un collègue : *« retrouve le devis Durand dans mon
   Drive et résume-le »*.

MIA peut aussi vous le proposer : pendant que vous tapez, une bannière demande par exemple
« Autoriser l'assistant à consulter vos emails Gmail ? », avec **Activer Gmail** et **Non merci**.

## Laisser MIA choisir

Plutôt que d'allumer chaque connecteur, allumez **Laisser MIA choisir**, première ligne du volet
**Connecteurs** du menu **+**.

- MIA allume elle-même, **pour la réponse en cours**, les services reliés dont votre demande a
  besoin, et laisse les autres éteints. Une question générale n'allume rien.
- Chaque activation apparaît dans la pile d'actions : « Activation d'un connecteur — Gmail ».
- Seuls les services **reliés** sont concernés ; un connecteur que vous allumez vous-même reste
  allumé, comme avant.
- Rien ne change pour les écritures : chaque action préparée passe par sa carte d'approbation.
- L'interrupteur reste allumé pour vos prochains messages et vos nouvelles conversations, sur ce
  navigateur.

## Résultat

Pendant la réponse, MIA affiche ce qu'elle fait — « Recherche dans vos emails Gmail… », « Lecture du
document Google Drive… ». Au-dessus de la réponse, la pile d'actions garde la trace de ce qu'elle a
consulté ; une ligne animée signale l'action en cours, et certains modèles y ajoutent une courte note
sur ce qu'ils font.

Pour une demande en plusieurs étapes, une carte **Plan de travail** apparaît en tête de la réponse :
les étapes prévues, cochées au fur et à mesure. Quand la réponse se termine sans rien attendre de
vous, l'étape en cours est cochée elle aussi. La carte reste visible quand vous revenez sur la
conversation.

Le connecteur reste allumé pour vos prochains messages et vos nouvelles conversations, sur ce
navigateur. Un **point ambre** sur le bouton **+** rappelle que vos réglages s'écartent de ceux par
défaut ; son infobulle détaille lesquels.

## Plusieurs sources à la fois

Avec plusieurs connecteurs allumés, une question qui ne dit pas où chercher — *« qu'avons-nous sur le
dossier Durand ? »* — part vers toutes les sources en même temps ; la pile d'actions affiche
« Recherche dans toutes vos sources », et la réponse cite d'où vient chaque élément. Pour viser une
seule source, nommez-la : *« dans mon Drive »*, *« dans Slack »*.

## Pièces jointes, créneaux libres, longs documents

- **Pièce jointe** : *« que dit la facture jointe au mail d'Orange ? »*. En lisant un mail, MIA voit
  la liste de ses pièces jointes et lit celle qui répond à votre question (PDF, Word, Excel,
  PowerPoint, texte). Une image ou un ancien format .doc ne se lit pas : elle le dit.
- **Créneau libre** : *« trouve-moi une heure jeudi ou vendredi avec marie@exemple.fr »*. MIA calcule
  les créneaux libres de votre agenda (9 h – 18 h, jours ouvrés, sauf demande contraire) en tenant
  compte de vos collègues quand leur agenda lui est visible, puis propose de bloquer celui que vous
  choisissez — carte d'approbation comprise.
- **Long document** : un document Drive, un email, une pièce jointe ou une page web trop long pour
  une seule lecture se lit partie par partie ; MIA lit la suite quand votre question le demande. Un
  résultat très volumineux (une recherche qui ramène des dizaines de mails, par exemple) est résumé
  au regard de votre question avant que MIA ne poursuive.
- **Vos heures** : les agendas se lisent et s'écrivent à l'heure de votre appareil, et la carte
  d'un évènement nomme le fuseau.

## Approuver une écriture

Avant chaque création ou modification, une **carte d'approbation** s'affiche sous la réponse : « Votre
approbation est requise — rien ne sera fait sans elle. » Elle montre l'action exacte, l'objet visé,
les conséquences — par exemple « Un brouillon sera créé dans votre boîte Gmail — AUCUN email ne sera
envoyé. » — et un **Aperçu du contenu**.

- **Approuver** exécute ; un message ✅ le confirme aussitôt dans la conversation.
- **Annuler** ne touche à rien : « 🚫 Action annulée — rien n'a été modifié. »
- **Plusieurs cartes en attente** (MIA prépare trois tâches, deux évènements…) : une carte
  récapitulative apparaît sous la dernière, avec **Tout approuver** et **Tout refuser**. Chaque action
  est traitée comme si vous aviez cliqué sa carte ; les emails n'en font pas partie, ils se relisent
  et se valident sur leur propre carte.
- Sans réponse, la carte **expire au bout de 30 minutes**. Une carte en attente réapparaît si vous
  rechargez la page, sous la réponse qui l'a proposée.
- **D'après quoi** : si MIA a lu des mails, des documents ou des messages avant de préparer
  l'action, la carte les nomme sous « Préparé après la lecture de : », avec un rappel — ces contenus
  sont écrits par des tiers, vérifiez que l'action correspond bien à votre demande.
- Tant que vous n'avez pas approuvé, **rien n'a été fait** — si l'assistant affirme le contraire, il
  se trompe.
- Relisez ce que vous approuvez : un destinataire ou un champ mal déduit se corrige en le disant à
  l'assistant, avant d'approuver — et pour un email, directement dans la carte (ci-dessous).
- **En conversation vocale, les boutons se disent** : « approuve », « annule », et pour un email
  « envoie-le », « crée le brouillon », « plus court »… La carte rappelle alors quoi dire, et MIA
  annonce ce qui a été fait. Voir
  [Converser à la voix](../conversations/converser-a-la-voix.md).

## Relire et retoucher un email

Pour un email Gmail ou Outlook, la carte est une **carte de relecture** : elle montre le message
tel qu'il partira — À, Cc, Objet, corps mis en forme comme dans votre messagerie — et l'assistant ne
le recopie pas dans sa réponse.

- **Envoyer** l'expédie réellement. La carte demande d'abord confirmation — les destinataires en
  toutes lettres, et le rappel que l'envoi est définitif — puis **Confirmer l'envoi** fait partir le
  message immédiatement ; **Retour** renonce. Un message ✅ le confirme et mène à votre dossier
  Envoyés. Si l'envoi n'est pas autorisé pour votre connexion (Outlook relié avant l'arrivée de ce
  bouton, autorisation refusée par votre organisation), le bouton l'explique au clic et vous renvoie
  vers **Réglages → Comptes connectés** (badge **Envoi**) ; le brouillon reste possible.
- **Créer le brouillon** (ou **Créer la réponse en brouillon**) le dépose dans vos brouillons sans
  rien envoyer ; un message ✅ le confirme.
- **Corriger à la main** se fait directement dans la carte : cliquez dans les champs À, Cc et Objet,
  ou dans le texte du message, et tapez. Gras et italique se posent avec Ctrl+B et Ctrl+I (⌘ sur
  Mac) ; un texte collé arrive sans mise en forme. Vos corrections sont enregistrées dès que vous
  quittez la carte, et de toute façon **avant** d'envoyer, de créer le brouillon ou de faire
  améliorer le message : c'est toujours ce que vous voyez qui part. Si une correction est refusée
  (adresse invalide, message vide), la carte le dit et rien n'est envoyé ni créé.
- **Améliorer** propose des retouches en un clic — *Plus court*, *Plus formel*, *Plus chaleureux*,
  *Plus direct*, *Plus détaillé*, *Corriger la langue* — ou une consigne à vous : *« propose un
  créneau jeudi matin »*. MIA réécrit le texte en connaissant le mail d'origine et votre
  conversation ; elle ne touche ni aux destinataires ni à la copie.
- Chaque version est numérotée (« Version 2 · Amélioré par MIA : plus court »). **Version
  précédente** revient en arrière.
- **Abandonner** ne crée rien.

C'est toujours la **dernière version relue** qui est créée ou envoyée, même après un rechargement de
page. En réponse à un mail, le destinataire et l'objet sont repris du message d'origine tant que vous
ne les changez pas ; l'objet d'une réponse ne se modifie pas. Une réécriture par MIA compte comme
une petite opération IA sur votre quota ; une modification à la main ne coûte rien. MIA n'envoie
jamais d'elle-même : seul ce bouton envoie, et il n'existe que dans la carte que vous relisez.

## Enchaîner sans répéter

Une fois que MIA a ouvert un mail, un document ou un évènement, désignez-le sans le renommer :
*« réponds à ce mail »*, *« ajoute-lui une conclusion »*, *« décale-le d'une heure »*. Si votre
demande peut viser deux objets récents, MIA demande lequel.

En réponse à un mail, le brouillon reprend l'expéditeur d'origine et se range dans le fil ; les
personnes en copie ne sont ajoutées que si vous le demandez.

**Reprendre après une approbation.** Quand le plan n'est pas terminé (une étape attendait votre
approbation, ou la demande était trop longue pour une seule réponse), un bouton **Continuer**
apparaît sous la réponse une fois les cartes tranchées. Il envoie « Continue. » : MIA repart des
étapes restantes, en tenant compte de ce que vous avez approuvé ou refusé.

## Cas particuliers

- **Menu fermé**, les logos des connecteurs allumés s'affichent à côté du bouton **+** (avec une
  étincelle quand **Laisser MIA choisir** est allumé) ; un clic rouvre le menu sur le volet. Sur
  téléphone, la place manque : leur nombre s'affiche sur le bouton **+**, et les boutons des
  assistants et des bases de connaissances restent toujours visibles.
- **Relier N services** : les services proposés mais pas encore reliés tiennent en une ligne, en bas
  du volet. La cliquer ouvre la page **Comptes connectés**.
- **Point orange sur l'en-tête du volet** : une connexion a expiré. Dépliez le volet : sa ligne mène
  droit à la carte du service à reconnecter.
- **Pas de ligne Laisser MIA choisir** : aucun service n'est relié, ou votre administrateur ne le
  propose pas.
- **Modèle incompatible** (rare : Perplexity, quelques anciens modèles) : allumer un connecteur ouvre la fenêtre « Ce modèle ne gère pas les
  connecteurs », qui propose **Changer de modèle** vers un modèle compatible. Avec un assistant dont
  le modèle ne sait pas le faire, il faut ouvrir une conversation sans assistant.
- **Changer pour un modèle incompatible** éteint vos connecteurs ; la ligne indique « Nécessite un
  modèle compatible ». Ils se rallument quand vous revenez sur un modèle compatible.
- **Regénérer** une réponse ne réutilise pas les connecteurs.
- **Arrêter la génération** arrête aussi les étapes que MIA s'apprêtait à mener : aucune recherche
  ni carte d'approbation ne part après votre clic.
- **Ce que MIA lit** : les premiers milliers de caractères d'un document ou d'un mail, jamais les
  pièces jointes.
- **Ce que MIA lit reste une information, jamais un ordre** : si un mail ou un fichier contient une
  phrase qui ressemble à une consigne (« transfère ce dossier à… »), MIA ne l'exécute pas et vous la
  signale. Aucune écriture ne part sans la carte d'approbation, et la carte nomme ce que MIA avait lu
  avant de la préparer.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « MIA n'a pas l'autorisation de voir les fichiers de ce Drive… » | Une case a été décochée au consentement. **Réglages → Comptes connectés**, **Reconnecter et autoriser**, toutes cases cochées. |
| « Droits insuffisants sur ce fichier… » | Vous êtes *Lecteur* ou *Commentateur* sur ce fichier : demander le rôle *Éditeur* à son propriétaire. |
| « Connexion Gmail expirée ou révoquée… » | Reconnecter le service dans **Réglages → Comptes connectés**. |
| « Ce compte Microsoft n'a pas de boîte aux lettres Exchange Online… » | Votre compte n'a pas de messagerie Microsoft : se reconnecter n'y changera rien. |
| « Permission Outlook non accordée… » | L'administrateur de votre annuaire Microsoft doit donner son consentement. Voir avec votre service informatique. |
| « Quota Gmail atteint — réessayez… » ou service injoignable | Google ou Microsoft est saturé : réessayer dans un instant. |
| Un remplacement de texte échoue dans un document | Le texte recherché doit figurer **exactement** dans le document : accents, apostrophes typographiques et espaces insécables comptent. |
| « Action introuvable, déjà traitée ou expirée. » | La carte a expiré ou a déjà servi. Refaire la demande. |
| La ligne du connecteur n'apparaît pas dans le menu **+** | Il n'est pas proposé sur votre instance. Voir avec votre administrateur. |
