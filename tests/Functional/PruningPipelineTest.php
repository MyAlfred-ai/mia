<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Élagage (/admin/pruning) de bout en bout, sur base réelle.
 *
 * Ce que ces tests protègent : une purge ne doit JAMAIS emporter ce qui est
 * protégé (conversation épinglée, conversation encore vivante, génération en
 * cours), ne doit JAMAIS laisser un fil troué ou une image morte, et doit
 * pouvoir être relancée sans rien refaire.
 */
final class PruningPipelineTest extends DatabaseTestCase
{
    private const VIEUX  = 200;   // jours
    private const RECENT = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec("DELETE FROM settings WHERE `key` LIKE 'pruning_%'");
        Settings::refresh();
        PruningService::invaliderCache();
    }

    // ------------------------------------------------------------------
    // Conversations
    // ------------------------------------------------------------------

    public function testSeulesLesConversationsAbandonneesSontSupprimees(): void
    {
        $this->insertUser(1);

        $abandonnee = $this->conversation('Abandonnée', self::VIEUX);
        $this->message($abandonnee, 'user', 'vieille question', self::VIEUX);

        $epinglee = $this->conversation('Épinglée', self::VIEUX, pinned: true);
        $this->message($epinglee, 'user', 'à garder', self::VIEUX);

        $recente = $this->conversation('Récente', self::RECENT);
        $this->message($recente, 'user', 'hier', self::RECENT);

        // updated_at ancien mais un message récent : le filet SQL doit la sauver
        // (touch() a pu être oublié sur un chemin d'écriture).
        $vivante = $this->conversation('Vivante', self::VIEUX);
        $this->message($vivante, 'user', 'message récent', self::RECENT);

        // Génération en vol : la supprimer ferait disparaître la ligne de job
        // sous les pieds du worker.
        $enCours = $this->conversation('Génération en cours', self::VIEUX);
        $this->message($enCours, 'user', 'génère-moi une image', self::VIEUX);
        $this->job($enCours, 'running');

        $rapport = PruningService::elaguerConversations(120);

        $this->assertTrue($rapport['verrou']);
        $this->assertSame(1, $rapport['supprimees']);
        $this->assertSame(0, $rapport['restantes']);

        $restantes = $this->ids($this->db()->query('SELECT id FROM conversations ORDER BY id')->fetchAll());
        $this->assertSame([$epinglee, $recente, $vivante, $enCours], $restantes);
    }

    /** Les messages partent en cascade, l'historique de consommation reste. */
    public function testLaCascadeNettoieLesMessagesMaisPasLaConsommation(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('À élaguer', self::VIEUX);
        $msg  = $this->message($conv, 'assistant', 'réponse', self::VIEUX);

        $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, conversation_id, message_id, llm_provider, model,
                                     tokens_input, tokens_output, credits_used)
             VALUES (1, ?, ?, "openai", "gpt-5.5", 100, 200, 42)'
        )->execute([$conv, $msg]);

        PruningService::elaguerConversations(120);

        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM messages')->fetchColumn());
        $ligne = $this->db()->query('SELECT * FROM usage_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($ligne, 'La trace de consommation ne doit pas disparaître.');
        $this->assertNull($ligne['conversation_id']);
        $this->assertNull($ligne['message_id']);
    }

    // ------------------------------------------------------------------
    // Créations
    // ------------------------------------------------------------------

    public function testUneCreationPureDevientUnMarqueurEtPerdSonPartage(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Avec page', self::VIEUX);

        $page = $this->message(
            $conv,
            'assistant',
            '<!DOCTYPE html><html><head><title>Ma page</title></head><body>…</body></html>',
            self::VIEUX,
            files: [['type' => 'html_page', 'name' => 'page.html']],
            fileName: 'page.html',
            fileType: 'html_page'
        );
        $this->db()->prepare('INSERT INTO creation_shares (message_id, created_by, token) VALUES (?,1,?)')
            ->execute([$page, str_repeat('a', 64)]);

        $rapport = PruningService::elaguerCreations(120);

        $this->assertSame(1, $rapport['neutralisees']);
        $this->assertSame(1, $rapport['partages']);

        $ligne = $this->messageRow($page);
        $this->assertStringContainsString('Création élaguée', $ligne['content']);
        $this->assertNull($ligne['files_json']);
        $this->assertNull($ligne['file_name'], 'Colonne legacy laissée : carte fantôme au rendu.');
        $this->assertNull($ligne['file_type'], 'Colonne legacy laissée : re-détectée comme création.');
        $this->assertNull($ligne['file_extracted']);

        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM creation_shares')->fetchColumn());
        $this->assertNull(Message::creationType($ligne), 'Le marqueur reste détecté comme une création.');
    }

    public function testUneCreationRecenteEstEpargnee(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Deck récent', self::RECENT);
        $deck = $this->message($conv, 'assistant', '{"title":"Mon deck","slides":[]}', self::RECENT,
            files: [['type' => 'slide_deck', 'name' => 'presentation.json']]);

        $rapport = PruningService::elaguerCreations(120);

        $this->assertSame(0, $rapport['neutralisees']);
        $this->assertStringContainsString('Mon deck', $this->messageRow($deck)['content']);
    }

    /** Le cas qui justifie toute la chirurgie : la réponse rédigée doit survivre. */
    public function testUnMessageMixteGardeSaProseEtPerdSesImages(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Prose et images', self::VIEUX);

        $contenu = "Voici deux propositions.\n\n"
            . "![Chat](/assets/img/generated/aaa.png)\n\n"
            . "La seconde est plus sobre :\n\n"
            . "![Chien](/assets/img/generated/bbb.png)\n\n"
            . "Dites-moi laquelle vous préférez.";
        $mixte = $this->message($conv, 'assistant', $contenu, self::VIEUX);

        $rapport = PruningService::elaguerCreations(120);

        $this->assertSame(0, $rapport['neutralisees'], 'Un message mixte ne doit pas être remplacé en entier.');
        $this->assertSame(1, $rapport['images']);

        $apres = $this->messageRow($mixte)['content'];
        $this->assertStringContainsString('Voici deux propositions.', $apres);
        $this->assertStringContainsString('Dites-moi laquelle vous préférez.', $apres);
        $this->assertStringNotContainsString('/assets/img/generated/', $apres);
        $this->assertSame(2, substr_count($apres, 'Image élaguée'));
    }

    /** Une image SEULE est une création à part entière : remplacement intégral. */
    public function testUneImageSeuleEstRemplaceeEnEntier(): void
    {
        $this->insertUser(1);
        $conv  = $this->conversation('Image seule', self::VIEUX);
        $image = $this->message($conv, 'assistant', '![Image générée](/assets/img/generated/ccc.png)', self::VIEUX);

        $rapport = PruningService::elaguerCreations(120);

        $this->assertSame(1, $rapport['neutralisees']);
        $this->assertStringContainsString('Création élaguée', $this->messageRow($image)['content']);
    }

    public function testUnMessageTexteNEstJamaisTouche(): void
    {
        $this->insertUser(1);
        $conv  = $this->conversation('Texte', self::VIEUX);
        $texte = $this->message($conv, 'assistant', 'Une réponse sans la moindre création.', self::VIEUX);

        PruningService::elaguerCreations(120);

        $this->assertSame('Une réponse sans la moindre création.', $this->messageRow($texte)['content']);
    }

    /** Relancé, l'élagage ne doit plus rien trouver : sinon il bouclerait. */
    public function testLElagageDesCreationsEstIdempotent(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Mélange', self::VIEUX);
        $this->message($conv, 'assistant', '<html><title>P</title></html>', self::VIEUX,
            files: [['type' => 'html_page', 'name' => 'p.html']]);
        $this->message($conv, 'assistant', "Prose.\n\n![X](/assets/img/generated/ddd.png)", self::VIEUX);

        $premier = PruningService::elaguerCreations(120);
        $this->assertSame(1, $premier['neutralisees']);
        $this->assertSame(1, $premier['images']);

        $second = PruningService::elaguerCreations(120);
        $this->assertSame(0, $second['neutralisees']);
        $this->assertSame(0, $second['images']);
    }

    /** La galerie « Mes créations » ne doit plus lister ce qui a été élagué. */
    public function testLaGalerieNeListePlusLesCreationsElaguees(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Galerie', self::VIEUX);
        $this->message($conv, 'assistant', '<html><title>Vieille</title></html>', self::VIEUX,
            files: [['type' => 'html_page', 'name' => 'v.html']]);

        $recente = $this->conversation('Galerie récente', self::RECENT);
        $this->message($recente, 'assistant', '<html><title>Neuve</title></html>', self::RECENT,
            files: [['type' => 'html_page', 'name' => 'n.html']]);

        $this->assertCount(2, Message::creationsForUser(1));

        PruningService::elaguerCreations(120);

        $restantes = Message::creationsForUser(1);
        $this->assertCount(1, $restantes);
        $this->assertStringContainsString('Neuve', $restantes[0]['content']);
    }

    // ------------------------------------------------------------------
    // Balayage des orphelins
    // ------------------------------------------------------------------

    public function testLeBalayageEpargneLeReferenceEtLeTropJeune(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Avec média', self::RECENT);
        $this->message($conv, 'assistant', '![Vu](/assets/img/generated/reference.png)', self::RECENT);

        $dossier = $this->dossierTemporaire();
        $this->fichier($dossier, 'reference.png', vieux: true);   // référencé  → gardé
        $this->fichier($dossier, 'orphelin.png',  vieux: true);   // orphelin   → supprimé
        $this->fichier($dossier, 'tout-neuf.png', vieux: false);  // < 24 h     → gardé

        $rapport = PruningService::balayerOrphelins([$dossier]);

        $this->assertSame(1, $rapport['supprimes']);
        $this->assertSame(0, $rapport['echecs']);
        $this->assertSame(1, $rapport['jeunes']);
        $this->assertFileDoesNotExist($dossier . '/orphelin.png');
        $this->assertFileExists($dossier . '/reference.png');
        $this->assertFileExists($dossier . '/tout-neuf.png');
    }

    /**
     * L'image de référence d'une vidéo n'est citée QUE dans le payload du job.
     * Worker arrêté un week-end → sans cette lecture, elle serait balayée sous
     * les pieds de la génération.
     */
    public function testUnFichierCiteParUnJobEnFileEstEpargne(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Vidéo en attente', self::RECENT);
        $this->db()->prepare(
            'INSERT INTO async_jobs (type, user_id, conversation_id, status, payload_json)
             VALUES ("video", 1, ?, "pending", ?)'
        )->execute([$conv, json_encode(['image_path' => '/storage/uploads/videoref_zzz.png'])]);

        $dossier = $this->dossierTemporaire();
        $this->fichier($dossier, 'videoref_zzz.png', vieux: true);

        $rapport = PruningService::balayerOrphelins([$dossier]);

        $this->assertSame(0, $rapport['supprimes']);
        $this->assertFileExists($dossier . '/videoref_zzz.png');
    }

    /** Les fichiers d'une conversation élaguée deviennent des orphelins ordinaires. */
    public function testLesMediasDUneConversationElagueeSontBalayes(): void
    {
        $this->insertUser(1);
        $conv = $this->conversation('Avec image', self::VIEUX);
        $this->message($conv, 'assistant', '![X](/assets/img/generated/aelaguer.png)', self::VIEUX);

        $dossier = $this->dossierTemporaire();
        $this->fichier($dossier, 'aelaguer.png', vieux: true);

        // Tant que la conversation vit, le fichier est référencé.
        $this->assertSame(0, PruningService::balayerOrphelins([$dossier])['supprimes']);

        PruningService::elaguerConversations(120);

        $this->assertSame(1, PruningService::balayerOrphelins([$dossier])['supprimes']);
        $this->assertFileDoesNotExist($dossier . '/aelaguer.png');
    }

    // ------------------------------------------------------------------
    // Statistiques
    // ------------------------------------------------------------------

    public function testLesStatistiquesComptentLEligibleEtLeProtege(): void
    {
        $this->insertUser(1);
        $vieille = $this->conversation('Vieille', self::VIEUX);
        $this->message($vieille, 'user', 'question', self::VIEUX);
        $epinglee = $this->conversation('Épinglée', self::VIEUX, pinned: true);
        $this->message($epinglee, 'user', 'question', self::VIEUX);
        $this->message($this->conversation('Récente', self::RECENT), 'user', 'question', self::RECENT);

        $stats = PruningService::statistiques(true);

        $this->assertSame(3, $stats['conversations']['total']);
        $this->assertSame(1, $stats['conversations']['epinglees']);
        $this->assertSame(1, $stats['conversations']['eligibles']);
        $this->assertGreaterThan(0, $stats['conversations']['octets']);
        $this->assertSame(120, $stats['jours_conversations']);
    }

    // ------------------------------------------------------------------
    // Semis
    // ------------------------------------------------------------------

    private function conversation(string $titre, int $ageJours, bool $pinned = false): int
    {
        $date = date('Y-m-d H:i:s', time() - $ageJours * 86400);
        $this->db()->prepare(
            'INSERT INTO conversations (user_id, title, created_at, updated_at, is_pinned, pinned_at)
             VALUES (1, ?, ?, ?, ?, ?)'
        )->execute([$titre, $date, $date, $pinned ? 1 : 0, $pinned ? $date : null]);

        return (int)$this->db()->lastInsertId();
    }

    private function message(
        int $convId,
        string $role,
        string $contenu,
        int $ageJours,
        array $files = [],
        ?string $fileName = null,
        ?string $fileType = null
    ): int {
        $date = date('Y-m-d H:i:s', time() - $ageJours * 86400);
        $this->db()->prepare(
            'INSERT INTO messages (conversation_id, role, content, files_json, file_name, file_type, created_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $convId, $role, $contenu,
            $files === [] ? null : json_encode($files),
            $fileName, $fileType, $date,
        ]);

        return (int)$this->db()->lastInsertId();
    }

    private function job(int $convId, string $statut): void
    {
        $this->db()->prepare(
            'INSERT INTO async_jobs (type, user_id, conversation_id, status, payload_json)
             VALUES ("chat", 1, ?, ?, "{}")'
        )->execute([$convId, $statut]);
    }

    private function messageRow(int $id): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @var list<string> */
    private array $dossiers = [];

    private function dossierTemporaire(): string
    {
        $dossier = sys_get_temp_dir() . '/mia-pruning-' . bin2hex(random_bytes(5));
        @mkdir($dossier, 0775, true);
        $this->dossiers[] = $dossier;
        return $dossier;
    }

    private function fichier(string $dossier, string $nom, bool $vieux): void
    {
        $chemin = $dossier . '/' . $nom;
        file_put_contents($chemin, str_repeat('x', 512));
        if ($vieux) {
            touch($chemin, time() - PruningService::AGE_MIN_ORPHELIN_SEC - 3600);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dossiers as $dossier) {
            foreach ((array)glob($dossier . '/*') as $fichier) {
                @unlink((string)$fichier);
            }
            @rmdir($dossier);
        }
        $this->dossiers = [];
        parent::tearDown();
    }
}
