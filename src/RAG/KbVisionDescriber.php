<?php

declare(strict_types=1);

/**
 * Description d'images par LLM vision, à l'ingestion d'une base de
 * connaissances : l'image devient un texte descriptif, chunké et vectorisé
 * comme n'importe quel document — donc cherchable par le RAG.
 *
 * Choix du LLM : le défaut de l'app s'il est vision-capable, sinon le
 * premier provider à clé configurée dont le modèle par défaut porte le tag
 * 'vision' au catalogue. Aucun candidat → RuntimeException (le document
 * passe en extraction failed avec un message clair).
 */
class KbVisionDescriber
{
    /** Ordre de préférence si le LLM par défaut de l'app n'est pas vision. */
    private const PROVIDER_ORDER = ['openai', 'anthropic', 'gemini', 'mistral'];

    private const PROMPT = 'Décris précisément et factuellement cette image pour une base documentaire : '
        . 'sujet principal, textes lisibles (retranscris-les intégralement), données chiffrées, '
        . 'schémas ou diagrammes (explique leur logique), personnes, lieux, objets notables. '
        . 'Sois exhaustif mais sans interprétation spéculative. Réponds en français.';

    /**
     * `$usage` (par référence, facultatif) reçoit la consommation de l'appel
     * vision — `['llm' => 'provider::model', 'tokens' => [...]]` — dès que
     * l'appel a eu lieu, y compris en échec (les tokens ont été payés).
     * L'appelant journalise via `UsageMeter`.
     *
     * @throws RuntimeException aucun LLM vision disponible, ou échec API
     */
    public static function describe(string $path, string $mime, string $fileName, ?array &$usage = null): string
    {
        $usage = null;
        $llm = self::resolveVisionLlm();
        if ($llm === null) {
            throw new \RuntimeException(
                'Aucun LLM vision disponible pour décrire les images. '
                . 'Configurez une clé API OpenAI, Anthropic, Gemini ou Mistral.'
            );
        }

        $binary = @file_get_contents($path);
        if (!is_string($binary) || $binary === '') {
            throw new \RuntimeException('Image illisible sur le stockage.');
        }

        // Même forme de message multimodal que le chat (cf. injectProfileVision) :
        // les adapters vision consomment ce format tel quel.
        $messages = [[
            'role'    => 'user',
            'content' => [
                [
                    'type' => 'image',
                    'mime' => $mime,
                    'data' => base64_encode($binary),
                    'name' => $fileName,
                    'path' => $path,
                ],
                ['type' => 'text', 'text' => self::PROMPT],
            ],
        ]];

        $adapter = LLMFactory::make($llm);
        $text    = '';
        $first   = null;
        $tokens  = $adapter->stream(
            $messages,
            'Tu décris des images pour une base documentaire.',
            function (string $chunk) use (&$text, &$first): void {
                if ($first === null) {
                    $first = $chunk;
                }
                $text .= $chunk;
            }
        );
        $usage = ['llm' => $llm, 'tokens' => is_array($tokens) ? $tokens : []];

        // Les adapters n'émettent pas d'exception : une erreur API arrive en
        // chunk texte ([Erreur ... ]). On la détecte pour ne pas stocker un
        // message d'erreur comme « description » de l'image.
        if ($first !== null && LlmErrorPresenter::parse($first) !== null) {
            $err = LlmErrorPresenter::present(LlmErrorPresenter::parse($first));
            throw new \RuntimeException('Description vision impossible : ' . ($err['message'] ?? 'erreur LLM.'));
        }

        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Le LLM vision n\'a renvoyé aucune description.');
        }
        return $text;
    }

    /**
     * Slug "provider::model" du LLM vision à utiliser, ou null si aucun.
     */
    public static function resolveVisionLlm(): ?string
    {
        // 1) LLM par défaut de l'app, s'il est vision-capable.
        $default = trim((string)Settings::get('default_llm', ''));
        if ($default !== '' && str_contains($default, '::')) {
            [$provider, $model] = explode('::', $default, 2);
            $tags = LlmCatalog::model($provider, $model)['tags'] ?? [];
            if (in_array('vision', $tags, true) && ApiKey::getDecrypted($provider)) {
                return $default;
            }
        }

        // 2) Premier provider à clé dont un modèle vision existe au catalogue
        //    (modèle configuré en api_keys prioritaire, sinon défaut catalogue).
        foreach (self::PROVIDER_ORDER as $provider) {
            if (!ApiKey::getDecrypted($provider)) {
                continue;
            }
            foreach (array_filter([ApiKey::getModel($provider), LlmCatalog::defaultModel($provider)]) as $model) {
                $tags = LlmCatalog::model($provider, (string)$model)['tags'] ?? [];
                if (in_array('vision', $tags, true)) {
                    return $provider . '::' . $model;
                }
            }
        }
        return null;
    }
}
