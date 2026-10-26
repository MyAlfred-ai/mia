<?php

declare(strict_types=1);

/**
 * Santé de l'installation : sonde publique et page d'administration.
 */
class HealthController
{
    /**
     * `GET /healthz` — sonde publique, non authentifiée.
     *
     * Réponse VOLONTAIREMENT pauvre : identifiant du contrôle et statut, rien
     * d'autre. Pas de nom d'hôte, pas de nom de variable, pas de message
     * d'exception — cette route est ouverte, elle ne doit rien apprendre à qui
     * la sonde. Le détail est réservé à /admin/diagnostics et à doctor.php.
     *
     * Traitée très tôt dans public/index.php, avant Session::start() et avant
     * les semis paresseux : appelée toutes les 30 secondes par le HEALTHCHECK
     * Docker, elle déclencherait sinon le calcul de signature du RAG à chaque
     * battement.
     */
    public static function healthz(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $controles = [];

        // La configuration est testée ici plutôt que laissée à la page
        // d'erreur : la sonde doit répondre du JSON en toute circonstance.
        if (Bootstrap::configProblems() !== []) {
            $controles[] = ['id' => 'configuration', 'statut' => Diagnostics::FAIL];
        } else {
            try {
                $controles = Diagnostics::liveness();
            } catch (\Throwable $e) {
                ErrorHandler::journaliser($e);
                $controles[] = ['id' => 'interne', 'statut' => Diagnostics::FAIL];
            }
        }

        $etat = Diagnostics::worst($controles);

        $resume = [];
        foreach ($controles as $c) {
            $resume[(string) $c['id']] = (string) $c['statut'];
        }

        http_response_code($etat === Diagnostics::FAIL ? 503 : 200);

        echo json_encode([
            'status'  => $etat === Diagnostics::FAIL ? 'fail' : ($etat === Diagnostics::WARN ? 'warn' : 'ok'),
            'version' => defined('APP_VERSION') ? APP_VERSION : '0.0.0',
            'checks'  => $resume,
            'ts'      => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** `GET /admin/diagnostics` — le même diagnostic, complet, pour l'administrateur. */
    public static function diagnostics(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');

        $controles = Diagnostics::run(true);
        $global    = Diagnostics::worst($controles);
        $groupes   = Diagnostics::parCategorie($controles);
        $page      = 'diagnostics';

        require ROOT . '/templates/admin/diagnostics.php';
    }
}
