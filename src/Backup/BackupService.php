<?php

declare(strict_types=1);

/**
 * Sauvegarde / restauration complète de la configuration MIA.
 *
 * Périmètre : utilisateurs (comptes, rôles, quotas, mémoires, connexions),
 * groupes et leurs droits, assistants (avec documents de référence et
 * liaisons), bases de connaissances (documents, extraits, partages, config de
 * sync), outils, clés API et paramètres de l'application — PLUS les fichiers
 * associés (documents, avatars, logos).
 *
 * PRINCIPE « toujours à jour » : les tables exportées ne sont PAS listées une
 * à une — on exporte TOUTES les tables du schéma SAUF une liste d'exclusion
 * explicite (données conversationnelles, historiques de consommation, files
 * éphémères). Une table ajoutée par une future migration est donc incluse
 * d'office, sans toucher à ce module. Le test de contrat
 * (BackupContractTest) casse si une exclusion référence une table disparue.
 *
 * Ce qui n'est PAS sauvegardé, à dessein :
 * - conversations, messages et partages de créations (données d'usage, pas de
 *   configuration — et volumineuses) ;
 * - journaux de consommation et résultats de tests (historique) ;
 * - files de jobs et actions en attente (éphémères) ;
 * - les vecteurs Qdrant : reconstructibles depuis les extraits MySQL — la
 *   restauration enfile automatiquement une réindexation par base.
 *
 * Les secrets (clés API, jetons OAuth) sont exportés tels quels, CHIFFRÉS
 * avec l'ENCRYPTION_KEY de l'instance : une restauration sur une instance
 * dont la clé diffère les rend illisibles (à re-saisir).
 *
 * Format : archive ZIP — manifest.json + tables/<table>.jsonl (une ligne JSON
 * par enregistrement, streamé : jamais toute la table en mémoire) +
 * files/<dossier relatif>/… pour les fichiers.
 */
class BackupService
{
    /** Version du format d'archive (incrémenter si la structure change). */
    public const FORMAT = 1;

    /**
     * Tables JAMAIS exportées. Tout le reste du schéma l'est —
     * y compris les tables futures.
     */
    public const EXCLUDED_TABLES = [
        'schema_migrations',            // l'instance cible gère ses propres migrations
        'async_jobs',                   // file de jobs éphémère
        'job_events',                   // flux rejouable des jobs
        'drive_pending_actions',        // approbations en attente (TTL 30 min)
        'conversations',                // données conversationnelles, pas de la config
        'messages',
        'creation_shares',
        'conversation_context_summaries', // résumés du début des longues conversations : du contenu, pas de la config
        'usage_logs',                   // historique de consommation
        'tool_usage_logs',
        'tool_usage_backfill_markers',
        'tool_test_runs',               // historique des tests d'outils
        'tool_test_items',
        'agent_turn_stats',             // télémétrie de l'agent : mesures de l'instance d'origine
        // Tâches programmées : des demandes PERSONNELLES (consignes, sources),
        // proches des conversations qu'elles alimentent — et une restauration
        // ne doit jamais relancer des exécutions automatiques ailleurs.
        'scheduled_tasks',
        'scheduled_task_runs',          // historique des exécutions
        // Appareils inscrits aux notifications : liés aux clés de CETTE
        // instance (non exportées non plus) — inutilisables ailleurs.
        'push_subscriptions',
        // Workflows : PERSONNELS comme les tâches programmées (un workflow
        // programmé restauré ailleurs repartirait tout seul), et leurs
        // exécutions sont du contenu, comme les conversations qu'elles écrivent.
        'workflows',
        'workflow_runs',
        'workflow_run_steps',
        'workflow_artifacts',
    ];

