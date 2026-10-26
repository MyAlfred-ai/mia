<?php

declare(strict_types=1);

/**
 * Contrat d'une pièce du dossier — pur.
 *
 * Une étape est un tour de conversation ordinaire : le modèle répond par du
 * texte. Ce contrat dit au modèle QUELLE forme donner à sa réponse
 * (`instruction()`), puis lit la réponse pour en tirer la pièce
 * (`extract()`) — et dit pourquoi quand elle ne se lit pas, pour qu'une
 * seconde tentative corrige précisément ce défaut.
 *
 * Contenu rangé selon la forme :
 *   text     → { text }
 *   list     → { items: list<string> }
 *   table    → { rows: list<list<string>> }        (première ligne = en-tête)
 *   fiche    → { fields: clé → valeur typée }       (cf. WorkflowValues::coerce)
 *   document → { format, filename, language, source } — fabriqué au
 *              téléchargement par FileFactory, comme un fichier livré dans
 *              le chat (même convention : un bloc de code NOMMÉ)
 *   file     → { name, type, path, text }           (fichier reçu au départ)
 *   image, page, map → remises par une brique Création (WorkflowCreations)
 *
 * `render()` donne le texte d'une pièce tel qu'une étape suivante le reçoit,
 * borné : une pièce ne peut pas manger à elle seule la fenêtre du modèle.
 */
final class ArtifactContract
{
    /** Taille d'une pièce injectée dans le prompt d'une étape (caractères). */
    public const PROMPT_CHARS = 40000;

    /** Langage de bloc attendu pour un format de document. */
    public const DOC_LANGUAGE = ['docx' => 'markdown', 'pdf' => 'markdown', 'pptx' => 'markdown', 'md' => 'markdown', 'xlsx' => 'csv'];

    public static function formLabel(string $form): string
    {
        return match ($form) {
            'text'     => t('Texte'),
            'list'     => t('Liste'),
            'table'    => t('Tableau'),
            'fiche'    => t('Fiche'),
            'document' => t('Document'),
            'file'     => t('Fichier'),
            'image'    => t('Image'),
            'page'     => t('Page web'),
            'map'      => t('Carte'),
            default    => $form,
        };
    }

    public static function formatLabel(string $format): string
    {
        return match ($format) {
            'docx' => 'Word',
            'pdf'  => 'PDF',
            'pptx' => 'PowerPoint',
            'xlsx' => 'Excel',
            'md'   => 'Markdown',
            default => strtoupper($format),
        };
    }

