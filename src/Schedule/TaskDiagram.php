<?php

declare(strict_types=1);

/**
 * Schéma d'une tâche programmée — ce qui la déclenche (en haut), l'assistant
 * qui l'exécute (au centre), le modèle qui réfléchit (à gauche), ce qu'elle
 * consulte et ce qu'elle engage (en dessous). Affiché à droite de la liste
 * quand on clique une tâche, et à droite du formulaire, tenu à jour pendant
 * la saisie.
 *
 * UN SEUL RENDU, dans le navigateur — public/assets/js/diagram.js (le moteur,
 * partagé avec le schéma d'un assistant, cf. AssistantDiagram) et
 * public/assets/js/schedule-diagram.js (ce qu'on montre d'une tâche) : le
 * formulaire doit se redessiner à chaque geste, et une seconde copie en PHP
 * divergerait au premier ajustement. Ce fichier ne fournit que des DONNÉES :
 *
 *  - `catalog()` : de quoi nommer et illustrer chaque élément — libellés
 *    TRADUITS ici (le script n'a aucun texte en dur), icônes de l'application
 *    (les mêmes que le menu, le composer et le menu « + » : AdminMenu,
 *    ConnectorRegistry, logos des fournisseurs, horloge des tâches), fiches
 *    des assistants, modèles, connecteurs, bases, outils ;
 *  - `spec()` / `specFromTask()` : la configuration d'UNE tâche, sous la forme
 *    même du formulaire (le script en compose une identique depuis les champs).
 *
 * Le modèle affiché suit la règle du tour réel (ChatController::scheduledTurnLlm) :
 * un assistant impose le sien (son défaut d'application s'il n'en a pas) ;
 * sans assistant, celui de la tâche s'il est encore permis, sinon le défaut.
 */
final class TaskDiagram
{
    /** Consigne montrée dans le nœud de l'assistant, au-delà : « … ». */
    public const INSTRUCTION_EXCERPT = 280;

    /**
     * Pictogrammes propres au schéma (caractéristiques de l'exécution). Les
     * autres viennent des sources de l'application, cf. icons().
     */
    private const TRAIT_ICONS = [
        // Lecture seule : un œil.
        'read_only' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        // Actions à approuver : une case cochée.
        'actions'   => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        // Agit sans validation : un éclair.
        'auto'      => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        // Emails envoyés (sans validation) : l'avion en papier.
        'auto_send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        // À la suite : la bulle de « Où voir le résultat ».
        'thread'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        // Nouvelle conversation à chaque fois : la bulle et un « + ».
        'new_conv'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v6M9 10h6"/>',
        // Notifications : la cloche de la carte Notifications.
        'notify'    => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        // Aller au réglage (formulaire) : le crayon de la section « Quoi ».
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    ];

    /**
     * Outils qu'une tâche peut engager (ScheduledTask::TOOL_TYPES) : les
     * pictogrammes des cartes de /admin/tools et du menu « + ».
     */
    private const TOOL_ICONS = [
        'image' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
        'n8n'   => '<img src="/assets/img/n8n.svg" width="16" height="16" alt="">',
        'gamma' => '<img src="/assets/img/gamma.svg" width="16" height="16" alt="">',
    ];

    /**
     * Pictogramme d'un outil par type — celui du schéma, repris par les tuiles
     * « Outils » du formulaire : un même dessin des deux côtés de la page.
     * Type inconnu : l'icône générique des outils (menu d'administration).
     */
    public static function toolIcon(string $type): string
    {
        return self::TOOL_ICONS[$type] ?? AdminMenu::icon('tools', 16);
    }

