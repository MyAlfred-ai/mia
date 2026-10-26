<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Schéma d'une tâche programmée — Espaces → Tâches programmées : à droite
 * de la liste (tâche cliquée) et du formulaire (tenu à jour pendant la saisie).
 *
 * Trois étages vérifiés :
 *  - les DONNÉES du serveur (TaskDiagram : configuration d'une tâche,
 *    libellés traduits, icônes de l'application) ;
 *  - le RENDU, exécuté sous node avec les vrais modules (diagram.js, le
 *    moteur partagé avec le schéma d'un assistant, puis schedule-diagram.js) :
 *    modèle résolu comme au tour réel, bases et connecteurs regroupés chacun
 *    dans UN nœud, tout texte échappé, aucune image d'une autre origine ;
 *  - le CÂBLAGE : un schéma jamais posé, un script chargé dans le mauvais
 *    ordre ou un seuil de mise en page qui diverge ne lèveraient aucune
 *    erreur — le schéma serait simplement absent ou faux.
 *
 * Sans node, la partie rendu est SKIPPÉE ; elle tourne sur le poste de
 * développement et en CI.
 */
final class ScheduleDiagramTest extends TestCase
{
    private const JS     = 'public/assets/js/schedule-diagram.js';
    private const ENGINE = 'public/assets/js/diagram.js';

    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    protected function setUp(): void
    {
        I18n::use('fr');
    }

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

    public function testLaConfigurationDUneTacheALaFormeDuFormulaire(): void
    {
        $spec = TaskDiagram::spec([
            'name'          => '  Point du lundi ',
            'instruction'   => str_repeat('a', TaskDiagram::INSTRUCTION_EXCERPT + 40),
            'profile_id'    => 0,
            'llm'           => 'openai::gpt-5.6',
            'sources'       => ['web', 'gmail'],
            'kb_ids'        => ['4', 7],
            'tool_ids'      => ['2'],
            'allow_actions' => '1',
            'destination'   => 'n’importe quoi',
            'notify'        => false,
        ], ['sentence' => 'Chaque lundi à 8 h 00', 'next' => 'lun. 5 oct.', 'active' => true]);

        $this->assertSame('Point du lundi', $spec['name']);
        $this->assertNull($spec['profile_id'], 'Aucun assistant : null, jamais 0.');
        $this->assertSame([4, 7], $spec['kb_ids']);
        $this->assertSame([2], $spec['tool_ids']);
        $this->assertTrue($spec['allow_actions']);
        $this->assertFalse($spec['notify']);
        $this->assertSame(ScheduledTask::DEST_THREAD, $spec['destination'], 'Destination inconnue : à la suite (le défaut).');
        $this->assertSame(TaskDiagram::INSTRUCTION_EXCERPT + 1, mb_strlen($spec['instruction']), 'Consigne abrégée, « … » compris.');
        $this->assertStringEndsWith('…', $spec['instruction']);
        $this->assertTrue($spec['trigger']['active']);
        $this->assertSame('', $spec['trigger']['pause']);
    }

    public function testUneTacheEnregistreeDonneSonDeclencheur(): void
    {
        $rule = ['freq' => Recurrence::WEEKLY, 'days' => [1, 4], 'time' => '08:00'];
        $tache = [
            'name' => 'Point', 'instruction' => 'Fais le point.', 'profile_id' => 12, 'llm' => '',
            'sources_list' => ['gmail'], 'kb_list' => [3], 'tool_list' => [], 'allow_actions' => 0,
            'destination' => ScheduledTask::DEST_NEW, 'notify' => 1, 'rule' => $rule, 'timezone' => 'Europe/Paris',
            'status' => 'active', 'pause_reason' => '', 'next_run_at' => '2026-10-05 06:00:00',
        ];

        $spec = TaskDiagram::specFromTask($tache);
        $this->assertSame(12, $spec['profile_id']);
        $this->assertSame(['gmail'], $spec['sources']);
        $this->assertSame([3], $spec['kb_ids']);
        $this->assertSame(ScheduledTask::DEST_NEW, $spec['destination']);
        $this->assertSame(Recurrence::describe($rule), $spec['trigger']['sentence']);
        $this->assertMatchesRegularExpression('/^lun\. 5 oct\. .*8\s*h\s*00$/u', $spec['trigger']['next'], 'Prochaine exécution à l’heure de la tâche (06:00 UTC = 8 h à Paris).');
        $this->assertStringContainsString('Paris', $spec['trigger']['zone']);
        $this->assertNotSame('', $spec['trigger']['per_month']);

        // En pause : ni prochaine exécution, ni raison quand c'est la personne qui l'a voulue.
        $pause = TaskDiagram::specFromTask(['status' => 'paused', 'pause_reason' => ScheduledTask::PAUSE_USER] + $tache);
        $this->assertFalse($pause['trigger']['active']);
        $this->assertSame('', $pause['trigger']['next']);
        $this->assertSame('', $pause['trigger']['pause']);

        // Pause imposée : la raison est dite.
        $echecs = TaskDiagram::specFromTask(['status' => 'paused', 'pause_reason' => ScheduledTask::PAUSE_FAILURES] + $tache);
        $this->assertSame(ScheduledTask::reasonLabel(ScheduledTask::PAUSE_FAILURES), $echecs['trigger']['pause']);
    }

    public function testAgirSansValidationMontreCeQuiSePasseraVraiment(): void
    {
        $trigger = ['sentence' => 'Chaque jour à 8 h 00', 'active' => true];

        // Le radio du formulaire vaut 'auto' ; les emails ne partent qu'en ce mode.
        $auto = TaskDiagram::spec(['allow_actions' => ScheduledTask::MODE_AUTO, 'auto_send_mail' => '1'], $trigger);
        $this->assertTrue($auto['allow_actions']);
        $this->assertTrue($auto['auto_actions']);
        $this->assertTrue($auto['auto_send_mail']);
        $approuver = TaskDiagram::spec(['allow_actions' => '1', 'auto_send_mail' => '1'], $trigger);
        $this->assertFalse($approuver['auto_actions']);
        $this->assertFalse($approuver['auto_send_mail'], 'Les emails ne partent qu’en « Agir sans validation ».');
        $lecture = TaskDiagram::spec(['allow_actions' => '', 'auto_actions' => true], $trigger);
        $this->assertFalse($lecture['auto_actions'], 'En lecture seule, rien n’agit.');

        // Tâche enregistrée : l'administrateur a le dernier mot.
        $tache = [
            'name' => 'Relances', 'instruction' => 'Relance les devis.', 'profile_id' => null, 'llm' => '',
            'sources_list' => ['gmail'], 'kb_list' => [], 'tool_list' => [], 'allow_actions' => true,
            'auto_actions' => true, 'auto_send_mail' => true, 'destination' => ScheduledTask::DEST_THREAD,
            'notify' => 1, 'rule' => ['freq' => Recurrence::DAILY, 'time' => '08:00'], 'timezone' => 'Europe/Paris',
            'status' => 'active', 'pause_reason' => '', 'next_run_at' => '2026-10-05 06:00:00',
        ];
        $spec = TaskDiagram::specFromTask($tache);
        $this->assertTrue($spec['auto_actions']);
        $this->assertTrue($spec['auto_send_mail']);
        $retire = TaskDiagram::specFromTask($tache, false);
        $this->assertTrue($retire['allow_actions']);
        $this->assertFalse($retire['auto_actions'], 'Option retirée : la tâche dépose des cartes — le schéma montre ce qui se passera.');
        $this->assertFalse($retire['auto_send_mail']);
    }

    public function testLesLibellesSontTraduitsCoteServeur(): void
    {
        $fr = TaskDiagram::labels();
        foreach ($fr as $cle => $texte) {
            $this->assertNotSame('', trim($texte), "Libellé vide : $cle");
        }
        $this->assertSame('Déclencheur', $fr['trigger']);
        $this->assertStringContainsString('{date}', $fr['next'], 'Le script pose la date dans « Prochaine : {date} ».');

        I18n::use('en');
        $en = TaskDiagram::labels();
        $this->assertSame(array_keys($fr), array_keys($en));
        $this->assertSame('Trigger', $en['trigger']);
        $this->assertSame('Next: {date}', $en['next']);
        $this->assertNotSame($fr['no_sources'], $en['no_sources']);
    }

    public function testLesIconesSontCellesDeLApplication(): void
    {
        $icons = TaskDiagram::icons();
        $this->assertSame(ScheduledTask::icon(15), $icons['trigger'], 'Le déclencheur porte l’horloge des tâches programmées.');
        $this->assertSame(AdminMenu::icon('profiles', 15), $icons['assistant']);
        $this->assertSame(AdminMenu::icon('llms', 15), $icons['model']);
        $this->assertSame(AdminMenu::icon('connectors', 15), $icons['connectors']);
        $this->assertSame(AdminMenu::icon('kb', 15), $icons['kbs']);
        $this->assertSame(AdminMenu::icon('tools', 15), $icons['tools']);
        $this->assertStringContainsString('/assets/img/mybrain.png', $icons['kb_live'], 'Une base en direct porte l’image du cerveau, comme dans le formulaire.');
    }

    public function testLeCatalogueReprendLesIconesDesConnecteursEtDesOutils(): void
    {
        $source = self::src('src/Schedule/TaskDiagram.php');
        // Connecteurs : l'icône du registre, jamais redessinée.
        $this->assertStringContainsString("ConnectorRegistry::chatCatalog()", $source);
        $this->assertStringContainsString("'icon'  => (string)\$row['icon']", $source);
        // Outils : un pictogramme par type qu'une tâche peut engager.
        foreach (ScheduledTask::TOOL_TYPES as $type) {
            $this->assertMatchesRegularExpression("/'" . preg_quote($type, '/') . "'\\s*=>\\s*'</", $source, "Pictogramme absent pour l'outil $type.");
        }
        $this->assertStringContainsString('/assets/img/n8n.svg', $source);
        $this->assertStringContainsString('/assets/img/gamma.svg', $source);
        // Logos des fournisseurs : ceux du sélecteur de modèle du chat.
        $this->assertStringContainsString('LLMFactory::logos()', $source);
        // Le défaut est celui du tour réel, parmi les modèles permis.
        $this->assertStringContainsString("LLMFactory::appDefault(\$choices['llms'])", $source);
    }

    // ------------------------------------------------------------------
    // Rendu, exécuté sous node
    // ------------------------------------------------------------------

    /** Catalogue de test : les vrais libellés et icônes, des fiches inventées. */
    private static function catalogue(): array
    {
        return [
            'labels'       => TaskDiagram::labels(),
            'icons'        => TaskDiagram::icons(),
            'tool_icons'   => ['image' => '<svg data-t="image"></svg>', 'n8n' => '<img src="/assets/img/n8n.svg" alt="">'],
            'mia'          => ['name' => 'MIA', 'avatar' => '/assets/img/logo.png'],
            'assistants'   => [
                '7' => ['name' => 'Veille <b>marché</b>', 'avatar' => '/assets/img/profils/alfred-loupe.png', 'color' => '#8b5cf6',
                        'description' => 'Suit vos <script>alert(1)</script> clients.', 'llm' => 'anthropic::claude-opus-5',
                        'kbs' => [['id' => 1, 'name' => 'Fiches clients', 'live' => false]]],
                '8' => ['name' => 'RH', 'avatar' => 'https://pixel.example/p.png', 'color' => 'red;background:url(x)',
                        'description' => '', 'llm' => '', 'kbs' => [['id' => 9, 'name' => 'Cerveau', 'live' => true]]],
            ],
            'llms'         => [
                'anthropic::claude-opus-5' => ['label' => 'Claude Opus 5', 'provider' => 'Claude (Anthropic)', 'logo' => '/assets/img/llm-anthropic.png'],
                'openai::gpt-5.6'          => ['label' => 'GPT-5.6 Sol', 'provider' => 'OpenAI', 'logo' => '/assets/img/llm-openai.png'],
                'mistral::mistral-large'   => ['label' => 'Mistral Large 3', 'provider' => 'Mistral', 'logo' => '//evil.example/x.png'],
            ],
            'allowed_llms' => ['openai::gpt-5.6', 'mistral::mistral-large'],
            'default_llm'  => 'openai::gpt-5.6',
            'sources'      => [
                'web'   => ['label' => 'Recherche Internet', 'icon' => '<svg data-c="web"></svg>', 'issue' => ''],
                'gmail' => ['label' => 'Gmail', 'icon' => '<img src="/assets/img/gmail.svg" alt="">', 'issue' => 'Gmail à reconnecter'],
            ],
            'kbs'          => ['1' => ['name' => 'Fiches clients', 'live' => false], '4' => ['name' => 'Études 2026', 'live' => false]],
            'tools'        => ['2' => ['name' => 'Illustrations', 'type' => 'image', 'kind' => 'Image']],
        ];
    }

    private static function trigger(bool $active = true): array
    {
        return ['sentence' => 'Chaque lundi à 8 h 00', 'next' => 'lun. 5 oct. à 8 h 00', 'zone' => 'heure de Paris',
                'per_month' => 'Environ 4 exécutions par mois', 'active' => $active, 'pause' => $active ? '' : 'trois échecs d’affilée'];
    }

    /** Scénarios : nom → configuration de tâche. */
    private static function scenarios(): array
    {
        $base = ['name' => 'Point du lundi', 'instruction' => 'Fais le point <i>vite</i>.', 'llm' => '', 'sources' => [], 'kb_ids' => [],
                 'tool_ids' => [], 'allow_actions' => false, 'destination' => 'thread', 'notify' => true];
        return [
            'assistant'     => ['profile_id' => 7, 'sources' => ['web', 'gmail'], 'kb_ids' => [1, 4], 'tool_ids' => [2], 'allow_actions' => true, 'trigger' => self::trigger()] + $base,
            'assistant_def' => ['profile_id' => 8, 'trigger' => self::trigger()] + $base,
            'mia_choisi'    => ['profile_id' => null, 'llm' => 'mistral::mistral-large', 'destination' => 'new', 'trigger' => self::trigger()] + $base,
            'mia_refuse'    => ['profile_id' => null, 'llm' => 'anthropic::claude-opus-5', 'trigger' => self::trigger()] + $base,
            'disparu'       => ['profile_id' => 99, 'trigger' => self::trigger(false)] + $base,
            'sans_regle'    => ['profile_id' => null, 'name' => '', 'trigger' => ['sentence' => '', 'active' => true]] + $base,
            'sans_valid'    => ['profile_id' => null, 'allow_actions' => true, 'auto_actions' => true, 'auto_send_mail' => true, 'notify' => false, 'trigger' => self::trigger()] + $base,
            'sans_valid_br' => ['profile_id' => null, 'allow_actions' => true, 'auto_actions' => true, 'auto_send_mail' => false, 'trigger' => self::trigger()] + $base,
            'auto_seul'     => ['profile_id' => null, 'allow_actions' => false, 'auto_actions' => true, 'auto_send_mail' => true, 'trigger' => self::trigger()] + $base,
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

        $script = "'use strict';\n"
            . (string)file_get_contents(ROOT . '/' . self::ENGINE) . "\n"
            . (string)file_get_contents(ROOT . '/' . self::JS) . "\n"
            . 'const D = globalThis.MiaScheduleDiagram;' . "\n"
            . 'const CAT = ' . json_encode(self::catalogue()) . ";\n"
            . 'const SC = ' . json_encode(self::scenarios()) . ";\n"
            . 'const out = { domTouched: typeof document !== "undefined" };' . "\n"
            . 'for (const [k, spec] of Object.entries(SC)) { const v = D.model(spec, CAT); out[k] = { view: v, html: D.html(v) }; }' . "\n"
            . 'process.stdout.write(JSON.stringify(out));';

        $fichier = tempnam(sys_get_temp_dir(), 'mia-schema-js-');
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
            self::fail("schedule-diagram.js a échoué sous node (code $code) :\n" . $erreurs);
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

    public function testLeModeleEstCeluiDuTourReel(): void
    {
        $s = self::sortie();
        // L'assistant impose le sien.
        $this->assertSame('Claude Opus 5', $s['assistant']['view']['llm']['label']);
        $this->assertSame(TaskDiagram::labels()['llm_imposed'], $s['assistant']['view']['llm']['note']);
        // Un assistant sans modèle : le défaut de l'application.
        $this->assertSame('GPT-5.6 Sol', $s['assistant_def']['view']['llm']['label']);
        $this->assertSame(TaskDiagram::labels()['llm_default'], $s['assistant_def']['view']['llm']['note']);
        // Sans assistant : celui de la tâche s'il est permis…
        $this->assertSame('Mistral Large 3', $s['mia_choisi']['view']['llm']['label']);
        $this->assertSame(TaskDiagram::labels()['llm_chosen'], $s['mia_choisi']['view']['llm']['note']);
        // … sinon le défaut, comme scheduledTurnLlm.
        $this->assertSame('GPT-5.6 Sol', $s['mia_refuse']['view']['llm']['label']);
        // Assistant disparu : rien d'inventé.
        $this->assertSame(TaskDiagram::labels()['llm_unknown'], $s['disparu']['view']['llm']['label']);
        $this->assertSame('missing', $s['disparu']['view']['assistant']['kind']);
    }

    public function testBasesEtConnecteursOccupentChacunUnSeulNoeud(): void
    {
        $s = self::sortie()['assistant'];
        $groupes = array_column($s['view']['groups'], null, 'kind');
        $this->assertSame(['connectors', 'kbs', 'tools'], array_keys($groupes));
        $this->assertSame(1, substr_count($s['html'], 'sd-kbs'), 'Toutes les bases dans UN nœud.');
        $this->assertSame(1, substr_count($s['html'], 'sd-connectors'), 'Tous les connecteurs dans UN nœud.');

        // Bases de l'assistant d'abord, sans doublon avec celles de la tâche.
        $kbs = array_column($groupes['kbs']['items'], 'label');
        $this->assertSame(['Fiches clients', 'Études 2026'], $kbs);
        $this->assertSame(TaskDiagram::labels()['via_assistant'], $groupes['kbs']['items'][0]['note']);

        // Un connecteur à reconnecter le dit.
        $gmail = $groupes['connectors']['items'][1];
        $this->assertTrue($gmail['warn']);
        $this->assertSame('Gmail à reconnecter', $gmail['note']);

        // Une base en direct porte l'image du cerveau.
        $rh = array_column(self::sortie()['assistant_def']['view']['groups'], null, 'kind')['kbs']['items'][0];
        $this->assertStringContainsString('mybrain.png', $rh['icon']);
    }

    public function testChaqueNoeudPorteSaFlecheVersLAssistant(): void
    {
        $html = self::sortie()['assistant']['html'];
        $this->assertSame(1, substr_count($html, 'data-node="assistant"'));
        $this->assertStringContainsString('data-node="trigger" data-edge="down"', $html);
        $this->assertStringContainsString('data-node="llm" data-edge="side"', $html);
        $this->assertSame(3, substr_count($html, 'data-edge="up"'), 'Une flèche par nœud du bas.');

        // Ni connecteur ni base : un nœud qui le dit, sans flèche depuis le vide.
        $vide = self::sortie()['mia_choisi']['html'];
        $this->assertStringContainsString('sd-empty', $vide);
        $this->assertStringNotContainsString('data-edge="up"', $vide);
    }

    public function testToutTexteEstEchappeEtAucuneImageNeVientDAilleurs(): void
    {
        $html = self::sortie()['assistant']['html'];
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('Veille &lt;b&gt;marché&lt;/b&gt;', $html);
        $this->assertStringContainsString('--sd-assistant:#8b5cf6', $html, 'La couleur de l’assistant teinte son nœud.');

        $rh = self::sortie()['assistant_def']['html'];
        $this->assertStringNotContainsString('pixel.example', $rh, 'Avatar d’une autre origine : jamais chargé.');
        $this->assertStringNotContainsString('url(x)', $rh, 'Une couleur hors #RRGGBB n’entre jamais dans un style.');

        $mistral = self::sortie()['mia_choisi']['html'];
        $this->assertStringNotContainsString('evil.example', $mistral, 'Un logo « //hôte » n’est pas un chemin local.');
    }

    public function testCaracteristiquesEtEtatDuDeclencheur(): void
    {
        $s = self::sortie();
        $L = TaskDiagram::labels();
        $traits = array_column($s['assistant']['view']['assistant']['traits'], 'text');
        $this->assertSame([$L['actions'], $L['dest_thread'], $L['notify']], $traits);
        $this->assertSame($L['dest_new'], $s['mia_choisi']['view']['assistant']['traits'][1]['text']);

        $this->assertStringContainsString('Prochaine : lun. 5 oct. à 8 h 00', $s['assistant']['html']);
        // En pause : pas de prochaine exécution, la raison est dite, les traits pointillés.
        $this->assertStringContainsString('sd is-paused', $s['disparu']['html']);
        $this->assertStringNotContainsString('Prochaine', $s['disparu']['html']);
        $this->assertStringContainsString('trois échecs d’affilée', $s['disparu']['html']);
        // Sans règle ni nom : ce qui manque est dit.
        $this->assertSame($L['rule_missing'], $s['sans_regle']['view']['trigger']['title']);
        $this->assertSame($L['untitled'], $s['sans_regle']['view']['name']);
        // La partie pure ne touche jamais au DOM.
        $this->assertFalse($s['domTouched']);
    }

    public function testLeSchemaDitQuandLaTacheAgitSansValidation(): void
    {
        $s = self::sortie();
        $L = TaskDiagram::labels();
        $traits = $s['sans_valid']['view']['assistant']['traits'];
        $this->assertSame([$L['auto'], $L['auto_send'], $L['dest_thread']], array_column($traits, 'text'));
        $this->assertSame(['auto', 'auto', ''], array_column($traits, 'tone'));
        $this->assertStringContainsString('sd-trait is-auto', $s['sans_valid']['html']);
        // Emails en brouillon : le trait d'envoi n'apparaît pas.
        $this->assertSame([$L['auto'], $L['dest_thread'], $L['notify']], array_column($s['sans_valid_br']['view']['assistant']['traits'], 'text'));
        // Sans actions, un drapeau « auto » égaré ne fait rien agir.
        $this->assertSame($L['read_only'], $s['auto_seul']['view']['assistant']['traits'][0]['text']);
        $this->assertCount(3, $s['auto_seul']['view']['assistant']['traits']);
        // Une teinte propre, celle du badge de la liste.
        $this->assertStringContainsString('.sd-trait.is-auto', self::src('public/assets/css/main.css'));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeScriptNAAucunTexteEnDur(): void
    {
        // Le moteur ET le modèle de la tâche : tout ce qui s'affiche vient de la vue, donc du catalogue.
        foreach ([self::ENGINE, self::JS] as $fichier) {
            $code = self::src($fichier);
            $code = (string)preg_replace('#/\*.*?\*/#s', '', $code);
            $lignes = array_filter(
                array_map(static fn(string $l): string => (string)preg_replace('#(^|\s)//\s.*$#', '', $l), explode("\n", $code)),
                static fn(string $l): bool => trim($l) !== ''
            );
            foreach ($lignes as $ligne) {
                $this->assertDoesNotMatchRegularExpression('/[À-ÿ’«»]/u', $ligne, "Texte en dur dans $fichier : « $ligne » — il vient du catalogue (TaskDiagram::labels).");
            }
            $this->assertStringNotContainsString(' t(', $code, "$fichier : aucun libellé traduit côté navigateur, le catalogue les porte déjà.");
        }
    }

    public function testLeMoteurEstPartageEtLeModeleSyBranche(): void
    {
        $moteur = self::src(self::ENGINE);
        $modele = self::src(self::JS);
        // Le rendu vit dans le moteur seul : balisage, flèches, pose dans la page.
        foreach (['function html(view)', 'function draw(host)', 'function mount(host, view, opts)', 'function routes('] as $fonction) {
            $this->assertStringContainsString($fonction, $moteur);
            $this->assertStringNotContainsString($fonction, $modele, "Le modèle de la tâche redéfinit $fonction : un second rendu divergerait.");
        }
        $this->assertStringContainsString('root.MiaDiagram = api;', $moteur);
        // Le modèle s'y branche, et garde l'API que la page connaît.
        $this->assertStringContainsString('const E = root.MiaDiagram;', $modele);
        $this->assertStringContainsString('html: E.html,', $modele);
        $this->assertStringContainsString('mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); }', $modele);
        $this->assertStringContainsString('root.MiaScheduleDiagram = api;', $modele);
        // Le moteur ne connaît aucune notion propre à la tâche : la consigne et
        // le nœud vide lui sont nommés par la vue.
        $this->assertStringNotContainsString("'instruction'", $moteur);
        $this->assertStringNotContainsString("'sources'", $moteur);
        $this->assertStringContainsString("target: 'instruction'", $modele);
        $this->assertStringContainsString("emptyTarget: 'sources'", $modele);
    }

    public function testLaListeEtLeFormulaireChargentLeSchemaAvantLeurScript(): void
    {
        foreach (['templates/settings/schedules.php', 'templates/settings/schedule-form.php'] as $gabarit) {
            $html = self::src($gabarit);
            $moteur    = strpos($html, '<script src="/assets/js/diagram.js');
            $diagramme = strpos($html, '<script src="/assets/js/schedule-diagram.js');
            $page      = strpos($html, '<script src="/assets/js/schedules.js');
            $this->assertNotFalse($moteur, "$gabarit ne charge pas diagram.js.");
            $this->assertNotFalse($diagramme, "$gabarit ne charge pas schedule-diagram.js.");
            $this->assertLessThan($diagramme, $moteur, "$gabarit : diagram.js (le moteur) doit précéder schedule-diagram.js, qui s'y branche.");
            $this->assertLessThan($page, $diagramme, "$gabarit : schedule-diagram.js doit précéder schedules.js (tous deux en defer, l'ordre est gardé).");
            $this->assertStringContainsString('id="schedDiagramPanel"', $html);
            $this->assertStringContainsString('id="schedDiagram"', $html);
            $this->assertStringContainsString('JSON_HEX_TAG', $html, 'Les icônes voyagent dans un <script> : < et > échappés.');
        }
        $this->assertStringContainsString('window.SCHED_DIAGRAMS', self::src('templates/settings/schedules.php'));
        $this->assertStringContainsString("'diagram'    => \$diagram", self::src('templates/settings/schedule-form.php'));
    }

    public function testLeControleurFournitCatalogueEtConfigurations(): void
    {
        $c = self::src('src/Controllers/ScheduledTaskController.php');
        $this->assertStringContainsString('TaskDiagram::specFromTask($task, $autoAllowed)', $c, 'Le schéma montre ce qui se passera : option « Agir sans validation » retirée = cartes à approuver.');
        $this->assertSame(2, substr_count($c, 'TaskDiagram::catalog('), 'Liste ET formulaire.');
        // La fiche d'un assistant porte son modèle : le schéma en tire le logo.
        $this->assertStringContainsString("'llm'         => \$llm", $c);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Schedule/TaskDiagram.php', self::src($liste), "$liste ne charge pas TaskDiagram.");
        }
    }

    public function testLeScriptDeLaPageAlimenteLeSchema(): void
    {
        $js = self::src('public/assets/js/schedules.js');
        // Formulaire : redessiné à chaque geste, et à chaque aperçu du serveur.
        $this->assertStringContainsString("form.addEventListener('input', renderDiagram)", $js);
        $this->assertStringContainsString("form.addEventListener('change', renderDiagram)", $js);
        $this->assertMatchesRegularExpression('/diagramTrigger = Object\.assign\(\{\}, diagramTrigger, \{\s*sentence: data\.sentence/', $js);
        // Ce que la tâche peut faire : le radio à trois choix ('' | '1' | 'auto').
        $this->assertStringContainsString("allow_actions: checkedMode() !== ''", $js);
        $this->assertStringContainsString("auto_actions: checkedMode() === 'auto'", $js);
        $this->assertStringContainsString("auto_send_mail: checkedMode() === 'auto' && !!autoSendInput?.checked", $js);
        $this->assertStringNotContainsString('actionsOpt', $js, 'Variable disparue avec le mode à trois choix : une référence restée ferait planter le schéma du formulaire.');
        // Liste : un clic sur la tâche, l'interrupteur tenu en phase.
        $this->assertStringContainsString('function setupListDiagram()', $js);
        $this->assertStringContainsString('listDiagram?.toggled(', $js);
        // Les commandes d'une tâche gardent leur geste.
        $this->assertStringContainsString("e.target.closest('a, button, input, select, textarea, label, form')", $js);
    }

    public function testLeSeuilCoteACoteEstLeMemePourLeStyleEtLeScript(): void
    {
        $css = self::src('public/assets/css/main.css');
        $js  = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("window.matchMedia('(min-width: 1200px)')", $js);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{\s*\.sched-split \{/', $css);
        // Colonne unique : le script lit le drapeau posé par la requête de conteneur.
        $this->assertStringContainsString('--sd-stack: 1;', $css);
        $this->assertStringContainsString("getPropertyValue('--sd-stack')", self::src(self::ENGINE));
        // Une teinte par nature d'élément.
        foreach (['.sd-trigger', '.sd-llm', '.sd-assistant', '.sd-connectors', '.sd-kbs', '.sd-tools'] as $noeud) {
            $this->assertMatchesRegularExpression('/' . preg_quote($noeud, '/') . '\s+\{[^}]*--sd-hue/', $css, "$noeud sans teinte.");
        }
        $this->assertStringContainsString('.sched-diagram-panel[hidden] { display: none !important; }', $css);
        // Page élargie par le schéma : « Nouvelle tâche » laisse sa place au sélecteur de langue à toute largeur.
        $this->assertMatchesRegularExpression('/@media \(min-width: 769px\) \{\s*\.sched-wrap\.has-diagram \.sched-head \{ padding-right: var\(--lang-switcher-reserve\); \}/', $css);
    }

    public function testLaDocumentationLeDit(): void
    {
        $this->assertStringContainsString('schéma', self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md'));
        $this->assertStringContainsString('schéma', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('schéma', self::src('docs/NOUVEAUTES.md'));
    }
}
