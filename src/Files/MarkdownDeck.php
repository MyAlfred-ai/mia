<?php

declare(strict_types=1);

/**
 * Markdown → présentation (spec de deck). Lecture PURE, sans bibliothèque.
 *
 * Le modèle livre une présentation PowerPoint comme un document : du Markdown
 * dans un bloc nommé (« plan.pptx »). Le découpage en slides est une
 * CONVENTION, enseignée au modèle par FileDelivery, et c'est ici seulement
 * qu'elle se lit :
 *
 *   # Titre              → couverture ; son premier paragraphe = sous-titre
 *   ## Titre             → une slide ; ses puces et paragraphes = ses points
 *                          (des `#` seulement, ou des `###` seulement : chacun
 *                          ouvre une slide, sans couverture)
 *   ## Titre, sans rien  → intercalaire de partie
 *   > citation seule     → slide citation (« — Auteur » en légende)
 *   - **42 %** — légende → chiffres clés (2 à 6 valeurs, rien d'autre)
 *   - **2024** — fait    → chronologie (les valeurs sont des dates)
 *   - **Terme** — texte  → cartes (2 à 6 termes, sans chiffre imposé)
 *   | tableau |          → un vrai tableau (seul tableau de la slide)
 *   ### A … ### B        → comparaison en deux colonnes titrées
 *   1. 2. 3. (seule)     → étapes numérotées (3 à 6)
 *   Notes : …            → note du présentateur de la slide
 *   ---                  → sépare deux slides (habitude des outils de slides
 *                          en Markdown, que les modèles reprennent)
 *
 * La GÉOMÉTRIE ne se lit pas ici : on produit un plan (titre, points, mise en
 * page voulue) et c'est LayoutEngine, déjà au service de l'outil Présentation,
 * qui pose les éléments. Les deux chemins donnent des slides de la même
 * famille — mêmes marges, mêmes filets, même échelle typographique — et les
 * exports (PptxExporter, SlidesPdfExporter) sont ceux de l'outil.
 *
 * Trop de contenu pour une slide : elle est DÉCOUPÉE en « Titre (suite) »
 * plutôt que rapetissée jusqu'à l'illisible ou amputée sans le dire. Au-delà
 * du plafond d'une présentation (DeckSpec::MAX_SLIDES), la fabrication est
 * REFUSÉE avec les formats qui gardent tout : couper la fin serait livrer un
 * fichier faux en silence.
 */
final class MarkdownDeck
{
    /** Points au plus par slide, et longueur cumulée de leur texte. */
    public const MAX_POINTS       = 6;
    public const MAX_POINTS_CHARS = 600;

    /** Au-delà de MAX_POINTS, des points COURTS tiennent encore sur deux colonnes. */
    public const TWO_COLUMNS_MAX   = 10;
    public const SHORT_POINT_CHARS = 60;

    /** Un premier paragraphe plus long n'est pas un sous-titre. */
    private const SUBTITLE_MAX = 200;

    /** Lignes de DONNÉES d'un tableau par slide (l'en-tête est répété). */
    public const TABLE_ROWS_PER_SLIDE = 8;

    /** Introduction d'un tableau : une ou deux lignes au-dessus. */
    private const INTRO_MAX = 240;

    /** Une valeur de chiffre clé : courte, avec au moins un chiffre. */
    private const KPI_VALUE_MAX = 16;

    /** Une étape se lit d'un coup d'œil ; plus longue, c'est une liste. */
    private const STEP_MAX = 120;

    /** Une carte : terme court, explication de quelques lignes au plus. */
    private const CARD_TERM_MAX = 48;
    private const CARD_TEXT_MAX = 160;

    /**
     * Une valeur qui est une DATE : année, trimestre, mois, jour/mois. Toutes
     * les valeurs d'une liste « **valeur** — texte » sont des dates → c'est
     * une chronologie, pas des chiffres clés.
     */
    private const DATE_VALUE = '/^(?:(?:19|20)\d{2}(?:\s*[-–]\s*(?:19|20)?\d{2,4})?|[TQ][1-4](?:\s+(?:19|20)\d{2})?|S[12]\s+(?:19|20)\d{2}|\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?'
        . '|(?:\d{1,2}(?:er)?\s+)?(?:janv|févr|fevr|mars|avr|mai|juin|juil|août|aout|sept|oct|nov|déc|dec|jan|feb|mar|apr|may|jun|jul|aug|sep)[a-zéû.]*(?:\s+(?:19|20)\d{2})?)$/iu';

