<?php

declare(strict_types=1);

/**
 * Échec de job PORTEUR d'un message destiné à l'utilisateur.
 *
 * `JobQueue::execute()` remplace la charge utile générique par celle-ci : sans
 * elle, une panne d'API se présenterait comme un « La génération n'a pas
 * abouti » indifférencié, alors que la cause exacte (clé manquante, 429,
 * fournisseur injoignable) est connue et déjà formatée par `LlmErrorPresenter`.
 */
class JobErrorException extends \RuntimeException
{
    /** @param array $payload charge utile UI (LlmErrorPresenter::present()) */
    public function __construct(private array $payload, string $message = 'Job en erreur')
    {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
