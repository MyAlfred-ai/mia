<?php

declare(strict_types=1);

/**
 * OAuth Google Tasks PAR UTILISATEUR. Mécanique commune : GoogleUserOAuth.
 * Le state `gtasks_` fait router le retour par AuthController::callback() vers
 * UserSettingsController::tasksCallback().
 *
 * Scopes : `tasks.readonly` (consultation des listes et des tâches) et `tasks`
 * (création, modification, suppression). Google Tasks n'offre AUCUN scope
 * intermédiaire — il n'existe pas d'« écriture sans suppression » : demander
 * l'écriture, c'est demander la suppression. D'où deux garde-fous applicatifs
 * qui, eux, existent : chaque écriture passe par la file d'approbation humaine,
 * et l'utilisateur peut couper la suppression dans les préférences du
 * connecteur (cf. UserTasksService::PREF_ALLOW_DELETE).
 * Les scopes réellement accordés sont stockés (colonne scope) et vérifiés par
 * UserTasksService (canRead / canWrite) : un consentement partiel donne une
 * connexion en lecture seule plutôt qu'un connecteur cassé.
 */
class GoogleTasksOAuth extends GoogleUserOAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/tasks.readonly',
        'https://www.googleapis.com/auth/tasks', // écriture : création, modification, suppression
        'openid',
        'email',
    ];

    public const STATE_PREFIX   = 'gtasks_';
    protected const SESSION_KEY = 'gtasks_oauth_state';
    protected const LOG_LABEL   = 'Tâches OAuth';
}
