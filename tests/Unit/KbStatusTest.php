<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * État d'une base de connaissances tel qu'on le montre (KbStatus + partiel
 * kb-status.php) et son rafraîchissement en direct.
 *
 * Le problème résolu : l'écran ne disait pas si les sources étaient dans la
 * base vectorisée. Une synchronisation en attente était invisible, un passage
 * en cours se réduisait à une ligne grise, un document en échec ne donnait
 * pas son motif, et rien ne se rafraîchissait — la modale arrêtait même son
 * suivi avant que la synchronisation demandée n'ait démarré.
 */
final class KbStatusTest extends TestCase
{
    private const NOW = 1_800_000_000;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private static function kb(array $over = []): array
    {
        return $over + [
            'id' => 7, 'name' => 'Base', 'is_system' => 0, 'embedding_model' => 'text-embedding-3-large',
            'doc_count' => 0, 'chunk_count' => 0, 'total_size' => 0, 'query_count' => 0, 'last_used_at' => null,
            'sync_provider' => null, 'sync_folder_ref' => null, 'sync_folder_name' => null, 'sync_enabled' => 0,
            'sync_status' => null, 'sync_error' => null, 'sync_report' => null, 'activity_json' => null,
        ];
    }

    private static function drive(array $over = []): array
    {
        return self::kb($over + [
            'sync_provider' => 'drive', 'sync_folder_ref' => '1AbCdEfGhIjKl', 'sync_folder_name' => 'Dossier',
            'sync_enabled' => 1, 'sync_status' => 'ok',
        ]);
    }

    private static function comptes(array $over = []): array
    {
        return $over + ['total' => 0, 'indexed' => 0, 'no_text' => 0, 'failed' => 0, 'lexical' => 0, 'busy' => 0, 'pending' => 0];
    }

    private static function vivant(array $over = []): array
    {
        return $over + ['interval_min' => 10, 'now' => self::NOW, 'last_age' => 120];
    }

    private static function rapport(array $over = []): string
    {
        return (string)json_encode($over + [
            'listed' => 3, 'selected' => 3, 'added' => 0, 'updated' => 0, 'renamed' => 0, 'deleted' => 0, 'failed' => 0,
            'ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0, 'issues' => [],
            'finished' => self::NOW - 240,
        ]);
    }

    private static function activite(array $a): string
    {
        return (string)json_encode($a + ['kind' => 'sync', 'started' => self::NOW - 30]);
    }

