<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Espaces → Assistants : la PAGE d'un assistant (/admin/profiles/{id},
 * consultation) et sa FICHE de création et de modification, refaites dans la
 * grammaire des pages « Bases de connaissances » (piste D) : en-tête, actions,
 * « Posez-lui une question », chiffres, consigne, ce qu'il connaît, modèle,
 * outils, qui le voit, schéma en bas ; fiche à barre du haut collante, menu
 * des sections, sections en cartes, schéma en dernière section.
 *
 * Ce qui est pur (gestes offerts, température dite, consigne repliée) est
 * exécuté ; le reste est du câblage, vérifié sur les sources.
 */
final class AssistantPageTest extends TestCase
{
    private const SHOW = 'templates/admin/profiles/show.php';
    private const FORM = 'templates/admin/profiles/edit.php';
    private const LIST = 'templates/admin/profiles/list.php';
    private const SHOW_JS = 'public/assets/js/assistant-show.js';
    private const FICHE_JS = 'public/assets/js/assistant-fiche.js';
    private const CSS = 'public/assets/css/assistants.css';

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    // ---------- Formes pures ----------

    public function testLesGestesOffertsSontCeuxDeLaListe(): void
    {
        $tous = ['manage' => true, 'edit' => true, 'delete' => true];

        // Discuter : actif, et pas l'assistant personnel d'un autre.
        $perso = ['is_personal' => 1, 'is_active' => 1, 'created_by' => 7];
        $this->assertTrue(AssistantPages::actions($perso, [], 7)['use']);
        $this->assertFalse(AssistantPages::actions($perso, $tous, 3)['use'], 'Un administrateur n’ouvre pas le chat de l’assistant personnel d’un autre.');
        $this->assertFalse(AssistantPages::actions(['is_active' => 0] + $perso, [], 7)['use'], 'Inactif : pas de conversation.');

        // Partager / rendre personnel : Gérer + Modifier, jamais le global, jamais un orphelin.
        $partage = ['is_personal' => 0, 'is_global' => 0, 'is_active' => 1, 'created_by' => 5];
        $this->assertTrue(AssistantPages::actions($partage, $tous, 1)['toggle']);
        $this->assertFalse(AssistantPages::actions(['created_by' => null] + $partage, $tous, 1)['toggle'], 'Sans créateur, il deviendrait orphelin.');
        $this->assertFalse(AssistantPages::actions(['is_global' => 1] + $partage, $tous, 1)['toggle']);
        $this->assertFalse(AssistantPages::actions($partage, ['manage' => true], 1)['toggle'], 'Sans Modifier.');
        $this->assertTrue(AssistantPages::actions($perso, $tous, 1)['toggle'], 'Un personnel se partage à l’organisation.');

        // Groupes : un assistant partagé, pour qui gère.
        $this->assertTrue(AssistantPages::actions($partage, $tous, 1)['groups']);
        $this->assertFalse(AssistantPages::actions($perso, $tous, 1)['groups']);
        $this->assertFalse(AssistantPages::actions($partage, ['edit' => true], 1)['groups']);

        $this->assertSame(['use' => true, 'toggle' => false, 'groups' => false, 'delete' => true, 'edit' => false],
            AssistantPages::actions($perso, ['delete' => true], 7));
    }

    public function testLaTemperatureSeDitEnMots(): void
    {
        $this->assertSame('Ce modèle règle lui-même sa température.', AssistantPages::temperatureText(0.2, false));
        $this->assertSame('Température : celle du modèle', AssistantPages::temperatureText(null, true));
        $this->assertSame('Température : celle du modèle', AssistantPages::temperatureText('', true));
        $this->assertSame('Température 0,2 · précise', AssistantPages::temperatureText('0.2', true));
        $this->assertSame('Température 0 · précise', AssistantPages::temperatureText(0, true));
        $this->assertSame('Température 0,7 · équilibrée', AssistantPages::temperatureText(0.7, true));
        $this->assertSame('Température 1,5 · créative', AssistantPages::temperatureText(1.5, true));

        I18n::use('en');
        $en = AssistantPages::temperatureText(0.7, true);
        $this->assertStringContainsString('0.7', $en, 'Point décimal en anglais.');
        $this->assertStringNotContainsString('équilibrée', $en);
    }

    public function testUneLongueConsigneSeReplie(): void
    {
        $this->assertFalse(AssistantPages::promptIsLong('Tu aides à rédiger.'));
        $this->assertFalse(AssistantPages::promptIsLong(implode("\n", array_fill(0, AssistantPages::PROMPT_LINES, 'ligne'))));
        $this->assertTrue(AssistantPages::promptIsLong(implode("\n", array_fill(0, AssistantPages::PROMPT_LINES + 1, 'ligne'))));
        $this->assertTrue(AssistantPages::promptIsLong(str_repeat('mot ', 300)), 'Long sans retour à la ligne.');
    }

