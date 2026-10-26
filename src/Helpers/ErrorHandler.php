<?php

declare(strict_types=1);

/**
 * Gestionnaire global d'erreurs — SOURCE UNIQUE du rendu des pannes.
 *
 * Volontairement SANS AUCUNE DÉPENDANCE : ni Settings, ni Database, ni autoload
 * Composer. Il est enregistré avant tout le reste et doit rester capable de
 * rendre une page quand justement tout le reste est mort — une page d'erreur qui
 * interroge la base pour connaître le thème rejoue l'exception qu'elle est
 * censée afficher (c'est ce que font aujourd'hui 404.php et 403.php, d'où la
 * page blanche jusque sur ces deux-là quand MySQL est injoignable).
 *
 * Chaque panne reçoit un IDENTIFIANT D'INCIDENT affiché à l'écran et repris en
 * tête de la ligne de journal : l'utilisateur peut le communiquer, l'exploitant
 * retrouve la trace complète sans avoir à corréler des horodatages.
 */
final class ErrorHandler
{
    /** Environnements où la trace complète est affichée à l'écran. */
    private const ENVS_VERBEUX = ['local', 'dev', 'development'];

    /** Types d'erreurs non rattrapables, à récupérer à l'extinction. */
    private const FATALES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private static string $env = 'production';
    private static bool $cli = false;
    /** Vrai dès qu'une panne a été rendue : évite le doublon exception + extinction. */
    private static bool $rendu = false;

    // ---------------------------------------------------------------- Enregistrement

