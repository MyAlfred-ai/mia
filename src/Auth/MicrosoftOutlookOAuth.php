<?php

declare(strict_types=1);

/**
 * OAuth Outlook PAR UTILISATEUR. Mécanique commune : MicrosoftUserOAuth. Le
 * state `outlook_` fait router le retour par AuthController::callback() vers
 * UserSettingsController::outlookCallback().
 *
 * ── SCOPES, ET POURQUOI CEUX-LÀ ────────────────────────────────────────────
 * `Mail.Send` sert à UNE chose : le bouton « Envoyer » de la carte de
 * relecture d'un email (cf. MailDraftReview). Aucun outil du modèle n'envoie
 * quoi que ce soit : l'envoi n'a lieu que sur ce clic explicite, après
 * confirmation, et Graph est appelé sur un brouillon que l'application vient
 * de créer (UserOutlookService::sendMessage) — jamais `/me/sendMail`. Un
 * annuaire qui refuse cette permission au consentement garde les brouillons
 * et perd seulement le bouton (la carte l'explique, la page Connexions
 * l'affiche « Envoi — »).
 *
 * `Mail.ReadWrite` est le MINIMUM pour créer un brouillon : Microsoft Graph
 * n'a pas d'équivalent de `gmail.compose` (« écrire sans toucher au reste »).
 * Ce scope ouvre donc aussi, côté API, la modification et la suppression de
 * messages existants — exactement la situation de Google Tasks, et elle se
 * traite pareil : ce sont des garde-fous APPLICATIFS qui remplacent le
 * demi-droit manquant.
 *   1. aucun outil de suppression ni de modification n'est exposé au modèle —
 *      `UserOutlookService` n'implémente que recherche, lecture, brouillon et
 *      l'envoi de CE brouillon ;
 *   2. la création de brouillon passe par la file d'approbation (rien n'est
 *      écrit pendant le stream), l'envoi par un clic « Envoyer » distinct ;
 *   3. la page Connexions annonce l'étendue réelle du droit accordé.
 *
 * `Mail.Read` est demandé EN PLUS de `Mail.ReadWrite` alors qu'il en est un
 * sous-ensemble : si l'annuaire n'accorde que la lecture (consentement
 * administrateur restrictif), le jeton revient avec `Mail.Read` seul et le
 * connecteur fonctionne en lecture seule au lieu d'échouer entièrement.
 *
 * Les scopes réellement accordés sont stockés (colonne `scope`) et vérifiés
 * par UserOutlookService (canRead / canDraft / canSend).
 */
class MicrosoftOutlookOAuth extends MicrosoftUserOAuth
{
    public const SCOPES = [
        'offline_access',   // produit le refresh token
        'User.Read',        // adresse du compte connecté (affichage)
        'Mail.Read',        // recherche + lecture
        'Mail.ReadWrite',   // brouillons
        'Mail.Send',        // bouton « Envoyer » de la carte de relecture, rien d'autre
    ];

    public const STATE_PREFIX   = 'outlook_';
    protected const SESSION_KEY = 'outlook_oauth_state';
    protected const LOG_LABEL   = 'Outlook OAuth';
}
