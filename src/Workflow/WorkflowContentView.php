<?php

declare(strict_types=1);

/**
 * Le CONTENU de ce qui a circulé dans un workflow, pour le relire après coup :
 * une pièce du dossier en entier (texte, document, liste, tableau, fiche,
 * fichier reçu, image, page web, itinéraire) et ce qu'une action a porté — un
 * email (destinataires, objet, corps tel qu'il est parti, pièces jointes), un
 * message Slack ou Teams, un évènement, une tâche, un document créé…
 *
 * LE PROBLÈME RÉSOLU. L'historique d'un workflow ne montrait d'une pièce que
 * trois lignes, et d'une action que son intitulé : impossible de relire
 * l'email qu'une brique « Préparer ou envoyer un email » avait écrit, de
 * savoir à qui il était parti, ni même s'il était parti. Le contenu entier
 * vit pourtant dans les arguments de l'action (l'aperçu de la carte n'en
 * garde qu'un extrait) et dans le dossier de l'exécution.
 *
 * HTML SÛR PAR CONSTRUCTION, rendu ici une seule fois pour l'historique
 * (gabarit) et la page de l'exécution (JSON) : tout texte est échappé ; un
 * corps d'email passe par MailHtml (le rendu même du brouillon, liste
 * blanche), un texte Markdown par MarkdownHtml (aucune image, liens
 * http(s)/mailto seulement). Aucune ressource distante n'est chargée. Une
 * page web n'est jamais insérée ici : elle se voit dans un cadre isolé
 * (sandbox, origine opaque) que html-preview.js remplit à l'ouverture.
 *
 * Pure : aucune requête — l'appelant passe la ligne de la carte (arguments
 * compris, DrivePendingAction::journalForConversation) et les pièces.
 */
final class WorkflowContentView
{
    /** Caractères d'un texte rendus au plus (au-delà : la fin est annoncée, le téléchargement a tout). */
    public const TEXT_MAX  = 60000;
    /** Lignes d'un tableau, éléments d'une liste, colonnes montrés au plus. */
    public const ROWS_MAX  = 200;
    public const ITEMS_MAX = 200;
    public const COLS_MAX  = 30;
    /** Données JSON (workflow n8n) montrées au plus. */
    public const JSON_MAX  = 20000;

    /**
     * Arguments d'une action montrés en en-tête, dans cet ordre. La valeur est
     * le libellé FRANÇAIS, traduit à l'affichage (une constante ne peut pas
     * appeler t()). Ce que l'API attend sans que ce soit lisible (identifiants,
     * modes, fuseau) n'y est pas : l'intitulé de la carte le dit déjà.
     */
    public const HEADER_ARGS = [
        'destinataire'     => 'À',
        'cc'               => 'Cc',
        'objet'            => 'Objet',
        'canal'            => 'Canal',
        'conversation_nom' => 'Conversation',
        'titre'            => 'Titre',
        'sujet'            => 'Sujet',
        'nouveau_nom'      => 'Nouveau nom',
        'agenda'           => 'Agenda',
        'liste'            => 'Liste',
        'debut'            => 'Début',
        'fin'              => 'Fin',
        'lieu'             => 'Lieu',
        'invites'          => 'Invités',
        'echeance'         => 'Échéance',
        'rappel'           => 'Rappel',
        'plage'            => 'Plage',
        'rechercher'       => 'Texte recherché',
        'workflow'         => 'Workflow n8n',
        'nombre_cartes'    => 'Nombre de cartes',
        'url'              => 'Adresse',
    ];

    /** Le corps d'une action : le premier de ces arguments qui est rempli. */
    public const BODY_ARGS = ['corps', 'texte', 'contenu', 'description', 'notes', 'instructions'];

    /** Outils dont `lieu` n'est PAS un lieu (Teams : « conversation » ou « canal »). */
    private const NO_PLACE_TOOLS = ['envoyer_message_teams'];

