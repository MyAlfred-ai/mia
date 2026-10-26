<?php

declare(strict_types=1);

/**
 * Schéma d'une base de connaissances — ce qui l'alimente (au-dessus : fichiers
 * déposés, dossier synchronisé, cerveau MyBrain), comment elle est cherchée (à
 * gauche : le modèle d'embedding, ou « en direct »), la base elle-même (au
 * centre : nom, description, état, active ou non) et ce qu'elle sert (en
 * dessous : les assistants liés et les personnes ou groupes avec qui elle est
 * partagée). Affiché à droite de la liste quand on clique une base, et à
 * droite de sa fiche — création comme modification —, tenu à jour pendant la
 * saisie.
 *
 * Le pendant de TaskDiagram et AssistantDiagram : UN SEUL RENDU, dans le
 * navigateur (public/assets/js/diagram.js, le moteur partagé ;
 * public/assets/js/kb-diagram.js, ce qu'on montre d'une base). Mêmes teintes,
 * mêmes pictogrammes, mêmes flèches — les bases y sont bleues, comme dans le
 * schéma d'un assistant. Ce fichier ne fournit que des DONNÉES :
 *
 *  - `catalog()` : de quoi nommer et illustrer chaque élément — libellés,
 *    icônes, sources, modèles d'embedding, assistants, personnes, groupes ;
 *  - `spec()` : la configuration d'UNE base, sous la forme même que la fiche
 *    compose depuis ses champs ;
 *  - `SOURCES` : le vocabulaire visuel des sources (nom, image, ce que c'est),
 *    SOURCE UNIQUE de la liste (filtres, pastille des cartes), de la fiche et
 *    du schéma — deux tables divergeraient au premier fournisseur ajouté.
 *    Ses textes sont la CLÉ française : on les affiche par les accesseurs
 *    traduits `sources()`, `sourceLabel()` et `sourceWhat()` (une constante ne
 *    peut pas appeler t()).
 *
 * Libellés dans la langue de la page (t()), comme TaskDiagram : la fiche et
 * la liste des bases sont traduites, le schéma parle la langue de l'écran
 * qui le porte.
 */
final class KbDiagram
{
    /** Éléments montrés par nœud ; au-delà, « + N autres ». */
    public const MAX_ITEMS = 5;

    /** Teinte d'une base : celle des bases dans le schéma d'un assistant. */
    public const HUE = '#3b82f6';

    /**
     * Sources d'une base. `drive` … `gcalendar` sont les fournisseurs
     * synchronisés (KbSync::PROVIDERS) ; `mybrain` n'est pas une
     * synchronisation mais une base EN DIRECT (backend = mybrain), rangée ici
     * parce que filtres, pastilles et schéma parlent de la SOURCE.
     */
    public const SOURCES = [
        'drive'      => ['label' => 'Google Drive',  'logo' => '/assets/img/google-drive.svg',    'what' => 'un dossier Google Drive'],
        'sharepoint' => ['label' => 'SharePoint',    'logo' => '/assets/img/sharepoint.svg',      'what' => 'un dossier SharePoint'],
        'gcalendar'  => ['label' => 'Google Agenda', 'logo' => '/assets/img/google-calendar.svg', 'what' => 'un agenda Google Agenda'],
        'onedrive'   => ['label' => 'OneDrive',      'logo' => '/assets/img/onedrive.svg',        'what' => 'un dossier OneDrive'],
        'mybrain'    => ['label' => 'MyBrain',       'logo' => '/assets/img/mybrain.png',         'what' => 'un cerveau MyBrain, interrogé en direct'],
    ];

    /**
     * Sources, libellés TRADUITS (nom et « ce que c'est ») — la forme de
     * SOURCES, pour ce qui s'affiche. Les noms de produit restent tels quels.
     *
     * @return array<string, array{label: string, logo: string, what: string}>
     */
    public static function sources(): array
    {
        $out = [];
        foreach (self::SOURCES as $key => $source) {
            $out[$key] = ['label' => t($source['label']), 'logo' => $source['logo'], 'what' => t($source['what'])];
        }
        return $out;
    }

