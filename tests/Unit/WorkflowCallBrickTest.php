<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Brique « Appeler un workflow » (cf. CLAUDE.md « Workflows ») : sa forme,
 * ce que MIA signale dans « À régler » (paramètre manquant ou de mauvaise
 * nature, workflow absent, appels en boucle, trop de niveaux, pièce récupérée
 * qui a changé), la parité du rail du navigateur, creer_workflow, le journal
 * et le volet d'avancement, et le câblage. Les parcours d'exécution sont dans
 * tests/Functional/WorkflowCallPipelineTest.php.
 */
final class WorkflowCallBrickTest extends TestCase
{
    private const CHILD = 7;
    private const SELF = 1;

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    /** L'appelé : un départ de toutes les natures, un livrable à deux pièces. */
    private static function child(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true],
                ['key' => 'echeance', 'label' => 'Échéance', 'type' => 'date'],
                ['key' => 'contact', 'label' => 'Contact', 'type' => 'email'],
                ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
                ['key' => 'priorite', 'label' => 'Priorité', 'type' => 'choice', 'options' => ['Haute', 'Basse']],
                ['key' => 'contrat', 'label' => 'Contrat', 'type' => 'file'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'longtext', 'default' => 'Rien à signaler'],
            ]],
            'nodes' => [
                ['key' => 'analyser', 'type' => 'step', 'title' => 'Analyser', 'instruction' => 'Analyse @{depart}.',
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'fiche', 'fields' => [
                        ['key' => 'total', 'label' => 'Total', 'type' => 'number'],
                        ['key' => 'resume', 'label' => 'Résumé', 'type' => 'text'],
                    ]]],
                ['key' => 'rediger', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Rédige depuis @{synthese}.',
                    'output' => ['key' => 'courrier', 'name' => 'Courrier', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['synthese', 'courrier']],
        ]);
    }

    /** Ce que l'éditeur et « À régler » savent des autres workflows. */
    private static function ctx(array $more = []): array
    {
        return ['self_id' => self::SELF, 'workflows' => $more + [
            self::CHILD => WorkflowCall::signature(['name' => 'Chiffrage', 'color' => '#0d9488', 'published' => self::child(), 'is_active' => 1]),
        ]];
    }

    /** L'appelant : un départ, une Fiche et un texte au dossier, puis l'appel. */
    private static function caller(array $params, array $call = []): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [
                ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                ['key' => 'budget', 'label' => 'Budget', 'type' => 'number'],
                ['key' => 'limite', 'label' => 'Date limite', 'type' => 'date'],
                ['key' => 'mail', 'label' => 'Mail', 'type' => 'email'],
                ['key' => 'prio', 'label' => 'Prio', 'type' => 'choice', 'options' => ['Haute', 'Basse']],
                ['key' => 'niveau', 'label' => 'Niveau', 'type' => 'choice', 'options' => ['Haute', 'Moyenne']],
                ['key' => 'piece_jointe', 'label' => 'Pièce jointe', 'type' => 'file'],
            ]],
            'nodes' => [
                ['key' => 'preparer', 'type' => 'step', 'title' => 'Préparer', 'instruction' => 'Prépare @{depart}.',
                    'output' => ['key' => 'brief', 'name' => 'Brief', 'form' => 'text']],
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste.',
                    'output' => ['key' => 'points', 'name' => 'Points', 'form' => 'list']],
                $call + ['key' => 'appel', 'type' => 'call', 'title' => 'Chiffrer', 'workflow_id' => self::CHILD, 'params' => $params,
                    'output' => ['key' => 'chiffrage', 'name' => 'Chiffrage reçu', 'from' => 'synthese', 'form' => 'fiche', 'fields' => [
                        ['key' => 'total', 'label' => 'Total', 'type' => 'number'],
                        ['key' => 'resume', 'label' => 'Résumé', 'type' => 'text'],
                    ]]],
                ['key' => 'conclure', 'type' => 'step', 'title' => 'Conclure', 'instruction' => 'Conclus avec @{chiffrage.total}.',
                    'output' => ['key' => 'conclusion', 'name' => 'Conclusion', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['conclusion']],
        ]);
    }

    /** Points « À régler » de la brique d'appel : [cible => [niveau, texte]…]. */
    private static function callIssues(array $def, ?array $ctx = null): array
    {
        $out = [];
        foreach (WorkflowSpec::issues($def, $ctx ?? self::ctx()) as $i) {
            if ($i['node'] === 'appel') {
                $out[$i['target']][] = [$i['level'], $i['text']];
            }
        }
        return $out;
    }

    private const GOOD = ['client' => '@{depart.nom}', 'montant' => '@{depart.budget}'];

    // ------------------------------------------------------------------
    // Forme
    // ------------------------------------------------------------------

    public function testLaBriqueSeNormaliseEtRemetUnePiece(): void
    {
        $def = self::caller(self::GOOD + ['Mauvaise clé' => 'x', 'vide' => '   ', 'note' => str_repeat('a', 5000)], ['on_stop' => 'continue', 'on_error' => ['retries' => 3, 'then' => 'skip']]);
        $call = $def['nodes'][2];
        $this->assertSame('call', $call['type']);
        $this->assertSame(self::CHILD, $call['workflow_id']);
        $this->assertSame(['client', 'montant', 'note'], array_keys($call['params']), 'Clé invalide et valeur vide écartées.');
        $this->assertSame(WorkflowSpec::CALL_PARAM_MAX, mb_strlen($call['params']['note']), 'Valeur bornée.');
        $this->assertSame(WorkflowSpec::CALL_STOP_CONTINUE, $call['on_stop']);
        $this->assertSame(['retries' => 0, 'then' => WorkflowSpec::ON_ERROR_SKIP], $call['on_error'], 'Aucun essai automatique : les briques de l’appelé réessaient déjà.');
        $this->assertSame(['chiffrage', 'fiche', 'synthese'], [$call['output']['key'], $call['output']['form'], $call['output']['from']]);
        $this->assertArrayNotHasKey('tool_id', $call['output'], 'Ni outil de création ni modèle : la pièce arrive faite.');

        // Par défaut : tout le livrable, en texte ; l'appel s'arrête avec l'appelé.
        $plain = WorkflowSpec::normalize(['nodes' => [['key' => 'a', 'type' => 'call', 'title' => 'A', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'fiche']]]])['nodes'][0];
        $this->assertSame(['text', '', WorkflowSpec::CALL_STOP_STOP, null], [$plain['output']['form'], $plain['output']['from'], $plain['on_stop'], $plain['workflow_id']]);

        // Elle remet une pièce comme une étape : au dossier après elle, pas avant.
        $pieces = WorkflowSpec::pieces($def);
        $this->assertSame('Chiffrage reçu', $pieces['chiffrage']['name']);
        $this->assertSame(['total', 'resume'], array_column($pieces['chiffrage']['fields'], 'key'));
        $this->assertArrayNotHasKey('chiffrage', WorkflowSpec::available($def, 'appel'));
        $this->assertArrayHasKey('chiffrage', WorkflowSpec::available($def, 'conclure'));
        $this->assertTrue(WorkflowSpec::producesPiece($call));
        // Ce qu'elle lit : les pièces citées par ses paramètres.
        $this->assertSame(['depart'], WorkflowSpec::readsOf($def, $call));
        $this->assertSame(1, WorkflowSpec::counts($def)['calls']);
        $this->assertStringContainsString('p', WorkflowSpec::silhouette($def));
        $this->assertContains('call', WorkflowCover::features($def));
    }

    public function testElleVitSurLeRailDansUneVoieEtDansUneBoucle(): void
    {
        $call = ['type' => 'call', 'title' => 'Appel', 'workflow_id' => self::CHILD];
        $def = WorkflowSpec::normalize([
            'nodes' => [
                $call + ['key' => 'c1', 'output' => ['key' => 'r1', 'name' => 'R1']],
                ['key' => 'cond', 'type' => 'condition', 'title' => 'Cond', 'mode' => 'question', 'question' => 'Oui ?',
                    'yes' => ['nodes' => [$call + ['key' => 'c2', 'output' => ['key' => 'r2', 'name' => 'R2']]]], 'no' => ['nodes' => []]],
                ['key' => 'liste', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste.', 'output' => ['key' => 'elements', 'name' => 'Éléments', 'form' => 'list']],
                ['key' => 'boucle', 'type' => 'loop', 'title' => 'Pour chacun', 'mode' => 'each', 'over' => 'elements',
                    'item' => ['key' => 'element', 'name' => 'Élément'],
                    'body' => [$call + ['key' => 'c3', 'params' => ['client' => '@{element}'], 'output' => ['key' => 'r3', 'name' => 'R3']]]],
            ],
        ]);
        $this->assertSame(['c1', 'c2', 'c3'], array_column(WorkflowCall::callNodes($def), 'key'));
        $this->assertSame([self::CHILD], WorkflowCall::callsOf($def));
        // Dans une boucle « pour chaque », la pièce de l'appel est rassemblée à la sortie.
        $this->assertSame('r3', WorkflowSpec::loopCollect($def['nodes'][3]));
    }

    public function testLaSignatureDitCeQuAttendEtRemetUnWorkflow(): void
    {
        $sig = self::ctx()['workflows'][self::CHILD];
        $this->assertSame(['Chiffrage', true, true], [$sig['name'], $sig['published'], $sig['active']]);
        $this->assertSame(['client', 'montant', 'echeance', 'contact', 'urgent', 'priorite', 'contrat', 'note'], array_column($sig['fields'], 'key'));
        $this->assertSame(['Haute', 'Basse'], $sig['fields'][5]['options']);
        $this->assertSame('Rien à signaler', $sig['fields'][7]['default']);
        $this->assertSame(['synthese', 'courrier'], array_column($sig['outputs'], 'key'));
        $this->assertSame(['total', 'resume'], array_column($sig['outputs'][0]['fields'], 'key'));
        $this->assertNull($sig['event']);
        $this->assertSame([], $sig['calls']);

        $draft = WorkflowCall::signature(['name' => 'Brouillon', 'color' => '', 'published' => null, 'is_active' => 1]);
        $this->assertSame([false, [], []], [$draft['published'], $draft['fields'], $draft['outputs']]);

        // Un workflow lancé par un évènement : sa pièce d'évènement se donne en paramètre.
        $event = WorkflowCall::signature(['name' => 'Tri', 'color' => '', 'is_active' => 1, 'published' => WorkflowSpec::normalize([
            'start' => ['event' => ['kind' => 'email', 'source' => 'gmail']],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'Lis.', 'output' => ['key' => 'p', 'name' => 'P', 'form' => 'text']]],
        ])]);
        $this->assertSame('email', $event['event']['kind']);
    }

    // ------------------------------------------------------------------
    // « À régler » : paramètres
    // ------------------------------------------------------------------

    public function testDesParametresJustesNeSignalentRien(): void
    {
        $def = self::caller(self::GOOD + [
            'echeance' => '@{depart.limite}', 'contact' => '@{depart.mail}', 'urgent' => 'oui',
            'priorite' => '@{depart.prio}', 'contrat' => '@{piece_jointe}', 'note' => 'Voir @{brief} et @{points}.',
        ]);
        $this->assertSame([], self::callIssues($def));
        $this->assertTrue(WorkflowSpec::publishable(WorkflowSpec::issues($def, self::ctx())));
        // Valeurs écrites en dur, de la bonne nature.
        $this->assertSame([], self::callIssues(self::caller(['client' => 'Dupont', 'montant' => '12 500,50', 'echeance' => '30 octobre 2026',
            'contact' => 'a@b.fr', 'urgent' => 'non', 'priorite' => 'Basse'])));
    }

    public function testUnParametreObligatoireManquantBloque(): void
    {
        $issues = self::callIssues(self::caller(['client' => '@{depart.nom}']));
        $this->assertSame([[WorkflowSpec::LEVEL_BLOCK, '« Chiffrage » a besoin de « Montant » : donnez-lui une valeur.']], $issues['param:montant']);
        $this->assertArrayNotHasKey('param:echeance', $issues, 'Facultatif : rien.');
        $this->assertArrayNotHasKey('param:note', $issues, 'Sa valeur par défaut suffit.');
        $this->assertFalse(WorkflowSpec::publishable(WorkflowSpec::issues(self::caller(['client' => '@{depart.nom}']), self::ctx())));

        // Un fichier obligatoire : une pièce, sauf si l'appelé porte son document de référence.
        $child = self::child();
        $child['start']['fields'][6]['required'] = true;
        $ctx = self::ctx();
        $ctx['workflows'][self::CHILD] = WorkflowCall::signature(['name' => 'Chiffrage', 'color' => '', 'published' => $child, 'is_active' => 1]);
        $this->assertSame('« Chiffrage » a besoin du fichier « Contrat » : donnez-lui une pièce du dossier.', self::callIssues(self::caller(self::GOOD), $ctx)['param:contrat'][0][1]);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> [champ, valeur, niveau attendu ('' = rien), fragment du texte] */
    public static function natures(): iterable
    {
        $b = WorkflowSpec::LEVEL_BLOCK;
        $w = WorkflowSpec::LEVEL_WARN;
        yield 'nombre écrit en lettres' => ['montant', 'beaucoup', $b, '« Montant » attend un nombre : « beaucoup » ne convient pas.'];
        yield 'nombre ← champ nombre' => ['montant', '@{depart.budget}', '', ''];
        yield 'nombre ← champ date' => ['montant', '@{depart.limite}', $b, '« Montant » attend un nombre : « Informations de départ › Date limite » est une date.'];
        yield 'nombre ← champ texte' => ['montant', '@{depart.nom}', $w, 'est un texte. Vérifiez'];
        yield 'nombre ← pièce texte' => ['montant', '@{brief}', $w, '« Brief » est un texte libre'];
        yield 'nombre ← pièce liste' => ['montant', '@{points}', $b, 'la pièce « Points » ne convient pas'];
        yield 'nombre ← texte composé' => ['montant', 'Environ @{depart.budget} €', $w, 'un texte composé risque de ne pas convenir'];
        yield 'date invalide' => ['echeance', 'bientôt', $b, '« Échéance » attend une date'];
        yield 'date ← champ nombre' => ['echeance', '@{depart.budget}', $b, 'est un nombre'];
        yield 'email invalide' => ['contact', 'pas une adresse', $b, '« Contact » attend une adresse email'];
        yield 'email ← champ email' => ['contact', '@{depart.mail}', '', ''];
        yield 'oui/non invalide' => ['urgent', 'peut-être', $b, '« Urgent » attend « oui » ou « non »'];
        yield 'choix hors options' => ['priorite', 'Moyenne', $b, '« Priorité » n’accepte que : Haute, Basse.'];
        yield 'choix ← mêmes options' => ['priorite', '@{depart.prio}', '', ''];
        yield 'choix ← autres options' => ['priorite', '@{depart.niveau}', $w, 'peut valoir autre chose'];
        yield 'choix ← champ nombre' => ['priorite', '@{depart.budget}', $b, 'est un nombre'];
        yield 'fichier ← texte' => ['contrat', 'Le contrat', $b, '« Contrat » attend un fichier : citez une pièce du dossier, et rien d’autre.'];
        yield 'fichier ← champ' => ['contrat', '@{depart.nom}', $b, 'attend un fichier'];
        yield 'fichier ← pièce + texte' => ['contrat', 'Voir @{brief}', $b, 'attend un fichier'];
        yield 'fichier ← une pièce' => ['contrat', '@{brief}', '', ''];
        yield 'texte ← n’importe quoi' => ['client', 'Client @{depart.budget} du @{depart.limite}', '', ''];
    }

    /** @dataProvider natures */
    #[\PHPUnit\Framework\Attributes\DataProvider('natures')]
    public function testUnParametreDeMauvaiseNatureEstSignale(string $field, string $value, string $level, string $fragment): void
    {
        $issues = self::callIssues(self::caller([$field => $value] + self::GOOD))['param:' . $field] ?? [];
        if ($level === '') {
            $this->assertSame([], $issues);
            return;
        }
        $this->assertCount(1, $issues, json_encode($issues, JSON_UNESCAPED_UNICODE));
        $this->assertSame($level, $issues[0][0], $issues[0][1]);
        $this->assertStringContainsString($fragment, $issues[0][1]);
    }

    public function testUnParametreQuiCiteUnePieceAbsenteOuPasEncoreRemiseBloque(): void
    {
        // « conclusion » est remise APRÈS l'appel.
        $late = self::callIssues(self::caller(self::GOOD + ['note' => 'Voir @{conclusion}']));
        $this->assertSame(WorkflowSpec::LEVEL_BLOCK, $late['param:note'][0][0] ?? null, json_encode($late, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('« Conclusion », qui n’existe pas encore à ce point', $late['param:note'][0][1]);
        $gone = self::callIssues(self::caller(self::GOOD + ['note' => 'Voir @{disparue}']));
        $this->assertStringContainsString('une pièce qui n’existe plus', $gone['param:note'][0][1]);
        $field = self::callIssues(self::caller(['montant' => '@{depart.inconnu}'] + self::GOOD));
        $this->assertStringContainsString('un champ qui n’existe plus dans « Informations de départ »', $field['param:montant'][0][1]);
    }

    public function testUnAppeleQuiAChangeEstSignale(): void
    {
        // Un paramètre qui ne correspond plus à aucun champ : ignoré, et dit.
        $extra = self::callIssues(self::caller(self::GOOD + ['ancien_champ' => 'x']));
        $this->assertSame(WorkflowSpec::LEVEL_WARN, $extra['params'][0][0]);
        $this->assertStringContainsString('ne correspond plus à aucun de ses champs', $extra['params'][0][1]);

        // La pièce récupérée a disparu du livrable de l'appelé.
        $child = self::child();
        $child['end']['pieces'] = ['courrier'];
        $ctx = self::ctx();
        $ctx['workflows'][self::CHILD] = WorkflowCall::signature(['name' => 'Chiffrage', 'color' => '', 'published' => $child, 'is_active' => 1]);
        $this->assertSame(WorkflowSpec::LEVEL_BLOCK, self::callIssues(self::caller(self::GOOD), $ctx)['output'][0][0]);

        // Sa forme : identique, enrichie (rien ne casse), changée (bloque).
        $out = self::caller(self::GOOD)['nodes'][2]['output'];
        $src = self::ctx()['workflows'][self::CHILD]['outputs'][0];
        $this->assertSame('', WorkflowCall::shapeGap($out, $src));
        $this->assertSame('grown', WorkflowCall::shapeGap($out, ['fields' => array_merge($src['fields'], [['key' => 'plus', 'label' => 'Plus', 'type' => 'text']])] + $src));
        $this->assertSame('changed', WorkflowCall::shapeGap($out, ['form' => 'text'] + $src));
        $this->assertSame('changed', WorkflowCall::shapeGap($out, ['fields' => [['key' => 'total', 'label' => 'Total', 'type' => 'text'], $src['fields'][1]]] + $src));
        $this->assertSame('changed', WorkflowCall::shapeGap($out, ['fields' => [$src['fields'][0]]] + $src), 'Un champ lu ici a disparu.');

        // Ce que l'éditeur recopie en choisissant la pièce.
        $sig = self::ctx()['workflows'][self::CHILD];
        $this->assertSame(['from' => 'synthese', 'form' => 'fiche', 'fields' => $src['fields']], WorkflowCall::outputShape($sig, 'synthese'));
        $this->assertSame(['from' => 'courrier', 'form' => 'text'], WorkflowCall::outputShape($sig, 'courrier'));
        $this->assertSame(['from' => '', 'form' => 'text'], WorkflowCall::outputShape($sig, 'inconnue'));
    }

    // ------------------------------------------------------------------
    // « À régler » : le workflow appelé
    // ------------------------------------------------------------------

    public function testLeWorkflowAppeleDoitExisterEtrePublieEtActif(): void
    {
        $none = self::callIssues(self::caller(self::GOOD, ['workflow_id' => null]));
        $this->assertSame([[WorkflowSpec::LEVEL_BLOCK, 'Choisissez le workflow à appeler.']], $none['workflow']);

        $self = self::callIssues(self::caller(self::GOOD, ['workflow_id' => self::SELF]));
        $this->assertSame('Un workflow ne peut pas s’appeler lui-même.', $self['workflow'][0][1]);

        $gone = self::callIssues(self::caller(self::GOOD, ['workflow_id' => 99]));
        $this->assertSame('Le workflow appelé n’existe plus : choisissez-en un autre.', $gone['workflow'][0][1]);

        $draft = self::ctx();
        $draft['workflows'][self::CHILD]['published'] = false;
        $this->assertStringContainsString('n’est pas encore publié', self::callIssues(self::caller(self::GOOD), $draft)['workflow'][0][1]);

        $paused = self::ctx();
        $paused['workflows'][self::CHILD]['active'] = false;
        $this->assertStringContainsString('est en pause', self::callIssues(self::caller(self::GOOD), $paused)['workflow'][0][1]);

        // Sans la liste des workflows (rail d'une exécution, rendu sans choix) : rien d'inventé.
        $this->assertArrayNotHasKey('workflow', self::callIssues(self::caller(self::GOOD), []));
    }

    public function testLesAppelsEnBoucleEtTropDeNiveauxSontRefuses(): void
    {
        $w = static fn(string $name, array $calls): array => ['name' => $name, 'published' => true, 'active' => true, 'fields' => [], 'event' => null, 'outputs' => [], 'calls' => $calls];
        $all = [1 => $w('A', [2]), 2 => $w('B', [3]), 3 => $w('C', [1]), 4 => $w('D', []), 5 => $w('E', [4])];
        $this->assertSame([2, 3, 1], WorkflowCall::cyclePath($all, 2, 1), 'B → C → A : le chemin qui revient.');
        $this->assertSame([3, 1], WorkflowCall::cyclePath($all, 3, 1));
        $this->assertNull(WorkflowCall::cyclePath($all, 4, 1));
        $this->assertNull(WorkflowCall::cyclePath($all, 5, 1));
        $this->assertSame(0, WorkflowCall::depthBelow($all, 4));
        $this->assertSame(1, WorkflowCall::depthBelow($all, 5));
        // Un cycle ne fait jamais tourner le calcul sans fin.
        $this->assertLessThanOrEqual(3, WorkflowCall::depthBelow($all, 1));

        // Dans « À régler » : A appelle B, qui (par C) rappelle A.
        $sig = self::ctx()['workflows'][self::CHILD];
        $ctx = ['self_id' => self::SELF, 'workflows' => [self::CHILD => ['calls' => [8]] + $sig, 8 => $w('Relance', [self::SELF])]];
        $loop = self::callIssues(self::caller(self::GOOD), $ctx)['workflow'];
        $this->assertSame(WorkflowSpec::LEVEL_BLOCK, $loop[0][0]);
        $this->assertSame('Appels en boucle : « Chiffrage » → « Relance » appelle déjà ce workflow. Ils se relanceraient sans fin.', $loop[0][1]);

        // Profondeur : ce workflow → Chiffrage → N2 → N3 → N4 dépasse DEPTH_MAX.
        $this->assertSame(3, WorkflowCall::DEPTH_MAX);
        $deep = ['self_id' => self::SELF, 'workflows' => [self::CHILD => ['calls' => [8]] + $sig, 8 => $w('N2', [9]), 9 => $w('N3', [10]), 10 => $w('N4', [])]];
        $texts = array_column(self::callIssues(self::caller(self::GOOD), $deep)['workflow'], 1);
        $this->assertStringContainsString('Trop de workflows imbriqués', implode(' ', $texts));
        $ok = ['self_id' => self::SELF, 'workflows' => [self::CHILD => ['calls' => [8]] + $sig, 8 => $w('N2', [9]), 9 => $w('N3', [])]];
        $this->assertArrayNotHasKey('workflow', self::callIssues(self::caller(self::GOOD), $ok), 'Trois niveaux : permis.');

        // Lancé à la fin de l'appelé ET l'appelant : il se relancerait lui-même.
        $def = self::caller(self::GOOD);
        $def['start']['event'] = ['kind' => 'workflow', 'workflow_id' => self::CHILD];
        $this->assertStringContainsString('il se relancera lui-même', implode(' ', array_column(self::callIssues($def)['workflow'] ?? [], 1)));
    }

    // ------------------------------------------------------------------
    // Ce qui part vers l'appelé
    // ------------------------------------------------------------------

    public function testLesCitationsDUneValeurSontLues(): void
    {
        $this->assertSame([[['piece' => 'depart', 'field' => 'nom']], true], WorkflowCall::refs(' @{depart.nom} '));
        $this->assertSame([[['piece' => 'brief', 'field' => null]], true], WorkflowCall::refs('@{brief}'));
        [$refs, $only] = WorkflowCall::refs('Voir @{brief} et @{depart.nom}.');
        $this->assertCount(2, $refs);
        $this->assertFalse($only);
        $this->assertSame([[], false], WorkflowCall::refs('Dupont'));
        $this->assertSame(['un nombre', 'une date', 'une adresse email', '« oui » ou « non »', 'une liste', 'un texte'],
            array_map(static fn(string $t): string => WorkflowCall::nature($t), ['number', 'date', 'email', 'yesno', 'list', 'text']));
    }

    public function testLeTitreDUneBriqueSeCiteSansGuillemetsImbriques(): void
    {
        $this->assertSame('« Chiffrer »', WorkflowCall::cite('Chiffrer'));
        $this->assertSame('Appeler « Analyse client »', WorkflowCall::cite('Appeler « Analyse client »'), 'Déjà des guillemets : pas une seconde paire.');
        I18n::use('en');
        $this->assertSame('“Chiffrer”', WorkflowCall::cite('Chiffrer'));
        I18n::use('fr');
    }

    public function testCeQuiEstDonneEstDitEnClair(): void
    {
        $in = ['values' => ['client' => 'Dupont', 'montant' => '1200', 'note' => str_repeat('x', 400)], 'files' => ['contrat' => ['name' => 'Brief.docx']], 'event' => null];
        $passed = WorkflowCall::passed(self::child(), $in);
        $this->assertSame(['Client', 'Montant', 'Contrat', 'Note'], array_column($passed, 'label'));
        $this->assertSame('« Brief.docx »', $passed[2]['value']);
        $this->assertLessThanOrEqual(205, mb_strlen($passed[3]['value']), 'Une longue valeur est abrégée.');
    }

    // ------------------------------------------------------------------
    // Navigateur, creer_workflow, journal, avancement
    // ------------------------------------------------------------------

    public function testLeRailDuNavigateurCompteLAppelCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $def = self::caller(self::GOOD + ['note' => 'Voir @{brief}.']);
        $keys = ['preparer', 'appel', 'conclure', 'end'];
        $expected = ['available' => [], 'reads' => WorkflowSpec::readsOf($def, $def['nodes'][2])];
        foreach ($keys as $k) {
            $expected['available'][$k] = array_keys(WorkflowSpec::available($def, $k));
        }
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS,
            'workflows' => [['id' => self::CHILD, 'name' => 'Chiffrage', 'color' => '#0d9488', 'published' => true, 'active' => true]]];
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';'
            . 'var av = {}; ' . json_encode($keys) . '.forEach(function (k) { av[k] = Object.keys(R.available(d, k)); });'
            . 'var loop = { type: "loop", mode: "each", body: [{ key: "c", type: "call", output: { key: "r", name: "R", form: "file" } }] };'
            . 'process.stdout.write(JSON.stringify({ available: av, reads: R.readsOf(d, d.nodes[2]), produces: R.produces(d.nodes[2]), found: (R.findWorkflow(cat, ' . self::CHILD . ') || {}).name,'
            . ' fileCollect: R.loopCollectable(loop, loop.body[0]), html: R.html(d, { catalog: cat, editable: true }) }));';
        $file = tempnam(sys_get_temp_dir(), 'wfcall') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        $this->assertSame($expected['available'], $got['available']);
        $this->assertEqualsCanonicalizing($expected['reads'], $got['reads']);
        $this->assertTrue($got['produces']);
        $this->assertSame('Chiffrage', $got['found']);
        $this->assertFalse($got['fileCollect'], 'Un fichier récupéré dans une boucle « pour chaque » ne se rassemble pas (comme côté serveur).');
        $this->assertNull(WorkflowSpec::loopCollect(['mode' => 'each', 'body' => [['key' => 'c', 'type' => 'call', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'file']]]]));
        $this->assertStringContainsString('Appelle « Chiffrage »', $got['html'], 'La brique dit quel workflow elle appelle.');
        $this->assertStringContainsString('3 paramètres', $got['html']);
        $this->assertStringContainsString('Chiffrage reçu', $got['html'], 'Et la pièce qu’elle remet.');
    }

    public function testCreerWorkflowSaitAppelerUnWorkflow(): void
    {
        $choices = ['profiles' => [], 'sources' => [], 'workflows' => self::ctx()['workflows']];
        $res = WorkflowDraft::fromArgs(['nom' => 'Devis complet', 'depart' => ['champs' => [
            ['nom' => 'Client', 'type' => 'texte', 'obligatoire' => true], ['nom' => 'Budget', 'type' => 'nombre'],
        ]], 'briques' => [
            ['type' => 'appel', 'titre' => 'Chiffrer', 'workflow' => 'chiffrage', 'recupere' => 'Synthèse', 'piece' => 'Chiffrage reçu', 'si_arret' => 'continuer',
                'parametres' => ['Client' => '[Informations de départ.Client]', 'Montant' => '[Informations de départ.Budget]', 'Inconnu' => 'x']],
            ['type' => 'etape', 'titre' => 'Conclure', 'consigne' => 'Conclus avec [Chiffrage reçu.Total].', 'piece' => ['nom' => 'Conclusion', 'forme' => 'texte']],
            ['type' => 'appel', 'titre' => 'Fantôme', 'workflow' => 'Introuvable'],
        ], 'livrable' => ['Conclusion']], $choices);
        $def = $res['definition'];
        $call = $def['nodes'][0];
        $this->assertSame(['call', self::CHILD, WorkflowSpec::CALL_STOP_CONTINUE], [$call['type'], $call['workflow_id'], $call['on_stop']]);
        $this->assertSame(['client' => '@{depart.client}', 'montant' => '@{depart.budget}'], $call['params'], 'Paramètres par le NOM des champs de l’appelé.');
        $this->assertSame(['synthese', 'fiche', 'Chiffrage reçu'], [$call['output']['from'], $call['output']['form'], $call['output']['name']]);
        $this->assertSame(['total', 'resume'], array_column($call['output']['fields'], 'key'));
        // L'étape suivante cite un champ de la Fiche revenue.
        $this->assertStringContainsString('@{' . $call['output']['key'] . '.total}', $def['nodes'][1]['instruction']);
        $notes = implode(' ', $res['notes']);
        $this->assertStringContainsString('Le paramètre « Inconnu » ne correspond à aucun champ du départ de « chiffrage »', $notes);
        $this->assertStringContainsString('Le workflow « Introuvable » à appeler est introuvable', $notes);
        $this->assertNull($def['nodes'][2]['workflow_id']);
        // Rien à régler pour l'appel juste ; l'appel fantôme bloque.
        $issues = WorkflowSpec::issues($def, ['self_id' => 0] + ['workflows' => $choices['workflows']]);
        $this->assertSame([], array_filter($issues, static fn($i) => $i['node'] === $call['key']));
        $this->assertNotEmpty(array_filter($issues, static fn($i) => $i['node'] === $def['nodes'][2]['key'] && $i['level'] === WorkflowSpec::LEVEL_BLOCK));

        // Une seule pièce au livrable de l'appelé : c'est elle, sans la nommer.
        $one = self::child();
        $one['end']['pieces'] = ['courrier'];
        $sig = WorkflowCall::signature(['name' => 'Courrier type', 'color' => '', 'published' => $one, 'is_active' => 1]);
        $auto = WorkflowDraft::fromArgs(['nom' => 'X', 'briques' => [['type' => 'appel', 'workflow' => 'Courrier type']]], ['workflows' => [3 => $sig]])['definition']['nodes'][0];
        $this->assertSame(['courrier', 'text', 'Courrier', 'Appeler Courrier type'], [$auto['output']['from'], $auto['output']['form'], $auto['output']['name'], $auto['title']]);

        // Fenêtre de lancement, carte du chat : la ligne dit quel workflow est appelé.
        $lines = WorkflowDraft::railLines($def, $choices + ['creations' => []]);
        $this->assertSame('Appelle « Chiffrage »', $lines[1]['sub']);
        // Le guide ne propose la brique que s'il y a quelque chose à appeler.
        $guide = WorkflowDraft::schemaGuide($choices);
        $this->assertStringContainsString('"type": "appel"', $guide);
        $this->assertStringContainsString('Chiffrage — attend : Client (text, obligatoire), Montant (number, obligatoire)', $guide);
        $this->assertStringContainsString('remet : Synthèse, Courrier', $guide);
        $this->assertStringNotContainsString('"type": "appel"', WorkflowDraft::schemaGuide(['workflows' => []]));
    }

    public function testLeJournalDitLAppel(): void
    {
        $def = self::caller(self::GOOD);
        $run = ['id' => 5, 'workflow_id' => self::SELF, 'definition' => $def, 'status' => WorkflowRun::RUNNING, 'trigger_kind' => 'manual', 'timezone' => 'Europe/Paris',
            'started_at' => '2026-11-17 08:00:00', 'finished_at' => null, 'error' => null, 'parent_run_id' => null, 'caller_step_id' => null, 'conversation_id' => 3,
            'current_key' => 'appel', 'inputs' => [], 'version' => 1];
        $detail = ['kind' => 'call', 'workflow_id' => self::CHILD, 'workflow' => 'Chiffrage', 'child_run' => 9,
            'params' => [['label' => 'Client', 'value' => 'Dupont'], ['label' => 'Montant', 'value' => '1200']]];
        $step = static fn(string $status, string $outcome, array $more = []): array => [
            'id' => 41, 'node_key' => 'appel', 'status' => $status, 'outcome' => $outcome, 'attempt' => 1, 'detail' => $more + $detail,
            'started_at' => '2026-11-17 08:01:00', 'finished_at' => $status === WorkflowRun::STEP_RUNNING ? null : '2026-11-17 08:03:05',
            'message_id' => null, 'resume_at' => null, 'job_id' => null,
        ];
        $now = new \DateTimeImmutable('2026-11-17 08:10:00', new \DateTimeZone('UTC'));
        $entry = static function (array $s, array $ctx = []) use ($run, $now): array {
            $entries = WorkflowJournal::entries($run, [$s], $ctx + ['artifacts' => []], $now);
            return $entries[0];
        };

        // En cours : l'appelé travaille ; le lien mène à SON exécution.
        $e = $entry($step(WorkflowRun::STEP_RUNNING, ''));
        $this->assertSame(['call', 'Workflow « Chiffrage »', '« Chiffrage » travaille…', 'run'], [$e['type'], $e['who'], $e['status'], $e['tone']]);
        $this->assertSame(['label' => 'Voir son exécution', 'href' => '/workflows/runs/9'], $e['link']);
        $this->assertSame([['label' => 'Client', 'value' => 'Dupont'], ['label' => 'Montant', 'value' => '1200']], $e['fields']);

        // L'appelé attend la personne : c'est dit, et on décide chez lui.
        $e = $entry($step(WorkflowRun::STEP_RUNNING, ''), ['calls' => [41 => ['status' => WorkflowRun::WAITING]]]);
        $this->assertSame(['« Chiffrage » attend votre décision', 'wait', 'Décider dans son exécution'], [$e['status'], $e['tone'], $e['link']['label']]);

        // L'appelé en échec : l'appel reste ouvert, sa cause se lit ici.
        $e = $entry($step(WorkflowRun::STEP_RUNNING, ''), ['calls' => [41 => ['status' => WorkflowRun::FAILED, 'error' => ['title' => 'Crédit épuisé', 'message' => 'Rechargez.']]]]);
        $this->assertSame('err', $e['tone']);
        $this->assertStringContainsString('s’est arrêté sur une erreur', $e['status']);
        $this->assertSame('Crédit épuisé', $e['error']['title']);

        // Terminé : la durée (une brique ordinaire), l'issue.
        $e = $entry($step(WorkflowRun::STEP_DONE, 'done'));
        $this->assertSame(['« Chiffrage » a terminé', 'ok'], [$e['status'], $e['tone']]);
        $this->assertNotSame('', $e['duration'], 'Un appel a une durée, comme une étape.');

        // Arrêté avant son livrable : selon le réglage de la brique.
        $e = $entry($step(WorkflowRun::STEP_DONE, 'stopped'));
        $this->assertStringContainsString('le workflow s’arrête aussi', $e['status']);
        $this->assertSame('off', $e['tone']);

        // En tête : une exécution appelée renvoie à l'appelante ; un appel fait compte comme une brique faite.
        $head = WorkflowJournal::head(['parent_run_id' => 4, 'caller_step_id' => 41, 'trigger_kind' => 'call'] + $run, [$step(WorkflowRun::STEP_DONE, 'done')], 1, 1, 0, $now);
        $this->assertSame(['/workflows/runs/4', 'Exécution appelante', 1], [$head['parent'], $head['parent_label'], $head['done_count']]);
        $this->assertSame('Appelé par un workflow', $head['trigger']);
        $this->assertSame('call', WorkflowJournal::triggerIcon('call'));
        $this->assertArrayHasKey('call', WorkflowCatalog::icons());
        $this->assertSame('Lancement précédent', WorkflowJournal::head(['parent_run_id' => 4] + $run, [], 1, 1, 0, $now)['parent_label']);
    }

    public function testLeVoletDAvancementDitQuiAttend(): void
    {
        $child = ['name' => 'Chiffrage', 'url' => '/workflows/runs/9', 'status' => WorkflowRun::WAITING, 'waiting' => true, 'blocked' => false];
        $state = ['status' => WorkflowRun::RUNNING, 'current' => 'appel', 'nodes' => ['appel' => ['status' => 'running', 'child' => $child]]];
        $this->assertSame(['kind' => 'call_wait', 'name' => 'Chiffrage', 'url' => '/workflows/runs/9'], WorkflowProgress::attention($state));
        $state['nodes']['appel']['child']['waiting'] = false;
        $this->assertSame('call', WorkflowProgress::attention($state)['kind']);
        // L'appel fini : plus rien à dire de lui.
        $state['nodes']['appel']['status'] = 'done';
        $this->assertNotSame('call', WorkflowProgress::attention($state)['kind'] ?? null);
        // Arrêté sur un appel : la cause se lit dans l'exécution appelée.
        $failed = WorkflowProgress::attention(['status' => WorkflowRun::FAILED, 'error' => ['title' => 'T', 'message' => 'M', 'child_run' => 9], 'resumable' => true]);
        $this->assertSame(['failed', '/workflows/runs/9'], [$failed['kind'], $failed['child_url']]);

        $tpl = self::src('templates/workflows/partials/run-progress.php');
        foreach (["case 'call_wait':", "case 'call':", "\$a['child_url']"] as $needle) {
            $this->assertStringContainsString($needle, $tpl);
        }
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeMoteurLaBaseEtLeChargementConnaissentLaBrique(): void
    {
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('WorkflowSpec::TYPE_CALL', $engine);
        $this->assertMatchesRegularExpression('/WorkflowSpec::TYPE_CALL\s*=> self::startCall\(/', $engine);
        // Toute fin d'exécution passe par le même point : c'est lui qui prévient l'appelante.
        $this->assertSame(1, substr_count($engine, 'WorkflowRun::setState('), 'setState() du moteur est le seul à écrire l’état d’une exécution.');
        $this->assertMatchesRegularExpression('/private static function setState\([\s\S]{0,900}self::childSettled\(/', $engine);
        foreach (['function startCall', 'function childSettled', 'function afterCall', 'function callDone', 'function callStopped', 'function callBlocked',
            'function closeOpenCalls', 'function callerResumed', 'function recoverLostCalls', 'function calledPreflight'] as $fn) {
            $this->assertStringContainsString($fn, $engine, $fn);
        }
        $this->assertMatchesRegularExpression('/function maintain\([\s\S]{0,6000}recoverLostCalls\(\)/', $engine, 'Une fin d’appel perdue est rattrapée par l’entretien.');
        // L'appelé passe son pré-contrôle AVANT d'être lancé, avec les connexions du moment.
        $this->assertMatchesRegularExpression('/function startCall[\s\S]{0,6000}self::assertReady\(\$child, \$user\)/', $engine);
        $this->assertMatchesRegularExpression('/function startCall[\s\S]{0,6000}WorkflowCall::DEPTH_MAX/', $engine);

        $migration = self::src('database/migrations/113_workflow_calls.sql');
        $this->assertStringContainsString('ADD COLUMN caller_step_id', $migration);
        $run = self::src('src/Models/WorkflowRun.php');
        foreach (['function ancestors', 'function calledBy', 'function calledBySteps', 'function calledCredits', 'caller_step_id'] as $needle) {
            $this->assertStringContainsString($needle, $run, $needle);
        }
        // Une exécution appelée ne prévient pas deux fois : l'appelante le fera.
        $this->assertStringContainsString('caller_step_id', self::src('src/Workflow/WorkflowNotifier.php'));

        // Pas d'autoloader : chargée des deux côtés, après ce dont elle dépend.
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $code = self::src($f);
            $this->assertStringContainsString('Workflow/WorkflowCall.php', $code, $f);
            $this->assertLessThan(strpos($code, 'Workflow/WorkflowCall.php'), strpos($code, 'Workflow/WorkflowSpec.php'), $f);
        }
    }

    public function testLesEcransConnaissentLaBrique(): void
    {
        $labels = WorkflowCatalog::labels();
        $this->assertSame('Appeler un workflow', $labels['kinds']['call']);
        $this->assertArrayHasKey('call', $labels['kindHelp']);
        $this->assertSame(['stop', 'continue'], array_keys($labels['callStop']));
        $this->assertArrayHasKey('flows', $labels['groups']);
        $this->assertArrayHasKey('call', WorkflowCatalog::HUES);
        $this->assertSame('Appelé par un workflow', WorkflowCatalog::triggerLabel('call'));

        $editor = self::src('public/assets/js/workflow-editor.js');
        foreach (['function callInspector', 'function callParam', 'function paramAlerts', 'function autoParams', 'function pickCall', 'function setCallOutput',
            "call: callInspector", "data-param-issues"] as $needle) {
            $this->assertStringContainsString($needle, $editor, $needle);
        }
        $rail = self::src('public/assets/js/workflow-rail.js');
        $this->assertStringContainsString("n.type === 'call'", $rail);
        $this->assertStringContainsString('findWorkflow', $rail);
        $run = self::src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString("node.type === 'call'", $run);
        $this->assertStringContainsString('called.waiting', $run);

        $controller = self::src('src/Controllers/WorkflowController.php');
        foreach (['function calledState', 'function callerOf', "'caller' => self::callerOf(\$run)", 'WorkflowRun::calledCredits('] as $needle) {
            $this->assertStringContainsString($needle, $controller, $needle);
        }
        $this->assertStringContainsString("\$state['caller']", self::src('templates/workflows/run.php'));
        $this->assertStringContainsString("\$e['link']", self::src('templates/workflows/partials/history-journal.php'));
        $css = self::src('public/assets/css/workflows.css');
        foreach (['.wf-call-params', '.wf-call-alert', '.wf-run-caller'] as $needle) {
            $this->assertStringContainsString($needle, $css, $needle);
        }
    }

    public function testLesTraductionsEtLaDocumentationSuivent(): void
    {
        $en = (array)(require ROOT . '/lang/en.php');
        $client = (array)(require ROOT . '/lang/en.client.php');
        foreach (['Appeler un workflow', 'Appelé par un workflow', 'un nombre', '« {champ} » attend {nature} : « {valeur} » ne convient pas.'] as $key) {
            $this->assertArrayHasKey($key, $en, $key);
        }
        foreach (['Appeler un workflow', 'Ce que vous lui donnez', 'Ce que vous récupérez', 'Quel workflow appeler ?'] as $key) {
            $this->assertArrayHasKey($key, $client, $key);
        }
        $this->assertStringContainsString('workflow-appelle-workflow', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Appeler un workflow', self::src('docs/GUIDE-CREATEUR.md'));
        $this->assertStringContainsString('Appeler un workflow', self::src('docs/user/fonctionnalites/workflows/index.md'));
        $this->assertFileExists(ROOT . '/docs/user/fonctionnalites/workflows/appeler-un-workflow.md');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneCiteLaMigration(): void
    {
        $this->assertStringContainsString('113_workflow_calls.sql', self::src('CLAUDE.md'));
    }
}
