<?php

declare(strict_types=1);

/**
 * Couverture d'une vignette de la liste des workflows (/workflows) — la
 * proposition C, « la couverture », choisie le 2026-10-16 : la teinte du
 * workflow, son déroulé tracé comme une ligne de métro, et deux phrases
 * (quand il démarre, ce qu'il produit).
 *
 * Formes PURES, sans base ni réseau : le gabarit les appelle, les tests les
 * vérifient. Le dessin est fait ici, côté serveur — la liste n'a besoin
 * d'aucun script pour s'afficher.
 */
final class WorkflowCover
{
    /** Pas entre deux stations (unités du dessin). */
    public const GAP = 30;
    /** Écart entre deux voies d'un embranchement, resserré au-delà de trois voies. */
    public const LANE = 22;
    public const LANE_TIGHT = 16;
    /** Échelle du dessin : une station garde la même taille d'une vignette à l'autre. */
    public const SCALE = 1.6;
    /** Contraste minimal du blanc sur la couverture : la pastille d'état y porte du texte. */
    public const MIN_CONTRAST = 4.5;
    /** Assistants montrés au pied de la vignette ; au-delà, « +n ». */
    public const MAX_AVATARS = 3;

    // ------------------------------------------------------------------
    // Couleur
    // ------------------------------------------------------------------

