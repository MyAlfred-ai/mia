<?php

declare(strict_types=1);

/**
 * Spécification d'une présentation (deck) — source unique de vérité.
 *
 * Le deck est stocké en JSON dans `messages.content`, avec le marqueur
 * `files_json = [['type' => 'slide_deck', ...]]` (même mécanique que l'outil
 * carte, cf. ChatController::generateMap).
 *
 * Pourquoi une spec structurée plutôt que du HTML libre :
 *  - le déplacement/redimensionnement d'éléments impose des coordonnées, qui
 *    sont exactement le modèle de PowerPoint et de Google Slides ;
 *  - les exports (PPTX, PDF, Slides) deviennent des mappings directs au lieu
 *    d'une rétro-ingénierie de HTML ;
 *  - le HTML n'est plus qu'un RENDU de la spec (cf. DeckRenderer).
 *
 * Géométrie : x/y/w/h en POURCENTAGE du canvas 16:9, origine en haut à gauche.
 * Conversion triviale vers EMU (PPTX) et millimètres (PDF).
 *
 * Toute écriture de deck — génération LLM comme sauvegarde depuis l'éditeur —
 * passe par `normalize()` : rien n'entre en base sans être borné et nettoyé.
 */
final class DeckSpec
{
    /** Version du format. Incrémenter si la structure change de façon incompatible. */
    public const VERSION = 1;

    /** Bornes du nombre de slides (demande utilisateur comme sortie LLM). */
    public const MIN_SLIDES = 1;
    public const MAX_SLIDES = 30;
    public const DEFAULT_SLIDES = 10;

    /** Garde-fous de volume (une spec forgée ne doit pas pouvoir exploser la base). */
    /**
     * Les mises en page « aplats » dessinent leurs cartes, pastilles et
     * fonds avec des formes : une slide de six cartes en compte une trentaine.
     */
    public const MAX_ELEMENTS_PER_SLIDE = 48;
    public const MAX_BULLETS = 10;
    public const MAX_TITLE_LEN = 220;
    public const MAX_TEXT_LEN = 2000;
    public const MAX_BULLET_LEN = 400;
    public const MAX_NOTES_LEN = 1500;

    /** Plus petite dimension d'élément, en % du canvas (filets d'accent). */
    public const MIN_SIZE = 0.2;

    /** Préfixe autorisé pour les images d'un deck (rien d'externe : cf. CSP de l'aperçu). */
    public const IMAGE_URL_PREFIX = '/assets/img/';

    /** Mises en page reconnues. Le LLM choisit dans cette liste fermée. */
    public const LAYOUTS = [
        'titre',          // couverture
        'titre-contenu',  // titre + puces
        'deux-colonnes',  // deux blocs de puces côte à côte
        'image-droite',   // puces à gauche, visuel à droite
        'image-pleine',   // visuel plein cadre + titre en surimpression
        'citation',       // citation centrée
        'section',        // intercalaire de partie
        'conclusion',     // synthèse / appel à l'action
    ];

    /**
     * Mises en page des présentations LIVRÉES DANS LA CONVERSATION (Markdown
     * lu par MarkdownDeck), en plus des précédentes. Hors de LAYOUTS à dessein :
     * cette liste est proposée au modèle par l'outil Présentation
     * (DeckGenerator), qui ne sait pas fournir les données qu'elles exigent
     * (valeurs, tableau, colonnes titrées, étapes, dates).
     */
    public const EXTRA_LAYOUTS = [
        'chiffres',     // chiffres clés : grandes valeurs et leur légende
        'tableau',      // un vrai tableau
        'comparaison',  // deux colonnes titrées
        'etapes',       // étapes numérotées
        'chronologie',  // dates sur une ligne de temps
        'cartes',       // points « **Terme** — explication » en cartes
    ];

    /** Types d'éléments posables sur une slide. */
    public const ELEMENT_TYPES = ['text', 'bullets', 'image', 'shape', 'table'];

