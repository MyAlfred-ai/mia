<?php

declare(strict_types=1);

/**
 * Dates d'arrêt des modèles relevées CHEZ L'ÉDITEUR, une fois par jour.
 *
 * Le problème résolu : l'arrêt d'un modèle n'était connu de MIA que par le
 * bloc `retirement` du catalogue (`config/llm_catalog.php`), écrit à la main
 * après relecture de la page Deprecations à chaque courriel d'OpenAI. Un
 * courriel manqué, et un modèle coupé restait proposé dans le chat, en échec
 * à chaque message. OpenAI publie la même information par son API : la page
 * Deprecations (relue le 2026-11-12) dit « call the List models endpoint
 * (`GET /v1/models`) and check each model's optional `shutdown_date` field »,
 * et la référence de l'endpoint (relue le même jour) le décrit ainsi :
 * `shutdown_date: optional string or null` — « The date when the model will
 * shut down, or null if not announced », `format: date` (AAAA-MM-JJ).
 *
 * Ce que fait la classe :
 *   - `tick()` (entretien du worker, et `JobQueue::maybeMaintain()` sans
 *     worker) enfile un job de maintenance `llm_shutdowns` quand le relevé est
 *     dû — jamais d'appel réseau dans une requête d'utilisateur ;
 *   - `refresh()` (le job) appelle `GET /v1/models` avec la clé de
 *     l'instance — lecture seule, jamais facturée —, sous un verrou MySQL, et
 *     garde dans `settings` les dates des modèles qui comptent pour cette
 *     instance (catalogue, `retired_models`, lignes `llm_models`, modèle de
 *     référence de la clé) ;
 *   - `dateFor()` est lu par `LlmCatalog::retirement()`, en REPLI du
 *     catalogue : le catalogue reste prioritaire, il porte le remplaçant
 *     désigné, que l'API ne donne pas.
 *
 * Ne lève jamais : une panne (réseau, clé refusée, réponse illisible) ne change
 * rien aux dates gardées, laisse une ligne `[LLM arrêts]` au journal et un état
 * lisible sur la page Fournisseurs et modèles.
 *
 * Un modèle ABSENT de la liste n'est pas « annulé » : un modèle coupé disparaît
 * de `GET /v1/models`, et oublier sa date le ferait revenir dans le chat le
 * lendemain de sa coupure. Seul un `shutdown_date` explicitement null (ou
 * absent sur un modèle listé) retire une date gardée — l'éditeur l'a annulée.
 */
final class LlmShutdownWatch
{
    /** Dates relevées : `{fournisseur: {modèle: "AAAA-MM-JJ"}}`. */
    public const SETTING = 'llm_api_shutdowns';
    /** État du relevé : `{fournisseur: {at, ok, next, error, status, listed, dated}}`. */
    public const SETTING_CHECK = 'llm_api_shutdowns_check';

    public const LOCK = 'mia_llm_shutdowns';
    /** Opération du job de maintenance (MaintenanceJob). */
    public const JOB_OP = 'llm_shutdowns';

    /** Après un relevé réussi, ou une panne qui ne passera pas seule (clé refusée). */
    public const INTERVAL_SEC = 86400;
    /** Après une panne passagère (réseau, limite de débit, 5xx), ou un job resté sans suite. */
    public const RETRY_SEC = 3600;

    public const TIMEOUT_SEC = 15;
    public const CONNECT_TIMEOUT_SEC = 5;
    /** La liste pèse quelques dizaines de Ko : au-delà, ce n'est pas elle. */
    public const MAX_BODY_BYTES = 2_000_000;

    /**
     * Fournisseurs qui publient leurs dates d'arrêt par l'API. Anthropic,
     * Google et Mistral listent leurs modèles sans date d'arrêt (relu le
     * 2026-11-12) : seul OpenAI.
     */
    public const PROVIDERS = [
        // Adresse de la liste : `endpoints.models` du catalogue (source unique,
        // redirigée par les tests comme les autres adresses).
        'openai' => [
            'label'      => 'OpenAI',
            'source_url' => 'https://developers.openai.com/api/docs/deprecations',
        ],
    ];

    /** Causes d'une panne, gardées en CODE (traduites à l'affichage). */
    public const ERR_AUTH       = 'auth';
    public const ERR_PERMISSION = 'permission';
    public const ERR_RATE       = 'rate';
    public const ERR_SERVER     = 'server';
    public const ERR_NETWORK    = 'network';
    public const ERR_INVALID    = 'invalid';
    public const ERR_OTHER      = 'other';

