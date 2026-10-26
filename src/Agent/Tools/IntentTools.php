<?php

declare(strict_types=1);

/**
 * Outils PAR INTENTION : une seule recherche qui couvre plusieurs sources
 * engagées, en un appel et en parallèle.
 *
 *   - `rechercher_emails`  : Gmail ET Outlook (exposé quand les deux sont engagés) ;
 *   - `consulter_agendas`  : Google Agenda ET calendrier Outlook (idem) ;
 *   - `rechercher_partout` : toutes les sources interrogeables engagées — mails,
 *     Drive, Slack, agendas, tâches, bases de connaissances du message —, dès
 *     qu'il y en a au moins deux.
 *
 * LE PROBLÈME RÉSOLU. Avec cinq connecteurs allumés, « qu'est-ce qu'on a sur
 * le dossier Durand ? » coûtait cinq allers-retours avec le modèle (un outil
 * par source, chacun relisant tout le contexte), et le modèle oubliait souvent
 * une source. Ici : un appel, toutes les sources interrogées EN MÊME TEMPS
 * (ParallelHttp), un résultat rangé par source avec de quoi lire la suite par
 * l'outil de chaque source.
 *
 * Les sources sont relues à CHAQUE appel (closure) : un connecteur activé par
 * MIA en cours de réponse (« Laisser MIA choisir ») y entre — le contrôleur
 * ré-ajoute alors ce fournisseur au registre, qui recalcule ses définitions.
 *
 * Lecture seule. Chaque source garde ses propres erreurs FR (connexion
 * expirée, droits) : une source en échec est NOMMÉE, les autres répondent.
 */
final class IntentTools extends AbstractToolProvider
{
    public const PER_SOURCE_DEFAULT = 5;
    public const PER_SOURCE_MAX     = 10;
    /** Délai global d'une recherche multi-sources (secondes). */
    public const DEADLINE           = 25.0;
    /** Fenêtre des agendas dans `rechercher_partout` (jours avant / après aujourd'hui). */
    public const AGENDA_WINDOW_DAYS = 90;
    /** Texte rendu au plus pour les bases de connaissances (caractères). */
    private const KB_CHARS          = 6000;

    /** Ordre des sources, et seules sources interrogeables. */
    private const SOURCE_ORDER = ['gmail', 'outlook', 'drive', 'slack', 'teams', 'calendar', 'mscalendar', 'tasks', 'mstodo'];

    /** Outil qui lit un élément trouvé, par source (rendu dans `lire_avec`). */
    private const READERS = [
        'gmail'      => 'lire_gmail (message = id)',
        'outlook'    => 'lire_outlook (message = id)',
        'drive'      => 'lire_google_drive (fichier = id)',
        'slack'      => 'lire_fil_slack (canal, message = ts)',
        'teams'      => 'lire_conversation_teams (conversation) ou lire_fil_teams (conversation, message)',
        'calendar'   => 'lire_evenement_agenda (evenement = id, agenda)',
        'mscalendar' => 'lire_evenement_agenda_outlook (evenement = id)',
        'tasks'      => 'lire_tache (tache = id, liste)',
        'mstodo'     => 'lire_tache_todo (tache = id)',
    ];

    /**
     * @param \Closure(): array<string, object> $services   connecteurs ENGAGÉS (clé → service)
     * @param (\Closure(string): ?string)|null  $searchBases bases de connaissances locales du message
     */
    public function __construct(
        AgentTurn $turn,
        private readonly \Closure $services,
        private readonly ?\Closure $searchBases = null,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'intent';
    }

    /**
     * Sources interrogeables parmi les connecteurs engagés (clé → libellé), dans
     * l'ordre. Slack ou Teams sans droit de recherche n'en est pas une. Pure.
     *
     * @param array<string, object> $services
     * @return array<string, string>
     */
    public static function searchableSources(array $services, bool $withBases): array
    {
        $out = [];
        foreach (self::SOURCE_ORDER as $key) {
            if (!isset($services[$key])) {
                continue;
            }
            if (in_array($key, ['slack', 'teams'], true) && method_exists($services[$key], 'canSearch')
                && !$services[$key]->canSearch()) {
                continue;
            }
            $out[$key] = self::label($key);
        }
        if ($withBases) {
            $out['bases'] = 'Bases de connaissances';
        }
        return $out;
    }

