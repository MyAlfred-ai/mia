<?php

declare(strict_types=1);

/**
 * Nettoyage des pages HTML générées par LLM.
 *
 * Source unique partagée entre le chat (ChatController) et la page de partage
 * (ShareController) : extraction du document depuis la réponse brute du modèle
 * (fences markdown, préambule texte) puis normalisation en document complet
 * responsive (doctype, charset, viewport).
 */
class GeneratedHtml
{
    /**
     * Marqueur que le LLM pose APRÈS </html> en mode modification : la ligne
     * qui le suit devient le court résumé des ajustements affiché dans le chat.
     */
    public const SUMMARY_MARKER = 'RESUME_MODIFICATIONS:';

    /** Un résumé reste un résumé — au-delà, on tronque. */
    private const SUMMARY_MAX_LEN = 800;

    /**
     * Dernière page HTML produite dans une conversation — base de travail des
     * demandes de modification. Statique : appelée aussi bien depuis le
     * contrôleur que depuis le worker de génération (HtmlPageJob).
     */
    public static function latestForConversation(int $conversationId): ?string
    {
        $history = Message::forConversation($conversationId);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $msg = $history[$i];
            if (($msg['role'] ?? '') !== 'assistant') {
                continue;
            }
            if (Message::creationType($msg) !== 'html_page') {
                continue;
            }
            $html = trim((string)($msg['content'] ?? ''));
            if ($html !== '') {
                return $html;
            }
        }

