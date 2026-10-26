<?php

declare(strict_types=1);

/**
 * Utilitaires partagés par tous les adapters LLM pour :
 *   - encoder proprement le payload JSON même si le systemPrompt contient
 *     de l'UTF-8 invalide (texte extrait de PDF bancal, etc.),
 *   - extraire le code HTTP de la réponse (via $http_response_header),
 *   - produire un message d'erreur utilisateur lisible pour les cas où
 *     l'API ferme le flux sans aucune donnée.
 */
class LlmAdapterHelpers
{
    /**
     * Encode un payload en JSON UTF-8 en substituant les octets invalides
     * par U+FFFD au lieu de faire échouer silencieusement json_encode().
     * Retourne false uniquement sur une erreur structurelle (ressource, etc.).
     * `$flags` s'ajoute aux options de base (ex. JSON_PRESERVE_ZERO_FRACTION,
     * pour renvoyer au fournisseur un nombre tel qu'il l'a écrit).
     *
     * @param mixed $payload
     * @return string|false
     */
    public static function encodeBody($payload, int $flags = 0)
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | $flags);
    }

    /**
     * Séparation à placer entre le texte déjà parti (ses deux derniers
     * caractères) et celui qui arrive pour qu'ils forment deux paragraphes —
     * jamais plus de sauts qu'il n'en faut, en comptant ceux qu'ils portent
     * déjà. Pure.
     */
    public static function paragraphBreak(string $tail, string $next): string
    {
        if ($tail === '') {
            return '';
        }
        $have = str_ends_with($tail, "\n\n") ? 2 : (str_ends_with($tail, "\n") ? 1 : 0);
        $have += str_starts_with($next, "\n\n") ? 2 : (str_starts_with($next, "\n") ? 1 : 0);
        return str_repeat("\n", max(0, 2 - $have));
    }

    /**
     * Extrait le code HTTP du tableau $http_response_header (variable
     * magique renseignée par le stream wrapper PHP après un fopen() sortant).
     *
     * @param array<int, string>|null $headers
     */
    public static function extractHttpStatus(?array $headers): ?int
    {
        if (!$headers) {
            return null;
        }
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }

    /**
     * Message d'erreur standard renvoyé quand un flux SSE se termine sans
     * aucun contenu ET sans erreur exploitable. Aide l'utilisateur à
     * diagnostiquer (clé API, modèle, quota).
     */
    public static function buildEmptyStreamMessage(string $provider, string $model, ?int $status): string
    {
        $statusLabel = $status !== null ? (string)$status : 'inconnu';
        return sprintf(
            '[Le serveur %s n\'a renvoyé aucune donnée (statut HTTP %s). Vérifiez votre clé API, le modèle configuré (« %s ») et les quotas côté %s.]',
            $provider,
            $statusLabel,
            $model,
            $provider
        );
    }

    /**
     * Message standard renvoyé quand l'API répond avec un statut HTTP d'erreur
     * avant même que le streaming commence.
     */
    public static function buildHttpErrorMessage(string $provider, int $status, string $apiMessage): string
    {
        return sprintf('[Erreur %s (%d) : %s]', $provider, $status, $apiMessage);
    }

    /** Longueur maximale du message fournisseur repris dans un détail d'erreur. */
    private const ERROR_MESSAGE_MAX = 600;

    /**
     * Détail d'une erreur fournisseur, pour le chunk d'erreur et les journaux :
     * « <codes machine> — <message> ».
     *
     * Les adaptateurs ne gardaient que `error.message`. Or c'est le CODE qui dit
     * la cause sans ambiguïté — `insufficient_quota` (compte sans crédit),
     * `context_length_exceeded`, `overloaded_error`, `RESOURCE_EXHAUSTED`,
     * `model_not_found` — et LlmErrorPresenter::classify le lit en premier : un
     * message seul ne distingue pas « plus de crédit » d'une limite de débit,
     * tous deux servis en 429.
     *
     * Accepte les formes de tous les fournisseurs :
     *   - OpenAI et compatibles : {error: {message, type, code}}
     *   - Anthropic             : {type: "error", error: {type, message}}
     *   - Google                : {error: {code, message, status, details[]}}
     *                             (raisons, identifiants de quota, délai)
     *   - Mistral / Responses   : {message, type, code} à plat
     *   - FastAPI (Perplexity)  : {detail}
     *   - Ollama                : {error: "texte"}
     * À défaut de JSON, le corps brut — borné et débarrassé de son balisage :
     * une page d'erreur HTML de proxy n'a rien à faire dans un journal.
     *
     * Le résultat ne part JAMAIS à l'écran : le présentateur n'en tire qu'un
     * genre d'erreur.
     */
    public static function errorDetail(mixed $decoded, string $rawBody = '', ?int $status = null): string
    {
        $codes   = [];
        $message = null;
        $retry   = null;
        $push    = static function (mixed $value) use (&$codes): void {
            if (is_int($value)) {
                // Un entier sous 1000 est le statut HTTP recopié (Google,
                // Perplexity) ; au-delà, un code métier (Z.ai 1113).
                $value = $value >= 1000 ? (string)$value : '';
            }
            if (!is_string($value)) {
                return;
            }
            $value = trim($value);
            if ($value !== '' && strlen($value) <= 80 && !in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        };

        if (is_array($decoded)) {
            // streamGenerateContent hors SSE : un tableau d'évènements.
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $decoded = $decoded[0];
            }
            $err = $decoded['error'] ?? null;
            if (is_array($err)) {
                $push($err['code'] ?? null);
                $push($err['type'] ?? null);
                $push($err['status'] ?? null);
                $details = $err['details'] ?? [];
                // Anthropic : un OBJET (`details.error_code`), pas une liste —
                // c'est lui qui sépare un plafond de dépense atteint
                // (`enforced_spend_limit_reached`) d'une limite de débit, sous
                // le même type `rate_limit_error` et le même statut 429.
                if (is_array($details) && !array_is_list($details)) {
                    $push($details['error_code'] ?? null);
                    $push($details['reason'] ?? null);
                    $details = [];
                }
                foreach ((array)$details as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    $push($detail['reason'] ?? null);
                    foreach ((array)($detail['violations'] ?? []) as $violation) {
                        if (is_array($violation)) {
                            $push($violation['quotaId'] ?? null);
                        }
                    }
                    if (isset($detail['retryDelay']) && is_string($detail['retryDelay'])) {
                        $retry = trim($detail['retryDelay']);
                    }
                }
                $message = $err['message'] ?? null;
            } elseif (is_string($err)) {
                $message = $err;
            }
            $push($decoded['code'] ?? null);
            if (($decoded['type'] ?? null) !== 'error') {
                $push($decoded['type'] ?? null);
            }
            $message ??= $decoded['message'] ?? $decoded['detail'] ?? null;
        }

        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $message = is_scalar($message) ? (string)$message : '';
        if (trim($message) === '') {
            // Corps non JSON : souvent la page d'erreur d'un proxy. Le balisage
            // n'est retiré que là — un message d'API peut contenir « < » et
            // « > » (« 215234 tokens > 200000 maximum ») sans être du HTML.
            $message = preg_match('/<\s*(?:!doctype|html|head|body|title|h1|center)\b/i', $rawBody) === 1
                ? strip_tags($rawBody)
                : $rawBody;
        }
        // Octets invalides : preg_replace rend null, on garde alors le texte tel quel.
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if (mb_strlen($message) > self::ERROR_MESSAGE_MAX) {
            $message = mb_substr($message, 0, self::ERROR_MESSAGE_MAX) . '…';
        }
        if ($message === '') {
            $message = $status !== null ? 'HTTP ' . $status : 'inconnue';
        }
        // Délai annoncé hors du message (Google : RetryInfo) — le présentateur
        // le relit sous cette forme.
        if ($retry !== null && $retry !== '' && stripos($message, 'retry in') === false) {
            $message .= ' (retry in ' . $retry . ')';
        }

        return ($codes !== [] ? implode(' ', $codes) . ' — ' : '') . $message;
    }

    /**
     * Raison d'arrêt d'un fournisseur (`finish_reason`, `stop_reason`,
     * `finishReason`, `incomplete_details.reason`) → genre d'arrêt ANORMAL, ou
     * null quand le modèle a simplement fini (ou appelé un outil). PURE.
     *
     * Une raison inconnue rend null : on n'invente jamais un problème. Sans
     * cette lecture, une réponse coupée par la longueur maximale ou bloquée
     * par un filtre s'arrêtait net, sans un mot — et quand rien n'avait été
     * écrit (raisonnement qui épuise le plafond, demande bloquée d'emblée),
     * l'utilisateur lisait « réponse vide, réessayez ».
     *
     * Genres rendus : ceux de LlmErrorPresenter::STOP_KINDS.
     */
    public static function stopKind(?string $reason): ?string
    {
        return match (strtolower(trim((string)$reason))) {
            // Plafond de sortie atteint.
            'length', 'max_tokens', 'max_output_tokens', 'max_completion_tokens'
                => 'truncated',
            // Fenêtre de contexte pleine en cours de génération.
            'model_length', 'model_context_window_exceeded', 'context_window_exceeded', 'context_length_exceeded'
                => 'context',
            // Filtres de sécurité : OpenAI, Google (demande ET réponse), Z.ai.
            'content_filter', 'safety', 'recitation', 'blocklist', 'prohibited_content', 'spii',
            'image_safety', 'image_prohibited_content', 'sensitive'
                => 'filtered',
            // DeepSeek (« insufficient_system_resource ») et Z.ai coupent une
            // réponse faute de ressources : surcharge, pas faute de l'utilisateur.
            'insufficient_system_resource', 'network_error'
                => 'overloaded',
            // `aborted` : DeepSeek (« if the generation was interrupted »,
            // référence de l'API relue le 2026-10-07).
            'error', 'other', 'language', 'malformed_function_call', 'unexpected_tool_call', 'incomplete', 'aborted'
                => 'generic',
            default => null,
        };
    }

    /**
     * Chunk d'erreur quand le modèle s'arrête SANS avoir rien répondu. Format
     * des adaptateurs (« [Erreur <Fournisseur> : … ] »), pour que TOUS les
     * appelants le reconnaissent — chat, pages web, présentations, appels
     * auxiliaires — sans rien savoir de plus. Le jeton `stop_reason=<genre>`
     * est lu tel quel par LlmErrorPresenter::classify.
     */
    public static function stopChunk(string $provider, string $kind, string $rawReason = ''): string
    {
        return '[Erreur ' . $provider . ' : stop_reason=' . $kind
            . ($rawReason !== '' ? ' (' . $rawReason . ')' : '')
            . ' — aucune réponse produite]';
    }

    /**
     * Clôture commune d'un flux : traite la raison d'arrêt relevée.
     *
     * Arrêt anormal ET rien d'écrit → chunk d'erreur (stopChunk), sauf si une
     * erreur est déjà partie. Arrêt anormal APRÈS du texte → rien n'est émis :
     * un marqueur entre crochets finirait dans une page web ou un JSON que
     * l'appelant attend intact. L'information voyage alors dans la clé `stop`
     * des jetons rendus par l'adaptateur, et c'est l'appelant qui décide (le
     * chat pose une note sous la réponse).
     *
     * @return array{kind:string, raw:string}|null à placer sous `stop` dans les jetons
     */
    public static function concludeStop(string $provider, ?string $rawReason, bool $textSent, bool $errorSent, callable $onChunk): ?array
    {
        $kind = self::stopKind($rawReason);
        if ($kind === null) {
            return null;
        }
        $raw = trim((string)$rawReason);
        error_log('[' . $provider . '] Arrêt anormal du modèle : ' . $raw . ' → ' . $kind
            . ($textSent ? '' : ' (aucune réponse produite)'));
        if (!$textSent && !$errorSent) {
            $onChunk(self::stopChunk($provider, $kind, $raw));
        }
        return ['kind' => $kind, 'raw' => $raw];
    }

    /**
     * Clôture commune d'un flux Chat Completions (OpenAI et compatibles), dans
     * l'ordre où les causes se lisent :
     *   1. un corps non-SSE resté en tampon = réponse d'erreur JSON servie en
     *      200 (ou dont le statut n'a pas été exposé) ;
     *   2. un arrêt anormal du modèle (concludeStop) ;
     *   3. à défaut, un flux vide : rien d'écrit, rien de compté.
     *
     * Le diagnostic « flux vide » n'est JAMAIS émis après du texte : il ne
     * regardait que les jetons, et un fournisseur qui ne rapporte pas son usage
     * (modèle local) — ou un arrêt demandé avant le dernier évènement —
     * terminait une réponse complète par « le serveur n'a renvoyé aucune
     * donnée, vérifiez votre clé API ».
     *
     * @param array{provider:string, tag:string, model:string, status:?int, context?:array<string,mixed>} $who
     * @param array{raw:string, text:bool, error:bool, finish:?string, usage:bool} $state
     * @return array{kind:string, raw:string}|null à placer sous `stop` dans les jetons
     */
    public static function concludeChatStream(array $who, array $state, callable $onChunk): ?array
    {
        $silent = !$state['text'] && !$state['error'];

        if ($state['raw'] !== '' && $silent) {
            $detail = self::errorDetail(json_decode($state['raw'], true), $state['raw'], $who['status']);
            error_log('[' . $who['tag'] . '] Erreur (fin de flux) : ' . $detail . ' | model=' . $who['model']);
            $onChunk('[Erreur ' . $who['provider'] . ' : ' . $detail . ']');
            return null;
        }

        $stop = self::concludeStop($who['provider'], $state['finish'], $state['text'], $state['error'], $onChunk);
        if ($stop !== null) {
            return $stop;
        }

        if ($silent && !$state['usage'] && !self::clientAborted()) {
            self::logEmptyStream($who['tag'], [
                'http_status' => $who['status'] ?? 'inconnu',
                'model'       => $who['model'],
            ] + ($who['context'] ?? []));
            $onChunk(self::buildEmptyStreamMessage($who['provider'], $who['model'], $who['status']));
        }

        return null;
    }

    /**
     * Ligne d'un flux SSE qui ne porte aucune charge utile : vide, commentaire
     * (`: keepalive`), ou champ `event:` / `id:` / `retry:`. Tout le reste, hors
     * `data:`, est tenu pour le corps d'une réponse d'erreur non-SSE — d'où
     * l'importance d'une seule définition : un adaptateur qui ne connaissait
     * pas `id:` prenait un flux parfaitement sain pour une erreur.
     */
    /**
     * Format neutre → messages Chat Completions, pour les fournisseurs qui
     * lisent les images en `image_url` (data URL base64) quand le modèle les
     * lit (DeepSeek V4.1 Flash, GLM 5.3 Flash — cf. LlmCatalog::acceptsImages).
     * Sinon, et pour les documents (PDF), le texte seul. Un message réduit à
     * son texte redevient une chaîne, comme avant. Pure.
     */
    public static function chatContent(array $messages, bool $withImages): array
    {
        return array_map(static function (array $msg) use ($withImages): array {
            if (!is_array($msg['content'])) return $msg;
            $parts = [];
            $text  = '';
            foreach ($msg['content'] as $part) {
                if (!is_array($part)) continue;
                $type = (string)($part['type'] ?? '');
                if ($type === 'text') {
                    $text .= (string)($part['text'] ?? '');
                    continue;
                }
                if ($type === 'image' && $withImages) {
                    $mime = (string)($part['mime'] ?? 'image/jpeg');
                    $b64  = preg_replace('/\s+/', '', (string)($part['data'] ?? '')) ?? '';
                    if ($b64 !== '' && str_starts_with($mime, 'image/')) {
                        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]];
                    }
                }
            }
            if ($parts === []) {
                return ['role' => $msg['role'], 'content' => $text];
            }
            if ($text !== '') {
                $parts[] = ['type' => 'text', 'text' => $text];
            }
            return ['role' => $msg['role'], 'content' => $parts];
        }, $messages);
    }

    public static function isSseNoise(string $line): bool
    {
        $t = trim($line);
        return $t === ''
            || str_starts_with($t, ':')
            || str_starts_with($t, 'event:')
            || str_starts_with($t, 'id:')
            || str_starts_with($t, 'retry:');
    }

    /** `finish_reason` d'un évènement Chat Completions (API OpenAI et compatibles), ou null. */
    public static function chatFinishReason(mixed $event): ?string
    {
        $reason = is_array($event) ? ($event['choices'][0]['finish_reason'] ?? null) : null;
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** Sonde d'arrêt hors HTTP (worker de jobs) — cf. setStopProbe(). */
    private static $stopProbe = null;

    /**
     * Branche une sonde d'arrêt supplémentaire. Indispensable aux générations
     * asynchrones : un worker CLI n'a AUCUNE connexion client, donc
     * `connection_aborted()` y renvoie toujours 0 et le bouton Stop resterait
     * sans effet. Le job y branche la lecture du drapeau `cancel_requested`.
     * Passer null pour la retirer (toujours le faire en fin de job : le worker
     * enchaîne les jobs dans le même process).
     */
    public static function setStopProbe(?callable $probe): void
    {
        self::$stopProbe = $probe;
    }

    /**
     * Usage d'une réponse ARRÊTÉE (bouton Stop) — pure. Le fournisseur facture
     * ce qu'il a lu et écrit jusqu'à l'arrêt, mais l'usage n'arrive qu'à la
     * fin du flux, que l'adaptateur ne lit plus : la réponse coupée laissait
     * 0 jeton, donc rien au grand livre ni au quota. On complète ce qui
     * manque par une estimation — l'entrée si rien n'en a été relevé, la
     * sortie d'après le texte déjà parti —, sans jamais baisser un relevé.
     *
     * @param array<string, mixed> $tokens jetons rendus par l'adaptateur
     * @param int $inputEstimate estimation de l'entrée envoyée (historique, consignes)
     * @return array<string, mixed> mêmes jetons, `estimated` posé si quelque chose a été estimé
     */
    public static function fillAbortedUsage(array $tokens, int $inputEstimate, string $outputText): array
    {
        $in  = max(0, (int)($tokens['input'] ?? 0));
        $out = max(0, (int)($tokens['output'] ?? 0));
        $outEstimate = trim($outputText) !== '' ? TextChunker::estimateTokens($outputText) : 0;
        if ($in === 0 && $inputEstimate > 0) {
            $tokens['input'] = $inputEstimate;
            $tokens['estimated'] = true;
        }
        if ($outEstimate > $out) {
            $tokens['output'] = $outEstimate;
            $tokens['estimated'] = true;
        }
        return $tokens;
    }

    /**
     * Indique si la connexion client a été interrompue (Stop côté utilisateur).
     * Centralise le check pour que tous les adapters utilisent la même logique.
     * Implique d'avoir flush() un chunk peu avant : PHP ne détecte un client
     * abandonné qu'après une tentative d'écriture sortante.
     */
    public static function clientAborted(): bool
    {
        if (self::$stopProbe !== null) {
            try {
                if ((bool)(self::$stopProbe)()) {
                    return true;
                }
            } catch (\Throwable $e) {
                error_log('[LlmAdapterHelpers] sonde d\'arrêt en erreur : ' . $e->getMessage());
            }
        }
        return connection_aborted() === 1;
    }

    /**
     * Signe de vie pendant un silence prolongé du fournisseur — raisonnement
     * long sans aucun token émis (Claude Fable : aucun delta avant la fin du
     * raisonnement en affichage `omitted`). Deux effets, sans lesquels un
     * plafond de sortie relevé devenait dangereux :
     *   - bat le cœur d'un job via la sonde d'arrêt (clientAborted → tick) :
     *     un job muet depuis 120 s est déclaré mort par le reaper ;
     *   - sur une réponse SSE HTTP, pousse un commentaire `: keepalive` — le
     *     watchdog du navigateur compte TOUT octet reçu, et relançait sinon
     *     la génération au bout de 90 s de silence.
     * Jamais d'écriture hors SSE : une réponse JSON serait corrompue.
     * Renvoie true si l'arrêt a été demandé.
     */
    /**
     * Budget d'allers-retours d'une boucle d'outils : celui de l'interface, ou
     * celui que l'appelant relève en cours de route (`max_rounds`, rappel LU À
     * CHAQUE ALLER — un plan long l'élargit), borné à
     * LLMInterface::TOOL_MAX_ROUNDS_EXTENDED. Source unique des deux boucles
     * (Anthropic, OpenAI).
     */
    public static function roundsBudget(array $options): int
    {
        $fn = $options['max_rounds'] ?? null;
        $n = is_callable($fn) ? (int)$fn() : LLMInterface::TOOL_MAX_ROUNDS;
        return max(1, min(LLMInterface::TOOL_MAX_ROUNDS_EXTENDED, $n));
    }

    /**
     * Consigne du dernier aller d'une boucle d'outils quand le fournisseur ne
     * sait pas interdire les appels (`tool_choice: "none"` absent ou refusé).
     */
    public static function finalRoundNote(): string
    {
        return "Dernier aller-retour : réponds maintenant à l'utilisateur avec ce que tu as obtenu, "
            . "sans appeler d'outil. S'il manque quelque chose, dis-le et propose la suite.";
    }

    /**
     * Note au modèle quand la recherche Internet ne peut pas accompagner ses
     * outils dans cette réponse : sans elle, il répondrait de mémoire comme
     * s'il avait vérifié en ligne.
     */
    public static function webSearchWithToolsUnavailableNote(): string
    {
        return "Note : la recherche Internet n'est pas disponible pour cette réponse (ce modèle ne sait pas "
            . "consulter le web en même temps qu'il utilise des outils). Si la question exige une information "
            . "récente, dis clairement que tu n'as pas pu vérifier en ligne.";
    }

    public static function keepAlive(): bool
    {
        if (PHP_SAPI !== 'cli' && self::isSseResponse()) {
            echo ": keepalive\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }
        return self::clientAborted();
    }

    /** La réponse HTTP en cours est-elle un flux SSE (`text/event-stream`) ? */
    private static function isSseResponse(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/event-stream') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log formaté pour errors.log quand un flux est vide.
     * @param array<string, mixed> $context
     */
    public static function logEmptyStream(string $provider, array $context): void
    {
        $parts = [];
        foreach ($context as $k => $v) {
            $parts[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        error_log('[' . $provider . '] Flux vide après fopen succès. ' . implode(' | ', $parts));
    }
}
