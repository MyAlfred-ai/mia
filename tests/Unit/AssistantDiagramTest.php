<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Schéma d'un assistant — Espaces → Assistants : à droite de la liste
 * (assistant cliqué) et de la fiche (création comme modification, tenu à jour
 * pendant la saisie). Le schéma des tâches programmées, sans déclencheur.
 *
 * Trois étages vérifiés :
 *  - les DONNÉES du serveur (AssistantDiagram : configuration d'un
 *    assistant, notes des bases et des outils, documents, libellés, icônes) ;
 *  - le RENDU, exécuté sous node avec les vrais modules (diagram.js, le
 *    moteur partagé avec les tâches, puis assistant-diagram.js) : modèle
 *    résolu comme au chat, température, extrait du prompt, un nœud par
 *    famille, tout texte échappé, aucune image d'une autre origine ;
 *  - le CÂBLAGE : un schéma jamais posé, un script chargé dans le mauvais
 *    ordre, une cible sans réglage ou un seuil qui diverge ne lèveraient
 *    aucune erreur — le schéma serait simplement absent ou faux.
 *
 * Sans node, la partie rendu est SKIPPÉE ; elle tourne sur le poste de
 * développement et en CI.
 */
final class AssistantDiagramTest extends TestCase
{
    private const ENGINE = 'public/assets/js/diagram.js';
    private const JS     = 'public/assets/js/assistant-diagram.js';
    private const LIST   = 'templates/admin/profiles/list.php';
    private const FORM   = 'templates/admin/profiles/edit.php';
    private const CSS    = 'public/assets/css/main.css';

    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // Données du serveur
    // ------------------------------------------------------------------

    public function testLaConfigurationDUnAssistantALaFormeDeLaFiche(): void
    {
        $doc = ['id' => '4', 'file_name' => 'Charte.pdf', 'mime_type' => 'application/pdf', 'file_size' => '2048',
                'token_count' => '1200', 'has_text' => '1', 'extraction_status' => 'done', 'stored_name' => 'x'];
        $spec = AssistantDiagram::spec([
            'name'          => '  Juriste  ',
            'description'   => str_repeat('d', 300),
            'color'         => 'red;background:url(x)',
            'avatar'        => '../../etc/alfred-loupe.png',
            'system_prompt' => str_repeat('p', AssistantDiagram::PROMPT_SOURCE_CHARS + 50),
            'default_llm'   => ' anthropic::claude-opus-5 ',
            'temperature'   => 0.3,
            'is_active'     => 0,
            'is_personal'   => 0,
        ], [3, '1', 3], ['5'], [$doc]);

        $this->assertSame('Juriste', $spec['name']);
        $this->assertSame(240, mb_strlen($spec['description']));
        $this->assertSame('', $spec['color'], 'Une couleur hors #RRGGBB ne passe jamais.');
        $this->assertSame('/assets/img/profils/alfred-loupe.png', $spec['avatar'], 'Le nom de fichier seul : aucun chemin ne sort de la bibliothèque.');
        $this->assertSame(AssistantDiagram::PROMPT_SOURCE_CHARS, mb_strlen($spec['prompt']));
        $this->assertSame('anthropic::claude-opus-5', $spec['llm']);
        $this->assertSame('0.3', $spec['temperature']);
        $this->assertFalse($spec['active']);
        $this->assertSame('org', $spec['sharing']);
        $this->assertSame([3, 1], $spec['kb_ids'], 'Sans doublon.');
        $this->assertSame([5], $spec['tool_ids']);
        $this->assertSame([AssistantDiagram::docEntry($doc)], $spec['docs']);

        // Sans température : vide, jamais « 0 ».
        $this->assertSame('', AssistantDiagram::spec(['name' => 'X', 'temperature' => null], [], [], [])['temperature']);
    }

    public function testUnDocumentALaFormeQueLaFicheRecoit(): void
    {
        $entry = AssistantDiagram::docEntry(['id' => '7', 'file_name' => 'Plan.pdf', 'mime_type' => 'application/pdf', 'file_size' => '10',
                                             'token_count' => '42', 'has_text' => 1, 'extraction_status' => 'processing']);
        $this->assertSame([
            'id' => 7, 'file_name' => 'Plan.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
            'token_count' => 42, 'has_text' => true, 'extraction_status' => 'processing',
        ], $entry);
        $this->assertSame('done', AssistantDiagram::docEntry(['id' => 1, 'file_name' => 'a', 'mime_type' => 'b', 'file_size' => 0, 'token_count' => 0])['extraction_status']);
    }

    public function testQuiVoitLAssistant(): void
    {
        $this->assertSame('global', AssistantDiagram::sharingOf(['is_global' => 1, 'is_personal' => 1]));
        $this->assertSame('personal', AssistantDiagram::sharingOf(['is_personal' => 1]));
        $this->assertSame('org', AssistantDiagram::sharingOf(['is_personal' => 0]));
    }

