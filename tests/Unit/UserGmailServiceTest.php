<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Statiques pures du connecteur Gmail : MIME brouillon, extraction de parts, aperçus. */
final class UserGmailServiceTest extends TestCase
{
    // ---- buildDraftMime : construction + anti-injection d'en-têtes ----

    public function testMimeComplet(): void
    {
        $mime = UserGmailService::buildDraftMime('alice@exemple.fr', 'Réunion été', "Bonjour,\nVoici le point.");
        [$head, $body] = explode("\r\n\r\n", $mime, 2);

        $this->assertStringContainsString('To: alice@exemple.fr', $head);
        $this->assertStringContainsString('MIME-Version: 1.0', $head);
        // Le corps voyage désormais en multipart/alternative : le Markdown que
        // le modèle écrit spontanément partait sinon en toutes lettres chez le
        // destinataire (cf. MailDraftTest).
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="', $head);
        // Objet UTF-8 toujours encodé.
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Réunion été') . '?=', $head);

        // Les deux parts sont annoncées et décodables, texte d'abord.
        $this->assertStringContainsString('Content-Type: text/plain; charset="UTF-8"', $body);
        $this->assertStringContainsString('Content-Type: text/html; charset="UTF-8"', $body);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $body);
        preg_match_all("/\r\n\r\n([A-Za-z0-9+\/=\r\n]+?)\r\n--/", $mime, $m);
        $parts = array_map(
            static fn(string $b64): string => (string)base64_decode((string)preg_replace('/\s+/', '', $b64), true),
            $m[1]
        );
        $this->assertSame("Bonjour,\nVoici le point.", $parts[0] ?? '', 'La part texte reste le corps brut.');
        $this->assertStringContainsString('Bonjour,<br>Voici le point.', $parts[1] ?? '');
    }

    public function testMimeMultiDestinatairesEtCc(): void
    {
        $mime = UserGmailService::buildDraftMime('a@ex.fr, b@ex.fr', 'X', 'corps', 'c@ex.fr');
        $this->assertStringContainsString('To: a@ex.fr, b@ex.fr', $mime);
        $this->assertStringContainsString('Cc: c@ex.fr', $mime);
    }

    public function testMimeSansCc(): void
    {
        $this->assertStringNotContainsString('Cc:', UserGmailService::buildDraftMime('a@ex.fr', 'X', 'corps'));
    }

    public function testInjectionEnTeteDansDestinataire(): void
    {
        // CR/LF neutralisés → « victime@ex.fr Bcc: pirate@ex.fr » n'est plus une adresse valide.
        $this->expectException(RuntimeException::class);
        UserGmailService::buildDraftMime("victime@ex.fr\r\nBcc: pirate@ex.fr", 'X', 'corps');
    }

    public function testInjectionEnTeteDansObjet(): void
    {
        $mime = UserGmailService::buildDraftMime('a@ex.fr', "Objet\r\nBcc: pirate@ex.fr", 'corps');
        [$head] = explode("\r\n\r\n", $mime, 2);
        // Le pseudo-Bcc reste DANS l'objet encodé : aucun en-tête Bcc n'apparaît.
        $this->assertStringNotContainsString('Bcc:', $head);
        $this->assertStringContainsString('=?UTF-8?B?' . base64_encode('Objet Bcc: pirate@ex.fr') . '?=', $head);
    }

    public function testAdresseInvalide(): void
    {
        $this->expectException(RuntimeException::class);
        UserGmailService::buildDraftMime('pas-une-adresse', 'X', 'corps');
    }

    public function testDestinataireVide(): void
    {
        $this->expectException(RuntimeException::class);
        UserGmailService::buildDraftMime('', 'X', 'corps');
    }

    // ---- extractTextFromPayload ----

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function testExtractionPreferePlain(): void
    {
        $payload = ['mimeType' => 'multipart/alternative', 'parts' => [
            ['mimeType' => 'text/html', 'body' => ['data' => self::b64url('<b>Salut</b>')]],
            ['mimeType' => 'text/plain', 'body' => ['data' => self::b64url('Salut en texte')]],
        ]];
        $this->assertSame('Salut en texte', UserGmailService::extractTextFromPayload($payload));
    }

    public function testExtractionFallbackHtml(): void
    {
        $payload = ['mimeType' => 'text/html', 'body' => ['data' => self::b64url('<p>Bonjour&nbsp;!</p><p>Suite</p>')]];
        $texte = UserGmailService::extractTextFromPayload($payload);
        $this->assertStringContainsString('Bonjour', $texte);
        $this->assertStringContainsString('Suite', $texte);
        $this->assertStringNotContainsString('<p>', $texte);
    }

    public function testExtractionPartsImbriquees(): void
    {
        // multipart/mixed > multipart/alternative > text/plain
        $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
            ['mimeType' => 'multipart/alternative', 'parts' => [
                ['mimeType' => 'text/plain', 'body' => ['data' => self::b64url('Profond')]],
            ]],
            ['mimeType' => 'application/pdf', 'body' => ['data' => '']],
        ]];
        $this->assertSame('Profond', UserGmailService::extractTextFromPayload($payload));
    }

    public function testExtractionVide(): void
    {
        $this->assertSame('', UserGmailService::extractTextFromPayload(['mimeType' => 'text/plain', 'body' => []]));
    }

    // ---- actionPreview ----

    public function testActionPreviewBrouillon(): void
    {
        $p = UserGmailService::actionPreview('creer_brouillon_gmail', [
            'destinataire' => 'alice@ex.fr',
            'objet'        => 'Point projet',
            'corps'        => str_repeat('x', 300),
            'cc'           => 'bob@ex.fr',
        ]);
        $this->assertSame('Nouvel email Gmail', $p['action']);
        $this->assertSame('Point projet → alice@ex.fr (cc : bob@ex.fr)', $p['fichier']);
        $this->assertNull($p['url']);
        // Deux issues d'exécution sur la carte : les impacts disent ce que fait
        // chacune — l'une range un brouillon, l'autre expédie sans retour.
        $this->assertStringContainsString('sans rien envoyer', $p['impacts'][0]);
        $this->assertStringContainsString('« Envoyer »', $p['impacts'][0]);
        $this->assertStringContainsString('sans retour possible', $p['impacts'][0]);
        $this->assertSame(281, mb_strlen($p['apercu']));
        $this->assertStringEndsWith('…', $p['apercu']);
        $this->assertSame('/assets/img/gmail.svg', $p['icone']);
    }

    public function testActionPreviewSansObjet(): void
    {
        $p = UserGmailService::actionPreview('creer_brouillon_gmail', ['destinataire' => 'a@ex.fr', 'corps' => '']);
        $this->assertSame('(sans objet) → a@ex.fr', $p['fichier']);
        $this->assertNull($p['apercu']);
    }

    // ---- divers ----

    public function testBase64UrlEncode(): void
    {
        $encoded = UserGmailService::base64UrlEncode("sujet\xFB\xFF binaire?");
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
    }

    public function testDisabledApiGmail(): void
    {
        $body = '{"error":{"status":"PERMISSION_DENIED","details":[{"reason":"SERVICE_DISABLED","metadata":{"service":"gmail.googleapis.com"}}]}}';
        $this->assertSame('Gmail', UserDriveService::disabledApiFromBody($body));
    }
}
