<?php

declare(strict_types=1);

class AnthropicAdapter implements LLMInterface
{
    use ToolUseUnsupportedTrait; // supportsToolUse()/streamWithTools() surchargés plus bas

    /** Seules les constantes indépendantes du catalogue restent ici. */
    private const WEB_SEARCH_MAX_USES = 5;

    /**
     * Plafond de sortie (`max_tokens`) quand le catalogue n'en déclare pas
     * (`max_output_tokens`). Le raisonnement COMPTE dans ce plafond : à 4 096,
     * les modèles qui pensent par défaut (Opus 5, Sonnet 5, Fable) coupaient
     * les réponses longues, les pages web et les présentations — ou rendaient
     * une réponse vide quand le raisonnement avait tout consommé.
     *
     * 64 000 = le plus PETIT plafond de l'API parmi les modèles Claude actifs
     * (Haiku 4.5, Sonnet 4.5, Opus 4.5 ; les autres acceptent 128 000 — page
     * « Output limits » de la documentation Anthropic, relue le 2026-09-30),
     * et le point de départ qu'Anthropic conseille aux efforts élevés. C'est un
     * PLAFOND, pas une cible : seuls les jetons produits sont facturés. Tous
     * les modèles actifs étant de génération 4.5 ou plus, une entrée longue
     * n'est pas refusée pour autant : l'API accepte `entrée + max_tokens` au-delà
     * de la fenêtre et s'arrête sur `model_context_window_exceeded`. Un modèle
     * qui accepte MOINS (hors catalogue, génération future) est rejoué à son
     * plafond — cf. outputCeilingFromError().
     */
    public const DEFAULT_MAX_TOKENS = 64000;

    /**
     * En-tête bêta du repli serveur sur refus (`fallbacks: "default"`). La date
     * 2026-07-01 est la seule qui accepte la forme "default" : sous toute autre
     * valeur `server-side-fallback-*`, le paramètre est rejeté en 400.
     */
    public const REFUSAL_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * Notes d'avancement entre deux appels d'outils rendues en texte
     * (`thinking: {type: "adaptive", display: "updates"}`) — sans cet en-tête,
     * `"updates"` est refusé comme valeur inconnue.
     */
    public const THINKING_UPDATES_BETA = 'thinking-display-updates-2026-08-18';

    /** Note d'un travail interrompu, que l'API écrit à la place d'une note d'avancement. */
    public const INTERRUPTED_NOTE = 'This part of the response was interrupted before it finished.';

    /**
     * Lecture du flux par tranches : au-delà de ce silence (s), un signe de vie
     * part (battement du job, commentaire SSE au navigateur) — cf. readSseLine().
     */
    private const SILENCE_TICK_SEC = 10;

    /**
     * Silence CONTINU (s) au-delà duquel le flux est abandonné. Large à dessein :
     * un raisonnement de plusieurs minutes est normal sur Fable, et c'est le
     * silence qui est borné, pas la durée de la réponse.
     */
    public const SILENCE_MAX_SEC = 900;

    /**
     * Barreaux de l'échelle de dégradation de la recherche web, du plus récent
     * au plus sûr. Le dernier (`none`) garantit une réponse même si l'API
     * refuse les deux types d'outil déclarés au catalogue.
     */
    public const WEB_SEARCH_VARIANT_LATEST = 'latest';
    public const WEB_SEARCH_VARIANT_LEGACY = 'legacy';
    public const WEB_SEARCH_VARIANT_NONE   = 'none';

    /**
     * Le paramètre `fallbacks` a été refusé par l'API (bêta non ouverte à
     * l'organisation, forme inconnue…) : on cesse de l'envoyer. Statique à
     * dessein — le worker enchaîne les jobs dans le même process, inutile de
     * payer un 400 à chaque message pour réapprendre la même chose.
     */
    private static bool $fallbacksRejected = false;

    /**
     * Plafonds de sortie appris d'un refus de l'API (modèle → plafond annoncé
     * par le 400). Statique pour la même raison : une fois appris, les
     * requêtes suivantes partent directement au bon plafond.
     *
     * @var array<string, int>
     */
    private static array $learnedOutputCeilings = [];

    /**
     * Réglages « agent » refusés par l'API (bêta non ouverte à l'organisation,
     * valeur inconnue du modèle) : on cesse de les envoyer, pour tout le
     * process — même raison que `$fallbacksRejected`.
     *
     * @var array<string, true> clé : progress | effort
     */
    private static array $agentFeaturesRejected = [];

    /**
     * Conduite de la boucle d'outils en cours (options de streamWithTools),
     * vide hors de la boucle : `stream()` n'en voit rien.
     *
     * @var array<string, mixed>
     */
    private array $agentOptions = [];

