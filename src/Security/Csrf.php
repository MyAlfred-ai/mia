<?php

declare(strict_types=1);

class Csrf
{
    /** Retourne le token CSRF de la session (le génère si absent). */
    public static function token(): string
    {
        if (!Session::has('csrf')) {
            Session::set('csrf', bin2hex(random_bytes(32)));
        }
        return Session::get('csrf');
    }

    /** Retourne un champ hidden HTML prêt à insérer dans un formulaire. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    /**
     * Jeton soumis par la requête courante ('' si aucun) :
     *  - le champ POST `csrf_token`  (formulaires HTML)
     *  - le header HTTP `X-CSRF-Token` (requêtes AJAX/fetch)
     *
     * Sert au diagnostic : jeton ABSENT = requête forgée ou hors page ;
     * jeton PRÉSENT mais refusé = page chargée avant une expiration / un
     * redémarrage de session (jeton périmé).
     */
    public static function submitted(): string
    {
        $fromPost   = $_POST['csrf_token']          ?? '';
        $fromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return (string)($fromPost ?: $fromHeader);
    }

    /** Vérifie le jeton CSRF de la requête courante. */
    public static function verify(): bool
    {
        $submitted = self::submitted();
        if ($submitted === '') return false;
        return hash_equals(self::token(), $submitted);
    }
}
