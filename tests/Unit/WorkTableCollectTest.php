<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Collectes EXHAUSTIVES des services (tables de travail) : le vrai code de
 * Gmail, Outlook et Drive, rejoué sans réseau — une sous-classe remplace le
 * seul point de sortie HTTP (`apiRaw`).
 *
 * Ce que ces tests gardent : les pages sont suivies jusqu'au bout, la limite
 * est dite (jamais une liste coupée présentée comme complète), l'ordre de la
 * source est conservé, et un lien de page suivante qui ne vise pas le
 * fournisseur n'est jamais suivi (le jeton partirait avec la requête).
 */
final class WorkTableCollectTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Gmail
    // ---------------------------------------------------------------------

    /** Gmail factice : deux pages d'identifiants, en-têtes par message. */
    private static function gmail(array $failOnce = []): UserGmailService
    {
        return new class($failOnce) extends UserGmailService {
            public array $calls = [];
            public function __construct(private array $failOnce)
            {
                parent::__construct(7, 'refresh', 'access', '2099-01-01 00:00:00', '', 'moi@ex.fr');
            }
            protected function apiRaw(string $method, string $url, ?array $body = null, int $timeout = 60): string
            {
                $this->calls[] = $url;
                parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
                if (preg_match('#/messages/([^/?]+)\?#', $url, $m) === 1) {
                    $id = $m[1];
                    if (in_array($id, $this->failOnce, true)) {
                        $this->failOnce = array_values(array_diff($this->failOnce, [$id]));
                        throw new \RuntimeException('Limite de débit Gmail.');
                    }
                    $n = (int)substr($id, 1);
                    return (string)json_encode([
                        'id' => $id, 'snippet' => 'Extrait &amp; suite ' . $n, 'internalDate' => (string)((1767225600 - $n * 60) * 1000),
                        'payload' => ['headers' => [
                            ['name' => 'From', 'value' => 'Client ' . $n . ' <c' . $n . '@ex.fr>'], ['name' => 'To', 'value' => 'moi@ex.fr'],
                            ['name' => 'Subject', 'value' => 'Objet ' . $n],
                        ]],
                    ]);
                }
                $page = (string)($q['pageToken'] ?? '');
                return (string)json_encode($page === ''
                    ? ['messages' => [['id' => 'm1'], ['id' => 'm2'], ['id' => 'm3']], 'nextPageToken' => 'p2', 'resultSizeEstimate' => 5]
                    : ['messages' => [['id' => 'm4'], ['id' => 'm5']]]);
            }
        };
    }

    public function testGmailSuitLesPagesEtGardeLOrdre(): void
    {
        $gmail = self::gmail();
        $seen = [];
        $res = $gmail->collectMessages('from:client', 50, function (int $done, ?int $total) use (&$seen): bool {
            $seen[] = [$done, $total];
            return true;
        });
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], array_column($res['messages'], 'id'));
        $this->assertFalse($res['tronque']);
        $this->assertSame(0, $res['echecs']);
        $this->assertSame(5, $res['estimation']);
        $this->assertSame('Client 2 <c2@ex.fr>', $res['messages'][1]['de']);
        $this->assertSame('Extrait & suite 2', $res['messages'][1]['extrait']);
        $this->assertSame(1767225600 - 120, $res['messages'][1]['recu']);
        $this->assertSame([5, 5], end($seen), 'L’avancement est annoncé, avec l’estimation de Gmail.');
        $this->assertStringContainsString('q=from%3Aclient', $gmail->calls[0]);
        $this->assertStringContainsString('pageToken=p2', implode(' ', $gmail->calls));
    }

    public function testGmailDitQuandLaLimiteCoupeLaListe(): void
    {
        $res = self::gmail()->collectMessages('', 4);
        $this->assertSame(['m1', 'm2', 'm3', 'm4'], array_column($res['messages'], 'id'));
        $this->assertTrue($res['tronque'], 'Un cinquième email existe : la table sera dite incomplète.');

        $exact = self::gmail()->collectMessages('', 5);
        $this->assertFalse($exact['tronque']);
    }

    public function testUnEmailRefuseUneFoisEstRelu(): void
    {
        $res = self::gmail(['m2', 'm4'])->collectMessages('', 50);
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], array_column($res['messages'], 'id'), 'Relu un par un, remis à sa place.');
        $this->assertSame(0, $res['echecs']);
    }

    public function testLArretDemandeRendCeQuiEstLu(): void
    {
        $res = self::gmail()->collectMessages('', 50, static fn(): bool => false);
        $this->assertTrue($res['interrompu']);
        $this->assertNotSame([], $res['messages'], 'Le lot déjà lu est rendu : rien de ce qui a été payé n’est jeté.');
    }

    // ---------------------------------------------------------------------
    // Outlook
    // ---------------------------------------------------------------------

    public function testOutlookSuitLesPagesDeGraphEtDeGraphSeulement(): void
    {
        $outlook = new class extends UserOutlookService {
            public array $calls = [];
            public function __construct()
            {
                parent::__construct(7, 'refresh', 'access', '2099-01-01 00:00:00', 'Mail.Read', 'moi@ex.fr');
            }
            protected function apiRaw(string $method, string $url, ?array $body = null, int $timeout = 60): string
            {
                $this->calls[] = $url;
                $msg = static fn(int $n): array => [
                    'id' => 'o' . $n, 'subject' => 'Sujet ' . $n, 'receivedDateTime' => '2026-11-0' . $n . 'T08:00:00Z', 'bodyPreview' => ' Aperçu ' . $n . ' ',
                    'from' => ['emailAddress' => ['name' => 'Marie', 'address' => 'marie@acme.fr']],
                    'toRecipients' => [['emailAddress' => ['name' => 'Moi', 'address' => 'moi@ex.fr']]],
                    'isRead' => true, 'hasAttachments' => $n === 2,
                ];
                if (str_contains($url, 'skiptoken')) {
                    return (string)json_encode(['value' => [$msg(3)], '@odata.nextLink' => 'https://evil.example/v1.0/me/messages?$skiptoken=Z']);
                }
                return (string)json_encode(['value' => [$msg(1), $msg(2)], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=Y']);
            }
        };
        $res = $outlook->collectMessages('facture "Acme"', 500);
        $this->assertSame(['o1', 'o2', 'o3'], array_column($res['messages'], 'id'));
        $this->assertCount(2, $outlook->calls, 'Le lien qui ne vise pas Graph n’est jamais suivi.');
        $this->assertStringContainsString('%24search', str_replace('$', '%24', $outlook->calls[0]));
        $this->assertStringNotContainsString('orderby', $outlook->calls[0], 'Graph refuse $search avec $orderby.');
        $this->assertSame('Marie <marie@acme.fr>', $res['messages'][0]['de']);
        $this->assertSame('Aperçu 1', $res['messages'][0]['extrait']);
        $this->assertTrue($res['messages'][1]['pieces']);
        $this->assertSame(strtotime('2026-11-01T08:00:00Z'), $res['messages'][0]['recu']);

        // Requête vide : la boîte de réception, du plus récent au plus ancien.
        $outlook->calls = [];
        $outlook->collectMessages('', 1);
        $this->assertStringContainsString('/mailFolders/inbox/messages', $outlook->calls[0]);
        $this->assertStringContainsString('orderby', $outlook->calls[0]);
    }

    public function testOutlookDitQuandLaLimiteCoupeLaListe(): void
    {
        $outlook = new class extends UserOutlookService {
            public function __construct()
            {
                parent::__construct(7, 'refresh', 'access', '2099-01-01 00:00:00', 'Mail.Read', 'moi@ex.fr');
            }
            protected function apiRaw(string $method, string $url, ?array $body = null, int $timeout = 60): string
            {
                $value = [];
                for ($i = 1; $i <= 3; $i++) {
                    $value[] = ['id' => 'o' . $i, 'subject' => 'S', 'receivedDateTime' => '2026-11-01T08:00:00Z'];
                }
                return (string)json_encode(['value' => $value]);
            }
        };
        $res = $outlook->collectMessages('', 2);
        $this->assertCount(2, $res['messages']);
        $this->assertTrue($res['tronque']);
    }

    // ---------------------------------------------------------------------
    // Drive
    // ---------------------------------------------------------------------

    private static function drive(): UserDriveService
    {
        return new class extends UserDriveService {
            public array $queries = [];
            public function __construct()
            {
                parent::__construct(7, 'refresh', 'access', '2099-01-01 00:00:00', 'https://www.googleapis.com/auth/drive.readonly');
            }
            protected function apiRaw(string $method, string $url, ?array $body = null, int $timeout = 60): string
            {
                $folder = 'application/vnd.google-apps.folder';
                parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
                if (!isset($q['q'])) {
                    // files.get du dossier de départ.
                    return (string)json_encode(['id' => 'ROOTFOLDER123456', 'name' => 'Clients', 'mimeType' => $folder, 'parents' => ['x']]);
                }
                $this->queries[] = (string)$q['q'] . (isset($q['pageToken']) ? ' #' . $q['pageToken'] : '');
                $file = static fn(string $id, string $name, string $parent, string $mime = 'application/pdf'): array
                    => ['id' => $id, 'name' => $name, 'mimeType' => $mime, 'modifiedTime' => '2026-11-01T10:00:00Z', 'size' => '2048', 'parents' => [$parent]];
                if (str_contains((string)$q['q'], "'ROOTFOLDER123456' in parents")) {
                    return (string)json_encode(isset($q['pageToken'])
                        ? ['files' => [$file('c', 'Zeta.pdf', 'ROOTFOLDER123456')]]
                        : ['files' => [$file('sub1', 'Contrats', 'ROOTFOLDER123456', $folder), $file('a', 'Alpha.pdf', 'ROOTFOLDER123456'), $file('b', 'beta.docx', 'ROOTFOLDER123456')], 'nextPageToken' => 'p2']);
                }
                if (str_contains((string)$q['q'], "'sub1' in parents")) {
                    return (string)json_encode(['files' => [$file('deep', '2026', 'sub1', $folder), $file('d', 'Delta.pdf', 'sub1')]]);
                }
                return (string)json_encode(['files' => [$file('e', 'Echo.pdf', 'deep')]]);
            }
        };
    }

    public function testDriveParcourtLeDossierEtSesSousDossiersNiveauParNiveau(): void
    {
        $drive = self::drive();
        $res = $drive->collectFiles('https://drive.google.com/drive/folders/ROOTFOLDER123456', true, 500);

        $this->assertSame('Clients', $res['dossier']['nom']);
        $this->assertSame(['Alpha.pdf', 'beta.docx', 'Zeta.pdf', 'Delta.pdf', 'Echo.pdf'], array_column($res['fichiers'], 'nom'), 'Pages suivies, dossiers écartés, rangés par chemin puis par nom.');
        $this->assertSame(['', '', '', 'Contrats', 'Contrats/2026'], array_column($res['fichiers'], 'chemin'));
        $this->assertSame(3, $res['dossiers']);
        $this->assertFalse($res['tronque']);
        $this->assertSame(2048, $res['fichiers'][0]['taille']);
        $this->assertStringContainsString('/file/d/a/view', $res['fichiers'][0]['lien']);
        $this->assertCount(4, $drive->queries, 'Un appel par niveau (plus la page suivante), pas un par dossier.');
    }

    public function testDriveSansSousDossiersEtAvecLimite(): void
    {
        $flat = self::drive()->collectFiles('https://drive.google.com/drive/folders/ROOTFOLDER123456', false, 500);
        $this->assertSame(['Alpha.pdf', 'beta.docx', 'Zeta.pdf'], array_column($flat['fichiers'], 'nom'));
        $this->assertSame(1, $flat['dossiers']);

        $cut = self::drive()->collectFiles('https://drive.google.com/drive/folders/ROOTFOLDER123456', true, 2);
        $this->assertCount(2, $cut['fichiers']);
        $this->assertTrue($cut['tronque']);
    }

    // ---------------------------------------------------------------------
    // Mise en lignes
    // ---------------------------------------------------------------------

    public function testUnEmailEtUnFichierDeviennentDesLignes(): void
    {
        $row = WorkTableCollector::emailRow(
            ['id' => 'm1', 'de' => '"Marie Durand" <Marie@ACME.fr>', 'a' => 'A <a@x.fr>, b@y.fr', 'objet' => 'Devis', 'recu' => 1767225600, 'extrait' => 'Bonjour'],
            'Gmail',
            new \DateTimeZone('UTC')
        );
        $this->assertSame([
            'date' => '2026-01-01 00:00', 'expediteur' => 'Marie Durand', 'email' => 'marie@acme.fr', 'destinataires' => 'a@x.fr, b@y.fr',
            'objet' => 'Devis', 'extrait' => 'Bonjour', 'source' => 'Gmail', 'id' => 'm1',
        ], $row);
        $this->assertSame(WorkTableOps::keys(WorkTableCollector::emailColumns()), array_keys($row), 'Les mêmes colonnes pour Gmail et Outlook : deux boîtes dans une table.');
        $this->assertSame('seul@ex.fr', WorkTableCollector::emailRow(['de' => 'seul@ex.fr'], 'Outlook')['expediteur']);

        $file = WorkTableCollector::fileRow(['id' => 'f', 'nom' => 'A.pdf', 'type' => 'PDF', 'modifie' => '2026-11-01T10:00:00Z', 'taille' => 500, 'chemin' => 'Sous/Dossier', 'lien' => 'L'], 'Racine', new \DateTimeZone('UTC'));
        $this->assertSame(WorkTableOps::keys(WorkTableCollector::fileColumns()), array_keys($file));
        $this->assertSame('Racine/Sous/Dossier', $file['dossier']);
        $this->assertSame('1', $file['taille_ko']);
        $this->assertSame('2026-11-01 10:00', $file['modifie_le']);
    }

    public function testUnClasseurJointSeLitDansSonFichierConfine(): void
    {
        $root = sys_get_temp_dir() . '/mia_wt_' . bin2hex(random_bytes(4));
        mkdir($root);
        $path = $root . '/clients.csv';
        file_put_contents($path, "Nom;Ville\nAcme;Lyon\nBolt;Paris\n");
        $outside = tempnam(sys_get_temp_dir(), 'mia_out_') . '.csv';
        file_put_contents($outside, "Secret;X\n1;2\n");
        try {
            $attachments = [
                ['numero' => 1, 'nom' => 'clients.csv', 'type' => 'text/csv', 'path' => $path, 'extracted' => "Nom;Ville\nPérimé;Ancien\n"],
                ['numero' => 2, 'nom' => 'autre.csv', 'type' => 'text/csv', 'path' => $outside, 'extracted' => "Nom;Ville\nTexte;Gardé\n"],
            ];
            $in = WorkTableCollector::attachmentSheet($attachments, '1', '', $root);
            $this->assertSame(['Acme', 'Bolt'], array_column($in['rows'], 'nom'), 'Le fichier d’origine fait foi quand il est encore là.');
            // Un chemin hors du dossier des envois n'est jamais lu : le texte gardé par le message sert.
            $out = WorkTableCollector::attachmentSheet($attachments, 'autre', '', $root);
            $this->assertSame(['Texte'], array_column($out['rows'], 'nom'));
        } finally {
            @unlink($path);
            @unlink($outside);
            @unlink(substr($outside, 0, -4));
            @rmdir($root);
        }
    }

    public function testUnGrosClasseurJointEstReconnuSansLireLeFichier(): void
    {
        $lignes = static fn(int $n): string => "Nom;Ville\n" . implode("\n", array_map(static fn(int $i): string => 'Client ' . $i . ';Lyon', range(1, $n)));
        $history = [
            ['id' => 11, 'role' => 'user', 'files_json' => json_encode([['name' => 'petit.csv', 'type' => 'text/csv', 'extracted' => $lignes(20)]])],
            ['id' => 12, 'role' => 'user', 'files_json' => json_encode([['name' => 'grand.csv', 'type' => 'text/csv', 'extracted' => $lignes(150)]])],
            ['id' => 13, 'role' => 'user', 'files_json' => json_encode([['name' => 'Coupé.csv', 'type' => 'text/csv', 'extracted' => $lignes(30)]])],
        ];
        $found = WorkTableCollector::sheetAttachments($history);
        $this->assertSame([11, 12, 13], array_column($found, 'message_id'), 'Chaque classeur garde le message qui l’a apporté.');

        $sized = WorkTableCollector::sized($found, ['coupé.csv']);
        $this->assertSame([21, 151, 31], array_column($sized, 'lignes'));
        $this->assertSame([false, true, true], array_column($sized, 'gros'), 'Plus de 100 lignes, ou coupé faute de place.');
        $this->assertSame(['grand.csv', 'Coupé.csv'], array_column(WorkTableCollector::bigSheets($sized), 'nom'));

        // Juste à la limite : 100 lignes ne suffisent pas, 101 oui.
        $limite = WorkTableCollector::sized([
            ['nom' => 'a.csv', 'extracted' => implode("\n", array_fill(0, WorkTableCollector::BIG_SHEET_LINES, 'x;y'))],
            ['nom' => 'b.csv', 'extracted' => implode("\n", array_fill(0, WorkTableCollector::BIG_SHEET_LINES + 1, 'x;y'))],
            ['nom' => 'c.xlsx', 'extracted' => ''],
        ]);
        $this->assertSame([false, true, true], array_column($limite, 'gros'), 'Sans texte gardé, seul le fichier fait foi : gros par prudence.');

        // Une liste sans taille (anciens appels) ne déclare aucun gros classeur.
        $this->assertSame([], WorkTableCollector::bigSheets($found));
    }
}