    public function testLaNoteDUneBaseDitCeQuIlFautEnSavoir(): void
    {
        $base = ['id' => 1, 'name' => 'Fiches', 'is_active' => 1, 'is_system' => 0, 'doc_count' => 12, 'sync_enabled' => 0, 'sync_provider' => null, 'backend' => 'local'];

        $this->assertSame(['name' => 'Fiches', 'live' => false, 'note' => '12 docs', 'warn' => false], AssistantDiagram::kbEntry($base));
        $this->assertSame('1 doc', AssistantDiagram::kbEntry(['doc_count' => 1] + $base)['note']);
        $this->assertSame('Aucun document', AssistantDiagram::kbEntry(['doc_count' => 0] + $base)['note']);
        $this->assertSame('Google Drive · 12 docs', AssistantDiagram::kbEntry(['sync_enabled' => 1, 'sync_provider' => 'drive'] + $base)['note']);
        $this->assertSame('SharePoint · 12 docs', AssistantDiagram::kbEntry(['sync_enabled' => 1, 'sync_provider' => 'sharepoint'] + $base)['note']);
        $this->assertSame('Base système', AssistantDiagram::kbEntry(['is_system' => 1] + $base)['note']);

        $live = AssistantDiagram::kbEntry(['backend' => KnowledgeBase::BACKEND_MYBRAIN] + $base);
        $this->assertTrue($live['live']);
        $this->assertSame('MyBrain · pour ses groupes seulement', $live['note'], 'Une base en direct suit la personne, pas l’assistant.');

        // Inactive : dit en premier, en ambre — le chat ne l'interroge pas.
        $off = AssistantDiagram::kbEntry(['is_active' => 0, 'backend' => KnowledgeBase::BACKEND_MYBRAIN] + $base);
        $this->assertSame('Inactive — non interrogée', $off['note']);
        $this->assertTrue($off['warn']);
    }

    public function testLaNoteDUnOutil(): void
    {
        $this->assertSame(['name' => 'Illus', 'type' => 'image', 'note' => 'Génération d’image', 'warn' => false],
            AssistantDiagram::toolEntry(['name' => 'Illus', 'type' => 'image', 'is_active' => 1]));
        $this->assertSame('Automatisations n8n', AssistantDiagram::toolEntry(['name' => 'Ops', 'type' => 'n8n', 'is_active' => 1])['note']);
        $this->assertSame('Présentations Gamma', AssistantDiagram::toolEntry(['name' => 'G', 'type' => 'gamma', 'is_active' => 1])['note']);
        $eteint = AssistantDiagram::toolEntry(['name' => 'Ancien', 'type' => 'image', 'is_active' => 0]);
        $this->assertSame('Outil désactivé', $eteint['note']);
        $this->assertTrue($eteint['warn']);
    }

    public function testLesLibellesSuiventLaLangueDeLaPage(): void
    {
        $fr = AssistantDiagram::labels();
        foreach ($fr as $cle => $texte) {
            $this->assertNotSame('', trim($texte), "Libellé vide : $cle");
        }
        foreach (['temp_value', 'temp_ignored'] as $cle) {
            $this->assertStringContainsString('{t}', $fr[$cle]);
        }
        foreach (['doc_tokens', 'more_one', 'more_many'] as $cle) {
            $this->assertStringContainsString('{n}', $fr[$cle]);
        }
        $this->assertSame('fr-FR', AssistantDiagram::numberLocale(), 'Nombres « 0,7 » en français.');

        // La page des assistants est traduite : le schéma parle sa langue —
        // chaque libellé passe par t(), et les substituants restent.
        I18n::use('en');
        $en = AssistantDiagram::labels();
        $this->assertSame(array_keys($fr), array_keys($en));
        foreach ($en as $cle => $texte) {
            $this->assertNotSame('', trim($texte), "Libellé vide en anglais : $cle");
            $this->assertSame(I18n::t($fr[$cle]), $texte, "Libellé qui ne passe pas par t() : $cle");
        }
        foreach (['temp_value', 'temp_ignored'] as $cle) {
            $this->assertStringContainsString('{t}', $en[$cle]);
        }
        foreach (['doc_tokens', 'more_one', 'more_many'] as $cle) {
            $this->assertStringContainsString('{n}', $en[$cle]);
        }
        $this->assertSame('Model', $en['model']);
        $this->assertSame('Knowledge bases', $en['kbs']);
        $this->assertSame('en-US', AssistantDiagram::numberLocale(), 'Nombres « 0.7 » en anglais.');
        $this->assertStringContainsString("'locale'       => self::numberLocale(),", self::src('src/Assistants/AssistantDiagram.php'));

        // Les notes aussi, constantes comprises (traduites à l'accesseur).
        $base = ['id' => 1, 'name' => 'Fiches', 'is_active' => 1, 'is_system' => 0, 'doc_count' => 3, 'sync_enabled' => 1, 'sync_provider' => 'gcalendar', 'backend' => 'local'];
        $this->assertSame('Google Calendar · ' . I18n::plural(3, '{n} doc', '{n} docs'), AssistantDiagram::kbEntry($base)['note']);
        $this->assertSame(I18n::t('Outil désactivé'), AssistantDiagram::toolEntry(['name' => 'X', 'type' => 'image', 'is_active' => 0])['note']);
        $this->assertSame(I18n::t('Génération d’image'), AssistantDiagram::toolEntry(['name' => 'X', 'type' => 'image', 'is_active' => 1])['note']);
    }

