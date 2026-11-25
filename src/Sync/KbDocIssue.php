<?php

declare(strict_types=1);

/**
 * Pourquoi UN document d'une base de connaissances n'est pas interrogeable,
 * et ce qu'on peut y faire — source unique de la fiche d'un document.
 *
 * Le problème résolu : une pastille « Illisible » ou « Non vectorisé » et un
 * message technique tronqué (souvent en anglais, venu d'une bibliothèque ou
 * d'un fournisseur) ne disaient ni la cause, ni s'il valait la peine de
 * recommencer, ni quoi faire sinon. Ici chaque cas connu reçoit :
 *   - un titre et une explication en clair ;
 *   - ce qu'il faut faire ;
 *   - un nouvel essai (`retry`) SEULEMENT quand il peut réussir — panne
 *     passagère d'un fournisseur, traitement interrompu, clé ajoutée depuis —,
 *     avec la phase à refaire : `full` (lire, découper, vectoriser), `vector`
 *     (vectoriser seulement : les extraits sont déjà là, le découpage n'est
 *     pas repayé) ou `sync` (le fichier sera retéléchargé par la
 *     synchronisation) ;
 *   - l'exclusion, toujours possible pour un document qui pose problème.
 *
 * Trois étages, comme KbStatus :
 *   - `of()`          : le verdict, PUR — tout le contexte arrive en paramètre ;
 *   - `inspectFile()` : regarde le fichier stocké (vide, protégé, numérisé) ;
 *   - `context()`     : lit le monde (fichier, modèles, clés, moteur
 *                       vectoriel) seulement pour la question que pose CE
 *                       document — jamais d'appel réseau pour rien.
 *
 * Les messages techniques d'origine (`technical`) restent tels quels : c'est
 * du contenu, réservé à qui gère la base.
 */
final class KbDocIssue
{
    /** Délai avant de déclarer interrompu un document en attente sans job. */
    public const STALL_GRACE_SEC = 120;

    public const KIND_STALLED            = 'stalled';
    public const KIND_MISSING            = 'missing_file';
    public const KIND_UNSUPPORTED        = 'unsupported';
    public const KIND_VISION_UNAVAILABLE = 'vision_unavailable';
    public const KIND_VISION             = 'vision';
    public const KIND_EXTRACTION         = 'extraction';
    public const KIND_EMPTY              = 'empty';
    public const KIND_PROTECTED          = 'protected';
    public const KIND_SCANNED            = 'scanned';
    public const KIND_NO_TEXT            = 'no_text';
    public const KIND_EMBED_KEY          = 'embedding_key';
    public const KIND_EMBED_API          = 'embedding_api';
    public const KIND_VECTOR_STORE       = 'vector_store';
    public const KIND_VECTOR             = 'vector';

    public const RETRY_FULL   = 'full';
    public const RETRY_VECTOR = 'vector';
    public const RETRY_SYNC   = 'sync';

    /** Verdicts de inspectFile(). */
    public const FILE_MISSING   = 'missing';
    public const FILE_EMPTY     = 'empty';
    public const FILE_PROTECTED = 'protected';
    public const FILE_SCANNED   = 'scanned';

    /** Lecture bornée du fichier : début et fin (PDF : trailer, dictionnaires). */
    private const INSPECT_HEAD = 2 * 1024 * 1024;
    private const INSPECT_TAIL = 256 * 1024;

    /** Fournisseurs d'embedding, noms de produit (jamais traduits). */
    private const PROVIDER_NAMES = ['openai' => 'OpenAI', 'mistral' => 'Mistral', 'gemini' => 'Google'];

    /** Messages dérivés, sans information propre : jamais montrés comme « détail ». */
    private const DERIVED_ERRORS = ['Extraction non aboutie.', 'Aucun texte extrait.'];

    // =====================================================================
    // Interrompu
    // =====================================================================

