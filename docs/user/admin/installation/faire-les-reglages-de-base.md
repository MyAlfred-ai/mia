---
title: Faire les réglages de base
description: Prendre la main sur une instance neuve — une clé d'IA, le modèle par défaut, la connexion, les groupes et l'apparence, dans l'ordre.
sidebar:
  order: 3
---

Juste installée, MIA répond, mais son assistant ne peut encore rien dire : aucune clé d'IA n'est
enregistrée, et un seul compte existe. Ces réglages se font dans l'administration, sans toucher au
serveur. Chaque étape renvoie à sa page pour le détail.

## Avant de commencer

- MIA installée : [Installer MIA sur le serveur](installer-mia-sur-le-serveur.md).
- L'identifiant et le mot de passe du compte administrateur, donnés par le récapitulatif de fin
  d'installation.
- Une clé d'API d'au moins un éditeur d'IA.

## Étapes

1. **Connectez-vous** : ouvrez l'adresse de MIA, saisissez l'**Email** et le **Mot de passe** du
   récapitulatif, puis **Se connecter**.
2. **Ouvrez la liste de ce qui reste** : l'adresse de MIA suivie de `/setup/suivant`. L'écran
   « Compte créé — MIA est prête » donne chaque réglage avec son état réel et un lien vers sa page.
   Seule la première ligne est indispensable.
3. **Enregistrez une clé d'API** : le bouton **Admin**, en bas de la barre latérale, mène à
   l'administration ; dans le menu de gauche, choisissez **Modèles IA → Clés API**. Tant
   qu'il n'y en a aucune, une pastille ambre clignote sur **Modèles IA**. Une clé OpenAI, Mistral ou Gemini sert aussi à la recherche dans les
   bases de connaissances : sans elle, cette recherche reste possible, mais moins pertinente.
   → [Enregistrer les clés API](../modeles/enregistrer-les-cles-api.md)
4. **Activez les modèles, puis choisissez le modèle par défaut** et le modèle de secours. Sans ce
   dernier geste, des utilisateurs peuvent se retrouver sans aucun modèle.
   → [Activer les modèles](../modeles/activer-les-modeles.md),
   [Régler le comportement par défaut](../modeles/regler-le-comportement-par-defaut.md)
5. **Configurez la connexion** : **Organisation → Authentification** — Google, Microsoft, e-mail et
   mot de passe, domaines autorisés. Cette page ne s'enregistre pas seule : un bouton **Enregistrer**
   par section. → [Configurer la connexion](../utilisateurs/configurer-la-connexion.md)
6. **Organisez les groupes** : qui a droit à quels modèles, assistants et outils, avec quel quota.
   → [Organiser les groupes](../utilisateurs/organiser-les-groupes.md)
7. **Réglez les crédits** : la valeur du crédit, la marge, les quotas.
   → [Régler les crédits](../credits/regler-les-credits.md)
8. **Présentez l'entreprise** : **Organisation → Entreprise** — son nom et son descriptif, que
   l'assistant connaîtra dans chaque conversation, son logo et son modèle PowerPoint.
   → [Présenter l'entreprise](../instance/presenter-l-entreprise.md)
9. **Donnez à MIA les couleurs de l'organisation** : **Organisation → Marque & apparence** — nom,
   logos, couleurs, message d'accueil, langue. → [Personnaliser l'apparence](../instance/personnaliser-l-apparence.md)
10. **Vérifiez sur la page Diagnostic** : **Maintenance → Diagnostic**.
   → [Consulter les logs et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md)

## Résultat

« Compte créé — MIA est prête » coche sa première ligne, le Diagnostic ne signale plus de défaut, et un
compte de test obtient une réponse à sa première question. L'instance est en service.

## Ensuite

- **Les connecteurs** proposés à l'organisation : [Les connecteurs](../connecteurs/index.md).
- **Les outils** de génération — image, vidéo, présentation — et leur autorisation par groupe :
  [Les outils](../outils/index.md).
- **Les premiers assistants**, avec leurs bases de connaissances : [Les assistants](../assistants/index.md),
  [Les bases de connaissances](../bases-de-connaissances/index.md).
- **Les appareils** : dire aux utilisateurs comment mettre MIA sur leur ordinateur et leur téléphone.
  → [Installer MIA sur ses appareils](../../fonctionnalites/installer-mia-sur-ses-appareils.md)

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Email ou mot de passe incorrect. » | Recopiez le mot de passe du récapitulatif. Perdu, il se redéfinit sur le serveur, dans le dossier de MIA : `docker compose exec -T app php scripts/admin-create.php --email=<adresse> --reset`, le nouveau mot de passe — 8 caractères au moins — lu sur l'entrée standard, jamais sur la ligne de commande. |
| L'écran « Bienvenue dans MIA » s'ouvre à la place de la connexion | L'installeur n'a pas pu créer le compte. Créez-le ici : **Nom affiché**, **Adresse e-mail**, **Mot de passe** — 8 caractères au moins —, puis **Créer le compte et continuer**. |
| L'assistant ne répond rien | Aucune clé d'API active, ou aucun modèle par défaut : étapes 3 et 4. |
| Une ligne du Diagnostic est en défaut | Elle porte son remède. Ce qui ne se corrige pas dans l'interface relève du serveur : [Consulter les logs et le diagnostic](../instance/consulter-les-logs-et-le-diagnostic.md). |
