<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Garde de non-régression de la source « OneDrive » des bases de connaissances
 * et de l'explorateur de dossiers (Google Drive « ma connexion » + OneDrive).
 *
 * Deux familles de tests :
 *  - la logique PURE des fournisseurs (parsing de références, normalisation
 *    d'items Graph, messages d'erreur) — aucun réseau, aucune base ;
 *  - le CÂBLAGE (routes, chargement des classes, migration, gabarits) : c'est
 *    lui qui casse en silence quand une brique est ajoutée d'un côté et
 *    oubliée de l'autre — le worker CLI et la page d'admin ne partageant que
 *    ces listes.
 */
final class OneDriveSyncTest extends TestCase
{
    // ===================== OneDriveProvider : références =====================

    public function testUneReferenceCompositeEstAcceptee(): void
    {
        $ref = OneDriveProvider::refFor('b!aBcD-1234_xyz', '01ABCDEF0123456789');
        $this->assertSame('b!aBcD-1234_xyz:01ABCDEF0123456789', $ref);
        $this->assertSame($ref, OneDriveProvider::parseFolderInput($ref));
    }

    public function testUneUrlDePartageEstAcceptee(): void
    {
        $url = 'https://contoso-my.sharepoint.com/:f:/g/personal/jean/EqZ123';
        $this->assertSame($url, OneDriveProvider::parseFolderInput($url));
    }

    public function testLesRacinesVirtuellesNeSontJamaisUnDossierSynchronisable(): void
    {
        // Elles ne désignent aucun dossier réel : les accepter enregistrerait
        // une base qui échouerait au premier cycle.
        $this->assertNull(OneDriveProvider::parseFolderInput(OneDriveProvider::REF_MY_DRIVE));
        $this->assertNull(OneDriveProvider::parseFolderInput(OneDriveProvider::REF_SHARED));
    }

    public function testUneSaisieIllisibleEstRefusee(): void
    {
        $this->assertNull(OneDriveProvider::parseFolderInput(''));
        $this->assertNull(OneDriveProvider::parseFolderInput('   '));
        $this->assertNull(OneDriveProvider::parseFolderInput('http://exemple.fr/dossier')); // pas https
        $this->assertNull(OneDriveProvider::parseFolderInput('mon dossier partagé'));
        $this->assertNull(OneDriveProvider::parseFolderInput(str_repeat('a', 300) . ':' . str_repeat('b', 300)));
    }

    // ===================== OneDriveProvider : items Graph =====================

    public function testUnDossierNEstPasUnFichierNormalise(): void
    {
        $this->assertNull(OneDriveProvider::normalizeItem(['id' => 'x', 'name' => 'Docs', 'folder' => ['childCount' => 2]]));
        $this->assertNull(OneDriveProvider::normalizeItem(['name' => 'sans id']));
    }

    public function testUnFichierGraphEstNormaliseAuFormatCommun(): void
    {
        $entry = OneDriveProvider::normalizeItem([
            'id'                   => '01ABC',
            'name'                 => 'Procédure.docx',
            'size'                 => 4096,
            'lastModifiedDateTime' => '2026-01-02T10:00:00Z',
            'file' => [
                'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'hashes'   => ['quickXorHash' => 'ABC123'],
            ],
        ]);

        $this->assertSame('01ABC', $entry['id']);
        $this->assertSame('Procédure.docx', $entry['name']);
        $this->assertSame(4096, $entry['size']);
        $this->assertSame('ABC123', $entry['hash']);
        $this->assertSame('2026-01-02T10:00:00Z', $entry['modified']);
        // Les fichiers Office de OneDrive sont de VRAIS docx : aucun export,
        // contrairement aux natifs Google.
        $this->assertNull($entry['export']);
    }

    public function testUnFichierSansEmpreinteRetombeSurLHorodatage(): void
    {
        $entry = OneDriveProvider::normalizeItem(['id' => 'x', 'name' => 'a.pdf', 'file' => ['mimeType' => 'application/pdf']]);
        $this->assertNull($entry['hash']);
        // Sans hash des deux côtés, KbSync compare l'horodatage opaque.
        $this->assertTrue(KbSync::isModified(
            $entry + ['modified' => '2026-01-02T10:00:00Z'],
            ['sync_hash' => null, 'sync_modified_at' => '2026-01-01T10:00:00Z']
        ));
    }

