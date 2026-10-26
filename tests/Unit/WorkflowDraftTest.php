<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MIA prépare un workflow (WorkflowDraft, WorkflowTools) — modèles livrés :
 * WorkflowTemplatesTest. Cf. CLAUDE.md « Workflows ». L’outil du chat et
 * « Décrire à MIA » passent par la MÊME conversion : des mots simples
 * (« etape », « consigne », « [Nom de la pièce] ») → une définition.
 */
final class WorkflowDraftTest extends TestCase
{
    /** Ce qu'une personne peut utiliser (forme de WorkflowCatalog::choices). */
    private static function choices(): array
    {
        $src = static fn(string $label): array => ['label' => $label, 'connected' => true];
        return [
            'profiles'    => [5 => ['name' => 'Veille', 'description' => 'Suit le secteur', 'default_llm' => '']],
            'llms'        => ['openai::gpt-5.6' => 'GPT-5.6'],
            'tool_llms'   => ['openai::gpt-5.6' => true],
            'default_llm' => 'openai::gpt-5.6',
            'sources'     => ['web' => $src('Internet'), 'gmail' => $src('Gmail'), 'outlook' => $src('Outlook'),
                'calendar' => $src('Agenda Google'), 'tasks' => $src('Tâches Google'), 'slack' => $src('Slack'), 'drive' => $src('Google Drive')],
            'kbs'         => [],
            'tools'       => [],
            'auto'        => true,
        ];
    }

    private static function args(): array
    {
        return [
            'nom' => 'Factures fournisseurs',
            'objectif' => 'Payer à temps des factures vérifiées.',
            'depart' => [
                'nom_fiche' => 'Contexte',
                'champs' => [
                    ['nom' => 'Facture', 'type' => 'fichier', 'obligatoire' => true],
                    ['nom' => 'Commentaire', 'type' => 'texte_long', 'obligatoire' => false],
                ],
                'programmation' => null,
            ],
            'briques' => [
                ['type' => 'etape', 'titre' => 'Lire la facture', 'assistant' => 'aucun',
                    'consigne' => 'Lis [Facture] et relève ses informations, en tenant compte de [Contexte].',
                    'piece' => ['nom' => 'Infos', 'forme' => 'fiche', 'champs' => [
                        ['nom' => 'Fournisseur', 'type' => 'texte'], ['nom' => 'Montant TTC', 'type' => 'nombre'],
                    ]]],
                ['type' => 'condition', 'titre' => 'Au-delà de 5 000 € ?',
                    'regle' => ['piece' => 'Infos', 'champ' => 'Montant TTC', 'operateur' => 'superieur', 'valeur' => '5000'],
                    'si_oui' => ['briques' => [
                        ['type' => 'validation', 'titre' => 'Accord de la direction', 'relit' => ['Infos']],
                        ['type' => 'condition', 'titre' => 'Imbriquée'],
                    ], 'ensuite' => 'continuer'],
                    'si_non' => ['briques' => [], 'ensuite' => 'continuer']],
                ['type' => 'action', 'titre' => 'Créer la tâche', 'outil' => 'tasks', 'consigne' => 'Crée une tâche avec [Infos].'],
                ['type' => 'action', 'titre' => 'Inconnue', 'outil' => 'fax'],
            ],
            'livrable' => ['Infos'],
        ];
    }

    public function testLesMotsSimplesDeviennentUneDefinition(): void
    {
        $d = WorkflowDraft::fromArgs(self::args(), self::choices());
        $def = $d['definition'];
        $this->assertSame('Factures fournisseurs', $d['name']);
        $this->assertSame('Payer à temps des factures vérifiées.', $def['objective']);

        // Départ : un fichier (pièce à lui seul) et un champ de la Fiche « Contexte ».
        $this->assertSame(['file', 'longtext'], array_column($def['start']['fields'], 'type'));
        $this->assertSame('Contexte', $def['start']['fiche_name']);
        $fileKey = $def['start']['fields'][0]['key'];

        // Étape : pièces citées par leur NOM → @{clé}.
        $step = $def['nodes'][0];
        $this->assertSame('step', $step['type']);
        $this->assertNull($step['profile_id'], '« aucun » = MIA.');
        $this->assertStringContainsString('@{' . $fileKey . '}', $step['instruction']);
        $this->assertStringContainsString('@{' . WorkflowSpec::START_PIECE . '}', $step['instruction']);
        $this->assertSame('fiche', $step['output']['form']);
        $this->assertSame(['Fournisseur', 'Montant TTC'], array_column($step['output']['fields'], 'label'));

        // Condition par règle sur le champ de la Fiche ; la condition imbriquée est écartée.
        $cond = $def['nodes'][1];
        $this->assertSame('rule', $cond['mode']);
        $item = $cond['rule']['items'][0];
        $this->assertSame($step['output']['key'], $item['piece']);
        $this->assertSame('gt', $item['op']);
        $fieldKeys = array_column($step['output']['fields'], 'key');
        $this->assertContains($item['field'], $fieldKeys, 'Le champ de la règle désigne un champ réel de la Fiche.');
        $this->assertSame(['gate'], array_column($cond['yes']['nodes'], 'type'));
        $this->assertSame('continue', $cond['no']['then']);

        // Actions : outil reconnu, à approuver par défaut ; outil inconnu signalé.
        $this->assertSame('tasks', $def['nodes'][2]['target']);
        $this->assertSame(WorkflowSpec::WRITE_APPROVE, $def['nodes'][2]['write']);
        $this->assertSame('', $def['nodes'][3]['target']);
        $this->assertSame([$step['output']['key']], $def['end']['pieces']);

        $notes = implode(' ', $d['notes']);
        $this->assertStringContainsString('une voie ne porte pas de condition', $notes);
        $this->assertStringContainsString('outil non reconnu', $notes);
    }

