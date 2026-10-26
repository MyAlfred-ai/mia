<?php

declare(strict_types=1);

/**
 * Corps d'email en HTML — SOURCE UNIQUE, partagée par Gmail et Outlook.
 *
 * LE PROBLÈME RÉSOLU. Les brouillons partaient en `text/plain` : le modèle
 * écrit spontanément du Markdown (**gras**, listes à tirets, [liens](url)),
 * qui atterrissait EN TOUTES LETTRES dans la boîte du destinataire. Même
 * symptôme que les Google Docs avant `DocsFormat`, même remède : convertir
 * côté serveur plutôt que d'espérer que le modèle produise du HTML propre.
 *
 * DEUX ENTRÉES POSSIBLES, et c'est volontaire :
 *  - du texte/Markdown (le cas normal) → converti, tout étant échappé D'ABORD :
 *    aucun fragment produit par un modèle ne peut injecter de balise ;
 *  - du HTML, quand le modèle en écrit quand même → nettoyé par liste blanche
 *    (l'échapper afficherait `<p>` en clair, le passer tel quel laisserait
 *    entrer scripts, styles et gestionnaires d'évènements).
 *
 * Aucune dépendance : le dépôt n'embarque pas de bibliothèque Markdown, et en
 * ajouter une pour six règles d'inline serait payer cher un besoin étroit.
 */
final class MailHtml
{
    /** Balises tolérées dans un corps d'email fourni en HTML. */
    private const TAGS_AUTORISES = '<p><br><b><strong><i><em><u><a><ul><ol><li><blockquote><h1><h2><h3><h4><span><div>';

    /** Schémas d'URL acceptés dans un lien — `javascript:` n'en fait pas partie. */
    private const SCHEMAS = ['http://', 'https://', 'mailto:'];

    /**
     * Corps prêt à envoyer en `text/html`.
     *
     * Ne rend JAMAIS de chaîne vide pour une entrée non vide : un brouillon au
     * corps vide passerait inaperçu jusqu'à l'ouverture dans Gmail.
     */
    public static function toHtml(string $body): string
    {
        $body = self::normaliserSauts($body);
        if (trim($body) === '') {
            return '';
        }
        return self::ressembleAHtml($body) ? self::nettoyer($body) : self::depuisMarkdown($body);
    }

    /**
     * Version texte du corps, pour la part `text/plain` de l'alternative.
     * Les clients qui n'affichent pas le HTML (ou les lecteurs d'écran en mode
     * texte) doivent lire quelque chose de sensé, pas des balises.
     */
    public static function toPlain(string $body): string
    {
        $body = self::normaliserSauts($body);
        return self::ressembleAHtml($body) ? self::htmlVersTexte($body) : $body;
    }

    // ===================== Images intégrées =====================

    /**
     * Repère d'une image intégrée au corps : `[[mia-image:<cid>]]`. Il n'est
     * JAMAIS écrit par le modèle (qui ne peut ni joindre ni désigner un
     * fichier) : c'est le serveur qui le pose, avec la pièce jointe qui porte
     * ce `cid` (WorkflowInserts, image du dossier d'un workflow). Il traverse
     * la conversion (texte sans caractère actif, échappé ou gardé tel quel) et
     * devient `<img src="cid:…">` à l'assemblage du message.
     */
    public const IMAGE_MARKER_PATTERN = '/\[\[mia-image:([a-z0-9][a-z0-9.\-]{0,60}@mia)\]\]/';

    public static function imageMarker(string $cid): string
    {
        return '[[mia-image:' . $cid . ']]';
    }

