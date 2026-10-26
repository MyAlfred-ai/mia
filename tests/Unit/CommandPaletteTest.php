<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Palette « Rechercher ou aller à… » (⌘K) : la partie PURE de
 * command-palette.js exécutée sous node (recherche sans accents, classement,
 * surlignage), puis son câblage — partial, déclencheurs, fin de l'ancienne
 * recherche du volet, traductions, documentation.
 *
 * Sans node (conteneur applicatif), la partie JavaScript est SKIPPÉE.
 */
final class CommandPaletteTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // Partie pure, sous node
    // ------------------------------------------------------------------

    public function testLaRechercheIgnoreAccentsEtCasseEtClasseLeLibelleDAbord(): void
    {
        $r = self::node(<<<'JS'
            const entries = [
                { kind: 'page', space: 'me', label: 'Comptes connectés', path: 'Réglages › Mon compte', href: '/settings/connections', keys: 'paramètres connexions gmail' },
                { kind: 'page', space: 'admin', label: 'Connecteurs', path: 'Administration › Capacités', href: '/admin/connectors', keys: '' },
                { kind: 'page', space: 'admin', label: 'Consommation', path: 'Administration › Coûts', href: '/admin/usage', keys: 'usages' },
                { kind: 'page', space: 'me', label: 'Usage & crédits', path: 'Réglages › Mon compte', href: '/settings/usage', keys: 'paramètres usage quota' },
                { kind: 'action', space: 'work', label: 'Nouvelle conversation', path: 'Espace de travail', href: '/', keys: '' },
                { kind: 'page', space: 'work', label: 'Assistants', path: 'Espace de travail', href: '/admin/profiles', keys: 'assistant' },
            ];
            out({
                normalize: M.normalize('  Réglages  →  Élagage '),
                connexions: M.filter(entries, 'connexions').map(e => e.href),
                connect: M.filter(entries, 'CONNECT').map(e => e.href),
                usages: M.filter(entries, 'usages').map(e => e.href),
                credits: M.filter(entries, 'credits').map(e => e.href),
                deuxMots: M.filter(entries, 'mon compte usage').map(e => e.href),
                rien: M.filter(entries, 'zzz').length,
                vide: M.score(entries[0], '   '),
                limite: M.filter(entries, 'e', 2).length,
                suggestions: M.suggestions(entries).map(e => e.href),
                surligne: M.highlightParts('Préférences IA', 'prefer'),
                surligneMots: M.highlightParts('Comptes connectés', 'conn comp').filter(p => p.hit).map(p => p.text),
                sansRecherche: M.highlightParts('Abc', ''),
            });
            JS);

        $this->assertSame('reglages → elagage', $r['normalize']);
        $this->assertSame(['/settings/connections'], $r['connexions'], 'Un ancien nom (mot-clé) retrouve la page renommée.');
        $this->assertSame('/admin/connectors', $r['connect'][0], 'Le libellé qui commence par la recherche passe en tête.');
        $this->assertContains('/settings/connections', $r['connect']);
        $this->assertSame(['/admin/usage'], $r['usages']);
        $this->assertSame(['/settings/usage'], $r['credits'], 'Sans accent, « credits » trouve « crédits ».');
        $this->assertSame(['/settings/usage'], $r['deuxMots'], 'Chaque mot doit se trouver quelque part (libellé, chemin ou mots-clés).');
        $this->assertSame(0, $r['rien']);
        $this->assertSame(0, $r['vide']);
        $this->assertSame(2, $r['limite']);
        $this->assertSame(['/', '/admin/profiles'], $r['suggestions'], 'Avant toute frappe : les actions puis l’espace de travail.');
        $this->assertSame([['text' => 'Préfér', 'hit' => true], ['text' => 'ences IA', 'hit' => false]], $r['surligne'], 'Le surlignage garde les accents du texte d’origine.');
        $this->assertSame(['Comp', 'conn'], $r['surligneMots']);
        $this->assertSame([['text' => 'Abc', 'hit' => false]], $r['sansRecherche']);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLePartialPoseLesDonneesSansTexteDansLeScript(): void
    {
        $tpl = self::lire('templates/partials/command-palette.php');
        $this->assertStringContainsString('NavRegistry::paletteEntries($paletteUser, $paletteReconnect)', $tpl);
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $tpl);
        $this->assertStringContainsString('role="combobox"', $tpl);
        $this->assertStringContainsString('role="listbox"', $tpl);
        $this->assertStringContainsString('JSON_HEX_TAG', $tpl, 'Les libellés (noms de connecteurs, chemins) ne doivent pas fermer le <script>.');
        $this->assertStringContainsString("te('Rechercher ou aller à…')", $tpl);

        $js = self::lire('public/assets/js/command-palette.js');
        $this->assertStringContainsString("'/chat/search?q='", $js);
        $this->assertStringContainsString('mySeq !== seq', $js, 'Une réponse périmée ne remplace pas la plus récente.');
        $this->assertStringContainsString('window.switchConversation', $js, 'Dans le chat, une conversation s’ouvre sans recharger.');
        $this->assertStringContainsString("'[data-palette-open]'", $js);
        $this->assertStringNotContainsString('MiaHub', $js, 'Plus de fenêtre de réglages à refermer : Réglages et Admin sont des liens.');
        // Le texte surligné vit dans son propre span : dans la rangée flex du libellé,
        // chaque <mark> serait un élément écarté par le gap (« conn ectés »).
        $this->assertStringContainsString('<span class="cmdp-label-text">\' + marked(e.label, query)', $js);
        $this->assertStringContainsString(".cmdp-label-text {\n    min-width: 0;\n    white-space: nowrap;", self::lire('public/assets/css/main.css'));
        $this->assertDoesNotMatchRegularExpression('/[àâçéèêëîïôûùüÿœ]/u', self::chainesJs($js), 'Aucun texte français dans le script : il vient du serveur.');
    }

    public function testLaPaletteRemplaceLaRechercheDuVolet(): void
    {
        foreach (['templates/chat/index.php', 'templates/partials/chat-shell-sidebar.php'] as $f) {
            $tpl = self::lire($f);
            $this->assertStringNotContainsString('id="convSearchInput"', $tpl, "$f : l'ancien champ de recherche reste.");
            $this->assertStringNotContainsString('id="convSearchResults"', $tpl, "$f : l'ancienne liste de résultats reste.");
            $this->assertStringContainsString('data-palette-open', $tpl, "$f : rien n'ouvre la palette.");
            $this->assertStringContainsString('data-palette-kbd', $tpl, "$f : le rappel de touche manque.");
        }
        $this->assertStringContainsString("require ROOT . '/templates/partials/command-palette.php';", self::lire('templates/chat/index.php'));
        $this->assertStringContainsString("require ROOT . '/templates/partials/command-palette.php';", self::lire('templates/partials/admin-nav.php'));
        $this->assertStringContainsString('data-palette-open', self::lire('templates/partials/sidebar-shortcuts.php'), 'La tuile « Tout » ouvre la palette.');

        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringNotContainsString('initConvSearch', $chat, 'Une seule recherche : la palette.');
        $this->assertStringNotContainsString('convSearchResults', $chat);
        $this->assertStringNotContainsString('convSearchInput', self::lire('public/assets/js/chat-shell.js'));

        // Correctif : l'épingle des résultats était lue sans être renvoyée.
        $search = self::lire('src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression("/'is_pinned'\s*=>\s*\(int\)\(\\\$row\['is_pinned'\]/", $search);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLesTextesSontTraduitsEtDocumentes(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (['Rechercher ou aller à…', 'Rechercher une page, une action ou une conversation', 'Recherche dans les conversations…',
                  'Suggestions', 'Conversations', 'Action', 'Résultats', 'naviguer', 'ouvrir', 'fermer', 'Échap', 'Annuler', 'Tout'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
        $i18n = self::lire('tests/Unit/I18nContractTest.php');
        $this->assertStringContainsString("'templates/partials/command-palette.php'", $i18n);

        $this->assertStringContainsString('Rechercher ou aller à', self::lire('docs/user/fonctionnalites/conversations/gerer-ses-conversations.md'));
        $this->assertStringContainsString('Rechercher ou aller à', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('command-palette.js', self::lire('CLAUDE.md'));
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /** Chaînes littérales du script (commentaires exclus, grossièrement). */
    private static function chainesJs(string $js): string
    {
        $sansCommentaires = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $js) ?? $js;
        preg_match_all("/'(?:[^'\\\\\\n]|\\\\.)*'/", $sansCommentaires, $m);
        return implode("\n", $m[0]);
    }

    /** @return array<string, mixed> */
    private static function node(string $scenario): array
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
        $module = dirname(__DIR__, 2) . '/public/assets/js/command-palette.js';
        $script = "'use strict';\n"
            . 'const M = require(' . json_encode($module) . ");\n"
            . "if (typeof M.filter !== 'function') throw new Error('Partie pure absente.');\n"
            . "const out = (v) => process.stdout.write(JSON.stringify(v));\n"
            . $scenario;
        $fichier = tempnam(sys_get_temp_dir(), 'mia-palette-') . '.js';
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
            self::fail("command-palette.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return $resultat;
    }
}
