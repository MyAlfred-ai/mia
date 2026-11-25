---
title: Organiser une réunion dans Google Agenda
description: Faire préparer par MIA une réunion Google Agenda — invités, répétition, visibilité privée ou publique, disponible ou occupé — ou modifier un évènement existant, après votre approbation.
sidebar:
  order: 3
---

Avec votre Google Agenda relié, MIA prépare de vraies réunions : elle trouve un créneau, invite les
personnes que vous nommez, fait **répéter** la réunion comme vous le dites, la rend **privée** ou
**publique**, et vous montre **disponible** ou **occupé** pendant ce temps. Elle sait aussi
**modifier** un évènement existant : ajouter ou retirer quelqu'un, le décaler, le passer en privé.
Rien n'est fait avant votre clic sur **Approuver**.

## Avant de commencer

- Votre Google Agenda est relié, avec le badge **Création et modification d'évènements ✓** dans
  **Réglages → Comptes connectés** : [Relier un service](relier-un-service.md).
- L'interrupteur **Agenda Google** est allumé dans le volet **Connecteurs** du menu **+**, ou
  **Laisser MIA choisir** l'est : [Utiliser un connecteur](utiliser-un-connecteur.md).
- Vous connaissez l'**adresse email** de chaque invité, ou MIA peut la trouver dans vos mails ou votre
  agenda.

## Créer une réunion

1. Demandez-la comme à un collègue : *« Organise un point budget avec sophie.bernard@example.com et
   julien.moreau@example.com le lundi à 9 h 30, une semaine sur deux jusqu'à fin juin, sauf le
   28 décembre. Léa Petit en facultatif. Mets-la en privé. »*
2. Si vous ne donnez pas d'heure, demandez plutôt un créneau : *« trouve une heure libre jeudi avec
   Sophie et Julien »*. MIA propose des créneaux communs, puis prépare la réunion sur celui que vous
   choisissez.
3. Une **carte d'approbation** s'affiche sous la réponse. Relisez-la : titre, date et heure, lieu, la
   **répétition** écrite en clair, la visibilité, et surtout **la liste des invités**.
4. Cliquez **Approuver**.

Ce que vous pouvez préciser :

| Vous dites… | Dans Google Agenda |
|---|---|
| *« avec Sophie et Julien »* (avec leurs adresses) | Invités **obligatoires** |
| *« Léa en facultatif »* | Invitée **facultative** |
| *« tous les jours »*, *« tous les jours ouvrés »*, *« chaque semaine le mardi et le jeudi »*, *« un mardi sur deux »* | Une **répétition** quotidienne ou hebdomadaire |
| *« chaque mois le 15 »*, *« le premier lundi du mois »*, *« le dernier vendredi du mois »*, *« chaque trimestre »* | Une **répétition** mensuelle (tous les 3 mois pour un trimestre) |
| *« chaque année »* | Une **répétition** annuelle |
| *« jusqu'à fin juin »*, *« dix fois »* | La **fin** de la série ; sans précision, elle ne s'arrête pas |
| *« sauf le 25 décembre »* | Une date **sautée** |
| *« en privé »* | **Visibilité privée** : qui voit votre agenda n'y voit qu'un créneau occupé, sans le détail |
| *« en public »* | **Visibilité publique** : le détail est visible de toute personne qui a accès à votre agenda |
| *« sans bloquer mon agenda »*, *« marque-moi disponible »* | Vous apparaissez **disponible** : le créneau reste libre |
| *« sans leur envoyer d'invitation »* | Les invités sont ajoutés **sans email** (voir plus bas) |

Le début est la **première occurrence** : pour *« chaque jeudi »*, la réunion commence un jeudi. Si
votre demande ne tombe pas juste (un *« dernier vendredi du mois »* qui commence un 10), MIA vous le
dit et vous propose la bonne date au lieu de créer une série fausse.

## Modifier un évènement

1. Désignez l'évènement : *« ajoute Carla à la réunion budget »*, *« retire Bob de l'invitation »*,
   *« décale le comité de jeudi à 15 h »*, *« passe le point de demain en privé »*, *« marque-moi
   disponible pendant le séminaire »*. Un évènement que MIA vient de créer ou de lire se désigne sans
   le renommer : *« ajoute-lui Paul »*.
