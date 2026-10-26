<?php

declare(strict_types=1);

/**
 * Recherche dans les AUTRES conversations de l'utilisateur (outil
 * `rechercher_conversations`) — SOURCE UNIQUE.
 *
 * LE PROBLÈME RÉSOLU. « Reprends le plan qu'on avait fait la semaine
 * dernière », « tu m'avais conseillé un outil, lequel ? » : chaque
 * conversation était un îlot. La mémoire « MIA pour vous » retient des faits
 * durables, pas le contenu des échanges ; l'utilisateur devait retrouver la
 * conversation lui-même et recopier le passage.
 *
 * Règles :
 *   - STRICTEMENT les conversations du compte (`c.user_id`), jamais celle en
 *     cours (déjà dans le contexte) ;
 *   - pas d'index plein texte ni de migration : des `LIKE` par mot significatif,
 *     dans le contenu OU le titre, sur la collation de la base (insensible à la
 *     casse et aux accents) — d'abord « tous les mots », puis « au moins un »
 *     pour compléter, classés par nombre de mots retrouvés puis récence ;
 *   - les réponses en erreur (⚠️) ne sont pas des souvenirs : écartées ;
 *   - le résultat porte le lien de chaque conversation (`/chat/{id}`), pour que
 *     la réponse cite d'où vient l'information.
 *
 * `tokens`, `snippet`, `group` et `looksLikePastReference` sont PURES.
 */
final class ConversationSearch
{
    public const MAX_TOKENS        = 6;
    public const MAX_CONVERSATIONS = 8;
    public const DEFAULT_RESULTS   = 5;
    public const MAX_SNIPPETS      = 2;
    public const SNIPPET_CHARS     = 280;
    /** Messages candidats lus au plus par passe. */
    public const SCAN_LIMIT        = 200;
    /** Contenu relu par message (un extrait n'a pas besoin de tout le message). */
    private const CONTENT_CHARS    = 6000;

    /** Mots vides (français + anglais) : joints par AND ils excluraient tout. */
    private const STOP_WORDS = [
        'les', 'des', 'une', 'aux', 'avec', 'pour', 'par', 'sur', 'dans', 'sans', 'sous', 'chez', 'entre',
        'est', 'sont', 'été', 'etre', 'être', 'avoir', 'fait', 'faire', 'que', 'qui', 'quoi', 'dont', 'quel',
        'quelle', 'quels', 'quelles', 'comment', 'pourquoi', 'quand', 'mais', 'donc', 'car', 'cette', 'ces',
        'cet', 'mon', 'mes', 'ton', 'tes', 'son', 'ses', 'notre', 'nos', 'votre', 'vos', 'leur', 'leurs',
        'nous', 'vous', 'ils', 'elles', 'elle', 'lui', 'moi', 'toi', 'tout', 'tous', 'toute', 'toutes',
        'plus', 'moins', 'très', 'tres', 'aussi', 'comme', 'avait', 'avais', 'avions', 'aviez', 'avaient',
        'parlé', 'parle', 'parler', 'discuté', 'discute', 'conversation', 'conversations', 'dernière',
        'derniere', 'dernier', 'fois', 'autre', 'jour', 'retrouve', 'retrouver', 'rappelle', 'souviens',
        'the', 'and', 'for', 'with', 'about', 'what', 'when', 'that', 'this', 'from', 'your', 'you', 'our',
        'did', 'was', 'were', 'have', 'had', 'last', 'time', 'talked', 'discussed',
    ];

