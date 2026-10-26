<?php

declare(strict_types=1);

/**
 * Mise en page des slides : (layout + contenu) → éléments positionnés.
 *
 * La géométrie n'est JAMAIS demandée au LLM. Il choisit un layout dans une
 * liste fermée et fournit du contenu ; c'est ce moteur qui pose les
 * coordonnées. Conséquence directe : quelle que soit la qualité de la sortie
 * du modèle, les slides restent bien composées et alignées entre elles.
 *
 * Repère : canvas 16:9 en pourcentages, origine en haut à gauche.
 * Marge latérale de référence : 8 %.
 */
final class LayoutEngine
{
    /** Marges de la zone utile. */
    private const M_X = 8.0;   // marge gauche/droite
    private const M_TOP = 11.0; // hauteur réservée au titre

    /**
     * Deux styles de mise en page, mêmes données. « classique » : fond du
     * thème, filets d'accent — celui de l'outil Présentation et de ses
     * thèmes. « aplats » : couverture et conclusion sur fond sombre, points
     * en cartes, chiffres sur un bandeau teinté, étapes en pastilles — celui
     * des présentations livrées dans la conversation. Toutes ses couleurs
     * sont des teintes NOMMÉES, dérivées de l'accent (DeckSpec::derivedColors) :
     * la présentation prend la couleur de l'entreprise sans rien recalculer ici.
     */
    public const STYLE_CLASSIC = 'classique';
    public const STYLE_APLATS  = 'aplats';

    /**
     * Construit une slide complète à partir d'une entrée du plan.
     *
     * @param array $item   entrée d'outline normalisée (title, points, layout, image_prompt)
     * @param int   $index  index 0-based de la slide
     * @param int   $total  nombre total de slides (pour le pied de page)
     * @param bool  $withImages  false = aucune image ne sera générée (les layouts
     *                           visuels retombent alors sur leur équivalent texte)
     * @param array{logo?: string, name?: string} $brand  marque de l'entreprise
     *                           (logo sous /assets/img/, nom) : couverture et pied
     *                           des slides de contenu. Vide = aucune (outil
     *                           Présentation).
     */
    public static function build(
        array $item,
        int $index,
        int $total,
        bool $withImages,
        array $brand = [],
        string $style = self::STYLE_CLASSIC
    ): array {
        $title  = (string)($item['title'] ?? '');
        $points = is_array($item['points'] ?? null) ? $item['points'] : [];
        $prompt = (string)($item['image_prompt'] ?? '');
        $layout = self::layoutOf($item, $index, $withImages);
        $aplats = $style === self::STYLE_APLATS;

        $elements = $aplats ? self::aplats($layout, $item, $title, $points, $prompt, $index) : match ($layout) {
            'chiffres'     => self::layoutChiffres($title, (array)$item['kpis']),
            'tableau'      => self::layoutTableau($title, (array)$item['table'], (string)($item['intro'] ?? '')),
            'comparaison'  => self::layoutComparaison($title, (array)$item['columns']),
            'etapes'       => self::layoutEtapes($title, (array)$item['steps']),
            'chronologie'  => self::layoutChronologie($title, (array)$item['events']),
            'titre'        => self::layoutTitre($title, $points),
            'section'      => self::layoutSection($title, $index),
            'citation'     => self::layoutCitation($title, $points),
            'image-pleine' => self::layoutImagePleine($title, $prompt),
            'image-droite' => self::layoutImageDroite($title, $points, $prompt),
            'deux-colonnes' => self::layoutDeuxColonnes($title, $points),
            'conclusion'   => self::layoutConclusion($title, $points),
            default        => self::layoutTitreContenu($title, $points),
        };

        // Numéro de slide : discret, absent des slides « pleine page ».
        if (!in_array($layout, ['titre', 'section', 'image-pleine'], true)) {
            $elements[] = self::text(
                'num',
                (string)($index + 1) . ' / ' . $total,
                88.0, 91.0, 8.0, 5.0,
                ['role' => 'numero', 'size' => 'xs', 'align' => 'right', 'weight' => 400]
                    + ($aplats && $layout === 'conclusion' ? ['color' => 'on-deep-muted'] : [])
            );
        }

        array_push($elements, ...($aplats ? self::brandAplats($layout, $brand) : self::brandElements($layout, $brand)));

        return DeckSpec::normalizeSlide([
            'id'       => $item['id'] ?? ('s' . ($index + 1)),
            'layout'   => $layout,
            'notes'    => $item['notes'] ?? '',
            'elements' => $elements,
        ], $index);
    }

    /**
     * Mise en page RÉELLE d'une entrée du plan, celle que build() posera :
     * données vérifiées, rattrapages compris. Publique : la présentation livrée
     * dans la conversation numérote ses parties (intercalaires) avant de les
     * mettre en page.
     */
    public static function layoutOf(array $item, int $index, bool $withImages): string
    {
        $points   = is_array($item['points'] ?? null) ? $item['points'] : [];
        $hasImage = $withImages && (string)($item['image_prompt'] ?? '') !== '';

        return self::resolveLayout(
            self::resolveExtra((string)($item['layout'] ?? 'titre-contenu'), $item),
            $hasImage,
            $points,
            $index
        );
    }

    /**
     * Mise en page des présentations livrées dans la conversation : elle n'a
     * de sens qu'avec ses DONNÉES (valeurs, tableau, colonnes, étapes, dates).
     * Sans elles, retour au cas courant — jamais une slide vide.
     */
    private static function resolveExtra(string $layout, array $item): string
    {
        $needs = [
            'chiffres'    => ['kpis', 2],
            'tableau'     => ['table', 1],
            'comparaison' => ['columns', 2],
            'etapes'      => ['steps', 2],
            'chronologie' => ['events', 2],
            'cartes'      => ['cards', 2],
        ];
        if (!isset($needs[$layout])) {
            return $layout;
        }
        [$key, $min] = $needs[$layout];
        $data = $item[$key] ?? null;
        if ($layout === 'tableau') {
            return is_array($data) && !empty($data['rows']) ? $layout : 'titre-contenu';
        }

        return is_array($data) && count($data) >= $min ? $layout : 'titre-contenu';
    }

