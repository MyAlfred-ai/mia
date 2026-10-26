<?php

declare(strict_types=1);

/**
 * Assistant système « MIA : mode d'emploi ».
 *
 * Semé automatiquement et de façon idempotente au bootstrap (public/index.php).
 * C'est un assistant GLOBAL (is_global = 1) : visible par tous les utilisateurs
 * sans condition de groupe. Il répond aux questions d'utilisation de MIA en
 * s'appuyant sur les 3 guides de docs/, portés par une BASE DE CONNAISSANCES
 * système (RAG vectoriel Qdrant, collection kb_{id}), liée à lui en N-N.
 *
 * Re-synchronisation automatique : une signature (hash du contenu des sources +
 * SEED_VERSION) est stockée en base. Dès que le contenu d'un document change
 * (= évolution de l'app documentée) ou que le volume de stockage des documents
 * est vierge (nouveau déploiement), les documents sont recopiés et réingérés
 * au prochain démarrage. Mettre à jour les .md de docs/ suffit donc.
 *
 * SIGNATURE PAR DOCUMENT, et ce n'est pas un raffinement. Un hash global
 * faisait repartir les QUATRE documents — dont deux guides de 100 Ko, chunking
 * par LLM et embeddings compris — dès qu'un octet bougeait n'importe où. Or
 * `docs/NOUVEAUTES.md` change à chaque évolution fonctionnelle : la
 * réingestion complète serait devenue la règle à chaque déploiement. Seul le
 * document dont le contenu FINAL a changé est remplacé.
 *
 * Le hash porte sur le contenu ÉCRIT, pas sur le fichier source : les
 * nouveautés sont ingérées sous une forme nettoyée (cf. Nouveautes::renderForKb),
 * donc retoucher un commentaire de rédaction dans le .md ne déclenche rien.
 *
 * Garde "cheap" (par requête) : 4 lectures de petits fichiers + quelques
 * lectures indexées. Le travail lourd (copie + extraction + chunking sémantique
 * + embeddings + upsert Qdrant) ne tourne que lorsque la signature a changé,
 * sous verrou MySQL et en tâche de fond.
 */
class DefaultAssistant
{
    private const NAME         = 'MIA : mode d\'emploi';
    private const AVATAR       = 'mia-aide.png';
    private const COLOR        = '#7c3aed';
    private const SEED_VERSION = 4; // v4 : + les nouveautés, et un hash par document
    private const SIG_KEY      = 'mia_help_assistant_sig';
    private const DOCS_SIG_KEY = 'mia_help_docs_sigs';
    private const ID_KEY       = 'mia_help_assistant_id';
    private const KB_ID_KEY    = 'mia_help_kb_id';
    private const LOCK_NAME    = 'mia_help_seed';

    private const KB_DESCRIPTION = 'Guides d\'utilisation de MIA (utilisateur, créateur, administrateur) '
        . 'et liste des nouveautés. '
        . 'Base système : resynchronisée automatiquement depuis les guides à chaque déploiement.';

