<?php

declare(strict_types=1);

/**
 * Ce qu'une boucle « pour chaque » parcourt — les DÉCOUPEURS. Pur.
 *
 * Trois contrats, et le moteur ne connaît que le dernier :
 *  1. la SOURCE : une pièce du dossier (fichier reçu, Email reçu, Document,
 *     Tableau, Liste, texte…) ou un connecteur (dossier Drive, recherche
 *     d'emails) ;
 *  2. le DÉCOUPEUR (`source.kind`) : la règle qui transforme la source en
 *     éléments — il dit les pièces qu'il accepte, ses options et les champs
 *     de ses éléments ;
 *  3. l'ÉLÉMENT : une Fiche typée (ou un texte), avec au besoin un fichier
 *     attaché. Les briques de la boucle citent `@{élément.champ}` ; le
 *     fichier est joint au message de l'étape.
 *
 * Ajouter un découpeur = une entrée dans KINDS (et FIXED_FIELDS s'il a des
 * champs fixes), sa lecture dans LoopRunner, ses libellés dans catalog().
 * L'éditeur ne connaît rien de plus que ce catalogue.
 *
 * Les libellés des champs fixes sont des NOMS DE CONTENU (comme les champs de
 * l'Email reçu) : ils restent en français, comme ce que la personne a nommé.
 * Le rail du navigateur les porte à l'identique (workflow-rail.js, testé).
 */
final class LoopSources
{
    public const LIST        = 'list';
    public const ROWS        = 'rows';
    public const ATTACHMENTS = 'attachments';
    public const SLIDES      = 'slides';
    public const PAGES       = 'pages';
    public const SECTIONS    = 'sections';
    public const FILES       = 'files';
    public const EMAILS      = 'emails';
    public const AI          = 'ai';

    /** Ordre = ordre des tuiles de l'éditeur. */
    public const KINDS = [self::ROWS, self::LIST, self::ATTACHMENTS, self::SLIDES, self::PAGES, self::SECTIONS, self::FILES, self::EMAILS, self::AI];

    /** D'où vient la source : une pièce du dossier, un fichier Drive désigné, une recherche. */
    public const FROM_PIECE  = 'piece';
    public const FROM_DRIVE  = 'drive';
    public const FROM_SEARCH = 'search';

    /** Découpeurs qui savent lire un fichier Drive désigné (lien, chemin, nom). */
    public const DRIVE_FILE_KINDS = [self::ROWS, self::SLIDES, self::PAGES, self::SECTIONS];

    /** Nature des pièces jointes gardées. */
    public const ATTACHMENT_TYPES = ['all', 'pdf', 'images', 'office', 'text'];

    public const MAX_COLUMNS   = 20;
    public const MAX_AI_FIELDS = 8;
    /** Emails lus au plus par une recherche (une lecture par email). */
    public const MAX_EMAILS     = 100;
    /** Lignes lues au plus dans une feuille, avant tout filtre. */
    public const MAX_ROWS_READ  = 5000;
    /** Éléments rendus au plus par MIA (« Repérés par MIA »). */
    public const MAX_AI_ITEMS   = 200;
    /** Fichiers lus au plus dans un dossier Drive. */
    public const MAX_FOLDER_FILES = 200;

