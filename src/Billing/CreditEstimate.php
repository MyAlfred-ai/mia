<?php

declare(strict_types=1);

/**
 * Estimation des crédits AVANT usage — SOURCE UNIQUE.
 *
 * Le problème résolu : chaque écran estimait à sa façon, et aucun ne disait
 * ce que la facturation compterait réellement.
 *   - les « € » des modèles ne lisaient que les lignes RENSEIGNÉES de Tarifs &
 *     crédits (jamais le tarif public du catalogue, que la facturation
 *     applique pourtant), et retombaient sur le prix d'un AUTRE modèle du même
 *     éditeur — un modèle sans ligne valait trois « € » d'office ;
 *   - ceux des outils demandaient le coût d'une image sans dire QUEL modèle :
 *     aucun tarif public trouvé, l'image passait pour la moins chère ;
 *   - le coût estimé d'un workflow ne trouvait jamais la moyenne de son modèle
 *     (clé `fournisseur::modèle::modèle` cherchée) et prenait celle de tous.
 *
 * Ici, une estimation passe TOUJOURS par CreditService — mêmes tarifs (ligne
 * renseignée, sinon tarif public), même coefficient, même arrondi, même
 * minimum que ce qui sera débité —, appliqués à une consommation RÉELLE :
 *   - réponse de chat : jetons MOYENS des réponses de ce modèle sur
 *     `WINDOW_DAYS` jours (contexte, historique, cache, raisonnement compris),
 *     sinon ceux de toutes les réponses de l'instance, sinon une réponse type
 *     (`DEFAULT_CHAT_TOKENS` : la consigne système de MIA pèse à elle seule
 *     environ 2 000 jetons) ;
 *   - outil : crédits MOYENS réellement débités par cet outil, sinon son
 *     tarif appliqué à une génération type ;
 *   - étape de workflow : jetons MOYENS des étapes de workflow menées par ce
 *     modèle (pièces lues comprises — elles pèsent plus qu'une question de
 *     chat), sinon une réponse de chat de ce modèle. Une étape s'écrit au
 *     grand livre comme un tour de chat (source `chat`) : elle n'en est
 *     distinguée que par sa conversation, celle d'une exécution
 *     (`conversations.workflow_run_id`), et retirée des moyennes de chat.
 *
 * Lectures du grand livre groupées (deux requêtes au plus par processus,
 * mémorisées `MEMO_TTL` secondes) ; ne lève jamais — une estimation
 * n'empêche rien, elle manque au pire.
 */
final class CreditEstimate
{
    /** Fenêtre de lecture du grand livre (jours). */
    public const WINDOW_DAYS = 30;

    /** Réponses d'un modèle à partir desquelles leur moyenne fait foi. */
    public const MIN_SAMPLES = 5;

    /** Générations d'un outil à partir desquelles leur moyenne fait foi. */
    public const MIN_TOOL_SAMPLES = 3;

    /** Réponse type tant que le grand livre est vide. */
    public const DEFAULT_CHAT_TOKENS = ['input' => 5000, 'output' => 600];

    /**
     * Pseudo-source des moyennes : un tour de chat mené dans la conversation
     * d'une exécution de workflow. N'existe pas au grand livre (la ligne y
     * porte `chat`) ; seulement dans les regroupements de `tokenRows()`.
     */
    public const WORKFLOW_STEP = 'workflow_step';

    /** Rédaction type d'une création par un modèle de langage, par source du grand livre. */
    public const DEFAULT_TOOL_TOKENS = [
        UsageSource::HTML_TOOL         => ['input' => 4000, 'output' => 9000],
        UsageSource::MAP_TOOL          => ['input' => 3000, 'output' => 2500],
        UsageSource::PRESENTATION_TOOL => ['input' => 6000, 'output' => 9000],
        UsageSource::WORKFLOW_JUDGE    => ['input' => 2500, 'output' => 120],
        // Préparation de la demande d'une image (prompt visuel rédigé par un
        // modèle) : quelques centaines de jetons dans chaque sens.
        UsageSource::IMAGE_PROMPT      => ['input' => 700, 'output' => 350],
        // Apprentissage de la mémoire : une transcription bornée (≈ 8 000
        // caractères), quelques opérations en réponse.
        UsageSource::MEMORY_LEARN      => ['input' => 3000, 'output' => 300],
    ];

    /**
     * Image d'un modèle facturé au jeton (OpenAI) : 1024×1024, qualité
     * moyenne — 1 056 jetons d'image produits, quelques jetons de demande.
     */
    public const TYPICAL_IMAGE_USAGE = ['text_input' => 150, 'image_input' => 0, 'image_output' => 1056];

