<?php

declare(strict_types=1);

/**
 * Rendu d'un deck : spec JSON → page HTML autonome 16:9.
 *
 * Source unique du rendu, partagée par l'aperçu du volet (iframe blob), la
 * fenêtre externe, la page de partage et le futur export. Le HTML produit ne
 * dépend d'aucune ressource distante : polices système, images servies par
 * l'app, styles et script inline. Il s'affiche donc à l'identique partout, y
 * compris sous la CSP stricte de l'aperçu.
 *
 * Échelle typographique : unités `cqw` (pourcentage de la largeur du conteneur
 * de slide). Une slide rendue à 320 px ou à 1600 px est ainsi rigoureusement la
 * même image, à l'échelle près — indispensable pour que la vignette, l'aperçu
 * et l'export coïncident.
 */
final class DeckRenderer
{
    /** Échelle typographique, en cqw (1 cqw = 1 % de la largeur de la slide). */
    private const FONT_SIZES = [
        'xs'  => 1.5,
        's'   => 2.0,
        'm'   => 2.5,
        'l'   => 4.0,
        'xl'  => 5.2,
        'xxl' => 6.8,
        'xxxl' => 20.0,
    ];

    /**
     * Rayon d'un rectangle arrondi : un sixième de son petit côté — la valeur
     * par défaut de la forme « rectangle à coins arrondis » de PowerPoint, que
     * l'export PPTX emploie telle quelle.
     */
    public const ROUND_RATIO = 0.16667;

