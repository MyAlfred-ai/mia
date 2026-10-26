<?php

declare(strict_types=1);

class PdfExportService
{
    /**
     * @param array{footer?: string, logo?: ?string} $chrome Habillage de
     *        l'entreprise (CompanyProfile::pdfChrome) : logo en en-tête, mention
     *        et numéro de page en pied. Vide = aucun (rapports d'administration).
     */
    public function buildFromHtml(string $html, string $title = 'Export message', array $chrome = []): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException('Dompdf n\'est pas disponible (autoload manquant).');
        }

        $document = $this->ensureResponsiveHtmlDocument($html);
        [$css, $body] = $this->extractCssAndBody($document);

        $tempDir = ROOT . '/storage/cache/dompdf';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('Impossible de créer le cache temporaire de Dompdf.');
        }
        if (!is_writable($tempDir)) {
            throw new RuntimeException('Le cache temporaire de Dompdf n\'est pas accessible en écriture.');
        }

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [ROOT]);
        $options->set('tempDir', $tempDir);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(
            '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>'
            . $this->basePrintCss()
            . $this->chromeCss()
            . $css
            . '</style></head><body>'
            . $this->chromeHtml($chrome)
            . $body
            . '</body></html>',
            'UTF-8'
        );
        $dompdf->render();
        $dompdf->getCanvas()->get_cpdf()->addInfo('Title', $title);
        if (trim((string)($chrome['footer'] ?? '')) !== '') {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $size = 7.5;
            $rightMargin = 12 * 72 / 25.4;
            $width = $dompdf->getFontMetrics()->getTextWidth('999 / 999', $font, $size);
            $canvas->page_text(
                $canvas->get_width() - $rightMargin - $width,
                $canvas->get_height() - 25,
                '{PAGE_NUM} / {PAGE_COUNT}',
                $font,
                $size,
                [0.5, 0.5, 0.5]
            );
        }

        return $dompdf->output();
    }

    /**
     * En-tête et pied de page de l'entreprise. Le logo est un fichier LOCAL
     * (aucune requête réseau) ; la mention est échappée — c'est un texte
     * saisi par l'administrateur.
     */
    private function chromeHtml(array $chrome): string
    {
        $logo   = $chrome['logo'] ?? null;
        $footer = trim((string)($chrome['footer'] ?? ''));
        $html = '';

        if (is_string($logo) && $logo !== '' && is_file($logo) && filesize($logo) <= 2_000_000) {
            $mime = (string)(@mime_content_type($logo) ?: 'image/png');
            if (in_array($mime, ['image/png', 'image/jpeg'], true)) {
                $html .= '<div class="pdf-header"><img src="data:' . $mime . ';base64,'
                    . base64_encode((string)file_get_contents($logo)) . '" alt=""></div>';
            }
        }
        if ($footer !== '') {
            $html .= '<div class="pdf-footer">'
                . htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</div>';
        }

        return $html;
    }

    private function chromeCss(): string
    {
        return <<<'CSS'
.pdf-header { position: fixed; top: -8mm; left: 0; right: 0; height: 9mm; text-align: right; }
.pdf-header img { height: 9mm; width: auto; }
.pdf-footer { position: fixed; bottom: -8mm; left: 0; right: 0; height: 7mm; border-top: 0.3pt solid #cfcfcf; padding-top: 2mm; color: #7f7f7f; font-family: "DejaVu Sans", sans-serif; font-size: 7.5pt; }
CSS;
    }

    private function basePrintCss(): string
    {
        return <<<'CSS'
html, body {
  margin: 0;
  padding: 0;
  color: #111;
  background: #fff;
  font-family: "DejaVu Sans", sans-serif;
  font-size: 11pt;
  line-height: 1.45;
}
* { box-sizing: border-box; }
img, svg, canvas, video, iframe { max-width: 100%; height: auto; }
pre, code { white-space: pre-wrap; word-break: break-word; }
table { width: 100%; border-collapse: collapse; }
hr { border: 0; border-top: 1px solid #cfcfcf; margin: 8px 0; }
a { color: #0f4c81; text-decoration: underline; }
CSS;
    }

    private function extractCssAndBody(string $html): array
    {
        if (!class_exists('DOMDocument')) {
            return ['', $html];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['', $html];
        }

        foreach (['script', 'noscript'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $cssParts = [];
        $styles = $doc->getElementsByTagName('style');
        for ($i = $styles->length - 1; $i >= 0; $i--) {
            $style = $styles->item($i);
            if ($style) {
                $cssParts[] = $this->sanitizeCss((string)$style->textContent);
                if ($style->parentNode) {
                    $style->parentNode->removeChild($style);
                }
            }
        }

        $allElements = $doc->getElementsByTagName('*');
        for ($i = 0; $i < $allElements->length; $i++) {
            $element = $allElements->item($i);
            if (!$element instanceof DOMElement) {
                continue;
            }
            if ($element->hasAttribute('style')) {
                $element->setAttribute(
                    'style',
                    $this->sanitizeCss((string)$element->getAttribute('style'))
                );
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $bodyHtml = $this->innerHtml($body);
            return [implode("\n", array_reverse($cssParts)), $bodyHtml];
        }

        return [implode("\n", $cssParts), $html];
    }

    private function sanitizeCss(string $css): string
    {
        $css = str_replace(["\r\n", "\r"], "\n", $css);

        // Les marges du document restent fixées par le service d'export.
        $css = preg_replace('/@page\s*\{[^}]*\}/i', '', $css) ?? $css;

        // Remplacements des layouts modernes non supportés pour éviter les rendus explosés.
        $css = preg_replace('/display\s*:\s*(inline-)?(flex|grid)\s*;?/i', 'display:block;', $css) ?? $css;
        $css = preg_replace('/(?:justify-content|align-items|align-content|place-items|place-content|gap|row-gap|column-gap)\s*:\s*[^;]+;?/i', '', $css) ?? $css;
        $css = preg_replace('/(?:flex|grid)-(?:basis|grow|shrink|flow|template|auto|area|columns|rows|column|row|start|end)\s*:\s*[^;]+;?/i', '', $css) ?? $css;
        $css = preg_replace('/position\s*:\s*(fixed|sticky|absolute)\s*;?/i', 'position:static;', $css) ?? $css;
        // Propriétés de POSITION seulement (`top: 0`), jamais la fin d'une
        // propriété composée : sans la garde sur le tiret, `border-left: …`,
        // `padding-left: …` ou `margin-top: …` étaient amputés de leur valeur
        // (il restait « border- »), et la règle entière était ignorée.
        $css = preg_replace('/(?<![\w-])(?:top|right|bottom|left)\s*:\s*[^;]+;?/i', '', $css) ?? $css;

        // Fonctions CSS complexes peu fiables dans un moteur PDF HTML.
        $css = preg_replace('/clamp\(\s*[^,]+,\s*([^,]+),\s*[^)]+\)/i', '$1', $css) ?? $css;
        $css = preg_replace('/(?:min|max)\(\s*([^,()]+)(?:,[^()]*)+\)/i', '$1', $css) ?? $css;
        $css = preg_replace('/calc\([^)]*\)/i', 'auto', $css) ?? $css;

        // Conversion des unités viewport vers points pour stabiliser le rendu.
        $css = preg_replace_callback('/(-?\d+(?:\.\d+)?)\s*(vw|vh|vmin|vmax)\b/i', static function (array $m): string {
            $value = (float)$m[1];
            $unit = strtolower((string)$m[2]);
            $factor = ($unit === 'vh' || $unit === 'vmax') ? 7.8 : 5.2;
            $pt = $value * $factor;
            if ($pt > 72.0) {
                $pt = 72.0;
            } elseif ($pt < -72.0) {
                $pt = -72.0;
            }
            return rtrim(rtrim(number_format($pt, 2, '.', ''), '0'), '.') . 'pt';
        }, $css) ?? $css;

        return trim($css);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }

    private function ensureResponsiveHtmlDocument(string $html): string
    {
        $html = trim($html);
        $baseHead = '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';

        if ($html === '') {
            return '<!DOCTYPE html><html lang="fr"><head>' . $baseHead . '</head><body></body></html>';
        }

        if (!preg_match('/<html\b/i', $html)) {
            if (preg_match('/<body\b[\s\S]*<\/body>/i', $html, $m)) {
                $body = (string)$m[0];
            } else {
                $body = '<body>' . $html . '</body>';
            }
            return '<!DOCTYPE html><html lang="fr"><head>' . $baseHead . '</head>' . $body . '</html>';
        }

        if (preg_match('/<html\b[\s\S]*<\/html>/i', $html, $m)) {
            $html = trim((string)$m[0]);
        }

        if (!preg_match('/<!doctype\s+html\b/i', $html)) {
            $html = "<!DOCTYPE html>\n" . $html;
        }

        if (!preg_match('/<head\b/i', $html)) {
            $html = preg_replace('/<html\b([^>]*)>/i', '<html$1><head>' . $baseHead . '</head>', $html, 1) ?? $html;
        } else {
            if (!preg_match('/<meta[^>]+charset\s*=\s*["\']?utf-8["\']?/i', $html)) {
                $html = preg_replace('/<head\b[^>]*>/i', '$0<meta charset="UTF-8">', $html, 1) ?? $html;
            }
            if (!preg_match('/<meta[^>]+name\s*=\s*["\']viewport["\']/i', $html)) {
                $html = preg_replace('/<head\b[^>]*>/i', '$0<meta name="viewport" content="width=device-width, initial-scale=1.0">', $html, 1) ?? $html;
            }
        }

        return trim($html);
    }
}
