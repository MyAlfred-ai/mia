<?php

declare(strict_types=1);

/**
 * Modèle SCRIPTÉ pour le banc de scénarios : il joue une suite d'allers-
 * retours écrite d'avance, à travers la VRAIE conduite de boucle (budget
 * relu à chaque aller, définitions relues par `tools_source`, dispatch unique
 * de l'appelant) — sans réseau ni clé.
 *
 * Un script est une liste d'étapes :
 *   - `['appels' => [[nom, arguments], …]]` : appels d'outils de cet aller ;
 *   - `['texte' => '…']` : réponse finale (fin du script).
 * Dans les arguments, une chaîne `$i.chemin.vers.valeur` est remplacée par la
 * valeur lue dans le résultat de l'appel n° i de l'aller PRÉCÉDENT (ex.
 * `$0.resultats.0.id`) — comme un modèle qui recopie l'identifiant qu'il
 * vient de recevoir. Un chemin introuvable laisse la chaîne telle quelle.
 */
final class ScriptedLlm implements LLMInterface
{
    /** Définitions vues au dernier aller (noms d'outils), pour les tests. */
    public array $toolNames = [];

    /** Prompt système reçu. */
    public string $systemPrompt = '';

    /** Allers effectivement joués. */
    public int $rounds = 0;

    /** @param list<array> $steps */
    public function __construct(private readonly array $steps)
    {
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        $onChunk((string)($this->steps[0]['texte'] ?? ''));
        return ['input' => 0, 'output' => 0];
    }

    public function getProviderName(): string
    {
        return 'bench';
    }

    public function supportsToolUse(): bool
    {
        return true;
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
        $this->systemPrompt = $systemPrompt;
        $this->toolNames    = array_column($tools, 'name');
        $previous = [];
        foreach ($this->steps as $step) {
            if ($this->rounds >= LlmAdapterHelpers::roundsBudget($options)) {
                return $this->tokens() + ['rounds_exhausted' => true];
            }
            if (is_callable($options['tools_source'] ?? null)) {
                $this->toolNames = array_column((array)($options['tools_source'])(), 'name');
            }
            $this->rounds++;
            if (isset($step['texte'])) {
                $onChunk((string)$step['texte']);
                return $this->tokens();
            }
            $results = [];
            foreach ((array)($step['appels'] ?? []) as [$name, $args]) {
                $results[] = $onToolCall((string)$name, self::resolve((array)$args, $previous));
            }
            $previous = $results;
        }
        return $this->tokens();
    }

    private function tokens(): array
    {
        return ['input' => 1000 * max(1, $this->rounds), 'output' => 200, 'input_cached' => 0];
    }

    /** Remplace les références `$i.chemin` par les valeurs de l'aller précédent. */
    private static function resolve(array $args, array $previous): array
    {
        array_walk_recursive($args, static function (mixed &$v) use ($previous): void {
            if (!is_string($v) || preg_match('/^\$(\d+)((?:\.[\w-]+)+)$/', $v, $m) !== 1) {
                return;
            }
            $node = $previous[(int)$m[1]] ?? null;
            foreach (explode('.', ltrim($m[2], '.')) as $key) {
                if (!is_array($node) || !array_key_exists(ctype_digit($key) ? (int)$key : $key, $node)) {
                    return;
                }
                $node = $node[ctype_digit($key) ? (int)$key : $key];
            }
            if (is_scalar($node)) {
                $v = (string)$node;
            }
        });
        return $args;
    }
}