        return null;
    }

    /**
     * Sépare le résumé de modifications (SUMMARY_MARKER) de la réponse brute.
     *
     * Le marqueur est cherché en DERNIÈRE occurrence (une page peut citer le
     * mot dans son contenu), en tolérant les variantes des modèles : accents,
     * espace au lieu de l'underscore, gras markdown. Garde-fou : si le texte
     * situé après le marqueur contient encore le document (<html / <!doctype),
     * ce n'est pas un résumé — la réponse est rendue intacte plutôt qu'amputée.
     *
     * Statique pure (testée sans base ni LLM).
     *
     * @return array{0: string, 1: ?string} [réponse sans le résumé, résumé ou null]
     */
    public static function splitSummary(string $raw): array
    {
        $pattern = '/[*_]{0,3}R[ÉE]SUM[ÉE]?[ _]MODIFICATIONS[\s*_]*:[ \t*_]*/iu';
        if (!preg_match_all($pattern, $raw, $m, PREG_OFFSET_CAPTURE)) {
            return [$raw, null];
        }

        [$match, $offset] = end($m[0]);
        $before  = substr($raw, 0, (int)$offset);
        $summary = substr($raw, (int)$offset + strlen((string)$match));

        // Le « résumé » contient encore un document ? Marqueur mal placé.
        if (preg_match('/<html\b|<!doctype\s+html\b/i', $summary)) {
            return [$raw, null];
        }

        // Fence markdown résiduel (modèle qui clôt sa réponse par ```).
        $summary = preg_replace('/\s*```\s*$/', '', trim($summary)) ?? $summary;
        $summary = trim($summary);
        if (mb_strlen($summary) > self::SUMMARY_MAX_LEN) {
            $summary = rtrim(mb_substr($summary, 0, self::SUMMARY_MAX_LEN)) . '…';
        }

        return [rtrim($before), $summary !== '' ? $summary : null];
    }

    /** Extrait et normalise le document HTML depuis une réponse LLM brute. */
    public static function sanitize(string $raw): string
    {
        $html = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html) ?? $html;

        // Cas fréquent: texte + bloc ```html ... ```
        if (preg_match('/```(?:html|htm)?\s*([\s\S]*?)\s*```/i', $html, $m)) {
            $html = trim((string)$m[1]);
        } else {
            $html = preg_replace('/^```(?:html|htm)?\s*/i', '', $html) ?? $html;
            $html = preg_replace('/\s*```\s*$/i', '', $html) ?? $html;
            $html = trim($html);
        }

        // Conserver uniquement le document HTML si trouvé.
        if (preg_match('/<!doctype\s+html\b[\s\S]*$/i', $html, $m)) {
            $html = trim((string)$m[0]);
        }
        if (preg_match('/<html\b[\s\S]*<\/html>/i', $html, $m)) {
            $html = trim((string)$m[0]);
        }

        // Éliminer un éventuel préambule texte résiduel.
        if (!preg_match('/^\s*</', $html)) {
            $firstTagPos = strpos($html, '<');
            if ($firstTagPos !== false) {
                $html = trim(substr($html, $firstTagPos));
            }
        }

        return self::ensureResponsiveDocument($html);
    }

    /** Garantit un document complet avec doctype, charset et viewport. */
    public static function ensureResponsiveDocument(string $html): string
    {
        $html = trim($html);
        $baseHead = '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';

        if ($html === '') {
            return '<!DOCTYPE html><html lang="fr"><head>' . $baseHead . '</head><body></body></html>';
        }

        if (!preg_match('/<html\b/i', $html)) {
            if (preg_match('/<body\b[\s\S]*<\/body>/i', $html, $m)) {
                $body = (string)$m[0];
            } else {
                $body = '<body>' . $html . '</body>';
            }
            return '<!DOCTYPE html><html lang="fr"><head>' . $baseHead . '</head>' . $body . '</html>';
        }

        if (preg_match('/<html\b[\s\S]*<\/html>/i', $html, $m)) {
            $html = trim((string)$m[0]);
        }

        if (!preg_match('/<!doctype\s+html\b/i', $html)) {
            $html = "<!DOCTYPE html>\n" . $html;
        }

        if (!preg_match('/<head\b/i', $html)) {
            $html = preg_replace('/<html\b([^>]*)>/i', '<html$1><head>' . $baseHead . '</head>', $html, 1) ?? $html;
        } else {
            if (!preg_match('/<meta[^>]+charset\s*=\s*["\']?utf-8["\']?/i', $html)) {
                $html = preg_replace('/<head\b[^>]*>/i', '$0<meta charset="UTF-8">', $html, 1) ?? $html;
            }
            if (!preg_match('/<meta[^>]+name\s*=\s*["\']viewport["\']/i', $html)) {
                $html = preg_replace('/<head\b[^>]*>/i', '$0<meta name="viewport" content="width=device-width, initial-scale=1.0">', $html, 1) ?? $html;
            }
        }

        return trim($html);
    }

    /**
     * Embarque les images générées (/assets/img/generated/...) en data-URI :
     * le fichier devient autonome (téléchargement, pièce jointe). Chemin
     * confiné au dossier des médias générés (anti-traversée). `$light` : en
     * JPEG allégé (ImageBytes::jpeg), pour qu'une page illustrée tienne dans
     * la limite d'une pièce jointe d'email.
     *
     * Tolère un préfixe d'origine (URL absolutisée côté client) et une query
     * (URL signée) : le contenu posté pour l'export peut arriver sous ces
     * trois formes. Seul le chemin canonique est résolu sur disque.
     */
    public static function inlineLocalImages(string $html, bool $light = false): string
    {
        $base = realpath(ROOT . '/public/assets/img/generated');
        if ($base === false) {
            return $html;
        }
        return preg_replace_callback(
            '#\bsrc\s*=\s*"(?:https?://[^/"]+)?(/assets/img/generated/[A-Za-z0-9._-]+)(?:\?[^"]*)?"#i',
            static function (array $m) use ($base, $light): string {
                $real = realpath(ROOT . '/public' . $m[1]);
                if ($real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($real)) {
                    return $m[0];
                }
                $bin = @file_get_contents($real);
                if (!is_string($bin) || $bin === '') {
                    return $m[0];
                }
                $mime = ImageBytes::mime($bin);
                if ($light) {
                    $jpeg = ImageBytes::jpeg($bin);
                    if ($jpeg !== null && strlen($jpeg) < strlen($bin)) {
                        [$bin, $mime] = [$jpeg, 'image/jpeg'];
                    }
                }
                return 'src="data:' . $mime . ';base64,' . base64_encode($bin) . '"';
            },
            $html
        ) ?? $html;
    }
}
