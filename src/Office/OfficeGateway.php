<?php

declare(strict_types=1);

/**
 * Passerelle Anthropic de « Claude dans Office » — formes PURES.
 *
 * L'add-in d'Anthropic (Excel, Word, PowerPoint) parle l'API Messages
 * d'Anthropic à une « passerelle » de l'organisation (`gateway_api_format:
 * anthropic`) : `POST /v1/messages` (flux SSE), `GET /v1/models`. MIA joue ce
 * rôle sous `/office` (OfficeGatewayController pour le HTTP) :
 *
 *   - le corps de la requête part À L'IDENTIQUE chez Anthropic, avec la clé de
 *     l'instance — l'add-in pilote lui-même ses outils (cellules, formules,
 *     recherche Internet, exécution de code) ;
 *   - la réponse revient À L'OCTET PRÈS, `id` compris : l'add-in reconnaît
 *     l'API Anthropic derrière la passerelle à l'identifiant de la réponse, et
 *     n'ouvre la recherche Internet et l'exécution de code qu'à cette
 *     condition (doc Anthropic, « Differences from signing in with a Claude
 *     account ») ;
 *   - pendant que le flux passe, l'usage est relevé (`message_start`,
 *     `message_delta` — mêmes lectures que l'adaptateur Anthropic du chat) et
 *     compté au grand livre, au compte de la personne (UsageSource::OFFICE_ADDIN).
 *
 * Aucun contenu n'est gardé : seulement les jetons, le modèle, la personne.
 */
final class OfficeGateway
{
    /** Corps de requête accepté (images et fichiers en base64 compris). */
    public const MAX_BODY_BYTES = 32 * 1024 * 1024;

    /** Texte relevé au plus pour estimer une réponse coupée (caractères). */
    private const TEXT_CAP = 400000;

    /**
     * Multiplicateurs de prix qu'Anthropic applique à TOUS les jetons d'une
     * requête (page Pricing relue le 2026-11-08) — l'add-in écrit ses
     * requêtes, MIA ne fait que les relayer : ce qu'il demande se paie.
     *  - mode rapide (`usage.speed: "fast"`) : Opus 5.5 8 $ / 40 $ au lieu de
     *    4 $ / 20 $, Opus 5 et 4.8 10 $ / 50 $ au lieu de 5 $ / 25 $ → ×2 ;
     *  - inférence aux États-Unis (`usage.inference_geo: "us"`, Claude 4.6 et
     *    suivants) → ×1,1.
     * Les deux se cumulent, et s'appliquent aussi au cache.
     */
    public const FAST_MODE_FACTOR   = 2.0;
    public const US_INFERENCE_FACTOR = 1.1;

    /** Erreurs au format Anthropic : statut → type. */
    public const ERROR_TYPES = [
        400 => 'invalid_request_error',
        401 => 'authentication_error',
        403 => 'permission_error',
        404 => 'not_found_error',
        405 => 'invalid_request_error',
        413 => 'request_too_large',
        429 => 'rate_limit_error',
        500 => 'api_error',
        503 => 'api_error',
    ];

