<?php

declare(strict_types=1);

/**
 * Découpage sémantique des documents (bases de connaissances) via le LLM par
 * défaut de l'application.
 *
 * Principe — détection de FRONTIÈRES, pas de réécriture : le texte est d'abord
 * découpé déterministiquement en unités atomiques (paragraphes, hard-split des
 * paragraphes géants) par TextChunker. Le LLM ne reçoit que de courts extraits
 * numérotés et renvoie les indices d'unités qui DÉBUTENT une nouvelle section
 * thématique. Les chunks sont ensuite assemblés à partir du texte ORIGINAL —
 * le contenu n'est jamais réécrit ni tronqué par le LLM (intégrité garantie).
 *
 * Robustesse : tout échec (aucune clé, erreur API, réponse illisible, budget
 * dépassé) bascule silencieusement sur le découpage heuristique
 * (TextChunker::splitIntoChunks). L'ingestion n'échoue jamais à cause du LLM.
 */
class SemanticChunker
{
    /** Providers chat interrogés (dans l'ordre) si le défaut app est inutilisable. */
    private const PROVIDER_ORDER = ['openai', 'anthropic', 'mistral', 'gemini', 'deepseek', 'moonshot', 'zai', 'perplexity'];

    /** Longueur de l'extrait envoyé par unité (le LLM n'a pas besoin du texte entier). */
    private const SNIPPET_CHARS = 180;

    /** Unités par appel LLM + plafond d'appels par document (borne le coût). */
    private const MAX_UNITS_PER_CALL = 50;
    private const MAX_CALLS          = 8;

    /**
     * Plancher d'un chunk (tokens estimés). Une section sémantique en-dessous
     * est fusionnée avec sa voisine (nettoie les fragments isolés : titres
     * seuls, listes courtes) ; toute section au-dessus reste un chunk distinct
     * → on respecte les frontières de sens sans les diluer jusqu'à la cible.
     */
    private const MIN_CHUNK_TOKENS = 120;

