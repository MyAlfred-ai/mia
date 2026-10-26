<?php

declare(strict_types=1);

/**
 * Documents d'une base de connaissances. Miroir de ProfileDocument avec une
 * phase supplémentaire : la vectorisation (embeddings → Qdrant), suivie par
 * vector_status / vector_error / chunk_count.
 *
 * Cycle : upload → extraction_status pending→done (texte ou description
 * vision pour les images) → chunks MySQL → vector_status pending→done
 * (embeddings + upsert Qdrant). Un échec vectoriel laisse les chunks MySQL
 * en place : la base reste interrogeable en fallback lexical (FULLTEXT).
 */
class KnowledgeBaseDocument
{
    /** Taille max d'un fichier uploadé manuellement (passe par PHP multipart,
     *  borné par upload_max_filesize/post_max_size). Partagé avec les documents
     *  de référence des assistants via AdminController. */
    public const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 Mo

    /** Taille max d'un fichier synchronisé depuis un dossier distant (Google
     *  Drive ou SharePoint) : téléchargé côté serveur (streamé en .part, hors
     *  limite d'upload PHP), d'où un plafond distinct et plus élevé que
     *  l'upload manuel. */
    public const MAX_SYNC_FILE_SIZE = 50 * 1024 * 1024; // 50 Mo

