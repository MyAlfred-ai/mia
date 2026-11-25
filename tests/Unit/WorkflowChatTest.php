<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Discussion dans la conversation d'une exécution de workflow (WorkflowChat)
 * — formes pures et câblage. Le parcours réel (tour de chat, carte, suite du
 * workflow) est dans tests/Functional/WorkflowChatPipelineTest.php.
 *
 * Le défaut corrigé (constaté chez un client, 2026-11-24) : l'Ad Spend donné dans la
 * discussion pendant la relecture n'entrait jamais dans la fiche du dossier,
 * et la suite du workflow écrivait « Ad Spend en attente de saisie ».
 */
final class WorkflowChatTest extends TestCase
{
    /** La fiche du workflow « Piloter Amazon 1P EU », réduite. */
    private static function fiche(): array
    {
        return [
            'key' => 'saisie_ad_spend', 'name' => 'Saisie Ad Spend confirmée', 'form' => 'fiche', 'producer' => 'ad_spend',
            'fields' => [
                ['key' => 'devise', 'label' => 'Devise', 'type' => 'text'],
                ['key' => 'statut', 'label' => 'Statut de disponibilité', 'type' => 'text'],
                ['key' => 'ad_spend_n', 'label' => 'Ad Spend du mois EUR HT', 'type' => 'number'],
                ['key' => 'ad_spend_n1', 'label' => 'Ad Spend du même mois N−1 EUR HT', 'type' => 'number'],
                ['key' => 'confirme', 'label' => 'Périmètres et montants confirmés', 'type' => 'yesno'],
            ],
        ];
    }

    private static function enAttente(): array
    {
        return [
            'fields'  => ['devise' => null, 'statut' => 'En attente de saisie', 'ad_spend_n' => null, 'ad_spend_n1' => null, 'confirme' => false],
            'summary' => '**Statut : en attente de saisie et de relecture.** Aucun montant publicitaire fourni.',
        ];
    }