    /**
     * Découpe $text en chunks sémantiquement cohérents. Retombe sur le
     * découpage heuristique en cas d'indisponibilité ou d'échec du LLM.
     *
     * `$usage` (par référence, facultatif) reçoit la CONSOMMATION des appels
     * LLM du découpage — `['llm' => 'provider::model', 'tokens' => [...],
     * 'calls' => N]` — ou null si aucun appel n'a eu lieu. Les tokens sont
     * sommés sur tous les lots (une opération = le découpage d'un document)
     * et comptés MÊME quand le résultat est inexploitable : ils ont été payés.
     * L'appelant journalise via `UsageMeter` (ce service ne sait pas à qui
     * imputer).
     *
     * @return list<string>
     */
    public static function split(string $text, ?array &$usage = null): array
    {
        $usage    = null;
        $maxChars = TextChunker::CHUNK_MAX_TOKENS * 4;
        $units    = TextChunker::paragraphUnits($text, $maxChars);

        // Rien à regrouper intelligemment : heuristique directe.
        if (count($units) <= 1) {
            return TextChunker::splitIntoChunks($text);
        }

        $llm = self::resolveChunkingLlm();
        if ($llm === null) {
            error_log('[KB] chunking sémantique indisponible (aucune clé LLM) — fallback heuristique');
            return TextChunker::splitIntoChunks($text);
        }

        $tokens = ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0];
        $calls  = 0;
        try {
            $boundaries = self::detectBoundaries($units, $llm, $tokens, $calls);
            if ($boundaries === null) {
                return TextChunker::splitIntoChunks($text);
            }
            $chunks = self::assemble($units, $boundaries);
            return $chunks !== [] ? $chunks : TextChunker::splitIntoChunks($text);
        } catch (\Throwable $e) {
            error_log('[KB] chunking sémantique échoué (' . $e->getMessage() . ') — fallback heuristique');
            return TextChunker::splitIntoChunks($text);
        } finally {
            if ($calls > 0) {
                $usage = ['llm' => $llm, 'tokens' => $tokens, 'calls' => $calls];
            }
        }
    }

    /**
     * Assemble les chunks à partir des unités et des indices de frontières
     * (début de section). Pur, testable sans LLM :
     *   1. groupe les unités entre frontières,
     *   2. borne chaque groupe à CHUNK_MAX (hard-split dimensionnel si besoin),
     *   3. fusionne les groupes voisins minuscules jusqu'à CHUNK_TARGET,
     *   4. reporte l'overlap.
     * @param list<string> $units
     * @param list<int>    $boundaries indices 0-based débutant une section
     * @return list<string>
     */
    public static function assemble(array $units, array $boundaries): array
    {
        $n = count($units);
        if ($n === 0) {
            return [];
        }
        $maxChars     = TextChunker::CHUNK_MAX_TOKENS      * 4;
        $overlapChars = TextChunker::CHUNK_OVERLAP_TOKENS  * 4;

        // Frontières valides : entières, dans [0, n-1], uniques, triées, avec 0.
        $bset = [0 => true];
        foreach ($boundaries as $b) {
            $b = (int)$b;
            if ($b > 0 && $b < $n) {
                $bset[$b] = true;
            }
        }
        $starts = array_keys($bset);
        sort($starts);

        // 1 : groupes d'unités entre frontières successives.
        $groups = [];
        $count  = count($starts);
        for ($i = 0; $i < $count; $i++) {
            $from = $starts[$i];
            $to   = ($i + 1 < $count) ? $starts[$i + 1] : $n;
            $groups[] = array_slice($units, $from, $to - $from);
        }

        // 2 : chaque section bornée à CHUNK_MAX (sinon découpe dimensionnelle,
        //     sans overlap ici — l'overlap est appliqué en toute fin).
        $sections = [];
        foreach ($groups as $g) {
            $joined = implode("\n\n", $g);
            if (mb_strlen($joined) <= $maxChars) {
                $sections[] = $joined;
            } else {
                foreach (TextChunker::regroupUnits($g, $maxChars, 0) as $piece) {
                    $sections[] = $piece;
                }
            }
        }

        // 3 : coalescence des fragments sous le plancher. On accumule tant que
        //     le buffer reste sous MIN_CHUNK, puis on vide : une section
        //     substantielle (≥ plancher) devient donc son propre chunk, les
        //     miettes voisines se regroupent. Le reliquat final rejoint le
        //     dernier chunk plutôt que de rester un fragment isolé.
        $floorChars = self::MIN_CHUNK_TOKENS * 4;
        $merged     = [];
        $buf        = '';
        foreach ($sections as $s) {
            $buf = $buf === '' ? $s : ($buf . "\n\n" . $s);
            if (mb_strlen($buf) >= $floorChars) {
                $merged[] = $buf;
                $buf = '';
            }
        }
        if ($buf !== '') {
            if ($merged !== []) {
                $merged[count($merged) - 1] .= "\n\n" . $buf;
            } else {
                $merged[] = $buf;
            }
        }

        // 4 : overlap + nettoyage.
        return TextChunker::applyOverlap($merged, $overlapChars);
    }

    /**
     * Slug "provider::model" du LLM à utiliser : défaut de l'app en priorité,
     * sinon premier provider chat disposant d'une clé API. null si aucun.
     */
    public static function resolveChunkingLlm(): ?string
    {
        $default = trim((string)Settings::get('default_llm', ''));
        if ($default !== '' && str_contains($default, '::')) {
            [$provider] = explode('::', $default, 2);
            if (ApiKey::getDecrypted($provider)) {
                return $default;
            }
        }
        foreach (self::PROVIDER_ORDER as $provider) {
            if (ApiKey::getDecrypted($provider)) {
                $model = ApiKey::getModel($provider) ?: LlmCatalog::defaultModel($provider);
                if ($model !== '') {
                    return $provider . '::' . $model;
                }
            }
        }
        return null;
    }

    /**
     * Interroge le LLM par lots pour obtenir les indices de début de section
     * sur l'ensemble des unités. Les frontières de lot sont toujours des
     * frontières ; un lot dont l'appel échoue n'ajoute simplement aucune
     * frontière interne (l'assemblage le bornera par taille). Au-delà de
     * MAX_CALLS lots, le reste n'est pas soumis (plafond de coût).
     * @param list<string> $units
     * @return list<int>|null  null = échec total (aucun lot exploitable)
     */
    private static function detectBoundaries(array $units, string $llm, array &$tokens, int &$calls): ?array
    {
        $adapter = LLMFactory::make($llm);
        [$provider, $model] = explode('::', $llm, 2);
        $temperature = LlmCatalog::supportsTemperature($provider, $model) ? 0.0 : null;

        $n         = count($units);
        $starts    = [];
        $anyOk     = false;
        $callCount = 0;

        for ($offset = 0; $offset < $n; $offset += self::MAX_UNITS_PER_CALL) {
            $window = array_slice($units, $offset, self::MAX_UNITS_PER_CALL);
            $starts[] = $offset; // début de lot = frontière garantie

            if ($callCount >= self::MAX_CALLS) {
                error_log('[KB] chunking sémantique : plafond d\'appels atteint, reste borné par taille');
                continue;
            }
            $callCount++;

            $rel = self::askBoundaries($adapter, $window, $temperature, $tokens);
            $calls++;
            if ($rel === null) {
                continue; // lot en échec → borné par taille à l'assemblage
            }
            $anyOk = true;
            foreach ($rel as $r) {
                if ($r > 0 && $r < count($window)) {
                    $starts[] = $offset + $r;
                }
            }
        }

        if (!$anyOk) {
            return null;
        }
        $starts = array_values(array_unique($starts));
        sort($starts);
        return $starts;
    }

    /**
     * Un appel LLM sur une fenêtre d'unités → indices relatifs de début de
     * section (0-based dans la fenêtre). null si erreur adaptateur / réponse
     * illisible.
     * @param list<string> $window
     * @return list<int>|null
     */
    private static function askBoundaries(LLMInterface $adapter, array $window, ?float $temperature, array &$tokens): ?array
    {
        $lines = [];
        foreach ($window as $i => $u) {
            $snippet = preg_replace('/\s+/u', ' ', trim($u));
            $snippet = mb_substr((string)$snippet, 0, self::SNIPPET_CHARS);
            $lines[] = '#' . $i . ': ' . $snippet;
        }
        $count  = count($window);
        $system = 'Tu segmentes un document pour un système de recherche (RAG). '
            . 'Objectif : regrouper les passages traitant du même sujet.';
        $prompt = "Voici les {$count} passages numérotés (0 à " . ($count - 1) . ") d'un document.\n"
            . "Indique les numéros de passage qui DÉBUTENT une nouvelle section thématique cohérente "
            . "(changement de sujet, nouvelle partie). Inclus toujours 0.\n"
            . "Réponds UNIQUEMENT par un tableau JSON d'entiers croissants, sans texte ni balise, "
            . "par exemple : [0,3,7]\n\n"
            . implode("\n", $lines);

        $out   = '';
        $first = null;
        $used = $adapter->stream(
            [['role' => 'user', 'content' => $prompt]],
            $system,
            function (string $chunk) use (&$out, &$first): void {
                if ($first === null) {
                    $first = $chunk;
                }
                $out .= $chunk;
            },
            $temperature
        );
        foreach ($tokens as $k => $v) {
            $tokens[$k] = $v + (int)($used[$k] ?? 0);
        }

        // Erreur API émise en tant que chunk texte par l'adaptateur.
        if ($first !== null && LlmErrorPresenter::parse($first) !== null) {
            return null;
        }
        return self::parseIndices($out, $count);
    }

    /**
     * Extrait le premier tableau JSON d'entiers d'une réponse LLM et le filtre
     * à [0, $count-1]. null si aucun tableau exploitable.
     * @return list<int>|null
     */
    public static function parseIndices(string $raw, int $count): ?array
    {
        if (preg_match('/\[[\s\d,]*\]/', $raw, $m) !== 1) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $i = (int)$v;
                if ($i >= 0 && $i < $count) {
                    $out[$i] = true;
                }
            }
        }
        if ($out === []) {
            return null;
        }
        $indices = array_keys($out);
        sort($indices);
        return $indices;
    }
}
