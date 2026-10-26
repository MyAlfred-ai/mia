<?php

declare(strict_types=1);

/**
 * Tâche programmée : une consigne confiée à un assistant, rejouée à date fixe
 * (migration 081, Espaces → Tâches programmées).
 *
 * Une tâche APPARTIENT À UNE PERSONNE, jamais à un assistant : elle tourne
 * avec ses connexions, son quota et ses droits du moment. D'où sa place dans
 * les paramètres du compte et non dans la fiche d'un assistant, partagée et
 * gérée par d'autres.
 *
 * Deux sections, comme ConnectorContext : la FORME (validation, phrases,
 * libellés — pure, testée sans base) puis la PERSISTANCE (toujours bornée au
 * propriétaire, sauf les quelques lectures du planificateur, nommées comme
 * telles). L'exécution elle-même vit dans Scheduler.
 */
final class ScheduledTask
{
    /** Réglage d'administration (Outils → carte « Tâches programmées »), activé par défaut. */
    public const SETTING_ENABLED = 'scheduled_tasks_enabled';

    /** Tâches ACTIVES par personne : chacune consomme des crédits sans que personne ne clique. */
    public const MAX_ACTIVE_PER_USER = 5;

    /** Échecs consécutifs avant la mise en pause automatique. */
    public const MAX_FAILURES = 3;

    public const NAME_MAX        = 120;
    public const INSTRUCTION_MAX = 4000;

    /**
     * Durée de vie d'une carte d'approbation déposée par une exécution
     * (option « actions à approuver ») : la personne n'est pas là quand la
     * tâche tourne — 30 minutes, la durée d'une carte ordinaire, la ferait
     * mourir avant son retour. Une semaine couvre une routine hebdomadaire.
     */
    public const ACTION_TTL_MINUTES = 7 * 24 * 60;

    /**
     * Réglage d'administration (Outils → carte « Tâches programmées ») : le
     * mode « Agir sans validation » est-il proposé ? Oui par défaut. Coupé,
     * les tâches réglées ainsi déposent des cartes à approuver, comme le mode
     * « Préparer des actions » — rien n'est perdu, rien ne part seul.
     */
    public const SETTING_AUTO = 'scheduled_tasks_auto_enabled';

    /**
     * Ce que la tâche peut faire (formulaire, « Ce que la tâche peut faire »).
     * En base : `allow_actions` (préparer) et `auto_actions` (exécuter sans
     * carte) — `auto` implique `allow_actions`.
     */
    public const MODE_READ    = 'read';
    public const MODE_APPROVE = 'approve';
    public const MODE_AUTO    = 'auto';

    /**
     * Actions qu'une exécution « sans validation » effectue elle-même, au
     * plus : au-delà, les suivantes attendent l'approbation dans la
     * conversation, comme une carte ordinaire. Une consigne mal comprise, ou
     * un contenu lu qui pousse à agir, ne déclenche pas cinquante envois.
     */
    public const AUTO_ACTIONS_MAX = 10;

    /**
     * Icône des tâches programmées : une horloge cerclée d'une flèche, la
     * ROUTINE. SOURCE UNIQUE du tracé — menus, barre latérale, bandeau,
     * boutons du chat (via window.SCHEDULE_ICON), carte d'administration et
     * glyphe de la trace d'actions (main.css, même tracé en data-URI).
     */
    public const ICON_PATHS = '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M12 7v5l3 2"/>';

    public const DEST_THREAD = 'thread';
    public const DEST_NEW    = 'new';

    /**
     * Outils qu'une tâche peut engager EN PLUS de ceux de son assistant : ceux
     * qu'un tour de conversation sait appeler (function-calling) — image,
     * workflows n8n, présentations Gamma. Les créations à volet (page web,
     * carte, présentation, vidéo) suivent leur propre chemin de génération et
     * ne se programment pas. Un outil par type : le tour n'en appelle qu'un.
     */
    public const TOOL_TYPES = ['image', 'n8n', 'gamma'];

    /**
     * Outils qui AGISSENT (workflow déclenché, présentation facturée) : leur
     * seule voie est la carte d'approbation. Les choisir IMPOSE donc la
     * validation (« actions à approuver ») — sans elle, ils ne feraient rien.
     */
    public const ACTING_TOOL_TYPES = ['n8n', 'gamma'];

    /**
     * Action que dépose chaque outil qui agit (nom dans la file d'approbation)
     * → son type. Ses arguments portent `tool_id`, l'outil qui l'exécutera.
     * En « Agir sans validation », une telle action n'est exécutée seule que
     * si son outil a été CHOISI pour la tâche (Scheduler::autoExecutable) :
     * celui qu'apporte l'assistant — partagé, modifiable par d'autres, ajouté
     * peut-être après coup — dépose une carte à approuver.
     */
    public const ACTING_TOOL_ACTIONS = [
        'executer_workflow_n8n'      => 'n8n',
        'generer_presentation_gamma' => 'gamma',
    ];

    /**
     * Raisons de pause (colonne `pause_reason`). `user` = pause voulue ; les
     * autres sont posées par le planificateur et nommées à l'écran.
     */
    public const PAUSE_USER      = 'user';
    public const PAUSE_FAILURES  = 'failures';
    public const PAUSE_ACCOUNT   = 'account_inactive';
    public const PAUSE_ASSISTANT = 'assistant_unavailable';
    public const PAUSE_RULE      = 'invalid_rule';
    /** Créneau sauté : le rôle de la personne ne permet plus d'exécuter ses tâches. */
    public const REASON_ROLE     = 'role';

