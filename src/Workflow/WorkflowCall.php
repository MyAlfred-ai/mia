<?php

declare(strict_types=1);

/**
 * Brique « Appeler un workflow » — SOURCE UNIQUE de ce qui lui est propre.
 *
 * Un workflow en appelle un autre de la même personne, comme on appelle une
 * fonction : ses PARAMÈTRES remplissent le départ de l'appelé (un texte qui
 * cite le dossier ; un champ Fichier reçoit une pièce), l'exécution ATTEND
 * qu'il se termine, puis récupère une pièce de son livrable — sous sa forme
 * (Fiche avec ses champs, document, image…) — ou tout son livrable en texte.
 *
 * Trois parties :
 *  - SIGNATURE : ce qu'un workflow publié expose à qui l'appelle (champs du
 *    départ, pièce d'évènement, pièces du livrable, workflows qu'il appelle
 *    lui-même). Calculée une fois par WorkflowCatalog::choices, lue par
 *    l'éditeur et par « À régler » ;
 *  - CONTRÔLES (purs) : paramètre obligatoire sans valeur, valeur qui n'est
 *    pas de la bonne nature (un texte là où il faut un nombre, une Fiche là
 *    où il faut une date…), workflow disparu, en pause ou pas publié, appels
 *    en boucle (A appelle B qui appelle A), trop de niveaux, pièce récupérée
 *    qui a changé. Les mêmes points bloquent la publication ET le lancement
 *    (WorkflowSpec::preflight) ;
 *  - EXÉCUTION : valeurs des paramètres lues dans le dossier, fichiers
 *    fabriqués, contenu de la pièce récupérée. Le lancement de l'appelé, son
 *    attente et sa fin sont l'affaire de WorkflowEngine.
 */
final class WorkflowCall
{
    /** Niveaux d'appels imbriqués, au plus (A → B → C → D). */
    public const DEPTH_MAX = 3;
    /** Clé du paramètre qui tient lieu de l'évènement de l'appelé (email reçu, fichier, données). */
    public const EVENT_PARAM = WorkflowSpec::EVENT_PIECE;
    /** Texte d'un paramètre une fois rempli (caractères) : une pièce entière peut y passer. */
    public const TEXT_MAX = 30000;
    /** `trigger_kind` d'une exécution lancée par un appel. */
    public const TRIGGER = 'call';

    // ==================================================================
    // Signature
    // ==================================================================

    /**
     * Ce qu'un workflow expose à qui l'appelle. `published` faux : rien
     * d'autre n'est connu (seule la version publiée s'exécute).
     *
     * @return array{name: string, color: string, published: bool, active: bool, fields: list<array>, event: ?array, outputs: list<array>, calls: list<int>}
     */
    public static function signature(array $workflow): array
    {
        $def = $workflow['published'] ?? null;
        $sig = [
            'name'      => (string)($workflow['name'] ?? ''),
            'color'     => (string)($workflow['color'] ?? ''),
            'published' => is_array($def),
            'active'    => !empty($workflow['is_active']),
            'fields'    => [],
            'event'     => null,
            'outputs'   => [],
            'calls'     => [],
            'silhouette' => '',
        ];
        if (!is_array($def)) {
            return $sig;
        }
        foreach ($def['start']['fields'] as $f) {
            $sig['fields'][] = [
                'key' => (string)$f['key'], 'label' => (string)$f['label'], 'type' => (string)$f['type'],
                'required' => !empty($f['required']), 'options' => array_values((array)($f['options'] ?? [])),
                'default' => (string)($f['default'] ?? ''), 'help' => (string)($f['help'] ?? ''),
                'reference' => !empty($f['reference']),
            ];
        }
        if (is_array($def['start']['event'] ?? null)) {
            $piece = WorkflowSpec::eventPiece($def['start']['event']);
            $sig['event'] = ['kind' => (string)$def['start']['event']['kind'], 'name' => (string)$piece['name'], 'form' => (string)$piece['form']];
        }
        $pieces = WorkflowSpec::pieces($def);
        foreach ($def['end']['pieces'] as $k) {
            if (!isset($pieces[$k])) {
                continue;
            }
            $p = $pieces[$k];
            $sig['outputs'][] = [
                'key' => (string)$p['key'], 'name' => (string)$p['name'], 'form' => (string)$p['form'],
                'fields' => array_map(static fn(array $f): array => ['key' => (string)$f['key'], 'label' => (string)$f['label'], 'type' => (string)$f['type']], (array)($p['fields'] ?? [])),
                'format' => $p['format'] ?? null,
            ];
        }
        $sig['calls'] = self::callsOf($def);
        $sig['silhouette'] = WorkflowSpec::silhouette($def);
        return $sig;
    }

