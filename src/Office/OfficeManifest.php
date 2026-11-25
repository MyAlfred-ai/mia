<?php

declare(strict_types=1);

/**
 * Manifeste de l'add-in « Claude » (Excel, Word, PowerPoint) réglé pour MIA —
 * le fichier que la DSI dépose UNE fois dans le Centre d'administration
 * Microsoft 365 (Paramètres → Applications intégrées → Charger des applications
 * personnalisées).
 *
 * Port en PHP de `build-manifest.mjs`, script du plugin d'installation
 * d'Anthropic (anthropics/financial-services, claude-for-msft-365-install,
 * licence Apache-2.0) : le manifeste canonique est lu sur pivot.claude.ai,
 * puis les réglages de l'organisation sont ajoutés à l'adresse du volet
 * (`SourceLocation` et la ressource `Taskpane.Url`), en paramètres de requête —
 * aucun secret ici (jeton Microsoft ou jeton saisi par la personne), donc rien
 * après `#`.
 *
 * Deux différences voulues avec le script :
 *   - `<Id>` est REMPLACÉ par un identifiant propre à l'instance, gardé
 *     (OfficeAddin::manifestId) : le modèle porte celui de l'add-in publié sur
 *     AppSource, et le Centre d'administration refuse un second dépôt du même ;
 *   - `<Version>` avance d'elle-même quand les réglages ou le modèle changent
 *     (OfficeAddin::manifestRevision) : le Centre ignore un dépôt à la même
 *     version, sans le dire.
 */
final class OfficeManifest
{
    public const TEMPLATE_URL = 'https://pivot.claude.ai/manifest.xml';

    /** Modèle gardé sur disque (le site d'Anthropic peut être injoignable un instant). */
    public const TEMPLATE_TTL_SEC = 86400;

    /** Emplacements d'adresse que lit Office (cf. URL_SLOTS du script d'Anthropic). */
    private const URL_SLOTS = [
        '/(<SourceLocation\s+DefaultValue=")([^"]+)(")/',
        '/(id="Taskpane\.Url"\s+DefaultValue=")([^"]+)(")/',
    ];

    /** Lecture HTTP de substitution (tests) : fn(string $url): ?string. */
    private static $fetcher = null;

    private static ?string $cacheDir = null;

    /**
     * Ajoute les paramètres à une adresse telle qu'écrite dans le XML (déjà
     * échappée, portant déjà `?m=…`). Pure.
     */
    public static function appendParams(string $xmlUrl, array $params): string
    {
        if (str_contains($xmlUrl, '#')) {
            throw new \RuntimeException('Adresse du modèle inattendue (fragment déjà présent).');
        }
        $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($qs === '') {
            return $xmlUrl;
        }
        return $xmlUrl . (str_contains($xmlUrl, '?') ? '&amp;' : '?') . str_replace('&', '&amp;', $qs);
    }

    /** Version du modèle (`1.0.0.12`), null si absente. Pure. */
    public static function templateVersion(string $xml): ?string
    {
        return preg_match('#<Version>\s*(\d+\.\d+\.\d+(?:\.\d+)?)\s*</Version>#', $xml, $m) === 1 ? $m[1] : null;
    }

