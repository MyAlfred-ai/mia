<?php

declare(strict_types=1);

/**
 * Schéma d'un assistant — le modèle qui répond (à gauche, avec sa
 * température), l'assistant lui-même (au centre : nom, description, extrait
 * du prompt système, actif ou non, qui le voit), et ce qu'il consulte et
 * engage (en dessous : bases de connaissances, documents de référence,
 * outils). Affiché à droite de la liste quand on clique un assistant, et à
 * droite de sa fiche — création comme modification —, tenu à jour pendant la
 * saisie.
 *
 * Le pendant de TaskDiagram, SANS déclencheur : UN SEUL RENDU, dans le
 * navigateur (public/assets/js/diagram.js, le moteur partagé avec le schéma
 * d'une tâche ; public/assets/js/assistant-diagram.js, ce qu'on montre d'un
 * assistant). Mêmes teintes, mêmes pictogrammes, mêmes flèches. Ce fichier ne
 * fournit que des DONNÉES :
 *
 *  - `catalog()` : de quoi nommer et illustrer chaque élément — libellés,
 *    icônes de l'application (celles du schéma des tâches et du menu
 *    d'administration, jamais redessinées), modèles, bases, outils ;
 *  - `spec()` : la configuration d'UN assistant, sous la forme même que la
 *    fiche compose depuis ses champs ;
 *  - `docEntry()` : un document de référence tel que la fiche le reçoit —
 *    source unique, partagée par la liste et la fiche.
 *
 * Libellés dans la langue de la personne, par t() — comme la page des
 * assistants qui porte le schéma (la liste comme la fiche), et comme le
 * schéma des tâches (TaskDiagram). Les constantes de classe (capacités des
 * outils, sources synchronisées) ne peuvent pas appeler t() : elles sont
 * traduites à l'accesseur (toolEntry, kbEntry). Les nombres du schéma
 * (température, tokens) suivent la même langue (`locale` du catalogue).
 *
 * Le modèle affiché suit la règle du chat (ChatController::resolveProfileDefaultLlm) :
 * celui de l'assistant s'il est encore actif, sinon le modèle par défaut de
 * l'application ; un assistant sans modèle suit ce défaut dans ses
 * changements.
 */
final class AssistantDiagram
{
    /** Éléments montrés par nœud du bas ; au-delà, « + N autres ». */
    public const MAX_ITEMS = 5;

    /** Caractères du prompt système que le script reçoit pour l'extrait (il en garde moins). */
    public const PROMPT_CHARS = 600;

    /**
     * Prompt brut transmis par assistant de la liste : de quoi composer
     * l'extrait une fois les titres de section retirés.
     */
    public const PROMPT_SOURCE_CHARS = 1200;

    /**
     * Pictogrammes propres au schéma de l'assistant — ceux des pastilles de
     * la liste (température, documents) et de qui le voit.
     */
    private const TRAIT_ICONS = [
        // Température : le thermomètre des pastilles de la liste.
        'temperature' => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        // Personnel : une personne.
        'personal'    => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        // Partagé (organisation) : le groupe du menu « Utilisateurs ».
        'org'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        // Global : le globe.
        'global'      => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    ];

    /** Document de référence : la feuille des pastilles de la liste. */
    private const DOC_PATHS = '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>';

    /** Document image : le cadre de l'outil image. */
    private const IMAGE_PATHS = '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>';

    /**
     * Ce que l'assistant peut déclencher (cf. AdminController::sanitizeProfileToolIds) :
     * les intitulés des capacités de la fiche. Texte source FRANÇAIS, traduit
     * à l'accesseur (toolEntry) — une constante ne peut pas appeler t().
     */
    private const TOOL_KINDS = [
        'image' => 'Génération d’image',
        'n8n'   => 'Automatisations n8n',
        'gamma' => 'Présentations Gamma',
    ];

    /**
     * Sources synchronisées d'une base (cf. KbSync) : leur nom d'usage —
     * traduit à l'accesseur (kbEntry) : « Google Agenda » est « Google
     * Calendar » en anglais, les autres sont des noms de produit.
     */
    private const SYNC_SOURCES = [
        'drive'      => 'Google Drive',
        'onedrive'   => 'OneDrive',
        'sharepoint' => 'SharePoint',
        'gcalendar'  => 'Google Agenda',
    ];

    /**
     * Tout ce que le script sait nommer et dessiner. `$kbs` = lignes de
     * KnowledgeBase (forManager) que le gestionnaire peut voir — une base liée
     * hors de ce périmètre reste COMPTÉE, sans son nom ; `$tools` = lignes de
     * chat_tools liées ou liables.
     *
     * @param list<array<string, mixed>> $kbs
     * @param list<array<string, mixed>> $tools
     */
    public static function catalog(array $kbs, array $tools): array
    {
        $logos  = LLMFactory::logos();
        $all    = LLMFactory::available();
        $llms   = [];
        foreach ($all as $slug => $label) {
            $provider = LLMFactory::provider((string)$slug);
            $llms[(string)$slug] = [
                'label'    => (string)$label,
                'provider' => LLMFactory::providerLabel($provider),
                'logo'     => (string)($logos[$provider] ?? ''),
                // Le catalogue dit si le modèle accepte une température :
                // la fiche grise le curseur, le schéma le dit.
                'temp'     => LLMFactory::supportsTemperature((string)$slug),
            ];
        }

        $kbMap = [];
        foreach ($kbs as $kb) {
            $kbMap[(int)$kb['id']] = self::kbEntry($kb);
        }

        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[(int)$tool['id']] = self::toolEntry($tool);
        }