    public function testLesChampsDonnesDansLaDiscussionRemplissentLaFiche(): void
    {
        $r = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), [
            'champs' => json_encode([
                'Ad Spend du mois EUR HT'          => 15539,
                'Ad Spend du même mois N−1 EUR HT' => '12 689',
                'devise'                           => 'EUR',
                'Statut de disponibilité'          => 'Confirmé',
                'Périmètres et montants confirmés' => true,
            ], JSON_UNESCAPED_UNICODE),
            'resume' => 'Ad Spend confirmé pour la France : 15 539 € HT en octobre 2026, 12 689 € HT en octobre 2025.',
        ]);

        $this->assertTrue($r['ok'], $r['error']);
        $this->assertTrue($r['changed']);
        $this->assertEquals(15539, $r['content']['fields']['ad_spend_n']);
        $this->assertEquals(12689, $r['content']['fields']['ad_spend_n1']);
        $this->assertSame('EUR', $r['content']['fields']['devise']);
        $this->assertTrue($r['content']['fields']['confirme']);
        $this->assertStringStartsWith('Ad Spend confirmé', $r['content']['summary']);
        // Ce que lira la suite du workflow : plus aucune trace de « en attente ».
        $texte = ArtifactContract::render(self::fiche(), $r['content']);
        $this->assertStringNotContainsString('en attente', mb_strtolower($texte));
        $this->assertStringContainsString('15', $texte);
    }

    public function testUnObjetJsonDirectEstAccepteEtLesAutresChampsRestent(): void
    {
        $r = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), ['champs' => ['ad_spend_n' => 15539]]);
        $this->assertTrue($r['ok'], $r['error']);
        $this->assertEquals(15539, $r['content']['fields']['ad_spend_n']);
        $this->assertSame('En attente de saisie', $r['content']['fields']['statut'], 'Un champ non donné ne change pas.');
    }

    public function testUnResumeDevenuFauxEstRetireSansResumeReecrit(): void
    {
        $r = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), ['champs' => '{"Ad Spend du mois EUR HT": 15539}']);
        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('summary', $r['content']);

        // « Ajuster » (page de l'exécution) suit la même règle.
        $ajuste = ArtifactContract::withCorrectedFields(self::fiche(), self::enAttente(), ['ad_spend_n' => 15539.0]);
        $this->assertArrayNotHasKey('summary', $ajuste);
        // Rien de changé : le résumé reste.
        $pareil = ArtifactContract::withCorrectedFields(self::fiche(), self::enAttente(), ['ad_spend_n' => null]);
        $this->assertArrayHasKey('summary', $pareil);
    }

    public function testUnChampInconnuOuIllisibleEstRefuseAvecUneRaison(): void
    {
        $inconnu = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), ['champs' => '{"TROAS": 4.2}']);
        $this->assertFalse($inconnu['ok']);
        $this->assertStringContainsString('« TROAS » inconnu', $inconnu['error']);
        $this->assertStringContainsString('« Ad Spend du mois EUR HT »', $inconnu['error']);

        $illisible = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), ['champs' => '{"Ad Spend du mois EUR HT": "beaucoup"}']);
        $this->assertFalse($illisible['ok']);
        $this->assertStringContainsString('illisible', $illisible['error']);

        $vide = WorkflowChat::updatedContent(self::fiche(), self::enAttente(), []);
        $this->assertFalse($vide['ok']);
    }

    public function testLesAutresFormesSeMettentAJour(): void
    {
        $texte = ['key' => 't', 'name' => 'Note', 'form' => 'text'];
        $r = WorkflowChat::updatedContent($texte, ['text' => 'Budget : à confirmer.'], ['remplacer' => 'à confirmer', 'par' => '12 000 €']);
        $this->assertSame('Budget : 12 000 €.', $r['content']['text']);
        $absent = WorkflowChat::updatedContent($texte, ['text' => 'Budget.'], ['remplacer' => 'introuvable', 'par' => 'x']);
        $this->assertFalse($absent['ok']);

        $liste = ['key' => 'l', 'name' => 'Clients', 'form' => 'list'];
        $r = WorkflowChat::updatedContent($liste, ['items' => ['A']], ['contenu' => "A\n- B\nC"]);
        $this->assertSame(['A', 'B', 'C'], $r['content']['items']);

        $tableau = ['key' => 'tb', 'name' => 'Montants', 'form' => 'table'];
        $r = WorkflowChat::updatedContent($tableau, ['rows' => [['Pays', 'Montant']]], ['contenu' => "| Pays | Montant |\n|---|---|\n| FR | 15 539 |"]);
        $this->assertSame([['Pays', 'Montant'], ['FR', '15 539']], $r['content']['rows']);
        $this->assertFalse(WorkflowChat::updatedContent($tableau, ['rows' => []], ['contenu' => 'pas un tableau'])['ok']);

        $doc = ['key' => 'd', 'name' => 'Email', 'form' => 'document', 'format' => 'md'];
        $r = WorkflowChat::updatedContent($doc, ['format' => 'md', 'source' => '# Titre'], ['contenu' => "```markdown\n# Titre\n\nCorps.\n```"]);
        $this->assertSame("# Titre\n\nCorps.", $r['content']['source']);
        $this->assertSame('md', $r['content']['format']);
    }

    private static function contexte(bool $correctable = true): array
    {
        return [
            'run'      => ['id' => 9, 'status' => WorkflowRun::WAITING, 'definition' => []],
            'workflow' => 'Piloter Amazon 1P EU',
            'gate'     => ['key' => 'ad_spend~review', 'title' => 'Relecture — Recueillir l’Ad Spend', 'type' => 'gate'],
            'step'     => ['id' => 3, 'pending_action_id' => 109, 'node_key' => 'ad_spend~review'],
            'pieces'   => ['saisie_ad_spend' => [
                'piece' => self::fiche(), 'art_key' => 'saisie_ad_spend',
                'art' => ['key' => 'saisie_ad_spend', 'node_key' => 'ad_spend', 'content' => self::enAttente(), 'provenance' => []],
                'correctable' => $correctable,
            ]],
        ];
    }

    public function testLaNoteDitOuEstLeModeleEtCeQuIlNeDoitPasFaire(): void
    {
        $note = WorkflowChat::promptNote(self::contexte(), true);
        $this->assertStringContainsString('CONVERSATION D\'UN WORKFLOW', $note);
        $this->assertStringContainsString('« Piloter Amazon 1P EU »', $note);
        // Le second défaut du cas constaté chez un client : le chat avait mené le reporting et préparé un email.
        $this->assertStringContainsString('ne prépare aucun email', $note);
        $this->assertStringContainsString('Tu ne peux PAS approuver à sa place', $note);
        $this->assertStringContainsString('« Saisie Ad Spend confirmée »', $note);
        $this->assertStringContainsString('en attente de saisie', $note, 'Le contenu ACTUEL de la pièce est montré.');
        $this->assertStringContainsString(WorkflowChat::TOOL, $note);
        $this->assertStringContainsString('réécris le résumé', $note);

        $sansOutil = WorkflowChat::promptNote(self::contexte(), false);
        $this->assertStringNotContainsString('`' . WorkflowChat::TOOL . '`', $sansOutil);
        $this->assertStringContainsString('« Ajuster »', $sansOutil);

        $enCours = self::contexte();
        $enCours['gate'] = null;
        $enCours['pieces'] = [];
        $enCours['run']['status'] = WorkflowRun::RUNNING;
        $this->assertStringContainsString('en train de travailler', WorkflowChat::promptNote($enCours, false));
        $enCours['run']['status'] = WorkflowRun::FAILED;
        $this->assertStringContainsString('« Reprendre »', WorkflowChat::promptNote($enCours, false));
    }

    public function testLOutilNeVisQueLesPiecesQueLaValidationLaisseCorriger(): void
    {
        $this->assertTrue(WorkflowChat::updatable(self::contexte()));
        $this->assertFalse(WorkflowChat::updatable(self::contexte(false)));
        $this->assertFalse(WorkflowChat::updatable(null));

        $def = WorkflowChat::toolDefinition(self::contexte());
        $this->assertSame(WorkflowChat::TOOL, $def['name']);
        $this->assertSame(['saisie_ad_spend'], $def['parameters']['properties']['piece']['enum']);
        $this->assertSame(['piece'], $def['parameters']['required']);
        // Gemini refuse un objet libre : les champs passent en texte JSON.
        $this->assertSame('string', $def['parameters']['properties']['champs']['type']);
    }

    public function testCableDansLeChatLEcranEtLaPageDeLExecution(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        // Jamais pour un tour sans personne (étape de workflow, tâche programmée).
        $this->assertStringContainsString('$workflowChat      = $unattended ? null : WorkflowChat::forTurn($convId, $userId);', $chat);
        $this->assertStringContainsString('WorkflowChat::promptNote($workflowChat, $workflowPieceTool)', $chat);
        $this->assertStringContainsString('new WorkflowPieceTools(', $chat);
        $this->assertStringContainsString("WorkflowChat::TOOL          => 'Mise à jour d\\'une pièce du workflow',", $chat);
        $this->assertStringContainsString("WorkflowChat::TOOL          => 'workflow',", $chat);
        // Aucun outil ne fait avancer un workflow : approuver reste un clic.
        $tools = (string)file_get_contents(ROOT . '/src/Agent/Tools/WorkflowPieceTools.php');
        $this->assertStringNotContainsString('gateResolved', $tools);
        $this->assertStringNotContainsString('gateResolved', (string)file_get_contents(ROOT . '/src/Workflow/WorkflowChat.php'));

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('json.drive_card_update', $js);
        $this->assertStringContainsString('function refreshDriveCard(id, preview)', $js);

        $wf = (string)file_get_contents(ROOT . '/src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('ArtifactContract::withCorrectedFields($piece, $content, $fields)', $wf);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = (string)file_get_contents(ROOT . '/' . $f);
            $this->assertStringContainsString('/src/Workflow/WorkflowChat.php', $src, $f);
            $this->assertStringContainsString('/src/Agent/Tools/WorkflowPieceTools.php', $src, $f);
        }
    }
}
