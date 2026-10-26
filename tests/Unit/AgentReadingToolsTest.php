<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/AgentSource.php';
require_once ROOT . '/src/Controllers/ChatController.php';

/**
 * Lecture paginée, pièces jointes des emails et lecture de pages web.
 *
 * Ce que ces tests protègent :
 *   - qu'un long document se lise EN ENTIER, partie par partie, sans perte ni
 *     coupe au milieu d'un caractère (TextPager) ;
 *   - qu'une pièce jointe se désigne par son numéro ou son nom, jamais par un
 *     identifiant que Gmail change à chaque lecture (MailAttachments) ;
 *   - que `lire_page_web` ne puisse JAMAIS atteindre le réseau interne : chaque
 *     verrou (schéma, port, nom, adresse résolue, redirection) est éprouvé sur
 *     le vrai code, avec un transport factice (WebPageReader).
 */
final class AgentReadingToolsTest extends TestCase
{
    // ===================== TextPager =====================

    public function testUnTexteCourtTientEnUnePartieSansSuite(): void
    {
        $p = TextPager::page("Bonjour.\n\nUn court texte.", 4000);
        $this->assertSame(1, $p['partie']);
        $this->assertSame(1, $p['parties']);
        $this->assertFalse($p['tronque']);
        $this->assertArrayNotHasKey('suite', $p);
        $this->assertSame("Bonjour.\n\nUn court texte.", $p['contenu']);
    }

    public function testUnLongTexteSeLitEnEntierPartieParPartie(): void
    {
        $paragraphes = [];
        for ($i = 1; $i <= 60; $i++) {
            $paragraphes[] = "Paragraphe $i : " . str_repeat("l'équipe avance bien sur le projet. ", 8);
        }
        $texte = implode("\n\n", $paragraphes);
        $first = TextPager::page($texte, 2000, 1);
        $this->assertGreaterThan(1, $first['parties']);
        $this->assertTrue($first['tronque']);
        $this->assertStringContainsString('partie: 2', $first['suite']);

        $relu = [];
        for ($n = 1; $n <= $first['parties']; $n++) {
            $part = TextPager::page($texte, 2000, $n);
            $this->assertLessThanOrEqual(2000, strlen($part['contenu']), 'Une partie ne dépasse jamais le budget.');
            $this->assertTrue(mb_check_encoding($part['contenu'], 'UTF-8'), 'Jamais de coupe au milieu d\'un caractère.');
            $this->assertSame($n < $first['parties'], $part['tronque']);
            $relu[] = $part['contenu'];
        }
        // Rien n'est perdu : chaque paragraphe se retrouve dans une partie.
        $tout = implode("\n\n", $relu);
        for ($i = 1; $i <= 60; $i++) {
            $this->assertStringContainsString("Paragraphe $i :", $tout);
        }
        // Coupe propre : une partie se termine sur une fin de paragraphe ou de phrase.
        $this->assertMatchesRegularExpression('/[.!?]$/u', $first['contenu']);
    }

    public function testLeDecoupageEstDeterministe(): void
    {
        $texte = str_repeat("Une phrase assez longue pour remplir. ", 400);
        $this->assertSame(TextPager::bounds($texte, 1500), TextPager::bounds($texte, 1500));
        $this->assertSame(TextPager::page($texte, 1500, 3), TextPager::page($texte, 1500, 3));
    }

    public function testUneCoupeDureNeCassePasUnCaractereMultioctet(): void
    {
        // Aucun blanc ni ponctuation : seule une coupe dure est possible.
        $texte = str_repeat('é', 3000);
        foreach (TextPager::bounds($texte, 1001) as [$s, $e]) {
            $this->assertTrue(mb_check_encoding(substr($texte, $s, $e - $s), 'UTF-8'));
        }
    }

    public function testUnePartieInexistanteEstUneErreurClaire(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ne compte que 1 partie');
        TextPager::page('court', 4000, 3);
    }

    // ===================== MailAttachments =====================

