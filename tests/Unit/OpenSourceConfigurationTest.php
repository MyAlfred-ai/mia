<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OpenSourceConfigurationTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string) file_get_contents(ROOT . '/' . $chemin);
    }

    public function testLeLienDeRetourEstOptInSansEcraserLesInstallationsExistantes(): void
    {
        $migrationInitiale = self::lire('database/migrations/058_settings_identite.sql');
        $migrationSuivante = self::lire('database/migrations/093_docs_url.sql');
        $gabarit            = self::lire('templates/about/index.php');
        $adminSettings      = self::lire('templates/admin/settings.php');
        $adminController    = self::lire('src/Controllers/AdminController.php');

        $this->assertMatchesRegularExpression("/\\('feedback_url',\\s*''\\)/", $migrationInitiale);
        $this->assertStringContainsString('INSERT IGNORE INTO settings', $migrationSuivante);
        $this->assertStringContainsString("if (\$feedbackUrl !== '')", $gabarit);
        $this->assertStringContainsString("Settings::get('docs_url', AboutController::DOCS_URL)", $gabarit);
        $this->assertStringContainsString('name="docs_url"', $adminSettings);
        $this->assertStringContainsString("'docs_url', 'feedback_url'", $adminController);
    }

    public function testLesURLsMobilesViennentDeLaConfigurationOuDeLOrigine(): void
    {
        $capacitor = self::lire('mobile/capacitor.config.ts');
        $pontNatif = self::lire('public/assets/js/native-bridge.js');

        $this->assertStringContainsString('const URL_PROD = process.env.MIA_SERVER_URL;', $capacitor);
        $this->assertStringContainsString('if (!URL_PROD)', $capacitor);
        $this->assertStringContainsString('throw new Error(', $capacitor);
        $this->assertStringContainsString('prod: location.origin', $pontNatif);
        $this->assertStringContainsString('staging: location.origin', $pontNatif);
    }
}
