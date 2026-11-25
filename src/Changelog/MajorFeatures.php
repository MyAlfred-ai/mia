<?php

declare(strict_types=1);

/**
 * Nouveautés MAJEURES — la fenêtre « Quoi de neuf » montrée une seule fois à
 * chaque personne, puis la pastille verte posée sur l'endroit où les trouver.
 *
 * Le problème résolu : la fenêtre « Nouveautés » (clic sur le numéro de
 * version) liste TOUT — améliorations et correctifs compris — et attend qu'on
 * vienne la chercher. L'arrivée d'une fonctionnalité entière (les workflows,
 * Claude dans Office) passait donc inaperçue de qui ne clique jamais ce numéro.
 *
 * Trois règles :
 *
 *  1. MAJEURE = UNE FONCTIONNALITÉ ENTIÈRE QUI ARRIVE. Jamais une amélioration
 *     ni un correctif. Chaque entrée de CATALOG porte l'identifiant d'une
 *     entrée de `docs/NOUVEAUTES.md` marquée `majeure: oui` (testé dans les
 *     deux sens) : le fichier dit ce qui est majeur, ce catalogue dit comment
 *     le présenter (deux phrases, une image, l'endroit où aller) et à qui.
 *  2. ON NE MONTRE QUE CE QUE LA PERSONNE PEUT UTILISER (`available`) : les
 *     workflows à qui les ouvre (créateurs et administrateurs par défaut),
 *     Claude dans Office seulement une fois activé par l'administrateur.
 *  3. UNE SEULE FOIS, PAR COMPTE (table `user_feature_news`, jamais le
 *     navigateur) : vue sur l'ordinateur, la fenêtre ne revient pas sur le
 *     téléphone. La pastille s'éteint dès que la personne ouvre la page où se
 *     trouve la nouveauté, et d'elle-même DOT_DAYS jours après la fenêtre.
 *
 * Qui voit quoi (`plan`, pure) : une nouveauté publiée APRÈS la création du
 * compte (un compte neuf découvre tout, rien ne lui est « nouveau »), depuis
 * moins de MAX_AGE_DAYS jours, au plus MAX_POPUP par fenêtre — les plus
 * récentes ; les autres sont comptées vues avec elles, pour ne pas revenir à
 * la page suivante.
 *
 * Lecture : UNE requête par page (mémorisée pour la requête), aucune quand le
 * catalogue est vide. Ne lève jamais : une panne de base ne coûte qu'une
 * fenêtre qui ne s'ouvre pas.
 */
final class MajorFeatures
{
    /** Nouveautés au plus dans une même fenêtre (maquette C : côte à côte). */
    public const MAX_POPUP = 3;

    /** Au-delà, une nouveauté n'est plus « nouvelle » pour qui revient. */
    public const MAX_AGE_DAYS = 90;

    /** La pastille s'éteint d'elle-même ce nombre de jours après la fenêtre. */
    public const DOT_DAYS = 30;

    /** Où se trouve une nouveauté : un espace de travail, Mon compte, l'administration. */
    public const PLACE_SPACE   = 'espace';
    public const PLACE_ACCOUNT = 'compte';
    public const PLACE_ADMIN   = 'admin';

