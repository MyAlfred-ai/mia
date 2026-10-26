<?php

declare(strict_types=1);

/**
 * Modèle Word de l'entreprise : ANALYSE et GREFFE.
 *
 * LE PROBLÈME RÉSOLU. Un document Word fabriqué par MIA (DocxFileBuilder,
 * export d'une réponse) sortait en Calibri, sur une page A4 nue : propre,
 * jamais à la charte du client. L'administrateur dépose le modèle officiel
 * (.docx ou .dotx) dans Organisation → Entreprise ; les documents Word sont
 * alors construits DANS ce modèle.
 *
 * COMMENT. Le document est d'abord fabriqué comme avant (PhpWord : titres,
 * listes, tableaux, liens), puis son CORPS est greffé dans une copie du
 * modèle, dont on garde tout le reste : styles, thème (polices, couleurs),
 * mise en page, en-têtes et pieds (logo, mentions), propriétés. Les titres
 * prennent les styles « Titre 1, 2, 3… » du modèle — retrouvés par leur nom
 * interne (« heading 1 »), qui ne change pas avec la langue de Word, alors
 * que l'identifiant, lui, change (`Heading1` en anglais, `Titre1` en
 * français). Le texte courant prend le style Normal du modèle.
 *
 * Ce qui accompagne le corps : les définitions de listes (fusionnées dans
 * celles du modèle, identifiants décalés), les liens et les images (relations
 * recopiées), les styles que le modèle n'a pas. Le contenu d'exemple du
 * modèle est retiré, ses commentaires aussi ; une protection en écriture
 * aussi (un document fabriqué se retravaille). Un modèle SANS pied de page
 * reçoit celui de MIA (mention de l'entreprise et numéro de page) ; un modèle
 * qui en a un le garde tel quel.
 *
 * Garde-fous du dépôt (OfficePackage) : archive valide, document Word sans
 * macros, taille et nombre d'entrées bornés, relations confinées au paquet.
 */
final class DocxTemplate
{
    private const W = OfficePackage::NS_W;
    private const R = OfficePackage::NS_R;

    private const CT_PREFIX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';
    private const CT_MAIN   = self::CT_PREFIX . 'document.main+xml';
    private const CT_MAINS  = [self::CT_PREFIX . 'document.main+xml', self::CT_PREFIX . 'template.main+xml'];

    /**
     * Styles de titre signalés à l'écran : nom interne → libellé de Word en
     * français. Les libellés sont des clés de traduction (« Titre 1 » →
     * « Heading 1 », le nom du même style dans un Word anglais) : l'écran les
     * passe à t().
     */
    public const HEADING_LABELS = [
        'title'     => 'Titre',
        'heading 1' => 'Titre 1',
        'heading 2' => 'Titre 2',
        'heading 3' => 'Titre 3',
    ];

