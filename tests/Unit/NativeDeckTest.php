<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Réaliser avec {modèle} » : le modèle de la conversation compose lui-même
 * la présentation PowerPoint ou le classeur Excel (NativeDeck), à partir de la
 * carte, de la demande, des fichiers joints à la conversation et — pour une
 * présentation — du modèle PowerPoint de l'entreprise ENTIER comme référence ;
 * le fichier est rangé 30 jours (NativeDeckStore) et suivi comme une
 * génération (NativeDeckJob).
 *
 * Les requêtes et la lecture des réponses sont éprouvées sans réseau, sur les
 * formes que documentent Anthropic (compétence « pptx », exécution de code,
 * Files API) et OpenAI (interpréteur de code, routes des conteneurs) — pages
 * relues le 2026-10-14. Le transport est remplacé : aucun appel ne part.
 */
final class NativeDeckTest extends TestCase
{
    private const PPTX = "PK\x03\x04....ppt/presentation.xml....";

    private string $dir = '';

    /** @var list<array{method: string, url: string, headers: array, body: mixed}> */
    private array $calls = [];

    protected function setUp(): void
    {
        I18n::use('fr');
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['company_name' => 'Acme', 'accent_color' => '#0055a4']);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
        CompanyProfile::overrideTemplate(sys_get_temp_dir() . '/aucun-modele-' . bin2hex(random_bytes(4)) . '.pptx');
        $this->dir = sys_get_temp_dir() . '/mia-native-' . bin2hex(random_bytes(6));
        NativeDeckStore::overrideDir($this->dir);
        NativeDeck::overrideUploadRoot($this->dir . '/uploads');
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        NativeDeck::overrideTransport(null);
        NativeDeck::overrideUploadRoot(null);
        NativeDeckStore::overrideDir(null);
        CompanyProfile::overrideTemplate(null);
        Settings::refresh();
        foreach (array_merge(glob($this->dir . '/uploads/*') ?: [], glob($this->dir . '/*') ?: []) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir . '/uploads');
        @rmdir($this->dir);
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: string}> $script méthode, motif d'URL, statut, corps */
    private function transport(array $script): void
    {
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$script): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            foreach ($script as $i => [$m, $pattern, $status, $response]) {
                if ($m === $method && preg_match($pattern, $url) === 1) {
                    unset($script[$i]);

                    return ['status' => $status, 'body' => $response];
                }
            }

            return ['status' => 200, 'body' => '{}'];
        });
    }

    // ------------------------------------------------------------ capacité

    public function testSeulsClaudeEtLaGenerationGptRecenteSaventLeFaire(): void
    {
        foreach (array_keys(LlmCatalog::models('anthropic')) as $model) {
            $this->assertTrue(NativeDeck::supports('anthropic::' . $model), "Claude $model");
        }
        foreach (['gpt-6.1-sol', 'gpt-6-luna', 'gpt-6-astra', 'gpt-5.6', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'] as $model) {
            $this->assertTrue(NativeDeck::supports('openai::' . $model), $model);
        }
        foreach (['gemini', 'mistral', 'deepseek', 'moonshot', 'zai', 'perplexity'] as $provider) {
            foreach (array_keys(LlmCatalog::models($provider)) as $model) {
                $this->assertFalse(NativeDeck::supports($provider . '::' . $model), "$provider::$model");
            }
        }
        $this->assertFalse(NativeDeck::supports('openai::o3'), 'Ancien modèle OpenAI : non listé pour l’interpréteur.');
        $this->assertFalse(NativeDeck::supports('local'));
    }

    // ------------------------------------------------------------- consigne

    public function testLaConsigneDitOuRangerLeFichierEtCommentUtiliserLeModele(): void
    {
        $avec = NativeDeck::prompt("# Offre\n\n## A\n\n- un", 'Offre', ['template' => true, 'filename' => 'offre.pptx', 'provider' => 'anthropic', 'company' => 'Acme']);
        $this->assertStringContainsString('« offre.pptx » dans le dossier $OUTPUT_DIR', $avec);
        $this->assertStringContainsString('modèle PowerPoint officiel de l\'entreprise est joint', $avec);
        $this->assertStringContainsString('slide_layouts', $avec);
        $this->assertStringContainsString("<<<\n# Offre", $avec);
        $this->assertStringContainsString('pour l\'entreprise Acme', $avec);

        $sans = NativeDeck::prompt('# T', 'T', ['template' => false, 'accent' => '#0055a4', 'filename' => 't.pptx', 'provider' => 'openai']);
        $this->assertStringContainsString('/mnt/data', $sans);
        $this->assertStringContainsString('#0055a4 comme couleur d\'accent', $sans);
        $this->assertStringNotContainsString('$OUTPUT_DIR', $sans);

        $this->assertSame('offre-mia.pptx', NativeDeck::filename('offre-mia.pptx'));
        $this->assertSame('budget 2027.pptx', NativeDeck::filename('../budget 2027.md'));
        $this->assertSame('presentation.pptx', NativeDeck::filename('<>.pptx'));
    }

    // ----------------------------------------------------------- Anthropic

    public function testRequeteAnthropicCompetencePptxEtModeleJoint(): void
    {
        $p = NativeDeck::anthropicPayload('claude-opus-5', 'Fais-la', 'file_tpl');
        $this->assertSame([['type' => 'anthropic', 'skill_id' => 'pptx', 'version' => 'latest']], $p['container']['skills']);
        $this->assertSame([['type' => NativeDeck::ANTHROPIC_CODE_TOOL, 'name' => 'code_execution']], $p['tools']);
        $this->assertSame(['type' => 'container_upload', 'file_id' => 'file_tpl'], $p['messages'][0]['content'][1]);
        $this->assertArrayNotHasKey('id', $p['container']);

        $suite = NativeDeck::anthropicPayload('claude-opus-5', 'Fais-la', null, [['role' => 'user', 'content' => 'x']], 'cntr_1');
        $this->assertSame('cntr_1', $suite['container']['id'], 'Reprise dans le même conteneur.');
        $this->assertSame([['role' => 'user', 'content' => 'x']], $suite['messages']);
    }

    public function testLesFichiersProduitsSontLusDansLesResultatsDOutils(): void
    {
        $response = ['content' => [
            ['type' => 'text', 'text' => 'Je commence.'],
            ['type' => 'server_tool_use', 'id' => 'srv1', 'name' => 'bash_code_execution', 'input' => ['command' => 'ls']],
            ['type' => 'bash_code_execution_tool_result', 'tool_use_id' => 'srv1', 'content' => [
                'type' => 'bash_code_execution_result', 'stdout' => 'offre.pptx', 'return_code' => 0,
                'content' => [['type' => 'bash_code_execution_output', 'file_id' => 'file_A']],
            ]],
            ['type' => 'bash_code_execution_tool_result', 'tool_use_id' => 'srv2', 'content' => [
                'type' => 'bash_code_execution_result', 'content' => [['file_id' => 'file_B'], ['file_id' => 'file_A']],
            ]],
        ], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 20]];

        $this->assertSame(['file_A', 'file_B'], NativeDeck::anthropicFileIds($response));
        $this->assertSame([], NativeDeck::anthropicFileIds(['content' => [
            ['type' => 'text', 'text' => 'rien'],
            ['type' => 'container_upload', 'file_id' => 'file_tpl'],
        ]]), 'Le fichier JOINT n\'est pas un fichier produit.');
        $this->assertSame(['input' => 150, 'output' => 50, 'input_cached' => 30, 'input_cache_write' => 20], NativeDeck::anthropicTokens($response));
    }

    /** Pause de l'API, reprise dans le même conteneur, dernier .pptx rapatrié, tout effacé chez Anthropic. */
    public function testRealisationParClaudeDeBoutEnBout(): void
    {
        $pause = json_encode(['stop_reason' => 'pause_turn', 'container' => ['id' => 'cntr_9'], 'content' => [
            ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_brouillon']]]],
        ], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 400]]);
        $fin = json_encode(['stop_reason' => 'end_turn', 'container' => ['id' => 'cntr_9'], 'content' => [
            ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_final']]]],
            ['type' => 'text', 'text' => 'Voici la présentation.'],
        ], 'usage' => ['input_tokens' => 2000, 'output_tokens' => 800]]);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, $pause],
            ['POST', '#/v1/messages$#', 200, $fin],
            ['GET', '#/v1/files/file_final$#', 200, json_encode(['id' => 'file_final', 'filename' => 'offre.pptx'])],
            ['GET', '#/v1/files/file_final/content$#', 200, self::PPTX],
        ]);

        $made = NativeDeck::make('anthropic::claude-opus-5', "# Offre\n\n## A\n\n- un", 'Offre', 'offre.pptx', null, 'sk-ant-test');

        $this->assertSame(self::PPTX, $made['bytes']);
        $this->assertSame('offre.pptx', $made['filename']);
        $this->assertSame(['input' => 3000, 'output' => 1200, 'input_cached' => 0, 'input_cache_write' => 0], $made['tokens']);

        $posts = array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'POST'));
        $this->assertCount(2, $posts);
        $second = json_decode((string)$posts[1]['body'], true);
        $this->assertSame('cntr_9', $second['container']['id']);
        $this->assertSame('assistant', $second['messages'][1]['role'], 'Le tour en pause est rendu tel quel.');
        $this->assertContains('x-api-key: sk-ant-test', $posts[0]['headers']);

        $deleted = array_column(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'DELETE'), 'url');
        $this->assertCount(2, $deleted, 'Brouillon et fichier final effacés chez Anthropic.');
    }

    public function testUnCreditEpuiseEstDitSansLeCorpsBrut(): void
    {
        $this->transport([
            ['POST', '#/v1/messages$#', 400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API. sk-ant-secret']])],
        ]);
        try {
            NativeDeck::make('anthropic::claude-opus-5', '# T', 'T', 't.pptx', null, 'sk-ant-test');
            $this->fail('Une exception était attendue.');
        } catch (NativeDeckException $e) {
            $this->assertStringNotContainsString('sk-ant-secret', $e->getMessage());
            $this->assertStringContainsString('Anthropic', $e->getMessage());
            $this->assertNotSame('', $e->title());
        }
    }

    public function testSansFichierPowerPointRienNEstInvente(): void
    {
        $this->transport([
            ['POST', '#/v1/messages$#', 200, json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Désolé.']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])],
        ]);
        try {
            NativeDeck::make('anthropic::claude-opus-5', '# T', 'T', 't.pptx', null, 'sk');
            $this->fail('Une exception était attendue.');
        } catch (NativeDeckException $e) {
            $this->assertStringContainsString('pas rendu de fichier PowerPoint', $e->getMessage());
            $this->assertSame(15, $e->tokens()['input'] + $e->tokens()['output'], 'Les jetons consommés restent facturés.');
        }
    }

    // -------------------------------------------------------------- OpenAI

    public function testRequeteEtLectureOpenAi(): void
    {
        $p = NativeDeck::openaiPayload('gpt-6.1-sol', 'Fais-la', 'file-tpl');
        $this->assertSame('code_interpreter', $p['tools'][0]['type']);
        $this->assertSame(['type' => 'auto', 'memory_limit' => '1g', 'file_ids' => ['file-tpl']], $p['tools'][0]['container']);

        $response = ['output' => [
            ['type' => 'code_interpreter_call', 'container_id' => 'cntr_x'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Voici.', 'annotations' => [
                ['type' => 'container_file_citation', 'container_id' => 'cntr_x', 'file_id' => 'cfile_1', 'filename' => 'offre.pptx'],
            ]]]],
        ], 'usage' => ['input_tokens' => 500, 'output_tokens' => 300,
            'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens_details' => ['reasoning_tokens' => 120]]];
        $this->assertSame([['container_id' => 'cntr_x', 'file_id' => 'cfile_1', 'filename' => 'offre.pptx']], NativeDeck::openaiCitations($response));
        $this->assertSame('cntr_x', NativeDeck::openaiContainerId($response));
        $this->assertSame(500, NativeDeck::openaiTokens($response)['input']);
        $this->assertSame(120, NativeDeck::openaiTokens($response)['output_reasoning']);
    }

    /** Aucune citation (elle n'est pas garantie) : le fichier est cherché dans le conteneur. */
    public function testRealisationParGptAvecRepliSurLeConteneur(): void
    {
        $this->transport([
            ['POST', '#/v1/responses$#', 200, json_encode(['status' => 'completed', 'output' => [
                ['type' => 'code_interpreter_call', 'container_id' => 'cntr_y'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fait.', 'annotations' => []]]],
            ], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]])],
            ['GET', '#/v1/containers/cntr_y/files$#', 200, json_encode(['data' => [
                ['id' => 'cfile_in', 'path' => '/mnt/data/modele-entreprise.pptx', 'source' => 'user'],
                ['id' => 'cfile_out', 'path' => '/mnt/data/offre.pptx', 'source' => 'assistant'],
            ]])],
            ['GET', '#/v1/containers/cntr_y/files/cfile_out/content$#', 200, self::PPTX],
        ]);

        $made = NativeDeck::make('openai::gpt-6.1-sol', '# Offre', 'Offre', 'offre.pptx', null, 'sk-test');
        $this->assertSame(self::PPTX, $made['bytes']);
        $this->assertContains('Authorization: Bearer sk-test', $this->calls[0]['headers']);
    }

    public function testUnFichierQuiNEstPasUnePresentationEstRefuse(): void
    {
        $this->assertTrue(NativeDeck::looksLikePptx(self::PPTX));
        $this->assertFalse(NativeDeck::looksLikePptx('<html>erreur</html>'));
        $this->transport([
            ['POST', '#/v1/responses$#', 200, json_encode(['output' => [['type' => 'message', 'content' => [['annotations' => [
                ['type' => 'container_file_citation', 'container_id' => 'c', 'file_id' => 'f', 'filename' => 'x.pptx'],
            ]]]]]])],
            ['GET', '#/content$#', 200, 'pas un pptx'],
        ]);
        $this->expectException(NativeDeckException::class);
        NativeDeck::make('openai::gpt-6.1-sol', '# T', 'T', 't.pptx', null, 'sk');
    }

    public function testUnModeleIncapableEstRefuseAvantToutAppel(): void
    {
        $this->transport([]);
        try {
            NativeDeck::make('gemini::gemini-3-flash-preview', '# T', 'T', 't.pptx', null, 'k');
            $this->fail('Une exception était attendue.');
        } catch (NativeDeckException $e) {
            $this->assertSame([], $this->calls);
        }
    }

    /**
     * Le modèle de l'entreprise part ENTIER, slides d'exemple comprises, comme
     * RÉFÉRENCE du style maison — en présentation (python-pptx refuse un
     * .potx) —, et la consigne dit combien de slides d'exemple il porte.
     */
    public function testLeModeleDEntrepriseEstEnvoyeEntierCommeReference(): void
    {
        require_once ROOT . '/tests/Support/PptxTemplateFixture.php';
        $tpl = sys_get_temp_dir() . '/mia-tpl-' . bin2hex(random_bytes(4)) . '.potx';
        PptxTemplateFixture::build($tpl, ['examples' => 2, 'potx' => true]);
        CompanyProfile::overrideTemplate($tpl);
        $sent   = [];
        $prompt = '';
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$sent, &$prompt): array {
            if ($method === 'POST' && str_ends_with($url, '/files')) {
                $sent[$body['file']->getPostFilename()] = (string)file_get_contents($body['file']->getFilename());

                return ['status' => 200, 'body' => json_encode(['id' => 'file_' . count($sent)])];
            }
            if ($method === 'POST') {
                $payload = json_decode((string)$body, true);
                $prompt  = (string)$payload['messages'][0]['content'][0]['text'];
                TestCase::assertSame(['type' => 'container_upload', 'file_id' => 'file_1'], $payload['messages'][0]['content'][1]);

                return ['status' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'content' => [], 'usage' => []])];
            }

            return ['status' => 200, 'body' => '{}'];
        });
        try {
            NativeDeck::make('anthropic::claude-opus-5', '# T', 'T', 't.pptx', null, 'k');
        } catch (NativeDeckException) {
            // Aucun fichier produit dans ce scénario : seule la pièce jointe compte ici.
        } finally {
            @unlink($tpl);
        }

        $this->assertArrayHasKey(NativeDeck::TEMPLATE_NAME, $sent);
        $path = sys_get_temp_dir() . '/mia-sent-' . bin2hex(random_bytes(4)) . '.pptx';
        file_put_contents($path, $sent[NativeDeck::TEMPLATE_NAME]);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $types    = (string)$zip->getFromName('[Content_Types].xml');
        $hasSlide = $zip->locateName('ppt/slides/slide1.xml') !== false && $zip->locateName('ppt/slides/slide2.xml') !== false;
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('presentation.main+xml', $types);
        $this->assertStringNotContainsString('template.main+xml', $types);
        $this->assertTrue($hasSlide, 'Les slides d\'exemple montrent le style maison : elles partent avec le modèle.');
        $this->assertStringContainsString('« ' . NativeDeck::TEMPLATE_NAME . ' »', $prompt);
        $this->assertStringContainsString('RÉFÉRENCE et ta base', $prompt);
        $this->assertStringContainsString('2 slides d\'exemple', $prompt);
        $this->assertStringContainsString('ne garde AUCUNE slide d\'exemple', $prompt);
    }

    /** PptxTemplate::reference : le modèle tel quel, seul son type principal change. */
    public function testLaReferenceGardeToutLeModele(): void
    {
        require_once ROOT . '/tests/Support/PptxTemplateFixture.php';
        $tpl = sys_get_temp_dir() . '/mia-ref-' . bin2hex(random_bytes(4)) . '.pptx';
        PptxTemplateFixture::build($tpl, ['examples' => 3]);
        $bytes = PptxTemplate::reference($tpl);
        $copy  = $tpl . '.out.pptx';
        file_put_contents($copy, $bytes);
        $info = PptxTemplate::analyze($copy);
        @unlink($tpl);
        @unlink($copy);
        $this->assertTrue($info['ok'], (string)($info['error'] ?? ''));
        $this->assertSame(3, $info['slides']);
    }

    // ---------------------------------------- ce que la personne a fourni

    /** La demande et les pièces jointes viennent de la conversation, AVANT la carte. */
    public function testLaDemandeEtLesPiecesJointesViennentDeLaConversation(): void
    {
        $file = static fn (string $name, string $type, string $extracted = 'x', string $path = ''): array =>
            ['name' => $name, 'type' => $type, 'extracted' => $extracted, 'path' => $path];
        $history = [
            ['id' => 1, 'role' => 'user', 'content' => 'Premier message', 'files_json' => json_encode([$file('ancien.pdf', 'application/pdf')])],
            ['id' => 2, 'role' => 'assistant', 'content' => 'Réponse', 'files_json' => json_encode([$file('genere.png', 'image/png')])],
            ['id' => 3, 'role' => 'user', 'content' => 'Deuxième', 'files_json' => json_encode([
                $file('photo.jpg', 'image/jpeg'), $file('presentation.json', 'slide_deck'), $file('vide.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ''),
            ])],
            ['id' => 4, 'role' => 'user', 'content' => 'Troisième, avec nos photos', 'files_json' => json_encode([$file('photo.jpg', 'image/jpeg', 'y')])],
            ['id' => 5, 'role' => 'user', 'content' => 'Quatrième', 'files_json' => null],
            ['id' => 6, 'role' => 'assistant', 'content' => "```markdown fichier=\"offre.pptx\"\n# Offre\n```", 'files_json' => null],
            ['id' => 7, 'role' => 'user', 'content' => 'Après la carte', 'files_json' => json_encode([$file('apres.png', 'image/png')])],
        ];

        $ctx = NativeDeck::contextFrom($history, 6);
        $this->assertSame("Deuxième\n---\nTroisième, avec nos photos\n---\nQuatrième", $ctx['request'],
            'Les trois derniers messages de la personne avant la carte, le plus récent en dernier.');
        $this->assertSame(['photo.jpg', 'ancien.pdf'], array_column($ctx['attachments'], 'name'),
            'Les plus récentes d’abord, dédoublonnées par nom ; ni le fichier d’une réponse, ni un marqueur de création, ni un fichier vide.');
        $this->assertSame('y', $ctx['attachments'][0]['extracted'], 'La version la plus récente d’un fichier joint deux fois.');

        $all = NativeDeck::contextFrom($history);
        $this->assertContains('apres.png', array_column($all['attachments'], 'name'), 'Sans message de carte : toute la conversation.');

        $long = NativeDeck::contextFrom([['id' => 1, 'role' => 'user', 'content' => str_repeat('a', 5000)]]);
        $this->assertLessThanOrEqual(NativeDeck::REQUEST_MESSAGE_CHARS + 4, mb_strlen($long['request']));

        $many = [];
        for ($i = 1; $i <= 12; $i++) {
            $many[] = ['id' => $i, 'role' => 'user', 'content' => '', 'files_json' => json_encode([$file('f' . $i . '.png', 'image/png')])];
        }
        $this->assertCount(NativeDeck::MAX_ATTACHMENTS, NativeDeck::contextFrom($many)['attachments']);
    }

    /**
     * Octets des pièces jointes : l'original sur le disque, confiné au dossier
     * des envois ; à défaut l'image gardée en base64 ; à défaut le texte extrait.
     */
    public function testLesPiecesJointesSontLuesSurLeDisqueOuDansLeMessage(): void
    {
        @mkdir($this->dir . '/uploads', 0775, true);
        file_put_contents($this->dir . '/uploads/abc.xlsx', 'PK-classeur');
        $outside = sys_get_temp_dir() . '/mia-hors-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($outside, 'secret');

        $files = NativeDeck::attachmentFiles([
            ['name' => 'chiffres 2026.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extracted' => 'texte', 'path' => $this->dir . '/uploads/abc.xlsx'],
            ['name' => 'photo.png', 'type' => 'image/png', 'extracted' => base64_encode('PNGDATA'), 'path' => $outside],
            ['name' => 'note.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extracted' => 'Texte du document', 'path' => '/nulle/part.docx'],
            ['name' => 'perdu.png', 'type' => 'image/png', 'extracted' => '', 'path' => ''],
            ['name' => NativeDeck::TEMPLATE_NAME, 'type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'extracted' => 'x', 'path' => ''],
            ['name' => 'logo-entreprise.png', 'type' => 'image/png', 'extracted' => base64_encode('L'), 'path' => ''],
            ['name' => 'ventes.csv', 'type' => 'text/plain', 'extracted' => "Mois;Total\nJanv;10", 'path' => ''],
        ]);
        @unlink($outside);

        $byName = array_column($files, null, 'name');
        $this->assertSame('PK-classeur', $byName['chiffres 2026.xlsx']['bytes'] ?? null, 'L’original, tel quel.');
        $this->assertSame('classeur Excel', $byName['chiffres 2026.xlsx']['label']);
        $this->assertSame('PNGDATA', $byName['photo.png']['bytes'] ?? null, 'Un chemin hors du dossier des envois n’est jamais lu : l’image gardée dans le message sert.');
        $this->assertSame('Texte du document', $byName['note.txt']['bytes'] ?? null, 'Original disparu : le texte extrait, en .txt.');
        $this->assertSame('text/plain', $byName['note.txt']['mime']);
        $this->assertArrayNotHasKey('perdu.png', $byName, 'Rien à envoyer : écarté.');
        $this->assertArrayNotHasKey(NativeDeck::TEMPLATE_NAME, $byName, 'Le nom du modèle d’entreprise est réservé.');
        $this->assertArrayNotHasKey('logo-entreprise.png', $byName, 'Le nom du logo aussi.');
        $this->assertSame("Mois;Total\nJanv;10", $byName['ventes.csv']['bytes'] ?? null, 'Un CSV typé text/plain garde son extension.');
        $this->assertSame(['text/csv', 'tableau CSV'], [$byName['ventes.csv']['mime'], $byName['ventes.csv']['label']]);
        $this->assertSame(6, count($files));
    }

    /** Pièces jointes et demande partent au modèle : déposées, citées dans la consigne comme DONNÉES. */
    public function testLesFichiersJointsEtLaDemandePartentChezClaude(): void
    {
        $uploads = [];
        $payload = null;
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$uploads, &$payload): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if ($method === 'POST' && str_ends_with($url, '/files')) {
                $uploads[] = [$body['file']->getPostFilename(), $body['file']->getMimeType()];

                return ['status' => 200, 'body' => json_encode(['id' => 'file_in' . count($uploads)])];
            }
            if ($method === 'POST') {
                $payload = json_decode((string)$body, true);

                return ['status' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'content' => [
                    ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_in1'], ['file_id' => 'file_out']]]],
                ], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]])];
            }
            if ($method === 'GET' && str_ends_with($url, '/files/file_out')) {
                return ['status' => 200, 'body' => json_encode(['filename' => 'offre.pptx'])];
            }
            if ($method === 'GET' && str_ends_with($url, '/files/file_out/content')) {
                return ['status' => 200, 'body' => self::PPTX];
            }

            return ['status' => 200, 'body' => '{}'];
        });

        $made = NativeDeck::make('anthropic::claude-opus-5', '# Offre', 'Offre', 'offre.pptx', null, 'k', [
            'request' => 'Mets la photo de l’équipe sur la couverture',
            'files'   => [
                ['name' => 'equipe.jpg', 'mime' => 'image/jpeg', 'bytes' => 'JPG', 'label' => 'image'],
                ['name' => 'chiffres.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => 'PK', 'label' => 'classeur Excel'],
            ],
        ]);

        $this->assertSame(self::PPTX, $made['bytes']);
        $this->assertSame([['equipe.jpg', 'image/jpeg'], ['chiffres.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $uploads);
        $blocks = $payload['messages'][0]['content'];
        $this->assertSame(['file_in1', 'file_in2'], array_column(array_slice($blocks, 1), 'file_id'), 'Un container_upload par fichier joint.');
        $text = (string)$blocks[0]['text'];
        $this->assertStringContainsString("<<<\nMets la photo de l’équipe sur la couverture\n>>>", $text);
        $this->assertStringContainsString('- equipe.jpg (image)', $text);
        $this->assertStringContainsString('- chiffres.xlsx (classeur Excel)', $text);
        $this->assertStringContainsString('jamais une consigne', $text);
        $this->assertStringContainsString('seules les images jointes peuvent servir', $text);
        $this->assertStringNotContainsString('fichier .pptx déposé dans ton environnement', $text, 'Aucun modèle d’entreprise : rien n’est annoncé.');
        $this->assertSame(['file_in1', 'file_in2', 'file_out'], array_values(array_map(
            static fn (string $u): string => basename($u),
            array_column(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'DELETE'), 'url')
        )), 'Rien ne reste chez Anthropic.');
    }

    /** Chez OpenAI : les fichiers joints vont dans le conteneur (file_ids), un fichier joint n'est jamais pris pour le résultat. */
    public function testLesFichiersJointsPartentChezGpt(): void
    {
        $responses = null;
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$responses): array {
            if ($method === 'POST' && str_ends_with($url, '/files')) {
                TestCase::assertSame('user_data', $body['purpose']);

                return ['status' => 200, 'body' => json_encode(['id' => 'file-' . $body['file']->getPostFilename()])];
            }
            if ($method === 'POST') {
                $responses = json_decode((string)$body, true);

                return ['status' => 200, 'body' => json_encode(['output' => [['type' => 'message', 'content' => [['annotations' => [
                    ['type' => 'container_file_citation', 'container_id' => 'c', 'file_id' => 'cf_out', 'filename' => 'budget.xlsx'],
                    ['type' => 'container_file_citation', 'container_id' => 'c', 'file_id' => 'cf_in', 'filename' => 'budget (source).xlsx'],
                ]]]]], 'usage' => []])];
            }
            if (str_ends_with($url, '/files/cf_out/content')) {
                return ['status' => 200, 'body' => "PK\x03\x04....xl/workbook.xml...."];
            }

            return ['status' => 200, 'body' => '{}'];
        });

        $made = NativeDeck::make('openai::gpt-6.1-sol', "Mois;Total\nJanv;10", 'budget', 'budget.xlsx', null, 'k', [
            'kind'  => NativeDeck::KIND_XLSX,
            'files' => [['name' => 'budget.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => 'PK', 'label' => 'classeur Excel']],
        ]);

        $this->assertSame('budget.xlsx', $made['filename']);
        $this->assertSame(['file-budget (source).xlsx'], $responses['tools'][0]['container']['file_ids'],
            'Un fichier joint qui porte le nom demandé est renommé : le résultat ne se confond pas avec lui.');
        $this->assertStringContainsString('- budget (source).xlsx (classeur Excel)', (string)$responses['input']);
    }

    /** Un classeur : compétence « xlsx », consigne de tableur, ni modèle PowerPoint ni logo. */
    public function testRealisationDUnClasseurParClaude(): void
    {
        require_once ROOT . '/tests/Support/PptxTemplateFixture.php';
        $tpl = sys_get_temp_dir() . '/mia-tpl-' . bin2hex(random_bytes(4)) . '.pptx';
        PptxTemplateFixture::build($tpl, ['examples' => 1]);
        CompanyProfile::overrideTemplate($tpl);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, json_encode(['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_x']]]],
            ], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]])],
            ['GET', '#/v1/files/file_x$#', 200, json_encode(['filename' => 'ventes.xlsx'])],
            ['GET', '#/v1/files/file_x/content$#', 200, "PK\x03\x04....xl/workbook.xml...."],
        ]);
        try {
            $made = NativeDeck::make('anthropic::claude-opus-5', "Mois;Ventes\nJanv;12", 'ventes', 'ventes.csv', null, 'k', ['kind' => NativeDeck::KIND_XLSX]);
        } finally {
            @unlink($tpl);
        }

        $this->assertSame('ventes.xlsx', $made['filename']);
        $this->assertSame([], array_filter($this->calls, static fn (array $c): bool => str_ends_with($c['url'], '/files') && $c['method'] === 'POST'),
            'Aucun modèle PowerPoint pour un classeur.');
        $post = json_decode((string)array_values(array_filter($this->calls, static fn (array $c): bool => str_ends_with($c['url'], '/messages')))[0]['body'], true);
        $this->assertSame('xlsx', $post['container']['skills'][0]['skill_id']);
        $text = (string)$post['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('classeur Excel (.xlsx)', $text);
        $this->assertStringContainsString('formules Excel', $text);
        $this->assertStringContainsString('sur fond #0055a4', $text);
        $this->assertStringContainsString('« ventes.xlsx »', $text);
        $this->assertStringNotContainsString('slide_layouts', $text);

        $this->expectException(NativeDeckException::class);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, json_encode(['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_p']]]],
            ], 'usage' => []])],
            ['GET', '#/v1/files/file_p$#', 200, json_encode(['filename' => 'ventes.pptx'])],
        ]);
        NativeDeck::make('anthropic::claude-opus-5', "a;b\n1;2", 'v', 'v.xlsx', null, 'k', ['kind' => NativeDeck::KIND_XLSX]);
    }

    /** Choix du fichier rendu : le nom demandé, sinon le dernier du genre, jamais un fichier déposé par MIA. */
    public function testLeFichierRenduEstCeluiDuNomDemande(): void
    {
        $c = [
            ['file_id' => 'a', 'filename' => '/mnt/data/brouillon.pptx'],
            ['file_id' => 'b', 'filename' => '/mnt/data/offre.pptx'],
            ['file_id' => 'c', 'filename' => '/mnt/data/modele-entreprise.pptx'],
            ['file_id' => 'd', 'filename' => '/mnt/data/notes.txt'],
        ];
        $this->assertSame('b', NativeDeck::pickProduced($c, 'offre.pptx', [NativeDeck::TEMPLATE_NAME], 'pptx')['file_id']);
        $this->assertSame('a', NativeDeck::pickProduced($c, 'autre.pptx', [NativeDeck::TEMPLATE_NAME, 'offre.pptx'], 'pptx')['file_id']);
        $this->assertNull(NativeDeck::pickProduced($c, 'x.xlsx', [], 'xlsx'));
        $this->assertTrue(NativeDeck::looksLike("PK\x03\x04 xl/workbook.xml", 'xlsx'));
        $this->assertFalse(NativeDeck::looksLike("PK\x03\x04 ppt/presentation.xml", 'xlsx'));
        $this->assertSame('ventes 2026.xlsx', NativeDeck::filename('ventes 2026.csv', 'xlsx'));
        $this->assertSame('classeur.xlsx', NativeDeck::filename('<>.csv', 'xlsx'));
    }

    /** Quelles cartes proposent « Réaliser avec … », et pour quel genre : même règle des deux côtés. */
    public function testLaCarteEtLeServeurSAccordentSurLeGenre(): void
    {
        $cas = [
            ['plan.pptx', 'markdown', 'pptx'],
            ['plan.pptx', '', 'pptx'],
            ['plan.key', 'markdown', 'pptx'],
            ['plan.pptx', 'text', ''],
            ['rapport.docx', 'markdown', ''],
            ['notes.md', 'markdown', ''],
            ['budget.xlsx', 'csv', 'xlsx'],
            ['budget.xlsx', '', 'xlsx'],
            ['budget.csv', 'csv', 'xlsx'],
            ['donnees.tsv', 'tsv', 'xlsx'],
            ['data.json', 'json', ''],
        ];
        foreach ($cas as [$nom, $langage, $genre]) {
            $this->assertSame($genre === '' ? null : $genre, NativeDeck::kindFor(FileFactory::resolveExtension($nom, $langage), $langage), "$nom ($langage)");
        }

        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : la moitié navigateur tourne sur le poste de développement et en CI.');
        }
        $script = "'use strict';\n"
            . 'globalThis.FILE_FORMATS = ' . json_encode(FileFormats::clientCatalog()) . ";\n"
            . "globalThis.t = (s) => s;\nglobalThis.tn = (n, un, plusieurs) => (n > 1 ? plusieurs : un);\n"
            . (string)file_get_contents(ROOT . '/public/assets/js/generated-files.js') . "\n"
            . 'const CAS = ' . json_encode($cas) . ";\n"
            . "console.log(JSON.stringify(CAS.map(c => globalThis.MiaFiles.nativeKind(globalThis.MiaFiles.describe(c[0], c[1])))));\n";
        $fichier = tempnam(sys_get_temp_dir(), 'mia-native-js-');
        file_put_contents($fichier, $script);
        try {
            $sortie = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $this->assertSame(array_column($cas, 2), json_decode((string)$sortie, true), (string)$sortie);
    }

    // ------------------------------------------- « Version soignée » : choix

    /**
     * Le modèle proposé d'office : celui de la conversation s'il sait faire
     * (même haut de gamme — c'est un choix), sinon le défaut de
     * l'organisation, sinon le modèle standard d'un éditeur capable, jamais un
     * haut de gamme d'office ; seuls des hauts de gamme : la personne choisit.
     */
    public function testLeModeleEstChoisiDOfficeSansJamaisPrendreUnHautDeGamme(): void
    {
        $defaults = ['anthropic' => 'claude-opus-5', 'openai' => 'gpt-5.6'];
        $all      = ['anthropic::claude-fable-5-1', 'anthropic::claude-opus-5', 'openai::gpt-6-luna', 'openai::gpt-5.6'];
        $premium  = ['anthropic::claude-fable-5-1'];

        $this->assertSame('anthropic::claude-fable-5-1', NativeDeck::pickModel('anthropic::claude-fable-5-1', $all, 'openai::gpt-5.6', $defaults, $premium),
            'Le modèle de la conversation sait faire : il est gardé, même haut de gamme.');
        $this->assertSame('openai::gpt-5.6', NativeDeck::pickModel('mistral::mistral-large-latest', $all, 'openai::gpt-5.6', $defaults, $premium),
            'Sinon le défaut de l’organisation.');
        $this->assertSame('anthropic::claude-opus-5', NativeDeck::pickModel('mistral::mistral-large-latest', $all, 'anthropic::claude-fable-5-1', $defaults, $premium),
            'Un défaut d’organisation haut de gamme n’est pas pris d’office : le modèle standard d’Anthropic.');
        $this->assertSame('anthropic::claude-opus-5', NativeDeck::pickModel('', $all, 'mistral::mistral-large-latest', $defaults, $premium));
        $this->assertSame('openai::gpt-6-luna', NativeDeck::pickModel('', ['anthropic::claude-fable-5-1', 'openai::gpt-6-luna'], '', $defaults, $premium),
            'Ni défaut ni modèle standard permis : le premier qui n’est pas haut de gamme.');
        $this->assertNull(NativeDeck::pickModel('mistral::mistral-large-latest', ['anthropic::claude-fable-5-1'], '', $defaults, $premium),
            'Seuls des hauts de gamme : rien d’office, la personne choisit.');
        $this->assertNull(NativeDeck::pickModel('', [], 'openai::gpt-5.6', $defaults, []));
    }

    /** Les candidats : le modèle de la conversation s'il sait faire (même hors de la liste du groupe), puis les modèles permis capables. */
    public function testLesCandidatsSontLesModelesPermisQuiSaventFaire(): void
    {
        $supports = static fn (string $slug): bool => str_starts_with($slug, 'anthropic::') || str_starts_with($slug, 'openai::');
        $allowed  = ['mistral::mistral-large-latest' => 'Mistral', 'openai::gpt-6-luna' => 'GPT-6 Luna', 'anthropic::claude-opus-5' => 'Opus 5'];

        $this->assertSame(['openai::gpt-6-luna', 'anthropic::claude-opus-5'], NativeDeck::candidates('mistral::mistral-large-latest', $allowed, $supports));
        $this->assertSame(['anthropic::claude-opus-5', 'openai::gpt-6-luna'], NativeDeck::candidates('anthropic::claude-opus-5', $allowed, $supports),
            'Le modèle de la conversation d’abord, sans doublon.');
        $this->assertSame(['anthropic::claude-sonnet-5-5', 'openai::gpt-6-luna', 'anthropic::claude-opus-5'],
            NativeDeck::candidates('anthropic::claude-sonnet-5-5', $allowed, $supports), 'Imposé par un assistant hors de la liste du groupe : proposé aussi.');
        $this->assertSame([], NativeDeck::candidates('', ['mistral::mistral-large-latest' => 'Mistral'], $supports));
    }

    /** Haut de gamme = sortie au-delà de 1,5 fois celle du modèle par défaut de l'éditeur (tarifs normaux du catalogue). */
    public function testLesModelesHautDeGammeSontReperesAuTarif(): void
    {
        foreach (['anthropic::claude-fable-5-1', 'anthropic::claude-fable-5', 'openai::gpt-6-astra'] as $slug) {
            $this->assertTrue(NativeDeck::isPremium($slug), $slug);
        }
        foreach (['anthropic::claude-opus-5', 'anthropic::claude-opus-5-5', 'anthropic::claude-sonnet-5-5', 'openai::gpt-5.6', 'openai::gpt-6-luna', 'openai::gpt-6.1-sol', 'anthropic'] as $slug) {
            $this->assertFalse(NativeDeck::isPremium($slug), $slug);
        }
        $this->assertSame('Anthropic', NativeDeck::providerLabel('anthropic::claude-opus-5'));
        $this->assertSame('OpenAI', NativeDeck::providerLabel('openai::gpt-6-luna'));
    }

    /** Le coût annoncé : la moyenne des dernières réalisations de la personne, sinon le tarif du modèle, en crédits ronds. */
    public function testLEstimationSuitLHistoriqueSinonLeTarif(): void
    {
        $this->assertSame(['credits' => 800, 'basis' => 'history', 'count' => 3], NativeDeck::estimate([810, 790, 812], 520));
        $this->assertSame(['credits' => 520, 'basis' => 'tariff', 'count' => 0], NativeDeck::estimate([], 523));
        $this->assertSame(['credits' => 30, 'basis' => 'tariff', 'count' => 0], NativeDeck::estimate([0, -4], 31), 'Une réalisation à 0 crédit ne compte pas.');
        $this->assertSame(['credits' => null, 'basis' => 'none', 'count' => 0], NativeDeck::estimate([], null));

        $this->assertSame([0, 7, 25, 800, 1200, 1], [
            NativeDeck::roundCredits(0), NativeDeck::roundCredits(7), NativeDeck::roundCredits(23),
            NativeDeck::roundCredits(803), NativeDeck::roundCredits(1234), NativeDeck::roundCredits(1),
        ]);

        // Au tarif du catalogue : une présentation coûte plus qu'un classeur, et Claude Opus plus que GPT-6 Luna.
        $opus = (int)NativeDeck::tariffCredits('anthropic::claude-opus-5', 'pptx');
        $this->assertGreaterThan(0, (int)NativeDeck::tariffCredits('openai::gpt-6-luna', 'pptx'));
        $this->assertGreaterThan((int)NativeDeck::tariffCredits('anthropic::claude-opus-5', 'xlsx'), $opus);
        $this->assertGreaterThan((int)NativeDeck::tariffCredits('openai::gpt-6-luna', 'pptx'), $opus);
        $this->assertNull(NativeDeck::tariffCredits('mistral::mistral-large-latest', 'pptx'), 'Éditeur incapable : aucune estimation.');
    }

    /** La précision de mise en page : une ligne, bornée, et dite au modèle comme une demande de l'utilisateur. */
    public function testLaPrecisionDeMiseEnPageEstBorneeEtTransmise(): void
    {
        $this->assertSame('style sobre, un chiffre clé', NativeDeck::cleanNote("  style sobre,\n\tun   chiffre clé\x07 "));
        $this->assertSame(NativeDeck::MAX_NOTE_CHARS, mb_strlen(NativeDeck::cleanNote(str_repeat('é', 400))));

        $avec = NativeDeck::prompt('# T', 'T', ['filename' => 't.pptx', 'provider' => 'openai', 'note' => "fond sombre\n"]);
        $this->assertStringContainsString("Précision de l'utilisateur pour la mise en page, au moment de lancer — respecte-la :\n<<<\nfond sombre\n>>>", $avec);
        $sans = NativeDeck::prompt('# T', 'T', ['filename' => 't.pptx', 'provider' => 'openai', 'note' => '   ']);
        $this->assertStringNotContainsString('Précision de l', $sans);
    }

    /** Ce que la fenêtre montre : la demande la plus récente, les pièces jointes avec leur poids, celles jointes plus tôt. */
    public function testLaFenetreMontreCeQuiPart(): void
    {
        @mkdir($this->dir . '/uploads', 0775, true);
        file_put_contents($this->dir . '/uploads/ventes.csv', str_repeat('x', 4100));
        $file = static fn (string $name, string $type, string $extracted = 'x', string $path = ''): array =>
            ['name' => $name, 'type' => $type, 'extracted' => $extracted, 'path' => $path];
        $history = [
            ['id' => 1, 'role' => 'user', 'content' => 'Premier', 'files_json' => json_encode([$file('devis.pdf', 'application/pdf', base64_encode(str_repeat('p', 3000)))])],
            ['id' => 2, 'role' => 'user', 'content' => 'Deuxième', 'files_json' => json_encode([$file('photo.png', 'image/png', base64_encode(str_repeat('i', 1200)))])],
            ['id' => 3, 'role' => 'user', 'content' => 'Troisième', 'files_json' => json_encode([$file('ventes.csv', 'text/plain', 'a;b', $this->dir . '/uploads/ventes.csv')])],
            ['id' => 4, 'role' => 'user', 'content' => 'Fais la présentation', 'files_json' => null],
            ['id' => 5, 'role' => 'assistant', 'content' => 'carte', 'files_json' => null],
        ];

        $ctx = NativeDeck::contextFrom($history, 5);
        $this->assertSame('Fais la présentation', $ctx['last']);
        $this->assertSame(2, $ctx['earlier'], 'Les deux messages d’avant dans la demande.');
        $this->assertSame(['ventes.csv' => false, 'photo.png' => false, 'devis.pdf' => true], array_column($ctx['attachments'], 'older', 'name'),
            'Jointe à un message plus ancien que la demande : signalée.');

        $summary = array_column(NativeDeck::attachmentSummary($ctx['attachments']), null, 'name');
        $this->assertSame(['name' => 'ventes.csv', 'label' => 'Tableau', 'size' => 4100, 'older' => false], $summary['ventes.csv'], 'Le poids du fichier d’origine.');
        $this->assertSame(['name' => 'photo.png', 'label' => 'Image', 'size' => 1200, 'older' => false], $summary['photo.png'], 'Une image gardée dans le message : son poids décodé.');
        $this->assertSame('PDF', $summary['devis.pdf']['label']);
        $this->assertTrue($summary['devis.pdf']['older']);

        $kept = NativeDeck::withoutExcluded($ctx['attachments'], ['DEVIS.pdf', '', 'absent.png']);
        $this->assertSame(['ventes.csv', 'photo.png'], array_column($kept, 'name'), 'Décochée dans la fenêtre : elle ne part pas (nom comparé sans casse).');

        $this->assertSame('4 Ko', Format::sizeShort(4100));
        $this->assertSame('1 Ko', Format::sizeShort(12));
        $this->assertSame('', Format::sizeShort(0));
        $this->assertSame('2,4 Mo', Format::sizeShort((int)(2.4 * 1024 * 1024)));
    }

    /** Modèle d'entreprise décoché : ni le modèle ni le logo ne partent, la consigne le sait. */
    public function testLeModeleDEntrepriseDecocheNePartPas(): void
    {
        require_once ROOT . '/tests/Support/PptxTemplateFixture.php';
        $tpl = sys_get_temp_dir() . '/mia-tpl-' . bin2hex(random_bytes(4)) . '.pptx';
        PptxTemplateFixture::build($tpl, ['examples' => 2]);
        CompanyProfile::overrideTemplate($tpl);
        $this->assertSame(['name' => 'Acme', 'template' => true, 'examples' => 2, 'logo' => false], NativeDeck::companySummary('pptx'));
        $this->assertNull(NativeDeck::companySummary('xlsx'), 'Un classeur ne part jamais avec le modèle PowerPoint.');

        $sent   = [];
        $prompt = '';
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$sent, &$prompt): array {
            if ($method === 'POST' && str_ends_with($url, '/files')) {
                $sent[] = $body['file']->getPostFilename();

                return ['status' => 200, 'body' => json_encode(['id' => 'file_' . count($sent)])];
            }
            if ($method === 'POST') {
                $prompt = (string)json_decode((string)$body, true)['input'];

                return ['status' => 200, 'body' => json_encode(['output' => [], 'usage' => []])];
            }

            return ['status' => 200, 'body' => '{}'];
        });
        try {
            NativeDeck::make('openai::gpt-6-luna', '# T', 'T', 't.pptx', null, 'k', [
                'company' => false,
                'note'    => 'un chiffre clé par slide',
                'files'   => [['name' => 'photo.jpg', 'mime' => 'image/jpeg', 'bytes' => 'JPG', 'label' => 'image']],
            ]);
        } catch (NativeDeckException) {
            // Aucun fichier produit dans ce scénario : seuls les envois comptent ici.
        } finally {
            @unlink($tpl);
        }

        $this->assertSame(['photo.jpg'], $sent, 'Ni « ' . NativeDeck::TEMPLATE_NAME . ' » ni logo.');
        $this->assertStringContainsString('Aucun modèle d\'entreprise', $prompt);
        $this->assertStringContainsString("<<<\nun chiffre clé par slide\n>>>", $prompt);
    }

    // ------------------------------------------------------------- stockage

    public function testLeFichierEstGardeTrenteJoursPourSonCompteEtSaConversation(): void
    {
        $token = NativeDeckStore::save(self::PPTX, 'offre.pptx', 7, 42, 'anthropic::claude-opus-5');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame('/chat/42/presentation/' . $token, NativeDeckStore::url(42, $token));

        $file = NativeDeckStore::find($token, 7, 42);
        $this->assertSame('offre.pptx', $file['name'] ?? null);
        $this->assertSame(self::PPTX, file_get_contents((string)$file['path']));
        $this->assertNull(NativeDeckStore::find($token, 8, 42), 'Autre compte.');
        $this->assertNull(NativeDeckStore::find($token, 7, 43), 'Autre conversation.');
        $this->assertNull(NativeDeckStore::find('../../etc/passwd', 7, 42));

        $old = time() - (NativeDeckStore::RETENTION_DAYS + 1) * 86400;
        touch($this->dir . '/' . $token . '.pptx', $old);
        // touch() ne vide pas le cache de stat de PHP : sans cela, filemtime()
        // rend la date lue par le find() d'au-dessus et le fichier passe pour frais.
        clearstatcache();
        $this->assertNull(NativeDeckStore::find($token, 7, 42), 'Échu : plus servi.');
        $fresh = NativeDeckStore::save(self::PPTX, 'b.pptx', 7, 42, 'x');
        $this->assertSame(1, NativeDeckStore::purgeExpired());
        $this->assertFileDoesNotExist($this->dir . '/' . $token . '.pptx');
        $this->assertFileDoesNotExist($this->dir . '/' . $token . '.json');
        $this->assertNotNull(NativeDeckStore::find($fresh, 7, 42));
        $this->assertTrue(NativeDeckStore::expired(1, 1 + 31 * 86400));
        $this->assertFalse(NativeDeckStore::expired(1, 1 + 29 * 86400));
        $this->assertSame(NativeDeckStore::TYPES['pptx'], NativeDeckStore::find($fresh, 7, 42)['mime']);

        // Un classeur : rangé en .xlsx, servi comme tel, purgé pareil.
        $sheet = NativeDeckStore::save('PK-xlsx', 'ventes.xlsx', 7, 42, 'openai::gpt-6.1-sol');
        $this->assertFileExists($this->dir . '/' . $sheet . '.xlsx');
        $this->assertSame(['path' => $this->dir . '/' . $sheet . '.xlsx', 'name' => 'ventes.xlsx', 'mime' => NativeDeckStore::TYPES['xlsx']],
            NativeDeckStore::find($sheet, 7, 42));
        touch($this->dir . '/' . $sheet . '.xlsx', $old);
        $this->assertSame(1, NativeDeckStore::purgeExpired());
        $this->assertFileDoesNotExist($this->dir . '/' . $sheet . '.json');
    }

    // --------------------------------------------------------------- câblage

    public function testCablage(): void
    {
        $src = static fn (string $f): string => (string)file_get_contents(ROOT . '/' . $f);

        $index = $src('public/index.php');
        $this->assertStringContainsString("\$router->post('/chat/{id}/native-deck',", $index);
        $this->assertStringContainsString("\$router->post('/chat/{id}/native-deck/plan', [ChatController::class, 'nativeDeckPlan']);", $index);
        $this->assertStringContainsString("\$router->get('/chat/{id}/presentation/{token}', [ChatController::class, 'downloadNativeDeck']);", $index);

        $chat = $src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('$this->scheduledTurnLlm($user, $profile, $hint)', $chat, 'Le modèle de la conversation est résolu comme pour un message.');
        $this->assertSame(2, substr_count($chat, '$this->nativeDeckChoice($user, $profile ?: null'), 'La fenêtre et le lancement font le même choix.');
        $this->assertStringContainsString("in_array(\$llm, \$choice['candidates'], true)", $chat, 'Le modèle demandé doit être l’un de ceux proposés.');
        $this->assertStringContainsString('NativeDeck::pickModel(', $chat);
        $this->assertSame(2, substr_count($chat, '$nativeForFiles = $this->nativeDeckAvailable($user);'), 'index() et show() posent le drapeau du bouton.');
        $this->assertStringContainsString("window.FILE_NATIVE = <?= !empty(\$nativeForFiles) ? 'true' : 'false' ?>;", $src('templates/chat/index.php'));
        $this->assertStringContainsString("JobQueue::enqueue('native_deck'", $chat);
        $this->assertStringContainsString('NativeDeckStore::find($token, (int)$user[\'id\'], $convId)', $chat);

        $queue = $src('src/Jobs/JobQueue.php');
        $this->assertStringContainsString("'native_deck'  => new NativeDeckJob(),", $queue);
        $this->assertMatchesRegularExpression("/NON_IDEMPOTENT = \\[[^\\]]*'native_deck'/", $queue, 'Un rejeu refacturerait.');
        $this->assertStringNotContainsString("'native_deck'", (string)preg_replace('/^.*QUOTA_EXEMPT = (\[[^\]]*\]).*$/s', '$1', $queue), 'Compté dans le quota.');
        $this->assertStringContainsString('NativeDeckStore::purgeExpired();', $queue);
        $this->assertStringContainsString('NativeDeckStore::purgeExpired();', $src('scripts/worker.php'));

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Files/NativeDeck.php', $src($f), $f);
            $this->assertStringContainsString('/src/Files/NativeDeckStore.php', $src($f), $f);
        }
        $this->assertStringContainsString('/src/Jobs/NativeDeckJob.php', $src('config/classes.php'));

        $this->assertSame(UsageSource::CAT_CREATION, UsageSource::category(UsageSource::NATIVE_DECK));
        $this->assertStringContainsString("'native_pptx' => LlmCatalog::supportsNativePptx(", $src('templates/chat/index.php'));

        $js = $src('public/assets/js/generated-files.js');
        $this->assertStringContainsString("'/native-deck'", $js);
        $this->assertStringContainsString('NATIVE_LINK', $js);
        $this->assertStringContainsString('refreshNative: refreshNative', $js);
        $chatJs = $src('public/assets/js/chat.js');
        $this->assertStringContainsString('JOB_TYPES.native_deck = {', $chatJs);
        $this->assertStringContainsString('window.MiaFiles?.refreshNative?.();', $chatJs);
        $this->assertStringContainsString('.file-polish', $src('public/assets/css/files.css'));
        $this->assertStringContainsString("'/native-deck/plan'", $js);

        // Ce que la personne a fourni part avec la carte.
        $this->assertStringContainsString("'kind'   => \$kind,", $chat);
        $this->assertStringContainsString("'before' => \$before,", $chat);
        $this->assertStringContainsString("(int)(\$_POST['message_id'] ?? 0)", $chat);
        $this->assertStringContainsString('NativeDeck::kindFor($origin, $language)', $chat);
        $this->assertStringContainsString("header('Content-Type: ' . \$file['mime']);", $chat, 'Un classeur est servi comme un classeur.');
        $job = $src('src/Jobs/NativeDeckJob.php');
        $this->assertStringContainsString('NativeDeck::contextFrom(Message::forConversation($convId), $before)', $job);
        $this->assertStringContainsString('NativeDeck::attachmentFiles(NativeDeck::withoutExcluded(', $job, 'Une pièce jointe décochée ne part pas.');
        foreach (["'exclude' => \$exclude,", "'company' => \$company,", "'note'    => \$note,"] as $cle) {
            $this->assertStringContainsString($cle, $chat, "Le lancement transmet $cle au job.");
        }
        $this->assertStringContainsString("'company' => (\$payload['company'] ?? true) !== false,", $job);
        $this->assertStringContainsString("message_id: request.messageId || ''", $js);
        $this->assertStringContainsString('+ polishButtonHtml(file)', $js);
        $this->assertStringContainsString("holder.getAttribute('data-message-id')", $js);

        // Le job tourne dans le WORKER : pièces jointes, logo et modèles doivent y être montés.
        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $compose) {
            $yaml   = $src($compose);
            $worker = substr($yaml, (int)strpos($yaml, "\n    worker:") ?: (int)strpos($yaml, "\n  worker:"));
            $worker = substr($worker, 0, (int)(strpos($worker, 'healthcheck:') ?: strlen($worker)));
            foreach (['storage_uploads:/var/www/html/storage/uploads', 'branding_logos:/var/www/html/public/assets/img/branding',
                      'storage_branding:/var/www/html/storage/branding'] as $mount) {
                $this->assertStringContainsString($mount, $worker, "$compose : le worker monte $mount");
            }
            $this->assertStringNotContainsString('storage_branding:/var/www/html/storage/branding:ro', $worker,
                'Pas de lecture seule : l’entrypoint fait un chown de storage/branding sous set -e.');
        }
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }

        return null;
    }
}
