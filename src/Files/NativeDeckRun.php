<?php

declare(strict_types=1);

/**
 * Carte de réalisation d'une « Version soignée » (NativeDeck) dans le fil.
 *
 * Le problème résolu : pendant la ou les minutes où un modèle compose la
 * présentation ou le classeur, le fil ne montrait qu'une ligne de texte
 * « ⏳ … le fichier arrivera ici », perdue entre deux réponses — et le fichier
 * arrivait dans un SECOND message, plus bas. Désormais le message d'attente
 * porte son état (`agent_json.progress`) et s'affiche en carte : en cours
 * (temps écoulé, étape, barre d'estimation, Arrêter), puis PRÊTE sur place
 * (bouton Télécharger), arrêtée ou interrompue.
 *
 * Le texte du message reste la source pour l'historique envoyé au modèle et
 * pour la recherche : la carte n'est qu'un rendu. Le job réécrit ce même
 * message à la fin (texte ✅ + état `done`) au lieu d'en créer un autre.
 *
 * UN SEUL RENDU : templates/partials/native-run-card.php, rendu par le serveur
 * au chargement de la page, renvoyé tout fait au lancement, dans l'historique
 * JSON et à la fin du job (`run_html`). public/assets/js/native-run.js ne fait
 * qu'animer (temps, étape, barre) avec les textes posés par le serveur.
 *
 * Un état « en cours » est relu contre la file : un job qui n'y tourne plus
 * (worker tombé, échec imprévu) vaut « interrompue » — jamais une carte qui
 * tourne pour toujours.
 */
final class NativeDeckRun
{
    public const TYPE = 'native_deck';

    public const RUNNING   = 'running';
    public const DONE      = 'done';
    public const CANCELLED = 'cancelled';
    public const FAILED    = 'failed';

    private const STATUSES = [self::RUNNING, self::DONE, self::CANCELLED, self::FAILED];

    private const NBSP = "\u{00A0}";

    /** Barre d'estimation : 92 % au plus, atteints en ~3 min (1 − e^(−t/τ)). */
    public const PROGRESS_CAP = 92;
    public const PROGRESS_TAU = 75;

    /** Au-delà, la carte dit que c'est plus long que d'habitude. */
    public const SLOW_AFTER_SEC = 200;

    /** Étapes affichées : début de chaque étape, en secondes. */
    private const PHASE_STARTS = [0, 20, 90];

    /**
     * État initial, posé sur le message d'attente au lancement (pure).
     *
     * @return array<string, mixed>
     */
    public static function start(string $kind, string $llm, string $label, string $title, int $jobId, int $now): array
    {
        return [
            'type'    => self::TYPE,
            'kind'    => NativeDeck::normalizeKind($kind),
            'status'  => self::RUNNING,
            'llm'     => $llm,
            'model'   => $label !== '' ? $label : $llm,
            'title'   => $title,
            'job_id'  => $jobId,
            'started' => $now,
        ];
    }

    /**
     * Fin de la réalisation (pure) : statut, heure de fin et, pour un fichier
     * prêt, son nom et son adresse.
     *
     * @param array<string, mixed> $run
     * @param array{file?: string, url?: string} $extra
     * @return array<string, mixed>
     */
    public static function finish(array $run, string $status, int $now, array $extra = []): array
    {
        $run['status'] = in_array($status, self::STATUSES, true) ? $status : self::FAILED;
        $run['ended']  = $now;
        foreach (['file', 'url'] as $key) {
            if (isset($extra[$key]) && is_string($extra[$key]) && $extra[$key] !== '') {
                $run[$key] = $extra[$key];
            }
        }

        return $run;
    }

    /**
     * État d'un message (ligne `messages`), ou null s'il n'en porte pas (pure).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function of(array $row): ?array
    {
        if (($row['role'] ?? '') !== 'assistant') {
            return null;
        }
        $run = Message::agentOf($row)['progress'] ?? null;
        if (!is_array($run) || ($run['type'] ?? '') !== self::TYPE || !in_array($run['status'] ?? '', self::STATUSES, true)) {
            return null;
        }

        return $run;
    }

    /**
     * Un état « en cours » dont le job ne tourne plus vaut « interrompue »
     * (pure ; `$jobActive` null = inconnu, l'état est gardé).
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function settle(array $run, ?bool $jobActive): array
    {
        if (($run['status'] ?? '') === self::RUNNING && $jobActive === false) {
            $run['status'] = self::FAILED;
        }

        return $run;
    }

    /** Part estimée de la réalisation, en pourcentage (pure, jamais 100 avant la fin). */
    public static function estimate(int $elapsed): int
    {
        return (int)round(self::PROGRESS_CAP * (1 - exp(-max(0, $elapsed) / self::PROGRESS_TAU)));
    }

