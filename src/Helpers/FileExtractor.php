<?php

declare(strict_types=1);

class FileExtractor
{
    /**
     * Scripts (Windows, Unix, langages de script) : lus comme du TEXTE, jamais
     * exécutés — MIA ne lit que leur contenu. Source unique, partagée par la
     * détection (EXT_FORCED_MIME) et les sélecteurs de fichiers (chat, bases
     * de connaissances). Sans elle, libmagic classe un .bat qui commence par
     * `@echo off` en `text/x-msdos-batch` et l'envoi était refusé (« Type de
     * fichier non supporté »), alors qu'un .bat sans cette ligne passait.
     */
    private const SCRIPTS = [
        'bat' => 'text/plain', 'cmd' => 'text/plain',
        'ps1' => 'text/plain', 'psm1' => 'text/plain', 'psd1' => 'text/plain',
        'vbs' => 'text/plain',
        'sh' => 'text/plain', 'bash' => 'text/plain', 'zsh' => 'text/plain',
        'ksh' => 'text/plain', 'fish' => 'text/plain',
        'py' => 'text/plain', 'rb' => 'text/plain', 'pl' => 'text/plain',
        'lua' => 'text/plain',
    ];

    /**
     * Autres formats TEXTE reconnus à leur extension, lus tels quels : balisage,
     * données, configuration, sous-titres, agendas, contacts, différences,
     * langages de programmation. Comme pour les scripts, une extension texte
     * n'est crue que si le contenu en est (looksLikeText) — et un fichier texte
     * d'extension inconnue, ou sans extension, est reconnu à son CONTENU
     * (promoteText) : cette liste ne sert qu'à trancher quand libmagic hésite
     * et à garnir les sélecteurs de fichiers.
     */
    private const TEXT_FORMATS = [
        // Balisage, documentation
        'tex' => 'text/plain', 'latex' => 'text/plain', 'sty' => 'text/plain', 'bib' => 'text/plain',
        'rst' => 'text/plain', 'adoc' => 'text/plain', 'asciidoc' => 'text/plain', 'org' => 'text/plain',
        'textile' => 'text/plain', 'text' => 'text/plain', 'nfo' => 'text/plain',
        'svg' => 'text/plain', 'xsd' => 'text/plain', 'xsl' => 'text/plain', 'xslt' => 'text/plain',
        'dtd' => 'text/plain', 'wsdl' => 'text/plain', 'rss' => 'text/plain', 'atom' => 'text/plain',
        'opml' => 'text/plain', 'xliff' => 'text/plain', 'xlf' => 'text/plain', 'po' => 'text/plain',
        'pot' => 'text/plain', 'resx' => 'text/plain', 'strings' => 'text/plain',
        // Données
        'ndjson' => 'text/plain', 'jsonl' => 'text/plain', 'geojson' => 'text/plain', 'gpx' => 'text/plain',
        'kml' => 'text/plain', 'ipynb' => 'text/plain', 'plist' => 'text/plain',
        // Agendas, contacts, sous-titres, différences
        'ics' => 'text/plain', 'ifb' => 'text/plain', 'vcf' => 'text/plain', 'vcard' => 'text/plain',
        'srt' => 'text/plain', 'vtt' => 'text/plain', 'ass' => 'text/plain', 'ssa' => 'text/plain',
        'diff' => 'text/plain', 'patch' => 'text/plain',
        // Configuration
        'cfg' => 'text/plain', 'properties' => 'text/plain', 'tfvars' => 'text/plain', 'hcl' => 'text/plain',
        'tf' => 'text/plain', 'gitignore' => 'text/plain', 'dockerignore' => 'text/plain',
        'editorconfig' => 'text/plain', 'htaccess' => 'text/plain', 'npmrc' => 'text/plain',
        // Langages
        'swift' => 'text/plain', 'scala' => 'text/plain', 'groovy' => 'text/plain', 'gradle' => 'text/plain',
        'dart' => 'text/plain', 'r' => 'text/plain', 'jl' => 'text/plain', 'm' => 'text/plain',
        'mm' => 'text/plain', 'ex' => 'text/plain', 'exs' => 'text/plain', 'erl' => 'text/plain',
        'hrl' => 'text/plain', 'hs' => 'text/plain', 'clj' => 'text/plain', 'cljs' => 'text/plain',
        'lisp' => 'text/plain', 'el' => 'text/plain', 'vim' => 'text/plain', 'proto' => 'text/plain',
        'graphql' => 'text/plain', 'gql' => 'text/plain', 'mk' => 'text/plain', 'cmake' => 'text/plain',
        'asm' => 'text/plain', 's' => 'text/plain', 'vb' => 'text/plain', 'bas' => 'text/plain',
        'f90' => 'text/plain', 'pas' => 'text/plain', 'coffee' => 'text/plain', 'sass' => 'text/plain',
        'styl' => 'text/plain', 'vue' => 'text/plain', 'svelte' => 'text/plain', 'astro' => 'text/plain',
        'pug' => 'text/plain', 'twig' => 'text/plain', 'hbs' => 'text/plain', 'mustache' => 'text/plain',
        'ejs' => 'text/plain', 'erb' => 'text/plain', 'j2' => 'text/plain', 'jinja' => 'text/plain',
    ];

    /**
     * Types MIME de TEXTE hors de la famille text/* : un fichier qui les porte
     * (ou porte un type texte que MIA n'a pas de lecteur dédié pour) est lu
     * comme du texte brut, à condition que son contenu en soit
     * (isReadableText). Les suffixes +xml et +json valent aussi texte.
     */
    private const TEXTUAL_MIMES = [
        'application/json', 'application/ld+json', 'application/x-ndjson', 'application/xml',
        'application/x-yaml', 'application/yaml', 'application/toml', 'application/javascript',
        'application/x-javascript', 'application/ecmascript', 'application/x-sh', 'application/x-shellscript',
        'application/x-csh', 'application/x-perl', 'application/x-php', 'application/x-httpd-php',
        'application/x-python', 'application/x-ruby', 'application/x-tex', 'application/x-latex',
        'application/x-bibtex', 'application/x-subrip', 'application/sql', 'application/x-sql',
        'application/csv', 'application/x-wine-extension-ini', 'application/x-desktop',
        'application/x-ipynb+json',
    ];

