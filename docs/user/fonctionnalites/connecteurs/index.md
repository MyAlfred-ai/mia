---
title: Les connecteurs
description: Donner à MIA l'accès à vos documents, mails, agendas et tâches Google ou Microsoft, à vos conversations Slack et Microsoft Teams et à Internet — jamais sans votre feu vert.
sidebar:
  order: 5
---

Un **connecteur** relie MIA à une source extérieure : votre Google Drive, votre messagerie Gmail ou
Outlook, vos agendas, vos tâches, vos conversations Slack ou Microsoft Teams — et Internet. Une fois allumé, MIA peut y
chercher, y lire, et préparer des écritures que vous approuvez une à une.

![Le menu + ouvert dans une conversation : Joindre des fichiers en tête, la section Créer avec Image, Page web, Site illustré et Itinéraire et leur coût en crédits, puis les Sources : Recherche Internet allumée, et le volet Connecteurs déplié, où Emails Gmail est allumé à côté de Fichiers Google Drive et Agenda Google. Le logo de Gmail s'affiche à côté du bouton +.](../../images/menu-plus.png)

## À quoi ça sert

À travailler sur **vos** données sans copier-coller : *« résume le dernier mail de Paul sur le
devis »*, *« ajoute une conclusion au compte rendu du comité »*, *« qu'ai-je jeudi après-midi ? »*,
*« coche la tâche de relance Leroy »*. MIA va chercher l'information là où elle est, avec vos droits.

## Comment ça marche

**Deux temps : relier, puis allumer.**

1. **Relier** le service, une fois pour toutes, depuis **Réglages → Comptes connectés** : vous autorisez
   MIA auprès de Google ou de Microsoft, avec votre propre compte.
2. **Allumer** le connecteur dans la conversation, depuis le volet **Connecteurs** du menu **+**,
   quand vous en avez besoin. Un service que vous venez de relier est déjà allumé.
   Il reste allumé pour vos messages suivants et vos nouvelles conversations, sur ce navigateur,
   jusqu'à ce que vous l'éteigniez.

**Ou laisser MIA choisir.** Avec l'interrupteur **Laisser MIA choisir** du menu **+**, vous n'avez
plus à allumer chaque connecteur avant de poser votre question : MIA allume elle-même, pour la
réponse en cours, un service relié dont votre demande a besoin, et laisse les autres éteints. La pile
d'actions l'indique (« Activation d'un connecteur — Gmail »), et les écritures restent soumises à
votre approbation. En [conversation vocale](../conversations/converser-a-la-voix.md), ce mode vaut
d'office quand il est proposé : à la voix, personne n'ouvre le menu **+**.

La **Recherche Internet** fait exception : rien à relier, elle est allumée par défaut.

**Quatre conditions** pour qu'un connecteur serve dans une réponse : il est **proposé** par votre
administrateur, votre compte est **relié**, il est **allumé** dans le menu **+** — ou **Laisser MIA
choisir** l'est —, et le **modèle** de la conversation sait s'en servir : le bandeau affiche alors
**✓ Connecteurs**.

```mermaid
flowchart TB
  accTitle: Les quatre conditions, dans l'ordre
  accDescr: Le connecteur est proposé par l'administrateur, relié depuis Réglages puis Comptes connectés, allumé dans le menu plus ou choisi par MIA quand Laisser MIA choisir est allumé, et le modèle de la conversation sait s'en servir. MIA peut alors s'appuyer dessus dans sa réponse.
  A["Proposé par votre administrateur"] --> B["Relié — Réglages → Comptes connectés"]
  B --> C["Allumé — menu +, ou choisi par MIA"] --> D["Modèle compatible — bandeau ✓ Connecteurs"]
  D --> R(["MIA s'en sert dans sa réponse"])
```

**Si une connexion expire**, MIA vous le dit sans bruit : une **pastille ambre** sur votre avatar, une
autre sur l'entrée **Comptes connectés** du menu de vos réglages, sur la ligne du connecteur dans le
menu **+**, et sur la carte du service — sa bulle, au survol ou en la touchant, nomme le service. Un
clic mène à la carte du service, à reconnecter ou à retirer :
[Relier un service](relier-un-service.md#quand-une-connexion-expire).

**Ce que MIA peut faire, service par service.**

| Connecteur | Consultation | Écriture, toujours soumise à votre approbation |
|---|---|---|
| **Fichiers Google Drive** | Rechercher, lire, parcourir les dossiers | Créer et modifier des Docs et des Sheets, les mettre en forme, dupliquer, renommer, déplacer |
| **Emails Gmail** | Rechercher et lire vos mails **et leurs pièces jointes** | Préparer un **email**, nouveau ou en réponse, que vous enregistrez en brouillon ou **envoyez** d'un clic |
| **Emails Outlook** | Rechercher et lire vos mails **et leurs pièces jointes** | Préparer un **email**, nouveau ou en réponse, que vous enregistrez en brouillon ou **envoyez** d'un clic |
| **Agenda Google** | Consulter vos agendas, trouver un **créneau libre** | Créer ou modifier une **réunion** : invités, **répétition**, visibilité **privée** ou publique, **disponible** ou occupé — Google Agenda écrit aux invités dès l'approbation, sauf si vous dites de ne pas leur envoyer d'invitation |
| **Agenda Outlook** | Consulter vos agendas, trouver un **créneau libre** ou une **salle libre** | Créer ou modifier une **réunion** : invités, **salle**, réunion Teams, répétition, rappel — Outlook écrit aux invités dès l'approbation |
| **Tâches Google** | Lister vos tâches | Ajouter, modifier, cocher, supprimer — suppression désactivable |
| **Tâches Microsoft To Do** | Lister vos tâches, lire leurs notes et leurs étapes | Ajouter, modifier, cocher, poser un **rappel à une heure** — jamais de suppression |
| **Messages Slack** | Rechercher, lire un canal, dérouler un fil | Publier un message en votre nom, nouveau ou en réponse — **visible aussitôt** |
| **Messages Teams** | Rechercher, lire une conversation ou un canal, dérouler un fil | Publier un message en votre nom dans une conversation, un canal ou un fil — **visible aussitôt** |
| **Recherche Internet** | Chercher sur le web public, **lire une page** dont vous donnez l'adresse | — |

**Aucun email ne part sans votre clic** : MIA n'a aucun outil d'envoi, seul le bouton **Envoyer** de la
carte de relecture envoie, après votre confirmation.
**Aucun fichier, aucun email, aucun évènement n'est jamais supprimé.** Les **pièces jointes** des mails (PDF, Word, Excel, PowerPoint —
anciens .doc, .xls et .ppt compris —, OpenDocument, EPUB, emails joints, archives ZIP, et tout fichier texte :
CSV, JSON, invitation .ics, code…) se lisent quand votre question porte dessus ; une image ne se lit pas, et
MIA le dit. Un long document se lit **en entier, partie par partie**.

**Les heures sont les vôtres.** Les agendas, et les échéances et rappels de Microsoft To Do, se lisent et s'écrivent à l'heure de votre appareil : un
évènement demandé « demain à 14 h » est créé à 14 h chez vous, et la carte d'approbation nomme le
fuseau. Pour un **créneau libre**, MIA tient compte aussi des disponibilités des collègues que vous
nommez, quand leur agenda lui est visible (même organisation, ou agenda partagé) — sans jamais en
voir le contenu.

**Trois écritures sont immédiates et sans retour.** Un message Slack ou Teams approuvé n'est pas un
brouillon : il **part dans le canal** ou la conversation et devient visible de tous — MIA ne peut
ensuite ni le modifier ni le supprimer, et ne voit jamais un canal privé dont vous n'êtes pas membre.
Un email **envoyé** depuis sa carte part de même, à ses destinataires ; c'est pourquoi le bouton
demande confirmation. Une **réunion Outlook avec invités** leur envoie l'**invitation** dès
l'approbation, et chaque modification les en informe : la carte les nomme avant.

**Toute écriture passe par votre approbation.** MIA ne modifie rien pendant qu'elle répond : elle
dépose une **carte d'approbation** — l'action exacte, l'objet visé, ses conséquences, un aperçu —,
et rien n'est fait tant que vous n'avez pas cliqué **Approuver**. Quand plusieurs cartes attendent,
**Tout approuver** et **Tout refuser** les tranchent d'un coup, emails exceptés. Sans réponse, la
carte expire au bout de 30 minutes — une semaine pour une carte préparée par une
[tâche programmée](../conversations/programmer-une-tache.md). Pour un email, la carte montre le message tel qu'il partira et vous laisse le
**modifier**, le faire **améliorer** par MIA ou l'**abandonner** avant de créer le brouillon ou de
l'**envoyer**.

**La carte dit d'après quoi.** Si MIA a lu des mails, des documents ou des messages avant de
préparer une action, la carte les nomme sous « Préparé après la lecture de : ». Ces contenus sont
écrits par d'autres que vous : vérifiez que l'action correspond bien à **votre** demande, et pas à
une phrase glissée dans un mail.

**Une question, toutes les sources.** Avec plusieurs connecteurs allumés, *« qu'avons-nous sur le
dossier Durand ? »* interroge toutes les sources en même temps — mails, Drive, Slack, agendas, tâches,
bases de connaissances — et la réponse cite d'où vient chaque élément. Gmail et Outlook, ou vos deux
agendas, se consultent aussi d'un seul coup. Une source en panne est nommée ; les autres répondent.

**Un travail complet, sans rien oublier.** Pour une demande qui porte sur **tout** — tous les mails
d'un client, tous les fichiers d'un dossier, chaque ligne d'un classeur —, MIA range les éléments
dans une **liste** au lieu de les lire un par un, puis croise, filtre et compte dessus :
*« quels clients de ce fichier ne m'ont pas écrit depuis septembre ? »*. Les chiffres de sa réponse
sont comptés, pas estimés, et la liste complète vous est remise en Excel. La table ne s'affiche pas
dans la conversation : un bouton **« Voir »**, sous la réponse, l'ouvre dans un volet où vous la
parcourez, la renommez ou la supprimez. Voir
[Recouper beaucoup d'éléments](recouper-des-sources.md) et
[Voir, renommer ou supprimer une table](voir-ses-tables.md). Elle peut ensuite **lire chaque ligne**
(classer, résumer, relever une valeur) et **préparer une action par ligne** — un brouillon, une
tâche — que vous approuvez en une fois : voir
[Classer, compléter et agir ligne par ligne](traiter-chaque-ligne.md).

**MIA garde le fil.** Un mail, un document ou un évènement qu'elle vient d'ouvrir peut être désigné
sans le renommer : *« réponds à ce mail »*, *« ajoute-lui une conclusion »*.

**Le coût.** Google et Microsoft ne coûtent rien ; ce qui compte, ce sont les tokens du modèle, qui
relit ce qu'il a trouvé, et chaque recherche Internet. Voir
[La gestion des crédits](../credits/index.md).

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Autoriser MIA à accéder à votre Drive, votre messagerie, vos agendas ou vos tâches | [Relier un service](relier-un-service.md) |
| Faire chercher, lire ou préparer un brouillon dans une conversation | [Utiliser un connecteur](utiliser-un-connecteur.md) |
| Faire traiter des centaines de mails, de fichiers ou de lignes : tout collecter, croiser, compter, exporter | [Recouper beaucoup d'éléments](recouper-des-sources.md) |
| Faire classer ou compléter chaque ligne d'une liste, puis préparer un brouillon ou une tâche pour chacune | [Classer, compléter et agir ligne par ligne](traiter-chaque-ligne.md) |
| Ouvrir une table établie par MIA, la parcourir, la télécharger, la renommer ou la supprimer | [Voir, renommer ou supprimer une table](voir-ses-tables.md) |
| Faire préparer une réunion Google Agenda — invités, répétition, privée ou publique, disponible ou occupé — ou en modifier une | [Organiser une réunion dans Google Agenda](organiser-une-reunion-google.md) |
| Faire préparer une réunion Outlook — invités, salle, Teams, répétition — ou en modifier une | [Organiser une réunion dans Outlook](organiser-une-reunion.md) |
| Résumer un canal Slack, ou y publier un message | [Travailler avec Slack](travailler-avec-slack.md) |
| Résumer une conversation ou un canal Microsoft Teams, ou y publier un message | [Travailler avec Microsoft Teams](travailler-avec-teams.md) |
| Faire vérifier une information en ligne | [Rechercher sur Internet](rechercher-sur-internet.md) |
| Relier Claude à Excel, Word ou PowerPoint avec votre compte MIA, pas à pas, sans compte Claude | [Utiliser Claude dans Excel, Word ou PowerPoint](claude-dans-office.md) |

## Ce que vous pouvez régler

- Les services que vous reliez, et ceux que vous déconnectez.
- Les connecteurs allumés dans chaque conversation, ou **Laisser MIA choisir** pour qu'elle les
  allume elle-même au besoin.
- Pour Google Tasks : votre liste par défaut, la fenêtre d'échéance, et l'autorisation de supprimer
  des tâches.

Les connecteurs proposés dépendent de votre administrateur : un connecteur qu'il masque disparaît du
menu **+** pour toute l'organisation.

## Exemples

**Répondre à un mail sans quitter MIA.** Vous allumez **Emails Gmail** : *« résume le dernier mail
de Paul Durand sur le devis »*, puis *« réponds-lui qu'on valide »*. La carte annonce « Répondre à
un email (brouillon Gmail) » et montre la réponse entière ; un clic sur *Plus chaleureux* la
réécrit, vous cliquez **Créer la réponse en brouillon**, et le brouillon attend dans Gmail, prêt à
être envoyé par vous.

**Compléter et ranger un document.** Avec **Fichiers Google Drive** : *« ajoute une conclusion au
compte rendu du comité du 12 mars »*, puis *« déplace-le dans Clients / Durand »*. Deux cartes, deux
approbations.

**Préparer sa semaine.** Avec **Agenda Outlook** et **Tâches Google** : *« liste mes rendez-vous de
la semaine et les tâches qui arrivent à échéance »*.

**Ne rien oublier.** Avec **Tâches Microsoft To Do** : *« ajoute "Relancer Durand" pour vendredi,
rappel jeudi à 9 h »*. La carte nomme la liste, l'échéance et l'heure du rappel ; une fois approuvée,
c'est To Do qui vous prévient jeudi.

**Ne rien allumer d'avance.** **Laisser MIA choisir** allumé, rien d'autre : *« retrouve le devis
Durand dans mes mails et ajoute une tâche de relance pour vendredi »*. MIA active Gmail, puis Tâches
Google ; la pile d'actions le montre, et la tâche attend votre approbation. À *« merci ! »*, elle
n'active rien.

**Vérifier une information récente.** **Recherche Internet** allumée, modèle **✓ Internet** : *« quel
est le taux du livret A ce mois-ci ? »* — MIA cherche avant de répondre.

**Caser une réunion.** Avec **Agenda Google** : *« trouve une heure libre jeudi ou vendredi avec
marie@exemple.fr »*. MIA propose des créneaux communs, puis, sur votre accord, prépare l'évènement à
approuver — Marie invitée.

**Un comité mensuel privé.** Avec **Agenda Google** : *« crée le comité de direction le premier lundi
de chaque mois à 9 h avec paul@exemple.fr, en privé, jusqu'à fin juin »*. La carte écrit la
répétition en clair (« chaque mois, le premier lundi du mois jusqu'au 2027-06-30 »), annonce la
visibilité privée et l'invitation de Paul. Plus tard : *« ajoute Carla à toutes les séances »*.

**Lancer un point d'équipe récurrent.** Avec **Agenda Outlook** : *« organise un point Teams chaque
mardi à 10 h avec sophie.bernard@example.com et julien.moreau@example.com, jusqu'à fin
janvier »*. La carte nomme les deux invités, annonce la réunion Teams et la répétition ; à
l'approbation, Outlook leur envoie l'invitation. Plus tard : *« décale celui de la semaine prochaine
à 11 h »*.

**Lire la facture jointe.** Avec **Emails Outlook** : *« que dit la facture jointe au dernier mail
d'Orange ? »*. MIA ouvre le mail, lit le PDF joint et cite son montant.

**Rattraper une discussion.** Avec **Messages Slack** : *« résume #projet-x depuis hier »*, puis
*« préviens le canal que la livraison passe à jeudi »*. La carte d'approbation rappelle que le
message partira vraiment ; vous approuvez, il est publié en votre nom.

## Voir aussi

- [Les modèles de LLM](../modeles/index.md) — presque tous savent utiliser les connecteurs ; le bandeau ✓ Connecteurs le dit
- [Les bases de connaissances](../bases-de-connaissances/index.md) — indexer un dossier Drive plutôt
  que le lire à la demande
- [Les outils](../outils/index.md) — produire, plutôt que consulter
