<?php

declare(strict_types=1);

/**
 * Conseils d'une base de connaissances — SOURCE UNIQUE de « cette base
 * est-elle bien exploitable par un assistant ? ».
 *
 * Une base peut être « à jour » (tout est indexé) et pourtant mal servir :
 * des scans sans texte, presque rien à lire, deux versions d'un même
 * document qui se contredisent, des tableurs que la recherche par extraits
 * comprend mal, ou un fourre-tout si gros que la recherche perd en précision.
 * Ce sont des CONSEILS, pas des pannes (KbStatus dit ce qui ne marche pas) :
 * chaque point dit ce qu'il faudrait changer, en mots simples.
 *
 * Pure : la base et ses documents (KnowledgeBaseDocument::allForKb, déjà lus
 * par la liste et la fiche), aucun accès à la base de données. Rien pour une
 * base en direct (MyBrain : aucun document chez MIA) ni pour la base système
 * (son contenu est tenu par MIA). Libellés traduits (t()) : ils suivent la
 * langue de l'écran qui les montre.
 */
final class KbAdvice
{
    /** Sous ce total de tokens indexés, un assistant n'y trouvera presque rien (≈ 450 mots). */
    public const THIN_TOKENS = 600;
    /** Au-delà de ce nombre d'extraits, un fourre-tout gagne à être découpé par thème. */
    public const LARGE_CHUNKS = 3000;
    /** Part du texte indexé venue de tableurs à partir de laquelle on le signale… */
    public const TABLE_SHARE = 0.5;
    /** …si ces tableurs pèsent au moins autant (quelques lignes ne gênent personne). */
    public const TABLE_MIN_TOKENS = 1000;
    /** Noms cités au plus par conseil. */
    public const MAX_NAMES = 3;
    /** Un token vaut environ trois quarts de mot (français, anglais). */
    public const WORDS_PER_TOKEN = 0.75;

    private const TABLE_EXTENSIONS = ['xlsx', 'xls', 'xlsm', 'ods', 'csv', 'tsv'];

    // Marques de version retirées d'un nom pour reconnaître deux versions
    // d'un même document. Ni les années ni les dates : « Rapport 2024 » et
    // « Rapport 2025 », ou deux comptes rendus datés, sont des documents
    // distincts, pas des versions.
    private const VERSION_MARKERS = [
        '/\b(?:v|ver|version|rev|revision)\s*\d+(?:\s+\d+)*\b/u',
        '/\b(?:copie|copy)(?:\s+(?:de|of))?\b/u',
        '/\b(?:final|finale|finalise|finalisee|definitif|definitive|def)\b/u',
        '/\b(?:old|ancien|ancienne|brouillon|draft|maj|mise a jour|updated?)\b/u',
    ];

