<?php

declare(strict_types=1);

/**
 * Noms de fichiers sûrs — SOURCE UNIQUE côté serveur.
 *
 * Le nom vient du MODÈLE (ligne d'ouverture d'un bloc de code), puis du
 * navigateur : deux sources qu'on ne maîtrise pas. Il finit dans un en-tête
 * HTTP et sur le disque de l'utilisateur. D'où trois gestes, toujours les
 * mêmes : ne garder que le dernier segment (jamais de chemin), retirer ce que
 * Windows et macOS refusent, et imposer l'extension du format réellement
 * fabriqué — un classeur nommé « budget.csv » serait ouvert comme du texte.
 *
 * Fonctions pures : testables sans requête ni base.
 */
final class FileNaming
{
    /** Longueur maximale du nom, extension comprise (en caractères). */
    public const MAX_LENGTH = 120;

    /** Noms réservés par Windows, avec ou sans extension. */
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /**
     * Nom sans extension (« radical »), nettoyé. Rend '' si rien d'exploitable
     * ne reste — l'appelant pose alors le nom par défaut du format.
     */
    public static function stem(string $filename): string
    {
        $name = self::lastSegment($filename);

        // Retire l'extension si elle en est une (« rapport.final.docx » garde
        // « rapport.final »), sinon le point fait partie du nom (« v1.2 »).
        $pos = strrpos($name, '.');
        if ($pos !== false && $pos > 0 && FileFormats::normalizeExtension(substr($name, $pos + 1)) !== '') {
            $name = substr($name, 0, $pos);
        }

        return self::clean($name);
    }

    /**
     * Nom final : radical nettoyé + extension imposée.
     *
     * @param string $fallbackStem Radical utilisé quand le nom fourni est vide
     *                             ou ne contient rien d'exploitable.
     */
    public static function build(string $filename, string $ext, string $fallbackStem = 'fichier'): string
    {
        $ext  = FileFormats::normalizeExtension($ext);
        $stem = self::stem($filename);
        if ($stem === '') {
            $stem = self::clean($fallbackStem) ?: 'fichier';
        }

        $suffix = $ext !== '' ? '.' . $ext : '';
        $room   = max(1, self::MAX_LENGTH - mb_strlen($suffix, 'UTF-8'));
        if (mb_strlen($stem, 'UTF-8') > $room) {
            $stem = rtrim(mb_substr($stem, 0, $room, 'UTF-8'), " .-_");
        }

        return $stem . $suffix;
    }

    /**
     * En-tête `Content-Disposition` d'un téléchargement.
     *
     * Deux paramètres, et les deux comptent : `filename=` (ASCII, pour les
     * clients anciens) et `filename*=` (RFC 5987, UTF-8 encodé). Un nom
     * accentué placé tel quel dans `filename=` est lu en Latin-1 par certains
     * navigateurs — « Synthèse » devient « SynthÃ¨se ».
     */
    public static function contentDisposition(string $filename): string
    {
        $ascii = self::asciiFallback($filename);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    /**
     * Variante ASCII d'un nom : accents aplatis, tout autre caractère remplacé.
     * Table explicite plutôt qu'iconv//TRANSLIT, dont la sortie dépend de la
     * locale du serveur (« é » → « 'e » sur certaines glibc).
     *
     * L'extension est mise de côté puis reposée : un nom entièrement écrit
     * dans un autre alphabet (« 予算.xlsx ») se réduirait sinon à « xlsx »,
     * un fichier sans extension que rien ne sait ouvrir.
     */
    public static function asciiFallback(string $filename): string
    {
        $ext  = FileFormats::extensionOf($filename);
        $stem = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;

        $map = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ñ' => 'n',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ý' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'Ç' => 'C',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ì' => 'I',
            'Ñ' => 'N',
            'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
            'Ÿ' => 'Y', 'Ý' => 'Y',
            'Œ' => 'OE', 'Æ' => 'AE',
            '’' => '', '‘' => '', '“' => '', '”' => '', '«' => '', '»' => '',
            '–' => '-', '—' => '-',
            // Espaces insécables (U+00A0, U+202F) : invisibles ici, d'où l'écriture échappée.
            "\u{00A0}" => ' ', "\u{202F}" => ' ',
        ];
        $ascii = strtr($stem, $map);
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $ascii);
        $ascii = trim((string)preg_replace('/_{2,}/', '_', $ascii), ' ._');
        if ($ascii === '') {
            $ascii = 'fichier';
        }

        return $ext !== '' ? $ascii . '.' . $ext : $ascii;
    }

    /** Dernier segment d'un chemin : le modèle écrit parfois « src/app/main.py ». */
    private static function lastSegment(string $filename): string
    {
        $filename = str_replace('\\', '/', $filename);
        $pos      = strrpos($filename, '/');

        return $pos === false ? $filename : substr($filename, $pos + 1);
    }

    /** Nettoyage du radical : caractères interdits, blancs, points de bord, noms réservés. */
    private static function clean(string $name): string
    {
        // UTF-8 invalide : on ne laisse pas une séquence tronquée voyager
        // jusque dans un en-tête HTTP.
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        // Caractères de contrôle, puis ceux que Windows refuse dans un nom.
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = (string)preg_replace('/[<>:"\/\\\\|?*]+/u', '-', $name);
        $name = (string)preg_replace('/\s+/u', ' ', $name);
        // Ni point ni blanc en bord : « .htaccess » deviendrait un fichier
        // caché, et Windows retire de lui-même un point final.
        $name = trim($name, " .-_\t");

        if ($name !== '' && in_array(strtolower($name), self::RESERVED, true)) {
            $name = '_' . $name;
        }

        return $name;
    }
}