    /** Outils dont le texte est déjà du mrkdwn Slack : montré tel quel, pas relu en Markdown. */
    private const RAW_TEXT_TOOLS = ['envoyer_message_slack'];

    /** Service d'un outil, pour « Ouvrir dans … » (nom de produit, non traduit). */
    private const SERVICES = [
        'creer_brouillon_gmail'          => 'Gmail',
        'creer_brouillon_outlook'        => 'Outlook',
        'creer_evenement_agenda'         => 'Google Agenda',
        'creer_evenement_agenda_outlook' => 'Outlook',
        'envoyer_message_slack'          => 'Slack',
        'envoyer_message_teams'          => 'Teams',
    ];

    // ==================================================================
    // Une action (carte)
    // ==================================================================

    /**
     * Ce qu'une action a porté, en HTML sûr : ses en-têtes (destinataires,
     * objet, titre, dates…), son corps entier, ses pièces jointes, le lien vers
     * ce qu'elle a produit et les contenus lus avant elle. '' = rien à montrer
     * de plus que l'intitulé.
     *
     * @param array $card  DrivePendingAction::journalForConversation (tool, args, preview, url)
     * @param array<string, string> $names nom de chaque pièce du dossier (pièces jointes d'une livraison)
     * @param array<string, string> $imageUrls adresse (locale) de chaque image du dossier encore présente, par clé
     */
    public static function action(array $card, array $names = [], array $imageUrls = []): string
    {
        $tool = (string)($card['tool'] ?? '');
        $args = is_array($card['args'] ?? null) ? $card['args'] : [];
        $preview = is_array($card['preview'] ?? null) ? $card['preview'] : [];
        $mail = MailDraftReview::isMailTool($tool);

        $rows = [];
        foreach (self::HEADER_ARGS as $key => $label) {
            if ($key === 'lieu' && in_array($tool, self::NO_PLACE_TOOLS, true)) {
                continue;
            }
            $value = self::scalarText($args[$key] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = [t($label), $key === 'url' ? self::link($value, $value) : self::esc($value)];
        }
        // Une réponse : l'objet et le destinataire sont repris du message d'origine.
        if ($mail && trim((string)($args['repondre_a'] ?? '')) !== '') {
            if (self::scalarText($args['objet'] ?? null) === '') {
                $rows[] = [t('Objet'), self::esc(t('(objet repris du message)'))];
            }
            if (self::scalarText($args['destinataire'] ?? null) === '') {
                $rows[] = [t('À'), self::esc(t('expéditeur du message'))];
            }
        }
        $attach = self::attachmentNames($args, $names);
        if ($attach !== []) {
            $rows[] = [t('Pièces jointes'), self::esc(implode(', ', $attach))];
        }

        $body = '';
        foreach (self::BODY_ARGS as $key) {
            $text = is_string($args[$key] ?? null) ? trim($args[$key]) : '';
            if ($text === '') {
                continue;
            }
            $body = match (true) {
                $mail && $key === 'corps'                            => '<div class="wf-view-mail">' . self::links(self::mailImages(MailHtml::toHtml(self::bound($text)), $args, $imageUrls)) . '</div>',
                in_array($tool, self::RAW_TEXT_TOOLS, true)          => self::pre($text),
                default                                              => self::markdown($text),
            };
            break;
        }
        if (isset($args['valeurs'])) {
            $body .= self::table(self::values($args['valeurs']), true);
        }
        if (isset($args['donnees']) && $args['donnees'] !== '' && $args['donnees'] !== []) {
            $body .= self::json($args['donnees']);
        }
        // Rien dans les arguments (outil inconnu, action ancienne) : l'extrait de la carte.
        if ($body === '' && $rows === []) {
            $excerpt = trim((string)($preview['apercu'] ?? ''));
            if ($excerpt !== '') {
                $body = self::pre($excerpt);
            }
        }

        $html = $rows !== [] ? self::meta($rows) : '';
        $html .= $body;
        $url = DrivePendingAction::resultUrl(isset($card['url']) ? (string)$card['url'] : null);
        if ($url !== null) {
            $service = self::SERVICES[$tool] ?? '';
            $html .= '<p class="wf-view-open">' . self::link($url, $service !== '' ? t('Ouvrir dans {service}', ['service' => $service]) : t('Ouvrir le résultat')) . '</p>';
        }
        $sources = array_values(array_filter(array_map('strval', (array)($preview['provenance'] ?? [])), static fn(string $s): bool => trim($s) !== ''));
        if ($sources !== []) {
            $html .= '<div class="wf-view-sources"><small>' . self::esc(t('Préparé après la lecture de :')) . '</small><ul>'
                . implode('', array_map(static fn(string $s): string => '<li>' . self::esc($s) . '</li>', $sources)) . '</ul></div>';
        }
        return $html;
    }

    /** Libellé du dépliant d'une action : « Voir l'email » pour un email, sinon « Voir le contenu ». */
    public static function actionLabel(string $tool): string
    {
        return MailDraftReview::isMailTool($tool) ? t('Voir l’email') : t('Voir le contenu');
    }

    // ==================================================================
    // Une pièce du dossier
    // ==================================================================

    /**
     * Une pièce en entier, en HTML sûr.
     *
     * `$opts` :
     * - download     : adresse de téléchargement de la pièce (page web : son .html)
     * - image        : adresse de l'image créée si le fichier existe encore, sinon ''
     * - conversation : adresse de la conversation de l'exécution, ou ''
     *
     * @param array $piece WorkflowSpec::pieces (form, fields…)
     * @param array $content contenu de la pièce (WorkflowRun::artifacts)
     */
    public static function piece(array $piece, array $content, array $opts = []): string
    {
        $form = (string)($piece['form'] ?? '');
        $conv = (string)($opts['conversation'] ?? '');
        switch ($form) {
            case 'text':
                return self::markdown((string)($content['text'] ?? ''));

            case 'document':
                $source = (string)($content['source'] ?? '');
                return ($content['language'] ?? '') === 'text' ? self::pre($source) : self::markdown($source);

            case 'list':
                $items = array_map('strval', (array)($content['items'] ?? []));
                if ($items === []) {
                    return '';
                }
                $shown = array_slice($items, 0, self::ITEMS_MAX);
                $left = count($items) - count($shown);
                return '<ol class="wf-view-list">' . implode('', array_map(static fn(string $i): string => '<li>' . self::inline($i) . '</li>', $shown)) . '</ol>'
                    . self::more(I18n::plural($left, '+ {n} autre élément', '+ {n} autres éléments'), $left);

            case 'table':
                return self::table(array_values(array_filter((array)($content['rows'] ?? []), 'is_array')), true);

            case 'fiche':
                return self::fiche($piece, $content);

            case 'file':
                $rows = [];
                if (trim((string)($content['name'] ?? '')) !== '') {
                    $rows[] = [t('Fichier'), self::esc((string)$content['name'])];
                }
                if (trim((string)($content['type'] ?? '')) !== '') {
                    $rows[] = [t('Type'), self::esc((string)$content['type'])];
                }
                $url = DrivePendingAction::resultUrl((string)($content['url'] ?? ''));
                $html = $rows !== [] ? self::meta($rows) : '';
                if ($url !== null) {
                    $html .= '<p class="wf-view-open">' . self::link($url, t('Ouvrir dans Drive')) . '</p>';
                }
                $text = trim((string)($content['text'] ?? ''));
                return $html . ($text !== '' ? '<small class="wf-view-caption">' . self::esc(t('Texte lu dans le fichier')) . '</small>' . self::pre($text) : '');

            case 'image':
                $src = (string)($opts['image'] ?? '');
                $prompt = trim((string)($content['revised_prompt'] ?? '') ?: (string)($content['prompt'] ?? ''));
                $html = $src !== '' && str_starts_with($src, '/')
                    ? '<a class="wf-view-img" href="' . self::esc($src) . '" target="_blank" rel="noopener"><img src="' . self::esc($src) . '" alt="" loading="lazy"></a>'
                    : '<p class="wf-view-note">' . self::esc(t('Image retirée avec la conversation de l’exécution : seule sa description reste.')) . '</p>';
                if ($prompt !== '') {
                    $html .= self::meta([[t('Demande'), self::esc($prompt)]]);
                }
                return $html;

            case 'page':
                $download = (string)($opts['download'] ?? '');
                $title = trim((string)($content['title'] ?? ''));
                $html = $title !== '' ? '<p class="wf-view-title">' . self::esc($title) . '</p>' : '';
                if ($download !== '' && str_starts_with($download, '/')) {
                    $html .= '<div class="wf-view-page" data-html-preview="' . self::esc($download . (str_contains($download, '?') ? '&' : '?') . 'format=html') . '">'
                        . '<iframe sandbox="allow-scripts" referrerpolicy="no-referrer" title="' . self::esc($title !== '' ? $title : t('Page web')) . '"></iframe>'
                        . '<p class="wf-view-note" data-preview-error hidden>' . self::esc(t('Aperçu indisponible : téléchargez la page pour l’ouvrir.')) . '</p></div>';
                }
                if ($conv !== '') {
                    $html .= '<p class="wf-view-open"><a href="' . self::esc($conv) . '">' . self::esc(t('Voir la page dans la conversation')) . '</a></p>';
                }
                return $html;

            case 'map':
                $spec = is_array($content['spec'] ?? null) ? $content['spec'] : [];
                $steps = array_values(array_filter(array_map(
                    static fn($w): string => trim((string)(is_array($w) ? ($w['name'] ?? '') : '')),
                    (array)($spec['waypoints'] ?? [])
                ), static fn(string $s): bool => $s !== ''));
                $svg = RouteThumbnail::svg((array)($spec['waypoints'] ?? [])) ?? '';
                $html = ($svg !== '' ? '<div class="wf-view-map">' . $svg . '</div>' : '')
                    . (trim((string)($spec['title'] ?? '')) !== '' ? '<p class="wf-view-title">' . self::esc((string)$spec['title']) . '</p>' : '')
                    . ($steps !== [] ? '<ol class="wf-view-list">' . implode('', array_map(static fn(string $s): string => '<li>' . self::esc($s) . '</li>', $steps)) . '</ol>' : '');
                if ($conv !== '') {
                    $html .= '<p class="wf-view-open"><a href="' . self::esc($conv) . '">' . self::esc(t('Voir la carte dans la conversation')) . '</a></p>';
                }
                return $html;
        }
        return self::markdown((string)($content['text'] ?? ''));
    }

    /** Une fiche : chaque champ et sa valeur entière (liste en puces, texte long tel quel). */
    public static function fiche(array $piece, array $content): string
    {
        $out = '';
        foreach ((array)($piece['fields'] ?? []) as $f) {
            $raw = $content['fields'][$f['key']] ?? null;
            if ($raw === null || $raw === '' || $raw === []) {
                $value = '<span class="wf-view-empty">—</span>';
            } elseif (is_array($raw)) {
                $items = array_slice(array_values(array_filter(array_map([self::class, 'scalarText'], $raw), static fn(string $s): bool => $s !== '')), 0, self::ITEMS_MAX);
                $value = '<ul>' . implode('', array_map(static fn(string $i): string => '<li>' . self::esc($i) . '</li>', $items)) . '</ul>';
            } else {
                $shown = WorkflowValues::display($raw, (string)($f['type'] ?? 'text'));
                $value = '<span class="wf-view-value">' . self::esc(self::bound($shown)) . '</span>';
            }
            $out .= '<div><dt>' . self::esc((string)($f['label'] ?? $f['key'])) . '</dt><dd>' . $value . '</dd></div>';
        }
        $html = $out !== '' ? '<dl class="wf-view-meta is-fiche">' . $out . '</dl>' : '';
        $summary = trim((string)($content['summary'] ?? ''));
        if ($summary !== '') {
            $html .= self::markdown($summary);
        }
        return $html;
    }

    // ==================================================================
    // Morceaux
    // ==================================================================

    /** Un texte Markdown rendu (MarkdownHtml : aucune image, liens sûrs), liens ouverts à part. */
    public static function markdown(string $md): string
    {
        $md = trim($md);
        if ($md === '') {
            return '';
        }
        return '<div class="wf-view-text">' . self::links(MarkdownHtml::render(MarkdownDocument::parse(self::bound($md)))) . '</div>';
    }

    /** Un texte montré tel quel (retours à la ligne gardés). */
    public static function pre(string $text): string
    {
        return '<div class="wf-view-pre">' . self::esc(self::bound(trim($text))) . '</div>';
    }

    /**
     * Un tableau (première ligne = en-tête si `$header`), borné en lignes et
     * en colonnes ; chaque cellule échappée.
     *
     * @param list<list<mixed>> $rows
     */
    public static function table(array $rows, bool $header): string
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if ($rows === []) {
            return '';
        }
        $cols = 0;
        foreach ($rows as $r) {
            $cols = max($cols, count($r));
        }
        $cols = min($cols, self::COLS_MAX);
        $shown = array_slice($rows, 0, self::ROWS_MAX + ($header ? 1 : 0));
        $html = '<div class="wf-view-table"><table>';
        foreach ($shown as $i => $r) {
            $r = array_values($r);
            $tag = $header && $i === 0 ? 'th' : 'td';
            $html .= '<tr>';
            for ($c = 0; $c < $cols; $c++) {
                $html .= '<' . $tag . '>' . self::esc(self::scalarText($r[$c] ?? '')) . '</' . $tag . '>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></div>';
        $left = count($rows) - count($shown);
        return $html . self::more(I18n::plural($left, '+ {n} autre ligne', '+ {n} autres lignes'), $left);
    }

    /** Des données (JSON) montrées indentées. */
    public static function json(mixed $data): string
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $data;
        }
        $text = is_string($data) ? $data : (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (mb_strlen($text) > self::JSON_MAX) {
            $text = mb_substr($text, 0, self::JSON_MAX) . "\n…";
        }
        return '<pre class="wf-view-code">' . self::esc($text) . '</pre>';
    }

    /**
     * Des valeurs de tableur (tableau 2D, ou sa chaîne JSON comme les modèles
     * l'écrivent parfois) ramenées à des lignes de cellules.
     *
     * @return list<list<string>>
     */
    public static function values(mixed $values): array
    {
        if (is_string($values)) {
            $values = json_decode($values, true);
        }
        if (!is_array($values)) {
            return [];
        }
        $rows = [];
        foreach ($values as $row) {
            $rows[] = is_array($row) ? array_map([self::class, 'scalarText'], array_values($row)) : [self::scalarText($row)];
        }
        return $rows;
    }

    /**
     * Les images intégrées à un email (repères `[[mia-image:…]]`, posés par
     * WorkflowInserts) montrées elles-mêmes — l'image du dossier, quand elle
     * est encore là —, sinon leur nom ; une image prévue mais dont le repère
     * a disparu, à la fin (comme à l'envoi).
     */
    private static function mailImages(string $html, array $args, array $imageUrls): string
    {
        $byCid = [];
        foreach ((array)($args[WorkflowDeliveries::ATTACH_KEY] ?? []) as $ref) {
            if (is_array($ref) && MailHtml::isImageCid((string)($ref['cid'] ?? ''))) {
                $url = (string)($imageUrls[(string)($ref['piece'] ?? '')] ?? '');
                $byCid[(string)$ref['cid']] = ['name' => (string)($ref['name'] ?? ''), 'url' => str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : ''];
            }
        }
        if ($byCid === [] && !str_contains($html, '[[mia-image:')) {
            return $html;
        }
        $render = static fn(array $img): string => $img['url'] !== ''
            ? '<img class="wf-view-inline" src="' . self::esc($img['url']) . '" alt="' . self::esc($img['name']) . '" loading="lazy">'
            : '<em class="wf-view-note">[' . self::esc($img['name'] !== '' ? $img['name'] : t('Image')) . ']</em>';
        $placed = [];
        $html = (string)preg_replace_callback(MailHtml::IMAGE_MARKER_PATTERN, static function (array $m) use ($byCid, $render, &$placed): string {
            if (!isset($byCid[$m[1]])) {
                return '';
            }
            $placed[$m[1]] = true;
            return $render($byCid[$m[1]]);
        }, $html);
        foreach ($byCid as $cid => $img) {
            if (!isset($placed[$cid])) {
                $html .= '<p>' . $render($img) . '</p>';
            }
        }
        return $html;
    }

    /** Les pièces jointes d'une livraison, par leur nom (référence interne `_wf_attach`) — hors images intégrées au corps. @return list<string> */
    public static function attachmentNames(array $args, array $names): array
    {
        $out = [];
        foreach ((array)($args[WorkflowDeliveries::ATTACH_KEY] ?? []) as $ref) {
            $key = is_array($ref) && !isset($ref['cid']) ? (string)($ref['piece'] ?? '') : '';
            if ($key !== '') {
                $out[] = (string)($names[$key] ?? $key);
            }
        }
        return $out;
    }

    /** Une valeur d'argument ou de cellule en texte d'une ligne (liste : séparée par des virgules). */
    public static function scalarText(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? t('oui') : t('non');
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        if (is_string($v)) {
            return trim($v);
        }
        if (is_array($v)) {
            return implode(', ', array_values(array_filter(array_map([self::class, 'scalarText'], $v), static fn(string $s): bool => $s !== '')));
        }
        return '';
    }

    /** Une ligne courte de Markdown (élément de liste) : sa syntaxe rendue, sans paragraphe. */
    private static function inline(string $text): string
    {
        $html = self::links(MarkdownHtml::render(MarkdownDocument::parse(self::bound($text))));
        return (string)preg_replace('#^<p>(.*)</p>\s*$#s', '$1', trim($html));
    }

    /** @param list<array{0: string, 1: string}> $rows libellé en clair, valeur déjà en HTML sûr */
    private static function meta(array $rows): string
    {
        return '<dl class="wf-view-meta">' . implode('', array_map(
            static fn(array $r): string => '<div><dt>' . self::esc($r[0]) . '</dt><dd>' . $r[1] . '</dd></div>',
            $rows
        )) . '</dl>';
    }

    /** « + 12 autres lignes » sous ce qui a été coupé (rien si rien ne l'a été). */
    private static function more(string $text, int $n): string
    {
        return $n > 0 ? '<p class="wf-view-note">' . self::esc($text) . '</p>' : '';
    }

    /** Un texte borné à TEXT_MAX, la coupe annoncée. */
    private static function bound(string $text): string
    {
        $len = mb_strlen($text);
        if ($len <= self::TEXT_MAX) {
            return $text;
        }
        return mb_substr($text, 0, self::TEXT_MAX) . "\n\n" . t('[… {n} caractères au total : téléchargez pour tout lire]', ['n' => $len]);
    }

    /** Les liens d'un HTML sûr (MailHtml, MarkdownHtml) s'ouvrent à part, sans dire d'où l'on vient. */
    private static function links(string $html): string
    {
        return str_replace('<a href="', '<a target="_blank" rel="noopener noreferrer" href="', $html);
    }

    private static function link(string $url, string $label): string
    {
        $safe = DrivePendingAction::resultUrl($url);
        if ($safe === null) {
            return self::esc($label);
        }
        return '<a href="' . self::esc($safe) . '" target="_blank" rel="noopener noreferrer">' . self::esc($label) . '</a>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
