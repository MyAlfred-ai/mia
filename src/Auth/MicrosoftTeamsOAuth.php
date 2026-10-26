<?php

declare(strict_types=1);

/**
 * OAuth de MICROSOFT TEAMS par utilisateur. Mécanique commune :
 * MicrosoftUserOAuth (application Azure du login, URI de redirection du login,
 * jetons roulants). Le state `msteams_` fait router le retour par
 * AuthController::callback() vers UserSettingsController::teamsCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * Les plus étroits que Graph accepte pour chaque geste (tableaux « Least
 * privileged permissions » de la documentation Graph) :
 *   - `Chat.Read` : conversations privées, de groupe et de réunion, et leurs
 *     messages. JAMAIS `Chat.ReadWrite`, qui ouvrirait aussi la modification
 *     et la suppression de messages et la gestion des membres ;
 *   - `ChatMessage.Send` : publier dans une conversation, et rien d'autre ;
 *   - `Team.ReadBasic.All` + `Channel.ReadBasic.All` : nom des équipes dont la
 *     personne est membre, et nom de leurs canaux ;
 *   - `ChannelMessage.Read.All` : messages des canaux. **Le seul qui exige le
 *     consentement d'un ADMINISTRATEUR de l'annuaire**, quel que soit le
 *     réglage du consentement utilisateur : il n'existe aucune permission
 *     déléguée plus étroite pour lire un canal ;
 *   - `ChannelMessage.Send` : publier dans un canal ou répondre dans un fil.
 *
 * Conséquence du point précédent : Entra refuse la demande ENTIÈRE tant qu'un
 * administrateur n'a pas consenti pour l'organisation (« Accorder un
 * consentement administrateur » sur l'application d'entreprise) — pas
 * seulement la lecture des canaux. Une fois ce consentement donné, chacun
 * relie Teams sans écran de consentement : le socle ne force jamais
 * `prompt=consent` (cf. MicrosoftUserOAuth::PROMPT).
 *
 * `ChatMessage.Send` / `ChannelMessage.Send` permettraient côté API d'écrire
 * dans n'importe quelle conversation de la personne : le garde-fou est
 * APPLICATIF — chaque publication passe par la file d'approbation, l'outil ne
 * modifie ni ne supprime aucun message, et la page Comptes connectés annonce
 * l'étendue réelle du droit accordé.
 *
 * Les comptes Microsoft PERSONNELS (outlook.com, hotmail…) n'ont pas d'API
 * Teams : ce connecteur ne sert qu'aux comptes d'organisation.
 *
 * Les scopes réellement accordés sont stockés (colonne `scope`) et vérifiés
 * par UserTeamsService (canReadChats, canReadChannels, canSendChats…).
 */
class MicrosoftTeamsOAuth extends MicrosoftUserOAuth
{
    public const SCOPES = [
        'offline_access',            // produit le refresh token
        'User.Read',                 // adresse du compte connecté (affichage)
        'Chat.Read',                 // conversations et leurs messages
        'ChatMessage.Send',          // publier dans une conversation (approuvé)
        'Team.ReadBasic.All',        // équipes dont la personne est membre
        'Channel.ReadBasic.All',     // canaux de ces équipes
        'ChannelMessage.Read.All',   // messages des canaux — consentement ADMINISTRATEUR
        'ChannelMessage.Send',       // publier dans un canal (approuvé)
    ];

    public const STATE_PREFIX   = 'msteams_';
    protected const SESSION_KEY = 'msteams_oauth_state';
    protected const LOG_LABEL   = 'Microsoft Teams OAuth';
}
