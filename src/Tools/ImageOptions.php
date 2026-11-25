<?php

declare(strict_types=1);

/**
 * Options d'une image générée — fond transparent, format de fichier,
 * orientation (carré, paysage, portrait). SOURCE UNIQUE de ce qui se règle,
 * de ce que chaque modèle accepte et de la façon dont trois niveaux se
 * combinent : l'OUTIL (sa fiche, Capacités → Outils), l'ASSISTANT qui s'en
 * sert (sa fiche, Capacités — génération d'image) et la brique Création d'un
 * WORKFLOW (ou l'étape qui remet une image). Le niveau le plus précis gagne,
 * une valeur vide laisse décider le niveau au-dessous.
 *
 * Les capacités viennent du catalogue (`config/tools_catalog.php` :
 * `backgrounds`, `transparent_preview`, `output_formats`, `sizes`,
 * `aspect_ratios`) : un écran ne propose que ce que l'API du modèle accepte,
 * et ImageGenerator n'envoie que cela (resolve). Tout est pur, sauf la
 * lecture du catalogue.
 */
final class ImageOptions
{
    /** Fond : '' = celui du modèle (opaque d'ordinaire), 'transparent'. */
    public const BACKGROUNDS = ['', 'transparent'];

    /** Formats de fichier connus ; '' = celui du modèle (PNG chez OpenAI). */
    public const OUTPUT_FORMATS = ['png', 'jpeg', 'webp'];

    /** Formats qui gardent la transparence (référence OpenAI : png ou webp). */
    public const TRANSPARENT_FORMATS = ['png', 'webp'];

    /** Orientations ; '' = non spécifiée. */
    public const FORMATS = ['square', 'landscape', 'portrait'];

    /** Rapport largeur:hauteur d'une orientation (Gemini, Imagen). */
    public const FORMAT_RATIOS = ['square' => '1:1', 'landscape' => '16:9', 'portrait' => '9:16'];

    /** Clés écrites dans le `config_json` d'un outil image. */
    public const KEYS = ['background', 'output_format', 'format'];

    /**
     * Ce que le modèle accepte. `formats` = orientations atteignables avec ses
     * tailles (OpenAI) ou ses rapports (Gemini) ; un modèle qui n'a rien à
     * régler rend trois listes vides — l'écran n'affiche alors rien.
     *
     * @return array{transparent: bool, transparent_preview: bool, output_formats: list<string>, formats: list<string>}
     */
    public static function capabilities(string $provider, string $model): array
    {
        $info = ToolCatalog::model('image', $provider, $model) ?? [];
        $transparent = in_array('transparent', ToolCatalog::modelOptions('image', $provider, $model, 'backgrounds'), true);
        $outputs = array_values(array_intersect(
            self::OUTPUT_FORMATS,
            ToolCatalog::modelOptions('image', $provider, $model, 'output_formats')
        ));

        $formats = [];
        if ($provider === 'openai') {
            $sizes = self::openAiSizes($model);
            foreach (self::FORMATS as $f) {
                if (ImageGenerator::sizeFor($sizes, $f) !== null) {
                    $formats[] = $f;
                }
            }
        } else {
            $ratios = ToolCatalog::modelOptions('image', $provider, $model, 'aspect_ratios');
            foreach (self::FORMATS as $f) {
                if (in_array(self::FORMAT_RATIOS[$f], $ratios, true)) {
                    $formats[] = $f;
                }
            }
        }

        return [
            'transparent'         => $transparent,
            'transparent_preview' => $transparent && !empty($info['transparent_preview']),
            'output_formats'      => $outputs,
            'formats'             => $formats,
        ];
    }

    /** Capacités d'un outil (ligne chat_tools) ; un outil qui n'est pas une image n'a rien. */
    public static function capabilitiesOf(array $tool): array
    {
        if (($tool['type'] ?? 'image') !== 'image') {
            return ['transparent' => false, 'transparent_preview' => false, 'output_formats' => [], 'formats' => []];
        }
        return self::capabilities((string)($tool['provider'] ?? ''), (string)($tool['model'] ?? ''));
    }

    /** Le modèle a-t-il au moins une option à régler ? */
    public static function hasAny(array $caps): bool
    {
        return !empty($caps['transparent']) || ($caps['output_formats'] ?? []) !== [] || ($caps['formats'] ?? []) !== [];
    }