    /**
     * Corps d'erreur au format Anthropic — c'est `error.message` que l'add-in
     * affiche. Pure.
     */
    public static function errorBody(int $status, string $message): string
    {
        return (string)json_encode([
            'type'  => 'error',
            'error' => ['type' => self::ERROR_TYPES[$status] ?? 'api_error', 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * En-têtes CORS de TOUTE réponse (erreurs comprises : sans eux, le
     * navigateur jette la réponse et l'add-in ne voit rien). Les en-têtes de
     * requête autorisés reprennent ceux que le navigateur annonce (le SDK
     * d'Anthropic ajoute des `x-stainless-*`) — `*` ne couvrirait pas
     * `Authorization`. Pure.
     *
     * @return array<string, string>
     */
    public static function corsHeaders(string $requested = ''): array
    {
        $names = ['authorization', 'x-api-key', 'content-type', 'accept', 'anthropic-version', 'anthropic-beta'];
        foreach (explode(',', strtolower($requested)) as $h) {
            $h = trim($h);
            if ($h !== '' && preg_match('/^[a-z0-9-]{1,64}$/', $h) === 1 && !in_array($h, $names, true)) {
                $names[] = $h;
            }
        }
        return [
            'Access-Control-Allow-Origin'   => OfficeAddin::ALLOWED_ORIGIN,
            'Access-Control-Allow-Methods'  => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers'  => implode(', ', array_slice($names, 0, 40)),
            'Access-Control-Expose-Headers' => 'request-id, retry-after',
            'Access-Control-Max-Age'        => '600',
            'Vary'                          => 'Origin, Access-Control-Request-Headers',
        ];
    }

    /**
     * Identifiant présenté par l'add-in : `Authorization: Bearer …` (jeton
     * Microsoft, ou jeton personnel avec `gateway_auth_header=authorization`)
     * sinon `x-api-key` (défaut de l'add-in). En-têtes en minuscules. Pure.
     */
    public static function credentialFrom(array $headers): string
    {
        $auth = trim((string)($headers['authorization'] ?? ''));
        if ($auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        return trim((string)($headers['x-api-key'] ?? ''));
    }

    /**
     * Modèles Claude proposés à la personne : ses modèles permis (même règle
     * que le chat — LLMFactory::available() ∩ liste de ses groupes), réduits à
     * ceux d'Anthropic. Un slug réduit au fournisseur (aucun modèle activé)
     * vaut le modèle de la clé, sinon le défaut du catalogue. Pure.
     *
     * @param array<string, string> $allowed slug → libellé
     * @return array<string, string> identifiant Anthropic → libellé
     */
    public static function claudeModels(array $allowed, string $providerFallbackModel): array
    {
        $out = [];
        foreach ($allowed as $slug => $label) {
            $slug = (string)$slug;
            if ($slug === 'anthropic') {
                if ($providerFallbackModel !== '') {
                    $out[$providerFallbackModel] = (string)$label;
                }
                continue;
            }
            if (!str_starts_with($slug, 'anthropic::')) {
                continue;
            }
            $id = substr($slug, strlen('anthropic::'));
            if ($id !== '') {
                $out[$id] = (string)$label;
            }
        }
        return $out;
    }

    /**
     * Réponse de `GET /v1/models`, forme Anthropic. L'ordre est celui du
     * sélecteur (du plus récent au plus ancien) ; `created_at` le reproduit,
     * la date réelle de sortie n'étant pas au catalogue. Pure.
     *
     * @param array<string, string> $models
     */
    public static function modelsPayload(array $models): array
    {
        $data = [];
        $t = gmmktime(0, 0, 0, 1, 1, 2026);
        foreach ($models as $id => $label) {
            $data[] = [
                'type'         => 'model',
                'id'           => (string)$id,
                'display_name' => (string)$label,
                'created_at'   => gmdate('Y-m-d\TH:i:s\Z', $t),
            ];
            $t -= 86400;
        }
        return [
            'data'     => $data,
            'has_more' => false,
            'first_id' => $data[0]['id'] ?? null,
            'last_id'  => $data === [] ? null : $data[count($data) - 1]['id'],
        ];
    }

    /**
     * En-têtes envoyés à Anthropic : la clé de l'instance, la version et les
     * bêtas demandées par l'add-in (nettoyées), rien d'autre — ni son
     * `Authorization`, ni ses en-têtes de navigateur. Pure.
     *
     * @return list<string>
     */
    public static function upstreamHeaders(array $clientHeaders, string $apiKey, string $defaultVersion): array
    {
        $version = trim((string)($clientHeaders['anthropic-version'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) !== 1) {
            $version = $defaultVersion;
        }
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . $version,
        ];
        $beta = strtolower(trim((string)($clientHeaders['anthropic-beta'] ?? '')));
        if ($beta !== '' && preg_match('/^[a-z0-9._,\- ]{1,1000}$/', $beta) === 1) {
            $headers[] = 'anthropic-beta: ' . preg_replace('/\s+/', '', $beta);
        }
        $accept = trim((string)($clientHeaders['accept'] ?? ''));
        $headers[] = 'Accept: ' . (preg_match('#^[a-z*/;=.,\- 0-9+]{1,200}$#i', $accept) === 1 ? $accept : 'application/json');
        return $headers;
    }

    // ================================================================ Relevé d'usage

    /** État initial du relevé d'une réponse. */
    public static function usageState(): array
    {
        return [
            'buffer'     => '',
            'usage'      => ['in' => 0, 'cached' => 0, 'write' => 0, 'out' => 0, 'searches' => 0],
            // Écriture de cache d'une heure (2× l'entrée) et réglages qui
            // changent le prix de toute la requête (cf. priceFactor).
            'write1h'    => 0,
            'speed'      => '',
            'geo'        => '',
            'model'      => '',
            'id'         => '',
            'iterations' => null,
            'text'       => '',
            'stop'       => null,
            'error'      => null,
            'events'     => 0,
        ];
    }

    /**
     * Fait passer un morceau du flux SSE dans le relevé (lignes coupées entre
     * deux morceaux recollées). Ne modifie rien de ce qui part à l'add-in.
     * Pure.
     */
    public static function feedSse(array $state, string $chunk): array
    {
        $state['buffer'] .= $chunk;
        while (($nl = strpos($state['buffer'], "\n")) !== false) {
            $line = rtrim(substr($state['buffer'], 0, $nl), "\r");
            $state['buffer'] = (string)substr($state['buffer'], $nl + 1);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $event = json_decode(ltrim(substr($line, 5)), true);
            if (is_array($event)) {
                $state = self::applyEvent($state, $event);
            }
        }
        // Une ligne démesurée sans fin de ligne n'est pas un évènement SSE.
        if (strlen($state['buffer']) > 4 * 1024 * 1024) {
            $state['buffer'] = '';
        }
        return $state;
    }

    /** Un évènement du flux (ou la réponse entière d'un appel sans flux). Pure. */
    public static function applyEvent(array $state, array $event): array
    {
        $state['events']++;
        $type = (string)($event['type'] ?? '');
        if ($type === 'message_start' || $type === 'message') {
            $msg = $type === 'message' ? $event : (array)($event['message'] ?? []);
            $state['model'] = (string)($msg['model'] ?? $state['model']);
            $state['id']    = (string)($msg['id'] ?? $state['id']);
            if (is_array($msg['usage'] ?? null)) {
                $state['usage'] = AnthropicAdapter::mergeStreamUsage($state['usage'], $msg['usage']);
                $state = self::readPriceFields($state, $msg['usage']);
                if (is_array($msg['usage']['iterations'] ?? null)) {
                    $state['iterations'] = $msg['usage']['iterations'];
                }
            }
            if ($type === 'message') {
                $state['stop'] = $msg['stop_reason'] ?? null;
                foreach ((array)($msg['content'] ?? []) as $block) {
                    if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                        $state = self::addText($state, $block['text']);
                    }
                }
            }
        } elseif ($type === 'message_delta') {
            if (is_array($event['usage'] ?? null)) {
                $state['usage'] = AnthropicAdapter::mergeStreamUsage($state['usage'], $event['usage']);
                $state = self::readPriceFields($state, $event['usage']);
                if (is_array($event['usage']['iterations'] ?? null)) {
                    $state['iterations'] = $event['usage']['iterations'];
                }
            }
            $state['stop'] = $event['delta']['stop_reason'] ?? $state['stop'];
        } elseif ($type === 'content_block_delta') {
            $delta = (array)($event['delta'] ?? []);
            foreach (['text', 'partial_json', 'thinking'] as $k) {
                if (isset($delta[$k]) && is_string($delta[$k])) {
                    $state = self::addText($state, $delta[$k]);
                }
            }
        } elseif ($type === 'error') {
            $state['error'] = (string)($event['error']['type'] ?? 'error');
        }
        return $state;
    }

    /**
     * Champs de l'usage qui changent le PRIX : écriture de cache d'une heure
     * (`cache_creation.ephemeral_1h_input_tokens`, cumulée comme le reste de
     * l'usage), vitesse et zone d'inférence. Pure.
     */
    private static function readPriceFields(array $state, array $usage): array
    {
        $creation = $usage['cache_creation'] ?? null;
        if (is_array($creation) && is_numeric($creation['ephemeral_1h_input_tokens'] ?? null)) {
            $state['write1h'] = max((int)$state['write1h'], max(0, (int)$creation['ephemeral_1h_input_tokens']));
        }
        if (is_string($usage['speed'] ?? null) && $usage['speed'] !== '') {
            $state['speed'] = $usage['speed'];
        }
        if (is_string($usage['inference_geo'] ?? null) && $usage['inference_geo'] !== '') {
            $state['geo'] = $usage['inference_geo'];
        }
        return $state;
    }

    /**
     * Multiplicateur de prix d'une requête d'après son usage (cf.
     * FAST_MODE_FACTOR, US_INFERENCE_FACTOR). Pure.
     */
    public static function priceFactor(string $speed, string $geo): float
    {
        $factor = 1.0;
        if (strtolower($speed) === 'fast') {
            $factor *= self::FAST_MODE_FACTOR;
        }
        if (strtolower($geo) === 'us') {
            $factor *= self::US_INFERENCE_FACTOR;
        }
        return round($factor, 4);
    }

    private static function addText(array $state, string $text): array
    {
        if (mb_strlen($state['text']) < self::TEXT_CAP) {
            $state['text'] .= $text;
        }
        return $state;
    }

    /** Relevé d'une réponse non streamée (JSON entier). Pure. */
    public static function stateFromJson(string $body): array
    {
        $state = self::usageState();
        $decoded = json_decode($body, true);
        if (is_array($decoded) && ($decoded['type'] ?? '') === 'message') {
            $state = self::applyEvent($state, $decoded);
        }
        return $state;
    }

    /**
     * Jetons à facturer, au contrat de UsageMeter::llm. Le modèle qui a
     * réellement répondu est tarifé (`model`) ; un relais côté serveur
     * (`usage.iterations`) suit la même lecture que le chat. Une réponse
     * coupée (personne partie, réseau) est complétée par estimation, jamais
     * en baissant un relevé. Pure.
     *
     * @return array<string, mixed>
     */
    public static function tokensFor(array $state, string $requestedModel, bool $aborted, int $inputEstimate): array
    {
        $factor = self::priceFactor((string)($state['speed'] ?? ''), (string)($state['geo'] ?? ''));
        $relay = AnthropicAdapter::relayUsage($state['iterations'], $requestedModel);
        if ($relay !== null) {
            $tokens = $relay['tokens'];
            $tokens['web_searches'] = (int)$state['usage']['searches'];
            $tokens['input_cache_write_1h'] = min((int)($tokens['input_cache_write'] ?? 0), (int)($state['write1h'] ?? 0));
            if ($factor !== 1.0) {
                $tokens['price_factor'] = $factor;
            }
            if ($relay['model'] !== $requestedModel) {
                $tokens['model'] = $relay['model'];
            }
            if ($relay['additional'] !== []) {
                $tokens['additional'] = $relay['additional'];
            }
            return $tokens;
        }
        [$in, $cached, $write, $out, $searches] = AnthropicAdapter::streamUsageTotals($state['usage']);
        $tokens = [
            'input'             => $in,
            'input_cached'      => $cached,
            'input_cache_write' => $write,
            'input_cache_write_1h' => min($write, (int)($state['write1h'] ?? 0)),
            'output'            => $out,
            'web_searches'      => $searches,
        ];
        if ($factor !== 1.0) {
            $tokens['price_factor'] = $factor;
        }
        $served = self::baseModel((string)$state['model']);
        if ($served !== '' && $served !== $requestedModel && LlmCatalog::model('anthropic', $served) !== null) {
            $tokens['model'] = $served;
        }
        if ($aborted) {
            $tokens = LlmAdapterHelpers::fillAbortedUsage($tokens, $inputEstimate, (string)$state['text']);
        }
        return $tokens;
    }

    /**
     * Identifiant du catalogue d'un modèle renvoyé par l'API : Anthropic peut
     * renvoyer l'identifiant daté (`claude-x-20260101`) d'un alias. Pure.
     */
    public static function baseModel(string $model): string
    {
        $model = trim($model);
        if ($model === '' || LlmCatalog::model('anthropic', $model) !== null) {
            return $model;
        }
        $base = (string)preg_replace('/-\d{8}$/', '', $model);
        return LlmCatalog::model('anthropic', $base) !== null ? $base : $model;
    }

    /**
     * Estimation de l'entrée d'une requête, pour une réponse coupée AVANT que
     * l'API n'ait dit son usage : le texte seulement (≈ 4 caractères par
     * jeton) — une image ou un fichier en base64 (`data`) compterait des
     * centaines de milliers de jetons qui n'en sont pas. Pure.
     */
    public static function inputEstimate(mixed $body): int
    {
        $chars = 0;
        $walk = static function (mixed $node) use (&$walk, &$chars): void {
            if (is_string($node)) {
                $chars += mb_strlen($node);
                return;
            }
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === 'data' || $k === 'source') {
                    continue;
                }
                $walk($v);
            }
        };
        $walk($body);
        return (int)ceil($chars / 4);
    }
}
