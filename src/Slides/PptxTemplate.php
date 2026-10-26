<?php

declare(strict_types=1);

/**
 * Modèle PowerPoint de l'entreprise : ANALYSE et REMPLISSAGE.
 *
 * LE PROBLÈME RÉSOLU. Une présentation fabriquée par MIA suivait SA mise en
 * page (LayoutEngine) et SES thèmes : propre, mais jamais à la charte du
 * client. Une entreprise a un modèle officiel (.pptx ou .potx) — masques,
 * mises en page, polices, couleurs, logo, pied de page. L'administrateur le
 * dépose dans Organisation → Entreprise ; les présentations livrées dans la
 * conversation sont alors construites DANS ce modèle.
 *
 * COMMENT. Aucune bibliothèque ne relit fidèlement un masque PowerPoint
 * importé (PHPPresentation perd une partie des mises en page) : on travaille
 * donc DIRECTEMENT sur le paquet OOXML, comme PowerPoint lui-même. On garde
 * TOUT le modèle (masques, mises en page, thème, médias), on retire ses slides
 * d'exemple, puis on ajoute une slide par entrée du plan (MarkdownDeck), qui
 * pointe vers la mise en page du modèle la plus proche :
 *
 *   couverture  → « Diapositive de titre »  (type `title`)
 *   contenu     → « Titre et contenu »      (type `obj`)
 *   intercalaire→ « Titre de section »      (type `secHead`)
 *   deux colonnes → « Deux contenus »       (type `twoObj`)
 *   comparaison → « Comparaison »           (type `twoTxTwoObj`)
 *   chiffres, tableau, étapes, chronologie → « Titre seul » (`titleOnly`)
 *                 + formes dessinées dans la zone de contenu du modèle
 *
 * Les textes vont dans les ESPACES RÉSERVÉS du modèle : position, police,
 * taille et puces viennent du masque. Les formes ajoutées (chiffres, tableau,
 * repères) utilisent les couleurs du THÈME (`accent1`, `tx1`…), jamais une
 * couleur figée : elles suivent la charte. Mise en page introuvable (modèle
 * atypique) → la plus proche, puis une zone de texte à la place de l'espace
 * réservé manquant ; jamais une slide perdue.
 *
 * Garde-fous du dépôt : archive ZIP valide, présentation (pas de macros),
 * taille et nombre d'entrées bornés (bombe ZIP), chemins confinés au paquet.
 */
final class PptxTemplate
{
    private const NS_P   = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NS_CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const NS_DC  = 'http://purl.org/dc/elements/1.1/';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const CT_PREFIX  = 'application/vnd.openxmlformats-officedocument.presentationml.';
    private const CT_MAIN    = self::CT_PREFIX . 'presentation.main+xml';
    private const CT_MAINS   = [
        self::CT_PREFIX . 'presentation.main+xml',
        self::CT_PREFIX . 'template.main+xml',
        self::CT_PREFIX . 'slideshow.main+xml',
    ];
    private const CT_SLIDE        = self::CT_PREFIX . 'slide+xml';
    private const CT_NOTES_SLIDE  = self::CT_PREFIX . 'notesSlide+xml';
    private const CT_NOTES_MASTER = self::CT_PREFIX . 'notesMaster+xml';
    private const CT_THEME        = 'application/vnd.openxmlformats-officedocument.theme+xml';

    /** Bombe ZIP : au-delà, ce n'est pas un modèle de présentation. */
    public const MAX_ENTRIES      = 4000;
    public const MAX_UNCOMPRESSED = 300_000_000;

    /** Rôles des mises en page, dans l'ordre de l'écran d'administration. */
    public const ROLES = ['cover', 'content', 'section', 'two', 'comparison', 'titleOnly'];

    /** Libellés des rôles (clés de traduction : l'écran d'administration les affiche). */
    public const ROLE_LABELS = [
        'cover'      => 'Couverture',
        'content'    => 'Contenu',
        'section'    => 'Intercalaire',
        'two'        => 'Deux colonnes',
        'comparison' => 'Comparaison',
        'titleOnly'  => 'Titre seul (chiffres, tableau, étapes)',
    ];

    /** Types OOXML d'une mise en page attendue pour chaque rôle, par préférence. */
    private const ROLE_TYPES = [
        'cover'      => ['title'],
        'content'    => ['obj', 'tx'],
        'section'    => ['secHead'],
        'two'        => ['twoObj', 'twoTxTwoObj'],
        'comparison' => ['twoTxTwoObj', 'twoObj'],
        'titleOnly'  => ['titleOnly'],
    ];

    /** Espaces réservés qui ne portent pas de contenu (pied de page, images…). */
    private const NOT_BODY = ['title', 'ctrTitle', 'subTitle', 'dt', 'ftr', 'sldNum', 'hdr', 'pic', 'chart', 'tbl',
        'clipArt', 'dgm', 'media', 'sldImg'];

    /** Largeur de référence (16:9, EMU) : les tailles de texte dessinées s'y rapportent. */
    private const REF_WIDTH = 12192000;

    /**
     * Identifiant du champ « numéro de slide » : un GUID au format attendu par
     * PowerPoint, le même partout (il désigne le TYPE de champ, pas la slide).
     */
    private const SLIDE_NUMBER_FIELD = '{8A6B7D0E-3B2C-4E1F-9A7D-5C4B3A2F1E0D}';

    // ==================================================================
    // Analyse
    // ==================================================================

    /**
     * Compte rendu d'un modèle, pour l'écran d'administration et le refus
     * d'un fichier inexploitable.
     *
     * @return array{ok: bool, error?: string, size?: array{cx: int, cy: int}, ratio?: string, slides?: int,
     *               layouts?: int, roles?: array<string, array{name: string, fallback: bool}>,
     *               colors?: array<string, string>, palette?: array<string, string>,
     *               fonts?: array{major: string, minor: string}, notes?: bool}
     */
    public static function analyze(string $path): array
    {
        try {
            $zip = self::open($path);
        } catch (PptxTemplateException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $pkg = self::package($zip);
            $master = $pkg['masters'][0];

            $roles = [];
            foreach (self::ROLES as $role) {
                $chosen = $pkg['roles'][$role];
                $roles[$role] = ['name' => (string)$chosen['layout']['name'], 'fallback' => $chosen['fallback']];
            }

            return [
                'ok'      => true,
                'size'    => $pkg['size'],
                'ratio'   => self::ratio($pkg['size']['cx'], $pkg['size']['cy']),
                'slides'  => count($pkg['slides']),
                'layouts' => count($master['layouts']),
                'roles'   => $roles,
                'colors'  => $master['theme']['colors'],
                'palette' => self::palette($master),
                'fonts'   => $master['theme']['fonts'],
                'notes'   => $pkg['notesMaster'] !== null,
            ];
        } catch (PptxTemplateException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[PptxTemplate] Analyse impossible : ' . get_class($e) . ' — ' . $e->getMessage());

            return ['ok' => false, 'error' => t('Ce fichier n’est pas un modèle PowerPoint lisible.')];
        } finally {
            $zip->close();
        }
    }

    /**
     * Couleurs d'une présentation de secours (PDF de slides) à la charte du
     * modèle : fond, texte, accent, texte secondaire — au format d'un thème
     * DeckSpec.
     *
     * @return array<string, string>
     */
    public static function themeColors(string $path): array
    {
        $info = self::analyze($path);

        return $info['ok'] ? (array)($info['palette'] ?? []) : [];
    }

    // ==================================================================
    // Remplissage
    // ==================================================================

    /**
     * Octets d'une présentation .pptx : le modèle, ses slides d'exemple
     * retirées, une slide par entrée du plan.
     *
     * @param list<array<string, mixed>> $slides Plan de MarkdownDeck::outline().
     * @param array{title?: string, lang?: string, footer?: string} $options
     *
     * @throws PptxTemplateException Modèle inexploitable.
     */
    public static function fill(string $templatePath, array $slides, array $options = []): string
    {
        $work = FileScratch::path('modele', 'pptx');
        if (!@copy($templatePath, $work)) {
            throw new PptxTemplateException(t('Le modèle PowerPoint de l’entreprise est introuvable.'));
        }

        try {
            $zip = self::open($work);
            try {
                (new self($zip, $options))->build($slides);
            } finally {
                $zip->close();
            }

            $bytes = (string)file_get_contents($work);
            if ($bytes === '') {
                throw new PptxTemplateException(t('La présentation n’a pas pu être écrite.'));
            }

            return $bytes;
        } finally {
            FileScratch::discard($work);
        }
    }