    /** Nom affiché d'une source (« Google Agenda » → « Google Calendar » en anglais) ; clé inconnue : la clé. */
    public static function sourceLabel(string $key): string
    {
        return isset(self::SOURCES[$key]) ? t(self::SOURCES[$key]['label']) : $key;
    }

    /** Ce qu'est une source, traduit (« un dossier Google Drive ») ; clé inconnue : ''. */
    public static function sourceWhat(string $key): string
    {
        return isset(self::SOURCES[$key]) ? t(self::SOURCES[$key]['what']) : '';
    }

    /** Pictogrammes propres au schéma d'une base (tracés 24×24, trait). */
    private const PATHS = [
        // La base : le livre ouvert du menu « Bases de connaissances ».
        'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        // Fichiers déposés : la flèche montante des cartes de la liste.
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        // Vectorisation : des couches.
        'layers'   => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        // Recherche en direct : la loupe.
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        // Partage : le groupe du menu « Utilisateurs ».
        'group'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'person'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        // Administrateurs : le bouclier du menu du compte.
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        // Pastilles : dimensions, extraits, documents, utilisations, base système.
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'lines'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'doc'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'pulse'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        // États (pastille d'état de la base) : ceux du panneau d'état.
        'state_ok'    => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'state_busy'  => '<path d="M21 12a9 9 0 1 1-9-9c2.5 0 4.9 1 6.7 2.7L21 8"/><path d="M21 3v5h-5"/>',
        'state_warn'  => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'state_error' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>',
        'state_idle'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];

    /**
     * Tout ce que le script sait nommer et dessiner.
     *
     * @param list<array<string, mixed>> $profiles assistants du périmètre du gestionnaire (Profile::forManager)
     * @param list<array<string, mixed>> $users    personnes à qui une base peut être partagée (id, name)
     * @param list<array<string, mixed>> $groups   groupes (id, name, is_active)
     */
    public static function catalog(array $profiles, array $users, array $groups, int $intervalMin, bool $qdrantUp): array
    {
        $assistants = [];
        foreach ($profiles as $profile) {
            $assistants[(int)$profile['id']] = self::assistantEntry($profile);
        }
        $userMap = [];
        foreach ($users as $user) {
            $userMap[(int)$user['id']] = (string)($user['name'] ?? '');
        }
        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[(int)$group['id']] = ['name' => (string)($group['name'] ?? ''), 'active' => !empty($group['is_active'])];
        }

        $sources = [];
        foreach (self::SOURCES as $key => $source) {
            $sources[$key] = [
                'label' => self::sourceLabel((string)$key),
                'logo'  => $source['logo'],
                'icon'  => '<img src="' . $source['logo'] . '" width="16" height="16" alt="">',
            ];
        }

        $logos      = LLMFactory::logos();
        $embeddings = [];
        foreach (EmbeddingService::catalog() as $provider => $entry) {
            foreach ((array)($entry['models'] ?? []) as $model => $meta) {
                $embeddings[$provider . '::' . $model] = [
                    // « OpenAI 3-large (1536d) » : les dimensions ont leur pastille.
                    'label'    => self::embeddingLabel((string)$provider, (string)$model),
                    'model'    => (string)$model,
                    'logo'     => (string)($logos[$provider] ?? ''),
                    'dims'     => (int)($meta['dims'] ?? 0),
                ];
            }
        }

