<?php

declare(strict_types=1);

/**
 * Les pièces du dossier insérées TELLES QUELLES dans ce qu'une étape écrit
 * (corps d'un email, message Slack ou Teams, document, description…).
 *
 * LE PROBLÈME RÉSOLU (2026-10-26). « Envoie un mail qui contient
 * @[Message Slack hebdomadaire] et l'image @[Image] » partait avec, pour tout
 * contenu, le NOM de la pièce en titre (« # Message Slack hebdomadaire ») et
 * sans image. La consigne affichée au modèle nomme les pièces (« Message
 * Slack hebdomadaire ») ; leur contenu lui est donné à part, comme données ;
 * c'était à lui de le recopier — il ne l'a pas fait, et rien ne lui permettait
 * de mettre une image dans un email (il n'a ni pièce jointe ni balise <img>).
 *
 * DÉSORMAIS le modèle écrit un REPÈRE — `@{clé}`, ou `@{clé.champ}` pour un
 * champ de Fiche — là où la pièce doit apparaître (WorkflowPrompt le lui
 * apprend, pièce par pièce) ; au DÉPÔT de l'action, le serveur le remplace
 * par le contenu exact de la pièce (fill). Une image dans le corps d'un email
 * devient une image INTÉGRÉE au message : un repère MailHtml
 * (`[[mia-image:<cid>]]`) et une référence interne `_wf_attach` portant ce
 * `cid` (la pièce jointe `inline` est fabriquée à l'envoi, WorkflowDeliveries
 * ::attachmentsFor). Une image que la consigne cite mais que le modèle n'a
 * pas placée est ajoutée à la fin du corps : elle était demandée.
 *
 * Rien de ce que le modèle écrit ne désigne un fichier : seuls les repères
 * des pièces que l'étape lit (`map`, calculé par le moteur dans la charge du
 * job) sont remplacés, et les arguments internes viennent du serveur.
 */
final class WorkflowInserts
{
    /** Arguments qui reçoivent le CONTENU d'une pièce. */
    public const BODY_ARGS = ['corps', 'texte', 'contenu', 'description', 'notes'];
    /** Arguments de contenu propres à un outil (le sujet d'une présentation Gamma). */
    private const TOOL_BODY_ARGS = ['generer_presentation_gamma' => ['sujet']];
    /** Arguments d'une ligne : un repère y devient le nom de la pièce (ou la valeur du champ). */
    public const LINE_ARGS = ['objet', 'titre', 'lieu'];
    /** Cellules d'un tableur : chaque cellule est une ligne. */
    private const CELL_ARGS = ['creer_google_sheet' => 'valeurs', 'modifier_google_sheet' => 'valeurs'];
    /** Arguments affichés en texte brut par leur service (note de tâche, description d'évènement). */
    private const PLAIN_ARGS = [
        'creer_tache' => ['notes'], 'modifier_tache' => ['notes'],
        'creer_tache_todo' => ['notes'], 'modifier_tache_todo' => ['notes'],
        'creer_evenement_agenda' => ['description'], 'creer_evenement_agenda_outlook' => ['description'],
    ];
    /** Forme du texte qui reçoit une pièce : Markdown, HTML (email), mrkdwn (Slack), texte brut. */
    public const FORMAT_MARKDOWN = '';
    public const FORMAT_HTML = 'html';
    public const FORMAT_MRKDWN = 'mrkdwn';
    public const FORMAT_PLAIN = 'plain';
    /** Caractères d'une pièce insérée au plus. */
    public const INSERT_MAX = 60000;
    /** Caractères insérés au plus dans UN texte, toutes pièces et tous niveaux confondus. */
    public const TOTAL_MAX = 150000;
    /** Niveaux de pièces insérées dans des pièces insérées, au plus. */
    public const MAX_DEPTH = 4;

    private const TOKEN = '/@\{([a-z0-9_]{1,40})(?:\.([a-z0-9_]{1,40}))?\}/';
    /** Un repère seul sur une ligne de liste : retrait, puce, repère. */
    private const BULLET_TOKEN = '/^([ \t]*)([-*+•]|\d{1,3}[.)])[ \t]+(@\{([a-z0-9_]{1,40})(?:\.([a-z0-9_]{1,40}))?\})[ \t]*$/mu';
    /** Une ligne d'une liste Markdown (puce ou numéro). */
    private const LIST_LINE = '/^[ \t]*(?:[-*+•]|\d{1,3}[.)])[ \t]+/u';
    private const BY_NAME = '/@\[([^\]\n]{1,120})\]/u';

