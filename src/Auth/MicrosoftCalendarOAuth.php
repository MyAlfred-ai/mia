<?php

declare(strict_types=1);

/**
 * OAuth du CALENDRIER OUTLOOK par utilisateur. Mécanique commune :
 * MicrosoftUserOAuth (application Azure du login, URI de redirection du login,
 * jetons roulants). Le state `mscal_` fait router le retour par
 * AuthController::callback() vers UserSettingsController::mscalendarCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * `Calendars.ReadWrite` est le MINIMUM pour créer ou modifier un évènement
 * (doc Graph « Create event » / « Update event », relue le 2026-11-13 :
 * permission la moins étendue, déléguée). Ce scope ouvre aussi, côté API, la
 * suppression et l'annulation — même situation que Google Tasks et qu'Outlook,
 * et elle se traite pareil : des garde-fous APPLICATIFS.
 *   1. aucun outil de suppression ni d'annulation n'est exposé au modèle —
 *      `UserOutlookCalendarService` liste, cherche, lit, crée et modifie ;
 *   2. création et modification passent par la file d'approbation (rien n'est
 *      écrit pendant le stream), et la carte nomme les invités à qui Outlook
 *      écrira : inviter quelqu'un lui envoie un email, sans réglage possible ;
 *   3. la page Connexions annonce l'étendue réelle du droit accordé.
 *
 * Aucun scope de plus pour les invités ni pour la réunion Teams :
 * `isOnlineMeeting` + `onlineMeetingProvider` sont des propriétés de
 * l'évènement, créées par Exchange (pas d'`OnlineMeetings.ReadWrite`).
 *
 * `Calendars.Read` est demandé EN PLUS bien qu'il soit un sous-ensemble : si
 * l'annuaire n'accorde que la lecture (consentement administrateur restrictif),
 * le jeton revient avec `Calendars.Read` seul et le connecteur fonctionne en
 * consultation au lieu d'échouer entièrement.
 *
 * `Calendars.ReadBasic` n'est JAMAIS demandé : il ne rend que des créneaux
 * occupé/libre, sans titre ni participants — un agenda illisible. Et c'est
 * précisément pourquoi `scopeGranted()` compare les jetons EXACTEMENT :
 * `Calendars.Read` en est un préfixe (cf. MicrosoftUserApiService).
 *
 * `MailboxSettings.Read` n'est pas demandé non plus : le fuseau de l'utilisateur
 * n'est pas lu chez Microsoft, c'est celui du SERVEUR qui fait foi partout dans
 * MIA (dates du system prompt, bornes de recherche, création d'évènement).
 * L'ajouter élargirait le consentement pour un gain nul.
 *
 * Les scopes réellement accordés sont stockés (colonne `scope`) et vérifiés par
 * UserOutlookCalendarService (canRead / canWrite).
 */
class MicrosoftCalendarOAuth extends MicrosoftUserOAuth
{
    public const SCOPES = [
        'offline_access',        // produit le refresh token
        'User.Read',             // adresse du compte connecté (affichage)
        'Calendars.Read',        // liste des agendas + consultation
        'Calendars.ReadWrite',   // création et modification d'évènements, approuvées UNIQUEMENT
    ];

    public const STATE_PREFIX   = 'mscal_';
    protected const SESSION_KEY = 'mscal_oauth_state';
    protected const LOG_LABEL   = 'Calendrier Outlook OAuth';
}
