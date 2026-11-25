<?php
declare(strict_types=1);

/**
 * Dictée vocale — SOURCE UNIQUE du moteur de transcription.
 *
 * Deux moteurs, au choix de l'administrateur (carte « Dictée vocale » de
 * /admin/tools) :
 *
 *  - `openai` (défaut, historique) : session OpenAI Realtime en WebRTC
 *    DIRECT navigateur ↔ OpenAI (jeton éphémère, cf.
 *    ChatController::realtimeTranscriptionToken). L'audio ne passe pas par
 *    le serveur ; la durée est rapportée par le navigateur.
 *  - `mistral` (Voxtral) : transcription de FICHIERS, `POST
 *    /v1/audio/transcriptions`. Le navigateur découpe la dictée aux silences
 *    (une phrase = un court WAV 16 kHz mono) et l'envoie au SERVEUR, qui la
 *    relaie à Mistral avec la clé de l'instance — jamais exposée au
 *    navigateur — et facture la durée que MISTRAL a mesurée
 *    (`usage.prompt_audio_seconds`), pas une durée rapportée par le client.
 *
 *    Pourquoi pas la transcription temps réel de Voxtral : elle passe par un
 *    WebSocket et un jeton client (`rt_…`) documenté dans une section
 *    marquée « deprecated » de la doc Mistral, sur des modèles en aperçu
 *    (relu le 2026-10-28). Le mode fichier est documenté, stable, et la
 *    découpe aux silences donne une phrase transcrite à chaque pause.
 *
 * Le moteur n'est retenu que s'il est UTILISABLE : Voxtral choisi mais clé
 * Mistral retirée depuis → retour sur OpenAI si sa clé est là, sinon aucun
 * micro. Le choix de Voxtral, lui, n'est enregistré qu'après un essai RÉEL
 * de la clé (une seconde de silence transcrite, quelques millièmes de
 * centime) : « la clé est renseignée » ne dit pas qu'elle marche.
 *
 * La conversation vocale, elle, ÉCOUTE toujours par OpenAI (Realtime) : ce
 * réglage ne la change pas — sans clé OpenAI, elle ne peut pas marcher et son
 * bouton le dit (SpeechSynthesizer::issueFrom). Sa LECTURE a son propre choix
 * de voix (SpeechSynthesizer::ENGINES : Realtime, Voxtral TTS 3…).
 */
final class Dictation
{
    /** Réglage d'instance : moteur choisi par l'administrateur. */
    public const SETTING_ENGINE = 'mic_engine';

    public const ENGINE_OPENAI  = 'openai';
    public const ENGINE_MISTRAL = 'mistral';

    public const ENGINES = [self::ENGINE_OPENAI, self::ENGINE_MISTRAL];

    public const MISTRAL_ENDPOINT = 'https://api.mistral.ai/v1/audio/transcriptions';

    /**
     * Modèles Voxtral essayés dans l'ordre. Voxtral Mini Transcribe 3 (aperçu
     * public depuis le 2026-10-20, recommandé par la doc) d'abord ; repli sur
     * Voxtral Mini Transcribe 2 (`voxtral-mini-latest`) si le premier est
     * refusé (« Invalid model ») — un aperçu peut ne pas être ouvert à tous
     * les comptes. Le repli est déprécié depuis le 31/10/2026 et retiré le
     * 30/04/2027 (page « Models » de Mistral, relue le 2026-11-10) : à ôter
     * d'ici là, ou dès que Transcribe 3 sort de l'aperçu. Tarifs dans
     * `config/llm_catalog.php` (provider mistral).
     */
    public const MISTRAL_MODELS = [
        'voxtral-mini-transcribe-3',
        'voxtral-mini-latest',
    ];

    /** Plus long morceau accepté par la route (≈ 60 s de WAV 16 kHz mono). */
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** Durée minimale d'un morceau : en dessous, rien à transcrire. */
    public const MIN_AUDIO_BYTES = 3200; // 0,1 s à 16 kHz, 16 bits