    /**
     * Ce qu'une étape qui écrit peut insérer (charge du job) : chaque pièce
     * qu'elle lit et qui existe → sa clé réelle au dossier (« image#2 » dans
     * une boucle) ; et les images que sa consigne cite. Vide pour une brique
     * qui n'écrit pas.
     *
     * @param array<string, array> $artifacts pièces vues par l'étape (clé citable → pièce, `key` = clé réelle)
     * @return array{map: array<string, string>, images: list<string>}
     */
    public static function forPass(array $def, array $node, array $artifacts): array
    {
        if (!WorkflowSpec::writes($node)) {
            return ['map' => [], 'images' => []];
        }
        $pieces = WorkflowSpec::pieces($def);
        $map = [];
        foreach (WorkflowSpec::readsOf($def, $node) as $k) {
            if (isset($pieces[$k], $artifacts[$k])) {
                $map[$k] = (string)($artifacts[$k]['key'] ?? $k);
            }
        }
        $images = [];
        foreach (WorkflowSpec::mentions((string)($node['instruction'] ?? '')) as $k) {
            if (isset($map[$k]) && self::isImage($pieces[$k], (array)$artifacts[$k]['content'])) {
                $images[] = $k;
            }
        }
        return ['map' => $map, 'images' => $images];
    }

    /**
     * Ce que le modèle doit savoir pour insérer (consigne de méthode) : la
     * règle, et le repère de chaque pièce qu'il lit. '' sans pièce.
     *
     * @param list<string> $keys pièces lues par l'étape, présentes au dossier
     */
    public static function promptNote(array $pieces, array $keys, array $artifacts, string $instruction): string
    {
        $lines = [];
        foreach ($keys as $k) {
            $p = $pieces[$k] ?? null;
            if ($p === null) {
                continue;
            }
            $line = '« ' . $p['name'] . ' » → @{' . $k . '}';
            if (self::isImage($p, (array)($artifacts[$k]['content'] ?? []))) {
                $line .= ' (image : dans le corps d\'un email, elle apparaît dans le message)';
            } elseif ($p['form'] === 'fiche') {
                $fields = array_slice((array)($p['fields'] ?? []), 0, 8);
                if ($fields !== []) {
                    $line .= ' ; un seul champ : ' . implode(', ', array_map(
                        static fn(array $f): string => '@{' . $k . '.' . $f['key'] . '} (' . $f['label'] . ')',
                        $fields
                    ));
                }
            }
            $lines[] = '- ' . $line;
        }
        if ($lines === []) {
            return '';
        }
        $out = "\nINSÉRER UNE PIÈCE TELLE QUELLE — quand la consigne demande de mettre une pièce du dossier dans ce que tu écris "
            . "(le corps d'un email, un message, un document), n'en recopie ni le nom ni un résumé : écris son REPÈRE, exactement, "
            . "à l'endroit où elle doit apparaître. Il est remplacé par le contenu exact de la pièce, dans ce que l'action dépose comme dans la pièce que tu remets. "
            . "N'écris jamais toi-même de balise <img> ni de lien vers une image : le repère d'une image suffit.\n"
            . "Repères :\n" . implode("\n", $lines) . "\n";
        $cited = array_values(array_filter(WorkflowSpec::mentions($instruction), static fn(string $k): bool => in_array($k, $keys, true)));
        if ($cited !== []) {
            $out .= "La consigne cite " . implode(', ', array_map(static fn(string $k): string => '« ' . $pieces[$k]['name'] . ' » (@{' . $k . '})', $cited))
                . " : quand elle demande de les mettre dans ce que tu écris, place leurs repères ; ne t'en sers autrement "
                . "(résumé, information tirée) que si elle le demande.\n";
        }
        return $out;
    }

