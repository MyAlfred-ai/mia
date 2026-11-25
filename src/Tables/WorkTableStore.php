<?php

declare(strict_types=1);

/**
 * Où vivent les tables de travail d'une conversation.
 *
 * Une interface, deux magasins : la base (SqlWorkTableStore, celui de
 * l'application) et la mémoire (tests). Les outils ne connaissent qu'elle —
 * ils s'éprouvent ainsi sans base, sur le même code que la production.
 *
 * Une table rendue : `{id, conversation_id, user_id, name, title, columns,
 * rows (nombre de lignes), origin, updated_at}`. Les LIGNES ne voyagent
 * jamais avec elle : elles se lisent à part (`rows`), par pages au besoin.
 */
interface WorkTableStore extends WorkTableRows
{
    /** @return list<array> tables de la conversation, la plus récente d'abord */
    public function all(int $conversationId): array;

    public function find(int $conversationId, string $name): ?array;

    public function findById(int $conversationId, int $tableId): ?array;

    /**
     * Crée la table, ou REMPLACE celle qui porte ce nom (colonnes et lignes).
     *
     * @param list<array{key:string,label:string,type:string,tech:bool}> $columns
     * @param list<array<string, string>>                                $rows
     * @param array<string, mixed>                                       $origin
     * @return array la table enregistrée
     * @throws \RuntimeException plafond de tables atteint
     */
    public function save(int $conversationId, int $userId, string $name, string $title, array $columns, array $rows, array $origin = []): array;

    /** @return list<array<string, string>> lignes, dans l'ordre */
    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array;

    /**
     * Complète une table SUR PLACE : ses colonnes (une colonne ajoutée) et les
     * lignes désignées par leur RANG (0 = la première, l'ordre de `rows`),
     * réécrites en entier. Les autres lignes ne bougent pas. C'est le point
     * de contrôle d'une opération longue : ce qui est fait reste fait, une
     * reprise repart de là.
     *
     * `$ifStamp` (WorkTableOps::stateStamp de la table telle que l'appelant l'a
     * lue ou laissée) : si la table a changé depuis — remplacée, transformée
     * par un autre message —, RIEN n'est écrit et la table rendue porte
     * `stale => true` : un rang ne désigne plus la même ligne.
     *
     * @param list<array{key:string,label:string,type:string,tech:bool}> $columns
     * @param array<int, array<string, string>>                          $rowsByPosition rang → ligne entière
     * @return array|null la table, null si elle n'existe plus dans cette conversation
     */
    public function patch(int $conversationId, int $tableId, array $columns, array $rowsByPosition, ?string $ifStamp = null): ?array;

    /**
     * Change le TITRE d'une table (le nom que lit la personne). Le nom court
     * — la poignée que tient le modèle — ne bouge jamais : ce qu'il a écrit
     * dans ses réponses précédentes désigne toujours la même table.
     *
     * Ni les lignes, ni les colonnes, ni la date d'écriture ne changent :
     * l'empreinte de la table (WorkTableOps::stateStamp) reste la même, une
     * opération de fond en cours sur elle continue.
     *
     * @return array|null la table, null si elle n'existe pas dans cette conversation
     */
    public function retitle(int $conversationId, int $tableId, string $title): ?array;

    public function delete(int $conversationId, string $name): bool;
}
