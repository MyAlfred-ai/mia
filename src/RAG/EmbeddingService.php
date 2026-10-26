<?php

declare(strict_types=1);

/**
 * Service d'embeddings multi-provider (bases de connaissances).
 * Providers/modèles déclarés dans config/embeddings_catalog.php (source
 * unique) ; clés API lues depuis ApiKey (chiffrées en base).
 *
 * Contrairement aux adapters LLM (qui streament les erreurs en texte),
 * ce service THROW une RuntimeException en cas d'échec : les appelants
 * (pipeline d'ingestion, retrieval chat) attrapent et basculent en
 * fallback lexical ou passent le document en statut failed.
 */
class EmbeddingService
{
    /**
     * Ordre de préférence pour le choix automatique du provider — et liste des
     * fournisseurs capables de vectoriser (AdminAttention la lit pour signaler
     * une instance sans aucune clé de vectorisation, sans rien déchiffrer).
     */
    public const PROVIDER_ORDER = ['openai', 'mistral', 'gemini'];

    private static ?array $catalog = null;

    public static function catalog(): array
    {
        if (self::$catalog === null) {
            self::$catalog = require ROOT . '/config/embeddings_catalog.php';
        }
        return self::$catalog;
    }

    /**
     * Providers d'embedding réellement utilisables : présents au catalogue
     * ET disposant d'une clé API configurée. Ordre = PROVIDER_ORDER.
     *
     * @return array<string, array> provider → entrée catalogue
     */
    public static function availableProviders(): array
    {
        $out = [];
        foreach (self::PROVIDER_ORDER as $provider) {
            $entry = self::catalog()[$provider] ?? null;
            if ($entry !== null && ApiKey::getDecrypted($provider)) {
                $out[$provider] = $entry;
            }
        }
        return $out;
    }

    /**
     * Choix par défaut à la création d'une base :
     * premier provider disponible + son default_model.
     *
     * @return array{provider:string,model:string,dims:int}|null
     */
    public static function defaultChoice(): ?array
    {
        foreach (self::availableProviders() as $provider => $entry) {
            $model = (string)$entry['default_model'];
            $dims  = (int)($entry['models'][$model]['dims'] ?? 0);
            if ($dims > 0) {
                return ['provider' => $provider, 'model' => $model, 'dims' => $dims];
            }
        }
        return null;
    }

    /**
     * Choix nominal du catalogue, SANS exiger de clé API : premier provider de
     * PROVIDER_ORDER + son default_model. Sert à créer une base quand aucun
     * fournisseur n'est configuré (déploiement Anthropic seul, par exemple) —
     * les vecteurs échoueront, la base restera interrogeable en fallback
     * lexical, et un « Réindexer » la vectorisera une fois la clé saisie.
     *
     * @return array{provider:string,model:string,dims:int}
     */
    public static function nominalDefault(): array
    {
        foreach (self::PROVIDER_ORDER as $provider) {
            $entry = self::catalog()[$provider] ?? null;
            if ($entry === null) {
                continue;
            }
            $model = (string)$entry['default_model'];
            $dims  = (int)($entry['models'][$model]['dims'] ?? 0);
            if ($dims > 0) {
                return ['provider' => $provider, 'model' => $model, 'dims' => $dims];
            }
        }
        throw new \RuntimeException('Catalogue d\'embeddings vide ou invalide.');
    }

    /** Dimensions déclarées au catalogue pour un couple provider/modèle. */
    public static function dimsFor(string $provider, string $model): ?int
    {
        $dims = self::catalog()[$provider]['models'][$model]['dims'] ?? null;
        return $dims !== null ? (int)$dims : null;
    }

    /**
     * Choix « provider::modèle » validé contre les fournisseurs UTILISABLES
     * (entrées de catalogue dont la clé API est configurée — cf.
     * availableProviders()). Null quand la saisie ne désigne rien
     * d'utilisable : fournisseur inconnu ou sans clé, modèle absent de son
     * catalogue, forme invalide.
     *
     * L'appelant décide du repli, et les deux cas diffèrent : une CRÉATION
     * retombe sur le choix par défaut (rien n'existe encore), une
     * RÉINDEXATION refuse — changer en silence le modèle d'une base existante
     * re-vectoriserait tous ses documents sur un modèle que personne n'a
     * choisi. Pure : $available est injecté, testable sans base ni clé.
     *
     * @param array<string, array> $available fournisseur → entrée de catalogue
     * @return array{provider:string,model:string,dims:int}|null
     */
    public static function resolveChoice(string $raw, array $available): ?array
    {
        $raw = trim($raw);
        if (!str_contains($raw, '::')) {
            return null;
        }
        [$provider, $model] = explode('::', $raw, 2);
        if ($provider === '' || $model === '' || !isset($available[$provider])) {
            return null;
        }
        $dims = (int)($available[$provider]['models'][$model]['dims'] ?? 0);
        return $dims > 0
            ? ['provider' => $provider, 'model' => $model, 'dims' => $dims]
            : null;
    }

