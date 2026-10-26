<?php

declare(strict_types=1);

/**
 * Prérequis d'exécution — SOURCE UNIQUE.
 *
 * Une seule liste d'extensions pour tout le projet : le Dockerfile, la
 * documentation d'installation, la page de diagnostic et le script `doctor`
 * s'y réfèrent. Avant, rien ne vérifiait quoi que ce soit — sans `ext-zip`,
 * l'application démarrait et ne cassait qu'au premier export .docx, par une
 * page blanche.
 *
 * Chaque manque nomme LA FONCTIONNALITÉ PERDUE, pas seulement l'extension :
 * « zip » ne dit rien à un exploitant, « exports Word et PowerPoint » si.
 *
 * Les trois statuts sont définis ici parce que cette classe est chargée avant
 * `Diagnostics`, qui les reprend tels quels.
 */
final class Requirements
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Plancher imposé par phpspreadsheet (dépendance de phppresentation). */
    public const PHP_MINIMUM = '8.3.0';

    /** Sans l'une d'elles, l'application ne peut pas fonctionner du tout. */
    public const EXTENSIONS_REQUISES = [
        'pdo_mysql' => 'accès à la base de données',
        'mbstring'  => 'traitement des textes accentués',
        'json'      => 'échanges avec les fournisseurs de modèles',
        'openssl'   => 'chiffrement des clés API stockées',
        'curl'      => 'appels aux API des modèles',
        'fileinfo'  => 'contrôle du type des fichiers déposés',
        'session'   => 'maintien des sessions utilisateur',
    ];

    /**
     * Exigées par les dépendances Composer (dompdf, phpword, phppresentation,
     * phpspreadsheet, pdfparser). `composer install` REFUSE de s'installer sans
     * elles : leur absence ne dégrade pas l'application, elle empêche
     * d'installer ses dépendances. C'est le premier écueil d'une installation
     * native sur une distribution minimale (php-xml et php-gd non installés).
     */
    public const EXTENSIONS_DOCUMENTS = [
        'gd'        => 'images : génération, redimensionnement, exports PDF',
        'zip'       => 'exports Word (.docx) et PowerPoint (.pptx)',
        'dom'       => 'lecture et écriture des formats Office',
        'xml'       => 'lecture et écriture des formats Office',
        'simplexml' => 'lecture des feuilles de calcul',
        'xmlreader' => 'lecture des feuilles de calcul',
        'xmlwriter' => 'écriture des feuilles de calcul',
        'iconv'     => 'conversion d\'encodage des documents importés',
        'ctype'     => 'analyse des feuilles de calcul',
        'zlib'      => 'compression des archives Office',
    ];

    /** Absentes, l'application tourne mais perd la fonctionnalité citée. */
    public const EXTENSIONS_FONCTIONNELLES = [
        'intl'    => 'formats de dates et de nombres localisés',
        'exif'    => 'orientation correcte des photos déposées',
        'opcache' => 'performances (compilation PHP mise en cache)',
        'pcntl'   => 'arrêt propre du worker de générations',
    ];

    /**
     * Extensions dont le nom déclaré diffère du nom du paquet.
     * `extension_loaded('opcache')` renvoie false alors qu'OPcache est actif :
     * son nom réel est « Zend OPcache ». Sans cet alias, le diagnostic
     * signalerait une panne inexistante.
     */
    private const ALIAS = [
        'opcache' => 'Zend OPcache',
    ];

    /** Dépendances Composer sondées par une classe qu'elles fournissent. */
    private const CLASSES_COMPOSER = [
        'dompdf/dompdf'                 => \Dompdf\Dompdf::class,
        'smalot/pdfparser'              => \Smalot\PdfParser\Parser::class,
        'phpoffice/phpword'             => \PhpOffice\PhpWord\PhpWord::class,
        'phpoffice/phppresentation'     => \PhpOffice\PhpPresentation\PhpPresentation::class,
    ];

    /** Répertoires que l'application doit pouvoir écrire (relatifs à ROOT). */
    public const CHEMINS_INSCRIPTIBLES = [
        'storage/uploads',
        'storage/logs',
        'storage/cache',
        'storage/ratelimit',
        'storage/profile_documents',
        'storage/knowledge_bases',
        'public/assets/img/generated',
        'public/assets/img/branding',
        'public/assets/img/avatars',
        'public/assets/img/profils',
    ];

    // ---------------------------------------------------------------- Contrôles

    /** @return array<string,mixed> */
    public static function php(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP_MINIMUM, '>=');

        return self::controle(
            'php_version',
            self::tr('Version de PHP'),
            $ok ? self::OK : self::FAIL,
            PHP_VERSION,
            $ok ? '' : self::tr('MIA exige PHP {version} ou plus récent.', ['version' => self::PHP_MINIMUM]),
            $ok ? '' : self::tr('Mettez PHP à jour, ou utilisez l’image Docker fournie (PHP 8.5).')
        );
    }

    /** @return list<array<string,mixed>> */
    public static function extensions(): array
    {
        return [
            self::controleExtensions(
                'php_extensions',
                self::tr('Extensions PHP requises'),
                self::EXTENSIONS_REQUISES,
                self::FAIL,
                self::tr('Sans elles, l’application ne peut pas démarrer.')
            ),
            self::controleExtensions(
                'php_extensions_documents',
                self::tr('Extensions PHP des dépendances'),
                self::EXTENSIONS_DOCUMENTS,
                self::FAIL,
                self::tr('composer install refusera de s’exécuter sans elles.')
            ),
            self::controleExtensions(
                'php_extensions_optionnelles',
                self::tr('Extensions PHP optionnelles'),
                self::EXTENSIONS_FONCTIONNELLES,
                self::WARN,
                self::tr('L’application fonctionne, mais ces fonctionnalités sont indisponibles.')
            ),
        ];
    }

    /** @return array<string,mixed> */
    public static function composer(): array
    {
        if (!defined('ROOT') || !is_file(ROOT . '/vendor/autoload.php')) {
            return self::controle(
                'composer',
                self::tr('Dépendances Composer'),
                self::FAIL,
                self::tr('vendor/ absent'),
                self::tr('Les dépendances PHP ne sont pas installées : les exports PDF, Word et PowerPoint échoueront.'),
                self::tr('Lancez : composer install --no-dev --optimize-autoloader')
            );
        }

        $manquantes = [];
        foreach (self::CLASSES_COMPOSER as $paquet => $classe) {
            if (!class_exists($classe)) {
                $manquantes[] = $paquet;
            }
        }

        if ($manquantes !== []) {
            return self::controle(
                'composer',
                self::tr('Dépendances Composer'),
                self::FAIL,
                self::tr('{n} manquante(s)', ['n' => count($manquantes)]),
                self::tr('Paquets absents de vendor/ : {paquets}.', ['paquets' => implode(', ', $manquantes)]),
                self::tr('Lancez : composer install --no-dev --optimize-autoloader')
            );
        }

        return self::controle(
            'composer',
            self::tr('Dépendances Composer'),
            self::OK,
            self::tr('{n} paquets', ['n' => count(self::CLASSES_COMPOSER)]),
            '',
            ''
        );
    }

    /** @return array<string,mixed> */
    public static function writablePaths(): array
    {
        $absents = [];
        $bloques = [];

        foreach (self::CHEMINS_INSCRIPTIBLES as $relatif) {
            $chemin = (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . '/' . $relatif;
            if (!is_dir($chemin)) {
                $absents[] = $relatif;
                continue;
            }
            if (!is_writable($chemin)) {
                $bloques[] = $relatif;
            }
        }

        if ($absents === [] && $bloques === []) {
            return self::controle(
                'storage_writable',
                self::tr('Répertoires de stockage'),
                self::OK,
                self::tr('{n} répertoires', ['n' => count(self::CHEMINS_INSCRIPTIBLES)]),
                '',
                ''
            );
        }

        $details = [];
        if ($absents !== []) {
            $details[] = self::tr('absents : {chemins}', ['chemins' => implode(', ', $absents)]);
        }
        if ($bloques !== []) {
            $details[] = self::tr('non inscriptibles : {chemins}', ['chemins' => implode(', ', $bloques)]);
        }

        return self::controle(
            'storage_writable',
            self::tr('Répertoires de stockage'),
            self::FAIL,
            self::tr('{n} en défaut', ['n' => count($absents) + count($bloques)]),
            self::tr('Les dépôts de fichiers et les journaux échoueront — {details}.', ['details' => implode(' ; ', $details)]),
            self::tr('Sous Docker : docker compose up -d --force-recreate app (l’entrypoint répare les droits). En natif : mkdir -p <chemins> && chown -R www-data:www-data storage public/assets/img')
        );
    }

    /**
     * Agrégat consommé par Diagnostics, doctor.php et l'installeur.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        return array_merge(
            [self::php()],
            self::extensions(),
            [self::composer(), self::writablePaths()]
        );
    }

    /** Gère les extensions dont le nom déclaré diffère du nom du paquet. */
    public static function extensionChargee(string $nom): bool
    {
        return extension_loaded(self::ALIAS[$nom] ?? $nom);
    }

    // ---------------------------------------------------------------- Interne

    /**
     * Traduit si I18n est chargé (page Diagnostic, installeur, doctor.php) ;
     * sinon substitue seulement. Ce fichier est un SOCLE chargé avant tout :
     * il ne doit dépendre de rien.
     *
     * @param array<string, string|int|float> $vars
     */
    private static function tr(string $texte, array $vars = []): string
    {
        if (function_exists('t')) {
            return t($texte, $vars);
        }
        $remplacements = [];
        foreach ($vars as $nom => $valeur) {
            $remplacements['{' . $nom . '}'] = (string)$valeur;
        }
        return strtr($texte, $remplacements);
    }

    /**
     * @param array<string,string> $liste extension => fonctionnalité perdue
     * @return array<string,mixed>
     */
    private static function controleExtensions(
        string $id,
        string $libelle,
        array $liste,
        string $statutSiManque,
        string $consequence
    ): array {
        $manquantes = [];
        foreach ($liste as $extension => $fonctionnalite) {
            if (!self::extensionChargee($extension)) {
                $manquantes[] = $extension . ' (' . self::tr($fonctionnalite) . ')';
            }
        }

        if ($manquantes === []) {
            return self::controle($id, $libelle, self::OK, self::tr('{n} présentes', ['n' => count($liste)]), '', '');
        }

        return self::controle(
            $id,
            $libelle,
            $statutSiManque,
            self::tr('{n} manquante(s)', ['n' => count($manquantes)]),
            $consequence . ' ' . self::tr('Absentes : {extensions}.', ['extensions' => implode(', ', $manquantes)]),
            self::tr('Sous Docker : reconstruisez l’image (docker compose build). En natif : installez les paquets php{version}-<extension>.', ['version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION])
        );
    }

    /** @return array<string,mixed> */
    private static function controle(
        string $id,
        string $libelle,
        string $statut,
        string $valeur,
        string $message,
        string $remede
    ): array {
        return [
            'id'       => $id,
            'categorie' => 'socle',
            'libelle'  => $libelle,
            'statut'   => $statut,
            'valeur'   => $valeur,
            'message'  => $message,
            'remede'   => $remede,
        ];
    }
}