    /**
     * Remplace les repères des arguments d'une écriture par le contenu exact
     * des pièces — RÉCURSIVEMENT : une pièce qui contient elle-même des
     * repères (rédigée par une étape qui écrit) est remplie à son tour, selon
     * la règle de SON auteur (nestedResolver). Chaque argument reçoit le
     * contenu dans sa forme (formatFor) : HTML d'un email écrit en HTML,
     * mrkdwn de Slack, texte brut d'une note ou d'une description. Pure, hors
     * l'existence des fichiers d'images (`$imageExists`, par défaut
     * WorkflowCreations::imagePath).
     *
     * @param array $inserts forPass (charge du job)
     * @param array $def définition FIGÉE de l'exécution
     * @param array<string, array> $artifacts pièces du dossier par clé RÉELLE
     */
    public static function fill(string $tool, array $args, array $inserts, array $def, array $artifacts, int $runId, ?callable $imageExists = null): array
    {
        $pieces = WorkflowSpec::pieces($def);
        $map = is_array($inserts['map'] ?? null) ? $inserts['map'] : [];
        $map = array_filter($map, static fn($real, $k): bool => is_string($k) && is_string($real) && isset($pieces[$k], $artifacts[$real]), ARRAY_FILTER_USE_BOTH);
        if ($map === []) {
            return $args;
        }
        // Au premier niveau, les seules pièces que la brique lit (forPass) ;
        // dans une pièce insérée, celles que SON auteur lisait.
        $top = static fn(string $k): ?array => isset($map[$k]) ? ['key' => $map[$k], 'content' => (array)($artifacts[$map[$k]]['content'] ?? [])] : null;
        $nested = self::nestedResolver($def, $artifacts);

        $imageExists ??= static fn(array $piece, array $content): bool => WorkflowCreations::imagePath((string)$piece['form'], $content) !== null;
        $mail = MailDraftReview::isMailTool($tool);
        $cids = []; // clé citable → [cid, clé réelle]
        $cidOf = static function (string $k, string $real) use (&$cids, $runId): string {
            $cids[$k] ??= ['img' . (count($cids) + 1) . '.' . substr(sha1($runId . '|' . $real), 0, 10) . '@mia', $real];
            return $cids[$k][0];
        };
        $embed = static fn(string $k, array $p, array $content, string $real): ?string
            => $imageExists($p, $content) ? MailHtml::imageMarker($cidOf($k, $real)) : null;

        foreach (array_merge(self::bodyArgs($tool), self::LINE_ARGS) as $arg) {
            if (!is_string($args[$arg] ?? null) || !str_contains($args[$arg], '@')) {
                continue;
            }
            $line = in_array($arg, self::LINE_ARGS, true);
            $text = self::byName($args[$arg], $pieces, $map);
            $args[$arg] = self::expandText(
                $text, $pieces, $top,
                $mail && $arg === 'corps' ? $embed : null,
                $line, $nested,
                $line ? self::FORMAT_MARKDOWN : self::formatFor($tool, $arg, $text)
            );
        }

        // Un tableur : chaque cellule est une ligne (la valeur d'un champ, le nom d'une pièce).
        $cellArg = self::CELL_ARGS[$tool] ?? null;
        if ($cellArg !== null && isset($args[$cellArg]) && str_contains((string)json_encode($args[$cellArg], JSON_UNESCAPED_UNICODE), '@')) {
            try {
                $rows = UserDriveService::normalizeValues($args[$cellArg]);
                $args[$cellArg] = array_map(static fn(array $row): array => array_map(
                    static fn($cell) => is_string($cell) && str_contains($cell, '@')
                        ? self::expandText(self::byName($cell, $pieces, $map), $pieces, $top, null, true)
                        : $cell,
                    $row
                ), $rows);
            } catch (\RuntimeException) {
                // Valeurs illisibles : le service le dira à l'exécution, rien à remplir.
            }
        }

        if ($mail && is_string($args['corps'] ?? null)) {
            // Une image que la consigne demande mais que le modèle n'a pas placée : à la fin.
            foreach ((array)($inserts['images'] ?? []) as $k) {
                $hit = is_string($k) ? $top($k) : null;
                if ($hit !== null && !isset($cids[$k]) && self::isImage($pieces[$k], $hit['content']) && $imageExists($pieces[$k], $hit['content'])) {
                    $args['corps'] = rtrim($args['corps']) . "\n\n" . MailHtml::imageMarker($cidOf($k, $hit['key']));
                }
            }
            if ($cids !== []) {
                $refs = is_array($args[WorkflowDeliveries::ATTACH_KEY] ?? null) ? $args[WorkflowDeliveries::ATTACH_KEY] : [];
                foreach ($cids as $k => [$cid, $real]) {
                    $refs[] = ['run_id' => $runId, 'piece' => $real, 'cid' => $cid, 'name' => (string)$pieces[$k]['name']];
                }
                $args[WorkflowDeliveries::ATTACH_KEY] = $refs;
            }
        }
        return $args;
    }

