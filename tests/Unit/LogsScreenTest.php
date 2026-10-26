<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Écran des journaux (Maintenance → Diagnostic → Journaux) : lecture du
 * fichier, classement des entrées, regroupement en problèmes, filtres,
 * frise, câblage. Tout est pur sauf la lecture bornée du fichier, testée sur
 * un fichier temporaire.
 */
final class LogsScreenTest extends TestCase
{
    private static function lire(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    /** @return list<array<string,mixed>> */
    private static function parse(string $text): array
    {
        return LogParser::parse($text);
    }

    // ------------------------------------------------------------------
    // 1. Lecture des entrées
    // ------------------------------------------------------------------

    public function testUneLigneDeLApplicationDonneEtiquetteMessageEtHorodatage(): void
    {
        $e = self::parse("[18-Oct-2026 09:12:03 UTC] [JobQueue] claimNext : SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n");
        $this->assertCount(1, $e);
        $this->assertSame(gmmktime(9, 12, 3, 10, 18, 2026), $e[0]['ts']);
        $this->assertSame('JobQueue', $e[0]['tag']);
        $this->assertSame('claimNext : SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', $e[0]['message']);
        $this->assertSame('error', $e[0]['level']);
        $this->assertSame('jobs', $e[0]['source']);
    }

    public function testLesTroisLignesDUnIncidentNeFontQuUneEntree(): void
    {
        // ErrorHandler::journaliser écrit le résumé, puis la pile, puis chaque cause.
        $log = "[18-Oct-2026 10:01:05 UTC] [incident a1b2c3d4e5f6] RuntimeException: Réponse illisible @ /var/www/html/src/LLM/OpenAIAdapter.php:612\n"
            . "[18-Oct-2026 10:01:05 UTC] [incident a1b2c3d4e5f6] #0 /var/www/html/src/LLM/OpenAIAdapter.php(388): OpenAIAdapter->decode()\n"
            . "#1 /var/www/html/vendor/composer/ClassLoader.php(12): run()\n"
            . "#2 {main}\n"
            . "[18-Oct-2026 10:01:05 UTC] [incident a1b2c3d4e5f6] causée par JsonException: Syntax error @ /var/www/html/src/LLM/OpenAIAdapter.php:598\n"
            . "[18-Oct-2026 10:01:06 UTC] [AUTH] Connexion Google : a@b.fr depuis 1.2.3.4\n";
        $e = self::parse($log);
        $this->assertCount(2, $e);
        $inc = $e[0];
        $this->assertSame('a1b2c3d4e5f6', $inc['incident']);
        $this->assertSame('RuntimeException', $inc['tag']);
        $this->assertSame('Réponse illisible', $inc['message']);
        $this->assertSame('src/LLM/OpenAIAdapter.php:612', $inc['location']);
        $this->assertSame('ia', $inc['source'], 'Un incident sans étiquette se range par l\'emplacement de l\'exception.');
        $this->assertSame('error', $inc['level']);
        $this->assertStringContainsString('#0 /var/www/html/src/LLM/OpenAIAdapter.php(388)', $inc['trace']);
        $this->assertStringContainsString('#2 {main}', $inc['trace']);
        $this->assertCount(1, $inc['causes']);
        $this->assertStringContainsString('JsonException', $inc['causes'][0]);
        $this->assertSame(4, substr_count($inc['raw'], "\n"), 'Le texte brut garde toutes les lignes de l\'incident.');
    }

    public function testDeuxIncidentsDifferentsRestentSepares(): void
    {
        $log = "[18-Oct-2026 10:01:05 UTC] [incident aaaaaaaaaaaa] RuntimeException: un @ /x.php:1\n"
            . "[18-Oct-2026 10:01:05 UTC] [incident bbbbbbbbbbbb] RuntimeException: deux @ /x.php:1\n";
        $this->assertCount(2, self::parse($log));
    }

    public function testUneErreurNativeDePhpGardeSaPileEtSonEmplacement(): void
    {
        $log = "[01-May-2026 08:08:14 UTC] PHP Fatal error:  Uncaught TypeError: Router::dispatch(): Argument #1 must be of type string in /var/www/html/src/Router.php:19\n"
            . "Stack trace:\n#0 /var/www/html/public/index.php(193): Router->dispatch()\n#1 {main}\n"
            . "  thrown in /var/www/html/src/Router.php on line 19\n";
        $e = self::parse($log)[0];
        $this->assertSame('PHP Fatal error', $e['tag']);
        $this->assertSame('critical', $e['level']);
        $this->assertSame('src/Router.php:19', $e['location']);
        $this->assertStringContainsString('#0 /var/www/html/public/index.php(193)', $e['trace']);
        $this->assertSame('php', $e['source']);
    }

    public function testUnAvertissementDErrorHandlerEstUnAvertissementPhp(): void
    {
        // L'ancien écran le rangeait dans « Autres » : il ne connaissait que « PHP Warning ».
        $e = self::parse("[18-Oct-2026 10:00:00 UTC] [Avertissement] Undefined array key \"sources\" @ /var/www/html/src/Schedule/Scheduler.php:144\n")[0];
        $this->assertSame('Avertissement', $e['tag']);
        $this->assertSame('warning', $e['level']);
        $this->assertSame('php', $e['source']);
        $this->assertSame('src/Schedule/Scheduler.php:144', $e['location']);
        $this->assertSame('Undefined array key "sources"', $e['message']);
    }

    public function testLaFinDuFichierLueAuMilieuDUneEntreeEnJetteLeMorceau(): void
    {
        $text = "#3 suite d'une pile coupée\n#4 {main}\n[18-Oct-2026 10:00:00 UTC] [KB] ok\n";
        $this->assertCount(1, LogParser::parse($text, true));
        $this->assertCount(2, LogParser::parse($text, false), 'Lu depuis le début, un texte sans horodatage reste une entrée.');
    }

    public function testHorodatagesLus(): void
    {
        $this->assertSame(gmmktime(14, 27, 17, 4, 30, 2026), LogParser::timestamp('30-Apr-2026 14:27:17 UTC'));
        $this->assertSame(gmmktime(12, 27, 17, 4, 30, 2026), LogParser::timestamp('30-Apr-2026 14:27:17 Europe/Paris'));
        $this->assertSame(gmmktime(8, 0, 0, 10, 18, 2026), LogParser::timestamp('2026-10-18T10:00:00+02:00'));
        $this->assertNull(LogParser::timestamp('pas une date'));
    }

    // ------------------------------------------------------------------
    // 2. Gravité, domaine, signature
    // ------------------------------------------------------------------

    /** @return array<string,array{0:?string,1:string,2:bool,3:string}> */
    public static function gravites(): array
    {
        return [
            'incident'              => ['RuntimeException', 'boom', true, 'error'],
            'base injoignable'      => ['DatabaseUnavailableException', 'Le serveur MySQL est injoignable', true, 'critical'],
            'classe absente'        => ['Error', "Failed opening required '/x.php'", true, 'critical'],
            'fatale d\'arrêt'       => ['Erreur fatale', 'Allowed memory size exhausted', true, 'critical'],
            'migration ratée'       => ['migrate', 'échec sur 058_x.sql : SQLSTATE[42S21]', false, 'critical'],
            'avertissement'         => ['Avertissement', 'Undefined array key', false, 'warning'],
            'php warning'           => ['PHP Warning', 'Undefined array key', false, 'warning'],
            'sqlstate'              => ['worker', 'réclamation impossible : SQLSTATE[HY000]', false, 'error'],
            'http 429'              => ['OpenAIAdapter/Responses', 'HTTP 429 : rate_limit_exceeded', false, 'error'],
            'code 400 —'            => ['ImageGenerator', "gpt-image : 400 — Invalid size", false, 'error'],
            'worker périmé'         => ['worker', 'version 1.2 ≠ version servie par l\'app', false, 'warning'],
            'connecteur absent'     => ['connecteurs', 'Google Drive demandé mais indisponible', false, 'warning'],
            'mot de passe faux'     => ['AUTH', 'Échec email/password pour a@b.fr', false, 'warning'],
            'connexion'             => ['AUTH', 'Connexion Google : a@b.fr', false, 'info'],
            'jeton périmé'          => ['CSRF', 'Rejet POST /x — jeton périmé', false, 'warning'],
            'démarrage'             => ['worker', 'démarré, version 1.5.1', false, 'info'],
        ];
    }

    #[DataProvider('gravites')]
    public function testGravite(?string $tag, string $message, bool $incident, string $attendu): void
    {
        $this->assertSame($attendu, LogCatalog::levelOf($tag, $message, $incident));
    }

    public function testDomaineParEtiquetteOuParEmplacement(): void
    {
        $this->assertSame('ia', LogCatalog::sourceOf('AnthropicAdapter/Tools', null));
        $this->assertSame('kb', LogCatalog::sourceOf('KB Sync', null));
        $this->assertSame('connecteurs', LogCatalog::sourceOf('Gmail user', null));
        $this->assertSame('connecteurs', LogCatalog::sourceOf('Drive OAuth', null));
        $this->assertSame('automatisation', LogCatalog::sourceOf('Workflows', null));
        $this->assertSame('securite', LogCatalog::sourceOf('AUTH', null));
        $this->assertSame('donnees', LogCatalog::sourceOf('migrate', null));
        $this->assertSame('couts', LogCatalog::sourceOf('Budget IA', null));
        $this->assertSame('kb', LogCatalog::sourceOf('RuntimeException', 'src/RAG/QdrantStore.php'));
        $this->assertSame('php', LogCatalog::sourceOf('RuntimeException', 'config/classes.php'));
        $this->assertSame('other', LogCatalog::sourceOf('Inconnu', null));
    }

    public function testToutesLesEtiquettesDuCodeOntUnDomaine(): void
    {
        // Une étiquette ajoutée dans le code sans être rangée tomberait dans « Autres » :
        // c'est exactement ce qui rendait l'ancien écran inutile.
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }
        $files = array_merge($files, glob(ROOT . '/scripts/*.php') ?: [], glob(ROOT . '/public/*.php') ?: []);
        $tags = [];
        foreach ($files as $file) {
            $src = (string)file_get_contents($file);
            preg_match_all('/error_log\(\s*(?:sprintf\(\s*)?[\'"]\[([^\]\'"$%{]{1,48})\]/u', $src, $m);
            foreach ($m[1] as $tag) {
                $tags[$tag] = substr($file, strlen(ROOT) + 1);
            }
            preg_match_all('/LOG_LABEL\s*=\s*\'\[?([^\]\']+)\]?\'/u', $src, $m);
            foreach ($m[1] as $tag) {
                $tags[$tag] = substr($file, strlen(ROOT) + 1);
            }
        }
        $this->assertGreaterThan(60, count($tags), 'Le balayage des étiquettes a cessé de trouver le code.');
        foreach ($tags as $tag => $file) {
            $this->assertNotSame('other', LogCatalog::sourceOf($tag, null), "Étiquette « [$tag] » ($file) sans domaine dans LogCatalog::SOURCES.");
        }
    }

