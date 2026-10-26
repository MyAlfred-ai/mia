---
title: Converser à la voix
description: Parler avec MIA et l'écouter répondre, sans clavier.
sidebar:
  order: 4
---

## Avant de commencer

Le bouton **onde sonore**, à côté du micro, apparaît quand la conversation vocale est disponible sur
votre instance : elle est active par défaut, mais votre administrateur peut la masquer, et elle
demande un service de transcription et de synthèse vocale configuré. S'il est absent du champ de
saisie, c'est à lui qu'il faut le demander.

La conversation suit la **langue de l'interface**. Elle est décomptée sur votre quota : les minutes
pendant lesquelles MIA vous écoute — y compris pendant sa lecture, quand l'interruption à la voix
est activée —, et les minutes pendant lesquelles elle vous lit sa réponse. Quota atteint, elle ne
démarre plus.

## Étapes

1. Cliquez le bouton **onde sonore** — « Converser à la voix ». Autorisez le micro si le navigateur le
   demande. Un bandeau apparaît au-dessus du champ : « **Je vous écoute** », avec une note claire.
2. Parlez, puis laissez une courte pause. MIA détecte la fin de votre phrase et l'envoie : deux
   notes montantes confirment le départ, et votre message apparaît dans le fil, comme si vous
   l'aviez tapé.
3. « **MIA réfléchit…** » : MIA prépare sa réponse. Un léger tic, de temps en temps, dit qu'elle
   travaille encore ; et quand elle fait quelque chose de particulier, elle l'annonce à voix haute —
   « Recherche sur Internet », « Recherche dans les bases de connaissances », « Consultation du
   cerveau MyBrain », « Recherche dans Gmail »… les mêmes libellés que la petite trace affichée
   au-dessus de sa réponse.
4. « **MIA vous répond** » : MIA commence à parler **dès sa première phrase écrite**, sans attendre
   la fin de sa réponse ; la suite est lue au fur et à mesure qu'elle s'écrit dans le fil. Si elle a
   tout dit et que la suite n'est pas encore là (un outil travaille, par exemple), elle marque une
   pause — le tic revient — puis reprend. Pour l'arrêter, dites « **stop** » (voir *Interrompre MIA
   à la voix*) ou cliquez **Passer**.
5. La réponse entièrement lue, la note claire revient : MIA vous écoute de nouveau. Parlez, et ainsi
   de suite.
6. **Terminer**, un nouveau clic sur l'onde sonore, ou simplement « **au revoir** », met fin à la
   conversation.

## Interrompre MIA à la voix

Pendant que MIA lit sa réponse, dites « **stop** » — ou « arrête », « tais-toi », « attends »,
« ça suffit » : elle se tait aussitôt, la note claire retentit, et elle vous écoute. Vous pouvez
enchaîner dans le même souffle : « stop, et pour demain ? » arrête la lecture **et** pose la
question suivante.

Si sa réponse s'écrit encore quand vous l'interrompez — « stop » ou **Passer** —, l'écriture
s'arrête aussi, comme avec le bouton Stop du chat : le texte déjà écrit reste dans le fil, marqué
« réponse interrompue ». Votre question suivante part dès que la réponse arrêtée est close, en
général dans la seconde.

Pendant la lecture, MIA n'entend **que** ces ordres d'arrêt. Une remarque, un « oui », une question
lancée par-dessus sa voix sont ignorés : pour lui parler, attendez la note claire, ou dites « stop »
d'abord. C'est ce qui l'empêche de prendre sa propre voix, ou un mot dit à un voisin, pour une
demande.

Le bouton **« Stop »** du bandeau allume ou éteint cette écoute, et s'en souvient **sur cet
appareil** :

- **allumé** (par défaut sur ordinateur et sur Android), le micro reste ouvert pendant que MIA lit —
  ces minutes comptent comme des minutes d'écoute ;
- **éteint** (par défaut sur iPhone et iPad), le micro est coupé pendant la lecture, et seul
  **Passer** l'interrompt. Sur ces appareils, un micro ouvert fait baisser la voix de MIA : allumez
  le bouton si vous préférez pouvoir l'interrompre à la voix.

## Répondre à une carte à la voix

Quand MIA prépare une action qui demande votre accord — un évènement, un document, un message
Slack, un email —, la carte s'affiche sous sa réponse, comme à l'écrit, avec une ligne
« **À la voix :** … » qui rappelle quoi dire. MIA vous le dit aussi en terminant sa réponse. Il
suffit alors de **dire le bouton** :

