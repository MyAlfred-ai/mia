<?php

declare(strict_types=1);

/**
 * Réponse de l'assistant à un message du chat, en tâche de fond.
 *
 * C'est le job le plus chargé : RAG, mémoire long terme, outils
 * (image, Drive, Gmail, Agenda), recherche web, bascule LLM de secours. Tout
 * cela reste dans `ChatController::runSend()` — le job ne fait que fournir le
 * canal d'émission et la sonde d'arrêt (cf. ChatJob).
 *
 * `regenerate()` reste synchrone : rejouer une réponse est un geste où
 * l'utilisateur attend devant l'écran, et le watchdog client s'appuie dessus
 * pour ses nouvelles tentatives automatiques.
 */
class ChatSendJob extends ChatJob
{
    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        // Tâche programmée restée en file trop longtemps (aucun worker
        // disponible) : rien n'est généré, l'exécution est consignée manquée.
        if (isset($payload['scheduled'])) {
            Scheduler::guardLateStart($payload);
        }
        return $chat->runSend($payload, $ctx->userId(), (int)$ctx->conversationId());
    }
}
