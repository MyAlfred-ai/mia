<?php

declare(strict_types=1);

/**
 * Lecture PAGINÉE d'un texte long rendu au modèle — SOURCE UNIQUE.
 *
 * Avant : chaque lecture (document Drive, email, page web, pièce jointe…)
 * tronquait au budget et s'arrêtait là, sur « [… contenu tronqué] ». La suite
 * d'un long document était INACCESSIBLE : le modèle ne pouvait que le dire, et
 * l'utilisateur devait recoller lui-même la fin du contrat dans le chat.
 *
 * Ici le texte est découpé en parties d'au plus `$maxChars` octets, chacune
 * coupée à une frontière propre (paragraphe, puis ligne, phrase, espace), et la
 * lecture rend la partie demandée avec de quoi demander la suivante.
 *
 * Deux choix assumés :
 *   - le découpage travaille en OCTETS, pas en caractères : une partie de N
 *     octets compte au plus N caractères, donc le budget n'est jamais dépassé,
 *     et le calcul reste linéaire sur un document de plusieurs mégaoctets (un
 *     découpage par `mb_substr` repartirait du début à chaque partie) ;
 *   - il est DÉTERMINISTE : même texte, même taille → mêmes parties. « Partie
 *     2 » désigne donc la même chose d'un appel à l'autre, y compris au tour
 *     suivant.
 *
 * Pure, testable sans réseau.
 */
final class TextPager
{
    /** Taille plancher d'une partie (octets) : en deçà, le découpage morcelle. */
    public const MIN_CHARS = 1000;

    /** Une coupe propre n'est cherchée que dans la seconde moitié de la fenêtre. */
    private const MIN_FILL = 0.5;

    /**
     * Partie `$part` (1 = début) d'un texte découpé en morceaux d'au plus
     * `$maxChars` octets.
     *
     * @return array{contenu:string, partie:int, parties:int, tronque:bool, suite?:string}
     * @throws \RuntimeException si la partie demandée n'existe pas
     */
    public static function page(string $text, int $maxChars, int $part = 1): array
    {
        $bounds = self::bounds($text, max(self::MIN_CHARS, $maxChars));
        $total  = count($bounds);
        $part   = max(1, $part);
        if ($part > $total) {
            throw new \RuntimeException(
                'Ce contenu ne compte que ' . $total . ' partie' . ($total > 1 ? 's' : '')
                . ' : demande une partie entre 1 et ' . $total . '.'
            );
        }
        [$start, $end] = $bounds[$part - 1];
        $out = [
            'contenu' => trim(substr($text, $start, $end - $start)),
            'partie'  => $part,
            'parties' => $total,
            // `tronque` garde le sens qu'il avait partout : « il y a une suite
            // que tu n'as pas lue ». Seule la dernière partie le met à faux.
            'tronque' => $part < $total,
        ];
        if ($part < $total) {
            $out['suite'] = 'Partie ' . $part . ' sur ' . $total
                . ' : relance la même lecture avec `partie: ' . ($part + 1) . '` pour lire la suite, '
                . 'seulement si la question le demande.';
        }
        return $out;
    }

    /**
     * Bornes [début, fin) de chaque partie, en octets. Un texte vide compte une
     * partie vide : « lire la partie 1 » d'un document sans texte n'est pas une
     * erreur de numéro.
     *
     * @return list<array{0:int, 1:int}>
     */
    public static function bounds(string $text, int $maxChars): array
    {
        $maxChars = max(self::MIN_CHARS, $maxChars);
        $len      = strlen($text);
        if ($len <= $maxChars) {
            return [[0, $len]];
        }
        $bounds = [];
        $pos    = 0;
        while ($pos < $len) {
            if ($len - $pos <= $maxChars) {
                $bounds[] = [$pos, $len];
                break;
            }
            $cut      = self::cutPoint($text, $pos, $maxChars);
            $bounds[] = [$pos, $cut];
            $pos      = $cut;
        }
        return $bounds;
    }

    /**
     * Fin de la partie qui commence à `$pos` : la frontière la plus « propre »
     * de la seconde moitié de la fenêtre — paragraphe, ligne, fin de phrase,
     * espace — et à défaut une coupe dure ramenée au début d'un caractère
     * UTF-8 (jamais au milieu d'un « é »).
     */
    private static function cutPoint(string $text, int $pos, int $maxChars): int
    {
        $window = substr($text, $pos, $maxChars);
        $floor  = (int)floor($maxChars * self::MIN_FILL);

        foreach (["\n\n", "\n", '. ', '! ', '? ', '; ', ' '] as $sep) {
            $at = strrpos($window, $sep);
            if ($at !== false && $at + strlen($sep) > $floor) {
                return $pos + $at + strlen($sep);
            }
        }

        $cut = $pos + $maxChars;
        // Octet de continuation UTF-8 (10xxxxxx) : reculer jusqu'au début du
        // caractère. Au plus trois pas.
        for ($i = 0; $i < 3 && $cut > $pos + 1 && (ord($text[$cut]) & 0xC0) === 0x80; $i++) {
            $cut--;
        }
        return $cut;
    }
}