    /**
     * « 42 s », « 1 min 05 s » (pure) — espaces insécables : une durée ne se
     * coupe jamais en fin de ligne (« 1 min 42 » / « s »).
     */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $text    = $seconds < 60
            ? t('{s} s', ['s' => (string)$seconds])
            : t('{m} min {s} s', ['m' => (string)intdiv($seconds, 60), 's' => sprintf('%02d', $seconds % 60)]);

        return str_replace(' ', self::NBSP, $text);
    }

    /** Titre : espace insécable devant « ! ? : ; » et dans les guillemets français (pure). */
    public static function typo(string $text): string
    {
        $text = (string)preg_replace('/ +([!?:;»])/u', self::NBSP . '$1', $text);

        return (string)preg_replace('/« +/u', '«' . self::NBSP, $text);
    }

    /**
     * Étapes affichées pendant la réalisation : [début en secondes, texte].
     * Une description de ce que fait le modèle, cadencée au temps — il ne
     * rapporte pas son avancement.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function phases(string $kind): array
    {
        $texts = [t('Lecture de votre demande et de vos fichiers…'), NativeDeck::label($kind, 'phase2'), NativeDeck::label($kind, 'phase3')];
        $out = [];
        foreach (self::PHASE_STARTS as $i => $at) {
            $out[] = [$at, $texts[$i]];
        }

        return $out;
    }

    /** Texte de l'étape atteinte après `$elapsed` secondes (pure). */
    public static function phaseAt(string $kind, int $elapsed): string
    {
        if ($elapsed >= self::SLOW_AFTER_SEC) {
            return self::slowText();
        }
        $text = '';
        foreach (self::phases($kind) as [$at, $label]) {
            if ($elapsed >= $at) {
                $text = $label;
            }
        }

        return $text;
    }

    private static function slowText(): string
    {
        return t('Un peu plus long que d’habitude — le fichier arrivera ici dès qu’il sera prêt.');
    }

    /**
     * Ce que la carte affiche (pure) : textes traduits, temps écoulé, étape,
     * estimation, fichier.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function view(array $run, int $messageId, int $now, int $credits = 0): array
    {
        $kind    = NativeDeck::normalizeKind((string)($run['kind'] ?? ''));
        $status  = in_array($run['status'] ?? '', self::STATUSES, true) ? (string)$run['status'] : self::FAILED;
        $model   = trim((string)($run['model'] ?? '')) !== '' ? (string)$run['model'] : (string)($run['llm'] ?? '');
        $started = (int)($run['started'] ?? 0);
        $ended   = (int)($run['ended'] ?? 0);
        $elapsed = $started > 0 ? max(0, ($status === self::RUNNING || $ended <= 0 ? $now : $ended) - $started) : 0;
        $provider = strstr((string)($run['llm'] ?? ''), '::', true) ?: (string)($run['llm'] ?? '');
        $logo    = LLMFactory::logos()[$provider] ?? null;
        $title   = trim((string)($run['title'] ?? ''));

        $state = match ($status) {
            self::RUNNING   => t('En cours'),
            self::DONE      => NativeDeck::label($kind, 'ready'),
            self::CANCELLED => t('Arrêtée'),
            default         => t('Interrompue'),
        };
        $by = match ($status) {
            self::RUNNING => NativeDeck::label($kind, 'running', ['model' => $model]),
            self::DONE => NativeDeck::label($kind, 'done_by', ['model' => $model, 'duree' => self::duration($elapsed)])
                . ($credits > 0 ? ' · ' . t('{n} crédits', ['n' => Format::credits($credits)]) : ''),
            self::CANCELLED => $elapsed > 0
                ? t('Réalisation arrêtée après {duree}.', ['duree' => self::duration($elapsed)])
                : t('Réalisation arrêtée.'),
            default => t('La réalisation n’a pas abouti.'),
        };
        $file = (string)($run['file'] ?? '');
        $url  = (string)($run['url'] ?? '');
        // Seule l'adresse de téléchargement d'un fichier réalisé est acceptée
        // (même motif que NATIVE_LINK, generated-files.js).
        if (!preg_match('#^/chat/\d+/presentation/[0-9a-f]{32}$#', $url)) {
            $url = '';
        }

        return [
            'status'     => $status,
            'kind'       => $kind,
            'ext'        => strtoupper($kind),
            'tone'       => NativeDeck::tone($kind),
            'message_id' => $messageId,
            'job_id'     => (int)($run['job_id'] ?? 0),
            'title'      => self::typo($title !== '' ? $title : NativeDeck::label($kind, 'noun')),
            'model'      => $model,
            'logo'       => $logo,
            'kicker'     => t('Version soignée'),
            'state'      => $state,
            'by'         => $by,
            'elapsed'    => $elapsed,
            'elapsed_text' => self::duration($elapsed),
            'progress'   => self::estimate($elapsed),
            'phases'     => self::phases($kind),
            'phase'      => self::phaseAt($kind, $elapsed),
            'slow_after' => self::SLOW_AFTER_SEC,
            'slow_text'  => self::slowText(),
            'window'     => t('habituellement 1 à 3 min'),
            'hint'       => t('Vous pouvez continuer à discuter : le fichier arrivera ici.'),
            'stop'       => t('Arrêter'),
            'confirm'    => t('Arrêter la réalisation ? Ce que le modèle a déjà consommé reste compté.'),
            'stopping'   => t('Arrêt en cours…'),
            'failed_state' => t('Interrompue'),
            'failed_by'  => t('La réalisation n’a pas abouti.'),
            'file'       => $file,
            'url'        => $url,
            'download'   => t('Télécharger'),
            'kept'       => t('Fichier conservé {days} jours', ['days' => (string)NativeDeckStore::RETENTION_DAYS]),
            'fmt_sec'    => t('{s} s'),
            'fmt_min'    => t('{m} min {s} s'),
        ];
    }

    /**
     * Vues des messages d'une conversation qui portent une réalisation,
     * relues contre la file (une requête pour toutes) : identifiant du
     * message → vue.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $credits crédits par message (sinon `credits_used` de la ligne)
     * @return array<int, array<string, mixed>>
     */
    public static function forMessages(array $rows, int $now, array $credits = []): array
    {
        $runs = [];
        foreach ($rows as $row) {
            $run = self::of($row);
            if ($run !== null) {
                $runs[(int)$row['id']] = [$run, (int)($credits[(int)$row['id']] ?? $row['credits_used'] ?? 0)];
            }
        }
        if ($runs === []) {
            return [];
        }
        $running = [];
        foreach ($runs as [$run]) {
            if ($run['status'] === self::RUNNING && (int)($run['job_id'] ?? 0) > 0) {
                $running[] = (int)$run['job_id'];
            }
        }
        $active = self::activeJobIds($running);

        $views = [];
        foreach ($runs as $id => [$run, $msgCredits]) {
            $jobId = (int)($run['job_id'] ?? 0);
            $known = $active !== null && $run['status'] === self::RUNNING;
            // Un état « en cours » sans job (lancement interrompu entre le
            // message et l'enfilage) ne tournera jamais.
            $jobActive = $known ? ($jobId > 0 && isset($active[$jobId])) : null;
            if ($run['status'] === self::RUNNING && $jobId <= 0) {
                $jobActive = false;
            }
            $views[$id] = self::view(self::settle($run, $jobActive), $id, $now, $msgCredits);
        }

        return $views;
    }

    /**
     * Jobs encore en file ou en cours parmi `$ids` (null = lecture impossible :
     * les cartes gardent leur état).
     *
     * @param list<int> $ids
     * @return array<int, true>|null
     */
    private static function activeJobIds(array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $ids  = array_values(array_unique($ids));
            $stmt = Database::getInstance()->prepare(
                'SELECT id FROM async_jobs WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ")
                 AND status IN ('pending', 'running')"
            );
            $stmt->execute($ids);

            return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            error_log('[Fichier par le modèle] État des réalisations illisible : ' . $e->getMessage());

            return null;
        }
    }

    /** HTML de la carte (le même gabarit que le rendu de la page). */
    public static function html(array $view): string
    {
        ob_start();
        try {
            $nativeRun = $view;
            include ROOT . '/templates/partials/native-run-card.php';
        } finally {
            $html = (string)ob_get_clean();
        }

        return $html;
    }
}
