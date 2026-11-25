<?php

declare(strict_types=1);

/**
 * Points à régler de l'administration — SOURCE UNIQUE des pastilles de
 * configuration du menu d'administration.
 *
 * Le problème résolu : une configuration incomplète ne se voyait nulle part.
 * Une clé Gemini enregistrée sans modèle activé, un assistant partagé
 * qu'aucun groupe n'autorise, un modèle par défaut dont la clé a été
 * supprimée, un connecteur autorisé dont les identifiants manquent : chaque
 * cas avait parfois son avertissement, mais sur SA page — qu'on n'ouvre que
 * lorsqu'un utilisateur se plaint. Ici, chaque constat devient une pastille
 * sur l'entrée du menu où il se corrige, et sur le groupe rétractable qui la
 * contient, avec une bulle qui dit quoi faire.
 *
 * Trois consommateurs, UNE liste :
 *   - `admin-nav.php`       pastille + bulle sur l'entrée ET sur son groupe,
 *                           pastille sur le bouton du tiroir mobile ;
 *   - le tableau de bord    une ligne « Config » par constat (carte Alertes) ;
 *   - le bouton « Admin »   le nombre de points à régler, en bas de la barre
 *                           latérale, jusque dans le chat. L'avatar, lui, reste
 *                           réservé aux connexions à reconnecter.
 *
 * Règles :
 *   - LECTURES LOCALES SEULEMENT, jamais un appel réseau : la liste est
 *     calculée à chaque page d'administration. Ce qui exige une sonde
 *     (Qdrant, cerveau MyBrain joignable, sortie Internet) reste au
 *     Diagnostic. Mémorisée pour la requête (`current()`).
 *   - Aucune famille ne peut faire tomber une page : chacune est isolée,
 *     une panne n'en masque pas une autre (`[Points à régler]` dans les logs).
 *   - Chaque constat porte son remède et S'ÉTEINT quand on corrige : aucun
 *     bouton « ignorer », rien à mémoriser, aucun signal oublié. UNE
 *     exception, décidée le 2026-10-26 : une démo épuisée (demoSignals) est un
 *     signal commercial, pas une panne — l'administrateur la traite puis la
 *     masque depuis la fiche du compte (`users.demo_alert_muted`).
 *   - Deux niveaux. `alert` (rouge) : quelque chose échoue chez les
 *     utilisateurs. `warn` (ambre) : quelque chose leur reste invisible, est
 *     remplacé en silence, ou n'est plus tenu à jour.
 *   - Les formes sont PURES (données lues en entrée) : testables sans base.
 *   - Textes TRADUITS à la création du constat (clé = phrase française
 *     entière, `{variables}`) : la bulle, le résumé du groupe et la carte
 *     Alertes s'affichent dans la langue de la page. Les constantes (ENTRIES,
 *     TEXTE_AUCUNE_CLE) gardent leur texte français, qui est leur clé : lire
 *     leurs libellés par les accesseurs (entryLabel, entryPath, texteAucuneCle).
 */
final class AdminAttention
{
    public const ALERT = 'alert';
    public const WARN  = 'warn';

    /**
     * Configuration initiale : aucune clé API active. Constat « dédié » : le
     * menu le rend par sa pastille pulsante historique (« Modèles IA » et
     * « Clés API », cf. LlmSetupAttentionTest) et le tableau de bord par son
     * bandeau de bienvenue — il ne compte ici que pour le menu du compte.
     */
    public const AUCUNE_CLE = 'llm_aucune_cle';

    /** Texte du constat « aucune clé » — aussi la bulle de la pastille pulsante. */
    public const TEXTE_AUCUNE_CLE = 'Aucune clé API enregistrée : MIA ne peut pas répondre. Renseignez une clé dans « Clés API ».';

    /**
     * Entrées du menu qui peuvent porter un constat (valeurs de `$page` dans
     * admin-nav.php) : libellé du menu, chemin complet (tableau de bord) et
     * adresse où corriger.
     */
    public const ENTRIES = [
        'users'                => ['Comptes', 'Personnes › Comptes', '/admin/users'],
        'user-groups'          => ['Groupes', 'Personnes › Groupes', '/admin/user-groups'],
        'llms-keys'            => ['Fournisseurs et modèles', 'Modèles IA › Fournisseurs et modèles', '/admin/llms'],
        'profiles'             => ['Assistants', 'Assistants', '/admin/profiles'],
        'knowledge-bases'      => ['Bases', 'Bases de connaissances', '/admin/knowledge-bases'],
        'knowledge-bases-sync' => ['Sources synchronisées', 'Capacités › Sources synchronisées', '/admin/knowledge-bases/sync'],
        'tools'                => ['Outils', 'Capacités › Outils', '/admin/tools'],
        'connectors'           => ['Connecteurs', 'Capacités › Connecteurs', '/admin/connectors'],
        'sso'                  => ['Authentification', 'Organisation › Authentification', '/admin/sso'],
        'diagnostics'          => ['Diagnostic', 'Maintenance › Diagnostic', '/admin/diagnostics'],
    ];

    /**
     * Groupes rétractables du menu (`data-nav-group`, rubriques d'AdminMenu)
     * → entrées qu'ils contiennent. Ordre = ordre du menu. Un groupe porte la
     * pastille de la pire de ses entrées : c'est elle qu'on voit tant qu'il
     * est replié. Assistants et bases n'ont pas de groupe : pages de l'espace
     * de travail, leurs constats se lisent sur leur tuile du volet du chat
     * (sidebar-shortcuts.php) et au tableau de bord.
     */
    public const GROUPS = [
        'people'       => ['users', 'user-groups'],
        'models'       => ['llms-keys'],
        'capabilities' => ['tools', 'connectors', 'knowledge-bases-sync'],
        'organisation' => ['sso'],
        'maintenance'  => ['diagnostics'],
    ];

    /** Au-delà, un nom de plus ne dit rien : « … et 3 autres ». */
    private const NOMS_MAX = 3;

    /** Worker muet depuis plus longtemps : arrêté (et non un redémarrage en cours). */
    public const WORKER_SILENCE_SEC = 180;

    /** Constats de la requête courante (menu, menu du compte, tableau de bord). */
    private static ?array $memo = null;

    // ================================================================ Accès

    /**
     * Constats du moment, calculés une fois par requête : le menu, le menu du
     * compte et le tableau de bord les lisent dans la même page. Jamais de
     * cache entre deux requêtes — une correction se voit à la page suivante.
     *
     * @return list<array{id:string, entry:string, level:string, short:string, text:string, dedicated:bool}>
     */
    public static function current(): array
    {
        return self::$memo ??= self::collect();
    }

    /** Oublie la mémoire de la requête (tests). */
    public static function forget(): void
    {
        self::$memo = null;
        self::$itemsMemo = null;
        self::$anchorsIssued = [];
    }

    /** Texte du constat « aucune clé » dans la langue de la page (la constante est sa clé). */
    public static function texteAucuneCle(): string
    {
        return t(self::TEXTE_AUCUNE_CLE);
    }

    /** Libellé du menu d'une entrée, traduit. */
    public static function entryLabel(string $entry): string
    {
        return t((string)(self::ENTRIES[$entry][0] ?? $entry));
    }

    /** Chemin complet d'une entrée (« Personnes › Comptes »), traduit — tableau de bord. */
    public static function entryPath(string $entry): string
    {
        return t((string)(self::ENTRIES[$entry][1] ?? $entry));
    }

    /** Constats rendus par la mécanique commune (hors constats dédiés). */
    public static function shown(array $signals): array
    {
        return array_values(array_filter($signals, static fn(array $s): bool => empty($s['dedicated'])));
    }

    /** Pire niveau d'une liste ; null si elle est vide. */
    public static function worstLevel(array $signals): ?string
    {
        $pire = null;
        foreach ($signals as $s) {
            if (($s['level'] ?? '') === self::ALERT) {
                return self::ALERT;
            }
            $pire = self::WARN;
        }
        return $pire;
    }

    /**
     * Ce que montre une ENTRÉE du menu : son niveau et le texte de sa bulle
     * (tous ses constats, en entier). Null = rien à signaler.
     *
     * @return array{level:string, text:string}|null
     */
    public static function forEntry(array $signals, string $entry): ?array
    {
        $siens = array_values(array_filter(
            self::shown($signals),
            static fn(array $s): bool => $s['entry'] === $entry
        ));
        if ($siens === []) {
            return null;
        }
        $textes = array_column($siens, 'text');
        return [
            'level' => (string)self::worstLevel($siens),
            'text'  => count($textes) === 1 ? $textes[0] : '• ' . implode("\n• ", $textes),
        ];
    }

    /**
     * Ce que montre un GROUPE replié : le pire niveau de ses entrées, et une
     * bulle qui résume chaque constat en une ligne (« Modèles : 2 modèles sans
     * clé API ») — le détail vit sur l'entrée, qu'on voit en dépliant.
     *
     * @return array{level:string, text:string}|null
     */
    public static function forGroup(array $signals, string $group): ?array
    {
        $entrees = self::GROUPS[$group] ?? [];
        $siens = [];
        foreach ($entrees as $entry) {
            foreach (self::shown($signals) as $s) {
                if ($s['entry'] === $entry) {
                    $siens[] = $s;
                }
            }
        }
        if ($siens === []) {
            return null;
        }
        $lignes = array_map(
            static fn(array $s): string => '• ' . t('{entree} : {constat}', ['entree' => self::entryLabel($s['entry']), 'constat' => $s['short']]),
            $siens
        );
        return [
            'level' => (string)self::worstLevel($siens),
            'text'  => t('À régler dans ce menu :') . "\n" . implode("\n", $lignes),
        ];
    }

    // ================================================================ Jusqu'à l'élément

