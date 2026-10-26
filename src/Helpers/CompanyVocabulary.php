<?php

declare(strict_types=1);

/**
 * Vocabulaire de l'entreprise (sigles, lexique maison) — SOURCE UNIQUE de sa
 * lecture, de sa sélection et de son fragment de prompt.
 *
 * Réglé sur la page Organisation → Entreprise (clé historique
 * `common_vocabulary`, inchangée : rien à migrer). Il vivait sur Gestion
 * LLMs → Modèles et partait EN ENTIER dans chaque message : un glossaire de
 * trois cents sigles pesait des milliers de jetons à chaque « merci », et
 * noyait les trois termes utiles au milieu des autres.
 *
 * INJECTION SÉLECTIVE, recalculée à CHAQUE message :
 *  - glossaire court (≤ FULL_CHARS) → tout, comme avant : il coûte peu et
 *    aide l'assistant à employer les bons mots même quand personne ne les a
 *    encore écrits ;
 *  - glossaire long → les seules entrées dont le TERME (ou le début de la
 *    définition, « taux journalier moyen » pour TJM) apparaît dans la
 *    conversation : message en cours, derniers échanges, prompt de
 *    l'assistant. Plus les lignes libres (une phrase sans « terme : sens »),
 *    qui sont du contexte général. Un terme qui surgit au dixième message
 *    est pris à ce message-là.
 * Correspondance insensible à la casse et aux accents, sur mots entiers
 * (« CRA » ne se trouve pas dans « crayon »), pluriel en -s/-x accepté.
 * Un faux positif coûte une ligne ; un faux négatif, un contresens — d'où la
 * consigne finale : un sigle maison inconnu se demande, il ne s'invente pas.
 *
 * Formes PURES (testables sans base) ; seule `raw()` lit les réglages, sans
 * jamais lever.
 */
final class CompanyVocabulary
{
    public const SETTING = 'common_vocabulary';

    /** Plafond de saisie (caractères) : au-delà, ce n'est plus un glossaire. */
    public const MAX_CHARS = 30_000;

    /** En deçà, le glossaire part en entier (≈ 500 jetons). */
    public const FULL_CHARS = 2_000;

    /** Entrées retenues au plus pour un message, et poids de leurs lignes. */
    public const MAX_SELECTED       = 40;
    public const MAX_SELECTED_CHARS = 4_000;

    /** Lignes libres (contexte général) gardées au plus en mode sélectif. */
    public const MAX_FREE_CHARS = 1_200;

    /** Contexte de recherche des termes (caractères, les plus récents). */
    public const MAX_CONTEXT_CHARS = 12_000;

    public const HEADER = "Vocabulaire et acronymes de l'entreprise";

    /** Séparateurs « terme : définition » reconnus, du plus sûr au moins sûr. */
    private const SEPARATORS = ['/^(.{1,60}?)\s*:\s+(.+)$/u', '/^(.{1,60}?)\s+[=→–—-]>?\s+(.+)$/u', '/^(.{1,60}?)\t+(.+)$/u'];

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public static function raw(): string
    {
        try {
            return self::sanitize((string)Settings::get(self::SETTING, ''));
        } catch (Throwable) {
            return '';
        }
    }

