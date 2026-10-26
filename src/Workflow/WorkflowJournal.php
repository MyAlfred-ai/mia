<?php

declare(strict_types=1);

/**
 * L'historique d'un workflow : chaque lancement, et pour chacun son déroulé
 * — ce que chaque brique a fait, ce qu'elle a remis, les actions menées et
 * leur issue, les erreurs et les reprises. Lu dans ce que le moteur garde
 * déjà (WorkflowRun : un passage de brique = une ligne `workflow_run_steps`),
 * sans rien écrire de plus : un lancement d'avant cet écran a son journal.
 *
 * Pure : le contrôleur lit la base (passages, pièces, traces d'actions des
 * réponses, cartes de la conversation) et passe tout ici. Les phrases sont
 * écrites dans la langue courante ; l'écran les affiche telles quelles.
 */
final class WorkflowJournal
{
    /** Lancements montrés à la fois (« Afficher plus » pour la suite). */
    public const PAGE = 30;
    /** Actions de la trace d'une réponse montrées par brique. */
    public const MAX_ACTIONS = 12;
    /** Champs d'une fiche de départ montrés. */
    public const MAX_FIELDS = 8;

    /** Tons : ok (fait), run (en cours), wait (vous attend), warn (repris, ignoré), err (échec), off (arrêté, sans suite). */
    public const TONES = ['ok', 'run', 'wait', 'warn', 'err', 'off'];

    /**
     * Filtres de l'historique : un groupe = des états d'exécution. Source
     * unique, lue par la requête (WorkflowRun::history) ET par le classement
     * d'une ligne (statusGroup) — testés l'un contre l'autre.
     */
    public const GROUPS = [
        'ok'     => [WorkflowRun::DONE, WorkflowRun::STOPPED],
        'err'    => [WorkflowRun::FAILED],
        'active' => [WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED],
        'off'    => [WorkflowRun::CANCELLED],
    ];

    /** Éléments du contexte d'un lancement (champs de départ) montrés dans la liste. */
    public const CONTEXT_FIELDS = 3;

    // ==================================================================
    // Un lancement dans la liste
    // ==================================================================

    /**
     * @param array    $run    WorkflowRun::hydrate (+ timer_at)
     * @param list<array> $steps WorkflowRun::steps
     * @param int      $n      numéro du lancement pour ce workflow (1 = le premier)
     * @param ?int     $latest version publiée du workflow, null sans publication
     */
    public static function summary(array $run, array $steps, int $n, ?int $latest, \DateTimeImmutable $now, ?array $event = null): array
    {
        $tz = self::zone($run);
        [$label, $tone] = self::runStatus($run);
        $started = ScheduledTask::fromSqlUtc((string)($run['started_at'] ?? ''));
        $finished = ScheduledTask::fromSqlUtc((string)($run['finished_at'] ?? ''));
        $running = !in_array($run['status'], array_merge(WorkflowRun::TERMINAL, [WorkflowRun::FAILED]), true);
        $seconds = $started !== null ? max(0, ($finished ?? ($running ? $now : self::lastActivity($steps, $started)))->getTimestamp() - $started->getTimestamp()) : 0;

        $where = '';
        if (in_array($run['status'], [WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED, WorkflowRun::FAILED], true)) {
            $where = self::titleOf($run['definition'], (string)$run['current_key']);
        }
        $error = '';
        if (is_array($run['error'])) {
            $error = trim((string)($run['error']['title'] ?? '')) ?: trim((string)($run['error']['message'] ?? ''));
        }
        $local = $started?->setTimezone($tz);
        return [
            'id'       => (int)$run['id'],
            'n'        => $n,
            'href'     => '/workflows/runs/' . (int)$run['id'],
            'status'   => (string)$run['status'],
            'group'    => self::statusGroup((string)$run['status']),
            'label'    => $label,
            'tone'     => $tone,
            'trigger'  => WorkflowCatalog::triggerLabel((string)$run['trigger_kind']),
            'trigger_icon' => self::triggerIcon((string)$run['trigger_kind']),
            'day'      => $local !== null ? self::dayLabel($local, $now->setTimezone($tz)) : '',
            'day_key'  => $local?->format('Y-m-d') ?? '',
            'time'     => $local !== null ? Format::time($local->format('H:i')) : '',
            'started'  => $local !== null ? Format::shortDateTime($local) : '',
            'duration' => $started !== null ? ($running ? t('depuis {duree}', ['duree' => self::duration($seconds)]) : self::duration($seconds)) : '',
            'version'  => (int)$run['version'],
            'old_version' => $latest !== null && $latest > (int)$run['version'],
            'track'    => self::track($run['definition'], $steps),
            'where'    => $where,
            'error'    => $error,
            'workflow' => self::workflowOf($run),
            'context'  => self::context($run, $event),
        ];
    }

    /** Tous | Réussis | En échec | En cours ou en attente — les filtres de la liste. */
    public static function statusGroup(string $status): string
    {
        foreach (self::GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }
        return 'active';
    }

    /** États d'un filtre de la liste ; `all` (ou inconnu) = aucun filtre. @return list<string> */
    public static function statusesOf(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }

    /** Le workflow d'un lancement (nom, couleur), lu avec lui par WorkflowRun::history. */
    private static function workflowOf(array $run): array
    {
        $color = (string)($run['workflow_color'] ?? '');
        return [
            'id'    => (int)($run['workflow_id'] ?? 0),
            'name'  => (string)($run['workflow_name'] ?? ''),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : Workflow::DEFAULT_COLOR,
        ];
    }

    /**
     * De quoi il s'agissait, en une ligne : les champs remplis au départ
     * (« Client : Ville de Lyon · Montant : 6 200 »), sinon ce qui l'a lancé
     * (l'objet d'un email reçu, le nom d'un fichier, le début des données).
     * C'est ce qui distingue deux lancements du même workflow dans la liste.
     *
     * `$event` : la pièce d'évènement (ou ce que WorkflowRun::eventHeadlines en a lu).
     */
    public static function context(array $run, ?array $event = null): string
    {
        $parts = [];
        $inputs = (array)($run['inputs'] ?? []);
        foreach ((array)($run['definition']['start']['fields'] ?? []) as $f) {
            $v = $inputs[$f['key']] ?? null;
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $type = $f['type'] === 'file' ? 'text' : WorkflowSpec::ficheTypeOfField((string)$f['type']);
            // Un texte perd sa syntaxe ; un nombre garde ses espaces insécables.
            $shown = WorkflowValues::display($v, $type);
            $shown = $type === 'text' ? self::plain($shown) : trim($shown);
            if ($shown === '' || $shown === '—') {
                continue;
            }
            $parts[] = t('{champ} : {valeur}', [
                'champ'  => (string)($f['label'] !== '' ? $f['label'] : $f['key']),
                'valeur' => mb_strlen($shown) > 60 ? rtrim(mb_substr($shown, 0, 59)) . '…' : $shown,
            ]);
            if (count($parts) >= self::CONTEXT_FIELDS) {
                break;
            }
        }
        if ($parts === [] && is_array($event)) {
            $fields = (array)($event['fields'] ?? []);
            $subject = trim((string)($fields['objet'] ?? ''));
            $from = trim((string)($fields['expediteur'] ?? ''));
            $name = trim((string)($event['name'] ?? ''));
            $text = self::plain((string)($event['text'] ?? ''));
            if ($subject !== '') {
                $parts[] = '« ' . $subject . ' »' . ($from !== '' ? ' — ' . $from : '');
            } elseif ($name !== '') {
                $parts[] = '« ' . $name . ' »';
            } elseif ($text !== '') {
                $parts[] = mb_strlen($text) > 100 ? rtrim(mb_substr($text, 0, 99)) . '…' : $text;
            }
        }
        $line = implode(' · ', $parts);
        return mb_strlen($line) > 180 ? rtrim(mb_substr($line, 0, 179)) . '…' : $line;
    }