    public function testLesPastillesDeFichierSontCellesDesBases(): void
    {
        $kinds = KbPages::fileKinds();
        foreach (['pdf' => 'pdf', 'docx' => 'doc', 'xlsx' => 'xls', 'pptx' => 'ppt', 'png' => 'img'] as $ext => $kind) {
            $this->assertSame($kind, $kinds[$ext]);
            $this->assertSame($kind, KbPages::fileKind('x.' . $ext));
        }
        $this->assertStringContainsString('KbPages::fileKinds()', self::src(self::FORM), 'La fiche pose les mêmes pastilles que la page.');
    }

    // ---------- Page de l'assistant ----------

    public function testLaPageEstRouteeEtGardee(): void
    {
        $routes = self::src('public/index.php');
        $page   = strpos($routes, "\$router->get('/admin/profiles/{id}',      [AdminController::class, 'showProfile']);");
        $this->assertNotFalse($page);
        $this->assertLessThan($page, strpos($routes, "\$router->get('/admin/profiles/new'"), 'Les routes littérales avant {id}.');
        $this->assertLessThan($page, strpos($routes, "\$router->get('/admin/profiles/{id}/edit'"));

        $c = self::src('src/Controllers/AdminController.php');
        $show = substr($c, strpos($c, 'public function showProfile('), 9000);
        $this->assertStringContainsString("AuthMiddleware::requirePermission('assistants.view');", $show);
        $this->assertStringContainsString('Profile::manageableBy($pid, $currentUser)', $show, 'Même porte que la fiche.');
        $this->assertStringContainsString('AssistantPages::actions($profile', $show);
        $this->assertStringContainsString('KnowledgeBase::hiddenFrom(', $show, 'Une base privée d’autrui n’est jamais nommée.');
        $this->assertStringContainsString('Profile::usageSummary($pid)', $show);
        $this->assertStringContainsString("require ROOT . '/templates/admin/profiles/show.php';", $show);
        $this->assertStringContainsString('AssistantDiagram::spec(', $show);
    }

    public function testLaPageMontreToutCeQuiFaitLAssistant(): void
    {
        $tpl = self::src(self::SHOW);
        foreach ([
            'id="asxShow"', 'class="kbx kbx-show is-asst"', 'href="/admin/profiles#assistant-',
            'data-asx-menu', 'data-asx-confirm=', 'name="return" value="page"',
            'action="/chat/new"', 'name="profile_id"', 'data-asx-ask', 'id="asxAskInput"',
            'Profile::USAGE_DAYS', 'id="asxPrompt"', 'id="asxPromptMore"', 'AssistantPages::promptIsLong(',
            'KbPages::tile(', 'KbPages::fileBadge(', 'AssistantPages::temperatureText(', 'TaskDiagram::toolIcon(',
            'AssistantPages::visibilityText(', 'id="asxShowDiagram"', 'window.ASSISTANT_SHOW = ',
            "AdminAttention::itemDot('profile:",
        ] as $marque) {
            $this->assertStringContainsString($marque, $tpl, "Page : $marque absent.");
        }
        $this->assertStringContainsString('JSON_HEX_TAG', $tpl);
        // Moteur, modèle, puis le script de la page.
        $moteur = strpos($tpl, '/assets/js/diagram.js');
        $modele = strpos($tpl, '/assets/js/assistant-diagram.js');
        $page   = strpos($tpl, '/assets/js/assistant-show.js');
        $this->assertNotFalse($moteur);
        $this->assertLessThan($modele, $moteur);
        $this->assertLessThan($page, $modele);
        // Les liens « Modifier » mènent à la bonne section de la fiche.
        foreach (['#consigne', '#connaissances', '#modele', '#capacites'] as $ancre) {
            $this->assertStringContainsString($ancre, $tpl, "Lien vers $ancre absent.");
            $this->assertStringContainsString('id="' . substr($ancre, 1) . '"', self::src(self::FORM), "Section $ancre absente de la fiche.");
        }
    }

    public function testLaQuestionAttendDansLeChat(): void
    {
        $js = self::src(self::SHOW_JS);
        $this->assertStringContainsString("sessionStorage.setItem('composerDraft', q)", $js);
        $this->assertStringContainsString("sessionStorage.getItem('composerDraft')", self::src('public/assets/js/chat.js'), 'Le chat reprend le brouillon.');
        $this->assertStringContainsString('window.miaUiZoom', $js, 'Menu en position fixe : taille d’affichage retirée.');
        $this->assertStringContainsString('MiaAssistantDiagram.mount(host, conf.spec, conf.catalog', $js);
    }