    /**
     * Document HTML complet.
     *
     * @param array $deck spec normalisée (DeckSpec::normalize)
     * @param array $opts 'mode' => 'single'|'scroll' (défaut single),
     *                    'nav'  => bool (navigation clavier/clic, défaut true)
     */
    public static function render(array $deck, array $opts = []): string
    {
        $theme  = is_array($deck['theme'] ?? null) ? $deck['theme'] : DeckSpec::normalizeTheme([]);
        $slides = is_array($deck['slides'] ?? null) ? $deck['slides'] : [];
        $mode   = ($opts['mode'] ?? 'single') === 'scroll' ? 'scroll' : 'single';
        $nav    = ($opts['nav'] ?? true) !== false;
        $title  = (string)($deck['title'] ?? 'Présentation');

        $body = '';
        foreach ($slides as $i => $slide) {
            $body .= self::renderSlide(is_array($slide) ? $slide : [], (int)$i);
        }
        if ($body === '') {
            $body = '<section class="slide is-active" data-index="0" data-layout="titre">'
                . '<div class="el el-text el-role-titre" style="left:8%;top:42%;width:84%;height:16%">'
                . 'Présentation vide</div></section>';
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::esc($title) . '</title>'
            . '<style>' . self::css($theme) . '</style></head>'
            . '<body class="deck deck-' . $mode . '" data-count="' . count($slides) . '">'
            . $body
            . ($nav ? '<script>' . self::script() . '</script>' : '')
            . '</body></html>';
    }

    /** Une slide, en fragment autonome (réutilisable pour les exports). */
    public static function renderSlide(array $slide, int $index): string
    {
        $layout   = (string)($slide['layout'] ?? 'titre-contenu');
        $elements = is_array($slide['elements'] ?? null) ? $slide['elements'] : [];

        $html = '';
        foreach ($elements as $el) {
            if (is_array($el)) {
                $html .= self::renderElement($el);
            }
        }

        return '<section class="slide' . ($index === 0 ? ' is-active' : '') . '"'
            . ' data-index="' . $index . '"'
            . ' data-slide-id="' . self::esc((string)($slide['id'] ?? ('s' . ($index + 1)))) . '"'
            . ' data-layout="' . self::esc($layout) . '">'
            . $html
            . '</section>';
    }

    /** Un élément positionné. */
    private static function renderElement(array $el): string
    {
        $type = (string)($el['type'] ?? '');
        $box  = sprintf(
            'left:%s%%;top:%s%%;width:%s%%;height:%s%%',
            self::num($el['x'] ?? 0),
            self::num($el['y'] ?? 0),
            self::num($el['w'] ?? 10),
            self::num($el['h'] ?? 10)
        );
        $id = self::esc((string)($el['id'] ?? ''));

        switch ($type) {
            case 'text':
                $size   = self::fontSizeCqw(
                    (string)($el['size'] ?? 'm'),
                    (string)($el['text'] ?? ''),
                    (float)($el['w'] ?? 0),
                    (float)($el['h'] ?? 0)
                );
                $style  = $box
                    . ';font-size:' . $size . 'cqw'
                    . ';font-weight:' . (int)($el['weight'] ?? 400)
                    . ';text-align:' . self::esc((string)($el['align'] ?? 'left'))
                    . self::typographyCss($el);

                return '<div class="el el-text el-role-' . self::esc((string)($el['role'] ?? 'corps')) . '"'
                    . ' data-el-id="' . $id . '" style="' . $style . '">'
                    . self::escMultiline((string)($el['text'] ?? ''))
                    . '</div>';

            case 'bullets':
                $size  = self::FONT_SIZES[(string)($el['size'] ?? 'm')] ?? self::FONT_SIZES['m'];
                $style = $box . ';font-size:' . $size . 'cqw'
                    . ';font-weight:' . (int)($el['weight'] ?? 400)
                    . ';text-align:' . self::esc((string)($el['align'] ?? 'left'))
                    . self::typographyCss($el);
                $marker = self::cssColor((string)($el['marker'] ?? ''));
                if ($marker !== '') {
                    $style .= ';--marker:' . $marker;
                }
                $items = '';
                foreach ((array)($el['items'] ?? []) as $item) {
                    $items .= '<li>' . self::escMultiline((string)$item) . '</li>';
                }

                return '<ul class="el el-bullets" data-el-id="' . $id . '" style="' . $style . '">'
                    . $items . '</ul>';

            case 'image':
                $url = (string)($el['url'] ?? '');
                $fit = (string)($el['fit'] ?? 'cover');
                $anchor = (string)($el['anchor'] ?? 'center') === 'left' ? ';object-position:left center' : '';
                $inner = $url !== ''
                    ? '<img src="' . self::esc($url) . '" alt="" style="object-fit:' . self::esc($fit) . $anchor . '">'
                    // Sans URL (image pas encore générée, ou génération en échec),
                    // on rend un cadre neutre plutôt qu'un trou dans la slide.
                    : '<span class="el-image-placeholder"></span>';

                return '<div class="el el-image" data-el-id="' . $id . '" style="' . $box . '">'
                    . $inner . '</div>';

            case 'shape':
                $fill    = (string)($el['fill'] ?? 'accent');
                $color   = in_array($fill, DeckSpec::FILL_TOKENS, true)
                    ? 'var(--' . $fill . ')'
                    : $fill;
                $style = $box
                    . ';background:' . self::esc($color)
                    . ';opacity:' . self::num($el['opacity'] ?? 1);
                $kind = (string)($el['shape'] ?? 'rect');
                if ($kind === 'circle') {
                    $style .= ';border-radius:50%';
                } elseif ($kind === 'round') {
                    // Petit côté en cqw : la hauteur se compte en % de la hauteur (× 9/16).
                    $short = min((float)($el['w'] ?? 10), (float)($el['h'] ?? 10) * 9 / 16);
                    $style .= ';border-radius:' . self::num($short * self::ROUND_RATIO) . 'cqw';
                }

                return '<div class="el el-shape" data-el-id="' . $id . '" style="' . $style . '"></div>';

            case 'table':
                $size  = self::FONT_SIZES[(string)($el['size'] ?? 's')] ?? self::FONT_SIZES['s'];
                $head  = self::cssColor((string)($el['head'] ?? ''));
                $band  = self::cssColor((string)($el['band'] ?? ''));
                $rows  = '';
                foreach (array_values((array)($el['rows'] ?? [])) as $r => $row) {
                    $isHead = $r === 0 && !empty($el['header']);
                    $tag    = $isHead ? 'th' : 'td';
                    $data   = $r - (empty($el['header']) ? 0 : 1);
                    $bg     = $isHead ? $head : ($band !== '' && $data % 2 === 1 ? $band : '');
                    $rows  .= $bg !== '' ? '<tr style="background:' . self::esc($bg) . '">' : '<tr>';
                    // Texte d'un en-tête recoloré : lisible sur SON fond.
                    $ink = $isHead && $head !== '' ? ' style="color:' . self::esc(self::headInk((string)$el['head'])) . '"' : '';
                    foreach ((array)$row as $cell) {
                        $rows .= '<' . $tag . $ink . '>' . self::esc((string)$cell) . '</' . $tag . '>';
                    }
                    $rows .= '</tr>';
                }

                return '<div class="el el-table" data-el-id="' . $id . '" style="' . $box . ';font-size:' . $size . 'cqw">'
                    . '<table>' . $rows . '</table></div>';
        }

        return '';
    }

    /** Feuille de style du deck, thème injecté en variables CSS. */
    private static function css(array $theme): string
    {
        $bg     = self::esc((string)($theme['bg'] ?? '#ffffff'));
        $text   = self::esc((string)($theme['text'] ?? '#16181d'));
        $muted  = self::esc((string)($theme['muted'] ?? '#6b7280'));
        $accent = self::esc((string)($theme['accent'] ?? '#2563eb'));
        $fTitle = (string)($theme['font_title'] ?? 'Helvetica, Arial, sans-serif');
        $fBody  = (string)($theme['font_body'] ?? 'Helvetica, Arial, sans-serif');

        // Couleur de texte posée SUR l'aplat d'accent (slides « section ») :
        // choisie par luminance, sinon un accent clair (ambre, citron) reçoit
        // du blanc et devient illisible.
        $onAccent = self::onColor($accent);

        // Teintes dérivées de l'accent (DeckSpec::derivedColors) : une forme
        // ou un texte qui les nomme suit la couleur du thème.
        $derived = '';
        foreach (DeckSpec::derivedColors((string)($theme['accent'] ?? '#2563eb'), (string)($theme['bg'] ?? '#ffffff')) as $key => $value) {
            if ($key !== 'on_accent') {
                $derived .= ';--' . str_replace('_', '-', $key) . ':' . self::esc($value);
            }
        }

        return <<<CSS
*,*::before,*::after{box-sizing:border-box}
:root{--bg:{$bg};--text:{$text};--muted:{$muted};--accent:{$accent};--on-accent:{$onAccent}{$derived}}
html,body{margin:0;padding:0;height:100%;background:#0b0b0d}
body{font-family:{$fBody};display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2.5vh;padding:1.5vh 1.5vw}
/* Une slide = un canvas 16:9 strict, letterboxé dans l'espace disponible. */
.slide{position:relative;width:min(100%,calc((100vh - 3vh) * 16 / 9));aspect-ratio:16/9;background:var(--bg);color:var(--text);container-type:size;overflow:hidden;flex:none;box-shadow:0 2px 24px rgba(0,0,0,.35)}
.deck-single .slide{display:none}
.deck-single .slide.is-active{display:block}
.deck-scroll{justify-content:flex-start;overflow-y:auto}
.el{position:absolute;margin:0}
.el-text{line-height:1.22;white-space:pre-wrap;overflow-wrap:anywhere;font-family:{$fBody}}
.el-role-titre{font-family:{$fTitle};line-height:1.12;letter-spacing:-.01em}
.el-role-sous-titre{color:var(--muted)}
.el-role-legende,.el-role-numero{color:var(--muted)}
.el-role-citation{font-family:{$fTitle};font-style:italic;line-height:1.3}
.el-role-chiffre{font-family:{$fTitle};color:var(--accent);line-height:1.05;letter-spacing:-.01em}
.el-table{overflow:hidden}
.el-table table{width:100%;border-collapse:collapse;line-height:1.3}
.el-table th,.el-table td{padding:.5em .8em;text-align:left;vertical-align:middle;border-bottom:.12cqw solid color-mix(in srgb,var(--muted) 35%,transparent);overflow-wrap:anywhere}
.el-table th{background:var(--accent);color:var(--on-accent);font-weight:700;border-bottom:0}
.el-bullets{list-style:none;padding:0;line-height:1.4;display:flex;flex-direction:column;gap:1.6cqw;overflow-wrap:anywhere}
.el-bullets li{position:relative;padding-left:2.6cqw}
.el-bullets li::before{content:"";position:absolute;left:0;top:.52em;width:.9cqw;height:.9cqw;border-radius:50%;background:var(--marker,var(--accent))}
.el-image{overflow:hidden}
.el-image img{width:100%;height:100%;display:block}
.el-image-placeholder{display:block;width:100%;height:100%;background:
  repeating-linear-gradient(45deg,rgba(127,127,127,.10) 0 8px,rgba(127,127,127,.18) 8px 16px)}
/* Slides à fond couvrant : le texte doit rester lisible quel que soit le thème. */
.slide[data-layout="section"] .el-text{color:var(--on-accent)}
.slide[data-layout="section"] .el-role-numero{color:var(--on-accent);opacity:.7}
.slide[data-layout="image-pleine"] .el-text{color:#fff;text-shadow:0 .2cqw 1cqw rgba(0,0,0,.5)}
.slide[data-layout="image-pleine"] .el-role-numero{color:rgba(255,255,255,.75)}
@media print{
  html,body{background:#fff;display:block;padding:0}
  .slide{width:100%;box-shadow:none;break-after:page;page-break-after:always;display:block!important}
  .slide:last-child{break-after:auto;page-break-after:auto}
}
CSS;
    }

    /**
     * Navigation embarquée : flèches, Espace, Page haut/bas, Début/Fin, clic
     * sur les bords, molette. Le deck est donc pilotable partout — y compris
     * sur la page de partage, qui ne charge aucun script de l'app.
     *
     * L'index courant est aussi remonté au parent (`mia-deck-index`), ce qui
     * permet au volet de synchroniser sa bande de vignettes sans dupliquer
     * cette logique.
     */
    private static function script(): string
    {
        return <<<'JS'
(function(){
  var slides = Array.prototype.slice.call(document.querySelectorAll('.slide'));
  if (!slides.length) return;
  var i = 0;
  function show(n){
    i = Math.max(0, Math.min(slides.length - 1, n));
    slides.forEach(function(s, k){ s.classList.toggle('is-active', k === i); });
    try { parent.postMessage({ type: 'mia-deck-index', index: i, total: slides.length }, '*'); } catch (e) {}
  }
  window.addEventListener('keydown', function(e){
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { show(i + 1); e.preventDefault(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { show(i - 1); e.preventDefault(); }
    else if (e.key === 'Home') { show(0); e.preventDefault(); }
    else if (e.key === 'End') { show(slides.length - 1); e.preventDefault(); }
  });
  document.addEventListener('click', function(e){
    // En mode édition, le clic sert à sélectionner un élément : naviguer ici
    // rendrait toute manipulation impossible.
    if (document.documentElement.classList.contains('mia-deck-editing')) return;
    // Clic sur le tiers gauche = précédent, ailleurs = suivant.
    show(e.clientX < window.innerWidth / 3 ? i - 1 : i + 1);
  });
  window.addEventListener('message', function(e){
    var d = e.data;
    if (!d || typeof d !== 'object') return;
    if (d.type === 'mia-deck-goto' && typeof d.index === 'number') show(d.index);
    if (d.type === 'mia-deck-mode') document.body.className = 'deck deck-' + (d.mode === 'scroll' ? 'scroll' : 'single');
    // L'éditeur (injecté par le volet) demande l'index courant au démarrage.
    if (d.type === 'mia-deck-where') post();
  });
  function post(){ try { parent.postMessage({ type: 'mia-deck-index', index: i, total: slides.length }, '*'); } catch (e) {} }
  show(0);
})();
JS;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Taille de police d'un élément, en cqw (1 cqw = 1 % de la largeur de la
     * slide). Publique : les exports PPTX et PDF convertissent cette même
     * échelle en points, pour que les trois rendus coïncident.
     *
     * Avec un texte et une boîte, la taille est RÉDUITE pour tenir : un titre
     * long passait sinon sur deux lignes et débordait sur le filet d'accent ou
     * sur le sous-titre — la mise en page est fixe, c'est donc au corps de
     * texte de céder. Estimation volontairement grossière (largeur moyenne d'un
     * caractère ≈ 0,52 em) : elle n'a pas à être exacte, seulement identique
     * dans les trois rendus.
     *
     * @param float $boxW largeur de la boîte en % de la largeur de la slide
     * @param float $boxH hauteur de la boîte en % de la HAUTEUR de la slide
     */
    public static function fontSizeCqw(string $size, string $text = '', float $boxW = 0.0, float $boxH = 0.0): float
    {
        $base = self::FONT_SIZES[$size] ?? self::FONT_SIZES['m'];
        $len  = mb_strlen(trim($text));
        if ($len === 0 || $boxW <= 0 || $boxH <= 0) {
            return $base;
        }

        // 1 % de hauteur = 0,5625 cqw (canvas 16:9) : tout se compare en cqw.
        $available = $boxH * 9 / 16;
        $font      = $base;
        $floor     = $base * 0.6;

        while ($font > $floor) {
            $perLine = max(1.0, $boxW / (0.52 * $font));
            $lines   = max(1, (int)ceil($len / $perLine));
            if ($lines * 1.22 * $font <= $available) {
                break;
            }
            $font -= $base * 0.05;
        }

        return round(max($floor, $font), 2);
    }

    /**
     * Surcharges de mise en forme posées par l'éditeur (couleur, italique,
     * police). Rendues en style inline plutôt qu'en classes : elles sont
     * propres à un élément, et l'éditeur les manipule directement dans le DOM
     * de l'aperçu.
     */
    private static function typographyCss(array $el): string
    {
        $css   = '';
        $color = self::cssColor((string)($el['color'] ?? ''));
        if ($color !== '') {
            $css .= ';color:' . $color;
        }
        if (!empty($el['italic'])) {
            $css .= ';font-style:italic';
        }
        $font = self::fontStack((string)($el['font'] ?? ''));
        if ($font !== '') {
            $css .= ';font-family:' . $font;
        }

        return $css;
    }

    /** Texte lisible sur le fond d'un en-tête de tableau recoloré. */
    private static function headInk(string $head): string
    {
        return match ($head) {
            'deep'   => 'var(--on-deep)',
            'accent' => 'var(--on-accent)',
            'text'   => 'var(--bg)',
            default  => preg_match('/^#[0-9a-fA-F]{6}$/', $head) === 1 ? self::onColor($head) : 'var(--text)',
        };
    }

    /** Couleur CSS d'une valeur de spec : #rrggbb, ou variable du thème pour une couleur nommée ; '' sinon. */
    private static function cssColor(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return strtolower($value);
        }

        return in_array($value, [...DeckSpec::TEXT_TOKENS, ...DeckSpec::FILL_TOKENS], true) ? 'var(--' . $value . ')' : '';
    }

    /**
     * Couleur nommée de la spec (`deep`, `on-deep`, `accent-ink`…) → #rrggbb
     * du thème. Source unique des trois rendus ; null pour un nom inconnu.
     */
    public static function tokenColor(string $token, array $theme): ?string
    {
        if (in_array($token, ['accent', 'text', 'muted', 'bg'], true)) {
            return (string)($theme[$token] ?? '');
        }
        if (!in_array($token, [...DeckSpec::TEXT_TOKENS, ...DeckSpec::FILL_TOKENS], true)) {
            return null;
        }
        $key     = str_replace('-', '_', $token);
        $derived = $theme[$key] ?? DeckSpec::derivedColors((string)($theme['accent'] ?? '#2563eb'), (string)($theme['bg'] ?? '#ffffff'))[$key] ?? null;

        return is_string($derived) ? $derived : null;
    }

    /** Pile CSS d'une police du catalogue ; chaîne vide = police du thème. */
    public static function fontStack(string $key): string
    {
        return (string)(DeckSpec::FONTS[$key]['css'] ?? '');
    }

    /**
     * Couleur d'un texte, règles de la feuille de style reprises telles quelles
     * (fond couvrant, rôles secondaires en `muted`). Source unique des trois
     * rendus : sans elle, un export PDF afficherait du texte sombre sur l'aplat
     * d'accent d'une slide « section ».
     */
    public static function textColor(array $el, string $layout, array $theme): string
    {
        // Choix explicite de l'utilisateur : il l'emporte sur les règles
        // automatiques, y compris sur les fonds couvrants.
        $chosen = trim((string)($el['color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $chosen) === 1) {
            return strtolower($chosen);
        }
        // Couleur nommée posée par la mise en page (texte sur fond sombre…).
        $named = $chosen !== '' ? self::tokenColor($chosen, $theme) : null;
        if ($named !== null && $named !== '') {
            return $named;
        }
        if ($layout === 'image-pleine') {
            return '#ffffff';
        }
        if ($layout === 'section') {
            return self::onColor((string)($theme['accent'] ?? '#2563eb'));
        }
        $role = (string)($el['role'] ?? 'corps');
        if ($role === 'chiffre') {
            return (string)($theme['accent'] ?? '#2563eb');
        }
        if (in_array($role, ['sous-titre', 'legende', 'numero'], true)) {
            return (string)($theme['muted'] ?? '#6b7280');
        }

        return (string)($theme['text'] ?? '#16181d');
    }

    /** Remplissage d'une forme : nom de rôle du thème, sinon couleur littérale. */
    public static function fillColor(string $fill, array $theme): string
    {
        if (in_array($fill, DeckSpec::FILL_TOKENS, true)) {
            return (string)(self::tokenColor($fill, $theme) ?: '#2563eb');
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $fill) === 1 ? $fill : (string)($theme['accent'] ?? '#2563eb');
    }

    /**
     * Noir ou blanc selon la luminance d'un fond, pour garantir le contraste
     * du texte posé dessus. Luminance relative WCAG, seuil 0.5.
     */
    public static function onColor(string $hex): string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m) !== 1) {
            return '#ffffff';
        }
        $rgb = array_map(
            static function (string $channel): float {
                $c = hexdec($channel) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (float)pow(($c + 0.055) / 1.055, 2.4);
            },
            str_split($m[1], 2)
        );
        $luminance = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];

        return $luminance > 0.4 ? '#141414' : '#ffffff';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Échappe en conservant les retours à la ligne (rendus par white-space:pre-wrap). */
    private static function escMultiline(string $value): string
    {
        return self::esc($value);
    }

    /** Nombre sûr pour une feuille de style (jamais de notation exponentielle ni de locale). */
    private static function num(mixed $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