    /**
     * Documents source (RAG), relatifs à ROOT. Le dernier est la liste des
     * nouveautés : c'est ce qui permet à l'assistant de répondre à « quoi de
     * neuf ? » — les guides décrivent ce que l'application fait, jamais ce
     * qu'elle vient d'apprendre à faire.
     */
    private const DOCS = [
        'docs/GUIDE-UTILISATEUR.md',
        'docs/GUIDE-CREATEUR.md',
        'docs/GUIDE-ADMIN.md',
        Nouveautes::SOURCE,
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es « MIA : mode d'emploi », l'assistant d'aide de la plateforme MIA.

Ton rôle : répondre aux questions des utilisateurs sur l'UTILISATION et la
CONFIGURATION de MIA — usage quotidien (chat, fichiers, recherche web,
génération d'images/vidéos/HTML), création d'assistants, et administration.

Règles de réponse :
- Réponds dans la langue de l'utilisateur (celle que donne la consigne
  « Langue des réponses »), de façon CLAIRE, PRÉCISE et COURTE. Va droit au but.
  La documentation est en français : quand tu réponds dans une autre langue,
  cite les libellés de l'écran dans cette langue (ex. « Réglages → Préférences
  IA » devient « Settings → AI preferences » en anglais).
- Guide TOUJOURS l'utilisateur par ce qui est AFFICHÉ À L'ÉCRAN : noms de pages,
  libellés de menus, intitulés exacts des boutons et des champs. Donne le chemin
  de navigation, ex. « Réglages → Préférences IA », « bouton Outils → Images ».
- N'utilise JAMAIS de termes techniques dans tes réponses : aucune URL ni chemin
  d'adresse, aucun nom de fichier, aucun nom de colonne ou de table, aucune
  balise ni code HTML. Traduis tout en langage visible par l'utilisateur.
  (Ex. : au lieu d'« va sur la page de préférences », dis « ouvre Réglages puis
  l'onglet Préférences IA ».)
- Donne un exemple concret quand cela aide (étapes numérotées, chemin de menu).
- Appuie-toi EXCLUSIVEMENT sur la documentation fournie en contexte (guides
  utilisateur, créateur, administrateur, et liste des nouveautés). N'invente
  jamais une fonctionnalité.
- Sur les NOUVEAUTÉS (« quoi de neuf ? », « qu'est-ce qui a changé ? », « depuis
  quand peut-on… ? ») : appuie-toi sur le document « Nouveautés », de la plus
  récente à la plus ancienne. Donne le titre et une phrase d'explication, avec
  la date ; ne cite un numéro de version que si on te le demande. Précise quand
  une nouveauté ne concerne que les administrateurs ou les créateurs
  d'assistants. Si on t'interroge sur une période plus ancienne que ce document,
  dis que tu ne remontes pas plus loin.
- Adapte la réponse au profil de la personne (utilisateur, créateur,
  administrateur) lorsque c'est pertinent.
- Si l'information n'est pas dans les guides, dis-le clairement et invite à
  contacter un administrateur — ne devine pas.
- Pour les procédures, utilise des listes courtes ou des étapes numérotées.
- Ne révèle jamais d'informations sensibles (clés API, secrets) et reste
  strictement sur le sujet de l'utilisation de MIA.
PROMPT;

    private const DESCRIPTION = "Pose-moi toute question sur l'utilisation de MIA : chat, assistants, administration.";

    /** Point d'entrée bootstrap : idempotent, ne lève jamais. */
    public static function ensureSeeded(): void
    {
        try {
            $sig = self::signature();
            if ($sig === '') {
                return; // guides absents → rien à semer
            }
            if (self::upToDate($sig)) {
                return;
            }

            // Le seed est long (LLM + embeddings) : une seule requête le porte,
            // les autres repartent immédiatement et servent l'app normalement.
            if (!self::acquireLock()) {
                return;
            }
            try {
                if (!self::upToDate($sig)) { // re-test sous verrou
                    self::seed($sig);
                }
            } finally {
                self::releaseLock();
            }
        } catch (\Throwable $e) {
            error_log('[DefaultAssistant] seed échoué : ' . $e->getMessage());
        }
    }

    /**
     * Contenu à INGÉRER pour un document source, ou null s'il est absent.
     *
     * Les guides partent tels quels. Les nouveautés, elles, sont rendues :
     * le fichier source porte des instructions de rédaction et des
     * métadonnées d'outillage (identifiants, marqueurs) qui n'ont rien à faire
     * dans un contexte lu par un assistant — son prompt lui interdit
     * justement d'employer le moindre terme technique.
     */
    private static function contenuPour(string $rel): ?string
    {
        $path = ROOT . '/' . $rel;
        if (!is_file($path)) {
            return null;
        }
        $brut = @file_get_contents($path);
        if ($brut === false) {
            return null;
        }
        if ($rel === Nouveautes::SOURCE) {
            return Nouveautes::renderForKb(
                Nouveautes::parse($brut),
                (string) Settings::get('app_name', 'MIA')
            );
        }
        return $brut;
    }

    /**
     * Hash du contenu ingéré, par document. C'est LUI qui décide de la
     * réingestion : un fichier source retouché sans effet sur le contenu rendu
     * (un commentaire, une consigne de rédaction) ne coûte rien.
     *
     * @return array<string,string>|null null si un document manque
     */
    private static function hashesParDocument(): ?array
    {
        $out = [];
        foreach (self::DOCS as $rel) {
            $contenu = self::contenuPour($rel);
            if ($contenu === null) {
                return null;
            }
            $out[$rel] = hash('sha256', 'v' . self::SEED_VERSION . '|' . $contenu);
        }
        return $out;
    }

    /** Signature globale = version + hash de chaque document. '' si l'un manque. */
    private static function signature(): string
    {
        $hashes = self::hashesParDocument();
        if ($hashes === null) {
            return '';
        }
        // Le prompt de l'assistant compte aussi : une consigne retouchée doit
        // atteindre les instances déjà semées, même sans document modifié.
        $parts = ['v' . self::SEED_VERSION, 'prompt:' . hash('sha256', self::SYSTEM_PROMPT)];
        foreach ($hashes as $rel => $h) {
            $parts[] = $rel . ':' . $h;
        }
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Tout est en place pour cette signature ? Garde volontairement pauvre en
     * requêtes : elle tourne à chaque requête HTTP.
     */
    private static function upToDate(string $sig): bool
    {
        if ((string) Settings::get(self::SIG_KEY, '') !== $sig) {
            return false;
        }
        $pid = (int) Settings::get(self::ID_KEY, '0');
        $kid = (int) Settings::get(self::KB_ID_KEY, '0');
        if ($pid <= 0 || $kid <= 0) {
            return false;
        }
        if (Profile::findById($pid) === null || KnowledgeBase::findById($kid) === null) {
            return false;
        }
        // Liaison assistant ↔ base rompue à la main → on la rétablit.
        if (!in_array($kid, KnowledgeBase::kbIdsForProfile($pid), true)) {
            return false;
        }
        return self::docsPresentOnDisk($kid);
    }

    /** Les documents existent en base ET leurs fichiers sont sur le volume ? */
    private static function docsPresentOnDisk(int $kbId): bool
    {
        $docs = KnowledgeBaseDocument::allForKb($kbId);
        if (count($docs) !== count(self::DOCS)) {
            return false;
        }
        $dir = KnowledgeBase::storageDirFor($kbId);
        foreach ($docs as $d) {
            if (!is_file($dir . '/' . (string) $d['stored_name'])) {
                return false;
            }
        }
        return true;
    }

    // ===================== Verrou =====================

    /**
     * Hashes de la dernière ingestion réussie, par document source. Un
     * réglage illisible (première exécution, valeur corrompue) rend un
     * tableau vide : tout est réingéré, ce qui est le comportement sûr.
     *
     * @return array<string,string>
     */
    private static function hashesConnus(): array
    {
        $brut = (string) Settings::get(self::DOCS_SIG_KEY, '');
        if ($brut === '') {
            return [];
        }
        $data = json_decode($brut, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $rel => $h) {
            if (is_string($rel) && is_string($h)) {
                $out[$rel] = $h;
            }
        }
        return $out;
    }

    /** Verrou MySQL non bloquant, relâché à la fermeture de la connexion. */
    private static function acquireLock(): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([self::LOCK_NAME]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function releaseLock(): void
    {
        Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
    }

    // ===================== Seed =====================

    private static function seed(string $sig): void
    {
        // Aucune clé d'embedding (déploiement Anthropic seul, par exemple) : on
        // sème quand même sur le choix nominal du catalogue. La vectorisation
        // échouera, mais les chunks MySQL rendent la base interrogeable en
        // fallback lexical — l'assistant d'aide répond, en mode dégradé, au
        // lieu de ne rien savoir. L'admin voit l'échec sur la base et la
        // vectorise via « Réindexer » dès qu'une clé est saisie.
        $embedding = EmbeddingService::defaultChoice();
        if ($embedding === null) {
            $embedding = EmbeddingService::nominalDefault();
            error_log('[DefaultAssistant] aucun fournisseur d\'embedding configuré — '
                . 'base de connaissances semée sans vecteurs (fallback lexical).');
        }

        $pid = self::upsertProfile();
        $kid = self::upsertKnowledgeBase($embedding);

        // L'assistant tire tout son savoir de la base. Ses documents de
        // référence (schéma des versions ≤ 2) feraient double emploi et, servis
        // en premier, consommeraient le budget RAG avant la base.
        self::purgeProfileDocuments($pid);

        KnowledgeBase::setForProfile($pid, [$kid], [$kid]);

        // Dimensions de la BASE, pas du choix par défaut : un administrateur a
        // pu la réindexer sur un autre modèle (1024 chez Mistral vs 1536). La
        // collection doit être taillée comme les vecteurs que les workers vont
        // produire, sinon l'upsert est rejeté.
        $kb     = KnowledgeBase::findById($kid);
        $hashes = self::hashesParDocument() ?? [];
        $connus = self::hashesConnus();
        self::syncKbDocuments($kid, (int) $kb['embedding_dims'], $hashes, $connus);

        Settings::set(self::ID_KEY, (string) $pid);
        Settings::set(self::KB_ID_KEY, (string) $kid);
        Settings::set(self::DOCS_SIG_KEY, json_encode($hashes, JSON_UNESCAPED_SLASHES) ?: '{}');
        Settings::set(self::SIG_KEY, $sig);
    }

    /**
     * Crée ou met à jour le profil système et renvoie son id.
     * Le LLM reste NULL = LLM par défaut de l'application (résolu en chat).
     */
    private static function upsertProfile(): int
    {
        $db  = Database::getInstance();
        $pid = (int) Settings::get(self::ID_KEY, '0');

        // Retrouve un profil existant (id mémorisé, sinon par nom).
        if ($pid <= 0 || Profile::findById($pid) === null) {
            $stmt = $db->prepare('SELECT id FROM profiles WHERE name = ? LIMIT 1');
            $stmt->execute([self::NAME]);
            $pid = (int) ($stmt->fetchColumn() ?: 0);
        }

        if ($pid > 0) {
            $db->prepare(
                'UPDATE profiles
                    SET name = ?, description = ?, system_prompt = ?, default_llm = NULL,
                        color = ?, temperature = NULL, avatar = ?,
                        is_active = 1, is_personal = 0, is_global = 1, created_by = NULL,
                        updated_at = NOW()
                  WHERE id = ?'
            )->execute([self::NAME, self::DESCRIPTION, self::SYSTEM_PROMPT, self::COLOR, self::AVATAR, $pid]);
            return $pid;
        }

        return Profile::create([
            'name'          => self::NAME,
            'description'   => self::DESCRIPTION,
            'system_prompt' => self::SYSTEM_PROMPT,
            'default_llm'   => null,
            'is_active'     => 1,
            'color'         => self::COLOR,
            'temperature'   => null,
            'avatar'        => self::AVATAR,
            'created_by'    => null,
            'is_personal'   => 0,
            'is_global'     => 1,
        ]);
    }

    /**
     * Crée ou met à jour la base système et renvoie son id. created_by reste
     * NULL (hors quota creator) et is_system = 1 la verrouille en admin.
     *
     * Le trio embedding_* n'est imposé qu'à la création : si un administrateur
     * a réindexé la base sur un autre modèle, son choix est conservé.
     *
     * @param array{provider:string,model:string,dims:int} $embedding
     */
    private static function upsertKnowledgeBase(array $embedding): int
    {
        $db  = Database::getInstance();
        $kid = (int) Settings::get(self::KB_ID_KEY, '0');

        if ($kid <= 0 || KnowledgeBase::findById($kid) === null) {
            $stmt = $db->prepare('SELECT id FROM knowledge_bases WHERE is_system = 1 ORDER BY id ASC LIMIT 1');
            $stmt->execute();
            $kid = (int) ($stmt->fetchColumn() ?: 0);
        }

        if ($kid > 0) {
            $db->prepare(
                'UPDATE knowledge_bases
                    SET name = ?, description = ?, is_active = 1, is_system = 1, created_by = NULL
                  WHERE id = ?'
            )->execute([self::NAME, self::KB_DESCRIPTION, $kid]);
            return $kid;
        }

        return KnowledgeBase::create([
            'name'               => self::NAME,
            'description'        => self::KB_DESCRIPTION,
            'embedding_provider' => $embedding['provider'],
            'embedding_model'    => $embedding['model'],
            'embedding_dims'     => $embedding['dims'],
            'is_active'          => 1,
            'is_system'          => 1,
            'created_by'         => null,
        ]);
    }

    /** Purge les documents de référence du profil (héritage des versions ≤ 2). */
    private static function purgeProfileDocuments(int $profileId): void
    {
        foreach (ProfileDocument::allForProfile($profileId) as $d) {
            ProfileDocument::deleteChunks((int) $d['id']);
            ProfileDocument::delete((int) $d['id']);
        }
    }

    /**
     * Aligne les documents de la base sur les sources, UN PAR UN.
     *
     * Un document n'est réécrit et réingéré que si son contenu a changé,
     * s'il a disparu du disque, ou s'il n'existe pas encore. C'est tout
     * l'intérêt du hash par document : les nouveautés bougent à chaque
     * évolution fonctionnelle, les guides beaucoup moins, et les 200 Ko de
     * guides n'ont aucune raison de repasser par le chunking sémantique et
     * les embeddings parce qu'une ligne d'annonce a été ajoutée.
     *
     * La collection est créée ICI, avant tout dispatch : les workers
     * concurrents la trouveront présente, là où plusieurs ensureCollection()
     * simultanés sur une collection neuve se marcheraient dessus. Elle n'est
     * PLUS détruite — un drop effacerait les vecteurs des documents inchangés,
     * qu'on vient précisément de décider de ne pas refaire ; les points d'un
     * document remplacé sont purgés individuellement, comme le fait la
     * synchronisation des sources distantes.
     *
     * @param array<string,string> $hashes chemin source => hash du contenu ingéré
     * @param array<string,string> $connus hashes de la dernière ingestion réussie
     */
    private static function syncKbDocuments(int $kbId, int $dims, array $hashes, array $connus): void
    {
        $collection = KnowledgeBase::collectionName($kbId);

        // Qdrant indisponible : l'ingestion posera les chunks MySQL et la base
        // répondra en fallback lexical. Jamais bloquant (cf. QdrantStore).
        $store = null;
        try {
            $store = new QdrantStore();
            $store->ensureCollection($collection, $dims);
        } catch (\Throwable $e) {
            error_log('[DefaultAssistant] collection Qdrant indisponible : ' . $e->getMessage());
        }

        $dir      = KnowledgeBase::ensureStorageDir($kbId);
        $attendus = [];
        foreach (self::DOCS as $rel) {
            $attendus[basename($rel)] = $rel;
        }

        // Documents en base, indexés par nom de fichier. Un doublon (incident
        // passé) est traité comme un intrus : on n'en garde qu'un.
        $enBase  = [];
        $intrus  = [];
        foreach (KnowledgeBaseDocument::allForKb($kbId) as $d) {
            $nom = (string) $d['file_name'];
            if (!isset($attendus[$nom]) || isset($enBase[$nom])) {
                $intrus[] = $d;   // guide renommé, document d'une version antérieure
                continue;
            }
            $enBase[$nom] = $d;
        }

        /**
         * Purge d'un document : les points Qdrant AVANT la ligne MySQL, dont
         * dépendent les identifiants de chunks.
         */
        $purger = static function (array $d) use ($store, $collection): void {
            if ($store !== null) {
                try {
                    $store->deletePointsByDocument($collection, (int) $d['id']);
                } catch (\Throwable $e) {
                    error_log('[DefaultAssistant] purge points doc ' . $d['id'] . ' : ' . $e->getMessage());
                }
            }
            KnowledgeBaseDocument::delete((int) $d['id']); // chunks par CASCADE
        };

        foreach ($intrus as $d) {
            $purger($d);
        }

        foreach ($attendus as $nom => $rel) {
            $contenu = self::contenuPour($rel);
            if ($contenu === null) {
                throw new \RuntimeException("Document source introuvable : $rel");
            }

            $chemin = $dir . '/' . $nom;
            $aJour  = isset($enBase[$nom])
                && is_file($chemin)
                && (($connus[$rel] ?? null) === $hashes[$rel]);
            if ($aJour) {
                continue; // rien n'a bougé : ni écriture, ni embeddings, ni coût
            }

            // La purge précède l'écriture : delete() efface AUSSI le fichier
            // stocké, et le remplaçant porte le même nom — l'ordre inverse
            // supprimerait le document qu'on vient d'écrire, sans une erreur.
            if (isset($enBase[$nom])) {
                $purger($enBase[$nom]);
            }
            if (@file_put_contents($chemin, $contenu) === false) {
                throw new \RuntimeException("Écriture impossible du document $nom");
            }
            $docId = KnowledgeBaseDocument::create([
                'kb_id'       => $kbId,
                'file_name'   => $nom,
                'stored_name' => $nom,
                'mime_type'   => 'text/markdown',
                'file_size'   => strlen($contenu),
            ]);
            JobQueue::enqueueMaintenance('kb_document', $docId, 'Ingestion document #' . $docId);
        }
    }
}
