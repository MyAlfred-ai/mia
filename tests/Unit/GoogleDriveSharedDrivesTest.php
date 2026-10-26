<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Drive partagés comme source d'une base de connaissances.
 *
 * Ce que ce test protège :
 *  - l'explorateur n'offrait que « Mon Drive » et « Partagés avec moi » : un
 *    Drive partagé appartient à l'ORGANISATION, pas à un utilisateur, et
 *    n'apparaît dans aucun des deux — il était donc impossible à choisir ;
 *  - sans `supportsAllDrives` / `includeItemsFromAllDrives`, un fichier de
 *    Drive partagé répond 404 et un dossier de Drive partagé se liste vide :
 *    chaque requête du miroir doit les porter ;
 *  - le corpus par défaut ne couvre, côté Drive partagé, que les fichiers
 *    déjà ouverts par le compte : un dossier de Drive partagé se liste dans
 *    le corpus DE SON DRIVE, avec repli sur l'ancien comportement pour un
 *    compte non membre — et jamais de repli sur un quota ;
 *  - un listage que Google signale incomplet n'est jamais mirroré (les
 *    fichiers absents seraient supprimés de la base).
 *
 * Le transport HTTP est remplacé par un faux (surcharge de `httpGet`) : on
 * vérifie les requêtes émises et la lecture des réponses, sans réseau.
 */
final class GoogleDriveSharedDrivesTest extends TestCase
{
    /** Forme réelle d'un id de Drive partagé (commence par « 0A »). */
    private const DRIVE_ID = '0AFkTnSharedDrv01PVA';

