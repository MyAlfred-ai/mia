<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pastilles jusqu'à l'élément concerné, texte en info-bulle (2026-11-14).
 *
 * Deux demandes d'Axel :
 *   1. le texte qui accompagne une pastille (point à régler, nouveauté,
 *      connexion à reconnecter) s'affiche en info-bulle, jamais en texte
 *      toujours visible — au doigt aussi ;
 *   2. la pastille ne s'arrête plus au menu : sur la page, la carte, la ligne
 *      ou la section de l'élément concerné porte la sienne, avec ce qui ne va
 *      pas pour LUI seul.
 *
 * Chaque constat d'AdminAttention nomme donc ses éléments (`targets`) ; la
 * page pose leur pastille (AdminAttention::itemDot) ; nav-signals.js montre
 * la bulle de toute pastille — survol, clavier, toucher.
 */
final class AttentionDotsTest extends TestCase
{
    private static function lire(string $relatif): string
    {
        $abs = ROOT . '/' . ltrim($relatif, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $relatif");
        return str_replace("\r\n", "\n", (string)file_get_contents($abs));
    }

    /** @return array<string, array<string,mixed>> */
    private static function parId(array $signals): array
    {
        $out = [];
        foreach ($signals as $s) {
            $out[$s['id']] = $s;
        }
        return $out;
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
        ToolCatalog::setToday(null);
        AdminAttention::forget();
    }

    // ================================================================ Éléments nommés par les constats

    public function testChaqueOutilPorteSonPropreConstat(): void
    {
        ToolCatalog::setToday('2026-09-30');
        $outil = static fn(int $id, string $nom, array $champs = []): array => $champs + [
            'id' => $id, 'name' => $nom, 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.6-flare',
            'config_json' => '{}', 'api_key_enc' => null, 'is_active' => 1, 'last_test_ok' => null,
        ];
        $s = self::parId(AdminAttention::toolSignals([
            $outil(1, 'Sans clé', ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-image']),
            $outil(3, 'Test KO', ['last_test_ok' => 0]),
            $outil(4, 'Seul'),
        ], [3 => 1], ['openai' => true], true, true, false));

        $this->assertSame(['tool:1'], array_keys($s['outils_inutilisables']['targets']));
        $this->assertStringContainsString('il manque la clé API Gemini', $s['outils_inutilisables']['targets']['tool:1'][0]);
        $this->assertStringNotContainsString('Sans clé', $s['outils_inutilisables']['targets']['tool:1'][0], 'La carte sait de quel outil il s\'agit : pas de nom répété');
        $this->assertSame(['tool:3'], array_keys($s['outils_test_echec']['targets']));
        $this->assertSame(['tool:4'], array_keys($s['outils_sans_groupe']['targets']));
        // La voix sans clé OpenAI : les deux cartes intégrées concernées.
        $this->assertSame(['builtin:mic', 'builtin:voice'], array_keys($s['voix_sans_openai']['targets']));
    }

    public function testLesModelesFournisseursEtDefautsSontNommes(): void
    {
        $modeles = [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5']];
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];
        $s = self::parId(AdminAttention::llmSignals(['openai', 'gemini'], $modeles, $dispo, 'anthropic::claude-opus-5', 'anthropic::claude-opus-5', 'anthropic::claude-opus-5'));

        $this->assertArrayHasKey('provider:gemini', $s['llm_fournisseur_sans_modele']['targets'], 'Clé sans modèle : la carte du fournisseur');
        $cibles = $s['llm_modeles_sans_cle']['targets'];
        $this->assertArrayHasKey('model:anthropic::claude-opus-5', $cibles, 'La ligne du modèle');
        $this->assertArrayHasKey('provider:anthropic', $cibles, 'Et la carte de son fournisseur');
        $this->assertSame(['llm-default'], array_keys($s['llm_defaut_sans_cle']['targets']));
        $this->assertSame(['llm-fallback'], array_keys($s['llm_secours']['targets']));

        $incident = self::parId(AdminAttention::providerIncidentSignals(['openai' => ['kind' => 'quota', 'first' => 1000, 'last' => 1100, 'count' => 3]], 1200));
        $this->assertSame(['provider:openai'], array_keys($incident['incident_openai']['targets']));
    }

    public function testAssistantsGroupesComptesConnecteursBasesConnexionWorker(): void
    {
        $profils = [
            ['id' => 7, 'name' => 'Juriste', 'is_personal' => 0, 'is_global' => 0, 'default_llm' => null, 'groups_n' => 0],
            ['id' => 8, 'name' => 'Ancien', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => 'openai::gpt-4o', 'groups_n' => 0],
        ];
        $s = self::parId(AdminAttention::assistantSignals($profils, ['openai::gpt-5.6' => 'GPT-5.6'], ['openai']));
        $this->assertSame(['profile:7'], array_keys($s['assistant_sans_groupe']['targets']));
        $this->assertSame(['profile:8'], array_keys($s['assistant_modele_retire']['targets']));

        $g = self::parId(AdminAttention::groupSignals(['Ventes' => ['anthropic::x']], ['openai::gpt-5.6' => 'GPT'], ['openai'], ['Ventes' => 12]));
        $this->assertSame(['group:12'], array_keys($g['groupe_sans_modele']['targets']));
        // Sans identifiant (forme d'avant), le constat reste, sans élément.
        $g = self::parId(AdminAttention::groupSignals(['Ventes' => ['anthropic::x']], ['openai::gpt-5.6' => 'GPT'], ['openai']));
        $this->assertSame([], $g['groupe_sans_modele']['targets']);

        $c = self::parId(AdminAttention::pendingAccountsSignals([['id' => 41, 'name' => 'Jeanne'], 'Paul']));
        $this->assertSame(['user:41'], array_keys($c['comptes_en_attente']['targets']));
        $this->assertStringContainsString('Jeanne, Paul', $c['comptes_en_attente']['text'], 'Les noms restent dans la bulle du menu');

        $d = self::parId(AdminAttention::demoSignals([['id' => 5, 'name' => 'Démo', 'email' => 'd@x.fr', 'is_active' => true, 'exhausted' => true, 'muted' => false]]));
        $this->assertSame(['user:5'], array_keys($d['demos_epuisees']['targets']));

        $k = self::parId(AdminAttention::connectorSignals([
            ['key' => 'gmail', 'label' => 'Gmail', 'family' => 'google', 'enabled' => true, 'configured' => false],
        ]));
        $this->assertSame(['connector:gmail'], array_keys($k['connecteurs_indisponibles']['targets']));
        $this->assertStringContainsString('identifiants Google absents', $k['connecteurs_indisponibles']['targets']['connector:gmail'][0]);

        $b = self::parId(AdminAttention::kbSignals([
            ['id' => 2, 'name' => 'Cerveau', 'backend' => 'mybrain', 'remote_status' => 'refused'],
            ['id' => 3, 'name' => 'Contrats', 'backend' => 'local', 'sync_provider' => 'sharepoint', 'sync_folder_ref' => 'https://x', 'sync_enabled' => 1, 'sync_status' => 'ok'],
            ['id' => 4, 'name' => 'Perso', 'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => 'abc', 'sync_enabled' => 1, 'sync_status' => 'ok', 'sync_owner_user_id' => 9],
            ['id' => 5, 'name' => 'Procédures', 'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => 'def', 'sync_enabled' => 1, 'sync_status' => 'error'],
        ], ['drive' => true, 'sharepoint' => false], false, true));
        $this->assertSame(['kb:2'], array_keys($b['mybrain_refuse']['targets']));
        $this->assertSame(['kb:5'], array_keys($b['bases_sync_echec']['targets']));
        $sans = $b['bases_source_sans_identifiants']['targets'];
        $this->assertArrayHasKey('kb:3', $sans);
        $this->assertArrayHasKey('sync:sharepoint', $sans, 'La carte de la source, sur les sources synchronisées');
        $this->assertArrayHasKey('kb:4', $sans);
        $this->assertArrayNotHasKey('sync:drive', $sans, 'Un Drive lu par son propriétaire dépend de l\'OAuth Google, pas de la carte Drive');

        $o = self::parId(AdminAttention::ssoSignals(['Google' => [true, true], 'Microsoft' => [true, false]]));
        $this->assertSame(['sso:microsoft'], array_keys($o['sso_sans_identifiants']['targets']));

        $w = AdminAttention::workerSignal(['at' => 1000, 'version' => '1'], '1', 5000);
        $this->assertSame(['diag:worker'], array_keys($w['targets']));
    }

    public function testLesArretsDeModelesVontALaLigneEtALaCarte(): void
    {
        $s = self::parId(AdminAttention::llmRetirementSignals(
            [['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3']],
            [['id' => 9, 'name' => 'Expert', 'default_llm' => 'openai::o3']]
        ));
        $famille = $s['llm_arret_annonce'] ?? $s['llm_modeles_arretes'];
        $this->assertArrayHasKey('model:openai::o3', $famille['targets']);
        $this->assertStringStartsWith('« o3 » : ', $famille['targets']['provider:openai'][0], 'Sur la carte du fournisseur, chaque ligne nomme son modèle');
        if (isset($s['assistant_modele_arret_annonce'])) {
            $this->assertSame(['profile:9'], array_keys($s['assistant_modele_arret_annonce']['targets']));
        }
    }

    // ================================================================ Fusion, pastille, lien

    public function testLesConstatsSontFusionnesParElement(): void
    {
        $signals = [
            ['id' => 'a', 'entry' => 'tools', 'level' => AdminAttention::WARN, 'short' => '', 'text' => 'A', 'dedicated' => false, 'targets' => ['tool:3' => ['Test en échec.']]],
            ['id' => 'b', 'entry' => 'tools', 'level' => AdminAttention::ALERT, 'short' => '', 'text' => 'B', 'dedicated' => false, 'targets' => ['tool:3' => ['Inutilisable.'], 'tool:4' => ['Sans groupe.']]],
            ['id' => 'c', 'entry' => 'llms-keys', 'level' => AdminAttention::ALERT, 'short' => '', 'text' => 'C', 'dedicated' => true, 'targets' => ['provider:x' => ['Dédié.']]],
            ['id' => 'd', 'entry' => 'users', 'level' => AdminAttention::WARN, 'short' => '', 'text' => 'D', 'dedicated' => false],
        ];
        $items = AdminAttention::itemsFor($signals);
        $this->assertSame(['tool:3', 'tool:4'], array_keys($items), 'Un constat dédié a sa propre surface ; sans élément, rien');
        $this->assertSame(AdminAttention::ALERT, $items['tool:3']['level'], 'Le pire niveau l\'emporte');
        $this->assertSame("• Test en échec.\n• Inutilisable.", $items['tool:3']['text']);
        $this->assertSame(['level' => AdminAttention::ALERT, 'text' => 'Sans groupe.'], $items['tool:4']);
    }

    public function testLaPastilleDUnElementPorteSonTexteEnBulle(): void
    {
        $items = ['tool:3' => ['level' => AdminAttention::ALERT, 'text' => 'Inutilisable : « x ».']];
        $this->assertSame('', AdminAttention::itemDot('tool:9', true, $items), 'Rien à signaler : rien');
        $dot = AdminAttention::itemDot('tool:3', true, $items);
        $this->assertStringContainsString('class="nav-signal-dot att-dot is-alert"', $dot);
        $this->assertStringContainsString('id="att-tool-3"', $dot);
        $this->assertStringContainsString('role="img"', $dot);
        $this->assertStringContainsString('aria-label="Inutilisable : « x »."', $dot);
        $this->assertStringContainsString('data-tip="Inutilisable : « x »."', $dot);
        $this->assertStringContainsString('data-tip-tone="alert"', $dot);
        $this->assertStringContainsString('tabindex="0"', $dot);
        // L'ancre n'existe qu'une fois dans la page (carte + panneau d'un fournisseur).
        $second = AdminAttention::itemDot('tool:3', false, $items);
        $this->assertStringNotContainsString('id="', $second);
        $this->assertStringNotContainsString('tabindex', $second, 'Dans un lien ou un bouton : pas de focus imbriqué');
        $this->assertSame('att-model-openai-gpt-5-6', AdminAttention::anchorId('model:openai::gpt-5.6'));
    }

    public function testReglerMeneJusquALElement(): void
    {
        $sig = static fn(string $entry, array $targets): array => ['entry' => $entry, 'targets' => $targets];
        $this->assertSame('/admin/tools#att-tool-3', AdminAttention::linkFor($sig('tools', ['tool:3' => ['x']])));
        $this->assertSame('/admin/profiles#assistant-7', AdminAttention::linkFor($sig('profiles', ['profile:7' => ['x']])));
        $this->assertSame('/admin/knowledge-bases#base-5', AdminAttention::linkFor($sig('knowledge-bases', ['kb:5' => ['x']])));
        $this->assertSame('/admin/knowledge-bases/sync#att-kb-5', AdminAttention::linkFor($sig('knowledge-bases-sync', ['kb:5' => ['x']])));
        $this->assertSame('/admin/sso#sso-microsoft', AdminAttention::linkFor($sig('sso', ['sso:microsoft' => ['x']])));
        $this->assertSame('/admin/llms?fournisseur=openai#att-model-openai-o3', AdminAttention::linkFor($sig('llms-keys', ['model:openai::o3' => ['x']])));
        $this->assertSame('/admin/llms?fournisseur=gemini#att-provider-gemini', AdminAttention::linkFor($sig('llms-keys', ['provider:gemini' => ['x']])));
        $this->assertSame('/admin/users', AdminAttention::linkFor($sig('users', [])), 'Sans élément : la page');

        $tpl = self::lire('templates/admin/index.php');
        $this->assertStringContainsString('AdminAttention::linkFor($sig)', $tpl);
    }

    // ================================================================ Câblage des pages

    public function testChaquePagePoseLaPastilleDeSesElements(): void
    {
        $pages = [
            'templates/admin/llms/index.php'          => ["itemDot('provider:' . \$p)", "itemDot('model:' . \$slug)", "itemDot('llm-default', false)", "itemDot('llm-fallback', false)"],
            'templates/admin/profiles/list.php'       => ["itemDot('profile:' . \$pid, false)"],
            'templates/admin/user-groups.php'         => ["itemDot('group:' . \$gid, false)"],
            'templates/admin/users.php'               => ["itemDot('user:' . \$uid)"],
            'templates/admin/tools.php'               => ["itemDot('tool:' . (int)\$tool['id'])", "itemDot('builtin:mic')", "itemDot('builtin:voice')"],
            'templates/admin/connectors.php'          => ["itemDot('connector:' . \$key)"],
            'templates/admin/knowledge-bases.php'     => ["itemDot('kb:' . \$r['id'], false)"],
            'templates/admin/knowledge-bases-sync.php' => ["itemDot('sync:drive')", "itemDot('sync:sharepoint')", "itemDot('sync:onedrive')", "itemDot('sync:gcalendar')"],
            'templates/admin/sso.php'                 => ["itemDot('sso:google')", "itemDot('sso:microsoft')"],
            'templates/admin/diagnostics.php'         => ["itemDot('diag:'"],
        ];
        foreach ($pages as $page => $appels) {
            $src = self::lire($page);
            foreach ($appels as $appel) {
                $this->assertStringContainsString('AdminAttention::' . $appel, $src, "$page : pastille absente ($appel)");
            }
        }
        // Les clés de source sont celles de KbSync, les sections celles de SsoRegistry.
        foreach (KbSync::PROVIDERS as $source) {
            $this->assertStringContainsString("itemDot('sync:$source')", self::lire('templates/admin/knowledge-bases-sync.php'));
        }
        $this->assertSame('google', SsoRegistry::SECTION_GOOGLE);
        $this->assertSame('microsoft', SsoRegistry::SECTION_MICROSOFT);
    }

    public function testLaNouveauteSePoseSurLaCarteQuElleDesigne(): void
    {
        $html = MajorFeatures::dotHtml(['workflows']);
        $this->assertStringContainsString('data-tip="Nouveau : Les workflows"', $html);
        $this->assertStringContainsString('data-tip-tone="news"', $html);
        $this->assertStringNotContainsString('title=', $html, 'Une seule bulle, pas de title natif en plus');
        $this->assertSame('', MajorFeatures::anchorDot('office-addin'), 'Sans arrivée par sa pastille : rien');
        $this->assertStringContainsString("MajorFeatures::anchorDot('office-addin')", self::lire('templates/settings/partials/office-addin-card.php'));
        foreach (MajorFeatures::CATALOG as $id => $f) {
            if (!empty($f['ancre'])) {
                $this->assertStringContainsString("anchorDot('" . $f['ancre'] . "')", self::lire('templates/settings/partials/office-addin-card.php') . self::lire('templates/settings/connections.php'), "Ancre sans pastille : $id");
            }
        }
    }

    // ================================================================ Bulle, jamais de texte permanent

    public function testLeScriptDesBullesSertToutesLesPastilles(): void
    {
        $js = self::lire('public/assets/js/nav-signals.js');
        $this->assertStringContainsString("'[data-tip], .nav-signal-dot, .nav-attention-dot, .nav-budget-dot, .news-dot, .reconnect-dot, .info-dot'", $js);
        $this->assertStringContainsString("getAttribute('data-tip')", $js);
        $this->assertStringContainsString("getAttribute('data-tip-tone')", $js);
        // Doigt : toucher la pastille lit sa bulle, sans suivre le lien qui la porte.
        $this->assertStringContainsString("dernierPointeur !== 'touch' && dernierPointeur !== 'pen'", $js);
        $this->assertMatchesRegularExpression('/e\.preventDefault\(\);\s*e\.stopPropagation\(\);/', $js);
        // Arrivée par « Régler → ».
        $this->assertStringContainsString('/^#att-[a-z0-9-]+$/', $js);
        $this->assertStringContainsString("addEventListener('hashchange', surAncre)", $js);
        // Aucun texte dans le script : tout vient de la page, déjà traduit.
        $this->assertDoesNotMatchRegularExpression("/textContent = '[^']/", $js);

        // Chargé partout où vivent des pastilles : toutes les variantes de
        // l'ossature (plus seulement l'administration) et la page de discussion.
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringNotContainsString("<?php if (\$navVariant === 'admin'): // Bulles des pastilles", $nav);
        $this->assertStringContainsString('<script src="/assets/js/nav-signals.js', $nav);
        $this->assertStringContainsString('<script src="/assets/js/nav-signals.js', self::lire('templates/chat/index.php'));

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.nav-signal-hint { display: none; }', $css);
        $this->assertStringContainsString('.nav-hint-bubble.is-news', $css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{[^@]*\.reconnect-dot::after,\s*\.info-dot::after \{\s*content: \'\';\s*position: absolute;\s*inset: -12px;/s', $css, 'Au doigt, une zone de 32 px autour du point');
        $this->assertStringContainsString('.att-dot {', $css);
        $this->assertStringContainsString('.att-dot.is-spotlit', $css);
    }

    public function testLesTextesDesPastillesNeRestentPasAffiches(): void
    {
        // Tuiles d'espace : la pastille porte le texte, la tuile son libellé.
        $tuiles = self::lire('templates/partials/sidebar-shortcuts.php');
        $this->assertStringContainsString('data-tip="<?= htmlspecialchars($sbSig[\'text\']) ?>"', $tuiles);
        $this->assertStringNotContainsString("\$sbSig !== null ? \$sbSig['text']", $tuiles);
        // Bouton « Admin » et mémoire : le nombre porte sa bulle.
        $this->assertStringContainsString('data-tip="<?= htmlspecialchars(I18n::plural($uaBadge[\'count\']', self::lire('templates/partials/user-account-actions.php'));
        $this->assertStringContainsString('mem-nav-count" data-tip=', self::lire('templates/partials/admin-nav.php'));
        // Menu « + » : le repère des connexions à reconnecter aussi.
        $this->assertStringContainsString('reconnect-dot plus-menu-fold-alert" data-tip=', self::lire('templates/chat/index.php'));
    }

    public function testLesBullesSontTraduites(): void
    {
        I18n::use('en');
        ToolCatalog::setToday('2026-09-30');
        $signaux = array_merge(
            AdminAttention::toolSignals([[
                'id' => 1, 'name' => 'X', 'type' => 'image', 'provider' => 'gemini', 'model' => 'gemini-3.1-flash-image',
                'config_json' => '{}', 'api_key_enc' => null, 'is_active' => 1, 'last_test_ok' => null,
            ], [
                'id' => 2, 'name' => 'Y', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.6-flare',
                'config_json' => '{}', 'api_key_enc' => null, 'is_active' => 1, 'last_test_ok' => 0,
            ], [
                'id' => 3, 'name' => 'Z', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.6-flare',
                'config_json' => '{}', 'api_key_enc' => null, 'is_active' => 1, 'last_test_ok' => null,
            ]], [2 => 1], ['openai' => true], false, false, true),
            AdminAttention::assistantSignals([
                ['id' => 7, 'name' => 'J', 'is_personal' => 0, 'is_global' => 0, 'default_llm' => 'anthropic::claude-opus-5', 'groups_n' => 0],
                ['id' => 8, 'name' => 'K', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => 'openai::gpt-4o', 'groups_n' => 0],
            ], ['anthropic::claude-opus-5' => 'Opus'], ['openai']),
            AdminAttention::groupSignals(['G' => ['a::b']], ['openai::gpt-5.6' => 'GPT'], ['openai'], ['G' => 1]),
            AdminAttention::pendingAccountsSignals([['id' => 1, 'name' => 'A']]),
            AdminAttention::demoSignals([['id' => 5, 'name' => 'D', 'email' => '', 'is_active' => true, 'exhausted' => true, 'muted' => false]]),
            AdminAttention::connectorSignals([['key' => 'gmail', 'label' => 'Gmail', 'family' => 'google', 'enabled' => true, 'configured' => false]]),
            AdminAttention::kbSignals([
                ['id' => 2, 'name' => 'C', 'backend' => 'mybrain', 'remote_status' => 'refused'],
                ['id' => 3, 'name' => 'S', 'backend' => 'local', 'sync_provider' => 'sharepoint', 'sync_folder_ref' => 'https://x', 'sync_enabled' => 1, 'sync_status' => 'ok'],
                ['id' => 4, 'name' => 'E', 'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => 'd', 'sync_enabled' => 1, 'sync_status' => 'error'],
            ], ['drive' => true, 'sharepoint' => false], false, true),
            AdminAttention::ssoSignals(['Microsoft' => [true, false]]),
            AdminAttention::llmSignals(['openai', 'gemini'], [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Opus']], [], '', '', null)
        );
        $vus = 0;
        foreach (AdminAttention::itemsFor($signaux) as $cible => $etat) {
            $vus++;
            foreach (['Clé ', 'Modèle ', 'Son modèle', 'Partagé', 'Aucun de ses', 'En attente', 'Démo ', 'Inutilisable', 'Dernier test', 'Autorisé dans', 'Activé', 'Synchronisation', 'Activée', 'proposé sans clé'] as $francais) {
                $this->assertStringNotContainsString($francais, $etat['text'], "Bulle de $cible non traduite : {$etat['text']}");
            }
        }
        $this->assertGreaterThan(12, $vus);
        $this->assertSame('Connection expired or revoked: reconnect it, or remove it.', html_entity_decode(
            (string)preg_replace('/.*data-tip="([^"]*)".*/', '$1', ConnectorRegistry::reconnectDotFor('gmail', ['gmail'])),
            ENT_QUOTES
        ));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('itemDot', self::lire('CLAUDE.md'));
        $this->assertStringContainsString('info-bulle', mb_strtolower(self::lire('docs/GUIDE-ADMIN.md')));
        $this->assertStringContainsString('pastilles-jusqu-a-l-element', self::lire('docs/NOUVEAUTES.md'));
    }
}
