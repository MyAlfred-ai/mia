<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage du troisième lot des workflows : la décision de MIA (moteur, job,
 * page de l'exécution), les filtres imposés aux outils de l'agent (pas
 * seulement annoncés au modèle), l'éditeur (choix repliés, champ qui cite le
 * dossier, couleurs par nature, paquets), et leur documentation.
 */
final class WorkflowStudioContractTest extends TestCase
{
    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    /** Code PHP sans commentaires (une règle citée dans un docblock ne compte pas). */
    private static function code(string $path): string
    {
        $out = '';
        foreach (token_get_all(self::src($path)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    public function testLePerimetreEstImposeParChaqueOutilDeRecherche(): void
    {
        $gmail = self::code('src/Agent/Tools/GmailTools.php');
        $this->assertStringContainsString('$scope->gmailQuery(', $gmail);
        $outlook = self::code('src/Agent/Tools/OutlookTools.php');
        $this->assertStringContainsString('$scope->filterOutlook($outlookService->search(', $outlook);
        $this->assertStringContainsString('$scope->outlookQuery(', $outlook);
        $drive = self::code('src/Agent/Tools/DriveTools.php');
        $this->assertStringContainsString('$scope->filterDrive($driveService->search(', $drive);
        $this->assertStringContainsString('$scope->driveFolder()', $drive);
        $intent = self::code('src/Agent/Tools/IntentTools.php');
        $this->assertSame(2, substr_count($intent, '$scope = $this->turn->scope();'), 'Les deux recherches par intention (emails, partout) appliquent le filtre.');
        $this->assertStringContainsString('$scope->filterDrive($svc->search($scope->driveQuery($q), $n, $scope->driveFolder()))', $intent);
        $turn = self::code('src/Agent/AgentTurn.php');
        $this->assertStringContainsString('private readonly ?SearchScope $scope = null', $turn);
        $this->assertStringContainsString('return $this->scope ?? SearchScope::none();', $turn);
    }

    public function testLesEcrituresPassentParLePerimetre(): void
    {
        $chat = self::code('src/Controllers/ChatController.php');
        $this->assertStringContainsString("SearchScope::fromFilters(is_array(\$workflowStep['filters'] ?? null)", $chat);
        $this->assertSame(2, substr_count($chat, '$refusal = $searchScope->writeRefusal($tool, $args);'),
            'Refus d’un destinataire non permis : avant la carte, ET dans le chemin « sans validation ».');
        $this->assertStringContainsString('$args = $searchScope->applyToWrite($tool, WorkflowDeliveries::stripInternal($args));', $chat,
            'Le dossier imposé est posé par le serveur APRÈS le retrait des arguments internes venus du modèle.');
        $this->assertMatchesRegularExpression('/\$timeZone,\s*\$searchScope\s*\);/', $chat, 'Le périmètre accompagne le tour (AgentTurn).');
    }

    public function testLeMoteurRemplitLesFiltresEtMeneLaDecision(): void
    {
        $engine = self::code('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('$filters = self::filtersFor($node, $pieces, $artifacts);', $engine);
        $this->assertStringContainsString("'filters'        => \$filters,", $engine);
        $this->assertStringContainsString('SearchScope::fromFilters($filters)->promptNote()', $engine);
        $this->assertStringContainsString('WorkflowSpec::TYPE_ROUTE     => self::startRoute($run, $node)', $engine);
        $this->assertStringContainsString("'op' => 'route'", $engine);
        $this->assertStringContainsString('WorkflowAi::choose(', $engine);
        $this->assertStringContainsString('self::openRouteDoubt(', $engine);
        $this->assertStringContainsString('return self::routeResolved($run, $step, $branching, $outcome);', $engine);
        $this->assertStringContainsString('!WorkflowSpec::isBranching($node)', $engine, 'Une décision ne s’ignore pas.');
        $this->assertStringNotContainsString("foreach (['yes', 'no'] as \$lane)", $engine, 'Plus aucune voie codée en dur : branchesOf.');
        $this->assertStringContainsString("'route'      => WorkflowEngine::runRouteJob(\$payload)", self::code('src/Jobs/WorkflowJob.php'));
        $this->assertStringNotContainsString("foreach (['yes', 'no'] as \$lane)", self::code('src/Workflow/WorkflowCatalog.php'));

        $ctrl = self::code('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString("['approve', 'return', 'stop', 'route', 'none']", $ctrl);
        $this->assertStringContainsString("'route'   => 'route:' . \$routeKey", $ctrl);
        $this->assertStringContainsString("'routes' => \$gate['type'] === WorkflowSpec::TYPE_ROUTE", $ctrl);
        $this->assertStringContainsString("'fallback', 'decided_by', 'tool',", $ctrl, 'La page dit si MIA a pris la voie de repli, et qui a tranché un doute.');

        $run = self::src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString('data-gate="route" data-route="', $run, 'La personne choisit la voie sur la page de l’exécution.');
        $this->assertStringContainsString("route: b.getAttribute('data-route') || ''", $run);
        $this->assertStringContainsString('R.branchesOf(n).forEach', $run);
        $this->assertStringContainsString("st.detail.decided_by === 'person'", $run);
    }

    public function testLEditeurNeMontreQueLesChoixRetenus(): void
    {
        $ed = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('function choice(o)', $ed);
        $this->assertStringContainsString('function toggleChoice(id, force)', $ed);
        foreach (["id: 'profile'", "id: 'sources'", "id: 'write'", "id: 'reads'", "id: 'onerror'", "id: 'target'", "id: 'filters'", "id: 'fallback'"] as $c) {
            $this->assertStringContainsString($c, $ed, "Le choix {$c} est replié par défaut.");
        }
        $this->assertStringContainsString('if (sec.classList.contains(\'wf-choice\') && !sec.classList.contains(\'is-open\')) { toggleChoice(spot, true); }', $ed,
            'Un point « À régler » ouvre le choix concerné.');
        // Un SEUL champ qui cite le dossier.
        $this->assertStringContainsString('function mentionField(o)', $ed);
        $this->assertStringNotContainsString('function instructionBox(', $ed);
        $this->assertStringNotContainsString('function mentionBox(', $ed);
        $this->assertStringContainsString("accept === 'email' && f.type === 'email'", $ed, 'Un destinataire n’accepte qu’un champ email.');
        $this->assertGreaterThanOrEqual(15, substr_count($ed, 'mentionField({'), 'Partout où une pièce a un sens.');
        $this->assertStringContainsString("accept: 'email'", $ed);
        $this->assertStringContainsString("path: 'node.note'", $ed, 'La note d’une validation cite le dossier.');
        $this->assertStringContainsString("path: 'end.note'", $ed);
        $this->assertStringContainsString('function availableFor(key) { return R.available(state.def, key, allPieces()); }', $ed,
            'Les pièces disponibles gardent la couleur de leur nature.');
        $this->assertStringNotContainsString('R.available(state.def, at)', $ed);
        $this->assertStringContainsString('function addMenuItems(where)', $ed);
        $this->assertStringContainsString('document.body.appendChild(addPop);', $ed, 'Le menu d’ajout vit sur la page : dans le canevas (overflow), il était rogné.');
        $this->assertStringContainsString('function placeAddMenu()', $ed);
        $this->assertStringContainsString("{ key: 'tools', items: (cat.targets || []).map(", $ed, 'Chaque outil relié s’ajoute directement.');
        $this->assertStringContainsString('function routeInspector(n)', $ed);
        $this->assertStringContainsString('function filtersEditor(n, connectors, acts)', $ed);
        // Pas de séquence \u écrite à la main décodée en caractère.
        $this->assertStringContainsString("normalize('NFD').replace(/[\\u0300-\\u036f]/g, '')", $ed);
    }

    public function testLeRailMontreCeQuiCircule(): void
    {
        $rail = self::src('public/assets/js/workflow-rail.js');
        $this->assertStringContainsString('function branchesOf(node)', $rail);
        $this->assertStringContainsString('function transitMap(def, all, pmap)', $rail);
        $this->assertStringContainsString('class="wf-pk"', $rail);
        $this->assertStringContainsString("if (colors && !Array.isArray(colors))", $rail, 'Une couleur par nature.');
        $this->assertStringNotContainsString("LANES.map(function (l) { return lane(", $rail);

        $css = self::src('public/assets/css/workflows.css');
        $this->assertStringContainsString('@keyframes wf-packet', $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.wf-rail:not\(\.is-run\) \.wf-pk/', $css, 'Moins de mouvement : paquets immobiles.');
        $this->assertStringContainsString('.wf-rail.is-run .wf-link.is-flow .wf-pk', $css, 'En exécution, seul le lien vers la brique en cours s’anime.');
        $this->assertStringContainsString('.wf-mention-wrap { position: relative; isolation: isolate;', $css, 'Le bouton @ ne passe pas au-dessus de l’en-tête collant du volet.');
        $this->assertStringContainsString('.wf-hl mark', $css);
        $this->assertStringContainsString('.wf-choice-body', $css);
        $this->assertStringContainsString('.wf-addpop { position: fixed; z-index: 1150;', $css, 'Au-dessus du volet (1100), sous les fenêtres (1200).');
        $this->assertStringContainsString('.wf-drawer .wf-input, .wf-drawer .wf-select, .wf-drawer .wf-textarea, .wf-drawer .wf-ins-title, .wf-hl { font-size: 16px; }', $css, 'Au doigt, 16 px : iOS ne zoome pas au focus.');

        foreach (WorkflowCatalog::FORM_COLORS as $form => $color) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color, $form);
        }
        $this->assertSame(count(WorkflowCatalog::FORM_COLORS), count(array_unique(WorkflowCatalog::FORM_COLORS)), 'Deux natures, deux couleurs.');
        foreach (['route', 'filter', 'at', 'user', 'search', 'inbox', 'shield', 'chev', 'field', 'people', 'logic'] as $ic) {
            $this->assertArrayHasKey($ic, WorkflowCatalog::icons(), "Pictogramme {$ic}.");
        }
        $this->assertArrayHasKey('route', WorkflowCatalog::HUES);
        $this->assertStringContainsString("'route', 'loop', 'gate', 'wait', 'notify'", self::src('public/assets/js/chat.js'), 'La carte du chat dessine une décision.');
    }

    public function testLesClassesSontChargeesDansLOrdre(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $s = self::src($f);
            $this->assertStringContainsString('/src/Workflow/WorkflowFilters.php', $s);
            $this->assertLessThan(strpos($s, '/src/Agent/AgentTurn.php'), strpos($s, '/src/Agent/SearchScope.php'), "{$f} : SearchScope avant AgentTurn.");
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationSuit(): void
    {
        $guide = self::src('docs/GUIDE-CREATEUR.md');
        $this->assertStringContainsString('**Décision de MIA**', $guide);
        $this->assertStringContainsString('**Les filtres**', $guide);
        $this->assertStringContainsString('Voies d\'une décision de MIA', $guide);
        $idx = self::src('docs/user/fonctionnalites/workflows/index.md');
        $this->assertStringContainsString('(laisser-mia-choisir-la-voie.md)', $idx);
        $this->assertStringContainsString('(filtrer-emails-et-fichiers.md)', $idx);
        foreach (['laisser-mia-choisir-la-voie', 'filtrer-emails-et-fichiers'] as $page) {
            $p = self::src('docs/user/fonctionnalites/workflows/' . $page . '.md');
            $this->assertStringNotContainsString('](../../admin', $p, 'Jamais de lien vers l’administration.');
            $this->assertStringContainsString("sidebar:\n  order:", $p);
        }
        $this->assertStringContainsString('id: workflows-decision-filtres', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('SearchScope', self::src('CLAUDE.md'));
    }
}