    // ===================== Choix du moteur =====================

    /** Moteur CHOISI (réglage), valeur connue ou défaut OpenAI. */
    public static function chosenEngine(): string
    {
        $value = (string)Settings::get(self::SETTING_ENGINE, self::ENGINE_OPENAI);
        return in_array($value, self::ENGINES, true) ? $value : self::ENGINE_OPENAI;
    }

    /**
     * Moteur EN VIGUEUR, d'après le choix et les clés présentes. Pure.
     * Voxtral choisi sans clé Mistral → OpenAI si possible ; OpenAI choisi
     * sans clé OpenAI → aucun (Voxtral n'est jamais pris sans avoir été
     * choisi — et donc essayé — par l'administrateur).
     */
    public static function resolveEngine(string $chosen, bool $hasOpenAi, bool $hasMistral): ?string
    {
        if ($chosen === self::ENGINE_MISTRAL && $hasMistral) {
            return self::ENGINE_MISTRAL;
        }
        return $hasOpenAi ? self::ENGINE_OPENAI : null;
    }

    /** Moteur en vigueur sur l'instance (lit le réglage et les clés). */
    public static function engine(): ?string
    {
        return self::resolveEngine(
            self::chosenEngine(),
            !empty(ApiKey::getDecrypted('openai')),
            !empty(ApiKey::getDecrypted('mistral'))
        );
    }

    /** Libellé court d'un moteur (badge de la carte d'administration). */
    public static function engineLabel(?string $engine): string
    {
        return $engine === self::ENGINE_MISTRAL ? 'Mistral · Voxtral' : 'OpenAI · Realtime';
    }

    // ===================== Transcription Voxtral =====================