    /** Recherches Internet par réponse qui en fait, tant que le grand livre ne dit rien. */
    public const DEFAULT_SEARCHES_PER_REPLY = 1.5;

    private const MEMO_TTL = 300;

    /** @var array{at:int, rows:list<array<string,mixed>>}|null */
    private static ?array $tokenMemo = null;
    /** @var array{at:int, rows:array<int, array{n:int, avg:float}>}|null */
    private static ?array $toolMemo = null;
    /** @var array{at:int, value:float}|null */
    private static ?array $searchMemo = null;

    /**
     * Lectures du grand livre imposées (tests) : groupes de jetons (source, k,
     * n, i, c, w, o, r), outils (`[tool_id => ['n' => …, 'avg' => …]]`) et
     * recherches moyennes par réponse. `forget()` rend la base.
     *
     * @param list<array<string,mixed>> $tokenRows
     * @param array<int, array{n:int, avg:float}> $toolRows
     */
    public static function useLedger(array $tokenRows, array $toolRows = [], ?float $searchesPerReply = null): void
    {
        $far = PHP_INT_MAX - self::MEMO_TTL;
        self::$tokenMemo = ['at' => $far, 'rows' => $tokenRows];
        self::$toolMemo = ['at' => $far, 'rows' => $toolRows];
        self::$searchMemo = ['at' => $far, 'value' => $searchesPerReply ?? self::DEFAULT_SEARCHES_PER_REPLY];
    }

    /** Oublie les lectures mémorisées (tests, process long). */
    public static function forget(): void
    {
        self::$tokenMemo = null;
        self::$toolMemo = null;
        self::$searchMemo = null;
    }

    // ------------------------------------------------------------------
    // Réponses de chat
    // ------------------------------------------------------------------

    /**
     * Crédits d'UNE réponse de chat avec ce modèle (hors recherche Internet),
     * ou null si le modèle n'a aucun tarif connu.
     *
     * @return array{credits:int, basis:string, samples:int}|null basis : model | instance | default
     */
    public static function chatReply(string $llmKey): ?array
    {
        $llmKey  = self::modelKey($llmKey);
        $profile = self::profileFrom(self::tokenRows(), UsageSource::CHAT, $llmKey, self::DEFAULT_CHAT_TOKENS);
        $credits = self::llmCredits($llmKey, $profile);

        return $credits === null ? null : ['credits' => $credits, 'basis' => $profile['basis'], 'samples' => $profile['samples']];
    }

    /**
     * Crédits d'UNE étape de workflow menée par ce modèle (hors recherche
     * Internet) : la moyenne des étapes réelles de ce modèle s'il y en a
     * assez, sinon une réponse de chat du même modèle.
     *
     * @return array{credits:int, basis:string, samples:int}|null basis : workflow | model | instance | default
     */
    public static function workflowStep(string $llmKey): ?array
    {
        $llmKey = self::modelKey($llmKey);
        $own = null;
        foreach (self::tokenRows() as $row) {
            if ((string)($row['source'] ?? '') === self::WORKFLOW_STEP && (string)($row['k'] ?? '') === $llmKey) {
                $own = $row;
                break;
            }
        }
        if ($own !== null && (int)$own['n'] >= self::MIN_SAMPLES) {
            $profile = self::profileFrom([$own], self::WORKFLOW_STEP, $llmKey, self::DEFAULT_CHAT_TOKENS);
            $credits = self::llmCredits($llmKey, $profile);
            if ($credits !== null) {
                return ['credits' => $credits, 'basis' => 'workflow', 'samples' => (int)$own['n']];
            }
        }

        return self::chatReply($llmKey);
    }

    /** Crédits d'UNE recherche Internet faite par ce modèle ; null s'il n'en fait pas ou sans tarif. */
    public static function webSearch(string $llmKey): ?int
    {
        $llmKey = self::modelKey($llmKey);
        [$provider, $model] = CreditService::splitLlmKey($llmKey);
        if (class_exists('LlmCatalog') && !LlmCatalog::supportsWebSearch($provider, $model)) {
            return null;
        }
        $res = CreditService::computeWebSearchCredits($llmKey, 1);

        return !empty($res['priced']) && (int)$res['total_credits'] > 0 ? (int)$res['total_credits'] : null;
    }