    /**
     * Pose `stalled` sur les documents dont le traitement s'est arrêté en
     * route : en attente ou en cours depuis plus de STALL_GRACE_SEC, sans
     * job d'ingestion ni de vectorisation vivant, et sans synchronisation ni
     * réindexation de la base qui tourne. Pur.
     *
     * @param list<array> $docs lignes de KnowledgeBaseDocument::allForKb (age_s)
     * @param array{base: bool, docs: array<int, true>} $work KnowledgeBaseDocument::activeWork
     * @return list<array>
     */
    public static function markStalled(array $docs, array $work): array
    {
        foreach ($docs as &$doc) {
            $doc['stalled'] = self::isStalled($doc, $work) ? 1 : 0;
        }
        unset($doc);
        return $docs;
    }

    /** @param array{base: bool, docs: array<int, true>} $work */
    public static function isStalled(array $doc, array $work): bool
    {
        if (!empty($doc['excluded_at']) || !empty($work['base']) || isset($work['docs'][(int)($doc['id'] ?? 0)])) {
            return false;
        }
        $ext = (string)($doc['extraction_status'] ?? 'pending');
        $vec = (string)($doc['vector_status'] ?? 'pending');
        $working = $ext === 'pending' || $ext === 'processing'
            || ($ext === 'done' && ($vec === 'pending' || $vec === 'processing'));
        $age = $doc['age_s'] ?? null;
        return $working && ($age === null || (int)$age >= self::STALL_GRACE_SEC);
    }

    // =====================================================================
    // Verdict (pur)
    // =====================================================================

    /**
     * Ce qui ne va pas avec un document, ou null s'il va bien ou travaille
     * encore. L'exclusion n'y change rien : la fiche d'un document exclu
     * redit pourquoi il posait problème.
     *
     * @param array $doc ligne allForKb (+ `stalled`)
     * @param array{
     *   synced?: bool,            piloté par la synchronisation active de la base
     *   file?: ?string,           inspectFile() (documents sans texte, fichiers absents)
     *   vision_ready?: bool,      un modèle sait décrire les images
     *   embedding_ready?: bool,   la clé du modèle d'embedding de la base est là
     *   embedding_provider?: string,
     *   vector_up?: ?bool,        le moteur vectoriel répond (null : non vérifié)
     * } $ctx
     * @return array{kind:string, tone:string, title:string, why:string, todo:string,
     *               retry:?string, exclude:bool, technical:list<string>}|null
     */
    public static function of(array $doc, array $ctx = []): ?array
    {
        $ext    = (string)($doc['extraction_status'] ?? 'pending');
        $vec    = (string)($doc['vector_status'] ?? 'pending');
        $chunks = (int)($doc['chunk_count'] ?? 0);
        $synced = !empty($ctx['synced']);
        $extErr = trim((string)($doc['extraction_error'] ?? ''));
        $vecErr = trim((string)($doc['vector_error'] ?? ''));
        $tech   = self::technical($extErr, $vecErr);

        if (!empty($doc['stalled'])) {
            $vectorOnly = $ext === 'done';
            return self::issue(self::KIND_STALLED, 'error',
                $vectorOnly ? t('Vectorisation interrompue') : t('Traitement interrompu'),
                t('Le traitement s\'est arrêté avant la fin (redémarrage du serveur, mémoire insuffisante…) et plus rien ne tourne pour ce document : il attendrait pour toujours.'),
                t('Réessayez. Si cela se reproduit, le fichier est sans doute trop lourd : découpez-le ou excluez-le.'),
                $vectorOnly ? self::RETRY_VECTOR : self::RETRY_FULL, $tech);
        }

        $mime = (string)($doc['mime_type'] ?? '');
        // Un format qui n'est plus lu (document déposé avant un changement) :
        // seulement quand la lecture n'a rien donné — un ancien document lu
        // et indexé reste bon.
        $unsupported = $mime !== '' && !isset(KnowledgeBaseDocument::ALLOWED_MIMES[$mime])
            ? self::issue(self::KIND_UNSUPPORTED, 'error', t('Format non pris en charge'),
                t('MIA ne sait pas lire ce type de fichier : aucun nouvel essai ne changera rien.'),
                self::replaceHint($synced, t('Enregistrez-le dans un format lu (PDF, Word, Excel, PowerPoint, texte)')),
                null, $tech)
            : null;

        if ($ext === 'failed') {
            return $unsupported ?? self::extractionIssue($extErr, $ctx, $synced, $tech);
        }
        if ($ext !== 'done') {
            return null; // en attente ou en lecture
        }
        if ($vec === 'failed') {
            return self::vectorIssue($vecErr, $chunks, $ctx, $tech);
        }
        if ($vec !== 'done') {
            return null; // vectorisation en cours
        }
        if ($chunks > 0) {
            return null; // indexé
        }
        return $unsupported ?? self::noTextIssue((string)($ctx['file'] ?? ''), $mime, $synced, $tech);
    }

