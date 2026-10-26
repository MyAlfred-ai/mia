<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Client de l'API MyBrain (base de connaissances « en direct ») — sans réseau :
 * le transport est remplacé par un faux qui enregistre ce qu'on lui envoie et
 * rejoue une réponse.
 *
 * Ce qui compte ici, dans l'ordre : la clé ne part QUE dans l'en-tête, jamais
 * dans l'URL ; une adresse en clair n'est acceptée que sur le réseau interne ;
 * chaque panne est rangée dans la bonne famille (refus durable, panne
 * passagère, demande refusée) — c'est elle qui décide si la base se grise.
 */
final class MyBrainClientTest extends TestCase
{
    private const KEY = 'mbk_aaaaaaaaaaaaaaaaaaaaaaaaaaaa012345';

    /** @var list<array{method:string, url:string, headers:list<string>, body:?string, timeout:int}> */
    private array $calls = [];

    /** Faux transport : note l'appel, rend [statut, corps, erreur réseau]. */
    private function transport(int $status, string $body, string $netError = ''): callable
    {
        return function (string $method, string $url, array $headers, ?string $body_, int $timeout) use ($status, $body, $netError): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body_, 'timeout' => $timeout];
            return [$status, $body, $netError];
        };
    }

    private function client(int $status, string $body, string $netError = ''): MyBrainClient
    {
        return new MyBrainClient('https://cerveau.exemple.fr', self::KEY, $this->transport($status, $body, $netError));
    }

    // =================== Adresse ===================

    public function testUneAdresseHttpsEstRameneeASaRacine(): void
    {
        $this->assertSame('https://cerveau.exemple.fr', MyBrainClient::normalizeBaseUrl('  https://cerveau.exemple.fr/  '));
        $this->assertSame('https://cerveau.exemple.fr/sous', MyBrainClient::normalizeBaseUrl('https://cerveau.exemple.fr/sous/'));
        // Requête et fragment n'ont rien à faire dans une base d'API.
        $this->assertSame('https://cerveau.exemple.fr', MyBrainClient::normalizeBaseUrl('https://cerveau.exemple.fr/?x=1#y'));
    }

    /** MyBrain affiche l'adresse de l'API et celle du serveur MCP : les deux se collent souvent à la place. */
    public function testLAdresseDeLApiOuDuMcpEstRameneeAuCerveau(): void
    {
        $this->assertSame('https://cerveau.exemple.fr', MyBrainClient::normalizeBaseUrl('https://cerveau.exemple.fr/api/v1'));
        $this->assertSame('https://cerveau.exemple.fr', MyBrainClient::normalizeBaseUrl('https://cerveau.exemple.fr/mcp/'));
    }

    /** La clé voyagerait en clair sur Internet : http n'est accepté que sur la machine ou le réseau interne. */
    public function testLeHttpEnClairNEstAccepteQuEnInterne(): void
    {
        $this->assertNull(MyBrainClient::normalizeBaseUrl('http://cerveau.exemple.fr'));
        $this->assertNull(MyBrainClient::normalizeBaseUrl('http://10.0.0.5:3000'));
        $this->assertSame('http://localhost:5199', MyBrainClient::normalizeBaseUrl('http://localhost:5199'));
        $this->assertSame('http://127.0.0.1:3000', MyBrainClient::normalizeBaseUrl('http://127.0.0.1:3000'));
        $this->assertSame('http://mybrain-web:3000', MyBrainClient::normalizeBaseUrl('http://mybrain-web:3000'));
        $this->assertSame('http://host.docker.internal:5199', MyBrainClient::normalizeBaseUrl('http://host.docker.internal:5199'));
        $this->assertSame('http://[::1]:3000', MyBrainClient::normalizeBaseUrl('http://[::1]:3000'));
    }

    public function testLesAdressesInacceptablesSontRefusees(): void
    {
        foreach (['', 'cerveau.exemple.fr', 'ftp://cerveau.exemple.fr', 'javascript:alert(1)',
                  'https://moi:secret@cerveau.exemple.fr', 'https://' . str_repeat('a', 420) . '.fr'] as $raw) {
            $this->assertNull(MyBrainClient::normalizeBaseUrl($raw), $raw);
        }
    }

    // =================== Clé ===================

    public function testLaFormeDUneCleEstCelleDeMyBrain(): void
    {
        $this->assertTrue(MyBrainClient::isKeyShaped(self::KEY));
        $this->assertFalse(MyBrainClient::isKeyShaped('mbk_court'));
        $this->assertFalse(MyBrainClient::isKeyShaped('at_' . substr(self::KEY, 4))); // jeton OAuth d'une personne
        $this->assertFalse(MyBrainClient::isKeyShaped(' ' . self::KEY));
    }

    public function testLApercuReconnaitLaCleSansPermettreDeSEnServir(): void
    {
        $hint = MyBrainClient::keyHint(self::KEY);
        $this->assertSame('mbk_aaaa…2345', $hint);
        $this->assertStringNotContainsString(substr(self::KEY, 8, 20), $hint);
        $this->assertSame('', MyBrainClient::keyHint('mbk_x'));
    }

    // =================== Appels ===================

    public function testLaCleNePartQueDansLEnTete(): void
    {
        $this->client(200, json_encode(['application' => 'MIA', 'identite' => 'app:mia', 'droits' => ['lire']]))->whoami();

        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://cerveau.exemple.fr/api/v1', $call['url']);
        $this->assertStringNotContainsString(self::KEY, $call['url'], 'La clé ne doit jamais apparaître dans l\'URL.');
        $this->assertContains('Authorization: Bearer ' . self::KEY, $call['headers']);
        $this->assertNull($call['body']);
    }

    public function testQuiSuisJeRendLApplicationEtSesSeulsDroitsConnus(): void
    {
        $moi = $this->client(200, json_encode([
            'application' => '  MIA (équipe)  ',
            'identite'    => 'app:mia-equipe',
            'droits'      => ['lire', 'nourrir', 'administrer', 'lire'],
        ]))->whoami();

        $this->assertSame('MIA (équipe)', $moi['application']);
        $this->assertSame('app:mia-equipe', $moi['identite']);
        $this->assertSame(['lire', 'nourrir'], $moi['droits'], 'Un droit inconnu ou répété ne passe pas.');
    }

    public function testUnServeurQuiNeSePresentePasCommeMyBrainEstInvalide(): void
    {
        try {
            $this->client(200, json_encode(['ok' => true]))->whoami();
            $this->fail('Une réponse sans « application » doit être refusée.');
        } catch (MyBrainException $e) {
            $this->assertSame(MyBrainException::INVALID, $e->kind);
        }
    }

    public function testLeContexteEnvoieQuestionHistoriqueEtBudget(): void
    {
        $res = $this->client(200, json_encode([
            'fiches'   => [
                ['chemin' => 'wiki/clients/globex.md', 'titre' => 'Globex', 'url' => 'https://cerveau.exemple.fr/cerveau?f=wiki%2Fclients%2Fglobex.md', 'derniere_maj' => '2026-09-06'],
            ],
            'tronque'  => true,
            'contexte' => "### Globex — wiki/clients/globex.md\nClient actif.",
        ]))->contexte('et les chantiers ?', [['role' => 'user', 'content' => 'Où en est Globex ?']], 4000);

        $call = $this->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://cerveau.exemple.fr/api/v1/contexte', $call['url']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
        $sent = json_decode((string)$call['body'], true);
        $this->assertSame('et les chantiers ?', $sent['question']);
        $this->assertSame([['role' => 'user', 'content' => 'Où en est Globex ?']], $sent['historique']);
        $this->assertSame(4000, $sent['budget']);
        $this->assertSame(MyBrainClient::TIMEOUT, $call['timeout']);

        $this->assertTrue($res['tronque']);
        $this->assertSame('Globex', $res['fiches'][0]['titre']);
        $this->assertSame('2026-09-06', $res['fiches'][0]['derniere_maj']);
    }

    public function testSansHistoriqueNiBudgetLesChampsNePartentPas(): void
    {
        $this->client(200, json_encode(['fiches' => [], 'tronque' => false, 'contexte' => '']))->contexte('Globex');
        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame(['question' => 'Globex'], $sent, 'MyBrain applique alors ses défauts.');
    }

    /** Un lien de fiche finit cliquable dans une réponse : seuls http(s) passent. */
    public function testSeulsLesLiensHttpDesFichesSurvivent(): void
    {
        $res = MyBrainClient::parseContexte([
            'fiches' => [
                ['chemin' => 'a.md', 'titre' => 'A', 'url' => 'javascript:alert(1)'],
                ['chemin' => 'b.md', 'titre' => 'B', 'url' => 'https://cerveau.exemple.fr/cerveau?f=b.md'],
                'pas une fiche',
            ],
        ]);
        $this->assertCount(2, $res['fiches']);
        $this->assertSame('', $res['fiches'][0]['url']);
        $this->assertSame('https://cerveau.exemple.fr/cerveau?f=b.md', $res['fiches'][1]['url']);
        $this->assertFalse($res['tronque']);
        $this->assertSame('', $res['contexte']);
    }

    // =================== Pannes ===================

    public function testUnePanneReseauEstPassagere(): void
    {
        try {
            $this->client(0, '', 'Could not resolve host: cerveau.exemple.fr')->contexte('Globex');
            $this->fail('Une panne réseau doit lever.');
        } catch (MyBrainException $e) {
            $this->assertSame(MyBrainException::UNREACHABLE, $e->kind);
            $this->assertFalse($e->isDurable());
            $this->assertStringContainsString('injoignable', $e->getMessage());
        }
    }

    public function testUneReponseIllisibleEstInvalide(): void
    {
        try {
            $this->client(200, '<html>Bienvenue</html>')->contexte('Globex');
            $this->fail('Une page HTML n\'est pas une réponse d\'API.');
        } catch (MyBrainException $e) {
            $this->assertSame(MyBrainException::INVALID, $e->kind);
            $this->assertTrue($e->isDurable());
        }
    }

    /** La famille décide de l'écran : REFUSED/INVALID grisent la base, UNREACHABLE non, REQUEST ne dit rien de la connexion. */
    public function testChaqueStatutEstRangeDansSaFamille(): void
    {
        $cas = [
            [401, null,                                         MyBrainException::REFUSED],
            [403, ['erreur' => 'cette clé n\'a pas le droit « lire »'], MyBrainException::REFUSED],
            [404, null,                                         MyBrainException::INVALID],
            [302, null,                                         MyBrainException::INVALID],
            [429, ['erreur' => 'déjà 3 questions en cours'],    MyBrainException::UNREACHABLE],
            [500, null,                                         MyBrainException::UNREACHABLE],
            [503, ['erreur' => 'aucune clé de modèle'],         MyBrainException::UNREACHABLE],
            [400, ['erreur' => 'question manquante'],           MyBrainException::REQUEST],
            [413, ['erreur' => 'question trop longue'],         MyBrainException::REQUEST],
        ];
        foreach ($cas as [$status, $data, $kind]) {
            $this->assertSame($kind, MyBrainClient::errorFor($status, $data)->kind, 'HTTP ' . $status);
        }
    }

    public function testLeMessageDeMyBrainEstRepris(): void
    {
        $e = MyBrainClient::errorFor(403, ['erreur' => 'cette clé n\'a pas le droit « lire »']);
        $this->assertStringContainsString('le droit « lire »', $e->getMessage());
        // Une redirection est refusée : l'en-tête Authorization partirait avec.
        $this->assertStringContainsString('redirige', MyBrainClient::errorFor(301, null)->getMessage());
    }

    // =================== Fiche (outil lire_fiche_mybrain) ===================

    public function testUneFicheSeLitParSonCheminEncodeDansLUrl(): void
    {
        $fiche = $this->client(200, json_encode([
            'chemin'  => 'wiki/clients/exemple.md',
            'titre'   => 'Exemple',
            'contenu' => "# Exemple\n\nClient depuis 2024.",
            'url'     => 'https://cerveau.exemple.fr/cerveau?f=wiki%2Fclients%2Fexemple.md',
        ]))->fiche('wiki/clients/exemple.md');

        $this->assertTrue($fiche['trouvee']);
        $this->assertSame('Exemple', $fiche['titre']);
        $this->assertStringContainsString('Client depuis 2024.', $fiche['contenu']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://cerveau.exemple.fr/api/v1/fiche?chemin=wiki%2Fclients%2Fexemple.md', $this->calls[0]['url']);
        // La clé reste dans l'en-tête, jamais dans l'URL — même pour une lecture.
        $this->assertStringNotContainsString(self::KEY, $this->calls[0]['url']);
    }

    public function testUneFicheSeLitAussiParLeNomDeCeQuElleDecrit(): void
    {
        $this->client(200, json_encode(['chemin' => 'wiki/clients/exemple.md', 'titre' => 'Exemple', 'contenu' => 'x', 'url' => '']))
            ->fiche('', 'Exemple & fils');
        $this->assertSame('https://cerveau.exemple.fr/api/v1/fiche?nom=Exemple+%26+fils', $this->calls[0]['url']);
    }

    /**
     * Une fiche introuvable est une RÉPONSE : un nom mal orthographié ne doit
     * pas compter comme une panne de la connexion (et finir par griser la base).
     */
    public function testUneFicheIntrouvableRendLesCandidatsSansLever(): void
    {
        $fiche = $this->client(404, json_encode([
            'erreur'    => 'aucune fiche ne porte ce nom : « Exempel »',
            'candidats' => [
                ['chemin' => 'wiki/clients/exemple.md', 'titre' => 'Exemple'],
                ['titre' => 'sans chemin, ignoré'],
            ],
        ]))->fiche('', 'Exempel');

        $this->assertFalse($fiche['trouvee']);
        $this->assertStringContainsString('Exempel', $fiche['message']);
        $this->assertSame([['chemin' => 'wiki/clients/exemple.md', 'titre' => 'Exemple']], $fiche['candidats']);
    }

    /** Un 404 SANS message de MyBrain, lui, dit qu'il n'y a pas d'API à cette adresse. */
    public function testUn404MuetResteUneAdresseInvalide(): void
    {
        try {
            $this->client(404, '<html>Not found</html>')->fiche('wiki/x.md');
            $this->fail('un 404 sans API aurait dû lever');
        } catch (MyBrainException $e) {
            $this->assertSame(MyBrainException::INVALID, $e->kind);
        }
    }

    public function testUneFicheNeGardeQueDesLiensHttp(): void
    {
        $this->assertSame('', MyBrainClient::parseFiche(['url' => 'javascript:alert(1)'])['url']);
        $this->assertSame('https://c.fr/cerveau?f=x', MyBrainClient::parseFiche(['url' => 'https://c.fr/cerveau?f=x'])['url']);
    }

    // =================== Nourrir (lot 3) ===================

    private const JOB = '0f8e2c4a-1b3d-4e5f-9a7b-6c5d4e3f2a1b';

    public function testLeDepotEnvoieLeTexteEtLaPersonne(): void
    {
        $depot = $this->client(202, json_encode([
            'job' => self::JOB, 'statut' => 'en-attente', 'suivi' => '/api/v1/nourrir/' . self::JOB,
            'pour' => 'alex@exemple.fr', 'avertissements' => [],
        ]))->nourrir(['titre' => 'Réunion', 'texte' => 'Le projet Alpha démarre.', 'lien' => '', 'pour' => 'alex@exemple.fr']);

        $this->assertSame(self::JOB, $depot['job']);
        $this->assertSame('alex@exemple.fr', $depot['pour']);
        $this->assertSame('POST', $this->calls[0]['method']);
        $this->assertSame('https://cerveau.exemple.fr/api/v1/nourrir', $this->calls[0]['url']);
        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame(['titre' => 'Réunion', 'texte' => 'Le projet Alpha démarre.', 'pour' => 'alex@exemple.fr'], $sent,
            'les champs vides ne partent pas');
    }

    public function testUnDepotSansIdentifiantValideEstUneReponseInvalide(): void
    {
        foreach ([[], ['job' => '../../admin'], ['job' => 'abc']] as $data) {
            try {
                MyBrainClient::parseDepot($data);
                $this->fail('identifiant accepté à tort : ' . json_encode($data));
            } catch (MyBrainException $e) {
                $this->assertSame(MyBrainException::INVALID, $e->kind);
            }
        }
    }

    /**
     * Le suivi part vers une adresse RECONSTRUITE depuis l'identifiant vérifié,
     * jamais vers le chemin que la réponse de MyBrain a proposé : la clé ne
     * suit aucune adresse dictée par une réponse.
     */
    public function testLeSuiviReconstruitSonAdresseEtRefuseUnIdentifiantForge(): void
    {
        $this->client(200, json_encode(['statut' => 'en-attente', 'position' => 2]))->suivi(strtoupper(self::JOB));
        $this->assertSame('https://cerveau.exemple.fr/api/v1/nourrir/' . self::JOB, $this->calls[0]['url']);

        $this->calls = [];
        try {
            $this->client(200, '{}')->suivi('../../../api/v1/fiche?chemin=x');
            $this->fail('identifiant forgé accepté');
        } catch (MyBrainException $e) {
            $this->assertSame(MyBrainException::REQUEST, $e->kind);
        }
        $this->assertSame([], $this->calls, 'aucun appel avec un identifiant forgé');
    }

    public function testLeSuiviNeConclutJamaisSurCeQuIlNeComprendPas(): void
    {
        $this->assertSame('en-cours', MyBrainClient::parseSuivi(['statut' => 'inconnu'])['statut']);
        $fait = MyBrainClient::parseSuivi([
            'statut'      => 'fait',
            'proposition' => ['id' => 'p1', 'url' => 'https://cerveau.exemple.fr/revue?id=p1'],
            'source'      => ['chemin' => 'wiki/sources/x.md', 'url' => 'javascript:alert(1)'],
            'pages'       => ['wiki/clients/exemple.md'],
        ]);
        $this->assertSame('https://cerveau.exemple.fr/revue?id=p1', $fait['proposition_url']);
        $this->assertSame('', $fait['source_url'], 'seuls les liens http(s) survivent');
        $this->assertSame(['wiki/clients/exemple.md'], $fait['pages']);
        $this->assertTrue(MyBrainClient::parseSuivi(['statut' => 'fait', 'zone_restreinte' => true])['zone_restreinte']);
    }

    public function testLeTransportReelNeSuitAucuneRedirection(): void
    {
        $source = (string)file_get_contents(ROOT . '/src/MyBrain/MyBrainClient.php');
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION   => false', $source);
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER   => true', $source);
        $this->assertStringContainsString("'Authorization: Bearer ' . \$this->apiKey", $source);
    }
}
