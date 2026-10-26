<?php

declare(strict_types=1);

/**
 * Fallback de function-calling pour les adapters qui ne pilotent pas de
 * boucle tool-use (DeepSeek, Perplexity, Gemini, Local, Mistral). Ils
 * satisfont l'interface sans code dédié : streamWithTools() ignore les
 * outils et retombe sur un stream texte classique.
 *
 * Un adapter qui implémente réellement le tool-use (OpenAI, Anthropic)
 * peut utiliser ce trait puis redéfinir supportsToolUse() + streamWithTools()
 * directement dans la classe : les méthodes de classe priment sur le trait.
 */
trait ToolUseUnsupportedTrait
{
    public function supportsToolUse(): bool
    {
        return false;
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
        // Pas de function-calling : on ignore $tools/$onToolCall et on streame
        // normalement. Le caller doit instruire le modèle (system prompt) de
        // demander à l'utilisateur d'employer l'outil manuel. La recherche web,
        // elle, ne dépend pas du tool-use : on la transmet (l'éteindre ici
        // ferait perdre l'option Internet sans raison).
        return $this->stream($messages, $systemPrompt, $onChunk, $temperature, $webSearch);
    }
}