    /** Magasin en mémoire (tests unitaires) : null = table `settings`. */
    private static ?array $memory = null;
    private static ?array $memoryChecks = null;

    /** Dernière valeur brute lue et sa forme décodée (lu à chaque retirement()). */
    private static ?string $memoRaw = null;
    private static array $memoDecoded = [];

    // ============================================================ Lecture

    /** Date d'arrêt relevée chez l'éditeur, ou null. Ne lève jamais. */
    public static function dateFor(string $provider, string $model): ?string
    {
        if ($model === '' || !isset(self::PROVIDERS[$provider])) {
            return null;
        }
        $date = self::reported($provider)[$model] ?? null;
        return is_string($date) ? $date : null;
    }

    /**
     * Toutes les dates relevées pour un fournisseur : `[modèle => date]`.
     *
     * @return array<string,string>
     */
    public static function reported(string $provider): array
    {
        $all = self::readDates();
        return is_array($all[$provider] ?? null) ? $all[$provider] : [];
    }

    /** Adresse de la page qui fait foi (lien d'un arrêt relevé). */
    public static function sourceUrl(string $provider): string
    {
        return (string)(self::PROVIDERS[$provider]['source_url'] ?? '');
    }

    public static function providerLabel(string $provider): string
    {
        return (string)(self::PROVIDERS[$provider]['label'] ?? $provider);
    }

    /**
     * État du dernier relevé d'un fournisseur, pour l'écran : null tant qu'aucun
     * relevé n'a été tenté.
     *
     * @return array{at:int, ok:int, error:string, status:int, dated:int}|null
     */
    public static function status(string $provider): ?array
    {
        $c = self::readChecks()[$provider] ?? null;
        if (!is_array($c) || (int)($c['at'] ?? 0) <= 0) {
            return null;
        }
        return [
            'at'     => (int)$c['at'],
            'ok'     => (int)($c['ok'] ?? 0),
            'error'  => (string)($c['error'] ?? ''),
            'status' => (int)($c['status'] ?? 0),
            'dated'  => (int)($c['dated'] ?? 0),
        ];
    }

    /** Premier instant (horodatage) où le relevé est permis ; 0 = tout de suite. */
    public static function nextCheck(string $provider): int
    {
        return (int)(self::readChecks()[$provider]['next'] ?? 0);
    }

    /** Cause d'une panne, lisible par un administrateur. */
    public static function errorLabel(string $code): string
    {
        return match ($code) {
            self::ERR_AUTH       => t('clé API refusée'),
            self::ERR_PERMISSION => t('la clé n\'a pas le droit de lister les modèles (autorisation « Models » en lecture)'),
            self::ERR_RATE       => t('limite de débit atteinte, nouvel essai dans une heure'),
            self::ERR_SERVER     => t('service momentanément indisponible, nouvel essai dans une heure'),
            self::ERR_NETWORK    => t('service injoignable, nouvel essai dans une heure'),
            self::ERR_INVALID    => t('réponse illisible'),
            default              => t('erreur inattendue'),
        };
    }

    // ===================================================== Formes pures