    /**
     * Mots significatifs d'une requête : minuscules, ≥ 3 caractères (2 s'il y a
     * un chiffre : « Q3 », « 2026 »), sans mot vide, dédoublonnés.
     *
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if ($w === '' || in_array($w, self::STOP_WORDS, true) || in_array(self::fold($w), self::STOP_WORDS, true)) {
                continue;
            }
            // Un chiffre suffit à rendre un mot court significatif : « Q3 », « 2026 ».
            $hasDigit = preg_match('/\p{N}/u', $w) === 1;
            if (mb_strlen($w) < ($hasDigit ? 2 : 3)) {
                continue;
            }
            // Liste et non clés de tableau : « 2026 » deviendrait l'entier 2026.
            if (!in_array($w, $out, true)) {
                $out[] = $w;
            }
            if (count($out) >= self::MAX_TOKENS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Le message fait-il allusion à un échange PASSÉ ? Porte d'exposition de
     * l'outil quand aucune autre source d'outils n'engage la boucle : sans
     * allusion, inutile de payer sa définition à chaque message.
     *
     * « souviens-toi QUE… » n'en est pas une : c'est une demande de mémoire
     * (cf. captureUserMemory), pas une recherche.
     */
    public static function looksLikePastReference(string $text): bool
    {
        $t = mb_strtolower($text);
        return preg_match(
            "/(l['’]autre jour|la derni[eè]re fois|la fois (pr[eé]c[eé]dente|pass[eé]e)|"
            . "on (en )?(avait|a|avions) (d[eé]j[aà] )?(parl[eé]|discut[eé]|vu|dit|d[eé]cid[eé]|convenu|travaill[eé])|"
            . "nous (en )?avions (d[eé]j[aà] )?(parl[eé]|discut[eé]|vu|dit|d[eé]cid[eé])|"
            . "tu m['’](avais|as d[eé]j[aà]) (dit|conseill[eé]|propos[eé]|expliqu[eé]|donn[eé]|recommand[eé]|r[eé]pondu|aid[eé])|"
            . "(dans|d['’]|sur) une (autre|ancienne|pr[eé]c[eé]dente) (conversation|discussion)|"
            . "(notre|nos) (derni[eè]re|pr[eé]c[eé]dente|ancienne|anciennes|pr[eé]c[eé]dentes) (conversation|discussion|[eé]change)|"
            . "dans nos (anciennes |pr[eé]c[eé]dentes )?(conversations|discussions|[eé]changes)|"
            . "tu te souviens|te souviens[- ]tu|rappelle[- ]toi de|souviens[- ]toi de|"
            . "retrouve (la|notre|cette) (conversation|discussion)|"
            . "last time|the other day|we (talked|spoke|discussed)|you (told|advised|suggested) me|"
            . "(another|previous|earlier) (conversation|chat)|do you remember|remember when)/u",
            $t
        ) === 1;
    }

