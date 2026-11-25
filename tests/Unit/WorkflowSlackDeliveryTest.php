<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Livraison Slack d'un workflow — le canal vérifié AVANT d'agir.
 *
 * LE CAS PAYÉ (recette, 2026-10-26) : un workflow « Décrire à MIA » portait
 * une action « Publier sur Slack » (canal « Général », publiée) ET une
 * livraison du livrable vers le canal « Canal Slack » — une description
 * écrite à la place d'un canal. La livraison déposait son action, échouait
 * à la publication avec le message destiné au modèle (« Canal Slack « Canal
 * Slack » introuvable parmi les conversations de l'utilisateur… »), et
 * l'exécution finissait en échec alors que le message était bien publié :
 * rien ne disait que c'était la LIVRAISON, ni que le workflow publiait deux
 * fois.
 */
final class WorkflowSlackDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Service Slack sans réseau : `conversations.list` rend ces canaux, chaque appel est noté. */
    private static function slack(array $canaux, string $scope = 'channels:read,groups:read,chat:write'): UserSlackService
    {
        return new class($canaux, $scope) extends UserSlackService {
            public array $calls = [];

            public function __construct(private array $canaux, string $scope)
            {
                parent::__construct(1, 'xoxp-test', null, null, $scope, 'alex@example.com');
            }

            protected function slackGet(string $method, array $query = []): array
            {
                $this->calls[] = $method;
                if ($method !== 'conversations.list') {
                    throw new \RuntimeException('Appel inattendu : ' . $method);
                }
                return ['ok' => true, 'channels' => $this->canaux, 'response_metadata' => ['next_cursor' => '']];
            }
        };
    }

    private static function canaux(): array
    {
        return [
            ['id' => 'C08AMPY0WE7', 'name' => 'général', 'is_member' => true],
            ['id' => 'C08AMPY0WE8', 'name' => 'aléatoire', 'is_member' => true],
            ['id' => 'C08AMPY0WE9', 'name' => 'general-annonces', 'is_member' => true],
        ];
    }

    public function testUneDescriptionNEstPasUnCanal(): void
    {
        foreach (['Canal Slack', 'le canal de l’équipe', '', '   ', '#'] as $ref) {
            $this->assertFalse(UserSlackService::isPlausibleChannelRef($ref), $ref);
        }
        foreach (['#général', 'Général', 'general-annonces', 'C08AMPY0WE7',
                  'https://exemple.slack.com/archives/C08AMPY0WE7/p1792994544302489'] as $ref) {
            $this->assertTrue(UserSlackService::isPlausibleChannelRef($ref), $ref);
        }
    }

    public function testLeCanalDUneLivraisonEstCherchePuisNommeCommeDansSlack(): void
    {
        $svc = self::slack(self::canaux());
        $this->assertSame('#général', WorkflowDeliveries::slackChannel($svc, 'Général'), 'casse et accents ignorés, vrai nom rendu');
        $this->assertSame('#général', WorkflowDeliveries::slackChannel($svc, '#general'));

        $id = self::slack(self::canaux());
        $this->assertSame('C08AMPY0WE7', WorkflowDeliveries::slackChannel($id, 'C08AMPY0WE7'), 'un identifiant reste un identifiant');
        $this->assertSame([], $id->calls, 'un identifiant ne coûte aucun appel');
    }

    public function testUnCanalInventeEchoueAvantToutDepotAvecUnMessagePourLaPersonne(): void
    {
        $svc = self::slack(self::canaux());
        try {
            WorkflowDeliveries::slackChannel($svc, 'Canal Slack');
            $this->fail('« Canal Slack » ne désigne aucun canal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('le canal Slack « Canal Slack » est introuvable parmi vos conversations Slack', $e->getMessage());
            $this->assertStringContainsString('Corrigez le canal de cette livraison dans le livrable du workflow.', $e->getMessage());
            $this->assertStringNotContainsString('l\'utilisateur', $e->getMessage(), 'le message du modèle ne parle pas à la personne');
        }
        $this->assertSame([], $svc->calls, 'une description ne part même pas chez Slack');

        $proche = self::slack(self::canaux());
        try {
            WorkflowDeliveries::slackChannel($proche, 'annonces');
            $this->fail('« annonces » n’existe pas tel quel.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('canaux approchants : #general-annonces', $e->getMessage());
            $this->assertStringNotContainsString('Demande lequel', $e->getMessage(), 'consigne au modèle, pas à la personne');
        }
    }

    public function testLeMessageDuModeleResteInchange(): void
    {
        $svc = self::slack(self::canaux());
        $this->assertSame(['id' => 'C08AMPY0WE7', 'nom' => 'général'], $svc->resolveChannel('#Général'));
        try {
            $svc->resolveChannel('annonces');
            $this->fail('introuvable');
        } catch (\RuntimeException $e) {
            $this->assertSame('Canal Slack « annonces » introuvable. Canaux approchants : #general-annonces. Demande lequel plutôt que de choisir.', $e->getMessage());
        }
    }

    public function testLaTraductionAnglaiseExiste(): void
    {
        I18n::use('en');
        try {
            WorkflowDeliveries::slackChannel(self::slack(self::canaux()), 'Canal Slack');
            $this->fail('introuvable');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('the Slack channel “Canal Slack” cannot be found', $e->getMessage());
        } finally {
            I18n::use('fr');
        }
    }

    // ------------------------------------------------------------------
    // « À régler »
    // ------------------------------------------------------------------

    private static function definition(string $canal, bool $actionSlack): array
    {
        $nodes = [
            ['key' => 'rediger', 'type' => 'step', 'title' => 'Rédiger la news', 'instruction' => 'Rédige la news de @{depart}.',
                'output' => ['key' => 'news', 'name' => 'News', 'form' => 'text']],
        ];
        if ($actionSlack) {
            $nodes[] = ['key' => 'publier', 'type' => 'action', 'title' => 'Publier sur Slack', 'target' => 'slack',
                'instruction' => 'Publie @{news} dans #général.', 'write' => 'approve'];
        }
        return WorkflowSpec::normalize([
            'objective' => 'Une news publiée.',
            'nodes' => $nodes,
            'end' => ['pieces' => ['news'], 'deliveries' => [
                ['type' => 'slack', 'channel' => $canal, 'message' => '@{news}', 'mode' => 'approve'],
            ]],
        ]);
    }

    private static function texts(array $def, string $level): array
    {
        $ctx = ['profiles' => [], 'llms' => [], 'tools' => [], 'auto' => true,
            'sources' => ['slack' => ['label' => 'Slack', 'connected' => true]]];
        return array_values(array_column(array_filter(WorkflowSpec::issues($def, $ctx), static fn($i) => $i['level'] === $level && $i['node'] === WorkflowSpec::END_KEY), 'text'));
    }

    public function testUneDescriptionAuLieuDUnCanalBloqueLaPublication(): void
    {
        $this->assertContains(
            '« Canal Slack » n’est pas un nom de canal Slack (il ne contient jamais d’espace) : écrivez-le comme dans Slack, par exemple #general.',
            self::texts(self::definition('Canal Slack', false), WorkflowSpec::LEVEL_BLOCK)
        );
        $this->assertSame([], self::texts(self::definition('#général', false), WorkflowSpec::LEVEL_BLOCK));
    }

    public function testUneActionQuiPublieDejaDansSlackEstSignalee(): void
    {
        $this->assertSame(
            ['L’action « Publier sur Slack » publie déjà dans Slack : cette livraison publiera un second message. Gardez l’une ou l’autre.'],
            self::texts(self::definition('#général', true), WorkflowSpec::LEVEL_WARN)
        );
        $this->assertSame([], self::texts(self::definition('#général', false), WorkflowSpec::LEVEL_WARN));
        $this->assertSame([], self::texts(self::definition('#général', true), WorkflowSpec::LEVEL_BLOCK), 'un avertissement, jamais un blocage');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaLivraisonVerifieLeCanalAvantDeDeposerSonAction(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Workflow/WorkflowDeliveries.php');
        $this->assertMatchesRegularExpression(
            '/private static function slack\(.*?self::slackChannel\(\$svc, .*?self::deposit\(/s',
            $src,
            'le canal est cherché AVANT le dépôt de l’action'
        );
    }

    public function testLeChatVerifieLeCanalAvantLaCarte(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Agent/Tools/SlackTools.php');
        $this->assertMatchesRegularExpression(
            "/'envoyer_message_slack'\] = function .*?->resolveChannel\(\\\$normalise\['canal'\]\).*?\\\$queueConnectorWrite\('envoyer_message_slack'/s",
            $src,
            'un canal inventé revient au modèle pendant qu’il rédige, pas après l’approbation'
        );
    }

    public function testLaCreationNInventeNiCanalNiDoublePublication(): void
    {
        $guide = WorkflowDraft::schemaGuide([]);
        $this->assertStringContainsString('jamais les deux', $guide, 'une action OU une livraison, jamais les deux');
        $this->assertStringContainsString('n\'invente aucune donnée que la description ne donne pas (adresse, dossier, canal Slack)', $guide,
            'la règle vaut aussi pour « Décrire à MIA », qui ne lit que ce guide');
    }
}