    /**
     * Les nouveautés majeures, de la plus récente à la plus ancienne.
     *
     *   - `date`      : jour de publication (AAAA-MM-JJ), celui de son entrée
     *                   dans docs/NOUVEAUTES.md ;
     *   - `titre`, `texte` : FRANÇAIS, clés de traduction (lang/en.php) ; deux
     *                   phrases au plus, pour quelqu'un qui n'est pas technicien ;
     *   - `schema`    : le SCHÉMA ANIMÉ qui présente la nouveauté dans la fenêtre,
     *                   templates/partials/feature-news/<schema>.php — un exemple
     *                   concret, en vrais mots traduits (obligatoire, testé) ;
     *   - `image`     : repli si le schéma manque, sous /assets/img/nouveautes/ ;
     *   - `cible`     : [lieu, clé] — l'espace de travail (NavRegistry::WORKSPACE),
     *                   la page de Mon compte (NavRegistry::ACCOUNT) ou la page
     *                   d'administration (AdminAccess::PAGES) où aller ;
     *   - `ancre`     : facultative, la carte à montrer sur cette page ;
     *   - `condition` : facultative, ce qu'il faut en plus pour y avoir accès
     *                   (cf. conditionMet).
     */
    public const CATALOG = [
        // Décision d'Axel (2026-11-10) : l'arrivée de ces modèles compte comme
        // une nouveauté majeure. Elle ne s'adresse qu'à qui ouvre Modèles IA
        // (droit `llms.view` : les administrateurs par défaut), seul à pouvoir
        // les proposer à l'équipe.
        'catalogue-llm-novembre-2026' => [
            'date'      => '2026-11-10',
            'titre'     => 'Claude Fable 5.2, Gemini 4 Argon et GPT-6.1 Terra',
            'texte'     => 'Les derniers modèles d’Anthropic, de Google et d’OpenAI sont arrivés : activez ceux que vous voulez proposer à votre équipe, avec le coût de chaque réponse.',
            'image'     => '/assets/img/nouveautes/nouveaux-modeles.svg',
            'schema'    => 'nouveaux-modeles',
            'cible'     => [self::PLACE_ADMIN, 'llms-keys'],
        ],
        'claude-dans-excel' => [
            'date'      => '2026-11-08',
            'titre'     => 'Claude dans Excel, Word et PowerPoint',
            'texte'     => 'Demandez à Claude d’analyser un tableau, de rédiger un document ou de préparer des diapositives, directement dans Office, avec votre compte MIA.',
            'image'     => '/assets/img/nouveautes/claude-office.svg',
            'schema'    => 'claude-dans-excel',
            'cible'     => [self::PLACE_ACCOUNT, 'connections'],
            'ancre'     => 'office-addin',
            'condition' => 'office',
        ],
        'workflows' => [
            'date'      => '2026-10-15',
            'titre'     => 'Les workflows',
            'texte'     => 'Décrivez une fois un travail en plusieurs étapes : MIA lit, rédige, vous demande votre accord et livre, toujours de la même façon.',
            'image'     => '/assets/img/nouveautes/workflows.svg',
            'schema'    => 'workflows',
            'cible'     => [self::PLACE_SPACE, 'workflows'],
        ],
    ];

    /** Catalogue de substitution (tests) ; null = CATALOG. */
    private static ?array $catalogOverride = null;

    /** Jour de substitution (tests), AAAA-MM-JJ ; null = aujourd'hui. */
    private static ?string $today = null;

    /** État calculé pour la requête, par compte. @var array<int, array> */
    private static array $memo = [];

    /** Pages dont la visite a déjà été consignée dans cette requête. @var array<string, true> */
    private static array $visitedPages = [];

    /** Nouveautés dont la pastille menait à la page ouverte (cette requête). @var list<string> */
    private static array $arrivedHere = [];

    // ===================== Catalogue =====================

    /** @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        return self::$catalogOverride ?? self::CATALOG;
    }

    /** Tests : remplace le catalogue (null rétablit le vrai). */
    public static function overrideCatalog(?array $catalog): void
    {
        self::$catalogOverride = $catalog;
        self::forget();
    }

    /** Tests : fixe le jour (null rétablit aujourd'hui). */
    public static function setToday(?string $day): void
    {
        self::$today = $day;
        self::forget();
    }

    public static function today(): string
    {
        return self::$today ?? date('Y-m-d');
    }

    /** Oublie l'état mémorisé (tests, ou après avoir écrit). */
    public static function forget(): void
    {
        self::$memo = [];
        self::$visitedPages = [];
        self::$arrivedHere = [];
    }

