<?php

declare(strict_types=1);

/**
 * Lecture des lignes d'une table, page par page — ce dont l'écran a besoin
 * pour afficher une grille (WorkTableView::page), que la table vive avec une
 * conversation (WorkTableStore) ou dans une base de connaissances
 * (KbTableStore).
 */
interface WorkTableRows
{
    /**
     * Lignes de la table (objets clé de colonne → texte), dans l'ordre.
     *
     * @return list<array<string, string>>
     */
    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array;
}
