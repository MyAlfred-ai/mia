<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * L'allure du rail (cf. CLAUDE.md « Workflows ») :
 * - une brique « Appeler un workflow » n'est pas une brique comme les autres :
 *   l'icône et la teinte de l'espace Workflows de la barre latérale, une pile
 *   de cartes, un surtitre, le workflow appelé en miniature ;
 * - une brique que fait le modèle de l'organisation porte le LOGO de MIA (une
 *   étape sans assistant au modèle par défaut ; un petit logo au coin d'une
 *   question ou d'une décision), une étape qui impose un autre modèle porte
 *   le logo de son éditeur.
 *
 * Le rail est exécuté sous node : c'est son vrai rendu qui est vérifié.
 */
final class WorkflowRailLookTest extends TestCase
{
    private const LOGO = '/assets/img/branding/logo-maison.png?v=3';

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    private static function catalog(): array
    {
        return [
            'icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS,
            'mia' => ['logo' => self::LOGO],
            'default_llm' => 'openai::gpt-5.6',
            'llms' => [
                ['slug' => 'openai::gpt-5.6', 'label' => 'GPT-5.6', 'logo' => '/assets/img/llm-openai.png'],
                ['slug' => 'anthropic::claude-opus-5', 'label' => 'Claude Opus 5', 'logo' => '/assets/img/llm-anthropic.png'],
            ],
            'workflows' => [['id' => 7, 'name' => 'Analyse client', 'color' => '#f59e0b', 'published' => true, 'active' => true, 'silhouette' => 'dscse']],
        ];
    }

    private static function definition(): array
    {
        return WorkflowSpec::normalize([
            'nodes' => [
                ['key' => 'defaut', 'type' => 'step', 'title' => 'Par défaut', 'instruction' => 'x', 'output' => ['key' => 'p1', 'name' => 'P1']],
                ['key' => 'meme', 'type' => 'step', 'title' => 'Le défaut nommé', 'instruction' => 'x', 'llm' => 'openai::gpt-5.6', 'output' => ['key' => 'p2', 'name' => 'P2']],
                ['key' => 'autre', 'type' => 'step', 'title' => 'Un autre modèle', 'instruction' => 'x', 'llm' => 'anthropic::claude-opus-5', 'output' => ['key' => 'p3', 'name' => 'P3']],
                ['key' => 'retire', 'type' => 'step', 'title' => 'Modèle retiré', 'instruction' => 'x', 'llm' => 'mistral::ancien', 'output' => ['key' => 'p4', 'name' => 'P4']],
                ['key' => 'appel', 'type' => 'call', 'title' => 'Appeler Analyse client', 'workflow_id' => 7, 'params' => ['client' => 'Durand'],
                    'output' => ['key' => 'p5', 'name' => 'Synthèse']],
                ['key' => 'question', 'type' => 'condition', 'title' => 'Solide ?', 'mode' => 'question', 'question' => 'Solide ?', 'yes' => ['nodes' => []], 'no' => ['nodes' => []]],
                ['key' => 'regle', 'type' => 'condition', 'title' => 'Gros ?', 'mode' => 'rule',
                    'rule' => ['join' => 'all', 'items' => [['piece' => 'p1', 'field' => 'x', 'op' => 'notempty', 'value' => '']]], 'yes' => ['nodes' => []], 'no' => ['nodes' => []]],
                ['key' => 'voie', 'type' => 'route', 'title' => 'Quel cas ?', 'routes' => [['key' => 'a', 'label' => 'A', 'nodes' => []], ['key' => 'b', 'label' => 'B', 'nodes' => []]]],
            ],
            'end' => ['pieces' => ['p5']],
        ]);
    }