    public function testUnRaccourciVersUnAutreDriveEstSuivi(): void
    {
        // Piège vécu côté « Partagés avec moi » : l'id du raccourci n'est PAS
        // celui du dossier réel, et le dossier vit dans le drive d'autrui.
        $item = [
            'id'         => 'raccourci-local',
            'name'       => 'Équipe',
            'remoteItem' => [
                'id'              => 'vrai-item',
                'folder'          => ['childCount' => 3],
                'parentReference' => ['driveId' => 'drive-du-collegue'],
            ],
        ];
        $this->assertTrue(OneDriveProvider::isFolderLike($item));
        $this->assertSame(['drive-du-collegue', 'vrai-item'], OneDriveProvider::identityOf($item));
    }

    public function testLIdentiteRetombeSurLeDriveCourantQuandGraphNeLeRepetePas(): void
    {
        $item = ['id' => 'abc', 'folder' => []];
        $this->assertSame(['drive-courant', 'abc'], OneDriveProvider::identityOf($item, 'drive-courant'));
    }

    public function testLesErreursMicrosoftSontTraduitesSansFuite(): void
    {
        foreach ([401, 403, 404, 429, 500] as $status) {
            $msg = OneDriveProvider::presentHttpError($status)->getMessage();
            $this->assertNotSame('', $msg);
            $this->assertStringNotContainsString('Bearer', $msg);
            $this->assertStringNotContainsString('graph.microsoft.com', $msg);
        }
        $this->assertStringContainsString('Comptes connectés', OneDriveProvider::presentHttpError(401)->getMessage());
        $this->assertStringContainsString('introuvable', OneDriveProvider::presentHttpError(404)->getMessage());
    }

    // ===================== KbSync : le fournisseur est connu du moteur =====================

    public function testOneDriveEstUnFournisseurDuMoteur(): void
    {
        $this->assertContains('onedrive', KbSync::PROVIDERS);
        $this->assertSame('OneDrive', KbSync::providerLabel('onedrive'));
        $this->assertNotSame('', KbSync::missingCredentialsLabel('onedrive'));
        $this->assertNotSame('', KbSync::invalidFolderInputMessage('onedrive'));
    }

    public function testOneDriveExigeUneConnexionPersonnelleContrairementAuxAutres(): void
    {
        $this->assertTrue(KbSync::requiresOwnerAuth('onedrive'));
        $this->assertTrue(KbSync::supportsOwnerAuth('onedrive'));
        // Drive accepte les deux ; SharePoint et Agenda n'ont que le mode global.
        $this->assertTrue(KbSync::supportsOwnerAuth('drive'));
        $this->assertFalse(KbSync::requiresOwnerAuth('drive'));
        $this->assertFalse(KbSync::supportsOwnerAuth('sharepoint'));
        $this->assertFalse(KbSync::supportsOwnerAuth('gcalendar'));
    }

    public function testLaValidationDeSaisieEstRouteeVersLeBonFournisseur(): void
    {
        $ref = 'b!drive:01ITEM';
        $this->assertSame($ref, KbSync::parseFolderInput('onedrive', $ref));
        // Une référence OneDrive n'a aucun sens pour Google Drive.
        $this->assertNull(KbSync::parseFolderInput('drive', $ref));
        $this->assertNull(KbSync::parseFolderInput('inconnu', $ref));
    }

    public function testAucunClientGlobalPourOneDrive(): void
    {
        // Il n'existe pas d'accès « sans utilisateur » : le message doit le
        // dire, pas laisser croire à un identifiant manquant.
        $resolved = KbSync::resolveClient('onedrive');
        $this->assertNull($resolved['client']);
        $this->assertStringContainsString('connexion', mb_strtolower((string)$resolved['error']));
    }

    // ===================== Verdict « repris / ignoré » de l'explorateur =====================