    /** Tailles qu'un modèle OpenAI accepte (Responses API ou API images). */
    public static function openAiSizes(string $model): array
    {
        if (ImageGenerator::usesResponsesImageTool($model)) {
            return ToolCatalog::options('image', 'openai', 'responses_sizes') ?: ['auto', '1024x1024', '1024x1536', '1536x1024'];
        }
        return ToolCatalog::modelOptions('image', 'openai', $model, 'sizes') ?: ['auto', '1024x1024', '1024x1536', '1536x1024'];
    }

    /**
     * Les trois options, valeurs inconnues ramenées à '' ; avec des
     * capacités, ce que le modèle n'accepte pas l'est aussi. Fond transparent
     * sans format qui le garde → PNG (le format qui s'enclenche par défaut),
     * WebP s'il est seul possible. Pure.
     *
     * @return array{background: string, output_format: string, format: string}
     */
    public static function normalize(array $raw, ?array $caps = null): array
    {
        $bg  = (string)($raw['background'] ?? '') === 'transparent' ? 'transparent' : '';
        $out = strtolower(trim((string)($raw['output_format'] ?? '')));
        $out = $out === 'jpg' ? 'jpeg' : $out;
        $out = in_array($out, self::OUTPUT_FORMATS, true) ? $out : '';
        $fmt = in_array((string)($raw['format'] ?? ''), self::FORMATS, true) ? (string)$raw['format'] : '';

        if ($caps !== null) {
            if ($bg !== '' && empty($caps['transparent'])) {
                $bg = '';
            }
            if ($out !== '' && !in_array($out, (array)($caps['output_formats'] ?? []), true)) {
                $out = '';
            }
            if ($fmt !== '' && !in_array($fmt, (array)($caps['formats'] ?? []), true)) {
                $fmt = '';
            }
        }
        if ($bg === 'transparent' && !in_array($out, self::TRANSPARENT_FORMATS, true)) {
            $possibles = $caps !== null ? (array)($caps['output_formats'] ?? []) : self::OUTPUT_FORMATS;
            $keeps = array_values(array_intersect(self::TRANSPARENT_FORMATS, $possibles));
            $out = $keeps[0] ?? '';
        }

        return ['background' => $bg, 'output_format' => $out, 'format' => $fmt];
    }

    /**
     * Combine des niveaux, du plus général au plus précis (outil, assistant,
     * brique) : une valeur non vide remplace celle d'avant. Un fond
     * transparent posé plus haut et un JPEG choisi plus bas se contredisent :
     * normalize() les départage ensuite (PNG). Pure.
     */
    public static function merge(array ...$layers): array
    {
        $out = ['background' => '', 'output_format' => '', 'format' => ''];
        foreach ($layers as $layer) {
            foreach (self::KEYS as $k) {
                $v = (string)($layer[$k] ?? '');
                if ($v !== '') {
                    $out[$k] = $v;
                }
            }
        }
        return self::normalize($out);
    }

    /** Les options enregistrées dans un outil (son `config_json`). Pure. */
    public static function ofTool(array $tool): array
    {
        $config = json_decode((string)($tool['config_json'] ?? ''), true);
        return self::normalize(is_array($config) ? $config : []);
    }

    /** Les options enregistrées sur un assistant (colonne `image_options`). Pure. */
    public static function ofProfile(?array $profile): array
    {
        $raw = json_decode((string)($profile['image_options'] ?? ''), true);
        return self::normalize(is_array($raw) ? $raw : []);
    }

