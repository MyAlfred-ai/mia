<?php

declare(strict_types=1);

/**
 * Job `workflow` : ce qu'un workflow fait hors d'un tour de conversation.
 *  - `condition`  : la QUESTION d'une condition, posée au modèle de l'instance
 *                   (WorkflowEngine::runConditionJob) ;
 *  - `route`      : le choix de la voie d'une DÉCISION, par le modèle de
 *                   l'instance (WorkflowEngine::runRouteJob) ;
 *  - `loop_until` : la condition de sortie d'une boucle « jusqu'à » ;
 *  - `loop_split` : la lecture des éléments d'une boucle « pour chaque »
 *                   (un fichier, un connecteur, MIA — LoopRunner::split) ;
 *  - `loop_item`  : un élément complété avant son tour (son fichier, le
 *                   contenu d'un email — LoopRunner::fetch) ;
 *  - `loop_actions` : les actions retenues à la relecture groupée d'une boucle ;
 *  - `deliver`    : les livraisons du livrable (email, Drive, Slack, base) ;
 *  - `watch`      : la vérification d'une boîte mail ou d'un dossier surveillé
 *                   (WorkflowTriggers), qui lance une exécution par nouveauté.
 *
 * Les quatre premiers vivent dans le couloir de la conversation de l'exécution
 * (CONVERSATION_LANE_TYPES) : les briques d'une exécution partent l'une après
 * l'autre. Hors rejeu (NON_IDEMPOTENT) : reposer la question coûterait deux
 * fois, livrer deux fois enverrait deux emails.
 */
class WorkflowJob implements JobHandler
{
    public function run(array $payload, JobContext $ctx): array
    {
        $op = (string)($payload['op'] ?? '');
        $ctx->emit(['phase' => 'workflow_' . ($op !== '' ? $op : 'job')]);
        return match ($op) {
            'condition'  => WorkflowEngine::runConditionJob($payload),
            'route'      => WorkflowEngine::runRouteJob($payload),
            'loop_until' => WorkflowEngine::runLoopUntilJob($payload),
            'loop_split' => WorkflowEngine::runLoopSplitJob($payload),
            'loop_item'  => WorkflowEngine::runLoopItemJob($payload),
            'loop_actions' => WorkflowEngine::runLoopActionsJob($payload),
            'deliver'    => WorkflowEngine::runDeliverJob($payload),
            'watch'      => WorkflowTriggers::runWatchJob($payload),
            default      => throw new JobErrorException(['kind' => 'generic', 'message' => t('Opération de workflow inconnue.')], 'opération inconnue'),
        };
    }
}
