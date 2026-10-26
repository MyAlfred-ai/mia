<?php

declare(strict_types=1);

/**
 * Erreur LLM jugée "retryable" : panne provider (5xx), rate-limit (429),
 * timeout réseau, ou pas de réponse — bref, situations où basculer sur un
 * LLM de secours a une chance d'aboutir.
 *
 * Levée par le wrapper de stream du ChatController quand l'adaptateur émet
 * son chunk d'erreur AVANT le premier token utile.
 */
class LlmRetryableException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
