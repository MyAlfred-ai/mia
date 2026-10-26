<?php

declare(strict_types=1);

/**
 * Stockage local des photos de profil.
 *
 * Contexte du bug corrigé :
 *  - Les URL de photo Google (lh3.googleusercontent.com) EXPIRENT après quelques
 *    jours → la photo "disparaît" même si l'utilisateur ne change rien.
 *  - Le dossier d'upload n'était pas monté en volume Docker → effacé à chaque
 *    rebuild/livraison.
 *
 * Solution : on télécharge la photo Google une fois et on la sert depuis notre
 * propre volume persistant (`public/assets/img/avatars`, monté en volume).
 */
class AvatarStorage
{
    public const DIR_URL  = '/assets/img/avatars';

    /** Poids maximal d'une photo choisie par l'utilisateur (fichier ou adresse). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Ce que demande un envoi du formulaire de profil (cf. requestedAction). */
    public const ACTION_KEEP   = 'keep';
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_IMPORT = 'import';
    public const ACTION_REMOVE = 'remove';

    /** Dossier de substitution (tests seulement). */
    private static ?string $dirOverride = null;

    private static function dirPath(): string
    {
        return self::$dirOverride ?? ROOT . '/public/assets/img/avatars';
    }

    /** Tests : écrit et supprime dans un dossier temporaire. Null = le vrai dossier. */
    public static function overrideDir(?string $dir): void
    {
        self::$dirOverride = $dir;
    }