    /**
     * Champs des éléments des découpeurs à champs fixes. `file` : l'élément
     * porte un fichier (joint au message des étapes qui le lisent).
     */
    public const FIXED_FIELDS = [
        self::ATTACHMENTS => [
            ['key' => 'nom',         'label' => 'Nom',            'type' => 'text'],
            ['key' => 'type',        'label' => 'Type',           'type' => 'text'],
            ['key' => 'taille',      'label' => 'Taille',         'type' => 'text'],
            ['key' => 'email_objet', 'label' => 'Objet de l’email', 'type' => 'text'],
            ['key' => 'email_de',    'label' => 'Expéditeur',     'type' => 'email'],
        ],
        self::SLIDES => [
            ['key' => 'numero', 'label' => 'Numéro', 'type' => 'number'],
            ['key' => 'titre',  'label' => 'Titre',  'type' => 'text'],
            ['key' => 'texte',  'label' => 'Texte',  'type' => 'text'],
            ['key' => 'notes',  'label' => 'Notes',  'type' => 'text'],
        ],
        self::PAGES => [
            ['key' => 'numero', 'label' => 'Numéro', 'type' => 'number'],
            ['key' => 'texte',  'label' => 'Texte',  'type' => 'text'],
        ],
        self::SECTIONS => [
            ['key' => 'titre',   'label' => 'Titre',   'type' => 'text'],
            ['key' => 'contenu', 'label' => 'Contenu', 'type' => 'text'],
        ],
        self::FILES => [
            ['key' => 'nom',        'label' => 'Nom',        'type' => 'text'],
            ['key' => 'type',       'label' => 'Type',       'type' => 'text'],
            ['key' => 'modifie_le', 'label' => 'Modifié le', 'type' => 'date'],
            ['key' => 'lien',       'label' => 'Lien',       'type' => 'text'],
        ],
        self::EMAILS => [
            ['key' => 'expediteur',     'label' => 'Expéditeur',     'type' => 'email'],
            ['key' => 'objet',          'label' => 'Objet',          'type' => 'text'],
            ['key' => 'recu_le',        'label' => 'Reçu le',        'type' => 'date'],
            ['key' => 'contenu',        'label' => 'Contenu',        'type' => 'text'],
            ['key' => 'pieces_jointes', 'label' => 'Pièces jointes', 'type' => 'list'],
        ],
    ];

    /** Découpeurs dont l'élément porte un fichier. */
    public const FILE_KINDS = [self::ATTACHMENTS, self::FILES];

    /** Clé du champ qui nomme l'élément, par défaut. */
    public const LABEL_FIELD = [
        self::ATTACHMENTS => 'nom', self::SLIDES => 'titre', self::SECTIONS => 'titre',
        self::FILES => 'nom', self::EMAILS => 'objet',
    ];

