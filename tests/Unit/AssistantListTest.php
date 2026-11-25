<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Espaces → Assistants : la liste, piste C (« recherche d'abord »).
 *
 * Ce qu'une ligne dit d'un assistant (AssistantPages, pur), et le câblage de
 * la page : recherche et filtres sur les lignes rendues, ligne → fiche,
 * « Discuter » et menu ⋯ sur la ligne, « Le décrire à MIA », bibliothèque
 * d'avatars dans une fenêtre qui se rouvre après ses gestes.
 */
final class AssistantListTest extends TestCase
{
    private const LIST = 'templates/admin/profiles/list.php';
    private const JS   = 'public/assets/js/assistant-list.js';
    private const CSS  = 'public/assets/css/assistants.css';

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    public function testQuiVoitLAssistant(): void
    {
        $this->assertSame(AssistantPages::VIS_PERSONAL, AssistantPages::visibility(['is_personal' => 1, 'is_global' => 1]), 'Personnel l’emporte.');
        $this->assertSame(AssistantPages::VIS_GLOBAL, AssistantPages::visibility(['is_personal' => 0, 'is_global' => 1]));
        $this->assertSame(AssistantPages::VIS_SHARED, AssistantPages::visibility(['is_personal' => 0, 'is_global' => 0]));
        $this->assertSame(AssistantPages::VIS_SHARED, AssistantPages::visibility([]));

        foreach ([AssistantPages::VIS_GLOBAL, AssistantPages::VIS_SHARED, AssistantPages::VIS_PERSONAL] as $vis) {
            $txt = AssistantPages::visibilityText($vis);
            $this->assertNotSame('', $txt['label']);
            $this->assertNotSame('', $txt['hint']);
            $this->assertStringContainsString('<svg', AssistantPages::visibilityIcon($vis));
        }
        I18n::use('en');
        $this->assertSame('Personal', AssistantPages::visibilityText(AssistantPages::VIS_PERSONAL)['label']);
        $this->assertSame('Everyone, with no group filter.', AssistantPages::visibilityText(AssistantPages::VIS_GLOBAL)['hint']);
    }

    public function testLaCouleurPartDansUnStyleSeulementSiCEstUnHexa(): void
    {
        $this->assertSame('#ea580c', AssistantPages::color(['color' => ' #EA580C ']));
        $this->assertSame(AssistantPages::DEFAULT_COLOR, AssistantPages::color(['color' => 'red;background:url(x)']));
        $this->assertSame(AssistantPages::DEFAULT_COLOR, AssistantPages::color(['color' => '#abc']));
        $this->assertSame(AssistantPages::DEFAULT_COLOR, AssistantPages::color([]));
    }

    public function testInitiale(): void
    {
        $this->assertSame('É', AssistantPages::initial('  éditeur'));
        $this->assertSame('?', AssistantPages::initial(' '));
    }

    public function testLaLigneResumeModeleSavoirEtOutils(): void
    {
        $this->assertSame('Claude Opus 5', AssistantPages::meta('Claude Opus 5', 0, 0, 0));
        $this->assertSame('Claude Opus 5 · 2 bases · 1 doc · 3 outils', AssistantPages::meta('Claude Opus 5', 2, 1, 3));
        $this->assertSame('GPT · 1 base', AssistantPages::meta('GPT', 1, 0, 0));
        I18n::use('en');
        $this->assertSame('GPT · 2 bases · 2 docs · 1 tool', AssistantPages::meta('GPT', 2, 2, 1));
    }

    public function testLeDecrireAMiaEstReconnuParMiaDansLesDeuxLangues(): void
    {
        // La conversation s'ouvre avec cette demande : MIA doit y voir une création d'assistant.
        $this->assertTrue(AssistantDraft::looksLikeAssistantRequest(t('Crée-moi un assistant qui') . ' rédige mes comptes rendus'));
        I18n::use('en');
        $this->assertTrue(AssistantDraft::looksLikeAssistantRequest(t('Crée-moi un assistant qui') . ' writes my meeting notes'));

        $c = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString("\$miaDraftText = t('Crée-moi un assistant qui') . ' ';", $c);
        $tpl = self::src(self::LIST);
        $this->assertStringContainsString('data-mia-draft="<?= htmlspecialchars($miaDraftText) ?>"', $tpl);
        // Même relais que le choix d'un assistant depuis le chat.
        $this->assertStringContainsString("sessionStorage.setItem('composerDraft'", self::src(self::JS));
        $this->assertStringContainsString("sessionStorage.getItem('composerDraft')", self::src('public/assets/js/chat.js'));
    }

    public function testLaListeSuitLaPisteC(): void
    {
        $tpl = self::src(self::LIST);
        foreach ([
            'id="asxSearch"', 'data-scope="all"', 'data-scope="org"', 'data-scope="personal"', 'data-off-filter',
            'data-group="org"', 'data-group="personal"', 'id="asxNone"',
            'href="/admin/profiles/new"', 'class="asx-mk is-mia"',
            'id="assistant-<?= $pid ?>"', 'data-asx-row', 'class="asx-ln-link" href="<?= htmlspecialchars($r[\'pageUrl\']) ?>"',
            'action="/chat/new"', 'name="profile_id"', 'data-asx-menu',
            'action="/admin/profiles/<?= $pid ?>/toggle-personal"', 'action="/admin/profiles/<?= $pid ?>/delete" data-asx-confirm=',
            'href="/admin/user-groups"', 'data-asx-avatars',
            "AssistantPages::visibility(\$p)", "AssistantPages::color(\$p)", "AssistantPages::meta(",
        ] as $marque) {
            $this->assertStringContainsString($marque, $tpl, "Liste : $marque absent.");
        }
        // Demande d'Axel : pas de section « les plus utilisés » ; plus de schéma ni d'anciennes lignes.
        $this->assertStringNotContainsString('plus utilisés', $tpl);
        $this->assertStringNotContainsString('diagram.js', $tpl);
        $this->assertStringNotContainsString('al-row', $tpl);
        $this->assertStringNotContainsString('onsubmit=', $tpl, 'La confirmation passe par le script, texte tiré du gabarit.');
        // Le gabarit charge sa feuille et son script, versionnés.
        $this->assertStringContainsString('/assets/css/assistants.css?v=', $tpl);
        $this->assertStringContainsString('/assets/js/assistant-list.js?v=', $tpl);
        // Le créateur voit ce qu'il lui reste ; la porte se grise au plafond.
        $this->assertStringContainsString("'Vous pouvez encore créer {n} assistants personnels.'", $tpl);
        $this->assertStringContainsString('class="asx-mk is-off" aria-disabled="true"', $tpl);
        // Une colonne vide ne s'affiche, avec son filtre, que pour un administrateur ; la légende ne nomme que ce qui est là.
        $this->assertStringContainsString("'org'      => \$orgCount > 0 || !empty(\$isAdminManager),", $tpl);
        $this->assertStringContainsString('<?php if ($bothGroups): ?>', $tpl);
        $this->assertStringContainsString('!empty($visPresent[AssistantPages::VIS_SHARED])', $tpl);
        $this->assertStringContainsString("root.querySelectorAll('[data-group]').length < 2", self::src(self::JS));
        // Colonnes fixes : l'icône et le menu restent alignés, Discuter ou pas.
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) 26px 76px 70px;', self::src(self::CSS));
        // Rendre personnel efface les autorisations de groupe : confirmé.
        $this->assertStringContainsString("data-asx-confirm=\"<?= te('Rendre « {nom} » personnel ?", $tpl);
    }

    public function testLeScriptNAAucunLibelle(): void
    {
        $js = self::src(self::JS);
        $code = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\s//\s.*$#m'], '', $js);
        $this->assertDoesNotMatchRegularExpression('/[\'"`][^\'"`\n]*[àâçéèêëîïôûùüÿœ][^\'"`\n]*[\'"`]/u', $code, 'Un libellé en français dans le script : il doit venir du gabarit.');
        $this->assertStringContainsString('window.confirm(form.dataset.asxConfirm', $js);
        // Menu posé en position fixe : pixels d'écran ramenés à la taille d'affichage.
        $this->assertStringContainsString('miaUiZoom', $js);
        // « / » ne vole pas la frappe d'un champ.
        $this->assertStringContainsString("/^(input|textarea|select)$/i.test(t.tagName)", $js);
    }

    public function testLesAvatarsViventDansUneFenetreQuiSeRouvre(): void
    {
        $c = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString("\$avatarsOpen = !empty(\$_GET['avatars']);", $c);
        $this->assertSame(1, preg_match('/public function uploadProfileAvatar\(\).*?\/\/ ---- Documents de référence/s', $c, $m));
        $this->assertSame(11, substr_count($m[0], "header('Location: /admin/profiles?avatars=1');"), 'Chaque geste sur un avatar revient dans la fenêtre.');
        $this->assertStringNotContainsString("header('Location: /admin/profiles');", $m[0]);

        $tpl = self::src(self::LIST);
        $this->assertStringContainsString('id="asxAvatars" role="dialog" aria-modal="true"', $tpl);
        $this->assertStringContainsString("<?= empty(\$avatarsOpen) ? ' hidden' : '' ?>", $tpl);
        $this->assertStringContainsString('action="/admin/profiles/avatar/upload"', $tpl);
        $this->assertStringContainsString('action="/admin/profiles/avatar/delete" id="deleteAvatarForm"', $tpl);
        $this->assertStringContainsString('.asx-modal[hidden] { display: none !important; }', self::src(self::CSS), '.modal est en display:flex, qui l’emporterait sur hidden.');
    }

    public function testLeStyleSuitLeThemeEtLeDoigt(): void
    {
        $css = self::src(self::CSS);
        $this->assertStringContainsString('html[data-theme="light"] .asx', $css);
        $this->assertStringContainsString('@media (hover: none)', $css);
        $this->assertStringContainsString('@media (max-width: 720px)', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('.asx-ln.is-menu .asx-acts { z-index: 70; }', $css);
        // Aucune couleur d'assistant écrite en dur : elle vient de --asx-c, validée par le serveur.
        $this->assertStringContainsString('var(--asx-c)', $css);
    }

    public function testLaDescriptionVientEnBulleAuSurvolEtSurLePointDInterrogation(): void
    {
        $tpl = self::src(self::LIST);
        // Le texte : une seule source, lue par un lecteur d'écran avec le lien.
        $this->assertStringContainsString("'desc'      => trim((string)(\$p['description'] ?? '')),", $tpl);
        $this->assertStringContainsString('aria-describedby="<?= $descId ?>"', $tpl);
        $this->assertStringContainsString('<span class="asx-sr" id="<?= $descId ?>" data-asx-desc>', $tpl);
        // Le « ? » suit le nom, seulement quand il y a une description, sans texte à lire en double.
        $this->assertMatchesRegularExpression('/<span class="asx-name">.*<\?php if \(\$desc !== \'\'\): \?><span class="asx-help" data-asx-help aria-hidden="true">/s', $tpl);
        $this->assertStringNotContainsString('title="<?= htmlspecialchars($desc', $tpl, 'Pas d’infobulle native en double de la bulle.');

        $js = self::src(self::JS);
        $this->assertStringContainsString("tip.setAttribute('aria-hidden', 'true');", $js, 'La bulle est visuelle : le lecteur d’écran a déjà la description.');
        $this->assertStringContainsString("const TIP_ROW_MS  = 450;", $js);
        // Toucher ou cliquer le « ? » ouvre la bulle, jamais la fiche : capture + preventDefault.
        $this->assertMatchesRegularExpression("/closest\\('\\[data-asx-help\\]'\\)[^;]*;\\s*if \\(!help\\) return;\\s*e\\.preventDefault\\(\\);\\s*e\\.stopPropagation\\(\\);/", $js);
        $this->assertMatchesRegularExpression('/showTip\(row, true\);\s*\}, true\);/', $js, 'Écoute en capture : le lien étiré ne voit pas le clic.');
        $this->assertStringContainsString("e.target.matches(':focus-visible')", $js, 'Au clavier : avec le focus visible du lien.');
        $this->assertStringContainsString("lastPointer === 'touch'", $js, 'Un toucher n’ouvre pas la bulle du survol.');
        $this->assertStringContainsString('window.miaUiZoom', $js);
        $this->assertMatchesRegularExpression('/closeMenus\(\);\s*hideTip\(\);\s*\}/', $js, 'Filtrer referme la bulle.');

        $css = self::src(self::CSS);
        $this->assertStringContainsString('.asx-help { position: relative; z-index: 1;', $css, 'Sous le lien étiré, le « ? » ne recevrait ni survol ni toucher.');
        $this->assertStringContainsString('.asx-help::before { content: \'\'; position: absolute; inset: -9px; }', $css, 'Cible élargie au doigt.');
        $this->assertStringContainsString('.asx-tip[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('max-width: min(340px, calc(calc(100vw / var(--ui-zoom, 1)) - 16px));', $css);
        $this->assertStringContainsString('.asx-name { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }', $css, 'Un long nom se coupe, le « ? » reste visible.');

        // L'aide de la fiche dit où la description s'affiche désormais.
        $this->assertStringContainsString('la liste des assistants l’affiche au survol de l’assistant', self::src('templates/admin/profiles/edit.php'));
    }

    public function testChargeDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Assistants/AssistantPages.php', self::src($liste), "$liste ne charge pas AssistantPages.");
        }
    }
}
