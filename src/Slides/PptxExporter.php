<?php

declare(strict_types=1);

use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * Export d'un deck au format PowerPoint (.pptx).
 *
 * La spec porte une géométrie en pourcentages du canvas 16:9 : la conversion
 * vers PowerPoint est donc une simple mise à l'échelle, sans rétro-ingénierie
 * d'un rendu HTML. PHPPresentation attend des pixels à 96 dpi (le writer les
 * convertit en EMU) — le canvas 16:9 de la bibliothèque, 9 144 000 × 5 143 500
 * EMU, fait exactement 960 × 540 px.
 *
 * Fidélité : les couleurs et l'échelle typographique sont lues chez
 * DeckRenderer, seule source de ces règles. Une slide exportée ressemble donc
 * à l'aperçu, y compris sur les cas piégeux (texte sur aplat d'accent, texte
 * blanc sur image pleine page).
 */
final class PptxExporter
{
    /** Canvas PowerPoint 16:9, en pixels à 96 dpi. */
    private const CANVAS_W = 960.0;
    private const CANVAS_H = 540.0;

    /** 1 cqw = 1 % de 960 px = 9,6 px = 7,2 pt. */
    private const PT_PER_CQW = 7.2;

    /**
     * Retrait d'une puce, en PIXELS (unité de PHPPresentation, convertie en
     * EMU par le writer) : 2,6 cqw, celui de l'aperçu (`.el-bullets li`,
     * DeckRenderer). Il valait 180 px — 19 % de la largeur de la slide entre
     * la puce et son texte.
     */
    private const BULLET_INDENT_PX = 2.6 * self::CANVAS_W / 100;

    /** Binaire .pptx d'un deck normalisé. */
    public static function export(array $deck): string
    {
        $deck   = DeckSpec::normalize($deck);
        $theme  = $deck['theme'];
        $slides = $deck['slides'];

        $pres = new PhpPresentation();
        $pres->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $pres->getDocumentProperties()
            ->setTitle((string)$deck['title'])
            ->setCreator('MIA')
            ->setSubject((string)$deck['title']);

        $tempDir = ROOT . '/storage/cache/deck';
        $tempImages = [];

        foreach (array_values($slides) as $index => $slide) {
            // Le constructeur crée déjà une slide : la réutiliser évite une
            // première page vide dans le fichier produit.
            $target = $index === 0 ? $pres->getSlide(0) : $pres->createSlide();
            self::buildSlide($target, is_array($slide) ? $slide : [], $theme, $tempDir, $tempImages);
        }

        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $file = $tempDir . '/deck-' . bin2hex(random_bytes(6)) . '.pptx';

        try {
            IOFactory::createWriter($pres, 'PowerPoint2007')->save($file);
            $binary = (string)file_get_contents($file);
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
            foreach ($tempImages as $tmp) {
                @unlink($tmp);
            }
        }

        return $binary;
    }

    /** @param array<int,string> $tempImages recadrages temporaires à supprimer après écriture */
    private static function buildSlide(
        Slide $target,
        array $slide,
        array $theme,
        string $tempDir,
        array &$tempImages
    ): void {
        $layout = (string)($slide['layout'] ?? 'titre-contenu');

        $background = new Slide\Background\Color();
        $background->setColor(new Color(self::argb((string)($theme['bg'] ?? '#ffffff'))));
        $target->setBackground($background);

        // L'ordre des éléments est l'ordre de superposition, dans la spec comme
        // en PPTX : un voile posé après l'image la couvre, comme en HTML.
        foreach ($slide['elements'] as $el) {
            if (!is_array($el)) {
                continue;
            }
            switch ((string)($el['type'] ?? '')) {
                case 'shape':
                    self::addShape($target, $el, $theme);
                    break;
                case 'text':
                    self::addText($target, $el, $layout, $theme);
                    break;
                case 'bullets':
                    self::addBullets($target, $el, $layout, $theme);
                    break;
                case 'image':
                    self::addImage($target, $el, $tempDir, $tempImages);
                    break;
                case 'table':
                    self::addTable($target, $el, $layout, $theme);
                    break;
            }
        }

        $notes = trim((string)($slide['notes'] ?? ''));
        if ($notes !== '') {
            $note = $target->getNote()->createRichTextShape();
            $note->setOffsetX(0)->setOffsetY(0)->setWidth((int)self::CANVAS_W)->setHeight(200);
            $note->createTextRun($notes);
        }
    }

