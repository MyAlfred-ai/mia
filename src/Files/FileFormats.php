<?php

declare(strict_types=1);

/**
 * Formats de fichiers livrables dans une conversation — SOURCE UNIQUE.
 *
 * LE PROBLÈME RÉSOLU. Un modèle ne sait produire que du texte : un tableau
 * arrivait dans un bloc ```csv à recopier, un courrier dans le fil à exporter
 * en entier, et rien ne ressemblait à « un fichier ». Désormais le modèle
 * DÉCLARE un fichier (cf. FileDelivery : un bloc de code nommé), l'écran en
 * fait une carte avec un bouton « Télécharger », et c'est le SERVEUR qui
 * fabrique le vrai fichier à partir du texte — un classeur Excel depuis du
 * CSV, un document Word ou une présentation PowerPoint depuis du Markdown.
 *
 * Ce catalogue dit, pour chaque extension : son type MIME, son libellé, sa
 * FAMILLE (qui décide de l'aperçu et des formats de rechange) et la classe qui
 * le fabrique. Le navigateur en reçoit une copie (`clientCatalog()`, injectée
 * en `window.FILE_FORMATS`) : aucune liste de formats n'est recopiée en
 * JavaScript.
 *
 * Ajouter un format = une entrée ici (+ sa classe si la fabrication est
 * nouvelle). Rien d'autre à toucher : le contrôleur, la carte et le menu
 * « Autres formats » lisent ce catalogue.
 *
 * INDÉPENDANT DU FOURNISSEUR, et c'est voulu : tout repose sur du texte rendu
 * par le modèle, donc sur les neuf adaptateurs — avec ou sans appel d'outils,
 * en envoi comme en régénération.
 */
final class FileFormats
{
    /** Tableur : la source est un tableau (CSV, TSV ou table Markdown). */
    public const FAMILY_TABLE = 'table';
    /** Document mis en forme : la source est du Markdown. */
    public const FAMILY_DOCUMENT = 'document';
    /**
     * Présentation : la source est AUSSI du Markdown, lu en slides
     * (cf. MarkdownDeck). Famille à part parce que son menu diffère : un
     * PowerPoint se propose en PDF de slides, pas en RTF.
     */
    public const FAMILY_PRESENTATION = 'presentation';
    /** Texte brut. */
    public const FAMILY_TEXT = 'text';
    /** Données structurées et code : recopiés tels quels. */
    public const FAMILY_CODE = 'code';

    /** Ce que le fabricant doit supposer de la source. */
    public const SYNTAX_TABLE    = 'table';
    public const SYNTAX_MARKDOWN = 'markdown';
    public const SYNTAX_HTML     = 'html';
    public const SYNTAX_TEXT     = 'text';
    public const SYNTAX_RAW      = 'raw';

    /** Plafond de la source (octets) quand le format n'en déclare pas. */
    public const DEFAULT_MAX_BYTES = 2_000_000;

    /** Route du fabricant (POST). Lue par le front via clientCatalog(). */
    public const ENDPOINT = '/files/build';