    private function gmailPayload(): array
    {
        return [
            'mimeType' => 'multipart/mixed',
            'parts'    => [
                ['mimeType' => 'multipart/alternative', 'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => 'SGVsbG8']],
                    ['mimeType' => 'text/html', 'body' => ['data' => 'PGI+SGVsbG88L2I+']],
                ]],
                ['mimeType' => 'application/pdf', 'filename' => 'Facture-2026-09.pdf',
                    'body' => ['attachmentId' => 'ANGjdJ_long_id_1', 'size' => 182340]],
                ['mimeType' => 'image/png', 'filename' => 'logo.png',
                    'headers' => [['name' => 'Content-Disposition', 'value' => 'inline; filename="logo.png"'],
                                  ['name' => 'Content-ID', 'value' => '<logo@x>']],
                    'body' => ['attachmentId' => 'ANGjdJ_long_id_2', 'size' => 2048]],
                ['mimeType' => 'text/csv', 'filename' => 'export.csv',
                    'body' => ['data' => rtrim(strtr(base64_encode("a;b\n1;2"), '+/', '-_'), '='), 'size' => 7]],
            ],
        ];
    }

    public function testLesPiecesJointesGmailSontInventorieesEtNumerotees(): void
    {
        $list = MailAttachments::fromGmailPayload($this->gmailPayload());
        $this->assertSame(['Facture-2026-09.pdf', 'logo.png', 'export.csv'], array_column($list, 'nom'));
        $this->assertSame([1, 2, 3], array_column($list, 'numero'));
        $this->assertTrue($list[1]['en_ligne'], 'Une image collée dans le corps est signalée.');
        $this->assertSame('', $list[0]['data']);
        $this->assertSame('ANGjdJ_long_id_1', $list[0]['ref']);
        $this->assertNotSame('', $list[2]['data'], 'Une petite pièce jointe porte ses octets dans le message.');
    }

    public function testLeModeleNeVoitNiIdentifiantNiOctets(): void
    {
        $forModel = MailAttachments::forModel(MailAttachments::fromGmailPayload($this->gmailPayload()));
        foreach ($forModel as $row) {
            $this->assertArrayNotHasKey('ref', $row);
            $this->assertArrayNotHasKey('data', $row);
        }
        $this->assertSame('PDF', $forModel[0]['type']);
        $this->assertSame('178 Ko', $forModel[0]['taille']);
        $this->assertTrue($forModel[0]['lisible']);
        $this->assertFalse($forModel[1]['lisible'], 'Une image ne se lit pas comme du texte.');
        $this->assertTrue($forModel[1]['en_ligne']);
    }

    public function testLesPiecesJointesOutlookDistinguentFichierEmailEtLien(): void
    {
        $list = MailAttachments::fromGraphList([
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'AAMk1', 'name' => 'Contrat.docx',
                'contentType' => 'application/octet-stream', 'size' => 50000],
            ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'AAMk2', 'name' => 'Re: devis', 'size' => 9000],
            ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'AAMk3', 'name' => 'Budget.xlsx', 'size' => 0],
        ]);
        $this->assertSame(['fichier', 'email', 'lien'], array_column($list, 'piece'));
        $this->assertNull(MailAttachments::unreadableReason($list[0]), 'Un .docx annoncé en octet-stream se lit par son extension.');
        $this->assertStringContainsString('email joint', (string)MailAttachments::unreadableReason($list[1]));
        $this->assertStringContainsString('lien de partage', (string)MailAttachments::unreadableReason($list[2]));
    }

    public function testUnePieceJointeSeDesigneParNumeroOuParNom(): void
    {
        $list = MailAttachments::fromGmailPayload($this->gmailPayload());
        $this->assertSame('Facture-2026-09.pdf', MailAttachments::pick($list, '1')['nom']);
        $this->assertSame('export.csv', MailAttachments::pick($list, 'n°3')['nom']);
        $this->assertSame('Facture-2026-09.pdf', MailAttachments::pick($list, 'facture-2026-09.PDF')['nom']);
        $this->assertSame('Facture-2026-09.pdf', MailAttachments::pick($list, 'facture')['nom'], 'Une partie unique du nom suffit.');
    }

    public function testUneDesignationAmbigueOuAbsenteNommeLesCandidats(): void
    {
        $list = MailAttachments::fromGraphList([
            ['id' => 'AAMk1', 'name' => 'Devis-v1.pdf', 'contentType' => 'application/pdf', 'size' => 10],
            ['id' => 'AAMk2', 'name' => 'Devis-v2.pdf', 'contentType' => 'application/pdf', 'size' => 10],
        ]);
        try {
            MailAttachments::pick($list, 'devis');
            $this->fail('Deux candidats : la lecture ne doit pas choisir au hasard.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Devis-v1.pdf', $e->getMessage());
            $this->assertStringContainsString('Devis-v2.pdf', $e->getMessage());
        }
        $this->expectExceptionMessage('Devis-v2.pdf');
        MailAttachments::pick($list, 'contrat');
    }

    public function testLesRaisonsDeNonLectureSontExplicites(): void
    {
        $this->assertStringContainsString('image', (string)MailAttachments::unreadableReason(
            ['nom' => 'photo.jpg', 'type' => 'image/jpeg', 'taille' => 10, 'piece' => 'fichier']));
        $this->assertStringContainsString('trop volumineuse', (string)MailAttachments::unreadableReason(
            ['nom' => 'gros.pdf', 'type' => 'application/pdf', 'taille' => MailAttachments::MAX_BYTES + 1, 'piece' => 'fichier']));
        $this->assertStringContainsString('pas un format lisible', (string)MailAttachments::unreadableReason(
            ['nom' => 'vieux.doc', 'type' => 'application/msword', 'taille' => 10, 'piece' => 'fichier']));
        $this->assertStringContainsString('Formats lus', (string)MailAttachments::unreadableReason(
            ['nom' => 'archive.zip', 'type' => 'application/zip', 'taille' => 10, 'piece' => 'fichier']));
    }

    public function testUnTexteEnWindows1252RessortEnUtf8Valide(): void
    {
        $bytes = mb_convert_encoding("Réunion d'équipe ; coût 12 €", 'Windows-1252', 'UTF-8');
        $text  = MailAttachments::extractText($bytes, 'text/csv', 'notes.csv');
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('Réunion', $text);
        $this->assertNotFalse(json_encode(['contenu' => $text]), 'Sans conversion, l\'encodage JSON du résultat échouait.');
    }

    public function testUnFormatNonLisibleEstRefuseAvantToutFichierTemporaire(): void
    {
        $this->expectException(\RuntimeException::class);
        MailAttachments::extractText('PK…', 'application/zip', 'archive.zip');
    }

    public function testDecodageBase64UrlEtTailles(): void
    {
        $this->assertSame("a;b\n1;2", MailAttachments::decodeBase64Url(rtrim(strtr(base64_encode("a;b\n1;2"), '+/', '-_'), '=')));
        $this->assertSame('', MailAttachments::decodeBase64Url('***'));
        $this->assertSame('840 o', MailAttachments::humanSize(840));
        $this->assertSame('3,4 Mo', MailAttachments::humanSize((int)(3.4 * 1024 * 1024)));
        $this->assertSame('application/pdf', MailAttachments::canonicalMime('application/octet-stream', 'x.PDF'));
        $this->assertSame('text/csv', MailAttachments::canonicalMime('text/plain', 'export.csv'));
    }

    // ===================== WebPageReader : verrous =====================

    /** @return list<string> */
    public static function adressesRefusees(): array
    {
        return [
            'ftp://exemple.fr/fichier',
            'file:///etc/passwd',
            'gopher://exemple.fr/',
            'https://user:secret@exemple.fr/',
            'https://exemple.fr:8080/',
            'http://localhost/',
            'http://admin.localhost/',
            'http://intranet/',
            'http://qdrant:6333/collections',
            'http://serveur.internal/',
            'http://nas.local/',
            'http://0x7f.1/',
            'http://0177.0.0.1/',
            'http://2130706433/',
            'http://127.0.0.1/',
            'http://10.1.2.3/',
            'http://172.16.0.5/',
            'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://0.0.0.0/',
            'http://[::1]/',
            'http://[fe80::1]/',
            'http://[fd00::1]/',
            'http://[::ffff:127.0.0.1]/',
        ];
    }

    public function testToutesLesAdressesInternesSontRefusees(): void
    {
        foreach (self::adressesRefusees() as $url) {
            try {
                WebPageReader::validateUrl($url);
                $this->fail('Adresse acceptée à tort : ' . $url);
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testUneAdressePubliqueEstNormalisee(): void
    {
        $t = WebPageReader::validateUrl('https://www.Exemple.fr/article?id=4#section');
        $this->assertSame('https://www.exemple.fr/article?id=4', $t['url'], 'Le fragment ne part jamais au serveur.');
        $this->assertSame(443, $t['port']);
        $this->assertNull($t['ip']);

        $sansSchema = WebPageReader::validateUrl('exemple.fr/page');
        $this->assertSame('https://exemple.fr/page', $sansSchema['url']);
        $this->assertSame('http://exemple.fr/', WebPageReader::validateUrl('http://exemple.fr:80')['url']);
    }

    public function testLaPlageDUneAdresseIpDecide(): void
    {
        $this->assertTrue(WebPageReader::isPublicIp('8.8.8.8'));
        $this->assertTrue(WebPageReader::isPublicIp('2001:4860:4860::8888'));
        foreach (['127.0.0.1', '10.0.0.1', '172.31.255.255', '192.168.0.1', '169.254.169.254', '100.64.1.1',
                     '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1',
                     '::ffff:10.0.0.1', '64:ff9b::a00:1', '2001:db8::1', 'pas une ip'] as $ip) {
            $this->assertFalse(WebPageReader::isPublicIp($ip), 'Doit être refusée : ' . $ip);
        }
        // IPv4 embarquée PUBLIQUE : acceptée (c'est elle qui sera jointe).
        $this->assertTrue(WebPageReader::isPublicIp('::ffff:8.8.8.8'));
    }

    /**
     * @param array<string, array> $pages  url => réponse
     * @param array<string, list<string>> $dns
     */
    private function reader(array $pages, array $dns, ?array &$calls = null): WebPageReader
    {
        $calls = [];
        return new WebPageReader(
            function (array $target, string $ip) use ($pages, &$calls): array {
                $calls[] = ['url' => $target['url'], 'ip' => $ip];
                return $pages[$target['url']] ?? ['status' => 404, 'headers' => [], 'body' => ''];
            },
            static fn(string $host): array => $dns[$host] ?? []
        );
    }

    public function testUnePageHtmlEstLueParSaZonePrincipale(): void
    {
        $html = '<html><head><title>Titre de la page</title><style>.x{}</style></head><body>'
            . '<nav>Accueil | Produits | Contact</nav>'
            . '<article><h1>Le vrai titre</h1><p>' . str_repeat('Contenu utile de l\'article. ', 30) . '</p>'
            . '<ul><li>Premier point</li><li>Second point</li></ul></article>'
            . '<footer>Mentions légales</footer><script>alert(1)</script></body></html>';
        $r = $this->reader(
            ['https://exemple.fr/a' => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => $html]],
            ['exemple.fr' => ['93.184.216.34']],
            $calls
        )->read('https://exemple.fr/a', 8000);

        $this->assertSame('Titre de la page', $r['titre']);
        $this->assertSame('page web', $r['type']);
        $this->assertStringContainsString('# Le vrai titre', $r['contenu']);
        $this->assertStringContainsString('- Premier point', $r['contenu']);
        $this->assertStringNotContainsString('Mentions légales', $r['contenu']);
        $this->assertStringNotContainsString('alert(1)', $r['contenu']);
        $this->assertStringNotContainsString('Produits', $r['contenu']);
        $this->assertSame('93.184.216.34', $calls[0]['ip'], 'La connexion est épinglée sur l\'adresse vérifiée.');
    }

    public function testUnNomQuiResoutVersUneAdresseInterneEstRefuse(): void
    {
        // Même UNE seule adresse interne parmi les réponses suffit.
        $reader = $this->reader([], ['piege.exemple.fr' => ['93.184.216.34', '10.0.0.5']], $calls);
        try {
            $reader->read('https://piege.exemple.fr/', 4000);
            $this->fail('Résolution interne acceptée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('réseau interne', $e->getMessage());
        }
        $this->assertSame([], $calls, 'Aucune connexion ne part.');
    }

    public function testUneRedirectionVersLeReseauInterneEstRefusee(): void
    {
        $reader = $this->reader(
            ['https://exemple.fr/r' => ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'], 'body' => '']],
            ['exemple.fr' => ['93.184.216.34']],
            $calls
        );
        try {
            $reader->read('https://exemple.fr/r', 4000);
            $this->fail('Redirection interne suivie.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Adresse refusée', $e->getMessage());
        }
        $this->assertCount(1, $calls, 'La cible interne n\'est jamais contactée.');
    }

    public function testUneRedirectionRelativeEstSuivieEtRevalidee(): void
    {
        $r = $this->reader([
            'https://exemple.fr/ancien' => ['status' => 301, 'headers' => ['location' => '/nouveau'], 'body' => ''],
            'https://exemple.fr/nouveau' => ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => 'Texte final'],
        ], ['exemple.fr' => ['93.184.216.34']])->read('https://exemple.fr/ancien', 4000);
        $this->assertSame('https://exemple.fr/nouveau', $r['url']);
        $this->assertSame('Texte final', $r['contenu']);
    }

    public function testLesRedirectionsSontBornees(): void
    {
        $pages = [];
        for ($i = 0; $i < 10; $i++) {
            $pages['https://exemple.fr/' . $i] = ['status' => 302, 'headers' => ['location' => '/' . ($i + 1)], 'body' => ''];
        }
        $this->expectExceptionMessage('Trop de redirections');
        $this->reader($pages, ['exemple.fr' => ['93.184.216.34']])->read('https://exemple.fr/0', 4000);
    }

    public function testLesErreursHttpEtLesFormatsSontNommes(): void
    {
        $dns = ['exemple.fr' => ['93.184.216.34']];
        $cases = [
            ['status' => 404, 'headers' => [], 'body' => '', 'attendu' => 'Page introuvable'],
            ['status' => 403, 'headers' => [], 'body' => '', 'attendu' => 'pages publiques'],
            ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => 'PNG', 'attendu' => 'image/png'],
            ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body><script>x()</script></body></html>', 'attendu' => 'Aucun texte lisible'],
        ];
        foreach ($cases as $c) {
            try {
                $this->reader(['https://exemple.fr/p' => $c], $dns)->read('https://exemple.fr/p', 4000);
                $this->fail('Erreur attendue : ' . $c['attendu']);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($c['attendu'], $e->getMessage());
            }
        }
        $this->expectExceptionMessage('Site introuvable');
        $this->reader([], [])->read('https://inconnu.exemple.fr/', 4000);
    }

    public function testUnePageLongueSeLitParParties(): void
    {
        $body = implode("\n\n", array_map(static fn(int $i): string => "Bloc $i : " . str_repeat('Une phrase de la page web. ', 6), range(1, 200)));
        $reader = $this->reader(
            ['https://exemple.fr/long' => ['status' => 200, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => $body]],
            ['exemple.fr' => ['93.184.216.34']]
        );
        $p1 = $reader->read('https://exemple.fr/long', 3000, 1);
        $this->assertGreaterThan(1, $p1['parties']);
        $p2 = $reader->read('https://exemple.fr/long', 3000, 2);
        $this->assertSame(2, $p2['partie']);
        $this->assertNotSame($p1['contenu'], $p2['contenu']);
    }

    public function testUnTexteEnLatin1EstConverti(): void
    {
        $r = $this->reader(
            ['https://exemple.fr/l' => ['status' => 200, 'headers' => ['content-type' => 'text/plain; charset=ISO-8859-1'],
                'body' => mb_convert_encoding('Café crème', 'ISO-8859-1', 'UTF-8')]],
            ['exemple.fr' => ['93.184.216.34']]
        )->read('https://exemple.fr/l', 4000);
        $this->assertSame('Café crème', $r['contenu']);
    }

    public function testLaPremiereAdresseDUnMessageEstExtraite(): void
    {
        $this->assertSame('https://exemple.fr/a?b=1', WebPageReader::firstUrl('Résume https://exemple.fr/a?b=1, merci.'));
        $this->assertSame('https://fr.wikipedia.org/wiki/Paris_(France)',
            WebPageReader::firstUrl('Voir (https://fr.wikipedia.org/wiki/Paris_(France)).'));
        $this->assertNull(WebPageReader::firstUrl('Pas de lien ici, juste exemple.fr.'));
        $this->assertSame('https://a.fr/x', WebPageReader::resolveRelative('https://a.fr/dossier/page', '/x'));
        $this->assertSame('https://a.fr/dossier/y', WebPageReader::resolveRelative('https://a.fr/dossier/page', 'y'));
        $this->assertSame('https://b.fr/z', WebPageReader::resolveRelative('https://a.fr/', '//b.fr/z'));
    }

    // ===================== Câblage =====================

    public function testLesOutilsDePiecesJointesSontDefinisLusEtMarquesCommeContenusTiers(): void
    {
        foreach (['GmailTools' => 'gmail', 'OutlookTools' => 'outlook'] as $class => $svc) {
            $src = AgentSource::tools($class);
            $tool = 'lire_piece_jointe_' . $svc;
            $this->assertStringContainsString("'name'        => '" . $tool . "'", $src);
            $this->assertStringContainsString("\$toolHandlers['" . $tool . "']", $src);
            $this->assertMatchesRegularExpression("/externalContentTools\(\): array\s*\{[^}]*'" . $tool . "'/s", $src,
                'Une pièce jointe est écrite par l\'expéditeur : contenu tiers.');
            $this->assertStringContainsString('readAttachment(', $src);
        }
        foreach (['src/Gmail/UserGmailService.php', 'src/Outlook/UserOutlookService.php'] as $f) {
            $code = (string)file_get_contents(ROOT . '/' . $f);
            $this->assertStringContainsString("'pieces_jointes'", $code, 'La lecture d\'un email liste ses pièces jointes.');
            $this->assertStringContainsString('MailAttachments::pick(', $code);
            $this->assertStringContainsString('TextPager::page(', $code, 'Une pièce jointe longue se lit par parties.');
        }
        $outlook = (string)file_get_contents(ROOT . '/src/Outlook/UserOutlookService.php');
        $this->assertStringContainsString("'id,name,contentType,size,isInline'", $outlook,
            'L\'inventaire n\'emporte pas contentBytes de chaque fichier.');
    }

    public function testLaLecturePasseParLaGardeEtLaConnexionEstEpinglee(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Helpers/WebPageReader.php');
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $src, 'curl ne suit jamais une redirection seul.');
        $this->assertStringContainsString("CURLOPT_PROXY          => ''", $src, 'Aucun proxy : l\'épinglage doit valoir.');
        $this->assertStringContainsString('CURLOPT_RESOLVE', $src);
        $this->assertStringContainsString('CURLPROTO_HTTP | CURLPROTO_HTTPS', $src);

        $tools = AgentSource::tools('WebPageTools');
        $this->assertStringContainsString("return ['lire_page_web'];", $tools, 'Une page web est un contenu tiers.');
    }

    public function testLeChatExposeLaLectureWebSeulementAvecInternetEtSansForcerLaBoucle(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('$pageUrlInMessage = $supportsTools && $webAccess && WebPageReader::firstUrl($content) !== null;', $src);
        $this->assertStringContainsString('|| $pageUrlInMessage || $pastReference || $fileMention;', $src,
            'Une adresse collée est une demande explicite (arbitrage recherche web / outils).');
        $this->assertStringContainsString('$loopRunsAnyway = $registry->hasTools();', $src);
        $this->assertStringContainsString('if ($supportsTools && $webAccess && ($pageUrlInMessage || $loopRunsAnyway)) {', $src);
        // Avant le plan de travail : sa consigne suit celles des outils.
        $this->assertLessThan(strpos($src, 'new PlanTools('), strpos($src, 'new WebPageTools($turn)'));
    }

    public function testLesNouvellesActionsOntLibelleIconeEtPhase(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        foreach (['gmail_attachment', 'outlook_attachment', 'calendar_slots', 'mscalendar_slots', 'web_read', 'conversation_search'] as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS);
            $this->assertArrayHasKey($type, ChatController::ACTION_ICONS);
            $this->assertMatchesRegularExpression('/^\s+' . $type . ':\s+t\(/m', $js, 'Phase SSE sans libellé : ' . $type);
        }
        $this->assertSame('gmail_attachment', ChatController::actionEntryForTool('lire_piece_jointe_gmail', ['piece_jointe' => '1'], ['nom' => 'Facture.pdf'])['t']);
        $this->assertSame('Facture.pdf', ChatController::actionEntryForTool('lire_piece_jointe_gmail', ['piece_jointe' => '1'], ['nom' => 'Facture.pdf'])['d']);
        $this->assertSame('exemple.fr/article', ChatController::actionEntryForTool('lire_page_web', ['url' => 'https://www.exemple.fr/article'], [])['d']);
        $this->assertSame('Le vrai titre', ChatController::actionEntryForTool('lire_page_web', ['url' => 'https://exemple.fr/a'], ['titre' => 'Le vrai titre'])['d']);
        $this->assertSame('memorizing', ChatController::actionEntryForTool('memoriser', ['fait' => 'Préfère le thé.'], [])['t']);
        $this->assertSame('1 h 30 · paul@exemple.fr', ChatController::slotsTraceDetail(['duree_minutes' => 90, 'participants' => 'paul@exemple.fr']));
        $this->assertNull(ChatController::slotsTraceDetail([]));
    }

    public function testLaMemoireDeConversationRetientLeMessageDUnePieceJointeLue(): void
    {
        $entry = ConnectorContext::entryForTool('lire_piece_jointe_gmail', ['message' => 'abc12345'], [
            'message' => '18f2a9c4d1e0b7a6', 'objet' => 'Facture septembre', 'nom' => 'Facture.pdf', 'contenu' => '…',
        ]);
        $this->assertSame('gmail_msg', $entry['kind']);
        $this->assertSame('18f2a9c4d1e0b7a6', $entry['ref']);
        $this->assertSame('Facture septembre', $entry['name']);
    }

    public function testLesNouvellesClassesSontChargeesDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = (string)file_get_contents(ROOT . '/' . $f);
            foreach (['/src/Agent/TextPager.php', '/src/Connectors/MailAttachments.php', '/src/Helpers/WebPageReader.php',
                         '/src/Calendar/FreeSlots.php', '/src/Agent/ConversationSearch.php',
                         '/src/Agent/Tools/WebPageTools.php', '/src/Agent/Tools/ConversationTools.php'] as $cls) {
                $this->assertStringContainsString($cls, $src, $cls . ' absent de ' . $f);
            }
            $this->assertLessThan(strpos($src, '/src/Agent/Tools/WebPageTools.php'), strpos($src, '/src/Agent/AbstractToolProvider.php'));
        }
    }
}