    public function testLaSignatureIgnoreCeQuiVarieDUneOccurrenceALAutre(): void
    {
        $a = LogCatalog::fingerprint('KB', 'vectorisation échouée doc 92 : Qdrant injoignable (http://qdrant:6333).');
        $b = LogCatalog::fingerprint('KB', 'vectorisation échouée doc 123 : Qdrant injoignable (http://qdrant:6333).');
        $this->assertSame($a, $b);
        $this->assertSame(
            LogCatalog::fingerprint('AUTH', 'Connexion Google : claire@acme.fr depuis 192.0.2.10'),
            LogCatalog::fingerprint('AUTH', 'Connexion Google : paul@acme.fr depuis 192.0.2.11')
        );
        $this->assertSame(
            LogCatalog::fingerprint('x', 'session sess_fph5e7l1dgfnurhuacimb9lhnk introuvable'),
            LogCatalog::fingerprint('x', 'session sess_a8b2c3d4e5f6g7h8i9j0k1l2m3 introuvable')
        );
        // Un autre fichier manquant est un autre problème.
        $this->assertNotSame(
            LogCatalog::fingerprint('Error', "Failed opening required '/var/www/html/src/Drive/DocsFormat.php'"),
            LogCatalog::fingerprint('Error', "Failed opening required '/var/www/html/src/Middleware/AuthMiddleware.php'")
        );
        $this->assertNotSame(LogCatalog::fingerprint('KB', 'x'), LogCatalog::fingerprint('RAG', 'x'));
        $this->assertTrue(LogCatalog::isFingerprint($a));
    }