    /**
     * Note du présentateur : « Notes : … », « Note du présentateur : … »,
     * « Speaker notes: … ». Jamais « Note : » seul : sur une slide, c'est une
     * remarque à afficher, pas une note cachée.
     */
    private const NOTES = '/^(?:notes|notes?\s+(?:du|de\s+la)\s+(?:pr[ée]sentat(?:eur|rice)|orat(?:eur|rice))|(?:speaker|presenter)\s+notes?)\s*:\s*/iu';

    /** Titre d'une dernière slide qui conclut : elle reçoit sa mise en page de clôture. */
    private const CONCLUSION = '/^(?:conclusions?|synth[èe]se|en\s+(?:r[ée]sum[ée]|bref)|[àa]\s+retenir|prochaines?\s+[ée]tapes?|next\s+steps?|summary|key\s+takeaways?|takeaways?)\b/iu';

    /** Légende d'une citation : un tiret en tête de ligne. */
    private const ATTRIBUTION = '/^\s*[—–-]\s*(.+)$/u';

    /**
     * Spec de deck normalisée, prête pour les exporteurs.
     *
     * @param list<array<string, mixed>> $blocks Blocs de MarkdownDocument.
     *
     * @throws FileBuildException Rien à mettre en slides, ou trop de slides.
     */
    /**
     * @param array{logo?: string, name?: string} $brand  marque de l'entreprise (cf. LayoutEngine)
     * @param array<string, string> $theme  couleurs imposées (`accent`, `bg`, `text`, `muted`) :
     *                                      celles du modèle PowerPoint de l'entreprise
     */
    public static function build(array $blocks, string $fallbackTitle = '', array $brand = [], array $theme = []): array
    {
        $outline = self::plan($blocks, $fallbackTitle);
        $total   = count($outline['slides']);

        $slides = [];
        $part   = 0;
        foreach ($outline['slides'] as $i => $item) {
            // Un intercalaire porte le numéro de SA partie, pas celui de la slide.
            if (LayoutEngine::layoutOf($item, $i, false) === 'section') {
                $item['part'] = ++$part;
            }
            // Aucune image : rien ne la générerait ici. Les mises en page
            // visuelles retombent donc sur leur équivalent texte. Style
            // « aplats » : fonds sombres, cartes, pastilles, à la couleur de
            // l'entreprise.
            $slides[] = LayoutEngine::build($item + ['id' => 's' . ($i + 1)], $i, $total, false, $brand, LayoutEngine::STYLE_APLATS);
        }

        return DeckSpec::normalize([
            'title'  => $outline['title'],
            'theme'  => ['preset' => DeckSpec::DEFAULT_THEME] + $theme,
            'slides' => $slides,
        ]);
    }

    /**
     * Plan VÉRIFIÉ : au moins une slide, au plus DeckSpec::MAX_SLIDES. Point
     * d'entrée commun de notre mise en page (build) et du modèle PowerPoint de
     * l'entreprise (PptxTemplate) : la même présentation est refusée ou
     * acceptée dans les deux cas.
     *
     * @return array{title: string, slides: list<array<string, mixed>>}
     * @throws FileBuildException
     */
    public static function plan(array $blocks, string $fallbackTitle = ''): array
    {
        $outline = self::outline($blocks, $fallbackTitle);
        $total   = count($outline['slides']);

        if ($total === 0) {
            throw FileBuildException::emptySource();
        }
        if ($total > DeckSpec::MAX_SLIDES) {
            // Les issues gardent TOUT le contenu : le document Word et la source.
            throw FileBuildException::tooManySlides($total, DeckSpec::MAX_SLIDES, ['docx', 'md']);
        }

        return $outline;
    }

