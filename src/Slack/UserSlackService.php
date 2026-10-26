<?php

declare(strict_types=1);

/**
 * Accès à l'espace de travail SLACK d'un utilisateur (OAuth Slack par
 * utilisateur, jetons `UserDriveToken` provider 'slack') : recherche et
 * lecture de messages, publication d'un message APRÈS APPROBATION.
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
 *  - **Aucun nouveau message direct.** Ouvrir une conversation privée demande
 *    `im:write`, qui n'est pas demandé. MIA lit les messages directs existants
 *    (ils font partie de ce que la personne voit) mais ne peut pas en ouvrir.
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

    /** Lister les conversations : une famille de scopes, un par type. */
    public const SCOPES_LIST = ['channels:read', 'groups:read', 'im:read', 'mpim:read'];

    /** Lire les messages : même découpage par type de conversation. */
    public const SCOPES_HISTORY = ['channels:history', 'groups:history', 'im:history', 'mpim:history'];

    public const MSG_RECONNECT = 'Connexion Slack expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son Slack dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de publication Slack non accordée. '
        . 'L\'utilisateur doit reconnecter son Slack dans Réglages → Comptes connectés.';

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

    /** Cache d'instance : un tour de chat lit souvent le même canal deux fois. */
    private array $cacheCanaux = [];
    private array $cacheNoms   = [];

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

    // ===================== Écriture (après approbation) =====================

    /**
     * Publie un message AU NOM de l'utilisateur. Appelée uniquement depuis la
     * file d'approbation (clic « Approuver »).
     *
     * @param array{canal?:string,texte?:string,repondre_a?:string} $args
     * @return array{canal:string,canal_id:string,ts:string,url:string,texte:string,fil:bool,compte:string}
     */
    public function postMessage(array $args): array
    {
        if (!$this->canPost()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        // `assertPostArgs` et NON `normalizePostArgs` : le texte reçu ici a
        // déjà été converti en mrkdwn par le handler (cf. la note de cette
        // dernière). Le reconvertir ABÎMERAIT le message.
        $normalise = self::assertPostArgs($args);
        $canal     = $this->resolveChannel($normalise['canal']);

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

        error_log(self::LOG_LABEL . ' message publié canal=' . $canal['id'] . ' ts=' . $ts
            . ($normalise['repondre_a'] !== '' ? ' (dans un fil)' : ''));

        return [
            'canal'    => $canal['nom'],
            'canal_id' => $canal['id'],
            'ts'       => $ts,
            'url'      => $this->permalink($canal['id'], $ts),
            'texte'    => $normalise['texte'],
            'fil'      => $normalise['repondre_a'] !== '',
            'compte'   => $this->accountEmail,
        ];
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
        [$id, $nom] = self::parseChannelInput($ref);
        if ($id !== '') {
            // Identifiant explicite : on ne cherche pas son nom si le cache ne
            // l'a pas — un appel de plus par lecture, pour un affichage.
            foreach ($this->cacheCanaux as $canal) {
                if ($canal['id'] === $id) {
                    return ['id' => $id, 'nom' => $canal['nom']];
                }
            }
            return ['id' => $id, 'nom' => $id];
        }

        $cible = self::normaliserNom($nom);
        $approchants = [];
        foreach ($this->tousLesCanaux() as $canal) {
            $courant = self::normaliserNom($canal['nom']);
            if ($courant === $cible) {
                return ['id' => $canal['id'], 'nom' => $canal['nom']];
            }
            if (str_contains($courant, $cible)) {
                $approchants[] = '#' . $canal['nom'];
            }
        }

        throw new \RuntimeException(
            'Canal Slack « ' . $nom . ' » introuvable'
            . ($approchants !== []
                ? '. Canaux approchants : ' . implode(', ', array_slice($approchants, 0, 8))
                    . '. Demande lequel plutôt que de choisir.'
                : ' parmi les conversations de l\'utilisateur (MIA ne voit que celles dont il est membre).')
        );
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
            ];
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
     * @return array{canal:string,texte:string,repondre_a:string}
     */
    public static function normalizePostArgs(array $args): array
    {
        $args['texte'] = SlackMrkdwn::toMrkdwn((string)($args['texte'] ?? ''));
        return self::assertPostArgs($args);
    }

    /**
     * Mêmes contrôles, SANS conversion : le texte est déjà du mrkdwn. Appelée
     * à l'exécution, où les arguments viennent de la carte approuvée.
     *
     * @return array{canal:string,texte:string,repondre_a:string}
     */
    public static function assertPostArgs(array $args): array
    {
        $canal = trim((string)($args['canal'] ?? ''));
        if ($canal === '') {
            throw new \RuntimeException('Aucun canal Slack indiqué pour la publication.');
        }
        $texte = trim((string)($args['texte'] ?? ''));
        if ($texte === '') {
            throw new \RuntimeException('Le message à publier est vide.');
        }
        if (mb_strlen($texte) > SlackMrkdwn::MAX_MESSAGE_LEN) {
            throw new \RuntimeException('Message trop long pour Slack : raccourcis-le (limite ≈ 40 000 caractères).');
        }
        $fil = trim((string)($args['repondre_a'] ?? ''));

        return [
            'canal'      => $canal,
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
        $canal = trim((string)($args['canal'] ?? ''));
        $canal = $canal !== '' && !str_starts_with($canal, '#') && !str_starts_with($canal, 'http')
            ? '#' . ltrim($canal, '#')
            : $canal;
        $texte = trim((string)($args['texte'] ?? ''));
        $fil   = trim((string)($args['repondre_a'] ?? '')) !== '';
        $apercu = $texte === '' ? null : (mb_strlen($texte) > 280 ? mb_substr($texte, 0, 280) . '…' : $texte);

        return [
            'action'  => $fil ? t('Répondre dans un fil Slack') : t('Publier un message Slack'),
            'fichier' => $canal !== '' ? $canal : t('(canal non précisé)'),
            'url'     => null,
            'impacts' => array_values(array_filter([
                t('Le message sera publié EN VOTRE NOM et visible immédiatement par les membres du canal.'),
                $fil ? t('Il sera rattaché au fil du message d\'origine.') : null,
                t('Contrairement à un brouillon d\'email, rien ne sera mis en attente : la publication est immédiate.'),
                t('MIA ne peut ni modifier ni supprimer un message publié — vous le ferez depuis Slack si besoin.'),
            ])),
            'apercu'  => $apercu,
            'icone'   => '/assets/img/slack.svg',
        ];
    }
}
