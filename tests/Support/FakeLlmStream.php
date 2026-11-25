<?php

declare(strict_types=1);

/**
 * Faux point d'accès du modèle : chaque ouverture rend la réponse suivante de
 * la file (format Chat Completions en flux) et garde le corps de la requête.
 * Le vrai adaptateur OpenAI l'ouvre par fopen(), comme une adresse https.
 */
final class FakeLlmStream
{
    public const SCHEME = 'miafauxmodele';

    /**
     * Branche le faux point d'accès sur le fournisseur OpenAI du catalogue (le
     * cache de LlmCatalog est réécrit : LlmCatalog::flushCache() le rend) et
     * vide la file et le relevé des requêtes.
     */
    public static function install(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$queue    = [];
        self::$requests = [];
        $cache     = new ReflectionProperty(LlmCatalog::class, 'cache');
        $catalogue = $cache->getValue() ?? require ROOT . '/config/llm_catalog.php';
        $catalogue['openai']['endpoints']['chat_completions'] = self::SCHEME . '://openai';
        $cache->setValue(null, $catalogue);
    }

    /** @var list<string> réponses à rendre, dans l'ordre des appels */
    public static array $queue = [];

    /** @var list<array> corps JSON des requêtes reçues, dans l'ordre */
    public static array $requests = [];

    /** @var resource|null posé par PHP */
    public $context;

    private string $data = '';
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $opts = is_resource($this->context) ? stream_context_get_options($this->context) : [];
        self::$requests[] = json_decode((string)($opts['http']['content'] ?? ''), true) ?: [];
        $this->data = self::$queue !== [] ? array_shift(self::$queue) : self::sse('Réponse par défaut.');
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr($this->data, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_close(): void
    {
    }

    public static function sse(string $text): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    /** Tout le texte envoyé au modèle par la requête n° $i (prompt système compris). */
    public static function promptOf(int $i): string
    {
        $parts = [];
        foreach ((array)(self::$requests[$i]['messages'] ?? []) as $message) {
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    $parts[] = (string)($part['text'] ?? '');
                }
            } else {
                $parts[] = (string)$content;
            }
        }
        return implode("\n", $parts);
    }
}