    private static function addShape(Slide $target, array $el, array $theme): void
    {
        $color = new Color(self::argb(DeckRenderer::fillColor((string)($el['fill'] ?? 'accent'), $theme)));
        $color->setAlpha((int)round(((float)($el['opacity'] ?? 1)) * 100));

        // Cercle et rectangle arrondi : de vraies formes PowerPoint, sans
        // contour (l'AutoShape n'en trace que si on lui en donne un). Le rayon
        // des coins est celui de PowerPoint par défaut, repris par l'aperçu
        // et le PDF (DeckRenderer::ROUND_RATIO).
        $kind = (string)($el['shape'] ?? 'rect');
        if ($kind === 'circle' || $kind === 'round') {
            $shape = $target->createAutoShape();
            $shape->setType($kind === 'circle' ? AutoShape::TYPE_OVAL : AutoShape::TYPE_ROUNDED_RECTANGLE);
            self::place($shape, $el);
            $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($color)->setEndColor($color);

            return;
        }

        // Une zone de texte vide avec un fond plein : PHPPresentation n'expose
        // pas de rectangle sans bordure par défaut, et une AutoShape en
        // dessinerait une.
        $shape = $target->createRichTextShape();
        self::place($shape, $el);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($color)->setEndColor($color);
        $shape->getBorder()->setLineStyle(Border::LINE_NONE);
        $shape->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
    }

    private static function addText(Slide $target, array $el, string $layout, array $theme): void
    {
        $shape = $target->createRichTextShape();
        self::place($shape, $el);
        $shape->setWrap(RichText::WRAP_SQUARE)
            ->setAutoFit(RichText::AUTOFIT_NORMAL)
            ->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->getBorder()->setLineStyle(Border::LINE_NONE);

        $lines = preg_split('/\r\n|\r|\n/', (string)($el['text'] ?? '')) ?: [''];
        foreach (array_values($lines) as $i => $line) {
            $paragraph = $i === 0 ? $shape->getActiveParagraph() : $shape->createParagraph();
            $paragraph->getAlignment()->setHorizontal(self::alignment((string)($el['align'] ?? 'left')));
            $run = $paragraph->createTextRun($line);
            $run->setLanguage(self::language());
            self::applyFont(
                $run->getFont(),
                $el,
                DeckRenderer::textColor($el, $layout, $theme),
                in_array((string)($el['role'] ?? 'corps'), ['titre', 'citation', 'chiffre'], true)
                    ? (string)($theme['font_title'] ?? '')
                    : (string)($theme['font_body'] ?? ''),
                (string)($el['text'] ?? '')
            );
        }
    }

    private static function addBullets(Slide $target, array $el, string $layout, array $theme): void
    {
        $items = array_values(array_filter(
            (array)($el['items'] ?? []),
            static fn ($item): bool => trim((string)$item) !== ''
        ));
        if ($items === []) {
            return;
        }

        $shape = $target->createRichTextShape();
        self::place($shape, $el);
        $shape->setWrap(RichText::WRAP_SQUARE)
            ->setAutoFit(RichText::AUTOFIT_NORMAL)
            ->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->getBorder()->setLineStyle(Border::LINE_NONE);

        $color  = DeckRenderer::textColor($el, $layout, $theme);
        $marker = (string)(DeckRenderer::tokenColor((string)($el['marker'] ?? ''), $theme)
            ?? (preg_match('/^#[0-9a-f]{6}$/', (string)($el['marker'] ?? '')) === 1 ? $el['marker'] : ''));
        if ($marker === '') {
            $marker = (string)($theme['accent'] ?? '#2563eb');
        }
        foreach ($items as $i => $item) {
            $paragraph = $i === 0 ? $shape->getActiveParagraph() : $shape->createParagraph();
            $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setMarginLeft(self::BULLET_INDENT_PX)->setIndent(-self::BULLET_INDENT_PX);
            // Interligne et écart entre puces de l'aperçu (1,4 em ; 1,6 cqw) :
            // 100 % de PowerPoint vaut déjà environ 1,2 em.
            $paragraph->setLineSpacing(115);
            $paragraph->setSpacingBefore($i === 0 ? 0 : (int)round(1.6 * self::PT_PER_CQW));
            $bullet = new Bullet();
            $bullet->setBulletType(Bullet::TYPE_BULLET)
                ->setBulletChar('•')
                ->setBulletColor(new Color(self::argb($marker)));
            $paragraph->setBulletStyle($bullet);
            $run = $paragraph->createTextRun((string)$item);
            $run->setLanguage(self::language());
            self::applyFont($run->getFont(), $el, $color, (string)($theme['font_body'] ?? ''));
        }
    }