    public function testLesIconesSontCellesDuSchemaDesTaches(): void
    {
        $assistant = AssistantDiagram::icons();
        $tache     = TaskDiagram::icons();
        foreach (['model', 'assistant', 'kbs', 'tools', 'kb', 'kb_live', 'edit'] as $cle) {
            $this->assertSame($tache[$cle], $assistant[$cle], "Icône « $cle » différente du schéma des tâches.");
        }
        $this->assertSame(AdminMenu::icon('profiles', 15), $assistant['assistant']);
        foreach (['docs', 'doc', 'doc_image', 'temperature', 'personal', 'org', 'global'] as $cle) {
            $this->assertStringStartsWith('<svg', $assistant[$cle], "Icône absente : $cle");
        }
        $source = self::src('src/Assistants/AssistantDiagram.php');
        foreach (['image', 'n8n', 'gamma'] as $type) {
            $this->assertStringContainsString("TaskDiagram::toolIcon('$type')", $source, "Outil $type : le pictogramme du schéma des tâches.");
        }
        $this->assertStringContainsString('LLMFactory::supportsTemperature', $source, 'La température vient du catalogue, comme le curseur de la fiche.');
        $this->assertStringContainsString('LLMFactory::appDefault($all)', $source, 'Le défaut est celui du chat.');
    }

    public function testLAvatarNeSortJamaisDeLaBibliotheque(): void
    {
        $this->assertSame('', AssistantDiagram::avatarUrl(''));
        $this->assertSame('', AssistantDiagram::avatarUrl(null));
        $this->assertSame('/assets/img/profils/passwd', AssistantDiagram::avatarUrl('../../etc/passwd'));
        $this->assertSame('/assets/img/profils/l%27avatar%20bleu.png', AssistantDiagram::avatarUrl("l'avatar bleu.png"));
    }

    // ------------------------------------------------------------------
    // Rendu, exécuté sous node
    // ------------------------------------------------------------------

    /** Catalogue de test : les vrais libellés et icônes, des modèles et des bases inventés. */
    private static function catalogue(array $surcharge = []): array
    {
        return $surcharge + [
            'labels'       => AssistantDiagram::labels(),
            'icons'        => AssistantDiagram::icons(),
            'tool_icons'   => ['image' => '<svg data-t="image"></svg>', 'n8n' => '<img src="/assets/img/n8n.svg" alt="">', 'gamma' => '<img src="/assets/img/gamma.svg" alt="">'],
            'llms'         => [
                'anthropic::claude-opus-5' => ['label' => 'Claude Opus 5', 'provider' => 'Claude (Anthropic)', 'logo' => '/assets/img/llm-anthropic.png', 'temp' => false],
                'mistral::mistral-large'   => ['label' => 'Mistral Large 3', 'provider' => 'Mistral', 'logo' => '//evil.example/x.png', 'temp' => true],
                'openai::gpt-5.6'          => ['label' => 'GPT-5.6 Sol', 'provider' => 'OpenAI', 'logo' => '/assets/img/llm-openai.png', 'temp' => false],
            ],
            'default_llm'  => 'openai::gpt-5.6',
            'kbs'          => [
                '1' => ['name' => 'Fiches clients', 'live' => false, 'note' => '3 docs', 'warn' => false],
                '2' => ['name' => 'Cerveau d’équipe', 'live' => true, 'note' => 'MyBrain · pour ses groupes seulement', 'warn' => false],
                '3' => ['name' => 'Archives 2024', 'live' => false, 'note' => 'Inactive — non interrogée', 'warn' => true],
                '4' => ['name' => '<img src=x onerror=alert(1)>', 'live' => false, 'note' => '1 doc', 'warn' => false],
            ],
            'tools'        => [
                '5' => ['name' => 'Illustrations', 'type' => 'image', 'note' => 'Génération d’image', 'warn' => false],
                '6' => ['name' => 'Automatisations Ops', 'type' => 'n8n', 'note' => 'Automatisations n8n', 'warn' => false],
                '7' => ['name' => 'Ancien générateur', 'type' => 'image', 'note' => 'Outil désactivé', 'warn' => true],
            ],
            'locale'       => 'fr-FR',
            'max_items'    => 5,
            'prompt_chars' => 600,
        ];
    }

    private static function doc(string $nom, array $champs = []): array
    {
        return $champs + ['id' => crc32($nom), 'file_name' => $nom, 'mime_type' => 'application/pdf', 'file_size' => 100,
                          'token_count' => 1234, 'has_text' => true, 'extraction_status' => 'done'];
    }

