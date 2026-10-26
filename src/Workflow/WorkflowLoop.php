<?php

declare(strict_types=1);

/**
 * Boucles d'un workflow — pur.
 *
 * Un tour de boucle rejoue le corps avec ses PROPRES pièces : la brique
 * `redaction` au tour 3 a pour clé de passage `redaction#3`, et sa pièce est
 * rangée au dossier sous `memoire#3`. Ce module dit :
 *  - comment se forment ces clés (iterKey / splitIter) ;
 *  - quels sont les éléments d'une boucle « pour chaque » (items) ;
 *  - ce que voit une brique au tour n (view) : la pièce `memoire` y désigne
 *    `memoire#n`, l'élément en cours `element#n` ;
 *  - comment se rassemblent les résultats en UNE pièce (aggregate).
 */
final class WorkflowLoop
{
    public const SEP = '#';

    public static function iterKey(string $key, ?int $i): string
    {
        return $i !== null && $i > 0 ? $key . self::SEP . $i : $key;
    }

    /** « redaction#3 » → ['redaction', 3] ; « redaction » → ['redaction', null]. */
    public static function splitIter(string $key): array
    {
        if (preg_match('/^(.+)' . self::SEP . '(\d{1,3})$/', $key, $m)) {
            return [$m[1], (int)$m[2]];
        }
        return [$key, null];
    }

    /**
     * Éléments d'une boucle « pour chaque » : les puces d'une liste, les lignes
     * d'un tableau (en-tête retiré, chaque ligne rendue « Colonne : valeur »),
     * ou les valeurs d'un champ liste de Fiche. Bornés à `$max` ; `total` dit
     * combien il y en avait.
     *
     * @return array{items: list<string>, total: int}
     */
    public static function items(array $piece, array $content, string $field, int $max): array
    {
        $all = [];
        switch ($piece['form'] ?? '') {
            case 'list':
                $all = array_values(array_map('strval', (array)($content['items'] ?? [])));
                break;
            case 'table':
                $rows = (array)($content['rows'] ?? []);
                $head = array_map('strval', (array)($rows[0] ?? []));
                foreach (array_slice($rows, 1) as $row) {
                    $cells = [];
                    foreach (array_values((array)$row) as $i => $cell) {
                        $cell = trim((string)$cell);
                        if ($cell === '') {
                            continue;
                        }
                        $label = trim($head[$i] ?? '');
                        $cells[] = $label !== '' ? $label . ' : ' . $cell : $cell;
                    }
                    if ($cells !== []) {
                        $all[] = implode(' — ', $cells);
                    }
                }
                break;
            case 'fiche':
                $v = $content['fields'][$field] ?? null;
                $all = is_array($v) ? array_values(array_map('strval', $v)) : (is_string($v) && trim($v) !== '' ? (WorkflowValues::listOf($v) ?? []) : []);
                break;
        }
        $all = array_values(array_filter(array_map(static fn(string $s): string => trim($s), $all), static fn(string $s): bool => $s !== ''));
        $items = array_map(static fn(string $s): string => mb_substr($s, 0, WorkflowSpec::ITEM_MAX), array_slice($all, 0, max(1, $max)));
        return ['items' => $items, 'total' => count($all)];
    }

    /** Texte replié pour comparer des noms : minuscules, sans accents. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae']);
    }

    /** Libellé court d'un élément (titres de section, première colonne d'un tableau). */
    public static function label(string $item, int $max = 80): string
    {
        $line = trim((string)strtok(str_replace("\r", '', $item), "\n"));
        return mb_strlen($line) > $max ? rtrim(mb_substr($line, 0, $max - 1)) . '…' : $line;
    }

    /**
     * Ce que voient les briques du tour `$i` d'une boucle : les pièces du
     * corps (et l'élément en cours) désignées par leur clé simple. Une pièce du
     * corps que ce tour n'a pas encore remise n'y est pas — sauf `$previous`
     * (boucle « jusqu'à » : la version du tour d'avant, sous la clé simple,
     * pour l'étape qui la reprend).
     *
     * @param array<string, array> $artifacts tout le dossier (clés « piece#n » comprises)
     * @param list<string> $bodyKeys clés des pièces du corps et de l'élément
     * @return array<string, array>
     */
    public static function view(array $artifacts, array $bodyKeys, int $i): array
    {
        $out = [];
        foreach ($artifacts as $k => $a) {
            [, $iter] = self::splitIter((string)$k);
            if ($iter === null && !in_array($k, $bodyKeys, true)) {
                $out[$k] = $a;
            }
        }
        foreach ($bodyKeys as $k) {
            $hit = $artifacts[self::iterKey($k, $i)] ?? null;
            if ($hit !== null) {
                $out[$k] = $hit;
            }
        }
        return $out;
    }

