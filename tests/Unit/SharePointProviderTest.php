<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Parties pures du fournisseur SharePoint (encodage shareId, parse, normalisation Graph). */
final class SharePointProviderTest extends TestCase
{
    public function testShareIdFromUrlFormatBase64Url(): void
    {
        $url = 'https://contoso.sharepoint.com/sites/Equipe/Documents partages/Dossier RAG';
        $id  = SharePointProvider::shareIdFromUrl($url);

        $this->assertStringStartsWith('u!', $id);
        $this->assertStringNotContainsString('=', $id);
        $this->assertStringNotContainsString('+', $id);
        $this->assertStringNotContainsString('/', substr($id, 2));

        // Round-trip : le shareId doit re-décoder exactement l'URL d'origine.
        $decoded = base64_decode(strtr(substr($id, 2), '-_', '+/'));
        $this->assertSame($url, $decoded);
    }

    public function testParseFolderInputAccepteUrlHttps(): void
    {
        $url = 'https://contoso.sharepoint.com/sites/Equipe/Shared%20Documents/Dossier';
        $this->assertSame($url, SharePointProvider::parseFolderInput($url));
        $this->assertSame($url, SharePointProvider::parseFolderInput('  ' . $url . '  '));

        // Lien de partage « raccourci » SharePoint.
        $share = 'https://contoso.sharepoint.com/:f:/s/Equipe/EhQx0abcdEFGH';
        $this->assertSame($share, SharePointProvider::parseFolderInput($share));
    }

    public function testParseFolderInputRejets(): void
    {
        $this->assertNull(SharePointProvider::parseFolderInput(''));
        $this->assertNull(SharePointProvider::parseFolderInput('   '));
        $this->assertNull(SharePointProvider::parseFolderInput('pas une url'));
        $this->assertNull(SharePointProvider::parseFolderInput('http://contoso.sharepoint.com/sites/X'));
        $this->assertNull(SharePointProvider::parseFolderInput('https://sanspoint/dossier'));

        // Au-delà de la colonne sync_folder_ref (500) : refus, pas de troncature.
        $long = 'https://contoso.sharepoint.com/' . str_repeat('a', 500);
        $this->assertNull(SharePointProvider::parseFolderInput($long));
    }

    public function testNormalizeItemFichierAvecHash(): void
    {
        $entry = SharePointProvider::normalizeItem([
            'id'   => '01ABC',
            'name' => 'rapport.pdf',
            'size' => 12345,
            'lastModifiedDateTime' => '2026-07-01T10:00:00Z',
            'file' => [
                'mimeType' => 'application/pdf',
                'hashes'   => ['quickXorHash' => 'AbCd012+/='],
            ],
        ]);

        $this->assertNotNull($entry);
        $this->assertSame('01ABC', $entry['id']);
        $this->assertSame('rapport.pdf', $entry['name']);
        $this->assertSame('application/pdf', $entry['mime']);
        $this->assertSame(12345, $entry['size']);
        $this->assertSame('AbCd012+/=', $entry['hash']);
        $this->assertSame('2026-07-01T10:00:00Z', $entry['modified']);
        $this->assertNull($entry['export']); // jamais d'export SharePoint (vrais fichiers Office)
    }

    public function testNormalizeItemSansHash(): void
    {
        $entry = SharePointProvider::normalizeItem([
            'id'   => '01DEF',
            'name' => 'note.docx',
            'size' => 10,
            'lastModifiedDateTime' => '2026-07-02T08:30:00Z',
            'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ]);

        $this->assertNotNull($entry);
        $this->assertNull($entry['hash']); // tenant chiffré : quickXorHash absent → fallback modified
    }

    public function testNormalizeItemDossierEtIdVide(): void
    {
        // Dossier : traité par le parcours BFS, pas par la normalisation.
        $this->assertNull(SharePointProvider::normalizeItem([
            'id' => '01DIR', 'name' => 'Sous-dossier', 'folder' => ['childCount' => 3],
        ]));
        $this->assertNull(SharePointProvider::normalizeItem(['name' => 'sans-id.pdf']));
    }

    public function testTokenErrorMessageCodesAadsts(): void
    {
        $cas = [
            ['AADSTS90002: Tenant \'x\' not found',            'tenant) introuvable'],
            ['AADSTS700016: Application not found',            'inconnu de ce tenant'],
            ['AADSTS7000215: Invalid client secret provided',  'Secret client invalide'],
            ['AADSTS7000222: expired client secret keys',      'Secret client expiré'],
        ];
        foreach ($cas as [$body, $attendu]) {
            $msg = SharePointProvider::tokenErrorMessage(400, '{"error_description":"' . $body . '"}');
            $this->assertStringContainsString($attendu, $msg);
            // Jamais de recopie du corps brut Microsoft dans le message utilisateur.
            $this->assertStringNotContainsString('AADSTS', $msg);
        }
    }

    public function testTokenErrorMessageGenerique(): void
    {
        $msg = SharePointProvider::tokenErrorMessage(401, '{"error":"invalid_client"}');
        $this->assertStringContainsString('Identifiants Azure invalides', $msg);
        $this->assertStringContainsString('401', $msg);
    }

    public function testRolesFromJwt(): void
    {
        $jwt = static function (array $payload): string {
            $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            return $b64('{"alg":"RS256"}') . '.' . $b64(json_encode($payload)) . '.' . $b64('sig');
        };

        $this->assertSame(
            ['Files.Read.All', 'User.Read.All'],
            SharePointProvider::rolesFromJwt($jwt(['roles' => ['Files.Read.All', 'User.Read.All']]))
        );
        // Consentement admin absent : claim roles manquant → liste vide (≠ null).
        $this->assertSame([], SharePointProvider::rolesFromJwt($jwt(['aud' => 'graph'])));
        // Token opaque / illisible → null (permissions non vérifiables).
        $this->assertNull(SharePointProvider::rolesFromJwt('pas-un-jwt'));
        $this->assertNull(SharePointProvider::rolesFromJwt('a.%%%.c'));
    }

    public function testNormalizeItemPackageSansFacetFile(): void
    {
        // Package (OneNote…) : ni facet file ni folder → mime vide, que le
        // moteur de sélection comptera en « ignoré — format ».
        $entry = SharePointProvider::normalizeItem([
            'id' => '01PKG', 'name' => 'Bloc-notes', 'size' => 999,
            'lastModifiedDateTime' => '2026-07-03T12:00:00Z',
            'package' => ['type' => 'oneNote'],
        ]);

        $this->assertNotNull($entry);
        $this->assertSame('', $entry['mime']);
    }
}
