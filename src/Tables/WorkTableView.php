<?php

declare(strict_types=1);

/**
 * Les tables de travail d'une conversation, telles que la PERSONNE les voit.
 *
 * LE PROBLÈME RÉSOLU. Une table de travail n'existait que pour le modèle : la
 * personne n'en voyait qu'une ligne de trace et, sur demande, un lien
 * d'export. Elle ne pouvait ni l'ouvrir, ni la renommer, ni la supprimer —
 * la vingt-et-unième table d'une conversation était refusée sans qu'elle
 * puisse faire de place.
 *
 * LA RÈGLE (décision d'Axel, 2026-11-16) : une table ne s'affiche JAMAIS dans
 * le fil de la conversation. Sous la réponse qui l'a établie, une ligne
 * discrète la nomme (nom, nombre de lignes) avec un bouton « Voir » ; la
 * grille ne s'ouvre qu'à la demande, dans un volet à droite.
 *
 * LES TABLES PERMANENTES engagées dans la conversation (bases de nature
 * « table », cochées par la personne ou portées par son assistant) s'y
 * voient aussi : sous la réponse qui les a lues ou proposé de les modifier,
 * dans la puce et dans le volet — en lecture seule, elles se tiennent dans
 * leur fiche. Sans cela, la table que la personne a cochée était la seule
 * qu'elle ne pouvait pas ouvrir depuis la conversation. Leur référence est
 * « p » + l'identifiant de leur base (`p12`), jamais un identifiant de table
 * de travail.
 *
 * Trois choses ici, toutes sans requête propre (le magasin les fait) :
 *   - la LIGNE sous une réponse (`refs`, `forMessages`, `line`) ;
 *   - la PUCE de la zone de saisie (`chip`) ;
 *   - le VOLET (`panel`) : liste des tables, page de lignes de la table
 *     choisie, filtre, liens d'export.
 *
 * TROIS SORTES DE TABLES, jamais confondues (décision d'Axel, 2026-11-20) :
 *   - « Vos tables » : les tables PERMANENTES engagées — toujours en tête,
 *     ouvertes par défaut ;
 *   - leurs VUES (WorkTableRecipe) : une table permanente filtrée, triée,
 *     réduite — rien n'est copié, les lignes sont recalculées à chaque
 *     lecture. Nommée par le modèle (`vers`), une vue est rangée sous sa
 *     table (une ligne de `work_tables`, origine « vue », sans lignes) ;
 *     sans nom, elle vit dans la référence même de la réponse
 *     (« p12.5.<recette> ») ;
 *   - les « Résultats de MIA » : listes que MIA a établies (collecte,
 *     croisement, regroupement) — temporaires, « tirées de… », signalées
 *     PÉRIMÉES quand la table permanente dont elles viennent a changé.
 * Les brouillons internes (WorkTableOps::isHidden) n'apparaissent nulle part.
 *
 * Les deux gabarits (templates/partials/work-table-line.php,
 * work-tables-panel.php) sont rendus au chargement de la page ET renvoyés
 * tout faits par les routes : le script du volet n'a aucun libellé.
 */
final class WorkTableView
{
    /** Lignes par page du volet. */
    public const PAGE_ROWS = 50;
    /**
     * Tables nommées sous une réponse : UNE, celle que le tour a établie en
     * dernier (le résultat qu'on vient voir). Les autres sont comptées
     * (« + 2 autres tables ») — la ligne reste une ligne, le fil reste lisible.
     */
    public const LINE_MAX = 1;
    /** Tables retenues par réponse (état « agent » du message). */
    public const REFS_MAX = 12;
    /** Caractères d'une cellule affichée ; le texte entier reste en infobulle. */
    public const CELL_CHARS = 140;
    /** Mots d'un filtre. */
    private const FILTER_WORDS = 6;
    /** Lignes lues par passage pendant un filtre : la mémoire reste bornée. */
    private const SCAN_CHUNK = 500;

    // ==================================================================
    // Sous une réponse
    // ==================================================================