    /**
     * Texte de la réponse parti au client pendant l'appel en cours : ses deux
     * derniers caractères (de quoi doser la séparation) et un drapeau posé
     * par chaque bloc d'outil — cf. emitText(). Remis à zéro par stream() et
     * streamWithTools().
     */
    private string $textTail = '';
    private bool $paragraphPending = false;

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('anthropic') ?? '';
        $this->model  = $modelOverride
            ?? ApiKey::getModel('anthropic')
            ?? LlmCatalog::defaultModel('anthropic');
    }

    private function apiUrl(): string
    {
        return LlmCatalog::endpoint('anthropic', 'messages')
            ?? 'https://api.anthropic.com/v1/messages';
    }

    private function apiVersion(): string
    {
        return LlmCatalog::apiVersion('anthropic') ?? '2023-06-01';
    }

    private function webSearchToolType(bool $preferLatest): string
    {
        return LlmCatalog::webSearchTool('anthropic', $preferLatest)
            ?? ($preferLatest ? 'web_search_20260209' : 'web_search_20250305');
    }

    /**
     * Outil serveur de recherche web correspondant à un barreau de l'échelle,
     * ou null pour le barreau « aucune recherche web ».
     *
     * @return array<string, mixed>|null
     */
    private function webSearchToolForVariant(string $variant): ?array
    {
        return match ($variant) {
            self::WEB_SEARCH_VARIANT_LATEST => $this->buildWebSearchTool(true),
            self::WEB_SEARCH_VARIANT_LEGACY => $this->buildWebSearchTool(false),
            default                         => null,
        };
    }

    /** Barreau suivant de l'échelle (jamais au-delà de `none`). */
    private static function nextWebSearchVariant(string $variant): string
    {
        return match ($variant) {
            self::WEB_SEARCH_VARIANT_LATEST => self::WEB_SEARCH_VARIANT_LEGACY,
            default                         => self::WEB_SEARCH_VARIANT_NONE,
        };
    }

    public function getProviderName(): string { return 'anthropic'; }

    /**
     * Charge utile adaptée au modèle RÉELLEMENT appelé — celui demandé, ou son
     * relais quand l'organisation ne remplit pas ses conditions d'accès.
     * Plafond de sortie, température et repli serveur sur refus se lisent au
     * catalogue, modèle par modèle : le relais n'a pas les mêmes réglages.
     */
    private function payloadFor(array $base, string $model, ?float $temperature): array
    {
        $payload = ['model' => $model, 'max_tokens' => self::maxTokensFor($model)] + $base;
        // NULL (assistant « réinitialisé ») → on n'envoie rien : l'API applique
        // sa température par défaut. Les modèles Opus 4.7+ / Claude 5 rejettent
        // le paramètre (400) → filtré via le catalogue, qui borne aussi la
        // valeur à 1 (le curseur de MIA va jusqu'à 2, l'API refuse au-delà).
        $sendTemperature = LlmCatalog::temperatureFor('anthropic', $model, $temperature);
        if ($sendTemperature !== null) {
            $payload['temperature'] = $sendTemperature;
        }
        $fallbacks = self::$fallbacksRejected ? null : LlmCatalog::refusalFallback('anthropic', $model);
        if ($fallbacks !== null) {
            $payload['fallbacks'] = $fallbacks;
        }
        return $payload;
    }

    /**
     * Plafond de sortie d'un modèle : catalogue, sinon défaut de l'adapter —
     * ramené au plafond que l'API a annoncé pour ce modèle, s'il est plus bas.
     */
    public static function maxTokensFor(string $model): int
    {
        $cap = LlmCatalog::maxOutputTokens('anthropic', $model) ?? self::DEFAULT_MAX_TOKENS;
        $learned = self::$learnedOutputCeilings[$model] ?? null;
        return $learned !== null ? min($cap, $learned) : $cap;
    }

    /**
     * Plafond annoncé par un 400 « max_tokens: 64000 > 32000, which is the
     * maximum allowed number of output tokens for … », ou null. PURE.
     *
     * Sans ce rejeu, un modèle qui accepte moins que le défaut échouait à
     * CHAQUE message sur un « Requête refusée », alors que la demande était
     * bonne : c'est notre plafond qui l'était moins.
     */
    public static function outputCeilingFromError(?int $status, string $message): ?int
    {
        if ($status !== 400
            || preg_match('/max_tokens\D{0,10}(\d+)\s*>\s*(\d+)\D{0,40}maximum allowed/i', $message, $m) !== 1) {
            return null;
        }
        $ceiling = (int)$m[2];
        return $ceiling > 0 && $ceiling < (int)$m[1] ? $ceiling : null;
    }

    /**
     * Retient le plafond annoncé par un refus, s'il est plus bas que celui
     * qui vient d'être envoyé (sinon rejouer ne changerait rien : l'erreur
     * reviendrait telle quelle). Rend vrai quand un nouvel essai s'impose.
     */
    private static function learnOutputCeiling(string $model, ?int $status, string $message, int $sent): bool
    {
        $ceiling = self::outputCeilingFromError($status, $message);
        if ($ceiling === null || $ceiling >= $sent) {
            return false;
        }
        self::$learnedOutputCeilings[$model] = $ceiling;
        error_log('[AnthropicAdapter] ' . $model . ' plafonne la sortie à ' . $ceiling
            . ' tokens — demande rejouée à ce plafond, et les suivantes aussi.');
        return true;
    }

    /**
     * En-têtes d'une requête. L'en-tête bêta n'accompagne QUE le paramètre
     * `fallbacks` : l'envoyer seul n'aurait aucun effet, et le paramètre sans
     * lui serait refusé.
     *
     * @return list<string>
     */
    private function headersFor(array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion(),
        ];
        $betas = self::betasFor($payload);
        if ($betas !== []) {
            // UN seul en-tête, valeurs séparées par des virgules : deux en-têtes
            // `anthropic-beta` ne se cumulent pas.
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }
        return $headers;
    }

    /**
     * Bêtas qu'exige une charge utile : chacune accompagne SON paramètre, et
     * seulement lui — l'envoyer seule ne ferait rien, le paramètre sans elle
     * serait refusé. Pure.
     *
     * @return list<string>
     */
    public static function betasFor(array $payload): array
    {
        $betas = [];
        if (isset($payload['fallbacks'])) {
            $betas[] = self::REFUSAL_FALLBACK_BETA;
        }
        if (($payload['thinking']['display'] ?? null) === 'updates') {
            $betas[] = self::THINKING_UPDATES_BETA;
        }
        return $betas;
    }

    /**
     * Réglages « agent » d'un aller de la boucle d'outils, pour le modèle
     * RÉELLEMENT appelé (un relais n'a pas les mêmes capacités) :
     *
     *   - notes d'avancement (`thinking.display: "updates"`) quand l'appelant
     *     les affiche (`on_progress`) et que le modèle les rend ;
     *   - effort explicite (`output_config.effort`) seulement s'il diffère du
     *     défaut du modèle — Opus 5.5 raisonne à `medium` par défaut.
     *
     * Pas de budget de tâche (`output_config.task_budget`), à dessein : son
     * compte à rebours arrive après CHAQUE résultat d'outil, et Anthropic le
     * déconseille en session interactive (le modèle peut le lire comme une
     * tentative d'injection) — réservé aux boucles sans personne devant.
     *
     * Chaque réglage refusé une fois ne part plus (`$agentFeaturesRejected`).
     */
    private function withAgentFeatures(array $payload, string $model): array
    {
        $opt = $this->agentOptions;
        if ($opt === []) {
            return $payload;
        }
        if (is_callable($opt['on_progress'] ?? null)
            && !isset(self::$agentFeaturesRejected['progress'])
            && LlmCatalog::supportsProgressUpdates('anthropic', $model)) {
            $payload['thinking'] = ['type' => 'adaptive', 'display' => 'updates'];
        }
        $effort = $opt['effort'] ?? null;
        $default = LlmCatalog::defaultEffort('anthropic', $model);
        if (is_string($effort) && $default !== null && $effort !== $default
            && in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)
            && !isset(self::$agentFeaturesRejected['effort'])) {
            $payload['output_config']['effort'] = $effort;
        }
        return $payload;
    }

    /**
     * Réglage « agent » visé par un refus de l'API (400), ou null. Pure.
     * Testé AVANT le refus d'un fil rejoué : les deux parlent de `thinking`.
     */
    public static function rejectedAgentFeature(?int $status, string $message, array $payload): ?string
    {
        if ($status !== 400) {
            return null;
        }
        if (isset($payload['output_config']['effort']) && stripos($message, 'effort') !== false) {
            return 'effort';
        }
        if (($payload['thinking']['display'] ?? null) === 'updates'
            && (stripos($message, 'display') !== false || stripos($message, self::THINKING_UPDATES_BETA) !== false)) {
            return 'progress';
        }
        return null;
    }

    /**
     * Note d'avancement d'un bloc de raisonnement (display « updates »), ou
     * null : bloc vide (le raisonnement lui-même reste masqué), ou note de
     * travail interrompu. Bornée à une ou deux phrases. Pure.
     */
    public static function progressNote(string $thinking): ?string
    {
        $note = trim((string)preg_replace('/\s+/u', ' ', $thinking));
        if ($note === '' || $note === self::INTERRUPTED_NOTE) {
            return null;
        }
        return mb_strlen($note) > 400 ? rtrim(mb_substr($note, 0, 399)) . '…' : $note;
    }

    /**
     * Texte de la réponse vers le client, dans son paragraphe : ce que le
     * modèle écrit APRÈS un outil (appel client, recherche web, aller suivant
     * de la boucle) se collait à ce qu'il avait écrit avant — « …le compte
     * rendu.Voici le résumé ». Deux blocs de texte consécutifs (texte découpé
     * par les citations) restent d'un seul tenant : seul un bloc d'outil pose
     * le drapeau. Les chunks d'erreur ne passent JAMAIS par ici : ils doivent
     * arriver entiers pour être reconnus.
     */
    private function emitText(callable $onChunk, string $text): void
    {
        if ($this->paragraphPending && $this->textTail !== '') {
            $text = LlmAdapterHelpers::paragraphBreak($this->textTail, $text) . $text;
        }
        $this->paragraphPending = false;
        $onChunk($text);
        $this->textTail = substr($this->textTail . $text, -2);
    }

    /** Bloc d'outil — appel client ou serveur, ou résultat d'un outil serveur. Pure. */
    public static function isToolBlockType(string $type): bool
    {
        return in_array($type, ['tool_use', 'server_tool_use', 'mcp_tool_use'], true)
            || str_ends_with($type, '_tool_result');
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[AnthropicAdapter] Aucune clé API Anthropic configurée.');
            $onChunk('[Clé API Anthropic manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $basePayload = [
            'stream'   => true,
            'system'   => $systemPrompt,
            'messages' => $this->formatMessages($messages),
        ];
        $this->textTail = '';
        $this->paragraphPending = false;

        // Échelle de dégradation : outil récent → outil de repli → AUCUN outil.
        // Le dernier barreau compte : si les deux types de tool sont refusés
        // (API en retard sur le catalogue), répondre sans recherche web vaut
        // mieux que rendre un bloc d'erreur. Seul le dernier essai a le droit
        // d'émettre l'erreur au client (les précédents sont muets, sinon un
        // repli réussi laisserait quand même une erreur dans la conversation).
        $variants = $webSearch
            ? [self::WEB_SEARCH_VARIANT_LATEST, self::WEB_SEARCH_VARIANT_LEGACY, self::WEB_SEARCH_VARIANT_NONE]
            : [self::WEB_SEARCH_VARIANT_NONE];

        // Modèle réellement appelé : celui demandé, ou son relais quand
        // l'organisation n'y a pas accès. Un seul relais, jamais de ping-pong.
        $model   = $this->model;
        $relay   = null;
        $result  = null;
        $emitted = false;
        $i = 0;
        while ($i < count($variants)) {
            $variant = $variants[$i];
            $emitted = ($i === count($variants) - 1);
            $payload = $this->payloadFor($basePayload, $model, $temperature);
            $tool    = $this->webSearchToolForVariant($variant);
            if ($tool !== null) {
                $payload['tools'] = [$tool];
            }
            $result = $this->streamWithPayload(
                $payload,
                $onChunk,
                $emitted,
                $relay === null ? LlmCatalog::retentionFallback('anthropic', $model) : null
            );
            if ($result['retry'] === 'fallbacks' || $result['retry'] === 'max_tokens') {
                continue; // même barreau, sans `fallbacks` ou au plafond annoncé (appris)
            }
            if ($result['retry'] === 'retention') {
                $relay = ['from' => $this->model, 'to' => (string)$result['retry_model'], 'reason' => 'retention'];
                $model = (string)$result['retry_model'];
                continue; // même barreau, sur le modèle de relais
            }
            if (!$result['retry_with_fallback']) {
                break;
            }
            error_log('[AnthropicAdapter] outil web search refusé (' . $variant . ') : '
                . $result['fatal_error'] . ' — nouvel essai avec ' . $variants[$i + 1] . '.');
            $i++;
        }

        if (!$emitted && $result['fatal_error'] !== null && !$result['has_delta']) {
            error_log('[AnthropicAdapter] Erreur : ' . $result['fatal_error']);
            // Avec le statut quand il est connu : recherche Internet cochée (le
            // défaut), c'est ICI que part toute erreur HTTP d'Anthropic. Sans
            // lui, un 5xx n'était plus reconnu comme une panne — ni présenté
            // comme tel, ni relayé par le LLM de secours.
            $status = $result['http_status'] ?? null;
            $onChunk($status !== null
                ? LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', (int)$status, $result['fatal_error'])
                : '[Erreur Anthropic : ' . $result['fatal_error'] . ']');
        }

        // Relais côté SERVEUR (le modèle demandé a décliné, Opus a repris) : le
        // modèle servi est annoncé par le flux lui-même.
        $finalModel = $result['served_model'] ?? $model;
        $byModel = [];
        self::addUsage($byModel, $finalModel, $result);
        foreach ($result['additional'] ?? [] as $a) {
            self::addUsage($byModel, (string)$a['model'], $a);
        }

        $tokens = $this->finalizeTokens($byModel, (int)($result['web_searches'] ?? 0), $finalModel, $relay);
        // Arrêt anormal APRÈS du texte (plafond de sortie, fenêtre de contexte) :
        // l'appelant décide quoi en dire — le chat pose une note sous la réponse.
        if (($result['stop'] ?? null) !== null) {
            $tokens['stop'] = $result['stop'];
        }
        return $tokens;
    }

    /**
     * Raison d'arrêt d'un `message_delta` (`max_tokens`,
     * `model_context_window_exceeded`, `end_turn`…), ou null si l'évènement
     * n'en porte pas. Le refus a son propre traitement (refusalFromDelta) ;
     * les autres arrêts anormaux sont lus par LlmAdapterHelpers::stopKind.
     */
    public static function stopReasonFromDelta(mixed $delta): ?string
    {
        $reason = is_array($delta) ? ($delta['stop_reason'] ?? null) : null;
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Refus du modèle (`stop_reason: "refusal"`, porté par le `message_delta`
     * final). Les classifieurs de sécurité de Fable 5 / 5.1 (cyber, bio…),
     * d'Opus 5 (cyber), d'Opus 5.5 et de Sonnet 5.5 (cyber, bio, extraction du
     * raisonnement ; Sonnet 5.5 : aussi `frontier_llm`, `general_harms`)
     * déclinent une demande par un HTTP 200 : flux vide si
     * le refus précède toute sortie, interrompu sinon. Avec le repli serveur,
     * il n'arrive plus ici que si aucun modèle de relais n'est prévu pour la
     * catégorie — ou si le relais décline aussi. Renvoie la catégorie
     * (`stop_details.category`, chaîne vide si l'API n'en donne pas) ou null
     * quand l'arrêt n'est pas un refus.
     */
    public static function refusalFromDelta(mixed $delta): ?string
    {
        if (!is_array($delta) || ($delta['stop_reason'] ?? null) !== 'refusal') {
            return null;
        }
        $category = $delta['stop_details']['category'] ?? '';
        return is_string($category) ? $category : '';
    }

    /**
     * Catégories de refus FACTURÉES même quand le refus précède toute sortie
     * (tableau « Billed before any output » de la documentation Anthropic,
     * relue le 2026-09-30) : celles où Anthropic mesure peu de faux positifs.
     * `cyber`, `general_harms` et une catégorie absente ne le sont pas. La
     * liste peut évoluer côté Anthropic : c'est ICI qu'elle se tient à jour.
     */
    public const REFUSAL_BILLED_CATEGORIES = ['bio', 'frontier_llm', 'reasoning_extraction'];

    /**
     * Un refus survenu AVANT toute sortie est-il facturé par Anthropic ? PURE.
     * Non facturé, ses jetons (rapportés dans `usage`) sont remis à zéro :
     * les compter ferait payer une demande qui n'a rien coûté. Facturé, ils
     * restent : le grand livre dit ce que l'organisation paie.
     */
    public static function refusalBilledBeforeOutput(string $category): bool
    {
        return in_array($category, self::REFUSAL_BILLED_CATEGORIES, true);
    }

    /**
     * Chunk à émettre après un refus. Avant tout texte : erreur au format des
     * adaptateurs, que LlmErrorPresenter présente en « Demande déclinée » —
     * sans lui, le flux vide passait pour une panne (« vérifiez votre clé
     * API »), et « Réessayer » reproduisait le même refus. Après un début de
     * réponse : note visible, la partie déjà affichée ne pouvant être retirée.
     */
    public static function refusalChunk(string $category, bool $textAlreadySent): string
    {
        if ($textAlreadySent) {
            return "\n\n*[" . t('Réponse interrompue : le modèle a décliné la suite de cette demande') . ']*';
        }
        return '[Refus Anthropic : demande déclinée par le modèle'
            . ($category !== '' ? ' (catégorie ' . $category . ')' : '') . '.]';
    }

    /**
     * Refus 400 d'une organisation qui ne conserve pas les données (Fable 5 et
     * 5.1 : « your organization or workspace must have data retention
     * enabled »). Décidé AVANT tout traitement : rejouer la même demande sur un
     * autre modèle est sans risque.
     */
    public static function isRetentionError(?int $status, string $message): bool
    {
        return $status === 400 && stripos($message, 'data retention') !== false;
    }

    /**
     * Le repli serveur (paramètre `fallbacks` ou son en-tête bêta) est-il la
     * cause du 400 ? Organisation hors bêta, modèle sans cible de repli… :
     * mieux vaut répondre sans repli que ne pas répondre.
     */
    public static function isFallbacksRejection(?int $status, string $message): bool
    {
        // Un en-tête bêta refusé ne met le repli en cause que s'il ne vise
        // pas les seules notes d'avancement (cf. rejectedAgentFeature).
        return $status === 400
            && (stripos($message, 'fallback') !== false
                || (stripos($message, 'anthropic-beta') !== false
                    && stripos($message, self::THINKING_UPDATES_BETA) === false));
    }

    /**
     * Consommation d'une réponse relayée par le serveur, lue dans
     * `usage.iterations` (dernier `message_delta`) : une entrée par tentative,
     * `message` pour un modèle qui a décliné, `fallback_message` pour celui
     * qui a servi. Le haut de `usage` ne décrit que la tentative servie — et
     * en streaming, `message_start` a déjà annoncé l'entrée du modèle DEMANDÉ
     * quand le refus survient en cours de réponse : sans les itérations,
     * l'entrée de Fable et la sortie d'Opus seraient additionnées sous un seul
     * modèle. Une tentative déclinée en cours de réponse est facturée, au
     * tarif de SON modèle — d'où `additional`. Déclinée AVANT toute sortie
     * (sortie à 0), elle ne l'est que dans les catégories facturées
     * (REFUSAL_BILLED_CATEGORIES) — or ni l'itération ni le bloc `fallback` ne
     * nomment la catégorie : elle n'est donc pas comptée, sous-comptage
     * possible d'un relais sur refus `bio` ou `frontier_llm`.
     *
     * Null sans entrée `fallback_message` : aucun relais, la lecture ordinaire
     * de l'usage reste juste.
     *
     * @return array{model:string, tokens:array{input:int,output:int,input_cached:int,input_cache_write:int}, additional:list<array{model:string,input:int,output:int,input_cached:int,input_cache_write:int}>}|null
     */
    public static function relayUsage(mixed $iterations, string $requestedModel): ?array
    {
        if (!is_array($iterations)) {
            return null;
        }
        $served   = null;
        $declined = [];
        foreach ($iterations as $it) {
            if (!is_array($it)) {
                continue;
            }
            $cached = max(0, (int)($it['cache_read_input_tokens'] ?? 0));
            $write  = max(0, (int)($it['cache_creation_input_tokens'] ?? 0));
            $entry  = [
                'model'             => (string)($it['model'] ?? ''),
                // Même sémantique que le reste de l'adapter : entrée TOTALE
                // (Anthropic compte input_tokens hors cache).
                'input'             => max(0, (int)($it['input_tokens'] ?? 0)) + $cached + $write,
                'output'            => max(0, (int)($it['output_tokens'] ?? 0)),
                'input_cached'      => $cached,
                'input_cache_write' => $write,
            ];
            if (($it['type'] ?? '') === 'fallback_message') {
                $served = $entry; // la dernière fait foi
            } else {
                $declined[] = $entry;
            }
        }
        if ($served === null || $served['model'] === '') {
            return null;
        }

        $additional = [];
        foreach ($declined as $d) {
            if ($d['output'] <= 0) {
                continue; // déclinée avant toute sortie : non facturée
            }
            // `from.model` renvoie la chaîne envoyée ; par prudence, un
            // identifiant inconnu du catalogue est tarifé comme le modèle
            // demandé plutôt que de tomber en « non tarifé ».
            if ($d['model'] === '' || LlmCatalog::model('anthropic', $d['model']) === null) {
                $d['model'] = $requestedModel;
            }
            $additional[] = $d;
        }

        $tokens = $served;
        unset($tokens['model']);
        return ['model' => $served['model'], 'tokens' => $tokens, 'additional' => $additional];
    }

    /** Relevé vide du flux (entrée HORS cache, comme l'API). */
    private const EMPTY_STREAM_USAGE = ['in' => 0, 'cached' => 0, 'write' => 0, 'out' => 0, 'searches' => 0];

    /**
     * Usage d'un évènement du flux — `message_start.message.usage` ou
     * `message_delta.usage` — reporté sur le relevé en cours.
     *
     * Les compteurs du `message_delta` sont CUMULÉS et comprennent l'entrée
     * ajoutée PENDANT la réponse : les résultats d'une recherche Internet
     * (« Web search results … are counted as input tokens »). Lire l'entrée au
     * seul `message_start` la sous-comptait — 2 679 jetons relevés pour 10 682
     * facturés dans l'exemple de la documentation. Le plus grand des deux fait
     * foi : un compteur cumulé ne décroît pas, et un 0 laissé par un
     * intermédiaire n'efface rien.
     *
     * @param array{in:int,cached:int,write:int,out:int,searches:int} $acc
     * @return array{in:int,cached:int,write:int,out:int,searches:int}
     */
    public static function mergeStreamUsage(array $acc, array $usage): array
    {
        $read = static fn(mixed $v): int => is_numeric($v) ? max(0, (int)$v) : 0;
        $acc['in']       = max($acc['in'], $read($usage['input_tokens'] ?? 0));
        $acc['cached']   = max($acc['cached'], $read($usage['cache_read_input_tokens'] ?? 0));
        $acc['write']    = max($acc['write'], $read($usage['cache_creation_input_tokens'] ?? 0));
        $acc['out']      = max($acc['out'], $read($usage['output_tokens'] ?? 0));
        $acc['searches'] = max($acc['searches'], $read($usage['server_tool_use']['web_search_requests'] ?? 0));
        return $acc;
    }

    /**
     * Relevé du flux → [entrée TOTALE, lue en cache, écrite en cache, sortie,
     * recherches]. Anthropic compte `input_tokens` hors cache ; le reste de
     * MIA attend l'entrée totale (hors cache + lu + écrit).
     *
     * @param array{in:int,cached:int,write:int,out:int,searches:int} $acc
     * @return array{0:int,1:int,2:int,3:int,4:int}
     */
    public static function streamUsageTotals(array $acc): array
    {
        return [$acc['in'] + $acc['cached'] + $acc['write'], $acc['cached'], $acc['write'], $acc['out'], $acc['searches']];
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0,
            'output' => 0,
            'input_cached' => 0,
            'input_cache_write' => 0,
            'output_reasoning' => 0,
            'web_searches' => 0,
        ];
    }

    /** Ajoute une consommation au cumul par modèle d'un appel. */
    private static function addUsage(array &$byModel, string $model, array $t): void
    {
        $byModel[$model] ??= ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0];
        foreach (['input', 'output', 'input_cached', 'input_cache_write'] as $k) {
            $byModel[$model][$k] += (int)($t[$k] ?? 0);
        }
    }

    /**
     * Contrat public des jetons, à partir de la consommation PAR MODÈLE d'un
     * appel (boucle d'outils comprise). Le modèle qui a produit la réponse
     * porte les jetons principaux ; s'il n'est pas celui demandé, `model` le
     * nomme — UsageMeter::effectiveLlmKey le tarife, lui. Les autres modèles
     * facturés (tentative déclinée en cours de réponse, tour servi par un
     * autre modèle) partent dans `additional` : une ligne du grand livre
     * chacun, à leur propre tarif. `relay` alimente la trace du message.
     *
     * Recherches exécutées par l'outil serveur (`usage.server_tool_use
     * .web_search_requests`, envoyé dans le dernier `message_delta`) :
     * facturées à la recherche, en plus des tokens.
     */
    private function finalizeTokens(array $byModel, int $searches, string $finalModel, ?array $relay): array
    {
        $main = $byModel[$finalModel] ?? ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0];
        unset($byModel[$finalModel]);

        $tokens = [
            'input'             => (int)$main['input'],
            'output'            => (int)$main['output'],
            'input_cached'      => (int)$main['input_cached'],
            'input_cache_write' => (int)$main['input_cache_write'],
            'output_reasoning'  => 0,
            'web_searches'      => $searches,
        ];
        if ($finalModel !== $this->model) {
            $tokens['model'] = $finalModel;
        }

        $additional = [];
        foreach ($byModel as $model => $t) {
            if ($t['input'] + $t['output'] > 0) {
                $additional[] = ['model' => (string)$model] + $t;
            }
        }
        if ($additional !== []) {
            $tokens['additional'] = $additional;
        }

        // Relais : « conservation » prime (cause première), sinon relais
        // serveur sur refus dès qu'un autre modèle a produit la réponse.
        if ($relay !== null) {
            $relay['to'] = $finalModel;
        } elseif ($finalModel !== $this->model) {
            $relay = ['from' => $this->model, 'to' => $finalModel, 'reason' => 'refusal'];
        }
        if ($relay !== null) {
            $tokens['relay'] = $relay;
        }

        return $tokens;
    }

    /**
     * Prochaine ligne COMPLÈTE du flux SSE, ou null en fin de flux.
     *
     * Pendant un raisonnement long, Claude peut rester muet plusieurs minutes
     * (affichage `omitted` : aucun delta avant la fin du raisonnement). Le flux
     * est lu par tranches de SILENCE_TICK_SEC secondes : à chaque tranche vide,
     * LlmAdapterHelpers::keepAlive() bat le cœur du job — muet 120 s, le
     * reaper le déclarerait mort — et rassure le watchdog du navigateur.
     * `$stopped` : arrêt demandé pendant le silence ; `$stalled` :
     * SILENCE_MAX_SEC de silence continu, flux abandonné. Une ligne coupée par
     * une tranche est recollée (`$pending`) : un JSON à moitié lu serait pris
     * pour une erreur.
     *
     * @param resource $stream
     */
    private static function readSseLine($stream, string &$pending, bool &$stalled, bool &$stopped): ?string
    {
        $silentSince = null;
        while (true) {
            $line = fgets($stream);
            if ($line !== false) {
                if (!str_ends_with($line, "\n") && !feof($stream)) {
                    $pending .= $line; // tranche écoulée en pleine ligne
                    continue;
                }
                $full    = $pending . $line;
                $pending = '';
                return $full;
            }
            if (feof($stream)) {
                if ($pending !== '') {
                    $full    = $pending;
                    $pending = '';
                    return $full;
                }
                return null;
            }

            // Silence : ni ligne ni fin de flux.
            $silentSince ??= microtime(true);
            if (LlmAdapterHelpers::keepAlive()) {
                $stopped = true;
                return null;
            }
            if (microtime(true) - $silentSince >= self::SILENCE_MAX_SEC) {
                $stalled = true;
                return null;
            }
            if (empty(stream_get_meta_data($stream)['timed_out'])) {
                usleep(200000); // lecture vide sans délai écoulé : pas de boucle à vide
            }
        }
    }

    /**
     * Un aller en streaming (chemin sans outils). Renvoie la consommation du
     * modèle qui a servi, et un signal `retry` quand la même demande doit être
     * rejouée autrement — sans rien émettre, puisqu'un nouvel essai suit :
     *   - `fallbacks` : l'API refuse le repli serveur (bêta non ouverte…) ;
     *   - `retention` : l'organisation ne conserve pas les données, le modèle
     *     `$retentionRelay` reprend la demande ;
     *   - `max_tokens` : le modèle accepte une sortie plus courte que celle
     *     demandée — son plafond est appris, la demande repart avec lui.
     *
     * @return array{input:int, output:int, input_cached:int, input_cache_write:int, output_reasoning:int, web_searches:int, has_delta:bool, fatal_error:?string, retry_with_fallback:bool, retry:?string, retry_model:?string, served_model:?string, additional:list<array>}
     */
    private function streamWithPayload(array $payload, callable $onChunk, bool $emitErrors, ?string $retentionRelay = null): array
    {
        $model = (string)($payload['model'] ?? $this->model);
        $fail = static fn(string $fatal, bool $retryWeb = false, ?string $retry = null, ?string $retryModel = null, ?int $status = null): array => [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
            'web_searches' => 0,
            'has_delta' => false,
            'fatal_error' => $fatal, 'retry_with_fallback' => $retryWeb,
            'retry' => $retry, 'retry_model' => $retryModel,
            'served_model' => null, 'additional' => [], 'stop' => null,
            // Statut de la réponse : l'appelant qui émet l'erreur à notre
            // place (échelle de la recherche web) doit pouvoir le redire.
            'http_status' => $status,
        ];

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[AnthropicAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            if ($emitErrors) {
                $onChunk('[Erreur Anthropic : requête invalide (encodage payload)]');
            }
            return $fail('encodage payload');
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $this->headersFor($payload)),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->apiUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[AnthropicAdapter] Impossible d\'ouvrir le stream vers ' . $this->apiUrl());
            if ($emitErrors) {
                $onChunk('[Erreur : impossible de contacter Anthropic]');
            }
            return $fail('impossible de contacter Anthropic');
        }
        stream_set_read_buffer($stream, 0);
        stream_set_timeout($stream, self::SILENCE_TICK_SEC);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            // Détail = type d'erreur + message (`overloaded_error — Overloaded`) :
            // les tests qui suivent cherchent une tournure DANS ce texte, le
            // type en tête ne les gêne pas — et c'est lui que le présentateur
            // lit pour nommer la cause.
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[AnthropicAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $model);
            // Rejeux sans rien émettre : l'essai suivant répondra.
            if (isset($payload['fallbacks']) && self::isFallbacksRejection($httpStatus, $errMsg)) {
                self::$fallbacksRejected = true;
                error_log('[AnthropicAdapter] repli serveur sur refus indisponible — requêtes suivantes sans `fallbacks`.');
                return $fail($errMsg, false, 'fallbacks');
            }
            if ($retentionRelay !== null && self::isRetentionError($httpStatus, $errMsg)) {
                error_log('[AnthropicAdapter] ' . $model . ' exige la conservation des données — relais sur ' . $retentionRelay . '.');
                return $fail($errMsg, false, 'retention', $retentionRelay);
            }
            if (self::learnOutputCeiling($model, $httpStatus, $errMsg, (int)($payload['max_tokens'] ?? 0))) {
                return $fail($errMsg, false, 'max_tokens');
            }
            // Un type de tool web search non reconnu est refusé par Anthropic en
            // HTTP 400 (`invalid_request_error`), PAS par un évènement `error`
            // au fil du flux : cette branche renvoyait `false` en dur, donc le
            // repli vers web_search_20250305 ne se déclenchait JAMAIS et
            // l'option Internet rendait un bloc d'erreur au lieu d'une réponse.
            $retryWithFallback = !$emitErrors && $this->shouldFallbackWebSearchTool($errMsg);
            if ($emitErrors) {
                $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', $httpStatus, $errMsg));
            }
            return $fail($errMsg, $retryWithFallback, null, null, $httpStatus);
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached     = 0; // cache_read_input_tokens
        $tokensInCacheWrite = 0; // cache_creation_input_tokens
        $webSearches = 0;        // usage.server_tool_use.web_search_requests
        $usageAcc = self::EMPTY_STREAM_USAGE; // relevé du flux (cf. mergeStreamUsage)
        $rawBuffer = '';
        $fatalError = null;
        $hasDelta = false;
        $refusal = null;         // catégorie si stop_reason = refusal
        $stopReason = null;      // stop_reason du dernier message_delta
        $servedModel = null;     // modèle de relais annoncé par le flux
        $iterations = null;      // usage.iterations (repli serveur)
        $citationSources = [];
        $pending = '';
        $stalled = false;
        $stopped = false;

        while (($line = self::readSseLine($stream, $pending, $stalled, $stopped)) !== null) {
            if (!str_starts_with($line, 'data: ')) {
                if (LlmAdapterHelpers::isSseNoise($line)) {
                    continue;
                }
                $rawBuffer .= $line;
                continue;
            }

            if ($rawBuffer !== '' && $fatalError === null) {
                $fatalError = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
                // `$hasDelta` : cf. l'évènement `error` plus bas.
                if ($emitErrors || $hasDelta) {
                    error_log('[AnthropicAdapter] Erreur (rawBuffer) : ' . $fatalError);
                    $onChunk('[Erreur Anthropic : ' . $fatalError . ']');
                }
                break;
            }

            $data = trim(substr($line, 6));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            $this->collectCitationSourcesFromNode($json, $citationSources);

            $type = (string)($json['type'] ?? '');

            // Relais serveur : le modèle demandé a décliné, un autre (Opus)
            // poursuit dans le même flux. Le bloc `fallback` marque la bascule ;
            // le texte déjà reçu reste valable, la suite vient du relais.
            if ($type === 'content_block_start' && (($json['content_block']['type'] ?? '') === 'fallback')) {
                $to = (string)($json['content_block']['to']['model'] ?? '');
                if ($to !== '') {
                    $servedModel = $to;
                }
                continue;
            }
            // Recherche web : le texte qui suit ouvre un paragraphe.
            if ($type === 'content_block_start' && self::isToolBlockType((string)($json['content_block']['type'] ?? ''))) {
                $this->paragraphPending = true;
            }

            if ($type === 'content_block_delta') {
                $delta = $json['delta'] ?? null;
                if (is_array($delta)) {
                    $content = (string)($delta['text'] ?? '');
                    if ($content !== '') {
                        $this->emitText($onChunk, $content);
                        $hasDelta = true;
                        if (LlmAdapterHelpers::clientAborted()) {
                            break;
                        }
                    }
                }
            }

            if ($type === 'message_delta') {
                $refusal = self::refusalFromDelta($json['delta'] ?? null) ?? $refusal;
                $stopReason = self::stopReasonFromDelta($json['delta'] ?? null) ?? $stopReason;
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usageAcc = self::mergeStreamUsage($usageAcc, $json['usage']);
                    $iterations = $json['usage']['iterations'] ?? $iterations;
                }
            }

            if ($type === 'message_start' && isset($json['message']['usage']) && is_array($json['message']['usage'])) {
                $usageAcc = self::mergeStreamUsage($usageAcc, $json['message']['usage']);
            }

            if ($type === 'error') {
                // `overloaded_error` au fil du flux est la panne Anthropic la
                // plus courante : sans son type, le message (« Overloaded »)
                // n'était présenté que comme « une erreur est survenue ».
                $fatalError = LlmAdapterHelpers::errorDetail($json);
                // Après du texte, l'erreur part TOUJOURS : aucun barreau de
                // l'échelle web ne rejouera une réponse déjà commencée. Avec
                // la recherche Internet cochée — le réglage par défaut —, le
                // premier essai n'avait pas le droit d'émettre : une panne en
                // pleine réponse la coupait net, sans un mot.
                if ($emitErrors || $hasDelta) {
                    error_log('[AnthropicAdapter] Erreur (SSE) : ' . $fatalError);
                    $onChunk('[Erreur Anthropic : ' . $fatalError . ']');
                }
                break;
            }
        }

        if ($rawBuffer !== '' && !$hasDelta && $fatalError === null) {
            $fatalError = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
            if ($emitErrors) {
                error_log('[AnthropicAdapter] Erreur (fin de stream) : ' . $fatalError);
                $onChunk('[Erreur Anthropic : ' . $fatalError . ']');
            }
        }

        // Silence continu : le fournisseur ne répond plus. « timeout » dans le
        // message → ChatController y voit une panne passagère (LLM de secours).
        if ($stalled) {
            error_log('[AnthropicAdapter] Flux abandonné après ' . self::SILENCE_MAX_SEC . ' s de silence | model=' . $model);
            if ($fatalError === null) {
                $fatalError = 'timeout — aucune donnée reçue depuis ' . self::SILENCE_MAX_SEC . ' s';
                // Réponse déjà commencée : le dire, plutôt que la laisser
                // s'arrêter au milieu d'une phrase.
                if ($emitErrors || $hasDelta) {
                    $onChunk('[Erreur Anthropic : ' . $fatalError . ']');
                }
            }
        }

        // Anthropic compte input_tokens hors cache : on les agrège pour garder
        // la sémantique « entrée totale » (hors cache + lu + écrit).
        [$tokensIn, $tokensInCached, $tokensInCacheWrite, $tokensOut, $webSearches] = self::streamUsageTotals($usageAcc);

        // Relais serveur : les itérations font foi pour la consommation (cf.
        // relayUsage) et désignent le modèle qui a réellement servi — y compris
        // un tour « collant », servi d'emblée par le relais sans bloc fallback.
        $additional = [];
        $relayUsage = self::relayUsage($iterations, $model);
        if ($relayUsage !== null) {
            $servedModel        = $relayUsage['model'];
            $tokensIn           = $relayUsage['tokens']['input'];
            $tokensOut          = $relayUsage['tokens']['output'];
            $tokensInCached     = $relayUsage['tokens']['input_cached'];
            $tokensInCacheWrite = $relayUsage['tokens']['input_cache_write'];
            $additional         = $relayUsage['additional'];
            error_log('[AnthropicAdapter] Réponse relayée par ' . $servedModel . ' (demandé : ' . $model . ').');
        }
        if ($servedModel === $model) {
            $servedModel = null;
        }

        // Refus AVANT toute sortie : Anthropic rapporte l'entrée dans `usage`,
        // mais ne la facture que dans certaines catégories. Hors de celles-ci,
        // la compter ferait payer une demande qui n'a rien coûté.
        if ($refusal !== null && $tokensOut === 0 && !self::refusalBilledBeforeOutput($refusal)) {
            $tokensIn = $tokensInCached = $tokensInCacheWrite = 0;
        }

        if ($refusal !== null) {
            // Émis même hors du dernier barreau de l'échelle web : un refus
            // ne relance jamais l'essai suivant (retry_with_fallback reste
            // faux), ce résultat est donc le dernier. Pas de liste de sources
            // sous une réponse déclinée.
            error_log('[AnthropicAdapter] Demande déclinée (stop_reason=refusal'
                . ($refusal !== '' ? ', catégorie ' . $refusal : '') . ') | model=' . ($servedModel ?? $model));
            $onChunk(self::refusalChunk($refusal, $hasDelta));
        } elseif ($hasDelta) {
            $this->emitCitationLinks($onChunk, $citationSources);
        }

        fclose($stream);

        $retryWithFallback = !$emitErrors
            && !$hasDelta
            && $fatalError !== null
            && $this->shouldFallbackWebSearchTool($fatalError);

        // Arrêt anormal hors refus : plafond de sortie atteint (`max_tokens` —
        // le raisonnement compte dans ce plafond et peut l'épuiser avant le
        // premier mot), fenêtre de contexte pleine. Émis même hors du dernier
        // barreau de l'échelle web, pour la même raison que le refus : sans
        // erreur fatale, rien ne relance l'essai suivant — ce résultat est le
        // dernier.
        $stop = null;
        if ($refusal === null && $fatalError === null && !$stalled && !$stopped) {
            $stop = LlmAdapterHelpers::concludeStop('Anthropic', $stopReason, $hasDelta, false, $onChunk);
        }

        // Un refus avant toute sortie n'est pas facturé (0 token) : sans la
        // garde `$refusal`, il déclencherait aussi le diagnostic « flux vide ».
        // Idem pour un arrêt demandé pendant un silence (raisonnement long).
        if ($tokensIn === 0 && $tokensOut === 0 && !$hasDelta && $fatalError === null && !$retryWithFallback && $refusal === null && !$stopped && $stop === null) {
            LlmAdapterHelpers::logEmptyStream('AnthropicAdapter', [
                'http_status' => $httpStatus ?? 'inconnu',
                'model'       => $model,
                'systemPromptLen' => strlen((string)($payload['system'] ?? '')),
                'messagesCount'   => count($payload['messages'] ?? []),
            ]);
            if ($emitErrors) {
                $onChunk(LlmAdapterHelpers::buildEmptyStreamMessage('Anthropic', $model, $httpStatus));
            }
        }

        return [
            'input' => $tokensIn,
            'output' => $tokensOut,
            'input_cached' => $tokensInCached,
            'input_cache_write' => $tokensInCacheWrite,
            'output_reasoning' => 0,
            'web_searches' => $webSearches,
            'has_delta' => $hasDelta,
            'fatal_error' => $fatalError,
            'retry_with_fallback' => $retryWithFallback,
            'retry' => null,
            'retry_model' => null,
            'served_model' => $servedModel,
            'additional' => $additional,
            'stop' => $stop,
        ];
    }

    private function buildWebSearchTool(bool $preferLatest): array
    {
        $type = $this->webSearchToolType($preferLatest);
        if ($preferLatest) {
            return [
                'type' => $type,
                'name' => 'web_search',
                'max_uses' => self::WEB_SEARCH_MAX_USES,
                // Empêche la dépendance implicite au code execution interne.
                'allowed_callers' => ['direct'],
            ];
        }
        return [
            'type' => $type,
            'name' => 'web_search',
            'max_uses' => self::WEB_SEARCH_MAX_USES,
        ];
    }

    private function shouldFallbackWebSearchTool(string $errorMessage): bool
    {
        $msg = strtolower(trim($errorMessage));
        if ($msg === '') {
            return false;
        }

        return str_contains($msg, $this->webSearchToolType(true))
            || str_contains($msg, 'unknown tool')
            || str_contains($msg, 'invalid tool')
            || str_contains($msg, 'unsupported tool')
            || str_contains($msg, 'allowed_callers')
            || str_contains($msg, 'code execution');
    }

    /**
     * @param mixed $node
     * @param array<int, array{url:string,title:string}> $sources
     */
    private function collectCitationSourcesFromNode($node, array &$sources): void
    {
        if (!is_array($node)) {
            return;
        }

        $type = is_string($node['type'] ?? null) ? (string)$node['type'] : '';
        if (in_array($type, ['web_search_result_location', 'web_search_result'], true) && isset($node['url']) && is_string($node['url'])) {
            $this->addCitationSource($sources, $node['url'], (string)($node['title'] ?? ''));
        }

        if (isset($node['citations']) && is_array($node['citations'])) {
            foreach ($node['citations'] as $citation) {
                if (is_array($citation) && isset($citation['url']) && is_string($citation['url'])) {
                    $this->addCitationSource($sources, $citation['url'], (string)($citation['title'] ?? ''));
                }
            }
        }

        if (isset($node['citation']) && is_array($node['citation']) && isset($node['citation']['url']) && is_string($node['citation']['url'])) {
            $this->addCitationSource($sources, $node['citation']['url'], (string)($node['citation']['title'] ?? ''));
        }

        foreach ($node as $child) {
            $this->collectCitationSourcesFromNode($child, $sources);
        }
    }

    /**
     * @param array<int, array{url:string,title:string}> $sources
     */
    private function addCitationSource(array &$sources, string $url, string $title = ''): void
    {
        $normalized = trim($url);
        if ($normalized === '' || !preg_match('/^https?:\/\//i', $normalized)) {
            return;
        }

        foreach ($sources as $idx => $source) {
            if ($source['url'] === $normalized) {
                if ($source['title'] === '' && $title !== '') {
                    $sources[$idx]['title'] = $title;
                }
                return;
            }
        }

        $sources[] = ['url' => $normalized, 'title' => trim($title)];
    }

    /**
     * @param array<int, array{url:string,title:string}> $sources
     */
    private function emitCitationLinks(callable $onChunk, array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $lines = [];
        $max = min(count($sources), 20);
        for ($i = 0; $i < $max; $i++) {
            $url = $sources[$i]['url'];
            $title = $sources[$i]['title'] !== '' ? $sources[$i]['title'] : $url;
            $title = str_replace(['[', ']'], ['(', ')'], $title);
            $lines[] = ($i + 1) . '. [' . $title . '](' . $url . ')';
        }

        $onChunk("\n\nSources:\n" . implode("\n", $lines) . "\n");
    }

    /**
     * Convertit le format neutre → format Anthropic Messages API.
     * image  → {"type":"image","source":{"type":"base64","media_type":...,"data":...}}
     * document → {"type":"document","source":{"type":"base64","media_type":"application/pdf","data":...}}
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            $role = (string)($msg['role'] ?? 'user');
            if (!is_array($msg['content'])) {
                return ['role' => $role, 'content' => (string)($msg['content'] ?? '')];
            }

            $parts = [];
            foreach ($msg['content'] as $part) {
                if (!is_array($part)) {
                    if (is_string($part) && $part !== '') {
                        $parts[] = ['type' => 'text', 'text' => $part];
                    }
                    continue;
                }

                if (($part['type'] ?? '') === 'text') {
                    $parts[] = ['type' => 'text', 'text' => (string)($part['text'] ?? '')];
                } elseif (($part['type'] ?? '') === 'image') {
                    $path = trim((string)($part['path'] ?? ''));
                    if ($path !== '' && preg_match('/^https?:\/\//i', $path) === 1) {
                        $parts[] = ['type' => 'image', 'source' => [
                            'type' => 'url',
                            'url'  => $path,
                        ]];
                        continue;
                    }
                    $imageData = $this->extractBase64Payload((string)($part['data'] ?? ''));
                    if ($imageData === '') {
                        continue;
                    }
                    $parts[] = ['type' => 'image', 'source' => [
                        'type'       => 'base64',
                        'media_type' => (string)($part['mime'] ?? 'image/jpeg'),
                        'data'       => $imageData,
                    ]];
                } elseif (($part['type'] ?? '') === 'document') {
                    $path = trim((string)($part['path'] ?? ''));
                    if ($path !== '' && preg_match('/^https?:\/\//i', $path) === 1) {
                        $parts[] = ['type' => 'document', 'source' => [
                            'type' => 'url',
                            'url'  => $path,
                        ]];
                        continue;
                    }
                    $pdfData = $this->extractBase64Payload((string)($part['data'] ?? ''));
                    if ($pdfData === '') {
                        continue;
                    }
                    $parts[] = ['type' => 'document', 'source' => [
                        'type'       => 'base64',
                        'media_type' => 'application/pdf',
                        'data'       => $pdfData,
                    ]];
                }
            }
            if ($parts === []) {
                return ['role' => $role, 'content' => ''];
            }
            return ['role' => $role, 'content' => $parts];
        }, $messages);
    }

    private function extractBase64Payload(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }
        $pos = stripos($raw, ';base64,');
        if ($pos !== false) {
            return substr($raw, $pos + 8);
        }
        return $raw;
    }

    // ============================================================
    // Function-calling (tool-use)
    // ============================================================

    // TOOL_MAX_ROUNDS est hérité de LLMInterface (source unique — la
    // guidance agent du ChatController annonce le même budget au modèle).

    /** Reprises d'un tour mis en pause par le serveur (`pause_turn`) dans une même réponse. */
    public const MAX_PAUSE_CONTINUATIONS = 3;

    public function supportsToolUse(): bool
    {
        return $this->apiKey !== '' && LlmCatalog::supportsToolUse('anthropic', $this->model);
    }

    public function streamWithTools(
        array $messages,
        string $systemPrompt,
        array $tools,
        callable $onChunk,
        callable $onToolCall,
        ?float $temperature = null,
        bool $webSearch = false,
        array $options = []
    ): array {
        if ($this->apiKey === '') {
            $onChunk('[Clé API Anthropic manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }
        // Conduite de la boucle visible des seuls allers de CETTE boucle
        // (withAgentFeatures, notes d'avancement) — remise à zéro même si un
        // rappel lève (bascule sur le LLM de secours).
        $this->agentOptions = $options;
        $this->textTail = '';
        $this->paragraphPending = false;
        try {
            return $this->toolLoop($messages, $systemPrompt, $tools, $onChunk, $onToolCall, $temperature, $webSearch, $options);
        } finally {
            $this->agentOptions = [];
        }
    }

    /** Corps de la boucle d'outils (cf. streamWithTools). */
    private function toolLoop(
        array $messages,
        string $systemPrompt,
        array $tools,
        callable $onChunk,
        callable $onToolCall,
        ?float $temperature,
        bool $webSearch,
        array $options
    ): array {
        // Cache de prompt entre les allers-retours (cf. withCacheBreakpoints).
        $cacheMode = self::cacheMode($options);
        $antTools = $this->buildAnthropicTools($tools);
        $toolsSig = self::toolNamesSignature($tools);
        $base = $this->formatMessages($messages);
        // Tours de CETTE réponse (blocs assistant capturés dans l'ordre +
        // résultats d'outils) : le fil de la requête est reconstruit à chaque
        // tour depuis eux, sous l'une des deux formes (cf. roundsToMessages).
        $rounds = [];
        // Forme FIDÈLE (raisonnement, blocs serveur, texte à sa place) tant que
        // l'API l'accepte ; un refus d'un fil rejoué fait retomber, pour toute
        // la fin de la boucle, sur la forme d'avant : texte + tool_use.
        $replayFull = true;
        $pauses = 0;

        // Outil SERVEUR de recherche web ajouté aux outils applicatifs : chez
        // Anthropic les deux cohabitent dans le même tableau `tools`. Sans lui,
        // activer le moindre outil (image, Drive, Gmail, Agenda) faisait passer
        // tout le message par cette boucle et l'option Internet devenait un
        // no-op silencieux.
        $webVariant = $webSearch ? self::WEB_SEARCH_VARIANT_LATEST : self::WEB_SEARCH_VARIANT_NONE;

        // Modèle appelé à chaque tour : celui demandé, ou son relais dès que
        // l'organisation s'est vu refuser l'accès (conservation des données).
        $model = $this->model;
        $relay = null;
        // Consommation PAR MODÈLE : un tour peut être servi par le relais
        // (refus décliné côté serveur), un autre par le modèle demandé.
        $byModel = [];
        $finalModel = $model;
        $totSearches = 0;
        $citationSources = [];
        $textSent = false; // du texte est-il déjà parti vers le client (tous rounds confondus) ?
        $stop = null;      // arrêt anormal du dernier tour (plafond de sortie, contexte)

        $forcedSynthesis = false; // le dernier aller (synthèse forcée) a-t-il eu lieu ?
        for ($round = 0; $round < LlmAdapterHelpers::roundsBudget($options); $round++) {
            // Dernier round : synthèse forcée (`tool_choice: none`). Sans ça,
            // des outils appelés au dernier tour étaient exécutés sans que le
            // modèle voie jamais leurs résultats — réponse amputée en plein
            // vol, sans erreur. Le budget est un plafond, pas une cible : ce
            // round n'est atteint que si le modèle enchaîne vraiment.
            $dernierRound = $round === LlmAdapterHelpers::roundsBudget($options) - 1;
            $abandon = false;

            // Outils relus à chaque aller (`tools_source`) : un connecteur activé
            // par le modèle y apparaît. Changer la liste invalide le
            // raisonnement déjà produit (l'API le vérifie) : le fil repasse
            // dans la forme d'avant, sans blocs de raisonnement — reprise
            // valide selon la documentation.
            if (is_callable($options['tools_source'] ?? null)) {
                $current = (array)($options['tools_source'])();
                $sig = self::toolNamesSignature($current);
                if ($sig !== $toolsSig) {
                    $antTools = $this->buildAnthropicTools($current);
                    $toolsSig = $sig;
                    if ($rounds !== [] && $replayFull) {
                        $replayFull = false;
                    }
                }
            }

            // Même échelle de dégradation que `stream()` : outil récent → outil
            // de repli → aucun. Un type refusé ne doit pas emporter la réponse
            // ni les outils applicatifs. Mêmes rejeux, aussi : sans repli
            // serveur s'il est refusé, sur le relais si l'organisation l'est.
            do {
                $payload = $this->payloadFor(
                    [
                        'stream'   => true,
                        'system'   => $systemPrompt,
                        'messages' => array_merge($base, self::roundsToMessages($rounds, $replayFull)),
                    ],
                    $model,
                    $temperature
                );
                $webTool   = $this->webSearchToolForVariant($webVariant);
                $roundTools = $webTool !== null ? array_merge([$webTool], $antTools) : $antTools;
                // Dernier barreau sans outil applicatif : `tools: []` serait
                // refusé, on n'envoie pas la clé plutôt qu'un tableau vide
                // (et `tool_choice` sans `tools` serait refusé aussi).
                if ($roundTools !== []) {
                    $payload['tools'] = $roundTools;
                    if ($dernierRound) {
                        $payload['tool_choice'] = ['type' => 'none'];
                    }
                }
                $payload = $this->withAgentFeatures($payload, $model);
                $payload = self::withCacheBreakpoints($payload, $cacheMode, count($rounds), $dernierRound);
                if ($dernierRound && $roundTools !== []) {
                    $forcedSynthesis = true;
                }
                $res = $this->streamToolRound(
                    $payload,
                    $onChunk,
                    $webTool !== null,
                    $relay === null ? LlmCatalog::retentionFallback('anthropic', $model) : null,
                    $replayFull && self::roundsCarrySpecialBlocks($rounds)
                );
                if ($res['retry'] === 'replay') {
                    // L'API refuse le fil rejoué (signature d'un raisonnement,
                    // bloc serveur mal reconstruit…) : on retombe sur la forme
                    // d'avant. Un tour en pause ne peut pas se poursuivre sans
                    // ses blocs serveur — sans eux ce serait un préremplissage
                    // (refusé) : on s'arrête là, le texte déjà écrit reste.
                    error_log('[AnthropicAdapter/Tools] fil rejoué refusé — suite de la boucle sans raisonnement ni blocs serveur.');
                    $replayFull = false;
                    if (self::endsPaused($rounds)) {
                        $abandon = true;
                        break;
                    }
                    continue;
                }
                if ($res['retry'] === 'fallbacks' || $res['retry'] === 'max_tokens' || $res['retry'] === 'agent_feature') {
                    continue;
                }
                if ($res['retry'] === 'retention') {
                    $relay = ['from' => $this->model, 'to' => (string)$res['retry_model'], 'reason' => 'retention'];
                    $model = (string)$res['retry_model'];
                    continue;
                }
                if (!$res['retry_web_search']) {
                    break;
                }
                error_log('[AnthropicAdapter/Tools] outil web search refusé (' . $webVariant
                    . ') — nouvel essai avec ' . self::nextWebSearchVariant($webVariant) . '.');
                $webVariant = self::nextWebSearchVariant($webVariant);
            } while (true);

            if ($abandon) {
                break;
            }

            foreach ($res['citations'] as $src) {
                $citationSources[] = $src;
            }
            $servedThisRound = $res['served_model'] ?? $model;
            self::addUsage($byModel, $servedThisRound, $res);
            foreach ($res['additional'] ?? [] as $a) {
                self::addUsage($byModel, (string)$a['model'], $a);
            }
            if ($res['input'] + $res['output'] > 0 || $res['text'] !== '') {
                $finalModel = $servedThisRound;
            }
            $totSearches += (int)($res['web_searches'] ?? 0);
            $textSent = $textSent || $res['text'] !== '';

            // Refus : fin de la boucle, message propre — erreur si rien n'a
            // encore été affiché, note sous le texte déjà parti sinon (un
            // chunk d'erreur arrivé après du texte serait affiché tel quel).
            if (($res['refusal'] ?? null) !== null) {
                $onChunk(self::refusalChunk($res['refusal'], $textSent));
                $citationSources = [];
                break;
            }

            // Pause du tour côté serveur (`pause_turn` : la boucle interne de
            // recherche web a atteint sa limite). On renvoie le tour tel quel,
            // SANS nouveau message utilisateur : l'API reprend où elle en
            // était. Possible seulement avec les blocs serveur rejoués.
            if (!$res['error'] && empty($res['tool_calls'])
                && ($res['stop_reason'] ?? null) === 'pause_turn'
                && $replayFull && $res['blocks'] !== null && $pauses < self::MAX_PAUSE_CONTINUATIONS) {
                $pauses++;
                $rounds[] = ['blocks' => $res['blocks'], 'text' => $res['text'], 'tool_calls' => [], 'results' => null];
                // Hors budget d'allers-retours : la pause est une limite de la
                // boucle SERVEUR, pas un appel d'outil du modèle (bornée par
                // MAX_PAUSE_CONTINUATIONS).
                $round--;
                continue;
            }

            if ($res['error'] || empty($res['tool_calls'])) {
                // Réponse finale : un arrêt anormal se dit — chunk d'erreur si
                // rien n'a été écrit, clé `stop` des jetons sinon. Tour en
                // erreur : le chunk est déjà parti.
                if (!$res['error']) {
                    $stop = LlmAdapterHelpers::concludeStop('Anthropic', $res['stop_reason'] ?? null, $textSent, false, $onChunk);
                }
                break;
            }

            // Un résultat par appel, dans l'ordre (alternance stricte : tous
            // les tool_result du tour dans UN message utilisateur).
            $results = [];
            foreach ($res['tool_calls'] as $tc) {
                // Arrêt demandé (Stop) : plus aucun appel — ni lecture, ni
                // carte d'approbation — sur une réponse coupée. Le contrôle
                // venait APRÈS les outils, et le flux ne le faisait que sur un
                // fragment de texte : un arrêt pendant qu'arrivait l'entrée
                // d'un outil laissait partir tout le tour.
                if (LlmAdapterHelpers::clientAborted()) {
                    break 2;
                }
                $results[] = ['id' => $tc['id'], 'result' => $onToolCall($tc['name'], $tc['input'])];
            }
            $rounds[] = [
                'blocks'     => $res['blocks'],
                'text'       => $res['text'],
                'tool_calls' => $res['tool_calls'],
                'results'    => $results,
            ];

            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }
        }

        // Sources citées : émises une seule fois, à la toute fin (le chemin
        // sans outils fait de même par round).
        $this->emitCitationLinks($onChunk, $citationSources);

        $tokens = $this->finalizeTokens($byModel, $totSearches, $finalModel, $relay);
        if ($stop !== null) {
            $tokens['stop'] = $stop;
        }
        // Budget d'allers-retours atteint : la synthèse a été forcée, la
        // demande peut rester inachevée — l'appelant propose de poursuivre.
        if ($forcedSynthesis) {
            $tokens['rounds_exhausted'] = true;
        }
        return $tokens;
    }

    /** Signature d'une liste d'outils (noms, dans l'ordre). Pure. */
    public static function toolNamesSignature(array $tools): string
    {
        return implode('|', array_map(static fn($t): string => is_array($t) ? (string)($t['name'] ?? '') : '', $tools));
    }

    /**
     * Mode de cache demandé (cf. LLMInterface::streamWithTools) ; toute
     * valeur inconnue vaut le défaut `lazy`. Pure.
     */
    public static function cacheMode(array $options): string
    {
        $mode = $options['cache'] ?? 'lazy';
        return is_string($mode) && in_array($mode, ['eager', 'lazy', 'off'], true) ? $mode : 'lazy';
    }

    /**
     * Points de cache d'un aller de la boucle d'outils. Pure.
     *
     * Le cache d'Anthropic est un préfixe (outils → système → messages) : un
     * aller suivant relit à 0,1× ce qu'un aller précédent a écrit à 1,25×.
     * Sans lui, chaque aller-retour refacturait au plein tarif tout le fil —
     * prompt système, historique, résultats d'outils — alors que seule la fin
     * change. Deux points, sur quatre permis :
     *
     *   - le DERNIER OUTIL : les définitions changent rarement d'un message à
     *     l'autre (mêmes connecteurs), elles se relisent donc aussi au message
     *     suivant — et survivent à un prompt système qui bouge ;
     *   - la FIN DU FIL (dernier bloc du dernier message utilisateur : question
     *     ou résultats d'outils) : l'aller suivant relit tout ce qui précède et
     *     n'écrit que le nouveau tour. Posé dès le premier aller en `eager`, à
     *     partir du second en `lazy` : une réponse directe n'en relirait rien
     *     et paierait l'écriture pour rien. JAMAIS au dernier aller forcé :
     *     son `tool_choice` diffère, le cache des messages y est invalidé — on
     *     paierait l'écriture sans lecture possible.
     *
     * Un préfixe sous le minimum du modèle (512 à 4 096 jetons) n'est pas mis
     * en cache, sans erreur ni surcoût. Rien n'est posé sur un bloc de
     * raisonnement (non éligible) : le point vit dans un message utilisateur.
     */
    public static function withCacheBreakpoints(array $payload, string $mode, int $roundsDone, bool $lastRound = false): array
    {
        if ($mode === 'off') {
            return $payload;
        }
        $mark = ['type' => 'ephemeral'];
        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $last = array_key_last($payload['tools']);
            // Seuls les outils applicatifs (sans `type`) portent le point : l'outil
            // serveur de recherche web est placé en tête du tableau.
            if (!isset($payload['tools'][$last]['type'])) {
                $payload['tools'][$last]['cache_control'] = $mark;
            }
        }
        if (empty($payload['messages']) || !is_array($payload['messages'])) {
            return $payload;
        }
        // Même forme d'un aller à l'autre : un message en chaîne devient un bloc
        // texte — sinon le message marqué à cet aller et le même, non marqué,
        // à l'aller suivant pourraient ne pas se rendre à l'identique.
        foreach ($payload['messages'] as $i => $m) {
            if (is_string($m['content'] ?? null) && trim($m['content']) !== '') {
                $payload['messages'][$i]['content'] = [['type' => 'text', 'text' => $m['content']]];
            }
        }
        $tail = !$lastRound && ($mode === 'eager' || $roundsDone >= 1);
        if (!$tail) {
            return $payload;
        }
        for ($i = count($payload['messages']) - 1; $i >= 0; $i--) {
            if (($payload['messages'][$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $payload['messages'][$i]['content'] ?? '';
            if (!is_array($content)) {
                return $payload;
            }
            for ($j = count($content) - 1; $j >= 0; $j--) {
                $type = (string)($content[$j]['type'] ?? '');
                $eligible = in_array($type, ['image', 'document', 'tool_result'], true)
                    || ($type === 'text' && trim((string)($content[$j]['text'] ?? '')) !== '');
                if ($eligible) {
                    $payload['messages'][$i]['content'][$j]['cache_control'] = $mark;
                    break;
                }
            }
            return $payload;
        }
        return $payload;
    }

    /**
     * Fil d'une réponse en cours, reconstruit depuis ses tours. Pure.
     *
     * Deux formes :
     *   - FIDÈLE (`$full`) : chaque tour assistant est rejoué avec ses blocs
     *     dans l'ordre reçu — raisonnement (`thinking` signé, même vide ;
     *     `redacted_thinking`), texte à sa place (citations comprises), blocs
     *     serveur de recherche web, `tool_use`. C'est ce que la doc d'Anthropic
     *     exige dans un tour d'outils : sans les blocs de raisonnement, le
     *     modèle repart sans ce qu'il avait réfléchi (dégradation muette), et
     *     sans les blocs serveur un tour en pause (`pause_turn`) ne peut pas
     *     reprendre. Un tour dont la capture n'est pas rejouable (bascule de
     *     relais en cours de réponse, raisonnement non signé) y garde la forme
     *     d'avant ;
     *   - D'AVANT : texte fusionné + `tool_use`, rien d'autre — le repli quand
     *     l'API refuse le fil rejoué.
     *
     * Un tour en pause (résultats null) n'a pas de message utilisateur : ses
     * blocs ouvrent le message assistant du tour qui le poursuit (deux
     * messages assistant d'affilée seraient refusés) ; s'il est le dernier, il
     * termine le fil — c'est ainsi que l'API sait reprendre.
     *
     * Un résultat en erreur (`['error' => …]`) part avec `is_error: true` : le
     * modèle distingue « l'outil a échoué » d'un résultat vide, et corrige.
     *
     * @param list<array{blocks:?list<array>,text:string,tool_calls:list<array>,results:?list<array{id:string,result:array}>}> $rounds
     * @return list<array{role:string,content:list<array>}>
     */
    public static function roundsToMessages(array $rounds, bool $full): array
    {
        $messages = [];
        $pending = [];
        foreach ($rounds as $r) {
            $blocks = ($full && $r['blocks'] !== null) ? $r['blocks'] : self::legacyAssistantContent($r);
            foreach ($blocks as $b) {
                $pending[] = $b;
            }
            if ($r['results'] === null) {
                continue; // tour en pause : la suite complète le même message assistant
            }
            if ($pending !== []) {
                $messages[] = ['role' => 'assistant', 'content' => $pending];
            }
            $pending = [];
            $toolResults = [];
            foreach ($r['results'] as $tr) {
                $result = is_array($tr['result']) ? $tr['result'] : [];
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string)$tr['id'],
                    'content'     => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                ];
                if (isset($result['error'])) {
                    $block['is_error'] = true;
                }
                $toolResults[] = $block;
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
        if ($pending !== []) {
            $messages[] = ['role' => 'assistant', 'content' => $pending];
        }
        return $messages;
    }

    /**
     * Forme d'avant d'un tour assistant : texte fusionné + tool_use. Pure.
     *
     * @param array{text:string,tool_calls:list<array{id:string,name:string,input:array}>} $round
     * @return list<array>
     */
    private static function legacyAssistantContent(array $round): array
    {
        $content = [];
        if ($round['text'] !== '') {
            $content[] = ['type' => 'text', 'text' => $round['text']];
        }
        foreach ($round['tool_calls'] as $tc) {
            $content[] = [
                'type'  => 'tool_use',
                'id'    => $tc['id'],
                'name'  => $tc['name'],
                // Objet JSON, jamais `[]` : un outil sans argument serait refusé.
                'input' => $tc['input'] === [] ? new \stdClass() : $tc['input'],
            ];
        }
        return $content;
    }

    /**
     * Le fil rejoué porte-t-il un bloc que seule la forme fidèle envoie
     * (raisonnement, bloc serveur) ? Sinon un refus de la requête ne peut pas
     * venir du rejeu, et on ne se replie pas pour rien. Pure.
     */
    public static function roundsCarrySpecialBlocks(array $rounds): bool
    {
        foreach ($rounds as $r) {
            foreach ($r['blocks'] ?? [] as $b) {
                $type = (string)(is_array($b) ? ($b['type'] ?? '') : ($b->type ?? ''));
                if (!in_array($type, ['text', 'tool_use'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Le dernier tour est-il en pause (attend sa reprise) ? Pure. */
    public static function endsPaused(array $rounds): bool
    {
        $last = $rounds === [] ? null : $rounds[count($rounds) - 1];
        return $last !== null && $last['results'] === null;
    }

    /**
     * Refus d'un fil rejoué : 400 qui désigne un bloc que seule la forme
     * fidèle envoie. Pure.
     */
    public static function isReplayRejection(int $status, string $message): bool
    {
        return $status === 400
            && preg_match('/thinking|signature|server_tool_use|web_search_tool_result|redacted|citation|encrypted_content/i', $message) === 1;
    }

    /** Traduit le schéma agnostique → format Anthropic (input_schema). */
    private function buildAnthropicTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[] = [
                'name'         => (string)($t['name'] ?? ''),
                'description'  => (string)($t['description'] ?? ''),
                'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $out;
    }

    /**
     * Un aller : POST messages en streaming, émet le texte, accumule les
     * blocs tool_use (input JSON fragmenté via input_json_delta) et l'usage.
     *
     * `$webSearchAttached` autorise le round à signaler un refus de l'outil
     * SERVEUR de recherche web (`retry_web_search`) au lieu d'émettre l'erreur :
     * l'appelant redescend alors d'un barreau. Sans outil web attaché, un
     * « unknown tool » vient forcément des outils applicatifs et doit remonter.
     * `retry` porte les autres rejeux (repli serveur refusé, organisation sans
     * conservation des données, plafond de sortie refusé) — mêmes règles que
     * streamWithPayload().
     *
     * @return array{text:string,tool_calls:list<array{id:string,name:string,input:array}>,input:int,output:int,input_cached:int,input_cache_write:int,error:bool,retry_web_search:bool,retry:?string,retry_model:?string,citations:array<int,array{url:string,title:string}>,refusal:?string,served_model:?string,additional:list<array>}
     */
    private function streamToolRound(
        array $payload,
        callable $onChunk,
        bool $webSearchAttached = false,
        ?string $retentionRelay = null,
        bool $replayedHistory = false
    ): array {
        $model = (string)($payload['model'] ?? $this->model);
        $fail = static function (bool $retryWebSearch = false, ?string $retry = null, ?string $retryModel = null): array {
            return ['text' => '', 'tool_calls' => [], 'blocks' => null, 'input' => 0, 'output' => 0,
                    'input_cached' => 0, 'input_cache_write' => 0, 'error' => true,
                    'retry_web_search' => $retryWebSearch, 'retry' => $retry, 'retry_model' => $retryModel,
                    'citations' => [], 'refusal' => null, 'served_model' => null, 'additional' => [],
                    'stop_reason' => null];
        };

        // JSON_PRESERVE_ZERO_FRACTION : un `12.0` écrit par le modèle dans
        // l'entrée d'un outil repart en `12.0`, pas en `12` — le fil rejoué
        // doit rester celui que l'API a produit (cf. replayBlocks).
        $body = LlmAdapterHelpers::encodeBody($payload, JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) {
            error_log('[AnthropicAdapter/Tools] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Anthropic : requête invalide (encodage payload)]');
            return $fail();
        }

        [$stream, $httpStatus] = $this->openRoundStream($payload, $body);
        if (!$stream) {
            error_log('[AnthropicAdapter/Tools] Impossible d\'ouvrir le stream vers ' . $this->apiUrl());
            $onChunk('[Erreur : impossible de contacter Anthropic]');
            return $fail();
        }
        stream_set_read_buffer($stream, 0);
        stream_set_timeout($stream, self::SILENCE_TICK_SEC);

        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[AnthropicAdapter/Tools] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $model);
            // Réglage « agent » refusé (bêta non ouverte, valeur inconnue du
            // modèle) : appris pour le process, même demande rejouée sans lui.
            // AVANT le repli sur refus : un en-tête bêta refusé à cause des
            // notes d'avancement cite `anthropic-beta`, que le repli
            // revendiquait — il coupait alors le repli sur refus pour tout le
            // process, puis rejouait la demande avec le même `display`. Et
            // AVANT le refus d'un fil rejoué — les deux parlent de `thinking`.
            $feature = self::rejectedAgentFeature($httpStatus, $errMsg, $payload);
            if ($feature !== null) {
                self::$agentFeaturesRejected[$feature] = true;
                error_log('[AnthropicAdapter/Tools] réglage « ' . $feature . ' » refusé — requêtes suivantes sans lui.');
                return $fail(false, 'agent_feature');
            }
            if (isset($payload['fallbacks']) && self::isFallbacksRejection($httpStatus, $errMsg)) {
                self::$fallbacksRejected = true;
                error_log('[AnthropicAdapter/Tools] repli serveur sur refus indisponible — requêtes suivantes sans `fallbacks`.');
                return $fail(false, 'fallbacks');
            }
            if ($retentionRelay !== null && self::isRetentionError($httpStatus, $errMsg)) {
                error_log('[AnthropicAdapter/Tools] ' . $model . ' exige la conservation des données — relais sur ' . $retentionRelay . '.');
                return $fail(false, 'retention', $retentionRelay);
            }
            if (self::learnOutputCeiling($model, $httpStatus, $errMsg, (int)($payload['max_tokens'] ?? 0))) {
                return $fail(false, 'max_tokens');
            }
            // Fil rejoué refusé (raisonnement, bloc serveur) : l'appelant
            // repasse sur la forme d'avant, rien n'est affiché.
            if ($replayedHistory && self::isReplayRejection($httpStatus, $errMsg)) {
                return $fail(false, 'replay');
            }
            // Type d'outil web search non reconnu : l'appelant redescend d'un
            // barreau, on ne pollue pas la conversation avec l'erreur.
            if ($webSearchAttached && $this->shouldFallbackWebSearchTool($errMsg)) {
                return $fail(true);
            }
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', $httpStatus, $errMsg));
            return $fail();
        }

        $text = '';
        // Blocs du tour par index, DANS L'ORDRE reçu : raisonnement, texte,
        // tool_use, blocs serveur. Le texte reste aussi cumulé à part ($text)
        // pour la forme d'avant.
        $blocks = [];
        $replayable = true; // la capture peut-elle être rejouée fidèlement ?
        $in = 0; $out = 0; $cached = 0; $cacheWrite = 0; $searches = 0;
        $usageAcc = self::EMPTY_STREAM_USAGE; // relevé du flux (cf. mergeStreamUsage)
        $rawBuffer = '';
        $citationSources = [];
        $refusal = null;
        $stopReason = null;
        $servedModel = null;
        $iterations = null;
        $pending = '';
        $stalled = false;
        $stopped = false;

        while (($line = self::readSseLine($stream, $pending, $stalled, $stopped)) !== null) {
            if (!str_starts_with($line, 'data: ')) {
                if (LlmAdapterHelpers::isSseNoise($line)) continue;
                $rawBuffer .= $line;
                continue;
            }

            if ($rawBuffer !== '') {
                $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
                error_log('[AnthropicAdapter/Tools] Erreur (rawBuffer) : ' . $msg);
                fclose($stream);
                if ($webSearchAttached && $text === '' && $this->shouldFallbackWebSearchTool((string)$msg)) {
                    return $fail(true);
                }
                $onChunk('[Erreur Anthropic : ' . $msg . ']');
                return $fail();
            }

            $data = trim(substr($line, 6));
            if ($data === '' || $data === '[DONE]') continue;

            $json = json_decode($data, true);
            if (!is_array($json)) continue;
            $this->collectCitationSourcesFromNode($json, $citationSources);

            $type = (string)($json['type'] ?? '');

            if ($type === 'content_block_start') {
                $idx = (int)($json['index'] ?? 0);
                $block = $json['content_block'] ?? [];
                $btype = (string)($block['type'] ?? '');
                if (self::isToolBlockType($btype)) {
                    $this->paragraphPending = true; // le texte qui suit ouvre un paragraphe
                }
                if ($btype === 'fallback') {
                    // Relais serveur en cours de réponse : les tool_use reçus
                    // AVANT la bascule appartiennent à la sortie déclinée —
                    // ni exécutés ni rejoués (règle documentée d'Anthropic).
                    // Le texte, lui, reste : le relais s'en sert pour poursuivre.
                    // Le tour n'est plus rejouable fidèlement (le bloc de
                    // bascule devrait rester à sa place) : forme d'avant.
                    $to = (string)($block['to']['model'] ?? '');
                    if ($to !== '') {
                        $servedModel = $to;
                    }
                    $blocks = [];
                    $replayable = false;
                } elseif ($btype === 'tool_use' || $btype === 'server_tool_use') {
                    $blocks[$idx] = [
                        'type' => $btype,
                        'id'   => (string)($block['id'] ?? ''),
                        'name' => (string)($block['name'] ?? ''),
                        'json' => '',
                    ];
                } elseif ($btype === 'thinking') {
                    $blocks[$idx] = [
                        'type'      => 'thinking',
                        'thinking'  => (string)($block['thinking'] ?? ''),
                        'signature' => (string)($block['signature'] ?? ''),
                    ];
                } elseif ($btype === 'redacted_thinking') {
                    $blocks[$idx] = ['type' => 'redacted_thinking', 'data' => (string)($block['data'] ?? '')];
                } elseif ($btype === 'text') {
                    $blocks[$idx] = ['type' => 'text', 'text' => (string)($block['text'] ?? ''), 'citations' => []];
                } elseif (str_ends_with($btype, '_tool_result')) {
                    // Résultat d'un outil SERVEUR (recherche web…) : complet dès
                    // son ouverture, gardé tel quel (objets JSON préservés —
                    // un `{}` décodé en tableau reviendrait en `[]`).
                    $raw = json_decode($data, false);
                    $blocks[$idx] = ['type' => 'server_result', 'raw' => is_object($raw) ? ($raw->content_block ?? null) : null];
                } elseif ($btype !== '') {
                    // Bloc inconnu : on ne sait pas le rejouer à l'identique.
                    $replayable = false;
                }
            } elseif ($type === 'content_block_delta') {
                $idx   = (int)($json['index'] ?? 0);
                $delta = $json['delta'] ?? [];
                $dtype = (string)($delta['type'] ?? '');
                if ($dtype === 'input_json_delta') {
                    if (isset($blocks[$idx])) {
                        $blocks[$idx]['json'] .= (string)($delta['partial_json'] ?? '');
                    }
                } elseif ($dtype === 'thinking_delta') {
                    // Raisonnement : jamais affiché comme réponse. Avec
                    // `display: "updates"`, un bloc au texte non vide est une
                    // note d'avancement (entre deux appels d'outils).
                    if (isset($blocks[$idx]) && $blocks[$idx]['type'] === 'thinking') {
                        $blocks[$idx]['thinking'] .= (string)($delta['thinking'] ?? '');
                    }
                } elseif ($dtype === 'signature_delta') {
                    if (isset($blocks[$idx]) && $blocks[$idx]['type'] === 'thinking') {
                        $blocks[$idx]['signature'] .= (string)($delta['signature'] ?? '');
                    }
                } elseif ($dtype === 'citations_delta') {
                    if (isset($blocks[$idx]) && $blocks[$idx]['type'] === 'text' && is_array($delta['citation'] ?? null)) {
                        $blocks[$idx]['citations'][] = $delta['citation'];
                    }
                } else {
                    // text_delta (ou autre) : on émet le texte.
                    $content = (string)($delta['text'] ?? '');
                    if ($content !== '') {
                        $this->emitText($onChunk, $content);
                        $text .= $content;
                        if (isset($blocks[$idx]) && $blocks[$idx]['type'] === 'text') {
                            $blocks[$idx]['text'] .= $content;
                        }
                        if (LlmAdapterHelpers::clientAborted()) break;
                    }
                }
            } elseif ($type === 'content_block_stop') {
                // Note d'avancement (display « updates ») : un bloc de
                // raisonnement au texte non vide, écrit avant l'appel d'outil
                // qu'il annonce. Transmise telle quelle, jamais dans la réponse.
                $idx = (int)($json['index'] ?? 0);
                $onProgress = $this->agentOptions['on_progress'] ?? null;
                if (is_callable($onProgress) && isset($blocks[$idx]) && $blocks[$idx]['type'] === 'thinking') {
                    $note = self::progressNote((string)$blocks[$idx]['thinking']);
                    if ($note !== null) {
                        $onProgress($note);
                    }
                }
            } elseif ($type === 'message_start' && isset($json['message']['usage']) && is_array($json['message']['usage'])) {
                $usageAcc = self::mergeStreamUsage($usageAcc, $json['message']['usage']);
            } elseif ($type === 'message_delta') {
                $refusal = self::refusalFromDelta($json['delta'] ?? null) ?? $refusal;
                $stopReason = self::stopReasonFromDelta($json['delta'] ?? null) ?? $stopReason;
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usageAcc = self::mergeStreamUsage($usageAcc, $json['usage']);
                    $iterations = $json['usage']['iterations'] ?? $iterations;
                }
            } elseif ($type === 'error') {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[AnthropicAdapter/Tools] Erreur (SSE) : ' . $errMsg);
                fclose($stream);
                if ($webSearchAttached && $text === '' && $this->shouldFallbackWebSearchTool((string)$errMsg)) {
                    return $fail(true);
                }
                $onChunk('[Erreur Anthropic : ' . $errMsg . ']');
                return $fail();
            }
        }

        fclose($stream);
        [$in, $cached, $cacheWrite, $out, $searches] = self::streamUsageTotals($usageAcc);

        // Silence continu : réponse abandonnée, ses appels d'outils aussi.
        if ($stalled) {
            error_log('[AnthropicAdapter/Tools] Flux abandonné après ' . self::SILENCE_MAX_SEC . ' s de silence | model=' . $model);
            // Émis même après du texte : le chat en fait une note sous la
            // réponse, au lieu d'une coupure muette.
            $onChunk('[Erreur Anthropic : timeout — aucune donnée reçue depuis ' . self::SILENCE_MAX_SEC . ' s]');
            $blocks = [];
        }
        // Arrêt demandé pendant un silence : rien à exécuter.
        if ($stopped) {
            $blocks = [];
        }

        // Relais serveur : les itérations font foi (cf. relayUsage).
        $additional = [];
        $relayUsage = self::relayUsage($iterations, $model);
        if ($relayUsage !== null) {
            $servedModel = $relayUsage['model'];
            $in          = $relayUsage['tokens']['input'];
            $out         = $relayUsage['tokens']['output'];
            $cached      = $relayUsage['tokens']['input_cached'];
            $cacheWrite  = $relayUsage['tokens']['input_cache_write'];
            $additional  = $relayUsage['additional'];
            error_log('[AnthropicAdapter/Tools] Tour relayé par ' . $servedModel . ' (demandé : ' . $model . ').');
        }
        if ($servedModel === $model) {
            $servedModel = null;
        }

        // Tour décliné : ses éventuels appels d'outils appartiennent à une
        // sortie refusée — les exécuter lancerait des actions sur une demande
        // que le modèle a lui-même abandonnée. L'appelant émet le message.
        if ($refusal !== null) {
            error_log('[AnthropicAdapter/Tools] Demande déclinée (stop_reason=refusal'
                . ($refusal !== '' ? ', catégorie ' . $refusal : '') . ') | model=' . ($servedModel ?? $model));
            $blocks = [];
            // Refus avant toute sortie : rapporté, facturé seulement dans
            // certaines catégories (même règle que le chemin sans outils).
            if ($out === 0 && !self::refusalBilledBeforeOutput($refusal)) {
                $in = $cached = $cacheWrite = 0;
            }
        }

        // Finalise les appels d'outils : décode l'input JSON accumulé.
        // Les blocs `server_tool_use` (recherche web) ne sont PAS exécutés —
        // Anthropic les mène lui-même, ils n'ont pas de handler.
        $toolCalls = [];
        ksort($blocks);
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') !== 'tool_use' || trim($b['name']) === '') continue;
            $input = $b['json'] !== '' ? json_decode($b['json'], true) : [];
            if (!is_array($input)) $input = [];
            $toolCalls[] = ['id' => $b['id'], 'name' => $b['name'], 'input' => $input];
        }

        return ['text' => $text, 'tool_calls' => $toolCalls,
                // Forme fidèle du tour — null si elle n'est pas rejouable
                // (abandon, refus, bascule de relais, bloc inconnu).
                'blocks' => ($replayable && !$stalled && !$stopped && $refusal === null) ? self::replayBlocks($blocks) : null,
                'input' => $in, 'output' => $out,
                'input_cached' => $cached, 'input_cache_write' => $cacheWrite, 'web_searches' => $searches,
                'error' => $stalled, 'retry_web_search' => false, 'retry' => null, 'retry_model' => null,
                'citations' => $citationSources, 'refusal' => $refusal,
                'served_model' => $servedModel, 'additional' => $additional,
                // Arrêt du tour (`max_tokens`…) — sans objet si le tour a été
                // abandonné (silence, arrêt demandé) ou décliné.
                'stop_reason' => ($stalled || $stopped || $refusal !== null) ? null : $stopReason];
    }

    /**
     * Ouvre le flux d'un tour de la boucle d'outils : POST en streaming, et le
     * statut HTTP lu dans `$http_response_header` (variable locale magique :
     * elle n'existe que dans la portée du fopen). Protégée pour que les tests
     * rejouent une boucle entière — plusieurs tours, requêtes relues — sans
     * réseau.
     *
     * @return array{0: resource|false, 1: ?int}
     */
    protected function openRoundStream(array $payload, string $body): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $this->headersFor($payload)),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);
        $stream = fopen($this->apiUrl(), 'r', false, $ctx);
        return [$stream, LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null)];
    }

    /**
     * Blocs capturés d'un tour → contenu assistant rejouable à l'identique.
     * Pure. Null si un bloc ne peut pas l'être (raisonnement sans signature,
     * résultat serveur illisible) : le tour gardera la forme d'avant.
     *
     * Les entrées d'outils sont décodées en OBJETS : un argument `{}`
     * reviendrait sinon en `[]`, et un outil sans argument serait refusé.
     *
     * @param array<int, array> $blocks capture ordonnée (ksort) de streamToolRound
     * @return list<array>|null
     */
    public static function replayBlocks(array $blocks): ?array
    {
        $out = [];
        foreach ($blocks as $b) {
            switch ($b['type'] ?? '') {
                case 'thinking':
                    if ($b['signature'] === '') {
                        return null;
                    }
                    // Rejoué tel que reçu, texte vide compris (affichage omis).
                    $out[] = ['type' => 'thinking', 'thinking' => $b['thinking'], 'signature' => $b['signature']];
                    break;
                case 'redacted_thinking':
                    if ($b['data'] === '') {
                        return null;
                    }
                    $out[] = ['type' => 'redacted_thinking', 'data' => $b['data']];
                    break;
                case 'text':
                    if ($b['text'] === '') {
                        break; // un bloc texte vide serait refusé
                    }
                    $block = ['type' => 'text', 'text' => $b['text']];
                    if (!empty($b['citations'])) {
                        $block['citations'] = $b['citations'];
                    }
                    $out[] = $block;
                    break;
                case 'tool_use':
                case 'server_tool_use':
                    if (trim((string)$b['name']) === '') {
                        return null;
                    }
                    $input = $b['json'] !== '' ? json_decode($b['json'], false) : null;
                    $out[] = [
                        'type'  => $b['type'],
                        'id'    => $b['id'],
                        'name'  => $b['name'],
                        'input' => is_object($input) ? $input : new \stdClass(),
                    ];
                    break;
                case 'server_result':
                    if (!is_object($b['raw'] ?? null)) {
                        return null;
                    }
                    $out[] = $b['raw'];
                    break;
                default:
                    return null;
            }
        }
        return $out;
    }
}
