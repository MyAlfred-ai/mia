<?php

declare(strict_types=1);

/**
 * Refus du fournisseur de synthèse vocale, PORTEUR de sa cause présentée
 * (LlmErrorPresenter) : « Crédit épuisé chez OpenAI », « Accès au service
 * refusé »… Le message de l'exception est celui de la charge utile — lisible
 * par n'importe qui, sans le détail brut du fournisseur (qui ne part qu'aux
 * journaux). Même rôle que JobErrorException pour les générations.
 */
class SpeechSynthesisException extends \RuntimeException
{
    /** @param array $payload charge utile UI (LlmErrorPresenter::present()) */
    public function __construct(private array $payload)
    {
        parent::__construct((string)($payload['message'] ?? 'Synthèse vocale impossible.'));
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