    /** Arguments qui reçoivent le contenu d'une pièce pour cet outil. @return list<string> */
    public static function bodyArgs(string $tool): array
    {
        return array_merge(self::BODY_ARGS, self::TOOL_BODY_ARGS[$tool] ?? []);
    }

    /**
     * La forme du texte qui reçoit l'insertion. Le contenu d'une pièce est du
     * Markdown ; il y est converti UNE fois, au premier niveau : un email
     * écrit en HTML reçoit du HTML (du Markdown y perdrait ses paragraphes),
     * Slack du mrkdwn (le texte du modèle y est DÉJÀ converti par le
     * gestionnaire de l'outil : il ne repasse plus jamais par la conversion),
     * une note de tâche ou une description d'évènement du texte brut (ni
     * dièses ni astérisques à l'écran).
     */
    public static function formatFor(string $tool, string $arg, string $text): string
    {
        if ($arg === 'corps' && MailDraftReview::isMailTool($tool)) {
            return MailHtml::ressembleAHtml($text) ? self::FORMAT_HTML : self::FORMAT_MARKDOWN;
        }
        if ($tool === 'envoyer_message_slack' && $arg === 'texte') {
            return self::FORMAT_MRKDWN;
        }
        return in_array($arg, self::PLAIN_ARGS[$tool] ?? [], true) ? self::FORMAT_PLAIN : self::FORMAT_MARKDOWN;
    }

    /**
     * Les repères d'un texte remplacés par le contenu des pièces, et ceux du
     * contenu inséré à leur tour selon la règle de son auteur (`$nested`) —
     * MAX_DEPTH niveaux, TOTAL_MAX caractères en tout, jamais une pièce dans
     * elle-même (une pièce déjà en cours d'insertion n'est plus que son nom).
     * Un repère inconnu reste tel quel.
     *
     * @param callable(string): ?array $lookup clé citable → {key: clé réelle, content}, au premier niveau
     * @param ?callable(string, array, array, string): ?string $image ce qui remplace une image (null : son nom)
     * @param bool $line une ligne (objet, titre, cellule) : le nom d'une pièce, la valeur d'un champ
     * @param ?callable(string, string): ?callable $nested pour la pièce insérée (clé citable, clé réelle) :
     *        la recherche de SES repères, ou null (ils restent tels quels)
     * @param string $format forme du texte (FORMAT_*) : le contenu inséré y est converti
     * @param list<string> $seen pièces en cours d'insertion
     */
    public static function expandText(string $text, array $pieces, callable $lookup, ?callable $image = null, bool $line = false, ?callable $nested = null, string $format = self::FORMAT_MARKDOWN, array $seen = []): string
    {
        $room = self::TOTAL_MAX;
        return self::expandIn($text, $pieces, $lookup, $image, $line, $nested, $format, $seen, 0, $room);
    }

    /**
     * Le contenu rendu d'une pièce, ses propres repères remplis selon la
     * règle de son auteur (livraisons : la pièce citée dans un message ou
     * ajoutée à une publication).
     */
    public static function renderPiece(string $key, array $piece, array $content, array $pieces, ?callable $nested, string $real, int $max = self::INSERT_MAX): string
    {
        $rendered = trim(ArtifactContract::render($piece, $content, $max));
        $sub = $nested !== null ? $nested($key, $real) : null;
        if ($sub === null) {
            return $rendered;
        }
        $room = self::TOTAL_MAX;
        return self::expandIn($rendered, $pieces, $sub, null, false, $nested, self::FORMAT_MARKDOWN, [$key], 1, $room);
    }

