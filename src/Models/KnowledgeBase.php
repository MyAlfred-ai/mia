<?php

declare(strict_types=1);

/**
 * Bases de connaissances : collections nommées de documents vectorisés,
 * partagées entre assistants (relation N-N via profile_knowledge_bases).
 *
 * Les vecteurs vivent dans Qdrant (une collection par base : kb_{id}) ;
 * MySQL reste la source de vérité (documents + chunks, fallback lexical).
 * Gérées par les admins (tout) et les creators (les leurs, via created_by).
 *
 * Deux natures (`backend`, migration 073) : `local` — des documents déposés
 * ou synchronisés, découpés et vectorisés ici — et `mybrain` — une base « en
 * direct » dont le contenu reste dans un cerveau MyBrain, interrogé à chaque
 * message (KnowledgeBaseRemote, MyBrainContext). La seconde réutilise tout ce
 * qui entoure une base (partage, assistants, sélecteur, compteurs) ; les
 * chemins réservés aux documents la refusent.
 */
class KnowledgeBase
{
    /** Quota de bases par creator (les admins ne sont pas limités). */
    public const MAX_PER_CREATOR = 5;

    /** Nombre maximum de documents par base. */
    public const MAX_DOCS_PER_KB = 20;

    /** Documents stockés et vectorisés dans MIA. */
    public const BACKEND_LOCAL = 'local';

    /** Contenu interrogé en direct dans un cerveau MyBrain. */
    public const BACKEND_MYBRAIN = 'mybrain';

    /**
     * Colonnes de la connexion d'une base en direct, jointes aux lectures de
     * liste (écran d'administration, sélecteur du chat). Jamais la clé : elle
     * ne sort de knowledge_base_remotes que pour un appel (KnowledgeBaseRemote).
     */
    private const REMOTE_COLUMNS =
        'r.base_url AS remote_url, r.key_hint AS remote_key_hint, r.app_name AS remote_app_name,
         r.rights AS remote_rights, r.status AS remote_status, r.status_error AS remote_error,
         r.checked_at AS remote_checked_at, TIMESTAMPDIFF(SECOND, r.checked_at, NOW()) AS remote_checked_age';

    /** Une base en direct (MyBrain) ? PURE — lit la colonne `backend` de la ligne. */
    public static function isRemote(array $kb): bool
    {
        return (string)($kb['backend'] ?? self::BACKEND_LOCAL) === self::BACKEND_MYBRAIN;
    }

    /**
     * Sépare les bases en direct des bases locales, ordre conservé. PURE.
     *
     * @param list<array<string, mixed>> $kbs
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [en direct, locales]
     */
    public static function partitionByBackend(array $kbs): array
    {
        $remote = [];
        $local  = [];
        foreach ($kbs as $kb) {
            if (self::isRemote($kb)) {
                $remote[] = $kb;
            } else {
                $local[] = $kb;
            }
        }
        return [$remote, $local];
    }

    // ===================== Stockage physique =====================

    public static function storageRoot(): string
    {
        return ROOT . '/storage/knowledge_bases';
    }

    public static function storageDirFor(int $kbId): string
    {
        return self::storageRoot() . '/' . $kbId;
    }

    public static function ensureStorageDir(int $kbId): string
    {
        $dir = self::storageDirFor($kbId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(t('Impossible de créer le dossier de la base de connaissances.'));
        }
        return $dir;
    }

    /**
     * Réindexation complète : drop de la collection Qdrant, recréation aux
     * dimensions courantes (utile après changement de modèle d'embedding),
     * puis re-embedding de tous les documents depuis leurs chunks MySQL — ni
     * ré-extraction, ni re-description vision.
     *
     * SOURCE UNIQUE : appelée par le worker de jobs, par le repli synchrone de
     * l'admin et par `scripts/reindex_kb.php`. Le code vivait en double, et
     * seule une des deux copies était corrigée.
     *
     * Progression écrite document par document (`setActivity`) : les documents
     * pas encore atteints gardent leur statut « fait » d'avant, rien d'autre ne
     * dirait où en est la réindexation. `$heartbeat` (job de maintenance) bat
     * le cœur de la file à chaque document : muette plus de 120 s, une
     * réindexation serait prise pour morte et REJOUÉE en parallèle — collection
     * supprimée sous les pieds de la première, embeddings facturés deux fois.
     *
     * @param (callable():mixed)|null $heartbeat
     */
    public static function reindex(int $kbId, ?callable $heartbeat = null): void
    {
        $kb = self::findById($kbId);
        // Une base en direct n'a ni vecteurs ni documents : rien à refaire.
        // Garde ICI, point d'arrivée de tous les chemins (écran, restauration
        // d'une sauvegarde, script) — sans elle, on créerait une collection
        // Qdrant de dimension 0 pour rien.
        if (!$kb || self::isRemote($kb)) {
            return;
        }

        try {
            $store = new QdrantStore();
            $store->dropCollection(self::collectionName($kbId));
            $store->ensureCollection(self::collectionName($kbId), (int)$kb['embedding_dims']);
        } catch (\Throwable $e) {
            // Qdrant indisponible : chaque document passera en vector_status=failed
            // via vectorizeDocument, avec un message explicite. On continue.
            error_log('[KB] réindexation ' . $kbId . ' : ' . $e->getMessage());
        }

        $docs  = KnowledgeBaseDocument::allForKb($kbId);
        $total = count($docs);
        try {
            foreach (array_values($docs) as $i => $doc) {
                self::setActivity($kbId, [
                    'kind'    => 'reindex',
                    'phase'   => 'files',
                    'done'    => $i,
                    'total'   => $total,
                    'current' => (string)$doc['file_name'],
                ]);
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                KnowledgeBaseDocument::vectorizeDocument((int)$doc['id'], $kb);
            }
        } finally {
            self::setActivity($kbId, null);
        }
    }

