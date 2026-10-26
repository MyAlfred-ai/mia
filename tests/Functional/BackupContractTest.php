<?php

declare(strict_types=1);

/**
 * Contrat du module Sauvegarde (« toujours à jour ») + aller-retour réel.
 *
 * Le module exporte TOUTES les tables du schéma sauf une liste d'exclusion
 * explicite : une table ajoutée par une future migration est donc couverte
 * d'office. Ces tests cassent si le contrat se dégrade — exclusion pointant
 * une table disparue (typo / table renommée), ou périmètre qui n'épouse plus
 * le schéma réel.
 */
final class BackupContractTest extends DatabaseTestCase
{
    public function testLesExclusionsPointentDesTablesExistantes(): void
    {
        $tables = Database::getInstance()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (BackupService::EXCLUDED_TABLES as $excluded) {
            $this->assertContains(
                $excluded,
                $tables,
                "Exclusion « $excluded » : table absente du schéma (renommée ou supprimée ?) — "
                . 'mettre à jour BackupService::EXCLUDED_TABLES.'
            );
        }
    }

    public function testToutesLesTablesSontCouvertesOuExclues(): void
    {
        $tables  = Database::getInstance()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $covered = BackupService::coveredTables();
        foreach ($tables as $table) {
            $this->assertTrue(
                in_array($table, $covered, true) || in_array($table, BackupService::EXCLUDED_TABLES, true),
                "Table « $table » ni couverte ni exclue — coveredTables() ne reflète plus le schéma."
            );
        }
        // Et une table couverte n'est jamais exclue (cohérence interne).
        $this->assertSame([], array_intersect($covered, BackupService::EXCLUDED_TABLES));
    }

    public function testExportPuisImportRestaureLesDonnees(): void
    {
        $db = $this->db();

        // Jeu de données minimal réparti sur plusieurs tables couvertes.
        $db->prepare("INSERT INTO users (google_id, email, name, role, is_active) VALUES (?,?,?,?,1)")
           ->execute(['gid-backup', 'backup@test.fr', 'Sauvegarde', 'admin']);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO profiles (name, system_prompt, is_active, created_by, is_personal) VALUES (?,?,1,?,1)')
           ->execute(['Assistant sauvegardé', 'Tu es un test.', $userId]);
        $profileId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO user_groups (name, is_active) VALUES (?,1)')->execute(['Groupe sauvegardé']);
        Settings::set('test_backup_marker', 'valeur-exportée');

        $zip = BackupService::export();
        $this->assertFileExists($zip);

        try {
            $manifest = BackupService::inspect($zip);
            $this->assertSame(BackupService::FORMAT, (int)$manifest['format']);
            $this->assertArrayHasKey('users', $manifest['tables']);
            $this->assertGreaterThan(0, (int)$manifest['tables']['users']);

            // Mutation puis restauration : l'état exporté doit revenir.
            $db->exec('DELETE FROM profiles');
            $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(['Écrasé', $userId]);
            Settings::set('test_backup_marker', 'valeur-écrasée');

            $report = BackupService::import($zip);

            $this->assertSame('Sauvegarde', $db->query("SELECT name FROM users WHERE id = $userId")->fetchColumn());
            $this->assertSame(
                'Assistant sauvegardé',
                $db->query("SELECT name FROM profiles WHERE id = $profileId")->fetchColumn()
            );
            Settings::refresh();
            $this->assertSame('valeur-exportée', Settings::get('test_backup_marker'));
            $this->assertGreaterThanOrEqual(3, count($report['tables']));
        } finally {
            @unlink($zip);
            $db->prepare("DELETE FROM settings WHERE `key` = 'test_backup_marker'")->execute();
            Settings::refresh();
        }
    }

    public function testUneRestaurationGardeLesReglagesPropresALInstance(): void
    {
        $db = $this->db();
        $avant = [];
        foreach (BackupService::EXCLUDED_SETTINGS_KEYS as $cle) {
            $avant[$cle] = Settings::get($cle);
        }
        // Paire de clés des notifications de l'instance d'origine…
        Settings::set(WebPush::SETTING_PUBLIC, 'cle-de-l-origine');
        $zip = BackupService::export();
        try {
            // … jamais exportée…
            $zipArchive = new \ZipArchive();
            $zipArchive->open($zip);
            $settings = (string)$zipArchive->getFromName('tables/settings.jsonl');
            $zipArchive->close();
            $this->assertStringNotContainsString('push_vapid_public', $settings);

            // … et, à la restauration, celle de l'instance qui restaure reste
            // en place : les appareils inscrits continuent de recevoir.
            Settings::set(WebPush::SETTING_PUBLIC, 'cle-de-la-cible');
            BackupService::import($zip);
            Settings::refresh();
            $this->assertSame('cle-de-la-cible', Settings::get(WebPush::SETTING_PUBLIC));
        } finally {
            @unlink($zip);
            foreach ($avant as $cle => $valeur) {
                if ($valeur === null) {
                    $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$cle]);
                } else {
                    Settings::set($cle, $valeur);
                }
            }
            Settings::refresh();
        }
    }
}