    /**
     * Tout ce que le script sait nommer et dessiner. `$choices` = forme
     * COMPLÈTE de ScheduledTask::choicesFor() ; `$assistantCards` =
     * ScheduledTaskController::assistantCards() (avatar, couleur, description,
     * modèle, bases et outils de chaque assistant) ; `$sourceIssues` = connecteurs à
     * reconnecter, nommés sur le nœud (liste des tâches).
     *
     * @param array<int, array> $assistantCards
     * @param array<string, string> $sourceIssues
     */
    public static function catalog(array $choices, array $assistantCards, array $sourceIssues = []): array
    {
        $logos = LLMFactory::logos();
        $llms  = [];
        // Tous les modèles actifs : un assistant impose le sien même hors de
        // la liste du groupe (cf. ChatController::resolveProfileDefaultLlm).
        foreach (LLMFactory::available() + $choices['llms'] as $slug => $label) {
            $provider = LLMFactory::provider((string)$slug);
            $llms[(string)$slug] = [
                'label'    => (string)$label,
                'provider' => LLMFactory::providerLabel($provider),
                'logo'     => (string)($logos[$provider] ?? ''),
            ];
        }

        $kbs = [];
        foreach ((array)($choices['kbs'] ?? []) as $id => $kb) {
            $kbs[(int)$id] = ['name' => (string)$kb['name'], 'live' => !empty($kb['live'])];
        }

        $assistants = [];
        foreach ($assistantCards as $id => $card) {
            $own = [];
            foreach (array_values((array)($card['kb_ids'] ?? [])) as $i => $kbId) {
                $own[] = [
                    'id'   => (int)$kbId,
                    'name' => (string)($card['kbs'][$i] ?? ''),
                    'live' => !empty($kbs[(int)$kbId]['live']),
                ];
            }
            $assistants[(int)$id] = [
                'name'        => (string)$card['name'],
                'avatar'      => (string)$card['avatar'],
                'color'       => (string)$card['color'],
                'description' => (string)$card['description'],
                'llm'         => (string)($card['llm'] ?? ''),
                'kbs'         => $own,
                // Outils qu'il apporte d'office : ils servent à la tâche sans
                // être cochés — le schéma les montre, et dit quand leurs
                // actions attendent une validation (Scheduler::autoExecutable).
                'tools'       => array_map(static fn(array $tool): array => [
                    'id'     => (int)$tool['id'],
                    'name'   => (string)$tool['name'],
                    'type'   => (string)$tool['type'],
                    'kind'   => ScheduledTask::toolTypeLabel((string)$tool['type']),
                    'acting' => !empty($tool['acting']),
                ], array_values((array)($card['tools'] ?? []))),
            ];
        }

        // Connecteurs : tout le catalogue du chat (une tâche peut citer une
        // source que l'administrateur a retirée depuis — elle reste nommée,
        // avec son problème), libellés comme dans choicesFor().
        $sources = [];
        foreach (ConnectorRegistry::chatCatalog() as $row) {
            $key = (string)$row['key'];
            $sources[$key] = [
                'label' => t((string)$row['label']),
                'icon'  => (string)$row['icon'],
                'issue' => (string)($sourceIssues[$key] ?? ''),
            ];
        }

        $tools = [];
        foreach ((array)($choices['tools'] ?? []) as $id => $tool) {
            $tools[(int)$id] = [
                'name' => (string)$tool['name'],
                'type' => (string)$tool['type'],
                'kind' => ScheduledTask::toolTypeLabel((string)$tool['type']),
            ];
        }

        return [
            'labels'       => self::labels(),
            'icons'        => self::icons(),
            'tool_icons'   => self::TOOL_ICONS,
            'mia'          => [
                'name'   => (string)Settings::get('app_name', 'MIA'),
                'avatar' => (string)Settings::get('logo_url', '/assets/img/logo.png'),
            ],
            'assistants'   => (object)$assistants,
            'llms'         => (object)$llms,
            'allowed_llms' => array_map('strval', array_keys($choices['llms'])),
            'default_llm'  => (string)(LLMFactory::appDefault($choices['llms']) ?? ''),
            'sources'      => (object)$sources,
            'kbs'          => (object)$kbs,
            'tools'        => (object)$tools,
        ];
    }

