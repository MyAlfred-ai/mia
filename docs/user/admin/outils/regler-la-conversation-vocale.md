---
title: Régler la conversation vocale
description: Proposer ou masquer le bouton onde sonore du chat, choisir la voix qui lit les réponses, et savoir ce qu'il coûte.
sidebar:
  order: 4
---

La conversation vocale enchaîne, sans clavier : l'utilisateur parle, MIA répond et lui lit sa réponse
à voix haute — dès sa première phrase, pendant que la suite s'écrit —, puis l'écoute de nouveau.
Elle se règle sur la page **Outils**, carte **Conversation vocale**, marquée **Intégré** — à côté de
la carte **Dictée vocale**. Côté utilisateur :
[Converser à la voix](../../fonctionnalites/conversations/converser-a-la-voix.md).

## Avant de commencer

La conversation vocale **écoute** l'utilisateur par OpenAI (la même transcription que la dictée) et
**lit** ses réponses avec la **voix de lecture** choisie sur la carte. Une clé **OpenAI** doit être
enregistrée dans **Modèles IA → Fournisseurs et modèles**. Sans elle, la carte affiche **Clé requise**
et le bouton n'apparaît pas.

**Le moteur de la dictée ne change rien à l'écoute** : même avec **Mistral Voxtral** choisi sur la
carte **Dictée vocale**, la conversation écoute par OpenAI.

- Voxtral et une clé OpenAI : elle marche. La voix de l'utilisateur part chez OpenAI, et la carte
  le rappelle.
- Voxtral **sans** clé OpenAI : elle ne peut pas marcher. Le bouton onde sonore reste visible avec
  une **pastille rouge**, ne démarre pas et dit pourquoi au survol ou au clic. Renseignez une clé
  OpenAI, ou passez la carte sur **Inactif** pour retirer le bouton.

## Choisir la voix de lecture

La liste **Voix de lecture** de la carte propose :

- **OpenAI Realtime** : la voix qu'OpenAI désigne pour remplacer sa voix d'origine. Comptée à la
  minute lue.
- **Mistral Voxtral TTS** : proposée avec une clé **Mistral**. Mistral la facture au **texte lu**
  (au caractère), et chaque langue a sa voix (française, anglaise).
- **La voix d'origine d'OpenAI**, qu'OpenAI **arrête le 6 janvier 2027** : la liste affiche sa date
  d'arrêt, puis la grise.

OpenAI Realtime et Voxtral ne sont retenues qu'après un **essai réussi** : MIA leur fait lire un mot
(non compté) au moment du choix ; en cas d'échec, la cause s'affiche et rien ne change.

**Sans choix de votre part**, la lecture garde la voix d'origine jusqu'à son arrêt, puis passe
**d'elle-même** sur OpenAI Realtime le 6 janvier 2027 — sans rien enregistrer. D'ici là, un **point à
régler** (pastille sur **Outils**) vous le rappelle. Une voix Voxtral dont la clé Mistral a été
retirée revient aussi à la voix OpenAI du moment, et la carte le dit.

Les annonces des actions sont mises en cache **par voix** : changer de voix les fait resynthétiser
une fois.

## Étapes

- **Proposer la conversation vocale** : interrupteur sur **Actif** — c'est le réglage par défaut. Le
  bouton onde sonore apparaît à côté du micro, dans le champ de saisie de tous les utilisateurs.
- **La masquer** : interrupteur sur **Inactif**. Le bouton disparaît pour tout le monde, et une
  conversation ne peut plus démarrer ni une réponse être lue, même depuis une page restée ouverte.

Les deux interrupteurs sont indépendants : on peut proposer la dictée sans la conversation vocale,
ou l'inverse. Il n'y a pas de réglage par groupe : la conversation vocale vaut pour toute l'instance.

## Ce qu'il faut savoir

- **Pastille rouge** : elle s'affiche aussi sur le bouton et sur l'icône de la carte quand une demande
  récente a montré que le crédit OpenAI est épuisé ou que la clé est refusée. Le bouton reste
  utilisable (le compte a pu être rechargé depuis), et une lecture réussie éteint la pastille.
- **Chaque tour est un message ordinaire** : l'assistant, les bases de connaissances, les
  connecteurs et le quota de l'utilisateur s'appliquent comme à l'écrit, et l'échange reste dans le
  fil. Le modèle reçoit en plus une consigne de réponse orale (courte, sans mise en forme).
- **Connecteurs à la demande** : à la voix, personne n'ouvre le menu **+**. Tant que la carte
  **Laisser MIA choisir** de la page **Connecteurs** est **Proposé**, les connecteurs reliés de
  l'utilisateur s'activent d'eux-mêmes pendant une conversation vocale, même interrupteur éteint —
  seulement ceux qu'il a reliés et que vous laissez visibles, et jamais une écriture sans sa carte
  d'approbation. Carte **Désactivé** : seuls les connecteurs allumés servent ; l'utilisateur peut
  en allumer un en disant « active Gmail ».