    /**
     * Formats fabriqués. `label` = libellé long (méta de la carte), `short` =
     * nom du format dans le menu (« Excel (.xlsx) »), `tone` = couleur de la
     * pastille (glyphe posé par main.css), `stem` = nom par défaut d'un
     * fichier que le modèle n'a pas nommé, `max` = plafond de la source.
     *
     * Les libellés sont des CLÉS de traduction (le texte français est la clé,
     * cf. I18n) : ils passent par t() à l'accesseur, jamais ici — une constante
     * de classe ne peut pas appeler de fonction.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CATALOG = [
        // ---- Tableur ------------------------------------------------------
        'xlsx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'label' => 'Classeur Excel', 'short' => 'Excel',
            'family' => self::FAMILY_TABLE, 'builder' => 'XlsxFileBuilder',
            'tone' => 'sheet', 'stem' => 'tableau', 'binary' => true,
        ],
        'csv' => [
            'mime' => 'text/csv; charset=utf-8',
            'label' => 'Fichier CSV', 'short' => 'CSV',
            'family' => self::FAMILY_TABLE, 'builder' => 'CsvFileBuilder',
            'tone' => 'sheet', 'stem' => 'tableau',
        ],
        'tsv' => [
            'mime' => 'text/tab-separated-values; charset=utf-8',
            'label' => 'Fichier TSV', 'short' => 'TSV',
            'family' => self::FAMILY_TABLE, 'builder' => 'CsvFileBuilder',
            'tone' => 'sheet', 'stem' => 'tableau',
        ],
        // ---- Document mis en forme ---------------------------------------
        'docx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'label' => 'Document Word', 'short' => 'Word',
            'family' => self::FAMILY_DOCUMENT, 'builder' => 'DocxFileBuilder',
            'tone' => 'doc', 'stem' => 'document', 'binary' => true, 'max' => 1_000_000,
        ],
        'pdf' => [
            'mime' => 'application/pdf',
            'label' => 'Document PDF', 'short' => 'PDF',
            'family' => self::FAMILY_DOCUMENT, 'builder' => 'PdfFileBuilder',
            'tone' => 'pdf', 'stem' => 'document', 'binary' => true, 'max' => 400_000,
        ],
        'rtf' => [
            'mime' => 'application/rtf',
            'label' => 'Document RTF', 'short' => 'RTF',
            'family' => self::FAMILY_DOCUMENT, 'builder' => 'RtfFileBuilder',
            'tone' => 'doc', 'stem' => 'document', 'max' => 1_000_000,
        ],
        // ---- Présentation ------------------------------------------------
        'pptx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'label' => 'Présentation PowerPoint', 'short' => 'PowerPoint',
            'family' => self::FAMILY_PRESENTATION, 'builder' => 'PptxFileBuilder',
            'tone' => 'slides', 'stem' => 'presentation', 'binary' => true, 'max' => 400_000,
        ],
        'md' => [
            'mime' => 'text/markdown; charset=utf-8',
            'label' => 'Document Markdown', 'short' => 'Markdown',
            'family' => self::FAMILY_DOCUMENT, 'builder' => 'TextFileBuilder',
            'tone' => 'text', 'stem' => 'document',
        ],
        // ---- Texte brut ---------------------------------------------------
        'txt' => [
            'mime' => 'text/plain; charset=utf-8',
            'label' => 'Fichier texte', 'short' => 'Texte',
            'family' => self::FAMILY_TEXT, 'builder' => 'TextFileBuilder',
            'tone' => 'text', 'stem' => 'texte',
        ],
        // ---- Données structurées ------------------------------------------
        'json' => ['mime' => 'application/json; charset=utf-8', 'label' => 'Fichier JSON', 'short' => 'JSON', 'tone' => 'data', 'stem' => 'donnees'],
        'jsonl' => ['mime' => 'application/x-ndjson; charset=utf-8', 'label' => 'Fichier JSON Lines', 'short' => 'JSON Lines', 'tone' => 'data', 'stem' => 'donnees'],
        'xml' => ['mime' => 'application/xml; charset=utf-8', 'label' => 'Fichier XML', 'short' => 'XML', 'tone' => 'data', 'stem' => 'donnees'],
        'yaml' => ['mime' => 'application/yaml; charset=utf-8', 'label' => 'Fichier YAML', 'short' => 'YAML', 'tone' => 'data', 'stem' => 'configuration'],
        'yml' => ['mime' => 'application/yaml; charset=utf-8', 'label' => 'Fichier YAML', 'short' => 'YAML', 'tone' => 'data', 'stem' => 'configuration'],
        'toml' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Fichier TOML', 'short' => 'TOML', 'tone' => 'data', 'stem' => 'configuration'],
        'ini' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Fichier INI', 'short' => 'INI', 'tone' => 'data', 'stem' => 'configuration'],
        'sql' => ['mime' => 'application/sql; charset=utf-8', 'label' => 'Script SQL', 'short' => 'SQL', 'tone' => 'data', 'stem' => 'requete'],
        'ics' => ['mime' => 'text/calendar; charset=utf-8', 'label' => 'Fichier agenda', 'short' => 'iCalendar', 'tone' => 'data', 'stem' => 'agenda', 'eol' => "\r\n"],
        'vcf' => ['mime' => 'text/vcard; charset=utf-8', 'label' => 'Fiche contact', 'short' => 'vCard', 'tone' => 'data', 'stem' => 'contact', 'eol' => "\r\n"],
        'srt' => ['mime' => 'application/x-subrip; charset=utf-8', 'label' => 'Sous-titres', 'short' => 'SRT', 'tone' => 'data', 'stem' => 'sous-titres'],
        'vtt' => ['mime' => 'text/vtt; charset=utf-8', 'label' => 'Sous-titres', 'short' => 'WebVTT', 'tone' => 'data', 'stem' => 'sous-titres'],
        'log' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Journal', 'short' => 'Log', 'tone' => 'text', 'stem' => 'journal'],
        // ---- Web ----------------------------------------------------------
        'html' => ['mime' => 'text/html; charset=utf-8', 'label' => 'Fichier HTML', 'short' => 'HTML', 'tone' => 'web', 'stem' => 'page'],
        'htm' => ['mime' => 'text/html; charset=utf-8', 'label' => 'Fichier HTML', 'short' => 'HTML', 'tone' => 'web', 'stem' => 'page'],
        'css' => ['mime' => 'text/css; charset=utf-8', 'label' => 'Feuille de style', 'short' => 'CSS', 'tone' => 'web', 'stem' => 'style'],
        'svg' => ['mime' => 'image/svg+xml', 'label' => 'Image vectorielle', 'short' => 'SVG', 'tone' => 'web', 'stem' => 'image'],
        // ---- Code ---------------------------------------------------------
        'js' => ['mime' => 'text/javascript; charset=utf-8', 'label' => 'Script JavaScript', 'short' => 'JavaScript', 'tone' => 'code', 'stem' => 'script'],
        'ts' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Script TypeScript', 'short' => 'TypeScript', 'tone' => 'code', 'stem' => 'script'],
        'py' => ['mime' => 'text/x-python; charset=utf-8', 'label' => 'Script Python', 'short' => 'Python', 'tone' => 'code', 'stem' => 'script'],
        'php' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Script PHP', 'short' => 'PHP', 'tone' => 'code', 'stem' => 'script'],
        'sh' => ['mime' => 'text/x-shellscript; charset=utf-8', 'label' => 'Script shell', 'short' => 'Shell', 'tone' => 'code', 'stem' => 'script'],
        'bat' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Script Windows', 'short' => 'Batch', 'tone' => 'code', 'stem' => 'script', 'eol' => "\r\n"],
        'ps1' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Script PowerShell', 'short' => 'PowerShell', 'tone' => 'code', 'stem' => 'script'],
        'tex' => ['mime' => 'text/plain; charset=utf-8', 'label' => 'Document LaTeX', 'short' => 'LaTeX', 'tone' => 'code', 'stem' => 'document'],
    ];

    /**
     * Langage du bloc de code → extension. Sert deux fois : à nommer un bloc
     * que le modèle n'a pas nommé (bouton « Télécharger » de son en-tête), et
     * à deviner le format d'un nom sans extension.
     *
     * `markdown`/`md` et `mermaid` n'y figurent pas, et c'est délibéré : le
     * premier est déballé par l'écran (texte rédigé, pas un fichier), le
     * second est rendu en schéma, qui a ses propres boutons d'export.
     *
     * @var array<string, string>
     */
    private const LANGUAGES = [
        'csv' => 'csv', 'tsv' => 'tsv',
        'text' => 'txt', 'txt' => 'txt', 'plaintext' => 'txt', 'plain' => 'txt',
        'json' => 'json', 'json5' => 'json', 'jsonl' => 'jsonl', 'ndjson' => 'jsonl',
        'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml', 'ini' => 'ini',
        'sql' => 'sql', 'mysql' => 'sql', 'postgresql' => 'sql', 'pgsql' => 'sql',
        'html' => 'html', 'css' => 'css', 'svg' => 'svg',
        'javascript' => 'js', 'js' => 'js', 'typescript' => 'ts', 'ts' => 'ts',
        'python' => 'py', 'py' => 'py', 'php' => 'php',
        'bash' => 'sh', 'sh' => 'sh', 'shell' => 'sh', 'zsh' => 'sh',
        'bat' => 'bat', 'batch' => 'bat', 'powershell' => 'ps1', 'ps1' => 'ps1',
        'latex' => 'tex', 'tex' => 'tex',
        'ics' => 'ics', 'vcard' => 'vcf', 'vcf' => 'vcf', 'srt' => 'srt', 'vtt' => 'vtt',
    ];

