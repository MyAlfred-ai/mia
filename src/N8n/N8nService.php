<?php

declare(strict_types=1);

/**
 * Outil n8n — les workflows d'automatisation de l'organisation exposés à un
 * assistant comme des outils function-calling.
 *
 * C'EST UN OUTIL, PAS UN CONNECTEUR. Un connecteur (Drive, Gmail, Agenda)
 * branche les données PERSONNELLES de l'utilisateur, qui relie son compte et
 * décide message par message. n8n est au contraire une capacité que
 * l'administrateur confie à un assistant : une ligne de `chat_tools` (type
 * `n8n` ; URL, tag et en-tête dans `config_json`, clé API dans `api_key_enc`),
 * liée aux assistants autorisés à la déclencher et soumise au périmètre de
 * groupe comme tout autre outil. L'utilisateur n'a rien à cocher : parler à
 * l'assistant suffit.
 *
 * PÉRIMÈTRE = UN TAG n8n, PAS UNE LISTE DUPLIQUÉE. Seuls les workflows
 * **actifs** portant le tag configuré (défaut `mia`) sont visibles et
 * déclenchables. L'allowlist vit donc dans n8n, là où vivent les workflows :
 * aucune liste à tenir à jour des deux côtés, et retirer un tag suffit à
 * couper l'accès immédiatement.
 *
 * EXÉCUTION = WEBHOOK. L'API publique n8n (`/api/v1`) sait lister et décrire
 * un workflow mais **ne sait pas le lancer** (aucun endpoint d'exécution).
 * Le déclenchement passe donc par le nœud **Webhook** du workflow, dont on lit
 * chemin et méthode depuis la définition récupérée par l'API — l'administrateur
 * n'a aucune URL à recopier.
 *
 * SÉCURITÉ. Un workflow peut faire n'importe quoi (écrire dans un CRM, envoyer
 * un message…) : toute exécution passe donc par la file d'approbation humaine
 * (même mécanique que les écritures Drive / Gmail / Agenda). Seule exception,
 * désactivée par défaut : les workflows portant EN PLUS le tag de lecture
 * (`<tag>-lecture`) peuvent être exécutés directement si l'administrateur
 * l'autorise. La clé API n'est JAMAIS envoyée au webhook (endpoint public,
 * clé d'administration) — un webhook protégé utilise l'en-tête partagé
 * configuré à part.
 */
final class N8nService
{
    /** Type de la ligne `chat_tools` qui porte une instance n8n. */
    public const TOOL_TYPE = 'n8n';

    /** Fournisseur/modèle stockés sur la ligne (colonnes NOT NULL, sans LLM ici). */
    public const TOOL_PROVIDER = 'n8n';
    public const TOOL_MODEL    = 'webhook';

    /** Tag n8n qui expose un workflow au chat, si l'admin n'en a pas choisi d'autre. */
    public const DEFAULT_TAG = 'mia';

    /** Suffixe du tag « lecture seule » : `mia` → `mia-lecture`. */
    public const READ_SUFFIX = '-lecture';

    /** Types de nœuds déclencheurs sur lesquels on sait taper. */
    public const WEBHOOK_NODE = 'n8n-nodes-base.webhook';

    /** Garde-fous : jamais plus que ça, quoi que demande le modèle. */
    public const MAX_RESULTS      = 25;
    public const MAX_SCAN         = 100;
    public const MAX_OUTPUT_CHARS = 6000;
    public const MAX_NOTES_CHARS  = 1200;
    public const SHORT_NOTES      = 220;

    private const API_TIMEOUT = 20;
    private const RUN_TIMEOUT = 120;

    /** Workflows exposés, résolus une seule fois par instance. */
    private ?array $exposed = null;

