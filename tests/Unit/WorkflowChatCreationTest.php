<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Un workflow proposé dans une conversation, créé « le plus complet
 * possible » à l'approbation, puis ouvert dans l'éditeur — cf. CLAUDE.md
 * « Workflows », puce « MIA prépare un workflow ».
 *
 * - WorkflowFit : ajusté aux services et outils de la personne (partagé avec
 *   les modèles livrés) ;
 * - WorkflowDraft : conversion enrichie (bases, renvoi d'une validation,
 *   doute d'une condition, destinataire tiré d'un champ), consigne qui
 *   présente ce que la personne a, plan d'approbation (publié, à publier,
 *   à régler), couleur ;
 * - câblage : approbation, carte qui ouvre l'éditeur, éditeur qui montre le
 *   retour à la conversation.
 */
final class WorkflowChatCreationTest extends TestCase
{
    private static function src(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    private static function methode(string $code, string $nom): string
    {
        $debut = strpos($code, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $suite = strpos($code, "\n    }\n", $debut);
        return substr($code, $debut, ($suite === false ? strlen($code) : $suite) - $debut);
    }

    /** Une fonction de premier niveau d'un script (jusqu'à son « } » en colonne 0). */
    private static function jsFunction(string $code, string $nom): string
    {
        $debut = strpos($code, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Fonction absente : $nom");
        $suite = strpos($code, "\n}\n", $debut);
        return substr($code, $debut, ($suite === false ? strlen($code) : $suite) - $debut);
    }

    /** Une personne sous Outlook et calendrier Outlook, sans Slack ; un outil n8n, une base. */
    private static function choices(array $surcharge = []): array
    {
        $src = static fn(string $label, bool $on = true): array => ['label' => $label, 'connected' => $on];
        return $surcharge + [
            'profiles'     => [5 => ['name' => 'Juriste', 'description' => 'Relit les contrats', 'default_llm' => '']],
            'llms'         => ['openai::gpt-5.6' => 'GPT-5.6'],
            'tool_llms'    => ['openai::gpt-5.6' => true],
            'default_llm'  => 'openai::gpt-5.6',
            'sources'      => ['web' => $src('Recherche Internet'), 'outlook' => $src('Outlook'),
                'mscalendar' => $src('Calendrier Outlook'), 'drive' => $src('Google Drive', false)],
            'kbs'          => [3 => ['id' => 3, 'name' => 'Contrats types', 'reason' => null],
                4 => ['id' => 4, 'name' => 'Archives', 'reason' => 'Aucun document vectorisé']],
            'tools'        => [9 => ['id' => 9, 'name' => 'Facturation n8n', 'type' => 'n8n', 'reason' => null]],
            'creations'    => [11 => ['id' => 11, 'name' => 'Images', 'type' => 'image', 'kind' => 'image', 'reason' => null]],
            'delivery_kbs' => [3 => ['name' => 'Contrats types']],
            'workflows'    => [],
            'auto'         => true,
        ];
    }

    private static function args(): array
    {
        return [
            'nom' => 'Relire un contrat',
            'objectif' => 'Un contrat relu, renvoyé au client.',
            'depart' => [
                'nom_fiche' => 'Demande',
                'champs' => [
                    ['nom' => 'Client', 'type' => 'texte'],
                    ['nom' => 'Email du client', 'type' => 'email'],
                ],
            ],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Relire le contrat', 'assistant' => 'Juriste',
                    'consigne' => 'Relis le contrat de [Demande] avec nos modèles.',
                    'sources' => ['gmail', 'slack', 'web'], 'bases' => ['Contrats types', 'Archives', 'Inconnue'],
                    'piece' => ['nom' => 'Avis juridique', 'forme' => 'texte']],
                ['type' => 'condition', 'titre' => 'Clause risquée ?', 'question' => 'L’avis signale-t-il une clause risquée ?',
                    'lit' => ['Avis juridique'], 'si_doute' => 'non',
                    'si_oui' => ['briques' => [
                        ['type' => 'validation', 'titre' => 'Accord', 'relit' => ['Avis juridique'],
                            'renvoie_a' => 'Relire le contrat', 'peut_corriger' => false, 'delai_jours' => 3],
                    ], 'ensuite' => 'continuer'],
                    'si_non' => ['briques' => [], 'ensuite' => 'continuer']],
                ['type' => 'action', 'titre' => 'Facturer', 'outil' => 'n8n', 'consigne' => 'Facture [Demande].'],
                ['type' => 'action', 'titre' => 'Bloquer un créneau', 'outil' => 'calendar', 'consigne' => 'Bloque une heure.'],
            ],
            'livrable' => ['Avis juridique'],
            'livraisons' => [
                ['type' => 'email', 'source' => 'gmail', 'a_champ' => 'Demande.Email du client', 'objet' => 'Votre contrat', 'message' => '[Avis juridique]'],
                ['type' => 'slack', 'canal' => '#juridique', 'message' => 'Contrat relu.'],
                ['type' => 'base', 'base' => 'Contrats types', 'pieces' => ['Avis juridique']],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Ajustement à la personne (WorkflowFit)
    // ------------------------------------------------------------------

    public function testLeWorkflowPrendLesServicesDeLaPersonne(): void
    {
        $d = WorkflowDraft::fromArgs(self::args(), self::choices());
        $def = $d['definition'];
        $step = $def['nodes'][0];
        // Gmail → Outlook ; Slack (absent, sans équivalent) retiré de l'étape.
        $this->assertSame(['outlook', 'web'], $step['sources']);
        // Agenda Google → calendrier Outlook pour l'action.
        $this->assertSame('mscalendar', $def['nodes'][3]['target']);
        // Livraisons : l'email part par Outlook, Slack est retiré, la base reste.
        $this->assertSame(['email', 'kb'], array_column($def['end']['deliveries'], 'type'));
        $this->assertSame('outlook', $def['end']['deliveries'][0]['source']);
        $joined = implode(' ', $d['notes']);
        $this->assertStringContainsString('remplacé par Outlook', $joined);
        $this->assertStringContainsString('Livraison par Slack retirée', $joined);
        $this->assertStringContainsString('« Slack » ne vous est pas proposé', $joined);
    }

    public function testLesOutilsSontRelies(): void
    {
        $def = WorkflowDraft::fromArgs(self::args(), self::choices())['definition'];
        $this->assertSame(9, $def['nodes'][2]['tool_id'], 'L’action n8n reçoit l’outil n8n de la personne.');

        // Un outil de création retiré entre la proposition et l'approbation : remplacé.
        $create = WorkflowSpec::normalize(['nodes' => [[
            'key' => 'affiche', 'type' => 'create', 'title' => 'Affiche', 'kind' => 'image', 'tool_id' => 77,
            'instruction' => 'Une affiche.', 'output' => ['key' => 'img', 'name' => 'Affiche', 'form' => 'image'],
        ]]]);
        $this->assertSame(11, WorkflowFit::apply($create, self::choices())['nodes'][0]['tool_id']);
        // Sans outil en service de cette nature : rien d'inventé.
        $sans = self::choices(['creations' => [11 => ['id' => 11, 'name' => 'Images', 'kind' => 'image', 'reason' => 'Hors service']]]);
        $this->assertSame(77, WorkflowFit::apply($create, $sans)['nodes'][0]['tool_id']);
    }

    public function testUneLivraisonDansUneBaseRecoitLaSeuleBaseGeree(): void
    {
        $def = WorkflowSpec::normalize([
            'nodes' => [['key' => 'e', 'type' => 'step', 'title' => 'Écrire', 'instruction' => 'Écris.', 'output' => ['key' => 'texte', 'name' => 'Texte', 'form' => 'text']]],
            'end' => ['pieces' => ['texte'], 'deliveries' => [['type' => 'kb', 'kb_id' => null, 'pieces' => ['texte']]]],
        ]);
        $this->assertSame(3, WorkflowFit::apply($def, self::choices())['end']['deliveries'][0]['kb_id']);
        $deux = self::choices(['delivery_kbs' => [3 => ['name' => 'A'], 8 => ['name' => 'B']]]);
        $this->assertNull(WorkflowFit::apply($def, $deux)['end']['deliveries'][0]['kb_id'], 'Deux bases : la personne choisit.');
    }

    public function testLesModelesLivresPassentParLeMemeAjustement(): void
    {
        $this->assertSame(WorkflowFit::PARTNERS, WorkflowTemplates::PARTNERS);
        $this->assertStringContainsString('return WorkflowFit::apply($def, $choices);', self::methode(self::src('src/Workflow/WorkflowTemplates.php'), 'adapt'));
    }

    // ------------------------------------------------------------------
    // Conversion enrichie
    // ------------------------------------------------------------------

    public function testLaConversionPorteBasesRenvoiDouteEtDestinataire(): void
    {
        $d = WorkflowDraft::fromArgs(self::args(), self::choices());
        $def = $d['definition'];
        $step = $def['nodes'][0];
        $this->assertSame(5, $step['profile_id']);
        $this->assertSame([3], $step['kb_ids'], 'Seule la base interrogeable et connue est retenue.');
        $joined = implode(' ', $d['notes']);
        $this->assertStringContainsString('« Archives » n’est pas interrogeable', $joined);
        $this->assertStringContainsString('base « Inconnue » introuvable', $joined);

        $cond = $def['nodes'][1];
        $this->assertSame(WorkflowSpec::DOUBT_NO, $cond['doubt']);
        $gate = $cond['yes']['nodes'][0];
        $this->assertSame($step['key'], $gate['returns_to'], 'Renvoyée à l’étape désignée par son titre.');
        $this->assertFalse($gate['can_correct']);
        $this->assertSame(3, $gate['days']);

        $email = $def['end']['deliveries'][0];
        $emailField = $def['start']['fields'][1]['key'];
        $this->assertSame(WorkflowSpec::START_PIECE . '.' . $emailField, $email['to_field']);
        $this->assertSame([], array_values(array_filter(WorkflowSpec::issues($def, self::choices()),
            static fn(array $i): bool => $i['level'] === WorkflowSpec::LEVEL_BLOCK && $i['node'] === WorkflowSpec::END_KEY)),
            'Le destinataire tiré du champ suffit : la livraison ne bloque pas.');
    }

    public function testUneValidationRenvoieAussiParLeNomDeLaPiece(): void
    {
        $args = self::args();
        $args['briques'][1]['si_oui']['briques'][0]['renvoie_a'] = 'Avis juridique';
        $def = WorkflowDraft::fromArgs($args, self::choices())['definition'];
        $this->assertSame($def['nodes'][0]['key'], $def['nodes'][1]['yes']['nodes'][0]['returns_to']);
    }

    public function testLaConsignePresenteCeQueLaPersonneA(): void
    {
        $g = WorkflowDraft::schemaGuide(self::choices());
        $this->assertStringContainsString("CE QUE L'UTILISATEUR A", $g);
        $this->assertStringContainsString('outlook (Outlook, relié)', $g);
        $this->assertStringContainsString('drive (Google Drive, pas encore relié)', $g);
        $this->assertStringContainsString('Contrats types', $g);
        $this->assertStringNotContainsString('Archives', $g, 'Une base non interrogeable n’est pas proposée.');
        $this->assertStringContainsString('n8n : Facturation n8n', $g);
        foreach (['"bases"', '"renvoie_a"', '"peut_corriger"', '"delai_jours"', '"si_doute"', '"a_champ"', '"defaut"'] as $cle) {
            $this->assertStringContainsString($cle, $g, $cle);
        }
        $this->assertStringContainsString('chaque champ obligatoire y porte un « defaut »', $g, 'Un départ programmé n’a personne pour remplir la fiche.');
        $this->assertStringContainsString('Construis-le COMPLET', self::src('src/Agent/Tools/WorkflowTools.php'));
        $this->assertStringNotContainsString('Garde-le simple : 3 à 8 briques', self::src('src/Agent/Tools/WorkflowTools.php'));
    }

    // ------------------------------------------------------------------
    // Ce que devient le workflow approuvé
    // ------------------------------------------------------------------

    public function testLePlanDApprobation(): void
    {
        $manuel = WorkflowSpec::normalize([
            'nodes' => [['key' => 'e', 'type' => 'step', 'title' => 'Écrire', 'instruction' => 'Écris un résumé.', 'output' => ['key' => 'texte', 'name' => 'Texte', 'form' => 'text']]],
            'end' => ['pieces' => ['texte']],
        ]);
        $this->assertSame(['mode' => WorkflowDraft::PLAN_PUBLISH, 'blocks' => 0], WorkflowDraft::approvalPlan($manuel, self::choices()));

        $programme = $manuel;
        $programme['start']['schedule'] = ['rule' => ['freq' => 'daily', 'days' => [], 'day' => 1, 'time' => '08:00'], 'timezone' => 'Europe/Paris'];
        $programme = WorkflowSpec::normalize($programme);
        $this->assertSame(WorkflowDraft::PLAN_TRIGGER, WorkflowDraft::approvalPlan($programme, self::choices())['mode']);

        $email = $manuel;
        $email['start']['event'] = ['kind' => 'email', 'source' => 'outlook', 'query' => ''];
        $this->assertSame(WorkflowDraft::PLAN_TRIGGER, WorkflowDraft::approvalPlan(WorkflowSpec::normalize($email), self::choices())['mode']);

        $bloque = $manuel;
        $bloque['nodes'][0]['instruction'] = '';
        $plan = WorkflowDraft::approvalPlan(WorkflowSpec::normalize($bloque), self::choices());
        $this->assertSame(WorkflowDraft::PLAN_ISSUES, $plan['mode']);
        $this->assertGreaterThan(0, $plan['blocks']);
    }

    public function testLaCarteDitCeQuiSePasseraEtNePortePlusAjuster(): void
    {
        $d = WorkflowDraft::fromArgs(self::args(), self::choices());
        $p = WorkflowDraft::actionPreview($d['name'], $d['definition'], self::choices(), $d['notes']);
        $this->assertArrayNotHasKey('ajuster', $p);
        $this->assertStringContainsString('ouvert dans l’éditeur', implode(' ', $p['impacts']));
        $this->assertNotContains('Créé en brouillon : vous le relisez dans l’éditeur, puis le publiez. Rien ne s’exécute avant.', $p['impacts']);
    }

    public function testLaCouleurEstStableEtTireeDesModeles(): void
    {
        $this->assertSame(WorkflowDraft::colorFor('Relire un contrat'), WorkflowDraft::colorFor('Relire un contrat'));
        $this->assertContains(WorkflowDraft::colorFor('Relire un contrat'), WorkflowDraft::COLORS);
        $this->assertContains(WorkflowDraft::colorFor(''), WorkflowDraft::COLORS);
        $vues = [];
        foreach (['Trier', 'Relire', 'Facturer', 'Accueillir', 'Veiller', 'Lancer'] as $n) {
            $vues[WorkflowDraft::colorFor($n)] = true;
        }
        $this->assertGreaterThan(1, count($vues), 'Les workflows préparés ne portent pas tous la même couleur.');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLApprobationAjusteEtPublieCeQuiPeutLEtre(): void
    {
        $draft = self::methode(self::src('src/Controllers/ChatController.php'), 'approveWorkflowDraft');
        $this->assertStringContainsString('WorkflowFit::apply(', $draft, 'Ajusté aux services du MOMENT de l’approbation.');
        $this->assertStringContainsString('WorkflowCatalog::choices($user)', $draft);
        $this->assertStringContainsString('WorkflowDraft::approvalPlan($def, $choices)', $draft);
        $this->assertStringContainsString('WorkflowDraft::colorFor($name)', $draft);
        $this->assertStringContainsString("'open'        => Workflow::allows(\$user, 'edit') ? \$url . '?conv=' . \$convId : null,", $draft);
        // Le reste des chemins de création ajuste et colore aussi.
        $wc = self::src('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('WorkflowFit::apply($args[\'definition\'], WorkflowCatalog::choices($user))', self::methode($wc, 'createFromCardArgs'));
        $this->assertStringContainsString('WorkflowDraft::colorFor($draft[\'name\'])', self::methode($wc, 'create'));
        $this->assertStringContainsString('WorkflowFit::apply($def, $choices, $notes)', self::methode(self::src('src/Workflow/WorkflowDraft.php'), 'fromArgs'));
    }

    public function testLaCarteOuvreLEditeurApresUnClic(): void
    {
        $js = self::src('public/assets/js/chat.js');
        $render = self::jsFunction($js, 'renderDriveConfirmCard');
        $this->assertStringContainsString("const issue = await resolveDriveAction(card, 'approve');", $render);
        $this->assertStringContainsString('openCreatedWorkflow(card, issue);', $render);
        $open = self::jsFunction($js, 'openCreatedWorkflow');
        $this->assertStringContainsString("issue !== 'executed'", $open);
        $this->assertStringContainsString('/^\/workflows\/\d+\/edit(\?conv=\d+)?$/', $open, 'Seulement l’éditeur de cette origine.');
        $this->assertStringContainsString("classList.contains('voice-chat-on')", $open, 'Pas pendant une conversation vocale.');
        $this->assertStringContainsString('window.location.assign(url)', $open);
        // L'adresse ne vient que de la réponse du serveur, posée sur la carte.
        $this->assertStringContainsString('card.dataset.workflowOpen = openUrl', self::jsFunction($js, 'applyDriveDecision'));
        // « Tout approuver » ne navigue pas : seul le clic sur la carte l'appelle.
        $this->assertSame(2, substr_count($js, 'openCreatedWorkflow('), 'Une définition, un appel.');
    }

    public function testLEditeurMontreLeRetourALaConversation(): void
    {
        $wc = self::src('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString("'origin' => \$this->originConversation((int)(\$_GET['conv'] ?? 0), (int)\$user['id']),", self::methode($wc, 'edit'));
        $this->assertStringContainsString('Conversation::belongsToUser($convId, $userId)', self::methode($wc, 'originConversation'));
        $tpl = self::src('templates/workflows/edit.php');
        $this->assertStringContainsString('id="wfOrigin"', $tpl);
        $this->assertStringContainsString("te('Retour à la conversation')", $tpl);
        $ed = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('renderOrigin(blocks);', $ed);
        $this->assertStringContainsString("if (D.origin && state.issues.some(function (i) { return i.level === 'block'; })) {", $ed);
        $this->assertStringContainsString('.wf-ed-origin {', self::src('public/assets/css/workflows.css'));
    }

    public function testLeModuleEstChargeDansLesDeuxListes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $code = self::src($f);
            $fit = strpos($code, '/src/Workflow/WorkflowFit.php');
            $this->assertIsInt($fit, $f);
            $this->assertLessThan(strpos($code, '/src/Workflow/WorkflowTemplates.php'), $fit, $f);
        }
    }

    public function testLesTextesSontTraduits(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en.php';
        $client = require dirname(__DIR__, 2) . '/lang/en.client.php';
        foreach ([
            '{de} remplacé par {par} : c’est le service que vous pouvez utiliser.',
            'Livraison par {service} retirée : ce service ne vous est pas proposé.',
            'Workflow créé et publié : [« {nom} »]({url}). Il est prêt : lancez-le depuis l’éditeur, ou avec « @ » dans une conversation. Rien ne démarre sans vous.',
            'Retour à la conversation',
        ] as $k) {
            $this->assertArrayHasKey($k, $en, $k);
        }
        foreach (['Ouverture de l’éditeur…', 'Créé depuis votre conversation et publié : prêt à lancer. Ajustez-le si besoin.'] as $k) {
            $this->assertArrayHasKey($k, $client, $k);
        }
    }
}
