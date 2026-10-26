<?php

declare(strict_types=1);

/**
 * Géocodage d'adresses via Nominatim (OpenStreetMap).
 * ---------------------------------------------------
 * Utilisé par l'outil « Carte / Itinéraire » pour résoudre les étapes
 * décrites par le LLM en coordonnées lat/lon.
 *
 * Politique d'usage Nominatim respectée :
 *  - User-Agent identifiant l'application (obligatoire)
 *  - 1 requête réseau par seconde maximum (throttle interne)
 *  - cache fichier persistant (storage/cache/geocode, volume storage_cache)
 *
 * Jamais d'exception : échec réseau ou adresse introuvable → null.
 */
class Geocoder
{
    private const ENDPOINT   = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT = 'myLLM-MIA/1.0 (outil carte itineraire)';
    private const TIMEOUT    = 8;      // secondes
    private const CACHE_TTL  = 2592000; // 30 jours

    /** Horodatage du dernier appel réseau (throttle 1 req/s). */
    private static float $lastRequestAt = 0.0;

    /**
     * Résout une adresse/lieu en coordonnées.
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    public static function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $cached = self::cacheGet($query);
        if ($cached !== null) {
            // Un échec mis en cache (tableau vide) évite de re-marteler Nominatim.
            return $cached === [] ? null : $cached;
        }

        $result = self::fetch($query);
        // On ne met en cache un échec que s'il s'agit d'un « introuvable » réel
        // (réponse HTTP valide sans résultat) — un échec réseau reste retentable.
        if ($result !== null) {
            self::cacheSet($query, $result['found'] ? $result['data'] : []);
            return $result['found'] ? $result['data'] : null;
        }

        return null;
    }

    /**
     * Appel réseau Nominatim.
     * @return array{found: bool, data: array}|null null = échec réseau/HTTP
     */
    private static function fetch(string $query): ?array
    {
        // Throttle : au plus une requête réseau par seconde (politique Nominatim).
        $elapsed = microtime(true) - self::$lastRequestAt;
        if ($elapsed < 1.0) {
            usleep((int)((1.0 - $elapsed) * 1_000_000));
        }
        self::$lastRequestAt = microtime(true);

        $url = self::ENDPOINT . '?' . http_build_query([
            'q'               => $query,
            'format'          => 'jsonv2',
            'limit'           => 1,
            'addressdetails'  => 0,
            'accept-language' => 'fr',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($body) || $status !== 200) {
            error_log('[Geocoder] Échec Nominatim (' . $status . ') pour: ' . mb_substr($query, 0, 120));
            return null;
        }

        $rows = json_decode($body, true);
        if (!is_array($rows)) {
            return null;
        }
        if (empty($rows[0]['lat']) || empty($rows[0]['lon'])) {
            return ['found' => false, 'data' => []];
        }

        return ['found' => true, 'data' => [
            'lat'          => (float)$rows[0]['lat'],
            'lon'          => (float)$rows[0]['lon'],
            'display_name' => (string)($rows[0]['display_name'] ?? $query),
        ]];
    }

    // ---------------------------------------------------------------
    // Cache fichier
    // ---------------------------------------------------------------

    private static function cacheDir(): string
    {
        $dir = ROOT . '/storage/cache/geocode';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function cachePath(string $query): string
    {
        return self::cacheDir() . '/' . md5(mb_strtolower($query)) . '.json';
    }

    private static function cacheGet(string $query): ?array
    {
        $path = self::cachePath($query);
        if (!is_file($path) || (time() - (int)@filemtime($path)) > self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function cacheSet(string $query, array $data): void
    {
        @file_put_contents(self::cachePath($query), json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