    /**
     * Couleur de la couverture : la teinte du workflow, assombrie jusqu'à ce
     * que le blanc s'y lise. La couleur d'un workflow est libre (les modèles
     * livrés portent du vert anis et de l'ambre) : sans ce calcul, la ligne et
     * la pastille d'état disparaîtraient sur une teinte claire.
     */
    public static function coverColor(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = ltrim(Workflow::DEFAULT_COLOR, '#');
        }
        $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        // On assombrit par multiplication : la teinte ne bouge pas, seule la clarté baisse.
        for ($i = 0; $i < 60 && self::contrastWithWhite($rgb) < self::MIN_CONTRAST; $i++) {
            $rgb = array_map(static fn(float|int $c): float => $c * 0.94, $rgb);
        }
        return sprintf('#%02x%02x%02x', (int)round($rgb[0]), (int)round($rgb[1]), (int)round($rgb[2]));
    }

    /** Contraste du blanc sur une couleur (WCAG 2). */
    public static function contrastWithWhite(array $rgb): float
    {
        $lin = static function (float|int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
        return 1.05 / ($l + 0.05);
    }

    // ------------------------------------------------------------------
    // Tracé
    // ------------------------------------------------------------------

    /**
     * Brique désignée par une clé d'exécution : un passage de boucle
     * (« clé#2 »), une relecture (« clé~review »), le jugement d'une boucle
     * « jusqu'à » (« clé~until ») ou la fin d'un tour (« @end:clé ») se
     * ramènent à leur brique.
     */
    public static function baseKey(string $key): string
    {
        foreach ([WorkflowSpec::LOOP_END, WorkflowSpec::LOOP_SKIP] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $key = substr($key, strlen($prefix));
            }
        }
        [$key] = WorkflowLoop::splitIter($key);
        foreach ([WorkflowEngine::REVIEW_SUFFIX, WorkflowSpec::UNTIL_SUFFIX, WorkflowEngine::LIST_SUFFIX, WorkflowEngine::ACTIONS_SUFFIX] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                $key = substr($key, 0, -strlen($suffix));
            }
        }
        [$key] = WorkflowLoop::splitIter($key);
        return $key;
    }

    /**
     * Plan de la ligne, de gauche à droite : stations (`start`, `station`,
     * `fork`, `stop`, `join`, `end`), liaisons (voie, tronçon sans brique,
     * suite que plus rien n'atteint) et cadres des boucles.
     *
     * @return array{nodes: list<array>, edges: list<array>, frames: list<array>, minY: float, maxY: float, maxX: float}
     */
    public static function layout(array $def): array
    {
        $nodes = [];
        $edges = [];
        $frames = [];
        $minY = 0.0;
        $maxY = 0.0;
        $put = static function (string $type, ?string $key, float $x, float $y, int $lane) use (&$nodes): int {
            $nodes[] = ['t' => $type, 'key' => $key, 'x' => $x, 'y' => $y, 'lane' => $lane];
            return count($nodes) - 1;
        };
        // `broken` : un embranchement dont aucune voie ne revient laisse la
        // suite du rail hors d'atteinte — elle est tracée en pointillés.
        $edge = static function (int $a, int $b, int $lane = -1, bool $empty = false, ?float $ly = null) use (&$edges): void {
            $edges[] = ['a' => $a, 'b' => $b, 'lane' => $lane, 'empty' => $empty, 'ly' => $ly];
        };

        $prev = $put('start', WorkflowSpec::START_KEY, 0.0, 0.0, -1);
        $x = (float)self::GAP;
        $broken = false;

        foreach ($def['nodes'] ?? [] as $node) {
            if (WorkflowSpec::isBranching($node)) {
                $fork = $put('fork', (string)$node['key'], $x, 0.0, -1);
                $edge($prev, $fork, -1, $broken);
                $lanes = array_values(WorkflowSpec::branchesOf($node));
                $k = count($lanes);
                $gap = $k <= 3 ? self::LANE : self::LANE_TIGHT;
                $xEnd = $x + self::GAP;
                $ends = [];
                $continues = false;
                foreach ($lanes as $i => $lane) {
                    $ly = ($i - ($k - 1) / 2) * $gap;
                    $minY = min($minY, $ly);
                    $maxY = max($maxY, $ly);
                    $lx = $x + self::GAP;
                    $lprev = $fork;
                    $stopped = false;
                    foreach ($lane['nodes'] ?? [] as $child) {
                        $isStop = ($child['type'] ?? '') === WorkflowSpec::TYPE_STOP;
                        $id = $put($isStop ? 'stop' : 'station', (string)$child['key'], $lx, $ly, $i);
                        $edge($lprev, $id, $i);
                        $lprev = $id;
                        $lx += self::GAP;
                        if ($isStop) {
                            $stopped = true;
                            break;
                        }
                    }
                    if (!$stopped && ($lane['then'] ?? WorkflowSpec::THEN_CONTINUE) === WorkflowSpec::THEN_STOP) {
                        // Voie réglée sur « arrêter » sans brique d'arrêt : la fin se dessine quand même.
                        $id = $put('stop', null, $lx, $ly, $i);
                        $edge($lprev, $id, $i);
                        $lx += self::GAP;
                        $stopped = true;
                    }
                    if ($stopped) {
                        $ends[] = null;
                        $xEnd = max($xEnd, $lx);
                        continue;
                    }
                    $continues = true;
                    if ($lprev === $fork) {
                        // Voie sans brique qui reprend le rail : un tronçon en pointillés.
                        $ends[] = ['empty' => true, 'lane' => $i, 'ly' => $ly];
                        $xEnd = max($xEnd, $x + 2 * self::GAP);
                    } else {
                        $ends[] = ['empty' => false, 'lane' => $i, 'from' => $lprev];
                        $xEnd = max($xEnd, $lx);
                    }
                }
                $join = $put('join', null, $xEnd, 0.0, -1);
                foreach ($ends as $end) {
                    if ($end === null) {
                        continue;
                    }
                    if ($end['empty']) {
                        $edge($fork, $join, $end['lane'], true, $end['ly']);
                    } else {
                        $edge($end['from'], $join, $end['lane']);
                    }
                }
                $broken = $broken || !$continues;
                $prev = $join;
                $x = $xEnd + self::GAP;
                continue;
            }

            if (($node['type'] ?? '') === WorkflowSpec::TYPE_LOOP) {
                $x0 = $x;
                $body = $node['body'] ?? [];
                $first = null;
                foreach ($body === [] ? [['key' => (string)$node['key']]] : $body as $child) {
                    $id = $put('station', (string)$child['key'], $x, 0.0, -1);
                    $first ??= $id;
                    $edge($prev, $id, -1, $broken);
                    $prev = $id;
                    $x += self::GAP;
                }
                $frames[] = ['key' => (string)$node['key'], 'x0' => $x0, 'x1' => $x - self::GAP, 'first' => $first];
                continue;
            }

            if (($node['type'] ?? '') === WorkflowSpec::TYPE_STOP) {
                continue;
            }
            $id = $put('station', (string)$node['key'], $x, 0.0, -1);
            $edge($prev, $id, -1, $broken);
            $prev = $id;
            $x += self::GAP;
        }

        $end = $put('end', WorkflowSpec::END_KEY, $x, 0.0, -1);
        $edge($prev, $end, -1, $broken);

        return ['nodes' => $nodes, 'edges' => $edges, 'frames' => $frames, 'minY' => $minY, 'maxY' => $maxY, 'maxX' => $x];
    }

    /**
     * Position d'une brique sur le rail principal : [n, total], ou null pour
     * le départ et le livrable. Une brique d'une voie ou d'une boucle compte
     * pour la brique du rail qui la porte.
     */
    public static function progress(array $def, string $key): ?array
    {
        $at = WorkflowSpec::locate($def, self::baseKey($key));
        if ($at === null) {
            return null;
        }
        $index = $at['in'] === 'main' ? (int)$at['index'] : (int)$at['condIndex'];
        return [$index + 1, count($def['nodes'] ?? [])];
    }

    /**
     * La ligne de métro, en SVG (muet pour un lecteur d'écran : la vignette
     * dit tout en texte). `$current` = clé de la brique où en est la dernière
     * exécution, `$tone` = run | wait | err (anneau autour de sa station).
     */
    public static function svg(array $def, ?string $current = null, string $tone = ''): string
    {
        $plan = self::layout($def);
        $nodes = $plan['nodes'];
        $pad = 6;
        $vx = -$pad;
        $vy = $plan['minY'] - $pad - 2;
        $vw = $plan['maxX'] + 2 * $pad;
        $vh = ($plan['maxY'] - $plan['minY']) + 2 * $pad + 4;
        $n = static fn(float|int $v): string => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');

        // Le rail se prolonge hors du cadre, des deux côtés : c'est une ligne, pas un schéma clos.
        $out = '<path class="wf-m-ghost" d="M-600 0H0"/>'
            . '<path class="wf-m-ghost" d="M' . $n($plan['maxX']) . ' 0H' . $n($plan['maxX'] + 600) . '"/>';

        foreach ($plan['frames'] as $f) {
            $out .= '<rect class="wf-m-fr" x="' . $n($f['x0'] - 12) . '" y="-12" width="' . $n($f['x1'] - $f['x0'] + 24)
                . '" height="24" rx="12"/>';
        }

        foreach ($plan['edges'] as $e) {
            $a = $nodes[$e['a']];
            $b = $nodes[$e['b']];
            $cls = 'wf-m-line' . ($e['lane'] >= 0 ? ' is-lane' : '') . ($e['empty'] ? ' is-empty' : '');
            if ($e['empty'] && $e['ly'] !== null && abs($e['ly'] - $a['y']) > 0.5) {
                $x1 = $a['x'] + self::GAP * 0.9;
                $x2 = $b['x'] - self::GAP * 0.9;
                $d = self::segment($a['x'], $a['y'], $x1, $e['ly'], false, $n)
                    . ' M' . $n($x1) . ' ' . $n($e['ly']) . 'H' . $n($x2) . ' '
                    . self::segment($x2, $e['ly'], $b['x'], $b['y'], true, $n);
            } else {
                $d = self::segment($a['x'], $a['y'], $b['x'], $b['y'], $b['t'] === 'join', $n);
            }
            $out .= '<path class="' . $cls . '" d="' . $d . '"/>';
        }

        $ringKey = $current !== null && $current !== '' ? self::baseKey($current) : null;
        $ringAt = null;
        if ($ringKey !== null) {
            foreach ($plan['frames'] as $f) {
                if ($f['key'] === $ringKey) {
                    $ringAt = $f['first'];
                }
            }
            foreach ($nodes as $i => $node) {
                if ($ringAt === null && $node['key'] === $ringKey && $node['t'] !== 'join') {
                    $ringAt = $i;
                }
            }
        }

        foreach ($nodes as $i => $node) {
            $x = $n($node['x']);
            $y = $n($node['y']);
            if ($i === $ringAt && in_array($tone, ['run', 'wait', 'err'], true)) {
                $out .= '<circle class="wf-m-ring is-' . $tone . '" cx="' . $x . '" cy="' . $y . '" r="10"/>';
            }
            $out .= match ($node['t']) {
                'start' => '<circle class="wf-m-term" cx="' . $x . '" cy="' . $y . '" r="5.5"/>',
                'end'   => '<circle class="wf-m-term" cx="' . $x . '" cy="' . $y . '" r="7.5"/><circle class="wf-m-hole" cx="' . $x . '" cy="' . $y . '" r="3.2"/>',
                'fork'  => '<rect class="wf-m-term" x="-5" y="-5" width="10" height="10" rx="1.5" transform="translate(' . $x . ' ' . $y . ') rotate(45)"/>',
                'stop'  => '<rect class="wf-m-term" x="' . $n($node['x'] - 1.6) . '" y="' . $n($node['y'] - 7) . '" width="3.2" height="14" rx="1.6"/>',
                'join'  => '',
                default => '<circle class="wf-m-st" cx="' . $x . '" cy="' . $y . '" r="4.4"/>',
            };
        }

        // Taille aussi en style : la règle commune des pictogrammes de la page
        // (16 px) l'emporte sur les attributs, jamais sur un style en ligne.
        $w = (int)round($vw * self::SCALE);
        $h = (int)round($vh * self::SCALE);
        return '<svg class="wf-metro" viewBox="' . $n($vx) . ' ' . $n($vy) . ' ' . $n($vw) . ' ' . $n($vh) . '"'
            . ' width="' . $w . '" height="' . $h . '" style="width:' . $w . 'px;height:' . $h . 'px"'
            . ' preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">' . $out . '</svg>';
    }

    /**
     * Un tronçon : droit à même hauteur, sinon un biais à 45° (ligne de
     * métro) — au départ d'une séparation, à l'arrivée d'une jonction.
     */
    private static function segment(float $x1, float $y1, float $x2, float $y2, bool $merge, callable $n): string
    {
        if (abs($y1 - $y2) < 0.5) {
            return 'M' . $n($x1) . ' ' . $n($y1) . 'H' . $n($x2);
        }
        $slant = min(abs($y2 - $y1), max(0.0, $x2 - $x1));
        return $merge
            ? 'M' . $n($x1) . ' ' . $n($y1) . 'H' . $n($x2 - $slant) . 'L' . $n($x2) . ' ' . $n($y2)
            : 'M' . $n($x1) . ' ' . $n($y1) . 'L' . $n($x1 + $slant) . ' ' . $n($y2) . 'H' . $n($x2);
    }

    // ------------------------------------------------------------------
    // Ce que dit la vignette
    // ------------------------------------------------------------------

    /** « Quand » : ce qui lance le workflow, en une phrase. */
    public static function when(array $def): string
    {
        $start = $def['start'] ?? [];
        if (($start['event'] ?? null) !== null) {
            return WorkflowCatalog::eventSentence($start['event']);
        }
        if (($start['schedule'] ?? null) !== null) {
            $rhythm = Recurrence::describe((array)$start['schedule']['rule']);
            return !empty($start['chat']) ? t('{rythme}, ou depuis le chat', ['rythme' => $rhythm]) : $rhythm;
        }
        return !empty($start['chat']) ? t('Quand vous le lancez, ici ou depuis le chat') : t('Quand vous le lancez');
    }

    /** Icône du déclencheur (catalogue WorkflowCatalog::icons). */
    public static function triggerIcon(array $def): string
    {
        $start = $def['start'] ?? [];
        if (($start['event'] ?? null) !== null) {
            return ['email' => 'mail', 'file' => 'folder', 'webhook' => 'webhook', 'workflow' => 'chain'][$start['event']['kind']] ?? 'workflow';
        }
        if (($start['schedule'] ?? null) !== null) {
            return 'clock';
        }
        return !empty($start['chat']) ? 'chat' : 'hand';
    }

    /**
     * « Résultat » : les pièces du livrable et où elles partent. À défaut de
     * livrable, l'objectif du workflow ; à défaut, rien.
     */
    public static function result(array $def): string
    {
        $pieces = WorkflowSpec::pieces($def);
        $names = [];
        foreach ($def['end']['pieces'] ?? [] as $key) {
            if (isset($pieces[$key]) && trim((string)$pieces[$key]['name']) !== '') {
                $names[] = trim((string)$pieces[$key]['name']);
            }
        }
        if ($names === []) {
            return trim((string)($def['objective'] ?? ''));
        }
        $where = [];
        foreach ($def['end']['deliveries'] ?? [] as $d) {
            $where[] = match ((string)$d['type']) {
                'email' => t('par email'),
                'drive' => t('dans Drive'),
                'slack' => t('dans Slack'),
                default => t('dans une base de connaissances'),
            };
        }
        $text = implode(', ', $names);
        $where = array_values(array_unique($where));
        return $where === [] ? $text : $text . ' · ' . implode(', ', $where);
    }

    /** Briques mises en avant sur la vignette d'un modèle, dans cet ordre. */
    public const FEATURE_ORDER = ['route', 'condition', 'loop', 'create', 'action', 'gate', 'wait', 'notify', 'stop'];

    /**
     * Ce que le workflow fait voir de plus qu'une suite d'étapes : les types
     * de briques présents (une relecture d'étape compte pour une validation),
     * dans l'ordre de FEATURE_ORDER. @return list<string>
     */
    public static function features(array $def): array
    {
        $seen = [];
        foreach (WorkflowSpec::flatNodes($def) as $node) {
            $seen[(string)$node['type']] = true;
            if (!empty($node['review'])) {
                $seen[WorkflowSpec::TYPE_GATE] = true;
            }
        }
        return array_values(array_filter(self::FEATURE_ORDER, static fn(string $t): bool => isset($seen[$t])));
    }

    /** Assistants des étapes, dans l'ordre du déroulé, sans doublon. */
    public static function profileIds(array $def): array
    {
        $ids = [];
        foreach (WorkflowSpec::flatNodes($def) as $node) {
            $pid = $node['profile_id'] ?? null;
            if (is_int($pid) && $pid > 0 && !in_array($pid, $ids, true)) {
                $ids[] = $pid;
            }
        }
        return $ids;
    }

    /**
     * État de la vignette : libellé, ton de la pastille, adresse de
     * l'exécution (null : la pastille n'ouvre rien), ton de l'anneau sur la
     * ligne ('' : aucun). `$timer` : la dernière exécution attend une
     * brique « Attendre » (elle reprendra seule).
     *
     * Une exécution en cours, qui attend ou en échec prime sur la pause du
     * workflow : mettre un workflow en pause n'arrête pas l'exécution qui vous
     * attend, et c'est elle qu'il faut voir. `$waitingRun` : une exécution
     * qui attend votre décision (WorkflowRun::waitingForUser), même plus
     * ancienne que la dernière — elle passe avant tout le reste.
     *
     * `key` : la brique où en est l'exécution montrée (anneau sur la ligne).
     *
     * @return array{label: string, tone: string, href: ?string, ring: string, key: string}
     */
    public static function state(array $workflow, bool $timer = false, ?array $waitingRun = null): array
    {
        if (($workflow['published'] ?? null) === null) {
            return ['label' => t('Brouillon'), 'tone' => 'draft', 'href' => null, 'ring' => '', 'key' => ''];
        }
        if ($waitingRun !== null && (int)($waitingRun['id'] ?? 0) > 0) {
            $workflow['last_run_status'] = WorkflowRun::WAITING;
            $workflow['last_run_id'] = (int)$waitingRun['id'];
            $workflow['last_run_key'] = (string)($waitingRun['current_key'] ?? '');
            $timer = false;
        }
        $status = (int)($workflow['last_run_id'] ?? 0) > 0 ? ($workflow['last_run_status'] ?? null) : null;
        $live = in_array($status, [WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED, WorkflowRun::FAILED], true);
        if (!$live && empty($workflow['is_active'])) {
            return ['label' => t('En pause'), 'tone' => 'pause', 'href' => null, 'ring' => '', 'key' => ''];
        }
        if ($status === null) {
            return ['label' => t('Publié'), 'tone' => 'idle', 'href' => null, 'ring' => '', 'key' => ''];
        }
        [$label, $tone] = WorkflowCatalog::statusLabel((string)$status, $timer);
        $ring = '';
        if ($live) {
            $ring = $status === WorkflowRun::FAILED ? 'err' : ($tone === 'run' ? 'run' : 'wait');
        }
        if ($status === WorkflowRun::RUNNING) {
            $at = self::progress((array)$workflow['published'], (string)($workflow['last_run_key'] ?? ''));
            if ($at !== null) {
                $label = t('{statut} · {n}/{total}', ['statut' => $label, 'n' => $at[0], 'total' => $at[1]]);
            }
        }
        return [
            'label' => $label,
            'tone'  => $tone !== '' ? $tone : 'idle',
            'href'  => '/workflows/runs/' . (int)$workflow['last_run_id'],
            'ring'  => $ring,
            'key'   => $ring !== '' ? (string)($workflow['last_run_key'] ?? '') : '',
        ];
    }
}
