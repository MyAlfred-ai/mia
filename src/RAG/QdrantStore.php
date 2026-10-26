<?php

declare(strict_types=1);

/**
 * Client REST du Qdrant EMBARQUÉ (service docker-compose interne, cf.
 * constante QDRANT_URL). Porte les vecteurs des bases de connaissances :
 * une collection par base (kb_{id}), point id = id MySQL du chunk, payload
 * minimal {kb_id, document_id, chunk_index} — le CONTENU reste en MySQL.
 *
 * Toute erreur (réseau, HTTP ≥ 400) THROW une RuntimeException : les
 * appelants basculent en fallback lexical (chat) ou passent le document
 * en vector_status=failed (ingestion). L'app ne dépend jamais durement
 * de Qdrant.
 */
class QdrantStore
{
    /** Taille des lots d'upsert (points par requête). */
    private const UPSERT_BATCH = 100;

    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? QDRANT_URL, '/');
    }

    /** Disponibilité du service (GET /readyz, timeout court). */
    public function healthCheck(): bool
    {
        try {
            $this->request('GET', '/readyz', null, 3, false);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Crée la collection si absente (idempotent). Distance Cosine — Qdrant
     * normalise les vecteurs à l'insertion et à la requête. Pose aussi un
     * index payload sur document_id (delete ciblé sans scan complet).
     */
    public function ensureCollection(string $name, int $dims): void
    {
        try {
            // Sonde d'existence : le 404 « collection absente » est le cas
            // NOMINAL avant création — on ne le logge pas (sinon chaque création
            // de base / 1re vectorisation / réindex crache un faux « HTTP 404 »).
            // Une vraie panne (5xx) reste visible sur le PUT de création qui suit.
            $this->request('GET', '/collections/' . rawurlencode($name), null, 10, true, false);
            return; // existe déjà
        } catch (\RuntimeException) {
            // 404 attendu → création.
        }

        $this->request('PUT', '/collections/' . rawurlencode($name), [
            'vectors' => ['size' => $dims, 'distance' => 'Cosine'],
        ]);
        $this->request('PUT', '/collections/' . rawurlencode($name) . '/index', [
            'field_name'   => 'document_id',
            'field_schema' => 'integer',
        ]);
    }

    /** Supprime la collection (tolère l'absence — 404 non loggé). */
    public function dropCollection(string $name): void
    {
        try {
            $this->request('DELETE', '/collections/' . rawurlencode($name), null, 10, true, false);
        } catch (\RuntimeException) {
            // Collection déjà absente : rien à faire.
        }
    }

    /**
     * Upsert de points par lots, avec wait=true (cohérence immédiate : le
     * polling UI et les tests lisent le count juste après).
     *
     * @param list<array{id:int,vector:list<float>,payload:array}> $points
     */
    public function upsertPoints(string $collection, array $points): void
    {
        foreach (array_chunk($points, self::UPSERT_BATCH) as $batch) {
            $this->request(
                'PUT',
                '/collections/' . rawurlencode($collection) . '/points?wait=true',
                ['points' => $batch],
                30
            );
        }
    }

    /**
     * Recherche vectorielle : ids + scores triés par similarité décroissante.
     * Le contenu des chunks est relu ensuite en MySQL (chunksByIds).
     *
     * @param  list<float> $vector
     * @return list<array{id:int,score:float}>
     */
    public function search(string $collection, array $vector, int $topK = 12): array
    {
        $data = $this->request(
            'POST',
            '/collections/' . rawurlencode($collection) . '/points/search',
            ['vector' => $vector, 'limit' => $topK],
            10
        );
        $out = [];
        foreach (($data['result'] ?? []) as $p) {
            $out[] = ['id' => (int)($p['id'] ?? 0), 'score' => (float)($p['score'] ?? 0.0)];
        }
        return $out;
    }

    /** Purge les points d'un document (filter payload, wait=true). */
    public function deletePointsByDocument(string $collection, int $documentId): void
    {
        $this->request(
            'POST',
            '/collections/' . rawurlencode($collection) . '/points/delete?wait=true',
            ['filter' => ['must' => [['key' => 'document_id', 'match' => ['value' => $documentId]]]]],
            30
        );
    }

    /**
     * Version du service (GET / → {title, version}) ; null si injoignable ou
     * si la racine ne répond pas au format attendu. Purement informatif :
     * n'échoue jamais (panneau de santé admin).
     */
    public function version(): ?string
    {
        try {
            $data = $this->request('GET', '/', null, 3, true, false);
            $v    = (string)($data['version'] ?? '');
            return $v !== '' ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Noms des collections existantes. Throw si Qdrant est injoignable —
     * l'appelant distingue « aucune collection » de « service en panne ».
     *
     * @return list<string>
     */
    public function listCollections(): array
    {
        $data = $this->request('GET', '/collections', null, 5);
        $out  = [];
        foreach (($data['result']['collections'] ?? []) as $c) {
            $name = (string)($c['name'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * État d'une collection : nombre de points et dimension du vecteur.
     * null si la collection n'existe pas (404 attendu, non loggé).
     *
     * @return array{points:int, dims:int, status:string}|null
     */
    public function collectionInfo(string $name): ?array
    {
        try {
            $data = $this->request('GET', '/collections/' . rawurlencode($name), null, 5, true, false);
        } catch (\RuntimeException) {
            return null;
        }
        $r = $data['result'] ?? [];
        return [
            'points' => (int)($r['points_count'] ?? 0),
            'dims'   => (int)($r['config']['params']['vectors']['size'] ?? 0),
            'status' => (string)($r['status'] ?? 'unknown'),
        ];
    }

    /** URL du service (affichage admin — ne contient aucun secret). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Parmi ces points (ids de chunks), ceux que la collection porte —
     * section « Avancé » d'une base : chaque extrait affiché dit s'il a son
     * vecteur. Lecture seule, sans vecteur ni payload. Une collection absente
     * n'a aucun point ([]) ; toute autre panne remonte (l'écran dit alors
     * « vérification impossible »).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function existingPointIds(string $collection, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            $data = $this->request(
                'POST',
                '/collections/' . rawurlencode($collection) . '/points',
                ['ids' => $ids, 'with_payload' => false, 'with_vector' => false],
                5,
                true,
                false
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '(HTTP 404)')) {
                return [];
            }
            throw $e;
        }
        $found = [];
        foreach (($data['result'] ?? []) as $point) {
            $id = (int)($point['id'] ?? 0);
            if ($id > 0) {
                $found[] = $id;
            }
        }
        return $found;
    }

    /** Nombre exact de points d'une collection (vérifications admin/tests). */
    public function countPoints(string $collection): int
    {
        $data = $this->request(
            'POST',
            '/collections/' . rawurlencode($collection) . '/points/count',
            ['exact' => true]
        );
        return (int)($data['result']['count'] ?? 0);
    }

    /**
     * Estimation de l'empreinte disque de l'index vectoriel — SECOURS quand
     * le volume qdrant_data n'est pas monté côté PHP (cf. StorageInfo, qui
     * mesure alors le répertoire réel). Par collection :
     *   - WAL : segments de 32 Mo préalloués dès la première écriture
     *     (wal_capacity_mb par défaut) — de loin le premier poste sur une
     *     petite base, c'est lui qui rendait l'ancienne estimation dérisoire ;
     *   - points × (vecteur float32 ×1,5 pour le graphe HNSW et les copies
     *     de segment, + surcoût id/version/payload RocksDB).
     * Ordre de grandeur assumé. Throw si Qdrant est injoignable (l'appelant
     * affiche « indisponible »).
     */
    private const EST_WAL_PER_COLLECTION = 32 * 1024 * 1024;
    private const EST_POINT_OVERHEAD     = 512;

    public function estimatedStorageBytes(): int
    {
        $list  = $this->request('GET', '/collections');
        $total = 0;
        foreach (($list['result']['collections'] ?? []) as $collection) {
            $name = (string)($collection['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $info   = $this->request('GET', '/collections/' . rawurlencode($name));
            $points = (int)($info['result']['points_count'] ?? 0);
            $dims   = (int)($info['result']['config']['params']['vectors']['size'] ?? 0);
            $total += self::EST_WAL_PER_COLLECTION
                + $points * ((int)round($dims * 4 * 1.5) + self::EST_POINT_OVERHEAD);
        }
        return $total;
    }

    // ===================== HTTP =====================

    /**
     * Requête HTTP JSON vers Qdrant. Erreur réseau ou statut ≥ 400 →
     * RuntimeException avec message court (le détail est loggé, sauf si
     * $logErrors est faux — sondes d'existence dont le 404 est attendu).
     */
    private function request(string $method, string $path, ?array $body = null, int $timeout = 10, bool $decode = true, bool $logErrors = true): array
    {
        $http = [
            'method'        => $method,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $http['header']  = 'Content-Type: application/json';
            $http['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $response = @file_get_contents($this->baseUrl . $path, false, stream_context_create(['http' => $http]));
        $status   = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }

        if ($response === false) {
            throw new \RuntimeException('Qdrant injoignable (' . $this->baseUrl . ').');
        }
        if ($status >= 400) {
            if ($logErrors) {
                error_log("[KB] Qdrant $method $path HTTP $status : " . mb_substr($response, 0, 300));
            }
            throw new \RuntimeException("Erreur Qdrant (HTTP $status) sur $method $path.");
        }
        if (!$decode) {
            return [];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }
}
