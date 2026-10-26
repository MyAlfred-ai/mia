<?php

declare(strict_types=1);

/**
 * Construction d'un deck de slides en tâche de fond (étape 2 de l'outil
 * Présentation). Le plan (étape 1) reste synchrone : l'utilisateur l'attend
 * pour le relire et le corriger.
 */
class PresentationJob extends ChatJob
{
    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        return $chat->runPresentationBuild($payload, $ctx->userId(), (int)$ctx->conversationId());
    }
}
