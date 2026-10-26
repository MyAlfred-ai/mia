<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Formulaire d'une tâche programmée — ergonomie (templates/settings/schedule-form.php).
 *
 *  - La CONSIGNE est le cœur de la tâche : bloc mis en avant, exemples
 *    cliquables (InstructionExamples) — LES MÊMES que la section « Exemples »
 *    du guide utilisateur, mot pour mot.
 *  - « Avec quoi » en tuiles alignées ; les contrats lus par schedules.js
 *    (classes d'état, data-*) n'ont pas bougé.
 *  - Le schéma reste en vue pendant le défilement (sticky, aucun overflow
 *    sur ses ancêtres) et chaque nœud mène à son réglage : cibles émises par
 *    diagram.js + schedule-diagram.js (data-target) = cibles résolues par schedules.js
 *    (JUMP_TARGETS), ids visés présents dans le gabarit, et — sous node, avec
 *    le vrai module — interactif seulement quand la page passe `jump`.
 */
final class ScheduledTaskFormUxTest extends TestCase
{
    private const FORM       = 'templates/settings/schedule-form.php';
    private const PAGE_JS    = 'public/assets/js/schedules.js';
    private const DIAGRAM_JS = 'public/assets/js/schedule-diagram.js';
    private const ENGINE_JS  = 'public/assets/js/diagram.js';
    private const CSS        = 'public/assets/css/main.css';
    private const GUIDE      = 'docs/user/fonctionnalites/conversations/programmer-une-tache.md';

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    // ------------------------------------------------------------------
    // Consigne : le cœur de la tâche, et ses exemples
    // ------------------------------------------------------------------

    public function testLesExemplesSontCeuxDuGuideUtilisateur(): void
    {
        I18n::use('fr');
        $exemples = InstructionExamples::all();
        $this->assertGreaterThanOrEqual(3, count($exemples), 'Assez d’exemples pour montrer le ton, pas un catalogue.');
        $this->assertLessThanOrEqual(5, count($exemples));

        // Le guide est relu sans ses retours à la ligne : une consigne y est
        // citée sur deux lignes.
        $guide  = (string)preg_replace('/\s+/u', ' ', self::src(self::GUIDE));
        $labels = [];
        $cles   = [];
        foreach ($exemples as $exemple) {
            $this->assertNotSame('', trim($exemple['label']));
            $this->assertNotSame('', trim($exemple['instruction']));
            $this->assertLessThanOrEqual(ScheduledTask::NAME_MAX, mb_strlen($exemple['label']), 'Le libellé devient le nom de la tâche.');
            $this->assertLessThanOrEqual(ScheduledTask::INSTRUCTION_MAX, mb_strlen($exemple['instruction']));
            $this->assertStringContainsString($exemple['instruction'], $guide, "Consigne absente de la section « Exemples » du guide : {$exemple['label']}");
            $labels[] = $exemple['label'];
            $cles[]   = $exemple['key'];
        }
        $this->assertSame($labels, array_values(array_unique($labels)), 'Deux puces du même nom.');
        $this->assertSame($cles, array_values(array_unique($cles)));
    }

    public function testLesExemplesSontTraduits(): void
    {
        $en = require ROOT . '/lang/en.php';
        I18n::use('fr');
        foreach (InstructionExamples::all() as $exemple) {
            $this->assertArrayHasKey($exemple['label'], $en, "Libellé sans traduction : {$exemple['label']}");
            $this->assertArrayHasKey($exemple['instruction'], $en, "Consigne sans traduction : {$exemple['label']}");
        }
    }

    public function testLeFormulaireMetLaConsigneEnAvantAvecSesExemples(): void
    {
        $tpl = self::src(self::FORM);
        $this->assertStringContainsString('$examples = InstructionExamples::all();', $tpl, 'Les exemples viennent de la source unique.');
        $this->assertStringContainsString('class="form-group sched-instruction" id="schedInstructionBlock"', $tpl);
        $this->assertStringContainsString('class="sched-instruction-kicker"', $tpl);
        $this->assertStringContainsString('id="schedExamples"', $tpl);
        $this->assertMatchesRegularExpression('/<button type="button" class="sched-example"\s+data-name=/', $tpl, 'Un bouton (jamais un lien ni un submit) par exemple.');
        $this->assertStringContainsString('data-instruction="<?= htmlspecialchars($example[\'instruction\']) ?>"', $tpl);
        // Le texte d'invite du champ EST le premier exemple : pas un cinquième à part.
        $this->assertStringContainsString("placeholder=\"<?= htmlspecialchars((string)(\$examples[0]['instruction'] ?? '')) ?>\"", $tpl);

        $css = self::src(self::CSS);
        foreach (['.sched-instruction {', '.sched-instruction-head label', '.sched-instruction-kicker', '.sched-example {', '.sched-example.is-active'] as $sel) {
            $this->assertStringContainsString($sel, $css, "Style absent : $sel");
        }

        // Un clic pose l'exemple ; jamais par-dessus un texte ÉCRIT sans confirmer ;
        // le nom suit si vide ; le schéma suit comme pour une frappe.
        $js = self::src(self::PAGE_JS);
        $this->assertStringContainsString("e.target.closest('.sched-example')", $js);
        $this->assertStringContainsString("window.confirm(t('Remplacer la consigne en cours par cet exemple ?'))", $js);
        $this->assertStringContainsString("if (current !== '' && current !== placedExample", $js);
        $this->assertStringContainsString("if (nameField && nameField.value.trim() === '') nameField.value = btn.dataset.name || '';", $js);
        $this->assertStringContainsString("instruction.dispatchEvent(new Event('input', { bubbles: true }));", $js);
    }

    // ------------------------------------------------------------------
    // « Avec quoi » : des tuiles alignées, mêmes contrats
    // ------------------------------------------------------------------

    public function testAvecQuoiEstEnTuilesAlignees(): void
    {
        $tpl = self::src(self::FORM);
        foreach (['id="schedKbs"', 'id="schedSources"', 'id="schedTools"', 'class="sched-pick-head"', 'class="sched-tiles"',
                  'class="sched-tile sched-kb', 'class="sched-tile sched-source', 'class="sched-tile sched-tool',
                  'data-role="count"', 'class="sched-tile-check"'] as $marqueur) {
            $this->assertStringContainsString($marqueur, $tpl, "Formulaire : $marqueur absent.");
        }
        // Les contrats lus par schedules.js : inchangés.
        foreach (['data-kb-id="<?= (int)$kbId ?>"', 'data-role="note" data-default=',
                  'data-type="<?= htmlspecialchars($tool[\'type\']) ?>"', 'data-acting="1"'] as $contrat) {
            $this->assertStringContainsString($contrat, $tpl, "Contrat de schedules.js perdu : $contrat");
        }
        // L'input reste le contrôle : une tuile EST un <label> autour de sa case.
        // (`.*?` et non `[^>]*` : les attributs portent des balises PHP, dont le chevron fermant.)
        $this->assertMatchesRegularExpression('/<label class="sched-tile sched-kb.*?>\s*<input type="checkbox" name="kb_ids\[\]"/s', $tpl);
        $this->assertMatchesRegularExpression('/<label class="sched-tile sched-source.*?>\s*<input type="checkbox" name="sources\[\]"/s', $tpl);
        $this->assertMatchesRegularExpression('/<label class="sched-tile sched-tool.*?>\s*<input type="checkbox" name="tool_ids\[\]"/s', $tpl);
        // Plus aucune pastille, ni dans le gabarit ni dans la feuille de style.
        $this->assertStringNotContainsString('sched-chip', $tpl);
        $this->assertStringNotContainsString('sched-chip', self::src(self::CSS));
        // Icônes : celles du schéma et du menu « + » — une seule source par élément.
        $this->assertStringContainsString("TaskDiagram::toolIcon((string)\$tool['type'])", $tpl);
        $this->assertStringContainsString("\$source['icon']", $tpl);
        $modele = self::src('src/Models/ScheduledTask.php');
        $this->assertStringContainsString("'icon'      => (string)(ConnectorRegistry::row(\$key)['icon'] ?? '')", $modele);
        $this->assertStringContainsString("'icon'      => (string)(ConnectorRegistry::row(ConnectorRegistry::WEB)['icon'] ?? '')", $modele);
        $this->assertStringContainsString('public static function toolIcon(string $type): string', self::src('src/Schedule/TaskDiagram.php'));

        // CSS : une grille de cases égales, l'état par :has(), les classes d'état.
        $css = self::src(self::CSS);
        $this->assertMatchesRegularExpression('/\.sched-tiles \{ display: grid; grid-template-columns: repeat\(auto-fill, minmax\(\d+px, 1fr\)\);/', $css);
        foreach (['.sched-tile:has(input:checked)', '.sched-tile:has(input:focus-visible)', '.sched-tile.is-locked',
                  '.sched-tile.is-unavailable', '.sched-tile.is-full', '.sched-tile-note.is-warn', '.sched-pick-count'] as $sel) {
            $this->assertStringContainsString($sel, $css, "Style absent : $sel");
        }
        // Au doigt : la tuile entière fait 48 px.
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{\s*\.sched-tile \{ min-height: 48px; \}/', $css);

        // Compteur « n/3 » et plafond : tenus par le script.
        $js = self::src(self::PAGE_JS);
        $this->assertStringContainsString("kbBox.querySelector('[data-role=\"count\"]')", $js);
        $this->assertStringContainsString("kbCount.textContent = checked + '/' + kbMax;", $js);
        $this->assertStringContainsString("chip.classList.toggle('is-full', full);", $js);
    }

    // ------------------------------------------------------------------
    // Le schéma : en vue, et chaque nœud mène à son réglage
    // ------------------------------------------------------------------

    public function testLeSchemaResteEnVuePendantLeDefilement(): void
    {
        $css = self::src(self::CSS);
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1200px\) \{.*?\.sched-form-layout > \.sched-diagram-panel \{\s*position: sticky;\s*top: \d+px;\s*max-height: calc\(calc\(100vh \/ var\(--ui-zoom, 1\)\) - \d+px\);\s*overflow: auto;/s',
            $css,
            'Côte à côte, le panneau du schéma suit le défilement ; plus haut que la fenêtre, il défile en lui-même.'
        );
        // Un `overflow` sur un ancêtre fait du sticky une position relative à
        // cet ancêtre : aucun entre le panneau et .admin-content — et
        // .admin-content lui-même, qui porte `overflow-y: auto` pour défiler
        // sur mobile alors que c'est la fenêtre qui défile sur desktop,
        // redevient visible sur les deux pages à schéma (vu au banc : le
        // panneau partait avec la page).
        $this->assertDoesNotMatchRegularExpression('/\.sched-form-layout \{[^}]*overflow/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.sched-form-wrap[^{]*\{[^}]*overflow/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.sched-wrap \{[^}]*overflow/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{.*?\.admin-content\.sched-content \{ overflow: visible; \}/s', $css);
        foreach (['templates/settings/schedule-form.php', 'templates/settings/schedules.php'] as $gabarit) {
            $this->assertStringContainsString('<div class="admin-content sched-content">', self::src($gabarit), "$gabarit : le conteneur ne porte pas sched-content.");
        }
        $this->assertStringContainsString('class="sched-diagram-hint"', self::src(self::FORM), 'Le panneau dit qu’un élément se clique.');
    }

    public function testChaqueNoeudDuSchemaMeneAUnReglageConnuDuFormulaire(): void
    {
        // Le moteur (nœuds) et le modèle de la tâche (consigne, traits, nœud vide).
        $diagram = self::src(self::ENGINE_JS) . "\n" . self::src(self::DIAGRAM_JS);
        $page    = self::src(self::PAGE_JS);

        // Cibles que le schéma peut émettre : nœuds (target('…')), consigne et
        // traits (target: '…'), nœud vide (emptyTarget: '…') et les groupes du
        // bas, dont la cible est leur genre.
        preg_match_all("/target\('(\w+)'\)/", $diagram, $noeuds);
        preg_match_all("/target: '(\w+)'/", $diagram, $traits);
        preg_match_all("/emptyTarget: '(\w+)'/", $diagram, $vide);
        $this->assertStringContainsString('target(g.kind)', $diagram);
        $this->assertStringContainsString('jumpBtn(g.kind)', $diagram);
        $cibles = array_values(array_unique(array_merge($noeuds[1], $traits[1], $vide[1], ['connectors', 'kbs', 'tools'])));
        sort($cibles);

        // Cibles que la page sait résoudre, et leur section.
        $this->assertSame(1, preg_match('/const JUMP_TARGETS = \{(.*?)\n            \};/s', $page, $bloc), 'JUMP_TARGETS introuvable.');
        preg_match_all('/^\s+(\w+):\s+\{ section: \'(what|with|when|where)\'/m', $bloc[1], $connues);
        $resolues = $connues[1];
        sort($resolues);
        $this->assertSame($cibles, $resolues, 'Chaque cible du schéma a sa section dans la page ; aucune entrée sans cible.');

        // Les ids visés existent dans le gabarit.
        $tpl = self::src(self::FORM);
        preg_match_all("/byId\('(sched\w+)'\)/", $bloc[1], $ids);
        $this->assertNotEmpty($ids[1]);
        foreach (array_unique($ids[1]) as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $tpl, "Cible du saut absente du gabarit : #$id");
        }
        // Les intitulés viennent des titres de section de la page, traduits.
        $this->assertStringContainsString("t('Modifier dans « {section} »', { section: label })", $page);
        foreach (['schedWhatTitle', 'schedWithTitle', 'schedWhenTitle', 'schedWhereTitle'] as $titre) {
            $this->assertStringContainsString("sectionTitle('$titre')", $page);
        }

        // Le geste : défilement (doux sauf préférence contraire), projecteur,
        // focus sans second défilement ; le schéma reçoit `jump` à chaque rendu.
        $this->assertStringContainsString("el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });", $page);
        $this->assertStringContainsString("el.classList.add('is-spotlit');", $page);
        $this->assertStringContainsString('target.focus({ preventScroll: true });', $page);
        $this->assertStringContainsString("diagramHost.addEventListener('click'", $page);
        $this->assertStringContainsString('{ animate: diagramFirst, jump: jumpTitles }', $page);

        $css = self::src(self::CSS);
        $this->assertStringContainsString('@keyframes sched-spot', $css);
        $this->assertStringContainsString('.sched-form .is-spotlit { animation: sched-spot', $css);
        $this->assertStringContainsString('.sd.is-interactive [data-target] { cursor: pointer; }', $css);
        $this->assertStringContainsString('.sd-jump {', $css);
        $this->assertMatchesRegularExpression('/@media \(hover: none\) \{\s*\.sd-jump \{ display: none; \}/', $css, 'Au doigt, pas de survol : le nœud entier se touche.');
    }

    /** Le VRAI module sous node : interactif avec `jump`, inerte sans — le rendu de la liste ne bouge pas. */
    public function testLeSchemaNEstInteractifQueQuandLaPagePasseLesIntitules(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $catalogue = [
            'labels'  => ['assistant' => 'Assistant', 'no_assistant' => 'Sans assistant', 'connectors' => 'Connecteurs', 'actions' => 'Actions', 'dest_new' => 'Nouvelle', 'notify' => 'Notifications'],
            'icons'   => ['edit' => '<svg class="crayon"></svg>'],
            'mia'     => ['name' => 'MIA', 'avatar' => '/assets/img/logo.png'],
            'sources' => ['web' => ['label' => 'Recherche Internet', 'icon' => '']],
            'kbs' => (object)[], 'tools' => (object)[], 'assistants' => (object)[], 'llms' => (object)[],
        ];
        $spec = [
            'name' => 'Point', 'instruction' => 'Fais le point.', 'profile_id' => null, 'sources' => ['web'],
            'allow_actions' => true, 'destination' => 'new', 'notify' => true,
            'trigger' => ['sentence' => 'Chaque lundi à 8 h 00', 'active' => true],
        ];
        $jump = ['trigger' => 'Modifier dans « Quand »', 'llm' => 'Quoi', 'assistant' => 'Quoi', 'connectors' => 'Avec quoi'];

        $script = "'use strict';\n" . self::src(self::ENGINE_JS) . "\n" . self::src(self::DIAGRAM_JS) . "\n"
            . 'const D = globalThis.MiaScheduleDiagram;' . "\n"
            . 'const CAT = ' . json_encode($catalogue) . ', SPEC = ' . json_encode($spec) . ', JUMP = ' . json_encode($jump) . ";\n"
            . 'process.stdout.write(JSON.stringify({ avec: D.html(D.model(SPEC, CAT, { jump: JUMP })), sans: D.html(D.model(SPEC, CAT)) }));';
        $fichier = tempnam(sys_get_temp_dir(), 'mia-schema-saut-');
        file_put_contents($fichier, $script);
        try {
            $sortie = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $out = json_decode($sortie, true);
        $this->assertIsArray($out, "schedule-diagram.js a échoué sous node :\n$sortie");

        $avec = $out['avec'];
        $this->assertStringContainsString('class="sd is-interactive"', $avec);
        // Un bouton par nœud nommé dans `jump` (déclencheur, modèle, assistant, connecteurs) — pas un de plus.
        $this->assertSame(4, substr_count($avec, 'class="sd-jump"'));
        $this->assertStringContainsString('<button type="button" class="sd-jump" data-target="trigger" title="Modifier dans « Quand »" aria-label="Modifier dans « Quand »"><svg class="crayon"></svg></button>', $avec);
        // Le nœud lui-même n'est jamais un bouton : son contenu reste lisible.
        $this->assertDoesNotMatchRegularExpression('/<div class="sd-node[^>]*role="button"/', $avec);
        // Les cibles plus précises de l'assistant : consigne, mode, destination, notifications.
        foreach (['instruction', 'mode', 'destination', 'notify', 'connectors', 'trigger', 'llm', 'assistant'] as $cible) {
            $this->assertStringContainsString('data-target="' . $cible . '"', $avec, "Cible absente : $cible");
        }

        $sans = $out['sans'];
        $this->assertStringNotContainsString('is-interactive', $sans);
        $this->assertStringNotContainsString('sd-jump', $sans);
        $this->assertStringNotContainsString('role="button"', $sans);
        // Les cibles restent : inertes dans la liste, c'est la page qui décide.
        $this->assertStringContainsString('data-target="connectors"', $sans);
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

    // ------------------------------------------------------------------
    // Chargement et documentation
    // ------------------------------------------------------------------

    public function testLaClasseDesExemplesEstChargeeDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::src($liste);
            $this->assertStringContainsString('/src/Schedule/InstructionExamples.php', $src, "$liste ne charge pas InstructionExamples.");
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        // Relu sans ses retours à la ligne : le guide est justifié à 100 colonnes.
        $guide = (string)preg_replace('/\s+/u', ' ', self::src(self::GUIDE));
        foreach (['Par exemple', 'reste en vue', 'cliquer un de ses éléments', 'cases alignées'] as $mot) {
            $this->assertStringContainsString($mot, $guide, "Guide utilisateur : « $mot » absent.");
        }
        $interne = self::src('docs/GUIDE-UTILISATEUR.md');
        foreach (['Par exemple', 'reste en vue', 'cliquer un de ses éléments'] as $mot) {
            $this->assertStringContainsString($mot, $interne, "GUIDE-UTILISATEUR : « $mot » absent.");
        }
        $this->assertStringContainsString('id: taches-formulaire-guide', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('InstructionExamples', self::src('CLAUDE.md'));
    }
}