    // ------------------------------------------------------------------
    // 3. Filtres
    // ------------------------------------------------------------------

    private static function query(array $get, int $now = 1792310400, ?int $first = null, string $tz = 'UTC'): LogQuery
    {
        return LogQuery::fromRequest($get, $tz, $now, $first);
    }

    public function testParDefautLesDernieres24HeuresSansLesInfos(): void
    {
        $q = self::query([]);
        $this->assertSame('24h', $q->period);
        $this->assertSame(86400, $q->to - $q->from);
        $this->assertSame(LogCatalog::DEFAULT_LEVELS, $q->levels);
        $this->assertSame('problemes', $q->view);
        $this->assertSame('/admin/logs', $q->url(), 'Les valeurs par défaut ne chargent pas l\'adresse.');
    }

    public function testUnNumeroDIncidentCollePasseEnFiltreIncidentSurToutLeJournal(): void
    {
        $q = self::query(['q' => 'A1B2C3D4E5F6'], 1792310400, 1700000000);
        $this->assertSame('a1b2c3d4e5f6', $q->incident);
        $this->assertSame('', $q->search);
        $this->assertSame('tout', $q->period, 'L\'incident date peut-être d\'hier : on cherche partout.');
        $this->assertSame(1700000000, $q->from);
        $this->assertSame('flux', $q->view);
    }

