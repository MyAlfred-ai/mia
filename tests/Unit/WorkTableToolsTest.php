<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Outils des tables de travail (WorkTableTools), sur un magasin en mémoire et
 * de faux services : ce que le modèle reçoit (la poignée, jamais la liste),
 * ce que le serveur garde (toutes les lignes), et un recoupement mené de bout
 * en bout — « quels clients de mon fichier n'ont pas écrit ? ».
 */
final class WorkTableToolsTest extends TestCase
{
    private MemoryWorkTableStore $store;

    /** @var list<array> évènements émis vers l'écran */
    private array $events = [];

    protected function setUp(): void
    {
        $this->store  = new MemoryWorkTableStore();
        $this->events = [];
    }

    private function turn(int $convId = 12): AgentTurn
    {
        return new AgentTurn(
            7,
            $convId,
            ['id' => 7, 'role' => 'user'],
            4000,
            function (array $event): void {
                $this->events[] = $event;
            },
            static fn(string $tool, array $args, ?array $preview = null): array => ['error' => 'aucune écriture attendue'],
            'Europe/Paris'
        );
    }

    /** Faux Gmail : `$count` emails, du plus récent au plus ancien. */
    private static function mailbox(int $count, bool $truncated = false): object
    {
        return new class($count, $truncated) {
            public array $calls = [];
            public function __construct(private int $count, private bool $truncated)
            {
            }
            public function collectMessages(string $query, int $limit, ?\Closure $progress = null): array
            {
                $this->calls[] = [$query, $limit];
                $out = [];
                for ($i = 1; $i <= min($this->count, $limit); $i++) {
                    $out[] = [
                        'id' => 'm' . $i, 'de' => 'Client ' . $i . ' <contact@client' . $i . '.fr>', 'a' => 'moi@ex.fr',
                        'objet' => 'Commande n°' . $i, 'recu' => 1767225600 - $i * 3600, 'extrait' => 'Bonjour, ' . str_repeat('texte ', 60),
                    ];
                    if ($progress !== null) {
                        $progress($i, $this->count);
                    }
                }
                return ['messages' => $out, 'estimation' => $this->count, 'tronque' => $this->truncated || $this->count > $limit, 'echecs' => 0, 'interrompu' => false];
            }
        };
    }

    /** Faux Drive : un dossier de fichiers, et un classeur CSV téléchargeable. */
    private static function drive(): object
    {
        return new class {
            public function collectFiles(string $input, bool $recursive, int $limit, ?\Closure $progress = null): array
            {
                $files = [
                    ['id' => 'f1', 'nom' => 'Devis Acme.pdf', 'mime' => 'application/pdf', 'type' => 'PDF', 'modifie' => '2026-11-01T10:00:00Z', 'taille' => 20480, 'chemin' => '', 'lien' => 'https://drive.google.com/file/d/f1/view'],
                    ['id' => 'f2', 'nom' => 'Contrat Bolt.docx', 'mime' => 'x', 'type' => 'Word', 'modifie' => '2026-10-01T10:00:00Z', 'taille' => null, 'chemin' => 'Contrats/2026', 'lien' => ''],
                ];
                return ['dossier' => ['id' => 'd0', 'nom' => 'Clients'], 'fichiers' => $recursive ? $files : [$files[0]], 'dossiers' => 2, 'tronque' => false, 'interrompu' => false];
            }
            public function downloadFile(string $ref): array
            {
                if ($ref === 'notes.pdf') {
                    return ['id' => 'p', 'nom' => 'notes.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF', 'lien' => ''];
                }
                return ['id' => 's', 'nom' => 'clients.csv', 'mime' => 'text/csv', 'lien' => '',
                    'bytes' => "Nom;Email;Ville\nClient 1;contact@client1.fr;Lyon\nClient 2;contact@client2.fr;Paris\nAbsent;info@absent.fr;Lyon\n"];
            }
        };
    }

