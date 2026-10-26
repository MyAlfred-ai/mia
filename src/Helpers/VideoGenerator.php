<?php

declare(strict_types=1);

/**
 * Génération de vidéos via l'API Videos d'OpenAI (Sora).
 *
 * OpenAI arrête cette API le 24/09/2026, sans remplaçant : l'arrêt est déclaré
 * dans le catalogue (`retirement`), et generate() ne l'appelle plus à partir
 * de cette date.
 */
class VideoGenerator
{
    // Toutes les listes (modèles, durées, tailles, formats) sont désormais lues
    // depuis `config/tools_catalog.php` via ToolCatalog. Ces constantes ne
    // servent plus que de filet de sécurité si le catalogue ne couvre pas le
    // provider demandé.
    private const API_BASE = 'https://api.openai.com/v1';
    private const DEFAULT_SIZE = '720x1280';
    private const DEFAULT_SECONDS = '4';
    private const DEFAULT_FORMAT = 'mp4';
    private const POLL_INTERVAL_SECONDS = 8;
    private const MAX_WAIT_SECONDS = 360;

    public static function capabilitiesForTool(array $tool): array
    {
        $provider  = trim((string)($tool['provider'] ?? ''));
        $toolModel = trim((string)($tool['model'] ?? ''));

        $providerConfig = ToolCatalog::provider('video', $provider);
        if (!empty($providerConfig)) {
            $catalogModels = ToolCatalog::models('video', $provider);
            $modelOptions  = [];
            foreach ($catalogModels as $id => $info) {
                $modelOptions[] = [
                    'value' => (string)$id,
                    'label' => (string)($info['label'] ?? $id),
                ];
            }
            if ($toolModel !== '' && !isset($catalogModels[$toolModel])) {
                // Un admin a enregistré un modèle non encore ajouté au catalogue — on l'affiche quand même.
                $modelOptions[] = ['value' => $toolModel, 'label' => $toolModel];
            }

            $modelValues  = array_map(static fn(array $o): string => (string)($o['value'] ?? ''), $modelOptions);
            $defaultModel = $toolModel !== '' ? $toolModel : ToolCatalog::defaultModel('video', $provider);
            if (!in_array($defaultModel, $modelValues, true)) {
                $defaultModel = $modelValues[0] ?? '';
            }

            $secondsOptions = array_map(
                static fn(string $v): array => ['value' => $v, 'label' => $v . ' secondes'],
                $providerConfig['seconds_options'] ?? [self::DEFAULT_SECONDS]
            );
            $formatOptions = array_map(
                static fn(string $v): array => ['value' => $v, 'label' => strtoupper($v)],
                $providerConfig['format_options'] ?? [self::DEFAULT_FORMAT]
            );
            $sizeOptions = [];
            foreach ($providerConfig['size_options'] ?? [] as $v => $label) {
                $sizeOptions[] = ['value' => (string)$v, 'label' => (string)$label];
            }
            if (empty($sizeOptions)) {
                $sizeOptions[] = ['value' => self::DEFAULT_SIZE, 'label' => self::DEFAULT_SIZE];
            }

            $defaults = $providerConfig['defaults'] ?? [];
            return [
                'provider'              => $provider,
                'provider_label'        => (string)($providerConfig['label'] ?? self::providerLabel($provider)),
                'supports_image_input'  => (bool)($providerConfig['supports_image_input'] ?? false),
                'model_options'         => $modelOptions,
                'seconds_options'       => $secondsOptions,
                'size_options'          => $sizeOptions,
                'format_options'        => $formatOptions,
                'defaults' => [
                    'model'   => $defaultModel,
                    'seconds' => (string)($defaults['seconds'] ?? self::DEFAULT_SECONDS),
                    'size'    => (string)($defaults['size']    ?? self::DEFAULT_SIZE),
                    'format'  => (string)($defaults['format']  ?? self::DEFAULT_FORMAT),
                ],
            ];
        }

        $fallbackModel = $toolModel !== '' ? $toolModel : 'default';
        return [
            'provider' => $provider,
            'provider_label' => self::providerLabel($provider),
            'model_options' => [
                ['value' => $fallbackModel, 'label' => $fallbackModel],
            ],
            'seconds_options' => [
                ['value' => self::DEFAULT_SECONDS, 'label' => self::DEFAULT_SECONDS . ' secondes'],
            ],
            'size_options' => [
                ['value' => self::DEFAULT_SIZE, 'label' => self::DEFAULT_SIZE],
            ],
            'format_options' => [
                ['value' => self::DEFAULT_FORMAT, 'label' => strtoupper(self::DEFAULT_FORMAT)],
            ],
            'defaults' => [
                'model' => $fallbackModel,
                'seconds' => self::DEFAULT_SECONDS,
                'size' => self::DEFAULT_SIZE,
                'format' => self::DEFAULT_FORMAT,
            ],
        ];
    }

