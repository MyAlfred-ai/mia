<?php

declare(strict_types=1);

/**
 * Brique UNIQUE de formatage d'affichage (euros, tokens, crédits, dates, tailles).
 * Aucun number_format inline dans les templates/contrôleurs pour ces grandeurs :
 * tout passe par ici (source : anciennes closures de templates/settings/usage.php).
 *
 * ===================== POURQUOI CE FICHIER EST BILINGUE ======================
 *
 * Ce n'est pas de la cosmétique. `dateFr()` rendait `07/09/2026` : un anglophone
 * y lit le 9 juillet, pas le 7 septembre. Traduire l'interface sans traiter ce
 * fichier produirait un écran anglais qui affiche des dates FAUSSES — une panne
 * silencieuse, et la seule du chantier qui trompe l'utilisateur sur des chiffres.
 *
 * Les huit signatures publiques sont INCHANGÉES : les ~168 sites d'appel du
 * dépôt n'ont rien à savoir de la locale, ils continuent d'appeler `Format::`.
 * Un seul fichier localise donc dates, nombres, devises et unités.
 *
 * `dateFr()` garde son nom malgré sa nouvelle sémantique : le renommer
 * toucherait tous les appelants pour un gain nul, et la constante d'appel
 * compte plus ici que la justesse du nom.
 *
 * Toujours SANS ext-intl (elle n'est pas garantie dans l'image Docker) : les
 * tables sont écrites à la main, comme MONTHS_FR_SHORT l'était déjà.
 */
final class Format
{
    private const MONTHS_FR_SHORT = [
        '01' => 'janv.', '02' => 'févr.', '03' => 'mars', '04' => 'avr.',
        '05' => 'mai', '06' => 'juin', '07' => 'juil.', '08' => 'août',
        '09' => 'sept.', '10' => 'oct.', '11' => 'nov.', '12' => 'déc.',
    ];