    public function testLeVerdictDeLExplorateurSuitCeluiDuMoteur(): void
    {
        $files = [
            ['id' => '1', 'name' => 'a.pdf',  'mime' => 'application/pdf', 'size' => 1000,  'hash' => null, 'modified' => '', 'export' => null],
            ['id' => '2', 'name' => 'b.exe',  'mime' => 'application/x-msdownload', 'size' => 1000, 'hash' => null, 'modified' => '', 'export' => null],
            ['id' => '3', 'name' => 'c.pdf',  'mime' => 'application/pdf', 'size' => KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE + 1, 'hash' => null, 'modified' => '', 'export' => null],
            ['id' => '4', 'name' => 'd.gdoc', 'mime' => 'application/vnd.google-apps.document', 'size' => 0, 'hash' => null, 'modified' => '', 'export' => ['mime' => 'x', 'ext' => 'docx']],
        ];

        $report   = ['ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0];
        $retenus  = array_column(KbSync::selectSyncable($files, $report), 'id');
        $verdicts = array_values(array_map(
            static fn(array $f): string => KbSync::isSyncable($f) ? $f['id'] : '',
            $files
        ));

        // Le pictogramme de l'explorateur et le contenu réel de la base
        // doivent dire la même chose, sinon l'aperçu ment.
        $this->assertSame($retenus, array_values(array_filter($verdicts)));
    }

    // ===================== Explorateur Google Drive =====================

    public function testLaCleApiGlobaleNeParcourtPasUneArborescence(): void
    {
        // Une clé API n'est le Drive de personne : la racine n'existe pas.
        $this->expectException(RuntimeException::class);
        (new GoogleDriveProvider('AIzaFactice'))->browse('');
    }

    public function testLesRacinesVirtuellesDriveNeSontPasDesDossiers(): void
    {
        // « sharedWithMe » passe pourtant le motif d'id générique.
        $this->assertNull(GoogleDriveProvider::parseFolderInput(GoogleDriveProvider::REF_SHARED));
        $this->assertNull(GoogleDriveProvider::parseFolderInput(GoogleDriveProvider::REF_MY_DRIVE));
        $this->assertSame('1AbCdEfGhIjKlMnO', GoogleDriveProvider::parseFolderInput('1AbCdEfGhIjKlMnO'));
    }

    public function testLaNormalisationDriveEstPartageeParLeMiroirEtLExplorateur(): void
    {
        $entry = GoogleDriveProvider::normalizeFile([
            'id'           => '1abc',
            'name'         => 'Note.pdf',
            'mimeType'     => 'application/pdf',
            'size'         => '2048',
            'md5Checksum'  => 'deadbeef',
            'modifiedTime' => '2026-03-01T08:00:00.000Z',
        ]);
        $this->assertSame(2048, $entry['size']);
        $this->assertSame('deadbeef', $entry['hash']);
        $this->assertNull($entry['export']);

        $natif = GoogleDriveProvider::normalizeFile([
            'id' => '2', 'name' => 'Doc', 'mimeType' => 'application/vnd.google-apps.document',
        ]);
        $this->assertSame('docx', $natif['export']['ext']);
    }

    // ===================== MicrosoftOAuth =====================

    public function testLesPermissionsDemandeesCouvrentLaLectureEtLeRefresh(): void
    {
        $this->assertContains('offline_access', MicrosoftOAuth::SCOPES); // sinon aucun refresh token
        $this->assertContains('Files.Read.All', MicrosoftOAuth::SCOPES); // couvre « partagés avec moi »
        // Aucune permission d'écriture : la connexion est en lecture seule.
        foreach (MicrosoftOAuth::SCOPES as $scope) {
            $this->assertStringNotContainsString('ReadWrite', $scope);
        }
    }

    public function testLUriDeLInscriptionDedieeEstStable(): void
    {
        // L'inscription DÉDIÉE (facultative) a sa propre URI, affichée à
        // l'administrateur pour être déclarée dans Azure — la changer casse
        // les connexions. Sans inscription dédiée, OneDrive prend celle du
        // login (cf. OneDriveLoginAppFallbackTest).
        $this->assertStringEndsWith('/settings/onedrive/callback', MicrosoftOAuth::dedicatedRedirectUri());
        $this->assertSame('onedrive_', MicrosoftOAuth::STATE_PREFIX);
    }

    public function testLesCodesAadstsSontTraduitsEnGesteConcret(): void
    {
        $this->assertStringContainsString('secret', mb_strtolower(MicrosoftOAuth::authErrorMessage('AADSTS7000215: bad secret')));
        $this->assertStringContainsString('expiré', MicrosoftOAuth::authErrorMessage('AADSTS7000222'));
        $this->assertStringContainsString(
            MicrosoftOAuth::dedicatedRedirectUri(),
            MicrosoftOAuth::authErrorMessage('AADSTS50011: redirect_uri mismatch', MicrosoftOAuth::dedicatedRedirectUri())
        );
    }

    // ===================== Câblage =====================

    public function testLesClassesSontChargeesParLesDeuxPointsDEntree(): void
    {
        // config/classes.php est partagé par public/index.php ET le worker CLI :
        // une classe oubliée ici ne planterait QUE dans le worker, en prod.
        $classes = file_get_contents(ROOT . '/config/classes.php');
        foreach ([
            'src/Auth/MicrosoftOAuth.php',
            'src/Sync/BrowsableFolderProvider.php',
            'src/Sync/OneDriveProvider.php',
            'src/Drive/UserOneDriveService.php',
        ] as $file) {
            $this->assertStringContainsString($file, $classes, $file . ' absent de config/classes.php');
        }
    }

    public function testLesRoutesDeConnexionEtDExplorationExistent(): void
    {
        $routes = file_get_contents(ROOT . '/public/index.php');
        $this->assertStringContainsString("'/settings/onedrive/connect'", $routes);
        $this->assertStringContainsString("'/settings/onedrive/callback'", $routes);
        $this->assertStringContainsString("'/settings/onedrive/disconnect'", $routes);
        $this->assertStringContainsString("'/admin/knowledge-bases/sync/browse'", $routes);

        // L'explorateur vit sous « sync/… » (deux segments) : {id} n'en capture
        // qu'un, donc aucune collision avec /admin/knowledge-bases/{id}.
        $this->assertMatchesRegularExpression(
            '#/admin/knowledge-bases/sync/browse.+browseSyncFolder#',
            $routes
        );
    }

    public function testLaMigrationElargitLaProvenanceDesDocuments(): void
    {
        $sql = file_get_contents(ROOT . '/database/migrations/061_kb_onedrive_sync.sql');
        $this->assertStringContainsString("'onedrive'", $sql);
        $this->assertStringContainsString('knowledge_base_documents', $sql);
        // Les 4 provenances antérieures doivent survivre à l'ALTER.
        foreach (['upload', 'drive', 'sharepoint', 'gcalendar'] as $source) {
            $this->assertStringContainsString("'" . $source . "'", $sql);
        }
    }

    public function testLeJetonOneDriveAUnFournisseurDedie(): void
    {
        $this->assertSame('onedrive', UserDriveToken::PROVIDER_ONEDRIVE);
        $this->assertNotSame(UserDriveToken::PROVIDER, UserDriveToken::PROVIDER_ONEDRIVE);
        // Microsoft renvoie un refresh token ROULANT : sans persistance, la
        // connexion mourrait à l'expiration du premier jeton.
        $this->assertTrue(method_exists(UserDriveToken::class, 'updateRefreshToken'));
    }

    public function testLaFicheDeBaseExposeLaSourceEtLExplorateur(): void
    {
        $tpl = file_get_contents(ROOT . '/templates/admin/knowledge-bases/edit.php');
        $js  = file_get_contents(ROOT . '/public/assets/js/kb-form.js');
        $this->assertStringContainsString("'onedrive' => [", $tpl);
        $this->assertStringContainsString('name="sync_provider" value="<?= htmlspecialchars($value) ?>"', $tpl);
        $this->assertStringContainsString('kbSyncBrowseBtn', $tpl);
        // L'explorateur s'ouvre DANS la fiche (plus de fenêtre par-dessus).
        $this->assertStringContainsString('id="kbBrowse"', $tpl);
        $this->assertStringNotContainsString('kbBrowseModal', $tpl);
        $this->assertStringContainsString('/admin/knowledge-bases/sync/browse', $js);
        // Un « Parcourir… » sans mode d'emploi laisse deviner d'où viennent
        // les dossiers, et son absence laisse croire à un bug.
        $this->assertStringContainsString('kbSyncBrowseHelp', $tpl);
        $this->assertStringContainsString('Marche à suivre', $js);
    }

    public function testLaPageConnexionsPorteLaCarteOneDrive(): void
    {
        $tpl = file_get_contents(ROOT . '/templates/settings/connections.php');
        $this->assertStringContainsString('/settings/onedrive/connect', $tpl);
        $this->assertStringContainsString('/settings/onedrive/disconnect', $tpl);
        $this->assertStringContainsString('oneDriveToken', $tpl);
    }

    public function testLesIdentifiantsMicrosoftViventSurLaPageDesSources(): void
    {
        // Ils ne servent QU'aux bases : les mettre dans /admin/settings les
        // ferait éteindre à chaque enregistrement de cette page-là.
        $tpl = file_get_contents(ROOT . '/templates/admin/knowledge-bases-sync.php');
        $this->assertStringContainsString('microsoft_client_id', $tpl);
        $this->assertStringContainsString('microsoft_client_secret', $tpl);
        $this->assertStringContainsString('MicrosoftOAuth::dedicatedRedirectUri()', $tpl);

        // La marche à suivre vit sur l'écran où l'on colle les valeurs :
        // renvoyer au guide imposerait l'aller-retour entre deux fenêtres
        // pendant une manipulation Azure en huit étapes.
        $this->assertStringContainsString('Marche à suivre', $tpl);
        $this->assertStringContainsString('Files.Read.All', $tpl);
        $this->assertStringContainsString('AADSTS50011', $tpl);
        $this->assertStringContainsString('msRedirectCopyBtn', $tpl);

        $settings = file_get_contents(ROOT . '/templates/admin/settings.php');
        $this->assertStringNotContainsString('microsoft_client_id', $settings);
    }
}