    /**
     * QUI PEUT CITER QUOI — la règle de la récursion, SOURCE UNIQUE. Un repère
     * n'est rempli que dans ce qu'a écrit une ÉTAPE QUI ÉCRIT (elle seule a
     * appris les repères, WorkflowPrompt) et seulement vers les pièces que
     * cette étape lisait (readsOf), telles qu'elle les voyait à son tour de
     * boucle. Une pièce venue d'ailleurs — formulaire de départ, email ou
     * fichier reçu, élément de boucle, création, pièce rassemblée — garde
     * ses « @{…} » tels quels : écrits par un tiers, ils ne doivent jamais
     * pouvoir faire sortir une autre pièce du dossier (un email reçu qui
     * contiendrait « @{rapport} » et qu'une réponse cite).
     *
     * @param array<string, array> $all pièces du dossier par clé RÉELLE
     * @return callable(string, string): ?callable
     */
    public static function nestedResolver(array $def, array $all): callable
    {
        $pieces = WorkflowSpec::pieces($def);
        $cache = [];
        return static function (string $k, string $real) use ($def, $all, $pieces, &$cache): ?callable {
            $producer = (string)($pieces[$k]['producer'] ?? '');
            $node = $producer !== '' ? WorkflowSpec::node($def, $producer) : null;
            if ($node === null || $node['type'] !== WorkflowSpec::TYPE_STEP || !WorkflowSpec::writes($node)) {
                return null;
            }
            [, $iter] = WorkflowLoop::splitIter($real);
            $id = $producer . '#' . (int)$iter;
            $cache[$id] ??= self::citable($def, $node, $all, $iter);
            $view = $cache[$id];
            return static fn(string $c): ?array => $c !== $k ? ($view[$c] ?? null) : null;
        };
    }

    /**
     * Ce qu'une brique peut citer : les pièces qu'elle lit (readsOf), au
     * dossier, telles qu'elle les voit à son tour de boucle `$iter`.
     *
     * @param array<string, array> $all pièces du dossier par clé RÉELLE
     * @return array<string, array{key: string, content: array}> par clé citable
     */
    public static function citable(array $def, array $node, array $all, ?int $iter): array
    {
        $pieces = WorkflowSpec::pieces($def);
        $loop = WorkflowSpec::loopOf($def, (string)$node['key']);
        $view = WorkflowLoop::view($all, $loop !== null ? WorkflowLoop::bodyPieceKeys($loop) : [], (int)$iter);
        $out = [];
        foreach (WorkflowSpec::readsOf($def, $node) as $k) {
            if (isset($pieces[$k], $view[$k])) {
                $out[$k] = ['key' => (string)($view[$k]['key'] ?? $k), 'content' => (array)($view[$k]['content'] ?? [])];
            }
        }
        return $out;
    }

