<?php

declare(strict_types=1);

/** Génération d'image de l'outil « + » du chat, en tâche de fond. */
class ImageJob extends ChatJob
{
    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        return $chat->runImagine($payload, $ctx->userId(), (int)$ctx->conversationId());
    }
}
