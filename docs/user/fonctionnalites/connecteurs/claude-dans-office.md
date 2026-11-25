---
title: Utiliser Claude dans Excel, Word ou PowerPoint
description: Relier l'add-in Claude d'Excel, Word ou PowerPoint à votre compte MIA, pas à pas et sans compte Claude, ses réponses comptées dans vos crédits MIA.
sidebar:
  order: 7
---

L'add-in **Claude** d'Anthropic travaille directement dans vos fichiers Office. Relié à MIA, il
s'utilise **avec votre compte MIA** : pas de compte Claude à créer, et ses réponses comptent dans
**vos crédits MIA**, comme une conversation. Comptez cinq minutes, une fois par application.

| Application | Ce que l'add-in y fait |
|---|---|
| **Excel** | Lit vos cellules, écrit et corrige des formules, construit tableaux croisés et graphiques, explique un classeur. |
| **Word** | Rédige, relit et réécrit un document en gardant sa mise en forme, résume un rapport. |
| **PowerPoint** | Crée des diapositives à partir d'un plan ou d'un document, retouche une présentation existante. |

> **Besoin d'être accompagné ?** Sur la carte **Claude dans Office** de MIA, le bouton **Me guider
> pas à pas** ouvre l'assistant **MIA : mode d'emploi**. Il reprend ce guide avec vous, une étape
> après l'autre, et répond à vos questions.

## Avant de commencer

- Dans MIA, **Réglages → Comptes connectés** montre la carte **Claude dans Office**. Ses icônes
  disent quelles applications votre organisation a ouvertes. Pas de carte : Claude dans Office n'est
  pas activé pour vous, demandez-le à votre administrateur.
- En bas de cette carte, **Modèles ouverts à votre compte** cite au moins un modèle Claude. Sinon,
  demandez-en un à votre administrateur.
- Excel, Word ou PowerPoint en version récente : Microsoft 365 sur Windows, ou Office pour Mac.
- L'add-in est **en anglais** : les libellés cités dans cette page sont ceux qu'il affiche.

## Votre cas

La carte **Claude dans Office** de MIA dit comment l'add-in vous reconnaît :

| La carte affiche | Ce que vous faites |
|---|---|
| **Avec votre compte Microsoft** seulement | Rien à saisir : ouvrez l'add-in (étape 2, point 2). Il vous reconnaît par votre compte Microsoft 365 ; la première fois, Microsoft peut vous demander de confirmer, avec votre compte de travail habituel. |
| **Avec un jeton personnel** | Les trois étapes ci-dessous. |
| Les deux | Ouvrez d'abord l'add-in : s'il s'ouvre directement sur sa zone de discussion, c'est fini. S'il affiche son écran d'accueil, suivez les trois étapes. |

## Étape 1 — Prendre votre adresse et votre jeton dans MIA

1. Dans MIA, cliquez sur **Réglages**, en bas à gauche sous votre nom, puis sur **Comptes
   connectés** (1).
2. Sur la carte **Claude dans Office**, en haut de la page, cliquez sur **Générer mon jeton** (2).
3. Laissez cette page ouverte : votre jeton n'est affiché qu'une fois, et vous viendrez y copier
   l'adresse (3) puis le jeton (4) à l'étape 3.