    private static function expandIn(string $text, array $pieces, callable $lookup, ?callable $image, bool $line, ?callable $nested, string $format, array $seen, int $depth, int &$room): string
    {
        if (!str_contains($text, '@{')) {
            return $text;
        }
        // Au premier niveau, ce qui entre dans le texte prend sa forme ; plus
        // bas, le contenu reste du Markdown, converti avec la pièce qui le porte.
        $top = $depth === 0;
        $value = static fn(string $v): string => $top ? self::escapeValue($v, $format) : $v;
        // [texte inséré, c'est le contenu d'une pièce (Markdown) ?] ; [null] : repère laissé tel quel.
        $resolve = static function (string $k, string $field) use ($pieces, $lookup, $image, $line, $nested, $seen, $depth, $value, &$room): array {
            $hit = $lookup($k);
            if ($hit === null || !isset($pieces[$k])) {
                return [null, false];
            }
            $p = $pieces[$k];
            $content = (array)$hit['content'];
            if ($field !== '') {
                foreach ((array)($p['fields'] ?? []) as $f) {
                    if ($f['key'] === $field) {
                        return [$value(WorkflowValues::display($content['fields'][$f['key']] ?? null, (string)$f['type'])), false];
                    }
                }
                return [null, false];
            }
            $name = '« ' . $p['name'] . ' »';
            if (self::isImage($p, $content)) {
                return [($image !== null ? $image($k, $p, $content, (string)$hit['key']) : null) ?? $value($name), false];
            }
            if ($line || in_array($k, $seen, true) || $depth >= self::MAX_DEPTH) {
                return [$value($line ? (string)$p['name'] : $name), false];
            }
            $rendered = trim(ArtifactContract::render($p, $content, self::INSERT_MAX));
            $size = mb_strlen($rendered);
            if ($size > $room) {
                // Le texte a déjà reçu tout ce qu'il peut porter : le nom.
                return [$value($name), false];
            }
            $room -= $size;
            // La pièce insérée cite d'autres pièces : remplies à leur tour, selon SON auteur.
            $sub = $nested !== null ? $nested($k, (string)$hit['key']) : null;
            if ($sub !== null) {
                $rendered = self::expandIn($rendered, $pieces, $sub, $image, false, $nested, self::FORMAT_MARKDOWN, array_merge($seen, [$k]), $depth + 1, $room);
            }
            return [$rendered, true];
        };
        $shape = static fn(string $md): string => $top ? self::formatInserted($md, $format) : $md;

        // Ce qui a déjà été inséré ne repasse pas par la seconde lecture.
        $done = [];
        if (!$line && $format !== self::FORMAT_HTML) {
            // Un repère seul derrière une puce (« - @{veille} ») : une liste
            // insérée y donnerait « - - … », un texte de plusieurs lignes
            // sortirait de la puce. La liste prend la place de la ligne, le
            // texte reste dans la puce (lignes suivantes en retrait).
            $text = (string)preg_replace_callback(self::BULLET_TOKEN, static function (array $m) use ($resolve, $shape, &$done): string {
                [$inserted, $isContent] = $resolve($m[4], $m[5] ?? '');
                if ($inserted === null) {
                    return $m[0];
                }
                $slot = "\0" . count($done) . "\0";
                $isList = $isContent && trim($inserted) !== '';
                if ($isContent) {
                    foreach (preg_split('/\R/u', $inserted) as $l) {
                        if (trim($l) !== '' && !preg_match(self::LIST_LINE, $l)) {
                            $isList = false;
                            break;
                        }
                    }
                    $inserted = $shape($inserted);
                }
                if (!str_contains($inserted, "\n") && !$isList) {
                    $done[$slot] = $m[1] . $m[2] . ' ' . $inserted;
                    return $slot;
                }
                $indent = $isList ? $m[1] : $m[1] . str_repeat(' ', mb_strlen($m[2]) + 1);
                $out = [];
                foreach (preg_split('/\R/u', $inserted) as $i => $l) {
                    $out[] = $isList ? ($l === '' ? '' : $indent . $l) : ($i === 0 ? $m[1] . $m[2] . ' ' . $l : ($l === '' ? '' : $indent . $l));
                }
                $done[$slot] = implode("\n", $out);
                return $slot;
            }, $text);
        }
        $text = (string)preg_replace_callback(self::TOKEN, static function (array $m) use ($resolve, $shape): string {
            [$inserted, $isContent] = $resolve($m[1], $m[2] ?? '');
            return $inserted === null ? $m[0] : ($isContent ? $shape($inserted) : $inserted);
        }, $text);
        return $done === [] ? $text : strtr($text, $done);
    }

    /** Le contenu d'une pièce (Markdown) dans la forme du texte qui le reçoit. */
    private static function formatInserted(string $markdown, string $format): string
    {
        return match ($format) {
            self::FORMAT_HTML   => MailHtml::toHtml($markdown),
            self::FORMAT_MRKDWN => SlackMrkdwn::toMrkdwn($markdown),
            self::FORMAT_PLAIN  => trim(MarkdownPlain::render(MarkdownDocument::parse($markdown))),
            default             => $markdown,
        };
    }

    /** Une valeur (champ, nom) écrite telle quelle dans un texte de cette forme. */
    private static function escapeValue(string $value, string $format): string
    {
        return match ($format) {
            self::FORMAT_HTML   => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::FORMAT_MRKDWN => strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']),
            default             => $value,
        };
    }

