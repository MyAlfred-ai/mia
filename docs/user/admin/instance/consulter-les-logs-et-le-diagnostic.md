---
title: Consulter les logs et le diagnostic
description: Vérifier l'état de l'instance, retrouver la cause d'une erreur, et préparer un signalement à MyAlfred.
sidebar:
  order: 3
---

Deux écrans pour comprendre un problème avant d'appeler à l'aide : le **Diagnostic**, qui vérifie
l'instance, et les **Journaux**, qui racontent ce qui s'est passé.

## Le diagnostic

**Administration → Maintenance → Diagnostic.**

- Un **verdict** : « Tout est opérationnel », « À surveiller » ou « À corriger », avec le nombre de
  contrôles de chaque sorte.
- Une section **À traiter**, puis les **Contrôles au vert**, répartis en quatre familles : socle
  technique, configuration, base de données, services et fournisseurs — modèles, indexation des
  bases, connecteurs Google et Microsoft, outils dont le modèle est arrêté par son fournisseur (ou
  va l'être), budget IA du mois (à surveiller au seuil d'alerte ou au plafond), assistant d'aide,
  espace disque.
- **Copier le rapport** met dans le presse-papier un texte complet, avec les remèdes : à joindre à
  votre message à MyAlfred. **Actualiser** relance les contrôles.

**Ce que vous corrigez vous-même** : les modèles — **Ouvrir Modèles IA** —, l'indexation des bases —
**Enregistrer une clé**, **Réindexer les bases** —, les connecteurs, les outils — **Ouvrir les
outils** —, le budget IA — **Ouvrir le budget**. Tout le reste propose des
commandes serveur : c'est le travail de MyAlfred. La page n'exécute jamais rien d'elle-même.

## Les logs

**Administration → Maintenance → Diagnostic → Journaux.**

L'écran lit le journal du serveur et en fait un tableau de bord. Il ne modifie jamais le fichier.

- **En tête**, un verdict — **Calme**, **Stable**, **À surveiller** ou **Pannes graves** — et la
  période que couvre le journal (heures données dans votre fuseau horaire).
- **Les tuiles** comptent les **Critiques** (l'application ou une demande est tombée), les
  **Erreurs**, les **Avertissements** et les **Infos** (connexions, démarrages) de la période.
  Toucher une tuile affiche ou masque cette gravité ; les infos sont masquées par défaut. La tuile
  **Erreurs** compare avec la période précédente de même durée, quand le journal la couvre.
  **Problèmes distincts** dit combien de pannes différentes, dont les **nouvelles** ;
  **Dernière erreur** mène à la plus récente.
- **Période** : **1 h**, **6 h**, **24 h** (par défaut), **7 jours**, **30 jours**, **Tout**, ou
  **Personnalisé**. **Recherche** : plusieurs mots (tous requis), « mot exact » entre guillemets,
  **-mot** pour exclure ; un identifiant d'incident collé tel quel ouvre l'incident.
- **Domaines** : IA & modèles, Créations & fichiers, Bases & RAG, Connecteurs, Tâches & workflows,
  File & workers, Connexion & sécurité, Base & stockage, Coûts & usage, PHP — chacun avec son nombre
  d'entrées.
- **La frise** montre le volume dans le temps, par gravité. Toucher une barre zoome sur ce
  créneau.
- **Problèmes** regroupe les entrées qui disent la même chose : une panne répétée 2 000 fois est
  une seule ligne, avec sa fréquence, sa courbe, sa première et sa dernière apparition, et la
  mention **Nouveau** si elle n'était pas apparue avant la période. Tri par **Gravité**, **Plus
  récents** ou **Plus fréquents**. Toucher un problème ouvre toutes ses occurrences.
- **Flux** liste les entrées une à une, par journée. Une entrée s'ouvre sur son détail : quand,
  gravité, domaine, emplacement dans le code, pile d'appels lisible (le code de MIA en évidence),
  cause, et les gestes **Copier l'entrée**, **Copier le lien**, **Autour de cet instant** (tout ce
  qui s'est passé cinq minutes avant et après), **Même problème**. Au clavier : **j** et **k** pour
  passer d'une entrée à l'autre, **/** pour chercher.
- **En direct** ajoute les nouvelles entrées au fil de l'eau, sans recharger la page.
- **Exporter** télécharge les entrées filtrées (texte) ; **Actualiser** relit le journal.

Seule la fin d'un journal très volumineux est analysée (la période couverte s'affiche en tête) ;
au-delà de 100 Mo, la mention **Fichier volumineux** rappelle de le faire tourner sur le serveur.

Les logs contiennent des adresses e-mail et IP : ne les transmettez qu'à MyAlfred.

Un troisième onglet, **Agent**, mesure ce que fait MIA quand elle se sert de ses outils : voir
[Suivre le travail de l'agent](suivre-le-travail-de-l-agent.md).

## Retrouver une erreur

**Un utilisateur voit une page d'erreur avec un « Identifiant d'incident ».** Demandez-lui ces douze
caractères et collez-les dans la recherche des **Journaux** : l'incident s'ouvre, quelle que soit la
période, avec son message, sa pile d'appels et sa cause. **Autour de cet instant** montre ce qui
s'est passé juste avant.

**Un refus de connexion Microsoft.**

1. **Journaux**, plage **24 h**, recherche **AADSTS**.
2. La ligne donne le code d'erreur Microsoft, qui nomme la cause.

| Code | Cause | Quoi faire |
|---|---|---|
| **AADSTS65001** | Consentement manquant | Accorder le consentement administrateur dans Entra |
| **AADSTS50105** | Personne non affectée à l'application | L'ajouter dans **Utilisateurs et groupes**, ou lever l'attribution obligatoire |
| **AADSTS50020** | Compte hors de l'annuaire autorisé | Élargir l'annuaire, ou utiliser un compte de l'organisation |
| **AADSTS9002326** | Adresse de retour déclarée en « application monopage » | La déclarer en plateforme **Web** |
| **AADSTS50011** | Adresse de retour non déclarée | Ajouter l'URI affichée par la page Authentification |
| **AADSTS7000215**, **AADSTS7000222** | Secret invalide ou expiré | Coller la **valeur** du secret, ou en créer un nouveau |

D'autres recherches utiles : « Domaine refusé pour », « Connexion refusée (compte inactif) ».

**Un assistant qui n'utilise pas un connecteur.** Recherche **[connecteurs]** : la ligne dit pourquoi
— modèle incompatible, connecteur masqué, identifiants absents, compte non relié.

## Signaler un problème à MyAlfred

1. **Diagnostic → Copier le rapport**.
2. Si l'erreur est précise : l'identifiant d'incident, ou les lignes de logs concernées.
3. Ce que faisait l'utilisateur, et quand.
