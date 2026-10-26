<?php

declare(strict_types=1);

/**
 * Accès à MICROSOFT TEAMS d'un utilisateur (OAuth Microsoft par utilisateur,
 * jetons UserDriveToken provider 'microsoft_teams') : conversations (privées,
 * de groupe, de réunion), équipes et canaux, recherche dans les messages,
 * lecture d'une conversation ou d'un fil, et publication — cette dernière
 * UNIQUEMENT via la file d'approbation. Aucune modification ni suppression de
 * message : l'outil n'existe pas (cf. MicrosoftTeamsOAuth sur les scopes).
 *
 * Plomberie commune (refresh lazy, jetons roulants, retry 401, erreurs FR sans
 * fuite) : MicrosoftUserApiService — ce fichier ne porte que le métier Teams.
 *
 * ── SIX PIÈGES GRAPH, TOUS PAYÉS DANS LE CODE ──────────────────────────────
 *
 *  1. **Deux lieux, deux adresses.** Un message vit soit dans une CONVERSATION
 *     (`/chats/{chatId}`), soit dans un CANAL d'une équipe
 *     (`/teams/{teamId}/channels/{channelId}`) — l'identifiant d'un canal ne
 *     suffit pas sans celui de son équipe. Les donner séparément au modèle,
 *     c'est l'inviter à les dépareiller. La référence rendue est donc UNE
 *     chaîne (`parseConversationRef`) : `19:…@thread.v2` pour une
 *     conversation, `<équipe>~<canal>` pour un canal, suivie de `~<message>`
 *     pour viser un message. `~` n'appartient à aucun identifiant Teams.
 *
 *  2. **L'ordre des messages n'est pas chronologique.** Les messages d'une
 *     conversation arrivent du plus récent au plus ancien ; ceux d'un canal
 *     sont triés par DERNIÈRE ACTIVITÉ de leur fil, et les réponses d'un fil
 *     du plus récent au plus ancien. Tout est retrié ICI par date de création
 *     (`sortRaw`) : le modèle lit une discussion dans l'ordre où elle a eu lieu.
 *
 *  3. **Une conversation privée n'a pas de nom.** `topic` est vide pour une
 *     conversation à deux et souvent pour un groupe : le nom affiché est celui
 *     des AUTRES participants (`summarizeChat`, la personne elle-même retirée
 *     par son adresse), sinon le sujet.
 *
 *  4. **Le corps est du HTML avec des mentions.** `<at id="0">Marie</at>`
 *     devient « @Marie », les pièces jointes (`<attachment>`) disparaissent du
 *     texte et sont nommées à part, et les messages de service
 *     (`systemEventMessage` : arrivée d'un membre, appel…) ou supprimés sont
 *     écartés (`summarizeMessage`).
 *
 *  5. **La recherche rend des extraits balisés et aucun lien utile.** Le
 *     `summary` porte des balises de surlignage (retirées) et le `webLink`
 *     d'un résultat mène… à Outlook Web : il n'est pas transmis. Chaque
 *     résultat porte en revanche la référence de sa conversation ou de son
 *     canal, que `lire_conversation_teams` accepte telle quelle.
 *
 *  6. **Pages de résultats.** Les listes sont paginées (`@odata.nextLink`). Le
 *     lien suivant n'est suivi QUE s'il vise Graph : le jeton part avec la
 *     requête, jamais vers une adresse rendue par une réponse.
 *
 * Non vérifié faute de compte Teams : la combinaison
 * `$expand=members,lastMessagePreview` + `$orderby` sur `/me/chats` (chacun est
 * documenté séparément). Un refus en 400 retombe sur `$expand=members` seul, la
 * liste étant de toute façon retriée ici.
 */
class UserTeamsService extends MicrosoftUserApiService
{
    /** Scopes Graph, en forme courte : `scopeGranted` gère le préfixe d'URI. */
    public const SCOPE_CHAT_READ      = 'Chat.Read';
    public const SCOPE_CHAT_READWRITE = 'Chat.ReadWrite';
    public const SCOPE_CHAT_SEND      = 'ChatMessage.Send';
    public const SCOPE_TEAMS          = 'Team.ReadBasic.All';
    public const SCOPE_CHANNELS       = 'Channel.ReadBasic.All';
    public const SCOPE_CHANNEL_READ   = 'ChannelMessage.Read.All';
    public const SCOPE_CHANNEL_SEND   = 'ChannelMessage.Send';

    /** Clé Settings : interrupteur global admin de Microsoft Teams dans le chat. */
    public const SETTING_ENABLED = 'user_teams_enabled';

    /** Séparateur de la référence composée (piège 1). */
    public const REF_SEPARATOR = '~';

    /** Longueur maximale d'un message publié (Markdown) : Teams plafonne le corps à ~28 Ko de HTML. */
    public const MAX_MESSAGE_LEN = 10000;

    /** Conversations lues au plus (pages de 50), équipes parcourues au plus. */
    private const CHAT_PAGES = 4;
    private const MAX_TEAMS  = 25;

    /** Réponses montrées sous chaque message d'un canal, et pages d'un fil. */
    private const INLINE_REPLIES = 3;
    private const THREAD_PAGES   = 4;

    /** Texte d'un message au-delà duquel il est coupé (le budget global borne le reste). */
    private const MESSAGE_TEXT_MAX = 4000;

    public const MSG_RECONNECT = 'Connexion à Microsoft Teams expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter Microsoft Teams dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de publier dans Teams non accordée. '
        . 'L\'utilisateur doit reconnecter Microsoft Teams dans Réglages → Comptes connectés.';
    public const MSG_CHATS_NOT_GRANTED = 'Lecture des conversations Teams non accordée. L\'utilisateur doit '
        . 'reconnecter Microsoft Teams dans Réglages → Comptes connectés.';
    public const MSG_CHANNELS_NOT_GRANTED = 'Lecture des canaux Teams non accordée : elle exige qu\'un '
        . 'administrateur de l\'annuaire Microsoft accorde le consentement administrateur à l\'application '
        . '(permission ChannelMessage.Read.All). Les conversations privées, de groupe et de réunion restent lisibles.';
    public const MSG_CHANNEL_LIST_NOT_GRANTED = 'Liste des équipes et des canaux Teams non accordée. '
        . 'L\'utilisateur doit reconnecter Microsoft Teams dans Réglages → Comptes connectés.';
    public const MSG_NO_TEAMS_LICENSE = 'Ce compte Microsoft n\'a pas accès à Teams (licence Teams absente, ou '
        . 'compte personnel — les API Teams ne servent que les comptes d\'organisation). Aucune reconnexion '
        . 'n\'y changera rien.';
    public const MSG_CHAT_NO_THREAD = 'Une conversation Teams (privée, de groupe ou de réunion) n\'a pas de fil '
        . 'de réponses : envoie le message sans `repondre_a`, il s\'ajoutera à la conversation.';
    public const MSG_THREAD_NOT_FOUND = 'Message introuvable dans ce canal : c\'est peut-être une RÉPONSE et non '
        . 'le message d\'origine d\'un fil. Lis le canal (lire_conversation_teams) pour retrouver le message '
        . 'qui ouvre le fil.';