    /**
     * Références de tables gardées dans l'état « agent » d'un message
     * (`agent_json.tables`) : l'identifiant d'une table de la conversation,
     * ou « p » + l'identifiant de base d'une table permanente. Pure.
     *
     * @return list<int|string>
     */
    public static function refs(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $ref) {
            $ref = self::ref($ref);
            if ($ref !== 0 && !in_array($ref, $out, true)) {
                $out[] = $ref;
            }
            if (count($out) >= self::REFS_MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * Une référence de table, telle qu'elle arrive (état d'un message,
     * adresse du volet) : entier > 0, ou « p12 ». 0 = aucune. Pure.
     */
    public static function ref(mixed $raw): int|string
    {
        if (is_string($raw) && preg_match('/^p([1-9]\d{0,9})(?:\.(\d{1,6})\.([A-Za-z0-9_-]{4,' . WorkTableRecipe::ENCODED_MAX . '}))?$/', $raw, $m) === 1) {
            return 'p' . (int)$m[1] . (isset($m[3]) && $m[3] !== '' ? '.' . (int)$m[2] . '.' . $m[3] : '');
        }
        $id = is_numeric($raw) ? (int)$raw : 0;
        return $id > 0 ? $id : 0;
    }

    /**
     * Tables permanentes qu'une réponse NOMME (par leur titre, ou leur nom
     * court) : le modèle connaît leur nombre de lignes et leurs colonnes par
     * la consigne, il peut donc répondre « elle compte 240 lignes » sans
     * appeler d'outil — la réponse doit quand même proposer « Voir ». Pure.
     *
     * @param list<array> $permanent tables permanentes engagées
     * @return list<string> références « p12 »
     */
    public static function namedIn(string $text, array $permanent): array
    {
        $folded = WorkTableOps::fold($text);
        if ($folded === '') {
            return [];
        }
        $out = [];
        foreach ($permanent as $t) {
            $title = WorkTableOps::fold((string)($t['title'] ?? ''));
            $name  = (string)($t['name'] ?? '');
            // Un titre très court (« T », « Suivi ») se retrouverait dans n'importe quelle phrase.
            if ((mb_strlen($title) >= 6 && str_contains($folded, $title))
                || (strlen($name) >= 6 && str_contains($folded, WorkTableOps::fold($name)))) {
                $out[] = self::refOf(['permanent' => true] + $t);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Référence d'une table : « p12 » pour une table permanente, « p12.5.<recette> »
     * pour une vue sans nom de la table 12 (5 lignes au moment de la réponse),
     * l'identifiant sinon (table de la conversation, vue nommée). Pure.
     */
    public static function refOf(array $table): int|string
    {
        if (!empty($table['permanent'])) {
            $kb   = (int)($table['kb_id'] ?? $table['id'] ?? 0);
            $code = isset($table['anon_steps']) ? WorkTableRecipe::encode((array)$table['anon_steps']) : '';
            return $code !== '' ? 'p' . $kb . '.' . max(0, (int)($table['anon_rows'] ?? 0)) . '.' . $code : 'p' . $kb;
        }
        return (int)($table['id'] ?? 0);
    }

    /**
     * Une référence de vue sans nom, décomposée : [base, lignes, recette brute],
     * ou null. Pure.
     *
     * @return array{0:int, 1:int, 2:array}|null
     */
    public static function anonRef(int|string $ref): ?array
    {
        if (!is_string($ref) || preg_match('/^p(\d+)\.(\d+)\.([A-Za-z0-9_-]+)$/', $ref, $m) !== 1) {
            return null;
        }
        $steps = WorkTableRecipe::decode($m[3]);
        return $steps !== null ? [(int)$m[1], (int)$m[2], $steps] : null;
    }

    // ==================================================================
    // Vues, résultats, brouillons
    // ==================================================================

    /** Une vue nommée (ligne de `work_tables`, origine « vue ») ? Pure. */
    public static function isView(array $table): bool
    {
        return empty($table['permanent']) && (string)($table['origin']['kind'] ?? '') === 'vue';
    }

    /** Table permanente d'une vue nommée (identifiant de sa base), 0 sinon. Pure. */
    public static function viewKb(array $table): int
    {
        return self::isView($table) ? (int)($table['origin']['kb'] ?? 0) : 0;
    }

    /** Recette (brute) d'une vue nommée — à repasser par WorkTableRecipe::normalize(). Pure. */
    public static function viewSteps(array $table): array
    {
        $steps = $table['origin']['steps'] ?? [];
        return is_array($steps) ? $steps : [];
    }

    /**
     * Tables permanentes dont un résultat est tiré : [{kb, stamp, titre}] (son
     * origine). Vide pour une table qui ne vient d'aucune. Pure.
     *
     * @return list<array{kb:int, stamp:string, titre:string}>
     */
    public static function basesOf(array $table): array
    {
        $out = [];
        foreach ((array)($table['origin']['bases'] ?? []) as $b) {
            if (is_array($b) && (int)($b['kb'] ?? 0) > 0) {
                $out[] = ['kb' => (int)$b['kb'], 'stamp' => (string)($b['stamp'] ?? ''), 'titre' => (string)($b['titre'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Un résultat PÉRIMÉ : la table permanente dont il est tiré a changé
     * depuis (son empreinte n'est plus la même). Rend le titre de cette
     * table, '' sinon — et '' quand elle n'est plus engagée : on ne sait pas. Pure.
     *
     * @param array<int, array> $permanentByKb tables permanentes engagées, par base
     */
    public static function staleBase(array $table, array $permanentByKb): string
    {
        if (!empty($table['permanent']) || self::isView($table)) {
            return '';
        }
        foreach (self::basesOf($table) as $b) {
            $now = $permanentByKb[$b['kb']] ?? null;
            if ($now !== null && $b['stamp'] !== '' && KbTableStore::stamp($now) !== $b['stamp']) {
                return (string)($now['title'] ?? $b['titre']);
            }
        }
        return '';
    }

    /**
     * Référence À MONTRER pour une table : un brouillon interne (enrichissement
     * ou actions sur une table permanente) montre la table qu'il complète. Pure.
     */
    public static function displayRef(array $table): int|string
    {
        if (WorkTableOps::isHidden((string)($table['name'] ?? ''))) {
            $ref = self::ref($table['origin']['ref'] ?? '');
            if ($ref === 0 && (int)($table['origin']['kb'] ?? 0) > 0) {
                $ref = 'p' . (int)$table['origin']['kb'];
            }
            return $ref;
        }
        return self::refOf($table);
    }

    /**
     * Les lignes d'une vue (nommée ou non) de la table permanente $permanent.
     * Rend {columns, rows, error} : `error` dit pourquoi la vue ne s'applique
     * plus (une colonne a disparu), et les lignes sont alors vides.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, steps: list<array>, error: string}
     */
    public static function viewData(array $permanent, array $rawSteps, WorkTableRows $rows): array
    {
        try {
            $steps = WorkTableRecipe::normalize((array)$permanent['columns'], $rawSteps);
        } catch (\InvalidArgumentException $e) {
            return ['columns' => (array)$permanent['columns'], 'rows' => [], 'steps' => [], 'error' => $e->getMessage()];
        }
        $res = WorkTableRecipe::apply((array)$permanent['columns'], $rows->rows((int)$permanent['kb_id']), $steps);
        return $res + ['steps' => $steps, 'error' => ''];
    }

    /**
     * Tables à nommer sous chaque réponse : celles que la réponse a établies
     * ou consultées ET qui existent encore. Une table supprimée depuis ne
     * laisse rien ; une table remplacée ou renommée montre son état du moment.
     * Pure.
     *
     * @param list<array> $messages  lignes de `messages` (id, role, agent_json)
     * @param list<array> $tables    tables de la conversation (WorkTableStore::all)
     * @param list<array> $permanent tables permanentes ENGAGÉES dans la conversation (KbTableStore::engaged) :
     *                               une table décochée depuis ne se nomme plus
     * @param WorkTableRows|null $permanentRows lignes des tables permanentes : le nombre de lignes d'une vue
     *                               est alors celui du MOMENT (`live_rows`) — une vue suit sa table, son
     *                               compte aussi. Sans lui, le compte noté à sa création.
     * @return array<int, list<array>> id de message → tables
     */
    public static function forMessages(array $messages, array $tables, array $permanent = [], ?WorkTableRows $permanentRows = null): array
    {
        if ($tables === [] && $permanent === []) {
            return [];
        }
        $byId = [];
        $byKb = [];
        foreach ($permanent as $t) {
            $byId[self::refOf(['permanent' => true] + $t)] = ['permanent' => true] + $t;
            $byKb[(int)$t['kb_id']] = ['permanent' => true] + $t;
        }
        foreach ($tables as $t) {
            $byId[(int)$t['id']] = self::withParent($t, $byKb);
        }
        $out = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'assistant' || empty($m['agent_json'])) {
                continue;
            }
            $found = [];
            foreach (self::refs(Message::agentOf($m)['tables'] ?? null) as $id) {
                if (isset($byId[$id])) {
                    $found[] = $byId[$id];
                    continue;
                }
                // Vue sans nom d'une table permanente encore engagée.
                $anon = self::anonRef($id);
                if ($anon !== null && isset($byKb[$anon[0]])) {
                    $found[] = $byKb[$anon[0]] + ['anon_steps' => $anon[2], 'anon_rows' => $anon[1]];
                }
            }
            if ($found !== []) {
                $out[(int)$m['id']] = $found;
            }
        }
        return $permanentRows !== null && $out !== [] ? self::withLiveCounts($out, $byKb, $permanentRows) : $out;
    }

    /**
     * Le nombre de lignes de chaque vue, recalculé sur sa table du moment
     * (`live_rows`), et `view_error` quand elle ne s'applique plus. Chaque
     * table n'est lue qu'une fois, chaque recette calculée une fois.
     *
     * @param array<int, list<array>> $lines forMessages
     * @param array<int, array>       $byKb  tables permanentes engagées, par base
     * @return array<int, list<array>>
     */
    private static function withLiveCounts(array $lines, array $byKb, WorkTableRows $permanentRows): array
    {
        $rowsByKb = [];
        $counts   = [];
        foreach ($lines as $msgId => $found) {
            foreach ($found as $i => $t) {
                $anon = isset($t['anon_steps']);
                $kb   = $anon ? (int)($t['kb_id'] ?? 0) : self::viewKb($t);
                if ($kb <= 0 || !isset($byKb[$kb])) {
                    continue;
                }
                $steps = $anon ? (array)$t['anon_steps'] : self::viewSteps($t);
                $key   = $kb . ':' . json_encode($steps);
                if (!isset($counts[$key])) {
                    $rowsByKb[$kb] ??= new ArrayTableRows($permanentRows->rows($kb));
                    $data = self::viewData($byKb[$kb], $steps, $rowsByKb[$kb]);
                    $counts[$key] = [count($data['rows']), $data['error'] !== ''];
                }
                $lines[$msgId][$i]['live_rows'] = $counts[$key][0];
                if ($counts[$key][1]) {
                    $lines[$msgId][$i]['view_error'] = true;
                }
            }
        }
        return $lines;
    }

    /**
     * Une vue ou un résultat tiré d'une table permanente engagée porte le
     * titre de celle-ci (`parent`) : « Clients 2026 › Clients à relancer ».
     *
     * @param array<int, array> $byKb tables permanentes engagées, par base
     */
    private static function withParent(array $t, array $byKb): array
    {
        $kb = self::viewKb($t);
        if ($kb > 0) {
            return $t + ['parent' => (string)($byKb[$kb]['title'] ?? ($t['origin']['titre_source'] ?? ''))];
        }
        foreach (self::basesOf($t) as $b) {
            return $t + ['parent' => (string)($byKb[$b['kb']]['title'] ?? $b['titre'])];
        }
        return $t;
    }

    /** Un message porte-t-il des tables à nommer ? (évite une lecture des tables pour rien) */
    public static function anyRefs(array $messages): bool
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'assistant' && !empty($m['agent_json'])
                && str_contains((string)$m['agent_json'], '"tables"')) {
                return true;
            }
        }
        return false;
    }

    /**
     * La ligne sous une réponse : au plus LINE_MAX tables nommées, le reste
     * compté. Pure.
     *
     * @param list<array> $tables
     * @return array{items: list<array{id:int|string,label:string,meta:string,permanent:bool}>, more: int, more_label: string}
     */
    public static function line(array $tables): array
    {
        $items = [];
        foreach (array_slice(array_values($tables), 0, self::LINE_MAX) as $t) {
            $permanent = !empty($t['permanent']);
            $anon      = $permanent && isset($t['anon_steps']);
            $view      = self::isView($t);
            $meta      = match (true) {
                // Une vue dont une colonne filtrée a disparu de sa table.
                ($anon || $view) && !empty($t['view_error']) => t('vue qui ne s’applique plus'),
                // Vue sans nom : « 5 lignes sur 40 · filtrée ». Compte du moment s'il est connu.
                $anon      => t('{lignes} sur {total}', ['lignes' => self::rowsLabel((int)($t['live_rows'] ?? $t['anon_rows'] ?? 0)), 'total' => Format::credits((int)$t['rows'])])
                    . ' · ' . t('filtrée'),
                $view      => self::rowsLabel((int)($t['live_rows'] ?? $t['origin']['lignes'] ?? 0)) . ' · ' . t('vue, toujours à jour'),
                $permanent => self::rowsLabel((int)$t['rows']) . ' · ' . t('table permanente'),
                default    => self::rowsLabel((int)$t['rows']) . ' · ' . t('résultat'),
            };
            $items[] = [
                'id'        => self::refOf($t),
                'label'     => self::label($t),
                // La vraie table d'abord : « Clients 2026 › Clients à relancer ».
                'parent'    => !$permanent ? trim((string)($t['parent'] ?? '')) : '',
                'meta'      => $meta,
                'permanent' => $permanent || $view,
            ];
        }
        $more = max(0, count($tables) - self::LINE_MAX);
        return [
            'items'      => $items,
            'more'       => $more,
            'more_label' => $more > 0 ? I18n::plural($more, '+ {n} autre table', '+ {n} autres tables') : '',
        ];
    }

    /** HTML de la ligne (le même gabarit que le rendu de la page), '' si rien à nommer. */
    public static function lineHtml(array $tables): string
    {
        if ($tables === []) {
            return '';
        }
        ob_start();
        try {
            $tableLine = self::line($tables);
            include ROOT . '/templates/partials/work-table-line.php';
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    // ==================================================================
    // Puce de la zone de saisie
    // ==================================================================

    /**
     * La puce : la table de la personne d'abord (« Clients 2026 · 2 résultats »),
     * jamais un compte qui mêle sa table et les listes de MIA.
     *
     * @param int          $results         résultats de MIA (tables de la conversation, vues comprises)
     * @param list<string> $permanentTitles titres des tables permanentes engagées
     * @return array{count:int,label:string,title:string}
     */
    public static function chip(int $results, array $permanentTitles = []): array
    {
        $results = max(0, $results);
        $titles  = array_values(array_filter(array_map(static fn($t): string => trim((string)$t), $permanentTitles), static fn(string $t): bool => $t !== ''));
        $perm    = count($titles);
        $count   = $results + $perm;
        $more    = $results > 0 ? I18n::plural($results, '{n} résultat', '{n} résultats') : '';
        $label   = match (true) {
            $perm === 1 => mb_strimwidth($titles[0], 0, 28, '…') . ($more !== '' ? ' · ' . $more : ''),
            $perm > 1   => I18n::plural($perm, '{n} table', '{n} tables') . ($more !== '' ? ' · ' . $more : ''),
            default     => $more,
        };
        return [
            'count' => $count,
            'label' => $label,
            'title' => $count > 0
                ? ($perm > 0
                    ? I18n::plural($perm, 'Voir votre table, et ce que MIA en a tiré', 'Voir vos {n} tables, et ce que MIA en a tiré')
                    : I18n::plural($results, 'Voir le résultat de MIA dans cette conversation', 'Voir les {n} résultats de MIA dans cette conversation'))
                : '',
        ];
    }

    // ==================================================================
    // Libellés
    // ==================================================================

    /** Pictogramme d'une table (grille) — le même partout : ligne, puce, volet. */
    public static function icon(int $size = 14): string
    {
        $size = max(10, min(40, $size));
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/></svg>';
    }

    /** Le nom qu'on lit : le titre, à défaut le nom court rendu lisible. Pure. */
    public static function label(array $table): string
    {
        $title = trim((string)($table['title'] ?? ''));
        if ($title !== '' && $title !== 'Table') {
            return $title;
        }
        $name = trim(str_replace('_', ' ', (string)($table['name'] ?? '')));
        return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : t('Table');
    }

    public static function rowsLabel(int $rows): string
    {
        return I18n::plural($rows, '{n} ligne', '{n} lignes', ['n' => Format::credits($rows)]);
    }

    /** D'où vient la table, en mots (colonne `origin_json`). '' si rien à dire. */
    public static function originLabel(array $table): string
    {
        $origin = is_array($table['origin'] ?? null) ? $table['origin'] : [];
        if (!empty($table['permanent'])) {
            return KbTableImport::originLabel($origin);
        }
        $str = static fn(string $k): string => is_scalar($origin[$k] ?? null) ? trim((string)$origin[$k]) : '';
        // Les titres d'abord (« Clients 2026 »), jamais le nom court (« clients_2026 »).
        $named = static fn(string $k): string => $str($k . '_titre') !== '' ? $str($k . '_titre') : $str($k);
        return match ($str('kind')) {
            'collecte'       => t(WorkTableCollector::label($str('source')))
                . ($str('requete') !== '' ? ' — ' . mb_strimwidth($str('requete'), 0, 60, '…') : ''),
            'saisie'         => t('Établie par MIA'),
            'croisement'     => t('Croisement de « {a} » et « {b} »', ['a' => $named('gauche'), 'b' => $named('droite')]),
            'transformation' => t('Tirée de « {table} »', ['table' => $named('depuis')]),
            'vue'            => t('Vue de « {table} »', ['table' => $str('titre_source')]),
            default          => '',
        };
    }

    /**
     * Titre saisi par la personne : une ligne, bornée. Pure.
     *
     * @throws \InvalidArgumentException titre vide
     */
    public static function cleanTitle(mixed $raw): string
    {
        $title = is_scalar($raw) ? (string)$raw : '';
        $title = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title);
        $title = trim((string)preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            throw new \InvalidArgumentException(t('Donnez un nom à la table.'));
        }
        return mb_substr($title, 0, WorkTableOps::TITLE_MAX);
    }

    // ==================================================================
    // Volet
    // ==================================================================

    /**
     * Colonnes montrées à la personne : les colonnes techniques
     * (identifiants de message, de fichier) ne lui disent rien — sauf si la
     * table n'a qu'elles. Pure.
     *
     * @return list<array{key:string,label:string,type:string,tech:bool}>
     */
    public static function visibleColumns(array $columns): array
    {
        $visible = array_values(array_filter($columns, static fn(array $c): bool => empty($c['tech'])));
        return $visible !== [] ? $visible : array_values($columns);
    }

    /** Mots d'un filtre, sans casse ni accents. Pure. @return list<string> */
    public static function filterWords(mixed $raw): array
    {
        $q = is_scalar($raw) ? trim((string)preg_replace('/\s+/u', ' ', (string)$raw)) : '';
        if ($q === '') {
            return [];
        }
        $words = [];
        foreach (explode(' ', mb_substr($q, 0, 120)) as $w) {
            $w = WorkTableOps::fold($w);
            if ($w !== '' && !in_array($w, $words, true)) {
                $words[] = $w;
            }
        }
        return array_slice($words, 0, self::FILTER_WORDS);
    }

    /**
     * Une ligne contient-elle TOUS les mots du filtre, dans ses colonnes
     * visibles ? Pure.
     *
     * @param list<string> $keys  clés des colonnes visibles
     * @param list<string> $words mots déjà normalisés (filterWords)
     */
    public static function rowMatches(array $row, array $keys, array $words): bool
    {
        $text = '';
        foreach ($keys as $k) {
            $text .= ' ' . (string)($row[$k] ?? '');
        }
        $text = WorkTableOps::fold($text);
        foreach ($words as $w) {
            if (!str_contains($text, $w)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Une page de lignes de la table, filtrée ou non. Avec un filtre, la table
     * est parcourue par tranches (jamais chargée entière) et le total des
     * lignes trouvées est exact : « 12 lignes sur 240 » se lit à l'écran.
     *
     * @return array{rows: list<array{n:int, cells:list<array{text:string,full:string,type:string,raw:string,key:string}>}>,
     *               page:int, pages:int, matched:int, total:int, from:int, to:int, q:string}
     */
    public static function page(WorkTableRows $store, array $table, mixed $q, int $page): array
    {
        $columns = self::visibleColumns((array)$table['columns']);
        $keys    = array_map(static fn(array $c): string => (string)$c['key'], $columns);
        $total   = (int)$table['rows'];
        $words   = self::filterWords($q);
        $page    = max(1, $page);
        $picked  = [];

        if ($words === []) {
            $matched = $total;
            $pages   = max(1, (int)ceil($total / self::PAGE_ROWS));
            $page    = min($page, $pages);
            $offset  = ($page - 1) * self::PAGE_ROWS;
            foreach ($store->rows((int)$table['id'], $offset, self::PAGE_ROWS) as $i => $row) {
                $picked[] = [$offset + $i + 1, $row];
            }
        } else {
            // Deux passages au plus : la page demandée peut ne plus exister
            // une fois le filtre appliqué (on retombe alors sur la dernière).
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $matched = 0;
                $picked  = [];
                $from    = ($page - 1) * self::PAGE_ROWS;
                for ($offset = 0; $offset < $total; $offset += self::SCAN_CHUNK) {
                    $chunk = $store->rows((int)$table['id'], $offset, self::SCAN_CHUNK);
                    if ($chunk === []) {
                        break;
                    }
                    foreach ($chunk as $i => $row) {
                        if (!self::rowMatches($row, $keys, $words)) {
                            continue;
                        }
                        if ($matched >= $from && count($picked) < self::PAGE_ROWS) {
                            $picked[] = [$offset + $i + 1, $row];
                        }
                        $matched++;
                    }
                }
                $pages = max(1, (int)ceil($matched / self::PAGE_ROWS));
                if ($page <= $pages) {
                    break;
                }
                $page = $pages;
            }
        }

        $rows = [];
        foreach ($picked as [$n, $row]) {
            $cells = [];
            foreach ($columns as $c) {
                $full = (string)($row[$c['key']] ?? '');
                $flat = trim((string)preg_replace('/\s+/u', ' ', $full));
                $cut  = mb_strlen($flat) > self::CELL_CHARS;
                $cells[] = [
                    'text' => $cut ? mb_substr($flat, 0, self::CELL_CHARS - 1) . '…' : $flat,
                    'full' => $cut ? $full : '',
                    'type' => (string)$c['type'],
                    // Valeur telle qu'elle est en base, et sa colonne : la grille
                    // MODIFIABLE d'une table permanente s'en sert (KbTableView).
                    'raw'  => $full,
                    'key'  => (string)$c['key'],
                ];
            }
            $rows[] = ['n' => (int)$n, 'cells' => $cells];
        }
        $first = ($page - 1) * self::PAGE_ROWS;
        return [
            'rows'    => $rows,
            'page'    => $page,
            'pages'   => $pages,
            'matched' => $matched,
            'total'   => $total,
            'from'    => $rows === [] ? 0 : $first + 1,
            'to'      => $first + count($rows),
            'q'       => is_scalar($q) ? trim(mb_substr((string)$q, 0, 120)) : '',
        ];
    }

    /**
     * Tout ce que montre le volet, en deux parties qu'on ne confond pas :
     *   - « Vos tables » : les tables PERMANENTES engagées, chacune suivie de
     *     ses vues nommées (lignes recalculées : toujours à jour) ;
     *   - « Résultats de MIA » : les listes établies dans la conversation
     *     (temporaires), « tirées de… », signalées périmées quand leur table
     *     d'origine a changé.
     * La table choisie (à défaut la première de « Vos tables », sinon le
     * résultat le plus récent) montre une page de lignes ; une vue y porte le
     * bandeau de ce qu'elle filtre, avec « Tout afficher ».
     *
     * Une table permanente s'y LIT (pages, filtre, exports) : elle se tient
     * dans sa fiche — ni renommée, ni supprimée, ni « gardée » d'ici.
     *
     * @param int|string         $selected      référence de la table choisie (identifiant, « p12 », « p12.5.<recette> »), 0 = aucune
     * @param bool               $busy          une opération de fond tourne sur les tables de cette conversation
     * @param list<array>        $permanent     tables permanentes engagées (KbTableStore::engaged)
     * @param WorkTableRows|null $permanentRows leurs lignes (KbTableStore)
     * @param bool               $canOpenKb     la personne peut ouvrir la fiche d'une base (lien « Ouvrir sa fiche »)
     * @param (\Closure(int): bool)|null $canWriteKb la personne peut-elle modifier la table permanente de cette base ?
     * @return array<string, mixed>
     */
    public static function panel(
        WorkTableStore $store,
        int $conversationId,
        int|string $selected,
        mixed $q,
        int $page,
        bool $busy = false,
        bool $canKeep = false,
        array $permanent = [],
        ?WorkTableRows $permanentRows = null,
        bool $canOpenKb = false,
        ?\Closure $canWriteKb = null,
    ): array {
        $work = $store->all($conversationId);
        $byKb = [];
        if ($permanentRows !== null) {
            foreach ($permanent as $t) {
                $byKb[(int)$t['kb_id']] = ['permanent' => true] + $t;
            }
        }
        // Lignes des tables permanentes, lues une fois pour toutes leurs vues.
        $cache   = [];
        $rowsOf  = static function (int $kb) use (&$cache, $permanentRows): ArrayTableRows {
            $cache[$kb] ??= $permanentRows !== null ? $permanentRows->rows($kb) : [];
            return new ArrayTableRows($cache[$kb]);
        };
        $viewOf  = static function (array $t) use ($byKb, $rowsOf): ?array {
            $kb = self::viewKb($t);
            return isset($byKb[$kb]) ? self::viewData($byKb[$kb], self::viewSteps($t), $rowsOf($kb)) : null;
        };

        // Ce qui est choisi : une table de la conversation, une table
        // permanente, ou une vue sans nom de celle-ci.
        $wanted = self::ref($selected);
        $anon   = self::anonRef($wanted);
        $chosen = null;
        if ($anon !== null && isset($byKb[$anon[0]])) {
            $chosen = $byKb[$anon[0]] + ['anon_steps' => $anon[2], 'anon_rows' => $anon[1]];
        }
        foreach (array_merge(array_values($byKb), $work) as $t) {
            if ($chosen === null && $wanted !== 0 && self::refOf($t) === $wanted) {
                $chosen = $t;
            }
        }
        $views   = array_values(array_filter($work, [self::class, 'isView']));
        $results = array_values(array_filter($work, static fn(array $t): bool => !self::isView($t)));
        if ($chosen === null) {
            $chosen = array_values($byKb)[0] ?? $results[0] ?? $views[0] ?? null;
        }
        $chosenRef = $chosen !== null ? self::refOf($chosen) : 0;
        // Une vue sans nom allume sa table dans la liste : c'est elle qu'on regarde, filtrée.
        $isChosen  = static fn(array $t): bool => self::refOf($t) === $chosenRef
            || ($chosen !== null && isset($chosen['anon_steps']) && !empty($t['permanent']) && (int)$t['kb_id'] === (int)$chosen['kb_id']);

        $item = static function (array $t, string $kind, string $meta, string $origin = '', string $stale = '') use ($isChosen): array {
            return [
                'id'        => self::refOf($t),
                'label'     => self::label($t),
                'meta'      => $meta,
                'origin'    => $origin,
                'selected'  => $isChosen($t),
                'permanent' => $kind !== 'result',
                'kind'      => $kind,
                'nested'    => $kind === 'view',
                'stale'     => $stale,
            ];
        };
        $colsLabel = static fn(array $t): string => I18n::plural(count(self::visibleColumns((array)$t['columns'])), '{n} colonne', '{n} colonnes');

        // ---- Vos tables, et leurs vues ----
        $mine   = [];
        $placed = [];
        foreach ($byKb as $kb => $t) {
            $mine[] = $item($t, 'permanent', self::rowsLabel((int)$t['rows']) . ' · ' . $colsLabel($t));
            foreach ($views as $v) {
                if (self::viewKb($v) !== $kb) {
                    continue;
                }
                $placed[(int)$v['id']] = true;
                $data = $viewOf($v);
                $mine[] = $item($v, 'view', $data !== null && $data['error'] === ''
                    ? t('{lignes} sur {total}', ['lignes' => self::rowsLabel(count($data['rows'])), 'total' => Format::credits((int)$t['rows'])]) . ' · ' . t('vue, toujours à jour')
                    : t('Cette vue ne s’applique plus'));
            }
        }

        // ---- Résultats de MIA (et vues dont la table n'est plus jointe) ----
        $resultItems = [];
        foreach ($results as $t) {
            $stale = self::staleBase($t, $byKb);
            $resultItems[] = $item($t, 'result', self::rowsLabel((int)$t['rows']) . ' · ' . $colsLabel($t), self::originLabel($t), $stale);
        }
        foreach ($views as $v) {
            if (!isset($placed[(int)$v['id']])) {
                $resultItems[] = $item($v, 'view', t('Vue d’une table qui n’est plus jointe à la conversation'), self::originLabel($v));
            }
        }

        $detail = null;
        if ($chosen !== null) {
            $detail = self::detail($conversationId, $chosen, $byKb, $rowsOf, $q, $page, $store, $canOpenKb, $canWriteKb, $permanentRows);
        }

        return [
            'conversation_id' => $conversationId,
            // `count` : les tables de la conversation (leur plafond) ;
            // `total` : avec les tables permanentes engagées.
            'count'           => count($work),
            'permanent_count' => count($byKb),
            'total'           => count($work) + count($byKb),
            'max'             => WorkTableOps::MAX_TABLES,
            'mine'            => $mine,
            'results'         => $resultItems,
            'tables'          => array_merge($mine, $resultItems),
            // Les résultats se déplient d'office quand la personne n'a pas de table à elle, ou en regarde un.
            'results_open'    => $mine === [] || ($detail !== null && $detail['kind'] === 'result'),
            'selected'        => $detail,
            'busy'            => $busy,
            // « Garder » : en faire une table permanente (droit de créer une base).
            'can_keep'        => $canKeep,
        ];
    }

    /**
     * Le détail de la table choisie : page de lignes, liens d'export, et ce
     * qui la distingue — bandeau d'une vue, avertissement d'un résultat
     * périmé, ce que « Garder » ferait d'un résultat tiré d'une table
     * permanente.
     *
     * @param array<int, array> $byKb
     * @param \Closure(int): ArrayTableRows $rowsOf
     */
    private static function detail(int $conversationId, array $chosen, array $byKb, \Closure $rowsOf, mixed $q, int $page,
        WorkTableStore $store, bool $canOpenKb, ?\Closure $canWriteKb, ?WorkTableRows $permanentRows): array
    {
        $isPermanent = !empty($chosen['permanent']);
        $isView      = self::isView($chosen);
        $isAnon      = $isPermanent && isset($chosen['anon_steps']);
        $kind        = $isView ? 'view' : ($isPermanent ? ($isAnon ? 'view' : 'permanent') : 'result');
        $kb          = $isPermanent ? (int)($chosen['kb_id'] ?? $chosen['id']) : self::viewKb($chosen);
        $source      = $byKb[$kb] ?? null;

        $view  = null;
        $table = $chosen;
        $rows  = null;
        if ($isView || $isAnon) {
            $data = $source !== null
                ? self::viewData($source, $isAnon ? (array)$chosen['anon_steps'] : self::viewSteps($chosen), $rowsOf($kb))
                : ['columns' => (array)$chosen['columns'], 'rows' => [], 'steps' => [], 'error' => t('La table « {table} » n’est plus jointe à cette conversation : cochez-la à nouveau pour lire cette vue.', [
                    'table' => (string)($chosen['origin']['titre_source'] ?? ''),
                ])];
            $table = ['id' => 0, 'columns' => $data['columns'], 'rows' => count($data['rows'])] + $chosen;
            $rows  = new ArrayTableRows(WorkTableRecipe::strip($data['rows']));
            $view  = [
                'source'     => (string)($source['title'] ?? ($chosen['origin']['titre_source'] ?? '')),
                'source_ref' => $source !== null ? 'p' . $kb : '',
                'what'       => WorkTableRecipe::describe((array)($source['columns'] ?? []), $data['steps']),
                'total'      => (int)($source['rows'] ?? 0),
                'error'      => $data['error'],
                'anon'       => $isAnon,
                'code'       => WorkTableRecipe::encode($data['steps']),
            ];
        } elseif ($isPermanent) {
            // Table entière : lue page par page dans sa base, jamais chargée d'un coup.
            $rows = $permanentRows;
        }
        $columns = self::visibleColumns((array)$table['columns']);
        $label   = $isAnon ? (string)$chosen['title'] : self::label($chosen);
        $link    = static function (string $ext) use ($isPermanent, $isView, $isAnon, $view, $conversationId, $kb, $chosen, $label): string {
            $file = WorkTableExport::fileName(['title' => $label] + $chosen, $label, $ext);
            if ($isView || $isAnon) {
                return WorkTableExport::permanentLink($conversationId, $kb, $file, (string)($view['code'] ?? ''));
            }
            return $isPermanent
                ? WorkTableExport::permanentLink($conversationId, $kb, $file)
                : WorkTableExport::link($conversationId, (int)$chosen['id'], $file);
        };

        // Résultat tiré d'une table permanente : périmé ? « Garder » le dit, et
        // propose d'AJOUTER à cette table les colonnes qu'elle n'a pas.
        $stale   = $kind === 'result' ? self::staleBase($chosen, $byKb) : '';
        $derived = '';
        $update  = null;
        if ($kind === 'result') {
            foreach (self::basesOf($chosen) as $b) {
                $base    = $byKb[$b['kb']] ?? null;
                $derived = (string)($base['title'] ?? $b['titre']);
                if ($base !== null && $canWriteKb !== null && $canWriteKb($b['kb'])) {
                    $missing = self::missingColumns((array)$base['columns'], (array)$chosen['columns']);
                    if ($missing !== []) {
                        $update = [
                            'kb'    => $b['kb'],
                            'label' => I18n::plural(count($missing), 'Ajouter la colonne {noms} à « {table} »', 'Ajouter les colonnes {noms} à « {table} »', [
                                'noms'  => I18n::listeCitee(array_slice($missing, 0, 3)),
                                'table' => (string)$base['title'],
                            ]),
                        ];
                    }
                }
                break;
            }
        }

        return [
            'id'        => self::refOf($chosen),
            'kind'      => $kind,
            'label'     => $label,
            'meta'      => self::rowsLabel((int)$table['rows']) . ' · ' . I18n::plural(count($columns), '{n} colonne', '{n} colonnes'),
            'origin'    => $isAnon ? '' : self::originLabel($chosen),
            'columns'   => array_map(static fn(array $c): array => ['label' => (string)$c['label'], 'type' => (string)$c['type']], $columns),
            'page'      => self::page($rows ?? $store, $table, $q, $page),
            'empty'     => (int)$table['rows'] === 0,
            'xlsx'      => $link('xlsx'),
            'csv'       => $link('csv'),
            'permanent' => $isPermanent && !$isAnon,
            'view'      => $view,
            'stale'     => $stale,
            'derived'   => $derived,
            'update'    => $update,
            // Renommer, supprimer : ce qui vit dans la conversation (résultat, vue nommée).
            'own'       => !$isPermanent,
            // Fiche de la table permanente (Bases de connaissances), si la personne peut l'ouvrir.
            'kb_url'    => $kb > 0 && $canOpenKb && ($isPermanent || $source !== null) ? '/admin/knowledge-bases/' . $kb . '/edit' : '',
        ];
    }

    /**
     * Colonnes d'un résultat qu'une table permanente n'a pas (comparées par
     * libellé, sans casse ni accents ; colonnes techniques exclues). Pure.
     *
     * @return list<string> libellés
     */
    public static function missingColumns(array $permanentColumns, array $resultColumns): array
    {
        $have = [];
        foreach ($permanentColumns as $c) {
            $have[WorkTableOps::fold((string)$c['label'])] = true;
            $have[WorkTableOps::fold((string)$c['key'])]   = true;
        }
        $out = [];
        foreach (self::visibleColumns($resultColumns) as $c) {
            if (empty($c['tech']) && !isset($have[WorkTableOps::fold((string)$c['label'])]) && !isset($have[WorkTableOps::fold((string)$c['key'])])) {
                $out[] = (string)$c['label'];
            }
        }
        return $out;
    }

    /**
     * Colonne qui désigne une même ligne dans la table permanente ET dans un
     * résultat : présente des deux côtés (même libellé), renseignée et sans
     * doublon dans la table permanente, et retrouvée pour au moins une ligne
     * du résultat. Les identifiants d'abord (email, référence, code, nom).
     * Rend [clé dans la table permanente, clé dans le résultat], ou null. Pure.
     *
     * @return array{0:string, 1:string}|null
     */
    public static function matchKey(array $permanentColumns, array $permanentRows, array $resultColumns, array $resultRows): ?array
    {
        $byLabel = [];
        foreach (self::visibleColumns($resultColumns) as $c) {
            $byLabel[WorkTableOps::fold((string)$c['label'])] = (string)$c['key'];
        }
        $candidates = [];
        foreach (self::visibleColumns($permanentColumns) as $c) {
            $fold = WorkTableOps::fold((string)$c['label']);
            if (!isset($byLabel[$fold])) {
                continue;
            }
            $rank = preg_match('/\b(e ?mail|courriel|ref|reference|code|id|identifiant|siret|siren|numero|matricule)\b/u', $fold) === 1 ? 0
                : (preg_match('/\b(nom|name|societe|client|raison sociale)\b/u', $fold) === 1 ? 1 : 2);
            $candidates[] = [$rank, (string)$c['key'], $byLabel[$fold]];
        }
        usort($candidates, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($candidates as [, $permKey, $resKey]) {
            $seen = [];
            $ok   = $permanentRows !== [];
            foreach ($permanentRows as $r) {
                $v = WorkTableOps::fold((string)($r[$permKey] ?? ''));
                if ($v === '' || isset($seen[$v])) {
                    $ok = false;
                    break;
                }
                $seen[$v] = true;
            }
            if (!$ok) {
                continue;
            }
            foreach ($resultRows as $r) {
                if (isset($seen[WorkTableOps::fold((string)($r[$resKey] ?? ''))])) {
                    return [$permKey, $resKey];
                }
            }
        }
        return null;
    }

    /** HTML du volet (le même gabarit, quel que soit l'appelant). */
    public static function panelHtml(array $view): string
    {
        ob_start();
        try {
            $tablesPanel = $view;
            include ROOT . '/templates/partials/work-tables-panel.php';
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }
}
