<?php

declare(strict_types=1);

/**
 * Fournisseur d'identité pour la CONNEXION à l'application (à ne pas confondre
 * avec les connecteurs personnels Drive/Gmail/Agenda, qui accèdent aux données
 * d'un utilisateur déjà connecté).
 *
 * Le contrat est volontairement étroit : construire une URL de consentement,
 * traiter le retour, et se tester sans faire tourner un navigateur. Tout le
 * reste (création du compte, rapprochement, session) appartient à
 * AuthController — un fournisseur ne connaît rien de la table `users`.
 */
interface SsoProvider
{
    /** Clé technique : 'google' | 'microsoft'. */
    public function key(): string;

    /** Libellé affiché sur le bouton de connexion. */
    public function label(): string;

    /** True si les identifiants nécessaires sont renseignés. */
    public function isConfigured(): bool;

    /** URL de consentement, avec state (et nonce) déposés en session. */
    public function authUrl(): string;

    /**
     * Traite le retour du fournisseur.
     *
     * Format normalisé, identique pour tous les fournisseurs :
     *   subject        identifiant stable et unique chez le fournisseur
     *   email          adresse déclarée (peut être vide)
     *   email_verified le fournisseur ATTESTE-T-IL cette adresse ? Ce drapeau
     *                  gouverne le rapprochement avec un compte existant : une
     *                  adresse non attestée ne doit jamais donner accès à un
     *                  compte qu'on n'a pas créé.
     *   name, picture  affichage (picture peut être vide)
     *
     * @return array{provider:string,subject:string,email:string,email_verified:bool,name:string,picture:string}|null
     */
    public function handleCallback(string $code, string $state): ?array;

    /**
     * Vérifie les identifiants SANS aller-retour navigateur (bouton « Tester »
     * de l'administration). Ne lève jamais.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public function testCredentials(): array;
}