    /**
     * Le contenu d'une pièce que vient de remettre une étape QUI ÉCRIT, ses
     * repères remplis (elle a appris à en mettre) : le dossier garde le vrai
     * contenu — l'historique, le téléchargement, les livraisons et les
     * briques suivantes le lisent tel qu'il est. Seulement vers les pièces
     * qu'elle lit (citable), jamais elle-même ; une image y reste son nom (un
     * texte ne porte pas d'image). Une étape qui n'écrit pas n'a rien appris :
     * un « @{…} » dans sa pièce vient d'ailleurs, il reste tel quel.
     *
     * @param array<string, array> $all pièces du dossier par clé RÉELLE
     */
    public static function expandContent(array $def, array $node, array $piece, array $content, array $all, ?int $iter): array
    {
        if (($node['type'] ?? '') !== WorkflowSpec::TYPE_STEP || !WorkflowSpec::writes($node)) {
            return $content;
        }
        $pieces = WorkflowSpec::pieces($def);
        $own = (string)($piece['key'] ?? '');
        $view = self::citable($def, $node, $all, $iter);
        $lookup = static fn(string $k): ?array => $k !== $own ? ($view[$k] ?? null) : null;
        $nested = self::nestedResolver($def, $all);
        $text = static fn($t, string $format = self::FORMAT_MARKDOWN): mixed
            => is_string($t) ? self::expandText($t, $pieces, $lookup, null, false, $nested, $format, [$own]) : $t;
        $one = static fn($t): mixed => is_string($t) ? self::expandText($t, $pieces, $lookup, null, true, null, self::FORMAT_MARKDOWN, [$own]) : $t;
        switch ((string)($piece['form'] ?? '')) {
            case 'text':
                $content['text'] = $text($content['text'] ?? '');
                break;
            case 'document':
                $content['source'] = match ((string)($content['language'] ?? '')) {
                    'html'         => $text($content['source'] ?? '', self::FORMAT_HTML),
                    'text', 'txt'  => $text($content['source'] ?? '', self::FORMAT_PLAIN),
                    // Un classeur (csv) : chaque repère est une cellule.
                    'csv'          => $one($content['source'] ?? ''),
                    default        => $text($content['source'] ?? ''),
                };
                break;
            case 'list':
                // Un élément de liste tient sur une ligne : le contenu inséré aussi.
                $content['items'] = array_map(static fn($t): mixed => is_string($t)
                    ? trim((string)preg_replace('/\s*\R\s*/u', ' ', (string)$text($t))) : $t, (array)($content['items'] ?? []));
                break;
            case 'table':
                $content['rows'] = array_map(static fn($row) => is_array($row) ? array_map($one, $row) : $row, (array)($content['rows'] ?? []));
                break;
            case 'fiche':
                foreach ((array)($piece['fields'] ?? []) as $f) {
                    $v = $content['fields'][$f['key']] ?? null;
                    if (is_string($v)) {
                        $content['fields'][$f['key']] = ($f['type'] ?? '') === 'longtext' ? $text($v) : $one($v);
                    } elseif (is_array($v)) {
                        $content['fields'][$f['key']] = array_map($one, $v);
                    }
                }
                if (isset($content['summary'])) {
                    $content['summary'] = $text($content['summary']);
                }
                break;
        }
        return $content;
    }

    /**
     * Les repères d'une écriture d'étape de workflow remplis, lus dans le
     * dossier de SON exécution (charge du job : `run_id`, `inserts`). Ne lève
     * jamais : une panne laisse les arguments tels quels (journalisée).
     */
    public static function forTurn(string $tool, array $args, ?array $workflowStep, int $userId): array
    {
        if ($workflowStep === null || !is_array($workflowStep['inserts'] ?? null) || ($workflowStep['inserts']['map'] ?? []) === []) {
            return $args;
        }
        try {
            $run = WorkflowRun::find((int)($workflowStep['run_id'] ?? 0));
            if ($run === null || $run['user_id'] !== $userId) {
                return $args;
            }
            return self::fill($tool, $args, $workflowStep['inserts'], $run['definition'], WorkflowRun::artifacts($run['id']), $run['id']);
        } catch (\Throwable $e) {
            error_log('[Workflows] repères non remplis (' . $tool . ') : ' . $e->getMessage());
            return $args;
        }
    }

    /** Une image du dossier : créée par une brique, ou un fichier image reçu. */
    public static function isImage(array $piece, array $content): bool
    {
        return ($piece['form'] ?? '') === 'image'
            || (($piece['form'] ?? '') === 'file' && str_starts_with((string)($content['type'] ?? ''), 'image/'));
    }

    /** `@[Nom de la pièce]` (la forme de l'éditeur) ramené au repère `@{clé}` — parmi les clés de `$view`. */
    private static function byName(string $text, array $pieces, array $view): string
    {
        if (!str_contains($text, '@[')) {
            return $text;
        }
        $byName = [];
        foreach (array_keys($view) as $k) {
            $byName[mb_strtolower(trim((string)$pieces[$k]['name']))] = $k;
        }
        return (string)preg_replace_callback(self::BY_NAME, static function (array $m) use ($byName): string {
            $k = $byName[mb_strtolower(trim($m[1]))] ?? null;
            return $k !== null ? '@{' . $k . '}' : $m[0];
        }, $text);
    }
}
