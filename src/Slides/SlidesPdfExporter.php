<?php

declare(strict_types=1);

/**
 * Export d'un deck au format PDF paysage 16:9.
 *
 * Volontairement à l'écart de PdfExportService : son `sanitizeCss()` retire
 * `position`, `flex`, `grid` et les unités de fenêtre. Ici chaque élément est
 * placé en absolu à partir des coordonnées en millimètres de la spec.
 */
final class SlidesPdfExporter
{
    /** Page 16:9 en millimètres (largeur A4 paysage, hauteur déduite du ratio). */
    private const PAGE_W = 297.0;
    private const PAGE_H = 167.06;

    /** 1 cqw = 1 % de 297 mm ; 1 mm = 2,83465 pt. */
    private const PT_PER_CQW = 8.4189;

    /** Binaire PDF d'un deck normalisé. */
    public static function export(array $deck): string
    {
        $deck   = DeckSpec::normalize($deck);
        $theme  = $deck['theme'];
        $slides = $deck['slides'];

        $tempDir = ROOT . '/storage/cache/dompdf';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('Impossible de créer le cache temporaire de Dompdf.');
        }
        if (!is_writable($tempDir)) {
            throw new RuntimeException('Le cache temporaire de Dompdf n\'est pas accessible en écriture.');
        }
        $cropDir    = ROOT . '/storage/cache/deck';
        $tempImages = [];

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [ROOT]);
        $options->set('tempDir', $tempDir);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper([0, 0, self::PAGE_W * 72 / 25.4, self::PAGE_H * 72 / 25.4]);

        try {
            if ($slides === []) {
                $slides = [['layout' => 'titre', 'elements' => []]];
            }

            $html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>'
                . self::printCss()
                . '</style></head><body>';
            foreach (array_values($slides) as $index => $slide) {
                $html .= self::writeSlide(is_array($slide) ? $slide : [], $theme, $cropDir, $tempImages);
            }
            $dompdf->loadHtml($html . '</body></html>', 'UTF-8');
            $dompdf->render();
            $dompdf->getCanvas()->get_cpdf()->addInfo('Title', (string)$deck['title']);
            $dompdf->getCanvas()->get_cpdf()->addInfo('Creator', 'MIA');

            return $dompdf->output();
        } finally {
            foreach ($tempImages as $tmp) {
                @unlink($tmp);
            }
        }
    }

    /** @param array<int,string> $tempImages recadrages temporaires à supprimer après écriture */
    private static function writeSlide(
        array $slide,
        array $theme,
        string $cropDir,
        array &$tempImages
    ): string {
        $layout = (string)($slide['layout'] ?? 'titre-contenu');
        $html = '<section class="slide" style="background-color:' . self::color((string)($theme['bg'] ?? '#ffffff')) . '">';

        foreach ($slide['elements'] as $el) {
            if (!is_array($el)) {
                continue;
            }
            [$x, $y, $w, $h] = self::box($el);

            switch ((string)($el['type'] ?? '')) {
                case 'shape':
                    $html .= self::positioned(self::shapeHtml($el, $theme, $w, $h), $x, $y, $w, $h);
                    break;
                case 'text':
                    $html .= self::positioned(self::textHtml($el, $layout, $theme), $x, $y, $w, $h);
                    break;
                case 'bullets':
                    $html .= self::positioned(self::bulletsHtml($el, $layout, $theme), $x, $y, $w, $h);
                    break;
                case 'image':
                    if ((string)($el['fit'] ?? 'cover') === 'contain') {
                        // Image ENTIÈRE (logo), dans sa boîte : ni recadrage
                        // ni réécriture, la transparence est conservée.
                        $path = SlideImage::resolvePath((string)($el['url'] ?? ''));
                        if ($path !== null) {
                            [$fx, $fy, $fw, $fh] = SlideImage::containBox($path, $x, $y, $w, $h, (string)($el['anchor'] ?? 'center'));
                            $html .= self::positioned(
                                '<img src="' . self::esc($path) . '" style="width:' . self::num($fw) . 'mm;height:' . self::num($fh) . 'mm">',
                                $fx,
                                $fy,
                                $fw,
                                $fh
                            );
                        }
                        break;
                    }
                    $imageHtml = self::imageHtml($el, $w, $h, $cropDir, $tempImages);
                    if ($imageHtml !== '') {
                        $html .= self::positioned($imageHtml, $x, $y, $w, $h);
                    }
                    break;
                case 'table':
                    $html .= self::positioned(self::tableHtml($el, $layout, $theme, $w), $x, $y, $w, $h);
                    break;
            }
        }

        return $html . '</section>';
    }

    private static function shapeHtml(array $el, array $theme, float $w, float $h): string
    {
        $kind   = (string)($el['shape'] ?? 'rect');
        $radius = match ($kind) {
            'circle' => min($w, $h) / 2,
            'round'  => min($w, $h) * DeckRenderer::ROUND_RATIO,
            default  => 0.0,
        };

        return self::rectHtml(
            DeckRenderer::fillColor((string)($el['fill'] ?? 'accent'), $theme),
            max(0.0, min(1.0, (float)($el['opacity'] ?? 1))),
            $w,
            $h,
            $radius
        );
    }

    /**
     * Aplat de couleur aux dimensions EXPLICITES.
     *
     * Jamais de `height:100%` ici : un pourcentage se résout sur toute la
     * slide, et un filet d'accent de
     * 1,6 % de haut repeignait toute la slide.
     */
    private static function rectHtml(string $color, float $opacity, float $w, float $h, float $radius = 0.0): string
    {
        return '<div style="width:' . self::num($w) . 'mm;height:' . self::num($h) . 'mm'
            . ';background-color:' . self::color($color, $opacity)
            . ($radius > 0 ? ';border-radius:' . self::num($radius) . 'mm' : '') . '"></div>';
    }

    private static function textHtml(array $el, string $layout, array $theme): string
    {
        $size   = DeckRenderer::fontSizeCqw(
            (string)($el['size'] ?? 'm'),
            (string)($el['text'] ?? ''),
            (float)($el['w'] ?? 0),
            (float)($el['h'] ?? 0)
        ) * self::PT_PER_CQW;
        $weight = ((int)($el['weight'] ?? 400)) >= 600 ? 'bold' : 'normal';
        $align  = in_array((string)($el['align'] ?? 'left'), ['center', 'right'], true)
            ? (string)$el['align']
            : 'left';
        $style  = 'margin:0;font-size:' . self::num($size) . 'pt'
            . ';line-height:1.22'
            . ';font-weight:' . $weight
            . ';text-align:' . $align
            . ';color:' . self::color(DeckRenderer::textColor($el, $layout, $theme))
            . self::typography($el);

        // Retours à la ligne conservés : le rendu HTML est en `pre-wrap`.
        $text = nl2br(self::esc((string)($el['text'] ?? '')), false);

        return '<div style="' . $style . '">' . $text . '</div>';
    }

    private static function bulletsHtml(array $el, string $layout, array $theme): string
    {
        $items = array_values(array_filter(
            (array)($el['items'] ?? []),
            static fn ($item): bool => trim((string)$item) !== ''
        ));
        if ($items === []) {
            return '';
        }

        $size   = DeckRenderer::fontSizeCqw((string)($el['size'] ?? 'm')) * self::PT_PER_CQW;
        $color  = self::color(DeckRenderer::textColor($el, $layout, $theme));
        $named  = DeckRenderer::tokenColor((string)($el['marker'] ?? ''), $theme);
        $raw    = (string)($el['marker'] ?? '');
        $accent = self::color($named ?? (preg_match('/^#[0-9a-f]{6}$/', $raw) === 1 ? $raw : (string)($theme['accent'] ?? '#2563eb')));
        // Retrait négatif : la puce déborde à gauche, le texte reste aligné —
        // équivalent du `padding-left` + puce absolue de la feuille de style.
        $indent = self::num($size * 0.62) . 'pt';

        $html = '<div style="margin:0;font-size:' . self::num($size) . 'pt;line-height:1.4;color:' . $color
            . ';font-weight:' . (((int)($el['weight'] ?? 400)) >= 600 ? 'bold' : 'normal')
            . self::typography($el) . '">';
        foreach ($items as $item) {
            $html .= '<div style="margin:0 0 ' . self::num($size * 0.55) . 'pt 0'
                . ';padding-left:' . $indent . ';text-indent:-' . $indent . '">'
                . '<span style="color:' . $accent . '">&#8226;</span>&nbsp;'
                . self::esc((string)$item)
                . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Tableau : mêmes règles que l'export PowerPoint et l'aperçu — en-tête
     * sur l'accent, lignes alternées, filet fin entre les lignes.
     */
    private static function tableHtml(array $el, string $layout, array $theme, float $w): string
    {
        $rows = array_values(array_filter((array)($el['rows'] ?? []), 'is_array'));
        if ($rows === []) {
            return '';
        }
        $size   = DeckRenderer::fontSizeCqw((string)($el['size'] ?? 's')) * self::PT_PER_CQW;
        $accent = (string)($el['head'] ?? '') !== '' ? DeckRenderer::fillColor((string)$el['head'], $theme) : (string)($theme['accent'] ?? '#2563eb');
        $text   = self::color(DeckRenderer::textColor(['role' => 'corps'], $layout, $theme));
        $on     = self::color(DeckRenderer::onColor($accent));
        $rule   = self::color((string)($theme['muted'] ?? '#6b7280'), 0.35);
        $band   = (string)($el['band'] ?? '') !== ''
            ? self::color(DeckRenderer::fillColor((string)$el['band'], $theme))
            : self::color((string)($theme['accent'] ?? '#2563eb'), 0.10);

        $html = '<table style="width:' . self::num($w) . 'mm;border-collapse:collapse;font-size:' . self::num($size) . 'pt;color:' . $text . '">';
        foreach ($rows as $r => $cells) {
            $head  = $r === 0 && !empty($el['header']);
            $bg    = $head ? self::color($accent) : (($r - (empty($el['header']) ? 0 : 1)) % 2 === 1 ? $band : 'transparent');
            $style = 'padding:1.4mm 2.2mm;text-align:left;background-color:' . $bg
                . ($head ? ';color:' . $on . ';font-weight:bold' : ';border-bottom:0.2mm solid ' . $rule);
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<td style="' . $style . '">' . self::esc((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * Surcharges de l'éditeur : italique et police. La couleur passe par
     * `DeckRenderer::textColor`, qui donne déjà la priorité au choix explicite.
     * Le PDF utilise une correspondance figée du catalogue plutôt qu'une
     * famille CSS libre.
     */
    private static function typography(array $el): string
    {
        $css  = !empty($el['italic']) ? ';font-style:italic' : '';
        $font = (string)(DeckSpec::FONTS[(string)($el['font'] ?? '')]['pdf'] ?? '');

        return $font !== '' ? $css . ';font-family:' . $font : $css;
    }

    /** @param array<int,string> $tempImages */
    private static function imageHtml(array $el, float $w, float $h, string $cropDir, array &$tempImages): string
    {
        $path = SlideImage::resolvePath((string)($el['url'] ?? ''));
        if ($path === null || $h <= 0) {
            return '';
        }

        // Recadrage préalable au ratio de la boîte : le moteur PDF ne gère pas
        // `object-fit`, une image carrée y serait étirée.
        $cropped = SlideImage::coverCrop($path, $w / $h, $cropDir);
        if ($cropped !== $path) {
            $tempImages[] = $cropped;
        }

        return '<img src="' . self::esc($cropped) . '" style="width:' . self::num($w) . 'mm;height:' . self::num($h) . 'mm">';
    }

    /** Élément à position fixe, en millimètres, débordement masqué. */
    private static function positioned(string $html, float $x, float $y, float $w, float $h): string
    {
        if ($html === '') {
            return '';
        }

        return '<div style="position:absolute;left:' . self::num($x) . 'mm;top:' . self::num($y)
            . 'mm;width:' . self::num($w) . 'mm;height:' . self::num($h) . 'mm;overflow:hidden">'
            . $html . '</div>';
    }

    private static function printCss(): string
    {
        $width = self::num(self::PAGE_W);
        $height = self::num(self::PAGE_H);

        return '@page{margin:0}html,body{margin:0;padding:0;width:' . $width . 'mm;height:' . $height
            . 'mm;font-family:"DejaVu Sans",sans-serif}.slide{position:relative;width:' . $width . 'mm;height:'
            . $height . 'mm;overflow:hidden;page-break-after:always}.slide:last-child{page-break-after:avoid}'
            . 'img{max-width:100%}table{border-collapse:collapse}';
    }

    /** Boîte d'un élément : pourcentages de la spec → millimètres de la page. */
    private static function box(array $el): array
    {
        return [
            ((float)($el['x'] ?? 0)) * self::PAGE_W / 100,
            ((float)($el['y'] ?? 0)) * self::PAGE_H / 100,
            max(0.1, ((float)($el['w'] ?? 10)) * self::PAGE_W / 100),
            max(0.1, ((float)($el['h'] ?? 10)) * self::PAGE_H / 100),
        ];
    }

    /** Couleur CSS, en rgba() dès qu'elle est translucide. */
    private static function color(string $hex, float $opacity = 1.0): string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m) !== 1) {
            return $opacity >= 1.0 ? '#000000' : 'rgba(0,0,0,' . self::num($opacity) . ')';
        }
        if ($opacity >= 1.0) {
            return '#' . strtolower($m[1]);
        }
        [$r, $g, $b] = array_map(static fn (string $c): int => (int)hexdec($c), str_split($m[1], 2));

        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . self::num($opacity) . ')';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