    private const MONTHS_EN_SHORT = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec',
    ];

    /** Vrai en anglais. Un seul point de décision, pour ne pas le disperser. */
    private static function en(): bool
    {
        return I18n::locale() === 'en';
    }

    /**
     * Séparateurs de nombre de la locale courante.
     *
     * Le séparateur de milliers français reste l'espace ORDINAIRE (U+0020) déjà
     * utilisée par ce fichier : la sortie française ne change pas d'un caractère.
     * Passer à une espace insécable serait typographiquement meilleur, mais
     * modifierait toutes les valeurs affichées — un autre sujet, un autre commit.
     *
     * @return array{0: string, 1: string} [décimale, milliers]
     */
    private static function separators(): array
    {
        return self::en() ? ['.', ','] : [',', ' '];
    }

    private static function nf(float $value, int $decimals): string
    {
        [$dec, $mil] = self::separators();

        return number_format($value, $decimals, $dec, $mil);
    }

    /**
     * '1 234,56 €' / '€1,234.56' — précision adaptée aux petits montants
     * (<0,01 € → 4 décimales, <1 € → 3).
     *
     * En anglais le symbole PRÉCÈDE le montant : « €1,234.56 ». L'euro reste la
     * devise dans les deux langues — c'est celle de la facturation, pas une
     * préférence d'affichage.
     */
    public static function eur(float $value): string
    {
        if ($value <= 0) {
            return self::en() ? '€0.00' : '0,00 €';
        }

        $abs = abs($value);
        $dec = $abs < 0.01 ? 4 : ($abs < 1 ? 3 : 2);
        $nb  = self::nf($value, $dec);

        return self::en() ? '€' . $nb : $nb . ' €';
    }

    /** '842' / '12,5 k' / '1,25 M' (FR) — '842' / '12.5k' / '1.25M' (EN). */
    public static function tokens(int $value): string
    {
        if ($value < 1000) {
            return (string) $value;
        }

        // L'anglais colle le suffixe au nombre (« 12.5k »), le français l'en
        // sépare d'une espace (« 12,5 k »).
        $sep = self::en() ? '' : ' ';

        if ($value < 1_000_000) {
            return self::nf($value / 1000, $value < 10000 ? 1 : 0) . $sep . 'k';
        }

        return self::nf($value / 1_000_000, 2) . $sep . 'M';
    }

    /** '12 840' / '12,840' — entier avec séparateur de milliers de la locale. */
    public static function credits(int $value): string
    {
        return self::nf((float) $value, 0);
    }

    /** 'YYYY-MM' → 'juil. 2026' / 'Jul 2026' ('' si invalide). */
    public static function monthLabel(string $yearMonth): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m) !== 1) {
            return '';
        }

        $table = self::en() ? self::MONTHS_EN_SHORT : self::MONTHS_FR_SHORT;
        $label = $table[$m[2]] ?? '';

        return $label !== '' ? $label . ' ' . $m[1] : '';
    }

    /**
     * 'YYYY-MM-DD[ HH:MM:SS]' → 'DD/MM/YYYY' (FR) ou 'MM/DD/YYYY' (EN).
     * '' si vide ou invalide.
     *
     * L'inversion jour/mois est LE point de ce fichier : les deux formats sont
     * indiscernables à l'œil pour un jour ≤ 12, et se lisent différemment.
     */
    public static function dateFr(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) !== 1) {
            return '';
        }

        return self::en()
            ? $m[2] . '/' . $m[3] . '/' . $m[1]
            : $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /**
     * Octets → 'X,Y Mo' / 'X.Y MB'. '< 0,1 Mo' pour un contenu non nul mais
     * minuscule — l'arrondi rendrait « 0,0 Mo », qu'on lit comme « vide ».
     */
    public static function sizeMo(int $bytes): string
    {
        $unite = self::en() ? 'MB' : 'Mo';
        $mo    = $bytes / (1024 * 1024);

        if ($bytes > 0 && $mo < 0.1) {
            return '< ' . self::nf(0.1, 1) . ' ' . $unite;
        }

        return self::nf($mo, 1) . ' ' . $unite;
    }

    /** Octets → 'X,XX Go' / 'X.XX GB' à partir de 1 Go, sinon délègue à sizeMo(). */
    public static function sizeGoMo(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return self::nf($bytes / (1024 ** 3), 2) . ' ' . (self::en() ? 'GB' : 'Go');
        }

        return self::sizeMo($bytes);
    }

    /**
     * Poids d'un fichier joint : '4 Ko' / '4 KB' sous 1 Mo (arrondi au Ko,
     * 1 Ko au moins — un petit fichier n'affiche jamais « 0 Ko »), sinon
     * sizeMo(). '' pour un poids inconnu (0).
     */
    public static function sizeShort(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024 * 1024) {
            return self::nf((float)max(1, (int)round($bytes / 1024)), 0) . ' ' . (self::en() ? 'KB' : 'Ko');
        }

        return self::sizeMo($bytes);
    }

    // ---------------------------------------------------------------------
    // Heures et jours — ajoutés avec les tâches programmées (src/Schedule/).
    // Mêmes règles que le reste du fichier : tables écrites à la main, aucun
    // ext-intl, la sortie suit la locale courante.
    // ---------------------------------------------------------------------

    private const DAYS_FR_SHORT = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    private const DAYS_EN_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * 'HH:MM' → '8 h 00' (FR) ou '08:00' (EN). '' si l'entrée n'est pas une heure.
     * Le français écrit l'heure sans zéro de tête, l'anglais garde les 24 h :
     * « 8:00 » se lirait AM/PM, et MIA n'a jamais affiché l'heure ainsi.
     * Espaces INSÉCABLES (U+00A0) : « 8 » en fin de ligne et « h 00 » au début
     * de la suivante, c'est illisible.
     */
    public static function time(string $hhmm): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m) !== 1) {
            return '';
        }

        return self::en()
            ? sprintf('%02d:%s', (int)$m[1], $m[2])
            : (int)$m[1] . "\u{00A0}h\u{00A0}" . $m[2];
    }

    /** Jour abrégé, 1 = lundi (ISO) : 'lun.' (FR) ou 'Mon' (EN). '' hors de 1-7. */
    public static function dayShort(int $isoDay): string
    {
        return (self::en() ? self::DAYS_EN_SHORT : self::DAYS_FR_SHORT)[$isoDay] ?? '';
    }

    /** Date courte avec le jour : 'lun. 5 oct.' (FR) ou 'Mon, Oct 5' (EN). */
    public static function shortDate(\DateTimeInterface $date): string
    {
        $jour = self::dayShort((int)$date->format('N'));
        $mois = $date->format('m');

        return self::en()
            ? $jour . ', ' . self::MONTHS_EN_SHORT[$mois] . ' ' . $date->format('j')
            : $jour . ' ' . $date->format('j') . ' ' . self::MONTHS_FR_SHORT[$mois];
    }

    /**
     * 'lun. 5 oct. à 8 h 00' (FR) ou 'Mon, Oct 5 at 08:00' (EN), dans le fuseau
     * que porte déjà la date : l'appelant la convertit, ce fichier n'en choisit
     * jamais un.
     */
    public static function shortDateTime(\DateTimeInterface $date): string
    {
        return self::shortDate($date) . (self::en() ? ' at ' : ' à ') . self::time($date->format('H:i'));
    }
}
