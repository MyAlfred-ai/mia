<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sections repliées du volet gardées sur le COMPTE (SidebarPreferences,
 * migration 109) — cf. CLAUDE.md, puce « Volet REPLIABLE ».
 *
 * Garde : tout est déplié par défaut ; seuls les choix de la personne sont
 * gardés ; l'état est
 * posé sur <html> avant le premier rendu d'après le compte (plus le
 * localStorage) ; chaque geste l'envoie à POST /settings/sidebar ; l'ancien
 * état du navigateur est repris une fois. Le module et le script de
 * démarrage sont EXÉCUTÉS sous node : une lecture du code ne dit pas dans
 * quel état le volet s'ouvre.
 */
final class SidebarPreferencesTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user'], $_SESSION['csrf']);
    }

    // ------------------------------------------------------------------
    // Formes pures
    // ------------------------------------------------------------------

    public function testToutEstDeplieParDefaut(): void
    {
        $vide = SidebarPreferences::normalize(null);
        foreach (SidebarPreferences::SECTIONS as $section) {
            $this->assertTrue(SidebarPreferences::isOpen($vide, $section), "Section $section dépliée par défaut.");
        }
        $this->assertSame(SidebarPreferences::SECTIONS, array_keys(SidebarPreferences::DEFAULTS), 'Un défaut par section.');
    }

    public function testLeChoixDeLaPersonneLEmporteSurLeDefaut(): void
    {
        $etat = SidebarPreferences::normalize(['spaces' => false, 'history' => false]);
        $this->assertFalse(SidebarPreferences::isOpen($etat, 'spaces'));
        $this->assertFalse(SidebarPreferences::isOpen($etat, 'history'));
        $this->assertTrue(SidebarPreferences::isOpen($etat, 'pinned'), 'Section non touchée : le défaut.');
    }

    public function testSeulsLesChoixValidesSontGardes(): void
    {
        $etat = SidebarPreferences::normalize(json_encode([
            'spaces'  => false,
            'pinned'  => 'non',           // pas un booléen
            'inconnu' => true,            // section inconnue
            'groups'  => [
                'today'     => false,
                '7d'        => true,
                'm2026-11'  => true,
                'm2026-12'  => true,      // mois 12 : hors 0-11
                'x<script>' => true,
                'yesterday' => 'oui',
            ],
        ]));
        $this->assertSame(['spaces' => false, 'groups' => ['today' => false, '7d' => true, 'm2026-11' => true]], $etat);
        $this->assertSame(['groups' => []], SidebarPreferences::normalize('{pas du json'));
        $this->assertSame(['groups' => []], SidebarPreferences::normalize(42));

        $beaucoup = [];
        for ($i = 0; $i < 100; $i++) {
            $beaucoup['m' . (1900 + $i) . '-1'] = true;
        }
        $this->assertCount(SidebarPreferences::MAX_GROUPS, SidebarPreferences::normalize(['groups' => $beaucoup])['groups']);
    }

    public function testLesPeriodesRestentUnObjetEnJson(): void
    {
        // Un `[]` deviendrait un tableau dans le navigateur : JSON.stringify y
        // perdrait les clés posées ensuite.
        $this->assertSame('{"groups":{}}', json_encode(SidebarPreferences::forJson(['groups' => []])));
        $this->assertSame('{"spaces":true,"groups":{"7d":false}}', json_encode(SidebarPreferences::forJson(['spaces' => true, 'groups' => ['7d' => false]])));
    }

    public function testLaConfigurationDuNavigateur(): void
    {
        $neuf = SidebarPreferences::clientConfig(['id' => 1, 'ui_sidebar' => null]);
        $this->assertFalse($neuf['saved']);
        $this->assertSame(SidebarPreferences::DEFAULTS, $neuf['defaults']);
        $this->assertSame('{"groups":{}}', json_encode($neuf['state']));

        $garde = SidebarPreferences::clientConfig(['id' => 1, 'ui_sidebar' => '{"spaces":true,"groups":{"today":false}}']);
        $this->assertTrue($garde['saved']);
        $this->assertSame('{"spaces":true,"groups":{"today":false}}', json_encode($garde['state']));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeCablage(): void
    {
        $migration = self::lire('database/migrations/109_user_sidebar_state.sql');
        $this->assertMatchesRegularExpression('/ALTER TABLE users\s+ADD COLUMN ui_sidebar VARCHAR\(2000\) NULL DEFAULT NULL;/', $migration);

        $this->assertStringContainsString("require ROOT . '/src/Helpers/SidebarPreferences.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/SidebarPreferences.php',", self::lire('tests/bootstrap.php'));

        $index = self::lire('public/index.php');
        $this->assertStringContainsString("\$router->post('/settings/sidebar',        [UserSettingsController::class, 'updateSidebar']);", $index);

        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertMatchesRegularExpression(
            '/function updateSidebar\(\): void\s*\{\s*AuthMiddleware::requireAuth\(\);.*?SidebarPreferences::save\(.*?AuthMiddleware::refreshUser\(\);/s',
            $ctrl
        );

        // Plus aucune écriture en localStorage : l'état part au compte.
        $js = self::lire('public/assets/js/sidebar-sections.js');
        $this->assertStringNotContainsString('localStorage.setItem', $js);
        $this->assertStringContainsString("fetch('/settings/sidebar', {", $js);
        $this->assertStringContainsString('keepalive: true', $js);
    }

    // ------------------------------------------------------------------
    // Exécution sous node : démarrage + module
    // ------------------------------------------------------------------

    /** Script de démarrage rendu pour un compte, tel que head-meta le pose. */
    private static function boot(?string $etatGarde): string
    {
        $_SESSION['user'] = ['id' => 42, 'role' => 'user', 'ui_sidebar' => $etatGarde];
        $_SESSION['csrf'] = str_repeat('c', 64);
        ob_start();
        require dirname(__DIR__, 2) . '/templates/partials/sidebar-sections-boot.php';
        $html = (string)ob_get_clean();
        self::assertSame(1, preg_match('/<script>(.*?)<\/script>/s', $html, $m));
        self::assertSame(1, preg_match('/^[\x00-\x7F]*$/', $m[1]), 'Le script de démarrage doit rester pur ASCII.');
        return $m[1];
    }

    public function testUnCompteNeufOuvreLeVoletToutDeplie(): void
    {
        $r = self::node(self::boot(null), null, "out({ attrs: html.attrs, posts: posts });");
        $this->assertSame([], $r['attrs'], 'Aucune section repliée.');
        $this->assertSame([], $r['posts'], 'Rien à enregistrer tant que la personne ne touche à rien.');
    }

    public function testUnGesteEstEnregistreSurLeCompte(): void
    {
        $scenario = <<<'JS'
            click(toggle('spaces'));
            const apres = Object.assign({}, html.attrs);
            click(toggle('history'));
            out({ apres: apres, final: html.attrs, posts: posts });
            JS;
        $r = self::node(self::boot(null), null, $scenario);
        $this->assertSame('closed', $r['apres']['data-sb-spaces'] ?? null, 'Espaces repliés au clic.');
        $this->assertSame('closed', $r['final']['data-sb-history']);
        $this->assertCount(2, $r['posts'], 'Un envoi par geste, sans attendre.');
        $this->assertSame('/settings/sidebar', $r['posts'][1]['url']);
        $this->assertSame(str_repeat('c', 64), $r['posts'][1]['csrf'], 'Jeton du démarrage quand la page n’a pas de balise csrf-token.');
        $this->assertTrue($r['posts'][1]['keepalive']);
        $this->assertEquals(['spaces' => false, 'groups' => [], 'history' => false], $r['posts'][1]['state']);
    }

    public function testLEtatDuCompteEstReluSurUnAutreAppareil(): void
    {
        $r = self::node(self::boot('{"spaces":false,"pinned":true,"history":false,"groups":{}}'), null, "out({ attrs: html.attrs, posts: posts, legacy: storage.removed });");
        $this->assertSame('closed', $r['attrs']['data-sb-spaces']);
        $this->assertArrayNotHasKey('data-sb-pinned', $r['attrs']);
        $this->assertSame('closed', $r['attrs']['data-sb-history']);
        $this->assertSame([], $r['posts']);
        $this->assertSame(['miaSidebar:42'], $r['legacy'], 'Ancienne clé du navigateur effacée : le compte fait foi.');
    }

    public function testLAncienEtatDuNavigateurEstRepriseUneFois(): void
    {
        // Avant : seuls les gestes étaient gardés, dans le navigateur.
        $ancien = '{"history":false,"groups":{"7d":true}}';
        $r = self::node(self::boot(null), $ancien, "setTimeout(() => out({ attrs: html.attrs, posts: posts, legacy: storage.removed }), 0);");
        $this->assertSame('closed', $r['attrs']['data-sb-history']);
        $this->assertArrayNotHasKey('data-sb-spaces', $r['attrs'], 'Jamais touchés : les défauts.');
        $this->assertCount(1, $r['posts'], 'Repris une fois, envoyé au compte.');
        $this->assertEquals(['history' => false, 'groups' => ['7d' => true]], $r['posts'][0]['state']);
        $this->assertSame(['miaSidebar:42'], $r['legacy'], 'Effacé une fois l’envoi réussi.');
    }

    /**
     * Exécute le script de démarrage puis sidebar-sections.js dans un DOM
     * minimal : <html> (attributs), un chevron par section, fetch et
     * localStorage enregistrés.
     *
     * @return array<string, mixed>
     */
    private static function node(string $boot, ?string $ancienEtat, string $scenario): array
    {
        $node = null;
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            $node = $explicite;
        } else {
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
                $candidat = rtrim($dossier, '/') . '/node';
                if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                    $node = $candidat;
                    break;
                }
            }
        }
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $module = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/sidebar-sections.js');
        $dom = <<<'JS'
            'use strict';
            const out = (v) => process.stdout.write(JSON.stringify(v));
            const html = { attrs: {}, lang: 'fr',
                setAttribute(k, v) { this.attrs[k] = String(v); },
                removeAttribute(k) { delete this.attrs[k]; },
                getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; } };
            function el(attrs) {
                return { attrs: Object.assign({}, attrs), classList: { toggle() {}, contains() { return false; } },
                    setAttribute(k, v) { this.attrs[k] = String(v); },
                    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
                    hasAttribute(k) { return k in this.attrs; },
                    closest(sel) {
                        const m = sel.match(/\[([a-z-]+)\]/g) || [];
                        return m.some((a) => a.slice(1, -1) in this.attrs) ? this : null;
                    } };
            }
            const toggles = ['spaces', 'pinned', 'history', 'account'].map((k) => el({ 'data-sb-toggle': k }));
            const toggle = (k) => toggles.find((t) => t.attrs['data-sb-toggle'] === k);
            let onClick = null;
            global.window = global;
            window.t = (s) => s;
            global.document = {
                documentElement: html, readyState: 'complete',
                querySelector(sel) { return sel === 'meta[name="mia-user"]' ? { content: '42' } : null; },
                querySelectorAll(sel) {
                    const m = sel.match(/^\[data-sb-toggle="([a-z]+)"\]$/);
                    return m ? toggles.filter((t) => t.attrs['data-sb-toggle'] === m[1]) : [];
                },
                getElementById() { return null; },
                addEventListener(type, fn) { if (type === 'click') onClick = fn; },
            };
            const click = (target) => onClick({ target: target, preventDefault() {} });
            const storage = { data: {}, removed: [] };
            global.localStorage = {
                getItem(k) { return k in storage.data ? storage.data[k] : null; },
                setItem(k, v) { storage.data[k] = v; },
                removeItem(k) { storage.removed.push(k); delete storage.data[k]; },
            };
            const posts = [];
            global.fetch = (url, opts) => {
                posts.push({ url: url, csrf: opts.headers['X-CSRF-Token'], keepalive: opts.keepalive,
                    state: JSON.parse(decodeURIComponent(opts.body.replace(/^state=/, ''))) });
                return Promise.resolve({ ok: true });
            };
            JS;
        $script = $dom . "\n"
            . ($ancienEtat !== null ? 'storage.data["miaSidebar:42"] = ' . json_encode($ancienEtat) . ";\n" : '')
            . $boot . "\n"
            . $module . "\n"
            . $scenario . "\n";
        $fichier = tempnam(sys_get_temp_dir(), 'mia-volet-') . '.js';
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
        $resultat = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultat)) {
            self::fail("Volet sous node : échec (code $code) :\n" . $erreurs . "\n" . $sortie);
        }
        return $resultat;
    }
}