    /** Saisie : fins de ligne unifiées, caractères de contrôle retirés, borné. */
    public static function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text);
        $text = (string)preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = trim((string)preg_replace('/\n{3,}/u', "\n\n", $text));

        return rtrim(mb_substr($text, 0, self::MAX_CHARS));
    }

    /**
     * Le glossaire, ligne à ligne : entrées « terme : définition » (puce de
     * liste tolérée) et lignes libres. Les titres Markdown (`## RH`) ne
     * servent qu'à la lecture humaine : ni entrée ni ligne libre.
     *
     * @return array{entries: list<array{term: string, definition: string, line: string}>, free: list<string>}
     */
    public static function parse(string $text): array
    {
        $entries = [];
        $free    = [];
        foreach (explode("\n", self::sanitize($text)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $body = trim((string)preg_replace('/^(?:[-*•·]|\d+[.)])\s+/u', '', $line));
            $entry = null;
            foreach (self::SEPARATORS as $pattern) {
                if (preg_match($pattern, $body, $m) === 1) {
                    $term = trim($m[1], " \t*_`\"'«»");
                    $def  = trim($m[2]);
                    // Un « terme » de plus de six mots est une phrase qui
                    // contient un deux-points, pas une entrée de glossaire.
                    if ($term !== '' && $def !== '' && count(preg_split('/\s+/u', $term) ?: []) <= 6) {
                        $entry = ['term' => $term, 'definition' => $def, 'line' => '- ' . $term . ' : ' . $def];
                    }
                    break;
                }
            }
            if ($entry !== null) {
                $entries[] = $entry;
            } else {
                $free[] = $body;
            }
        }

        return ['entries' => $entries, 'free' => $free];
    }

    // ------------------------------------------------------------------
    // Sélection
    // ------------------------------------------------------------------

    /**
     * Ce qui part au modèle pour ce contexte.
     *
     * @return array{mode: string, lines: list<string>, total: int, matched: int, terms: list<string>}
     *         mode : 'none' (glossaire vide), 'all' (glossaire court), 'selected'
     */
    public static function select(string $text, string $context): array
    {
        $text = self::sanitize($text);
        if ($text === '') {
            return ['mode' => 'none', 'lines' => [], 'total' => 0, 'matched' => 0, 'terms' => []];
        }
        $parsed = self::parse($text);
        $total  = count($parsed['entries']);

        if (mb_strlen($text) <= self::FULL_CHARS) {
            return ['mode' => 'all', 'lines' => [$text], 'total' => $total, 'matched' => $total,
                'terms' => array_column($parsed['entries'], 'term')];
        }

        $haystack = ' ' . self::normalize(mb_substr($context, -self::MAX_CONTEXT_CHARS)) . ' ';
        $lines    = [];
        $terms    = [];
        $weight   = 0;
        foreach ($parsed['entries'] as $entry) {
            if (count($terms) >= self::MAX_SELECTED) {
                break;
            }
            if (!self::mentioned($entry, $haystack)) {
                continue;
            }
            $size = mb_strlen($entry['line']) + 1;
            if ($weight + $size > self::MAX_SELECTED_CHARS) {
                continue;
            }
            $lines[] = $entry['line'];
            $terms[] = $entry['term'];
            $weight += $size;
        }

        $freeWeight = 0;
        $freeLines  = [];
        foreach ($parsed['free'] as $line) {
            $size = mb_strlen($line) + 1;
            if ($freeWeight + $size > self::MAX_FREE_CHARS) {
                break;
            }
            $freeLines[] = $line;
            $freeWeight += $size;
        }

        return ['mode' => 'selected', 'lines' => array_merge($freeLines, $lines), 'total' => $total,
            'matched' => count($terms), 'terms' => $terms];
    }

    /**
     * Le terme (ou le début de sa définition, s'il fait au moins deux mots)
     * figure-t-il dans le contexte, en mot entier ? `$haystack` est déjà
     * normalisé et entouré d'espaces.
     *
     * @param array{term: string, definition: string} $entry
     */
    public static function mentioned(array $entry, string $haystack): bool
    {
        $term = self::normalize($entry['term']);
        if (mb_strlen(str_replace(' ', '', $term)) >= 2) {
            foreach (['', 's', 'x'] as $suffix) {
                if (str_contains($haystack, ' ' . $term . $suffix . ' ')) {
                    return true;
                }
            }
        }

        // « Taux Journalier Moyen (prix d'une journée) » → « taux journalier moyen ».
        $head = self::normalize((string)preg_split('/[(,;.]/u', $entry['definition'])[0]);
        if (substr_count($head, ' ') >= 1 && mb_strlen($head) >= 8) {
            return str_contains($haystack, ' ' . $head . ' ');
        }

        return false;
    }

    /**
     * Minuscules, sans accents, ponctuation ramenée à des espaces : « R&D »
     * et « r&d » donnent tous deux « r d ».
     */
    public static function normalize(string $text): string
    {
        $s = mb_strtolower($text);
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        ]);
        $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    // ------------------------------------------------------------------
    // Prompt
    // ------------------------------------------------------------------

    /** Fragment du prompt système pour ce contexte, '' sans glossaire. */
    public static function promptFragment(string $context): string
    {
        return self::fragmentFrom(self::raw(), $context);
    }

    /**
     * Forme PURE du fragment. Prompt de MÉTHODE, en français : il ne cite
     * aucun libellé de l'écran.
     */
    public static function fragmentFrom(string $text, string $context): string
    {
        $sel = self::select($text, $context);
        if ($sel['mode'] === 'none') {
            return '';
        }
        if ($sel['mode'] === 'all') {
            return "\n\n" . self::HEADER . " :\n" . $sel['lines'][0];
        }

        if ($sel['lines'] === []) {
            return "\n\nL'entreprise a un glossaire de " . $sel['total'] . ' termes ; aucun ne figure dans cette conversation.'
                . " Si l'utilisateur emploie un sigle ou un terme maison que tu ne connais pas, demande son sens plutôt que de l'inventer.";
        }

        $intro = match (true) {
            $sel['matched'] > 1  => self::HEADER . ' — les ' . $sel['matched'] . ' entrées (sur ' . $sel['total'] . ') qui concernent cette conversation',
            $sel['matched'] === 1 => self::HEADER . ' — l\'entrée (sur ' . $sel['total'] . ') qui concerne cette conversation',
            default               => self::HEADER . ' — contexte général (aucun terme du glossaire ne figure dans cette conversation)',
        };

        return "\n\n" . $intro . " :\n" . implode("\n", $sel['lines'])
            . "\nUn sigle ou un terme maison absent de cette liste : demande son sens plutôt que de l'inventer.";
    }

    /**
     * Texte dans lequel chercher les termes : prompt de l'assistant, puis les
     * derniers échanges, le message en cours en dernier (le plus récent est
     * gardé quand il faut couper).
     *
     * @param list<array<string, mixed>> $history Messages (clé `content`).
     */
    public static function contextFrom(string $current, array $history = [], string $assistantPrompt = '', int $lastMessages = 8): string
    {
        $parts = [];
        if (trim($assistantPrompt) !== '') {
            $parts[] = mb_substr($assistantPrompt, 0, 3000);
        }
        foreach (array_slice($history, -$lastMessages) as $message) {
            $content = trim((string)($message['content'] ?? ''));
            if ($content !== '' && !str_starts_with($content, '⚠')) {
                $parts[] = mb_substr($content, 0, 3000);
            }
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return mb_substr(implode("\n", $parts), -self::MAX_CONTEXT_CHARS);
    }
}