    protected const OAUTH_CLASS       = MicrosoftTeamsOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_TEAMS;
    protected const API_LABEL         = 'Microsoft Teams';
    protected const LOG_LABEL         = '[Teams user]';
    protected const MSG_NOT_FOUND     = 'Conversation, canal ou message introuvable dans Teams (supprimé, '
        . 'inaccessible à ce compte, ou référence invalide — utilise lister_conversations_teams, '
        . 'lister_canaux_teams ou rechercher_teams pour obtenir la référence exacte).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Microsoft Teams (HTTP 400) — vérifie la référence '
        . 'de la conversation ou du message.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Microsoft Teams';
    protected const MSG_SCOPE_INSUFFICIENT = 'Permission Teams non accordée. L\'utilisateur doit reconnecter '
        . 'Microsoft Teams dans Réglages → Comptes connectés ; si le refus persiste, un administrateur de '
        . 'l\'annuaire Microsoft doit accorder le consentement administrateur à l\'application.';

    /** Conversations et canaux, mis en cache pour la durée de l'instance (un tour). */
    private ?array $chatCache    = null;
    private ?array $channelCache = null;

    /** Fuseau de l'utilisateur pour CE tour ; '' = celui du serveur. */
    private string $timeZone = '';

    /** Instance pour l'utilisateur, ou null si Teams n'est pas connecté (ou révoqué). */
    public static function forUser(int $userId): ?self
    {
        $row = self::tokenRowForUser($userId);
        if ($row === null) {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null,
            (string)($row['scope'] ?? ''),
            (string)($row['google_email'] ?? '')
        );
    }

    // ===================== Droits réellement accordés =====================

    /** Conversations lisibles ? (`Chat.ReadWrite` l'implique) */
    public function canReadChats(): bool
    {
        return self::grantsReadChats($this->grantedScope);
    }

    /** Équipes et canaux listables (noms seulement) ? */
    public function canListChannels(): bool
    {
        return self::grantsListChannels($this->grantedScope);
    }

    /** Messages des canaux lisibles ? (consentement administrateur) */
    public function canReadChannels(): bool
    {
        return self::grantsReadChannels($this->grantedScope);
    }

    /** Recherche dans les messages (conversations et/ou canaux) ? */
    public function canSearch(): bool
    {
        return $this->canReadChats() || $this->canReadChannels();
    }

    /** Publier dans une conversation ? */
    public function canSendChats(): bool
    {
        return self::grantsSendChats($this->grantedScope);
    }

