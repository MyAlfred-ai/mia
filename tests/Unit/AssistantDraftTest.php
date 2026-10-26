<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MIA prépare un assistant (outil `creer_assistant`) : garde lexicale,
 * validation d'une proposition avec les choix permis du compte, carte
 * d'approbation, révision d'une proposition en attente, avatar créé, fiche
 * pré-remplie (« Ajuster »). Formes pures et câblage.
 */
final class AssistantDraftTest extends TestCase
{
    /** Image générée de test (dossier des images générées, ignoré par git). */
    private ?string $generatedFile = null;
    private bool $generatedDirCreated = false;

    protected function setUp(): void
    {
        I18n::use('fr');
        // ChatController n'est pas chargé par le bootstrap (pas d'autoloader).
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    protected function tearDown(): void
    {
        if ($this->generatedFile !== null && is_file($this->generatedFile)) {
            unlink($this->generatedFile);
        }
        if ($this->generatedDirCreated) {
            @rmdir(dirname((string)$this->generatedFile));
        }
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $fin = preg_match('/\n    (public|private|protected)( static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10)
            ? $m[0][1] : strlen($source);
        return substr($source, $debut, $fin - $debut);
    }

    private static function choix(array $extra = []): array
    {
        return $extra + [
            'admin'       => false,
            'llms'        => [
                'anthropic::claude-sonnet-5-5' => 'Claude Sonnet 5.5',
                'openai::gpt-6-luna'           => 'GPT-6 Luna',
                'openai::gpt-5.6'              => 'GPT-5.6',
            ],
            'llm_info'    => [
                'anthropic::claude-sonnet-5-5' => ['note' => 'vision, outils', 'temp' => true, 'tools' => true],
                'openai::gpt-6-luna'           => ['note' => 'économique', 'temp' => false, 'tools' => true],
                'openai::gpt-5.6'              => ['note' => '', 'temp' => true, 'tools' => false],
            ],
            'app_default' => ['slug' => 'anthropic::claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'note' => '', 'temp' => true, 'tools' => true],
            'kbs'         => [
                4 => ['name' => 'Catalogue produits', 'description' => 'Fiches produits', 'docs' => 12, 'active' => true, 'live' => false],
                9 => ['name' => 'Procédures RH', 'description' => '', 'docs' => 3, 'active' => false, 'live' => false],
            ],
            'tools'       => [21 => ['name' => 'Images marketing', 'type' => 'image']],
            'avatars'     => ['alfred-loupe.png' => 'Loupe', 'alfred-poete.png' => 'Poète'],
        ];
    }

    private static function saisie(array $extra = []): array
    {
        return $extra + [
            'name'          => '  Préparateur   de rendez-vous ',
            'description'   => 'Prépare les rendez-vous clients',
            'system_prompt' => "## Rôle\nTu es …\r\n## Mission\nPréparer",
            'default_llm'   => '',
            'temperature'   => '0,3',
            'color'         => '#38F',
            'kb_ids'        => ['catalogue produits'],
            'tool_ids'      => [21],
            'avatar'        => 'alfred-loupe.png',
        ];
    }

    private function imageGeneree(): string
    {
        $dir = ROOT . '/public/assets/img/generated';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            $this->generatedDirCreated = true;
        }
        $name = 'test-avatar-' . bin2hex(random_bytes(6)) . '.png';
        $this->generatedFile = $dir . '/' . $name;
        file_put_contents($this->generatedFile, 'png');
        return '/assets/img/generated/' . $name;
    }

    // ------------------------------------------------------------------
    // La garde lexicale
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function messages(): iterable
    {
        yield 'crée-moi'            => ['Crée-moi un assistant qui prépare mes rendez-vous clients', true];
        yield 'créer un nouvel'     => ['Peux-tu créer un nouvel assistant pour les RH ?', true];
        yield 'configure'           => ['Configure un assistant juridique', true];
        yield 'fais-moi un chatbot' => ['Fais-moi un chatbot pour la FAQ', true];
        yield 'je voudrais'         => ['Je voudrais un assistant qui relit mes contrats', true];
        yield 'besoin'              => ['J’ai besoin d’un assistant pour la veille', true];
        yield 'en tête'             => ['Un nouvel assistant pour la compta, avec la base Factures', true];
        yield 'anglais'             => ['Create a new assistant for customer support', true];
        yield 'anglais, besoin'     => ['I need an assistant that drafts sales emails', true];
        // Ni création, ni souhait : la boucle d'outils n'a pas à s'engager.
        yield 'question'            => ['Quel assistant utiliser pour mes contrats ?', false];
        yield 'collègue'            => ['L’assistant de direction m’a envoyé le planning', false];
        yield 'mon nouvel'          => ['Mon nouvel assistant de direction arrive lundi, rédige-lui un mot', false];
        yield 'rédaction'           => ['Fais un résumé pour l’assistant commercial', false];
        yield 'banal'               => ['Résume ce document en cinq points', false];
    }

    #[DataProvider('messages')]
    public function testLaDemandeDAssistantEstReconnue(string $message, bool $attendu): void
    {
        $this->assertSame($attendu, AssistantDraft::looksLikeAssistantRequest($message), $message);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function testUneSaisieValideEstNormalisee(): void
    {
        $data = AssistantDraft::normalizeInput(self::saisie(), self::choix());
        $this->assertSame('Préparateur de rendez-vous', $data['name']);
        $this->assertSame("## Rôle\nTu es …\n## Mission\nPréparer", $data['system_prompt']);
        $this->assertNull($data['default_llm'], 'chaîne vide = modèle de l’application, jamais recopié');
        $this->assertSame(0.3, $data['temperature']);
        $this->assertSame('#3388ff', $data['color']);
        $this->assertSame([4], $data['kb_ids'], 'une base se désigne aussi par son nom, sans casse');
        $this->assertSame([21], $data['tool_ids']);
        $this->assertSame('alfred-loupe.png', $data['avatar']);
        $this->assertNull($data['avatar_generated']);
    }

    /** @return iterable<string, array{0: array, 1: string}> */
    public static function refus(): iterable
    {
        yield 'sans nom'           => [['name' => ' '], 'nom'];
        yield 'sans prompt'        => [['system_prompt' => "\n "], 'prompt système'];
        yield 'modèle hors groupe' => [['default_llm' => 'openai::gpt-6-astra'], 'Modèle non permis'];
        yield 'température'        => [['temperature' => 'chaud'], 'Température'];
        yield 'couleur'            => [['color' => 'bleu'], 'Couleur'];
        yield 'base inconnue'      => [['kb_ids' => [77]], 'périmètre : 77'];
        yield 'outil inconnu'      => [['tool_ids' => ['Gamma']], 'Gamma'];
        yield 'avatar inconnu'     => [['avatar' => 'pirate.png'], 'bibliothèque'];
        yield 'avatar d’ailleurs'  => [['avatar' => '', 'avatar_generated' => '/assets/img/generated/autre.png'], 'n’est plus disponible'];
    }

    #[DataProvider('refus')]
    public function testUneSaisieInvalideEstRefuseeAvecUnMessage(array $champs, string $attendu): void
    {
        try {
            AssistantDraft::normalizeInput(self::saisie($champs), self::choix());
            $this->fail('Saisie acceptée à tort');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($attendu, $e->getMessage());
        }
    }

    public function testLaTemperatureEstBorneeEtTombeQuandLeModeleNeLaReglePas(): void
    {
        $this->assertSame(2.0, AssistantDraft::normalizeInput(self::saisie(['temperature' => '3.7']), self::choix())['temperature']);
        $this->assertNull(AssistantDraft::normalizeInput(self::saisie(['temperature' => '']), self::choix())['temperature']);
        // GPT-6 Luna n'en règle pas : rien n'est enregistré, comme dans la fiche.
        $this->assertNull(AssistantDraft::normalizeInput(
            self::saisie(['default_llm' => 'openai::gpt-6-luna', 'temperature' => '0.5']),
            self::choix()
        )['temperature']);
    }

    public function testUnAvatarCreeNEstAccepteQueSIlVientDeLaConversationEtExiste(): void
    {
        $url = $this->imageGeneree();
        $choices = self::choix();
        $data = AssistantDraft::normalizeInput(self::saisie(['avatar' => '', 'avatar_generated' => $url . '?e=1&s=abc']), $choices, [$url]);
        $this->assertSame($url, $data['avatar_generated'], 'adresse ramenée à sa forme canonique');
        $this->assertNull($data['avatar']);
        // Relue au clic « Approuver » : la carte ne porte que ce que le serveur y a écrit.
        $this->assertSame($url, AssistantDraft::normalizeInput(self::saisie(['avatar' => '', 'avatar_generated' => $url]), $choices, null)['avatar_generated']);

        unlink((string)$this->generatedFile);
        $this->expectException(\InvalidArgumentException::class);
        AssistantDraft::normalizeInput(self::saisie(['avatar' => '', 'avatar_generated' => $url]), $choices, null);
    }

    public function testLAdresseDUneImageGenereeEstConfinee(): void
    {
        $this->assertSame('/assets/img/generated/a1.png', AssistantDraft::generatedUrl('/assets/img/generated/a1.png?e=9&s=x'));
        $this->assertSame('/assets/img/generated/a1.png', AssistantDraft::generatedUrl('https://mia.example/assets/img/generated/a1.png'));
        foreach (['/assets/img/profils/a.png', '/assets/img/generated/../x.png', '/assets/img/generated/sous/a.png',
                  '/assets/img/generated/a.php', '', 'javascript:alert(1)'] as $refus) {
            $this->assertNull(AssistantDraft::generatedUrl($refus), $refus);
        }
        $this->assertNull(AssistantDraft::generatedImagePath('/assets/img/generated/absent-' . bin2hex(random_bytes(4)) . '.png'));
    }

    public function testLesIncoherencesSontRenvoyeesAuModele(): void
    {
        $choices = self::choix();
        $data = AssistantDraft::normalizeInput(self::saisie(['default_llm' => 'openai::gpt-5.6']), $choices);
        $issues = AssistantDraft::coherenceIssues($data, $choices);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('outils', $issues[0]);

        $data = AssistantDraft::normalizeInput(self::saisie(['kb_ids' => [9], 'tool_ids' => []]), $choices);
        $this->assertStringContainsString('désactivée', AssistantDraft::coherenceIssues($data, $choices)[0]);

        $data = AssistantDraft::normalizeInput(self::saisie(), $choices);
        $this->assertSame([], AssistantDraft::coherenceIssues($data, $choices));
    }

    // ------------------------------------------------------------------
    // Carte, arguments, fiche pré-remplie
    // ------------------------------------------------------------------

    public function testLaCarteMontreCeQuiSeraCree(): void
    {
        $choices = self::choix();
        $data = AssistantDraft::normalizeInput(self::saisie(), $choices);
        $preview = AssistantDraft::actionPreview($data, $choices);

        $this->assertSame('Créer un assistant', $preview['action']);
        $this->assertSame('Préparateur de rendez-vous', $preview['fichier']);
        $this->assertSame($data['system_prompt'], $preview['apercu']);
        $this->assertSame('Prompt système', $preview['apercu_titre']);
        $this->assertSame(AssistantDraft::ADJUST_URL, $preview['ajuster']);
        $this->assertSame('/assets/img/assistant.svg', $preview['icone']);
        $this->assertFileExists(ROOT . '/public' . $preview['icone']);
        $this->assertSame(['image' => '/assets/img/profils/alfred-loupe.png', 'couleur' => '#3388ff', 'initiale' => 'P'], $preview['visuel']);

        $impacts = implode("\n", $preview['impacts']);
        $this->assertStringContainsString('Modèle : celui de l’application (Claude Sonnet 5.5)', $impacts);
        $this->assertStringContainsString('température 0,3', $impacts);
        $this->assertStringContainsString('Bases de connaissances : Catalogue produits', $impacts);
        $this->assertStringContainsString('Outils : Images marketing', $impacts);
        $this->assertStringContainsString('Avatar : « Loupe »', $impacts);
        $this->assertStringContainsString('jusqu’à ce qu’un administrateur le partage', $impacts);

        // Un administrateur partage lui-même ; un prompt démesuré est abrégé dans la carte.
        $long = AssistantDraft::normalizeInput(self::saisie(['system_prompt' => str_repeat('a', AssistantDraft::PREVIEW_PROMPT_MAX + 50)]), $choices);
        $admin = AssistantDraft::actionPreview($long, self::choix(['admin' => true]));
        $this->assertStringContainsString('jusqu’à ce que vous le partagiez', implode("\n", $admin['impacts']));
        $this->assertLessThanOrEqual(AssistantDraft::PREVIEW_PROMPT_MAX + 2, mb_strlen($admin['apercu']));
    }

    public function testLesArgumentsDeLaCarteRedonnentLeMemeAssistant(): void
    {
        $choices = self::choix();
        $data = AssistantDraft::normalizeInput(self::saisie(), $choices);
        $this->assertSame($data, AssistantDraft::normalizeInput(AssistantDraft::toArgs($data), $choices));

        $prefill = AssistantDraft::formPrefill(AssistantDraft::toArgs($data));
        $this->assertSame('Préparateur de rendez-vous', $prefill['name']);
        $this->assertSame('0.3', $prefill['temperature']);
        $this->assertSame('#3388ff', $prefill['color']);
        $this->assertSame([4], $prefill['kb_ids']);
        $this->assertSame([21], $prefill['tool_ids']);
        $this->assertSame('', $prefill['avatar_generated']);
        $this->assertSame('', AssistantDraft::formPrefill(['color' => 'bleu'])['color'], 'la fiche ne lève jamais');
    }

    public function testUneRevisionNeChangeQueCeQueLeModelePrecise(): void
    {
        $choices = self::choix();
        $base = AssistantDraft::toArgs(AssistantDraft::normalizeInput(self::saisie(), $choices));

        // Le handler passe d'abord par revisionArgs : les modèles renvoient
        // souvent tous les paramètres, les inchangés vides (constaté avec
        // GPT-5.5) — une liste vide ne retire rien, `vider` si.
        $this->assertNotSame([], $base['kb_ids']);
        $args = AssistantTools::revisionArgs(['couleur' => '#2563eb', 'bases' => [], 'nom' => '', 'prompt_systeme' => '', 'remplace' => true], ['bases', 'outils']);
        $data = AssistantDraft::normalizeInput(AssistantTools::input($args, $base), $choices);
        $this->assertSame('#2563eb', $data['color']);
        $this->assertSame(array_map('intval', $base['kb_ids']), $data['kb_ids'], 'une liste vide ne retire rien');
        $this->assertSame($base['name'], $data['name']);
        $this->assertSame($base['system_prompt'], $data['system_prompt'], 'le prompt est repris tel quel');
        $this->assertSame('alfred-loupe.png', $data['avatar']);
        $vide = AssistantTools::input(AssistantTools::revisionArgs(['vider' => ['bases']], ['bases', 'outils']), $base);
        $this->assertSame([], AssistantDraft::normalizeInput($vide, $choices)['kb_ids'], '`vider` retire les bases');
        $this->assertNotSame([], $base['tool_ids']);
        $this->assertSame(['bases', 'outils'], AssistantTools::ignoredEmptyLists(['bases' => [], 'outils' => []], $base, ['bases' => 'kb_ids', 'outils' => 'tool_ids']));
        $this->assertSame(['outils'], AssistantTools::ignoredEmptyLists(['bases' => [], 'outils' => [], 'vider' => ['bases']], $base, ['bases' => 'kb_ids', 'outils' => 'tool_ids']));
        $handler = self::src('src/Agent/Tools/AssistantTools.php');
        $this->assertLessThan(
            strpos($handler, '$input   = self::input($args'),
            strpos($handler, '$args = self::revisionArgs($args, array_keys(self::REVISION_LISTS));')
        );

        // Un avatar d'image générée remplace celui de la bibliothèque, et inversement.
        $in = AssistantTools::input(['avatar' => '/assets/img/generated/z.png'], $base);
        $this->assertSame(['', '/assets/img/generated/z.png'], [$in['avatar'], $in['avatar_generated']]);
        $in = AssistantTools::input(['avatar' => 'aucun'], ['avatar_generated' => '/assets/img/generated/z.png'] + $base);
        $this->assertSame(['', ''], [$in['avatar'], $in['avatar_generated']]);
        // « défaut » = le modèle de l'application.
        $this->assertSame('', AssistantTools::input(['modele' => 'défaut'], $base)['default_llm']);
    }

    public function testUnModeleSansTemperatureLeDitSurLaCarte(): void
    {
        $choices = self::choix();
        $data = AssistantDraft::normalizeInput(self::saisie(['default_llm' => 'openai::gpt-6-luna']), $choices);
        $this->assertStringContainsString('température non réglable sur ce modèle', implode("\n", AssistantDraft::actionPreview($data, $choices)['impacts']));
        $this->assertFalse(AssistantDraft::temperatureSupported('openai::gpt-6-luna', $choices));
        $this->assertTrue(AssistantDraft::temperatureSupported(null, $choices), 'modèle de l’application');
    }

    public function testLaPropositionEnAttenteEstRappeleeAuModele(): void
    {
        // Constaté en essai réel : sans ce rappel, « mets-le en vert » faisait
        // réécrire de mémoire un prompt que le modèle ne voyait plus.
        $choices = self::choix();
        $args = AssistantDraft::toArgs(AssistantDraft::normalizeInput(self::saisie(), $choices));
        $note = AssistantDraft::pendingNote($args, $choices);
        $this->assertStringContainsString("Proposition d'assistant EN ATTENTE", $note);
        $this->assertStringContainsString("<<<\n" . $args['system_prompt'] . "\n>>>", $note, 'le prompt en entier');
        $this->assertStringContainsString('4 = « Catalogue produits »', $note);
        $this->assertStringContainsString('`remplace: true` et UNIQUEMENT les champs modifiés', $note);

        $sans = new AssistantTools(self::tour(), $choices, null, static fn(): array => []);
        $avec = new AssistantTools(self::tour(), $choices, null, static fn(): array => [], $args);
        $this->assertStringNotContainsString('EN ATTENTE', $sans->guidance());
        $this->assertStringContainsString($note, $avec->guidance());
    }

    public function testLAvatarCreeEstDecritCommeUnAvatar(): void
    {
        $data = AssistantDraft::normalizeInput(self::saisie(), self::choix());
        $prompt = AssistantDraft::avatarPrompt('une chouette à lunettes', $data);
        $this->assertStringContainsString('« Préparateur de rendez-vous »', $prompt);
        $this->assertStringContainsString('une chouette à lunettes', $prompt);
        $this->assertStringContainsString('Aucun texte', $prompt);
        $this->assertStringContainsString('#3388ff', $prompt);

        $carre = AssistantDraft::squareTool(['id' => 3, 'config_json' => '{"size":"1536x1024","quality":"low"}']);
        $config = json_decode($carre['config_json'], true);
        $this->assertSame(['1024x1024', 'low', '1:1'], [$config['size'], $config['quality'], $config['aspect_ratio']]);
    }

    // ------------------------------------------------------------------
    // L'outil
    // ------------------------------------------------------------------

    private static function tour(array $user = ['id' => 1, 'role' => 'creator']): AgentTurn
    {
        return new AgentTurn(
            (int)$user['id'], 5, $user, 4000,
            static function (array $event): void {},
            static fn(string $tool, array $args, ?array $ref): array => ['statut' => 'en_attente_approbation']
        );
    }

    public function testLaDefinitionNeProposeQueLesChoixDuCompte(): void
    {
        $tools = new AssistantTools(self::tour(), self::choix(), null, static fn(): array => []);
        $def = $tools->definitions()[0];
        $this->assertSame(AssistantDraft::TOOL, $def['name']);
        $props = $def['parameters']['properties'];
        $this->assertSame([], $def['parameters']['required'], 'une révision ne porte que ce qui change');
        $this->assertSame(['', 'anthropic::claude-sonnet-5-5', 'openai::gpt-6-luna', 'openai::gpt-5.6'], $props['modele']['enum']);
        $this->assertStringContainsString('4 = « Catalogue produits »', $props['bases']['description']);
        $this->assertStringContainsString('[désactivée]', $props['bases']['description']);
        $this->assertStringContainsString('21 = « Images marketing » (génération d\'images)', $props['outils']['description']);
        $this->assertStringContainsString('« alfred-loupe.png » = Loupe', $props['avatar']['description']);
        $this->assertArrayNotHasKey('avatar_a_generer', $props, 'sans outil image, aucun avatar à créer');
        $this->assertArrayHasKey('remplace', $props);
        $this->assertSame(['bases', 'outils'], $props['vider']['items']['enum']);

        $avecImage = new AssistantTools(self::tour(), self::choix(['kbs' => [], 'tools' => []]), ['id' => 3, 'provider' => 'openai'], static fn(): array => []);
        $props = $avecImage->definitions()[0]['parameters']['properties'];
        $this->assertArrayHasKey('avatar_a_generer', $props);
        $this->assertArrayNotHasKey('bases', $props, 'aucune base dans le périmètre : pas de paramètre mort');
        $this->assertArrayNotHasKey('outils', $props);
        $this->assertArrayNotHasKey('vider', $props, 'aucune liste à vider');

        $guidance = $tools->guidance();
        $this->assertStringContainsString(PromptStarters::structureDirective(), $guidance, 'même structure que la fiche');
        $this->assertStringContainsString('`remplace: true`', $guidance);
        $this->assertStringContainsString('N\'affirme jamais que l\'assistant est créé', $guidance);
    }

    public function testUnSimpleUtilisateurNePeutRienPreparer(): void
    {
        $this->assertFalse(AssistantDraft::canCreate(['id' => 1, 'role' => 'user']));
        $this->assertFalse(AssistantDraft::canCreate(['id' => 1, 'role' => 'creator', 'is_active' => 0]));
        $this->assertTrue(AssistantDraft::canCreate(['id' => 1, 'role' => 'creator']));
        $this->assertTrue(AssistantDraft::canCreate(['id' => 1, 'role' => 'admin']));

        $tools = new AssistantTools(self::tour(['id' => 1, 'role' => 'user']), self::choix(), null, static fn(): array => []);
        $resultat = $tools->handlers()[AssistantDraft::TOOL](['nom' => 'X', 'prompt_systeme' => 'Y']);
        $this->assertStringContainsString('pas le droit', $resultat['error'] ?? '');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLOutilNEstExposeQueSurDemandeAuxGestionnaires(): void
    {
        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        $this->assertStringContainsString('$assistantRequest = $supportsTools && !$unattended && AssistantDraft::canCreate($user)', $runSend);
        $this->assertStringContainsString('AssistantDraft::looksLikeAssistantRequest($content) || AssistantDraft::hasPending($convId, (int)$user[\'id\'])', $runSend);
        $this->assertStringContainsString('$explicitToolSources = $explicitToolSources || $assistantRequest;', $runSend);
        // Jamais « parce que la boucle tourne » : la définition est lourde.
        $this->assertStringContainsString("        if (\$assistantRequest) {\n            \$pendingDraft   = AssistantDraft::latestPending(\$convId, (int)\$user['id']);\n            \$assistantTools = new AssistantTools(", $runSend);
        $this->assertStringContainsString('$imageTool ?? AssistantDraft::avatarImageTool($user, $groupIds, $hasGroupAssignments)', $runSend);
        // L'aperçu calculé par le fournisseur voyage par la file d'approbation.
        $this->assertStringContainsString("\$tool === AssistantDraft::TOOL      => (array)(\$fileRef['preview'] ?? []),", $runSend);
        // L'avatar créé est facturé comme une image du chat.
        $this->assertStringContainsString('$this->logImageToolUsage($assistantTools->avatarTool(), $avatarResult, $billingCtx)', $runSend);
    }

    public function testLApprobationRejoueLesReglesAuMomentT(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString("if ((string)\$action['tool'] === AssistantDraft::TOOL) {", self::methode($chat, 'decideAction'));
        $approve = self::methode($chat, 'approveAssistantDraft');
        $this->assertStringContainsString('AssistantDraft::canCreate($user)', $approve);
        $this->assertStringContainsString('AssistantDraft::normalizeInput((array)$action[\'args\'], AssistantDraft::choicesFor($user), null)', $approve);
        // Quota atteint : la carte reste en attente, rien n'est consommé.
        $this->assertStringContainsString('DrivePendingAction::release($actionId);', $approve);
        $this->assertStringContainsString("'retryable' => true", $approve);
        $this->assertLessThan(strpos($approve, 'AssistantDraft::create('), strpos($approve, 'AssistantDraft::quotaReached('));
        $this->assertStringContainsString("'assistant_id' => \$profileId", $approve);

        $this->assertSame('Préparation d\'un assistant', ChatController::ACTION_LABELS[AssistantDraft::TOOL] ?? null);
        $this->assertSame('assistant', ChatController::ACTION_ICONS[AssistantDraft::TOOL] ?? null);
        $this->assertSame(['t' => AssistantDraft::TOOL, 'd' => 'Veille'], ChatController::actionEntryForTool(AssistantDraft::TOOL, ['nom' => 'Veille'], []));
        $this->assertStringContainsString('.msg-trace-icon[data-icon="assistant"]', self::src('public/assets/css/main.css'));
    }

    public function testLesCartesEnAttenteReviennentAuRechargementSansCondition(): void
    {
        // La lecture n'était faite que si un connecteur était relié : une carte
        // d'assistant (aucun connecteur) disparaissait au rechargement.
        $show = self::methode(self::src('src/Controllers/ChatController.php'), 'show');
        $this->assertStringContainsString("\$drivePendingActions = DrivePendingAction::pendingForConversation(\$convId, (int)\$user['id']);", $show);
        $this->assertStringNotContainsString('$driveConnected || $gmailConnected', $show);
    }

    public function testLaCarteSAfficheSeReviseEtOuvreLaConversation(): void
    {
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString("assistant_prepare:         t('Préparation de l’assistant…')", $js);
        $this->assertStringContainsString("generating_avatar:         t('Création de l’avatar de l’assistant…')", $js);
        // « Ajuster » : la fiche visée par la carte, même origine seulement.
        $this->assertStringContainsString("const adjustBase = (typeof data.ajuster === 'string'", $js);
        $this->assertStringContainsString("const apercuTitle = String(data.apercu_titre || 'Aperçu du contenu');", $js);
        $this->assertStringContainsString('class="drive-confirm-visual"', $js);
        // Proposition révisée : la carte d'avant se ferme.
        $this->assertStringContainsString('json.drive_superseded.ids.forEach(markDriveCardSuperseded);', $js);
        $this->assertStringContainsString('function markDriveCardSuperseded(id) {', $js);
        // Assistant créé : on peut lui parler aussitôt.
        $this->assertStringContainsString('if (r.assistant_id) {', $js);
        $this->assertStringContainsString("fetchWithSessionGuard('/chat/new'", self::methodeJs($js, 'startConversationWithAssistant'));

        $css = self::src('public/assets/css/main.css');
        foreach (['.drive-confirm-visual {', '.drive-confirm-card.is-superseded', '.drive-confirm-start {'] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
    }

    private static function methodeJs(string $js, string $nom): string
    {
        $debut = strpos($js, 'function ' . $nom . '(');
        self::assertIsInt($debut, $nom);
        return substr($js, $debut, 900);
    }

    public function testAjusterOuvreLaFichePreRemplieEtFermeLaCarte(): void
    {
        $admin = self::src('src/Controllers/AdminController.php');
        $new = self::methode($admin, 'newProfile');
        $this->assertStringContainsString("DrivePendingAction::findPendingForUser(\$actionId, (int)\$currentUser['id'])", $new);
        $this->assertStringContainsString("(string)\$pending['tool'] === AssistantDraft::TOOL", $new);
        $this->assertStringContainsString('AssistantDraft::formPrefill(', $new);

        $create = self::methode($admin, 'createProfile');
        // L'avatar créé vient de la CARTE relue, jamais d'une adresse postée.
        $this->assertStringContainsString("AssistantDraft::generatedImagePath((string)(\$draftCard['args']['avatar_generated'] ?? ''))", $create);
        $this->assertStringContainsString('AssistantDraft::GENERATED_AVATAR_CHOICE', $create);
        $this->assertStringContainsString('AssistantDraft::closeAdjustedCard($fromAction', $create);

        $close = self::methode(self::src('src/Assistants/AssistantDraft.php'), 'closeAdjustedCard');
        $this->assertStringContainsString('DrivePendingAction::claim($actionId, $userId, $convId)', $close);
        $this->assertStringContainsString("DrivePendingAction::resolve(\$actionId, 'executed');", $close);

        $tpl = self::src('templates/admin/profiles/edit.php');
        $this->assertStringContainsString('name="from_action"', $tpl);
        $this->assertStringContainsString('AssistantDraft::GENERATED_AVATAR_CHOICE', $tpl);
        $this->assertStringContainsString('if (radio && radio.dataset.src) return radio.dataset.src;', $tpl);
    }

    public function testLaFicheEtLeChatPartagentLesMemesRegles(): void
    {
        $admin = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString('return AssistantDraft::allowedLlmsFor(Session::currentUser());', self::methode($admin, 'allowedLlmsForCurrentManager'));
        $this->assertStringContainsString('return AssistantDraft::sanitizeToolIds($raw);', self::methode($admin, 'sanitizeProfileToolIds'));
        $this->assertStringContainsString('array_keys(AssistantDraft::kbScope(Session::currentUser()))', self::methode($admin, 'applyProfileKbLinks'));
    }

    public function testLesClassesSontChargeesPartout(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::src($liste);
            $this->assertStringContainsString('/src/Assistants/AssistantDraft.php', $src, $liste);
            $this->assertStringContainsString('/src/Agent/Tools/AssistantTools.php', $src, $liste);
        }
        $classes = self::src('config/classes.php');
        $this->assertLessThan(strpos($classes, '/src/Agent/Tools/AssistantTools.php'), strpos($classes, '/src/Agent/AbstractToolProvider.php'));
    }

    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('creer-un-assistant-avec-mia.md', self::src('docs/user/fonctionnalites/assistants/index.md'));
        $this->assertStringContainsString('« Ajuster »', self::src('docs/user/fonctionnalites/assistants/creer-un-assistant-avec-mia.md'));
        $this->assertStringContainsString('MIA crée l’assistant pour vous', self::src('docs/GUIDE-CREATEUR.md'));
        $this->assertStringContainsString('id: mia-cree-vos-assistants', self::src('docs/NOUVEAUTES.md'));
    }
}