    /** Rôles typographiques (pilotent le style, jamais la position). */
    public const ROLES = ['titre', 'sous-titre', 'corps', 'citation', 'legende', 'numero', 'chiffre'];

    /** Bornes d'un tableau : au-delà, il ne se lit plus sur une slide. */
    public const MAX_TABLE_ROWS = 12;
    public const MAX_TABLE_COLS = 6;
    public const MAX_CELL_LEN   = 200;

    public const SIZES  = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl'];

    /** Libellés des crans de taille, pour l'éditeur (les crans restent fermés). */
    public const SIZE_LABELS = [
        'xs'  => 'Très petit',
        's'   => 'Petit',
        'm'   => 'Normal',
        'l'   => 'Grand',
        'xl'  => 'Très grand',
        'xxl' => 'Titre',
        'xxxl' => 'Géant',
    ];

    public const ALIGNS = ['left', 'center', 'right'];

    /**
     * Polices proposées à l'utilisateur, en LISTE FERMÉE : chaque entrée
     * décline la même famille pour les trois rendus. Une famille libre
     * (« Comic Sans ») s'afficherait à l'écran puis retomberait sur une police
     * de substitution dans le PPTX ou le PDF — la présentation exportée ne
     * ressemblerait plus à l'aperçu.
     *
     * `''` = la police du thème (choix par défaut, aucune surcharge).
     */
    public const FONTS = [
        ''      => ['label' => 'Police du thème', 'css' => '',                                                  'pptx' => '',          'pdf' => ''],
        'sans'  => ['label' => 'Sans serif',      'css' => "'Helvetica Neue', Helvetica, Arial, sans-serif",     'pptx' => 'Arial',     'pdf' => 'dejavusans'],
        'serif' => ['label' => 'Serif',           'css' => "Georgia, 'Times New Roman', serif",                  'pptx' => 'Georgia',   'pdf' => 'dejavuserif'],
        'mono'  => ['label' => 'Chasse fixe',     'css' => "'SFMono-Regular', Menlo, Consolas, monospace",       'pptx' => 'Consolas',  'pdf' => 'dejavusansmono'],
    ];

    public const FITS   = ['cover', 'contain'];
    /** `round` = rectangle aux coins arrondis (rayon : un sixième du petit côté, celui de PowerPoint). */
    public const SHAPES = ['rect', 'circle', 'line', 'round'];

    /**
     * Couleurs nommées d'une forme : celles du thème, plus les teintes que
     * normalizeTheme() DÉRIVE de l'accent (fond sombre, teinte claire…). Une
     * forme qui les nomme suit donc la couleur de l'entreprise.
     */
    public const FILL_TOKENS = ['accent', 'text', 'muted', 'bg', 'deep', 'tint', 'soft', 'on-accent', 'on-deep'];

    /**
     * Couleurs nommées d'un texte, posées par la mise en page (jamais par
     * l'éditeur, qui écrit une couleur #rrggbb) : texte sur fond sombre, sur
     * aplat d'accent, valeur lisible en couleur d'accent.
     */
    public const TEXT_TOKENS = ['text', 'muted', 'accent', 'accent-ink', 'on-accent', 'on-accent-faint',
        'on-deep', 'on-deep-muted', 'on-deep-accent'];

    /** Options de génération proposées dans le volet. */
    public const TEXT_LENGTHS = ['court', 'moyen', 'detaille'];
    public const TONES        = ['professionnel', 'pedagogique', 'inspirant', 'direct'];

