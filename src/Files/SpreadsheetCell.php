<?php

declare(strict_types=1);

/**
 * Typage d'une cellule de tableur — PUR, sans bibliothèque.
 *
 * Un classeur dont toutes les cellules sont du texte ne sert à rien : les
 * sommes valent zéro, les tris sont alphabétiques, les graphiques vides. Il
 * faut donc reconnaître les nombres, pourcentages, montants et dates que le
 * modèle a écrits comme du texte.
 *
 * Et il faut le faire avec RETENUE, parce que l'erreur inverse est pire — elle
 * détruit la donnée en silence : un code postal « 01000 » devenu 1000, un
 * numéro de téléphone amputé de son zéro, un IBAN arrondi à quinze chiffres.
 * D'où la règle : dans le doute, c'est du texte.
 *
 * `$locale` tranche les deux ambiguïtés sans solution générale : « 1,234 »
 * (1,234 en français, mille deux cent trente-quatre en anglais) et
 * « 03/04/2026 » (3 avril ou 4 mars).
 */
final class SpreadsheetCell
{
    public const BLANK    = 'empty';
    public const TEXT     = 'text';
    public const NUMBER   = 'number';
    public const PERCENT  = 'percent';
    public const CURRENCY = 'currency';
    public const DATE     = 'date';
    public const DATETIME = 'datetime';
    public const BOOL     = 'bool';
    public const FORMULA  = 'formula';

    /** Au-delà de quinze chiffres significatifs, Excel arrondit : on garde le texte. */
    private const MAX_DIGITS = 15;

    private const CURRENCIES = ['€', '$', '£', 'EUR', 'USD', 'GBP', 'CHF'];

    /**
     * Première année reconnue comme date. Excel compte un 29 février 1900 qui
     * n'a jamais existé : avant mars 1900 son numéro de série est décalé d'un
     * jour. Plutôt que de reproduire le bogue, on laisse ces dates en texte.
     */
    private const MIN_YEAR = 1901;

    /**
     * Type et valeur d'une cellule.
     *
     * @return array{type: string, value: mixed, format?: string}
     *   - number   : value int|float, format = masque Excel ('' = Standard)
     *   - percent  : value float (0.125 pour « 12,5 % »), format
     *   - currency : value float, format (symbole compris)
     *   - date / datetime : value float (numéro de série Excel), format
     *   - bool     : value bool
     *   - formula  : value string (formule normalisée, « = » compris)
     *   - text     : value string ; empty : value ''
     */
    public static function classify(string $raw, string $locale = 'fr'): array
    {
        $text = self::clean($raw);
        if ($text === '') {
            return ['type' => self::BLANK, 'value' => ''];
        }

        if ($text[0] === '=') {
            $formula = SpreadsheetFormula::normalize($text);

            return $formula !== null
                ? ['type' => self::FORMULA, 'value' => $formula]
                : ['type' => self::TEXT, 'value' => $raw];
        }

        $lower = strtolower($text);
        if (in_array($lower, ['true', 'vrai'], true)) {
            return ['type' => self::BOOL, 'value' => true];
        }
        if (in_array($lower, ['false', 'faux'], true)) {
            return ['type' => self::BOOL, 'value' => false];
        }

        // Pourcentage : « 12,5 % », « 8% ».
        if (preg_match('/^(.+?)\s?%$/u', $text, $m) === 1) {
            $n = self::parseNumber($m[1], $locale);
            if ($n !== null) {
                return [
                    'type'   => self::PERCENT,
                    'value'  => $n['value'] / 100,
                    'format' => '0' . self::decimalMask($n['decimals']) . '%',
                ];
            }
        }

        // Montant : symbole après (« 1 200 € ») ou avant (« $1,200.50 »).
        foreach (self::CURRENCIES as $symbol) {
            $quoted = preg_quote($symbol, '/');
            if (preg_match('/^(.+?)\s?' . $quoted . '$/u', $text, $m) === 1
                && ($n = self::parseNumber($m[1], $locale)) !== null) {
                return [
                    'type'   => self::CURRENCY,
                    'value'  => $n['value'],
                    'format' => '#,##0' . self::decimalMask($n['decimals']) . ' "' . $symbol . '"',
                ];
            }
            if (mb_strlen($symbol, 'UTF-8') === 1
                && preg_match('/^([+-]?)' . $quoted . '\s?(.+)$/u', $text, $m) === 1
                && ($n = self::parseNumber($m[1] . $m[2], $locale)) !== null) {
                return [
                    'type'   => self::CURRENCY,
                    'value'  => $n['value'],
                    'format' => '"' . $symbol . '"#,##0' . self::decimalMask($n['decimals']),
                ];
            }
        }

        $number = self::parseNumber($text, $locale);
        if ($number !== null) {
            $mask = $number['grouped'] ? '#,##0' : ($number['decimals'] > 0 ? '0' : '');

            return [
                'type'   => self::NUMBER,
                'value'  => $number['value'],
                'format' => $mask === '' ? '' : $mask . self::decimalMask($number['decimals']),
            ];
        }

        $date = self::parseDate($text, $locale);
        if ($date !== null) {
            return $date;
        }

        return ['type' => self::TEXT, 'value' => $raw];
    }

