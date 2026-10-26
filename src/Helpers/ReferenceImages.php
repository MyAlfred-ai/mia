<?php

declare(strict_types=1);

/**
 * Images de référence transmises au modèle image.
 *
 * Le problème résolu : une image déposée dans une base de connaissances n'en
 * sortait que sous forme de DESCRIPTION (KbVisionDescriber) — le modèle image
 * ne recevait que des mots, jamais l'image. Et des documents de référence de
 * l'assistant, seule la PREMIÈRE image servait de base, sans choix possible.
 *
 * Désormais, pour un message, le catalogue réunit les images des documents de
 * référence de l'assistant ET celles des bases de connaissances interrogées
 * (bases de l'assistant + bases cochées dans la conversation, déjà filtrées
 * sur le périmètre de la personne par l'appelant). Le modèle de conversation
 * — ou, pour l'outil Image du menu « + », l'appel qui prépare la demande —
 * en choisit au plus MAX_PER_GENERATION par leur nom ; les fichiers d'origine
 * sont relus sur disque et envoyés au modèle image avec la consigne.
 *
 * Deux sections, comme ConnectorContext : la FORME (pure, testable sans base
 * ni disque : catalogue, désignation, recherche, liste du prompt) et la
 * LECTURE (base de données, fichiers).
 */
final class ReferenceImages
{
    /** Images de référence au plus par génération (le modèle image en accepte davantage : 16 chez OpenAI, 14 chez Google). */
    public const MAX_PER_GENERATION = 4;

    /** Images listées dans le prompt ; au-delà, l'outil de recherche prend le relais. */
    public const MAX_LISTED = 25;

    /** Images lues au catalogue, au plus. */
    public const MAX_CATALOG = 300;

    /** Longueur de la description montrée dans la liste du prompt. */
    public const DESCRIPTION_CHARS = 110;

    /** Texte de description gardé pour la recherche. */
    public const SEARCH_CHARS = 600;

    /** Poids d'une image envoyée, après réduction éventuelle. */
    public const MAX_BYTES_PER_IMAGE = 4 * 1024 * 1024;

    /** Poids cumulé des références d'une génération (la requête Gemini plafonne à 20 Mo, base64 compris). */
    public const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    /** Plus grand côté d'une image réduite. */
    public const MAX_SIDE = 2048;

    /** Formats que les deux fournisseurs acceptent tels quels (OpenAI : png, jpeg, webp). */
    public const SENDABLE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    // =====================================================================
    // Forme (pure)
    // =====================================================================

    /**
     * Catalogue d'un message : images de l'assistant d'abord (choisies pour
     * lui), puis celles des bases, dans l'ordre reçu. Chaque entrée porte une
     * CLÉ unique — le nom présenté au modèle : le nom du fichier, suffixé de
     * sa provenance quand deux images portent le même. Pure.
     *
     * @param list<array<string, mixed>> $profileRows lignes profile_documents (+ `path`)
     * @param list<array<string, mixed>> $kbRows      lignes knowledge_base_documents (+ `kb_name`, `path`)
     * @return list<array{key:string, name:string, origin:string, source:string, description:string, search:string, mime:string, size:int, path:string}>
     */
    public static function catalog(array $profileRows, array $kbRows): array
    {
        $entries = [];
        foreach ($profileRows as $row) {
            $entries[] = self::entry($row, 'assistant', '');
        }
        foreach ($kbRows as $row) {
            $entries[] = self::entry($row, 'kb', (string)($row['kb_name'] ?? ''));
        }
        $entries = array_values(array_filter($entries, static fn(array $e): bool => $e['name'] !== ''));
        $entries = array_slice($entries, 0, self::MAX_CATALOG);

        $byName = [];
        foreach ($entries as $e) {
            $byName[self::fold($e['name'])] = ($byName[self::fold($e['name'])] ?? 0) + 1;
        }
        $used = [];
        foreach ($entries as $i => $e) {
            $key = $e['name'];
            if ($byName[self::fold($e['name'])] > 1) {
                $key .= ' (' . ($e['origin'] === 'assistant' ? 'assistant' : ($e['source'] !== '' ? $e['source'] : 'base')) . ')';
            }
            $base = $key;
            for ($n = 2; isset($used[self::fold($key)]); $n++) {
                $key = $base . ' #' . $n;
            }
            $used[self::fold($key)] = true;
            $entries[$i]['key'] = $key;
        }
        return $entries;
    }