    private static function extractionIssue(string $err, array $ctx, bool $synced, array $tech): array
    {
        $file = (string)($ctx['file'] ?? '');
        if (self::matches($err, ['Fichier introuvable', 'illisible sur le stockage']) || $file === self::FILE_MISSING) {
            return self::issue(self::KIND_MISSING, 'error', t('Fichier introuvable'),
                t('Le fichier n\'est plus sur le stockage de MIA : il ne peut pas être relu.'),
                $synced
                    ? t('Réessayez : la synchronisation le retéléchargera depuis le dossier en ligne à son prochain passage.')
                    : t('Supprimez-le et déposez-le de nouveau, ou excluez-le.'),
                $synced ? self::RETRY_SYNC : null, $tech);
        }
        if (self::matches($err, ['Aucun LLM vision'])) {
            $ready = !empty($ctx['vision_ready']);
            return self::issue(self::KIND_VISION_UNAVAILABLE, 'error', t('Image non décrite'),
                t('Une image est décrite par un modèle capable de lire les images ; aucun n\'était disponible quand elle a été déposée.'),
                $ready
                    ? t('Un tel modèle est maintenant disponible : réessayez.')
                    : t('Un administrateur doit ajouter une clé OpenAI, Anthropic, Google ou Mistral (Modèles IA → Fournisseurs et modèles), puis réessayez ; ou excluez l\'image.'),
                $ready ? self::RETRY_FULL : null, $tech);
        }
        if (self::matches($err, ['Description vision', 'aucune description'])) {
            return self::issue(self::KIND_VISION, 'error', t('Image non décrite'),
                t('Le modèle chargé de décrire l\'image n\'a pas répondu correctement (service saturé, crédit épuisé, réponse vide…).'),
                t('Réessayez dans quelques minutes. Si l\'erreur revient, consultez le détail technique ou excluez l\'image.'),
                self::RETRY_FULL, $tech);
        }
        return self::issue(self::KIND_EXTRACTION, 'error', t('Lecture impossible'),
            t('La lecture du texte du fichier a échoué.'),
            t('Réessayez une fois. Si l\'erreur revient, le fichier est sans doute abîmé ou d\'une forme que MIA ne lit pas : remplacez-le par une autre version (PDF, Word), ou excluez-le.'),
            self::RETRY_FULL, $tech);
    }