    /**
     * Les points à améliorer, du plus bloquant au plus discret.
     *
     * @param array<string, mixed>             $kb
     * @param list<array<string, mixed>>       $docs
     * @return list<array{key: string, title: string, text: string, docs: list<string>}>
     */
    public static function forBase(array $kb, array $docs): array
    {
        if (!KnowledgeBase::holdsDocuments($kb) || (int)($kb['is_system'] ?? 0) === 1 || $docs === []) {
            return [];
        }

        $noText     = [];
        $busy       = false;
        $tokens     = 0;
        $tableTok   = 0;
        $chunks     = 0;
        $indexed    = 0;
        foreach ($docs as $d) {
            $key = KbStatus::documentState($d)['key'];
            if ($key === KbStatus::DOC_NO_TEXT) {
                $noText[] = (string)$d['file_name'];
            }
            if (in_array($key, [KbStatus::DOC_PENDING, KbStatus::DOC_EXTRACTING, KbStatus::DOC_INDEXING], true)) {
                $busy = true;
            }
            if ($key === KbStatus::DOC_INDEXED || $key === KbStatus::DOC_LEXICAL) {
                $indexed++;
                $n = (int)($d['token_count'] ?? 0);
                $tokens += $n;
                $chunks += (int)($d['chunk_count'] ?? 0);
                if (self::isTable($d)) {
                    $tableTok += $n;
                }
            }
        }

        $out = [];

        if ($noText !== []) {
            $n = count($noText);
            $out[] = [
                'key'   => 'no_text',
                'title' => I18n::plural($n, 'Un document sans texte', '{n} documents sans texte'),
                'text'  => t('MIA n\'y trouve rien : ce sont souvent des scans ou des photos de pages. Déposez plutôt une version texte (PDF exporté depuis un traitement de texte, document Word), ou passez le scan à la reconnaissance de texte.'),
                'docs'  => array_slice($noText, 0, self::MAX_NAMES),
            ];
        }

        // Un total n'a de sens qu'une fois tout lu : pendant la lecture, il monte.
        if (!$busy && $indexed > 0 && $tokens < self::THIN_TOKENS) {
            $out[] = [
                'key'   => 'thin',
                'title' => t('Très peu de texte'),
                'text'  => t('La base contient environ {n} mots : un assistant n\'y trouvera presque rien. Déposez les documents de référence complets plutôt que des extraits.', [
                    'n' => self::words($tokens),
                ]),
                'docs'  => [],
            ];
        }

        $versions = self::versionGroups($docs);
        if ($versions !== []) {
            $first = $versions[0];
            $out[] = [
                'key'   => 'versions',
                'title' => t('Plusieurs versions d\'un même document'),
                'text'  => count($versions) > 1
                    ? t('{n} documents existent en plusieurs exemplaires. Gardez seulement la version à jour de chacun : sinon l\'assistant peut mélanger anciennes et nouvelles informations.', ['n' => count($versions)])
                    : t('Gardez seulement la version à jour : sinon l\'assistant peut mélanger anciennes et nouvelles informations.'),
                'docs'  => array_slice($first, 0, self::MAX_NAMES),
            ];
        }

        if ($tableTok >= self::TABLE_MIN_TOKENS && $tokens > 0 && $tableTok / $tokens >= self::TABLE_SHARE) {
            $out[] = [
                'key'   => 'tables',
                'title' => t('Surtout des tableaux'),
                'text'  => t('La recherche par extraits retrouve des lignes de tableur, pas des totaux ni des comparaisons. Pour calculer sur des chiffres, joignez plutôt le fichier dans la conversation ; gardez ici les textes qui expliquent.'),
                'docs'  => [],
            ];
        }

        if ($chunks > self::LARGE_CHUNKS) {
            $out[] = [
                'key'   => 'large',
                'title' => t('Base très volumineuse'),
                'text'  => t('{n} extraits. Si elle mêle plusieurs sujets, découpez-la en bases thématiques et cochez seulement celle qui sert : la recherche sera plus précise.', [
                    'n' => Format::credits($chunks),
                ]),
                'docs'  => [],
            ];
        }

        return $out;
    }

    /** Un tableur (Excel, OpenDocument, CSV), d'après son nom puis son type. */
    public static function isTable(array $doc): bool
    {
        $ext = strtolower(pathinfo((string)($doc['file_name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, self::TABLE_EXTENSIONS, true)) {
            return true;
        }
        $mime = strtolower((string)($doc['mime_type'] ?? ''));
        return str_contains($mime, 'spreadsheet') || str_contains($mime, 'ms-excel')
            || in_array($mime, ['text/csv', 'application/csv', 'text/tab-separated-values'], true);
    }

    /**
     * Le nom d'un document sans ses marques de version : « Règlement
     * intérieur v2 (1).pdf » et « reglement-interieur_final.docx » se
     * retrouvent. Sans accents, sans casse, sans extension.
     */
    public static function baseTitle(string $fileName): string
    {
        $name = (string)pathinfo($fileName, PATHINFO_FILENAME);
        // « (1) » des copies faites par le système, avant que la ponctuation tombe.
        $name = (string)preg_replace('/\(\s*\d+\s*\)/u', ' ', $name);
        // Sans casse, accents ni ponctuation : la règle de la mémoire long terme.
        $name = UserMemory::normalizeForCompare($name);
        foreach (self::VERSION_MARKERS as $marker) {
            $name = (string)preg_replace($marker, ' ', $name);
        }
        return trim((string)preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Groupes de documents qui sont des versions l'un de l'autre (même nom
     * une fois les marques de version retirées), du plus grand au plus petit.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<list<string>> noms d'origine, par groupe
     */
    public static function versionGroups(array $docs): array
    {
        $groups = [];
        foreach ($docs as $d) {
            $name = (string)($d['file_name'] ?? '');
            $base = self::baseTitle($name);
            if (mb_strlen($base) < 3) {
                continue;
            }
            $groups[$base][] = $name;
        }
        $groups = array_values(array_filter($groups, static fn(array $g): bool => count($g) > 1));
        usort($groups, static fn(array $a, array $b): int => count($b) <=> count($a));
        return $groups;
    }

    /** Nombre de mots arrondi, lisible (« 450 »). */
    public static function words(int $tokens): string
    {
        $words = (int)round($tokens * self::WORDS_PER_TOKEN);
        $words = $words >= 100 ? (int)(round($words / 50) * 50) : (int)(round($words / 10) * 10);
        return Format::credits(max(10, $words));
    }

    /** Taille cible d'un extrait, en mots (conseil « des titres et des paragraphes »). */
    public static function chunkWords(): string
    {
        return Format::credits((int)(round(TextChunker::CHUNK_TARGET_TOKENS * self::WORDS_PER_TOKEN / 50) * 50));
    }
}