    /**
     * La pastille ne s'arrête pas au menu : chaque constat NOMME les éléments
     * qu'il concerne (`targets` : « tool:12 », « provider:openai »,
     * « profile:7 »…) et dit, pour chacun, ce qui ne va pas — sans les autres.
     * Sur la page, la carte, la ligne ou la section de cet élément porte sa
     * propre pastille (itemDot), avec ce texte en info-bulle.
     *
     * Ici, les constats sont FUSIONNÉS par élément : pire niveau, une ligne
     * par constat (un outil peut être à la fois en échec et sans groupe).
     *
     * @return array<string, array{level:string, text:string}>
     */
    public static function itemsFor(array $signals): array
    {
        $acc = [];
        foreach (self::shown($signals) as $s) {
            foreach ((array)($s['targets'] ?? []) as $cible => $lignes) {
                $cible = (string)$cible;
                $acc[$cible]['lines'] = array_merge($acc[$cible]['lines'] ?? [], array_map('strval', (array)$lignes));
                $acc[$cible]['level'] = (($acc[$cible]['level'] ?? '') === self::ALERT || ($s['level'] ?? '') === self::ALERT)
                    ? self::ALERT
                    : self::WARN;
            }
        }
        $out = [];
        foreach ($acc as $cible => $a) {
            $lignes = array_values(array_unique(array_filter($a['lines'], static fn(string $l): bool => $l !== '')));
            if ($lignes === []) {
                continue;
            }
            $out[$cible] = [
                'level' => $a['level'],
                'text'  => count($lignes) === 1 ? $lignes[0] : '• ' . implode("\n• ", $lignes),
            ];
        }
        return $out;
    }

    /** Éléments signalés pour la personne connectée, mémorisés pour la requête. */
    private static ?array $itemsMemo = null;

    /** Identifiants de pastille déjà posés dans la page (un `id` n'existe qu'une fois). */
    private static array $anchorsIssued = [];

    /**
     * Éléments signalés que la personne connectée doit voir : les mêmes que
     * ses pastilles du menu — qui ADMINISTRE (bouton « Admin »), constats des
     * seules pages que son rôle ouvre (AdminAccess::filterAttention).
     *
     * @return array<string, array{level:string, text:string}>
     */
    public static function itemsForViewer(?array $user = null): array
    {
        if (self::$itemsMemo !== null) {
            return self::$itemsMemo;
        }
        $user ??= Session::currentUser();
        try {
            self::$itemsMemo = AdminAccess::hasAdministration($user)
                ? self::itemsFor(AdminAccess::filterAttention(self::current(), $user))
                : [];
        } catch (\Throwable $e) {
            error_log('[Points à régler] éléments : ' . $e->getMessage());
            self::$itemsMemo = [];
        }
        return self::$itemsMemo;
    }

    /** Ancre d'une pastille d'élément (« att-tool-12 ») : pure, stable. */
    public static function anchorId(string $target): string
    {
        return 'att-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($target)), '-');
    }

    /**
     * La pastille d'un élément sur sa page, son explication en info-bulle
     * (nav-signals.js : survol, focus clavier, toucher). Vide si l'élément n'a
     * rien à signaler. `role="img"` + nom accessible : les lecteurs d'écran la
     * lisent là où elle est. `$focusable` à false quand l'élément qui la
     * porte est déjà un lien ou un bouton (pas de focus imbriqué).
     */
    public static function itemDot(string $target, bool $focusable = true, ?array $items = null): string
    {
        $etat = ($items ?? self::itemsForViewer())[$target] ?? null;
        if ($etat === null) {
            return '';
        }
        return self::dotHtml($etat, $target, $focusable);
    }

    /** Forme HTML d'une pastille d'élément (pure). */
    public static function dotHtml(array $etat, string $target, bool $focusable = true): string
    {
        $ton   = ($etat['level'] ?? '') === self::ALERT ? 'is-alert' : 'is-warn';
        $texte = htmlspecialchars((string)($etat['text'] ?? ''), ENT_QUOTES);
        $ancre = self::anchorId($target);
        $id    = '';
        if (!isset(self::$anchorsIssued[$ancre])) {
            self::$anchorsIssued[$ancre] = true;
            $id = ' id="' . $ancre . '"';
        }
        return '<span class="nav-signal-dot att-dot ' . $ton . '"' . $id
            . ' role="img" aria-label="' . $texte . '" data-tip="' . $texte . '" data-tip-tone="' . ($ton === 'is-alert' ? 'alert' : 'warn') . '"'
            . ($focusable ? ' tabindex="0"' : '') . '></span>';
    }

    /**
     * Où mène « Régler → » d'un constat : sa page, posée sur son premier
     * élément concerné — l'ancre de sa pastille, ou celle que la page sait
     * déjà ouvrir (un assistant, une base, une section d'Authentification),
     * et le bon fournisseur pour un modèle.
     */
    public static function linkFor(array $signal): string
    {
        $href   = (string)(self::ENTRIES[(string)($signal['entry'] ?? '')][2] ?? '/admin');
        $cibles = array_keys((array)($signal['targets'] ?? []));
        if ($cibles === []) {
            return $href;
        }
        $cible = (string)$cibles[0];
        [$genre, $cle] = array_pad(explode(':', $cible, 2), 2, '');
        return match ($genre) {
            'profile'  => '/admin/profiles#assistant-' . (int)$cle,
            'kb'       => $href === '/admin/knowledge-bases' ? '/admin/knowledge-bases#base-' . (int)$cle : $href . '#' . self::anchorId($cible),
            'sso'      => $href . '#sso-' . preg_replace('/[^a-z]/', '', $cle),
            'provider' => '/admin/llms?fournisseur=' . rawurlencode($cle) . '#' . self::anchorId($cible),
            'model'    => '/admin/llms?fournisseur=' . rawurlencode(explode('::', $cle, 2)[0]) . '#' . self::anchorId($cible),
            default    => $href . '#' . self::anchorId($cible),
        };
    }

    // ================================================================ Collecte

    /**
     * Lit l'état de l'instance et le passe aux formes pures, famille par
     * famille. Ne lève jamais.
     *
     * @return list<array{id:string, entry:string, level:string, short:string, text:string, dedicated:bool}>
     */
    public static function collect(): array
    {
        $signals = [];
        $ajouter = static function (string $famille, callable $f) use (&$signals): void {
            try {
                foreach ($f() as $s) {
                    $signals[] = $s;
                }
            } catch (\Throwable $e) {
                error_log('[Points à régler] ' . $famille . ' : ' . $e->getMessage());
            }
        };

        // Clés API actives : même prédicat qu'ApiKey::hasActiveLlm(). Lue une
        // fois, elle sert à plusieurs familles ; en cas de panne, ces familles
        // se taisent (null) plutôt que de conclure « aucune clé ».
        $cles = null;
        try {
            $cles = self::readKeyProviders();
        } catch (\Throwable $e) {
            error_log('[Points à régler] clés API : ' . $e->getMessage());
        }

        if ($cles !== null) {
            if ($cles === []) {
                $signals[] = self::signal(self::AUCUNE_CLE, 'llms-keys', self::ALERT, t('aucune clé API'), self::texteAucuneCle(), true);
            }
            $ajouter('modèles', static function () use ($cles): array {
                $disponibles = LLMFactory::available();
                $actifs      = LlmModel::active();
                $profils     = self::readProfiles();
                [$listes, $idsGroupes] = self::readGroupLists();
                return array_merge(
                    self::llmSignals(
                        $cles,
                        $actifs,
                        $disponibles,
                        (string)Settings::get('default_llm', ''),
                        (string)Settings::get('default_llm_fallback', ''),
                        LLMFactory::appDefault($disponibles)
                    ),
                    self::llmRetirementSignals($actifs, $profils),
                    self::groupSignals($listes, $disponibles, $cles, $idsGroupes),
                    self::assistantSignals($profils, $disponibles, $cles)
                );
            });
            $ajouter('outils', static fn(): array => self::toolSignals(
                ChatTool::all(),
                ChatTool::activeGroupCounts(),
                array_fill_keys($cles, true),
                // La dictée ne manque d'une clé OpenAI que si aucun moteur
                // n'est utilisable : Voxtral choisi et clé Mistral présente
                // suffit (Dictation).
                Settings::get('mic_enabled', '1') === '1'
                    && Dictation::resolveEngine(Dictation::chosenEngine(), in_array('openai', $cles, true), in_array('mistral', $cles, true)) === null,
                SpeechSynthesizer::isEnabled(),
                in_array('openai', $cles, true),
                Dictation::resolveEngine(Dictation::chosenEngine(), in_array('openai', $cles, true), in_array('mistral', $cles, true)) === Dictation::ENGINE_MISTRAL
            ));
            // Voix de lecture de la conversation vocale : arrêt annoncé de la
            // voix en vigueur (gpt-4o-mini-tts, 6 janvier 2027).
            $ajouter('voix de lecture', static fn(): array => self::speechSignals(
                SpeechSynthesizer::isEnabled(),
                SpeechSynthesizer::resolveEngine(
                    SpeechSynthesizer::chosenEngine(),
                    in_array('openai', $cles, true),
                    in_array('mistral', $cles, true)
                )
            ));
            $ajouter('bases', static fn(): array => self::kbSignals(
                self::readKnowledgeBases(),
                KbSync::configuredProviders(),
                GoogleDriveOAuth::isConfigured(),
                array_intersect(EmbeddingService::PROVIDER_ORDER, $cles) !== []
            ));
        }

        // Comptes fournisseur refusés au fil des demandes (crédit épuisé, clé
        // refusée) : consignés par le chat et les jobs, lus dans `settings`.
        $ajouter('incidents fournisseurs', static fn(): array => self::providerIncidentSignals(ProviderIncident::active(), time()));
        $ajouter('comptes', static fn(): array => self::pendingAccountsSignals(self::readPendingAccounts()));
        $ajouter('démos', static fn(): array => self::demoSignals(array_values(DemoAccount::accounts())));
        $ajouter('connecteurs', static function (): array {
            $rows = [];
            foreach (ConnectorRegistry::catalog() as $c) {
                $key = (string)$c['key'];
                $rows[] = $c + [
                    'enabled'    => ConnectorRegistry::isEnabled($key),
                    'configured' => ConnectorRegistry::isConfigured($key),
                ];
            }
            return self::connectorSignals($rows);
        });
        $ajouter('authentification', static fn(): array => self::ssoSignals([
            'Google'    => [SsoRegistry::isEnabled(SsoRegistry::GOOGLE), SsoRegistry::isConfigured(SsoRegistry::GOOGLE)],
            'Microsoft' => [SsoRegistry::isEnabled(SsoRegistry::MICROSOFT), SsoRegistry::isConfigured(SsoRegistry::MICROSOFT)],
        ]));
        $ajouter('worker', static function (): array {
            $s = self::workerSignal(JobQueue::workerHeartbeat(), defined('APP_VERSION') ? (string)APP_VERSION : '', time());
            return $s !== null ? [$s] : [];
        });

        return self::ordered($signals);
    }

