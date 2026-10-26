<?php

declare(strict_types=1);

/**
 * Résolution, confinement et suppression d'un média produit ou reçu par le chat.
 *
 * SOURCE UNIQUE de la garde anti-traversée : aucune suppression de fichier ne
 * doit s'écrire ailleurs. Recopiée, elle finirait par diverger — et une garde de
 * confinement qui diverge, c'est un `unlink` hors périmètre.
 *
 * Deux conventions de chemin coexistent dans `messages.files_json` et ne se
 * devinent PAS l'une de l'autre :
 *   - pièce jointe utilisateur → chemin ABSOLU du système de fichiers
 *     (`/var/www/html/storage/uploads/<hash>.pdf`, cf. ChatController::…'path')
 *   - vidéo générée           → URL WEB (`/assets/video/generated/<hash>.mp4`)
 * `resoudre()` est le seul endroit qui sait faire la différence.
 */
final class MediaFile
{
    /**
     * Dossiers dans lesquels une suppression est autorisée, relatifs à ROOT.
     * Rien ne se supprime en dehors — pas même par un chemin qui « a l'air » bon.
     */
    public const RACINES = [
        '/storage/uploads',
        '/public/assets/img/generated',
        '/public/assets/video/generated',
    ];

    /**
     * Chemin disque absolu d'une référence lue dans files_json ou dans le
     * contenu d'un message. Null si la référence ne désigne aucun média connu
     * (URL tierce, chemin hors périmètre, chaîne vide).
     *
     * Le fichier n'a pas besoin d'exister : la méthode résout, elle ne teste pas.
     */
    public static function resoudre(string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        // Convention « URL web » : /assets/... → public/assets/...
        if (str_starts_with($reference, '/assets/')) {
            $chemin = parse_url($reference, PHP_URL_PATH);
            if (!is_string($chemin) || $chemin === '') {
                return null;
            }
            return self::dansUneRacine(ROOT . '/public' . $chemin);
        }

        // Convention « chemin de FS absolu » : uniquement s'il tombe dans une racine.
        if (str_starts_with($reference, '/')) {
            return self::dansUneRacine($reference);
        }

        return null;
    }

    /**
     * Le chemin est-il RÉELLEMENT à l'intérieur du dossier ?
     * `realpath()` des deux côtés : il résout `..` ET les liens symboliques, donc
     * un lien posé dans le dossier mais pointant ailleurs est rejeté. Le
     * séparateur final évite qu'un dossier voisin de préfixe commun
     * (`/generated-old`) passe pour un enfant de `/generated`.
     */
    public static function estConfine(string $chemin, string $dossierBase): bool
    {
        $reel = realpath($chemin);
        $base = realpath($dossierBase);
        if ($reel === false || $base === false) {
            return false;
        }
        return str_starts_with($reel, $base . DIRECTORY_SEPARATOR);
    }

    /**
     * Supprime un fichier confiné au dossier donné.
     * Retourne les octets réellement libérés, 0 si le fichier est absent, hors
     * périmètre, ou si l'`unlink` a échoué (droits) — l'appelant DOIT distinguer
     * ces cas dans son rapport : annoncer un succès sur un `unlink` muet serait
     * un mensonge.
     */
    public static function supprimerDans(string $chemin, string $dossierBase): int
    {
        if (!self::estConfine($chemin, $dossierBase)) {
            return 0;
        }

        $reel = (string)realpath($chemin);
        if (!is_file($reel)) {
            return 0;
        }

        $taille = @filesize($reel);
        $taille = $taille !== false ? (int)$taille : 0;

        return @unlink($reel) ? $taille : 0;
    }

    /**
     * Supprime un média désigné par une URL web, en le confinant à un dossier.
     * Conserve la signature historique d'AdminController::cleanupGeneratedAsset
     * (nettoyage des assets produits par les tests d'outils admin).
     */
    public static function supprimerAsset(string $assetUrl, string $dossierBase): int
    {
        if ($assetUrl === '') {
            return 0;
        }
        $chemin = parse_url($assetUrl, PHP_URL_PATH);
        if (!is_string($chemin) || $chemin === '') {
            return 0;
        }
        return self::supprimerDans(ROOT . '/public' . $chemin, $dossierBase);
    }

    /** Chemin rendu normalisé s'il tombe dans une des RACINES, null sinon. */
    private static function dansUneRacine(string $chemin): ?string
    {
        // Comparaison LEXICALE (et non realpath) : la référence peut viser un
        // fichier DÉJÀ supprimé, et resoudre() doit quand même savoir le nommer.
        // C'est précisément pourquoi normaliser() doit rejeter les `..` lui-même :
        // sans ça, `<racine>/../../etc/passwd` commencerait bien par la racine.
        $normalise = self::normaliser($chemin);
        if ($normalise === null) {
            return null;
        }
        foreach (self::RACINES as $racine) {
            $base = self::normaliser(ROOT . $racine);
            if ($base !== null && str_starts_with($normalise, $base . '/')) {
                return $normalise;
            }
        }
        return null;
    }

    /** Compacte les `/` redondants, refuse tout segment `..` ou octet nul. Null = chemin inacceptable. */
    private static function normaliser(string $chemin): ?string
    {
        if (str_contains($chemin, "\0")) {
            return null;
        }
        $chemin = (string)preg_replace('#/+#', '/', $chemin);
        foreach (explode('/', $chemin) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        return rtrim($chemin, '/');
    }
}