    /** Étiquettes « type inconnu » : seul le contenu peut trancher. */
    private const GENERIC_MIMES = ['', 'application/octet-stream', 'application/unknown', 'binary/octet-stream'];

    /**
     * Étiquettes que libmagic pose sur un conteneur OLE (anciens Office) : la
     * bonne (application/msword, application/vnd.ms-excel), mais aussi des
     * génériques (CDFV2, x-ole-storage) selon sa version et le fichier. Les
     * flux du conteneur tranchent (LegacyOffice::kind).
     */
    private const OLE_MIMES = [
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.ms-outlook',
        'application/vnd.ms-office',
        'application/x-ole-storage',
        'application/CDFV2',
        'application/CDFV2-unknown',
        'application/CDFV2-corrupt',
        'application/octet-stream',
        '',
    ];

    /** Extensions d'un conteneur OLE : le conteneur est ouvert même si libmagic hésite. */
    private const OLE_EXTENSIONS = ['doc', 'dot', 'xls', 'xlt', 'ppt', 'pps', 'pot', 'msg'];

    /** Nature d'un conteneur OLE (LegacyOffice::kind) → type lu. */
    private const OLE_KINDS = [
        'doc' => LegacyOffice::MIME_DOC,
        'xls' => LegacyOffice::MIME_XLS,
        'ppt' => LegacyOffice::MIME_PPT,
        'msg' => MailFile::MIME_MSG,
    ];

    /**
     * Extensions → MIME canonique forcé (cf. detectMime). Couvre les formats où
     * libmagic est ambigu : conteneurs ZIP (OOXML/ODF/EPUB) et fichiers texte
     * (code, données structurées) souvent vus comme application/zip ou text/plain.
     */
    private const EXT_FORCED_MIME = [
        ...self::SCRIPTS,
        ...self::TEXT_FORMATS,
        // Web / texte enrichi
        'html' => 'text/html', 'htm' => 'text/html',
        'rtf'  => 'application/rtf',
        // Données structurées + code → lus comme texte brut (chunkables tels quels)
        'csv' => 'text/plain', 'tsv' => 'text/plain', 'json' => 'text/plain',
        'xml' => 'text/plain', 'yaml' => 'text/plain', 'yml' => 'text/plain',
        'log' => 'text/plain', 'ini' => 'text/plain', 'conf' => 'text/plain',
        'toml' => 'text/plain', 'env' => 'text/plain',
        'php' => 'text/plain', 'js' => 'text/plain', 'mjs' => 'text/plain',
        'ts' => 'text/plain', 'jsx' => 'text/plain', 'tsx' => 'text/plain',
        'py' => 'text/plain', 'rb' => 'text/plain', 'go' => 'text/plain',
        'rs' => 'text/plain', 'java' => 'text/plain', 'kt' => 'text/plain',
        'c' => 'text/plain', 'cpp' => 'text/plain', 'cc' => 'text/plain',
        'h' => 'text/plain', 'hpp' => 'text/plain', 'cs' => 'text/plain',
        'sh' => 'text/plain', 'bash' => 'text/plain', 'sql' => 'text/plain',
        'css' => 'text/plain', 'scss' => 'text/plain', 'less' => 'text/plain',
        // OOXML présentation + OpenDocument + EPUB (conteneurs ZIP)
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'epub' => 'application/epub+zip',
        'eml'  => MailFile::MIME_EML,
    ];

    /**
     * Détecte le type MIME d'un fichier uploadé de façon robuste.
     *
     * `mime_content_type()` s'appuie sur libmagic et classifie souvent les DOCX
     * (et parfois XLSX) comme `application/zip` parce qu'ils reposent sur le
     * conteneur ZIP. Cette méthode :
     *   1. détecte via libmagic,
     *   2. si ZIP ou octet-stream, inspecte le contenu pour trouver le marqueur
     *      OOXML (`word/document.xml` ou `xl/worksheets/sheet*.xml`),
     *   3. en dernier recours, se rabat sur l'extension du nom fourni.
     */
    public static function detectMime(string $path, string $clientName = ''): string
    {
        $clientExt = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        // Markdown : libmagic le rapporte presque toujours comme text/plain.
        // On force text/markdown quand l'extension est explicite, pour distinguer
        // du txt brut côté UI/extraction.
        if (in_array($clientExt, ['md', 'markdown'], true)) {
            return 'text/markdown';
        }

        // Extension fiable (upload admin) + libmagic ambigu → MIME canonique forcé.
        // Un fichier « texte » par son extension doit l'être aussi par son
        // contenu : un exécutable renommé en .bat retombe sur le verdict de
        // libmagic (et se fait refuser) au lieu d'être lu comme du texte.
        if (isset(self::EXT_FORCED_MIME[$clientExt])) {
            $forced = self::EXT_FORCED_MIME[$clientExt];
            if (!in_array($forced, ['text/plain', MailFile::MIME_EML], true) || self::looksLikeText($path)) {
                return $forced;
            }
        }

        $mime = (string)(@mime_content_type($path) ?: '');

        // Word, Excel et PowerPoint 97-2003, emails Outlook : d'après les flux
        // du conteneur, pas l'étiquette — un .doc renommé .xls se lit pour ce
        // qu'il est.
        if (in_array($mime, self::OLE_MIMES, true) || in_array($clientExt, self::OLE_EXTENSIONS, true)) {
            $legacy = self::OLE_KINDS[LegacyOffice::kind($path) ?? ''] ?? null;
            if ($legacy !== null) {
                return $legacy;
            }
        }

        $ambiguous = [
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            '',
        ];
        if (in_array($mime, $ambiguous, true)) {
            $refined = self::detectOfficeOpenXmlMime($path);
            if ($refined !== null) {
                return $refined;
            }
            // Une vraie archive ZIP (ni Office, ni OpenDocument, ni EPUB) : son contenu se lit.
            if ($mime !== '' && $mime !== 'application/octet-stream') {
                return ArchiveReader::MIME;
            }
        }

        if ($mime === '' || $mime === 'application/octet-stream') {
            $byExt = [
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf'  => 'application/pdf',
                'txt'  => 'text/plain',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];
            if (isset($byExt[$clientExt])) {
                return $byExt[$clientExt];
            }
        }

        return self::promoteText($path, $mime);
    }