    public function testUnAssistantSeDesigneParNumeroOuParNom(): void
    {
        $args = self::args();
        $args['briques'] = [['type' => 'etape', 'titre' => 'Veiller', 'assistant' => 'veille', 'consigne' => 'Cherche.']];
        $this->assertSame(5, WorkflowDraft::fromArgs($args, self::choices())['definition']['nodes'][0]['profile_id']);
        $args['briques'][0]['assistant'] = '5';
        $this->assertSame(5, WorkflowDraft::fromArgs($args, self::choices())['definition']['nodes'][0]['profile_id']);
        $args['briques'][0]['assistant'] = 'Inconnu';
        $d = WorkflowDraft::fromArgs($args, self::choices());
        $this->assertNull($d['definition']['nodes'][0]['profile_id']);
        $this->assertStringContainsString('introuvable', implode(' ', $d['notes']));
    }

    public function testSansLivrableLaDernierePieceDuRailEstLivree(): void
    {
        $args = self::args();
        unset($args['livrable']);
        $args['briques'] = [
            ['type' => 'etape', 'titre' => 'A', 'consigne' => 'a', 'piece' => ['nom' => 'Pièce A']],
            ['type' => 'etape', 'titre' => 'B', 'consigne' => 'b', 'piece' => ['nom' => 'Pièce B']],
        ];
        $def = WorkflowDraft::fromArgs($args, self::choices())['definition'];
        $this->assertSame([$def['nodes'][1]['output']['key']], $def['end']['pieces']);
    }

    public function testUneProgrammationSeLit(): void
    {
        $args = self::args();
        $args['depart']['champs'] = [['nom' => 'Sujet', 'type' => 'texte', 'defaut' => 'IA']];
        $args['depart']['programmation'] = ['frequence' => 'chaque_semaine', 'jours' => [1], 'heure' => '09:00'];
        $args['fuseau'] = 'Europe/Paris';
        $def = WorkflowDraft::fromArgs($args, self::choices())['definition'];
        $this->assertSame('weekly', $def['start']['schedule']['rule']['freq']);
        $this->assertSame('09:00', $def['start']['schedule']['rule']['time']);
        $this->assertSame('Europe/Paris', $def['start']['schedule']['timezone']);
    }

    public function testLaCarteMontreLeDeroule(): void
    {
        $d = WorkflowDraft::fromArgs(self::args(), self::choices());
        $p = WorkflowDraft::actionPreview($d['name'], $d['definition'], self::choices(), $d['notes']);
        $this->assertSame('/assets/img/workflow.svg', $p['icone']);
        // Pas d'« Ajuster » : approuver ouvre déjà l'éditeur.
        $this->assertArrayNotHasKey('ajuster', $p);
        $this->assertSame('Factures fournisseurs', $p['fichier']);
        $kinds = array_column($p['rail'], 'kind');
        $this->assertSame('start', $kinds[0]);
        $this->assertSame('end', end($kinds));
        $this->assertContains('condition', $kinds);
        $this->assertContains('gate', $kinds);
        $this->assertContains('action', $kinds);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/img/workflow.svg');
    }

    public function testLaGardeLexicale(): void
    {
        foreach (['Monte-moi un workflow qui lit mes factures', 'Crée un workflow pour les appels d’offres', 'Can you build a workflow for invoices?', 'Prépare un flux de travail pour l’accueil'] as $oui) {
            $this->assertTrue(WorkflowDraft::looksLikeWorkflowRequest($oui), $oui);
        }
        foreach (['Qu’est-ce qu’un workflow ?', 'Prépare un email à Paul', 'Mon workflow habituel est lent'] as $non) {
            $this->assertFalse(WorkflowDraft::looksLikeWorkflowRequest($non), $non);
        }
    }

    public function testLeJsonDuModeleSeLitSousToutesSesFormes(): void
    {
        $this->assertSame(['nom' => 'X'], WorkflowTools::decode('{"nom": "X"}'));
        $this->assertSame(['nom' => 'X'], WorkflowTools::decode(['nom' => 'X']));
        $this->assertSame(['nom' => 'Y'], WorkflowTools::decode("Voici :\n```json\n{\"nom\": \"Y\"}\n```"));
        $this->assertNull(WorkflowTools::decode(''));
        $this->assertNull(WorkflowTools::decode('pas de json'));
        $this->assertStringContainsString('"nom":"X"', str_replace(' ', '', WorkflowDraft::pendingNote(['nom' => 'X'])));
    }

    public function testLaFormeDecriteNommeCeQueLaPersonnePeutUtiliser(): void
    {
        $guide = WorkflowDraft::schemaGuide(self::choices());
        $this->assertStringContainsString('5. Veille', $guide);
        $this->assertStringContainsString('"gmail"', $guide);
        $this->assertStringContainsString('si_oui', $guide);
        $this->assertStringContainsString('[Nom de la pièce]', $guide);
    }
}