    /** Briques d'appel d'une définition (rail, voies, corps de boucle). @return list<array> */
    public static function callNodes(array $def): array
    {
        return array_values(array_filter(WorkflowSpec::flatNodes($def), static fn(array $n): bool => $n['type'] === WorkflowSpec::TYPE_CALL));
    }

    /** Workflows qu'une définition appelle. @return list<int> */
    public static function callsOf(array $def): array
    {
        $ids = [];
        foreach (self::callNodes($def) as $n) {
            if ($n['workflow_id'] !== null && !in_array($n['workflow_id'], $ids, true)) {
                $ids[] = (int)$n['workflow_id'];
            }
        }
        return $ids;
    }

    /**
     * Chemin d'appels de `$from` jusqu'à `$target`, d'après les versions
     * publiées (`calls` des signatures), ou null s'il n'y en a pas.
     *
     * @param array<int, array> $workflows id → signature
     * @return list<int>|null [from, …, target]
     */
    public static function cyclePath(array $workflows, int $from, int $target, array $seen = []): ?array
    {
        if ($from === $target) {
            return [$from];
        }
        if (isset($seen[$from])) {
            return null;
        }
        $seen[$from] = true;
        foreach ((array)($workflows[$from]['calls'] ?? []) as $next) {
            $path = self::cyclePath($workflows, (int)$next, $target, $seen);
            if ($path !== null) {
                return [$from, ...$path];
            }
        }
        return null;
    }

    /** Niveaux d'appels SOUS un workflow : 0 s'il n'appelle rien. Un cycle ne compte qu'une fois. */
    public static function depthBelow(array $workflows, int $id, array $seen = []): int
    {
        if (isset($seen[$id])) {
            return 0;
        }
        $seen[$id] = true;
        $max = 0;
        foreach ((array)($workflows[$id]['calls'] ?? []) as $next) {
            $max = max($max, 1 + self::depthBelow($workflows, (int)$next, $seen));
        }
        return $max;
    }

    // ==================================================================
    // À régler
    // ==================================================================

