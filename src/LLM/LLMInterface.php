<?php

declare(strict_types=1);

interface LLMInterface
{
    /**
     * Nombre maximal d'allers-retours modèle ↔ outils par message, partagé
     * par toutes les boucles de function-calling (garde-fou anti-boucle).
     * SOURCE UNIQUE : les adapters l'héritent (aucune redéclaration locale)
     * et `AgentGuidance::method()` annonce ce budget au modèle — une
     * constante recopiée dériverait et la consigne mentirait.
     * 8 et non 3 : une chaîne « chercher email → lire → créer évènement »
     * consommait déjà 3 rounds, zéro marge pour adapter une recherche vide
     * ou croiser deux connecteurs. La garde anti-répétition du dispatch
     * (`ToolRegistry`) rend ce budget sûr.
     */
    public const TOOL_MAX_ROUNDS = 8;

    /**
     * Budget RELEVÉ quand l'agent affiche un plan de travail long (option
     * `max_rounds` de streamWithTools, cf. ChatController::toolLoopOptions) :
     * huit allers-retours ne suffisent pas à mener cinq étapes qui lisent
     * puis écrivent. Plafond dur de toute boucle.
     */
    public const TOOL_MAX_ROUNDS_EXTENDED = 12;

    /**
     * Envoie les messages au LLM et streame la réponse via SSE.
     * Retourne le nombre de tokens utilisés [input, output].
     *
     * @param array  $messages  [['role'=>'user','content'=>'...'], ...]
     * @param string $systemPrompt
     * @param callable $onChunk  Appelé à chaque chunk reçu avec le texte
     * @return array{input: int, output: int}
     */
    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array;

    public function getProviderName(): string;

    /**
     * Indique si l'adapter sait piloter une boucle de function-calling
     * (tool-use). Les adapters sans support retombent sur stream() via
     * ToolUseUnsupportedTrait.
     */
    public function supportsToolUse(): bool;

    /**
     * Streame la réponse en autorisant le modèle à appeler des outils.
     * L'adapter possède la boucle de round-trips : à chaque tool call complet,
     * il invoque $onToolCall($name, $args) et réinjecte le résultat au modèle,
     * jusqu'à la réponse texte finale.
     *
     * $webSearch autorise EN PLUS la recherche web native, quand le provider
     * sait faire cohabiter son outil serveur avec des outils applicatifs.
     * Sans ce paramètre, activer le moindre outil (image, Drive, Gmail,
     * Agenda) faisait de l'option Internet un no-op silencieux : tout le
     * message basculait dans cette boucle, qui ne la portait pas.
     *
     * $options règle la conduite de la boucle ; un adapter ignore ce qu'il ne
     * sait pas faire (aucune clé n'est obligatoire) :
     *   - 'cache'     => 'eager' | 'lazy' | 'off' : cache de prompt entre les
     *                    allers-retours d'une même réponse. `eager` écrit dès
     *                    le premier (des connecteurs sont engagés : plusieurs
     *                    allers-retours sont probables), `lazy` à partir du
     *                    second (défaut : une réponse directe ne paie pas
     *                    l'écriture), `off` jamais ;
     *   - 'cache_key' => string : clé de routage du cache chez les fournisseurs
     *                    qui en prennent une (OpenAI `prompt_cache_key`).
     *
     * @param array    $tools       [['name'=>,'description'=>,'parameters'=>[json schema]], ...]
     * @param callable $onChunk     fn(string $textChunk): void
     * @param callable $onToolCall  fn(string $name, array $args): array  (résultat renvoyé au modèle)
     * @param array<string, mixed> $options cf. ci-dessus
     * @return array{input:int,output:int} tokens sommés sur tous les round-trips
     */
    public function streamWithTools(
        array $messages,
        string $systemPrompt,
        array $tools,
        callable $onChunk,
        callable $onToolCall,
        ?float $temperature = null,
        bool $webSearch = false,
        array $options = []
    ): array;
}
