<?php

declare(strict_types=1);

/**
 * Amorçage : distingue les trois états dans lesquels une installation peut se
 * trouver avant d'être utilisable.
 *
 * Auparavant un critère UNIQUE (`APP_SECRET` vide) déclenchait le wizard de
 * configuration. Sous Docker, compose transmet toujours `APP_SECRET` — vide
 * quand le .env manque — et `getenv()` renvoie alors `''` et non `false` :
 * `config/app.php` ne charge donc jamais le fichier .env, `isNeeded()` reste
 * vrai pour toujours, et l'on tourne en boucle de redirection vers /setup dont
 * le formulaire écrit un .env À L'INTÉRIEUR du conteneur (perdu au rebuild).
 *
 * Les trois états sont désormais séparés :
 *   1. configuration absente  → page d'erreur qui NOMME la variable manquante
 *   2. base injoignable       → page d'erreur qui nomme l'hôte et le remède
 *   3. aucun administrateur   → /setup, qui ne fait plus que créer ce compte
 */
final class Bootstrap
{
    /** Valeur de démonstration livrée dans la documentation : équivaut à vide. */
    private const SECRET_FACTICE = 'change_me_32_chars_minimum_secret';

    /**
     * Problèmes de configuration RÉDHIBITOIRES : l'application ne peut rien
     * faire d'utile tant qu'ils ne sont pas réglés.
     *
     * Volontairement limité à « absent ou factice ». Un secret présent mais
     * court est signalé par la page de diagnostic, pas ici : transformer une
     * instance qui tourne en page d'erreur au premier déploiement serait pire
     * que le défaut qu'on corrige.
     *
     * @return list<array{variable:string,message:string,remede:string}>
     */
    public static function configProblems(): array
    {
        $problemes = [];

        if (self::estVideOuFactice(APP_SECRET)) {
            $problemes[] = [
                'variable' => 'APP_SECRET',
                'message'  => 'Clé de signature des sessions. Sans elle, aucune connexion ne peut être maintenue.',
                'remede'   => 'Générez-la : openssl rand -hex 32',
            ];
        }

        if (self::estVideOuFactice(ENCRYPTION_KEY)) {
            $problemes[] = [
                'variable' => 'ENCRYPTION_KEY',
                'message'  => 'Clé de chiffrement des clés API stockées en base. Vide, elles seraient chiffrées '
                    . 'avec une clé prévisible — autant dire en clair.',
                'remede'   => 'Générez-la : openssl rand -hex 32 — et ne la changez plus ensuite '
                    . '(les clés API déjà enregistrées deviendraient illisibles).',
            ];
        }

        return $problemes;
    }

    /**
     * Base joignable ? Renvoie `null` si tout va bien, sinon un message français
     * déjà expurgé (jamais le mot de passe, que PDO glisse volontiers dans ses
     * messages d'erreur).
     */
    public static function databaseReachable(): ?string
    {
        try {
            Database::getInstance();
            return null;
        } catch (DatabaseUnavailableException | MigrationFailedException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
            return 'La base de données a répondu de façon inattendue.';
        }
    }

    /**
     * Aucun compte n'existe encore : l'installation attend son premier
     * administrateur.
     *
     * Même prédicat que la règle « premier utilisateur Google = administrateur »
     * de User::createFromSso() — les deux chemins d'amorçage partagent
     * désormais le même critère et ne peuvent plus se contredire.
     *
     * Base injoignable → `false` : ce n'est pas un problème de compte, et la
     * panne de base a sa propre page. Renvoyer `true` enverrait l'utilisateur
     * remplir un formulaire qui ne peut rien enregistrer.
     */
    public static function needsFirstAdmin(): bool
    {
        try {
            return (int) Database::getInstance()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rend la page « configuration incomplète » et arrête la requête.
     *
     * @param list<array{variable:string,message:string,remede:string}> $problemes
     */
    public static function renderConfigError(array $problemes): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header_remove('Content-Type');
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $sousDocker = self::sousDocker();
        require ROOT . '/templates/errors/config.php';
        exit;
    }

    /**
     * Vrai si l'on tourne dans un conteneur : le remède affiché n'est pas le
     * même (variable d'environnement du compose) qu'en installation native
     * (fichier .env à la racine).
     */
    public static function sousDocker(): bool
    {
        return is_file('/.dockerenv') || getenv('DB_HOST') === 'db';
    }

    private static function estVideOuFactice(string $valeur): bool
    {
        $valeur = trim($valeur);

        return $valeur === '' || $valeur === self::SECRET_FACTICE;
    }
}
