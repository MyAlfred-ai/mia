<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Workflows et tâches programmées demandés dans une conversation : ce que les
 * modèles écrivent VRAIMENT (banc réel GPT-5.5 / GPT-6 Luna, 2026-11-05) doit
 * donner le workflow ou la tâche demandés — cf. CLAUDE.md « Workflows » et
 * « Tâches programmées ».
 *
 * Défauts constatés sur ce banc, chacun gardé ici :
 * - l'assistant recopié tel que listé (« 4. Juriste », « aucun (MIA) ») →
 *   introuvable, étape confiée à MIA ;
 * - « [Fiche.Champ] » laissé en clair ou désignant la fiche entière (noms
 *   tronqués à 24 caractères, qui se confondaient aussi entre eux) ;
 * - `"evenement": {"type": "appel"}` écrit pour « lancement à la main » →
 *   workflow déclenché par une adresse web, jamais publié ;
 * - programmation et évènement sans fuseau → l'heure du serveur (UTC) ;
 * - filtre « Montant dû > 0 » sur une colonne restée texte → tout écarté ;
 * - « toutes les deux semaines » : l'outil de tâche n'était même pas exposé ;
 * - une tâche qui prépare un brouillon d'email sans messagerie dans ses sources.
 */
final class ChatDraftCombinationsTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function choices(): array
    {
        $src = static fn(string $label): array => ['label' => $label, 'connected' => true];
        return [
            'profiles'    => [
                4 => ['name' => 'Juriste', 'description' => 'Relit les contrats', 'default_llm' => ''],
                5 => ['name' => 'Commercial', 'description' => 'Prépare les devis', 'default_llm' => ''],
            ],
            'llms'        => ['openai::gpt-5.6' => 'GPT-5.6'],
            'tool_llms'   => ['openai::gpt-5.6' => true],
            'default_llm' => 'openai::gpt-5.6',
            'sources'     => ['web' => $src('Internet'), 'gmail' => $src('Gmail'), 'calendar' => $src('Agenda Google'), 'drive' => $src('Google Drive')],
            'kbs'         => [],
            'tools'       => [],
            'auto'        => true,
        ];
    }

    private static function draft(array $args): array
    {
        return WorkflowDraft::fromArgs($args + ['nom' => 'Essai', 'objectif' => 'x'], self::choices());
    }

    // ------------------------------------------------------------------
    // Assistant désigné
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: ?int}> */
    public static function assistants(): iterable
    {
        yield 'numéro'                  => ['4', 4];
        yield 'nom'                     => ['Commercial', 5];
        yield 'ligne de liste'          => ['4. Juriste', 4];
        yield 'ligne de liste, tiret'   => ['5 — Commercial', 5];
        yield 'ligne de liste, égal'    => ['4 = « Juriste »', 4];
        yield 'dièse'                   => ['#5', 5];
        yield 'nom et numéro'           => ['Juriste (4)', 4];
        yield 'nom et description'      => ['Commercial — Prépare les devis', 5];
        yield 'numéro faux, nom juste'  => ['9. Juriste', 4];
        yield 'aucun (MIA)'             => ['aucun (MIA)', 0];
        yield 'MIA'                     => ['MIA', 0];
        yield 'sans assistant'          => ['sans assistant', 0];
        yield 'vide'                    => ['', 0];
        yield 'inconnu'                 => ['Comptable', null];
        yield 'numéro inconnu'          => ['12', null];
    }

    #[DataProvider('assistants')]
    public function testLAssistantSeReconnaitSousLesFormesQueLeModeleEcrit(string $ref, ?int $attendu): void
    {
        $this->assertSame($attendu, WorkflowDraft::assistantId($ref, self::choices()['profiles']), $ref);
    }

    public function testUnAssistantNommeAssistantResteUnAssistant(): void
    {
        $this->assertSame(9, WorkflowDraft::assistantId('Assistant', [9 => ['name' => 'Assistant']]));
    }

    public function testLEtapeRecoitLAssistantDeLaLigneRecopiee(): void
    {
        $d = self::draft(['briques' => [
            ['type' => 'etape', 'titre' => 'Analyser', 'assistant' => '4. Juriste', 'consigne' => 'Analyse.', 'piece' => ['nom' => 'Analyse']],
            ['type' => 'etape', 'titre' => 'Chiffrer', 'assistant' => 'aucun (MIA)', 'consigne' => 'Chiffre.', 'piece' => ['nom' => 'Prix']],
        ]]);
        $this->assertSame(4, $d['definition']['nodes'][0]['profile_id']);
        $this->assertNull($d['definition']['nodes'][1]['profile_id']);
        $this->assertSame([], $d['notes'], 'Aucun « assistant introuvable ».');
    }

    // ------------------------------------------------------------------
    // Pièces et champs cités
    // ------------------------------------------------------------------

    public function testDeuxNomsLongsQuiCommencentPareilRestentDistincts(): void
    {
        $d = self::draft(['briques' => [
            ['type' => 'etape', 'titre' => 'Rédiger', 'consigne' => 'Rédige.', 'piece' => ['nom' => 'Proposition commerciale chiffrée']],
            ['type' => 'etape', 'titre' => 'Réviser', 'consigne' => 'Révise [Proposition commerciale chiffrée].', 'piece' => ['nom' => 'Proposition commerciale révisée']],
            ['type' => 'etape', 'titre' => 'Envoyer', 'consigne' => 'Résume [Proposition commerciale chiffrée] puis [Proposition commerciale révisée].', 'piece' => ['nom' => 'Résumé']],
        ]]);
        [$a, $b, $c] = $d['definition']['nodes'];
        $this->assertNotSame($a['output']['key'], $b['output']['key']);
        $this->assertSame('Résume @{' . $a['output']['key'] . '} puis @{' . $b['output']['key'] . '}.', $c['instruction']);
    }

    public function testUnChampDeFicheCiteDevientCeChamp(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Informations de départ', 'champs' => [
                ['nom' => 'Client', 'type' => 'texte'], ['nom' => 'Email client', 'type' => 'email'],
            ]],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Préparer', 'consigne' => 'Pour [Client], prépare [Informations de départ].',
                    'piece' => ['nom' => 'Email de relance préparé', 'forme' => 'fiche', 'champs' => [
                        ['nom' => 'Destinataire', 'type' => 'email'], ['nom' => 'Objet', 'type' => 'texte'],
                    ]]],
                ['type' => 'action', 'titre' => 'Envoyer', 'outil' => 'gmail',
                    'consigne' => 'Écris à [Informations de départ.Email client] avec [Email de relance préparé.Objet] ; [Email de relance préparé.Inconnu].',
                    'filtres' => ['destinataires' => '[Email de relance préparé.Destinataire]']],
            ],
        ]);
        [$step, $action] = $d['definition']['nodes'];
        $piece = $step['output']['key'];
        $this->assertSame('Pour @{depart.client}, prépare @{depart}.', $step['instruction'], 'Un champ du départ cité seul.');
        $this->assertSame('Écris à @{depart.email_client} avec @{' . $piece . '.objet} ; @{' . $piece . '}.', $action['instruction'],
            'Un champ inconnu d’une Fiche connue : la Fiche entière, jamais une citation en clair.');
        $this->assertSame('@{' . $piece . '.destinataire}', $action['filters']['recipients']);
    }

    public function testUneBaseCiteeEntreCrochetsDevientSonNom(): void
    {
        $c = self::choices();
        $c['kbs'] = [3 => ['id' => 3, 'name' => 'Catalogue produits', 'reason' => null]];
        $d = WorkflowDraft::fromArgs(['nom' => 'x', 'objectif' => 'x', 'briques' => [
            ['type' => 'etape', 'titre' => 'Fiche', 'consigne' => 'Appuie-toi sur [Catalogue produits].', 'bases' => ['Catalogue produits'], 'piece' => ['nom' => 'Fiche']],
        ]], $c);
        $step = $d['definition']['nodes'][0];
        $this->assertSame('Appuie-toi sur « Catalogue produits ».', $step['instruction']);
        $this->assertSame([3], $step['kb_ids']);
    }

    public function testUnDepartQuiNeDemandeQuUnFichierSeCiteParLeNomDeLaFiche(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Fichier clients', 'champs' => [['nom' => 'Tableau clients', 'type' => 'fichier']]],
            'briques' => [['type' => 'etape', 'titre' => 'Vérifier', 'consigne' => 'Contrôle [Fichier clients].', 'piece' => ['nom' => 'Vérifié', 'forme' => 'tableau']]],
        ]);
        $this->assertSame('Contrôle @{tableau_clients}.', $d['definition']['nodes'][0]['instruction']);
    }

    public function testLeFichierDuDepartSeCiteAussiCommeUnChampDeLaFiche(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Relance', 'champs' => [
                ['nom' => 'Liste clients', 'type' => 'fichier'], ['nom' => 'Ton', 'type' => 'texte'],
            ]],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Lire', 'consigne' => 'Lis [Relance.Liste clients] sur le ton [Relance.Ton].', 'piece' => ['nom' => 'Lu']],
                ['type' => 'boucle', 'titre' => 'Chaque client', 'parcourir' => 'lignes', 'pour_chaque' => 'Relance.Liste clients',
                    'colonnes' => ['Nom'], 'element' => 'Client', 'briques' => [
                        ['type' => 'etape', 'titre' => 'Rédiger', 'consigne' => 'Écris à [Client].', 'piece' => ['nom' => 'Email']],
                    ]],
                ['type' => 'boucle', 'titre' => 'Encore', 'parcourir' => 'lignes', 'pour_chaque' => 'Relance',
                    'colonnes' => ['Nom'], 'element' => 'Client 2', 'briques' => [
                        ['type' => 'etape', 'titre' => 'Rédiger 2', 'consigne' => 'Écris à [Client 2].', 'piece' => ['nom' => 'Email 2']],
                    ]],
            ],
        ]);
        [$step, $loop, $loop2] = $d['definition']['nodes'];
        $this->assertSame('Lis @{liste_clients} sur le ton @{depart.ton}.', $step['instruction']);
        $this->assertSame('liste_clients', $loop['over'], 'La fiche ne se parcourt pas en lignes : son fichier, si.');
        $this->assertSame('liste_clients', $loop2['over']);
        $issues = array_column(WorkflowSpec::issues($d['definition'], self::choices()), 'text');
        $this->assertSame([], array_filter($issues, static fn(string $t): bool => str_contains($t, 'ne se parcourt pas')));
    }

    // ------------------------------------------------------------------
    // Départ
    // ------------------------------------------------------------------

    public function testAppelSeulNArmePasLAdresseWeb(): void
    {
        $manuel = self::draft([
            'depart' => ['nom_fiche' => 'Demande', 'champs' => [['nom' => 'Produit', 'type' => 'texte']], 'evenement' => ['type' => 'appel']],
            'briques' => [['type' => 'etape', 'titre' => 'Rédiger', 'consigne' => 'Fiche de [Demande].', 'piece' => ['nom' => 'Fiche']]],
        ]);
        $this->assertNull($manuel['definition']['start']['event'], '« appel » = lancement à la main, le cas courant.');
        $this->assertSame(WorkflowDraft::PLAN_PUBLISH, WorkflowDraft::approvalPlan($manuel['definition'], self::choices())['mode']);

        $web = self::draft(['depart' => ['evenement' => ['type' => 'adresse_web']],
            'briques' => [['type' => 'etape', 'titre' => 'Lire', 'consigne' => 'Lis [Données reçues].', 'piece' => ['nom' => 'Lu']]]]);
        $this->assertSame('webhook', $web['definition']['start']['event']['kind']);
        $this->assertSame('Lis @{' . WorkflowSpec::EVENT_PIECE . '}.', $web['definition']['nodes'][0]['instruction']);
    }

    public function testLaPieceDUnEvenementSeCiteSousSonNomOuCeluiQueLeModeleDonne(): void
    {
        $email = self::draft([
            'depart' => ['evenement' => ['type' => 'email', 'objet' => 'devis']],
            'briques' => [['type' => 'etape', 'titre' => 'Extraire', 'consigne' => 'Lis [Email reçu] de [Email reçu.Expéditeur].', 'piece' => ['nom' => 'Besoin']]],
        ]);
        $this->assertSame('Lis @{evenement} de @{evenement.expediteur}.', $email['definition']['nodes'][0]['instruction']);

        // « nom_piece » dans l'évènement, ou à côté de lui (le modèle hésite).
        foreach ([
            ['evenement' => ['type' => 'fichier', 'dossier' => 'Factures', 'nature' => 'pdf', 'nom_piece' => 'PDF reçu']],
            ['evenement' => ['type' => 'fichier', 'dossier' => 'Factures', 'nature' => 'pdf'], 'nom_piece' => 'PDF reçu'],
        ] as $depart) {
            $d = self::draft(['depart' => $depart,
                'briques' => [['type' => 'etape', 'titre' => 'Lire', 'consigne' => 'Lis [PDF reçu].', 'piece' => ['nom' => 'Infos']]]]);
            $this->assertSame('Lis @{evenement}.', $d['definition']['nodes'][0]['instruction']);
            $this->assertSame('PDF reçu', $d['definition']['start']['event']['piece_name']);
            $this->assertSame('pdf', $d['definition']['start']['event']['filters']['drive']['kind'], 'Nature des fichiers du déclencheur.');
        }
    }

    public function testEnAnglaisLaPieceDeLEvenementPorteSonNomAnglais(): void
    {
        I18n::use('en');
        $d = self::draft([
            'depart' => ['evenement' => ['type' => 'email']],
            'briques' => [['type' => 'etape', 'titre' => 'Extract', 'consigne' => 'Read [Email received] from [Email received.Sender].', 'piece' => ['nom' => 'Need']]],
        ]);
        $this->assertSame('Email received', $d['definition']['start']['event']['piece_name']);
        $this->assertSame('Read @{evenement} from @{evenement.expediteur}.', $d['definition']['nodes'][0]['instruction']);
        $this->assertSame(['Start', 'Deliverable'], [$d['definition']['start']['title'], $d['definition']['end']['title']]);
    }

    public function testLOutilDuChatDonneLeFuseauDuNavigateur(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Agent/Tools/WorkflowTools.php');
        $this->assertStringContainsString("\$source['fuseau'] = \$this->turn->timeZone;", $src);
        $this->assertLessThan(strpos($src, 'WorkflowDraft::fromArgs($source'), strpos($src, "\$source['fuseau'] = \$this->turn->timeZone;"),
            'Avant la conversion (et gardé dans la source de la carte, pour les retouches).');
        $d = self::draft(['fuseau' => 'Europe/Paris', 'depart' => ['programmation' => ['frequence' => 'chaque_semaine', 'jours' => [1], 'heure' => '08:30']],
            'briques' => [['type' => 'etape', 'titre' => 'Veille', 'consigne' => 'Veille.', 'piece' => ['nom' => 'Veille']]]]);
        $this->assertSame('Europe/Paris', $d['definition']['start']['schedule']['timezone']);
    }

    // ------------------------------------------------------------------
    // Règles sur des champs restés texte
    // ------------------------------------------------------------------

    public function testUneColonneFiltreeParSuperieurDevientUnNombre(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Fichier', 'champs' => [['nom' => 'Clients', 'type' => 'fichier']]],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Vérifier', 'consigne' => 'Vérifie [Fichier].', 'piece' => ['nom' => 'Tableau vérifié', 'forme' => 'tableau',
                    'champs' => [['nom' => 'Nom', 'type' => 'texte'], ['nom' => 'Email', 'type' => 'email'], ['nom' => 'Échéance', 'type' => 'texte']]]],
                ['type' => 'boucle', 'titre' => 'Relancer', 'parcourir' => 'lignes', 'pour_chaque' => 'Tableau vérifié',
                    'colonnes' => ['Nom', 'Email', 'Montant dû', 'Échéance'], 'element' => 'Client',
                    'garder_si' => ['champ' => 'Montant dû', 'operateur' => 'superieur', 'valeur' => '0'],
                    'briques' => [['type' => 'etape', 'titre' => 'Rédiger', 'consigne' => 'Écris à [Client.Email].', 'piece' => ['nom' => 'Mail']]]],
            ],
        ]);
        $loop = $d['definition']['nodes'][1];
        $types = array_column($loop['source']['columns'], 'type', 'label');
        $this->assertSame(['Nom' => 'text', 'Email' => 'email', 'Montant dû' => 'number', 'Échéance' => 'text'], $types,
            'Type déclaré par l’étape, nombre pour la colonne filtrée par « supérieur ».');
        $this->assertSame('Écris à @{client.email}.', $loop['body'][0]['instruction']);
    }

    public function testSansColonnesLaBoucleReprendCellesDeclareesParLEtape(): void
    {
        $d = self::draft(['briques' => [
            ['type' => 'etape', 'titre' => 'Repérer', 'consigne' => 'Repère.', 'piece' => ['nom' => 'Actions', 'forme' => 'tableau',
                'champs' => [['nom' => 'Qui', 'type' => 'texte'], ['nom' => 'Pour quand', 'type' => 'date']]]],
            ['type' => 'boucle', 'titre' => 'Chaque action', 'parcourir' => 'lignes', 'pour_chaque' => 'Actions', 'element' => 'Action',
                'briques' => [['type' => 'etape', 'titre' => 'Écrire', 'consigne' => 'Pour [Action.Qui].', 'piece' => ['nom' => 'Mot']]]],
        ]]);
        $loop = $d['definition']['nodes'][1];
        $this->assertSame(['Qui' => 'text', 'Pour quand' => 'date'], array_column($loop['source']['columns'], 'type', 'label'));
    }

    public function testUneRegleSuperieurSurUnChampTexteLeRendNumerique(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Note', 'champs' => [['nom' => 'Montant', 'type' => 'texte'], ['nom' => 'Date limite', 'type' => 'texte']]],
            'briques' => [
                ['type' => 'condition', 'titre' => 'Plus de 500 € ?', 'regle' => ['piece' => 'Note', 'champ' => 'Montant', 'operateur' => 'superieur', 'valeur' => '500'],
                    'si_oui' => ['briques' => [], 'ensuite' => 'continuer'], 'si_non' => ['briques' => [], 'ensuite' => 'continuer']],
                ['type' => 'condition', 'titre' => 'Avant le 30/11 ?', 'regle' => ['piece' => 'Note', 'champ' => 'Date limite', 'operateur' => 'inferieur', 'valeur' => '2026-11-30'],
                    'si_oui' => ['briques' => [], 'ensuite' => 'continuer'], 'si_non' => ['briques' => [], 'ensuite' => 'continuer']],
            ],
        ]);
        $this->assertSame(['montant' => 'number', 'date_limite' => 'date'], array_column($d['definition']['start']['fields'], 'type', 'key'));
    }

    public function testComparerDesNombresDansUnChampTexteMarcheQuandMeme(): void
    {
        $this->assertTrue(WorkflowValues::compare('1 250,50 €', 'gt', '1000', 'text'));
        $this->assertFalse(WorkflowValues::compare('900', 'gt', '1000', 'text'));
        $this->assertTrue(WorkflowValues::compare('12/03/2026', 'lt', '2026-04-01', 'text'));
        $this->assertFalse(WorkflowValues::compare('beaucoup', 'gt', '0', 'text'), 'Ni nombre ni date : faux, comme avant.');
        $this->assertTrue(WorkflowValues::compare('Payé', 'eq', 'payé', 'text'), 'Égalité de texte inchangée.');
    }

    public function testDeuxVoiesQuiArretentToutesLesDeuxSontSignalees(): void
    {
        $d = self::draft([
            'depart' => ['nom_fiche' => 'Note', 'champs' => [['nom' => 'Montant', 'type' => 'nombre']]],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Synthèse', 'consigne' => 'Résume [Note].', 'piece' => ['nom' => 'Synthèse']],
                ['type' => 'condition', 'titre' => 'Gros ?', 'regle' => ['piece' => 'Note', 'champ' => 'Montant', 'operateur' => 'superieur', 'valeur' => '500'],
                    'si_oui' => ['briques' => [], 'ensuite' => 'arreter'], 'si_non' => ['briques' => [], 'ensuite' => 'arreter']],
            ],
            'livrable' => ['Synthèse'],
        ]);
        $texts = array_column(WorkflowSpec::issues($d['definition'], self::choices()), 'text');
        $this->assertContains('Les deux voies arrêtent le workflow : le livrable ne sera jamais atteint.', $texts);
    }

    // ------------------------------------------------------------------
    // Forme décrite au modèle
    // ------------------------------------------------------------------

    public function testLaFormeDitCeQueLesModelesConfondaient(): void
    {
        $guide = WorkflowDraft::schemaGuide(self::choices());
        $this->assertStringContainsString('n° 4 : Juriste', $guide);
        $this->assertStringContainsString("le numéro d'un assistant de la liste", $guide);
        $this->assertStringContainsString('null pour un lancement à la main', $guide);
        $this->assertStringNotContainsString('{"type": "appel"}', $guide);
        $this->assertStringContainsString('« Email reçu » (champs Expéditeur, Objet', $guide);
        $this->assertStringContainsString('[Nom de la Fiche.Nom du champ]', $guide);
        $this->assertStringContainsString('« arreter » (ou une brique « arreter » en dernier) seulement pour une voie qui écarte le cas', $guide);
        $this->assertStringContainsString('"lit": ["Nom de la pièce"] OU "regle"', $guide, 'Une question à MIA lit des pièces.');
        $this->assertStringNotContainsString('PUBLIE OU LIT', $guide, 'Slack absent : pas proposé.');
    }

    // ------------------------------------------------------------------
    // Tâches programmées
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string}> */
    public static function rythmesRares(): iterable
    {
        yield 'deux semaines'   => ['Toutes les deux semaines le mardi à 10h, fais une veille concurrentielle.'];
        yield 'quinze jours'    => ['Tous les quinze jours, un point sur mes projets'];
        yield 'trois mois'      => ['Tous les trois mois, un bilan de mes ventes'];
        yield 'une semaine sur deux' => ['Une semaine sur deux, prépare le compte rendu'];
        yield 'un lundi sur deux'    => ['Un lundi sur deux, envoie-moi un récap'];
        yield 'every two weeks'      => ['Every two weeks, summarize my inbox'];
        yield 'biweekly'             => ['Send me a biweekly digest of industry news'];
    }

    #[DataProvider('rythmesRares')]
    public function testUnRythmeRareExposeQuandMemeLOutil(string $message): void
    {
        $this->assertTrue(ScheduledTask::looksLikeScheduleRequest($message), $message);
    }

    public function testLaConsigneDitDeNePasApprocherUnRythmeEnSilence(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Agent/Tools/ScheduleTools.php');
        $this->assertStringContainsString('ne s\'approxime jamais en', $src);
    }

    private static function taskChoices(): array
    {
        return [
            'sources' => [
                'web' => ['label' => 'Recherche Internet', 'connected' => true],
                'gmail' => ['label' => 'Gmail', 'connected' => true],
                'calendar' => ['label' => 'Agenda Google', 'connected' => true],
            ],
            'tools' => [11 => ['id' => 11, 'name' => 'Automatisations', 'type' => 'n8n', 'reason' => null]],
        ];
    }

    private static function task(string $instruction, array $sources, bool $actions = false, array $tools = []): array
    {
        return ['instruction' => $instruction, 'sources' => $sources, 'allow_actions' => $actions, 'tool_ids' => $tools];
    }

    public function testUneTacheSansLeServiceQueSaConsigneSupposeEstRenvoyeeAuModele(): void
    {
        $c = self::taskChoices();
        // Le cas du banc : un brouillon d'email, l'agenda seul.
        $issues = ScheduleTools::sourceIssues(self::task('Résume ma semaine depuis mon agenda et prépare un brouillon d\'email pour l\'équipe.', ['calendar'], true), $c);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('La consigne suppose gmail', $issues[0]);
        $this->assertSame([], ScheduleTools::sourceIssues(self::task('Résume ma semaine depuis mon agenda et prépare un brouillon d\'email.', ['calendar', 'gmail'], true), $c));
        // Lire « mes emails » sans messagerie, même en lecture seule.
        $this->assertNotSame([], ScheduleTools::sourceIssues(self::task('Fais le point sur mes emails importants.', ['web']), $c));
        $this->assertNotSame([], ScheduleTools::sourceIssues(self::task('Liste mes rendez-vous de demain.', ['web']), $c));
        // Une veille sur « l'email marketing » ne suppose aucune boîte.
        $this->assertSame([], ScheduleTools::sourceIssues(self::task('Veille sur l\'email marketing B2B.', ['web']), $c));
        // Un service que le compte n'a pas : le modèle ne pourrait rien corriger.
        $this->assertSame([], ScheduleTools::sourceIssues(self::task('Résume mon drive.', ['web']), $c));
    }

    public function testDesActionsSansAucunServiceOuEcrireSontRenvoyeesAuModele(): void
    {
        $c = self::taskChoices();
        $issues = ScheduleTools::sourceIssues(self::task('Fais une veille et prépare un post LinkedIn.', ['web'], true), $c);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('aucune source ne permet d\'agir', $issues[0]);
        $this->assertSame([], ScheduleTools::sourceIssues(self::task('Lance l\'automatisation de relance.', ['web'], true, [11]), $c), 'Un outil qui agit suffit.');
        $this->assertSame([], ScheduleTools::sourceIssues(self::task('Fais une veille.', ['web']), $c), 'Lecture seule : rien à dire.');
    }

    public function testLeHandlerRenvoieCesManquesAvantLaCarte(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Agent/Tools/ScheduleTools.php');
        $merge = strpos($src, 'self::sourceIssues($data, $choices)');
        $this->assertNotFalse($merge);
        $this->assertLessThan(strpos($src, '($this->turn->queueWrite)(self::TOOL'), $merge);
    }

    public function testUneRetoucheDeTacheGardeSonNomDansLaTrace(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->assertSame('Veille produits', ChatController::actionEntryForTool('programmer_tache', ['remplace' => true, 'heure' => '09:00'], ['tache' => 'Veille produits'])['d']);
        $this->assertSame('Point du lundi', ChatController::actionEntryForTool('programmer_tache', ['nom' => 'Point du lundi'], ['tache' => 'X'])['d']);
        $src = (string)file_get_contents(ROOT . '/src/Agent/Tools/ScheduleTools.php');
        $this->assertStringContainsString("\$result['tache'] = \$data['name'];", $src);
    }
}