    /** Une source de boucle connue ? */
    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * Forme de l'élément d'une boucle « pour chaque » : `form` (text | fiche),
     * `fields`, `file` (l'élément porte un fichier). Une boucle d'avant les
     * découpeurs (`source.kind` vide) garde son élément texte.
     *
     * @return array{form: string, fields: list<array>, file: bool}
     */
    public static function itemShape(array $loop): array
    {
        $source = is_array($loop['source'] ?? null) ? $loop['source'] : [];
        $kind = (string)($source['kind'] ?? '');
        if ($kind === self::ROWS) {
            return ['form' => 'fiche', 'fields' => array_values((array)($source['columns'] ?? [])), 'file' => false];
        }
        if ($kind === self::AI) {
            $fields = array_values((array)($source['fields'] ?? []));
            return ['form' => $fields !== [] ? 'fiche' : 'text', 'fields' => $fields, 'file' => false];
        }
        if (isset(self::FIXED_FIELDS[$kind])) {
            return ['form' => 'fiche', 'fields' => self::FIXED_FIELDS[$kind], 'file' => in_array($kind, self::FILE_KINDS, true)];
        }
        return ['form' => 'text', 'fields' => [], 'file' => false];
    }

    /**
     * Le découpeur accepte-t-il cette pièce comme source ? Un fichier reçu se
     * juge sur son contenu à l'exécution (son type n'est pas connu avant) :
     * ici, sa seule forme compte.
     */
    public static function accepts(string $kind, array $piece, string $overField = ''): bool
    {
        $form = (string)($piece['form'] ?? '');
        $format = (string)($piece['format'] ?? '');
        return match ($kind) {
            self::LIST        => in_array($form, ['list', 'text'], true) || ($form === 'fiche' && self::listField($piece, $overField) !== null),
            self::ROWS        => in_array($form, ['table', 'file'], true) || ($form === 'document' && $format === 'xlsx'),
            self::SLIDES      => $form === 'file' || ($form === 'document' && $format === 'pptx'),
            self::PAGES       => $form === 'file',
            self::SECTIONS    => in_array($form, ['file', 'text'], true) || ($form === 'document' && $format !== 'xlsx'),
            self::ATTACHMENTS => ($piece['event'] ?? '') === 'email',
            self::AI          => !in_array($form, ['image', 'page', 'map'], true) && $form !== '',
            default           => false,
        };
    }

    /** Le champ liste d'une Fiche désigné par `over_field`, ou null. */
    public static function listField(array $piece, string $key): ?array
    {
        foreach ((array)($piece['fields'] ?? []) as $f) {
            if ($f['key'] === $key && $f['type'] === 'list') {
                return $f;
            }
        }
        return null;
    }

    /** La source lit-elle une pièce du dossier (sinon : un connecteur) ? */
    public static function readsPiece(array $source): bool
    {
        $kind = (string)($source['kind'] ?? '');
        if ($kind === '' || $kind === self::LIST || $kind === self::AI) {
            return true;
        }
        if (in_array($kind, [self::FILES, self::EMAILS], true)) {
            return false;
        }
        if ($kind === self::ATTACHMENTS) {
            return ($source['from'] ?? self::FROM_PIECE) !== self::FROM_SEARCH;
        }
        return ($source['from'] ?? self::FROM_PIECE) !== self::FROM_DRIVE;
    }

    /**
     * Connecteur dont la source a besoin (clé de ConnectorRegistry), ou ''.
     * Une pièce jointe d'un Email reçu vient de la boîte du déclencheur.
     */
    public static function connector(array $source, ?array $event = null): string
    {
        $kind = (string)($source['kind'] ?? '');
        if (in_array($kind, self::DRIVE_FILE_KINDS, true)) {
            return ($source['from'] ?? '') === self::FROM_DRIVE ? 'drive' : '';
        }
        return match ($kind) {
            self::FILES       => 'drive',
            self::EMAILS      => (string)($source['service'] ?? 'gmail'),
            self::ATTACHMENTS => ($source['from'] ?? self::FROM_PIECE) === self::FROM_SEARCH
                ? (string)($source['service'] ?? 'gmail')
                : (string)($event['source'] ?? 'gmail'),
            default           => '',
        };
    }

    /**
     * La lecture des éléments demande-t-elle un job (fichier, connecteur,
     * IA) ? Sinon elle se fait sur place, au démarrage de la boucle.
     */
    public static function needsJob(array $source, ?array $piece): bool
    {
        $kind = (string)($source['kind'] ?? '');
        if ($kind === '' || $kind === self::LIST) {
            return false;
        }
        if ($kind === self::ROWS) {
            return !self::readsPiece($source) || ($piece['form'] ?? '') !== 'table';
        }
        if ($kind === self::SECTIONS) {
            return !self::readsPiece($source) || !in_array($piece['form'] ?? '', ['text', 'document'], true);
        }
        return true;
    }

    /** Un élément de ce découpeur se complète-t-il au moment de son tour (fichier, contenu) ? */
    public static function needsFetch(array $source): bool
    {
        return in_array((string)($source['kind'] ?? ''), [self::ATTACHMENTS, self::FILES, self::EMAILS], true);
    }

    /** Une pièce jointe de ce type est-elle gardée ? */
    public static function attachmentKept(string $mime, string $name, string $types): bool
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($types) {
            'pdf'    => $mime === 'application/pdf' || $ext === 'pdf',
            'images' => str_starts_with($mime, 'image/'),
            'office' => in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'csv', 'rtf'], true)
                || str_contains($mime, 'officedocument') || str_contains($mime, 'opendocument') || str_contains($mime, 'msword') || str_contains($mime, 'ms-excel'),
            'text'   => str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'csv', 'json'], true),
            default  => true,
        };
    }

    /**
     * Phrase qui dit ce que la boucle parcourt (rail, volet, message de
     * démarrage). `$over` : la pièce parcourue, si la source en lit une.
     */
    public static function describe(array $loop, ?array $over): string
    {
        $source = is_array($loop['source'] ?? null) ? $loop['source'] : [];
        $kind = (string)($source['kind'] ?? '');
        $name = $over !== null ? (string)$over['name'] : '';
        $fromDrive = ($source['from'] ?? '') === self::FROM_DRIVE;
        $driveName = trim((string)($source['drive'] ?? ''));
        $of = $fromDrive ? ($driveName !== '' ? $driveName : t('un fichier Drive')) : $name;
        return match ($kind) {
            self::ROWS        => $of !== '' ? t('Pour chaque ligne de « {source} »', ['source' => $of]) : t('Pour chaque ligne d’un tableau'),
            self::SLIDES      => $of !== '' ? t('Pour chaque slide de « {source} »', ['source' => $of]) : t('Pour chaque slide d’une présentation'),
            self::PAGES       => $of !== '' ? t('Pour chaque page de « {source} »', ['source' => $of]) : t('Pour chaque page d’un PDF'),
            self::SECTIONS    => $of !== '' ? t('Pour chaque section de « {source} »', ['source' => $of]) : t('Pour chaque section d’un document'),
            self::ATTACHMENTS => ($source['from'] ?? '') === self::FROM_SEARCH
                ? t('Pour chaque pièce jointe des emails trouvés')
                : ($name !== '' ? t('Pour chaque pièce jointe de « {source} »', ['source' => $name]) : t('Pour chaque pièce jointe')),
            self::FILES       => $driveName !== '' ? t('Pour chaque fichier du dossier « {source} »', ['source' => $driveName]) : t('Pour chaque fichier d’un dossier Drive'),
            self::EMAILS      => t('Pour chaque email trouvé'),
            self::AI          => $name !== '' ? t('Pour chaque élément repéré par MIA dans « {source} »', ['source' => $name]) : t('Pour chaque élément repéré par MIA'),
            default           => $name !== '' ? t('Pour chaque élément de « {piece} »', ['piece' => $name]) : t('Pour chaque élément'),
        };
    }

    /**
     * Catalogue de l'éditeur : une entrée par découpeur — libellé, aide,
     * icône, champs fixes, et ce qu'il demande (pièce, fichier Drive,
     * recherche). Traduit ici : le script n'a aucun libellé.
     */
    public static function catalog(): array
    {
        $help = [
            self::ROWS        => [t('Lignes d’un tableau ou d’un Excel'), t('Excel, CSV ou tableau du dossier : une ligne = un élément, ses colonnes deviennent des champs.'), 'table'],
            self::LIST        => [t('Éléments d’une liste'), t('Une liste, un champ liste d’une Fiche, ou un texte (une ligne = un élément).'), 'list'],
            self::ATTACHMENTS => [t('Pièces jointes d’un email'), t('Chaque fichier joint à l’email reçu, ou aux emails d’une recherche.'), 'file'],
            self::SLIDES      => [t('Slides d’une présentation'), t('PowerPoint (.pptx) ou présentation du dossier : le titre, le texte et les notes de chaque slide.'), 'slides'],
            self::PAGES       => [t('Pages d’un PDF'), t('Le texte de chaque page d’un PDF.'), 'pdf'],
            self::SECTIONS    => [t('Sections d’un document'), t('Word, Markdown ou texte du dossier : une section par titre.'), 'document'],
            self::FILES       => [t('Fichiers d’un dossier Drive'), t('Chaque fichier d’un dossier Google Drive, joint au tour qui le traite.'), 'folder'],
            self::EMAILS      => [t('Emails d’une recherche'), t('Chaque email trouvé dans Gmail ou Outlook.'), 'mail'],
            self::AI          => [t('Repérés par MIA'), t('MIA lit un texte, un email ou un document et en sort les éléments que vous décrivez : sujets, références, personnes…'), 'spark'],
        ];
        $out = [];
        foreach (self::KINDS as $kind) {
            [$label, $text, $icon] = $help[$kind];
            $out[] = [
                'key'      => $kind,
                'label'    => $label,
                'help'     => $text,
                'icon'     => $icon,
                'fields'   => self::FIXED_FIELDS[$kind] ?? [],
                'file'     => in_array($kind, self::FILE_KINDS, true),
                'drive'    => in_array($kind, self::DRIVE_FILE_KINDS, true),
                'search'   => in_array($kind, [self::EMAILS, self::ATTACHMENTS], true),
                'folder'   => $kind === self::FILES,
                'ai'       => $kind === self::AI,
                'label_field' => self::LABEL_FIELD[$kind] ?? '',
            ];
        }
        return $out;
    }

    /** Libellés de la nature des pièces jointes gardées. */
    public static function attachmentTypeLabels(): array
    {
        return [
            'all'    => t('Toutes'),
            'pdf'    => t('PDF seulement'),
            'images' => t('Images seulement'),
            'office' => t('Documents Office'),
            'text'   => t('Textes et CSV'),
        ];
    }
}