- **Interrompre MIA** : sa voix baisse dès que l'utilisateur parle par-dessus, et « stop » la coupe ;
  Espace, Échap, un toucher sur le bandeau ou le bouton du casque l'arrêtent aussi. « Lis-le » fait
  lire à voix haute la carte en attente, comptée comme une lecture.
- **Deux postes de coût**, tous deux dans le quota de l'utilisateur, à retrouver dans **Tarifs &
  crédits** et sur la page **Consommation**, catégorie *Voix* : les **minutes écoutées** (ligne **Dictée**,
  rapportées par le navigateur à la fin de chaque session) et la **lecture** (une ligne **Lecture
  à voix haute** par voix : à la minute lue pour les voix OpenAI, mesurée sur l'audio produit ; au
  million de caractères lus pour Voxtral). Laissées à 0, ces lignes reprennent le tarif public que
  MIA connaît. Lire une réponse pendant qu'elle s'écrit, par passages de quelques phrases, ne coûte
  rien de plus : la minute ou le caractère lus sont les mêmes.
- **Interruption à la voix** : pendant que MIA lit, l'utilisateur peut dire « stop ». Le micro reste
  alors ouvert pendant la lecture, et ces minutes s'ajoutent aux **minutes écoutées**. C'est un
  réglage de l'utilisateur, par appareil (bouton **« Stop »** du bandeau) : allumé par défaut sur
  ordinateur et sur Android, éteint par défaut sur iPhone et iPad, où un micro ouvert fait baisser
  la voix. Éteint, le micro est coupé pendant la lecture et ces secondes ne sont pas facturées.
  Allumé, la conversation ouvre **deux sessions** chez OpenAI au lieu d'une : celle qui écoute les
  demandes, et une seconde, où le micro part pendant la lecture, réglée pour comprendre un ordre
  court en une seconde environ. Le micro n'est envoyé qu'à une session à la fois : les minutes
  écoutées sont les mêmes. Si la seconde ne s'ouvre pas, rien ne s'affiche et « stop » reste
  entendu, plus lentement (deux à trois secondes). Dans
  les deux cas, rien de ce que MIA lit ne devient un message : pendant sa lecture, seul un ordre
  d'arrêt est retenu. Interrompue alors qu'elle s'écrit encore, la réponse s'arrête aussi, comme
  avec le bouton Stop du chat : ce qui n'est pas écrit n'est pas généré.
- **Les cartes se disent** : en conversation vocale, « approuve », « annule », « envoie-le »,
  « confirme », « plus court »… actionnent les boutons de la carte en attente. C'est le navigateur
  de l'utilisateur qui reconnaît l'ordre et déclenche le bouton — mêmes contrôles côté serveur qu'un
  clic, aucun appel à un modèle pour décider, et l'assistant ne reçoit aucun moyen d'approuver ou
  d'envoyer. Un « oui » seul ne déclenche rien, et un envoi d'email demande toujours une confirmation
  dite. Les phrases de retour (« C'est fait », « Email envoyé »…) sont en cache comme les annonces.
- **Chaque utilisateur règle sa voix** dans *Préférences IA*, carte **Voix** : la vitesse de lecture
  (appliquée par son navigateur, donc sans coût ni re-synthèse) et la longueur des réponses à l'oral.
  Celle-ci est **brève par défaut** — moins de tokens à la génération et moins de minutes lues que
  pour une réponse écrite. Il n'y a pas de réglage d'instance pour ces deux choix : ils sont
  personnels, comme le style de réponse.
- **Les annonces des actions** (« Recherche sur Internet », « Consultation du cerveau MyBrain »…)
  sont synthétisées **une seule fois par instance et par langue**, puis servies depuis le cache de
  l'application : la première fois qu'une action est annoncée, quelques secondes de lecture sont
  comptées à l'utilisateur qui l'a déclenchée ; ensuite, plus rien pour personne. Vider le cache de
  l'application ne fait que les resynthétiser à la prochaine occasion.
- Les repères sonores (message envoyé, MIA au travail, à vous de parler) sont produits par le
  navigateur : ils ne coûtent rien et ne demandent aucun réglage.
- La transcription et la lecture suivent la **langue de l'interface** de l'utilisateur.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| Personne n'a de bouton onde sonore | Vérifier que la carte est **Active** et qu'une clé OpenAI est enregistrée. |
| « Accès au micro refusé. » chez un utilisateur | C'est le navigateur de l'utilisateur qui bloque le micro : à autoriser de son côté. |
| Les réponses ne sont pas lues (« Lecture à voix haute impossible ») | Vérifier la clé du fournisseur de la voix de lecture (OpenAI ou Mistral) dans **Modèles IA** ; le détail de l'erreur est dans les **Journaux**. |
| La voix de lecture choisie est refusée à l'essai | Le message dit la cause (clé refusée, crédit épuisé, service fermé à ce compte) : réglez-la chez le fournisseur, ou choisissez une autre voix. |
| Les actions ne sont pas annoncées, les réponses le sont | Une annonce qui échoue est sautée (la conversation continue) ; le motif est dans les **Journaux**, et le dossier de cache de l'application doit être accessible en écriture. |
