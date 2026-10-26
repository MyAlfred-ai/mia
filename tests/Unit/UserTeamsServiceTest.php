<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur Microsoft Teams : logique PURE du service, appels Graph rejoués
 * sans réseau (sous-classe qui remplace `apiJson`), fournisseur d'outils et
 * câblage dans l'application.
 *
 * Les gardes visent les pièges RÉELS de Microsoft Graph, documentés en tête de
 * UserTeamsService :
 *  - deux lieux, deux adresses → référence UNE chaîne (`19:…`, `équipe~canal`) ;
 *  - messages ni chronologiques (conversation : du plus récent ; canal : par
 *    activité du fil) → tout est retrié ;
 *  - conversation privée sans nom → nom des AUTRES participants ;
 *  - corps HTML à mentions, messages de service à écarter ;
 *  - lecture des canaux = consentement ADMINISTRATEUR → pas de `prompt=consent`
 *    (aucun connecteur Microsoft ne le force, cf. MicrosoftConsentPromptTest).
 */
final class UserTeamsServiceTest extends TestCase
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const TEAM  = '11111111-2222-3333-4444-555555555555';
    private const CHAN  = '19:abcdef0123456789@thread.tacv2';
    private const CHAT1 = '19:aaa_bbb@unq.gbl.spaces';
    private const CHAT2 = '19:groupe42@thread.v2';
    private const ALL_SCOPES = 'Chat.Read ChatMessage.Send Team.ReadBasic.All Channel.ReadBasic.All '
        . 'ChannelMessage.Read.All ChannelMessage.Send User.Read';

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /**
     * Service dont les appels Graph sont servis par `$routes` (préfixe
     * « MÉTHODE url » → réponse, closure, ou exception ; le préfixe le plus
     * LONG l'emporte). Chaque appel est noté.
     */
    private static function service(array $routes, string $scope = self::ALL_SCOPES): UserTeamsService
    {
        uksort($routes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return new class($routes, $scope) extends UserTeamsService {
            public array $calls = [];

            public function __construct(private array $routes, string $scope)
            {
                parent::__construct(1, 'refresh', 'access', null, $scope, 'alex@example.com');
            }

            protected function apiJson(string $method, string $url, ?array $body = null, int $timeout = 60): array
            {
                $this->calls[] = [$method, $url, $body];
                foreach ($this->routes as $prefix => $reponse) {
                    if (str_starts_with($method . ' ' . $url, $prefix)) {
                        if ($reponse instanceof \Throwable) {
                            throw $reponse;
                        }
                        return $reponse instanceof \Closure ? $reponse($url, $body) : $reponse;
                    }
                }
                throw new \RuntimeException('Appel inattendu : ' . $method . ' ' . $url);
            }
        };
    }

    private static function chats(): array
    {
        return ['value' => [
            [
                'id' => self::CHAT2, 'topic' => null, 'chatType' => 'group',
                'lastUpdatedDateTime' => '2026-10-01T08:00:00Z',
                'webUrl' => 'https://teams.microsoft.com/l/chat/19%3Agroupe42%40thread.v2/0',
                'members' => [
                    ['displayName' => 'Alex', 'email' => 'ALEX@example.com'],
                    ['displayName' => 'Marie Dupont', 'email' => 'marie@example.com'],
                    ['displayName' => 'Paul Martin', 'email' => 'paul@example.com'],
                ],
                'lastMessagePreview' => [
                    'createdDateTime' => '2026-10-02T09:00:00Z',
                    'body' => ['contentType' => 'text', 'content' => 'On valide le budget ?'],
                    'from' => ['user' => ['displayName' => 'Paul Martin']],
                ],
                'viewpoint' => ['lastMessageReadDateTime' => '2026-10-01T09:00:00Z'],
            ],
            [
                'id' => self::CHAT1, 'topic' => '', 'chatType' => 'oneOnOne',
                'lastUpdatedDateTime' => '2026-10-03T10:00:00Z',
                'webUrl' => 'https://teams.microsoft.com/l/chat/19%3Aaaa_bbb%40unq.gbl.spaces/0',
                'members' => [
                    ['displayName' => 'Alex', 'email' => 'alex@example.com'],
                    ['displayName' => 'Marie Dupont', 'email' => 'marie@example.com'],
                ],
                'lastMessagePreview' => [
                    'createdDateTime' => '2026-10-03T10:00:00Z',
                    'body' => ['contentType' => 'html', 'content' => '<p>Je t’envoie le devis</p>'],
                    'from' => ['user' => ['displayName' => 'Marie Dupont']],
                ],
                'viewpoint' => ['lastMessageReadDateTime' => '2026-10-03T11:00:00Z'],
            ],
        ]];
    }

    private static function equipes(): array
    {
        return [
            'GET ' . self::GRAPH . '/me/joinedTeams' => ['value' => [
                ['id' => self::TEAM, 'displayName' => 'Projet Atlas'],
                ['id' => '99999999-2222-3333-4444-555555555555', 'displayName' => 'Direction'],
            ]],
            'GET ' . self::GRAPH . '/teams/' . self::TEAM . '/channels' => ['value' => [
                ['id' => self::CHAN, 'displayName' => 'Général', 'membershipType' => 'standard',
                    'webUrl' => 'https://teams.microsoft.com/l/channel/x/G?groupId=' . self::TEAM],
                ['id' => '19:budget@thread.tacv2', 'displayName' => 'Budget', 'membershipType' => 'private'],
            ]],
            'GET ' . self::GRAPH . '/teams/99999999-2222-3333-4444-555555555555/channels' => ['value' => [
                ['id' => '19:dir@thread.tacv2', 'displayName' => 'Général'],
            ]],
        ];
    }

    // ===================== Scopes et consentement =====================

    public function testLesScopesSontComparesExactement(): void
    {
        $this->assertTrue(UserTeamsService::grantsReadChats('Chat.Read'));
        $this->assertTrue(UserTeamsService::grantsReadChats('https://graph.microsoft.com/Chat.ReadWrite'));
        // Chat.ReadBasic ne rend PAS les messages : préfixe de Chat.Read, pas un équivalent.
        $this->assertFalse(UserTeamsService::grantsReadChats('Chat.ReadBasic'));
        $this->assertTrue(UserTeamsService::grantsReadChannels('ChannelMessage.Read.All'));
        $this->assertFalse(UserTeamsService::grantsReadChannels('ChannelMessage.Read.Group'));
        $this->assertTrue(UserTeamsService::grantsSendChats('ChatMessage.Send'));
        $this->assertFalse(UserTeamsService::grantsListChannels('Team.ReadBasic.All'), 'Les deux droits sont requis.');

        // Consentement administrateur absent : les conversations marchent, les canaux non.
        $sansCanaux = self::service([], 'Chat.Read ChatMessage.Send Team.ReadBasic.All Channel.ReadBasic.All ChannelMessage.Send');
        $this->assertTrue($sansCanaux->canReadChats());
        $this->assertFalse($sansCanaux->canReadChannels());
        $this->assertTrue($sansCanaux->canSearch());
        $this->assertTrue($sansCanaux->canWrite());
    }

    public function testLeConsentementAdministrateurNEstPasForceAChaqueConnexion(): void
    {
        $this->assertContains('ChannelMessage.Read.All', MicrosoftTeamsOAuth::SCOPES);
        $this->assertNotContains('Chat.ReadWrite', MicrosoftTeamsOAuth::SCOPES, 'Jamais le droit de modifier ou supprimer.');
        $this->assertSame('msteams_', MicrosoftTeamsOAuth::STATE_PREFIX);
        $prompt = static fn(string $c): string => (string)(new \ReflectionClassConstant($c, 'PROMPT'))->getValue();
        $this->assertSame('select_account', $prompt(MicrosoftTeamsOAuth::class));
        // Aucun connecteur Microsoft ne force le consentement (Globex : il
        // bloquait les utilisateurs malgré le consentement administrateur).
        $this->assertSame('select_account', $prompt(MicrosoftTodoOAuth::class));
        $this->assertSame('select_account', $prompt(MicrosoftOutlookOAuth::class));
        $this->assertSame('select_account', $prompt(MicrosoftCalendarOAuth::class));
        $this->assertStringContainsString("'prompt'        => static::PROMPT", self::lire('src/Auth/MicrosoftUserOAuth.php'));
    }

    // ===================== Références et liens =====================

    public function testUneReferenceDesigneUnLieuSansAmbiguite(): void
    {
        $chat = UserTeamsService::parseConversationRef(self::CHAT1);
        $this->assertSame(['chat', self::CHAT1, ''], [$chat['kind'], $chat['chat'], $chat['message']]);

        $msg = UserTeamsService::parseConversationRef(self::CHAT2 . '~1700000000123');
        $this->assertSame(['chat', '1700000000123'], [$msg['kind'], $msg['message']]);

        $canal = UserTeamsService::parseConversationRef(self::TEAM . '~' . self::CHAN);
        $this->assertSame(['channel', self::TEAM, self::CHAN, ''], [$canal['kind'], $canal['team'], $canal['channel'], $canal['message']]);

        $fil = UserTeamsService::parseConversationRef(self::TEAM . '~' . self::CHAN . '~1616965872395');
        $this->assertSame('1616965872395', $fil['message'], 'Identifiant gardé en CHAÎNE : un entier perdrait des chiffres.');

        $seul = UserTeamsService::parseConversationRef(self::CHAN);
        $this->assertSame(['channel', ''], [$seul['kind'], $seul['team']], 'Un canal seul : son équipe sera retrouvée.');

        $this->assertNull(UserTeamsService::parseConversationRef('Projet Atlas / Général'), 'Un nom se résout ailleurs.');
        $this->assertNull(UserTeamsService::parseConversationRef('Marie Dupont'));

        $this->expectException(\RuntimeException::class);
        UserTeamsService::parseConversationRef(self::CHAT1 . '~abc');
    }

    public function testUnLienTeamsColleEstLu(): void
    {
        $chat = UserTeamsService::parseConversationRef(
            'https://teams.microsoft.com/l/chat/19%3Aaaa_bbb%40unq.gbl.spaces/0?tenantId=t');
        $this->assertSame(['chat', self::CHAT1], [$chat['kind'], $chat['chat']]);

        $canal = UserTeamsService::parseConversationRef('https://teams.microsoft.com/l/channel/'
            . rawurlencode(self::CHAN) . '/G%C3%A9n%C3%A9ral?groupId=' . self::TEAM . '&tenantId=t');
        $this->assertSame(['channel', self::TEAM, self::CHAN], [$canal['kind'], $canal['team'], $canal['channel']]);

        // Lien d'une RÉPONSE : il vise le fil de son message d'origine.
        $reponse = UserTeamsService::parseConversationRef('https://teams.cloud.microsoft/l/message/'
            . rawurlencode(self::CHAN) . '/1700000000999?groupId=' . self::TEAM . '&parentMessageId=1700000000111');
        $this->assertSame(['channel', '1700000000111'], [$reponse['kind'], $reponse['message']]);

        $message = UserTeamsService::parseConversationRef('https://teams.microsoft.com/l/message/'
            . rawurlencode(self::CHAT2) . '/1700000000555?context=%7B%22contextType%22%3A%22chat%22%7D');
        $this->assertSame(['chat', self::CHAT2], [$message['kind'], $message['chat']]);

        foreach (['https://evil.example.com/l/chat/' . rawurlencode(self::CHAT1) . '/0',
                  'http://teams.microsoft.com/l/chat/' . rawurlencode(self::CHAT1) . '/0',
                  'https://teams.microsoft.com.evil.com/l/chat/' . rawurlencode(self::CHAT1) . '/0'] as $faux) {
            try {
                UserTeamsService::parseConversationRef($faux);
                $this->fail('Lien accepté à tort : ' . $faux);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Microsoft Teams', $e->getMessage());
            }
        }
    }

    // ===================== Formes =====================

    public function testUneConversationSansSujetPorteLeNomDesAutres(): void
    {
        $rows = UserTeamsService::sortChats(array_values(array_filter(array_map(
            static fn(array $c) => UserTeamsService::summarizeChat($c, 'alex@example.com', 'Europe/Paris'),
            self::chats()['value']
        ))));
        // La plus récemment active d'abord.
        $this->assertSame([self::CHAT1, self::CHAT2], array_column($rows, 'id'));
        $this->assertSame('Marie Dupont', $rows[0]['nom'], 'La personne elle-même est retirée (adresse sans casse).');
        $this->assertSame('conversation privée', $rows[0]['type']);
        $this->assertFalse($rows[0]['non_lu']);
        $this->assertSame('Marie Dupont, Paul Martin', $rows[1]['nom']);
        $this->assertTrue($rows[1]['non_lu'], 'Dernier message postérieur à la dernière lecture.');
        $this->assertSame('Je t’envoie le devis', $rows[0]['dernier_message']['extrait']);
        $this->assertSame('03/10/2026 12:00', $rows[0]['dernier_message']['date'], 'Heure de Paris.');
        $this->assertArrayNotHasKey('_tri', UserTeamsService::withoutInternal($rows[0]));

        $this->assertNull(UserTeamsService::summarizeChat(['id' => 'pas-un-id']));
        $sansLien = UserTeamsService::summarizeChat(['id' => self::CHAT1, 'webUrl' => 'https://evil.example.com/x']);
        $this->assertSame('', $sansLien['lien'], 'Seul un lien Teams est transmis.');
    }

    public function testUnMessageEstRenduLisible(): void
    {
        $m = UserTeamsService::summarizeMessage([
            'id' => '1700000000123456789', 'messageType' => 'message', 'createdDateTime' => '2026-10-02T07:30:00Z',
            'from' => ['user' => ['displayName' => 'Marie Dupont']],
            'body' => ['contentType' => 'html', 'content' => '<p>Merci <at id="0">Paul</at>, voici le fichier<attachment id="a1"></attachment></p>'],
            'attachments' => [['id' => 'a1', 'name' => 'devis.pdf']],
        ], 'Europe/Paris');
        $this->assertSame('1700000000123456789', $m['id']);
        $this->assertSame('Merci @Paul, voici le fichier', $m['texte']);
        $this->assertSame(['devis.pdf'], $m['pieces_jointes']);
        $this->assertSame('02/10/2026 09:30', $m['date']);

        $this->assertNull(UserTeamsService::summarizeMessage(['id' => '1', 'messageType' => 'systemEventMessage',
            'body' => ['content' => '<systemEventMessage/>']]), 'Message de service écarté.');
        $this->assertNull(UserTeamsService::summarizeMessage(['id' => '2', 'deletedDateTime' => '2026-10-01T00:00:00Z',
            'body' => ['content' => 'x']]), 'Message supprimé écarté.');
        $this->assertSame('Bot Atlas', UserTeamsService::authorName(['from' => ['application' => ['displayName' => 'Bot Atlas']]]));
    }

    public function testLesMessagesSontRetriesEtBornes(): void
    {
        $tries = UserTeamsService::sortRaw([
            ['id' => '3', 'createdDateTime' => '2026-10-03T10:00:00Z'],
            ['id' => '1', 'createdDateTime' => '2026-10-01T10:00:00.123Z'],
            ['id' => '2', 'createdDateTime' => '2026-10-02T12:00:00+02:00'],
        ]);
        $this->assertSame(['1', '2', '3'], array_column($tries, 'id'));

        $messages = [];
        for ($i = 1; $i <= 30; $i++) {
            $messages[] = ['id' => (string)$i, 'texte' => str_repeat('x', 200)];
        }
        $fit = UserTeamsService::fitToBudget($messages, 2000);
        $this->assertTrue($fit['tronque']);
        $this->assertSame('30', end($fit['messages'])['id'], 'Les plus récents restent.');
        $this->assertNotSame('1', $fit['messages'][0]['id'], 'Les plus anciens tombent.');
    }

    public function testUnResultatDeRechercheDesigneSaConversation(): void
    {
        $canal = UserTeamsService::summarizeHit(['summary' => '…le <c0>budget</c0> validé', 'resource' => [
            'id' => '1657782060227', 'createdDateTime' => '2026-10-02T07:00:00Z',
            'from' => ['emailAddress' => ['name' => 'Goncalo Torres']],
            'channelIdentity' => ['teamId' => self::TEAM, 'channelId' => self::CHAN],
            'webLink' => 'https://outlook.office365.com/owa/?ItemID=x',
        ]], 'Europe/Paris');
        $this->assertSame(self::TEAM . '~' . self::CHAN, $canal['conversation']);
        $this->assertSame(['canal', '1657782060227', '…le budget validé'], [$canal['lieu'], $canal['message'], $canal['extrait']]);
        $this->assertArrayNotHasKey('lien', $canal, 'Le lien d\'un résultat mène à Outlook : jamais transmis.');

        $chat = UserTeamsService::summarizeHit(['summary' => 'x', 'resource' => ['id' => '1', 'chatId' => self::CHAT2, 'channelIdentity' => []]]);
        $this->assertSame([self::CHAT2, 'conversation'], [$chat['conversation'], $chat['lieu']]);
        $this->assertNull(UserTeamsService::summarizeHit(['resource' => ['id' => '1']]));
    }

    // ===================== Graph rejoué =====================

    public function testLaListeDesConversationsSeRabatSurUneRequeteSimple(): void
    {
        $svc = self::service([
            'GET ' . self::GRAPH . '/me/chats?$expand=members,lastMessagePreview' => new \RuntimeException(
                (string)(new \ReflectionClassConstant(UserTeamsService::class, 'MSG_BAD_REQUEST'))->getValue()),
            'GET ' . self::GRAPH . '/me/chats?$expand=members&' => self::chats(),
        ]);
        $rows = $svc->listChats('paul');
        $this->assertSame([self::CHAT2], array_column($rows, 'id'), 'Filtre sur un participant.');
        $this->assertCount(2, $svc->calls, 'Une seule nouvelle tentative, sans lastMessagePreview.');
    }

    public function testUneConversationSeLitDansLOrdre(): void
    {
        $svc = self::service([
            'GET ' . self::GRAPH . '/me/chats?' => self::chats(),
            'GET ' . self::GRAPH . '/chats/' . rawurlencode(self::CHAT1) . '/messages?$top=20&$orderby=createdDateTime%20desc' => ['value' => [
                ['id' => '3', 'createdDateTime' => '2026-10-03T10:00:00Z', 'body' => ['content' => 'C'], 'from' => ['user' => ['displayName' => 'Marie Dupont']]],
                ['id' => '2', 'messageType' => 'systemEventMessage', 'createdDateTime' => '2026-10-02T10:00:00Z'],
                ['id' => '1', 'createdDateTime' => '2026-10-01T10:00:00Z', 'body' => ['content' => 'A'], 'from' => ['user' => ['displayName' => 'Alex']]],
            ]],
        ]);
        $svc->setTimeZone('Europe/Paris');
        $r = $svc->readConversation('Marie Dupont', 20, 4000);
        $this->assertSame(['1', '3'], array_column($r['messages'], 'id'), 'Du plus ancien au plus récent, sans message de service.');
        $this->assertSame(['ref' => self::CHAT1, 'nom' => 'Marie Dupont', 'lieu' => 'conversation',
            'lien' => 'https://teams.microsoft.com/l/chat/19%3Aaaa_bbb%40unq.gbl.spaces/0'], $r['conversation']);
    }

    public function testUnCanalMontreSesFilsEtUnFilSeLitEnEntier(): void
    {
        $base = self::GRAPH . '/teams/' . self::TEAM . '/channels/' . rawurlencode(self::CHAN);
        $svc = self::service(['GET ' . self::GRAPH . '/me/chats?' => self::chats()] + self::equipes() + [
            'GET ' . $base . '/messages?$top=20&$expand=replies' => ['value' => [
                ['id' => '20', 'createdDateTime' => '2026-10-02T10:00:00Z', 'body' => ['content' => 'Second sujet'], 'replies' => []],
                ['id' => '10', 'createdDateTime' => '2026-10-01T10:00:00Z', 'body' => ['content' => 'Premier sujet'],
                    'replies' => [
                        ['id' => '13', 'createdDateTime' => '2026-10-01T13:00:00Z', 'body' => ['content' => 'r3']],
                        ['id' => '11', 'createdDateTime' => '2026-10-01T11:00:00Z', 'body' => ['content' => 'r1']],
                        ['id' => '12', 'createdDateTime' => '2026-10-01T12:00:00Z', 'body' => ['content' => 'r2']],
                        ['id' => '14', 'createdDateTime' => '2026-10-01T14:00:00Z', 'body' => ['content' => 'r4']],
                    ]],
            ]],
            'GET ' . $base . '/messages/10/replies' => ['value' => [
                ['id' => '12', 'createdDateTime' => '2026-10-01T12:00:00Z', 'body' => ['content' => 'r2']],
                ['id' => '11', 'createdDateTime' => '2026-10-01T11:00:00Z', 'body' => ['content' => 'r1']],
            ], '@odata.nextLink' => 'https://evil.example.com/vol?token'],
            'GET ' . $base . '/messages/10' => ['id' => '10', 'createdDateTime' => '2026-10-01T10:00:00Z', 'body' => ['content' => 'Premier sujet']],
        ]);

        $r = $svc->readConversation('Projet Atlas > Général', 20, 4000);
        $this->assertSame(['10', '20'], array_column($r['messages'], 'id'));
        $this->assertSame(4, $r['messages'][0]['reponses']);
        $this->assertSame(['12', '13', '14'], array_column($r['messages'][0]['dernieres_reponses'], 'id'));
        $this->assertSame('Projet Atlas / Général', $r['conversation']['nom']);

        $fil = $svc->readThread(self::TEAM . '~' . self::CHAN, '10', 4000);
        $this->assertSame('10', $fil['message_d_origine']['id']);
        $this->assertSame(['11', '12'], array_column($fil['reponses'], 'id'));
        foreach ($svc->calls as [, $url]) {
            $this->assertStringStartsWith(self::GRAPH . '/', $url, 'Jamais une adresse rendue par une réponse.');
        }
    }

    public function testUnNomAmbiguNommeLesCandidats(): void
    {
        $svc = self::service(['GET ' . self::GRAPH . '/me/chats?' => self::chats()] + self::equipes());
        try {
            $svc->resolveConversation('Général');
            $this->fail('« Général » existe dans deux équipes.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Projet Atlas / Général', $e->getMessage());
            $this->assertStringContainsString('Direction / Général', $e->getMessage());
        }
        $this->assertSame('channel', $svc->resolveConversation('Direction / Général')['kind']);
        $this->assertSame('19:budget@thread.tacv2', $svc->resolveConversation('#budget')['channel']);
        // Le NOM d'une conversation à deux passe devant le participant d'un groupe.
        $this->assertSame(self::CHAT1, $svc->resolveConversation('marie dupont')['chat']);

        $this->expectExceptionMessageMatches('/lister_conversations_teams/');
        $svc->resolveConversation('Comptabilité');
    }

    public function testUnePublicationViseLeBonLieuEtLeVerifie(): void
    {
        $base = self::GRAPH . '/teams/' . self::TEAM . '/channels/' . rawurlencode(self::CHAN);
        $svc = self::service([
            'POST ' . self::GRAPH . '/chats/' . rawurlencode(self::CHAT1) . '/messages' => ['id' => '1700000000001'],
            'POST ' . $base . '/messages/1616/replies' => ['id' => '1700000000002',
                'webUrl' => 'https://teams.microsoft.com/l/message/x/1700000000002?groupId=' . self::TEAM],
        ]);
        $chat = $svc->postMessage(['conversation' => self::CHAT1, 'conversation_nom' => 'Marie Dupont', 'texte' => 'Bonjour **Marie**']);
        $this->assertSame(['Marie Dupont', 'conversation', false], [$chat['conversation'], $chat['lieu'], $chat['fil']]);
        [, , $body] = $svc->calls[0];
        $this->assertSame('html', $body['body']['contentType']);
        $this->assertStringContainsString('<strong>Marie</strong>', $body['body']['content'], 'Markdown converti pour Teams.');

        $fil = $svc->postMessage(['conversation' => self::TEAM . '~' . self::CHAN, 'texte' => 'Vu', 'repondre_a' => '1616']);
        $this->assertTrue($fil['fil']);
        $this->assertStringContainsString('1700000000002', $fil['url']);

        try {
            $svc->postMessage(['conversation' => self::CHAT1, 'texte' => 'x', 'repondre_a' => '1616']);
            $this->fail('Une conversation n\'a pas de fil.');
        } catch (\RuntimeException $e) {
            $this->assertSame(UserTeamsService::MSG_CHAT_NO_THREAD, $e->getMessage());
        }

        $muet = self::service(['POST ' . self::GRAPH . '/chats/' => []]);
        $this->expectExceptionMessage('Teams n\'a pas confirmé la publication du message.');
        $muet->postMessage(['conversation' => self::CHAT1, 'texte' => 'x']);
    }

    public function testSansDroitDePublierRienNePart(): void
    {
        $lecture = self::service([], 'Chat.Read ChannelMessage.Read.All');
        $this->expectExceptionMessage(UserTeamsService::MSG_RECONNECT_WRITE);
        $lecture->postMessage(['conversation' => self::CHAT1, 'texte' => 'x']);
    }

    public function testAucuneModificationNiSuppressionDeMessage(): void
    {
        $code = '';
        foreach (token_get_all(self::lire('src/Teams/UserTeamsService.php')) as $tok) {
            if (!is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($tok) ? $tok[1] : $tok;
            }
        }
        foreach (["'DELETE'", "'PATCH'", "'PUT'", 'softDelete', 'setReaction'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $code);
        }
    }

    // ===================== Carte d'approbation =====================

    public function testLaCarteDitQueLeMessagePartAussitot(): void
    {
        $c = UserTeamsService::actionPreview('envoyer_message_teams', ['conversation' => self::TEAM . '~' . self::CHAN,
            'conversation_nom' => 'Projet Atlas / Général', 'lieu' => 'canal', 'texte' => 'Réunion déplacée',
            'repondre_a' => '1616', 'lien' => 'https://teams.microsoft.com/l/channel/x']);
        $this->assertSame('Répondre dans un fil Teams', $c['action']);
        $this->assertSame('Projet Atlas / Général', $c['fichier']);
        $this->assertStringContainsString('EN VOTRE NOM', $c['impacts'][0]);
        $this->assertStringContainsString('membres du canal', $c['impacts'][0]);
        $this->assertStringContainsString('rattaché au fil', implode(' ', $c['impacts']));
        $this->assertSame('Réunion déplacée', $c['apercu']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public' . $c['icone']);

        $p = UserTeamsService::actionPreview('envoyer_message_teams', ['conversation' => self::CHAT1, 'lieu' => 'conversation',
            'texte' => 'x', 'lien' => 'https://evil.example.com']);
        $this->assertSame('Envoyer un message Teams', $p['action']);
        $this->assertStringContainsString('participants', $p['impacts'][0]);
        $this->assertNull($p['url']);
    }

    // ===================== Fournisseur d'outils =====================

    public function testLesOutilsSuiventLesDroitsAccordes(): void
    {
        $noms = static fn(bool ...$d): array => array_column(TeamsTools::definitionsFor(...$d), 'name');
        $this->assertSame(
            ['lister_conversations_teams', 'lister_canaux_teams', 'rechercher_teams', 'lire_conversation_teams', 'lire_fil_teams', 'envoyer_message_teams'],
            $noms(true, true, true, true, true)
        );
        // Sans consentement administrateur : pas de fil de canal, la recherche reste.
        $this->assertSame(
            ['lister_conversations_teams', 'lister_canaux_teams', 'rechercher_teams', 'lire_conversation_teams'],
            $noms(true, true, false, true, false)
        );
        $this->assertStringContainsString('consentement administrateur', TeamsTools::guidanceFor(false, true));
        $this->assertStringContainsString('en_attente_approbation', TeamsTools::guidanceFor(true, true));
        $this->assertStringContainsString('lecture seule', TeamsTools::guidanceFor(true, false));
    }

    public function testLeHandlerResoutLaDestinationAvantLaCarte(): void
    {
        $deposes = [];
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            function (string $t, array $a, ?array $r) use (&$deposes): array {
                $deposes[] = [$t, $a, $r];
                return ['statut' => 'en_attente_approbation'];
            }, 'Europe/Paris');
        $svc = self::service(['GET ' . self::GRAPH . '/me/chats?' => self::chats()] + self::equipes() + [
            'GET ' . self::GRAPH . '/teams/' . self::TEAM . '/channels/' . rawurlencode(self::CHAN) . '?' =>
                ['displayName' => 'Général', 'webUrl' => 'https://teams.microsoft.com/l/channel/x'],
            'GET ' . self::GRAPH . '/teams/' . self::TEAM . '?' => ['displayName' => 'Projet Atlas'],
        ]);
        $outils = new TeamsTools($turn, $svc);
        $this->assertSame('teams', $outils->connector());
        $h = $outils->handlers();

        $this->assertArrayHasKey('error', $h['envoyer_message_teams'](['conversation' => 'Marie Dupont', 'texte' => '']));
        $this->assertArrayHasKey('error', $h['envoyer_message_teams'](['conversation' => 'Général', 'texte' => 'x']), 'Ambigu.');
        $this->assertArrayHasKey('error', $h['envoyer_message_teams'](['conversation' => 'Marie Dupont', 'texte' => 'x', 'repondre_a' => '12']));
        $this->assertSame([], $deposes, 'Une entrée invalide ne dépose aucune carte.');

        $h['envoyer_message_teams'](['conversation' => 'marie dupont', 'texte' => 'Je regarde']);
        $this->assertSame('envoyer_message_teams', $deposes[0][0]);
        $this->assertSame(['conversation' => self::CHAT1, 'conversation_nom' => 'Marie Dupont', 'lieu' => 'conversation',
            'texte' => 'Je regarde', 'repondre_a' => ''], array_diff_key($deposes[0][1], ['lien' => 1]));

        // Lien d'un message de canal : la publication part dans son fil.
        $h['envoyer_message_teams'](['conversation' => 'https://teams.microsoft.com/l/message/' . rawurlencode(self::CHAN)
            . '/1700000000999?groupId=' . self::TEAM . '&parentMessageId=1700000000111', 'texte' => 'Ok']);
        $this->assertSame(self::TEAM . '~' . self::CHAN, $deposes[1][1]['conversation']);
        $this->assertSame('1700000000111', $deposes[1][1]['repondre_a']);
        $this->assertSame('Projet Atlas / Général', $deposes[1][1]['conversation_nom']);
    }

    // ===================== Câblage =====================

    public function testLeConnecteurEstCableDeBoutEnBout(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->assertSame(UserTeamsService::class, ConnectorRegistry::serviceClass('teams'));
        $this->assertSame(MicrosoftTeamsOAuth::class, ConnectorRegistry::oauthClass('teams'));
        $this->assertSame(UserDriveToken::PROVIDER_TEAMS, ConnectorRegistry::tokenProvider('teams'));
        $this->assertSame('microsoft', ConnectorRegistry::row('teams')['family'] ?? null);
        $this->assertContains('msteams_', SsoRegistry::CONNECTOR_PREFIXES);
        $this->assertContains('ChannelMessage.Read.All', ConnectorRegistry::delegatedScopes('microsoft')['Microsoft Teams'] ?? [],
            'La page Authentification doit nommer les permissions à déclarer sur l\'application Azure.');
        $this->assertSame('teams_access', Scheduler::ACCESS_FLAGS['teams']);
        $this->assertSame('teams', WorkflowSpec::ACTION_TARGETS['teams']);
        foreach (ConnectorRegistry::defaultTriggers('teams') as $expr) {
            $this->assertNotSame('teams', $expr, '« teams » seul est un mot anglais courant.');
        }

        $auth = self::lire('src/Controllers/AuthController.php');
        $this->assertStringContainsString("str_starts_with((string)\$state, 'msteams_')", $auth);
        $this->assertStringContainsString('->teamsCallback()', $auth);

        $chat = self::lire('src/Controllers/ChatController.php');
        foreach ([
            "\$teamsAccess    = (\$_POST['teams_access'] ?? '0') === '1';",
            "'teams_access'    => \$teamsAccess,",
            'ConnectorRegistry::TEAMS    => $teamsAccess,',
            'new TeamsTools($turn, $service)',
            "(string)\$action['tool'] === 'envoyer_message_teams'",
            "'envoyer_message_teams'   => UserTeamsService::actionPreview(\$tool, \$args),",
            "'teams_read'                => 'chat',",
        ] as $attendu) {
            $this->assertStringContainsString($attendu, $chat);
        }

        $this->assertStringContainsString("'toggle'    => 'teamsAccessToggle'", self::lire('templates/chat/index.php'));
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("fd.append('teams_access'", $js);
        $this->assertStringContainsString("{ name: 'teams',    row: document.getElementById('plusMenuTeamsRow')", $js);
        $this->assertStringContainsString('teams_read:', $js);
        $this->assertStringContainsString("connectorRule('teams'", self::lire('public/assets/js/tool-suggest.js'));
        $this->assertStringContainsString('a[href^="/settings/teams/connect"]', self::lire('public/assets/js/native-bridge.js'));
        $this->assertStringContainsString('id="connector-teams"', self::lire('templates/settings/connections.php'));
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/img/microsoft-teams.svg');

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::lire($f);
            $this->assertLessThan(strpos($src, '/src/Teams/UserTeamsService.php'), strpos($src, '/src/Outlook/UserOutlookCalendarService.php'), $f);
            $this->assertLessThan(strpos($src, '/src/Auth/MicrosoftTeamsOAuth.php'), strpos($src, '/src/Auth/MicrosoftUserOAuth.php'), $f);
            $this->assertLessThan(strpos($src, '/src/Agent/Tools/TeamsTools.php'), strpos($src, '/src/Agent/AbstractToolProvider.php'), $f);
        }

        // Mémoire de conversation : la conversation lue est rappelée au tour suivant.
        $e = ConnectorContext::entryForTool('lire_fil_teams', ['conversation' => 'Général'],
            ['conversation' => ['ref' => self::TEAM . '~' . self::CHAN, 'nom' => 'Projet Atlas / Général', 'fil' => '10']]);
        $this->assertSame(['teams_conv', 'teams', self::TEAM . '~' . self::CHAN . '~10', 'Projet Atlas / Général'],
            [$e['kind'], $e['connector'], $e['ref'], $e['name']]);

        // Trace : le nom RÉSOLU.
        $trace = ChatController::actionEntryForTool('lire_conversation_teams', ['conversation' => 'marie'],
            ['conversation' => ['nom' => 'Marie Dupont']]);
        $this->assertSame(['teams_read', 'Marie Dupont'], [$trace['t'], $trace['d']]);
    }

    public function testLaRechercheMultiSourcesInterrogeTeams(): void
    {
        $teams = new class {
            public function canSearch(): bool { return true; }
            public function searchMessages(string $q, int $n): array
            {
                return [['conversation' => '19:x@thread.v2', 'message' => '1', 'lieu' => 'conversation', 'extrait' => 'dossier Durand']];
            }
        };
        $turn  = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            static fn(string $t, array $a, ?array $r): array => [], 'Europe/Paris');
        $slack = new class {
            public function canSearch(): bool { return false; }
        };
        $tools = new IntentTools($turn, static fn(): array => ['teams' => $teams, 'slack' => $slack]);
        $this->assertSame(['teams' => 'Microsoft Teams'], IntentTools::searchableSources(['teams' => $teams, 'slack' => $slack], false));
        $this->assertArrayNotHasKey('rechercher_partout', array_flip(array_column($tools->definitions(), 'name')),
            'Une seule source : pas de recherche multi-sources.');

        $avecBases = new IntentTools($turn, static fn(): array => ['teams' => $teams], static fn(string $q): ?string => null);
        $r = $avecBases->handlers()['rechercher_partout'](['requete' => 'Durand']);
        $this->assertSame('dossier Durand', $r['sources']['Microsoft Teams']['elements'][0]['extrait']);
        $this->assertStringContainsString('lire_conversation_teams', $r['sources']['Microsoft Teams']['lire_avec']);
    }

    public function testUneIdeeDeSaisieParleDeTeams(): void
    {
        $groupes = array_column(ComposerHints::forChat(['connectors' => ['teams']]), 'g');
        $this->assertContains('teams', $groupes);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationSuit(): void
    {
        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/GUIDE-ADMIN.md', 'docs/NOUVEAUTES.md', 'CLAUDE.md',
                  'docs/user/fonctionnalites/connecteurs/index.md', 'docs/user/admin/connecteurs/index.md',
                  'docs/user/admin/connecteurs/autoriser-mia-dans-microsoft-entra-id.md'] as $doc) {
            $this->assertStringContainsString('Microsoft Teams', self::lire($doc), $doc);
        }
        $this->assertStringContainsString('ChannelMessage.Read.All',
            self::lire('docs/user/admin/connecteurs/autoriser-mia-dans-microsoft-entra-id.md'));
    }
}