    private function tools(array $services, array $attachments = [], int $convId = 12): WorkTableTools
    {
        return new WorkTableTools($this->turn($convId), $this->store, static fn(): array => $services, $attachments);
    }

    private static function call(WorkTableTools $tools, string $name, array $args): array
    {
        return ($tools->handlers()[$name])($args);
    }

    // ---------------------------------------------------------------------
    // 1. Ce qui est exposé
    // ---------------------------------------------------------------------

    public function testLesSourcesSuiventLesConnecteursEngagesEtLesClasseursJoints(): void
    {
        $this->assertSame([], WorkTableTools::sources([], false));
        $this->assertSame(['emails_gmail', 'lignes_piece_jointe'], WorkTableTools::sources(['gmail' => new \stdClass()], true));
        $this->assertSame(['emails_outlook', 'fichiers_drive', 'lignes_fichier_drive'],
            WorkTableTools::sources(['outlook' => new \stdClass(), 'drive' => new \stdClass(), 'slack' => new \stdClass()], false));

        // Sans aucune source, la collecte n'est pas proposée ; le reste sert
        // aux tables déjà construites dans la conversation.
        $names = array_column($this->tools([])->definitions(), 'name');
        $this->assertSame(['ecrire_table', 'croiser_tables', 'transformer_table', 'apercu_table', 'exporter_table'], $names);

        $defs = $this->tools(['gmail' => self::mailbox(1)])->definitions();
        $this->assertSame(WorkTableTools::COLLECT, $defs[0]['name']);
        $this->assertSame(['emails_gmail'], $defs[0]['parameters']['properties']['source']['enum']);
        $this->assertNull($this->tools([])->connector(), 'Pas un connecteur : ni plan ni méthode agent pour lui seul.');
    }

    public function testChaqueDefinitionEstUnSchemaQueTousLesFournisseursAcceptent(): void
    {
        foreach ($this->tools(['gmail' => self::mailbox(1), 'drive' => self::drive()])->definitions() as $def) {
            $this->assertSame('object', $def['parameters']['type'], $def['name']);
            $this->assertNotSame('', trim($def['description']));
            array_walk_recursive($def['parameters'], function ($v, $k) use ($def): void {
                // Gemini refuse un objet libre (sans propriétés) : chaque objet imbriqué déclare les siennes.
                $this->assertNotSame('additionalProperties', $k, $def['name']);
            });
            foreach ($def['parameters']['properties'] as $name => $prop) {
                if (($prop['type'] ?? '') === 'array' && ($prop['items']['type'] ?? '') === 'object') {
                    $this->assertNotEmpty($prop['items']['properties'], $def['name'] . '.' . $name);
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. Collecter : tout en base, la poignée au modèle
    // ---------------------------------------------------------------------

    public function testUneCollecteGardeToutEtNeRendQueLaPoignee(): void
    {
        $gmail = self::mailbox(250);
        $tools = $this->tools(['gmail' => $gmail]);
        $out = self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'Mails Commandes', 'requete' => 'commande', 'max' => 400]);

        $this->assertSame('mails_commandes', $out['table']);
        $this->assertSame(250, $out['lignes']);
        $this->assertSame(['date', 'expediteur', 'email', 'destinataires', 'objet', 'extrait', 'source', 'id'], $out['colonnes']);
        $this->assertCount(WorkTableOps::SAMPLE_ROWS, $out['exemples']);
        $this->assertArrayHasKey('rappel', $out, 'Le modèle est prévenu : trois exemples ne sont pas la table.');
        $this->assertArrayNotHasKey('incomplet', $out);
        $this->assertLessThan(2500, strlen((string)json_encode($out, JSON_UNESCAPED_UNICODE)), 'Le résultat rendu au modèle reste petit, quel que soit le nombre de lignes.');
        $this->assertSame([['commande', 400]], $gmail->calls);

        // La table entière est en base, dans l'ordre, typée.
        $table = $this->store->find(12, 'mails_commandes');
        $this->assertNotNull($table);
        $this->assertSame(250, $table['rows']);
        $rows = $this->store->rows((int)$table['id']);
        $this->assertCount(250, $rows);
        $this->assertSame('contact@client1.fr', $rows[0]['email']);
        $this->assertSame('Client 1', $rows[0]['expediteur']);
        $this->assertSame('Gmail', $rows[0]['source']);
        $this->assertSame('2026-01-01 00:00', $rows[0]['date'], 'Heure de réception dans le fuseau de la personne (23 h UTC = minuit à Paris).');
        $this->assertSame('m250', $rows[249]['id']);
        $this->assertSame('date', $table['columns'][0]['type']);
        $this->assertTrue($table['columns'][7]['tech'], 'L’identifiant est une colonne technique : gardée, pas exportée.');

        $this->assertContains(['phase' => 'table_collect'], $this->events);
    }

    public function testUneCollecteIncompleteLeDit(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(900)]);
        $out = self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        $this->assertSame(WorkTableCollector::EMAILS_DEFAULT, $out['lignes']);
        $this->assertTrue($out['incomplet']);
        $this->assertStringContainsString('max', $out['note']);
    }