    private function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $tag,
        private readonly string $authHeaderName = '',
        private readonly string $authHeaderValue = '',
        private readonly bool $autoRead = false
    ) {
    }

    // ===================== Configuration (portée par l'outil) =====================

    /**
     * Instance prête à l'emploi depuis une ligne `chat_tools`, ou null si
     * l'outil est incomplet (URL ou clé API manquante) — le chat ne doit alors
     * rien exposer plutôt que d'échouer à la première question.
     *
     * @param array<string,mixed>|null $tool ligne chat_tools
     */
    public static function fromTool(?array $tool): ?self
    {
        if (!$tool || (string)($tool['type'] ?? '') !== self::TOOL_TYPE) {
            return null;
        }
        $config  = self::decodeToolConfig($tool);
        $baseUrl = self::normalizeBaseUrl((string)($config['base_url'] ?? ''));
        // La clé voyage dans `X-N8N-API-KEY` : nettoyée des CR/LF comme toute
        // valeur d'en-tête, une clé mal collée ne doit pas pouvoir en ajouter.
        $apiKey  = self::sanitizeHeaderValue(self::decryptOrEmpty((string)($tool['api_key_enc'] ?? '')));
        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        $headerName  = self::sanitizeHeaderName((string)($config['webhook_header_name'] ?? ''));
        $headerValue = self::sanitizeHeaderValue(self::decryptOrEmpty((string)($config['webhook_secret_enc'] ?? '')));
        if ($headerName === '' || $headerValue === '') {
            // Un nom sans valeur (ou l'inverse) n'authentifie rien : on n'envoie
            // pas d'en-tête à moitié plutôt que d'en envoyer un vide.
            $headerName = $headerValue = '';
        }

        return new self(
            $baseUrl,
            $apiKey,
            self::sanitizeTag((string)($config['tag'] ?? '')),
            $headerName,
            $headerValue,
            !empty($config['auto_read'])
        );
    }

    /** `config_json` d'un outil n8n → tableau (jamais null). */
    public static function decodeToolConfig(?array $tool): array
    {
        $raw = json_decode((string)($tool['config_json'] ?? ''), true);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Formulaire d'administration → `config_json` de l'outil.
     * Le secret du webhook est chiffré ici (config_json est stocké en clair) ;
     * laissé vide, l'ancien est conservé — même règle que partout dans l'app.
     *
     * @param array<string,mixed> $post    champs du formulaire
     * @param array<string,mixed> $ancien  config actuelle (édition)
     */
    public static function configFromPost(array $post, array $ancien = []): array
    {
        $config = [
            'base_url'            => self::normalizeBaseUrl((string)($post['config_n8n_base_url'] ?? '')),
            'tag'                 => self::sanitizeTag((string)($post['config_n8n_tag'] ?? '')),
            'webhook_header_name' => self::sanitizeHeaderName((string)($post['config_n8n_header_name'] ?? '')),
            'auto_read'           => !empty($post['config_n8n_auto_read']),
        ];

        $secret = self::sanitizeHeaderValue((string)($post['config_n8n_header_value'] ?? ''));
        if (!empty($post['config_n8n_header_clear'])) {
            $config['webhook_secret_enc'] = '';
        } elseif ($secret !== '') {
            $config['webhook_secret_enc'] = Encryption::encrypt($secret);
        } elseif (!empty($ancien['webhook_secret_enc'])) {
            $config['webhook_secret_enc'] = (string)$ancien['webhook_secret_enc'];
        }
        return $config;
    }

    /** Tag qui marque un workflow comme sûr à exécuter sans approbation. */
    public function readTag(): string
    {
        return $this->tag . self::READ_SUFFIX;
    }

    /** Déchiffre une valeur stockée, sans jamais lever. */
    private static function decryptOrEmpty(string $enc): string
    {
        return $enc === '' ? '' : trim(Encryption::decrypt($enc));
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * URL d'instance saisie par un humain → base propre, ou '' si inexploitable.
     *
     * Tolère l'absence de schéma (`n8n.exemple.fr`), la barre finale et le
     * copier-coller du chemin de l'API (`…/api/v1`) ou de l'interface
     * (`…/home/workflows`) — trois erreurs de saisie qui produiraient sinon des
     * 404 incompréhensibles. Rejette tout schéma autre que http/https.
     */
    public static function normalizeBaseUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        // Chemins que l'on retrouve dans un copier-coller de barre d'adresse.
        foreach (['/api/v1', '/api', '/home/workflows', '/home', '/workflow', '/workflows', '/signin'] as $suffix) {
            if (strtolower(substr($path, -strlen($suffix))) === $suffix) {
                $path = substr($path, 0, -strlen($suffix));
                break;
            }
        }
        return $scheme . '://' . strtolower($host) . $port . rtrim($path, '/');
    }

    /** Tag saisi par un humain → tag n8n comparable (minuscules, sans fioritures). */
    public static function sanitizeTag(string $raw): string
    {
        $tag = strtolower(trim($raw));
        $tag = (string)preg_replace('/[^a-z0-9 _.-]+/u', '', $tag);
        $tag = trim($tag);
        return $tag !== '' ? mb_substr($tag, 0, 40) : self::DEFAULT_TAG;
    }

    /**
     * Nom d'en-tête HTTP → jeton RFC 7230 valide, ou '' si inexploitable.
     * Filet anti-injection : un nom porteur de CR/LF permettrait d'ajouter des
     * en-têtes arbitraires à la requête sortante.
     */
    public static function sanitizeHeaderName(string $raw): string
    {
        $name = trim($raw);
        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/', $name) === 1 ? $name : '';
    }

    /** Valeur d'en-tête → sans CR/LF/NUL (même raison). */
    public static function sanitizeHeaderValue(string $raw): string
    {
        return trim((string)preg_replace('/[\r\n\x00]+/', '', $raw));
    }

    /** @return array<int,string> noms des tags d'un workflow, en minuscules */
    public static function tagNames(array $wf): array
    {
        $out = [];
        foreach ((array)($wf['tags'] ?? []) as $t) {
            $name = is_array($t) ? (string)($t['name'] ?? '') : (string)$t;
            $name = strtolower(trim($name));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Workflow visible par le chat : actif ET porteur du tag d'exposition. */
    public static function isExposed(array $wf, string $tag): bool
    {
        return !empty($wf['active']) && in_array(strtolower($tag), self::tagNames($wf), true);
    }

    /** Workflow déclaré sans effet de bord par son tag `<tag>-lecture`. */
    public static function isReadOnly(array $wf, string $tag): bool
    {
        return in_array(strtolower($tag) . self::READ_SUFFIX, self::tagNames($wf), true);
    }

    /**
     * Nœud Webhook du workflow → de quoi construire l'appel.
     *
     * Renvoie null quand le workflow n'a pas de déclencheur Webhook actif : il
     * est alors listé mais non déclenchable (un déclencheur Cron ou Formulaire
     * n'est pas appelable depuis le chat), et on le dit au modèle plutôt que
     * de le laisser inventer une URL.
     *
     * @return array{method:string,path:string,notes:string,response_mode:string,auth:string,node:string}|null
     */
    public static function webhookFromWorkflow(array $wf): ?array
    {
        foreach ((array)($wf['nodes'] ?? []) as $node) {
            if (!is_array($node) || (string)($node['type'] ?? '') !== self::WEBHOOK_NODE) {
                continue;
            }
            if (!empty($node['disabled'])) {
                continue;
            }
            $params = (array)($node['parameters'] ?? []);
            // Chemin vide = n8n sert le webhook sur son identifiant technique.
            $path = trim((string)($params['path'] ?? ''));
            if ($path === '') {
                $path = trim((string)($node['webhookId'] ?? ''));
            }
            if ($path === '') {
                continue;
            }

            // `httpMethod` est une chaîne, ou un tableau quand le nœud accepte
            // plusieurs verbes (`multipleMethods`). Défaut n8n : GET.
            $method = $params['httpMethod'] ?? 'GET';
            if (is_array($method)) {
                $upper  = array_map(static fn($m) => strtoupper((string)$m), $method);
                $method = in_array('POST', $upper, true) ? 'POST' : (string)($upper[0] ?? 'GET');
            }
            $method = strtoupper(trim((string)$method));
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
                $method = 'POST';
            }

            return [
                'method'        => $method,
                'path'          => ltrim($path, '/'),
                'notes'         => mb_substr(trim((string)($node['notes'] ?? '')), 0, self::MAX_NOTES_CHARS),
                'response_mode' => (string)($params['responseMode'] ?? 'onReceived'),
                // Protection du webhook côté n8n : 'none' | 'headerAuth' |
                // 'basicAuth' | 'jwtAuth'. Seul headerAuth est à notre portée.
                'auth'          => (string)($params['authentication'] ?? 'none'),
                'node'          => (string)($node['name'] ?? 'Webhook'),
            ];
        }
        return null;
    }

    /** URL de production d'un webhook n8n. */
    public static function webhookUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/webhook/' . ltrim($path, '/');
    }

    /**
     * Données fournies par le LLM → tableau associatif.
     * Les modèles renvoient tantôt un objet, tantôt sa sérialisation JSON :
     * les deux sont acceptés, tout le reste est refusé explicitement (le
     * message revient au modèle, qui corrige au tour suivant).
     */
    public static function normalizePayload(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(
                    'Le paramètre « donnees » doit être un objet JSON (ex. {"client":"Dupont"}).'
                );
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            throw new \RuntimeException('Le paramètre « donnees » doit être un objet JSON.');
        }
        // Une liste nue ([1,2,3]) n'est pas un jeu de champs nommés : n8n la
        // recevrait mais aucun workflow ne saurait la lire.
        if ($raw !== [] && array_is_list($raw)) {
            throw new \RuntimeException(
                'Le paramètre « donnees » doit être un objet avec des champs nommés, pas une liste.'
            );
        }
        return $raw;
    }

    /**
     * Ligne de workflow renvoyée au modèle.
     * `$full` = fiche détaillée (notes complètes, méthode) ; sinon résumé de
     * liste (notes tronquées) — lister 25 workflows avec leurs notes entières
     * mangerait le contexte.
     */
    public static function summarizeWorkflow(array $wf, string $tag, bool $full = false): array
    {
        $hook = self::webhookFromWorkflow($wf);
        $row  = [
            'id'            => (string)($wf['id'] ?? ''),
            'nom'           => (string)($wf['name'] ?? ''),
            'declenchable'  => $hook !== null,
            'lecture_seule' => self::isReadOnly($wf, $tag),
        ];
        $notes = $hook['notes'] ?? '';
        if ($full) {
            $row['description']      = $notes;
            $row['methode']          = $hook['method'] ?? '';
            $row['reponse_immediate'] = ($hook['response_mode'] ?? 'onReceived') !== 'onReceived';
            $row['tags']             = self::tagNames($wf);
        } else {
            $row['description'] = $notes === ''
                ? ''
                : (mb_strlen($notes) > self::SHORT_NOTES ? mb_substr($notes, 0, self::SHORT_NOTES) . '…' : $notes);
        }
        if (!$row['declenchable']) {
            $row['note'] = 'Ce workflow n\'a pas de déclencheur Webhook actif : il ne peut pas être lancé depuis le chat.';
        }
        return $row;
    }

    /**
     * Corps renvoyé par le webhook → valeur exploitable par le modèle, bornée.
     * JSON décodé quand c'est du JSON, texte tronqué sinon.
     */
    public static function summarizeOutput(string $body, int $maxChars = self::MAX_OUTPUT_CHARS): array
    {
        $body = trim($body);
        if ($body === '') {
            return ['sortie' => null, 'tronque' => false];
        }
        $maxChars = max(500, $maxChars);
        $decoded  = json_decode($body, true);
        if (is_array($decoded)) {
            $reencoded = (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (mb_strlen($reencoded) <= $maxChars) {
                return ['sortie' => $decoded, 'tronque' => false];
            }
            // Trop volumineux : on rend du texte tronqué plutôt qu'un JSON
            // coupé au milieu, illisible pour le modèle.
            return ['sortie' => mb_substr($reencoded, 0, $maxChars) . '…', 'tronque' => true];
        }
        $tronque = mb_strlen($body) > $maxChars;
        return ['sortie' => $tronque ? mb_substr($body, 0, $maxChars) . '…' : $body, 'tronque' => $tronque];
    }

    /**
     * Aperçu HUMAIN de l'exécution demandée, pour la carte d'approbation
     * (mêmes clés que UserDriveService::actionPreview + icone).
     *
     * @param array{id:string,nom:string,lecture_seule:bool}|null $ref workflow résolu
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args, ?array $ref = null): array
    {
        $nom = trim((string)($ref['nom'] ?? ($args['workflow'] ?? '')));
        if ($nom === '') {
            $nom = t('(workflow sans nom)');
        }
        $donnees = $args['donnees'] ?? [];
        try {
            $donnees = self::normalizePayload($donnees);
        } catch (\RuntimeException) {
            $donnees = [];
        }
        $apercu = $donnees === []
            ? null
            : (string)json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($apercu !== null && mb_strlen($apercu) > 280) {
            $apercu = mb_substr($apercu, 0, 280) . '…';
        }

        $impacts = [
            t('Le workflow n8n sera exécuté immédiatement avec les données ci-dessous.'),
            t('Ses effets dépendent entièrement de ce que fait le workflow (envoi, écriture dans un outil tiers…) et peuvent être irréversibles.'),
        ];
        if (!empty($ref['lecture_seule'])) {
            $impacts[] = t('Ce workflow est marqué « lecture seule » par vos administrateurs.');
        }

        return [
            'action'  => t('Exécuter un workflow n8n'),
            'fichier' => $nom,
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $apercu,
            'icone'   => '/assets/img/n8n.svg',
        ];
    }

    // ===================== Lecture de l'instance =====================

    /**
     * Workflows exposés au chat (actifs + tag), triés par nom.
     * Une seule requête par instance, mémorisée.
     */
    public function exposedWorkflows(): array
    {
        if ($this->exposed !== null) {
            return $this->exposed;
        }
        $data = $this->apiGet('/api/v1/workflows', [
            'active'            => 'true',
            'tags'              => $this->tag,
            'excludePinnedData' => 'true',
            'limit'             => (string)self::MAX_SCAN,
        ]);
        $rows = [];
        foreach ((array)($data['data'] ?? []) as $wf) {
            // Le filtre `tags` est appliqué côté n8n ; on re-vérifie ici, une
            // version d'API qui l'ignorerait exposerait TOUT l'instance.
            if (is_array($wf) && self::isExposed($wf, $this->tag)) {
                $rows[] = $wf;
            }
        }
        usort($rows, static fn(array $a, array $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $this->exposed = $rows;
    }

    /**
     * Liste pour le modèle : `$query` filtre sur le nom et la description.
     * @return array<int,array<string,mixed>>
     */
    public function listWorkflows(string $query = '', int $limit = 10): array
    {
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $query = mb_strtolower(trim($query));
        $out   = [];
        foreach ($this->exposedWorkflows() as $wf) {
            $row = self::summarizeWorkflow($wf, $this->tag);
            if ($query !== '') {
                $haystack = mb_strtolower((string)$row['nom'] . ' ' . (string)$row['description']);
                if (!str_contains($haystack, $query)) {
                    continue;
                }
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Workflow exposé désigné par son id ou son nom.
     * Throw RuntimeException à message FR (transmis tel quel au modèle).
     */
    public function resolveWorkflow(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \RuntimeException('Aucun workflow indiqué — utilise d\'abord lister_workflows_n8n.');
        }
        $exposed = $this->exposedWorkflows();
        if ($exposed === []) {
            throw new \RuntimeException(
                'Aucun workflow n8n n\'est exposé au chat : les workflows doivent être actifs et porter le tag « '
                . $this->tag .' ».'
            );
        }
        foreach ($exposed as $wf) {
            if ((string)($wf['id'] ?? '') === $ref) {
                return $wf;
            }
        }
        $needle  = mb_strtolower($ref);
        $partiel = [];
        foreach ($exposed as $wf) {
            $nom = mb_strtolower((string)($wf['name'] ?? ''));
            if ($nom === $needle) {
                return $wf;
            }
            if ($nom !== '' && str_contains($nom, $needle)) {
                $partiel[] = $wf;
            }
        }
        if (count($partiel) === 1) {
            return $partiel[0];
        }
        if (count($partiel) > 1) {
            $noms = array_map(static fn(array $w) => '« ' . (string)($w['name'] ?? '') . ' »', array_slice($partiel, 0, 5));
            throw new \RuntimeException(
                'Plusieurs workflows correspondent à « ' . $ref . ' » : ' . implode(', ', $noms)
                . '. Précise lequel (nom exact ou id).'
            );
        }
        throw new \RuntimeException(
            'Workflow « ' . $ref . ' » introuvable parmi les workflows exposés au chat '
            . '(actifs et portant le tag « ' . $this->tag . ' »). Utilise lister_workflows_n8n.'
        );
    }

    /** Fiche détaillée d'un workflow (nom, description, méthode, réponse). */
    public function describe(string $ref): array
    {
        return self::summarizeWorkflow($this->resolveWorkflow($ref), $this->tag, true);
    }

    /** L'exécution directe des workflows de lecture est-elle autorisée ? */
    public function autoReadAllowed(): bool
    {
        return $this->autoRead;
    }

    /** Le workflow peut-il être exécuté sans passer par l'approbation humaine ? */
    public function runsWithoutApproval(array $wf): bool
    {
        return $this->autoRead && self::isReadOnly($wf, $this->tag);
    }

    /** Tag d'exposition de cette instance. */
    public function tagName(): string
    {
        return $this->tag;
    }

    // ===================== Exécution =====================

    /**
     * Déclenche le webhook du workflow et renvoie ce qu'il produit.
     *
     * @param array $wf   workflow déjà résolu (resolveWorkflow)
     * @param array $data champs transmis au workflow
     * @return array{workflow:string,statut:string,sortie:mixed,tronque:bool,message?:string}
     */
    public function run(array $wf, array $data): array
    {
        $hook = self::webhookFromWorkflow($wf);
        $nom  = (string)($wf['name'] ?? '');
        if ($hook === null) {
            throw new \RuntimeException(
                'Le workflow « ' . $nom . ' » n\'a pas de déclencheur Webhook actif : il ne peut pas être lancé '
                . 'depuis le chat (un déclencheur planifié ou manuel n\'est pas appelable).'
            );
        }

        $url     = self::webhookUrl($this->baseUrl, $hook['path']);
        $method  = $hook['method'];
        $headers = ['Accept: application/json'];
        $authName  = $this->authHeaderName;
        $authValue = $this->authHeaderValue;
        if ($authName !== '') {
            // La clé API d'administration ne part JAMAIS sur un webhook :
            // l'endpoint est public, seul l'en-tête partagé y a sa place.
            $headers[] = $authName . ': ' . self::sanitizeHeaderValue($authValue);
        }

        $body = null;
        if (in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            if ($data !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
            }
        } else {
            $headers[] = 'Content-Type: application/json';
            // `[]` s'encoderait en tableau JSON : un workflow qui lit
            // `$json.body.champ` recevrait une liste et planterait. JSON_FORCE_OBJECT
            // n'est pas une option, il convertirait aussi les listes imbriquées.
            $body = $data === [] ? '{}' : (string)json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        [$status, $response] = self::request($method, $url, $headers, $body, self::RUN_TIMEOUT);

        if ($status === 0) {
            throw new \RuntimeException(
                'Instance n8n injoignable pendant l\'exécution du workflow « ' . $nom . ' ».'
            );
        }
        if ($status === 404) {
            throw new \RuntimeException(
                'Webhook introuvable pour « ' . $nom . ' » (HTTP 404). Le workflow doit être ACTIVÉ dans n8n : '
                . 'tant qu\'il ne l\'est pas, seule l\'URL de test répond.'
            );
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException(
                'Webhook protégé : n8n a refusé l\'appel (HTTP ' . $status . '). '
                . 'L\'en-tête d\'authentification configuré côté MIA ne correspond pas à celui du workflow.'
            );
        }
        if ($status >= 400) {
            error_log('[n8n] webhook ' . $method . ' HTTP ' . $status . ' : ' . mb_substr($response, 0, 300));
            $detail = self::errorDetail($response);
            throw new \RuntimeException(
                'Le workflow « ' . $nom . ' » a échoué (HTTP ' . $status . ')' . ($detail !== '' ? ' : ' . $detail : '') . '.'
            );
        }

        $out = self::summarizeOutput($response);
        // Mode « répond dès réception » : n8n renvoie un accusé, pas le
        // résultat. Le dire au modèle évite qu'il invente une sortie.
        $immediat = ($hook['response_mode'] ?? 'onReceived') === 'onReceived';

        return [
            'workflow' => $nom,
            'statut'   => 'execute',
            'sortie'   => $out['sortie'],
            'tronque'  => $out['tronque'],
            'message'  => $immediat
                ? 'Workflow déclenché. Il est configuré pour répondre dès réception : il continue de tourner dans n8n '
                    . 'et son résultat final n\'est pas disponible ici.'
                : 'Workflow exécuté, sortie ci-dessus.',
        ];
    }

    // ===================== Test d'administration =====================

    /**
     * Bouton « Tester la connexion » : valide URL + clé API et compte les
     * workflows réellement exposés. Statique pour pouvoir tester les valeurs
     * saisies dans le formulaire AVANT enregistrement.
     *
     * @return array{ok:bool,detail?:string,error?:string}
     */
    public static function testConnection(string $baseUrl, string $apiKey, string $tag): array
    {
        $tag  = self::sanitizeTag($tag);
        $scan = self::scanExposed($baseUrl, $apiKey, $tag);
        if (isset($scan['error'])) {
            return ['ok' => false, 'error' => $scan['error']];
        }

        $exposes  = count($scan['workflows']);
        $sansHook = [];
        foreach ($scan['workflows'] as $wf) {
            if (self::webhookFromWorkflow($wf) === null) {
                $sansHook[] = (string)($wf['name'] ?? '');
            }
        }

        if ($exposes === 0) {
            return ['ok' => true, 'detail' => t(
                'connexion établie, mais aucun workflow exposé : ajoutez le tag « {tag} » à un workflow ACTIF dans n8n.',
                ['tag' => $tag]
            )];
        }
        $detail = I18n::plural(
            $exposes,
            '{n} workflow exposé (tag « {tag} »).',
            '{n} workflows exposés (tag « {tag} »).',
            ['tag' => $tag]
        );
        if ($sansHook !== []) {
            $detail .= ' ' . I18n::plural(
                count($sansHook),
                '{n} sans déclencheur Webhook, non lançable depuis le chat : {noms}.',
                '{n} sans déclencheur Webhook, non lançables depuis le chat : {noms}.',
                ['noms' => implode(', ', array_slice($sansHook, 0, 3))]
            );
        }
        return ['ok' => true, 'detail' => $detail];
    }

    /**
     * Bouton « Vérifier la protection des webhooks » (étape 3).
     *
     * On ne DÉCLENCHE rien : appeler un webhook pour éprouver son en-tête
     * exécuterait le workflow — avec ses effets réels — dès que la valeur est
     * bonne. Le test lit donc la protection déclarée par chaque nœud Webhook
     * (`authentication`) et la confronte à ce qui est configuré ici. Il détecte
     * les trois situations qui cassent en production sans jamais rien dire :
     * workflows en Header Auth alors qu'aucun en-tête n'est configuré (403 à
     * chaque exécution), workflows en Basic/JWT Auth (MIA ne sait pas les
     * fournir), webhooks sans aucune protection (déclenchables par quiconque
     * connaît l'URL).
     *
     * La VALEUR de l'en-tête, elle, vit dans un credential n8n que l'API
     * n'expose pas : elle est invérifiable à distance, et on le dit.
     *
     * @return array{ok:bool,detail?:string,error?:string}
     */
    public static function testWebhookProtection(
        string $baseUrl,
        string $apiKey,
        string $tag,
        string $headerName,
        bool $hasHeaderValue
    ): array {
        $tag  = self::sanitizeTag($tag);
        $scan = self::scanExposed($baseUrl, $apiKey, $tag);
        if (isset($scan['error'])) {
            return ['ok' => false, 'error' => $scan['error']];
        }
        return self::classifyWebhookProtection($scan['workflows'], $tag, $headerName, $hasHeaderValue);
    }

    /**
     * Verdict de l'étape 3 à partir des workflows déjà lus — partie pure,
     * testable sans réseau (c'est là que vit tout le raisonnement).
     *
     * @param array<int,array> $workflows workflows exposés
     * @return array{ok:bool,detail?:string,error?:string}
     */
    public static function classifyWebhookProtection(
        array $workflows,
        string $tag,
        string $headerName,
        bool $hasHeaderValue
    ): array {
        $tag        = self::sanitizeTag($tag);
        $headerName = self::sanitizeHeaderName($headerName);
        $configure  = $headerName !== '' && $hasHeaderValue;

        if ($headerName === '' && $hasHeaderValue) {
            return ['ok' => false, 'error' => t(
                'Nom d\'en-tête absent ou invalide : un seul mot, sans espace (ex. X-Mia-Token). Une valeur sans nom n\'est envoyée nulle part.'
            )];
        }

        $protege = [];  // headerAuth — à notre portée
        $autre   = [];  // basicAuth / jwtAuth — hors de notre portée
        $ouvert  = [];  // aucune protection
        foreach ($workflows as $wf) {
            $hook = is_array($wf) ? self::webhookFromWorkflow($wf) : null;
            if ($hook === null) {
                continue; // non déclenchable : sa protection n'a pas d'objet
            }
            $nom = (string)($wf['name'] ?? '');
            match ($hook['auth']) {
                'headerAuth'          => $protege[] = $nom,
                'basicAuth', 'jwtAuth' => $autre[] = $nom,
                default                => $ouvert[] = $nom,
            };
        }

        if ($protege === [] && $autre === [] && $ouvert === []) {
            return ['ok' => true, 'detail' => t(
                'aucun workflow déclenchable à vérifier (commencez par l\'étape 1, puis testez la connexion).'
            )];
        }

        // Noms cités : les guillemets suivent la langue (I18n::listeCitee).
        $liste = static fn(array $noms): string => I18n::listeCitee(array_slice($noms, 0, 3))
            . (count($noms) > 3 ? ', …' : '');

        // Bloquants d'abord : ces workflows échoueront à coup sûr.
        $problemes = [];
        if ($protege !== [] && !$configure) {
            $problemes[] = I18n::plural(
                count($protege),
                '{n} workflow exige un en-tête d\'authentification ({liste}), mais aucun n\'est configuré ici : leurs exécutions seront refusées (HTTP 403).',
                '{n} workflows exigent un en-tête d\'authentification ({liste}), mais aucun n\'est configuré ici : leurs exécutions seront refusées (HTTP 403).',
                ['liste' => $liste($protege)]
            );
        }
        if ($autre !== []) {
            $problemes[] = I18n::plural(
                count($autre),
                '{n} workflow utilise Basic Auth ou JWT ({liste}), que MIA ne sait pas fournir : passez-les en Header Auth, ou retirez-leur le tag « {tag} ».',
                '{n} workflows utilisent Basic Auth ou JWT ({liste}), que MIA ne sait pas fournir : passez-les en Header Auth, ou retirez-leur le tag « {tag} ».',
                ['liste' => $liste($autre), 'tag' => $tag]
            );
        }
        if ($problemes !== []) {
            return ['ok' => false, 'error' => implode(' ', $problemes)];
        }

        $detail = [];
        if ($protege !== []) {
            $detail[] = I18n::plural(
                count($protege),
                '{n} webhook protégé par Header Auth, et MIA envoie bien un en-tête « {entete} ». La valeur ne peut pas être vérifiée à distance (elle vit dans un credential n8n) : un refus 401/403 à l\'exécution signalerait qu\'elle ne correspond pas.',
                '{n} webhooks protégés par Header Auth, et MIA envoie bien un en-tête « {entete} ». La valeur ne peut pas être vérifiée à distance (elle vit dans un credential n8n) : un refus 401/403 à l\'exécution signalerait qu\'elle ne correspond pas.',
                ['entete' => $headerName]
            );
        } elseif ($configure) {
            $detail[] = t(
                'aucun workflow exposé n\'utilise Header Auth : l\'en-tête « {entete} » configuré ici ne sert à rien pour l\'instant (il est envoyé, mais personne ne le lit).',
                ['entete' => $headerName]
            );
        }
        if ($ouvert !== []) {
            $detail[] = '⚠ ' . I18n::plural(
                count($ouvert),
                '{n} webhook est ouvert ({liste}) : quiconque connaît leur URL peut les déclencher, MIA ou non. Protégez-les côté n8n (nœud Webhook → Authentication → Header Auth).',
                '{n} webhooks sont ouverts ({liste}) : quiconque connaît leur URL peut les déclencher, MIA ou non. Protégez-les côté n8n (nœud Webhook → Authentication → Header Auth).',
                ['liste' => $liste($ouvert)]
            );
        }
        if ($detail === []) {
            $detail[] = t('rien à signaler.');
        }
        return ['ok' => true, 'detail' => implode(' ', $detail)];
    }

    /**
     * Lecture des workflows exposés pour les tests d'administration, sur des
     * identifiants passés en argument (donc testables avant enregistrement).
     *
     * @return array{workflows:array<int,array>}|array{error:string}
     */
    private static function scanExposed(string $baseUrl, string $apiKey, string $tag): array
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if ($baseUrl === '') {
            return ['error' => t('URL de l\'instance n8n manquante ou invalide (attendu : https://n8n.exemple.fr).')];
        }
        $apiKey = self::sanitizeHeaderValue($apiKey);
        if ($apiKey === '') {
            return ['error' => t('Clé API n8n manquante.')];
        }

        $url = $baseUrl . '/api/v1/workflows?' . http_build_query([
            'active'            => 'true',
            'tags'              => $tag,
            'excludePinnedData' => 'true',
            'limit'             => (string)self::MAX_SCAN,
        ]);
        [$status, $body] = self::request('GET', $url, [
            'Accept: application/json',
            'X-N8N-API-KEY: ' . $apiKey,
        ], null, self::API_TIMEOUT);

        if ($status === 0) {
            return ['error' => t(
                'Instance injoignable — vérifiez l\'URL, le DNS et que le serveur MIA peut sortir vers cette adresse.'
            )];
        }
        if ($status === 401 || $status === 403) {
            return ['error' => t(
                'Clé API refusée par n8n (HTTP {status}) — recréez-la dans n8n → Paramètres → API.',
                ['status' => $status]
            )];
        }
        if ($status === 404) {
            return ['error' => t(
                'API publique introuvable sur cette URL (HTTP 404) — vérifiez l\'adresse, et que l\'API publique n\'est pas désactivée (N8N_PUBLIC_API_DISABLED).'
            )];
        }
        if ($status >= 400) {
            return ['error' => t('n8n a répondu HTTP {status}.', ['status' => $status])];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data'])) {
            return ['error' => t('Réponse inattendue : l\'URL ne pointe pas vers une API n8n.')];
        }

        $rows = [];
        foreach ((array)$data['data'] as $wf) {
            if (is_array($wf) && self::isExposed($wf, $tag)) {
                $rows[] = $wf;
            }
        }
        return ['workflows' => $rows];
    }

    // ===================== HTTP =====================

    /** GET authentifié sur l'API publique n8n. */
    private function apiGet(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        [$status, $body] = self::request('GET', $url, [
            'Accept: application/json',
            'X-N8N-API-KEY: ' . $this->apiKey,
        ], null, self::API_TIMEOUT);

        if ($status === 0) {
            throw new \RuntimeException('Instance n8n injoignable — réessayez dans quelques instants.');
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Accès à n8n refusé (clé API invalide ou révoquée) — prévenez un administrateur.');
        }
        if ($status === 404) {
            throw new \RuntimeException('API n8n introuvable à cette adresse — la configuration du connecteur est à revoir.');
        }
        if ($status >= 400) {
            error_log('[n8n] GET ' . $path . ' HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
            throw new \RuntimeException('Erreur n8n (HTTP ' . $status . ').');
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Requête HTTP brute. Renvoie [statut, corps] ; statut 0 = pas de réponse.
     * Aucune exception ici : chaque appelant présente l'erreur dans SES termes.
     *
     * @param array<int,string> $headers
     * @return array{0:int,1:string}
     */
    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            // Une redirection vers un autre hôte n'a rien à faire là : on la
            // laisse remonter en statut 3xx plutôt que de suivre à l'aveugle.
            'max_redirects' => 1,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        $status   = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return [$status, $response === false ? '' : $response];
    }

    /** Message d'erreur exploitable dans un corps n8n, ou ''. */
    private static function errorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        foreach (['message', 'error', 'hint'] as $key) {
            if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                return mb_substr(trim($decoded[$key]), 0, 200);
            }
        }
        return '';
    }
}
