<?php

declare(strict_types=1);

/**
 * Export CSV téléchargeable, compatible Excel FR : BOM UTF-8 + séparateur ';'.
 * Neutralise l'injection de formule tableur (cellules commençant par = + - @).
 */
final class CsvExport
{
    private const SEPARATOR = ';';

    /**
     * Émet les headers de téléchargement, streame le CSV puis termine la requête.
     *
     * @param list<string> $header
     * @param iterable<array<int, string|int|float|null>> $rows
     */
    public static function stream(string $filename, array $header, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, self::SEPARATOR);
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::sanitizeCell(...), $row), self::SEPARATOR);
        }
        fclose($out);
        exit;
    }

    /**
     * Préfixe "'" les cellules texte interprétables comme formule par un tableur.
     * Les valeurs numériques (int/float) sont émises telles quelles — un nombre
     * négatif n'est pas une formule.
     */
    private static function sanitizeCell(string|int|float|null $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $str = (string)($value ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    }
}
