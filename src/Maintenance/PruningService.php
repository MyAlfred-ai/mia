<?php

declare(strict_types=1);

/**
 * Élagage des conversations et des créations anciennes (/admin/pruning).
 *
 * Trois opérations, toutes MANUELLES (aucun déclencheur automatique) :
 *   - elaguerConversations() : supprime les conversations inactives depuis N
 *     jours, SAUF les épinglées ;
 *   - elaguerCreations()     : NEUTRALISE les créations de plus de N jours — le
 *     message reste dans le fil, son contenu devient un marqueur daté ;
 *   - balayerOrphelins()     : supprime du disque les fichiers que plus aucun
 *     message ne référence.
 *
 * SYNCHRONE, BORNÉ, REPRENABLE — et non un job de maintenance. La page doit
 * afficher des chiffres à jour juste après l'action : un job rendrait la main
 * tout de suite et imposerait de rejouer l'appareillage de /admin/credits
 * (bandeau, GET /jobs/active, rechargement) pour un écran utilisé deux fois par
 * an. En plus, MaintenanceJob refuse une op sans cible (`$id <= 0` lève) et le
 * worker est mutualisé : un élagage de cinq minutes bloquerait toutes les
 * générations derrière lui. Quand le budget mural tombe, on s'arrête proprement
 * et le rapport annonce ce qu'il reste — l'admin voit sa progression à chaque
 * clic et rien ne peut rester coincé en file.
 */
final class PruningService
{
    /** Rétention par défaut, en jours, pour les deux élagages. */
    public const JOURS_DEFAUT = 120;
    public const JOURS_MIN    = 1;
    public const JOURS_MAX    = 3650;

    public const CLE_JOURS_CONV     = 'pruning_conversations_days';
    public const CLE_JOURS_CREATION = 'pruning_creations_days';

    /**
     * Un fichier plus jeune que ça n'est JAMAIS balayé, même s'il n'est
     * référencé nulle part. Trois fenêtres réelles où un fichier vivant n'a
     * pas encore de message : illustration d'une page en cours de génération
     * (HtmlIllustrator écrit les images bien avant que le message html_page
     * n'existe), job en attente si le worker est arrêté, et pièce jointe dont
     * le jeton dort en session parce que l'utilisateur n'a pas encore envoyé.
     * 24 h de latence sur un ramasse-miettes ne coûtent rien ; supprimer un
     * fichier vivant casse une conversation.
     */
    public const AGE_MIN_ORPHELIN_SEC = 86400;

    /**
     * Dossiers balayés, relatifs à ROOT. Volontairement DISJOINTS de
     * StorageInfo::DOCUMENT_DIRS : storage/knowledge_bases et
     * storage/profile_documents sont référencés par knowledge_base_documents et
     * profile_documents, PAS par messages — les balayer avec l'index de
     * références des messages les effacerait intégralement.
     */
    public const DOSSIERS_BALAYES = [
        '/storage/uploads',
        '/public/assets/img/generated',
        '/public/assets/video/generated',
    ];

    /**
     * Budget mural d'une exécution. En dessous du timeout de tout proxy
     * raisonnable : on rend la main et on annonce le reste plutôt que de faire
     * tomber la requête au milieu d'un lot.
     */
    private const BUDGET_SEC = 25;

    private const LOT_CONV     = 200;
    private const LOT_CREATION = 100;
    private const LOT_SCAN     = 100;

    /**
     * Contenu au-delà duquel un message « prose + images » n'est PAS réécrit.
     * Une sortie de LLM fait quelques dizaines de Ko ; au-delà, on saute et on
     * le dit dans le rapport plutôt que de risquer une réécriture tronquée —
     * une perte de données silencieuse.
     */
    private const CONTENU_MAX_REECRITURE = 1048576;

    /**
     * Longueur de contenu remontée pour la DÉTECTION du type de création.
     * Une création « image seule » fait quelques centaines d'octets ; au-delà,
     * le message porte forcément autre chose et part en chirurgie.
     */
    private const CONTENU_DETECTION = 4000;

    private const CACHE_FILE = '/storage/cache/pruning_stats.json';
    private const CACHE_TTL  = 600;

    /** Verrou MySQL : un seul élagage à la fois, tous processus confondus. */
    private const VERROU = 'mia_pruning';