    public function testLesAdressesDeLAncienneEcranRestentComprises(): void
    {
        $q = self::query(['type' => 'error', 'from' => '2026-10-18T00:00', 'to' => '2026-10-18T12:00']);
        $this->assertSame(['critical', 'error'], $q->levels);
        $this->assertSame('perso', $q->period);
        $this->assertSame('ia', self::query(['type' => 'llm'])->source);
        $this->assertSame('securite', self::query(['type' => 'auth'])->source);
    }

    public function testUnIntervalleSeLitDansLeFuseauDeLaPersonne(): void
    {
        // Minuit à Paris (UTC+2 en octobre) = 22 h UTC la veille.
        $q = self::query(['periode' => 'perso', 'du' => '2026-10-18T00:00', 'au' => '2026-10-18T12:00'], 1792310400, null, 'Europe/Paris');
        $this->assertSame(gmmktime(22, 0, 0, 10, 17, 2026), $q->from);
        $this->assertSame(gmmktime(10, 0, 0, 10, 18, 2026), $q->to);
        $this->assertSame('2026-10-18T00:00', $q->fromInput);
        // Un fuseau invalide retombe sur UTC, jamais sur une erreur.
        $this->assertSame('UTC', LogQuery::timeZone('Mars/Olympus;DROP')->getName());
        $this->assertSame('America/Argentina/Buenos_Aires', LogQuery::timeZone('America/Argentina/Buenos_Aires')->getName());
    }

    public function testRecherche(): void
    {
        [$terms, $excludes] = LogQuery::parseSearch('Timeout "gone away" -auth  -"jeton périmé"');
        $this->assertSame(['timeout', 'gone away'], $terms);
        $this->assertSame(['auth', 'jeton périmé'], $excludes);
        $q = self::query(['q' => 'mysql -claim', 'periode' => 'tout', 'niveaux' => 'tous'], 1792310400, 0);
        $base = ['ts' => 1792300000, 'level' => 'error', 'source' => 'jobs', 'incident' => null, 'sig' => 'x'];
        $this->assertTrue($q->matches($base + ['raw' => '[worker] MySQL server has gone away']));
        $this->assertFalse($q->matches($base + ['raw' => '[JobQueue] claimNext : MySQL server has gone away']));
        $this->assertFalse($q->matches($base + ['raw' => '[KB] Qdrant']));
    }

    public function testBasculerUneGraviteNeLaisseJamaisLaListeVide(): void
    {
        $q = self::query([]);
        $this->assertSame('tous', $q->toggledLevels('info'));
        $this->assertSame('critical,error', $q->toggledLevels('warning'));
        $seul = self::query(['niveaux' => 'error']);
        $this->assertNull($seul->toggledLevels('error'), 'Plus aucune gravité : retour au défaut.');
        $this->assertSame(LogCatalog::DEFAULT_LEVELS, LogQuery::parseLevels('n_importe_quoi'));
    }

