---
title: Configurer la connexion
description: Activer Google, Microsoft ou le mot de passe, et restreindre l'accès à vos domaines.
sidebar:
  order: 4
---

**Administration → Organisation → Authentification**

Trois moyens d'entrer, activables indépendamment. La page de connexion n'affiche que ceux qui sont à
la fois **activés** et **renseignés** : un fournisseur sans identifiants n'apparaît jamais, plutôt que
d'offrir un bouton qui mène à une erreur.

**Cette page ne s'enregistre pas seule** : chaque section — Google, Microsoft, E-mail et mot de passe,
Domaines autorisés, Message sur la page de connexion — a son bouton **Enregistrer**, et la
confirmation s'affiche dans la section.

Le cadre général — rôles, groupes, approbation des comptes — est dans
[Les utilisateurs et les accès](index.md).

## L'URI de redirection

Affichée en haut de page, en lecture seule, avec un bouton **Copier**. C'est l'adresse à déclarer
**telle quelle** chez Google et chez Microsoft.

**Une seule URI suffit** pour les deux connexions et les six connecteurs personnels du chat. Seul
OneDrive, qui sert aux bases de connaissances, utilise sa propre application et sa propre adresse —
voir [Configurer les sources synchronisées](../bases-de-connaissances/configurer-les-sources-synchronisees.md).

## Google

1. Dans la console Google Cloud, créez un **ID client OAuth 2.0** de type **Application Web**.
2. Ajoutez-y l'URI de redirection affichée par la page.
3. Recopiez l'**ID client** et le **secret client**, puis activez l'interrupteur.

Le secret est stocké chiffré et n'est jamais réaffiché : un champ laissé vide conserve la valeur
enregistrée.

Ces identifiants servent **aussi aux connecteurs** Drive, Gmail, Agenda et Tasks — même si
l'interrupteur de connexion par Google reste éteint. Les API, les autorisations, l'audience et les
réglages de la console d'administration Google :
[Autoriser MIA dans Google Workspace](../connecteurs/autoriser-mia-dans-google-workspace.md).

## Microsoft

1. Dans le portail Microsoft Entra, inscrivez une application.
2. Ajoutez l'URI de redirection, en type **Web**.
3. Dans **Certificats & secrets**, créez un secret et copiez sa **valeur** — pas son identifiant.
4. Renseignez l'ID d'application, le secret et l'annuaire, puis activez l'interrupteur.

Ces identifiants servent aux connecteurs Outlook et Calendrier Outlook même si l'interrupteur de
connexion par Microsoft reste éteint. Les autorisations exactes, le consentement administrateur et les
réglages d'annuaire qui bloquent encore les utilisateurs :
[Autoriser MIA dans Microsoft Entra ID](../connecteurs/autoriser-mia-dans-microsoft-entra-id.md).

### Le champ Annuaire

| Valeur | Qui peut se connecter |
|---|---|
| `common` *(défaut)* | Tout compte Microsoft, professionnel ou personnel |
| `organizations` | Comptes professionnels uniquement |
| `consumers` | Comptes Microsoft personnels uniquement |
| **ID de votre annuaire**, ou l'un de ses domaines vérifiés | Votre organisation seule |

Une inscription à **locataire unique** — le choix par défaut d'Entra — exige l'ID de l'annuaire :
laissé à `common`, le champ fait échouer la connexion avec le code **AADSTS50194**.

> **Sur un annuaire ouvert, Microsoft ne garantit pas qu'une adresse appartient à la personne qui se
> connecte** : n'importe qui peut créer un annuaire et y déclarer l'adresse de son choix. MIA refuse
> donc de rattacher une connexion Microsoft à un compte existant dans ce cas. Renseigner **l'ID de
> votre annuaire** est la solution recommandée.

## Restreindre aux domaines de votre organisation

Un domaine par ligne dans **Domaines autorisés** — 20 au plus. Seules ces adresses peuvent **créer un
compte** par Google ou Microsoft. Vide = aucune restriction.

- La règle ne s'applique qu'à la **création** : un compte existant continue de se connecter même si
  son domaine sort de la liste. **Modifier ce champ n'éjecte jamais personne.**
- Déclarer vos domaines vaut reconnaissance : une connexion Microsoft venant d'un de ces domaines peut
  alors rejoindre un compte existant.

## Afficher un message sur la page de connexion

La section **Message sur la page de connexion** affiche un texte sous les boutons : une consigne, un
contact d'assistance. 500 caractères au plus, retours à la ligne conservés, jamais traduit. Un aperçu
montre le rendu.

## Vérifier avant de mettre en service

Chaque fournisseur porte un bouton **Tester les identifiants** : il vérifie l'annuaire, l'ID client et
le secret **sans ouvrir d'écran de connexion et sans rien enregistrer**. Les erreurs sont nommées.

## On ne peut pas se verrouiller dehors

Si un enregistrement devait ne laisser **aucun** moyen de connexion utilisable, MIA réactive d'office
la connexion par mot de passe et vous le signale.

MyAlfred peut aussi configurer, côté serveur, un accès administrateur de secours : la page de
connexion affiche alors le lien **Connexion admin locale ›**, qui fonctionne quels que soient les
interrupteurs.

## En cas de problème avec Microsoft

Un refus vient rarement d'un identifiant mal recopié. MIA nomme la cause :

| Cause | Quoi faire |
|---|---|
| Consentement manquant | **Applications d'entreprise → Autorisations → Accorder le consentement administrateur**. Pour les connecteurs, il ne suffit pas toujours : voir [Autoriser MIA dans Microsoft Entra ID](../connecteurs/autoriser-mia-dans-microsoft-entra-id.md) |
| Inscription à locataire unique, annuaire laissé à `common` (**AADSTS50194**) | Renseigner l'ID de l'annuaire dans le champ **Annuaire** |
| Compte non autorisé | Ajouter la personne dans **Utilisateurs et groupes**, ou passer « Attribution requise » à Non |
| Compte hors annuaire | Élargir l'annuaire, ou se connecter avec un compte de l'organisation |
| Redirection en « monopage » | Retirer l'URI de **Application monopage (SPA)** et l'ajouter sous **Web** |
| URI non déclarée | Ajouter l'URI affichée en haut de page, plateforme **Web** |
| Secret refusé ou expiré | Coller la **valeur** du secret, ou en créer un nouveau |

Sur la page de connexion, le message reste volontairement générique : le détail figure dans les
logs. **Maintenance → Diagnostic → Journaux**, plage **24 h**, recherchez **AADSTS** : le code d'erreur Microsoft
nomme la cause — voir [Consulter les logs et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md).