    /**
     * Ajuste le layout demandé à la réalité du contenu : un layout visuel sans
     * image, ou un layout à colonnes sans assez de points, produirait une slide
     * bancale. On retombe alors sur l'équivalent le plus proche.
     */
    private static function resolveLayout(string $layout, bool $hasImage, array $points, int $index): string
    {
        if (in_array($layout, DeckSpec::EXTRA_LAYOUTS, true)) {
            return $layout;   // données vérifiées par resolveExtra
        }
        if (!in_array($layout, DeckSpec::LAYOUTS, true)) {
            $layout = 'titre-contenu';
        }

        if (!$hasImage && in_array($layout, ['image-droite', 'image-pleine'], true)) {
            return $points === [] ? ($index === 0 ? 'titre' : 'section') : 'titre-contenu';
        }

        // Symétrique : une slide porteuse d'un prompt d'image sur un layout sans
        // emplacement visuel perdrait son illustration en silence. Le modèle
        // désobéit régulièrement à cette consigne — on le rattrape ici.
        if ($hasImage && !in_array($layout, ['image-droite', 'image-pleine'], true)) {
            return $points === [] ? 'image-pleine' : 'image-droite';
        }

        if ($layout === 'deux-colonnes' && count($points) < 4) {
            return 'titre-contenu';
        }

        if (in_array($layout, ['titre-contenu', 'conclusion'], true) && $points === []) {
            return $index === 0 ? 'titre' : 'section';
        }

        return $layout;
    }

    // ------------------------------------------------------------- layouts

    /** Couverture : filet d'accent, titre fort, sous-titre optionnel. */
    private static function layoutTitre(string $title, array $points): array
    {
        $els = [
            self::shape('bar', 0, 0, 100, 1.6, 'accent'),
            self::text('titre', $title, self::M_X, 34, 84, 24, [
                'role' => 'titre', 'size' => 'xxl', 'weight' => 700,
            ]),
        ];

        $subtitle = trim((string)($points[0] ?? ''));
        if ($subtitle !== '') {
            $els[] = self::text('sous', $subtitle, self::M_X, 60, 70, 12, [
                'role' => 'sous-titre', 'size' => 'l', 'weight' => 400,
            ]);
        }

        return $els;
    }

