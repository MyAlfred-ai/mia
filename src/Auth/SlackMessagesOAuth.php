<?php

declare(strict_types=1);

/**
 * OAuth Slack PAR UTILISATEUR. Mécanique commune : SlackUserOAuth. Le state
 * `slack_` fait router le retour par AuthController::callback() vers
 * UserSettingsController::slackCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * Ce sont des `user_scope` : MIA agit AU NOM de la personne, et ne voit donc
 * jamais que ce qu'elle voit déjà. Un canal privé où elle n'est pas reste
 * invisible, quoi qu'il arrive — c'est Slack qui le garantit, pas nous.
 *
 * Lecture :
 *   - `search:read` — la recherche, qui couvre TOUT ce que la personne peut
 *     lire (canaux publics, privés dont elle est membre, messages directs) ;
 *   - `channels:read` / `groups:read` / `im:read` / `mpim:read` — lister les
 *     conversations pour en désigner une par son nom (« #projet-x ») ;
 *   - `channels:history` / `groups:history` / `im:history` / `mpim:history` —
 *     lire les messages d'une conversation et les réponses d'un fil ;
 *   - `users:read` — remplacer les `<@U04F…>` par des noms lisibles. Sans lui,
 *     chaque message rapporté citerait des identifiants, et le modèle les
 *     recopierait tels quels.
 *
 *   - `users:read.email` — retrouver la personne à qui écrire par son ADRESSE
 *     (`users.lookupByEmail`) : une brique Slack de workflow prend souvent son
 *     destinataire dans une Fiche, qui porte un email. Lu pour désigner une
 *     personne, jamais rapporté au modèle.
 *
 * Écriture :
 *   - `chat:write` — publier un message EN SON NOM, et rien d'autre. C'est le
 *     seul scope d'écriture ; il n'ouvre ni la suppression (`chat:write` permet
 *     `chat.delete` sur ses propres messages, côté API) ni la modification.
 *     Même situation que `Mail.ReadWrite` chez Outlook ou que Google Tasks, et
 *     mêmes garde-fous APPLICATIFS en remplacement du demi-droit manquant :
 *       1. aucun outil de suppression ni de modification n'est exposé au
 *          modèle — `UserSlackService` n'implémente que recherche, lecture et
 *          publication ;
 *       2. chaque publication passe par la file d'approbation (rien n'est
 *          envoyé pendant le stream) ;
 *       3. la page Connexions et la fiche d'administration annoncent l'étendue
 *          réelle du droit accordé.
 *
 *   - `im:write` — ouvrir la conversation directe avec UNE personne désignée,
 *     pour y publier (même file d'approbation, carte qui nomme la personne).
 *     `mpim:write` (conversations de groupe) n'est pas demandé ;
 *   - `files:write` — joindre les fichiers d'une brique Slack de workflow
 *     (images, documents du dossier). Le modèle ne désigne jamais un fichier.
 *     Ni `files:read`, ni suppression de fichier.
 *
 * Ces trois autorisations sont arrivées avec la brique Slack des workflows
 * (2026-10-26) : une connexion plus ancienne ne les a pas, et le dit (page
 * Comptes connectés, « À régler » d'un workflow) jusqu'à sa reconnexion.
 *
 * ⚠ Une publication Slack est IRRÉVERSIBLE du point de vue des lecteurs :
 * contrairement à un brouillon Gmail ou Outlook, le message part réellement
 * dans le canal dès l'approbation. La carte d'approbation le dit en toutes
 * lettres (cf. `UserSlackService::actionPreview`).
 *
 * Les scopes réellement accordés sont stockés (colonne `scope`) et vérifiés par
 * UserSlackService (canSearch / canRead / canPost) : un consentement partiel
 * donne une connexion dégradée plutôt qu'un connecteur cassé.
 */
class SlackMessagesOAuth extends SlackUserOAuth
{
    public const SCOPES = [
        'search:read',
        'channels:read',
        'groups:read',
        'im:read',
        'mpim:read',
        'channels:history',
        'groups:history',
        'im:history',
        'mpim:history',
        'users:read',
        'users:read.email', // retrouver un destinataire par son adresse
        'chat:write',       // publication approuvée — jamais de suppression
        'im:write',         // message direct à une personne désignée
        'files:write',      // fichiers joints par une brique de workflow
    ];

    public const STATE_PREFIX   = 'slack_';
    protected const SESSION_KEY = 'slack_oauth_state';
    protected const LOG_LABEL   = 'Slack OAuth';
}