    /**
     * Langue envoyée à Voxtral : code ISO 639-1 de l'interface, sinon rien
     * (le modèle détecte). Pure.
     */
    public static function languageFor(string $locale): ?string
    {
        $code = strtolower(substr(trim($locale), 0, 2));
        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Le refus vise-t-il le MODÈLE (« Invalid model », modèle inconnu) ? Le
     * suivant de MISTRAL_MODELS est alors essayé. Pure.
     */
    public static function isModelRefusal(int $status, string $body): bool
    {
        if (!in_array($status, [400, 404, 422], true)) {
            return false;
        }
        return preg_match('/invalid model|model[^"]{0,40}(not found|does not exist|unknown|not available)|unknown model|no such model/i', $body) === 1;
    }

    /**
     * Texte transcrit lu dans la réponse de Mistral, et durée audio MESURÉE
     * par Mistral. Pure ; null si la réponse n'a pas la forme attendue.
     *
     * @return array{text:string, seconds:float, model:string}|null
     */
    public static function parseResponse(string $body, string $model): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !array_key_exists('text', $json)) {
            return null;
        }
        $seconds = $json['usage']['prompt_audio_seconds'] ?? null;
        return [
            'text'    => trim((string)$json['text']),
            'seconds' => is_numeric($seconds) ? max(0.0, (float)$seconds) : 0.0,
            'model'   => (string)($json['model'] ?? $model) ?: $model,
        ];
    }

    /**
     * Transcrit un morceau d'audio avec Voxtral. Lève une
     * SpeechSynthesisException (charge utile présentée : crédit épuisé, clé
     * refusée…) — le corps brut du fournisseur ne part qu'aux journaux.
     *
     * @param callable|null $transport fn(string $url, array $headers, array $fields): array{status:int, body:string}
     *                                 — substitut des tests ; par défaut cURL multipart.
     * @return array{text:string, seconds:float, model:string}
     */
    public static function transcribeMistral(string $audio, string $apiKey, ?string $language, string $fileName = 'dictee.wav', ?callable $transport = null): array
    {
        if ($apiKey === '') {
            throw new SpeechSynthesisException(LlmErrorPresenter::present(['kind' => 'missing_key', 'provider' => 'mistral', 'status' => null]));
        }
        $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
        $cause   = null;

        foreach (self::MISTRAL_MODELS as $model) {
            $fields = ['model' => $model, 'file' => ['name' => $fileName, 'content' => $audio]];
            if ($language !== null) {
                $fields['language'] = $language;
            }
            $response = $transport !== null
                ? $transport(self::MISTRAL_ENDPOINT, $headers, $fields)
                : self::curlMultipart(self::MISTRAL_ENDPOINT, $headers, $fields);
            $status = (int)($response['status'] ?? 0);
            $body   = (string)($response['body'] ?? '');

            if ($status === 0) {
                throw new SpeechSynthesisException(LlmErrorPresenter::present(['kind' => 'network', 'provider' => 'mistral', 'status' => null]));
            }
            if ($status === 200) {
                $parsed = self::parseResponse($body, $model);
                if ($parsed !== null) {
                    return $parsed;
                }
                error_log('[dictée Voxtral] réponse illisible (' . $model . ') : ' . substr($body, 0, 300));
                throw new SpeechSynthesisException(LlmErrorPresenter::present(['kind' => 'generic', 'provider' => 'mistral', 'status' => 200]));
            }

            error_log('[dictée Voxtral] ' . $model . ' -> HTTP ' . $status . ' : ' . substr($body, 0, 500));
            $cause = LlmErrorPresenter::fromHttp($status, $body, 'mistral');
            if (!self::isModelRefusal($status, $body)) {
                break; // crédit, clé, panne : le modèle suivant n'y changerait rien
            }
        }

        $cause ??= LlmErrorPresenter::present(['kind' => 'generic', 'provider' => 'mistral', 'status' => null]);
        ProviderIncident::note($cause);
        throw new SpeechSynthesisException($cause);
    }

    /**
     * Essai RÉEL de la clé Mistral avant de choisir Voxtral : une seconde de
     * silence transcrite (coût négligeable, non compté au grand livre —
     * comme les tests de clés de Modèles IA). Rend null si tout va bien,
     * sinon la cause présentée (titre + message).
     *
     * @return array{title:string, message:string}|null
     */
    public static function checkMistral(string $apiKey, ?callable $transport = null): ?array
    {
        try {
            self::transcribeMistral(self::silentWav(1.0), $apiKey, null, 'essai.wav', $transport);
            return null;
        } catch (SpeechSynthesisException $e) {
            $p = $e->payload();
            return ['title' => (string)($p['title'] ?? ''), 'message' => (string)($p['message'] ?? $e->getMessage())];
        }
    }

    /** WAV 16 kHz mono 16 bits de silence. Pure. */
    public static function silentWav(float $seconds): string
    {
        $rate    = 16000;
        $samples = max(1, (int)round($rate * $seconds));
        $data    = str_repeat("\0\0", $samples);
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }

    /**
     * L'audio reçu est-il un WAV PCM (ce que le navigateur fabrique) ? Garde
     * de la route : on ne relaie pas n'importe quel fichier. Pure.
     */
    public static function looksLikeWav(string $audio): bool
    {
        return strlen($audio) >= 44 && str_starts_with($audio, 'RIFF') && substr($audio, 8, 4) === 'WAVE';
    }

    /** @return array{status:int, body:string} */
    private static function curlMultipart(string $url, array $headers, array $fields): array
    {
        $post = [];
        foreach ($fields as $name => $value) {
            $post[$name] = is_array($value)
                ? new \CURLStringFile((string)$value['content'], (string)$value['name'], 'audio/wav')
                : (string)$value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            error_log('[dictée Voxtral] cURL : ' . curl_error($ch));
            return ['status' => 0, 'body' => ''];
        }
        return ['status' => $status, 'body' => (string)$body];
    }
}
