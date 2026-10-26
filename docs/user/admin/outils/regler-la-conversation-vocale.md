---
title: Régler la conversation vocale
description: Proposer ou masquer le bouton onde sonore du chat, et savoir ce qu'il coûte.
sidebar:
  order: 4
---

La conversation vocale enchaîne, sans clavier : l'utilisateur parle, MIA répond et lui lit sa réponse
à voix haute — dès sa première phrase, pendant que la suite s'écrit —, puis l'écoute de nouveau.
Elle se règle sur la page **Outils**, carte **Conversation vocale**, marquée **Intégré** — à côté de
la carte **Dictée vocale**. Côté utilisateur :
[Converser à la voix](../../fonctionnalites/conversations/converser-a-la-voix.md).

## Avant de commencer

La conversation vocale repose sur deux services d'OpenAI : la transcription (la même que la dictée)
et la synthèse vocale. Une clé **OpenAI** doit être enregistrée dans **Modèles IA → Clés API**.
Sans elle, la carte affiche **Clé requise** et le bouton n'apparaît pas.

## Étapes

- **Proposer la conversation vocale** : interrupteur sur **Actif** — c'est le réglage par défaut. Le
  bouton onde sonore apparaît à côté du micro, dans le champ de saisie de tous les utilisateurs.
- **La masquer** : interrupteur sur **Inactif**. Le bouton disparaît pour tout le monde, et une
  conversation ne peut plus démarrer ni une réponse être lue, même depuis une page restée ouverte.

Les deux interrupteurs sont indépendants : on peut proposer la dictée sans la conversation vocale,
ou l'inverse. Il n'y a pas de réglage par groupe : la conversation vocale vaut pour toute l'instance.

## Ce qu'il faut savoir

- **Chaque tour est un message ordinaire** : l'assistant, les bases de connaissances, les
  connecteurs et le quota de l'utilisateur s'appliquent comme à l'écrit, et l'échange reste dans le
  fil. Le modèle reçoit en plus une consigne de réponse orale (courte, sans mise en forme).
- **Deux postes de coût**, tous deux dans le quota de l'utilisateur, à retrouver dans **Tarifs &
  crédits** et sur la page **Consommation**, catégorie *Voix* : les **minutes écoutées** (ligne **Dictée**,
  rapportées par le navigateur à la fin de chaque session) et les **minutes lues** (ligne **Lecture
  à voix haute**, mesurées sur l'audio produit par la synthèse). Laissées à 0, ces lignes reprennent
  le tarif public d'OpenAI que MIA connaît. Lire une réponse pendant qu'elle s'écrit, par
  passages de quelques phrases, ne coûte rien de plus : la minute lue est la même.
- **Interruption à la voix** : pendant que MIA lit, l'utilisateur peut dire « stop ». Le micro reste
  alors ouvert pendant la lecture, et ces minutes s'ajoutent aux **minutes écoutées**. C'est un
  réglage de l'utilisateur, par appareil (bouton **« Stop »** du bandeau) : allumé par défaut sur
  ordinateur et sur Android, éteint par défaut sur iPhone et iPad, où un micro ouvert fait baisser
  la voix. Éteint, le micro est coupé pendant la lecture et ces secondes ne sont pas facturées. Dans
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
| Les réponses ne sont pas lues (« Lecture à voix haute impossible ») | Vérifier la clé OpenAI dans **Modèles IA** ; le détail de l'erreur est dans les **Journaux**. |
| Les actions ne sont pas annoncées, les réponses le sont | Une annonce qui échoue est simplement sautée (la conversation continue) ; le motif est dans les **Journaux**, et le dossier de cache de l'application doit être accessible en écriture. |