    /** Clés de settings éphémères, retirées de l'export. */
    public const EXCLUDED_SETTINGS_KEYS = [
        'job_worker_heartbeat_at',
        'job_maintenance_at',
        'app_code_version',
        // Dépense du mois mémorisée par le budget IA : propre à l'instance
        // d'origine (son grand livre n'est pas exporté), recalculée d'elle-même.
        'ai_budget_memo_json',
        // Paire VAPID des notifications : propre à l'instance. Restaurée
        // ailleurs, elle y enverrait au nom de l'instance d'origine ; l'instance
        // cible crée la sienne au premier besoin.
        'push_vapid_public',
        'push_vapid_private_enc',
    ];

    /**
     * Dossiers de fichiers embarqués dans l'archive (relatifs à ROOT).
     * Les médias générés (images/vidéos des conversations) sont exclus,
     * comme les conversations elles-mêmes.
     */
    public const FILE_DIRS = [
        'storage/profile_documents',
        'storage/knowledge_bases',
        'public/assets/img/profils',
        'public/assets/img/branding',
        // Modèle PowerPoint de l'entreprise (Organisation → Entreprise).
        'storage/branding',
    ];

    /** Tables réellement exportées : tout le schéma moins les exclusions. */
    public static function coveredTables(): array
    {
        $db     = Database::getInstance();
        $tables = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(
            array_map('strval', $tables),
            static fn(string $t): bool => !in_array($t, self::EXCLUDED_TABLES, true)
        ));
    }

    /**
     * Construit l'archive et renvoie son chemin (fichier temporaire — à
     * supprimer par l'appelant après envoi). Lève en cas d'échec.
     */
    public static function export(): string
    {
        $db      = Database::getInstance();
        $zipPath = tempnam(sys_get_temp_dir(), 'mia_backup_') ?: '';
        if ($zipPath === '') {
            throw new \RuntimeException(t('Impossible de créer le fichier temporaire.'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(t("Impossible de créer l'archive ZIP."));
        }

        $tmpFiles = [];
        try {
            $tables = self::coveredTables();
            $counts = [];

            foreach ($tables as $table) {
                // Un .jsonl temporaire par table, écrit ligne à ligne : jamais
                // toute la table en mémoire (les extraits des bases de
                // connaissances peuvent peser des dizaines de Mo).
                $tmp = tempnam(sys_get_temp_dir(), 'mia_tbl_') ?: '';
                if ($tmp === '') {
                    throw new \RuntimeException(t('Fichier temporaire indisponible.'));
                }
                $tmpFiles[] = $tmp;
                $fh = fopen($tmp, 'wb');
                if ($fh === false) {
                    throw new \RuntimeException(t('Écriture temporaire impossible.'));
                }

                $n    = 0;
                $stmt = $db->query('SELECT * FROM `' . $table . '`');
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    if ($table === 'settings'
                        && in_array((string)($row['key'] ?? ''), self::EXCLUDED_SETTINGS_KEYS, true)) {
                        continue;
                    }
                    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
                    $n++;
                }
                fclose($fh);
                $counts[$table] = $n;
                $zip->addFile($tmp, 'tables/' . $table . '.jsonl');
            }

            // Fichiers associés (documents, avatars, logos).
            $fileCount = 0;
            foreach (self::FILE_DIRS as $dir) {
                $abs = ROOT . '/' . $dir;
                if (!is_dir($abs)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    $rel = $dir . '/' . ltrim(substr((string)$file->getPathname(), strlen($abs)), '/');
                    $zip->addFile((string)$file->getPathname(), 'files/' . $rel);
                    $fileCount++;
                }
            }

            $latestMigration = (string)($db->query(
                'SELECT MAX(filename) FROM schema_migrations'
            )->fetchColumn() ?: '');

            $manifest = [
                'format'           => self::FORMAT,
                'app_version'      => defined('APP_VERSION') ? APP_VERSION : '?',
                'created_at'       => date('c'),
                'latest_migration' => $latestMigration,
                'tables'           => $counts,
                'dirs'             => self::FILE_DIRS,
                'files'            => $fileCount,
            ];
            $zip->addFromString('manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (!$zip->close()) {
                throw new \RuntimeException(t("Finalisation de l'archive impossible."));
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            throw $e;
        } finally {
            // Les temporaires ne peuvent partir qu'APRÈS close() (ZipArchive
            // les lit à la finalisation).
            foreach ($tmpFiles as $tmp) {
                @unlink($tmp);
            }
        }

        return $zipPath;
    }

    /** Nom de téléchargement proposé pour l'archive. */
    public static function suggestedFileName(): string
    {
        $app = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)Settings::get('app_name', 'MIA')) ?: 'MIA';
        return sprintf('%s-sauvegarde-%s.zip', $app, date('Y-m-d-His'));
    }

    /**
     * Lit le manifest d'une archive sans rien restaurer (écran de
     * confirmation). Lève si l'archive est illisible ou d'un format inconnu.
     */
    public static function inspect(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(t('Archive illisible (fichier corrompu ou pas un ZIP).'));
        }
        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                throw new \RuntimeException(t("Archive sans manifest.json — ce n'est pas une sauvegarde MIA."));
            }
            $manifest = json_decode((string)$raw, true);
            if (!is_array($manifest) || (int)($manifest['format'] ?? 0) < 1) {
                throw new \RuntimeException(t('Manifest illisible.'));
            }
            if ((int)$manifest['format'] > self::FORMAT) {
                throw new \RuntimeException(t("Sauvegarde d'un format plus récent que cette version de l'application — mettez d'abord l'application à jour."));
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * Restaure une archive : REMPLACE le contenu des tables couvertes et des
     * dossiers de fichiers par celui de la sauvegarde. Transactionnel côté
     * tables (tout ou rien) ; les fichiers sont remplacés ensuite.
     *
     * @return array{tables:array<string,int>, skipped:list<string>, files:int, reindexed:int}
     */
    public static function import(string $zipPath): array
    {
        $manifest = self::inspect($zipPath);

        // Refus d'une sauvegarde issue d'un schéma PLUS RÉCENT : ses lignes
        // porteraient des colonnes inconnues ici. L'inverse (sauvegarde plus
        // ancienne) passe : les colonnes ajoutées depuis prennent leur défaut.
        $db     = Database::getInstance();
        $latest = (string)($db->query('SELECT MAX(filename) FROM schema_migrations')->fetchColumn() ?: '');
        $backupMigration = (string)($manifest['latest_migration'] ?? '');
        if ($backupMigration !== '' && strcmp($backupMigration, $latest) > 0) {
            throw new \RuntimeException(t(
                "Sauvegarde issue d'une version plus récente du schéma ({sauvegarde} > {instance}) — mettez d'abord l'application à jour.",
                ['sauvegarde' => $backupMigration, 'instance' => $latest]
            ));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(t('Archive illisible.'));
        }

        $restored = [];
        $skipped  = [];
        try {
            $covered = self::coveredTables();

            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            $db->beginTransaction();
            try {
                foreach ($covered as $table) {
                    $idx = $zip->locateName('tables/' . $table . '.jsonl');
                    if ($idx === false) {
                        // Table apparue depuis la sauvegarde : on n'y touche pas.
                        $skipped[] = $table;
                        continue;
                    }
                    if ($table === 'settings') {
                        // Les clés propres à CETTE instance (battements,
                        // version, paire VAPID des notifications…) ne sont
                        // jamais dans l'archive : elles restent en place.
                        $in = implode(',', array_fill(0, count(self::EXCLUDED_SETTINGS_KEYS), '?'));
                        $db->prepare('DELETE FROM `settings` WHERE `key` NOT IN (' . $in . ')')
                           ->execute(self::EXCLUDED_SETTINGS_KEYS);
                    } else {
                        $db->exec('DELETE FROM `' . $table . '`');
                    }

                    $stream = $zip->getStream('tables/' . $table . '.jsonl');
                    if ($stream === false) {
                        throw new \RuntimeException(t("Lecture de {table} impossible dans l'archive.", ['table' => $table]));
                    }
                    $n = 0;
                    $prepared = [];
                    while (($line = fgets($stream)) !== false) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $row = json_decode($line, true);
                        if (!is_array($row) || $row === []) {
                            continue;
                        }
                        if ($table === 'settings'
                            && in_array((string)($row['key'] ?? ''), self::EXCLUDED_SETTINGS_KEYS, true)) {
                            continue; // gardée en place (cf. plus haut)
                        }
                        // Requête préparée par combinaison de colonnes (les
                        // lignes d'une même table partagent normalement la
                        // même — une seule préparation par table).
                        $cols = array_keys($row);
                        $sig  = implode(',', $cols);
                        if (!isset($prepared[$sig])) {
                            $prepared[$sig] = $db->prepare(
                                'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
                                . rtrim(str_repeat('?,', count($cols)), ',') . ')'
                            );
                        }
                        $prepared[$sig]->execute(array_values($row));
                        $n++;
                    }
                    fclose($stream);
                    $restored[$table] = $n;
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            } finally {
                $db->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            // Fichiers : remplacement dossier par dossier (uniquement ceux
            // déclarés par le manifest — un dossier inconnu est ignoré).
            $files = 0;
            $dirs  = array_values(array_intersect(
                array_map('strval', (array)($manifest['dirs'] ?? [])),
                self::FILE_DIRS
            ));
            foreach ($dirs as $dir) {
                $abs = ROOT . '/' . $dir;
                if (is_dir($abs)) {
                    self::clearDir($abs);
                } else {
                    @mkdir($abs, 0775, true);
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (!str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                    continue;
                }
                $rel = substr($name, strlen('files/'));
                // Confinement : uniquement sous un dossier déclaré, jamais de
                // traversée (une archive forgée ne peut pas écrire ailleurs).
                if (str_contains($rel, '..')) {
                    continue;
                }
                $inDeclared = false;
                foreach ($dirs as $dir) {
                    if (str_starts_with($rel, $dir . '/')) {
                        $inDeclared = true;
                        break;
                    }
                }
                if (!$inDeclared) {
                    continue;
                }
                $dest = ROOT . '/' . $rel;
                @mkdir(dirname($dest), 0775, true);
                $in  = $zip->getStream($name);
                $out = $in !== false ? fopen($dest, 'wb') : false;
                if ($in !== false && $out !== false) {
                    stream_copy_to_stream($in, $out);
                    $files++;
                }
                if (is_resource($in))  { fclose($in); }
                if (is_resource($out)) { fclose($out); }
            }
        } finally {
            $zip->close();
        }

        // Les réglages viennent d'être remplacés sous le cache mémoire.
        Settings::refresh();

        // Les vecteurs Qdrant ne voyagent pas : réindexation de chaque base
        // depuis ses extraits MySQL, en tâche de fond (dedup_key = pas de
        // doublon si une réindexation est déjà en file). Une base en direct
        // (MyBrain) n'a rien à réindexer : son contenu est resté dans MyBrain.
        $reindexed = 0;
        try {
            $kbIds = $db->query("SELECT id FROM knowledge_bases WHERE backend = 'local'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($kbIds as $kbId) {
                JobQueue::enqueueMaintenance('kb_reindex', (int)$kbId, 'Réindexation base #' . (int)$kbId);
                $reindexed++;
            }
        } catch (\Throwable $e) {
            error_log('[Backup] réindexation post-restauration : ' . $e->getMessage());
        }

        return ['tables' => $restored, 'skipped' => $skipped, 'files' => $files, 'reindexed' => $reindexed];
    }

    /** Vide un dossier récursivement (le dossier lui-même est conservé). */
    private static function clearDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                @rmdir((string)$entry->getPathname());
            } else {
                @unlink((string)$entry->getPathname());
            }
        }
    }
}
