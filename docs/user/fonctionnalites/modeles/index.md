---
title: Les modèles de LLM
description: Les moteurs d'IA qui rédigent les réponses — lequel choisir, ce qu'ils savent faire et ce qu'ils coûtent.
sidebar:
  order: 2
---

Un **modèle** — ou LLM, *large language model* — est le moteur d'IA qui rédige les réponses. MIA en
réunit plusieurs, de plusieurs éditeurs, derrière la même interface : vous passez de l'un à l'autre
sans changer d'outil.

## À quoi ça sert

Tous les modèles ne se valent pas pour toutes les tâches, et ne coûtent pas le même prix. Un modèle
rapide et économique suffit pour reformuler un mail ; une analyse de contrat ou un raisonnement
chiffré mérite un modèle plus puissant. Choisir le modèle, c'est arbitrer entre qualité, vitesse et
coût.

## Comment ça marche

**Qui décide des modèles proposés.** Votre administrateur enregistre les éditeurs, active les
modèles, et fixe ceux auxquels chaque groupe a droit. Vous choisissez parmi ceux-là.

**Où se choisit le modèle.** Dans une conversation *standard*, le sélecteur de modèle, sous le champ
de saisie. Avec un assistant, c'est lui qui impose son modèle : le sélecteur disparaît. Voir
[Les assistants](../assistants/index.md).

**Le choix suit la conversation.** Le modèle est enregistré avec la conversation et revient quand
vous la rouvrez. Vous pouvez en changer en cours de route : tout l'historique part alors vers le
nouveau modèle. Une nouvelle conversation démarre sur le modèle par défaut de l'instance.

**Ce que chaque modèle sait faire.** Le bandeau au-dessus des messages l'indique, une fois déplié
(il est replié par défaut : la flèche à sa droite l'ouvre) :

| Indication | Le modèle sait… |
|---|---|
| **✓ Internet** | chercher sur le web avant de répondre |
| **✓ Images** | analyser une image jointe |
| **✓ Fichiers** | lire un PDF joint tel quel |
| **✓ Connecteurs** | consulter vos données Google et Microsoft, et appeler des outils |

Un modèle qui ne lit pas les images ou les PDF **les ignore sans prévenir** : si MIA semble ne pas
voir votre fichier, regardez d'abord le bandeau. Les documents Word, Excel et texte sont lisibles
par tous les modèles.

**Le coût.** Chaque réponse est décomptée en crédits, au tarif de son modèle. Le repère **€** à
**€€€€€** du sélecteur situe le prix d'un modèle par rapport aux autres modèles qui vous sont
proposés — le prix, pas la qualité ; son infobulle et le bandeau du modèle donnent le coût en clair
(« ≈ 35 crédits par réponse »), calculé comme il sera décompté. Voir
[La gestion des crédits](../credits/index.md).

**Le secours.** Si le modèle choisi est en panne avant d'avoir écrit son premier mot, MIA peut
basculer sur un modèle de secours désigné par l'administrateur. Un message vous le signale, avec la
raison ; le secours garde vos connecteurs s'il sait s'en servir, et la réponse est facturée à son
tarif. Le message suivant retente votre modèle.

**Internet et connecteurs avec Mistral.** Les modèles Mistral ne savent pas chercher sur Internet
pendant qu'ils utilisent un connecteur ou un outil d'assistant. Quand vous en avez allumé un, il
passe avant : la recherche Internet est mise de côté pour cette réponse, et MIA vous le dit. Les
autres éditeurs font les deux à la fois.

**Quand un modèle échoue.** Une panne n'est jamais muette : un encadré remplace la réponse, et son
titre nomme la cause — éditeur surchargé, crédit épuisé chez l'éditeur, conversation devenue trop
longue pour ce modèle, pièce jointe refusée… Si la réponse avait déjà commencé, le texte écrit reste
et une ligne dit pourquoi il s'arrête là. Le détail est dans [Choisir un modèle](choisir-un-modele.md).

**Le relais après un refus.** Les modèles les plus avancés de certains éditeurs filtrent des sujets
sensibles — la cybersécurité, la recherche en biologie — et peuvent décliner par prudence une
demande légitime. MIA la fait alors reprendre aussitôt par un autre modèle du même éditeur, dans la
même réponse, recherche et connecteurs compris. Même relais quand votre organisation n'a pas accès
au modèle choisi. La liste des actions au-dessus du message le dit, l'étiquette nomme le modèle qui
a répondu, et chacun est décompté à son tarif. Si aucun modèle ne peut reprendre, l'encadré
**Demande déclinée** s'affiche.

**Les longues réflexions.** Certains modèles réfléchissent toujours avant d'écrire, parfois
plusieurs minutes sur une question difficile, et peuvent rendre de longues réponses. Tant que le
modèle travaille, la réponse n'est pas considérée comme bloquée.

## Les façons de s'en servir

| Vous voulez… | Page |
|---|---|
| Changer de modèle, comparer deux réponses, comprendre un message d'erreur | [Choisir un modèle](choisir-un-modele.md) |

## Ce que vous pouvez régler

Le modèle de chaque conversation standard. Tout le reste — les modèles proposés, celui par défaut,
celui de secours, ceux de votre groupe — dépend de votre administrateur.

## Exemples

**Un mail à reformuler.** Un modèle marqué **€** ou **€€** : la réponse est quasi instantanée et ne
coûte presque rien.

**Un contrat à analyser.** Vous joignez le PDF : vérifiez **✓ Fichiers** dans le bandeau, puis
choisissez un modèle plus puissant pour une analyse fine.

**Comparer deux modèles sur la même question.** Vous posez la question, sélectionnez un autre modèle,
puis cliquez **Regénérer** sous la réponse : l'étiquette indique le nouveau modèle et ses crédits.

**Une question d'actualité.** Choisissez un modèle **✓ Internet** et laissez **Recherche Internet**
allumée dans le menu **+** : MIA cherche avant de répondre et cite ses sources.

## Voir aussi

- [Les assistants](../assistants/index.md) — un modèle préconfiguré pour un métier
- [Les connecteurs](../connecteurs/index.md) — qui demandent un modèle compatible
- [La gestion des crédits](../credits/index.md) — ce que coûte chaque réponse
