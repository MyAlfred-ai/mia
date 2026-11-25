<?php

declare(strict_types=1);

/**
 * Accès à l'espace de travail SLACK d'un utilisateur (OAuth Slack par
 * utilisateur, jetons `UserDriveToken` provider 'slack') : recherche et
 * lecture de messages, publication d'un message APRÈS APPROBATION — dans un
 * canal ou à une personne (message direct), avec ou sans fichiers joints.
 *
 * Tout se fait avec un jeton UTILISATEUR : MIA ne voit que ce que la personne
 * voit et ne publie qu'en son nom. Aucun jeton de bot n'est demandé nulle part
 * (cf. `SlackUserOAuth` : `user_scope`, jamais `scope`).
 *
 * ── CE QUE CE CONNECTEUR NE FAIT PAS, ET POURQUOI ──────────────────────────
 *  - **Aucune suppression, aucune modification.** `chat:write` les autoriserait
 *    côté API sur les messages de la personne (`chat.delete`, `chat.update`) :
 *    aucun outil n'est exposé, exactement comme pour `Mail.ReadWrite` chez
 *    Outlook. Ce sont des garde-fous applicatifs, pas un demi-droit Slack.
 *  - **Un message direct, seulement à UNE personne désignée.** `im:write`
 *    ouvre la conversation directe (`conversations.open`) au moment d'y
 *    publier, comme pour un canal : même file d'approbation, même carte, qui
 *    nomme la personne. Jamais de conversation de groupe ouverte par MIA.
 *  - **Des fichiers, seulement ceux d'un workflow.** `files:write` téléverse
 *    les pièces jointes d'une brique Slack (`files.getUploadURLExternal`, puis
 *    `files.completeUploadExternal`, qui les publie avec le texte en un seul
 *    message). Le modèle ne désigne jamais un fichier : la liste est posée
 *    par le serveur (`_wf_attach`, WorkflowDeliveries::attachmentsFor).
 *  - **Aucune publication sans approbation.** `postMessage()` n'est appelée que
 *    depuis la file d'approbation, au clic « Approuver ». Et contrairement à un
 *    brouillon d'email, un message publié est VU IMMÉDIATEMENT : la carte
 *    d'approbation le dit en toutes lettres.
 *
 * ── DEUX PIÈGES SLACK PAYÉS DANS CE FICHIER ────────────────────────────────
 *  1. **`ts` est une CHAÎNE, et c'est l'identifiant du message.**
 *     « 1720000000.123456 » ressemble à un nombre : le caster en float perd les
 *     derniers chiffres, et l'identifiant ne désigne plus AUCUN message — le
 *     fil visé n'existe pas, la réponse part à la racine du canal. Il reste
 *     donc une chaîne de bout en bout, validée par `assertTs()`.
 *  2. **L'historique arrive du plus RÉCENT au plus ancien.** Donné tel quel au
 *     modèle, « le dernier message » devient le premier de la liste et tout
 *     résumé chronologique s'inverse. `readChannel()` remet l'ordre de lecture.
 *
 * Plomberie commune (refresh lazy, retry 401, `ok:false`, erreurs FR sans
 * fuite) : `SlackUserApiService` — ce fichier ne porte que le métier Slack.
 */
class UserSlackService extends SlackUserApiService
{
    /** Clé Settings : interrupteur global admin du Slack utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_slack_enabled';

    public const SCOPE_SEARCH = 'search:read';
    public const SCOPE_USERS  = 'users:read';
    public const SCOPE_WRITE  = 'chat:write';
    /** Ouvrir une conversation directe avec une personne, pour y publier. */
    public const SCOPE_DIRECT = 'im:write';
    /** Joindre des fichiers à une publication. */
    public const SCOPE_FILES  = 'files:write';
    /** Retrouver une personne par son adresse (une Fiche porte souvent un email). */
    public const SCOPE_EMAILS = 'users:read.email';

    /** Lister les conversations : une famille de scopes, un par type. */
    public const SCOPES_LIST = ['channels:read', 'groups:read', 'im:read', 'mpim:read'];

    /** Lire les messages : même découpage par type de conversation. */
    public const SCOPES_HISTORY = ['channels:history', 'groups:history', 'im:history', 'mpim:history'];

    public const MSG_RECONNECT = 'Connexion Slack expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son Slack dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de publication Slack non accordée. '
        . 'L\'utilisateur doit reconnecter son Slack dans Réglages → Comptes connectés.';
    public const MSG_DIRECT_NOT_GRANTED = 'Votre connexion Slack ne permet pas encore d\'écrire à une personne '
        . '(autorisation im:write). Reconnectez Slack dans Réglages → Comptes connectés.';
    public const MSG_FILES_NOT_GRANTED = 'Votre connexion Slack ne permet pas encore de joindre des fichiers '
        . '(autorisation files:write). Reconnectez Slack dans Réglages → Comptes connectés.';
    public const MSG_EMAIL_NOT_GRANTED = 'Votre connexion Slack ne permet pas de retrouver une personne par son adresse '
        . '(autorisation users:read.email) : désignez-la par son nom, ou reconnectez Slack dans Réglages → Comptes connectés.';

    protected const OAUTH_CLASS       = SlackMessagesOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_SLACK;
    protected const API_LABEL         = 'Slack';
    protected const LOG_LABEL         = '[Slack user]';
    protected const MSG_NOT_FOUND     = 'Conversation ou message Slack introuvable : l\'identifiant est invalide, '
        . 'ou l\'utilisateur n\'a pas accès à cette conversation (MIA ne voit que ce qu\'il voit).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Slack — vérifie le canal visé et les paramètres fournis.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Slack';
    protected const MSG_SCOPE_INSUFFICIENT = 'Autorisation Slack non accordée. L\'utilisateur doit reconnecter '
        . 'son Slack dans Réglages → Comptes connectés ; sur certains espaces de travail, un administrateur Slack doit '
        . 'approuver l\'application au préalable.';

    /** Conversations ramenées en une passe (limite haute de l'API : 1000). */
    private const CHANNELS_PAGE = 200;

    /** Pages de conversations parcourues au plus — la méthode est limitée en débit. */
    private const CHANNELS_MAX_PAGES = 3;

    /** Messages ramenés par lecture de canal ou de fil. */
    private const HISTORY_MAX = 50;

    /** Personnes résolues par appel : au-delà, les identifiants restent bruts. */
    private const NAMES_MAX = 30;

    /** Membres de l'espace parcourus pour retrouver une personne par son nom (users.list, limité en débit). */
    private const PEOPLE_PAGE = 200;
    private const PEOPLE_MAX_PAGES = 5;

    /** Lecture d'une période (brique « Lire » d'un workflow) : messages et fils dépliés au plus. */
    public const PERIOD_MAX = 200;
    private const PERIOD_THREADS_MAX = 15;

    /** Fichiers joints à une publication : nombre et octets au plus. */
    public const FILES_MAX = 10;
    public const FILES_MAX_BYTES = 50 * 1024 * 1024;