    /** True si l'avatar est un fichier local servi par l'app (donc à préserver). */
    public static function isLocal(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, self::DIR_URL . '/');
    }

    /** True si l'avatar est un upload manuel de l'utilisateur (à ne jamais écraser). */
    public static function isUserUpload(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, self::DIR_URL . '/avatar_');
    }

    // ===================== Photo choisie par l'utilisateur =====================
    //
    // LE PROBLÈME RÉSOLU. La page Mon profil portait un champ « Ou URL d'image »
    // de type `url`, rempli avec l'avatar enregistré. Or un avatar téléversé ou
    // venu de Google est un chemin LOCAL (`/assets/img/avatars/…`), qu'un champ
    // `url` déclare invalide : l'enregistrement automatique refusait alors TOUT
    // envoi (« valeur incomplète »), fichier choisi compris — il fallait vider le
    // champ pour changer de photo. Et une adresse collée était gardée telle
    // quelle : URL distante qui expire, et photo écrasée par celle de Google à
    // la connexion suivante (seul un fichier `avatar_…` est protégé).
    //
    // Désormais le champ d'adresse est une ACTION, comme le choix d'un fichier :
    // vide (ou inchangé) = garder la photo ; une adresse = la télécharger à
    // travers les verrous de WebPageReader et l'enregistrer comme un fichier
    // choisi. Les deux chemins aboutissent au même `avatar_<id>_<hasard>.<ext>`.

    /**
     * Ce que demande un envoi du formulaire de profil. Le formulaire ENTIER
     * repart à chaque enregistrement automatique : seul un geste explicite
     * change la photo. Priorité : fichier, puis adresse, puis retrait.
     *
     * @param array<string, mixed>      $post
     * @param array<string, mixed>|null $file entrée de `$_FILES['avatar_file']`
     */
    public static function requestedAction(array $post, ?array $file, ?string $current): string
    {
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return self::ACTION_UPLOAD;
        }
        $url = trim((string)($post['avatar_url'] ?? ''));
        // Un chemin (ancienne page encore ouverte, qui reposte l'avatar local
        // enregistré) ne désigne rien à télécharger : rien ne change.
        if ($url !== '' && $url !== trim((string)$current) && !str_starts_with($url, '/')) {
            return self::ACTION_IMPORT;
        }
        if ((string)($post['remove_avatar'] ?? '') === '1') {
            return self::ACTION_REMOVE;
        }
        return self::ACTION_KEEP;
    }

    /**
     * Valeur du champ d'adresse à l'affichage : une adresse web enregistrée
     * (photo collée avant ce correctif), jamais un chemin local.
     */
    public static function urlFieldValue(?string $current): string
    {
        $current = trim((string)$current);
        return preg_match('#^https?://#i', $current) === 1 ? $current : '';
    }

    /** Message d'un échec de téléversement PHP, ou null si le fichier est arrivé. */
    public static function uploadError(int $code): ?string
    {
        return match ($code) {
            UPLOAD_ERR_OK                           => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::tooBigMessage(),
            default                                 => t('Le téléversement de l’image a échoué. Réessayez.'),
        };
    }

    /**
     * Enregistre un fichier téléversé (`$_FILES['avatar_file']`).
     *
     * @param array<string, mixed> $file
     * @throws \RuntimeException message traduit
     */
    public static function storeUpload(int $userId, array $file): string
    {
        $error = self::uploadError((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException(self::tooBigMessage());
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException(t('Le téléversement de l’image a échoué. Réessayez.'));
        }
        $bytes = @file_get_contents($tmp);
        if ($bytes === false) {
            throw new \RuntimeException(t('Le téléversement de l’image a échoué. Réessayez.'));
        }
        return self::storeUserImage($userId, $bytes);
    }

    /**
     * Télécharge l'image désignée par une adresse web et l'enregistre comme un
     * fichier choisi. Passe par WebPageReader : aucune adresse interne, aucune
     * redirection non revérifiée (une page de profil ne doit pas devenir une
     * porte vers le réseau de l'instance).
     *
     * @throws \RuntimeException message traduit
     */
    public static function importFromUrl(int $userId, string $url, ?WebPageReader $reader = null): string
    {
        try {
            $res = ($reader ?? new WebPageReader())->download(
                trim($url),
                'image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'
            );
        } catch (\RuntimeException $e) {
            // Le détail (en français, pour le journal) ne part pas à l'écran :
            // seul l'hôte est noté, une adresse peut porter un jeton.
            error_log('[AvatarStorage] Import refusé (' . (string)(parse_url(trim($url), PHP_URL_HOST) ?: '?')
                . ') : ' . $e->getMessage());
            throw new \RuntimeException(t('Impossible de récupérer l’image à cette adresse. Elle doit mener directement à une image publique (JPEG, PNG, WebP ou GIF).'));
        }
        if ($res['truncated'] || strlen($res['body']) > self::MAX_BYTES) {
            throw new \RuntimeException(self::tooBigMessage());
        }
        if (self::imageExtension($res['body']) === null) {
            throw new \RuntimeException(t('Cette adresse ne mène pas à une image JPEG, PNG, WebP ou GIF.'));
        }
        return self::storeUserImage($userId, $res['body']);
    }

    /**
     * Écrit une photo choisie par l'utilisateur, après en avoir vérifié la
     * NATURE sur les octets (jamais l'extension ni le type annoncé).
     *
     * @return string chemin web `/assets/img/avatars/avatar_<id>_<hasard>.<ext>`
     * @throws \RuntimeException message traduit
     */
    public static function storeUserImage(int $userId, string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException(self::tooBigMessage());
        }
        $ext = self::imageExtension($bytes);
        if ($ext === null) {
            throw new \RuntimeException(t('Format d’image non supporté (JPEG, PNG, WebP ou GIF).'));
        }

        $dir = self::dirPath();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[AvatarStorage] Impossible de créer ' . $dir);
            throw new \RuntimeException(t('La photo n’a pas pu être enregistrée. Réessayez plus tard.'));
        }
        $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $filename, $bytes) === false) {
            error_log('[AvatarStorage] Écriture échouée : ' . $dir . '/' . $filename);
            throw new \RuntimeException(t('La photo n’a pas pu être enregistrée. Réessayez plus tard.'));
        }
        @chmod($dir . '/' . $filename, 0664);
        return self::DIR_URL . '/' . $filename;
    }

    /**
     * Supprime l'ancienne photo d'un utilisateur quand il en change — seulement
     * un fichier qu'IL a choisi (`avatar_<son id>_…`), désigné par un nom sans
     * détour. L'ancien code supprimait tout chemin commençant par le dossier
     * des avatars, et le champ d'adresse acceptait n'importe quel texte : un
     * `…/avatars/../../index.php` enregistré là aurait été effacé au
     * changement suivant. Les photos venues d'un fournisseur restent (un nom
     * qui ne dit pas à qui il appartient) — un orphelin vaut mieux qu'une
     * suppression erronée.
     */
    public static function deleteUserFile(?string $url, int $userId): bool
    {
        $url = (string)$url;
        if (!str_starts_with($url, self::DIR_URL . '/')) {
            return false;
        }
        $name = substr($url, strlen(self::DIR_URL) + 1);
        if (preg_match('#^avatar_' . $userId . '_[A-Za-z0-9]+\.(?:jpg|png|gif|webp)$#', $name) !== 1) {
            return false;
        }
        $dir  = realpath(self::dirPath());
        $path = $dir !== false ? realpath($dir . '/' . $name) : false;
        if ($path === false || dirname($path) !== $dir || !is_file($path)) {
            return false;
        }
        return @unlink($path);
    }

    /** Extension d'une image JPEG, PNG, GIF ou WebP lisible, ou null. */
    public static function imageExtension(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $ext = self::extensionFromImage($bytes);
        // Les octets doivent aussi se DÉCODER en image : un fichier qui n'en a
        // que la signature (texte déguisé, image tronquée) est refusé.
        if ($ext !== null && function_exists('getimagesizefromstring') && @getimagesizefromstring($bytes) === false) {
            return null;
        }
        return $ext;
    }

    private static function tooBigMessage(): string
    {
        return t('L’image ne doit pas dépasser 2 Mo.');
    }

    /**
     * Résout l'avatar à persister pour un utilisateur connecté par SSO.
     *
     * Règles :
     *  - Si l'utilisateur a un upload manuel → on le conserve (priorité absolue).
     *  - Sinon on (re)télécharge la photo du fournisseur dans notre volume et on
     *    renvoie le chemin local. Le téléchargement réutilise un fichier déjà
     *    présent si la source n'a pas changé (clé = hash de l'URL).
     *  - En cas d'échec du téléchargement : on garde l'avatar local existant s'il
     *    y en a un, sinon on renvoie '' (pas d'URL distante périssable en base).
     *
     * Le préfixe de fichier reste `google_` pour le fournisseur Google : les
     * avatars déjà téléchargés gardent donc leur nom, aucun ne se re-télécharge
     * ni ne disparaît au déploiement.
     *
     * @param string|null $currentAvatarUrl avatar actuellement en base (peut être null)
     * @param string      $provider         'google' | 'microsoft'
     * @param string      $subject          identifiant chez le fournisseur (nommage stable)
     * @param string      $pictureUrl       URL de photo renvoyée (peut être vide)
     */
    public static function resolveForSsoUser(?string $currentAvatarUrl, string $provider, string $subject, string $pictureUrl): string
    {
        // 1) Upload manuel : on ne touche pas.
        if (self::isUserUpload($currentAvatarUrl)) {
            return (string)$currentAvatarUrl;
        }

        // 2) Pas de photo fournie : on garde un local existant, sinon vide.
        //    C'est le cas nominal de Microsoft, dont la photo n'est accessible
        //    que via Graph avec un jeton porteur — aucune URL publique.
        $pictureUrl = trim($pictureUrl);
        if ($pictureUrl === '' || !preg_match('#^https?://#i', $pictureUrl)) {
            return self::isLocal($currentAvatarUrl) ? (string)$currentAvatarUrl : '';
        }

        // 3) Télécharge la photo dans notre volume persistant.
        $local = self::downloadPicture($provider, $subject, $pictureUrl);
        if ($local !== null) {
            return $local;
        }

        // 4) Échec téléchargement : repli sur un local existant, jamais sur
        //    l'URL distante (qui expirerait). Sinon vide → initiales côté UI.
        return self::isLocal($currentAvatarUrl) ? (string)$currentAvatarUrl : '';
    }

    /**
     * Télécharge la photo du fournisseur et l'écrit dans le volume.
     * Nom de fichier déterministe par (sujet + hash URL) pour éviter de
     * réécrire à l'identique et permettre le nettoyage des anciennes versions.
     *
     * @return string|null chemin web `/assets/img/avatars/google_xxx.ext` ou null si échec
     */
    private static function downloadPicture(string $provider, string $subject, string $url): ?string
    {
        $dir = self::dirPath();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[AvatarStorage] Impossible de créer ' . $dir);
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 8,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header'        => "Accept: image/*\r\nUser-Agent: myLLM-avatar-fetch/1.0",
        ]]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') {
            error_log('[AvatarStorage] Téléchargement échoué : ' . $url);
            return null;
        }

        // Statut HTTP (file_get_contents remplit $http_response_header). Lu
        // par `??` : une lecture nue est dépréciée en PHP 8.5 (cf. CLAUDE.md).
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($status >= 400) {
            error_log('[AvatarStorage] HTTP ' . $status . ' sur ' . $url);
            return null;
        }

        // Détection MIME → extension.
        $ext = self::extensionFromImage($data);
        if ($ext === null) {
            error_log('[AvatarStorage] Contenu non-image pour ' . $url);
            return null;
        }

        // Taille max de sécurité : 5 Mo.
        if (strlen($data) > 5 * 1024 * 1024) {
            error_log('[AvatarStorage] Image trop volumineuse pour ' . $url);
            return null;
        }

        // Clé inchangée pour Google (sujet + URL) : les fichiers déjà écrits
        // gardent exactement le même nom.
        $key      = substr(hash('sha256', $subject . '|' . $url), 0, 16);
        $filename = $provider . '_' . $key . '.' . $ext;
        $destPath = $dir . '/' . $filename;
        $webPath  = self::DIR_URL . '/' . $filename;

        // Déjà présent (même URL Google) → pas de réécriture.
        if (is_file($destPath) && filesize($destPath) === strlen($data)) {
            return $webPath;
        }

        if (@file_put_contents($destPath, $data) === false) {
            error_log('[AvatarStorage] Écriture échouée : ' . $destPath);
            return null;
        }
        @chmod($destPath, 0664);

        // Nettoyage des anciennes photos de cet utilisateur (URL changée).
        self::cleanupOldAvatars($subject, $filename);

        return $webPath;
    }

    /** Supprime les anciens fichiers de ce user sauf celui courant. */
    private static function cleanupOldAvatars(string $subject, string $keepFilename): void
    {
        // Les fichiers ne contiennent pas le sujet en clair (hash combiné),
        // donc on ne peut pas filtrer par user de façon fiable. On se contente
        // de ne PAS supprimer agressivement : la conservation de quelques
        // fichiers orphelins est préférable à une suppression erronée.
        // (No-op volontaire : évite les effets de bord multi-utilisateurs.)
    }

    /** Renvoie l'extension (jpg/png/gif/webp) à partir des octets, ou null. */
    private static function extensionFromImage(string $data): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_buffer($finfo, $data) : false;
            return match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => null,
            };
        }

        // Fallback signatures binaires.
        if (str_starts_with($data, "\xFF\xD8\xFF")) return 'jpg';
        if (str_starts_with($data, "\x89PNG"))      return 'png';
        if (str_starts_with($data, 'GIF8'))         return 'gif';
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') return 'webp';
        return null;
    }
}