    /** Un `cid` d'image intégrée de forme valide (celle que pose le serveur). */
    public static function isImageCid(string $cid): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.\-]{0,60}@mia$/', $cid) === 1;
    }

    /**
     * Les repères d'images d'un corps DÉJÀ converti en HTML deviennent les
     * images elles-mêmes : `<img src="cid:…">` (`$preview` faux : le message
     * qui part, la pièce jointe portant ce `cid`), ou leur nom en clair
     * (`$preview` vrai : la carte de relecture, qui n'a pas le fichier).
     * Une image annoncée mais dont le repère a disparu du corps (retouché à la
     * main) est ajoutée à la fin : une image prévue ne se perd jamais. Un
     * repère sans image connue est retiré.
     *
     * @param list<array{cid: string, name?: string}> $images
     */
    public static function withInlineImages(string $html, array $images, bool $preview = false): string
    {
        $byCid = [];
        foreach ($images as $img) {
            $cid = (string)($img['cid'] ?? '');
            if (self::isImageCid($cid)) {
                $byCid[$cid] = trim((string)($img['name'] ?? ''));
            }
        }
        if ($byCid === [] && !str_contains($html, '[[mia-image:')) {
            return $html;
        }
        $render = static function (string $cid, string $name) use ($preview): string {
            $alt = htmlspecialchars($name !== '' ? $name : 'image', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $preview
                ? '<em>[' . $alt . ']</em>'
                : '<img src="cid:' . $cid . '" alt="' . $alt . '" style="display:block;max-width:100%;height:auto;margin:8px 0">';
        };
        $placed = [];
        $html = (string)preg_replace_callback(self::IMAGE_MARKER_PATTERN, static function (array $m) use ($byCid, $render, &$placed): string {
            if (!array_key_exists($m[1], $byCid)) {
                return '';
            }
            $placed[$m[1]] = true;
            return $render($m[1], $byCid[$m[1]]);
        }, $html);
        foreach ($byCid as $cid => $name) {
            if (!isset($placed[$cid])) {
                $html .= "\n<p>" . $render($cid, $name) . '</p>';
            }
        }
        return $html;
    }

    /** Côté texte brut : un repère d'image devient son nom entre crochets. @param list<array{cid: string, name?: string}> $images */
    public static function plainImages(string $text, array $images): string
    {
        $byCid = [];
        foreach ($images as $img) {
            $byCid[(string)($img['cid'] ?? '')] = trim((string)($img['name'] ?? ''));
        }
        return (string)preg_replace_callback(self::IMAGE_MARKER_PATTERN, static function (array $m) use ($byCid): string {
            return isset($byCid[$m[1]]) ? '[' . ($byCid[$m[1]] !== '' ? $byCid[$m[1]] : 'image') . ']' : '';
        }, $text);
    }

    /** Le corps porte-t-il des balises HTML structurantes ? */
    public static function ressembleAHtml(string $body): bool
    {
        return preg_match('#<(?:p|br|div|ul|ol|li|table|a|strong|b|em|i|h[1-6])\b[^>]*>#i', $body) === 1;
    }

    /**
     * HTML → texte lisible. Sert aussi à la signature : elle arrive de Google
     * en HTML, et la part texte du message doit quand même la porter.
     */
    public static function htmlVersTexte(string $html): string
    {
        $t = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $t = preg_replace('#</\s*(?:p|div|li|h[1-6]|tr)\s*>#i', "\n", $t) ?? $t;
        $t = preg_replace('#<\s*li\b[^>]*>#i', '- ', $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /**
     * Assemble corps et signature. Séparateur `--` : la convention que les
     * clients mail savent replier. Signature vide → rien n'est ajouté (pas de
     * séparateur orphelin en bas du message).
     */
    public static function avecSignature(string $corpsHtml, string $signatureHtml): string
    {
        $signatureHtml = trim($signatureHtml);
        if ($signatureHtml === '') {
            return $corpsHtml;
        }
        return $corpsHtml . "\n<br><br>\n<div class=\"gmail_signature\">--<br>\n" . $signatureHtml . "\n</div>";
    }

    /** Même assemblage, côté texte brut. */
    public static function avecSignatureTexte(string $corpsTexte, string $signatureHtml): string
    {
        $sig = self::htmlVersTexte($signatureHtml);
        return $sig === '' ? $corpsTexte : rtrim($corpsTexte) . "\n\n--\n" . $sig;
    }

    // ===================== Conversion =====================

    /**
     * Markdown restreint → HTML. Le texte est échappé AVANT toute
     * transformation : les balises produites ci-dessous sont donc les seules
     * du document, quoi qu'ait écrit le modèle.
     */
    private static function depuisMarkdown(string $texte): string
    {
        $lignes = explode("\n", $texte);
        $html   = [];
        $liste  = null; // 'ul' | 'ol' | null
        $para   = [];

        $viderParagraphe = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . implode('<br>', $para) . '</p>';
                $para   = [];
            }
        };
        $fermerListe = static function () use (&$liste, &$html): void {
            if ($liste !== null) {
                $html[] = '</' . $liste . '>';
                $liste  = null;
            }
        };

        foreach ($lignes as $ligne) {
            $brut = rtrim($ligne);
            $nu   = trim($brut);

            if ($nu === '') {
                $viderParagraphe();
                $fermerListe();
                continue;
            }

            // Titres — bornés à h3/h4 : un h1 dans un email écrase la mise en
            // page du fil de discussion chez la plupart des clients.
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $nu, $m) === 1) {
                $viderParagraphe();
                $fermerListe();
                $niveau = min(4, max(3, strlen($m[1]) + 2));
                $html[] = '<h' . $niveau . '>' . self::inline($m[2]) . '</h' . $niveau . '>';
                continue;
            }

            // Puces
            if (preg_match('/^[-*•]\s+(.+)$/u', $nu, $m) === 1) {
                $viderParagraphe();
                if ($liste !== 'ul') {
                    $fermerListe();
                    $html[] = '<ul>';
                    $liste  = 'ul';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            // Numérotation
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $nu, $m) === 1) {
                $viderParagraphe();
                if ($liste !== 'ol') {
                    $fermerListe();
                    $html[] = '<ol>';
                    $liste  = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $fermerListe();
            $para[] = self::inline($nu);
        }
        $viderParagraphe();
        $fermerListe();

        return implode("\n", $html);
    }

    /** Enrichissements de ligne, sur du texte DÉJÀ échappé. */
    private static function inline(string $texte): string
    {
        $t = htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Liens Markdown d'abord : leur URL ne doit pas être re-capturée par
        // l'autolien qui suit.
        $t = preg_replace_callback(
            '/\[([^\]]{1,200})\]\(([^)\s]{1,500})\)/u',
            static function (array $m): string {
                $url  = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safe = self::lienSur($url);
                // Schéma refusé : le libellé survit en texte, le lien meurt.
                return $safe === '' ? $m[1] : '<a href="' . $safe . '">' . $m[1] . '</a>';
            },
            $t
        ) ?? $t;

        // Gras avant italique : `**x**` sinon capturé comme deux italiques.
        $t = preg_replace('/\*\*([^*]{1,500})\*\*/u', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/(?<![\w*])\*([^*\n]{1,500})\*(?![\w*])/u', '<em>$1</em>', $t) ?? $t;
        $t = preg_replace('/(?<![\w_])_([^_\n]{1,500})_(?![\w_])/u', '<em>$1</em>', $t) ?? $t;

        // URL nue non déjà dans un href.
        $t = preg_replace_callback(
            '#(?<!href=")(?<!>)\bhttps?://[^\s<>"]{4,500}#u',
            static fn(array $m): string => '<a href="' . self::lienSur($m[0]) . '">' . $m[0] . '</a>',
            $t
        ) ?? $t;

        return $t;
    }

    /** URL retenue si son schéma est acceptable, '' sinon (le lien devient du texte). */
    private static function lienSur(string $url): string
    {
        $url = trim($url);
        foreach (self::SCHEMAS as $schema) {
            if (stripos($url, $schema) === 0) {
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        return '';
    }

    /**
     * HTML fourni par un modèle → HTML sûr. Liste blanche de balises, puis
     * TOUS les attributs retirés sauf `href` sur `<a>` : c'est la seule
     * manière simple d'éliminer d'un coup `onclick`, `style`, `srcdoc` et
     * leurs variantes d'écriture.
     */
    private static function nettoyer(string $html): string
    {
        // Groupe CAPTURANT obligatoire : la rétro-référence `\1` ne désigne rien
        // derrière un `(?:…)`, la regex ne matchait donc jamais et `strip_tags`
        // laissait le CONTENU du script en clair dans le message.
        $html = preg_replace('#<\s*(script|style|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = strip_tags($html, self::TAGS_AUTORISES);

        return preg_replace_callback(
            '#<\s*([a-zA-Z0-9]+)\b([^>]*)>#',
            static function (array $m): string {
                $tag = strtolower($m[1]);
                if ($tag !== 'a') {
                    return '<' . $tag . '>';
                }
                if (preg_match('#href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[2], $h) !== 1) {
                    return '<a>';
                }
                $url = html_entity_decode($h[2] ?? ($h[3] ?? ($h[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safe = self::lienSur($url);
                return $safe === '' ? '<a>' : '<a href="' . $safe . '">';
            },
            $html
        ) ?? $html;
    }

    /** CRLF et CR isolés ramenés à LF : le reste du traitement raisonne en LF. */
    private static function normaliserSauts(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }
}
