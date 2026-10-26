<?php

declare(strict_types=1);

/**
 * Bibliothèque nommée d'avatars d'assistants.
 *
 * Fichiers : public/assets/img/profils/ (volume Docker `profile_avatars`).
 * Les avatars système sont bakés dans profils-defaults/ et semés dans profils/
 * au démarrage (entrypoint) et à la volée (ensureDefaultsSeeded).
 * Le NOM de chaque avatar vit en base (table assistant_avatars), plus dérivé du
 * nom de fichier.
 */
class AssistantAvatar
{
    public const DIR_WEB  = '/assets/img/profils';
    public const MAX_SIZE = 2 * 1024 * 1024; // 2 Mo

    public const ALLOWED_MIMES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Avatars système (non supprimables). */
    public const DEFAULTS = [
        'alfred-calcul.png', 'alfred-lecteur.png', 'alfred-loupe.png',
        'alfred-peintre.png', 'alfred-poete.png', 'alfred-robot.png',
        'alfred-serveur.png', 'alfred-vieux.png', 'alfred-yoda.png',
        'alfred-mabel.png', 'alfred-edith.png', 'alfred-agnes.png',
        'mia-aide.png',
    ];

    private static function dir(): string         { return ROOT . '/public/assets/img/profils'; }
    private static function defaultsDir(): string { return ROOT . '/public/assets/img/profils-defaults'; }

    public static function isDefault(string $filename): bool
    {
        return in_array($filename, self::DEFAULTS, true);
    }

    /** Copie les avatars système manquants depuis profils-defaults/ vers profils/. */
    public static function ensureDefaultsSeeded(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        foreach (self::DEFAULTS as $f) {
            $dest = $dir . '/' . $f;
            if (is_file($dest)) {
                continue;
            }
            $src = self::defaultsDir() . '/' . $f;
            if (is_file($src)) {
                @copy($src, $dest);
            }
        }
    }

    /**
     * Réconcilie base ↔ disque : enregistre les fichiers non référencés (nom
     * dérivé) et purge les entrées dont le fichier a disparu.
     */
    public static function syncFromDisk(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $db = Database::getInstance();

        $entries = scandir($dir) ?: [];
        $insert  = $db->prepare('INSERT IGNORE INTO assistant_avatars (filename, name) VALUES (?, ?)');
        foreach ($entries as $entry) {
            if (self::isValidFilename($entry) && is_file($dir . '/' . $entry)) {
                $insert->execute([$entry, self::deriveName($entry)]);
            }
        }

        foreach ($db->query('SELECT id, filename FROM assistant_avatars')->fetchAll() as $row) {
            if (!is_file($dir . '/' . $row['filename'])) {
                $db->prepare('DELETE FROM assistant_avatars WHERE id = ?')->execute([(int) $row['id']]);
            }
        }
    }

    /**
     * Bibliothèque complète, triée par nom.
     * @return array<int, array{id:int,filename:string,name:string,created_by:?int,is_default:bool}>
     */
    public static function all(): array
    {
        self::ensureDefaultsSeeded();
        self::syncFromDisk();

        $rows = Database::getInstance()
            ->query('SELECT id, filename, name, created_by FROM assistant_avatars ORDER BY name ASC')
            ->fetchAll();

        foreach ($rows as $i => $r) {
            $rows[$i]['id']         = (int) $r['id'];
            $rows[$i]['created_by'] = $r['created_by'] !== null ? (int) $r['created_by'] : null;
            $rows[$i]['is_default'] = self::isDefault((string) $r['filename']);
        }
        return $rows;
    }

    /**
     * Bibliothèque en lecture seule (nom de fichier → nom), sans la
     * réconciliation disque de all() : c'est ce que MIA propose quand elle
     * prépare un assistant, à chaque message où l'outil est exposé — un
     * balayage du dossier par message serait payer cher une liste qui change
     * rarement. Un fichier disparu est écarté.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        $out = [];
        $rows = Database::getInstance()
            ->query('SELECT filename, name FROM assistant_avatars ORDER BY name ASC')
            ->fetchAll();
        foreach ($rows as $r) {
            $file = (string)$r['filename'];
            if (self::fileExists($file)) {
                $out[$file] = (string)$r['name'];
            }
        }
        // Avatars système pas encore semés en base (premier démarrage).
        foreach (self::DEFAULTS as $file) {
            if (!isset($out[$file]) && self::fileExists($file)) {
                $out[$file] = self::deriveName($file);
            }
        }
        return $out;
    }

    /** Côté de l'avatar importé depuis une image (pixels). */
    public const IMPORT_SIZE = 512;

    /**
     * Importe une image du serveur (avatar créé par MIA pour un assistant)
     * dans la bibliothèque : recadrée au carré, centrée, réduite à
     * IMPORT_SIZE et enregistrée en PNG sous un nom généré, puis nommée.
     * Sans GD, copiée telle quelle si elle tient dans MAX_SIZE. Null en cas
     * d'échec — l'assistant est alors créé sans avatar, jamais refusé.
     */
    public static function importImage(string $sourcePath, string $name, ?int $userId): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        try {
            $rand = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            $rand = str_replace('.', '', uniqid('', true));
        }
        $base = 'assistant-' . date('Ymd-His') . '-' . $rand;

