<?php

declare(strict_types=1);

/**
 * Une table de travail remise à l'utilisateur : un fichier Excel ou CSV,
 * fabriqué AU TÉLÉCHARGEMENT depuis les lignes en base (rien n'est stocké en
 * plus, le fichier suit la table).
 *
 * Le lien est rendu par l'outil `exporter_table` et cité tel quel par le
 * modèle : `/chat/{conversation}/tables/{table}/{nom}.xlsx`. La route
 * (WorkTableController) revérifie que la conversation est à la personne et
 * que la table est à cette conversation.
 */
final class WorkTableExport
{
    public const FORMATS = ['xlsx', 'csv'];

    /** Au-delà, un classeur Excel ne se fabrique plus en mémoire : la table part en CSV. */
    private const XLSX_MAX_CELLS = 100000;

    private const BOM = "\xEF\xBB\xBF";

    public static function format(mixed $wanted): string
    {
        $f = is_scalar($wanted) ? strtolower(trim((string)$wanted, " .")) : '';
        return in_array($f, self::FORMATS, true) ? $f : 'xlsx';
    }

    /** Nom du fichier : celui demandé, sinon celui de la table — sûr dans une adresse. */
    public static function fileName(array $table, mixed $wanted, string $format): string
    {
        $base = is_scalar($wanted) ? (string)preg_replace('/\.(xlsx|csv)$/i', '', trim((string)$wanted)) : '';
        $slug = str_replace('_', '-', WorkTableOps::slug($base !== '' ? $base : (string)$table['name'], 60));
        return ($slug !== '' ? $slug : 'table') . '.' . $format;
    }

    /** Adresse de téléchargement (relative à l'application). */
    public static function link(int $conversationId, int $tableId, string $fileName): string
    {
        return '/chat/' . $conversationId . '/tables/' . $tableId . '/' . rawurlencode($fileName);
    }

    /**
     * Adresse de téléchargement d'une table PERMANENTE engagée dans une
     * conversation : même route, la table désignée par « p » + l'identifiant
     * de sa base (WorkTableController::download revérifie qu'elle est bien
     * ouverte à la personne pour cette conversation). Une VUE de la table
     * (WorkTableRecipe) voyage dans l'adresse (`?vue=`) : le fichier est
     * calculé au clic, il suit la table.
     */
    public static function permanentLink(int $conversationId, int $kbId, string $fileName, string $recipe = ''): string
    {
        return '/chat/' . $conversationId . '/tables/p' . $kbId . '/' . rawurlencode($fileName)
            . ($recipe !== '' ? '?vue=' . $recipe : '');
    }

    /** Un lien de table, tel que la route le sert. */
    public static function isFileName(string $file): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9\-]{0,60}\.(?:xlsx|csv)$/', $file) === 1;
    }

    /** La table tient-elle dans un classeur Excel ? (colonnes techniques exclues) */
    public static function fitsXlsx(array $columns, int $rowCount, int $csvBytes): bool
    {
        $visible = count(array_filter($columns, static fn(array $c): bool => empty($c['tech']))) ?: count($columns);
        return ($rowCount + 1) * max(1, $visible) <= self::XLSX_MAX_CELLS
            && $csvBytes <= FileFormats::DEFAULT_MAX_BYTES;
    }

    /**
     * Le fichier. Un classeur qui ne peut pas être fabriqué (table trop
     * grande, bibliothèque absente) part en CSV : un fichier lisible vaut
     * mieux qu'un refus.
     *
     * @param list<array<string, string>> $rows
     */
    public static function build(array $table, array $rows, string $fileName, string $locale = 'fr', string $author = 'MIA'): GeneratedFile
    {
        $columns   = (array)$table['columns'];
        $delimiter = str_starts_with($locale, 'fr') ? ';' : ',';
        $format    = str_ends_with($fileName, '.csv') ? 'csv' : 'xlsx';

        if ($format === 'xlsx') {
            $source = WorkTableOps::csv($columns, $rows, $delimiter, "\n");
            if (self::fitsXlsx($columns, count($rows), strlen($source))) {
                try {
                    return FileFactory::build($fileName, $source, 'csv', '', ['locale' => $locale, 'author' => $author]);
                } catch (FileBuildException $e) {
                    error_log('[Tables] classeur Excel non fabriqué, CSV livré : ' . $e->getMessage());
                }
            }
            $fileName = (string)preg_replace('/\.xlsx$/', '.csv', $fileName);
        }
        return new GeneratedFile(
            $fileName,
            'text/csv; charset=utf-8',
            self::BOM . WorkTableOps::csv($columns, $rows, $delimiter, "\r\n")
        );
    }
}