| Carte | Ce que vous dites | Ce qui se passe |
|---|---|---|
| Action à approuver | « approuve », « valide », « vas-y, crée-le » | L'action est exécutée. |
| Action à approuver | « annule », « laisse tomber » | L'action est abandonnée, rien n'est fait. |
| Plusieurs actions | « approuve tout », « annule tout » | Toutes les actions en attente, d'un coup (jamais un email). |
| Email | « envoie-le », « envoyer » | MIA demande confirmation : dites « **confirme** » pour envoyer, « retour » pour renoncer. |
| Email | « crée le brouillon », « en brouillon » | Le brouillon est créé dans votre messagerie. |
| Email | « plus court », « plus formel », « corrige les fautes » | MIA réécrit le message et affiche la nouvelle version. |
| Email | « améliore », puis votre consigne | MIA demande quoi changer, et applique ce que vous dites. |
| Email | « version précédente » | Revient à la version d'avant la dernière retouche. |
| Email | « abandonne », « laisse tomber » | L'email est abandonné. |

MIA annonce ce qu'elle a fait — « C'est fait », « Email envoyé », « Brouillon créé », « C'est
annulé » —, puis vous écoute de nouveau. La voix agit sur la carte en attente **la plus récente**
de la conversation.

Trois règles de prudence :

- **Un « oui » ne suffit pas.** Approuver une action ou confirmer un envoi demande le mot de
  l'action — « approuve », « confirme ». « Oui », « ok », « d'accord » sont trop courants dans une
  pièce pour déclencher quoi que ce soit : MIA vous redit simplement quoi dire.
- **Un envoi se confirme toujours.** « Envoie-le » n'envoie rien : il ouvre la confirmation,
  exactement comme le bouton **Envoyer**.
- **Dans le doute, c'est un message.** « Envoie-le à Paul plutôt », « ne l'approuve pas », « ajoute
  que je serai en retard » ne sont pas des ordres : ils partent vers l'assistant, qui prépare une
  nouvelle carte ou vous répond.

Les boutons restent là : cliquer fait exactement la même chose.

## Régler la voix

**Volume.** Le curseur **Volume de la voix**, dans le bandeau, règle la voix de MIA et tous les sons
de la conversation ; il est mémorisé pour vos prochaines conversations sur cet appareil. Sur iPhone
et iPad, le navigateur ne laisse pas une page régler le volume : le curseur n'y apparaît pas, ce sont
les **boutons de volume de l'appareil** qui règlent, et la voix sort par le haut-parleur.

**Longueur des réponses.** La liste déroulante du bandeau choisit des réponses *Brèves*, *Standard*
ou *Détaillées*. Elle prend effet à votre prochaine question : la réponse déjà en cours garde la
longueur demandée au moment où vous avez parlé. C'est le même réglage que la carte **Voix** de vos
**Préférences IA**, il vous suit donc sur tous vos appareils.

**Vitesse de lecture.** Elle se règle dans **Préférences IA**, carte **Voix**, de 0,75× à 2×. Elle
s'applique à la voix et aux annonces, jamais aux petits sons de repère, et ne consomme aucun crédit
supplémentaire.

## Ce qu'il faut savoir

- **Chaque tour est un message ordinaire.** L'assistant choisi, les bases de connaissances cochées,
  les connecteurs allumés et les fichiers joints s'appliquent comme à l'écrit, et tout reste dans
  le fil de la conversation.
- **MIA répond plus court, et sans mise en forme**, parce qu'elle sait que sa réponse sera lue. Un
  tableau ou un bloc de code n'est pas lu à voix haute : elle en donne l'essentiel et propose de
  détailler par écrit.
- **La voix n'attend pas la fin de la réponse** : elle démarre à la première phrase, puis lit la
  suite par passages de quelques phrases, toujours coupés en fin de phrase. Lire en direct ne coûte
  rien de plus : ce sont les mêmes minutes lues.
- **Les annonces sont courtes et rares** : une par type d'action et par tour, quatre au plus, et
  jamais pour une action qui a échoué — c'est la réponse qui l'explique. Elles ne coupent jamais
  la voix de MIA : ce qui doit être dit attend son tour.
- Un message **tapé au clavier** pendant la conversation part normalement, mais sa réponse n'est
  pas lue.
- **C'est toujours vous qui décidez.** Un ordre dit à voix haute vaut un clic de votre part : il
  est exécuté par votre navigateur, avec les mêmes vérifications. L'assistant, lui, ne peut
  toujours ni approuver ni envoyer quoi que ce soit.
- Dire « stop » alors que MIA ne lit plus n'envoie rien : elle continue simplement de vous écouter.
- Si un **outil de génération** est engagé (image, page web, présentation…), votre tour lui est
  confié, comme un message écrit ; il n'y a alors rien à lire, MIA vous écoute de nouveau.
