---
title: Ajouter un outil
description: Créer un outil de génération en trois étapes — son type, son moteur, son nom — puis l'autoriser aux groupes.
sidebar:
  order: 1
---

**Administration → Capacités → Outils → Ajouter un outil.** Trois étapes, **Continuer** entre chacune, puis
**Ajouter l'outil**.

## Avant de commencer

- La clé de l'éditeur du moteur est enregistrée dans **Modèles IA → Clés API** — ou vous avez une
  clé dédiée à l'outil.
- Pour n8n : l'adresse de votre instance n8n et une clé d'API n8n. Pour Gamma : une clé d'API Gamma.

## Étapes

### 1. Que voulez-vous générer ?

Choisissez le type : **Image**, **Vidéo**, **Page HTML**, **Site illustré**, **Carte / Itinéraire**,
**Automatisation n8n** ou **Présentation Gamma**. Le type ne se change plus ensuite.

**Carte / Itinéraire** porte la pastille **En bêta test** : il fonctionne, mais son comportement peut
encore évoluer et présenter des imperfections. La pastille suit l'outil sur sa carte une fois créé.

Le type **Présentation** est grisé : il n'est plus proposé. Une présentation se demande dans la
conversation, et **Modifier les slides** sur sa carte ouvre l'éditeur (illustrations comprises, avec
les outils image ouverts à la personne). Un outil Présentation déjà créé n'apparaît plus dans le chat
— sa carte le dit — et peut être supprimé ; les présentations déjà produites restent ouvrables.

### 2. Réglez le moteur

| Type | Réglages |
|---|---|
| **Image** | **Fournisseur** et **Modèle**. Tous les modèles ne savent pas **retoucher** une image existante : pour que les utilisateurs puissent affiner une image message après message, et que les assistants reprennent les **images de référence** de leurs documents et de leurs bases (personnage, produit, logo), choisissez un modèle qui le permet — la description du modèle l'indique |
| **Vidéo** | Le modèle vidéo. Durée et format se choisissent dans le chat |
| **Page HTML**, **Carte / Itinéraire** | **LLM fournisseur** et **Modèle** ; **System prompt** pré-rempli, modifiable ; **Température**, de 0 à 1 |
| **Site illustré** | Les mêmes, plus **Outil image pour les illustrations**, **Images max / page** (1 à 6) et **Style graphique des images** |
| **Automatisation n8n** | **URL de l'instance n8n**, **Tag d'exposition** — seuls les workflows portant ce tag sont exposés —, **Clé API n8n**, en-tête d'authentification facultatif, et l'option d'exécution directe des workflows de lecture |
| **Présentation Gamma** | **Clé API Gamma**, **Thème**, **Plafond de slides**, **Images des slides**, **Export automatique** (PowerPoint ou PDF), **Consignes par défaut** |

Les modèles arrêtés par leur fournisseur ne sont plus proposés ; un modèle dont l'arrêt est annoncé
porte sa date dans la liste. Un type dont plus aucun modèle n'est en service reste visible à
l'étape 1, mais grisé.

### 3. Nommez et activez

- **Nom affiché** *(obligatoire)* : ce que les utilisateurs lisent dans le menu **+**.
- **Clé API dédiée** *(image et vidéo)* : vide, l'outil utilise la clé de **Modèles IA**.
- **Ordre** : sa place dans le menu, parmi les outils du même type.
- **Actif — proposé aux groupes qui l'autorisent** : coché par défaut. Il met l'outil en service ;
  les groupes décident ensuite qui le voit.

Cliquez **Ajouter l'outil** : « Outil « … » créé. Pour le proposer aux utilisateurs, cochez-le dans
Personnes → Groupes → Droits d'accès → Outils. »

## Ensuite — deux gestes indispensables

1. **Testez-le** : **Tester**, sur sa carte. Tant qu'il n'a pas été testé avec succès, rien ne prouve
   qu'il fonctionne — voir [Tester et entretenir les outils](tester-et-entretenir-les-outils.md).
2. **Autorisez-le** : **Personnes → Groupes → Droits d'accès → Outils**, cochez-le dans les groupes
   voulus. **Un nouvel outil n'est autorisé à aucun groupe** : tant que vous ne l'avez coché nulle
   part, seuls les administrateurs le voient, et sa carte l'indique — « Aucun groupe — admins
   seuls », avec un avertissement. Une fois autorisé, elle affiche le nombre de groupes.

Pour un outil image, n8n ou Gamma destiné à un assistant : cochez-le aussi dans la section
**Capacités** de la fiche de l'assistant.

Un outil **Présentation Gamma** sert aussi, **sans assistant**, le choix **Par Gamma** du bouton
**Version soignée** des présentations PowerPoint livrées dans le chat : il apparaît pour les
utilisateurs des groupes qui l'autorisent, dès que l'outil a sa clé et n'est pas en échec à son dernier test. La présentation y
garde son texte ; le **thème**, les **images des slides** et les **consignes par défaut** de l'outil
s'appliquent, le **plafond de slides** aussi — une présentation plus longue est refusée, jamais
coupée. Sans **export automatique** choisi, un fichier PowerPoint est demandé à Gamma. Si une
conversation a un assistant lié à un outil Gamma, c'est celui-là qui sert.

Enfin, vérifiez son tarif dans **Tarifs & crédits → Vue services** : une ligne à son nom y est créée.
Laissée à 0, elle reprend le tarif public du modèle quand MIA le connaît ; sinon, les créations de
l'outil sont signalées « non tarifées » — voir [Régler les crédits](../credits/regler-les-credits.md).

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Nom, fournisseur et modèle sont requis. » | Compléter les champs de l'étape 2 et le nom. |
| La carte affiche **Clé requise** | Il manque la clé de l'éditeur, l'outil image source d'un site illustré, ou l'adresse n8n : suivre le message de la carte. |
| L'outil n'apparaît que chez les administrateurs — sa carte affiche « Aucun groupe — admins seuls » | Il n'est coché dans aucun groupe actif : l'autoriser dans **Personnes → Groupes → Droits d'accès → Outils**. |
