<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Navigation du volet de gauche du chat — cf. CLAUDE.md « Menu du compte, roue
 * crantée, bouclier » (section « Volet de gauche »).
 *
 * Garde : le volet ouvre sur un bloc de départ — « Nouvelle conversation » et,
 * collée dessous, la recherche ; qui gère des assistants (créateur ou
 * administrateur) trouve ensuite Assistants, Tâches programmées et Workflows
 * (si ces fonctionnalités sont proposées) et Bases de connaissances, dans cet ordre, aux
 * adresses et icônes de la SOURCE UNIQUE (AdminMenu, ScheduledTask) ; roue
 * crantée et bouclier sont des liens directs ; la feuille de style (texte fin,
 * lignes serrées) et la documentation suivent.
 */
final class SidebarNavTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    public function testLeVoletOuvreSurLaNouvelleConversationPuisLesEspaces(): void
    {
        $chat  = self::lire('templates/chat/index.php');
        $pBloc = (int)strpos($chat, '<div class="sidebar-start">');
        $pInc  = (int)strpos($chat, "require ROOT . '/templates/partials/sidebar-shortcuts.php'");
        $this->assertGreaterThan(0, $pBloc, 'Le bloc de départ ouvre le volet.');
        $this->assertGreaterThan($pBloc, $pInc, 'Les tuiles d’espace (partial partagé avec le volet des pages d’espace) suivent le bloc de départ.');
        $bloc = substr($chat, $pBloc, $pInc - $pBloc);

        // Bloc de départ : le bouton (contrat JS, icône d'origine) puis la recherche, dans le même bloc.
        $this->assertStringContainsString('class="sidebar-new-btn" id="newChatBtn" data-new-chat', $bloc, 'Le bouton de nouvelle conversation garde son contrat JS.');
        $this->assertStringContainsString('<path d="M12 2.75A9.25 9.25 0 0 0 2.75 12', $bloc, "L'icône (bulle de discussion) est conservée.");

        // Tuiles : la liste vient de NavRegistry (droits compris), jamais recopiée.
        $tpl = self::lire('templates/partials/sidebar-shortcuts.php');
        $this->assertStringContainsString('$sbNavItems  = NavRegistry::workspaceFor($sbNavUser);', $tpl);
        $this->assertStringContainsString('<nav class="sidebar-spaces" aria-label="<?= te(\'Espaces\') ?>">', $tpl);
        $this->assertStringContainsString('class="space-tile space-<?= SidebarSpaces::tone($sbKey) ?><?= $sbIsOpen ? \' active\' : \'\' ?>"', $tpl);
        $this->assertStringContainsString('$sbIsOpen = $sbNavActive === $sbKey;', $tpl);
        $this->assertStringContainsString('NavRegistry::icon($sbItem[\'icon\'], 18)', $tpl);
        $this->assertStringContainsString('te($sbItem[\'short\'])', $tpl);
        $this->assertStringNotContainsString('href="/settings/schedules"', $tpl, 'Adresses lues à la source unique.');

        // Bloc de départ, tuiles, puis la liste.
        $this->assertTrue($pBloc < $pInc && $pInc < (int)strpos($chat, 'id="convList"'), 'Bloc de départ, tuiles, puis historique.');
    }

    /** Chaque tuile mène à une page de l'espace de travail, dans l'ordre et selon les droits. */
    public function testLesTuilesSuiventLesDroits(): void
    {
        $this->assertSame(['profiles', 'kb', 'schedules', 'workflows', 'creations'], array_keys(NavRegistry::WORKSPACE));
        $this->assertSame('/admin/profiles', NavRegistry::WORKSPACE['profiles']['href']);
        $this->assertSame('/admin/knowledge-bases', NavRegistry::WORKSPACE['kb']['href']);
        $this->assertSame('/settings/schedules', NavRegistry::WORKSPACE['schedules']['href']);
        $this->assertSame('/settings/creations', NavRegistry::WORKSPACE['creations']['href']);
        // Un simple utilisateur : ses tâches et ses créations (avant : dans « Paramètres »).
        $this->assertNotContains('assistants.view', Permissions::DEFAULTS['user']);
        $user = ['id' => 3, 'role' => 'user', 'is_active' => 1];
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $avant  = [$cache->getValue(), $loaded->getValue()];
        $cache->setValue(null, []);
        $loaded->setValue(null, true);
        try {
            $this->assertSame(['schedules', 'creations'], array_keys(NavRegistry::workspaceFor($user)));
            $this->assertSame([], NavRegistry::workspaceFor(null));
        } finally {
            $cache->setValue(null, $avant[0]);
            $loaded->setValue(null, $avant[1]);
        }
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (NavRegistry::WORKSPACE as $w) {
            foreach (['label', 'short'] as $champ) {
                $this->assertArrayHasKey($w[$champ], $en, "Traduction absente : {$w[$champ]}");
            }
        }
        foreach (['Espaces', 'Nouvelle conversation'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
    }

    public function testRéglagesEtAdminSontDesBoutonsLibelles(): void
    {
        $actions = self::lire('templates/partials/user-account-actions.php');
        // Des liens directs : la page s'ouvre au premier clic, menus à gauche.
        $this->assertStringContainsString('<a href="/settings" class="user-action-btn" id="userSettingsBtn"', $actions);
        $this->assertStringContainsString('<a href="/admin" class="user-action-btn is-admin" id="adminMenuBtn"', $actions);
        $this->assertStringNotContainsString('data-hub-open', $actions);
        $this->assertStringContainsString('<span class="user-action-label"><?= te(\'Réglages\') ?></span>', $actions, 'Plus d’icône muette.');
        $this->assertStringContainsString('<span class="user-action-label"><?= te(\'Admin\') ?></span>', $actions);
        $this->assertStringContainsString("str_starts_with(\$uaPath, '/settings/')", $actions);
        $this->assertStringContainsString("str_starts_with(\$uaPath, '/admin/')", $actions);
        // L'avatar du header mobile mène à Mon profil.
        $avatar = self::lire('templates/partials/header-avatar-btn.php');
        $this->assertStringContainsString('<a href="/settings" class="header-avatar-btn"', $avatar);
        $this->assertStringNotContainsString('data-hub-open', $avatar);
    }

    public function testLaFeuilleDeStyleHabilleLeVolet(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach ([
            '.sidebar-start {',
            '.sidebar-new-btn {',
            '.sidebar-nav {',
            '.sidebar-nav-link {',
            '.sidebar-nav-link:hover { background: var(--sb-row-hover); }',
            '.sidebar-nav-icon {',
            '.conv-search:focus-within { border-color: var(--accent); }',
            '.user-action-btn[aria-current="page"] {',
            '.sidebar-spaces-grid {',
            '.space-tile.active {',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css, "Règle CSS absente : $regle");
        }
        // Deux moitiés d'un même bloc : arrondi en haut pour le bouton, en bas pour la
        // recherche, qui remonte d'un pixel pour partager la bordure.
        $bouton = substr($css, (int)strpos($css, '.sidebar-new-btn {'), 700);
        $this->assertStringContainsString('border-radius: 10px 10px 0 0;', $bouton);
        $this->assertStringContainsString('background: var(--sb-start-tint);', $bouton);
        $recherche = substr($css, (int)strpos($css, '.conv-search {'), 300);
        $this->assertStringContainsString('margin-top: -1px;', $recherche);
        $this->assertStringContainsString('border-radius: 0 0 10px 10px;', $recherche);
        // Des liens habillés en boutons : pas de soulignement.
        $bloc = substr($css, (int)strpos($css, '.user-action-btn {'), 500);
        $this->assertStringContainsString('text-decoration: none;', $bloc);
    }

    /** ⌘K / Ctrl+K ouvre la palette depuis n'importe où ; le bouton du volet en porte le rappel. */
    public function testLeRaccourciClavierMeneALaRecherche(): void
    {
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('<button type="button" class="conv-search conv-search-trigger" data-palette-open aria-haspopup="dialog" aria-expanded="false">', $tpl);
        $this->assertStringContainsString('<kbd class="conv-search-kbd" data-palette-kbd aria-hidden="true"></kbd>', $tpl);
        $this->assertStringContainsString("<span class=\"conv-search-label\"><?= te('Rechercher ou aller à…') ?></span>", $tpl);

        $js = self::lire('public/assets/js/command-palette.js');
        $this->assertStringContainsString("String(e.key || '').toLowerCase() !== 'k'", $js);
        $this->assertStringContainsString("var shortcut = isMacLike ? '⌘K' : 'Ctrl K';", $js);
        $this->assertStringContainsString("document.querySelectorAll('[data-palette-kbd]')", $js);
        // Le volet garde son interface de repli, exposée aux autres scripts.
        $this->assertStringContainsString("window.MiaSidebar = {", self::lire('public/assets/js/chat.js'));
        // Les états vides viennent du serveur, traduits.
        $tplPalette = self::lire('templates/partials/command-palette.php');
        $this->assertStringContainsString("'empty'       => t('Aucun résultat'),", $tplPalette);
        $this->assertStringContainsString("'unavailable' => t('Recherche indisponible'),", $tplPalette);
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (['Rechercher ou aller à…', 'Aucun résultat', 'Recherche indisponible'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
    }

    /** Un seul accent dans le volet (l'action), punchline sur deux lignes, conversation ouverte distincte du survol. */
    public function testLaFeuilleDeStyleGardeLeVoletCoherent(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $repli = substr($css, (int)strpos($css, '.sidebar-collapse-btn,' . "\n" . '.sidebar-reopen-btn {'), 420);
        $this->assertStringContainsString('color: var(--text-muted);', $repli, 'Le bouton de repli est discret.');
        $this->assertStringNotContainsString('color: var(--accent);' . "\n", $repli);
        $this->assertStringContainsString('.sidebar-reopen-btn:hover { background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); }', $css);

        $tagline = substr($css, (int)strpos($css, '.logo-tagline {'), 300);
        $this->assertStringContainsString('-webkit-line-clamp: 2;', $tagline);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $tagline);
        $this->assertStringContainsString('class="logo-tagline" title="<?= htmlspecialchars($appTagline) ?>"', self::lire('templates/chat/index.php'));
        $this->assertStringContainsString('class="logo-tagline" title="<?= htmlspecialchars($appTagline) ?>"', self::lire('templates/partials/admin-nav.php'));

        // Conversation ouverte : un gris plus soutenu que le survol, dans les deux thèmes
        // (l'accent reste réservé à « Nouvelle conversation »).
        $this->assertStringContainsString('.conv-item.active:hover { background: var(--sb-row-active); }', $css);
        $this->assertStringContainsString('[data-theme="light"] .sidebar {', $css);
        foreach (['--sb-start-tint:', '--sb-search-bg:', '--sb-row-hover:', '--sb-row-active:'] as $jeton) {
            $this->assertSame(2, substr_count($css, $jeton), "Teinte du volet à déclarer pour les deux thèmes : $jeton");
        }
        $this->assertStringNotContainsString('[data-theme="light"] .conv-item.active', $css, 'Une seule règle pour la conversation ouverte.');
        // Le bouton « Rechercher ou aller à… » : rappel de touche au pointeur fin seulement.
        $this->assertStringContainsString(".conv-search-trigger .conv-search-kbd { position: static; transform: none; }", $css);
        $this->assertStringContainsString("@media (hover: hover) and (pointer: fine) {\n    .conv-search-trigger .conv-search-kbd { display: inline-flex; }", $css);
        $this->assertStringNotContainsString('.conv-search-input', $css, 'Plus de champ de recherche dans le volet : la palette.');
        // Texte fin, lignes serrées : conversations de 28 px en Lexend 350, libellés de date en minuscules.
        $conv = substr($css, (int)strpos($css, '.conv-item {'), 500);
        $this->assertStringContainsString('min-height: 28px;', $conv);
        $this->assertStringContainsString('font-weight: 350;', $conv);
        $groupe = substr($css, (int)strpos($css, '.conv-group-label {'), 200);
        $this->assertStringNotContainsString('text-transform: uppercase', $groupe);
        // Au doigt : lignes de 36 px ; le champ de la palette est à 16 px (iOS zoome sinon).
        $this->assertStringContainsString('.conv-item { min-height: 36px; padding: 8px 10px; font-size: 14px; }', $css);
        $this->assertStringContainsString('font-size: 16px;', substr($css, (int)strpos($css, '.cmdp-input {'), 400));
        // Couleur de l'assistant en pastille ronde ; épingle masquée dans l'historique (le groupe le dit).
        $this->assertStringContainsString('border-radius: 50%;', substr($css, (int)strpos($css, "\n.conv-color-bar {"), 200));
        $this->assertStringContainsString('#convList .conv-pin-icon { display: none; }', $css);
        // Densité : logo 52 px (plus 70), pied resserré.
        $this->assertStringNotContainsString("width: 70px;\n    height: 70px;", $css, 'Le logo de la barre latérale ne fait plus 70 px.');
        $this->assertStringContainsString(".sidebar-logo {\n    width: 52px;\n    height: 52px;", $css);
        $this->assertStringContainsString(".sidebar-footer {\n    padding: 8px 10px;", $css);
    }

    /**
     * Quatrième passe (proposition A « Cadres ») : raccourcis encadrés et terminés
     * par un chevron, historique rangé en groupes — Épinglées dans leur cadre,
     * chaque période ouverte par un libellé prolongé d'un filet.
     */
    public function testLesEnsemblesDuVoletSontDistincts(): void
    {
        // Gabarit : le plafond d'épinglage lu à la source unique.
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('id="convList" data-max-pinned="<?= Conversation::MAX_PINNED ?>"', $tpl);
        // Le panneau encadré habille encore « Retour au chat » des pages d'administration.
        $this->assertStringContainsString('class="sidebar-nav admin-nav-back-panel"', self::lire('templates/partials/admin-nav.php'));

        // Feuille de style : panneau encadré, tuile, filet, chevron ; Épinglées encadrées ; filet des périodes.
        $css = self::lire('public/assets/css/main.css');
        $panneau = substr($css, (int)strpos($css, "\n.sidebar-nav {"), 320);
        $this->assertStringContainsString('border: 1px solid var(--sb-search-border);', $panneau);
        $this->assertStringContainsString('background: var(--sb-search-bg);', $panneau);
        $this->assertStringContainsString('.sidebar-nav-link + .sidebar-nav-link::before {', $css);
        $this->assertStringContainsString('background: var(--sb-tile);', substr($css, (int)strpos($css, "\n.sidebar-nav-icon {"), 300));
        $this->assertStringContainsString('.sidebar-nav-link:hover .sidebar-nav-chevron { transform: translateX(2px);', $css);
        $epinglees = substr($css, (int)strpos($css, "\n.conv-group--pinned {"), 260);
        $this->assertStringContainsString('border: 1px solid var(--sb-search-border);', $epinglees);
        $this->assertStringContainsString(".conv-group-label::after {\n    content: '';", $css);
        $this->assertStringContainsString('.conv-group--pinned .conv-group-label::after { display: none; }', $css);
        foreach (['--sb-tile:', '--sb-group-label:'] as $jeton) {
            $this->assertSame(2, substr_count($css, $jeton), "Teinte du volet à déclarer pour les deux thèmes : $jeton");
        }
        $this->assertSame(3, substr_count($css, '--sb-rule:'), 'Filet : deux thèmes, plus le contraste renforcé.');
        $this->assertStringContainsString("html[data-contrast=\"high\"] .sidebar {\n    --sb-rule:          var(--border);", $css);

        // Groupes rejouables : rangement à la SOURCE UNIQUE (sidebar-sections.js),
        // chat.js y délègue ; épinglage qui met à jour toutes les copies.
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("function regroupConvList(list) {\n    if (list && window.MiaSidebarSections) window.MiaSidebarSections.regroup(list);\n}", $js);
        $sections = self::lire('public/assets/js/sidebar-sections.js');
        $regroupe = substr($sections, (int)strpos($sections, 'function regroup(list) {'), 6000);
        $this->assertStringContainsString("group.className = 'conv-group' + (key === 'pinned' ? ' conv-group--pinned' : '');", $regroupe);
        $this->assertStringContainsString("list.querySelectorAll('.conv-group, .conv-group-label, .conv-history-head')", $regroupe);
        $this->assertStringContainsString('Number(list.dataset.maxPinned)', $regroupe);
        $this->assertStringContainsString("c.className = 'conv-group-count';", $regroupe);
        $epingler = substr($js, (int)strpos($js, 'async function togglePinConv('), 2200);
        $this->assertStringNotContainsString('item.parentElement', $epingler, 'L’item vit dans son groupe, pas dans la liste.');
        $this->assertStringContainsString("regroupConvList(document.getElementById('convList'));", $epingler);

        // Libellés de période traduits (ils restaient en français), mois dans la langue de la page.
        $this->assertStringContainsString("label: window.t('Aujourd’hui')", $sections);
        $this->assertStringContainsString("label: window.t('Hier')", $sections);
        $this->assertStringContainsString('toLocaleDateString(html.lang', $sections);
        $client = (array)(require dirname(__DIR__, 2) . '/lang/en.client.php');
        foreach (['Aujourd’hui', 'Hier', 'Épinglées', '7 derniers jours', '30 derniers jours'] as $cle) {
            $this->assertArrayHasKey($cle, $client, "Traduction client absente : $cle");
        }
    }

    /** « Retour au chat » (administration, paramètres) : l'habit des raccourcis, hors de la liste qui défile. */
    public function testLeRetourAuChatPrendLHabitDesRaccourcis(): void
    {
        $nav    = self::lire('templates/partials/admin-nav.php');
        $pBack  = (int)strpos($nav, '<div class="sidebar-nav admin-nav-back-panel">');
        $pScroll = (int)strpos($nav, '<nav class="admin-nav-scroll">');
        $this->assertGreaterThan(0, $pBack, 'Le retour au chat est un panneau de raccourci.');
        $this->assertTrue($pBack < $pScroll, 'Il est posé hors de la liste qui défile, au-dessus.');
        $panneau = substr($nav, $pBack, $pScroll - $pBack);
        $this->assertStringContainsString('<a href="/" class="sidebar-nav-link admin-nav-back">', $panneau);
        $this->assertStringContainsString('<span class="sidebar-nav-icon" aria-hidden="true"><svg', $panneau);
        $this->assertStringContainsString('<polyline points="12 19 5 12 12 5"/>', $panneau, 'La flèche vers la gauche est conservée.');
        $this->assertStringContainsString("<span class=\"sidebar-nav-label\"><?= te('Retour au chat') ?></span>", $panneau);
        $this->assertStringNotContainsString('sidebar-nav-chevron', $panneau, 'Pas de chevron : on revient.');
        $this->assertStringContainsString("<?php if (\$navVariant !== 'none'): ?>", substr($nav, $pBack - 120, 120), 'Pas sur « À propos ».');

        // Les jetons du volet valent aussi pour le menu latéral de l'administration.
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString(".admin-sidebar,\n.sidebar {", $css);
        $this->assertStringContainsString("[data-theme=\"light\"] .admin-sidebar,\n[data-theme=\"light\"] .sidebar {", $css);
        $this->assertStringContainsString("html[data-contrast=\"high\"] .admin-sidebar,\nhtml[data-contrast=\"high\"] .sidebar {", $css);
        $this->assertStringContainsString(".admin-nav-back-panel {\n    margin: 0 0 8px;", $css);
    }

    /**
     * Retour utilisateur (2026-11-04) : une conversation ordinaire n'avait rien à
     * gauche de son titre — elle se perdait parmi les conversations de workflow
     * (icône) et de tâche (horloge), et son titre ne s'alignait pas. Chaque ligne
     * porte désormais UN marqueur dans une case de 13 px, l'anneau neutre en
     * dernier recours, dans les trois rendus de l'historique.
     */
    public function testChaqueConversationPorteUnMarqueurAligne(): void
    {
        foreach (['templates/chat/index.php', 'templates/partials/chat-shell-sidebar.php'] as $gabarit) {
            $src = self::lire($gabarit);
            $horloge  = strpos($src, '<?php if ($isScheduled): ?>');
            $workflow = strpos($src, '<?php elseif ($isWorkflow): ?>');
            $couleur  = strpos($src, "<?php elseif (!empty(\$conv['profile_color'])): ?>");
            $anneau   = strpos($src, '<span class="conv-plain-dot" aria-hidden="true"></span>');
            $titre    = strpos($src, '<span class="conv-title">');
            foreach (['horloge' => $horloge, 'workflow' => $workflow, 'couleur' => $couleur, 'anneau' => $anneau] as $nom => $pos) {
                $this->assertIsInt($pos, "$gabarit : $nom absent");
            }
            // Une seule chaîne : horloge, puis workflow, puis couleur, puis anneau, avant le titre.
            $this->assertTrue($horloge < $workflow && $workflow < $couleur && $couleur < $anneau && $anneau < $titre, $gabarit);
            $this->assertSame(1, substr_count($src, '<span class="conv-color-bar"></span>'), "$gabarit : une seule pastille d'assistant, en alternative");
        }
        // Création d'une conversation sans rechargement : même règle.
        $js = self::lire('public/assets/js/chat.js');
        $ajout = substr($js, (int)strpos($js, 'function addConvToSidebar('), 1600);
        $this->assertStringContainsString('<span class="conv-plain-dot" aria-hidden="true"></span>', $ajout);
        $this->assertStringContainsString("marker = '<span class=\"conv-color-bar\"></span>';", $ajout);
        $this->assertStringContainsString('/^#[0-9a-fA-F]{6}$/.test(color)', $ajout, 'La couleur part dans un style : validée.');
        $this->assertStringContainsString("a.innerHTML = marker + `<span class=\"conv-title\">", $ajout);
        // L'épingle se pose après le marqueur, quel qu'il soit.
        $this->assertStringContainsString(".conv-scheduled-icon, .conv-workflow-icon, .conv-color-bar, .conv-plain-dot", $js);
        // Case de 13 px pour tous : icônes de 13 px, pastille de 6 px et anneau de 8 px centrés.
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.conv-workflow-icon { display: inline-flex; color: var(--text-muted); flex-shrink: 0; width: 13px; justify-content: center; }', $css);
        $pastille = substr($css, (int)strpos($css, "\n.conv-color-bar {"), 260);
        $this->assertStringContainsString('width: 6px;', $pastille);
        $this->assertStringContainsString('margin: 0 3.5px;', $pastille);
        $anneau = substr($css, (int)strpos($css, "\n.conv-plain-dot {"), 320);
        $this->assertStringContainsString('width: 8px;', $anneau);
        $this->assertStringContainsString('margin: 0 2.5px;', $anneau);
        $this->assertStringContainsString('box-sizing: border-box;', $anneau);
        $this->assertStringContainsString('border: 1.5px solid currentColor;', $anneau);
        $this->assertStringContainsString('html[data-contrast="high"] .conv-plain-dot { opacity: .9; }', $css);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationSuit(): void
    {
        $this->assertStringContainsString('id: volet-gauche-decoupe', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('dans le cadre **Épinglées**', self::lire('docs/user/fonctionnalites/conversations/gerer-ses-conversations.md'));
        $this->assertStringContainsString('Quatrième passe', self::lire('CLAUDE.md'));
        $nouveautes = self::lire('docs/NOUVEAUTES.md');
        $this->assertStringContainsString('id: volet-gauche-espaces-createur', $nouveautes);
        $this->assertStringContainsString('id: volet-gauche-allege', $nouveautes);
        $this->assertStringContainsString('Tâches programmées', self::lire('docs/user/premiers-pas.md'));
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('mène directement', $guide);
        $this->assertStringContainsString('juste dessous dans le même bloc, le bouton « **Rechercher ou aller à…** »', $guide, 'La recherche est attachée à « Nouvelle conversation ».');
        $this->assertStringContainsString('barre latérale', self::lire('docs/GUIDE-CREATEUR.md'));
        $this->assertStringContainsString('Volet de gauche', self::lire('CLAUDE.md'));
        foreach (['docs/user/fonctionnalites/conversations/gerer-ses-conversations.md', 'docs/GUIDE-UTILISATEUR.md', 'docs/user/premiers-pas.md'] as $doc) {
            $this->assertStringContainsString('Ctrl+K', self::lire($doc), "$doc : le raccourci n'est pas documenté.");
        }
    }
}
