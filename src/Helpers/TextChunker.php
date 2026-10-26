<?php

declare(strict_types=1);

/**
 * Découpage et empilage de texte en chunks pour le RAG (documents de référence
 * des assistants ET bases de connaissances). Splitter pur : aucun accès DB,
 * aucune dépendance — testable isolément.
 *
 * Extrait de ProfileDocument (qui délègue désormais ici) pour être partagé
 * avec KnowledgeBaseDocument sans duplication.
 */
final class TextChunker
{
    /**
     * Tailles exprimées en tokens estimés (1 token ≈ 4 caractères).
     *   TARGET  : taille visée d'un chunk regroupé
     *   MAX     : un paragraphe plus gros est hard-splitté à cette borne
     *   OVERLAP : recouvrement reporté en tête du chunk suivant (recall)
     */
    public const CHUNK_TARGET_TOKENS  = 500;
    public const CHUNK_MAX_TOKENS     = 800;
    public const CHUNK_OVERLAP_TOKENS = 60;

    /**
     * Estimation grossière de tokens (1 token ≈ 4 caractères pour texte latin).
     */
    public static function estimateTokens(?string $text): int
    {
        if ($text === null || $text === '') {
            return 0;
        }
        return (int)ceil(mb_strlen($text) / 4);
    }

    /**
     * Découpe un texte en chunks.
     *   1. Normalise les fins de ligne, découpe en paragraphes (lignes vides).
     *   2. Hard-split des paragraphes géants à CHUNK_MAX_TOKENS.
     *   3. Regroupe les unités jusqu'à CHUNK_TARGET_TOKENS.
     *   4. Reporte un overlap en tête du chunk suivant.
     * @return list<string>
     */
    public static function splitIntoChunks(string $text): array
    {
        $units = self::paragraphUnits($text, self::CHUNK_MAX_TOKENS * 4);
        if ($units === []) {
            return [];
        }
        return self::regroupUnits($units, self::CHUNK_TARGET_TOKENS * 4, self::CHUNK_OVERLAP_TOKENS * 4);
    }

    /**
     * Étapes 1+2 : normalise, découpe en paragraphes (lignes vides), et
     * hard-split les paragraphes dépassant $maxChars. Unité atomique de base,
     * partagée par le chunker heuristique ET le chunker sémantique (LLM) :
     * ce dernier regroupe ces mêmes unités selon des frontières de sens plutôt
     * que par taille, ce qui garantit l'intégrité du contenu (jamais réécrit).
     * @return list<string>
     */
    public static function paragraphUnits(string $text, int $maxChars): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }
        $units = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) <= $maxChars) {
                $units[] = $para;
            } else {
                foreach (self::hardSplit($para, $maxChars) as $piece) {
                    $units[] = $piece;
                }
            }
        }
        return $units;
    }

    /**
     * Étapes 3+4 : regroupe des unités jusqu'à $targetChars, puis reporte un
     * overlap ($overlapChars) en tête du chunk suivant (recall). Découpage
     * purement dimensionnel — le chunker sémantique fournit sa propre logique
     * de regroupement (frontières LLM) et ne passe pas par ici.
     * @param list<string> $units
     * @return list<string>
     */
    public static function regroupUnits(array $units, int $targetChars, int $overlapChars): array
    {
        $chunks = [];
        $buf    = '';
        foreach ($units as $u) {
            if ($buf === '') {
                $buf = $u;
            } elseif (mb_strlen($buf) + 2 + mb_strlen($u) <= $targetChars) {
                $buf .= "\n\n" . $u;
            } else {
                $chunks[] = $buf;
                $buf = $u;
            }
        }
        if ($buf !== '') {
            $chunks[] = $buf;
        }

        return self::applyOverlap($chunks, $overlapChars);
    }

    /**
     * Reporte la queue ($overlapChars caractères) de chaque chunk en tête du
     * suivant (recall au voisinage des frontières), puis nettoie. Partagé par
     * le regroupement heuristique et le regroupement sémantique.
     * @param list<string> $chunks
     * @return list<string>
     */
    public static function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars > 0 && count($chunks) > 1) {
            $out      = [];
            $prevTail = '';
            foreach ($chunks as $c) {
                $out[]    = $prevTail !== '' ? ($prevTail . "\n\n" . $c) : $c;
                $prevTail = mb_substr($c, max(0, mb_strlen($c) - $overlapChars));
            }
            $chunks = $out;
        }

        return array_values(array_filter(
            array_map('trim', $chunks),
            static fn($c) => $c !== ''
        ));
    }

    /**
     * Éclate un texte trop long en morceaux ≤ $maxChars, en coupant de
     * préférence sur une frontière de phrase puis d'espace (dans la moitié
     * haute du morceau), sinon coupe nette.
     * @return list<string>
     */
    public static function hardSplit(string $text, int $maxChars): array
    {
        $out = [];
        $len = mb_strlen($text);
        $pos = 0;
        while ($pos < $len) {
            if ($len - $pos <= $maxChars) {
                $out[] = mb_substr($text, $pos);
                break;
            }
            $slice = mb_substr($text, $pos, $maxChars);
            $cut   = 0;
            foreach (['. ', "\n", ' '] as $sep) {
                $p = mb_strrpos($slice, $sep);
                if ($p !== false && $p > (int)($maxChars * 0.5)) {
                    $cut = $p + mb_strlen($sep);
                    break;
                }
            }
            if ($cut <= 0) {
                $cut = $maxChars;
            }
            $out[] = trim(mb_substr($text, $pos, $cut));
            $pos  += $cut;
        }
        return $out;
    }

    /**
     * Empile des lignes {file_name, content, token_count} jusqu'au budget.
     * Garantit au moins le meilleur chunk (tronqué au budget si nécessaire).
     * Format : "--- fichier ---\ncontenu", blocs joints par "\n\n".
     * @param list<array<string,mixed>> $rows
     */
    public static function packChunks(array $rows, int $tokenBudget): ?string
    {
        $parts = [];
        $used  = 0;
        foreach ($rows as $row) {
            $content = (string)$row['content'];
            $tk      = (int)($row['token_count'] ?? 0) ?: self::estimateTokens($content);

            if ($parts === [] && $tk > $tokenBudget) {
                // Meilleur chunk déjà trop gros : on le tronque au budget.
                $content = mb_substr($content, 0, $tokenBudget * 4);
                $parts[] = '--- ' . $row['file_name'] . " ---\n" . $content;
                break;
            }
            if ($used + $tk > $tokenBudget) {
                if ($parts === []) {
                    continue;
                }
                break;
            }
            $parts[] = '--- ' . $row['file_name'] . " ---\n" . $content;
            $used   += $tk;
        }
        return $parts ? implode("\n\n", $parts) : null;
    }
}
