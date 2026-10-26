<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cartes d'approbation : trancher en une fois, retrouver sa place, voir
 * l'issue tout de suite.
 *
 *  - « Tout approuver / Tout refuser » dès que deux cartes attendent — un
 *    agent qui prépare cinq tâches demandait cinq clics ;
 *  - une carte en attente revient SOUS la réponse qui l'a proposée au
 *    rechargement (migration 077), plus empilée en fin de fil ;
 *  - le message d'issue persisté (✅, 🚫, ⚠️) s'affiche aussitôt : il
 *    n'apparaissait qu'au rechargement.
 *
 * Les emails restent hors du lot, à dessein : ils se relisent sur leur carte.
 */
final class ApprovalBatchTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    public function testLesIdentifiantsDUnLotSontPropresEtBornes(): void
    {
        $this->assertSame([3, 1], ChatController::batchActionIds(['3', 1, '3', 0, -2, 'x', [7]]));
        $this->assertCount(ChatController::BATCH_ACTIONS_MAX, ChatController::batchActionIds(range(1, 50)));
        $this->assertSame([], ChatController::batchActionIds([]));
    }

    public function testLeLotPasseParLeMemeCheminQuUnClic(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("\$router->post('/chat/{id}/drive-actions', [ChatController::class, 'driveActionsBatch']);",
            (string)file_get_contents(ROOT . '/public/index.php'));

        $lot = self::methode($src, 'driveActionsBatch');
        $this->assertStringContainsString('$this->decideAction($convId, $user, $actionId, $decision);', $lot,
            'Réservation atomique, droits re-vérifiés, message d’issue : rien n’est recopié.');
        $this->assertStringContainsString("in_array(\$decision, ['approve', 'cancel'], true)", $lot, 'Jamais « send » en lot.');
        $this->assertMatchesRegularExpression('/MailDraftReview::isMailTool\(.*?\'skipped\' => true/s', $lot,
            'Un email se relit sur sa propre carte.');
        $this->assertStringContainsString('JobQueue::respondAndDispatch($payload', $lot, 'Les suivis partent APRÈS la réponse du lot.');

        $carte = self::methode($src, 'driveAction');
        $this->assertStringContainsString('$this->decideAction($convId, $user, $actionId, $decision);', $carte);
    }

    public function testLaDecisionJointSonMessageDIssueALaReponse(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        foreach (['decideAction', 'resolveMailAction'] as $m) {
            $corps = self::methode($src, $m);
            $this->assertStringNotContainsString('$this->json(', $corps, "$m : toute réponse passe par driveJson.");
            $this->assertStringNotContainsString("Message::create(\$convId, 'assistant'", $corps, "$m : tout message d’issue passe par driveOutcome.");
            $this->assertStringNotContainsString('JobQueue::respondAndDispatch(', $corps, $m);
        }
        $json = self::methode($src, 'driveJson');
        $this->assertStringContainsString("\$data['message'] = \$this->driveOutcome;", $json);
    }

    public function testLesCartesDuTourSontRattacheesASaReponse(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('$this->turnActionIds[] = $id;', $src);
        $this->assertMatchesRegularExpression(
            '/Message::setActions\(\(int\)\$msg\[\'id\'\], \$this->actionTrace\);.*?DrivePendingAction::attachToMessage\(\$this->turnActionIds, \(int\)\$msg\[\'id\'\], \(int\)\$user\[\'id\'\]\);/s',
            $src
        );
        $this->assertStringContainsString('$this->turnActionIds = [];', $src, 'Remis à zéro à chaque tour : le worker réutilise l’instance.');

        $migration = (string)file_get_contents(ROOT . '/database/migrations/077_pending_action_message.sql');
        $this->assertStringContainsString('ON DELETE SET NULL', $migration, 'Une réponse supprimée ne supprime pas la carte.');
    }

    public function testLeNavigateurPlaceLesCartesEtProposeLeLot(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('.message[data-message-id="${Number(p.message_id)}"] .msg-body', $js,
            'Au rechargement, la carte revient sous sa réponse.');
        $this->assertStringContainsString("!card.classList.contains('mail-draft-card')", $js);
        $this->assertStringContainsString('if (cards.length < 2) return;', $js, 'Le lot n’a de sens qu’à partir de deux cartes.');
        $this->assertStringContainsString('`/chat/${convId()}/drive-actions`', $js);
        $this->assertStringContainsString('applyDriveDecision(card, decision, payload);', $js, 'Même rendu qu’un clic sur chaque carte.');
        $this->assertSame(3, substr_count($js, 'showDriveOutcome(data);'), 'Issue affichée pour une action faite, annulée ou échouée.');

        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.drive-batch-bar', $css);
        $client = require ROOT . '/lang/en.client.php';
        foreach (['Tout approuver', 'Tout refuser', '{n} actions attendent votre approbation.'] as $cle) {
            $this->assertArrayHasKey($cle, $client);
        }
    }

    /** Corps d'une méthode de ChatController (jusqu'à la méthode suivante). */
    private static function methode(string $src, string $nom): string
    {
        $debut = preg_match('/(public|private|protected) (static )?function ' . preg_quote($nom, '/') . '\(/', $src, $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $debut, "Méthode $nom introuvable.");
        $pos = $m[0][1];
        $suite = preg_match('/\n    (public|private|protected) (static )?function /', $src, $n, PREG_OFFSET_CAPTURE, $pos + 10);
        return substr($src, $pos, $suite === 1 ? $n[0][1] - $pos : null);
    }
}