    // ------------------------------------------------------------------
    // 4. Analyse : facettes, problèmes, frise
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private static function fabrique(int $now): array
    {
        $lines = [];
        $at = static fn(int $ts): string => '[' . gmdate('d-M-Y H:i:s', $ts) . ' UTC] ';
        // La veille de la fenêtre : un problème déjà connu.
        $lines[] = $at($now - 90000) . '[KB] vectorisation échouée doc 1 : Qdrant injoignable';
        for ($i = 0; $i < 5; $i++) {
            $lines[] = $at($now - 3600 * ($i + 1)) . '[KB] vectorisation échouée doc ' . (10 + $i) . ' : Qdrant injoignable';
        }
        $lines[] = $at($now - 600) . '[OpenAIAdapter] HTTP 429 : rate_limit_exceeded';
        $lines[] = $at($now - 300) . '[AUTH] Connexion Google : a@b.fr depuis 1.2.3.4';
        $lines[] = $at($now - 200) . '[Avertissement] Undefined array key "x" @ /var/www/html/src/Schedule/Scheduler.php:144';
        return LogParser::parse(implode("\n", $lines) . "\n");
    }

    public function testFacettesProblemesEtNouveautes(): void
    {
        $now = 1792310400;
        $entries = self::fabrique($now);
        $first = LogAnalysis::firstTimestamp($entries);
        $q = LogQuery::fromRequest([], 'UTC', $now, $first);
        $r = LogAnalysis::run($entries, $q, $first);

        $this->assertSame(6, $r['levelCounts']['error']);
        $this->assertSame(1, $r['levelCounts']['warning']);
        $this->assertSame(1, $r['levelCounts']['info'], 'Les infos sont comptées même masquées : la tuile dit ce qu\'on manque.');
        $this->assertSame(7, $r['total'], 'Infos masquées par défaut.');
        $this->assertSame(3, $r['groupTotal']);

        $kb = null;
        foreach ($r['groups'] as $g) {
            if ($g['tag'] === 'KB') {
                $kb = $g;
            }
        }
        $this->assertNotNull($kb);
        $this->assertSame(5, $kb['count']);
        $this->assertSame($now - 18000, $kb['first']);
        $this->assertSame($now - 3600, $kb['last']);
        $this->assertFalse($kb['new'], 'Vu avant la fenêtre : pas nouveau.');
        $this->assertSame(5, array_sum($kb['buckets']));
        $this->assertSame(2, $r['newCount']);
        $this->assertSame(5, $r['sigCounts'][$kb['sig']]);

        // Facette de domaine : elle ignore son propre filtre.
        $qKb = LogQuery::fromRequest(['domaine' => 'kb'], 'UTC', $now, $first);
        $rKb = LogAnalysis::run($entries, $qKb, $first);
        $this->assertSame(5, $rKb['total']);
        $this->assertSame(1, $rKb['sourceCounts']['ia']);
        $this->assertSame(5, $rKb['levelCounts']['error']);

        // Un problème choisi : le Flux n'en montre que lui, la liste des problèmes reste entière.
        $qSig = LogQuery::fromRequest(['sig' => $kb['sig'], 'vue' => 'flux'], 'UTC', $now, $first);
        $rSig = LogAnalysis::run($entries, $qSig, $first);
        $this->assertSame(5, $rSig['total']);
        $this->assertSame(3, $rSig['groupTotal']);
        $this->assertSame($now - 3600, $rSig['matches'][0]['ts'], 'Du plus récent au plus ancien.');
    }

    public function testTriDesProblemes(): void
    {
        $now = 1792310400;
        $entries = self::fabrique($now);
        $first = LogAnalysis::firstTimestamp($entries);
        $tags = static fn(array $r): array => array_map(static fn($g) => $g['tag'], $r['groups']);
        $this->assertSame(['OpenAIAdapter', 'KB', 'Avertissement'], $tags(LogAnalysis::run($entries, LogQuery::fromRequest([], 'UTC', $now, $first), $first)));
        $this->assertSame(['KB', 'Avertissement', 'OpenAIAdapter'], $tags(LogAnalysis::run($entries, LogQuery::fromRequest(['tri' => 'frequence'], 'UTC', $now, $first), $first)));
        $this->assertSame(['Avertissement', 'OpenAIAdapter', 'KB'], $tags(LogAnalysis::run($entries, LogQuery::fromRequest(['tri' => 'recent'], 'UTC', $now, $first), $first)));
    }

