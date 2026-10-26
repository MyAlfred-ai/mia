<?php

declare(strict_types=1);

/**
 * Écran « Agent » (Maintenance → Diagnostic → Agent), admins seuls : ce que
 * fait la boucle d'outils, mesuré par AgentTelemetry. Lecture seule.
 */
class AgentStatsController
{
    /** GET /admin/agent?jours=7|30|90 */
    public function index(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');

        $days    = AgentTelemetry::period($_GET['jours'] ?? 30);
        $error   = null;
        $summary = AgentTelemetry::ratios([]) + ['turns' => 0, 'chat_turns' => 0];
        $models  = [];
        $tools   = [];
        $cards   = [];
        try {
            $summary = AgentTelemetry::summary($days);
            $models  = AgentTelemetry::byModel($days);
            $tools   = AgentTelemetry::byTool($days);
            $cards   = AgentTelemetry::cards($days);
        } catch (\Throwable $e) {
            error_log('[Télémétrie agent] lecture impossible : ' . $e->getMessage());
            $error = t('Les mesures sont illisibles pour le moment (base indisponible ou migration en attente).');
        }
        $signals = AgentTelemetry::signals($summary, $tools, $cards, [self::class, 'toolLabel']);

        require ROOT . '/templates/admin/agent.php';
    }

    /**
     * Libellé lisible d'un outil (celui de la trace des réponses, dans la
     * langue de la page), sinon son nom technique.
     */
    public static function toolLabel(string $tool): string
    {
        $spec = ChatController::actionEntryForTool($tool, [], []);
        return $spec !== null && isset(ChatController::ACTION_LABELS[$spec['t']])
            ? t(ChatController::ACTION_LABELS[$spec['t']])
            : $tool;
    }
}