    /**
     * Thèmes visuels. Volontairement peu nombreux et franchement distincts :
     * un choix évident à l'œil vaut mieux qu'un nuancier.
     *
     * `font_title` / `font_body` sont des piles système — le deck reste
     * autonome (aucun appel réseau, donc rendu identique en aperçu, en partage
     * et à l'export).
     */
    public const THEMES = [
        'sobre' => [
            'label'      => 'Sobre',
            'bg'         => '#ffffff',
            'text'       => '#16181d',
            'muted'      => '#6b7280',
            'accent'     => '#2563eb',
            'font_title' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
            'font_body'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        ],
        'audacieux' => [
            'label'      => 'Audacieux',
            'bg'         => '#0f172a',
            'text'       => '#f8fafc',
            'muted'      => '#94a3b8',
            'accent'     => '#f59e0b',
            'font_title' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
            'font_body'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        ],
        'corporate' => [
            'label'      => 'Corporate',
            'bg'         => '#f8fafc',
            'text'       => '#0f172a',
            'muted'      => '#64748b',
            'accent'     => '#0369a1',
            'font_title' => "Georgia, 'Times New Roman', serif",
            'font_body'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        ],
        'chaleureux' => [
            'label'      => 'Chaleureux',
            'bg'         => '#fffaf3',
            'text'       => '#432818',
            'muted'      => '#8a6d5c',
            'accent'     => '#ea580c',
            'font_title' => "Georgia, 'Times New Roman', serif",
            'font_body'  => "Georgia, 'Times New Roman', serif",
        ],
        'sombre' => [
            'label'      => 'Sombre',
            'bg'         => '#101114',
            'text'       => '#ededed',
            'muted'      => '#8e8ea0',
            'accent'     => '#10a37f',
            'font_title' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
            'font_body'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        ],
    ];

    public const DEFAULT_THEME = 'sobre';

    /**
     * Normalise une spec complète : structure garantie en sortie, quelles que
     * soient les approximations du LLM ou d'un POST forgé.
     */
    public static function normalize(array $raw): array
    {
        $slides = [];
        $seenIds = [];
        $rawSlides = is_array($raw['slides'] ?? null) ? $raw['slides'] : [];
        foreach (array_slice($rawSlides, 0, self::MAX_SLIDES) as $i => $rawSlide) {
            if (!is_array($rawSlide)) {
                continue;
            }
            $slide = self::normalizeSlide($rawSlide, count($slides));
            // Identifiants uniques garantis : un LLM répète volontiers le même
            // id, et l'édition ciblée d'une slide s'appliquerait alors à
            // plusieurs d'entre elles.
            if (isset($seenIds[$slide['id']])) {
                $slide['id'] = $slide['id'] . '-' . (count($slides) + 1);
            }
            $seenIds[$slide['id']] = true;
            $slides[] = $slide;
        }

        return [
            'version' => self::VERSION,
            'title'   => self::text($raw['title'] ?? '', self::MAX_TITLE_LEN) ?: 'Présentation',
            'ratio'   => '16:9',
            'theme'   => self::normalizeTheme(is_array($raw['theme'] ?? null) ? $raw['theme'] : []),
            'options' => self::normalizeOptions(is_array($raw['options'] ?? null) ? $raw['options'] : []),
            'outline' => self::normalizeOutline(is_array($raw['outline'] ?? null) ? $raw['outline'] : []),
            'slides'  => $slides,
        ];
    }

    /** Thème : preset connu + surcharges de couleurs valides uniquement. */
    public static function normalizeTheme(array $raw): array
    {
        $preset = (string)($raw['preset'] ?? self::DEFAULT_THEME);
        if (!isset(self::THEMES[$preset])) {
            $preset = self::DEFAULT_THEME;
        }
        $base = self::THEMES[$preset];

        $theme = [
            'preset'     => $preset,
            'bg'         => self::color($raw['bg']     ?? null, $base['bg']),
            'text'       => self::color($raw['text']   ?? null, $base['text']),
            'muted'      => self::color($raw['muted']  ?? null, $base['muted']),
            'accent'     => self::color($raw['accent'] ?? null, $base['accent']),
            'font_title' => $base['font_title'],
            'font_body'  => $base['font_body'],
        ];

        return $theme + self::derivedColors($theme['accent'], $theme['bg']);
    }

