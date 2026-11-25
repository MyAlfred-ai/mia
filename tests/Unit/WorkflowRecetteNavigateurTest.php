<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Défauts trouvés en essayant les workflows dans le navigateur, sur la recette
 * (2026-11-09) — cf. CLAUDE.md « Workflows ».
 *
 *  1. Une Fiche dont les champs ont été ajoutés dans l'éditeur (clés tirées au
 *     hasard, « fh5v4d ») s'affichait sous la réponse de l'étape avec ces clés :
 *     le modèle recevait l'ordre de les recopier. Il reçoit désormais les
 *     LIBELLÉS, que la lecture sait déjà rattacher à leur champ.
 *  2. Après un renvoi avec remarque, la condition et la carte de validation
 *     suivantes n'apparaissaient qu'au rechargement : la synchro du fil,
 *     différée pendant que la réponse de l'étape s'écrivait, n'était jamais
 *     rejouée.
 *  3. « Regénérer » sous un message du moteur (livrable, validation…)
 *     supprimait ces messages et répondait hors du workflow.
 *  4. « ne peut pas valoir à la fois « Haute », « Basse » » : la dernière
 *     valeur est jointe par « et ».
 *  5. Menu « @ » : le nom du champ d'une Fiche était tronqué au profit du nom
 *     de la Fiche (« Informations de départ › Cl… »).
 */
final class WorkflowRecetteNavigateurTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function fiche(array $fields): array
    {
        return ['key' => 'analyse', 'name' => 'Analyse', 'form' => 'fiche', 'fields' => $fields, 'format' => 'docx'];
    }

    // ---- 1. Fiche : libellés demandés au modèle ----

    public function testLaFicheDemandeLesLibellesEtNonLesClesTirees(): void
    {
        $piece = self::fiche([
            ['key' => 'fj7n1k', 'label' => 'Catégorie', 'type' => 'text'],
            ['key' => 'fh5v4d', 'label' => 'Priorité', 'type' => 'text'],
            ['key' => 'fykbg2', 'label' => 'Montant estimé', 'type' => 'number'],
        ]);
        $this->assertSame(['Catégorie', 'Priorité', 'Montant estimé'], ArtifactContract::ficheJsonKeys($piece));
        $consigne = ArtifactContract::instruction($piece);
        $this->assertStringContainsString('"Priorité": "texte"', $consigne);
        $this->assertStringContainsString('"Montant estimé": nombre', $consigne);
        $this->assertStringNotContainsString('fh5v4d', $consigne, 'Une clé tirée au hasard ne doit plus être demandée au modèle.');

        // La réponse écrite avec les libellés est rattachée aux bonnes clés.
        $reply = "Synthèse.\n\n```json\n{\"Catégorie\": \"Formation\", \"Priorité\": \"Haute\", \"Montant estimé\": \"15 000 €\"}\n```";
        $r = ArtifactContract::extract($piece, $reply);
        $this->assertTrue($r['ok']);
        $this->assertSame('Formation', $r['content']['fields']['fj7n1k']);
        $this->assertSame('Haute', $r['content']['fields']['fh5v4d']);
        $this->assertEquals(15000, $r['content']['fields']['fykbg2']);

        // Le libellé passe avant une clé homonyme d'un autre champ.
        $croise = self::fiche([
            ['key' => 'budget', 'label' => 'Montant', 'type' => 'number'],
            ['key' => 'x1', 'label' => 'Budget', 'type' => 'number'],
        ]);
        $lu = ArtifactContract::extract($croise, "```json\n{\"Montant\": 100, \"budget\": 200}\n```")['content']['fields'];
        $this->assertEquals(100, $lu['budget']);
        $this->assertEquals(200, $lu['x1']);

        // Une réponse d'avant (écrite avec les clés) se lit toujours.
        $ancienne = "```json\n{\"fj7n1k\": \"Formation\", \"fh5v4d\": \"Normale\"}\n```";
        $this->assertSame('Normale', ArtifactContract::extract($piece, $ancienne)['content']['fields']['fh5v4d']);
    }

    public function testLaFicheRetombeSurLesClesQuandLesLibellesSontAmbigus(): void
    {
        $doublon = self::fiche([
            ['key' => 'a', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'b', 'label' => 'date', 'type' => 'date'],
        ]);
        $this->assertSame(['a', 'b'], ArtifactContract::ficheJsonKeys($doublon), 'Deux libellés qui se confondent : les clés.');

        $vide = self::fiche([
            ['key' => 'a', 'label' => 'Client', 'type' => 'text'],
            ['key' => 'b', 'label' => '', 'type' => 'text'],
        ]);
        $this->assertSame(['a', 'b'], ArtifactContract::ficheJsonKeys($vide), 'Un libellé vide : les clés.');

        // La lecture essaie la clé d'abord : un libellé égal à la clé d'un AUTRE
        // champ attribuerait la valeur au mauvais champ.
        $croise = self::fiche([
            ['key' => 'budget', 'label' => 'Montant', 'type' => 'number'],
            ['key' => 'x1', 'label' => 'budget', 'type' => 'text'],
        ]);
        $this->assertSame(['budget', 'x1'], ArtifactContract::ficheJsonKeys($croise));
    }

    // ---- 2. Synchro du fil après un renvoi ----

    public function testLaSynchroDuFilDiffereeEstRejouee(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/assets/js/chat.js');
        $this->assertMatchesRegularExpression('/function deferTailSync\(\)\s*\{[^}]*setTimeout\(\(\) => \{ tailSyncRetry = null; syncConversationTail\(\); \}/s', $js);
        $start = strpos($js, 'async function syncConversationTail()');
        $this->assertNotFalse($start);
        $body = substr($js, $start, 400);
        $this->assertStringContainsString('if (live && !live.done) { deferTailSync(); return; }', $body,
            'Une synchro écartée pendant que la réponse s’écrit doit être rejouée, sinon la carte suivante n’apparaît qu’au rechargement.');
    }

    // ---- 3. « Regénérer » dans la conversation d'un workflow ----

    public function testRegenererEstRefuseDansLaConversationDUnWorkflow(): void
    {
        $src = (string)file_get_contents(self::root() . '/src/Controllers/ChatController.php');
        $start = strpos($src, 'public function regenerate(string $id): void');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 4000);
        $garde = strpos($body, "if (!empty(\$convRow['workflow_run_id']))");
        $this->assertNotFalse($garde, 'regenerate() refuse une conversation de workflow.');
        $this->assertLessThan(strpos($body, 'Message::deleteLastAssistant($convId)'), $garde, 'Refus AVANT toute suppression.');
        $this->assertStringContainsString('http_response_code(409)', substr($body, $garde, 400));

        $en = require self::root() . '/lang/en.php';
        $this->assertArrayHasKey('Cette conversation est celle d’un workflow : pour refaire une étape, ouvrez le suivi détaillé de l’exécution.', $en);
    }

    public function testRegenererEtRefaireRegulierementSontMasquesDansUneConversationDeWorkflow(): void
    {
        $css = (string)file_get_contents(self::root() . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.chat-main[data-workflow-run]:not([data-workflow-run="0"]) .msg-actions .btn-regen', $css);
        $this->assertStringContainsString('.chat-main[data-workflow-run]:not([data-workflow-run="0"]) .msg-actions .btn-schedule', $css);
        // data-workflow-run est posé par le gabarit ET tenu à jour au changement de conversation.
        $tpl = (string)file_get_contents(self::root() . '/templates/chat/index.php');
        $this->assertStringContainsString('data-workflow-run="<?= (int)($workflowRunId ?? 0) ?>"', $tpl);
        $this->assertStringContainsString('main.dataset.workflowRun = String(Number(workflowRunId) || 0);', (string)file_get_contents(self::root() . '/public/assets/js/chat.js'));
        // La feuille des workflows est chargée par le chat.
        $this->assertStringContainsString('/assets/css/workflows.css?v=', $tpl);
    }

    // ---- 4. Règles contradictoires : « et » ----

    public function testLesValeursContradictoiresSeJoignentParEt(): void
    {
        $pieces = ['depart' => ['key' => 'depart', 'name' => 'Départ', 'form' => 'fiche', 'fields' => [
            ['key' => 'urgence', 'label' => 'Urgence', 'type' => 'text', 'options' => ['Haute', 'Normale', 'Basse']],
        ]]];
        $rule = ['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'urgence', 'op' => 'eq', 'value' => 'Haute'],
            ['piece' => 'depart', 'field' => 'urgence', 'op' => 'eq', 'value' => 'Basse'],
        ]];
        $issues = WorkflowValues::ruleConflicts($rule, $pieces);
        $this->assertNotSame([], $issues);
        $this->assertStringContainsString('« Haute » et « Basse »', $issues[0]['text']);
    }

    // ---- 5. Menu « @ » ----

    public function testLeMenuDesPiecesGardeLeNomDuChampLisible(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('<span class="wf-mp-label">\' + esc(p.label) + \'</span>', $js);
        $css = (string)file_get_contents(self::root() . '/public/assets/css/workflows.css');
        $this->assertMatchesRegularExpression('/\.is-field \.wf-mp-label \{ flex: 0 0 auto;/', $css, 'Le nom du champ ne rétrécit pas.');
        $this->assertMatchesRegularExpression('/\.is-field \.wf-mp-parent \{ flex: 0 1 auto;[^}]*text-overflow: ellipsis;/', $css, 'La Fiche se raccourcit d’abord.');
    }

    // ---- 6. Validation refusée : « Arrêtée », pas « faite » ----

    public function testUneValidationRefuseeNeSAffichePasCommeFaite(): void
    {
        $def = ['start' => [], 'end' => ['pieces' => []], 'nodes' => [
            ['key' => 'relire', 'type' => WorkflowSpec::TYPE_GATE, 'title' => 'Relire la note'],
            ['key' => 'ok', 'type' => WorkflowSpec::TYPE_GATE, 'title' => 'Valider'],
        ]];
        $state = ['status' => WorkflowRun::STOPPED, 'terminal' => true, 'current' => '', 'nodes' => [
            'relire' => ['status' => 'done', 'outcome' => 'stopped', 'seconds' => 21],
            'ok'     => ['status' => 'done', 'outcome' => 'approved', 'seconds' => 5],
        ]];
        $rows = array_column(WorkflowProgress::rows($def, $state), null, 'key');
        $this->assertSame('off', $rows['relire']['state']);
        $this->assertSame('', $rows['relire']['note'], 'Pas de durée sous une validation refusée.');
        $this->assertSame('done', $rows['ok']['state']);
    }

    // ---- 7. Étape de workflow : pas l'outil image de l'assistant ----

    public function testUneEtapeDeWorkflowNUtilisePasLOutilImageDeSonAssistant(): void
    {
        $src = (string)file_get_contents(self::root() . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("} elseif (\$workflowStep !== null) {\n            \$imageTool = \$taskTools['image'] ?? null;", $src,
            'Une étape ne reçoit que l’outil image choisi pour elle.');
        // Les tâches programmées gardent les outils de leur assistant.
        $this->assertStringContainsString("\$imageTool = \$taskTools['image'] ?? \$this->resolveProfileImageTool(\$profile, \$user, \$groupIds);", $src);
    }

    // ---- 8. Cartes closes ailleurs ----

    public function testLaSynchroDuFilFermeLesCartesQuiNAttendentPlus(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/assets/js/chat.js');
        $this->assertStringContainsString('closeStaleDriveCards(wrap, new Set(pendingList.map((p) => String(p.id))));', $js,
            'Une validation tranchée ou arrêtée ailleurs ne garde pas ses boutons dans le fil.');
        $start = strpos($js, 'function closeStaleDriveCards(wrap, pendingIds)');
        $this->assertNotFalse($start);
        $body = substr($start === false ? '' : $js, (int)$start, 1500);
        $this->assertStringContainsString("if (card.querySelector('button:disabled')) return;", $body, 'Une carte en cours de décision est laissée à sa réponse.');
        $this->assertStringContainsString("card.querySelector('.drive-confirm-actions')?.remove();", $body);
        $en = require self::root() . '/lang/en.client.php';
        $this->assertArrayHasKey('Cette carte n’attend plus de décision.', $en);
        $css = (string)file_get_contents(self::root() . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.chat-main[data-workflow-run]:not([data-workflow-run="0"]) .msg-plan-workflow', $css);
    }
}