![La page Comptes connectés de MIA, juste après Générer mon jeton : à gauche, le menu de vos réglages, Comptes connectés sélectionné ; en haut de la page, la carte Claude dans Office avec les icônes d'Excel, Word et PowerPoint, le bouton Me guider pas à pas, l'adresse https://mia.example.com/office et son bouton Copier, puis l'encadré « Votre jeton — copiez-le maintenant, il ne sera plus affiché » avec le jeton et son bouton Copier. Des numéros de 1 à 4 repèrent Comptes connectés, Générer mon jeton et les deux boutons Copier.](../../images/office-jeton.png)

| | À l'écran |
|---|---|
| **1** | **Comptes connectés**, dans le menu de vos réglages. |
| **2** | **Générer mon jeton** crée votre jeton personnel. Une fois le jeton créé, le bouton devient **Remplacer mon jeton**. |
| **3** | **Copier**, à côté de l'**Adresse** : l'adresse de votre MIA pour l'add-in. Elle se termine par `/office`. |
| **4** | **Copier**, sous **Votre jeton** : il commence par `mia_office_`. Il n'est montré qu'une fois. Il est personnel, comme un mot de passe : ne le donnez à personne. |

## Étape 2 — Ouvrir Claude dans Excel, Word ou PowerPoint

1. Ouvrez un fichier dans l'application, ou créez-en un vierge.
2. Dans l'onglet **Accueil**, cliquez sur **Claude**, à droite du ruban. Le volet **Claude** s'ouvre
   à droite de la fenêtre. Pas de bouton **Claude** : voir [Cas particuliers](#cas-particuliers).
3. L'écran d'accueil propose **Log in**, **Sign up** et **Cloud provider or gateway**. Cliquez sur
   **Cloud provider or gateway**. Ni **Log in** ni **Sign up** : ils demandent un compte Claude, dont
   vous n'avez pas besoin.

![PowerPoint, l'onglet Accueil et le volet Claude ouvert à droite : le titre « Claude, right in your slides » et les trois boutons Log in, Sign up et Cloud provider or gateway. Le numéro 1 repère le bouton Claude du ruban, à droite de Compléments et Concepteur ; le numéro 2 repère Cloud provider or gateway.](../../images/office-connexion.png)

| | À l'écran |
|---|---|
| **1** | Le bouton **Claude** du ruban, dans l'onglet **Accueil**. Il est au même endroit dans Excel, Word et PowerPoint. |
| **2** | **Cloud provider or gateway** : la connexion par MIA, sans compte Claude. |

Si votre organisation a préparé l'add-in pour MIA, il peut s'ouvrir directement sur l'écran de
l'étape 3, l'adresse déjà remplie.

## Étape 3 — Coller l'adresse et le jeton

L'écran **Connect another way** s'affiche, onglet **Gateway** déjà choisi. S'il ne l'est pas,
cliquez sur **Gateway** ; ni **Vertex**, ni **Bedrock**, ni **Azure**.

1. Dans **Gateway URL**, collez l'adresse copiée dans MIA (repère 3 de l'étape 1). Si elle est déjà
   remplie, laissez-la.
2. Revenez dans MIA, cliquez sur **Copier** sous votre jeton (repère 4 de l'étape 1), puis collez-le
   dans **Token**. Le champ n'affiche que des points : c'est normal.
3. Cliquez sur **Continue**.

![Le volet Claude sur l'écran Connect another way : l'onglet Gateway choisi parmi Gateway, Vertex, Bedrock et Azure, le champ Gateway URL rempli avec https://mia.example.com/office, le champ Token masqué par des points, et le bouton Continue en bas. Des numéros de 1 à 3 repèrent les deux champs et le bouton.](../../images/office-passerelle.png)

| | À l'écran |
|---|---|
| **1** | **Gateway URL** : l'adresse de MIA, celle de la carte. |
| **2** | **Token** : votre jeton personnel, masqué. |
| **3** | **Continue** : l'add-in vérifie la connexion auprès de MIA. |

## Résultat

L'add-in s'ouvre sur sa zone de discussion : écrivez votre demande en bas du volet, comme dans MIA.
Le sélecteur de modèle, en bas, ne propose que les modèles Claude ouverts à votre compte.

Dans MIA, la carte **Claude dans Office** affiche ensuite **Jeton actif**, la date de création du
jeton et celle de sa dernière utilisation, puis, dès votre première demande, les crédits consommés
dans le mois.

**Dans une autre application** — Word après Excel, par exemple —, reprenez les étapes 2 et 3 avec
**le même jeton**. Copiez-le dans chaque application tant qu'il est affiché : une fois la page de MIA
quittée, il ne l'est plus. L'add-in garde la connexion d'une ouverture à l'autre ; sur un autre
ordinateur, refaites les étapes 2 et 3.

## Ce qu'il faut savoir

- **Crédits.** Chaque demande de l'add-in compte dans vos crédits du mois, à son coût réel : le texte
  lu et écrit par le modèle, votre classeur, votre document ou vos diapositives compris, et ses
  recherches Internet. Pour une tâche, l'add-in envoie souvent plusieurs demandes à la suite.
  **Réglages → Usage & crédits** les range dans la catégorie **Claude dans Office**, avec le coût
  moyen d'une demande, quelle que soit l'application. Quota atteint : l'add-in vous le dit et
  s'arrête, comme le chat.
- **Historique.** Vos échanges restent dans l'add-in, sur votre ordinateur. Ils n'apparaissent pas
  dans vos conversations MIA.
- **Données.** Vos demandes passent par MIA puis par Anthropic. MIA n'en garde aucun contenu,
  seulement ce qu'elles ont consommé.
- **Ce qui ne marche pas par ce chemin.** La dictée, et le travail entre plusieurs applications
  Office. La recherche Internet et l'exécution de code fonctionnent, si votre organisation les laisse
  ouvertes. Outlook n'est pas concerné.

## Cas particuliers

**Pas de bouton Claude dans le ruban.** Regardez aussi sous **Accueil → Compléments**. Si votre
organisation vient de l'installer pour vous, il peut mettre jusqu'à 24 heures à apparaître : fermez
puis rouvrez l'application plus tard. Sinon, l'add-in n'est pas encore installé pour vous.
Demandez-le à votre administrateur, ou, si votre organisation vous laisse installer des
compléments, installez-le vous-même : sur Microsoft Marketplace, cherchez **Claude for Microsoft
365**, d'Anthropic, puis cliquez sur **Obtenir maintenant**. Rouvrez ensuite l'application et
reprenez l'étape 2.

**Le bouton est là, mais pas dans toutes les applications.** Votre organisation n'a ouvert Claude
dans Office que pour certaines : la carte de MIA en montre les icônes.

**Jeton perdu, ou nouvel ordinateur.** Sur la carte, **Remplacer mon jeton** en crée un nouveau.
L'ancien cesse aussitôt de fonctionner, partout : collez le nouveau dans chaque application qui s'en
servait.

**Arrêter.** **Révoquer**, sur la carte, coupe l'add-in dans toutes vos applications.

## En cas de problème

L'add-in affiche le message de MIA :

| Ce qui arrive | Quoi faire |
|---|---|
| « Jeton inconnu ou révoqué » | Le jeton a été remplacé ou révoqué. Générez-en un dans **Réglages → Comptes connectés**, et collez-le dans **Token**. |
| « Identifiant non reconnu : ce n'est ni un jeton MIA (mia_office_…) ni un jeton Microsoft » | Le champ **Token** contient autre chose que votre jeton, souvent l'adresse. Recopiez le jeton depuis MIA. |
| « Claude dans Office n'est pas activé sur cette instance MIA » | Votre administrateur doit l'activer. |
| « Les jetons personnels sont désactivés sur cette instance » | Votre organisation passe par votre compte Microsoft : demandez-lui de déployer l'add-in pour vous. |
| « Aucun compte MIA pour… » | Votre compte Microsoft n'a pas de compte MIA à la même adresse : demandez l'accès à votre administrateur. |
| « Votre rôle MIA n'ouvre pas Claude dans Office » | Demandez ce droit à votre administrateur. |
| « Aucun modèle Claude n'est ouvert à votre compte MIA » | Demandez à votre administrateur d'ouvrir un modèle Claude à votre groupe. |
| « Le modèle … n'est pas ouvert à votre compte MIA » | Choisissez, en bas du volet, un des modèles cités dans le message. |
| « Votre session Microsoft a expiré » | Fermez le volet Claude, puis rouvrez-le. |
| Quota atteint, ou service IA suspendu | Comme dans le chat : attendez le mois suivant, ou voyez avec votre administrateur. |
| Après **Continue**, l'add-in n'arrive pas à se connecter | Vérifiez l'adresse : celle de la carte, en entier, avec `https://` au début et `/office` à la fin. |

## Exemples

**Excel — analyser un export.** Dans un classeur de ventes : *« fais un tableau croisé des ventes par
région et par trimestre, avec un graphique »*. L'add-in construit le tableau dans une nouvelle
feuille.

**Excel — corriger une formule.** *« pourquoi la cellule F12 renvoie #N/A ? »* : l'add-in lit la
formule, la plage visée, et propose la correction.

**Word — préparer une note.** Dans un rapport de vingt pages : *« résume ce rapport en une page pour
le comité, avec trois décisions à prendre »*.

**PowerPoint — monter une présentation.** Dans une présentation vierge au modèle de l'entreprise :
*« fais six diapositives sur les résultats du trimestre à partir de ce plan »*, puis *« raccourcis
les textes de la diapositive 4 »*.

## Voir aussi

- [Les connecteurs](index.md)
- [La gestion des crédits](../credits/index.md)