    private static function rendre(array $kb, array $st, string $variant, bool $canManage = true, string $owner = ''): string
    {
        return (static function (array $kb, array $kbStatus, string $kbStatusVariant, bool $kbCanManage, string $kbOwnerName): string {
            ob_start();
            require ROOT . '/templates/partials/kb-status.php';
            return (string)ob_get_clean();
        })($kb, $st, $variant, $canManage, $owner);
    }

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function testUnDocumentIndexeEstInterrogeable(): void
    {
        $st = KbStatus::documentState(['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 12]);
        $this->assertSame(KbStatus::DOC_INDEXED, $st['key']);
        $this->assertSame(KbStatus::TONE_OK, $st['tone']);
        $this->assertSame('Indexé', $st['label']);
        $this->assertSame('12 extraits interrogeables', $st['detail']);
        $this->assertSame('1 extrait interrogeable', KbStatus::documentState(['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 1])['detail']);
    }

    /** Le piège : traité, « vectorisé », et pourtant introuvable. */
    public function testUnDocumentSansTexteNEstPasPrisPourIndexe(): void
    {
        $st = KbStatus::documentState([
            'extraction_status' => 'done', 'extraction_error' => 'Aucun texte extrait.',
            'vector_status' => 'done', 'chunk_count' => 0,
        ]);
        $this->assertSame(KbStatus::DOC_NO_TEXT, $st['key']);
        $this->assertSame(KbStatus::TONE_WARN, $st['tone']);
        $this->assertStringContainsString('ne ressortira d\'aucune recherche', $st['detail']);
    }

    public function testUnEchecDeLectureDonneSonMotif(): void
    {
        $st = KbStatus::documentState([
            'extraction_status' => 'failed', 'extraction_error' => "PDF   endommagé :\n impossible de le lire.",
            'vector_status' => 'failed', 'vector_error' => 'Extraction non aboutie.',
        ]);
        $this->assertSame(KbStatus::DOC_FAILED, $st['key']);
        $this->assertSame(KbStatus::TONE_ERROR, $st['tone']);
        $this->assertSame('PDF endommagé : impossible de le lire.', $st['detail'], 'Le motif tient sur une ligne.');

        $sansMotif = KbStatus::documentState(['extraction_status' => 'failed', 'extraction_error' => '  ']);
        $this->assertSame('Le texte du fichier n\'a pas pu être lu.', $sansMotif['detail']);
    }

    public function testUnEchecDeVectorisationResteConsultableParMots(): void
    {
        $st = KbStatus::documentState([
            'extraction_status' => 'done', 'vector_status' => 'failed', 'chunk_count' => 7,
            'vector_error' => 'Clé API OpenAI refusée (401).',
        ]);
        $this->assertSame(KbStatus::DOC_LEXICAL, $st['key']);
        $this->assertSame(KbStatus::TONE_ERROR, $st['tone']);
        $this->assertStringContainsString('mots-clés', $st['detail']);
        $this->assertStringContainsString('Clé API OpenAI refusée (401).', $st['detail']);
    }

    public function testLesDocumentsEnCoursSontDistinguesDeCeuxEnAttente(): void
    {
        $this->assertSame(KbStatus::DOC_PENDING, KbStatus::documentState(['extraction_status' => 'pending'])['key']);
        $this->assertSame(KbStatus::DOC_EXTRACTING, KbStatus::documentState(['extraction_status' => 'processing'])['key']);
        $this->assertSame(KbStatus::DOC_INDEXING, KbStatus::documentState(['extraction_status' => 'done', 'vector_status' => 'processing'])['key']);
        $this->assertSame(KbStatus::DOC_INDEXING, KbStatus::documentState(['extraction_status' => 'done', 'vector_status' => 'pending'])['key']);
    }

    public function testUnMotifTropLongEstBorne(): void
    {
        $st = KbStatus::documentState(['extraction_status' => 'failed', 'extraction_error' => str_repeat('x', 600)]);
        $this->assertLessThanOrEqual(220, mb_strlen($st['detail']));
        $this->assertStringEndsWith('…', $st['detail']);
    }

    /** Groupes (forBases) ou lignes (liste) : une seule règle, les mêmes comptes. */
    public function testLesComptesPassentParLaMemeRegleQueLaListe(): void
    {
        $c = KbStatus::countDocuments([
            ['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 1, 'n' => 22],
            ['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 0, 'n' => 1],
            ['extraction_status' => 'failed', 'vector_status' => 'failed', 'chunk_count' => 0, 'n' => 1],
            ['extraction_status' => 'done', 'vector_status' => 'failed', 'chunk_count' => 1, 'n' => 1],
            ['extraction_status' => 'processing', 'vector_status' => 'pending', 'chunk_count' => 0],
            ['extraction_status' => 'pending', 'vector_status' => 'pending', 'chunk_count' => 0, 'n' => 2],
        ]);
        $this->assertSame(
            ['total' => 28, 'indexed' => 22, 'no_text' => 1, 'failed' => 1, 'lexical' => 1, 'busy' => 1, 'pending' => 2],
            $c
        );
    }

    // ------------------------------------------------------------------
    // Base : priorités d'affichage
    // ------------------------------------------------------------------

    public function testUnPassageVivantMontreSaProgression(): void
    {
        $st = KbStatus::summarize(
            self::drive(['sync_status' => 'syncing', 'activity_json' => self::activite(['phase' => 'files', 'done' => 12, 'total' => 40, 'current' => 'Grille tarifaire.xlsx'])]),
            self::comptes(['total' => 12, 'indexed' => 12]),
            self::vivant(['sync_running' => true])
        );
        $this->assertSame(KbStatus::SYNCING, $st['state']);
        $this->assertTrue($st['busy']);
        $this->assertSame('12 / 40', $st['meta']);
        $this->assertSame(30, $st['progress']['pct']);
        $this->assertSame('« Grille tarifaire.xlsx »', $st['detail']);
    }

    public function testUnPassageSansTotalConnuEstIndetermine(): void
    {
        $demarrage = KbStatus::summarize(self::drive(), self::comptes(), self::vivant(['sync_running' => true]));
        $this->assertTrue($demarrage['progress']['indeterminate']);
        $this->assertSame('Démarrage…', $demarrage['detail']);

        $lecture = KbStatus::summarize(
            self::drive(['activity_json' => self::activite(['phase' => 'listing'])]),
            self::comptes(),
            self::vivant(['sync_running' => true])
        );
        $this->assertTrue($lecture['progress']['indeterminate']);
        $this->assertSame('Lecture du dossier…', $lecture['detail']);
    }

    /** Le verrou dit la vérité : un état « en échec » d'hier ne masque pas le passage d'aujourd'hui. */
    public function testLeVerrouPrimeSurLEtatEnregistre(): void
    {
        foreach (['error', 'syncing', 'ok'] as $status) {
            $st = KbStatus::summarize(self::drive(['sync_status' => $status]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['sync_running' => true]));
            $this->assertSame(KbStatus::SYNCING, $st['state'], "Statut enregistré « $status » + verrou tenu.");
        }
    }

    /** L'oubli de l'ancien écran : une synchronisation demandée mais pas démarrée. */
    public function testUneSynchronisationEnAttenteEstVisibleEtSuivie(): void
    {
        $st = KbStatus::summarize(self::drive(['sync_status' => 'error', 'sync_error' => 'x']), self::comptes(['total' => 1, 'indexed' => 1]), self::vivant(['sync_queued' => true]));
        $this->assertSame(KbStatus::QUEUED, $st['state']);
        $this->assertSame('Synchronisation en attente', $st['title']);
        $this->assertTrue($st['busy'], 'En attente, l\'écran doit continuer à se rafraîchir.');
    }

    public function testUneReindexationSuitSaProgression(): void
    {
        $st = KbStatus::summarize(
            self::kb(['activity_json' => (string)json_encode(['kind' => 'reindex', 'phase' => 'files', 'done' => 3, 'total' => 12, 'current' => 'Guide.pdf'])]),
            self::comptes(['total' => 12, 'indexed' => 12]),
            self::vivant(['reindex_active' => true])
        );
        $this->assertSame(KbStatus::REINDEXING, $st['state']);
        $this->assertSame('3 / 12', $st['meta']);
        $this->assertSame('« Guide.pdf »', $st['detail']);

        $enFile = KbStatus::summarize(self::kb(), self::comptes(['total' => 2, 'indexed' => 2]), self::vivant(['reindex_active' => true]));
        $this->assertSame('Elle démarre dès que le serveur est disponible.', $enFile['detail']);
    }

    public function testDesDocumentsEnLectureDonnentUneIndexation(): void
    {
        $st = KbStatus::summarize(self::kb(), self::comptes(['total' => 5, 'indexed' => 3, 'busy' => 1, 'pending' => 1]), self::vivant());
        $this->assertSame(KbStatus::INDEXING, $st['state']);
        $this->assertSame('3 / 5', $st['meta']);
        $this->assertSame(60, $st['progress']['pct']);
        $this->assertTrue($st['busy']);
    }

    /** Processus tué au milieu : l'état dit « syncing » pour toujours, sans verrou ni job. */
    public function testUnPassageTueEstDitInterrompu(): void
    {
        $st = KbStatus::summarize(
            self::drive(['sync_status' => 'syncing', 'activity_json' => self::activite(['phase' => 'files', 'done' => 27, 'total' => 64])]),
            self::comptes(['total' => 27, 'indexed' => 27]),
            self::vivant(['last_age' => 3 * 3600])
        );
        $this->assertSame(KbStatus::STALLED, $st['state']);
        $this->assertSame(KbStatus::TONE_WARN, $st['tone']);
        $this->assertStringContainsString('(27 / 64)', $st['detail']);
        $this->assertSame('il y a 3 h', $st['meta']);
        $this->assertFalse($st['busy']);
    }

    public function testUnEchecDeSynchronisationAfficheSonMotif(): void
    {
        $st = KbStatus::summarize(
            self::drive(['sync_status' => 'error', 'sync_error' => 'Le secret Azure a expiré.']),
            self::comptes(['total' => 9, 'indexed' => 9]),
            self::vivant(['last_age' => 540])
        );
        $this->assertSame(KbStatus::ERROR, $st['state']);
        $this->assertSame('Le secret Azure a expiré.', $st['detail']);
        $this->assertSame('il y a 9 min', $st['meta']);

        $sansMotif = KbStatus::summarize(self::drive(['sync_status' => 'error']), self::comptes(), self::vivant());
        $this->assertSame('Motif inconnu — voir les journaux du serveur.', $sansMotif['error']);
    }

    public function testUneSourceJamaisSynchroniseeAttendSonPremierPassage(): void
    {
        $st = KbStatus::summarize(self::drive(['sync_status' => null]), self::comptes(), self::vivant(['last_age' => null]));
        $this->assertSame(KbStatus::WAITING, $st['state']);
        $this->assertTrue($st['busy']);
    }

    public function testUneBaseVideDitPourquoi(): void
    {
        $ignores = KbStatus::summarize(self::drive(['sync_report' => self::rapport(['ignored_unsupported' => 3])]), self::comptes(), self::vivant());
        $this->assertSame(KbStatus::EMPTY, $ignores['state']);
        $this->assertSame('Aucun fichier repris', $ignores['title']);
        $this->assertStringContainsString('3 fichiers ignorés', $ignores['detail']);

        $depot = KbStatus::summarize(self::kb(), self::comptes(), self::vivant());
        $this->assertSame('Aucun document', $depot['title']);

        $coupee = KbStatus::summarize(self::drive(['sync_enabled' => 0]), self::comptes(), self::vivant());
        $this->assertSame('Synchronisation désactivée', $coupee['title']);
    }

    public function testDesDocumentsAVerifierDonnentUnEtatPartiel(): void
    {
        $echecs = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 25, 'indexed' => 22, 'failed' => 1, 'lexical' => 1, 'no_text' => 1]), self::vivant());
        $this->assertSame(KbStatus::PARTIAL, $echecs['state']);
        $this->assertSame('2 documents en échec', $echecs['title']);
        $this->assertSame('il y a 4 min', $echecs['meta']);

        $sansTexte = KbStatus::summarize(self::kb(), self::comptes(['total' => 4, 'indexed' => 3, 'no_text' => 1]), self::vivant());
        $this->assertSame('1 document sans texte', $sansTexte['title']);

        $telechargement = KbStatus::summarize(self::drive(['sync_report' => self::rapport(['failed' => 2])]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant());
        $this->assertSame('2 fichiers non téléchargés', $telechargement['title']);
    }

    /** Budget IA atteint : la pause est voulue, l'écran le dit au lieu d'annoncer un retard. */
    public function testLaPauseDuBudgetIANEstPasUnRetard(): void
    {
        $st = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['last_age' => 2 * 3600, 'auto_paused' => true]));
        $this->assertSame(KbStatus::LATE, $st['state']);
        $this->assertSame('Synchronisation suspendue', $st['title']);
        $this->assertStringContainsString('Synchroniser maintenant', $st['detail']);
        $this->assertSame('', $st['next'], 'Aucun prochain passage annoncé pendant la pause');

        $ok = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['last_age' => 20 * 60, 'auto_paused' => true]));
        $this->assertSame(KbStatus::OK, $ok['state']);
        $this->assertSame('', $ok['next']);
    }

    /** Sans worker ni fork, le déclencheur paresseux ne fait rien — et rien d'autre ne le dirait. */
    public function testDesPassagesQuiNOntPlusLieuSontSignales(): void
    {
        $st = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['last_age' => 2 * 3600]));
        $this->assertSame(KbStatus::LATE, $st['state']);
        $this->assertStringContainsString('10 minutes', $st['detail']);