    /** Intercalaire de partie : aplat d'accent plein cadre, numéro et titre. */
    private static function layoutSection(string $title, int $index): array
    {
        return [
            self::shape('fond', 0, 0, 100, 100, 'accent'),
            self::text('num', str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT), self::M_X, 28, 20, 12, [
                'role' => 'numero', 'size' => 'xl', 'weight' => 700,
            ]),
            self::text('titre', $title, self::M_X, 42, 84, 22, [
                'role' => 'titre', 'size' => 'xxl', 'weight' => 700,
            ]),
        ];
    }

    /** Citation : filet vertical d'accent, texte large, attribution en dessous. */
    private static function layoutCitation(string $title, array $points): array
    {
        $els = [
            self::shape('filet', self::M_X, 26, 0.7, 42, 'accent'),
            self::text('titre', $title, 13, 28, 79, 34, [
                'role' => 'citation', 'size' => 'xl', 'weight' => 400,
            ]),
        ];

        $author = trim((string)($points[0] ?? ''));
        if ($author !== '') {
            $els[] = self::text('sous', $author, 13, 66, 60, 8, [
                'role' => 'legende', 'size' => 's', 'weight' => 400,
            ]);
        }

        return $els;
    }

    /** Visuel plein cadre, voile de lisibilité, titre en surimpression basse. */
    private static function layoutImagePleine(string $title, string $prompt): array
    {
        return [
            self::image('img', 0, 0, 100, 100, $prompt, 'cover'),
            // Voile noir explicite, jamais `text` : sur un thème sombre la
            // couleur de texte est claire, et le voile éclaircirait l'image
            // sous un titre blanc — illisible.
            self::shape('voile', 0, 0, 100, 100, '#000000', 0.45),
            self::text('titre', $title, self::M_X, 60, 84, 24, [
                'role' => 'titre', 'size' => 'xxl', 'weight' => 700,
            ]),
        ];
    }

    /** Puces à gauche, visuel à droite sur toute la hauteur utile. */
    private static function layoutImageDroite(string $title, array $points, string $prompt): array
    {
        return [
            self::text('titre', $title, self::M_X, self::M_TOP, 50, 13, [
                'role' => 'titre', 'size' => 'l', 'weight' => 700,
            ]),
            self::shape('filet', self::M_X, 26.5, 6, 0.7, 'accent'),
            self::bullets('pts', $points, self::M_X, 32, 48, 56),
            self::image('img', 62, self::M_TOP, 30, 78, $prompt, 'cover'),
        ];
    }

    /** Deux colonnes de puces : les points sont répartis en deux moitiés. */
    private static function layoutDeuxColonnes(string $title, array $points): array
    {
        $half  = (int)ceil(count($points) / 2);
        $left  = array_slice($points, 0, $half);
        $right = array_slice($points, $half);

        return [
            self::text('titre', $title, self::M_X, self::M_TOP, 84, 13, [
                'role' => 'titre', 'size' => 'l', 'weight' => 700,
            ]),
            self::shape('filet', self::M_X, 26.5, 6, 0.7, 'accent'),
            self::bullets('ptsA', $left, self::M_X, 32, 40, 58),
            self::bullets('ptsB', $right, 52, 32, 40, 58),
        ];
    }

    /** Synthèse : même grammaire que titre-contenu, avec un filet de clôture. */
    private static function layoutConclusion(string $title, array $points): array
    {
        return [
            self::text('titre', $title, self::M_X, self::M_TOP, 84, 13, [
                'role' => 'titre', 'size' => 'l', 'weight' => 700,
            ]),
            self::shape('filet', self::M_X, 26.5, 6, 0.7, 'accent'),
            self::bullets('pts', $points, self::M_X, 32, 84, 50),
            self::shape('clot', self::M_X, 86, 16, 0.9, 'accent'),
        ];
    }

    /** Cas courant : titre, filet d'accent, puces sur toute la largeur. */
    private static function layoutTitreContenu(string $title, array $points): array
    {
        return [
            self::text('titre', $title, self::M_X, self::M_TOP, 84, 13, [
                'role' => 'titre', 'size' => 'l', 'weight' => 700,
            ]),
            self::shape('filet', self::M_X, 26.5, 6, 0.7, 'accent'),
            self::bullets('pts', $points, self::M_X, 32, 84, 56),
        ];
    }

    /** Titre et filet d'accent, communs aux mises en page de contenu. */
    private static function header(string $title): array
    {
        return [
            self::text('titre', $title, self::M_X, self::M_TOP, 84, 13, [
                'role' => 'titre', 'size' => 'l', 'weight' => 700,
            ]),
            self::shape('filet', self::M_X, 26.5, 6, 0.7, 'accent'),
        ];
    }

    /**
     * Chiffres clés : grandes valeurs en couleur d'accent, légende dessous.
     * Une rangée jusqu'à quatre, deux rangées de trois au-delà.
     *
     * @param list<array{value?: string, label?: string}> $kpis
     */
    private static function layoutChiffres(string $title, array $kpis): array
    {
        $kpis = array_slice(array_values($kpis), 0, 6);
        $n    = count($kpis);
        $cols = $n <= 4 ? $n : 3;
        $rows = (int)ceil($n / $cols);
        $gap  = 3.0;
        $w    = (84 - $gap * ($cols - 1)) / $cols;
        $rowH = $rows === 1 ? 44.0 : 26.0;
        $top  = $rows === 1 ? 38.0 : 33.0;

        $els = self::header($title);
        foreach ($kpis as $k => $kpi) {
            $x = self::M_X + ($k % $cols) * ($w + $gap);
            $y = $top + intdiv($k, $cols) * ($rowH + 2);
            $els[] = self::text('val' . ($k + 1), (string)($kpi['value'] ?? ''), $x, $y, $w, $rows === 1 ? 20 : 13, [
                'role' => 'chiffre', 'size' => $rows === 1 ? 'xxl' : 'xl', 'weight' => 700, 'align' => 'center',
            ]);
            $els[] = self::text('lib' . ($k + 1), (string)($kpi['label'] ?? ''), $x, $y + ($rows === 1 ? 21 : 13.5), $w, $rows === 1 ? 16 : 11, [
                'role' => 'legende', 'size' => 's', 'align' => 'center',
            ]);
        }

        return $els;
    }

    /**
     * Un vrai tableau, sous le titre, précédé d'une phrase d'introduction le
     * cas échéant. Hauteur proportionnelle au nombre de lignes.
     *
     * @param array{rows?: list<list<string>>, header?: bool} $table
     */
    private static function layoutTableau(string $title, array $table, string $intro): array
    {
        $rows = array_values((array)($table['rows'] ?? []));
        $els  = self::header($title);
        $top  = 31.0;
        if (trim($intro) !== '') {
            $els[] = self::text('intro', $intro, self::M_X, 30, 84, 7, ['role' => 'corps', 'size' => 's']);
            $top = 38.5;
        }
        $h = min(88 - $top, max(12.0, count($rows) * 6.5));

        $els[] = [
            'id' => 'tab', 'type' => 'table',
            'x' => self::M_X, 'y' => $top, 'w' => 84, 'h' => $h,
            'rows' => $rows, 'header' => ($table['header'] ?? true) !== false,
            'size' => count($rows) > 8 ? 'xs' : 's',
        ];

        return $els;
    }

    /**
     * Deux colonnes TITRÉES (avant / après, option A / option B) : un titre
     * de colonne souligné d'accent, ses puces dessous.
     *
     * @param list<array{title?: string, points?: list<string>}> $columns
     */
    private static function layoutComparaison(string $title, array $columns): array
    {
        $els = self::header($title);
        foreach (array_slice(array_values($columns), 0, 2) as $k => $col) {
            $x = $k === 0 ? self::M_X : 52.0;
            $els[] = self::text('col' . ($k + 1), (string)($col['title'] ?? ''), $x, 31, 40, 8, [
                'role' => 'corps', 'size' => 'm', 'weight' => 700,
            ]);
            $els[] = self::shape('trait' . ($k + 1), $x, 39.5, 40, 0.5, 'accent');
            $points = array_values((array)($col['points'] ?? []));
            if ($points !== []) {
                $els[] = self::bullets('pts' . ($k + 1), $points, $x, 43, 40, 45);
            }
        }

        return $els;
    }

    /**
     * Étapes numérotées, de gauche à droite : le numéro en couleur
     * d'accent, un trait, le texte de l'étape.
     *
     * @param list<string> $steps
     */
    private static function layoutEtapes(string $title, array $steps): array
    {
        $steps = array_slice(array_values($steps), 0, 6);
        $n     = count($steps);
        $gap   = 2.5;
        $w     = (84 - $gap * ($n - 1)) / $n;

        $els = self::header($title);
        foreach ($steps as $k => $step) {
            $x = self::M_X + $k * ($w + $gap);
            $els[] = self::text('num' . ($k + 1), str_pad((string)($k + 1), 2, '0', STR_PAD_LEFT), $x, 34, $w, 12, [
                'role' => 'chiffre', 'size' => 'xl', 'weight' => 700,
            ]);
            $els[] = self::shape('trait' . ($k + 1), $x, 47, min($w, 10), 0.7, 'accent');
            $els[] = self::text('etape' . ($k + 1), (string)$step, $x, 51, $w, 36, [
                'role' => 'corps', 'size' => $n > 4 ? 's' : 'm',
            ]);
        }

        return $els;
    }

    /**
     * Chronologie : une ligne de temps, un repère par date, la date au-dessus,
     * l'évènement dessous.
     *
     * @param list<array{date?: string, label?: string}> $events
     */
    private static function layoutChronologie(string $title, array $events): array
    {
        $events = array_slice(array_values($events), 0, 6);
        $n      = count($events);
        $w      = 84 / $n;

        $els   = self::header($title);
        $els[] = self::shape('ligne', self::M_X, 52, 84, 0.4, 'muted', 0.5);
        foreach ($events as $k => $event) {
            $x = self::M_X + $k * $w;
            $els[] = self::text('date' . ($k + 1), (string)($event['date'] ?? ''), $x, 40, $w - 2, 9, [
                'role' => 'chiffre', 'size' => 'm', 'weight' => 700,
            ]);
            $els[] = self::shape('repere' . ($k + 1), $x, 50.6, 1.4, 3.2, 'accent');
            $els[] = self::text('evt' . ($k + 1), (string)($event['label'] ?? ''), $x, 57, $w - 2, 30, [
                'role' => 'corps', 'size' => 's',
            ]);
        }

        return $els;
    }

    // --------------------------------------------------- style « aplats »

    /** Une slide en style « aplats ». Les visuels (jamais livrés dans la conversation) restent classiques. */
    private static function aplats(string $layout, array $item, string $title, array $points, string $prompt, int $index): array
    {
        return match ($layout) {
            'titre'         => self::aplatsCouverture($title, $points),
            'section'       => self::aplatsSection($title, (int)($item['part'] ?? ($index + 1))),
            'citation'      => self::aplatsCitation($title, $points),
            'image-pleine'  => self::layoutImagePleine($title, $prompt),
            'image-droite'  => self::layoutImageDroite($title, $points, $prompt),
            'deux-colonnes' => self::aplatsPastilles($title, $points),
            'conclusion'    => self::aplatsConclusion($title, $points),
            'chiffres'      => self::aplatsChiffres($title, (array)$item['kpis']),
            'tableau'       => self::aplatsTableau($title, (array)$item['table'], (string)($item['intro'] ?? '')),
            'comparaison'   => self::aplatsComparaison($title, (array)$item['columns']),
            'etapes'        => self::aplatsEtapes($title, (array)$item['steps']),
            'chronologie'   => self::aplatsChronologie($title, (array)$item['events']),
            'cartes'        => self::aplatsCartes($title, (array)$item['cards']),
            default         => self::aplatsContenu($title, $points),
        };
    }

    /** Petit trait d'accent, puis le titre de la slide. */
    private static function aplatsHeader(string $title): array
    {
        return [
            self::shape('bar', self::M_X, 8.3, 4.4, 1.1, 'accent'),
            self::text('titre', $title, self::M_X, 12, 84, 13, ['role' => 'titre', 'size' => 'l', 'weight' => 700]),
        ];
    }

    /** Couverture : fond sombre, grand disque d'accent, titre blanc centré verticalement. */
    private static function aplatsCouverture(string $title, array $points): array
    {
        $subtitle = trim((string)($points[0] ?? ''));
        $f        = DeckRenderer::fontSizeCqw('xxl', $title, 52, 30);
        $titleH   = min(30.0, self::lines($title, 52, $f, true) * self::lineH($f));
        $subF     = self::cqw('m');
        $subH     = $subtitle !== '' ? min(14.0, self::lines($subtitle, 50, $subF) * self::lineH($subF)) : 0.0;
        $blockH   = $titleH + ($subtitle !== '' ? 4 + $subH : 0);
        $top      = max(26.0, 52 - $blockH / 2);

        $els = [
            self::shape('fond', 0, 0, 100, 100, 'deep'),
            self::shape('disque', 62, 39.5, 34, 60.4, 'accent', 1.0, 'circle'),
            self::shape('halo', 52, 8, 16, 28.4, 'on-deep', 0.08, 'circle'),
            self::shape('bar', self::M_X, $top - 4, 6.9, 1.1, 'accent'),
            self::text('titre', $title, self::M_X, $top, 52, $titleH + 1, [
                'role' => 'titre', 'size' => 'xxl', 'weight' => 700, 'color' => 'on-deep',
            ]),
        ];
        if ($subtitle !== '') {
            $els[] = self::text('sous', $subtitle, self::M_X, $top + $titleH + 4, 50, $subH + 1, [
                'role' => 'sous-titre', 'size' => 'm', 'color' => 'on-deep-muted',
            ]);
        }

        return $els;
    }

    /** Intercalaire : aplat d'accent, grand numéro de partie en filigrane, titre. */
    private static function aplatsSection(string $title, int $part): array
    {
        return [
            self::shape('fond', 0, 0, 100, 100, 'accent'),
            self::shape('halo', 72, 6, 24, 42.7, 'on-accent', 0.12, 'circle'),
            self::text('num', str_pad((string)max(1, $part), 2, '0', STR_PAD_LEFT), 6.5, 9, 50, 45, [
                'role' => 'chiffre', 'size' => 'xxxl', 'weight' => 700, 'color' => 'on-accent-faint',
            ]),
            self::text('titre', $title, self::M_X, 55, 80, 18, [
                'role' => 'titre', 'size' => 'xxl', 'weight' => 700, 'color' => 'on-accent',
            ]),
            self::shape('trait', self::M_X, 75, 10, 1.1, 'on-accent'),
        ];
    }

    /**
     * Cas courant : le titre dans un panneau teinté à gauche, les puces à
     * droite, centrées en hauteur. Trop de texte pour la colonne : titre en
     * haut et puces sur toute la largeur, au cran qui tient.
     */
    private static function aplatsContenu(string $title, array $points): array
    {
        $size = self::bulletsSize($points, 51, 76, ['m', 's']);
        if ($size !== null) {
            $height = self::bulletsHeight($points, 51, $size);
            $top    = max(11.0, (100 - $height) / 2 - 2);

            return [
                self::shape('panneau', 0, 0, 37, 100, 'tint'),
                self::shape('bar', 6.25, 8.3, 4.4, 1.1, 'accent'),
                self::text('titre', $title, 6.25, 12.5, 28, 50, ['role' => 'titre', 'size' => 'l', 'weight' => 700]),
                self::bullets('pts', $points, 42, $top, 51, min(76.0, 88 - $top), ['size' => $size]),
            ];
        }

        return [
            ...self::aplatsHeader($title),
            self::bullets('pts', $points, self::M_X, 29, 84, 59, [
                'size' => self::bulletsSize($points, 84, 59, ['m', 's', 'xs']) ?? 'xs',
            ]),
        ];
    }

    /** Synthèse finale : fond sombre, disque d'accent, puces claires. */
    private static function aplatsConclusion(string $title, array $points): array
    {
        return [
            self::shape('fond', 0, 0, 100, 100, 'deep'),
            self::shape('disque', 76, 6, 20, 35.6, 'accent', 0.9, 'circle'),
            self::shape('bar', self::M_X, 8.3, 4.4, 1.1, 'accent'),
            self::text('titre', $title, self::M_X, 12, 62, 14, [
                'role' => 'titre', 'size' => 'xl', 'weight' => 700, 'color' => 'on-deep',
            ]),
            self::bullets('pts', $points, self::M_X, 31, 62, 55, [
                'size'   => self::bulletsSize($points, 62, 55, ['m', 's', 'xs']) ?? 'xs',
                'color'  => 'on-deep',
                'marker' => 'on-deep-accent',
            ]),
        ];
    }

    /** Beaucoup de puces courtes : des pastilles teintées, sur deux colonnes. */
    private static function aplatsPastilles(string $title, array $points): array
    {
        $points = array_slice(array_values($points), 0, 10);
        $rows   = max(1, (int)ceil(count($points) / 2));
        $gapY   = 2.8;
        $h      = min(11.0, (59 - ($rows - 1) * $gapY) / $rows);
        $w      = (84 - 2.5) / 2;
        $f      = self::cqw('s');

        $els = self::aplatsHeader($title);
        foreach ($points as $k => $point) {
            $x  = self::M_X + ($k % 2) * ($w + 2.5);
            $y  = 29 + intdiv($k, 2) * ($h + $gapY);
            $tw = $w - 6.4;
            $th = min(2, self::lines((string)$point, $tw, $f)) * self::lineH($f);
            $els[] = self::shape('pastille' . ($k + 1), $x, $y, $w, $h, 'tint', 1.0, 'round');
            $els[] = self::shape('point' . ($k + 1), $x + 2.2, $y + $h / 2 - 0.98, 1.1, 1.96, 'accent', 1.0, 'circle');
            $els[] = self::text('txt' . ($k + 1), (string)$point, $x + 4.6, $y + ($h - $th) / 2, $tw, $th + 0.6, ['size' => 's']);
        }

        return $els;
    }

    /**
     * Chiffres clés sur un bandeau teinté : grandes valeurs en accent lisible,
     * légende dessous, filets entre les colonnes. Une rangée jusqu'à quatre,
     * deux rangées de trois au-delà.
     *
     * @param list<array{value?: string, label?: string}> $kpis
     */
    private static function aplatsChiffres(string $title, array $kpis): array
    {
        $kpis   = array_slice(array_values($kpis), 0, 6);
        $n      = count($kpis);
        $cols   = $n <= 4 ? $n : 3;
        $rows   = (int)ceil($n / $cols);
        $panelY = $rows === 1 ? 31.0 : 28.0;
        $panelH = $rows === 1 ? 42.0 : 60.0;
        $colW   = 84 / $cols;
        $rowH   = $panelH / $rows;
        $values = array_map(static fn($kpi) => trim((string)($kpi['value'] ?? '')), $kpis);
        $size   = self::commonSize($values, $colW - 5, $rows === 1 ? ['xxl', 'xl', 'l'] : ['xl', 'l', 'm'], true);
        $fits   = array_filter($values, static fn($v) => !self::fitsAt($v, $colW - 5, $size, true)) === [];

        $els   = self::aplatsHeader($title);
        $els[] = self::shape('bandeau', self::M_X, $panelY, 84, $panelH, 'tint', 1.0, 'round');
        foreach ($kpis as $k => $kpi) {
            $c = $k % $cols;
            $x = self::M_X + $c * $colW;
            $y = $panelY + intdiv($k, $cols) * $rowH;
            if ($c > 0) {
                $els[] = self::shape('filet' . ($k + 1), $x, $y + $rowH * 0.18, 0.16, $rowH * 0.64, 'soft');
            }
            // Une valeur qui tient ne se coupe jamais : « 2 400 », « 4 sem. »
            // gardent leurs espaces insécables dans les trois rendus.
            $value = $fits ? str_replace(' ', "\u{00A0}", $values[$k]) : $values[$k];
            $els[] = self::text('val' . ($k + 1), $value, $x + 2.5, $y + $rowH * 0.17, $colW - 5, $rows === 1 ? 15 : 12, [
                'role' => 'chiffre', 'size' => $size, 'weight' => 700, 'color' => 'accent-ink',
            ]);
            $els[] = self::text('lib' . ($k + 1), (string)($kpi['label'] ?? ''), $x + 2.5, $y + $rowH * 0.17 + ($rows === 1 ? 17 : 13.5), $colW - 5, $rows === 1 ? 14 : 10, [
                'size' => 's',
            ]);
        }

        return $els;
    }

    /**
     * Tableau : en-tête sur le fond sombre, lignes alternées teintées.
     *
     * @param array{rows?: list<list<string>>, header?: bool} $table
     */
    private static function aplatsTableau(string $title, array $table, string $intro): array
    {
        $rows = array_values((array)($table['rows'] ?? []));
        $els  = self::aplatsHeader($title);
        $top  = 29.0;
        if (trim($intro) !== '') {
            $els[] = self::text('intro', $intro, self::M_X, 27.5, 84, 6, ['role' => 'legende', 'size' => 's']);
            $top   = 35.0;
        }
        $els[] = [
            'id' => 'tab', 'type' => 'table',
            'x' => self::M_X, 'y' => $top, 'w' => 84, 'h' => min(88 - $top, max(12.0, count($rows) * 6.5)),
            'rows' => $rows, 'header' => ($table['header'] ?? true) !== false,
            'size' => count($rows) > 8 ? 'xs' : 's',
            'head' => 'deep', 'band' => 'tint',
        ];

        return $els;
    }

    /**
     * Comparaison : deux cartes, la première teintée, la seconde sur le fond
     * sombre — l'œil va de l'une à l'autre.
     *
     * @param list<array{title?: string, points?: list<string>}> $columns
     */
    private static function aplatsComparaison(string $title, array $columns): array
    {
        $els = self::aplatsHeader($title);
        foreach (array_slice(array_values($columns), 0, 2) as $k => $col) {
            $x    = $k === 0 ? self::M_X : 51.0;
            $dark = $k === 1;
            $els[] = self::shape('carte' . ($k + 1), $x, 29, 41, 57, $dark ? 'deep' : 'tint', 1.0, 'round');
            $els[] = self::text('col' . ($k + 1), (string)($col['title'] ?? ''), $x + 3.5, 34, 34, 8, [
                'size' => 'm', 'weight' => 700, 'color' => $dark ? 'on-deep' : '',
            ]);
            $points = array_values((array)($col['points'] ?? []));
            if ($points !== []) {
                $els[] = self::bullets('pts' . ($k + 1), $points, $x + 3.5, 44, 34, 38, [
                    'size'   => self::bulletsSize($points, 34, 38, ['m', 's', 'xs']) ?? 'xs',
                    'color'  => $dark ? 'on-deep' : '',
                    'marker' => $dark ? 'on-deep-accent' : 'accent',
                ]);
            }
        }

        return $els;
    }

    /**
     * Étapes : pastilles numérotées reliées par un trait, libellé dessous ;
     * la dernière sur le fond sombre (l'aboutissement).
     *
     * @param list<string> $steps
     */
    private static function aplatsEtapes(string $title, array $steps): array
    {
        $steps = array_slice(array_values($steps), 0, 6);
        $n     = count($steps);
        $colW  = 84 / max(1, $n);
        $d     = 7.5;
        $dh    = $d * 16 / 9;
        $cy    = 33.0;
        $f     = self::cqw('m');
        $first = self::M_X + $colW / 2;
        $last  = self::M_X + $colW * ($n - 0.5);
        $size  = self::commonSize($steps, $colW - 2, $n > 4 ? ['s', 'xs'] : ['m', 's', 'xs'], true, 4);

        $els = self::aplatsHeader($title);
        if ($n > 1) {
            $els[] = self::shape('trait', $first, $cy + $dh / 2 - 0.42, $last - $first, 0.84, 'soft');
        }
        foreach ($steps as $k => $step) {
            $cx    = self::M_X + $colW * ($k + 0.5);
            $end   = $k === $n - 1;
            $els[] = self::shape('pastille' . ($k + 1), $cx - $d / 2, $cy, $d, $dh, $end ? 'deep' : 'accent', 1.0, 'circle');
            $els[] = self::text('num' . ($k + 1), (string)($k + 1), $cx - $d / 2, $cy + ($dh - self::lineH($f)) / 2, $d, self::lineH($f) + 0.4, [
                'role' => 'chiffre', 'size' => 'm', 'weight' => 700, 'align' => 'center', 'color' => $end ? 'on-deep' : 'on-accent',
            ]);
            $els[] = self::text('etape' . ($k + 1), (string)$step, self::M_X + $colW * $k + 1, 52, $colW - 2, 34, [
                'size' => $size, 'weight' => 600, 'align' => 'center',
            ]);
        }

        return $els;
    }

    /**
     * Chronologie : une ligne de temps, la date au-dessus en accent lisible,
     * un repère rond, l'évènement dessous.
     *
     * @param list<array{date?: string, label?: string}> $events
     */
    private static function aplatsChronologie(string $title, array $events): array
    {
        $events = array_slice(array_values($events), 0, 6);
        $n      = count($events);
        $w      = 84 / max(1, $n);
        $size   = self::commonSize(array_map(static fn($e) => (string)($e['date'] ?? ''), $events), $w - 2, ['m', 's'], true, 2);

        $els   = self::aplatsHeader($title);
        $els[] = self::shape('ligne', self::M_X, 52.5, 84, 0.56, 'soft');
        foreach ($events as $k => $event) {
            $x     = self::M_X + $k * $w;
            $els[] = self::text('date' . ($k + 1), (string)($event['date'] ?? ''), $x, 36, $w - 2, 9, [
                'role' => 'chiffre', 'size' => $size, 'weight' => 700, 'color' => 'accent-ink',
            ]);
            $els[] = self::shape('repere' . ($k + 1), $x, 50.56, 2.5, 4.44, $k === $n - 1 ? 'deep' : 'accent', 1.0, 'circle');
            $els[] = self::text('evt' . ($k + 1), (string)($event['label'] ?? ''), $x, 59, $w - 2.5, 28, ['size' => 's']);
        }

        return $els;
    }

    /** Citation : panneau sombre et grand guillemet à gauche, la citation à droite. */
    private static function aplatsCitation(string $quote, array $points): array
    {
        $f      = DeckRenderer::fontSizeCqw('l', $quote, 53, 46);
        $height = min(46.0, self::lines($quote, 53, $f) * self::lineH($f));
        $top    = max(14.0, 44 - $height / 2);

        $els = [
            self::shape('panneau', 0, 0, 33, 100, 'deep'),
            self::text('guillemet', '“', 6.5, 4, 24, 46, [
                'role' => 'chiffre', 'size' => 'xxxl', 'weight' => 400, 'color' => 'accent', 'font' => 'serif',
            ]),
            self::text('titre', $quote, 39, $top, 53, $height + 1, ['size' => 'l', 'weight' => 500]),
        ];

        $author = trim((string)($points[0] ?? ''));
        if ($author !== '') {
            $y     = $top + $height + 5;
            $els[] = self::shape('trait', 39, $y + 2.6, 3.75, 0.56, 'accent');
            $els[] = self::text('sous', $author, 44, $y, 48, 6, ['role' => 'legende', 'size' => 's']);
        }

        return $els;
    }

    /**
     * Points « **Terme** — explication » : une carte teintée par point, numéro
     * en pastille, terme en gras, explication dessous. Deux colonnes, ou une
     * seule rangée de cartes larges pour trois points.
     *
     * @param list<array{term?: string, text?: string}> $cards
     */
    private static function aplatsCartes(string $title, array $cards): array
    {
        $cards = array_slice(array_values($cards), 0, 6);
        $n     = count($cards);
        [$cols, $rows] = match ($n) {
            2       => [2, 1],
            3       => [1, 3],
            4       => [2, 2],
            default => [2, 3],
        };
        $h    = match ($rows) { 1 => 34.0, 2 => 28.0, default => 17.7 };
        $gapY = $rows === 3 ? 2.9 : 3.0;
        $w    = $cols === 1 ? 84.0 : (84 - 2.5) / 2;
        $fs   = self::cqw('s');

        $els = self::aplatsHeader($title);
        foreach ($cards as $k => $card) {
            $x = self::M_X + ($k % $cols) * ($w + 2.5);
            $y = 29 + intdiv($k, $cols) * ($h + $gapY);
            $els[] = self::shape('carte' . ($k + 1), $x, $y, $w, $h, 'tint', 1.0, 'round');
            $els[] = self::shape('pastille' . ($k + 1), $x + 2.4, $y + 3, 3.75, 6.67, 'accent', 1.0, 'circle');
            $els[] = self::text('num' . ($k + 1), (string)($k + 1), $x + 2.4, $y + 3 + (6.67 - self::lineH($fs)) / 2, 3.75, self::lineH($fs) + 0.3, [
                'role' => 'chiffre', 'size' => 's', 'weight' => 700, 'align' => 'center', 'color' => 'on-accent',
            ]);
            $els[] = self::text('terme' . ($k + 1), (string)($card['term'] ?? ''), $x + 8.6, $y + 3.2, $w - 11, 6, [
                'size' => 'm', 'weight' => 700,
            ]);
            $text = trim((string)($card['text'] ?? ''));
            if ($text !== '') {
                $els[] = self::text('expl' . ($k + 1), $text, $x + 8.6, $y + 9.6, $w - 11, max(5.0, $h - 11.6), ['size' => 's']);
            }
        }

        return $els;
    }

    /**
     * Marque en style « aplats » : sur un fond sombre (couverture,
     * conclusion), le nom passe en clair et le logo se pose sur une pastille
     * blanche — un logo sombre y disparaîtrait. Sur une citation, le pied
     * se décale à droite du panneau sombre.
     *
     * @param array{logo?: string, name?: string, legal?: string} $brand
     */
    private static function brandAplats(string $layout, array $brand): array
    {
        $logo  = (string)($brand['logo'] ?? '');
        $name  = trim((string)($brand['name'] ?? ''));
        $legal = trim((string)($brand['legal'] ?? ''));
        if (($logo === '' && $name === '' && $legal === '') || in_array($layout, ['section', 'image-pleine'], true)) {
            return [];
        }

        if ($layout === 'titre') {
            if ($logo !== '') {
                return [
                    self::shape('pastille-logo', self::M_X, 7, 17, 11.5, '#ffffff', 1.0, 'round'),
                    ['anchor' => 'center'] + self::logo('logo', $logo, self::M_X + 1.3, 8.6, 14.4, 8.3),
                ];
            }

            return $name !== ''
                ? [self::text('marque', $name, self::M_X, 8.5, 50, 6, ['role' => 'legende', 'size' => 's', 'weight' => 700, 'color' => 'on-deep'])]
                : [];
        }

        $dark = $layout === 'conclusion';
        $x0   = $layout === 'citation' ? 39.0 : self::M_X;
        $out  = [];
        if ($logo !== '') {
            if ($dark) {
                $out[] = self::shape('pastille-logo', $x0 - 0.6, 89.3, 13.2, 8.2, '#ffffff', 1.0, 'round');
            }
            $out[] = self::logo('logo', $logo, $x0, 90, 12, 6);
        }
        $text = $legal !== '' ? $legal : ($logo === '' ? $name : '');
        if ($text !== '') {
            $x     = $logo !== '' ? $x0 + 14 : $x0;
            $out[] = self::text($legal !== '' ? 'mention' : 'marque', $text, $x, 91, 86 - $x, 5, [
                'role' => 'numero', 'size' => 'xs', 'color' => $dark ? 'on-deep-muted' : '',
            ]);
        }

        return $out;
    }

    /**
     * Marque de l'entreprise : son logo en haut de la couverture, puis en pied
     * de chaque slide de contenu — à défaut de logo, son nom. La mention de
     * pied de page (adresse, SIRET, « Document interne »), si elle est
     * renseignée, prend la place du nom, à droite du logo. Ni sur les
     * intercalaires (aplat d'accent) ni sur les visuels plein cadre.
     *
     * @param array{logo?: string, name?: string, legal?: string} $brand
     */
    private static function brandElements(string $layout, array $brand): array
    {
        $logo  = (string)($brand['logo'] ?? '');
        $name  = trim((string)($brand['name'] ?? ''));
        $legal = trim((string)($brand['legal'] ?? ''));
        if (($logo === '' && $name === '' && $legal === '') || in_array($layout, ['section', 'image-pleine'], true)) {
            return [];
        }

        if ($layout === 'titre') {
            if ($logo !== '') {
                return [self::logo('logo', $logo, self::M_X, 8, 22, 12)];
            }

            return $name !== ''
                ? [self::text('marque', $name, self::M_X, 9, 60, 6, ['role' => 'legende', 'size' => 's', 'weight' => 600])]
                : [];
        }

        $out = [];
        if ($logo !== '') {
            $out[] = self::logo('logo', $logo, self::M_X, 90, 12, 6);
        }
        $text = $legal !== '' ? $legal : ($logo === '' ? $name : '');
        if ($text !== '') {
            $x     = $logo !== '' ? self::M_X + 14 : self::M_X;
            $out[] = self::text($legal !== '' ? 'mention' : 'marque', $text, $x, 91, 86 - $x, 5, ['role' => 'numero', 'size' => 'xs']);
        }

        return $out;
    }

    private static function logo(string $id, string $url, float $x, float $y, float $w, float $h): array
    {
        return [
            'id' => $id, 'type' => 'image',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'url' => $url, 'prompt' => '', 'fit' => 'contain', 'anchor' => 'left',
        ];
    }

    // ------------------------------------------------------- fabriques

    private static function text(string $id, string $text, float $x, float $y, float $w, float $h, array $opts = []): array
    {
        return [
            'id'     => $id,
            'type'   => 'text',
            'x'      => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'text'   => self::typo($text),
            'role'   => $opts['role']   ?? 'corps',
            'size'   => $opts['size']   ?? 'm',
            'align'  => $opts['align']  ?? 'left',
            'weight' => $opts['weight'] ?? 400,
            'color'  => $opts['color']  ?? '',
            'font'   => $opts['font']   ?? '',
        ];
    }

    /**
     * Espace insécable devant « : ; ? ! » et dans les guillemets français :
     * sans elle, un renderer renvoie le deux-points seul en début de ligne
     * (« Notre réponse / : MIA »). Sans effet sur un texte anglais, qui n'a
     * pas d'espace à cet endroit.
     */
    private static function typo(string $text): string
    {
        return preg_replace(['/ (?=[:;?!»])/u', '/« /u'], ["\u{00A0}", "«\u{00A0}"], $text) ?? $text;
    }

    private static function bullets(string $id, array $items, float $x, float $y, float $w, float $h, array $opts = []): array
    {
        return [
            'id'     => $id,
            'type'   => 'bullets',
            'x'      => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'items'  => array_map(static fn($item) => self::typo((string)$item), array_values($items)),
            'size'   => $opts['size'] ?? (count($items) > 5 ? 's' : 'm'),
            'align'  => 'left',
            'color'  => $opts['color']  ?? '',
            'marker' => $opts['marker'] ?? '',
        ];
    }

    private static function image(string $id, float $x, float $y, float $w, float $h, string $prompt, string $fit): array
    {
        return [
            'id'     => $id,
            'type'   => 'image',
            'x'      => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'prompt' => $prompt,
            'url'    => '',
            'fit'    => $fit,
        ];
    }

    private static function shape(string $id, float $x, float $y, float $w, float $h, string $fill, float $opacity = 1.0, string $kind = 'rect'): array
    {
        return [
            'id'      => $id,
            'type'    => 'shape',
            'x'       => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'shape'   => $kind,
            'fill'    => $fill,
            'opacity' => $opacity,
        ];
    }

    // ------------------------------------------------- estimations de texte

    /** Taille de base d'un cran, en cqw. */
    /** Largeur moyenne d'un caractère GRAS, en em (DejaVu Sans Bold du PDF : la plus large des trois). */
    private const BOLD_EM = 0.62;

    private static function cqw(string $size): float
    {
        return DeckRenderer::fontSizeCqw($size);
    }

    /**
     * Lignes qu'occupe un texte dans une largeur, à une taille donnée — la
     * même estimation que DeckRenderer::fontSizeCqw (0,52 em par caractère) :
     * grossière, mais identique pour les trois rendus. Un texte GRAS est plus
     * large (0,62 em : la police grasse du PDF passait un titre « d'une ligne »
     * sur deux, par-dessus le sous-titre) — mieux vaut réserver une ligne de
     * trop qu'en chevaucher une.
     */
    private static function lines(string $text, float $w, float $cqw, bool $bold = false): int
    {
        $perLine = max(1.0, $w / (($bold ? self::BOLD_EM : 0.52) * $cqw));

        return max(1, (int)ceil(mb_strlen(trim($text)) / $perLine));
    }

    /**
     * Plus grand cran de $sizes (du plus grand au plus petit) auquel le texte
     * tient en $maxLines lignes ET son mot le plus long sur une seule — sans
     * quoi un renderer coupe le mot (« Configuratio-n »), ou un chiffre
     * (« 2 / 400 »). À défaut, le plus petit cran.
     *
     * @param list<string> $sizes
     */
    private static function fitSize(string $text, float $w, array $sizes, bool $bold = false, int $maxLines = 1): string
    {
        foreach ($sizes as $size) {
            if (self::fitsAt($text, $w, $size, $bold, $maxLines)) {
                return $size;
            }
        }

        return $sizes[count($sizes) - 1];
    }

    /** Le texte tient-il à ce cran : $maxLines lignes au plus, aucun mot coupé ? */
    private static function fitsAt(string $text, float $w, string $size, bool $bold = false, int $maxLines = 1): bool
    {
        $longest = 0;
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $longest = max($longest, mb_strlen($word));
        }
        $f = self::cqw($size);

        return $longest * ($bold ? self::BOLD_EM : 0.52) * $f <= $w && self::lines($text, $w, $f, $bold) <= $maxLines;
    }

    /**
     * Cran COMMUN à plusieurs textes : le plus petit de leurs crans — des
     * chiffres clés ou des étapes à des tailles différentes se liraient comme
     * une hiérarchie qui n'existe pas.
     *
     * @param list<string> $texts
     * @param list<string> $sizes
     */
    private static function commonSize(array $texts, float $w, array $sizes, bool $bold = false, int $maxLines = 1): string
    {
        $rank = 0;
        foreach ($texts as $text) {
            $rank = max($rank, (int)array_search(self::fitSize((string)$text, $w, $sizes, $bold, $maxLines), $sizes, true));
        }

        return $sizes[$rank];
    }

    /** Hauteur d'une ligne, en % de la HAUTEUR de la slide (1 % de hauteur = 0,5625 cqw). */
    private static function lineH(float $cqw, float $lineHeight = 1.22): float
    {
        return $lineHeight * $cqw * 16 / 9;
    }

    /**
     * Hauteur estimée d'une liste à puces (interligne 1,4, écart de 1,6 cqw
     * entre deux puces, retrait de 2,6 cqw), en % de la hauteur de la slide.
     */
    private static function bulletsHeight(array $items, float $w, string $size): float
    {
        $f     = self::cqw($size);
        $total = 0.0;
        foreach ($items as $item) {
            $total += self::lines((string)$item, $w - 2.6, $f) * 1.4 * $f;
        }
        $total += max(0, count($items) - 1) * 1.6;

        return $total * 16 / 9;
    }

    /** Premier cran de la liste où les puces tiennent dans la boîte ; null si aucun. */
    private static function bulletsSize(array $items, float $w, float $h, array $sizes): ?string
    {
        foreach ($sizes as $size) {
            if (self::bulletsHeight($items, $w, $size) <= $h) {
                return $size;
            }
        }

        return null;
    }
}