    // ================================================================ Formes pures

    /**
     * Modèles : fournisseur sans modèle activé, modèle activé sans clé,
     * modèle par défaut et de secours.
     *
     * @param list<string>               $keyProviders fournisseurs dont la clé est active (local compris)
     * @param list<array<string,mixed>>  $activeModels lignes actives de llm_models
     * @param array<string,string>       $available    LLMFactory::available()
     */
    public static function llmSignals(
        array $keyProviders,
        array $activeModels,
        array $available,
        string $storedDefault,
        string $fallback,
        ?string $effective
    ): array {
        // Aucune clé : le constat dédié « Aucune clé API » dit tout.
        if ($keyProviders === []) {
            return [];
        }
        $cles    = array_fill_keys($keyProviders, true);
        $signals = [];

        // 1. Une clé sans aucun modèle activé. Le LLM local n'a rien à
        //    activer : sa configuration EST l'instance.
        $avecModele = [];
        foreach ($activeModels as $m) {
            $avecModele[(string)$m['provider']] = true;
        }
        $sansModele = [];
        $ciblesSansModele = [];
        foreach ($keyProviders as $p) {
            if ($p !== 'local' && !isset($avecModele[$p])) {
                $sansModele[] = LLMFactory::providerLabel($p);
                $ciblesSansModele['provider:' . $p] = t('Clé enregistrée, mais aucun modèle activé : choisissez ceux à proposer dans le chat.');
            }
        }
        if ($sansModele !== []) {
            $signals[] = count($sansModele) === 1
                ? self::signal('llm_fournisseur_sans_modele', 'llms-keys', self::WARN,
                    t('{fournisseur} : aucun modèle activé', ['fournisseur' => $sansModele[0]]),
                    t('Clé API {fournisseur} enregistrée, mais aucun de ses modèles n\'est activé : choisissez ceux à proposer dans le chat.', ['fournisseur' => $sansModele[0]]),
                    false, $ciblesSansModele)
                : self::signal('llm_fournisseur_sans_modele', 'llms-keys', self::WARN,
                    t('{n} fournisseurs sans modèle activé', ['n' => count($sansModele)]),
                    t('Clés API enregistrées sans aucun modèle activé : {fournisseurs}. Choisissez les modèles à proposer dans le chat.', ['fournisseurs' => implode(', ', $sansModele)]),
                    false, $ciblesSansModele);
        }

        // 2. Un modèle activé dont le fournisseur n'a plus de clé : le chat le
        //    propose toujours (LLMFactory::available ne lit que llm_models),
        //    et chaque message échoue sur « clé API manquante ».
        $sansCle = [];
        $ciblesSansCle = [];
        $parFournisseur = [];
        foreach ($activeModels as $m) {
            if (!isset($cles[(string)$m['provider']])) {
                $sansCle[] = trim((string)($m['display_name'] ?? '')) !== ''
                    ? (string)$m['display_name']
                    : (string)$m['provider'] . '::' . (string)$m['model'];
                $ciblesSansCle['model:' . $m['provider'] . '::' . $m['model']] = t('Modèle activé sans clé API : il échoue à chaque message. Renseignez la clé, ou retirez-le.');
                $parFournisseur[(string)$m['provider']] = ($parFournisseur[(string)$m['provider']] ?? 0) + 1;
            }
        }
        foreach ($parFournisseur as $p => $n) {
            $ciblesSansCle['provider:' . $p] = I18n::plural($n, '{n} modèle proposé sans clé API : il échoue à chaque message.', '{n} modèles proposés sans clé API : ils échouent à chaque message.');
        }
        if ($sansCle !== []) {
            $signals[] = self::signal('llm_modeles_sans_cle', 'llms-keys', self::ALERT,
                I18n::plural(count($sansCle), '{n} modèle sans clé API', '{n} modèles sans clé API'),
                count($sansCle) === 1
                    ? t('Modèle activé sans clé API : {noms}. Toujours proposé dans le chat, il échoue à chaque message : renseignez la clé (Clés API) ou retirez-le.', ['noms' => self::noms($sansCle, false)])
                    : t('Modèles activés sans clé API : {noms}. Toujours proposés dans le chat, ils échouent à chaque message : renseignez la clé (Clés API) ou retirez-les.', ['noms' => self::noms($sansCle, false)]),
                false, $ciblesSansCle);
        }

        // 3. Le modèle par défaut EFFECTIF (celui que MIA utilise vraiment).
        if ($effective !== null) {
            $libelle = self::llmLabel($effective, $available);
            if (!isset($cles[LLMFactory::provider($effective)])) {
                $signals[] = self::on(self::signal('llm_defaut_sans_cle', 'llms-keys', self::ALERT,
                    t('modèle par défaut sans clé API'),
                    t('Le modèle par défaut « {modele} » n\'a plus de clé API : les conversations sans assistant échouent. Renseignez la clé ou choisissez un autre modèle par défaut.', ['modele' => $libelle])), 'llm-default');
            } elseif (trim($storedDefault) !== $effective) {
                // Le réglage ne désigne plus aucun modèle disponible : même
                // constat que l'encadré « Confirmer ce modèle » de la page.
                $signals[] = self::on(self::signal('llm_defaut_a_confirmer', 'llms-keys', self::WARN,
                    t('modèle par défaut à confirmer'),
                    LLMFactory::isRetired(trim($storedDefault))
                        ? t('Le modèle par défaut enregistré a été arrêté par son éditeur : MIA utilise « {modele} ». Confirmez-le ou choisissez-en un autre (carte Comportement par défaut).', ['modele' => $libelle])
                        : t('Aucun modèle par défaut n\'est enregistré : MIA utilise « {modele} ». Confirmez-le ou choisissez-en un autre (carte Comportement par défaut).', ['modele' => $libelle])), 'llm-default');
            }
        }

        // 4. Le modèle de secours : il ne sert qu'en cas de panne, et c'est
        //    précisément là qu'on découvrirait qu'il ne marche plus.
        $fallback = trim($fallback);
        if ($fallback !== '') {
            $libelle = self::llmLabel($fallback, $available);
            if (!isset($available[$fallback])) {
                $signals[] = self::on(self::signal('llm_secours', 'llms-keys', self::WARN,
                    t('modèle de secours retiré'),
                    LLMFactory::isRetired($fallback)
                        ? t('Le modèle de secours « {modele} » a été arrêté par son éditeur : aucune bascule en cas de panne. Choisissez-en un autre.', ['modele' => $libelle])
                        : t('Le modèle de secours « {modele} » n\'est plus activé : aucune bascule en cas de panne. Choisissez-en un autre.', ['modele' => $libelle])), 'llm-fallback');
            } elseif (!isset($cles[LLMFactory::provider($fallback)])) {
                $signals[] = self::on(self::signal('llm_secours', 'llms-keys', self::WARN,
                    t('modèle de secours sans clé API'),
                    t('Le modèle de secours « {modele} » n\'a plus de clé API : la bascule échouerait en cas de panne.', ['modele' => $libelle])), 'llm-fallback');
            }
        }

        return $signals;
    }

