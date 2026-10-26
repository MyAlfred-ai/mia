<?php

declare(strict_types=1);

class WebSearch
{
    /**
     * Recherche via l'API gratuite DuckDuckGo Instant Answer.
     * Retourne un bloc de texte à injecter dans le prompt système,
     * ou une chaîne vide si aucun résultat.
     */
    public static function search(string $query): string
    {
        $url = 'https://api.duckduckgo.com/?' . http_build_query([
            'q'             => $query,
            'format'        => 'json',
            'no_html'       => '1',
            'skip_disambig' => '1',
        ]);

        $ctx = stream_context_create(['http' => [
            'timeout'       => 6,
            'ignore_errors' => true,
            'header'        => "User-Agent: myLLM-bot/1.0\r\n",
        ]]);

        $body = @file_get_contents($url, false, $ctx);
        if (!$body) return '';

        $data = json_decode($body, true) ?? [];
        $parts = [];

        if (!empty($data['Answer'])) {
            $parts[] = '• ' . $data['Answer'];
        }

        if (!empty($data['AbstractText'])) {
            $text = $data['AbstractText'];
            if (!empty($data['AbstractURL'])) {
                $text .= ' [' . $data['AbstractURL'] . ']';
            }
            $parts[] = '• ' . $text;
        }

        foreach ($data['RelatedTopics'] ?? [] as $topic) {
            if (count($parts) >= 5) break;
            if (!isset($topic['Text']) || $topic['Text'] === '') continue;
            $text = $topic['Text'];
            if (!empty($topic['FirstURL'])) {
                $text .= ' [' . $topic['FirstURL'] . ']';
            }
            $parts[] = '• ' . $text;
        }

        if (empty($parts)) return '';

        return "Résultats de recherche web pour « {$query} » :\n" . implode("\n", $parts);
    }
}