    /** Libellé d'un modèle tel que l'écran l'affiche (repli : identifiant du modèle). */
    public static function label(string $provider, string $model): string
    {
        $label = self::catalog()[$provider]['models'][$model]['label'] ?? null;
        return is_string($label) && $label !== '' ? $label : $model;
    }

    /**
     * Embeddings d'un lot de textes. Découpe en sous-lots (max_batch du
     * catalogue) et vérifie que chaque réponse contient autant de vecteurs
     * que de textes envoyés.
     *
     * @param  list<string> $texts
     * @return list<list<float>> même ordre que $texts
     * @throws RuntimeException clé manquante, provider inconnu, échec API
     */
    public static function embedBatch(string $provider, string $model, array $texts): array
    {
        return self::embedBatchWithUsage($provider, $model, $texts)['vectors'];
    }

    /**
     * Même chose, avec la CONSOMMATION du lot : tokens facturés par le
     * fournisseur (`usage.prompt_tokens` chez OpenAI et Mistral) ou, quand
     * l'API n'en renvoie pas (Gemini), une estimation par la longueur des
     * textes — signalée par `estimated`. L'appelant journalise via
     * `UsageMeter::embeddings()` : ce service ne sait pas à qui imputer.
     *
     * @param  list<string> $texts
     * @return array{vectors:list<list<float>>, tokens:int, estimated:bool, requests:int}
     * @throws RuntimeException clé manquante, provider inconnu, échec API
     */
    public static function embedBatchWithUsage(string $provider, string $model, array $texts): array
    {
        if ($texts === []) {
            return ['vectors' => [], 'tokens' => 0, 'estimated' => false, 'requests' => 0];
        }
        $entry = self::catalog()[$provider] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("Provider d'embedding inconnu : $provider");
        }
        $apiKey = ApiKey::getDecrypted($provider);
        if (!$apiKey) {
            throw new \RuntimeException("Clé API $provider manquante pour les embeddings.");
        }

