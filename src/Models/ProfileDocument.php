<?php

declare(strict_types=1);

class ProfileDocument
{
    /**
     * Plafonds par défaut pour l'injection des images de référence en vision.
     * Les images sont ré-envoyées au LLM à CHAQUE tour : on borne le nombre
     * et le poids cumulé pour maîtriser le coût en tokens d'entrée.
     */
    public const VISION_MAX_IMAGES = 3;
    public const VISION_MAX_BYTES  = 4 * 1024 * 1024;

    /**
     * Découpage en chunks (RAG lexical). Façades sur TextChunker — la logique
     * est partagée avec les bases de connaissances (KnowledgeBaseDocument).
     */
    public const CHUNK_TARGET_TOKENS  = TextChunker::CHUNK_TARGET_TOKENS;
    public const CHUNK_MAX_TOKENS     = TextChunker::CHUNK_MAX_TOKENS;
    public const CHUNK_OVERLAP_TOKENS = TextChunker::CHUNK_OVERLAP_TOKENS;

    /**
     * Répertoire de stockage physique des documents de référence.
     * Un sous-répertoire par profil : storage/profile_documents/{profile_id}/.
     */
    public static function storageRoot(): string
    {
        return ROOT . '/storage/profile_documents';
    }

    public static function storageDirFor(int $profileId): string
    {
        return self::storageRoot() . '/' . $profileId;
    }

    public static function ensureStorageDir(int $profileId): string
    {
        $dir = self::storageDirFor($profileId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(t('Impossible de créer le dossier des documents de référence.'));
        }
        return $dir;
    }