    /** Libellés du schéma, dans la langue de la page. */
    public static function labels(): array
    {
        return [
            'aria'              => t('Schéma de la tâche'),
            'trigger'           => t('Déclencheur'),
            'model'             => t('Modèle'),
            'assistant'         => t('Assistant'),
            'no_assistant'      => t('Sans assistant'),
            'mia_description'   => t('Répond sans assistant, avec le modèle choisi pour la tâche.'),
            'assistant_missing' => t('Assistant indisponible'),
            'connectors'        => t('Connecteurs'),
            'kbs'               => t('Bases de connaissances'),
            'tools'             => t('Outils'),
            'active'            => t('Active'),
            'paused'            => t('En pause'),
            'next'              => t('Prochaine : {date}'),
            'rule_missing'      => t('Rythme à préciser'),
            'llm_imposed'       => t('Imposé par l’assistant'),
            'llm_chosen'        => t('Choisi pour la tâche'),
            'llm_default'       => t('Modèle par défaut'),
            'llm_unknown'       => t('Modèle indisponible'),
            'read_only'         => t('Lecture seule'),
            'actions'           => t('Actions à approuver'),
            'auto'              => t('Agit sans validation'),
            'auto_send'         => t('Emails envoyés'),
            'dest_thread'       => t('Résultats à la suite'),
            'dest_new'          => t('Nouvelle conversation à chaque fois'),
            'notify'            => t('Notifications'),
            'via_assistant'     => t('via l’assistant'),
            'tool_held'         => t('ses actions attendent votre validation'),
            'live'              => t('en direct'),
            'no_sources'        => t('Ni connecteur ni base de connaissances : la tâche répond avec ce que sait le modèle.'),
            'untitled'          => t('Nouvelle tâche'),
        ];
    }

    /**
     * Icônes du schéma : celles de l'application, jamais redessinées — le
     * menu d'administration pour l'assistant, le modèle, les connecteurs, les
     * bases et les outils, l'horloge des tâches pour le déclencheur.
     */
    public static function icons(): array
    {
        $trait = static fn(string $paths): string =>
            '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

        $icons = [
            'trigger'    => ScheduledTask::icon(15),
            'model'      => AdminMenu::icon('llms', 15),
            'assistant'  => AdminMenu::icon('profiles', 15),
            'connectors' => AdminMenu::icon('connectors', 15),
            'kbs'        => AdminMenu::icon('kb', 15),
            'tools'      => AdminMenu::icon('tools', 15),
            'kb'         => AdminMenu::icon('kb', 14),
            'kb_live'    => '<img src="/assets/img/mybrain.png" width="16" height="16" alt="">',
        ];
        foreach (self::TRAIT_ICONS as $key => $paths) {
            $icons[$key] = $trait($paths);
        }
        return $icons;
    }

    /**
     * Configuration d'une tâche telle que le script la lit. `$form` a la forme
     * du formulaire (ScheduledTaskController::formFromTask/formFromPost) ;
     * `$trigger` : phrase, prochaine exécution, fuseau, volume, état. Pure.
     */
    public static function spec(array $form, array $trigger): array
    {
        $profile = $form['profile_id'] ?? null;
        // Ce que la tâche peut faire : le radio du formulaire vaut '' | '1' |
        // 'auto' ; une tâche enregistrée passe ses drapeaux (auto_actions).
        $allow   = $form['allow_actions'] ?? false;
        $actions = !empty($allow);
        $auto    = $actions && ($allow === ScheduledTask::MODE_AUTO || !empty($form['auto_actions']));
        $instruction = trim((string)($form['instruction'] ?? ''));
        if (mb_strlen($instruction) > self::INSTRUCTION_EXCERPT) {
            $instruction = rtrim(mb_substr($instruction, 0, self::INSTRUCTION_EXCERPT)) . '…';
        }
        return [
            'name'          => trim((string)($form['name'] ?? '')),
            'instruction'   => $instruction,
            'profile_id'    => ($profile !== null && (int)$profile > 0) ? (int)$profile : null,
            'llm'           => (string)($form['llm'] ?? ''),
            'sources'       => array_values(array_map('strval', (array)($form['sources'] ?? []))),
            'kb_ids'        => array_values(array_map('intval', (array)($form['kb_ids'] ?? []))),
            'tool_ids'      => array_values(array_map('intval', (array)($form['tool_ids'] ?? []))),
            'allow_actions' => $actions,
            'auto_actions'  => $auto,
            'auto_send_mail' => $auto && !empty($form['auto_send_mail']),
            'destination'   => ($form['destination'] ?? '') === ScheduledTask::DEST_NEW ? ScheduledTask::DEST_NEW : ScheduledTask::DEST_THREAD,
            'notify'        => !empty($form['notify']),
            'trigger'       => [
                'sentence'  => (string)($trigger['sentence'] ?? ''),
                'next'      => (string)($trigger['next'] ?? ''),
                'zone'      => (string)($trigger['zone'] ?? ''),
                'per_month' => (string)($trigger['per_month'] ?? ''),
                'active'    => ($trigger['active'] ?? true) !== false,
                'pause'     => (string)($trigger['pause'] ?? ''),
            ],
        ];
    }