    /**
     * Modèles dont l'éditeur a annoncé l'arrêt (bloc `retirement` du
     * catalogue, lu par LlmCatalog::retirement — date du jour comprise).
     *
     *   - activé et date à venir : rien ne prévient sinon, et ce jour-là le
     *     modèle quitte le chat ; on nomme la date et le remplaçant désigné ;
     *   - activé et date passée : déjà retiré du chat (LLMFactory::available),
     *     ses conversations et assistants répondent avec le modèle par défaut
     *     — remplacé en silence, d'où l'avertissement ;
     *   - assistant qui IMPOSE un modèle dont l'arrêt est annoncé. Une fois la
     *     date passée, « assistant sur un modèle retiré » prend le relais ;
     *   - arrêt RELEVÉ chez l'éditeur (LlmShutdownWatch, `source` = `api`)
     *     que le catalogue ne connaît pas : point dédié, sans remplaçant
     *     désigné — le catalogue est à mettre à jour ;
     *   - date relevée chez l'éditeur DIFFÉRENTE de celle du catalogue : MIA
     *     suit le catalogue, l'écart est signalé (date repoussée ou avancée).
     *
     * @param list<array<string,mixed>> $activeModels lignes actives de llm_models
     * @param list<array<string,mixed>> $profiles     assistants actifs (name, default_llm)
     */
    public static function llmRetirementSignals(array $activeModels, array $profiles): array
    {
        $annonces = [];
        $arretes  = [];
        $releves  = [];
        $ecarts   = [];
        // Ce que dit la pastille de CHAQUE modèle, et une ligne par modèle sur
        // la carte de son fournisseur.
        $cibles = ['arretes' => [], 'annonces' => [], 'releves' => [], 'ecarts' => []];
        $poser  = static function (string $famille, array $m, string $nom, string $texte) use (&$cibles): void {
            $p = (string)($m['provider'] ?? '');
            $cibles[$famille]['model:' . $p . '::' . (string)($m['model'] ?? '')][] = $texte;
            $cibles[$famille]['provider:' . $p][] = t('« {label} » : {constat}', ['label' => $nom, 'constat' => $texte]);
        };
        foreach ($activeModels as $m) {
            $arret = LlmCatalog::retirement((string)($m['provider'] ?? ''), (string)($m['model'] ?? ''));
            if ($arret === null) {
                continue;
            }
            $nom = trim((string)($m['display_name'] ?? ''));
            if ($nom === '') {
                $nom = LlmCatalog::label((string)$m['provider'], (string)$m['model']);
            }
            $vars = ['nom' => $nom, 'date' => Format::dateFr($arret['date']), 'remplacant' => $arret['replacement_label']];
            // Arrêt connu par l'API de l'éditeur seulement : point dédié, le
            // catalogue de MIA est à mettre à jour (aucun remplaçant désigné).
            if (($arret['source'] ?? 'catalog') === 'api') {
                $releves[] = $arret['retired'] ? t('{nom} (depuis le {date})', $vars) : t('{nom} (le {date})', $vars);
                $poser('releves', $m, $nom, t('Date d\'arrêt relevée chez l\'éditeur ({date}), absente du catalogue de MIA : activez un remplaçant.', $vars));
                continue;
            }
            $releve = $arret['reported'] ?? null;
            if (is_string($releve) && $releve !== $arret['date']) {
                $ecarts[] = t('{nom} (catalogue : {date}, éditeur : {releve})', $vars + ['releve' => Format::dateFr($releve)]);
                $poser('ecarts', $m, $nom, t('Date d\'arrêt à vérifier : {date} au catalogue de MIA, {releve} chez l\'éditeur.', $vars + ['releve' => Format::dateFr($releve)]));
            }
            if ($arret['retired']) {
                $arretes[] = $arret['replacement_label'] !== ''
                    ? t('{nom} (depuis le {date}, remplaçant : {remplacant})', $vars)
                    : t('{nom} (depuis le {date})', $vars);
                $poser('arretes', $m, $nom, $arret['replacement_label'] !== ''
                    ? t('Arrêté par son éditeur depuis le {date}, retiré du chat : activez le remplaçant ({remplacant}), puis retirez-le.', $vars)
                    : t('Arrêté par son éditeur depuis le {date}, retiré du chat : activez un remplaçant, puis retirez-le.', $vars));
            } else {
                $annonces[] = $arret['replacement_label'] !== ''
                    ? t('{nom} (le {date}, remplaçant : {remplacant})', $vars)
                    : t('{nom} (le {date})', $vars);
                $poser('annonces', $m, $nom, $arret['replacement_label'] !== ''
                    ? t('Arrêt annoncé par l\'éditeur le {date} : activez le remplaçant ({remplacant}) avant cette date.', $vars)
                    : t('Arrêt annoncé par l\'éditeur le {date} : activez un remplaçant avant cette date.', $vars));
            }
        }

        $assistants = [];
        foreach ($profiles as $p) {
            $slug = trim((string)($p['default_llm'] ?? ''));
            if (!str_contains($slug, '::')) {
                continue;
            }
            [$provider, $model] = explode('::', $slug, 2);
            $arret = LlmCatalog::retirement($provider, $model);
            if ($arret !== null && !$arret['retired']) {
                $assistants[] = (string)($p['name'] ?? '');
                if (!empty($p['id'])) {
                    $cibles['assistants']['profile:' . (int)$p['id']] = t('Son modèle va être arrêté par son éditeur le {date} : choisissez le remplaçant dans sa fiche, ou « Défaut application ».', ['date' => Format::dateFr($arret['date'])]);
                }
            }
        }

        $signals = [];
        if ($arretes !== []) {
            $signals[] = self::signal('llm_modeles_arretes', 'llms-keys', self::WARN,
                I18n::plural(count($arretes), '{n} modèle arrêté par son éditeur', '{n} modèles arrêtés par leur éditeur'),
                count($arretes) === 1
                    ? t('Modèle arrêté par son éditeur, retiré du chat : {noms}. Ce qui les utilisait répond avec le modèle par défaut : activez le remplaçant, puis retirez-le.', ['noms' => self::noms($arretes, false)])
                    : t('Modèles arrêtés par leur éditeur, retirés du chat : {noms}. Ce qui les utilisait répond avec le modèle par défaut : activez le remplaçant, puis retirez-les.', ['noms' => self::noms($arretes, false)]),
                false, $cibles['arretes']);
        }
        if ($annonces !== []) {
            $signals[] = self::signal('llm_arret_annonce', 'llms-keys', self::WARN,
                I18n::plural(count($annonces), '{n} modèle bientôt arrêté', '{n} modèles bientôt arrêtés'),
                count($annonces) === 1
                    ? t('Arrêt annoncé par l\'éditeur : {noms}. Activez le remplaçant et choisissez-le comme modèle par défaut ou de secours s\'il y a lieu : à cette date, MIA retirera ce modèle du chat.', ['noms' => self::noms($annonces, false)])
                    : t('Arrêt annoncé par l\'éditeur : {noms}. Activez le remplaçant et choisissez-le comme modèle par défaut ou de secours s\'il y a lieu : à cette date, MIA retirera ces modèles du chat.', ['noms' => self::noms($annonces, false)]),
                false, $cibles['annonces']);
        }
        if ($releves !== []) {
            $signals[] = self::signal('llm_arret_releve', 'llms-keys', self::WARN,
                I18n::plural(count($releves), '{n} arrêt relevé hors catalogue', '{n} arrêts relevés hors catalogue'),
                count($releves) === 1
                    ? t('Date d\'arrêt relevée chez l\'éditeur, absente du catalogue de MIA : {noms}. À cette date, MIA retire ce modèle du chat, sans remplaçant désigné : activez-en un et choisissez-le comme modèle par défaut ou de secours s\'il y a lieu. Signalez-le à l\'équipe qui maintient MIA, pour que le catalogue désigne un remplaçant.', ['noms' => self::noms($releves, false)])
                    : t('Dates d\'arrêt relevées chez l\'éditeur, absentes du catalogue de MIA : {noms}. À ces dates, MIA retire ces modèles du chat, sans remplaçant désigné : activez-en un et choisissez-le comme modèle par défaut ou de secours s\'il y a lieu. Signalez-le à l\'équipe qui maintient MIA, pour que le catalogue désigne un remplaçant.', ['noms' => self::noms($releves, false)]),
                false, $cibles['releves']);
        }
        if ($ecarts !== []) {
            $signals[] = self::signal('llm_arret_date_differente', 'llms-keys', self::WARN,
                I18n::plural(count($ecarts), '{n} date d\'arrêt à vérifier', '{n} dates d\'arrêt à vérifier'),
                t('La date d\'arrêt annoncée par l\'éditeur diffère de celle du catalogue de MIA : {noms}. MIA suit le catalogue : signalez l\'écart à l\'équipe qui maintient MIA.', ['noms' => self::noms($ecarts, false)]),
                false, $cibles['ecarts']);
        }
        if ($assistants !== []) {
            $signals[] = self::signal('assistant_modele_arret_annonce', 'profiles', self::WARN,
                I18n::plural(count($assistants), '{n} assistant sur un modèle bientôt arrêté', '{n} assistants sur un modèle bientôt arrêté'),
                count($assistants) === 1
                    ? t('Assistant dont le modèle va être arrêté par son éditeur : {noms}. À cette date, il répondra avec le modèle par défaut : choisissez le remplaçant dans sa fiche, ou « Défaut application ».', ['noms' => self::noms($assistants)])
                    : t('Assistants dont le modèle va être arrêté par son éditeur : {noms}. À cette date, ils répondront avec le modèle par défaut : choisissez le remplaçant dans leur fiche, ou « Défaut application ».', ['noms' => self::noms($assistants)]),
                false, $cibles['assistants'] ?? []);
        }
        return $signals;
    }

    /**
     * Groupes dont la liste de modèles autorisés ne désigne plus AUCUN modèle
     * disponible : leurs membres n'ont plus rien (sauf par un autre groupe),
     * et chaque message répond « Ce LLM n'est pas autorisé pour votre
     * groupe ». Une liste dont une partie seulement est morte reste inoffensive.
     *
     * @param array<string, list<string>> $lists       nom du groupe (actif) → slugs autorisés
     * @param array<string,string>        $available   LLMFactory::available()
     * @param list<string>                $keyProviders
     * @param array<string,int>           $groupIds    nom du groupe → identifiant (pastille sur sa carte)
     */
    public static function groupSignals(array $lists, array $available, array $keyProviders, array $groupIds = []): array
    {
        // Rien de disponible : les constats de Modèles IA passent d'abord.
        if ($keyProviders === [] || $available === []) {
            return [];
        }
        $vides  = [];
        $cibles = [];
        foreach ($lists as $nom => $slugs) {
            $vivants = array_filter($slugs, static fn($slug): bool => isset($available[(string)$slug]));
            if ($slugs !== [] && $vivants === []) {
                $vides[] = (string)$nom;
                if (isset($groupIds[(string)$nom])) {
                    $cibles['group:' . (int)$groupIds[(string)$nom]] = t('Aucun de ses modèles autorisés n\'est activé : ses membres n\'ont accès à aucun modèle, sauf par un autre groupe. Cochez un modèle activé dans ses droits d\'accès.');
                }
            }
        }
        if ($vides === []) {
            return [];
        }
        return [self::signal('groupe_sans_modele', 'user-groups', self::ALERT,
            I18n::plural(count($vides), '{n} groupe sans modèle utilisable', '{n} groupes sans modèle utilisable'),
            count($vides) === 1
                ? t('Groupe {noms} : aucun de ses modèles autorisés n\'est activé. Ses membres n\'ont accès à aucun modèle, sauf par un autre groupe : cochez un modèle activé dans ses droits d\'accès.', ['noms' => self::noms($vides)])
                : t('Groupes dont aucun modèle autorisé n\'est activé : {noms}. Leurs membres n\'ont accès à aucun modèle, sauf par un autre groupe : cochez un modèle activé dans leurs droits d\'accès.', ['noms' => self::noms($vides)]),
            false, $cibles)];
    }