    /** Clés des pièces vues par le corps d'une boucle : élément en cours et pièces de ses étapes et créations. @return list<string> */
    public static function bodyPieceKeys(array $loop): array
    {
        $keys = [];
        if (($loop['mode'] ?? '') === WorkflowSpec::LOOP_EACH) {
            $keys[] = $loop['item']['key'];
        }
        foreach ($loop['body'] ?? [] as $child) {
            if (in_array($child['type'], WorkflowSpec::PRODUCING_TYPES, true)) {
                $keys[] = $child['output']['key'];
            }
        }
        return $keys;
    }

    /** Issue d'un élément : traité, écarté (filtre ou condition), en échec, brique ignorée. */
    public const DONE    = 'done';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';
    public const EMPTY   = 'empty';

    /** Libellé de l'issue d'un élément (colonne Statut, page de l'exécution). */
    public static function statusLabel(string $status, string $note = ''): string
    {
        $label = match ($status) {
            self::SKIPPED => t('écarté'),
            self::FAILED  => t('en échec'),
            self::EMPTY   => t('rien remis'),
            default       => t('traité'),
        };
        return $note !== '' && $status !== self::DONE ? $label . ' — ' . $note : $label;
    }

    /**
     * Les éléments gardés par le filtre d'une boucle : une règle sur leurs
     * champs (WorkflowValues::evaluate, sans IA), sans doublon sur un champ,
     * sans élément dont le nom est vide (une note en bas d'un classeur) ni
     * ligne de total d'un tableau (« Total », « Sous-total », « Total TTC »).
     * Rend aussi ce qui a été écarté, par motif.
     *
     * @param list<array> $elements {label, fields, …}
     * @return array{kept: list<array>, ignored: array{filter: int, dup: int, label: int}}
     */
    public static function filterElements(array $loop, array $itemPiece, array $elements): array
    {
        $filter = (array)($loop['filter'] ?? []);
        $rule = (array)($filter['rule'] ?? []);
        $dedupe = (string)($filter['dedupe'] ?? '');
        $requireLabel = array_key_exists('require_label', $filter) ? (bool)$filter['require_label'] : true;
        $itemKey = (string)$itemPiece['key'];
        $fieldTypes = array_column((array)($itemPiece['fields'] ?? []), 'type', 'key');
        $kept = [];
        $seen = [];
        $ignored = ['filter' => 0, 'dup' => 0, 'label' => 0];
        foreach ($elements as $e) {
            if ($requireLabel && (trim((string)($e['label'] ?? '')) === '' || (isset($e['cells']) && self::isTotalRow((string)$e['label'])))) {
                $ignored['label']++;
                continue;
            }
            if (($rule['items'] ?? []) !== []) {
                $eval = WorkflowValues::evaluate($rule, [$itemKey => $itemPiece], [$itemKey => ['fields' => (array)($e['fields'] ?? [])]]);
                if (!$eval['result']) {
                    $ignored['filter']++;
                    continue;
                }
            }
            if ($dedupe !== '' && isset($fieldTypes[$dedupe])) {
                $v = $e['fields'][$dedupe] ?? null;
                $norm = mb_strtolower(trim(is_array($v) ? implode(',', $v) : (string)$v));
                if ($norm !== '') {
                    if (isset($seen[$norm])) {
                        $ignored['dup']++;
                        continue;
                    }
                    $seen[$norm] = true;
                }
            }
            $kept[] = $e;
        }
        return ['kept' => $kept, 'ignored' => $ignored];
    }

    /** Une ligne de total d'un tableau : « Total », « Sous-total », « Total TTC », « Grand total »… */
    public static function isTotalRow(string $label): bool
    {
        $l = trim((string)preg_replace('/[^a-z0-9]+/', ' ', self::fold($label)));
        return (bool)preg_match('/^(?:grand |sous )?(?:total|totaux|somme)(?: (?:general|generaux|ht|ttc|tva|du mois|mensuel|annuel|des montants))?$/', $l);
    }

    /**
     * Contenu de la pièce « élément en cours » d'un tour : un texte, ou une
     * Fiche (ses champs, un résumé de ce qui n'est pas un champ, son fichier).
     */
    public static function elementContent(array $shape, array $element): array
    {
        $text = mb_substr((string)($element['text'] ?? $element['label'] ?? ''), 0, WorkflowSpec::ITEM_MAX);
        if ($shape['form'] !== 'fiche') {
            return ['text' => $text !== '' ? $text : (string)($element['label'] ?? '')];
        }
        $fields = [];
        foreach ($shape['fields'] as $f) {
            $fields[$f['key']] = WorkflowValues::coerce($element['fields'][$f['key']] ?? null, (string)$f['type']);
        }
        $out = ['fields' => $fields, 'summary' => $text];
        if (is_array($element['file'] ?? null)) {
            $out['file'] = $element['file'];
        }
        return $out;
    }

