<?php

declare(strict_types=1);

/**
 * Configuration et sélection des bases synchronisées (colonnes sync_* de la
 * migration 049) : RÀZ d'état au changement de source, filtrage des bases
 * dues par fournisseur configuré, diff multi-fournisseurs.
 */
final class KbSyncConfigTest extends DatabaseTestCase
{
    private function insertKb(string $name, int $isSystem = 0): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO knowledge_bases (name, embedding_provider, embedding_model, embedding_dims, is_active, is_system)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$name, 'openai', 'text-embedding-3-large', 1536, $isSystem]);
        return (int)$this->db()->lastInsertId();
    }

    public function testSetSyncConfigPoseEtDeconfigure(): void
    {
        $id = $this->insertKb('KB');

        KnowledgeBase::setSyncConfig($id, 'sharepoint', 'https://contoso.sharepoint.com/sites/X', true);
        $kb = KnowledgeBase::findById($id);
        $this->assertSame('sharepoint', $kb['sync_provider']);
        $this->assertSame('https://contoso.sharepoint.com/sites/X', $kb['sync_folder_ref']);
        $this->assertSame(1, (int)$kb['sync_enabled']);

        // Déconfiguration (retour « Fichiers uploadés ») : tout NULL, désactivée.
        KnowledgeBase::setSyncConfig($id, null, null, true);
        $kb = KnowledgeBase::findById($id);
        $this->assertNull($kb['sync_provider']);
        $this->assertNull($kb['sync_folder_ref']);
        $this->assertSame(0, (int)$kb['sync_enabled']);
    }

    public function testSetSyncConfigRazEtatSurChangementDeSource(): void
    {
        $id = $this->insertKb('KB');
        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', true);
        KnowledgeBase::setSyncState($id, [
            'status' => 'ok', 'error' => null, 'folder_name' => 'Dossier A',
            'report' => '{"added":3}', 'touch_last_sync' => true,
        ]);

        // Même source, même dossier : seul le toggle bouge, l'état est préservé.
        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', false);
        $kb = KnowledgeBase::findById($id);
        $this->assertSame(0, (int)$kb['sync_enabled']);
        $this->assertSame('ok', $kb['sync_status']);
        $this->assertSame('Dossier A', $kb['sync_folder_name']);
        $this->assertNotNull($kb['sync_last_at']);

        // Changement de fournisseur : RÀZ complète de l'état.
        KnowledgeBase::setSyncConfig($id, 'sharepoint', 'https://contoso.sharepoint.com/sites/B', true);
        $kb = KnowledgeBase::findById($id);
        $this->assertSame('sharepoint', $kb['sync_provider']);
        $this->assertNull($kb['sync_status']);
        $this->assertNull($kb['sync_folder_name']);
        $this->assertNull($kb['sync_report']);
        $this->assertNull($kb['sync_last_at']);
    }

    public function testDueForSyncFiltreParFournisseurEtSysteme(): void
    {
        $drive = $this->insertKb('KB Drive');
        KnowledgeBase::setSyncConfig($drive, 'drive', 'folderA12345', true);

        $sp = $this->insertKb('KB SharePoint');
        KnowledgeBase::setSyncConfig($sp, 'sharepoint', 'https://contoso.sharepoint.com/s/X', true);

        $system = $this->insertKb('KB Système', 1);
        KnowledgeBase::setSyncConfig($system, 'drive', 'folderS67890', true);

        $ids = static fn(array $rows): array => array_column($rows, 'id');

        // Jamais synchronisées (sync_last_at NULL) → dues, filtrées par provider.
        $this->assertSame([], KnowledgeBase::dueForSync(10, []));
        $this->assertSame([$drive], $ids(KnowledgeBase::dueForSync(10, ['drive'])));
        $this->assertSame([$sp], $ids(KnowledgeBase::dueForSync(10, ['sharepoint'])));

        $both = KnowledgeBase::dueForSync(10, ['drive', 'sharepoint']);
        $this->assertEqualsCanonicalizing([$drive, $sp], $ids($both));
        foreach ($both as $row) {
            $this->assertArrayHasKey('sync_provider', $row);
        }
        // La base système n'est jamais due.
        $this->assertNotContains($system, $ids($both));

        // Cycle récent → plus due ; cycle ancien → due à nouveau.
        KnowledgeBase::setSyncState($drive, ['touch_last_sync' => true]);
        $this->assertSame([], KnowledgeBase::dueForSync(10, ['drive']));
        $this->db()->prepare(
            'UPDATE knowledge_bases SET sync_last_at = NOW() - INTERVAL 30 MINUTE WHERE id = ?'
        )->execute([$drive]);
        $this->assertSame([$drive], $ids(KnowledgeBase::dueForSync(10, ['drive'])));
    }

    public function testSyncedDocsForKbTousFournisseursSaufUpload(): void
    {
        $id = $this->insertKb('KB');

        KnowledgeBaseDocument::create([
            'kb_id' => $id, 'file_name' => 'manuel.pdf', 'stored_name' => 'm.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'source' => 'upload',
        ]);
        KnowledgeBaseDocument::create([
            'kb_id' => $id, 'file_name' => 'drive.pdf', 'stored_name' => 'd.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'source' => 'drive',
            'sync_file_id' => 'gd-1', 'sync_hash' => 'aaa', 'sync_modified_at' => '2026-07-01T00:00:00Z',
        ]);
        KnowledgeBaseDocument::create([
            'kb_id' => $id, 'file_name' => 'sp.pdf', 'stored_name' => 's.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'source' => 'sharepoint',
            'sync_file_id' => 'sp-1', 'sync_hash' => null, 'sync_modified_at' => '2026-07-02T00:00:00Z',
        ]);

        // Diff multi-fournisseurs : les docs drive ET sharepoint, indexés par
        // sync_file_id — c'est ce qui purge l'ancienne source après une bascule.
        $docs = KnowledgeBaseDocument::syncedDocsForKb($id);
        $this->assertEqualsCanonicalizing(['gd-1', 'sp-1'], array_keys($docs));
        $this->assertSame('drive', $docs['gd-1']['source']);
        $this->assertSame('sharepoint', $docs['sp-1']['source']);

        $this->assertSame(1, KnowledgeBaseDocument::countBySource($id, 'upload'));
        $this->assertSame(1, KnowledgeBaseDocument::countBySource($id, 'sharepoint'));
    }

    // ===================== Mode « connexion du propriétaire » =====================

    public function testSetSyncConfigOwnerPoseEtNettoie(): void
    {
        $uid = $this->insertUser(42, 'admin');
        $id  = $this->insertKb('KB');

        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', true, $uid);
        $kb = KnowledgeBase::findById($id);
        $this->assertSame($uid, (int)$kb['sync_owner_user_id']);

        // Bascule vers SharePoint : le mode « connexion personnelle » n'existe
        // que pour Drive, l'owner ne doit pas survivre.
        KnowledgeBase::setSyncConfig($id, 'sharepoint', 'https://contoso.sharepoint.com/sites/X', true, $uid);
        $kb = KnowledgeBase::findById($id);
        $this->assertNull($kb['sync_owner_user_id']);

        // Retour Drive en mode clé API : owner null explicite.
        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', true, null);
        $this->assertNull(KnowledgeBase::findById($id)['sync_owner_user_id']);
    }

    public function testSetSyncConfigChangementDOwnerRemetLEtatAZero(): void
    {
        $u1 = $this->insertUser(51, 'admin');
        $u2 = $this->insertUser(52, 'admin');
        $id = $this->insertKb('KB');

        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', true, $u1);
        KnowledgeBase::setSyncState($id, ['status' => 'ok', 'folder_name' => 'Dossier A', 'touch_last_sync' => true]);

        // Même dossier, autre connexion : c'est un changement d'accès — l'état
        // du cycle précédent (posé avec d'autres droits) ne vaut plus rien.
        KnowledgeBase::setSyncConfig($id, 'drive', 'folderA12345', true, $u2);
        $kb = KnowledgeBase::findById($id);
        $this->assertSame($u2, (int)$kb['sync_owner_user_id']);
        $this->assertNull($kb['sync_status']);
        $this->assertNull($kb['sync_last_at']);
    }

    public function testDueForSyncModeOwnerSansCleApiGlobale(): void
    {
        $uid    = $this->insertUser(61, 'creator');
        $owner  = $this->insertKb('Owner');
        $simple = $this->insertKb('Cle API');
        KnowledgeBase::setSyncConfig($owner, 'drive', 'folderOwner123', true, $uid);
        KnowledgeBase::setSyncConfig($simple, 'drive', 'folderCle45678', true, null);

        // Aucun fournisseur configuré, owner exclu : rien ne tourne.
        $this->assertSame([], KnowledgeBase::dueForSync(10, [], 10, false));

        // Owner inclus : SEULE la base portée par une connexion part (la base
        // « clé API » attendrait une clé qui n'existe pas).
        $due = KnowledgeBase::dueForSync(10, [], 10, true);
        $this->assertSame([$owner], array_column($due, 'id'));

        // Clé API configurée : les deux partent.
        $due = KnowledgeBase::dueForSync(10, ['drive'], 10, true);
        $this->assertEqualsCanonicalizing([$owner, $simple], array_column($due, 'id'));
    }

    public function testResolveClientForKbOwnerSansConnexion(): void
    {
        // Owner renseigné mais connexion Drive absente (jamais connectée,
        // révoquée, ou OAuth non configuré — cas de l'environnement de test) :
        // le message nomme la connexion, JAMAIS de repli silencieux sur la clé.
        $uid = $this->insertUser(71, 'creator');
        $res = KbSync::resolveClientForKb([
            'sync_provider' => 'drive', 'sync_owner_user_id' => $uid,
        ]);
        $this->assertNull($res['client']);
        $this->assertStringContainsString('connexion Google Drive de User 71', (string)$res['error']);
    }

    public function testResolveClientForKbSansOwnerRetombeSurLaCle(): void
    {
        Settings::set(KbSync::SETTING_DRIVE_API_KEY, null);
        $res = KbSync::resolveClientForKb(['sync_provider' => 'drive', 'sync_owner_user_id' => null]);
        $this->assertNull($res['client']);
        $this->assertStringContainsString('non configurée', (string)$res['error']);
    }

    // ===================== Résolution des identifiants =====================

    /**
     * Clé API chiffrée avec une AUTRE ENCRYPTION_KEY. Blob figé (IV et clé
     * tierce déterministes) plutôt que généré : avec une clé fausse, le
     * contrôle de padding AES-CBC passe par hasard ~1 fois sur 256 — un blob
     * tiré au sort rendrait le test capricieux.
     */
    private const BLOB_AUTRE_CLE =
        'MDAwMDAwMDAwMDAwMDAwMFJmSStrM05uOVBsdndJZVNnUGVidFkzdWJnVkhxK0MzKytrSlFaM1FLazQ9';

    public function testResolveClientReglageAbsent(): void
    {
        Settings::set(KbSync::SETTING_DRIVE_API_KEY, null);

        $res = KbSync::resolveClient('drive');
        $this->assertNull($res['client']);
        $this->assertStringContainsString('non configurée', (string)$res['error']);
        $this->assertStringNotContainsString('illisible', (string)$res['error']);
    }

    public function testResolveClientReglagePresentMaisIndechiffrable(): void
    {
        // Cas vécu en recette : ENCRYPTION_KEY régénérée. Le réglage EST là,
        // donc « non configurée » envoyait l'admin vérifier un champ déjà bon.
        Settings::set(KbSync::SETTING_DRIVE_API_KEY, self::BLOB_AUTRE_CLE);

        $res = KbSync::resolveClient('drive');
        $this->assertNull($res['client']);
        $this->assertStringContainsString('illisible', (string)$res['error']);
        $this->assertStringContainsString('ENCRYPTION_KEY', (string)$res['error']);
        $this->assertStringNotContainsString('non configurée', (string)$res['error']);

        // Le fournisseur reste « configuré » : sinon le déclencheur paresseux
        // écarterait la base et l'erreur ne serait plus jamais rafraîchie.
        $this->assertTrue(KbSync::providerConfigured('drive'));
    }

    public function testResolveClientReglageValide(): void
    {
        Settings::set(KbSync::SETTING_DRIVE_API_KEY, Encryption::encrypt('AIzaSyFAKE-cle-de-test'));

        $res = KbSync::resolveClient('drive');
        $this->assertInstanceOf(GoogleDriveProvider::class, $res['client']);
        $this->assertNull($res['error']);
    }

    public function testTestConnectionDistingueAussiLesDeuxCas(): void
    {
        Settings::set(KbSync::SETTING_GCAL_API_KEY, self::BLOB_AUTRE_CLE);
        $res = KbSync::testConnection('gcalendar');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('illisible', (string)$res['error']);

        Settings::set(KbSync::SETTING_GCAL_API_KEY, null);
        $res = KbSync::testConnection('gcalendar');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Aucune clé API Google', (string)$res['error']);
    }
}