    /**
     * Motif de TOUTE mention d'un média dans un texte, quelle que soit la
     * syntaxe. DISTINCT de Message::MOTIF_IMAGE_GENEREE, qui parse du markdown :
     * celui-ci attrape aussi les <img src> d'une page illustrée
     * (HtmlIllustrator) et les "image":"…" d'une spec de deck. Ne PAS les
     * fusionner — un balayage qui ne verrait que le markdown supprimerait les
     * images d'une page HTML encore affichée. Les textes analysés sont
     * dés-échappés au préalable (cf. indexerReferences).
     */
    private const MOTIF_REFERENCE =
        '#(?:/assets/img/generated/|/assets/video/generated/|/storage/uploads/)([A-Za-z0-9._-]+)#';

    // ---------------------------------------------------------------- Réglages

    /**
     * Normalise un nombre de jours saisi. Pure, testée.
     * `''` et `'0'` sont falsy → retour au DÉFAUT, jamais à « tout élaguer ».
     */
    public static function joursValides(mixed $brut): int
    {
        $jours = (int)$brut;
        if ($jours === 0) {
            return self::JOURS_DEFAUT;
        }
        return max(self::JOURS_MIN, min(self::JOURS_MAX, $jours));
    }

    /** Rétention configurée pour les conversations. */
    public static function joursConversations(): int
    {
        return self::joursValides(Settings::get(self::CLE_JOURS_CONV, (string)self::JOURS_DEFAUT));
    }

    /** Rétention configurée pour les créations. */
    public static function joursCreations(): int
    {
        return self::joursValides(Settings::get(self::CLE_JOURS_CREATION, (string)self::JOURS_DEFAUT));
    }

    /** Date-seuil SQL correspondant à N jours d'ancienneté. */
    public static function seuil(int $jours): string
    {
        return date('Y-m-d H:i:s', time() - $jours * 86400);
    }

    // ------------------------------------------------------------ Statistiques

