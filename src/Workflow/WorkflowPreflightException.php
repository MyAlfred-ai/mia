<?php

declare(strict_types=1);

/**
 * Un workflow ne peut pas démarrer : ce qu'il faut régler d'abord (cf.
 * WorkflowSpec::preflight). Message affichable, traduit ; `items` = les
 * points, avec le connecteur à relier quand il y en a un.
 */
final class WorkflowPreflightException extends \InvalidArgumentException
{
    /** @param list<array> $items */
    public function __construct(string $message, private readonly array $items = [])
    {
        parent::__construct($message);
    }

    /** @return list<array> */
    public function items(): array
    {
        return $this->items;
    }
}