    /**
     * Formats de rechange d'une famille, dans l'ordre du menu « Autres
     * formats ». Tous se fabriquent depuis la MÊME source : un tableau donne
     * un classeur ou un CSV, un document Markdown donne Word, PDF, RTF… et une
     * présentation, un PowerPoint, un PDF de slides, un Word ou sa source.
     *
     * @var array<string, list<string>>
     */
    private const ALTERNATIVES = [
        self::FAMILY_TABLE        => ['xlsx', 'csv'],
        self::FAMILY_DOCUMENT     => ['docx', 'pdf', 'rtf', 'md', 'txt'],
        self::FAMILY_PRESENTATION => ['pptx', 'pdf', 'docx', 'md'],
    ];

    /**
     * Extensions d'une PRÉSENTATION : celle que l'on fabrique et celles que
     * l'on refuse au profit du `.pptx`. Leur contenu se lit en slides — un
     * PDF demandé depuis l'une d'elles est un PDF de slides (cf. PdfFileBuilder).
     */
    private const PRESENTATIONS = ['pptx', 'ppt', 'odp', 'key'];

    /**
     * Extensions qu'on ne sait PAS fabriquer depuis du texte, avec ce qu'il
     * faut proposer à la place. Sans cette liste, un fichier « rapport.key »
     * serait téléchargé tel quel : un fichier texte portant une extension
     * binaire, que PowerPoint refuse d'ouvrir — sans que rien ne l'explique.
     *
     * `why` est une clé de traduction ; `use` liste les formats de rechange.
     *
     * @var array<string, array{why: string, use: list<string>}>
     */
    private const UNSUPPORTED = [
        'xls'  => ['why' => 'Ancien format Excel : MIA crée des classeurs .xlsx.', 'use' => ['xlsx', 'csv']],
        'xlsm' => ['why' => 'Classeur à macros : MIA crée des classeurs .xlsx, sans macro.', 'use' => ['xlsx', 'csv']],
        'ods'  => ['why' => 'Format OpenDocument : MIA crée des classeurs .xlsx, que LibreOffice ouvre.', 'use' => ['xlsx', 'csv']],
        'numbers' => ['why' => 'Format Numbers : MIA crée des classeurs .xlsx, que Numbers ouvre.', 'use' => ['xlsx', 'csv']],
        'doc'  => ['why' => 'Ancien format Word : MIA crée des documents .docx.', 'use' => ['docx', 'rtf', 'pdf']],
        'odt'  => ['why' => 'Format OpenDocument : MIA crée des documents .docx, que LibreOffice ouvre.', 'use' => ['docx', 'rtf', 'pdf']],
        'pages' => ['why' => 'Format Pages : MIA crée des documents .docx, que Pages ouvre.', 'use' => ['docx', 'rtf', 'pdf']],
        'ppt'  => ['why' => 'Ancien format PowerPoint : MIA crée des présentations .pptx.', 'use' => ['pptx', 'pdf']],
        'odp'  => ['why' => 'Format OpenDocument : MIA crée des présentations .pptx, que LibreOffice ouvre.', 'use' => ['pptx', 'pdf']],
        'key'  => ['why' => 'Format Keynote : MIA crée des présentations .pptx, que Keynote ouvre.', 'use' => ['pptx', 'pdf']],
        'png'  => ['why' => 'Une image se crée avec l’outil Image du menu « + ».', 'use' => []],
        'jpg'  => ['why' => 'Une image se crée avec l’outil Image du menu « + ».', 'use' => []],
        'jpeg' => ['why' => 'Une image se crée avec l’outil Image du menu « + ».', 'use' => []],
        'gif'  => ['why' => 'Une image se crée avec l’outil Image du menu « + ».', 'use' => []],
        'webp' => ['why' => 'Une image se crée avec l’outil Image du menu « + ».', 'use' => []],
        'zip'  => ['why' => 'MIA ne crée pas d’archive : téléchargez chaque fichier séparément.', 'use' => []],
        'rar'  => ['why' => 'MIA ne crée pas d’archive : téléchargez chaque fichier séparément.', 'use' => []],
        '7z'   => ['why' => 'MIA ne crée pas d’archive : téléchargez chaque fichier séparément.', 'use' => []],
        'exe'  => ['why' => 'MIA ne crée pas de programme exécutable.', 'use' => []],
        'mp3'  => ['why' => 'MIA ne crée pas de fichier audio.', 'use' => []],
        'wav'  => ['why' => 'MIA ne crée pas de fichier audio.', 'use' => []],
        'mp4'  => ['why' => 'Une vidéo se crée avec l’outil Vidéo du menu « + ».', 'use' => []],
        'mov'  => ['why' => 'Une vidéo se crée avec l’outil Vidéo du menu « + ».', 'use' => []],
        'epub' => ['why' => 'MIA ne crée pas de livre numérique : demandez un document Word ou PDF.', 'use' => ['docx', 'pdf']],
    ];