    /**
     * Teintes DÉRIVÉES de l'accent, recalculées à chaque normalisation (jamais
     * lues dans la spec reçue) : un accent changé — celui de l'entreprise —
     * les entraîne toutes. Le texte posé sur chacune garde un contraste
     * lisible, quel que soit l'accent (un jaune ne reçoit pas de blanc).
     *
     * @return array<string, string>
     */
    public static function derivedColors(string $accent, string $bg): array
    {
        $dark     = self::luminance($bg) < 0.25;
        $deep     = self::mixColor($accent, '#0b1220', 0.38);
        $tint     = self::mixColor($accent, $bg, 0.10);
        $soft     = self::mixColor($accent, $bg, 0.28);
        // Texte posé sur l'accent (intercalaire, pastilles) : celui des deux
        // qui contraste le plus — un orange ou un vert vif reçoit du noir.
        $onAccent = self::readableOn($accent);
        $onDeep   = self::readableOn($deep);

        // Valeurs et numéros en couleur d'accent, posés sur le fond ou sur la
        // teinte claire : assombri (éclairci sur fond sombre) jusqu'à 4,5:1.
        $ink = $accent;
        for ($k = 1; $k <= 10 && min(self::contrast($ink, $bg), self::contrast($ink, $tint)) < 4.5; $k++) {
            $ink = self::mixColor($accent, $dark ? '#ffffff' : '#0b1220', 1 - $k * 0.1);
        }

        return [
            'deep'            => $deep,
            'tint'            => $tint,
            'soft'            => $soft,
            'accent_ink'      => $ink,
            'on_accent'       => $onAccent,
            'on_accent_faint' => self::mixColor($onAccent, $accent, 0.24),
            'on_deep'         => $onDeep,
            'on_deep_muted'   => self::mixColor($onDeep, $deep, 0.70),
            'on_deep_accent'  => self::mixColor($accent, $onDeep, 0.55),
        ];
    }

    /** Blanc ou quasi-noir : celui qui se lit le mieux sur `$hex`. */
    private static function readableOn(string $hex): string
    {
        return self::contrast('#ffffff', $hex) >= self::contrast('#141414', $hex) ? '#ffffff' : '#141414';
    }

