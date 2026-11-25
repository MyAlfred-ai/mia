<?php

declare(strict_types=1);

/**
 * D'où viennent les lignes d'une table permanente : un classeur déposé
 * (Excel, CSV), des colonnes saisies à la main, ou une table de travail
 * gardée depuis une conversation. Rend toujours la même forme —
 * `{columns, rows, origin, notes}` —, que KbTableStore écrit.
 *
 * Rien n'est coupé ici : un fichier plus long que le plafond est REFUSÉ par
 * KbTableStore (KbTableTooLargeException), avec le nombre de lignes trouvé.
 */
final class KbTableImport
{
    public const ORIGIN_FILE         = 'fichier';
    public const ORIGIN_MANUAL       = 'saisie';
    public const ORIGIN_CONVERSATION = 'conversation';

    /**
     * Un classeur déposé par le navigateur (entrée de $_FILES).
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, origin: array, notes: list<string>}
     * @throws \RuntimeException fichier absent, illisible, ou qui n'est pas un classeur (message pour la personne)
     */
    public static function fromUpload(array $file, string $sheet = ''): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || (string)($file['tmp_name'] ?? '') === '') {
            throw new \RuntimeException(t('Choisissez un fichier Excel ou CSV.'));
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(t('Le fichier n\'a pas pu être envoyé. Réessayez, ou vérifiez sa taille ({max} Mo au plus).', [
                'max' => (int)round(LoopFiles::MAX_BYTES / 1048576),
            ]));
        }
        return self::fromPath((string)$file['tmp_name'], (string)($file['type'] ?? ''), (string)($file['name'] ?? 'fichier'), $sheet);
    }

    /**
     * Un classeur sur disque.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, origin: array, notes: list<string>}
     */
    public static function fromPath(string $path, string $mime, string $name, string $sheet = ''): array
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        $name = $name !== '' ? mb_substr($name, 0, 160) : 'fichier';
        if (!LoopFiles::isSheet($mime, $name)) {
            throw new \RuntimeException(t('« {nom} » n’est pas un classeur : déposez un fichier Excel (.xlsx, .xls) ou CSV.', ['nom' => $name]));
        }
        $grid   = LoopFiles::sheet($path, $mime, $name, $sheet);
        $header = array_values((array)($grid['header'] ?? []));
        if ($header === []) {
            throw new \RuntimeException(t('« {nom} » ne contient aucune ligne lisible.', ['nom' => $name]));
        }
        // Le plafond de colonnes se constate sur l'en-tête LU : la
        // normalisation s'arrêterait d'elle-même, sans le dire.
        if (count($header) > WorkTableOps::MAX_COLUMNS) {
            throw new KbTableTooLargeException(0, count($header));
        }
        $columns = WorkTableOps::columns($header);
        $rows    = WorkTableOps::rows($columns, (array)($grid['rows'] ?? []));
        $columns = WorkTableOps::typed($columns, $rows);

        $notes  = [];
        $sheets = array_values(array_filter((array)($grid['sheets'] ?? []), 'is_string'));
        $read   = (string)($grid['sheet'] ?? ($sheets[0] ?? ''));
        if (count($sheets) > 1) {
            $notes[] = t('Onglet lu : « {onglet} ». Autres onglets du fichier : {autres}.', [
                'onglet' => $read,
                'autres' => implode(', ', array_slice(array_values(array_diff($sheets, [$read])), 0, 12)),
            ]);
        }
        return [
            'columns' => $columns,
            'rows'    => $rows,
            'origin'  => ['kind' => self::ORIGIN_FILE, 'file' => $name] + (count($sheets) > 1 ? ['sheet' => $read] : []),
            'notes'   => $notes,
        ];
    }

    /**
     * Une table commencée à la main : ses colonnes, saisies en une ligne
     * (« Nom, Email, Ville ») ou une par ligne. Aucune ligne encore.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, origin: array, notes: list<string>}
     */
    public static function fromHeaders(mixed $raw): array
    {
        $labels = is_array($raw)
            ? $raw
            : (preg_split('/[\n;,\t]+/u', (string)$raw) ?: []);
        $labels = array_values(array_filter(
            array_map(static fn($l): string => trim((string)preg_replace('/\s+/u', ' ', is_scalar($l) ? (string)$l : '')), $labels),
            static fn(string $l): bool => $l !== ''
        ));
        if ($labels === []) {
            throw new \RuntimeException(t('Donnez au moins une colonne : « Nom, Email, Ville ».'));
        }
        if (count($labels) > WorkTableOps::MAX_COLUMNS) {
            throw new KbTableTooLargeException(0, count($labels));
        }
        return [
            'columns' => WorkTableOps::columns($labels),
            'rows'    => [],
            'origin'  => ['kind' => self::ORIGIN_MANUAL],
            'notes'   => [],
        ];
    }

    /**
     * Une table de travail GARDÉE depuis une conversation : ses colonnes
     * visibles et toutes ses lignes. Les colonnes techniques (identifiants
     * d'un mail, d'un fichier) restent dans la conversation.
     *
     * @param array                       $table table de travail (WorkTableStore)
     * @param list<array<string, string>> $rows  toutes ses lignes
     * @return array{columns: list<array>, rows: list<array<string,string>>, origin: array, notes: list<string>}
     */
    public static function fromWorkTable(array $table, array $rows, string $conversationTitle = ''): array
    {
        $columns = WorkTableView::visibleColumns((array)($table['columns'] ?? []));
        $keys    = WorkTableOps::keys($columns);
        $kept    = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $key) {
                $line[$key] = (string)($row[$key] ?? '');
            }
            $kept[] = $line;
        }
        $columns = array_map(static fn(array $c): array => ['tech' => false] + $c, $columns);
        return [
            'columns' => $columns,
            'rows'    => $kept,
            'origin'  => array_filter([
                'kind'            => self::ORIGIN_CONVERSATION,
                'conversation_id' => (int)($table['conversation_id'] ?? 0),
                'conversation'    => mb_substr(trim($conversationTitle), 0, 120),
                'table'           => (string)($table['name'] ?? ''),
            ], static fn($v): bool => $v !== '' && $v !== 0),
            'notes'   => [],
        ];
    }

    /** D'où vient la table, en une phrase pour la personne. '' si rien à dire. Pure. */
    public static function originLabel(array $origin): string
    {
        return match ((string)($origin['kind'] ?? '')) {
            self::ORIGIN_FILE => (string)($origin['file'] ?? '') !== ''
                ? t('Importée de {fichier}', ['fichier' => (string)$origin['file']])
                    . ((string)($origin['sheet'] ?? '') !== '' ? ' · ' . t('onglet « {onglet} »', ['onglet' => (string)$origin['sheet']]) : '')
                : t('Importée d\'un fichier'),
            self::ORIGIN_MANUAL       => t('Saisie à la main'),
            self::ORIGIN_CONVERSATION => (string)($origin['conversation'] ?? '') !== ''
                ? t('Gardée de la conversation « {titre} »', ['titre' => (string)$origin['conversation']])
                : t('Gardée d\'une conversation'),
            default => '',
        };
    }
}
