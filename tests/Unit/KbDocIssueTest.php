<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fiche d'un document d'une base : pourquoi il n'est pas interrogeable, si un
 * nouvel essai vaut la peine (et lequel), ou s'il faut l'exclure — KbDocIssue,
 * les états « Interrompu » et « Exclu » de KbStatus, et le câblage du volet
 * (routes, gardes, module partagé, gabarits).
 *
 * Le problème résolu : « Illisible », « Non vectorisé » et un message
 * technique tronqué ne disaient ni la cause, ni s'il fallait recommencer, ni
 * quoi faire ; un document dont le processus était mort restait « En
 * attente » pour toujours ; et un fichier qui ne passera jamais (scan, mot de
 * passe) restait un problème de la base, sans moyen de l'écarter.
 */
final class KbDocIssueTest extends TestCase
{
    private static function doc(array $over = []): array
    {
        return $over + [
            'id' => 5, 'kb_id' => 3, 'file_name' => 'Rapport.pdf', 'mime_type' => 'application/pdf',
            'extraction_status' => 'done', 'extraction_error' => null,
            'vector_status' => 'done', 'vector_error' => null,
            'chunk_count' => 4, 'token_count' => 900, 'source' => 'upload', 'age_s' => 3600,
        ];
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    // ------------------------------------------------------------------
    // Verdict
    // ------------------------------------------------------------------

    public function testUnDocumentIndexeOuEnCoursNePosePasDeProbleme(): void
    {
        $this->assertNull(KbDocIssue::of(self::doc()));
        $this->assertNull(KbDocIssue::of(self::doc(['extraction_status' => 'processing', 'vector_status' => 'pending'])));
        $this->assertNull(KbDocIssue::of(self::doc(['vector_status' => 'processing'])));
        // Un ancien format lu et indexé reste bon.
        $this->assertNull(KbDocIssue::of(self::doc(['mime_type' => 'application/x-ancien'])));
    }

    public function testUneLectureEchoueeSeReessaieEnEntier(): void
    {
        $i = KbDocIssue::of(self::doc(['extraction_status' => 'failed', 'extraction_error' => 'Unable to parse xref table', 'vector_status' => 'failed', 'vector_error' => 'Extraction non aboutie.', 'chunk_count' => 0]));
        $this->assertSame(KbDocIssue::KIND_EXTRACTION, $i['kind']);
        $this->assertSame(KbDocIssue::RETRY_FULL, $i['retry']);
        $this->assertTrue($i['exclude']);
        // Le message dérivé « Extraction non aboutie. » n'apprend rien : il n'est pas montré.
        $this->assertSame(['Unable to parse xref table'], $i['technical']);
    }

    public function testUnFichierDisparuNeSeReessaieQuePourUnDossierSynchronise(): void
    {
        $doc = self::doc(['extraction_status' => 'failed', 'extraction_error' => 'Fichier introuvable sur le stockage.', 'chunk_count' => 0]);
        $upload = KbDocIssue::of($doc);
        $this->assertSame(KbDocIssue::KIND_MISSING, $upload['kind']);
        $this->assertNull($upload['retry'], 'Un fichier déposé disparu ne se relit pas : on le redépose.');
        $synced = KbDocIssue::of($doc, ['synced' => true]);
        $this->assertSame(KbDocIssue::RETRY_SYNC, $synced['retry'], 'Synchronisé : la synchronisation le retélécharge.');
    }

    public function testUneImageSansModeleDeVisionAttendUneCle(): void
    {
        $doc = self::doc(['mime_type' => 'image/png', 'extraction_status' => 'failed', 'extraction_error' => 'Aucun LLM vision disponible pour décrire les images. Configurez une clé API…']);
        $this->assertNull(KbDocIssue::of($doc, ['vision_ready' => false])['retry']);
        $this->assertSame(KbDocIssue::RETRY_FULL, KbDocIssue::of($doc, ['vision_ready' => true])['retry']);
        $panne = KbDocIssue::of(self::doc(['mime_type' => 'image/png', 'extraction_status' => 'failed', 'extraction_error' => 'Description vision impossible : overloaded']));
        $this->assertSame(KbDocIssue::KIND_VISION, $panne['kind']);
        $this->assertSame(KbDocIssue::RETRY_FULL, $panne['retry']);
    }

    public function testUneVectorisationEchoueeNeRefaitQueLaVectorisation(): void
    {
        $doc = self::doc(['vector_status' => 'failed', 'vector_error' => 'Erreur API embeddings openai (HTTP 429).', 'chunk_count' => 6]);
        $i = KbDocIssue::of($doc, ['embedding_provider' => 'openai']);
        $this->assertSame(KbDocIssue::KIND_EMBED_API, $i['kind']);
        $this->assertSame(KbDocIssue::RETRY_VECTOR, $i['retry'], 'Les extraits sont là : le découpage n’est pas repayé.');
        $this->assertStringContainsString('OpenAI', $i['why']);
        $this->assertStringContainsString('mots-clés', $i['why'], 'Ses extraits restent trouvables par mots-clés en attendant.');

        $cle = KbDocIssue::of(self::doc(['vector_status' => 'failed', 'vector_error' => 'Clé API mistral manquante pour les embeddings.']), ['embedding_provider' => 'mistral', 'embedding_ready' => false]);
        $this->assertSame(KbDocIssue::KIND_EMBED_KEY, $cle['kind']);
        $this->assertNull($cle['retry'], 'Sans clé, un nouvel essai échouerait pareil.');
        $this->assertSame(KbDocIssue::RETRY_VECTOR, KbDocIssue::of(self::doc(['vector_status' => 'failed', 'vector_error' => 'Clé API mistral manquante pour les embeddings.']), ['embedding_ready' => true])['retry']);

        $qdrant = self::doc(['vector_status' => 'failed', 'vector_error' => 'Qdrant injoignable (http://qdrant:6333).']);
        $this->assertSame(KbDocIssue::KIND_VECTOR_STORE, KbDocIssue::of($qdrant)['kind']);
        $this->assertNull(KbDocIssue::of($qdrant, ['vector_up' => false])['retry']);
        $this->assertSame(KbDocIssue::RETRY_VECTOR, KbDocIssue::of($qdrant, ['vector_up' => true])['retry']);
    }

    public function testUnDocumentSansTexteNeSeReessaiePasMaisSExclut(): void
    {
        $doc = self::doc(['chunk_count' => 0, 'token_count' => 0, 'extraction_error' => 'Aucun texte extrait.']);
        foreach ([
            KbDocIssue::FILE_SCANNED   => KbDocIssue::KIND_SCANNED,
            KbDocIssue::FILE_PROTECTED => KbDocIssue::KIND_PROTECTED,
            KbDocIssue::FILE_EMPTY     => KbDocIssue::KIND_EMPTY,
            ''                         => KbDocIssue::KIND_NO_TEXT,
        ] as $file => $kind) {
            $i = KbDocIssue::of($doc, ['file' => $file]);
            $this->assertSame($kind, $i['kind'], "Fichier « $file »");
            $this->assertNull($i['retry'], 'C’est le fichier qu’il faut corriger : un nouvel essai ne changerait rien.');
            $this->assertTrue($i['exclude']);
            $this->assertSame('warn', $i['tone']);
            $this->assertSame([], $i['technical'], '« Aucun texte extrait. » est un constat, pas un détail technique.');
        }
        // Un format plus lu.
        $vieux = KbDocIssue::of(self::doc(['mime_type' => 'application/x-ancien', 'chunk_count' => 0]));
        $this->assertSame(KbDocIssue::KIND_UNSUPPORTED, $vieux['kind']);
        $this->assertNull($vieux['retry']);
    }

    public function testLeConseilDitOuCorrigerSelonLaProvenance(): void
    {
        $doc = self::doc(['chunk_count' => 0]);
        $this->assertStringContainsString('déposez-le de nouveau', KbDocIssue::of($doc, ['file' => KbDocIssue::FILE_SCANNED])['todo']);
        $this->assertStringContainsString('dossier synchronisé', KbDocIssue::of($doc, ['file' => KbDocIssue::FILE_SCANNED, 'synced' => true])['todo']);
    }

    // ------------------------------------------------------------------
    // Interrompu
    // ------------------------------------------------------------------

    public function testUnTraitementSansRienQuiTourneEstInterrompu(): void
    {
        $doc  = self::doc(['extraction_status' => 'processing', 'vector_status' => 'pending', 'chunk_count' => 0]);
        $none = ['base' => false, 'docs' => []];
        $this->assertTrue(KbDocIssue::isStalled($doc, $none));
        $this->assertFalse(KbDocIssue::isStalled($doc, ['base' => false, 'docs' => [5 => true]]), 'Un job vivant le traite.');
        $this->assertFalse(KbDocIssue::isStalled($doc, ['base' => true, 'docs' => []]), 'Une synchronisation ou une réindexation le traite.');
        $this->assertFalse(KbDocIssue::isStalled(['age_s' => 30] + $doc, $none), 'Tout juste déposé : son job part.');
        $this->assertFalse(KbDocIssue::isStalled(self::doc(), $none), 'Indexé : rien à attendre.');
        $this->assertFalse(KbDocIssue::isStalled(['excluded_at' => '2026-11-19 10:00:00'] + $doc, $none));

        $marked = KbDocIssue::markStalled([$doc, self::doc(['id' => 6])], $none);
        $this->assertSame([1, 0], array_column($marked, 'stalled'));

        $lecture = KbDocIssue::of(['stalled' => 1] + $doc);
        $this->assertSame(KbDocIssue::KIND_STALLED, $lecture['kind']);
        $this->assertSame(KbDocIssue::RETRY_FULL, $lecture['retry']);
        $vecteurs = KbDocIssue::of(['stalled' => 1] + self::doc(['vector_status' => 'processing']));
        $this->assertSame(KbDocIssue::RETRY_VECTOR, $vecteurs['retry'], 'Texte lu : seule la vectorisation est refaite.');

        $st = KbStatus::documentState(['stalled' => 1] + $doc);
        $this->assertSame([KbStatus::DOC_STALLED, KbStatus::TONE_ERROR], [$st['key'], $st['tone']]);
    }

    public function testUnDocumentExcluNestPlusUnProbleme(): void
    {
        $doc = ['excluded_at' => '2026-11-19 10:00:00', 'excluded_reason' => 'Document numérisé'] + self::doc(['chunk_count' => 0]);
        $st  = KbStatus::documentState($doc);
        $this->assertSame([KbStatus::DOC_EXCLUDED, KbStatus::TONE_IDLE], [$st['key'], $st['tone']]);
        $this->assertStringContainsString('Document numérisé', $st['detail']);
        $c = KbStatus::countDocuments([$doc, self::doc(), ['stalled' => 1] + self::doc(['extraction_status' => 'pending', 'vector_status' => 'pending', 'chunk_count' => 0])]);
        $this->assertSame(1, $c['excluded']);
        $this->assertSame(1, $c['failed'], 'Interrompu = un échec, qui demande un geste.');
        $this->assertSame(0, $c['no_text']);
        $this->assertSame(1, $c['indexed']);
        // Le verdict d'un exclu redit pourquoi il posait problème.
        $this->assertSame(KbDocIssue::KIND_NO_TEXT, KbDocIssue::of($doc)['kind']);
        // Une base dont le seul souci a été exclu est « Prête ».
        $base = ['id' => 1, 'sync_provider' => null, 'sync_folder_ref' => null, 'sync_enabled' => 0, 'sync_status' => null, 'sync_report' => null, 'activity_json' => null, 'is_system' => 0];
        $sum  = KbStatus::summarize($base, KbStatus::countDocuments([$doc, self::doc()]), ['sync_running' => false, 'sync_queued' => false, 'reindex_active' => false, 'last_age' => null, 'interval_min' => 10, 'now' => time()]);
        $this->assertSame(KbStatus::OK, $sum['state']);
        $this->assertStringContainsString('1 exclu', $sum['detail']);
    }

    // ------------------------------------------------------------------
    // Étapes
    // ------------------------------------------------------------------

    public function testLesTroisEtapesDisentOuEnEstLeDocument(): void
    {
        $tones = static fn(array $doc): array => array_column(KbDocIssue::steps($doc), 'tone');
        $this->assertSame(['ok', 'ok', 'ok'], $tones(self::doc()));
        $this->assertSame(['ok', 'ok', 'error'], $tones(self::doc(['vector_status' => 'failed'])));
        $this->assertSame(['error', 'idle', 'idle'], $tones(self::doc(['extraction_status' => 'failed', 'chunk_count' => 0])));
        $this->assertSame(['warn', 'warn', 'idle'], $tones(self::doc(['chunk_count' => 0, 'token_count' => 0])));
        $this->assertSame(['busy', 'idle', 'idle'], $tones(self::doc(['extraction_status' => 'processing', 'vector_status' => 'pending', 'chunk_count' => 0])));
        $this->assertSame(['error', 'idle', 'idle'], $tones(['stalled' => 1] + self::doc(['extraction_status' => 'processing', 'vector_status' => 'pending', 'chunk_count' => 0])));
        $this->assertSame(['read', 'split', 'vector'], array_column(KbDocIssue::steps(self::doc()), 'key'));
        // Des extraits sans compte de tokens : le texte a bien été lu.
        $this->assertSame('ok', KbDocIssue::steps(self::doc(['token_count' => 0]))[0]['tone']);
        $this->assertStringContainsString('image', KbDocIssue::steps(self::doc(['mime_type' => 'image/jpeg']))[0]['label']);
    }

    // ------------------------------------------------------------------
    // Le fichier stocké
    // ------------------------------------------------------------------

    public function testLeFichierStockeDitSIlEstVideProtegeOuNumerise(): void
    {
        $dir = sys_get_temp_dir() . '/kbdoc-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $write = static function (string $name, string $bytes) use ($dir): string {
                file_put_contents($dir . '/' . $name, $bytes);
                return $dir . '/' . $name;
            };
            $this->assertSame(KbDocIssue::FILE_MISSING, KbDocIssue::inspectFile($dir . '/absent.pdf', 'application/pdf'));
            $this->assertSame(KbDocIssue::FILE_EMPTY, KbDocIssue::inspectFile($write('vide.txt', ''), 'text/plain'));
            $this->assertSame(KbDocIssue::FILE_EMPTY, KbDocIssue::inspectFile($write('blanc.txt', "  \n\n "), 'text/plain'));
            $this->assertSame(KbDocIssue::FILE_PROTECTED, KbDocIssue::inspectFile(
                $write('chiffre.pdf', "%PDF-1.6\n1 0 obj <</Type/Catalog>> endobj\ntrailer <</Root 1 0 R /Encrypt 5 0 R>>\n%%EOF"), 'application/pdf'));
            $this->assertSame(KbDocIssue::FILE_SCANNED, KbDocIssue::inspectFile(
                $write('scan.pdf', "%PDF-1.4\n4 0 obj <</Type/XObject /Subtype /Image /Width 2480 /Height 3508>> stream\n...\nendstream\n%%EOF"), 'application/pdf'));
            $this->assertNull(KbDocIssue::inspectFile(
                $write('texte.pdf', "%PDF-1.4\n5 0 obj <</Type/Font /Subtype/Type1 /BaseFont/Helvetica>> endobj\n6 0 obj <</Subtype /Image>> endobj\n%%EOF"), 'application/pdf'));
            // Un .docx protégé n'est plus une archive ZIP mais un conteneur OLE chiffré.
            $this->assertSame(KbDocIssue::FILE_PROTECTED, KbDocIssue::inspectFile(
                $write('secret.docx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64)),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
            $this->assertNull(KbDocIssue::inspectFile($write('vrai.docx', "PK\x03\x04" . str_repeat('x', 40)),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLesRoutesEtLesGardes(): void
    {
        $index = self::src('public/index.php');
        foreach ([
            "\$router->get('/admin/knowledge-bases/{id}/documents/{docId}',",
            "\$router->post('/admin/knowledge-bases/{id}/documents/{docId}/retry',",
            "\$router->post('/admin/knowledge-bases/{id}/documents/{docId}/exclude',",
            "\$router->post('/admin/knowledge-bases/{id}/documents/{docId}/restore',",
        ] as $route) {
            $this->assertStringContainsString($route, $index);
        }
        $ctrl = self::src('src/Controllers/AdminController.php');
        $this->assertSame(1, preg_match('/public function knowledgeBaseDocument\(string \$id, string \$docId\): void\s*\{(.*?)\n    \}/s', $ctrl, $m));
        $this->assertStringContainsString('KnowledgeBase::visibleBy($kbId, $user)', $m[1], 'Lire la fiche : qui voit la base.');
        $this->assertStringContainsString('session_write_close();', $m[1]);
        $this->assertSame(1, preg_match('/private function kbDocumentForAction\(string \$id, string \$docId\): array\s*\{(.*?)\n    \}/s', $ctrl, $g));
        $this->assertStringContainsString("AuthMiddleware::requirePermission('knowledge_bases.edit');", $g[1], 'Agir : qui gère la base.');
        $this->assertStringContainsString('KnowledgeBase::manageableBy($kbId, Session::currentUser())', $g[1]);
        $this->assertStringContainsString("(int)(\$kb['is_system'] ?? 0) === 1", $g[1], 'La base système reste verrouillée.');
        $this->assertStringContainsString('activeWork(', $g[1], 'Jamais pendant qu’un traitement tourne.');
        // Exclure : points Qdrant purgés AVANT les lignes (leurs ids partent avec).
        $excl = substr($ctrl, (int)strpos($ctrl, 'public function excludeKnowledgeBaseDocument'));
        $this->assertLessThan(strpos($excl, 'KnowledgeBaseDocument::exclude('), strpos($excl, 'deletePointsByDocument('));
        // Les trois phases d'un nouvel essai.
        $this->assertStringContainsString("JobQueue::enqueueMaintenance('kb_vectorize'", $ctrl);
        $this->assertStringContainsString("case 'kb_vectorize':", self::src('src/Jobs/MaintenanceJob.php'));
    }

    public function testLaSynchronisationNeReprendPasUnExcluSaufSIlChange(): void
    {
        $model = self::src('src/Models/KnowledgeBaseDocument.php');
        $this->assertSame(1, preg_match('/public static function updateSyncedVersion\(.*?\n    \}/s', $model, $m));
        $this->assertStringContainsString('excluded_at = NULL', $m[0], 'Modifié à la source : il est réessayé.');
        $this->assertStringContainsString("if (!empty(\$doc['excluded_at'])) {", self::src('src/Models/KnowledgeBase.php'), 'Une réindexation saute les exclus.');
        $this->assertStringContainsString('self::setChunkCount($id, $chunkCount);', $model, 'Un document non vectorisé montre ses extraits.');
    }

    public function testLeVoletEstPartageEtSansLibelle(): void
    {
        foreach (['templates/admin/knowledge-bases/show.php', 'templates/admin/knowledge-bases/edit.php'] as $page) {
            $tpl = self::src($page);
            $this->assertStringContainsString("partials/doc-drawer.php'", $tpl, $page);
            $this->assertStringContainsString('kb-doc.js', $tpl, $page);
        }
        $js = self::src('public/assets/js/kb-doc.js');
        $this->assertStringContainsString("'/admin/knowledge-bases/' + o.kbId + '/documents/' + docId", $js);
        $this->assertStringContainsString("e.target.closest('[data-doc-act]')", $js, 'Les gestes viennent de la fiche rendue.');
        $this->assertStringContainsString('window.confirm(ask)', $js);
        // Aucun texte français codé en dur hors t()/tn() : les messages viennent du serveur.
        $this->assertDoesNotMatchRegularExpression("/textContent = '[A-ZÀ-Ý]/u", $js);
        $show = self::src('public/assets/js/kb-show.js');
        $this->assertStringContainsString('window.MiaKbDoc.drawer({', $show);
        $this->assertStringContainsString("'/sync-status' + (list ? '?rows=1' : '')", $show);
        $this->assertStringContainsString("'rows'         => !empty(\$_GET['rows']) ? \$this->kbShowRows(\$docs) : null,", self::src('src/Controllers/AdminController.php'));
        $detail = self::src('templates/admin/knowledge-bases/partials/doc-detail.php');
        foreach (['data-doc-act="retry"', 'data-doc-act="exclude"', 'data-doc-act="restore"', 'data-doc-act="delete"', 'class="kbd-tech"'] as $needle) {
            $this->assertStringContainsString($needle, $detail);
        }
        $this->assertStringContainsString('<?php if ($canManage && $dTech !== []): ?>', $detail, 'Le détail technique : à qui gère la base.');
    }

    public function testLHeureDeLaDerniereIndexationSAfficheDansLeFuseauDuNavigateur(): void
    {
        // Le serveur tourne en UTC : il pose l'instant, le navigateur l'écrit dans SON heure.
        $ts  = gmmktime(13, 32, 0, 11, 19, 2026);
        $tag = KbPages::timeTag($ts);
        $this->assertStringContainsString('<time datetime="2026-11-19T13:32:00Z" data-local>', $tag);
        $this->assertStringContainsString('19/11/2026 13:32 UTC', $tag, 'Sans script, l’heure reste lisible, dite en UTC.');
        $this->assertSame('', KbPages::timeTag(null));
        $this->assertSame('', KbPages::timeTag(0));
        // La phrase est échappée AVANT d'y poser la balise.
        $html = KbPages::withTime('indexé le {date} <b>', $ts);
        $this->assertStringStartsWith('indexé le <time ', $html);
        $this->assertStringEndsWith('&lt;b&gt;', $html);
        $this->assertSame('', KbPages::withTime('indexé le {date}', null), 'Jamais indexé : rien dans la ligne.');

        $model = self::src('src/Models/KnowledgeBaseDocument.php');
        $this->assertStringContainsString("\$stamp = \$status === 'done' ? ', indexed_at = NOW()' : '';", $model, 'Posée à chaque vectorisation menée au bout.');
        $this->assertStringContainsString('UNIX_TIMESTAMP(d.indexed_at) AS indexed_ts', $model, 'Un instant, quel que soit le fuseau de MySQL.');
        $this->assertStringContainsString("KbPages::withTime(t('indexé le {date}')", self::src('templates/admin/knowledge-bases/partials/show-doc-row.php'));
        $this->assertStringContainsString("KbPages::withTime(t('Dernière indexation : {date}')", self::src('templates/admin/knowledge-bases/partials/doc-detail.php'));
        $js = self::src('public/assets/js/kb-doc.js');
        $this->assertStringContainsString("querySelectorAll('time[data-local][datetime]')", $js);
        $this->assertStringContainsString('window.MiaKbDoc = { chunkViewer, drawer, formatTime, localTimes };', $js);
        $this->assertStringContainsString("t('indexé le {date}', { date: when })", self::src('public/assets/js/kb-form.js'));
        $this->assertStringContainsString("'indexed_at'        => !empty(\$doc['indexed_ts'])", self::src('src/Controllers/AdminController.php'));
    }

    public function testLaMigrationEtLeChargement(): void
    {
        $sql = self::src('database/migrations/115_kb_document_exclusion.sql');
        foreach (['excluded_at', 'excluded_by', 'excluded_reason', 'indexed_at'] as $col) {
            $this->assertStringContainsString('ADD COLUMN ' . $col, $sql);
        }
        $this->assertStringContainsString("UPDATE knowledge_base_documents SET indexed_at = created_at WHERE vector_status = 'done';", $sql, 'Les documents déjà indexés reprennent leur date de dépôt.');
        $this->assertStringContainsString("require ROOT . '/src/Sync/KbDocIssue.php';", self::src('config/classes.php'));
        $this->assertStringContainsString("'/src/Sync/KbDocIssue.php',", self::src('tests/bootstrap.php'));
    }
}
