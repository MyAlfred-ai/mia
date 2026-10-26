<?php

declare(strict_types=1);

/**
 * « Crée-moi un assistant qui… » : MIA PRÉPARE un assistant.
 *
 * Source unique des règles de création d'un assistant HORS du formulaire
 * d'administration : qui en a le droit, ce qu'il peut choisir (modèles permis
 * à SES groupes, bases de connaissances de SON périmètre, outils liables,
 * avatars de la bibliothèque), la validation d'une proposition, la carte
 * d'approbation qui la montre, et la création elle-même au clic
 * « Approuver ». Le formulaire `/admin/profiles` délègue ici ce qu'il
 * partageait (modèles permis, outils liables, périmètre des bases) : deux
 * règles divergeraient au premier ajustement — un assistant préparé dans le
 * chat pourrait alors porter un modèle que la fiche refuserait.
 *
 * Rien n'est créé avant l'approbation : l'outil `creer_assistant`
 * (AssistantTools) dépose une carte ; « Approuver » rejoue normalizeInput()
 * sur les choix permis AU MOMENT T (droits, modèles, bases, outils) ;
 * « Ajuster » ouvre la fiche de création pré-remplie depuis la carte.
 *
 * L'avatar peut être CRÉÉ pour l'assistant : l'image générée pendant la
 * proposition (dossier des images générées) n'entre dans la bibliothèque des
 * avatars qu'à la création — carrée, réduite, nommée comme l'assistant.
 */
final class AssistantDraft
{
    /** Nom de l'outil (et de l'action de la file d'approbation). */
    public const TOOL = 'creer_assistant';

    /** Prompt système : au-delà, il ne tient plus dans la carte (aperçu TEXT). */
    public const PROMPT_MAX = 16000;
    /** Aperçu du prompt dans la carte (preview_json est un TEXT de 64 Ko). */
    public const PREVIEW_PROMPT_MAX = 6000;
    /** Description visuelle d'un avatar à créer. */
    public const AVATAR_PROMPT_MAX = 600;
    /** Bases et outils liés par une proposition (le formulaire n'en borne aucun). */
    public const MAX_LINKS = 10;

    /** Fiche de création pré-remplie depuis une carte (`?action=ID`). */
    public const ADJUST_URL = '/admin/profiles/new';

    /** Valeur du bouton radio « Avatar créé par MIA » dans la fiche pré-remplie. */
    public const GENERATED_AVATAR_CHOICE = '@genere';

    /** Dossier web des images générées (l'avatar créé y attend la création). */
    public const GENERATED_DIR_WEB = '/assets/img/generated/';