    public static function resolveOptions(array $tool, array $input = []): array
    {
        $caps = self::capabilitiesForTool($tool);
        $defaults = $caps['defaults'] ?? [];

        $allowedModels = self::optionValues($caps['model_options'] ?? []);
        $allowedSeconds = self::optionValues($caps['seconds_options'] ?? []);
        $allowedSizes = self::optionValues($caps['size_options'] ?? []);
        $allowedFormats = self::optionValues($caps['format_options'] ?? []);

        $defaultModel = (string)($defaults['model'] ?? trim((string)($tool['model'] ?? '')));
        $defaultSeconds = (string)($defaults['seconds'] ?? self::DEFAULT_SECONDS);
        $defaultSize = (string)($defaults['size'] ?? self::DEFAULT_SIZE);
        $defaultFormat = (string)($defaults['format'] ?? self::DEFAULT_FORMAT);

        return [
            'model' => self::normalizeByAllowed((string)($input['model'] ?? ''), $allowedModels, $defaultModel),
            'seconds' => self::normalizeByAllowed((string)($input['seconds'] ?? ''), $allowedSeconds, $defaultSeconds),
            'size' => self::normalizeByAllowed((string)($input['size'] ?? ''), $allowedSizes, $defaultSize),
            'format' => self::normalizeByAllowed((string)($input['format'] ?? ''), $allowedFormats, $defaultFormat),
        ];
    }

    public static function generate(array $tool, string $prompt, array $inputOptions = [], ?array $imageData = null): array
    {
        $provider = trim((string)($tool['provider'] ?? ''));
        $options = self::resolveOptions($tool, $inputOptions);
        $model = trim((string)($options['model'] ?? ''));
        $seconds = (string)($options['seconds'] ?? self::DEFAULT_SECONDS);
        $size = (string)($options['size'] ?? self::DEFAULT_SIZE);
        $format = (string)($options['format'] ?? self::DEFAULT_FORMAT);

        // Service arrêté par le fournisseur (catalogue, `retirement`) : aucun
        // appel. Il échouerait de toute façon, avec un message brut en anglais
        // qui ne dit pas quoi faire.
        $arret = ToolCatalog::retiredNotice('video', $provider, $model);
        if ($arret !== null) {
            return ['error' => $arret];
        }

        if ($provider !== 'openai') {
            return ['error' => "Fournisseur vidéo non pris en charge : {$provider}"];
        }
        if ($model === '') {
            return ['error' => 'Modèle vidéo manquant.'];
        }

        // Clé dédiée à l'outil prioritaire, sinon clé globale OpenAI.
        $apiKey = !empty($tool['api_key_enc'])
            ? Encryption::decrypt($tool['api_key_enc'])
            : ApiKey::getDecrypted('openai');

        if (!$apiKey) {
            return ['error' => 'Clé API OpenAI non configurée. Ajoutez-la dans Administration → Modèles IA.'];
        }

        $formFields = [
            'model'   => $model,
            'prompt'  => $prompt,
            'seconds' => $seconds,
            'size'    => $size,
        ];

        // Image en entrée si fournie
        if ($imageData !== null && !empty($imageData['path']) && is_readable($imageData['path'])) {
            $role = trim((string)($imageData['role'] ?? 'first_frame'));
            $paramName = ($role === 'last_frame') ? 'last_frame_image' : 'image';
            $formFields[$paramName] = curl_file_create(
                $imageData['path'],
                $imageData['type'] ?? 'image/jpeg',
                basename($imageData['path'])
            );
        }

        $videosUrl = ToolCatalog::endpoint('video', 'openai', 'videos') ?? (self::API_BASE . '/videos');
        $createRes = self::requestForm($videosUrl, $apiKey, $formFields);

        if (!empty($createRes['error'])) {
            return ['error' => trim((string)$createRes['error'])];
        }

        $videoId = (string)($createRes['data']['id'] ?? '');
        if ($videoId === '') {
            return ['error' => 'Réponse OpenAI invalide: identifiant vidéo absent.'];
        }

        $statusRes = self::pollUntilCompleted($apiKey, $videoId);
        if (!empty($statusRes['error'])) {
            return ['error' => $statusRes['error']];
        }

        $binRes = self::requestBinary('GET', self::API_BASE . '/videos/' . rawurlencode($videoId) . '/content', $apiKey);
        if (!empty($binRes['error'])) {
            return ['error' => $binRes['error']];
        }

        $filename = self::saveVideo($binRes['body'] ?? '');
        if ($filename === null) {
            return ['error' => "Impossible d'enregistrer la vidéo générée."];
        }

        $finalData = $statusRes['data'] ?? [];
        return [
            'ok'        => true,
            'video_url' => '/assets/video/generated/' . $filename,
            'video_id'  => $videoId,
            'model'     => (string)($finalData['model'] ?? $model),
            'size'      => (string)($finalData['size'] ?? $size),
            'seconds'   => (string)($finalData['seconds'] ?? $seconds),
            'format'    => $format,
        ];
    }