    /** Recherches moyennes d'une réponse qui en fait (grand livre, sinon `DEFAULT_SEARCHES_PER_REPLY`). */
    public static function searchesPerReply(): float
    {
        if (self::$searchMemo !== null && time() - self::$searchMemo['at'] < self::MEMO_TTL) {
            return self::$searchMemo['value'];
        }
        $value = self::DEFAULT_SEARCHES_PER_REPLY;
        try {
            $row = Database::getInstance()->query(
                "SELECT COUNT(*) AS n, AVG(quantity) AS q FROM usage_logs
                 WHERE source = 'web_search' AND quantity > 0 AND created_at >= NOW() - INTERVAL " . self::WINDOW_DAYS . ' DAY'
            )->fetch();
            if (is_array($row) && (int)$row['n'] >= self::MIN_SAMPLES && (float)$row['q'] > 0) {
                $value = (float)$row['q'];
            }
        } catch (\Throwable $e) {
            error_log('[Estimation] recherches moyennes illisibles : ' . $e->getMessage());
        }
        self::$searchMemo = ['at' => time(), 'value' => $value];

        return $value;
    }

    /** Crédits d'un appel auxiliaire de cette source (question d'une condition de workflow…). */
    public static function helperCall(string $source, string $llmKey): ?int
    {
        $llmKey  = self::modelKey($llmKey);
        $default = self::DEFAULT_TOOL_TOKENS[$source] ?? self::DEFAULT_CHAT_TOKENS;

        return self::llmCredits($llmKey, self::profileFrom(self::tokenRows(), $source, $llmKey, $default));
    }

    // ------------------------------------------------------------------
    // Outils de création
    // ------------------------------------------------------------------

    /**
     * Crédits d'UNE génération avec cet outil (une image, une vidéo, une page,
     * une carte, une présentation), ou null si rien ne permet de l'estimer.
     *
     * @return array{credits:int, basis:string, samples:int}|null basis : history | tariff
     */
    public static function toolUse(array $tool): ?array
    {
        $id   = (int)($tool['id'] ?? 0);
        $type = (string)($tool['type'] ?? '');
        $hist = self::toolRows()[$id] ?? null;
        if ($hist !== null && $hist['n'] >= self::MIN_TOOL_SAMPLES && $hist['avg'] > 0) {
            return ['credits' => max(1, (int)round($hist['avg'])), 'basis' => 'history', 'samples' => $hist['n']];
        }

        $cfg = json_decode((string)($tool['config_json'] ?? ''), true);
        $cfg = is_array($cfg) ? $cfg : [];
        $llmKey = (string)($tool['provider'] ?? '') . '::' . (string)($tool['model'] ?? '');
        $credits = match ($type) {
            'image' => self::imageCredits($id, (string)($tool['model'] ?? ''), $cfg),
            'video' => self::videoCredits($id, (string)($tool['model'] ?? ''), $cfg),
            'html', 'html_illustrated' => self::htmlCredits($type, $llmKey, $cfg),
            'map' => self::llmCredits($llmKey, self::profileFrom(self::tokenRows(), UsageSource::MAP_TOOL, $llmKey, self::DEFAULT_TOOL_TOKENS[UsageSource::MAP_TOOL])),
            'presentation' => self::llmCredits($llmKey, self::profileFrom(self::tokenRows(), UsageSource::PRESENTATION_TOOL, $llmKey, self::DEFAULT_TOOL_TOKENS[UsageSource::PRESENTATION_TOOL])),
            default => null,
        };

        return $credits === null ? null : ['credits' => $credits, 'basis' => 'tariff', 'samples' => 0];
    }

    private static function imageCredits(int $toolId, string $model, array $cfg): ?int
    {
        $pricing = CreditService::catalogToolPricing('image', $model);
        $res = CreditService::computeToolCredits($toolId, 'image', [
            'model'   => $model,
            'size'    => (string)($cfg['size'] ?? '1024x1024'),
            'quality' => (string)($cfg['quality'] ?? ''),
            'count'   => 1,
            // Facturé au jeton : sans consommation, aucun coût ne se calcule.
            'usage'   => is_array($pricing['tokens_usd_per_1m'] ?? null) ? self::TYPICAL_IMAGE_USAGE : null,
        ]);

        return !empty($res['priced']) ? (int)$res['total_credits'] : null;
    }

    private static function videoCredits(int $toolId, string $model, array $cfg): ?int
    {
        $res = CreditService::computeToolCredits($toolId, 'video', [
            'model'   => $model,
            'size'    => (string)($cfg['size'] ?? '720x1280'),
            'seconds' => (float)($cfg['seconds'] ?? 4),
            'count'   => 1,
        ]);

        return !empty($res['priced']) ? (int)$res['total_credits'] : null;
    }