    /**
     * Points « À régler » d'une brique d'appel (WorkflowSpec::issues). Cibles :
     * `workflow`, `output`, `params`, et `param:<clé>` pour ce qui tient à UN
     * paramètre — l'éditeur pose l'alerte sous son champ.
     * `$ctx['workflows']` : id → signature ; `$ctx['self_id']` : le workflow
     * édité. Sans ces deux-là (contrôle hors contexte), seuls les points
     * lisibles dans la définition sont rendus.
     */
    public static function issues(array $def, array $node, array $ctx, array &$names, array $available, callable $add): void
    {
        $k = (string)$node['key'];
        $block = WorkflowSpec::LEVEL_BLOCK;
        $warn = WorkflowSpec::LEVEL_WARN;

        $o = $node['output'];
        if ($o['name'] === '') {
            $add($k, 'output', $block, t('Nommez la pièce que l’appel remet.'));
        } elseif (isset($names[mb_strtolower($o['name'])])) {
            $add($k, 'output', $block, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $o['name']]));
        } else {
            $names[mb_strtolower($o['name'])] = true;
        }

        $pieces = WorkflowSpec::pieces($def);
        $cited = [];
        foreach ($node['params'] as $pk => $value) {
            foreach (WorkflowSpec::mentions((string)$value) as $m) {
                if (!isset($available[$m]) && !isset($cited[$m])) {
                    $cited[$m] = true;
                    $add($k, 'param:' . $pk, $block, isset($pieces[$m])
                        ? t('Un paramètre cite « {nom} », qui n’existe pas encore à ce point.', ['nom' => $pieces[$m]['name']])
                        : t('Un paramètre cite une pièce qui n’existe plus.'));
                }
            }
        }

        $id = $node['workflow_id'];
        if ($id === null) {
            $add($k, 'workflow', $block, t('Choisissez le workflow à appeler.'));
            return;
        }
        $self = (int)($ctx['self_id'] ?? 0);
        if ($self > 0 && $id === $self) {
            $add($k, 'workflow', $block, t('Un workflow ne peut pas s’appeler lui-même.'));
            return;
        }
        if (!isset($ctx['workflows'])) {
            return;
        }
        $w = $ctx['workflows'][$id] ?? null;
        if (!is_array($w)) {
            $add($k, 'workflow', $block, t('Le workflow appelé n’existe plus : choisissez-en un autre.'));
            return;
        }
        $name = (string)$w['name'];
        if (empty($w['published'])) {
            $add($k, 'workflow', $block, t('« {nom} » n’est pas encore publié : publiez-le pour pouvoir l’appeler.', ['nom' => $name]));
            return;
        }
        if (array_key_exists('active', $w) && !$w['active']) {
            $add($k, 'workflow', $block, t('« {nom} » est en pause : réactivez-le pour pouvoir l’appeler.', ['nom' => $name]));
        }
        if ($self > 0) {
            $path = self::cyclePath($ctx['workflows'], $id, $self);
            if ($path !== null) {
                // Le chemin finit par ce workflow lui-même : « ce workflow », dans la phrase.
                $chain = implode(' → ', array_map(static fn(int $i): string => '« ' . (string)($ctx['workflows'][$i]['name'] ?? '?') . ' »', array_slice($path, 0, -1)));
                $add($k, 'workflow', $block, t('Appels en boucle : {chaine} appelle déjà ce workflow. Ils se relanceraient sans fin.', ['chaine' => $chain]));
            }
        }
        if (1 + self::depthBelow($ctx['workflows'], $id) > self::DEPTH_MAX) {
            $add($k, 'workflow', $block, t('Trop de workflows imbriqués : « {nom} » en appelle déjà d’autres, et {n} niveaux d’appels sont permis au plus.', ['nom' => $name, 'n' => self::DEPTH_MAX]));
        }
        $event = $def['start']['event'] ?? null;
        if (is_array($event) && $event['kind'] === 'workflow' && (int)($event['workflow_id'] ?? 0) === $id) {
            $add($k, 'workflow', $block, t('Ce workflow démarre quand « {nom} » se termine : s’il l’appelle aussi, il se relancera lui-même.', ['nom' => $name]));
        }

        // Paramètres : chaque champ du départ de l'appelé.
        $known = [];
        foreach ((array)($w['fields'] ?? []) as $f) {
            $known[(string)$f['key']] = true;
            $label = (string)$f['label'] !== '' ? (string)$f['label'] : (string)$f['key'];
            $value = trim((string)($node['params'][$f['key']] ?? ''));
            if ($value === '') {
                $hasDefault = $f['type'] === 'file' ? !empty($f['reference']) : (string)($f['default'] ?? '') !== '';
                if (!empty($f['required']) && !$hasDefault) {
                    $add($k, 'param:' . $f['key'], $block, $f['type'] === 'file'
                        ? t('« {nom} » a besoin du fichier « {champ} » : donnez-lui une pièce du dossier.', ['nom' => $name, 'champ' => $label])
                        : t('« {nom} » a besoin de « {champ} » : donnez-lui une valeur.', ['nom' => $name, 'champ' => $label]));
                }
                continue;
            }
            foreach (self::paramIssues($f, $value, $available) as [$level, $text]) {
                $add($k, 'param:' . $f['key'], $level, $text);
            }
        }
        if (is_array($w['event'] ?? null)) {
            $known[self::EVENT_PARAM] = true;
            $value = trim((string)($node['params'][self::EVENT_PARAM] ?? ''));
            if ($value === '') {
                $add($k, 'param:' . self::EVENT_PARAM, $warn, t('« {nom} » démarre d’habitude sur un évènement : sans rien à la place, sa pièce « {piece} » sera vide.', ['nom' => $name, 'piece' => (string)$w['event']['name']]));
            } elseif ($w['event']['form'] === 'file') {
                foreach (self::paramIssues(['key' => self::EVENT_PARAM, 'label' => (string)$w['event']['name'], 'type' => 'file'], $value, $available) as [$level, $text]) {
                    $add($k, 'param:' . self::EVENT_PARAM, $level, $text);
                }
            }
        }
        foreach (array_keys($node['params']) as $pk) {
            if (!isset($known[$pk])) {
                $add($k, 'params', $warn, t('« {nom} » a changé : un paramètre de cet appel ne correspond plus à aucun de ses champs et sera ignoré.', ['nom' => $name]));
                break;
            }
        }

        // Pièce récupérée : elle doit toujours exister, sous la forme connue ici.
        if ($o['from'] !== '') {
            $src = null;
            foreach ((array)($w['outputs'] ?? []) as $p) {
                if ($p['key'] === $o['from']) {
                    $src = $p;
                }
            }
            if ($src === null) {
                $add($k, 'output', $block, t('« {nom} » ne remet plus cette pièce dans son livrable : choisissez à nouveau ce que vous récupérez.', ['nom' => $name]));
            } else {
                $gap = self::shapeGap($o, $src);
                if ($gap === 'changed') {
                    $add($k, 'output', $block, t('La pièce « {piece} » de « {nom} » a changé de forme : choisissez-la à nouveau pour la mettre à jour.', ['piece' => (string)$src['name'], 'nom' => $name]));
                } elseif ($gap === 'grown') {
                    $add($k, 'output', $warn, t('La pièce « {piece} » de « {nom} » a de nouveaux champs : choisissez-la à nouveau pour les utiliser ici.', ['piece' => (string)$src['name'], 'nom' => $name]));
                }
            }
        }
    }

    /**
     * Écart entre la forme recopiée dans la brique et celle de la pièce de
     * l'appelé : '' (aucun), 'grown' (des champs en plus chez l'appelé : rien
     * ne casse), 'changed' (forme différente, champ disparu ou d'une autre
     * nature : ce que lisent les briques suivantes n'existe plus).
     */
    public static function shapeGap(array $output, array $source): string
    {
        if ((string)$output['form'] !== (string)$source['form']) {
            return 'changed';
        }
        if ($output['form'] !== 'fiche') {
            return '';
        }
        $theirs = [];
        foreach ((array)($source['fields'] ?? []) as $f) {
            $theirs[(string)$f['key']] = (string)$f['type'];
        }
        foreach ((array)($output['fields'] ?? []) as $f) {
            if (($theirs[(string)$f['key']] ?? null) !== (string)$f['type']) {
                return 'changed';
            }
        }
        return count($theirs) > count((array)($output['fields'] ?? [])) ? 'grown' : '';
    }

    /**
     * La pièce à recopier dans la brique quand on choisit ce qu'on récupère
     * (éditeur, creer_workflow) : forme, champs, format de la pièce de
     * l'appelé. `$from` vide ou inconnu : tout le livrable, en texte.
     */
    public static function outputShape(array $signature, string $from): array
    {
        foreach ((array)($signature['outputs'] ?? []) as $p) {
            if ($from !== '' && $p['key'] === $from) {
                $out = ['from' => $from, 'form' => (string)$p['form']];
                if ($p['form'] === 'fiche') {
                    $out['fields'] = $p['fields'];
                }
                if ($p['form'] === 'document') {
                    $out['format'] = (string)($p['format'] ?? 'docx');
                }
                return $out;
            }
        }
        return ['from' => '', 'form' => 'text'];
    }

    /**
     * Citations d'une valeur : [liste de {piece, field|null}, la valeur
     * n'est-elle QU'une citation ?].
     *
     * @return array{0: list<array{piece: string, field: ?string}>, 1: bool}
     */
    public static function refs(string $value): array
    {
        $pattern = '/@\{([a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '})(?:\.([a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '}))?\}/';
        preg_match_all($pattern, $value, $m, PREG_SET_ORDER);
        $refs = array_map(static fn(array $hit): array => ['piece' => $hit[1], 'field' => ($hit[2] ?? '') !== '' ? $hit[2] : null], $m);
        $only = count($refs) === 1 && trim((string)preg_replace($pattern, '', $value)) === '';
        return [$refs, $only];
    }

    /**
     * Une valeur convient-elle au champ qu'elle remplit ? Pure : lit la
     * NATURE de ce qui est cité (type du champ de Fiche, forme de la pièce),
     * ou la valeur elle-même quand elle est écrite en dur.
     *
     * @param array $field champ du départ de l'appelé {key, label, type, options}
     * @param array $available pièces disponibles avant la brique (WorkflowSpec::available)
     * @return list<array{0: string, 1: string}> [niveau, texte]
     */
    public static function paramIssues(array $field, string $value, array $available): array
    {
        $block = WorkflowSpec::LEVEL_BLOCK;
        $warn = WorkflowSpec::LEVEL_WARN;
        $label = (string)($field['label'] ?? '') !== '' ? (string)$field['label'] : (string)$field['key'];
        $type = (string)$field['type'];
        [$refs, $only] = self::refs($value);

        if ($type === 'file') {
            if (!$only || $refs[0]['field'] !== null) {
                return [[$block, t('« {champ} » attend un fichier : citez une pièce du dossier, et rien d’autre.', ['champ' => $label])]];
            }
            return [];
        }
        if ($type === 'text' || $type === 'longtext') {
            foreach ($refs as $r) {
                $p = $available[$r['piece']] ?? null;
                if ($p !== null && $r['field'] === null && $p['form'] === 'image') {
                    return [[$warn, t('« {champ} » est un texte : l’image « {piece} » n’y figurera que par son nom.', ['champ' => $label, 'piece' => (string)$p['name']])]];
                }
            }
            return [];
        }

        $wanted = WorkflowSpec::ficheTypeOfField($type);
        $nature = $type === 'choice' ? t('une des options prévues') : self::nature($wanted);
        if ($refs === []) {
            // Valeur écrite en dur : vérifiée tout de suite, comme au lancement.
            if ($type === 'choice') {
                return in_array($value, (array)($field['options'] ?? []), true) ? [] : [[$block, t('« {champ} » n’accepte que : {options}.', [
                    'champ' => $label, 'options' => implode(', ', (array)($field['options'] ?? [])),
                ])]];
            }
            return WorkflowValues::coerce($value, $wanted) !== null ? [] : [[$block, t('« {champ} » attend {nature} : « {valeur} » ne convient pas.', [
                'champ' => $label, 'nature' => $nature, 'valeur' => mb_substr($value, 0, 40),
            ])]];
        }
        if (!$only) {
            return [[$warn, t('« {champ} » attend {nature} : un texte composé risque de ne pas convenir. Citez plutôt un seul champ.', ['champ' => $label, 'nature' => $nature])]];
        }
        $ref = $refs[0];
        $p = $available[$ref['piece']] ?? null;
        if ($p === null) {
            return [];
        }
        if ($ref['field'] === null) {
            return $p['form'] === 'text'
                ? [[$warn, t('« {champ} » attend {nature} : « {piece} » est un texte libre. Vérifiez qu’il ne contiendra que cela.', ['champ' => $label, 'nature' => $nature, 'piece' => (string)$p['name']])]]
                : [[$block, t('« {champ} » attend {nature} : la pièce « {piece} » ne convient pas. Citez plutôt un champ d’une Fiche.', ['champ' => $label, 'nature' => $nature, 'piece' => (string)$p['name']])]];
        }
        $source = null;
        foreach ((array)($p['fields'] ?? []) as $f) {
            if ($f['key'] === $ref['field']) {
                $source = $f;
            }
        }
        if ($source === null) {
            return [[$block, t('Un paramètre cite un champ qui n’existe plus dans « {piece} ».', ['piece' => (string)$p['name']])]];
        }
        $from = '« ' . $p['name'] . ' › ' . $source['label'] . ' »';
        $st = (string)$source['type'];
        if ($type === 'choice') {
            $options = (array)($field['options'] ?? []);
            if ($st !== 'text') {
                return [[$block, t('« {champ} » n’accepte que : {options}. {source} est {nature}.', ['champ' => $label, 'options' => implode(', ', $options), 'source' => $from, 'nature' => self::nature($st)])]];
            }
            $theirs = (array)($source['options'] ?? []);
            return $theirs !== [] && array_diff($theirs, $options) === []
                ? []
                : [[$warn, t('« {champ} » n’accepte que : {options}. {source} peut valoir autre chose.', ['champ' => $label, 'options' => implode(', ', $options), 'source' => $from])]];
        }
        if ($st === $wanted) {
            return [];
        }
        return $st === 'text'
            ? [[$warn, t('« {champ} » attend {nature} : {source} est un texte. Vérifiez qu’il contiendra bien cela.', ['champ' => $label, 'nature' => $nature, 'source' => $from])]]
            : [[$block, t('« {champ} » attend {nature} : {source} est {autre}.', ['champ' => $label, 'nature' => $nature, 'source' => $from, 'autre' => self::nature($st)])]];
    }

    /**
     * Le titre d'une brique, cité dans une phrase : entre guillemets (ceux de
     * la langue), sauf s'il en porte déjà — « Appeler « Analyse client » »
     * se lirait mal.
     */
    public static function cite(string $title): string
    {
        return preg_match('/[«“"]/u', $title) ? $title : I18n::listeCitee([$title]);
    }

    /** « un nombre », « une date »… : la nature d'un champ, en toutes lettres. */
    public static function nature(string $ficheType): string
    {
        return match ($ficheType) {
            'number' => t('un nombre'),
            'date'   => t('une date'),
            'email'  => t('une adresse email'),
            'yesno'  => t('« oui » ou « non »'),
            'list'   => t('une liste'),
            default  => t('un texte'),
        };
    }

    // ==================================================================
    // Exécution
    // ==================================================================

    /**
     * Les valeurs données à l'appelé, lues dans le dossier de l'appelant :
     * `values` (champ → texte ; une pièce citée entière y est insérée, un
     * champ cité y devient sa valeur), `files` (champ Fichier → fichier
     * fabriqué depuis la pièce citée), `event` (ce qui tient lieu de
     * l'évènement de l'appelé, ou null). Les types sont vérifiés ensuite par
     * WorkflowEngine::validateInputs, comme pour tout lancement.
     *
     * @param array $run l'exécution appelante
     * @param array $childDef version publiée de l'appelé
     * @param array $pieces pièces de l'appelant (WorkflowSpec::pieces)
     * @param array $artifacts le dossier tel que le voit la brique (tour de boucle compris)
     * @return array{values: array<string, string>, files: array<string, array>, event: ?array}
     * @throws \InvalidArgumentException message affichable (pièce absente, fichier impossible)
     */
    public static function inputs(array $run, array $node, array $childDef, array $pieces, array $artifacts): array
    {
        $values = [];
        $files = [];
        foreach ($childDef['start']['fields'] as $f) {
            $raw = trim((string)($node['params'][$f['key']] ?? ''));
            if ($raw === '') {
                continue;
            }
            if ($f['type'] === 'file') {
                $file = self::fileFor($run, $raw, $pieces, $artifacts, (string)($f['label'] ?: $f['key']));
                if ($file !== null) {
                    $files[$f['key']] = $file;
                }
                continue;
            }
            $values[$f['key']] = trim(mb_substr(WorkflowDeliveries::fill($raw, $pieces, $artifacts, true, self::TEXT_MAX), 0, self::TEXT_MAX));
        }
        $event = null;
        $kind = $childDef['start']['event']['kind'] ?? null;
        $raw = trim((string)($node['params'][self::EVENT_PARAM] ?? ''));
        if ($kind !== null && $raw !== '') {
            $event = $kind === 'file'
                ? WorkflowTriggers::manualEvent($childDef, '', self::fileFor($run, $raw, $pieces, $artifacts, (string)$childDef['start']['event']['piece_name']))
                : WorkflowTriggers::manualEvent($childDef, WorkflowDeliveries::fill($raw, $pieces, $artifacts, true, self::TEXT_MAX), null);
            if ($event !== null) {
                // Ce contenu vient du dossier de l'appelant, pas d'un essai à la main.
                unset($event['summary']);
                $event['headline'] = '';
            }
        }
        return ['values' => $values, 'files' => $files, 'event' => $event];
    }

    /**
     * Ce qui a été donné à l'appelé, pour l'affichage (conversation,
     * historique) : libellé du champ → valeur lisible, bornée.
     *
     * @param array $in résultat de inputs()
     * @return list<array{label: string, value: string}>
     */
    public static function passed(array $childDef, array $in): array
    {
        $out = [];
        foreach ($childDef['start']['fields'] as $f) {
            $label = (string)$f['label'] !== '' ? (string)$f['label'] : (string)$f['key'];
            if ($f['type'] === 'file') {
                if (isset($in['files'][$f['key']])) {
                    $out[] = ['label' => $label, 'value' => '« ' . (string)$in['files'][$f['key']]['name'] . ' »'];
                }
                continue;
            }
            $v = trim((string)($in['values'][$f['key']] ?? ''));
            if ($v !== '') {
                $v = (string)preg_replace('/\s+/u', ' ', $v);
                $out[] = ['label' => $label, 'value' => mb_strlen($v) > 160 ? mb_substr($v, 0, 159) . '…' : $v];
            }
        }
        if (($in['event'] ?? null) !== null) {
            $name = (string)($childDef['start']['event']['piece_name'] ?? '');
            $v = isset($in['event']['name']) ? '« ' . (string)$in['event']['name'] . ' »'
                : (string)preg_replace('/\s+/u', ' ', trim((string)($in['event']['text'] ?? $in['event']['fields']['contenu'] ?? '')));
            $out[] = ['label' => $name, 'value' => mb_strlen($v) > 160 ? mb_substr($v, 0, 159) . '…' : $v];
        }
        return $out;
    }

    /**
     * Le fichier d'un paramètre Fichier : la pièce citée, fabriquée comme à
     * son téléchargement (WorkflowFiles), rangée parmi les téléversements.
     * Null si la pièce n'a pas été remise (voie non prise, brique ignorée).
     */
    private static function fileFor(array $run, string $value, array $pieces, array $artifacts, string $label): ?array
    {
        [$refs, $only] = self::refs($value);
        if (!$only || $refs[0]['field'] !== null || !isset($pieces[$refs[0]['piece']])) {
            throw new \InvalidArgumentException(t('« {champ} » attend un fichier : citez une pièce du dossier, et rien d’autre.', ['champ' => $label]));
        }
        $art = $artifacts[$refs[0]['piece']] ?? null;
        if ($art === null) {
            return null;
        }
        try {
            $built = WorkflowFiles::build($run, (string)($art['key'] ?? $refs[0]['piece']), '');
            $stored = LoopRunner::storeFile($built['name'], $built['bytes']);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(t('« {champ} » : la pièce « {piece} » n’a pas pu être transmise en fichier ({cause}).', [
                'champ' => $label, 'piece' => (string)$pieces[$refs[0]['piece']]['name'], 'cause' => $e->getMessage(),
            ]));
        }
        return ['name' => $stored['name'], 'type' => $stored['type'], 'path' => $stored['path'], 'extracted' => $stored['text'], 'text' => $stored['text']];
    }

    /**
     * Ce que l'appelant récupère d'une exécution terminée de l'appelé : le
     * contenu de sa pièce, et sa provenance (une pièce tirée de contenus de
     * tiers le reste ici). Null : l'appelé n'a pas remis cette pièce cette
     * fois (voie non prise) — la suite se passe d'elle.
     *
     * @return array{content: array, provenance: array}|null
     * @throws \RuntimeException la pièce n'a plus la forme attendue (message affichable)
     */
    public static function result(array $node, array $childRun): ?array
    {
        $o = $node['output'];
        if ($o['from'] === '') {
            $text = WorkflowTriggers::deliverableText($childRun);
            return trim($text) === '' ? null : ['content' => ['text' => $text], 'provenance' => self::provenance(WorkflowRun::artifacts($childRun['id'], false))];
        }
        $art = WorkflowRun::artifact($childRun['id'], (string)$o['from']);
        if ($art === null) {
            return null;
        }
        if ((string)$art['form'] !== (string)$o['form']) {
            throw new \RuntimeException(t('La pièce « {piece} » n’a plus la forme attendue : rouvrez la brique dans l’éditeur, choisissez-la à nouveau et publiez.', ['piece' => (string)$art['name']]));
        }
        $content = (array)$art['content'];
        if ($o['form'] === 'fiche') {
            // Les champs connus ici, par leur clé ; un champ disparu reste vide.
            $fields = [];
            foreach ((array)($o['fields'] ?? []) as $f) {
                $fields[$f['key']] = $content['fields'][$f['key']] ?? null;
            }
            $content['fields'] = $fields;
        }
        return ['content' => $content, 'provenance' => (array)($art['provenance'] ?? [])];
    }

    /** Provenances réunies de plusieurs pièces (sans doublon, bornées). */
    private static function provenance(array $artifacts): array
    {
        $out = [];
        foreach ($artifacts as $a) {
            foreach ((array)($a['provenance'] ?? []) as $p) {
                $sig = json_encode($p);
                if (!isset($out[$sig]) && count($out) < 8) {
                    $out[$sig] = $p;
                }
            }
        }
        return array_values($out);
    }
}
