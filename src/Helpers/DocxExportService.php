<?php

declare(strict_types=1);

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\IOFactory;

/**
 * Service d'export DOCX d'un message assistant via PHPWord.
 *
 * Approche simple & robuste : on transforme le HTML reçu en HTML "sûr" pour
 * PhpWord (pas de styles inline complexes, pas de balises non gérées) puis
 * on délègue à `Html::addHtml()`. En cas de plantage de Html::addHtml() sur
 * un fragment imprévisible, on tombe sur un rendu texte brut.
 */
class DocxExportService
{
    /**
     * @param bool $companyChrome En-tête et pied aux couleurs de l'entreprise
     *                            (CompanyDocx) : documents produits pour la
     *                            personne, jamais un rapport d'administration.
     */
    public function buildFromHtml(string $html, bool $companyChrome = false): string
    {
        if (!class_exists(PhpWord::class)) {
            throw new RuntimeException('PHPWord n\'est pas disponible (autoload manquant).');
        }

        // Active l'échappement XML côté writer : `&`, `<`, `>` dans le texte
        // doivent être encodés en `&amp;`, `&lt;`, `&gt;` dans `<w:t>` sinon
        // Word refuse d'ouvrir le fichier ("contenu illisible"). PhpWord 1.4
        // a ce flag à false par défaut.
        PhpWordSettings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
        $section = $phpWord->addSection([
            'marginTop'    => 720,  // ≈ 1,27 cm (twips)
            'marginBottom' => 720,
            'marginLeft'   => 720,
            'marginRight'  => 720,
        ]);

        if ($companyChrome && class_exists('CompanyDocx')) {
            CompanyDocx::addChrome($section);
        }

        $safeHtml = $this->prepareHtmlForWord($html);
        try {
            // fullHTML=false, preserveWhiteSpace=false : on injecte un fragment.
            Html::addHtml($section, $safeHtml, false, false);
        } catch (Throwable $e) {
            // Fallback : insertion en texte brut pour ne jamais bloquer l'export.
            error_log('[DocxExport] Html::addHtml a échoué (' . $e->getMessage() . ') — fallback texte brut');
            $section->addText($this->htmlToPlainText($html));
        }

        $tempDir = ROOT . '/storage/cache/docx';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $tmpFile = $tempDir . '/export-' . bin2hex(random_bytes(6)) . '.docx';

        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tmpFile);
            $binary = (string)file_get_contents($tmpFile);
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        if ($binary === '') {
            throw new RuntimeException('Export DOCX vide.');
        }