        return [
            'labels'     => self::labels(),
            'icons'      => self::icons(),
            'sources'    => $sources,
            'embeddings' => (object)$embeddings,
            'assistants' => (object)$assistants,
            'users'      => (object)$userMap,
            'groups'     => (object)$groupMap,
            'interval'   => max(1, $intervalMin),
            'qdrant_up'  => $qdrantUp,
            'hue'        => self::HUE,
            // Nombres et tri dans la langue de la page : « 1 234 » / « 1,234 ».
            'locale'     => I18n::locale() === 'en' ? 'en-US' : 'fr-FR',
            'max_items'  => self::MAX_ITEMS,
        ];
    }

    /** Nom d'un modèle d'embedding sans ses dimensions (« OpenAI 3-large »). Pure. */
    public static function embeddingLabel(string $provider, string $model): string
    {
        return trim((string)preg_replace('/\s*\(\d+\s*d\)$/i', '', EmbeddingService::label($provider, $model)));
    }

    /** Libellés du schéma, dans la langue de la page (cf. en-tête). */
    public static function labels(): array
    {
        return [
            'aria'              => t('Schéma de la base de connaissances'),
            'base'              => t('Base de connaissances'),
            'untitled'          => t('Nouvelle base'),
            'active'            => t('Active'),
            'inactive'          => t('Inactive'),
            'system'            => t('Base système'),
            'shared_read'       => t('Partagée avec vous · lecture'),
            'shared_manage'     => t('Partagée avec vous'),
            'docs_none'         => t('Aucun document'),
            'docs_one'          => t('1 document'),
            'docs_many'         => t('{n} documents'),
            'usage_none'        => t('Jamais utilisée'),
            'usage_one'         => t('1 utilisation'),
            'usage_many'        => t('{n} utilisations'),
            // Ce qui l'alimente.
            'sources'           => t('Sources'),
            'sources_empty'     => t('Aucune source pour l’instant : déposez des fichiers ou reliez un dossier.'),
            'files'             => t('Fichiers déposés'),
            'file_new'          => t('Vectorisé à la création'),
            'folder'            => t('Dossier « {name} »'),
            'folder_linked'     => t('Dossier relié'),
            'folder_missing'    => t('Dossier à choisir'),
            'calendar'          => t('Agenda « {name} »'),
            'calendar_linked'   => t('Agenda relié'),
            'calendar_missing'  => t('Agenda à choisir'),
            'sync_auto'         => t('Auto'),
            'sync_manual'       => t('Manuelle'),
            'sync_every'        => t('Synchronisée toutes les {n} min'),
            'sync_off'          => t('Synchronisation automatique désactivée'),
            'synced_one'        => t('1 document repris'),
            'synced_many'       => t('{n} documents repris'),
            'via_owner'         => t('Lu avec la connexion de {name}'),
            'live_title'        => t('Cerveau MyBrain'),
            'live_status'       => t('En direct'),
            'live_missing'      => t('Adresse à renseigner'),
            'live_app'          => t('Application « {app} »'),
            'live_hint'         => t('Interrogé à chaque message'),
            // Comment elle est cherchée.
            'vector'            => t('Vectorisation'),
            'vector_none'       => t('Aucun modèle disponible'),
            'vector_note'       => t('Recherche par le sens'),
            'vector_create'     => t('Changeable plus tard, en réindexant'),
            'qdrant_down'       => t('Moteur vectoriel indisponible : recherche par mots'),
            'dims'              => t('{n} dimensions'),
            'chunks_none'       => t('Aucun extrait'),
            'chunks_one'        => t('1 extrait'),
            'chunks_many'       => t('{n} extraits'),
            'search'            => t('Recherche'),
            'live'              => t('En direct'),
            'live_provider'     => 'MyBrain', // nom de produit
            'live_note'         => t('Rien n’est copié dans MIA'),
            // Ce qu'elle sert.
            'assistants'        => t('Assistants liés'),
            'assistants_none'   => t('Aucun assistant lié : la base se coche aussi dans le chat.'),
            'hidden_one'        => t('1 assistant hors de votre périmètre'),
            'hidden_many'       => t('{n} assistants hors de votre périmètre'),
            'hidden_note'       => t('lié par un administrateur'),
            'personal'          => t('Personnel'),
            'org'               => t('Partagé'),
            'global'            => t('Global'),
            'assistant_off'     => t('Assistant inactif'),
            'sharing'           => t('Partage'),
            'private'           => t('Privée'),
            'shared'            => t('Partagée'),
            'everyone'          => t('Tout le monde'),
            'everyone_note'     => t('Base de l’assistant d’aide de MIA'),
            'you'               => t('Vous'),
            'owner'             => t('Propriétaire'),
            'creator_unknown'   => t('Créateur supprimé'),
            'admins'            => t('Administrateurs'),
            'admins_note'       => t('Voient et gèrent toutes les bases'),
            'can_manage'        => t('Peut modifier'),
            'can_read'          => t('Lecture et rattachement'),
            'live_query'        => t('Interroge le cerveau'),
            'live_default'      => t('cochée par défaut'),
            'live_feed'         => t('peut nourrir'),
            'group_off'         => t('Groupe inactif : partage sans effet'),
            'user_unknown'      => t('Utilisateur #{id}'),
            'group_unknown'     => t('Groupe #{id}'),
            'shared_you_read'   => t('Partagée avec vous en lecture'),
            'shared_you_manage' => t('Partagée avec vous en modification'),
            'more_one'          => t('+ {n} autre'),
            'more_many'         => t('+ {n} autres'),
        ];
    }

    /**
     * Icônes du schéma : celles du schéma des tâches pour ce que les trois
     * schémas partagent (assistant, crayon), celle du menu pour la base,
     * plus les pictogrammes propres à une base.
     */
    public static function icons(): array
    {
        $task = TaskDiagram::icons();
        $svg  = static fn(string $paths, int $size): string =>
            '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

        $trait = static fn(string $path): string => $svg(self::PATHS[$path], 13);

        return [
            'base'          => $task['kbs'],
            'glyph'         => $svg(self::PATHS['book'], 20),
            'assistants'    => $task['assistant'],
            'assistant'     => AdminMenu::icon('profiles', 14),
            'edit'          => $task['edit'],
            'upload'        => $svg(self::PATHS['upload'], 15),
            'vector'        => $svg(self::PATHS['layers'], 15),
            'search'        => $svg(self::PATHS['search'], 15),
            'sharing'       => $svg(self::PATHS['group'], 15),
            'person'        => $svg(self::PATHS['person'], 14),
            'group'         => $svg(self::PATHS['group'], 14),
            'admins'        => $svg(self::PATHS['shield'], 14),
            'everyone'      => $svg(self::PATHS['globe'], 14),
            'doc'           => $svg(self::PATHS['doc'], 14),
            'doc_image'     => AssistantDiagram::icons()['doc_image'],
            'trait_dims'    => $trait('grid'),
            'trait_chunks'  => $trait('lines'),
            'trait_docs'    => $trait('doc'),
            'trait_usage'   => $trait('pulse'),
            'trait_system'  => $trait('lock'),
            'trait_shared'  => $trait('person'),
            'state_ok'      => $trait('state_ok'),
            'state_busy'    => $trait('state_busy'),
            'state_warn'    => $trait('state_warn'),
            'state_error'   => $trait('state_error'),
            'state_idle'    => $trait('state_idle'),
        ];
    }

    /**
     * Un assistant tel que le schéma le nomme : son nom, son avatar ou sa
     * couleur, qui le voit — les mots de la liste des assistants. Pure.
     *
     * @param array<string, mixed> $profile ligne de Profile
     * @return array{name: string, avatar: string, color: string, sharing: string, active: bool}
     */
    public static function assistantEntry(array $profile): array
    {
        $color = (string)($profile['color'] ?? '');
        return [
            'name'    => trim((string)($profile['name'] ?? '')),
            'avatar'  => AssistantDiagram::avatarUrl((string)($profile['avatar'] ?? '')),
            'color'   => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '',
            'sharing' => AssistantDiagram::sharingOf($profile),
            'active'  => !empty($profile['is_active']),
        ];
    }

    /**
     * Un fichier déposé tel que le schéma le montre : son nom, sa nature, et
     * son verdict (KbStatus::documentState — celui de la liste des documents).
     * Pure.
     *
     * @param array<string, mixed> $doc ligne de KnowledgeBaseDocument
     * @return array{name: string, image: bool, tone: string, label: string}
     */
    public static function fileEntry(array $doc): array
    {
        $state = KbStatus::documentState($doc);
        return [
            'name'  => (string)($doc['file_name'] ?? ''),
            'image' => str_starts_with((string)($doc['mime_type'] ?? ''), 'image/'),
            'tone'  => (string)$state['tone'],
            'label' => (string)$state['label'],
        ];
    }

    /**
     * Qui a accès à une base, tel que le schéma le montre — le propriétaire
     * voit la liste du partage ; un bénéficiaire ne voit que son propre accès.
     * Pure.
     *
     * @param array{users?: array<int,int>, groups?: array<int,int>}|null $shares    KnowledgeBase::sharesFor (propriétaire seulement)
     * @param array{users?: list<int>, groups?: list<int>}|null           $defaultOn KnowledgeBase::defaultOnFor (base en direct)
     * @param array{users?: list<int>, groups?: list<int>}|null           $canFeed   KnowledgeBase::feedersFor (base en direct)
     */
    public static function access(array $kb, bool $owned, bool $youCreated, ?array $shares, ?array $defaultOn, ?array $canFeed): array
    {
        $flags = static function (array $map, ?array $default, ?array $feed, string $side): array {
            $out = [];
            foreach ($map as $id => $manage) {
                $out[] = [
                    'id'      => (int)$id,
                    'manage'  => (int)$manage === 1,
                    'default' => in_array((int)$id, array_map('intval', (array)($default[$side] ?? [])), true),
                    'feed'    => in_array((int)$id, array_map('intval', (array)($feed[$side] ?? [])), true),
                ];
            }
            return $out;
        };
        $shares = $owned ? ($shares ?? ['users' => [], 'groups' => []]) : ['users' => [], 'groups' => []];
        return [
            'owned'      => $owned,
            'shared'     => (int)($kb['is_shared'] ?? 0) === 1,
            'can_manage' => $owned || (int)($kb['share_can_manage'] ?? 0) === 1,
            'you'        => $youCreated,
            'creator'    => trim((string)($kb['creator_name'] ?? '')),
            'users'      => $flags((array)($shares['users'] ?? []), $defaultOn, $canFeed, 'users'),
            'groups'     => $flags((array)($shares['groups'] ?? []), $defaultOn, $canFeed, 'groups'),
        ];
    }

    /**
     * Configuration d'une base ENREGISTRÉE telle que le script la lit — la
     * forme que la fiche compose depuis ses champs. Pure.
     *
     * @param array<string, mixed>       $kb         ligne de KnowledgeBase (forManager / findById)
     * @param list<array<string, mixed>> $docs       documents de la base (KnowledgeBaseDocument::allForKb)
     * @param list<int>                  $profileIds assistants liés (tous : hors périmètre compris)
     * @param array<string, mixed>       $access     self::access()
     * @param array<string, mixed>|null  $status     KbStatus::summarize (null : pas d'état à montrer)
     */
    public static function spec(array $kb, array $docs, array $profileIds, array $access, ?array $status, string $syncOwnerName = ''): array
    {
        $live     = KnowledgeBase::isRemote($kb);
        $provider = (string)($kb['sync_provider'] ?? '');
        $linked   = (string)($kb['sync_folder_ref'] ?? '') !== '';

        $files  = [];
        $synced = 0;
        foreach ($docs as $doc) {
            if ((string)($doc['source'] ?? 'upload') === 'upload') {
                $files[] = self::fileEntry($doc);
            } else {
                $synced++;
            }
        }

        return [
            'name'          => trim((string)($kb['name'] ?? '')),
            'description'   => mb_substr(trim((string)($kb['description'] ?? '')), 0, 240),
            'active'        => !empty($kb['is_active']),
            'system'        => (int)($kb['is_system'] ?? 0) === 1,
            'live'          => $live,
            'source'        => [
                'provider' => !$live && $provider !== '' && $linked ? $provider : '',
                'linked'   => $linked,
                'folder'   => trim((string)($kb['sync_folder_name'] ?? '')),
                'auto'     => (int)($kb['sync_enabled'] ?? 0) === 1,
                'owner'    => (int)($kb['sync_owner_user_id'] ?? 0) > 0 ? $syncOwnerName : '',
            ],
            'remote'        => $live ? [
                'host' => (string)(parse_url((string)($kb['remote_url'] ?? ''), PHP_URL_HOST) ?? ''),
                'app'  => trim((string)($kb['remote_app_name'] ?? '')),
            ] : null,
            'files'         => $files,
            'synced_total'  => $synced,
            'chunks'        => (int)($kb['chunk_count'] ?? 0),
            'usage'         => (int)($kb['query_count'] ?? 0),
            'embedding'     => $live ? '' : (string)($kb['embedding_provider'] ?? '') . '::' . (string)($kb['embedding_model'] ?? ''),
            'state'         => $status !== null ? [
                'title' => (string)($status['title'] ?? ''),
                'tone'  => (string)($status['tone'] ?? ''),
            ] : null,
            'assistant_ids' => array_values(array_unique(array_map('intval', $profileIds))),
            'access'        => $access,
        ];
    }
}