    /**
     * Le geste principal du journal, selon où en est le lancement : décider
     * (il vous attend), le reprendre (en échec, en pause), le suivre (en
     * cours, ou une attente qui reprendra seule), sinon l'ouvrir.
     *
     * @return array{label: string, icon: string}
     */
    public static function openAction(array $run): array
    {
        $timer = !empty($run['timer_at']);
        return match ((string)$run['status']) {
            WorkflowRun::WAITING => $timer
                ? ['label' => t('Suivre en direct'), 'icon' => 'eye']
                : ['label' => t('Décider dans l’exécution'), 'icon' => 'gate'],
            WorkflowRun::FAILED, WorkflowRun::PAUSED => ['label' => t('Reprendre l’exécution'), 'icon' => 'retry'],
            WorkflowRun::RUNNING => ['label' => t('Suivre en direct'), 'icon' => 'eye'],
            default              => ['label' => t('Ouvrir l’exécution'), 'icon' => 'eye'],
        };
    }

    /**
     * Ce qu'un lancement terminé a remis : les pièces du livrable, chacune
     * avec son aperçu et son téléchargement (l'en-tête du journal les montre
     * d'abord — c'est la réponse à « qu'est-ce que ça a donné ? »).
     *
     * @return list<array>
     */
    public static function outputs(array $run, array $artifacts, array $ctx = []): array
    {
        if ((string)$run['status'] !== WorkflowRun::DONE) {
            return [];
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $out = [];
        foreach ((array)($run['definition']['end']['pieces'] ?? []) as $k) {
            $a = $artifacts[$k] ?? null;
            if ($a === null) {
                continue;
            }
            $out[] = self::pieceLine($run, $pieces[$k] ?? ['key' => $k, 'name' => $a['name'], 'form' => $a['form'], 'fields' => []], $a, $ctx);
        }
        return $out;
    }

    /**
     * Les valeurs d'un lancement, prêtes à pré-remplir la fenêtre de
     * lancement (« Relancer ») : seulement les champs que la version publiée
     * demande ENCORE, du même type, écrits comme on les taperait. Les fichiers
     * ne se rejouent pas : ils sont à rejoindre.
     *
     * @param array $def    définition publiée du workflow (celle que la fenêtre montrera)
     * @param array $inputs WorkflowRun::hydrate()['inputs'] du lancement
     * @return array<string, string>
     */
    public static function relaunchValues(array $def, array $inputs): array
    {
        $out = [];
        foreach ((array)($def['start']['fields'] ?? []) as $f) {
            $key = (string)$f['key'];
            if ($f['type'] === 'file' || !array_key_exists($key, $inputs)) {
                continue;
            }
            $v = $inputs[$key];
            $s = match ((string)$f['type']) {
                'yesno'  => is_bool($v) ? ($v ? 'oui' : 'non') : (is_string($v) ? $v : ''),
                'number' => is_int($v) || is_float($v) ? (string)$v : (is_string($v) ? $v : ''),
                default  => is_scalar($v) ? (string)$v : '',
            };
            if ($s === '') {
                continue;
            }
            if ($f['type'] === 'choice' && !in_array($s, (array)$f['options'], true)) {
                continue;
            }
            if ($f['type'] === 'date' && WorkflowValues::date($s) === null) {
                continue;
            }
            $out[$key] = $s;
        }
        return $out;
    }

    /**
     * La bande d'un lancement : une case par brique du rail principal, dans
     * l'ordre, colorée par son dernier passage (rien = pas atteinte). Le
     * départ et le livrable encadrent.
     *
     * @return list<array{title:string, tone:string}>
     */
    public static function track(array $def, array $steps): array
    {
        $last = [];
        foreach ($steps as $s) {
            [$base] = WorkflowLoop::splitIter((string)$s['node_key']);
            if (str_ends_with($base, WorkflowEngine::REVIEW_SUFFIX)) {
                $base = substr($base, 0, -strlen(WorkflowEngine::REVIEW_SUFFIX));
            }
            $owner = self::railOwner($def, $base);
            if ($owner !== null) {
                $tone = self::stepTone($s, false);
                // Une voie ou un tour en échec colore sa brique du rail ; un
                // succès plus tard ne l'efface que s'il vient de la même brique.
                if (!isset($last[$owner]) || $owner === $base || $tone === 'err' || $last[$owner] !== 'err') {
                    $last[$owner] = $tone;
                }
            }
        }
        $out = [['title' => t('Départ'), 'tone' => $last[WorkflowSpec::START_KEY] ?? '']];
        foreach ($def['nodes'] as $n) {
            $out[] = ['title' => (string)$n['title'], 'tone' => $last[$n['key']] ?? ''];
        }
        $out[] = ['title' => (string)($def['end']['title'] ?? t('Livrable')), 'tone' => $last[WorkflowSpec::END_KEY] ?? ''];
        return $out;
    }

    // ==================================================================
    // Le déroulé d'un lancement
    // ==================================================================

    /**
     * Ce que montre le haut du journal : l'état, quand et comment il est parti,
     * combien de temps il a pris, ce qu'il a coûté, la cause d'un arrêt.
     */
    public static function head(array $run, array $steps, int $n, ?int $latest, int $credits, \DateTimeImmutable $now, ?array $event = null): array
    {
        $s = self::summary($run, $steps, $n, $latest, $now, $event);
        $done = 0;
        foreach ($steps as $st) {
            if ($st['status'] === WorkflowRun::STEP_DONE && !in_array($st['node_key'], [WorkflowSpec::START_KEY, WorkflowSpec::END_KEY], true)
                && empty($st['detail']['kind'])) {
                $done++;
            }
        }
        $error = null;
        if (is_array($run['error']) && (trim((string)($run['error']['message'] ?? '')) !== '' || trim((string)($run['error']['title'] ?? '')) !== '')) {
            $error = [
                'title'   => trim((string)($run['error']['title'] ?? '')),
                'message' => trim((string)($run['error']['message'] ?? '')),
            ];
        }
        return $s + [
            'credits'   => $credits,
            'done_count' => $done,
            'conversation_url' => !empty($run['conversation_id']) ? '/chat/' . (int)$run['conversation_id'] : null,
            'error_box' => $error,
            'parent'    => !empty($run['parent_run_id']) ? '/workflows/runs/' . (int)$run['parent_run_id'] : null,
            'open'      => self::openAction($run),
        ];
    }

    /**
     * Une entrée par passage de brique, dans l'ordre où ils ont eu lieu.
     *
     * `$ctx` :
     * - artifacts : WorkflowRun::artifacts
     * - actions   : [id de message => trace affichable (ChatController::withActionIcons)]
     * - cards     : [id de carte => ['id','tool','status','expired','auto','preview','error','message_id']]
     * - profiles  : [id => nom de l'assistant]
     * - llms      : [slug => libellé du modèle]
     * - image     : fn(contenu d'une image créée) → son adresse si le fichier existe, sinon null
     *
     * @return list<array>
     */
    public static function entries(array $run, array $steps, array $ctx, \DateTimeImmutable $now): array
    {
        $def = $run['definition'];
        $tz = self::zone($run);
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = (array)($ctx['artifacts'] ?? []);
        $cards = (array)($ctx['cards'] ?? []);
        $names = self::pieceNames($artifacts);
        $images = self::imageUrls($artifacts, $ctx);
        $byMessage = [];
        foreach ($cards as $c) {
            if (!empty($c['message_id']) && ($c['tool'] ?? '') !== WorkflowEngine::GATE_TOOL) {
                $byMessage[(int)$c['message_id']][] = $c;
            }
        }
        $runStart = ScheduledTask::fromSqlUtc((string)($run['started_at'] ?? ''))?->setTimezone($tz);

        // Dernier passage de chaque clé : un passage repris plus tard le dit.
        $lastIndex = [];
        foreach ($steps as $i => $s) {
            $lastIndex[(string)$s['node_key']] = $i;
        }

        $out = [];
        foreach ($steps as $i => $s) {
            $key = (string)$s['node_key'];
            [$base, $iter] = WorkflowLoop::splitIter($key);
            $later = $lastIndex[$key] !== $i;
            $node = match ($key) {
                WorkflowSpec::START_KEY => ['type' => 'start', 'key' => $key, 'title' => t('Départ')],
                WorkflowSpec::END_KEY   => ['type' => 'end', 'key' => $key, 'title' => (string)($def['end']['title'] ?? t('Livrable'))],
                default                 => WorkflowEngine::nodeFor($def, $key),
            };
            if ($node === null) {
                // Une brique disparue de la définition figée : rien à en dire de juste.
                continue;
            }
            $review = str_ends_with($base, WorkflowEngine::REVIEW_SUFFIX);
            $kind = (string)($s['detail']['kind'] ?? '');
            $started = ScheduledTask::fromSqlUtc((string)($s['started_at'] ?? ''))?->setTimezone($tz);
            $finished = ScheduledTask::fromSqlUtc((string)($s['finished_at'] ?? ''))?->setTimezone($tz);

            $e = [
                'key'      => $key,
                'type'     => $review ? 'review' : (string)$node['type'],
                'icon'     => self::iconFor($node, $review, $kind),
                'title'    => (string)$node['title'],
                'who'      => self::who($node, $s, $ctx, $review),
                'time'     => $started !== null ? Format::time($started->format('H:i')) : '',
                'day'      => $started !== null && $runStart !== null && $started->format('Y-m-d') !== $runStart->format('Y-m-d') ? Format::shortDate($started) : '',
                // Une brique instantanée (départ, prévenir, règle) : « 0 s » n'apprend rien.
                'duration' => $started !== null && $finished !== null && $kind === '' && $finished->getTimestamp() - $started->getTimestamp() >= 1
                    ? self::duration($finished->getTimestamp() - $started->getTimestamp()) : '',
                'tone'     => self::stepTone($s, $later),
                'status'   => '',
                'chips'    => [],
                'quote'    => null,
                'lines'    => [],
                'error'    => null,
                'actions'  => [],
                'cards'    => [],
                'deliveries' => [],
                'piece'    => null,
                // Fichiers reçus au départ : chacun sa carte de pièce (aperçu, contenu, téléchargement).
                'files'    => [],
                'fields'   => [],
                // Tout le départ, quand les champs montrés en ligne en ont été coupés.
                'more'     => null,
                // Un échec repris plus tard ; un passage refait plus tard (sa pièce a été remplacée).
                'later'    => $later && in_array($s['status'], [WorkflowRun::STEP_FAILED, WorkflowRun::STEP_CANCELLED], true),
                'redone'   => $later,
                // Un passage dans une boucle : rangé sous elle à l'écran.
                'nested'   => $iter !== null,
            ];
            if ($iter !== null) {
                $e['chips'][] = self::iterChip($def, $base, $iter, $artifacts);
            }
            if ((int)$s['attempt'] > 1 && $kind === '') {
                $e['chips'][] = t('Tentative {n}', ['n' => (int)$s['attempt']]);
            }
            if (!empty($s['detail']['auto']) && $kind === '') {
                $e['chips'][] = t('Essai automatique {n}', ['n' => (int)$s['detail']['auto']]);
            }
            if (!empty($s['detail']['retry_reason'])) {
                $e['chips'][] = t('Seconde chance : pièce à reformer');
            }

            self::describe($e, $node, $s, $run, $pieces, $artifacts, $byMessage, $cards, $ctx, $tz, $now);

            // Ce que la réponse de la brique a fait (lectures, recherches, actions).
            $reply = (int)($s['detail']['reply_id'] ?? 0) ?: ($s['status'] !== WorkflowRun::STEP_RUNNING ? (int)($s['message_id'] ?? 0) : 0);
            if ($reply > 0 && isset($ctx['actions'][$reply]) && in_array($node['type'], [WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION, WorkflowSpec::TYPE_CREATE], true)) {
                $e['actions'] = self::actionLines((array)$ctx['actions'][$reply]);
            }
            if ($reply > 0 && isset($byMessage[$reply]) && $node['type'] !== 'end') {
                $e['cards'] = array_map(static fn(array $c): array => self::cardLine($c, $names, $images), $byMessage[$reply]);
            }
            $out[] = $e;
        }
        $closing = self::closing($run, $steps, $tz, $runStart);
        if ($closing !== null) {
            $out[] = $closing;
        }
        return $out;
    }

    /**
     * La fin d'un lancement qu'aucun passage ne dit : une voie réglée sur
     * « arrêter », un arrêt par la personne, une erreur, une pause. Rien quand
     * le livrable ou une brique « Arrêter le workflow » ont fermé la marche.
     */
    private static function closing(array $run, array $steps, \DateTimeZone $tz, ?\DateTimeImmutable $runStart): ?array
    {
        $status = (string)$run['status'];
        $last = $steps !== [] ? $steps[count($steps) - 1] : null;
        if ($last !== null) {
            [$base] = WorkflowLoop::splitIter((string)$last['node_key']);
            $node = $base === WorkflowSpec::END_KEY ? null : WorkflowSpec::node($run['definition'], $base);
            if ($base === WorkflowSpec::END_KEY || ($node['type'] ?? '') === WorkflowSpec::TYPE_STOP) {
                return null;
            }
        }
        [$title, $text, $tone, $icon] = match ($status) {
            WorkflowRun::STOPPED   => [t('Fin du workflow'), t('La voie suivie s’arrête là : pas de livrable, comme prévu'), 'off', 'stop'],
            WorkflowRun::CANCELLED => [t('Arrêt'), t('Exécution arrêtée avant la fin'), 'off', 'stop'],
            WorkflowRun::FAILED    => [t('Arrêt sur une erreur'), t('Reprenable depuis la page de l’exécution : réessayer, ignorer la brique ou repartir d’une étape'), 'err', 'alert'],
            WorkflowRun::PAUSED    => [t('En pause'), t('Quota, budget ou workflows coupés : reprenable depuis la page de l’exécution'), 'warn', 'pause'],
            default                => [null, null, null, null],
        };
        if ($title === null) {
            return null;
        }
        $at = ScheduledTask::fromSqlUtc((string)($run['finished_at'] ?? '') ?: (string)($run['updated_at'] ?? ''))?->setTimezone($tz);
        return [
            'key' => '', 'type' => 'closing', 'icon' => $icon, 'title' => $title, 'who' => '',
            'time' => $at !== null ? Format::time($at->format('H:i')) : '',
            'day' => $at !== null && $runStart !== null && $at->format('Y-m-d') !== $runStart->format('Y-m-d') ? Format::shortDate($at) : '',
            'duration' => '', 'tone' => $tone, 'status' => $text, 'chips' => [], 'quote' => null, 'lines' => [],
            'error' => null, 'actions' => [], 'cards' => [], 'deliveries' => [], 'piece' => null, 'files' => [], 'fields' => [],
            'more' => null, 'later' => false, 'redone' => false, 'nested' => false,
        ];
    }

    // ==================================================================
    // Ce que dit chaque passage
    // ==================================================================

    private static function describe(array &$e, array $node, array $s, array $run, array $pieces, array $artifacts, array $byMessage, array $cards, array $ctx, \DateTimeZone $tz, \DateTimeImmutable $now): void
    {
        $d = (array)$s['detail'];
        $status = (string)$s['status'];
        $outcome = (string)$s['outcome'];
        $kind = (string)($d['kind'] ?? '');
        $type = (string)$node['type'];
        [$base, $iter] = WorkflowLoop::splitIter((string)$s['node_key']);

        if ($status === WorkflowRun::STEP_CANCELLED) {
            $e['status'] = $outcome === 'expired' ? t('Délai de validation dépassé : l’exécution s’est arrêtée') : t('Arrêtée');
            if ($outcome === 'expired') {
                $e['tone'] = 'err';
            }
            return;
        }

        // Minuteurs : une attente, un nouvel essai automatique.
        if ($kind === 'retry') {
            $at = self::localSql((string)($d['resume'] ?? $s['resume_at'] ?? ''), $tz);
            $e['icon'] = 'retry';
            $e['title'] = t('Nouvel essai automatique — {titre}', ['titre' => $node['title']]);
            $e['status'] = $status === WorkflowRun::STEP_WAITING
                ? t('Prévu {quand} ({n}/{max})', ['quand' => $at, 'n' => (int)($d['auto'] ?? 1), 'max' => (int)($d['of'] ?? 1)])
                : t('Lancé ({n}/{max})', ['n' => (int)($d['auto'] ?? 1), 'max' => (int)($d['of'] ?? 1)]);
            if (trim((string)($d['cause'] ?? '')) !== '') {
                $e['quote'] = ['label' => t('Cause'), 'text' => (string)$d['cause']];
            }
            $e['tone'] = $status === WorkflowRun::STEP_WAITING ? 'run' : 'warn';
            return;
        }
        if ($type === WorkflowSpec::TYPE_WAIT) {
            if (!empty($d['skipped_by'])) {
                $e['status'] = t('Attente passée par vous');
                $e['tone'] = 'off';
            } elseif (!empty($d['past'])) {
                $e['status'] = t('La date était passée : suite immédiate');
            } elseif ($status === WorkflowRun::STEP_WAITING) {
                $e['status'] = t('Reprendra seule {quand}', ['quand' => self::localSql((string)($d['resume'] ?? $s['resume_at'] ?? ''), $tz)]);
                $e['tone'] = 'run';
            } else {
                $e['status'] = t('Attente terminée');
            }
            return;
        }

        if ($status === WorkflowRun::STEP_RUNNING) {
            $e['status'] = match ($type) {
                WorkflowSpec::TYPE_CONDITION => t('MIA examine la question…'),
                WorkflowSpec::TYPE_ROUTE     => t('MIA choisit la voie…'),
                WorkflowSpec::TYPE_LOOP      => self::loopProgress($d),
                'end'                        => t('Livraison en cours…'),
                default                      => t('En cours…'),
            };
            return;
        }

        if ($status === WorkflowRun::STEP_FAILED) {
            $e['error'] = self::errorOf($d, $outcome);
            $e['status'] = match ($outcome) {
                'quota'     => t('Quota ou budget atteint : l’exécution s’est mise en pause'),
                'malformed' => t('La pièce n’avait pas la forme attendue'),
                default     => t('Échec'),
            };
            if ($outcome === 'quota') {
                $e['tone'] = 'warn';
                $e['error'] = null;
            }
            if ($e['later']) {
                $e['status'] .= ' — ' . t('reprise ensuite');
            }
            if ($type === 'end') {
                $e['deliveries'] = self::deliveryLines((array)($d['deliveries'] ?? []), $cards, self::pieceNames($artifacts), self::imageUrls($artifacts, $ctx));
            }
            return;
        }

        switch ($type) {
            case 'start':
                $e['status'] = WorkflowCatalog::triggerLabel((string)$run['trigger_kind']);
                $e['who'] = '';
                $start = $artifacts[WorkflowSpec::START_PIECE] ?? null;
                if ($start !== null && isset($pieces[WorkflowSpec::START_PIECE])) {
                    $e['fields'] = self::fields($pieces[WorkflowSpec::START_PIECE], (array)$start['content']);
                    // Des champs coupés en ligne (trop nombreux, trop longs) : le départ entier se déplie.
                    if (self::fields($pieces[WorkflowSpec::START_PIECE], (array)$start['content'], PHP_INT_MAX, PHP_INT_MAX) !== $e['fields']) {
                        $e['more'] = [
                            'label' => t('Voir tout le départ'),
                            'html'  => WorkflowContentView::fiche($pieces[WorkflowSpec::START_PIECE], (array)$start['content']),
                        ];
                    }
                }
                $event = $artifacts[WorkflowSpec::EVENT_PIECE] ?? null;
                if ($event !== null) {
                    $e['piece'] = self::pieceLine($run, $pieces[WorkflowSpec::EVENT_PIECE] ?? ['key' => WorkflowSpec::EVENT_PIECE, 'name' => $event['name'], 'form' => $event['form'], 'fields' => []], $event, $ctx);
                }
                foreach ($artifacts as $a) {
                    if ($a['node_key'] === WorkflowSpec::START_KEY && $a['form'] === 'file') {
                        $e['files'][] = self::pieceLine($run, $pieces[$a['key']] ?? ['key' => $a['key'], 'name' => $a['name'], 'form' => 'file', 'fields' => []], $a, $ctx);
                    }
                }
                return;

            case 'end':
                $e['deliveries'] = self::deliveryLines((array)($d['deliveries'] ?? []), $cards, self::pieceNames($artifacts), self::imageUrls($artifacts, $ctx));
                if ($status === WorkflowRun::STEP_WAITING) {
                    $e['status'] = t('Attend votre accord sur les livraisons');
                } elseif ($outcome === 'skipped') {
                    $e['status'] = t('Terminé sans les livraisons, à votre demande');
                    $e['tone'] = 'off';
                } else {
                    $e['status'] = $e['deliveries'] !== [] ? t('Livrable remis et livré') : t('Livrable remis');
                }
                $names = [];
                foreach ((array)($run['definition']['end']['pieces'] ?? []) as $k) {
                    if (isset($artifacts[$k])) {
                        $names[] = '« ' . $artifacts[$k]['name'] . ' »';
                    }
                }
                if ($names !== []) {
                    $e['chips'][] = t('Pièces : {liste}', ['liste' => implode(', ', $names)]);
                }
                return;

            case WorkflowSpec::TYPE_CONDITION:
                self::describeChoice($e, $node, $s, $d, $status, $outcome);
                return;

            case WorkflowSpec::TYPE_ROUTE:
                self::describeChoice($e, $node, $s, $d, $status, $outcome);
                return;

            case WorkflowSpec::TYPE_GATE:
                if (($node['loop_gate'] ?? '') !== '' && $status !== WorkflowRun::STEP_WAITING) {
                    // Relecture d'une boucle : la liste avant les tours, les actions après.
                    if ($outcome === 'stopped') {
                        $e['status'] = t('Arrêtée par vous');
                        $e['tone'] = 'off';
                    } elseif ($node['loop_gate'] === 'list') {
                        $e['status'] = isset($d['kept'])
                            ? I18n::plural((int)$d['kept'], '{n} élément gardé', '{n} éléments gardés')
                            : t('Liste approuvée');
                    } else {
                        $done = count((array)($d['approved'] ?? []));
                        $refused = count((array)($d['refused'] ?? []));
                        $e['status'] = $outcome === 'none' || $done === 0
                            ? t('Aucune action exécutée')
                            : I18n::plural($done, '{n} action exécutée', '{n} actions exécutées');
                        if ($refused > 0 && $done > 0) {
                            $e['chips'][] = I18n::plural($refused, '{n} écartée', '{n} écartées');
                        }
                        if (($d['mail_mode'] ?? '') === 'send' && $done > 0) {
                            $e['chips'][] = t('Emails envoyés');
                        }
                        $e['tone'] = $done === 0 ? 'off' : 'ok';
                    }
                    return;
                }
                if ($status === WorkflowRun::STEP_WAITING) {
                    $e['status'] = t('Attend votre décision');
                } else {
                    $e['status'] = match ($outcome) {
                        'approved' => t('Approuvée'),
                        'returned' => t('Renvoyée pour être reprise'),
                        'stopped'  => t('Arrêtée par vous'),
                        default    => t('Validée'),
                    };
                    $e['tone'] = match ($outcome) { 'returned' => 'warn', 'stopped' => 'off', default => 'ok' };
                }
                if (trim((string)($d['remark'] ?? '')) !== '') {
                    $e['quote'] = ['label' => t('Votre remarque'), 'text' => (string)$d['remark']];
                }
                return;

            case WorkflowSpec::TYPE_LOOP:
                if ($outcome === 'exhausted') {
                    $e['status'] = t('Nombre maximal de tours atteint');
                    $e['tone'] = 'warn';
                } else {
                    $count = (int)($d['count'] ?? 0);
                    $e['status'] = $node['mode'] === WorkflowSpec::LOOP_EACH
                        ? I18n::plural($count, '{n} élément traité', '{n} éléments traités')
                        : I18n::plural((int)($d['i'] ?? 0), '{n} tour', '{n} tours');
                }
                if ((int)($d['total'] ?? 0) > (int)($d['count'] ?? 0) && $node['mode'] === WorkflowSpec::LOOP_EACH) {
                    $e['chips'][] = t('{total} trouvés, limités à {n}', ['total' => (int)$d['total'], 'n' => (int)$d['count']]);
                }
                // Éléments en échec, écartés par une condition, laissés par le filtre.
                $st = (string)($d['st'] ?? '');
                if ($st !== '') {
                    $failed = substr_count($st, 'f');
                    $skipped = substr_count($st, 's');
                    $e['status'] = I18n::plural(substr_count($st, 'd'), '{n} élément traité', '{n} éléments traités');
                    if ($failed > 0) {
                        $e['chips'][] = I18n::plural($failed, '{n} en échec', '{n} en échec');
                        $e['tone'] = 'warn';
                    }
                    if ($skipped > 0) {
                        $e['chips'][] = I18n::plural($skipped, '{n} écarté', '{n} écartés');
                    }
                }
                $ignored = array_sum(array_map('intval', (array)($d['ignored'] ?? [])));
                if ($ignored > 0) {
                    $e['chips'][] = I18n::plural($ignored, '{n} laissé par le filtre', '{n} laissés par le filtre');
                }
                if (!empty($d['only'])) {
                    $e['chips'][] = t('Éléments en échec refaits');
                }
                return;

            case WorkflowSpec::TYPE_NOTIFY:
                $e['status'] = t('Vous avez été prévenu');
                if (trim((string)($d['text'] ?? '')) !== '') {
                    $e['quote'] = ['label' => t('Message'), 'text' => (string)$d['text']];
                }
                return;

            case WorkflowSpec::TYPE_STOP:
                $e['status'] = $outcome === WorkflowSpec::STOP_STOPPED ? t('Le workflow s’arrête ici') : t('Le workflow se termine ici');
                $e['tone'] = $outcome === WorkflowSpec::STOP_STOPPED ? 'off' : 'ok';
                if (trim((string)($d['reason'] ?? '')) !== '') {
                    $e['quote'] = ['label' => t('Mot de la fin'), 'text' => (string)$d['reason']];
                }
                return;
        }

        // Étape, action, création.
        if (!empty($d['skipped_by'])) {
            $e['status'] = t('Ignorée, à votre demande');
            $e['tone'] = 'off';
            return;
        }
        if ($outcome === 'skipped') {
            $e['status'] = t('Ignorée après une erreur : le workflow a continué sans elle');
            $e['error'] = self::errorOf($d, 'error');
            return;
        }
        if ($status === WorkflowRun::STEP_WAITING) {
            $n = count((array)($d['cards'] ?? []));
            $e['status'] = I18n::plural(max(1, $n), 'Attend votre accord sur une action', 'Attend votre accord sur {n} actions');
            return;
        }
        $e['status'] = match ($type) {
            WorkflowSpec::TYPE_CREATE => t('Créée'),
            WorkflowSpec::TYPE_ACTION => t('Faite'),
            default                   => t('Terminée'),
        };
        if (trim((string)($d['remark'] ?? '')) !== '') {
            $e['quote'] = ['label' => t('Reprise avec votre remarque'), 'text' => (string)$d['remark']];
        }
        if (isset($node['output']['key'])) {
            if ($e['redone']) {
                // Le dossier ne garde que la dernière version : celle-ci a été refaite.
                $e['chips'][] = t('Refaite ensuite');
                return;
            }
            $artKey = WorkflowLoop::iterKey((string)$node['output']['key'], $iter);
            $a = $artifacts[$artKey] ?? null;
            if ($a !== null) {
                $e['piece'] = self::pieceLine($run, $pieces[$node['output']['key']] ?? ['key' => $artKey, 'name' => $a['name'], 'form' => $a['form'], 'fields' => []], $a, $ctx);
            }
        }
    }

    /** Condition et décision : la réponse, qui l'a donnée, pourquoi. */
    private static function describeChoice(array &$e, array $node, array $s, array $d, string $status, string $outcome): void
    {
        $route = $node['type'] === WorkflowSpec::TYPE_ROUTE;
        $reason = trim((string)($d['reason'] ?? ''));
        if ($status === WorkflowRun::STEP_WAITING) {
            $e['status'] = $route ? t('MIA hésite : attend votre choix') : t('MIA hésite : attend votre réponse');
            if ($reason !== '') {
                $e['quote'] = ['label' => t('Ce qui fait hésiter MIA'), 'text' => $reason];
            }
            $suggested = (string)($d['suggested'] ?? '');
            if ($route && $suggested !== '') {
                $e['chips'][] = t('Plus proche : {voie}', ['voie' => WorkflowSpec::laneLabel($node, $suggested)]);
            }
            return;
        }
        $person = ($d['decided_by'] ?? '') === 'person';
        if ($outcome === 'stopped') {
            $e['status'] = t('Arrêté par vous');
            $e['tone'] = 'off';
            return;
        }
        if ($route) {
            $lane = WorkflowSpec::laneLabel($node, $outcome);
            $e['status'] = $person
                ? t('Voie choisie par vous : « {voie} »', ['voie' => $lane])
                : (!empty($d['fallback'])
                    ? t('Aucune voie ne convenait : voie par défaut « {voie} »', ['voie' => $lane])
                    : t('Voie choisie : « {voie} »', ['voie' => $lane]));
        } else {
            $answer = $outcome === 'yes' ? t('oui') : t('non');
            // Condition dans une boucle : ce qu'elle a fait de l'élément.
            $then = (string)($node[$outcome === 'yes' ? 'yes' : 'no']['then'] ?? '');
            if ($then === WorkflowSpec::THEN_SKIP) {
                $e['chips'][] = t('Élément écarté');
            }
            $e['status'] = $person
                ? t('Vous avez répondu : {reponse}', ['reponse' => $answer])
                : (!empty($d['unsure'])
                    ? t('MIA hésitait : « {reponse} » retenu', ['reponse' => $answer])
                    : (($d['mode'] ?? '') === 'rule'
                        ? t('Règle vérifiée : {reponse}', ['reponse' => $answer])
                        : t('MIA répond : {reponse}', ['reponse' => $answer])));
            $e['lines'] = array_values(array_map('strval', (array)($d['lines'] ?? [])));
        }
        if ($reason !== '') {
            $e['quote'] = ['label' => $person ? t('Ce qui avait fait hésiter MIA') : t('Pourquoi'), 'text' => $reason];
        }
    }

    // ==================================================================
    // Morceaux
    // ==================================================================

    /**
     * Une action proposée (carte) : son intitulé, son objet, ce qu'il en est
     * advenu — un email ENVOYÉ ou rangé en brouillon se distinguent — et son
     * contenu entier à déplier (WorkflowContentView::action).
     *
     * @param array<string, string> $names nom de chaque pièce du dossier (pièces jointes)
     * @param array<string, string> $images adresse de chaque image du dossier encore présente (images intégrées à un email)
     * @return array{id:int, label:string, object:string, state:string, tone:string, error:string, view:string, view_label:string}
     */
    public static function cardLine(array $c, array $names = [], array $images = []): array
    {
        $status = (string)($c['status'] ?? '');
        $expired = !empty($c['expired']);
        $outcome = (string)($c['outcome'] ?? '');
        $auto = !empty($c['auto']);
        [$state, $tone] = match (true) {
            $status === 'executed' && $outcome === DrivePendingAction::OUTCOME_SENT  => [$auto ? t('Envoyé sans validation') : t('Envoyé'), 'ok'],
            $status === 'executed' && $outcome === DrivePendingAction::OUTCOME_DRAFT => [$auto ? t('Brouillon créé sans validation') : t('Brouillon créé'), 'ok'],
            $status === 'executed' && $auto              => [t('Faite sans validation'), 'ok'],
            $status === 'executed'                       => [t('Approuvée et faite'), 'ok'],
            $status === 'cancelled'                      => [t('Refusée ou remplacée'), 'off'],
            $status === 'failed'                         => [t('Approuvée, mais en échec'), 'err'],
            $expired                                     => [t('Expirée sans décision'), 'off'],
            default                                      => [t('Attend votre accord'), 'wait'],
        };
        $p = (array)($c['preview'] ?? []);
        return [
            'id'     => (int)($c['id'] ?? 0),
            'label'  => trim((string)($p['action'] ?? '')) ?: t('Action'),
            'object' => mb_substr(trim((string)($p['fichier'] ?? '')), 0, 160),
            'state'  => $state,
            'tone'   => $tone,
            'error'  => $status === 'failed' ? mb_substr(trim((string)($c['error'] ?? '')), 0, 300) : '',
            'view'   => WorkflowContentView::action($c, $names, $images),
            'view_label' => WorkflowContentView::actionLabel((string)($c['tool'] ?? '')),
        ];
    }

    /**
     * Adresse de chaque image créée du dossier dont le fichier existe encore
     * (`ctx.image`), par clé : une image intégrée à un email se montre.
     *
     * @return array<string, string>
     */
    public static function imageUrls(array $artifacts, array $ctx): array
    {
        $out = [];
        if (!isset($ctx['image']) || !is_callable($ctx['image'])) {
            return $out;
        }
        foreach ($artifacts as $k => $a) {
            if (($a['form'] ?? '') === 'image') {
                $url = (string)(($ctx['image'])((array)($a['content'] ?? [])) ?? '');
                if ($url !== '') {
                    $out[(string)$k] = $url;
                }
            }
        }
        return $out;
    }

    /** Nom de chaque pièce du dossier, par clé (pièces jointes d'une livraison). @return array<string, string> */
    private static function pieceNames(array $artifacts): array
    {
        $out = [];
        foreach ($artifacts as $k => $a) {
            $out[(string)$k] = (string)($a['name'] ?? $k);
        }
        return $out;
    }

    /**
     * Les livraisons du livrable et leur issue, cartes comprises : chaque
     * livraison passée par une action (email, Drive, Slack) en garde la ligne,
     * avec son contenu à déplier — faite sans carte aussi.
     */
    public static function deliveryLines(array $results, array $cards, array $names = [], array $images = []): array
    {
        $out = [];
        foreach ($results as $r) {
            $status = (string)($r['status'] ?? '');
            $label = trim((string)($r['label'] ?? '')) ?: t('Livraison');
            [$state, $tone] = match ($status) {
                'done'    => [t('Livrée'), 'ok'],
                'skipped' => [trim((string)($r['note'] ?? '')) !== '' ? t('Rien à livrer : {raison}', ['raison' => (string)$r['note']]) : t('Rien à livrer'), 'off'],
                'failed'  => [t('Échec'), 'err'],
                default   => [t('Attend votre accord'), 'wait'],
            };
            $lines = [];
            $outcomes = [];
            foreach ((array)($r['action_ids'] ?? []) as $id) {
                if (isset($cards[(int)$id])) {
                    $lines[] = self::cardLine($cards[(int)$id], $names, $images);
                    $outcomes[] = (string)($cards[(int)$id]['outcome'] ?? '');
                }
            }
            if ($status === 'card' && $lines !== []) {
                $tones = array_column($lines, 'tone');
                $tone = in_array('err', $tones, true) ? 'err' : (in_array('wait', $tones, true) ? 'wait' : (in_array('ok', $tones, true) ? 'ok' : 'off'));
                $state = $lines[0]['state'];
            }
            // Faite sans carte : un email dit s'il est parti ou resté en brouillon.
            if ($status === 'done' && $lines !== [] && in_array($outcomes[0], [DrivePendingAction::OUTCOME_SENT, DrivePendingAction::OUTCOME_DRAFT], true)) {
                $state = $lines[0]['state'];
            }
            $out[] = ['label' => $label, 'state' => $state, 'tone' => $tone, 'error' => $status === 'failed' ? mb_substr((string)($r['error'] ?? ''), 0, 300) : '', 'cards' => $lines];
        }
        return $out;
    }

    /** Lignes de la trace d'une réponse, bornées. @return list<array{icon:string, label:string, detail:string, ok:bool}> */
    public static function actionLines(array $trace): array
    {
        $out = [];
        foreach ($trace as $a) {
            $label = trim((string)($a['l'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'icon'   => (string)($a['i'] ?? 'check'),
                'label'  => $label,
                'detail' => mb_substr(trim((string)($a['d'] ?? '')), 0, 140),
                'ok'     => ($a['ok'] ?? true) !== false,
            ];
        }
        if (count($out) > self::MAX_ACTIONS) {
            $more = count($out) - self::MAX_ACTIONS;
            $out = array_slice($out, 0, self::MAX_ACTIONS);
            $out[] = ['icon' => 'check', 'label' => I18n::plural($more, '+ {n} autre action', '+ {n} autres actions'), 'detail' => '', 'ok' => true];
        }
        return $out;
    }

    /**
     * Ligne d'une pièce remise : nom, forme, résumé, téléchargement — et un
     * aperçu de son contenu : les champs d'une fiche, le début d'un texte ou
     * d'un document, les premiers éléments d'une liste.
     */
    private static function pieceLine(array $run, array $piece, array $a, array $ctx = []): array
    {
        $content = (array)$a['content'];
        $form = (string)$a['form'];
        $line = [
            'key'      => (string)$a['key'],
            'name'     => (string)$a['name'],
            'form'     => ArtifactContract::formLabel($form),
            'icon'     => $form,
            'summary'  => ArtifactContract::summary($piece + ['form' => $form], $content),
            'version'  => (int)($a['version'] ?? 1),
            'download' => '/workflows/runs/' . (int)$run['id'] . '/pieces/' . rawurlencode((string)$a['key']) . '/download',
            'fields'   => [],
            'excerpt'  => '',
            'image'    => '',
            'view'     => '',
            'view_label' => t('Voir le contenu'),
        ];
        // Une image créée : sa vignette, si le fichier existe encore (`ctx.image`
        // le vérifie sur le disque, l'élagage a pu passer).
        if ($form === 'image' && isset($ctx['image']) && is_callable($ctx['image'])) {
            $line['image'] = (string)(($ctx['image'])($content) ?? '');
        }
        if ($form === 'fiche') {
            $line['fields'] = array_slice(self::fields($piece, $content), 0, 4);
        } elseif ($form === 'list') {
            $items = array_slice(array_map('strval', (array)($content['items'] ?? [])), 0, 3);
            $line['excerpt'] = implode(' · ', array_map(static fn(string $i): string => mb_substr(self::plain($i), 0, 80), $items));
        } elseif (in_array($form, ['text', 'document'], true)) {
            $line['excerpt'] = mb_substr(self::plain((string)($form === 'document' ? ($content['source'] ?? '') : ($content['text'] ?? ''))), 0, 260);
        } elseif ($form === 'file') {
            $line['excerpt'] = mb_substr(self::plain((string)($content['text'] ?? '')), 0, 260);
        }
        // Tout le contenu, à déplier : ce qui a réellement circulé entre les briques.
        $line['view'] = WorkflowContentView::piece($piece + ['form' => $form], $content, [
            'download'     => $line['download'],
            'image'        => $line['image'],
            'conversation' => !empty($run['conversation_id']) ? '/chat/' . (int)$run['conversation_id'] : '',
        ]);
        if ($form === 'page') {
            $line['view_label'] = t('Voir la page');
        }
        return $line;
    }

    /** Un texte Markdown réduit à ses mots, sur une ligne (aperçu). */
    public static function plain(string $md): string
    {
        $t = (string)preg_replace('/```[^\n]*\n.*?(```|$)/su', ' ', $md);
        $t = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $t);
        $t = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t);
        $t = (string)preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+[.)])\s+/mu', '', $t);
        $t = str_replace(['**', '__', '`', '|'], ['', '', '', ' '], $t);
        $t = (string)preg_replace('/(?<![\p{L}\d])[*_](\S(?:.*?\S)?)[*_](?![\p{L}\d])/u', '$1', $t);
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /** Champs renseignés d'une fiche (départ), bornés. @return list<array{label:string, value:string}> */
    private static function fields(array $piece, array $content, int $max = self::MAX_FIELDS, int $chars = 200): array
    {
        $out = [];
        foreach ((array)($piece['fields'] ?? []) as $f) {
            $v = WorkflowValues::display($content['fields'][$f['key']] ?? null, (string)$f['type']);
            if (trim($v) === '') {
                continue;
            }
            $out[] = ['label' => (string)$f['label'], 'value' => mb_substr($v, 0, $chars)];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    private static function errorOf(array $d, string $outcome): ?array
    {
        $err = is_array($d['error'] ?? null) ? $d['error'] : [];
        $title = trim((string)($err['title'] ?? ''));
        $message = trim((string)($err['message'] ?? ''));
        if ($outcome === 'malformed' && $message === '' && trim((string)($d['why'] ?? '')) !== '') {
            $message = (string)$d['why'];
        }
        if ($title === '' && $message === '') {
            return null;
        }
        return ['title' => $title, 'message' => mb_substr($message, 0, 600)];
    }

    /** Qui a fait la brique : l'assistant (et son modèle), MIA, l'outil, vous. */
    private static function who(array $node, array $s, array $ctx, bool $review): string
    {
        $type = (string)$node['type'];
        if ($review || $type === WorkflowSpec::TYPE_GATE) {
            return t('Vous');
        }
        if ($type === WorkflowSpec::TYPE_STEP) {
            $profile = !empty($node['profile_id']) ? (string)($ctx['profiles'][(int)$node['profile_id']] ?? '') : '';
            $llm = (string)($s['detail']['llm'] ?? '');
            $model = $llm !== '' ? (string)($ctx['llms'][$llm] ?? self::modelName($llm)) : '';
            $who = $profile !== '' ? $profile : 'MIA';
            return $model !== '' ? $who . ' · ' . $model : $who;
        }
        if ($type === WorkflowSpec::TYPE_CREATE) {
            return trim((string)($s['detail']['tool'] ?? ''));
        }
        if ($type === WorkflowSpec::TYPE_ACTION) {
            $conn = WorkflowSpec::ACTION_TARGETS[$node['target']] ?? '';
            return match (true) {
                $conn === 'tool:n8n'   => t('Automatisation n8n'),
                $conn === 'tool:gamma' => t('Présentation Gamma'),
                $conn !== ''           => ConnectorRegistry::label($conn) !== '' ? t(ConnectorRegistry::label($conn)) : $conn,
                default                => '',
            };
        }
        if (in_array($type, [WorkflowSpec::TYPE_CONDITION, WorkflowSpec::TYPE_ROUTE], true)) {
            return ($node['mode'] ?? '') === 'rule' ? t('Règle') : 'MIA';
        }
        return '';
    }

    private static function modelName(string $slug): string
    {
        [$provider, $model] = array_pad(explode('::', $slug, 2), 2, '');
        if ($model === '') {
            return '';
        }
        return (string)(LlmCatalog::model($provider, $model)['label'] ?? $model);
    }

    /** « Élément 2 · Société X » ou « Tour 2 ». */
    private static function iterChip(array $def, string $base, int $iter, array $artifacts): string
    {
        // Relecture d'un tour : la boucle est celle de l'étape relue.
        if (str_ends_with($base, WorkflowEngine::REVIEW_SUFFIX)) {
            $base = substr($base, 0, -strlen(WorkflowEngine::REVIEW_SUFFIX));
        }
        $loop = WorkflowSpec::loopOf($def, $base);
        if ($loop !== null && $loop['mode'] === WorkflowSpec::LOOP_EACH) {
            $items = (array)($artifacts[$loop['key'] . WorkflowEngine::ITEMS_SUFFIX]['content']['items'] ?? []);
            $label = WorkflowLoop::label((string)($items[$iter - 1] ?? ''), 60);
            return $label !== '' ? t('Élément {n} · {nom}', ['n' => $iter, 'nom' => $label]) : t('Élément {n}', ['n' => $iter]);
        }
        return t('Tour {n}', ['n' => $iter]);
    }

    private static function loopProgress(array $d): string
    {
        if (($d['phase'] ?? '') === 'split') {
            return t('Préparation de la liste des éléments…');
        }
        $i = (int)($d['i'] ?? 0);
        $count = (int)($d['count'] ?? 0);
        return $count > 0 ? t('Élément {i} sur {n}…', ['i' => max(1, $i), 'n' => $count]) : t('Tour {i}…', ['i' => max(1, $i)]);
    }

    private static function iconFor(array $node, bool $review, string $kind): string
    {
        if ($kind === 'retry') {
            return 'retry';
        }
        if ($review) {
            return 'eye';
        }
        if ($node['type'] === WorkflowSpec::TYPE_CREATE) {
            return (string)($node['kind'] ?? 'create');
        }
        return (string)$node['type'];
    }

    /** Ton d'un passage d'après son statut et son issue. */
    public static function stepTone(array $s, bool $later): string
    {
        $outcome = (string)($s['outcome'] ?? '');
        return match ((string)$s['status']) {
            WorkflowRun::STEP_RUNNING => 'run',
            WorkflowRun::STEP_WAITING => ($s['detail']['kind'] ?? '') !== '' ? 'run' : 'wait',
            WorkflowRun::STEP_FAILED  => $later ? 'warn' : ($outcome === 'quota' ? 'warn' : 'err'),
            WorkflowRun::STEP_CANCELLED => $outcome === 'expired' ? 'err' : 'off',
            default => match ($outcome) {
                'skipped', 'returned', 'exhausted', 'retried' => 'warn',
                'stopped' => 'off',
                default => 'ok',
            },
        };
    }

    /** Libellé et ton de l'état d'un lancement (ceux de la page de l'exécution). */
    public static function runStatus(array $run): array
    {
        [$label, $tone] = WorkflowCatalog::statusLabel((string)$run['status'], !empty($run['timer_at']));
        $tone = match ($tone) { 'ok' => 'ok', 'run' => 'run', 'wait' => 'wait', 'err' => 'err', default => 'off' };
        if ($run['status'] === WorkflowRun::PAUSED) {
            $tone = 'warn';
        }
        return [$label, $tone];
    }

    /** « 8 s », « 2 min 05 s », « 14 min », « 1 h 07 », « 2 j 3 h ». */
    public static function duration(int $seconds): string
    {
        $s = max(0, $seconds);
        if ($s < 60) {
            return t('{n} s', ['n' => $s]);
        }
        if ($s < 600) {
            return t('{m} min {s} s', ['m' => intdiv($s, 60), 's' => sprintf('%02d', $s % 60)]);
        }
        if ($s < 3600) {
            return t('{m} min', ['m' => intdiv($s, 60)]);
        }
        if ($s < 86400) {
            return t('{h} h {m}', ['h' => intdiv($s, 3600), 'm' => sprintf('%02d', intdiv($s % 3600, 60))]);
        }
        return t('{j} j {h} h', ['j' => intdiv($s, 86400), 'h' => intdiv($s % 86400, 3600)]);
    }

    /** « Aujourd’hui », « Hier », sinon la date courte. */
    public static function dayLabel(\DateTimeImmutable $day, \DateTimeImmutable $now): string
    {
        if ($day->format('Y-m-d') === $now->format('Y-m-d')) {
            return t('Aujourd’hui');
        }
        if ($day->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return t('Hier');
        }
        return Format::shortDate($day) . ($day->format('Y') !== $now->format('Y') ? ' ' . $day->format('Y') : '');
    }

    /** Pictogramme du déclencheur d'un lancement (WorkflowCatalog::icons). */
    public static function triggerIcon(string $trigger): string
    {
        return match ($trigger) {
            'schedule' => 'clock',
            'chat'     => 'chat',
            'email'    => 'mail',
            'file'     => 'folder',
            'webhook'  => 'webhook',
            'workflow' => 'chain',
            default    => 'hand',
        };
    }

    /** Titre de la brique d'une clé (passage de boucle, relecture, départ, livrable). */
    public static function titleOf(array $def, string $key): string
    {
        if ($key === WorkflowSpec::START_KEY || $key === '') {
            return t('Départ');
        }
        if ($key === WorkflowSpec::END_KEY) {
            return (string)($def['end']['title'] ?? t('Livrable'));
        }
        return (string)(WorkflowEngine::nodeFor($def, $key)['title'] ?? '');
    }

    /** La brique du rail principal qui porte une clé (elle-même, sa condition, sa boucle), ou null. */
    private static function railOwner(array $def, string $key): ?string
    {
        if ($key === WorkflowSpec::START_KEY || $key === WorkflowSpec::END_KEY) {
            return $key;
        }
        // Relecture de la liste ou des actions d'une boucle : la case de la boucle.
        foreach ([WorkflowEngine::LIST_SUFFIX, WorkflowEngine::ACTIONS_SUFFIX] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                $key = substr($key, 0, -strlen($suffix));
            }
        }
        foreach ($def['nodes'] as $n) {
            if ($n['key'] === $key) {
                return $key;
            }
            foreach (WorkflowSpec::branchesOf($n) as $lane) {
                foreach ((array)($lane['nodes'] ?? []) as $inner) {
                    if ($inner['key'] === $key) {
                        return $n['key'];
                    }
                }
            }
            foreach ((array)($n['body'] ?? []) as $inner) {
                if ($inner['key'] === $key) {
                    return $n['key'];
                }
            }
        }
        return null;
    }

    private static function lastActivity(array $steps, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        $last = $fallback;
        foreach ($steps as $s) {
            foreach (['finished_at', 'started_at'] as $col) {
                $d = ScheduledTask::fromSqlUtc((string)($s[$col] ?? ''));
                if ($d !== null && $d > $last) {
                    $last = $d;
                }
            }
        }
        return $last;
    }

    private static function localSql(string $utc, \DateTimeZone $tz): string
    {
        $d = ScheduledTask::fromSqlUtc($utc);
        return $d !== null ? Format::shortDateTime($d->setTimezone($tz)) : '';
    }

    private static function zone(array $run): \DateTimeZone
    {
        return Recurrence::zone(ScheduledTask::normalizeTimezone((string)($run['timezone'] ?? '')));
    }
}