        return [
            'labels'       => self::labels(),
            'icons'        => self::icons(),
            'tool_icons'   => [
                'image' => TaskDiagram::toolIcon('image'),
                'n8n'   => TaskDiagram::toolIcon('n8n'),
                'gamma' => TaskDiagram::toolIcon('gamma'),
            ],
            'llms'         => (object)$llms,
            'default_llm'  => (string)(LLMFactory::appDefault($all) ?? ''),
            'kbs'          => (object)$kbMap,
            'tools'        => (object)$toolMap,
            // Nombres dans la langue de la page : « 1 234 », « 0,7 » en
            // français ; « 1,234 », « 0.7 » en anglais.
            'locale'       => self::numberLocale(),
            'max_items'    => self::MAX_ITEMS,
            'prompt_chars' => self::PROMPT_CHARS,
        ];
    }

    /**
     * Locale des nombres du schéma (toLocaleString côté navigateur) : celle
     * de la langue de la personne — même règle que les fichiers Office
     * (CompanyProfile::officeLanguage).
     */
    public static function numberLocale(): string
    {
        return I18n::locale() === 'en' ? 'en-US' : 'fr-FR';
    }

    /**
     * Libellés du schéma, dans la langue de la page. Les substituants
     * ({t}, {n}) sont posés par le script (MiaDiagram.fill).
     */
    public static function labels(): array
    {
        return [
            'aria'             => t('Schéma de l’assistant'),
            'model'            => t('Modèle'),
            'assistant'        => t('Assistant'),
            'untitled'         => t('Nouvel assistant'),
            'active'           => t('Actif'),
            'inactive'         => t('Inactif'),
            'prompt_missing'   => t('Prompt système à écrire'),
            'llm_imposed'      => t('Imposé à ses conversations'),
            'llm_default'      => t('Suit le modèle par défaut'),
            'llm_retired'      => t('Modèle choisi indisponible : le défaut répond'),
            'llm_none'         => t('Aucun modèle disponible'),
            'temp_value'       => t('Température {t}'),
            'temp_default'     => t('Température par défaut'),
            'temp_unsupported' => t('Température non réglable'),
            'temp_ignored'     => t('Température {t} ignorée'),
            'personal'         => t('Personnel'),
            'org'              => t('Partagé'),
            'global'           => t('Global'),
            'kbs'              => t('Bases de connaissances'),
            'docs'             => t('Documents de référence'),
            'tools'            => t('Outils'),
            'kb_hidden'        => t('Base hors de votre périmètre'),
            'kb_hidden_note'   => t('liée par un administrateur'),
            'doc_tokens'       => t('~{n} tokens'),
            'doc_pending'      => t('Extraction en cours…'),
            'doc_failed'       => t('Échec de l’extraction'),
            'doc_image'        => t('Image montrée au modèle'),
            'doc_empty'        => t('Pas de texte extrait'),
            'doc_new'          => t('Ajouté à la création'),
            'more_one'         => t('+ {n} autre'),
            'more_many'        => t('+ {n} autres'),
            'no_sources'       => t('Ni base, ni document, ni outil : l’assistant répond avec son prompt et ce que sait le modèle.'),
        ];
    }

    /**
     * Icônes du schéma : celles du schéma des tâches pour ce que les deux
     * partagent (modèle, assistant, bases, outils, base en direct, crayon) —
     * donc celles du menu d'administration —, plus les pastilles propres à
     * l'assistant.
     */
    public static function icons(): array
    {
        $task  = TaskDiagram::icons();
        $svg   = static fn(string $paths, int $size): string =>
            '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

        $icons = [
            'model'     => $task['model'],
            'assistant' => $task['assistant'],
            'kbs'       => $task['kbs'],
            'tools'     => $task['tools'],
            'kb'        => $task['kb'],
            'kb_live'   => $task['kb_live'],
            'edit'      => $task['edit'],
            'docs'      => $svg(self::DOC_PATHS, 15),
            'doc'       => $svg(self::DOC_PATHS, 14),
            'doc_image' => $svg(self::IMAGE_PATHS, 14),
        ];
        foreach (self::TRAIT_ICONS as $key => $paths) {
            $icons[$key] = $svg($paths, 13);
        }
        return $icons;
    }

    /**
     * Une base telle que le schéma la nomme : son nom, et ce qu'il faut en
     * savoir — inactive (le chat ne l'interroge pas), en direct (MyBrain, pour
     * les seules personnes qui y ont accès), système, synchronisée, ou son
     * nombre de documents. Pure.
     *
     * @param array<string, mixed> $kb ligne de KnowledgeBase
     * @return array{name: string, live: bool, note: string, warn: bool}
     */
    public static function kbEntry(array $kb): array
    {
        $live = KnowledgeBase::isRemote($kb);
        $docs = (int)($kb['doc_count'] ?? 0);
        // Abrégé comme les pastilles de la liste (« 3 docs ») : la note tient sur une ligne.
        $docLabel = $docs === 0 ? t('aucun document') : I18n::plural($docs, '{n} doc', '{n} docs');
        $sync = !empty($kb['sync_enabled']) ? (self::SYNC_SOURCES[(string)($kb['sync_provider'] ?? '')] ?? null) : null;

        if (empty($kb['is_active'])) {
            $note = t('Inactive — non interrogée');
        } elseif ($live) {
            $note = t('MyBrain · pour ses groupes seulement');
        } elseif ((int)($kb['is_system'] ?? 0) === 1) {
            $note = t('Base système');
        } elseif ($sync !== null) {
            $note = t($sync) . ' · ' . $docLabel;
        } else {
            $note = ucfirst($docLabel);
        }

        return [
            'name' => (string)($kb['name'] ?? ''),
            'live' => $live,
            'note' => $note,
            'warn' => empty($kb['is_active']),
        ];
    }

    /**
     * Un outil tel que le schéma le nomme : son nom, la capacité qu'il donne,
     * et s'il est éteint (l'assistant ne peut plus s'en servir). Pure.
     *
     * @param array<string, mixed> $tool ligne de chat_tools
     * @return array{name: string, type: string, note: string, warn: bool}
     */
    public static function toolEntry(array $tool): array
    {
        $type   = (string)($tool['type'] ?? '');
        $active = !empty($tool['is_active']);
        return [
            'name' => (string)($tool['name'] ?? ''),
            'type' => $type,
            'note' => $active ? (isset(self::TOOL_KINDS[$type]) ? t(self::TOOL_KINDS[$type]) : '') : t('Outil désactivé'),
            'warn' => !$active,
        ];
    }

    /**
     * Qui voit l'assistant — les mots de la liste : global (tout le monde,
     * sans filtrage de groupe), partagé (organisation, via les groupes),
     * personnel (son seul créateur). Pure.
     */
    public static function sharingOf(array $profile): string
    {
        if (!empty($profile['is_global'])) {
            return 'global';
        }
        return !empty($profile['is_personal']) ? 'personal' : 'org';
    }

    /**
     * Avatar d'un assistant, en adresse de la bibliothèque d'avatars
     * (le nom de fichier seul : aucun chemin n'en sort). Pure.
     */
    public static function avatarUrl(?string $avatar): string
    {
        $file = basename(trim((string)$avatar));
        return $file === '' ? '' : '/assets/img/profils/' . rawurlencode($file);
    }

    /**
     * Un document de référence tel que la fiche le reçoit (et que le schéma
     * le lit) : la liste et la fiche passent par ici. Pure.
     *
     * @param array<string, mixed> $doc ligne de ProfileDocument::allForProfile
     * @return array{id: int, file_name: string, mime_type: string, file_size: int, token_count: int, has_text: bool, extraction_status: string}
     */
    public static function docEntry(array $doc): array
    {
        return [
            'id'                => (int)$doc['id'],
            'file_name'         => (string)$doc['file_name'],
            'mime_type'         => (string)$doc['mime_type'],
            'file_size'         => (int)$doc['file_size'],
            'token_count'       => (int)$doc['token_count'],
            'has_text'          => (int)($doc['has_text'] ?? 0) === 1,
            'extraction_status' => (string)($doc['extraction_status'] ?? 'done'),
        ];
    }

    /**
     * Configuration d'un assistant ENREGISTRÉ telle que le script la lit — la
     * forme que la fiche compose depuis ses champs. Pure.
     *
     * @param array<string, mixed>      $profile ligne de Profile
     * @param list<int>                 $kbIds   bases liées (toutes : hors périmètre comprises)
     * @param list<int>                 $toolIds outils liés
     * @param list<array<string, mixed>> $docs   documents de référence (ProfileDocument::allForProfile)
     */
    public static function spec(array $profile, array $kbIds, array $toolIds, array $docs): array
    {
        $color = (string)($profile['color'] ?? '');
        $temp  = $profile['temperature'] ?? null;
        return [
            'name'        => trim((string)($profile['name'] ?? '')),
            'description' => mb_substr(trim((string)($profile['description'] ?? '')), 0, 240),
            'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '',
            'avatar'      => self::avatarUrl((string)($profile['avatar'] ?? '')),
            'prompt'      => mb_substr((string)($profile['system_prompt'] ?? ''), 0, self::PROMPT_SOURCE_CHARS),
            'llm'         => trim((string)($profile['default_llm'] ?? '')),
            'temperature' => ($temp === null || $temp === '') ? '' : (string)$temp,
            'active'      => !empty($profile['is_active']),
            'sharing'     => self::sharingOf($profile),
            'kb_ids'      => array_values(array_unique(array_map('intval', $kbIds))),
            'tool_ids'    => array_values(array_unique(array_map('intval', $toolIds))),
            'docs'        => array_values(array_map([self::class, 'docEntry'], $docs)),
        ];
    }
}