    private static function addImage(Slide $target, array $el, string $tempDir, array &$tempImages): void
    {
        $path = SlideImage::resolvePath((string)($el['url'] ?? ''));
        if ($path === null) {
            return;   // image non générée : la boîte reste vide, comme à l'écran
        }

        $w = max(0.01, (float)($el['w'] ?? 10)) * self::CANVAS_W / 100;
        $h = max(0.01, (float)($el['h'] ?? 10)) * self::CANVAS_H / 100;

        // Image ENTIÈRE (logo) : ni recadrage, ni réécriture — `coverCrop`
        // perdrait la transparence d'un PNG et noircirait son fond.
        if ((string)($el['fit'] ?? 'cover') === 'contain') {
            [$fx, $fy, $fw, $fh] = SlideImage::containBox(
                $path,
                (float)($el['x'] ?? 0) * self::CANVAS_W / 100,
                (float)($el['y'] ?? 0) * self::CANVAS_H / 100,
                $w,
                $h,
                (string)($el['anchor'] ?? 'center')
            );
            $shape = $target->createDrawingShape();
            $shape->setPath($path, false);
            $shape->setResizeProportional(false);
            $shape->setOffsetX((int)round($fx))->setOffsetY((int)round($fy));
            $shape->setWidth((int)max(1, round($fw)))->setHeight((int)max(1, round($fh)));

            return;
        }

        $cropped = SlideImage::coverCrop($path, $w / $h, $tempDir);
        if ($cropped !== $path) {
            $tempImages[] = $cropped;
        }

        $shape = $target->createDrawingShape();
        $shape->setPath($cropped, false);
        self::place($shape, $el);
    }

    /**
     * Un VRAI tableau PowerPoint (modifiable dans PowerPoint) : en-tête sur
     * l'accent du thème, lignes alternées d'une teinte légère, filet fin
     * entre les lignes. Toutes les bordures sont posées explicitement : la
     * bibliothèque trace sinon un cadre noir autour de chaque cellule.
     */
    private static function addTable(Slide $target, array $el, string $layout, array $theme): void
    {
        $rows = array_values(array_filter((array)($el['rows'] ?? []), 'is_array'));
        if ($rows === []) {
            return;
        }
        $cols   = max(1, max(array_map('count', $rows)));
        $table  = $target->createTableShape($cols);
        self::place($table, $el);
        $width  = max(1, (int)round(((float)($el['w'] ?? 84)) * self::CANVAS_W / 100));
        $height = max(1, (int)round(((float)($el['h'] ?? 40)) * self::CANVAS_H / 100));
        $rowH   = max(18, intdiv($height, count($rows)));
        $colW   = max(1, intdiv($width, $cols));

        $accent = (string)($theme['accent'] ?? '#2563eb');
        $bg     = (string)($theme['bg'] ?? '#ffffff');
        $text   = DeckRenderer::textColor(['role' => 'corps'], $layout, $theme);
        $band   = (string)($el['band'] ?? '') !== '' ? DeckRenderer::fillColor((string)$el['band'], $theme) : self::mix($accent, $bg, 0.10);
        $head   = (string)($el['head'] ?? '') !== '' ? DeckRenderer::fillColor((string)$el['head'], $theme) : $accent;
        $rule   = self::mix((string)($theme['muted'] ?? '#6b7280'), $bg, 0.45);
        $pt     = (int)max(8, round(DeckRenderer::fontSizeCqw((string)($el['size'] ?? 's')) * self::PT_PER_CQW));
        $header = !empty($el['header']);

        foreach ($rows as $r => $cells) {
            $row = $table->createRow();
            $row->setHeight($rowH);
            $isHeader = $header && $r === 0;
            $fill     = $isHeader ? $head : ((($r - ($header ? 1 : 0)) % 2 === 1) ? $band : $bg);

            for ($c = 0; $c < $cols; $c++) {
                $cell = $row->nextCell();
                $cell->setWidth($colW);
                $color = new Color(self::argb($fill));
                $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($color)->setEndColor($color);
                foreach (['getLeft', 'getRight', 'getTop'] as $side) {
                    $cell->getBorders()->{$side}()->setLineStyle(Border::LINE_NONE);
                }
                $cell->getBorders()->getBottom()
                    ->setLineStyle($isHeader ? Border::LINE_NONE : Border::LINE_SINGLE)
                    ->setLineWidth(1)
                    ->setColor(new Color(self::argb($rule)));

                $paragraph = $cell->getActiveParagraph();
                $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $run = $paragraph->createTextRun((string)($cells[$c] ?? ''));
                $run->setLanguage(self::language());
                $run->getFont()
                    ->setSize($pt)
                    ->setBold($isHeader)
                    ->setColor(new Color(self::argb($isHeader ? DeckRenderer::onColor($head) : $text)));
                $name = self::fontName((string)($theme['font_body'] ?? ''));
                if ($name !== '') {
                    $run->getFont()->setName($name);
                }
            }
        }
    }

