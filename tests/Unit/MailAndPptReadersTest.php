<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/LegacyOfficeFixture.php';

/**
 * Lecteurs dédiés des binaires fréquents :
 *  - emails enregistrés (.eml MIME, .msg Outlook) : en-têtes, corps, pièces
 *    jointes nommées puis lues, email joint compris (MailFile) ;
 *  - PowerPoint 97-2003 (.ppt) : diapositives dans l'ordre de la
 *    présentation, notes comprises (LegacyOffice::pptText).
 * Les .msg et .ppt sont écrits octet par octet (LegacyOfficeFixture) ; le .ppt
 * réel, enregistré par LibreOffice, vit dans tests/Fixtures/legacy-office.
 */
final class MailAndPptReadersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mia_mailppt_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/legacy-office/' . $name;
    }

    // ===================== .eml =====================

    private function eml(): string
    {
        $csv = "Poste;Montant\nLocation;1200\nTraiteur;850\n";
        $inner = "From: Paul <paul@exemple.fr>\nSubject: Devis initial\nDate: Mon, 5 Oct 2026 09:00:00 +0200\n"
            . "Content-Type: text/plain; charset=utf-8\n\nLe devis initial était de 1 900 €.\n";
        return "Received: from relais\nFrom: =?UTF-8?B?w4lsaXNl?= Durand <elise@exemple.fr>\n"
            . "To: Axel <axel@exemple.fr>, Marie <marie@exemple.fr>\nCc: compta@exemple.fr\n"
            . "Subject: =?UTF-8?Q?R=C3=A9union_budg=C3=A9taire?=\n Q4\nDate: Tue, 27 Oct 2026 14:30:00 +0100\n"
            . "MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"AAA\"\n\n"
            . "Préambule ignoré\n--AAA\nContent-Type: multipart/alternative; boundary=BBB\n\n"
            . "--BBB\nContent-Type: text/plain; charset=iso-8859-1\nContent-Transfer-Encoding: quoted-printable\n\n"
            . "Bonjour,\nVoici le budget pr=E9vu pour l'=E9v=E9nement.\n--BBB\n"
            . "Content-Type: text/html; charset=utf-8\n\n<p>Ne pas lire la version HTML</p>\n--BBB--\n"
            . "--AAA\nContent-Type: text/csv; name=\"budget.csv\"\nContent-Disposition: attachment; filename*=utf-8''budget%20pr%C3%A9vu.csv\n"
            . "Content-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($csv), 76, "\n")
            . "--AAA\nContent-Type: image/png\nContent-Disposition: inline\nContent-ID: <logo>\n"
            . "Content-Transfer-Encoding: base64\n\niVBORw0KGgo=\n"
            . "--AAA\nContent-Type: message/rfc822\n\n" . $inner
            . "--AAA--\nÉpilogue ignoré\n";
    }

    public function testUnEmailEstLuAvecSesEnTetesSonCorpsEtSesPiecesJointes(): void
    {
        $path = $this->file('reunion.eml', $this->eml());
        $this->assertSame(MailFile::MIME_EML, FileExtractor::detectMime($path, 'reunion.eml'));
        $text = (string)FileExtractor::extract($path, MailFile::MIME_EML);

        $this->assertStringContainsString('# Email : Réunion budgétaire Q4', $text, 'Objet décodé (RFC 2047) et replié.');
        $this->assertStringContainsString('De : Élise Durand <elise@exemple.fr>', $text);
        $this->assertStringContainsString('À : Axel <axel@exemple.fr>, Marie <marie@exemple.fr>', $text);
        $this->assertStringContainsString('Cc : compta@exemple.fr', $text);
        $this->assertStringContainsString('Date : 2026-10-27 13:30 UTC', $text);
        $this->assertStringContainsString("Voici le budget prévu pour l'événement.", $text, 'Quoted-printable et ISO-8859-1 décodés.');
        $this->assertStringNotContainsString('Ne pas lire la version HTML', $text, 'La version texte passe avant le HTML.');
        $this->assertStringNotContainsString('Préambule', $text);
        $this->assertStringContainsString('Pièces jointes : budget prévu.csv, Devis initial.eml', $text,
            'Nom RFC 2231 décodé ; image collée dans le corps ni listée ni lue.');
        $this->assertStringContainsString("## Pièce jointe : budget prévu.csv\nPoste;Montant", $text);
        $this->assertStringContainsString('## Pièce jointe : Devis initial.eml', $text);
        $this->assertStringContainsString('Le devis initial était de 1 900 €.', $text, 'L\'email joint est lu à son tour.');
    }

    public function testUnEmailEnHtmlSeulEstConvertiEnTexte(): void
    {
        $raw = "From: a@b.fr\nSubject: Lettre\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n"
            . base64_encode('<html><style>p{color:red}</style><body><p>Premier paragraphe</p><p>Second</p><script>x()</script></body></html>');
        $mail = MailFile::parseEml($raw);
        $this->assertNotNull($mail);
        $this->assertStringContainsString('Premier paragraphe', $mail['body']);
        $this->assertStringContainsString('Second', $mail['body']);
        $this->assertStringNotContainsString('color', $mail['body']);
        $this->assertStringNotContainsString('x()', $mail['body']);
    }

    public function testUnTexteQuelconqueNEstPasPrisPourUnEmail(): void
    {
        $this->assertNull(MailFile::parseEml("Juste une note\nsans en-tête\n"));
        $this->assertNull(MailFile::parseEml(''));
    }

    // ===================== .msg =====================

    private static function u16(string $s): string
    {
        return mb_convert_encoding($s . "\0", 'UTF-16LE', 'UTF-8');
    }

    /** Flux de propriétés : en-tête (taille selon le niveau), puis entrées de 16 octets. */
    private static function props(int $header, array $entries): string
    {
        $out = str_repeat("\0", $header);
        foreach ($entries as $tag => $value8) {
            $out .= pack('V', $tag) . pack('V', 6) . $value8;
        }
        return $out;
    }

    private function msg(): string
    {
        // 27/10/2026 13:30 UTC en FILETIME.
        $ft = (1793107800 + 11644473600) * 10000000;
        $embedded = [
            '__properties_version1.0' => self::props(24, []),
            '__substg1.0_0037001F'    => self::u16('Ancien fil'),
            '__substg1.0_1000001F'    => self::u16('Message transféré : rendez-vous jeudi.'),
        ];
        return LegacyOfficeFixture::cfb([
            '__properties_version1.0' => self::props(32, [0x00390040 => pack('P', $ft)]),
            '__substg1.0_0037001F'    => self::u16('Point budget Q4'),
            '__substg1.0_0C1A001F'    => self::u16('Élise Durand'),
            '__substg1.0_5D01001F'    => self::u16('elise@exemple.fr'),
            '__substg1.0_1000001F'    => self::u16("Bonjour,\r\nle budget est validé.\r\n"),
        ], [
            '__recip_version1.0_#00000000' => [
                '__properties_version1.0' => self::props(8, [0x0C150003 => pack('VV', 1, 0)]),
                '__substg1.0_3001001F'    => self::u16('Axel'),
                '__substg1.0_39FE001F'    => self::u16('axel@exemple.fr'),
            ],
            '__recip_version1.0_#00000001' => [
                '__properties_version1.0' => self::props(8, [0x0C150003 => pack('VV', 2, 0)]),
                '__substg1.0_3001001F'    => self::u16('Compta'),
                '__substg1.0_39FE001F'    => self::u16('compta@exemple.fr'),
            ],
            '__attach_version1.0_#00000000' => [
                '__properties_version1.0' => self::props(8, []),
                '__substg1.0_3707001F'    => self::u16('notes réunion.txt'),
                // Au-delà de 4096 octets : le conteneur de test n'écrit pas de mini-flux.
                '__substg1.0_37010102'    => "Décision : lancer l'appel d'offres.\n" . str_repeat("Ligne de détail.\n", 300),
            ],
            '__attach_version1.0_#00000001' => [
                '__properties_version1.0' => self::props(8, []),
                '__substg1.0_3001001F'    => self::u16('Ancien fil'),
                '__substg1.0_3701000D'    => $embedded,
            ],
        ]);
    }

    public function testUnEmailOutlookEstLuAvecDestinatairesEtPiecesJointes(): void
    {
        $path = $this->file('budget.msg', $this->msg());
        $this->assertSame('msg', LegacyOffice::kind($path));
        $this->assertSame(MailFile::MIME_MSG, FileExtractor::detectMime($path, 'budget.msg'));
        $text = (string)FileExtractor::extract($path, MailFile::MIME_MSG);

        $this->assertStringContainsString('# Email : Point budget Q4', $text);
        $this->assertStringContainsString('De : Élise Durand <elise@exemple.fr>', $text);
        $this->assertStringContainsString('À : Axel <axel@exemple.fr>', $text);
        $this->assertStringContainsString('Cc : Compta <compta@exemple.fr>', $text);
        $this->assertStringContainsString('Date : 2026-10-27 13:30 UTC', $text);
        $this->assertStringContainsString('le budget est validé.', $text);
        $this->assertStringContainsString('Pièces jointes : notes réunion.txt, Ancien fil', $text);
        $this->assertStringContainsString("Décision : lancer l'appel d'offres.", $text);
        $this->assertStringContainsString('Message transféré : rendez-vous jeudi.', $text, 'L\'email joint (dossier incorporé) est lu.');
    }

    public function testLeCorpsRtfCompresseDOutlookSeDecompresse(): void
    {
        // Une référence au dictionnaire initial (« {\rtf1 », 6 octets en 0),
        // des littéraux, puis la fin (référence à la position d'écriture).
        $literal = ' Bonjour}';
        $body = chr(0b00000001) . "\x00\x04" . substr($literal, 0, 7) . chr(0b00000100) . substr($literal, 7)
            . pack('n', ((207 + 6 + strlen($literal)) << 4));
        $data = pack('VV', strlen($body) + 12, 6 + strlen($literal)) . 'LZFu' . pack('V', 0) . $body;
        $this->assertSame('{\rtf1 Bonjour}', MailFile::decompressRtf($data));

        $this->assertSame('{\rtf1 brut}', MailFile::decompressRtf(pack('VV', 23, 12) . 'MELA' . pack('V', 0) . '{\rtf1 brut}'));
        $this->assertNull(MailFile::decompressRtf('court'));
        $this->assertNull(MailFile::decompressRtf(pack('VV', 20, 10) . 'XXXX' . pack('V', 0) . 'abcd'));
    }

    // ===================== .ppt =====================

    public function testUnePresentationPowerPoint97EstLueDansLOrdreAvecSesNotes(): void
    {
        $path = self::fixture('ancien.ppt');
        $this->assertSame('ppt', LegacyOffice::kind($path));
        $this->assertSame(LegacyOffice::MIME_PPT, FileExtractor::detectMime($path, 'ancien.ppt'));
        $text = (string)FileExtractor::extract($path, LegacyOffice::MIME_PPT);

        $this->assertStringContainsString("## Diapositive 1\nBilan trimestriel été", $text);
        $this->assertStringContainsString('Coût unitaire : 3 €', $text, 'Texte d\'un octet par caractère converti.');
        $this->assertStringContainsString('Notes : Insister sur la marge.', $text);
        $this->assertStringContainsString("## Diapositive 2\nProchaines étapes", $text);
        $this->assertStringContainsString('Zone libre : recruter deux personnes', $text, 'Une zone de texte libre est lue.');
        $this->assertLessThan(strpos($text, 'Diapositive 2'), strpos($text, 'Diapositive 1'));
        $this->assertStringNotContainsString('Cliquez pour', $text, 'Le masque des diapositives n\'est pas lu.');
    }

    public function testUnePresentationAbimeeRendNull(): void
    {
        $path = $this->file('faux.ppt', LegacyOfficeFixture::cfb(['PowerPoint Document' => "\x0F\x00\xE8\x03\xFF\xFF\xFF\x7F"]));
        $this->assertSame('ppt', LegacyOffice::kind($path));
        $this->assertNull(LegacyOffice::pptText($path), 'Un enregistrement qui déborde arrête la lecture, sans boucle.');
    }

    // ===================== Partout =====================

    public function testLesNouveauxFormatsSontAcceptesPartout(): void
    {
        foreach ([LegacyOffice::MIME_PPT, MailFile::MIME_EML, MailFile::MIME_MSG] as $mime) {
            $this->assertContains($mime, UPLOAD_ALLOWED_TYPES, $mime);
            $this->assertArrayHasKey($mime, KnowledgeBaseDocument::ALLOWED_MIMES, $mime);
            $this->assertTrue(FileExtractor::isExtractable($mime), $mime);
        }
        $accept = FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES);
        foreach (['.ppt', '.eml', '.msg'] as $ext) {
            $this->assertStringContainsString($ext, $accept);
        }
        foreach ([['vieux.ppt', 'application/vnd.ms-powerpoint'], ['fil.eml', 'message/rfc822'], ['note.msg', 'application/octet-stream']] as [$n, $t]) {
            $this->assertNull(MailAttachments::unreadableReason(['nom' => $n, 'type' => $t, 'taille' => 10, 'piece' => 'fichier']), $n);
        }
        $this->assertNull(MailAttachments::unreadableReason(['nom' => 'Transfert', 'type' => '', 'taille' => 10, 'piece' => 'email']),
            'Un email joint Outlook se lit par son contenu MIME.');

        $root = dirname(__DIR__, 2);
        $outlook = (string)file_get_contents($root . '/src/Outlook/UserOutlookService.php');
        $this->assertStringContainsString("\$url . '/\$value'", $outlook, 'Email joint Outlook téléchargé en MIME.');
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertNotFalse(strpos($src, 'MailFile.php'), $f);
            $this->assertLessThan(strpos($src, 'MailFile.php'), strpos($src, 'LegacyOffice.php'), $f . ' : LegacyOffice avant MailFile.');
        }
    }
}