    /**
     * Page où se trouve une nouveauté : [lieu, clé, valeur de `$page` de son
     * gabarit, adresse]. Null si la cible ne désigne rien de connu.
     *
     * @return array{place:string, key:string, page:string, href:string}|null
     */
    public static function target(array $feature): ?array
    {
        [$place, $key] = array_pad((array)($feature['cible'] ?? []), 2, '');
        $place = (string)$place;
        $key   = (string)$key;
        $entry = match ($place) {
            self::PLACE_SPACE   => NavRegistry::WORKSPACE[$key] ?? null,
            self::PLACE_ACCOUNT => NavRegistry::ACCOUNT[$key] ?? null,
            self::PLACE_ADMIN   => isset(AdminAccess::PAGE_HREFS[$key]) ? ['page' => $key, 'href' => AdminAccess::PAGE_HREFS[$key]] : null,
            default             => null,
        };
        if ($entry === null) {
            return null;
        }
        return ['place' => $place, 'key' => $key, 'page' => (string)$entry['page'], 'href' => (string)$entry['href']];
    }

    /**
     * Présentation d'une nouveauté, TRADUITE : ce que lit la fenêtre.
     *
     * @return array{id:string, titre:string, texte:string, image:string, lien:string, date:string}|null
     */
    public static function item(string $id): ?array
    {
        $f = self::catalog()[$id] ?? null;
        $cible = $f === null ? null : self::target($f);
        if ($cible === null) {
            return null;
        }
        $ancre = (string)($f['ancre'] ?? '');
        return [
            'id'    => $id,
            'titre' => t((string)$f['titre']),
            'texte' => t((string)$f['texte']),
            'image' => (string)($f['image'] ?? ''),
            'schema' => (string)($f['schema'] ?? ''),
            'lien'  => $cible['href'] . ($ancre !== '' ? '#' . $ancre : ''),
            'date'  => (string)$f['date'],
        ];
    }

    // ===================== Accès =====================

    /** La personne peut-elle se servir de cette nouveauté, ici et maintenant ? */
    public static function available(array $user, array $feature): bool
    {
        $cible = self::target($feature);
        if ($cible === null) {
            return false;
        }
        $ouverte = match ($cible['place']) {
            self::PLACE_SPACE   => isset(NavRegistry::workspaceFor($user)[$cible['key']]),
            self::PLACE_ACCOUNT => isset(NavRegistry::accountFor($user)[$cible['key']]),
            self::PLACE_ADMIN   => AdminAccess::canOpen($cible['key'], $user),
            default             => false,
        };
        return $ouverte && self::conditionMet((string)($feature['condition'] ?? ''), $user);
    }

    /** Ce qu'une nouveauté exige en plus de sa page (réglage de l'organisation, droit). */
    public static function conditionMet(string $condition, array $user): bool
    {
        return match ($condition) {
            ''       => true,
            'office' => OfficeAddin::isEnabled() && Role::userCan($user, 'office_addin.use'),
            default  => false, // condition inconnue : on ne promet rien
        };
    }

    // ===================== Décision (pure) =====================

    /**
     * Ce qu'il faut montrer à une personne.
     *
     * @param array<string, string>        $dates     id → date de publication
     * @param array<string, array{shown_days:?int, visited:bool}> $rows ce qu'elle a déjà vu
     * @param list<string>                 $available nouveautés auxquelles elle a accès
     * @param string                       $created   jour de création du compte (AAAA-MM-JJ)
     * @param string                       $today     AAAA-MM-JJ
     * @return array{popup:list<string>, pending:list<string>, dots:list<string>}
     *   `popup` = les MAX_POPUP plus récentes à montrer ; `pending` = toutes
     *   celles que la fenêtre compte vues (popup + celles qu'elle n'a pas la
     *   place de montrer) ; `dots` = celles qui portent une pastille.
     */
    public static function plan(array $dates, array $rows, array $available, string $created, string $today): array
    {
        arsort($dates, SORT_STRING); // la plus récente d'abord (dates AAAA-MM-JJ)
        $pending = [];
        $dots    = [];
        foreach ($dates as $id => $date) {
            $id = (string)$id;
            if (!in_array($id, $available, true) || $date > $today) {
                continue;
            }
            $row       = $rows[$id] ?? null;
            $visited   = (bool)($row['visited'] ?? false);
            $shownDays = $row['shown_days'] ?? null;
            if ($shownDays === null) {
                // Jamais montrée : seulement si le compte existait avant elle
                // et qu'elle est encore récente.
                if ($date <= $created || self::daysBetween($date, $today) > self::MAX_AGE_DAYS) {
                    continue;
                }
                $pending[] = $id;
                if (!$visited) {
                    $dots[] = $id;
                }
                continue;
            }
            if (!$visited && (int)$shownDays <= self::DOT_DAYS) {
                $dots[] = $id;
            }
        }
        return [
            'popup'   => array_slice($pending, 0, self::MAX_POPUP),
            'pending' => $pending,
            'dots'    => $dots,
        ];
    }

