<?php

declare(strict_types=1);

/** Génération vidéo (Sora…) en tâche de fond — plusieurs minutes en pratique. */
class VideoJob extends ChatJob
{
    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        return $chat->runVideo($payload, $ctx->userId(), (int)$ctx->conversationId());
    }
}
