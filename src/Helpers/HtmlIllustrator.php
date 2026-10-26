<?php

declare(strict_types=1);

/**
 * Pipeline d'illustration d'une page HTML générée : remplit les placeholders
 * `<img data-ai-image="...">` par des images réellement générées et réécrit
 * leur `src`.
 *
 * Extrait de ChatController pour être appelable depuis un worker (l'outil
 * « Page HTML illustrée » s'exécute désormais en tâche de fond). Aucun état :
 * la progression sort par le callback, la facturation par le retour.
 */
class HtmlIllustrator
{
    private const PATTERN = '/<img\b[^>]*\bdata-ai-image\s*=\s*("([^"]*)"|\'([^\']*)\')[^>]*>/i';

    /**
     * Plafonné à $maxImages ; les balises au-delà ou en échec reçoivent un SVG
     * de secours inline (jamais de <img> vide cassée dans la page).
     *
     * Ne facture rien : il n'y a pas encore de message porteur. Chaque image
     * produite est rendue dans `images` (paramètres réels du rendu : modèle,
     * taille, qualité) et l'appelant la journalise via `UsageMeter::tool()`
     * une fois la page persistée.
     *
     * @param callable $emitProgress fn(int $done, int $total): void
     * @return array{html:string,images:list<array{model:string,size:string,quality:string,count:int}>}
     */
    public static function run(
        string $html,
        ?array $imageTool,
        int $maxImages,
        string $imageStyle,
        callable $emitProgress
    ): array {
        preg_match_all(self::PATTERN, $html, $all);
        $total = min($maxImages, count($all[0] ?? []));

        $imgConfig    = ($imageTool !== null) ? (json_decode($imageTool['config_json'] ?? '{}', true) ?: []) : [];
        $images       = [];
        $done         = 0;
        $generated    = 0;

        $out = preg_replace_callback(
            self::PATTERN,
            function (array $m) use (&$images, &$done, &$generated, $imageTool, $imgConfig, $maxImages, $total, $imageStyle, $emitProgress) {
                $tag       = $m[0];
                $rawPrompt = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
                $imgPrompt = trim(html_entity_decode($rawPrompt, ENT_QUOTES | ENT_HTML5));
                $alt       = self::extractAlt($tag);

                if ($imageTool === null || $generated >= $maxImages || $imgPrompt === '') {
                    return self::setSrc($tag, self::placeholderDataUri($alt));
                }
                $generated++;

                // Préfixe déterministe du style choisi (en plus de la consigne donnée au LLM).
                $genPrompt = $imageStyle !== '' ? ($imgPrompt . ' — Style: ' . $imageStyle) : $imgPrompt;
                $result = ImageGenerator::generate($imageTool, $genPrompt);
                if (isset($result['error']) || empty($result['image_url'])) {
                    error_log('[HtmlIllustrator] image échouée : ' . ($result['error'] ?? 'inconnue'));
                    $done++;
                    $emitProgress($done, $total);
                    return self::setSrc($tag, self::placeholderDataUri($alt));
                }

                $images[] = [
                    'model'   => (string)($imageTool['model'] ?? ''),
                    'size'    => (string)($result['size']    ?? $imgConfig['size']    ?? ''),
                    'quality' => (string)($result['quality'] ?? $imgConfig['quality'] ?? ''),
                    'count'   => 1,
                    'usage'   => $result['usage'] ?? null,
                ];

                $done++;
                $emitProgress($done, $total);
                return self::setSrc($tag, (string)$result['image_url']);
            },
            $html
        );

        return ['html' => $out ?? $html, 'images' => $images];
    }

    /** Remplace le src d'une balise <img> (et retire data-ai-image), en préservant les autres attributs. */
    public static function setSrc(string $tag, string $src): string
    {
        $t = preg_replace('/\s+data-ai-image\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $tag) ?? $tag;
        $t = preg_replace('/\s+src\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $t) ?? $t;
        return preg_replace('/<img\b/i', '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '"', $t, 1) ?? $t;
    }

    /** Récupère l'attribut alt d'une balise <img> (décodé), ou chaîne vide. */
    public static function extractAlt(string $tag): string
    {
        if (preg_match('/\balt\s*=\s*"([^"]*)"/i', $tag, $m) || preg_match('/\balt\s*=\s*\'([^\']*)\'/i', $tag, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }
        return '';
    }

    /** Image SVG de secours (dégradé + libellé), encodée en data-URI — si une image échoue ou dépasse le plafond. */
    public static function placeholderDataUri(string $alt): string
    {
        $label = htmlspecialchars(mb_substr($alt !== '' ? $alt : 'Image', 0, 48), ENT_QUOTES);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" preserveAspectRatio="xMidYMid slice">'
             . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e2e8f0"/><stop offset="1" stop-color="#cbd5e1"/></linearGradient></defs>'
             . '<rect width="800" height="600" fill="url(#g)"/>'
             . '<text x="400" y="308" font-family="system-ui,-apple-system,sans-serif" font-size="30" fill="#64748b" text-anchor="middle">' . $label . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