    /** Mélange de deux couleurs #rrggbb : `$ratio` de la première. */
    private static function mix(string $a, string $b, float $ratio): string
    {
        $parse = static fn (string $hex): array => preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m) === 1
            ? array_map('hexdec', str_split($m[1], 2))
            : [255, 255, 255];
        [$ca, $cb] = [$parse($a), $parse($b)];
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int)round($ca[$i] * $ratio + $cb[$i] * (1 - $ratio))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** Géométrie : pourcentages du canvas → pixels PowerPoint. */
    private static function place(object $shape, array $el): void
    {
        $shape->setOffsetX((int)round(((float)($el['x'] ?? 0)) * self::CANVAS_W / 100));
        $shape->setOffsetY((int)round(((float)($el['y'] ?? 0)) * self::CANVAS_H / 100));
        $shape->setWidth((int)max(1, round(((float)($el['w'] ?? 10)) * self::CANVAS_W / 100)));
        $shape->setHeight((int)max(1, round(((float)($el['h'] ?? 10)) * self::CANVAS_H / 100)));
    }

    /** @param string $fit texte servant à l'ajustement de taille ('' = pas d'ajustement) */
    private static function applyFont(object $font, array $el, string $color, string $family, string $fit = ''): void
    {
        $cqw = DeckRenderer::fontSizeCqw(
            (string)($el['size'] ?? 'm'),
            $fit,
            (float)($el['w'] ?? 0),
            (float)($el['h'] ?? 0)
        );

        $font->setSize((int)max(6, round($cqw * self::PT_PER_CQW)))
            ->setBold(((int)($el['weight'] ?? 400)) >= 600)
            ->setItalic(!empty($el['italic']))
            ->setColor(new Color(self::argb($color)));

        // Police choisie dans l'éditeur : le catalogue donne son équivalent
        // PowerPoint, sinon on retombe sur la police du thème.
        $chosen = (string)(DeckSpec::FONTS[(string)($el['font'] ?? '')]['pptx'] ?? '');
        $name   = $chosen !== '' ? $chosen : self::fontName($family);
        if ($name !== '') {
            $font->setName($name);
        }
    }

    /**
     * Langue de vérification des textes. PHPPresentation écrit `en-US` par
     * défaut : un deck français s'ouvrait souligné en rouge de bout en bout.
     * Faute de langue propre au deck, celle de la personne qui l'exporte.
     */
    private static function language(): string
    {
        return class_exists('I18n') && I18n::locale() === 'en' ? 'en-US' : 'fr-FR';
    }

    /** Première famille d'une pile CSS, sans guillemets : PowerPoint veut un nom. */
    private static function fontName(string $stack): string
    {
        $first = trim((string)(explode(',', $stack)[0] ?? ''));

        return trim($first, "'\" ");
    }

    private static function alignment(string $align): string
    {
        return match ($align) {
            'center' => Alignment::HORIZONTAL_CENTER,
            'right'  => Alignment::HORIZONTAL_RIGHT,
            default  => Alignment::HORIZONTAL_LEFT,
        };
    }

    /** #rrggbb → ARGB opaque attendu par PHPPresentation. */
    private static function argb(string $hex): string
    {
        return preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m) === 1
            ? 'FF' . strtoupper($m[1])
            : 'FF000000';
    }
}