        return $binary;
    }

    /**
     * Réduit le HTML à un sous-ensemble que `PhpOffice\PhpWord\Shared\Html`
     * digère bien. On garde les structures (titres, paragraphes, listes,
     * tables, code, blockquote, em/strong/u/s, br, hr, liens, images) et on
     * jette le reste (scripts, balises inconnues, attributs hostiles).
     */
    private function prepareHtmlForWord(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '<p></p>';
        }

        // Élimine ce qui ne doit jamais finir dans un docx.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '',  $html) ?? $html;
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html) ?? $html;

        if (!class_exists('DOMDocument')) {
            return '<div>' . $html . '</div>';
        }

        // Étape 1 : parse en HTML5 (tolérant).
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><html><body><div id="__myllm_root">' . $html . '</div></body></html>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return '<div>' . htmlspecialchars($this->htmlToPlainText($html), ENT_QUOTES | ENT_XML1, 'UTF-8') . '</div>';
        }

        $root = $doc->getElementById('__myllm_root');
        if (!$root) {
            $divs = $doc->getElementsByTagName('div');
            $root = $divs->length > 0 ? $divs->item(0) : null;
        }
        if (!$root) {
            return '<div>' . htmlspecialchars($this->htmlToPlainText($html), ENT_QUOTES | ENT_XML1, 'UTF-8') . '</div>';
        }

        $allowedTags = [
            'p','br','hr','h1','h2','h3','h4','h5','h6',
            'strong','b','em','i','u','s','strike','sup','sub',
            'ul','ol','li',
            'blockquote','pre','code',
            'table','thead','tbody','tfoot','tr','th','td',
            'a','span','div',
            // 'img' volontairement exclu : PhpWord casse souvent dessus côté Word
            // (loadXML strict, data URIs énormes). Trop d'effets de bord pour un
            // export "robuste". À ré-introduire séparément si besoin.
        ];
        // `style` autorisé uniquement sur table/tr/td/th : on contrôle ce qu'on
        // injecte (cf. decorateTables) et PhpWord parse les bordures/bg/align.
        $allowedAttrs = [
            'href','title','colspan','rowspan',
            'style','width','align','valign','bgcolor','border',
        ];

        $this->stripUnknown($root, $allowedTags, $allowedAttrs);
        $this->decorateTables($root);
        $this->decorateCode($root, $doc);

        // Étape 2 : sérialise puis force les balises HTML5 void en XML self-closing.
        // PhpWord::Html::addHtml() utilise DOMDocument::loadXML() qui n'accepte pas
        // `<br>` / `<hr>` / `<img>` ; on les convertit en `<br/>` / `<hr/>` / `<img/>`.
        $output = '';
        foreach ($root->childNodes as $child) {
            $xml = $doc->saveXML($child);
            if (is_string($xml)) {
                $output .= $xml;
            }
        }
        $output = $this->forceXmlSelfClosing($output);
        $output = trim($output);
        if ($output === '') {
            return '<p></p>';
        }
        return $output;
    }

    /**
     * Injecte un rendu de tableau cohérent avec celui du chat :
     * - bordures fines grises sur chaque cellule,
     * - en-têtes en fond gris clair + gras,
     * - padding intra-cellule,
     * - largeur 100 %.
     *
     * On préserve toute valeur déjà présente dans l'attribut `style` (l'auteur
     * du HTML reste prioritaire) ; on ne fait qu'ajouter ce qui manque. PhpWord
     * exige le format exact `1px #CCCCCC solid` pour `border` (cf.
     * `Shared/Html::parseInlineStyle`), avec hex couleur sur 6 chars.
     */
    private function decorateTables(DOMNode $root): void
    {
        $borderRule = '1px #C0C0C0 solid';
        $headerBg   = '#F3F4F6';
        $padding    = '4px';

        $defaults = [
            'table' => [
                'border-collapse' => 'collapse',
                'width'           => '100%',
            ],
            'th' => [
                'border'           => $borderRule,
                'background-color' => $headerBg,
                'padding'          => $padding,
                'font-weight'      => 'bold',
                'text-align'       => 'left',
                'vertical-align'   => 'middle',
            ],
            'td' => [
                'border'         => $borderRule,
                'padding'        => $padding,
                'vertical-align' => 'top',
            ],
        ];

        if (!method_exists($root, 'getElementsByTagName')) {
            return;
        }

        // Tables d'abord : force attribut `border` pour PhpWord (utilisé en
        // plus du style ; cf. parseTable, le ratio est divisé par 2).
        foreach ($this->elementsByTag($root, 'table') as $tbl) {
            $this->mergeInlineStyle($tbl, $defaults['table']);
            if (!$tbl->hasAttribute('border')) {
                $tbl->setAttribute('border', '6'); // pixels → ~3 twips après /2 PhpWord
            }
        }
        foreach ($this->elementsByTag($root, 'th') as $th) {
            $this->mergeInlineStyle($th, $defaults['th']);
        }
        foreach ($this->elementsByTag($root, 'td') as $td) {
            $this->mergeInlineStyle($td, $defaults['td']);
        }
    }

    /**
     * Récupère tous les descendants d'un nœud par nom de balise.
     * `DOMElement::getElementsByTagName` existe ; on emballe pour DOMNode aussi.
     */
    private function elementsByTag(DOMNode $node, string $tag): array
    {
        $out = [];
        if ($node instanceof DOMDocument || $node instanceof DOMElement) {
            $nodes = $node->getElementsByTagName($tag);
            for ($i = 0; $i < $nodes->length; $i++) {
                $out[] = $nodes->item($i);
            }
        }
        return $out;
    }

    /**
     * Fusionne `$add` dans l'attribut `style` d'un élément (les valeurs déjà
     * présentes l'emportent — on ne fait qu'ajouter ce qui manque).
     */
    private function mergeInlineStyle(DOMElement $el, array $add): void
    {
        $current = trim((string)$el->getAttribute('style'));
        $existing = [];
        if ($current !== '') {
            foreach (explode(';', $current) as $decl) {
                $decl = trim($decl);
                if ($decl === '') continue;
                $pos = strpos($decl, ':');
                if ($pos === false) continue;
                $k = strtolower(trim(substr($decl, 0, $pos)));
                $v = trim(substr($decl, $pos + 1));
                if ($k !== '' && $v !== '') {
                    $existing[$k] = $v;
                }
            }
        }
        foreach ($add as $k => $v) {
            $k = strtolower($k);
            if (!isset($existing[$k])) {
                $existing[$k] = $v;
            }
        }
        $parts = [];
        foreach ($existing as $k => $v) {
            $parts[] = $k . ':' . $v;
        }
        $el->setAttribute('style', implode(';', $parts));
    }

    /**
     * Rendu code cohérent avec le chat (mais en variante "claire" = imprimable) :
     * - `<pre>` (bloc multi-lignes) → `<p>` empilés sur fond gris, police Courier,
     *   chaque ligne préservée (PhpWord transforme les `\n` en espaces, donc on
     *   éclate manuellement en plusieurs paragraphes pour conserver les sauts).
     * - `<code>` inline (hors `<pre>`) → `<span>` police Courier, fond clair,
     *   couleur d'accent type IDE.
     */
    private function decorateCode(DOMNode $root, DOMDocument $doc): void
    {
        if (!method_exists($root, 'getElementsByTagName')) {
            return;
        }

        $preBg     = '#F0F0F0';
        $preBorder = '1px #D1D5DB solid';
        $prePad    = '6px';
        $preColor  = '#1F2937';
        $codeBg    = '#F3F4F6';
        $codeColor = '#9A3412';
        $codeBorder= '1px #E5E7EB solid';
        $monoFont  = 'Courier New';

        // 1) Blocs <pre> : on extrait le texte brut (avec sauts de ligne),
        //    on remplace le <pre> par une suite de <p> stylés, un par ligne.
        $pres = $this->elementsByTag($root, 'pre');
        foreach ($pres as $pre) {
            if (!$pre instanceof DOMElement) continue;
            $text = $pre->textContent;
            // Normalise les fins de ligne et préserve les blancs.
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $lines = explode("\n", $text);
            // Trim final empty line (souvent ajouté par marked).
            while (count($lines) > 1 && $lines[count($lines) - 1] === '') {
                array_pop($lines);
            }
            if (empty($lines)) {
                $lines = [''];
            }

            $wrapper = $doc->createElement('div');
            $wrapper->setAttribute('style', implode(';', [
                'background-color:' . $preBg,
                'border:' . $preBorder,
                'padding:' . $prePad,
                'font-family:' . $monoFont,
                'color:' . $preColor,
            ]));

            // bg-color appliqué sur chaque <p> aussi : PhpWord ignore les
            // styles sur les <div> conteneurs, donc le fond doit être porté
            // par les paragraphes eux-mêmes pour apparaître dans Word.
            $lineStyle = implode(';', [
                'font-family:' . $monoFont,
                'color:' . $preColor,
                'background-color:' . $preBg,
                'margin:0',
            ]);
            foreach ($lines as $line) {
                $p = $doc->createElement('p');
                $p->setAttribute('style', $lineStyle);
                if ($line === '') {
                    // PhpWord saute les paragraphes vides → on met un espace insécable
                    // pour matérialiser la ligne vide à l'intérieur du bloc code.
                    $p->appendChild($doc->createTextNode("\u{00A0}"));
                } else {
                    // Préserve l'indentation : remplace les espaces consécutifs par NBSP.
                    $preserved = preg_replace_callback('/ {2,}/', static function ($m) {
                        return str_repeat("\u{00A0}", strlen($m[0]));
                    }, $line);
                    $p->appendChild($doc->createTextNode($preserved ?? $line));
                }
                $wrapper->appendChild($p);
            }
            $pre->parentNode?->replaceChild($wrapper, $pre);
        }

        // 2) <code> inline (résiduels, hors <pre> car ces derniers ont été remplacés).
        foreach ($this->elementsByTag($root, 'code') as $code) {
            if (!$code instanceof DOMElement) continue;
            $this->mergeInlineStyle($code, [
                'font-family'      => $monoFont,
                'background-color' => $codeBg,
                'color'            => $codeColor,
                'border'           => $codeBorder,
                'padding'          => '1px 3px',
            ]);
        }
    }

    /**
     * Force la fermeture XML des balises void HTML5. Indispensable pour PhpWord
     * qui parse le HTML via loadXML (strict).
     */
    private function forceXmlSelfClosing(string $html): string
    {
        $void = ['br','hr','img','area','base','col','embed','input','link','meta','param','source','track','wbr'];
        foreach ($void as $tag) {
            // <tag> ou <tag attrs...> non suivi de "/" ⇒ on insère " /" avant ">".
            $html = preg_replace(
                '#<' . $tag . '\b([^>]*?)(?<!/)>#i',
                '<' . $tag . '$1 />',
                $html
            ) ?? $html;
        }
        return $html;
    }

    /**
     * Parcours récursif pour : 1) supprimer balises hors liste blanche en
     * conservant leur contenu textuel ; 2) supprimer attributs hors liste.
     */
    private function stripUnknown(DOMNode $node, array $allowedTags, array $allowedAttrs): void
    {
        // On itère sur une copie car on peut muter la liste pendant le parcours.
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                // Récursion d'abord
                $this->stripUnknown($child, $allowedTags, $allowedAttrs);

                if (!in_array($tag, $allowedTags, true)) {
                    // Remonter les enfants à la place du parent.
                    $parent = $child->parentNode;
                    if ($parent) {
                        while ($child->firstChild) {
                            $parent->insertBefore($child->firstChild, $child);
                        }
                        $parent->removeChild($child);
                    }
                    continue;
                }

                // Nettoyage attributs.
                if ($child->hasAttributes()) {
                    $toRemove = [];
                    foreach ($child->attributes as $attr) {
                        if (!in_array(strtolower($attr->nodeName), $allowedAttrs, true)) {
                            $toRemove[] = $attr->nodeName;
                        }
                    }
                    foreach ($toRemove as $name) {
                        $child->removeAttribute($name);
                    }
                }

                // Sécurité : on bannit les schémas non-http sur href/src.
                foreach (['href', 'src'] as $attrName) {
                    if ($child->hasAttribute($attrName)) {
                        $val = trim((string)$child->getAttribute($attrName));
                        if ($val === '' || !preg_match('#^(https?:|mailto:|/|\.)#i', $val)) {
                            $child->removeAttribute($attrName);
                        }
                    }
                }
            } elseif ($child instanceof DOMComment) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /** Repli ultime : extraction texte brut depuis le HTML, lignes préservées. */
    private function htmlToPlainText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6]|tr)>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