    /** Scénarios : nom → [configuration d'assistant, options du modèle, catalogue particulier ?]. */
    private static function scenarios(): array
    {
        $base = ['name' => 'Juriste', 'description' => 'Relit vos contrats.', 'color' => '#8b5cf6', 'avatar' => '/assets/img/profils/alfred-loupe.png',
                 'prompt' => "## Rôle\nTu es **un juriste** interne.\n\n## Mission\nAider les équipes.", 'llm' => 'mistral::mistral-large',
                 'temperature' => '0.4', 'active' => true, 'sharing' => 'org', 'kb_ids' => [], 'tool_ids' => [], 'docs' => []];
        $docs = [
            self::doc('Contrat-type.pdf', ['token_count' => 12450]),
            self::doc('CGV 2026.docx', ['has_text' => false, 'token_count' => 0, 'extraction_status' => 'processing']),
            self::doc('Scan.pdf', ['has_text' => false, 'token_count' => 0, 'extraction_status' => 'failed']),
            self::doc('Logo.png', ['has_text' => false, 'token_count' => 0, 'mime_type' => 'image/png']),
            self::doc('Vide.txt', ['has_text' => false, 'token_count' => 0]),
            self::doc('Annexe A.pdf'),
            self::doc('Annexe B.pdf'),
        ];
        return [
            'complet'    => [['kb_ids' => [3, 1, 2], 'fixed_kb_ids' => [99, 1], 'tool_ids' => [7, 5, 6], 'docs' => $docs] + $base, null, null],
            'defaut'     => [['llm' => '', 'temperature' => '', 'avatar' => '', 'color' => '#123456', 'name' => 'élodie RH', 'sharing' => 'personal', 'kb_ids' => [1]] + $base, null, null],
            'par_defaut' => [['temperature' => ''] + $base, null, null],
            'ignoree'    => [['llm' => 'anthropic::claude-opus-5', 'temperature' => '0.3', 'sharing' => 'global'] + $base, null, null],
            'retire'     => [['llm' => 'openai::gpt-4o-retire'] + $base, null, null],
            'vide'       => [['name' => '  ', 'description' => '', 'prompt' => '', 'active' => false, 'color' => '#1e293b', 'avatar' => '', 'llm' => ''] + $base, null, null],
            'sans_llm'   => [['llm' => ''] + $base, null, ['llms' => [], 'default_llm' => '']],
            'hostile'    => [['name' => 'Veille <b>marché</b>', 'description' => 'Suit vos <script>alert(1)</script> clients.', 'avatar' => 'https://pixel.example/p.png',
                              'color' => 'red;background:url(x)', 'kb_ids' => [4], 'llm' => 'mistral::mistral-large'] + $base, null, null],
            'creation'   => [['docs' => [['file_name' => 'Plaquette.pdf', 'mime_type' => 'application/pdf', 'pending_create' => true]]] + $base, null, null],
            'saut'       => [['kb_ids' => [1], 'tool_ids' => [5], 'docs' => [self::doc('Charte.pdf')]] + $base,
                             ['jump' => ['llm' => 'Modifier dans « Comportement »', 'assistant' => 'Modifier dans « Identité »', 'kbs' => 'Modifier dans « Connaissances »',
                                         'docs' => 'Modifier dans « Connaissances »', 'tools' => 'Modifier dans « Comportement »', 'knowledge' => 'Modifier dans « Connaissances »']], null],
            'saut_vide'  => [['name' => 'Vide', 'prompt' => 'Tu es là.'] + $base, ['jump' => ['knowledge' => 'Modifier dans « Connaissances »']], null],
        ];
    }

