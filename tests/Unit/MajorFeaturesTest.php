<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Nouveautés MAJEURES (fenêtre « Quoi de neuf », pastille verte) : le
 * catalogue répond à docs/NOUVEAUTES.md, chaque nouveauté désigne une page et
 * une image qui existent, ses textes sont traduits ; les règles de « qui voit
 * quoi » (pure) ; le câblage des gabarits, de la route et des styles.
 */
final class MajorFeaturesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        MajorFeatures::overrideCatalog(null);
        MajorFeatures::setToday(null);
        I18n::use('fr');
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $path);
    }

    // ---------------- Catalogue ----------------

    public function testChaqueNouveauteMajeureDuFichierEstAuCatalogueEtInversement(): void
    {
        $majeures = [];
        foreach (Nouveautes::depuisFichier(self::ROOT) as $e) {
            if ($e['majeure'] === Nouveautes::MAJEURE) {
                $majeures[] = $e['id'];
            }
        }
        $catalogue = array_keys(MajorFeatures::CATALOG);
        sort($majeures);
        sort($catalogue);
        $this->assertNotSame([], $catalogue);
        $this->assertSame($majeures, $catalogue,
            'Une entrée marquée « majeure: oui » dans docs/NOUVEAUTES.md doit avoir sa présentation dans MajorFeatures::CATALOG, et inversement.');
    }

    public function testLaMarqueMajeureEstValidee(): void
    {
        $base = ['id' => 'x-y', 'titre' => 'T', 'corps' => 'C', 'genre' => 'nouveaute', 'pour' => 'tous', 'date' => '', 'version' => ''];
        $this->assertSame([], Nouveautes::valider([$base + ['majeure' => 'oui']]));
        $this->assertNotSame([], Nouveautes::valider([$base + ['majeure' => 'non']]));
        $this->assertNotSame([], Nouveautes::valider([['genre' => 'amelioration', 'majeure' => 'oui'] + $base]),
            'Une amélioration n’est jamais majeure.');
        $entrees = Nouveautes::parse("## Titre\n<!-- id: abc | genre: nouveaute | pour: tous | majeure: oui -->\n\nTexte.\n");
        $this->assertSame('oui', $entrees[0]['majeure']);
    }

    public function testChaqueNouveauteEstBienFormee(): void
    {
        $publiees = [];
        foreach (Nouveautes::chargePubliee(self::ROOT)['nouveautes'] as $e) {
            $publiees[$e['id']] = $e;
        }
        $dates = array_column(array_map(static fn($f) => ['d' => $f['date']], MajorFeatures::CATALOG), 'd');
        $tri = $dates;
        rsort($tri, SORT_STRING);
        $this->assertSame($tri, $dates, 'Le catalogue va de la plus récente à la plus ancienne.');

        foreach (MajorFeatures::CATALOG as $id => $f) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $f['date'], $id);
            if (isset($publiees[$id]) && $publiees[$id]['date'] !== '') {
                $this->assertSame($publiees[$id]['date'], $f['date'], "$id : même date que son entrée des Nouveautés.");
            }
            $this->assertNotNull(MajorFeatures::target($f), "$id : la cible ne désigne aucune page connue.");
            $this->assertLessThanOrEqual(60, mb_strlen($f['titre']), "$id : titre trop long pour la carte.");
            $this->assertLessThanOrEqual(170, mb_strlen($f['texte']), "$id : deux phrases courtes au plus.");
            $this->assertStringStartsWith('/assets/img/nouveautes/', $f['image']);
            $this->assertFileExists(self::ROOT . '/public' . $f['image'], "$id : image absente.");
            $this->assertTrue(
                !isset($f['condition']) || in_array($f['condition'], ['office'], true),
                "$id : condition inconnue (elle ne serait jamais remplie)."
            );
            foreach (['titre', 'texte'] as $champ) {
                $this->assertDoesNotMatchRegularExpression('/\.php|src\/|->|API|OAuth|SSO/', $f[$champ], "$id : pas de jargon.");
            }
        }
    }

    public function testLesTextesSontTraduits(): void
    {
        $dict = require self::ROOT . '/lang/en.php';
        foreach (MajorFeatures::CATALOG as $id => $f) {
            $this->assertArrayHasKey($f['titre'], $dict, "$id : titre sans traduction anglaise.");
            $this->assertArrayHasKey($f['texte'], $dict, "$id : texte sans traduction anglaise.");
        }
        foreach (['Nouveau : {titre}', 'Une nouveauté depuis votre dernière visite', '{n} nouveautés depuis votre dernière visite'] as $cle) {
            $this->assertArrayHasKey($cle, $dict, $cle);
        }
        I18n::use('en');
        $item = MajorFeatures::item('workflows');
        $this->assertSame('Workflows', $item['titre']);
        $this->assertSame('/workflows', $item['lien']);
        $this->assertSame('/settings/connections#office-addin', MajorFeatures::item('claude-dans-excel')['lien']);
        // Les nouveaux modèles : pour qui ouvre Modèles IA → Fournisseurs et modèles.
        $this->assertSame('/admin/llms', MajorFeatures::item('catalogue-llm-novembre-2026')['lien']);
        $this->assertSame('Claude Fable 5.2, Gemini 4 Argon and GPT-6.1 Terra', MajorFeatures::item('catalogue-llm-novembre-2026')['titre']);
    }

    public function testLesCiblesDesignentLesBonnesPages(): void
    {
        $this->assertSame(
            ['place' => 'espace', 'key' => 'workflows', 'page' => 'workflows', 'href' => '/workflows'],
            MajorFeatures::target(['cible' => ['espace', 'workflows']])
        );
        $this->assertSame('connections', MajorFeatures::target(['cible' => ['compte', 'connections']])['page']);
        $this->assertSame('budget', MajorFeatures::target(['cible' => ['admin', 'budget']])['page']);
        $this->assertNull(MajorFeatures::target(['cible' => ['espace', 'inconnu']]));
        $this->assertNull(MajorFeatures::target(['cible' => ['ailleurs', 'workflows']]));
        $this->assertNull(MajorFeatures::target([]));
    }

    public function testUneConditionInconnueNeSeRemplitJamais(): void
    {
        $this->assertTrue(MajorFeatures::conditionMet('', ['id' => 1, 'role' => 'user']));
        $this->assertFalse(MajorFeatures::conditionMet('quelquechose', ['id' => 1, 'role' => 'admin']));
    }

    // ---------------- Qui voit quoi (pure) ----------------

    private const DATES = ['c' => '2026-06-10', 'b' => '2026-05-20', 'a' => '2026-03-01'];

    public function testUneNouveauteNonVueEstMontreeEtPorteUnePastille(): void
    {
        $p = MajorFeatures::plan(self::DATES, [], ['b', 'c'], '2026-01-01', '2026-06-15');
        $this->assertSame(['c', 'b'], $p['popup']);
        $this->assertSame(['c', 'b'], $p['pending']);
        $this->assertSame(['c', 'b'], $p['dots']);
    }

    public function testOnNeMontreQueCeQueLaPersonnePeutUtiliser(): void
    {
        $p = MajorFeatures::plan(self::DATES, [], ['b'], '2026-01-01', '2026-06-15');
        $this->assertSame(['b'], $p['popup']);
        $this->assertSame(['b'], $p['dots']);
    }

    public function testUnCompteNeufNeVoitRien(): void
    {
        $p = MajorFeatures::plan(self::DATES, [], ['a', 'b', 'c'], '2026-06-10', '2026-06-15');
        $this->assertSame([], $p['popup']);
        $this->assertSame([], $p['dots'], 'Tout est nouveau pour un compte neuf : ni fenêtre ni pastille.');
        $p = MajorFeatures::plan(self::DATES, [], ['a', 'b', 'c'], '2026-05-25', '2026-06-15');
        $this->assertSame(['c'], $p['popup']);
    }

    public function testUneNouveauteTropAncienneNEstPlusMontree(): void
    {
        $p = MajorFeatures::plan(self::DATES, [], ['a', 'b', 'c'], '2025-01-01', '2026-06-15');
        $this->assertSame(['c', 'b'], $p['popup'], '« a » a plus de 90 jours.');
        $this->assertNotContains('a', $p['dots']);
        $p = MajorFeatures::plan(['x' => '2026-06-20'], [], ['x'], '2025-01-01', '2026-06-15');
        $this->assertSame([], $p['popup'], 'Une nouveauté datée dans le futur attend son jour.');
    }

    public function testUneFoisMontreeElleNeRevientPasMaisGardeSaPastille(): void
    {
        $rows = ['c' => ['shown_days' => 0, 'visited' => false]];
        $p = MajorFeatures::plan(self::DATES, $rows, ['b', 'c'], '2026-01-01', '2026-06-15');
        $this->assertSame(['b'], $p['popup']);
        $this->assertSame(['c', 'b'], $p['dots']);
    }

    public function testLaVisiteEteintLaPastille(): void
    {
        // markVisited pose aussi shown_at : une page visitée n'a plus de fenêtre.
        $rows = ['c' => ['shown_days' => 2, 'visited' => true], 'b' => ['shown_days' => 0, 'visited' => true]];
        $p = MajorFeatures::plan(self::DATES, $rows, ['b', 'c'], '2026-01-01', '2026-06-15');
        $this->assertSame([], $p['dots']);
        $this->assertSame([], $p['popup']);
    }

    public function testLaPastilleSEteintSeuleApresTrenteJours(): void
    {
        $p = MajorFeatures::plan(self::DATES, ['c' => ['shown_days' => MajorFeatures::DOT_DAYS, 'visited' => false]], ['c'], '2026-01-01', '2026-06-15');
        $this->assertSame(['c'], $p['dots']);
        $p = MajorFeatures::plan(self::DATES, ['c' => ['shown_days' => MajorFeatures::DOT_DAYS + 1, 'visited' => false]], ['c'], '2026-01-01', '2026-06-15');
        $this->assertSame([], $p['dots']);
    }

    public function testAuPlusTroisParFenetreLesAutresSontCompteesVues(): void
    {
        $dates = ['d' => '2026-06-14', 'c' => '2026-06-13', 'b' => '2026-06-12', 'a' => '2026-06-11'];
        $p = MajorFeatures::plan($dates, [], array_keys($dates), '2026-01-01', '2026-06-15');
        $this->assertSame(['d', 'c', 'b'], $p['popup']);
        $this->assertSame(['d', 'c', 'b', 'a'], $p['pending']);
    }

    public function testLOrdreSuitLaDateQuelQueSoitCeluiDuTableau(): void
    {
        $p = MajorFeatures::plan(['a' => '2026-06-01', 'b' => '2026-06-10'], [], ['a', 'b'], '2026-01-01', '2026-06-15');
        $this->assertSame(['b', 'a'], $p['popup']);
    }

    public function testEcartEnJours(): void
    {
        $this->assertSame(31, MajorFeatures::daysBetween('2026-01-01', '2026-02-01'));
        $this->assertSame(-1, MajorFeatures::daysBetween('2026-01-02', '2026-01-01'));
        $this->assertSame(0, MajorFeatures::daysBetween('pas-une-date', '2026-01-01'));
    }

    public function testLaPastille(): void
    {
        $this->assertSame('', MajorFeatures::dotHtml([]));
        $html = MajorFeatures::dotHtml(['workflows']);
        $this->assertStringContainsString('class="news-dot"', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Nouveau : Les workflows"', $html);
        $this->assertStringContainsString('is-inline', MajorFeatures::dotHtml(['workflows'], 'is-inline'));
    }

    public function testSansCompteNiCatalogueRienNEstLu(): void
    {
        $this->assertSame(['popup' => [], 'pending' => [], 'dots' => []], MajorFeatures::forViewer(null));
        MajorFeatures::overrideCatalog([]);
        $this->assertSame(['popup' => [], 'pending' => [], 'dots' => []], MajorFeatures::forViewer(['id' => 5]));
    }

    // ---------------- Schémas animés ----------------

    /** Rend le schéma d'une nouveauté, comme la fenêtre (partial inclus tel quel). */
    private function renderSchema(string $name): string
    {
        $file = self::ROOT . '/templates/partials/feature-news/' . $name . '.php';
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public function testChaqueNouveauteSePresenteParUnSchemaAnime(): void
    {
        foreach (MajorFeatures::CATALOG as $id => $f) {
            $this->assertArrayHasKey('schema', $f, "$id : une nouveauté majeure se présente par un schéma animé.");
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $f['schema'], $id);
            $this->assertFileExists(self::ROOT . '/templates/partials/feature-news/' . $f['schema'] . '.php', "$id : schéma absent.");
            $this->assertSame($f['schema'], MajorFeatures::item($id)['schema'], "$id : la fenêtre reçoit son schéma.");
        }
    }

    public function testLesSchemasParlentEnVraisMotsDansLaLangueDeLEcran(): void
    {
        foreach (MajorFeatures::CATALOG as $id => $f) {
            $fr = $this->renderSchema($f['schema']);
            $this->assertMatchesRegularExpression('/^<div class="fns fns-[a-z]+" role="img" aria-label="[^"]{20,}">/', trim($fr),
                "$id : un schéma est une image pour les lecteurs d’écran, décrite par une phrase.");
            $this->assertStringNotContainsString('<script', $fr, "$id : aucun script, tout est en CSS.");
            $this->assertStringNotContainsString('<img', $fr, "$id : du texte, pas une image.");
        }
        I18n::use('en');
        $wf = $this->renderSchema('workflows');
        $this->assertStringContainsString('MIA writes', $wf);
        $this->assertStringContainsString('Example: responding to a call for tenders', $wf);
        $xl = $this->renderSchema('claude-dans-excel');
        $this->assertStringContainsString('Which region is growing fastest?', $xl);
        $this->assertStringContainsString('Paris region: +12%. I’ve added a chart.', $xl);
        $this->assertStringContainsString('Sales 2026.xlsx', $xl);
        $md = $this->renderSchema('nouveaux-modeles');
        $this->assertStringContainsString('Example: offering the new models', $md);
        $this->assertStringContainsString('Providers and models', $md);
        $this->assertStringContainsString('Offered in the chat right away', $md);
        $this->assertStringContainsString('Anthropic · the most capable', $md);
        // Les noms de modèles sont des noms de produit : jamais traduits.
        foreach (['Claude Fable 5.2', 'Gemini 4 Argon', 'GPT-6.1 Terra'] as $modele) {
            $this->assertStringContainsString('<b>' . $modele . '</b>', $md);
        }
    }

    public function testLaFenetreChargeLesSchemasEtNeVaPasChercherAilleurs(): void
    {
        $modal = $this->read('templates/partials/feature-news-modal.php');
        $this->assertStringContainsString('/assets/css/feature-news.css', $modal);
        $this->assertStringContainsString("preg_match('/^[a-z0-9-]+$/', \$name)", $modal, 'Un nom de schéma ne désigne qu’un fichier du dossier.');
        $this->assertStringContainsString("'/templates/partials/feature-news/'", $modal);
        $this->assertStringContainsString("\$fnItem['image'] !== ''", $modal, 'L’image reste le repli.');
    }

    public function testLesSchemasSAnimentSansGenerQuiNeVeutPasDeMouvement(): void
    {
        $css = $this->read('public/assets/css/feature-news.css');
        $this->assertStringContainsString('container-type', $this->read('public/assets/css/main.css'), 'Le cadre est un conteneur : le schéma se règle sur sa largeur.');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.fns \*, \.fns \*::before, \.fns \*::after \{ animation: none !important; \}/', $css);
        // Les deux thèmes : chaque schéma redéfinit son fond pour le sombre ET le clair.
        foreach (['.fns-wf', '.fns-xl', '.fns-md'] as $sel) {
            $this->assertStringContainsString(':root:not([data-theme="light"]) ' . $sel, $css);
            $this->assertStringContainsString('[data-theme="light"] ' . $sel, $css);
        }
        // Aucune taille en pixels dans un schéma (hors filets de 1 px) : tout suit la largeur du cadre.
        $schemas = substr($css, (int) strpos($css, '/* ---- Socle'));
        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])(?!1px\b)\d+(\.\d+)?px/', preg_replace('#/\*.*?\*/#s', '', $schemas) ?? '', 'Une taille en pixels ne suivrait pas la largeur du cadre.');
    }

    // ---------------- Câblage ----------------

    public function testLaFenetreEstRendueSurLaConversationSeulement(): void
    {
        $chat = $this->read('templates/chat/index.php');
        $this->assertStringContainsString("templates/partials/feature-news-modal.php", $chat);
        $modal = $this->read('templates/partials/feature-news-modal.php');
        $this->assertStringContainsString('MajorFeatures::forViewer', $modal);
        $this->assertStringContainsString('id="featureNews" hidden', $modal);
        $this->assertStringContainsString('data-fn-go', $modal);
        $this->assertStringContainsString('data-fn-close', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString("['pending']", $modal, 'La fenêtre compte vues aussi celles qu’elle n’a pas la place de montrer.');
    }

    public function testLeScriptMarqueVueAOuvertureEtNePorteAucunTexte(): void
    {
        $js = $this->read('public/assets/js/feature-news.js');
        $this->assertStringContainsString("'/news/seen'", $js);
        $this->assertStringContainsString('markSeen();', $js);
        $this->assertStringContainsString("e.key === 'Escape'", $js);
        $this->assertStringContainsString("'X-CSRF-Token'", $js);
        $this->assertDoesNotMatchRegularExpression('/(?<![\\w.$])t\\(/', $js, 'Aucun texte à traduire dans le script.');
        // Hors commentaires, aucune lettre accentuée : les textes sont dans le gabarit.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js) ?? $js;
        $this->assertDoesNotMatchRegularExpression('/[àâçéèêëîïôûùüÿœ]/iu', $code);
    }

    public function testLaRouteEtLesClassesSontBranchees(): void
    {
        $index = $this->read('public/index.php');
        $this->assertStringContainsString("\$router->post('/news/seen',", $index);
        $this->assertStringContainsString("FeatureNewsController::class, 'seen'", $index);
        $this->assertStringContainsString("/src/Changelog/MajorFeatures.php", $this->read('config/classes.php'));
        $this->assertStringContainsString("/src/Controllers/FeatureNewsController.php", $this->read('config/classes.php'));
        $this->assertStringContainsString("/src/Changelog/MajorFeatures.php", $this->read('tests/bootstrap.php'));
        $ctrl = $this->read('src/Controllers/FeatureNewsController.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $ctrl);
        $this->assertStringContainsString('MajorFeatures::markShown(', $ctrl);
    }

    public function testLaVisiteEstConsigneeAvantLeRenduDuMenu(): void
    {
        $nav = $this->read('templates/partials/admin-nav.php');
        $visite = strpos($nav, 'MajorFeatures::forViewer($navUser, $page)');
        $this->assertNotFalse($visite);
        $this->assertLessThan(strpos($nav, '<div class="admin-mobile-header"'), $visite,
            'La visite doit être consignée avant toute pastille : la page où l’on arrive ne la repose pas.');
    }

    public function testLesPastillesSontPoseesSurLesAcces(): void
    {
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_SPACE, $sbKey)', $this->read('templates/partials/sidebar-shortcuts.php'));
        $actions = $this->read('templates/partials/user-account-actions.php');
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_ACCOUNT)', $actions);
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_ADMIN)', $actions);
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_ACCOUNT)', $this->read('templates/partials/header-avatar-btn.php'));
        $nav = $this->read('templates/partials/admin-nav.php');
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_ACCOUNT, $navKey)', $nav);
        $this->assertStringContainsString('MajorFeatures::dotAt(MajorFeatures::PLACE_ADMIN, $p)', $nav);
        $this->assertStringContainsString('MajorFeatures::dotsAt(MajorFeatures::PLACE_SPACE)', $this->read('templates/chat/index.php'));
    }

    public function testLesStylesDistinguentLaPastilleDeLaCouleurDeLOrganisation(): void
    {
        $css = $this->read('public/assets/css/main.css');
        $this->assertStringContainsString('--news-green: #22c55e', $css);
        $this->assertMatchesRegularExpression('/\.news-dot \{[^}]*box-shadow: 0 0 0 2px var\(--news-ring/', $css);
        $this->assertStringContainsString('.fn-modal[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.fn-count-3 .fn-cards', $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.fn-modal, \.fn-dialog/', $css);
    }

    public function testLaTableEstMigreeEtHorsSauvegarde(): void
    {
        $sql = $this->read('database/migrations/104_user_feature_news.sql');
        $this->assertStringContainsString('CREATE TABLE user_feature_news', $sql);
        $this->assertStringContainsString('PRIMARY KEY (user_id, feature_id)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertContains('user_feature_news', BackupService::EXCLUDED_TABLES);
    }

    public function testLaRegleEstDocumentee(): void
    {
        $this->assertStringContainsString('`majeure` : oui', $this->read('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Quoi de neuf', $this->read('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Quoi de neuf', $this->read('docs/user/fonctionnalites/resoudre-un-probleme.md'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneDitLaRegle(): void
    {
        $this->assertStringContainsString('## Nouveautés MAJEURES', $this->read('CLAUDE.md'));
    }
}