    public function testAjouterReunitDeuxCollectesSansDoublon(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(5), 'outlook' => self::mailbox(8)]);
        self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        // Même boîte, relue : rien de neuf.
        $again = self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails', 'ajouter' => 'true']);
        $this->assertSame(5, $again['lignes']);
        $this->assertSame(0, $again['lignes_ajoutees']);
        // Outlook : mêmes identifiants, autre source — ce ne sont pas des doublons.
        $both = self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_outlook', 'table' => 'mails', 'ajouter' => true]);
        $this->assertSame(13, $both['lignes']);
        $this->assertSame(8, $both['lignes_ajoutees']);

        // Sans `ajouter`, le même nom REMPLACE la table.
        $replaced = self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_outlook', 'table' => 'mails']);
        $this->assertSame(8, $replaced['lignes']);
        $this->assertCount(1, $this->store->all(12));

        // Des colonnes différentes ne se réunissent pas.
        $tools = $this->tools(['gmail' => self::mailbox(2), 'drive' => self::drive()]);
        $refus = self::call($tools, WorkTableTools::COLLECT, ['source' => 'fichiers_drive', 'table' => 'mails', 'dossier' => 'Clients', 'ajouter' => true]);
        $this->assertStringContainsString('autres colonnes', $refus['error']);
    }

    public function testUneSourceNonEngageeEstRefuseeEnNommantLeConnecteur(): void
    {
        $out = self::call($this->tools(['gmail' => self::mailbox(1)]), WorkTableTools::COLLECT, ['source' => 'fichiers_drive', 'table' => 't', 'dossier' => 'X']);
        $this->assertStringContainsString('Google Drive', $out['error']);
        $out = self::call($this->tools(['gmail' => self::mailbox(1)]), WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => ' — ']);
        $this->assertStringContainsString('nom court', $out['error']);
        $this->assertSame([], $this->store->all(12));
    }

    public function testFichiersDUnDossierEtLignesDUnClasseur(): void
    {
        $tools = $this->tools(['drive' => self::drive()]);
        $files = self::call($tools, WorkTableTools::COLLECT, ['source' => 'fichiers_drive', 'table' => 'docs', 'dossier' => 'Clients', 'sous_dossiers' => true]);
        $this->assertSame(2, $files['lignes']);
        $rows = $this->store->rows((int)$this->store->find(12, 'docs')['id']);
        $this->assertSame('Clients/Contrats/2026', $rows[1]['dossier']);
        $this->assertSame('20', $rows[0]['taille_ko']);
        $this->assertSame('2026-11-01 11:00', $rows[0]['modifie_le']);

        $filtered = self::call($tools, WorkTableTools::COLLECT, ['source' => 'fichiers_drive', 'table' => 'devis', 'dossier' => 'Clients', 'sous_dossiers' => true, 'requete' => 'DEVIS']);
        $this->assertSame(1, $filtered['lignes']);

        $sheet = self::call($tools, WorkTableTools::COLLECT, ['source' => 'lignes_fichier_drive', 'table' => 'clients', 'fichier' => 'clients.csv']);
        $this->assertSame(3, $sheet['lignes']);
        $this->assertSame(['nom', 'email', 'ville'], $sheet['colonnes']);

        $notSheet = self::call($tools, WorkTableTools::COLLECT, ['source' => 'lignes_fichier_drive', 'table' => 'x', 'fichier' => 'notes.pdf']);
        $this->assertStringContainsString('pas un classeur', $notSheet['error']);
    }

    public function testUnClasseurJointSeLitDepuisLaConversation(): void
    {
        $history = [
            ['id' => 1, 'files_json' => json_encode([['name' => 'photo.png', 'type' => 'image/png', 'extracted' => 'AAAA']])],
            ['id' => 2, 'files_json' => json_encode([[
                'name' => 'Fournisseurs.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extracted' => "# sheet1.xml\nNom\tPays\nAlpha\tFrance\nBeta\tItalie\n\n# sheet2.xml\nA\tB\n1\t2",
            ]])],
        ];
        $attachments = WorkTableCollector::sheetAttachments($history);
        $this->assertSame(['Fournisseurs.xlsx'], array_column($attachments, 'nom'), 'Seuls les classeurs comptent.');

        $tools = $this->tools([], $attachments);
        $this->assertSame(['lignes_piece_jointe'], $tools->definitions()[0]['parameters']['properties']['source']['enum']);
        $out = self::call($tools, WorkTableTools::COLLECT, ['source' => 'lignes_piece_jointe', 'table' => 'fournisseurs']);
        $this->assertSame(2, $out['lignes']);
        $this->assertSame(['nom', 'pays'], $out['colonnes']);
        $this->assertSame('Italie', $this->store->rows((int)$this->store->find(12, 'fournisseurs')['id'])[1]['pays']);

        $bad = self::call($tools, WorkTableTools::COLLECT, ['source' => 'lignes_piece_jointe', 'table' => 'x', 'fichier' => 'budget']);
        $this->assertStringContainsString('1. Fournisseurs.xlsx', $bad['error'], 'Un fichier introuvable : ceux qui existent sont nommés.');
    }

    // ---------------------------------------------------------------------
    // 3. Écrire soi-même une liste
    // ---------------------------------------------------------------------

    public function testEcrireUneTablePuisYAjouter(): void
    {
        $tools = $this->tools([]);
        $out = self::call($tools, WorkTableTools::WRITE, [
            'table' => 'a_relancer', 'colonnes' => ['Nom', 'Motif'], 'lignes' => [['Acme', 'Devis sans réponse'], ['Bolt', 'Facture impayée']],
        ]);
        $this->assertSame(2, $out['lignes']);
        $this->assertArrayNotHasKey('exemples', $out, 'Le modèle vient d’écrire ces lignes : inutile de les lui rendre.');

        // Ajout, avec une colonne de plus et des lignes en JSON (certains modèles les passent ainsi).
        $more = self::call($tools, WorkTableTools::WRITE, [
            'table' => 'a_relancer', 'ajouter' => true, 'colonnes' => ['Nom', 'Priorité'], 'lignes' => '[["Cirrus","haute"]]',
        ]);
        $this->assertSame(3, $more['lignes']);
        $this->assertSame(['nom', 'motif', 'priorite'], $more['colonnes']);
        $rows = $this->store->rows((int)$this->store->find(12, 'a_relancer')['id']);
        $this->assertSame(['nom' => 'Cirrus', 'motif' => '', 'priorite' => 'haute'], $rows[2]);
        $this->assertSame('', $rows[0]['priorite']);

        $this->assertStringContainsString('colonnes', self::call($tools, WorkTableTools::WRITE, ['table' => 'neuve', 'lignes' => [['a']]])['error']);
        $tooMany = self::call($tools, WorkTableTools::WRITE, ['table' => 'x', 'colonnes' => ['A'], 'lignes' => array_fill(0, WorkTableOps::WRITE_ROWS_MAX + 1, ['v'])]);
        $this->assertStringContainsString((string)WorkTableOps::WRITE_ROWS_MAX, $tooMany['error']);
    }

    // ---------------------------------------------------------------------
    // 4. Le recoupement de bout en bout
    // ---------------------------------------------------------------------

    public function testQuelsClientsDuFichierNOntPasEcrit(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(40), 'drive' => self::drive()]);
        self::call($tools, WorkTableTools::COLLECT, ['source' => 'lignes_fichier_drive', 'table' => 'clients', 'fichier' => 'clients.csv']);
        self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);

        // Qui manque : le serveur rapproche, le modèle lit les compteurs.
        $missing = self::call($tools, WorkTableTools::JOIN, [
            'gauche' => 'clients', 'droite' => 'mails', 'cle_gauche' => 'Email', 'cle_droite' => 'email',
            'comparaison' => 'email', 'garder' => 'sans_correspondance', 'vers' => 'sans_mail',
        ]);
        $this->assertSame(1, $missing['lignes']);
        $this->assertSame(['lignes_gauche' => 3, 'lignes_droite' => 40, 'gauche_avec_correspondance' => 2,
            'gauche_sans_correspondance' => 1, 'droite_sans_correspondance' => 38, 'paires' => 2], $missing['compteurs']);
        $this->assertSame('Absent', $missing['exemples'][0]['nom']);

        // Par client : nombre de mails et dernier objet.
        $per = self::call($tools, WorkTableTools::JOIN, [
            'gauche' => 'clients', 'droite' => 'mails', 'cle_gauche' => 'email', 'cle_droite' => 'email',
            'comparaison' => 'email', 'garder' => 'tout_gauche', 'une_ligne_par' => 'gauche', 'vers' => 'suivi',
        ]);
        $this->assertSame(3, $per['lignes']);
        $suivi = $this->store->rows((int)$this->store->find(12, 'suivi')['id']);
        $this->assertSame(['1', '1', '0'], array_column($suivi, 'nb_correspondances'));
        $this->assertSame('Commande n°2', $suivi[1]['objet']);

        // Filtrer, regrouper, trier : « combien par ville, parmi ceux qui ont écrit ? »
        $byCity = self::call($tools, WorkTableTools::TRANSFORM, [
            'table' => 'suivi', 'vers' => 'par_ville',
            'filtres' => [['colonne' => 'nb_correspondances', 'test' => 'superieur', 'valeur' => '0']],
            'regrouper_par' => ['ville'], 'calculs' => [['operation' => 'compte', 'nom' => 'Clients']],
            'trier_par' => [['colonne' => 'Clients', 'sens' => 'decroissant']],
        ]);
        $this->assertSame(2, $byCity['lignes']);
        $this->assertSame(3, $byCity['lignes_avant']);
        $this->assertSame(['ville', 'clients'], $byCity['colonnes']);

        // Lire une page, puis remettre le fichier.
        $view = self::call($tools, WorkTableTools::VIEW, ['table' => 'mails', 'debut' => 39, 'lignes' => 10, 'colonnes' => ['objet', 'email']]);
        $this->assertSame(40, $view['lignes']);
        $this->assertSame([39, 40], [$view['de'], $view['a']]);
        $this->assertSame(['objet', 'email'], array_keys($view['contenu'][0]));
        $this->assertArrayNotHasKey('suite', $view);
        $first = self::call($tools, WorkTableTools::VIEW, ['table' => 'mails']);
        $this->assertCount(WorkTableOps::PREVIEW_ROWS_DEFAULT, $first['contenu']);
        $this->assertStringContainsString('debut=21', $first['suite']);

        $list = self::call($tools, WorkTableTools::VIEW, []);
        $this->assertEqualsCanonicalizing(['clients', 'mails', 'sans_mail', 'suivi', 'par_ville'], array_column($list['tables'], 'table'));

        $export = self::call($tools, WorkTableTools::EXPORT, ['table' => 'sans_mail', 'nom' => 'Clients sans mail']);
        $this->assertSame('clients-sans-mail.xlsx', $export['fichier']);
        $id = (int)$this->store->find(12, 'sans_mail')['id'];
        $this->assertSame('/chat/12/tables/' . $id . '/clients-sans-mail.xlsx', $export['lien']);
        $this->assertStringContainsString('[clients-sans-mail.xlsx](' . $export['lien'] . ')', $export['a_faire']);
    }

    public function testUneTableOuUneColonneInconnueNommeCeQuiExiste(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(3)]);
        $none = self::call($tools, WorkTableTools::VIEW, ['table' => 'clients']);
        $this->assertStringContainsString('Aucune table', $none['error']);

        self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        $unknown = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients', 'vers' => 'x']);
        $this->assertStringContainsString('Tables de la conversation : mails', $unknown['error']);
        $column = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'mails', 'vers' => 'x', 'filtres' => [['colonne' => 'ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        $this->assertStringContainsString('expediteur', $column['error']);
        $this->assertNull($this->store->find(12, 'x'), 'Une transformation refusée n’écrit rien.');

        $mode = self::call($tools, WorkTableTools::JOIN, ['gauche' => 'mails', 'droite' => 'mails', 'cle_gauche' => 'email', 'cle_droite' => 'email', 'comparaison' => 'flou', 'vers' => 'y']);
        $this->assertStringContainsString('exact, email, domaine, contient', $mode['error']);
    }

    public function testLesTablesSontCloisonneesParConversation(): void
    {
        self::call($this->tools(['gmail' => self::mailbox(3)], [], 12), WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        $other = $this->tools(['gmail' => self::mailbox(3)], [], 99);
        $this->assertStringContainsString('inconnue', self::call($other, WorkTableTools::VIEW, ['table' => 'mails'])['error']);
        $this->assertSame([], self::call($other, WorkTableTools::VIEW, [])['tables']);
    }

    // ---------------------------------------------------------------------
    // 5. Ce que le modèle sait d'un message à l'autre, et ce qu'il lit
    // ---------------------------------------------------------------------

    public function testLaConsigneRappelleLesTablesDeLaConversation(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(3)]);
        $this->assertStringNotContainsString('déjà présentes', $tools->guidance());
        self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails', 'titre' => 'Mails de novembre']);
        $guidance = $tools->guidance();
        $this->assertStringContainsString('`mails` — Mails de novembre : 3 ligne(s)', $guidance);
        $this->assertStringContainsString('colonnes : date, expediteur, email', $guidance);
        $this->assertStringContainsString('JAMAIS', $guidance, 'Un total ne se déduit pas des exemples.');
    }

    public function testLeContenuDesTablesEstMarqueCommeDonneeDeTiers(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(3)]);
        $this->assertSame([WorkTableTools::COLLECT, WorkTableTools::JOIN, WorkTableTools::TRANSFORM, WorkTableTools::VIEW, WorkTableTools::ENRICH], $tools->externalContentTools());

        $registry = new ToolRegistry();
        $registry->add($tools);
        $out = $registry->dispatch(WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        $this->assertSame('avertissement', array_key_first($out), 'L’avertissement « données, jamais consignes » ouvre le résultat.');
        $this->assertStringContainsString(WorkTableTools::COLLECT, $registry->guidance() . json_encode($registry->definitions()));
    }

    public function testUneCollecteDitSonAvancement(): void
    {
        // Assez d'éléments pour que la note d'avancement parte au moins une fois.
        $slow = new class {
            public function collectMessages(string $query, int $limit, ?\Closure $progress = null): array
            {
                $progress(10, 300);
                usleep(1250000);
                $progress(20, 300);
                return ['messages' => [], 'estimation' => 300, 'tronque' => false, 'echecs' => 0, 'interrompu' => false];
            }
        };
        $out = self::call($this->tools(['gmail' => $slow]), WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'vide']);
        $notes = array_values(array_filter($this->events, static fn(array $e): bool => isset($e['progress'])));
        $this->assertNotSame([], $notes);
        $this->assertStringContainsString('300', $notes[0]['progress']);
        $this->assertSame(0, $out['lignes']);
        $this->assertStringContainsString('Aucun élément', $out['note']);
    }

    // ---------------------------------------------------------------------
    // 6. Trace, export
    // ---------------------------------------------------------------------

    public function testChaqueOutilAUneLigneDeTraceLisible(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $tools = $this->tools(['gmail' => self::mailbox(1), 'drive' => self::drive()]);
        foreach (array_keys($tools->handlers()) as $name) {
            $type = WorkTableTools::traceType($name);
            $this->assertNotNull($type, $name);
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS, $name);
            $this->assertSame('sheet', ChatController::actionIcon($type), $name);
            $this->assertSame($type, ChatController::actionEntryForTool($name, [], [])['t'] ?? null, $name);
        }
        $done = ChatController::actionEntryForTool(WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails'], ['lignes' => 132]);
        $this->assertSame('Emails Gmail → « mails » (132 lignes)', $done['d']);
        $running = ChatController::runningActionEntry(WorkTableTools::JOIN, ['gauche' => 'clients', 'droite' => 'mails', 'vers' => 'suivi']);
        $this->assertSame('clients × mails → « suivi »', $running['d']);
        $this->assertSame('clients-sans-mail.xlsx', WorkTableTools::traceDetail(WorkTableTools::EXPORT, ['table' => 'sans_mail'], ['fichier' => 'clients-sans-mail.xlsx']));
        $this->assertNull(WorkTableTools::traceDetail(WorkTableTools::VIEW, [], []));
    }

    public function testLExportFabriqueUnFichierLisibleSansColonneTechnique(): void
    {
        $tools = $this->tools(['gmail' => self::mailbox(4)]);
        self::call($tools, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails']);
        $table = $this->store->find(12, 'mails');
        $rows  = $this->store->rows((int)$table['id']);

        $csv = WorkTableExport::build($table, $rows, 'mails.csv', 'fr');
        $this->assertSame('mails.csv', $csv->name);
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Date\";\"Expéditeur\"", $csv->bytes, 'BOM + libellés : Excel lit les accents, séparateur « ; » en français.');
        $this->assertStringNotContainsString('Identifiant', $csv->bytes);
        $this->assertSame(5, substr_count($csv->bytes, "\r\n"));
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Date\",", WorkTableExport::build($table, $rows, 'mails.csv', 'en')->bytes);

        // Excel si la bibliothèque est là ; sinon un CSV, jamais un refus.
        $xlsx = WorkTableExport::build($table, $rows, 'mails.xlsx', 'fr');
        if (FileLibrary::has(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->assertSame('mails.xlsx', $xlsx->name);
            $this->assertStringStartsWith('PK', $xlsx->bytes);
        } else {
            $this->assertSame('mails.csv', $xlsx->name);
        }

        $this->assertTrue(WorkTableExport::isFileName('clients-sans-mail.xlsx'));
        $this->assertFalse(WorkTableExport::isFileName('../secret.xlsx'));
        $this->assertFalse(WorkTableExport::isFileName('table.exe'));
        $this->assertSame('table.xlsx', WorkTableExport::fileName(['name' => ''], ' — ', 'xlsx'));
        $this->assertFalse(WorkTableExport::fitsXlsx($table['columns'], 60000, 1000), 'Trop de cellules pour un classeur fabriqué en mémoire.');

        // Une table vide ne s'exporte pas : rien à remettre.
        $empty = $this->tools(['gmail' => self::mailbox(0)]);
        self::call($empty, WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'rien']);
        $this->assertStringContainsString('vide', self::call($empty, WorkTableTools::EXPORT, ['table' => 'rien'])['error']);
    }
}
