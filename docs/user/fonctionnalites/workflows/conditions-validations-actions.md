---
title: Conditions, validations et actions
description: Faire bifurquer un workflow, l'arrêter pour votre accord, et le faire agir dans vos outils.
sidebar:
  order: 2
---

Quatre briques donnent sa logique à un workflow : la **condition** (deux chemins possibles),
**Arrêter le workflow** (une sortie propre au bout d'un chemin), la **validation** (votre décision) et
l'**action** (un geste dans un outil relié).

## Avant de commencer

Une action agit dans un service relié à **votre** compte : reliez-le d'abord dans **Réglages →
Comptes connectés**. Une condition par règle lit une **Fiche** : il faut qu'une étape (ou le départ) en remette
une avant elle.

## Une condition

1. **+** sur le rail → **Condition**.
2. **Comment décider** :
   - **Une règle** — par exemple *Montant TTC est supérieur à 5 000*, sur un champ d'une Fiche. Elle
     se calcule sans IA : même réponse à chaque fois, aucun crédit. Trois conditions au plus, toutes
     ou au moins une.
   - **Une question à MIA** — une question fermée (*« Cet appel d'offres correspond-il à notre
     offre ? »*), qui cite les pièces avec @. MIA répond oui ou non avec sa raison, visible dans
     l'exécution. **Si MIA hésite** : vous demander (une validation s'ouvre avec ce qu'elle a vu), ou
     prendre « non », la voie prudente.
3. **Les deux voies** : ajoutez des briques dans **Si oui** et **Si non** (« + Ajouter » dans la voie),
   puis choisissez pour chacune **puis reprendre le rail** ou **puis arrêter le workflow**. Une voie ne
   contient pas de condition.

Sur le rail, les deux voies s'ouvrent côte à côte sous la condition, en colonnes de même largeur,
chacune de sa couleur. Les liaisons descendent chaque voie et se rejoignent sur un point avant la
brique suivante ; une voie qui arrête le workflow ne revient pas. Au téléphone, les voies passent
l'une sous l'autre.

Plus de deux suites possibles, ou une question sans réponse oui/non ? Une **décision de MIA** —
voir [Laisser MIA choisir la voie](laisser-mia-choisir-la-voie.md).

**Dans une boucle**, une condition n'ouvre pas de voies : elle trie l'élément en cours. Pour chaque
réponse, choisissez **continuer le tour**, **passer à l'élément suivant** ou **arrêter le workflow** —
voir [Répéter pour chaque élément](repeter-pour-chaque-element.md).

## Arrêter le workflow au bout d'une voie

Quand une voie n'a plus rien à faire (une petite facture déjà classée, une demande hors sujet), terminez-la
par la brique **Arrêter le workflow** : l'exécution s'arrête là, proprement, sans passer par le livrable.

1. Dans la voie, **+ Ajouter** → **Arrêter le workflow** (rubrique *Logique*). Elle ne se propose que
   dans une voie de condition ou de décision, une fois par voie, et se place toujours en dernier : ce
   que vous ajoutez ensuite dans la voie se range avant elle.
2. **Statut de l'exécution** : **Terminée** (une fin prévue, par défaut) ou **Arrêtée** (une sortie à
   remarquer dans l'historique des exécutions).
3. **Le mot de la fin** (facultatif) : un message laissé dans la conversation de l'exécution, qui peut
   citer les pièces avec @ — *« Facture de @[Fiche facture › Montant] classée, pas d'accord
   nécessaire »*.

Rien ne part après elle : ni livrable, ni livraison, ni workflow qui s'enchaîne. Ce qui a été produit
jusque-là reste dans le dossier, et vous êtes prévenu sur vos appareils si le workflow le demande. Une
pièce remise dans l'autre voie reste citable après l'embranchement : la voie qui s'arrête ne la prive
de rien.

## Une validation

1. **+** → **Validation**, ou, plus simple, **Je relis la pièce avant la suite** dans l'étape.
2. **Ce qu'on relit** : les pièces à vérifier. **Ce qu'il faut vérifier** : une consigne pour vous.
3. **Ce que vous pourrez faire** : approuver ou arrêter (toujours), **corriger la pièce
   directement**, **renvoyer à l'étape avec une remarque** (deux fois au plus).
4. **Sans réponse** : au bout de un à quatorze jours (sept par défaut), l'exécution s'arrête.

## Une action

1. **+** → **Action**.
2. **Dans quel outil** : email Gmail ou Outlook, agenda, tâches, Slack, Google Drive, automatisation
   n8n, présentation Gamma — ceux que vous avez reliés.
3. **Ce que l'action fait** : une consigne qui cite les pièces (*« Prépare un email à l'adresse de
   @[Contexte] avec @[Synthèse] »*). Une pièce que vous demandez de **mettre** dans l'email, le message ou
   le document y figure **telle quelle**, son contenu exact (pas son nom, pas un résumé) ; une **image**
   citée apparaît **dans le corps de l'email**, même si MIA a oublié de la placer. La pièce prend la mise
   en forme de sa destination : mise en forme Slack dans un message Slack, texte simple dans les notes
   d'une tâche ou la description d'un évènement. Une pièce qui en cite d'autres (une news rédigée à partir
   de la veille de la semaine) arrive complète : les pièces qu'elle cite y figurent aussi. Un texte venu
   de l'extérieur — un email reçu, un fichier déposé — n'est jamais complété ainsi : ce qu'il contient
   reste tel qu'il est arrivé. Pour un résumé ou une information tirée d'une pièce, dites-le dans la
   consigne (*« résume @[Synthèse] en trois lignes »*).
4. **Filtres** (Gmail, Outlook, Google Drive) : les emails ou les fichiers que l'action voit, et les
   destinataires permis — voir [Filtrer les emails et les fichiers](filtrer-emails-et-fichiers.md).
5. **Ce que fait l'action** : **Préparer des actions à approuver** (par défaut — une carte attend votre accord
   dans la conversation de l'exécution, et le workflow aussi) ou **Agir sans validation** (si votre
   administrateur le propose ; un email reste un brouillon tant que **Envoyer réellement les emails**
   n'est pas coché).

Une **étape** peut aussi agir dans ses sources : réglez **Ce qu'elle peut faire dans les outils** sur
**Préparer des actions à approuver** ou **Agir sans validation**. Les pièces qu'elle met dans ce qu'elle
écrit y figurent de même, telles quelles.