- Changer de conversation termine la conversation vocale ; la réponse en cours, elle, se termine.
- **Sur téléphone et tablette, l'écran reste allumé** tant que la conversation est en cours, puis
  retrouve son délai de veille habituel dès que vous la terminez. Sans cela, la mise en veille
  suspendrait la page et couperait le dialogue pendant que MIA réfléchit ou parle.

## En cas de problème

| Ce qui arrive | Quoi faire |
|---|---|
| « Accès au micro refusé. » | Autoriser le micro dans les réglages du navigateur. |
| « Conversation vocale : aucun son reçu. Vérifiez que le micro capte du son. » | Vérifier l'entrée micro choisie par votre système, et son niveau. |
| MIA coupe votre phrase trop tôt | Parlez d'une traite, sans longue pause au milieu d'une phrase ; ou dictez puis envoyez (bouton micro). |
| Pas de bouton onde sonore | La conversation vocale est masquée ou non configurée sur votre instance. Voir avec votre administrateur. |
| La fenêtre **Quota mensuel atteint** s'ouvre, ou la conversation s'arrête d'elle-même | Votre quota du mois est épuisé : attendre le 1er du mois, ou demander une augmentation à un administrateur. |
| « Conversation vocale interrompue — … » | Le modèle n'a pas pu répondre. Le message reprend la cause affichée dans le fil (**Crédit épuisé chez** l'éditeur, **Service surchargé**, **Trop de contenu pour ce modèle**…) : l'encadré ⚠️ dit quoi faire — voir le tableau de [Choisir un modèle](../modeles/choisir-un-modele.md) —, puis relancez la conversation vocale. Une réponse qui s'arrête en route est lue jusqu'à sa dernière ligne, qui en donne la raison. |
| « Lecture à voix haute impossible — … » | La synthèse vocale a échoué pour cette réponse ; la notification en donne la cause. La réponse reste lisible dans le fil, et MIA vous écoute de nouveau. Si la cause est **Crédit épuisé chez** l'éditeur ou **Accès au service refusé**, prévenez un administrateur. |
| « Conversation vocale impossible — Crédit épuisé chez OpenAI » | Le service qui écoute votre voix ne répond plus pour votre organisation : ce n'est ni votre micro ni votre compte. Prévenez un administrateur ; en attendant, écrivez vos messages. |
| La voix marque une pause au milieu de la réponse | MIA a lu tout ce qui était déjà écrit et attend la suite — un outil travaille, ou le modèle écrit moins vite qu'elle ne parle : elle reprend d'elle-même. **Passer** ou « stop » arrête la réponse. |
| La voix est faible, ou sort par l'écouteur du téléphone | Montez le volume avec le curseur du bandeau, ou les boutons de l'appareil. Sur iPhone et iPad, éteignez le bouton **« Stop »** du bandeau : micro ouvert pendant la lecture, la voix y est moins forte. Si elle reste dans l'écouteur, fermez les autres applications qui utilisent le micro. |
| MIA ne s'arrête pas quand je dis « stop » | Vérifiez que le bouton **« Stop »** du bandeau est allumé. Dites l'ordre seul, distinctement ; l'arrêt suit d'une à deux secondes. **Passer** reste disponible. |
| MIA s'interrompt toute seule pendant sa lecture | Le micro capte sa voix ou une voix voisine : baissez le volume, utilisez un casque, ou éteignez le bouton **« Stop »**. |
| Je dis « oui » et MIA me redemande quoi dire | C'est voulu : approuver ou confirmer demande le mot de l'action — « approuve », « confirme ». |
| Mon ordre est parti comme un message | Il n'a pas été reconnu comme un ordre (phrase plus longue, négation, nom d'un autre destinataire). Redites-le simplement, avec les mots de la ligne « À la voix » de la carte, ou cliquez le bouton. |
| Pas d'annonce des actions | Elles ne sont dites que pendant une conversation vocale, et seulement pour les actions réussies ; une annonce absente ne signifie pas que l'action n'a pas eu lieu — la trace au-dessus de la réponse fait foi. |
| L'écran s'éteint pendant la conversation, et le dialogue se coupe | Votre navigateur ne sait pas garder l'écran allumé (version ancienne), ou un économiseur de batterie l'en empêche : mettez-le à jour ou désactivez l'économiseur le temps de la conversation ; sinon, touchez l'écran de temps en temps. |
| « La conversation vocale est désactivée par l'administrateur. » | Le bouton a été masqué pendant que votre page était ouverte : rechargez-la. |