    /**
     * Octets du modèle ENTIER, slides d'exemple comprises, en présentation :
     * la RÉFÉRENCE envoyée au modèle de langage qui réalise lui-même une
     * présentation (NativeDeck). Les slides d'exemple sont ce qui montre le
     * style maison — placement des titres, cartes, pied de page, logo — et la
     * compétence « pptx » d'Anthropic construit justement une présentation à
     * partir des slides d'un modèle. Seul le type principal change : un .potx
     * devient une présentation, que python-pptx sait ouvrir.
     *
     * @throws PptxTemplateException Modèle inexploitable.
     */
    public static function reference(string $templatePath): string
    {
        $work = FileScratch::path('modele-reference', 'pptx');
        if (!@copy($templatePath, $work)) {
            throw new PptxTemplateException(t('Le modèle PowerPoint de l’entreprise est introuvable.'));
        }

        try {
            $zip = self::open($work);
            try {
                $self           = new self($zip, []);
                $self->pkg      = self::package($zip);
                $self->presPart = $self->pkg['main'];
                $self->ct       = self::xml($zip, '[Content_Types].xml');
                $self->mainContentType();
                $zip->addFromString('[Content_Types].xml', (string)$self->ct->saveXML());
            } finally {
                $zip->close();
            }

            $bytes = (string)file_get_contents($work);
            if ($bytes === '') {
                throw new PptxTemplateException(t('La présentation n’a pas pu être écrite.'));
            }

            return $bytes;
        } finally {
            FileScratch::discard($work);
        }
    }

    // ------------------------------------------------------------------
    // État d'un remplissage
    // ------------------------------------------------------------------

    private ZipArchive $zip;
    private string $lang;
    private string $footer;
    private string $title;
    /** @var array<string, mixed> */
    private array $pkg;
    private DOMDocument $ct;
    private DOMDocument $pres;
    private DOMDocument $presRels;
    private string $presPart;
    private int $shapeId = 1;