    /** Nom de la collection Qdrant portant les vecteurs de cette base. */
    public static function collectionName(int $kbId): string
    {
        return 'kb_' . $kbId;
    }

    /**
     * Marque comme « utilisées » les bases dont des extraits viennent d'être
     * injectés dans un prompt (migration 068). Une seule requête, quel que
     * soit le nombre de bases interrogées.
     *
     * On compte la CONTRIBUTION, pas l'interrogation : une base liée à un
     * assistant est fouillée à chaque message, donc compter les recherches
     * ne mesurerait que le trafic de l'assistant. Ce qui distingue une base
     * utile d'une base morte, c'est qu'il en sorte quelque chose.
     *
     * Best-effort absolu : un compteur d'affichage ne casse jamais un tour de
     * conversation (colonne pas encore migrée, base indisponible…).
     *
     * @param array<int, array<string, mixed>> $chunkRows lignes portant `kb_id`
     */
    public static function markUsedFromChunks(array $chunkRows): void
    {
        $ids = [];
        foreach ($chunkRows as $row) {
            $ids[] = (int)($row['kb_id'] ?? 0);
        }
        self::markUsed($ids);
    }

    /**
     * Même compteur, pour des bases désignées directement : une base en
     * direct (MyBrain) n'a pas d'extraits en MySQL, elle compte quand le
     * cerveau a rendu au moins une fiche qui est partie dans le prompt — la
     * même règle de contribution, appliquée à ce qu'elle rend.
     *
     * @param list<int> $kbIds
     */
    public static function markUsed(array $kbIds): void
    {
        $ids = [];
        foreach ($kbIds as $kbId) {
            if ((int)$kbId > 0) { $ids[(int)$kbId] = true; }
        }
        if ($ids === []) {
            return;
        }
        $ids = array_keys($ids);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = Database::getInstance()->prepare(
                "UPDATE knowledge_bases
                    SET query_count = query_count + 1, last_used_at = NOW()
                  WHERE id IN ($ph)"
            );
            $stmt->execute($ids);
        } catch (\Throwable $e) {
            error_log('[KB] compteur d\'utilisation : ' . $e->getMessage());
        }
    }

    // ===================== Lecture =====================

    /**
     * Toutes les bases, avec agrégats (nb documents / nb chunks) et nom du
     * créateur. Sous-requêtes scalaires : pas de GROUP BY (ONLY_FULL_GROUP_BY).
     */
    public static function all(): array
    {
        return Database::getInstance()->query(
            'SELECT kb.*,
                    u.name AS creator_name,
                    (SELECT COUNT(*) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS doc_count,
                    (SELECT COALESCE(SUM(file_size), 0) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS total_size,
                    (SELECT COUNT(*) FROM knowledge_base_chunks c WHERE c.kb_id = kb.id)    AS chunk_count,
                    ' . self::REMOTE_COLUMNS . '
             FROM knowledge_bases kb
             LEFT JOIN users u ON u.id = kb.created_by
             LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
             ORDER BY kb.name ASC'
        )->fetchAll();
    }

    /**
     * Bases visibles par un gestionnaire :
     *  - admin   → toutes,
     *  - creator → celles qu'il a créées (created_by) + celles qu'on lui a
     *              partagées, nominativement ou via un de ses groupes actifs.
     *
     * Les lignes portent `is_shared` (1 = partagée avec moi, je n'en suis pas
     * le créateur) et `share_can_manage` (droit d'écriture accordé par le
     * partage), pour que l'UI distingue lecture seule et gestion.
     */
    public static function forManager(array $user): array
    {
        // Toutes les bases de l'organisation : l'administrateur, et tout rôle
        // qui porte le droit « Bases de connaissances — Gérer ».
        if (Role::userCan($user, 'knowledge_bases.manage')) {
            return array_map(static function (array $kb): array {
                $kb['is_shared']        = 0;
                $kb['share_can_manage'] = 1;
                return $kb;
            }, self::all());
        }

        $userId   = (int)$user['id'];
        $groupIds = self::activeGroupIdsFor($userId);
        // Placeholders du IN : une branche neutre quand l'utilisateur n'a aucun
        // groupe actif (IN () est une erreur de syntaxe MySQL).
        $groupIn  = $groupIds === [] ? 'NULL' : implode(',', array_fill(0, count($groupIds), '?'));

        $sql =
            'SELECT kb.*,
                    u.name AS creator_name,
                    (SELECT COUNT(*) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS doc_count,
                    (SELECT COALESCE(SUM(file_size), 0) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS total_size,
                    (SELECT COUNT(*) FROM knowledge_base_chunks c WHERE c.kb_id = kb.id)    AS chunk_count,
                    CASE WHEN kb.created_by = ? THEN 0 ELSE 1 END AS is_shared,
                    CASE WHEN kb.created_by = ? THEN 1 ELSE COALESCE((
                        SELECT MAX(s.can_manage) FROM knowledge_base_shares s
                        WHERE s.kb_id = kb.id
                          AND (s.user_id = ? OR s.group_id IN (' . $groupIn . '))
                    ), 0) END AS share_can_manage,
                    ' . self::REMOTE_COLUMNS . '
             FROM knowledge_bases kb
             LEFT JOIN users u ON u.id = kb.created_by
             LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
             WHERE kb.created_by = ?
                OR EXISTS (
                    SELECT 1 FROM knowledge_base_shares s
                    WHERE s.kb_id = kb.id
                      AND (s.user_id = ? OR s.group_id IN (' . $groupIn . '))
                )
             ORDER BY kb.name ASC';

        $params = array_merge(
            [$userId, $userId, $userId],
            $groupIds,
            [$userId, $userId],
            $groupIds
        );

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Bases proposables dans le CHAT — celles que l'utilisateur a créées, plus
     * celles qu'on lui a partagées (nominativement ou via un de ses groupes
     * actifs) ; admin → toutes. AUCUN filtre de rôle : un simple `user` reçoit
     * un partage exactement comme un creator, et c'est le sélecteur du composer
     * qui lui donne enfin un écran où s'en servir.
     *
     * Distincte de forManager() à dessein, et non un simple alias :
     *  - ici on filtre `is_active = 1` — proposer une base éteinte serait un
     *    mensonge, elle ne sera jamais interrogée ; forManager, elle, doit
     *    montrer les bases inactives pour qu'on puisse les rallumer ;
     *  - ici on rapporte l'état de VECTORISATION, qui décide de la
     *    sélectionnabilité (cf. unavailableReason()).
     * Le PRÉDICAT D'ACCÈS, lui, est le même : le recopier est délibéré (deux
     * SQL, une seule règle), et un test fonctionnel vérifie que les deux
     * périmètres coïncident à `is_active` près.
     *
     * Les lignes sont des lignes `knowledge_bases` COMPLÈTES (`kb.*`) : elles
     * satisfont telles quelles le contrat de buildKnowledgeBaseContext()
     * (`id`, `name`, `embedding_provider`, `embedding_model`).
     */
    public static function selectableForUser(array $user): array
    {
        // Compteurs de vectorisation, en sous-requêtes scalaires : pas de
        // GROUP BY (ONLY_FULL_GROUP_BY), même patron que doc_count.
        $counters =
            '(SELECT COUNT(*) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS doc_count,
              (SELECT COUNT(*) FROM knowledge_base_documents d
                WHERE d.kb_id = kb.id AND d.vector_status = \'done\'
                  AND d.chunk_count > 0)                                     AS ready_docs,
              (SELECT COUNT(*) FROM knowledge_base_documents d
                WHERE d.kb_id = kb.id
                  AND d.vector_status IN (\'pending\',\'processing\'))          AS pending_docs,
              (SELECT COUNT(*) FROM knowledge_base_documents d
                WHERE d.kb_id = kb.id AND d.vector_status = \'failed\')        AS failed_docs';

        // Une base en direct n'a pas de documents : c'est l'état de sa
        // connexion (remote_status) qui décide si elle se coche.
        $counters .= ',
              ' . self::REMOTE_COLUMNS;

        // Sans le droit « Bases de connaissances — Utiliser », aucune base à
        // choisir dans la conversation (ni cerveau MyBrain, qui suit la
        // personne : gateForUser passe par ici). Les bases liées à un
        // assistant local, elles, répondent toujours avec lui.
        if (!Role::userCan($user, 'knowledge_bases.use')) {
            return [];
        }

        if (Role::userCan($user, 'knowledge_bases.manage')) {
            return Database::getInstance()->query(
                'SELECT kb.*, u.name AS creator_name, 0 AS is_shared, ' . $counters . '
                   FROM knowledge_bases kb
                   LEFT JOIN users u ON u.id = kb.created_by
                   LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
                  WHERE kb.is_active = 1
                  ORDER BY kb.name ASC'
            )->fetchAll();
        }

        $userId   = (int)$user['id'];
        $groupIds = self::activeGroupIdsFor($userId);
        // Branche neutre quand l'utilisateur n'a aucun groupe actif : `IN ()`
        // est une erreur de syntaxe MySQL, `IN (NULL)` est simplement faux.
        $groupIn  = $groupIds === [] ? 'NULL' : implode(',', array_fill(0, count($groupIds), '?'));

        $sql =
            'SELECT kb.*, u.name AS creator_name,
                    CASE WHEN kb.created_by = ? THEN 0 ELSE 1 END AS is_shared, ' . $counters . '
               FROM knowledge_bases kb
               LEFT JOIN users u ON u.id = kb.created_by
               LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
              WHERE kb.is_active = 1
                AND (kb.created_by = ?
                     OR EXISTS (
                         SELECT 1 FROM knowledge_base_shares s
                         WHERE s.kb_id = kb.id
                           AND (s.user_id = ? OR s.group_id IN (' . $groupIn . '))
                     ))
              ORDER BY kb.name ASC';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute(array_merge([$userId, $userId, $userId], $groupIds));
        return $stmt->fetchAll();
    }

    /**
     * La base a-t-elle de quoi répondre par la voie vectorielle ? PURE, lit les
     * compteurs posés par selectableForUser().
     *
     * `vector_status = 'done'` NE SUFFIT PAS : un document dont l'extraction ne
     * rend aucun texte (PDF scanné, image sans légende, fichier vide) termine
     * en 'done' avec chunk_count = 0 — vectorisé au sens du statut, vide au
     * sens du contenu (cf. KnowledgeBaseDocument::processIngestion). Sans la
     * seconde condition, une base 100 % illisible s'annoncerait prête.
     */
    public static function isVectorized(array $kb): bool
    {
        return (int)($kb['ready_docs'] ?? 0) > 0;
    }

    /**
     * Pourquoi cette base n'est pas sélectionnable, ou null si elle l'est.
     * SOURCE UNIQUE du grisé : le gabarit du chat, la validation du contrôleur
     * et les tests appellent tous celle-ci — deux règles divergentes
     * afficheraient une case cochable que le serveur refuserait.
     *
     * Conséquence assumée : une base dont TOUS les vecteurs ont échoué mais
     * dont les chunks MySQL existent reste interrogeable par le repli lexical,
     * et sera pourtant grisée. Faux négatif rare contre critère unique.
     */
    public static function unavailableReason(array $kb): ?string
    {
        // Base en direct : seul un refus DURABLE la grise (clé supprimée, droit
        // retiré, adresse fausse). Une panne passagère la laisse cochable — le
        // message part sans elle et le dit ; la griser ferait disparaître le
        // choix de l'utilisateur pour une coupure de trente secondes.
        if (self::isRemote($kb)) {
            $status = (string)($kb['remote_status'] ?? '');
            if ($status === '' && (string)($kb['remote_url'] ?? '') === '') {
                return 'Connexion MyBrain non configurée';
            }
            return $status === KnowledgeBaseRemote::STATUS_REFUSED
                ? 'Connexion MyBrain refusée — à vérifier par un administrateur'
                : null;
        }

        $docs    = (int)($kb['doc_count']    ?? 0);
        $ready   = (int)($kb['ready_docs']   ?? 0);
        $pending = (int)($kb['pending_docs'] ?? 0);
        $failed  = (int)($kb['failed_docs']  ?? 0);
        // Les 4 statuts sont exhaustifs : 'done' = ready + « done mais vide ».
        $empty   = max(0, $docs - $pending - $failed - $ready);

        if ($ready > 0)                  { return null; }
        if ($docs === 0)                 { return 'Aucun document'; }
        if ($pending > 0)                { return 'Vectorisation en cours'; }
        if ($empty > 0 && $failed === 0) { return 'Aucun texte exploitable dans les documents'; }
        if ($failed > 0)                 { return 'Vectorisation en échec'; }

        return 'Base non vectorisée';
    }

    /** Groupes actifs de l'utilisateur (cibles possibles d'un partage). */
    private static function activeGroupIdsFor(int $userId): array
    {
        return UserGroup::membershipScopeForUser($userId)['active_group_ids'] ?? [];
    }

    public static function findById(int $id): ?array
    {
        // Colonnes de connexion jointes : le panneau d'état d'une base en
        // direct (KbStatus) les lit sur la ligne, comme celles de la liste.
        $stmt = Database::getInstance()->prepare(
            'SELECT kb.*, ' . self::REMOTE_COLUMNS . '
               FROM knowledge_bases kb
               LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
              WHERE kb.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Droit d'ÉCRITURE sur une base : métadonnées, documents, réindexation.
     * Admin et créateur toujours ; bénéficiaire d'un partage seulement si
     * celui-ci porte can_manage = 1.
     */
    public static function manageableBy(int $kbId, array $user): bool
    {
        if (self::ownedBy($kbId, $user)) {
            return true;
        }
        return self::shareCanManage($kbId, $user);
    }

    /**
     * Droit de PROPRIÉTAIRE : suppression de la base et gestion de son partage.
     * Réservé au créateur et aux administrateurs — un partage, même en écriture,
     * ne permet ni de supprimer la base ni de la re-partager.
     */
    public static function ownedBy(int $kbId, array $user): bool
    {
        if (Role::userCan($user, 'knowledge_bases.manage')) {
            return self::findById($kbId) !== null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM knowledge_bases WHERE id = ? AND created_by = ?'
        );
        $stmt->execute([$kbId, (int)$user['id']]);
        return (bool)$stmt->fetch();
    }

    /** Base visible par l'utilisateur : créateur, admin, ou partage reçu. */
    public static function visibleBy(int $kbId, array $user): bool
    {
        if (self::ownedBy($kbId, $user)) {
            return true;
        }
        return self::shareLevel($kbId, $user) !== null;
    }

    /** Un partage en écriture existe-t-il pour cet utilisateur ? */
    private static function shareCanManage(int $kbId, array $user): bool
    {
        return self::shareLevel($kbId, $user) === 1;
    }

    /**
     * Niveau du partage reçu : 1 (écriture), 0 (lecture/rattachement),
     * null si aucun partage. Nominatif et groupes confondus, le plus permissif
     * l'emporte.
     */
    private static function shareLevel(int $kbId, array $user): ?int
    {
        $userId   = (int)($user['id'] ?? 0);
        $groupIds = self::activeGroupIdsFor($userId);
        $groupIn  = $groupIds === [] ? 'NULL' : implode(',', array_fill(0, count($groupIds), '?'));

        $stmt = Database::getInstance()->prepare(
            'SELECT MAX(can_manage) FROM knowledge_base_shares
             WHERE kb_id = ? AND (user_id = ? OR group_id IN (' . $groupIn . '))'
        );
        $stmt->execute(array_merge([$kbId, $userId], $groupIds));
        $level = $stmt->fetchColumn();
        return $level === false || $level === null ? null : (int)$level;
    }

    /**
     * Base système (« MIA : mode d'emploi ») : son contenu vient des guides
     * versionnés et est resynchronisé à chaque déploiement par DefaultAssistant.
     * Toute modification manuelle serait écrasée ou priverait l'assistant d'aide
     * de son savoir → suppression, documents et liaisons verrouillés en admin.
     * Seule la réindexation reste ouverte (changement de modèle d'embedding).
     */
    public static function isSystem(int $kbId): bool
    {
        $kb = self::findById($kbId);
        return $kb !== null && (int)($kb['is_system'] ?? 0) === 1;
    }

    /** Nombre de bases appartenant à un créateur (pour le quota). */
    public static function countByCreator(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM knowledge_bases WHERE created_by = ?'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    // ===================== Écriture =====================

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        // Une base en direct n'a rien à vectoriser : son trio embedding_* vaut
        // '' / '' / 0, quoi que l'appelant ait passé.
        $remote = ($data['backend'] ?? self::BACKEND_LOCAL) === self::BACKEND_MYBRAIN;
        $db->prepare(
            'INSERT INTO knowledge_bases
                (name, description, backend, embedding_provider, embedding_model, embedding_dims, is_active, is_system, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            (string)$data['name'],
            $data['description'] ?? null,
            $remote ? self::BACKEND_MYBRAIN : self::BACKEND_LOCAL,
            $remote ? '' : (string)$data['embedding_provider'],
            $remote ? '' : (string)$data['embedding_model'],
            $remote ? 0 : (int)$data['embedding_dims'],
            !empty($data['is_active']) ? 1 : 0,
            !empty($data['is_system']) ? 1 : 0,
            isset($data['created_by']) && $data['created_by'] !== null ? (int)$data['created_by'] : null,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Métadonnées éditables : nom, description, statut. Le trio embedding_*
     * est figé à la création (dims = taille de la collection Qdrant) ; il ne
     * change que via la réindexation (setEmbedding + re-upsert complet).
     */
    public static function update(int $id, array $data): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_bases SET name = ?, description = ?, is_active = ? WHERE id = ?'
        )->execute([
            (string)$data['name'],
            $data['description'] ?? null,
            !empty($data['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    /** Changement de modèle d'embedding (préalable à une réindexation complète). */
    public static function setEmbedding(int $id, string $provider, string $model, int $dims): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_bases SET embedding_provider = ?, embedding_model = ?, embedding_dims = ? WHERE id = ?'
        )->execute([$provider, $model, $dims, $id]);
    }

    // ============ Synchronisation d'un dossier distant (Drive / SharePoint) ============

    /**
     * Configure (ou déconfigure : provider/ref null) la source distante d'une base.
     * Un fournisseur ou un dossier différent remet l'état de sync à zéro : le
     * prochain cycle repart de rien et l'ancien contenu disparaît naturellement
     * du diff. Provider null = synchronisation retirée (tout NULL, désactivée).
     */
    /**
     * $ownerUserId (Drive seulement) : la sync lit le dossier avec la
     * connexion Google Drive de cet utilisateur (partage nominatif) ; null =
     * clé API globale (dossier partagé « par lien »).
     */
    public static function setSyncConfig(int $id, ?string $provider, ?string $folderRef, bool $enabled, ?int $ownerUserId = null): void
    {
        if ($provider === null || $folderRef === null) {
            $provider = null;
            $folderRef = null;
        }
        if ($provider === null || !KbSync::supportsOwnerAuth($provider)) {
            // Le mode « connexion personnelle » n'existe que pour Google Drive
            // et OneDrive : SharePoint et Google Agenda passent par des
            // identifiants globaux.
            $ownerUserId = null;
        }
        $kb      = self::findById($id);
        $changed = $kb === null
            || (string)($kb['sync_provider'] ?? '') !== (string)$provider
            || (string)($kb['sync_folder_ref'] ?? '') !== (string)$folderRef
            || (int)($kb['sync_owner_user_id'] ?? 0) !== (int)$ownerUserId;

        if ($changed) {
            Database::getInstance()->prepare(
                'UPDATE knowledge_bases
                 SET sync_provider = ?, sync_folder_ref = ?, sync_owner_user_id = ?, sync_folder_name = NULL,
                     sync_enabled = ?, sync_last_at = NULL, sync_status = NULL,
                     sync_error = NULL, sync_report = NULL
                 WHERE id = ?'
            )->execute([$provider, $folderRef, $ownerUserId, $enabled && $folderRef !== null ? 1 : 0, $id]);
            return;
        }
        Database::getInstance()->prepare(
            'UPDATE knowledge_bases SET sync_enabled = ? WHERE id = ?'
        )->execute([$enabled && $folderRef !== null ? 1 : 0, $id]);
    }

    /**
     * Reflète l'état d'un cycle de synchronisation. Clés acceptées :
     * status ('syncing'|'ok'|'error'), error, report (JSON), folder_name,
     * touch_last_sync (bool : pose sync_last_at = NOW()).
     */
    public static function setSyncState(int $id, array $state): void
    {
        $sets   = [];
        $params = [];
        if (array_key_exists('status', $state)) {
            $sets[]   = 'sync_status = ?';
            $params[] = $state['status'];
        }
        if (array_key_exists('error', $state)) {
            $sets[]   = 'sync_error = ?';
            $params[] = $state['error'] !== null ? mb_substr((string)$state['error'], 0, 500) : null;
        }
        if (array_key_exists('report', $state)) {
            $sets[]   = 'sync_report = ?';
            $params[] = $state['report'];
        }
        if (array_key_exists('folder_name', $state)) {
            $sets[]   = 'sync_folder_name = ?';
            $params[] = $state['folder_name'] !== null ? mb_substr((string)$state['folder_name'], 0, 255) : null;
        }
        if (!empty($state['touch_last_sync'])) {
            $sets[] = 'sync_last_at = NOW()';
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        Database::getInstance()->prepare(
            'UPDATE knowledge_bases SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);
    }

    /**
     * Activité de fond en cours (migration 072) — SEUL écrivain de
     * `activity_json`. `$activity` : kind ('sync'|'reindex'), phase, done,
     * total, current (nom du fichier traité) ; null = passage terminé. Posée
     * à chaque étape par KbSync::runSync et reindex() : c'est ce qui permet à
     * l'écran d'afficher « 12 fichiers sur 40 » pendant que ça tourne.
     *
     * Best-effort : une progression d'affichage ne fait jamais échouer le
     * passage qu'elle décrit (colonne pas encore migrée, base supprimée…).
     */
    public static function setActivity(int $id, ?array $activity): void
    {
        try {
            $json = $activity === null
                ? null
                : json_encode($activity + ['at' => time()], JSON_UNESCAPED_UNICODE);
            Database::getInstance()->prepare(
                'UPDATE knowledge_bases SET activity_json = ? WHERE id = ?'
            )->execute([$json ?: null, $id]);
        } catch (\Throwable $e) {
            error_log('[KB] progression base ' . $id . ' : ' . $e->getMessage());
        }
    }

    /**
     * Bases dont la synchronisation distante est due (activée + jamais
     * synchronisée ou intervalle écoulé), restreinte aux fournisseurs dont les
     * identifiants sont configurés. Utilisé par le déclencheur paresseux.
     *
     * @param list<string> $providers
     * @return list<array{id:int, sync_provider:string}>
     */
    /**
     * $includeDriveOwner : inclut aussi les bases Drive en mode « connexion
     * du propriétaire » (sync_owner_user_id), qui tournent sans clé API
     * globale — donc même quand 'drive' est absent de $providers.
     */
    public static function dueForSync(int $intervalMin, array $providers, int $limit = 10, bool $includeDriveOwner = false): array
    {
        $providers = array_values(array_filter(array_map('strval', $providers)));
        if ($providers === [] && !$includeDriveOwner) {
            return [];
        }
        $clauses = [];
        $params  = [];
        if ($providers !== []) {
            $clauses[] = 'sync_provider IN (' . implode(',', array_fill(0, count($providers), '?')) . ')';
            $params    = $providers;
        }
        if ($includeDriveOwner) {
            $clauses[] = "(sync_provider = 'drive' AND sync_owner_user_id IS NOT NULL)";
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, sync_provider FROM knowledge_bases
             WHERE sync_enabled = 1
               AND sync_folder_ref IS NOT NULL
               AND (' . implode(' OR ', $clauses) . ')
               AND is_system = 0
               AND (sync_last_at IS NULL OR sync_last_at <= NOW() - INTERVAL ? MINUTE)
             ORDER BY sync_last_at IS NULL DESC, sync_last_at ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(array_merge($params, [$intervalMin]));
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'sync_provider' => (string)$r['sync_provider']],
            $stmt->fetchAll()
        );
    }

    /**
     * Supprime la base : fichiers disque + ligne (documents, chunks et liaisons
     * suivent par CASCADE). Le drop de la collection Qdrant est orchestré par
     * l'appelant (best effort, avant l'appel).
     */
    public static function delete(int $id): void
    {
        $dir = self::storageDirFor($id);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
        Database::getInstance()->prepare('DELETE FROM knowledge_bases WHERE id = ?')->execute([$id]);
    }

    // ===================== Partage =====================

    /**
     * Cibles pour lesquelles la base est « cochée par défaut » (migration 073),
     * prêtes à pré-cocher un formulaire : ['users' => [ids], 'groups' => [ids]].
     */
    public static function defaultOnFor(int $kbId): array
    {
        return self::shareTargetsWhere($kbId, 'default_on');
    }

    /**
     * Cibles qui « peuvent nourrir » la base (migration 074), même forme que
     * defaultOnFor().
     */
    public static function feedersFor(int $kbId): array
    {
        return self::shareTargetsWhere($kbId, 'can_feed');
    }

    /**
     * Cet utilisateur peut-il proposer un texte à cette base en direct ?
     * Base MyBrain active, et : administrateur, ou un partage « peut nourrir »
     * qui le vise — nominativement ou par un de ses groupes ACTIFS (même règle
     * que la lecture : un groupe désactivé n'ouvre rien).
     *
     * Le droit de la CLÉ (« nourrir », côté MyBrain) est une autre condition,
     * vérifiée par l'appelant sur la connexion (MyBrainFeed::feedableBases) :
     * la clé fixe le maximum, les groupes décident qui.
     */
    public static function canFeed(array $kb, array $user): bool
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || !self::isRemote($kb) || empty($kb['is_active'])) {
            return false;
        }
        if (Role::userCan($user, 'knowledge_bases.manage')) {
            return true;
        }
        $groupIds = self::activeGroupIdsFor($userId);
        $groupIn  = $groupIds === [] ? 'NULL' : implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM knowledge_base_shares
              WHERE kb_id = ? AND can_feed = 1 AND (user_id = ? OR group_id IN (' . $groupIn . ')) LIMIT 1'
        );
        $stmt->execute(array_merge([(int)$kb['id'], $userId], $groupIds));
        return $stmt->fetchColumn() !== false;
    }

    /** Cibles d'un partage portant ce drapeau (`default_on`, `can_feed`). */
    private static function shareTargetsWhere(int $kbId, string $flag): array
    {
        if (!in_array($flag, ['default_on', 'can_feed'], true)) {
            throw new \InvalidArgumentException('drapeau de partage inconnu : ' . $flag);
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT user_id, group_id FROM knowledge_base_shares WHERE kb_id = ? AND ' . $flag . ' = 1'
        );
        $stmt->execute([$kbId]);

        $out = ['users' => [], 'groups' => []];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['user_id'] !== null) {
                $out['users'][] = (int)$row['user_id'];
            } elseif ($row['group_id'] !== null) {
                $out['groups'][] = (int)$row['group_id'];
            }
        }
        return $out;
    }

    /**
     * Bases à cocher d'office dans une NOUVELLE conversation de cet
     * utilisateur : un partage « cochée par défaut » le vise, nominativement
     * ou par un de ses groupes ACTIFS, sur une base active et cochable.
     *
     * Passe par selectableForUser() et unavailableReason() — le périmètre et
     * le grisé du sélecteur : un défaut ne peut pas cocher ce que
     * l'utilisateur ne pourrait pas cocher lui-même. Un admin n'en reçoit que
     * par un partage qui le vise, comme tout le monde : qu'il voie toutes les
     * bases ne fait pas de toutes ses bases par défaut.
     *
     * @return list<int>
     */
    public static function defaultOnIdsForUser(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }
        $groupIds = self::activeGroupIdsFor($userId);
        $groupIn  = $groupIds === [] ? 'NULL' : implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = Database::getInstance()->prepare(
            'SELECT DISTINCT kb_id FROM knowledge_base_shares
              WHERE default_on = 1 AND (user_id = ? OR group_id IN (' . $groupIn . '))'
        );
        $stmt->execute(array_merge([$userId], $groupIds));
        $wanted = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($wanted === []) {
            return [];
        }

        $out = [];
        foreach (self::selectableForUser($user) as $kb) {
            if (isset($wanted[(int)$kb['id']]) && self::unavailableReason($kb) === null) {
                $out[] = (int)$kb['id'];
            }
        }
        return $out;
    }

    /**
     * Partages posés sur une base, prêts à pré-cocher un formulaire :
     * ['users' => [userId => canManage], 'groups' => [groupId => canManage]].
     */
    public static function sharesFor(int $kbId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT user_id, group_id, can_manage FROM knowledge_base_shares WHERE kb_id = ?'
        );
        $stmt->execute([$kbId]);

        $out = ['users' => [], 'groups' => []];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['user_id'] !== null) {
                $out['users'][(int)$row['user_id']] = (int)$row['can_manage'];
            } elseif ($row['group_id'] !== null) {
                $out['groups'][(int)$row['group_id']] = (int)$row['can_manage'];
            }
        }
        return $out;
    }

    /**
     * Remplace l'intégralité des partages d'une base. Réservé à l'appelant qui
     * a passé ownedBy() : un bénéficiaire ne re-partage pas ce qu'on lui prête.
     *
     * Base en direct (MyBrain) : `can_manage` est forcé à 0 — sa connexion
     * (une clé émise par l'administrateur de MyBrain) reste aux
     * administrateurs de MIA —, `$defaultOn` pose « cochée par défaut » et
     * `$canFeed` « peut nourrir ». Sur une base locale, les deux sont ignorés :
     * l'écran ne les propose pas.
     *
     * @param array<int,int> $users     userId  => can_manage (0|1)
     * @param array<int,int> $groups    groupId => can_manage (0|1)
     * @param array{users?: list<int>, groups?: list<int>} $defaultOn cibles « cochée par défaut »
     * @param array{users?: list<int>, groups?: list<int>} $canFeed   cibles « peut nourrir »
     */
    public static function setShares(int $kbId, array $users, array $groups, array $defaultOn = [], array $canFeed = []): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM knowledge_base_shares WHERE kb_id = ?')->execute([$kbId]);

        $kb = self::findById($kbId);
        $creatorId  = $kb !== null && $kb['created_by'] !== null ? (int)$kb['created_by'] : null;
        $remote     = $kb !== null && self::isRemote($kb);
        $defUsers   = $remote ? array_flip(array_map('intval', $defaultOn['users'] ?? [])) : [];
        $defGroups  = $remote ? array_flip(array_map('intval', $defaultOn['groups'] ?? [])) : [];
        $feedUsers  = $remote ? array_flip(array_map('intval', $canFeed['users'] ?? [])) : [];
        $feedGroups = $remote ? array_flip(array_map('intval', $canFeed['groups'] ?? [])) : [];

        $ins = $db->prepare(
            'INSERT INTO knowledge_base_shares (kb_id, user_id, group_id, can_manage, default_on, can_feed) VALUES (?,?,?,?,?,?)'
        );
        foreach ($users as $userId => $canManage) {
            $userId = (int)$userId;
            // Se partager sa propre base n'a pas de sens : il y a déjà tous les droits.
            if ($userId <= 0 || $userId === $creatorId) {
                continue;
            }
            $ins->execute([$kbId, $userId, null, !$remote && $canManage ? 1 : 0,
                isset($defUsers[$userId]) ? 1 : 0, isset($feedUsers[$userId]) ? 1 : 0]);
        }
        foreach ($groups as $groupId => $canManage) {
            $groupId = (int)$groupId;
            if ($groupId <= 0) {
                continue;
            }
            $ins->execute([$kbId, null, $groupId, !$remote && $canManage ? 1 : 0,
                isset($defGroups[$groupId]) ? 1 : 0, isset($feedGroups[$groupId]) ? 1 : 0]);
        }
    }

    // ===================== Porte des bases en direct =====================

    /**
     * Retire des bases données celles EN DIRECT que cet utilisateur n'a pas le
     * droit d'interroger. Les bases locales passent telles quelles.
     *
     * Le point de la décision : une base MyBrain suit la PERSONNE, jamais
     * l'assistant. Une base locale liée à un assistant sert tous ceux qui
     * ouvrent l'assistant — c'est son contrat. Pour MyBrain, ce serait une
     * porte dérobée : un créateur qui a reçu la base par son groupe la lierait
     * à un assistant ouvert à un autre groupe, et le cerveau de l'équipe
     * répondrait à des personnes que l'administrateur n'a pas choisies. Le
     * droit est donc revérifié à chaque tour, par le même périmètre que le
     * sélecteur (selectableForUser : partage nominatif ou groupe actif,
     * admin) — et sans utilisateur connu, aucune base en direct ne passe.
     *
     * @param list<array<string, mixed>> $kbs
     * @return list<array<string, mixed>>
     */
    public static function gateForUser(array $kbs, ?array $user): array
    {
        [$remote] = self::partitionByBackend($kbs);
        if ($remote === []) {
            return array_values($kbs);
        }
        $allowed = [];
        if ($user !== null && !empty($user['id'])) {
            foreach (self::selectableForUser($user) as $kb) {
                if (self::isRemote($kb)) {
                    $allowed[(int)$kb['id']] = true;
                }
            }
        }
        return array_values(array_filter(
            $kbs,
            static fn(array $kb): bool => !self::isRemote($kb) || isset($allowed[(int)$kb['id']])
        ));
    }

    /**
     * Bases d'un assistant que CET utilisateur fait interroger : forProfile()
     * passé par la porte des bases en direct. C'est elle que le chat appelle
     * (envoi, régénération, présentation, sélecteur) — forProfile() seul
     * ouvrirait MyBrain à quiconque ouvre l'assistant.
     *
     * @return list<array<string, mixed>>
     */
    public static function forProfileAndUser(int $profileId, ?array $user): array
    {
        return self::gateForUser(self::forProfile($profileId), $user);
    }

    // ===================== Liaisons N-N assistants =====================

    /** Bases ACTIVES liées à un assistant (utilisé par le chat). */
    public static function forProfile(int $profileId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT kb.*
             FROM knowledge_bases kb
             JOIN profile_knowledge_bases pkb ON pkb.kb_id = kb.id
             WHERE pkb.profile_id = ? AND kb.is_active = 1
             ORDER BY kb.name ASC'
        );
        $stmt->execute([$profileId]);
        return $stmt->fetchAll();
    }

    /** Ids des assistants liés à une base. */
    public static function profileIdsFor(int $kbId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT profile_id FROM profile_knowledge_bases WHERE kb_id = ?'
        );
        $stmt->execute([$kbId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Ids des bases liées à un assistant (pour pré-cocher les formulaires). */
    public static function kbIdsForProfile(int $profileId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT kb_id FROM profile_knowledge_bases WHERE profile_id = ?'
        );
        $stmt->execute([$profileId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Remplace les assistants liés à une base, EN SE LIMITANT au périmètre du
     * gestionnaire courant ($scopeProfileIds = assistants qu'il a le droit de
     * gérer). Les liaisons hors périmètre (posées par un admin sur des
     * assistants invisibles pour un creator) sont préservées.
     *
     * @param list<int>|array $profileIds       cases cochées dans le formulaire
     * @param list<int>|array $scopeProfileIds  assistants manageables par l'appelant
     */
    public static function setProfiles(int $kbId, array $profileIds, array $scopeProfileIds): void
    {
        $scope = array_values(array_unique(array_map('intval', $scopeProfileIds)));
        if ($scope === []) {
            return;
        }
        $wanted = array_values(array_intersect(
            array_unique(array_map('intval', $profileIds)),
            $scope
        ));

        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $db->prepare("DELETE FROM profile_knowledge_bases WHERE kb_id = ? AND profile_id IN ($ph)")
           ->execute(array_merge([$kbId], $scope));

        $ins = $db->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?,?)');
        foreach ($wanted as $pid) {
            if ($pid > 0) {
                $ins->execute([$pid, $kbId]);
            }
        }
    }

    /**
     * Miroir de setProfiles côté assistant : remplace les bases liées à un
     * assistant, en se limitant aux bases manageables par l'appelant
     * ($scopeKbIds). Les liaisons vers des bases hors périmètre sont préservées.
     *
     * @param list<int>|array $kbIds       cases cochées dans le formulaire
     * @param list<int>|array $scopeKbIds  bases manageables par l'appelant
     */
    public static function setForProfile(int $profileId, array $kbIds, array $scopeKbIds): void
    {
        $scope = array_values(array_unique(array_map('intval', $scopeKbIds)));
        if ($scope === []) {
            return;
        }
        $wanted = array_values(array_intersect(
            array_unique(array_map('intval', $kbIds)),
            $scope
        ));

        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $db->prepare("DELETE FROM profile_knowledge_bases WHERE profile_id = ? AND kb_id IN ($ph)")
           ->execute(array_merge([$profileId], $scope));

        $ins = $db->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?,?)');
        foreach ($wanted as $kid) {
            if ($kid > 0) {
                $ins->execute([$profileId, $kid]);
            }
        }
    }
}