    /** JSON à enregistrer sur un assistant ; null quand rien n'est réglé. Pure. */
    public static function encode(array $opts): ?string
    {
        $o = array_filter(self::normalize($opts), static fn($v) => $v !== '');
        return $o === [] ? null : (string)json_encode($o, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Les options lues d'un formulaire (préfixe des champs : `config_` pour
     * la fiche d'un outil, `image_` pour celle d'un assistant). Case du fond
     * absente = fond du modèle. Pure.
     */
    public static function fromPost(array $post, string $prefix, ?array $caps = null): array
    {
        return self::normalize([
            'background'    => !empty($post[$prefix . 'transparent']) ? 'transparent' : '',
            'output_format' => (string)($post[$prefix . 'output_format'] ?? ''),
            'format'        => (string)($post[$prefix . 'format'] ?? ''),
        ], $caps);
    }

    /**
     * L'outil avec des options par-dessus les siennes : les valeurs non
     * vides de `$opts` remplacent celles de l'outil, le reste de sa
     * configuration ne bouge pas. Sans rien à poser : l'outil tel quel. Pure.
     */
    public static function applyTo(array $tool, array $opts): array
    {
        $opts = array_filter(self::normalize($opts), static fn($v) => $v !== '');
        if ($opts === []) {
            return $tool;
        }
        $config = json_decode((string)($tool['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        foreach ($opts as $k => $v) {
            $config[$k] = $v;
        }
        $tool['config_json'] = (string)json_encode($config, JSON_UNESCAPED_UNICODE);
        return $tool;
    }

    /**
     * Configuration réellement envoyée au modèle : options ramenées à ce qu'il
     * accepte, orientation traduite en taille (OpenAI : la taille de l'outil
     * si elle a déjà cette orientation, sinon la plus petite qui l'a) ou en
     * rapport (Gemini). Appelée par ImageGenerator::generate. Pure (lit le
     * catalogue).
     */
    public static function resolve(string $provider, string $model, array $config): array
    {
        $caps = self::capabilities($provider, $model);
        $o = self::normalize($config, $caps);
        foreach (['background', 'output_format'] as $k) {
            if ($o[$k] !== '') {
                $config[$k] = $o[$k];
            } else {
                unset($config[$k]);
            }
        }
        unset($config['format']);
        return $o['format'] !== '' ? self::orient($provider, $model, $config, $o['format']) : $config;
    }

    /**
     * Une orientation traduite dans la configuration : taille (OpenAI) et
     * rapport largeur:hauteur pour les modèles qui le prennent. Une taille
     * déjà de la bonne orientation est gardée ; sinon la plus petite qui l'a
     * — la moins chère, une valeur hors liste serait refusée. Pure.
     */
    public static function orient(string $provider, string $model, array $config, string $format): array
    {
        if (!isset(self::FORMAT_RATIOS[$format])) {
            return $config;
        }
        if ($provider === 'openai') {
            $sizes = self::openAiSizes($model);
            $current = (string)($config['size'] ?? '');
            if (!in_array($current, $sizes, true) || ImageGenerator::sizeFor([$current], $format) === null) {
                $size = ImageGenerator::sizeFor($sizes, $format);
                if ($size !== null) {
                    $config['size'] = $size;
                }
            }
        }
        if (in_array(self::FORMAT_RATIOS[$format], ToolCatalog::modelOptions('image', $provider, $model, 'aspect_ratios'), true)) {
            $config['aspect_ratio'] = self::FORMAT_RATIOS[$format];
        }
        return $config;
    }

    /** Extension du fichier enregistré pour un format ('' = PNG). Pure. */
    public static function extension(string $outputFormat): string
    {
        return match ($outputFormat) {
            'jpeg'  => 'jpg',
            'webp'  => 'webp',
            default => 'png',
        };
    }

    /**
     * Capacités de tous les modèles image du catalogue, pour les écrans
     * (`[provider][model] => capacités`) : la fiche d'un outil change de
     * modèle sans recharger.
     *
     * @return array<string, array<string, array>>
     */
    public static function catalogCapabilities(): array
    {
        $out = [];
        foreach (array_keys(ToolCatalog::providers('image')) as $provider) {
            foreach (array_keys(ToolCatalog::models('image', (string)$provider)) as $model) {
                $out[(string)$provider][(string)$model] = self::capabilities((string)$provider, (string)$model);
            }
        }
        return $out;
    }

    /** Libellés des options, traduits (écrans). */
    public static function labels(): array
    {
        return [
            'output_formats' => [
                ''     => t('Celui du modèle (PNG)'),
                'png'  => 'PNG',
                'jpeg' => 'JPEG',
                'webp' => 'WebP',
            ],
            'formats' => [
                ''          => t('Non spécifié'),
                'square'    => t('Carré'),
                'landscape' => t('Paysage'),
                'portrait'  => t('Portrait'),
            ],
            'transparent'      => t('Fond transparent'),
            'transparent_hint' => t('Le sujet se détache sur un fond vide : logo, pictogramme, produit détouré. Le fichier passe en PNG (ou WebP), seuls formats qui gardent la transparence.'),
            'preview'          => t('en aperçu chez le fournisseur'),
            'output_format'    => t('Format du fichier'),
            'format'           => t('Format'),
        ];
    }

    /** Résumé court des options réglées (rail, schéma) ; '' sans rien. Pure, traduit. */
    public static function summary(array $opts): string
    {
        $o = self::normalize($opts);
        $labels = self::labels();
        $parts = [];
        if ($o['format'] !== '') {
            $parts[] = $labels['formats'][$o['format']];
        }
        if ($o['background'] === 'transparent') {
            $parts[] = $labels['transparent'];
        }
        if ($o['output_format'] !== '') {
            $parts[] = $labels['output_formats'][$o['output_format']];
        }
        return implode(' · ', $parts);
    }
}