    /** Familles d'outils liables à un assistant (function-calling). */
    public static function linkableToolTypes(): array
    {
        return ['image', N8nService::TOOL_TYPE, GammaService::TOOL_TYPE];
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    /**
     * Droit « Assistants — Créer » du rôle (Personnes → Rôles), compte
     * actif. Créateurs et administrateurs l'ont par défaut.
     */
    public static function canCreate(array $user): bool
    {
        return (int)($user['is_active'] ?? 1) === 1 && Role::userCan($user, 'assistants.create');
    }

    /** Quota d'assistants personnels atteint (« Assistants — Gérer » : jamais). */
    public static function quotaReached(array $user): bool
    {
        if (Role::userCan($user, 'assistants.manage')) {
            return false;
        }
        return Profile::countPersonalByCreator((int)$user['id']) >= Profile::MAX_PERSONAL_PER_CREATOR;
    }

    /**
     * Modèles qu'un gestionnaire peut donner à un assistant : administrateur =
     * tous les modèles activés ; créateur = ceux de ses groupes (même règle
     * que le chat, cf. UserGroup::allowedLlmKeysForUser — null = aucune
     * restriction).
     *
     * @return array<string, string> slug → libellé
     */
    public static function allowedLlmsFor(array $user): array
    {
        $all = LLMFactory::available();
        // « Assistants — Gérer » (administrateur, ou rôle qui le porte) :
        // tous les modèles activés ; sinon ceux de ses groupes.
        if (Role::userCan($user, 'assistants.manage')) {
            return $all;
        }
        $scope   = UserGroup::membershipScopeForUser((int)$user['id']);
        $allowed = UserGroup::allowedLlmKeysForUser($scope['active_group_ids']);
        return $allowed === null ? $all : array_intersect_key($all, array_flip($allowed));
    }

    /**
     * Outils liables à un assistant : actifs, d'un type exposé en
     * function-calling (cocher autre chose n'aurait aucun effet). La portée
     * de groupe, elle, s'applique à l'usage (le chat ne propose un outil lié
     * qu'à qui ses groupes l'ouvrent).
     *
     * @return array<int, array<string, mixed>> id → ligne chat_tools
     */
    public static function linkableTools(): array
    {
        $out = [];
        foreach (self::linkableToolTypes() as $type) {
            foreach (ChatTool::active($type) as $t) {
                $out[(int)$t['id']] = $t;
            }
        }
        return $out;
    }

    /**
     * Liste d'identifiants d'outils d'un POST ramenée aux outils liables. Ordre
     * conservé, doublons retirés.
     *
     * @return list<int>
     */
    public static function sanitizeToolIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $valid = self::linkableTools();
        $out = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($valid[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Bases qu'un gestionnaire peut lier à un assistant : son périmètre
     * (KnowledgeBase::forManager), base système exclue — sa liaison à
     * l'assistant d'aide est posée par DefaultAssistant et ne suit aucun
     * formulaire.
     *
     * @return array<int, array<string, mixed>> id → ligne knowledge_bases
     */
    public static function kbScope(array $user): array
    {
        $out = [];
        foreach (KnowledgeBase::forManager($user) as $kb) {
            if ((int)($kb['is_system'] ?? 0) !== 1) {
                $out[(int)$kb['id']] = $kb;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Ce que la personne peut choisir
    // ------------------------------------------------------------------

    /**
     * Choix permis à ce compte, sous la forme que lisent la validation, la
     * carte et la définition de l'outil.
     *
     * @return array{
     *   admin: bool,
     *   llms: array<string, string>,
     *   llm_info: array<string, array{note: string, temp: bool, tools: bool}>,
     *   app_default: ?array{slug: string, label: string, temp: bool, tools: bool},
     *   kbs: array<int, array{name: string, description: string, docs: int, active: bool, live: bool}>,
     *   tools: array<int, array{name: string, type: string}>,
     *   avatars: array<string, string>
     * }
     */
    public static function choicesFor(array $user): array
    {
        $llms = self::allowedLlmsFor($user);
        $info = [];
        foreach (array_keys($llms) as $slug) {
            $info[(string)$slug] = self::llmInfo((string)$slug);
        }
        $all        = LLMFactory::available();
        $appDefault = LLMFactory::appDefault($all);

        $kbs = [];
        foreach (self::kbScope($user) as $id => $kb) {
            $kbs[$id] = [
                'name'        => (string)$kb['name'],
                'description' => trim((string)($kb['description'] ?? '')),
                'docs'        => (int)($kb['doc_count'] ?? 0),
                'active'      => (int)($kb['is_active'] ?? 1) === 1,
                'live'        => KnowledgeBase::isRemote($kb),
            ];
        }
        $tools = [];
        foreach (self::linkableTools() as $id => $t) {
            $tools[$id] = ['name' => (string)$t['name'], 'type' => (string)$t['type']];
        }

        return [
            'admin'       => Role::userCan($user, 'assistants.manage'),
            'llms'        => $llms,
            'llm_info'    => $info,
            'app_default' => $appDefault !== null
                ? ['slug' => $appDefault, 'label' => (string)($all[$appDefault] ?? $appDefault)] + self::llmInfo($appDefault)
                : null,
            'kbs'         => $kbs,
            'tools'       => $tools,
            'avatars'     => AssistantAvatar::catalog(),
        ];
    }

    /**
     * Ce qu'il faut savoir d'un modèle pour le choisir : ses capacités (tags du
     * catalogue), sa note, son tarif, s'il règle une température et s'il pilote
     * des outils. Lit le catalogue, aucune base.
     *
     * @return array{note: string, temp: bool, tools: bool}
     */
    public static function llmInfo(string $slug): array
    {
        if ($slug === 'local') {
            return ['note' => 'modèle local', 'temp' => true, 'tools' => LlmCatalog::supportsToolUse('local', '')];
        }
        [$provider, $model] = str_contains($slug, '::')
            ? explode('::', $slug, 2)
            : [$slug, LlmCatalog::defaultModel($slug)];
        $entry = LlmCatalog::model($provider, $model) ?? [];
        $parts = [];
        $tags  = array_values(array_filter(array_map('strval', (array)($entry['tags'] ?? []))));
        $tools = LlmCatalog::supportsToolUse($provider, $model);
        if ($tools) {
            $tags[] = 'outils';
        }
        if ($tags !== []) {
            $parts[] = implode(', ', array_unique($tags));
        }
        $note = trim((string)($entry['note'] ?? ''));
        if ($note !== '') {
            $note = (string)preg_replace('/\s+/u', ' ', $note);
            $parts[] = mb_strlen($note) > 140 ? rtrim(mb_substr($note, 0, 139)) . '…' : $note;
        }
        $pricing = LlmCatalog::pricing($provider, $model);
        if (is_array($pricing) && isset($pricing['input'], $pricing['output'])) {
            $parts[] = self::number((float)$pricing['input'], 2) . ' $ / ' . self::number((float)$pricing['output'], 2)
                . ' $ par million de tokens (entrée / sortie)';
        }
        return [
            'note'  => implode(' — ', $parts),
            'temp'  => LlmCatalog::supportsTemperature($provider, $model),
            'tools' => $tools,
        ];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Saisie (champs de la fiche, ou arguments d'une carte) → assistant
     * validé, avec les choix permis du compte. Lève une
     * InvalidArgumentException au message présentable. Pure (hors existence
     * du fichier d'un avatar créé).
     *
     * @param array $in clés : name, description, system_prompt, default_llm,
     *        temperature, color, kb_ids, tool_ids, avatar, avatar_generated
     * @param list<string> $generatedAllowed images générées utilisables comme
     *        avatar (produites dans cette conversation) ; null = toute image
     *        générée encore présente (carte relue au clic « Approuver »).
     * @return array{name: string, description: string, system_prompt: string,
     *   default_llm: ?string, temperature: ?float, color: ?string,
     *   kb_ids: list<int>, tool_ids: list<int>, avatar: ?string, avatar_generated: ?string}
     */
    public static function normalizeInput(array $in, array $choices, ?array $generatedAllowed = []): array
    {
        $name = self::oneLine((string)($in['name'] ?? ''), PromptStarters::NAME_MAX);
        if ($name === '') {
            throw new \InvalidArgumentException(t('Le nom de l’assistant est obligatoire.'));
        }
        $description = self::oneLine((string)($in['description'] ?? ''), PromptStarters::DESC_MAX);

        $prompt = trim(str_replace(["\r\n", "\r"], "\n", (string)($in['system_prompt'] ?? '')));
        $prompt = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);
        if ($prompt === '') {
            throw new \InvalidArgumentException(t('Le prompt système est obligatoire.'));
        }
        if (mb_strlen($prompt) > self::PROMPT_MAX) {
            throw new \InvalidArgumentException(t('Prompt système trop long : {n} caractères au plus.', ['n' => self::PROMPT_MAX]));
        }

        $llm = trim((string)($in['default_llm'] ?? ''));
        if ($llm !== '' && !isset($choices['llms'][$llm])) {
            throw new \InvalidArgumentException(t('Modèle non permis pour votre compte : {modele}.', ['modele' => $llm]));
        }
        $llm = $llm !== '' ? $llm : null;

        $temperature = null;
        $rawTemp = $in['temperature'] ?? null;
        if ($rawTemp !== null && trim((string)$rawTemp) !== '') {
            $rawTemp = str_replace(',', '.', trim((string)$rawTemp));
            if (!is_numeric($rawTemp)) {
                throw new \InvalidArgumentException(t('Température invalide : un nombre entre 0 et 2 est attendu.'));
            }
            $temperature = round(max(0.0, min(2.0, (float)$rawTemp)), 1);
        }
        // Un modèle qui ne règle pas de température l'ignorerait : rien n'est
        // enregistré (même règle que la fiche, qui grise alors le curseur).
        if (!self::temperatureSupported($llm, $choices)) {
            $temperature = null;
        }

        $color = self::color((string)($in['color'] ?? ''));

        $kbIds   = self::idsWithin($in['kb_ids'] ?? [], (array)($choices['kbs'] ?? []), t('Base de connaissances hors de votre périmètre : {ref}.'));
        $toolIds = self::idsWithin($in['tool_ids'] ?? [], (array)($choices['tools'] ?? []), t('Outil non liable à un assistant : {ref}.'));

        $avatar = trim((string)($in['avatar'] ?? ''));
        if ($avatar !== '' && !isset($choices['avatars'][$avatar])) {
            throw new \InvalidArgumentException(t('Avatar absent de la bibliothèque : {avatar}.', ['avatar' => $avatar]));
        }
        $generated = self::generatedUrl((string)($in['avatar_generated'] ?? ''));
        if ($generated !== null) {
            $permitted = $generatedAllowed === null
                || in_array($generated, array_map(static fn($u): ?string => self::generatedUrl((string)$u), $generatedAllowed), true);
            if (!$permitted || self::generatedImagePath($generated) === null) {
                throw new \InvalidArgumentException(t('L’avatar créé pour cet assistant n’est plus disponible : choisissez-en un autre.'));
            }
            $avatar = '';
        }

        return [
            'name'             => $name,
            'description'      => $description,
            'system_prompt'    => $prompt,
            'default_llm'      => $llm,
            'temperature'      => $temperature,
            'color'            => $color,
            'kb_ids'           => $kbIds,
            'tool_ids'         => $toolIds,
            'avatar'           => $avatar !== '' ? $avatar : null,
            'avatar_generated' => $generated,
        ];
    }

    /**
     * Le modèle de l'assistant (null = celui de l'application) règle-t-il une
     * température ? Pure.
     */
    public static function temperatureSupported(?string $llm, array $choices): bool
    {
        return $llm !== null
            ? (bool)($choices['llm_info'][$llm]['temp'] ?? true)
            : (bool)($choices['app_default']['temp'] ?? true);
    }

    /**
     * Incohérences d'une proposition que le modèle doit corriger avant de la
     * soumettre (jamais bloquantes dans la fiche : l'administrateur garde la
     * main). Pure.
     *
     * @return list<string>
     */
    public static function coherenceIssues(array $data, array $choices): array
    {
        $issues = [];
        if ($data['tool_ids'] !== []) {
            $tools = $data['default_llm'] !== null
                ? (bool)($choices['llm_info'][$data['default_llm']]['tools'] ?? false)
                : (bool)($choices['app_default']['tools'] ?? false);
            if (!$tools) {
                $issues[] = 'Le modèle choisi ne sait pas utiliser d\'outils : les outils liés ne serviraient à rien. '
                    . 'Choisis un modèle marqué « outils », ou retire les outils.';
            }
        }
        foreach ($data['kb_ids'] as $id) {
            if (empty($choices['kbs'][$id]['active'])) {
                $issues[] = 'La base « ' . (string)($choices['kbs'][$id]['name'] ?? $id) . ' » est désactivée : '
                    . 'elle ne servirait à rien. Retire-la, ou dis à l\'utilisateur de la réactiver d\'abord.';
            }
        }
        return $issues;
    }

    /** « #3b82f6 », « 3B82F6 », « #38f » → « #3b82f6 » ; vide → null. Pure. */
    public static function color(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        $hex = ltrim($raw, '#');
        if (preg_match('/^[0-9a-f]{3}$/', $hex) === 1) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException(t('Couleur invalide : attendue au format #RRGGBB.'));
        }
        return '#' . $hex;
    }

    /**
     * Identifiants ou NOMS → identifiants du catalogue (le modèle écrit souvent
     * le nom d'une base plutôt que son numéro). Nom comparé sans casse ni
     * accents. Pure.
     *
     * @param array<int, array{name: string}> $catalog
     * @return list<int>
     */
    public static function idsWithin(mixed $raw, array $catalog, string $unknownMessage): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : (preg_split('/\s*[,;]\s*/u', trim($raw)) ?: []);
        }
        $byName = [];
        foreach ($catalog as $id => $row) {
            $byName[self::fold((string)($row['name'] ?? ''))] = (int)$id;
        }
        $out = [];
        foreach ((array)$raw as $ref) {
            if (is_array($ref)) {
                $ref = $ref['id'] ?? $ref['nom'] ?? $ref['name'] ?? '';
            }
            $ref = trim((string)$ref);
            if ($ref === '') {
                continue;
            }
            $id = ctype_digit($ref) && isset($catalog[(int)$ref]) ? (int)$ref : ($byName[self::fold($ref)] ?? null);
            if ($id === null) {
                throw new \InvalidArgumentException(strtr($unknownMessage, ['{ref}' => $ref]));
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        if (count($out) > self::MAX_LINKS) {
            throw new \InvalidArgumentException(t('{n} liaisons au plus par catégorie.', ['n' => self::MAX_LINKS]));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Carte, arguments, fiche pré-remplie
    // ------------------------------------------------------------------

    /**
     * Carte d'approbation : ce qui sera créé, en toutes lettres. Le prompt
     * système va dans l'aperçu dépliable ; l'avatar (ou la pastille de
     * couleur) en tête. Lit les libellés des choix, aucune base.
     */
    public static function actionPreview(array $data, array $choices): array
    {
        $impacts = [];
        if ($data['description'] !== '') {
            $impacts[] = t('Description : {texte}', ['texte' => $data['description']]);
        }

        if ($data['default_llm'] !== null) {
            $model = t('Modèle : {modele}', ['modele' => (string)($choices['llms'][$data['default_llm']] ?? $data['default_llm'])]);
        } elseif (!empty($choices['app_default']['label'])) {
            $model = t('Modèle : celui de l’application ({modele}), qu’il suivra s’il change', ['modele' => (string)$choices['app_default']['label']]);
        } else {
            $model = t('Modèle : celui de l’application');
        }
        $model .= ' · ' . match (true) {
            !self::temperatureSupported($data['default_llm'], $choices) => t('température non réglable sur ce modèle'),
            $data['temperature'] !== null => t('température {valeur}', ['valeur' => self::number($data['temperature'], 1)]),
            default                       => t('température par défaut'),
        };
        $impacts[] = $model;

        $kbNames = array_values(array_filter(array_map(
            static fn(int $id): string => (string)($choices['kbs'][$id]['name'] ?? ''),
            $data['kb_ids']
        )));
        $impacts[] = $kbNames !== []
            ? t('Bases de connaissances : {liste}', ['liste' => implode(', ', $kbNames)])
            : t('Aucune base de connaissances : il répond avec ses connaissances et les fichiers joints');

        $toolNames = array_values(array_filter(array_map(
            static fn(int $id): string => (string)($choices['tools'][$id]['name'] ?? ''),
            $data['tool_ids']
        )));
        if ($toolNames !== []) {
            $impacts[] = t('Outils : {liste}', ['liste' => implode(', ', $toolNames)]);
        }

        $impacts[] = match (true) {
            $data['avatar_generated'] !== null => t('Avatar créé pour lui par MIA'),
            $data['avatar'] !== null           => t('Avatar : « {nom} »', ['nom' => (string)($choices['avatars'][$data['avatar']] ?? $data['avatar'])]),
            default                            => t('Sans avatar : pastille de couleur avec son initiale'),
        };
        $impacts[] = !empty($choices['admin'])
            ? t('Assistant personnel : vous seul le voyez, jusqu’à ce que vous le partagiez depuis la liste des assistants')
            : t('Assistant personnel : vous seul le voyez, jusqu’à ce qu’un administrateur le partage');
        $impacts[] = t('Documents de référence : à ajouter depuis sa fiche, une fois créé');

        $prompt = $data['system_prompt'];
        if (mb_strlen($prompt) > self::PREVIEW_PROMPT_MAX) {
            $prompt = rtrim(mb_substr($prompt, 0, self::PREVIEW_PROMPT_MAX)) . "\n…";
        }

        return [
            'action'       => t('Créer un assistant'),
            'fichier'      => $data['name'],
            'apercu'       => $prompt,
            'apercu_titre' => t('Prompt système'),
            'impacts'      => $impacts,
            'icone'        => '/assets/img/assistant.svg',
            'visuel'       => [
                'image'    => $data['avatar_generated'] ?? ($data['avatar'] !== null ? AssistantAvatar::DIR_WEB . '/' . rawurlencode($data['avatar']) : ''),
                'couleur'  => $data['color'] ?? '',
                'initiale' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
            ],
            // « Ajuster » : la fiche de création pré-remplie depuis CETTE carte.
            'ajuster'      => self::ADJUST_URL,
        ];
    }

    /**
     * Assistant validé → arguments de la carte (relus au clic « Approuver »,
     * par « Ajuster », et comme base d'une proposition révisée). Pure.
     */
    public static function toArgs(array $data): array
    {
        return [
            'name'             => (string)$data['name'],
            'description'      => (string)$data['description'],
            'system_prompt'    => (string)$data['system_prompt'],
            'default_llm'      => (string)($data['default_llm'] ?? ''),
            'temperature'      => $data['temperature'] !== null ? (string)$data['temperature'] : '',
            'color'            => (string)($data['color'] ?? ''),
            'kb_ids'           => array_map('strval', $data['kb_ids']),
            'tool_ids'         => array_map('strval', $data['tool_ids']),
            'avatar'           => (string)($data['avatar'] ?? ''),
            'avatar_generated' => (string)($data['avatar_generated'] ?? ''),
        ];
    }

    /**
     * Arguments d'une carte → valeurs de la fiche de création (« Ajuster »).
     * Aucune validation ici : la fiche les montre, la personne corrige, et
     * l'enregistrement applique les règles du formulaire. Pure.
     *
     * @return array{name: string, description: string, system_prompt: string, default_llm: string,
     *   temperature: ?string, color: string, kb_ids: list<int>, tool_ids: list<int>,
     *   avatar: string, avatar_generated: string}
     */
    public static function formPrefill(array $args): array
    {
        $ids = static fn(mixed $raw): array => array_values(array_unique(array_filter(
            array_map('intval', is_array($raw) ? $raw : []),
            static fn(int $id): bool => $id > 0
        )));
        $temp = trim((string)($args['temperature'] ?? ''));
        try {
            $color = self::color((string)($args['color'] ?? '')) ?? '';
        } catch (\InvalidArgumentException) {
            $color = '';
        }
        return [
            'name'             => (string)($args['name'] ?? ''),
            'description'      => (string)($args['description'] ?? ''),
            'system_prompt'    => (string)($args['system_prompt'] ?? ''),
            'default_llm'      => (string)($args['default_llm'] ?? ''),
            'temperature'      => is_numeric($temp) ? $temp : null,
            'color'            => $color,
            'kb_ids'           => $ids($args['kb_ids'] ?? []),
            'tool_ids'         => $ids($args['tool_ids'] ?? []),
            'avatar'           => (string)($args['avatar'] ?? ''),
            'avatar_generated' => (string)(self::generatedUrl((string)($args['avatar_generated'] ?? '')) ?? ''),
        ];
    }

    /**
     * L'assistant vient d'être créé depuis « Ajuster » : la carte d'origine
     * est close (sinon un « Approuver » tardif créerait un doublon) et la
     * conversation le dit. Une carte déjà tranchée ou expirée reste telle
     * quelle — l'assistant, lui, existe : c'était la demande.
     */
    public static function closeAdjustedCard(int $actionId, int $userId, int $profileId, string $name): void
    {
        $pending = DrivePendingAction::findPendingForUser($actionId, $userId);
        if ($pending === null || (string)$pending['tool'] !== self::TOOL) {
            return;
        }
        $convId = (int)$pending['conversation_id'];
        if (DrivePendingAction::claim($actionId, $userId, $convId) === null) {
            return;
        }
        DrivePendingAction::resolve($actionId, 'executed');
        Message::create($convId, 'assistant', '✅ ' . t('Assistant créé depuis sa fiche : [« {nom} »]({url}).', [
            'nom' => $name,
            'url' => '/admin/profiles/' . $profileId . '/edit',
        ]));
        Conversation::touch($convId);
    }

    /**
     * Prompt envoyé au modèle image pour l'avatar d'un assistant : sujet
     * décrit par la proposition, contraintes d'un avatar (lisible en petit,
     * centré, fond uni, aucun texte). Pure.
     */
    public static function avatarPrompt(string $visual, array $data): string
    {
        $visual = self::oneLine($visual, self::AVATAR_PROMPT_MAX);
        $parts  = ['Avatar carré d\'un assistant conversationnel nommé « ' . $data['name'] . ' »'
            . ($data['description'] !== '' ? ' (' . $data['description'] . ')' : '') . '.'];
        if ($visual !== '') {
            $parts[] = $visual . '.';
        }
        $parts[] = 'Illustration simple, expressive et lisible même en tout petit (pastille ronde de 40 pixels) : '
            . 'un seul sujet, centré, cadré serré, sur un fond uni'
            . ($data['color'] !== null ? ' qui s\'accorde avec la couleur ' . $data['color'] : '')
            . '. Aucun texte, aucune lettre, aucun logo, aucun cadre.';
        return implode(' ', $parts);
    }

    // ------------------------------------------------------------------
    // Persistance
    // ------------------------------------------------------------------

    /**
     * Crée l'assistant validé pour ce compte : personnel (visible de son seul
     * créateur), actif, ses outils et ses bases liés dans le périmètre du
     * compte, l'avatar créé par MIA importé dans la bibliothèque.
     *
     * @return int identifiant du nouvel assistant
     */
    public static function create(array $data, array $user): int
    {
        $userId = (int)$user['id'];
        $avatar = $data['avatar'];
        if ($data['avatar_generated'] !== null) {
            $path = self::generatedImagePath($data['avatar_generated']);
            $imported = $path !== null ? AssistantAvatar::importImage($path, $data['name'], $userId) : null;
            if ($imported === null) {
                error_log('[assistants] avatar créé par MIA non importé (' . $data['avatar_generated'] . ') : assistant créé sans avatar');
            }
            $avatar = $imported;
        }

        $id = Profile::create([
            'name'          => $data['name'],
            'description'   => $data['description'],
            'system_prompt' => $data['system_prompt'],
            'default_llm'   => $data['default_llm'],
            'is_active'     => 1,
            'color'         => $data['color'],
            'temperature'   => $data['temperature'] !== null ? (string)$data['temperature'] : null,
            'avatar'        => $avatar,
            'created_by'    => $userId,
            'is_personal'   => 1,
        ]);
        Profile::setTools($id, self::sanitizeToolIds($data['tool_ids']));
        KnowledgeBase::setForProfile($id, $data['kb_ids'], array_keys(self::kbScope($user)));
        return $id;
    }

    /**
     * Dernière proposition d'assistant encore en attente dans cette
     * conversation (base d'une proposition révisée : « mets-le en bleu »
     * reprend tout le reste). Null s'il n'y en a pas.
     */
    public static function latestPending(int $convId, int $userId): ?array
    {
        return DrivePendingAction::latestPendingForTool($convId, $userId, self::TOOL);
    }

    /**
     * Rappel, pour le modèle, de la proposition EN ATTENTE dans la
     * conversation : les résultats d'outils ne reviennent pas d'un message à
     * l'autre, et sans ce rappel « plus formel » ou « mets-le en vert »
     * faisaient réécrire DE MÉMOIRE un prompt que le modèle ne voit plus
     * (constaté en essai réel : tout était renvoyé, le prompt changé). Le
     * prompt est donné en entier : une retouche qui le concerne part de lui.
     * Données écrites par le modèle lui-même au tour précédent. Pure.
     *
     * @param array $args arguments de la carte en attente
     */
    public static function pendingNote(array $args, array $choices): string
    {
        $prefill = self::formPrefill($args);
        $names = static fn(array $ids, array $catalog): string => $ids === [] ? 'aucune' : implode(', ', array_map(
            static fn(int $id): string => $id . ' = « ' . (string)($catalog[$id]['name'] ?? '?') . ' »',
            $ids
        ));
        $avatar = $prefill['avatar_generated'] !== ''
            ? 'créé pour lui (' . $prefill['avatar_generated'] . ')'
            : ($prefill['avatar'] !== '' ? $prefill['avatar'] : 'aucun');
        return "\n\nProposition d'assistant EN ATTENTE dans cette conversation (carte affichée, pas encore approuvée). "
            . "Les demandes de changement portent sur elle :\n"
            . '- nom : ' . $prefill['name'] . "\n"
            . '- description : ' . ($prefill['description'] !== '' ? $prefill['description'] : '(aucune)') . "\n"
            . '- modèle : ' . ($prefill['default_llm'] !== '' ? $prefill['default_llm'] : "celui de l'application") . "\n"
            . '- température : ' . ($prefill['temperature'] ?? 'par défaut') . "\n"
            . '- couleur : ' . ($prefill['color'] !== '' ? $prefill['color'] : '(aucune)') . "\n"
            . '- bases : ' . $names($prefill['kb_ids'], (array)($choices['kbs'] ?? [])) . "\n"
            . '- outils : ' . $names($prefill['tool_ids'], (array)($choices['tools'] ?? [])) . "\n"
            . '- avatar : ' . $avatar . "\n"
            . "- prompt système :\n<<<\n" . $prefill['system_prompt'] . "\n>>>\n"
            . "Pour la changer : `creer_assistant` avec `remplace: true` et UNIQUEMENT les champs modifiés. "
            . "Ne renvoie `prompt_systeme` que si la demande porte sur lui, et alors en partant de ce texte (corrigé, "
            . "jamais réécrit de mémoire). Pour un assistant DIFFÉRENT, sans `remplace`.";
    }

    /** Une proposition d'assistant attend-elle dans cette conversation ? */
    public static function hasPending(int $convId, int $userId): bool
    {
        return DrivePendingAction::hasPendingForTool($convId, $userId, self::TOOL);
    }

    /**
     * Proposition remplacée par une version révisée : la carte d'avant est
     * close (annulée), sans quoi un « Approuver » tardif créerait un doublon.
     */
    public static function supersede(int $actionId, int $userId, int $convId): bool
    {
        return DrivePendingAction::supersede($actionId, $userId, $convId);
    }

    // ------------------------------------------------------------------
    // Avatar créé par MIA
    // ------------------------------------------------------------------

    /**
     * Outil image avec lequel MIA crée un avatar pour ce compte : le premier
     * que ses groupes lui ouvrent, en service (modèle non arrêté, dernier test
     * non raté) et muni d'une clé. Null = pas d'avatar créé, la bibliothèque
     * reste proposée.
     */
    public static function avatarImageTool(array $user, array $activeGroupIds, bool $hasAssignments): ?array
    {
        foreach (ChatTool::activeByUser('image', (string)$user['role'], $activeGroupIds, $hasAssignments) as $tool) {
            if (isset($tool['last_test_ok']) && (int)$tool['last_test_ok'] === 0) {
                continue;
            }
            if (ChatTool::isRetired($tool)) {
                continue;
            }
            if (empty($tool['api_key_enc']) && ApiKey::findByProvider((string)$tool['provider']) === null) {
                continue;
            }
            return $tool;
        }
        return null;
    }

    /**
     * L'outil image réglé pour un avatar : image carrée (taille ou format
     * 1:1), le reste de sa configuration (qualité, clé) inchangé. Pure.
     */
    public static function squareTool(array $tool): array
    {
        $config = json_decode((string)($tool['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $config['size']         = '1024x1024';
        $config['aspect_ratio'] = '1:1';
        $tool['config_json'] = (string)json_encode($config, JSON_UNESCAPED_UNICODE);
        return $tool;
    }

    /**
     * Adresse d'une image générée, ramenée à sa forme canonique (« /assets/
     * img/generated/<fichier> », signature ou paramètres retirés) ; null si ce
     * n'en est pas une. Pure.
     */
    public static function generatedUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (!str_starts_with($path, self::GENERATED_DIR_WEB)) {
            return null;
        }
        $file = substr($path, strlen(self::GENERATED_DIR_WEB));
        if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$/i', $file) !== 1 || str_contains($file, '..')) {
            return null;
        }
        return self::GENERATED_DIR_WEB . $file;
    }

    /** Fichier d'une image générée, confiné à son dossier ; null s'il n'existe pas. */
    public static function generatedImagePath(string $url): ?string
    {
        $canonical = self::generatedUrl($url);
        if ($canonical === null) {
            return null;
        }
        $base = realpath(ROOT . '/public' . rtrim(self::GENERATED_DIR_WEB, '/'));
        $real = realpath(ROOT . '/public' . $canonical);
        if ($base === false || $real === false || !is_file($real)
            || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Images générées d'une conversation qu'une proposition peut prendre pour
     * avatar (« prends l'image que tu viens de faire ») : celles des réponses
     * de MIA et celles des cartes d'assistant déjà proposées. Jamais l'image
     * d'une autre conversation.
     *
     * @return list<string>
     */
    public static function conversationImages(int $convId, int $userId): array
    {
        $db   = Database::getInstance();
        $urls = [];
        $stmt = $db->prepare(
            'SELECT m.content FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.conversation_id = ? AND c.user_id = ? AND m.role = \'assistant\'
               AND m.content LIKE ? ORDER BY m.id DESC LIMIT 50'
        );
        $stmt->execute([$convId, $userId, '%' . self::GENERATED_DIR_WEB . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $content) {
            if (preg_match_all('#' . preg_quote(self::GENERATED_DIR_WEB, '#') . '[A-Za-z0-9._-]+#', (string)$content, $m)) {
                array_push($urls, ...$m[0]);
            }
        }
        $stmt = $db->prepare(
            'SELECT args_json FROM drive_pending_actions
             WHERE conversation_id = ? AND user_id = ? AND tool = ? ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([$convId, $userId, self::TOOL]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $args = json_decode((string)$json, true);
            if (is_array($args) && ($u = self::generatedUrl((string)($args['avatar_generated'] ?? ''))) !== null) {
                $urls[] = $u;
            }
        }
        return array_values(array_unique(array_filter(array_map(
            static fn(string $u): ?string => self::generatedUrl($u),
            $urls
        ))));
    }

    // ------------------------------------------------------------------
    // Garde lexicale
    // ------------------------------------------------------------------

    /**
     * Le message demande-t-il de CRÉER un assistant ? Garde de l'outil
     * `creer_assistant` (FR/EN), précise comme celle des tâches programmées :
     * vraie, elle engage la boucle d'outils pour ce message — et, sur un
     * modèle qui ne cumule pas recherche Internet et outils (Mistral), elle
     * écarte la recherche de cette réponse. D'où un verbe de création ou un
     * souhait, suivi de près par « assistant » : « l'assistant de direction
     * m'a écrit » ou « quel assistant choisir ? » n'en sont pas. Pure.
     */
    public static function looksLikeAssistantRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        $noun  = '(?:assistant|assistante|assistants|chatbot|agent|gpt)';
        $det   = '(?:\s+(?:un|une|mon|ma|notre|nos|des|le|la|les|cet|cette|ce|l[\'’]))?';
        $adj   = '(?:\s*(?:nouvel|nouveau|nouvelle|petit|petite|autre|second|seconde|deuxi[èe]me|vrai|propre))?';
        $verbs = 'cr[ée]e|cr[ée]er|cr[ée]ez|cr[ée]ons|fais|faire|faites|fabrique|fabriquer|monte|monter|montez'
            . '|configure|configurer|configurez|construis|construire|construisez|con[çc]ois|concevoir|concevez'
            . '|pr[ée]pare|pr[ée]parer|pr[ée]parez|g[ée]n[èe]re|g[ée]n[ée]rer|mets\s+en\s+place|mettre\s+en\s+place'
            . '|param[èe]tre|param[ée]trer|d[ée]finis|d[ée]finir|imagine|imaginer|propose|proposer|proposez';
        $patterns = [
            // « crée-moi un assistant », « configure un nouvel assistant »,
            // « peux-tu préparer un assistant », « fais moi un chatbot »
            '(?:' . $verbs . ')(?:-(?:moi|nous|lui)|\s+(?:moi|nous))?' . $det . $adj . '\s*' . $noun,
            // « je voudrais un assistant », « j'aimerais un nouvel assistant », « il me faut un assistant »
            '(?:voudrais|veux|voulons|aimerais|aimerions|souhaite|souhaiterais|souhaitons|faudrait|faut)\s+(?:un|une|mon|notre)' . $adj . '\s*' . $noun,
            '(?:besoin|envie)\s+d[\'’]\s*(?:un|une)' . $adj . '\s*' . $noun,
            // « Un nouvel assistant pour les RH » (en tête de message ou après
            // « un » : « mon nouvel assistant de direction arrive lundi » n'en
            // est pas un)
            '(?:^[^\p{L}]*|un\s+)(?:nouvel|nouveau)\s+(?:assistant|chatbot)',
            // English
            '(?:create|build|make|set\s+up|design|configure|generate)(?:\s+me)?\s+(?:a|an|my|our|new|a\s+new|another)(?:\s+(?:new|custom|dedicated))?\s+(?:assistant|agent|chatbot|gpt)',
            '(?:i|we)\s+(?:want|need|would\s+like|\'d\s+like)\s+(?:a|an)(?:\s+(?:new|custom|dedicated))?\s+(?:assistant|chatbot)',
            'new\s+(?:assistant|chatbot)',
        ];
        foreach ($patterns as $motif) {
            if (preg_match('/(?<![\p{L}\p{N}])(?:' . $motif . ')(?![\p{L}\p{N}])/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /** Une ligne, sans contrôle, bornée. Pure. */
    private static function oneLine(string $value, int $max): string
    {
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)) : $value;
    }

    /** Minuscules sans accents ni ponctuation de bord (comparaison de noms). Pure. */
    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $plain = class_exists('Normalizer')
            ? (string)preg_replace('/\p{Mn}+/u', '', (string)\Normalizer::normalize($value, \Normalizer::FORM_D))
            : $value;
        return trim((string)preg_replace('/[\s«»"\'’]+/u', ' ', $plain));
    }

    /** Nombre au séparateur décimal de la langue (« 0,7 » en français). Pure. */
    private static function number(float $value, int $decimals): string
    {
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
        return I18n::locale() === 'fr' ? str_replace('.', ',', $text) : $text;
    }
}