    /**
     * Configuration d'une tâche ENREGISTRÉE (ligne de ScheduledTask::forUser) :
     * la liste en donne une par tâche, le clic l'affiche sans aller-retour.
     * `$autoAllowed` = « Agir sans validation » proposé par l'administrateur :
     * retiré, la tâche dépose des cartes à approuver — le schéma montre ce qui
     * se passera, comme pour le modèle (Scheduler::actionMode).
     */
    public static function specFromTask(array $task, bool $autoAllowed = true): array
    {
        $auto = $autoAllowed && ScheduledTask::modeOf($task) === ScheduledTask::MODE_AUTO;
        $rule   = $task['rule'] ?? null;
        $tz     = (string)($task['timezone'] ?? '');
        $active = ($task['status'] ?? '') === 'active';
        $next   = ScheduledTask::fromSqlUtc($task['next_run_at'] ?? null);
        $reason = (string)($task['pause_reason'] ?? '');

        return self::spec([
            'name'          => (string)$task['name'],
            'instruction'   => (string)$task['instruction'],
            'profile_id'    => $task['profile_id'] ?? null,
            'llm'           => (string)($task['llm'] ?? ''),
            'sources'       => (array)($task['sources_list'] ?? []),
            'kb_ids'        => (array)($task['kb_list'] ?? []),
            'tool_ids'      => (array)($task['tool_list'] ?? []),
            'allow_actions' => (bool)($task['allow_actions'] ?? false),
            'auto_actions'  => $auto,
            'auto_send_mail' => $auto && !empty($task['auto_send_mail']),
            'destination'   => (string)($task['destination'] ?? ScheduledTask::DEST_THREAD),
            'notify'        => (bool)($task['notify'] ?? true),
        ], self::trigger($rule, $tz, $active, $active && $next !== null ? $next : null, $reason));
    }

    /**
     * Le déclencheur : la phrase de la règle, la prochaine exécution à l'heure
     * de la tâche, le volume mensuel, l'état. Partagé par la liste et par le
     * premier rendu du formulaire (le script le tient ensuite à jour depuis
     * l'aperçu du serveur).
     */
    public static function trigger(?array $rule, string $tz, bool $active, ?\DateTimeImmutable $next, string $pauseReason = ''): array
    {
        return [
            'sentence'  => $rule !== null ? Recurrence::describe($rule) : '',
            'next'      => $next !== null ? Format::shortDateTime($next->setTimezone(Recurrence::zone($tz))) : '',
            'zone'      => t('heure de {ville}', ['ville' => ScheduledTask::zoneCity($tz)]),
            'per_month' => $rule !== null
                ? I18n::plural(Recurrence::perMonth($rule), 'Environ {n} exécution par mois', 'Environ {n} exécutions par mois')
                : '',
            'active'    => $active,
            'pause'     => (!$active && $pauseReason !== '' && $pauseReason !== ScheduledTask::PAUSE_USER)
                ? ScheduledTask::reasonLabel($pauseReason)
                : '',
        ];
    }
}