    /** Cache d'instance : un tour de chat lit souvent le même canal deux fois. */
    private array $cacheCanaux = [];
    private array $cacheNoms   = [];
    private array $cachePersonnes = [];

    /** Instance pour l'utilisateur, ou null si Slack non connecté (ou révoqué). */
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

    /** Recherche transverse accordée ? */
    public function canSearch(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_SEARCH);
    }

    /** Lecture d'au moins un type de conversation accordée ? */
    public function canRead(): bool
    {
        return self::anyScopeGranted($this->grantedScope, self::SCOPES_HISTORY);
    }

    /** Liste des conversations accordée ? */
    public function canList(): bool
    {
        return self::anyScopeGranted($this->grantedScope, self::SCOPES_LIST);
    }

    /** Publication accordée ? */
    public function canPost(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_WRITE);
    }

    /** Écrire à une personne (message direct) accordé ? */
    public function canDirect(): bool
    {
        return $this->canPost() && self::scopeGranted($this->grantedScope, self::SCOPE_DIRECT);
    }

    /** Joindre des fichiers accordé ? */
    public function canAttach(): bool
    {
        return $this->canPost() && self::scopeGranted($this->grantedScope, self::SCOPE_FILES);
    }

    /** Retrouver une personne par son adresse accordé ? */
    public function canFindByEmail(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_EMAILS);
    }

    /**
     * Ce que la connexion permet, pour l'écran d'un workflow (« À régler ») :
     * mêmes gardes que les outils et la publication.
     *
     * @return array{read: bool, search: bool, list: bool, post: bool, direct: bool, files: bool, email: bool}
     */
    public function capabilities(): array
    {
        return [
            'read'   => $this->canRead(),
            'search' => $this->canSearch(),
            'list'   => $this->canList(),
            'post'   => $this->canPost(),
            'direct' => $this->canDirect(),
            'files'  => $this->canAttach(),
            'email'  => $this->canFindByEmail(),
        ];
    }

    /**
     * Ce que chaque autorisation ouvre dans MIA — SOURCE UNIQUE du tableau de
     * l'écran « Application Slack ». Bâti sur les MÊMES constantes que
     * canSearch / canList / canRead / canPost, qui décident des outils exposés
     * au modèle : la page dit donc à l'administrateur ce qui se passe
     * réellement quand un utilisateur accorde, ou refuse, une autorisation.
     * Le test vérifie que chaque scope demandé y figure une fois, et que chaque
     * outil cité existe dans `SlackTools::definitionsFor`.
     *
     * @return list<array{scopes: list<string>, usage: string, outils: list<string>, sans: string}>
     */
    public static function scopeGuide(): array
    {
        return [
            [
                'scopes' => [self::SCOPE_SEARCH],
                'usage'  => "Chercher un sujet dans tout ce que l'utilisateur peut lire : canaux publics, "
                    . "canaux privés dont il est membre, messages directs.",
                'outils' => ['rechercher_slack'],
                'sans'   => "Pas de recherche : MIA ne lit qu'un canal qu'on lui désigne.",
            ],
            [
                'scopes' => self::SCOPES_LIST,
                'usage'  => "Retrouver une conversation par son nom (« #projet-x ») et lister les canaux de "
                    . "l'utilisateur.",
                'outils' => ['lister_canaux_slack'],
                'sans'   => "Un canal ne se désigne plus que par son lien ou son identifiant.",
            ],
            [
                'scopes' => self::SCOPES_HISTORY,
                'usage'  => "Lire les derniers messages d'un canal, dérouler un fil avec toutes ses réponses.",
                'outils' => ['lire_canal_slack', 'lire_fil_slack'],
                'sans'   => "MIA ne voit que les extraits renvoyés par la recherche.",
            ],
            [
                'scopes' => [self::SCOPE_USERS],
                'usage'  => "Écrire le nom des personnes au lieu de leur identifiant Slack, dans tout ce que "
                    . "MIA rapporte.",
                'outils' => [],
                'sans'   => "Les messages rapportés citent des identifiants illisibles (U04F…).",
            ],
            [
                'scopes' => [self::SCOPE_WRITE],
                'usage'  => "Publier un message au nom de l'utilisateur, dans un canal dont il est membre ou "
                    . "dans un fil, après son approbation sur une carte.",
                'outils' => ['envoyer_message_slack'],
                'sans'   => "Lecture seule : MIA rédige le texte, l'utilisateur le publie lui-même.",
            ],
            [
                'scopes' => [self::SCOPE_DIRECT],
                'usage'  => "Écrire à une personne (message direct) plutôt que dans un canal : depuis une "
                    . "conversation, ou une brique Slack d'un workflow.",
                'outils' => [],
                'sans'   => "MIA ne publie que dans des canaux.",
            ],
            [
                'scopes' => [self::SCOPE_FILES],
                'usage'  => "Joindre des fichiers au message publié par une brique Slack d'un workflow : "
                    . "images, documents, tableaux du dossier de l'exécution.",
                'outils' => [],
                'sans'   => "Le message part sans ses pièces jointes : la brique échoue en le disant.",
            ],
            [
                'scopes' => [self::SCOPE_EMAILS],
                'usage'  => "Retrouver la personne à qui écrire par son adresse email — celle d'une Fiche, "
                    . "par exemple.",
                'outils' => [],
                'sans'   => "Une personne ne se désigne que par son nom ou son pseudo Slack.",
            ],
        ];
    }

    // ===================== Lecture =====================

    /**
     * Conversations visibles par l'utilisateur, filtrées sur le nom.
     *
     * Le filtre est appliqué EN PHP : `conversations.list` n'a pas de paramètre
     * de recherche, et la méthode est limitée en débit (une poignée d'appels
     * par minute). On rapatrie donc une fois, on filtre localement, et on
     * garde le résultat pour le reste du tour de chat.
     *
     * @return list<array{id:string,nom:string,prive:bool,membre:bool,sujet:string}>
     */
    public function listChannels(string $query = '', int $limit = 20): array
    {
        $limit  = max(1, min(100, $limit));
        $filtre = self::normaliserNom($query);

        $out = [];
        foreach ($this->tousLesCanaux() as $canal) {
            if ($filtre !== '' && !str_contains(self::normaliserNom($canal['nom']), $filtre)) {
                continue;
            }
            $out[] = $canal;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Recherche transverse dans tout ce que l'utilisateur peut lire (canaux
     * publics, canaux privés dont il est membre, messages directs).
     *
     * La syntaxe native de Slack est acceptée telle quelle (`in:#canal`,
     * `from:@alice`, `during:july`, `has:link`) : la relayer vaut mieux que de
     * la réinventer, et le modèle la connaît.
     *
     * @return list<array{canal:string,canal_id:string,auteur:string,date:string,ts:string,extrait:string,url:string}>
     */
    public function searchMessages(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('Aucun terme de recherche fourni.');
        }
        if (!$this->canSearch()) {
            throw new \RuntimeException(self::MSG_SCOPE_INSUFFICIENT);
        }

        $data = $this->slackGet('search.messages', [
            'query' => $query,
            'count' => (string)max(1, min(20, $limit)),
            'sort'  => 'timestamp',
        ]);

        $out = [];
        foreach ((array)($data['messages']['matches'] ?? []) as $match) {
            if (is_array($match)) {
                $out[] = self::summarizeMatch($match);
            }
        }
        return $out;
    }

    /**
     * Derniers messages d'une conversation, du plus ANCIEN au plus récent.
     *
     * @return array{canal:string,canal_id:string,messages:list<array>,tronque:bool}
     */
    public function readChannel(string $channelRef, int $limit, int $tokenBudget): array
    {
        $canal = $this->resolveChannel($channelRef);
        $data  = $this->slackGet('conversations.history', [
            'channel' => $canal['id'],
            'limit'   => (string)max(1, min(self::HISTORY_MAX, $limit)),
        ]);

        return $this->rendreMessages($canal, (array)($data['messages'] ?? []), $tokenBudget, true);
    }

    /**
     * Un fil complet : le message d'origine et ses réponses, dans l'ordre.
     *
     * @return array{canal:string,canal_id:string,messages:list<array>,tronque:bool}
     */
    public function readThread(string $channelRef, string $ts, int $tokenBudget): array
    {
        $canal = $this->resolveChannel($channelRef);
        $data  = $this->slackGet('conversations.replies', [
            'channel' => $canal['id'],
            'ts'      => self::assertTs($ts),
            'limit'   => (string)self::HISTORY_MAX,
        ]);

        // `conversations.replies` rend DÉJÀ le fil dans l'ordre de lecture :
        // ne pas l'inverser, contrairement à l'historique d'un canal.
        return $this->rendreMessages($canal, (array)($data['messages'] ?? []), $tokenBudget, false);
    }

    /**
     * Les messages d'une conversation DEPUIS une date (brique « Lire » d'un
     * workflow), du plus ANCIEN au plus récent, chaque fil déplié sous son
     * message d'origine. Bornée : PERIOD_MAX messages (les plus récents
     * gardés), PERIOD_THREADS_MAX fils dépliés, le budget de texte.
     *
     * @param array{id:string,nom:string} $canal
     * @return array{canal:string,canal_id:string,messages:list<array>,tronque:bool}
     */
    public function readPeriod(array $canal, int $since, int $tokenBudget): array
    {
        if (!$this->canRead()) {
            throw new \RuntimeException(self::MSG_SCOPE_INSUFFICIENT);
        }
        $raw = [];
        $cursor = '';
        $plus = false;
        for ($page = 0; $page < 5 && count($raw) < self::PERIOD_MAX; $page++) {
            $data = $this->slackGet('conversations.history', [
                'channel' => $canal['id'],
                'oldest'  => (string)max(0, $since),
                'limit'   => '200',
                'cursor'  => $cursor,
            ]);
            foreach ((array)($data['messages'] ?? []) as $m) {
                if (is_array($m)) {
                    $raw[] = $m;
                }
            }
            $cursor = trim((string)($data['response_metadata']['next_cursor'] ?? ''));
            $plus = !empty($data['has_more']);
            if ($cursor === '' || !$plus) {
                break;
            }
        }
        // L'historique arrive du plus RÉCENT au plus ancien : garder le début,
        // c'est garder les plus récents.
        $tronque = $plus || count($raw) > self::PERIOD_MAX;
        $raw = array_reverse(array_slice($raw, 0, self::PERIOD_MAX));

        // Les fils : leurs réponses rangées juste après le message d'origine
        // (les fils les plus récents d'abord dépliés, dans la limite).
        $replies = [];
        $budgetFils = self::PERIOD_THREADS_MAX;
        foreach (array_reverse($raw) as $m) {
            $ts = trim((string)($m['ts'] ?? ''));
            if ((int)($m['reply_count'] ?? 0) <= 0 || $ts === '' || $budgetFils <= 0) {
                continue;
            }
            $budgetFils--;
            try {
                $d = $this->slackGet('conversations.replies', ['channel' => $canal['id'], 'ts' => $ts, 'limit' => (string)self::HISTORY_MAX]);
                $replies[$ts] = array_values(array_filter(
                    (array)($d['messages'] ?? []),
                    static fn($r): bool => is_array($r) && trim((string)($r['ts'] ?? '')) !== $ts
                ));
            } catch (\RuntimeException $e) {
                // Un fil illisible n'empêche pas la lecture : son message reste.
                continue;
            }
        }
        $ordered = [];
        foreach ($raw as $m) {
            $ordered[] = $m;
            foreach ($replies[trim((string)($m['ts'] ?? ''))] ?? [] as $r) {
                $ordered[] = $r + ['_reponse' => true];
            }
        }
        $out = $this->rendreMessages($canal, $ordered, $tokenBudget, false);
        $out['tronque'] = $out['tronque'] || $tronque;
        return $out;
    }

    // ===================== Écriture (après approbation) =====================

    /**
     * Publie un message AU NOM de l'utilisateur, dans un canal ou à une
     * personne (message direct), avec ou sans fichiers joints. Appelée
     * uniquement depuis la file d'approbation (clic « Approuver », ou le
     * moteur d'un workflow réglé « sans validation »).
     *
     * Avec des fichiers, texte et fichiers partent en UN message : les
     * fichiers sont téléversés (`files.getUploadURLExternal` + octets), puis
     * publiés ensemble par `files.completeUploadExternal`, le texte en
     * `initial_comment`. Slack partage les fichiers de façon asynchrone :
     * l'horodatage du message, donc son lien, n'est rendu que s'il est déjà
     * connu — un lien absent n'est pas un échec.
     *
     * @param array{canal?:string,personne?:string,texte?:string,repondre_a?:string} $args
     * @param list<array{name:string,mime:string,bytes:string}> $files fichiers joints (posés par le serveur)
     * @return array{canal:string,canal_id:string,ts:string,url:string,texte:string,fil:bool,compte:string,direct:bool,fichiers:list<string>}
     */
    public function postMessage(array $args, array $files = []): array
    {
        if (!$this->canPost()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        // `assertPostArgs` et NON `normalizePostArgs` : le texte reçu ici a
        // déjà été converti en mrkdwn par le handler (cf. la note de cette
        // dernière). Le reconvertir ABÎMERAIT le message.
        $normalise = self::assertPostArgs($args, $files !== []);
        $files = self::normalizeFiles($files);
        if ($files !== [] && !$this->canAttach()) {
            throw new \RuntimeException(self::MSG_FILES_NOT_GRANTED);
        }
        $direct = $normalise['personne'] !== '';
        if ($direct) {
            $personne = $this->resolvePerson($normalise['personne']);
            $canal = ['id' => $this->openDirect($personne['id']), 'nom' => $personne['nom']];
        } else {
            $canal = $this->resolveChannel($normalise['canal']);
        }

        if ($files === []) {
            $payload = [
                'channel' => $canal['id'],
                'text'    => $normalise['texte'],
                // `mrkdwn` est le défaut, mais l'écrire protège d'un changement de
                // défaut côté Slack : sans lui, le message partirait un jour en
                // texte brut, astérisques comprises.
                'mrkdwn'  => true,
            ];
            if ($normalise['repondre_a'] !== '') {
                $payload['thread_ts'] = $normalise['repondre_a'];
            }

            $data = $this->slackPost('chat.postMessage', $payload);
            $ts   = trim((string)($data['ts'] ?? ''));
            if ($ts === '') {
                // Slack a répondu `ok:true` sans horodatage : rien ne prouve que le
                // message existe, et annoncer un succès serait pire que l'erreur.
                throw new \RuntimeException('Slack n\'a pas confirmé la publication du message.');
            }
        } else {
            $ts = $this->shareFiles($canal['id'], $files, $normalise['texte'], $normalise['repondre_a']);
        }

        error_log(self::LOG_LABEL . ' message publié ' . ($direct ? 'direct' : 'canal') . '=' . $canal['id']
            . ' ts=' . ($ts !== '' ? $ts : '?') . ($files !== [] ? ' fichiers=' . count($files) : '')
            . ($normalise['repondre_a'] !== '' ? ' (dans un fil)' : ''));

        return [
            'canal'    => $canal['nom'],
            'canal_id' => $canal['id'],
            'ts'       => $ts,
            'url'      => $ts !== '' ? $this->permalink($canal['id'], $ts) : '',
            'texte'    => $normalise['texte'],
            'fil'      => $normalise['repondre_a'] !== '',
            'compte'   => $this->accountEmail,
            'direct'   => $direct,
            'fichiers' => array_map(static fn(array $f): string => $f['name'], $files),
        ];
    }

    /**
     * Téléverse les fichiers puis les publie ensemble, avec le texte, dans la
     * conversation. Rend l'horodatage du message s'il est déjà connu, ''
     * sinon (partage asynchrone).
     *
     * @param list<array{name:string,mime:string,bytes:string}> $files
     */
    private function shareFiles(string $channelId, array $files, string $texte, string $threadTs): string
    {
        $ids = [];
        foreach ($files as $f) {
            $up = $this->slackForm('files.getUploadURLExternal', [
                'filename' => $f['name'],
                'length'   => (string)strlen($f['bytes']),
            ]);
            $url = trim((string)($up['upload_url'] ?? ''));
            $id  = trim((string)($up['file_id'] ?? ''));
            if ($url === '' || $id === '') {
                throw new \RuntimeException('Slack n\'a pas préparé l\'envoi du fichier « ' . $f['name'] . ' ».');
            }
            $this->uploadToSlack($url, $f['bytes']);
            $ids[] = ['id' => $id, 'title' => $f['name']];
        }
        $body = ['files' => $ids, 'channel_id' => $channelId];
        if ($texte !== '') {
            $body['initial_comment'] = $texte;
        }
        if ($threadTs !== '') {
            $body['thread_ts'] = $threadTs;
        }
        $data = $this->slackPost('files.completeUploadExternal', $body);
        return self::sharedTs($data, $channelId);
    }

    /**
     * Horodatage du message qui partage les fichiers, s'il figure déjà dans la
     * réponse (`files[].shares.public|private[canal][0].ts`) — '' sinon. Pure.
     */
    public static function sharedTs(array $data, string $channelId): string
    {
        foreach ((array)($data['files'] ?? []) as $f) {
            foreach (['public', 'private'] as $kind) {
                $ts = trim((string)($f['shares'][$kind][$channelId][0]['ts'] ?? ''));
                if (preg_match('/^\d{10}\.\d{6}$/', $ts) === 1) {
                    return $ts;
                }
            }
        }
        return '';
    }

    /**
     * Fichiers joints nettoyés et bornés (FILES_MAX, FILES_MAX_BYTES). Pure.
     *
     * @return list<array{name:string,mime:string,bytes:string}>
     */
    public static function normalizeFiles(array $files): array
    {
        $out = [];
        $total = 0;
        foreach ($files as $f) {
            if (!is_array($f) || !is_string($f['bytes'] ?? null) || $f['bytes'] === '') {
                continue;
            }
            $out[] = [
                'name'  => OutgoingAttachments::cleanName((string)($f['name'] ?? '')),
                'mime'  => OutgoingAttachments::cleanMime((string)($f['mime'] ?? '')),
                'bytes' => $f['bytes'],
            ];
            $total += strlen($f['bytes']);
        }
        if (count($out) > self::FILES_MAX) {
            throw new \RuntimeException(t('{n} fichiers au plus par message Slack.', ['n' => self::FILES_MAX]));
        }
        if ($total > self::FILES_MAX_BYTES) {
            throw new \RuntimeException(t('Fichiers trop lourds : {n} Mo au plus par message Slack.', ['n' => (int)(self::FILES_MAX_BYTES / 1024 / 1024)]));
        }
        return $out;
    }

    // ===================== Personnes =====================

    /**
     * Même recherche que resolvePerson(), SANS lever quand la personne est
     * introuvable : la personne trouvée (null sinon), le nom cherché et les
     * personnes approchantes (8 au plus). Une adresse sans l'autorisation de
     * la chercher lève (MSG_EMAIL_NOT_GRANTED) : c'est un réglage, pas une
     * absence.
     *
     * @return array{personne: ?array{id:string,nom:string}, nom: string, approchants: list<string>}
     */
    public function findPerson(string $ref): array
    {
        [$id, $email, $nom] = self::parsePersonInput($ref);
        if ($id !== '') {
            return ['personne' => ['id' => $id, 'nom' => $this->resoudreNoms([$id])[$id] ?? $id], 'nom' => $id, 'approchants' => []];
        }
        if ($email !== '') {
            if (!$this->canFindByEmail()) {
                throw new \RuntimeException(self::MSG_EMAIL_NOT_GRANTED);
            }
            $data = $this->slackTry('users.lookupByEmail', ['email' => $email], ['users_not_found']);
            $u = is_array($data['user'] ?? null) ? $data['user'] : null;
            if ($u === null || !empty($u['deleted']) || trim((string)($u['id'] ?? '')) === '') {
                return ['personne' => null, 'nom' => $email, 'approchants' => []];
            }
            return ['personne' => ['id' => (string)$u['id'], 'nom' => self::personLabel(self::personRow($u))], 'nom' => $email, 'approchants' => []];
        }
        $m = self::matchPerson($this->toutesLesPersonnes(), $nom);
        return ['personne' => $m['personne'], 'nom' => $nom, 'approchants' => $m['approchants']];
    }

    /**
     * Personne désignée → identifiant et nom. Introuvable ou ambiguë : erreur
     * qui NOMME les personnes approchantes — deviner enverrait le message à
     * quelqu'un d'autre, et un message direct ne se rattrape pas.
     *
     * @return array{id:string,nom:string}
     */
    public function resolvePerson(string $ref): array
    {
        if (trim($ref) === '') {
            throw new \RuntimeException('Aucune personne indiquée.');
        }
        $f = $this->findPerson($ref);
        if ($f['personne'] !== null) {
            return $f['personne'];
        }
        throw new \RuntimeException(
            'Personne Slack « ' . $f['nom'] . ' » introuvable ou ambiguë'
            . ($f['approchants'] !== []
                ? '. Personnes approchantes : ' . implode(', ', $f['approchants']) . '.'
                : ' parmi les membres de l\'espace de travail.')
        );
    }

    /** Conversation directe avec une personne, ouverte pour y publier (im:write). */
    public function openDirect(string $userId): string
    {
        if (!$this->canDirect()) {
            throw new \RuntimeException(self::MSG_DIRECT_NOT_GRANTED);
        }
        $data = $this->slackPost('conversations.open', ['users' => $userId]);
        $id = trim((string)($data['channel']['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Slack n\'a pas ouvert la conversation directe.');
        }
        return $id;
    }

    /**
     * Conversation directe EXISTANTE avec une personne, pour la lire : cherchée
     * parmi les conversations directes de l'utilisateur (im:read), à défaut
     * ouverte (im:write). Null si aucune n'existe et qu'elle ne peut pas l'être.
     */
    public function directWith(string $userId): ?string
    {
        if (self::scopeGranted($this->grantedScope, 'im:read')) {
            $cursor = '';
            for ($page = 0; $page < self::CHANNELS_MAX_PAGES; $page++) {
                $data = $this->slackGet('conversations.list', [
                    'types'  => 'im',
                    'limit'  => (string)self::CHANNELS_PAGE,
                    'cursor' => $cursor,
                ]);
                foreach ((array)($data['channels'] ?? []) as $c) {
                    if (is_array($c) && (string)($c['user'] ?? '') === $userId && trim((string)($c['id'] ?? '')) !== '') {
                        return (string)$c['id'];
                    }
                }
                $cursor = trim((string)($data['response_metadata']['next_cursor'] ?? ''));
                if ($cursor === '') {
                    break;
                }
            }
        }
        return $this->canDirect() ? $this->openDirect($userId) : null;
    }

    /**
     * Membres de l'espace de travail, en cache pour le tour : ni comptes
     * supprimés, ni applications. `users.list` est limitée en débit.
     *
     * @return list<array{id:string,handle:string,nom:string,affiche:string}>
     */
    private function toutesLesPersonnes(): array
    {
        if ($this->cachePersonnes !== []) {
            return $this->cachePersonnes;
        }
        if (!self::scopeGranted($this->grantedScope, self::SCOPE_USERS)) {
            throw new \RuntimeException(self::MSG_SCOPE_INSUFFICIENT . ' (autorisation attendue : ' . self::SCOPE_USERS . ')');
        }
        $out = [];
        $cursor = '';
        for ($page = 0; $page < self::PEOPLE_MAX_PAGES; $page++) {
            $data = $this->slackGet('users.list', ['limit' => (string)self::PEOPLE_PAGE, 'cursor' => $cursor]);
            foreach ((array)($data['members'] ?? []) as $u) {
                if (!is_array($u) || !empty($u['deleted']) || !empty($u['is_bot']) || ($u['id'] ?? '') === 'USLACKBOT') {
                    continue;
                }
                $row = self::personRow($u);
                if ($row['id'] !== '') {
                    $out[] = $row;
                }
            }
            $cursor = trim((string)($data['response_metadata']['next_cursor'] ?? ''));
            if ($cursor === '') {
                break;
            }
        }
        return $this->cachePersonnes = $out;
    }

    // ===================== Résolution =====================

    /**
     * Référence donnée par le modèle → conversation réelle.
     *
     * Quatre formes acceptées, parce que ce sont les quatre qui circulent dans
     * une conversation : l'identifiant (`C04F3KJ1`), le nom avec ou sans `#`,
     * et un lien Slack collé par l'utilisateur. Un nom introuvable lève une
     * erreur qui NOMME les canaux approchants : deviner enverrait le message
     * dans le mauvais canal, et une publication ne se rattrape pas.
     *
     * @return array{id:string,nom:string}
     */
    public function resolveChannel(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \RuntimeException('Aucun canal Slack indiqué.');
        }
        ['canal' => $canal, 'nom' => $nom, 'approchants' => $approchants] = $this->findChannel($ref);
        if ($canal !== null) {
            return $canal;
        }

        throw new \RuntimeException(
            'Canal Slack « ' . $nom . ' » introuvable'
            . ($approchants !== []
                ? '. Canaux approchants : ' . implode(', ', $approchants)
                    . '. Demande lequel plutôt que de choisir.'
                : ' parmi les conversations de l\'utilisateur (MIA ne voit que celles dont il est membre).')
        );
    }

    /**
     * Même recherche que resolveChannel(), SANS lever : rend le canal trouvé
     * (null sinon), le nom cherché et les canaux approchants (8 au plus). Les
     * appelants qui parlent à une PERSONNE (livraison d'un workflow) bâtissent
     * leur propre message — celui de resolveChannel() s'adresse au modèle.
     *
     * @return array{canal: ?array{id:string,nom:string}, nom: string, approchants: list<string>}
     */
    public function findChannel(string $ref): array
    {
        [$id, $nom] = self::parseChannelInput(trim($ref));
        if ($id !== '') {
            // Identifiant explicite : on ne cherche pas son nom si le cache ne
            // l'a pas — un appel de plus par lecture, pour un affichage.
            foreach ($this->cacheCanaux as $canal) {
                if ($canal['id'] === $id) {
                    return ['canal' => ['id' => $id, 'nom' => $canal['nom']], 'nom' => $id, 'approchants' => []];
                }
            }
            return ['canal' => ['id' => $id, 'nom' => $id], 'nom' => $id, 'approchants' => []];
        }

        $cible = self::normaliserNom($nom);
        $approchants = [];
        if ($cible !== '') {
            foreach ($this->tousLesCanaux() as $canal) {
                $courant = self::normaliserNom($canal['nom']);
                if ($courant === $cible) {
                    return ['canal' => ['id' => $canal['id'], 'nom' => $canal['nom']], 'nom' => $nom, 'approchants' => []];
                }
                if (str_contains($courant, $cible)) {
                    $approchants[] = '#' . $canal['nom'];
                }
            }
        }
        return ['canal' => null, 'nom' => $nom, 'approchants' => array_slice($approchants, 0, 8)];
    }

    /**
     * Toutes les conversations visibles, mises en cache pour le tour de chat.
     * `conversations.list` est limitée en débit : la rappeler à chaque outil
     * ferait échouer le second appel d'un même message.
     *
     * @return list<array{id:string,nom:string,prive:bool,membre:bool,sujet:string}>
     */
    private function tousLesCanaux(): array
    {
        if ($this->cacheCanaux !== []) {
            return $this->cacheCanaux;
        }
        if (!$this->canList()) {
            throw new \RuntimeException(self::MSG_SCOPE_INSUFFICIENT);
        }

        $out    = [];
        $cursor = '';
        for ($page = 0; $page < self::CHANNELS_MAX_PAGES; $page++) {
            $data = $this->slackGet('conversations.list', [
                'types'            => 'public_channel,private_channel',
                'exclude_archived' => 'true',
                'limit'            => (string)self::CHANNELS_PAGE,
                'cursor'           => $cursor,
            ]);
            foreach ((array)($data['channels'] ?? []) as $canal) {
                if (!is_array($canal) || trim((string)($canal['id'] ?? '')) === '') {
                    continue;
                }
                $out[] = [
                    'id'     => (string)$canal['id'],
                    'nom'    => (string)($canal['name'] ?? $canal['id']),
                    'prive'  => (bool)($canal['is_private'] ?? false),
                    'membre' => (bool)($canal['is_member'] ?? false),
                    'sujet'  => trim((string)($canal['purpose']['value'] ?? $canal['topic']['value'] ?? '')),
                ];
            }
            $cursor = trim((string)($data['response_metadata']['next_cursor'] ?? ''));
            if ($cursor === '') {
                break;
            }
        }

        usort($out, static fn(array $a, array $b): int => strcasecmp($a['nom'], $b['nom']));
        $this->cacheCanaux = $out;
        return $out;
    }

    /**
     * Noms affichés des identifiants cités sans libellé. Best-effort ABSOLU :
     * une résolution en échec laisse l'identifiant brut, elle ne doit jamais
     * faire échouer la lecture qui l'a déclenchée.
     *
     * @param list<string> $ids
     * @return array<string,string>
     */
    private function resoudreNoms(array $ids): array
    {
        $out = [];
        $reste = self::NAMES_MAX;
        foreach ($ids as $id) {
            if (isset($this->cacheNoms[$id])) {
                $out[$id] = $this->cacheNoms[$id];
                continue;
            }
            if ($reste-- <= 0) {
                break;
            }
            try {
                if ($id[0] === 'C' || $id[0] === 'G' || $id[0] === 'D') {
                    $data = $this->slackGet('conversations.info', ['channel' => $id]);
                    $nom  = (string)($data['channel']['name'] ?? '');
                } else {
                    $data = $this->slackGet('users.info', ['user' => $id]);
                    $nom  = (string)($data['user']['profile']['display_name'] ?? '');
                    if (trim($nom) === '') {
                        $nom = (string)($data['user']['real_name'] ?? $data['user']['name'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
            $nom = trim($nom);
            if ($nom !== '') {
                $this->cacheNoms[$id] = $nom;
                $out[$id] = $nom;
            }
        }
        return $out;
    }

    /** Lien permanent d'un message. Best-effort : un lien absent n'est pas un échec. */
    private function permalink(string $channelId, string $ts): string
    {
        try {
            $data = $this->slackGet('chat.getPermalink', ['channel' => $channelId, 'message_ts' => $ts]);
            return (string)($data['permalink'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Messages bruts → messages lisibles, bornés au budget.
     *
     * @param array{id:string,nom:string} $canal
     * @return array{canal:string,canal_id:string,messages:list<array>,tronque:bool}
     */
    private function rendreMessages(array $canal, array $messages, int $tokenBudget, bool $inverser): array
    {
        $messages = array_values(array_filter($messages, static fn($m): bool => is_array($m) && !self::estBruit($m)));
        if ($inverser) {
            // L'historique d'un canal arrive du plus récent au plus ancien :
            // sans cette inversion, « résume la journée » se lit à l'envers.
            $messages = array_reverse($messages);
        }

        // Identifiants cités sans libellé, résolus EN UNE PASSE pour tout le lot.
        $aResoudre = [];
        foreach ($messages as $m) {
            foreach (SlackMrkdwn::unresolvedIds((string)($m['text'] ?? '')) as $id) {
                $aResoudre[$id] = true;
            }
            $auteur = trim((string)($m['user'] ?? ''));
            if ($auteur !== '') {
                $aResoudre[$auteur] = true;
            }
        }
        $noms = $this->resoudreNoms(array_keys($aResoudre));

        $maxChars = max(1000, $tokenBudget * 4);
        $utilises = 0;
        $out      = [];
        $tronque  = false;
        foreach ($messages as $m) {
            $texte = SlackMrkdwn::toText((string)($m['text'] ?? ''), $noms);
            if ($texte === '') {
                continue;
            }
            if ($utilises + mb_strlen($texte) > $maxChars) {
                $tronque = true;
                break;
            }
            $utilises += mb_strlen($texte);

            $auteurId = trim((string)($m['user'] ?? ''));
            $ts       = trim((string)($m['ts'] ?? ''));
            $out[] = [
                // `ts` est l'identifiant du message ET sa clé de tri : il reste
                // une CHAÎNE (cf. l'en-tête de cette classe).
                'ts'       => $ts,
                'date'     => self::formatTs($ts),
                'auteur'   => $auteurId !== ''
                    ? (string)($noms[$auteurId] ?? $auteurId)
                    : trim((string)($m['username'] ?? $m['bot_id'] ?? '')),
                'texte'    => $texte,
                'fil'      => trim((string)($m['thread_ts'] ?? '')) !== ''
                    && (string)($m['thread_ts'] ?? '') !== $ts,
                'reponses' => (int)($m['reply_count'] ?? 0),
            ] + (!empty($m['_reponse']) ? ['reponse' => true] : []);
        }

        return [
            'canal'    => $canal['nom'],
            'canal_id' => $canal['id'],
            'messages' => $out,
            'tronque'  => $tronque,
        ];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Référence de canal → [identifiant, nom]. L'un des deux est vide.
     *
     * Le lien Slack est la forme la plus fréquente (on colle ce qu'on a sous la
     * main) et la plus trompeuse : `…/archives/C04F3KJ1/p1720000000123456`
     * contient l'identifiant du canal ET l'horodatage du message, mais ce
     * dernier y est écrit SANS son point. Le rendre tel quel désignerait un
     * message inexistant — c'est `tsFromPermalink()` qui le remet en forme.
     *
     * @return array{0:string,1:string} [id, nom]
     */
    public static function parseChannelInput(string $ref): array
    {
        $ref = trim($ref);

        if (preg_match('#/archives/([A-Z][A-Z0-9]{2,})#', $ref, $m) === 1) {
            return [$m[1], ''];
        }
        // Un identifiant Slack commence par une lettre majuscule de type
        // (C canal, G groupe historique, D message direct) suivie de
        // majuscules et de chiffres — jamais de minuscule, contrairement à un
        // nom de canal, qui est toujours en minuscules.
        if (preg_match('/^[CGD][A-Z0-9]{2,}$/', $ref) === 1) {
            return [$ref, ''];
        }
        return ['', ltrim($ref, '#')];
    }

    /**
     * Référence de personne → [identifiant, adresse, nom]. Un seul des trois
     * est rempli. Formes reconnues : la mention Slack (`<@U04F3KJ1>`, avec ou
     * sans libellé), l'identifiant (`U…`, `W…` : majuscules ET chiffres),
     * l'adresse email (nue, ou « Marie Dupont <marie@exemple.fr> »), sinon un
     * nom ou un pseudo (`@marie`).
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function parsePersonInput(string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('/^<@([UW][A-Z0-9]{2,})(?:\|[^>]*)?>$/', $ref, $m) === 1) {
            return [$m[1], '', ''];
        }
        if (preg_match('/^[UW](?=[A-Z0-9]*\d)[A-Z0-9]{6,}$/', $ref) === 1) {
            return [$ref, '', ''];
        }
        if (filter_var($ref, FILTER_VALIDATE_EMAIL)) {
            return ['', mb_strtolower($ref), ''];
        }
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $ref, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_EMAIL)) {
            return ['', mb_strtolower($m[1]), ''];
        }
        return ['', '', trim(ltrim($ref, '@'))];
    }

    /**
     * Une personne parmi les membres, par son nom : correspondance EXACTE (le
     * pseudo, le nom complet ou le nom affiché, sans casse ni accents) et
     * UNIQUE ; sinon aucune, avec les personnes approchantes — deux homonymes
     * exacts sont rendus comme approchants, jamais départagés. Pure.
     *
     * @param list<array{id:string,handle:string,nom:string,affiche:string}> $people
     * @return array{personne: ?array{id:string,nom:string}, approchants: list<string>}
     */
    public static function matchPerson(array $people, string $name): array
    {
        $cible = self::normaliserNom($name);
        if ($cible === '') {
            return ['personne' => null, 'approchants' => []];
        }
        $exact = [];
        $proches = [];
        foreach ($people as $p) {
            $noms = array_values(array_filter(array_map(
                static fn(string $n): string => self::normaliserNom($n),
                [(string)($p['handle'] ?? ''), (string)($p['nom'] ?? ''), (string)($p['affiche'] ?? '')]
            )));
            if (in_array($cible, $noms, true)) {
                $exact[] = $p;
                continue;
            }
            foreach ($noms as $n) {
                if (str_contains($n, $cible)) {
                    $proches[] = $p;
                    break;
                }
            }
        }
        if (count($exact) === 1) {
            return ['personne' => ['id' => (string)$exact[0]['id'], 'nom' => self::personLabel($exact[0])], 'approchants' => []];
        }
        $liste = array_values(array_unique(array_map(
            static fn(array $p): string => self::personLabel($p) . ($p['handle'] !== '' ? ' (@' . $p['handle'] . ')' : ''),
            count($exact) > 1 ? $exact : $proches
        )));
        return ['personne' => null, 'approchants' => array_slice($liste, 0, 8)];
    }

    /**
     * Personne RÉSOLUE, désignée sans ambiguïté sur la carte d'approbation :
     * la mention Slack porteuse de son nom (`<@U04F3KJ1|Marie Dupont>`) —
     * l'identifiant fait foi à l'exécution, le nom se lit. Pure.
     */
    public static function personRef(string $id, string $nom): string
    {
        $nom = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[<>|]+/u', ' ', $nom)));
        return '<@' . $id . ($nom !== '' && $nom !== $id ? '|' . $nom : '') . '>';
    }

    /** Ce qu'une référence de personne montre : le nom d'une mention résolue, sinon elle-même. Pure. */
    public static function personDisplay(string $ref): string
    {
        if (preg_match('/^<@[UW][A-Z0-9]{2,}\|([^>]+)>$/', trim($ref), $m) === 1) {
            return trim($m[1]);
        }
        return trim($ref);
    }

    /** Membre Slack brut → ligne comparable. Pure. @return array{id:string,handle:string,nom:string,affiche:string} */
    public static function personRow(array $u): array
    {
        return [
            'id'      => trim((string)($u['id'] ?? '')),
            'handle'  => trim((string)($u['name'] ?? '')),
            'nom'     => trim((string)($u['real_name'] ?? $u['profile']['real_name'] ?? '')),
            'affiche' => trim((string)($u['profile']['display_name'] ?? '')),
        ];
    }

    /** Nom à montrer d'une personne : son nom complet, sinon affiché, sinon son pseudo. Pure. */
    public static function personLabel(array $p): string
    {
        foreach (['nom', 'affiche', 'handle'] as $k) {
            $v = trim((string)($p[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return (string)($p['id'] ?? '');
    }

    /**
     * La référence PEUT-ELLE désigner un canal ? Un nom de canal Slack ne
     * contient jamais d'espace : « Canal Slack », « le canal de l'équipe »
     * sont des descriptions, pas des canaux — aucune recherche ne les
     * trouvera. Pure : sert à le dire AVANT toute exécution (« À régler »
     * d'un workflow), sans appel à Slack. Un identifiant ou un lien passe.
     */
    public static function isPlausibleChannelRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }
        [$id, $nom] = self::parseChannelInput($ref);
        return $id !== '' || ($nom !== '' && preg_match('/\s/u', $nom) !== 1);
    }

    /**
     * Horodatage d'un lien permanent Slack (`…/p1720000000123456`) → `ts`
     * utilisable (`1720000000.123456`). Chaîne vide si le lien n'en porte pas.
     *
     * Le point manquant est tout le piège : Slack le retire dans ses URL, et
     * l'identifiant sans point ne désigne aucun message.
     */
    public static function tsFromPermalink(string $url): string
    {
        if (preg_match('#/p(\d{16,})#', trim($url), $m) !== 1) {
            return '';
        }
        return substr($m[1], 0, -6) . '.' . substr($m[1], -6);
    }

    /**
     * Horodatage Slack valide, rendu tel quel. Throw sinon : un `ts` inventé
     * par le modèle partirait en requête et rendrait un refus illisible plutôt
     * qu'une consigne claire.
     *
     * Un lien permanent est accepté et converti — c'est ce que l'utilisateur
     * colle, et le refuser obligerait le modèle à une gymnastique inutile.
     */
    public static function assertTs(string $ts): string
    {
        $ts = trim($ts);
        if (str_contains($ts, '/')) {
            $ts = self::tsFromPermalink($ts);
        }
        if (preg_match('/^\d{10}\.\d{6}$/', $ts) !== 1) {
            throw new \RuntimeException(
                'Identifiant de message Slack invalide (forme attendue : 1720000000.123456) — '
                . 'utilise rechercher_slack ou lire_canal_slack pour l\'obtenir.'
            );
        }
        return $ts;
    }

    /**
     * `ts` Slack → « 15/01/2026 09:32 » dans le fuseau du serveur.
     * La partie décimale est un compteur d'unicité, pas une précision : seule
     * la partie entière est une date.
     */
    public static function formatTs(string $ts): string
    {
        $ts = trim($ts);
        if ($ts === '' || preg_match('/^(\d{10})/', $ts, $m) !== 1) {
            return '';
        }
        return date('d/m/Y H:i', (int)$m[1]);
    }

    /**
     * Résultat de `search.messages` → ligne lisible. Les libellés des mentions
     * sont déjà présents dans les résultats de recherche (Slack les y ajoute),
     * d'où l'absence de résolution ici.
     *
     * @return array{canal:string,canal_id:string,auteur:string,date:string,ts:string,extrait:string,url:string}
     */
    public static function summarizeMatch(array $match): array
    {
        $ts = trim((string)($match['ts'] ?? ''));
        return [
            'canal'    => (string)($match['channel']['name'] ?? ''),
            'canal_id' => (string)($match['channel']['id'] ?? ''),
            'auteur'   => trim((string)($match['username'] ?? $match['user'] ?? '')),
            'date'     => self::formatTs($ts),
            'ts'       => $ts,
            'extrait'  => SlackMrkdwn::toText((string)($match['text'] ?? '')),
            'url'      => (string)($match['permalink'] ?? ''),
        ];
    }

    /**
     * Arguments de publication normalisés et VALIDÉS avant tout appel réseau —
     * et avant la carte d'approbation : une erreur revient ainsi au modèle
     * pendant qu'il rédige, jamais dans une carte cassée que l'utilisateur
     * approuverait pour rien.
     *
     * Le texte est converti en mrkdwn ICI, une SEULE fois : la carte
     * d'approbation doit montrer EXACTEMENT ce qui sera publié, pas le Markdown
     * d'origine.
     *
     * ⚠ CONVERTIR DEUX FOIS ABÎME LE MESSAGE, en silence. Repasser du mrkdwn
     * dans `toMrkdwn` transforme le gras `*titre*` en italique `_titre_` (la
     * règle d'italique du Markdown reconnaît l'astérisque simple) et ÉCHAPPE les
     * chevrons de nos propres liens : `<url|libellé>` deviendrait
     * `&lt;url|libellé&gt;`, publié en toutes lettres. D'où la séparation :
     * cette méthode convertit (handler), `assertPostArgs` se contente de
     * valider (exécution, au clic « Approuver »).
     *
     * `$hasFiles` : des fichiers partent avec le message (brique d'un
     * workflow) — le texte devient alors facultatif.
     *
     * @return array{canal:string,personne:string,texte:string,repondre_a:string}
     */
    public static function normalizePostArgs(array $args, bool $hasFiles = false): array
    {
        $args['texte'] = SlackMrkdwn::toMrkdwn((string)($args['texte'] ?? ''));
        return self::assertPostArgs($args, $hasFiles);
    }

    /**
     * Mêmes contrôles, SANS conversion : le texte est déjà du mrkdwn. Appelée
     * à l'exécution, où les arguments viennent de la carte approuvée.
     * Destination : un canal OU une personne (message direct) — la personne,
     * si elle est indiquée, l'emporte.
     *
     * @return array{canal:string,personne:string,texte:string,repondre_a:string}
     */
    public static function assertPostArgs(array $args, bool $hasFiles = false): array
    {
        $personne = trim((string)($args['personne'] ?? ''));
        $canal = $personne !== '' ? '' : trim((string)($args['canal'] ?? ''));
        if ($canal === '' && $personne === '') {
            throw new \RuntimeException('Aucun canal Slack (ni aucune personne) indiqué pour la publication.');
        }
        $texte = trim((string)($args['texte'] ?? ''));
        if ($texte === '' && !$hasFiles) {
            throw new \RuntimeException('Le message à publier est vide.');
        }
        if (mb_strlen($texte) > SlackMrkdwn::MAX_MESSAGE_LEN) {
            throw new \RuntimeException('Message trop long pour Slack : raccourcis-le (limite ≈ 40 000 caractères).');
        }
        $fil = trim((string)($args['repondre_a'] ?? ''));

        return [
            'canal'      => $canal,
            'personne'   => $personne,
            'texte'      => $texte,
            'repondre_a' => $fil !== '' ? self::assertTs($fil) : '',
        ];
    }

    /**
     * Message de service (arrivée, départ, épinglage…) : du bruit pour un
     * résumé de conversation. Un message d'application (`bot_id`) est GARDÉ :
     * les notifications de déploiement ou d'astreinte sont souvent ce qu'on
     * vient chercher.
     */
    public static function estBruit(array $message): bool
    {
        $subtype = (string)($message['subtype'] ?? '');
        return in_array($subtype, [
            'channel_join', 'channel_leave', 'group_join', 'group_leave',
            'channel_topic', 'channel_purpose', 'channel_name', 'channel_archive',
            'channel_unarchive', 'pinned_item', 'unpinned_item',
        ], true);
    }

    /** Minuscules sans accents ni `#` — comparaison de noms de canaux. */
    public static function normaliserNom(string $nom): string
    {
        $nom = mb_strtolower(trim(ltrim(trim($nom), '#')));
        return strtr($nom, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }

    /**
     * Aperçu HUMAIN de la publication, pour la carte d'approbation (mêmes clés
     * que `UserDriveService::actionPreview` + `icone`).
     *
     * ⚠ Le premier impact est le plus important de tout le connecteur : à la
     * différence d'un brouillon Gmail ou Outlook, ce message part RÉELLEMENT et
     * devient visible de tous les membres du canal. L'écrire en toutes lettres
     * est ce qui fait la différence entre une approbation éclairée et un clic
     * machinal.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args): array
    {
        $personne = self::personDisplay((string)($args['personne'] ?? ''));
        $canal = trim((string)($args['canal'] ?? ''));
        $canal = $canal !== '' && !str_starts_with($canal, '#') && !str_starts_with($canal, 'http')
            && preg_match('/^[CGD][A-Z0-9]{2,}$/', $canal) !== 1
            ? '#' . ltrim($canal, '#')
            : $canal;
        $texte = trim((string)($args['texte'] ?? ''));
        $fil   = trim((string)($args['repondre_a'] ?? '')) !== '';
        $apercu = $texte === '' ? null : (mb_strlen($texte) > 280 ? mb_substr($texte, 0, 280) . '…' : $texte);
        // Fichiers joints par un workflow : nommés sur la carte (posés par le serveur).
        $fichiers = [];
        foreach ((array)($args[WorkflowDeliveries::ATTACH_KEY] ?? []) as $r) {
            if (is_array($r) && trim((string)($r['name'] ?? '')) !== '') {
                $fichiers[] = '« ' . trim((string)$r['name']) . ' »';
            }
        }

        return [
            'action'  => $personne !== ''
                ? t('Envoyer un message direct Slack')
                : ($fil ? t('Répondre dans un fil Slack') : t('Publier un message Slack')),
            'fichier' => $personne !== '' ? $personne : ($canal !== '' ? $canal : t('(canal non précisé)')),
            'url'     => null,
            'impacts' => array_values(array_filter([
                $personne !== ''
                    ? t('Le message sera envoyé EN VOTRE NOM à {personne}, qui le verra immédiatement.', ['personne' => $personne])
                    : t('Le message sera publié EN VOTRE NOM et visible immédiatement par les membres du canal.'),
                $fil ? t('Il sera rattaché au fil du message d\'origine.') : null,
                $fichiers !== [] ? t('Fichiers joints : {fichiers}.', ['fichiers' => implode(', ', $fichiers)]) : null,
                t('Contrairement à un brouillon d\'email, rien ne sera mis en attente : la publication est immédiate.'),
                t('MIA ne peut ni modifier ni supprimer un message publié — vous le ferez depuis Slack si besoin.'),
            ])),
            'apercu'  => $apercu,
            'icone'   => '/assets/img/slack.svg',
        ];
    }
}