    private function __construct(ZipArchive $zip, array $options)
    {
        $this->zip    = $zip;
        $this->lang   = preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string)($options['lang'] ?? '')) === 1 ? (string)$options['lang'] : 'fr-FR';
        $this->footer = self::clean((string)($options['footer'] ?? ''));
        $this->title  = self::clean((string)($options['title'] ?? ''));
    }

    /** @param list<array<string, mixed>> $slides */
    private function build(array $slides): void
    {
        $this->pkg      = self::package($this->zip);
        $this->presPart = $this->pkg['main'];
        $this->ct       = self::xml($this->zip, '[Content_Types].xml');
        $this->pres     = self::xml($this->zip, $this->presPart);
        $this->presRels = self::xml($this->zip, self::relsPath($this->presPart));

        $this->removeExampleSlides();
        $this->mainContentType();

        $notesMaster = null;
        foreach ($slides as $slide) {
            if (trim((string)($slide['notes'] ?? '')) !== '') {
                $notesMaster = $this->notesMaster();
                break;
            }
        }

        $total = count($slides);
        $ids   = [];
        foreach (array_values($slides) as $i => $slide) {
            $n      = $i + 1;
            $part   = 'ppt/slides/slide' . $n . '.xml';
            [$xml, $layoutPart] = $this->slideXml($slide, $n, $total);
            $rels = [['type' => 'slideLayout', 'target' => self::relative($part, $layoutPart)]];

            $notes = trim((string)($slide['notes'] ?? ''));
            if ($notes !== '' && $notesMaster !== null) {
                $notesPart = 'ppt/notesSlides/notesSlide' . $n . '.xml';
                $this->zip->addFromString($notesPart, $this->notesXml($notes));
                $this->zip->addFromString(self::relsPath($notesPart), self::relsXml([
                    ['type' => 'notesMaster', 'target' => self::relative($notesPart, $notesMaster)],
                    ['type' => 'slide', 'target' => self::relative($notesPart, $part)],
                ]));
                $this->override($notesPart, self::CT_NOTES_SLIDE);
                $rels[] = ['type' => 'notesSlide', 'target' => self::relative($part, $notesPart)];
            }

            $this->zip->addFromString($part, $xml);
            $this->zip->addFromString(self::relsPath($part), self::relsXml($rels));
            $this->override($part, self::CT_SLIDE);
            $ids[] = $this->addPresRel('slide', self::relative($this->presPart, $part));
        }

        $this->slideIdList($ids);
        $this->documentProperties($total);

        $this->zip->addFromString('[Content_Types].xml', (string)$this->ct->saveXML());
        $this->zip->addFromString($this->presPart, (string)$this->pres->saveXML());
        $this->zip->addFromString(self::relsPath($this->presPart), (string)$this->presRels->saveXML());
    }

    /**
     * Retire les slides d'exemple du modèle, avec leurs notes et commentaires,
     * et tout ce qui les cite encore : sections (une section qui pointe vers
     * une slide disparue fait « réparer » le fichier par PowerPoint),
     * diaporamas personnalisés. Les médias restent : un masque peut les
     * partager.
     */
    private function removeExampleSlides(): void
    {
        $xp = self::xpath($this->pres);
        foreach ($xp->query('/p:presentation/p:sldIdLst') as $list) {
            $list->parentNode->removeChild($list);
        }
        foreach ($xp->query('/p:presentation/p:custShowLst') as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xp->query('/p:presentation/p:extLst/p:ext') as $ext) {
            // Liste des sections (PowerPoint 2010+) : elle cite les slides par identifiant.
            if (strtoupper((string)$ext->getAttribute('uri')) === '{521415D9-36F7-43E2-AB2F-B90AF26B5E84}') {
                $ext->parentNode->removeChild($ext);
            }
        }

        $rx = self::xpath($this->presRels);
        foreach ($this->pkg['slides'] as $slide) {
            foreach ($slide['children'] as $child) {
                $this->deletePart($child);
            }
            $this->deletePart($slide['part']);
            foreach ($rx->query('/rel:Relationships/rel:Relationship[@Id="' . $slide['rid'] . '"]') as $rel) {
                $rel->parentNode->removeChild($rel);
            }
        }
    }

    private function deletePart(string $part): void
    {
        $this->zip->deleteName($part);
        $this->zip->deleteName(self::relsPath($part));
        $xp = self::xpath($this->ct);
        foreach ($xp->query('/ct:Types/ct:Override[@PartName="/' . $part . '"]') as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    /** Un modèle (.potx) ou un diaporama (.ppsx) devient une présentation. */
    private function mainContentType(): void
    {
        $this->override($this->presPart, self::CT_MAIN);
    }

    private function override(string $part, string $type): void
    {
        $xp   = self::xpath($this->ct);
        $node = $xp->query('/ct:Types/ct:Override[@PartName="/' . $part . '"]')->item(0);
        if (!$node instanceof DOMElement) {
            $node = $this->ct->createElementNS(self::NS_CT, 'Override');
            $node->setAttribute('PartName', '/' . $part);
            $this->ct->documentElement->appendChild($node);
        }
        $node->setAttribute('ContentType', $type);
    }

    /** Relation de presentation.xml vers une nouvelle partie ; rend son rId. */
    private function addPresRel(string $type, string $target): string
    {
        $used = [];
        foreach (self::xpath($this->presRels)->query('/rel:Relationships/rel:Relationship') as $rel) {
            $used[(string)$rel->getAttribute('Id')] = true;
        }
        $n = count($used) + 1;
        while (isset($used['rId' . $n])) {
            $n++;
        }
        $rel = $this->presRels->createElementNS(self::NS_REL, 'Relationship');
        $rel->setAttribute('Id', 'rId' . $n);
        $rel->setAttribute('Type', self::REL . $type);
        $rel->setAttribute('Target', $target);
        $this->presRels->documentElement->appendChild($rel);

        return 'rId' . $n;
    }

    /**
     * `p:sldIdLst`, à sa place dans `p:presentation` : après les listes de
     * masques, avant la taille des slides (l'ordre du schéma est imposé).
     *
     * @param list<string> $rids
     */
    private function slideIdList(array $rids): void
    {
        $list = $this->pres->createElementNS(self::NS_P, 'p:sldIdLst');
        foreach ($rids as $k => $rid) {
            $id = $this->pres->createElementNS(self::NS_P, 'p:sldId');
            $id->setAttribute('id', (string)(256 + $k));
            $id->setAttributeNS(self::NS_R, 'r:id', $rid);
            $list->appendChild($id);
        }

        $root   = $this->pres->documentElement;
        $before = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && !in_array($child->localName, ['sldMasterIdLst', 'notesMasterIdLst', 'handoutMasterIdLst'], true)) {
                $before = $child;
                break;
            }
        }
        $before === null ? $root->appendChild($list) : $root->insertBefore($list, $before);
    }

    /**
     * Masque des notes : celui du modèle, sinon un masque minimal créé à la
     * volée (beaucoup de modèles n'en ont pas, et PowerPoint refuse une page
     * de notes sans masque). Rend son chemin dans le paquet.
     */
    private function notesMaster(): string
    {
        if ($this->pkg['notesMaster'] !== null) {
            return $this->pkg['notesMaster'];
        }

        // Thème du masque des notes : une copie de celui des slides.
        $n = 1;
        while ($this->zip->locateName('ppt/theme/theme' . $n . '.xml') !== false) {
            $n++;
        }
        $theme = 'ppt/theme/theme' . $n . '.xml';
        $this->zip->addFromString($theme, (string)$this->zip->getFromName($this->pkg['masters'][0]['themePart']));
        $this->override($theme, self::CT_THEME);

        $part = 'ppt/notesMasters/notesMaster1.xml';
        $this->zip->addFromString($part, self::notesMasterXml());
        $this->zip->addFromString(self::relsPath($part), self::relsXml([
            ['type' => 'theme', 'target' => self::relative($part, $theme)],
        ]));
        $this->override($part, self::CT_NOTES_MASTER);
        $rid = $this->addPresRel('notesMaster', self::relative($this->presPart, $part));

        $list = $this->pres->createElementNS(self::NS_P, 'p:notesMasterIdLst');
        $id   = $this->pres->createElementNS(self::NS_P, 'p:notesMasterId');
        $id->setAttributeNS(self::NS_R, 'r:id', $rid);
        $list->appendChild($id);
        $masters = self::xpath($this->pres)->query('/p:presentation/p:sldMasterIdLst')->item(0);
        $masters !== null && $masters->nextSibling !== null
            ? $masters->parentNode->insertBefore($list, $masters->nextSibling)
            : $this->pres->documentElement->appendChild($list);

        $this->pkg['notesMaster'] = $part;

        return $part;
    }

    /** Titre du document et nombre de slides dans les propriétés du fichier. */
    private function documentProperties(int $total): void
    {
        $core = 'docProps/core.xml';
        if ($this->title !== '' && $this->zip->locateName($core) !== false) {
            try {
                $doc   = self::xml($this->zip, $core);
                $title = $doc->getElementsByTagNameNS(self::NS_DC, 'title')->item(0);
                if ($title === null) {
                    $title = $doc->createElementNS(self::NS_DC, 'dc:title');
                    $doc->documentElement->appendChild($title);
                }
                $title->textContent = $this->title;
                $this->zip->addFromString($core, (string)$doc->saveXML());
            } catch (Throwable) {
                // Propriété accessoire : jamais un échec de fabrication.
            }
        }

        $app = 'docProps/app.xml';
        if ($this->zip->locateName($app) !== false) {
            $xml = (string)$this->zip->getFromName($app);
            $xml = (string)preg_replace('#<Slides>\d+</Slides>#', '<Slides>' . $total . '</Slides>', $xml);
            $this->zip->addFromString($app, $xml);
        }
    }

    // ------------------------------------------------------------------
    // Slides
    // ------------------------------------------------------------------

    /**
     * XML d'une slide et chemin de sa mise en page.
     *
     * @return array{0: string, 1: string}
     */
    private function slideXml(array $slide, int $n, int $total): array
    {
        $this->shapeId = 1;
        $layout = (string)($slide['layout'] ?? '');
        $title  = self::clean((string)($slide['title'] ?? ''));
        $points = array_values(array_filter(array_map(
            static fn ($p): string => self::clean((string)$p),
            (array)($slide['points'] ?? [])
        ), static fn (string $p): bool => $p !== ''));

        // Titre seul (ni point ni donnée) : intercalaire, couverture en tête.
        if ($layout === '' || ($points === [] && in_array($layout, ['titre-contenu', 'conclusion', 'deux-colonnes'], true))) {
            $layout = $n === 1 ? 'titre' : 'section';
        }

        [$role, $shapes] = match ($layout) {
            'titre'         => ['cover', fn (array $l): string => $this->cover($l, $title, $points)],
            'section'       => ['section', fn (array $l): string => $this->section($l, $title)],
            'deux-colonnes' => ['two', fn (array $l): string => $this->twoColumns($l, $title, $points)],
            'comparaison'   => ['comparison', fn (array $l): string => $this->comparison($l, $title, (array)($slide['columns'] ?? []))],
            'citation'      => ['content', fn (array $l): string => $this->quote($l, $title, $points)],
            'chiffres'      => ['titleOnly', fn (array $l): string => $this->drawn($l, $title, $this->kpis((array)($slide['kpis'] ?? []), $l))],
            'tableau'       => ['titleOnly', fn (array $l): string => $this->drawn($l, $title, $this->table((array)($slide['table'] ?? []), (string)($slide['intro'] ?? ''), $l))],
            'etapes'        => ['titleOnly', fn (array $l): string => $this->drawn($l, $title, $this->steps((array)($slide['steps'] ?? []), $l))],
            'chronologie'   => ['titleOnly', fn (array $l): string => $this->drawn($l, $title, $this->timeline((array)($slide['events'] ?? []), $l))],
            default         => ['content', fn (array $l): string => $this->content($l, $title, $points)],
        };

        $layoutInfo = $this->pkg['roles'][$role]['layout'];
        $body       = $shapes($layoutInfo);
        if ($role !== 'cover') {
            $body .= $this->footerShapes($layoutInfo, $n);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $body
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';

        return [$xml, (string)$layoutInfo['part']];
    }

    private function cover(array $layout, string $title, array $points): string
    {
        $out      = $this->titleShape($layout, $title);
        $subtitle = $points[0] ?? '';
        if ($subtitle !== '') {
            $ph = self::placeholder($layout, 'subTitle') ?? self::bodies($layout)[0] ?? null;
            $out .= $ph !== null
                ? $this->placeholderShape($ph, [$this->para($subtitle)])
                : $this->textBox(self::area($layout, $this->pkg), [$this->para($subtitle)]);
        }

        return $out;
    }

    private function section(array $layout, string $title): string
    {
        return $this->titleShape($layout, $title);
    }

    private function content(array $layout, string $title, array $points): string
    {
        $paras = array_map(fn (string $p): string => $this->para($p), $points);
        $ph    = self::bodies($layout)[0] ?? null;

        return $this->titleShape($layout, $title)
            . ($ph !== null ? $this->placeholderShape($ph, $paras) : $this->textBox(self::area($layout, $this->pkg), $this->bulleted($points)));
    }

    private function twoColumns(array $layout, string $title, array $points): string
    {
        $bodies = self::bodies($layout);
        if (count($bodies) < 2) {
            return $this->content($layout, $title, $points);
        }
        $half = (int)ceil(count($points) / 2);
        $cols = [array_slice($points, 0, $half), array_slice($points, $half)];

        $out = $this->titleShape($layout, $title);
        foreach ([0, 1] as $k) {
            $out .= $this->placeholderShape($bodies[$k], array_map(fn (string $p): string => $this->para($p), $cols[$k]));
        }

        return $out;
    }

    /**
     * Comparaison : sur la mise en page « Comparaison » du modèle, un titre de
     * colonne dans chaque petit espace réservé ; sur « Deux contenus », le
     * titre de colonne en tête de liste, en gras et sans puce.
     *
     * @param list<array{title?: string, points?: list<string>}> $columns
     */
    private function comparison(array $layout, string $title, array $columns): string
    {
        $columns = array_values(array_slice($columns, 0, 2));
        $bodies  = self::bodies($layout);
        $out     = $this->titleShape($layout, $title);

        // Deux colonnes de deux espaces réservés : en-tête (en haut), contenu (dessous).
        $byCol = self::columns($bodies);
        if (count($byCol) === 2 && count($byCol[0]) >= 2 && count($byCol[1]) >= 2) {
            foreach ([0, 1] as $k) {
                $col  = $columns[$k] ?? [];
                $out .= $this->placeholderShape($byCol[$k][0], [$this->para(self::clean((string)($col['title'] ?? '')), plain: true)]);
                $out .= $this->placeholderShape($byCol[$k][1], array_map(
                    fn ($p): string => $this->para(self::clean((string)$p)),
                    (array)($col['points'] ?? [])
                ));
            }

            return $out;
        }

        if (count($bodies) >= 2) {
            foreach ([0, 1] as $k) {
                $col   = $columns[$k] ?? [];
                $paras = [$this->para(self::clean((string)($col['title'] ?? '')), plain: true, bold: true)];
                foreach ((array)($col['points'] ?? []) as $p) {
                    $paras[] = $this->para(self::clean((string)$p));
                }
                $out .= $this->placeholderShape($bodies[$k], $paras);
            }

            return $out;
        }

        $flat = [];
        foreach ($columns as $col) {
            $flat[] = self::clean((string)($col['title'] ?? ''));
            foreach ((array)($col['points'] ?? []) as $p) {
                $flat[] = self::clean((string)$p);
            }
        }

        return $this->content($layout, $title, array_values(array_filter($flat)));
    }

    /** Citation : le texte en grand et en italique, sans puce ; sa légende alignée à droite. */
    private function quote(array $layout, string $quote, array $points): string
    {
        $scale   = $this->scale();
        $paras   = [$this->para('« ' . $quote . ' »', plain: true, italic: true, size: (int)round(2800 * $scale))];
        $caption = $points[0] ?? '';
        if ($caption !== '') {
            $paras[] = $this->para('— ' . $caption, plain: true, size: (int)round(1600 * $scale), align: 'r');
        }
        $ph = self::bodies($layout)[0] ?? null;

        return $ph !== null ? $this->placeholderShape($ph, $paras) : $this->textBox(self::area($layout, $this->pkg), $paras);
    }

    /** Titre (espace réservé du modèle) suivi de formes dessinées. */
    private function drawn(array $layout, string $title, string $shapes): string
    {
        return $this->titleShape($layout, $title) . $shapes;
    }

    /**
     * Chiffres clés : la valeur en grand, couleur d'accent et police des
     * titres du thème ; la légende dessous.
     *
     * @param list<array{value?: string, label?: string}> $kpis
     */
    private function kpis(array $kpis, array $layout): string
    {
        $kpis = array_values(array_slice($kpis, 0, 6));
        $n    = count($kpis);
        if ($n === 0) {
            return '';
        }
        [$x, $y, $w, $h] = self::area(self::contentLayout($this->pkg), $this->pkg);
        $cols  = $n <= 4 ? $n : 3;
        $rows  = (int)ceil($n / $cols);
        $gap   = (int)round($w * 0.03);
        $cellW = (int)(($w - $gap * ($cols - 1)) / $cols);
        $cellH = (int)($h / $rows);
        $scale = $this->scale();

        $out = '';
        foreach ($kpis as $k => $kpi) {
            $cx = $x + ($k % $cols) * ($cellW + $gap);
            $cy = $y + intdiv($k, $cols) * $cellH + ($rows === 1 ? (int)($h * 0.12) : 0);
            $bar = (int)min($cellW * 0.3, $w * 0.06);
            $out .= $this->rect([$cx + intdiv($cellW - $bar, 2), $cy - (int)($h * 0.04), $bar, (int)max(12700, $h * 0.012)], 'accent1');
            $out .= $this->textBox([$cx, $cy, $cellW, (int)($cellH * ($rows === 1 ? 0.75 : 0.95))], [
                $this->para($value = self::clean((string)($kpi['value'] ?? '')), plain: true, bold: true,
                    size: self::fit((int)round(($rows === 1 ? 5400 : 4000) * $scale), $value, $cellW), align: 'ctr', color: $this->emphasis(), font: '+mj-lt'),
                $this->para(self::clean((string)($kpi['label'] ?? '')), plain: true,
                    size: (int)round(1600 * $scale), align: 'ctr'),
            ]);
        }

        return $out;
    }

    /**
     * Un vrai tableau PowerPoint, aux couleurs du thème : en-tête sur
     * `accent1`, lignes alternées teintées, filets blancs.
     *
     * @param array{header?: bool, rows?: list<list<string>>} $table
     */
    private function table(array $table, string $intro, array $layout): string
    {
        $rows = array_values(array_filter((array)($table['rows'] ?? []), 'is_array'));
        if ($rows === []) {
            return '';
        }
        [$x, $y, $w, $h] = self::area(self::contentLayout($this->pkg), $this->pkg);
        $scale = $this->scale();
        $out   = '';

        $intro = self::clean($intro);
        if ($intro !== '') {
            $introH = (int)round($h * 0.12);
            $out   .= $this->textBox([$x, $y, $w, $introH], [$this->para($intro, plain: true, size: (int)round(1600 * $scale))]);
            $y     += $introH;
            $h     -= $introH;
        }

        $cols   = max(1, max(array_map('count', $rows)));
        $header = ($table['header'] ?? true) !== false;
        $rowH   = (int)min($h / count($rows), 640000 * $scale);
        $colW   = intdiv($w, $cols);
        $size   = (int)round((count($rows) > 8 ? 1200 : 1400) * $scale);

        $grid = '';
        for ($c = 0; $c < $cols; $c++) {
            $grid .= '<a:gridCol w="' . ($c === $cols - 1 ? $w - $colW * ($cols - 1) : $colW) . '"/>';
        }
        // Cellules TEINTÉES (claires) : texte foncé `dk1`, quel que soit le
        // fond de la slide ; filets clairs `lt1`. En-tête sur l'accent, texte
        // clair ou foncé selon l'accent.
        $line = static fn (string $side): string => '<a:' . $side . ' w="12700" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="lt1"/></a:solidFill><a:prstDash val="solid"/></a:' . $side . '>';
        $onAccent = $this->pkg['masters'][0]['contrast']['onAccent'];
        $trs  = '';
        foreach ($rows as $r => $cells) {
            $isHead = $header && $r === 0;
            $band   = !$isHead && (($r - ($header ? 1 : 0)) % 2 === 0);
            $fill   = $isHead
                ? '<a:solidFill><a:schemeClr val="accent1"/></a:solidFill>'
                : '<a:solidFill><a:schemeClr val="accent1"><a:lumMod val="' . ($band ? '20000' : '10000') . '"/><a:lumOff val="' . ($band ? '80000' : '90000') . '"/></a:schemeClr></a:solidFill>';
            $trs .= '<a:tr h="' . $rowH . '">';
            for ($c = 0; $c < $cols; $c++) {
                $text = self::clean((string)($cells[$c] ?? ''));
                $run  = $text === ''
                    ? '<a:endParaRPr lang="' . $this->lang . '" sz="' . $size . '" dirty="0"/>'
                    : '<a:r><a:rPr lang="' . $this->lang . '" sz="' . $size . '"' . ($isHead ? ' b="1"' : '') . ' dirty="0">'
                        . '<a:solidFill><a:schemeClr val="' . ($isHead ? $onAccent : 'dk1') . '"/></a:solidFill></a:rPr>'
                        . '<a:t>' . self::esc($text) . '</a:t></a:r>';
                $trs .= '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p>' . $run . '</a:p></a:txBody>'
                    . '<a:tcPr marL="91440" marR="91440" marT="45720" marB="45720" anchor="ctr">'
                    . $line('lnL') . $line('lnR') . $line('lnT') . $line('lnB') . $fill . '</a:tcPr></a:tc>';
            }
            $trs .= '</a:tr>';
        }

        $id = $this->nextId();

        return $out . '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Tableau ' . $id . '"/>'
            . '<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            . '<p:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . ($rowH * count($rows)) . '"/></p:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
            . '<a:tbl><a:tblPr firstRow="' . ($header ? '1' : '0') . '" bandRow="1"/><a:tblGrid>' . $grid . '</a:tblGrid>'
            . $trs . '</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /**
     * Étapes : numéro en couleur d'accent, trait, texte — de gauche à droite.
     *
     * @param list<string> $steps
     */
    private function steps(array $steps, array $layout): string
    {
        $steps = array_values(array_slice($steps, 0, 6));
        $n     = count($steps);
        if ($n === 0) {
            return '';
        }
        [$x, $y, $w, $h] = self::area(self::contentLayout($this->pkg), $this->pkg);
        $gap   = (int)round($w * 0.025);
        $cellW = (int)(($w - $gap * ($n - 1)) / $n);
        $scale = $this->scale();

        $out = '';
        foreach ($steps as $k => $step) {
            $cx   = $x + $k * ($cellW + $gap);
            $out .= $this->textBox([$cx, $y, $cellW, (int)($h * 0.22)], [
                $this->para(str_pad((string)($k + 1), 2, '0', STR_PAD_LEFT), plain: true, bold: true,
                    size: (int)round(3600 * $scale), color: $this->emphasis(), font: '+mj-lt'),
            ]);
            $out .= $this->rect([$cx, $y + (int)($h * 0.25), min($cellW, (int)($w * 0.1)), (int)max(12700, $h * 0.012)], 'accent1');
            $out .= $this->textBox([$cx, $y + (int)($h * 0.31), $cellW, (int)($h * 0.65)], [
                $this->para(self::clean((string)$step), plain: true, size: (int)round(($n > 4 ? 1400 : 1600) * $scale)),
            ]);
        }

        return $out;
    }

    /**
     * Chronologie : ligne de temps, un repère par date ; la date au-dessus,
     * l'évènement dessous.
     *
     * @param list<array{date?: string, label?: string}> $events
     */
    private function timeline(array $events, array $layout): string
    {
        $events = array_values(array_slice($events, 0, 6));
        $n      = count($events);
        if ($n === 0) {
            return '';
        }
        [$x, $y, $w, $h] = self::area(self::contentLayout($this->pkg), $this->pkg);
        $cellW = intdiv($w, $n);
        $lineY = $y + (int)($h * 0.32);
        $scale = $this->scale();

        $out = $this->rect([$x, $lineY, $w, (int)max(9525, $h * 0.008)], 'tx1', 30000);
        foreach ($events as $k => $event) {
            $cx   = $x + $k * $cellW;
            $out .= $this->textBox([$cx, $y + (int)($h * 0.08), $cellW - (int)($w * 0.02), (int)($h * 0.2)], [
                $this->para($date = self::clean((string)($event['date'] ?? '')), plain: true, bold: true,
                    size: self::fit((int)round(2000 * $scale), $date, $cellW - (int)($w * 0.02)), color: $this->emphasis(), font: '+mj-lt'),
            ]);
            $mark = (int)max(91440, $h * 0.05);
            $out .= $this->rect([$cx, $lineY - intdiv($mark, 2), (int)($mark * 0.45), $mark], 'accent1');
            $out .= $this->textBox([$cx, $lineY + (int)($h * 0.07), $cellW - (int)($w * 0.02), (int)($h * 0.55)], [
                $this->para(self::clean((string)($event['label'] ?? '')), plain: true, size: (int)round(1400 * $scale)),
            ]);
        }

        return $out;
    }

    /** Pied de page : numéro de slide, et le nom de l'entreprise s'il est connu. */
    private function footerShapes(array $layout, int $n): string
    {
        $out = '';
        $num = self::placeholder($layout, 'sldNum');
        if ($num !== null) {
            $out .= $this->placeholderShape($num, [
                '<a:p><a:fld id="' . self::SLIDE_NUMBER_FIELD . '" type="slidenum"><a:rPr lang="' . $this->lang . '"/><a:t>' . $n . '</a:t></a:fld></a:p>',
            ]);
        }
        $ftr = self::placeholder($layout, 'ftr');
        if ($ftr !== null && $this->footer !== '') {
            $out .= $this->placeholderShape($ftr, [$this->para($this->footer)]);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Formes
    // ------------------------------------------------------------------

    /** Titre dans l'espace réservé du modèle, sinon une zone de texte à la place du titre du masque. */
    private function titleShape(array $layout, string $title): string
    {
        if ($title === '') {
            return '';
        }
        $ph = self::placeholder($layout, 'ctrTitle') ?? self::placeholder($layout, 'title');
        if ($ph !== null) {
            return $this->placeholderShape($ph, [$this->para($title)]);
        }

        return $this->textBox(self::titleArea($this->pkg), [
            $this->para($title, plain: true, bold: true, size: (int)round(3200 * $this->scale()), font: '+mj-lt'),
        ]);
    }

    /**
     * Forme qui REMPLIT un espace réservé du modèle : aucune géométrie, aucune
     * mise en forme — tout est hérité de la mise en page et du masque.
     *
     * @param list<string> $paragraphs `<a:p>` déjà construits
     */
    private function placeholderShape(array $ph, array $paragraphs): string
    {
        if ($paragraphs === []) {
            return '';
        }
        $id    = $this->nextId();
        $attrs = '';
        foreach (['type', 'orient', 'sz', 'idx'] as $name) {
            if (($ph['attrs'][$name] ?? '') !== '') {
                $attrs .= ' ' . $name . '="' . self::esc((string)$ph['attrs'][$name]) . '"';
            }
        }

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Espace réservé ' . $id . '"/>'
            . '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph' . $attrs . '/></p:nvPr></p:nvSpPr>'
            . '<p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>' . implode('', $paragraphs) . '</p:txBody></p:sp>';
    }

    /**
     * Zone de texte dessinée (pas un espace réservé) : police et couleurs du
     * thème par défaut, ajustement automatique du texte à la zone.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $box
     * @param list<string> $paragraphs
     */
    private function textBox(array $box, array $paragraphs): string
    {
        [$x, $y, $w, $h] = array_map('intval', $box);
        $id = $this->nextId();

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Zone de texte ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . max(1, $w) . '" cy="' . max(1, $h) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" rtlCol="0" anchor="t"><a:normAutofit/></a:bodyPr>'
            . '<a:lstStyle/>' . implode('', $paragraphs) . '</p:txBody></p:sp>';
    }

    /**
     * Rectangle plein, couleur du thème.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $box
     */
    private function rect(array $box, string $scheme, int $alpha = 0): string
    {
        [$x, $y, $w, $h] = array_map('intval', $box);
        $id   = $this->nextId();
        $fill = '<a:schemeClr val="' . $scheme . '">' . ($alpha > 0 ? '<a:alpha val="' . $alpha . '"/>' : '') . '</a:schemeClr>';

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Forme ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . max(1, $w) . '" cy="' . max(1, $h) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:solidFill>' . $fill . '</a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    /**
     * Un paragraphe. Sans option, il hérite tout (puce, taille, couleur) de
     * l'espace réservé : c'est le cas courant, celui qui respecte la charte.
     */
    private function para(
        string $text,
        bool $plain = false,
        bool $bold = false,
        bool $italic = false,
        int $size = 0,
        string $align = '',
        string $color = '',
        string $font = ''
    ): string {
        $pPr = '';
        if ($plain || $align !== '') {
            $pPr = '<a:pPr' . ($plain ? ' marL="0" indent="0"' : '') . ($align !== '' ? ' algn="' . $align . '"' : '') . '>'
                . ($plain ? '<a:buNone/>' : '') . '</a:pPr>';
        }
        $rPr = '<a:rPr lang="' . $this->lang . '"' . ($size > 0 ? ' sz="' . $size . '"' : '')
            . ($bold ? ' b="1"' : '') . ($italic ? ' i="1"' : '') . ' dirty="0"';
        $inner = ($color !== '' ? '<a:solidFill><a:schemeClr val="' . $color . '"/></a:solidFill>' : '')
            . ($font !== '' ? '<a:latin typeface="' . $font . '"/>' : '');
        $rPr .= $inner !== '' ? '>' . $inner . '</a:rPr>' : '/>';

        return '<a:p>' . $pPr . '<a:r>' . $rPr . '<a:t>' . self::esc($text) . '</a:t></a:r></a:p>';
    }

    /**
     * Points en puces dans une zone de texte dessinée (aucun espace réservé
     * de contenu dans la mise en page) : la puce est posée explicitement.
     *
     * @param list<string> $points
     * @return list<string>
     */
    private function bulleted(array $points): array
    {
        $size = (int)round(2000 * $this->scale());

        return array_map(fn (string $p): string => '<a:p><a:pPr marL="285750" indent="-285750"><a:buFont typeface="Arial"/><a:buChar char="•"/></a:pPr>'
            . '<a:r><a:rPr lang="' . $this->lang . '" sz="' . $size . '" dirty="0"/><a:t>' . self::esc($p) . '</a:t></a:r></a:p>', $points);
    }

    /**
     * Taille (centièmes de point) d'une valeur courte qui doit tenir sur UNE
     * ligne : « 4,2 M€ » coupé en deux se lit mal. Estimation par la largeur
     * moyenne d'un caractère gras (≈ 0,62 em) ; plancher à 12 points.
     */
    private static function fit(int $preferred, string $text, int $widthEmu): int
    {
        $chars = max(1, mb_strlen($text));
        $max   = (int)floor(($widthEmu / 12700) / (0.62 * $chars) * 100);

        return max(1200, min($preferred, $max));
    }

    /** Couleur de mise en valeur des chiffres, numéros et dates (cf. contrastChoices). */
    private function emphasis(): string
    {
        return $this->pkg['masters'][0]['contrast']['emphasis'];
    }

    private function nextId(): int
    {
        return ++$this->shapeId;
    }

    /** Facteur de taille : une slide 4:3 (plus étroite) reçoit un texte un peu plus petit. */
    private function scale(): float
    {
        return max(0.6, min(1.4, $this->pkg['size']['cx'] / self::REF_WIDTH));
    }

    private function notesXml(string $notes): string
    {
        $paras = '';
        foreach (preg_split('/\n+/', $notes) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $paras .= '<a:p><a:r><a:rPr lang="' . $this->lang . '" dirty="0"/><a:t>' . self::esc($line) . '</a:t></a:r></a:p>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:notes xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Image de la diapositive"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr>'
            . '<p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
            . '<p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>' . ($paras !== '' ? $paras : '<a:p><a:endParaRPr lang="' . $this->lang . '"/></a:p>') . '</p:txBody></p:sp>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }

    /** Masque des notes minimal : image de la slide en haut, notes dessous (page portrait). */
    private static function notesMasterXml(): string
    {
        $sp = static fn (int $id, string $name, string $ph, array $box, string $body = ''): string =>
            '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
            . '<p:nvPr><p:ph ' . $ph . '/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $box[0] . '" y="' . $box[1] . '"/><a:ext cx="' . $box[2] . '" cy="' . $box[3] . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>' . $body . '</p:sp>';
        $text = '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="fr-FR"/></a:p></p:txBody>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:notesMaster xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $sp(2, 'Image de la diapositive', 'type="sldImg" idx="2"', [685800, 1143000, 5486400, 3086100])
            . $sp(3, 'Notes', 'type="body" sz="quarter" idx="3"', [685800, 4400550, 5486400, 3600450], $text)
            . $sp(4, 'Numéro', 'type="sldNum" sz="quarter" idx="5"', [3884613, 8685213, 2971800, 458787], $text)
            . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:notesStyle><a:lvl1pPr marL="0" algn="l" defTabSz="914400" rtl="0" eaLnBrk="1" latinLnBrk="0" hangingPunct="1">'
            . '<a:defRPr sz="1200" kern="1200"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="+mn-lt"/><a:ea typeface="+mn-ea"/><a:cs typeface="+mn-cs"/></a:defRPr>'
            . '</a:lvl1pPr></p:notesStyle></p:notesMaster>';
    }

    // ==================================================================
    // Lecture du paquet
    // ==================================================================

    /** @throws PptxTemplateException */
    private static function open(string $path): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PptxTemplateException(t('La lecture des modèles PowerPoint n’est pas disponible sur ce serveur.'));
        }
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path) !== true) {
            throw new PptxTemplateException(t('Ce fichier n’est pas un modèle PowerPoint lisible.'));
        }

        $total = 0;
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new PptxTemplateException(t('Ce modèle PowerPoint est trop volumineux.'));
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat   = $zip->statIndex($i);
            $total += (int)($stat['size'] ?? 0);
            $name   = (string)($stat['name'] ?? '');
            if ($total > self::MAX_UNCOMPRESSED) {
                $zip->close();
                throw new PptxTemplateException(t('Ce modèle PowerPoint est trop volumineux.'));
            }
            if (str_contains($name, 'vbaProject')) {
                $zip->close();
                throw new PptxTemplateException(t('Ce modèle contient des macros : enregistrez-le sans macros (.pptx ou .potx).'));
            }
        }

        return $zip;
    }

    /**
     * Lecture du paquet : partie principale, slides, masques et leurs mises
     * en page, thème, rôle de chaque mise en page.
     *
     * @return array<string, mixed>
     * @throws PptxTemplateException
     */
    private static function package(ZipArchive $zip): array
    {
        $rootRels = self::xml($zip, '_rels/.rels');
        $main     = null;
        foreach (self::xpath($rootRels)->query('/rel:Relationships/rel:Relationship') as $rel) {
            if (str_ends_with((string)$rel->getAttribute('Type'), '/officeDocument')) {
                $main = self::resolve('', (string)$rel->getAttribute('Target'));
            }
        }
        if ($main === null || $zip->locateName($main) === false) {
            throw new PptxTemplateException(t('Ce fichier n’est pas un modèle PowerPoint lisible.'));
        }

        $ct   = self::xml($zip, '[Content_Types].xml');
        $type = '';
        foreach (self::xpath($ct)->query('/ct:Types/ct:Override[@PartName="/' . $main . '"]') as $node) {
            $type = (string)$node->getAttribute('ContentType');
        }
        if (str_contains($type, 'macroEnabled')) {
            throw new PptxTemplateException(t('Ce modèle contient des macros : enregistrez-le sans macros (.pptx ou .potx).'));
        }
        if (!in_array($type, self::CT_MAINS, true)) {
            throw new PptxTemplateException(t('Ce fichier n’est pas une présentation PowerPoint (.pptx ou .potx).'));
        }

        $pres = self::xml($zip, $main);
        $px   = self::xpath($pres);
        $sz   = $px->query('/p:presentation/p:sldSz')->item(0);
        $size = [
            'cx' => $sz instanceof DOMElement ? max(914400, (int)$sz->getAttribute('cx')) : self::REF_WIDTH,
            'cy' => $sz instanceof DOMElement ? max(914400, (int)$sz->getAttribute('cy')) : 6858000,
        ];

        $rels = self::rels($zip, $main);

        $slides = [];
        foreach ($px->query('/p:presentation/p:sldIdLst/p:sldId') as $sld) {
            $rid  = (string)$sld->getAttributeNS(self::NS_R, 'id');
            $part = $rels[$rid]['target'] ?? null;
            if ($part === null) {
                continue;
            }
            $children = [];
            foreach (self::rels($zip, $part) as $rel) {
                // Notes et commentaires de la slide d'exemple : à elle seule.
                if (preg_match('#/(notesSlide|comments)$#', $rel['type']) === 1 && !$rel['external']) {
                    $children[] = $rel['target'];
                }
            }
            $slides[] = ['rid' => $rid, 'part' => $part, 'children' => $children];
        }

        $masters = [];
        foreach ($px->query('/p:presentation/p:sldMasterIdLst/p:sldMasterId') as $m) {
            $part = $rels[(string)$m->getAttributeNS(self::NS_R, 'id')]['target'] ?? null;
            if ($part !== null && $zip->locateName($part) !== false) {
                $masters[] = self::master($zip, $part);
            }
        }
        if ($masters === [] || $masters[0]['layouts'] === []) {
            throw new PptxTemplateException(t('Ce modèle n’a pas de mise en page de slides : enregistrez-le depuis PowerPoint (.pptx ou .potx).'));
        }

        $notesMaster = null;
        foreach ($px->query('/p:presentation/p:notesMasterIdLst/p:notesMasterId') as $nm) {
            $notesMaster = $rels[(string)$nm->getAttributeNS(self::NS_R, 'id')]['target'] ?? null;
        }

        $pkg = [
            'main'        => $main,
            'size'        => $size,
            'slides'      => $slides,
            'masters'     => $masters,
            'notesMaster' => $notesMaster !== null && $zip->locateName($notesMaster) !== false ? $notesMaster : null,
        ];
        $pkg['roles'] = self::roles($masters[0]['layouts']);

        return $pkg;
    }

    /** Un masque : ses espaces réservés, son thème, ses mises en page. */
    private static function master(ZipArchive $zip, string $part): array
    {
        $doc  = self::xml($zip, $part);
        $rels = self::rels($zip, $part);

        $themePart = null;
        $layouts   = [];
        foreach (self::xpath($doc)->query('/p:sldMaster/p:sldLayoutIdLst/p:sldLayoutId') as $id) {
            $target = $rels[(string)$id->getAttributeNS(self::NS_R, 'id')]['target'] ?? null;
            if ($target !== null && $zip->locateName($target) !== false) {
                $layouts[] = $target;
            }
        }
        foreach ($rels as $rel) {
            if (str_ends_with($rel['type'], '/theme')) {
                $themePart = $rel['target'];
            }
        }

        $masterPh = self::placeholders($doc, '/p:sldMaster');
        $clrMap   = [];
        $map      = self::xpath($doc)->query('/p:sldMaster/p:clrMap')->item(0);
        if ($map instanceof DOMElement) {
            foreach ($map->attributes as $attr) {
                $clrMap[$attr->name] = $attr->value;
            }
        }
        $background = self::background($doc);

        $parsed = [];
        foreach ($layouts as $layoutPart) {
            $ld = self::xml($zip, $layoutPart);
            $lx = self::xpath($ld);
            $root = $lx->query('/p:sldLayout')->item(0);
            $cSld = $lx->query('/p:sldLayout/p:cSld')->item(0);
            $phs  = self::placeholders($ld, '/p:sldLayout');
            foreach ($phs as $k => $ph) {
                if ($ph['box'] === null) {
                    $phs[$k]['box'] = self::inherited($masterPh, $ph['attrs']['type'] ?? '');
                }
            }
            $parsed[] = [
                'part' => $layoutPart,
                'name' => $cSld instanceof DOMElement ? (string)$cSld->getAttribute('name') : '',
                'type' => $root instanceof DOMElement ? (string)$root->getAttribute('type') : '',
                'ph'   => $phs,
            ];
        }

        $theme = $themePart !== null && $zip->locateName($themePart) !== false
            ? self::theme(self::xml($zip, $themePart))
            : ['colors' => [], 'fonts' => ['major' => '', 'minor' => '']];

        return [
            'part'       => $part,
            'layouts'    => $parsed,
            'ph'         => $masterPh,
            'clrMap'     => $clrMap,
            'themePart'  => (string)$themePart,
            'theme'      => $theme,
            'background' => $background,
            'contrast'   => self::contrastChoices($theme['colors'], $clrMap, $background),
        ];
    }

    /**
     * Fond du masque : une couleur UNIE (nom de couleur du thème), ou null
     * pour une image, un dégradé ou un motif — dont on ne sait pas lire la
     * teinte réelle.
     */
    private static function background(DOMDocument $doc): ?string
    {
        $xp = self::xpath($doc);
        $bg = $xp->query('/p:sldMaster/p:cSld/p:bg')->item(0);
        if (!$bg instanceof DOMElement) {
            return 'bg1';
        }
        $solid = $xp->query('p:bgPr/a:solidFill/a:schemeClr', $bg)->item(0);
        if ($solid instanceof DOMElement) {
            return (string)$solid->getAttribute('val');
        }
        $ref = $xp->query('p:bgRef', $bg)->item(0);
        // Style de fond n° 1 (idx 1001) : l'aplat du thème. Au-delà, dégradés
        // et images (idx 1002, 1003).
        if ($ref instanceof DOMElement && (string)$ref->getAttribute('idx') === '1001') {
            $clr = $xp->query('a:schemeClr', $ref)->item(0);

            return $clr instanceof DOMElement ? (string)$clr->getAttribute('val') : 'bg1';
        }

        return null;
    }

    /**
     * Couleurs de mise en valeur des formes dessinées, choisies pour rester
     * LISIBLES : la couleur d'accent sur un fond uni qui la fait ressortir,
     * sinon la couleur de texte du masque (un fond en image ou en dégradé est
     * souvent de la teinte de l'accent) ; le texte de l'en-tête d'un tableau,
     * clair ou foncé selon l'accent.
     *
     * @param array<string, string> $colors
     * @param array<string, string> $clrMap
     * @return array{emphasis: string, onAccent: string}
     */
    private static function contrastChoices(array $colors, array $clrMap, ?string $background): array
    {
        $resolve = static fn (string $name): ?string => $colors[$clrMap[$name] ?? $name] ?? null;
        $accent  = $colors['accent1'] ?? null;

        $emphasis = 'tx1';
        if ($background !== null && $accent !== null) {
            $bg = $resolve($background);
            if ($bg !== null && self::contrast($accent, $bg) >= 3.0) {
                $emphasis = 'accent1';
            }
        }

        $onAccent = 'lt1';
        if ($accent !== null && isset($colors['lt1'], $colors['dk1'])
            && self::contrast($accent, $colors['dk1']) > self::contrast($accent, $colors['lt1'])) {
            $onAccent = 'dk1';
        }

        return ['emphasis' => $emphasis, 'onAccent' => $onAccent];
    }

    /** Rapport de contraste WCAG entre deux couleurs #rrggbb. */
    private static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $rgb = array_map(static function (string $c): float {
                $v = hexdec($c) / 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        [$la, $lb] = [$lum($a), $lum($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Espaces réservés d'un masque ou d'une mise en page : attributs du
     * `p:ph` et boîte (EMU) si elle est posée ici.
     *
     * @return list<array{attrs: array<string, string>, box: array{0: int, 1: int, 2: int, 3: int}|null}>
     */
    private static function placeholders(DOMDocument $doc, string $root): array
    {
        $xp  = self::xpath($doc);
        $out = [];
        foreach ($xp->query($root . '/p:cSld/p:spTree/p:sp') as $sp) {
            $ph = $xp->query('p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
            if (!$ph instanceof DOMElement) {
                continue;
            }
            $attrs = [];
            foreach (['type', 'orient', 'sz', 'idx'] as $name) {
                if ($ph->hasAttribute($name)) {
                    $attrs[$name] = (string)$ph->getAttribute($name);
                }
            }
            $off = $xp->query('p:spPr/a:xfrm/a:off', $sp)->item(0);
            $ext = $xp->query('p:spPr/a:xfrm/a:ext', $sp)->item(0);
            $box = $off instanceof DOMElement && $ext instanceof DOMElement
                ? [(int)$off->getAttribute('x'), (int)$off->getAttribute('y'), (int)$ext->getAttribute('cx'), (int)$ext->getAttribute('cy')]
                : null;
            $out[] = ['attrs' => $attrs, 'box' => $box];
        }

        return $out;
    }

    /** Boîte héritée du masque : titre pour un titre, corps pour le reste. */
    private static function inherited(array $masterPh, string $type): ?array
    {
        $want = in_array($type, ['title', 'ctrTitle'], true) ? 'title'
            : (in_array($type, ['dt', 'ftr', 'sldNum'], true) ? $type : 'body');
        foreach ($masterPh as $ph) {
            if (($ph['attrs']['type'] ?? '') === $want) {
                return $ph['box'];
            }
        }

        return null;
    }

    /** Couleurs (#rrggbb) et polices du thème. */
    private static function theme(DOMDocument $doc): array
    {
        $xp     = self::xpath($doc);
        $colors = [];
        foreach (['dk1', 'lt1', 'dk2', 'lt2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6'] as $name) {
            $node = $xp->query('//a:clrScheme/a:' . $name . '/*')->item(0);
            if ($node instanceof DOMElement) {
                $hex = $node->localName === 'sysClr' ? (string)$node->getAttribute('lastClr') : (string)$node->getAttribute('val');
                if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1) {
                    $colors[$name] = '#' . strtolower($hex);
                }
            }
        }
        $font = static function (string $kind) use ($xp): string {
            $node = $xp->query('//a:fontScheme/a:' . $kind . '/a:latin')->item(0);

            return $node instanceof DOMElement ? (string)$node->getAttribute('typeface') : '';
        };

        return ['colors' => $colors, 'fonts' => ['major' => $font('majorFont'), 'minor' => $font('minorFont')]];
    }

    /**
     * Couleurs du modèle au format d'un thème DeckSpec : fond et texte selon
     * la correspondance du masque (`bg1` → lt1 en général), accent, texte
     * secondaire.
     *
     * @return array<string, string>
     */
    private static function palette(array $master): array
    {
        $colors = $master['theme']['colors'];
        $map    = $master['clrMap'];
        $pick   = static fn (string $logical, string $default) => $colors[$map[$logical] ?? $default] ?? null;

        $out = array_filter([
            'bg'     => $pick('bg1', 'lt1'),
            'text'   => $pick('tx1', 'dk1'),
            'accent' => $colors['accent1'] ?? null,
            'muted'  => $pick('tx2', 'dk2'),
        ]);

        return array_map('strval', $out);
    }

    /**
     * Mise en page retenue pour chaque rôle. Par TYPE d'abord (PowerPoint les
     * pose sur ses mises en page standard, quelle que soit leur langue), puis
     * par composition (titre + un corps…), puis le contenu par défaut.
     *
     * @param list<array<string, mixed>> $layouts
     * @return array<string, array{layout: array<string, mixed>, fallback: bool}>
     */
    private static function roles(array $layouts): array
    {
        $byType = [];
        foreach ($layouts as $layout) {
            if ($layout['type'] !== '' && !isset($byType[$layout['type']])) {
                $byType[$layout['type']] = $layout;
            }
        }
        $find = static function (callable $test) use ($layouts): ?array {
            foreach ($layouts as $layout) {
                if ($test($layout)) {
                    return $layout;
                }
            }

            return null;
        };

        $roles = [];
        foreach (self::ROLE_TYPES as $role => $types) {
            foreach ($types as $k => $type) {
                if (isset($byType[$type])) {
                    $roles[$role] = ['layout' => $byType[$type], 'fallback' => $k > 0];
                    break;
                }
            }
        }

        // Par composition, quand le modèle n'a pas typé ses mises en page.
        $roles['content'] ??= ($l = $find(static fn (array $l): bool => self::placeholder($l, 'title') !== null && count(self::bodies($l)) === 1)) !== null
            ? ['layout' => $l, 'fallback' => true] : null;
        $roles['cover'] ??= ($l = $find(static fn (array $l): bool => self::placeholder($l, 'ctrTitle') !== null)) !== null
            ? ['layout' => $l, 'fallback' => true] : null;
        $roles['titleOnly'] ??= ($l = $find(static fn (array $l): bool => self::placeholder($l, 'title') !== null && self::bodies($l) === [])) !== null
            ? ['layout' => $l, 'fallback' => true] : null;
        $roles['comparison'] ??= ($l = $find(static fn (array $l): bool => self::placeholder($l, 'title') !== null && count(self::bodies($l)) === 4)) !== null
            ? ['layout' => $l, 'fallback' => true] : null;
        $roles['two'] ??= ($l = $find(static fn (array $l): bool => self::placeholder($l, 'title') !== null && count(self::bodies($l)) === 2)) !== null
            ? ['layout' => $l, 'fallback' => true] : null;

        $default = $roles['content'] ?? ['layout' => $layouts[0], 'fallback' => true];
        $roles['content'] ??= $default;
        // À défaut : une comparaison se pose sur deux colonnes, un intercalaire
        // sur la couverture, le reste sur le contenu.
        $nearest = ['section' => 'cover', 'comparison' => 'two'];
        foreach (self::ROLES as $role) {
            if (($roles[$role] ?? null) === null) {
                $near = $roles[$nearest[$role] ?? 'content'] ?? null;
                $roles[$role] = ['layout' => $near['layout'] ?? $default['layout'], 'fallback' => true];
            }
        }
        // Un intercalaire sans modèle d'intercalaire : la couverture vaut mieux que le contenu.
        if ($roles['section']['fallback'] && isset($byType['title'])) {
            $roles['section'] = ['layout' => $byType['title'], 'fallback' => true];
        }

        return $roles;
    }

    // ------------------------------------------------------------------
    // Espaces réservés
    // ------------------------------------------------------------------

    /** Premier espace réservé d'un type donné, null s'il n'y en a pas. */
    private static function placeholder(array $layout, string $type): ?array
    {
        foreach ($layout['ph'] as $ph) {
            if (($ph['attrs']['type'] ?? '') === $type) {
                return $ph;
            }
        }

        return null;
    }

    /**
     * Espaces réservés de CONTENU (corps, objet), de gauche à droite puis de
     * haut en bas.
     *
     * @return list<array<string, mixed>>
     */
    private static function bodies(array $layout): array
    {
        $out = array_values(array_filter(
            $layout['ph'],
            static fn (array $ph): bool => !in_array($ph['attrs']['type'] ?? '', self::NOT_BODY, true)
                && ($ph['attrs']['orient'] ?? '') !== 'vert'
        ));
        usort($out, static fn (array $a, array $b): int => [($a['box'][0] ?? 0), ($a['box'][1] ?? 0)] <=> [($b['box'][0] ?? 0), ($b['box'][1] ?? 0)]);

        return $out;
    }

    /**
     * Espaces de contenu regroupés en deux colonnes (milieu de la slide),
     * chacune triée de haut en bas.
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function columns(array $bodies): array
    {
        if ($bodies === [] || in_array(null, array_column($bodies, 'box'), true)) {
            return [];
        }
        $mid  = (min(array_map(static fn ($b) => $b['box'][0], $bodies)) + max(array_map(static fn ($b) => $b['box'][0] + $b['box'][2], $bodies))) / 2;
        $cols = [[], []];
        foreach ($bodies as $b) {
            $cols[($b['box'][0] + $b['box'][2] / 2) < $mid ? 0 : 1][] = $b;
        }
        foreach ($cols as $k => $col) {
            usort($col, static fn ($a, $b): int => $a['box'][1] <=> $b['box'][1]);
            $cols[$k] = $col;
        }

        return array_values(array_filter($cols));
    }

    /** Mise en page de contenu du paquet (sa zone de corps sert aux formes dessinées). */
    private static function contentLayout(array $pkg): array
    {
        return $pkg['roles']['content']['layout'];
    }

    /**
     * Zone de contenu d'une mise en page (EMU) : son plus grand espace de
     * corps, sinon une marge de 8 % sous la zone du titre.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function area(array $layout, array $pkg): array
    {
        $best = null;
        foreach (self::bodies($layout) as $ph) {
            if ($ph['box'] !== null && ($best === null || $ph['box'][2] * $ph['box'][3] > $best[2] * $best[3])) {
                $best = $ph['box'];
            }
        }
        if ($best !== null && $best[2] > 0 && $best[3] > 0) {
            return $best;
        }
        [$cx, $cy] = [$pkg['size']['cx'], $pkg['size']['cy']];

        return [(int)($cx * 0.08), (int)($cy * 0.28), (int)($cx * 0.84), (int)($cy * 0.6)];
    }

    /** Zone du titre du masque, sinon le haut de la slide. */
    private static function titleArea(array $pkg): array
    {
        $box = self::inherited($pkg['masters'][0]['ph'], 'title');
        if ($box !== null && $box[2] > 0) {
            return $box;
        }
        [$cx, $cy] = [$pkg['size']['cx'], $pkg['size']['cy']];

        return [(int)($cx * 0.08), (int)($cy * 0.08), (int)($cx * 0.84), (int)($cy * 0.15)];
    }

    // ------------------------------------------------------------------
    // XML et relations
    // ------------------------------------------------------------------

    /** @throws PptxTemplateException */
    private static function xml(ZipArchive $zip, string $part): DOMDocument
    {
        $raw = $zip->getFromName($part);
        $doc = new DOMDocument();
        // Pas de réseau, pas d'entité externe : un modèle reste une donnée.
        if (!is_string($raw) || $raw === '' || !@$doc->loadXML($raw, LIBXML_NONET)) {
            throw new PptxTemplateException(t('Ce fichier n’est pas un modèle PowerPoint lisible.'));
        }

        return $doc;
    }

    private static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('p', self::NS_P);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('r', self::NS_R);
        $xp->registerNamespace('rel', self::NS_REL);
        $xp->registerNamespace('ct', self::NS_CT);

        return $xp;
    }

    /**
     * Relations d'une partie, par identifiant, cibles résolues en chemins du
     * paquet.
     *
     * @return array<string, array{type: string, target: string, external: bool}>
     */
    private static function rels(ZipArchive $zip, string $part): array
    {
        $path = self::relsPath($part);
        if ($zip->locateName($path) === false) {
            return [];
        }
        $out = [];
        foreach (self::xpath(self::xml($zip, $path))->query('/rel:Relationships/rel:Relationship') as $rel) {
            $external = strtolower((string)$rel->getAttribute('TargetMode')) === 'external';
            $out[(string)$rel->getAttribute('Id')] = [
                'type'     => (string)$rel->getAttribute('Type'),
                'target'   => $external ? (string)$rel->getAttribute('Target') : self::resolve($part, (string)$rel->getAttribute('Target')),
                'external' => $external,
            ];
        }

        return $out;
    }

    /** @param list<array{type: string, target: string}> $rels */
    private static function relsXml(array $rels): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::NS_REL . '">';
        foreach ($rels as $k => $rel) {
            $out .= '<Relationship Id="rId' . ($k + 1) . '" Type="' . self::REL . $rel['type'] . '" Target="' . self::esc($rel['target']) . '"/>';
        }

        return $out . '</Relationships>';
    }

    /** `ppt/slides/slide1.xml` → `ppt/slides/_rels/slide1.xml.rels`. */
    private static function relsPath(string $part): string
    {
        $dir = dirname($part);

        return ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($part) . '.rels';
    }

    /**
     * Cible d'une relation → chemin du paquet. Confiné : une cible qui
     * remonte au-delà de la racine est refusée.
     *
     * @throws PptxTemplateException
     */
    private static function resolve(string $source, string $target): string
    {
        if (str_starts_with($target, '/')) {
            $path = ltrim($target, '/');
        } else {
            $dir  = dirname($source);
            $path = ($dir === '.' || $dir === '' ? '' : $dir . '/') . $target;
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    throw new PptxTemplateException(t('Ce fichier n’est pas un modèle PowerPoint lisible.'));
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }

    /** Chemin relatif d'une partie vers une autre (cible d'une relation). */
    private static function relative(string $from, string $to): string
    {
        $fromDir = explode('/', dirname($from));
        $toParts = explode('/', $to);
        while ($fromDir !== [] && $toParts !== [] && $fromDir[0] === $toParts[0]) {
            array_shift($fromDir);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromDir)) . implode('/', $toParts);
    }

    private static function ratio(int $cx, int $cy): string
    {
        $r = $cx / max(1, $cy);
        foreach (['16:9' => 16 / 9, '16:10' => 1.6, '4:3' => 4 / 3] as $label => $value) {
            if (abs($r - $value) < 0.02) {
                return $label;
            }
        }

        return number_format($r, 2, ',', '') . ':1';
    }

    /** Texte d'une ligne, sans caractère interdit en XML. */
    private static function clean(string $text): string
    {
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
