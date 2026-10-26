<?php

declare(strict_types=1);

/**
 * OAuth du CALENDRIER OUTLOOK par utilisateur. Mécanique commune :
 * MicrosoftUserOAuth (application Azure du login, URI de redirection du login,
 * jetons roulants). Le state `mscal_` fait router le retour par
 * AuthController::callback() vers UserSettingsController::mscalendarCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * `Calendars.ReadWrite` est le MINIMUM pour créer un évènement : Graph n'a
 * aucun droit « créer sans modifier ni supprimer ». Ce scope ouvre donc aussi,
 * côté API, la modification et la suppression d'évènements existants — même
 * situation que Google Tasks et qu'Outlook, et elle se traite pareil : ce sont
 * des garde-fous APPLICATIFS qui remplacent le demi-droit manquant.
 *   1. aucun outil de modification ni de suppression n'est exposé au modèle —
 *      `UserOutlookCalendarService` n'implémente que lister, chercher, lire et
 *      créer ;
 *   2. la création passe par la file d'approbation (rien n'est écrit pendant le
 *      stream) ;
 *   3. la page Connexions annonce l'étendue réelle du droit accordé.
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
        'Calendars.ReadWrite',   // création d'évènements approuvée UNIQUEMENT
    ];

    public const STATE_PREFIX   = 'mscal_';
    protected const SESSION_KEY = 'mscal_oauth_state';
    protected const LOG_LABEL   = 'Calendrier Outlook OAuth';
}
