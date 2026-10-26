<?php

declare(strict_types=1);

/**
 * Carte d'itinéraire en tâche de fond : la brique Création d'un workflow
 * (le chat, lui, la génère en direct). Même corps que la route du chat —
 * ChatController::runMap.
 */
class MapJob extends ChatJob
{
    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        return $chat->runMap($payload, $ctx->userId(), (int)$ctx->conversationId());
    }
}