    private static function vectorIssue(string $err, int $chunks, array $ctx, array $tech): array
    {
        $provider = self::providerName((string)($ctx['embedding_provider'] ?? ''), $err);
        $meanwhile = $chunks > 0
            ? ' ' . t('En attendant, ses extraits restent trouvables par mots-clés.')
            : '';

        if (self::matches($err, ['manquante pour les embeddings', 'Provider d\'embedding', 'Catalogue d\'embeddings'])) {
            $ready = !empty($ctx['embedding_ready']);
            return self::issue(self::KIND_EMBED_KEY, 'error', t('Clé manquante pour la recherche par le sens'),
                t('La base est réglée sur un modèle d\'embedding de {fournisseur}, et aucune clé n\'était configurée pour lui.', ['fournisseur' => $provider]) . $meanwhile,
                $ready
                    ? t('La clé est maintenant là : réessayez.')
                    : t('Un administrateur doit ajouter la clé (Modèles IA → Fournisseurs et modèles), ou réindexez la base avec un autre modèle (fiche de la base, Avancé).'),
                $ready ? self::RETRY_VECTOR : null, $tech);
        }
        if (self::matches($err, ['Qdrant'])) {
            $down = ($ctx['vector_up'] ?? null) === false;
            return self::issue(self::KIND_VECTOR_STORE, 'error', t('Moteur vectoriel injoignable'),
                t('Le moteur vectoriel, qui range les extraits pour la recherche par le sens, ne répondait pas.') . $meanwhile,
                $down
                    ? t('Il ne répond toujours pas : un administrateur peut le vérifier (Maintenance → Diagnostic → Moteur vectoriel). Réessayez ensuite.')
                    : t('Il répond de nouveau : réessayez.'),
                $down ? null : self::RETRY_VECTOR, $tech);
        }
        if (self::matches($err, ['API embeddings', 'Réponse embeddings'])) {
            $status = preg_match('/HTTP\s+(\d{3})/', $err, $m) === 1 ? (int)$m[1] : 0;
            $why = match (true) {
                $status === 401 || $status === 403 => t('{fournisseur} a refusé la clé utilisée pour la recherche par le sens.', ['fournisseur' => $provider]),
                $status === 429                    => t('{fournisseur} a limité les demandes, ou le crédit du compte est épuisé.', ['fournisseur' => $provider]),
                $status === 0 || $status >= 500    => t('{fournisseur} n\'a pas répondu (service indisponible ou réseau).', ['fournisseur' => $provider]),
                default                            => t('{fournisseur} a renvoyé une erreur pendant la vectorisation.', ['fournisseur' => $provider]),
            };
            $todo = ($status === 401 || $status === 403)
                ? t('Un administrateur doit vérifier la clé (Modèles IA → Fournisseurs et modèles), puis réessayez.')
                : t('Réessayez dans quelques minutes.');
            return self::issue(self::KIND_EMBED_API, 'error', t('Vectorisation refusée par le fournisseur'),
                $why . $meanwhile, $todo, self::RETRY_VECTOR, $tech);
        }
        return self::issue(self::KIND_VECTOR, 'error', t('Vectorisation échouée'),
            t('Le calcul des vecteurs de ses extraits a échoué.') . $meanwhile,
            t('Réessayez. Si l\'erreur revient, consultez le détail technique.'),
            self::RETRY_VECTOR, $tech);
    }

    private static function noTextIssue(string $file, string $mime, bool $synced, array $tech): array
    {
        return match ($file) {
            self::FILE_EMPTY => self::issue(self::KIND_EMPTY, 'warn', t('Fichier vide'),
                t('Le fichier ne contient rien : il n\'y a rien à lire.'),
                self::replaceHint($synced, t('Remplacez-le par la bonne version')), null, $tech),
            self::FILE_PROTECTED => self::issue(self::KIND_PROTECTED, 'warn', t('Protégé par un mot de passe'),
                t('Le fichier est chiffré : MIA ne peut pas l\'ouvrir sans son mot de passe, et aucun nouvel essai n\'y changera rien.'),
                self::replaceHint($synced, t('Enregistrez-en une copie sans mot de passe')), null, $tech),
            self::FILE_SCANNED => self::issue(self::KIND_SCANNED, 'warn', t('Document numérisé'),
                t('Le PDF ne contient que des images de pages : il n\'a pas de texte à lire, et MIA ne fait pas de reconnaissance de caractères.'),
                self::replaceHint($synced, t('Passez-le dans un outil de reconnaissance de caractères (OCR), par exemple Adobe Acrobat')), null, $tech),
            default => self::issue(self::KIND_NO_TEXT, 'warn', t('Aucun texte trouvé'),
                str_starts_with($mime, 'image/')
                    ? t('La description de l\'image est revenue vide : il n\'y a rien à chercher.')
                    : t('La lecture a abouti, mais le fichier ne contient aucun texte exploitable (document numérisé, contenu en images, fichier vide ?).'),
                self::replaceHint($synced, t('Remplacez-le par une version qui contient du texte')), null, $tech),
        };
    }

    /** « Faites X, puis remplacez-le … ; ou excluez-le. » selon la provenance. */
    private static function replaceHint(bool $synced, string $action): string
    {
        return $synced
            ? t('{action} dans le dossier synchronisé : la base reprendra la nouvelle version. Sinon, excluez-le.', ['action' => $action])
            : t('{action}, puis déposez-le de nouveau. Sinon, excluez-le.', ['action' => $action]);
    }

    /** @return list<string> */
    private static function technical(string $extErr, string $vecErr): array
    {
        $out = [];
        foreach ([$extErr, $vecErr] as $raw) {
            $raw = trim((string)preg_replace('/\s+/u', ' ', $raw));
            if ($raw !== '' && !in_array($raw, self::DERIVED_ERRORS, true) && !in_array($raw, $out, true)) {
                $out[] = $raw;
            }
        }
        return $out;
    }