    public function testLaListeMeneALaPage(): void
    {
        $list = self::src(self::LIST);
        $this->assertStringContainsString("'pageUrl'   => '/admin/profiles/' . \$pid", $list);
        $this->assertStringContainsString('AssistantPages::actions($p', $list, 'La liste et la page offrent les mêmes gestes.');
    }

    // ---------- Fiche ----------

    public function testLaFicheSuitLaGrammaireDesBases(): void
    {
        $tpl = self::src(self::FORM);
        foreach ([
            'class="admin-content pf-content kbx-page"', 'class="kbx kbx-fiche is-asst"', 'id="pfFiche"',
            'class="kbx-top"', 'id="pfFicheTitle"', 'id="pfHeadAva"', 'id="pfHeadState"', 'class="kbx-nav"',
            'id="pfMoreBtn"', 'id="pfMoreMenu"', 'id="mpDeleteBtn"',
            'id="mpDocDrop"', 'id="mpPendingFiles"', 'data-autosave-skip',
            "KbPages::tile(\$kbOpt)", '/assets/css/kb-pages.css', '/assets/js/assistant-fiche.js',
        ] as $marque) {
            $this->assertStringContainsString($marque, $tpl, "Fiche : $marque absent.");
        }
        // Chaque entrée du menu des sections mène à une section qui existe.
        $this->assertSame(1, preg_match('#<nav class="kbx-nav"[^>]*>(.*?)</nav>#s', $tpl, $nav));
        preg_match_all('#href="\#([a-z-]+)"#', $nav[1], $ancres);
        $this->assertSame(['identite', 'consigne', 'modele', 'capacites', 'connaissances', 'ensemble'], $ancres[1]);
        foreach ($ancres[1] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $tpl);
        }
        // Le bouton « Uploader » et son champ natif ont cédé la place à la zone de dépôt.
        $this->assertStringNotContainsString('mpDocUploadBtn', $tpl);
        $this->assertStringContainsString("docFileInput.addEventListener('change'", $tpl, 'Un fichier choisi part aussitôt.');
    }

    public function testLesScriptsNOntAucunLibelle(): void
    {
        foreach ([self::SHOW_JS, self::FICHE_JS] as $fichier) {
            $js = self::src($fichier);
            // Aucune chaîne accentuée hors commentaires : les textes viennent du gabarit.
            $sansCommentaires = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
            $this->assertDoesNotMatchRegularExpression("/'[^'\\n]*[éèêàùçôî][^'\\n]*'/u", $sansCommentaires, "$fichier porte un libellé.");
        }
        $fiche = self::src(self::FICHE_JS);
        $this->assertStringContainsString('pending.dataset.remove', $fiche);
        $this->assertStringContainsString('new DataTransfer()', $fiche);
    }

    public function testLaFicheVaDUnBordALAutreAuTelephone(): void
    {
        $pages = self::src('public/assets/css/kb-pages.css');
        $this->assertStringContainsString('body.admin-page .admin-content > .kbx-fiche { width: auto; max-width: none; }', $pages);
        $this->assertMatchesRegularExpression('/@media \(max-width: 768px\) \{\s*\.kbx-fiche \{ margin: -12px -12px 0; \}/', $pages);
        $this->assertMatchesRegularExpression('/@media \(max-width: 420px\) \{\s*\.kbx-fiche \{ margin: -10px -8px 0; \}/', $pages);
        $css = self::src(self::CSS);
        foreach (['.kbx.is-asst', '.asx-ava.is-big', '.asx-ask-go', '.asx-prompt.is-clamped', '.asx-fact', '.asx-group-title', '.asx-kb-tile'] as $classe) {
            $this->assertStringContainsString($classe, $css, "Style absent : $classe");
        }
    }

    public function testLeModeleCompteLUsageEtLesGroupes(): void
    {
        $m = self::src('src/Models/Profile.php');
        $this->assertStringContainsString('public const USAGE_DAYS = 30;', $m);
        $this->assertStringContainsString('public static function usageSummary(int $id', $m);
        $this->assertStringContainsString('public static function authorizedGroups(int $id)', $m);
        $usage = substr($m, strpos($m, 'public static function usageSummary('), 3000);
        $this->assertStringContainsString('tool_usage_logs', $usage, 'Les créations comptent aussi.');
        $this->assertStringContainsString('catch (', $usage, 'Une panne ne casse pas la page.');
    }
}
