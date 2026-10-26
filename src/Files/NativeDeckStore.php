<?php

declare(strict_types=1);

/**
 * Présentations et classeurs réalisés par le modèle : le SEUL fichier livré
 * dans une conversation que MIA garde sur le disque.
 *
 * Les autres fichiers sont fabriqués au clic depuis le texte affiché (rien
 * n'est stocké). Celui-ci vient de l'environnement de code du fournisseur —
 * celui d'OpenAI disparaît après 20 minutes d'inactivité, et une seconde
 * réalisation serait facturée : le fichier est rapatrié et gardé
 * RETENTION_DAYS jours, puis effacé par l'entretien du worker.
 *
 * Rangés dans un SOUS-dossier de storage/uploads (volume persistant) : le
 * balayage des orphelins de l'élagage ne descend pas dans les sous-dossiers
 * (PruningService::balayerOrphelins), il ne les prendrait donc pas pour des
 * pièces jointes abandonnées. Un fichier = `<jeton>.pptx` (ou `.xlsx`) +
 * `<jeton>.json` (nom, compte, conversation, modèle) ; le jeton (128 bits) est le seul
 * identifiant, et le téléchargement vérifie en plus le compte et la
 * conversation.
 */
final class NativeDeckStore
{
    public const RETENTION_DAYS = 30;

    /** Extensions rangées, et le type servi au téléchargement. */
    public const TYPES = [
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private static ?string $dir = null;

    /** Tests : dossier de rangement (null = celui de l'application). */
    public static function overrideDir(?string $dir): void
    {
        self::$dir = $dir;
    }

    public static function dir(): string
    {
        return self::$dir ?? rtrim(UPLOAD_DIR, '/') . '/presentations';
    }

    /** Adresse de téléchargement d'un fichier rangé. */
    public static function url(int $convId, string $token): string
    {
        return '/chat/' . $convId . '/presentation/' . $token;
    }

    /**
     * Range un fichier ; rend son jeton.
     *
     * @throws RuntimeException dossier non inscriptible
     */
    public static function save(string $bytes, string $filename, int $userId, int $convId, string $llm): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('dossier des présentations non inscriptible');
        }
        $token = bin2hex(random_bytes(16));
        $ext   = self::extensionOf($filename);
        $meta  = [
            'name'            => $filename,
            'user_id'         => $userId,
            'conversation_id' => $convId,
            'llm'             => $llm,
            'created_at'      => gmdate('c'),
        ];
        if (@file_put_contents($dir . '/' . $token . '.' . $ext, $bytes) === false
            || @file_put_contents($dir . '/' . $token . '.json', (string)json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
            @unlink($dir . '/' . $token . '.' . $ext);
            throw new RuntimeException('fichier réalisé non enregistré');
        }

        return $token;
    }

    /**
     * Fichier d'un jeton, s'il existe encore et appartient à ce compte et à
     * cette conversation.
     *
     * @return array{path: string, name: string, mime: string}|null
     */
    public static function find(string $token, int $userId, int $convId): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $meta = json_decode((string)@file_get_contents(self::dir() . '/' . $token . '.json'), true);
        $ext  = self::extensionOf(is_array($meta) ? (string)($meta['name'] ?? '') : '');
        $path = self::dir() . '/' . $token . '.' . $ext;
        if (!is_file($path) || !is_array($meta)
            || (int)($meta['user_id'] ?? 0) !== $userId
            || (int)($meta['conversation_id'] ?? 0) !== $convId
            || self::expired((int)@filemtime($path), time())) {
            return null;
        }

        return ['path' => $path, 'name' => (string)($meta['name'] ?? 'presentation.pptx'), 'mime' => self::TYPES[$ext]];
    }

    /** Extension rangée d'après le nom du fichier : .xlsx pour un classeur, .pptx sinon (pure). */
    public static function extensionOf(string $filename): string
    {
        return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) === 'xlsx' ? 'xlsx' : 'pptx';
    }

    /** Un fichier de cet âge doit-il partir ? (pure) */
    public static function expired(int $mtime, int $now): bool
    {
        return $mtime > 0 && $now - $mtime > self::RETENTION_DAYS * 86400;
    }

    /** Efface les fichiers échus (entretien du worker). Rend le nombre de fichiers effacés. */
    public static function purgeExpired(?int $now = null): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return 0;
        }
        $now   = $now ?? time();
        $count = 0;
        // Deux motifs plutôt que GLOB_BRACE, absent des libc qui ne le portent pas (musl).
        foreach (array_merge(glob($dir . '/*.pptx') ?: [], glob($dir . '/*.xlsx') ?: []) as $path) {
            if (!self::expired((int)@filemtime($path), $now)) {
                continue;
            }
            if (@unlink($path)) {
                $count++;
            }
            @unlink(substr($path, 0, -5) . '.json');
        }

        return $count;
    }
}