    public function definitions(): array
    {
        $s    = ($this->services)();
        $defs = [];
        if (isset($s['gmail'], $s['outlook'])) {
            $defs[] = [
                'name'        => 'rechercher_emails',
                'description' => "Recherche des emails dans Gmail ET Outlook en un seul appel (les deux boîtes sont "
                    . "interrogées en même temps). Mots-clés simples, communs aux deux boîtes ; requête vide = "
                    . "les derniers messages reçus. Chaque résultat porte sa `source` : lis-le ensuite avec "
                    . "lire_gmail ou lire_outlook.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete'       => ['type' => 'string', 'description' => "Mots-clés (expéditeur, sujet, nom…). Vide = derniers messages."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Résultats par boîte (1 à " . self::PER_SOURCE_MAX . ", défaut : " . self::PER_SOURCE_DEFAULT . ")."],
                    ],
                ],
            ];
        }
        if (isset($s['calendar'], $s['mscalendar'])) {
            $defs[] = [
                'name'        => 'consulter_agendas',
                'description' => "Liste les évènements de l'agenda Google ET du calendrier Outlook en un seul appel, "
                    . "fusionnés dans l'ordre chronologique, avec un filtre texte facultatif. Défaut : "
                    . "d'aujourd'hui à +90 jours. Chaque évènement porte sa `source`.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete'       => ['type' => 'string', 'description' => "Filtre texte facultatif (titre, lieu, participant)."],
                        'debut'         => ['type' => 'string', 'description' => "Début de la fenêtre, AAAA-MM-JJ (défaut : aujourd'hui)."],
                        'fin'           => ['type' => 'string', 'description' => "Fin de la fenêtre (incluse), AAAA-MM-JJ."],
                        'max_resultats' => ['type' => 'integer', 'description' => "Évènements par agenda (1 à " . self::PER_SOURCE_MAX . ", défaut : " . self::PER_SOURCE_DEFAULT . ")."],
                    ],
                ],
            ];
        }
        $sources = self::searchableSources($s, $this->searchBases !== null);
        if (count($sources) >= 2) {
            $defs[] = [
                'name'        => 'rechercher_partout',
                'description' => "Cherche un sujet dans TOUTES les sources allumées en même temps ("
                    . implode(', ', $sources) . ") et rend, par source, les éléments trouvés avec leur "
                    . "identifiant et l'outil qui les lit. À utiliser quand la question ne dit pas OÙ chercher "
                    . "(« qu'avons-nous sur le dossier Durand ? ») ; quand elle vise une source précise, "
                    . "utilise directement l'outil de cette source.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete'          => ['type' => 'string', 'description' => "Le sujet en quelques mots précis (nom, dossier, référence)."],
                        'max_par_source'   => ['type' => 'integer', 'description' => "Éléments par source (1 à " . self::PER_SOURCE_MAX . ", défaut : " . self::PER_SOURCE_DEFAULT . ")."],
                    ],
                    'required'   => ['requete'],
                ],
            ];
        }
        return $defs;
    }

    public function guidance(): string
    {
        $names = array_column($this->definitions(), 'name');
        if ($names === []) {
            return '';
        }
        $txt = "\n\nPlusieurs de tes sources se recoupent :";
        if (in_array('rechercher_partout', $names, true)) {
            $txt .= " pour une question qui ne dit pas OÙ chercher (« qu'avons-nous sur le dossier Durand ? »), "
                . "appelle d'abord `rechercher_partout` — il interroge toutes les sources allumées en même temps "
                . "et rend, par source, ce qu'il a trouvé ; lis ensuite le plus pertinent avec l'outil de sa source.";
        }
        if (in_array('rechercher_emails', $names, true)) {
            $txt .= " `rechercher_emails` couvre Gmail et Outlook en un appel.";
        }
        if (in_array('consulter_agendas', $names, true)) {
            $txt .= " `consulter_agendas` couvre les deux agendas en un appel.";
        }
        return $txt . " Quand la question vise UNE source précise, utilise directement son outil.";
    }

    public function externalContentTools(): array
    {
        // Mails, fichiers, messages, évènements : écrits par des tiers.
        return ['rechercher_emails', 'consulter_agendas', 'rechercher_partout'];
    }

    public function handlers(): array
    {
        return [
            'rechercher_emails'  => fn(array $args): array => $this->searchEmails($args),
            'consulter_agendas'  => fn(array $args): array => $this->searchAgendas($args),
            'rechercher_partout' => fn(array $args): array => $this->searchEverywhere($args),
        ];
    }

    // ===================== Handlers =====================

    private function searchEmails(array $args): array
    {
        $this->sseEmit(['phase' => 'emails_search']);
        $s = ($this->services)();
        $q = self::str($args['requete'] ?? '');
        $n = self::perSource($args['max_resultats'] ?? null);
        $tasks = [];
        $scope = $this->turn->scope();
        if (isset($s['gmail'])) {
            // Requête vide : comme Outlook, les derniers messages reçus.
            $gq = $scope->gmailQuery($q);
            $tasks['gmail'] = static fn() => $s['gmail']->search($gq !== '' ? $gq : 'in:inbox', $n);
        }
        if (isset($s['outlook'])) {
            $oq = $scope->outlookQuery($q);
            $tasks['outlook'] = static fn() => $scope->filterOutlook($s['outlook']->search($oq, $n));
        }
        [$bySource, $errors] = self::collect(ParallelHttp::run($tasks, self::DEADLINE));

        $items = [];
        foreach ($bySource as $key => $rows) {
            foreach ($rows as $row) {
                $items[] = ['source' => self::label($key)] + (array)$row;
            }
        }
        usort($items, static fn(array $a, array $b): int => self::emailTime($b) <=> self::emailTime($a));

        $out = ['resultats' => $items];
        if ($errors !== []) {
            $out['erreurs'] = $errors;
        }
        $out['note'] = 'Pour lire un email : lire_gmail ou lire_outlook selon sa `source`, avec son id.';
        return self::hintIfEmpty($out, 'resultats',
            "Aucun email trouvé dans les deux boîtes. Essaie d'autres mots-clés, un seul mot, ou une requête vide pour les derniers messages.");
    }

    private function searchAgendas(array $args): array
    {
        $this->sseEmit(['phase' => 'agendas_search']);
        $s    = ($this->services)();
        $q    = self::str($args['requete'] ?? '');
        $from = self::str($args['debut'] ?? '');
        $to   = self::str($args['fin'] ?? '');
        $n    = self::perSource($args['max_resultats'] ?? null);
        $tasks = [];
        foreach (['calendar', 'mscalendar'] as $key) {
            if (isset($s[$key])) {
                $svc = $s[$key];
                $tasks[$key] = static fn() => $svc->searchEvents('', $q, $from, $to, $n);
            }
        }
        [$bySource, $errors] = self::collect(ParallelHttp::run($tasks, self::DEADLINE));

        $items = [];
        foreach ($bySource as $key => $rows) {
            foreach ($rows as $row) {
                $items[] = ['source' => self::label($key)] + (array)$row;
            }
        }
        usort($items, static fn(array $a, array $b): int => self::displayTime((string)($a['debut'] ?? '')) <=> self::displayTime((string)($b['debut'] ?? '')));

        $out = ['evenements' => $items];
        if ($errors !== []) {
            $out['erreurs'] = $errors;
        }
        $out['note'] = 'Pour lire un évènement : lire_evenement_agenda ou lire_evenement_agenda_outlook selon sa `source`.';
        return self::hintIfEmpty($out, 'evenements',
            "Aucun évènement dans les deux agendas sur cette période. Élargis la fenêtre (debut, fin) ou retire le filtre texte.");
    }

    private function searchEverywhere(array $args): array
    {
        $this->sseEmit(['phase' => 'everywhere_search']);
        $q = self::str($args['requete'] ?? '');
        if ($q === '') {
            return ['error' => 'Donne le sujet à chercher (quelques mots précis).'];
        }
        $n = self::perSource($args['max_par_source'] ?? null);
        $s = ($this->services)();
        // Filtres d'une brique de workflow : appliqués source par source.
        $scope = $this->turn->scope();

        $tz    = FreeSlots::zone($this->turn->timeZone);
        $today = new \DateTimeImmutable('today', $tz);
        $from  = $today->modify('-' . self::AGENDA_WINDOW_DAYS . ' days')->format('Y-m-d');
        $to    = $today->modify('+' . self::AGENDA_WINDOW_DAYS . ' days')->format('Y-m-d');

        $tasks = [];
        foreach (array_keys(self::searchableSources($s, $this->searchBases !== null)) as $key) {
            $svc = $s[$key] ?? null;
            $tasks[$key] = match ($key) {
                'gmail'            => static fn() => $svc->search($scope->gmailQuery($q) ?: 'in:inbox', $n),
                'outlook'          => static fn() => $scope->filterOutlook($svc->search($scope->outlookQuery($q), $n)),
                'drive'            => static fn() => $scope->filterDrive($svc->search($scope->driveQuery($q), $n, $scope->driveFolder())),
                'slack', 'teams'   => static fn() => $svc->searchMessages($q, $n),
                'calendar', 'mscalendar' => static fn() => $svc->searchEvents('', $q, $from, $to, $n),
                'tasks', 'mstodo'  => static fn() => self::filterTasks($svc->listTasks('', 'toutes', '', '', 100), $q, $n),
                'bases'            => fn() => self::basesExcerpt(($this->searchBases)($q)),
                default            => static fn() => [],
            };
        }
        [$bySource, $errors] = self::collect(ParallelHttp::run($tasks, self::DEADLINE));

        $sources = [];
        $empty   = [];
        foreach ($bySource as $key => $rows) {
            $label = self::label($key);
            if ($rows === [] || $rows === null || $rows === '') {
                $empty[] = $label;
                continue;
            }
            $sources[$label] = $key === 'bases'
                ? ['extraits' => $rows]
                : ['elements' => array_values((array)$rows), 'lire_avec' => self::READERS[$key] ?? ''];
        }

        $out = ['sources' => $sources];
        if ($empty !== []) {
            $out['sans_resultat'] = $empty;
        }
        if ($errors !== []) {
            $out['erreurs'] = $errors;
        }
        $out['note'] = $sources === []
            ? "Rien trouvé nulle part. Essaie d'autres mots (synonyme, nom propre, référence), ou demande à l'utilisateur où chercher."
            : 'Résultats rangés par source. Lis l\'élément pertinent avec l\'outil indiqué (`lire_avec`) avant de conclure, et cite sa source.';
        return $out;
    }

    // ===================== Formes (pures) =====================

    /**
     * Résultats du planificateur → [lignes par source, erreurs par libellé].
     * Une erreur de connecteur (RuntimeException) est déjà rédigée pour
     * l'utilisateur ; toute autre panne reste au journal.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function collect(array $results): array
    {
        $rows   = [];
        $errors = [];
        foreach ($results as $key => $r) {
            if (isset($r['error'])) {
                $e = $r['error'];
                if (!$e instanceof \RuntimeException) {
                    error_log('[Recherche multi-sources] ' . $key . ' : ' . $e->getMessage());
                }
                $errors[self::label((string)$key)] = $e instanceof \RuntimeException
                    ? $e->getMessage() : 'Source indisponible pour le moment.';
                continue;
            }
            $rows[(string)$key] = $r['ok'] ?? [];
        }
        return [$rows, $errors];
    }

    /**
     * Tâches dont le titre ou l'aperçu contient un des mots cherchés. Accepte
     * la réponse ENTIÈRE d'un listing (`{liste, liste_nom, taches[]…}`, la
     * forme rendue par les deux services de tâches) comme la seule liste :
     * parcourir la réponse entière faisait passer ses clés pour des tâches,
     * et la recherche multi-sources ne trouvait jamais aucune tâche.
     */
    public static function filterTasks(array $tasks, string $query, int $limit): array
    {
        if (isset($tasks['taches']) && is_array($tasks['taches'])) {
            $tasks = $tasks['taches'];
        }
        $words = ConversationSearch::tokens($query);
        if ($words === []) {
            $words = [mb_strtolower(trim($query))];
        }
        $out = [];
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            $hay = self::fold((string)($t['titre'] ?? '') . ' ' . (string)($t['apercu'] ?? ''));
            foreach ($words as $w) {
                if ($w !== '' && str_contains($hay, self::fold($w))) {
                    $out[] = $t;
                    break;
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Horodatage d'un résultat d'email (Gmail : RFC 2822, Outlook : JJ/MM/AAAA HH:MM), 0 si illisible. */
    public static function emailTime(array $row): int
    {
        $date = trim((string)($row['date'] ?? ''));
        if ($date === '') {
            return 0;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?: (\d{2}):(\d{2}))?#', $date, $m) === 1) {
            return (int)mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), 0, (int)$m[2], (int)$m[1], (int)$m[3]);
        }
        $ts = strtotime($date);
        return $ts !== false ? $ts : 0;
    }

    /** « vendredi 07/08/2026 09:00 » ou « … (journée entière) » → clé de tri (journée entière en tête du jour). */
    public static function displayTime(string $display): int
    {
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})(?: (\d{2}):(\d{2}))?#', $display, $m) !== 1) {
            return PHP_INT_MAX;
        }
        return (int)mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), 0, (int)$m[2], (int)$m[1], (int)$m[3]);
    }

    private static function basesExcerpt(?string $text): string
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > self::KB_CHARS ? mb_substr($text, 0, self::KB_CHARS) . "\n[… extraits coupés]" : $text;
    }

    private static function label(string $key): string
    {
        return $key === 'bases' ? 'Bases de connaissances' : ConnectorRegistry::label($key);
    }

    private static function perSource(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int)$raw : self::PER_SOURCE_DEFAULT;
        return max(1, min(self::PER_SOURCE_MAX, $n));
    }

    private static function str(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string)$raw) : '';
    }

    private static function fold(string $s): string
    {
        return strtr(mb_strtolower($s), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i',
            'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => "'",
        ]);
    }
}
