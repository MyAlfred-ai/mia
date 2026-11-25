<?php

declare(strict_types=1);

/**
 * Des lignes déjà calculées (celles d'une vue : WorkTableRecipe), lues comme
 * celles d'une table en base — le volet les montre page par page, avec le
 * même code. L'identifiant demandé est ignoré : il n'y a qu'une liste.
 */
final class ArrayTableRows implements WorkTableRows
{
    /** @param list<array<string, string>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
    {
        return array_slice($this->rows, max(0, $offset), $limit);
    }
}