    /** La cellule est-elle un nombre, un pourcentage ou un montant ? */
    public static function isNumeric(string $raw, string $locale = 'fr'): bool
    {
        return in_array(
            self::classify($raw, $locale)['type'],
            [self::NUMBER, self::PERCENT, self::CURRENCY],
            true
        );
    }

    /**
     * Nombre écrit « à la main » → valeur, nombre de décimales, présence d'un
     * séparateur de milliers. Null si ce n'est pas un nombre SANS ambiguïté.
     *
     * @return array{value: int|float, decimals: int, grouped: bool}|null
     */
    public static function parseNumber(string $text, string $locale = 'fr'): ?array
    {
        $text = self::clean($text);
        if ($text === '' || preg_match('/^([+-]?)(.+)$/su', $text, $m) !== 1) {
            return null;
        }
        $negative = $m[1] === '-';
        $body     = $m[2];

        $digits   = null;   // partie entière, sans séparateur
        $fraction = '';
        $grouped  = false;

        if (preg_match('/^\d+$/', $body) === 1) {
            // Entier nu. Un zéro de tête signale un CODE (postal, téléphone,
            // référence), pas une quantité.
            if (strlen($body) > 1 && $body[0] === '0') {
                return null;
            }
            $digits = $body;
        } elseif (preg_match('/^(\d+)([.,])(\d+)$/', $body, $p) === 1) {
            // Un seul séparateur : décimale, sauf « 1,234 » lu en anglais.
            if (strlen($p[1]) > 1 && $p[1][0] === '0') {
                return null;
            }
            if ($p[2] === ',' && $locale === 'en' && strlen($p[3]) === 3 && strlen($p[1]) <= 3 && $p[1] !== '0') {
                $digits  = $p[1] . $p[3];
                $grouped = true;
            } else {
                $digits   = $p[1];
                $fraction = $p[3];
            }
        } elseif (preg_match('/^(\d{1,3}(?: \d{3})+)(?:[.,](\d+))?$/', $body, $p) === 1) {
            // Milliers à l'espace (français) : « 1 234 567,89 ».
            $digits   = str_replace(' ', '', $p[1]);
            $fraction = $p[2] ?? '';
            $grouped  = true;
        } elseif (preg_match('/^(\d{1,3}(?:,\d{3})+)(?:\.(\d+))?$/', $body, $p) === 1
            && (substr_count($p[1], ',') > 1 || ($p[2] ?? '') !== '')) {
            // Milliers à la virgule (anglais) : « 1,234,567 », « 1,234.50 ».
            $digits   = str_replace(',', '', $p[1]);
            $fraction = $p[2] ?? '';
            $grouped  = true;
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{3})+)(?:,(\d+))?$/', $body, $p) === 1
            && (substr_count($p[1], '.') > 1 || ($p[2] ?? '') !== '')) {
            // Milliers au point (allemand, espagnol) : « 1.234.567 », « 1.234,50 ».
            $digits   = str_replace('.', '', $p[1]);
            $fraction = $p[2] ?? '';
            $grouped  = true;
        } elseif (preg_match('/^(\d{1,3}(?:\'\d{3})+)(?:\.(\d+))?$/', $body, $p) === 1) {
            // Milliers à l'apostrophe (suisse) : « 1'234.50 ».
            $digits   = str_replace("'", '', $p[1]);
            $fraction = $p[2] ?? '';
            $grouped  = true;
        } elseif (preg_match('/^\d+(?:\.\d+)?[eE][+-]?\d+$/', $body) === 1) {
            $value = (float)$body;

            return is_finite($value)
                ? ['value' => $negative ? -$value : $value, 'decimals' => 0, 'grouped' => false]
                : null;
        } else {
            return null;
        }

        if (strlen(ltrim($digits, '0')) + strlen($fraction) > self::MAX_DIGITS) {
            return null;
        }

        if ($fraction === '') {
            $value = (int)$digits;
        } else {
            $value = (float)($digits . '.' . $fraction);
        }
        if ($negative) {
            $value = -$value;
        }

        return ['value' => $value, 'decimals' => min(strlen($fraction), 10), 'grouped' => $grouped];
    }

    /**
     * Date écrite en ISO (« 2026-09-30 », avec ou sans heure) ou en barres
     * obliques (« 30/09/2026 »). Le masque d'affichage reproduit l'écriture de
     * la source : une date lue ne doit pas changer d'allure dans le classeur.
     *
     * @return array{type: string, value: float, format: string}|null
     */
    public static function parseDate(string $text, string $locale = 'fr'): ?array
    {
        $text = self::clean($text);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $text, $m) === 1) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if (!checkdate($mo, $d, $y) || $y < self::MIN_YEAR) {
                return null;
            }
            if (($m[4] ?? '') === '') {
                return ['type' => self::DATE, 'value' => self::excelSerial($y, $mo, $d), 'format' => 'yyyy-mm-dd'];
            }
            [$h, $i, $s] = [(int)$m[4], (int)$m[5], (int)($m[6] ?? 0)];
            if ($h > 23 || $i > 59 || $s > 59) {
                return null;
            }

            return [
                'type'   => self::DATETIME,
                'value'  => self::excelSerial($y, $mo, $d, $h, $i, $s),
                'format' => 'yyyy-mm-dd hh:mm',
            ];
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m) === 1) {
            [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y < self::MIN_YEAR) {
                return null;
            }
            // Ordre de la langue d'abord ; l'autre ordre seulement si le
            // premier est impossible (« 25/12/2026 » lu en anglais).
            $dayFirst = $locale !== 'en';
            [$d, $mo] = $dayFirst ? [$a, $b] : [$b, $a];
            if (!checkdate($mo, $d, $y)) {
                [$d, $mo]  = [$mo, $d];
                $dayFirst  = !$dayFirst;
                if (!checkdate($mo, $d, $y)) {
                    return null;
                }
            }

            return [
                'type'   => self::DATE,
                'value'  => self::excelSerial($y, $mo, $d),
                'format' => $dayFirst ? 'dd/mm/yyyy' : 'mm/dd/yyyy',
            ];
        }

        return null;
    }

    /**
     * Numéro de série Excel (jours depuis le 30/12/1899, fraction = heure).
     * Calcul en UTC : un fuseau à heure d'été décalerait une date d'un jour.
     */
    public static function excelSerial(int $y, int $m, int $d, int $h = 0, int $i = 0, int $s = 0): float
    {
        $days = gmmktime(0, 0, 0, $m, $d, $y) / 86400 + 25569;

        return $days + ($h * 3600 + $i * 60 + $s) / 86400;
    }

    /** Masque des décimales : '' pour 0, '.00' pour 2. */
    private static function decimalMask(int $decimals): string
    {
        return $decimals > 0 ? '.' . str_repeat('0', min($decimals, 10)) : '';
    }

    /**
     * Espaces insécables et fines ramenés à l'espace simple, signe moins
     * typographique au tiret : ce sont eux qui font échouer un `is_numeric`
     * sur un nombre parfaitement lisible.
     */
    private static function clean(string $text): string
    {
        $text = strtr($text, [
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ',
            "\u{2212}" => '-', "\u{2019}" => "'",
        ]);

        return trim($text);
    }
}