    /** Types d'images qu'un corps peut porter, par extension. */
    private const MEDIA_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'emf' => 'image/x-emf', 'wmf' => 'image/x-wmf',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    ];

    // ==================================================================
    // Analyse
    // ==================================================================

    /**
     * Compte rendu d'un modèle, pour l'écran d'administration et le refus d'un
     * fichier inexploitable.
     *
     * @return array<string, mixed> `ok` + `error`, ou la fiche : page, margins,
     *         headers, footers, header_image, styles, fonts, colors, content.
     */
    public static function analyze(string $path): array
    {
        try {
            $zip = OfficePackage::open($path, 'Word');
        } catch (OfficeTemplateException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $main = self::mainChecked($zip);
            $doc  = OfficePackage::xml($zip, $main);
            $xp   = OfficePackage::xpath($doc);
            $rels = OfficePackage::rels($zip, $main);

            $styles = [];
            $stylePart = self::relTarget($rels, '/styles');
            if ($stylePart !== null && $zip->locateName($stylePart) !== false) {
                $byName = self::styleIndex(OfficePackage::xml($zip, $stylePart))['byName']['paragraph'] ?? [];
                foreach (self::HEADING_LABELS as $name => $label) {
                    $styles[$name] = isset($byName[$name]);
                }
            }

            $theme = ['fonts' => ['major' => '', 'minor' => ''], 'colors' => []];
            $themePart = self::relTarget($rels, '/theme');
            if ($themePart !== null && $zip->locateName($themePart) !== false) {
                $theme = self::theme(OfficePackage::xml($zip, $themePart));
            }

            $sect = $xp->query('/w:document/w:body/w:sectPr')->item(0);
            $page = ['w' => 11906, 'h' => 16838, 'orient' => 'portrait'];
            $margins = null;
            $headers = $footers = 0;
            $headerImage = false;
            if ($sect instanceof DOMElement) {
                $sz = $xp->query('w:pgSz', $sect)->item(0);
                if ($sz instanceof DOMElement) {
                    $page['w'] = (int)$sz->getAttributeNS(self::W, 'w') ?: $page['w'];
                    $page['h'] = (int)$sz->getAttributeNS(self::W, 'h') ?: $page['h'];
                    $page['orient'] = $sz->getAttributeNS(self::W, 'orient') === 'landscape' || $page['w'] > $page['h'] ? 'landscape' : 'portrait';
                }
                $mar = $xp->query('w:pgMar', $sect)->item(0);
                if ($mar instanceof DOMElement) {
                    $margins = [
                        'top' => (int)$mar->getAttributeNS(self::W, 'top'), 'bottom' => (int)$mar->getAttributeNS(self::W, 'bottom'),
                        'left' => (int)$mar->getAttributeNS(self::W, 'left'), 'right' => (int)$mar->getAttributeNS(self::W, 'right'),
                    ];
                }
                foreach ($xp->query('w:headerReference', $sect) as $ref) {
                    $headers++;
                    $part = $rels[(string)$ref->getAttributeNS(self::R, 'id')]['target'] ?? null;
                    if ($part !== null && !$headerImage) {
                        $xml = (string)$zip->getFromName($part);
                        $headerImage = str_contains($xml, '<w:drawing') || str_contains($xml, '<w:pict') || str_contains($xml, '<v:imagedata');
                    }
                }
                $footers = $xp->query('w:footerReference', $sect)->length;
            }

            $content = 0;
            foreach ($xp->query('/w:document/w:body//w:p') as $p) {
                if (trim($p->textContent) !== '') {
                    $content++;
                }
            }

            return [
                'ok'           => true,
                'page'         => self::pageLabel($page),
                // Dimensions brutes : l'écran recalcule le libellé dans la
                // langue de qui le lit (`page` reste dans celle du dépôt).
                'page_size'    => $page,
                'margins'      => $margins,
                'headers'      => $headers,
                'footers'      => $footers,
                'header_image' => $headerImage,
                'styles'       => $styles,
                'fonts'        => $theme['fonts'],
                'colors'       => $theme['colors'],
                'content'      => $content,
            ];
        } catch (OfficeTemplateException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[DocxTemplate] Analyse impossible : ' . get_class($e) . ' — ' . $e->getMessage());

            return ['ok' => false, 'error' => t('Ce fichier n’est pas un modèle Word lisible.')];
        } finally {
            $zip->close();
        }
    }

    /**
     * « A4 portrait », « Lettre US paysage », sinon les dimensions en cm —
     * dans la langue courante (« US Letter landscape », « 10.0 × 20.0 cm »).
     */
    public static function pageLabel(array $page): string
    {
        $w = (int)($page['w'] ?? 0);
        $h = (int)($page['h'] ?? 0);
        $short = min($w, $h);
        $long  = max($w, $h);
        $dec = I18n::locale() === 'en' ? '.' : ',';
        $name = match (true) {
            abs($short - 11906) < 60 && abs($long - 16838) < 60 => 'A4',
            abs($short - 12240) < 60 && abs($long - 15840) < 60 => t('Lettre US'),
            abs($short - 8391) < 60 && abs($long - 11906) < 60  => 'A5',
            default => number_format($w / 567, 1, $dec, '') . ' × ' . number_format($h / 567, 1, $dec, '') . ' cm',
        };

        return ($page['orient'] ?? 'portrait') === 'landscape'
            ? t('{format} paysage', ['format' => $name])
            : t('{format} portrait', ['format' => $name]);
    }

    // ==================================================================
    // Greffe
    // ==================================================================

    /**
     * Octets d'un document .docx : le modèle, son contenu d'exemple retiré,
     * le corps du document fabriqué à la place.
     *
     * @param string $generated Octets du document fabriqué (PhpWord).
     * @param array{title?: string, author?: string, footer?: string} $options
     *
     * @throws OfficeTemplateException Modèle inexploitable.
     */
    public static function fill(string $templatePath, string $generated, array $options = []): string
    {
        $work = FileScratch::path('modele', 'docx');
        $gen  = FileScratch::path('corps', 'docx');
        if (!@copy($templatePath, $work)) {
            throw new OfficeTemplateException(t('Le modèle Word de l’entreprise est introuvable.'));
        }

        try {
            if (@file_put_contents($gen, $generated) === false) {
                throw new RuntimeException('Document fabriqué impossible à écrire.');
            }
            $source = new ZipArchive();
            if ($source->open($gen) !== true) {
                throw new RuntimeException('Document fabriqué illisible.');
            }
            try {
                $zip = OfficePackage::open($work, 'Word');
                try {
                    (new self($zip, $source, $options))->build();
                } finally {
                    $zip->close();
                }
            } finally {
                $source->close();
            }

            $bytes = (string)file_get_contents($work);
            if ($bytes === '') {
                throw new OfficeTemplateException(t('Le document n’a pas pu être écrit.'));
            }

            return $bytes;
        } finally {
            FileScratch::discard($work);
            FileScratch::discard($gen);
        }
    }

    // ------------------------------------------------------------------
    // État d'une greffe
    // ------------------------------------------------------------------

    private ZipArchive $zip;
    private ZipArchive $source;
    private string $title;
    private string $author;
    private string $footer;
    private string $main;
    private DOMDocument $ct;
    private DOMDocument $doc;
    private DOMDocument $rels;
    /** @var array<string, array{type: string, target: string, external: bool, raw: string}> */
    private array $relIndex;
    private int $relSeq = 0;

    private function __construct(ZipArchive $zip, ZipArchive $source, array $options)
    {
        $this->zip    = $zip;
        $this->source = $source;
        $this->title  = OfficePackage::clean((string)($options['title'] ?? ''));
        $this->author = OfficePackage::clean((string)($options['author'] ?? ''));
        $this->footer = OfficePackage::clean((string)($options['footer'] ?? ''));
    }

    private function build(): void
    {
        $this->main     = self::mainChecked($this->zip);
        $this->ct       = OfficePackage::xml($this->zip, '[Content_Types].xml');
        $this->doc      = OfficePackage::xml($this->zip, $this->main);
        $this->relIndex = OfficePackage::rels($this->zip, $this->main);
        $relsPath       = OfficePackage::relsPath($this->main);
        $this->rels     = $this->zip->locateName($relsPath) !== false
            ? OfficePackage::xml($this->zip, $relsPath)
            : self::emptyRels();

        $srcMain = OfficePackage::mainPart($this->source);
        $srcDoc  = OfficePackage::xml($this->source, $srcMain);
        $srcRels = OfficePackage::rels($this->source, $srcMain);

        $nodes = $this->replaceBody($srcDoc);
        $this->mapStyles($nodes, $srcRels);
        $this->mergeNumbering($nodes, $srcRels);
        $this->copyRelations($nodes, $srcRels, $srcMain);
        $this->stripAnnotations($nodes);
        $this->defaultFooter();
        $this->removeComments();
        $this->unprotect();
        $this->documentProperties();
        $this->setMainContentType();

        $this->zip->addFromString($this->main, (string)$this->doc->saveXML());
        $this->zip->addFromString($relsPath, (string)$this->rels->saveXML());
        $this->zip->addFromString('[Content_Types].xml', (string)$this->ct->saveXML());
    }

    /**
     * Corps du modèle vidé (sa mise en page finale gardée), corps fabriqué à
     * la place.
     *
     * @return list<DOMElement> Nœuds importés.
     */
    private function replaceBody(DOMDocument $srcDoc): array
    {
        $xp   = OfficePackage::xpath($this->doc);
        $body = $xp->query('/w:document/w:body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new OfficeTemplateException(t('Ce fichier n’est pas un modèle Word lisible.'));
        }

        $sect = null;
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::W && $child->localName === 'sectPr') {
                $sect = $child;
                continue;
            }
            $body->removeChild($child);
        }

        $srcBody = OfficePackage::xpath($srcDoc)->query('/w:document/w:body')->item(0);
        $nodes   = [];
        if ($srcBody instanceof DOMElement) {
            foreach ($srcBody->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->namespaceURI === self::W && $child->localName === 'sectPr') {
                    if ($sect === null) {
                        // Modèle sans mise en page : celle du document fabriqué.
                        $sect = $this->doc->importNode($child, true);
                        $body->appendChild($sect);
                    }
                    continue;
                }
                $node    = $this->doc->importNode($child, true);
                $nodes[] = $sect !== null ? $body->insertBefore($node, $sect) : $body->appendChild($node);
            }
        }
        if ($nodes === []) {
            // Un corps vide n'est pas valide : un paragraphe vide le remplace.
            $p = $this->doc->createElementNS(self::W, 'w:p');
            $nodes[] = $sect !== null ? $body->insertBefore($p, $sect) : $body->appendChild($p);
        }

        return $nodes;
    }

    /**
     * Styles du corps → styles du modèle, par NOM interne et par type ; un
     * style que le modèle n'a pas est recopié (sinon Word retombe sur Normal
     * et les titres disparaissent).
     *
     * @param list<DOMElement> $nodes
     */
    private function mapStyles(array $nodes, array $srcRels): void
    {
        $srcPart = self::relTarget($srcRels, '/styles');
        $tplPart = self::relTarget($this->relIndex, '/styles');
        if ($srcPart === null || $tplPart === null
            || $this->source->locateName($srcPart) === false || $this->zip->locateName($tplPart) === false) {
            return;
        }
        $srcStyles = OfficePackage::xml($this->source, $srcPart);
        $tplStyles = OfficePackage::xml($this->zip, $tplPart);
        $src = self::styleIndex($srcStyles);
        $tpl = self::styleIndex($tplStyles);
        $copied = [];

        foreach (['pStyle' => 'paragraph', 'rStyle' => 'character', 'tblStyle' => 'table'] as $tag => $type) {
            foreach ($nodes as $node) {
                foreach ($node->getElementsByTagNameNS(self::W, $tag) as $ref) {
                    $id = (string)$ref->getAttributeNS(self::W, 'val');
                    if ($id === '') {
                        continue;
                    }
                    $name = $src['byId'][$id]['name'] ?? null;
                    if ($name !== null && isset($tpl['byName'][$type][$name])) {
                        $ref->setAttributeNS(self::W, 'w:val', $tpl['byName'][$type][$name]);
                        continue;
                    }
                    if (isset($tpl['byId'][$id]) || isset($copied[$id])) {
                        continue;
                    }
                    $element = $src['byId'][$id]['element'] ?? null;
                    if ($element instanceof DOMElement) {
                        $clone = $tplStyles->importNode($element, true);
                        // Héritage vers un style que le modèle n'a pas : retiré.
                        foreach (['basedOn', 'next', 'link'] as $child) {
                            foreach (iterator_to_array($clone->getElementsByTagNameNS(self::W, $child)) as $b) {
                                if (!isset($tpl['byId'][(string)$b->getAttributeNS(self::W, 'val')])) {
                                    $b->parentNode?->removeChild($b);
                                }
                            }
                        }
                        $tplStyles->documentElement->appendChild($clone);
                        $copied[$id] = true;
                    }
                }
            }
        }

        if ($copied !== []) {
            $this->zip->addFromString($tplPart, (string)$tplStyles->saveXML());
        }
    }

    /**
     * Définitions de listes du corps ajoutées à celles du modèle, identifiants
     * décalés ; puis les définitions abstraites renumérotées par POSITION
     * (0, 1, 2…) — l'aperçu de macOS et d'iOS les cherche ainsi (cf.
     * DocxFileBuilder::normalizeForReaders). Les instances (`w:num`) gardent
     * les numéros du modèle : ses styles peuvent les citer.
     *
     * @param list<DOMElement> $nodes
     */
    private function mergeNumbering(array $nodes, array $srcRels): void
    {
        $refs = [];
        foreach ($nodes as $node) {
            foreach ($node->getElementsByTagNameNS(self::W, 'numId') as $ref) {
                if ((int)$ref->getAttributeNS(self::W, 'val') > 0) {
                    $refs[] = $ref;
                }
            }
        }
        $srcPart = self::relTarget($srcRels, '/numbering');
        if ($refs === [] || $srcPart === null || $this->source->locateName($srcPart) === false) {
            return;
        }
        $srcNum = OfficePackage::xml($this->source, $srcPart);

        $tplPart = self::relTarget($this->relIndex, '/numbering');
        if ($tplPart === null || $this->zip->locateName($tplPart) === false) {
            // Modèle sans listes : celles du document fabriqué, telles quelles.
            $tplPart = $this->freePart('word/numbering.xml');
            $this->zip->addFromString($tplPart, (string)$srcNum->saveXML());
            $this->addRel('numbering', OfficePackage::relative($this->main, $tplPart));
            $this->override($tplPart, self::CT_PREFIX . 'numbering+xml');

            return;
        }

        $tplNum = OfficePackage::xml($this->zip, $tplPart);
        $root   = $tplNum->documentElement;
        $maxAbs = -1;
        $maxNum = 0;
        foreach ($root->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
            $maxAbs = max($maxAbs, (int)$a->getAttributeNS(self::W, 'abstractNumId'));
        }
        $firstNum = null;
        foreach ($root->getElementsByTagNameNS(self::W, 'num') as $n) {
            $firstNum ??= $n;
            $maxNum = max($maxNum, (int)$n->getAttributeNS(self::W, 'numId'));
        }
        $cleanup = $root->getElementsByTagNameNS(self::W, 'numIdMacAtCleanup')->item(0);

        $absOffset = $maxAbs + 1;
        foreach ($srcNum->documentElement->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
            $clone = $tplNum->importNode($a, true);
            $clone->setAttributeNS(self::W, 'w:abstractNumId', (string)((int)$a->getAttributeNS(self::W, 'abstractNumId') + $absOffset));
            foreach ($clone->getElementsByTagNameNS(self::W, 'nsid') as $nsid) {
                $nsid->setAttributeNS(self::W, 'w:val', strtoupper(bin2hex(random_bytes(4))));
            }
            // Avant la première instance : le schéma range les définitions d'abord.
            $firstNum !== null ? $root->insertBefore($clone, $firstNum) : ($cleanup !== null ? $root->insertBefore($clone, $cleanup) : $root->appendChild($clone));
        }
        $map = [];
        foreach ($srcNum->documentElement->getElementsByTagNameNS(self::W, 'num') as $n) {
            $old   = (int)$n->getAttributeNS(self::W, 'numId');
            $new   = $old + $maxNum;
            $map[$old] = $new;
            $clone = $tplNum->importNode($n, true);
            $clone->setAttributeNS(self::W, 'w:numId', (string)$new);
            foreach ($clone->getElementsByTagNameNS(self::W, 'abstractNumId') as $abs) {
                $abs->setAttributeNS(self::W, 'w:val', (string)((int)$abs->getAttributeNS(self::W, 'val') + $absOffset));
            }
            $cleanup !== null ? $root->insertBefore($clone, $cleanup) : $root->appendChild($clone);
        }
        foreach ($refs as $ref) {
            $old = (int)$ref->getAttributeNS(self::W, 'val');
            if (isset($map[$old])) {
                $ref->setAttributeNS(self::W, 'w:val', (string)$map[$old]);
            }
        }

        // Définitions abstraites renumérotées par position.
        $positions = [];
        foreach ($root->getElementsByTagNameNS(self::W, 'abstractNum') as $i => $a) {
            $positions[(string)$a->getAttributeNS(self::W, 'abstractNumId')] = $i;
            $a->setAttributeNS(self::W, 'w:abstractNumId', (string)$i);
        }
        foreach ($root->getElementsByTagNameNS(self::W, 'num') as $n) {
            foreach ($n->getElementsByTagNameNS(self::W, 'abstractNumId') as $abs) {
                $val = (string)$abs->getAttributeNS(self::W, 'val');
                if (isset($positions[$val])) {
                    $abs->setAttributeNS(self::W, 'w:val', (string)$positions[$val]);
                }
            }
        }

        $this->zip->addFromString($tplPart, (string)$tplNum->saveXML());
    }

    /**
     * Relations citées par le corps (liens, images) recopiées dans le modèle,
     * sous de nouveaux identifiants ; les images avec leurs octets.
     *
     * @param list<DOMElement> $nodes
     */
    private function copyRelations(array $nodes, array $srcRels, string $srcMain): void
    {
        $map = [];
        foreach ($nodes as $node) {
            foreach (self::relAttributes($node) as $attr) {
                $old = $attr->value;
                if (!isset($map[$old])) {
                    $rel = $srcRels[$old] ?? null;
                    $map[$old] = $rel === null ? null : $this->copyRelation($rel, $srcMain);
                }
                if ($map[$old] !== null) {
                    $attr->value = $map[$old];
                } else {
                    // Relation introuvable : l'attribut orphelin rendrait le fichier illisible.
                    $attr->ownerElement?->removeAttributeNode($attr);
                }
            }
        }
    }

    /** @param array{type: string, target: string, external: bool, raw: string} $rel */
    private function copyRelation(array $rel, string $srcMain): ?string
    {
        if ($rel['external']) {
            return $this->addRel(substr($rel['type'], strlen(OfficePackage::REL)), $rel['raw'], true, $rel['type']);
        }
        if (!str_ends_with($rel['type'], '/image')) {
            return null;
        }
        $bytes = $this->source->getFromName($rel['target']);
        $ext   = strtolower(pathinfo($rel['target'], PATHINFO_EXTENSION));
        if (!is_string($bytes) || !isset(self::MEDIA_TYPES[$ext])) {
            return null;
        }
        $part = $this->freePart('word/media/mia-image.' . $ext);
        $this->zip->addFromString($part, $bytes);
        $this->defaultType($ext, self::MEDIA_TYPES[$ext]);

        return $this->addRel('image', OfficePackage::relative($this->main, $part));
    }

    /** @return list<DOMAttr> Attributs `r:id`, `r:embed`, `r:link`… d'un sous-arbre. */
    private static function relAttributes(DOMElement $node): array
    {
        $out = [];
        $xp  = OfficePackage::xpath($node->ownerDocument);
        foreach ($xp->query('descendant-or-self::*/@r:*', $node) as $attr) {
            if ($attr instanceof DOMAttr) {
                $out[] = $attr;
            }
        }

        return $out;
    }

    /**
     * Notes, commentaires et renvois qui pointeraient vers des parties du
     * modèle : retirés du corps greffé (le document fabriqué n'en porte pas,
     * un export HTML pourrait).
     *
     * @param list<DOMElement> $nodes
     */
    private function stripAnnotations(array $nodes): void
    {
        foreach ($nodes as $node) {
            foreach (['footnoteReference', 'endnoteReference', 'commentReference', 'commentRangeStart', 'commentRangeEnd'] as $tag) {
                foreach (iterator_to_array($node->getElementsByTagNameNS(self::W, $tag)) as $el) {
                    $el->parentNode?->removeChild($el);
                }
            }
        }
    }

    /**
     * Modèle sans pied de page : celui de MIA (mention de l'entreprise et
     * numéro de page). Un modèle qui a le sien le garde.
     */
    private function defaultFooter(): void
    {
        if ($this->footer === '') {
            return;
        }
        $xp   = OfficePackage::xpath($this->doc);
        $sect = $xp->query('/w:document/w:body/w:sectPr')->item(0);
        if (!$sect instanceof DOMElement || $xp->query('w:footerReference', $sect)->length > 0) {
            return;
        }

        $part = $this->freePart('word/footer-mia.xml');
        $this->zip->addFromString($part, self::footerXml($this->footer));
        $this->override($part, self::CT_PREFIX . 'footer+xml');
        $rid = $this->addRel('footer', OfficePackage::relative($this->main, $part));

        $ref = $this->doc->createElementNS(self::W, 'w:footerReference');
        $ref->setAttributeNS(self::W, 'w:type', 'default');
        $ref->setAttributeNS(self::R, 'r:id', $rid);
        // Le schéma veut les renvois d'en-tête et de pied EN TÊTE de la section.
        $after = null;
        foreach ($xp->query('w:headerReference', $sect) as $h) {
            $after = $h;
        }
        $next = $after !== null ? $after->nextSibling : $sect->firstChild;
        $next !== null ? $sect->insertBefore($ref, $next) : $sect->appendChild($ref);
    }

    /** Pied de page : la mention, centrée, puis le numéro de page (champ PAGE). */
    public static function footerXml(string $text): string
    {
        $rPr = '<w:rPr><w:color w:val="7F7F7F"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '">'
            . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . OfficePackage::esc($text) . ' · </w:t></w:r>'
            . '<w:r>' . $rPr . '<w:fldChar w:fldCharType="begin"/></w:r>'
            . '<w:r>' . $rPr . '<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            . '<w:r>' . $rPr . '<w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r>' . $rPr . '<w:t>1</w:t></w:r>'
            . '<w:r>' . $rPr . '<w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p></w:ftr>';
    }

    /** Commentaires du modèle (sur son contenu d'exemple, retiré) : retirés aussi. */
    private function removeComments(): void
    {
        $xp = OfficePackage::xpath($this->rels);
        foreach (iterator_to_array($xp->query('/rel:Relationships/rel:Relationship')) as $rel) {
            $type = (string)$rel->getAttribute('Type');
            if (preg_match('#/(comments|commentsExtended|commentsIds|commentsExtensible)$#', $type) !== 1) {
                continue;
            }
            $id   = (string)$rel->getAttribute('Id');
            $part = $this->relIndex[$id]['target'] ?? null;
            if ($part !== null && empty($this->relIndex[$id]['external'])) {
                $this->deletePart($part);
            }
            $rel->parentNode?->removeChild($rel);
        }
    }

    /** Un document fabriqué se retravaille : la protection du modèle est levée. */
    private function unprotect(): void
    {
        $part = self::relTarget($this->relIndex, '/settings');
        if ($part === null || $this->zip->locateName($part) === false) {
            return;
        }
        $settings = OfficePackage::xml($this->zip, $part);
        $changed  = false;
        foreach (['documentProtection', 'writeProtection'] as $tag) {
            foreach (iterator_to_array($settings->getElementsByTagNameNS(self::W, $tag)) as $el) {
                $el->parentNode?->removeChild($el);
                $changed = true;
            }
        }
        if ($changed) {
            $this->zip->addFromString($part, (string)$settings->saveXML());
        }
    }

    /** Titre, auteur et dates du document ; la miniature du modèle est retirée. */
    private function documentProperties(): void
    {
        $root = OfficePackage::xml($this->zip, '_rels/.rels');
        foreach (iterator_to_array(OfficePackage::xpath($root)->query('/rel:Relationships/rel:Relationship')) as $rel) {
            if (str_ends_with((string)$rel->getAttribute('Type'), '/thumbnail')) {
                $this->deletePart(OfficePackage::resolve('', (string)$rel->getAttribute('Target')));
                $rel->parentNode?->removeChild($rel);
                $this->zip->addFromString('_rels/.rels', (string)$root->saveXML());
            }
        }

        $core = 'docProps/core.xml';
        if ($this->zip->locateName($core) === false) {
            return;
        }
        try {
            $doc = OfficePackage::xml($this->zip, $core);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $set = function (string $ns, string $qname, string $value, bool $w3c = false) use ($doc): void {
                [, $local] = explode(':', $qname);
                $el = $doc->getElementsByTagNameNS($ns, $local)->item(0);
                if ($el === null) {
                    $el = $doc->createElementNS($ns, $qname);
                    $doc->documentElement->appendChild($el);
                }
                $el->textContent = $value;
                if ($w3c) {
                    $el->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', 'dcterms:W3CDTF');
                }
            };
            if ($this->title !== '') {
                $set(OfficePackage::NS_DC, 'dc:title', $this->title);
            }
            if ($this->author !== '') {
                $set(OfficePackage::NS_DC, 'dc:creator', $this->author);
                $set(OfficePackage::NS_CP, 'cp:lastModifiedBy', $this->author);
            }
            $set(OfficePackage::NS_DCTERMS, 'dcterms:created', $now, true);
            $set(OfficePackage::NS_DCTERMS, 'dcterms:modified', $now, true);
            $this->zip->addFromString($core, (string)$doc->saveXML());
        } catch (Throwable) {
            // Propriétés accessoires : jamais un échec de fabrication.
        }
    }

    /** Un .dotx devient un document : sinon Word l'ouvre comme « nouveau document ». */
    private function setMainContentType(): void
    {
        $this->override($this->main, self::CT_MAIN);
    }

    // ------------------------------------------------------------------
    // Paquet
    // ------------------------------------------------------------------

    /** @throws OfficeTemplateException */
    private static function mainChecked(ZipArchive $zip): string
    {
        $main = OfficePackage::mainPart($zip);
        $type = OfficePackage::contentType($zip, $main);
        if (str_contains($type, 'macroEnabled')) {
            throw new OfficeTemplateException(t('Ce modèle contient des macros : enregistrez-le sans macros (.docx ou .dotx).'));
        }
        if (!in_array($type, self::CT_MAINS, true)) {
            throw new OfficeTemplateException(t('Ce fichier n’est pas un document Word (.docx ou .dotx).'));
        }

        return $main;
    }

    /**
     * Index des styles : par identifiant (nom interne en minuscules, type,
     * élément) et par type puis nom.
     *
     * @return array{byId: array<string, array{name: string, type: string, element: DOMElement}>, byName: array<string, array<string, string>>}
     */
    private static function styleIndex(DOMDocument $styles): array
    {
        $byId = [];
        $byName = [];
        foreach ($styles->getElementsByTagNameNS(self::W, 'style') as $style) {
            $id   = (string)$style->getAttributeNS(self::W, 'styleId');
            $type = (string)$style->getAttributeNS(self::W, 'type') ?: 'paragraph';
            $nameEl = null;
            foreach ($style->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'name') {
                    $nameEl = $child;
                    break;
                }
            }
            $name = $nameEl !== null ? mb_strtolower(trim((string)$nameEl->getAttributeNS(self::W, 'val'))) : mb_strtolower($id);
            if ($id === '') {
                continue;
            }
            $byId[$id] = ['name' => $name, 'type' => $type, 'element' => $style];
            $byName[$type][$name] ??= $id;
        }

        return ['byId' => $byId, 'byName' => $byName];
    }

    /** Polices et couleurs d'un thème Office. */
    private static function theme(DOMDocument $doc): array
    {
        $xp = OfficePackage::xpath($doc);
        $font = static function (string $kind) use ($xp): string {
            $node = $xp->query('//a:fontScheme/a:' . $kind . '/a:latin')->item(0);

            return $node instanceof DOMElement ? trim((string)$node->getAttribute('typeface')) : '';
        };
        $colors = [];
        foreach (['dk1', 'lt1', 'dk2', 'lt2', 'accent1', 'accent2', 'accent3', 'accent4'] as $name) {
            $node = $xp->query('//a:clrScheme/a:' . $name . '/*')->item(0);
            if (!$node instanceof DOMElement) {
                continue;
            }
            $hex = $node->localName === 'sysClr' ? $node->getAttribute('lastClr') : $node->getAttribute('val');
            if (preg_match('/^[0-9a-f]{6}$/i', $hex) === 1) {
                $colors[$name] = '#' . strtolower($hex);
            }
        }

        return ['fonts' => ['major' => $font('majorFont'), 'minor' => $font('minorFont')], 'colors' => $colors];
    }

    /** Cible de la première relation dont le type finit par `$suffix`. */
    private static function relTarget(array $rels, string $suffix): ?string
    {
        foreach ($rels as $rel) {
            if (!$rel['external'] && str_ends_with($rel['type'], $suffix)) {
                return $rel['target'];
            }
        }

        return null;
    }

    private function addRel(string $type, string $target, bool $external = false, ?string $fullType = null): string
    {
        do {
            $id = 'rIdMia' . (++$this->relSeq);
        } while (isset($this->relIndex[$id]));
        $rel = $this->rels->createElementNS(OfficePackage::NS_REL, 'Relationship');
        $rel->setAttribute('Id', $id);
        $rel->setAttribute('Type', $fullType ?? OfficePackage::REL . $type);
        $rel->setAttribute('Target', $target);
        if ($external) {
            $rel->setAttribute('TargetMode', 'External');
        }
        $this->rels->documentElement->appendChild($rel);
        $this->relIndex[$id] = ['type' => $fullType ?? OfficePackage::REL . $type, 'target' => $target, 'external' => $external, 'raw' => $target];

        return $id;
    }

    /** Nom de partie libre : `word/footer-mia.xml`, sinon `word/footer-mia2.xml`… */
    private function freePart(string $wanted): string
    {
        $dot  = strrpos($wanted, '.');
        $stem = $dot === false ? $wanted : substr($wanted, 0, $dot);
        $ext  = $dot === false ? '' : substr($wanted, $dot);
        $part = $wanted;
        for ($n = 2; $this->zip->locateName($part) !== false; $n++) {
            $part = $stem . $n . $ext;
        }

        return $part;
    }

    private function deletePart(string $part): void
    {
        $this->zip->deleteName($part);
        $this->zip->deleteName(OfficePackage::relsPath($part));
        foreach (iterator_to_array(OfficePackage::xpath($this->ct)->query('/ct:Types/ct:Override[@PartName="/' . $part . '"]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function override(string $part, string $type): void
    {
        $xp = OfficePackage::xpath($this->ct);
        foreach ($xp->query('/ct:Types/ct:Override[@PartName="/' . $part . '"]') as $node) {
            $node->setAttribute('ContentType', $type);

            return;
        }
        $node = $this->ct->createElementNS(OfficePackage::NS_CT, 'Override');
        $node->setAttribute('PartName', '/' . $part);
        $node->setAttribute('ContentType', $type);
        $this->ct->documentElement->appendChild($node);
    }

    private function defaultType(string $ext, string $type): void
    {
        $xp = OfficePackage::xpath($this->ct);
        foreach ($xp->query('/ct:Types/ct:Default') as $node) {
            if (strtolower((string)$node->getAttribute('Extension')) === $ext) {
                return;
            }
        }
        $node = $this->ct->createElementNS(OfficePackage::NS_CT, 'Default');
        $node->setAttribute('Extension', $ext);
        $node->setAttribute('ContentType', $type);
        // Les `Default` précèdent les `Override` : certains lecteurs y tiennent.
        $first = $xp->query('/ct:Types/ct:Override')->item(0);
        $first !== null ? $this->ct->documentElement->insertBefore($node, $first) : $this->ct->documentElement->appendChild($node);
    }

    private static function emptyRels(): DOMDocument
    {
        $doc = new DOMDocument();
        $doc->loadXML('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . OfficePackage::NS_REL . '"/>');

        return $doc;
    }
}
