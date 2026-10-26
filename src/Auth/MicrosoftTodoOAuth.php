<?php

declare(strict_types=1);

/**
 * OAuth de MICROSOFT TO DO par utilisateur. Mécanique commune :
 * MicrosoftUserOAuth (application Azure du login, URI de redirection du login,
 * jetons roulants). Le state `mstodo_` fait router le retour par
 * AuthController::callback() vers UserSettingsController::mstodoCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * `Tasks.ReadWrite` est le MINIMUM pour créer ou modifier une tâche : Graph n'a
 * aucun droit « écrire sans supprimer ». Ce scope ouvre donc aussi, côté API,
 * la suppression de tâches et de listes — même situation que Google Tasks,
 * qu'Outlook et que le calendrier Outlook, et elle se traite pareil : ce sont
 * des garde-fous APPLICATIFS qui remplacent le demi-droit manquant.
 *   1. aucun outil de suppression n'est exposé au modèle —
 *      `UserMicrosoftTodoService` n'implémente que lister, lire, créer et
 *      modifier (« c'est fait » = marquer terminée, ce qui garde la trace) ;
 *   2. chaque écriture passe par la file d'approbation (rien n'est écrit
 *      pendant le stream) ;
 *   3. la page Connexions annonce l'étendue réelle du droit accordé.
 *
 * `Tasks.Read` est demandé EN PLUS bien qu'il soit un sous-ensemble : si
 * l'annuaire n'accorde que la lecture (consentement administrateur restrictif),
 * le jeton revient avec `Tasks.Read` seul et le connecteur fonctionne en
 * consultation au lieu d'échouer entièrement.
 *
 * `Tasks.Read.Shared` / `Tasks.ReadWrite.Shared` ne sont JAMAIS demandés : ils
 * ouvrent les listes PARTAGÉES par d'autres personnes, au-delà de ce que
 * l'utilisateur a choisi de relier. Et c'est précisément pourquoi
 * `scopeGranted()` compare les jetons EXACTEMENT : `Tasks.Read` est un préfixe
 * de `Tasks.ReadWrite` ET de `Tasks.Read.Shared` (cf. MicrosoftUserApiService).
 *
 * Les scopes réellement accordés sont stockés (colonne `scope`) et vérifiés par
 * UserMicrosoftTodoService (canRead / canWrite).
 */
class MicrosoftTodoOAuth extends MicrosoftUserOAuth
{
    public const SCOPES = [
        'offline_access',    // produit le refresh token
        'User.Read',         // adresse du compte connecté (affichage)
        'Tasks.Read',        // listes et tâches en consultation
        'Tasks.ReadWrite',   // création et modification approuvées UNIQUEMENT
    ];

    public const STATE_PREFIX   = 'mstodo_';
    protected const SESSION_KEY = 'mstodo_oauth_state';
    protected const LOG_LABEL   = 'Microsoft To Do OAuth';
}
