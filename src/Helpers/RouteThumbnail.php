<?php

declare(strict_types=1);

/**
 * Vignette d'itinéraire : tracé SVG des étapes géocodées.
 *
 * Pourquoi pas une vraie carte : afficher des tuiles OSM dans une galerie
 * enverrait une requête par carte à un serveur tiers, à chaque affichage de la
 * page — charge externe et fuite du contenu des itinéraires de l'utilisateur.
 * Le tracé seul suffit à RECONNAÎTRE un trajet (sa forme, son étendue), ce qui
 * est tout ce qu'on demande à une vignette. Zéro réseau, zéro dépendance.
 *
 * Les coordonnées viennent de la spec persistée : le géocodage a lieu à la
 * génération (ChatController::generateMap), pas ici.
 */
class RouteThumbnail
{
    /** Boîte de dessin (ratio 16/10, comme .creation-thumb). */
    private const W = 160.0;
    private const H = 100.0;
    private const PAD = 14.0;
    /** Marge basse renforcée : le bandeau « Départ → Arrivée » occupe le bas. */
    private const PAD_BOTTOM = 24.0;

    /** Au-delà, le tracé devient illisible et le SVG inutilement lourd. */
    private const MAX_POINTS = 25;

    /**
     * @param array $waypoints étapes de la spec (['lat','lon'] éventuellement nuls)
     * @return string|null SVG (sans dimensions : il s'étire dans son conteneur),
     *                     null si moins de 2 étapes géocodées — l'appelant
     *                     retombe alors sur la tuile d'icône.
     */
    public static function svg(array $waypoints): ?string
    {
        $points = self::project($waypoints);
        if ($points === null) {
            return null;
        }

        $line = '';
        foreach ($points as $p) {
            $line .= ($line === '' ? '' : ' ') . self::num($p[0]) . ',' . self::num($p[1]);
        }

        $dots  = '';
        $last  = count($points) - 1;
        foreach ($points as $i => $p) {
            // Départ et arrivée en pastilles pleines, étapes intermédiaires en points creux.
            $isEnd = ($i === 0 || $i === $last);
            $dots .= '<circle cx="' . self::num($p[0]) . '" cy="' . self::num($p[1]) . '"'
                . ' r="' . ($isEnd ? '4.2' : '2.6') . '"'
                . ' fill="' . ($isEnd ? 'currentColor' : 'var(--bg-surface, #fff)') . '"'
                . ' stroke="currentColor" stroke-width="' . ($isEnd ? '0' : '1.6') . '"/>';
        }

        return '<svg class="creation-route-svg" viewBox="0 0 ' . self::num(self::W) . ' ' . self::num(self::H) . '"'
            . ' preserveAspectRatio="xMidYMid meet" fill="none" aria-hidden="true">'
            . '<polyline points="' . $line . '" stroke="currentColor" stroke-width="2.2"'
            . ' stroke-linejoin="round" stroke-linecap="round" opacity=".55"/>'
            . $dots
            . '</svg>';
    }

    /**
     * Étapes → coordonnées de dessin, en projection Web Mercator (celle des
     * cartes en ligne : une même forme de trajet ici et dans le volet Leaflet).
     * Le ratio est PRÉSERVÉ — sans ça un aller-retour nord-sud s'étalerait sur
     * toute la largeur et ne ressemblerait plus à rien.
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    private static function project(array $waypoints): ?array
    {
        $raw = [];
        foreach ($waypoints as $wp) {
            if (!is_array($wp) || !isset($wp['lat'], $wp['lon']) || !is_numeric($wp['lat']) || !is_numeric($wp['lon'])) {
                continue;
            }
            $lat = (float)$wp['lat'];
            $lon = (float)$wp['lon'];
            if ($lat < -85 || $lat > 85 || $lon < -180 || $lon > 180) {
                continue; // hors domaine Mercator (pôles) ou aberrant
            }
            // Mercator. Le facteur 180/π est INDISPENSABLE : sans lui, X reste
            // en degrés de longitude pendant que Y sort en radians, et le tracé
            // est écrasé d'un facteur ~57 — une tournée en boucle ressemblait à
            // une ligne droite.
            $raw[] = [$lon, (180 / M_PI) * log(tan(M_PI / 4 + ($lat * M_PI / 180) / 2))];
            if (count($raw) >= self::MAX_POINTS) {
                break;
            }
        }
        if (count($raw) < 2) {
            return null;
        }

        $xs = array_column($raw, 0);
        $ys = array_column($raw, 1);
        $minX = min($xs); $maxX = max($xs);
        $minY = min($ys); $maxY = max($ys);

        $spanX = $maxX - $minX;
        $spanY = $maxY - $minY;
        // Trajet quasi rectiligne (ou étapes confondues) : une étendue nulle
        // ferait une division par zéro et un tracé écrasé sur un bord.
        $span   = max($spanX, $spanY, 1e-9);
        $boxW   = self::W - 2 * self::PAD;
        $boxH   = self::H - self::PAD - self::PAD_BOTTOM;
        $scale  = min($boxW / max($spanX, $span * 0.12),
                      $boxH / max($spanY, $span * 0.12));

        $drawnW = $spanX * $scale;
        $drawnH = $spanY * $scale;
        $offX   = (self::W - $drawnW) / 2;
        $offY   = self::PAD + ($boxH - $drawnH) / 2;

        $points = [];
        foreach ($raw as [$x, $y]) {
            $points[] = [
                $offX + ($x - $minX) * $scale,
                // L'axe Y du SVG descend, celui de Mercator monte : on inverse.
                $offY + ($maxY - $y) * $scale,
            ];
        }
        return $points;
    }

    /** Nombre court et stable (jamais de notation scientifique dans un SVG). */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