2. Pour une réunion qui se répète, dites si vous parlez de **toute la série** (*« ajoute Carla au
   point hebdo, à toutes les séances »*) ou d'**une seule séance** (*« celle de lundi prochain »*).
3. MIA retrouve l'évènement et affiche une carte **Modifier un évènement Google Agenda** : la réunion
   visée, **ce qui change**, et **qui sera prévenu**.
4. Cliquez **Approuver**.

Déplacer le début **garde la durée** ; donner une date sans heure change le jour en gardant l'heure,
une heure seule change l'heure en gardant le jour.

## Résultat

Un message ✅ confirme dans la conversation, avec le lien de l'évènement dans Google Agenda. Il dit à
qui l'invitation est partie, la répétition créée, ou qui a été prévenu de la modification.

**Inviter, c'est écrire.** Une personne que vous invitez voit la réunion apparaître dans son Google
Agenda. Par défaut, Google Agenda lui envoie aussi **l'invitation par email**, en votre nom, dès votre
approbation, puis un email à chaque changement de l'horaire, du lieu ou de la liste des invités. La
carte nomme chaque invité avant que vous approuviez : une invitation partie ne se rattrape pas.

Si vous préférez ne rien envoyer, dites-le (*« sans leur envoyer d'invitation »*) : les invités sont
ajoutés sans email. Attention, une adresse **hors Google** ne sera alors prévenue de rien.

## Cas particuliers

- **Une réunion organisée par quelqu'un d'autre** : vous n'en réglez que votre visibilité et votre
  disponibilité. Le reste appartient à l'organisateur ; MIA le nomme.
- **La visibilité d'une réunion qui se répète** se règle sur toute la série : Google ignore une
  visibilité moins stricte posée sur une seule séance.
- **Sur toute une série**, l'horaire ne se change que d'heure (*« passe le point hebdo à 10 h »*) ;
  déplacer les jours d'une série, ou changer sa répétition, se fait dans Google Agenda.
- **Un « chaque mois le 31 »** saute les mois plus courts, et un *« 5e lundi du mois »* n'existe pas
  tous les mois : MIA propose alors le dernier lundi du mois.
- **Une répétition plus fine** (deux règles mêlées, une date ajoutée hors de la règle) se règle dans
  Google Agenda : MIA vous le dit plutôt que de l'approcher en silence.
- **Ni suppression ni annulation** : MIA n'a aucun outil pour cela. Annulez la réunion dans Google
  Agenda.
- **Une adresse incomplète** (*« invite Paul »*) : MIA cherche l'adresse dans vos mails ou votre agenda,
  ou vous la demande ; elle ne la devine jamais.
- **Vos heures** : la réunion est créée à l'heure de votre appareil, et la carte nomme le fuseau.
- **Tâche programmée ou workflow « sans validation »** : une réunion avec invités n'est créée ou
  modifiée seule que si la tâche a aussi le droit d'**envoyer les emails**. Sinon, elle vous attend
  dans une carte. Voir [Programmer une tâche](../conversations/programmer-une-tache.md).

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Adresse d'invité invalide… » | Donnez l'adresse email complète de chaque invité. |
| « Le début (…) doit tomber un des jours de la récurrence » | Choisissez comme début le premier jour voulu de la série. |
| « Le … n'est pas une date de cette série » | La date à sauter doit être un jour où la réunion a lieu : vérifiez-la. |
| « Seul l'organisateur … peut modifier… » | La réunion ne vous appartient pas : demandez la modification à son organisateur. |
| « … ne fait pas partie des invités de cet évènement » | Vérifiez le nom : demandez à MIA de lire l'évènement pour voir qui est invité. |
| « Autorisation de création d'évènements non accordée… » | **Réglages → Comptes connectés**, **Reconnecter et autoriser**. |
| « Action introuvable, déjà traitée ou expirée. » | La carte a expiré (30 minutes) ou a déjà servi : refaites la demande. |

## Voir aussi

- [Utiliser un connecteur](utiliser-un-connecteur.md) — allumer, approuver, comprendre la trace
- [Relier un service](relier-un-service.md) — connecter votre Google Agenda
- [Organiser une réunion dans Outlook](organiser-une-reunion.md) — la même chose avec le calendrier Outlook