        $filename = $base . '.png';
        if (!self::writeSquarePng($sourcePath, $dir . '/' . $filename, self::IMPORT_SIZE)) {
            $mime = (string)(mime_content_type($sourcePath) ?: '');
            $ext  = self::ALLOWED_MIMES[$mime] ?? null;
            $size = (int)@filesize($sourcePath);
            if ($ext === null || $size <= 0 || $size > self::MAX_SIZE) {
                return null;
            }
            $filename = $base . '.' . $ext;
            if (!@copy($sourcePath, $dir . '/' . $filename)) {
                return null;
            }
        }
        self::register($filename, $name, $userId);
        return $filename;
    }

    /** Recadrage carré centré + réduction (GD), PNG avec transparence. */
    private static function writeSquarePng(string $source, string $dest, int $side): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $data = @file_get_contents($source);
        $src  = is_string($data) && $data !== '' ? @imagecreatefromstring($data) : false;
        if ($src === false) {
            return false;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $crop = min($w, $h);
        if ($crop < 1) {
            return false;
        }
        $out  = min($side, $crop);
        $dst  = imagecreatetruecolor($out, $out);
        if ($dst === false) {
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $crop, 2), intdiv($h - $crop, 2), $out, $out, $crop, $crop);
        $ok = @imagepng($dst, $dest, 6);
        return $ok && is_file($dest);
    }

    public static function findByFilename(string $filename): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM assistant_avatars WHERE filename = ?');
        $stmt->execute([$filename]);
        return $stmt->fetch() ?: null;
    }

    public static function nameFor(string $filename): string
    {
        $row = self::findByFilename($filename);
        return $row['name'] ?? self::deriveName($filename);
    }

    /** Enregistre (ou met à jour le nom d') un avatar après upload du fichier. */
    public static function register(string $filename, string $name, ?int $userId): void
    {
        $name = self::sanitizeName($name);
        if ($name === '') {
            $name = self::deriveName($filename);
        }
        Database::getInstance()->prepare(
            'INSERT INTO assistant_avatars (filename, name, created_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        )->execute([$filename, $name, $userId]);
    }

    public static function rename(string $filename, string $name): bool
    {
        $name = self::sanitizeName($name);
        if ($name === '') {
            return false;
        }
        Database::getInstance()->prepare('UPDATE assistant_avatars SET name = ? WHERE filename = ?')
            ->execute([$name, $filename]);
        return true;
    }

    public static function deleteByFilename(string $filename): void
    {
        Database::getInstance()->prepare('DELETE FROM assistant_avatars WHERE filename = ?')->execute([$filename]);
    }

    /** « Assistants — Gérer » (admin) = tout ; sinon les avatars qu'il a ajoutés. */
    public static function manageableBy(array $avatar, array $user): bool
    {
        if (Role::userCan($user, 'assistants.manage')) {
            return true;
        }
        return (int) ($avatar['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    }

    public static function isValidFilename(string $filename): bool
    {
        $name = trim($filename);
        if ($name === '' || basename($name) !== $name) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return false;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    public static function fileExists(string $filename): bool
    {
        return self::isValidFilename($filename) && is_file(self::dir() . '/' . $filename);
    }

    public static function sanitizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return mb_substr($name, 0, 120);
    }

    public static function deriveName(string $filename): string
    {
        $base  = pathinfo($filename, PATHINFO_FILENAME);
        $label = str_replace(['alfred-', 'assistant-', '_', '-'], ['', '', ' ', ' '], $base);
        $label = trim((string) preg_replace('/\s+/', ' ', $label));
        if ($label === '' || preg_match('/^[0-9]/', $label)) {
            $label = 'Avatar';
        }
        return ucfirst($label);
    }

    /**
     * Déplace un fichier uploadé dans profils/ et renvoie son nom de fichier.
     * @param array<string, mixed> $file entrée de $_FILES
     * @throws \RuntimeException en cas d'erreur d'upload/format/taille.
     */
    public static function storeUpload(array $file): ?string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            throw new \RuntimeException(t('Échec de l\'upload de l\'avatar.'));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_SIZE) {
            throw new \RuntimeException(t('Avatar invalide : taille maximale 2 Mo.'));
        }

        $mime = (string) (mime_content_type((string) $file['tmp_name']) ?: '');
        $ext  = self::ALLOWED_MIMES[$mime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException(t('Format non supporté. Acceptés : PNG, JPG, GIF, WebP.'));
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(t('Impossible de créer le dossier des avatars.'));
        }

        try {
            $rand = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            $rand = str_replace('.', '', uniqid('', true));
        }

        $filename = 'assistant-' . date('Ymd-His') . '-' . $rand . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $filename)) {
            throw new \RuntimeException(t('Impossible d\'enregistrer l\'avatar uploadé.'));
        }
        return $filename;
    }

    /** Supprime le fichier physique (utilisé après contrôle des permissions). */
    public static function deleteFile(string $filename): bool
    {
        $path = self::dir() . '/' . $filename;
        if (is_file($path)) {
            return @unlink($path);
        }
        return true;
    }
}
