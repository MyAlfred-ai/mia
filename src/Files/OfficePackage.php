<?php

declare(strict_types=1);

/**
 * Lecture d'un paquet OOXML (Word, PowerPoint) déposé par un administrateur :
 * ouverture bornée (bombe ZIP, macros), XML sans réseau, relations résolues
 * et CONFINÉES au paquet, miniature enregistrée par Office.
 *
 * Utilisé par le modèle Word (DocxTemplate) et par la miniature des deux
 * modèles. PptxTemplate garde ses propres lectures, antérieures, qui suivent
 * les mêmes règles.
 */
final class OfficePackage
{
    public const NS_W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    public const NS_CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';
    public const NS_A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const NS_CP  = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';
    public const NS_DC  = 'http://purl.org/dc/elements/1.1/';
    public const NS_DCTERMS = 'http://purl.org/dc/terms/';

    public const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /** Bombe ZIP : au-delà, ce n'est pas un modèle de document. */
    public const MAX_ENTRIES      = 4000;
    public const MAX_UNCOMPRESSED = 300_000_000;

    /**
     * Ouvre un paquet déposé : lisible, borné, sans macros.
     *
     * @param string $label « Word » ou « PowerPoint » (messages).
     * @throws OfficeTemplateException
     */
    public static function open(string $path, string $label = 'Word'): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new OfficeTemplateException(strtr(t('La lecture des modèles {format} n’est pas disponible sur ce serveur.'), ['{format}' => $label]));
        }
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path) !== true) {
            throw new OfficeTemplateException(strtr(t('Ce fichier n’est pas un modèle {format} lisible.'), ['{format}' => $label]));
        }

        $tooBig = strtr(t('Ce modèle {format} est trop volumineux.'), ['{format}' => $label]);
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new OfficeTemplateException($tooBig);
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat   = $zip->statIndex($i);
            $total += (int)($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED) {
                $zip->close();
                throw new OfficeTemplateException($tooBig);
            }
            if (str_contains((string)($stat['name'] ?? ''), 'vbaProject')) {
                $zip->close();
                throw new OfficeTemplateException(t('Ce modèle contient des macros : enregistrez-le sans macros (.docx ou .dotx).'));
            }
        }

        // Certains paquets (modèles mis en cache par Office) nomment leurs
        // parties avec des barres inverses (`word\document.xml`) : Office les
        // lit, la norme et ZipArchive non. Elles sont renommées.
        $renamed = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_contains($name, '\\')) {
                $zip->renameIndex($i, str_replace('\\', '/', $name));
                $renamed = true;
            }
        }
        if ($renamed) {
            $zip->close();
            if ($zip->open($path) !== true) {
                throw new OfficeTemplateException(strtr(t('Ce fichier n’est pas un modèle {format} lisible.'), ['{format}' => $label]));
            }
        }

        return $zip;
    }

    /**
     * Partie principale du paquet (relation `officeDocument` de la racine).
     *
     * @throws OfficeTemplateException
     */
    public static function mainPart(ZipArchive $zip): string
    {
        foreach (self::rels($zip, '') as $rel) {
            if (str_ends_with($rel['type'], '/officeDocument') && !$rel['external']) {
                if ($zip->locateName($rel['target']) !== false) {
                    return $rel['target'];
                }
            }
        }

        throw new OfficeTemplateException(t('Ce fichier n’est pas un modèle Word lisible.'));
    }

    /** Type de contenu déclaré d'une partie, '' s'il n'y en a pas. */
    public static function contentType(ZipArchive $zip, string $part): string
    {
        $ct = self::xml($zip, '[Content_Types].xml');
        foreach (self::xpath($ct)->query('/ct:Types/ct:Override[@PartName="/' . $part . '"]') as $node) {
            return (string)$node->getAttribute('ContentType');
        }

        return '';
    }

    /** @throws OfficeTemplateException */
    public static function xml(ZipArchive $zip, string $part): DOMDocument
    {
        $raw = $zip->getFromName($part);
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        // Pas de réseau, pas d'entité externe : un modèle reste une donnée.
        if (!is_string($raw) || $raw === '' || !@$doc->loadXML($raw, LIBXML_NONET)) {
            throw new OfficeTemplateException(t('Ce fichier n’est pas un modèle Word lisible.'));
        }

        return $doc;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', self::NS_W);
        $xp->registerNamespace('r', self::NS_R);
        $xp->registerNamespace('rel', self::NS_REL);
        $xp->registerNamespace('ct', self::NS_CT);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('cp', self::NS_CP);
        $xp->registerNamespace('dc', self::NS_DC);
        $xp->registerNamespace('dcterms', self::NS_DCTERMS);

        return $xp;
    }

    /**
     * Relations d'une partie ('' = la racine du paquet), par identifiant,
     * cibles internes résolues en chemins du paquet.
     *
     * @return array<string, array{type: string, target: string, external: bool, raw: string}>
     * @throws OfficeTemplateException
     */
    public static function rels(ZipArchive $zip, string $part): array
    {
        $path = self::relsPath($part);
        if ($zip->locateName($path) === false) {
            return [];
        }
        $out = [];
        foreach (self::xpath(self::xml($zip, $path))->query('/rel:Relationships/rel:Relationship') as $rel) {
            $external = strtolower((string)$rel->getAttribute('TargetMode')) === 'external';
            $raw      = (string)$rel->getAttribute('Target');
            $out[(string)$rel->getAttribute('Id')] = [
                'type'     => (string)$rel->getAttribute('Type'),
                'target'   => $external ? $raw : self::resolve($part, $raw),
                'external' => $external,
                'raw'      => $raw,
            ];
        }

        return $out;
    }

    /** `word/document.xml` → `word/_rels/document.xml.rels` ; '' → `_rels/.rels`. */
    public static function relsPath(string $part): string
    {
        if ($part === '') {
            return '_rels/.rels';
        }
        $dir = dirname($part);

        return ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($part) . '.rels';
    }

    /**
     * Cible d'une relation → chemin du paquet. Confiné : une cible qui
     * remonte au-delà de la racine est refusée.
     *
     * @throws OfficeTemplateException
     */
    public static function resolve(string $source, string $target): string
    {
        if (str_starts_with($target, '/')) {
            $path = ltrim($target, '/');
        } else {
            $dir  = $source === '' ? '' : dirname($source);
            $path = ($dir === '.' || $dir === '' ? '' : $dir . '/') . $target;
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    throw new OfficeTemplateException(t('Ce fichier n’est pas un modèle Word lisible.'));
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }

    /** Chemin relatif d'une partie vers une autre (cible d'une relation). */
    public static function relative(string $from, string $to): string
    {
        $fromDir = explode('/', dirname($from));
        $toParts = explode('/', $to);
        while ($fromDir !== [] && $toParts !== [] && $fromDir[0] === $toParts[0]) {
            array_shift($fromDir);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromDir)) . implode('/', $toParts);
    }

    /**
     * Miniature enregistrée par Office dans le fichier (relation `thumbnail`
     * de la racine) : JPEG ou PNG seulement — un WMF ou un EMF ne s'affiche
     * pas dans un navigateur.
     *
     * @return array{bytes: string, ext: string}|null
     */
    public static function thumbnail(string $path): ?array
    {
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path) !== true) {
            return null;
        }
        try {
            foreach (self::rels($zip, '') as $rel) {
                if (!str_ends_with($rel['type'], '/thumbnail') || $rel['external']) {
                    continue;
                }
                $stat  = $zip->statName($rel['target']);
                if ($stat === false || (int)$stat['size'] > 5_000_000) {
                    return null;
                }
                $bytes = $zip->getFromName($rel['target']);
                if (!is_string($bytes) || $bytes === '') {
                    return null;
                }
                if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
                    return ['bytes' => $bytes, 'ext' => 'jpg'];
                }
                if (str_starts_with($bytes, "\x89PNG")) {
                    return ['bytes' => $bytes, 'ext' => 'png'];
                }

                return null;
            }

            return null;
        } catch (Throwable) {
            return null;
        } finally {
            $zip->close();
        }
    }

    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Texte d'une ligne, sans caractère interdit en XML. */
    public static function clean(string $text): string
    {
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
