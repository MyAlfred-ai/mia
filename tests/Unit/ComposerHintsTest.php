<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Idées d'utilisation de la zone de saisie vide : ce qu'on propose
 * (ComposerHints, selon les droits et les connexions de la personne), comment
 * on le fait défiler (composer-hints.js, partie pure sous node) et le câblage.
 *
 * La règle qui compte : une idée ne promet jamais ce que la personne ne peut
 * pas faire — un connecteur non relié, un outil arrêté, une tâche programmée
 * sans le droit d'en créer.
 */
final class ComposerHintsTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::use('fr');
        ToolCatalog::setToday(null);
    }

    private static function lire(string $chemin): string
    {
        $contenu = file_get_contents(ROOT . '/' . $chemin);
        if (!is_string($contenu)) {
            self::fail("Fichier introuvable : $chemin");
        }

        return $contenu;
    }

    /** @return list<string> */
    private static function groupes(array $caps): array
    {
        return array_values(array_unique(array_column(ComposerHints::forChat($caps), 'g')));
    }

    /** Tout ce qu'une personne peut obtenir, d'un coup. */
    private const TOUT = [
        'tools'      => ['image', 'html', 'map', 'video'],
        'connectors' => ['gmail', 'outlook', 'calendar', 'mscalendar', 'drive', 'tasks', 'mstodo', 'slack'],
        'web'        => true,
        'kb'         => true,
        'schedule'   => true,
        'workflow'   => true,
        'assistant'  => true,
        'company'    => 'MyAlfred',
    ];

    // ------------------------------------------------------------------
    // Ce qu'on propose
    // ------------------------------------------------------------------

    public function testSansRienDePlusLesIdeesRestentCeQueToutLeMondePeutFaire(): void
    {
        $this->assertSame(['redaction', 'analyse', 'fichiers', 'memoire'], self::groupes([]));
    }

    public function testUnConnecteurNEstCiteQueSIlEstRelie(): void
    {
        $this->assertNotContains('mail', self::groupes(['connectors' => []]));
        $this->assertContains('mail', self::groupes(['connectors' => ['gmail']]));
        $this->assertContains('mail', self::groupes(['connectors' => ['outlook']]));
        $this->assertContains('agenda', self::groupes(['connectors' => ['mscalendar']]));
        $this->assertContains('taches', self::groupes(['connectors' => ['mstodo']]));
        $this->assertContains('drive', self::groupes(['connectors' => ['drive']]));
        $this->assertContains('slack', self::groupes(['connectors' => ['slack']]));
        // Gmail et Outlook disent la même chose : un seul groupe, pas deux fois les mêmes phrases.
        $mail = array_filter(ComposerHints::forChat(['connectors' => ['gmail', 'outlook']]), static fn(array $h): bool => $h['g'] === 'mail');
        $this->assertCount(2, $mail);
        // Une clé inconnue ne fabrique rien.
        $this->assertSame(self::groupes([]), self::groupes(['connectors' => ['inconnu', 'web']]));
    }

    public function testLesOutilsEtLesDroitsOuvrentLeursIdees(): void
    {
        $g = self::groupes(self::TOUT);
        foreach (['image', 'page', 'carte', 'video', 'web', 'bases', 'programmee', 'workflow', 'assistant', 'entreprise'] as $groupe) {
            $this->assertContains($groupe, $g, "Groupe « $groupe » absent alors que la capacité est là.");
        }
        $this->assertContains('page', self::groupes(['tools' => ['html_illustrated']]), 'Un site illustré est une page web.');
        foreach (['web', 'bases', 'programmee', 'workflow', 'assistant', 'entreprise', 'image'] as $groupe) {
            $this->assertNotContains($groupe, self::groupes([]));
        }
    }

    /** L'exemple de tâche programmée ne cite qu'une source que la personne a. */
    public function testLExempleDeTacheProgrammeeSuitLesSourcesDisponibles(): void
    {
        $phrase = static function (array $caps): string {
            foreach (ComposerHints::forChat($caps + ['schedule' => true]) as $h) {
                if ($h['g'] === 'programmee') {
                    return $h['t'];
                }
            }
            return '';
        };
        $this->assertStringContainsString('emails', $phrase(['connectors' => ['gmail'], 'web' => true]));
        $this->assertStringContainsString('veille', $phrase(['web' => true]));
        $avecRien = $phrase([]);
        $this->assertStringNotContainsString('email', $avecRien);
        $this->assertStringNotContainsString('veille', $avecRien);
    }

    public function testLeNomDeLEntrepriseEstRepris(): void
    {
        $textes = array_column(ComposerHints::forChat(['company' => 'Globex']), 't');
        $this->assertContains('Présente Globex en une page pour un prospect', $textes);
        // Trop long pour une ligne : pas d'idée plutôt qu'une idée coupée.
        $this->assertNotContains('entreprise', self::groupes(['company' => str_repeat('Groupe ', 10)]));
        $this->assertNotContains('entreprise', self::groupes(['company' => '   ']));
    }

    /** Un outil grisé dans le menu « + » ne donne aucune idée. */
    public function testSeulsLesOutilsEnServiceComptent(): void
    {
        ToolCatalog::setToday('2026-10-16');
        $types = ComposerHints::usableToolTypes([
            'image' => [['type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2', 'last_test_ok' => 1]],
            'html'  => [['type' => 'html_illustrated'], ['type' => 'html', 'last_test_ok' => 0]],
            'map'   => [['type' => 'map', 'last_test_ok' => 0]],
            // L'API vidéo d'OpenAI est arrêtée depuis le 24/09/2026.
            'video' => [['type' => 'video', 'provider' => 'openai', 'model' => 'sora-2']],
        ]);
        $this->assertSame(['image', 'html_illustrated'], $types);
    }

    public function testChaquePhraseTientSurUneLigneDansLesDeuxLangues(): void
    {
        foreach (['fr', 'en'] as $langue) {
            I18n::use($langue);
            foreach (ComposerHints::forChat(self::TOUT) as $h) {
                $this->assertLessThanOrEqual(ComposerHints::MAX_LEN, mb_strlen($h['t']), "Trop long ($langue) : {$h['t']}");
                $this->assertNotSame('', trim($h['t']));
            }
        }
    }

    public function testToutesLesPhrasesSontTraduites(): void
    {
        $fr = array_column(ComposerHints::forChat(self::TOUT), 't');
        I18n::use('en');
        $en = array_column(ComposerHints::forChat(self::TOUT), 't');
        $this->assertCount(count($fr), $en);
        foreach ($fr as $i => $texte) {
            $this->assertNotSame($texte, $en[$i], "Non traduite : $texte");
        }
    }

    // ------------------------------------------------------------------
    // Défilement (node)
    // ------------------------------------------------------------------

    public function testLesIdeesAlternentLesGroupesEtPassentToutes(): void
    {
        $r = self::node();
        $this->assertCount(6, $r['ordre']);
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'c1', 'c2'], $r['trie'], 'Chaque idée passe une fois, aucune ne se perd.');
        // Les trois premières viennent de trois groupes différents.
        $this->assertCount(3, array_unique(array_map(static fn(string $t): string => $t[0], array_slice($r['ordre'], 0, 3))));
        // Jamais deux idées du même groupe à la suite tant qu'il en reste un autre.
        $this->assertNotSame($r['ordre'][3][0], $r['ordre'][4][0]);
        $this->assertSame([], $r['vide'], 'Entrée invalide : aucune idée, aucune erreur.');
        $this->assertSame($r['graine1'], $r['graine2'], 'Même tirage, même ordre.');
        $this->assertGreaterThanOrEqual(3600, $r['court']);
        $this->assertLessThanOrEqual(6400, $r['long']);
        $this->assertGreaterThan($r['court'], $r['moyen']);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaClasseEstChargeeParLesDeuxListes(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Helpers/ComposerHints.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/ComposerHints.php'", self::lire('tests/bootstrap.php'));
    }

    public function testLaPageLivreLesIdeesEtChargeLeScriptApresLeChat(): void
    {
        $page = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('window.COMPOSER_HINTS = <?= json_encode(ComposerHints::forChat([', $page);
        // Le nom de l'entreprise est un texte d'administrateur : jamais de </script> possible.
        $bloc = substr($page, strpos($page, 'window.COMPOSER_HINTS'), 1400);
        $this->assertStringContainsString('JSON_HEX_TAG', $bloc);
        // Idées calculées depuis ce que la page a déjà : menu « + », bases, droits.
        foreach (['$connectorRows', '$imageTools', '$htmlTools', '$mapTools', '$videoTools', '$webFeatureEnabled',
                  "ScheduledTask::allows(\$hintUser, 'create')", "Workflow::allows(\$hintUser, 'create')", 'AssistantDraft::canCreate($hintUser)'] as $source) {
            $this->assertStringContainsString($source, $bloc);
        }
        $this->assertGreaterThan(strpos($page, '$connectorRows = [];'), strpos($page, 'window.COMPOSER_HINTS'), 'Les connecteurs sont connus avant.');
        $chat  = strpos($page, '/assets/js/chat.js');
        $hints = strpos($page, '/assets/js/composer-hints.js');
        $this->assertNotFalse($hints);
        $this->assertGreaterThan($chat, $hints, 'Après chat.js, qui raccourcit le texte d\'aide sur téléphone.');
    }

    public function testLeScriptNAAucunTexteASoi(): void
    {
        $js = self::lire('public/assets/js/composer-hints.js');
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js) ?? '';
        $this->assertDoesNotMatchRegularExpression('/\bt\(\s*[\'"]/', $code);
        $this->assertDoesNotMatchRegularExpression("/'[^'\\n]*[à-ÿ][^'\\n]*'/u", $code, 'Aucune phrase en dur : elles viennent du serveur.');
        $this->assertStringContainsString("insertAdjacentElement('afterend', overlay)", $js, 'Le calque suit le champ : :placeholder-shown le masque.');
        $this->assertStringContainsString("setAttribute('aria-hidden', 'true')", $js);
    }

    public function testLaFeuilleDeStyleMasqueLeCalqueDesQuOnEcrit(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.msg-textarea:not(:placeholder-shown) + .composer-hint', $css);
        $this->assertStringContainsString('.input-row.has-composer-hint .msg-textarea::placeholder { color: transparent; }', $css);
        $bloc = substr($css, strpos($css, '.composer-hint {'), 600);
        $this->assertStringContainsString('font-style: italic;', $bloc);
        $this->assertStringContainsString('font-weight: 300;', $bloc);
        $this->assertStringContainsString('pointer-events: none;', $bloc);
        $this->assertStringContainsString('@keyframes composer-hint-in', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\/\*[^*]*\*\/\s*\.composer-hint-line\.is-entering/', $css);
        $this->assertStringContainsString('html[data-contrast="high"] .composer-hint', $css);
        // Le texte d'aide sans script garde la même allure.
        $this->assertStringContainsString('.msg-textarea::placeholder { color: var(--text-muted); font-style: italic; font-weight: 300; }', $css);
    }

    public function testLaFonctionnaliteEstDocumentee(): void
    {
        $this->assertStringContainsString('<!-- id: idees-zone-de-saisie ', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Des idées dans la zone de saisie', self::lire('docs/user/fonctionnalites/conversations/mener-une-conversation.md'));
        $this->assertStringContainsString('idées d\'utilisation', self::lire('docs/GUIDE-UTILISATEUR.md'));
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function node(): array
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

        $script = "'use strict';\n" . self::lire('public/assets/js/composer-hints.js') . "\n" . <<<'JS'
            const M = globalThis.MiaComposerHints;
            if (!M || typeof M.rotation !== 'function') throw new Error('Partie pure absente.');
            if (typeof M.next === 'function') throw new Error('La partie DOM ne doit pas se charger sans document.');
            function graine(n) { let s = n; return () => { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; }; }
            const ideas = [
                { g: 'a', t: 'a1' }, { g: 'a', t: 'a2' }, { g: 'a', t: 'a3' },
                { g: 'b', t: 'b1' }, { g: 'c', t: 'c1' }, { g: 'c', t: 'c2' },
            ];
            const ordre = M.rotation(ideas, graine(7));
            process.stdout.write(JSON.stringify({
                ordre: ordre,
                trie: ordre.slice().sort(),
                vide: M.rotation([null, { g: 'x' }, { g: 'y', t: '  ' }, 'texte'], graine(1)),
                graine1: M.rotation(ideas, graine(42)),
                graine2: M.rotation(ideas, graine(42)),
                court: M.dwell('Oui'),
                moyen: M.dwell('Résume les emails importants reçus cette semaine'),
                long: M.dwell('x'.repeat(400)),
            }));
            JS;

        $fichier = tempnam(sys_get_temp_dir(), 'mia-idees-js-');
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
        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("composer-hints.js a échoué sous node (code $code) :\n" . $erreurs);
        }

        return $resultats;
    }
}