    /** Mélange de deux couleurs #rrggbb : `$ratio` de la première. */
    public static function mixColor(string $a, string $b, float $ratio): string
    {
        $ca  = self::rgb($a);
        $cb  = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int)round($ca[$i] * $ratio + $cb[$i] * (1 - $ratio))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** Luminance relative WCAG d'une couleur #rrggbb. */
    public static function luminance(string $hex): float
    {
        $c = array_map(static function (int $v): float {
            $x = $v / 255;

            return $x <= 0.03928 ? $x / 12.92 : (float)pow(($x + 0.055) / 1.055, 2.4);
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** Rapport de contraste WCAG entre deux couleurs. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m) === 1
            ? array_map(static fn (string $c): int => (int)hexdec($c), str_split($m[1], 2))
            : [255, 255, 255];
    }

    /** Options de génération (rejouées lors d'une régénération). */
    public static function normalizeOptions(array $raw): array
    {
        $imageStyle = self::text($raw['image_style'] ?? '', 60);
        $toolId     = (int)($raw['image_tool_id'] ?? 0);

        return [
            'slide_count'   => self::slideCount($raw['slide_count'] ?? self::DEFAULT_SLIDES),
            'text_length'   => self::inList((string)($raw['text_length'] ?? 'moyen'), self::TEXT_LENGTHS, 'moyen'),
            'tone'          => self::inList((string)($raw['tone'] ?? 'professionnel'), self::TONES, 'professionnel'),
            'image_style'   => $imageStyle,
            'image_tool_id' => $toolId > 0 ? $toolId : 0,
        ];
    }

    /** Plan : titres + points, sans géométrie (la mise en page vient après). */
    public static function normalizeOutline(array $raw): array
    {
        $outline = [];
        foreach (array_slice($raw, 0, self::MAX_SLIDES) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $points = [];
            $rawPoints = is_array($item['points'] ?? null) ? $item['points'] : [];
            foreach (array_slice($rawPoints, 0, self::MAX_BULLETS) as $point) {
                $clean = self::text($point, self::MAX_BULLET_LEN);
                if ($clean !== '') {
                    $points[] = $clean;
                }
            }

            $outline[] = [
                'id'           => self::id($item['id'] ?? null, 's' . ((int)$i + 1)),
                'title'        => self::text($item['title'] ?? '', self::MAX_TITLE_LEN) ?: 'Sans titre',
                'points'       => $points,
                'layout'       => self::inList((string)($item['layout'] ?? ''), self::LAYOUTS, 'titre-contenu'),
                'image_prompt' => self::text($item['image_prompt'] ?? '', self::MAX_TEXT_LEN),
            ];
        }

        return $outline;
    }

    /** Une slide : identité, mise en page, notes, éléments positionnés. */
    public static function normalizeSlide(array $raw, int $index = 0): array
    {
        $elements  = [];
        $rawItems  = is_array($raw['elements'] ?? null) ? $raw['elements'] : [];
        foreach (array_slice($rawItems, 0, self::MAX_ELEMENTS_PER_SLIDE) as $j => $rawEl) {
            if (!is_array($rawEl)) {
                continue;
            }
            $el = self::normalizeElement($rawEl, 'e' . ($j + 1));
            if ($el !== null) {
                $elements[] = $el;
            }
        }

        return [
            'id'       => self::id($raw['id'] ?? null, 's' . ($index + 1)),
            'layout'   => self::inList((string)($raw['layout'] ?? ''), [...self::LAYOUTS, ...self::EXTRA_LAYOUTS], 'titre-contenu'),
            'notes'    => self::text($raw['notes'] ?? '', self::MAX_NOTES_LEN),
            'elements' => $elements,
        ];
    }

    /**
     * Un élément. Renvoie null si l'élément est inexploitable (type inconnu,
     * image hors périmètre, contenu vide) — mieux vaut le retirer que rendre
     * une boîte fantôme sur la slide.
     */
    public static function normalizeElement(array $raw, string $fallbackId = 'e1'): ?array
    {
        $type = (string)($raw['type'] ?? '');
        if (!in_array($type, self::ELEMENT_TYPES, true)) {
            return null;
        }

        // Géométrie bornée au canvas : un élément traîné hors cadre revient dedans.
        // Le minimum est volontairement très bas (0,2 %) : les filets et barres
        // d'accent font moins de 1 % de haut et seraient sinon épaissis.
        $x = self::clamp((float)($raw['x'] ?? 0), 0.0, 99.0);
        $y = self::clamp((float)($raw['y'] ?? 0), 0.0, 99.0);
        $w = self::clamp((float)($raw['w'] ?? 50), self::MIN_SIZE, 100.0 - $x);
        $h = self::clamp((float)($raw['h'] ?? 20), self::MIN_SIZE, 100.0 - $y);

        $el = [
            'id'   => self::id($raw['id'] ?? null, $fallbackId),
            'type' => $type,
            'x'    => round($x, 2),
            'y'    => round($y, 2),
            'w'    => round($w, 2),
            'h'    => round($h, 2),
        ];

        switch ($type) {
            case 'text':
                $text = self::text($raw['text'] ?? '', self::MAX_TEXT_LEN);
                if ($text === '') {
                    return null;
                }
                $el['text']   = $text;
                $el['role']   = self::inList((string)($raw['role'] ?? 'corps'), self::ROLES, 'corps');
                $el['align']  = self::inList((string)($raw['align'] ?? 'left'), self::ALIGNS, 'left');
                $el['size']   = self::inList((string)($raw['size'] ?? 'm'), self::SIZES, 'm');
                $el['weight'] = (int)self::clamp((float)($raw['weight'] ?? 400), 300, 900);
                self::applyTypography($el, $raw);
                break;

            case 'bullets':
                $items = [];
                $rawItems = is_array($raw['items'] ?? null) ? $raw['items'] : [];
                foreach (array_slice($rawItems, 0, self::MAX_BULLETS) as $item) {
                    $clean = self::text($item, self::MAX_BULLET_LEN);
                    if ($clean !== '') {
                        $items[] = $clean;
                    }
                }
                if ($items === []) {
                    return null;
                }
                $el['items']  = $items;
                $el['align']  = self::inList((string)($raw['align'] ?? 'left'), self::ALIGNS, 'left');
                $el['size']   = self::inList((string)($raw['size'] ?? 'm'), self::SIZES, 'm');
                $el['weight'] = (int)self::clamp((float)($raw['weight'] ?? 400), 300, 900);
                // Couleur des puces : '' = l'accent du thème.
                $el['marker'] = self::namedColor($raw['marker'] ?? null, self::TEXT_TOKENS);
                self::applyTypography($el, $raw);
                break;

            case 'image':
                // Le prompt survit même sans URL : l'image peut être générée plus tard.
                $el['prompt'] = self::text($raw['prompt'] ?? '', self::MAX_TEXT_LEN);
                $el['url']    = self::imageUrl($raw['url'] ?? null);
                $el['fit']    = self::inList((string)($raw['fit'] ?? 'cover'), self::FITS, 'cover');
                // Ancrage d'une image entière (`contain`) dans sa boîte : un
                // logo de pied de page se colle à gauche, sur le texte.
                $el['anchor'] = self::inList((string)($raw['anchor'] ?? 'center'), ['center', 'left'], 'center');
                if ($el['url'] === '' && $el['prompt'] === '') {
                    return null;
                }
                break;

            case 'shape':
                $el['shape']   = self::inList((string)($raw['shape'] ?? 'rect'), self::SHAPES, 'rect');
                $el['fill']    = self::fill($raw['fill'] ?? 'accent');
                $el['opacity'] = round(self::clamp((float)($raw['opacity'] ?? 1), 0.05, 1.0), 2);
                break;

            case 'table':
                // Lignes de cellules TEXTE, toutes de la même largeur : la
                // première est l'en-tête quand `header` est vrai.
                $rows = [];
                foreach (array_slice(is_array($raw['rows'] ?? null) ? $raw['rows'] : [], 0, self::MAX_TABLE_ROWS) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cells = [];
                    foreach (array_slice(array_values($row), 0, self::MAX_TABLE_COLS) as $cell) {
                        $cells[] = self::text($cell, self::MAX_CELL_LEN);
                    }
                    if (implode('', $cells) !== '') {
                        $rows[] = $cells;
                    }
                }
                if ($rows === []) {
                    return null;
                }
                $width = max(array_map('count', $rows));
                foreach ($rows as $k => $cells) {
                    $rows[$k] = array_pad($cells, $width, '');
                }
                $el['rows']   = $rows;
                $el['header'] = !array_key_exists('header', $raw) || !empty($raw['header']);
                $el['size']   = self::inList((string)($raw['size'] ?? 's'), self::SIZES, 's');
                // Fond de l'en-tête et des lignes alternées : '' = règles par
                // défaut (accent, teinte légère de l'accent).
                $el['head']   = self::namedColor($raw['head'] ?? null, self::FILL_TOKENS);
                $el['band']   = self::namedColor($raw['band'] ?? null, self::FILL_TOKENS);
                break;
        }

        return $el;
    }

    /**
     * Mise en forme applicable aux textes et aux puces, posée par l'éditeur.
     *
     * Les trois attributs sont facultatifs : vides, l'élément suit le thème et
     * les règles de lisibilité du layout (texte blanc sur image pleine page,
     * contraste calculé sur l'aplat d'accent). Une couleur explicite prend le
     * pas dessus — c'est un choix de l'utilisateur, pas une valeur par défaut.
     */
    private static function applyTypography(array &$el, array $raw): void
    {
        // Une couleur #rrggbb (choix de l'éditeur) ou une couleur nommée posée
        // par la mise en page (texte sur fond sombre…).
        $el['color']  = self::namedColor($raw['color'] ?? null, self::TEXT_TOKENS);
        $el['italic'] = !empty($raw['italic']);
        $el['font']   = isset(self::FONTS[(string)($raw['font'] ?? '')]) ? (string)($raw['font'] ?? '') : '';
    }

    /** Couleur #rrggbb, ou nom de la liste donnée ; '' sinon. */
    private static function namedColor(mixed $value, array $tokens): string
    {
        $value = trim((string)($value ?? ''));
        if (in_array($value, $tokens, true)) {
            return $value;
        }

        return self::hexColor($value) ?? '';
    }

    /** Couleur #rrggbb normalisée en minuscules, ou null si ce n'en est pas une. */
    private static function hexColor(mixed $value): ?string
    {
        $hex = trim((string)($value ?? ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? strtolower($hex) : null;
    }

    /**
     * Résumé court d'un deck — sert à la fois de libellé de carte et de
     * substitut dans l'historique LLM (la spec complète y coûterait des
     * milliers de tokens à chaque message suivant, pour rien).
     */
    public static function summary(array $deck): string
    {
        $title = self::text($deck['title'] ?? '', self::MAX_TITLE_LEN) ?: 'Présentation';
        $count = count(is_array($deck['slides'] ?? null) ? $deck['slides'] : []);

        return $count > 0
            ? sprintf('%s (%d slide%s)', $title, $count, $count > 1 ? 's' : '')
            : $title;
    }

    /** Nombre de slides demandé, borné. */
    public static function slideCount(mixed $value): int
    {
        return (int)self::clamp((float)(int)$value, self::MIN_SLIDES, self::MAX_SLIDES);
    }

    /** Décodage d'une spec persistée. Renvoie null si le contenu n'est pas un deck. */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['slides'] ?? null)) {
            return null;
        }

        return self::normalize($decoded);
    }