    /** Publier dans un canal (ou répondre dans un fil) ? */
    public function canSendChannels(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_CHANNEL_SEND);
    }

    /** Au moins une lecture possible. */
    public function canRead(): bool
    {
        return $this->canReadChats() || $this->canReadChannels();
    }

    /** Au moins une publication possible. */
    public function canWrite(): bool
    {
        return $this->canSendChats() || $this->canSendChannels();
    }

    /** Statiques partagées avec la page Comptes connectés (qui n'a que la colonne `scope`). */
    public static function grantsReadChats(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_CHAT_READ)
            || self::scopeGranted($scope, self::SCOPE_CHAT_READWRITE);
    }

    public static function grantsListChannels(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_TEAMS)
            && self::scopeGranted($scope, self::SCOPE_CHANNELS);
    }

    public static function grantsReadChannels(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_CHANNEL_READ);
    }

    public static function grantsSendChats(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_CHAT_SEND)
            || self::scopeGranted($scope, self::SCOPE_CHAT_READWRITE);
    }

    public static function grantsSendChannels(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_CHANNEL_SEND);
    }

    /** Fuseau de l'utilisateur (celui du navigateur) : dates affichées. Le serveur tourne en UTC. */
    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = UserCalendarService::validTimeZone($timeZone);
    }

    // ===================== Lecture =====================

    /**
     * Conversations du compte, de la plus récemment active à la plus ancienne,
     * filtrées sur le nom ou un participant. Throw RuntimeException à message FR.
     *
     * @return list<array<string,mixed>>
     */
    public function listChats(string $query = '', int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows  = $this->allChats();
        $q     = UserOutlookCalendarService::foldText($query);
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $c) use ($q): bool {
                $haystack = UserOutlookCalendarService::foldText($c['nom'] . ' ' . implode(' ', $c['participants']));
                return str_contains($haystack, $q);
            }));
        }
        return array_map([self::class, 'withoutInternal'], array_slice($rows, 0, $limit));
    }

    /**
     * Canaux des équipes dont la personne est membre (« Équipe / Canal »),
     * filtrés sur le nom. Throw RuntimeException à message FR.
     *
     * @return list<array<string,mixed>>
     */
    public function listChannels(string $query = '', int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows  = $this->allChannels();
        $q     = UserOutlookCalendarService::foldText(ltrim(trim($query), '#'));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn(array $c): bool => str_contains(
                UserOutlookCalendarService::foldText($c['nom'] . ' ' . $c['description']),
                $q
            )));
        }
        return array_slice($rows, 0, $limit);
    }

    /**
     * Recherche dans tous les messages que la personne peut lire (Microsoft
     * Search, entité `chatMessage`). La syntaxe KQL est relayée telle quelle
     * (`from:`, `sent>`, guillemets). Throw RuntimeException à message FR.
     *
     * @return list<array<string,string>>
     */
    public function searchMessages(string $query, int $limit = 10): array
    {
        if (!$this->canSearch()) {
            throw new \RuntimeException(self::MSG_CHATS_NOT_GRANTED);
        }
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('Indique ce qu\'il faut chercher dans Teams.');
        }
        $data = $this->apiJson('POST', self::GRAPH_BASE . '/search/query', [
            'requests' => [[
                'entityTypes' => ['chatMessage'],
                'query'       => ['queryString' => mb_substr($query, 0, 500)],
                'from'        => 0,
                'size'        => max(1, min(25, $limit)),
            ]],
        ]);

        $out = [];
        foreach ((array)($data['value'][0]['hitsContainers'][0]['hits'] ?? []) as $hit) {
            if (is_array($hit) && ($row = self::summarizeHit($hit, $this->timeZone)) !== null) {
                $out[] = $row;
            }
        }
        return $this->withConversationNames($out);
    }

    /**
     * Derniers messages d'une conversation ou d'un canal, du plus ANCIEN au
     * plus récent. Pour un canal, chaque message d'origine porte le nombre de
     * réponses de son fil et les dernières d'entre elles. Une référence qui
     * vise un message d'un canal lit son fil. Throw RuntimeException à message FR.
     */
    public function readConversation(string $ref, int $max, int $tokenBudget): array
    {
        $target = $this->resolveConversation($ref);
        if ($target['kind'] === 'channel' && $target['message'] !== '') {
            return $this->readThread($ref, '', $tokenBudget);
        }
        $max = max(1, min(50, $max));

        if ($target['kind'] === 'chat') {
            if (!$this->canReadChats()) {
                throw new \RuntimeException(self::MSG_CHATS_NOT_GRANTED);
            }
            $data = $this->apiJson('GET', self::GRAPH_BASE . '/chats/' . rawurlencode($target['chat'])
                . '/messages?$top=' . $max . '&$orderby=createdDateTime%20desc');
            $messages = [];
            foreach (self::sortRaw((array)($data['value'] ?? [])) as $m) {
                if (is_array($m) && ($row = self::summarizeMessage($m, $this->timeZone)) !== null) {
                    $messages[] = $row;
                }
            }
        } else {
            if (!$this->canReadChannels()) {
                throw new \RuntimeException(self::MSG_CHANNELS_NOT_GRANTED);
            }
            $data = $this->apiJson('GET', $this->channelUrl($target) . '/messages?$top=' . $max . '&$expand=replies');
            $messages = [];
            foreach (self::sortRaw((array)($data['value'] ?? [])) as $m) {
                if (!is_array($m) || ($row = self::summarizeMessage($m, $this->timeZone)) === null) {
                    continue;
                }
                $replies = [];
                foreach (self::sortRaw((array)($m['replies'] ?? [])) as $r) {
                    if (is_array($r) && ($reply = self::summarizeMessage($r, $this->timeZone)) !== null) {
                        $replies[] = $reply;
                    }
                }
                if ($replies !== []) {
                    $row['reponses']           = count($replies);
                    $row['dernieres_reponses'] = array_slice($replies, -self::INLINE_REPLIES);
                }
                $messages[] = $row;
            }
        }

        $fit = self::fitToBudget($messages, max(4000, $tokenBudget * 4));
        return [
            'conversation' => self::publicTarget($target),
            'messages'     => $fit['messages'],
            'tronque'      => $fit['tronque'],
            'note'         => $fit['tronque']
                ? 'Messages les plus anciens non affichés (budget de lecture atteint).'
                : '',
        ];
    }

    /**
     * Fil complet d'un canal : le message d'origine et toutes ses réponses, du
     * plus ancien au plus récent. `$messageId` vide = celui que porte la
     * référence. Une conversation n'a pas de fil : elle est lue en entier.
     * Throw RuntimeException à message FR.
     */
    public function readThread(string $ref, string $messageId, int $tokenBudget): array
    {
        $target = $this->resolveConversation($ref);
        if ($target['kind'] === 'chat') {
            $read = $this->readConversation(self::chatRef($target['chat']), 30, $tokenBudget);
            $read['note'] = trim('Les conversations Teams n\'ont pas de fil de réponses : voici la conversation. '
                . $read['note']);
            return $read;
        }
        if (!$this->canReadChannels()) {
            throw new \RuntimeException(self::MSG_CHANNELS_NOT_GRANTED);
        }
        $messageId = trim($messageId) !== '' ? self::assertMessageId($messageId) : $target['message'];
        if ($messageId === '') {
            throw new \RuntimeException('Indique le message qui ouvre le fil (identifiant rendu par '
                . 'lire_conversation_teams ou rechercher_teams, ou lien du message).');
        }

        $base = $this->channelUrl($target) . '/messages/' . rawurlencode($messageId);
        try {
            $root = $this->apiJson('GET', $base);
        } catch (\RuntimeException $e) {
            throw $e->getMessage() === static::MSG_NOT_FOUND ? new \RuntimeException(self::MSG_THREAD_NOT_FOUND) : $e;
        }

        $raw = [];
        $url = $base . '/replies?$top=50';
        for ($page = 0; $page < self::THREAD_PAGES && $url !== ''; $page++) {
            $data = $this->apiJson('GET', $url);
            foreach ((array)($data['value'] ?? []) as $r) {
                if (is_array($r)) {
                    $raw[] = $r;
                }
            }
            $url = self::nextPage($data);
        }
        $replies = [];
        foreach (self::sortRaw($raw) as $r) {
            if (($row = self::summarizeMessage($r, $this->timeZone)) !== null) {
                $replies[] = $row;
            }
        }

        $origin = self::summarizeMessage($root, $this->timeZone);
        $fit    = self::fitToBudget($replies, max(4000, $tokenBudget * 4) - strlen((string)json_encode($origin)));
        $target['message'] = $messageId;
        return [
            'conversation'      => self::publicTarget($target),
            'message_d_origine' => $origin,
            'reponses'          => $fit['messages'],
            'tronque'           => $fit['tronque'],
            'note'              => $fit['tronque'] ? 'Réponses les plus anciennes non affichées (budget de lecture atteint).' : '',
        ];
    }

    // ===================== Écriture (file d'approbation uniquement) =====================

    /**
     * Publie un message AU NOM de l'utilisateur : dans une conversation, dans
     * un canal, ou en réponse dans le fil d'un canal. Appelée UNIQUEMENT depuis
     * la file d'approbation (clic « Approuver »). Le texte est du Markdown,
     * converti en HTML ici (MailHtml, comme les emails) : la carte d'approbation
     * a montré ce même texte.
     *
     * @return array{conversation:string,ref:string,message:string,url:string,fil:bool,lieu:string,compte:string}
     */
    public function postMessage(array $args): array
    {
        $n      = self::normalizePostArgs($args);
        $target = $this->resolveConversation($n['conversation'], false);
        $nom    = trim((string)($args['conversation_nom'] ?? '')) ?: $target['nom'];
        $body   = ['body' => ['contentType' => 'html', 'content' => MailHtml::toHtml($n['texte'])]];

        if ($target['kind'] === 'chat') {
            if (!$this->canSendChats()) {
                throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
            }
            if ($n['repondre_a'] !== '') {
                throw new \RuntimeException(self::MSG_CHAT_NO_THREAD);
            }
            $m = $this->apiJson('POST', self::GRAPH_BASE . '/chats/' . rawurlencode($target['chat']) . '/messages', $body);
        } else {
            if (!$this->canSendChannels()) {
                throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
            }
            $fil = $n['repondre_a'] !== '' ? $n['repondre_a'] : $target['message'];
            $url = $this->channelUrl($target) . '/messages'
                . ($fil !== '' ? '/' . rawurlencode($fil) . '/replies' : '');
            $m = $this->apiJson('POST', $url, $body);
            $n['repondre_a'] = $fil;
        }

        $id = trim((string)($m['id'] ?? ''));
        if ($id === '') {
            // Graph a répondu sans identifiant : rien ne prouve que le message
            // existe, et annoncer un succès serait pire que l'erreur.
            throw new \RuntimeException('Teams n\'a pas confirmé la publication du message.');
        }
        error_log(self::LOG_LABEL . ' message publié ' . $target['kind']
            . ($n['repondre_a'] !== '' ? ' (dans un fil)' : '')
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        $lien = self::isTeamsLink((string)($m['webUrl'] ?? '')) ? (string)$m['webUrl'] : $target['lien'];
        return [
            'conversation' => $nom !== '' ? $nom : ($target['kind'] === 'chat' ? 'conversation Teams' : 'canal Teams'),
            'ref'          => self::targetRef($target, false),
            'message'      => $id,
            'url'          => $lien,
            'fil'          => $n['repondre_a'] !== '',
            'lieu'         => $target['kind'] === 'chat' ? 'conversation' : 'canal',
            'compte'       => $this->accountEmail,
        ];
    }

    // ===================== Résolution =====================

    /**
     * Référence donnée par le modèle → lieu réel. Formes acceptées : la
     * référence rendue par les outils, un lien Teams collé par l'utilisateur,
     * ou un NOM (sujet ou participant d'une conversation, « Équipe / Canal »
     * ou canal seul). Un nom ambigu ou introuvable lève une erreur qui NOMME
     * les candidats : deviner enverrait le message au mauvais endroit, et une
     * publication ne se rattrape pas. `$withName` = chercher le nom affiché
     * (best-effort) d'une référence exacte.
     *
     * @return array{kind:string,chat:string,team:string,channel:string,message:string,nom:string,lien:string}
     */
    public function resolveConversation(string $ref, bool $withName = true): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \RuntimeException('Aucune conversation ni canal Teams indiqué.');
        }
        $parsed = self::parseConversationRef($ref);
        if ($parsed !== null) {
            return $this->completeTarget($parsed, $withName);
        }

        $candidates = [];
        if ($this->canReadChats()) {
            foreach ($this->allChats() as $c) {
                $candidates[] = ['type' => 'chat', 'id' => $c['id'], 'nom' => $c['nom'],
                    'alias' => $c['participants'], 'lien' => $c['lien']];
            }
        }
        if ($this->canListChannels()) {
            try {
                foreach ($this->allChannels() as $c) {
                    $candidates[] = ['type' => 'channel', 'id' => $c['id'], 'nom' => $c['nom'],
                        'alias' => [$c['canal']], 'lien' => $c['lien']];
                }
            } catch (\RuntimeException) {
                // Canaux illisibles : les conversations restent candidates.
            }
        }
        if ($candidates === []) {
            throw new \RuntimeException(self::MSG_CHATS_NOT_GRANTED);
        }

        $found = self::matchConversations($ref, $candidates);
        if (count($found) === 1) {
            $target = self::parseConversationRef($found[0]['id']) ?? throw new \RuntimeException(static::MSG_NOT_FOUND);
            $target['nom']  = $found[0]['nom'];
            $target['lien'] = $found[0]['lien'];
            return $target;
        }
        if ($found === []) {
            throw new \RuntimeException('Aucune conversation ni aucun canal Teams ne correspond à « ' . $ref
                . ' ». Utilise lister_conversations_teams ou lister_canaux_teams pour voir les noms exacts.');
        }
        $noms = array_map(static fn(array $c): string => $c['nom'], array_slice($found, 0, 8));
        throw new \RuntimeException('Plusieurs conversations ou canaux Teams correspondent à « ' . $ref . ' » : '
            . implode(' ; ', $noms) . (count($found) > 8 ? ' ; …' : '')
            . '. Demande à l\'utilisateur lequel viser (nom de l\'équipe ET du canal, ou référence exacte).');
    }

    /** Référence exacte → nom affiché et lien (best-effort), équipe retrouvée pour un canal donné seul. */
    private function completeTarget(array $t, bool $withName): array
    {
        if ($t['kind'] === 'chat') {
            if ($withName && $this->canReadChats()) {
                try {
                    foreach ($this->allChats() as $c) {
                        if ($c['id'] === $t['chat']) {
                            $t['nom']  = $c['nom'];
                            $t['lien'] = $c['lien'];
                            break;
                        }
                    }
                } catch (\RuntimeException) {
                    // Nom introuvable : la référence reste utilisable.
                }
            }
            return $t;
        }

        // Canal sans son équipe (identifiant seul) : retrouvé parmi les canaux.
        if ($t['team'] === '') {
            if (!$this->canListChannels()) {
                throw new \RuntimeException('Indique l\'équipe de ce canal (référence « équipe~canal », lien '
                    . 'du canal, ou nom « Équipe / Canal ») : l\'identifiant du canal seul ne suffit pas.');
            }
            foreach ($this->allChannels() as $c) {
                [$team, $channel] = explode(self::REF_SEPARATOR, $c['id'], 2);
                if ($channel === $t['channel']) {
                    $t['team'] = $team;
                    $t['nom']  = $c['nom'];
                    $t['lien'] = $c['lien'];
                    return $t;
                }
            }
            throw new \RuntimeException(static::MSG_NOT_FOUND);
        }

        if ($withName && $this->canListChannels()) {
            if ($this->channelCache !== null) {
                foreach ($this->channelCache as $c) {
                    if ($c['id'] === self::channelRef($t['team'], $t['channel'])) {
                        $t['nom']  = $c['nom'];
                        $t['lien'] = $c['lien'];
                        return $t;
                    }
                }
            }
            // Deux appels plutôt que le parcours de toutes les équipes.
            try {
                $ch   = $this->apiJson('GET', $this->channelUrl($t) . '?$select=displayName,webUrl');
                $team = $this->apiJson('GET', self::GRAPH_BASE . '/teams/' . rawurlencode($t['team']) . '?$select=displayName');
                $t['nom']  = self::channelLabel((string)($team['displayName'] ?? ''), (string)($ch['displayName'] ?? ''));
                $t['lien'] = self::isTeamsLink((string)($ch['webUrl'] ?? '')) ? (string)$ch['webUrl'] : '';
            } catch (\RuntimeException) {
                // Nom introuvable : la référence reste utilisable.
            }
        }
        return $t;
    }

    /** Toutes les conversations (au plus CHAT_PAGES pages), triées par activité. */
    private function allChats(): array
    {
        if ($this->chatCache !== null) {
            return $this->chatCache;
        }
        if (!$this->canReadChats()) {
            throw new \RuntimeException(self::MSG_CHATS_NOT_GRANTED);
        }
        try {
            $raw = $this->pages(self::GRAPH_BASE . '/me/chats?$expand=members,lastMessagePreview'
                . '&$orderby=lastMessagePreview/createdDateTime%20desc&$top=50', self::CHAT_PAGES);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== static::MSG_BAD_REQUEST) {
                throw $e;
            }
            // Combinaison refusée (cf. l'en-tête) : la liste est retriée ici de toute façon.
            $raw = $this->pages(self::GRAPH_BASE . '/me/chats?$expand=members&$top=50', self::CHAT_PAGES);
        }
        $rows = [];
        foreach ($raw as $c) {
            if (($row = self::summarizeChat($c, $this->accountEmail, $this->timeZone)) !== null) {
                $rows[] = $row;
            }
        }
        return $this->chatCache = self::sortChats($rows);
    }

    /** Canaux de toutes les équipes de la personne (au plus MAX_TEAMS équipes). */
    private function allChannels(): array
    {
        if ($this->channelCache !== null) {
            return $this->channelCache;
        }
        if (!$this->canListChannels()) {
            throw new \RuntimeException(self::MSG_CHANNEL_LIST_NOT_GRANTED);
        }
        $teams = [];
        foreach ($this->pages(self::GRAPH_BASE . '/me/joinedTeams', 2) as $t) {
            $id = trim((string)($t['id'] ?? ''));
            if ($id !== '' && empty($t['isArchived'])) {
                $teams[$id] = trim((string)($t['displayName'] ?? '')) ?: '(équipe sans nom)';
            }
        }
        $rows = [];
        foreach (array_slice($teams, 0, self::MAX_TEAMS, true) as $teamId => $teamName) {
            try {
                $data = $this->apiJson('GET', self::GRAPH_BASE . '/teams/' . rawurlencode((string)$teamId) . '/channels');
            } catch (\RuntimeException) {
                continue; // Une équipe illisible n'empêche pas les autres.
            }
            foreach ((array)($data['value'] ?? []) as $c) {
                if (is_array($c) && ($row = self::summarizeChannel($c, (string)$teamId, $teamName)) !== null) {
                    $rows[] = $row;
                }
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp(
            UserOutlookCalendarService::foldText($a['nom']),
            UserOutlookCalendarService::foldText($b['nom'])
        ));
        return $this->channelCache = $rows;
    }

    /** Pages successives d'une liste Graph (piège 6). */
    private function pages(string $url, int $maxPages): array
    {
        $out = [];
        for ($page = 0; $page < $maxPages && $url !== ''; $page++) {
            $data = $this->apiJson('GET', $url);
            foreach ((array)($data['value'] ?? []) as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            $url = self::nextPage($data);
        }
        return $out;
    }

    /** Résultats de recherche : nom des conversations retrouvé (best-effort, conversations en cache). */
    private function withConversationNames(array $rows): array
    {
        $names = [];
        try {
            if ($this->canReadChats()) {
                foreach ($this->allChats() as $c) {
                    $names[$c['id']] = $c['nom'];
                }
            }
            foreach ($this->channelCache ?? [] as $c) {
                $names[$c['id']] = $c['nom'];
            }
        } catch (\RuntimeException) {
            return $rows;
        }
        foreach ($rows as &$row) {
            if (isset($names[$row['conversation']])) {
                $row['conversation_nom'] = $names[$row['conversation']];
            }
        }
        unset($row);
        return $rows;
    }

    /** Adresse Graph d'un canal. */
    private function channelUrl(array $t): string
    {
        return self::GRAPH_BASE . '/teams/' . rawurlencode($t['team']) . '/channels/' . rawurlencode($t['channel']);
    }

    /** Teams sans licence : un refus que personne ne saurait lire, et qu'aucune reconnexion ne lève. */
    protected function specificHttpError(int $status, string $body): ?\RuntimeException
    {
        // 401 et 404 sont tranchés avant ce point par le squelette commun ; un
        // compte sans licence Teams est refusé en 403 (ou 400) avec ce mot.
        if ($status >= 400 && stripos($body, 'license') !== false) {
            return new \RuntimeException(self::MSG_NO_TEAMS_LICENSE);
        }
        return null;
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /** Référence d'une conversation (et d'un de ses messages). */
    public static function chatRef(string $chatId, string $messageId = ''): string
    {
        return $chatId . ($messageId !== '' ? self::REF_SEPARATOR . $messageId : '');
    }

    /** Référence d'un canal (et d'un de ses messages). */
    public static function channelRef(string $teamId, string $channelId, string $messageId = ''): string
    {
        return $teamId . self::REF_SEPARATOR . $channelId . ($messageId !== '' ? self::REF_SEPARATOR . $messageId : '');
    }

    /** Référence d'une cible résolue, avec ou sans son message. */
    public static function targetRef(array $t, bool $withMessage = true): string
    {
        $message = $withMessage ? (string)($t['message'] ?? '') : '';
        return $t['kind'] === 'chat'
            ? self::chatRef($t['chat'], $message)
            : self::channelRef($t['team'], $t['channel'], $message);
    }

    /**
     * Référence ou lien → cible ; null pour un NOM (à résoudre parmi les
     * conversations et canaux). Throw RuntimeException sur un lien non reconnu.
     *
     * @return array{kind:string,chat:string,team:string,channel:string,message:string,nom:string,lien:string}|null
     */
    public static function parseConversationRef(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return self::parseTeamsLink($ref);
        }

        $parts = explode(self::REF_SEPARATOR, $ref);
        $n     = count($parts);
        if ($n > 3) {
            return null;
        }
        $first = $parts[0];

        if (self::isGuid($first) && $n >= 2 && self::isThreadId($parts[1])) {
            return self::target('channel', '', $first, $parts[1], $n === 3 ? self::assertMessageId($parts[2]) : '');
        }
        if (self::isThreadId($first)) {
            if (self::isChannelId($first)) {
                return self::target('channel', '', '', $first, $n === 2 ? self::assertMessageId($parts[1]) : '');
            }
            return $n <= 2
                ? self::target('chat', $first, '', '', $n === 2 ? self::assertMessageId($parts[1]) : '')
                : null;
        }
        return null;
    }

    /**
     * Lien Teams collé par l'utilisateur. Trois formes, celles de « Copier le
     * lien » : `/l/chat/{conversation}/…`, `/l/channel/{canal}/{nom}?groupId=
     * {équipe}`, `/l/message/{conversation ou canal}/{message}?groupId=…&
     * parentMessageId=…` (un message de canal vise le fil de son message
     * d'origine).
     */
    public static function parseTeamsLink(string $url): array
    {
        if (!self::isTeamsLink($url)) {
            throw new \RuntimeException('Lien non reconnu : seul un lien Microsoft Teams (« Copier le lien » d\'une '
                . 'conversation, d\'un canal ou d\'un message) peut désigner une conversation Teams.');
        }
        $path     = (string)parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $group = trim((string)($query['groupId'] ?? ''));

        $kind = '';
        $id   = '';
        $msg  = '';
        foreach ($segments as $i => $seg) {
            if ($seg === 'l' && isset($segments[$i + 2])) {
                $kind = $segments[$i + 1];
                $id   = rawurldecode($segments[$i + 2]);
                $msg  = isset($segments[$i + 3]) ? rawurldecode($segments[$i + 3]) : '';
                break;
            }
        }
        if (!self::isThreadId($id)) {
            throw new \RuntimeException('Lien Teams sans identifiant de conversation ni de canal reconnaissable.');
        }

        if ($kind === 'chat') {
            return self::target('chat', $id, '', '', '');
        }
        if ($kind === 'channel') {
            return self::target('channel', '', self::isGuid($group) ? $group : '', $id, '');
        }
        if ($kind === 'message') {
            $parent = trim((string)($query['parentMessageId'] ?? ''));
            $root   = preg_match('/^\d{1,20}$/', $parent) ? $parent : $msg;
            if ($group !== '' || self::isChannelId($id)) {
                return self::target('channel', '', self::isGuid($group) ? $group : '', $id, self::assertMessageId($root));
            }
            return self::target('chat', $id, '', '', preg_match('/^\d{1,20}$/', $msg) ? $msg : '');
        }
        throw new \RuntimeException('Lien Teams non reconnu : utilise le lien d\'une conversation, d\'un canal ou d\'un message.');
    }

    /** Lien Microsoft Teams d'organisation (teams.microsoft.com, teams.cloud.microsoft), https seulement. */
    public static function isTeamsLink(string $url): bool
    {
        $url = trim($url);
        if (!preg_match('#^https://#i', $url)) {
            return false;
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        foreach (['teams.microsoft.com', 'teams.cloud.microsoft'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /** Identifiant d'un message Teams : une chaîne de chiffres (GARDÉE en chaîne : un entier perdrait des chiffres). */
    public static function assertMessageId(string $id): string
    {
        $id = trim($id);
        if (!preg_match('/^\d{1,20}$/', $id)) {
            throw new \RuntimeException('Identifiant de message Teams invalide : « ' . mb_substr($id, 0, 40)
                . ' » (attendu : celui rendu par lire_conversation_teams ou rechercher_teams).');
        }
        return $id;
    }

    /** Identifiant de conversation ou de canal : `19:…@thread.v2`, `19:…@unq.gbl.spaces`, `19:…@thread.tacv2`… */
    public static function isThreadId(string $id): bool
    {
        return (bool)preg_match('/^19:[^\s~\/?#]{3,300}@[a-z0-9.]+$/i', $id);
    }

    /** Identifiant de CANAL (et non de conversation). */
    public static function isChannelId(string $id): bool
    {
        return (bool)preg_match('/@thread\.(tacv2|skype)$/i', $id);
    }

    public static function isGuid(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** « Équipe / Canal ». */
    public static function channelLabel(string $team, string $channel): string
    {
        $team    = trim($team);
        $channel = trim($channel) ?: '(canal sans nom)';
        return $team !== '' ? $team . ' / ' . $channel : $channel;
    }

    /**
     * Ligne de conversation Graph → `{id, nom, type, participants, dernier_message,
     * non_lu, lien}` (+ `_tri` interne). Le nom d'une conversation sans sujet
     * est celui des AUTRES participants (piège 3).
     */
    public static function summarizeChat(array $c, string $selfEmail = '', string $timeZone = ''): ?array
    {
        $id = trim((string)($c['id'] ?? ''));
        if (!self::isThreadId($id)) {
            return null;
        }
        $self   = mb_strtolower(trim($selfEmail));
        $others = [];
        foreach ((array)($c['members'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $email = mb_strtolower(trim((string)($m['email'] ?? '')));
            if ($self !== '' && $email === $self) {
                continue;
            }
            $name = trim((string)($m['displayName'] ?? '')) ?: $email;
            if ($name !== '') {
                $others[] = $name;
            }
        }
        $others = array_values(array_unique($others));

        $type = match ((string)($c['chatType'] ?? '')) {
            'oneOnOne' => 'conversation privée',
            'meeting'  => 'conversation de réunion',
            default    => 'conversation de groupe',
        };
        $topic = trim((string)($c['topic'] ?? ''));
        if ($topic !== '') {
            $nom = $topic;
        } elseif ($others !== []) {
            $nom = implode(', ', array_slice($others, 0, 4))
                . (count($others) > 4 ? ' et ' . (count($others) - 4) . ' autres' : '');
        } else {
            $nom = '(conversation sans nom)';
        }

        $preview = is_array($c['lastMessagePreview'] ?? null) ? $c['lastMessagePreview'] : null;
        $last    = null;
        $sortKey = (string)($c['lastUpdatedDateTime'] ?? $c['createdDateTime'] ?? '');
        $unread  = false;
        if ($preview !== null && empty($preview['isDeleted'])) {
            $created = (string)($preview['createdDateTime'] ?? '');
            $text    = self::bodyText((array)($preview['body'] ?? []));
            $last = [
                'auteur'  => self::authorName($preview),
                'date'    => self::formatDate($created, $timeZone),
                'extrait' => mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text,
            ];
            if ($created !== '') {
                $sortKey = $created;
                $read    = (string)($c['viewpoint']['lastMessageReadDateTime'] ?? '');
                $unread  = $read === '' || strcmp(self::isoKey($created), self::isoKey($read)) > 0;
            }
        }

        return [
            'id'              => $id,
            'nom'             => $nom,
            'type'            => $type,
            'participants'    => array_slice($others, 0, 8),
            'dernier_message' => $last,
            'non_lu'          => $unread,
            'lien'            => self::isTeamsLink((string)($c['webUrl'] ?? '')) ? (string)$c['webUrl'] : '',
            '_tri'            => self::isoKey($sortKey),
        ];
    }

    /** Ligne de canal Graph → `{id (équipe~canal), nom, equipe, canal, description, prive, lien}`. */
    public static function summarizeChannel(array $c, string $teamId, string $teamName): ?array
    {
        $id = trim((string)($c['id'] ?? ''));
        if (!self::isThreadId($id) || !self::isGuid($teamId)) {
            return null;
        }
        $canal = trim((string)($c['displayName'] ?? '')) ?: '(canal sans nom)';
        return [
            'id'          => self::channelRef($teamId, $id),
            'nom'         => self::channelLabel($teamName, $canal),
            'equipe'      => $teamName,
            'canal'       => $canal,
            'description' => trim((string)($c['description'] ?? '')),
            'prive'       => in_array((string)($c['membershipType'] ?? ''), ['private', 'shared'], true),
            'lien'        => self::isTeamsLink((string)($c['webUrl'] ?? '')) ? (string)$c['webUrl'] : '',
        ];
    }

    /**
     * Message Graph → `{id, auteur, date, texte, objet?, pieces_jointes?, lien?}`,
     * ou null pour un message de service, supprimé ou vide (piège 4).
     */
    public static function summarizeMessage(array $m, string $timeZone = ''): ?array
    {
        $type = (string)($m['messageType'] ?? 'message');
        if ($type !== 'message' || trim((string)($m['deletedDateTime'] ?? '')) !== '') {
            return null;
        }
        $id = trim((string)($m['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $text  = self::bodyText((array)($m['body'] ?? []));
        $files = [];
        foreach ((array)($m['attachments'] ?? []) as $a) {
            if (is_array($a) && trim((string)($a['name'] ?? '')) !== '') {
                $files[] = trim((string)$a['name']);
            }
        }
        if ($text === '' && $files === []) {
            return null;
        }
        if (mb_strlen($text) > self::MESSAGE_TEXT_MAX) {
            $text = mb_substr($text, 0, self::MESSAGE_TEXT_MAX) . ' [… message coupé]';
        }
        $row = [
            'id'     => $id,
            'auteur' => self::authorName($m),
            'date'   => self::formatDate((string)($m['createdDateTime'] ?? ''), $timeZone),
            'texte'  => $text,
        ];
        $subject = trim((string)($m['subject'] ?? ''));
        if ($subject !== '') {
            $row['objet'] = $subject;
        }
        if ($files !== []) {
            $row['pieces_jointes'] = $files;
        }
        if (self::isTeamsLink((string)($m['webUrl'] ?? ''))) {
            $row['lien'] = (string)$m['webUrl'];
        }
        return $row;
    }

    /**
     * Résultat de recherche → `{conversation, message, lieu, auteur, date,
     * extrait}` (piège 5) ; null si aucun lieu n'est désigné.
     */
    public static function summarizeHit(array $hit, string $timeZone = ''): ?array
    {
        $r  = is_array($hit['resource'] ?? null) ? $hit['resource'] : [];
        $id = trim((string)($r['id'] ?? ''));
        $team    = trim((string)($r['channelIdentity']['teamId'] ?? ''));
        $channel = trim((string)($r['channelIdentity']['channelId'] ?? ''));
        $chat    = trim((string)($r['chatId'] ?? ''));

        if (self::isGuid($team) && self::isThreadId($channel)) {
            $conversation = self::channelRef($team, $channel);
            $lieu         = 'canal';
        } elseif (self::isThreadId($chat)) {
            $conversation = self::chatRef($chat);
            $lieu         = 'conversation';
        } else {
            return null;
        }
        $summary = html_entity_decode(strip_tags((string)($hit['summary'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $summary = trim((string)preg_replace('/\s+/u', ' ', $summary));

        $from = $r['from']['emailAddress'] ?? [];
        return [
            'conversation' => $conversation,
            'message'      => preg_match('/^\d{1,20}$/', $id) ? $id : '',
            'lieu'         => $lieu,
            'auteur'       => trim((string)($from['name'] ?? '')) ?: trim((string)($from['address'] ?? '')),
            'date'         => self::formatDate((string)($r['createdDateTime'] ?? ''), $timeZone),
            'extrait'      => $summary,
        ];
    }

    /** Corps d'un message → texte : mentions en « @Nom », pièces jointes retirées (piège 4). */
    public static function bodyText(array $body): string
    {
        $content = (string)($body['content'] ?? '');
        if (trim($content) === '') {
            return '';
        }
        if (strtolower((string)($body['contentType'] ?? 'text')) !== 'html') {
            return trim($content);
        }
        $content = (string)preg_replace('#<at\b[^>]*>(.*?)</at>#is', '@$1', $content);
        $content = (string)preg_replace('#<attachment\b[^>]*>.*?</attachment>#is', '', $content);
        $content = (string)preg_replace('#<attachment\b[^>]*/?>#i', '', $content);
        return UserOutlookService::htmlToText($content);
    }

    /** Auteur d'un message : la personne, sinon l'application (bot, connecteur). */
    public static function authorName(array $m): string
    {
        foreach (['user', 'application', 'device'] as $k) {
            $name = trim((string)($m['from'][$k]['displayName'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return 'Inconnu';
    }

    /** Horodatage Graph → « 15/01/2026 09:32 » dans le fuseau de l'utilisateur ; illisible → tel quel. */
    public static function formatDate(string $iso, string $timeZone = ''): string
    {
        if (trim($iso) === '') {
            return '';
        }
        $tz = UserCalendarService::validTimeZone($timeZone);
        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone($tz !== '' ? $tz : date_default_timezone_get()))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /** Clé de tri d'un horodatage (UTC, comparable en chaîne) ; '' si illisible. */
    public static function isoKey(string $iso): string
    {
        if (trim($iso) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Messages Graph bruts → triés du plus ancien au plus récent (piège 2). */
    public static function sortRaw(array $messages): array
    {
        $messages = array_values(array_filter($messages, 'is_array'));
        usort($messages, static fn(array $a, array $b): int => strcmp(
            self::isoKey((string)($a['createdDateTime'] ?? '')),
            self::isoKey((string)($b['createdDateTime'] ?? ''))
        ));
        return $messages;
    }

    /** Conversations → de la plus récemment active à la plus ancienne. */
    public static function sortChats(array $chats): array
    {
        usort($chats, static fn(array $a, array $b): int => strcmp((string)($b['_tri'] ?? ''), (string)($a['_tri'] ?? '')));
        return $chats;
    }

    /** Clés internes (préfixe `_`) retirées avant de rendre une ligne au modèle. */
    public static function withoutInternal(array $row): array
    {
        return array_filter($row, static fn($k): bool => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Messages (du plus ancien au plus récent) bornés à un budget de
     * caractères : les plus ANCIENS tombent d'abord, les plus récents restent.
     *
     * @return array{messages:list<array>,tronque:bool}
     */
    public static function fitToBudget(array $messages, int $maxChars): array
    {
        $maxChars = max(1000, $maxChars);
        $tronque  = false;
        while (count($messages) > 1 && strlen((string)json_encode($messages, JSON_UNESCAPED_UNICODE)) > $maxChars) {
            array_shift($messages);
            $tronque = true;
        }
        if (count($messages) === 1 && strlen((string)json_encode($messages, JSON_UNESCAPED_UNICODE)) > $maxChars) {
            $messages[0]['texte'] = mb_substr((string)($messages[0]['texte'] ?? ''), 0, (int)($maxChars / 2))
                . ' [… message coupé]';
            unset($messages[0]['dernieres_reponses']);
            $tronque = true;
        }
        return ['messages' => array_values($messages), 'tronque' => $tronque];
    }

    /**
     * Noms donnés par le modèle → conversations et canaux correspondants, par
     * ordre de confiance (sans casse, accents, `#` ni `@`) : le NOM égal à la
     * demande (« Marie Dupont » = la conversation à deux), sinon un
     * participant ou le canal seul égal à la demande (« Général » existe dans
     * chaque équipe : ambigu, et c'est voulu), sinon un nom qui la CONTIENT.
     *
     * @param list<array{type:string,id:string,nom:string,alias:list<string>,lien:string}> $candidates
     * @return list<array{type:string,id:string,nom:string,alias:list<string>,lien:string}>
     */
    public static function matchConversations(string $ref, array $candidates): array
    {
        $needle = self::foldName($ref);
        if ($needle === '') {
            return [];
        }
        $tiers = [[], [], []];
        foreach ($candidates as $c) {
            $nom   = self::foldName($c['nom']);
            $alias = array_map([self::class, 'foldName'], $c['alias'] ?? []);
            if ($nom === $needle) {
                $tiers[0][] = $c;
            } elseif (in_array($needle, $alias, true)) {
                $tiers[1][] = $c;
            } else {
                foreach (array_merge([$nom], $alias) as $f) {
                    if ($f !== '' && str_contains($f, $needle)) {
                        $tiers[2][] = $c;
                        break;
                    }
                }
            }
        }
        foreach ($tiers as $tier) {
            if ($tier !== []) {
                return $tier;
            }
        }
        return [];
    }

    /** Nom normalisé pour comparaison : « Équipe > Canal », « #canal » et « équipe/canal » se valent. */
    public static function foldName(string $name): string
    {
        $name = UserOutlookCalendarService::foldText(ltrim(trim($name), '#@'));
        $name = (string)preg_replace('#\s*[/>|]\s*#u', ' / ', $name);
        return trim($name);
    }

    /**
     * Arguments d'une publication, validés. Le texte reste du Markdown (converti
     * à la publication) : la carte montre ce que l'utilisateur relit.
     *
     * @return array{conversation:string,texte:string,repondre_a:string}
     */
    public static function normalizePostArgs(array $args): array
    {
        $conversation = trim((string)($args['conversation'] ?? ''));
        if ($conversation === '') {
            throw new \RuntimeException('Aucune conversation ni canal Teams indiqué pour la publication.');
        }
        $texte = trim((string)($args['texte'] ?? ''));
        if ($texte === '') {
            throw new \RuntimeException('Le message à publier est vide.');
        }
        if (mb_strlen($texte) > self::MAX_MESSAGE_LEN) {
            throw new \RuntimeException('Message trop long pour Teams : raccourcis-le (limite ≈ '
                . self::MAX_MESSAGE_LEN . ' caractères).');
        }
        $fil = trim((string)($args['repondre_a'] ?? ''));
        return [
            'conversation' => $conversation,
            'texte'        => $texte,
            'repondre_a'   => $fil !== '' ? self::assertMessageId($fil) : '',
        ];
    }

    /** Cible rendue au modèle (sans les champs vides). */
    public static function publicTarget(array $t): array
    {
        return array_filter([
            'ref'  => self::targetRef($t, false),
            'nom'  => (string)($t['nom'] ?? ''),
            'lieu' => $t['kind'] === 'chat' ? 'conversation' : 'canal',
            'fil'  => (string)($t['message'] ?? ''),
            'lien' => (string)($t['lien'] ?? ''),
        ], static fn(string $v): bool => $v !== '');
    }

    /**
     * Aperçu HUMAIN de la publication pour la carte d'approbation (mêmes clés
     * que les autres connecteurs + `icone`). Le premier impact est le plus
     * important : à la différence d'un brouillon, ce message part RÉELLEMENT
     * et devient visible aussitôt.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args): array
    {
        $nom   = trim((string)($args['conversation_nom'] ?? '')) ?: trim((string)($args['conversation'] ?? ''));
        $canal = (string)($args['lieu'] ?? '') === 'canal';
        $fil   = trim((string)($args['repondre_a'] ?? '')) !== '';
        $texte = trim((string)($args['texte'] ?? ''));
        $lien  = (string)($args['lien'] ?? '');

        $action = $fil
            ? t('Répondre dans un fil Teams')
            : ($canal ? t('Publier dans un canal Teams') : t('Envoyer un message Teams'));
        return [
            'action'  => $action,
            'fichier' => $nom !== '' ? $nom : t('(conversation non précisée)'),
            'url'     => self::isTeamsLink($lien) ? $lien : null,
            'impacts' => array_values(array_filter([
                $canal
                    ? t('Le message sera publié EN VOTRE NOM et visible immédiatement par les membres du canal.')
                    : t('Le message sera envoyé EN VOTRE NOM et visible immédiatement par les participants de la conversation.'),
                $fil ? t('Il sera rattaché au fil du message d\'origine.') : null,
                t('Contrairement à un brouillon d\'email, rien ne sera mis en attente : la publication est immédiate.'),
                t('MIA ne peut ni modifier ni supprimer un message publié — vous le ferez depuis Teams si besoin.'),
            ])),
            'apercu'  => $texte === '' ? null : (mb_strlen($texte) > 280 ? mb_substr($texte, 0, 280) . '…' : $texte),
            'icone'   => '/assets/img/microsoft-teams.svg',
        ];
    }

    /** Lien de page suivante, seulement s'il vise Graph (piège 6). */
    public static function nextPage(array $data): string
    {
        $next = (string)($data['@odata.nextLink'] ?? '');
        return str_starts_with($next, self::GRAPH_BASE . '/') ? $next : '';
    }

    /** @return array{kind:string,chat:string,team:string,channel:string,message:string,nom:string,lien:string} */
    private static function target(string $kind, string $chat, string $team, string $channel, string $message): array
    {
        return ['kind' => $kind, 'chat' => $chat, 'team' => $team, 'channel' => $channel,
            'message' => $message, 'nom' => '', 'lien' => ''];
    }
}