    /**
     * Manifeste de l'instance à partir du modèle. Pure.
     *
     * @param array<string, string> $params
     */
    public static function build(string $template, array $params, string $id, int $revision, ?array $hosts = null): string
    {
        $xml = $template;
        if ($hosts !== null) {
            $xml = self::keepHosts($xml, $hosts);
        }
        foreach (self::URL_SLOTS as $slot) {
            if (preg_match($slot, $xml) !== 1) {
                throw new \RuntimeException('Le manifeste d’Anthropic a changé de forme : emplacement d’adresse introuvable.');
            }
            $xml = (string)preg_replace_callback(
                $slot . 'm',
                static fn(array $m): string => $m[1] . self::appendParams($m[2], $params) . $m[3],
                $xml
            );
        }
        $count = 0;
        $xml = (string)preg_replace('#<Id>[^<]*</Id>#', '<Id>' . $id . '</Id>', $xml, 1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('Le manifeste d’Anthropic a changé de forme : identifiant introuvable.');
        }
        $base = self::templateVersion($template) ?? '1.0.0';
        $parts = array_slice(explode('.', $base), 0, 3);
        $version = implode('.', $parts) . '.' . max(1, $revision);
        $xml = (string)preg_replace('#<Version>[^<]*</Version>#', '<Version>' . $version . '</Version>', $xml, 1);

        $prev = libxml_use_internal_errors(true);
        $ok = simplexml_load_string($xml) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new \RuntimeException('Le manifeste fabriqué n’est pas un XML valide.');
        }
        return $xml;
    }

    /**
     * Ne garde que les applications voulues (`Workbook`, `Document`,
     * `Presentation`) : leur ligne dans `<Hosts>` et leur bloc dans
     * `<VersionOverrides>`, avec le commentaire qui le précède. Pure.
     *
     * @param list<string> $hosts
     */
    public static function keepHosts(string $xml, array $hosts): string
    {
        $hosts = array_values(array_intersect(['Workbook', 'Document', 'Presentation'], $hosts));
        if ($hosts === []) {
            throw new \RuntimeException('Au moins une application doit recevoir l’add-in.');
        }
        foreach (['Workbook', 'Document', 'Presentation'] as $host) {
            if (in_array($host, $hosts, true)) {
                continue;
            }
            $xml = (string)preg_replace('#[ \t]*<Host\s+Name="' . $host . '"\s*/>[ \t]*\R?#', '', $xml);
            $xml = (string)preg_replace('#(?:[ \t]*<!--[^>]*-->[ \t]*\R)?[ \t]*<Host\s+xsi:type="' . $host . '">.*?</Host>[ \t]*\R?#s', '', $xml);
        }
        if (!str_contains($xml, '<Host Name="') ) {
            throw new \RuntimeException('Le manifeste d’Anthropic a changé de forme : applications introuvables.');
        }
        return $xml;
    }

    /**
     * Manifeste de la configuration enregistrée : rend `[xml, version]`.
     * @throws \RuntimeException message présentable
     */
    public static function current(): array
    {
        $template = self::template();
        $params   = OfficeAddin::currentManifestParams();
        $apps     = OfficeAddin::apps();
        $revision = OfficeAddin::manifestRevision($params + ['_template' => (string)self::templateVersion($template), '_apps' => implode(',', $apps)]);
        $xml = self::build($template, $params, OfficeAddin::manifestId(), $revision, OfficeAddin::hostsFor($apps));
        return [$xml, (string)self::templateVersion($xml)];
    }

    /** Modèle d'Anthropic (gardé un jour, repli sur la copie gardée). */
    public static function template(): string
    {
        $path = self::cachePath();
        $cached = @file_get_contents($path);
        $fresh = is_string($cached) && $cached !== '' && time() - (int)@filemtime($path) < self::TEMPLATE_TTL_SEC;
        if ($fresh) {
            return $cached;
        }
        $body = self::fetch(self::TEMPLATE_URL);
        if (is_string($body) && str_contains($body, '<OfficeApp') && self::templateVersion($body) !== null) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, $body);
            return $body;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        throw new \RuntimeException(t('Le modèle du manifeste est injoignable sur pivot.claude.ai. Réessayez dans un instant ; le serveur de MIA doit pouvoir joindre ce site.'));
    }

    private static function fetch(string $url): ?string
    {
        try {
            if (self::$fetcher !== null) {
                return (self::$fetcher)($url);
            }
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
            $status = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
            return $status === 200 && is_string($body) ? $body : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cachePath(): string
    {
        return rtrim(self::$cacheDir ?? (ROOT . '/storage/cache'), '/') . '/office-manifest-template.xml';
    }

    /** @param null|callable(string): ?string $fetcher */
    public static function overrideFetcher(?callable $fetcher): void
    {
        self::$fetcher = $fetcher;
    }

    public static function overrideCacheDir(?string $dir): void
    {
        self::$cacheDir = $dir;
    }
}
