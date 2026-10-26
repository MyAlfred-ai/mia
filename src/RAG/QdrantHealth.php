<?php

declare(strict_types=1);

/**
 * Diagnostic du moteur vectoriel (Qdrant) et actions de remise en état —
 * source unique du panneau « Moteur vectoriel » de /admin/knowledge-bases.
 *
 * MySQL reste la source de vérité : une base a N chunks (knowledge_base_chunks)
 * et Qdrant doit porter autant de points (point id = id du chunk). Tout écart
 * entre les deux est un défaut visible ici, avec le geste qui le corrige :
 *   - collection absente      → « Réparer » (recréation aux dims de la base)
 *   - dimension incohérente   → « Réindexer » (drop + re-embed, seul chemin sûr)
 *   - 0 vecteur / incomplet   → « Réindexer »
 *   - collection sans base    → « Purger » (reliquat d'une base supprimée)
 *
 * Aucune méthode ne lève : Qdrant injoignable est un ÉTAT du rapport
 * (`up = false`), pas une exception — le panneau doit rester affichable
 * précisément quand le service est en panne.
 */
class QdrantHealth
{
    /** Verdicts possibles par base, du plus grave au plus bénin. */
    public const STATE_DOWN    = 'down';     // service injoignable
    public const STATE_MISSING = 'missing';  // collection absente
    public const STATE_DIMS    = 'dims';     // dimension ≠ modèle d'embedding de la base
    public const STATE_EMPTY   = 'empty';    // chunks en base, aucun vecteur
    public const STATE_PARTIAL = 'partial';  // moins de vecteurs que de chunks
    public const STATE_RUNNING = 'running';  // écart explicable par une ingestion en cours
    public const STATE_FAILED  = 'failed';   // vecteurs complets mais documents en échec
    public const STATE_VOID    = 'void';     // base sans aucun document
    public const STATE_OK      = 'ok';

    /** Verdicts qui appellent une action de l'admin (compteur du bandeau). */
    private const PROBLEM_STATES = [
        self::STATE_DOWN, self::STATE_MISSING, self::STATE_DIMS,
        self::STATE_EMPTY, self::STATE_PARTIAL, self::STATE_FAILED,
    ];