    /** Nom de fichier d'une pièce Document. */
    public static function filename(array $piece): string
    {
        $base = trim((string)preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string)$piece['name']));
        return ($base !== '' ? $base : 'document') . '.' . (string)($piece['format'] ?? 'docx');
    }

    /**
     * Consigne de forme ajoutée au prompt de l'étape (méthode, en français :
     * elle ne cite rien de l'écran).
     */
    public static function instruction(array $piece): string
    {
        $name = (string)$piece['name'];
        $head = "Ta réponse devient la pièce « {$name} » du dossier. ";
        switch ($piece['form']) {
            case 'list':
                return $head . "Forme attendue : une LISTE — une puce par élément (« - … »), un élément par ligne, rien d'autre (ni titre, ni conclusion).";
            case 'table':
                return $head . "Forme attendue : un TABLEAU Markdown, avec une ligne d'en-tête, rien d'autre autour.";
            case 'fiche':
                $spec = [];
                foreach ((array)($piece['fields'] ?? []) as $f) {
                    $spec[] = '"' . $f['key'] . '": ' . self::typeHint((string)$f['type']) . '  // ' . $f['label'];
                }
                return $head . "Forme attendue : une FICHE. Écris d'abord, si utile, deux ou trois phrases de synthèse ; puis TERMINE par un bloc ```json contenant EXACTEMENT ces clés :\n"
                    . "{\n  " . implode(",\n  ", $spec) . "\n}\n"
                    . "Une valeur que les pièces ne donnent pas vaut null — ne l'invente jamais.";
            case 'document':
                $format = (string)($piece['format'] ?? 'docx');
                $file   = self::filename($piece);
                if ($format === 'xlsx') {
                    return $head . "Forme attendue : un CLASSEUR Excel. Écris ses données dans UN bloc de code nommé, ligne d'en-tête comprise :\n```csv fichier=\"{$file}\"\n…\n```\n"
                        . "Avant le bloc, une phrase au plus. Rien après.";
                }
                $shape = $format === 'pptx'
                    ? "en présentation : « # Titre » pour la couverture, puis « ## Titre de slide » et des puces courtes pour chaque slide"
                    : "en Markdown structuré : titres « # » et « ## », paragraphes, listes, tableaux";
                return $head . "Forme attendue : un DOCUMENT " . self::formatLabel($format) . ". Écris-le EN ENTIER, {$shape}, dans UN bloc de code nommé :\n````markdown fichier=\"{$file}\"\n…\n````\n"
                    . "Avant le bloc, une phrase au plus. Rien après. Le document est fabriqué au modèle de l'entreprise : n'y ajoute ni logo ni en-tête.";
            default:
                return $head . "Forme attendue : un TEXTE rédigé, prêt à être lu tel quel — sans préambule (« Voici… »), sans question à l'utilisateur.";
        }
    }

    private static function typeHint(string $type): string
    {
        return match ($type) {
            'number' => 'nombre',
            'date'   => '"AAAA-MM-JJ"',
            'yesno'  => 'true | false',
            'email'  => '"adresse email"',
            'list'   => '["…", "…"]',
            default  => '"texte"',
        };
    }

    /**
     * Lit la réponse d'une étape.
     *
     * @return array{ok: bool, content: array, error: string}
     */
    public static function extract(array $piece, string $reply): array
    {
        $reply = str_replace(["\r\n", "\r"], "\n", trim($reply));
        if ($reply === '' || str_starts_with($reply, '⚠')) {
            return self::fail("la réponse est vide ou en erreur");
        }
        switch ($piece['form']) {
            case 'list':
                $items = self::listItems($reply);
                return $items === []
                    ? self::fail("aucune puce « - … » n'a été trouvée")
                    : self::ok(['items' => $items]);

            case 'table':
                $rows = self::tableRows($reply);
                return $rows === null
                    ? self::fail("aucun tableau Markdown (ligne d'en-tête puis ligne de tirets) n'a été trouvé")
                    : self::ok(['rows' => $rows]);

            case 'fiche':
                $json = self::lastJsonObject($reply);
                if ($json === null) {
                    return self::fail("aucun bloc ```json n'a été trouvé à la fin de la réponse");
                }
                $fields = [];
                $found = 0;
                foreach ((array)($piece['fields'] ?? []) as $f) {
                    $raw = $json[$f['key']] ?? self::byLabel($json, (string)$f['label']);
                    $value = WorkflowValues::coerce($raw, (string)$f['type']);
                    if ($value !== null && $value !== '' && $value !== []) {
                        $found++;
                    }
                    $fields[$f['key']] = $value;
                }
                if ($found === 0 && $piece['fields'] !== []) {
                    return self::fail("le bloc json ne contient aucune des clés attendues (" . implode(', ', array_column($piece['fields'], 'key')) . ")");
                }
                $summary = trim((string)preg_replace('/```json.*$/s', '', $reply));
                return self::ok(['fields' => $fields, 'summary' => mb_substr($summary, 0, 2000)]);

            case 'document':
                $block = self::namedBlock($reply);
                $format = (string)($piece['format'] ?? 'docx');
                if ($block === null) {
                    // Tolérance : une réponse sans bloc mais substantielle est le document.
                    if (mb_strlen($reply) < 200) {
                        return self::fail("le document n'est pas dans un bloc de code nommé fichier=\"…\"");
                    }
                    $block = ['language' => self::DOC_LANGUAGE[$format] ?? 'markdown', 'source' => self::stripOuterFence($reply)];
                }
                if (trim($block['source']) === '') {
                    return self::fail("le bloc du document est vide");
                }
                return self::ok([
                    'format'   => $format,
                    'filename' => self::filename($piece),
                    'language' => $block['language'] !== '' ? $block['language'] : (self::DOC_LANGUAGE[$format] ?? 'markdown'),
                    'source'   => $block['source'],
                ]);

            default:
                return self::ok(['text' => self::stripOuterFence($reply)]);
        }
    }

    /** @return array{ok: bool, content: array, error: string} */
    private static function ok(array $content): array
    {
        return ['ok' => true, 'content' => $content, 'error' => ''];
    }

    /** @return array{ok: bool, content: array, error: string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'content' => [], 'error' => $why];
    }

    /**
     * Texte d'une pièce pour le prompt d'une étape ou l'écran, borné à
     * `$max` caractères (la fin est remplacée par une note).
     */
    public static function render(array $piece, array $content, int $max = self::PROMPT_CHARS): string
    {
        if (isset(WorkflowSpec::CREATE_KINDS[$piece['form']])) {
            return WorkflowCreations::render($piece, $content, $max);
        }
        $text = match ($piece['form']) {
            'list'  => implode("\n", array_map(static fn($i) => '- ' . $i, (array)($content['items'] ?? []))),
            'table' => self::tableMarkdown((array)($content['rows'] ?? [])),
            'fiche' => self::ficheText($piece, $content),
            'document' => (string)($content['source'] ?? ''),
            'file'  => (string)($content['text'] ?? ''),
            default => (string)($content['text'] ?? ''),
        };
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max) . "\n\n[… pièce tronquée : " . mb_strlen($text) . " caractères au total]";
        }
        return $text;
    }

    /** Résumé d'une ligne (fil d'exécution, dossier). */
    public static function summary(array $piece, array $content): string
    {
        if (isset(WorkflowSpec::CREATE_KINDS[$piece['form']])) {
            return WorkflowCreations::summary($piece, $content);
        }
        return match ($piece['form']) {
            'list'  => I18n::plural(count((array)($content['items'] ?? [])), '{n} élément', '{n} éléments'),
            'table' => I18n::plural(max(0, count((array)($content['rows'] ?? [])) - 1), '{n} ligne', '{n} lignes'),
            'fiche' => I18n::plural(count(array_filter((array)($content['fields'] ?? []), static fn($v) => $v !== null && $v !== '' && $v !== [])), '{n} champ renseigné', '{n} champs renseignés'),
            'document' => self::formatLabel((string)($content['format'] ?? 'docx')) . ' · ' . I18n::plural(self::wordCount((string)($content['source'] ?? '')), '{n} mot', '{n} mots'),
            'file'  => (string)($content['name'] ?? ''),
            default => I18n::plural(self::wordCount((string)($content['text'] ?? '')), '{n} mot', '{n} mots'),
        };
    }

    private static function wordCount(string $s): int
    {
        return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function ficheText(array $piece, array $content): string
    {
        $lines = [];
        foreach ((array)($piece['fields'] ?? []) as $f) {
            $v = $content['fields'][$f['key']] ?? null;
            $lines[] = '- ' . $f['label'] . ' : ' . WorkflowValues::display($v, (string)$f['type']);
        }
        $summary = trim((string)($content['summary'] ?? ''));
        return ($summary !== '' ? $summary . "\n\n" : '') . implode("\n", $lines);
    }

    public static function tableMarkdown(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $esc = static fn($c): string => str_replace(['|', "\n"], ['\\|', ' '], (string)$c);
        $out = '| ' . implode(' | ', array_map($esc, $rows[0])) . " |\n";
        $out .= '|' . str_repeat(' --- |', count($rows[0])) . "\n";
        foreach (array_slice($rows, 1) as $r) {
            $out .= '| ' . implode(' | ', array_map($esc, $r)) . " |\n";
        }
        return rtrim($out);
    }

    // ------------------------------------------------------------------
    // Lecture de la réponse
    // ------------------------------------------------------------------

    /** @return list<string> */
    public static function listItems(string $reply): array
    {
        $items = [];
        foreach (explode("\n", $reply) as $line) {
            if (preg_match('/^\s*(?:[-*•+]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $item = trim(str_replace(['**', '__'], '', $m[1]));
                if ($item !== '') {
                    $items[] = mb_substr($item, 0, 1000);
                }
            }
        }
        return array_slice($items, 0, 200);
    }

    /** Premier tableau Markdown de la réponse, ou null. @return list<list<string>>|null */
    public static function tableRows(string $reply): ?array
    {
        $lines = explode("\n", $reply);
        $block = [];
        $best = null;
        foreach ($lines as $line) {
            if (str_contains($line, '|') && trim($line) !== '') {
                $block[] = $line;
                continue;
            }
            if (count($block) >= 2) {
                $best = $block;
                break;
            }
            $block = [];
        }
        if ($best === null && count($block) >= 2) {
            $best = $block;
        }
        if ($best === null) {
            return null;
        }
        $rows = TabularData::parseMarkdownTable(implode("\n", $best));
        if ($rows === null || $rows === []) {
            return null;
        }
        return array_slice(array_map(static fn(array $r): array => array_map(static fn($c) => mb_substr((string)$c, 0, 500), $r), $rows), 0, 500);
    }

    /** Dernier objet JSON de la réponse (bloc ```json de préférence). */
    public static function lastJsonObject(string $reply): ?array
    {
        if (preg_match_all('/```(?:json)?\s*\n(\{.*?\})\s*\n```/s', $reply, $m) && $m[1] !== []) {
            $decoded = json_decode((string)end($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $end = strrpos($reply, '}');
        if ($end === false) {
            return null;
        }
        for ($start = strrpos(substr($reply, 0, $end), '{'); $start !== false; $start = $start > 0 ? strrpos(substr($reply, 0, $start), '{') : false) {
            $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function byLabel(array $json, string $label): mixed
    {
        $fold = static fn(string $s): string => WorkflowSpec::slug($s);
        $target = $fold($label);
        foreach ($json as $k => $v) {
            if ($fold((string)$k) === $target) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Bloc de code NOMMÉ (``` lang fichier="x.docx") : le délimiteur fermant
     * est le DERNIER de même longueur ou plus — un document peut contenir
     * ses propres blocs de code.
     *
     * @return array{language: string, source: string}|null
     */
    public static function namedBlock(string $reply): ?array
    {
        $lines = explode("\n", $reply);
        $open = null;
        $fence = '';
        $lang = '';
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_+-]*)[^\n]*\b(?:fichier|file|filename)\s*=/u', $line, $m)) {
                $open = $i;
                $fence = $m[1];
                $lang = strtolower($m[2]);
                break;
            }
        }
        if ($open === null) {
            return null;
        }
        $close = null;
        for ($j = count($lines) - 1; $j > $open; $j--) {
            $t = trim($lines[$j]);
            if ($t !== '' && $t[0] === $fence[0] && strlen($t) >= strlen($fence) && trim($t, $fence[0]) === '') {
                $close = $j;
                break;
            }
        }
        $body = array_slice($lines, $open + 1, ($close ?? count($lines)) - $open - 1);
        return ['language' => $lang, 'source' => trim(implode("\n", $body), "\n")];
    }

    /** Retire un bloc de code qui envelopperait TOUTE la réponse. */
    public static function stripOuterFence(string $reply): string
    {
        if (preg_match('/^(`{3,})[^\n]*\n(.*)\n\1\s*$/s', trim($reply), $m)) {
            return trim($m[2]);
        }
        return trim($reply);
    }
}