    private static function pollUntilCompleted(string $apiKey, string $videoId): array
    {
        $elapsed = 0;
        $url = self::API_BASE . '/videos/' . rawurlencode($videoId);

        while ($elapsed <= self::MAX_WAIT_SECONDS) {
            $res = self::requestJson('GET', $url, $apiKey);
            if (!empty($res['error'])) {
                return ['error' => $res['error']];
            }

            $data = $res['data'] ?? [];
            $status = (string)($data['status'] ?? '');

            if ($status === 'completed') {
                return ['ok' => true, 'data' => $data];
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                $err = (string)($data['error']['message'] ?? '');
                if ($err === '') {
                    $err = "La génération vidéo a échoué (statut: {$status}).";
                }
                return ['error' => $err];
            }

            sleep(self::POLL_INTERVAL_SECONDS);
            $elapsed += self::POLL_INTERVAL_SECONDS;
        }

        return ['error' => 'Le rendu vidéo prend trop de temps. Réessayez dans quelques instants.'];
    }

    private static function requestJson(string $method, string $url, string $apiKey, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'Extension cURL indisponible sur ce serveur.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => "Impossible d'initialiser la connexion API."];
        }

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            return ['error' => 'Erreur réseau OpenAI Videos: ' . ($err ?: 'connexion impossible')];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($status >= 400) {
            $apiMsg = (string)($data['error']['message'] ?? '');
            if ($apiMsg === '') {
                $apiMsg = "Erreur OpenAI Videos (HTTP {$status}).";
            }
            return ['error' => $apiMsg];
        }

        return ['ok' => true, 'data' => $data];
    }

    private static function requestForm(string $url, string $apiKey, array $fields): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'Extension cURL indisponible sur ce serveur.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => "Impossible d'initialiser la connexion API."];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            return ['error' => 'Erreur réseau OpenAI Videos: ' . ($err ?: 'connexion impossible')];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($status >= 400) {
            $apiMsg = (string)($data['error']['message'] ?? '');
            if ($apiMsg === '') {
                $apiMsg = "Erreur OpenAI Videos (HTTP {$status}).";
            }
            return ['error' => $apiMsg];
        }

        return ['ok' => true, 'data' => $data];
    }

    private static function requestBinary(string $method, string $url, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'Extension cURL indisponible sur ce serveur.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => "Impossible d'initialiser la connexion API."];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: video/mp4,application/octet-stream',
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            return ['error' => 'Erreur téléchargement vidéo: ' . ($err ?: 'connexion impossible')];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($status >= 400) {
            $errData = json_decode($raw, true);
            $apiMsg = (string)($errData['error']['message'] ?? '');
            if ($apiMsg === '') {
                $apiMsg = "Erreur OpenAI Videos (HTTP {$status}) lors du téléchargement.";
            }
            return ['error' => $apiMsg];
        }

        if (stripos($contentType, 'application/json') !== false) {
            $errData = json_decode($raw, true);
            $apiMsg = (string)($errData['error']['message'] ?? '');
            if ($apiMsg !== '') {
                return ['error' => $apiMsg];
            }
        }

        return ['ok' => true, 'body' => $raw];
    }

    private static function saveVideo(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $dir = ROOT . '/public/assets/video/generated';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        // 128 bits d'aléa (comme les images) : le nom est une capacité. 32 bits
        // + horodatage restaient brute-forçables par un interne connaissant la
        // fenêtre temporelle ; l'accès est désormais gardé (MediaController),
        // mais une capacité doit rester non devinable en défense en profondeur.
        $name = 'video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.mp4';
        $path = $dir . '/' . $name;

        if (file_put_contents($path, $bytes) === false) {
            return null;
        }
        return $name;
    }

    private static function optionValues(array $options): array
    {
        $values = [];
        foreach ($options as $opt) {
            $v = trim((string)($opt['value'] ?? ''));
            if ($v !== '') {
                $values[] = $v;
            }
        }
        return $values;
    }

    private static function normalizeByAllowed(string $value, array $allowed, string $fallback): string
    {
        $value = trim($value);
        if ($value !== '' && in_array($value, $allowed, true)) {
            return $value;
        }
        return $fallback;
    }

    private static function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'gemini' => 'Gemini',
            'anthropic' => 'Anthropic',
            'mistral' => 'Mistral',
            'deepseek' => 'DeepSeek',
            'moonshot' => 'Kimi (Moonshot)',
            'zai' => 'GLM (Z.ai)',
            'perplexity' => 'Perplexity',
            default => $provider !== '' ? $provider : 'Provider inconnu',
        };
    }
}