    private static function htmlCredits(string $type, string $llmKey, array $cfg): ?int
    {
        $page = self::llmCredits($llmKey, self::profileFrom(self::tokenRows(), UsageSource::HTML_TOOL, $llmKey, self::DEFAULT_TOOL_TOKENS[UsageSource::HTML_TOOL]));
        if ($page === null || $type !== 'html_illustrated') {
            return $page;
        }
        // Site illustré : ses images, au coût de l'outil image qu'il emploie.
        $imageTool = (int)($cfg['image_tool_id'] ?? 0) > 0 && class_exists('ChatTool') ? ChatTool::findById((int)$cfg['image_tool_id']) : null;
        $image = $imageTool !== null ? self::toolUse($imageTool) : null;
        $count = max(1, min(6, (int)($cfg['max_images'] ?? 3)));

        return $page + ($image !== null ? $count * $image['credits'] : 0);
    }

    // ------------------------------------------------------------------
    // Formes pures
    // ------------------------------------------------------------------

    /**
     * Consommation type d'un appel (pure) : la moyenne des appels de CE modèle
     * pour cette source s'ils sont assez nombreux, sinon celle de toute
     * l'instance (pondérée), sinon `$default`.
     *
     * @param list<array<string,mixed>> $rows groupes (source, k, n, i, c, w, o, r)
     * @param array{input:int, output:int} $default
     * @return array{input:int, input_cached:int, input_cache_write:int, output:int, output_reasoning:int, basis:string, samples:int}
     */
    public static function profileFrom(array $rows, string $source, string $llmKey, array $default): array
    {
        $own = null;
        $sum = ['n' => 0, 'i' => 0.0, 'c' => 0.0, 'w' => 0.0, 'o' => 0.0, 'r' => 0.0];
        foreach ($rows as $row) {
            if ((string)($row['source'] ?? '') !== $source) {
                continue;
            }
            $n = (int)($row['n'] ?? 0);
            if ($n <= 0) {
                continue;
            }
            if ((string)($row['k'] ?? '') === $llmKey) {
                $own = $row;
            }
            $sum['n'] += $n;
            foreach (['i', 'c', 'w', 'o', 'r'] as $f) {
                $sum[$f] += (float)($row[$f] ?? 0) * $n;
            }
        }

        $pick = static fn(array $r, string $basis, int $n): array => [
            'input'             => (int)round((float)$r['i']),
            'input_cached'      => (int)round((float)$r['c']),
            'input_cache_write' => (int)round((float)$r['w']),
            'output'            => (int)round((float)$r['o']),
            'output_reasoning'  => (int)round((float)$r['r']),
            'basis'             => $basis,
            'samples'           => $n,
        ];
        if ($own !== null && (int)$own['n'] >= self::MIN_SAMPLES) {
            return $pick($own, 'model', (int)$own['n']);
        }
        if ($sum['n'] >= self::MIN_SAMPLES) {
            $avg = [];
            foreach (['i', 'c', 'w', 'o', 'r'] as $f) {
                $avg[$f] = $sum[$f] / $sum['n'];
            }
            return $pick($avg, 'instance', $sum['n']);
        }

        return [
            'input' => (int)$default['input'], 'input_cached' => 0, 'input_cache_write' => 0,
            'output' => (int)$default['output'], 'output_reasoning' => 0, 'basis' => 'default', 'samples' => 0,
        ];
    }

    /**
     * Niveau de coût de 1 à 5, RELATIF aux options comparées (pure) : échelle
     * logarithmique — un modèle dix fois plus cher qu'un autre gagne des
     * « € », sans écraser tous les autres au premier niveau comme le ferait
     * une échelle linéaire dès qu'un modèle hors norme est proposé. Une
     * option sans estimation ne reçoit rien.
     *
     * @param array<string|int, ?int> $credits
     * @return array<string|int, int>
     */
    public static function stars(array $credits): array
    {
        $known = array_filter($credits, static fn($c): bool => is_int($c) && $c > 0);
        if ($known === []) {
            return [];
        }
        $min = log((float)min($known));
        $max = log((float)max($known));
        $out = [];
        foreach ($known as $key => $c) {
            $out[$key] = $max - $min < 1e-9 ? 3 : 1 + (int)round((log((float)$c) - $min) / ($max - $min) * 4);
        }

        return $out;
    }

    /** Crédits ronds, pour un ordre de grandeur (« ≈ 800 », « ≈ 15 ») — pure. */
    public static function round(int $credits): int
    {
        if ($credits <= 0) {
            return 0;
        }
        // Exact sous 20 : « ≈ 15 » pour 13 crédits serait une hausse de 15 %.
        $step = $credits < 20 ? 1 : ($credits < 100 ? 5 : ($credits < 1000 ? 10 : 100));

        return max($step, (int)(round($credits / $step) * $step));
    }