    /**
     * Plan de la présentation, AVANT mise en page.
     *
     * @param list<array<string, mixed>> $blocks
     * Une slide porte `title`, `points`, `layout`, `notes`, et selon sa mise
     * en page ses DONNÉES : `kpis` (chiffres), `table` + `intro` (tableau),
     * `columns` (comparaison), `steps` (étapes), `events` (chronologie).
     *
     * @return array{title: string, slides: list<array<string, mixed>>}
     */
    public static function outline(array $blocks, string $fallbackTitle = ''): array
    {
        $slides   = [];
        $sections = self::sections($blocks);
        $last     = count($sections) - 1;

        foreach ($sections as $s => $section) {
            $made = self::slidesOf($section);
            if ($s === $last && $made !== [] && preg_match(self::CONCLUSION, $section['title']) === 1) {
                foreach ($made as $k => $slide) {
                    if ($slide['layout'] === 'titre-contenu') {
                        $made[$k]['layout'] = 'conclusion';
                    }
                }
            }
            foreach ($made as $slide) {
                // Une section sans titre ni point (des notes après un `---`)
                // n'est pas une slide : ses notes rejoignent la précédente.
                if ($slide['title'] === '' && $slide['points'] === [] && $slide['layout'] !== 'citation') {
                    if ($slide['notes'] !== '' && $slides !== []) {
                        $previous = count($slides) - 1;
                        $slides[$previous]['notes'] = mb_substr(
                            trim($slides[$previous]['notes'] . "\n" . $slide['notes']),
                            0,
                            DeckSpec::MAX_NOTES_LEN
                        );
                    }
                    continue;
                }
                $slides[] = $slide;
            }
        }

        return ['title' => self::deckTitle($blocks, $fallbackTitle), 'slides' => $slides];
    }

    /**
     * La présentation en CARTES DE TEXTE, telle que le modèle l'a écrite : une
     * carte par section de la convention (couverture, titre de slide, `---`),
     * dans le Markdown d'origine — emphase, liens, tableaux, listes gardés —,
     * notes du présentateur retirées. Pour un service qui met en page
     * lui-même (gamma.app, cf. GammaService::deckPayload) : on lui confie le
     * texte, pas notre géométrie.
     *
     * Même découpage que outline() — niveau de titre des slides (slideLevel),
     * filets, blocs de code jamais coupés, mêmes motifs de ligne que
     * MarkdownDocument —, à une différence près, voulue : une section trop
     * chargée n'est pas redécoupée en « (suite) », la mise en page est
     * l'affaire du service.
     *
     * @return array{title: string, cards: list<string>}
     */
    public static function sourceCards(string $markdown, string $fallbackTitle = ''): array
    {
        $markdown = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);
        $blocks   = MarkdownDocument::parse($markdown);
        [$level]  = self::slideLevel($blocks);
        $lines    = explode("\n", $markdown);
        $count    = count($lines);

        $cards   = [];
        $current = [];
        $flush   = static function () use (&$cards, &$current): void {
            $text = trim(implode("\n", $current), "\n");
            if (trim($text) !== '') {
                $cards[] = rtrim($text);
            }
            $current = [];
        };