        $batchSize = max(1, (int)($entry['max_batch'] ?? 32));
        $vectors   = [];
        $tokens    = 0;
        $estimated = false;
        $requests  = 0;
        foreach (array_chunk(array_values($texts), $batchSize) as $batch) {
            try {
                $got = match ($provider) {
                    'openai', 'mistral' => self::embedOpenAiCompatible($provider, $entry, $model, $apiKey, $batch),
                    'gemini'            => self::embedGemini($entry, $model, $apiKey, $batch),
                    default             => throw new \RuntimeException("Provider d'embedding non géré : $provider"),
                };
            } catch (\Throwable $e) {
                // Les lots déjà vectorisés sont payés au fournisseur, même si
                // la suite échoue : l'appelant doit pouvoir les journaliser.
                if ($tokens > 0) {
                    throw new EmbeddingPartialException($e->getMessage(), $tokens, $estimated, $e);
                }
                throw $e;
            }
            $requests++;
            if (count($got['vectors']) !== count($batch)) {
                throw new \RuntimeException(sprintf(
                    'Réponse embeddings %s incomplète (%d vecteurs pour %d textes).',
                    $provider, count($got['vectors']), count($batch)
                ));
            }
            foreach ($got['vectors'] as $v) {
                $vectors[] = $v;
            }
            if ($got['tokens'] !== null) {
                $tokens += $got['tokens'];
            } else {
                $estimated = true;
                foreach ($batch as $text) {
                    $tokens += TextChunker::estimateTokens($text);
                }
            }
        }
        return ['vectors' => $vectors, 'tokens' => $tokens, 'estimated' => $estimated, 'requests' => $requests];
    }

    /** @return list<float> */
    public static function embedOne(string $provider, string $model, string $text): array
    {
        $vectors = self::embedBatch($provider, $model, [$text]);
        return $vectors[0];
    }

    /**
     * Un seul texte, avec sa consommation (embedding d'une question de chat).
     * @return array{vector:list<float>, tokens:int, estimated:bool}
     */
    public static function embedOneWithUsage(string $provider, string $model, string $text): array
    {
        $got = self::embedBatchWithUsage($provider, $model, [$text]);
        return ['vector' => $got['vectors'][0], 'tokens' => $got['tokens'], 'estimated' => $got['estimated']];
    }

    // ===================== Implémentations provider =====================

    /**
     * OpenAI et Mistral partagent le même contrat /v1/embeddings
     * ({model, input:[...]} → {data:[{index, embedding}], usage:{prompt_tokens}}).
     * @param list<string> $batch
     * @return array{vectors:list<list<float>>, tokens:?int}
     */
    private static function embedOpenAiCompatible(string $provider, array $entry, string $model, string $apiKey, array $batch): array
    {
        $payload = ['model' => $model, 'input' => $batch];
        $reqDims = $entry['models'][$model]['request_dimensions'] ?? null;
        if ($reqDims !== null) {
            $payload['dimensions'] = (int)$reqDims;
        }

        $data = self::postJson(
            (string)$entry['endpoint'],
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            $payload,
            $provider
        );

        $rows = $data['data'] ?? null;
        if (!is_array($rows)) {
            throw new \RuntimeException("Réponse embeddings $provider invalide (champ data absent).");
        }
        // L'API garantit l'ordre mais fournit index : on trie par prudence.
        usort($rows, static fn($a, $b) => (int)($a['index'] ?? 0) <=> (int)($b['index'] ?? 0));
        $usage  = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $tokens = isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens']
            : (isset($usage['total_tokens']) ? (int)$usage['total_tokens'] : null);
        return [
            'vectors' => array_map(static fn($r) => array_map('floatval', (array)($r['embedding'] ?? [])), $rows),
            'tokens'  => $tokens,
        ];
    }

    /**
     * Gemini batchEmbedContents ({requests:[{model, content, outputDimensionality}]}
     * → {embeddings:[{values}]}), clé en header x-goog-api-key. Aucun
     * compteur de tokens dans la réponse : `tokens` vaut null, l'appelant
     * estime.
     * @param list<string> $batch
     * @return array{vectors:list<list<float>>, tokens:?int}
     */
    private static function embedGemini(array $entry, string $model, string $apiKey, array $batch): array
    {
        $outDims  = $entry['models'][$model]['output_dimensionality'] ?? null;
        $requests = [];
        foreach ($batch as $text) {
            $req = [
                'model'   => 'models/' . $model,
                'content' => ['parts' => [['text' => $text]]],
            ];
            if ($outDims !== null) {
                $req['outputDimensionality'] = (int)$outDims;
            }
            $requests[] = $req;
        }

        $endpoint = str_replace('{model}', $model, (string)$entry['endpoint']);
        $data     = self::postJson(
            $endpoint,
            ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            ['requests' => $requests],
            'gemini'
        );

        $rows = $data['embeddings'] ?? null;
        if (!is_array($rows)) {
            throw new \RuntimeException('Réponse embeddings Gemini invalide (champ embeddings absent).');
        }
        return [
            'vectors' => array_map(static fn($r) => array_map('floatval', (array)($r['values'] ?? [])), $rows),
            'tokens'  => null,
        ];
    }

    /**
     * POST JSON avec statut vérifié. Le corps d'erreur du provider est loggé
     * mais JAMAIS inclus dans l'exception (il peut contenir la clé API).
     */
    private static function postJson(string $url, array $headers, array $payload, string $provider): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout'       => 60,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $ctx);
        $status   = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }

        if ($response === false || $response === '') {
            throw new \RuntimeException("API embeddings $provider injoignable (statut HTTP " . ($status ?: 'inconnu') . ').');
        }
        if ($status >= 400) {
            error_log("[KB] embeddings $provider HTTP $status : " . mb_substr($response, 0, 500));
            throw new \RuntimeException("Erreur API embeddings $provider (HTTP $status).");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Réponse embeddings $provider illisible (JSON invalide).");
        }
        return $data;
    }
}

/**
 * Vectorisation interrompue après des lots déjà facturés par le fournisseur :
 * porte leurs jetons, pour qu'ils restent au grand livre (ils en sortaient
 * avec l'exception).
 */
final class EmbeddingPartialException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $tokens, public readonly bool $estimated, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