    private static function providerName(string $provider, string $err): string
    {
        if ($provider === '' && preg_match('/embeddings\s+(openai|mistral|gemini)/i', $err, $m) === 1) {
            $provider = strtolower($m[1]);
        }
        return self::PROVIDER_NAMES[$provider] ?? ($provider !== '' ? $provider : t('le fournisseur'));
    }

    /** @param list<string> $needles */
    private static function matches(string $err, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($err !== '' && stripos($err, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $tech
     * @return array{kind:string, tone:string, title:string, why:string, todo:string,
     *               retry:?string, exclude:bool, technical:list<string>}
     */
    private static function issue(string $kind, string $tone, string $title, string $why, string $todo, ?string $retry, array $tech): array
    {
        return [
            'kind'      => $kind,
            'tone'      => $tone,
            'title'     => $title,
            'why'       => $why,
            'todo'      => $todo,
            'retry'     => $retry,
            'exclude'   => true,
            'technical' => $tech,
        ];
    }

    // =====================================================================
    // Étapes (pur)
    // =====================================================================

    /**
     * Les trois étapes d'un document, telles que sa fiche les montre : lire
     * le texte, le découper en extraits, le ranger pour la recherche par le
     * sens. Ton de chaque étape : ok, busy, error, warn, idle (pas encore,
     * ou sans objet). Pur.
     *
     * @return list<array{key:string, label:string, tone:string, detail:string}>
     */
    public static function steps(array $doc): array
    {
        $ext     = (string)($doc['extraction_status'] ?? 'pending');
        $vec     = (string)($doc['vector_status'] ?? 'pending');
        $chunks  = (int)($doc['chunk_count'] ?? 0);
        $tokens  = (int)($doc['token_count'] ?? 0);
        $stalled = !empty($doc['stalled']);
        $image   = str_starts_with((string)($doc['mime_type'] ?? ''), 'image/');

        $read = match (true) {
            $ext === 'done'                                  => ['ok', match (true) {
                $tokens > 0 => I18n::plural($tokens, '{n} token lu', '{n} tokens lus', ['n' => Format::number($tokens)]),
                $chunks > 0 => t('Texte lu'),
                default     => t('Aucun texte'),
            }],
            $ext === 'failed'                                => ['error', t('Échec')],
            $stalled                                         => ['error', t('Interrompue')],
            $ext === 'processing'                            => ['busy', t('En cours…')],
            default                                          => ['idle', t('À faire')],
        };
        if ($ext === 'done' && $tokens === 0 && $chunks === 0) {
            $read[0] = 'warn';
        }

        $split = match (true) {
            $ext !== 'done'  => ['idle', '—'],
            $chunks > 0      => ['ok', I18n::plural($chunks, '{n} extrait', '{n} extraits', ['n' => Format::number($chunks)])],
            $vec === 'done'  => ['warn', t('Aucun extrait')],
            default          => ['idle', '—'],
        };

        $sense = match (true) {
            $ext !== 'done'                         => ['idle', '—'],
            $vec === 'failed'                       => ['error', t('Échec')],
            $stalled                                => ['error', t('Interrompue')],
            $vec === 'processing'                   => ['busy', t('En cours…')],
            $vec === 'pending'                      => ['idle', t('À faire')],
            $chunks > 0                             => ['ok', t('Trouvable')],
            default                                 => ['idle', t('Rien à ranger')],
        };

        return [
            ['key' => 'read',   'label' => $image ? t('Description de l\'image') : t('Lecture du texte'), 'tone' => $read[0],  'detail' => $read[1]],
            ['key' => 'split',  'label' => t('Découpage en extraits'),                                    'tone' => $split[0], 'detail' => $split[1]],
            ['key' => 'vector', 'label' => t('Recherche par le sens'),                                    'tone' => $sense[0], 'detail' => $sense[1]],
        ];
    }

    // =====================================================================
    // Le fichier stocké
    // =====================================================================

    /**
     * Ce que le fichier stocké dit de lui-même, sans le lire en entier :
     * absent, vide, protégé par un mot de passe (PDF chiffré, document
     * Office enveloppé dans un conteneur chiffré), numérisé (PDF fait
     * d'images, sans police). Null : rien de reconnaissable.
     */
    public static function inspectFile(string $path, string $mime): ?string
    {
        if ($path === '' || !is_file($path)) {
            return self::FILE_MISSING;
        }
        $size = (int)@filesize($path);
        if ($size <= 0) {
            return self::FILE_EMPTY;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $head = (string)fread($fh, min($size, self::INSPECT_HEAD));
            $tail = '';
            if ($size > self::INSPECT_HEAD) {
                fseek($fh, -min(self::INSPECT_TAIL, $size - self::INSPECT_HEAD), SEEK_END);
                $tail = (string)fread($fh, self::INSPECT_TAIL);
            }
        } finally {
            fclose($fh);
        }

        // Un .docx/.xlsx/.pptx protégé par un mot de passe n'est plus une
        // archive ZIP : Office l'enveloppe dans un conteneur OLE chiffré.
        $ooxml = str_contains($mime, 'officedocument') || str_contains($mime, 'opendocument');
        if ($ooxml && str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return self::FILE_PROTECTED;
        }
        if (trim($head) === '') {
            return self::FILE_EMPTY;
        }
        if ($mime === 'application/pdf') {
            $blob = $head . $tail;
            if (preg_match('#/Encrypt\b#', $blob) === 1) {
                return self::FILE_PROTECTED;
            }
            $images = preg_match_all('#/Subtype\s*/Image\b#', $blob);
            $fonts  = preg_match_all('#/(?:Font|FontDescriptor|BaseFont)\b#', $blob);
            if ($images > 0 && $fonts === 0) {
                return self::FILE_SCANNED;
            }
        }
        return null;
    }

    // =====================================================================
    // Contexte (lit le monde, seulement ce que la question demande)
    // =====================================================================

    /**
     * Contexte de of() pour un document : provenance, puis UNE vérification
     * selon le cas — fichier (sans texte, introuvable), modèle d'images,
     * clé d'embedding, moteur vectoriel. Ne lève jamais.
     */
    public static function context(array $kb, array $doc): array
    {
        $source = (string)($doc['source'] ?? 'upload');
        $ctx = [
            'synced'             => $source !== 'upload'
                && (int)($kb['sync_enabled'] ?? 0) === 1
                && $source === (string)($kb['sync_provider'] ?? ''),
            'embedding_provider' => (string)($kb['embedding_provider'] ?? ''),
        ];
        $ext    = (string)($doc['extraction_status'] ?? '');
        $vec    = (string)($doc['vector_status'] ?? '');
        $extErr = (string)($doc['extraction_error'] ?? '');
        $vecErr = (string)($doc['vector_error'] ?? '');
        try {
            $noText = $ext === 'done' && $vec === 'done' && (int)($doc['chunk_count'] ?? 0) === 0;
            if ($noText || ($ext === 'failed' && self::matches($extErr, ['Fichier introuvable', 'illisible sur le stockage']))) {
                $path = KnowledgeBase::storageDirFor((int)$doc['kb_id']) . '/' . (string)($doc['stored_name'] ?? '');
                $ctx['file'] = self::inspectFile($path, (string)($doc['mime_type'] ?? ''));
            }
            if ($ext === 'failed' && self::matches($extErr, ['Aucun LLM vision'])) {
                $ctx['vision_ready'] = KbVisionDescriber::resolveVisionLlm() !== null;
            }
            if ($ext === 'done' && $vec === 'failed') {
                if (self::matches($vecErr, ['manquante pour les embeddings', 'Provider d\'embedding', 'Catalogue d\'embeddings'])) {
                    $ctx['embedding_ready'] = isset(EmbeddingService::availableProviders()[(string)($kb['embedding_provider'] ?? '')]);
                } elseif (self::matches($vecErr, ['Qdrant'])) {
                    $ctx['vector_up'] = (new QdrantStore())->healthCheck();
                }
            }
        } catch (\Throwable $e) {
            error_log('[KB] diagnostic du document ' . (int)($doc['id'] ?? 0) . ' : ' . $e->getMessage());
        }
        return $ctx;
    }
}