    /**
     * Assistants : partagés mais autorisés dans aucun groupe actif (seuls les
     * administrateurs les voient), et assistants dont le modèle imposé n'est
     * plus activé (remplacé en silence par le défaut) ou n'a plus de clé.
     *
     * @param list<array<string,mixed>> $profiles     assistants ACTIFS : name, is_personal,
     *                                                is_global, default_llm, groups_n
     * @param array<string,string>      $available
     * @param list<string>              $keyProviders
     */
    public static function assistantSignals(array $profiles, array $available, array $keyProviders): array
    {
        $cles       = array_fill_keys($keyProviders, true);
        $sansGroupe = [];
        $retire     = [];
        $sansCle    = [];
        $cibles     = ['sansGroupe' => [], 'retire' => [], 'sansCle' => []];
        foreach ($profiles as $p) {
            $nom   = (string)($p['name'] ?? '');
            $cible = !empty($p['id']) ? 'profile:' . (int)$p['id'] : '';
            if ((int)($p['is_personal'] ?? 1) === 0 && (int)($p['is_global'] ?? 0) === 0 && (int)($p['groups_n'] ?? 0) === 0) {
                $sansGroupe[] = $nom;
                $cibles['sansGroupe'][$cible] = t('Partagé, mais autorisé dans aucun groupe : seuls les administrateurs le voient. Cochez-le dans Personnes › Groupes, ou repassez-le en personnel.');
            }
            $modele = trim((string)($p['default_llm'] ?? ''));
            // Sans clé du tout, le constat « Aucune clé API » passe d'abord.
            if ($modele === '' || $keyProviders === []) {
                continue;
            }
            if (!isset($available[$modele])) {
                $retire[] = $nom;
                $cibles['retire'][$cible] = t('Son modèle n\'est plus activé : il répond avec le modèle par défaut. Choisissez-lui un modèle activé, ou « Défaut application ».');
            } elseif (!isset($cles[LLMFactory::provider($modele)])) {
                $sansCle[] = $nom;
                $cibles['sansCle'][$cible] = t('Son modèle n\'a plus de clé API : chaque message échoue. Renseignez la clé, ou choisissez un autre modèle dans sa fiche.');
            }
        }

        $signals = [];
        if ($sansCle !== []) {
            $signals[] = self::signal('assistant_modele_sans_cle', 'profiles', self::ALERT,
                I18n::plural(count($sansCle), '{n} assistant sur un modèle sans clé', '{n} assistants sur un modèle sans clé'),
                count($sansCle) === 1
                    ? t('Assistant dont le modèle n\'a plus de clé API : {noms}. Chaque message échoue : renseignez la clé (Modèles IA › Fournisseurs et modèles) ou choisissez un autre modèle dans la fiche.', ['noms' => self::noms($sansCle)])
                    : t('Assistants dont le modèle n\'a plus de clé API : {noms}. Chaque message échoue : renseignez la clé (Modèles IA › Fournisseurs et modèles) ou choisissez un autre modèle dans la fiche.', ['noms' => self::noms($sansCle)]),
                false, $cibles['sansCle']);
        }
        if ($sansGroupe !== []) {
            $signals[] = self::signal('assistant_sans_groupe', 'profiles', self::WARN,
                I18n::plural(count($sansGroupe), '{n} assistant partagé sans groupe', '{n} assistants partagés sans groupe'),
                count($sansGroupe) === 1
                    ? t('Assistant partagé autorisé dans aucun groupe : {noms}. Seuls les administrateurs le voient : cochez-le dans Personnes › Groupes › Assistants autorisés, ou repassez-le en personnel.', ['noms' => self::noms($sansGroupe)])
                    : t('Assistants partagés autorisés dans aucun groupe : {noms}. Seuls les administrateurs les voient : cochez-les dans Personnes › Groupes › Assistants autorisés, ou repassez-les en personnel.', ['noms' => self::noms($sansGroupe)]),
                false, $cibles['sansGroupe']);
        }
        if ($retire !== []) {
            $signals[] = self::signal('assistant_modele_retire', 'profiles', self::WARN,
                I18n::plural(count($retire), '{n} assistant sur un modèle retiré', '{n} assistants sur un modèle retiré'),
                count($retire) === 1
                    ? t('Assistant dont le modèle n\'est plus activé : {noms}. Il répond avec le modèle par défaut : choisissez-lui un modèle activé, ou « Défaut application ».', ['noms' => self::noms($retire)])
                    : t('Assistants dont le modèle n\'est plus activé : {noms}. Ils répondent avec le modèle par défaut : choisissez-leur un modèle activé, ou « Défaut application ».', ['noms' => self::noms($retire)]),
                false, $cibles['retire']);
        }
        return $signals;
    }

    /**
     * Comptes en attente d'activation : inactifs et jamais utilisés (aucune
     * conversation). C'est l'arrivée ordinaire d'un collègue par Google ou
     * Microsoft quand l'activation automatique est coupée — et rien ne
     * prévenait personne : il voyait « compte inactif », l'administrateur rien.
     *
     * @param list<string|array{id:int, name:string}> $names comptes concernés, du plus
     *        récent au plus ancien (avec leur identifiant : pastille sur leur ligne)
     */
    public static function pendingAccountsSignals(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $cibles = [];
        foreach ($names as $i => $compte) {
            if (is_array($compte)) {
                $names[$i] = (string)($compte['name'] ?? '');
                if (!empty($compte['id'])) {
                    $cibles['user:' . (int)$compte['id']] = t('En attente d\'activation : activez ce compte, ou supprimez-le s\'il n\'a pas à utiliser MIA.');
                }
            }
        }
        $names = array_values(array_map('strval', $names));
        $n = count($names);
        return [self::signal('comptes_en_attente', 'users', self::WARN,
            I18n::plural($n, '{n} compte en attente d\'activation', '{n} comptes en attente d\'activation'),
            $n === 1
                ? t('Compte en attente d\'activation : {noms}. Activez-le, ou supprimez-le s\'il n\'a pas à utiliser MIA.', ['noms' => self::noms($names, false)])
                : t('{n} comptes en attente d\'activation : {noms}. Activez-les, ou supprimez ceux qui n\'ont pas à utiliser MIA.', ['n' => $n, 'noms' => self::noms($names, false)]),
            false, $cibles)];
    }

    /**
     * Comptes de démo dont l'enveloppe est épuisée : MIA ne leur répond plus,
     * c'est le moment de proposer la suite. Ni un compte désactivé (déjà
     * traité), ni une alerte masquée depuis la fiche du compte.
     *
     * @param list<array{name:string, email:string, is_active:bool, exhausted:bool, muted:bool}> $accounts DemoAccount::accounts()
     */
    public static function demoSignals(array $accounts): array
    {
        $names  = [];
        $cibles = [];
        foreach ($accounts as $a) {
            if (!empty($a['exhausted']) && empty($a['muted']) && !empty($a['is_active'])) {
                $names[] = trim((string)($a['name'] ?? '')) !== '' ? (string)$a['name'] : (string)($a['email'] ?? '');
                if (!empty($a['id'])) {
                    $cibles['user:' . (int)$a['id']] = t('Démo épuisée : MIA ne lui répond plus. Ajoutez des crédits, convertissez le compte, ou masquez cette alerte dans sa fiche.');
                }
            }
        }
        if ($names === []) {
            return [];
        }
        $n = count($names);
        return [self::signal('demos_epuisees', 'users', self::WARN,
            I18n::plural($n, '{n} démo épuisée', '{n} démos épuisées'),
            $n === 1
                ? t('Démo épuisée : {noms}. MIA ne lui répond plus : proposez la suite, ajoutez des crédits ou convertissez le compte depuis sa fiche — où vous pouvez aussi masquer cette alerte.', ['noms' => self::noms($names, false)])
                : t('{n} démos épuisées : {noms}. MIA ne leur répond plus : proposez la suite, ajoutez des crédits ou convertissez chaque compte depuis sa fiche — où vous pouvez aussi masquer cette alerte.', ['n' => $n, 'noms' => self::noms($names, false)]),
            false, $cibles)];
    }

