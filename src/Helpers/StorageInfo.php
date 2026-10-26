<?php

declare(strict_types=1);

/**
 * Mesure de l'empreinte disque de l'application pour le tableau de bord admin :
 * base MySQL (information_schema), base vectorielle Qdrant (mesure EXACTE du
 * volume qdrant_data monté en lecture seule — QDRANT_DATA_PATH —, sinon
 * estimation via l'API en secours), médias générés, documents utilisateurs
 * (uploads chat + bases de connaissances + docs assistants) et le reste de
 * l'application (code, assets, logs, cache).
 *
 * Le calcul parcourt tout le système de fichiers : résultat mis en cache
 * CACHE_TTL secondes dans storage/cache (le dashboard reste instantané).
 * Composant non mesurable (MySQL ou Qdrant injoignable) → null, affiché
 * « indisponible » et exclu du total.
 */
final class StorageInfo
{
    /** Durée de vie du cache (secondes). */
    private const CACHE_TTL = 600;

    private const CACHE_FILE = '/storage/cache/storage_info.json';

    /**
     * Répertoires des médias générés (images + vidéos), relatifs à ROOT.
     * Publique : l'élagage (PruningService) mesure et balaie les mêmes dossiers,
     * une seconde liste finirait par diverger de celle du tableau de bord.
     */
    public const GENERATED_DIRS = [
        '/public/assets/img/generated',
        '/public/assets/video/generated',
    ];

    /**
     * Répertoires des documents utilisateurs, relatifs à ROOT.
     * ATTENTION : cette liste n'est PAS un périmètre de balayage. Seul
     * /storage/uploads est référencé par messages ; knowledge_bases et
     * profile_documents le sont par knowledge_base_documents et
     * profile_documents — les balayer avec l'index de références des messages
     * les effacerait intégralement (cf. PruningService::DOSSIERS_BALAYES).
     */
    public const DOCUMENT_DIRS = [
        '/storage/uploads',
        '/storage/knowledge_bases',
        '/storage/profile_documents',
    ];

    /**
     * Instantané (mis en cache) de l'empreinte disque.
     *
     * @return array{
     *     mysql: int|null, qdrant: int|null, qdrant_exact: bool, generated: int,
     *     documents: int, app: int, total: int, computed_at: string
     * }
     */
    public static function snapshot(): array
    {
        $cacheFile = ROOT . self::CACHE_FILE;
        if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) < self::CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['total'], $cached['computed_at'])
                && array_key_exists('qdrant_exact', $cached)) {
                return $cached;
            }
        }

        $data = self::compute();

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $data;
    }

    /**
     * Jette l'instantané en cache : le prochain snapshot() recalcule.
     * Appelé après un élagage — sinon le tableau de bord afficherait l'empreinte
     * d'AVANT pendant dix minutes, juste après avoir libéré des gigaoctets.
     */
    public static function invalidate(): void
    {
        @unlink(ROOT . self::CACHE_FILE);
    }

    /** @return array{mysql:int|null,qdrant:int|null,qdrant_exact:bool,generated:int,documents:int,app:int,total:int,computed_at:string} */
    private static function compute(): array
    {
        $generated = 0;
        foreach (self::GENERATED_DIRS as $dir) {
            $generated += self::dirBytes(ROOT . $dir);
        }

        $documents = 0;
        foreach (self::DOCUMENT_DIRS as $dir) {
            $documents += self::dirBytes(ROOT . $dir);
        }

        // Le reste du projet = code, templates, assets, logs, cache… en
        // excluant .git et les répertoires déjà comptés ci-dessus.
        $excluded = array_map(
            static fn(string $rel): string => ROOT . $rel,
            array_merge(['/.git'], self::GENERATED_DIRS, self::DOCUMENT_DIRS)
        );
        $app = self::dirBytes(ROOT, $excluded);

        $mysql = self::mysqlBytes();
        [$qdrant, $qdrantExact] = self::qdrantBytes();

        return [
            'mysql'        => $mysql,
            'qdrant'       => $qdrant,
            'qdrant_exact' => $qdrantExact,
            'generated'    => $generated,
            'documents'    => $documents,
            'app'          => $app,
            'total'        => $app + $generated + $documents + ($mysql ?? 0) + ($qdrant ?? 0),
            'computed_at'  => date('Y-m-d H:i'),
        ];
    }

    /** Taille de la base MySQL (données + index), null si indisponible. */
    private static function mysqlBytes(): ?int
    {
        try {
            $stmt = Database::getInstance()->query(
                'SELECT COALESCE(SUM(data_length + index_length), 0)
                   FROM information_schema.TABLES
                  WHERE table_schema = DATABASE()'
            );
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Empreinte de l'index vectoriel : mesure EXACTE du volume qdrant_data
     * s'il est monté (QDRANT_DATA_PATH, lecture seule dans le compose), sinon
     * estimation via l'API Qdrant. [octets|null, mesure exacte ?]
     *
     * @return array{0:int|null,1:bool}
     */
    private static function qdrantBytes(): array
    {
        if (defined('QDRANT_DATA_PATH') && is_dir(QDRANT_DATA_PATH)) {
            return [self::dirBytes(QDRANT_DATA_PATH), true];
        }
        try {
            return [(new QdrantStore())->estimatedStorageBytes(), false];
        } catch (\Throwable) {
            return [null, false];
        }
    }

    /**
     * Taille récursive d'un répertoire (octets). Liens symboliques ignorés,
     * entrées illisibles sautées, répertoire absent → 0.
     *
     * Publique : l'élagage mesure les mêmes dossiers et ne doit pas réécrire un
     * second parcours de système de fichiers (mêmes pièges de liens symboliques
     * et de répertoires partiellement illisibles à re-payer).
     *
     * @param list<string> $excluded Chemins absolus à ne pas parcourir.
     */
    public static function dirBytes(string $path, array $excluded = []): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        try {
            $inner = new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            );
            $filter = new \RecursiveCallbackFilterIterator(
                $inner,
                static function (\SplFileInfo $file) use ($excluded): bool {
                    if ($file->isLink()) {
                        return false;
                    }
                    foreach ($excluded as $skip) {
                        if ($file->getPathname() === $skip) {
                            return false;
                        }
                    }
                    return true;
                }
            );
            $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $size = @$file->getSize();
                    $bytes += $size !== false ? (int)$size : 0;
                }
            }
        } catch (\Throwable) {
            // Répertoire partiellement illisible : total partiel plutôt que rien.
        }

        return $bytes;
    }
}