    public function testComparaisonAvecLaPeriodePrecedenteSeulementSiLeJournalLaCouvre(): void
    {
        $now = 1792310400;
        $entries = self::fabrique($now);
        $first = LogAnalysis::firstTimestamp($entries);
        $r = LogAnalysis::run($entries, LogQuery::fromRequest(['periode' => '6h'], 'UTC', $now, $first), $first);
        $this->assertSame(0, $r['previous'], 'Les 6 h précédentes sont couvertes par le journal.');
        $r24 = LogAnalysis::run($entries, LogQuery::fromRequest([], 'UTC', $now, $first), $first);
        $this->assertNull($r24['previous'], 'Le journal ne remonte pas à 48 h : pas de comparaison inventée.');
    }

    public function testFriseAlignéeSurLHeureLocaleEtBornee(): void
    {
        $now = gmmktime(10, 17, 0, 10, 18, 2026);
        foreach (array_keys(LogQuery::PERIODS) as $p) {
            $q = LogQuery::fromRequest(['periode' => $p], 'Asia/Kolkata', $now, $now - 86400 * 400);
            $h = LogAnalysis::histogram($q);
            $this->assertLessThanOrEqual(LogAnalysis::MAX_BUCKETS + 1, count($h['buckets']), "Période $p");
            $this->assertLessThanOrEqual($q->from, $h['start']);
            $this->assertGreaterThanOrEqual($q->to, end($h['buckets'])['to']);
        }
        // 24 h → tranches de 30 min alignées sur la demi-heure de l'Inde (UTC+5:30).
        $h = LogAnalysis::histogram(LogQuery::fromRequest([], 'Asia/Kolkata', $now, null));
        $this->assertSame(1800, $h['step']);
        $this->assertSame(0, ($h['start'] + 19800) % 1800);
    }

    // ------------------------------------------------------------------
    // 5. Lecture bornée du fichier
    // ------------------------------------------------------------------