    public static function encode(array $deck): string
    {
        return (string)json_encode($deck, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Texte propre : caractères de contrôle retirés, espaces normalisés,
     * longueur bornée. Le rendu échappe ensuite — ici on assainit la donnée.
     */
    private static function text(mixed $value, int $maxLen): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        $text = (string)$value;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = trim($text);

        return mb_substr($text, 0, $maxLen);
    }

    /** Identifiant stable : indispensable au drag et aux régénérations ciblées. */
    private static function id(mixed $value, string $fallback): string
    {
        $id = is_string($value) ? preg_replace('/[^A-Za-z0-9_-]/', '', $value) : '';

        return ($id !== null && $id !== '') ? mb_substr($id, 0, 40) : $fallback;
    }

    private static function inList(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        if (is_nan($value)) {
            return $min;
        }

        return max($min, min($max, $value));
    }

    /** Couleur hexadécimale stricte, sinon la valeur du thème. */
    private static function color(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1) {
            return strtolower(trim($value));
        }

        return $fallback;
    }

    /** Remplissage d'une forme : jeton de thème ou couleur hexadécimale. */
    private static function fill(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::FILL_TOKENS, true)) {
            return $value;
        }

        return self::color($value, 'accent');
    }

    /**
     * URL d'image : uniquement des fichiers servis par l'app
     * (`/assets/img/...`). Interdit toute URL externe — une spec forgée ne peut
     * donc pas faire charger de ressource tierce dans l'aperçu ni exfiltrer via
     * une requête d'image.
     */
    private static function imageUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $url = trim($value);
        if ($url === '' || !str_starts_with($url, self::IMAGE_URL_PREFIX)) {
            return '';
        }
        // Pas de remontée de répertoire, pas de query/fragment.
        if (str_contains($url, '..') || str_contains($url, '?') || str_contains($url, '#')) {
            return '';
        }

        return mb_substr($url, 0, 300);
    }
}