    /**
     * Rassemble les résultats d'une boucle en UNE pièce, selon la forme de la
     * pièce rassemblée (WorkflowSpec::loopOutputPiece).
     *
     * @param array $out pièce rassemblée ({form, fields, format})
     * @param ?array $src pièce suivie dans le corps ({form, fields, format}), null si aucune
     * @param list<array{item: string, content: ?array, note?: string, status?: string, cells?: list<string>}> $results un par tour, dans l'ordre
     * @param list<string> $origin en-tête des colonnes D'ORIGINE (lignes d'un tableau
     *        ou d'un classeur parcouru) : le tableau rassemblé les garde en tête,
     *        avant les champs remis — « compléter mon fichier »
     */
    public static function aggregate(array $out, ?array $src, array $results, array $origin = []): array
    {
        // Une colonne Statut dès qu'un élément n'a pas été traité.
        $withStatus = array_filter($results, static fn(array $r): bool => ($r['status'] ?? self::DONE) !== self::DONE) !== [];
        if (($out['until'] ?? false) === true) {
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if ($results[$i]['content'] !== null) {
                    return $results[$i]['content'];
                }
            }
            return self::emptyContent((string)$out['form']);
        }
        $form = (string)$out['form'];
        switch ($form) {
            case 'table':
                if (($src['form'] ?? '') === 'fiche') {
                    $fields = (array)($src['fields'] ?? []);
                    $head = $origin !== [] ? $origin : [t('Élément')];
                    $rows = [array_merge($head, array_map(static fn(array $f): string => (string)$f['label'], $fields), $withStatus ? [t('Statut')] : [])];
                    foreach ($results as $r) {
                        $row = $origin !== []
                            ? array_map(static fn(int $i): string => (string)(($r['cells'] ?? [])[$i] ?? ''), array_keys($origin))
                            : [self::label($r['item'])];
                        foreach ($fields as $f) {
                            // Sans pièce, la cause va dans la colonne Statut, pas dans chaque champ.
                            $row[] = $r['content'] !== null
                                ? WorkflowValues::display($r['content']['fields'][$f['key']] ?? null, (string)$f['type'])
                                : ($origin !== [] || $withStatus ? '' : (string)($r['note'] ?? '—'));
                        }
                        if ($withStatus) {
                            $row[] = self::statusLabel((string)($r['status'] ?? self::DONE), (string)($r['note'] ?? ''));
                        }
                        $rows[] = $row;
                    }
                    return ['rows' => $rows];
                }
                $rows = [];
                foreach ($results as $r) {
                    $part = $r['content'] !== null ? self::rowsOf($src ?? [], $r['content']) : [];
                    if ($part === []) {
                        continue;
                    }
                    if ($rows === []) {
                        $rows = $part;
                    } else {
                        foreach (array_slice($part, 1) as $row) {
                            $rows[] = $row;
                        }
                    }
                }
                return ['rows' => $rows];

            case 'list':
                $items = [];
                foreach ($results as $r) {
                    foreach ((array)($r['content']['items'] ?? []) as $it) {
                        $items[] = (string)$it;
                    }
                }
                return ['items' => array_slice($items, 0, 500)];

            case 'document':
                $parts = [];
                foreach ($results as $r) {
                    $body = $r['content'] !== null ? trim((string)($r['content']['source'] ?? '')) : '_' . self::statusLabel((string)($r['status'] ?? self::EMPTY), (string)($r['note'] ?? '')) . '_';
                    $parts[] = '## ' . self::label($r['item']) . "\n\n" . $body;
                }
                $format = (string)($out['format'] ?? 'docx');
                return [
                    'format'   => $format,
                    'filename' => ArtifactContract::filename(['name' => (string)($out['name'] ?? 'document'), 'format' => $format]),
                    'language' => 'markdown',
                    'source'   => implode("\n\n", $parts),
                ];

            default:
                $parts = [];
                foreach ($results as $r) {
                    $body = $r['content'] !== null && $src !== null
                        ? ArtifactContract::render($src, $r['content'], 20000)
                        : '_' . self::statusLabel((string)($r['status'] ?? self::EMPTY), (string)($r['note'] ?? '')) . '_';
                    $parts[] = '### ' . self::label($r['item']) . "\n\n" . trim($body);
                }
                return ['text' => implode("\n\n", $parts)];
        }
    }

    /** Lignes d'un tableau, ou d'un classeur Excel écrit en CSV. @return list<list<string>> */
    private static function rowsOf(array $src, array $content): array
    {
        if (isset($content['rows'])) {
            return array_values((array)$content['rows']);
        }
        if (($src['form'] ?? '') === 'document' && isset($content['source'])) {
            try {
                return TabularData::parse((string)$content['source'])->rows();
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    public static function emptyContent(string $form): array
    {
        return match ($form) {
            'list'     => ['items' => []],
            'table'    => ['rows' => []],
            'fiche'    => ['fields' => [], 'summary' => ''],
            'document' => ['format' => 'docx', 'filename' => 'document.docx', 'language' => 'markdown', 'source' => ''],
            default    => ['text' => ''],
        };
    }
}