    /** Le rail rendu par le navigateur, découpé par brique (clé → son <article>). */
    private static function bricks(array $def, array $cat): array
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node absent');
        }
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';'
            . 'process.stdout.write(JSON.stringify({ html: R.html(d, { catalog: cat, editable: true }), logo: R.miaLogo(cat), plain: R.miaLogo({ icons: cat.icons }),'
            . ' def: [R.isDefaultLlm(cat, ""), R.isDefaultLlm(cat, "openai::gpt-5.6"), R.isDefaultLlm(cat, "anthropic::claude-opus-5"), R.isDefaultLlm(cat, "mistral::ancien")] }));';
        $file = tempnam(sys_get_temp_dir(), 'wflook') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        self::assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        preg_match_all('/<article class="wf-brick[^"]*" data-node="([^"]+)"[\s\S]*?<\/article>/', $got['html'], $m, PREG_SET_ORDER);
        $out = ['_' => $got];
        foreach ($m as $hit) {
            $out[$hit[1]] = $hit[0];
        }
        return $out;
    }

    public function testLaBriqueDAppelPorteLIconeEtLaTeinteDeLEspaceWorkflows(): void
    {
        $b = self::bricks(self::definition(), self::catalog());
        $call = $b['appel'];
        $this->assertStringContainsString('wf-k-call', $call);
        // L'icône de la tuile Workflows de la barre latérale, pas un pictogramme à part.
        $this->assertStringContainsString(Workflow::ICON_PATHS, $call);
        $this->assertSame(Workflow::icon(16), WorkflowCatalog::icons()['call'], 'Partout (menu, volets, journal), l’appel a l’icône des workflows.');
        $this->assertSame(WorkflowCatalog::icons()['workflow'], WorkflowCatalog::icons()['call']);
        // La teinte de l'espace : la même pour toutes les briques d'appel ; la
        // couleur du workflow appelé ne marque que sa miniature.
        $this->assertStringContainsString('--hue:var(--wf-call, #0d9488)', $call);
        $this->assertStringNotContainsString('--hue:#f59e0b', $call);
        $this->assertMatchesRegularExpression('/<span class="wf-brick-kicker">Appel de workflow<\/span>/', $call);
        $this->assertMatchesRegularExpression('/<span class="wf-call-mini" style="--c:#f59e0b"[^>]*><i class="wf-call-mini-dot"><\/i><div class="wf-sil"/', $call);
        $this->assertSame(5, substr_count(substr($call, (int)strpos($call, 'wf-call-mini')), '<i class="') - 1, 'La silhouette du workflow appelé : une station par brique.');
        $this->assertStringContainsString('Appelle « Analyse client »', $call);

        // Sans silhouette connue (catalogue d'avant), pas de miniature vide.
        $cat = self::catalog();
        unset($cat['workflows'][0]['silhouette']);
        $this->assertStringNotContainsString('wf-call-mini', self::bricks(self::definition(), $cat)['appel']);

        // Les autres briques ne changent pas d'allure.
        $this->assertStringNotContainsString('wf-brick-kicker', $b['defaut'] . $b['question'] . $b['voie']);
    }

    public function testLaPileDeCartesEtSesEtats(): void
    {
        $css = self::src('public/assets/css/workflows.css');
        $this->assertStringContainsString(':root { --wf-call: #0d9488; }', $css);
        $this->assertStringContainsString(':root:not([data-theme="light"]) { --wf-call: #2dd4bf; }', $css, 'Les teintes de .space-wf, dans les deux thèmes.');
        $main = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.space-wf   { --space: #2dd4bf; }', $main);
        $this->assertStringContainsString('[data-theme="light"] .space-wf   { --space: #0d9488; }', $main);
        $this->assertMatchesRegularExpression('/\.wf-brick\.wf-k-call \{[^}]*--wf-stack:[^}]*box-shadow: var\(--wf-stack\)/s', $css);
        // Chaque état garde sa bordure et son anneau, la pile dessous.
        foreach (['is-sel', 'st-active', 'st-waiting', 'st-failed', 'st-timer'] as $state) {
            $this->assertMatchesRegularExpression('/\.wf-brick\.wf-k-call\.' . $state . ' \{[^}]*box-shadow: 0 0 0 3px [^}]*var\(--wf-stack\)/', $css, $state);
        }
        $this->assertMatchesRegularExpression('/\.wf-brick\.wf-k-call\.st-pending \{[^}]*box-shadow: none/', $css);
        // Carte du chat (creer_workflow) : la ligne d'un appel a sa pastille.
        $this->assertStringContainsString("'stop', 'call', 'end'", self::src('public/assets/js/chat.js'));
        $this->assertStringContainsString('.drive-confirm-rail li[data-kind="call"]', $main);
    }

    public function testLeModeleDeLOrganisationPorteLeLogoDeMia(): void
    {
        $b = self::bricks(self::definition(), self::catalog());
        $got = $b['_'];
        $this->assertSame([true, true, false, true], $got['def'], 'Vide, nommé, autre, retiré (le moteur retombe sur le défaut).');
        $logo = '<img class="wf-mia-logo" src="' . self::LOGO . '" alt="">';
        $this->assertSame($logo, $got['logo']);
        $this->assertSame(WorkflowCatalog::icons()['mia'], $got['plain'], 'Sans logo connu : le pictogramme.');

        foreach (['defaut', 'meme', 'retire'] as $key) {
            $this->assertStringContainsString('<span class="wf-ava mia is-logo" title="MIA">' . $logo . '</span>', $b[$key], $key);
            $this->assertStringContainsString('<small>MIA</small>', $b[$key], $key);
        }
        // Un autre modèle imposé : le logo de son éditeur, et son nom.
        $this->assertStringContainsString('<span class="wf-ava llm" title="Claude Opus 5"><img src="/assets/img/llm-anthropic.png" alt=""></span>', $b['autre']);
        $this->assertStringContainsString('<small>MIA · Claude Opus 5</small>', $b['autre']);
        $this->assertStringNotContainsString('wf-mia-logo', $b['autre']);

        // Question et décision : le petit logo de MIA au coin du pictogramme ; une règle, non.
        $badge = '<span class="wf-ic-mia" title="MIA">' . $logo . '</span>';
        $this->assertStringContainsString($badge, $b['question']);
        $this->assertStringContainsString($badge, $b['voie']);
        $this->assertStringNotContainsString('wf-ic-mia', $b['regle']);
        $this->assertStringNotContainsString('wf-ic-mia', $b['appel'] . $b['defaut']);
    }

    public function testLeLogoVientDeLOrganisationEtResteDeCetteOrigine(): void
    {
        $this->assertSame('/assets/img/branding/logo.png?v=12', WorkflowCatalog::logoPath('/assets/img/branding/logo.png?v=12'));
        foreach (['https://exemple.fr/logo.png', '//exemple.fr/logo.png', 'javascript:alert(1)', '/a" onerror="x', '', ' /logo.png'] as $bad) {
            $this->assertSame('/assets/img/logo.png', WorkflowCatalog::logoPath($bad), $bad);
        }
        $catalog = self::src('src/Workflow/WorkflowCatalog.php');
        $this->assertStringContainsString("'mia'         => ['logo' => self::miaLogo()],", $catalog, 'Livré aux écrans par le catalogue.');
        $this->assertStringContainsString("Settings::get('logo_url', '/assets/img/logo.png')", $catalog, 'Le logo de Marque & apparence.');
        $this->assertStringContainsString("'silhouette' => (string)(\$w['silhouette'] ?? ''),", $catalog);

        // Les autres endroits qui disent « MIA » : volet de l'étape, choix de l'éditeur, vignettes de la liste.
        $this->assertStringContainsString('R.avatar(prof, cat, node.llm)', self::src('public/assets/js/workflow-run.js'));
        $editor = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('R.avatar(p || null, cat, n.llm)', $editor);
        $this->assertStringContainsString("ic: R.miaLogo(cat), label: 'MIA'", $editor);
        $this->assertStringContainsString('WorkflowCatalog::miaLogo()', self::src('templates/workflows/index.php'));
        $this->assertStringNotContainsString("\$icons['mia']", self::src('templates/workflows/index.php'));
    }

    public function testLaSignatureDonneLaSilhouetteDeLAppele(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['key' => 'p', 'name' => 'P']],
        ], 'end' => ['pieces' => ['p']]]);
        $sig = WorkflowCall::signature(['name' => 'X', 'color' => '', 'published' => $def, 'is_active' => 1]);
        $this->assertSame(WorkflowSpec::silhouette($def), $sig['silhouette']);
        $this->assertSame('', WorkflowCall::signature(['name' => 'X', 'color' => '', 'published' => null, 'is_active' => 1])['silhouette']);
    }
}