    /** @return array{key:string, name:string, origin:string, source:string, description:string, search:string, mime:string, size:int, path:string} */
    private static function entry(array $row, string $origin, string $source): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($row['file_name'] ?? '')) ?? '');
        $text = self::cleanDescription((string)($row['extracted_text'] ?? ''));
        return [
            'key'         => $name,
            'name'        => $name,
            'origin'      => $origin,
            'source'      => trim($source),
            'description' => self::shorten($text, self::DESCRIPTION_CHARS),
            'search'      => self::fold($name . ' ' . mb_substr($text, 0, self::SEARCH_CHARS)),
            'mime'        => strtolower((string)($row['mime_type'] ?? '')),
            'size'        => (int)($row['file_size'] ?? 0),
            'path'        => (string)($row['path'] ?? ''),
        ];
    }

    /**
     * Description d'une image de base : le texte de KbVisionDescriber sans
     * son en-tête (« Description de l'image x.png : »), blancs repliés. Pure.
     */
    public static function cleanDescription(string $text): string
    {
        $text = preg_replace('/^\s*Description de l\'image .*?:\s*/su', '', $text, 1) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Texte borné à une frontière de mot, avec points de suspension. Pure. */
    private static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int)($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " ,;:.-") . '…';
    }

    /**
     * Liste des références demandées, quelle que soit la forme qu'en donne le
     * modèle : liste, texte JSON d'une liste, noms séparés par des virgules
     * ou des retours à la ligne. Doublons retirés, bornée. Pure.
     *
     * @return list<string>
     */
    public static function normalizeWanted(mixed $raw): array
    {
        if (is_string($raw)) {
            $txt = trim($raw);
            if ($txt === '') {
                return [];
            }
            if ($txt[0] === '[') {
                $decoded = json_decode($txt, true);
                if (is_array($decoded)) {
                    return self::normalizeWanted($decoded);
                }
            }
            $raw = preg_split('/\s*[\n,;]\s*/u', $txt) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $name = trim((string)$item, " \t\n\r\0\x0B\"'«»“”");
            if ($name === '' || isset($seen[self::fold($name)])) {
                continue;
            }
            $seen[self::fold($name)] = true;
            $out[] = mb_substr($name, 0, 255);
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * Désignation des références demandées : clé exacte, puis nom de fichier,
     * puis nom sans extension, puis partie unique du nom (casse et accents
     * ignorés). Un nom qui en désigne plusieurs est AMBIGU, jamais tranché au
     * hasard : reprendre le mauvais personnage ou le mauvais logo coûte une
     * génération et ne se voit qu'à l'arrivée. Pure.
     *
     * @param list<array<string, mixed>> $catalog
     * @param list<string>               $wanted
     * @return array{picked: list<array<string, mixed>>, unknown: list<string>, ambiguous: list<array{wanted:string, candidates:list<string>}>}
     */
    public static function pick(array $catalog, array $wanted): array
    {
        $picked = [];
        $keys   = [];
        $unknown = [];
        $ambiguous = [];
        foreach ($wanted as $w) {
            $hits = self::match($catalog, $w);
            if (count($hits) === 1) {
                $e = $hits[0];
                if (!isset($keys[$e['key']])) {
                    $keys[$e['key']] = true;
                    $picked[] = $e;
                }
            } elseif ($hits === []) {
                $unknown[] = $w;
            } else {
                $ambiguous[] = ['wanted' => $w, 'candidates' => array_map(static fn(array $e): string => $e['key'], $hits)];
            }
        }
        return ['picked' => $picked, 'unknown' => $unknown, 'ambiguous' => $ambiguous];
    }

    /** @return list<array<string, mixed>> entrées désignées par un nom, de la règle la plus stricte à la plus large */
    private static function match(array $catalog, string $wanted): array
    {
        $w = self::fold($wanted);
        if ($w === '') {
            return [];
        }
        $rules = [
            static fn(array $e): bool => self::fold($e['key']) === $w,
            static fn(array $e): bool => self::fold($e['name']) === $w,
            static fn(array $e): bool => self::fold((string)pathinfo($e['name'], PATHINFO_FILENAME)) === $w,
            static fn(array $e): bool => mb_strlen($w) >= 3 && str_contains(self::fold($e['key']), $w),
        ];
        foreach ($rules as $rule) {
            $hits = array_values(array_filter($catalog, $rule));
            if ($hits !== []) {
                return $hits;
            }
        }
        return [];
    }

    /**
     * Message rendu au modèle quand une référence demandée n'est pas
     * désignable — il nomme les bonnes, pour que l'appel suivant réussisse. Pure.
     *
     * @param array{unknown: list<string>, ambiguous: list<array{wanted:string, candidates:list<string>}>} $pick
     * @param list<array<string, mixed>> $catalog
     */
    public static function pickError(array $pick, array $catalog): string
    {
        $parts = [];
        if ($pick['unknown'] !== []) {
            $parts[] = 'Aucune image de référence ne s\'appelle ' . self::quoted($pick['unknown']) . '.';
        }
        foreach ($pick['ambiguous'] as $a) {
            $parts[] = 'Plusieurs images correspondent à « ' . $a['wanted'] . ' » : '
                . self::quoted(array_slice($a['candidates'], 0, 8)) . ' — reprends le nom exact.';
        }
        $names = array_map(static fn(array $e): string => $e['key'], array_slice($catalog, 0, self::MAX_LISTED));
        $parts[] = count($catalog) > self::MAX_LISTED
            ? 'Cherche-la avec `chercher_images_reference`, puis reprends son nom exact.'
            : 'Images disponibles : ' . self::quoted($names) . '.';
        $parts[] = 'Aucune image n\'a été générée.';
        return implode(' ', $parts);
    }

    /**
     * Images dont le nom ou la description répondent à une requête : chaque
     * mot significatif trouvé compte, trois fois s'il est dans le nom. Les
     * meilleures d'abord, l'ordre du catalogue départageant. Pure.
     *
     * @param list<array<string, mixed>> $catalog
     * @return list<array<string, mixed>>
     */
    public static function search(array $catalog, string $query, int $limit = 10): array
    {
        $scored = self::scored($catalog, $query);
        $hits = array_values(array_filter($scored, static fn(array $s): bool => $s['score'] > 0));
        return array_map(static fn(array $s): array => $s['entry'], array_slice($hits, 0, max(1, $limit)));
    }

    /**
     * Catalogue réordonné pour un message : images de l'assistant en tête
     * (choisies pour lui), puis celles des bases les plus proches du texte,
     * puis le reste dans l'ordre d'origine. Sert à choisir les MAX_LISTED
     * montrées au modèle quand il y en a davantage. Pure.
     *
     * @param list<array<string, mixed>> $catalog
     * @return list<array<string, mixed>>
     */
    public static function rank(array $catalog, string $text): array
    {
        $assistant = array_values(array_filter($catalog, static fn(array $e): bool => $e['origin'] === 'assistant'));
        $kb        = array_values(array_filter($catalog, static fn(array $e): bool => $e['origin'] !== 'assistant'));
        return array_merge($assistant, array_map(static fn(array $s): array => $s['entry'], self::scored($kb, $text)));
    }

    /** @return list<array{entry: array<string, mixed>, score: int}> trié par score décroissant, ordre d'origine conservé à égalité */
    private static function scored(array $catalog, string $query): array
    {
        $words = self::words($query);
        $scored = [];
        foreach (array_values($catalog) as $i => $e) {
            $score = 0;
            if ($words !== []) {
                $name = self::fold($e['key'] . ' ' . $e['name']);
                foreach ($words as $w) {
                    if (str_contains($name, $w)) {
                        $score += 3;
                    } elseif (str_contains((string)$e['search'], $w)) {
                        $score += 1;
                    }
                }
            }
            $scored[] = ['entry' => $e, 'score' => $score, 'i' => $i];
        }
        usort($scored, static fn(array $a, array $b): int => [$b['score'], $a['i']] <=> [$a['score'], $b['i']]);
        return array_map(static fn(array $s): array => ['entry' => $s['entry'], 'score' => $s['score']], $scored);
    }

    /** Mots significatifs d'une requête (au moins trois lettres, ou deux avec un chiffre), mots vides retirés. */
    private static function words(string $text): array
    {
        $stop = ['les', 'des', 'une', 'pour', 'avec', 'dans', 'sur', 'par', 'qui', 'que', 'est', 'son', 'ses',
            'aux', 'ces', 'cette', 'mais', 'the', 'and', 'with', 'image', 'images', 'photo', 'photos'];
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::fold($text)) ?: [] as $w) {
            $significant = mb_strlen($w) >= 3 || (mb_strlen($w) === 2 && preg_match('/\d/', $w) === 1);
            if ($significant && !in_array($w, $stop, true)) {
                $out[$w] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Références citées par leur nom dans un texte (nom complet, ou nom sans
     * extension d'au moins quatre caractères) — repli de l'outil Image du menu
     * « + » quand aucun modèle ne peut préparer la demande. Pure.
     *
     * @param list<array<string, mixed>> $catalog
     * @return list<string> clés, dans l'ordre du catalogue
     */
    public static function mentionedIn(array $catalog, string $text): array
    {
        $hay = self::fold($text);
        if ($hay === '') {
            return [];
        }
        $out = [];
        foreach ($catalog as $e) {
            $full = self::fold($e['name']);
            $base = self::fold((string)pathinfo($e['name'], PATHINFO_FILENAME));
            if (($full !== '' && str_contains($hay, $full)) || (mb_strlen($base) >= 4 && str_contains($hay, $base))) {
                $out[] = $e['key'];
            }
        }
        return array_slice($out, 0, self::MAX_PER_GENERATION);
    }

    /**
     * Référence par défaut — la première image de l'assistant. C'est ce que
     * faisait l'outil avant que les références ne se choisissent : un
     * assistant configuré autour d'une image (charte, personnage) continue
     * de partir d'elle quand le modèle ne nomme rien. Jamais une image de
     * base : parmi des dizaines, la première venue n'a rien de « par défaut ».
     *
     * @param list<array<string, mixed>> $catalog
     */
    public static function defaultReference(array $catalog): ?array
    {
        foreach ($catalog as $e) {
            if ($e['origin'] === 'assistant') {
                return $e;
            }
        }
        return null;
    }

    /**
     * Liste montrée au modèle : une ligne par image, sa provenance et le
     * début de sa description. Pure.
     *
     * @param list<array<string, mixed>> $catalog déjà ordonné (cf. rank)
     */
    public static function promptList(array $catalog, int $max = self::MAX_LISTED): string
    {
        $lines = [];
        foreach (array_slice($catalog, 0, max(1, $max)) as $e) {
            $line = '- « ' . $e['key'] . ' » (' . self::originLabel($e) . ')';
            if ($e['description'] !== '') {
                $line .= ' : ' . $e['description'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** « assistant » ou « base X » : d'où vient une image. Pure. */
    public static function originLabel(array $entry): string
    {
        if (($entry['origin'] ?? '') === 'assistant') {
            return 'assistant';
        }
        $source = (string)($entry['source'] ?? '');
        return $source !== '' ? 'base « ' . $source . ' »' : 'base de connaissances';
    }

    /**
     * Côtés d'une image ramenée à `$max` px au plus sur son plus grand côté,
     * proportions gardées. Pure.
     *
     * @return array{0:int, 1:int}
     */
    public static function fitWithin(int $width, int $height, int $max): array
    {
        if ($width <= 0 || $height <= 0 || ($width <= $max && $height <= $max)) {
            return [max(0, $width), max(0, $height)];
        }
        $ratio = min($max / $width, $max / $height);
        return [max(1, (int)round($width * $ratio)), max(1, (int)round($height * $ratio))];
    }

    /** « a », « b » : noms cités. Pure. */
    public static function quoted(array $names): string
    {
        return implode(', ', array_map(static fn($n): string => '« ' . (string)$n . ' »', $names));
    }

    /** Minuscules sans accents ni blancs superflus, pour comparer des noms. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
        $s = (string)preg_replace('/\p{Mn}+/u', '', is_string($t) ? $t : $s);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    // =====================================================================
    // Lecture (base, disque)
    // =====================================================================

    /**
     * Catalogue d'un message : images des documents de référence de
     * l'assistant et des bases LOCALES reçues — l'appelant les a déjà passées
     * au périmètre de la personne (forProfileAndUser, selectedKbsForTurn).
     * Une base en direct (MyBrain) n'a pas de fichier : elle n'y figure pas.
     * Ne lève jamais : un incident rend un catalogue vide, l'image se fait
     * alors sans référence, comme avant.
     *
     * @param list<array<string, mixed>> $kbs bases du message
     * @return list<array<string, mixed>>
     */
    public static function forTurn(?array $profile, array $kbs): array
    {
        try {
            $db = Database::getInstance();
            $profileRows = [];
            if ($profile !== null && (int)($profile['id'] ?? 0) > 0) {
                $profileId = (int)$profile['id'];
                $stmt = $db->prepare(
                    "SELECT id, file_name, stored_name, mime_type, file_size
                     FROM profile_documents
                     WHERE profile_id = ? AND mime_type LIKE 'image/%'
                     ORDER BY created_at ASC, id ASC
                     LIMIT " . self::MAX_CATALOG
                );
                $stmt->execute([$profileId]);
                foreach ($stmt->fetchAll() as $row) {
                    $row['path'] = ProfileDocument::storageDirFor($profileId) . '/' . $row['stored_name'];
                    $profileRows[] = $row;
                }
            }

            $kbNames = [];
            foreach ($kbs as $kb) {
                if (!KnowledgeBase::isRemote($kb) && (int)($kb['id'] ?? 0) > 0) {
                    $kbNames[(int)$kb['id']] = (string)($kb['name'] ?? '');
                }
            }
            $kbRows = [];
            if ($kbNames !== []) {
                $ids = array_keys($kbNames);
                $stmt = $db->prepare(
                    "SELECT id, kb_id, file_name, stored_name, mime_type, file_size,
                            LEFT(extracted_text, " . (self::SEARCH_CHARS + 80) . ") AS extracted_text
                     FROM knowledge_base_documents
                     WHERE kb_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                       AND mime_type LIKE 'image/%'
                     ORDER BY kb_id ASC, file_name ASC, id ASC
                     LIMIT " . self::MAX_CATALOG
                );
                $stmt->execute($ids);
                $order = array_flip($ids);
                $rows  = $stmt->fetchAll();
                // L'ordre des bases est celui reçu (bases de l'assistant, puis
                // celles cochées), pas celui de leurs identifiants.
                usort($rows, static fn(array $a, array $b): int
                    => [$order[(int)$a['kb_id']] ?? 0, (string)$a['file_name']] <=> [$order[(int)$b['kb_id']] ?? 0, (string)$b['file_name']]);
                foreach ($rows as $row) {
                    $row['kb_name'] = $kbNames[(int)$row['kb_id']] ?? '';
                    $row['path']    = KnowledgeBase::storageDirFor((int)$row['kb_id']) . '/' . $row['stored_name'];
                    $kbRows[] = $row;
                }
            }

            return self::catalog($profileRows, $kbRows);
        } catch (\Throwable $e) {
            error_log('[Références image] catalogue illisible : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Charge les images choisies, prêtes à partir chez le fournisseur :
     * fichier relu sous les seuls dossiers de stockage des documents, format
     * accepté par les deux fournisseurs (GIF converti en PNG), plus grand
     * côté ramené à MAX_SIDE et poids borné. Une image qui ne peut pas
     * partir est NOMMÉE dans `failed`, jamais écartée en silence : l'image
     * générée sans elle ne ressemblerait pas à ce qui était demandé.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{images: list<array{b64:string, mime:string, name:string}>, failed: list<array{name:string, reason:string}>}
     */
    public static function loadMany(array $entries): array
    {
        $images = [];
        $failed = [];
        $total  = 0;
        foreach ($entries as $e) {
            $name = (string)($e['key'] ?? $e['name'] ?? '');
            $loaded = self::load($e);
            if (isset($loaded['error'])) {
                $failed[] = ['name' => $name, 'reason' => $loaded['error']];
                continue;
            }
            $bytes = (int)(strlen($loaded['b64']) * 3 / 4);
            if ($total + $bytes > self::MAX_TOTAL_BYTES) {
                $failed[] = ['name' => $name, 'reason' => 'trop lourde avec les autres références'];
                continue;
            }
            $total += $bytes;
            $images[] = $loaded + ['name' => $name];
        }
        return ['images' => $images, 'failed' => $failed];
    }

    /**
     * Une image prête à partir, ou `error` (raison courte, en français).
     *
     * @return array{b64:string, mime:string}|array{error:string}
     */
    public static function load(array $entry): array
    {
        $path = self::confinedPath((string)($entry['path'] ?? ''));
        if ($path === null) {
            return ['error' => 'fichier introuvable'];
        }
        $binary = @file_get_contents($path);
        if (!is_string($binary) || $binary === '') {
            return ['error' => 'fichier illisible'];
        }
        $mime = self::detectMime($binary, (string)($entry['mime'] ?? ''));
        if (!str_starts_with($mime, 'image/')) {
            return ['error' => 'ce n\'est pas une image'];
        }

        $size = @getimagesizefromstring($binary);
        $tooWide = is_array($size) && max((int)$size[0], (int)$size[1]) > self::MAX_SIDE;
        $sendable = in_array($mime, self::SENDABLE_MIMES, true);
        if ($sendable && !$tooWide && strlen($binary) <= self::MAX_BYTES_PER_IMAGE) {
            return ['b64' => base64_encode($binary), 'mime' => $mime];
        }

        $converted = self::reencode($binary, $mime);
        if ($converted !== null && strlen($converted['binary']) <= self::MAX_BYTES_PER_IMAGE) {
            return ['b64' => base64_encode($converted['binary']), 'mime' => $converted['mime']];
        }
        if ($sendable && strlen($binary) <= self::MAX_BYTES_PER_IMAGE) {
            // Trop grande en pixels mais impossible à réduire ici (format que
            // GD ne lit pas) : le fournisseur la redimensionne lui-même.
            return ['b64' => base64_encode($binary), 'mime' => $mime];
        }
        return ['error' => $sendable ? 'trop lourde' : 'format non pris en charge (' . $mime . ')'];
    }

    /** Chemin réel sous un dossier de stockage de documents, sinon null. */
    private static function confinedPath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        foreach ([ProfileDocument::storageRoot(), KnowledgeBase::storageRoot()] as $root) {
            $rootReal = realpath($root);
            if ($rootReal !== false && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        return null;
    }

    /**
     * Type réel du contenu : le type enregistré peut mentir (un texte déposé
     * sous un nom en .png). Le type enregistré ne sert que si le contenu ne
     * peut pas être inspecté.
     */
    private static function detectMime(string $binary, string $stored): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = strtolower(trim((string)finfo_buffer($finfo, $binary)));
                if ($detected !== '') {
                    return $detected;
                }
            }
        }
        return strtolower($stored);
    }

    /**
     * Ré-encodage par GD : réduite à MAX_SIDE, PNG si l'image a de la
     * transparence (ou était un PNG ou un GIF), JPEG sinon. Null si GD est
     * absent ou ne sait pas lire le format.
     *
     * @return array{binary:string, mime:string}|null
     */
    private static function reencode(string $binary, string $mime): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        [$nw, $nh] = self::fitWithin($w, $h, self::MAX_SIDE);
        $keepAlpha = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true);

        $dst = $src;
        if ($nw !== $w || $nh !== $h || $keepAlpha) {
            $dst = imagecreatetruecolor($nw, $nh);
            if ($keepAlpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        }

        ob_start();
        $ok = $keepAlpha ? imagepng($dst, null, 6) : imagejpeg($dst, null, 88);
        $out = (string)ob_get_clean();
        if (!$ok || $out === '') {
            return null;
        }
        return ['binary' => $out, 'mime' => $keepAlpha ? 'image/png' : 'image/jpeg'];
    }
}