    /**
     * État des lieux, mis en cache CACHE_TTL secondes.
     *
     * Le coût n'est pas dans le nombre de lignes mais dans le volume hors-page :
     * `content` et `files_json` sont des LONGTEXT, et files_json porte le base64
     * des pièces jointes (jusqu'à ~13 Mo pour un fichier de 10 Mo). SUM(LENGTH())
     * force la lecture de toutes les pages de débordement — d'où le cache.
     *
     * @return array{
     *   conversations:array{total:int,epinglees:int,eligibles:int,octets:int,octets_eligibles:int},
     *   creations:array{total:int,eligibles:int,octets:int,octets_eligibles:int},
     *   disque:array{total:int,orphelins:int,orphelins_octets:int},
     *   mysql:int|null, jours_conversations:int, jours_creations:int, computed_at:string
     * }
     */
    public static function statistiques(bool $forcer = false): array
    {
        $cacheFile = ROOT . self::CACHE_FILE;
        if (!$forcer && is_file($cacheFile) && time() - (int)filemtime($cacheFile) < self::CACHE_TTL) {
            $cache = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cache) && isset($cache['conversations'], $cache['creations'], $cache['disque'])) {
                return $cache;
            }
        }

        $data = self::calculer();

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $data;
    }

    /** Jette l'instantané : le prochain appel recalcule (après tout élagage). */
    public static function invaliderCache(): void
    {
        @unlink(ROOT . self::CACHE_FILE);
    }

    private static function calculer(): array
    {
        $db            = Database::getInstance();
        $joursConv     = self::joursConversations();
        $joursCreation = self::joursCreations();

        // (a) Comptes — petite table sans blob, instantané.
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(c.is_pinned = 1), 0) AS epinglees,
                    COALESCE(SUM(c.is_pinned = 0 AND c.updated_at < ?), 0) AS eligibles
             FROM conversations c'
        );
        $stmt->execute([self::seuil($joursConv)]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // (b) Octets — un seul passage sur messages, agrégé par conversation.
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(t.o), 0) AS octets,
                    COALESCE(SUM(CASE WHEN c.is_pinned = 0 AND c.updated_at < ? THEN t.o END), 0) AS octets_eligibles
             FROM conversations c
             JOIN (SELECT conversation_id,
                          SUM(LENGTH(content)
                              + LENGTH(COALESCE(files_json, ""))
                              + LENGTH(COALESCE(file_extracted, ""))) AS o
                   FROM messages GROUP BY conversation_id) t
               ON t.conversation_id = c.id'
        );
        $stmt->execute([self::seuil($joursConv)]);
        $convOctets = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // (c) Créations — un passage, prédicat partagé avec la galerie.
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(m.created_at < ?), 0) AS eligibles,
                    COALESCE(SUM(LENGTH(m.content) + LENGTH(COALESCE(m.files_json, ""))), 0) AS octets,
                    COALESCE(SUM(CASE WHEN m.created_at < ?
                                 THEN LENGTH(m.content) + LENGTH(COALESCE(m.files_json, "")) END), 0) AS octets_eligibles
             FROM messages m
             WHERE ' . Message::creationPredicateSql('m')
        );
        $stmt->execute([self::seuil($joursCreation), self::seuil($joursCreation)]);
        $creations = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // (d) Disque : total mesuré, part orpheline évaluée à blanc.
        $disqueTotal = 0;
        foreach (self::DOSSIERS_BALAYES as $rel) {
            $disqueTotal += StorageInfo::dirBytes(ROOT . $rel);
        }
        $blanc = self::balayerOrphelins(null, true);

        return [
            'conversations' => [
                'total'            => (int)($conv['total'] ?? 0),
                'epinglees'        => (int)($conv['epinglees'] ?? 0),
                'eligibles'        => (int)($conv['eligibles'] ?? 0),
                'octets'           => (int)($convOctets['octets'] ?? 0),
                'octets_eligibles' => (int)($convOctets['octets_eligibles'] ?? 0),
            ],
            'creations' => [
                'total'            => (int)($creations['total'] ?? 0),
                'eligibles'        => (int)($creations['eligibles'] ?? 0),
                'octets'           => (int)($creations['octets'] ?? 0),
                'octets_eligibles' => (int)($creations['octets_eligibles'] ?? 0),
            ],
            'disque' => [
                'total'            => $disqueTotal,
                'orphelins'        => (int)$blanc['candidats'],
                'orphelins_octets' => (int)$blanc['octets'],
            ],
            'mysql'               => self::mysqlOctets(),
            'jours_conversations' => $joursConv,
            'jours_creations'     => $joursCreation,
            'computed_at'         => date('Y-m-d H:i'),
        ];
    }

    /**
     * Occupation InnoDB des trois tables conversationnelles.
     * ESTIMATION, et surtout : elle ne DIMINUE PAS après un DELETE (MySQL
     * réutilise l'espace au lieu de le rendre au système). La page doit le dire.
     */
    private static function mysqlOctets(): ?int
    {
        try {
            $stmt = Database::getInstance()->query(
                "SELECT COALESCE(SUM(data_length + index_length), 0)
                   FROM information_schema.TABLES
                  WHERE table_schema = DATABASE()
                    AND table_name IN ('conversations', 'messages', 'creation_shares')"
            );
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------- Élagage : conversations

    /**
     * Supprime les conversations inactives depuis N jours, hors épinglées.
     * Le SQL cascade seul (messages → creation_shares, drive_pending_actions,
     * async_jobs → job_events) ; usage_logs et tool_usage_logs passent en
     * SET NULL — volontaire (migration 029) : l'historique de consommation et
     * les quotas ne sont pas touchés.
     *
     * @return array{supprimees:int,restantes:int,octets:int,jours:int,
     *               verrou:bool,budget_atteint:bool,balayage:array}
     */
    public static function elaguerConversations(int $jours): array
    {
        $jours  = self::joursValides($jours);
        $rapport = self::rapportVide($jours) + ['supprimees' => 0, 'restantes' => 0, 'octets' => 0];

        if (!self::prendreVerrou()) {
            $rapport['verrou'] = false;
            return $rapport;
        }

        try {
            self::preparerExecutionLongue();

            $db      = Database::getInstance();
            $seuil   = self::seuil($jours);
            $debut   = time();
            $select  = $db->prepare(
                'SELECT c.id
                 FROM conversations c
                 WHERE c.is_pinned = 0
                   AND c.updated_at < ?
                   AND NOT EXISTS (SELECT 1 FROM messages m
                                   WHERE m.conversation_id = c.id AND m.created_at >= ?)
                   AND NOT EXISTS (SELECT 1 FROM async_jobs j
                                   WHERE j.conversation_id = c.id
                                     AND j.status IN ("pending", "running"))
                 ORDER BY c.id
                 LIMIT ' . self::LOT_CONV
            );

            while (true) {
                $select->execute([$seuil, $seuil]);
                $ids = $select->fetchAll(PDO::FETCH_COLUMN);
                if ($ids === []) {
                    break;
                }

                $rapport['octets'] += self::octetsDesConversations($ids);

                $in = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM conversations WHERE id IN ($in)")->execute($ids);
                $rapport['supprimees'] += count($ids);

                if (time() - $debut >= self::BUDGET_SEC) {
                    $rapport['budget_atteint'] = true;
                    break;
                }
            }

            // Reste à faire : compté APRÈS la boucle, donc juste et affichable.
            $rapport['restantes'] = self::compterConversationsEligibles($seuil);

            // Le balayage tourne DANS la même exécution : sans lui, les fichiers
            // des conversations supprimées deviendraient des orphelins définitifs.
            $rapport['balayage'] = self::balayerOrphelins();
        } finally {
            self::relacherVerrou();
        }

        self::invaliderCache();
        StorageInfo::invalidate();

        return $rapport;
    }

    // ----------------------------------------------------- Élagage : créations

    /**
     * Neutralise les créations de plus de N jours. Le message SURVIT : seul son
     * contenu est remplacé, le lien de partage révoqué, le média balayé.
     *
     * Deux populations, deux traitements :
     *  (a) création pure  → remplacement intégral (100 % SQL, aucun LONGTEXT
     *      remonté en PHP) ;
     *  (b) prose + images → chirurgie : la réponse rédigée reste, chaque image
     *      devient un marqueur inline. Tout remplacer détruirait la réponse et
     *      créerait exactement le trou qu'on veut éviter.
     *
     * @return array{neutralisees:int,images:int,ignorees:int,partages:int,
     *               jours:int,verrou:bool,budget_atteint:bool,balayage:array}
     */
    public static function elaguerCreations(int $jours): array
    {
        $jours   = self::joursValides($jours);
        $rapport = self::rapportVide($jours)
            + ['neutralisees' => 0, 'images' => 0, 'ignorees' => 0, 'partages' => 0];

        if (!self::prendreVerrou()) {
            $rapport['verrou'] = false;
            return $rapport;
        }

        try {
            self::preparerExecutionLongue();

            $db    = Database::getInstance();
            $seuil = self::seuil($jours);
            $jour  = date('Y-m-d');
            $debut = time();

            // Pagination par id CROISSANT, et non « les 100 premiers » : un
            // message sauté (contenu démesuré, cf. CONTENU_MAX_REECRITURE)
            // continuerait de matcher le prédicat et serait resélectionné à
            // l'infini. Avec `m.id > ?`, un traité comme un sauté est derrière.
            //
            // files_json est lu ENTIER, sans LEFT() : le prédicat impose
            // role = 'assistant', et un files_json d'assistant est écrit par
            // notre propre code (quelques dizaines d'octets). Le base64 des
            // pièces jointes vit dans les messages UTILISATEUR, hors périmètre.
            // Le contenu, lui, est borné — et une ligne tronquée est routée en
            // « mixte » (cf. plus bas), le côté prudent.
            $select = $db->prepare(
                'SELECT m.id, m.file_type, m.files_json,
                        LEFT(m.content, ' . self::CONTENU_DETECTION . ') AS content,
                        LENGTH(m.content) AS taille
                 FROM messages m
                 WHERE m.id > ?
                   AND m.created_at < ?
                   AND ' . Message::creationPredicateSql('m') . '
                 ORDER BY m.id
                 LIMIT ' . self::LOT_CREATION
            );
            $marqueur = Message::marqueurCreation($jour);
            $apres    = 0;

            while (true) {
                $select->execute([$apres, $seuil]);
                $lignes = $select->fetchAll(PDO::FETCH_ASSOC);
                if ($lignes === []) {
                    break;
                }

                $pures  = [];
                $mixtes = [];
                foreach ($lignes as $ligne) {
                    $apres = (int)$ligne['id'];

                    // Contenu tronqué : on le retire de la DÉTECTION. La regex
                    // « image seule » de generatedImageUrl() est ancrée sur la
                    // fin du contenu — sur un texte coupé, elle pourrait déclarer
                    // « image seule » un message qui porte en réalité de la prose
                    // au-delà de la coupure, et le remplacement intégral la
                    // détruirait. Les types riches, eux, ne dépendent pas du
                    // contenu : la détection reste juste.
                    $pourDetection = $ligne;
                    if ((int)$ligne['taille'] > self::CONTENU_DETECTION) {
                        $pourDetection['content'] = '';
                    }

                    if (Message::creationType($pourDetection) !== null) {
                        $pures[] = (int)$ligne['id'];
                    } else {
                        $mixtes[] = (int)$ligne['id'];
                    }
                }

                if ($pures !== []) {
                    // Vider les colonnes legacy n'est PAS optionnel : le partial
                    // message.php retombe sur file_name/file_type quand
                    // files_json est vide (carte fantôme), et creationType()
                    // continuerait de voir une création à l'élagage suivant.
                    $in = implode(',', array_fill(0, count($pures), '?'));
                    $db->prepare(
                        "UPDATE messages
                            SET content = ?, files_json = NULL,
                                file_name = NULL, file_type = NULL, file_extracted = NULL
                          WHERE id IN ($in)"
                    )->execute(array_merge([$marqueur], $pures));

                    $rapport['neutralisees'] += count($pures);
                    $rapport['partages']     += CreationShare::revokeMany($pures);
                }

                if ($mixtes !== []) {
                    $bilan = self::neutraliserMessagesMixtes($mixtes, $jour);
                    $rapport['images']   += $bilan['touches'];
                    $rapport['ignorees'] += $bilan['ignorees'];
                }

                if (time() - $debut >= self::BUDGET_SEC) {
                    $rapport['budget_atteint'] = true;
                    break;
                }
            }

            $rapport['balayage'] = self::balayerOrphelins();
        } finally {
            self::relacherVerrou();
        }

        self::invaliderCache();
        StorageInfo::invalidate();

        return $rapport;
    }

    /**
     * Chirurgie des messages « prose + images générées ».
     * Les contenus démesurés sont SAUTÉS et comptés : mieux vaut un message
     * intact et un chiffre dans le rapport qu'une réécriture tronquée.
     *
     * @return array{touches:int,ignorees:int}
     */
    private static function neutraliserMessagesMixtes(array $ids, string $jour): array
    {
        $ignorees = 0;
        $db       = Database::getInstance();
        $in       = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $db->prepare(
            "SELECT id, content, LENGTH(content) AS taille FROM messages WHERE id IN ($in)"
        );
        $stmt->execute($ids);

        $update  = $db->prepare('UPDATE messages SET content = ? WHERE id = ?');
        $touches = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            if ((int)$ligne['taille'] > self::CONTENU_MAX_REECRITURE) {
                $ignorees++;
                continue;
            }
            $nouveau = Message::neutraliserImages((string)$ligne['content'], $jour);
            if ($nouveau === (string)$ligne['content']) {
                continue;
            }
            $update->execute([$nouveau, (int)$ligne['id']]);
            $touches++;
        }

        return ['touches' => $touches, 'ignorees' => $ignorees];
    }

    // ---------------------------------------------------- Balayage des médias

    /**
     * Balayage déclenché SEUL depuis la page (bouton dédié) : prend le verrou et
     * prépare l'exécution longue, ce que balayerOrphelins() ne fait pas — il est
     * aussi appelé DEPUIS les deux élagages, qui tiennent déjà le verrou.
     *
     * @return array{candidats:int,supprimes:int,octets:int,echecs:int,jeunes:int,verrou:bool}
     */
    public static function balayerOrphelinsSeul(): array
    {
        if (!self::prendreVerrou()) {
            return ['candidats' => 0, 'supprimes' => 0, 'octets' => 0,
                    'echecs' => 0, 'jeunes' => 0, 'verrou' => false];
        }

        try {
            self::preparerExecutionLongue();
            $rapport = self::balayerOrphelins();
        } finally {
            self::relacherVerrou();
        }

        return $rapport + ['verrou' => true];
    }

    /**
     * Supprime du disque les fichiers que plus aucun message (ni aucun job en
     * file) ne référence, et qui ont dépassé AGE_MIN_ORPHELIN_SEC.
     *
     * @param list<string>|null $dossiers Chemins ABSOLUS, pour les tests. Null = DOSSIERS_BALAYES.
     * @param bool $blanc Ne supprime rien, se contente de compter (statistiques).
     * @return array{candidats:int,supprimes:int,octets:int,echecs:int,jeunes:int}
     */
    public static function balayerOrphelins(?array $dossiers = null, bool $blanc = false): array
    {
        $rapport = ['candidats' => 0, 'supprimes' => 0, 'octets' => 0, 'echecs' => 0, 'jeunes' => 0];

        $cibles = $dossiers ?? array_map(static fn(string $r): string => ROOT . $r, self::DOSSIERS_BALAYES);

        try {
            $references = self::referencesConnues();
        } catch (\Throwable $e) {
            // Index de références indisponible → on ne supprime RIEN. Balayer
            // avec un index vide effacerait tous les médias de l'application.
            error_log('[Élagage] index des références indisponible, balayage annulé : ' . $e->getMessage());
            return $rapport;
        }

        $maintenant = time();

        foreach ($cibles as $dossier) {
            if (!is_dir($dossier)) {
                continue;
            }

            try {
                $iterateur = new \FilesystemIterator($dossier, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                continue;
            }

            foreach ($iterateur as $fichier) {
                /** @var \SplFileInfo $fichier */
                if ($fichier->isLink() || !$fichier->isFile()) {
                    continue;
                }
                $nom = $fichier->getFilename();
                if ($nom === '' || $nom[0] === '.') {   // .gitkeep, .gitignore
                    continue;
                }
                if (isset($references[$nom])) {
                    continue;
                }

                $mtime = @$fichier->getMTime();
                if ($mtime !== false && $maintenant - (int)$mtime < self::AGE_MIN_ORPHELIN_SEC) {
                    $rapport['jeunes']++;
                    continue;
                }

                $rapport['candidats']++;

                if ($blanc) {
                    $taille = @$fichier->getSize();
                    $rapport['octets'] += $taille !== false ? (int)$taille : 0;
                    continue;
                }

                $octets = MediaFile::supprimerDans($fichier->getPathname(), $dossier);
                if ($octets > 0) {
                    $rapport['supprimes']++;
                    $rapport['octets'] += $octets;
                } else {
                    // @unlink échoue en silence si le répertoire appartient à
                    // root : compté et remonté, sinon le rapport mentirait.
                    $rapport['echecs']++;
                }
            }
        }

        if (!$blanc && $rapport['supprimes'] > 0) {
            self::invaliderCache();
            StorageInfo::invalidate();
        }

        return $rapport;
    }

    /**
     * Ensemble des NOMS DE FICHIER encore référencés, indexé pour un `isset`.
     *
     * Sens de parcours choisi : un seul passage sur `messages`, comparaison en
     * mémoire. Tester chaque fichier par une requête ferait N scans complets.
     *
     * JSON_EXTRACT plutôt qu'un `SELECT files_json` nu : MySQL parse la colonne
     * côté serveur et ne renvoie que les chemins — jamais le base64 des pièces
     * jointes (jusqu'à ~13 Mo par ligne). JSON_VALID en garde : une ligne au
     * JSON abîmé ferait sinon échouer TOUTE la requête (ER_INVALID_JSON_TEXT).
     *
     * @return array<string,true>
     */
    private static function referencesConnues(): array
    {
        $db  = Database::getInstance();
        $vus = [];

        $stmt = $db->prepare(
            'SELECT m.id,
                    CASE WHEN m.content LIKE "%/assets/img/generated/%"
                           OR m.content LIKE "%/assets/video/generated/%"
                           OR m.content LIKE "%/storage/uploads/%"
                         THEN m.content END AS contenu,
                    JSON_EXTRACT(IF(JSON_VALID(m.files_json), m.files_json, NULL), "$[*].path") AS chemins
             FROM messages m
             WHERE m.id > ?
               AND (m.content LIKE "%/assets/img/generated/%"
                 OR m.content LIKE "%/assets/video/generated/%"
                 OR m.content LIKE "%/storage/uploads/%"
                 OR m.files_json IS NOT NULL)
             ORDER BY m.id
             LIMIT ' . self::LOT_SCAN
        );

        $apres = 0;
        while (true) {
            $stmt->execute([$apres]);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($lignes === []) {
                break;
            }
            foreach ($lignes as $ligne) {
                $apres = (int)$ligne['id'];
                self::indexerReferences((string)($ligne['contenu'] ?? ''), $vus);
                self::indexerReferences((string)($ligne['chemins'] ?? ''), $vus);
            }
        }

        // Deuxième source, invisible depuis messages : l'image de référence
        // d'une vidéo est déposée dans storage/uploads et son chemin ne vit que
        // dans le payload du job. Worker arrêté un week-end → le fichier
        // dépasserait la garde d'âge et serait balayé sous les pieds du job.
        $jobs = $db->query("SELECT payload_json FROM async_jobs WHERE status IN ('pending', 'running')");
        foreach ($jobs->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            self::indexerReferences((string)$payload, $vus);
        }

        // Troisième source : le dossier des workflows. Un fichier reçu au
        // départ, une image créée par une brique Création peuvent n'être cités
        // QUE par une exécution — qui attend parfois une validation des jours
        // durant ; le balayer ferait échouer sa livraison. Une conversation
        // élaguée retire ses créations elle-même (neutralisation), pas ici.
        $arts = $db->prepare(
            'SELECT id, content_json FROM workflow_artifacts
             WHERE id > ? AND (content_json LIKE "%generated%" OR content_json LIKE "%uploads%")
             ORDER BY id LIMIT ' . self::LOT_SCAN
        );
        $apres = 0;
        while (true) {
            $arts->execute([$apres]);
            $lignes = $arts->fetchAll(PDO::FETCH_ASSOC);
            if ($lignes === []) {
                break;
            }
            foreach ($lignes as $ligne) {
                $apres = (int)$ligne['id'];
                self::indexerReferences((string)$ligne['content_json'], $vus);
            }
        }

        return $vus;
    }

    /** @param array<string,true> $vus */
    private static function indexerReferences(string $texte, array &$vus): void
    {
        if ($texte === '') {
            return;
        }

        // json_encode() échappe les barres obliques par DÉFAUT : en base, un
        // chemin de files_json ou de payload_json s'écrit
        // « \/storage\/uploads\/x.png ». Sans ce dés-échappement, le motif ne
        // reconnaîtrait AUCUN chemin venu de JSON — et le balayage supprimerait
        // toutes les pièces jointes encore référencées. Le contenu markdown des
        // messages, lui, n'est pas échappé : la substitution y est sans effet.
        $texte = str_replace('\\/', '/', $texte);

        if (preg_match_all(self::MOTIF_REFERENCE, $texte, $m) === 0) {
            return;
        }
        foreach ($m[1] as $nom) {
            $vus[$nom] = true;
        }
    }

    // ------------------------------------------------------------- Plomberie

    /** @param list<int|string> $ids */
    private static function octetsDesConversations(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT COALESCE(SUM(LENGTH(content)
                                 + LENGTH(COALESCE(files_json, ''))
                                 + LENGTH(COALESCE(file_extracted, ''))), 0)
               FROM messages WHERE conversation_id IN ($in)"
        );
        $stmt->execute($ids);
        return (int)$stmt->fetchColumn();
    }

    private static function compterConversationsEligibles(string $seuil): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*)
             FROM conversations c
             WHERE c.is_pinned = 0
               AND c.updated_at < ?
               AND NOT EXISTS (SELECT 1 FROM messages m
                               WHERE m.conversation_id = c.id AND m.created_at >= ?)
               AND NOT EXISTS (SELECT 1 FROM async_jobs j
                               WHERE j.conversation_id = c.id
                                 AND j.status IN ("pending", "running"))'
        );
        $stmt->execute([$seuil, $seuil]);
        return (int)$stmt->fetchColumn();
    }

    private static function rapportVide(int $jours): array
    {
        return [
            'jours'          => $jours,
            'verrou'         => true,
            'budget_atteint' => false,
            'balayage'       => ['candidats' => 0, 'supprimes' => 0, 'octets' => 0, 'echecs' => 0, 'jeunes' => 0],
        ];
    }

    /**
     * La session est fermée AVANT le travail long (sinon toute autre requête du
     * même admin attendrait), et la requête survit à un client qui se déconnecte
     * — le lot en cours doit finir proprement plutôt que de couper au milieu.
     */
    private static function preparerExecutionLongue(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
    }

    /** Verrou non bloquant : un double-clic repart tout de suite avec verrou=false. */
    private static function prendreVerrou(): bool
    {
        try {
            $stmt = Database::getInstance()->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute([self::VERROU]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function relacherVerrou(): void
    {
        try {
            Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)')->execute([self::VERROU]);
        } catch (\Throwable) {
            // Le verrou tombe de toute façon à la fermeture de la connexion.
        }
    }
}
