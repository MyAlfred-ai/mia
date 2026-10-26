<?php

declare(strict_types=1);

/**
 * OAuth Google Agenda PAR UTILISATEUR. Mécanique commune : GoogleUserOAuth.
 * Le state `gcal_` fait router le retour par AuthController::callback() vers
 * UserSettingsController::calendarCallback().
 *
 * Scopes : calendar.readonly (agendas + lecture des évènements) et
 * calendar.events (création/modification d'évènements — les créations passent
 * par la file d'approbation, et `sendUpdates` reste toujours à `none` : aucune
 * invitation n'est envoyée automatiquement). Le scope `calendar` (contrôle
 * total, partages, suppression d'agendas) n'est JAMAIS demandé.
 * Les scopes réellement accordés sont stockés (colonne scope) et vérifiés par
 * UserCalendarService (canRead / canWrite).
 */
class GoogleCalendarOAuth extends GoogleUserOAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/calendar.events', // évènements uniquement — jamais le scope calendar complet
        'openid',
        'email',
    ];

    public const STATE_PREFIX   = 'gcal_';
    protected const SESSION_KEY = 'gcal_oauth_state';
    protected const LOG_LABEL   = 'Agenda OAuth';
}