    /**
     * Outils actifs : inutilisables (clé, URL ou source manquante), modèle
     * arrêté par son fournisseur, dernier test en échec, autorisés dans aucun
     * groupe — UN constat par outil, le plus grave. Plus les outils intégrés
     * de la voix, activés sans clé OpenAI : le micro et l'onde n'apparaissent
     * alors pas dans le chat — sauf Voxtral en vigueur, où l'onde se montre
     * avec sa pastille rouge et échoue sous les yeux des utilisateurs (alerte).
     *
     * @param list<array<string,mixed>> $tools            chat_tools (tous)
     * @param array<int,int>            $groupCounts      ChatTool::activeGroupCounts()
     * @param array<string,bool>        $providersWithKey fournisseur → clé API active
     */
    public static function toolSignals(
        array $tools,
        array $groupCounts,
        array $providersWithKey,
        bool $micEnabled,
        bool $voiceChatEnabled,
        bool $hasOpenAiKey,
        bool $voxtralInForce = false
    ): array {
        $parId = [];
        foreach ($tools as $t) {
            $parId[(int)$t['id']] = $t;
        }
        $libelles = [];
        foreach ($tools as $t) {
            $p = (string)($t['provider'] ?? '');
            $libelles[$p] = LLMFactory::providerLabel($p);
        }

        $inutilisables = [];
        $horsService   = [];
        $annonces      = [];
        $testsKo       = [];
        $sansGroupe    = [];
        $cibles        = ['inutilisables' => [], 'horsService' => [], 'annonces' => [], 'testsKo' => [], 'sansGroupe' => []];
        foreach ($tools as $t) {
            if ((int)($t['is_active'] ?? 0) !== 1) {
                continue;
            }
            $nom       = t('« {label} »', ['label' => (string)($t['name'] ?? '')]);
            $manquants = ChatTool::missingPrerequisites($t, $providersWithKey, $parId, $libelles);
            $arret     = ChatTool::retirement($t);
            $cible     = 'tool:' . (int)$t['id'];
            if ($manquants !== []) {
                $inutilisables[] = t('{nom} (il manque {elements})', ['nom' => $nom, 'elements' => self::et($manquants)]);
                $cibles['inutilisables'][$cible] = t('Inutilisable : il manque {elements}. Proposé dans le chat, il échoue à chaque demande.', ['elements' => self::et($manquants)]);
            } elseif ($arret !== null && $arret['retired']) {
                $horsService[] = $nom;
                $cibles['horsService'][$cible] = t('Modèle arrêté par son fournisseur : outil grisé dans le chat. Choisissez un autre modèle (« Modifier »), ou désactivez l\'outil.');
            } elseif (isset($t['last_test_ok']) && $t['last_test_ok'] !== null && (int)$t['last_test_ok'] === 0) {
                $testsKo[] = $nom;
                $cibles['testsKo'][$cible] = t('Dernier test en échec : outil écarté du chat. Corrigez sa configuration, puis relancez « Tester ».');
            } elseif ($arret !== null) {
                $annonces[] = t('{nom} le {date}', ['nom' => $nom, 'date' => Format::dateFr($arret['date'])]);
                $cibles['annonces'][$cible] = t('Arrêt du modèle annoncé le {date} : choisissez-en un autre avant cette date, sinon l\'outil sera grisé.', ['date' => Format::dateFr($arret['date'])]);
            } elseif ((int)($groupCounts[(int)$t['id']] ?? 0) === 0) {
                $sansGroupe[] = $nom;
                $cibles['sansGroupe'][$cible] = t('Autorisé dans aucun groupe : seuls les administrateurs le voient. Cochez-le dans Personnes › Groupes › Outils.');
            }
        }

        $signals = [];
        if ($inutilisables !== []) {
            $signals[] = self::signal('outils_inutilisables', 'tools', self::ALERT,
                I18n::plural(count($inutilisables), '{n} outil inutilisable', '{n} outils inutilisables'),
                count($inutilisables) === 1
                    ? t('Outil actif inutilisable : {outil}. Proposé dans le chat, il échoue à chaque demande.', ['outil' => $inutilisables[0]])
                    : t('Outils actifs inutilisables : {outils}. Proposés dans le chat, ils échouent à chaque demande.', ['outils' => self::nomsCites($inutilisables)]),
                false, $cibles['inutilisables']);
        }
        if ($horsService !== []) {
            $signals[] = self::signal('outils_hors_service', 'tools', self::WARN,
                I18n::plural(count($horsService), '{n} outil hors service', '{n} outils hors service'),
                count($horsService) === 1
                    ? t('Modèle arrêté par son fournisseur, outil grisé dans le chat : {outil}. Choisissez un autre modèle (« Modifier ») ou désactivez l\'outil.', ['outil' => $horsService[0]])
                    : t('Modèles arrêtés par leur fournisseur, outils grisés dans le chat : {outils}. Choisissez un autre modèle (« Modifier ») ou désactivez ces outils.', ['outils' => self::nomsCites($horsService)]),
                false, $cibles['horsService']);
        }
        if ($testsKo !== []) {
            $signals[] = self::signal('outils_test_echec', 'tools', self::WARN,
                I18n::plural(count($testsKo), '{n} test en échec', '{n} tests en échec'),
                count($testsKo) === 1
                    ? t('Dernier test en échec, outil écarté du chat : {outil}. Corrigez sa configuration, puis relancez « Tester ».', ['outil' => $testsKo[0]])
                    : t('Dernier test en échec, outils écartés du chat : {outils}. Corrigez leur configuration, puis relancez « Tester ».', ['outils' => self::nomsCites($testsKo)]),
                false, $cibles['testsKo']);
        }
        if ($annonces !== []) {
            $signals[] = self::signal('outils_arret_annonce', 'tools', self::WARN,
                I18n::plural(count($annonces), '{n} arrêt de modèle annoncé', '{n} arrêts de modèle annoncés'),
                count($annonces) === 1
                    ? t('Arrêt annoncé par le fournisseur : {outils}. Choisissez un autre modèle avant cette date, sinon l\'outil sera grisé.', ['outils' => self::nomsCites($annonces)])
                    : t('Arrêt annoncé par le fournisseur : {outils}. Choisissez un autre modèle avant ces dates, sinon ces outils seront grisés.', ['outils' => self::nomsCites($annonces)]),
                false, $cibles['annonces']);
        }
        if ($sansGroupe !== []) {
            $signals[] = self::signal('outils_sans_groupe', 'tools', self::WARN,
                I18n::plural(count($sansGroupe), '{n} outil sans groupe', '{n} outils sans groupe'),
                count($sansGroupe) === 1
                    ? t('Outil actif autorisé dans aucun groupe : {outil}. Seuls les administrateurs le voient : cochez-le dans Personnes › Groupes › Outils.', ['outil' => $sansGroupe[0]])
                    : t('Outils actifs autorisés dans aucun groupe : {outils}. Seuls les administrateurs les voient : cochez-les dans Personnes › Groupes › Outils.', ['outils' => self::nomsCites($sansGroupe)]),
                false, $cibles['sansGroupe']);
        }

        if (!$hasOpenAiKey && ($micEnabled || $voiceChatEnabled)) {
            [$court, $texte] = match (true) {
                $micEnabled && $voiceChatEnabled => [
                    t('voix sans clé OpenAI'),
                    t('La dictée vocale et la conversation vocale sont activées, mais aucune clé API OpenAI n\'est enregistrée : elles n\'apparaissent pas dans le chat. Renseignez une clé OpenAI, ou désactivez-les ici.'),
                ],
                $micEnabled => [
                    t('dictée vocale sans clé OpenAI'),
                    t('La dictée vocale est activée, mais aucune clé API OpenAI n\'est enregistrée : elle n\'apparaît pas dans le chat. Renseignez une clé OpenAI, ou désactivez-la ici.'),
                ],
                $voxtralInForce => [
                    t('conversation vocale impossible avec Voxtral'),
                    t('La conversation vocale est activée, mais Voxtral ne sert qu\'à la dictée et aucune clé API OpenAI n\'est enregistrée : son bouton porte une pastille rouge dans le chat et ne démarre pas. Renseignez une clé OpenAI, ou désactivez-la ici.'),
                ],
                default => [
                    t('conversation vocale sans clé OpenAI'),
                    t('La conversation vocale est activée, mais aucune clé API OpenAI n\'est enregistrée : elle n\'apparaît pas dans le chat. Renseignez une clé OpenAI, ou désactivez-la ici.'),
                ],
            };
            $voix = array_merge($micEnabled ? ['builtin:mic'] : [], $voiceChatEnabled ? ['builtin:voice'] : []);
            $signals[] = self::on(self::signal('voix_sans_openai', 'tools', $voxtralInForce && $voiceChatEnabled ? self::ALERT : self::WARN, $court, $texte), ...$voix);
        }
        return $signals;
    }

    /**
     * Voix de lecture de la conversation vocale : celle en vigueur a un arrêt
     * ANNONCÉ par son fournisseur (bloc `retirement` de
     * SpeechSynthesizer::ENGINES — gpt-4o-mini-tts, le 6 janvier 2027). Tant
     * qu'elle est la seule que l'instance emploie, l'administrateur le voit ;
     * à la date, la lecture passe d'elle-même sur son remplaçant (rien
     * d'arrêté ne reste en vigueur), et le constat s'éteint. Pure (le jour
     * vient de LlmCatalog::today, que les tests fixent).
     */
    public static function speechSignals(bool $voiceChatEnabled, ?string $effectiveEngine): array
    {
        if (!$voiceChatEnabled || $effectiveEngine === null) {
            return [];
        }
        $arret = SpeechSynthesizer::retirement($effectiveEngine);
        if ($arret === null || $arret['retired']) {
            return [];
        }
        $remplacant = SpeechSynthesizer::engineLabel($arret['replacement']);
        return [self::on(self::signal(
            'voix_lecture_arret_annonce',
            'tools',
            self::WARN,
            t('voix de lecture arrêtée le {date}', ['date' => Format::dateFr($arret['date'])]),
            t('La conversation vocale lit ses réponses avec {voix}, que son fournisseur arrête le {date}. Choisissez une autre voix de lecture sur la carte Conversation vocale ; sans choix, la lecture passera d\'elle-même sur {remplacant} ce jour-là.', [
                'voix'       => SpeechSynthesizer::engineLabel($effectiveEngine),
                'date'       => Format::dateFr($arret['date']),
                'remplacant' => $remplacant,
            ])
        ), 'builtin:voice')];
    }

    /**
     * Connecteurs autorisés mais indisponibles : leurs identifiants manquent,
     * donc ils n'apparaissent nulle part chez les utilisateurs — sans une
     * erreur. Regroupés par remède.
     *
     * @param list<array<string,mixed>> $rows catalogue + enabled + configured
     */
    public static function connectorSignals(array $rows): array
    {
        $parRemede = [];
        $cibles    = [];
        $n = 0;
        foreach ($rows as $c) {
            if (empty($c['enabled']) || !empty($c['configured'])) {
                continue;
            }
            $key    = (string)($c['key'] ?? '');
            $remede = match (true) {
                $key === ConnectorRegistry::ONEDRIVE            => t('application Azure absente (Capacités › Sources synchronisées)'),
                ($c['family'] ?? null) === 'google'             => t('identifiants Google absents (Organisation › Authentification)'),
                ($c['family'] ?? null) === 'microsoft'          => t('application Microsoft absente (Organisation › Authentification)'),
                ($c['family'] ?? null) === 'slack'              => t('application Slack absente (bouton « Configurer l\'application Slack »)'),
                default                                         => t('prérequis absent'),
            };
            $parRemede[$remede][] = (string)($c['label'] ?? $key);
            $cibles['connector:' . $key] = t('Activé, mais {remede} : il n\'apparaît pas chez les utilisateurs. Renseignez les identifiants, ou masquez-le.', ['remede' => $remede]);
            $n++;
        }
        if ($parRemede === []) {
            return [];
        }
        $details = '';
        foreach ($parRemede as $remede => $labels) {
            $partie  = t('{connecteurs} : {remede}', ['connecteurs' => implode(', ', $labels), 'remede' => $remede]);
            $details = $details === '' ? $partie : t('{a} ; {b}', ['a' => $details, 'b' => $partie]);
        }
        return [self::signal('connecteurs_indisponibles', 'connectors', self::WARN,
            I18n::plural($n, '{n} connecteur activé sans identifiants', '{n} connecteurs activés sans identifiants'),
            $n === 1
                ? t('Connecteur activé mais indisponible — {details}. Il n\'apparaît pas chez les utilisateurs : renseignez les identifiants, ou masquez ce connecteur.', ['details' => $details])
                : t('Connecteurs activés mais indisponibles — {details}. Ils n\'apparaissent pas chez les utilisateurs : renseignez les identifiants, ou masquez ces connecteurs.', ['details' => $details]),
            false, $cibles)];
    }

