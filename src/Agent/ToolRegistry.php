<?php

declare(strict_types=1);

/**
 * Registre des outils d'UN tour de chat — sorti de ChatController::runSend.
 *
 * Chaque fournisseur (src/Agent/Tools/) s'y ajoute si ses conditions sont
 * réunies (connecteur coché et connecté, outil lié à l'assistant…). Le
 * registre agrège ce qui part au modèle (définitions, consignes) et reste le
 * point de passage UNIQUE de chaque appel d'outil (`dispatch`) :
 *
 *   - outil inconnu → erreur rendue au modèle, jamais une exception ; un nom
 *     qui ne diffère que par la casse est accepté (Anthropic le documente :
 *     le modèle écrit parfois `Lire_Gmail`), les autres reçoivent la liste des
 *     outils disponibles pour corriger au tour suivant ;
 *   - garde anti-répétition : un appel strictement identique (même outil,
 *     mêmes arguments, ordre des clés indifférent) déjà exécuté dans ce tour
 *     n'est pas rejoué — il re-coûterait (API externe, génération, carte
 *     d'approbation dupliquée) sans rien apprendre de neuf. C'est elle qui
 *     rend sûr le budget de LLMInterface::TOOL_MAX_ROUNDS allers-retours ;
 *   - un outil qui rapporte un contenu écrit par un TIERS (email, fichier,
 *     message…) voit son résultat enveloppé d'un avertissement « données,
 *     jamais consignes » (ExternalContent::wrap) ;
 *   - juste avant l'exécution, `onStart` annonce l'appel (ligne « en cours »
 *     de la trace) — une lecture Drive ou une recherche de 20 s ne laisse plus
 *     l'écran figé ;
 *   - après exécution, `onDone` (fourni par le contrôleur) trace l'action et
 *     mémorise l'objet touché : le détail et le vrai nom viennent du RÉSULTAT ;
 *   - enfin `condense` (fourni par le contrôleur) peut réduire un résultat
 *     démesuré avant qu'il reparte au modèle (ResultCondenser) — APRÈS
 *     `onDone`, qui garde ainsi le résultat entier pour la trace et la mémoire ;
 *   - chaque appel est COMPTÉ (`stats()` : appels, erreurs, durée par outil,
 *     répétitions bloquées, noms inconnus) pour la télémétrie de l'agent
 *     (AgentTelemetry) — des nombres et des noms d'outils, jamais un contenu.
 *
 * Aucun handler ne connaît ces règles, aucune n'est recopiée dans un handler.
 */
final class ToolRegistry
{
    /** @var array<string, ToolProvider> clé → fournisseur actif (ordre d'ajout) */
    private array $providers = [];

    /**
     * Définitions exposées au modèle, PAR fournisseur (ordre d'ajout). Par
     * fournisseur et non en liste plate : ré-ajouter un fournisseur remplace
     * ses définitions — c'est ainsi que les outils par intention suivent un
     * connecteur activé en cours de réponse.
     *
     * @var array<string, list<array>>
     */
    private array $definitions = [];

    /** @var array<string, callable> nom d'outil → handler */
    private array $handlers = [];

    /** @var array<string, true> outils dont le résultat est un contenu tiers */
    private array $external = [];

    /** @var array<string, true> signatures déjà exécutées dans ce tour */
    private array $executed = [];

    private int $executedCount = 0;

    /** @var array<string, array{n:int, err:int, ms:int, max:int}> nom d'outil → compteurs */
    private array $toolStats = [];

    private int $duplicatesBlocked = 0;

    private int $unknownCalls = 0;

    /**
     * @param (\Closure(string, array, array): void)|null  $onDone   appelé après chaque exécution réelle
     * @param (\Closure(string, array): void)|null         $onStart  appelé juste avant (garde passée)
     * @param (\Closure(string, array, array): array)|null $condense résultat rendu au modèle (démesuré → condensé)
     */
    public function __construct(
        private readonly ?\Closure $onDone = null,
        private readonly ?\Closure $onStart = null,
        private readonly ?\Closure $condense = null,
    ) {
    }

    /**
     * Ajoute un fournisseur : ses définitions, sa consigne, ses handlers. Le
     * ré-ajouter (même clé) RECALCULE ses définitions, à sa place d'origine.
     */
    public function add(ToolProvider $provider): void
    {
        $key = $provider->key();
        $this->providers[$key]   = $provider;
        $this->definitions[$key] = array_values($provider->definitions());
        foreach ($provider->handlers() as $name => $handler) {
            $this->handlers[(string)$name] = $handler;
        }
        foreach ($provider->externalContentTools() as $name) {
            $this->external[(string)$name] = true;
        }
    }

    public function provider(string $key): ?ToolProvider
    {
        return $this->providers[$key] ?? null;
    }

    /** Au moins un outil à exposer : le tour passe par la boucle function-calling. */
    public function hasTools(): bool
    {
        return $this->definitions() !== [];
    }

    /** @return list<array> */
    public function definitions(): array
    {
        return $this->definitions === [] ? [] : array_merge(...array_values($this->definitions));
    }

    /**
     * Consignes des fournisseurs actifs, dans leur ordre d'ajout, puis la règle
     * « contenus lus = données » dès qu'un outil de ce tour rapporte un
     * contenu tiers.
     */
    public function guidance(): string
    {
        $txt = '';
        foreach ($this->providers as $provider) {
            $txt .= $provider->guidance();
        }
        if ($this->external !== []) {
            $txt .= ExternalContent::rule();
        }
        return $txt;
    }