    /**
     * Rapport complet : santé du service + une ligne par base + collections
     * orphelines. Ne lève jamais.
     *
     * @return array{
     *   up:bool, url:string, version:?string, error:?string,
     *   collections:int, points:int, issues:int,
     *   bases:list<array>, orphans:list<array{name:string,points:int}>,
     *   checked_at:string
     * }
     */
    public static function report(): array
    {
        $store = new QdrantStore();
        $out   = [
            'up'          => false,
            'url'         => $store->baseUrl(),
            'version'     => null,
            'error'       => null,
            'collections' => 0,
            'points'      => 0,
            'issues'      => 0,
            'bases'       => [],
            'orphans'     => [],
            'checked_at'  => date('Y-m-d H:i:s'),
        ];

        $collections = [];
        try {
            $collections = $store->listCollections();
            $out['up']      = true;
            $out['version'] = $store->version();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        $out['collections'] = count($collections);
        $known              = array_fill_keys($collections, true);

        foreach (self::basesWithCounts() as $kb) {
            $kbId       = (int)$kb['id'];
            $collection = KnowledgeBase::collectionName($kbId);
            unset($known[$collection]);

            $info = ($out['up'] && in_array($collection, $collections, true))
                ? $store->collectionInfo($collection)
                : null;

            $points = $info !== null ? (int)$info['points'] : 0;
            $out['points'] += $points;

            $row = [
                'id'           => $kbId,
                'name'         => (string)$kb['name'],
                'collection'   => $collection,
                'is_system'    => (int)($kb['is_system'] ?? 0) === 1,
                'model'        => (string)$kb['embedding_model'],
                'dims'         => (int)$kb['embedding_dims'],
                'actual_dims'  => $info !== null ? (int)$info['dims'] : null,
                'docs'         => (int)$kb['doc_count'],
                'chunks'       => (int)$kb['chunk_count'],
                'points'       => $info !== null ? $points : null,
                'pending_docs' => (int)$kb['pending_docs'],
                'failed_docs'  => (int)$kb['failed_docs'],
            ];
            $row['state'] = self::stateFor($out['up'], $info !== null, $row);
            $row['label'] = self::stateLabel($row['state'], $row);
            $row['hint']  = self::stateHint($row['state'], $row);
            if (in_array($row['state'], self::PROBLEM_STATES, true)) {
                $out['issues']++;
            }
            $out['bases'][] = $row;
        }

        // Reliquat : collections kb_* sans base MySQL (base supprimée alors que
        // Qdrant était en panne). Les collections hors nommage kb_* ne sont pas
        // à nous — jamais proposées à la purge.
        foreach (array_keys($known) as $name) {
            if (preg_match('/^kb_\d+$/', (string)$name) !== 1) {
                continue;
            }
            $info = $store->collectionInfo((string)$name);
            $out['orphans'][] = ['name' => (string)$name, 'points' => $info !== null ? (int)$info['points'] : 0];
            $out['points']   += $info !== null ? (int)$info['points'] : 0;
        }
        if ($out['orphans'] !== []) {
            $out['issues'] += count($out['orphans']);
        }

        return $out;
    }

    /**
     * « Réparer » : (re)crée les collections manquantes aux dimensions
     * déclarées par chaque base. Ne touche à AUCUNE collection existante —
     * une dimension incohérente se corrige par une réindexation, qui seule
     * reconstruit les vecteurs. Ne lève jamais.
     *
     * @return array{ok:bool, created:list<string>, failed:list<string>, error:?string}
     */
    public static function repair(): array
    {
        $store = new QdrantStore();
        $res   = ['ok' => true, 'created' => [], 'failed' => [], 'error' => null];

        try {
            $existing = $store->listCollections();
        } catch (\Throwable $e) {
            return ['ok' => false, 'created' => [], 'failed' => [], 'error' => $e->getMessage()];
        }

        foreach (self::basesWithCounts() as $kb) {
            $collection = KnowledgeBase::collectionName((int)$kb['id']);
            if (in_array($collection, $existing, true)) {
                continue;
            }
            try {
                $store->ensureCollection($collection, (int)$kb['embedding_dims']);
                $res['created'][] = $collection;
            } catch (\Throwable $e) {
                $res['failed'][] = $collection;
                error_log('[KB] réparation collection ' . $collection . ' : ' . $e->getMessage());
            }
        }
        $res['ok'] = $res['failed'] === [];
        return $res;
    }

    /**
     * « Purger » : supprime les collections kb_* sans base MySQL. Ne lève
     * jamais. Une collection dont la base existe n'est JAMAIS touchée.
     *
     * @return array{ok:bool, dropped:list<string>, error:?string}
     */
    public static function prune(): array
    {
        $store = new QdrantStore();
        try {
            $collections = $store->listCollections();
        } catch (\Throwable $e) {
            return ['ok' => false, 'dropped' => [], 'error' => $e->getMessage()];
        }

        $alive = [];
        foreach (self::basesWithCounts() as $kb) {
            $alive[KnowledgeBase::collectionName((int)$kb['id'])] = true;
        }

        $dropped = [];
        foreach ($collections as $name) {
            if (isset($alive[$name]) || preg_match('/^kb_\d+$/', $name) !== 1) {
                continue;
            }
            try {
                $store->dropCollection($name);
                $dropped[] = $name;
            } catch (\Throwable $e) {
                error_log('[KB] purge collection ' . $name . ' : ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'dropped' => $dropped, 'error' => null];
    }

    // ===================== Interne =====================

    /**
     * Verdict d'une base. Logique pure (testable) : l'ordre des tests est celui
     * de la gravité — un service en panne masque tout le reste, une collection
     * absente rend les compteurs de vecteurs sans objet, et un écart
     * chunks/points s'explique d'abord par une ingestion en cours.
     *
     * @param array{chunks:int,docs:int,dims:int,actual_dims:?int,points:?int,pending_docs:int,failed_docs:int} $row
     */
    public static function stateFor(bool $up, bool $hasCollection, array $row): string
    {
        if (!$up) {
            return self::STATE_DOWN;
        }
        if (!$hasCollection) {
            return ($row['docs'] === 0 && $row['chunks'] === 0) ? self::STATE_VOID : self::STATE_MISSING;
        }
        if ((int)$row['actual_dims'] > 0 && (int)$row['actual_dims'] !== (int)$row['dims']) {
            return self::STATE_DIMS;
        }
        if ($row['docs'] === 0 && $row['chunks'] === 0) {
            return self::STATE_VOID;
        }
        $points = (int)$row['points'];
        if ($points < (int)$row['chunks']) {
            if ((int)$row['pending_docs'] > 0) {
                return self::STATE_RUNNING; // ingestion/vectorisation en cours
            }
            return $points === 0 ? self::STATE_EMPTY : self::STATE_PARTIAL;
        }
        if ((int)$row['failed_docs'] > 0) {
            return self::STATE_FAILED;
        }
        return self::STATE_OK;
    }

    /**
     * Libellé court d'un verdict (badge). Volontairement bref : c'est la
     * première colonne d'une grille, un libellé long décalerait le nom de la
     * base d'une ligne à l'autre. Le détail va dans stateHint().
     */
    public static function stateLabel(string $state, array $row): string
    {
        return match ($state) {
            self::STATE_DOWN    => t('Moteur injoignable'),
            self::STATE_MISSING => t('Collection absente'),
            self::STATE_DIMS    => t('Dimension incohérente'),
            self::STATE_EMPTY   => t('Aucun vecteur'),
            self::STATE_PARTIAL => t('Vectorisation incomplète'),
            self::STATE_RUNNING => t('Vectorisation en cours'),
            self::STATE_FAILED  => I18n::plural((int)$row['failed_docs'], '{n} document en échec', '{n} documents en échec'),
            self::STATE_VOID    => t('Base vide'),
            default             => t('Opérationnelle'),
        };
    }

    /** Explication + geste correctif (infobulle du badge). */
    public static function stateHint(string $state, array $row): string
    {
        return match ($state) {
            self::STATE_DOWN    => t('Qdrant ne répond pas : la base est interrogée en recherche texte.'),
            self::STATE_MISSING => t('La collection vectorielle n\'existe pas. « Réparer » la recrée, puis « Réindexer » y remet les vecteurs.'),
            self::STATE_DIMS    => t(
                'La collection est en {reelles} dimensions alors que le modèle d\'embedding de la base en produit {attendues}. Les vecteurs sont inexploitables : réindexez.',
                ['reelles' => (int)$row['actual_dims'], 'attendues' => (int)$row['dims']]
            ),
            self::STATE_EMPTY   => t('Des extraits existent en base mais aucun vecteur : la recherche se fait en mode texte, moins pertinente. Réindexez.'),
            self::STATE_PARTIAL => t('Moins de vecteurs que d\'extraits : une partie des documents n\'est pas interrogeable en recherche sémantique. Réindexez.'),
            self::STATE_RUNNING => t('Une vectorisation est en cours : l\'écart va se combler seul.'),
            self::STATE_FAILED  => t('Les vecteurs sont complets mais des documents sont en échec — ouvrez la base pour voir lesquels.'),
            self::STATE_VOID    => t('Aucun document : rien à vectoriser.'),
            default             => t('Extraits et vecteurs concordent.'),
        };
    }

    /**
     * Bases + volumétrie MySQL et compteurs de documents par statut vectoriel.
     * Sous-requêtes scalaires (pas de GROUP BY — ONLY_FULL_GROUP_BY).
     */
    private static function basesWithCounts(): array
    {
        return Database::getInstance()->query(
            "SELECT kb.id, kb.name, kb.is_system, kb.embedding_model, kb.embedding_dims,
                    (SELECT COUNT(*) FROM knowledge_base_documents d WHERE d.kb_id = kb.id) AS doc_count,
                    (SELECT COUNT(*) FROM knowledge_base_chunks  c WHERE c.kb_id  = kb.id) AS chunk_count,
                    (SELECT COUNT(*) FROM knowledge_base_documents d
                      WHERE d.kb_id = kb.id AND d.vector_status IN ('pending', 'processing')) AS pending_docs,
                    (SELECT COUNT(*) FROM knowledge_base_documents d
                      WHERE d.kb_id = kb.id AND d.vector_status = 'failed') AS failed_docs
             FROM knowledge_bases kb
             ORDER BY kb.name ASC"
        )->fetchAll();
    }
}
