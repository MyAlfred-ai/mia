<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bases de connaissances — liste à schéma et fiche en page pleine
 * (Espaces → Bases de connaissances).
 *
 * Plus de fenêtre : la création et la modification d'une base remplissent la
 * zone principale ; un schéma — le même moteur que celui d'un assistant et
 * d'une tâche programmée — montre ce qui l'alimente (au-dessus), comment elle
 * est cherchée (à gauche), ce qu'elle sert (assistants liés, partage, en
 * dessous) ; le modèle d'embedding et le détail des extraits vectorisés
 * vivent dans une section « Avancé », en bas de la fiche.
 *
 * Trois étages vérifiés :
 *  - les DONNÉES du serveur (KbDiagram : sources, accès, configuration d'une
 *    base, catalogue, libellés, icônes) ;
 *  - le RENDU, exécuté sous node avec les vrais modules (diagram.js puis
 *    kb-diagram.js) : entrées au-dessus, vectorisation à gauche, flèches qui
 *    PARTENT vers les assistants et le partage, tout texte échappé ;
 *  - le CÂBLAGE : routes, contrôleur, pages, script de la fiche, extraits,
 *    styles, documentation — un schéma jamais posé ou une cible sans réglage
 *    ne lèveraient aucune erreur.
 *
 * Sans node, la partie rendu est SKIPPÉE ; elle tourne sur le poste de
 * développement et en CI.
 */
final class KbDiagramTest extends TestCase
{
    private const ENGINE = 'public/assets/js/diagram.js';
    private const JS     = 'public/assets/js/kb-diagram.js';
    private const FORMJS = 'public/assets/js/kb-form.js';
    private const LIST   = 'templates/admin/knowledge-bases.php';
    private const FORM   = 'templates/admin/knowledge-bases/edit.php';
    private const CSS    = 'public/assets/css/main.css';

    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // Données du serveur
    // ------------------------------------------------------------------

    public function testLesSourcesSontUneSeuleTable(): void
    {
        foreach (KbSync::PROVIDERS as $provider) {
            $this->assertArrayHasKey($provider, KbDiagram::SOURCES, "Fournisseur synchronisé sans source : $provider");
        }
        $this->assertArrayHasKey('mybrain', KbDiagram::SOURCES);
        foreach (KbDiagram::SOURCES as $cle => $source) {
            $this->assertNotSame('', $source['label'], $cle);
            $this->assertNotSame('', $source['what'], $cle);
            $this->assertFileExists(ROOT . '/public' . $source['logo'], "Logo absent : $cle");
        }
        // La liste et la fiche lisent la même table, jamais une copie — la
        // constante, ou son accesseur traduit.
        $this->assertMatchesRegularExpression('/\$syncProviderMeta = KbDiagram::(SOURCES|sources\(\));/', self::src(self::LIST));
        $this->assertStringContainsString("KbDiagram::SOURCES[\$value]['logo']", self::src(self::FORM));
        $this->assertStringContainsString("KbDiagram::sourceLabel('drive')", self::src(self::FORM), 'Les tuiles nomment la source par l’accesseur traduit.');

        // Les accesseurs traduisent ce qui s'affiche, la constante garde les clés.
        $this->assertSame(array_keys(KbDiagram::SOURCES), array_keys(KbDiagram::sources()));
        $this->assertSame('Google Agenda', KbDiagram::sourceLabel('gcalendar'));
        $this->assertSame(KbDiagram::SOURCES['drive']['what'], KbDiagram::sourceWhat('drive'));
        $this->assertSame('inconnue', KbDiagram::sourceLabel('inconnue'));
        $this->assertSame('', KbDiagram::sourceWhat('inconnue'));
        I18n::use('en');
        $this->assertSame('Google Calendar', KbDiagram::sourceLabel('gcalendar'));
        $this->assertSame('Google Calendar', KbDiagram::sources()['gcalendar']['label']);
        $this->assertSame('OneDrive', KbDiagram::sourceLabel('onedrive'), 'Un nom de produit reste tel quel.');
    }

    public function testLeNomDUnModeleNeRepetePasSesDimensions(): void
    {
        $this->assertSame('OpenAI 3-large (1536d)', EmbeddingService::label('openai', 'text-embedding-3-large'), 'Précondition : le libellé du catalogue porte les dimensions.');
        $this->assertSame('OpenAI 3-large', KbDiagram::embeddingLabel('openai', 'text-embedding-3-large'));
        $this->assertSame('Mistral Embed', KbDiagram::embeddingLabel('mistral', 'mistral-embed'));
    }

