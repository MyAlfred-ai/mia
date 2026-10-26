<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Parse de la saisie fichier (URL Docs/Sheets/Slides/Drive ou id brut). */
final class UserDriveServiceTest extends TestCase
{
    public function testUrlGoogleDocs(): void
    {
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            UserDriveService::parseFileInput('https://docs.google.com/document/d/1AbCdEfGhIjKlMnOp/edit')
        );
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            UserDriveService::parseFileInput('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOp/edit#gid=0')
        );
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            UserDriveService::parseFileInput('https://docs.google.com/presentation/d/1AbCdEfGhIjKlMnOp/edit')
        );
    }

    public function testUrlDriveFichier(): void
    {
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            UserDriveService::parseFileInput('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view?usp=sharing')
        );
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            UserDriveService::parseFileInput('https://drive.google.com/open?id=1AbCdEfGhIjKlMnOp')
        );
    }

    public function testIdBrut(): void
    {
        $this->assertSame('1AbCdEfGhIjKlMnOp', UserDriveService::parseFileInput('  1AbCdEfGhIjKlMnOp  '));
    }

    public function testRejets(): void
    {
        $this->assertNull(UserDriveService::parseFileInput(''));
        $this->assertNull(UserDriveService::parseFileInput('court'));
        $this->assertNull(UserDriveService::parseFileInput('id avec espaces interdits'));
        $this->assertNull(UserDriveService::parseFileInput('https://example.com/document/pas-drive'));
    }

    public function testNormalizeValuesTableau2D(): void
    {
        $this->assertSame(
            [['Nom', 'Montant'], ['Alice', '120']],
            UserDriveService::normalizeValues([['Nom', 'Montant'], ['Alice', '120']])
        );
    }

    public function testNormalizeValuesChaineJsonEtLigneScalaire(): void
    {
        // Chaîne JSON (certains LLM sérialisent le paramètre tableau).
        $this->assertSame(
            [['a', 'b'], ['c', 'd']],
            UserDriveService::normalizeValues('[["a","b"],["c","d"]]')
        );
        // Ligne scalaire enveloppée en ligne à une cellule.
        $this->assertSame([['seul']], UserDriveService::normalizeValues(['seul']));
    }

    public function testNormalizeValuesRejets(): void
    {
        $this->expectException(RuntimeException::class);
        UserDriveService::normalizeValues([]);
    }

    public function testNormalizeValuesChaineIllisible(): void
    {
        $this->expectException(RuntimeException::class);
        UserDriveService::normalizeValues('pas du json');
    }

    /**
     * Interrupteur global admin (clé Settings user_drive_enabled) : défaut
     * activé (continuité), '0' coupe, '1' réactive. Le cache Settings est
     * marqué chargé par réflexion pour éviter tout accès base en test unitaire.
     */
    public function testIsGloballyEnabled(): void
    {
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');

        // Clé absente → défaut activé ($loaded évite le load() en base ;
        // l'ancienne sentinelle « cache non vide » a été retirée, cf. Settings).
        $loaded->setValue(null, true);
        $cache->setValue(null, []);
        $this->assertTrue(UserDriveService::isGloballyEnabled());

        $cache->setValue(null, [UserDriveService::SETTING_ENABLED => '0']);
        $this->assertFalse(UserDriveService::isGloballyEnabled());

        $cache->setValue(null, [UserDriveService::SETTING_ENABLED => '1']);
        $this->assertTrue(UserDriveService::isGloballyEnabled());

        Settings::refresh();
    }

    // ---- disabledApiFromBody : 403 « API non activée » (SERVICE_DISABLED) ----

    public function testDisabledApiDocs(): void
    {
        $body = json_encode(['error' => [
            'code' => 403, 'status' => 'PERMISSION_DENIED',
            'message' => 'Google Docs API has not been used in project 123 before or it is disabled.',
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'SERVICE_DISABLED',
                'metadata' => ['service' => 'docs.googleapis.com'],
            ]],
        ]]);
        $this->assertSame('Google Docs', UserDriveService::disabledApiFromBody($body));
    }

    public function testDisabledApiSheetsLegacy(): void
    {
        $body = '{"error":{"errors":[{"domain":"usageLimits","reason":"accessNotConfigured"}],'
            . '"message":"Access Not Configured. sheets.googleapis.com"}}';
        $this->assertSame('Google Sheets', UserDriveService::disabledApiFromBody($body));
    }

    public function testDisabledApiServiceInconnu(): void
    {
        $this->assertSame('Google', UserDriveService::disabledApiFromBody('{"reason":"SERVICE_DISABLED"}'));
    }

    public function testPermissionDenieeSansServiceDisabled(): void
    {
        $this->assertNull(UserDriveService::disabledApiFromBody(
            '{"error":{"status":"PERMISSION_DENIED","message":"The caller does not have permission"}}'
        ));
        $this->assertNull(UserDriveService::disabledApiFromBody(''));
        $this->assertNull(UserDriveService::disabledApiFromBody('pas du json'));
    }

    public function testProjectNumberFromBody(): void
    {
        $this->assertSame('123456789', UserDriveService::projectNumberFromBody(
            'Google Docs API has not been used in project 123456789 before or it is disabled.'
        ));
        $this->assertSame('987654321', UserDriveService::projectNumberFromBody(
            '{"metadata":{"consumer":"projects/987654321","service":"docs.googleapis.com"}}'
        ));
        $this->assertNull(UserDriveService::projectNumberFromBody('aucun numéro ici'));
        $this->assertNull(UserDriveService::projectNumberFromBody(''));
    }

    // ---- actionPreview : aperçu humain des écritures soumises à approbation ----

    public function testActionPreviewCreerDoc(): void
    {
        $p = UserDriveService::actionPreview('creer_google_doc', [
            'titre'   => ' Compte-rendu ',
            'contenu' => str_repeat('a', 300),
        ]);
        $this->assertSame('Créer un Google Doc', $p['action']);
        $this->assertSame('Compte-rendu', $p['fichier']);
        $this->assertNull($p['url']);
        $this->assertStringContainsString('nouveau document', $p['impacts'][0]);
        // Extrait tronqué à 280 chars + ellipse.
        $this->assertSame(281, mb_strlen($p['apercu']));
        $this->assertStringEndsWith('…', $p['apercu']);
    }

    public function testActionPreviewCreerDocVide(): void
    {
        $p = UserDriveService::actionPreview('creer_google_doc', ['titre' => '']);
        $this->assertSame('Document sans titre', $p['fichier']);
        $this->assertContains('Le document sera créé vide.', $p['impacts']);
        $this->assertNull($p['apercu']);
    }

    public function testActionPreviewModifierDocRemplacerTout(): void
    {
        $ref = ['nom' => 'Roadmap', 'url' => 'https://docs.google.com/document/d/x/edit'];
        $p   = UserDriveService::actionPreview('modifier_google_doc', [
            'mode' => 'remplacer_tout', 'texte' => 'Nouveau contenu',
        ], $ref);
        $this->assertSame('Modifier un Google Doc', $p['action']);
        $this->assertSame('Roadmap', $p['fichier']);
        $this->assertSame($ref['url'], $p['url']);
        // L'irréversibilité est annoncée explicitement.
        $this->assertStringContainsString('INTÉGRALITÉ', $p['impacts'][0]);
        $this->assertStringContainsString('historique des versions', $p['impacts'][1]);
    }

    public function testActionPreviewModifierDocAjouterConserveContenu(): void
    {
        $p = UserDriveService::actionPreview('modifier_google_doc', [
            'mode' => 'ajouter', 'texte' => 'Suite',
        ], ['nom' => 'Notes', 'url' => 'u']);
        $this->assertStringContainsString('FIN de document', $p['impacts'][0]);
        $this->assertStringContainsString('conservé', $p['impacts'][1]);
    }

    public function testActionPreviewModifierSheetEcrirePlage(): void
    {
        $p = UserDriveService::actionPreview('modifier_google_sheet', [
            'mode' => 'ecrire_plage', 'plage' => 'Feuille1!B2:D3',
            'valeurs' => [['a', 'b', 'c'], ['d', 'e', 'f']],
        ], ['nom' => 'Budget', 'url' => 'u']);
        $this->assertStringContainsString('Feuille1!B2:D3', $p['impacts'][0]);
        $this->assertStringContainsString('2 lignes × 3 colonnes', $p['impacts'][0]);
        $this->assertStringContainsString('écrasées', $p['impacts'][1]);
    }

    public function testActionPreviewCreerSheetDimensions(): void
    {
        $p = UserDriveService::actionPreview('creer_google_sheet', [
            'titre' => 'Suivi', 'valeurs' => [['x']],
        ]);
        $this->assertStringContainsString('1 ligne × 1 colonne', $p['impacts'][1]);
    }
}