    /**
     * Recherche (SQL). Ne lève pas : une base indisponible rend une erreur
     * présentable, jamais une exception qui casserait le tour.
     *
     * @return array{conversations: list<array>, note?: string}|array{error: string}
     */
    public static function search(int $userId, int $excludeConvId, string $query, int $limit = self::DEFAULT_RESULTS): array
    {
        $tokens = self::tokens($query);
        if ($tokens === []) {
            return ['error' => 'Requête trop vague : donne un ou plusieurs mots précis (sujet, nom, outil…).'];
        }
        $limit = max(1, min(self::MAX_CONVERSATIONS, $limit));
        try {
            $rows = self::fetch($userId, $excludeConvId, $tokens, true);
            if (count(array_unique(array_column($rows, 'conv_id'))) < $limit && count($tokens) > 1) {
                $seen = array_flip(array_map(static fn(array $r): int => (int)$r['msg_id'], $rows));
                foreach (self::fetch($userId, $excludeConvId, $tokens, false) as $r) {
                    if (!isset($seen[(int)$r['msg_id']])) {
                        $rows[] = $r;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Conversations] recherche impossible (user ' . $userId . ') : ' . $e->getMessage());
            return ['error' => 'La recherche dans les anciennes conversations est indisponible pour le moment.'];
        }
        return ['conversations' => self::group($rows, $tokens, $limit)];
    }

    /**
     * Regroupe les messages trouvés par conversation : la mieux classée
     * d'abord (mots retrouvés, puis récence), au plus MAX_SNIPPETS extraits.
     *
     * @param list<array{conv_id:int|string, title:?string, updated_at:string, msg_id:int|string, role:string, content:string}> $rows
     * @return list<array{titre:string, date:string, lien:string, extraits:list<array{auteur:string, texte:string}>}>
     */
    public static function group(array $rows, array $tokens, int $limit): array
    {
        $scored = [];
        foreach ($rows as $r) {
            $hay = self::fold((string)($r['title'] ?? '') . ' ' . (string)($r['content'] ?? ''));
            $hits = 0;
            foreach ($tokens as $t) {
                if (str_contains($hay, self::fold($t))) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            $scored[] = ['hits' => $hits, 'id' => (int)$r['msg_id'], 'row' => $r];
        }
        usort($scored, static fn(array $a, array $b): int => [$b['hits'], $b['id']] <=> [$a['hits'], $a['id']]);

        $byConv = [];
        foreach ($scored as $s) {
            $r   = $s['row'];
            $cid = (int)$r['conv_id'];
            if (!isset($byConv[$cid])) {
                if (count($byConv) >= $limit) {
                    continue;
                }
                $byConv[$cid] = [
                    'titre'    => trim((string)($r['title'] ?? '')) !== '' ? trim((string)$r['title']) : 'Conversation sans titre',
                    'date'     => self::date((string)($r['updated_at'] ?? '')),
                    'lien'     => '/chat/' . $cid,
                    'extraits' => [],
                ];
            }
            if (count($byConv[$cid]['extraits']) < self::MAX_SNIPPETS) {
                $byConv[$cid]['extraits'][] = [
                    'auteur' => ($r['role'] ?? '') === 'user' ? 'utilisateur' : 'MIA',
                    'texte'  => self::snippet((string)($r['content'] ?? ''), $tokens, self::SNIPPET_CHARS),
                ];
            }
        }
        return array_values($byConv);
    }

    /**
     * Extrait autour du premier mot retrouvé (casse et accents ignorés), sans
     * images, ni blocs de code, ni blancs en série.
     */
    public static function snippet(string $content, array $tokens, int $chars = self::SNIPPET_CHARS): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $content) ?? $content;
        $text = preg_replace('/```[\s\S]*?(```|$)/u', ' [code] ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $len  = mb_strlen($text);
        if ($len <= $chars) {
            return $text;
        }
        $folded = self::fold($text);
        $pos = null;
        foreach ($tokens as $t) {
            $p = mb_strpos($folded, self::fold($t));
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = $pos === null ? 0 : max(0, $pos - intdiv($chars, 3));
        $start = min($start, max(0, $len - $chars));
        return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, $chars)) . ($start + $chars < $len ? '…' : '');
    }

    /** Motif LIKE d'un mot, jokers échappés. */
    public static function likePattern(string $token): string
    {
        return '%' . addcslashes($token, '%_\\') . '%';
    }

    // ===================== Interne =====================

    /**
     * @param list<string> $tokens
     * @return list<array>
     */
    private static function fetch(int $userId, int $excludeConvId, array $tokens, bool $all): array
    {
        $conds  = [];
        $params = [$userId, $excludeConvId];
        foreach ($tokens as $t) {
            $conds[]  = '(m.content LIKE ? OR c.title LIKE ?)';
            $params[] = self::likePattern($t);
            $params[] = self::likePattern($t);
        }
        $sql = 'SELECT c.id AS conv_id, c.title, c.updated_at, m.id AS msg_id, m.role, '
            . 'LEFT(m.content, ' . self::CONTENT_CHARS . ') AS content '
            . 'FROM messages m JOIN conversations c ON c.id = m.conversation_id '
            . 'WHERE c.user_id = ? AND c.id <> ? '
            . "AND m.role IN ('user', 'assistant') "
            . "AND m.content NOT LIKE '⚠%' "
            . 'AND (' . implode($all ? ' AND ' : ' OR ', $conds) . ') '
            . 'ORDER BY m.id DESC LIMIT ' . self::SCAN_LIMIT;
        $st = Database::getInstance()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** « 2026-09-12 14:03:00 » → « 12/09/2026 ». */
    private static function date(string $sql): string
    {
        $ts = strtotime($sql);
        return $ts !== false ? date('d/m/Y', $ts) : '';
    }

    /**
     * Minuscules sans accents, caractère pour caractère (la position d'un mot
     * dans le texte replié est donc sa position dans le texte d'origine).
     */
    private static function fold(string $s): string
    {
        return strtr(mb_strtolower($s), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', '’' => "'",
        ]);
    }
}