    /** Jours entre deux dates AAAA-MM-JJ (négatif si $to précède $from). */
    public static function daysBetween(string $from, string $to): int
    {
        try {
            $a = new DateTimeImmutable($from, new DateTimeZone('UTC'));
            $b = new DateTimeImmutable($to, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }
        return (int)$a->diff($b)->format('%r%a');
    }

    // ===================== Persistance =====================

    /** @return array<string, array{shown_days:?int, visited:bool}> */
    public static function rowsFor(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT feature_id,
                    CASE WHEN shown_at IS NULL THEN NULL ELSE DATEDIFF(CURDATE(), DATE(shown_at)) END AS shown_days,
                    visited_at IS NOT NULL AS visited
               FROM user_feature_news WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['feature_id']] = [
                'shown_days' => $r['shown_days'] === null ? null : max(0, (int)$r['shown_days']),
                'visited'    => (bool)$r['visited'],
            ];
        }
        return $out;
    }

    /** La fenêtre a montré ces nouveautés : elles ne reviendront plus. */
    public static function markShown(int $userId, array $ids): void
    {
        $ids = self::knownIds($ids);
        if ($ids === []) {
            return;
        }
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO user_feature_news (user_id, feature_id, shown_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE shown_at = COALESCE(shown_at, NOW())'
        );
        foreach ($ids as $id) {
            $stmt->execute([$userId, $id]);
        }
        unset(self::$memo[$userId]);
    }

    /**
     * La personne a ouvert la page de ces nouveautés : pastille éteinte, et
     * plus de fenêtre pour elles — elle les a trouvées seule.
     */
    public static function markVisited(int $userId, array $ids): void
    {
        $ids = self::knownIds($ids);
        if ($ids === []) {
            return;
        }
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO user_feature_news (user_id, feature_id, shown_at, visited_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE shown_at = COALESCE(shown_at, NOW()), visited_at = COALESCE(visited_at, NOW())'
        );
        foreach ($ids as $id) {
            $stmt->execute([$userId, $id]);
        }
        unset(self::$memo[$userId]);
    }

    /** @return list<string> identifiants du catalogue, sans doublon */
    private static function knownIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string)$id;
            if (isset(self::catalog()[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    // ===================== Pour l'écran =====================

    /**
     * État de la personne connectée pour CETTE page : consigne d'abord la
     * visite de `$page` (la pastille des nouveautés qui s'y trouvent
     * s'éteint), puis rend ce qu'il faut montrer. Mémorisé pour la requête.
     *
     * @return array{popup:list<array>, pending:list<string>, dots:list<string>}
     */
    public static function forViewer(?array $user, string $page = ''): array
    {
        $vide = ['popup' => [], 'pending' => [], 'dots' => []];
        $uid  = (int)($user['id'] ?? 0);
        if ($user === null || $uid <= 0 || self::catalog() === []) {
            return $vide;
        }
        try {
            $visitKey = $uid . '|' . $page;
            if ($page !== '' && !isset(self::$visitedPages[$visitKey])) {
                self::$visitedPages[$visitKey] = true;
                $ici = [];
                foreach (self::catalog() as $id => $f) {
                    $cible = self::target($f);
                    if ($cible !== null && $cible['page'] === $page && self::available($user, $f)) {
                        $ici[] = (string)$id;
                    }
                }
                if ($ici !== []) {
                    $rows = self::rowsFor($uid);
                    $aConsigner = array_values(array_filter($ici, static fn(string $id): bool => empty($rows[$id]['visited'])));
                    if ($aConsigner !== []) {
                        // Celles qui portaient une pastille AVANT cette visite :
                        // leur carte la porte encore, le temps de la page.
                        $avant = self::$memo[$uid]['dots'] ?? self::forViewer($user)['dots'];
                        self::$arrivedHere = array_values(array_intersect($aConsigner, $avant));
                        self::markVisited($uid, $aConsigner);
                    }
                }
            }
            if (isset(self::$memo[$uid])) {
                return self::$memo[$uid];
            }

            $dates = [];
            $available = [];
            foreach (self::catalog() as $id => $f) {
                $dates[(string)$id] = (string)$f['date'];
                if (self::available($user, $f)) {
                    $available[] = (string)$id;
                }
            }
            if ($available === []) {
                return self::$memo[$uid] = $vide;
            }
            $created = substr((string)($user['created_at'] ?? ''), 0, 10);
            if ($created === '') {
                $created = '0000-00-00';
            }
            $plan = self::plan($dates, self::rowsFor($uid), $available, $created, self::today());
            $popup = [];
            foreach ($plan['popup'] as $id) {
                $item = self::item($id);
                if ($item !== null) {
                    $popup[] = $item;
                }
            }
            return self::$memo[$uid] = ['popup' => $popup, 'pending' => $plan['pending'], 'dots' => $plan['dots']];
        } catch (\Throwable $e) {
            error_log('[Nouveautés majeures] ' . $e->getMessage());
            return self::$memo[$uid] = $vide;
        }
    }

    /** Nouveautés à pastille de la personne connectée, en un lieu (et une clé). @return list<string> */
    public static function dotsAt(string $place, ?string $key = null): array
    {
        $state = self::forViewer(Session::currentUser());
        $out = [];
        foreach ($state['dots'] as $id) {
            $cible = self::target(self::catalog()[$id] ?? []);
            if ($cible !== null && $cible['place'] === $place && ($key === null || $cible['key'] === $key)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * La pastille verte d'une entrée (vide s'il n'y a rien de nouveau). Une
     * seule forme pour toutes les surfaces : tuile, bouton, menu. `role="img"`
     * et son nom accessible disent « Nouveau » aux lecteurs d'écran, au milieu
     * du nom du lien qui la porte.
     *
     * @param list<string> $ids nouveautés signalées
     */
    public static function dotHtml(array $ids, string $extraClass = ''): string
    {
        if ($ids === []) {
            return '';
        }
        $titres = [];
        foreach ($ids as $id) {
            $item = self::item($id);
            if ($item !== null) {
                $titres[] = $item['titre'];
            }
        }
        $label = $titres === [] ? t('Nouveau') : t('Nouveau : {titre}', ['titre' => implode(', ', $titres)]);
        // Le titre vient en info-bulle (nav-signals.js : survol, clavier,
        // toucher), jamais en `title` natif — une seule bulle, verte.
        return '<span class="news-dot' . ($extraClass !== '' ? ' ' . $extraClass : '') . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES) . '" data-tip="' . htmlspecialchars($label, ENT_QUOTES) . '" data-tip-tone="news"></span>';
    }

    /** Raccourci : pastille d'un lieu (et d'une clé). */
    public static function dotAt(string $place, ?string $key = null, string $extraClass = ''): string
    {
        return self::dotHtml(self::dotsAt($place, $key), $extraClass);
    }

    /**
     * Sur la page d'une nouveauté qu'on vient d'atteindre par sa pastille :
     * la pastille de la carte qu'elle désigne (`ancre`), pour cette visite.
     * La pastille du menu s'éteint à l'arrivée ; celle-ci dit où regarder.
     */
    public static function anchorDot(string $anchor, string $extraClass = 'is-anchor'): string
    {
        $ids = [];
        foreach (self::$arrivedHere as $id) {
            if ((string)(self::catalog()[$id]['ancre'] ?? '') === $anchor) {
                $ids[] = $id;
            }
        }
        return self::dotHtml($ids, $extraClass);
    }
}
