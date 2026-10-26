---
title: Suivre le travail de l'agent
description: Voir si MIA se sert bien de ses outils et connecteurs, repérer un outil qui échoue ou traîne, et des cartes d'approbation refusées.
sidebar:
  order: 6
---

**Administration → Maintenance → Diagnostic → Agent.** Quand MIA consulte un connecteur, une base
en direct ou un outil d'assistant avant de répondre, elle enchaîne des allers-retours avec le modèle
et des appels d'outils. Cet écran les mesure, sur 7, 30 ou 90 jours.

## Ce que vous y lisez

- **Huit indicateurs** : réponses passées par les outils (et leur part dans toutes les réponses),
  allers-retours moyens avec le modèle, appels d'outils, taux d'erreur des outils, réponses qui ont
  atteint le **budget d'allers-retours**, répétitions bloquées, part de l'entrée relue du cache,
  durée moyenne d'une réponse.
- **À surveiller** : les points qui méritent un coup d'œil, en clair — un outil qui échoue souvent,
  un outil lent, des cartes refusées plus d'une fois sur deux ou laissées sans décision, un budget
  souvent atteint. Rien n'y figure tant qu'il n'y a pas assez de cas pour conclure.
- **Par outil** : appels, erreurs, durée moyenne et maximale de chaque outil.
- **Cartes d'approbation** : pour chaque action, les cartes proposées, approuvées, refusées, en échec
  à l'exécution et restées sans décision, le délai moyen de décision et le taux d'approbation.
- **Par modèle** : les mêmes mesures, modèle par modèle — utile pour comparer deux modèles sur le
  même usage.

## Comment réagir

| Ce que vous voyez | Ce que cela veut dire | Quoi faire |
|---|---|---|
| Un connecteur échoue souvent | Connexions expirées, droits manquants, service en panne | **Diagnostic → Contrôles** et **Journaux**, recherche **[connecteurs]** |
| Le budget est souvent atteint | Les demandes dépassent un tour, ou le modèle tourne en rond | Les utilisateurs cliquent **Continuer** ; essayer un autre modèle sur cet usage |
| Les cartes d'une action sont souvent refusées | L'assistant propose des écritures que personne n'a demandées | Relire les consignes de l'assistant concerné |
| Beaucoup de cartes sans décision | Elles arrivent trop tard ou sans explication suffisante | En parler aux utilisateurs concernés |
| Un outil lent | Un service extérieur répond lentement | Rien à régler côté MIA si cela reste ponctuel |

## Ce qui est mesuré

Seules les réponses qui passent par les outils sont détaillées ; les autres sont seulement comptées.
**Aucun contenu** n'est enregistré : ni question, ni réponse, ni nom de fichier, ni personne — des
nombres, des noms d'outils et le modèle. Les mesures sont gardées 180 jours et ne font pas partie de
la sauvegarde.
