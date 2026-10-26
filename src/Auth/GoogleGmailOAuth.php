<?php

declare(strict_types=1);

/**
 * OAuth Gmail PAR UTILISATEUR. Mécanique commune : GoogleUserOAuth. Le state
 * `gmail_` fait router le retour par AuthController::callback() vers
 * UserSettingsController::gmailCallback().
 *
 * Scopes : gmail.readonly (recherche + lecture) et gmail.compose (brouillons
 * ET envoi). Google décrit `gmail.compose` comme « Manage drafts and send
 * emails » : le consentement déjà donné pour les brouillons couvre
 * `messages.send`, et `gmail.send` n'est donc JAMAIS demandé — il n'ajouterait
 * rien. La promesse « rien ne part sans votre clic » est tenue par le code,
 * pas par un scope : aucun outil du modèle n'envoie, seule la carte de
 * relecture le fait, sur le bouton « Envoyer », après confirmation
 * (cf. UserGmailService::sendMessage et MailDraftReview).
 * Les scopes réellement accordés sont stockés (colonne scope) et vérifiés par
 * UserGmailService (canRead / canDraft / canSend).
 */
class GoogleGmailOAuth extends GoogleUserOAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.compose', // brouillons + envoi sur clic — gmail.send serait redondant
        // Lecture des réglages de la boîte, pour la SIGNATURE par défaut. MIA
        // n'appelle que `settings.sendAs.list` et n'écrit aucun réglage. Une
        // connexion antérieure ne l'a pas : la signature est alors simplement
        // absente, jamais une erreur.
        'https://www.googleapis.com/auth/gmail.settings.basic',
        'openid',
        'email',
    ];

    public const STATE_PREFIX   = 'gmail_';
    protected const SESSION_KEY = 'gmail_oauth_state';
    protected const LOG_LABEL   = 'Gmail OAuth';
}
