<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tâches programmées, lot 2 : MIA prépare une tâche (outil `programmer_tache`
 * + carte d'approbation), l'horloge de l'assistant ouvre le formulaire depuis
 * la zone de saisie, une tâche peut préparer des actions qui attendent
 * l'approbation une semaine. Formes pures et câblage.
 */
final class ScheduleToolsTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
        // ChatController n'est pas chargé par le bootstrap (pas d'autoloader) :
        // ses constantes ACTION_LABELS / ACTION_ICONS sont lues ici.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $fin = preg_match('/\n    (public|private|protected)( static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10)
            ? $m[0][1] : strlen($source);
        return substr($source, $debut, $fin - $debut);
    }

    private static function choix(): array
    {
        return [
            'profiles' => [7 => ['id' => 7, 'name' => 'Veille commerciale']],
            'llms'     => ['openai::gpt-5.6' => 'GPT-5.6'],
            'sources'  => [
                'web'   => ['label' => 'Recherche Internet', 'connected' => true],
                'gmail' => ['label' => 'Gmail', 'connected' => true],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Le déclencheur
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function messages(): iterable
    {
        yield 'chaque lundi'           => ['Fais-moi le point sur mes mails chaque lundi à 8h', true];
        yield 'tous les matins'        => ['Tous les matins, une revue de presse IA', true];
        yield 'le 1er du mois'         => ['Le 1er de chaque mois, un bilan de mes rendez-vous', true];
        yield 'programme-moi'          => ['Programme-moi ce résumé', true];
        yield 'planifie une revue'     => ['Planifie une revue de presse sur l’IA', true];
        yield 'demande polie'          => ['Peux-tu me faire un point chaque vendredi soir ?', true];
        yield 'refaire régulièrement'  => ['Refais ça régulièrement', true];
        yield 'parler des tâches'      => ['Comment modifier une tâche programmée ?', true];
        yield 'anglais'                => ['Every Monday, summarize my emails', true];
        yield 'anglais, programmer'    => ['Schedule this for me', true];
        // Rien de tout cela ne demande qu'une chose se répète : la boucle
        // d'outils (et, sur Mistral, l'éviction de la recherche Internet) n'a
        // pas à être engagée.
        yield 'demande ordinaire'      => ['Résume ce document en cinq points', false];
        yield 'question de calendrier' => ['Quel jour sommes-nous ?', false];
        yield 'programme, le nom'      => ['Quel est le programme de la conférence de demain ?', false];
        yield 'programme, le nom (2)'  => ['Le programme nous convient, merci', false];
        yield 'adjectif'               => ['Résume le rapport mensuel des ventes', false];
        yield 'routine sportive'       => ['Propose-moi une routine sportive pour débuter', false];
        yield 'question de faits'      => ['Combien de mails je reçois chaque jour ?', false];
        yield 'question de faits (en)' => ['How many emails do I get every day?', false];
        yield 'quotidien'              => ['Que dit le quotidien Le Monde ce matin ?', false];
    }

    #[DataProvider('messages')]
    public function testLaDemandeDeRepetitionEstReconnue(string $message, bool $attendu): void
    {
        $this->assertSame($attendu, ScheduledTask::looksLikeScheduleRequest($message), $message);
    }

    // ------------------------------------------------------------------
    // Arguments du modèle → formulaire
    // ------------------------------------------------------------------

    public function testLesArgumentsDuModeleDeviennentLesChampsDuFormulaire(): void
    {
        $input = ScheduleTools::input([
            'consigne'            => 'Fais le point sur mes mails.',
            'nom'                 => 'Point du lundi',
            'frequence'           => 'chaque_semaine',
            'jours'               => ['lundi', 4, '4'],
            'heure'               => '07:45',
            'sources'             => ['gmail'],
            'destination'         => 'nouvelle',
            'actions_a_approuver' => 'true',
        ], ['profile_id' => 7, 'llm' => '', 'sources' => ['web']], 'Europe/Paris');

        $this->assertSame('weekly', $input['freq']);
        $this->assertSame([1, 4], $input['days']);
        $this->assertSame('07:45', $input['time']);
        $this->assertSame('7', $input['profile_id'], 'L’assistant de la conversation est repris.');
        $this->assertSame(['gmail'], $input['sources']);
        $this->assertSame('new', $input['destination']);
        $this->assertSame('1', $input['allow_actions']);
        $this->assertSame('Europe/Paris', $input['timezone']);

        $data = ScheduledTask::normalizeInput($input, ScheduledTask::allowedFrom(self::choix()));
        $this->assertSame(['freq' => 'weekly', 'days' => [1, 4], 'time' => '07:45'], $data['rule']);
        $this->assertTrue($data['allow_actions']);
    }

    public function testCeQueLeModeleNePrecisePasVientDuTour(): void
    {
        $input = ScheduleTools::input(
            ['consigne' => 'Revue de presse', 'frequence' => 'jours_ouvres'],
            ['profile_id' => null, 'llm' => 'openai::gpt-5.6', 'sources' => ['web', 'gmail']],
            ''
        );
        $this->assertSame('weekdays', $input['freq']);
        $this->assertSame('08:00', $input['time'], 'Sans heure : 8 h, la consigne le dit au modèle.');
        $this->assertSame(['web', 'gmail'], $input['sources']);
        $this->assertSame('openai::gpt-5.6', $input['llm']);
        $this->assertSame('thread', $input['destination']);
        $this->assertSame('', $input['allow_actions'], 'Lecture seule par défaut.');
    }

    public function testLesJoursEtLeJourDuMoisSeLisentSousToutesLeursFormes(): void
    {
        $this->assertSame([1, 4], ScheduleTools::dayNumbers('lundi, jeudi'));
        $this->assertSame([5], ScheduleTools::dayNumbers(['Friday']));
        $this->assertSame([2, 7], ScheduleTools::dayNumbers([7, '2', 'huit', 0]));
        $this->assertSame('last', ScheduleTools::monthDay('Dernier'));
        $this->assertSame('15', ScheduleTools::monthDay(15));
    }

    public function testLaDefinitionDeLOutilNeProposeQueDesSourcesPermises(): void
    {
        $tools = new ScheduleTools($this->tour(), self::choix(), ['profile_id' => null, 'llm' => '', 'sources' => []]);
        $def = $tools->definitions()[0];
        $this->assertSame('programmer_tache', $def['name']);
        // Rien d'obligatoire : une retouche (`remplace`) ne passe que ce qui
        // change ; le handler exige consigne et fréquence d'une NOUVELLE tâche.
        $this->assertSame([], $def['parameters']['required']);
        $this->assertArrayHasKey('remplace', $def['parameters']['properties']);
        $this->assertSame(['sources'], $def['parameters']['properties']['vider']['items']['enum']);
        // Choix légers (boucle déjà lancée) : ni assistants, ni modèles, ni
        // bases, ni outils décrits — des jetons à ne pas payer à chaque message.
        foreach (['assistant', 'modele', 'bases', 'outils'] as $champ) {
            $this->assertArrayNotHasKey($champ, $def['parameters']['properties'], $champ);
        }
        $this->assertSame(['web', 'gmail'], $def['parameters']['properties']['sources']['items']['enum']);
        $this->assertSame(['chaque_jour', 'jours_ouvres', 'chaque_semaine', 'chaque_mois'], $def['parameters']['properties']['frequence']['enum']);
        // Tous les tableaux typent leurs éléments (Gemini refuse sinon).
        $this->assertSame('integer', $def['parameters']['properties']['jours']['items']['type']);
        $this->assertNull($tools->connector(), 'Pas un connecteur : ni plan ni méthode agent pour lui seul.');
        $this->assertStringContainsString("n'affirme jamais qu'elle est programmée", $tools->guidance());
    }

    /** Choix lus en entier : assistants, modèles, bases, outils (forme de ScheduledTask::choicesFor). */
    private static function choixComplets(): array
    {
        return self::choix() + [
            'kbs' => [
                3 => ['id' => 3, 'name' => 'Catalogue produits', 'description' => 'Fiches produits', 'reason' => null, 'live' => false, 'docs' => 4, 'shared' => false],
                5 => ['id' => 5, 'name' => 'Brouillons', 'description' => '', 'reason' => 'Aucun document vectorisé', 'live' => false, 'docs' => 0, 'shared' => false],
                8 => ['id' => 8, 'name' => 'Veille marché', 'description' => '', 'reason' => null, 'live' => false, 'docs' => 2, 'shared' => true],
            ],
            'profile_kbs'   => [7 => [['id' => 8, 'name' => 'Veille marché']]],
            'tools' => [
                11 => ['id' => 11, 'name' => 'Images', 'type' => 'image', 'reason' => null],
                12 => ['id' => 12, 'name' => 'Automatisations', 'type' => 'n8n', 'reason' => null],
                13 => ['id' => 13, 'name' => 'Images bis', 'type' => 'image', 'reason' => null],
                14 => ['id' => 14, 'name' => 'Gamma en panne', 'type' => 'gamma', 'reason' => 'Dernier test en échec'],
            ],
            'profile_tools' => [],
            'auto'          => false,
        ];
    }

    private static function tacheEnAttente(): array
    {
        $data = ScheduledTask::normalizeInput([
            'name' => 'Point du lundi', 'instruction' => "Fais le point sur mes mails.\nTrois puces au plus.", 'profile_id' => '7',
            'llm' => '', 'freq' => 'weekly', 'days' => ['1'], 'time' => '08:00', 'timezone' => 'Europe/Paris',
            'sources' => ['gmail'], 'kb_ids' => ['3'], 'destination' => 'thread', 'notify' => '1',
        ], ScheduledTask::allowedFrom(self::choixComplets()));
        return ScheduledTask::toInput($data);
    }

    public function testUneRetoucheNeChangeQueCeQuiEstDemande(): void
    {
        $base = self::tacheEnAttente();
        // « Plutôt à 9 h, et préviens-moi pas » : le reste est repris tel quel,
        // y compris ce qui viendrait sinon du tour (autre assistant, autres sources).
        $input = ScheduleTools::input(
            ['heure' => '09:00', 'me_prevenir' => false],
            ['profile_id' => null, 'llm' => 'openai::gpt-5.6', 'sources' => ['web']],
            'America/New_York',
            $base
        );
        $this->assertSame('09:00', $input['time']);
        $this->assertSame('', $input['notify']);
        $this->assertSame($base['instruction'], $input['instruction'], 'Consigne jamais réécrite de mémoire.');
        $this->assertSame('7', $input['profile_id']);
        $this->assertSame(['gmail'], $input['sources']);
        $this->assertSame(['3'], $input['kb_ids']);
        $this->assertSame('Europe/Paris', $input['timezone'], 'Le fuseau de la tâche reste le sien.');
        $this->assertSame(['1'], $input['days']);

        $data = ScheduledTask::normalizeInput($input, ScheduledTask::allowedFrom(self::choixComplets()));
        $this->assertSame(['freq' => 'weekly', 'days' => [1], 'time' => '09:00'], $data['rule']);
        $this->assertFalse($data['notify']);

        // Sans base, rien ne change de l'existant : le tour fournit les défauts.
        $fresh = ScheduleTools::input(['consigne' => 'X', 'frequence' => 'chaque_jour'], ['profile_id' => null, 'llm' => 'm', 'sources' => ['web']], 'UTC');
        $this->assertSame('1', $fresh['notify']);
        $this->assertSame([], $fresh['tool_ids']);
    }

    /**
     * Constaté en essai réel (GPT-5.5) : pour « plutôt à 9 h », le modèle
     * renvoie TOUS les paramètres, les inchangés vides (`""`, `[]`). Une liste
     * vide effacerait sinon les sources et les bases de la tâche.
     */
    public function testDansUneRetoucheUnChampVideVeutDireInchange(): void
    {
        $args = ScheduleTools::revisionArgs([
            'consigne' => '', 'nom' => '  ', 'frequence' => 'chaque_semaine', 'jours' => [1, 4], 'jour_du_mois' => '',
            'heure' => '09:00', 'sources' => [], 'assistant' => '', 'modele' => '', 'bases' => [], 'outils' => null,
            'actions_a_approuver' => false, 'me_prevenir' => true, 'remplace' => true,
        ], ['sources', 'bases', 'outils']);
        $this->assertSame([
            'frequence' => 'chaque_semaine', 'jours' => [1, 4], 'heure' => '09:00',
            'actions_a_approuver' => false, 'me_prevenir' => true, 'remplace' => true,
        ], $args, 'Les booléens passent, les vides tombent.');

        $input = ScheduleTools::input($args, ['profile_id' => null, 'llm' => '', 'sources' => []], 'UTC', self::tacheEnAttente());
        $this->assertSame(['gmail'], $input['sources']);
        $this->assertSame(['3'], $input['kb_ids']);
        $this->assertSame("Fais le point sur mes mails.\nTrois puces au plus.", $input['instruction']);

        // `vider` retire une liste entière, explicitement.
        $vidage = ScheduleTools::revisionArgs(['sources' => [], 'bases' => [3], 'vider' => ['sources', 'outils']], ['sources', 'bases', 'outils']);
        $this->assertSame(['bases' => [3], 'sources' => [], 'outils' => []], $vidage);
        $this->assertSame([], ScheduleTools::input($vidage, ['profile_id' => null, 'llm' => '', 'sources' => []], 'UTC', self::tacheEnAttente())['sources']);

        // Le modèle sait qu'une liste vide n'a rien retiré.
        $listes = ['sources' => 'sources', 'bases' => 'kb_ids', 'outils' => 'tool_ids'];
        $this->assertSame(['sources', 'bases'], ScheduleTools::ignoredEmptyLists(['sources' => [], 'bases' => [], 'outils' => []], self::tacheEnAttente(), $listes));
        $this->assertSame([], ScheduleTools::ignoredEmptyLists(['sources' => [], 'vider' => ['sources']], self::tacheEnAttente(), $listes));
    }

    public function testAssistantModeleBasesEtOutilsSeDesignentParNomOuNumero(): void
    {
        $choix = self::choixComplets();
        $in = ScheduleTools::resolveRefs(ScheduleTools::input([], ['profile_id' => null, 'llm' => '', 'sources' => []], 'UTC'), [
            'assistant' => 'veille COMMERCIALE',
            'bases'     => ['catalogue produits', 8],
            'outils'    => 'Automatisations',
        ], $choix);
        $this->assertSame('7', $in['profile_id']);
        $this->assertSame(['3', '8'], $in['kb_ids']);
        $this->assertSame(['12'], $in['tool_ids']);

        $sans = ScheduleTools::resolveRefs(['profile_id' => '7', 'llm' => ''], ['assistant' => 'aucun', 'modele' => 'gpt-5.6'], $choix);
        $this->assertSame('', $sans['profile_id'], '« aucun » retire l’assistant.');
        $this->assertSame('openai::gpt-5.6', $sans['llm'], 'Le libellé désigne le modèle.');
        $this->assertSame('7', ScheduleTools::resolveRefs(['profile_id' => '7'], ['assistant' => ''], $choix)['profile_id'], 'Vide : rien ne change.');
        $this->assertSame('', ScheduleTools::resolveRefs(['llm' => 'x'], ['modele' => 'défaut'], $choix)['llm']);

        foreach ([
            [['assistant' => 'Juriste'], 'Assistants disponibles : « Veille commerciale »'],
            [['bases' => ['Brouillons']], 'Base de connaissances « Brouillons » inconnue ou indisponible'],
            [['outils' => [14]], 'Outil « 14 » inconnu ou indisponible'],
            [['modele' => 'claude-opus-5'], 'Modèle « claude-opus-5 » non permis'],
        ] as [$args, $attendu]) {
            try {
                ScheduleTools::resolveRefs([], $args, $choix);
                $this->fail('Désignation acceptée à tort : ' . json_encode($args));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage());
            }
        }
    }

    public function testUneTacheQuiConsulteDesConnecteursExigeUnModeleAOutils(): void
    {
        $choix = self::choixComplets();
        $data = ['profile_id' => null, 'llm' => 'openai::gpt-5.6', 'sources' => ['web', 'gmail'], 'tool_ids' => [11]];
        $issues = ScheduleTools::coherenceIssues($data, $choix, 'openai::gpt-5.6', false);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('ne sait pas utiliser d\'outils', $issues[0]);
        $this->assertStringContainsString('Gmail, Images', $issues[0]);
        $this->assertStringNotContainsString('Recherche Internet', $issues[0], 'Internet passe par le modèle lui-même.');
        $this->assertSame([], ScheduleTools::coherenceIssues($data, $choix, 'openai::gpt-5.6', true));
        $this->assertSame([], ScheduleTools::coherenceIssues(['sources' => ['web'], 'tool_ids' => []] + $data, $choix, 'x', false));
        $avecAssistant = ScheduleTools::coherenceIssues(['profile_id' => 7] + $data, $choix, 'openai::gpt-5.6', false);
        $this->assertStringContainsString('l\'assistant « Veille commerciale » répond avec le modèle « GPT-5.6 »', $avecAssistant[0]);
    }

    public function testLeModeleEffectifSuitLaRegleDuTour(): void
    {
        $choix = self::choix();
        $choix['profiles'][7]['default_llm'] = 'openai::gpt-5.6';
        $catalog = ['openai::gpt-5.6' => 'GPT-5.6'];
        $this->assertSame('openai::gpt-5.6', ScheduleTools::effectiveLlm(['profile_id' => 7, 'llm' => ''], $choix, 'defaut::app', $catalog));
        $choix['profiles'][7]['default_llm'] = 'retire::modele';
        $this->assertSame('defaut::app', ScheduleTools::effectiveLlm(['profile_id' => 7, 'llm' => ''], $choix, 'defaut::app', $catalog), 'Modèle retiré : défaut.');
        $this->assertSame('defaut::app', ScheduleTools::effectiveLlm(['profile_id' => null, 'llm' => ''], $choix, 'defaut::app', $catalog));
        $this->assertSame('openai::gpt-5.6', ScheduleTools::effectiveLlm(['profile_id' => null, 'llm' => 'openai::gpt-5.6'], $choix, null, $catalog));
    }

    public function testLaTacheEnAttenteEstRappeleeAuModele(): void
    {
        $note = ScheduleTools::pendingNote(self::tacheEnAttente(), self::choixComplets());
        $this->assertStringContainsString('Tâche programmée EN ATTENTE', $note);
        $this->assertStringContainsString("<<<\nFais le point sur mes mails.\nTrois puces au plus.\n>>>", $note, 'Consigne en entier.');
        $this->assertStringContainsString('frequence chaque_semaine, jours 1, heure 08:00', $note, 'Rythme dans le vocabulaire de l’outil.');
        $this->assertStringContainsString('assistant : 7 = « Veille commerciale »', $note);
        $this->assertStringContainsString('bases : 3 = « Catalogue produits »', $note);
        $this->assertStringContainsString('`remplace: true` et UNIQUEMENT les champs modifiés', $note);

        $tools = new ScheduleTools($this->tour(), self::choixComplets(), ['profile_id' => 7, 'llm' => '', 'sources' => []], self::tacheEnAttente());
        $this->assertStringContainsString('Tâche programmée EN ATTENTE', $tools->guidance());
        $sans = new ScheduleTools($this->tour(), self::choixComplets(), ['profile_id' => 7, 'llm' => '', 'sources' => []]);
        $this->assertStringNotContainsString('EN ATTENTE', $sans->guidance());
    }

    public function testSurDemandeLaDefinitionDecritLesChoixDuCompte(): void
    {
        $tools = new ScheduleTools($this->tour(), self::choixComplets(), ['profile_id' => 7, 'llm' => '', 'sources' => []]);
        $props = $tools->definitions()[0]['parameters']['properties'];
        $this->assertStringContainsString('7 = « Veille commerciale » [ses bases : Veille marché]', $props['assistant']['description']);
        $this->assertStringContainsString('Par défaut : celui de cette conversation (« Veille commerciale »)', $props['assistant']['description']);
        $this->assertSame(['', 'openai::gpt-5.6'], $props['modele']['enum']);
        $this->assertStringContainsString('« openai::gpt-5.6 » = GPT-5.6 [outils]', $props['modele']['description']);
        $this->assertStringContainsString('3 = « Catalogue produits »', $props['bases']['description']);
        $this->assertStringNotContainsString('Brouillons', $props['bases']['description'], 'Base indisponible : jamais proposée.');
        $this->assertStringContainsString('12 = « Automatisations » (', $props['outils']['description']);
        $this->assertStringContainsString('agit : actions à approuver', $props['outils']['description']);
        $this->assertStringNotContainsString('Gamma en panne', $props['outils']['description']);
        $this->assertSame('integer', $props['bases']['items']['type']);
        $this->assertSame('integer', $props['outils']['items']['type']);
        $this->assertSame(['sources', 'bases', 'outils'], $props['vider']['items']['enum']);
        $this->assertStringContainsString('Compose une tâche COHÉRENTE', $tools->guidance());
    }

    public function testLesNotesPreviennentSansBloquer(): void
    {
        $choix = self::choixComplets();
        $choix['sources']['gmail']['connected'] = false;
        $data = ['profile_id' => 7, 'llm' => '', 'sources' => ['gmail'], 'kb_ids' => [3], 'tool_ids' => [11, 12], 'allow_actions' => true];
        $input = ['kb_ids' => ['3', '8'], 'tool_ids' => ['11', '13', '12'], 'allow_actions' => ''];
        $notes = implode(' | ', ScheduleTools::notes($data, $input, ['modele' => 'openai::gpt-5.6'], $choix));
        $this->assertStringContainsString('Modèle ignoré : un assistant impose le sien', $notes);
        $this->assertStringContainsString('Pas encore relié au compte : Gmail', $notes);
        $this->assertStringContainsString('Déjà apportées par l\'assistant, interrogées d\'office : Veille marché', $notes);
        $this->assertStringContainsString('Un seul outil par type : non retenu(s) Images bis', $notes);
        $this->assertStringContainsString('La tâche préparera des actions à approuver', $notes);
        $this->assertSame([], ScheduleTools::notes(['profile_id' => null, 'sources' => ['web'], 'tool_ids' => [], 'allow_actions' => false], [], [], self::choixComplets()));
    }

    public function testLaCarteDitLaConsigneLesSourcesARelierEtLaNotification(): void
    {
        $choix = self::choixComplets();
        $choix['sources']['gmail']['connected'] = false;
        $data = ScheduledTask::normalizeInput(self::tacheEnAttente(), ScheduledTask::allowedFrom($choix));
        $preview = ScheduledTask::actionPreview($data, $choix, new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('UTC')));
        $this->assertSame('Consigne', $preview['apercu_titre']);
        $impacts = implode(' | ', $preview['impacts']);
        $this->assertStringContainsString('Sources consultées : Gmail (à connecter)', $impacts);
        $this->assertStringContainsString('Vous prévient sur vos appareils quand le résultat arrive', $impacts);
        $this->assertStringContainsString('Bases de connaissances : Catalogue produits', $impacts);
        $data['notify'] = false;
        $this->assertContains('Sans notification sur vos appareils', ScheduledTask::actionPreview($data, $choix, new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('UTC')))['impacts']);
    }

    // ------------------------------------------------------------------
    // La carte
    // ------------------------------------------------------------------

    public function testLaCarteMontreCeQuiSeraProgramme(): void
    {
        $data = ScheduledTask::normalizeInput([
            'name' => 'Point du lundi', 'instruction' => 'Fais le point.', 'profile_id' => '7', 'llm' => '',
            'freq' => 'weekly', 'days' => ['1'], 'time' => '08:00', 'timezone' => 'Europe/Paris',
            'sources' => ['gmail'], 'destination' => 'thread',
        ], ScheduledTask::allowedFrom(self::choix()));
        $preview = ScheduledTask::actionPreview($data, self::choix(), new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('UTC')));

        $this->assertSame('Programmer une tâche', $preview['action']);
        $this->assertSame('Point du lundi', $preview['fichier']);
        $this->assertSame('Fais le point.', $preview['apercu']);
        $this->assertTrue($preview['ajuster']);
        $this->assertSame('/assets/img/routine.svg', $preview['icone']);
        $impacts = implode(' | ', $preview['impacts']);
        $this->assertStringContainsString("Chaque lundi à 8\u{00A0}h\u{00A0}00 (heure de Paris)", $impacts);
        $this->assertStringContainsString("Première exécution : lun. 5 oct. à 8\u{00A0}h\u{00A0}00", $impacts);
        $this->assertStringContainsString('Avec l’assistant « Veille commerciale »', $impacts);
        $this->assertStringContainsString('Sources consultées : Gmail', $impacts);
        $this->assertStringContainsString('Lecture seule', $impacts);
        // La carte rejoue la saisie à l'identique : ses arguments relus redonnent la même tâche.
        $this->assertSame($data, ScheduledTask::normalizeInput(ScheduledTask::toInput($data), ScheduledTask::allowedFrom(self::choix())));
    }

    public function testLaCarteGeneriqueSaitAjuster(): void
    {
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('const adjustLink = data.ajuster', $js);
        $this->assertStringContainsString('/settings/schedules/new?action=${encodeURIComponent(String(data.id))}', $js);
        $this->assertStringContainsString("schedule_prepare:          t('Préparation de la tâche programmée…')", $js);
    }

    // ------------------------------------------------------------------
    // Câblage : exposition, file d'approbation, approbation
    // ------------------------------------------------------------------

    public function testLOutilNEstExposeQueSurDemandeOuDansUneBoucleDejaLanceeJamaisPendantUneTache(): void
    {
        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        // Fonctionnalité proposée ET droit du rôle « Tâches — Créer » (ScheduledTask::allows).
        $this->assertStringContainsString("\$scheduleRequest  = \$supportsTools && !\$unattended && ScheduledTask::allows(\$user, 'create')", $runSend);
        // Une tâche en attente garde l'outil exposé : « plutôt à 9 h » la corrige.
        $this->assertStringContainsString('|| DrivePendingAction::hasPendingForTool($convId, (int)$user[\'id\'], ScheduleTools::TOOL));', $runSend);
        $this->assertStringContainsString('$explicitToolSources = $explicitToolSources || $scheduleRequest;', $runSend);
        $this->assertStringContainsString("if (\$scheduleRequest || (\$loopRunsAnyway && \$supportsTools && !\$unattended && ScheduledTask::allows(\$user, 'create'))) {", $runSend);
        // Choix LÉGERS pour décrire l'outil à chaque message ; la validation
        // d'un appel relit les choix en entier (bases, outils).
        $this->assertStringContainsString('$registry->add(new ScheduleTools($turn, ScheduledTask::choicesFor($user, $scheduleRequest), [', $runSend);
        $this->assertStringContainsString('], $pendingTask !== null ? (array)$pendingTask[\'args\'] : null));', $runSend);
        $this->assertStringContainsString('$choices = ScheduledTask::choicesFor($this->turn->user);', self::src('src/Agent/Tools/ScheduleTools.php'));
        // L'aperçu calculé par le fournisseur voyage par la file d'approbation.
        $this->assertStringContainsString("\$tool === 'programmer_tache'        => (array)(\$fileRef['preview'] ?? []),", $runSend);
    }

    public function testLApprobationRejoueLesReglesDuFormulaireAuMomentT(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString("if ((string)\$action['tool'] === 'programmer_tache') {", self::methode($chat, 'decideAction'));
        $approve = self::methode($chat, 'approveScheduledTask');
        $this->assertStringContainsString('ScheduledTask::normalizeInput(', $approve);
        $this->assertStringContainsString('ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user))', $approve);
        $this->assertStringContainsString('ScheduledTask::countActive($userId) >= ScheduledTask::MAX_ACTIVE_PER_USER', $approve);
        // Plafond atteint : la carte reste en attente, rien n'est consommé.
        $this->assertStringContainsString('DrivePendingAction::release($actionId);', $approve);
        $this->assertStringContainsString("'retryable' => true", $approve);
        $this->assertLessThan(strpos($approve, 'ScheduledTask::create('), strpos($approve, 'ScheduledTask::countActive('));

        // Trace : un libellé et une famille d'icône, l'horloge.
        $this->assertSame('Préparation d\'une tâche programmée', ChatController::ACTION_LABELS['programmer_tache'] ?? null);
        $this->assertSame('routine', ChatController::ACTION_ICONS['programmer_tache'] ?? null);
        $this->assertStringContainsString('.msg-trace-icon[data-icon="routine"]', self::src('public/assets/css/main.css'));
    }

    public function testAjusterOuvreLeFormulaireEtFermeLaCarte(): void
    {
        $ctrl = self::src('src/Controllers/ScheduledTaskController.php');
        $create = self::methode($ctrl, 'create');
        $this->assertStringContainsString('DrivePendingAction::findPendingForUser($actionId, (int)$user[\'id\'])', $create);
        $this->assertStringContainsString("(string)\$pending['tool'] === 'programmer_tache'", $create);
        $store = self::methode($ctrl, 'store');
        $this->assertStringContainsString('$this->closeAdjustedCard($fromAction, $userId, $id, $data, $next);', $store);
        $close = self::methode($ctrl, 'closeAdjustedCard');
        $this->assertStringContainsString('DrivePendingAction::claim($actionId, $userId, $convId)', $close);
        $this->assertStringContainsString("DrivePendingAction::resolve(\$actionId, 'executed');", $close);
        $this->assertStringContainsString('name="from_action"', self::src('templates/settings/schedule-form.php'));
    }

    public function testUneRetoucheRemplaceLaCarteDAvant(): void
    {
        $tools = self::src('src/Agent/Tools/ScheduleTools.php');
        $handler = substr($tools, (int)strpos($tools, 'self::TOOL => function (array $args): array {'));
        // La tâche en attente est la base de la retouche ; sans elle, `remplace` est une erreur.
        $this->assertStringContainsString('DrivePendingAction::latestPendingForTool($this->turn->convId, $this->turn->userId, self::TOOL)', $handler);
        // Retouche : les vides ne sont jamais pris pour un effacement.
        $this->assertLessThan(strpos($handler, 'self::input($args'), strpos($handler, '$args = self::revisionArgs($args, array_keys(self::REVISION_LISTS));'));
        $this->assertStringContainsString('Aucune tâche n\\\'attend dans cette conversation', $handler);
        // Contrôles AVANT la carte : désignations, règles du formulaire, cohérence, plafond.
        $file = strpos($handler, '($this->turn->queueWrite)(');
        foreach (['self::resolveRefs(', 'ScheduledTask::normalizeInput(', 'self::coherenceIssues(', 'ScheduledTask::countActive('] as $avant) {
            $this->assertLessThan($file, strpos($handler, $avant), $avant);
        }
        // Puis la carte d'avant est close, et grisée à l'écran.
        $this->assertGreaterThan($file, strpos($handler, 'DrivePendingAction::supersede((int)$previous[\'id\']'));
        $this->assertStringContainsString("\$this->sseEmit(['drive_superseded' => ['ids' => [(int)\$previous['id']]]]);", $handler);
        $this->assertStringContainsString("\$result['proposition_remplacee'] = true;", $handler);

        // Mécanique partagée avec les assistants préparés : une seule implémentation.
        $draft = self::src('src/Assistants/AssistantDraft.php');
        $this->assertStringContainsString('return DrivePendingAction::latestPendingForTool($convId, $userId, self::TOOL);', $draft);
        $this->assertStringContainsString('return DrivePendingAction::hasPendingForTool($convId, $userId, self::TOOL);', $draft);
        $this->assertStringContainsString('return DrivePendingAction::supersede($actionId, $userId, $convId);', $draft);
        $this->assertSame('programmer_tache', ScheduleTools::TOOL);
    }

    public function testUneTacheApprouveeSeLanceDepuisLaCarte(): void
    {
        $approve = self::methode(self::src('src/Controllers/ChatController.php'), 'approveScheduledTask');
        $this->assertStringContainsString("'task_id' => \$taskId,", $approve);
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('if (r.task_id) {', $js);
        $this->assertStringContainsString("runBtn.textContent = t('Exécuter maintenant');", $js);
        $run = substr($js, (int)strpos($js, 'async function runScheduledTaskNow('), 1400);
        // La route du bouton de la liste : mêmes contrôles côté serveur.
        $this->assertStringContainsString('`/settings/schedules/${encodeURIComponent(String(taskId))}/run`', $run);
        $this->assertStringContainsString("'Accept': 'application/json'", $run);
        $this->assertStringContainsString("'Exécuter maintenant' => 'Run now'", self::src('lang/en.client.php'));
    }

    public function testSurUnModeleSansOutilsMIADonneLesChemins(): void
    {
        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        $this->assertStringContainsString("if (!\$supportsTools && !\$unattended && ScheduledTask::allows(\$user, 'create')\n                && ScheduledTask::looksLikeScheduleRequest(\$content)) {", $runSend);
        $this->assertStringContainsString('de la créer depuis Espaces → Tâches programmées', $runSend);
    }

    // ------------------------------------------------------------------
    // L'horloge de l'assistant
    // ------------------------------------------------------------------

    public function testLHorlogeEstDansLaRangeeDeLAssistantACoteDeModifier(): void
    {
        $index = self::src('templates/chat/index.php');
        $horloge  = strpos($index, 'id="assistantScheduleBtn"');
        $modifier = strpos($index, 'id="profileEditBtn"');
        $this->assertIsInt($horloge);
        $this->assertLessThan($modifier, $horloge, 'Premier bouton de droite, « Modifier » juste après.');
        $this->assertStringContainsString('href="/settings/schedules/new?compose=1', $index);
        $this->assertStringContainsString('<?= ScheduledTask::icon(13) ?>', $index);

        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString("document.getElementById('assistantScheduleBtn')?.addEventListener('click'", $js);
        // Le texte en cours de saisie ne passe JAMAIS par l'adresse.
        $ouvrir = substr($js, (int)strpos($js, 'function openScheduleFromComposer('), 1200);
        $this->assertStringContainsString('sessionStorage.setItem(SCHEDULE_DRAFT_KEY', $ouvrir);
        $this->assertStringNotContainsString("params.set('text'", $ouvrir);
        $this->assertStringContainsString("scheduleBtn.href = '/settings/schedules/new?compose=1&profile='", $js);

        $form = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("form.dataset.compose === '1'", $form);
        $this->assertStringContainsString("sessionStorage.removeItem('mia.scheduleDraft')", $form);

        $ctrl = self::methode(self::src('src/Controllers/ScheduledTaskController.php'), 'prefillFromComposer');
        $this->assertStringContainsString("isset(\$choices['profiles'][\$profileId])", $ctrl, 'Assistant de l’adresse validé.');
        $this->assertStringContainsString("isset(\$choices['llms'][\$llm])", $ctrl, 'Modèle de l’adresse validé.');
    }

    /** Une seule icône pour la routine : le tracé du modèle, partout. */
    public function testUneSeuleHorlogePartout(): void
    {
        $this->assertStringContainsString("'schedules' => ScheduledTask::icon(\$size),", self::src('src/Helpers/NavRegistry.php'), 'Tuile, menu et palette : la même horloge.');
        foreach (['templates/chat/index.php', 'templates/partials/message.php',
                  'templates/admin/tools.php', 'templates/settings/schedules.php'] as $gabarit) {
            $src = self::src($gabarit);
            $this->assertStringContainsString('ScheduledTask::icon(', $src, "$gabarit n'emploie pas l'horloge commune");
            $this->assertStringNotContainsString('polyline points="17 1 21 5 17 9"', $src, "$gabarit garde l'ancienne icône");
        }
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('window.SCHEDULE_ICON', $js);
        $this->assertStringNotContainsString('polyline points="17 1 21 5 17 9"', $js);
        $this->assertStringContainsString('window.SCHEDULE_ICON = <?= json_encode(ScheduledTask::ICON_PATHS) ?>;', self::src('templates/chat/index.php'));
        // Le glyphe de la trace et l'image de la carte portent le même tracé.
        $this->assertStringContainsString("M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8", self::src('public/assets/img/routine.svg'));
        $this->assertStringContainsString("%3Cpath d='M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8'/%3E", self::src('public/assets/css/main.css'));
    }

    // ------------------------------------------------------------------
    // Actions à approuver
    // ------------------------------------------------------------------

    public function testUneTachePeutPreparerDesActionsQuiAttendentUneSemaine(): void
    {
        $sql = self::src('database/migrations/082_scheduled_task_actions.sql');
        $this->assertStringContainsString('ADD COLUMN allow_actions TINYINT(1) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('ADD COLUMN expires_at DATETIME NULL DEFAULT NULL', $sql);
        $this->assertSame(7 * 24 * 60, ScheduledTask::ACTION_TTL_MINUTES);

        // Chaque lecture de la file respecte l'expiration PROPRE d'une carte.
        $file = self::src('src/Models/DrivePendingAction.php');
        foreach (['claim', 'findPending', 'updatePending', 'pendingForConversation', 'countPendingByConversation'] as $fn) {
            $this->assertStringContainsString('expires_at >= NOW() OR (expires_at IS NULL AND created_at >= NOW() - INTERVAL', self::methode($file, $fn), $fn);
        }

        // La charge du job porte l'option, la consigne s'adapte.
        $this->assertStringContainsString("'allow_actions' => !empty(\$task['allow_actions'])", self::src('src/Schedule/Scheduler.php'));
        $this->assertStringContainsString('Tu peux PRÉPARER des actions', Scheduler::promptNote(['name' => 'X', 'allow_actions' => true]));
        $this->assertStringContainsString('Lecture seule', Scheduler::promptNote(['name' => 'X', 'allow_actions' => false]));

        // runSend : la carte est déposée puis prolongée ; sans l'option, refus.
        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        $this->assertStringContainsString('if ($scheduledMode === ScheduledTask::MODE_READ) {', $runSend);
        $this->assertStringContainsString('DrivePendingAction::extend($id, $userId, ScheduledTask::ACTION_TTL_MINUTES);', $runSend);

        $form = self::src('templates/settings/schedule-form.php');
        $this->assertStringContainsString('name="allow_actions" value="1"', $form);
        $this->assertStringContainsString("I18n::plural(\$row['pending'], '{n} action à approuver', '{n} actions à approuver')", self::src('templates/settings/schedules.php'));
    }

    private function tour(): AgentTurn
    {
        return new AgentTurn(1, 1, ['id' => 1], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
    }
}
