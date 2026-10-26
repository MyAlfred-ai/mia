<?php

declare(strict_types=1);

/**
 * Sauvegarde / restauration de la configuration (page admin « Sauvegarde »).
 * Le travail réel est dans BackupService — ici : gardes, upload, download.
 */
class BackupController
{
    /** GET /admin/backup */
    public function index(): void
    {
        AuthMiddleware::requireAdmin();
        $coveredTables  = BackupService::coveredTables();
        $excludedTables = BackupService::EXCLUDED_TABLES;
        $fileDirs       = BackupService::FILE_DIRS;
        $success        = Session::getFlash('success');
        $error          = Session::getFlash('error');
        require ROOT . '/templates/admin/backup.php';
    }

    /** POST /admin/backup/export — construit l'archive et la sert. */
    public function export(): void
    {
        AuthMiddleware::requireAdmin();
        session_write_close(); // la construction peut prendre plusieurs secondes

        try {
            $zipPath = BackupService::export();
        } catch (\Throwable $e) {
            error_log('[Backup] export : ' . $e->getMessage());
            Session::start();
            Session::flash('error', t('Export impossible : {erreur}', ['erreur' => $e->getMessage()]));
            header('Location: /admin/backup');
            exit;
        }

        $size = (int)(@filesize($zipPath) ?: 0);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . BackupService::suggestedFileName() . '"');
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /** POST /admin/backup/import — restaure une archive uploadée. */
    public function import(): void
    {
        AuthMiddleware::requireAdmin();

        // Confirmation explicite : la restauration REMPLACE la configuration.
        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'REMPLACER') {
            Session::flash('error', t('Restauration annulée : tapez {mot} dans le champ de confirmation.', ['mot' => 'REMPLACER']));
            header('Location: /admin/backup');
            exit;
        }

        $file = $_FILES['backup_file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg  = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t("Fichier trop volumineux pour la limite d'upload du serveur (upload_max_filesize)."),
                UPLOAD_ERR_NO_FILE => t('Aucun fichier reçu — choisissez une archive de sauvegarde (.zip).'),
                default => t('Upload en échec (code {code}).', ['code' => $code]),
            };
            Session::flash('error', $msg);
            header('Location: /admin/backup');
            exit;
        }

        $tmp = (string)$file['tmp_name'];
        session_write_close(); // la restauration peut prendre plusieurs secondes

        try {
            $report = BackupService::import($tmp);
        } catch (\Throwable $e) {
            error_log('[Backup] import : ' . $e->getMessage());
            Session::start();
            Session::flash('error', t("Restauration impossible : {erreur} Aucune donnée n'a été modifiée (restauration tout-ou-rien côté base).", ['erreur' => $e->getMessage()]));
            header('Location: /admin/backup');
            exit;
        }

        $nbTables = count($report['tables']);
        $nbRows   = array_sum($report['tables']);
        $msg = t('Restauration terminée : {tables} ({lignes}), {fichiers}.', [
            'tables'   => I18n::plural($nbTables, '{n} table', '{n} tables'),
            'lignes'   => I18n::plural($nbRows, '{n} enregistrement', '{n} enregistrements', ['n' => Format::credits($nbRows)]),
            'fichiers' => I18n::plural((int)$report['files'], '{n} fichier', '{n} fichiers'),
        ]);
        if ((int)$report['reindexed'] > 0) {
            $msg .= ' ' . t('{n} base(s) de connaissances en cours de réindexation (vecteurs reconstruits en arrière-plan).', ['n' => (int)$report['reindexed']]);
        }
        if (!empty($report['skipped'])) {
            $msg .= ' ' . t('Tables absentes de la sauvegarde (non touchées) : {liste}.', ['liste' => implode(', ', $report['skipped'])]);
        }

        Session::start();
        Session::flash('success', $msg);
        header('Location: /admin/backup');
        exit;
    }
}