    /**
     * Un fichier que MIA n'a pas de lecteur pour, mais dont le CONTENU est du
     * texte, se lit comme du texte brut — quelle que soit son extension, et
     * même sans extension (journal, export, code, iCalendar, vCard, LaTeX…).
     * Le type annoncé ne décide que du droit d'essayer (texte, +xml, +json,
     * ou type inconnu) ; le contenu tranche. Un binaire n'est jamais « lu »
     * comme du texte : il en sortirait une bouillie qui coûte et égare.
     * Les emails (message/*) attendent leur lecteur dédié.
     */
    private static function promoteText(string $path, string $mime): string
    {
        if (self::isExtractable($mime) || str_starts_with($mime, 'message/')) {
            return $mime;
        }
        if ($mime === 'application/xhtml+xml') {
            return 'text/html';
        }
        if ((in_array($mime, self::GENERIC_MIMES, true) || self::isTextualMime($mime)) && self::isReadableTextFile($path)) {
            return 'text/plain';
        }
        return $mime;
    }

    /** Un lecteur de MIA sait-il tirer le contenu de ce type ? Miroir d'extract(). */
    public static function isExtractable(string $mime): bool
    {
        return in_array($mime, [
            'text/plain', 'text/markdown', 'text/html', 'application/rtf', 'text/rtf',
            LegacyOffice::MIME_DOC, LegacyOffice::MIME_XLS, LegacyOffice::MIME_PPT,
            MailFile::MIME_EML, MailFile::MIME_MSG, ArchiveReader::MIME, 'application/epub+zip', 'application/pdf',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        ], true)
            || str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'spreadsheetml')
            || str_contains($mime, 'presentationml')
            || str_contains($mime, 'opendocument');
    }

    /**
     * Ce type annonce-t-il du texte ? text/* (hors HTML et RTF, qui ont leur
     * lecteur), les types texte de TEXTUAL_MIMES, les suffixes +xml et +json.
     * Pure : le contenu doit encore le confirmer (isReadableText).
     */
    public static function isTextualMime(string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime === '' || str_starts_with($mime, 'message/')) {
            return false;
        }
        return str_starts_with($mime, 'text/')
            || in_array($mime, self::TEXTUAL_MIMES, true)
            || str_ends_with($mime, '+xml')
            || str_ends_with($mime, '+json');
    }

    /**
     * Ces octets sont-ils du texte qu'un modèle peut lire ? UTF-16 reconnu ;
     * sinon aucun octet nul et presque aucun caractère de contrôle (1 % au
     * plus : un journal peut porter des codes de couleur). Un binaire (archive,
     * image, exécutable) porte des octets nuls dès ses premiers kilo-octets.
     * Pure.
     */
    public static function isReadableText(string $sample): bool
    {
        if ($sample === '') {
            return false;
        }
        if (self::utf16Order($sample) !== null) {
            return true;
        }
        if (str_contains($sample, "\0")) {
            return false;
        }
        $controls = preg_match_all('/[\x01-\x08\x0E-\x1A\x1C-\x1F\x7F]/', $sample);
        return $controls <= max(2, (int)floor(strlen($sample) * 0.01));
    }

    /** isReadableText sur le début d'un fichier (64 Ko). */
    public static function isReadableTextFile(string $path): bool
    {
        $sample = @file_get_contents($path, false, null, 0, 65536);
        return is_string($sample) && self::isReadableText($sample);
    }

    /**
     * Extensions de documents reconnues quand un service n'annonce qu'un type
     * générique (pièce jointe en `application/octet-stream`, fichier Drive).
     */
    private const DOCUMENT_EXTENSIONS = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc'  => LegacyOffice::MIME_DOC,
        'xls'  => LegacyOffice::MIME_XLS,
        'ppt'  => LegacyOffice::MIME_PPT, 'pps' => LegacyOffice::MIME_PPT,
        'msg'  => MailFile::MIME_MSG,
        'eml'  => MailFile::MIME_EML,
        'zip'  => ArchiveReader::MIME,
        'md'   => 'text/markdown', 'markdown' => 'text/markdown',
        'txt'  => 'text/plain',
    ];

    /** Types « à télécharger » que certains serveurs annoncent à la place du vrai. */
    private const DOWNLOAD_MIMES = ['application/x-download', 'application/download', 'application/force-download'];

    /**
     * Extensions de binaires qu'aucun lecteur ne lit : sous un type générique,
     * on ne les tente pas en texte (le refus nomme le format).
     */
    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'ico',
        '7z', 'rar', 'gz', 'tgz', 'bz2', 'xz', 'tar',
        'exe', 'dll', 'msi', 'dmg', 'pkg', 'apk', 'iso', 'bin',
        'mp3', 'wav', 'm4a', 'ogg', 'flac', 'mp4', 'mov', 'avi', 'mkv', 'webm',
        'key', 'pages', 'numbers',
    ];

    /**
     * Comment lire un fichier qu'un SERVICE décrit (pièce jointe d'email,
     * fichier Drive, page web) par son type et son nom, avant d'en avoir les
     * octets : le type à passer à extract(), ou null s'il ne se lit pas (image,
     * archive, binaire connu). Pure.
     *
     *  - un type que MIA lit (Office, OpenDocument, EPUB, PDF, RTF, HTML,
     *    Markdown, texte) se lit tel quel ;
     *  - un type texte sans lecteur dédié (CSV, JSON, XML, code, iCalendar…)
     *    se lit en text/plain ;
     *  - un type GÉNÉRIQUE (octet-stream, vide) suit l'extension, et à défaut
     *    se TENTE en text/plain : le contenu tranche (`isReadableText`).
     *
     * Quand la réponse est text/plain, l'appelant DOIT vérifier les octets
     * (`isReadableText`) : un binaire n'est jamais « lu » comme du texte.
     */
    public static function readerFor(string $mime, string $name = ''): ?string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === 'application/xhtml+xml') {
            return 'text/html';
        }
        if (in_array($mime, self::GENERIC_MIMES, true) || in_array($mime, self::DOWNLOAD_MIMES, true)) {
            $byExt = self::DOCUMENT_EXTENSIONS[$ext] ?? self::EXT_FORCED_MIME[$ext] ?? null;
            if ($byExt !== null) {
                return $byExt;
            }
            return in_array($ext, self::BINARY_EXTENSIONS, true) ? null : 'text/plain';
        }
        if (str_starts_with($mime, 'image/')) {
            return null;
        }
        if ($mime === 'text/rtf') {
            return 'application/rtf';
        }
        if (self::isExtractable($mime)) {
            return $mime;
        }
        if (self::isTextualMime($mime) || self::hasTextExtension($name)) {
            return 'text/plain';
        }
        return null;
    }

    /**
     * Ce nom de fichier porte-t-il une extension de texte (scripts, données,
     * code, balisage…) ? Pour les sources qui ne donnent qu'un type générique
     * (pièces jointes d'email, Drive) : le contenu tranche ensuite.
     */
    public static function hasTextExtension(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['txt', 'md', 'markdown'], true)
            || (self::EXT_FORCED_MIME[$ext] ?? null) === 'text/plain';
    }

    /**
     * Extensions des fichiers lus comme du texte (scripts, données, code,
     * balisage…), pour les sélecteurs de fichiers. Même liste que la
     * détection : ce qu'un sélecteur propose, le serveur l'accepte.
     *
     * @return list<string>
     */
    public static function textExtensions(): array
    {
        $out = [];
        foreach (self::EXT_FORCED_MIME as $ext => $mime) {
            if ($mime === 'text/plain') {
                $out[] = '.' . $ext;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Refus présentable d'un fichier que MIA ne sait pas lire : le format reçu,
     * et ce qu'elle lit. Partagé par le chat, les workflows, les bases et les
     * documents d'assistant.
     */
    public static function unsupportedMessage(string $name, string $mime): string
    {
        if (in_array($mime, ['application/x-empty', 'inode/x-empty'], true)) {
            return t('« {nom} » est vide.', ['nom' => $name]);
        }
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type = $ext !== '' ? '.' . $ext : ($mime !== '' ? $mime : t('type inconnu'));
        return t('MIA ne sait pas lire « {nom} » ({type}). Elle lit les PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, RTF, pages web, emails (.eml, .msg), archives ZIP, images et tout fichier texte (code, données, journaux…).', [
            'nom'  => $name,
            'type' => $type,
        ]);
    }

    /** Type MIME d'un PDF et d'une présentation PowerPoint, clés de UPLOAD_LIMITS. */
    public const MIME_PDF  = 'application/pdf';
    public const MIME_PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /**
     * Poids maximal d'un fichier joint (chat, départ d'un workflow), selon sa
     * nature ; tout autre type est borné à UPLOAD_MAX_SIZE.
     *
     *  - PDF : 20 Mo. Il part ENTIER au modèle (images et mise en page
     *    comprises), encodé en base64 dans le message : un tiers de plus à
     *    stocker, à garder en mémoire et à envoyer à chaque tour. Plus lourd,
     *    il dépasserait les 64 Mo qu'accepte MySQL en une écriture et la
     *    mémoire de PHP.
     *  - PowerPoint (.pptx) : 50 Mo. Seul son texte est lu, les images restent
     *    sur le disque : le poids du fichier ne pèse sur rien d'autre.
     *
     * Le plafond PHP (upload_max_filesize, Dockerfile) doit rester AU MOINS
     * égal au plus grand d'entre eux, post_max_size un peu au-dessus.
     */
    public const UPLOAD_LIMITS = [
        self::MIME_PDF  => 20 * 1024 * 1024,
        self::MIME_PPTX => 50 * 1024 * 1024,
    ];

    public static function uploadLimit(string $mime): int
    {
        return self::UPLOAD_LIMITS[$mime] ?? UPLOAD_MAX_SIZE;
    }

    public static function largestUploadLimit(): int
    {
        return max(UPLOAD_MAX_SIZE, ...array_values(self::UPLOAD_LIMITS));
    }

    /** Nature d'un fichier pour sa limite, d'après son type ou à défaut son extension. */
    public static function uploadKind(string $mime, string $name = ''): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === self::MIME_PDF || ($mime === '' && $ext === 'pdf')) {
            return 'pdf';
        }
        if ($mime === self::MIME_PPTX || ($mime === '' && $ext === 'pptx')) {
            return 'pptx';
        }
        return 'other';
    }

    /** « 20 Mo » : une limite s'écrit sans décimale. */
    public static function limitLabel(int $bytes): string
    {
        return preg_replace('/[,.]0 /', ' ', Format::sizeMo($bytes)) ?? Format::sizeMo($bytes);
    }

    /**
     * Refus présentable d'un fichier trop lourd : ce qu'il pèse, la limite de
     * SA nature et pourquoi elle existe, et quoi faire. $size null = poids
     * inconnu (fichier arrêté par PHP avant d'arriver).
     */
    public static function tooLargeMessage(string $name, string $mime, ?int $size): string
    {
        $kind = self::uploadKind($mime, $name);
        $head = $size !== null && $size > 0
            ? t('« {nom} » pèse {taille} :', ['nom' => $name, 'taille' => Format::sizeMo($size)])
            : t('« {nom} » est trop lourd :', ['nom' => $name]);
        return $head . ' ' . self::tooLargeRules()[$kind];
    }

    /**
     * Explication de la limite, par nature (pdf, pptx, other). Partagée avec le
     * navigateur (clientUploadLimits), qui refuse un fichier trop lourd avant
     * de l'envoyer.
     *
     * @return array{pdf: string, pptx: string, other: string}
     */
    public static function tooLargeRules(): array
    {
        return [
            'pdf' => t('un PDF ne peut pas dépasser {max}. MIA envoie le PDF entier au modèle, pour qu’il en voie aussi les images et la mise en page : plus lourd, il ne passerait pas. Réduisez sa taille (fonction « Réduire la taille » ou « Optimiser » de votre lecteur PDF) ou découpez-le en plusieurs parties.', [
                'max' => self::limitLabel(self::UPLOAD_LIMITS[self::MIME_PDF]),
            ]),
            'pptx' => t('une présentation PowerPoint ne peut pas dépasser {max}. Compressez ses images (dans PowerPoint : Fichier, puis Compresser les images) ou retirez-en les vidéos, puis joignez-la à nouveau.', [
                'max' => self::limitLabel(self::UPLOAD_LIMITS[self::MIME_PPTX]),
            ]),
            'other' => t('ce type de fichier ne peut pas dépasser {max} ({pdf} pour un PDF, {pptx} pour une présentation PowerPoint). Réduisez-le ou découpez-le en plusieurs parties.', [
                'max'  => self::limitLabel(UPLOAD_MAX_SIZE),
                'pdf'  => self::limitLabel(self::UPLOAD_LIMITS[self::MIME_PDF]),
                'pptx' => self::limitLabel(self::UPLOAD_LIMITS[self::MIME_PPTX]),
            ]),
        ];
    }

    /**
     * Limites et messages pour le navigateur (window.FILE_UPLOAD_LIMITS) : il
     * refuse un fichier trop lourd AVANT de l'envoyer — inutile de faire
     * monter 80 Mo pour se l'entendre dire. Le serveur reste juge.
     *
     * @return array<string, mixed>
     */
    public static function clientUploadLimits(): array
    {
        return [
            'limits' => [
                'pdf'   => self::UPLOAD_LIMITS[self::MIME_PDF],
                'pptx'  => self::UPLOAD_LIMITS[self::MIME_PPTX],
                'other' => UPLOAD_MAX_SIZE,
            ],
            'head'  => t('« {nom} » pèse {taille} :'),
            'rules' => self::tooLargeRules(),
            'unit'  => I18n::locale() === 'en' ? 'MB' : 'Mo',
            'decimal' => I18n::locale() === 'en' ? '.' : ',',
        ];
    }

    /**
     * Extensions des scripts lus comme du texte (`.bat`, `.ps1`…), pour les
     * sélecteurs de fichiers. Même liste que la détection : ce qu'un
     * sélecteur propose, le serveur l'accepte.
     *
     * @return list<string>
     */
    public static function scriptExtensions(): array
    {
        return array_map(static fn(string $ext): string => '.' . $ext, array_keys(self::SCRIPTS));
    }

    /**
     * Le contenu est-il du texte ? Un octet nul n'apparaît dans aucun texte
     * en UTF-8 ni en Windows-1252 ; il est en revanche partout en UTF-16, que
     * Windows emploie volontiers (PowerShell ISE, Bloc-notes « Unicode ») :
     * BOM UTF-16, ou octets nuls d'un seul côté des paires, valent texte.
     * Fichier illisible : rien ne s'y oppose (le reste de l'envoi tranchera).
     */
    public static function looksLikeText(string $path): bool
    {
        $sample = @file_get_contents($path, false, null, 0, 8192);
        if (!is_string($sample) || !str_contains($sample, "\0")) {
            return true;
        }
        return self::utf16Order($sample) !== null;
    }

    /**
     * Ordre des octets d'un texte UTF-16 (BOM, sinon octets nuls réguliers
     * d'un côté des paires — un texte latin en UTF-16 sans BOM), ou null.
     */
    private static function utf16Order(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return 'UTF-16BE';
        }
        $pairs = intdiv(strlen($bytes), 2);
        if ($pairs < 2) {
            return null;
        }
        $evenNul = 0;
        $oddNul  = 0;
        for ($i = 0; $i < $pairs * 2; $i += 2) {
            if ($bytes[$i] === "\0") {
                $evenNul++;
            }
            if ($bytes[$i + 1] === "\0") {
                $oddNul++;
            }
        }
        // Texte latin : l'octet de poids fort est nul presque partout, l'autre
        // jamais. Un binaire a des nuls des deux côtés.
        if ($oddNul >= 0.6 * $pairs && $evenNul <= 0.05 * $pairs) {
            return 'UTF-16LE';
        }
        if ($evenNul >= 0.6 * $pairs && $oddNul <= 0.05 * $pairs) {
            return 'UTF-16BE';
        }
        return null;
    }

    /**
     * Texte d'un fichier lu tel quel, rendu en UTF-8 valide : BOM UTF-8 retiré,
     * UTF-16 décodé, sinon Windows-1252 (le « ANSI » du Bloc-notes français)
     * quand les octets ne sont pas de l'UTF-8 ; fins de ligne Windows ramenées
     * à « \n ». Un texte non converti ferait échouer l'encodage JSON du
     * message (fichiers joints) ou l'écriture des extraits (bases).
     */
    public static function decodeText(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (($order = self::utf16Order(substr($raw, 0, 8192))) !== null) {
            if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
                $raw = substr($raw, 2);
            }
            $decoded = @mb_convert_encoding($raw, 'UTF-8', $order);
            $raw = is_string($decoded) ? $decoded : '';
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $raw = is_string($converted) ? $converted : mb_scrub($raw, 'UTF-8');
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        // Octets nuls et contrôles invisibles : rien à lire, et MySQL comme
        // json_encode s'en passent volontiers. Tabulations et sauts gardés.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? $raw;
    }

    private static function extractPlainText(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $text = self::decodeText($raw);
        return $text !== '' ? $text : null;
    }

    private static function detectOfficeOpenXmlMime(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $hasDoc   = $zip->locateName('word/document.xml') !== false;
        $hasSheet = false;
        $hasPpt   = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n === false) {
                continue;
            }
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $n)) {
                $hasSheet = true;
            } elseif (preg_match('#^ppt/slides/slide[0-9]+\.xml$#', $n)) {
                $hasPpt = true;
            }
        }
        // ODF / EPUB : le fichier `mimetype` (premier membre du ZIP) porte le MIME exact.
        $odfMime = $zip->getFromName('mimetype');
        $zip->close();

        if ($hasDoc)   return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        if ($hasSheet) return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if ($hasPpt)   return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
        if (is_string($odfMime)) {
            $odfMime = trim($odfMime);
            if (str_starts_with($odfMime, 'application/vnd.oasis.opendocument.')
                || $odfMime === 'application/epub+zip') {
                return $odfMime;
            }
        }
        return null;
    }

    /**
     * Extensions à proposer dans un sélecteur de fichiers, par type MIME accepté
     * (cf. acceptAttribute). Un type absent d'ici est proposé par son MIME seul.
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg'      => ['.jpg', '.jpeg'],
        'image/png'       => ['.png'],
        'image/gif'       => ['.gif'],
        'image/webp'      => ['.webp'],
        'application/pdf' => ['.pdf'],
        'text/plain'      => ['.txt'],
        'text/markdown'   => ['.md'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['.docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['.xlsx'],
        LegacyOffice::MIME_DOC => ['.doc'],
        LegacyOffice::MIME_XLS => ['.xls'],
        LegacyOffice::MIME_PPT => ['.ppt', '.pps'],
        MailFile::MIME_EML     => ['.eml'],
        MailFile::MIME_MSG     => ['.msg'],
        ArchiveReader::MIME    => ['.zip'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['.pptx'],
        'application/vnd.oasis.opendocument.text'         => ['.odt'],
        'application/vnd.oasis.opendocument.spreadsheet'  => ['.ods'],
        'application/vnd.oasis.opendocument.presentation' => ['.odp'],
        'application/epub+zip' => ['.epub'],
        'text/html'            => ['.html', '.htm'],
        'application/rtf'      => ['.rtf'],
    ];

    /**
     * Valeur de l'attribut `accept` d'un <input type="file"> pour une liste de
     * types MIME acceptés par le serveur (UPLOAD_ALLOWED_TYPES) — pure, testée.
     * Un seul endroit décide de ce que le sélecteur propose, et il suit la liste
     * du serveur : un type ajouté côté serveur apparaît dans le sélecteur.
     *
     * Forme : les types MIME d'abord, IMAGES EN TÊTE, puis les extensions.
     *
     *  - Des types MIME, pas des extensions seules : la WebView Android convertit
     *    les extensions par une table figée (`.md` en est absent) et Chrome
     *    Android par celle du système (qui ignore `md` avant Android 10) — un
     *    type déclaré en MIME est compris partout.
     *  - Images EN TÊTE, et ce n'est pas cosmétique : dans l'application Android
     *    (WebView Capacitor), le PREMIER type MIME devient le type de l'intent
     *    système ACTION_GET_CONTENT et décide des applications proposées. Un
     *    type document en tête (application/pdf) n'appelle que les explorateurs
     *    de fichiers — la galerie et le sélecteur de photos, qui ne déclarent
     *    que image/* et video/*, n'apparaissent pas. Un type image en tête fait
     *    venir galerie ET explorateurs (leur filtre * / * accepte tout), les
     *    autres types restant portés par EXTRA_MIME_TYPES. Les autres moteurs
     *    ignorent l'ordre : Chrome Android ouvre toujours un sélecteur générique,
     *    iOS une feuille Photothèque / Fichiers, un navigateur de bureau un
     *    filtre.
     *  - Jamais `image/*` (un joker est écarté) : sur iOS, WebKit livre alors les
     *    photos HEIC dans leur format d'origine au lieu de les convertir en
     *    JPEG — format que le serveur refuse.
     *  - Les extensions en plus : les sélecteurs de bureau (Windows, macOS)
     *    filtrent par extension.
     *
     * @param list<string> $allowedMimes
     */
    public static function acceptAttribute(array $allowedMimes): string
    {
        $mimes = [];
        foreach ($allowedMimes as $mime) {
            $mime = strtolower(trim((string)$mime));
            if ($mime === '' || str_contains($mime, '*') || in_array($mime, $mimes, true)) {
                continue;
            }
            $mimes[] = $mime;
        }
        $isImage = static fn(string $m): bool => str_starts_with($m, 'image/');
        $ordered = array_merge(
            array_values(array_filter($mimes, $isImage)),
            array_values(array_filter($mimes, static fn(string $m): bool => !$isImage($m))),
        );
        $extensions = [];
        foreach ($ordered as $mime) {
            $mimeExtensions = self::MIME_EXTENSIONS[$mime] ?? [];
            if ($mime === 'text/plain') {
                // Scripts, données, code, balisage sont lus comme du texte :
                // accepter le texte, c'est les accepter (un sélecteur de
                // bureau filtre par extension). Un fichier texte d'extension
                // inconnue reste accepté par glisser-déposer : le serveur le
                // reconnaît à son contenu.
                $mimeExtensions = array_merge($mimeExtensions, self::textExtensions());
            }
            foreach ($mimeExtensions as $ext) {
                if (!in_array($ext, $extensions, true)) {
                    $extensions[] = $ext;
                }
            }
        }
        return implode(',', array_merge($ordered, $extensions));
    }

    public static function extract(string $filePath, string $mimeType): ?string
    {
        return match(true) {
            $mimeType === 'text/plain',
            $mimeType === 'text/markdown'                   => self::extractPlainText($filePath),
            $mimeType === 'text/html'                       => self::extractHtml($filePath),
            $mimeType === 'application/rtf',
            $mimeType === 'text/rtf'                         => self::extractRtf($filePath),
            $mimeType === LegacyOffice::MIME_DOC            => self::cleaned(LegacyOffice::docText($filePath)),
            $mimeType === LegacyOffice::MIME_XLS            => self::cleaned(LegacyOffice::xlsText($filePath)),
            $mimeType === LegacyOffice::MIME_PPT            => self::cleaned(LegacyOffice::pptText($filePath)),
            $mimeType === MailFile::MIME_EML                => self::cleaned(MailFile::emlText($filePath)),
            $mimeType === MailFile::MIME_MSG                => self::cleaned(MailFile::msgText($filePath)),
            $mimeType === ArchiveReader::MIME               => self::cleaned(ArchiveReader::zipText($filePath)),
            str_contains($mimeType, 'wordprocessingml')     => self::extractDocx($filePath),
            str_contains($mimeType, 'spreadsheetml')        => self::extractXlsx($filePath),
            str_contains($mimeType, 'presentationml')       => self::extractPptx($filePath),
            str_contains($mimeType, 'opendocument')         => self::extractOpenDocument($filePath),
            $mimeType === 'application/epub+zip'            => self::extractEpub($filePath),
            $mimeType === 'application/pdf'                 => self::extractPdf($filePath),
            // Images : envoyées directement à l'API, pas d'extraction texte
            default => null,
        };
    }

    private static function cleaned(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = self::cleanText($text);
        return $text !== '' ? $text : null;
    }

    private static function extractHtml(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        return self::cleanText(self::htmlToText($raw));
    }

    /** HTML (corps d'un email) → texte nettoyé. */
    public static function htmlToPlain(string $html): string
    {
        return self::cleanText(self::htmlToText($html));
    }

    /** RTF (corps d'un email Outlook) → texte nettoyé, même lecture que les fichiers .rtf. */
    public static function rtfToPlain(string $rtf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mia_rtf_');
        if ($tmp === false || @file_put_contents($tmp, $rtf) === false) {
            return '';
        }
        try {
            return (string)self::extractRtf($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Convertit un fragment HTML en texte : retire script/style, garde les sauts. */
    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/table)\s*>#i', "\n", $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extraction texte d'un RTF (best-effort, sans dépendance) : décode les
     * octets \'hh (codepage Windows-1252), supprime groupes et mots de contrôle.
     */
    private static function extractRtf(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        // Groupes ignorables {\*\... } (polices, infos) → supprimés.
        $raw = preg_replace('/\{\\\\\*[^{}]*\}/s', ' ', $raw) ?? $raw;
        // \'hh → octet (codepage), converti ensuite en UTF-8.
        $raw = preg_replace_callback('/\\\\\'([0-9a-fA-F]{2})/', static fn(array $m): string => chr(hexdec($m[1])), $raw) ?? $raw;
        // Sauts de paragraphe/ligne.
        $raw = preg_replace('/\\\\(par|line|sect)\b ?/', "\n", $raw) ?? $raw;
        // Autres mots de contrôle \word, \word123 + accolades restantes.
        $raw = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $raw) ?? $raw;
        $raw = str_replace(['{', '}'], '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                $raw = $converted;
            }
        }
        return self::cleanText($raw);
    }

    /** PPTX : concatène le texte (<a:t>) de chaque diapositive, dans l'ordre. */
    private static function extractPptx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && preg_match('#^ppt/slides/slide[0-9]+\.xml$#', $n)) {
                $slides[] = $n;
            }
        }
        sort($slides, SORT_NATURAL);

        $out = [];
        foreach ($slides as $n) {
            $xml = $zip->getFromName($n);
            if ($xml === false) {
                continue;
            }
            if (preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $m)) {
                $txt = implode(' ', array_map(
                    static fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    $m[1]
                ));
                if (trim($txt) !== '') {
                    $out[] = $txt;
                }
            }
        }
        $zip->close();

        return $out ? self::cleanText(implode("\n\n", $out)) : null;
    }

    /** OpenDocument (ODT/ODS/ODP) : texte de content.xml, balises retirées. */
    private static function extractOpenDocument(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return null;
        }
        $xml = preg_replace('#</text:(p|h)>|</table:table-row>#', "\n", $xml) ?? $xml;
        $xml = str_replace('</table:table-cell>', "\t", $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::cleanText($text);
    }

    /** EPUB : concatène le texte des chapitres XHTML, dans l'ordre des noms. */
    private static function extractEpub(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && preg_match('#\.(xhtml|html|htm)$#i', $n)) {
                $files[] = $n;
            }
        }
        sort($files, SORT_NATURAL);

        $out = [];
        foreach ($files as $n) {
            $html = $zip->getFromName($n);
            if ($html === false) {
                continue;
            }
            $t = self::htmlToText($html);
            if (trim($t) !== '') {
                $out[] = $t;
            }
        }
        $zip->close();

        return $out ? self::cleanText(implode("\n\n", $out)) : null;
    }

    private static function extractDocx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) return null;

        $xml = str_replace(['</w:r>', '</w:p>'], [' ', "\n"], $xml);
        return self::cleanText(strip_tags($xml));
    }

    /**
     * Extraction texte depuis un XLSX (format Office Open XML).
     * Parcourt toutes les feuilles, résout les chaînes partagées (sharedStrings.xml),
     * produit un rendu tabulaire ligne par ligne, feuille par feuille.
     */
    private static function extractXlsx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;

        // Chaînes partagées
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = @simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    // Concaténation de toutes les runs de texte dans l'élément <si>
                    $text = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                        $text .= (string)$t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Feuilles
        $pieces = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                continue;
            }
            $sheetXml = $zip->getFromName($name);
            if ($sheetXml === false) continue;

            $sheet = @simplexml_load_string($sheetXml);
            if ($sheet === false) continue;

            $rows = [];
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c ?? [] as $c) {
                    $type  = (string)($c['t'] ?? '');
                    $value = '';
                    if (isset($c->v)) {
                        $raw = (string)$c->v;
                        if ($type === 's') {
                            $idx = (int)$raw;
                            $value = $sharedStrings[$idx] ?? '';
                        } elseif ($type === 'b') {
                            $value = $raw === '1' ? 'TRUE' : 'FALSE';
                        } else {
                            $value = $raw;
                        }
                    } elseif (isset($c->is)) {
                        // Inline string
                        foreach ($c->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $value .= (string)$t;
                        }
                    }
                    $value = str_replace(["\t", "\r", "\n"], ' ', $value);
                    $cells[] = $value;
                }
                if ($cells) {
                    $rows[] = implode("\t", $cells);
                }
            }
            if ($rows) {
                $label = preg_replace('#^xl/worksheets/#', '', $name);
                $pieces[] = '# ' . $label . "\n" . implode("\n", $rows);
            }
        }
        $zip->close();

        if (!$pieces) return null;
        return self::cleanText(implode("\n\n", $pieces));
    }

    /**
     * Extraction texte d'un PDF.
     * Essaie dans l'ordre :
     *   1. la lib PHP `smalot/pdfparser` (gère les encodages + CMap),
     *   2. le binaire `pdftotext` (poppler-utils) s'il est disponible,
     *   3. un fallback natif best-effort (ASCII seulement, perd les accents).
     */
    private static function extractPdf(string $path): ?string
    {
        $text = self::extractPdfViaSmalot($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }

        $text = self::extractPdfViaPdftotext($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }

        $text = self::extractPdfFallback($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }
        return null;
    }

    private static function extractPdfViaSmalot(string $path): ?string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }
        try {
            // Ni les images (elles ne portent aucun texte : un scan de vingt
            // pages, gardé et décompressé en mémoire, dépasse les 256 Mo de
            // PHP — erreur fatale que rien ne rattrape), ni un flux décompressé
            // au-delà de la borne du repli.
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(self::PDF_STREAM_INFLATE_MAX);
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf    = $parser->parseFile($path);
            $text   = $pdf->getText();
            return is_string($text) && $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            error_log('[FileExtractor] smalot/pdfparser a échoué : ' . $e->getMessage());
            return null;
        }
    }

    private static function extractPdfViaPdftotext(string $path): ?string
    {
        // Cherche le binaire; désactivé si exec/proc_open est interdit.
        if (!function_exists('proc_open')) return null;

        $which = @shell_exec('command -v pdftotext 2>/dev/null');
        if (!$which || trim((string)$which) === '') return null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = 'pdftotext -layout -nopgbrk ' . escapeshellarg($path) . ' -';
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) return null;

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $stdout !== '' ? $stdout : null;
    }

    /**
     * Fallback natif PHP : extrait les chaînes encapsulées dans les flux PDF
     * (opérateurs Tj, TJ). Pas parfait pour les PDF complexes / compressés lourds,
     * mais suffisant pour la majorité des PDF texte bureautiques.
     *
     * Lu FLUX PAR FLUX, jamais d'un bloc : c'est le dernier recours, appelé
     * justement quand les deux autres n'ont trouvé aucun texte — un PDF
     * scanné, fait d'images. L'ancienne version décompressait TOUS les flux
     * FlateDecode d'un coup dans une copie du fichier : une page A4 numérisée
     * pèse 26 Mo une fois décompressée, et un scan de quelques pages épuisait
     * les 256 Mo de PHP (erreur fatale, envoi en échec 500, constaté chez
     * un client le 2026-10-29). Les images, polices et index ne contiennent
     * jamais de texte : ils sont écartés AVANT d'être recopiés, et ce qui
     * reste est décompressé dans des bornes (PDF_STREAM_INFLATE_MAX par flux,
     * PDF_FALLBACK_BUDGET en tout).
     */
    private static function extractPdfFallback(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;

        $out = [];
        $budget = self::PDF_FALLBACK_BUDGET;
        foreach (self::pdfTextStreams($raw, $budget) as $decoded) {
            foreach (self::pdfShownStrings($decoded) as $s) $out[] = $s;
        }

        if (!$out) return null;
        return implode("\n", $out);
    }

    /** Taille maximale d'un flux une fois décompressé (repli PDF). */
    private const PDF_STREAM_INFLATE_MAX = 8 * 1024 * 1024;

    /** Total décompressé par le repli PDF, tous flux confondus. */
    private const PDF_FALLBACK_BUDGET = 32 * 1024 * 1024;

    /**
     * Flux d'un PDF susceptibles de porter du texte, décompressés un par un.
     * Écartés sans être recopiés : images, polices, métadonnées XML, index de
     * références, et tout filtre que PHP ne sait pas défaire (JPEG, JPEG 2000,
     * fax, JBIG2, LZW…). Un flux qui dépasserait PDF_STREAM_INFLATE_MAX une
     * fois décompressé est écarté (gzuncompress s'arrête à la borne).
     *
     * @return \Generator<string>
     */
    private static function pdfTextStreams(string $raw, int &$budget): \Generator
    {
        if (!preg_match_all('#>>\s*stream\r?\n#', $raw, $marks, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($marks[0] as [$mark, $offset]) {
            if ($budget <= 0) return;
            $dataStart = $offset + strlen($mark);
            $end = strpos($raw, 'endstream', $dataStart);
            if ($end === false) continue;

            // Dictionnaire du flux : depuis le dernier « obj » qui le précède.
            $from = max(0, $offset - 4096);
            $window = substr($raw, $from, $offset - $from + 2);
            $objAt = strrpos($window, 'obj');
            $dict = $objAt === false ? $window : substr($window, $objAt);

            if (preg_match('#/Subtype\s*/(?:Image|Type1C|CIDFontType0C|OpenType|XML)\b|/Length[123]\b|/Type\s*/(?:XRef|Metadata|EmbeddedFile)\b#', $dict)) {
                continue;
            }
            $flate = false;
            if (preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $dict, $f)) {
                $filters = preg_match_all('#/(\w+)#', $f[1], $fm) ? $fm[1] : [];
                $filters = array_map(static fn(string $n): string => $n === 'Fl' ? 'FlateDecode' : $n, $filters);
                if ($filters !== ['FlateDecode']) continue;
                $flate = true;
            }

            $length = $end - $dataStart;
            if ($length <= 0) continue;
            $data = rtrim(substr($raw, $dataStart, $length), "\r\n");
            if ($flate) {
                $data = @gzuncompress($data, min(self::PDF_STREAM_INFLATE_MAX, max(1, $budget)));
                if ($data === false) continue;
            }
            $budget -= strlen($data);
            yield $data;
        }
    }

    /**
     * Chaînes affichées par les opérateurs Tj et TJ d'un flux de contenu.
     *
     * @return list<string>
     */
    private static function pdfShownStrings(string $decoded): array
    {
        $out = [];
        // Tj : ( ... ) Tj
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $decoded, $m)) {
            foreach ($m[1] as $s) $out[] = self::unescapePdfString($s);
        }
        // TJ : [(...) ... (...)] TJ
        if (preg_match_all('/\[((?:[^\[\]]|(?R))*)\]\s*TJ/s', $decoded, $m)) {
            foreach ($m[1] as $arr) {
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $arr, $mm)) {
                    $buf = '';
                    foreach ($mm[1] as $s) $buf .= self::unescapePdfString($s);
                    $out[] = $buf;
                }
            }
        }
        return $out;
    }

    private static function unescapePdfString(string $s): string
    {
        $replacements = [
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
            '\\b' => "\x08", '\\f' => "\x0C",
            '\\(' => '(',  '\\)' => ')', '\\\\' => '\\',
        ];
        return strtr($s, $replacements);
    }

    private static function cleanText(string $text): string
    {
        // Garantit un encodage UTF-8 valide. Les PDF extraits via smalot/pdfparser
        // ou via le fallback natif peuvent contenir des séquences d'octets invalides
        // qui feraient échouer silencieusement json_encode() côté adapter LLM.
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Convertit depuis UTF-8 avec substitution des octets invalides.
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        // Retire les caractères de contrôle non imprimables (y compris NUL).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    public static function toBase64(string $filePath, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($filePath));
    }
}
