<?php

declare(strict_types=1);

/**
 * L'avancement d'une exécution, dans SA conversation — pur.
 *
 * Le problème résolu (retour du 2026-11-04, « Discussion-first ») : lancer un
 * workflow menait sur la page de l'exécution, les décisions se prenaient tantôt
 * là, tantôt dans la conversation — l'utilisateur alternait entre deux
 * espaces et s'y perdait. Désormais on lance, on se retrouve dans la
 * conversation, et un VOLET sur le côté montre les étapes et leur état ; ce qui
 * vous attend (validation, carte, reprise) y est rappelé, les décisions se
 * prennent dans la discussion. La page détaillée reste à un clic.
 *
 * Ce fichier ne fait que mettre en forme l'état que calcule
 * WorkflowController::runState (le même que la page de l'exécution) : une
 * liste de lignes, une à chaque brique du rail, et les briques de la voie prise
 * quand une condition ou une décision a tranché.
 */
final class WorkflowProgress
{
    /** Délais du sondage du volet : vite quand ça tourne, lentement quand ça vous attend. */
    public const POLL_RUNNING_MS = 3000;
    public const POLL_WAITING_MS = 8000;

    /**
     * @param array $state WorkflowController::runState
     * @param array $meta  {run_id, name, color, version}
     */
    public static function view(array $def, array $state, array $meta): array
    {
        $rows = self::rows($def, $state);
        $bricks = array_values(array_filter($rows, static fn(array $r): bool => $r['kind'] !== 'lane'));
        $done = count(array_filter($bricks, static fn(array $r): bool => in_array($r['state'], ['done', 'skip'], true)));
        [$label, $tone] = WorkflowCatalog::statusLabel((string)$state['status'], ($state['timer'] ?? null) !== null);
        $terminal = !empty($state['terminal']);
        $current = null;
        foreach ($rows as $r) {
            if (in_array($r['state'], ['run', 'wait', 'err'], true)) {
                $current = $r;
                break;
            }
        }
        return [
            'run_id'       => (int)$meta['run_id'],
            'name'         => (string)$meta['name'],
            'color'        => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($meta['color'] ?? '')) ? (string)$meta['color'] : Workflow::DEFAULT_COLOR,
            'version'      => (int)($meta['version'] ?? 0),
            'status'       => (string)$state['status'],
            'status_label' => $label,
            'status_tone'  => $tone,
            'terminal'     => $terminal,
            'credits'      => (int)($state['credits'] ?? 0),
            'rows'         => $rows,
            'done'         => $done,
            'total'        => count($bricks),
            'current'      => $current !== null ? (string)$current['title'] : '',
            'attention'    => self::attention($state),
            'detail_url'   => '/workflows/runs/' . (int)$meta['run_id'],
            'poll'         => $terminal ? 0 : (in_array($state['status'], [WorkflowRun::WAITING, WorkflowRun::PAUSED, WorkflowRun::FAILED], true) ? self::POLL_WAITING_MS : self::POLL_RUNNING_MS),
            // Ce qui change quand il faut relire la conversation (un message
            // du moteur, une étape qui démarre) : le volet le compare d'un
            // sondage à l'autre.
            'signature'    => self::signature($state),
        ];
    }

    /**
     * Une ligne par brique, dans l'ordre du rail : départ, briques, voie prise
     * d'un embranchement (avec son nom), livrable.
     *
     * @return list<array{key: string, title: string, kind: string, depth: int, state: string, note: string, lane?: int}>
     */
    public static function rows(array $def, array $state): array
    {
        $rows = [[
            'key' => WorkflowSpec::START_KEY, 'title' => trim((string)($def['start']['title'] ?? '')) ?: t('Départ'),
            'kind' => 'start', 'depth' => 0, 'state' => 'done', 'note' => t('Lancé'),
        ]];
        foreach ((array)($def['nodes'] ?? []) as $n) {
            self::nodeRows($n, $state, 0, $rows);
        }
        $end = $state['nodes'][WorkflowSpec::END_KEY] ?? null;
        $endState = match (true) {
            ($state['status'] ?? '') === WorkflowRun::DONE      => 'done',
            $end !== null && $end['status'] === 'running'      => 'run',
            $end !== null && $end['status'] === 'waiting'      => 'wait',
            $end !== null && $end['status'] === 'failed'       => 'err',
            default                                            => 'todo',
        };
        if (($state['status'] ?? '') === WorkflowRun::DONE || ($def['end']['pieces'] ?? []) !== [] || ($def['end']['deliveries'] ?? []) !== [] || $end !== null) {
            $rows[] = [
                'key' => WorkflowSpec::END_KEY, 'title' => trim((string)($def['end']['title'] ?? '')) ?: t('Livrable'),
                'kind' => 'end', 'depth' => 0, 'state' => $endState, 'note' => $endState === 'done' ? t('Livré') : '',
            ];
        }
        return $rows;
    }

    private static function nodeRows(array $n, array $state, int $depth, array &$rows): void
    {
        $key = (string)$n['key'];
        $st = $state['nodes'][$key] ?? null;
        $review = $state['nodes'][$key . '~review'] ?? null;
        $row = ['key' => $key, 'title' => (string)$n['title'], 'kind' => (string)$n['type'], 'depth' => $depth,
            'state' => self::stateOf($st, $key, $state, $review), 'note' => ''];
        // Une validation REFUSÉE (« Annuler ») clôt son passage en « fait,
        // issue stopped » : elle s'affichait en vert avec sa durée, comme
        // approuvée (recette du 2026-11-09). Elle est « Arrêtée ».
        if ($n['type'] === WorkflowSpec::TYPE_GATE && $row['state'] === 'done' && ($st['outcome'] ?? '') === 'stopped') {
            $row['state'] = 'off';
        }
        if ($row['state'] === 'wait' && $review !== null && ($review['status'] ?? '') === 'waiting') {
            $row['note'] = t('À relire');
        } elseif (!empty($st['resume']) && ($st['status'] ?? '') === 'waiting') {
            $row['note'] = t('Reprend le {quand}', ['quand' => (string)$st['resume']]);
            // Une attente minutée n'attend personne : les lecteurs d'écran
            // entendaient « Vous attend » (run-progress.php).
            $row['timer'] = true;
        } elseif ($row['state'] === 'done' && isset($st['seconds']) && $st['seconds'] !== null) {
            $row['note'] = self::duration((int)$st['seconds']);
        }
        // Un appel en cours : c'est le workflow appelé qui travaille — ou qui
        // attend la personne, dans SON exécution.
        if ($n['type'] === WorkflowSpec::TYPE_CALL && is_array($st['child'] ?? null) && ($st['status'] ?? '') === 'running') {
            $child = $st['child'];
            if (!empty($child['waiting'])) {
                $row['state'] = 'wait';
                $row['note'] = t('« {nom} » vous attend', ['nom' => (string)$child['name']]);
            } elseif (!empty($child['blocked'])) {
                $row['state'] = 'err';
            } else {
                $row['note'] = (string)$child['name'];
            }
        }
        if ($n['type'] === WorkflowSpec::TYPE_LOOP) {
            $lp = $state['loops'][$key] ?? null;
            if ($lp !== null && ((int)$lp['count'] > 0 || (int)$lp['i'] > 0)) {
                $row['note'] = $lp['mode'] === WorkflowSpec::LOOP_EACH
                    ? t('{n} / {total}', ['n' => (int)$lp['done_count'] + (int)$lp['skipped'], 'total' => (int)$lp['count']])
                    : t('Tour {n}', ['n' => (int)$lp['i']]);
            }
        }
        $lanes = WorkflowSpec::branchesOf($n);
        $taken = null;
        if ($lanes !== [] && $st !== null && ($st['status'] ?? '') === 'done') {
            $taken = (string)($st['outcome'] ?? '');
            $index = array_search($taken, array_keys($lanes), true);
            if ($index !== false) {
                $row['lane'] = (int)$index;
                $row['note'] = $n['type'] === WorkflowSpec::TYPE_CONDITION
                    ? ($taken === 'yes' ? t('Oui') : t('Non'))
                    : WorkflowSpec::laneLabel($n, $taken);
            }
        }
        $rows[] = $row;
        if ($taken !== null && isset($lanes[$taken])) {
            $index = (int)array_search($taken, array_keys($lanes), true);
            $rows[] = ['key' => $key . ':' . $taken, 'title' => $n['type'] === WorkflowSpec::TYPE_CONDITION
                ? ($taken === 'yes' ? t('Si oui') : t('Si non')) : WorkflowSpec::laneLabel($n, $taken),
                'kind' => 'lane', 'depth' => $depth + 1, 'state' => 'done', 'note' => '', 'lane' => $index];
            foreach ((array)($lanes[$taken]['nodes'] ?? []) as $child) {
                self::nodeRows($child, $state, $depth + 1, $rows);
            }
        }
    }

    private static function stateOf(?array $st, string $key, array $state, ?array $review): string
    {
        if ($review !== null && ($review['status'] ?? '') === 'waiting') {
            return 'wait';
        }
        if ($st === null) {
            [$curBase] = WorkflowLoop::splitIter((string)($state['current'] ?? ''));
            return $curBase === $key && empty($state['terminal']) ? 'run' : 'todo';
        }
        return match ((string)$st['status']) {
            'done'      => ($st['outcome'] ?? '') === 'skipped' ? 'skip' : 'done',
            'running'   => 'run',
            'waiting'   => 'wait',
            'failed'    => 'err',
            'cancelled' => 'off',
            default     => 'todo',
        };
    }

    /**
     * Ce qui vous attend, ou ce qui vient de se passer : null quand le
     * workflow travaille sans vous.
     */
    public static function attention(array $state): ?array
    {
        $status = (string)($state['status'] ?? '');
        $gate = $state['gate'] ?? null;
        if (is_array($gate)) {
            return [
                'kind'       => !empty($gate['doubt']) ? 'doubt' : 'gate',
                'title'      => (string)$gate['title'],
                'reason'     => (string)($gate['reason'] ?? ''),
                'action_id'  => (int)($gate['action_id'] ?? 0),
                'can_return' => !empty($gate['can_return']) && empty($gate['doubt']) && ($gate['loop_gate'] ?? '') === '',
                'routes'     => array_values(array_map(static fn(array $r): array => ['key' => (string)$r['key'], 'label' => (string)$r['label']], (array)($gate['routes'] ?? []))),
                'suggested'  => (string)($gate['suggested'] ?? ''),
                'loop_gate'  => (string)($gate['loop_gate'] ?? ''),
            ];
        }
        if (!empty($state['waiting_cards'])) {
            return ['kind' => 'cards'];
        }
        if (is_array($state['timer'] ?? null)) {
            return ['kind' => 'timer', 'at' => (string)($state['timer']['at'] ?? ''), 'retry' => ($state['timer']['kind'] ?? '') === 'retry',
                'cause' => (string)($state['timer']['cause'] ?? '')];
        }
        // La brique en cours appelle un autre workflow : il attend la personne
        // (c'est dans SON exécution qu'elle agit), ou il travaille.
        $current = $state['nodes'][(string)($state['current'] ?? '')] ?? null;
        $child = is_array($current['child'] ?? null) && ($current['status'] ?? '') === 'running' ? $current['child'] : null;
        if ($status === WorkflowRun::RUNNING && $child !== null && !empty($child['url'])) {
            return ['kind' => !empty($child['waiting']) ? 'call_wait' : 'call', 'name' => (string)$child['name'], 'url' => (string)$child['url']];
        }
        $error = is_array($state['error'] ?? null) ? $state['error'] : [];
        return match ($status) {
            WorkflowRun::FAILED => ['kind' => 'failed', 'title' => (string)($error['title'] ?? ''), 'message' => (string)($error['message'] ?? ''),
                'resumable' => !empty($state['resumable']), 'latest' => (int)($state['latest_version'] ?? 0),
                // Arrêté sur un appel : l'exécution du workflow appelé, où la cause se lit.
                'child_url' => !empty($error['child_run']) ? '/workflows/runs/' . (int)$error['child_run'] : ''],
            WorkflowRun::PAUSED => ['kind' => 'paused', 'message' => (string)($error['message'] ?? ''), 'resumable' => !empty($state['resumable'])],
            WorkflowRun::DONE      => ['kind' => 'done'],
            WorkflowRun::STOPPED   => ['kind' => 'stopped'],
            WorkflowRun::CANCELLED => ['kind' => 'cancelled'],
            default                => null,
        };
    }

    /** Empreinte de l'état : elle change quand une brique avance. */
    public static function signature(array $state): string
    {
        $parts = [(string)($state['status'] ?? ''), (string)($state['current'] ?? '')];
        foreach ((array)($state['nodes'] ?? []) as $k => $n) {
            $parts[] = $k . '=' . ($n['status'] ?? '') . '/' . ($n['outcome'] ?? '') . '/' . ($n['attempt'] ?? '')
                // Un appel : l'état du workflow appelé (il attend, il s'est arrêté) fait aussi bouger le volet.
                . (isset($n['child']['status']) ? '/' . $n['child']['status'] : '');
        }
        return substr(sha1(implode('|', $parts)), 0, 16);
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return t('{n} s', ['n' => max(1, $seconds)]);
        }
        if ($seconds < 3600) {
            return t('{n} min', ['n' => (int)round($seconds / 60)]);
        }
        return t('{n} h', ['n' => (int)floor($seconds / 3600)]);
    }
}