    /**
     * Sources qu'une tâche peut engager : les connecteurs du chat (Recherche
     * Internet comprise). Ce sont les MÊMES interrupteurs que le menu « + »,
     * rejoués à chaque exécution ; « Laisser MIA choisir » n'en fait pas
     * partie — une tâche qui tourne seule dit ce qu'elle lit.
     *
     * @return list<string>
     */
    public static function sourceKeys(): array
    {
        return ConnectorRegistry::keys();
    }

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED, '1') === '1';
    }

    /**
     * Cette personne a-t-elle ce droit sur ses tâches programmées (`view`,
     * `use`, `create`, `edit`, `delete`) ? La fonctionnalité proposée par
     * l'administrateur (Outils → Tâches programmées), puis le droit de son
     * rôle (Personnes → Rôles). SOURCE UNIQUE des pages, des menus, de
     * l'outil `programmer_tache` et du planificateur.
     */
    public static function allows(?array $user, string $action): bool
    {
        return self::isEnabled() && Role::userCan($user, 'schedules.' . $action);
    }

    /** L'administrateur propose-t-il le mode « Agir sans validation » ? */
    public static function autoAllowed(): bool
    {
        return Settings::get(self::SETTING_AUTO, '1') === '1';
    }

    /**
     * Mode d'une tâche (hydratée) ou d'une saisie validée : lecture seule,
     * actions à approuver, ou actions exécutées sans validation. Pure — la
     * permission de l'administrateur est appliquée à l'exécution
     * (Scheduler::actionMode), pas ici : l'écran montre le choix enregistré.
     */
    public static function modeOf(array $task): string
    {
        if (empty($task['allow_actions'])) {
            return self::MODE_READ;
        }
        return !empty($task['auto_actions']) ? self::MODE_AUTO : self::MODE_APPROVE;
    }

    /**
     * Saisie d'une tâche préparée par MIA (carte `programmer_tache`) : le
     * mode « sans validation » ne se choisit que dans le FORMULAIRE, par la
     * personne — jamais par un modèle, ni par un contenu qu'il aurait lu.
     * Ramené à « actions à approuver ». Pure.
     */
    public static function withoutAutoMode(array $input): array
    {
        if (is_scalar($input['allow_actions'] ?? null) && (string)$input['allow_actions'] === self::MODE_AUTO) {
            $input['allow_actions'] = '1';
        }
        unset($input['auto_send_mail']);
        return $input;
    }

    /** L'icône, prête à poser dans un gabarit (décorative : `aria-hidden`). */
    public static function icon(int $size = 16, string $class = ''): string
    {
        return '<svg' . ($class !== '' ? ' class="' . htmlspecialchars($class) . '"' : '')
            . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . self::ICON_PATHS . '</svg>';
    }

    // ==================================================================
    // CHOIX PERMIS AU COMPTE — formulaire, outil du chat, approbation
    // ==================================================================

    /**
     * Ce qu'une tâche de cette personne peut engager, lu au moment T : ses
     * assistants accessibles, les modèles que ses groupes lui permettent, les
     * sources du menu « + » que l'administrateur laisse voir (avec l'état de
     * SA connexion). Une seule lecture pour les trois chemins de création
     * (formulaire, demande à MIA, approbation de la carte) : le serveur reste
     * juge de ce qu'un POST forgé ou un modèle pourrait proposer.
     *
     * `$full = false` : assistants, modèles et sources seulement — ce qu'il
     * faut pour DÉCRIRE l'outil `programmer_tache` à chaque message où il est
     * exposé. Bases et outils (une requête par assistant porteur de bases) ne
     * sont lus que pour valider une saisie : formulaire, appel de l'outil,
     * approbation, essai.
     *
     * @return array{profiles: array<int, array>, llms: array<string, string>,
     *               sources: array<string, array{label: string, connected: bool}>,
     *               kbs?: array<int, array>, profile_kbs?: array<int, list<array{id: int, name: string}>>,
     *               tools?: array<int, array{name: string, type: string, reason: ?string}>,
     *               profile_tools?: array<int, list<array{id: int, name: string, type: string, acting: bool}>>}
     */
    public static function choicesFor(array $user, bool $full = true): array
    {
        $userId = (int)$user['id'];
        $scope  = UserGroup::membershipScopeForUser($userId);

        $profiles = [];
        foreach (Profile::accessibleByUser($userId, (string)$user['role'], $scope['active_group_ids'], $scope['has_assignments']) as $profile) {
            $profiles[(int)$profile['id']] = $profile;
        }

        $allowed = UserGroup::allowedLlmKeysForUser($scope['active_group_ids']);
        $llms    = LLMFactory::available();
        if ($allowed !== null) {
            $llms = array_intersect_key($llms, array_flip($allowed));
        }

        // Chaque source porte l'icône de son connecteur (celle du menu « + » et
        // du schéma) : le formulaire la montre sur sa tuile.
        $sources = [];
        if (ConnectorRegistry::isEnabled(ConnectorRegistry::WEB)) {
            $sources[ConnectorRegistry::WEB] = [
                'label'     => t('Recherche Internet'),
                'connected' => true,
                'icon'      => (string)(ConnectorRegistry::row(ConnectorRegistry::WEB)['icon'] ?? ''),
            ];
        }
        // Sources personnelles : seulement pour qui relie ses comptes (droit
        // « Connecteurs — Utiliser » de son rôle) — la tâche les lirait sinon
        // en son nom alors que le chat les lui refuse.
        $personalSources = ConnectorRegistry::usableBy($user) ? ConnectorRegistry::chatFeatureState($userId) : [];
        foreach ($personalSources as $key => $state) {
            if (!empty($state['feature'])) {
                // Nom du produit, traduit (« Google Agenda » → « Google Calendar »).
                $sources[$key] = [
                    'label'     => t(ConnectorRegistry::label($key)),
                    'connected' => !empty($state['connected']),
                    'icon'      => (string)(ConnectorRegistry::row($key)['icon'] ?? ''),
                ];
            }
        }

        $choices = ['profiles' => $profiles, 'llms' => $llms, 'sources' => $sources];
        if (!$full) {
            return $choices;
        }

        // Bases de connaissances : le périmètre du sélecteur du chat (créées,
        // partagées, admin → toutes), grisées avec leur raison plutôt que
        // masquées — même règle que le composer (KnowledgeBase::unavailableReason).
        $kbs = [];
        foreach (KnowledgeBase::selectableForUser($user) as $kb) {
            // La raison est une clé de traduction (texte français source).
            $reason = KnowledgeBase::unavailableReason($kb);
            $kbs[(int)$kb['id']] = [
                'id'          => (int)$kb['id'],
                'name'        => (string)$kb['name'],
                'description' => (string)($kb['description'] ?? ''),
                'reason'      => $reason !== null ? t($reason) : null,
                'live'        => KnowledgeBase::isRemote($kb),
                'docs'        => (int)($kb['doc_count'] ?? 0),
                'shared'      => !empty($kb['is_shared']),
            ];
        }
        // Bases que chaque assistant apporte d'office (la porte suit la
        // personne : forProfileAndUser) — rien à cocher pour elles.
        $profileKbs = [];
        foreach ($profiles as $pid => $profile) {
            if ((int)($profile['kb_count'] ?? 0) <= 0) {
                continue;
            }
            $profileKbs[$pid] = array_map(
                static fn(array $kb): array => ['id' => (int)$kb['id'], 'name' => (string)$kb['name']],
                KnowledgeBase::forProfileAndUser($pid, $user)
            );
        }

        // Outils : ceux que les groupes de la personne lui ouvrent (même
        // périmètre que le menu « + »), grisés avec leur raison.
        $tools = [];
        foreach (self::TOOL_TYPES as $type) {
            foreach (ChatTool::activeByUser($type, (string)$user['role'], $scope['active_group_ids'], $scope['has_assignments']) as $tool) {
                $tools[(int)$tool['id']] = [
                    'id'     => (int)$tool['id'],
                    'name'   => (string)$tool['name'],
                    'type'   => $type,
                    'reason' => self::toolUnavailableReason($tool),
                ];
            }
        }

        // « Agir sans validation » : proposé si l'administrateur le permet.
        return $choices + [
            'kbs'           => $kbs,
            'profile_kbs'   => $profileKbs,
            'tools'         => $tools,
            'profile_tools' => self::assistantTools(ChatTool::idsForProfiles(array_keys($profiles)), $tools),
            'auto'          => self::autoAllowed(),
        ];
    }

    /**
     * Outils que chaque assistant APPORTE à une tâche de cette personne : le
     * premier de chaque type lié à l'assistant, parmi ceux que ses groupes lui
     * ouvrent et qui servent (`$tools`, déjà lus dans l'ordre de l'écran Outils)
     * — la règle du tour (ChatController::resolveProfile*Tool). Un outil choisi
     * pour la tâche passe AVANT celui de l'assistant, du même type : le
     * formulaire et le schéma le montrent ainsi. Pure.
     *
     * @param array<int, list<int>> $bound profile_id → outils liés (ChatTool::idsForProfiles)
     * @param array<int, array{id: int, name: string, type: string, reason: ?string}> $tools
     * @return array<int, list<array{id: int, name: string, type: string, acting: bool}>>
     */
    public static function assistantTools(array $bound, array $tools): array
    {
        $out = [];
        foreach ($bound as $profileId => $toolIds) {
            $linked = array_flip(array_map('intval', (array)$toolIds));
            $list = [];
            foreach (self::TOOL_TYPES as $type) {
                foreach ($tools as $id => $tool) {
                    if ($tool['type'] !== $type || !isset($linked[(int)$id]) || $tool['reason'] !== null) {
                        continue;
                    }
                    $list[] = [
                        'id'     => (int)$id,
                        'name'   => (string)$tool['name'],
                        'type'   => $type,
                        'acting' => in_array($type, self::ACTING_TOOL_TYPES, true),
                    ];
                    break;
                }
            }
            if ($list !== []) {
                $out[(int)$profileId] = $list;
            }
        }
        return $out;
    }

    /**
     * Forme attendue par normalizeInput() : identifiants seuls. Bases et
     * outils n'y figurent que si les choix ont été lus en entier — sinon
     * normalizeInput n'en retient aucun.
     */
    public static function allowedFrom(array $choices): array
    {
        $allowed = [
            'profiles' => array_fill_keys(array_keys($choices['profiles']), true),
            'llms'     => $choices['llms'],
            'sources'  => array_keys($choices['sources']),
        ];
        if (isset($choices['kbs'])) {
            $allowed['kbs'] = array_values(array_map('intval', array_keys(array_filter(
                $choices['kbs'],
                static fn(array $kb): bool => $kb['reason'] === null
            ))));
            $allowed['profile_kbs'] = array_map(
                static fn(array $list): array => array_map(static fn(array $kb): int => (int)$kb['id'], $list),
                $choices['profile_kbs'] ?? []
            );
        }
        if (isset($choices['tools'])) {
            $allowed['tools'] = [];
            foreach ($choices['tools'] as $id => $tool) {
                if ($tool['reason'] === null) {
                    $allowed['tools'][(int)$id] = (string)$tool['type'];
                }
            }
        }
        $allowed['auto'] = !empty($choices['auto']);
        return $allowed;
    }

    /** Libellé d'un type d'outil, tel qu'une tâche le propose. */
    public static function toolTypeLabel(string $type): string
    {
        return match ($type) {
            'image' => t('Image'),
            'n8n'   => t('Automatisation n8n'),
            'gamma' => t('Présentation Gamma'),
            default => $type,
        };
    }

    /**
     * Pourquoi un outil ne peut pas servir à une tâche (null = il peut) : mêmes
     * motifs que le menu « + » et que le tour de conversation, qui l'écarterait
     * — modèle arrêté par son fournisseur, dernier test en échec, configuration
     * incomplète.
     */
    public static function toolUnavailableReason(array $tool): ?string
    {
        if (($retired = ChatTool::retiredNotice($tool)) !== null) {
            return $retired;
        }
        if (isset($tool['last_test_ok']) && (int)$tool['last_test_ok'] === 0) {
            return t('Dernier test en échec — prévenir un administrateur');
        }
        $type = (string)($tool['type'] ?? '');
        if (($type === 'n8n' && N8nService::fromTool($tool) === null)
            || ($type === 'gamma' && GammaService::fromTool($tool) === null)) {
            return t('Outil incomplet — prévenir un administrateur');
        }
        return null;
    }

    /**
     * Le message demande-t-il qu'une chose SE RÉPÈTE ? Garde lexicale de
     * l'outil `programmer_tache` (FR/EN), comme `looksLikePastReference` pour
     * la recherche dans les anciennes conversations. Elle doit être PRÉCISE :
     * vraie, elle engage la boucle d'outils pour ce message — et, sur un modèle
     * qui ne cumule pas recherche Internet et outils (Mistral), elle écarte la
     * recherche de cette réponse. D'où aucun mot isolé (« programme » est
     * d'abord un nom, « mensuel » un adjectif) : soit on parle des tâches
     * programmées ou on demande d'en programmer une, soit un RYTHME (« chaque
     * lundi », « tous les matins ») apparaît dans une demande — pas dans une
     * question sur des faits (« combien de mails chaque jour ? »). Quand des
     * connecteurs sont engagés, l'outil est exposé de toute façon. Pure.
     */
    public static function looksLikeScheduleRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if (self::matchesAny($t, self::gateExplicit())) {
            return true;
        }
        return self::matchesAny($t, self::gateRhythm())
            && preg_match('/^[^\p{L}\p{N}]*(?:quel(?:le)?s?|combien|qui|quand|o[uù]|pourquoi|comment|what|which|how|when|who|why|where|is|are|was|were|do|does|did)(?![\p{L}\p{N}])/u', $t) !== 1;
    }

    /** On parle des tâches programmées, ou on demande d'en programmer une. */
    private static function gateExplicit(): array
    {
        $objets = 't[âa]che|routine|envoi|r[ée]sum[ée]|point|rapport|revue|veille|bilan|synth[eè]se|relance|newsletter|r[ée]cap';
        return [
            't[âa]ches?\s+programm[ée]es?',
            '(?:scheduled|recurring)\s+tasks?',
            // « programme-moi ça », « planifie-le », « programme moi un point »
            // (sans trait d'union, « moi » seul : « le programme nous convient »)
            '(?:programme|planifie|automatise|programmez|planifiez|automatisez)(?:-(?:moi|nous|la|le|les|[çc]a|cela)|\s+moi)',
            // « planifie une revue de presse », « peux-tu programmer ce résumé »
            '(?:programme|planifie|programmer|planifier|programmez|planifiez)\s+(?:une|un|cette|ce|cet|la|le|les|des|mon|ma|mes)\s+(?:' . $objets . ')',
            // « refais ça régulièrement » (le bouton sous une réponse porte ce nom)
            're(?:fai(?:s|re|tes)|lance|lancer)(?:[-\s](?:le|la|les|[çc]a|cela))?\s+r[ée]guli[èe]rement',
            '(?:schedule|automate)\s+(?:this|it|that)',
            'schedule\s+(?:a|an)\s+(?:task|routine|report|summary|digest|recap|reminder|review)',
            'set\s+up\s+(?:a\s+)?(?:recurring|daily|weekly|monthly)',
        ];
    }

    /** Un rythme : « chaque lundi », « tous les matins », « every Monday ». */
    private static function gateRhythm(): array
    {
        $jours = 'lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche';
        $days  = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday';
        return [
            'chaque\s+(?:' . $jours . '|jour|matin|midi|soir|nuit|semaine|quinzaine|mois|trimestre|semestre|ann[ée]e|d[ée]but|fin|1er|premier|dernier|week-?end)',
            'tou(?:te)?s\s+les\s+(?:(?:' . $jours . ')s?|jours|matins|midis|soirs|nuits|semaines|quinze\s+jours|mois|trimestres|ans|ann[ée]es|week-?ends|\d+\s+(?:jours|semaines|mois|heures))',
            'le\s+(?:1er|premier|dernier(?:\s+jour)?)\s+(?:de\s+chaque|du)\s+mois',
            '(?:every|each)\s+(?:' . $days . '|day|morning|evening|night|week|weekday|weekend|month|quarter|year|\d+\s+(?:days|weeks|months|hours)|other\s+(?:day|week))',
            '(?:daily|weekly|monthly)\s+(?:at|on)',
            '(?:1st|first|last\s+day)\s+of\s+(?:each|every|the)\s+month',
        ];
    }

    /**
     * Un des motifs, pris comme des mots entiers. Bornes en lettres Unicode :
     * `\b` ignore les lettres accentuées (« programmée » ne finirait jamais).
     */
    private static function matchesAny(string $text, array $motifs): bool
    {
        foreach ($motifs as $motif) {
            if (preg_match('/(?<![\p{L}\p{N}])(?:' . $motif . ')(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Carte d'approbation d'une tâche préparée par MIA (outil
     * `programmer_tache`) : ce qui sera programmé, en clair, avant tout
     * enregistrement. Clés lues par la carte générique du chat (action,
     * fichier, apercu, impacts, icone) ; `ajuster` ouvre le formulaire
     * pré-rempli depuis cette carte.
     *
     * @param array $data   sortie de normalizeInput()
     * @param array $choices sortie de choicesFor()
     */
    public static function actionPreview(array $data, array $choices, \DateTimeImmutable $now): array
    {
        $zone  = Recurrence::zone((string)$data['timezone']);
        $next  = Recurrence::next($data['rule'], (string)$data['timezone'], $now);

        $impacts = [
            Recurrence::describe($data['rule']) . ' (' . t('heure de {ville}', ['ville' => self::zoneCity((string)$data['timezone'])]) . ')',
            t('Première exécution : {date}', ['date' => Format::shortDateTime($next->setTimezone($zone))]),
        ];
        if ($data['profile_id'] !== null) {
            $impacts[] = t('Avec l’assistant « {nom} »', ['nom' => (string)($choices['profiles'][$data['profile_id']]['name'] ?? '')]);
        } elseif ($data['llm'] !== '') {
            $impacts[] = t('Avec le modèle {modele}', ['modele' => (string)($choices['llms'][$data['llm']] ?? $data['llm'])]);
        }
        // Une source proposée mais pas encore reliée au compte : la tâche ne
        // pourra pas la lire tant que la personne ne l'a pas connectée.
        $labels = array_map(
            static fn(string $key): string => isset($choices['sources'][$key]) && empty($choices['sources'][$key]['connected'])
                ? t('{source} (à connecter)', ['source' => (string)$choices['sources'][$key]['label']])
                : (string)($choices['sources'][$key]['label'] ?? $key),
            $data['sources']
        );
        $impacts[] = $labels !== []
            ? t('Sources consultées : {liste}', ['liste' => implode(', ', $labels)])
            : t('Aucune source : l’assistant répond avec ses connaissances');
        $kbNames = array_values(array_filter(array_map(
            static fn(int $id): string => (string)($choices['kbs'][$id]['name'] ?? ''),
            (array)($data['kb_ids'] ?? [])
        )));
        if ($kbNames !== []) {
            $impacts[] = t('Bases de connaissances : {liste}', ['liste' => implode(', ', $kbNames)]);
        }
        $toolNames = array_values(array_filter(array_map(
            static fn(int $id): string => (string)($choices['tools'][$id]['name'] ?? ''),
            (array)($data['tool_ids'] ?? [])
        )));
        if ($toolNames !== []) {
            $impacts[] = t('Outils : {liste}', ['liste' => implode(', ', $toolNames)]);
        }
        $impacts[] = $data['destination'] === self::DEST_NEW
            ? t('Résultat : une nouvelle conversation à chaque fois')
            : t('Résultat : dans une même conversation, à la suite');
        $impacts[] = !empty($data['allow_actions'])
            ? t('Peut préparer des actions : elles attendront votre approbation')
            : t('Lecture seule : rien n’est envoyé ni modifié en votre nom');
        $impacts[] = !empty($data['notify'])
            ? t('Vous prévient sur vos appareils quand le résultat arrive')
            : t('Sans notification sur vos appareils');

        return [
            'action'  => t('Programmer une tâche'),
            'fichier' => (string)$data['name'],
            'apercu'  => (string)$data['instruction'],
            // La consigne, envoyée telle quelle à chaque exécution.
            'apercu_titre' => t('Consigne'),
            'impacts' => $impacts,
            'icone'   => '/assets/img/routine.svg',
            'ajuster' => true,
        ];
    }

    /**
     * Données validées → champs du formulaire (et arguments d'une carte en
     * attente, relus au clic « Approuver » ou par « Ajuster »). Pure.
     */
    public static function toInput(array $data): array
    {
        $rule = $data['rule'];
        return [
            'name'          => (string)$data['name'],
            'instruction'   => (string)$data['instruction'],
            'profile_id'    => $data['profile_id'] !== null ? (string)$data['profile_id'] : '',
            'llm'           => (string)$data['llm'],
            'freq'          => (string)$rule['freq'],
            'days'          => array_map('strval', (array)($rule['days'] ?? [])),
            'day'           => (string)($rule['day'] ?? '1'),
            'time'          => (string)$rule['time'],
            'timezone'      => (string)$data['timezone'],
            'sources'       => array_values($data['sources']),
            'kb_ids'        => array_map('strval', (array)($data['kb_ids'] ?? [])),
            'tool_ids'      => array_map('strval', (array)($data['tool_ids'] ?? [])),
            'destination'   => (string)$data['destination'],
            // Valeurs du choix « Ce que la tâche peut faire » : '' lecture
            // seule, '1' actions à approuver, 'auto' sans validation.
            'allow_actions' => match (self::modeOf($data)) {
                self::MODE_AUTO    => self::MODE_AUTO,
                self::MODE_APPROVE => '1',
                default            => '',
            },
            'auto_send_mail' => self::modeOf($data) === self::MODE_AUTO && !empty($data['auto_send_mail']) ? '1' : '',
            'notify'        => !empty($data['notify']) ? '1' : '',
        ];
    }

    // ==================================================================
    // FORME (pure)
    // ==================================================================

    /**
     * Valide la saisie du formulaire. Pure : les choix permis (assistants,
     * modèles, sources du compte) sont fournis par l'appelant, qui les lit
     * pour la personne connectée — le serveur reste juge de ce qu'un POST
     * forgé pourrait proposer.
     *
     * Bases et outils ne sont retenus que si `$allowed` les porte (choix lus en
     * entier, cf. allowedFrom). Un outil qui AGIT (n8n, Gamma) exclut la
     * lecture seule : `allow_actions` passe à vrai, quoi qu'ait posté le
     * formulaire — sans action possible, il ne ferait rien.
     *
     * « Ce que la tâche peut faire » (`allow_actions`) : '' lecture seule, '1'
     * actions à approuver, 'auto' sans validation. 'auto' n'est retenu que si
     * l'administrateur le propose (`$allowed['auto']`) ; sinon la tâche
     * PRÉPARE ses actions — jamais moins que ce que la personne a permis.
     * `auto_send_mail` ne vaut qu'en mode 'auto'.
     *
     * @param array{profiles: array<int, mixed>, llms: array<string, mixed>, sources: list<string>,
     *              kbs?: list<int>, profile_kbs?: array<int, list<int>>, tools?: array<int, string>,
     *              auto?: bool} $allowed
     * @return array{name: string, instruction: string, profile_id: ?int, llm: string, rule: array,
     *               timezone: string, sources: list<string>, kb_ids: list<int>, tool_ids: list<int>,
     *               destination: string, allow_actions: bool, auto_actions: bool, auto_send_mail: bool,
     *               notify: bool}
     * @throws \InvalidArgumentException message affichable, déjà traduit
     */
    public static function normalizeInput(array $post, array $allowed): array
    {
        $instruction = trim(str_replace(["\r\n", "\r"], "\n", (string)($post['instruction'] ?? '')));
        if ($instruction === '') {
            throw new \InvalidArgumentException(t('Écrivez la consigne que l’assistant suivra.'));
        }
        if (mb_strlen($instruction) > self::INSTRUCTION_MAX) {
            throw new \InvalidArgumentException(t('La consigne dépasse {n} caractères.', ['n' => self::INSTRUCTION_MAX]));
        }

        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($post['name'] ?? '')));
        if ($name === '') {
            $name = self::defaultName($instruction);
        }
        $name = mb_substr($name, 0, self::NAME_MAX);

        $profileId = null;
        $rawProfile = (string)($post['profile_id'] ?? '');
        if ($rawProfile !== '' && $rawProfile !== '0') {
            $profileId = filter_var($rawProfile, FILTER_VALIDATE_INT) ?: null;
            if ($profileId === null || !isset($allowed['profiles'][$profileId])) {
                throw new \InvalidArgumentException(t('Cet assistant n’est pas disponible pour votre compte.'));
            }
        }

        // Le modèle ne vaut que SANS assistant : un assistant impose le sien.
        $llm = $profileId === null ? trim((string)($post['llm'] ?? '')) : '';
        if ($llm !== '' && !isset($allowed['llms'][$llm])) {
            throw new \InvalidArgumentException(t('Ce modèle n’est pas disponible pour votre compte.'));
        }

        $rule = Recurrence::normalize([
            'freq' => $post['freq'] ?? '',
            'days' => $post['days'] ?? [],
            'day'  => $post['day'] ?? '',
            'time' => $post['time'] ?? '',
        ]);

        // Une source décochée par l'administrateur depuis le chargement de la
        // page est simplement ignorée : rien à reprocher à la personne.
        $sources = array_values(array_intersect(
            (array)($allowed['sources'] ?? []),
            array_map('strval', (array)($post['sources'] ?? []))
        ));

        $destination = (string)($post['destination'] ?? self::DEST_THREAD) === self::DEST_NEW
            ? self::DEST_NEW
            : self::DEST_THREAD;

        // Bases de connaissances : parmi celles de la personne, hors celles que
        // l'assistant apporte déjà (elles sont interrogées d'office, les garder
        // consommerait un des trois crans). Même règle que le sélecteur du chat.
        $kbIds = [];
        if (isset($allowed['kbs'])) {
            $kbAllowed = (array)$allowed['kbs'];
            if ($profileId !== null) {
                $kbAllowed = array_values(array_diff($kbAllowed, (array)($allowed['profile_kbs'][$profileId] ?? [])));
            }
            $selection = ConversationKnowledgeBase::normalizeSelection((array)($post['kb_ids'] ?? []), $kbAllowed);
            if ($selection['capped']) {
                throw new \InvalidArgumentException(t('{n} bases de connaissances au plus par tâche.', [
                    'n' => ConversationKnowledgeBase::MAX_SELECTED,
                ]));
            }
            $kbIds = $selection['ids'];
        }

        // Outils : un par type (le tour n'en appelle qu'un), parmi ceux que
        // les groupes de la personne lui ouvrent.
        $toolIds = [];
        $acting  = false;
        if (isset($allowed['tools'])) {
            $types = [];
            foreach ((array)($post['tool_ids'] ?? []) as $raw) {
                $id   = is_scalar($raw) ? (int)$raw : 0;
                $type = $allowed['tools'][$id] ?? null;
                if ($type === null || isset($types[$type]) || in_array($id, $toolIds, true)) {
                    continue;
                }
                $types[$type] = true;
                $toolIds[] = $id;
                $acting = $acting || in_array($type, self::ACTING_TOOL_TYPES, true);
            }
        }

        // Ce que la tâche peut faire : absent du POST = lecture seule, le
        // réglage prudent — sauf outil qui agit, qui l'exclut. « Sans
        // validation » demandé mais non proposé par l'administrateur : la
        // tâche prépare ses actions, à approuver.
        $rawMode = is_scalar($post['allow_actions'] ?? null) ? (string)$post['allow_actions'] : '';
        $auto    = $rawMode === self::MODE_AUTO && !empty($allowed['auto']);

        return [
            'name'          => $name,
            'instruction'   => $instruction,
            'profile_id'    => $profileId,
            'llm'           => $llm,
            'rule'          => $rule,
            'timezone'      => self::normalizeTimezone((string)($post['timezone'] ?? '')),
            'sources'       => $sources,
            'kb_ids'        => $kbIds,
            'tool_ids'      => $toolIds,
            'destination'   => $destination,
            'allow_actions' => $acting || $rawMode === self::MODE_AUTO || filter_var($rawMode, FILTER_VALIDATE_BOOLEAN),
            'auto_actions'  => $auto,
            // Un email préparé part réellement — case distincte, décochée par
            // défaut : sinon il est enregistré en brouillon.
            'auto_send_mail' => $auto && filter_var($post['auto_send_mail'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // « Me prévenir » : case du formulaire (absente = décochée). Les
            // autres chemins (MIA, carte, essai) la posent explicitement.
            'notify'        => filter_var($post['notify'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Nom par défaut : le début de la consigne, sur une ligne. */
    public static function defaultName(string $instruction): string
    {
        $line = trim((string)preg_replace('/\s+/u', ' ', $instruction));
        if (mb_strlen($line) <= 60) {
            return $line;
        }
        return rtrim(mb_substr($line, 0, 57)) . '…';
    }

    /** Fuseau IANA du navigateur, ou '' (le fuseau du serveur fera foi). */
    public static function normalizeTimezone(string $tz): string
    {
        $tz = trim($tz);
        if ($tz === '' || strlen($tz) > 64) {
            return '';
        }
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : '';
    }

    /**
     * Ville du fuseau, pour la mention « heure de Paris » : dernier segment de
     * l'identifiant IANA, tirets bas en espaces. Vide = fuseau du serveur.
     */
    public static function zoneCity(string $tz): string
    {
        if ($tz === '') {
            return 'UTC';
        }
        $parts = explode('/', $tz);
        return str_replace('_', ' ', (string)end($parts));
    }

    /** Raison d'une pause ou d'une exécution non aboutie, en clair. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::PAUSE_USER      => t('mise en pause par vous'),
            self::PAUSE_FAILURES  => t('trois échecs d’affilée'),
            self::PAUSE_ACCOUNT   => t('compte désactivé'),
            self::PAUSE_ASSISTANT => t('assistant indisponible'),
            self::PAUSE_RULE      => t('récurrence illisible, à reprendre'),
            self::REASON_ROLE     => t('votre rôle ne permet plus d’exécuter des tâches programmées'),
            'quota'               => t('quota de crédits atteint'),
            'budget'              => t('budget IA de l’organisation atteint'),
            'still_running'       => t('l’exécution précédente n’était pas terminée'),
            'late'                => t('serveur indisponible à l’heure prévue'),
            'model_unavailable'   => t('aucun modèle disponible'),
            'model_error'         => t('le modèle a répondu en erreur'),
            'lost'                => t('exécution perdue par le serveur'),
            ''                    => '',
            default               => t('erreur pendant l’exécution'),
        };
    }

    /** Statut d'une exécution, en clair (liste des tâches, historique). */
    public static function runStatusLabel(string $status): string
    {
        return match ($status) {
            'queued'    => t('En cours'),
            'done'      => t('Réussie'),
            'failed'    => t('Échec'),
            'cancelled' => t('Arrêtée'),
            'skipped'   => t('Sautée'),
            'missed'    => t('Manquée'),
            default     => $status,
        };
    }

    /**
     * Ligne de base → tâche prête à l'emploi : règle décodée (null si
     * illisible), sources en liste, identifiants en entiers.
     */
    public static function hydrate(array $row): array
    {
        $row['id']         = (int)$row['id'];
        $row['user_id']    = (int)$row['user_id'];
        $row['profile_id'] = $row['profile_id'] !== null ? (int)$row['profile_id'] : null;
        $row['failures']   = (int)$row['failures'];
        $row['allow_actions'] = (int)($row['allow_actions'] ?? 0) === 1;
        // « Sans validation » n'a de sens qu'avec des actions permises.
        $row['auto_actions']  = $row['allow_actions'] && (int)($row['auto_actions'] ?? 0) === 1;
        $row['auto_send_mail'] = $row['auto_actions'] && (int)($row['auto_send_mail'] ?? 0) === 1;
        $row['notify']        = (int)($row['notify'] ?? 1) === 1;
        $row['rule']       = Recurrence::fromJson((string)$row['rule_json']);
        $row['sources_list'] = array_values(array_filter(explode(',', (string)$row['sources'])));
        $row['kb_list']      = self::idList((string)($row['kb_ids'] ?? ''));
        $row['tool_list']    = self::idList((string)($row['tool_ids'] ?? ''));
        return $row;
    }

    /** « 3,7 » → [3, 7] : entiers positifs, sans doublon, ordre conservé. Pure. */
    public static function idList(string $csv): array
    {
        $ids = [];
        foreach (explode(',', $csv) as $part) {
            $id = (int)trim($part);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Prochaine exécution d'une tâche à partir de `$now` (UTC), null si la
     * règle est illisible — la tâche est alors mise en pause.
     */
    public static function nextRunFor(?array $rule, string $timezone, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $rule !== null ? Recurrence::next($rule, $timezone, $now) : null;
    }

    /** DATETIME UTC pour la base (toutes les dates de ces tables sont en UTC). */
    public static function sqlUtc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** DATETIME UTC de la base → date, ou null. */
    public static function fromSqlUtc(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    // ==================================================================
    // PERSISTANCE — bornée au propriétaire
    // ==================================================================

    /** @return list<array> tâches de la personne, actives d'abord, puis par nom */
    public static function forUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM scheduled_tasks WHERE user_id = ?
             ORDER BY status = 'active' DESC, name ASC, id ASC"
        );
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll() ?: []);
    }

    /** Une tâche, quel que soit son propriétaire (planificateur, notifications). */
    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM scheduled_tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM scheduled_tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /** Tâches actives de la personne, une tâche exclue (celle qu'on modifie). */
    public static function countActive(int $userId, ?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM scheduled_tasks WHERE user_id = ? AND status = 'active'";
        $params = [$userId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Crée une tâche ACTIVE. Le plafond de tâches actives est vérifié par
     * l'appelant (il en fait un message), le calcul de la prochaine
     * exécution ici.
     */
    public static function create(int $userId, array $data, \DateTimeImmutable $now): int
    {
        $db = Database::getInstance();
        $next = Recurrence::next($data['rule'], $data['timezone'], $now);
        $db->prepare(
            'INSERT INTO scheduled_tasks
                (user_id, profile_id, llm, name, instruction, rule_json, timezone, sources, kb_ids, tool_ids,
                 destination, allow_actions, auto_actions, auto_send_mail, notify, status, next_run_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        )->execute([
            $userId,
            $data['profile_id'],
            $data['llm'],
            $data['name'],
            $data['instruction'],
            Recurrence::toJson($data['rule']),
            $data['timezone'],
            implode(',', $data['sources']),
            implode(',', (array)($data['kb_ids'] ?? [])),
            implode(',', (array)($data['tool_ids'] ?? [])),
            $data['destination'],
            !empty($data['allow_actions']) ? 1 : 0,
            ...self::autoFlags($data),
            self::notifyFlag($data),
            self::sqlUtc($next),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Colonnes `auto_actions` et `auto_send_mail` d'une saisie validée :
     * jamais sans `allow_actions`, l'envoi jamais sans « sans validation ».
     *
     * @return array{0: int, 1: int}
     */
    private static function autoFlags(array $data): array
    {
        $auto = self::modeOf($data) === self::MODE_AUTO;
        return [$auto ? 1 : 0, $auto && !empty($data['auto_send_mail']) ? 1 : 0];
    }

    /** « Me prévenir » : oui tant que la saisie ne dit pas le contraire (défaut de la colonne). */
    private static function notifyFlag(array $data): int
    {
        return !array_key_exists('notify', $data) || !empty($data['notify']) ? 1 : 0;
    }

    /**
     * Met à jour le contenu d'une tâche. La prochaine exécution est
     * RECALCULÉE à partir de maintenant : une heure changée vaut pour la
     * prochaine occurrence, jamais pour un créneau déjà passé.
     */
    public static function update(int $id, int $userId, array $data, \DateTimeImmutable $now): bool
    {
        $next = Recurrence::next($data['rule'], $data['timezone'], $now);
        $stmt = Database::getInstance()->prepare(
            'UPDATE scheduled_tasks
             SET profile_id = ?, llm = ?, name = ?, instruction = ?, rule_json = ?, timezone = ?,
                 sources = ?, kb_ids = ?, tool_ids = ?, destination = ?, allow_actions = ?,
                 auto_actions = ?, auto_send_mail = ?, notify = ?, next_run_at = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $data['profile_id'],
            $data['llm'],
            $data['name'],
            $data['instruction'],
            Recurrence::toJson($data['rule']),
            $data['timezone'],
            implode(',', $data['sources']),
            implode(',', (array)($data['kb_ids'] ?? [])),
            implode(',', (array)($data['tool_ids'] ?? [])),
            $data['destination'],
            !empty($data['allow_actions']) ? 1 : 0,
            ...self::autoFlags($data),
            self::notifyFlag($data),
            self::sqlUtc($next),
            $id,
            $userId,
        ]);
        return $stmt->rowCount() > 0 || self::findForUser($id, $userId) !== null;
    }

    /**
     * Reprendre : compteur d'échecs remis à zéro, prochaine exécution
     * recalculée — une tâche en pause depuis un mois ne part pas aussitôt sur
     * un créneau périmé.
     */
    public static function resume(int $id, int $userId, \DateTimeImmutable $now): bool
    {
        $task = self::findForUser($id, $userId);
        if ($task === null || $task['rule'] === null) {
            return false;
        }
        $next = Recurrence::next($task['rule'], (string)$task['timezone'], $now);
        $stmt = Database::getInstance()->prepare(
            "UPDATE scheduled_tasks
             SET status = 'active', pause_reason = '', failures = 0, next_run_at = ?
             WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([self::sqlUtc($next), $id, $userId]);
        return true;
    }

    public static function pauseForUser(int $id, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE scheduled_tasks SET status = 'paused', pause_reason = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([self::PAUSE_USER, $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Supprime la tâche. Ses conversations restent : c'est un historique. */
    public static function delete(int $id, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM scheduled_tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Conversation où la tâche écrit à la suite (destination « thread ») : la
     * plus récente née d'elle. Supprimée par la personne ? La prochaine
     * exécution en ouvre une nouvelle.
     */
    public static function threadConversationId(int $taskId, int $userId): ?int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM conversations WHERE scheduled_task_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$taskId, $userId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Bandeau en tête d'une conversation née d'une tâche (rendu serveur et
     * chemin JSON) : de quoi se repérer sans quitter le fil. Null si la
     * conversation ne vient d'aucune tâche, ou d'une tâche supprimée.
     */
    public static function bannerFor(array $conversation, int $userId): ?array
    {
        $taskId = (int)($conversation['scheduled_task_id'] ?? 0);
        if ($taskId <= 0) {
            return null;
        }
        $task = self::findForUser($taskId, $userId);
        if ($task === null) {
            return null;
        }
        return [
            'id'       => $task['id'],
            'name'     => (string)$task['name'],
            'sentence' => $task['rule'] !== null ? Recurrence::describe($task['rule']) : '',
            'active'   => $task['status'] === 'active',
            'url'      => '/settings/schedules/' . $task['id'] . '/edit',
        ];
    }

    // ==================================================================
    // PERSISTANCE — planificateur (toutes personnes confondues)
    // ==================================================================

    /** @return list<int> tâches actives dont l'échéance est passée, les plus anciennes d'abord */
    public static function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id FROM scheduled_tasks
             WHERE status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= ?
             ORDER BY next_run_at ASC, id ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([self::sqlUtc($now)]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Verrouille une tâche échue pour la traiter, dans la transaction de
     * l'appelant. SKIP LOCKED : un autre worker qui la tient déjà passe son
     * chemin au lieu d'attendre — et la relecture sous verrou écarte une
     * tâche déjà avancée entre la liste et le verrou.
     */
    public static function lockDue(int $id, \DateTimeImmutable $now): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM scheduled_tasks
             WHERE id = ? AND status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= ?
             FOR UPDATE SKIP LOCKED"
        );
        $stmt->execute([$id, self::sqlUtc($now)]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function setNextRun(int $id, ?\DateTimeImmutable $next): void
    {
        Database::getInstance()->prepare('UPDATE scheduled_tasks SET next_run_at = ? WHERE id = ?')
            ->execute([$next !== null ? self::sqlUtc($next) : null, $id]);
    }

    /** Pause posée par le planificateur, avec sa raison (affichée). */
    public static function pauseWithReason(int $id, string $reason): void
    {
        Database::getInstance()->prepare(
            "UPDATE scheduled_tasks SET status = 'paused', pause_reason = ? WHERE id = ?"
        )->execute([$reason, $id]);
    }

    /**
     * Issue d'une exécution : un succès remet le compteur d'échecs à zéro, un
     * échec l'incrémente — et le TROISIÈME d'affilée met la tâche en pause.
     * Une tâche qui échoue chaque matin (crédit du fournisseur épuisé, clé
     * révoquée) ne doit ni coûter ni remplir un fil d'erreurs sans fin.
     */
    public static function recordOutcome(int $id, bool $success): void
    {
        $db = Database::getInstance();
        if ($success) {
            $db->prepare('UPDATE scheduled_tasks SET failures = 0 WHERE id = ?')->execute([$id]);
            return;
        }
        $db->prepare(
            'UPDATE scheduled_tasks SET failures = LEAST(failures + 1, 255) WHERE id = ?'
        )->execute([$id]);
        $db->prepare(
            "UPDATE scheduled_tasks SET status = 'paused', pause_reason = ?
             WHERE id = ? AND status = 'active' AND failures >= ?"
        )->execute([self::PAUSE_FAILURES, $id, self::MAX_FAILURES]);
    }
}