    /** @return array<string, mixed> */
    private static function sortie(): array
    {
        if (self::$sortie !== null) {
            return self::$sortie;
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        I18n::use('fr');
        $scenarios = [];
        foreach (self::scenarios() as $nom => [$spec, $options, $catalogue]) {
            $scenarios[$nom] = ['spec' => $spec, 'opts' => $options, 'cat' => self::catalogue($catalogue ?? [])];
        }

        $script = "'use strict';\n"
            . self::src(self::ENGINE) . "\n"
            . self::src(self::JS) . "\n"
            . 'const D = globalThis.MiaAssistantDiagram;' . "\n"
            . 'const SC = ' . json_encode($scenarios) . ";\n"
            . 'const out = { domTouched: typeof document !== "undefined", tint: globalThis.MiaDiagram.tint("#1e293b") };' . "\n"
            . 'for (const [k, s] of Object.entries(SC)) { const v = D.model(s.spec, s.cat, s.opts || undefined); out[k] = { view: v, html: D.html(v) }; }' . "\n"
            . 'process.stdout.write(JSON.stringify(out));';

        $fichier = tempnam(sys_get_temp_dir(), 'mia-schema-assistant-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }
        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("assistant-diagram.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return self::$sortie = $resultats;
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

    public function testUnAssistantNAPasDeDeclencheur(): void
    {
        $s = self::sortie()['complet'];
        $this->assertNull($s['view']['trigger']);
        $this->assertStringContainsString('<div class="sd-canvas no-trigger">', $s['html']);
        $this->assertStringNotContainsString('sd-trigger', $s['html']);
        $this->assertStringNotContainsString('data-node="trigger"', $s['html']);
        $this->assertSame(1, substr_count($s['html'], 'data-node="assistant"'));
        $this->assertStringContainsString('data-node="llm" data-edge="side"', $s['html']);
        $this->assertSame(3, substr_count($s['html'], 'data-edge="up"'), 'Une flèche par nœud du bas.');
        $this->assertFalse(self::sortie()['domTouched'], 'La partie pure ne touche jamais au DOM.');
    }

    public function testLeModeleEstCeluiDuChat(): void
    {
        $s = self::sortie();
        $L = AssistantDiagram::labels();
        // Un modèle choisi et actif : imposé.
        $this->assertSame('Mistral Large 3', $s['complet']['view']['llm']['label']);
        $this->assertSame($L['llm_imposed'], $s['complet']['view']['llm']['note']);
        // Sans modèle : le défaut de l'application, qu'il suit.
        $this->assertSame('GPT-5.6 Sol', $s['defaut']['view']['llm']['label']);
        $this->assertSame($L['llm_default'], $s['defaut']['view']['llm']['note']);
        // Un modèle retiré : le défaut répond, et c'est dit en ambre.
        $this->assertSame('GPT-5.6 Sol', $s['retire']['view']['llm']['label']);
        $this->assertSame($L['llm_retired'], $s['retire']['view']['llm']['note']);
        $this->assertStringContainsString('<div class="sd-note is-warn">' . $L['llm_retired'], $s['retire']['html']);
        // Aucun modèle sur l'instance : rien d'inventé.
        $this->assertSame($L['llm_none'], $s['sans_llm']['view']['llm']['label']);
        $this->assertStringContainsString('sd-llm is-missing', $s['sans_llm']['html']);
    }

    public function testLaTemperatureDitCeQuiEstVraimentEnvoye(): void
    {
        $s = self::sortie();
        $temp = static fn(string $k): string => $s[$k]['view']['llm']['params'][0]['text'];
        $this->assertSame('Température 0,4', $temp('complet'), 'Virgule française : l’écran est en français.');
        $this->assertSame('Température par défaut', $temp('par_defaut'));
        $this->assertSame('Température non réglable', $temp('defaut'), 'Le modèle par défaut n’accepte pas de température.');
        $this->assertSame('Température 0,3 ignorée', $temp('ignoree'), 'Une valeur gardée d’un modèle précédent ne part pas.');
        $this->assertSame('temperature', $s['complet']['view']['llm']['params'][0]['target']);
        $this->assertStringContainsString('<ul class="sd-traits sd-params">', $s['complet']['html']);
    }

    public function testLExtraitDuPromptSansSesTitres(): void
    {
        $s = self::sortie();
        $quote = $s['complet']['view']['assistant']['quote'];
        $this->assertSame('Tu es un juriste interne. Aider les équipes.', $quote['text'], 'Titres de section et gras retirés, sur une ligne.');
        $this->assertSame('prompt', $quote['target']);
        $this->assertStringContainsString('<p class="sd-quote" data-target="prompt">', $s['complet']['html']);

        // Prompt vide, nom vide : ce qui manque est dit.
        $vide = $s['vide'];
        $this->assertTrue($vide['view']['assistant']['quote']['missing']);
        $this->assertStringContainsString('<p class="sd-quote is-missing" data-target="prompt"><span>' . AssistantDiagram::labels()['prompt_missing'], $vide['html']);
        $this->assertStringContainsString('<span class="sd-title sd-name is-missing">' . AssistantDiagram::labels()['untitled'] . '</span>', $vide['html']);
        $this->assertSame(AssistantDiagram::labels()['untitled'], $vide['view']['name']);
    }

    public function testBasesDocumentsEtOutilsOccupentChacunUnNoeud(): void
    {
        $s = self::sortie()['complet'];
        $groupes = array_column($s['view']['groups'], null, 'kind');
        $this->assertSame(['kbs', 'docs', 'tools'], array_keys($groupes));
        foreach (['sd-kbs', 'sd-docs', 'sd-tools'] as $classe) {
            $this->assertSame(1, substr_count($s['html'], 'sd-group ' . $classe), "Un seul nœud $classe.");
        }

        // Bases : cochées et fixes, sans doublon, par ordre alphabétique ;
        // une base hors du périmètre ferme la marche, sans son nom.
        $L = AssistantDiagram::labels();
        $this->assertSame(['Archives 2024', 'Cerveau d’équipe', 'Fiches clients', $L['kb_hidden']], array_column($groupes['kbs']['items'], 'label'));
        $this->assertSame($L['kb_hidden_note'], $groupes['kbs']['items'][3]['note']);
        $this->assertTrue($groupes['kbs']['items'][0]['warn'], 'Une base inactive est signalée.');
        $this->assertStringContainsString('mybrain.png', $groupes['kbs']['items'][1]['icon'], 'Une base en direct porte l’image du cerveau.');

        // Documents : cinq montrés, le nœud compte TOUT et dit le reste.
        $docs = $groupes['docs'];
        $this->assertCount(5, $docs['items']);
        $this->assertSame(7, $docs['count']);
        $this->assertSame('+ 2 autres', $docs['more']);
        $this->assertStringContainsString('<span class="sd-count">7</span>', $s['html']);
        $this->assertStringContainsString('<li class="sd-more">+ 2 autres</li>', $s['html']);
        $notes = array_column($docs['items'], 'note', 'label');
        $this->assertMatchesRegularExpression('/^~12\s450 tokens$/u', $notes['Contrat-type.pdf']);
        $this->assertSame($L['doc_pending'], $notes['CGV 2026.docx']);
        $this->assertSame($L['doc_failed'], $notes['Scan.pdf']);
        $this->assertSame($L['doc_image'], $notes['Logo.png']);
        $this->assertSame($L['doc_empty'], $notes['Vide.txt']);
        $warn = array_column($docs['items'], 'warn', 'label');
        $this->assertTrue($warn['Scan.pdf']);
        $this->assertTrue($warn['Vide.txt'], 'Sans texte, le document ne servira à rien : signalé.');
        $this->assertFalse($warn['Logo.png'], 'Une image est montrée aux modèles « vision ».');

        // Outils : par ordre alphabétique, un outil éteint signalé.
        $this->assertSame(['Ancien générateur', 'Automatisations Ops', 'Illustrations'], array_column($groupes['tools']['items'], 'label'));
        $this->assertTrue($groupes['tools']['items'][0]['warn']);
        $this->assertStringContainsString('/assets/img/n8n.svg', $s['html']);
    }

    public function testUnSeulDocumentNeDitPasLeReste(): void
    {
        $saut = array_column(self::sortie()['saut']['view']['groups'], null, 'kind');
        $this->assertSame('', $saut['docs']['more']);
        $this->assertStringNotContainsString('sd-more', self::sortie()['saut']['html']);
        // Fichiers choisis à la création : pas encore envoyés.
        $creation = array_column(self::sortie()['creation']['view']['groups'], null, 'kind');
        $this->assertSame(AssistantDiagram::labels()['doc_new'], $creation['docs']['items'][0]['note']);
    }

    public function testEtatEtPartage(): void
    {
        $s = self::sortie();
        $L = AssistantDiagram::labels();
        $this->assertStringContainsString('<span class="sd-status is-on" data-target="active">' . $L['active'] . '</span>', $s['complet']['html']);
        $this->assertSame([$L['org']], array_column($s['complet']['view']['assistant']['traits'], 'text'));
        $this->assertSame([$L['personal']], array_column($s['defaut']['view']['assistant']['traits'], 'text'));
        $this->assertSame([$L['global']], array_column($s['ignoree']['view']['assistant']['traits'], 'text'));
        $this->assertStringContainsString('<ul class="sd-traits"><li class="sd-trait"><span class="sd-trait-ic"', $s['complet']['html'], 'Le partage ne se règle pas sur la fiche : pas de cible.');

        // Inactif : couleurs éteintes, traits pointillés, pastille ambre.
        $this->assertStringContainsString('<div class="sd is-paused"', $s['vide']['html']);
        $this->assertStringContainsString('<span class="sd-status is-off" data-target="active">' . $L['inactive'] . '</span>', $s['vide']['html']);
    }

    public function testPastilleAInitialeEtTeinteLisible(): void
    {
        $s = self::sortie();
        // Sans avatar : la pastille de sa couleur, à son initiale.
        $this->assertStringContainsString('<span class="sd-ava is-dot has-initial" aria-hidden="true"><span style="background:#123456">É</span></span>', $s['defaut']['html']);
        $this->assertStringContainsString('--sd-assistant:#123456', $s['defaut']['html']);
        // Couleur presque noire (celle proposée à la création) : le nœud prend
        // l'accent, la pastille garde la vraie couleur.
        $this->assertSame('', self::sortie()['tint']);
        $this->assertStringNotContainsString('--sd-assistant', $s['vide']['html']);
        $this->assertStringContainsString('background:#1e293b', $s['vide']['html']);
        // Avec avatar : son image.
        $this->assertStringContainsString('<span class="sd-ava"><img src="/assets/img/profils/alfred-loupe.png" alt=""></span>', $s['complet']['html']);
    }

    public function testToutTexteEstEchappeEtAucuneImageNeVientDAilleurs(): void
    {
        $html = self::sortie()['hostile']['html'];
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html, 'Le nom d’une base est échappé.');
        $this->assertStringContainsString('Veille &lt;b&gt;marché&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('pixel.example', $html, 'Avatar d’une autre origine : jamais chargé.');
        $this->assertStringNotContainsString('url(x)', $html, 'Une couleur hors #RRGGBB n’entre jamais dans un style.');
        $this->assertStringNotContainsString('evil.example', $html, 'Un logo « //hôte » n’est pas un chemin local.');
    }

    public function testInteractifSeulementQuandLaFichePasseLesIntitules(): void
    {
        $s = self::sortie();
        $saut = $s['saut']['html'];
        $this->assertStringContainsString('<div class="sd is-interactive"', $saut);
        // Un bouton par nœud nommé dans `jump` et présent : modèle, assistant, bases, documents, outils.
        $this->assertSame(5, substr_count($saut, 'class="sd-jump"'));
        $this->assertStringContainsString('<button type="button" class="sd-jump" data-target="kbs" title="Modifier dans « Connaissances »" aria-label="Modifier dans « Connaissances »">', $saut);
        $this->assertDoesNotMatchRegularExpression('/<div class="sd-node[^>]*role="button"/', $saut);

        // Rien à relier : le nœud vide mène aux connaissances.
        $vide = $s['saut_vide']['html'];
        $this->assertStringContainsString('class="sd-node sd-empty" data-node="source" data-target="knowledge"', $vide);
        $this->assertStringContainsString('<button type="button" class="sd-jump" data-target="knowledge"', $vide);

        // La liste : ni bouton ni classe interactive, les cibles restent inertes.
        $liste = $s['complet']['html'];
        $this->assertStringNotContainsString('is-interactive', $liste);
        $this->assertStringNotContainsString('sd-jump', $liste);
        $this->assertStringContainsString('data-target="kbs"', $liste);
    }

    public function testLesCiblesDuSchemaSontCellesQueLaFicheSaitResoudre(): void
    {
        $s = self::sortie();
        $cibles = [];
        foreach (['complet', 'saut_vide'] as $k) {
            preg_match_all('/data-target="(\w+)"/', $s[$k]['html'], $m);
            $cibles = array_merge($cibles, $m[1]);
        }
        $cibles = array_values(array_unique($cibles));
        sort($cibles);

        $this->assertSame(1, preg_match('/const JUMP_TARGETS = \{(.*?)\n        \};/s', self::src(self::FORM), $bloc), 'JUMP_TARGETS introuvable dans la fiche.');
        preg_match_all('/^\s+(\w+):\s+\{ section:/m', $bloc[1], $connues);
        $resolues = $connues[1];
        sort($resolues);
        $this->assertSame($cibles, $resolues, 'Chaque cible du schéma a son réglage dans la fiche ; aucune entrée sans cible.');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeScriptNAAucunTexteEnDur(): void
    {
        $code = self::src(self::JS);
        $code = (string)preg_replace('#/\*.*?\*/#s', '', $code);
        $lignes = array_filter(
            array_map(static fn(string $l): string => (string)preg_replace('#(^|\s)//\s.*$#', '', $l), explode("\n", $code)),
            static fn(string $l): bool => trim($l) !== ''
        );
        foreach ($lignes as $ligne) {
            $this->assertDoesNotMatchRegularExpression('/[À-ÿ’«»]/u', $ligne, "Texte en dur dans le schéma d'un assistant : « $ligne » — il vient du catalogue (AssistantDiagram::labels).");
        }
    }

    public function testLeModeleSeBrancheSurLeMoteurPartage(): void
    {
        $modele = self::src(self::JS);
        $this->assertStringContainsString('const E = root.MiaDiagram;', $modele);
        $this->assertStringContainsString('html: E.html,', $modele);
        $this->assertStringContainsString('mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); }', $modele);
        $this->assertStringContainsString('root.MiaAssistantDiagram = api;', $modele);
        foreach (['function html(', 'function draw(', 'function routes(', 'function mount('] as $fonction) {
            $this->assertStringNotContainsString($fonction, $modele, "Le modèle de l'assistant redéfinit $fonction : un second rendu divergerait.");
        }
        $this->assertStringContainsString('trigger: null,', $modele, 'Un assistant n’a pas de déclencheur.');
    }

    public function testLesPagesChargentLeMoteurPuisLeModeleAvantLeurScript(): void
    {
        foreach ([self::LIST => 'window.ASSISTANT_DIAGRAMS', self::FORM => 'window.ASSISTANT_FORM_DIAGRAM'] as $gabarit => $global) {
            $html = self::src($gabarit);
            $moteur = strpos($html, '<script src="/assets/js/diagram.js');
            $modele = strpos($html, '<script src="/assets/js/assistant-diagram.js');
            $page   = strpos($html, "<script>\n(function () {");
            $this->assertNotFalse($moteur, "$gabarit ne charge pas diagram.js.");
            $this->assertNotFalse($modele, "$gabarit ne charge pas assistant-diagram.js.");
            $this->assertNotFalse($page);
            $this->assertLessThan($modele, $moteur, "$gabarit : le moteur avant le modèle, qui s'y branche.");
            $this->assertLessThan($page, $modele, "$gabarit : le modèle avant le script de la page, qui s'en sert aussitôt.");
            // Chargés sans defer : le script de la page, en fin de document, s'exécute avant tout script différé.
            $this->assertDoesNotMatchRegularExpression('#<script src="/assets/js/(diagram|assistant-diagram)\.js[^"]*" defer#', $html);
            $this->assertStringContainsString($global . ' = <?= json_encode($diagram, ', $html);
            $this->assertStringContainsString('JSON_HEX_TAG', $html, 'Les icônes voyagent dans un <script> : < et > échappés.');
            foreach (['id="pfDiagramPanel"', 'id="pfDiagram"', 'id="pfDiagramTitle"', 'class="admin-content pf-content"'] as $marque) {
                $this->assertStringContainsString($marque, $html, "$gabarit : $marque absent.");
            }
        }
        $this->assertStringContainsString('Configuration de l\'assistant', self::src(self::LIST));
        // Texte traduit (te()) depuis le passage FR/EN de la fiche.
        $this->assertStringContainsString("te('Aperçu de l\\'assistant')", self::src(self::FORM));
        $this->assertStringContainsString('class="sched-diagram-hint"', self::src(self::FORM), 'Le panneau dit qu’un élément se clique.');
    }

    public function testLesReglagesVisesExistentDansLaFiche(): void
    {
        $tpl = self::src(self::FORM);
        $this->assertSame(1, preg_match('/const JUMP_TARGETS = \{(.*?)\n        \};/s', $tpl, $bloc));
        preg_match_all("/byId\\('(mp\\w+)'\\)/", $bloc[1], $ids);
        $this->assertNotEmpty($ids[1]);
        foreach (array_unique($ids[1]) as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $tpl, "Réglage visé absent de la fiche : #$id");
        }
        $this->assertStringContainsString('class="mp-active-toggle"', $tpl);
        foreach (['mpToolsGroup', 'mpN8nGroup', 'mpGammaGroup'] as $groupe) {
            $this->assertStringContainsString("'$groupe'", $tpl);
        }
        // Le geste : défilement doux sauf préférence contraire, projecteur, focus sans second défilement.
        $this->assertStringContainsString("el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });", $tpl);
        $this->assertStringContainsString("el.classList.add('is-spotlit');", $tpl);
        $this->assertStringContainsString('target.focus({ preventScroll: true });', $tpl);
        $this->assertStringContainsString("{ animate: first, jump: jumpTitles }", $tpl);
        // Les intitulés viennent des titres des sections.
        foreach (['mpSectionIdentity', 'mpSectionBehavior', 'mpSectionKnowledge'] as $section) {
            $this->assertStringContainsString("sectionTitle('$section')", $tpl);
            $this->assertStringContainsString('id="' . $section . '"', $tpl);
        }
    }

    public function testLaFicheSuitSesChampsEtSesDocuments(): void
    {
        $tpl = self::src(self::FORM);
        // Tout geste de la page, interrupteur « Actif » (hors du formulaire) compris.
        $this->assertStringContainsString("document.addEventListener('input', render);", $tpl);
        $this->assertStringContainsString("document.addEventListener('change', render);", $tpl);
        // Les documents : la liste de la fiche prévient le schéma à chaque rendu.
        $this->assertStringContainsString("document.dispatchEvent(new CustomEvent('mia:profile-docs', { detail: docs || [] }));", $tpl);
        $this->assertStringContainsString("document.addEventListener('mia:profile-docs'", $tpl);
        $this->assertStringContainsString('pending_create: true', $tpl, 'En création, les fichiers choisis.');
        // Une base désactivée (système) n'est pas envoyée : le schéma la tient des liaisons fixes.
        $this->assertStringContainsString('.filter((i) => i.checked && !i.disabled)', $tpl);
        $this->assertStringContainsString('fixed_kb_ids: conf.fixed_kb_ids || []', $tpl);
        // « Défaut application » consulte la température du modèle par défaut.
        $this->assertStringContainsString("\$llmTempSupport[''] = ", $tpl);
    }

    public function testLaListeGardeSesCommandesEtSonRepli(): void
    {
        $tpl = self::src(self::LIST);
        $this->assertStringContainsString('<a class="al-name-link" href="<?= htmlspecialchars($editUrl) ?>">', $tpl, 'Sans JavaScript, le nom mène à la fiche.');
        $this->assertStringContainsString('id="assistant-<?= $pid ?>"', $tpl);
        $this->assertStringContainsString("e.target.closest('button, a, form, label, input, select, textarea')", $tpl);
        $this->assertStringContainsString('if (!listDiagram) {', $tpl, 'Sans le module, la ligne ouvre la fiche comme avant.');
        $this->assertStringContainsString('listDiagram?.refresh();', $tpl);
        $this->assertSame(2, substr_count($tpl, 'listDiagram?.refresh();'), 'Après un filtre ET après un tri.');
        $this->assertStringContainsString("/^#assistant-(\\d+)$/", $tpl);
    }

    public function testLeControleurFournitCatalogueEtConfigurations(): void
    {
        $c = self::src('src/Controllers/AdminController.php');
        $this->assertSame(2, substr_count($c, 'AssistantDiagram::catalog('), 'Liste ET fiche.');
        $this->assertStringContainsString('AssistantDiagram::spec(', $c);
        $this->assertStringContainsString("array_map([AssistantDiagram::class, 'docEntry'], ProfileDocument::allForProfile(\$pid))", $c, 'Un document a la même forme dans la liste et dans la fiche.');
        $this->assertStringContainsString("'fixed_kb_ids' =>", $c);
        // La liste s'ouvre sur l'assistant créé — sauf souci de documents — et sur l'assistant basculé.
        $this->assertStringContainsString("header('Location: /admin/profiles' . (\$problems ? '' : '#assistant-' . \$id));", $c);
        $this->assertStringContainsString("header('Location: /admin/profiles#assistant-' . \$profileId);", $c);
        $this->assertStringContainsString("'/admin/profiles#assistant-' . (int)\$profile['id']", self::src(self::FORM), 'Le retour à la liste rouvre l’assistant.');

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Assistants/AssistantDiagram.php', self::src($liste), "$liste ne charge pas AssistantDiagram.");
        }
    }

    public function testLeSeuilCoteACoteEstLeMemePourLeStyleEtLeScript(): void
    {
        $css = self::src(self::CSS);
        $this->assertStringContainsString("window.matchMedia('(min-width: 1200px)')", self::src(self::LIST));
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{\s*\.pf-split\.is-open \{/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{.*?\.admin-content\.pf-content \{ overflow: visible; \}/s', $css, 'Sans cela, le sticky ne bouge jamais (overflow de .admin-content).');
        $this->assertMatchesRegularExpression('/\.pf-split > \.sched-diagram-panel,\s*\.pf-form-layout > \.sched-diagram-panel \{\s*position: sticky;/', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1199px\) \{\s*\.pf-split \.sched-diagram-close \{ display: inline-grid; \}/', $css);
        // Le schéma sans déclencheur, la teinte des documents, la pastille à initiale.
        $this->assertMatchesRegularExpression('/\.sd-canvas\.no-trigger \{\s*grid-template-areas:\s*"llm\s+assistant"\s*"sources sources";/', $css);
        $this->assertMatchesRegularExpression('/\.sd-docs\s+\{[^}]*--sd-hue/', $css);
        $this->assertStringContainsString('.sd-ava.is-dot.has-initial > span', $css);
        $this->assertStringContainsString('.sd-canvas.no-trigger { grid-template-areas: "assistant" "llm" "sources"; }', $css, 'Colonne unique, sans déclencheur.');
        $this->assertStringContainsString('.pf-content .is-spotlit { animation: sched-spot', $css);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationLeDit(): void
    {
        $this->assertStringContainsString('### Lire le schéma d\'un assistant', self::src('docs/GUIDE-CREATEUR.md'));
        $this->assertStringContainsString('## Lire le schéma d\'un assistant', self::src('docs/user/fonctionnalites/assistants/creer-un-assistant.md'));
        $this->assertStringContainsString('schéma', self::src('docs/user/admin/assistants/gerer-les-assistants.md'));
        $this->assertStringContainsString('schéma de l\'assistant', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('id: assistants-schema', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('AssistantDiagram', self::src('CLAUDE.md'));
    }
}