    /**
     * MIME acceptés → extension de stockage. Source unique, partagée entre
     * l'upload manuel (AdminController) et la synchronisation de dossier
     * distant (KbSync, qui tourne aussi en worker CLI sans les contrôleurs).
     */
    public const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'         => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'  => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/epub+zip' => 'epub',
        'text/html'     => 'html',
        'application/rtf' => 'rtf',
        'text/rtf'        => 'rtf',
        'text/plain'    => 'txt',
        'text/markdown' => 'md',
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public static function allForKb(int $kbId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, kb_id, file_name, stored_name, mime_type, file_size,
                    token_count, created_at, extraction_status, extraction_error,
                    vector_status, vector_error, chunk_count, source, sync_file_id,
                    CASE WHEN extracted_text IS NULL THEN 0 ELSE 1 END AS has_text
             FROM knowledge_base_documents
             WHERE kb_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$kbId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM knowledge_base_documents WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO knowledge_base_documents
                (kb_id, file_name, stored_name, mime_type, file_size, extraction_status, vector_status,
                 source, sync_file_id, sync_hash, sync_modified_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            (int)$data['kb_id'],
            (string)$data['file_name'],
            (string)$data['stored_name'],
            (string)$data['mime_type'],
            (int)($data['file_size'] ?? 0),
            (string)($data['extraction_status'] ?? 'pending'),
            (string)($data['vector_status'] ?? 'pending'),
            (string)($data['source'] ?? 'upload'),
            isset($data['sync_file_id']) && $data['sync_file_id'] !== null ? (string)$data['sync_file_id'] : null,
            isset($data['sync_hash']) && $data['sync_hash'] !== null ? (string)$data['sync_hash'] : null,
            isset($data['sync_modified_at']) && $data['sync_modified_at'] !== null ? (string)$data['sync_modified_at'] : null,
        ]);
        return (int)$db->lastInsertId();
    }

    // ============ Synchronisation d'un dossier distant (Drive / SharePoint) ============

    /**
     * Documents pilotés par la synchronisation distante d'une base, indexés par
     * sync_file_id (clé du diff). TOUS fournisseurs confondus (source <> 'upload') :
     * au changement de source distante, le premier cycle du nouveau fournisseur
     * retire ainsi naturellement les documents de l'ancien.
     *
     * @return array<string, array>
     */
    public static function syncedDocsForKb(int $kbId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, kb_id, file_name, stored_name, mime_type, file_size,
                    source, sync_file_id, sync_hash, sync_modified_at
             FROM knowledge_base_documents
             WHERE kb_id = ? AND source <> 'upload' AND sync_file_id IS NOT NULL"
        );
        $stmt->execute([$kbId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['sync_file_id']] = $row;
        }
        return $out;
    }

    /**
     * Nouvelle version d'un document synchronisé : remplace fichier stocké et
     * métadonnées, remet les statuts d'ingestion à zéro. L'ancien fichier
     * disque est supprimé par l'appelant APRÈS téléchargement réussi.
     */
    public static function updateSyncedVersion(int $id, array $data): void
    {
        Database::getInstance()->prepare(
            "UPDATE knowledge_base_documents
             SET file_name = ?, stored_name = ?, mime_type = ?, file_size = ?,
                 sync_hash = ?, sync_modified_at = ?,
                 extraction_status = 'pending', extraction_error = NULL,
                 vector_status = 'pending', vector_error = NULL
             WHERE id = ?"
        )->execute([
            (string)$data['file_name'],
            (string)$data['stored_name'],
            (string)$data['mime_type'],
            (int)($data['file_size'] ?? 0),
            isset($data['sync_hash']) && $data['sync_hash'] !== null ? (string)$data['sync_hash'] : null,
            isset($data['sync_modified_at']) && $data['sync_modified_at'] !== null ? (string)$data['sync_modified_at'] : null,
            $id,
        ]);
    }

    /** Renommage côté source distante sans changement de contenu : aucune réingestion. */
    public static function updateFileName(int $id, string $fileName): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_base_documents SET file_name = ? WHERE id = ?'
        )->execute([mb_substr($fileName, 0, 255), $id]);
    }

    /** Nombre de documents d'une provenance donnée (quotas séparés upload/synchronisé). */
    public static function countBySource(int $kbId, string $source): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM knowledge_base_documents WHERE kb_id = ? AND source = ?'
        );
        $stmt->execute([$kbId, $source]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Supprime le document : fichier disque + ligne (chunks par CASCADE).
     * La purge des points Qdrant est orchestrée par l'appelant (best effort,
     * AVANT cet appel — les ids de chunks disparaissent avec la ligne).
     */
    public static function delete(int $id): void
    {
        $doc = self::findById($id);
        if (!$doc) {
            return;
        }
        $path = KnowledgeBase::storageDirFor((int)$doc['kb_id']) . '/' . $doc['stored_name'];
        if (is_file($path)) {
            @unlink($path);
        }
        Database::getInstance()->prepare('DELETE FROM knowledge_base_documents WHERE id = ?')->execute([$id]);
    }

    // ===================== Statuts =====================

    public static function setExtractionStatus(int $id, string $status, ?string $error = null): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_base_documents SET extraction_status = ?, extraction_error = ? WHERE id = ?'
        )->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $id]);
    }

    public static function setExtractedText(int $id, ?string $text): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_base_documents SET extracted_text = ?, token_count = ? WHERE id = ?'
        )->execute([$text, TextChunker::estimateTokens($text), $id]);
    }

    public static function setVectorStatus(int $id, string $status, ?string $error = null, ?int $chunkCount = null): void
    {
        if ($chunkCount !== null) {
            Database::getInstance()->prepare(
                'UPDATE knowledge_base_documents SET vector_status = ?, vector_error = ?, chunk_count = ? WHERE id = ?'
            )->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $chunkCount, $id]);
            return;
        }
        Database::getInstance()->prepare(
            'UPDATE knowledge_base_documents SET vector_status = ?, vector_error = ? WHERE id = ?'
        )->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $id]);
    }

    // ===================== Chunking =====================

    /**
     * (Re)construit les chunks MySQL d'un document depuis son extracted_text.
     * Transactionnel et idempotent (delete + insert). Retourne le nb de chunks.
     * NB : les anciens ids de chunks (= point ids Qdrant) disparaissent —
     * l'appelant doit purger les points du document AVANT de ré-upserter.
     */
    public static function rechunkDocument(int $documentId): int
    {
        $doc = self::findById($documentId);
        if (!$doc) {
            return 0;
        }
        $text = (string)($doc['extracted_text'] ?? '');
        $kbId = (int)$doc['kb_id'];

        // Découpage sémantique via le LLM par défaut (fallback heuristique
        // automatique si LLM indisponible/échec). IMPÉRATIF : hors transaction.
        // Il enchaîne des appels HTTP de plusieurs secondes ; à l'intérieur, les
        // verrous de knowledge_base_chunks resteraient tenus pendant tout
        // l'aller-retour et deux documents d'une même base ingérés en parallèle
        // partiraient en deadlock (upload multiple, seed de la base système).
        $chunkingUsage = null;
        $chunks = trim($text) !== '' ? SemanticChunker::split($text, $chunkingUsage) : [];
        // Les appels LLM du découpage sont payés même si le résultat est
        // retombé sur l'heuristique : ils sont journalisés quoi qu'il arrive.
        if ($chunkingUsage !== null) {
            UsageMeter::llm(
                (string)$chunkingUsage['llm'],
                (array)$chunkingUsage['tokens'],
                UsageSource::KB_CHUNKING,
                self::billingContext($kbId)
            );
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM knowledge_base_chunks WHERE document_id = ?')
               ->execute([$documentId]);

            $n = 0;
            if ($chunks !== []) {
                $ins = $db->prepare(
                    'INSERT INTO knowledge_base_chunks
                        (document_id, kb_id, chunk_index, content, token_count)
                     VALUES (?,?,?,?,?)'
                );
                foreach ($chunks as $i => $c) {
                    $ins->execute([$documentId, $kbId, $i, $c, TextChunker::estimateTokens($c)]);
                    $n++;
                }
            }
            $db->commit();
            return $n;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Chunks d'un document (id, contenu, tokens) — pour l'embedding. */
    public static function chunksForDocument(int $documentId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, chunk_index, content, token_count
             FROM knowledge_base_chunks
             WHERE document_id = ?
             ORDER BY chunk_index ASC'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /** Extraits par page dans le détail de la section « Avancé » d'une base. */
    public const CHUNKS_PER_PAGE = 20;

    /** Caractères d'un extrait renvoyés à l'écran (au-delà, il est dit coupé). */
    public const CHUNK_PREVIEW_CHARS = 4000;

    /**
     * Une page des extraits d'une base, tels qu'ils sont en base de données —
     * section « Avancé » de la fiche : un document, ou tous ; un texte
     * cherché dans leur contenu, ou rien. Ordre du document, puis de
     * l'extrait : celui dans lequel la recherche les a découpés.
     *
     * @return array{total: int, rows: list<array{id: int, document_id: int, file_name: string, chunk_index: int, token_count: int, chars: int, content: string}>}
     */
    public static function chunksPage(int $kbId, ?int $documentId, string $query, int $page): array
    {
        $where  = ['c.kb_id = ?'];
        $params = [$kbId];
        if ($documentId !== null && $documentId > 0) {
            $where[]  = 'c.document_id = ?';
            $params[] = $documentId;
        }
        $query = trim($query);
        if ($query !== '') {
            // Recherche littérale : les jokers de LIKE tapés à l'écran restent du texte.
            $where[]  = "c.content LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $db = Database::getInstance();

        $count = $db->prepare('SELECT COUNT(*) FROM knowledge_base_chunks c WHERE ' . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $perPage = self::CHUNKS_PER_PAGE;
        $offset  = max(0, $page - 1) * $perPage;
        $stmt = $db->prepare(
            'SELECT c.id, c.document_id, d.file_name, c.chunk_index, c.token_count,
                    CHAR_LENGTH(c.content) AS chars,
                    LEFT(c.content, ' . self::CHUNK_PREVIEW_CHARS . ') AS content
               FROM knowledge_base_chunks c
               JOIN knowledge_base_documents d ON d.id = c.document_id
              WHERE ' . $sqlWhere . '
              ORDER BY d.created_at ASC, d.id ASC, c.chunk_index ASC
              LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id'          => (int)$row['id'],
                'document_id' => (int)$row['document_id'],
                'file_name'   => (string)$row['file_name'],
                'chunk_index' => (int)$row['chunk_index'],
                'token_count' => (int)$row['token_count'],
                'chars'       => (int)$row['chars'],
                'content'     => (string)$row['content'],
            ];
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Volume des extraits d'une base : nombre et tokens — l'en-tête du
     * détail de la section « Avancé ».
     *
     * @return array{chunks: int, tokens: int}
     */
    public static function chunkTotals(int $kbId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(token_count), 0) AS tokens FROM knowledge_base_chunks WHERE kb_id = ?'
        );
        $stmt->execute([$kbId]);
        $row = $stmt->fetch() ?: [];
        return ['chunks' => (int)($row['n'] ?? 0), 'tokens' => (int)($row['tokens'] ?? 0)];
    }

    // ===================== Retrieval lexical (fallback) =====================

    /**
     * Fallback lexical multi-bases : FULLTEXT MySQL sur knowledge_base_chunks,
     * utilisé quand Qdrant ou l'API d'embedding est indisponible. Même format
     * de sortie que le chemin vectoriel (TextChunker::packChunks).
     *
     * Requête 100 % positionnelle : PDO en prepares natives interdit de
     * mélanger placeholders nommés et positionnels, et le IN (...) est généré.
     *
     * @param list<int> $kbIds
     */
    public static function retrieveLexicalFallback(array $kbIds, string $query, int $tokenBudget): ?string
    {
        $kbIds = array_values(array_filter(array_map('intval', $kbIds), static fn($i) => $i > 0));
        if ($kbIds === [] || $tokenBudget <= 0) {
            return null;
        }

        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($kbIds), '?'));

        $rows = [];
        $q    = trim($query);
        if ($q !== '') {
            $stmt = $db->prepare(
                "SELECT c.kb_id, d.file_name, c.content, c.token_count,
                        MATCH(c.content) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
                 FROM knowledge_base_chunks c
                 JOIN knowledge_base_documents d ON d.id = c.document_id
                 WHERE c.kb_id IN ($ph)
                   AND MATCH(c.content) AGAINST (? IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC
                 LIMIT 50"
            );
            $stmt->execute(array_merge([$q], $kbIds, [$q]));
            $rows = $stmt->fetchAll();
        }

        // Requête vide / stopwords / 0 match → premiers chunks des bases.
        if (!$rows) {
            $stmt = $db->prepare(
                "SELECT c.kb_id, d.file_name, c.content, c.token_count
                 FROM knowledge_base_chunks c
                 JOIN knowledge_base_documents d ON d.id = c.document_id
                 WHERE c.kb_id IN ($ph)
                 ORDER BY c.kb_id ASC, c.document_id ASC, c.chunk_index ASC
                 LIMIT 50"
            );
            $stmt->execute($kbIds);
            $rows = $stmt->fetchAll();
        }

        if (!$rows) {
            return null;
        }
        // Le repli lexical compte comme une contribution : c'est bien cette
        // base qui a alimenté le prompt, moteur vectoriel debout ou non.
        KnowledgeBase::markUsedFromChunks($rows);

        return TextChunker::packChunks($rows, $tokenBudget);
    }

    /**
     * Contenus de chunks par ids, réordonnés selon l'ordre fourni (scores
     * vectoriels desc). Utilisé après un search Qdrant : les points orphelins
     * (chunk supprimé côté MySQL) sont filtrés naturellement.
     *
     * @param list<int> $chunkIds
     */
    public static function chunksByIds(array $chunkIds): array
    {
        $chunkIds = array_values(array_filter(array_map('intval', $chunkIds), static fn($i) => $i > 0));
        if ($chunkIds === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($chunkIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT c.id, c.kb_id, d.file_name, c.content, c.token_count
             FROM knowledge_base_chunks c
             JOIN knowledge_base_documents d ON d.id = c.document_id
             WHERE c.id IN ($ph)"
        );
        $stmt->execute($chunkIds);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $ordered = [];
        foreach ($chunkIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    // ===================== Pipeline d'ingestion =====================

    /**
     * Pipeline complet d'un document (worker en tâche de fond ou secours
     * synchrone). N'émet aucune exception : tout échec passe le document en
     * 'failed' (extraction ou vecteur selon la phase).
     */
    /**
     * Contexte d'imputation des coûts d'alimentation d'une base : son
     * CRÉATEUR (`created_by`) — la base système, sans créateur, est imputée
     * à l'instance (user_id NULL). Hors quota par construction (UsageSource) :
     * un créateur qui vectorise 20 documents n'est pas privé de chat.
     *
     * @return array{user_id:?int, kb_id:int}
     */
    private static function billingContext(int $kbId, ?array $kb = null): array
    {
        $kb ??= KnowledgeBase::findById($kbId);
        $creator = isset($kb['created_by']) ? (int)$kb['created_by'] : 0;
        return ['user_id' => $creator > 0 ? $creator : null, 'kb_id' => $kbId];
    }

    public static function processIngestion(int $id): void
    {
        $doc = self::findById($id);
        if (!$doc) {
            return;
        }
        $kb = KnowledgeBase::findById((int)$doc['kb_id']);
        if (!$kb) {
            return;
        }

        // --- Phase 1 : extraction du texte (ou description vision) ---
        self::setExtractionStatus($id, 'processing');
        try {
            $mime = (string)$doc['mime_type'];
            $path = KnowledgeBase::storageDirFor((int)$doc['kb_id']) . '/' . $doc['stored_name'];

            if (!is_file($path)) {
                self::setExtractionStatus($id, 'failed', 'Fichier introuvable sur le stockage.');
                self::setVectorStatus($id, 'failed', 'Extraction non aboutie.');
                return;
            }

            if (str_starts_with($mime, 'image/')) {
                // Image : description via LLM vision, vectorisée comme un texte.
                // La consommation est journalisée même si la description échoue
                // (finally) : l'appel a eu lieu et a été facturé.
                $visionUsage = null;
                try {
                    $desc = KbVisionDescriber::describe($path, $mime, (string)$doc['file_name'], $visionUsage);
                } finally {
                    if ($visionUsage !== null) {
                        UsageMeter::llm(
                            (string)$visionUsage['llm'],
                            (array)$visionUsage['tokens'],
                            UsageSource::KB_VISION,
                            self::billingContext((int)$doc['kb_id'], $kb)
                        );
                    }
                }
                $text = 'Description de l\'image ' . $doc['file_name'] . " :\n" . $desc;
            } else {
                $text = FileExtractor::extract($path, $mime);
            }

            self::setExtractedText($id, $text);
            $chunkCount = self::rechunkDocument($id);

            if ($text === null || trim((string)$text) === '') {
                self::setExtractionStatus($id, 'done', 'Aucun texte extrait.');
                self::setVectorStatus($id, 'done', null, 0);
                return;
            }
            self::setExtractionStatus($id, 'done');
        } catch (\Throwable $e) {
            self::setExtractionStatus($id, 'failed', $e->getMessage());
            self::setVectorStatus($id, 'failed', 'Extraction non aboutie.');
            error_log('[KB] extraction échouée doc ' . $id . ' : ' . $e->getMessage());
            return;
        }

        // --- Phase 2 : embeddings + upsert Qdrant ---
        self::vectorizeDocument($id, $kb, $chunkCount);
    }

    /**
     * Vectorise les chunks MySQL d'un document vers Qdrant. Idempotent :
     * purge les points du document avant ré-upsert (les ids de chunks changent
     * à chaque rechunk). Échec → vector_status 'failed', chunks conservés
     * (fallback lexical opérationnel).
     */
    public static function vectorizeDocument(int $id, array $kb, ?int $chunkCount = null): void
    {
        self::setVectorStatus($id, 'processing');
        try {
            $chunks = self::chunksForDocument($id);
            $collection = KnowledgeBase::collectionName((int)$kb['id']);

            $store = new QdrantStore();
            $store->ensureCollection($collection, (int)$kb['embedding_dims']);
            $store->deletePointsByDocument($collection, $id);

            if ($chunks === []) {
                self::setVectorStatus($id, 'done', null, 0);
                return;
            }

            $embedded = EmbeddingService::embedBatchWithUsage(
                (string)$kb['embedding_provider'],
                (string)$kb['embedding_model'],
                array_map(static fn($c) => (string)$c['content'], $chunks)
            );
            $vectors = $embedded['vectors'];
            // Vectorisation payée au fournisseur : journalisée avant l'upsert
            // Qdrant (un échec de stockage n'annule pas la facture).
            UsageMeter::embeddings(
                (string)$kb['embedding_provider'],
                (string)$kb['embedding_model'],
                (int)$embedded['tokens'],
                UsageSource::KB_EMBEDDING,
                self::billingContext((int)$kb['id'], $kb)
            );

            $points = [];
            foreach ($chunks as $i => $c) {
                $points[] = [
                    'id'      => (int)$c['id'],
                    'vector'  => $vectors[$i],
                    'payload' => [
                        'kb_id'       => (int)$kb['id'],
                        'document_id' => $id,
                        'chunk_index' => (int)$c['chunk_index'],
                    ],
                ];
            }
            $store->upsertPoints($collection, $points);
            self::setVectorStatus($id, 'done', null, $chunkCount ?? count($chunks));
        } catch (\Throwable $e) {
            // Lots vectorisés avant la panne : payés, donc journalisés.
            if ($e instanceof EmbeddingPartialException && $e->tokens > 0) {
                UsageMeter::embeddings(
                    (string)$kb['embedding_provider'],
                    (string)$kb['embedding_model'],
                    $e->tokens,
                    UsageSource::KB_EMBEDDING,
                    self::billingContext((int)$kb['id'], $kb)
                );
            }
            self::setVectorStatus($id, 'failed', $e->getMessage());
            error_log('[KB] vectorisation échouée doc ' . $id . ' : ' . $e->getMessage());
        }
    }
}