    private const FOLDER = 'application/vnd.google-apps.folder';

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        // Les erreurs HTTP simulées sont journalisées par le fournisseur :
        // inutile d'en salir la sortie des tests.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
    }

    // ===================== Racines de l'explorateur =====================

    public function testLaRacineProposeLesDrivePartagesDansLOrdreDeGoogleDrive(): void
    {
        $p     = $this->provider([]);
        $level = $p->browse('');

        $this->assertSame(
            [GoogleDriveProvider::REF_MY_DRIVE, GoogleDriveProvider::REF_SHARED_DRIVES, GoogleDriveProvider::REF_SHARED],
            array_column($level['folders'], 'ref')
        );
        $this->assertSame(['Mon Drive', 'Drive partagés', 'Partagés avec moi'], array_column($level['folders'], 'name'));
        $this->assertFalse($level['selectable'], 'Une racine virtuelle ne se synchronise pas.');
        $this->assertSame([], $p->calls, 'La racine est construite sans appel à Google.');
    }

    public function testLaListeDesDrivePartagesNEstJamaisUnDossierSynchronisable(): void
    {
        // « sharedDrives » passe pourtant le motif d'id générique (≥ 10 caractères).
        $this->assertNull(GoogleDriveProvider::parseFolderInput(GoogleDriveProvider::REF_SHARED_DRIVES));
        $this->assertNull(KbSync::parseFolderInput('drive', GoogleDriveProvider::REF_SHARED_DRIVES));
    }

    public function testUnDrivePartageSeDesigneParSonIdOuSonLien(): void
    {
        $this->assertSame(self::DRIVE_ID, GoogleDriveProvider::parseFolderInput(self::DRIVE_ID));
        $this->assertSame(
            self::DRIVE_ID,
            GoogleDriveProvider::parseFolderInput('https://drive.google.com/drive/folders/' . self::DRIVE_ID)
        );
        // Compte secondaire du navigateur : l'URL porte « /u/1/ ».
        $this->assertSame(
            self::DRIVE_ID,
            GoogleDriveProvider::parseFolderInput('https://drive.google.com/drive/u/1/folders/' . self::DRIVE_ID)
        );
    }

    public function testLesDrivePartagesSontListesPageParPageEtTries(): void
    {
        $p = $this->provider([
            '/drives' => [
                [200, ['drives' => [['id' => '0AZzzzzzzzzzzzzzPVA', 'name' => 'Zeta RH']], 'nextPageToken' => 'page-2']],
                [200, ['drives' => [['id' => '0AAaaaaaaaaaaaaaPVA', 'name' => 'Alpha Commerce']]]],
            ],
        ]);

        $level = $p->browse(GoogleDriveProvider::REF_SHARED_DRIVES);

        $this->assertSame(['Alpha Commerce', 'Zeta RH'], array_column($level['folders'], 'name'));
        $this->assertSame(['0AAaaaaaaaaaaaaaPVA', '0AZzzzzzzzzzzzzzPVA'], array_column($level['folders'], 'ref'));
        $this->assertFalse($level['selectable'], 'La liste des Drive n\'est pas un dossier.');
        $this->assertSame([], $level['files']);
        $this->assertFalse($level['truncated']);

        $this->assertCount(2, $p->calls);
        $this->assertSame('/drives', $p->calls[0]['path']);
        $this->assertSame('100', $p->calls[0]['query']['pageSize'], 'Sans pageSize, Google ne rend que 10 Drive.');
        $this->assertSame('page-2', $p->calls[1]['query']['pageToken']);
        foreach ($p->calls as $call) {
            // Un administrateur Workspace verrait sinon tous les Drive de
            // l'organisation, y compris ceux dont il ne lit pas le contenu.
            $this->assertArrayNotHasKey('useDomainAdminAccess', $call['query']);
        }
    }

    public function testAucunDrivePartageEstExpliquePlutotQueVide(): void
    {
        $p     = $this->provider(['/drives' => [[200, ['drives' => []]]]]);
        $level = $p->browse(GoogleDriveProvider::REF_SHARED_DRIVES);

        $this->assertSame([], $level['folders']);
        $this->assertStringContainsString('Drive partagé', (string)($level['empty'] ?? ''));
        $this->assertStringContainsString('Partagés avec moi', (string)$level['empty']);
    }

    public function testSansIdentiteLesDrivePartagesNeSeParcourentPas(): void
    {
        $p = $this->provider([], false);
        try {
            $p->browse(GoogleDriveProvider::REF_SHARED_DRIVES);
            $this->fail('La clé API globale n\'est le compte de personne : aucun Drive partagé à lister.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connexion Google Drive', $e->getMessage());
        }
        $this->assertSame([], $p->calls);
    }

    // ===================== Descendre dans un Drive partagé =====================

    public function testOuvrirUnDrivePartageListeSaRacineSousLeNomDuDrive(): void
    {
        $p = $this->provider([
            '/files/' . self::DRIVE_ID => [[200, [
                'id' => self::DRIVE_ID, 'name' => 'Drive', 'mimeType' => self::FOLDER, 'driveId' => self::DRIVE_ID,
            ]]],
            '/drives/' . self::DRIVE_ID => [[200, ['name' => 'Équipe Commerciale']]],
            '/files' => [[200, ['files' => [
                ['id' => 'f1', 'name' => 'Tarifs.pdf', 'mimeType' => 'application/pdf', 'size' => '1000'],
                ['id' => 'd1', 'name' => 'Contrats', 'mimeType' => self::FOLDER],
            ]]]],
        ]);

        $level = $p->browse(self::DRIVE_ID);

        $this->assertSame(self::DRIVE_ID, $level['ref']);
        $this->assertSame('Équipe Commerciale', $level['name'], 'Le nom connu de l\'utilisateur est celui du Drive.');
        $this->assertSame(GoogleDriveProvider::REF_SHARED_DRIVES, $level['parent_ref']);
        $this->assertTrue($level['selectable'], 'Un Drive partagé se synchronise entier.');
        $this->assertSame(['Contrats'], array_column($level['folders'], 'name'));
        $this->assertSame(['Tarifs.pdf'], array_column($level['files'], 'name'));

        $get = $this->callsTo($p, '/files/' . self::DRIVE_ID)[0];
        $this->assertSame('true', $get['query']['supportsAllDrives']);
        $this->assertStringContainsString('driveId', $get['query']['fields']);

        $list = $this->callsTo($p, '/files')[0];
        $this->assertSame("'" . self::DRIVE_ID . "' in parents and trashed=false", $list['query']['q']);
        $this->assertSame('true', $list['query']['supportsAllDrives']);
        $this->assertSame('true', $list['query']['includeItemsFromAllDrives']);
        $this->assertSame('drive', $list['query']['corpora'], 'Sinon seuls les fichiers déjà ouverts remontent.');
        $this->assertSame(self::DRIVE_ID, $list['query']['driveId']);
    }

    public function testUnSousDossierDeDrivePartageRemonteVersSonParent(): void
    {
        $p = $this->provider([
            '/files/1ContratsFolderXYZ' => [[200, [
                'id' => '1ContratsFolderXYZ', 'name' => 'Contrats', 'mimeType' => self::FOLDER,
                'parents' => [self::DRIVE_ID], 'driveId' => self::DRIVE_ID,
            ]]],
            '/files' => [[200, ['files' => []]]],
        ]);

        $level = $p->browse('1ContratsFolderXYZ');

        $this->assertSame('Contrats', $level['name']);
        $this->assertSame(self::DRIVE_ID, $level['parent_ref']);
        $this->assertSame([], $this->callsTo($p, '/drives/' . self::DRIVE_ID), 'drives.get ne sert qu\'à la racine.');
        $this->assertSame(self::DRIVE_ID, $this->callsTo($p, '/files')[0]['query']['driveId']);
    }

    public function testMonDriveEtPartagesAvecMoiGardentLeCorpusParDefaut(): void
    {
        $p = $this->provider(['/files' => [[200, ['files' => []]]]]);
        $p->browse(GoogleDriveProvider::REF_MY_DRIVE);
        $p->browse(GoogleDriveProvider::REF_SHARED);

        foreach ($this->callsTo($p, '/files') as $call) {
            $this->assertArrayNotHasKey('corpora', $call['query']);
            $this->assertArrayNotHasKey('driveId', $call['query']);
        }
    }

    public function testLeNomDuDriveResteLisibleQuandDrivesGetEchoue(): void
    {
        $p = $this->provider([
            '/files/' . self::DRIVE_ID => [[200, [
                'id' => self::DRIVE_ID, 'name' => 'Équipe Commerciale', 'mimeType' => self::FOLDER, 'driveId' => self::DRIVE_ID,
            ]]],
            '/drives/' . self::DRIVE_ID => [[500, '{"error":{"code":500}}']],
        ]);

        // Le nom n'est qu'un affichage : son échec ne doit jamais faire échouer
        // le test du dossier ni un cycle de synchronisation.
        $this->assertSame(['name' => 'Équipe Commerciale'], $p->resolveFolder(self::DRIVE_ID));
    }

    public function testEnModeCleApiLeNomDuDriveVientDeFilesGet(): void
    {
        $p = $this->provider([
            '/files/' . self::DRIVE_ID => [[200, [
                'id' => self::DRIVE_ID, 'name' => 'Équipe Commerciale', 'mimeType' => self::FOLDER, 'driveId' => self::DRIVE_ID,
            ]]],
        ], false);

        $this->assertSame(['name' => 'Équipe Commerciale'], $p->resolveFolder(self::DRIVE_ID));
        $this->assertSame([], $this->callsTo($p, '/drives/' . self::DRIVE_ID), 'drives.get exige une identité.');
    }

    public function testLaRacineDUnDrivePartageSeReconnaitASonId(): void
    {
        $this->assertTrue(GoogleDriveProvider::isSharedDriveRoot(['id' => self::DRIVE_ID, 'driveId' => self::DRIVE_ID]));
        // Dossier À L'INTÉRIEUR d'un Drive partagé.
        $this->assertFalse(GoogleDriveProvider::isSharedDriveRoot(['id' => '1Sub', 'driveId' => self::DRIVE_ID]));
        // Dossier de « Mon Drive » : Google ne renseigne pas driveId.
        $this->assertFalse(GoogleDriveProvider::isSharedDriveRoot(['id' => '1Mine']));
        $this->assertFalse(GoogleDriveProvider::isSharedDriveRoot([]));
    }

    public function testUneReferenceForgeeNAtteintJamaisLaRequete(): void
    {
        $p = $this->provider([]);
        try {
            $p->browse("1abc' or trashed=true or '");
            $this->fail('Une apostrophe dans la référence finirait dans la requête `q`.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non reconnu', $e->getMessage());
        }
        $this->assertSame([], $p->calls);
    }

    // ===================== Miroir =====================

    public function testLeMiroirDUnDrivePartageParcourtToutDansLeCorpusDuDrive(): void
    {
        $p = $this->provider([
            // driveIdFor : le Drive du dossier, lu une fois avant le parcours.
            '/files/' . self::DRIVE_ID => [[200, ['id' => self::DRIVE_ID, 'driveId' => self::DRIVE_ID]]],
            '/files' => [
                [200, [
                    'files' => [
                        ['id' => 'sub1', 'name' => 'Contrats', 'mimeType' => self::FOLDER],
                        ['id' => 'f1', 'name' => 'Tarifs.pdf', 'mimeType' => 'application/pdf', 'size' => '10', 'md5Checksum' => 'a'],
                    ],
                    'nextPageToken' => 'suite',
                ]],
                [200, ['files' => [['id' => 'f2', 'name' => 'CGV.pdf', 'mimeType' => 'application/pdf', 'size' => '20']]]],
                [200, ['files' => [['id' => 'f3', 'name' => 'Contrat type', 'mimeType' => 'application/vnd.google-apps.document']]]],
            ],
        ]);

        $files = $p->listFolderRecursive(self::DRIVE_ID);

        $this->assertSame(['f1', 'f2', 'f3'], array_column($files, 'id'));
        $this->assertSame('docx', $files[2]['export']['ext'], 'Un Google Doc de Drive partagé s\'exporte comme les autres.');

        $lists = $this->callsTo($p, '/files');
        $this->assertCount(3, $lists);
        $this->assertSame("'" . self::DRIVE_ID . "' in parents and trashed=false", $lists[0]['query']['q']);
        $this->assertSame('suite', $lists[1]['query']['pageToken']);
        $this->assertSame("'sub1' in parents and trashed=false", $lists[2]['query']['q']);
        foreach ($lists as $call) {
            $this->assertSame('true', $call['query']['supportsAllDrives']);
            $this->assertSame('true', $call['query']['includeItemsFromAllDrives']);
            $this->assertStringContainsString('incompleteSearch', $call['query']['fields'], 'Sinon la garde est inerte.');
            // Tout le sous-arbre vit dans le même Drive : un seul corpus.
            $this->assertSame('drive', $call['query']['corpora']);
            $this->assertSame(self::DRIVE_ID, $call['query']['driveId']);
        }
    }

    public function testLeDriveDuDossierApprisParResolveFolderNeCoutePasDeSecondAppel(): void
    {
        // Un cycle appelle resolveFolder PUIS listFolderRecursive sur la même
        // instance : le Drive du dossier est déjà connu.
        $p = $this->provider([
            '/files/1ContratsFolderXYZ' => [[200, [
                'id' => '1ContratsFolderXYZ', 'name' => 'Contrats', 'mimeType' => self::FOLDER, 'driveId' => self::DRIVE_ID,
            ]]],
            '/files' => [[200, ['files' => []]]],
        ]);

        $p->resolveFolder('1ContratsFolderXYZ');
        $p->listFolderRecursive('1ContratsFolderXYZ');

        $this->assertCount(1, $this->callsTo($p, '/files/1ContratsFolderXYZ'));
        $this->assertSame(self::DRIVE_ID, $this->callsTo($p, '/files')[0]['query']['driveId']);
    }

    public function testUnDossierDeMonDriveSeListeCommeAvant(): void
    {
        $p = $this->provider([
            '/files/1MonDossierPerso' => [[200, ['id' => '1MonDossierPerso', 'name' => 'Perso', 'mimeType' => self::FOLDER]]],
            '/files' => [[200, ['files' => [['id' => 'f1', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '5']]]]],
        ]);

        $p->resolveFolder('1MonDossierPerso');
        $this->assertSame(['f1'], array_column($p->listFolderRecursive('1MonDossierPerso'), 'id'));

        $list = $this->callsTo($p, '/files')[0];
        $this->assertArrayNotHasKey('corpora', $list['query'], 'Hors Drive partagé, rien ne change.');
    }

    public function testUnCompteNonMembreRetombeSurLeCorpusParDefaut(): void
    {
        // Dossier d'un Drive partagé confié à un compte qui n'est pas membre
        // du Drive : Google refuse le corpus du Drive (403 documenté). Le
        // listage d'avant doit prendre le relais, pour tout le parcours.
        $refus = '{"error":{"code":403,"errors":[{"domain":"global","reason":"teamDriveMembershipRequired",'
            . '"message":"The attempted action requires shared drive membership."}]}}';
        $p = $this->provider([
            '/files/1DossierConfie' => [[200, [
                'id' => '1DossierConfie', 'name' => 'Dossier confié', 'mimeType' => self::FOLDER, 'driveId' => self::DRIVE_ID,
            ]]],
            '/files' => [
                [403, $refus],
                [200, ['files' => [
                    ['id' => 'sub', 'name' => 'Annexes', 'mimeType' => self::FOLDER],
                    ['id' => 'f1', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '5'],
                ]]],
                [200, ['files' => [['id' => 'f2', 'name' => 'b.pdf', 'mimeType' => 'application/pdf', 'size' => '5']]]],
            ],
        ]);

        $p->resolveFolder('1DossierConfie');
        $this->assertSame(['f1', 'f2'], array_column($p->listFolderRecursive('1DossierConfie'), 'id'));

        $lists = $this->callsTo($p, '/files');
        $this->assertCount(3, $lists, 'Un seul essai du corpus du Drive, pas un par dossier.');
        $this->assertSame('drive', $lists[0]['query']['corpora']);
        $this->assertArrayNotHasKey('corpora', $lists[1]['query']);
        $this->assertArrayNotHasKey('corpora', $lists[2]['query']);
    }

    public function testUnQuotaNeProvoqueJamaisDeRepli(): void
    {
        $quota = '{"error":{"code":403,"message":"User Rate Limit Exceeded","errors":[{"domain":"usageLimits",'
            . '"reason":"userRateLimitExceeded","message":"User Rate Limit Exceeded"}]}}';
        $p = $this->provider([
            '/files/' . self::DRIVE_ID => [[200, ['id' => self::DRIVE_ID, 'driveId' => self::DRIVE_ID]]],
            '/files' => [[403, $quota]],
        ]);

        try {
            $p->listFolderRecursive(self::DRIVE_ID);
            $this->fail('Un quota atteint doit interrompre le cycle.');
        } catch (RuntimeException) {
            // attendu
        }
        $this->assertCount(1, $this->callsTo($p, '/files'), 'Se replier doublerait les appels refusés.');
    }

    public function testLeRefusDuCorpusEstDistingueDesAutresErreurs(): void
    {
        $this->assertTrue(GoogleDriveProvider::isDriveCorpusRefusal(404, '{"error":{"code":404}}'));
        $this->assertTrue(GoogleDriveProvider::isDriveCorpusRefusal(
            403,
            '{"error":{"code":403,"errors":[{"reason":"teamDriveMembershipRequired"}]}}'
        ));
        // Quota et limites de débit : Google les range en 403.
        foreach (['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'RATE_LIMIT_EXCEEDED'] as $reason) {
            $this->assertFalse(GoogleDriveProvider::isDriveCorpusRefusal(
                403,
                '{"error":{"code":403,"errors":[{"domain":"usageLimits","reason":"' . $reason . '"}]}}'
            ), $reason);
        }
        // Scope manquant : le message doit nommer la reconnexion, pas se replier.
        $this->assertFalse(GoogleDriveProvider::isDriveCorpusRefusal(
            403,
            '{"error":{"code":403,"details":[{"reason":"ACCESS_TOKEN_SCOPE_INSUFFICIENT"}]}}'
        ));
        foreach ([400, 401, 429, 500] as $status) {
            $this->assertFalse(GoogleDriveProvider::isDriveCorpusRefusal($status, '{}'), (string)$status);
        }
    }

    public function testEnModeCleApiLeListageNeChangePas(): void
    {
        // Sans identité, aucun corpus de Drive (réservé aux membres) : le
        // listage reste celui de l'exemple officiel d'un dossier public.
        $p = $this->provider(['/files' => [[200, ['files' => []]]]], false);
        $p->listFolderRecursive(self::DRIVE_ID);

        $this->assertSame([], $this->callsTo($p, '/files/' . self::DRIVE_ID), 'Aucun files.get superflu.');
        $list = $this->callsTo($p, '/files')[0];
        $this->assertArrayNotHasKey('corpora', $list['query']);
        $this->assertSame('true', $list['query']['includeItemsFromAllDrives']);
    }

    public function testUnListageIncompletNEstJamaisMirrore(): void
    {
        $p = $this->provider([
            '/files/' . self::DRIVE_ID => [[200, ['id' => self::DRIVE_ID, 'driveId' => self::DRIVE_ID]]],
            '/files' => [[200, [
                'incompleteSearch' => true,
                'files' => [['id' => 'f1', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '10']],
            ]]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incomplet');
        $p->listFolderRecursive(self::DRIVE_ID);
    }

    public function testLeTelechargementDUnFichierDeDrivePartageDeclareLeSupport(): void
    {
        $p = $this->provider([
            '/files/f1'        => [[200, '%PDF-contenu']],
            '/files/g1/export' => [[200, 'docx-binaire']],
        ]);
        $dest = tempnam(sys_get_temp_dir(), 'kbdrive');

        try {
            $p->fetchTo(['id' => 'f1', 'export' => null], $dest);
            $this->assertSame('%PDF-contenu', file_get_contents($dest));
            $media = $this->callsTo($p, '/files/f1')[0];
            $this->assertSame('media', $media['query']['alt']);
            $this->assertSame('true', $media['query']['supportsAllDrives'], 'Sans lui, Google répond 404.');

            $p->fetchTo(['id' => 'g1', 'export' => GoogleDriveProvider::EXPORT_MAP['application/vnd.google-apps.document']], $dest);
            $this->assertSame('docx-binaire', file_get_contents($dest));
        } finally {
            @unlink($dest);
        }
    }

    // ===================== Messages d'erreur =====================

    public function testUneAutorisationManquanteEstNommeeCommeTelle(): void
    {
        // drives.list exige la lecture du Drive : une connexion qui ne l'a pas
        // reçue répond 403 « scope insuffisant ». Le remède est de RECONNECTER,
        // pas de demander des droits sur un dossier.
        $scope = '{"error":{"code":403,"status":"PERMISSION_DENIED","errors":[{"reason":"insufficientPermissions"}],'
            . '"details":[{"reason":"ACCESS_TOKEN_SCOPE_INSUFFICIENT"}]}}';
        $p = $this->provider(['/drives' => [[403, $scope]]]);
        try {
            $p->browse(GoogleDriveProvider::REF_SHARED_DRIVES);
            $this->fail('Un 403 doit lever.');
        } catch (RuntimeException $e) {
            $this->assertSame(UserDriveService::MSG_READ_NOT_GRANTED, $e->getMessage());
        }

        // Un 403 d'une autre nature garde son message.
        $p = $this->provider(['/drives' => [[403, '{"error":{"code":403,"status":"PERMISSION_DENIED"}}']]]);
        try {
            $p->browse(GoogleDriveProvider::REF_SHARED_DRIVES);
            $this->fail('Un 403 doit lever.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Accès refusé par Google', $e->getMessage());
        }
    }

    // ===================== Câblage =====================

    public function testLExplorateurTransmetLExplicationDUnNiveauVide(): void
    {
        $ctrl = file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("'empty'      => isset(\$level['empty'])", $ctrl);

        $js = file_get_contents(ROOT . '/public/assets/js/kb-form.js');
        $this->assertStringContainsString("browseMessage(data.empty || t('Ce dossier est vide.'))", $js);
        // L'écran annonce la possibilité là où l'on choisit l'accès.
        $tpl = file_get_contents(ROOT . '/templates/admin/knowledge-bases/edit.php');
        $this->assertStringContainsString('Drive partagé dont vous êtes membre', $tpl);
    }

    public function testLaDocumentationEtLesNouveautesAnnoncentLesDrivePartages(): void
    {
        $page = file_get_contents(ROOT . '/docs/user/fonctionnalites/bases-de-connaissances/synchroniser-une-base-avec-un-dossier.md');
        $this->assertStringContainsString('**Drive partagés**', $page);
        $this->assertStringContainsString('kb-drive-partages', file_get_contents(ROOT . '/docs/NOUVEAUTES.md'));
    }

    // ===================== Outillage =====================

    /**
     * Fournisseur dont le transport HTTP est un script de réponses par chemin
     * d'API (`/files`, `/files/{id}`, `/drives`…). Une file de plusieurs
     * réponses est consommée dans l'ordre ; la dernière reste servie. Un
     * chemin non scripté répond 404.
     *
     * @param array<string, list<array{0:int,1:array<mixed>|string}>> $routes
     */
    private function provider(array $routes, bool $oauth = true): GoogleDriveProvider
    {
        $p = new class ('') extends GoogleDriveProvider {
            /** @var array<string, list<array{0:int,1:array<mixed>|string}>> */
            public array $routes = [];
            /** @var list<array{path:string,query:array<string,mixed>}> */
            public array $calls = [];

            protected function httpGet(string $url, int $timeout): array
            {
                $parts = parse_url($url);
                parse_str((string)($parts['query'] ?? ''), $query);
                $path = rawurldecode(substr((string)($parts['path'] ?? ''), strlen('/drive/v3')));
                $this->calls[] = ['path' => $path, 'query' => $query];

                $queue = $this->routes[$path] ?? [];
                if ($queue === []) {
                    return [404, '{"error":{"code":404,"message":"non scripté"}}'];
                }
                $reply = count($queue) > 1 ? array_shift($this->routes[$path]) : $queue[0];
                return [$reply[0], is_string($reply[1]) ? $reply[1] : (string)json_encode($reply[1])];
            }
        };
        $p->routes = $routes;
        if ($oauth) {
            (new ReflectionProperty(GoogleDriveProvider::class, 'bearerSupplier'))
                ->setValue($p, static fn(): string => 'jeton-de-test');
        }
        return $p;
    }

    /** @return list<array{path:string,query:array<string,mixed>}> */
    private function callsTo(GoogleDriveProvider $p, string $path): array
    {
        return array_values(array_filter($p->calls, static fn(array $c): bool => $c['path'] === $path));
    }
}