    /**
     * Bases de connaissances : cerveau MyBrain refusé (la base est grisée dans
     * le chat), source synchronisée dont les identifiants manquent (plus
     * jamais mise à jour, sans erreur), synchronisation en échec, et aucune
     * clé capable de vectoriser (recherche par mots seulement).
     *
     * @param list<array<string,mixed>> $kbs         bases ACTIVES hors base système
     * @param array<string,bool>        $configured  KbSync::configuredProviders()
     * @param bool                      $driveOAuth  GoogleDriveOAuth::isConfigured() (Drive lu par son propriétaire)
     */
    public static function kbSignals(array $kbs, array $configured, bool $driveOAuth, bool $hasEmbeddingKey): array
    {
        $refuses   = [];
        $sansSource = [];
        $enEchec   = [];
        $locales   = 0;
        $cibles    = ['refuses' => [], 'sansSource' => [], 'enEchec' => []];
        foreach ($kbs as $kb) {
            $nom   = (string)($kb['name'] ?? '');
            $cible = !empty($kb['id']) ? 'kb:' . (int)$kb['id'] : '';
            // Table permanente : ni source ni vectorisation, rien à signaler ici.
            if (KnowledgeBase::isTable($kb)) {
                continue;
            }
            if (KnowledgeBase::isRemote($kb)) {
                if ((string)($kb['remote_status'] ?? '') === KnowledgeBaseRemote::STATUS_REFUSED) {
                    $refuses[] = $nom;
                    $cibles['refuses'][$cible] = t('Clé refusée par MyBrain : base grisée dans le chat. « Modifier », puis « Tester et enregistrer » avec une clé valide.');
                }
                continue;
            }
            $locales++;
            $provider = (string)($kb['sync_provider'] ?? '');
            $synchro  = $provider !== ''
                && trim((string)($kb['sync_folder_ref'] ?? '')) !== ''
                && (int)($kb['sync_enabled'] ?? 0) === 1;
            if (!$synchro) {
                continue;
            }
            // Même règle que le déclencheur (KnowledgeBase::dueForSync) : un
            // Drive lu par son propriétaire passe par l'OAuth Google, le reste
            // par les identifiants de sa source.
            $pret = $provider === 'drive' && !empty($kb['sync_owner_user_id'])
                ? $driveOAuth
                : !empty($configured[$provider]);
            if (!$pret) {
                $sansSource[] = t('« {base} » ({source})', ['base' => $nom, 'source' => KbSync::providerLabel($provider)]);
                $cibles['sansSource'][$cible][] = t('Synchronisation impossible : les identifiants de {source} sont absents. La base n\'est plus mise à jour.', ['source' => KbSync::providerLabel($provider)]);
                // La carte de la source, sur la page des sources synchronisées
                // (un Drive lu par son propriétaire dépend, lui, de l'OAuth Google).
                if (!($provider === 'drive' && !empty($kb['sync_owner_user_id']))) {
                    $cibles['sansSource']['sync:' . $provider][] = t('« {base} » ne peut plus se synchroniser : renseignez les identifiants de cette source.', ['base' => $nom]);
                }
            } elseif ((string)($kb['sync_status'] ?? '') === 'error') {
                $enEchec[] = $nom;
                $cibles['enEchec'][$cible] = t('Synchronisation en échec : ouvrez la base pour lire le motif, puis « Synchroniser maintenant ».');
            }
        }

        $signals = [];
        if ($refuses !== []) {
            $signals[] = self::signal('mybrain_refuse', 'knowledge-bases', self::ALERT,
                I18n::plural(count($refuses), '{n} cerveau MyBrain refusé', '{n} cerveaux MyBrain refusés'),
                count($refuses) === 1
                    ? t('Clé refusée par MyBrain, base grisée dans le chat : {noms}. « Modifier », puis « Tester et enregistrer » avec une clé valide.', ['noms' => self::noms($refuses)])
                    : t('Clés refusées par MyBrain, bases grisées dans le chat : {noms}. « Modifier », puis « Tester et enregistrer » avec une clé valide.', ['noms' => self::noms($refuses)]),
                false, $cibles['refuses']);
        }
        if ($enEchec !== []) {
            $signals[] = self::signal('bases_sync_echec', 'knowledge-bases', self::WARN,
                I18n::plural(count($enEchec), '{n} synchronisation en échec', '{n} synchronisations en échec'),
                count($enEchec) === 1
                    ? t('Synchronisation en échec : {noms}. Ouvrez la base pour lire le motif, puis « Synchroniser maintenant ».', ['noms' => self::noms($enEchec)])
                    : t('Synchronisation en échec : {noms}. Ouvrez chaque base pour lire le motif, puis « Synchroniser maintenant ».', ['noms' => self::noms($enEchec)]),
                false, $cibles['enEchec']);
        }
        if ($sansSource !== []) {
            $signals[] = self::signal('bases_source_sans_identifiants', 'knowledge-bases-sync', self::WARN,
                I18n::plural(count($sansSource), '{n} source sans identifiants', '{n} sources sans identifiants'),
                count($sansSource) === 1
                    ? t('Synchronisation impossible, identifiants absents : {bases}. Cette base n\'est plus mise à jour : renseignez les identifiants de la source.', ['bases' => self::nomsCites($sansSource)])
                    : t('Synchronisation impossible, identifiants absents : {bases}. Ces bases ne sont plus mises à jour : renseignez les identifiants de la source.', ['bases' => self::nomsCites($sansSource)]),
                false, $cibles['sansSource']);
        }
        if ($locales > 0 && !$hasEmbeddingKey) {
            $signals[] = self::signal('bases_sans_vectorisation', 'knowledge-bases', self::WARN,
                t('aucune clé de vectorisation'),
                t('Aucune clé ne permet de vectoriser : les bases répondent en recherche par mots seulement, moins pertinente. Enregistrez une clé OpenAI, Mistral ou Gemini (Modèles IA › Fournisseurs et modèles), puis « Réindexer » les bases.'));
        }
        return $signals;
    }

    /**
     * Connexion Google ou Microsoft ACTIVÉE dont les identifiants manquent :
     * son bouton n'apparaît pas sur la page de connexion, sans un mot.
     *
     * @param array<string, array{0:bool, 1:bool}> $methods libellé → [activée, configurée]
     */
    public static function ssoSignals(array $methods): array
    {
        $manquants = [];
        $cibles    = [];
        foreach ($methods as $libelle => [$active, $configuree]) {
            if ($active && !$configuree) {
                $manquants[] = (string)$libelle;
                $cibles['sso:' . strtolower((string)$libelle)] = t('Activée sans identifiants : son bouton n\'apparaît pas sur la page de connexion. Renseignez-les, ou désactivez cette connexion.');
            }
        }
        if ($manquants === []) {
            return [];
        }
        $methodes = self::et($manquants);
        return [count($manquants) === 1
            ? self::signal('sso_sans_identifiants', 'sso', self::WARN,
                t('{methodes} activé sans identifiants', ['methodes' => $methodes]),
                t('Connexion {methodes} activée, mais sans identifiants : son bouton n\'apparaît pas sur la page de connexion. Renseignez-les, ou désactivez cette connexion.', ['methodes' => $methodes]),
                false, $cibles)
            : self::signal('sso_sans_identifiants', 'sso', self::WARN,
                t('{methodes} activés sans identifiants', ['methodes' => $methodes]),
                t('Connexions {methodes} activées, mais sans identifiants : leurs boutons n\'apparaissent pas sur la page de connexion. Renseignez-les, ou désactivez ces connexions.', ['methodes' => $methodes]),
                false, $cibles)];
    }

    /**
     * Worker de générations arrêté (il battait, il ne bat plus) ou resté sur
     * une ancienne version. Un worker JAMAIS vu n'est pas signalé : c'est une
     * installation sans worker (poste de développement, hébergement sans
     * Docker), où les générations passent par le fork — le Diagnostic le dit.
     *
     * @param array{at:int, version:string}|null $heartbeat JobQueue::workerHeartbeat()
     */
    public static function workerSignal(?array $heartbeat, string $appVersion, int $now): ?array
    {
        if ($heartbeat === null || (int)$heartbeat['at'] <= 0) {
            return null;
        }
        $age = $now - (int)$heartbeat['at'];
        if ($age >= self::WORKER_SILENCE_SEC) {
            return self::on(self::signal('worker', 'diagnostics', self::WARN, t('worker arrêté'),
                t('Le worker de générations ne répond plus depuis {duree} : pages, images, vidéos et présentations passent en mode dégradé. Démarrez-le (docker compose up -d worker).', ['duree' => self::duree($age)])), 'diag:worker');
        }
        $version = (string)$heartbeat['version'];
        if ($appVersion !== '' && $version !== $appVersion) {
            return self::on(self::signal('worker', 'diagnostics', self::WARN, t('worker à reconstruire'),
                t('Le worker tourne sur une ancienne version ({version} au lieu de {app}) : les générations passent en mode dégradé. Reconstruisez-le (docker compose build && docker compose up -d worker).', [
                    'version' => $version !== '' ? $version : t('inconnue'),
                    'app'     => $appVersion,
                ])), 'diag:worker');
        }
        return null;
    }

