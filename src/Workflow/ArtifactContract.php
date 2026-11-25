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
 *              le chat (même convention : un bloc de code NOMMÉ). Un
 *              document Word, PDF, PowerPoint, Excel ou Markdown est rédigé
 *              (Markdown, tableau) ; tout autre format (.py, .json, .csv…,
 *              brique « Fichier » du menu Créer) est un FICHIER BRUT : son
 *              contenu exact, recopié tel quel (isRawFile)
 *   file     → { name, type, path, text }           (fichier reçu au départ)
 *   image, page, map → fabriquées par un outil de création (WorkflowCreations) :
 *              une brique Création, ou une étape — dont la réponse est alors
 *              la DEMANDE rédigée que l'outil reçoit (`instruction()` le dit au
 *              modèle, `extract()` la lit : { text }), pas la pièce elle-même
 *
 * `render()` donne le texte d'une pièce tel qu'une étape suivante le reçoit,
 * borné : une pièce ne peut pas manger à elle seule la fenêtre du modèle.
 */
final class ArtifactContract
{
    /** Taille d'une pièce injectée dans le prompt d'une étape (caractères). */
    public const PROMPT_CHARS = 40000;

    /** Demande rédigée par une étape pour un outil de création : celle d'une image, celle d'une page ou d'une carte (caractères). */
    public const BRIEF_IMAGE_CHARS = 4000;
    public const BRIEF_CHARS = 20000;

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
            // Un fichier : le nom court du catalogue (Python, JSON…), sinon l'extension.
            default => $format !== '' ? FileFormats::get($format)['short'] : '',
        };
    }

    /**
     * Formats d'une pièce Document qui se RÉDIGENT (Markdown, tableau) et se
     * convertissent ; les autres sont des fichiers bruts, écrits tels quels.
     */
    private const REDACTED_FORMATS = ['docx', 'pdf', 'rtf', 'pptx', 'md', 'xlsx'];

    /** Un fichier brut (script, données, page…) plutôt qu'un document rédigé ? */
    public static function isRawFile(string $format): bool
    {
        return $format !== '' && !in_array($format, self::REDACTED_FORMATS, true);
    }

    /** Langage du bloc de code d'un format (celui que lit le chat : python, json…). */
    public static function languageFor(string $format): string
    {
        if (isset(self::DOC_LANGUAGE[$format])) {
            return self::DOC_LANGUAGE[$format];
        }
        $named = ['py' => 'python', 'js' => 'javascript', 'ts' => 'typescript', 'sh' => 'bash', 'ps1' => 'powershell',
            'tex' => 'latex', 'yml' => 'yaml', 'bat' => 'batch', 'vcf' => 'vcard'];
        if (isset($named[$format])) {
            return $named[$format];
        }
        return FileFormats::extensionForLanguage($format) === $format ? $format : 'text';
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
        if (WorkflowSpec::isCreationForm((string)$piece['form'])) {
            return self::briefInstruction($piece);
        }
        switch ($piece['form']) {
            case 'list':
                return $head . "Forme attendue : une LISTE — une puce par élément (« - … »), un élément par ligne, rien d'autre (ni titre, ni conclusion).";
            case 'table':
                return $head . "Forme attendue : un TABLEAU Markdown, avec une ligne d'en-tête, rien d'autre autour.";
            case 'fiche':
                $spec = [];
                $ficheFields = array_values((array)($piece['fields'] ?? []));
                foreach (self::ficheJsonKeys($piece) as $i => $name) {
                    $f = $ficheFields[$i];
                    $spec[] = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': ' . self::typeHint((string)$f['type'])
                        . ($name !== (string)$f['label'] ? '  // ' . $f['label'] : '');
                }
                return $head . "Forme attendue : une FICHE. Écris d'abord, si utile, deux ou trois phrases de synthèse ; puis TERMINE par un bloc ```json contenant EXACTEMENT ces clés :\n"
                    . "{\n  " . implode(",\n  ", $spec) . "\n}\n"
                    . "Une valeur que les pièces ne donnent pas vaut null — ne l'invente jamais.";
            case 'document':
                $format = (string)($piece['format'] ?? 'docx');
                $file   = self::filename($piece);
                if (self::isRawFile($format)) {
                    $lang = self::languageFor($format);
                    $label = self::formatLabel($format);
                    return $head . "Forme attendue : un FICHIER {$label} (.{$format}). Écris son contenu EXACT et COMPLET, prêt à l'emploi tel quel "
                        . "(jamais d'extrait, de « … » ni de partie à compléter), dans UN bloc de code nommé :\n````{$lang} fichier=\"{$file}\"\n…\n````\n"
                        . "Avant le bloc, une phrase au plus. Rien après. Tout ce qui doit figurer dans le fichier (commentaires, explications) est DANS le bloc.";
                }
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

    /**
     * Une étape qui remet une création : elle rédige la DEMANDE, l'outil de
     * création la fabrique juste après son tour (WorkflowEngine).
     */
    private static function briefInstruction(array $piece): string
    {
        $name = (string)$piece['name'];
        $head = "La pièce « {$name} » du dossier sera fabriquée par un outil de création juste après ton tour, d'après ta réponse : "
            . "ta réponse est la DEMANDE que cet outil reçoit, rien d'autre. Ne fabrique pas la pièce toi-même, n'appelle aucun outil pour cela, "
            . "ne dis pas que tu ne sais pas le faire. ";
        return $head . match ((string)$piece['form']) {
            'image' => "Forme attendue : la DESCRIPTION VISUELLE précise de l'image — sujet, composition et cadrage, style, couleurs, ambiance, "
                . "et le texte à y écrire mot pour mot s'il y en a un (court) — en un ou deux paragraphes, sans préambule ni question. "
                . "Appuie-toi sur les pièces du dossier et tes sources : ce qu'elles disent compte, pas ce que tu imagines.",
            'page' => "Forme attendue : TOUT LE CONTENU de la page web, prêt à être mis en page — titre, sections, textes, chiffres, "
                . "appels à l'action — en Markdown structuré (titres « # » et « ## », paragraphes, listes). Pas de HTML ni de code : "
                . "l'outil fait la mise en page. N'invente aucune donnée que les pièces ou tes sources ne donnent pas.",
            default => "Forme attendue : l'ITINÉRAIRE à tracer — une liste numérotée des étapes dans l'ordre, chacune avec un lieu précis "
                . "(nom et ville, ou adresse), puis le mode de déplacement et les conseils utiles. Rien d'autre.",
        };
    }

    /**
     * Noms des clés du bloc json demandé pour une Fiche, dans l'ordre des
     * champs : leurs LIBELLÉS (« Priorité »), lisibles dans la conversation où
     * la réponse s'affiche. Les clés techniques des champs ajoutés dans
     * l'éditeur sont tirées au hasard (« fh5v4d ») : demandées telles quelles,
     * elles s'affichaient en clair sous la réponse de chaque étape (recette du
     * 2026-11-09). La lecture retrouve un champ par son libellé (byLabel).
     * Repli sur les clés si un libellé est vide, si deux libellés se
     * confondent, ou si un libellé vaut la clé d'un AUTRE champ — la lecture
     * essaie la clé d'abord, elle attribuerait la valeur au mauvais champ.
     *
     * @return list<string>
     */
    public static function ficheJsonKeys(array $piece): array
    {
        $fields = array_values((array)($piece['fields'] ?? []));
        $keys = array_map(static fn($f) => (string)($f['key'] ?? ''), $fields);
        $labels = [];
        $seen = [];
        foreach ($fields as $i => $f) {
            $label = trim((string)($f['label'] ?? ''));
            $slug = WorkflowSpec::slug($label);
            $other = array_diff_key($keys, [$i => true]);
            if ($label === '' || $slug === '' || isset($seen[$slug]) || in_array($label, $other, true)) {
                return $keys;
            }
            $seen[$slug] = true;
            $labels[] = $label;
        }
        return $labels;
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
        // Une erreur d'adaptateur (« [Erreur OpenAI (503) : …] ») n'est jamais une pièce :
        // un fichier brut, sans longueur minimale, la prendrait sinon pour son contenu.
        if ($reply === '' || str_starts_with($reply, '⚠') || (str_starts_with($reply, '[') && LlmErrorPresenter::parse($reply) !== null)) {
            return self::fail("la réponse est vide ou en erreur");
        }
        if (WorkflowSpec::isCreationForm((string)$piece['form'])) {
            // La demande d'une création (étape) : un texte, sans image collée ni enveloppe.
            $brief = trim((string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', self::stripOuterFence($reply)));
            return $brief === ''
                ? self::fail("la réponse ne contient pas la demande à transmettre à l'outil de création")
                : self::ok(['text' => mb_substr($brief, 0, $piece['form'] === 'image' ? self::BRIEF_IMAGE_CHARS : self::BRIEF_CHARS)]);
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
                // Demandée par libellés : le libellé d'abord (une clé technique
                // écrite en minuscules ne doit jamais capter la valeur d'un autre
                // champ), la clé ensuite (réponses d'avant, repli sur les clés).
                $byLabels = self::ficheJsonKeys($piece) !== array_map(static fn($f) => (string)($f['key'] ?? ''), array_values((array)($piece['fields'] ?? [])));
                foreach ((array)($piece['fields'] ?? []) as $f) {
                    $raw = $byLabels
                        ? (self::byLabel($json, (string)$f['label']) ?? $json[$f['key']] ?? null)
                        : ($json[$f['key']] ?? self::byLabel($json, (string)$f['label']));
                    $value = WorkflowValues::coerce($raw, (string)$f['type']);
                    if ($value !== null && $value !== '' && $value !== []) {
                        $found++;
                    }
                    $fields[$f['key']] = $value;
                }
                if ($found === 0 && $piece['fields'] !== []) {
                    return self::fail("le bloc json ne contient aucune des clés attendues (" . implode(', ', self::ficheJsonKeys($piece)) . ")");
                }
                $summary = trim((string)preg_replace('/```json.*$/s', '', $reply));
                return self::ok(['fields' => $fields, 'summary' => mb_substr($summary, 0, 2000)]);

            case 'document':
                $block = self::namedBlock($reply);
                $format = (string)($piece['format'] ?? 'docx');
                $raw = self::isRawFile($format);
                if ($block === null && $raw) {
                    // Un fichier brut sans nom de bloc : son seul bloc de code, sinon la réponse entière.
                    $block = self::singleBlock($reply) ?? ['language' => '', 'source' => self::stripOuterFence($reply)];
                }
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
                    'language' => $block['language'] !== '' ? $block['language'] : ($raw ? self::languageFor($format) : (self::DOC_LANGUAGE[$format] ?? 'markdown')),
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
            'document' => self::isRawFile((string)($content['format'] ?? 'docx'))
                ? self::formatLabel((string)$content['format']) . ' · ' . I18n::plural(self::lineCount((string)($content['source'] ?? '')), '{n} ligne', '{n} lignes')
                : self::formatLabel((string)($content['format'] ?? 'docx')) . ' · ' . I18n::plural(self::wordCount((string)($content['source'] ?? '')), '{n} mot', '{n} mots'),
            'file'  => (string)($content['name'] ?? ''),
            default => I18n::plural(self::wordCount((string)($content['text'] ?? '')), '{n} mot', '{n} mots'),
        };
    }

    private static function lineCount(string $s): int
    {
        $s = trim($s, "\n");
        return $s === '' ? 0 : substr_count($s, "\n") + 1;
    }

    private static function wordCount(string $s): int
    {
        return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Fiche corrigée après coup (« Ajuster » sur la page de l'exécution, ou
     * la discussion de la conversation, WorkflowChat) : les champs donnés
     * remplacent les anciens. Le RÉSUMÉ a été écrit avec les anciennes
     * valeurs : réécrit (`$summary`), il remplace l'ancien ; sinon il est
     * retiré dès qu'un champ change — « Statut : en attente de saisie »
     * laissé au-dessus d'un montant saisi faisait encore écrire « en
     * attente » à la suite du workflow (constaté chez un client, 2026-11-24).
     *
     * @param array<string, mixed> $fields
     */
    public static function withCorrectedFields(array $piece, array $content, array $fields, ?string $summary = null): array
    {
        $before = (array)($content['fields'] ?? []);
        $after = $before;
        foreach ((array)($piece['fields'] ?? []) as $f) {
            if (array_key_exists((string)$f['key'], $fields)) {
                $after[(string)$f['key']] = $fields[(string)$f['key']];
            }
        }
        $content['fields'] = $after;
        if ($summary !== null) {
            $content['summary'] = mb_substr(trim($summary), 0, 2000);
        } elseif ($after !== $before) {
            unset($content['summary']);
        }
        return $content;
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

    /**
     * Le bloc de code d'une réponse qui n'en porte qu'UN (non nommé), ou null :
     * « Voici le script : ```python … ``` » — la phrase autour n'en fait pas partie.
     *
     * @return array{language: string, source: string}|null
     */
    public static function singleBlock(string $reply): ?array
    {
        if (preg_match_all('/^(`{3,}|~{3,})[ \t]*([A-Za-z0-9_+.-]*)[^\n]*\n(.*?)\n\1[ \t]*$/ms', $reply, $m, PREG_SET_ORDER) !== 1) {
            return null;
        }
        return ['language' => strtolower($m[0][2]), 'source' => trim($m[0][3], "\n")];
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