    public function testLectureDeLaFinEtDesNouveautes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lgx');
        try {
            file_put_contents($file, str_repeat("[18-Oct-2026 10:00:00 UTC] [KB] ligne de remplissage\n", 100));
            $size = filesize($file);
            $tail = LogFile::tail($file, 200);
            $this->assertTrue($tail['truncated']);
            $this->assertSame($size, $tail['size']);
            $this->assertCount(3, LogParser::parse($tail['text'], true), 'La ligne coupée en tête est jetée.');

            $this->assertSame('', LogFile::since($file, $size)['text']);
            file_put_contents($file, "[18-Oct-2026 10:00:01 UTC] [KB] nouvelle\n[18-Oct-2026 10:00:02 UTC] [KB] en cours d'écri", FILE_APPEND);
            $since = LogFile::since($file, $size);
            $this->assertSame("[18-Oct-2026 10:00:01 UTC] [KB] nouvelle\n", $since['text'], 'Une ligne inachevée attend le passage suivant.');
            $this->assertSame($size + strlen($since['text']), $since['size']);

            $this->assertTrue(LogFile::since($file, $size * 10)['reset'], 'Fichier plus court que la position : il a été remplacé.');
            $this->assertTrue(LogFile::since($file, 0, 100)['overflow']);
        } finally {
            @unlink($file);
        }
        $this->assertFalse(LogFile::stat($file)['exists']);
        $this->assertSame('', LogFile::tail($file)['text']);
    }

    // ------------------------------------------------------------------
    // 6. Mise en forme
    // ------------------------------------------------------------------

    public function testPileDAppelsLisible(): void
    {
        $frames = LogView::frames("Stack trace:\n#0 /var/www/html/src/Jobs/JobQueue.php(311): PDOStatement->execute(Array)\n#1 /var/www/html/vendor/composer/ClassLoader.php(12): run()\n#2 [internal function]: f()\n#3 {main}");
        $this->assertCount(4, $frames);
        $this->assertSame(['0', 'PDOStatement->execute(Array)', 'src/Jobs/JobQueue.php', '311', true], [$frames[0]['n'], $frames[0]['call'], $frames[0]['file'], $frames[0]['line'], $frames[0]['app']]);
        $this->assertFalse($frames[1]['app'], 'Une bibliothèque n\'est pas le code de MIA.');
        $this->assertSame('interne', $frames[2]['file']);
        $this->assertSame('{main}', $frames[3]['call']);
    }

    public function testDatesEnFrancaisDansLeFuseau(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $now = gmmktime(10, 0, 0, 10, 18, 2026);
        $this->assertSame('Aujourd\'hui', LogView::dayLabel($now - 3600, $tz, $now));
        $this->assertSame('Hier', LogView::dayLabel($now - 86400, $tz, $now));
        $this->assertSame('vendredi 16 octobre', LogView::dayLabel($now - 2 * 86400, $tz, $now));
        $this->assertSame('12:00:00', LogView::clock($now, $tz));
        $this->assertSame('il y a 5' . "\u{00A0}" . 'min', LogView::relative($now - 300, $now));
        $this->assertStringContainsString('<rect', LogView::sparkline([0, 2, 5, 0]));
        $this->assertSame('', LogView::sparkline([]));
    }

    // ------------------------------------------------------------------
    // 7. Câblage
    // ------------------------------------------------------------------

    public function testRoutesEtChargement(): void
    {
        $index = self::lire('public/index.php');
        $this->assertStringContainsString("\$router->get('/admin/logs',               [LogsController::class, 'index']);", $index);
        $this->assertStringContainsString("[LogsController::class, 'feed']", $index);
        $classes = self::lire('config/classes.php');
        foreach (['LogCatalog', 'LogParser', 'LogFile', 'LogQuery', 'LogAnalysis', 'LogView'] as $c) {
            $this->assertStringContainsString("/src/Logs/$c.php", $classes);
            $this->assertStringContainsString("/src/Logs/$c.php", self::lire('tests/bootstrap.php'));
        }
        $this->assertStringContainsString('/src/Controllers/LogsController.php', $classes);
        $this->assertStringNotContainsString('function logs(', self::lire('src/Controllers/AdminController.php'), 'L\'ancien écran a quitté AdminController.');
    }

    public function testLeControleurResteEnLectureSeuleEtGardeParLeDroit(): void
    {
        $src = self::lire('src/Controllers/LogsController.php');
        $this->assertSame(2, substr_count($src, "AuthMiddleware::requirePermission('diagnostics.view');"));
        $file = self::lire('src/Logs/LogFile.php');
        foreach (['file_put_contents', 'unlink(', 'ftruncate', 'rename(', 'fwrite('] as $write) {
            $this->assertStringNotContainsString($write, $src . $file, "L'écran ne modifie jamais le journal ($write).");
        }
        $this->assertStringContainsString("fopen(\$path, 'rb')", $file, 'Le journal n\'est ouvert qu\'en lecture.');
        $this->assertDoesNotMatchRegularExpression('/(?<![\w])file\(\$/', $file, 'Plus de lecture du fichier entier.');
    }

    public function testGabaritFeuilleEtScript(): void
    {
        $tpl = self::lire('templates/admin/logs.php');
        $this->assertStringContainsString('/assets/css/logs.css?v=', $tpl);
        $this->assertStringContainsString('/assets/js/admin-logs.js?v=', $tpl);
        $this->assertStringContainsString("<h1><?= te('Journaux') ?></h1>", $tpl);
        $this->assertStringContainsString("require ROOT . '/templates/admin/logs/entries.php'", $tpl);
        $this->assertStringContainsString('data-cookie="<?= $h(LogsController::TZ_COOKIE) ?>"', $tpl);

        $js = self::lire('public/assets/js/admin-logs.js');
        $this->assertStringContainsString('root.dataset.cookie', $js, 'Le nom du cookie vient du serveur.');
        $this->assertStringContainsString('/admin/logs/feed?', $js);
        $this->assertStringContainsString('miaUiZoom', $js);
        $this->assertStringContainsString('document.hidden', $js, 'Le suivi en direct se met en pause onglet masqué.');

        $css = self::lire('public/assets/css/logs.css');
        $this->assertStringContainsString('[data-theme="light"] .lgx', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringNotContainsString('lg-entry', self::lire('public/assets/css/main.css'), 'Les règles de l\'ancien écran ont quitté main.css.');
        $this->assertStringContainsString('.lg-stat {', self::lire('public/assets/css/main.css'), 'Agent et Élagage s\'en servent encore.');
    }

    public function testDocumentation(): void
    {
        $doc = self::lire('docs/user/admin/instance/consulter-les-logs-et-le-diagnostic.md');
        foreach (['Problèmes', 'Flux', 'En direct', 'incident'] as $mot) {
            $this->assertStringContainsString($mot, $doc);
        }
        $this->assertStringContainsString('Problèmes', self::lire('docs/GUIDE-ADMIN.md'));
    }
}