    // ------------------------------------------------------------------
    // Lectures
    // ------------------------------------------------------------------

    /** Crédits d'un appel à la consommation donnée, comptés comme ils le seront ; null sans tarif. */
    /**
     * Clé « fournisseur::modèle » réellement appelée. Un fournisseur seul
     * (« openai » : aucun modèle activé, le chat prend celui de la clé, sinon
     * le défaut du catalogue) n'a aucun tarif : sans cette résolution, toute
     * estimation d'une telle instance tombait à « aucun tarif connu ».
     */
    public static function modelKey(string $llmKey): string
    {
        $llmKey = trim($llmKey);
        if ($llmKey === '' || str_contains($llmKey, '::')) {
            return $llmKey;
        }
        try {
            $model = (string)(ApiKey::getModel($llmKey) ?: LlmCatalog::defaultModel($llmKey));
        } catch (\Throwable) {
            $model = class_exists('LlmCatalog') ? LlmCatalog::defaultModel($llmKey) : '';
        }
        return $model !== '' ? $llmKey . '::' . $model : $llmKey;
    }

    private static function llmCredits(string $llmKey, array $profile): ?int
    {
        if ($llmKey === '' || $llmKey === '::' || (int)$profile['input'] + (int)$profile['output'] <= 0) {
            return null;
        }
        $res = CreditService::computeLlmCredits(
            $llmKey,
            (int)$profile['input'],
            (int)$profile['output'],
            min((int)$profile['input_cached'], (int)$profile['input']),
            (int)$profile['input_cache_write'],
            min((int)$profile['output_reasoning'], (int)$profile['output'])
        );

        return !empty($res['priced']) ? (int)$res['total_credits'] : null;
    }

    /** @return list<array<string,mixed>> */
    private static function tokenRows(): array
    {
        if (self::$tokenMemo !== null && time() - self::$tokenMemo['at'] < self::MEMO_TTL) {
            return self::$tokenMemo['rows'];
        }
        $rows = [];
        try {
            // Régénération = une réponse comme une autre ; un tour mené dans la
            // conversation d'une exécution de workflow = une étape (ses pièces
            // lues la font plus lourde qu'une question de chat : la mêler aux
            // réponses fausserait les deux moyennes). `llm_provider` porte la
            // clé complète « fournisseur::modèle ».
            $kind = "CASE WHEN u.source IN ('chat', 'regenerate') AND c.workflow_run_id IS NOT NULL THEN '" . self::WORKFLOW_STEP . "'
                          WHEN u.source = 'regenerate' THEN 'chat' ELSE u.source END";
            $rows = Database::getInstance()->query(
                "SELECT $kind AS source, u.llm_provider AS k, COUNT(*) AS n,
                        AVG(u.tokens_input) AS i, AVG(u.tokens_input_cached) AS c, AVG(u.tokens_input_cache_write) AS w,
                        AVG(u.tokens_output) AS o, AVG(u.tokens_output_reasoning) AS r
                 FROM usage_logs u LEFT JOIN conversations c ON c.id = u.conversation_id
                 WHERE u.created_at >= NOW() - INTERVAL " . self::WINDOW_DAYS . " DAY
                   AND u.source IN ('chat', 'regenerate', 'html_tool', 'map_tool', 'presentation_tool', 'workflow_judge', 'image_prompt')
                   AND u.tokens_input + u.tokens_output > 0
                 GROUP BY $kind, u.llm_provider"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[Estimation] consommation moyenne illisible : ' . $e->getMessage());
        }
        self::$tokenMemo = ['at' => time(), 'rows' => $rows];

        return $rows;
    }

    /** @return array<int, array{n:int, avg:float}> */
    private static function toolRows(): array
    {
        if (self::$toolMemo !== null && time() - self::$toolMemo['at'] < self::MEMO_TTL) {
            return self::$toolMemo['rows'];
        }
        $rows = [];
        try {
            foreach (Database::getInstance()->query(
                "SELECT tool_id, COUNT(*) AS n, AVG(credits_used) AS a FROM tool_usage_logs
                 WHERE credits_used > 0 AND source <> 'tool_test'
                   AND created_at >= NOW() - INTERVAL " . self::WINDOW_DAYS . ' DAY
                 GROUP BY tool_id'
            )->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['tool_id']] = ['n' => (int)$r['n'], 'avg' => (float)$r['a']];
            }
        } catch (\Throwable $e) {
            error_log('[Estimation] coûts des outils illisibles : ' . $e->getMessage());
        }
        self::$toolMemo = ['at' => time(), 'rows' => $rows];

        return $rows;
    }
}
