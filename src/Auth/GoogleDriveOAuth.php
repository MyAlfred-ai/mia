<?php

declare(strict_types=1);

/**
 * OAuth Google Drive PAR UTILISATEUR — distinct du login (GoogleOAuth, qui ne
 * demande que openid/email/profile et jette son token) et de la sync KB
 * (GoogleDriveProvider, clé API globale, dossiers publics uniquement).
 * Mécanique commune : GoogleUserOAuth. Le state `drive_` fait router le retour
 * par AuthController::callback() vers UserSettingsController::driveCallback().
 *
 * Les scopes documents/spreadsheets permettent la création et la modification
 * de Google Docs et Sheets. Un utilisateur connecté AVANT leur ajout garde la
 * lecture seule : les scopes réellement accordés sont stockés (colonne scope)
 * et vérifiés par UserDriveService — reconnexion = re-consent = écriture.
 *
 * `drive.metadata` (renommer / déplacer) est demandé À LA PLACE du scope
 * `drive` complet, volontairement : renommer et déplacer ne touchent QUE des
 * métadonnées (`name`, `parents`), et `drive` ouvrirait en plus l'écriture du
 * CONTENU de tous les fichiers — bien au-delà du besoin. C'est le scope le
 * plus étroit que l'API accepte pour `files.update` sur ces champs.
 * Il autorise en revanche la mise à la corbeille (`trashed` est une
 * métadonnée) : comme pour Google Tasks, où aucun demi-droit n'existe, ce sont
 * des garde-fous APPLICATIFS qui tiennent — aucun outil de suppression n'est
 * exposé au modèle, et toute écriture passe par la file d'approbation.
 *
 * Sa PORTÉE est GLOBALE, contrairement à `drive.file` : Google le documente
 * comme un scope restreint « View and manage metadata of files in your Drive »,
 * donc il couvre TOUS les fichiers de l'utilisateur, pas seulement ceux créés
 * par l'application. Le connecteur a longtemps affirmé le contraire — dans son
 * message d'erreur ET dans la guidance du modèle —, ce qui faisait passer une
 * autorisation simplement non accordée pour une impossibilité de nature, et
 * décourageait la seule action utile : se reconnecter en acceptant tout.
 *
 * TOUS ces scopes sont décochables un à un sur l'écran de consentement
 * (« granular consent »), y compris la LECTURE. Aucun n'est donc acquis :
 * `UserDriveService` vérifie chacun d'eux avant d'exposer l'outil
 * correspondant, la page Connexions les affiche, et Google les redit à chaque
 * rafraîchissement de jeton (ils sont réenregistrés à ce moment-là).
 *
 * `drive.file` (duplication d'un fichier via `files.copy`) est demandé pour la
 * même raison d'étroitesse : c'est un accès PAR FICHIER, limité à ceux que
 * l'application crée — donc juste ce qu'il faut pour déposer une copie, sans
 * les droits d'écriture globaux de `drive`. Contrepartie assumée : Google peut
 * refuser la copie d'un fichier que l'app n'a pas elle-même créé (le fichier
 * SOURCE n'entre pas dans le périmètre de `drive.file`) — `UserDriveService::copyFile()`
 * traduit alors le 403 en message explicite plutôt que de laisser une erreur nue.
 */
class GoogleDriveOAuth extends GoogleUserOAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/drive.metadata',
        'https://www.googleapis.com/auth/drive.file',
        'https://www.googleapis.com/auth/documents',
        'https://www.googleapis.com/auth/spreadsheets',
        'openid',
        'email',
    ];

    public const STATE_PREFIX   = 'drive_';
    protected const SESSION_KEY = 'drive_oauth_state';
    protected const LOG_LABEL   = 'Drive OAuth';
}
