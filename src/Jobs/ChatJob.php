<?php

declare(strict_types=1);

/**
 * Socle des jobs qui réutilisent la logique métier de `ChatController`.
 *
 * Le contrôleur reste la SEULE implémentation de ces générations (image,
 * vidéo, présentation) : la dupliquer dans un handler aurait garanti la
 * divergence. Le job se contente de rebrancher les deux canaux que la logique
 * attend et qui n'existent pas hors requête HTTP :
 *   - `ChatController::setEmitSink()` : les `sseEmit()` partent dans le flux
 *     rejouable du job au lieu d'une connexion ouverte ;
 *   - `LlmAdapterHelpers::setStopProbe()` : `connection_aborted()` renvoie
 *     toujours 0 dans un worker CLI, le bouton Stop serait sans effet.
 *
 * Les deux sont remis à null en `finally` — le worker enchaîne les jobs dans
 * le même process, une sonde oubliée arrêterait le job suivant.
 */
abstract class ChatJob implements JobHandler
{
    public function run(array $payload, JobContext $ctx): array
    {
        ChatController::setEmitSink(static function (array $event) use ($ctx): void {
            $ctx->emit($event);
        });
        LlmAdapterHelpers::setStopProbe(static fn(): bool => $ctx->shouldStop());

        try {
            $result = $this->execute(new ChatController(), $payload, $ctx);
        } finally {
            ChatController::setEmitSink(null);
            LlmAdapterHelpers::setStopProbe(null);
        }

        // `done` est un évènement du flux ET le résultat stocké : un client
        // attaché en direct le reçoit, un client attaché après coup le relit
        // depuis `result_json`.
        $ctx->emit($result);

        return $result;
    }

    abstract protected function execute(ChatController $chat, array $payload, JobContext $ctx): array;
}