    public static function allForProfile(int $profileId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, profile_id, file_name, stored_name, mime_type, file_size,
                    token_count, created_at, extraction_status, extraction_error,
                    CASE WHEN extracted_text IS NULL THEN 0 ELSE 1 END AS has_text
             FROM profile_documents
             WHERE profile_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$profileId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM profile_documents WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO profile_documents
                (profile_id, file_name, stored_name, mime_type, file_size, extracted_text, token_count, extraction_status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            (int)$data['profile_id'],
            (string)$data['file_name'],
            (string)$data['stored_name'],
            (string)$data['mime_type'],
            (int)($data['file_size'] ?? 0),
            $data['extracted_text'] ?? null,
            (int)($data['token_count'] ?? 0),
            (string)($data['extraction_status'] ?? 'done'),
        ]);
        return (int)$db->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $doc = self::findById($id);
        if (!$doc) {
            return;
        }
        $path = self::storageDirFor((int)$doc['profile_id']) . '/' . $doc['stored_name'];
        if (is_file($path)) {
            @unlink($path);
        }
        Database::getInstance()->prepare('DELETE FROM profile_documents WHERE id = ?')->execute([$id]);
    }

    /**
     * Somme des tokens estimés (extracted_text) pour un profil.
     */
    public static function totalTokens(int $profileId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(token_count), 0) FROM profile_documents WHERE profile_id = ?'
        );
        $stmt->execute([$profileId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Concatène le texte extrait de tous les documents d'un profil,
     * prêt à être injecté dans le system prompt. Retourne null si aucun texte disponible.
     */
    public static function buildRagContext(int $profileId): ?string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT file_name, extracted_text
             FROM profile_documents
             WHERE profile_id = ?
               AND extracted_text IS NOT NULL
               AND length(extracted_text) > 0
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$profileId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return null;
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = '--- ' . $row['file_name'] . " ---\n" . $row['extracted_text'];
        }
        return implode("\n\n", $parts);
    }

    /**
     * Construit les blocs image (vision) des documents de référence d'un profil,
     * pour injection multimodale dans les messages LLM. Miroir de buildRagContext()
     * côté images : ne renvoie QUE les documents image, encodés en base64.
     *
     * Forme de chaque part identique à celle produite par
     * ChatController::buildLLMMessages() : les adapters vision la consomment tel quel.
     *   ['type'=>'image','mime'=>..,'data'=>base64,'name'=>..,'path'=>absPath]
     *
     * Borne le nombre d'images et le poids cumulé (cf. VISION_MAX_*). Les fichiers
     * trop volumineux ou illisibles sont silencieusement ignorés.
     *
     * @return list<array{type:string,mime:string,data:string,name:string,path:string}>
     */
    public static function imageParts(
        int $profileId,
        int $maxImages = self::VISION_MAX_IMAGES,
        int $maxBytes = self::VISION_MAX_BYTES
    ): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT file_name, stored_name, mime_type, file_size
             FROM profile_documents
             WHERE profile_id = ?
               AND mime_type LIKE 'image/%'
             ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([$profileId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [];
        }

        $dir   = self::storageDirFor($profileId);
        $parts = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if (count($parts) >= $maxImages) {
                break;
            }
            $path = $dir . '/' . $row['stored_name'];
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $size = (int)($row['file_size'] ?? 0) ?: (int)(@filesize($path) ?: 0);
            if ($size > 0 && $bytes + $size > $maxBytes) {
                continue;
            }
            $binary = @file_get_contents($path);
            if (!is_string($binary) || $binary === '') {
                continue;
            }
            $bytes  += strlen($binary);
            $parts[] = [
                'type' => 'image',
                'mime' => (string)$row['mime_type'],
                'data' => base64_encode($binary),
                'name' => (string)$row['file_name'],
                'path' => $path,
            ];
        }
        return $parts;
    }

    /**
     * Estimation grossière de tokens. Façade sur TextChunker.
     */
    public static function estimateTokens(?string $text): int
    {
        return TextChunker::estimateTokens($text);
    }

    // ===================== Chunking (RAG lexical) =====================

    /**
     * Découpe un texte en chunks. Façade sur TextChunker (logique partagée
     * avec les bases de connaissances).
     * @return list<string>
     */
    public static function splitIntoChunks(string $text): array
    {
        return TextChunker::splitIntoChunks($text);
    }

    /**
     * (Re)construit les chunks d'un document à partir de son extracted_text.
     * Transactionnel et idempotent (delete + insert). Retourne le nb de chunks.
     * Document sans texte (image, extraction vide) → 0 chunk.
     */
    public static function rechunkDocument(int $documentId): int
    {
        $doc = self::findById($documentId);
        if (!$doc) {
            return 0;
        }
        $text      = (string)($doc['extracted_text'] ?? '');
        $profileId = (int)$doc['profile_id'];

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM profile_document_chunks WHERE document_id = ?')
               ->execute([$documentId]);

            $n = 0;
            if (trim($text) !== '') {
                $ins = $db->prepare(
                    'INSERT INTO profile_document_chunks
                        (document_id, profile_id, chunk_index, content, token_count)
                     VALUES (?,?,?,?,?)'
                );
                foreach (self::splitIntoChunks($text) as $i => $c) {
                    $ins->execute([$documentId, $profileId, $i, $c, self::estimateTokens($c)]);
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

    public static function deleteChunks(int $documentId): void
    {
        Database::getInstance()
            ->prepare('DELETE FROM profile_document_chunks WHERE document_id = ?')
            ->execute([$documentId]);
    }

    /**
     * Chunke tous les documents (d'un profil ou globalement) qui ont du texte
     * mais aucun chunk. Idempotent — utilisé pour le backfill post-déploiement.
     * Retourne le nombre de documents traités.
     */
    public static function backfillChunks(?int $profileId = null): int
    {
        $sql = 'SELECT pd.id
                FROM profile_documents pd
                LEFT JOIN profile_document_chunks c ON c.document_id = pd.id
                WHERE pd.extracted_text IS NOT NULL AND length(pd.extracted_text) > 0
                  AND c.id IS NULL';
        $params = [];
        if ($profileId !== null) {
            $sql      .= ' AND pd.profile_id = ?';
            $params[]  = $profileId;
        }
        $sql .= ' GROUP BY pd.id';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $done = 0;
        foreach ($ids as $id) {
            self::rechunkDocument((int)$id);
            $done++;
        }
        return $done;
    }

    // ===================== Retrieval lexical budgété =====================

    /**
     * Sélectionne les chunks les plus pertinents pour $query (FULLTEXT MySQL,
     * NATURAL LANGUAGE MODE) dans la limite de $tokenBudget tokens estimés.
     * Format identique à buildRagContext() : "--- fichier ---\ncontenu",
     * blocs joints par "\n\n". Retourne null si rien à injecter.
     *
     * Chaîne de fallback (l'assistant ne casse jamais) :
     *   - budget ≤ 0 → null (jamais d'overflow)
     *   - aucun chunk en base → fallback texte brut tronqué (backfill non lancé)
     *   - requête vide / stopwords / 0 match FULLTEXT → N premiers chunks
     */
    public static function retrieveRelevantContext(int $profileId, string $query, int $tokenBudget): ?string
    {
        if ($tokenBudget <= 0) {
            return null;
        }

        $db = Database::getInstance();

        $cnt = $db->prepare('SELECT COUNT(*) FROM profile_document_chunks WHERE profile_id = ?');
        $cnt->execute([$profileId]);
        if ((int)$cnt->fetchColumn() === 0) {
            return self::fallbackContextFromText($profileId, $tokenBudget);
        }

        $rows = [];
        $q    = trim($query);
        if ($q !== '') {
            // Deux placeholders distincts liés à la même valeur : PDO en mode
            // prepares natives (EMULATE_PREPARES=false) ne garantit pas la
            // réutilisation d'un même paramètre nommé.
            $stmt = $db->prepare(
                'SELECT pd.file_name, c.content, c.token_count,
                        MATCH(c.content) AGAINST (:qsel IN NATURAL LANGUAGE MODE) AS score
                 FROM profile_document_chunks c
                 JOIN profile_documents pd ON pd.id = c.document_id
                 WHERE c.profile_id = :pid
                   AND MATCH(c.content) AGAINST (:qwhere IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC
                 LIMIT 50'
            );
            $stmt->execute([':qsel' => $q, ':qwhere' => $q, ':pid' => $profileId]);
            $rows = $stmt->fetchAll();
        }

        if (!$rows) {
            $stmt = $db->prepare(
                'SELECT pd.file_name, c.content, c.token_count
                 FROM profile_document_chunks c
                 JOIN profile_documents pd ON pd.id = c.document_id
                 WHERE c.profile_id = ?
                 ORDER BY c.document_id ASC, c.chunk_index ASC
                 LIMIT 50'
            );
            $stmt->execute([$profileId]);
            $rows = $stmt->fetchAll();
        }

        return $rows ? self::packChunks($rows, $tokenBudget) : null;
    }

    /**
     * Empile des lignes {file_name, content, token_count} jusqu'au budget.
     * Façade sur TextChunker::packChunks.
     * @param list<array<string,mixed>> $rows
     */
    private static function packChunks(array $rows, int $tokenBudget): ?string
    {
        return TextChunker::packChunks($rows, $tokenBudget);
    }

    /**
     * Garde-fou : des documents ont du texte mais aucun chunk (backfill non
     * lancé). On dégrade vers le texte brut tronqué au budget plutôt que de
     * crasher ou de ne rien renvoyer.
     */
    private static function fallbackContextFromText(int $profileId, int $tokenBudget): ?string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM profile_documents
             WHERE profile_id = ? AND extracted_text IS NOT NULL AND length(extracted_text) > 0'
        );
        $stmt->execute([$profileId]);
        if ((int)$stmt->fetchColumn() === 0) {
            return null;
        }

        error_log("[RAG] profil $profileId : documents non chunkés — lancez scripts/backfill_document_chunks.php");
        $full = self::buildRagContext($profileId);
        if ($full === null) {
            return null;
        }
        $maxChars = $tokenBudget * 4;
        return mb_strlen($full) > $maxChars ? mb_substr($full, 0, $maxChars) : $full;
    }

    /**
     * Budget de tokens alloué au bloc RAG en fonction de la fenêtre de contexte
     * du modèle actif. Conservateur : on réserve sortie + historique + scaffolding
     * et on plafonne dur à 50 % de la fenêtre (absorbe l'imprécision d'estimateTokens).
     */
    public static function ragTokenBudget(int $contextWindow): int
    {
        if ($contextWindow <= 0) {
            return 0;
        }
        // Réservations bornées (sortie + scaffolding) pour qu'une petite fenêtre
        // (modèle local 8k) garde tout de même un budget RAG non nul exploitable,
        // tout en réservant l'historique de conversation. Plafond dur à 50 % de la
        // fenêtre → absorbe l'imprécision d'estimateTokens, jamais d'overflow.
        $reservedOutput  = min(4096, (int)round($contextWindow * 0.15));
        $reservedHistory = (int)round($contextWindow * 0.35);
        $reservedFixed   = min(2000, (int)round($contextWindow * 0.10));

        $budget  = $contextWindow - $reservedOutput - $reservedHistory - $reservedFixed;
        $ceiling = (int)round($contextWindow * 0.50);
        if ($budget < 0) {
            $budget = 0;
        }
        return min($budget, $ceiling);
    }

    // ===================== Extraction asynchrone =====================

    public static function setExtractionStatus(int $id, string $status, ?string $error = null): void
    {
        Database::getInstance()->prepare(
            'UPDATE profile_documents SET extraction_status = ?, extraction_error = ? WHERE id = ?'
        )->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $id]);
    }

    public static function setExtractedText(int $id, ?string $text): void
    {
        Database::getInstance()->prepare(
            'UPDATE profile_documents SET extracted_text = ?, token_count = ? WHERE id = ?'
        )->execute([$text, self::estimateTokens($text), $id]);
    }

    /**
     * Pipeline d'extraction d'un document (appelé par le worker en tâche de fond
     * ou en secours synchrone). Extrait le texte, met à jour le statut, chunke.
     * N'émet aucune exception : tout échec passe le document en 'failed'.
     */
    public static function processExtraction(int $id): void
    {
        $doc = self::findById($id);
        if (!$doc) {
            return;
        }
        self::setExtractionStatus($id, 'processing');
        try {
            $mime = (string)$doc['mime_type'];
            $path = self::storageDirFor((int)$doc['profile_id']) . '/' . $doc['stored_name'];

            if (!is_file($path)) {
                self::setExtractionStatus($id, 'failed', 'Fichier introuvable sur le stockage.');
                return;
            }

            // Images : pas d'extraction texte (chemin vision), traité direct.
            if (str_starts_with($mime, 'image/')) {
                self::setExtractedText($id, null);
                self::deleteChunks($id);
                self::setExtractionStatus($id, 'done');
                return;
            }

            $text = FileExtractor::extract($path, $mime);
            self::setExtractedText($id, $text);
            self::rechunkDocument($id);

            if ($text === null || trim($text) === '') {
                self::setExtractionStatus($id, 'done', 'Aucun texte extrait.');
            } else {
                self::setExtractionStatus($id, 'done');
            }
        } catch (\Throwable $e) {
            self::setExtractionStatus($id, 'failed', $e->getMessage());
            error_log('[RAG] extraction échouée doc ' . $id . ' : ' . $e->getMessage());
        }
    }
}