        $recent = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['last_age' => 20 * 60]));
        $this->assertSame(KbStatus::OK, $recent['state'], 'Deux intervalles de retard ne sont pas encore un retard.');
    }

    public function testUneBaseSaineEstAJourOuPrete(): void
    {
        $sync = KbStatus::summarize(self::drive(['sync_report' => self::rapport()]), self::comptes(['total' => 18, 'indexed' => 18]), self::vivant());
        $this->assertSame(KbStatus::OK, $sync['state']);
        $this->assertSame('À jour', $sync['title']);
        $this->assertSame('18 documents indexés', $sync['detail']);
        $this->assertSame('il y a 4 min', $sync['meta'], 'La FIN du dernier passage, pas son début.');
        $this->assertSame('dans 8 min', $sync['next']);
        $this->assertTrue($sync['syncable']);
        $this->assertFalse($sync['busy']);

        $depot = KbStatus::summarize(self::kb(), self::comptes(['total' => 6, 'indexed' => 6]), self::vivant());
        $this->assertSame('Prête', $depot['title']);
        $this->assertFalse($depot['syncable']);

        $systeme = KbStatus::summarize(self::drive(['is_system' => 1]), self::comptes(['total' => 1, 'indexed' => 1]), self::vivant());
        $this->assertFalse($systeme['syncable']);
    }

    public function testLesDureesSontLisibles(): void
    {
        $this->assertSame('à l\'instant', KbStatus::ago(10));
        $this->assertSame('il y a 1 min', KbStatus::ago(50));
        $this->assertSame('il y a 4 min', KbStatus::ago(240));
        $this->assertSame('il y a 2 h', KbStatus::ago(2 * 3600 + 1800));
        $this->assertSame('il y a 3 j', KbStatus::ago(3 * 86400 + 5));
        $this->assertSame('imminente', KbStatus::inFuture(30));
        $this->assertSame('imminente', KbStatus::inFuture(-500));
        $this->assertSame('dans 6 min', KbStatus::inFuture(330));
        $this->assertSame('dans 2 h', KbStatus::inFuture(2 * 3600 + 60));
    }

    public function testLeBilanNeRetientQueCeQuiALieu(): void
    {
        $this->assertSame('aucun changement', KbStatus::reportLine(['added' => 0]));
        $this->assertSame(
            '3 ajoutés · 1 mis à jour · 1 renommé · 2 retirés · 1 en échec · 4 non repris',
            KbStatus::reportLine([
                'added' => 3, 'updated' => 1, 'renamed' => 1, 'deleted' => 2, 'failed' => 1,
                'ignored_unsupported' => 2, 'ignored_too_big' => 1, 'ignored_over_cap' => 1,
            ])
        );
    }

    // ------------------------------------------------------------------
    // Rendu (partiel partagé carte / panneau)
    // ------------------------------------------------------------------

    public function testLaCarteRendLaProgressionEtEchappeLesNoms(): void
    {
        $kb = self::drive(['sync_status' => 'syncing', 'activity_json' => self::activite(['phase' => 'files', 'done' => 1, 'total' => 4, 'current' => '<img src=x onerror=alert(1)>.pdf'])]);
        $html = self::rendre($kb, KbStatus::summarize($kb, self::comptes(['total' => 1, 'indexed' => 1]), self::vivant(['sync_running' => true])), 'card');

        $this->assertStringContainsString('class="kb-live" data-kb-live="7"', $html);
        $this->assertStringContainsString('kb-bar is-progress', $html);
        $this->assertStringContainsString('aria-valuenow="25"', $html);
        $this->assertStringContainsString('1 / 4', $html);
        $this->assertStringNotContainsString('<img src=x', $html, 'Un nom de fichier distant est du contenu : toujours échappé.');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.pdf', $html);
    }

    public function testLeBoutonDeRelanceSuitLesDroitsEtLEtat(): void
    {
        $kb = self::drive(['sync_status' => 'error', 'sync_error' => 'Accès refusé.']);
        $st = KbStatus::summarize($kb, self::comptes(['total' => 2, 'indexed' => 2]), self::vivant());

        $this->assertMatchesRegularExpression('/data-kb-sync-now="7"[^>]*title="Réessayer"/', self::rendre($kb, $st, 'card'));
        $this->assertStringNotContainsString('data-kb-sync-now', self::rendre($kb, $st, 'card', false), 'Lecture seule : pas de relance.');

        $enCours = KbStatus::summarize($kb, self::comptes(), self::vivant(['sync_running' => true]));
        $this->assertStringNotContainsString('data-kb-sync-now', self::rendre($kb, $enCours, 'card'), 'Pendant un passage : rien à relancer.');

        $depot = KbStatus::summarize(self::kb(), self::comptes(['total' => 1, 'indexed' => 1]), self::vivant());
        $this->assertStringNotContainsString('data-kb-sync-now', self::rendre(self::kb(), $depot, 'card'), 'Sans source : rien à synchroniser.');
    }

    /** Sous « en attente » ou « en échec », une barre verte brouillerait le message. */
    public function testLaBarreDeSanteNeParasitePasUnEchec(): void
    {
        $kb = self::drive(['sync_status' => 'error', 'sync_error' => 'x']);
        $html = self::rendre($kb, KbStatus::summarize($kb, self::comptes(['total' => 9, 'indexed' => 9]), self::vivant()), 'card');
        $this->assertStringNotContainsString('kb-bar', $html);

        $partiel = self::drive(['sync_report' => self::rapport()]);
        $html = self::rendre($partiel, KbStatus::summarize($partiel, self::comptes(['total' => 4, 'indexed' => 2, 'failed' => 1, 'no_text' => 1]), self::vivant()), 'card');
        $this->assertStringContainsString('kb-bar-seg" data-tone="ok"', $html);
        $this->assertStringContainsString('kb-bar-seg" data-tone="error"', $html);
        $this->assertStringContainsString('1 sans texte', $html);
    }

    public function testLePanneauDitCombienSontInterrogeablesEtLesFichiersNonRepris(): void
    {
        $kb = self::drive(['sync_report' => self::rapport([
            'updated' => 1, 'ignored_unsupported' => 2,
            'issues' => [['name' => 'Logo.svg', 'reason' => 'format non pris en charge'], ['name' => 'Schéma.vsdx', 'reason' => 'format non pris en charge']],
        ])]);
        $st   = KbStatus::summarize($kb, self::comptes(['total' => 25, 'indexed' => 22, 'failed' => 2, 'no_text' => 1]), self::vivant());
        $html = self::rendre($kb, $st, 'panel', true, 'Alex');

        $this->assertStringContainsString('22 / 25 interrogeables par le sens', $html);
        $this->assertStringContainsString('via la connexion de Alex', $html);
        $this->assertStringContainsString('1 mis à jour · 2 non repris', $html);
        $this->assertStringContainsString('Prochain passage', $html);
        $this->assertStringContainsString('2 fichiers de la source non repris', $html);
        $this->assertStringContainsString('Schéma.vsdx', $html);
        $this->assertStringNotContainsString('Ouvrez la base', $html, 'Dans la modale, la base est déjà ouverte.');

        $enCours = KbStatus::summarize($kb, self::comptes(['total' => 3, 'indexed' => 3]), self::vivant(['sync_running' => true]));
        $this->assertStringNotContainsString('Prochain passage', self::rendre($kb, $enCours, 'panel'), 'Pendant un passage, « le prochain » n\'a pas de sens.');
    }

    public function testLaSyntheseNAnnonceQueCeQuiDemandeAttention(): void
    {
        $rendre = static function (array $kbStatuses): string {
            ob_start();
            require ROOT . '/templates/partials/kb-status-overview.php';
            return (string)ob_get_clean();
        };
        $calme = $rendre([1 => ['busy' => false, 'tone' => 'ok'], 2 => ['busy' => false, 'tone' => 'idle']]);
        $this->assertStringNotContainsString('kb-overview-item', $calme);

        $agite = $rendre([
            1 => ['busy' => true, 'tone' => 'busy'], 2 => ['busy' => true, 'tone' => 'busy'],
            3 => ['busy' => false, 'tone' => 'error'], 4 => ['busy' => false, 'tone' => 'warn'],
        ]);
        $this->assertStringContainsString('2 en cours', $agite);
        $this->assertStringContainsString('1 en échec', $agite);
        $this->assertStringContainsString('1 à vérifier', $agite);
    }

    // ------------------------------------------------------------------
    // Moteur : progression, battement de cœur, fichiers nommés
    // ------------------------------------------------------------------

    public function testLeRapportNommeLesFichiersNonReprisEtResteBorne(): void
    {
        $report = ['ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0, 'issues' => []];
        $files  = [
            ['id' => 'a', 'name' => 'Archive.zip', 'mime' => 'application/zip', 'size' => 10, 'hash' => null, 'modified' => '', 'export' => null],
            ['id' => 'b', 'name' => 'Vide.pdf', 'mime' => 'application/pdf', 'size' => 0, 'hash' => null, 'modified' => '', 'export' => null],
            ['id' => 'c', 'name' => 'Enorme.pdf', 'mime' => 'application/pdf', 'size' => KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE + 1, 'hash' => null, 'modified' => '', 'export' => null],
        ];
        KbSync::selectSyncable($files, $report);
        $this->assertSame([
            ['name' => 'Archive.zip', 'reason' => 'format non pris en charge'],
            ['name' => 'Vide.pdf', 'reason' => 'fichier vide'],
            ['name' => 'Enorme.pdf', 'reason' => 'plus de 50 Mo'],
        ], $report['issues']);

        $report = ['ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0, 'issues' => []];
        $beaucoup = [];
        for ($i = 0; $i < 80; $i++) {
            $beaucoup[] = ['id' => 'x' . $i, 'name' => "img$i.svg", 'mime' => 'image/svg+xml', 'size' => 5, 'hash' => null, 'modified' => '', 'export' => null];
        }
        KbSync::selectSyncable($beaucoup, $report);
        $this->assertSame(80, $report['ignored_unsupported'], 'Le compteur dit le total…');
        $this->assertCount(KbSync::MAX_REPORT_ISSUES, $report['issues'], '… la liste reste bornée.');
    }

    public function testLeMoteurEcritSaProgressionEtNeLaLaissePasDerriereLui(): void
    {
        $sync = self::lire('src/Sync/KbSync.php');
        $this->assertStringContainsString("KnowledgeBase::setActivity(\$kbId, \$step + ['kind' => 'sync'", $sync);
        $this->assertStringContainsString("'phase' => 'listing'", $sync);
        $this->assertStringContainsString("'phase' => 'files'", $sync);
        $this->assertStringContainsString("\$report['finished'] = time();", $sync, 'La fin du passage date « synchronisée il y a… ».');
        $this->assertMatchesRegularExpression(
            '/finally \{\s*(?:\/\/[^\n]*\n\s*)*KnowledgeBase::setActivity\(\$kbId, null\);/',
            $sync,
            'La progression est effacée même sur une exception.'
        );

        $kb = self::lire('src/Models/KnowledgeBase.php');
        $this->assertStringContainsString("'kind'    => 'reindex'", $kb);
        $this->assertMatchesRegularExpression('/finally \{\s*self::setActivity\(\$kbId, null\);/', $kb);
    }

    /** Muet plus de 120 s, un job est rejoué : deux réindexations en parallèle, facturées deux fois. */
    public function testLesTachesLonguesBattentLeCoeur(): void
    {
        $job = self::lire('src/Jobs/MaintenanceJob.php');
        $this->assertStringContainsString('$ctx->shouldStop();', $job);
        $this->assertStringContainsString('KnowledgeBase::reindex($id, $heartbeat);', $job);
        $this->assertStringContainsString('KbSync::syncKb($id, $heartbeat);', $job);
        $this->assertStringContainsString('$heartbeat();', self::lire('src/Sync/KbSync.php'));
        $this->assertStringContainsString('$heartbeat();', self::lire('src/Models/KnowledgeBase.php'));
    }

    // ------------------------------------------------------------------
    // Écran : rafraîchissement en direct
    // ------------------------------------------------------------------

    public function testLaListeSeRafraichitSansRecharger(): void
    {
        $routes = self::lire('public/index.php');
        $this->assertMatchesRegularExpression(
            "#get\('/admin/knowledge-bases',\s+\[AdminController::class, 'knowledgeBases'\]\);\s*(?://[^\n]*\n\s*)*\\\$router->get\('/admin/knowledge-bases/status',#",
            $routes,
            'Route littérale déclarée juste après la liste.'
        );

        $ctrl = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString('public function knowledgeBasesStatus(): void', $ctrl);
        $this->assertStringContainsString("'panel_html'", $ctrl);
        $this->assertStringContainsString("'state'             => KbStatus::documentState(\$doc)", $ctrl);
        $this->assertGreaterThanOrEqual(2, substr_count($ctrl, 'session_write_close();'), 'Un rafraîchissement ne retient jamais la session.');

        $tpl = self::lire('templates/admin/knowledge-bases.php');
        $this->assertStringContainsString("fetch('/admin/knowledge-bases/status'", $tpl);
        $this->assertStringContainsString('data-kb-id="<?= $kbId ?>"', $tpl);
        $this->assertStringContainsString("live.outerHTML = b.html", $tpl, 'Le bloc arrive tout rendu : aucun libellé côté JavaScript.');
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $tpl);

        // La fiche d'une base suit son passage tant que le serveur dit « busy »
        // — attente comprise — et reçoit son panneau tout rendu.
        $fiche = self::lire('public/assets/js/kb-form.js');
        $this->assertStringContainsString('next = data.busy ? 2500 : 0;', $fiche);
        $this->assertStringContainsString("'/sync-status'", $fiche);
        $this->assertStringContainsString('data.panel_html', $fiche);
        foreach (['pollDocStatus', 'renderSyncState', 'pollSyncStatus', 'docStatusText', 'kbSyncStateWrap'] as $ancien) {
            $this->assertStringNotContainsString($ancien, $tpl, "« $ancien » appartient à l'ancien suivi.");
            $this->assertStringNotContainsString($ancien, $fiche, "« $ancien » appartient à l'ancien suivi.");
        }
        $this->assertStringContainsString("require ROOT . '/templates/partials/kb-status.php';", $tpl);
        $this->assertStringContainsString("require ROOT . '/templates/partials/kb-status-overview.php';", $tpl);
    }

    public function testLaClasseEtLaMigrationSontEnPlace(): void
    {
        $this->assertStringContainsString("'/src/Sync/KbStatus.php'", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Sync/KbStatus.php'", self::lire('tests/bootstrap.php'));
        $this->assertLessThan(
            strpos(self::lire('config/classes.php'), "'/src/Sync/KbStatus.php'"),
            strpos(self::lire('config/classes.php'), "'/src/Sync/KbSync.php'"),
            'KbStatus s\'appuie sur KbSync : chargé après lui.'
        );
        $this->assertStringContainsString('ADD COLUMN activity_json', self::lire('database/migrations/072_kb_activity.sql'));
    }
}