    /** Contexte web. À appeler AVANT config/app.php (pour voir une fatale dedans). */
    public static function register(): void
    {
        self::configurerJournal();

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** Contexte CLI (worker, doctor, scripts de maintenance) : STDERR + code 1. */
    public static function registerCli(): void
    {
        self::$cli = true;
        self::configurerJournal();

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Bascule verbeux / production. À appeler juste après config/app.php, une
     * fois APP_ENV connue — pas avant : l'environnement n'existe pas encore.
     */
    public static function setEnvironment(string $env): void
    {
        self::$env = strtolower(trim($env));
    }

    // ---------------------------------------------------------------- Rappels PHP

    public static function handleException(\Throwable $e): void
    {
        $incident = self::journaliser($e);

        if (self::$cli) {
            self::rendreCli($e, $incident);
            exit(1);
        }

        // Les pannes d'infrastructure ont leur propre page : elles se règlent en
        // corrigeant la configuration, pas en signalant un bug.
        if ($e instanceof DatabaseUnavailableException || $e instanceof MigrationFailedException) {
            self::rendreWeb('database', $incident, $e);
            return;
        }

        self::rendreWeb('500', $incident, $e);
    }

    /**
     * Les avertissements sont JOURNALISÉS, jamais convertis en exceptions : du
     * code existant s'appuie sur leur tolérance (`@copy`, `@unlink`…), et les
     * promouvoir ferait tomber des chemins qui fonctionnent aujourd'hui.
     * Seul E_USER_ERROR — levé explicitement par le code — devient une exception.
     */
    public static function handleError(int $type, string $message, string $fichier = '', int $ligne = 0): bool
    {
        // Erreur supprimée par l'opérateur `@` : le masque courant l'exclut.
        if ((error_reporting() & $type) === 0) {
            return true;
        }

        if ($type === E_USER_ERROR) {
            throw new \ErrorException($message, 0, $type, $fichier, $ligne);
        }

        error_log(sprintf('[%s] %s @ %s:%d', self::nomDuType($type), $message, $fichier, $ligne));

        return true; // pris en charge : pas de double journalisation par PHP
    }

    /** Rattrape les fatales (E_ERROR, E_PARSE…) que set_exception_handler ne voit pas. */
    public static function handleShutdown(): void
    {
        $fatale = error_get_last();
        if ($fatale === null || (($fatale['type'] & self::FATALES) === 0)) {
            return;
        }
        if (self::$rendu) {
            return;
        }

        $incident = self::identifiant();
        error_log(sprintf(
            '[incident %s] %s: %s @ %s:%d',
            $incident,
            self::nomDuType($fatale['type']),
            $fatale['message'],
            $fatale['file'],
            $fatale['line']
        ));

        if (self::$cli) {
            fwrite(STDERR, sprintf(
                "\nErreur fatale : %s\n  %s:%d\n  incident %s\n",
                $fatale['message'],
                $fatale['file'],
                $fatale['line'],
                $incident
            ));
            return;
        }

        $detail = self::estVerbeux()
            ? sprintf("%s\n%s:%d", $fatale['message'], $fatale['file'], $fatale['line'])
            : null;
        self::rendreWeb('500', $incident, null, $detail);
    }

    // ---------------------------------------------------------------- Rendu

    /**
     * @param string          $vue      nom du template dans templates/errors/
     * @param string|null     $detail   texte technique déjà formaté (fatales)
     */
    private static function rendreWeb(string $vue, string $incident, ?\Throwable $e = null, ?string $detail = null): void
    {
        if (self::$rendu) {
            return;
        }
        self::$rendu = true;

        // Flux déjà commencé (SSE, téléchargement, réponse partielle) : on ne
        // peut plus ni changer le statut ni le type de contenu. Injecter du HTML
        // dans un `text/event-stream` corromprait le flux — le journal suffit.
        if (headers_sent()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $code = ($vue === 'database') ? 503 : 500;
        header_remove('Content-Type');
        http_response_code($code);

        if ($detail === null && $e !== null && self::estVerbeux()) {
            $detail = self::traceLisible($e);
        }

        if (self::veutDuJson()) {
            header('Content-Type: application/json; charset=utf-8');
            $charge = [
                'error'    => self::messagePublic($vue, $e),
                'incident' => $incident,
            ];
            if ($detail !== null) {
                $charge['detail'] = $detail;
            }
            echo json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        $gabarit = defined('ROOT') ? ROOT . '/templates/errors/' . $vue . '.php' : '';
        if ($gabarit !== '' && is_file($gabarit)) {
            // Variables exposées au template.
            $message = self::messagePublic($vue, $e);
            require $gabarit;
            return;
        }

        // Filet ultime : le template lui-même est introuvable.
        echo '<!DOCTYPE html><html lang="fr"><meta charset="utf-8">'
            . '<title>Erreur</title><body style="font-family:sans-serif;padding:2rem">'
            . '<h1>Une erreur interne est survenue.</h1>'
            . '<p>Identifiant d\'incident : <code>' . htmlspecialchars($incident) . '</code></p>'
            . '</body></html>';
    }

    private static function rendreCli(\Throwable $e, string $incident): void
    {
        fwrite(STDERR, sprintf(
            "\nErreur : %s\n  %s\n  %s:%d\n  incident %s (trace complète dans storage/logs/errors.log)\n",
            $e->getMessage(),
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $incident
        ));
        if (self::estVerbeux()) {
            fwrite(STDERR, "\n" . $e->getTraceAsString() . "\n");
        }
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Journalise la panne et renvoie son identifiant d'incident.
     * Public : les chemins qui rattrapent une exception pour la présenter
     * eux-mêmes (jobs, contrôleurs) doivent pouvoir la tracer à l'identique.
     */
    public static function journaliser(\Throwable $e): string
    {
        $incident = self::identifiant();

        error_log(sprintf(
            '[incident %s] %s: %s @ %s:%d',
            $incident,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        error_log('[incident ' . $incident . '] ' . $e->getTraceAsString());

        $precedente = $e->getPrevious();
        while ($precedente !== null) {
            error_log(sprintf(
                '[incident %s] causée par %s: %s @ %s:%d',
                $incident,
                get_class($precedente),
                $precedente->getMessage(),
                $precedente->getFile(),
                $precedente->getLine()
            ));
            $precedente = $precedente->getPrevious();
        }

        return $incident;
    }

    /**
     * Journalisation dans storage/logs/errors.log dès l'enregistrement : sans
     * cela, tout ce qui casse avant que index.php n'ait fixé error_log part dans
     * le vide (le chemin par défaut était un reliquat Windows `C:/laragon/...`).
     */
    private static function configurerJournal(): void
    {
        ini_set('log_errors', '1');
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        if (!defined('ROOT')) {
            return;
        }
        $dossier = ROOT . '/storage/logs';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        if (is_dir($dossier) && is_writable($dossier)) {
            ini_set('error_log', $dossier . '/errors.log');
        }
    }

    private static function identifiant(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return substr(md5(uniqid('', true)), 0, 12);
        }
    }

    private static function estVerbeux(): bool
    {
        return in_array(self::$env, self::ENVS_VERBEUX, true);
    }

    private static function messagePublic(string $vue, ?\Throwable $e): string
    {
        if ($vue === 'database') {
            // Message déjà rédigé en français et expurgé par l'exception.
            return $e !== null ? $e->getMessage() : 'La base de données est injoignable.';
        }

        return 'Une erreur interne est survenue.';
    }

    private static function traceLisible(\Throwable $e): string
    {
        return sprintf(
            "%s: %s\n%s:%d\n\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    /**
     * Heuristique JSON volontairement RECOPIÉE ici (6 lignes) plutôt que
     * déléguée à AuthMiddleware : le gestionnaire doit fonctionner même quand
     * config/classes.php n'a pas encore été chargé.
     */
    private static function veutDuJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $ajax   = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        return str_contains($accept, 'application/json')
            || strcasecmp($ajax, 'XMLHttpRequest') === 0;
    }

    private static function nomDuType(int $type): string
    {
        return match ($type) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'Erreur fatale',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Avertissement',
            E_NOTICE, E_USER_NOTICE     => 'Remarque',
            E_DEPRECATED, E_USER_DEPRECATED => 'Obsolète',
            E_PARSE                     => 'Erreur d\'analyse',
            E_RECOVERABLE_ERROR         => 'Erreur récupérable',
            default                     => 'Erreur',
        };
    }
}