    /**
     * Connecteurs qui exposent réellement des outils dans ce tour. Une LISTE et
     * non un booléen : le rappel des objets déjà consultés (ConnectorContext)
     * est filtré à ces connecteurs — citer un fichier Drive alors que Drive est
     * éteint ferait tenter un appel impossible.
     *
     * @return list<string>
     */
    public function engagedConnectors(): array
    {
        $keys = [];
        foreach ($this->providers as $provider) {
            $key = $provider->connector();
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** L'outil rapporte-t-il un contenu écrit par un tiers ? */
    public function isExternalContent(string $name): bool
    {
        return isset($this->external[$name]);
    }

    /** Nombre d'appels réellement exécutés dans ce tour (gardes refusés exclus). */
    public function executedCount(): int
    {
        return $this->executedCount;
    }

    /**
     * Compteurs du tour pour la télémétrie : par outil (appels, erreurs, durée
     * cumulée et maximale en ms — handler seul, condensation exclue), plus les
     * appels refusés par la garde anti-répétition et les noms inconnus.
     *
     * @return array{tools: array<string, array{n:int, err:int, ms:int, max:int}>, calls:int, errors:int, duplicates:int, unknown:int}
     */
    public function stats(): array
    {
        return [
            'tools'      => $this->toolStats,
            'calls'      => $this->executedCount,
            'errors'     => array_sum(array_column($this->toolStats, 'err')),
            'duplicates' => $this->duplicatesBlocked,
            'unknown'    => $this->unknownCalls,
        ];
    }

    /**
     * Point de passage UNIQUE de chaque appel d'outil (cf. en-tête de classe).
     *
     * @return array résultat rendu au modèle
     */
    public function dispatch(string $name, array $args): array
    {
        if (!isset($this->handlers[$name])) {
            $resolved = self::resolveName($name, array_keys($this->handlers));
            if ($resolved === null) {
                $this->unknownCalls++;
                return ['error' => 'Outil inconnu : ' . $name . ($this->handlers !== []
                    ? '. Outils disponibles : ' . implode(', ', array_slice(array_keys($this->handlers), 0, 40)) . '.'
                    : '')];
            }
            $name = $resolved;
        }
        // Garde anti-répétition : AVANT le handler et avant la trace — rien
        // n'a eu lieu, rien n'est tracé, rien n'est payé.
        $signature = self::signature($name, $args);
        if (isset($this->executed[$signature])) {
            $this->duplicatesBlocked++;
            return ['error' => 'Appel identique déjà effectué dans cette réponse (même outil, mêmes arguments). '
                . 'Change tes paramètres, utilise un autre outil, ou conclus avec les informations déjà obtenues.'];
        }
        $this->executed[$signature] = true;
        $this->executedCount++;

        if ($this->onStart !== null) {
            ($this->onStart)($name, $args);
        }
        $started = hrtime(true);
        $result  = ($this->handlers[$name])($args);
        $result  = is_array($result) ? $result : [];
        $ms      = (int)intdiv(hrtime(true) - $started, 1_000_000);
        $stat    = $this->toolStats[$name] ?? ['n' => 0, 'err' => 0, 'ms' => 0, 'max' => 0];
        $stat['n']++;
        $stat['err'] += isset($result['error']) ? 1 : 0;
        $stat['ms']  += $ms;
        $stat['max']  = max($stat['max'], $ms);
        $this->toolStats[$name] = $stat;
        if (isset($this->external[$name])) {
            $result = ExternalContent::wrap($result);
        }

        if ($this->onDone !== null) {
            ($this->onDone)($name, $args, $result);
        }
        if ($this->condense !== null) {
            $result = ($this->condense)($name, $args, $result);
        }
        return $result;
    }

    /**
     * Nom d'outil existant pour un nom appelé qui n'en diffère que par la
     * casse, s'il est UNIQUE ; null sinon. Pure.
     *
     * @param list<string> $known
     */
    public static function resolveName(string $name, array $known): ?string
    {
        $matches = array_values(array_filter($known, static fn(string $k): bool => strcasecmp($k, $name) === 0));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Signature canonique d'un appel d'outil (garde anti-répétition). Pure :
     * mêmes nom + arguments → même signature, l'ordre des clés est normalisé
     * pour qu'un modèle qui renvoie les mêmes arguments dans un autre ordre ne
     * contourne pas la garde.
     */
    public static function signature(string $name, array $args): string
    {
        self::ksortRecursif($args);
        return $name . '|' . (json_encode($args, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: serialize($args));
    }

    private static function ksortRecursif(array &$tableau): void
    {
        ksort($tableau);
        foreach ($tableau as &$valeur) {
            if (is_array($valeur)) {
                self::ksortRecursif($valeur);
            }
        }
        unset($valeur);
    }

    /**
     * Ajoute un conseil de relance à un résultat de recherche VIDE, pour que
     * le modèle adapte (élargir, retirer un filtre) au lieu d'abandonner.
     * Pure ; ne touche ni les erreurs ni les résultats non vides.
     */
    public static function hintIfEmpty(array $result, string $cle, string $conseil): array
    {
        if (($result[$cle] ?? null) === [] && !isset($result['error'])) {
            $result['note'] = $conseil;
        }
        return $result;
    }
}