        $fence     = null;    // [caractère, longueur] du bloc de code ouvert
        $state     = 'blank'; // bloc de la ligne précédente : blank | para | list | table | other
        $paraStart = 0;       // début, dans $current, du paragraphe en cours
        $inNotes   = false;   // paragraphe de notes du présentateur : ses lignes sont mises de côté
        $held      = [];      // … ici (rendues s'il s'avère être un titre souligné)

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if ($fence !== null) {
                $current[] = $line;
                if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',}[ \t]*$/', $line) === 1) {
                    $fence = null;
                }
                continue;
            }
            if (trim($line) === '') {
                $state     = 'blank';
                $inNotes   = false;
                $current[] = $line;
                continue;
            }
            if (preg_match(MarkdownDocument::FENCE_OPEN, $line, $m) === 1) {
                $fence     = [$m[1][0], strlen($m[1])];
                $state     = 'other';
                $inNotes   = false;
                $current[] = $line;
                continue;
            }
            if (preg_match(MarkdownDocument::HEADING, $line, $m) === 1) {
                if (strlen($m[1]) <= $level) {
                    $flush();
                }
                $state     = 'other';
                $inNotes   = false;
                $current[] = $line;
                continue;
            }
            // Titre souligné : le paragraphe qui précède en est le texte
            // (MarkdownDocument teste ce cas AVANT le filet). Réécrit en `#` :
            // un soulignement `---` se lirait ailleurs comme un séparateur.
            if ($state === 'para' && preg_match(MarkdownDocument::SETEXT, $line, $m) === 1) {
                $title      = $inNotes ? $held : array_splice($current, $paraStart);
                $titleLevel = $m[1][0] === '=' ? 1 : 2;
                if ($titleLevel <= $level) {
                    $flush();
                }
                $current[] = str_repeat('#', $titleLevel) . ' ' . implode(' ', array_map('trim', $title));
                $state     = 'other';
                $inNotes   = false;
                continue;
            }
            if (MarkdownDocument::isRule($line)) {
                $flush();
                $state   = 'other';
                $inNotes = false;
                continue;
            }

            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $state   = 'other';
                $inNotes = false;
            } elseif (MarkdownDocument::listMarker($line) !== null) {
                $state   = 'list';
                $inNotes = false;
            } elseif ($state === 'table' && str_contains($line, '|')) {
                // ligne du tableau en cours
            } elseif (str_contains($line, '|') && $i + 1 < $count && MarkdownDocument::isTableSeparator($lines[$i + 1])) {
                $state   = 'table';
                $inNotes = false;
            } elseif ($state !== 'para' && $state !== 'list') {
                // Nouveau paragraphe : une note du présentateur n'a rien à
                // faire sur une carte (« **Notes :** » compris).
                $state     = 'para';
                $paraStart = count($current);
                $inNotes   = preg_match(self::NOTES, ltrim($line, " *_")) === 1;
                $held      = [];
            }

            if ($inNotes) {
                $held[] = $line;
            } else {
                $current[] = $line;
            }
        }
        $flush();

        return ['title' => self::deckTitle($blocks, $fallbackTitle), 'cards' => $cards];
    }

    // ------------------------------------------------------------------
    // Sections : un titre et ce qui le suit
    // ------------------------------------------------------------------

    /**
     * Blocs regroupés en sections : un titre du niveau des slides (ou plus
     * haut) ouvre la suivante, un filet referme la courante. Une section ne
     * naît que si elle porte quelque chose — `---` puis `## Titre` ne donne
     * pas de slide vide.
     *
     * @return list<array{title: string, cover: bool, blocks: list<array<string, mixed>>}>
     */
    private static function sections(array $blocks): array
    {
        [$slideLevel, $coverAllowed] = self::slideLevel($blocks);

        $sections = [];
        $current  = null;

        $close = static function () use (&$sections, &$current): void {
            if ($current !== null && ($current['title'] !== '' || $current['blocks'] !== [])) {
                $sections[] = $current;
            }
            $current = null;
        };

        foreach ($blocks as $block) {
            $type = (string)($block['type'] ?? '');

            if ($type === 'heading' && (int)($block['level'] ?? 1) <= $slideLevel) {
                // Couverture : un titre de niveau 1 en TOUTE première position.
                $cover = $coverAllowed && (int)($block['level'] ?? 1) === 1 && $sections === [] && $current === null;
                $close();
                $current = ['title' => self::clean(self::text($block['inlines'] ?? []), DeckSpec::MAX_TITLE_LEN), 'cover' => $cover, 'blocks' => []];
                continue;
            }
            if ($type === 'rule') {
                $close();
                continue;
            }

            $current ??= ['title' => '', 'cover' => false, 'blocks' => []];
            $current['blocks'][] = $block;
        }
        $close();

        return $sections;
    }

    /**
     * Niveau de titre qui ouvre une slide, et si un `#` peut faire une
     * couverture. La convention enseignée est `#` (couverture) puis `##`
     * (slides) ; mais un modèle écrit aussi une présentation en `#` seulement
     * — chacun est alors une slide, sinon la première serait prise pour une
     * couverture et ses puces envoyées ailleurs — ou en `###` seulement.
     *
     * @return array{0: int, 1: bool}
     */
    private static function slideLevel(array $blocks): array
    {
        $levels = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'heading') {
                $level          = (int)($block['level'] ?? 1);
                $levels[$level] = ($levels[$level] ?? 0) + 1;
            }
        }

        if (($levels[2] ?? 0) > 0) {
            return [2, true];
        }
        if (($levels[1] ?? 0) >= 2) {
            return [1, false];
        }
        if (($levels[1] ?? 0) === 1) {
            return [2, true];
        }

        return [$levels === [] ? 2 : min(array_keys($levels)), false];
    }

    /**
     * Slides d'une section : une, ou plusieurs si ses points débordent.
     *
     * @param array{title: string, cover: bool, blocks: list<array<string, mixed>>} $section
     * @return list<array{title: string, points: list<string>, layout: string, notes: string}>
     */
    private static function slidesOf(array $section): array
    {
        $title  = $section['title'];
        $points = [];
        $notes  = [];
        $content = [];
        foreach ($section['blocks'] as $block) {
            $note = self::noteOf($block);
            if ($note !== null) {
                if ($note !== '') {
                    $notes[] = $note;
                }
                continue;
            }
            $content[] = $block;
            self::collect($block, $points);
        }
        $notes = implode("\n", $notes);

        $quote = self::citation($content, $title);
        if ($quote !== null) {
            return [self::slide($quote['text'], $quote['caption'] !== '' ? [$quote['caption']] : [], 'citation', $notes)];
        }

        if (!$section['cover']) {
            $structured = self::structured($content, $title, $notes);
            if ($structured !== null) {
                return $structured;
            }
        }

        if ($section['cover']) {
            // Un premier PARAGRAPHE court sert de sous-titre ; le reste de la
            // couverture (rare) passe sur la slide suivante, sans titre.
            $subtitle = [];
            if ($content !== [] && ($content[0]['type'] ?? '') === 'paragraph' && $points !== []
                && mb_strlen($points[0]) <= self::SUBTITLE_MAX) {
                $subtitle = [array_shift($points)];
            }
            $slides = [self::slide($title, $subtitle, 'titre', $notes)];
            foreach (self::chunks($points) as [$chunk, $layout]) {
                $slides[] = self::slide('', $chunk, $layout, '');
            }

            return $slides;
        }

        if ($points === []) {
            // Titre seul : LayoutEngine en fait un intercalaire (une
            // couverture s'il ouvre la présentation).
            return [self::slide($title, [], '', $notes)];
        }

        $slides = [];
        foreach (self::chunks($points) as $k => [$chunk, $layout]) {
            $slides[] = self::slide(
                $k === 0 || $title === '' ? $title : self::clean(t('{titre} (suite)', ['titre' => $title]), DeckSpec::MAX_TITLE_LEN),
                $chunk,
                $layout,
                $k === 0 ? $notes : ''
            );
        }

        return $slides;
    }

    /** @return array{title: string, points: list<string>, layout: string, notes: string} */
    private static function slide(string $title, array $points, string $layout, string $notes, array $data = []): array
    {
        return [
            'title'  => $title,
            'points' => array_values($points),
            'layout' => $layout,
            'notes'  => mb_substr($notes, 0, DeckSpec::MAX_NOTES_LEN),
        ] + $data;
    }

    /** Titre d'une slide de suite. */
    private static function continued(string $title, int $k): string
    {
        return $k === 0 || $title === '' ? $title : self::clean(t('{titre} (suite)', ['titre' => $title]), DeckSpec::MAX_TITLE_LEN);
    }

    /**
     * Mises en page à DONNÉES, reconnues à la forme du contenu — avant qu'il
     * ne soit aplati en puces. null : rien de reconnu, la slide reste une
     * liste de points.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>|null
     */
    private static function structured(array $content, string $title, string $notes): ?array
    {
        if ($content === []) {
            return null;
        }

        // Tableau : le SEUL tableau de la slide, entouré au plus de deux
        // paragraphes — son introduction. Avec des listes ou des sous-titres
        // autour, la slide reste une liste de points (tableau compris), comme
        // avant : rien ne serait plus à sa place. Trop de lignes : découpé,
        // en-tête répété.
        $tables = array_values(array_filter($content, static fn (array $b): bool => ($b['type'] ?? '') === 'table'));
        $others = array_filter($content, static fn (array $b): bool => ($b['type'] ?? '') !== 'table');
        $prose  = array_filter($others, static fn (array $b): bool => ($b['type'] ?? '') === 'paragraph');
        if (count($tables) === 1 && count($others) === count($prose) && count($prose) <= 2) {
            $table = self::tableOf($tables[0]);
            if ($table !== null) {
                $intro = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'table') {
                        self::collect($block, $intro);
                    }
                }
                $intro  = self::clean(implode(' ', $intro), self::INTRO_MAX);
                $header = $table['header'] ? [$table['rows'][0]] : [];
                $data   = $table['header'] ? array_slice($table['rows'], 1) : $table['rows'];
                $slides = [];
                foreach (array_chunk($data, self::TABLE_ROWS_PER_SLIDE) ?: [[]] as $k => $chunk) {
                    $slides[] = self::slide(self::continued($title, $k), [], 'tableau', $k === 0 ? $notes : '', [
                        'table' => ['header' => $table['header'], 'rows' => array_merge($header, $chunk)],
                        'intro' => $k === 0 ? $intro : '',
                    ]);
                }

                return $slides;
            }
        }

        // Comparaison : deux sous-titres, chacun suivi de son contenu.
        if (($content[0]['type'] ?? '') === 'heading') {
            $columns = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'heading') {
                    $columns[] = ['title' => self::clean(self::text($block['inlines'] ?? []), DeckSpec::MAX_TITLE_LEN), 'points' => []];
                    continue;
                }
                self::collect($block, $columns[count($columns) - 1]['points']);
            }
            if (count($columns) === 2) {
                return [self::slide($title, [], 'comparaison', $notes, ['columns' => $columns])];
            }
        }

        // Chiffres clés ou chronologie : « **valeur** — légende », partout.
        $pairs = self::valuePairs($content);
        if ($pairs !== null && count($pairs) >= 2 && count($pairs) <= 6) {
            $dates = array_filter($pairs, static fn (array $p): bool => preg_match(self::DATE_VALUE, $p['value']) === 1);
            if (count($dates) === count($pairs)) {
                return [self::slide($title, [], 'chronologie', $notes, [
                    'events' => array_map(static fn (array $p): array => ['date' => $p['value'], 'label' => $p['label']], $pairs),
                ])];
            }

            return [self::slide($title, [], 'chiffres', $notes, [
                'kpis' => array_map(static fn (array $p): array => ['value' => $p['value'], 'label' => $p['label']], $pairs),
            ])];
        }

        // Cartes : « **Terme** — explication », partout, sans chiffre imposé.
        // Les points gardent la forme texte : le modèle PowerPoint de
        // l'entreprise, qui n'a pas de cartes, les reprend en puces.
        $cards = self::termPairs($content);
        if ($cards !== null && count($cards) >= 2 && count($cards) <= 6) {
            return [self::slide(
                $title,
                array_map(static fn (array $c): string => $c['text'] !== '' ? $c['term'] . ' — ' . $c['text'] : $c['term'], $cards),
                'cartes',
                $notes,
                ['cards' => $cards]
            )];
        }

        // Étapes : une liste numérotée SEULE, de 3 à 6 entrées courtes.
        if (count($content) === 1 && ($content[0]['type'] ?? '') === 'list' && !empty($content[0]['ordered'])) {
            $steps = [];
            foreach ((array)($content[0]['items'] ?? []) as $item) {
                if (!empty($item['children'])) {
                    return null;
                }
                $steps[] = self::clean(self::text($item['inlines'] ?? []));
            }
            $steps = array_values(array_filter($steps, static fn (string $s): bool => $s !== ''));
            $long  = array_filter($steps, static fn (string $s): bool => mb_strlen($s) > self::STEP_MAX);
            if (count($steps) >= 3 && count($steps) <= 6 && $long === []) {
                return [self::slide($title, [], 'etapes', $notes, ['steps' => $steps])];
            }
        }

        return null;
    }

    /**
     * Tableau exploitable : cellules en texte, au plus MAX_TABLE_COLS
     * colonnes (au-delà, il ne se lit plus sur une slide — il reste en puces).
     *
     * @return array{header: bool, rows: list<list<string>>}|null
     */
    private static function tableOf(array $block): ?array
    {
        $plain  = static fn (array $cells): array => array_map(
            static fn ($cell): string => self::clean(self::text((array)$cell), DeckSpec::MAX_CELL_LEN),
            $cells
        );
        $header = $plain((array)($block['header'] ?? []));
        $rows   = array_map($plain, array_map(static fn ($r): array => (array)$r, (array)($block['rows'] ?? [])));
        $width  = max(count($header), ...array_map('count', $rows ?: [[]]));
        if ($width < 1 || $width > DeckSpec::MAX_TABLE_COLS) {
            return null;
        }
        $hasHeader = implode('', $header) !== '';

        return ['header' => $hasHeader, 'rows' => $hasHeader ? array_merge([$header], $rows) : $rows];
    }

    /**
     * Paires « **valeur** — légende » : chaque paragraphe et chaque entrée de
     * liste de la slide doit avoir cette forme (valeur en gras, courte, avec
     * un chiffre ; puis un séparateur et une légende). null au premier bloc
     * qui ne l'a pas.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array{value: string, label: string}>|null
     */
    private static function valuePairs(array $content): ?array
    {
        $runs = [];
        foreach ($content as $block) {
            $type = (string)($block['type'] ?? '');
            if ($type === 'paragraph') {
                $runs[] = (array)($block['inlines'] ?? []);
            } elseif ($type === 'list') {
                foreach ((array)($block['items'] ?? []) as $item) {
                    if (!empty($item['children'])) {
                        return null;
                    }
                    $runs[] = (array)($item['inlines'] ?? []);
                }
            } else {
                return null;
            }
        }

        $pairs = [];
        foreach ($runs as $inlines) {
            $first = $inlines[0] ?? null;
            if (!is_array($first) || empty($first['bold'])) {
                return null;
            }
            $value = self::clean((string)($first['text'] ?? ''));
            $rest  = self::clean(self::text(array_slice($inlines, 1)));
            if ($value === '' || mb_strlen($value) > self::KPI_VALUE_MAX || preg_match('/\d/u', $value) !== 1
                || preg_match('/^[—–\-:]\s*(.+)$/u', $rest, $m) !== 1) {
                return null;
            }
            $pairs[] = ['value' => $value, 'label' => self::clean($m[1], DeckSpec::MAX_BULLET_LEN)];
        }

        return $pairs;
    }

    /**
     * Paires « **Terme** — explication » (ou « **Terme :** explication ») :
     * chaque paragraphe et chaque entrée de liste de la slide doit commencer
     * par un terme en gras, court, suivi d'une explication courte. null au
     * premier bloc qui n'a pas cette forme.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array{term: string, text: string}>|null
     */
    private static function termPairs(array $content): ?array
    {
        $runs = [];
        foreach ($content as $block) {
            $type = (string)($block['type'] ?? '');
            if ($type === 'paragraph') {
                $runs[] = (array)($block['inlines'] ?? []);
            } elseif ($type === 'list') {
                foreach ((array)($block['items'] ?? []) as $item) {
                    if (!empty($item['children'])) {
                        return null;
                    }
                    $runs[] = (array)($item['inlines'] ?? []);
                }
            } else {
                return null;
            }
        }

        $cards = [];
        foreach ($runs as $inlines) {
            $first = $inlines[0] ?? null;
            if (!is_array($first) || empty($first['bold'])) {
                return null;
            }
            $term  = self::clean((string)($first['text'] ?? ''));
            $rest  = self::clean(self::text(array_slice($inlines, 1)));
            $colon = preg_match('/^(.+?)\s*[:—–]$/u', $term, $t) === 1;
            if ($colon) {
                $term = trim($t[1]);
                $text = $rest;
            } elseif (preg_match('/^[—–\-:]\s*(.+)$/u', $rest, $m) === 1) {
                $text = $m[1];
            } else {
                return null;
            }
            if ($term === '' || mb_strlen($term) > self::CARD_TERM_MAX || mb_strlen($text) > self::CARD_TEXT_MAX) {
                return null;
            }
            $cards[] = ['term' => $term, 'text' => self::clean($text, self::CARD_TEXT_MAX)];
        }

        return $cards;
    }

    /**
     * Points répartis en slides : une seule tant qu'ils tiennent, deux
     * colonnes pour une liste de points courts, sinon des tranches.
     *
     * @param list<string> $points
     * @return list<array{0: list<string>, 1: string}>
     */
    private static function chunks(array $points): array
    {
        if ($points === []) {
            return [];
        }

        $chars = array_sum(array_map('mb_strlen', $points));
        if (count($points) <= self::MAX_POINTS && $chars <= self::MAX_POINTS_CHARS) {
            return [[$points, 'titre-contenu']];
        }

        $short = max(array_map('mb_strlen', $points)) <= self::SHORT_POINT_CHARS;
        if ($short && count($points) <= self::TWO_COLUMNS_MAX) {
            return [[$points, 'deux-colonnes']];
        }

        $chunks = [];
        $chunk  = [];
        $length = 0;
        foreach ($points as $point) {
            $size = mb_strlen($point);
            if ($chunk !== [] && (count($chunk) >= self::MAX_POINTS || $length + $size > self::MAX_POINTS_CHARS)) {
                $chunks[] = [$chunk, 'titre-contenu'];
                $chunk    = [];
                $length   = 0;
            }
            $chunk[] = $point;
            $length += $size;
        }
        $chunks[] = [$chunk, 'titre-contenu'];

        return $chunks;
    }

    // ------------------------------------------------------------------
    // Contenu d'une slide
    // ------------------------------------------------------------------

    /**
     * Points d'un bloc, ajoutés à `$points`. Une slide n'a qu'une forme de
     * contenu — des puces — : listes imbriquées, sous-titres, tableaux et
     * citations y deviennent des puces, dans l'ordre où ils sont écrits.
     *
     * @param list<string> $points
     */
    private static function collect(array $block, array &$points): void
    {
        switch ((string)($block['type'] ?? '')) {
            case 'heading':
            case 'paragraph':
                self::push($points, self::text($block['inlines'] ?? []));
                break;

            case 'list':
                foreach ((array)($block['items'] ?? []) as $item) {
                    self::push($points, self::text($item['inlines'] ?? []));
                    foreach ((array)($item['children'] ?? []) as $child) {
                        self::collect((array)$child, $points);
                    }
                }
                break;

            case 'table':
                // Une ligne = une puce : « première cellule : les autres ».
                $rows = (array)($block['rows'] ?? []);
                if ($rows === []) {
                    $rows = [(array)($block['header'] ?? [])];
                }
                foreach ($rows as $row) {
                    $cells = array_values(array_filter(
                        array_map(static fn ($cell): string => self::clean(self::text((array)$cell)), (array)$row),
                        static fn (string $cell): bool => $cell !== ''
                    ));
                    if ($cells !== []) {
                        $first = array_shift($cells);
                        self::push($points, $cells === [] ? $first : $first . ' : ' . implode(' · ', $cells));
                    }
                }
                break;

            case 'quote':
                foreach ((array)($block['blocks'] ?? []) as $inner) {
                    self::collect((array)$inner, $points);
                }
                break;

            case 'code':
                self::push($points, (string)($block['text'] ?? ''));
                break;
        }
    }

    /**
     * Citation qui occupe seule sa slide : son texte devient le corps de la
     * slide, sa légende (« — Auteur ») ou, à défaut, le titre de la section.
     *
     * @param list<array<string, mixed>> $content
     * @return array{text: string, caption: string}|null
     */
    private static function citation(array $content, string $title): ?array
    {
        $count = count($content);
        if ($count === 0 || $count > 2 || ($content[0]['type'] ?? '') !== 'quote') {
            return null;
        }

        $inner   = array_values((array)($content[0]['blocks'] ?? []));
        $caption = '';

        if ($count === 2) {
            // La légende écrite sous la citation, hors du `>`.
            if (($content[1]['type'] ?? '') !== 'paragraph'
                || preg_match(self::ATTRIBUTION, self::clean(self::text($content[1]['inlines'] ?? [])), $m) !== 1) {
                return null;
            }
            $caption = trim($m[1]);
        } elseif ($inner !== [] && ($inner[count($inner) - 1]['type'] ?? '') === 'paragraph'
            && preg_match(self::ATTRIBUTION, self::clean(self::text($inner[count($inner) - 1]['inlines'] ?? [])), $m) === 1
            && count($inner) > 1) {
            // La légende écrite dans la citation : « > — Auteur ».
            $caption = trim($m[1]);
            array_pop($inner);
        }

        $lines = [];
        foreach ($inner as $block) {
            self::collect((array)$block, $lines);
        }
        $text = self::clean(implode(' ', $lines), DeckSpec::MAX_BULLET_LEN);
        if ($text === '') {
            return null;
        }

        return ['text' => $text, 'caption' => $caption !== '' ? self::clean($caption, DeckSpec::MAX_BULLET_LEN) : $title];
    }

    /** Texte d'une note du présentateur ; null si le bloc n'en est pas une. */
    private static function noteOf(array $block): ?string
    {
        if (($block['type'] ?? '') !== 'paragraph') {
            return null;
        }
        $text = self::clean(self::text($block['inlines'] ?? []), PHP_INT_MAX);
        if (preg_match(self::NOTES, $text, $m) !== 1) {
            return null;
        }

        return trim(mb_substr($text, mb_strlen($m[0])));
    }

    /** Titre du deck : le premier titre de niveau 1, sinon le premier titre, sinon le nom du fichier. */
    private static function deckTitle(array $blocks, string $fallback): string
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'heading' && (int)($block['level'] ?? 0) === 1) {
                $title = self::clean(self::text($block['inlines'] ?? []), DeckSpec::MAX_TITLE_LEN);
                if ($title !== '') {
                    return $title;
                }
            }
        }
        $title = self::clean(MarkdownDocument::title($blocks), DeckSpec::MAX_TITLE_LEN);

        return $title !== '' ? $title : self::clean($fallback, DeckSpec::MAX_TITLE_LEN);
    }

    // ------------------------------------------------------------------
    // Texte
    // ------------------------------------------------------------------

    /** @param list<string> $points */
    private static function push(array &$points, string $text): void
    {
        $text = self::clean($text, DeckSpec::MAX_BULLET_LEN);
        if ($text !== '') {
            $points[] = $text;
        }
    }

    /** Texte d'une suite de runs : une slide ne garde pas l'emphase. */
    private static function text(array $runs): string
    {
        return MarkdownDocument::plainText($runs);
    }

    /**
     * Une ligne, sans blancs superflus, bornée À UN MOT près avec une
     * ellipse : DeckSpec coupe net au caractère, au milieu d'un mot.
     */
    private static function clean(string $text, int $max = PHP_INT_MAX): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:.-–—") . '…';
    }
}
