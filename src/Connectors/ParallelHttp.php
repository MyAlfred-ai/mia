<?php

declare(strict_types=1);

/**
 * Requêtes de PLUSIEURS connecteurs en parallèle (outils par intention :
 * `rechercher_partout`, `rechercher_emails`, `consulter_agendas`).
 *
 * LE PROBLÈME RÉSOLU. Interroger Gmail, Outlook, Drive, Slack et deux agendas
 * l'un après l'autre additionne leurs temps de réponse — cinq à dix secondes,
 * pendant que l'utilisateur regarde une ligne « en cours ». PHP n'a pas de
 * fils d'exécution ; il a des Fibers et `curl_multi`.
 *
 * Fonctionnement : chaque tâche (la recherche d'UNE source) tourne dans sa
 * Fiber. Tout appel HTTP de connecteur passe par `ConnectorApiService::apiRaw`
 * (un seul point, vérifié) : sous ce planificateur, il ne bloque plus — il
 * confie sa requête à `curl_multi` et suspend sa Fiber ; le planificateur fait
 * avancer TOUTES les requêtes à la fois et réveille chaque Fiber à l'arrivée
 * de sa réponse. Les services ne savent rien de tout cela : leur code, leurs
 * erreurs, leur retry sur 401 sont inchangés.
 *
 * Règles :
 *   - hors planificateur (tout le reste de l'application), `apiRaw` garde son
 *     transport historique : rien ne change pour une lecture ordinaire ;
 *   - un planificateur imbriqué exécute ses tâches à la suite (jamais deux
 *     boucles curl_multi l'une dans l'autre) ;
 *   - une tâche qui échoue rend son exception, les autres continuent ;
 *   - délai GLOBAL : au-delà, les tâches encore en vol sont abandonnées et
 *     rendues en erreur « trop lent » — une source muette ne retient pas les
 *     autres ;
 *   - aucun proxy (CURLOPT_PROXY vide) : le transport historique
 *     (`file_get_contents`) n'en emploie pas non plus, les deux chemins
 *     joignent les mêmes adresses de la même façon.
 */
final class ParallelHttp
{
    private static ?self $current = null;

    private \CurlMultiHandle $multi;

    /** @var array<int, \Fiber> identifiant du handle curl → Fiber qui l'attend */
    private array $waiting = [];

    /** @var array<int, \CurlHandle> */
    private array $handles = [];

    /** @var array<int, string> identifiant du handle → corps reçu */
    private array $bodies = [];

    private function __construct()
    {
        $this->multi = curl_multi_init();
    }

    /**
     * Le code appelant tourne-t-il sous le planificateur, dans une de ses
     * Fibers ? (Une autre Fiber, ou aucune, garde le transport historique.)
     */
    public static function active(): bool
    {
        return self::$current !== null && \Fiber::getCurrent() !== null;
    }

    /**
     * Exécute des tâches en parallèle. Chaque résultat est
     * `['ok' => valeur]` ou `['error' => \Throwable]`, rangé sous la clé de sa
     * tâche, dans l'ordre des tâches.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, array{ok?: mixed, error?: \Throwable}>
     */
    public static function run(array $tasks, float $deadlineSec = 25.0): array
    {
        if (self::$current !== null || !function_exists('curl_multi_init')) {
            return self::runSequential($tasks);
        }
        $sched = new self();
        self::$current = $sched;
        try {
            return $sched->loop($tasks, microtime(true) + max(1.0, $deadlineSec));
        } finally {
            self::$current = null;
            foreach ($sched->handles as $ch) {
                curl_multi_remove_handle($sched->multi, $ch);
            }
            curl_multi_close($sched->multi);
        }
    }

    /**
     * Requête HTTP depuis une Fiber du planificateur : suspend la Fiber
     * jusqu'à la réponse. Même contrat que le transport historique d'apiRaw :
     * statut (0 si inconnu) et corps (false si la requête n'a pas abouti).
     *
     * @param list<string> $headers lignes d'en-tête
     * @return array{0:int, 1:string|false}
     */
    public static function request(string $method, string $url, array $headers, string $payload, int $timeout): array
    {
        if (!self::active()) {
            throw new \LogicException('ParallelHttp::request hors du planificateur.');
        }
        $ch = curl_init($url);
        $id = spl_object_id($ch);
        $sched = self::$current;
        $sched->bodies[$id] = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY          => '',
            CURLOPT_ENCODING       => '',
            CURLOPT_WRITEFUNCTION  => static function ($h, string $chunk) use ($sched, $id): int {
                $sched->bodies[$id] .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($payload !== '' || !in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $sched->handles[$id] = $ch;
        curl_multi_add_handle($sched->multi, $ch);

        // Rend la main au planificateur ; il nous réveille avec la réponse.
        $res = \Fiber::suspend($ch);
        return is_array($res) ? $res : [0, false];
    }

    // ===================== Interne =====================

    /**
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, array{ok?: mixed, error?: \Throwable}>
     */
    private function loop(array $tasks, float $deadline): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = null;
            $fiber = new \Fiber(static fn() => $task());
            $this->step((string)$key, $fiber, static fn() => $fiber->start(), $results);
        }

        while ($this->waiting !== []) {
            if (microtime(true) >= $deadline) {
                break;
            }
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($this->multi)) !== false) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                $fiber = $this->waiting[$id] ?? null;
                unset($this->waiting[$id]);
                $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = $info['result'] === CURLE_OK ? ($this->bodies[$id] ?? '') : false;
                curl_multi_remove_handle($this->multi, $ch);
                unset($this->handles[$id], $this->bodies[$id]);
                if ($fiber !== null) {
                    $key = array_search($fiber, $this->fibers, true);
                    $this->step((string)$key, $fiber, static fn() => $fiber->resume([$httpStatus, $body]), $results);
                }
            }
            if ($this->waiting !== [] && curl_multi_select($this->multi, 0.2) === -1) {
                usleep(20000);
            }
        }

        // Délai dépassé : ce qui reste en vol est abandonné, et dit.
        foreach ($results as $key => $r) {
            if ($r === null) {
                $results[$key] = ['error' => new \RuntimeException('Source trop lente : abandonnée pour ne pas retenir les autres.')];
            }
        }
        $this->waiting = [];
        $this->fibers = [];
        return $results;
    }

    /** @var array<string, \Fiber> */
    private array $fibers = [];

    /**
     * Fait avancer une Fiber jusqu'à sa prochaine suspension (une requête de
     * plus) ou sa fin (un résultat), et range l'issue.
     */
    private function step(string $key, \Fiber $fiber, \Closure $advance, array &$results): void
    {
        $this->fibers[$key] = $fiber;
        try {
            $suspended = $advance();
            if ($fiber->isTerminated()) {
                $results[$key] = ['ok' => $fiber->getReturn()];
                unset($this->fibers[$key]);
                return;
            }
            if ($suspended instanceof \CurlHandle) {
                $this->waiting[spl_object_id($suspended)] = $fiber;
                return;
            }
            // Suspension étrangère au planificateur : on ne sait pas la servir.
            $results[$key] = ['error' => new \RuntimeException('Tâche suspendue hors requête HTTP.')];
            unset($this->fibers[$key]);
        } catch (\Throwable $e) {
            $results[$key] = ['error' => $e];
            unset($this->fibers[$key]);
        }
    }

    /**
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, array{ok?: mixed, error?: \Throwable}>
     */
    private static function runSequential(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $key => $task) {
            try {
                $out[$key] = ['ok' => $task()];
            } catch (\Throwable $e) {
                $out[$key] = ['error' => $e];
            }
        }
        return $out;
    }
}