    /**
     * Lit la réponse de `GET /v1/models` : `[modèle => date|null]`. Null si la
     * réponse n'a pas la forme d'une liste — rien ne doit alors changer.
     *
     * Une date mal formée vaut null : jamais une date inventée.
     *
     * @return array<string,?string>|null
     */
    public static function parseList(mixed $decoded): ?array
    {
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }
        $out = [];
        foreach ($decoded['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > 200) {
                continue;
            }
            $out[$id] = self::validDate($item['shutdown_date'] ?? null);
        }
        return $out;
    }

    /** « AAAA-MM-JJ » d'un vrai jour, sinon null. */
    public static function validDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : null;
    }

    /**
     * Nouvelles dates gardées pour un fournisseur.
     *
     *   - modèle listé avec une date → cette date ;
     *   - modèle listé sans date (null ou champ absent) → plus de date
     *     (l'éditeur l'a annulée, ou ne l'a jamais annoncée) ;
     *   - modèle absent de la liste → la date déjà gardée reste : un modèle
     *     coupé disparaît de la liste, l'oublier le ferait revenir dans le chat ;
     *   - seuls les modèles qui comptent pour l'instance (`$relevant`) sont
     *     gardés : la liste d'OpenAI compte des modèles d'image, de voix,
     *     d'embeddings… que le catalogue LLM ne lit jamais.
     *
     * @param array<string,string>  $previous dates déjà gardées
     * @param array<string,?string> $listed   parseList()
     * @param list<string>          $relevant modèles qui comptent
     * @return array<string,string> trié par modèle (écriture stable)
     */
    public static function merge(array $previous, array $listed, array $relevant): array
    {
        $out = [];
        foreach (array_unique($relevant) as $model) {
            $model = (string)$model;
            if ($model === '') {
                continue;
            }
            if (array_key_exists($model, $listed)) {
                if ($listed[$model] !== null) {
                    $out[$model] = $listed[$model];
                }
            } elseif (isset($previous[$model]) && self::validDate($previous[$model]) !== null) {
                $out[$model] = (string)$previous[$model];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Le relevé est-il dû ? (`next` = premier instant permis, 0 = tout de suite.) */
    public static function due(array $check, int $now): bool
    {
        return $now >= (int)($check['next'] ?? 0);
    }

    /** Code d'une panne d'après le statut HTTP (0 = rien reçu). */
    public static function errorCode(int $status): string
    {
        return match (true) {
            $status === 0                   => self::ERR_NETWORK,
            $status === 401                 => self::ERR_AUTH,
            $status === 403                 => self::ERR_PERMISSION,
            $status === 429                 => self::ERR_RATE,
            $status >= 500                  => self::ERR_SERVER,
            default                         => self::ERR_OTHER,
        };
    }

    /** Délai avant le prochain essai après une panne : court si elle passera seule. */
    public static function failureDelay(string $code): int
    {
        return in_array($code, [self::ERR_NETWORK, self::ERR_RATE, self::ERR_SERVER], true)
            ? self::RETRY_SEC
            : self::INTERVAL_SEC;
    }

    /**
     * Ce qui a changé entre deux relevés, pour le journal.
     *
     * @param array<string,string> $before
     * @param array<string,string> $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $model => $date) {
            if (!isset($before[$model])) {
                $out[] = $model . ' : ' . $date;
            } elseif ($before[$model] !== $date) {
                $out[] = $model . ' : ' . $before[$model] . ' → ' . $date;
            }
        }
        foreach ($before as $model => $date) {
            if (!isset($after[$model])) {
                $out[] = $model . ' : ' . $date . ' annulée';
            }
        }
        return $out;
    }

    /**
     * Relevé d'UN fournisseur, sans base : appelle l'API par `$transport`,
     * rend les nouvelles dates et l'état. Une panne rend les dates d'avant
     * telles quelles.
     *
     * `$transport(string $url, array $headers): array{status:int, body:string}`
     * peut lever : c'est une panne réseau.
     *
     * @param array<string,string> $previous
     * @param list<string>         $relevant
     * @return array{dates: array<string,string>, check: array<string,mixed>, changes: list<string>}
     */
    public static function fetchProvider(
        string $provider,
        string $apiKey,
        array $previous,
        array $relevant,
        callable $transport,
        int $now
    ): array {
        $url = (string)(LlmCatalog::endpoint($provider, 'models') ?? '');
        $status = 0;
        $body = '';
        try {
            if ($url === '') {
                throw new \RuntimeException('adresse de la liste des modèles absente du catalogue');
            }
            $resp = $transport($url, [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ]);
            $status = (int)($resp['status'] ?? 0);
            $body = (string)($resp['body'] ?? '');
        } catch (\Throwable) {
            $status = 0;
        }

        $listed = null;
        $error = '';
        if ($status === 200) {
            $listed = self::parseList(json_decode($body, true));
            if ($listed === null) {
                $error = self::ERR_INVALID;
            }
        } else {
            $error = self::errorCode($status);
        }

        if ($listed === null) {
            return [
                'dates'   => $previous,
                'check'   => ['at' => $now, 'next' => $now + self::failureDelay($error), 'error' => $error, 'status' => $status],
                'changes' => [],
            ];
        }

        $dates = self::merge($previous, $listed, $relevant);
        return [
            'dates'   => $dates,
            'check'   => [
                'at'     => $now,
                'ok'     => $now,
                'next'   => $now + self::INTERVAL_SEC,
                'error'  => '',
                'status' => 200,
                'listed' => count($listed),
                'dated'  => count($dates),
            ],
            'changes' => self::changes($previous, $dates),
        ];
    }

    // ===================================================== Déclenchement

    /**
     * Enfile le relevé quand il est dû (worker : bloc d'entretien ; sans
     * worker : JobQueue::maybeMaintain). Rend true si un job est parti. Ne
     * lève jamais, et ne fait aucun appel réseau.
     */
    public static function tick(?int $now = null): bool
    {
        $now ??= time();
        try {
            $checks = self::readChecks();
            $enqueue = false;
            foreach (array_keys(self::PROVIDERS) as $provider) {
                // L'échéance d'abord : elle se lit dans le cache des réglages,
                // la clé demande une requête.
                if (!self::due($checks[$provider] ?? [], $now) || !self::hasKey($provider)) {
                    continue;
                }
                // Un job enfilé mais jamais exécuté (aucun worker, aucun fork)
                // ne doit pas en enfiler un par minute : nouvel essai dans une
                // heure, que refresh() remplacera par la vraie échéance.
                $checks[$provider] = is_array($checks[$provider] ?? null) ? $checks[$provider] : [];
                $checks[$provider]['next'] = $now + self::RETRY_SEC;
                $enqueue = true;
            }
            if (!$enqueue) {
                return false;
            }
            self::writeChecks($checks);
            JobQueue::enqueueMaintenance(self::JOB_OP, 1, 'Dates d’arrêt des modèles', false);
            return true;
        } catch (\Throwable $e) {
            error_log('[LLM arrêts] déclenchement impossible : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Relevé à la prochaine occasion (clé OpenAI enregistrée ou changée) : la
     * nouvelle clé peut voir d'autres modèles, ou réparer une clé refusée.
     */
    public static function soon(string $provider): void
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return;
        }
        try {
            $checks = self::readChecks();
            $checks[$provider] = (is_array($checks[$provider] ?? null) ? $checks[$provider] : []);
            $checks[$provider]['next'] = 0;
            self::writeChecks($checks);
        } catch (\Throwable $e) {
            error_log('[LLM arrêts] relevé anticipé impossible : ' . $e->getMessage());
        }
    }

    /**
     * Le relevé lui-même (job `llm_shutdowns`), sous verrou : un seul à la
     * fois, les autres repartent aussitôt. Ne lève jamais.
     *
     * @param callable|null $transport cf. fetchProvider() ; null = HTTP réel
     * @return array<string,mixed> résumé (résultat du job)
     */
    public static function refresh(?callable $transport = null, ?int $now = null): array
    {
        $now ??= time();
        $transport ??= [self::class, 'httpGet'];
        try {
            $db = Database::getInstance();
            $get = $db->prepare('SELECT GET_LOCK(?, 0)');
            $get->execute([self::LOCK]);
            if ((int)$get->fetchColumn() !== 1) {
                return ['skipped' => 'verrou'];
            }
        } catch (\Throwable $e) {
            error_log('[LLM arrêts] verrou impossible : ' . $e->getMessage());
            return ['skipped' => 'base'];
        }

        $summary = [];
        try {
            foreach (array_keys(self::PROVIDERS) as $provider) {
                $summary[$provider] = self::refreshProvider($provider, $transport, $now);
            }
        } catch (\Throwable $e) {
            error_log('[LLM arrêts] relevé impossible : ' . $e->getMessage());
            $summary['error'] = 'exception';
        } finally {
            try {
                $db->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
            } catch (\Throwable) {
                // Le verrou tombe avec la connexion.
            }
        }
        return $summary;
    }

    /** @return array<string,mixed> */
    private static function refreshProvider(string $provider, callable $transport, int $now): array
    {
        $key = trim((string)(ApiKey::getDecrypted($provider) ?? ''));
        if ($key === '') {
            return ['skipped' => 'cle'];
        }

        // Relu SOUS le verrou : un autre relevé a pu écrire entre-temps.
        Settings::refresh();
        $dates  = self::readDates();
        $checks = self::readChecks();
        $before = is_array($dates[$provider] ?? null) ? $dates[$provider] : [];

        $result = self::fetchProvider($provider, $key, $before, self::relevantModels($provider), $transport, $now);

        // L'état d'avant complète celui-ci : une panne garde la date du
        // dernier relevé réussi et le nombre de dates connues.
        $check = is_array($checks[$provider] ?? null) ? $checks[$provider] : [];
        $checks[$provider] = $result['check'] + $check;
        self::writeChecks($checks);

        $label = self::providerLabel($provider);
        if ($result['check']['error'] !== '') {
            error_log('[LLM arrêts] ' . $label . ' : relevé impossible (' . $result['check']['error']
                . ($result['check']['status'] > 0 ? ', HTTP ' . $result['check']['status'] : '') . ') — dates gardées telles quelles');
            return ['ok' => false, 'error' => $result['check']['error'], 'status' => $result['check']['status']];
        }

        if ($result['dates'] !== $before) {
            $dates[$provider] = $result['dates'];
            self::writeDates($dates);
            error_log('[LLM arrêts] ' . $label . ' : dates d\'arrêt mises à jour — ' . implode(' ; ', $result['changes']));
        }
        return ['ok' => true, 'listed' => $result['check']['listed'], 'dated' => $result['check']['dated'], 'changes' => $result['changes']];
    }

    /**
     * Modèles qui comptent pour l'instance : ceux du catalogue (le défaut, la
     * recherche, un modèle qu'on active demain), ceux que MIA ne propose plus
     * (`retired_models`), toute ligne `llm_models` du fournisseur (activée ou
     * non : on ne réactive pas un modèle coupé) et le modèle de référence de
     * la clé — souvent saisi en texte libre avant le 05/03/2026.
     *
     * @return list<string>
     */
    public static function relevantModels(string $provider): array
    {
        $ids = array_map('strval', array_keys(LlmCatalog::models($provider)));
        $retired = LlmCatalog::provider($provider)['retired_models'] ?? [];
        if (is_array($retired)) {
            $ids = array_merge($ids, array_map('strval', array_keys($retired)));
        }
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT model FROM llm_models WHERE provider = ?');
        $stmt->execute([$provider]);
        $ids = array_merge($ids, array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $stmt = $db->prepare('SELECT model FROM api_keys WHERE provider = ?');
        $stmt->execute([$provider]);
        $ids = array_merge($ids, array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_unique(array_filter(array_map('trim', $ids), static fn(string $m): bool => $m !== '')));
    }

    private static function hasKey(string $provider): bool
    {
        $row = ApiKey::findByProvider($provider);
        return $row !== null && trim((string)($row['key_enc'] ?? '')) !== '';
    }

    /**
     * `GET` en lecture seule : aucune redirection suivie (l'en-tête partirait
     * avec), TLS vérifié, corps borné.
     *
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    public static function httpGet(string $url, array $headers): array
    {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SEC,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    return 0; // interrompt le transfert : ce n'est pas la liste attendue
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = $ok === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false) {
            throw new \RuntimeException('transfert interrompu : ' . curl_error($ch));
        }
        return ['status' => $status, 'body' => $body];
    }

    // ============================================================ Magasin

    /** Magasin en mémoire (tests unitaires : aucune lecture ni écriture en base). */
    public static function useMemoryStore(array $dates = [], array $checks = []): void
    {
        self::$memory = $dates;
        self::$memoryChecks = $checks;
        self::$memoRaw = null;
    }

    /** Rend la table `settings` comme magasin (comportement de l'application). */
    public static function useSettingsStore(): void
    {
        self::$memory = null;
        self::$memoryChecks = null;
        self::$memoRaw = null;
    }

    /** @return array<string,array<string,string>> */
    private static function readDates(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }
        try {
            $raw = (string)Settings::get(self::SETTING, '');
        } catch (\Throwable) {
            return []; // base injoignable : le catalogue seul fait foi
        }
        if ($raw !== self::$memoRaw) {
            self::$memoRaw = $raw;
            self::$memoDecoded = self::decodeDates($raw);
        }
        return self::$memoDecoded;
    }

    /**
     * Forme gardée, relue avec méfiance : un réglage abîmé n'arrête aucun modèle.
     *
     * @return array<string,array<string,string>>
     */
    public static function decodeDates(string $raw): array
    {
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $provider => $models) {
            if (!is_string($provider) || !is_array($models)) {
                continue;
            }
            foreach ($models as $model => $date) {
                $date = self::validDate($date);
                if (is_string($model) && $model !== '' && $date !== null) {
                    $out[$provider][$model] = $date;
                }
            }
        }
        return $out;
    }

    private static function writeDates(array $dates): void
    {
        if (self::$memory !== null) {
            self::$memory = $dates;
            return;
        }
        Settings::set(self::SETTING, $dates === [] ? '' : (string)json_encode($dates, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,array<string,mixed>> */
    private static function readChecks(): array
    {
        if (self::$memoryChecks !== null) {
            return self::$memoryChecks;
        }
        try {
            $data = json_decode((string)Settings::get(self::SETTING_CHECK, ''), true);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? array_filter($data, 'is_array') : [];
    }

    private static function writeChecks(array $checks): void
    {
        if (self::$memoryChecks !== null) {
            self::$memoryChecks = $checks;
            return;
        }
        Settings::set(self::SETTING_CHECK, (string)json_encode($checks, JSON_UNESCAPED_SLASHES));
    }
}
