<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Logique pure du moteur de sync : sélection/tri/plafond et détection de modification. */
final class KbSyncSelectionTest extends TestCase
{
    /** @return array<string, int> */
    private function rapportVide(): array
    {
        return [
            'listed' => 0, 'selected' => 0,
            'added' => 0, 'updated' => 0, 'renamed' => 0, 'deleted' => 0, 'failed' => 0,
            'ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0,
        ];
    }

    private function fichier(string $id, string $name, string $mime, int $size, ?array $export = null): array
    {
        return [
            'id' => $id, 'name' => $name, 'mime' => $mime, 'size' => $size,
            'hash' => null, 'modified' => '2026-07-01T00:00:00Z', 'export' => $export,
        ];
    }

    public function testSelectSyncableFiltres(): void
    {
        $report = $this->rapportVide();
        $files  = [
            $this->fichier('a', 'ok.pdf', 'application/pdf', 1000),
            $this->fichier('b', 'inconnu.xyz', 'application/x-foo', 1000),
            $this->fichier('c', 'vide.pdf', 'application/pdf', 0),
            $this->fichier('d', 'enorme.pdf', 'application/pdf', KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE + 1),
            // Natif exportable (Google Doc) : retenu malgré size 0 et mime google.
            $this->fichier('e', 'natif', 'application/vnd.google-apps.document', 0,
                GoogleDriveProvider::EXPORT_MAP['application/vnd.google-apps.document']),
        ];

        $out = KbSync::selectSyncable($files, $report);

        $this->assertSame(['e', 'a'], array_column($out, 'id')); // tri par nom : 'natif' < 'ok.pdf'
        $this->assertSame(2, $report['ignored_unsupported']);    // mime inconnu + taille 0
        $this->assertSame(1, $report['ignored_too_big']);
        $this->assertSame(0, $report['ignored_over_cap']);
    }

    public function testSelectSyncableTriDeterministeEtPlafond(): void
    {
        $report = $this->rapportVide();
        $files  = [];
        // 105 fichiers insérés en ordre inversé : le tri name/id doit primer.
        for ($i = 104; $i >= 0; $i--) {
            $files[] = $this->fichier('id' . $i, sprintf('doc-%03d.pdf', $i), 'application/pdf', 100);
        }

        $out = KbSync::selectSyncable($files, $report);

        $this->assertCount(KbSync::MAX_DOCS_PER_SYNCED_KB, $out);
        $this->assertSame(5, $report['ignored_over_cap']);
        $this->assertSame('doc-000.pdf', $out[0]['name']);
        $this->assertSame('doc-099.pdf', $out[count($out) - 1]['name']); // les 100 premiers alphabétiques
    }

    public function testIsModifiedParHash(): void
    {
        $remote = ['hash' => 'aaa', 'modified' => '2026-07-01T00:00:00Z'];

        $this->assertFalse(KbSync::isModified($remote, ['sync_hash' => 'aaa', 'sync_modified_at' => 'peu importe']));
        $this->assertTrue(KbSync::isModified($remote, ['sync_hash' => 'bbb', 'sync_modified_at' => '2026-07-01T00:00:00Z']));
    }

    public function testIsModifiedFallbackHorodatage(): void
    {
        // Hash absent d'un côté (Google Doc natif, tenant SharePoint chiffré…) :
        // l'horodatage distant est comparé comme chaîne opaque.
        $remote = ['hash' => null, 'modified' => '2026-07-02T10:00:00Z'];

        $this->assertFalse(KbSync::isModified($remote, ['sync_hash' => null, 'sync_modified_at' => '2026-07-02T10:00:00Z']));
        $this->assertTrue(KbSync::isModified($remote, ['sync_hash' => null, 'sync_modified_at' => '2026-07-01T09:00:00Z']));
        $this->assertTrue(KbSync::isModified(
            ['hash' => 'aaa', 'modified' => '2026-07-02T10:00:00Z'],
            ['sync_hash' => null, 'sync_modified_at' => '2026-07-01T09:00:00Z']
        ));
    }
}
