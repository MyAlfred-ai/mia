<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Volet de gauche repliable (piste A, choisie le 2026-11-09) — cf. CLAUDE.md
 * « Menus en trois espaces », puce « Volet repliable ».
 *
 * Garde : Espaces, Épinglées et Historique se replient (en-tête à chevron,
 * « Tout replier » dans l'en-tête du volet), l'état est posé AVANT le premier
 * rendu ; chaque espace a sa couleur et une ligne d'information
 * (SidebarSpaces, source unique) ; repliés, les espaces restent une rangée
 * d'icônes cliquables ; l'historique est rangé par UN seul script, partagé
 * par la page de discussion et le volet des pages d'espace.
 */
final class SidebarSectionsTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
        SidebarSpaces::forget();
    }

    public function testChaqueEspaceASaTeinte(): void
    {
        $this->assertSame(array_keys(NavRegistry::WORKSPACE), array_keys(SidebarSpaces::TONES), 'Un espace ajouté doit recevoir sa teinte.');
        $css = self::lire('public/assets/css/main.css');
        foreach (SidebarSpaces::TONES as $ton) {
            $this->assertStringContainsString(".space-$ton ", $css, "Teinte sombre absente : $ton");
            $this->assertStringContainsString("[data-theme=\"light\"] .space-$ton ", $css, "Teinte claire absente : $ton");
        }
        $this->assertSame('neutral', SidebarSpaces::tone('inconnu'));
    }

    public function testLaLigneDInformationDitLEssentiel(): void
    {
        I18n::use('fr');
        $txt = static fn(string $k, array $c): ?string => SidebarSpaces::metaText($k, $c)['text'] ?? null;

        $this->assertSame('12 disponibles', $txt('profiles', ['profiles' => 12]));
        $this->assertSame('1 disponible', $txt('profiles', ['profiles' => 1]));
        $this->assertSame('Aucun disponible', $txt('profiles', ['profiles' => 0]));
        $this->assertSame('8 bases', $txt('kb', ['kb' => 8]));
        $this->assertSame('Aucune base', $txt('kb', ['kb' => 0]));
        $this->assertSame('2 actives', $txt('schedules', ['schedules' => 2]));
        $this->assertSame('Aucune active', $txt('schedules', ['schedules' => 0]));
        $this->assertSame('Images, pages, présentations', $txt('creations', []));

        // Workflows : ce qui VOUS attend d'abord, puis ce qui tourne, puis le nombre.
        $attend = SidebarSpaces::metaText('workflows', ['wf_waiting' => 2, 'wf_running' => 1, 'wf_total' => 5]);
        $this->assertSame(['text' => '2 vous attendent', 'attention' => true], $attend);
        $this->assertSame('1 en cours', $txt('workflows', ['wf_waiting' => 0, 'wf_running' => 1, 'wf_total' => 5]));
        $this->assertSame('5 workflows', $txt('workflows', ['wf_waiting' => 0, 'wf_running' => 0, 'wf_total' => 5]));
        $this->assertSame('Aucun workflow', $txt('workflows', ['wf_waiting' => 0, 'wf_running' => 0, 'wf_total' => 0]));

        // Lecture impossible : la tuile se tait plutôt que d'afficher un faux zéro.
        foreach (['profiles', 'kb', 'schedules', 'workflows'] as $k) {
            $this->assertNull(SidebarSpaces::metaText($k, []), $k);
        }
        $this->assertNull(SidebarSpaces::metaText('inconnu', ['profiles' => 3]));

        I18n::use('en');
        $this->assertSame('1 needs you', $txt('workflows', ['wf_waiting' => 1]));
        $this->assertSame('3 available', $txt('profiles', ['profiles' => 3]));
        $this->assertSame('No bases', $txt('kb', ['kb' => 0]));
    }

    /** Comptes déjà lus par la page de discussion : repris tels quels, sans requête. */
    public function testLesComptesConnusNeSontPasRelus(): void
    {
        $this->assertSame([], SidebarSpaces::metas(null, ['profiles']));
        $user = ['id' => 987654, 'role' => 'admin', 'is_active' => 1];
        $metas = SidebarSpaces::metas($user, ['profiles', 'kb', 'creations'], ['profiles' => 4, 'kb' => 0]);
        $this->assertSame('4 disponibles', $metas['profiles']['text']);
        $this->assertSame('Aucune base', $metas['kb']['text']);
        $this->assertSame('Images, pages, présentations', $metas['creations']['text']);
    }

    public function testLaSectionEspacesSeReplieEnRangeeDIcones(): void
    {
        $tpl = self::lire('templates/partials/sidebar-shortcuts.php');
        // L'état des sections est posé dans <head> (head-meta.php), sur toutes
        // les pages qui ont un volet : plus rien à requérir ici.
        $this->assertStringNotContainsString("require ROOT . '/templates/partials/sidebar-sections-boot.php'", $tpl);
        $head = self::lire('templates/partials/head-meta.php');
        $this->assertStringContainsString("<?php if (\$miaHeadUserId > 0) { require ROOT . '/templates/partials/sidebar-sections-boot.php'; } ?>", $head);
        $this->assertLessThan(strpos($head, 'sidebar-sections-boot.php'), strpos($head, 'name="mia-user"'), 'Le compte est connu avant le script.');
        $this->assertStringContainsString('data-sb-toggle="spaces" aria-expanded="true" aria-controls="sbSpacesGrid"', $tpl);
        $this->assertStringContainsString('<div class="sidebar-spaces-grid" id="sbSpacesGrid">', $tpl);
        $this->assertStringContainsString('<div class="sidebar-spaces-mini">', $tpl);
        $this->assertStringContainsString('class="space-mini space-<?= SidebarSpaces::tone($sbKey) ?>', $tpl);
        $this->assertStringContainsString('class="sb-mini-open" data-sb-toggle="spaces"', $tpl);
        $this->assertStringContainsString('SidebarSpaces::metas($sbNavUser, array_keys($sbNavItems), $sbNavKnown ?? [])', $tpl);
        $this->assertStringContainsString('<span class="space-tile-meta<?= $sbMeta[\'attention\'] ? \' is-attention\' : \'\' ?>">', $tpl);
        // Les pastilles (nouveauté, points à régler) suivent l'espace replié.
        $this->assertSame(2, substr_count($tpl, 'MajorFeatures::dotAt(MajorFeatures::PLACE_SPACE, $sbKey)'));
        $this->assertStringNotContainsString('space-tile-all', $tpl, 'La recherche est dans le bloc de départ.');

        // La page de discussion passe les comptes qu'elle a déjà.
        $chat = self::lire('templates/chat/index.php');
        $this->assertStringContainsString("\$sbNavKnown = ['profiles' => count(\$profiles ?? []), 'kb' => count(\$chatKnowledgeBases ?? [])];", $chat);

        // Avant le premier rendu : l'état du COMPTE, mêmes sections que le
        // module (cf. SidebarPreferencesTest).
        $boot = self::lire('templates/partials/sidebar-sections-boot.php');
        $this->assertStringContainsString('SidebarPreferences::clientConfig(Session::currentUser())', $boot);
        $this->assertStringContainsString("['spaces', 'pinned', 'history', 'account'].forEach", $boot);
        $this->assertStringContainsString("h.setAttribute('data-sb-' + k, 'closed')", $boot);
        // Rendu dans <head>, AVANT <meta charset> : le script reste pur ASCII.
        preg_match('/<script>.*?<\/script>/s', $boot, $m);
        $this->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $m[0] ?? 'é'), 'Le script de démarrage contient un caractère non ASCII.');
        $js = self::lire('public/assets/js/sidebar-sections.js');
        $this->assertStringContainsString("var CFG = window.MIA_SIDEBAR || { state: {}, defaults: {}, saved: true };", $js);
        $this->assertStringContainsString("var SECTIONS = ['spaces', 'pinned', 'history', 'account'];", $js);
        $this->assertSame(SidebarPreferences::SECTIONS, ['spaces', 'pinned', 'history', 'account'], 'Mêmes sections côté serveur.');
    }

    /**
     * Carte du compte (pied du volet, sur toutes les pages : chat, espaces,
     * réglages, administration) : un chevron la replie en une rangée — avatar
     * cerclé de ses crédits, Réglages et Admin en icônes ; l'avatar ou le
     * chevron la redéplient. Nom et crédits restent lus (masqués à l'œil).
     */
    public function testLaCarteDuCompteSeReplieEnUneRangee(): void
    {
        $tpl = self::lire('templates/partials/user-account-trigger.php');
        $pDock = strpos($tpl, 'id="userDock"');
        $pTog  = strpos($tpl, 'class="user-dock-toggle" data-sb-toggle="account" aria-expanded="true" aria-controls="userDock"');
        $pInfo = strpos($tpl, 'id="userAccountCard"');
        $this->assertIsInt($pTog);
        $this->assertTrue($pDock < $pTog && $pTog < $pInfo, 'Le chevron est dans la carte, avant l’identité.');
        $this->assertStringContainsString('data-label-fold="<?= te(\'Replier le compte\') ?>" data-label-unfold="<?= te(\'Déplier le compte\') ?>"', $tpl);
        $this->assertStringContainsString('<span class="user-info-text"', $tpl);
        // Anneau des crédits : mêmes valeurs que la barre, posées sur la carte.
        $this->assertStringContainsString("style=\"--quota-left:' . max(0, 100 - \$quotaPct) . '%;--quota-color:' . \$quotaBarColor", $tpl);
        $this->assertStringContainsString('<span class="user-avatar-wrap" title="<?= htmlspecialchars($dockAvatarTitle) ?>">', $tpl);

        // Les trois volets portent la carte ; les réglages et l'administration
        // chargent le module (le volet du chat le charge avec l'historique).
        $nav = self::lire('templates/partials/admin-nav.php');
        $pFoot = strpos($nav, "partials/user-account-trigger.php'");
        $pJs   = strpos($nav, '<script src="/assets/js/sidebar-sections.js?v=');
        $this->assertIsInt($pJs);
        $this->assertTrue($pFoot < $pJs, 'Le module suit la carte du compte.');

        $js = self::lire('public/assets/js/sidebar-sections.js');
        // Libellé du chevron selon l'état ; avatar d'une carte repliée = déplier.
        $this->assertStringContainsString("b.getAttribute(isOpen(k) ? 'data-label-fold' : 'data-label-unfold')", $js);
        $this->assertStringContainsString("!isOpen('account') && e.target.closest && e.target.closest('.user-dock .user-info')", $js);
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded', applySections)", $js);

        // chat.js tient l'anneau et l'infobulle à jour avec la barre.
        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('function syncAccountDockQuota(leftPct, color, creditText)', $chat);
        $this->assertStringContainsString("syncAccountDockQuota(hasQuota ? 100 - pct : null, barColor, remainingEl ? remainingEl.textContent.trim() : '');", $chat);

        $css = self::lire('public/assets/css/main.css');
        foreach ([
            'html[data-sb-account="closed"] .user-dock {',
            'html[data-sb-account="closed"] .user-dock-toggle > .sb-chevron { transform: rotate(180deg); }',
            'html[data-sb-account="closed"] .user-info-text {',
            'html[data-sb-account="closed"] .user-dock.has-quota .user-avatar-wrap::before {',
            'background: conic-gradient(var(--quota-color, var(--accent)) var(--quota-left, 100%)',
            'html[data-sb-account="closed"] .user-action-btn { width: 40px; height: 40px; }',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css, "Règle CSS absente : $regle");
        }
        // Le chevron tourne d'après <html> (posé avant le rendu), pas d'après
        // aria-expanded (posé plus tard) : la règle générique est neutralisée.
        $this->assertGreaterThan(
            strpos($css, '[aria-expanded="false"] > .sb-chevron { transform: rotate(-90deg); }'),
            strpos($css, '.user-dock-toggle > .sb-chevron { transform: none; }')
        );
    }

    public function testToutReplierEtHistoriqueRepliable(): void
    {
        $bouton = self::lire('templates/partials/sidebar-fold-all.php');
        $this->assertStringContainsString('data-sb-all', $bouton);
        $this->assertStringContainsString('data-label-fold="<?= te(\'Tout replier\') ?>" data-label-unfold="<?= te(\'Tout déplier\') ?>"', $bouton);
        foreach (['templates/chat/index.php', 'templates/partials/chat-shell-sidebar.php'] as $f) {
            $src = self::lire($f);
            $pHead = strpos($src, 'class="sidebar-header"');
            $pFold = strpos($src, "require ROOT . '/templates/partials/sidebar-fold-all.php'");
            $pList = strpos($src, 'id="convList"');
            $pJs   = strpos($src, '<script src="/assets/js/sidebar-sections.js?v=');
            $this->assertTrue($pHead < $pFold && $pFold < $pList, "$f : « Tout replier » dans l'en-tête.");
            $this->assertTrue($pList < $pJs, "$f : le script suit la liste (groupes avant le premier rendu).");
            $this->assertStringNotContainsString('defer src="/assets/js/sidebar-sections.js', $src, "$f : sans defer.");
        }

        $js = self::lire('public/assets/js/sidebar-sections.js');
        // Une période par groupe, chacune repliable ; Aujourd'hui et Hier dépliées par défaut.
        $this->assertStringContainsString("var OPEN_BY_DEFAULT = { today: true, yesterday: true };", $js);
        $this->assertStringContainsString("head.setAttribute('data-sb-group', key);", $js);
        $this->assertStringContainsString("hist.setAttribute('data-sb-toggle', 'history');", $js);
        $this->assertStringContainsString("head.setAttribute('data-sb-toggle', 'pinned');", $js);
        // La période de la conversation ouverte reste visible.
        $this->assertStringContainsString('if (ctx.active === key) return true;', $js);
        // Un choix de la personne l'emporte sur la règle.
        $this->assertStringContainsString("if (Object.prototype.hasOwnProperty.call(state.groups, key)) return !!state.groups[key];", $js);
        $this->assertStringContainsString('window.MiaSidebarSections = { regroup: regroup, apply: applySections };', $js);

        $css = self::lire('public/assets/css/main.css');
        foreach ([
            'html[data-sb-spaces="closed"] .sidebar-spaces-grid { display: none; }',
            'html[data-sb-spaces="closed"] .sidebar-spaces-mini { display: flex; }',
            'html[data-sb-history="closed"] #convList .conv-group:not(.conv-group--pinned) { display: none; }',
            'html[data-sb-pinned="closed"] .conv-group--pinned > .conv-item[data-id] { display: none; }',
            '.conv-group.is-folded > .conv-item[data-id] { display: none; }',
            '[aria-expanded="false"] > .sb-chevron { transform: rotate(-90deg); }',
            '.space-tile:last-child:nth-child(odd) {',
            '.space-tile.active .space-tile-icon { background: var(--space); color: #fff; }',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css, "Règle CSS absente : $regle");
        }
    }

    public function testTraductionsEtDocumentation(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (['Tout replier', 'Tout déplier', 'Déplier les espaces', 'Replier le compte', 'Déplier le compte', 'Aucun disponible', '{n} disponible', '{n} disponibles',
                  'Aucune base', '{n} base', '{n} bases', 'Aucune active', '{n} active', '{n} actives', '{n} vous attend',
                  '{n} vous attendent', '{n} en cours', 'Aucun workflow', '{n} workflow', '{n} workflows', 'Images, pages, présentations'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
        $client = (array)(require dirname(__DIR__, 2) . '/lang/en.client.php');
        $this->assertArrayHasKey('Historique', $client);

        $this->assertStringContainsString('Tout replier', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('volet-repliable', self::lire('docs/NOUVEAUTES.md'));
    }
}