    /**
     * Comptes fournisseur que les demandes des utilisateurs trouvent fermés :
     * crédit épuisé (ou plafond de dépense atteint), clé refusée. Sans ce
     * constat, l'administration ne l'apprenait que si quelqu'un prenait la
     * peine de le lui écrire — et jamais quand le LLM de secours masquait la
     * panne. Un constat par fournisseur ; il s'éteint de lui-même dès qu'une
     * opération de ce fournisseur aboutit (cf. ProviderIncident).
     *
     * @param array<string, array{kind:string, first:int, last:int, count:int}> $incidents ProviderIncident::active()
     */
    public static function providerIncidentSignals(array $incidents, int $now): array
    {
        $signals = [];
        foreach ($incidents as $provider => $e) {
            $nom    = LLMFactory::providerLabel((string)$provider);
            $refus  = (int)$e['count'];
            $depuis = t('depuis {duree} ({demandes})', [
                'duree'    => self::duree(max(0, $now - (int)$e['first'])),
                'demandes' => I18n::plural($refus, '{n} demande refusée', '{n} demandes refusées'),
            ]);
            if ($e['kind'] === 'quota') {
                $signals[] = self::on(self::signal('incident_' . $provider, 'llms-keys', self::ALERT,
                    t('crédit épuisé chez {fournisseur}', ['fournisseur' => $nom]),
                    t('{fournisseur} refuse les demandes faute de crédit {depuis} : le solde du compte est épuisé ou son plafond de dépense est atteint. Rechargez-le chez {fournisseur}, puis vérifiez avec « Tester ». Le signal s\'éteint dès que le compte répond de nouveau.', ['fournisseur' => $nom, 'depuis' => $depuis])), 'provider:' . $provider);
            } elseif ($e['kind'] === 'auth') {
                $signals[] = self::on(self::signal('incident_' . $provider, 'llms-keys', self::ALERT,
                    t('clé {fournisseur} refusée', ['fournisseur' => $nom]),
                    t('{fournisseur} refuse la clé API de l\'instance {depuis} : elle est invalide, révoquée ou expirée. Enregistrez une nouvelle clé. Le signal s\'éteint dès que le compte répond de nouveau.', ['fournisseur' => $nom, 'depuis' => $depuis])), 'provider:' . $provider);
            }
        }
        return $signals;
    }

    // ================================================================ Lectures

    /** @return list<string> fournisseurs dont une clé est active — prédicat d'ApiKey::hasActiveLlm(). */
    private static function readKeyProviders(): array
    {
        $rows = Database::getInstance()
            ->query('SELECT provider FROM api_keys WHERE is_active = 1 ORDER BY provider ASC')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('strval', $rows)));
    }

    /**
     * Assistants ACTIFS et nombre de groupes ACTIFS qui les autorisent — même
     * portée que Profile::accessibleByUser (un groupe désactivé n'ouvre rien).
     *
     * @return list<array<string,mixed>>
     */
    private static function readProfiles(): array
    {
        return Database::getInstance()->query(
            'SELECT p.id, p.name, p.is_personal, p.is_global, p.default_llm,
                    (SELECT COUNT(*) FROM user_group_profiles ugp
                       JOIN user_groups g ON g.id = ugp.group_id AND g.is_active = 1
                      WHERE ugp.profile_id = p.id) AS groups_n
             FROM profiles p
             WHERE p.is_active = 1
             ORDER BY p.name ASC'
        )->fetchAll();
    }

    /** @return array{0: array<string, list<string>>, 1: array<string,int>} nom du groupe ACTIF → modèles autorisés (listes non vides), et nom → identifiant. */
    private static function readGroupLists(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT g.id, g.name, ugl.llm_key
             FROM user_groups g
             JOIN user_group_llms ugl ON ugl.group_id = g.id
             WHERE g.is_active = 1
             ORDER BY g.name ASC'
        )->fetchAll();
        $lists = [];
        $ids   = [];
        foreach ($rows as $r) {
            $lists[(string)$r['name']][] = (string)$r['llm_key'];
            $ids[(string)$r['name']]     = (int)$r['id'];
        }
        return [$lists, $ids];
    }

    /** @return list<array{id:int, name:string}> comptes inactifs sans aucune conversation, du plus récent au plus ancien. */
    private static function readPendingAccounts(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT u.id, u.name, u.email FROM users u
             WHERE u.is_active = 0
               AND NOT EXISTS (SELECT 1 FROM conversations c WHERE c.user_id = u.id)
             ORDER BY u.created_at DESC, u.id DESC'
        )->fetchAll();
        return array_map(
            static fn(array $r): array => [
                'id'   => (int)$r['id'],
                'name' => trim((string)$r['name']) !== '' ? (string)$r['name'] : (string)$r['email'],
            ],
            $rows
        );
    }

    /**
     * @return list<array<string,mixed>> bases actives hors base système et hors
     * bases « Uniquement pour moi » (leur créateur suit leur état sur sa
     * carte ; les nommer ici les montrerait aux administrateurs), avec l'état
     * de leur connexion en direct.
     */
    private static function readKnowledgeBases(): array
    {
        return Database::getInstance()->query(
            'SELECT kb.id, kb.name, kb.backend, kb.sync_provider, kb.sync_folder_ref, kb.sync_enabled,
                    kb.sync_status, kb.sync_owner_user_id, r.status AS remote_status
             FROM knowledge_bases kb
             LEFT JOIN knowledge_base_remotes r ON r.kb_id = kb.id
             WHERE kb.is_active = 1 AND kb.is_system = 0 AND kb.is_private = 0
             ORDER BY kb.name ASC'
        )->fetchAll();
    }

    // ================================================================ Outils

    /**
     * @param array<string, string|list<string>> $targets éléments concernés
     *        (« tool:12 », « provider:openai »…) → ce que dit LEUR pastille
     * @return array{id:string, entry:string, level:string, short:string, text:string, dedicated:bool, targets:array<string, list<string>>}
     */
    private static function signal(string $id, string $entry, string $level, string $short, string $text, bool $dedicated = false, array $targets = []): array
    {
        $cibles = [];
        foreach ($targets as $cible => $lignes) {
            $lignes = array_values(array_filter(array_map('strval', (array)$lignes), static fn(string $l): bool => $l !== ''));
            if ($cible !== '' && $lignes !== []) {
                $cibles[(string)$cible] = $lignes;
            }
        }
        return [
            'id'        => $id,
            'entry'     => $entry,
            'level'     => $level,
            'short'     => $short,
            'text'      => $text,
            'dedicated' => $dedicated,
            'targets'   => $cibles,
        ];
    }

    /** Pose le texte du constat sur des éléments qui n'ont pas d'explication plus précise. */
    private static function on(array $signal, string ...$cibles): array
    {
        foreach ($cibles as $cible) {
            $signal['targets'][$cible][] = (string)$signal['text'];
        }
        return $signal;
    }

    /** Ordre du menu, puis les alertes avant les avertissements. */
    private static function ordered(array $signals): array
    {
        $rang = array_flip(array_keys(self::ENTRIES));
        usort($signals, static function (array $a, array $b) use ($rang): int {
            return [($rang[$a['entry']] ?? 99), $a['level'] === self::ALERT ? 0 : 1]
                <=> [($rang[$b['entry']] ?? 99), $b['level'] === self::ALERT ? 0 : 1];
        });
        return $signals;
    }

    /** « 12 minutes », « 3 heures », « 2 jours » : l'unité qui se lit d'un coup d'œil. */
    private static function duree(int $secondes): string
    {
        $minutes = max(1, intdiv($secondes, 60));
        if ($minutes < 120) {
            return I18n::plural($minutes, '{n} minute', '{n} minutes');
        }
        $heures = intdiv($minutes, 60);
        if ($heures < 48) {
            return I18n::plural($heures, '{n} heure', '{n} heures');
        }
        $jours = intdiv($heures, 24);
        return I18n::plural($jours, '{n} jour', '{n} jours');
    }

    /** « « A », « B » et 2 autres » — noms bornés à NOMS_MAX. */
    private static function noms(array $noms, bool $guillemets = true): string
    {
        $noms = array_values($noms);
        $vus  = array_slice($noms, 0, self::NOMS_MAX);
        if ($guillemets) {
            $vus = array_map(static fn(string $n): string => t('« {label} »', ['label' => $n]), $vus);
        }
        $liste = implode(', ', $vus);
        $reste = count($noms) - count($vus);
        return $reste > 0 ? I18n::plural($reste, '{liste} et {n} autre', '{liste} et {n} autres', ['liste' => $liste]) : $liste;
    }

    /** « A et B », « A et B et C » — énumération dans la langue de la page. */
    private static function et(array $items): string
    {
        $sortie = '';
        foreach (array_values($items) as $i => $item) {
            $sortie = $i === 0 ? (string)$item : t('{a} et {b}', ['a' => $sortie, 'b' => (string)$item]);
        }
        return $sortie;
    }

    /** Même bornage pour des libellés déjà cités (« « X » (il manque …) »). */
    private static function nomsCites(array $noms): string
    {
        return self::noms($noms, false);
    }

    /** Libellé lisible d'un slug LLM, même retiré : liste active, sinon catalogue, sinon le slug. */
    private static function llmLabel(string $slug, array $available): string
    {
        if (isset($available[$slug])) {
            return (string)$available[$slug];
        }
        if (str_contains($slug, '::')) {
            [$provider, $model] = explode('::', $slug, 2);
            $label = (string)(LlmCatalog::models($provider)[$model]['label'] ?? '');
            if ($label !== '') {
                return $label;
            }
        }
        return $slug;
    }
}