    /**
     * Extension « propre » : minuscules, lettres et chiffres, 1 à 10
     * caractères, dont AU MOINS UNE LETTRE. Sans cette dernière condition,
     * « budget.2026 » et « v1.2 » perdraient leur fin : « 2026 » et « 2 »
     * seraient prises pour des extensions.
     */
    public static function normalizeExtension(string $ext): string
    {
        $ext = strtolower(ltrim(trim($ext), '.'));

        return preg_match('/^(?=[a-z0-9]*[a-z])[a-z0-9]{1,10}$/', $ext) === 1 ? $ext : '';
    }

    /** Extension d'un nom de fichier, '' s'il n'en porte pas d'exploitable. */
    public static function extensionOf(string $filename): string
    {
        $pos = strrpos($filename, '.');
        if ($pos === false || $pos === 0) {
            return '';
        }

        return self::normalizeExtension(substr($filename, $pos + 1));
    }

    /** Extension associée à un langage de bloc de code, '' si aucune. */
    public static function extensionForLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if (isset(self::LANGUAGES[$language])) {
            return self::LANGUAGES[$language];
        }

        // Un modèle écrit parfois le FORMAT voulu en guise de langage
        // (```xlsx, ```docx) : c'est une déclaration de cible, pas une syntaxe.
        return isset(self::CATALOG[$language]) ? $language : '';
    }

    /** Le format est-il fabriqué par une classe dédiée (et non recopié) ? */
    public static function isKnown(string $ext): bool
    {
        return isset(self::CATALOG[self::normalizeExtension($ext)]);
    }

    /** L'extension désigne-t-elle une présentation (fabriquée ou refusée au profit du .pptx) ? */
    public static function isPresentation(string $ext): bool
    {
        return in_array(self::normalizeExtension($ext), self::PRESENTATIONS, true);
    }

    /**
     * Fiche d'un format. Une extension inconnue rend une fiche « texte
     * recopié » : un `.conf`, un `.env.example` ou un `.gitignore` sont du
     * texte, et les refuser au motif qu'ils ne sont pas au catalogue
     * reviendrait à tenir une liste de tous les formats texte du monde.
     * Les extensions BINAIRES connues, elles, sont refusées en amont
     * (cf. unsupported()).
     *
     * @return array{ext: string, mime: string, label: string, short: string, family: string,
     *               builder: string, tone: string, stem: string, binary: bool, max: int, eol: string}
     */
    public static function get(string $ext): array
    {
        $ext   = self::normalizeExtension($ext);
        $entry = self::CATALOG[$ext] ?? null;

        if ($entry === null) {
            $type = $ext !== '' ? strtoupper($ext) : '';

            return [
                'ext'     => $ext !== '' ? $ext : 'txt',
                'mime'    => 'text/plain; charset=utf-8',
                'label'   => $type !== '' ? t('Fichier {type}', ['type' => $type]) : t('Fichier texte'),
                'short'   => $type !== '' ? $type : t('Texte'),
                'family'  => self::FAMILY_CODE,
                'builder' => 'TextFileBuilder',
                'tone'    => 'code',
                'stem'    => t('fichier'),
                'binary'  => false,
                'max'     => self::DEFAULT_MAX_BYTES,
                'eol'     => "\n",
            ];
        }

        return [
            'ext'     => $ext,
            'mime'    => (string)$entry['mime'],
            'label'   => t((string)$entry['label']),
            'short'   => t((string)$entry['short']),
            'family'  => (string)($entry['family'] ?? self::FAMILY_CODE),
            'builder' => (string)($entry['builder'] ?? 'TextFileBuilder'),
            'tone'    => (string)($entry['tone'] ?? 'code'),
            'stem'    => t((string)($entry['stem'] ?? 'fichier')),
            'binary'  => !empty($entry['binary']),
            'max'     => (int)($entry['max'] ?? self::DEFAULT_MAX_BYTES),
            'eol'     => (string)($entry['eol'] ?? "\n"),
        ];
    }

    /**
     * Raison du refus d'une extension qu'on ne sait pas fabriquer, et formats
     * à proposer à la place — null si l'extension est acceptable.
     *
     * @return array{why: string, use: list<string>}|null
     */
    public static function unsupported(string $ext): ?array
    {
        $entry = self::UNSUPPORTED[self::normalizeExtension($ext)] ?? null;

        return $entry === null ? null : ['why' => t($entry['why']), 'use' => $entry['use']];
    }

    /**
     * Formats de rechange d'un format : ceux qui se fabriquent depuis la même
     * source. Le format lui-même en fait partie (il ouvre le menu), sauf s'il
     * est seul — un menu à une entrée n'est pas un choix.
     *
     * @return list<string>
     */
    public static function alternatives(string $ext): array
    {
        $ext    = self::normalizeExtension($ext);
        $family = self::get($ext)['family'];
        $list   = self::ALTERNATIVES[$family] ?? [];

        if ($list === []) {
            return [];
        }
        // Le format demandé d'abord (tsv, par exemple, n'est pas dans la liste
        // de sa famille : on ne le propose pas d'office, mais on ne le perd pas).
        return array_values(array_unique(array_merge([$ext], $list)));
    }

    /**
     * Syntaxe de la SOURCE, d'après le fichier tel que le modèle l'a déclaré
     * (son extension d'origine) et le langage de son bloc.
     *
     * La règle tient en une phrase : un tableur est écrit en tableau, un
     * document (ou une présentation) en Markdown, tout le reste est recopié.
     * Une nuance, née de ce que les modèles écrivent réellement : un document
     * dont le bloc est en ```text est du texte BRUT (ses astérisques ne sont
     * pas de la mise en forme), et en ```html du HTML.
     *
     * C'est l'ORIGINE qui décide, jamais le format demandé : « rapport.docx »
     * téléchargé en PDF ou en texte reste un document Markdown. Déduire la
     * syntaxe de la cible ferait lire le même contenu de deux façons selon
     * l'entrée choisie dans le menu.
     */
    public static function sourceSyntax(string $originExt, string $language): string
    {
        $originExt = self::normalizeExtension($originExt);
        $language  = strtolower(trim($language));

        if (self::get($originExt)['family'] === self::FAMILY_TABLE) {
            return self::SYNTAX_TABLE;
        }
        if (in_array($originExt, ['docx', 'pdf', 'rtf', 'pptx'], true)) {
            if ($language === 'html') {
                return self::SYNTAX_HTML;
            }

            return in_array($language, ['text', 'txt', 'plaintext', 'plain'], true)
                ? self::SYNTAX_TEXT
                : self::SYNTAX_MARKDOWN;
        }
        if ($originExt === 'md') {
            return self::SYNTAX_MARKDOWN;
        }

        return self::SYNTAX_RAW;
    }

    /**
     * Un format peut-il être fabriqué depuis une source de cette syntaxe ?
     *
     * Garde du contrôleur : le menu « Autres formats » ne propose que des
     * cibles valides, mais la requête vient du navigateur — demander un
     * classeur depuis un courrier doit être refusé proprement, pas produire
     * un fichier absurde.
     */
    public static function canBuild(string $syntax, string $targetExt, string $originExt): bool
    {
        $targetExt = self::normalizeExtension($targetExt);
        $originExt = self::normalizeExtension($originExt);

        return match ($syntax) {
            self::SYNTAX_TABLE => in_array($targetExt, ['xlsx', 'csv', 'tsv'], true),
            // Les trois écritures d'un document ouvrent le MÊME menu (celui de
            // la famille) : toute entrée proposée doit donc être fabricable,
            // sans quoi le menu offrirait un choix que le serveur refuse. Le
            // PowerPoint, lui, n'est fabriqué que depuis une présentation : un
            // compte rendu découpé en slides à chaque titre ne serait pas une
            // présentation, juste un document illisible.
            self::SYNTAX_MARKDOWN,
            self::SYNTAX_TEXT,
            self::SYNTAX_HTML  => in_array($targetExt, ['docx', 'pdf', 'rtf', 'md', 'txt'], true)
                || ($targetExt === 'pptx' && self::isPresentation($originExt)),
            // Recopie : même extension qu'à l'origine, ou `.txt` (toujours possible).
            default            => $targetExt === $originExt || $targetExt === 'txt',
        };
    }

    /**
     * Catalogue livré au navigateur (`window.FILE_FORMATS`). Libellés déjà
     * traduits : le JavaScript affiche, il ne connaît aucun nom de format.
     *
     * @return array<string, mixed>
     */
    public static function clientCatalog(): array
    {
        $formats = [];
        foreach (array_keys(self::CATALOG) as $ext) {
            $f = self::get((string)$ext);
            $formats[$ext] = [
                'label'  => $f['label'],
                'menu'   => $f['short'] . ' (.' . $ext . ')',
                'family' => $f['family'],
                'tone'   => $f['tone'],
                'stem'   => $f['stem'],
            ];
        }

        $alternatives = [];
        foreach (self::ALTERNATIVES as $family => $list) {
            $alternatives[$family] = $list;
        }

        $unsupported = [];
        foreach (array_keys(self::UNSUPPORTED) as $ext) {
            $unsupported[$ext] = self::unsupported((string)$ext);
        }

        return [
            'endpoint'     => self::ENDPOINT,
            'formats'      => $formats,
            'languages'    => self::LANGUAGES,
            'alternatives' => $alternatives,
            'unsupported'  => $unsupported,
            'attributes'   => FileDelivery::ATTRIBUTES,
            'generic'      => [
                'label' => t('Fichier {type}'),
                'stem'  => t('fichier'),
            ],
            'maxBytes'     => self::DEFAULT_MAX_BYTES,
        ];
    }

    /** @return list<string> extensions du catalogue (tests, documentation) */
    public static function extensions(): array
    {
        return array_map('strval', array_keys(self::CATALOG));
    }

    /** @return array<string, string> langage de bloc → extension (tests) */
    public static function languages(): array
    {
        return self::LANGUAGES;
    }
}