    public function testUnFichierDeposeDitSonEtat(): void
    {
        $ok = KbDiagram::fileEntry(['file_name' => 'Guide.pdf', 'mime_type' => 'application/pdf', 'extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 3]);
        $this->assertSame(['name' => 'Guide.pdf', 'image' => false, 'tone' => 'ok', 'label' => 'Indexé'], $ok);

        $scan = KbDiagram::fileEntry(['file_name' => 'Scan.png', 'mime_type' => 'image/png', 'extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 0]);
        $this->assertTrue($scan['image']);
        $this->assertSame('warn', $scan['tone'], 'Traité mais sans extrait : « Sans texte », jamais « indexé ».');
        $this->assertSame(KbStatus::documentState(['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 0])['label'], $scan['label']);
    }

    public function testLAccesDuProprietaireEtDUnBeneficiaire(): void
    {
        $kb = ['creator_name' => '  Alex Banc ', 'is_shared' => 0, 'share_can_manage' => 0];
        $parts = ['users' => [21 => 1, 22 => 0], 'groups' => [31 => 0]];
        $owner = KbDiagram::access($kb, true, true, $parts, ['users' => [22], 'groups' => [31]], ['users' => ['21']]);

        $this->assertTrue($owner['owned']);
        $this->assertTrue($owner['can_manage']);
        $this->assertTrue($owner['you']);
        $this->assertSame('Alex Banc', $owner['creator']);
        $this->assertSame([
            ['id' => 21, 'manage' => true, 'default' => false, 'feed' => true],
            ['id' => 22, 'manage' => false, 'default' => true, 'feed' => false],
        ], $owner['users']);
        $this->assertSame([['id' => 31, 'manage' => false, 'default' => true, 'feed' => false]], $owner['groups']);

        // Un bénéficiaire ne voit jamais la liste du partage, même si on la lui passait.
        $lecteur = KbDiagram::access(['is_shared' => 1, 'share_can_manage' => 0] + $kb, false, false, $parts, null, null);
        $this->assertSame([], $lecteur['users']);
        $this->assertSame([], $lecteur['groups']);
        $this->assertTrue($lecteur['shared']);
        $this->assertFalse($lecteur['can_manage']);
        $this->assertTrue(KbDiagram::access(['is_shared' => 1, 'share_can_manage' => 1] + $kb, false, false, null, null, null)['can_manage']);
    }

    public function testLaConfigurationDUneBaseALaFormeDeLaFiche(): void
    {
        $docs = [
            ['file_name' => 'Notes.md', 'mime_type' => 'text/markdown', 'source' => 'upload', 'extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 2],
            ['file_name' => 'Synthèse.pdf', 'mime_type' => 'application/pdf', 'source' => 'drive', 'extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 4],
            ['file_name' => 'Plan.docx', 'mime_type' => 'application/pdf', 'source' => 'drive', 'extraction_status' => 'pending', 'vector_status' => 'pending', 'chunk_count' => 0],
        ];
        $kb = [
            'name' => '  Dossiers clients ', 'description' => str_repeat('d', 300), 'is_active' => 1, 'is_system' => 0,
            'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => '1AbCdEf', 'sync_folder_name' => ' Clients 2026 ',
            'sync_enabled' => 1, 'sync_owner_user_id' => 1, 'chunk_count' => 6, 'query_count' => 9,
            'embedding_provider' => 'mistral', 'embedding_model' => 'mistral-embed',
        ];
        $access = KbDiagram::access($kb, true, true, null, null, null);
        $spec = KbDiagram::spec($kb, $docs, [3, '1', 3], $access, ['title' => 'À jour', 'tone' => 'ok', 'state' => 'ok'], 'Alex Banc');

        $this->assertSame('Dossiers clients', $spec['name']);
        $this->assertSame(240, mb_strlen($spec['description']));
        $this->assertTrue($spec['active']);
        $this->assertFalse($spec['live']);
        $this->assertSame(['provider' => 'drive', 'linked' => true, 'folder' => 'Clients 2026', 'auto' => true, 'owner' => 'Alex Banc'], $spec['source']);
        $this->assertNull($spec['remote']);
        $this->assertSame([KbDiagram::fileEntry($docs[0])], $spec['files'], 'Seuls les fichiers DÉPOSÉS sont nommés.');
        $this->assertSame(2, $spec['synced_total'], 'Les documents repris du dossier sont comptés.');
        $this->assertSame(6, $spec['chunks']);
        $this->assertSame(9, $spec['usage']);
        $this->assertSame('mistral::mistral-embed', $spec['embedding']);
        $this->assertSame(['title' => 'À jour', 'tone' => 'ok'], $spec['state']);
        $this->assertSame([3, 1], $spec['assistant_ids'], 'Sans doublon.');
        $this->assertSame($access, $spec['access']);

        // Un dossier choisi mais pas encore relié n'est pas une source.
        $nonRelie = KbDiagram::spec(['sync_folder_ref' => ''] + $kb, [], [], $access, null);
        $this->assertSame('', $nonRelie['source']['provider']);
        $this->assertSame('', $nonRelie['source']['owner'], 'Le nom du lecteur ne vient que d’un passage relié.');
        $this->assertNull($nonRelie['state']);

        // Une base en direct : son hôte et son application, ni dossier ni modèle.
        $live = KbDiagram::spec(['backend' => KnowledgeBase::BACKEND_MYBRAIN, 'remote_url' => 'https://cerveau.exemple.fr/api/v1', 'remote_app_name' => ' MIA '] + $kb, [], [], $access, null);
        $this->assertTrue($live['live']);
        $this->assertSame(['host' => 'cerveau.exemple.fr', 'app' => 'MIA'], $live['remote']);
        $this->assertSame('', $live['source']['provider']);
        $this->assertSame('', $live['embedding']);
    }

    public function testLeCatalogueNommeChaqueElement(): void
    {
        $cat = KbDiagram::catalog(
            [['id' => 11, 'name' => ' Veille ', 'avatar' => '../../etc/alfred-loupe.png', 'color' => 'red;x', 'is_personal' => 1, 'is_active' => 0]],
            [['id' => 21, 'name' => 'Marie Durand']],
            [['id' => 31, 'name' => 'Stagiaires', 'is_active' => 0]],
            0,
            false
        );
        $this->assertSame(['name' => 'Veille', 'avatar' => '/assets/img/profils/alfred-loupe.png', 'color' => '', 'sharing' => 'personal', 'active' => false], ((array)$cat['assistants'])[11]);
        $this->assertSame('Marie Durand', ((array)$cat['users'])[21]);
        $this->assertSame(['name' => 'Stagiaires', 'active' => false], ((array)$cat['groups'])[31]);
        $this->assertSame(1, $cat['interval'], 'Jamais « toutes les 0 min ».');
        $this->assertFalse($cat['qdrant_up']);
        $this->assertSame(KbDiagram::HUE, $cat['hue']);
        $this->assertSame('fr-FR', $cat['locale']);
        $this->assertSame('Google Agenda', $cat['sources']['gcalendar']['label']);

        $emb = ((array)$cat['embeddings'])['openai::text-embedding-3-large'];
        $this->assertSame(['label' => 'OpenAI 3-large', 'model' => 'text-embedding-3-large', 'logo' => '/assets/img/llm-openai.png', 'dims' => 1536], $emb);
        $this->assertSame(array_keys(KbDiagram::SOURCES), array_keys($cat['sources']));

        // Des tables vides restent des OBJETS JSON (le script les lit par clé).
        $vide = json_encode(KbDiagram::catalog([], [], [], 10, true));
        $this->assertStringContainsString('"assistants":{}', (string)$vide);
        $this->assertStringContainsString('"users":{}', (string)$vide);
        $this->assertStringContainsString('"groups":{}', (string)$vide);
    }

    public function testLesLibellesSontTraduitsCoteServeur(): void
    {
        $fr = KbDiagram::labels();
        foreach ($fr as $cle => $texte) {
            $this->assertNotSame('', trim($texte), "Libellé vide : $cle");
        }
        foreach (['docs_many', 'usage_many', 'chunks_many', 'dims', 'sync_every', 'synced_many', 'hidden_many', 'more_one', 'more_many'] as $cle) {
            $this->assertStringContainsString('{n}', $fr[$cle], $cle);
        }
        foreach (['folder', 'calendar', 'via_owner'] as $cle) {
            $this->assertStringContainsString('{name}', $fr[$cle], $cle);
        }
        $this->assertStringContainsString('{app}', $fr['live_app']);
        $this->assertStringContainsString('{id}', $fr['user_unknown']);
        $this->assertSame('Aucun document', $fr['docs_none']);

        // La fiche et la liste sont traduites : le schéma parle leur langue.
        I18n::use('en');
        $en = KbDiagram::labels();
        $this->assertSame(array_keys($fr), array_keys($en));
        $this->assertSame('No documents', $en['docs_none']);
        $this->assertSame('You', $en['you']);
        $this->assertSame('Search', $en['search']);
        foreach ($fr as $cle => $texte) {
            preg_match_all('/\{\w+\}/', $texte, $a);
            preg_match_all('/\{\w+\}/', $en[$cle], $b);
            $this->assertSame($a[0], $b[0], "Substituants perdus à la traduction : $cle");
        }
        // Nombres et tri dans la langue de la page.
        $this->assertSame('en-US', KbDiagram::catalog([], [], [], 10, true)['locale']);
    }

    public function testLesIconesSontCellesDesAutresSchemas(): void
    {
        $kb    = KbDiagram::icons();
        $tache = TaskDiagram::icons();
        $this->assertSame($tache['kbs'], $kb['base'], 'La base : le livre ouvert des autres schémas.');
        $this->assertSame($tache['assistant'], $kb['assistants']);
        $this->assertSame($tache['edit'], $kb['edit']);
        $this->assertSame(AdminMenu::icon('profiles', 14), $kb['assistant']);
        $this->assertSame(AssistantDiagram::icons()['doc_image'], $kb['doc_image']);
        foreach ($kb as $cle => $icone) {
            $this->assertStringStartsWith('<svg', $icone, "Icône absente : $cle");
        }
        foreach (['ok', 'busy', 'warn', 'error', 'idle'] as $ton) {
            $this->assertArrayHasKey('state_' . $ton, $kb, "État sans pictogramme : $ton");
        }
    }

    // ------------------------------------------------------------------
    // Rendu, exécuté sous node
    // ------------------------------------------------------------------

    /** Catalogue de test : celui du serveur, avec des assistants, personnes et groupes inventés. */
    private static function catalogue(array $surcharge = []): array
    {
        $cat = KbDiagram::catalog(
            [
                ['id' => 11, 'name' => 'Veille marché', 'avatar' => 'alfred-loupe.png', 'color' => '#22c55e', 'is_personal' => 0, 'is_active' => 1],
                ['id' => 12, 'name' => 'élodie RH', 'avatar' => '', 'color' => '#ef4444', 'is_personal' => 1, 'is_active' => 1],
                ['id' => 13, 'name' => 'Support client', 'avatar' => '', 'color' => '', 'is_personal' => 0, 'is_global' => 1, 'is_active' => 0],
                ['id' => 14, 'name' => 'Pirate <img src=x onerror=alert(1)>', 'avatar' => '', 'color' => '#123456', 'is_personal' => 0, 'is_active' => 1],
            ],
            [['id' => 21, 'name' => 'Marie Durand'], ['id' => 22, 'name' => 'Paul <b>Martin</b>']],
            [['id' => 31, 'name' => 'Ventes', 'is_active' => 1], ['id' => 32, 'name' => 'Stagiaires', 'is_active' => 0], ['id' => 33, 'name' => 'Direction', 'is_active' => 1]],
            10,
            true
        );
        return $surcharge + $cat;
    }

    private static function fichier(string $nom, string $mime = 'application/pdf', string $ext = 'done', string $vec = 'done', int $chunks = 3): array
    {
        return KbDiagram::fileEntry(['file_name' => $nom, 'mime_type' => $mime, 'extraction_status' => $ext, 'vector_status' => $vec, 'chunk_count' => $chunks]);
    }

    /** Scénarios : nom → [configuration d'une base, options du modèle, catalogue particulier ?]. */
    private static function scenarios(): array
    {
        $owner = ['owned' => true, 'shared' => false, 'can_manage' => true, 'you' => true, 'creator' => 'Alex Banc', 'users' => [], 'groups' => []];
        $base = [
            'name' => 'Documentation produit', 'description' => 'Manuels et FAQ.', 'active' => true, 'system' => false, 'live' => false,
            'source' => ['provider' => '', 'linked' => false, 'folder' => '', 'auto' => false, 'owner' => ''],
            'remote' => null, 'files' => [], 'synced_total' => 0, 'chunks' => 34, 'usage' => 12,
            'embedding' => 'openai::text-embedding-3-large', 'state' => ['title' => 'À jour', 'tone' => 'ok'],
            'assistant_ids' => [], 'access' => $owner,
        ];
        $fichiers = [
            self::fichier('Guide utilisateur.pdf'),
            self::fichier('FAQ support.docx'),
            self::fichier('Scan contrat.pdf', 'application/pdf', 'done', 'done', 0),
            self::fichier('Tarifs 2026.xlsx', 'application/pdf', 'failed', 'failed', 0),
            self::fichier('Schéma.png', 'image/png'),
            self::fichier('Annexe A.pdf'),
            self::fichier('Annexe B.pdf'),
        ];
        $partage = ['groups' => [
            ['id' => 33, 'manage' => false, 'default' => false, 'feed' => false],
            ['id' => 31, 'manage' => true, 'default' => true, 'feed' => true],
            ['id' => 32, 'manage' => false, 'default' => false, 'feed' => false],
            ['id' => 99, 'manage' => false, 'default' => false, 'feed' => false],
        ], 'users' => [
            ['id' => 22, 'manage' => false, 'default' => false, 'feed' => true],
        ]];
        $drive = ['provider' => 'drive', 'linked' => true, 'folder' => 'Clients 2026', 'auto' => true, 'owner' => 'Alex Banc'];

        $jump = ['jump' => [
            'docs' => 'Modifier dans « Sources »', 'connectors' => 'Modifier dans « Sources »', 'live' => 'Modifier dans « Sources »',
            'llm' => 'Modifier dans « Avancé »', 'assistant' => 'Modifier dans « Identité »',
            'assistants' => 'Modifier dans « Assistants liés »', 'sharing' => 'Modifier dans « Partage »',
        ]];

        return [
            'locale'    => [['files' => $fichiers, 'assistant_ids' => [13, 11, 12, 99, 11], 'access' => array_merge($owner, $partage)] + $base, null, null],
            'drive'     => [['source' => $drive, 'synced_total' => 3, 'files' => [self::fichier('Notes.md', 'text/markdown', 'processing', 'pending', 0)]] + $base, null, null],
            'manuelle'  => [['source' => ['auto' => false, 'owner' => ''] + $drive, 'synced_total' => 3] + $base, null, null],
            'agenda'    => [['source' => ['provider' => 'gcalendar', 'linked' => false, 'folder' => '', 'auto' => true, 'owner' => '']] + $base, null, null],
            'cerveau'   => [['live' => true, 'remote' => ['host' => 'cerveau.exemple.fr', 'app' => 'MIA'], 'embedding' => '', 'chunks' => 0,
                             'access' => array_merge($owner, $partage)] + $base, null, null],
            'cerveau_vide' => [['live' => true, 'remote' => ['host' => '', 'app' => ''], 'embedding' => ''] + $base, null, null],
            'creation'  => [['name' => '  ', 'description' => '', 'creating' => true, 'state' => null, 'chunks' => 0, 'usage' => 0] + $base, null, null],
            'inactive'  => [['active' => false] + $base, null, null],
            'systeme'   => [['system' => true, 'access' => ['owned' => false, 'shared' => false, 'can_manage' => false, 'you' => false, 'creator' => '', 'users' => [], 'groups' => []]] + $base, null, null],
            'lecteur'   => [['access' => ['owned' => false, 'shared' => true, 'can_manage' => false, 'you' => false, 'creator' => 'Marie Durand', 'users' => [], 'groups' => []]] + $base, null, null],
            'privee'    => [$base, null, null],
            'qdrant'    => [$base, null, ['qdrant_up' => false]],
            'sans_emb'  => [['embedding' => 'openai::text-embedding-ada-002'] + $base, null, null],
            'hostile'   => [['name' => 'Base <b>clients</b>', 'description' => 'Suit vos <script>alert(1)</script> dossiers.',
                             'source' => ['folder' => '<img src=x>'] + $drive, 'assistant_ids' => [14],
                             'files' => [self::fichier('<svg onload=alert(1)>.pdf')],
                             'access' => array_merge($owner, ['users' => [['id' => 22, 'manage' => false, 'default' => false, 'feed' => false]]])] + $base, null, null],
            'saut'      => [['files' => [self::fichier('Guide.pdf')], 'source' => $drive, 'assistant_ids' => [11]] + $base, $jump, null],
            'saut_cerveau' => [['live' => true, 'remote' => ['host' => 'cerveau.exemple.fr', 'app' => ''], 'embedding' => ''] + $base, $jump, null],
        ];
    }

    /** @return array<string, mixed> */
    private static function sortie(): array
    {
        if (self::$sortie !== null) {
            return self::$sortie;
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        I18n::use('fr');
        $scenarios = [];
        foreach (self::scenarios() as $nom => [$spec, $options, $catalogue]) {
            $scenarios[$nom] = ['spec' => $spec, 'opts' => $options, 'cat' => self::catalogue($catalogue ?? [])];
        }

        $script = "'use strict';\n"
            . self::src(self::ENGINE) . "\n"
            . self::src(self::JS) . "\n"
            . 'const D = globalThis.MiaKbDiagram;' . "\n"
            . 'const SC = ' . json_encode($scenarios) . ";\n"
            . 'const out = { domTouched: typeof document !== "undefined" };' . "\n"
            . 'for (const [k, s] of Object.entries(SC)) { const v = D.model(s.spec, s.cat, s.opts || undefined); out[k] = { view: v, html: D.html(v) }; }' . "\n"
            . 'process.stdout.write(JSON.stringify(out));';

        $fichier = tempnam(sys_get_temp_dir(), 'mia-schema-base-');
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
            self::fail("kb-diagram.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return self::$sortie = $resultats;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    /** Nœuds de la vue par genre. */
    private static function parGenre(array $noeuds): array
    {
        return array_column($noeuds, null, 'kind');
    }

    public function testLesSourcesAlimententLaBaseParLeHaut(): void
    {
        $s = self::sortie()['locale'];
        $this->assertNull($s['view']['trigger']);
        $this->assertStringContainsString('<div class="sd-canvas">', $s['html'], 'Des entrées au-dessus : la disposition à trois rangées.');
        $this->assertStringContainsString('<div class="sd-inputs">', $s['html']);
        $this->assertStringNotContainsString('sd-trigger', $s['html']);
        $this->assertStringContainsString('data-node="input" data-edge="down" data-target="docs"', $s['html']);
        $this->assertStringContainsString('data-node="llm" data-edge="side"', $s['html']);
        $this->assertSame(1, substr_count($s['html'], 'data-node="assistant"'));
        $this->assertFalse(self::sortie()['domTouched'], 'La partie pure ne touche jamais au DOM.');

        // Fichiers déposés : cinq montrés, le nœud compte TOUT et dit le reste.
        $docs = self::parGenre($s['view']['inputs'])['docs'];
        $this->assertSame(KbDiagram::labels()['files'], $docs['title']);
        $this->assertCount(5, $docs['items']);
        $this->assertSame(7, $docs['count']);
        $this->assertSame('+ 2 autres', $docs['more']);
        $this->assertStringContainsString('<span class="sd-count">7</span>', $s['html']);
        $warn = array_column($docs['items'], 'warn', 'label');
        $this->assertFalse($warn['Guide utilisateur.pdf']);
        $this->assertTrue($warn['Scan contrat.pdf'], 'Sans texte : signalé.');
        $this->assertTrue($warn['Tarifs 2026.xlsx'], 'Illisible : signalé.');
        $notes = array_column($docs['items'], 'note', 'label');
        $this->assertSame('Indexé', $notes['Guide utilisateur.pdf']);
        $this->assertSame(KbDiagram::icons()['doc_image'], self::parGenre($s['view']['inputs'])['docs']['items'][4]['icon'], 'Une image a son pictogramme.');
    }

    public function testUnDossierSynchroniseDitCeQuIlFait(): void
    {
        $L = KbDiagram::labels();
        $drive = self::parGenre(self::sortie()['drive']['view']['inputs']);
        $this->assertSame(['connectors', 'docs'], array_keys($drive), 'Le dossier d’abord, puis les fichiers déposés à côté.');
        $c = $drive['connectors'];
        $this->assertSame('Google Drive', $c['title']);
        $this->assertSame('Dossier « Clients 2026 »', $c['headline']);
        $this->assertSame(['text' => $L['sync_auto'], 'on' => true, 'muted' => false], $c['status']);
        $this->assertSame('Synchronisée toutes les 10 min', $c['sub']);
        $this->assertSame('Lu avec la connexion de Alex Banc', $c['note']);
        $this->assertSame(3, $c['count']);
        $this->assertStringContainsString('/assets/img/google-drive.svg', self::sortie()['drive']['html']);

        $manuelle = self::parGenre(self::sortie()['manuelle']['view']['inputs'])['connectors'];
        $this->assertSame(['text' => $L['sync_manual'], 'on' => false, 'muted' => true], $manuelle['status']);
        $this->assertSame($L['sync_off'], $manuelle['sub']);
        $this->assertStringContainsString('<span class="sd-status is-muted">' . $L['sync_manual'] . '</span>', self::sortie()['manuelle']['html']);

        // Un agenda pas encore relié : le dit, en pointillés.
        $agenda = self::parGenre(self::sortie()['agenda']['view']['inputs'])['connectors'];
        $this->assertSame('Google Agenda', $agenda['title']);
        $this->assertSame($L['calendar_missing'], $agenda['headline']);
        $this->assertTrue($agenda['missing']);
        $this->assertStringContainsString('sd-group sd-connectors is-missing', self::sortie()['agenda']['html']);
    }

    public function testUneBaseSansSourceLeDit(): void
    {
        $L = KbDiagram::labels();
        $creation = self::sortie()['creation'];
        $this->assertCount(1, $creation['view']['inputs']);
        $vide = $creation['view']['inputs'][0];
        $this->assertSame(['docs', true, $L['sources_empty']], [$vide['kind'], $vide['missing'], $vide['empty']]);
        $this->assertStringContainsString('<p class="sd-group-empty">' . $L['sources_empty'] . '</p>', $creation['html']);
        // Nom encore vide : « Nouvelle base », en italique.
        $this->assertSame($L['untitled'], $creation['view']['name']);
        $this->assertStringContainsString('<span class="sd-title sd-name is-missing">' . $L['untitled'] . '</span>', $creation['html']);
        // À la création : ni chiffres ni extraits — rien n'existe encore.
        $this->assertSame([], $creation['view']['assistant']['traits']);
        $this->assertCount(1, $creation['view']['llm']['params']);
        $this->assertSame($L['vector_create'], $creation['view']['llm']['note']);
    }

    public function testLaVectorisationEstAGauche(): void
    {
        $L = KbDiagram::labels();
        $s = self::sortie();
        $llm = $s['locale']['view']['llm'];
        $this->assertSame([$L['vector'], 'OpenAI 3-large', 'text-embedding-3-large'], [$llm['kicker'], $llm['label'], $llm['provider']]);
        $this->assertSame('/assets/img/llm-openai.png', $llm['logo']);
        $this->assertSame($L['vector_note'], $llm['note']);
        $this->assertMatchesRegularExpression('/^1\s536 dimensions$/u', $llm['params'][0]['text'], 'Nombres à la française.');
        $this->assertSame(['embedding', 'chunks'], array_column($llm['params'], 'target'));
        $this->assertSame('34 extraits', $llm['params'][1]['text']);
        $this->assertStringContainsString('<ul class="sd-traits sd-params">', $s['locale']['html']);

        // Moteur vectoriel en panne : le dit, en ambre.
        $this->assertSame($L['qdrant_down'], $s['qdrant']['view']['llm']['note']);
        $this->assertStringContainsString('<div class="sd-note is-warn">' . $L['qdrant_down'], $s['qdrant']['html']);
        // Modèle retiré du catalogue : rien d'inventé.
        $this->assertSame($L['vector_none'], $s['sans_emb']['view']['llm']['label']);
        $this->assertStringContainsString('sd-llm is-missing', $s['sans_emb']['html']);
    }

    public function testUnCerveauEnDirect(): void
    {
        $L = KbDiagram::labels();
        $s = self::sortie()['cerveau'];
        $entree = $s['view']['inputs'][0];
        $this->assertCount(1, $s['view']['inputs']);
        $this->assertSame(['live', $L['live_title'], 'cerveau.exemple.fr', 'Application « MIA »'], [$entree['kind'], $entree['title'], $entree['headline'], $entree['sub']]);
        $this->assertSame(['text' => $L['live_status'], 'on' => true], $entree['status']);
        $this->assertStringContainsString('/assets/img/mybrain.png', $s['html']);
        // Rien de vectorisé : la recherche est « en direct ».
        $this->assertSame([$L['search'], $L['live'], $L['live_provider'], $L['live_note']], [$s['view']['llm']['kicker'], $s['view']['llm']['label'], $s['view']['llm']['provider'], $s['view']['llm']['note']]);
        $this->assertArrayNotHasKey('params', $s['view']['llm']);
        $this->assertNotContains($L['docs_none'], array_column($s['view']['assistant']['traits'], 'text'));
        // Le partage dit ce que chaque groupe peut de plus.
        $partage = array_column(self::parGenre($s['view']['groups'])['sharing']['items'], 'note', 'label');
        $this->assertSame('Interroge le cerveau · cochée par défaut · peut nourrir', $partage['Ventes']);
        $this->assertSame('Interroge le cerveau', $partage['Direction']);

        $vide = self::sortie()['cerveau_vide']['view']['inputs'][0];
        $this->assertSame([$L['live_missing'], true, $L['live_hint']], [$vide['headline'], $vide['missing'], $vide['sub']]);
    }

    public function testLaBaseAuCentre(): void
    {
        $L = KbDiagram::labels();
        $s = self::sortie();
        $centre = $s['locale']['view']['assistant'];
        $this->assertSame(['kb', 'Documentation produit', 'Manuels et FAQ.'], [$centre['kind'], $centre['name'], $centre['description']]);
        $this->assertStringContainsString('<span class="sd-ava is-glyph" aria-hidden="true"><svg', $s['locale']['html'], 'Une base n’a pas de visage : son pictogramme.');
        $this->assertStringContainsString('--sd-assistant:' . KbDiagram::HUE, $s['locale']['html'], 'Les bases sont bleues, comme dans le schéma d’un assistant.');
        $this->assertStringContainsString('<span class="sd-status is-on" data-target="active">' . $L['active'] . '</span>', $s['locale']['html']);
        $this->assertSame(['À jour', '7 documents', '12 utilisations'], array_column($centre['traits'], 'text'));
        $this->assertStringContainsString('<li class="sd-trait is-ok" data-target="state">', $s['locale']['html']);

        // Sans document : l'état le dit déjà, pas de « Aucun document » en double.
        $this->assertSame(['À jour', '12 utilisations'], array_column($s['privee']['view']['assistant']['traits'], 'text'));

        // Inactive : couleurs éteintes, pastille ambre.
        $this->assertStringContainsString('<div class="sd is-paused"', $s['inactive']['html']);
        $this->assertStringContainsString('<span class="sd-status is-off" data-target="active">' . $L['inactive'] . '</span>', $s['inactive']['html']);
        // Système, partagée avec vous : dit en pastille.
        $this->assertContains($L['system'], array_column($s['systeme']['view']['assistant']['traits'], 'text'));
        $this->assertContains($L['shared_read'], array_column($s['lecteur']['view']['assistant']['traits'], 'text'));
    }

    public function testLesAssistantsLiesPartentDeLaBase(): void
    {
        $L = KbDiagram::labels();
        $s = self::sortie()['locale'];
        $groupes = self::parGenre($s['view']['groups']);
        $this->assertSame(['assistants', 'sharing'], array_keys($groupes));
        $this->assertSame(2, substr_count($s['html'], 'data-flow="out"'), 'Ce que la base sert : la flèche part d’elle.');
        $this->assertStringContainsString('data-node="source" data-edge="up" data-flow="out" data-target="assistants"', $s['html']);

        $a = $groupes['assistants'];
        $this->assertSame('out', $a['flow']);
        // Par ordre alphabétique, sans doublon ; un assistant hors périmètre ferme la marche, sans son nom.
        $this->assertSame(['élodie RH', 'Support client', 'Veille marché', '1 assistant hors de votre périmètre'], array_column($a['items'], 'label'));
        $this->assertSame(4, $a['count']);
        $parNom = array_column($a['items'], null, 'label');
        $this->assertSame('/assets/img/profils/alfred-loupe.png', $parNom['Veille marché']['avatar']);
        $this->assertSame(['#ef4444', 'É'], [$parNom['élodie RH']['dot'], $parNom['élodie RH']['initial']], 'Sans avatar : sa couleur, à son initiale.');
        $this->assertSame('#64748b', $parNom['Support client']['dot'], 'Sans couleur : un gris neutre.');
        $this->assertSame([$L['assistant_off'], true], [$parNom['Support client']['note'], $parNom['Support client']['warn']]);
        $this->assertSame([$L['personal'], $L['org']], [$parNom['élodie RH']['note'], $parNom['Veille marché']['note']]);
        $this->assertStringContainsString('<span class="sd-item-ic is-ava" aria-hidden="true"><img src="/assets/img/profils/alfred-loupe.png" alt=""></span>', $s['html']);
        $this->assertStringContainsString('<span class="sd-item-ic is-dot" aria-hidden="true"><span style="background:#ef4444">É</span></span>', $s['html']);

        // Aucun assistant : le dit, et rappelle que la base se coche dans le chat.
        $aucun = self::parGenre(self::sortie()['privee']['view']['groups'])['assistants'];
        $this->assertSame([[], $L['assistants_none']], [$aucun['items'], $aucun['empty']]);
    }

    public function testLePartageDitQuiVoitLaBase(): void
    {
        $L = KbDiagram::labels();
        $s = self::sortie();
        $partage = self::parGenre($s['locale']['view']['groups'])['sharing'];
        // Propriétaire d'abord, groupes puis personnes par ordre alphabétique,
        // administrateurs en dernier — cinq montrés, le nœud compte TOUT.
        $this->assertSame(['Vous', 'Direction', 'Groupe #99', 'Stagiaires', 'Ventes'], array_column($partage['items'], 'label'));
        $this->assertSame([7, '+ 2 autres'], [$partage['count'], $partage['more']]);
        $this->assertSame(['text' => $L['shared'], 'on' => true], $partage['status']);
        $notes = array_column($partage['items'], null, 'label');
        $this->assertSame($L['owner'], $notes['Vous']['note']);
        $this->assertSame($L['can_manage'], $notes['Ventes']['note']);
        $this->assertSame($L['can_read'], $notes['Direction']['note']);
        $this->assertSame([$L['group_off'], true], [$notes['Stagiaires']['note'], $notes['Stagiaires']['warn']], 'Un groupe inactif : partage sans effet.');

        // Une personne, puis les administrateurs, qui voient tout.
        $hostile = self::parGenre($s['hostile']['view']['groups'])['sharing'];
        $this->assertSame(['Vous', 'Paul <b>Martin</b>', $L['admins']], array_column($hostile['items'], 'label'));
        $this->assertSame([$L['can_read'], $L['admins_note']], [$hostile['items'][1]['note'], $hostile['items'][2]['note']]);

        // Personne : privée, en sourdine.
        $privee = self::parGenre($s['privee']['view']['groups'])['sharing'];
        $this->assertSame(['text' => $L['private'], 'on' => false, 'muted' => true], $privee['status']);
        $this->assertSame(['Vous', $L['admins']], array_column($privee['items'], 'label'));

        // Un bénéficiaire ne voit que son accès et le propriétaire.
        $lecteur = self::parGenre($s['lecteur']['view']['groups'])['sharing'];
        $this->assertSame([['Vous', $L['shared_you_read']], ['Marie Durand', $L['owner']]], array_map(null, array_column($lecteur['items'], 'label'), array_column($lecteur['items'], 'note')));

        // La base de l'assistant d'aide : tout le monde.
        $systeme = self::parGenre($s['systeme']['view']['groups'])['sharing'];
        $this->assertSame([$L['everyone']], array_column($systeme['items'], 'label'));
    }

    public function testInteractifSeulementQuandLaFichePasseLesIntitules(): void
    {
        $s = self::sortie();
        $saut = $s['saut']['html'];
        $this->assertStringContainsString('<div class="sd is-interactive"', $saut);
        // Un bouton par nœud nommé et présent : dossier, fichiers, vectorisation, base, assistants, partage.
        $this->assertSame(6, substr_count($saut, 'class="sd-jump"'));
        $this->assertStringContainsString('<button type="button" class="sd-jump" data-target="connectors" title="Modifier dans « Sources »" aria-label="Modifier dans « Sources »">', $saut);
        $this->assertDoesNotMatchRegularExpression('/<div class="sd-node[^>]*role="button"/', $saut);
        $this->assertSame(5, substr_count($s['saut_cerveau']['html'], 'class="sd-jump"'), 'Cerveau : son nœud, la recherche, la base, assistants, partage.');

        // La liste : ni bouton ni classe interactive, les cibles restent inertes.
        $liste = $s['locale']['html'];
        $this->assertStringNotContainsString('is-interactive', $liste);
        $this->assertStringNotContainsString('sd-jump', $liste);
        $this->assertStringContainsString('data-target="sharing"', $liste);
    }

    public function testLesCiblesDuSchemaSontCellesQueLaFicheSaitResoudre(): void
    {
        $s = self::sortie();
        $cibles = [];
        foreach (['locale', 'drive', 'cerveau', 'creation'] as $k) {
            preg_match_all('/data-target="(\w+)"/', $s[$k]['html'], $m);
            $cibles = array_merge($cibles, $m[1]);
        }
        $cibles = array_values(array_unique($cibles));
        sort($cibles);

        $this->assertSame(1, preg_match('/const JUMP_TARGETS = \{(.*?)\n        \};/s', self::src(self::FORMJS), $bloc), 'JUMP_TARGETS introuvable dans kb-form.js.');
        preg_match_all('/^\s+(\w+):\s+\{ section:/m', $bloc[1], $connues);
        $resolues = $connues[1];
        sort($resolues);
        $this->assertSame($cibles, $resolues, 'Chaque cible du schéma a son réglage dans la fiche ; aucune entrée sans cible.');

        // Et chaque réglage visé existe dans la page.
        preg_match_all("/byId\\('(kb\\w+)'\\)/", $bloc[1], $ids);
        $this->assertNotEmpty($ids[1]);
        $page = self::src(self::FORM);
        foreach (array_unique($ids[1]) as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $page, "Réglage visé absent de la fiche : #$id");
        }
    }

    public function testToutTexteEstEchappeEtAucuneImageNeVientDAilleurs(): void
    {
        $html = self::sortie()['hostile']['html'];
        foreach (['<b>', '<script>', '<img src=x', '<svg onload'] as $balise) {
            $this->assertStringNotContainsString($balise, $html, "Balise passée telle quelle : $balise");
        }
        $this->assertStringContainsString('Base &lt;b&gt;clients&lt;/b&gt;', $html);
        $this->assertStringContainsString('Dossier « &lt;img src=x&gt; »', $html);
        $this->assertStringContainsString('Pirate &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('Paul &lt;b&gt;Martin&lt;/b&gt;', $html);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeScriptDuSchemaNAAucunTexteEnDur(): void
    {
        $code = self::src(self::JS);
        $code = (string)preg_replace('#/\*.*?\*/#s', '', $code);
        $lignes = array_filter(
            array_map(static fn(string $l): string => (string)preg_replace('#(^|\s)//\s.*$#', '', $l), explode("\n", $code)),
            static fn(string $l): bool => trim($l) !== ''
        );
        foreach ($lignes as $ligne) {
            $this->assertDoesNotMatchRegularExpression('/[À-ÿ’«»]/u', $ligne, "Texte en dur dans le schéma d'une base : « $ligne » — il vient du catalogue (KbDiagram::labels).");
        }
    }

    public function testLeModeleSeBrancheSurLeMoteurPartage(): void
    {
        $modele = self::src(self::JS);
        $this->assertStringContainsString('const E = root.MiaDiagram;', $modele);
        $this->assertStringContainsString('html: E.html,', $modele);
        $this->assertStringContainsString('mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); }', $modele);
        $this->assertStringContainsString('root.MiaKbDiagram = api;', $modele);
        foreach (['function html(', 'function draw(', 'function routes(', 'function mount('] as $fonction) {
            $this->assertStringNotContainsString($fonction, $modele, "Le modèle d'une base redéfinit $fonction : un second rendu divergerait.");
        }
        $this->assertStringContainsString('trigger: null,', $modele, 'Une base n’a pas de déclencheur : des entrées.');

        // Le moteur : entrées au-dessus, flèches sortantes, et la gouttière en colonne unique.
        $moteur = self::src(self::ENGINE);
        $this->assertStringContainsString("out += '<div class=\"sd-inputs\">' + inputs.map((g) => group(g, 'input', 'down')).join('') + '</div>';", $moteur);
        $this->assertStringContainsString("(g.flow === 'out' ? ' data-flow=\"out\"' : '')", $moteur);
        $this->assertStringContainsString("S.out = el.getAttribute('data-flow') === 'out';", $moteur);
        $this->assertStringContainsString('function gutter(', $moteur);
    }

    public function testLesPagesChargentLeMoteurPuisLeModele(): void
    {
        $liste = self::src(self::LIST);
        $moteur = strpos($liste, '<script src="/assets/js/diagram.js');
        $modele = strpos($liste, '<script src="/assets/js/kb-diagram.js');
        $page   = strpos($liste, "<script>\n(function () {");
        $this->assertNotFalse($moteur);
        $this->assertNotFalse($modele);
        $this->assertNotFalse($page);
        $this->assertLessThan($modele, $moteur, 'Le moteur avant le modèle, qui s’y branche.');
        $this->assertLessThan($page, $modele, 'Le modèle avant le script de la page, qui s’en sert aussitôt.');
        $this->assertStringContainsString('window.KB_DIAGRAMS = <?= json_encode($diagram, ', $liste);

        $fiche = self::src(self::FORM);
        $ordre = [
            strpos($fiche, 'window.KB_FORM = <?= json_encode(['),
            strpos($fiche, '<script src="/assets/js/diagram.js'),
            strpos($fiche, '<script src="/assets/js/kb-diagram.js'),
            strpos($fiche, '<script src="/assets/js/kb-form.js'),
        ];
        $this->assertNotContains(false, $ordre, 'La fiche charge son état, le moteur, le modèle puis son script.');
        $trie = $ordre;
        sort($trie);
        $this->assertSame($trie, $ordre);
        foreach ([$liste, $fiche] as $html) {
            $this->assertStringContainsString('JSON_HEX_TAG', $html, 'Les icônes voyagent dans un <script> : < et > échappés.');
            $this->assertDoesNotMatchRegularExpression('#<script src="/assets/js/(diagram|kb-diagram|kb-form)\.js[^"]*" defer#', $html);
            $this->assertStringContainsString('id="kbDiagramPanel"', $html);
            $this->assertStringContainsString('id="kbDiagram"', $html);
            $this->assertStringContainsString('admin-content pf-content kb-content', $html);
        }
        $this->assertStringContainsString('Composition de la base', $liste);
        $this->assertStringContainsString('Aperçu de la base', $fiche);
        $this->assertStringContainsString('class="sched-diagram-hint"', $fiche, 'Le panneau dit qu’un élément se clique.');
    }

    public function testPlusAucuneFenetre(): void
    {
        $liste = self::src(self::LIST);
        $fiche = self::src(self::FORM);
        foreach (['kb-modal', 'openKbModal', 'kbReindexModal', 'kbBrowseModal', 'kbShareModal', 'class="modal'] as $ancien) {
            $this->assertStringNotContainsString($ancien, $liste, "La liste porte encore « $ancien ».");
            $this->assertStringNotContainsString($ancien, $fiche, "La fiche porte encore « $ancien ».");
        }
        // Créer et modifier mènent à la page de la fiche.
        $this->assertStringContainsString('href="/admin/knowledge-bases/new"', $liste);
        $this->assertStringContainsString("'/admin/knowledge-bases/' . \$kbId . '/edit'", $liste);
        // L'explorateur de dossiers s'ouvre dans la fiche, sous le champ.
        $this->assertStringContainsString('id="kbBrowse"', $fiche);
    }

    public function testLesRoutesEtLeControleur(): void
    {
        $routes = self::src('public/index.php');
        foreach ([
            "get('/admin/knowledge-bases/new',",
            "get('/admin/knowledge-bases/{id}/edit',",
            "get('/admin/knowledge-bases/{id}/chunks',",
        ] as $route) {
            $this->assertStringContainsString($route, $routes, "Route absente : $route");
        }
        $ctrl = self::src('src/Controllers/AdminController.php');
        foreach (['public function newKnowledgeBase(): void', 'public function editKnowledgeBase(string $id): void', 'public function knowledgeBaseChunks(string $id): void'] as $methode) {
            $this->assertStringContainsString($methode, $ctrl);
        }
        $this->assertSame(2, substr_count($ctrl, 'KbDiagram::catalog('), 'Liste ET fiche.');
        $this->assertSame(2, substr_count($ctrl, 'KbDiagram::spec('), 'Liste ET fiche.');
        $this->assertStringContainsString("require ROOT . '/templates/admin/knowledge-bases/edit.php';", $ctrl);
        // Créer mène à la liste, ouverte sur la base créée ; une erreur ramène au formulaire.
        $this->assertStringContainsString("header('Location: /admin/knowledge-bases#base-' . \$id);", $ctrl);
        $this->assertStringContainsString("header('Location: /admin/knowledge-bases/new');", $ctrl);
        // Modifier ramène à la fiche, jamais à la liste.
        $this->assertStringContainsString("\$back = '/admin/knowledge-bases/' . \$kbId . '/edit';", $ctrl);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Sync/KbDiagram.php', self::src($liste), "$liste ne charge pas KbDiagram.");
        }
    }

    public function testLesExtraitsSeLisentSansRienExposer(): void
    {
        $ctrl = self::src('src/Controllers/AdminController.php');
        $this->assertSame(1, preg_match('/public function knowledgeBaseChunks\(string \$id\): void\s*\{(.*?)\n    \}/s', $ctrl, $m));
        $corps = $m[1];
        $this->assertStringContainsString("AuthMiddleware::requirePermission('knowledge_bases.view');", $corps);
        $this->assertStringContainsString('KnowledgeBase::manageableBy($kbId, Session::currentUser())', $corps, 'Seul qui gère la base voit ses extraits.');
        $this->assertStringContainsString('session_write_close();', $corps);
        $this->assertStringContainsString('KnowledgeBase::isRemote($kb)', $corps, 'Un cerveau en direct n’a pas d’extraits chez MIA.');
        $this->assertStringContainsString('existingPointIds(', $corps);
        $this->assertStringContainsString("'vector' => \$present === null ? null : isset(\$present[\$row['id']])", $corps, 'Qdrant injoignable : « non vérifié », jamais « sans vecteur ».');

        $modele = self::src('src/Models/KnowledgeBaseDocument.php');
        $this->assertStringContainsString("c.content LIKE ? ESCAPE '!'", $modele, 'Les jokers tapés restent du texte.');
        $this->assertStringContainsString('LEFT(c.content, \' . self::CHUNK_PREVIEW_CHARS . \')', $modele, 'Jamais un extrait entier de plusieurs Mo.');

        $qdrant = self::src('src/RAG/QdrantStore.php');
        $this->assertStringContainsString("['ids' => \$ids, 'with_payload' => false, 'with_vector' => false]", $qdrant, 'Lecture seule, sans vecteur ni payload.');

        // Le détail ne se charge qu'à l'ouverture de « Avancé ».
        $js = self::src(self::FORMJS);
        $this->assertStringContainsString("'/admin/knowledge-bases/' + C.id + '/chunks?'", $js);
        $this->assertStringContainsString("advanced?.addEventListener('toggle'", $js);
    }

    public function testLaFicheSuitSesChamps(): void
    {
        $js = self::src(self::FORMJS);
        // Tout geste de la page, interrupteur « Active » (hors du formulaire) compris.
        $this->assertStringContainsString("document.addEventListener('input',", $js);
        $this->assertStringContainsString("document.addEventListener('change',", $js);
        $this->assertStringContainsString('window.MiaKbDiagram.mount(host, s, conf.catalog, { animate: first, jump: jumpTitles });', $js);
        // Le geste : défilement doux sauf préférence contraire, projecteur, focus sans second défilement.
        $this->assertStringContainsString("el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });", $js);
        $this->assertStringContainsString("el.classList.add('is-spotlit');", $js);
        $this->assertStringContainsString('target.focus({ preventScroll: true });', $js);
        // Les intitulés viennent des titres des sections, sans leur compteur.
        $this->assertStringContainsString("copy.querySelectorAll('.kb-linked-count').forEach((n) => n.remove());", $js);
        foreach (['kbSectionIdentity', 'kbSectionSources', 'kbSectionAssistants', 'kbSectionSharing'] as $section) {
            $this->assertStringContainsString("sectionTitle('$section')", $js);
        }
    }

    public function testLeSeuilCoteACoteEtLesStyles(): void
    {
        $css = self::src(self::CSS);
        $this->assertStringContainsString("window.matchMedia('(min-width: 1200px)')", self::src(self::LIST));
        $this->assertStringContainsString('.kb-content [hidden] { display: none !important; }', $css, 'Des règles display:flex l’emporteraient sur l’attribut hidden.');
        foreach (['.sd-inputs', '.sd-live', '.sd-assistants', '.sd-sharing', '.sd-ava.is-glyph', '.sd-item-ic.is-dot', '.sd-status.is-muted', '.kb-advanced', '.kb-chunk', '.kb-source-tile'] as $classe) {
            $this->assertStringContainsString($classe, $css, "Style absent : $classe");
        }
        $this->assertStringContainsString('.kb-content .is-spotlit', $css);
        // Barre d'actions : la mention d'enregistrement sur sa ligne, jamais un libellé coupé.
        $this->assertStringContainsString('.pf-actions-right .btn-primary, .pf-actions-right .btn-secondary { white-space: nowrap; }', $css);
        $this->assertStringContainsString('.pf-actions-note { flex-basis: 100%; }', $css);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationLeDit(): void
    {
        $this->assertStringContainsString('id: kb-fiche-schema', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('schéma de la base', self::src('docs/GUIDE-CREATEUR.md'));
        $this->assertStringContainsString('Avancé', self::src('docs/user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md'));
        $this->assertStringContainsString('KbDiagram', self::src('CLAUDE.md'));
    }
}
